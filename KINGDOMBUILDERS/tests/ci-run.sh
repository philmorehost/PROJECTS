#!/usr/bin/env bash
# Run a CI command and, on failure, publish the tail of its output as annotations.
# Actions logs are large; annotations survive where log downloads may not.
set -uo pipefail
log="$(mktemp)"
trap 'rm -f "$log"' EXIT
"$@" 2>&1 | tee "$log"
status=${PIPESTATUS[0]}
if [ "$status" -ne 0 ]; then
  # Every explicit failure first (they may be far above the tail), then the tail
  # for context, so a CI run explains itself without a log download.
  while IFS= read -r line; do
    line="${line//'%'/'%25'}"
    line="${line//$'\r'/}"
    echo "::error::${line}"
  done < <(grep -E '(^|[[:space:]])FAIL|Fatal error|Uncaught' "$log" | head -n 25)
  while IFS= read -r line; do
    line="${line//'%'/'%25'}"
    line="${line//$'\r'/}"
    echo "::error::${line}"
  done < <(grep -v '^[[:space:]]*$' "$log" | tail -n 8)
fi
exit "$status"
