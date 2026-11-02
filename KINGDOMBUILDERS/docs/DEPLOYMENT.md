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

## 3a. Verifying an upload (do this first when a change "does not appear")

1. Open `https://your-domain/health` — it returns JSON with the **build** number and PHP
   version. Compare with the branch: `/health` and the admin footer must agree.
2. Run `php bin/check.php` on the server (or ask your host to). It prints the build and
   checks that the files added by recent updates exist.
3. If the build number is correct but a page still behaves the old way, the host is
   serving cached PHP. Ask them to flush **OPcache** and restart PHP-FPM
   (`opcache.validate_timestamps` should be `1` for shared hosting). Static files such
   as CSS update without OPcache, so a changed stylesheet with unchanged behaviour is a
   strong sign of a stale opcode cache.
4. Browser caches are not the cause for HTML (`Cache-Control: private, no-store`), but
   CSS is versioned: the stylesheet URL carries `?v=<build>`. Hard-refresh once after an
   update.

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

Queued email is delivered by `bin/worker.php`. **Admin → Email & SMTP → Scheduled task**
shows a copy-ready command built from this server's real paths, plus a "How to add this
cron job" guide, the PHP CLI path setting, and the last-run heartbeat.

```cron
* * * * * KB_CONFIG_DIR=/var/lib/kingdombuilders/private /usr/bin/php /var/www/kingdombuilders/bin/worker.php >> /var/lib/kingdombuilders/private/worker.log 2>&1
```

- cPanel/Plesk: **Cron Jobs → Add New Cron Job**, schedule `* * * * *`, paste the
  command from the admin page. Select the PHP version your site uses in MultiPHP
  Manager; enter that binary's absolute path in the admin form if detection is wrong.
- SSH: `crontab -e`, add the same line.
- No scheduler: ask the host to add it. Hitting a URL from a browser is **not** a
  substitute — delivery must run as a command-line task.
- Verify: within two minutes the SMTP page shows a recent heartbeat. Then send a test
  email and confirm the queue row becomes **Sent**.
- No cron yet? **Admin → Notifications → Deliver queued messages now**, or **Send now** on
  an individual row, sends immediately. The batch action is a small,
  time-budgeted batch immediately. It is a stop-gap: it only runs while an administrator
  has the page open, so confirmations and password resets still need the scheduled task.
- A test message is always sent synchronously and never enters the queue; a queue row
  marked *Pending* means the worker has not run yet, not that SMTP failed.

Each run finalizes expired attempts, refreshes domain activation (6-hour cache,
≤24-hour outage grace), delivers up to 20 emails with leased claims and bounded
retries, and prunes expired tokens, old rate limits, audit rows older than 90 days and
sent mail older than 30 days. Overlapping runs are safe (`flock`); a lease older than
15 minutes is marked failed and requires manual retry. Monitor exit codes, queue age,
`worker-heartbeat.json` and `worker.log`.

## 6a. Reader text size

The default base text is 19px. Administrators can enable **Settings → Reading comfort →
extra-large text** (21.5px) for the whole site, and every visitor can pick A / A+ / A++
in the footer. The visitor choice is stored in a first-party cookie for a year and
overrides the site setting for that browser.

## 7. SMTP

In **Admin → Email & SMTP** set host, port, security (STARTTLS/SSL), username,
password, sender address/name and administrator notification address.

- **Test without saving** validates the current form values against the real server and
  reports the exact stage that failed (connection, STARTTLS, TLS, authentication,
  sender, recipient or message data) with the SMTP reply code. It never changes saved
  settings and never renders the password back.
- **Save & send test** persists first, then sends.
- The test sends synchronously to the administrator email; queued activity email still
  depends on the cron worker.
- Passwords are encrypted at rest. Unencrypted SMTP is allowed only when
  `local_preview` is explicitly true.
- Notifications are queued transactionally with the activity that produced them.
  Delivery is at-least-once: an ambiguous failure after SMTP acceptance can duplicate a
  message on retry. Idempotency keys prevent duplicate queue rows, not remote duplicates.
- Common rejection causes: host entered with `https://` or a port; wrong port/security
  pair (587 STARTTLS vs 465 implicit TLS); normal password instead of an app password;
  From address not authorized by the provider; outbound SMTP blocked by the host.

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
