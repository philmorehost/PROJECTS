# RISE 63 PLATFORM — REFINED TECHNICAL & ARCHITECTURAL BLUEPRINT

> **Status:** v1.0 refined blueprint — implementation baseline for `RISE63/`.
> **Owner:** RCCG Lagos Province 63 — LP63 YAYA CSR & Young Professionals Club.
> **Supersedes:** the first-draft blueprint (retained in `docs/archive/blueprint-v0.md` for traceability).

---

## 1. Executive Summary & Objectives

The **RISE 63 Platform** is a mobile-first Progressive Web Application (PWA) that unifies five things the Province currently tracks in WhatsApp groups, spreadsheets and paper files:

1. **Grassroots parish intake** — who the YAYA CSR representative is in every parish, and what the parish actually needs.
2. **Applicant profiling** — members, super teens and tertiary students in one searchable directory.
3. **Four empowerment programs** — RISE Academy, Seed Grants, Cooperative Access, Education Bridge.
4. **Financial remittances** — applicant disbursements and program pool accounting with a transaction PIN and a full audit trail.
5. **Administrative security** — cPHulk / Imunify360-style brute-force protection run from a single control panel.

Design constraints, in priority order:

| # | Objective | Measurable target |
|---|---|---|
| 1 | Usable by a 19-year-old on a 360 px Android screen **and** a 70-year-old officer | ≥ 16 px base type, ≥ 48 px touch targets, no horizontal scroll at 320 px |
| 2 | Find your parish in under 3 seconds | Live-filtered Zone → Area → Parish picker, server-agnostic (works on 2G after shell cache) |
| 3 | Never lose an application | Durable draft saves + offline queue with explicit sync state |
| 4 | Enterprise-grade login hardening without a WAF | cPHulk-style account/IP windows, escalation ladder, country filter, firewall job queue |
| 5 | Deployable on ordinary cPanel shared hosting | Vanilla PHP 8.2+, MySQL/MariaDB, no Composer, no build step, no root |

### 1.1 What changed versus the draft blueprint

The first draft was directionally right but had eleven gaps that would have caused rework or security incidents. These are now resolved and are binding:

| # | Draft gap | Refinement |
|---|---|---|
| 1 | Single `parish_structure` row repeated zone/area names → duplicate zoning data | Normalised `zones → areas → parishes` tree with foreign keys; the flat view is preserved as the `parish_directory` SQL view for reporting. |
| 2 | `role` enum had no installation owner and no finance separation | Roles: `provincial_admin`, `provincial_treasurer`, `csr_rep`, `youth_member`. Treasurer alone may disburse; admin alone may change security policy. Separation is enforced in code, not just in the enum. |
| 3 | Security settings as free-text `TEXT` | Every setting is validated on write against a typed schema (`int` ranges, enums, booleans, e-mail). Invalid values can never be persisted. |
| 4 | Country filtering described but no geo source | MaxMind-style MMDB is unavailable on shared hosting, so country resolution is an **explicit, pluggable `GeoResolver`** (Cloudflare `CF-IPCountry` when the request provably comes from a trusted proxy, an operator-managed CIDR→country table, or fail-open "unknown"). Unknown country is **never** treated as blacklisted. |
| 5 | "Firewall Level Blocks" implied PHP runs firewall commands | PHP only **queues** `firewall_jobs`. A separate, least-privilege CLI worker (`bin/security-worker.php`) applies and expires blocks. If no adapter is configured the queue stays pending and the UI says so. |
| 6 | "Silent installer" with an obfuscated `sys_check_core()` | Activation is a normal, auditable, TLS-verified HTTPS call to the manager. It is cached for 6 h with a 24 h outage grace window, and a documented local fixture exists for development. Obfuscation is explicitly rejected: it hides bugs, not secrets. The word "license" is not used in UI, code, logs or parameters — the product term is **system activation / system token**. |
| 7 | PIN as the only confirmation for money movement | PIN + password re-entry for first-time payee registration, PIN lockout after 5 failures/15 min, 4-digit PIN floor raised to *reject* trivial sequences (`0000`, `1234`, birth-year repeats), and every disbursement writes an immutable `remittance_ledger` row. |
| 8 | `TEXT` JSON columns | MySQL 5.7+/8 JSON where available, with a `TEXT` fallback and application-side schema validation (`document refs`, `needs assessment`, `academy progress`). |
| 9 | Module B/C/D/E described as features but with no state machine | Each program has an explicit, enforced lifecycle (see §7.2) and a single `application_events` timeline table. Illegal transitions return 409. |
| 10 | No offline story beyond "shows a banner" | Service worker + IndexedDB-free approach (Cache API + a small `localStorage` write queue) with idempotency keys on queued submissions, so a replayed submit cannot duplicate an application. |
| 11 | No accessibility or reduced-data consideration | Skip links, focus-visible rings, `aria-live` status regions, `prefers-reduced-motion`, `prefers-reduced-data`, system theme default with manual override. |

### 1.2 Explicit non-goals

Payment-gateway integration, SMS, native app-store builds, multi-tenant SaaS, and automated (unsupervised) grant approval. Disbursements are **recorded** and reconciled; they are not initiated to a bank from this application. A human always presses the final button, and a second human verifies.

---

## 2. Mobile-First UX, Typography & Simplicity Framework

### 2.1 Type scale (rem based, single source of truth in `public/assets/app.css`)

| Token | Size | Used for |
|---|---|---|
| `--t-xs` | 0.8125 rem / 13 px | Badges, meta only — never body copy |
| `--t-sm` | 0.9375 rem / 15 px | Secondary labels adjacent to a larger control |
| `--t-base` | **1rem / 16 px minimum** | Body copy, all inputs, all table cells |
| `--t-lg` | 1.125 rem / 18 px | Card titles |
| `--t-xl` | 1.375 rem / 22 px | Section headings |
| `--t-2xl` | clamp(1.625 rem, 4.5vw, 2.25 rem) | Page headings |
| Line height | 1.6 body / 1.25 headings | `leading-relaxed` equivalent |

Rules: body text is never below 16 px; every interactive element is at least **48 × 48 px**; active state is `transform: scale(.97)`; focus ring is a 3 px high-contrast outline that survives both themes; nothing relies on colour alone (status badges carry text + icon).

### 2.2 Themes

Default follows the OS (`prefers-color-scheme`), overridable to **Light / Dark / System** from both the bottom navigation and Profile, persisted server-side on the user and client-side in `localStorage` for pre-auth pages. All pairs are checked to ≥ 4.5:1 for text and ≥ 3:1 for UI boundaries in both themes.

### 2.3 Live-search parish selector

A single accessible combobox (`role="combobox"` + `aria-activedescendant`, keyboard-operable) that filters on **parish, area and zone** names simultaneously, debounced at 120 ms, server-provided JSON at `/api/parishes` (cached 1 h, ETag). Selecting a parish auto-fills its area and zone read-only so nobody can assemble an impossible combination.

### 2.4 Status micro-tracker

A three-step component (`Submitted → Under Review → Approved/Disbursed`) rendered from the real state machine, with an `aria-live="polite"` summary line ("Step 2 of 3: Under review since 4 Nov"). Rejected applications show the reason inline instead of a dead-end badge.

### 2.5 Offline, sync and performance feedback

- Floating banner when `navigator.onLine === false`: *"You are offline. Changes will sync when you reconnect."* (dismissible, `role="status"`).
- Queued writes show a **Pending sync** chip until the server acknowledges; failed replays surface a *"Needs your attention"* card rather than silently dropping data.
- Submissions are protected by a client-generated idempotency key, server-unique per user+program+key.
- `Save-Point` behaviour on long forms: drafts auto-save every 20 s to the server (authenticated) and to `localStorage` (guest pre-auth).

### 2.6 Readability for senior officers

Optional **"Large text"** toggle (17 px/1.75 and 19 px/1.8) stored on the profile, plus a print stylesheet for parish needs-assessment hand-outs.

---

## 3. PWA & Native Experience Architecture

| Artifact | Detail |
|---|---|
| `manifest.webmanifest` | `name: "RISE 63"`, `short_name: "RISE63"`, `start_url: "./?source=pwa"`, `display: standalone`, `orientation: portrait-primary`, `theme_color: #2563eb`, `background_color`, maskable + any-purpose icons at 192/512 (plus apple-touch-icon and monochrome mask icon). |
| `sw.js` | Pre-caches the app shell (`/`, `/index.php`, manifest, CSS, JS, icons, offline page) at install; network-first for HTML with an offline fallback, stale-while-revalidate for fingerprinted assets, **never** caches authenticated POSTs or `/api/*` responses containing personal data. Version constant bumped per release; old caches deleted on activate. |
| Install prompt | Captures `beforeinstallprompt`, shows a floating "Install RISE 63" banner; iOS/Safari gets an illustrated Share → Add to Home Screen guide; hidden in `display-mode: standalone`; dismissal snoozed for 14 days. |
| Dynamic SVG favicon | `/favicon.svg` builds an inline SVG from the first grapheme of the admin-configured site title (colour-stable hash → hue), served with a strong `ETag` and 1-day cache, and referenced from `<head>` on every page including install and offline. |
| Update flow | New service-worker version triggers a non-blocking "A new version is available — Refresh" toast; never force-reloads a form in progress. |

---

## 4. Technical Stack & Performance

- **PHP 8.2+**, no framework, PSR-4-ish `RISE63\` autoloader, front controller `index.php`, strict types everywhere.
- **MySQL 8 / MariaDB 10.6+**, InnoDB, utf8mb4, explicit foreign keys, `DECIMAL(15,2)` money, `CHECK` constraints, `JSON` where supported.
- **PDO** with prepared statements only (`ATTR_EMULATE_PREPARES => false`), optional persistent connections (`DB_PERSISTENT` env, default off — persistent connections are unsafe with transactions on some FPM setups and are therefore opt-in).
- **Money type** — a small `Money` value object doing integer-kobo arithmetic with half-up rounding; no float ever touches money.
- **Performance budget** — first HTML < 45 KB gzipped, one CSS file, one deferred JS file; asset filename carries a content hash for `immutable` caching; OPcache and gzip/Brotli documented as hosting requirements.
- **Caching** — `Cache-Control: no-store` on authenticated HTML, `immutable` + 1 year on fingerprinted assets, `public, max-age=86400` on the favicon, ETag on the parish JSON.
- **Timezone** — stored UTC, displayed Africa/Lagos with an explicit label so an officer never mis-reads a deadline.

---

## 5. Data Model (implemented in `database/schema.sql`)

Normalised core:

```
zones (id, name, code)
  └── areas (id, zone_id, name, code)
        └── parishes (id, area_id, name, code, address, yaya_rep_name, yaya_rep_phone, youth_count, needs_summary)
users (id, parish_id→parishes, full_name, email, phone, password_hash, role, …security columns…, theme, large_text, login_notifications_enabled)
program_applications (id, user_id, program_type, education_level, school_name, requested_amount, documents_json, status, education fields…)
application_events (id, application_id, actor_id, from_status, to_status, note)   -- immutable timeline
remittances (id, application_id, amount, payee, bank, reference, recorded_by, verified_by, status)
remittance_ledger (id, entry_type, amount, balance_after, reference, application_id, created_by)  -- append-only
security_settings (setting_key, setting_value)                                      -- typed, validated
security_logs (id, ip, country_code, username_attempted, event_type, details)       -- cPHulk-style
security_ips (ip, clean_sessions, whitelisted, failure_count, blocked_until)
security_accounts (user_id, failure_count, is_suspended, ……)
country_rules (country_code, country_name, action)                                  -- 250 seeded
manual_access_rules (rule_type, target_value, action)
geo_ip_ranges (cidr_start, cidr_end, country_code)                                  -- optional operator import
firewall_jobs (ip, action, block_until, status, attempts, notes)
notification_outbox (recipient, subject, body_html, body_text, status, attempts, sent_at)
program_settings / academy_cohorts / academy_progress / cooperative_partners
audit_events (actor, action, entity, entity_id, before_json, after_json)
sessions_meta (id, user_id, ip, user_agent, created_at, last_seen_at)  -- device list / revoke
```

Integrity rules that matter:

- One application per `(user_id, program_type, cycle_year)` unless the program allows repeats (`cooperative`).
- Education Bridge rows require `education_level != 'none'` and a non-empty `school_name` (CHECK constraint).
- `remittance_ledger` is append-only: no `UPDATE`/`DELETE` grant is documented, and triggers reject mutation.
- Passwords: Argon2id when available, bcrypt fallback, 12–72 byte input ceiling, rehash-on-login.
- Every write goes through CSRF + origin checks; every privileged read is scope-checked (a `csr_rep` only sees their own parish subtree).

---

## 6. Four-Stage Installer

`index.php` checks `config/installed.lock`; if absent it hands off to `install/index.php`, guarded by a filesystem mutex and a one-time installer session.

| Stage | Content | Guardrails |
|---|---|---|
| **1 — System validation** | PHP ≥ 8.1, extensions (`pdo`, `pdo_mysql`, `curl`, `gd`, `json`, `mbstring` advisory), writable `config/`+`storage/`, HTTPS check, system-token activation against the manager API (`sys_check_core()`, TLS-verified, 10 s timeout, cached) | Token never logged; failure messages never echo the token; a clear "use the local fixture" path exists for developers only. |
| **2 — Database** | Host/port/name/user/password test, empty-database assertion, schema + country seed + default settings executed once, connection file written `0600` | Refuses to run over an existing installation; no destructive retry (documented manual recovery). |
| **3 — Provincial administrator** | Name, e-mail, password (12+ bytes), transaction PIN, site title (drives the SVG favicon), notification e-mail | PIN policy enforced here, not merely in the UI; the first admin is `provincial_admin` and is exempt from self-suspension lockouts. |
| **4 — Complete & lock** | Writes `installed.lock` (0600), prints the post-install security checklist (HTTPS, 2FA at host, backups, cron, OPcache, private-dir denial), offers the sign-in link | Installer becomes unreachable; a re-run shows a "already installed" page with no data leakage. |

---

## 7. Security Engine (cPHulk / Imunify360-style)

### 7.1 Two independent, additive detectors

**Username detector.** Counts `failed_login` events per e-mail inside `account_window`. At `account_max` the account is suspended (`is_suspended = 1`) *if* username protection is on, the request is in scope (`local_only` vs local+remote), and the account is not a privileged account while `protect_privileged` is off. Suspension is logged, the user is notified, and **existing suspensions survive toggling protection off** (the draft asked for exactly this and it is easy to get wrong).

**IP detector.** Counts failures per IP inside `ip_window`; at `ip_max` the IP receives an application-level block (`security_ips.blocked_until`) and a `firewall_jobs` row is queued with the configured duration (**1 day / 1 week / 1 month / 1 year**). A login attempt for an e-mail that does not exist blocks the IP immediately when IP protection is on. Blocks are evaluated **before** password hashing so a blocked IP costs the server nothing.

### 7.2 Escalation ladder

| Strikes within window | Action |
|---|---|
| 1–(max−1) | 1 s→8 s progressive delay (server-side `usleep`, capped), neutral error text |
| max | Account suspended and/or IP blocked for the configured duration, admin e-mail alert |
| repeat after expiry | Next rung of the ladder is used (day → week → month → year, driven by `security_logs` history, never downgraded) |

### 7.3 Recognition (whitelisting) — an indicator, never a bypass

Each **distinct** clean session increments `security_ips.clean_sessions`. At **5** the IP is flagged `whitelisted` and shows a **crown icon with a green marking** in the logs. Recognised IPs still obey failure thresholds; they only (a) suppress the "new device" e-mail and (b) rank first in the admin log list. Admin/privileged logins from unrecognised IPs generate an alert e-mail.

### 7.4 Country policy

`country_rules` holds all 250 ISO codes (`whitelisted` / `not_specified` / `blacklisted`), seeded with Nigeria whitelisted. Resolution order: trusted-proxy `CF-IPCountry` → operator CIDR table → `unknown`. Blacklisted country → hard reject with a neutral page + security log; `unknown` → allowed in `not_specified` mode, denied only if the operator sets `country_unknown_policy = deny`. Every decision is logged with the reason so an operator can explain a false positive.

### 7.5 Other controls

CSRF tokens per session with `hash_equals`, `Origin`/`Referer` validation on all writes, session id regeneration on privilege change, 30-minute inactivity expiry, HttpOnly + SameSite=Lax + Secure cookies, per-session device list with revoke, PIN/administrative action throttling, generic authentication errors, constant-time-ish password verification for unknown accounts, HSTS/`frame-ancestors 'none'`/`X-Content-Type-Options`/referrer policy, CSP without `unsafe-inline`, upload MIME + size + randomised-name validation behind an authenticated download handler, and no secrets in `public/`.

---

## 8. Functional Modules

### 8.1 Program lifecycle (enforced server-side)

```
draft → submitted → under_review → (documents_requested → under_review)* → verified → approved → disbursed → completed
                                   └────────────────────────────────────→ rejected (reason required)
```

| Module | Intake fields | Lifecycle specifics |
|---|---|---|
| **A. Parish Connect** | Parish, YAYA CSR rep name/phone, youth count, needs assessment | Directory + needs tracker; not an application. Officers update their own parish only. |
| **B. RISE Academy** | Cohort, business idea, availability | 3-month tracker; completion of ≥ 80 % sessions is the graduation gate that unlocks Seed Grants. |
| **C. Seed Grants** | Business proposal, budget, Academy graduation | ₦100,000/month pool; applications ranked by a scored rubric, disbursed only from that month's remaining pool. |
| **D. Cooperative Access** | Target cooperative, savings capacity, loan interest | Repeatable; links to a `cooperative_partners` record; never moves money itself. |
| **E. Education Bridge** | `education_level` (super_teen SS1–SS3 / tertiary), school, fee schedule, result documents | 48–72 h verification SLA with an ageing badge; direct-to-school payment record; Super Teen vs Tertiary validation. |

### 8.2 Remittance & money movement

Requested amount is an *ask*, not a balance. Disbursement creates `remittances` (pending) → treasurer confirms with the transaction PIN → `remittance_ledger` append-only row → application moves to `disbursed`. The Seed Grant pool is enforced with a `SELECT … FOR UPDATE` on `program_settings` so two treasurers cannot over-disburse concurrently. Every figure in the UI is read from the ledger, never recomputed in the template.

### 8.3 Notifications

Transactional outbox → leased CLI worker (`bin/mail-worker.php`) → SMTP with retry/backoff, escaped HTML templates, plain-text alternative, no passwords or PINs ever e-mailed, unsubscribe honoured for non-transactional mail, retention pruning. Events: welcome credentials hand-off instructions, application received / status changed, disbursement recorded, administrator login from an unrecognised IP, brute-force alert with attempted account, suspension, profile-preference change.

---

## 9. Repository Layout, Environments & Operations

```
RISE63/
  index.php                 front controller (routes, installer hand-off)
  install/index.php         4-stage wizard
  api/index.php             JSON endpoints (parishes, search, pin-check)
  favicon.svg               dynamic SVG favicon
  manifest.webmanifest      PWA manifest
  sw.js                     service worker
  app/                      Core (Auth, Security, Installer, Mail, Security/Settings…), Repos, Views
  config/                   private config + installed.lock (deny-all .htaccess)
  database/                 schema.sql, countries.sql, seed.sql
  storage/                  sessions, documents, logs (private, deny-all)
  public/assets/            app.<hash>.css, app.<hash>.js, icons/
  bin/                      mail-worker.php, security-worker.php, maintenance.php
  tests/                    php tests (no DB required where possible) + verification harness
  docs/                     DEPLOYMENT.md, IMPLEMENTATION_STATUS.md, blueprint archive
```

Environments: **local** (`php -S` + development router, activation fixture), **staging** (real manager key, test database, mail sink), **production** (HTTPS, cron workers, backups, monitoring). Deployment, backup and rollback steps live in `docs/DEPLOYMENT.md`; the honest, test-by-test status lives in `docs/IMPLEMENTATION_STATUS.md`.

---

## 10. Acceptance Criteria (definition of done for v1.0)

1. Installer completes on PHP 8.2 + MySQL 8 and on MariaDB 10.6 without warnings; re-running it is inert.
2. A 360 px viewport has no horizontal scroll on any page; all controls ≥ 48 px; contrast passes AA in both themes.
3. Parish picker returns a match for any parish by partial name, area or zone in one request (< 200 ms locally).
4. Both detectors demonstrably suspend, block, escalate to the next rung, retain suspensions after toggling off, and auto-recognise an IP after five clean sessions.
5. A duplicate submit (offline replay / double tap) creates exactly one application.
6. Illegal status transitions return 409 and write no timeline row; every legal one writes exactly one.
7. Concurrency test: two simultaneous approvals of the final Seed Grant slot cannot over-disburse.
8. `php -l` clean on every file and the test harness green; no secrets, credentials or personal data in the repository or in `public/`.
9. PWA installs, runs offline with the shell cached, and shows the update toast on a version bump.
10. Every money figure in the UI traces to an append-only ledger row.
