# symfony-integration-service

CLI-first Symfony app for integrating external systems via adapters (**Shopware is the first adapter**).

## Goals

- Provide a generic integration service architecture (extendable to other systems).
- Keep system-specific code inside adapter/infrastructure code (Shopware lives in the Shopware adapter).
- Be easy to debug (PhpStorm), test, and run in CI.

## Current status

- Symfony 6.4 application running in a Docker-based dev stack
- Shopware Admin API reachable (OAuth token + requests)
- Minimal CLI commands implemented:
    - `integration:shopware:ping`
    - `integration:shopware:products:list`
    - `integration:shopware:products:create`
- Unit tests (PHPUnit) + static analysis (PHPStan) + CI workflow (GitHub Actions)

## Project structure (high level)

- `src/Integration/...`  
  Integration code + adapter infrastructure (Shopware lives here)
- `config/packages/integration.yaml`  
  Integration configuration root (adapter settings)
- `tests/...`  
  Unit tests for Shopware client + token provider

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
docker compose run --rm composer composer install
docker compose run --rm composer composer update
```

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

## Roadmap (next)

- **M1: Product data pipeline** (start small, then scale)
    - list/search products via Admin API
    - create/update products
    - later: CSV-based imports

## Contributing / workflow

- Work in small issues + feature branches
- Open PRs and squash-merge into `main`
- Keep changes incremental and easy to review
