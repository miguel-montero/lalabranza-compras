#!/usr/bin/env bash
# Post-deploy smoke test.  deploy/smoke.sh <base-url>   e.g. https://compras.lalabranza.cl
# (for the subpath fallback: https://lalabranza.cl/compras).  Exits non-zero on any mismatch.
set -u
BASE="${1:?usage: smoke.sh <base-url>}"
BASE="${BASE%/}"
FAIL=0

check() { # name expected actual
  if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1 (expected $2, got $3)"; FAIL=1; fi
}
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
contains() { # name needle haystack
  if printf '%s' "$3" | grep -qF -- "$2"; then echo "ok   $1"; else echo "FAIL $1 (missing: $2)"; FAIL=1; fi
}

body=$(curl -s "$BASE/")
check "GET / -> 200" 200 "$(status "$BASE/")"
contains "/ has root div" '<div id="root">' "$body"
check "GET /compras/nueva -> 200 (SPA fallback)" 200 "$(status "$BASE/compras/nueva")"
contains "/compras/nueva serves the app" '<div id="root">' "$(curl -s "$BASE/compras/nueva")"
check "GET /api/auth/me -> 200" 200 "$(status "$BASE/api/auth/me")"
contains "/api/auth/me is unauthenticated JSON" '"authenticated":false' "$(curl -s "$BASE/api/auth/me" | tr -d ' ')"
check "GET /api/receipts -> 401" 401 "$(status "$BASE/api/receipts")"
for p in /.git/config /.htaccess; do
  code=$(status "$BASE$p")
  if [ "$code" = 404 ] || [ "$code" = 403 ]; then echo "ok   GET $p -> $code"; else echo "FAIL GET $p (expected 404/403, got $code)"; FAIL=1; fi
done
# kt-app must live outside the docroot: its URL is either 404/403 or the SPA fallback, never PHP source
code=$(status "$BASE/kt-app/bootstrap.php")
if [ "$code" = 404 ] || [ "$code" = 403 ] || { [ "$code" = 200 ] && ! curl -s "$BASE/kt-app/bootstrap.php" | grep -q '<?php'; }; then
  echo "ok   GET /kt-app/bootstrap.php not exposed ($code)"; else echo "FAIL /kt-app/bootstrap.php exposed ($code)"; FAIL=1; fi
contains "X-Robots-Tag noindex" 'noindex' "$(curl -sI "$BASE/" | grep -i '^x-robots-tag:')"
contains "robots.txt disallows all" 'Disallow: /' "$(curl -s "$BASE/robots.txt")"

[ "$FAIL" = 0 ] && echo "SMOKE PASSED" || echo "SMOKE FAILED"
exit "$FAIL"
