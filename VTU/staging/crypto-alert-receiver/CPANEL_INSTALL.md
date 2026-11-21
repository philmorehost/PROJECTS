# Staging cPanel HTTPS adapter — install disabled first

This is a manual staging installation guide, **not confirmation of deployment**. DNS/SSL, PHP-FPM, Apache/LiteSpeed, MySQL permissions, Cloudflare behavior and process isolation have not been inspected on your VPS. Do not modify your live application's account, document root, database or credentials. Production sender and polling-worker flags stay off throughout preparation.

## 1. Files and layout

Use the dedicated staging cPanel account. In File Manager, confirm the account's actual domain document root; the examples below assume `/home/CPANELUSER/public_html`. Replace `CPANELUSER` with that account's username, not the live account username. The parent of the document root must be that account's home for the supplied public entry path to work.

Create this private folder **outside public_html**, not inside it:

```
/home/CPANELUSER/crypto-alert-receiver/
    receiver.php
    http.php
    http-entry.php
    receiver-cli.php          # optional, schema preview/CLI drills only
    config.php               # create from config.example.php; leave enabled=false
/home/CPANELUSER/public_html/
    index.php                # from public/index.php
    .htaccess                # from public/.htaccess; show hidden files in File Manager
```

Upload only `public/index.php` and `public/.htaccess` into the document root. Never upload the private directory, config, schema dumps, tests, README, database backups, `.env` files or the entire repository into public_html. The public entry loads `../crypto-alert-receiver/http-entry.php`, a fixed filesystem path, not an HTTP-supplied path. If cPanel uses a custom document root, adjust that private filesystem location deliberately; do not make the private folder public to fix an include error.

With owner-run PHP-FPM, prefer private directory mode 0700 and private PHP/config files 0600; public index may be 0644. Verify the staging account's PHP pool runs as that account and cannot read the live account. Do not solve permission errors with 0777 or world-readable secrets. Container/OS/DB isolation on a shared VPS is not supplied by this adapter.

## 2. Private configuration — disabled by default

Copy `config.example.php` to **private** `config.php`. Leave `enabled` as boolean false initially. No actual config.php or real secret belongs in Git.

Set `hostname` to the exact lowercase staging hostname (for this installation: `crypto-alerts-staging.datagifting.com.ng`). The hostname is configuration, not hardcoded application logic. Do not include https://, a port or path.

Generate a **staging-only** random key locally/on-server using an approved secret manager or terminal:

```
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Treat the output as a secret. Do not paste it into chat, tickets, screenshots, shell history assignments or committed files. Put it in private config `key`; eventually provision the same value as sender environment `BC_CRYPTO_POLL_ALERT_KEY`. Never reuse provider/API keys or production secrets. Config uses PHP array values, not getenv/HTTP fallback; quote/escape values correctly and run `php -l` privately before use. The sender URL remains its existing environment configuration, not this file.

## 3. Isolated local staging MySQL database

In this staging account's cPanel MySQL Databases tool, create a separate database/user with a strong unique password. Set `database.host` to `localhost`, and use cPanel's complete prefixed database/user names. The adapter intentionally supports **local MySQL only**; remote DB requires separately reviewed verified-TLS transport. Confirm `localhost` uses the approved local socket and no remote unencrypted connection.

Preview the two receiver-only table statements in Terminal, outside public_html:

```
php /home/CPANELUSER/crypto-alert-receiver/receiver-cli.php schema
```

Review/apply them only to the new staging database through phpMyAdmin or an approved admin process. Do not apply the production application installer just for this receiver. No schema is created by HTTP requests. Tables must be InnoDB. Receiver-only compatibility CI covers MySQL 8.0 and MariaDB 10.11 on PHP 8.3/8.5; this does not establish MariaDB support for the full financial application.

After schema setup, the runtime user needs SELECT/INSERT/UPDATE on `staging_alert_inbox` and SELECT/INSERT on `staging_alert_acceptance_events`, not schema administration, deletion or access to application financial tables. Verify cPanel per-table privileges with the database administrator if its UI only offers database-wide grants. The runtime sets session transaction/lock/query limits. `information_schema` table-engine metadata must be readable. Do not give broader global privileges to bypass a configuration failure.

## 4. HTTPS route and web-server limits

The only application route is **POST `/alert`**; `/`, direct `/index.php`, query strings and other paths intentionally refuse. A 404 on the bare hostname after installation is expected, not a reason to expose private files. Apache `.htaccess` preserves AutoSSL validation paths, disables directory listing/MultiViews and rewrites only `/alert`. It requires compatible Apache 2.4 rewrite/override support; LiteSpeed equivalents must be independently checked. Disable PHP source serving and stop immediately if any `.php` file downloads as text.

Requirements before setting enabled=true:
- Browser and server-side HTTPS certificate/hostname verification succeeds. Cloudflare must be Full (strict), never Flexible. The origin must itself receive HTTPS. The adapter trusts only server `HTTPS=on/1`, not X-Forwarded-Proto or client-supplied proxy assertions.
- No interactive challenge, CAPTCHA, login redirect, content rewrite or caching on `/alert`. Cloudflare should not cache POST/receipts. Do not disable WAF globally; use the narrowest reviewed rule if needed.
- Enforce POST body limits (1024 bytes at application level, tightly bounded server request buffering), header limits, connection/body timeouts, request-rate and PHP worker-concurrency limits at the staging vhost/proxy. Reject request-smuggling/ambiguous framing and duplicate `Content-Length`, `Content-Type`, `Idempotency-Key`, `X-Crypto-Timestamp`, `X-Crypto-Signature` headers before PHP normalization.
- PHP rejects case-variant duplicate header keys when visible and combined invalid signature values, but **cannot reliably detect duplicates discarded/overwritten by Apache/Cloudflare before getallheaders()**. Upstream duplicate rejection is a deployment gate, not a guarantee supplied by PHP.
- Confirm PHP-FPM exposes original POST URI `/alert`, real origin HTTPS, expected Host and required headers. Host including a port, alternate aliases or a query string is rejected deliberately. Do not weaken these checks based on untrusted forwarded headers.
- Supported request is `Content-Type: application/json`, explicit Content-Length, no content/transfer encoding; sender cURL produces this. No CORS/browser UI is provided.
- Configure MySQL/PHP-FPM/server wall-clock execution limits, logging redaction and disk/resource quotas. The adapter sets a 3s DB connect timeout, InnoDB lock wait limit and engine-specific query limit (MySQL max_execution_time=3000 milliseconds; MariaDB max_statement_time=3 seconds); these are not an absolute wall-clock bound on every request. Failure to set limits refuses acceptance rather than dropping the safeguard.

## 5. Controlled staging activation and checks

Only after the above checks and a separate staging-test approval, set private `enabled` to boolean true. This enables the **receiver only**, not polling. Missing config or disabled receiver responds 404. Rejected packets return generic 400 JSON; valid durable receipts return 200 JSON. Responses are no-store and do not reflect exception/SQL/body/key. HTTP acceptance does not create a schema, access provider APIs, move funds or acknowledge an operator alert.

Use synthetic data and a staging sender installation with its own approved schema, destination and key. Sender destination would be configured as `https://crypto-alerts-staging.datagifting.com.ng/alert` through `BC_CRYPTO_POLL_ALERT_URL`; do not change a live sender destination or reset pinned deliveries to conduct these tests. Keep real polling disabled. The hostname/IP must satisfy sender public IPv4/DNS pinning and TLS checks; no localhost/private-IP bypass is added.

Follow the signature/receipt contract and real-staging crash checklist in README.md. First validate unsigned/wrong-route/stale/oversized refusal without DB acceptance; then a signed synthetic acceptance, same-ID duplicate receipt, changed-content refusal and unknown/lost-response retry. Confirm acceptance records and logs without copying secrets. Test actual sender/receiver process kills, network/TLS failure, DB outage/restore, Cloudflare header handling and on-call ownership separately; CI does not establish these.

Receiver inbox stores only delivery ID and payload/key fingerprints plus acceptance times. It is a durable acceptance reference, **not a forwarding system or human-visible on-call inbox**. Assign response ownership and implement/verify forwarding before relying on it for incident response. Retain deduplication records across the retry/incident horizon; never delete an ID to force a resend.

## Rollback/containment

Set private enabled=false to refuse new HTTP requests; already executing requests may finish. Disable sender delivery in its own environment separately (running processes retain their environment). Keep receiver DB/deduplication records and audit intact. Do not delete delivery IDs, reset budgets or alter financial holds. If keys/config were exposed, stop, rotate via the reviewed incident plan and investigate rather than changing pins blindly.

No VPS/cPanel login, private key or database password is needed in this chat. Installation, certificate validation and actual staging observations remain operator actions; the code merge is not deployment approval.

## MariaDB timeout compatibility update

If private `http-entry.php` previously called `SET SESSION max_execution_time=3000` directly, MariaDB rejects it with error 1193 and the HTTP adapter returns generic 400. While the receiver is disabled, update **both private `http.php` and `http-entry.php`** from this revision. Do not overwrite private config.php, change keys, remove timeouts, alter tables or delete acceptance records. The bootstrap now delegates to an engine-aware session-limit helper; MySQL retains its millisecond limit and MariaDB uses its seconds-based equivalent. Unknown/unsupported setting or permission failures still fail closed. Re-run syntax checks, then a separately authorized brief synthetic HTTPS test and disable again. This fixes one proven connector incompatibility; it does not prove that other HTTPS/header/vhost issues are absent.
