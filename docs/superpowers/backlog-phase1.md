# Phase 1 — deferred review findings (post-launch backlog)

Collected from per-task and final reviews on 2026-11-04. Items fixed later in the build are excluded. Triage per the final review: all "can wait" unless noted.

## Before launch (owner-assisted, no code expected)
- Verify on HostGator that `REMOTE_ADDR` is the real client IP (login throttling keys on it; behind a proxy one attacker could lock out the owner).
- Run one test backup + restore (README).
- Run `deploy/smoke.sh https://compras.lalabranza.cl`.
- Import the real CONTROL DE PRECIOS.xlsx (upload the .xlsx, not a Windows-1252 CSV).

## Backlog
- (T1) Router static segments not preg_quote'd
- (T1) Migrator DDL non-atomic / ';' + trailing comment / no lock (doc note)
- (T1) Db overrides sql_mode wholesale (drops ONLY_FULL_GROUP_BY)
- (T1) MigratorTest extends TestCase, drops tables directly
- (T1) .htaccess HTTP_AUTHORIZATION rule on second pass; 404 vs 405
- (T2) no half/negative-tie fixtures (spread [[1,1],-1], costs [50,factura]); enteredAmount not in fixtures
- (T2) Compare diff forced 0 when pct rounds 0; spread empty/negative amounts unguarded; baseQuantity partial-package fixtures
- (T3) session_regenerate_id branch untested (CLI fallback); no test that id changes on login
- (T3) throttle tests missing (success not counted, >15min ignored); check-then-insert race
- (T3) throttle keys REMOTE_ADDR (verify HostGator proxy topology)
- (T3) GET /auth/me starts session for anonymous callers
- (T3) gc_maxlifetime runtime vs host cron cleanup
- (T3) Settings::set writes not in transaction; numbers stored unnormalized
- (T3) /auth/password no throttle, no other-session invalidation; set-password stty restore; no Retry-After
- (T4) listProducts N+1 queries for matched_alias
- (T4) SELECT ? LIKE ? uses connection collation not unicode_ci
- (T4) base_unit lock check not transactional; LIKE escape w/o ESCAPE clause
- (T4) test gaps (wildcard q, rename collision, guard 1062, active=0/limit); MigratorTest asserts only [0]
- (T4) ñ folds to n (Piña/Pina collide) — plan-mandated collation behavior
- (T5) real transaction commit/rollback + 1062 race branch untested (DbTestCase wraps tx)
- (T5) no upper bounds on money/quantity → PDO overflow 500
- (T5) package unit error key; empty-string package fields 422
- (T6) baseline same-receipt lineOrder branch untested directly; annotate/baseline agreement untested; convoluted audit assertion; rollback untested; validate before void check (422 vs 409); list N+1; paging boundary
- (T7) expectClose null check; template leftovers (web/README.md, icons); skipLibCheck/eslint comment; pin PHP version in README
- (T8) packaged-mode quantity/entry_unit errors not shown; 401 toast; 403 csrf unmapped; thin tests (buildPayload, 409/422, retry key); tap targets <44px; ProductPicker stale exact check; RequireAuth no retry
- (T8) hybrid-device hover-open then outside click needs 2 clicks; 409 dialog & 422 rendering no automated coverage
- (T9) dashboard from=garbage → 500; CSV formula injection; total_paid vs by_category ±1 peso; CSV href ignores BASE_URL (matters for /compras/ subpath fallback!); chart same-date points overwrite; test gaps (undated+range, receipt_number, CSV quote/newline)
- (T10) dump in-memory; download test weak; readSheet API cast/catch-all; CSV UTF-8 only (Windows-1252 garbles); "Fila N" numbering; no import component test
- (T10) blur clears dup flag w/o change; per-row Aceptar; backup protection test checks files only
- (T11) Request prefix regex accepts any single segment; smoke gaps (cron no-token, config.php); README typo, ea-php81 path, set-password no-shell vague
- (T11) cron token in query string (logs); chmod 600 clave.txt suggestion; 301 on POST downgrade (safety net only)
- (final) Drafts cannot be discarded (no "Descartar" action).
- (final) Replayed save after a lost response can drop later edits (same idempotency key).
- (final) Backup not a consistent snapshot (wrap in START TRANSACTION WITH CONSISTENT SNAPSHOT).
- (final) README no-shell migration path doesn't fill schema_migrations.
- (final) display_errors set after config.php loads.
- (final) Import commit has no batch idempotency.
- (final) No X-Content-Type-Options / frame-ancestors headers.
- (final) Audit view shows totals only, not line-level diff.
