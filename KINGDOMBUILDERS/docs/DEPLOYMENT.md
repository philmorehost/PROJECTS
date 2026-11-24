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

**Upgrading an existing site:** upload the new files and open any page. `Migrator::run()` compares `schema_migrations` with the build's version and applies the numbered files in `database/migrations/` once (0.11.0 adds `one_time_codes`). Statements are idempotent, so an interrupted upgrade is retried on the next request. `php bin/check.php` prints the resulting schema version. If the database user cannot create tables, run the file in `database/migrations/` manually.

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
- Every notification is **multipart/alternative**: the queued plain text plus a modern
  HTML rendering with the site monogram, one clear action button, a detail panel and
  security notes. It is image-free (no tracking pixel), uses inline styles plus a small
  dark-mode stylesheet, and keeps a plain URL under each button for restricted clients.
  **Admin → Email & SMTP → Preview email design** opens the live template.
- **Save & send test** persists first, then sends.
- The test sends synchronously to the administrator email; queued activity email still
  depends on the cron worker.
- Passwords are encrypted at rest. Unencrypted SMTP is allowed only when
  `local_preview` is explicitly true.
- Notifications are queued transactionally with the activity that produced them.
  Delivery is at-least-once: an ambiguous failure after SMTP acceptance can duplicate a
  message on retry. Idempotency keys prevent duplicate queue rows, not remote duplicates.
- Capability parsing: the reply code is stripped before EHLO capabilities are read, so
  `250-AUTH PLAIN LOGIN`, `250-AUTH=LOGIN PLAIN`, lower-case replies and AUTH split over
  several lines are all understood. PLAIN is preferred, LOGIN is the fallback, and when a
  server hides AUTH the client probes both. A provider that offers only CRAM-MD5/XOAUTH2
  is named in the error instead of a generic failure.
- Common rejection causes: host entered with `https://` or a port; wrong port/security
  pair (587 STARTTLS vs 465 implicit TLS); normal password instead of an app password;
  From address not authorized by the provider; outbound SMTP blocked by the host.

### Nothing is being delivered on a new domain

Work through these in order — each step removes one possible cause:

1. **Admin → Email & SMTP → Diagnose connection.** This server-side probe tests the saved
   port and the usual alternatives (465 implicit TLS, 587 STARTTLS, 25), reads the SMTP
   greeting and reports DNS, timing and failures for each. It never sends credentials and
   never changes a setting. If a different port answers, the panel offers a one-click
   switch to that port and security mode.
2. **“Connection timed out” or “did not answer”** — a firewall is dropping the traffic.
   Shared hosts and new cloud instances commonly block outbound SMTP. Ask the provider to
   open outbound 587 or 465, or supply their relay hostname and credentials.
3. **“Connection refused”** — nothing is listening: wrong hostname, wrong port, or the mail
   service is not running on that server.
4. **“answered with something that is not SMTP”** — the port belongs to another service
   (POP3, IMAP, HTTPS). Use the mail submission port from the provider's documentation.
5. **Wrong port/security pair** — 465 is TLS from the first byte (*Implicit TLS*); 587 starts
   plain and upgrades (*STARTTLS*). Mixing them produces a timeout at the greeting.
6. **Delivery still queued** — the queue drains only when the cron job runs; see §6.

Log lines now keep the failing stage and the message, for example
`stage=greeting code=none message=The SMTP server did not answer during greeting…`, so the
error log and the Notifications page both identify where the conversation stopped.

### Question access after completion

`Admin → Settings → Assessment settings → Let members review the questions after completing a
quiz` is **off by default**. A completed member then sees only the score: percentage, correct
count, pass mark, unanswered/incorrect totals and the submission time. The question text,
options, references and explanations are not selected from the database and never reach the
browser — reopening the quiz page or calling the quiz API for a finished attempt returns an
empty question list. Turn the toggle on for a full answer review; turning it off withdraws
access immediately, including for attempts completed earlier.

`Show practice feedback after each answer` is a separate setting: it reveals the correct answer
during an attempt, not after it.

## 7a. Google sign-in (optional)

1. In the Google Cloud console create an **OAuth client ID → Web application**.
2. Add the site origin (for example `https://gamio.pmhserver.name.ng`) to **Authorized
   JavaScript origins**.
3. Add the redirect URI shown at **Admin → Settings → Google sign-in**. It is exactly
   `https://<site>/auth/google/callback`; it must match character for character.
4. Configure the OAuth consent screen (scopes `openid`, `email`, `profile`). While the
   app is in *Testing*, only listed test users can sign in; press *Publish* for everyone.
5. Paste the client ID and secret into the admin form, tick **Show “Continue with
   Google”** and save. The secret is sealed with the installation key and is never
   rendered back.

Behaviour:

- Only Google-verified email addresses are accepted.
- An existing account is matched by email, keeping its attempts and results; an
  unverified local account is marked verified by the Google proof.
- A new account is created as a member only when the email is new **and** registration
  is open. No password is stored that the user knows; they can use *Forgot password* to
  add one.
- Suspended accounts are refused. Every Google sign-in is audited.
- State, nonce and a PKCE verifier are held server-side for 15 minutes and consumed once,
  so a replayed or forged callback is rejected.
- The flow requires HTTPS (except an explicit local preview) and needs outbound HTTPS to
  `accounts.google.com`, `oauth2.googleapis.com` and `openidconnect.googleapis.com`.
  If the host blocks those, the page explains that Google could not be reached.

## 7b. The assessment: one attempt, revisable answers, 50 questions in 20 minutes

| Rule | Behaviour |
|---|---|
| **One attempt per member** | Once an attempt is submitted, expired, **or abandoned** the member cannot start another. Leaving the quiz page (any link, the mobile dock, closing the tab) ends the paper: the saved answers are scored and the attempt is used up, with a confirmation first. `/quiz?id=…` for a finished attempt redirects to its result, and the dashboard and mobile dock show **Assessment completed** instead of a start button. The database is the authority: `Quiz::start()` refuses a second attempt inside the same transaction that creates the first. |
| **Answers are revisable until submission** | Choosing another option saves immediately and the **last** choice is the one scored. This is allowed while the attempt is `active` and inside the deadline. `allow_back` (default on) controls whether the member can also go back to earlier questions; with it off, only the question on screen can be changed and *Previous* is disabled. Every save is a normal `UPDATE`, so the audit trail is unchanged. |
| **Nothing changes after submission** | The API answers `409` for any attempt that is no longer active. Question content is not returned once the paper is closed unless the administrator has enabled review. |
| **50 questions, 20 minutes** | `questions_per_attempt` = 50 and `duration_minutes` = 20. The deadline is the *total* budget, fixed server-side when the paper starts; reloading or closing the browser does not reset it, and the worker submits an expired paper automatically. |
| **The bank must be big enough** | A paper takes `min(active questions, 50)`. With fewer than 50 active questions the member sees a shorter paper but still gets the full 20 minutes, and the question bank warns you. **Load starter questions** adds the built-in bank (80 questions across 15 books) without creating duplicates. |

`Settings → Assessment settings` exposes all of it. `seconds_per_question` is kept only as the
per-question pace used when `duration_minutes` is 0 (an attempt created by an older build).

Upgrading from 0.10 or earlier: the migration applies 50/20/revisable answers **once**, recorded
as schema version 3, so later changes you make in Settings are kept. To change it by hand:

```sql
UPDATE settings SET setting_value='50' WHERE setting_key='questions_per_attempt';
UPDATE settings SET setting_value='20' WHERE setting_key='duration_minutes';
```

Members with an attempt already open keep its snapshot (question count, deadline and rules are
frozen at start), which is what makes an in-progress paper fair.

## 7c. Unexpected or automated registrations

If the member count climbs without real people, work through this list.

**What the application itself can do.** Nothing in the code schedules or loops a
registration; every `users` row comes from a POST to `/register`, a Google
sign-in, or the administrator form. A fixed cadence (for example one every 30
seconds) therefore means a client is repeatedly submitting the public form.

**Look at the evidence first.**

```sql
-- Who appeared, from where, and did they ever confirm?
SELECT id, email, created_at, email_verified_at, last_login_at
FROM users ORDER BY id DESC LIMIT 30;

-- Registration and blocked-attempt fingerprints (SHA-256 of the visitor address)
SELECT action, subject_id, source_hash, created_at
FROM audit_events
WHERE action IN ('account.registered','registration.blocked','security.captcha_failed')
ORDER BY id DESC LIMIT 30;
```

One repeating `source_hash` means a single script; many different values means a
distributed run. Before 0.11.0 the fingerprint was based on `REMOTE_ADDR`, so
behind a CDN every visitor shared one value — see *Behind a proxy* below.

**Turn the protections on** (Admin → Settings → Registration protection):

1. **Email codes for sign-up** (on by default). The account is created only
   after the six digits are entered, so form filling writes nothing. Requires
   working SMTP — send a test message first.
2. **reCAPTCHA** — create a v2 “I’m not a robot” key for the exact domain at
   `google.com/recaptcha/admin/create` and paste both keys. Verification happens
   server-side, including a hostname comparison. If Google is unreachable,
   registration pauses instead of silently losing the check.
3. **Sign-ups per hour** (default 60, `0` = unlimited) — a circuit breaker for
   the whole site. Blocked attempts return a friendly “try again later”.
4. **Clean up** what earlier runs created: Admin → Members → *Remove unverified
   accounts* deletes members who never confirmed an email and are older than
   24 hours (batches of 200, audited).

**Second factor at sign-in.** *Email a six-digit code when signing in from an
unrecognised device* is off by default. Turn it on only when the SMTP test
message succeeds: if mail delivery stops, sign-in stops too. A trusted device is
remembered for 30 days. If you are ever locked out, run:

```sql
UPDATE settings SET setting_value='false' WHERE setting_key='otp_login';
```

**Behind a proxy or CDN.** `Security::clientIp()` only trusts
`CF-Connecting-IP`, `X-Real-IP` or the first `X-Forwarded-For` entry when the
request comes from the address in the `trusted_proxy` configuration value (or
during an explicit local preview). Without that trust, per-visitor rate limits
collapse into one shared bucket. Add to `site.php`:

```php
'trusted_proxy' => '203.0.113.10',   // the CDN edge or reverse proxy address
```

**Housekeeping.** `bin/worker.php` deletes expired codes hourly (with the other
retention jobs). Codes are stored only as keyed hashes and expire after ten
minutes.

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
