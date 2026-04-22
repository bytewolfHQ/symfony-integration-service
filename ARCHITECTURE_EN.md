# Architecture: symfony-integration-service

## 1. Overview

This project is a **Symfony 6.4 CLI application** acting as a middleware service between external data sources (e.g. CSV files) and target systems (currently: Shopware 6). Its focus is product import: product data is read, validated, and pushed into Shopware via REST API.

The project serves two goals:

1. **Practical use** — a reusable import service for real-world e-commerce integrations
2. **Portfolio demonstration** — showcases Hexagonal Architecture, clean code, and test-driven design in PHP

The architecture is designed so that new data sources (XML, ERP API) or new target systems (WooCommerce, Magento) can be added with minimal effort and without modifying existing code.

---

## 2. Hexagonal Architecture (Ports & Adapters)

The project follows the **Hexagonal Architecture** pattern (also known as Ports & Adapters). The core idea: business logic and technical details are strictly separated. Dependencies always point inward — Infrastructure knows Domain, Domain knows nothing outside itself.

### Layers

```
┌─────────────────────────────────────────────────────────┐
│  CLI Commands  (src/Command/)                           │
│  Application entry points                               │
└──────────────────────┬──────────────────────────────────┘
                       │ uses interface
┌──────────────────────▼──────────────────────────────────┐
│  Application  (src/Integration/Application/)            │
│  Use-case orchestration; knows only Domain + Ports      │
└──────┬───────────────────────────────┬──────────────────┘
       │ Port                          │ Port
┌──────▼──────────┐          ┌─────────▼──────────────────┐
│  Domain         │          │  Infrastructure             │
│  (src/Integration│         │  (src/Integration/          │
│   /Domain/)     │          │   Infrastructure/)          │
│  Value objects  │          │  Shopware adapter, HTTP,    │
│  No external    │          │  CSV reader, DI bundle      │
│  dependencies   │          │                             │
└─────────────────┘          └────────────────────────────┘
```

### Dependency Diagram (concrete classes)

```
CLI Commands (src/Command/)
    │
    └──▶ ImportProductsUseCaseInterface
              │
              ├──▶ ProductReaderInterface  ◀──  ProductCsvReader
              │
              └──▶ ProductImporterInterface  ◀──  ProductCsvImportRunner
                            │
                            ├──▶ ProductDraftValidatorInterface  ◀──  ProductDraftValidator
                            │
                            └──▶ ShopwareProductImportInterface  ◀──  ShopwareProductImportService
                                          │
                                          ├──▶ ShopwareAdminApiClientInterface  ◀──  ShopwareAdminApiClient
                                          │         └──▶ ShopwareTokenProviderInterface  ◀──  ShopwareTokenProvider
                                          │
                                          └──▶ ShopwareReferenceDataResolverInterface  ◀──  ShopwareReferenceDataResolver
```

The left side (`*Interface`) defines the **port** — what the system expects. The right side is the **adapter** — the concrete technical implementation. Bindings are declared in `config/services.yaml`.

### Domain Layer (`src/Integration/Domain/`)

Contains only business value objects with no external dependencies:

| Class | Description |
|-------|-------------|
| `ProductDraft` | Carries all product data to be imported (product number, name, stock, price, tax rate, currency, active status) |
| `ImportResult` | Summarises the result of an import run (created, updated, skipped, failed) with per-product detail rows |
| `ValidationError` | Describes a single validation error with row number, field name, and reason |

### Application Layer (`src/Integration/Application/`)

Contains the use case and defines the ports (interfaces) through which Infrastructure is connected:

| Class / Interface | Description |
|-------------------|-------------|
| `ImportProductsUseCase` | Orchestrates the import: reads drafts via `ProductReaderInterface`, imports via `ProductImporterInterface` |
| `ImportProductsUseCaseInterface` | Public contract of the use case (used by commands) |
| `ProductReaderInterface` | Port for reading product data from any source |
| `ProductImporterInterface` | Port for importing a list of `ProductDraft` objects into a target system |

### Infrastructure Layer (`src/Integration/Infrastructure/`)

Contains all technical adapters:

| Subdirectory | Contents |
|-------------|----------|
| `Http/Shopware/` | HTTP client (`ShopwareAdminApiClient`), OAuth2 token provider, exception |
| `Shopware/Product/` | Upsert service (`ShopwareProductImportService`) |
| `Shopware/Product/Import/` | CSV reader, validator, import runner |
| `Shopware/ReferenceData/` | Resolver for currency and tax UUIDs |
| `Symfony/` | Bundle, DI extension, configuration schema |

---

## 3. Design Decisions

### Consistent use of `final` and `readonly`

All classes are `final` — they are not designed for inheritance. All constructor parameters use `readonly`. This prevents unintended mutation after initialisation and makes objects inherently thread-safe. Anyone needing a different behaviour creates a new class and binds it through the interface — not through subclassing.

### Interfaces for every injected service

Every class that is received as a dependency by another class has an interface. This enables:
- **Testability without real API calls** — all tests use mocks/stubs
- **Replaceability** — an implementation can be swapped without changing the caller
- **Explicit contracts** — the interface documents what a dependency is required to do

### `ProductDraft` in Domain, not in Infrastructure

A product draft is a business concept: "a product that should be imported." It is independent of where the data comes from (CSV, XML, API) and where it goes (Shopware, WooCommerce). The Shopware-specific mapping (`buildPayload()`) lives exclusively in `ShopwareProductImportService`. This allows `ProductDraft` to be produced by multiple reader adapters and consumed by multiple target adapters.

### Resolver cache per key

`ShopwareReferenceDataResolver` caches currency and tax UUIDs per key (ISO code and tax rate respectively):

```php
private array $currencyIds = [];  // ['EUR' => 'uuid-...', 'USD' => 'uuid-...']
private array $taxIds = [];       // [19 => 'uuid-...', 7 => 'uuid-...']
```

A batch import of 500 products (all EUR, all 19% VAT) resolves the UUIDs exactly **once** — not 500 times. At the same time, the cache works correctly when a batch contains mixed currencies or tax rates.

### Skip-if-unchanged

Before each `PATCH` request, `ShopwareProductImportService` fetches the current data from Shopware and compares it with the draft. Only if something has actually changed is an update sent. Floats are compared with a tolerance of 0.001 to ignore rounding differences. This saves API calls and prevents unnecessary version entries in Shopware.

---

## 4. Patterns Used

### DTO (Data Transfer Object)

`ProductDraft` and `ImportResult` are DTOs: pure data containers without business logic. They transport data between layers without coupling those layers to each other.

### Value Object

All domain classes (`ProductDraft`, `ImportResult`, `ValidationError`) are `readonly` and have no mutable state. Equality is determined by values, not object identity. They are created once and passed around unchanged.

### Port & Adapter (Hexagonal Architecture)

The central pattern of the project. Ports are interfaces in the Application layer (`ProductReaderInterface`, `ProductImporterInterface`). Adapters are the concrete implementations in Infrastructure (`ProductCsvReader`, `ProductCsvImportRunner`). Binding is done exclusively in `config/services.yaml` — no production code references concrete implementations directly.

### Adapter (GoF)

`ShopwareAdminApiClient` is a classic GoF Adapter: it translates generic HTTP concepts (`request()`, `requestOrFail()`) into the Shopware-specific format (OAuth2 headers, JSON payload structure, error handling via `ShopwareApiException`).

### Strategy (prepared)

`ProductReaderInterface` and `ProductImporterInterface` are Strategy ports. `ImportProductsUseCase` knows only these interfaces — the concrete strategy (read CSV, import to Shopware) is injected from outside. A second implementation (XML reader, ERP importer) would be a new Strategy with no changes to the use case.

### Upsert with Change Detection

`ShopwareProductImportService.upsert()` implements the upsert pattern with upfront change detection:
- Product not found → `"create"`
- Product found, no change → `"skipped"`
- Product found, change detected → `"update"`

---

## 5. Extension Points

### Adding a new import source (e.g. XML file or ERP REST API)

1. Create a new class implementing `ProductReaderInterface`:
   ```php
   final class XmlProductReader implements ProductReaderInterface
   {
       public function read(string $source, string $delimiter = ',', ?int $limit = null): array
       {
           // parse XML, return list of ProductDraft objects
       }
   }
   ```
2. Bind it in `config/services.yaml`:
   ```yaml
   App\Integration\Application\Port\ProductReaderInterface:
       '@App\Integration\Infrastructure\Xml\XmlProductReader'
   ```
3. `ImportProductsUseCase`, `ShopwareProductsImportCsvCommand`, and all other classes remain **unchanged**.

### Adding a new target system (e.g. WooCommerce)

1. Create a new class implementing `ProductImporterInterface`:
   ```php
   final class WooCommerceProductImporter implements ProductImporterInterface
   {
       public function import(array $drafts, bool $dryRun = false): ImportResult
       {
           // call WooCommerce REST API
       }
   }
   ```
2. Optionally create a dedicated use case implementing `ImportProductsUseCaseInterface` if the orchestration differs.
3. Update `services.yaml` — Domain and Application code remains **untouched**.

### Adding new configuration parameters

1. Extend the schema in `src/Integration/Infrastructure/Symfony/DependencyInjection/Configuration.php`
2. Expose new parameters as container parameters in `IntegrationExtension::load()`
3. Add them to `config/packages/integration.yaml`

---

## 6. Import Pipeline: Step by Step

Using a CSV import via CLI as an example:

```
Step 1: CLI invocation
  php bin/console integration:shopware:products:import-csv --file var/import/products.csv

Step 2: ShopwareProductsImportCsvCommand
  Reads CLI options (--file, --dry-run, --delimiter, --limit)
  → calls ImportProductsUseCaseInterface::execute($file, $delimiter, $limit, $dryRun)

Step 3: ImportProductsUseCase — reading
  → calls ProductReaderInterface::read($file, $delimiter, $limit)
       [Adapter: ProductCsvReader]
       - Opens CSV with SplFileObject
       - First row = header (column names)
       - Subsequent rows: merges header + values
       - Parses fields: parseInt, parseFloat (comma as decimal separator), parseBool (yes/no/1/0)
       - Skips rows without productNumber or name
       - Returns list<ProductDraft>

Step 4: ImportProductsUseCase — importing
  → calls ProductImporterInterface::import($drafts, $dryRun)
       [Adapter: ProductCsvImportRunner]

Step 5: ProductCsvImportRunner — per ProductDraft
  a) Validation:
     ProductDraftValidator::validate($draft, $rowNumber)
     - stock >= 0 (if provided)
     - gross required when net is provided
     - taxRate > 0 (if provided)
     → on errors: ValidationError[] → row marked as 'failed', no upsert

  b) Upsert:
     ShopwareProductImportService::upsert($draft, $dryRun)

     i.  POST /api/search/product
         → filter: productNumber = $draft->productNumber
         → product not found: continue with (ii)
         → product found (UUID): continue with (iii)

     ii. Create new product:
         buildPayload($draft)
           - net = gross / (1 + taxRate/100) if net=null
           - ShopwareReferenceDataResolver::getCurrencyId() → currency UUID (cached)
           - ShopwareReferenceDataResolver::getTaxId() → tax UUID (cached)
         → POST /api/product  (skipped when --dry-run)
         → returns "create"

     iii. Update or skip:
          GET /api/product/{uuid} → fetchCurrentData()
          hasChanges($draft, $current)
           - compares: name, stock, active, gross (float tolerance 0.001)
          → no change: returns "skipped"
          → change detected:
            buildPayload($draft) → PATCH /api/product/{uuid}  (skipped when --dry-run)
            → returns "update"

  c) Accumulate result (created / updated / skipped / failed + per-item list)

Step 6: ShopwareProductsImportCsvCommand — output
  Per product: "[action] productNumber – name"
  Summary: "Summary: created=X, updated=Y, skipped=Z, failed=W"
  Exit code: 0 (no errors) or 1 (at least one failure)
```

### Batch Import (multiple CSV files)

`ShopwareProductsImportBatchCommand` runs the same flow for all files matching the pattern `products*.csv` in `var/import/incoming/` and moves each file after import to `var/import/processed/` (success) or `var/import/failed/` (errors).

---

## Configuration

Environment variables (`.env.local`):

| Variable | Description |
|----------|-------------|
| `SHOPWARE_BASE_URL` | Base URL of the Shopware Admin API |
| `SHOPWARE_CLIENT_ID` | OAuth2 client ID |
| `SHOPWARE_CLIENT_SECRET` | OAuth2 client secret |
| `INTEGRATION_DEFAULT_CURRENCY` | Default currency (default: `EUR`) |
| `INTEGRATION_DEFAULT_LANGUAGE` | Default language (default: `de-DE`) |

Bundle configuration (`config/packages/integration.yaml`):

```yaml
integration:
  defaults:
    currency: 'EUR'
    language: 'de-DE'
  adapters:
    shopware:
      base_url: '%env(SHOPWARE_BASE_URL)%'
      client_id: '%env(SHOPWARE_CLIENT_ID)%'
      client_secret: '%env(SHOPWARE_CLIENT_SECRET)%'
```
