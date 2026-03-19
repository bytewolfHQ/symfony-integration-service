# symfony-integration-service

CLI-first Symfony app for integrating external systems via adapters (**Shopware is the first adapter**).

## Goals

- Provide a generic integration service architecture (extendable to other systems).
- Keep system-specific code inside adapter/infrastructure code (Shopware lives in the Shopware adapter).
- Be easy to debug (PhpStorm), test, and run in CI.

## Current status

- Symfony 6.4 application running in a Docker-based dev stack
- Shopware Admin API reachable (OAuth token + requests)
- CLI commands implemented:
  - `integration:shopware:ping`
  - `integration:shopware:products:list`
  - `integration:shopware:products:create`
  - `integration:shopware:products:import`
  - `integration:shopware:products:import-csv`
  - `integration:shopware:products:import-batch`
- Unit tests (PHPUnit) + static analysis (PHPStan) + CI workflow (GitHub Actions)

## Project structure (high level)

- `src/Integration/...`  
  Integration code + adapter infrastructure (Shopware lives here)
- `config/packages/integration.yaml`  
  Integration configuration root (adapter settings)
- `tests/...`  
  Unit tests for Shopware client, token provider and product import

## Configuration

Copy the example env file and adjust values:
```bash
cp .env.example .env
# optional: local overrides
touch .env.local
```

Shopware adapter configuration keys (bound via env vars and DI):

- `integration.adapters.shopware.base_url`
- `integration.adapters.shopware.client_id`
- `integration.adapters.shopware.client_secret`

> Note: Keep secrets in `.env.local` (not committed). Use `.env.example` for documentation.

## Running locally (Docker)

Open a shell inside the PHP container:
```bash
docker compose exec php sh
```

List available Symfony commands:
```bash
docker compose exec php sh -lc "bin/console"
```

Shopware smoke tests:
```bash
docker compose exec php sh -lc "bin/console integration:shopware:ping"
docker compose exec php sh -lc "bin/console integration:shopware:products:list --limit=5 --page=1"
docker compose exec php sh -lc "bin/console integration:shopware:products:create --name='Test product' --number='TEST-001'"
```

Install/update dependencies (composer container pattern):
```bash
docker compose run --rm composer install
docker compose run --rm composer update
```

## Product CSV import

The CSV import pipeline reads product data from a CSV file and upserts them into Shopware via the Admin API.

### CSV format
```csv
productNumber,name,stock,gross,net,taxRate,currency,active
IMP-101,Imported product 101,10,19.99,16.80,19,EUR,true
IMP-102,Imported product 102,0,29.99,,19,,false
IMP-103,Imported product 103,5,9.99,,,,true
```

**Required fields:** `productNumber`, `name`

**Optional fields and defaults:**

| Field | Default if empty |
|---|---|
| `stock` | `0` |
| `gross` | no price entry sent |
| `net` | calculated from `gross` + `taxRate` |
| `taxRate` | `19` |
| `currency` | `EUR` |
| `active` | `true` |

A template is available at `resources/import/templates/products_import_template.csv`.

### Single file import
```bash
# dry-run: shows what would be imported without writing to Shopware
docker compose exec php sh -lc "bin/console integration:shopware:products:import-csv \
  --file=var/import/incoming/products.csv --dry-run"

# real import
docker compose exec php sh -lc "bin/console integration:shopware:products:import-csv \
  --file=var/import/incoming/products.csv"
```

### Batch import

Scans `var/import/incoming/` for files matching `products*.csv` and processes them in order:
```bash
docker compose exec php sh -lc "bin/console integration:shopware:products:import-batch"
docker compose exec php sh -lc "bin/console integration:shopware:products:import-batch --dry-run"
```

Successfully processed files are moved to `var/import/processed/`, failed files to `var/import/failed/`.

## Quality checks

Run unit tests:
```bash
docker compose exec php sh -lc "vendor/bin/phpunit"
```

Run static analysis:
```bash
docker compose exec php sh -lc "vendor/bin/phpstan analyse -c phpstan.dist.neon"
```

## CI

GitHub Actions runs on PRs and `main`:

- PHPUnit
- PHPStan (Symfony container aware; warms up `dev` cache with `debug=true`)

## Roadmap

| Milestone | Status |
|---|---|
| M0 – Foundation | ✅ complete |
| M1 – Product import basics | ✅ mostly complete |
| M1.6 – Extend CSV schema (stock, pricing, active) | ✅ complete |
| M1.7 – Validation + error report | 🔲 next |
| M1.8 – Skip-if-unchanged | 🔲 planned |
| M2 – State & SyncRuns (Doctrine) | 🔲 planned |
| M3 – Generic write / push | 🔲 planned |

## Contributing / workflow

- Work in small issues + feature branches
- Open PRs and squash-merge into `main`
- Keep changes incremental and easy to review
```

---

Einspielen, dann der finale Commit für Step 7:
```
docs: update README for M1.6 (CSV import schema, commands, roadmap)

Closes #23