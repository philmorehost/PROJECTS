#!/bin/sh
# Invoke with /bin/sh: no executable-bit dependency, browser session or cron secret.
# BC_REQUERY_PHP may pin a trusted absolute PHP CLI path; never reads request input.
set -eu
umask 027
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
probe='if (PHP_SAPI === "cli" && PHP_VERSION_ID >= 80200 && extension_loaded("mysqli") && extension_loaded("curl")) { echo "BC_REQUERY_CLI_OK"; } else { exit(1); }'
valid_php() {
    [ -x "$1" ] && [ "$("$1" -r "$probe" 2>/dev/null)" = 'BC_REQUERY_CLI_OK' ]
}
PHP_CLI=''
if [ -n "${BC_REQUERY_PHP:-}" ]; then
    case "$BC_REQUERY_PHP" in /*) ;; *) echo 'BC_REQUERY_PHP must be an absolute PHP CLI path.' >&2; exit 2 ;; esac
    if valid_php "$BC_REQUERY_PHP"; then PHP_CLI=$BC_REQUERY_PHP;
    else echo 'Configured PHP is not CLI PHP 8.2+ with mysqli and curl. No work performed.' >&2; exit 2; fi
else
    for candidate in /usr/local/bin/php /usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php /opt/plesk/php/8.4/bin/php /opt/plesk/php/8.3/bin/php /opt/plesk/php/8.2/bin/php; do
        if valid_php "$candidate"; then PHP_CLI=$candidate; break; fi
    done
    if [ -z "$PHP_CLI" ]; then
        candidate=$(command -v php 2>/dev/null || true)
        if [ -n "$candidate" ] && valid_php "$candidate"; then PHP_CLI=$candidate; fi
    fi
fi
if [ -z "$PHP_CLI" ]; then
    echo 'No CLI PHP 8.2+ with mysqli and curl found. Set BC_REQUERY_PHP to a verified CLI executable. No work performed.' >&2
    exit 2
fi
cd "$ROOT"
# A safe diagnostic never loads application config, connects to DB or queries a provider.
if [ "$#" -eq 1 ] && [ "$1" = '--check' ]; then
    echo "Verified PHP CLI: $PHP_CLI"
    exec "$PHP_CLI" "$ROOT/cron/process_requery_queue.php" --check
fi
if [ "$#" -ne 0 ]; then echo 'Usage: /bin/sh cron/run_requery_queue.sh [--check]' >&2; exit 2; fi
mkdir -p "$ROOT/logs" || { echo 'Cannot create requery logs directory.' >&2; exit 2; }
[ -w "$ROOT/logs" ] || { echo 'Requery logs directory is not writable.' >&2; exit 2; }
exec >> "$ROOT/logs/requery_queue.log" 2>&1
exec "$PHP_CLI" "$ROOT/cron/process_requery_queue.php"
