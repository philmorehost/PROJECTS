# RISE 63

Mobile-first **Progressive Web App** for RCCG Lagos Province 63 — LP63 YAYA CSR & Young Professionals Club.
Grassroots parish intake, applicant profiling, four empowerment programs, controlled grant disbursement and
cPHulk/Imunify360-style login protection, on plain PHP 8.2 + MySQL with no framework, no Composer and no build step.

```
Parish Connect → RISE Academy → Seed Grants → Cooperative Access → Education Bridge
                          ↑                        ↑
              three-step tracker        append-only ledger + transaction PIN
```

## Quick start (local)

```sh
cd RISE63
php -S 0.0.0.0:8080 router.php        # development router; .htaccess is ignored by php -S
# open http://localhost:8080/ — the installer runs because config/installed.lock is absent
```

For local work you can skip the activation round-trip with the documented fixture:

```sh
RISE63_ACTIVATION=local-fixture php -S 0.0.0.0:8080 router.php
```

Production never sets that variable. See `docs/DEPLOYMENT.md` for the real flow.

## Tests

```sh
php tests/unit.php          # money, state machine, security policy, settings schema, schema integrity
php tests/render.php        # every view against fixtures, XSS probes, UX contract, route consistency
php bin/build-assets.php    # fingerprint CSS/JS + refresh the service-worker shell list
```

In a sandbox without a PHP binary, the same suites run through php-wasm:

```sh
npm i php-wasm
node tests/support/phpwasm-lint.mjs
node tests/support/phpwasm-test.mjs
node tests/support/phpwasm-render.mjs
```

Current state: **57 files lint clean, 31 logic tests pass, 24 view tests pass.** What is verified and what still
needs a real database/browser is listed, without decoration, in [`docs/IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md).

## Highlights

| Area | What is actually built |
|---|---|
| **Installer** | Four stages — system check + activation, database, administrator, lock. Filesystem mutex, refuses a non-empty database, writes `config/*` as 0600. |
| **Security** | Account suspension and IP blocks on independent windows, immediate block for unknown accounts, escalating day→week→month→year blocks, five-clean-session recognition with a crown badge, 250-country filter, manual IP/e-mail rules, queued firewall jobs applied by a least-privilege worker. |
| **Money** | Integer-kobo `Money` value object, `DECIMAL(15,2)` columns, append-only ledger with database triggers, two-person disbursement (request → PIN confirmation), Seed Grant pool locked with `SELECT … FOR UPDATE`. |
| **Programs** | One enforced state machine for all four modules: illegal transitions are refused (409), every move writes a timeline row and queues a notification. |
| **Mobile UX** | 16 px+ type, 48 px targets, live-search parish combobox, three-step status tracker, offline banner, draft autosave, idempotent offline submissions, dark/light/system themes, large-text mode. |
| **PWA** | Manifest with any + maskable icons, versioned service worker (never caches `/api/` or authenticated POSTs), floating install prompt with an iOS Share-guide, update toast, dynamic SVG favicon from the site title. |
| **Operations** | Mail worker with lease + backoff, firewall worker, maintenance worker with meaningful exit codes, `.htaccess` + vhost hardening notes, backup/restore drill, incident playbook. |

## Layout

```
index.php              front controller (installer hand-off, routing, activation gate)
install/index.php      four-stage wizard
api/index.php          authenticated JSON parish selector
app/{bootstrap,actions,pages}.php   wiring
app/Core/              Money, Database, Settings, Security, BruteForce, GeoResolver, Auth,
                       Registration, ParishDirectory, Program, Applications, Ledger,
                       Notifier, Mailer, Activation, Installer, Audit, View
app/Views/             layout + auth/ member/ staff/ partials/
bin/                   mail-worker, security-worker, maintenance, build-assets
database/              schema.sql, countries.sql (250 ISO), seed.sql, ledger-guards.sql
public/                assets + PWA icons
tests/                 unit.php, render.php, php-wasm harnesses
docs/                  DEPLOYMENT.md, IMPLEMENTATION_STATUS.md, archive/blueprint-v0.md
```

## Before going live

1. Follow `docs/DEPLOYMENT.md` §1–§4 — HTTPS, private-directory denial, installer lock.
2. Install the three cron entries; without them no notification is ever delivered.
3. Read `docs/IMPLEMENTATION_STATUS.md` §3 and §5: run the database, brute-force and PWA checks on staging, and note
   the known gaps (no self-service password reset, no 2FA, no gateway, Academy progress entry still to be wired).

Not yet production-complete. Nothing in this repository claims otherwise.
