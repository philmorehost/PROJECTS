# Deployment and operations

## 1. Server requirements

- PHP **8.2+** (8.3+ recommended), PHP-FPM for production.
- Extensions: `pdo_mysql`, `openssl`, `mbstring`, `curl`, `session`, `fileinfo`, `json`.
- MySQL 8+ or MariaDB 10.6+, InnoDB, `utf8mb4`.
- Apache with `mod_rewrite`/`mod_headers`, or Nginx. See `../deploy/nginx.conf.example`.
- TLS certificate. The installer rejects public HTTP URLs.
- A cron scheduler and a writable log location.

## 2. Private state

Set these before first access:

```ini
; Example PHP-FPM pool environment
env[KB_CONFIG_DIR] = /var/lib/kingdombuilders/private
env[KB_SESSION_DIR] = /var/lib/kingdombuilders/sessions
```

Both directories must be **outside the document root**, owned by the PHP user, mode `0700`. If `KB_CONFIG_DIR` is unset, the app derives a sibling directory named `kb-private-<hash>`; explicit configuration is strongly preferred.

Private files:

| File | Purpose |
|---|---|
| `site.php` | Database credentials, canonical URL, base path, installation key |
| `runtime.dat` | AES-256-GCM encrypted domain-activation state |
| `installed.lock` | Completion marker; installer returns 404 afterwards |
| `setup.lock` | Installer mutual exclusion |
| `worker.lock` | Worker mutual exclusion |
| `limits.dat` | Pre-install file-backed rate limiting |

Never commit these files. Back them up with the same access controls as the database.

## 3. Database

Create an empty database and a dedicated user. The installer needs CREATE/ALTER/INDEX/REFERENCES plus SELECT/INSERT/UPDATE/DELETE. After installation you may revoke CREATE/ALTER if you apply future migrations manually.

The schema is versioned in `database/schema.sql` (migration 1). The installer refuses databases containing unrelated tables or existing users. A partially created schema with zero users can be retried safely.

## 4. Web server

- Apache: `.htaccess` blocks private/source directories and non-front-controller PHP execution.
- Nginx: adapt `deploy/nginx.conf.example`; only `/index.php` executes PHP.
- Do not expose `app/`, `database/`, `resources/`, `docs/`, `tests/`, `bin/`, `deploy/`, dotfiles, or `*.dat|sql|json|lock` files.
- Enable OPcache. Static assets are versioned by `?v=` and can be cached/CDN-served.
- If TLS terminates at a reverse proxy, set `trusted_proxy` in the private `site.php` to the proxy’s exact source IP and forward `X-Forwarded-Proto`/`X-Forwarded-Host`. Without this, forwarded headers are ignored.

## 5. Installer

1. Visit `/`. First access redirects to `/install`.
2. **Stage 1:** requirements, operational agreement, canonical site URL and activation key. The key is validated server-side against `https://manager.pmhserver.name.ng/api.php` for the configured domain.
3. **Stage 2:** database connection and schema/seed installation.
4. **Stage 3:** administrator name, email and password.
5. **Stage 4:** congratulations and a post-sign-in checklist.

The installer uses an exclusive lock and becomes unavailable after completion. If a multi-node deployment shares the document root, run setup from one node only.

## 6. Background worker (required)

```cron
* * * * * /usr/bin/php /var/www/kingdombuilders/bin/worker.php >> /var/log/kingdombuilders/worker.log 2>&1
```

Each run:

- finalizes expired attempts in batches;
- refreshes domain activation (6-hour cache, ≤24-hour outage grace);
- delivers up to 20 queued emails with leased claims and bounded retries;
- prunes expired tokens, old rate limits, audit events older than 90 days and sent mail older than 30 days.

Overlapping runs are safe: `flock` skips duplicates and queue claims use random lease tokens. A lease older than 15 minutes is marked failed and requires manual retry to avoid ambiguous duplicate delivery. Monitor worker exit codes, queue age and failed messages.

## 7. SMTP

In **Admin → Email & SMTP** set host, port, security (STARTTLS/SSL), username, password, sender address/name and administrator notification address. Enable SMTP, save, send a test message and run/await the worker.

- Passwords are encrypted at rest and never rendered back into HTML.
- Unencrypted SMTP is allowed only when `local_preview` is explicitly true.
- Notifications are queued transactionally with the activity that produced them.
- Delivery is at-least-once: an ambiguous network failure after SMTP acceptance can produce a duplicate on retry. Idempotency keys prevent duplicate queue rows, not duplicate remote acceptance.

## 8. Backups and recovery

Back up together:

1. MySQL logical dump (`mysqldump --single-transaction`) or snapshot.
2. The entire private configuration directory, especially `site.php` and `runtime.dat`.
3. Any custom question exports.

Test restores periodically. Without the installation key, encrypted runtime state and SMTP secrets cannot be decrypted; you would need to re-run activation recovery and re-enter SMTP credentials.

## 9. Scaling checklist

- Measure before promising capacity. Plan targets: 100/500/1,000 concurrent users, p95 page <500 ms, answer <250 ms, errors <0.1%.
- Tune PHP-FPM children, MySQL buffer pool and slow-query log.
- Keep quiz starts/answers/scoring on the primary DB; replicas may serve committed reads only after routing is added.
- For multiple web nodes, move sessions and rate limits to shared storage (e.g. Redis) and share the private key through a secret manager. The current file/session defaults are single-node.
- Archive old attempts and partition audit data only when measurements justify it.
- Put static assets behind a CDN; keep authenticated HTML `private, no-store`.

## 10. Activation outage policy

Successful checks are cached for six hours. If the provider is unreachable, the site continues for at most 24 hours after the last successful check, then redirects to administrator recovery. An administrator can sign in at `/admin/authorization` and submit a renewed key. Domain changes must be made in the provider dashboard and matched by the canonical site URL.
