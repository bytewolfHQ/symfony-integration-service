# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

All commands run inside the PHP Docker container unless noted otherwise.

```bash
# Open a shell in the container
docker compose exec php sh

# Run tests
docker compose exec php sh -lc "vendor/bin/phpunit"

# Run a single test file
docker compose exec php sh -lc "vendor/bin/phpunit tests/Integration/Infrastructure/ShopwareProductImportServiceTest.php"

# Static analysis (requires dev cache to be warm)
docker compose exec php sh -lc "vendor/bin/phpstan analyse -c phpstan.dist.neon"

# Warm cache (needed before first PHPStan run)
docker compose exec php sh -lc "bin/console cache:warmup --env=dev"

# Install/update dependencies
docker compose run --rm composer install
docker compose run --rm composer update
```

## Architecture

This is a **Symfony 6.4 CLI application** structured as an adapter-based integration service. Shopware is the first (and currently only) adapter.

### Layer overview

```
src/Command/Integration/          ← Symfony console commands (CLI entry points)
src/Integration/
  Domain/                         ← Entities, value objects (adapter-agnostic)
  Application/                    ← Use case orchestration
  Infrastructure/
    Http/Shopware/                ← OAuth HTTP client (ShopwareAdminApiClient)
    Shopware/Product/             ← Shopware product upsert + CSV import pipeline
    Symfony/                      ← IntegrationBundle, DI Extension, Configuration
```

### Key design patterns

**Custom Bundle + Extension** — `IntegrationBundle` registers the `integration:` config root (`config/packages/integration.yaml`). Adapter settings (base_url, client_id, client_secret) are bound to services via `config/services.yaml`.

**Upsert with skip-if-unchanged** — `ShopwareProductImportService::upsert()` returns one of `"create"`, `"update"`, or `"skipped"`. It first fetches the existing product by `productNumber` and compares all fields (with float tolerance) before sending any write request.

**CSV import pipeline**:
1. `ProductCsvReader` — parses CSV rows into `ProductDraft` DTOs; handles comma decimals and boolean strings (`yes/no/true/false/1/0`)
2. `ProductDraftValidator` — validates drafts, returns `ValidationError[]`
3. `ProductCsvImportRunner` — orchestrates validation + upsert, returns a summary (`total`, `created`, `updated`, `skipped`, `failed`)

**Batch import** — `ShopwareProductsImportBatchCommand` globs `var/import/incoming/products*.csv`, processes each file, then moves it to `var/import/processed/` (success) or `var/import/failed/` (failure).

### Configuration

Environment variables (see `.env.example`):
- `SHOPWARE_BASE_URL`, `SHOPWARE_CLIENT_ID`, `SHOPWARE_CLIENT_SECRET`
- `INTEGRATION_DEFAULT_CURRENCY` (default: `EUR`), `INTEGRATION_DEFAULT_LANGUAGE` (default: `de-DE`)

Put secrets in `.env.local` (not committed).

### PHPStan

Level 3, Symfony container-aware. The CI workflow warms up the `dev` cache with `debug=true` before running PHPStan. If analysis fails locally with a missing container XML error, run `cache:warmup` first.
