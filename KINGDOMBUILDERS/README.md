# KINGDOMBUILDERS

A Bible-learning community and CBT platform rebuilt in **vanilla PHP 8.2+ / MySQL 8+ (or MariaDB 10.6+)** with a responsive HTML5 frontend. No framework, Composer runtime, or build step is required.

## What is included

- Four-stage root installer: requirements & site activation → database → administrator → completion checklist.
- Dynamic SVG favicon generated from the first Unicode character of the configured site title.
- Member registration, email confirmation, login/logout, password reset, profile and password change.
- Administrator dashboard, question bank CRUD, member CRUD, suspend/unsuspend, audited “sign in as”, results and formula-safe CSV export.
- Server-scored quizzes with immutable question/answer snapshots, configurable timing, forward-only or backtracking mode, saved progress, focus-change policy, results review and email notifications.
- Admin-configurable SMTP with a durable outbox, retries, a synchronous test that reports the failing stage, a copy-ready cron command, notification queue and CLI worker.
- Site settings, audit trail, CSRF/origin checks, hashed single-use tokens, session revocation, keyed rate limits, encrypted private runtime state and security headers.
- Integration, HTTP, SMTP-socket and CI checks under `tests/` and `.github/workflows/kingdombuilders.yml`.

## Quick start (local preview)

Requirements: PHP 8.2+ with `pdo_mysql`, `openssl`, `mbstring`, `curl`, `session`, `fileinfo`, `json`; an empty MySQL/MariaDB database; a writable private directory outside the web root.

```bash
cd KINGDOMBUILDERS
export KB_CONFIG_DIR=/absolute/private/kingdombuilders   # outside the public web root
export KB_SESSION_DIR=/absolute/private/kb-sessions
php -S 0.0.0.0:8080 router.php
```

Open `http://localhost:8080`. The first visit redirects to `/install`.

For an **explicitly local** preview without contacting the activation service:

```bash
export KB_LOCAL_PREVIEW=1
export KB_PREVIEW_ACTIVATION=local-fixture
# Installer activation key: LOCAL-PREVIEW-ONLY
```

Both variables are required; the fixture is never enabled by a browser request or database setting. Do not set them in production.

## Production deployment

See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md). Summary:

1. Use PHP-FPM 8.3+ behind Apache/Nginx with TLS. Point the virtual host at this directory.
2. Keep `KB_CONFIG_DIR` and `KB_SESSION_DIR` outside the web root, owned by the PHP user, mode `0700`.
3. Create an empty database and a least-privilege application user.
4. Visit the site once and complete the installer. It locks itself after completion.
5. In **Email & SMTP**, enter the provider details, use **Test without saving** to confirm the connection, then save.
6. Open **Scheduled task** (also shown on **Settings**) and copy the ready-made cron command into your host's cron tool (once per minute). It includes `KB_CONFIG_DIR`, so the worker reads the same private configuration as the site. The card shows setup steps and the last recorded run.
7. Back up the database **and** private configuration directory together. Losing the private key makes encrypted state unrecoverable.

Apache rules are in `.htaccess`; an Nginx example is in `deploy/nginx.conf.example`. The development `router.php` is not a production server.

## Checking what is deployed

`/health` returns JSON with the current build, and `php bin/check.php` prints it on the
server. If a change does not appear after uploading, flush OPcache (see
`docs/DEPLOYMENT.md`, section 3a) before assuming the files were wrong.

## Useful commands

```bash
php bin/check.php          # requirements, schema version, question count, SMTP state
php bin/worker.php         # finalize expired attempts, refresh activation, send mail, prune retention
php -l index.php           # syntax check
```

## Tests

The test helpers refuse to run unless `KB_LOCAL_PREVIEW=1` and the database name ends in `_test`.

```bash
export KB_LOCAL_PREVIEW=1 KB_PREVIEW_ACTIVATION=local-fixture
export KB_CONFIG_DIR=/absolute/private/kb-test KB_SESSION_DIR=/absolute/private/kb-test-sessions
export KB_DB_HOST=127.0.0.1 KB_DB_NAME=kingdombuilders_test KB_DB_USER=kb_app KB_DB_PASSWORD=secret
php tests/make_config.php
php -r 'require "app/bootstrap.php"; foreach(array_filter(array_map("trim",explode(";",file_get_contents("database/schema.sql")))) as $sql) KB\DB::connection()->exec($sql);'
php tests/run.php
python3 tests/smtp_fixture.py   # requires: pip install aiosmtpd

# Against a running disposable site:
export KB_BASE_URL=http://127.0.0.1:8080
export KB_ADMIN_EMAIL=admin@example.test KB_ADMIN_PASSWORD='...'
export KB_MEMBER_EMAIL=member@example.test KB_MEMBER_PASSWORD='...' KB_MEMBER_ID=2
python3 tests/http_smoke.py
```

Set `KB_INSTALL=1` plus `KB_DB_*` to exercise the full installer on an empty database. CI runs PHP 8.2/8.3/8.4 against MySQL 8.4.

## Layout

```text
app/            bootstrap, controllers and core services
app/Views/      HTML5 templates
assets/         CSS and progressive-enhancement JavaScript
bin/            CLI worker and health check
database/       schema and starter question bank
deploy/         example Nginx and cron configuration
docs/           plan, deployment and implementation status
resources/      encoded operational agreement
tests/          integration, HTTP and SMTP checks
index.php       front controller and dynamic favicon
```

## Security notes

- Activation state is encrypted with AES-256-GCM; encoding/obfuscation is **not** a tamper-proof boundary. A server owner can always inspect local PHP.
- Passwords use Argon2id when available, otherwise bcrypt cost 12. Reset/verification tokens are stored as SHA-256 hashes and consumed atomically.
- The old public JSON/participant/session files and shared-password API have been removed from the working tree. Git history may still contain them; restrict repository access and arrange a separately authorized history purge if required.
- Capacity targets in the plan are **not yet measured**. Run load tests and a security review before a high-traffic launch.
