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
| Syntax | `node tests/support/phpwasm-lint.mjs` | **57 files parsed cleanly** with `token_get_all(TOKEN_PARSE)` |
| Logic | `node tests/support/phpwasm-test.mjs` (`tests/unit.php`) | **31 passed, 0 failed** |
| Views | `node tests/support/phpwasm-render.mjs` (`tests/render.php`) | **24 passed, 0 failed** |

What the logic suite actually asserts:

* **Money** — parsing without floats, rejection of malformed/over-precise/negative input, exact decimal round-trips, half-up basis-point rounding, allocation that always re-adds to the total, display and compact formatting, and an explicit refusal on 32-bit integer platforms.
* **Application state machine** — only declared transitions are legal; approval cannot skip verification; terminal states are terminal; reasons are mandatory for rejection and document requests; reviewers cannot set system-only statuses (e.g. `disbursed`); members can only withdraw before money moves; the three-step tracker mapping.
* **Security policy** — password policy (length, common words, name/e-mail fragments), transaction-PIN policy (rejects `0000`, `1234`, `4321`, `2026`), hashing round-trip and rehash detection, CIDR boundary matching for IPv4 /25 /12 /8 and IPv6 mismatch, local-vs-remote classification, the cPHulk-style block decision table (threshold, immediate block for unknown accounts, protection-off), country policy (unknown → allow by default, deny on request, filter-off disables rules), and the escalation ladder day → week → month → year with clamping and a safe fallback for invalid configuration.
* **Settings** — every declared type rejects the values the draft blueprint would have accepted ("sixty" in a retention window, `blue` as a colour, `lots` as a money value, control characters in a title); the seed file cannot contain a key the schema does not declare.
* **Schema** — all fifteen tables exist, money is `DECIMAL(15,2)`, InnoDB everywhere, the education CHECK constraint, the idempotency unique key, the flat `parish_directory` view replacing the draft's denormalised table, 250 ISO country rows with Nigeria whitelisted, and no private-key material in the tree.

What the view suite asserts:

* All fourteen screens render against fixtures under a strict error handler (any PHP notice fails the run).
* User-supplied strings carrying `<script>alert("xss")</script>` are escaped on every screen that displays them.
* Members never see the reviewer decision form or the transaction PIN panel; representatives do not see admin-only parish controls.
* Role gates exist on every privileged page (`people`, `security`, `settings` → admin; `treasury` → treasurer).
* Every form `action` in the templates has a matching POST handler; every bottom-navigation target is a routed page.
* No template prints raw `$_GET/$_POST/$_SERVER/$_COOKIE`.
* The stylesheet declares 16 px base type, a 48 px touch token, 16 px inputs, both themes, reduced-motion, print, tracker, combobox, offline banner and install prompt — and contains no type below 13 px.
* The manifest is valid JSON with 192/512 any-purpose **and** maskable icons that exist on disk; the service worker versions its cache, deletes old caches, and refuses to cache `/api/` or non-GET requests.

### 2.2 What that does and does not prove

It proves the grammar, the pure logic and the rendered markup. It does **not** prove anything that needs a database, an SMTP server or a real browser: SQL execution, transactions under concurrency, `SELECT … FOR UPDATE` behaviour, session persistence, real email delivery, installability, or Android/iOS layout.

---

## 3. Written but not yet verified (must be tested on hosting)

| Area | Why it is unverified | How to verify |
|---|---|---|
| Schema execution | Needs MySQL 8 / MariaDB 10.6 | Run the installer on staging; confirm all 15 tables + the view + triggers. |
| `SELECT … FOR UPDATE` pool guard | Needs a real database | Two sessions approve the last Seed Grant slot at once; only one may succeed (target: 100 % rejection of the second). |
| Brute-force end-to-end | Needs request IPs and time | Script: 5 failures → suspension; 10 failures → IP block; unknown account → immediate block; 5 clean sessions → crown badge; toggle protection off → existing suspensions remain. |
| Ledger triggers | Trivial to check but needs MySQL | `UPDATE remittance_ledger SET amount = 1` must raise `45000`. |
| Mail delivery | Needs SMTP | Configure `config/smtp.php`, run `bin/mail-worker.php --verbose`, confirm inbox placement and SPF/DKIM. |
| Firewall worker | Needs a firewall adapter | Test the `command` adapter against a scratch CSF install; confirm whitelisted/loopback addresses are skipped. |
| Activation | Needs a real token and domain | Install with a live token at stage 1 and 3; confirm the 24-hour grace window by pointing the host at a dead manager. |
| PWA install | Needs a browser | Chrome Android: install banner, standalone launch, offline reload. iOS Safari: Share → Add to Home Screen, guide text. |
| 320–430 px layout | Needs a browser | No horizontal scroll on every page; 48 px targets; dark/light contrast. |
| Service-worker update | Needs a version bump | Change `SHELL_VERSION`, reload twice, confirm the "Reload" toast. |

`tests/integration.php` is the intended home for the database-backed cases above. It is written to refuse to run unless the database name ends in `_test`.

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

1. **No self-service password reset.** Password changes require the current password; recovery is by the province office (the UI says so).
2. **No forced first-login password change.** An administrator-created account keeps its temporary password until the holder changes it. Add a `must_change_password` flag before broad rollout.
3. **No two-factor authentication** for administrators.
4. **No CSV import** for the parish directory (the draft implied a large directory; bulk entry is manual, or via SQL by the operator).
5. **No exports** (applications, ledger, directory) — needed for board reporting.
6. **No payment gateway.** Disbursements are *recorded* and reconciled by two people; nothing is sent to a bank.
7. **No SMS notifications.**
8. **Mail is at-least-once.** A crash between "SMTP accepted" and "row marked sent" can duplicate a message. Templates contain no secrets, so this is tolerable; keep it in mind before adding anything sensitive.
9. **No automated off-site backup** — the guide schedules host backups and demands a restore drill.
10. **Academy progress entry UI is not wired** (`academy_progress` exists and gates Seed Grant eligibility, but no screen writes it yet). Until then the Seed Grant eligibility rule will report "not graduated" for everyone; the administrator can override by creating the row directly. **This is the largest functional gap in the program flow.**
11. **`tests/integration.php` needs writing** (see §3 for the case list).
12. **No load testing.** Capacity numbers are unmeasured; do not promise throughput.
13. **Accessibility**: keyboard focus, labels, `aria-live` and contrast are designed in, but have not been audited with a screen reader or axe.

---

## 6. Repository layout

```
RISE63/
  blueprint.php              refined blueprint (this version)
  index.php                  front controller
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
  bin/                       mail-worker, security-worker, maintenance, build-assets
  database/                  schema.sql, countries.sql, seed.sql, ledger-guards.sql
  public/assets/             app.css, app.js, security.js + fingerprinted builds
  public/icons/              PWA icons (any + maskable + apple-touch)
  tests/                     unit.php, render.php + php-wasm harnesses
  docs/                      DEPLOYMENT.md, IMPLEMENTATION_STATUS.md, archive/
```

---

## 7. Recommended order of work from here

1. Run the installer on staging MySQL; then write `tests/integration.php` with the §3 cases.
2. Wire the Academy progress screen (gap 10) — it is the one place where a documented rule currently cannot be satisfied through the UI.
3. Self-service password reset with signed, single-use, 30-minute tokens, once mail delivery is confirmed.
4. CSV import for the parish directory.
5. Exports (CSV/PDF) for the ledger and applications.
6. Accessibility audit with a real screen reader, then a penetration test before real money moves.
