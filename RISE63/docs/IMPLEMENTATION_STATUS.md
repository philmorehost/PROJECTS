# RISE 63 — implementation status

Updated: 2026-11-08 · version 1.0.0 · review this file before any launch decision.

This page is deliberately conservative. It separates **verified here**, **written but unverified**, and **not built**.

---

## 1. What this deliverable is

A complete native-PHP implementation of the refined blueprint (`blueprint.php`): the four-stage installer, the cPHulk/Imunify360-style security engine, the parish directory, the five modules with an enforced state machine, the append-only remittance ledger, the notification queue with two workers, and the PWA shell (manifest, service worker, install prompt, dynamic SVG favicon, offline banner).

Every page is server-rendered HTML with one stylesheet and one deferred script. There is no build step, no Composer dependency and no JavaScript framework.

---

## 2. Verified in this workspace

The sandbox has no PHP binary, MySQL or curl (`apt` sources are unreachable, `release-assets.githubusercontent.com` is blocked). Verification therefore ran in two layers.

### 2.1 PHP engine tests (php-wasm, real PHP 8.4 grammar)

| Suite | Command | Result |
|---|---|---|
| Syntax | `node tests/support/phpwasm-lint.mjs` | **64 files parsed cleanly** with `token_get_all(TOKEN_PARSE)` |
| Logic | `node tests/support/phpwasm-test.mjs` (`tests/unit.php`) | **40 passed, 0 failed** |
| Views | `node tests/support/phpwasm-render.mjs` (`tests/render.php`) | **28 passed, 0 failed** |
| Requests | `node tests/support/phpwasm-smoke.mjs` | **15 passed, 0 failed** |

What the logic suite actually asserts:

* **Money** — parsing without floats, rejection of malformed/over-precise/negative input, exact decimal round-trips, half-up basis-point rounding, allocation that always re-adds to the total, display and compact formatting, and an explicit refusal on 32-bit integer platforms.
* **Application state machine** — only declared transitions are legal; approval cannot skip verification; terminal states are terminal; reasons are mandatory for rejection and document requests; reviewers cannot set system-only statuses (e.g. `disbursed`); members can only withdraw before money moves; the three-step tracker mapping.
* **Security policy** — password policy (length, common words, name/e-mail fragments), transaction-PIN policy (rejects `0000`, `1234`, `4321`, `2026`), hashing round-trip and rehash detection, CIDR boundary matching for IPv4 /25 /12 /8 and IPv6 mismatch, local-vs-remote classification, the cPHulk-style block decision table (threshold, immediate block for unknown accounts, protection-off), country policy (unknown → allow by default, deny on request, filter-off disables rules), and the escalation ladder day → week → month → year with clamping and a safe fallback for invalid configuration.
* **Settings** — every declared type rejects the values the draft blueprint would have accepted ("sixty" in a retention window, `blue` as a colour, `lots` as a money value, control characters in a title); the seed file cannot contain a key the schema does not declare.
* **Licensing** — keys are rejected before any network call if they are malformed (spaces, newlines, wrong length, non-ASCII); domains are normalised the way the provider stores them (`www.`, scheme, port, trailing dot removed); and only `status: 1` grants access across the documented response table, including `{"status":0}`, unreadable JSON, a missing status field and the suspended marker.
* **Account lifecycle** — temporary passwords satisfy the policy they will be replaced under, contain no ambiguous glyphs and do not collide across 50 draws; reset tokens are 64 hex characters, only their sha256 is stored, and the resolver's pattern rejects every malformed shape (short, long, uppercase, path traversal).
* **Migration shipping rules** — file names, contiguous versions, no `DELIMITER`, no triggers, no `DROP TABLE`.
* **Academy attendance** — the percentage gate used to unlock Seed Grants (0 sessions → 0 %, 8/10 → 80 %, clamping at 100 %).
* **Schema** — all fifteen tables exist, money is `DECIMAL(15,2)`, InnoDB everywhere, the education CHECK constraint, the idempotency unique key, the flat `parish_directory` view replacing the draft's denormalised table, 250 ISO country rows with Nigeria whitelisted, and no private-key material in the tree.

What the view suite asserts:

* The password-help page offers a reset link **only** when SMTP is configured, and its confirmation never reveals whether an address has an account.
* The reset and change-password screens never echo the token into visible text, and a forced change offers no cancel path.

* All fourteen screens render against fixtures under a strict error handler (any PHP notice fails the run).
* User-supplied strings carrying `<script>alert("xss")</script>` are escaped on every screen that displays them.
* Members never see the reviewer decision form or the transaction PIN panel; representatives do not see admin-only parish controls.
* Role gates exist on every privileged page (`people`, `security`, `settings` → admin; `treasury` → treasurer).
* Every form `action` in the templates has a matching POST handler; every bottom-navigation target is a routed page.
* No template prints raw `$_GET/$_POST/$_SERVER/$_COOKIE`.
* Every form action in the templates has a POST handler, and the only GET-with-action route
  (`document-download`) lives in its own file with scope, attachment and `realpath` containment
  checks — the tests assert all three.
* The stylesheet declares 16 px base type, a 48 px touch token, 16 px inputs, both themes, reduced-motion, print, tracker, combobox, offline banner and install prompt — and contains no type below 13 px.
* The manifest is valid JSON with 192/512 any-purpose **and** maskable icons that exist on disk; the service worker versions its cache, deletes old caches, and refuses to cache `/api/` or non-GET requests.

### 2.2 Static SQL consistency audit (no database needed)

Because SQL cannot be executed here, the code was cross-checked against `database/schema.sql` with a
one-off parser instead:

* every table named after `FROM` / `JOIN` / `INSERT INTO` / `UPDATE` exists (22 tables + the view);
* every `INSERT` column list and every `UPDATE … SET` assignment names a real column (no mismatches);
* 86 qualified/selected column references in `SELECT` lists resolve, including through table aliases.

The audit is a script kept outside the repository (it is a review tool, not a test); rerun it after schema
edits. It cannot catch type, index or constraint mistakes — only naming and existence.

### 2.3 What that does and does not prove

It proves the grammar, the pure logic and the rendered markup. It does **not** prove anything that needs a database, an SMTP server or a real browser: SQL execution, transactions under concurrency, `SELECT … FOR UPDATE` behaviour, session persistence, real email delivery, installability, or Android/iOS layout.

---

### 2.4a Field report — the first real deployment attempt, and what it exposed

The first upload to a real host (`buildroom.rccglp63yaya.org.ng`, PHP 8.x on cPanel) produced:

```
PHP Fatal error: Uncaught Error: Undefined constant "ROOT"
in .../install/index.php:21
```

Three defects, all of a kind that lint, the unit suite and the view harness cannot see, because
none of them execute a page:

| Defect | Why every existing test missed it | Fix |
|---|---|---|
| `install/index.php` used bare `ROOT` while sitting outside the `RISE63` namespace | Lint only parses; the view harness loads *Views*, never the installer | `use const RISE63\{ROOT};` in the installer |
| `app/Core/Installer.php` used bare `MIN_PHP` inside `RISE63\Core` — stage 1 would have fataled on the *next* screen too | Same; it is only reached by a POST through the wizard | Fully qualified `\RISE63\MIN_PHP` |
| `session.sid_length` / `sid_bits_per_character` are deprecated in PHP 8.4 | Only runs when a session starts, and only warns | Set only below 8.4; PHP 8.4+ already uses those values |

The response is structural, not just three edits: **`tests/support/phpwasm-smoke.mjs`** now drives
real requests through `index.php` in a fresh PHP process each time — installer first screen, CSRF
rejection on POST, a crafted `page` parameter, the installed-lock behaviour, and the wizard refusing
to run twice. A new view-suite test also scans every entry-point file for a bare bootstrap constant
outside the `RISE63` namespace, so the class of bug fails a test rather than a user's first upload.

Lesson recorded here because it applies to the whole project: **a green lint and a green unit suite
say nothing about whether a page renders.** The smoke suite is now the check that does.

### 2.4 Defects found by this review and fixed

Worth recording, because each would have failed only on a live database or browser:

| Defect | Effect if shipped | Fix |
|---|---|---|
| Document download was wired as a POST-only action but linked as a GET | Every attachment link returned 404 | Moved to `app/download.php`, routed for GET, with scope + attachment + `realpath` containment checks |
| The optional attachment on the apply form was never submitted or stored | Applicants believed a document was attached | One `document-upload` handler used by both the apply form (multipart) and the detail page |
| The parish picker on the public registration page received 401 | Registration on a phone was unusable | Restricted anonymous branch: ≥3 characters, ≤15 rows, names only, cookie rate limit, no session |
| An outbound activation call happened while a page was already being rendered in maintenance mode | "Headers already sent" during outages | Maintenance check moved before the activation gate |
| Academy attendance had no write path | The Seed Grant graduation rule was unreachable | `Academy::save()` + panel, staff-only |
| Typo `RPCG` in the meta description | Visible on every page | Corrected |

## 3. Written but not yet verified (must be tested on hosting)

| Area | Why it is unverified | How to verify |
|---|---|---|
| Schema execution | Needs MySQL 8 / MariaDB 10.6 | Run the installer on staging; confirm all 15 tables + the view + triggers. |
| `SELECT … FOR UPDATE` pool guard | Needs a real database | Two sessions approve the last Seed Grant slot at once; only one may succeed (target: 100 % rejection of the second). |
| Brute-force end-to-end | Needs request IPs and time | Script: 5 failures → suspension; 10 failures → IP block; unknown account → immediate block; 5 clean sessions → crown badge; toggle protection off → existing suspensions remain. |
| Ledger triggers | Trivial to check but needs MySQL | `UPDATE remittance_ledger SET amount = 1` must raise `45000`. |
| Mail delivery | Needs SMTP | Configure `config/smtp.php`, run `bin/mail-worker.php --verbose`, confirm inbox placement and SPF/DKIM. |
| Firewall worker | Needs a firewall adapter | Test the `command` adapter against a scratch CSF install; confirm whitelisted/loopback addresses are skipped. |
| Licensing | **The API has never been called from this environment** — this sandbox blocks `manager.pmhserver.name.ng` (outbound HTTPS is limited to package registries). The response handling is tested against the documented contract; the network call itself is not. | Run `php bin/license-check.php` on the host with a real key. Then `php bin/license-check.php --domain=wrong.example` to confirm `status: 0`, and `--key=… --save` to confirm a key is stored. For the grace window: confirm once, block outbound HTTPS, and reload. |
| PWA install | Needs a browser | Chrome Android: install banner, standalone launch, offline reload. iOS Safari: Share → Add to Home Screen, guide text. |
| 320–430 px layout | Needs a browser | No horizontal scroll on every page; 48 px targets; dark/light contrast. |
| Service-worker update | Needs a version bump | Change `SHELL_VERSION`, reload twice, confirm the "Reload" toast. |
| Migrations | Needs MySQL | On a copy of staging: `bin/migrate.php --status`, `--dry-run`, then `--apply`; re-run and confirm exit code 2 (nothing pending). Corrupt one applied file and confirm exit 3. |
| Password reset end to end | Needs MySQL + SMTP | Request a link, open it, set a password, confirm the old one stops working and other devices are signed out. Request again for a non-existent address and confirm the same wording. |

`tests/integration.php` now exists for the database-backed cases above. It refuses to run unless the database name ends in `_test`, and it has **never been executed** — this sandbox has no MySQL:

```sh
RISE63_TEST_DSN='mysql:host=127.0.0.1;dbname=rise63_test' \
RISE63_TEST_USER=rise RISE63_TEST_PASS=secret php tests/integration.php
```

It asserts: the schema and triggers build; `UPDATE`/`DELETE` on the ledger raise 45000; a second
connection blocks on the pool lock for at least 1.5 s; the Idempotency key collision is refused by the
unique index; reset tokens expire and burn correctly against real `DATETIME` arithmetic; and the
migration runner applies in order, re-runs as a no-op, and reports a modified file. Every one of those
is a *claim* until it runs on a machine with MySQL — treat a green unit/render run as the weaker signal
it is. Nothing here has been executed against a database.

---

## 4. Deliberate design decisions (and the trade-offs)

| Decision | Why | Trade-off |
|---|---|---|
| No obfuscation for the activation check | Obfuscated PHP hides bugs, not secrets; a server owner can always read the code | A determined operator can see the check. Real enforcement lives in the response of the manager, not the shape of the code. |
| Recognition is an indicator, never a bypass | A whitelist that skips authentication is how shared-host sites get owned | A recognised IP can still be blocked by failures. |
| Country "unknown" defaults to allow | Most Nigerian mobile traffic is CGNAT; denying unknown countries would lock out real members | Blacklisting therefore only acts on *resolved* countries. |
| Firewall work is queued, never executed in a request | Running privileged shell commands from a web request is the classic privilege-escalation path | Blocks are application-level until the worker is installed and configured. |
| Members cannot apply twice to the same program per cycle | The ₦100,000 pool is small; duplicates would silently consume it | Repeat applications need a new cycle year (or an admin withdrawal). |
| Seed Grant pool is reserved monthly, not annually | Matches "₦100,000 monthly pool" | Mid-year disbursements cannot borrow against future months. |
| Education Bridge pays the school | The blueprint says "direct school payments" | The payee field rejects the applicant's own name for that program. |
| Password reset is human-mediated | No mail delivery is guaranteed yet, and a reset link is a second authentication path | An officer must be reachable. Self-service reset is a listed gap. |
| Persistent PDO connections opt-in | A crashed FPM request can leave a connection mid-transaction | Slightly higher connect cost per request. |
| Sessions on disk in `storage/sessions` | Correct and simple on one server | Not horizontally scalable; a shared handler is required before adding nodes. |

---

## 5. Known gaps (honest list)

1. **No two-factor authentication** for administrators. Everything else in the account lifecycle is built.
2. **No CSV import** for the parish directory (the draft implied a large directory; bulk entry is manual, or via SQL by the operator).
3. **No exports** (applications, ledger, directory) — needed for board reporting.
4. **No payment gateway.** Disbursements are *recorded* and reconciled by two people; nothing is sent to a bank.
5. **No SMS notifications.**
6. **Mail is at-least-once.** A crash between "SMTP accepted" and "row marked sent" can duplicate a message. Templates contain no secrets, so this is tolerable; keep it in mind before adding anything sensitive.
7. **No automated off-site backup** — the guide schedules host backups and demands a restore drill.
8. **`tests/integration.php` has never run.** It is written and lint-clean; it needs a `_test` database.
9. **No load testing.** Capacity numbers are unmeasured; do not promise throughput.
10. **Password history is one deep.** A reset or change cannot reuse the *current* password; older ones are not stored, so they are not checked. Honest limitation of not keeping a history table.
11. **Accessibility**: keyboard focus, labels, `aria-live` and contrast are designed in, but have not been audited with a screen reader or axe.
12. **Academy cohorts** (`academy_cohorts`) exist in the schema but attendance is recorded per application, not per cohort. Fine for a single yearly intake; add cohort assignment before running parallel intakes.

---

## 6. Repository layout

```
RISE63/
  blueprint.php              refined blueprint (this version)
  index.php                  front controller
  router.php                 development router for php -S (never deployed)
  install/index.php          four-stage wizard
  api/index.php              JSON parish selector (authenticated)
  favicon.svg                dynamic SVG favicon
  manifest.webmanifest       PWA manifest
  sw.js                      service worker
  app/
    bootstrap.php            constants, polyfills, class loading
    actions.php              every POST (CSRF + origin checked first)
    pages.php                every GET (role-gated)
    Core/                    Money, Database, Settings, Security, BruteForce, GeoResolver,
                             Auth, Registration, ParishDirectory, Program, Applications,
                             Ledger, Notifier, Mailer, Activation, Installer, Audit, View
    Views/                   layout + auth/ member/ staff/ partials/
  bin/                       mail-worker, security-worker, maintenance, migrate, build-assets
  database/                  schema.sql, countries.sql, seed.sql, ledger-guards.sql
  database/migrations/       numbered, checksummed schema changes (0001 = account lifecycle)
  public/assets/             app.css, app.js, security.js + fingerprinted builds
  public/icons/              PWA icons (any + maskable + apple-touch)
  tests/                     unit.php, render.php, integration.php (MySQL), run.sh
  tests/support/             php-wasm harnesses: lint, logic, views, request-level smoke
  docs/                      DEPLOYMENT.md, IMPLEMENTATION_STATUS.md, archive/
```

---

## 7. Recommended order of work from here

1. Run the installer on staging MySQL; then write `tests/integration.php` with the §3 cases.
2. Self-service password reset with signed, single-use, 30-minute tokens, once mail delivery is confirmed.
3. CSV import for the parish directory.
4. Exports (CSV/PDF) for the ledger and applications.
5. Accessibility audit with a real screen reader, then a penetration test before real money moves.
