# Audit: symfony-integration-service

**Date:** 2026-03-30
**Scope:** Architecture, code quality, PHPStan, tests, security, practical improvements
**Status:** Tier 1–3 fully implemented. Tier 4 partially done (see open items).

---

## Overall Assessment

The codebase is **well-written at the micro level** — strict types, `readonly`, `final`, clean DI, good unit tests. The main structural gap was that the README advertised Hexagonal Architecture while the actual code was a flat single-layer adapter. Targeted fixes (implemented in this audit) close that gap and make the project compelling for a senior-role portfolio.

---

## 1. Architecture

### Finding

The README references `src/Integration/Domain/` and `src/Integration/Application/`, but both were empty. The actual dependency graph was flat:

```
Command → Infrastructure (direct)
```

### Issues found

| ID | Issue | File | Status |
|----|-------|------|--------|
| A1 | `ProductDraft` and `ValidationError` lived in Infrastructure, not Domain | `Infrastructure/Shopware/Product/ProductDraft.php` | ✅ Fixed — moved to `src/Integration/Domain/` |
| A2 | All interfaces defined in Infrastructure, not in a port layer | All `*Interface.php` under `Infrastructure/` | ✅ Partially fixed — new interfaces added; existing ones stay in Infrastructure as ports-by-convention |
| A3 | Commands injected concrete Infrastructure classes, not interfaces | `ShopwarePingCommand`, `ShopwareProductsImportBatchCommand`, `ShopwareProductsImportCsvCommand`, `ShopwareProductsImportCommand` | ✅ Fixed — all commands now inject interfaces |
| A4 | `ShopwareReferenceDataResolver` depended on `ShopwareAdminApiClient` (concrete) | `ShopwareReferenceDataResolver.php:18` | ✅ Fixed — now uses `ShopwareAdminApiClientInterface` |
| A5 | `ProductCsvReader` and `ProductCsvImportRunner` had no interfaces | — | ✅ Fixed — `ProductCsvReaderInterface` and `ProductCsvImportRunnerInterface` added |
| A6 | `services.yaml` only bound 2 of 5 interfaces explicitly | `config/services.yaml:40-41` | ✅ Fixed — all 5 interface→implementation bindings now explicit |

### Remaining gap

`src/Integration/Application/` is still empty. A proper Hexagonal Architecture would place use-case orchestration (e.g. an `ImportProductsUseCase`) there, with the Application layer depending only on Domain and port interfaces. The current structure (Commands → Infrastructure directly) is pragmatic and readable, but does not fully implement the pattern.

---

## 2. Code Quality

### Strengths

- `declare(strict_types=1)` in every file
- Consistent use of `final`, `readonly`, and constructor promotion
- Clean split: `request()` returns result, `requestOrFail()` throws on error
- In-memory caching in `ShopwareTokenProvider` (token) and `ShopwareReferenceDataResolver` (currency/tax UUIDs)
- `ProductDraftValidator` correctly separated from import logic
- `buildPayload()` is well-commented with pricing strategy rationale

### Issues found

| ID | Issue | Location | Status |
|----|-------|----------|--------|
| Q1 | `snippet()` duplicated verbatim in `ShopwarePingCommand` and `ShopwareAdminApiClient` | Both files | ✅ Fixed — extracted to `SnippetFormatter::format()` |
| Q2 | `ShopwareReferenceDataResolver` took concrete class (see A4) | `ShopwareReferenceDataResolver.php:18` | ✅ Fixed |
| Q3 | `fetchCurrentData()` returned untyped `array`; `hasChanges()` compared with `!==` — Shopware JSON APIs sometimes return numeric values as strings (e.g. `stock: "0"`), causing false-change detection | `ShopwareProductImportService.php:143–193` | ✅ Fixed — explicit `(int)`, `(bool)`, `(float)` casts added; typed `@return` shape added |
| Q4 | `rowNumber` in `ProductCsvImportRunner` starts at 2 and increments per draft, but rows skipped by `ProductCsvReader` (missing productNumber/name) are not counted — reported row numbers can diverge from actual CSV line numbers | `ProductCsvImportRunner.php:36` | ✅ Documented with comment. Full fix would require passing actual line numbers from `ProductCsvReader`; deferred. |
| Q5 | `--dry-run` in `ShopwareProductsImportBatchCommand` short-circuited with `continue` before reading the CSV — produced no output and skipped the actual dry-run pass. `ShopwareProductsImportCsvCommand` was correct. | `ShopwareProductsImportBatchCommand.php:90-94` | ✅ Fixed — `$dryRun` now passed to `importDrafts()`; file moves skipped in dry-run mode |
| Q6 | TODO left in production code for configurable default tax rate | `ShopwareProductImportService.php:12-13` | ✅ Fixed — `defaultTaxRate` is now a constructor parameter (default: `19`), injectable via `services.yaml` |
| Q7 | Mixed error reporting: HTTP errors throw `ShopwareApiException`; validation errors return `ValidationError[]`; runner failures embed `'action' => 'error: ...'` strings | `ProductCsvImportRunner.php:56-63` | ⬜ Open — unifying into a typed result object is a larger refactor; acceptable for current scope |

---

## 3. PHPStan

**Level before:** 3
**Level after:** 5 ✅ (passes clean)

### Issues addressed

| ID | Issue | Location | Status |
|----|-------|----------|--------|
| P1 | Array parameters lacked generic shapes | `ShopwareAdminApiClientInterface`, `ShopwareAdminApiClient` | ✅ Added `@param array<string, mixed>` docblocks |
| P2 | `request()` / `requestOrFail()` return `array` without shape; `get()` docblock said `body:string` (incorrect — body is array) | `ShopwareAdminApiClient.php:122-128` | ✅ Fixed — `@return array{status: int, raw: string, body: array<string, mixed>}` added to all three methods |
| P3 | `fetchCurrentData()` returned untyped `array` | `ShopwareProductImportService.php:143-161` | ✅ Fixed — typed shape added |
| P4 | `hasChanges()` `$current` parameter untyped | `ShopwareProductImportService.php:164` | ✅ Fixed — `@param array{name: string|null, stock: int|null, active: bool|null, gross: float|null}` added |
| P5 | Test methods missing `: void` | All test files | — Not applicable — all test methods already declared `: void` |
| P6 | `buildPayload()` returned plain `array` | `ShopwareProductImportService.php:106` | ✅ Fixed — `@return array<string, mixed>` added |
| P7 | `$res['body']['data'][0]['id'] ?? null` chains into `mixed` | `ShopwareReferenceDataResolver.php:46`, `ShopwareProductImportService.php:43-53` | ✅ Acceptable at level 5 — access-on-mixed is only flagged at level 8+; existing `is_string()` / `is_array()` guards provide runtime safety |

### Additional cleanups surfaced by level 5

| File | Issue | Fix |
|------|-------|-----|
| `ShopwareAdminApiClient.php` | `is_string($raw)` always true (`getContent()` returns `string`) | Removed redundant check |
| `ShopwareTokenProviderTest.php` | `is_array($parsed)` always true after `parse_str()` | Removed redundant check |
| `tests/bootstrap.php` | `method_exists(Dotenv::class, 'bootEnv')` always true in current Symfony version | Removed `method_exists` guard |

---

## 4. Tests

### Coverage before

| Class | Status |
|-------|--------|
| `ShopwareTokenProvider` | ✅ Covered |
| `ShopwareAdminApiClient` | ✅ Covered |
| `ProductCsvReader` | ✅ Covered |
| `ProductDraftValidator` | ✅ Covered |
| `ProductCsvImportRunner` | ✅ Covered |
| `ShopwareProductImportService` | ✅ Covered |
| `ShopwareReferenceDataResolver` | ❌ Zero tests |
| Commands | ❌ Zero tests |

### New tests added

| Test class | Tests | What is covered |
|------------|-------|-----------------|
| `ShopwareReferenceDataResolverTest` | 8 | Currency UUID resolution; result caching per ISO code; default EUR fallback; not-found exception; tax UUID resolution; result caching per rate; independent cache per rate; not-found exception |
| `ShopwareProductsImportBatchCommandTest` | 4 | File processed + summary shown; `--dry-run` passes `$dryRun=true` to runner and does not move files; empty directory returns success; missing directory returns failure |

**Total tests:** 30 → 44

### Remaining gaps

| ID | Gap | Impact |
|----|-----|--------|
| T3 | `ProductCsvReader` edge cases: non-existent file, custom `--delimiter`, `--limit` | Medium |
| T4 | `ProductCsvImportRunner` dry-run mode (`$dryRun=true`) never tested | Medium |
| T5 | API error propagation through import runner (`requestOrFail` throws inside runner) | Low |
| T6 | Row number correctness — the Q4 gap is untested | Low |
| T7 | `ShopwareTokenProvider` with network-level errors (`TransportException`) | Low |

### Structural note

The test directory is `tests/Integration/Infrastructure/...` — "Integration" names the product domain, not the test type. All tests are unit tests with mocked HTTP. The naming is a known convention in this project, not a defect.

---

## 5. Security

### No critical vulnerabilities in core code

- OAuth credentials come from env vars, never hardcoded
- Client secret sent in POST body (correct OAuth pattern, not URL params)
- `verifySsl=true` by default
- Token value is never logged (log includes method/path/status only)

### Items noted

| ID | Finding | Severity |
|----|---------|----------|
| S1 | No 401 recovery — if a token expires mid-batch (Shopware default TTL: 600s), `ShopwareApiException` bubbles up and the file moves to `failed/`. Long imports risk this. | Low / reliability |
| S2 | `--pattern` option in batch command feeds directly into `glob()` — a pattern like `../../*.csv` matches files outside the intended directory. CLI-only risk. | Low |
| S3 | `APP_SECRET` value is in `.env` in the repo. `.env` is in `.gitignore` so it will not be committed going forward, but the value is in git history. Acceptable for a dev-only project; worth noting as a known anti-pattern. | Informational |
| S4 | No rate limiting on batch imports — large CSV files could saturate the Shopware API. No throttle or backoff between products. | Informational |

---

## 6. Open Items

Items not yet implemented, ordered by portfolio impact:

| Priority | Item | Effort |
|----------|------|--------|
| Medium | **Implement `Application/` layer** — add an `ImportProductsUseCase` that `ShopwareProductsImportCsvCommand` calls, completing the Hexagonal Architecture | Medium |
| Medium | **Fix row number tracking (Q4)** — pass actual CSV line numbers from `ProductCsvReader` through to `ProductCsvImportRunner` so `ValidationError.rowNumber` matches the real file | Medium |
| Medium | **Add `ProductCsvImportRunner` dry-run test (T4)** | Low |
| Medium | **Add `ProductCsvReader` edge-case tests (T3)** | Low |
| Low | **Unify error model (Q7)** — replace mixed `action: 'error: ...'` strings with a typed `ImportResult` value object | Medium |
| Low | **Token refresh on 401 (S1)** — retry with a fresh token on `ShopwareApiException` with status 401 | Low |
| Low | **Sanitize `--pattern` input (S2)** — validate that the glob pattern stays within the incoming directory | Low |
| Low | **PHPStan level 6** — add missing typehints to reach the next level; mainly affects `mixed` return values in the HTTP access chains | Low |
