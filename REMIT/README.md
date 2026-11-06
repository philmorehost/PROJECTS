# REMIT — RCCG Youth Province treasury

Initial native PHP implementation. No framework, Composer package or frontend build is required. **Not yet production-complete.** License validation and software updates use the existing License Manager API. No real credentials are included in source.

## Implemented

- Four-stage, CSRF-protected, licence-key-gated installer with a filesystem mutex and installation lock. No CLI operator token is required.
- PDO prepared statements, persistent connections, InnoDB/foreign-key schema, unique parish/month reports; province-level users have a **nullable hierarchy ID** instead of a fictitious province node.
- Login with generic errors, session rotation, 30-minute inactivity expiry, HttpOnly/SameSite cookies, rechecking suspension on every request, CSRF on all writes and output escaping.
- Configurable account and IP failure windows, application IP blocks, suspension scope and privileged-role exceptions, audit log, PIN throttling, recognized-IP green king badge after five clean session logins. Recognition never bypasses security. Unknown accounts trigger IP blocks when IP protection is enabled.
- Province-wide treasurer/admin views and recursive hierarchy-scoped representative/officer views. Only parish officers submit their own reports. Representatives are read-only.
- Integer-cent money arithmetic (64-bit required), half-up basis-point allocation, monthly income reports, cumulative bank-confirmed reconciliation under row locks with a transaction PIN and audit entry.
- Responsive bright dashboard driven by actual database records, ledger filtering, directory builder, atomic/repeatable CSV directory import, officer provisioning, settings and security event views.
- Transactional notification outbox and firewall work queue (no delivery/execution workers yet).

## Requirements / deployment

PHP **8.2+**, 64-bit; PDO, pdo_mysql, curl, gd, json, OpenSSL (plus ZIP for software updates); MySQL 8+ or MariaDB 10.6+; Apache 2.4/LiteSpeed with .htaccess enabled. Use a dedicated empty database and database user with only that database's privileges. DDL privileges are needed for installation; reduce privileges afterward. Never use the database root account.

1. Upload the contents of this folder to a dedicated document directory/subdomain. Keep sibling projects separate.
2. Verify `app`, `config`, `storage`, `database`, `bin`, and `tests` return **403/404 over HTTP**. `.htaccess` protects them but server configuration must honor it. Disable directory listing. Do not serve the repository root as a public document root.
3. Enable HTTPS **before setup**. If using a reverse proxy, configure trusted proxy addresses at the server layer and pass verified HTTPS/client address information. The application intentionally ignores all `X-Forwarded-*` headers; never let arbitrary clients supply trusted addresses. Confirm secure session cookies in the browser.
4. Make `config` writable by the PHP user. Open `index.php` and enter your License Manager key. Stage 1 posts `key` and the detected hostname to `https://manager.pmhserver.name.ng/api.php`. The key must be active and authorized for that hostname. The obsolete `bin/setup-token.php` command now only prints a retirement notice.
5. Follow environment → empty database → administrator/PIN → completion. The database is provisioned in cPanel, not created by PHP. Completion writes `config/installed.lock`. Licence credentials/cache are stored in private `config/license.json` and `config/license-cache.json` with owner-only permissions. Configuration files are written with mode 0600 (confirm hosting-user compatibility).
6. Back up the database and configuration securely. Once installed, remove write access to `config` if practical. Never remove the installation lock on a live installation.
7. Configure cron: `php /absolute/path/to/REMIT/bin/maintenance.php` every five minutes. Display retention is 5/10/15/60 minutes; deletion retains failures until **all enforcement windows** have expired. Non-failure audit events are retained indefinitely pending a formal archival policy.
8. Enable OPcache at hosting level: `opcache.enable=1`, `opcache.memory_consumption=128`. Do not disable timestamp validation without a deployment cache-reset procedure. Enable transport compression. Only fingerprinted CSS/JS receive immutable caching; change the filename whenever contents change.

### Interrupted installation

MySQL DDL auto-commits. Migration is deliberately **not retried destructively**. If stage 2 fails, inspect server logs, remove only this new installation's partial tables using a database administration tool, and retry with an empty database. If configuration already exists or the browser loses its setup session, an operator must inspect the installation before recovery: for an unused installation only, remove the partial database/configuration and restart with a valid licence key. Do not apply this procedure to an installation containing real transactions. Protect `setup.mutex` like other config files.

### Local development / verification

```sh
cd REMIT
php tests/run.php
php tests/license-updates.php
php tests/church-portal.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
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

- **Product activation and updates:** implemented as described below. No obfuscated helper was added; security logic remains auditable. Live merchant hosting validation and update testing are still required before rollout.
- **PayHub:** require official endpoint documentation, merchant credentials supplied via server environment, signature/replay rules, settlement currency/amount verification and sandbox. No virtual accounts, payment initiation or webhook routes are exposed yet. Add an idempotent payment-events table and transactional amount reconciliation before enabling callbacks.
- **Receipts:** no upload/download endpoint is exposed yet; `storage/receipts` is private. Implement MIME/content validation, size limits, randomized names, malware review and an authenticated scope-checked download handler before exposing proof uploads.
- **Firewall:** application blocks work immediately; `firewall_jobs` contains **pending requests only**. Build a separately privileged worker with a narrow, validated firewall adapter (e.g. CSF API), management-IP safeguards, expiry/unblock handling, idempotent retries and status reporting. Never run privileged shell commands in PHP web requests. Shared hosting may not permit this integration.
- **Email:** notifications are queued, not delivered. Add a retry-safe, atomically claimed SMTP worker and escaped HTML templates. Never email passwords or PINs. Compliance scheduling and device fingerprinting are not implemented (alerts currently use unrecognized IPs only).
- Income category breakdowns, category-specific split configuration, receipt upload/audit workflow, extended payment history/overpayment policy, period-aware compliance, weekly charts, live polling, interactive tree view, exports, admin user lifecycle/PIN reset, password change/recovery, setup recovery, MFA, integration tests and deployment hardening remain.

## Acceptance checklist (requires PHP + MySQL)

- Run money tests and PHP lint; install on both supported database engines.
- Verify private paths are inaccessible, HTTPS cookies, CSP, CSRF rejection and generic login errors.
- Provision two zones/areas/parishes. Confirm cross-scope reads/writes cannot leak other parish records; representatives cannot mutate data.
- Test CSV rollback/conflicts/reimport and duplicate monthly report rejection.
- Test failure thresholds, time windows, privileged/local exceptions, immediate unknown-user IP blocks, retained locks after toggles, five clean distinct sessions, suspension recheck and PIN lockout.
- Test concurrent payment confirmation/replays; confirmed cumulative amount cannot decrease or exceed allocation.
- Test setup retries, configuration permissions, backups/restore and maintenance retention against enforcement windows.
- Perform a security review before handling real remittances. No gateway, firewall or email delivery is asserted by the UI. The licence/update integration must also be tested against the deployed LM.

## Verification in this workspace

- PHP syntax checks and PHP 8.2 grammar parsing are run on the PHP files, including the new licence and updater classes.
- `tests/run.php`: **1,020 money assertions passed**, including DECIMAL limits, half-up rounding, invalid inputs and randomized roundtrips.
- JavaScript passed `node --check`; the first-run installer rendered without PHP warnings/errors in a CLI request simulation.
- Native PHP 8.2, Apache/LiteSpeed and MySQL/MariaDB integration were **not available/tested** here. WASM checks do not establish database correctness or production readiness.


## License Manager activation

The public documentation is `https://manager.pmhserver.name.ng/api-docs.php`. The deployed validation contract is `POST /api.php` with form fields `key` and `domain`. Only numeric/string `1` is accepted as valid; `0` and `suspended` reject access. Unexpected statuses and malformed responses fail closed. There is no direct database coupling to LM.

- **New install:** enter your LM key in environment stage 1. The detected hostname is lowercased, validated and stripped of its port. The persisted hostname must match subsequent requests; `X-Forwarded-Host` is not trusted. Configure your web server to reject unrecognized Host headers and use your final production hostname during setup.
- **Existing install:** the provincial administrator signs in and opens **Portal settings → Licence & activation**. Missing/invalid licences restrict other users; the administrator retains sign-in and licence-repair access. Non-licence record changes require valid activation.
- Keys are never rendered back to the browser, placed in download URLs, or logged by REMIT. Successful activation is logged without credentials. The **existing LM server source logs licence keys**; review and restrict/remove those manager-side logs separately before production.
- Successful checks are cached for one hour. Confirmed rejection is cached for 60 seconds and never receives outage grace. An unreachable manager permits ordinary portal use only from a prior successful check less than 24 hours old. Explicit rechecks, activation and update operations require an online result; installation and updates cannot use grace.
- HTTPS certificate verification remains enabled, redirects are disabled, response/download size limits and timeouts apply, and only the fixed manager host is contacted. Permit outbound HTTPS to that host on your actual hosting server. No secret key is requested in chat.
- If a setup browser session from the older operator-token installer exists, it is reset to stage 1 unless it has completed licence validation. An installation that already migrated a database but lost its installer session still requires the interrupted-installation recovery procedure above; no data is deleted automatically.

## Admin software updates from LM

The public docs explain validation but not the update payload. The updater contract is taken from this repository's **`LM/check-update.php` and `LM/download.php`**:

1. Admin selects **Check for updates** under Portal settings. REMIT validates the licence online and POSTs `license_key` and `domain` to `/check-update.php`.
2. LM returns `status: "success"`, `latest_version`, `changelog`, `checksum` (SHA-256), and a one-time `download_url`. The update is scoped by the **LM licence tier**, not a REMIT-specific API field. Make sure the licence tier publishes the correct REMIT release.
3. **Pull & verify release** makes a fresh check (tokens expire/are one-use), downloads only from `https://manager.pmhserver.name.ng/download.php?token=…`, checks SHA-256 and ZIP structure, and stages privately under `storage/updates/`. Download tokens are not displayed or persisted in update metadata.
4. **Install verified release** requires a transaction PIN, an explicit external-backup confirmation and a fresh valid licence. The updater serializes update jobs and excludes other portal requests, backs up every existing destination, records originally absent files, and briefly enables maintenance while replacing code with same-directory atomic renames. Configuration, receipts, licence files and the database are not overwritten.
5. A failed file replacement attempts rollback. Incomplete rollback or an interrupted update retains maintenance for operator recovery. Successful installs retain previous files and a rollback inventory in the private staging directory. OPcache entries for replaced PHP files are invalidated.

### Release/package requirements (including LM GitHub pulls)

LM can obtain the release from GitHub upstream; REMIT still consumes the published LM ZIP, **not GitHub directly**. This branch does not change LM's upstream GitHub publishing implementation. Its deployed publisher must produce the following metadata:

- `version.json` inside the REMIT payload: `{"product":"remit","version":"0.3.0"}` (example next version). Update this file in the release commit; the returned LM `latest_version` must match exactly. Current baseline is `0.2.2`.
- `manifest.json` in the REMIT payload, or at ZIP root (LM's auto-generated manifest is supported): `{"version":"0.3.0","database_queries":[],"files_to_delete":[]}`. Changelog/tier metadata may also be present.
- Supported layouts: application files at ZIP root; `REMIT/`; or `PROJECTS-main/REMIT/` (the wrapper name may vary). Sibling project contents in a full GitHub archive are **never applied**. Archives are still subject to size/entry validation across the entire ZIP.
- Required files: `index.php`, `app/bootstrap.php`, `version.json`. A different `product` is rejected even if LM accidentally serves another application's tier.
- Automatic installation permits selected source files under `app`, `bin`, `tests`, `database`, `public/assets`, and known root files. It never executes packaged SQL or PHP migration hooks. Any non-empty `database_queries` or `files_to_delete` list rejects automatic installation. Schema-changing releases require a reviewed manual migration/deployment.
- Protect private files when building packages: do not include actual `config/database.php`, licence credentials, `.git`, receipts, backups or runtime storage. Private-path overwrite attempts are rejected (the standard config/storage `.htaccess` markers are ignored). ZIP links, special files, path traversal, duplicate/case-colliding files, encrypted entries, over 5,000 entries, over 25 MB download or over 100 MB expanded contents are rejected. Individual entries are capped at 10 MB.
- The checksum is served over verified HTTPS by LM, **not an independent signature**. Treat LM and its publishing account as trusted code-signing authorities. Restrict LM admin access and verify the release pipeline. REMIT does not claim that the API cryptographically signs releases.

### Update recovery / backups

Before every install, back up the entire application and MySQL database **outside this web directory**. Automatic backups cover only files touched by a release, not database snapshots. Do not delete storage while installing. For normal successful upgrades, review/prune old `storage/updates/` directories manually after the retention period you choose; never publish or download them through a public URL.

If `storage/maintenance.json` remains after a crash, keep public traffic stopped. Read its backup ID, then inspect `storage/updates/<id>/rollback.json`. For each path whose value is `true`, restore the corresponding file from `backup/<path>`; for each path whose value is `false`, remove only that newly installed file if present. Restore the original permissions, verify code syntax and the old `version.json`, clear/invalidate OPcache, and remove `storage/maintenance.json` **only after recovery is complete**. If backups were not fully written, restore your external application backup instead. Do not run application code from a partly applied release. Database rollback is not needed for this file-only updater; schema migrations are intentionally not executed.

### New verification

`php tests/license-updates.php` covers activation fields, cached validation, suspension, domain binding, outage-grace boundaries, URL restrictions, protected paths, ZIP traversal/links, wrong products/versions, migration/deletion refusal, GitHub subtree isolation and a complete staged file installation with backup/config preservation. The licence transport is mocked; no real LM keys or live requests are used. A deployed cPanel/LM integration test and failure-recovery drill remain required.

## 0.2.0 — Bright church portal and Youth Thanksgiving workflow

The portal now uses a light blue/white design with teal confirmation states, larger offering inputs and explicit total/retention/payable cards. No demo transactions or gateway settlements are fabricated.

### Upgrade an existing installation

1. Back up the application **and database** externally. Deploy the complete updated `REMIT` directory without overwriting generated private config or storage.
2. Sign in as provincial administrator, open **Portal settings**, and select **Enable registration & payment features**. This executes `database/migrations/002-church-portal.sql`, an additive/idempotent migration serialized with a MySQL advisory lock. DDL auto-commits; inspect logs and retry if interrupted. No existing ledger amounts or rates are rewritten.
3. Configure **Church retention / deduction (%)**, the provincial receiving bank account, and whether public registration is open.
4. Review new applications under **Church hierarchy → Church registrations**. Verify parish authority through an independent trusted contact before approving. Multiple approved officers may represent one parish; they share parish ledger visibility, but each officer has separate bank profile details.
5. Confirm the PHP OpenSSL extension and config-directory permissions. The first account-detail submission creates `config/bank.key` (32 random bytes, owner-only permissions). Back up this key securely **with your database backups**, outside the public document root. Losing it makes existing account numbers undecryptable. Do not regenerate or replace it during an update or restore. Account details remain masked to registration reviewers and are shown in full only to the owning officer's authenticated profile.

New installations apply the migration automatically during database setup. Old installations remain able to view their existing data and use licence repair while awaiting upgrade; new reports and church features require the migration. Migration activation requires a valid licence, CSRF protection and the provincial administrator role. Restrict database DDL permissions again after activation if using a least-privilege runtime account.

### Percentage meaning

**Total offering is always 100%.** The administrator sets the percentage the church **retains / deducts** from its total. The remainder is the percentage payable to the province. For example: ₦100,000 total − 20% church retention = ₦80,000 payable and ₦20,000 retained. Enter `20`, not `2000`, in the new admin field. The old internal `levy_basis_points` setting still stores the *payable* percentage to preserve existing installations; existing default 10% payable therefore appears as 90% retained until changed by the administrator.

The payable amount is rounded half-up to cents, and retention is the exact remainder so both always add to the full collection. Server-side validation/calculation is authoritative; the browser preview uses integer arithmetic too. Each new report snapshots the rate, service type and retention in `report_allocations`. Policy changes never recalculate old reports. A 100%-retained / zero-payable report is marked complete without creating a payment. One report per parish/month is retained from the existing model (multiple service entries within a month are not supported yet).

### Church self-registration and account privacy

`index.php?page=register` lets an officer search the existing church directory, select their parish, supply officer/contact details, choose a password and submit the church's bank name, account name and 10-digit Nigerian account number. The directory exposes only parish/code/area/zone labels, not other officers' contacts or banking details. No new church, privileged role or login access is created by an unapproved application. The public route is available only when registration is enabled, the schema is ready and the site's licence is valid.

Every valid-CSRF submission attempt is counted server-side (five per IP per hour), including invalid inputs. Approval creates only a parish-officer account for the requested parish, copies its encrypted bank profile and clears the application's password hash. Rejection also clears the password hash. Duplicate emails receive the same generic acknowledgement without creating another account. Registration is admin-verified, not email-verified; automatic email delivery and password recovery remain pending. Configure a deployment-level request limit/WAF as additional spam protection; shared/NAT IPs share this application limit.

Account numbers use AES-256-GCM with a local key; only their last four digits are retained separately for masked review. Bank name, account name, officer contacts and registration records are personal information: restrict database/backups, define a retention/privacy policy, and do not include credentials, PINs, BVNs or card details. Registration approvals and review decisions are retained on each application; email notifications are **queued, not delivered** until an email worker is configured. Officer profile changes do not alter the provincial receiving account and do not perform bank-account ownership verification.

### Manual transfers and notifications

Church officers see the configured provincial account, enter their full Youth Thanksgiving collection and save their calculated report. After transferring funds, they submit an amount, actual bank transaction reference and transfer date. Notifications are pending and do not change confirmed revenue. Pending amounts cannot exceed the outstanding balance; report access is parish-scoped. Treasurer/admin approval requires the transaction PIN and independent statement verification. Approval adds the notification amount once, with row locks, audit records and replay protection. Rejection leaves the ledger unchanged and can include a review note. Existing cumulative manual confirmation remains available to treasurers for bank-statement reconciliation; it does not auto-approve or consume pending notifications. Resolve/reject any conflicting pending notifications before confirming them.

**Automated PayHub deposit remains unavailable.** The payment-method card is explicitly marked unavailable, with no initiation, virtual-account or webhook route pretending to work. The published PayHub page still lacks the detailed authentication/initialization/verification/signature contract. Receipt upload/download also remains unimplemented; the new notification form takes bank transaction details only, not proof files.

### Tests for this release

`php tests/church-portal.php` passes **156 assertions** covering percentages, cent conservation, encrypted account integrity, registration/approval/rejection, rate limiting, scope checks, notification/PIN/replay handling, confirmed balances, historical rate snapshots and view rendering. It executes real SQL through a SQLite compatibility harness that adapts MySQL syntax. It does **not** validate MySQL DDL, MySQL row locks, simultaneous approvals, cPanel permissions or live external APIs. Run the MySQL deployment acceptance checks, two-parish authorization tests and concurrent payment tests before handling real remittances.


## 0.2.1 — Readability, mobile navigation and dashboard improvements

- Body text is now 16px, inputs/selects at least 16px (avoiding input-focus auto-zoom on phones), with larger labels, explanatory text and accessible focus indicators. Buttons and navigation controls have at least 44px touch targets. Browser zoom remains enabled.
- Authenticated views at 900px and below use a fixed footer icon bar: Home, Payments/Ledger, Churches, More. The top hamburger opens the same role-aware native dialog. The dialog supports Escape, a visible close button, backdrop dismissal, focus containment/return and scroll locking. Profile/admin links are shown according to the current role; these remain navigation conveniences, not authorization controls. Extra fallback links remain available when scripting/dialog support is absent.
- Bottom navigation accounts for safe-area insets; page/footer padding prevents it covering content. The desktop sidebar remains available above 900px. There are no new external scripts, fonts or icon services; footer icons are inline accessible SVG.
- Dashboard shortcuts guide officers to reporting, payments and their account details; administrators get review, directory and percentage shortcuts. Guidance uses existing scoped database totals, with no fabricated collection trends. Report completion is explicitly about submitted reports, not churches that have not reported.
- The ledger now combines search and status filtering, announces the loaded result count, and displays a no-matches message. At 600px and below its rows become labelled cards; other wide administrative tables retain internal scrolling. Filtering only covers the currently loaded reports (existing 100-report limit), not a full-history search.
- No schema migration is required for this patch. Deploy all code/assets together. Earlier 0.2.0 installations still need the documented church-feature schema activation if not already done. Clear hosting/CDN HTML caches if needed; asset filenames are content-fingerprinted.

Validation: 39 PHP role-specific navigation assertions, 237 Chromium browser assertions over three roles and five viewport widths, plus the existing 156 church-portal, 1,020 money and 76 licence/update assertions. Browser checks use isolated **test fixtures**, not live user/financial data. They cover font sizes, touch targets, modal open/close/Escape/focus return, role menus, card layout, ledger filters, script-free fallback links and absence of page-level horizontal overflow. Actual iOS/Android device testing and production MySQL/cPanel acceptance remain recommended.

Optional browser regression (requires PHP, Node, Playwright and an installed Chromium):

```sh
mkdir -p /tmp/remit-mobile-fixtures
for role in parish_officer provincial_admin area_rep; do
  php tests/mobile-ui.php "$role" "/tmp/remit-mobile-fixtures/$role.html"
done
# Install Playwright in an external development tools directory, not production:
npm install --prefix /tmp/remit-browser-tools playwright
# Point to your installed Chromium binary; omit CHROME_EXECUTABLE for Playwright-managed Chromium.
CHROME_EXECUTABLE=/path/to/chromium NODE_PATH=/tmp/remit-browser-tools/node_modules \
  node tests/mobile-browser.js /tmp/remit-mobile-fixtures
```

## 0.2.2 — Downloadable church import sample

Provincial administrators can open **Church hierarchy → Import a church directory → Download sample CSV**. The download is generated server-side through the authenticated portal, requires an active licence, and is not a public asset. It uses the importer's shared column definition:

```csv
zone,area,parish,parish_code,contact_person,phone_number
```

The attachment `remit-church-import-sample.csv` is UTF-8 without a BOM, uses CRLF rows, and contains three explicitly fictional parish examples showing a shared zone and two areas. Replace all sample rows before uploading; keep the exact header/order, assign unique parish codes, and treat phone numbers as text in spreadsheet tools to preserve leading zeroes. Downloading does not read/export existing churches or change the database.

No schema migration or frontend asset update is needed for this patch. Deploy the updated PHP files together. `php tests/import-sample.php` verifies admin authorization, CSV formatting/header compatibility and sample import/reimport using a SQLite compatibility harness (26 assertions). Live MySQL and browser download headers still need deployment verification.
