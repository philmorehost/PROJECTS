# Implementation status

Updated: 2026-11-02. This is a working foundation, not a claim of production penetration testing or measured traffic capacity.

## Implemented

| Area | Status | Notes |
|---|---|---|
| Vanilla PHP/MySQL architecture | Done | Front controller, PDO prepared statements, InnoDB schema, no runtime framework |
| HTML5 design | Done | Responsive navy/teal/gold/ivory theme, mobile navigation, progressive-enhancement quiz JS |
| Root installer | Done | Four stages, requirement grid, encoded agreement, activation, DB/schema/seed, admin creation, completion checklist, post-install lock |
| Dynamic favicon | Done | First Unicode character of site title, SVG, ETag revalidation |
| Activation | Done | Provider API contract, strict `status===1`, TLS verification, 6h cache, ≤24h outage grace, encrypted private state, explicit local fixture |
| Accounts | Done | Register, verify (POST confirm), login/logout, reset, profile/password change, session revocation |
| Administration | Done | Dashboard, question CRUD, user CRUD, suspend/unsuspend, audited impersonation with admin-return, last-admin/self guards, results/CSV, settings, SMTP, notifications, audit |
| Quiz/CBT | Done | Immutable snapshots, server scoring, deadline, forward-only/backtracking, shuffle, saved answers/skips, focus policy, ownership checks, idempotent finish |
| Email | Done | Transactional outbox, admin SMTP settings, encrypted secret, test message, leased worker, retries/backoff, sanitized errors, retention pruning |
| Security | Done | CSRF + origin, security headers/CSP, HttpOnly/SameSite cookies, hashed single-use tokens, Argon2id/bcrypt, keyed throttling, AES-256-GCM private state, no public legacy JSON/API |
| Operations | Done | CLI worker/check, Apache rules, Nginx/cron examples, deployment and backup docs |
| Tests/CI | Done | 17 core integration checks, HTTP smoke (installer/admin/member/support/CSRF), real SMTP socket fixture, PHP 8.2/8.3/8.4 + MySQL 8.4 workflow |

## Verification performed locally

- PHP 8.4 syntax lint across project PHP files.
- Core integration suite: **17 passed / 0 failed** (hashing, encryption/tamper, canonical URL, favicon source, quiz payload secrecy, single active attempt, forward-only/ownership, snapshot immutability, scoring/idempotency, deadline, focus finalization, token single-use/revocation, suspension, mail idempotency, nested rollback, rate limiting, activation fixture/encrypted state).
- HTTP smoke against PHP built-in server + MariaDB: installer stages, admin pages/CRUD, suspend/unsuspend/delete, member quiz flow, answer-key secrecy, CSRF 419, cross-origin 403, forward-only 409, ownership 404, CSV export, impersonation/admin-return.
- Password reset: GET does not consume token; POST changes password; token is single-use; old password rejected.
- SMTP fixture: real local SMTP transaction, Unicode encoded headers, recipient envelope and dot-stuffing.
- Worker CLI and `bin/check.php` executed successfully.
- Browser screenshots at 1440px and 390px; mobile horizontal overflow corrected.

## Remaining production gates

1. **Load/performance:** capacity targets are unmeasured. Run representative load tests and tune FPM/MySQL before launch.
2. **Security review:** independent review/penetration test, especially proxy trust, session storage, installer exposure and activation outage behavior.
3. **Provider activation:** local checks use the explicit fixture. Validate a real key/domain against the provider before public launch.
4. **SMTP deliverability:** configure real SPF/DKIM/DMARC-aligned sender and confirm inbox placement.
5. **Multi-node:** current sessions/rate limits/private files are single-node. Add shared session/rate-limit storage and secret management before horizontal scaling.
6. **History hygiene:** legacy public files are removed from the working tree, but Git history may still contain them. Restrict access and arrange an authorized history purge if required.
7. **Monitoring/alerting:** wire worker exit codes, queue age, 5xx rate, DB latency and activation grace expiry into the host’s monitoring.
8. **Retention policy:** confirm legal/organizational retention for results and backups; defaults prune audit at 90 days and sent mail at 30 days.

## Known trade-offs

- PHP encoding/obfuscation is not tamper-proof; a server owner can inspect local code. Stronger enforcement requires signed service capabilities or hosted logic.
- Mail delivery is at-least-once under ambiguous network failure; remote duplicates are possible even though queue rows are idempotent.
- Random question selection uses bounded pivot sampling, not `ORDER BY RAND()` over large banks.
- Focus-change monitoring is advisory and server-enforced by policy; it is not a proctoring guarantee.

## Follow-up: SMTP diagnostics, cron guidance and readability

- `Audit::log()` normalizes string IDs returned by some MySQL/PDO configurations; the
  `/login` TypeError that surfaced as a generic 500 page is covered by a regression test.
- SMTP saving and testing now report the exact failure stage instead of a generic
  "failed" message. Tests can run against unsaved form values, and a successful test
  no longer implies queued activity mail was delivered — that still needs the worker.
- The SMTP page and Notifications page show a copy-ready cron command
  (`KB_CONFIG_DIR` included), cPanel/SSH/no-scheduler instructions, editable PHP CLI
  path, and a worker heartbeat recorded by each run.
- Base typography moved from 15px to 17px with explicit minimum sizes for labels,
  tables, badges and helper text; mobile header wraps instead of shrinking text.

### Follow-up 2: queue is not cron-dependent; larger type

- "Email test remains pending" happens when SMTP delivery is enabled but the scheduled
  task has never run: the test used to be queued like any other message. Tests now send
  synchronously, and **Deliver queued messages now** processes a small, time-budgeted
  batch from the browser (administrator-only, CSRF-checked, throttled).
- The cron command card is included on **Admin → Settings** as well as **Email & SMTP**,
  and the Notifications page shows how many messages are waiting plus the last run.
- A `large_text` administrator setting switches the whole site to a larger type scale;
  the default root size is now 18px (20.5px with the setting on).
- The footer shows the deployed build number for administrators, so an outdated upload
  is visible at a glance.

### Follow-up 3: verify the deployment, then use the new controls

Diagnosis against the live site on 2026-11-02: `/` serves the redesigned landing page and
`/assets/app.css` ends with the 18px readability block, so static files from that update
reached production. The admin-reported behaviour (test stuck at "Pending", no cron card)
belongs to PHP files from an earlier revision — consistent with a partial upload or a
stale OPcache. Two safeguards were added:

- `/health` now returns the build number, and `php bin/check.php` prints the build and
  verifies that the cron/diagnostic files exist on the server.
- An upload-ready zip (`KINGDOMBUILDERS-<version>-upload.zip`) contains every deployable
  file so a whole-folder replacement is one action.

New in build 0.4.0: a dedicated **Cron job** admin page (also linked in the admin nav),
**Send now** per queued message with the real SMTP diagnosis, "Retrying" with the next
attempt time instead of a bare "Pending", a reader-facing A / A+ / A++ text-size control
in the footer, and 19px base text.

### Build 0.5.0: Google sign-in

- **Continue with Google** on both the sign-in and registration pages, using the OAuth
  2.0 authorization-code flow with PKCE, a single-use state, a nonce and an ID-token
  claim check (issuer, audience, expiry, nonce) plus a userinfo cross-check.
- Accounts are linked by verified email: existing members keep their progress, a new
  Google identity creates a verified member when registration is open, and suspended
  accounts are refused. No schema change is required.
- Client ID and secret live in **Admin → Settings → Google sign-in**; the secret is
  sealed with the installation key and never rendered. The exact redirect URI is shown
  with a copy button, alongside Google Cloud console instructions and a readiness badge.
- Tests cover PKCE/URL construction, state replay and expiry, account linking,
  suspension, closed registration, settings validation, the redirect to Google and a
  forged callback — without contacting Google.

### Build 0.6.0: final answers and a redesigned quiz

- **Answers lock on save.** `Quiz::answer()` refuses any change once an option is stored and
  returns `409` with a plain explanation; repeating the *same* choice succeeds so a network
  retry can recover. Payloads now expose `locked` per question and the client renders saved
  answers as read-only.
- **`allow_back` now means "review earlier questions"**, not "change answers". The admin label
  was updated; the stored key is unchanged, so no migration is needed.
- **Rebuilt question and answer screen:** progress dots, answered counter, timer pill with a
  thin time rail, category chip, lock badge, lettered answer cards with hover/focus states,
  a persistent lock note, keyboard shortcuts (`1`–`4`, `Enter`) and a redesigned finish screen
  with a score ring.
- **Rebuilt result page:** pass/fail hero, SVG score ring, summary strip and per-question
  review cards with correct/your-answer markers and references.
- **Design v2 layer:** new token set, sticky translucent header, gradient buttons, softer
  layered shadows, pill badges, rounded tables with sticky headers, pill admin navigation,
  refreshed home hero and cards. Text sizes from the readability work are preserved.

Verification: PHP lint, CSS tree validation, 23 jsdom UI checks (render, lock, revisit,
keyboard, finish) and the HTTP smoke suite extended with lock/idempotency/refusal checks.

### Build 0.7.0: mobile quick-navigation dock

- **Thumb-reachable footer dock** for signed-in users below 760 px: Home, the assessment tab
  and Profile. It replaces hunting through the header menu on a phone.
- The centre tab is a single call to action. With an open attempt it reads **Resume quiz** and
  links straight back to the saved position (gold pulse badge); otherwise it submits
  `POST quiz/start` with a CSRF token and reads **Start quiz**. When the administrator closes
  assessments it becomes a non-interactive **Quiz closed** label. Members whose email address is
  still unconfirmed see **Verify email**, which opens the confirmation screen (and its resend
  button) instead of an authorisation error.
- Administrators get their own variant — **Overview / Members / Profile** — because they cannot
  take assessments.
- The dock is never rendered on the quiz screen (it would cover the answer controls), on the
  installer, on the authorisation confirmation page, for signed-out visitors, or above 760 px.
  `Quiz::activeId()` looks up the single active attempt through the `active_user_id` column and
  the `one_active_attempt` unique key, so the Resume/Start choice always matches the database.
- Layout safety: frosted bar with `env(safe-area-inset-bottom)` for notched phones, a raised
  centre button, active-tab underline, landscape compaction under 520 px of height, reduced
  motion support and print hiding. Page and footer padding grow with a `has-dock` body class so
  nothing is hidden behind the bar.

Verification: PHP lint, CSS parse, `tests/dock_render.php` (no database: start form + CSRF,
resume link, closed state, active tab, administrator variant, icon/label counts) and HTTP smoke
assertions for the dock on member, administrator and signed-out pages.

### Build 0.7.1: SMTP authentication repaired

- **Every authenticated mailbox was rejected** with “The server does not offer a supported
  AUTH method (PLAIN/LOGIN)”, even with correct credentials. The EHLO reader kept the SMTP
  reply code in the capability name (`250-AUTH` instead of `AUTH`), so `AUTH` — and
  `STARTTLS` — were never detected. The code is now stripped before parsing, `AUTH=PLAIN LOGIN`
  is accepted, and mechanisms split over several lines are merged.
- **Smarter sign-in:** PLAIN is preferred (one round trip) and LOGIN is the fallback; servers
  that hide AUTH are probed. `530`/`538` report that TLS is required first, `535` reports bad
  credentials, and a provider offering only CRAM-MD5/XOAUTH2 names that mechanism instead of
  a generic message. Passwords are never placed in an error message.
- Tests: `tests/mail_parse.php` (no database) covers real reply lines, `AUTH=`, multi-line
  and lower-case replies plus mechanism choice; the socket fixture now requires authentication
  (PLAIN and LOGIN), checks a refused password yields stage `auth`, code `535` and no secret,
  and that an XOAUTH2-only server explains itself.

Verification: PHP lint, the parsing suite, the five-server socket fixture, and the existing
integration/HTTP suites in CI.
