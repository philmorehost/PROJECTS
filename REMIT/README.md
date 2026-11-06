# REMIT — RCCG Youth Province treasury

Initial native PHP implementation. No framework, Composer package or frontend build is required. **Not yet production-complete.** No external APIs have been guessed or simulated.

## Implemented

- Four-stage, CSRF-protected, operator-token-gated installer with a filesystem mutex and installation lock.
- PDO prepared statements, persistent connections, InnoDB/foreign-key schema, unique parish/month reports; province-level users have a **nullable hierarchy ID** instead of a fictitious province node.
- Login with generic errors, session rotation, 30-minute inactivity expiry, HttpOnly/SameSite cookies, rechecking suspension on every request, CSRF on all writes and output escaping.
- Configurable account and IP failure windows, application IP blocks, suspension scope and privileged-role exceptions, audit log, PIN throttling, recognized-IP green king badge after five clean session logins. Recognition never bypasses security. Unknown accounts trigger IP blocks when IP protection is enabled.
- Province-wide treasurer/admin views and recursive hierarchy-scoped representative/officer views. Only parish officers submit their own reports. Representatives are read-only.
- Integer-cent money arithmetic (64-bit required), half-up basis-point allocation, monthly income reports, cumulative bank-confirmed reconciliation under row locks with a transaction PIN and audit entry.
- Responsive dark dashboard driven by actual database records, ledger filtering, directory builder, atomic/repeatable CSV directory import, officer provisioning, settings and security event views.
- Transactional notification outbox and firewall work queue (no delivery/execution workers yet).

## Requirements / deployment

PHP **8.2+**, 64-bit; PDO, pdo_mysql, curl, gd, json; MySQL 8+ or MariaDB 10.6+; Apache 2.4/LiteSpeed with .htaccess enabled. Use a dedicated empty database and database user with only that database's privileges. DDL privileges are needed for installation; reduce privileges afterward. Never use the database root account.

1. Upload the contents of this folder to a dedicated document directory/subdomain. Keep sibling projects separate.
2. Verify `app`, `config`, `storage`, `database`, `bin`, and `tests` return **403/404 over HTTP**. `.htaccess` protects them but server configuration must honor it. Disable directory listing. Do not serve the repository root as a public document root.
3. Enable HTTPS **before setup**. If using a reverse proxy, configure trusted proxy addresses at the server layer and pass verified HTTPS/client address information. The application intentionally ignores all `X-Forwarded-*` headers; never let arbitrary clients supply trusted addresses. Confirm secure session cookies in the browser.
4. Make `config` writable by the PHP user. Run `php bin/setup-token.php`, and privately copy the resulting token into the installer at `index.php`. The operator token is **not remote product activation**.
5. Follow environment → empty database → administrator/PIN → completion. The database is provisioned in cPanel, not created by PHP. Completion writes `config/installed.lock` and removes the token. Configuration files are written with mode 0600 (confirm hosting-user compatibility).
6. Back up the database and configuration securely. Once installed, remove write access to `config` if practical. Never remove the installation lock on a live installation.
7. Configure cron: `php /absolute/path/to/REMIT/bin/maintenance.php` every five minutes. Display retention is 5/10/15/60 minutes; deletion retains failures until **all enforcement windows** have expired. Non-failure audit events are retained indefinitely pending a formal archival policy.
8. Enable OPcache at hosting level: `opcache.enable=1`, `opcache.memory_consumption=128`. Do not disable timestamp validation without a deployment cache-reset procedure. Enable transport compression. Only fingerprinted CSS/JS receive immutable caching; change the filename whenever contents change.

### Interrupted installation

MySQL DDL auto-commits. Migration is deliberately **not retried destructively**. If stage 2 fails, inspect server logs, remove only this new installation's partial tables using a database administration tool, and retry with an empty database. If configuration already exists or the browser loses its setup session, an operator must inspect the installation before recovery: for an unused installation only, remove the partial database/configuration and restart with a fresh token. Do not apply this procedure to an installation containing real transactions. Protect `setup.mutex` like other config files.

### Local development / verification

```sh
cd REMIT
php tests/run.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php bin/setup-token.php
php -S 0.0.0.0:8080 router.php
```

Use the provided development router, **not** plain `php -S`, because PHP's built-in server ignores .htaccess. A working MySQL/MariaDB server is required for installation and integration tests. Never expose development HTTP to untrusted networks.

### CSV

UTF-8, exact header (no BOM):

```csv
zone,area,parish,parish_code,contact_person,phone_number
Grace Zone,Hope Area,Living Water Parish,LWP001,Parish Officer,08000000000
```

Up to 5,000 rows and 2 MB. The importer reuses matching zone/area ancestors and parish codes, updates contact details for matching parishes, rejects code conflicts, and rolls back the entire import on failure. CSV contact persons are **not automatically given accounts**: the supplied specification has no officer email field. Provision accounts separately in the directory; share initial credentials via a trusted channel. Forced first-login password change and self-service password recovery remain to be implemented before broad rollout.

## Integration boundaries / remaining work

- **Product activation:** verified request/response, signing, licence binding and outage policy for `manager.pmhserver.name.ng` are required. This implementation does not contact that service or falsely claim activation. No obfuscated helper was added; security logic remains auditable. Enforce a reviewed activation integration before distribution if licences are required.
- **PayHub:** require official endpoint documentation, merchant credentials supplied via server environment, signature/replay rules, settlement currency/amount verification and sandbox. No virtual accounts, payment initiation or webhook routes are exposed yet. Add an idempotent payment-events table and transactional amount reconciliation before enabling callbacks.
- **Receipts:** no upload/download endpoint is exposed yet; `storage/receipts` is private. Implement MIME/content validation, size limits, randomized names, malware review and an authenticated scope-checked download handler before exposing proof uploads.
- **Firewall:** application blocks work immediately; `firewall_jobs` contains **pending requests only**. Build a separately privileged worker with a narrow, validated firewall adapter (e.g. CSF API), management-IP safeguards, expiry/unblock handling, idempotent retries and status reporting. Never run privileged shell commands in PHP web requests. Shared hosting may not permit this integration.
- **Email:** notifications are queued, not delivered. Add a retry-safe, atomically claimed SMTP worker and escaped HTML templates. Never email passwords or PINs. Compliance scheduling and device fingerprinting are not implemented (alerts currently use unrecognized IPs only).
- Income category breakdowns, multi-rate split configuration, receipt audit workflow, payment history/overpayment policy, period-aware compliance, weekly charts, live polling, interactive tree view, exports, admin user lifecycle/PIN reset, password change/recovery, setup recovery, MFA, integration tests and deployment hardening remain.

## Acceptance checklist (requires PHP + MySQL)

- Run money tests and PHP lint; install on both supported database engines.
- Verify private paths are inaccessible, HTTPS cookies, CSP, CSRF rejection and generic login errors.
- Provision two zones/areas/parishes. Confirm cross-scope reads/writes cannot leak other parish records; representatives cannot mutate data.
- Test CSV rollback/conflicts/reimport and duplicate monthly report rejection.
- Test failure thresholds, time windows, privileged/local exceptions, immediate unknown-user IP blocks, retained locks after toggles, five clean distinct sessions, suspension recheck and PIN lockout.
- Test concurrent payment confirmation/replays; confirmed cumulative amount cannot decrease or exceed allocation.
- Test setup retries, configuration permissions, backups/restore and maintenance retention against enforcement windows.
- Perform a security review before handling real remittances. No gateway, firewall or email delivery is asserted by the UI.

## Verification in this workspace

- All 12 PHP files passed PHP 8.5.12 WASM CLI syntax checks and a PHP 8.2 grammar parser.
- `tests/run.php`: **1,020 money assertions passed**, including DECIMAL limits, half-up rounding, invalid inputs and randomized roundtrips.
- JavaScript passed `node --check`; the first-run installer rendered without PHP warnings/errors in a CLI request simulation.
- Native PHP 8.2, Apache/LiteSpeed and MySQL/MariaDB integration were **not available/tested** here. WASM checks do not establish database correctness or production readiness.
