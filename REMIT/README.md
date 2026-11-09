# REMIT — RCCG Youth Province treasury

Initial native PHP implementation. No framework, Composer package or frontend build is required. **Not yet production-complete.** License validation and software updates use the existing License Manager API. No real credentials are included in source.

## Implemented

- Four-stage, CSRF-protected, licence-key-gated installer with a filesystem mutex and installation lock. No CLI operator token is required.
- PDO prepared statements, persistent connections, InnoDB/foreign-key schema, unique parish/month reports; province-level users have a **nullable hierarchy ID** instead of a fictitious province node.
- Login with generic errors, session rotation, 30-minute inactivity expiry, HttpOnly/SameSite cookies, rechecking suspension on every request, CSRF on all writes and output escaping.
- Configurable account and IP failure windows, application IP blocks, suspension scope and privileged-role exceptions, audit log, PIN throttling, recognized-IP green king badge after five clean session logins. Recognition never bypasses security. Unknown accounts trigger IP blocks when IP protection is enabled.
- Province-wide treasurer/admin views and recursive hierarchy-scoped representative/officer views. Parish, Area and Zone representatives submit their own church’s reports; Area/Zone representatives also supervise descendant churches.
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

- `version.json` inside the REMIT payload: `{"product":"remit","version":"0.5.0"}` (example next version). Update this file in the release commit; the returned LM `latest_version` must match exactly. Current baseline is `0.4.0`.
- `manifest.json` in the REMIT payload, or at ZIP root (LM's auto-generated manifest is supported): `{"version":"0.5.0","database_queries":[],"files_to_delete":[]}`. Changelog/tier metadata may also be present.
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


## 0.2.3 — Hidden desktop sidebar scrollbar

The desktop sidebar (901px and wider) hides its scrollbar in Firefox and WebKit/Blink browsers while retaining `overflow-y: auto`. Mouse-wheel, trackpad and keyboard navigation continue to work; mobile navigation is unchanged. Deploy the updated templates and fingerprinted stylesheet together. No database migration is required.

## 0.3.0 — Searchable units, bulk assignment and zone-planted parishes

### Find a church without scrolling

Church-unit selects in registration, officer provisioning, unit creation, bulk destination selection and parish upgrades now become type-ahead comboboxes. Type a name, parish code, parent or zone; matching units appear automatically (up to 20 suggestions at a time; refine the query to narrow large matches). Click a result or use Arrow keys and Enter. Free text is not accepted as a unit ID. Escape closes the suggestions; native selects remain available without JavaScript. The search uses the permitted options already rendered to the page, with no new public lookup endpoint or contact-data exposure. Public registration lists church names/codes and area/zone context, including parishes directly under a zone.

### Assign multiple units

Provincial administrators can open **Church hierarchy → Assign church units in bulk**:

1. Search/select the destination Zone or Area.
2. Filter source units by name/code/parent/zone and by Area/Parish.
3. Select individual checkboxes or **Select visible units**, then **Assign selected units** (maximum 500 per operation). Filtering retains hidden selections; the visible/selected count and Clear selection control make this explicit.

Areas may be assigned only to Zones. Parishes may be assigned to Areas **or directly to Zones**. Zones cannot be moved beneath another unit. Moving an Area keeps its child parishes attached, so select the Area only, not its descendants. Mixed batches that cannot all use the selected destination are rejected atomically. Unknown IDs, self-assignment, cyclic ancestry, selected ancestor/descendant batches and unauthorized roles are rejected. Moves serialize with CSV imports and other hierarchy writes using the `site_title` row lock; reports, parish IDs/codes and officer accounts are not recreated or deleted. Representatives' authorized scope follows the new tree on the next request. Already-open pages do not update until refreshed.

### Upgrade a planted parish safely

Use **Church hierarchy → Upgrade a zone-planted parish to an Area** for a Parish currently directly under a Zone. Enter the new Area name. The action creates an Area under the original Zone and moves the original Parish beneath it as the headquarters parish. It deliberately **does not change the old parish's type or ID**: existing financial records and parish-officer accounts remain attached to that parish and remain visible in rollups. Add other parishes using the assignment tool and provision a separate Area representative against the new Area. Repeating the upgrade after the parish has moved beneath an Area is rejected.

Hierarchy move/upgrade identifiers are logged to the server application log; no contacts, passwords or bank details are logged by these operations. Retain/protect server logs according to the deployment policy.

### CSV and installation compatibility

No database migration is required. Existing hierarchies retain their structure until an administrator changes them. New Parishes can choose either an Area or Zone parent in the creation form; Areas still require a Zone. CSV imports retain the same six columns, but `area` may now be blank for a direct Zone parish:

```csv
zone,area,parish,parish_code,contact_person,phone_number
Example Zone,,Example Planted Parish,PLANT001,Example Officer,08000000000
```

Existing parish-code conflicts still reject the import; importing does not silently move a previously assigned parish. Use the bulk assignment tool for reorganizations. The downloaded three-row sample remains compatible, and the import page now explains the optional Area column.

### Verification

- `php tests/hierarchy.php`: 44 assertions using a SQLite compatibility harness for parent validation, role restrictions, atomic move rejection, moving Areas/Parishes, representative scopes, safe upgrades, CSV direct-parish import/reimport and registration labels. MySQL row-lock/concurrency semantics still need deployment testing.
- `php tests/hierarchy-ui.php /tmp/remit-hierarchy-ui.html` generates a fictional-only test fixture from the production hierarchy-tools view.
- With Playwright/Chromium available, `node tests/hierarchy-browser.js /tmp/remit-hierarchy-ui.html` runs 40 assertions at phone and desktop widths for live suggestions, result limiting, pointer/keyboard selection, invalid free text, no-results messaging, retained hidden selections, clearing and destination selection. Use the external dev-tools `NODE_PATH`/`CHROME_EXECUTABLE` setup documented above. Fixtures do not contain live church data.
- Existing church portal, money, licence/update, sample CSV and role-specific navigation regression tests are retained. Deploy PHP and newly fingerprinted frontend assets together; validate real MySQL/cPanel and representative account scopes before a large reorganization.

## 0.4.0 — Church directory workspace, editing and protected deletion

**Church hierarchy** now puts Quick add/edit first, alongside the directory at widths of 1100px and above. Smaller screens stack the tools above the cards. A top shortcut bar opens CSV import, officer provisioning, bulk assignment and registration review; less-used tools are collapsible instead of forcing administrators to scroll past them before reaching the list. Directory cards support combined name/code/contact/parent search, level filtering, Zone filtering, result counts and an empty-results state. Non-admin users retain read-only, server-scoped views; contact fields and edit/delete controls are admin-only.

### Edit a unit

Choose **Edit details** on a directory card. You can update the name, parish code, contacts and valid parent. Unit ID and level remain unchanged; this preserves officer/registration/financial relationships. Areas still require Zone parents, and Parishes can use Area or Zone parents. Zones remain at the provincial root. Level changes are rejected; use the existing safe Parish-to-Area upgrade instead. Duplicate parish codes are rejected by the database and changes roll back together. Parent changes alter representative scope on subsequent requests. Quick add now also accepts optional contact details.

### Delete an unused unit

The **Delete** link only opens a confirmation view; it never mutates data via GET. The administrator must type the exact current unit name and check a confirmation box, then submit a CSRF-protected POST with a valid site licence. An unused leaf Parish, empty Area or empty Zone can be permanently removed. Deletion is blocked when the unit has:

- Any child units (move/reassign them first).
- Any officer/user accounts attached to it.
- Any remittance ledger entry, including unpaid reports.
- Any church registration records when that feature schema is enabled, including rejected/approved records.

No cascade deletion of accounts, registrations or financial history is offered. Database foreign keys provide a further safeguard against links added concurrently. Structural writes share the same serialization lock used by creation, imports, bulk assignments and upgrades. Edit/delete unit IDs and actor IDs are logged to the application log without bank or contact details. Logs are not a substitute for external backups. Repeated deletion and stale/wrong-name confirmations are rejected.

**No database migration is required.** Deploy the updated PHP templates and fingerprinted assets together. Existing data is unchanged until an administrator submits an action. MySQL concurrency/permissions and browser behavior on the actual hosting site should still be verified before reorganizing a live province.

Validation for this release: `tests/hierarchy-edit-delete.php` adds 25 SQL/role/rollback/protection assertions on top of the 44 existing hierarchy assertions (SQLite compatibility harness, not a MySQL locking test). `tests/hierarchy-page-ui.php` renders fictional-only admin/read-only/edit/delete fixtures; `tests/hierarchy-page-browser.js` runs 138 Chromium assertions across 320, 390, 768, 1024, 1100 and 1440 pixel layouts, including tool ordering, side-by-side alignment, mobile overflow, tool disclosure shortcuts, filters, role visibility and prefilled confirmation/edit forms. Existing autocomplete, mobile navigation, finance, registration, CSV and licence/update regressions are retained.

Optional workspace browser check (using the external Playwright/Chromium setup above):

```sh
mkdir -p /tmp/remit-directory-fixtures
php tests/hierarchy-page-ui.php provincial_admin /tmp/remit-directory-fixtures/provincial_admin.html
php tests/hierarchy-page-ui.php area_rep /tmp/remit-directory-fixtures/area_rep.html
php tests/hierarchy-page-ui.php provincial_admin /tmp/remit-directory-fixtures/edit.html edit
php tests/hierarchy-page-ui.php provincial_admin /tmp/remit-directory-fixtures/delete.html delete
CHROME_EXECUTABLE=/path/to/chromium NODE_PATH=/tmp/remit-browser-tools/node_modules \
  node tests/hierarchy-page-browser.js /tmp/remit-directory-fixtures
```

## 0.4.1 — Responsive Church directory pagination

The directory displays **6 units per page** by default, with **6 / 12 / 24** choices. Phones show large Previous/Next controls and a Page X of Y indicator; wider layouts also show a bounded numbered page strip. Search, level and Zone filters apply across the entire authorized directory, not just the current page. Changing a filter or page size returns to page 1; out-of-range pages are clamped safely. Empty lists show 0–0 counts and disabled navigation.

Pagination and filtering work as server-rendered GET requests without JavaScript (use **Apply filters**). With JavaScript enabled they update instantly, preserve shareable/refreshable URL state and support Back/Forward between page selections. Admin edit/delete links retain the selected directory filters and page on the confirmation/edit view. Role scope and admin-only contact visibility/search remain unchanged. No database migration is required; deploy PHP views and fingerprinted assets together.

This is **display pagination**, not database LIMIT/OFFSET pagination: the existing role-authorized nodes are still loaded for hierarchy tools, and an inert HTML template holds their cards for instant filtering. Only the current page's cards are active in the directory. Large-province data-volume optimization would require a separate scoped query/API design.

Validation: 42 pure-PHP pagination assertions; 294 directory interaction/layout assertions across admin/read-only roles and six widths (320–1440px); 27 URL/history/server-rendered fallback assertions using fictional fixtures. Existing finance, hierarchy edit/delete, registration, licence/update, CSV, autocomplete and mobile-navigation regressions also pass. Browser checks use headless Chromium, not physical devices; live cPanel/MySQL acceptance remains necessary.

```sh
php tests/directory-pagination.php
# First generate the admin/read-only/edit/delete fixtures shown above, then:
php tests/hierarchy-page-ui.php provincial_admin /tmp/remit-directory-fixtures/page2.html '' 'directory_page=2'
php tests/hierarchy-page-ui.php provincial_admin /tmp/remit-directory-fixtures/filtered.html '' 'directory_q=Planted&directory_size=6&directory_page=2'
php tests/hierarchy-page-ui.php provincial_admin /tmp/remit-directory-fixtures/empty.html '' 'directory_q=DoesNotExist&directory_page=999'
php tests/hierarchy-page-ui.php provincial_admin /tmp/remit-directory-fixtures/contact.html '' 'directory_q=Test+Officer'
CHROME_EXECUTABLE=/path/to/chromium NODE_PATH=/tmp/remit-browser-tools/node_modules \
  node tests/directory-pagination-browser.js /tmp/remit-directory-fixtures
```

## 0.5.0 — Officer-only registration and intelligent church discovery

`index.php?page=register` now registers an **officer account**, not a new church/bank account. Search or select admin-added **Parishes, Areas and Zones**. Suggestions rank exact, partial, multi-word and common typo matches, including parent/code context. Suggestions never silently choose a fuzzy match. The native directory dropdown remains usable without JavaScript. Full name, phone, email and password are required; bank details are no longer collected or stored during signup. Approved Parish officers can complete their bank profile later in **My church account**.

If genuinely absent, check **my church is not listed**, enter its **CAPS name** and level, then submit for review. The browser uppercases the name and offers similar churches alongside it. Server-side checks independently ignore case, spacing and punctuation when comparing names: a unique exact normalized match reuses the existing unit, even if “not listed” was chosen. Ambiguous matches require explicit directory selection. Approximate matches do not auto-link. No public registration request creates any hierarchy row, preventing duplicate church creation from this flow, including concurrent submissions. Different officer applications may refer to the same church; emails remain unique per application/account.

Unlisted names appear in the admin's **Church hierarchy → Registrations** queue, including their submitted level. Verify affiliation independently, select an existing church, or first add the genuinely missing church using Quick add (correct parent and parish code), then link it before approval. Rejection needs no church link. Approval derives the role from the verified unit: `parish_officer`, `area_rep`, or `zonal_rep`; applicants cannot request treasury/admin roles. Existing license/CSRF checks, IP attempt limits, pending-password clearing, foreign keys and unique-email constraints remain. Legacy pending registrations retain their bank ciphertext and profile details on approval. Notifications remain queued in the existing outbox, not guaranteed email delivery.

### Required upgrade for existing installations

1. Back up the database, files and original `config/bank.key`.
2. Deploy all 0.5.0 PHP files and fingerprinted assets together.
3. As admin, open **Portal settings → Upgrade registration & payment features**. New public registrations stay unavailable until this completes; existing login/payment/report features remain enabled.
4. The existing licence/CSRF/admin-gated schema upgrader makes `church_registrations.parish_id` nullable and adds nullable `requested_church_name` / `requested_church_type` columns, then records `officer_registration_schema=1`. It preserves existing records, foreign keys, bank data, percentages and financial history. MySQL DDL auto-commits: interrupted column additions can be retried through the same upgrade action. New installations receive the changes during setup.
5. Verify a Parish, Area, Zone and unlisted application on your hosting environment before rollout. Recheck representative scopes after approval. No public hierarchy creation is offered.

Validation adds **35** officer registration SQL/role/privacy/rollback assertions (on top of 156 existing portal checks), **24** migration SQL-recording assertions and **125** discovery/browser checks at widths 320, 390, 768, 1024 and 1440. Existing hierarchy, edit/delete, pagination, CSV, money, licensing, autocomplete and mobile regressions pass. SQLite and SQL-recording harnesses do **not** prove real MySQL/MariaDB DDL, locking or concurrent deployment; headless Chromium is not physical-device acceptance.

```sh
php tests/officer-registration.php /tmp/remit-registration.html
php tests/registration-upgrade.php
CHROME_EXECUTABLE=/path/to/chromium NODE_PATH=/tmp/remit-browser-tools/node_modules \
  node tests/officer-registration-browser.js /tmp/remit-registration.html
```

## 0.6.0 — Multiline hierarchy entry and automatic CAPS

Admins can now use **Church hierarchy → Add multiple churches** and paste one hierarchy per line:

```text
GRACE ZONE, HOPE AREA, LIVING HOPE PARISH
GRACE ZONE, NEW LIFE AREA
GRACE ZONE, HOPE AREA, GRACE PARISH
GRACE ZONE
NEW ZONE
```

A line can contain **ZONE**, **ZONE, AREA**, or **ZONE, AREA, PARISH**. Blank lines are ignored; a missing Parish creates no placeholder and can be supplied in a later batch. Repeat a Zone/Area to build its other descendants. Matching is CAPS/whitespace-normalized **within the same level and parent**, so repeated paths reuse existing units and preserve their IDs/codes; identical names in different Zones/Areas remain separate. Legacy ambiguous duplicate paths stop the whole batch rather than selecting an arbitrary unit. For an existing direct-Zone church, use `ZONE, , PARISH`. Quote names containing commas, e.g. `"GRACE, EAST ZONE", HOPE AREA`.

The admin/licence/CSRF-protected action accepts up to **5,000 non-empty lines / 2 MB** in one transaction, with the existing structural-write singleton lock. Any invalid row or database failure rolls back the complete batch. New Parishes get unique **AUTO{id}** codes (with collision suffixes), editable afterward via Edit details. Existing parish codes, contacts, officer accounts, registrations, links and financial records are not replaced. Reposting the same paths adds no duplicates. CSV import stays available and now rejects an existing same-parent Parish name with a different code rather than duplicating it; edit its code explicitly if required.

**All church name entry is automatically CAPS**: multiline input, Quick add, editing, Area upgrades, CSV import and unlisted registration names. Browser code changes the actual input value while preserving the caret; PHP independently validates/converts names, including when JavaScript is off. Names are limited to 150 UTF-8 bytes after conversion. A bundled Unicode 14.0 uppercase mapping (generated from Python's Unicode data) supports non-ASCII names and expanding letters without adding an mbstring dependency. Personal names, bank details and existing parish codes are not uppercased.

Existing directory/registration church labels appear in CAPS immediately. **Saving the first successful batch also converts existing stored directory names to CAPS** without merging units or changing IDs, parents or history. No schema migration is needed for this release; the 0.5.0 officer-registration schema upgrade is still required if not previously enabled. Review old ambiguous/invalid directory names if a batch asks for correction. Deletion confirmation accepts the displayed CAPS form of legacy names; all linked-record and explicit-confirmation protections remain.

All newly added **Zones, Areas and Parishes** are returned by the existing public registration directory and can be searched or selected. Similar-name/typo suggestions and native dropdown fallback remain. Unlisted names are converted to CAPS and saved only with the pending application; exact normalized matches reuse existing churches. Admin church linking and affiliation approval are still mandatory before access. No public hierarchy creation is allowed.

Validation adds **34** multiline/Unicode/idempotency/parent-scope/code-collision/rollback/privacy assertions to the existing 44 hierarchy checks. The directory browser suite now has **330** assertions across two roles and six widths, including actual multiline/Quick add CAPS values and read-only visibility; the **125** registration discovery and **27** URL/fallback checks remain. Other finance, portal, CSV, edit/delete, licensing, autocomplete and mobile regressions are retained. SQLite/headless tests do not establish MySQL locking/concurrency, cPanel deployment or physical-device acceptance.

```sh
php tests/hierarchy-batch.php
# Use the previously documented fixture commands for directory and registration browser checks.
```

## 0.7.0 — Mobile-first fintech redesign and persistent footer navigation

All shared workspace and public page layouts now use a simpler mobile-first visual system: light neutral surfaces, deep teal actions, readable forms, softer borders and rounded panels. Dashboard stat cards have subtle blue, sage, amber and lavender backgrounds. The desktop sidebar is no longer displayed; the content uses the available width and a centered reading layout. Forms stack on phones and expand to paired columns where space permits. Existing directory tools, CAPS/bulk entry, pagination, allocation previews, bank/payment controls, settings, security and role scoping remain functional.

The **persistent bottom menu** is fixed to the viewport and reserves document space so final form actions are not obscured, including safe-area padding. Phones/tablets show **icons only**, with accessible names, current-page state and 48px targets. **More** opens the existing keyboard-accessible dialog for profile/settings/security and logout; native no-JavaScript links remain available when enhancement is unavailable. At desktop widths (901px+), the footer uses **text links**, including permitted profile/settings/security destinations directly. Public sign-in, registration and installer pages have their own fixed Sign in/Register footer (icons on phones, text on desktop). No authenticated/private destinations are added to public navigation. Sign out remains available in the workspace header as well as the mobile dialog.

The redesign covers dashboard, remittances/offering/payment, hierarchy, profile, settings/licensing, security, login, officer registration and setup. It preserves native form semantics, focus outlines, SVG accessible-name handling and reduced-motion preferences. Data tables keep contained horizontal scrolling where card conversion is unsuitable. No finance, permission, migration or payment-integration behavior changed. **No new database migration is needed**; deploy all PHP templates and fingerprinted assets together. Prior feature upgrades remain required if not already enabled.

Validation: `tests/redesign-ui.php` renders nine actual production page layouts with fictional data and SQLite compatibility fixtures; `tests/redesign-browser.js` runs **675** layout/navigation checks across nine pages at 320, 390, 768, 1024 and 1440 pixels, including mobile icon-only/desktop text presentation, footer persistence, touch targets, no page overflow, non-overlapping final actions and stat backgrounds. The role/mobile suite now has **255** browser assertions plus 39 PHP role checks. Existing directory 330, registration 125, URL/fallback 27 and autocomplete 40 browser checks pass, along with finance, hierarchy, CSV, licensing and portal regressions. These headless checks do not replace physical-device, screen-reader or live cPanel/MySQL acceptance.

```sh
php tests/redesign-ui.php /tmp/remit-redesign
CHROME_EXECUTABLE=/path/to/chromium NODE_PATH=/tmp/remit-browser-tools/node_modules \
  node tests/redesign-browser.js /tmp/remit-redesign
```

## 0.8.0 — Remittance for every worshipping church, fewer steps

**Updated policy:** Zones and Areas have their own worshipping members and monthly Youth Service offerings, as do Parishes. Approved `zonal_rep`, `area_rep` and `parish_officer` accounts can now submit **their own assigned church's** monthly offering and notify treasury of its bank transfer. This supersedes earlier release notes that described Area/Zone representatives as read-only. Funds still go directly to the provincial treasury; this is not inter-level fund collection or consolidated reporting.

### Staff flow

1. Tap **Start remittance** on the dashboard (or **Remit** in the footer).
2. Enter month, year and total offering; retention and provincial amount calculate automatically.
3. Save the offering. A 303 redirect takes the user straight to the provincial bank/payment section.
4. Transfer to the displayed provincial account, using the report reference, then **Submit payment** with the actual bank reference.
5. Treasury independently checks the bank and confirms payment. Fully pending deposits show **Awaiting treasury confirmation** instead of being offered again; partial pending deposits reduce the selectable balance.

The report form identifies the account's church and never offers a church selector. Server checks derive its ID from the authenticated account and validate the role/unit type (`zonal_rep` → Zone, `area_rep` → Area, `parish_officer` → Parish), ignoring posted target IDs. Payment notifications are restricted to that same church. Representatives cannot submit for child/sibling/parent churches or approve payments. Provincial admin/treasurer accounts continue to confirm payments with their transaction PIN. Profiles/contact/bank details are available to all three church-representative roles; they never alter the provincial receiving account.

Financial reads now include the representative's own unit and its descendants; treasury sees all levels. Dashboard totals for Area/Zone representatives are explicitly labelled as own + supervised churches. This preserves supervisory visibility without permitting writes against those descendants. The older `scope()` helper retains parish-only semantics for compatibility; production financial routes use `financialScope()`.

**Shorter pages:** dashboard tasks use concise labels; lengthy guidance is removed; charts, reports and payment history use native collapsible sections. Remittance forms and transfer controls come before report history. Payment options query the account's own reports directly, so a busy supervisory ledger's 100-row display limit cannot hide the account's outstanding reports. PayHub remains explicitly not connected; no automated deposit API is invented.

**No schema migration is required.** The historical `remittance_ledgers.parish_id` column already references `church_hierarchy.id` without a parish-only constraint, and the unique unit/month/year key and allocation snapshots work for every hierarchy level. Column/table names are kept for update compatibility; existing data, IDs, percentages, receipts, history, encryption keys and parish reports are unchanged. Deploy PHP templates and fingerprinted CSS together. Existing feature schema upgrades are still required if not enabled. Existing valid Area/Zone representative accounts gain own-church remittance after deployment; no re-registration is necessary.

Validation adds **52** PHP role/ownership/report/payment/allocation/duplicate/treasury-confirmation assertions plus **164** browser checks across all three representative roles and four widths. The browser flow clicks Start remittance, verifies the live 80/20 calculation, checks own-church payment choices, collapsed history and mobile overflow. Fifteen fictional production fixtures cover the original nine page types plus representative dashboard/remittance routes. Existing 675 page-layout checks, 255 role/mobile checks, directory/registration/autocomplete checks and finance/portal/hierarchy/licensing regressions remain. SQLite compatibility and headless Chromium do not establish production MySQL locking, concurrency, live bank settlement or physical-device acceptance.

```sh
php tests/representative-remittance.php
php tests/redesign-ui.php /tmp/remit-redesign
CHROME_EXECUTABLE=/path/to/chromium NODE_PATH=/tmp/remit-browser-tools/node_modules \
  node tests/representative-remittance-browser.js /tmp/remit-redesign
```

## 0.9.0 — Readable account details and installable PWA

Explicit dark text fixes the bank-card regression (white inherited text on a pale background). Account number, bank name, account name and caption now have separate high-contrast foregrounds. Supporting text, form labels, placeholders, allocation previews, cards and navigation text are darker and clearer without changing any account values, percentages or financial behavior.

### Install REMIT

Deploy over **HTTPS**. A scoped web manifest, standalone display, 192/512px and maskable icons, Apple home-screen metadata and root service worker make REMIT installable in supporting browsers. Chromium's **Install app** banner appears when the browser emits `beforeinstallprompt`; the browser's native install dialog is triggered only by a user click. Safari/iOS receives manual Share → Add to Home Screen instructions. Dismissing the banner suppresses it for **30 days** in that browser/profile. Installed/standalone mode and the `appinstalled` event suppress it, with a scoped local-storage hint shared between same-origin tabs. Storage-denied/private mode is best effort. A fresh native install-eligibility event can clear a stale installed hint after uninstalling. Other unsupported browsers are not falsely promised installation.

**Browser → installed app:** web pages cannot reliably detect every previously installed PWA or force-open it when someone types a URL into a browser. REMIT does not use redirect loops, custom URL schemes, Android package guesses or fake “Open app” buttons. `launch_handler: navigate-existing` asks supporting browsers to reuse an installed app window when the browser/OS launches the PWA. Actual link capture/open-in-app depends on browser/OS capabilities and user preferences. Otherwise open REMIT from its home-screen/app icon. Local installed hints do not prove installation in another browser/profile and can become stale after uninstalling or clearing storage. This is a platform limitation, not an automatic handoff guarantee.

### Offline and financial privacy

The worker is scoped to the deployment directory, supports subfolder installs, and versions cache names per scope. It caches **only** the generic offline screen, its stylesheet and public icons. Financial pages, bank details, profiles, login/session responses, APIs, receipts and POST requests are never put in the service-worker cache. Navigations are network-first without caching their responses; network failure shows a generic reconnect screen. There is **no offline payment/report queue or automatic POST replay**. Reconnect to transact. Authentication, session expiry and sign-in requirements remain unchanged. `sw.js` and the manifest are revalidated; scripts/styles use fingerprinted names. Deployment and licence-managed updates must include the manifest, worker, offline screen and icons; updater exceptions are restricted to those exact new root/icon paths.

Validation adds **24** browser assertions for native-prompt simulation, persistent dismissal, installed/standalone suppression, Safari-instruction simulation, nested deployment scope, icons/manifest, offline fallback, no private-cache entries, failed offline POST and bank-text contrast (all four card text elements ≥4.5:1). Native OS installation, iOS rendering and browser link capture still require real-device acceptance. Existing finance, role, remittance, directory and responsive regressions are retained. No database migration is required.

```sh
CHROME_EXECUTABLE=/path/to/chromium NODE_PATH=/tmp/remit-browser-tools/node_modules \
  node tests/pwa-browser.js
```

## 0.10.0 — Payment receipts and full financial history

Parish, Area and Zone representatives can submit a payment notification for their **own** church, optionally attaching a JPEG, PNG or WebP receipt. The bank reference/date/amount remain required. A thumbnail opens an accessible, keyboard-dismissable receipt modal; without dialog support/JavaScript the link opens the protected image. Treasury/admin can preview all receipts; representatives can view only their own church's receipts, **not** descendant churches' private images. Session/suspension checks and licence validation apply to image requests too. Screenshots do not verify settlement: independent bank reconciliation, treasury PIN, existing approval/rejection logic and audit remain unchanged.

**Existing installations:** back up the database, deploy the complete release, then use **Portal settings → Enable receipt uploads**. The additive, repeatable migration creates `payment_receipts` without changing old notifications. MySQL DDL auto-commits; DDL privileges are required for this step. Fresh installation includes it automatically. PHP GD must include WebP encoding support; otherwise payment notifications still work without attachments. Set `upload_max_filesize` to at least `8M`, `post_max_size` above that (e.g. `12M`), and an appropriate PHP memory limit (e.g. `256M`). Keep Apache/LiteSpeed private-directory protections enabled.

Originals are limited to 8 MiB, 12 megapixels and 8000 pixels per edge; documents/PDFs are rejected. The server decodes and re-encodes images to WebP, strips metadata, corrects JPEG orientation when EXIF is available, flattens transparency on white, and stores only a maximum-2000px image and 320px thumbnail (each capped at 2 MiB). Upload names are never used as paths. Random private filenames live under protected `storage/receipts/`; do not expose this directory through a CDN or alias. Receipt responses are authenticated, `no-store`, and never cached by the service worker. Include receipt files **and** database metadata in backups. Normal failed submissions roll back and remove generated files; the existing CLI maintenance task also removes unreferenced receipt files after a 24-hour grace period. Referenced receipts are retained for financial review, not automatically purged.

**View reports** and **Review/View payments** now search across the entire authorized financial history, not just the newest 100 records. Search church names/codes, report/bank references and amounts; payment search includes review notes. Status, year and month filters are applied in the database with bounded 12-row pages. Report dates refer to service period; payment dates refer to transfer date. Typing refreshes automatically after a short pause, selects refresh immediately, and Apply/Reset/Previous/Next work without JavaScript. Read scope is enforced before every query; this does not broaden receipt or write permissions.

Validation: `tests/payment-proof-history.php` exercises actual GD JPEG/PNG/WebP conversion, bounds, malformed files, private-path/access checks, orphan cleanup and >100-record history queries using the SQLite compatibility harness. `tests/payment-proof-history-browser.js` covers modal/Escape/focus return and automatic filters at 320/390/1024px. Existing money, remittance, registration, hierarchy, licensing/update and layout suites are retained. These are not live MySQL concurrency, cPanel multipart upload or real-device deployment tests; perform those deployment checks before production acceptance.
