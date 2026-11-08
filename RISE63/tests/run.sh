#!/bin/sh
# Runs every available check. Uses a real PHP binary when present, otherwise php-wasm.
set -e
cd "$(dirname "$0")/.."

if command -v php >/dev/null 2>&1; then
  echo "== PHP lint =="
  find . -name '*.php' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l >/dev/null
  echo "OK"
  echo "== unit =="; php tests/unit.php
  echo "== render =="; php tests/render.php
  if [ -n "$RISE63_TEST_DSN" ]; then
    echo "== integration (MySQL) =="; php tests/integration.php
  else
    echo "== integration skipped: set RISE63_TEST_DSN/USER/PASS (database name must end in _test) =="
  fi
else
  echo "No php binary found; using php-wasm."
  node tests/support/phpwasm-lint.mjs
  node tests/support/phpwasm-test.mjs
  node tests/support/phpwasm-render.mjs
fi
