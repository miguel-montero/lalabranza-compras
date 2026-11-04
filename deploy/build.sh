#!/usr/bin/env bash
# Build the deployable bundle into dist/.
#   deploy/build.sh [--base=/compras/] [--app-root=/home/USER/kt-app]
set -euo pipefail

BASE="/"
APP_ROOT="/home/USER/kt-app"
for arg in "$@"; do
  case "$arg" in
    --base=*) BASE="${arg#--base=}" ;;
    --app-root=*) APP_ROOT="${arg#--app-root=}" ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done
case "$BASE" in /*/|/) ;; *) echo "--base must start and end with / (got $BASE)" >&2; exit 2 ;; esac
case "$APP_ROOT" in *"'"*) echo "--app-root must not contain quotes" >&2; exit 2 ;; esac

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"

( cd "$ROOT/web" && npm ci && VITE_BASE="$BASE" npm run build )

rm -rf "$DIST"
mkdir -p "$DIST/docroot/api" "$DIST/kt-app"

# docroot: SPA + API front controller
cp -R "$ROOT/web/dist/." "$DIST/docroot/"
cp "$ROOT/api/public/index.php" "$ROOT/api/public/.htaccess" "$DIST/docroot/api/"
printf "<?php\nreturn '%s';\n" "$APP_ROOT" > "$DIST/docroot/api/app-root.php"

# kt-app: PHP code outside the docroot (no vendor/, no tests/)
cp "$ROOT/api/bootstrap.php" "$ROOT/api/config.example.php" "$DIST/kt-app/"
cp -R "$ROOT/api/src" "$ROOT/api/migrations" "$ROOT/api/bin" "$DIST/kt-app/"
find "$DIST" -name '.DS_Store' -delete

echo "Built $DIST (base=$BASE, app-root=$APP_ROOT)"
