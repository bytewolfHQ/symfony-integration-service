# Architektur: symfony-integration-service

## 1. Überblick

Dieses Projekt ist ein **Symfony 6.4 CLI-Anwendung**, die als Middleware-Service zwischen externen Datenquellen (z. B. CSV-Dateien) und Zielsystemen (derzeit: Shopware 6) fungiert. Der Schwerpunkt liegt auf dem Produktimport: Produktdaten werden gelesen, validiert und per REST-API in Shopware eingespielt.

Das Projekt verfolgt zwei Ziele:

1. **Praktischer Einsatz** — wiederverwendbarer Import-Service für reale E-Commerce-Integrationen
2. **Portfolio-Demonstration** — zeigt den Einsatz von Hexagonaler Architektur, Clean Code und testgetriebenem Design in PHP

Die Architektur ist so angelegt, dass neue Datenquellen (XML, ERP-API) oder neue Zielsysteme (WooCommerce, Magento) mit minimalem Aufwand ergänzt werden können, ohne bestehenden Code zu ändern.

---

## 2. Hexagonale Architektur (Ports & Adapters)

Das Projekt folgt dem Muster der **Hexagonalen Architektur** (auch: Ports & Adapters). Die Kernidee: Fachlogik und technische Details sind strikt voneinander getrennt. Abhängigkeiten zeigen immer von außen nach innen — Infrastructure kennt Domain, Domain kennt nichts außerhalb von sich selbst.

### Schichten

```
┌─────────────────────────────────────────────────────────┐
│  CLI-Commands  (src/Command/)                           │
│  Einstiegspunkte der Anwendung                          │
└──────────────────────┬──────────────────────────────────┘
                       │ nutzt Interface
┌──────────────────────▼──────────────────────────────────┐
│  Application  (src/Integration/Application/)            │
│  Use-Case-Orchestrierung; kennt nur Domain + Ports      │
└──────┬───────────────────────────────┬──────────────────┘
       │ Port                          │ Port
┌──────▼──────────┐          ┌─────────▼──────────────────┐
│  Domain         │          │  Infrastructure             │
│  (src/Integration│         │  (src/Integration/          │
│   /Domain/)     │          │   Infrastructure/)          │
│  Value Objects  │          │  Shopware-Adapter, HTTP,    │
│  Keine externen │          │  CSV-Reader, DI-Bundle      │
│  Abhängigkeiten │          │                             │
└─────────────────┘          └────────────────────────────┘
```

### Abhängigkeitsdiagramm (konkrete Klassen)

```
CLI-Commands (src/Command/)
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

Die linke Seite (`*Interface`) definiert den **Port** — was das System erwartet. Die rechte Seite ist der **Adapter** — die konkrete technische Umsetzung. Die Bindung erfolgt in `config/services.yaml`.

### Domain-Schicht (`src/Integration/Domain/`)

Enthält ausschließlich fachliche Value Objects ohne externe Abhängigkeiten:

| Klasse | Beschreibung |
|--------|-------------|
| `ProductDraft` | Trägt alle Produktdaten, die importiert werden sollen (produktnummer, name, stock, preis, steuersatz, währung, aktiv-Status) |
| `ImportResult` | Fasst das Ergebnis eines Import-Durchlaufs zusammen (created, updated, skipped, failed) mit Einzelergebnissen pro Produkt |
| `ValidationError` | Beschreibt einen einzelnen Validierungsfehler mit Zeilennummer, Feldname und Grund |

### Application-Schicht (`src/Integration/Application/`)

Enthält den Use Case und definiert die Ports (Interfaces), über die Infrastructure angebunden wird:

| Klasse / Interface | Beschreibung |
|--------------------|-------------|
| `ImportProductsUseCase` | Orchestriert den Import: liest Drafts über `ProductReaderInterface`, importiert über `ProductImporterInterface` |
| `ImportProductsUseCaseInterface` | Öffentlicher Vertrag des Use Case (wird von Commands genutzt) |
| `ProductReaderInterface` | Port zum Lesen von Produktdaten aus einer beliebigen Quelle |
| `ProductImporterInterface` | Port zum Importieren von `ProductDraft`-Listen in ein Zielsystem |

### Infrastructure-Schicht (`src/Integration/Infrastructure/`)

Enthält alle technischen Adapter:

| Unterverzeichnis | Inhalt |
|-----------------|--------|
| `Http/Shopware/` | HTTP-Client (`ShopwareAdminApiClient`), OAuth2-Token-Provider, Exception |
| `Shopware/Product/` | Upsert-Service (`ShopwareProductImportService`) |
| `Shopware/Product/Import/` | CSV-Reader, Validator, Import-Runner |
| `Shopware/ReferenceData/` | Resolver für Währungs- und Steuer-UUIDs |
| `Symfony/` | Bundle, DI-Extension, Konfigurationsschema |

---

## 3. Designentscheidungen

### `final` und `readonly` konsequent einsetzen

Alle Klassen sind `final` — sie sind nicht zur Vererbung vorgesehen. Alle Konstruktor-Parameter nutzen `readonly`. Das verhindert unbeabsichtigte Mutation nach der Initialisierung und macht Objekte inhärent threadsafe. Wer eine andere Implementierung braucht, erstellt eine neue Klasse und bindet sie über das Interface — nicht über Vererbung.

### Interfaces für jeden injizierten Service

Jede Klasse, die von einer anderen als Abhängigkeit empfangen wird, hat ein Interface. Das ermöglicht:
- **Testbarkeit ohne echte API-Calls** — alle Tests verwenden Mocks/Stubs
- **Austauschbarkeit** — die Implementierung kann gewechselt werden, ohne den Aufrufer zu ändern
- **Explizite Verträge** — das Interface dokumentiert, was eine Abhängigkeit leisten muss

### `ProductDraft` in der Domain, nicht in Infrastructure

Ein Produkt-Draft ist ein fachliches Konzept: "ein Produkt, das importiert werden soll". Es ist unabhängig davon, woher die Daten kommen (CSV, XML, API) und wohin sie gehen (Shopware, WooCommerce). Das Shopware-spezifische Mapping (`buildPayload()`) liegt ausschließlich im `ShopwareProductImportService`. So kann `ProductDraft` von mehreren Adaptern gelesen und an mehrere Zielsysteme übergeben werden.

### Resolver-Cache per Schlüssel

`ShopwareReferenceDataResolver` cacht Währungs- und Steuer-UUIDs pro Schlüssel (ISO-Code bzw. Steuersatz):

```php
private array $currencyIds = [];  // ['EUR' => 'uuid-...', 'USD' => 'uuid-...']
private array $taxIds = [];       // [19 => 'uuid-...', 7 => 'uuid-...']
```

Ein Batch-Import mit 500 Produkten (alle EUR, alle 19% MwSt.) löst die UUIDs genau **einmal** auf — nicht 500-mal. Gleichzeitig funktioniert der Cache korrekt, wenn ein Batch gemischte Währungen oder Steuersätze enthält.

### Skip-if-unchanged

Vor jedem `PATCH`-Request holt `ShopwareProductImportService` die aktuellen Daten aus Shopware und vergleicht sie mit dem Draft. Nur wenn sich tatsächlich etwas geändert hat, wird ein Update gesendet. Floats werden mit einer Toleranz von 0,001 verglichen, um Rundungsdifferenzen zu ignorieren. Das spart API-Calls und verhindert unnötige Versionseinträge in Shopware.

---

## 4. Verwendete Muster

### DTO (Data Transfer Object)

`ProductDraft` und `ImportResult` sind DTOs: reine Datencontainer ohne Fachlogik. Sie transportieren Daten zwischen Schichten, ohne die Schichten aneinander zu koppeln.

### Value Object

Alle Domain-Klassen (`ProductDraft`, `ImportResult`, `ValidationError`) sind `readonly` und haben keinen veränderbaren Zustand. Gleichheit ergibt sich aus den Werten, nicht aus der Objektidentität. Sie werden einmal erzeugt und dann unverändert weitergegeben.

### Port & Adapter (Hexagonal Architecture)

Das zentrale Muster des Projekts. Ports sind Interfaces in der Application-Schicht (`ProductReaderInterface`, `ProductImporterInterface`). Adapter sind die konkreten Implementierungen in Infrastructure (`ProductCsvReader`, `ProductCsvImportRunner`). Die Bindung erfolgt ausschließlich in `config/services.yaml` — kein Produktionscode referenziert konkrete Implementierungen direkt.

### Adapter (GoF)

`ShopwareAdminApiClient` ist ein klassischer GoF-Adapter: Er übersetzt generische HTTP-Konzepte (`request()`, `requestOrFail()`) in das Shopware-spezifische Format (OAuth2-Header, JSON-Payload-Struktur, Fehlerbehandlung via `ShopwareApiException`).

### Strategy (vorbereitet)

`ProductReaderInterface` und `ProductImporterInterface` sind Strategy-Ports. Der `ImportProductsUseCase` kennt nur diese Interfaces — die konkrete Strategie (CSV-Lesen, Shopware-Importieren) wird von außen injiziert. Eine zweite Implementierung (XML-Reader, ERP-Importer) wäre eine neue Strategy ohne Änderungen am Use Case.

### Upsert mit Change Detection

`ShopwareProductImportService.upsert()` implementiert das Upsert-Muster mit vorgelagerter Change Detection:
- Produkt nicht vorhanden → `"create"`
- Produkt vorhanden, keine Änderung → `"skipped"`
- Produkt vorhanden, Änderung erkannt → `"update"`

---

## 5. Erweiterungspunkte

### Neue Import-Quelle hinzufügen (z. B. XML-Datei oder ERP-REST-API)

1. Neue Klasse erstellen, die `ProductReaderInterface` implementiert:
   ```php
   final class XmlProductReader implements ProductReaderInterface
   {
       public function read(string $source, string $delimiter = ',', ?int $limit = null): array
       {
           // XML parsen, ProductDraft-Objekte zurückgeben
       }
   }
   ```
2. In `config/services.yaml` binden:
   ```yaml
   App\Integration\Application\Port\ProductReaderInterface:
       '@App\Integration\Infrastructure\Xml\XmlProductReader'
   ```
3. `ImportProductsUseCase`, `ShopwareProductsImportCsvCommand` und alle anderen Klassen bleiben **unverändert**.

### Neues Zielsystem hinzufügen (z. B. WooCommerce)

1. Neue Klasse erstellen, die `ProductImporterInterface` implementiert:
   ```php
   final class WooCommerceProductImporter implements ProductImporterInterface
   {
       public function import(array $drafts, bool $dryRun = false): ImportResult
       {
           // WooCommerce REST-API aufrufen
       }
   }
   ```
2. Optional: Eigenen Use Case erstellen, der `ImportProductsUseCaseInterface` implementiert, falls die Orchestrierung abweicht.
3. `services.yaml` anpassen — Domain- und Application-Code bleiben **unberührt**.

### Neue Konfigurationsparameter ergänzen

1. Schema in `src/Integration/Infrastructure/Symfony/DependencyInjection/Configuration.php` erweitern
2. Neue Parameter in `IntegrationExtension::load()` als Container-Parameter exponieren
3. In `config/packages/integration.yaml` eintragen

---

## 6. Import-Pipeline: Ablauf Schritt für Schritt

Am Beispiel eines CSV-Imports über die CLI:

```
Schritt 1: CLI-Aufruf
  php bin/console integration:shopware:products:import-csv --file var/import/products.csv

Schritt 2: ShopwareProductsImportCsvCommand
  Liest CLI-Optionen (--file, --dry-run, --delimiter, --limit)
  → ruft ImportProductsUseCaseInterface::execute($file, $delimiter, $limit, $dryRun)

Schritt 3: ImportProductsUseCase — Lesen
  → ruft ProductReaderInterface::read($file, $delimiter, $limit)
       [Adapter: ProductCsvReader]
       - Öffnet CSV mit SplFileObject
       - Erste Zeile = Header (Spaltennamen)
       - Folgezeilen: kombiniert Header + Werte
       - Parst Felder: parseInt, parseFloat (Komma als Dezimaltrennzeichen), parseBool (yes/no/1/0)
       - Überspringt Zeilen ohne productNumber oder name
       - Gibt list<ProductDraft> zurück

Schritt 4: ImportProductsUseCase — Importieren
  → ruft ProductImporterInterface::import($drafts, $dryRun)
       [Adapter: ProductCsvImportRunner]

Schritt 5: ProductCsvImportRunner — pro ProductDraft
  a) Validierung:
     ProductDraftValidator::validate($draft, $rowNumber)
     - stock >= 0 (falls angegeben)
     - gross erforderlich, wenn net angegeben
     - taxRate > 0 (falls angegeben)
     → Fehler: ValidationError[] → Zeile als 'failed' markieren, kein Upsert

  b) Upsert:
     ShopwareProductImportService::upsert($draft, $dryRun)

     i.  POST /api/search/product
         → Filter: productNumber = $draft->productNumber
         → Produkt nicht gefunden: weiter mit (ii)
         → Produkt gefunden (UUID): weiter mit (iii)

     ii. Neu anlegen:
         buildPayload($draft)
           - net = gross / (1 + taxRate/100), falls net=null
           - ShopwareReferenceDataResolver::getCurrencyId() → Währungs-UUID (gecacht)
           - ShopwareReferenceDataResolver::getTaxId() → Steuer-UUID (gecacht)
         → POST /api/product  (entfällt bei --dry-run)
         → gibt "create" zurück

     iii. Aktualisieren oder überspringen:
          GET /api/product/{uuid} → fetchCurrentData()
          hasChanges($draft, $current)
           - vergleicht: name, stock, active, gross (Float-Toleranz 0,001)
          → keine Änderung: gibt "skipped" zurück
          → Änderung erkannt:
            buildPayload($draft) → PATCH /api/product/{uuid}  (entfällt bei --dry-run)
            → gibt "update" zurück

  c) Ergebnis akkumulieren (created / updated / skipped / failed + Einzelliste)

Schritt 6: ShopwareProductsImportCsvCommand — Ausgabe
  Pro Produkt: "[action] productNumber – name"
  Zusammenfassung: "Summary: created=X, updated=Y, skipped=Z, failed=W"
  Exit-Code: 0 (kein Fehler) oder 1 (mindestens ein Fehler)
```

### Batch-Import (mehrere CSV-Dateien)

`ShopwareProductsImportBatchCommand` führt denselben Ablauf für alle Dateien durch, die dem Muster `products*.csv` in `var/import/incoming/` entsprechen, und verschiebt jede Datei nach dem Import in `var/import/processed/` (Erfolg) oder `var/import/failed/` (Fehler).

---

## Konfiguration

Umgebungsvariablen (`.env.local`):

| Variable | Beschreibung |
|----------|-------------|
| `SHOPWARE_BASE_URL` | Basis-URL der Shopware Admin API |
| `SHOPWARE_CLIENT_ID` | OAuth2 Client-ID |
| `SHOPWARE_CLIENT_SECRET` | OAuth2 Client-Secret |
| `INTEGRATION_DEFAULT_CURRENCY` | Standard-Währung (Standard: `EUR`) |
| `INTEGRATION_DEFAULT_LANGUAGE` | Standard-Sprache (Standard: `de-DE`) |

Bundle-Konfiguration (`config/packages/integration.yaml`):

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
