# La Labranza Kitchen Price Tracker (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a single-user web app for receipt entry and supplier price comparison. It computes the real cost per kg/L/unidad, adjusted for IVA, and flags price changes. It is deployed at `compras.lalabranza.cl` on HostGator.

**Architecture:** A static React SPA (Vite + shadcn/ui) talks to a small same-origin PHP JSON API under `/api/`, backed by MySQL/MariaDB. All money and unit calculations live in one PHP module, and a TypeScript mirror runs the live preview while the user types. Both are pinned to the same JSON fixture file, so they cannot drift apart. Comparisons are computed when read, never stored, so edits and voids stay consistent.

**Tech Stack:** PHP 8.1+ (no framework, PDO), PHPUnit 10, MariaDB/MySQL (InnoDB, utf8mb4); Node 20+, Vite, React 18+, TypeScript, Tailwind, shadcn/ui (incl. chart → Recharts), react-router, @tanstack/react-query, read-excel-file, Vitest + @testing-library/react.

**Spec:** `docs/superpowers/specs/2026-11-04-kitchen-price-tracker-design.md`. Read it before starting any task. Section numbers below (§) refer to it.

## Global Constraints

- PHP code must run on **PHP 8.1** (HostGator). Local is 8.5, so do not use features newer than 8.1. Production PHP has **no Composer runtime dependencies**. PHPUnit is dev-only.
- All SQL uses PDO prepared statements. InnoDB, utf8mb4. Must run on MySQL 5.7+ and MariaDB 10.3+, so do not use window functions.
- Money: paid amounts are integer CLP. `normalized_price` and `base_quantity` are `DECIMAL(14,4)`. Rounding is always **half away from zero** (PHP `round()`; TS must use the `roundHalfAway` helper, never bare `Math.round`).
- Settings defaults: `iva_rate = 0.19`, `alert_threshold_pct = 10`, `recent_days = 90`, `timezone = America/Santiago`.
- The UI copy is **Spanish**. Fixed labels: "Nueva compra", "Consultar precio", "Panel", "Historial", "Productos", "Proveedores", "Categorías", "Ajustes", "Importar planilla", "Sin comparación", "sin fecha", "Descuento / ajuste", "Despacho", "Boleta", "Factura", "exento / sin IVA".
- Formats: CLP `$1.300` (dot thousands, no decimals); per-unit prices `$1.300/kg`; percentages `+15,38%` (comma decimal, sign always shown); dates `dd-mm-yyyy`. Purchase dates are plain `DATE` values with no time-zone conversion.
- Allowed units are exactly `kg`, `g`, `L`, `mL`, `unidad`. Product base units are exactly `kg`, `L`, `unidad`.
- Single user. No roles. Every endpoint except `POST /api/auth/login` and `GET /api/auth/me` requires a session. All mutating requests require the `X-CSRF-Token` header.
- Licences: MIT/Apache/BSD only. No paid services.
- Phase 2 inventory, OCR, attachments and special taxes are **out of scope**. Do not add tables or UI for them.

## Review Focus

1. **A half-peso or a negative discount rounds differently in PHP and TS.** Preview and saved values must match to the peso. Pinned by fixture cases in Task 2 that both suites run.
2. **Editing an older receipt's date or voiding it changes the baseline of later purchases.** The later purchase's comparison must reflect the edit immediately. Pinned in Task 6 (`test_edit_date_reorders_baseline`).
3. **Changing a product's base unit after it has purchases** would make old normalized prices wrong. Expected: the API refuses with 409 while the product has lines. Pinned in Task 4.
4. **Typing a decimal comma on a phone ("1,5" kg, "0,75" L)** must parse as 1.5 / 0.75, never as 15 or NaN. Pinned in Task 7 (`parseDecimal`).
5. **The session expires halfway through entering a receipt.** Expected: redirect to login, keep the draft, restore it after login, and save it once. Pinned in Task 8 (`useReceiptDraft` persistence + 401 handling).

---

## File Structure

```
KitchernTracker/
  .gitignore
  README.md                         deploy, backup, restore instructions (Task 11)
  shared/calc-fixtures.json         single source of truth for calc cases (Task 2)
  api/
    composer.json  phpunit.xml
    bootstrap.php                   autoload (PSR-4 KT\ → src/), config, error handler
    config.example.php              DB DSN/user/pass, cookie_secure, backup_dir, cron_token
    public/index.php                front controller (deployed to <docroot>/api/index.php)
    public/.htaccess                rewrite everything to index.php
    migrations/001_init.sql
    bin/migrate.php  bin/set-password.php  bin/backup.php
    src/Http/{Router,Request,Response,HttpError}.php
    src/Db.php  src/Migrator.php  src/Settings.php  src/Audit.php
    src/Auth/{Auth,Csrf}.php
    src/Calc/{Units,Allocation,LineCalc,Compare}.php
    src/Catalog/{CatalogRepo,CatalogController}.php
    src/Receipts/{ReceiptValidator,ReceiptService,ReceiptController}.php
    src/Prices/{PriceQueries,Dashboard,CsvExport,PricesController}.php
    src/Import/{ImportValidator,ImportService,ImportController}.php
    src/Admin/{Backup,AdminController}.php
    src/routes.php                  all route registrations
    tests/                          mirrors src/ ; tests/DbTestCase.php
  web/
    (Vite + shadcn scaffold) vite.config.ts  components.json  index.html
    public/robots.txt  public/.htaccess
    src/lib/{api,calc,format,parse}.ts
    src/lib/calc.test.ts  format.test.ts  parse.test.ts
    src/app/{App,Layout,RequireAuth}.tsx  src/app/theme.css
    src/features/auth/LoginPage.tsx
    src/features/catalog/{SuppliersPage,ProductsPage,CategoriesPage,ProductPicker,SupplierPicker}.tsx
    src/features/receipts/{useReceiptDraft.ts,useReceiptDraft.test.tsx,ReceiptForm.tsx,LineRow.tsx,ComparisonChip.tsx,ReceiptDetailPage.tsx}
    src/features/prices/{LookupPage,ProductPage,HistoryPage,DashboardPage}.tsx
    src/features/admin/{SettingsPage,ImportPage}.tsx  src/features/admin/importRows.ts(+.test.ts)
  deploy/build.sh                   builds dist/ for upload (Task 11)
```

**Calculation split:** PHP `KT\Calc\*` is authoritative: the server always recomputes on save and ignores any computed values the client sends. `web/src/lib/calc.ts` exists only for the live preview. Both must pass every case in `shared/calc-fixtures.json`.

**Comparison algorithm (decided here, used in Tasks 6, 9):** the ordering key of a line is `(purchase_date, receipt_id, line_order)`, where `receipt_id` (auto-increment) is the entry order. A line's baseline is the comparable line for the same product with the greatest key below its own. A line is *comparable* when its receipt is `active`, `date_unknown = 0`, and its `normalized_price` is not null. History annotation loads every comparable line for the products involved, sorted by key, and walks them in PHP. Data volume is one kitchen, so this is fine and avoids window functions.

---

### Task 1: Repository, API skeleton, schema

**Files:**
- Create: `.gitignore`, `api/composer.json`, `api/phpunit.xml`, `api/bootstrap.php`, `api/config.example.php`, `api/public/index.php`, `api/public/.htaccess`, `api/src/Http/{Router,Request,Response,HttpError}.php`, `api/src/Db.php`, `api/src/Migrator.php`, `api/src/routes.php`, `api/migrations/001_init.sql`, `api/bin/migrate.php`
- Test: `api/tests/DbTestCase.php`, `api/tests/Http/RouterTest.php`, `api/tests/MigratorTest.php`

**Interfaces:**
- Produces:
  - `Router::add(string $method, string $pattern, callable $handler): void`. The pattern uses `{id}` segments that match digits only. `Router::dispatch(Request $r): Response`. Unknown route → 404 `{"error":"not_found"}`. A thrown `HttpError(int $status, string $code, array $extra = [])` → that status with `{"error": code, ...extra}`.
  - `Request`: `method`, `path` (with the `/api` prefix stripped), `query(): array`, `json(): array` (400 `invalid_json` on bad body), `header(string): ?string`, `params` (route params).
  - `Response::json(mixed $data, int $status = 200)`, `Response::csv(string $filename, iterable $rows)`, `Response::download(string $filename, string $body, string $mime)`.
  - `Db::pdo(): PDO`, using ERRMODE_EXCEPTION, real prepared statements, and `SET time_zone` from settings.
  - `Migrator::migrate(PDO): array` applies the `migrations/*.sql` files not yet recorded in the `schema_migrations` table and returns the applied names.
  - `DbTestCase` (PHPUnit base) drops and recreates all tables in the `kt_test` DB in `setUpBeforeClass`, and wraps each test in a transaction it rolls back. Helpers: `makeSupplier(string $name): int`, `makeProduct(string $name, string $baseUnit, ?int $categoryId = null): int`.
- Schema (`001_init.sql`) covers the tables in spec §7, plus these decided columns:
  - `receipts`: `adjustment_amount INT NOT NULL DEFAULT 0`, `delivery_amount INT NOT NULL DEFAULT 0`, `printed_total INT NULL`, `date_unknown TINYINT NOT NULL DEFAULT 0`, `doc_type ENUM('boleta','factura')`, `status ENUM('active','void')`, `idempotency_key CHAR(36) UNIQUE`, `source ENUM('manual','import')`, `created_at`/`updated_at DATETIME`.
  - `receipt_items`: `line_order SMALLINT`, `entry_mode ENUM('total','unit_price')`, `quantity DECIMAL(14,4)`, `entry_unit`, `package_count DECIMAL(14,4) NULL`, `package_content DECIMAL(14,4) NULL`, `package_unit NULL`, `unit_price INT NULL`, `entered_amount INT`, `adjustment_share INT`, `iva_exempt TINYINT`, `real_cost INT`, `amount_paid INT`, `base_quantity DECIMAL(14,4) NULL`, `normalized_price DECIMAL(14,4) NULL`, `notes`.
  - `product_aliases.alias UNIQUE`; `products.base_unit ENUM('kg','L','unidad')`.
  - `audit_log(entity, entity_id, action ENUM('create','update','void'), before_json, after_json, reason, created_at)`.
  - `settings(k PK, v)`, seeded with the Global Constraints defaults.
  - `app_user(id, password_hash)`.
  - `login_attempts(ip, attempted_at)`.
  - Index `receipt_items(product_id)` and `receipts(purchase_date, id)`.

- [ ] **Step 1:** `git init` at the project root. Add a `.gitignore` covering `api/vendor`, `api/config.php`, `web/node_modules`, `web/dist`, `dist/`, `.DS_Store`. Run `brew services start mariadb` and create the databases `kt_dev` and `kt_test`, plus a local user. Copy `config.example.php` to `config.php`. Run `composer require --dev phpunit/phpunit:^10` in `api/`. phpunit.xml sets `KT_DB_DSN=mysql:host=127.0.0.1;dbname=kt_test`.
- [ ] **Step 2: Write failing tests.**
  `RouterTest::test_unknown_route_404`: dispatch `GET /nope` → status 404, body `{"error":"not_found"}`.
  `RouterTest::test_param_route`: `add('GET','/receipts/{id}', fn($r)=>Response::json(['id'=>(int)$r->params['id']]))`; dispatch `/receipts/42` → `{"id":42}`; `/receipts/abc` → 404.
  `RouterTest::test_http_error`: a handler throws `new HttpError(409,'duplicate_receipt_number',['existing_id'=>7])` → 409, body `{"error":"duplicate_receipt_number","existing_id":7}`.
  `MigratorTest::test_migrate_idempotent`: the first `migrate()` returns `['001_init.sql']`, the second returns `[]`, and `SELECT v FROM settings WHERE k='iva_rate'` = `'0.19'`.
- [ ] **Step 3:** Run `cd api && vendor/bin/phpunit`. Expected: FAIL (classes missing).
- [ ] **Step 4:** Implement the files above. `public/index.php` resolves the app root from `getenv('KT_APP_ROOT')`. If that is unset, it uses `require __DIR__.'/app-root.php'` when that file exists (it is generated at deploy). Otherwise it uses `__DIR__.'/..'`. It then requires `bootstrap.php`, builds the Router from `src/routes.php`, and dispatches. Uncaught exceptions → 500 `{"error":"server_error"}`, logged, with no stack trace in the body.
- [ ] **Step 5:** Run `vendor/bin/phpunit`. Expected: all PASS. Run `php bin/migrate.php` against kt_dev and see it succeed.
- [ ] **Step 6:** Commit: `git add -A && git commit -m "chore: repo, PHP API skeleton, schema"`. The spec and plan are committed too.

### Task 2: Calculation core (PHP) + shared fixtures

**Files:**
- Create: `shared/calc-fixtures.json`, `api/src/Calc/{Units,Allocation,LineCalc,Compare}.php`
- Test: `api/tests/Calc/FixturesTest.php`

**Interfaces:**
- Produces:
  - `Units::toBase(float $qty, string $unit, string $baseUnit): ?float`. Returns null for incompatible pairs. `g`→kg ÷1000, `mL`→L ÷1000, `unidad`→unidad.
  - `LineCalc::baseQuantity(array $line, string $baseUnit): ?float`. `$line` has keys `quantity, entry_unit, package_count?, package_content?, package_unit?`. If all three package fields are set, it returns `package_count × toBase(package_content, package_unit, base)`. Otherwise it returns `toBase(quantity, entry_unit, base)`. A `kg`/`L` product entered as `unidad` with no package content → null.
  - `LineCalc::enteredAmount(array $line): int`. For `entry_mode = total`, returns `entered_amount`. For `unit_price`, returns `round(unit_price × (package_count ?? quantity))`.
  - `Allocation::spread(array $amounts, int $adjustment): array`. Returns int shares, each `round(adjustment × amount / sum)`. The remainder (adjustment − Σshares) goes to the largest amount (lowest index on ties). If `sum = 0` and `adjustment ≠ 0`, it throws `InvalidArgumentException`.
  - `LineCalc::costs(int $netOrPrinted, string $docType, bool $exempt, float $ivaRate): array{real_cost:int, amount_paid:int}`. Boleta: both = input. Factura not exempt: `real_cost = input`, `amount_paid = round(input × (1+rate))`. Factura exempt: both = input.
  - `LineCalc::normalizedPrice(int $realCost, ?float $baseQty): ?float`. Null if `baseQty` is null or ≤ 0. Otherwise `round(realCost / baseQty, 4)`.
  - `LineCalc::receiptTotals(array $lines, int $deliveryAmount, string $docType, float $ivaRate): array{neto:int, exento:int, iva:int, total:int}`. Each `$lines` item has `real_cost` and `iva_exempt`. Factura: `neto = Σ non-exempt real_cost + delivery`, `exento = Σ exempt`, `iva = round(neto × rate)`, `total = neto + exento + iva`. Boleta: `neto = exento = iva = 0`, `total = Σ real_cost + delivery`.
  - `Compare::change(?float $new, ?float $ref, float $thresholdPct): array{status:'up'|'down'|'same'|'none', diff:?float, pct:?float, flagged:bool}`. Returns `none` when new or ref is null or ref ≤ 0. Otherwise `pct = round((new−ref)/ref×100, 2)` and `diff = round(new−ref, 2)`. `status` is `same` when pct = 0. `flagged = abs(pct) ≥ threshold`.
- Fixture format: `{"toBase":[{"in":[qty,unit,base],"out":x|null}], "baseQuantity":[...], "spread":[{"in":[[amounts],adj],"out":[...]}], "costs":[...], "normalizedPrice":[...], "receiptTotals":[...], "change":[{"in":[new,ref,thr],"out":{...}}]}`.

- [ ] **Step 1: Write `shared/calc-fixtures.json`.** It must include at least these cases:
  - toBase: `[1000,"g","kg"]→1`, `[750,"mL","L"]→0.75`, `[2,"kg","L"]→null`, `[3,"unidad","kg"]→null`.
  - baseQuantity: 3 packages × 500 g → 1.5 for kg; `{quantity:1, entry_unit:"unidad"}` for an L product → null; 2 packages × 12 unidad → 24 for unidad.
  - spread: `[[1000,3000],-400]→[-100,-300]`; `[[1,1,1],-100]→[-34,-33,-33]` (remainder −1 goes to index 0); `[[333,333,334],5]→[2,2,1]` (half rounding: 1.665→2, 1.665→2, 1.67→2, sum 6 ≠ 5, so −1 to the largest, index 2 → 1); `[[500],-501]→[-501]`.
  - costs: `[1000,"factura",false,0.19]→{real_cost:1000, amount_paid:1190}`; `[1190,"boleta",false,0.19]→{1190,1190}`; `[1000,"factura",true,0.19]→{1000,1000}`; `[5,"factura",false,0.19]→{5,6}` (5.95→6).
  - normalizedPrice: `[1300,1]→1300`, `[1000,1.5]→666.6667`, `[1000,null]→null`.
  - receiptTotals: factura lines `[{real_cost:1000,iva_exempt:false},{real_cost:500,iva_exempt:true}]`, delivery 2000 → `{neto:3000, exento:500, iva:570, total:4070}`.
  - change: `[1500,1300,10]→{status:"up",diff:200,pct:15.38,flagged:true}` (AT-02); `[1100,1000,10]→{up,100,10,true}` (AT-02b, boundary); `[1050,1000,10]→{up,50,5,false}`; `[900,1000,10]→{down,-100,-10,true}`; `[1000,null,10]→{none,null,null,false}`; `[1000,0,10]→none`; `[1000,1000,10]→{same,0,0,false}`.
- [ ] **Step 2:** Write `FixturesTest` with one data-provider test per function. It loads the JSON and asserts equality, using a 1e-4 delta for floats.
- [ ] **Step 3:** Run `vendor/bin/phpunit tests/Calc`. Expected: FAIL.
- [ ] **Step 4:** Implement the four classes as static pure functions.
- [ ] **Step 5:** Run `vendor/bin/phpunit tests/Calc`. Expected: PASS.
- [ ] **Step 6:** Commit `feat: calculation core with shared fixtures`.

### Task 3: Auth, CSRF, settings

**Files:**
- Create: `api/src/Auth/{Auth,Csrf}.php`, `api/src/Settings.php`, `api/bin/set-password.php`; register routes in `api/src/routes.php`
- Test: `api/tests/Auth/AuthTest.php`, `api/tests/SettingsTest.php`

**Interfaces:**
- Produces:
  - `Auth::login(string $password, string $ip): bool`. Throws `HttpError(429,'too_many_attempts')` once 5 failed attempts from that IP fall within 15 minutes. Successful logins don't count. It calls `session_regenerate_id(true)`.
  - `Auth::requireUser(): void`. Throws 401 `unauthenticated`.
  - `Auth::logout()`.
  - `Auth::setPassword(string $plain)` uses `PASSWORD_DEFAULT`. The minimum length is 10, otherwise it throws `InvalidArgumentException`.
  - `Csrf::token(): string` (per session, 32 random bytes hex). `Csrf::verify(Request)` throws 403 `csrf` when the header doesn't match.
  - The session cookie is `KTSESS`: HttpOnly, SameSite=Strict, Secure unless `config['cookie_secure'] === false`, idle timeout 12 h.
  - `Settings::get(string $k): string`, `Settings::all(): array`, `Settings::set(array $kv): void`. `set` validates: `iva_rate` 0–1, `alert_threshold_pct` 0–100, `recent_days` 1–3650, otherwise 422 `invalid_setting`.
  - Routes:
    - `POST /auth/login {password}` → `{csrf_token}`
    - `POST /auth/logout`
    - `GET /auth/me` → `{authenticated:bool, csrf_token?:string}`
    - `POST /auth/password {current, new}`
    - `GET /settings`, `PUT /settings`
  - Router middleware (wired here): every route except login/me calls `requireUser`, and every non-GET route also calls `Csrf::verify`.

- [ ] **Step 1: Write failing tests.**
  `test_login_success_and_wrong_password`.
  `test_throttle_after_five_failures`: the 6th attempt throws HttpError with status 429, even with the correct password.
  `test_protected_route_401_without_session`.
  `test_mutation_403_without_csrf`.
  `test_settings_defaults`: `Settings::get('recent_days')==='90'`.
  `test_settings_reject_out_of_range`: `iva_rate=1.5` → 422.
  Tests drive the router directly, with sessions started via `session_id()` in CLI using `use_cookies=0`.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Auth tests/SettingsTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. `bin/set-password.php` prompts without echoing the password and calls `Auth::setPassword`.
- [ ] **Step 4:** Run the tests. Expected: PASS.
- [ ] **Step 5:** Commit `feat: single-user auth, CSRF, settings`.

### Task 4: Catalog API (suppliers, categories, products, aliases)

**Files:**
- Create: `api/src/Catalog/{CatalogRepo,CatalogController}.php`; add routes
- Test: `api/tests/Catalog/CatalogTest.php`

**Interfaces:**
- Produces routes:
  - `GET /suppliers?q&active`, `POST /suppliers {name, notes?}`, `PATCH /suppliers/{id} {name?, notes?, active?}`
  - The same for `/categories {name}`
  - `GET /products?q&active&limit=20` → `[{id,name,base_unit,category_id,category_name,active,aliases:[...], matched_alias?:string}]`. The search is accent- and case-insensitive (utf8mb4_unicode_ci) over name and aliases. Name-prefix matches come first.
  - `POST /products {name, base_unit, category_id?, notes?}`, `PATCH /products/{id}`
  - `POST /products/{id}/aliases {alias}`, `DELETE /aliases/{id}`
- Rules:
  - Supplier, category and product names are unique, ignoring case and accents → 409 `duplicate_name`.
  - An alias may not equal any product name or existing alias → 409 `duplicate_alias`.
  - Changing `base_unit` on a product that has receipt_items → 409 `base_unit_locked` (Review Focus 3).
  - There is no DELETE for suppliers, products or categories; set `active=false` instead.
  - Inactive records are excluded from `GET` unless `active=all`.
- `CatalogRepo::findProductByNameOrAlias(string $text): ?array` is used by Task 10.

- [ ] **Step 1: Write failing tests.**
  `test_search_matches_alias_accent_insensitive`: product "Pimentón" with alias "aji color"; `q=pimenton` and `q=AJI` both find it.
  `test_duplicate_product_name_409`.
  `test_alias_conflicts_with_product_name_409`.
  `test_base_unit_locked_when_used` (insert one receipt_item via fixture SQL, then PATCH base_unit → 409; a product without lines → 200).
  `test_inactive_hidden_by_default`.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Catalog`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. Expected: PASS.
- [ ] **Step 5:** Commit `feat: catalog API`.

### Task 5: Receipt validation and save (create)

**Files:**
- Create: `api/src/Receipts/{ReceiptValidator,ReceiptService,ReceiptController}.php`, `api/src/Audit.php`; add routes
- Test: `api/tests/Receipts/CreateReceiptTest.php`

**Interfaces:**
- Consumes: Task 2 `KT\Calc\*`, Task 4 products/suppliers, `Settings::get('iva_rate')`.
- Produces:
  - `POST /receipts`. The body is a `ReceiptInput`: `{idempotency_key, supplier_id, purchase_date: "YYYY-MM-DD", doc_type, receipt_number?: string, notes?, adjustment_amount?: int, delivery_amount?: int, printed_total?: int, confirm_duplicate?: bool, items: [{product_id, entry_mode, quantity, entry_unit, package_count?, package_content?, package_unit?, entered_amount?, unit_price?, iva_exempt?, notes?}]}`.
  - Returns 201 `ReceiptView` (shape below). If the idempotency key was seen before, it returns 200 with the existing receipt and inserts nothing. If an **active** receipt has the same supplier and same non-empty `receipt_number` and `confirm_duplicate` isn't set, it returns 409 `duplicate_receipt_number` with `{existing_id}`.
  - `ReceiptService::compute(array $input, string $baseUnitsById[], float $ivaRate): array` is a pure function that returns the items with `entered_amount, adjustment_share, real_cost, amount_paid, base_quantity, normalized_price` plus `totals`. Create and update both use it, and import (Task 10) calls it too. Order: enteredAmount → spread(adjustment) → costs(entered+share) → baseQuantity → normalizedPrice → receiptTotals.
  - `ReceiptValidator::validate(array $input): void` throws 422 `validation` with `{fields: {"items.0.quantity": "debe ser mayor que 0", ...}}`. The checks:
    - supplier exists and is active
    - date is valid and not after today in America/Santiago
    - `doc_type` is in the enum
    - there is at least one item
    - quantity > 0
    - entered_amount or unit_price > 0, matching entry_mode
    - the unit is in the allowed list
    - the unit is compatible with the product's base unit, **or** package fields are given that make it compatible
    - after allocation every `entered_amount + share` ≥ 0 (otherwise `adjustment_too_large`)
    - `receipt_number` has at most 40 chars and is stored exactly as given (leading zeros kept)
  - `Audit::record(string $entity, int $id, string $action, ?array $before, ?array $after, ?string $reason): void`.
  - `ReceiptView`: `{id, supplier:{id,name}, purchase_date, date_unknown, doc_type, receipt_number, notes, status, adjustment_amount, delivery_amount, printed_total, iva_rate, totals:{neto,exento,iva,total}, printed_total_mismatch: bool, items:[{...stored fields, product:{id,name,base_unit}}]}`. Task 6 adds `comparison` to each item.
  - The whole save runs in one transaction: receipt, items, and the audit `create` entry.

- [ ] **Step 1: Write failing tests.**
  `test_create_three_items_persists_once` (AT-01): POST three items, then the `receipt_items` count is 3. Re-POST the same idempotency_key → status 200, same id, count still 3.
  `test_receipt_number_leading_zeros_kept`: `"000123"` round-trips.
  `test_factura_net_entry`: factura, item `entered_amount 1000`, 1 kg → stored `real_cost 1000`, `amount_paid 1190`, `normalized_price 1000.0000`.
  `test_discount_spread_and_delivery_separate`: boleta with items 1000 and 3000, `adjustment_amount -400`, `delivery_amount 2000` → real_cost 900 and 2700, totals.total 5600.
  `test_validation_errors` (AT-05): a negative quantity, a missing supplier and an L product entered in `unidad` without package content → 422, the field keys are present, and nothing is inserted.
  `test_duplicate_number_requires_confirm`: second receipt same supplier + `"45"` → 409 with existing_id; with `confirm_duplicate:true` → 201; with an empty number → 201 and no warning.
  `test_printed_total_mismatch_flag`.
  `test_audit_create_written`.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Receipts`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. Expected: PASS.
- [ ] **Step 5:** Commit `feat: receipt creation with calc, idempotency, duplicate guard`.

### Task 6: Receipt read, edit, void + comparisons

**Files:**
- Create: `api/src/Prices/PriceQueries.php`; modify `api/src/Receipts/ReceiptService.php`, `ReceiptController.php`; add routes
- Test: `api/tests/Receipts/EditVoidTest.php`, `api/tests/Prices/BaselineTest.php`

**Interfaces:**
- Consumes: Task 5 `ReceiptService::compute`, `Audit::record`, Task 2 `Compare::change`.
- Produces:
  - `PriceQueries::baseline(int $productId, string $purchaseDate, ?int $receiptId, ?int $lineOrder): ?array{normalized_price:float, supplier_name:string, purchase_date:string, receipt_id:int}`. Implements the comparison algorithm in File Structure. `receiptId = null` means a new unsaved receipt, so every comparable line with `purchase_date ≤ date` counts as earlier.
  - `PriceQueries::annotate(array $lines): array` adds `comparison: {status, diff, pct, flagged, ref:{normalized_price, supplier_name, purchase_date}|null}` to each line using one in-memory pass. Task 9 uses it too.
  - `GET /products/{id}/baseline?date=YYYY-MM-DD` → the `baseline()` result or `null`. The receipt form uses it.
  - `GET /receipts/{id}` → `ReceiptView` with annotated items, plus `audit: [{action, created_at, reason, before, after}]`.
  - `PUT /receipts/{id}` takes a full `ReceiptInput` (no idempotency key) plus `reason?`. It replaces the items, recomputes, writes an `update` audit entry with the before/after `ReceiptView`, and runs in one transaction. A voided receipt → 409 `receipt_void`.
  - `POST /receipts/{id}/void {reason?}` sets `status=void` and writes a `void` audit entry.
  - `GET /receipts?from&to&supplier_id&receipt_number&status=active|void|all&page` → a paged list `{items:[{id,purchase_date,date_unknown,supplier_name,doc_type,receipt_number,total,status,flagged_count}], total}`, 50 per page, newest first.

- [ ] **Step 1: Write failing tests.**
  `test_at02_comparison`: boleta 1000 g for 1300 on 2026-01-10; boleta 1 kg for 1500 on 2026-02-10 → the second item's comparison is `{status:"up", diff:200, pct:15.38, flagged:true}`.
  `test_at02b_factura_vs_boleta`: factura net 1000 / 1 kg, then boleta 1100 / 1 kg → pct 10, flagged.
  `test_at03_first_purchase_none`: status `none`, flagged false.
  `test_at04_noncomparable_excluded`: an L product bought as 1 unidad × 1 L package is comparable (normalized per L). A line for the same product with `normalized_price` NULL (inserted via SQL, since the API rejects it) is never used as a baseline. The next line compares against the earlier comparable one, or shows `none`.  `test_same_date_uses_entry_order`: two receipts on the same date → the second compares to the first, and the first shows `none`.
  `test_void_removed_from_baseline` (AT-06): A(1000), B(1200), C(1300) → C ref = B; void B → C ref = A; B's GET still works and shows status void.
  `test_edit_date_reorders_baseline` (Review Focus 2): A dated 01-10 (1000), B dated 02-10 (1200); edit A's date to 03-10 → B shows `none` and A compares to B.
  `test_edit_writes_audit_and_recalculates` (AT-06): change the price, then audit has an `update` entry whose before/after differ on `real_cost`.
  `test_undated_excluded`: a receipt with `date_unknown=1` (SQL fixture) is never a baseline.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Receipts tests/Prices`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. Expected: PASS.
- [ ] **Step 5:** Commit `feat: receipt edit/void with audit; read-time comparisons`.

### Task 7: Web scaffold, TS calc mirror, formatting and parsing

**Files:**
- Create: the `web/` Vite React-TS scaffold, Tailwind and shadcn init (`components.json`), `web/vite.config.ts` (dev proxy `/api` → `http://localhost:8080`, `base` from `VITE_BASE` default `/`), `web/src/lib/{calc,format,parse}.ts`
- Test: `web/src/lib/calc.test.ts`, `format.test.ts`, `parse.test.ts`

**Interfaces:**
- Produces (TS, same semantics and names as the PHP versions in camelCase):
  - `roundHalfAway(x: number, decimals = 0): number`
  - `toBase(qty, unit, baseUnit): number | null`
  - `baseQuantity(line: LineInput, baseUnit): number | null`
  - `enteredAmount(line): number`
  - `spread(amounts: number[], adjustment: number): number[]`
  - `lineCosts(input, docType, exempt, ivaRate): {realCost, amountPaid}`
  - `normalizedPrice(realCost, baseQty): number | null`
  - `receiptTotals(lines, delivery, docType, ivaRate)`
  - `change(next, ref, thresholdPct): Comparison`
  - Type `Unit = 'kg'|'g'|'L'|'mL'|'unidad'`, `BaseUnit = 'kg'|'L'|'unidad'`, `DocType = 'boleta'|'factura'`
  - `format.ts`:
    - `formatCLP(n): string` → `"$1.300"`, negative `"-$400"`
    - `formatUnitPrice(n, base)` → `"$1.300/kg"`, `"$667/kg"`, rounded to a whole peso for display
    - `formatPct(p)` → `"+15,38%"`, `"-10,00%"`, `"0,00%"`
    - `formatDate("2026-02-10")` → `"10-02-2026"`, null → `"sin fecha"`
  - `parse.ts`: `parseDecimal(s): number | null` accepts `"1,5"`, `"1.5"`, `" 0,75 "` → 0.75. It accepts no thousands separators in quantities. `"abc"`, `""`, `"1,2,3"` → null. `parseCLP(s): number | null` accepts `"1.300"`, `"$1.300"`, `"1300"` → 1300, and `"1,5"` → null (no decimals in pesos).

- [ ] **Step 1:** Scaffold with `npm create vite@latest web -- --template react-ts`, add Tailwind, run `npx shadcn@latest init`, and add components: button, input, select, card, table, dialog, form, label, badge, tabs, toast/sonner, command, popover, chart, dropdown-menu, sheet. Add Vitest + jsdom + testing-library. Run `npm run dev` and see the page load.
- [ ] **Step 2: Write failing tests.** `calc.test.ts` imports `../../../shared/calc-fixtures.json` and loops every case for every function (floats within 1e-4). `format.test.ts` and `parse.test.ts` cover the exact strings listed above.
- [ ] **Step 3:** Run `cd web && npx vitest run`. Expected: FAIL.
- [ ] **Step 4:** Implement `calc.ts`, `format.ts` and `parse.ts`. `format.ts` uses `Intl.NumberFormat('es-CL')`.
- [ ] **Step 5:** Run `npx vitest run`. Expected: PASS, including every fixture case.
- [ ] **Step 6:** Commit `feat: web scaffold, calc mirror, es-CL format/parse`.

### Task 8: App shell, auth, catalog screens, receipt entry

**Files:**
- Create: `web/src/lib/api.ts`, `web/src/app/{App,Layout,RequireAuth}.tsx`, `web/src/app/theme.css`, `web/src/features/auth/LoginPage.tsx`, `web/src/features/catalog/*`, `web/src/features/receipts/{useReceiptDraft.ts,ReceiptForm.tsx,LineRow.tsx,ComparisonChip.tsx}`
- Test: `web/src/features/receipts/useReceiptDraft.test.tsx`, `web/src/lib/api.test.ts`

**Interfaces:**
- Consumes: the Task 3–6 endpoints and the Task 7 lib.
- Produces:
  - `api.ts`:
    - `api<T>(method, path, body?): Promise<T>` sends `credentials: 'same-origin'` and `X-CSRF-Token` from memory. The token is fetched from `/auth/me` on boot and refreshed on login.
    - A 401 dispatches the `kt:unauthenticated` window event, which the router turns into a redirect to `/login?next=<path>`, and rejects with `ApiError`. 409/422 reject with `ApiError{status, code, data}`.
  - Routes:
    - `/login`
    - `/` (Panel)
    - `/compras/nueva`
    - `/compras/:id`
    - `/compras` (receipt list)
    - `/consultar`
    - `/productos`, `/productos/:id`
    - `/proveedores`
    - `/categorias`
    - `/historial`
    - `/ajustes`
    - `/importar`
  - Layout: on phones, a bottom tab bar with Nueva compra, Consultar precio, Panel and Más. On desktop, a sidebar.
  - Theme: `theme.css` maps shadcn CSS variables to the main site's palette and fonts. Read the colour and font values from `https://lalabranza.cl` CSS custom properties (`--color-piedra-volcanica`, `--color-fibra-cruda`) and Fraunces / Space Grotesk / Lora, self-hosted via `@fontsource`.
  - `useReceiptDraft(): {draft, setHeader, addLine, updateLine, removeLine, computed, reset}`:
    - The draft is persisted to `localStorage['kt:draft:new']` on every change. It is restored on mount and survives unmount and logout. It is cleared only by `reset()` after a successful save.
    - A new `idempotency_key` (crypto.randomUUID) is generated per draft and kept across retries.
    - `computed` is derived with the Task 7 calc: per line `{baseQty, realCost, amountPaid, normalizedPrice, comparison}` plus `totals`.
    - Baselines come from `GET /products/{id}/baseline?date=` via react-query, keyed by (productId, date).
  - `ReceiptForm` behaviour:
    - Header: date (default today in America/Santiago), SupplierPicker (shadcn command with "Crear «…»" inline), Boleta/Factura segmented toggle, receipt number, notes.
    - Line rows: ProductPicker (search incl. aliases, "Crear «…»" opens a small dialog with name, base unit, category); quantity + unit select limited to units compatible with the product's base unit, with an "en envases" toggle revealing count × content + unit; amount field labelled "Total línea" or "Total neto línea" on facturas; "Precio unitario" mode toggle that shows which field is calculated; "exento / sin IVA" checkbox only on facturas.
    - Each row shows `formatUnitPrice(normalizedPrice)` and `ComparisonChip` (↑ red / ↓ green / = / "Sin comparación"; tooltip with ref supplier and date; flagged chips are bold).
    - Footer: "Descuento / ajuste", "Despacho", optional "Total impreso", computed Neto/Exento/IVA/Total or Total, and a non-blocking mismatch warning.
    - Enter in the last amount field adds a new line.
    - Saving disables the button while in flight. A 409 duplicate shows a confirm dialog and resubmits with `confirm_duplicate: true`. A 422 maps field errors onto inputs. On success: reset the draft and navigate to `/compras/:id`.
  - Catalog pages: shadcn tables with create and edit dialogs and an active toggle. A 409 `base_unit_locked` shows "No se puede cambiar la unidad: el producto ya tiene compras."

- [ ] **Step 1: Write failing tests.**
  `useReceiptDraft.test.tsx`:
  - `persists and restores after unmount` (Review Focus 5)
  - `keeps idempotency key across failed save`
  - `reset clears storage`
  - `computed matches calc for factura line` (net 1000, 1 kg → realCost 1000, amountPaid 1190)

  `api.test.ts`:
  - `401 dispatches kt:unauthenticated and does not touch draft storage`
  - `sends X-CSRF-Token on POST`
- [ ] **Step 2:** Run `npx vitest run src/features src/lib/api.test.ts`. Expected: FAIL.
- [ ] **Step 3:** Implement the shell, login, catalog pages and receipt form.
- [ ] **Step 4:** Run `npx vitest run`. Expected: PASS.
- [ ] **Step 5: Verify in the browser.** Run `php -S localhost:8080 -t api/public` with `KT_APP_ROOT=api` and `npm run dev`, and set a password with `bin/set-password.php`. At a 390 px width: log in, create a supplier and product inline, and enter a 2-line factura with a discount. The chips and totals update as you type. Reload mid-entry and the draft is restored. Saving shows the receipt. Double-clicking save creates one receipt.
- [ ] **Step 6:** Commit `feat: app shell, auth, catalog UI, receipt entry`.

### Task 9: Price queries, history, export, dashboard, lookup + their screens

**Files:**
- Create: `api/src/Prices/{Dashboard,CsvExport,PricesController}.php`; modify `PriceQueries.php`; `web/src/features/prices/{LookupPage,ProductPage,HistoryPage,DashboardPage}.tsx`, `web/src/features/receipts/ReceiptDetailPage.tsx`
- Test: `api/tests/Prices/{HistoryTest,LookupTest,DashboardTest,CsvExportTest}.php`

**Interfaces:**
- Consumes: Task 6 `PriceQueries::annotate`, `Settings::get('recent_days')`.
- Produces:
  - `GET /history?from&to&product_id&supplier_id&category_id&receipt_number&doc_type&direction=up|down|flagged&include_undated=1&page` returns annotated line rows: `{receipt_id, purchase_date, date_unknown, supplier_name, product_name, category_name, doc_type, receipt_number, quantity, entry_unit, package_*, base_quantity, base_unit, entered_amount, amount_paid, real_cost, normalized_price, comparison}`. The direction filter is applied after annotation. Voided receipts are always excluded. With `&format=csv` it returns the same rows, unpaged, as `historial-YYYYMMDD.csv`.
  - CSV format: UTF-8 with BOM, `;` separator (Excel es-CL), and the columns `Fecha;Proveedor;Producto;Categoría;Tipo doc;N° documento;Cantidad;Unidad;Envases;Contenido;Unidad contenido;Cantidad base;Unidad base;Monto ingresado;Monto pagado;Costo real;Costo real por unidad base;Cambio %;Alerta`. Numbers use no thousands separator and a comma decimal.
  - `GET /products/{id}` → `{product, aliases, stats:{latest:{normalized_price,supplier_name,purchase_date}|null, lowest_recent:{...}|null, purchase_count, recent_days}, by_supplier:[{supplier_name, latest_normalized_price, purchase_date, count_in_window}], series:[{purchase_date|null, normalized_price, supplier_name, doc_type}]}`. "Recent" is `purchase_date ≥ today − recent_days`. If nothing falls in the window, `lowest_recent` is null and the UI shows `latest` with its date. Undated rows appear only in `series`, with `purchase_date: null`.
  - `GET /lookup?q=` → up to 10 products, each with the same `stats` and `by_supplier`.
  - `GET /dashboard?from&to` (default: the current month) → `{recent_receipts:[last 10], flagged:[annotated lines with flagged=true in range], spend:{total_paid, total_real, by_category:[{name,total_paid,total_real}], by_supplier:[...]}}`. Delivery amounts are a "Despacho" category entry. For facturas, `total_paid` includes IVA on delivery.
  - Screens:
    - **Consultar precio:** a large search box with autofocus. Result cards show the latest real price per unit, the date and supplier, the "más bajo en 90 días" price, and per-supplier rows.
    - **Producto:** the stats plus a shadcn line chart of real cost per unit over time with one series per supplier. Undated points are listed below as "sin fecha".
    - **Historial:** a filter bar, a table and an "Exportar CSV" button that uses the current filters.
    - **Panel:** stat cards (gasto pagado, costo real), a flagged-changes list, recent receipts, and a bar chart of spend by category.
    - **Recibo detail:** the items with chips, totals, an Editar button (reuses ReceiptForm in edit mode with PUT; the draft key is `kt:draft:<id>`), Anular with a reason dialog, and a collapsible "Historial de cambios".

- [ ] **Step 1: Write failing tests.**
  `HistoryTest::test_filters_and_direction` covers supplier, date range and `direction=flagged`, returning only lines whose `|pct| ≥ 10`.
  `HistoryTest::test_void_excluded`.
  `CsvExportTest::test_matches_filter_and_columns` (AT-08): the CSV row count equals the JSON total for the same filter; the header row matches exactly; it starts with a BOM; `Costo real por unidad base` for a 1000 g / 1300 line is `1300,0000`.
  `LookupTest::test_lowest_recent_respects_window`: purchases 100 days ago at 900 and 10 days ago at 1100 → `lowest_recent` = 1100, `latest` = 1100.
  `LookupTest::test_by_supplier_latest`.
  `DashboardTest::test_spend_includes_delivery_and_iva`: factura net 1000 + delivery 2000 → total_real 3000, total_paid 3570, and a "Despacho" category of 2000 real.
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Prices`. Expected: FAIL.
- [ ] **Step 3:** Implement the API, then the four screens and the receipt detail.
- [ ] **Step 4:** Run `vendor/bin/phpunit` and `npx vitest run`. Expected: all PASS.
- [ ] **Step 5: Verify in the browser.** Enter AT-02 data (two receipts). Panel lists the flagged +15,38% line. Consultar precio shows the 1.500/kg latest price. The Producto chart shows two points. Exportar CSV opens correctly in Excel/Numbers with accents intact. Editing the first receipt's price updates the chip on the second.
- [ ] **Step 6:** Commit `feat: history, export, lookup, dashboard`.

### Task 10: Spreadsheet import + Ajustes screen + backups

**Files:**
- Create: `api/src/Import/{ImportValidator,ImportService,ImportController}.php`, `api/src/Admin/{Backup,AdminController}.php`, `api/bin/backup.php`, `web/src/features/admin/{ImportPage,SettingsPage}.tsx`, `web/src/features/admin/importRows.ts`
- Test: `api/tests/Import/ImportTest.php`, `api/tests/Admin/BackupTest.php`, `web/src/features/admin/importRows.test.ts`

**Interfaces:**
- Consumes: Task 4 `CatalogRepo::findProductByNameOrAlias`, Task 5 `ReceiptService::compute` and the save path.
- Produces:
  - `importRows.ts`:
    - `readSheet(file: File): Promise<RawRow[]>` handles `.xlsx` via read-excel-file (first sheet or "Hoja 1") and `.csv` (`;` or `,` auto-detected).
    - `mapHeaders(rows): ImportRow[]` uses this exact header mapping, case-, accent- and space-insensitive: `Fecha→date`, `Lugar / Proveedor→supplier`, `Pruducto|Producto→product`, `Categoria→category`, `Cantidad→quantity`, `Unidad→unit`, `Precio unitario→unit_price`, `Total de compra→total`, `Numero de boleta→receipt_number`.
    - Unit normalisation: `gr|g|grs→g`, `kg|kilo|kilos→kg`, `l|lt|litro→L`, `ml|cc→mL`, `unidad|un|u|und→unidad`, anything else → null.
    - Excel serial dates are converted to `YYYY-MM-DD`.
  - `POST /import/validate {rows: ImportRow[]}` → `{rows: [{...row, index, flags: string[], suggestions: {supplier_id?, product_id?, swapped?: bool}}]}`. The flags are exactly: `missing_date`, `missing_unit`, `missing_total`, `missing_receipt_number`, `unknown_supplier`, `unknown_product`, `possible_swap` (the supplier text matches a known product or alias, or the product text matches a known supplier), `ambiguous_price` (unit_price is set, total is empty and quantity ≠ 1), `invalid_quantity`.
  - `POST /import/commit {rows: ResolvedRow[]}`. Each `ResolvedRow` has `supplier_id, product_id, purchase_date|null, date_unknown, doc_type, receipt_number?, quantity, entry_unit, package_*?, entered_amount, accepted_flags: string[]`. The server re-validates and returns 422 if any row has a flag that is neither resolved nor listed in `accepted_flags`. It groups rows into receipts by (supplier_id, receipt_number, purchase_date) when all three are present, and makes one receipt per row otherwise. Receipts get `source='import'`. Spreadsheet amounts are always IVA-included, as paid (spec §8). If a row is set to factura, the server converts `entered_amount = round(total / (1 + iva_rate))` before `compute`; boleta rows are stored as given. Each receipt also gets an audit `create` entry with reason "importación". Everything is in one transaction. It returns `{receipts_created, lines_created}`.
  - **Importar planilla** screen: file picker → an editable preview table. Flag badges are in Spanish ("Falta fecha", "Falta unidad", "Falta total", "Sin N° boleta", "Proveedor nuevo", "Producto nuevo", "¿Columnas invertidas?", "¿Precio unitario o total?"). Each row can: map or create a supplier and product (or add the text as an alias); swap supplier/product with one click; choose whether the price is unit or total; set Boleta/Factura (default Boleta); accept a missing date (sets `date_unknown`). Multi-select plus "Asignar fecha" sets one date on many rows. "Importar" stays disabled until every row has no unresolved flags.
  - `Backup::dump(PDO): string` produces a PHP-native SQL dump (CREATE TABLE + INSERTs for every table except `login_attempts`), with no `exec`.
  - `GET /admin/backup` downloads `kt-backup-YYYYMMDD-HHMM.sql`.
  - `bin/backup.php` writes to `config['backup_dir']` (outside the docroot) and deletes files older than 30 days.
  - `GET /cron/backup?token=` is exempt from session/CSRF. It requires `hash_equals(config['cron_token'], token)` with a token of at least 32 chars; otherwise 403.
  - **Ajustes** screen: IVA rate (shown as %), alert threshold %, recent days, change password, and "Descargar respaldo".

- [ ] **Step 1: Write failing tests.**
  `ImportTest::test_flags_sample_rows` (AT-07): the rows `{supplier:"Pimenton", product:"Vega central", quantity:1}`, `{product:"Tomate", quantity:3, unit_price:920}` and `{supplier:"Vega Central", product:"Ajo", unit:null, total:null}`, with known supplier "Vega Central" and product "Pimentón" → row 0 has `possible_swap`, `missing_date`, `missing_total`, `missing_receipt_number`; row 1 has `ambiguous_price`; row 2 has `missing_unit`, `missing_total`.
  `ImportTest::test_commit_rejects_unaccepted_flags`.
  `ImportTest::test_factura_row_converted_to_net`: a factura row with total 1190 is stored as entered_amount 1000 and real_cost 1000.
  `ImportTest::test_commit_groups_by_receipt_and_marks_undated`: two rows with the same supplier, number and date → one receipt with two lines; an undated row → `date_unknown=1` and it is excluded from baselines (via `PriceQueries::baseline`).
  `BackupTest::test_dump_restores`: dump, drop the tables, run the dump SQL, and the receipt count is equal.
  `BackupTest::test_cron_token_required`.
  `importRows.test.ts`:
  - `maps misspelled Pruducto header`
  - `normalizes Gr/gr/Unidad`
  - `converts excel serial 46000 to 2025-12-09`
- [ ] **Step 2:** Run `vendor/bin/phpunit tests/Import tests/Admin` and `npx vitest run src/features/admin`. Expected: FAIL.
- [ ] **Step 3:** Implement the API and both screens.
- [ ] **Step 4:** Run both suites. Expected: PASS.
- [ ] **Step 5: Verify in the browser.** Import a CSV of the three test rows, resolve every flag in the UI, import, and see them in Historial (the undated one appears under "sin fecha"). If the owner has provided `CONTROL DE PRECIOS.xlsx`, run it too and confirm the flags match spec §8. Otherwise, note that AT-07 against the real file is still pending.
- [ ] **Step 6:** Commit `feat: spreadsheet import, settings, backups`.

### Task 11: Deployment packaging and README

**Files:**
- Create: `deploy/build.sh`, `web/public/.htaccess`, `web/public/robots.txt`, `api/public/.htaccess`, `README.md`
- Test: `deploy/smoke.sh`

**Interfaces:**
- Produces a `dist/` with this layout:
  ```
  dist/docroot/            → upload to the subdomain document root (e.g. ~/public_html/compras/)
    index.html assets/ robots.txt .htaccess
    api/index.php api/.htaccess api/app-root.php   (app-root.php returns KT_APP_ROOT path, default '/home/<cpaneluser>/kt-app')
  dist/kt-app/             → upload to ~/kt-app/ (outside public_html)
    bootstrap.php src/ migrations/ bin/ config.example.php   (no vendor/, no tests/)
  ```
- `deploy/build.sh [--base=/compras/] [--app-root=/home/USER/kt-app]` runs `npm ci && npm run build` with `VITE_BASE`, copies the files, and writes `app-root.php`. `--base` covers the subpath fallback from spec §12.
- `web/public/.htaccess`:
  - force HTTPS
  - `Header set X-Robots-Tag "noindex, nofollow"`
  - deny dotfiles
  - long cache for `/assets/*`, no-cache for `index.html`
  - SPA fallback: anything that is not an existing file and not under `api/` rewrites to `index.html`
- `robots.txt`: `Disallow: /`.
- `api/public/.htaccess` rewrites everything to `index.php`.
- README sections:
  - **cPanel checklist** (spec §12): PHP ≥ 8.1 via MultiPHP, MySQL DB + user, subdomain + AutoSSL, cron.
  - **First deploy:** upload, create `config.php`, run `php bin/migrate.php` and `php bin/set-password.php` via SSH or cPanel Terminal. Without a shell, note the alternative: run the migration SQL in phpMyAdmin.
  - **Updating.**
  - **Backups:** a cron line `0 4 * * * php ~/kt-app/bin/backup.php`, or the external-scheduler URL fallback.
  - **Restore procedure:** import the `.sql` in phpMyAdmin or with `mysql < file`. It must be tested once before launch.
  - **Subpath fallback** instructions, plus a warning that the main site deploy must not delete `compras/`.

- [ ] **Step 1: Write `deploy/smoke.sh <base-url>`.** It curls `/` (200, has `<div id="root">`), `/compras/nueva` (200, SPA fallback), `/api/auth/me` (200 JSON `{"authenticated":false}`), `/api/receipts` (401) and `/kt-app/` or `/.git` (404 or 403), and checks the `X-Robots-Tag` header. It exits non-zero on any mismatch.
- [ ] **Step 2:** Run it against the local Apache-equivalent: `php -S localhost:8081 -t dist/docroot` cannot apply `.htaccess`, so run `docker run --rm -p 8081:80 -v $PWD/dist/docroot:/var/www/html php:8.1-apache` with `a2enmod rewrite headers`, mount `dist/kt-app`, and point to a dev DB. Expected: FAIL before build.sh exists.
- [ ] **Step 3:** Implement `build.sh`, the `.htaccess` files, robots.txt and the README.
- [ ] **Step 4:** Run `deploy/build.sh --app-root=/kt-app`, start the PHP 8.1 Apache container, then run `deploy/smoke.sh http://localhost:8081`. Expected: all checks pass. Because this runs on **PHP 8.1**, it also proves the version floor. Run `vendor/bin/phpunit` inside the container too.
- [ ] **Step 5:** Commit `chore: deployment packaging and README`.
- [ ] **Step 6 (owner-assisted, not automatable):** with the owner, walk through the README cPanel checklist on HostGator, deploy, run `smoke.sh https://compras.lalabranza.cl`, perform one test backup + restore, and import the real spreadsheet.
