# RISE 63 — implementation status

Updated: 2026-11-08 · version 1.2.0 · review this file before any launch decision.

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
| Syntax | `node tests/support/phpwasm-lint.mjs` | **80 files parsed cleanly** with `token_get_all(TOKEN_PARSE)` |
| Logic | `node tests/support/phpwasm-test.mjs` (`tests/unit.php`) | **68 passed, 0 failed** |
| Views | `node tests/support/phpwasm-render.mjs` (`tests/render.php`) | **44 passed, 0 failed** |
| Namespaces | `node tests/support/phpwasm-guard.mjs` (`bin/guard-namespaces.php`) | **25 functions, every call site resolves** |
| Requests | `node tests/support/phpwasm-smoke.mjs` | **38 passed, 0 failed** |
| **Database** | `node tests/support/phpwasm-e2e.mjs` (`tests/e2e.php`) | **30 passed, 0 failed, 1 skipped** — real SQL |
| **Pages** | `node tests/support/phpwasm-pages.mjs` (`tests/pages.php`) | **14 passed, 0 failed, 1 skipped** — real router, real SQL |
| Offline shell | `node tests/support/offline-behaviour.mjs` | **15 passed, 0 failed** |
| Parish combobox | `node tests/support/combobox-behaviour.mjs` | **31 passed, 0 failed** |
| Assets & cache | `node tests/support/assets-and-cache.mjs` | **11 passed, 0 failed** |
| Asset build | `node tests/support/phpwasm-build-assets.mjs` | fingerprint + manifest + shell list regenerated |

`npm test` runs all of the above in one command.

### The database suite is real, and it arrived one release late

Earlier releases said "the database tests need MySQL and cannot run here". That was true of
`tests/integration.php` and it stopped being true when PGlite was wired in: php-wasm can host an
in-memory PostgreSQL, so **`tests/e2e.php` runs the actual SQL** — the member lifecycle end to end,
the pool arithmetic, the audit filters and the keyset pager. `tests/support/pgsql-schema.php` is a
PostgreSQL rendering of the tables those tests need, kept in step with `database/schema.sql` by hand
and documented as such.

The one skipped test is `Fund::setPool()`, which persists through `Settings::set()` and therefore
uses MySQL's `INSERT … ON DUPLICATE KEY UPDATE`. That is a dialect gap, not an application fault, and
it is reported as SKIP with the reason rather than quietly passing. `tests/integration.php` covers it
on real MySQL.

**What this cost.** The 1.0.1 release shipped two faults that the 42 view tests and 65 logic tests
all passed straight over, because neither ever executed the SQL those pages depend on:
`Audit::page()` and `Members::directory()` called `new Paginator(...)` despite a private
constructor — a 500 on every load of Settings and People — and reused one named placeholder in three
positions, which native prepares reject. Both are now covered by a test that would have caught them
(`The pages that crashed in production build their paginator through the factory`), and by the e2e
suite that runs the queries for real.

`bin/build-assets.php` needs a PHP CLI, which this sandbox does not have; `phpwasm-build-assets.mjs`
runs the same file in php-wasm (with the CLI-SAPI guard removed from the staged copy only) and
copies the fingerprinted output back into `public/assets/`. Without that step the manifest points at
the previous build and the browser serves the old stylesheet from its immutable cache — which looks
exactly like "the CSS change did nothing".

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
* The stylesheet declares 16 px base type, a 48 px touch token, 16 px inputs, both themes, reduced-motion, print, tracker, combobox, offline banner and install prompt — and contains no type below 11 px (0.6875rem), the tab-bar label floor.
* The manifest is valid JSON with 192/512 any-purpose **and** maskable icons that exist on disk; the service worker versions its cache, deletes old caches, and refuses to cache `/api/` or non-GET requests.

### 2.1a What 1.0.3 fixes — a second 500, and the two checks that now catch it

A second 500 shipped in 1.0.2, and it was **not** the same fault as 1.0.1's. `app/pages.php` calls
`config_dir()` on the Updates route. `pages.php` sits in the **global** namespace; `config_dir()` is
declared in `RISE63`. A non-namespaced file has no current namespace to search, so PHP does not fall
back — it throws

```
Error: Call to undefined function config_dir()
```

and every request to `/updates` died as a 500. Settings and People had been fixed; Updates had not,
and nothing in the suite could see it: the linter parses, the logic tests never require the router,
the render harness feeds views their data directly, and the e2e suite tests the domain layer.

Two checks now close that gap, and both were **verified to fail before they were allowed to pass**:

| Check | What it does | Proof it bites |
|---|---|---|
| `bin/guard-namespaces.php` | Loads the real bootstrap, asks PHP which functions exist in `RISE63`, then tokenises every shipped PHP file and resolves each unqualified call against that set. No database, no network, runs first. | Re-removing `config_dir` from the `use function` line reports `app/pages.php:413 config_dir() [global namespace]`. |
| `tests/pages.php` | `require`s the **real** `app/pages.php`, once per route, against real SQL. Fails if a page throws, emits PHP error markup, leaks `SQLSTATE`, or renders without its own heading. Filter and pager parameters are varied so the SQL is actually exercised, and the audit `kind` filter is asserted to *narrow* — the trap that made a filtered page look filtered while returning everything. | With the import removed, `Updates renders for an administrator :: Call to undefined function config_dir()`. |

`tests/pages.php` also caught a genuine gap in `tests/support/pgsql-schema.php` while it was being
written: five tables (`security_ips`, `country_rules`, `manual_access_rules`, `geo_ip_ranges`,
`firewall_jobs`) were missing, so the harness now covers all seventeen.

Three administrator screens — Dashboard, Treasury and Security — still cannot be rendered under
PGlite: each builds a `UTC_TIMESTAMP() - INTERVAL n HOUR` expression, which is MySQL syntax that
PostgreSQL rejects. They are reported as SKIP with that reason, and `tests/render.php` renders all
three with a stubbed database. This is a dialect limit of the test engine, not a fault in the pages.

### 2.1b What 1.0.4 fixes — the People page, and the reason it was never going to work

The operator reported `/people` still returning the 500 page after 1.0.3. It was a different fault
again, and this one had been there since the page was written:

```
PDOException: SQLSTATE[42S03]: Unknown column 'parish_name_raw' in 'field list'
```

`parish_name_raw` is added to `users` by **migration 0002**. The People page is new and names that
column outright in the "churches members asked for" query. On an installation created by the
installer before 0002 existed, the column is not there — so the page had never once worked on that
server. It was reproduced by dropping exactly that one column from a working schema: `/people`
throws, and Settings, Activity, Updates, Applications and Parish Connect all stay up. One column,
one page, one cause.

Three things were wrong, and only one of them was the missing column:

| Fault | Why it mattered | Fix |
|---|---|---|
| A page of record-keeping died because of one optional card | The queue is a convenience. It must not be able to take the member tools down with it | The query is guarded; the card is replaced by a notice naming the missing column, and the rest of the page renders |
| Nothing told the operator a migration was waiting | `php bin/migrate.php` is CLI-only, the updater never runs migrations, and no screen mentioned them | **Settings → Database updates**: what is applied, what is waiting, and the exact command. Read-only, deliberately |
| The failure was invisible | `index.php` logs to the server error log, which is not reachable from a shared host's file manager | The log line now names the query that failed, and the page states its own cause |

**Migrations are still not applied from a web request, and that is a decision, not an omission.** A
schema change is the one operation that can leave a database half-modified; doing it behind a button
with no shell, no backup and no way to see the error is how an installation becomes unrecoverable.
The panel makes the state visible and puts the command in front of the person who needs it.

### 2.1c Mail settings, now in the admin UI

`config/smtp.php` was the one file an operator had to hand-write — in a specific PHP array shape,
over SSH or FTP — and the only feedback if they got it wrong was that password resets silently never
arrived. **Settings → Mail** replaces that.

| Concern | How it is handled |
|---|---|
| The password never reaches the browser | `Smtp::masked()` removes it before the view sees it; the field renders empty with "Saved — leave blank to keep it". A blank field on save keeps the stored value; removal is a separate explicit control |
| A typo is caught while the operator is looking at the field | 12 validations, each naming the field. The two that matter most: **STARTTLS on port 465** and **implicit TLS on 587** are refused with the reason, because both hang until the timeout and look like an outage |
| Proof it works without sending anything | *Test the connection* performs the identical handshake `Mailer` does, then disconnects |
| Proof that mail is *delivered* | *Send a test message to me* queues a real message — the only way to find out about SPF, DKIM and the spam folder |
| A credential on disk | Written atomically (temp file + rename) at **0600**; a corrupt or missing file degrades to "not configured" rather than throwing |
| What members see | `from_email` and `from_name` are configurable, and `Mailer::sender()` falls back to the old derivation, so a hand-written config keeps working |

The audit trail records `settings.smtp` with the transport (`smtp.gmail.com:587 TLS from no-reply@…`)
and never the password or the mailbox username.

Two checks guard the secrecy specifically, and both were verified to fail first: making
`masked()` return the password fails `The Mail panel renders and never echoes the stored password`
with *THE PASSWORD REACHED THE PAGE*, and the same happens if the template pre-fills the field.

### 2.1d What 1.0.5 fixes — the same missing column, one layer down, and the message that hid it

The operator reported: *"I tried to create a new administrator, but it failed with an error: The
record could not be saved. Please try again."*

That message is the hander's catch-all branch. The real error was:

```
SQLSTATE[42S03]: Unknown column 'parish_name_raw' in 'field list'
```

**The same column as 1.0.4, in a different statement.** 1.0.4 fixed the *read* on the People page.
`Members::create()` still names `parish_name_raw` in its **INSERT**, so on an installation without
migration 0002 every account creation failed — administrators, representatives and members alike.
Reproduced against real SQL by dropping that one column: the healthy path creates the account, the
degraded path throws exactly the operator's error.

| Fault | Why it mattered | Fix |
|---|---|---|
| A write named a column that may not exist | `INSERT`/`UPDATE users` named `parish_name_raw` unconditionally, so any account write needed a migration it might not have | The column is named only when there is a value for it. Creating without a parish, or with one from the directory, works on either schema. The same applies to `Members::update()`, `Registration::create()` and the profile save |
| The failure named no cause | `catch (PDOException)` collapsed everything into "Please try again", which is what an unapplied migration looked like for three releases | `42S22` (unknown column) and `42S02` (unknown table) now say: *"a database update is waiting — open Settings → Database updates and run `php bin/migrate.php`"* |
| The operator found out by failing | Nothing warned before the attempt | People now shows a **"Database updates are waiting"** banner above the forms, and applying an update reports pending migrations in the same flash |

**What still genuinely needs the column, and is allowed to fail.** A member who types a church that
is not in the directory has nowhere else to put it — the typed name *is* `parish_name_raw`. That
write still fails on an un-migrated database, and now fails with a message that says which command
fixes it. Pretending otherwise would be a silent data loss: the church name would simply vanish.

The regression test was verified to bite: restoring the unconditional `INSERT` fails with the
operator's exact SQLSTATE, and passes when the conditional write is restored.

### 2.1e What 1.0.6 fixes — the database now heals itself

Reported, with a screenshot of the shell:

```
$ php bin/migrate.php
! The schema_migrations table is missing — is this a RISE 63 database?
Refusing to migrate. Fix the inconsistency above first.
```

That is a deadlock, not a diagnosis. The runner refused to continue because it could not record
anything in a bookkeeping table that was not there — so the only command that could repair the
database was the one command it blocked. There was no way forward from the shell at all.

**The fix is structural, not another instruction.** The manual step is gone:

| Before | Now |
|---|---|
| `php bin/migrate.php` refused when `schema_migrations` was missing | `Migrator::ensureLedger()` creates it, with exactly the columns `schema.sql` declares |
| Every migration looked pending, so the existing ones were re-run and failed on a duplicate column | `Migrator::reconcile()` adopts a migration **only when information_schema proves its effect is already present**. Anything unprovable runs |
| An operator had to know the command existed, and to run it after every release | `SelfHeal::run()` does it on the first request after a release, before any page reads the schema |

Why "on the first request after a release" and not on every request: the check is stamped with
`VERSION` in `config/schema-stamp.php`, so a normal page load costs one row read and the schema work
happens once per version. A failed attempt is remembered for five minutes so a broken database is
not hammered.

What it deliberately does **not** do:

- **It does not decide anything.** Adoption is proved by reading `information_schema` — a table
  exists, a column exists. A statement shape the code does not understand is treated as *not*
  applied and runs normally. Guessing here would be a silent, permanent divergence.
- **It does not run inside a transaction.** MySQL commits DDL implicitly, so running it mid-way
  through somebody else's transaction would commit work they expected to roll back.
- **It is never fatal.** A failure is logged, stamped, and shown on Settings → Database updates. A
  schema problem must not be able to turn a page into a 500 — 1.0.1 through 1.0.5 were exactly that.
- **It still refuses a real inconsistency.** A migration file that changed after it was applied is
  a genuine divergence and only a person can decide what to do. That refusal now says so, with the
  remedy, instead of dead-ending.

The same healing runs from `php bin/migrate.php` — which now heals *before* it verifies, so the
command that failed in the screenshot works. `--status`, `--dry-run` and `--verify` still write
nothing, and say what the plain command will do.

**Two bugs in the test double were found by this work**, both of which had been hiding the real
behaviour: `tests/support/pgsql-schema.php` was missing `password_resets` and `schema_migrations`.
And the first version of the existence check used backtick-quoted identifiers, which PostgreSQL
rejects — the syntax error looked exactly like a missing column, which is why the check now asks
`information_schema` instead of selecting from an identifier.

### 2.1f What 1.0.7 fixes — SMTP, and the transport that was never the one asked for

Reported: **"SMTP rejected the request (no response)."**

Two bugs, and the message named neither of them.

**The transport was a typo.** `Mailer::connect()` chose its socket like this:

```php
$transport = ($encryption === 'ssl' || $encryption === 'none') ? 'tcp' : 'tcp';
```

Both branches are `'tcp'`. So choosing **Implicit TLS** — port 465, the second option in the
Settings dropdown — opened a *plain* socket to a server that begins its TLS handshake the instant it
accepts. The server waited for a ClientHello, the client waited for a `220` banner, and neither sent
anything. That is the "no response".

The fix is one mapping, `Mailer::transportFor()`, now public and unit-tested:

| Setting | Transport | Why |
|---|---|---|
| Implicit TLS | `ssl://` | PHP performs the handshake during `stream_socket_client()` |
| STARTTLS | `tcp://` | plain socket, upgraded later with `stream_socket_enable_crypto()` |
| None (localhost only) | `tcp://` | nothing to negotiate |

**The message conflated two opposite failures.** `expect()` threw
`SMTP rejected the request (no response)` — asserting a rejection while reporting that no response
arrived. Those are different problems with different fixes:

- **No reply at all** → a transport problem: a blocked port, the wrong encryption for the port, or a
  firewall dropping SMTP. The message now says that, names the endpoint it tried, and says plainly
  that it is *not* a password problem.
- **A reply with an unexpected code** → the server refused, and the message quotes what it actually
  said and names the setting: `535` points at app passwords, `550` at the "send from" address.

`connectError()` covers the connection failures, including the specific case that produces this
symptom most often — port 465 with STARTTLS selected.

**Three supporting changes, so the next report is not a guess:**

1. **`bin/doctor.php` performs the real handshake** (connect, EHLO, STARTTLS, AUTH, QUIT — sends
   nothing). It used to stop at "a host is configured", which is how a broken transport looked
   healthy on every screen while every message queued and failed. `--no-net` skips it for cron.
2. **The Settings panel checks for the openssl extension.** Without it every TLS mode fails with a
   message about a missing socket transport, which reads like an application bug rather than a PHP
   build. Saving a setting is now also refused on port 25 with STARTTLS, which is blocked nearly
   everywhere.
3. **`connect()` no longer leaks its socket** when a handshake step fails. `bin/mail-worker.php`
   exits on that error anyway, but Settings → "Test the connection" catches it and carries on.

Deliberately **not** done: `Smtp::save()` does not check whether this PHP can do TLS. Validation
should judge the request, not the machine — a check that depends on the host makes the same form
save or refuse depending on where it runs, and it blocked the unit suite (which has no TLS) the
first time it was tried. Capability belongs in `test()`, which reports it where the operator is
looking.

### 2.1g What 1.0.8 fixes — shared links showed raw markup

Reported: pasting the site link into WhatsApp produced the HTML document as text, not a preview.

**Cause.** Every page was served with **no `Content-Type` of its own**, so it inherited PHP's
`default_mimetype`. On the deployment host that value is `text/plain`. A browser guesses its way to
a usable page when the type is wrong, which is why nobody ever saw a broken site — but a
link-preview scraper does not guess, so the whole document rendered as text in the chat window.

The fix is one declaration, in `Security::sendHtmlHeaders()`, called by `sendHeaders()` — which
every HTML entry point already went through:

```php
header('Content-Type: text/html; charset=utf-8');
```

This is the right fix regardless of the host's ini. A page that only works when a server default
happens to be correct is a page that is broken on the next host.

**Second cause, found while fixing the first: there was nothing to preview.** No `og:` tag existed
anywhere in the application. Even a correctly-typed page would have produced a bare title and a
paragraph of body text. Added `RISE63\Core\Share` and `app/Views/partials/share.php`:

| Tag | Value | Why |
|---|---|---|
| `og:title`, `og:description` | page title, tagline (≤200 chars) | the two lines a card shows |
| `og:url`, `rel=canonical` | absolute URL of this page | a relative URL resolves against the scraper's host |
| `og:image` + width/height | `public/icons/share.jpg`, 1200×630 | the card; declared size reserves space before load |
| `twitter:card` etc. | `summary_large_image` | X does not read Open Graph reliably |
| `robots: noindex` | on every signed-in and transactional page | a card for `/treasury` would show figures to strangers |

The card image is **JPEG, 48 KB**. The first version was a palette PNG at 477 KB — a scraper on a
slow connection gives up long before that, and the declared `1200×630` in the tags did not match the
`1424×752` the generator actually produced.

**Only pages reachable without signing in get a preview card.** A scraper carries no session, so any
URL behind the login would render the sign-in page and cache *that* as the image.

## 2.1h Security review (1.0.8)

An audit of the codebase, not a change of posture — the findings were that posture is already sound.
Recorded so the next review starts from facts rather than starting over.

**SQL injection: none.** A static pass over all 85 PHP files for SQL built by interpolation found
four sites, all provably closed:

| Site | Value interpolated | Why it is safe |
|---|---|---|
| `Auth::pinLockedFor()` | `Settings::int('pin_lock_minutes')` | returns `int` |
| `BruteForce::failures()` | column name | whitelist `['ip_address','username_attempted']` |
| `BruteForce::failures()` | `max(1, int)` minutes | `int` |
| `ParishDirectory::search()` | `max(1, min(200, int))` limit | `int`, clamped |

Everything else parameterises. `Database` uses `ATTR_EMULATE_PREPARES => false`, so parameters are
bound by the server rather than escaped client-side.

**Also checked and found correct:** CSRF (one `assertPost()` on every POST in the front controller,
`hash_equals`, plus a same-origin check); XSS (every dynamic `<?=` goes through `e()`, which uses
`ENT_QUOTES | ENT_SUBSTITUTE`; the ~98 unescaped expressions are literal ternaries, `(int)` casts or
closed-set CSS class names); session hardening (strict mode, `HttpOnly`, `SameSite=Lax`,
`Secure` when HTTPS, 48-char ids); password reset (32 random bytes, only the SHA-256 stored,
single-use, expiring); uploads (extension whitelist *plus* MIME sniffing, random stored name, 0640,
private directory); document download (pattern-validated name, `realpath` containment, ownership
check); open redirect (targets stripped to `[a-z0-9-]`); zip-slip (segments rejected, absolute paths
rejected); SSRF (release downloads pinned to the licence host and scheme); headers (CSP without
`unsafe-inline`, `frame-ancestors 'none'`, `nosniff`, HSTS on HTTPS).

One genuine latent issue, fixed: `View::render()` concatenated its template name into a path with no
containment check. No caller passes request input today — the router derives page names through
`preg_replace('/[^a-z0-9-]/','')` — but a future caller could. It now resolves the real path and
refuses anything outside `app/Views`.

### 2.1k What 1.2.0 adds — the public website and its CMS

The application had no public face: an anonymous visitor was redirected to the sign-in form from
every URL except `/login` and `/register`. This release adds a landing page, five content pages and a
contact route, all editable from Settings without a release.

**Public site.** `site/layout.php` is a separate document from the application shell — its own
stylesheet, no tab bar, no install prompt, no theme toggle, and it is always light. Six routes are
public: the landing page (`/`), About Us, Vision, Privacy Policy, Terms & Conditions, Contact, and any
page an administrator publishes later. Unknown anonymous URLs now render the site's own 404 instead
of redirecting to a sign-in form, which used to tell a stranger they needed an account to read a
public website.

**Hero slider.** Up to eight slides, each with a headline, kicker, summary, supporting line,
photograph, described alternative text, an optional call to action and a light/dark text treatment.
The carousel advances on a configurable interval (0 disables), pauses on hover and on focus, stops for
good once the visitor touches a control, does not advance in a hidden tab, and does not autoplay at
all under `prefers-reduced-motion`. Arrow keys, Home and End work, it is swipeable with a dead zone so
a vertical scroll is not read as a swipe, and the pips are plain buttons with `aria-current` rather
than a `tablist` the page does not implement.

**CMS.** Settings → Website lists pages and slides as rows with Edit, View, Move and Delete. Bodies
are plain textareas with four pieces of markup (`##`, `###`, `-`, `>`) explained inline; there is no
rich-text editor, because a WYSIWYG body is a stored-XSS surface. Images are validated by
`getimagesize()`, stored under a random name in `storage/site/`, and streamed by `app/site-image.php`
with a fixed content type and `nosniff` — never by the attachment path. The four footer pages cannot
be deleted or have their slug changed, because the footer links to them on every page.

**Defects found and fixed while testing this release:**

* `View::capture()` hardcoded `app/Views/layout.php` and ignored the shell a caller asked for, so every
  public page rendered as a bare fragment with no `<head>`. It now resolves the named shell and
  refuses an unknown one.
* The landing page emitted four `<h1>` elements, one per slide. It now declares one, with the other
  slides at `<h2>`.
* The carousel marked itself up as a `tablist` with `tab` children and no tabpanels.
* `parish-connect` was linked from the contact page and from the closing slide's call to action, but
  no migration seeded a page behind it — a dead end for the one audience the site is for. Migration
  0005 now seeds it with real copy.
* The hero image sat inside an `aria-hidden` wrapper while carrying meaningful `alt` text, so the
  description was hidden from the visitors it exists for.

**Verified here:** 292 assertions pass (`unit 77 · render 50 · smoke 38 · e2e 33+1 skipped ·
pages 22+1 skipped · offline 15 · combobox 46 · cache 11`), the function guard is clean, and the CMS
lifecycle — create, edit, unpublish, delete, reorder — is exercised against a real SQL engine.

**Not verified: the design has never been seen in a browser.** This sandbox has no PHP binary, no
MySQL, no live host and no browser (Playwright's Chromium download is blocked), so layout, spacing,
type scale and colour contrast were checked only by static analysis: a template-class-versus-
stylesheet audit, and CSS assertions for the hero's fixed height, the grid collapses, the sticky
contents list and the reduced-motion rule. It needs a look on a real screen before launch, particularly
on a phone.

### 2.1j What 1.1.0 fixes — zones and areas are churches too

Reported: *"I already added zones and areas, but parishes are yet to be added. The registration page
and the church directory should be able to list the already added zones and Area churches because
people worship in the zonal and area churches ... As of now, the directory is empty despite having
multiple churches added via the bulk already."*

The install held 16 zones and 26 areas and no parishes. Both screens counted `parishes` rows only,
so 42 churches that existed were invisible, the directory read "No parishes match that search", and
the registration picker offered nothing.

What changed:

* **A church may be a parish, an area church or a zonal church.** `users.zone_id` and `users.area_id`
  are new nullable columns (migration 0004); exactly one of the three is set, and the registration
  form, the member's own account page and the admin edit form all post a level beside the id.
  Deliberately *not* done: synthesising a parish row per zone or area, which would have invented
  churches nobody chose and duplicated the hierarchy.
* **The picker offers all three.** Labels say which level a church is (`GRACE ZONE · ZONE CHURCH`),
  and the hidden field that carries the id is chosen by level, so a zone id is never posted as a
  parish id.
* **The directory lists all three**, with a badge per row, and the counters read *Churches members can
  choose · Parish churches · Area churches · Zonal churches* instead of "0 Parishes" beside 26 areas.
* **Member rows, the admin edit form and My account show the church at whatever level it is**, rather
  than a blank where `parish_name` was null.
* **The import preview warns** when a line creates an area with no church after it. Two-column lines
  remain valid — they are how a hierarchy is entered before its parishes — but that silence is how
  this install came to have 42 places and no churches.
* `pickerState()` has two states, not four: `ok` and `empty`. "All switched off" and "orphaned" can no
  longer occur once a zone is itself a church, and dead states are dead code.

Migration 0004 is additive and runs itself on the next page load (1.0.6 behaviour); nothing is
required from the operator.

### 2.1i What 1.0.9 fixes — the registration page listed no churches

Reported: the registration page showed no churches. Searching for one answered **"No parish matches
'Sanctuary'."**

**The page was not broken. The directory was empty.** Nothing was added to it on that installation,
and the code had no way to say so. That is the real defect: four different situations — never added,
all switched off, rows orphaned from their area, or working — were indistinguishable from the
outside, and the one message the member did see ("No parish matches") actively pointed at the wrong
cause.

What changed, and why each part matters:

| Symptom | Before | Now |
|---|---|---|
| Nothing in the directory | Seach box matched nothing; member concluded the search was broken | The search box is replaced by a required church-name field and a notice saying the directory is empty |
| Member cannot pick a church | No field on the rendered page at that moment | The typed name is submitted, kept as `parish_name_raw`, and listed on **People & roles** for the office to add |
| Administrator cannot see the problem | An empty directory looked normal: counters read 0 | A notice at the top of **Parish Connect** states the state and the cure, next to the bulk-import box that fixes it |
| Diagnosing it needs a browser | — | `bin/doctor.php` prints `Parish directory: …`, and `GET /api/index.php` returns `directory: "empty" | "inactive" | "orphaned" | "ok"` |

`ParishDirectory::pickerState()` is the single source for that answer. It distinguishes the states
by query, not by guessing:

- **empty** — no rows in `parishes`.
- **inactive** — rows exist, none has `is_active = 1`.
- **orphaned** — rows exist but their `area` (or the area's `zone`) does not, so the picker's inner
  joins drop them. This is the only state that is genuine corruption, and re-importing repairs it.
- **ok** — at least one parish is reachable.

**Three smaller defects found while fixing it:**

1. The registration view read `$choosable` *before* the assignment that defines it, so a naive
   empty-state branch would have fired on every load, populated directory or not. The assignment now
   precedes its first use.
2. The `<noscript>` fallback contained a second `name="parish_name_raw"` input. With the picker
   present that was harmless, but in the empty-directory state it would have submitted two values
   for one field and the last would win. It is now rendered only alongside the picker.
3. The live search had no protection against a stale response: `AbortController` is not available
   everywhere, and without it a slow early request could repaint over a correct later one. Each
   request now carries a sequence number and a superseded answer is discarded.

**The tests were making the same mistake as the page.** Three registration tests in
`tests/render.php` rendered the view with no `parishes` key at all — which is now precisely the
empty-directory state — and asserted that the picker's markup was present. They passed because the
markup was unconditional; they would have kept passing if the picker had vanished entirely. They now
pass a populated fixture and assert the empty state separately.

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

### 2.3a Field report two — the wizard rendered as unstyled HTML

The second deployment showed the setup wizard as bare HTML: no layout, no colours, default
browser form controls. Every PHP file was valid; the page simply had no stylesheet.

| Defect | Why it happened |
|---|---|
| `base_path()` used `$_SERVER['SCRIPT_NAME']` | `install/index.php` and `api/index.php` are **included by** `index.php`, so SCRIPT_NAME points at `/install/index.php`. Every `url()`/`asset()` call therefore produced `/install/public/assets/app.css`, which does not exist (404) |
| The installer had no layout of its own | It borrowed `.auth-shell`, which is a narrow single-column centred card meant for a login form — wrong for a four-step wizard with a checklist and two-column fields |

`base_path()` now strips the `/install`, `/api` and `/bin` suffixes, with `RISE63_BASE_PATH` as an
explicit escape hatch. The wizard got its own `.wizard-*` component: a header with brand and version,
a four-step progress rail that marks done/current, a checklist using tick/cross icons instead of raw
`<ul>`, a two-column field grid that collapses on small screens, and a completion screen.

**Screenshots cannot be produced in this sandbox** (no browser; the Chromium download is blocked), so
`node tests/support/phpwasm-previews.mjs` renders each stage to `docs/preview/*.html` with the real
`app.css` inlined — open those files in any browser to see exactly what a host renders. That is also
why the smoke suite now asserts the stylesheet URL, the absence of `/install/public/`, that the CSS
link is in `<head>` before `<body>`, and that the wizard shell markup is present.

### 2.3b Field report three — "The licence is not confirmed for this domain"

The third deployment reached stage 3 with a key the operator had just been issued, and the form
refused to advance: *"The licence is not confirmed for this domain: Invalid license key or domain."*
The provider answers that exact sentence for **every** mismatch, so the message could not tell the
operator whether the key was wrong, the domain was spelled differently, or the provider had simply
been asked too often.

| Defect | Why it happened |
|---|---|
| Stage 3 forced a **second** network check | `Installer::createAdministrator()` called `check($host, true)`. `true` means *ignore the cache*, so a key confirmed seconds earlier at stage 1 was re-submitted; a rate-limited or briefly failing provider then refused it and setup could not be finished at all |
| The domain was read-only | `install/index.php` showed **Domain to license** as `readonly`. If the key had been ordered for `example.org` and the site is served from `buildroom.example.org`, there was no way to say so — only a shell on the server |
| The failure message named no domain | It repeated the provider's sentence verbatim, which is the same for a bad key, a bad domain and a suspended licence |

Now:

- **The domain is checked once.** `verify()` records every domain the provider accepted alongside
  the key (`domains` in `config/activation-key.php`, capped at five, written *only* on `status: 1`).
  Stage 3 compares against that record instead of calling the provider. It still requires a stored
  key, and it still calls the provider once if this host was never recorded — so a key revoked
  before setup finishes cannot slip an administrator account into the database, and a first-run
  install where the operator skipped stage 1's check is not locked out.
- **The domain is editable** on stage 1 and stage 3, and what is typed there is the string sent to
  the provider. A replacement key at stage 3 is probed against the typed domain before it is saved.
- **`bin/license-check.php --probe`** tries the plausible spellings (bare domain, `www.`, parent
  domain, this server's hostname when it is a real domain), prints the provider's answer for each,
  and with `--save` records the accepted one so the wizard can be skipped.
- **An unreachable provider is no longer reported as a refusal.** `verify()` now marks transport
  failures (`unreachable`), and the checker's three outcomes are kept apart: accepted (exit 0),
  refused by a provider that answered (exit 1), and *nothing was decided* (exit 2, with the DNS
  result and a `curl` command). Before this, a firewall blocking outbound HTTPS produced the same
  three-word verdict as a suspended key — sending the operator to buy a licence they already owned.
- Resuming setup at stage 1 no longer forces a fresh call either: if the stored key is already
  accepted for this domain, it advances without asking.
- Refusals now name the exact string that was sent.
- **`bin/doctor.php` exists.** The stage-4 checklist had told operators to run a file that was never
  written. It is a read-only self-check — PHP build and extensions, writable folders, the
  `.htaccess` guards on `config/` and `storage/`, key-file mode, SMTP host, table and administrator
  presence, ledger triggers, security e-mail, licence — and it exits 0 only when nothing blocks
  launch. It refuses to guess: a CLI run cannot see whether HTTPS is on, so it prints a note
  instead of claiming the site is insecure.

**Not verified here:** the provider is unreachable from this sandbox, so the *pairs* this code will
send are covered by tests against the documented contract (`tests/unit.php`), not by a live call.
The live confirmation belongs on the host: `php bin/license-check.php --probe`.

### 2.3c Field report four — the licence is valid in the console but rejected on the site

The operator's console showed `MAN-749B9B757500` as **active** for `buildroom.rccglp63yaya.org.ng`,
while the site reported *"Invalid license key or domain."* Both statements were true, because the
two screens were talking to different scripts:

| Endpoint | What it does |
|---|---|
| `api.php` (documented in the API reference) | Validates a key. Registers an unknown domain automatically while the licence has spare slots. |
| `check-update.php` (updates only) | Validates the key **and** requires the domain to be already listed; refuses when the limit is reached. |

`check-update.php` never returns "valid", so it can never be the thing that proves a licence — a
`403` from it was being read as a licence refusal. The site's own check uses `api.php` and is
unchanged. The distinction is now documented in `docs/DEPLOYMENT.md` §3b/§3c so the two are not
confused again.

The gate's **Try again** button also did nothing useful: it reloaded the page, which re-read the
same cached failure, so an operator who had just fixed their key or their host's firewall saw no
change. It is now a CSRF-checked POST that forces a fresh provider call.

### 2.3d Field report five — a valid-looking key that the provider does not have

The installation completed, the console showed an active licence, and the site still refused. The
screen showed only the provider's sentence, *"Invalid license key or domain."*, which covers four
unrelated faults, so it pointed at the wrong one.

**What was actually wrong.** Probing the provider directly with the key on file settled it in one
request:

```
GET https://manager.pmhserver.name.ng/check-update.php
        ?license_key=MAN-749B9B757500&domain=buildroom.rccglp63yaya.org.ng
 -> {"status":"error","message":"Invalid license key."}
```

`Invalid license key.` — *not* "…or domain" — comes from `LM/check-update.php` line 109, the branch
taken when the `SELECT … WHERE license_key = ?` finds **no row**. The key is not in the provider's
database, so no domain spelling could ever have worked. The same probe against a known-good licence
returned `{"status":"success","latest_version":"7.57",…}`, which proves the method rather than the
conclusion.

The key on file came from the *console's* row for this domain. Either it was edited in the console
after it was issued, or the domain row and the key row disagree. That is a provider-side fact this
application cannot repair, and it must not pretend otherwise.

**What changed here.** A refusal now reports the three things that identify it, instead of a
sentence that fits four faults:

- the masked key that was **actually sent** (`MAN-••••••••7500`), which is what exposes a wrong or
  stale key;
- the domain that was sent;
- the provider's literal answer.

`bin/license-check.php` additionally asks `check-update.php` on the failure path — the one endpoint
that can tell *unknown key* from *domain not on the licence* — and gives a single instruction based
on the answer. The gate points at that command and lists the five checks in the order that
eliminates them.

Also in this change: the updater now understands the **deployed** release endpoint rather than only
the written contract. That endpoint adds an `access` block, reports `update_required`, sends
`changelog_format`, and — importantly — sends `status: "success"` even when `access.allowed` is
false. Reading only `status` would have offered an update for a licence that is on hold.

### 2.3e Field report six — the real cause: a nested array on the wire

Systematic probing finally separated the two endpoints:

| Probe | Answer |
|---|---|
| `check-update.php` with `MAN-8F91CCC3CC57` + this domain | `status: "success"`, version 7.58, `access.allowed: true` |
| `api.php` with the same key and domain, from the installation | `{"status":0,"message":"Invalid license key or domain."}` |

`check-update.php` *strictly* requires a `licensed_domains` row and passed, so the key and the
domain were both genuinely correct. The key was never the problem.

**The fault was in our client.** `check()` built its request like this:

```php
$key = $this->key();                     // ['key' => 'MAN-8F91CCC3CC57']  ← a metadata row
$this->post(['key' => $key, ...]);       // http_build_query() nests it
```

which puts `key%5Bkey%5D=MAN-8F91CCC3CC57` on the wire. PHP parses that into
`$_POST['key'] = ['key' => '…']` — an **array**. `api.php` binds it to `WHERE license_key = ?`,
matches nothing, and takes the `!$license` branch, which answers exactly `Invalid license key or
domain.` So every valid key was rejected, on every installation, with a message that blamed the
operator. `KINGDOMBUILDERS` passes a string and never had the fault; only RISE63 wrapped it.

**Fix.** `key()` now returns the string, and `Activation::encode()` refuses any non-string field
outright — a nested value becomes a loud "this is a bug in the activation client, not a key
problem" instead of a silent mismatch. The regression test asserts the wire body byte for byte
(`key=MAN-8F91CCC3CC57&domain=…`) and proves the old body parses back as an array.

The two earlier reports were the same symptom seen from different angles: the request was never
well-formed, so no key could match, and the provider's one-size-fits-all message sent us looking at
the key, then the domain, before the body itself.

### 2.3f Field report seven — "Not Found" on every page but the home page

Activation cleared, then `/login` returned Apache's `Not Found`.

**Cause.** The application routes on `$_GET['page']`, and every link and redirect it emits is a
path segment (`url('login')` → `/login`). `.htaccess` had no rule mapping a path segment to
`index.php`. Nothing failed for the site root, because `DirectoryIndex index.php` serves it —
which is exactly why the symptom read as "the site is up, the links are broken".

So clean URLs had never worked on Apache. The development router (`router.php`) sent everything to
`index.php` but *without* translating the path into `?page=`, and the harnesses all passed
`?page=…` directly, so the gap was invisible to every test.

**Fix, in three layers.**

1. `.htaccess` gains the missing front-controller rule, guarded by `!-f` and `!-d` so real files
   (`public/`, `api/`, `install/`, assets) are never rewritten, and with no `RewriteBase` so a
   subdirectory install keeps working.
2. `page_from_path()` derives the page from `REQUEST_URI` when `?page=` is absent, after stripping
   `base_path()`. A host with `AllowOverride` off therefore still serves `/login` correctly — the
   rewrite becomes an optimisation, not a requirement.
3. `router.php` (development) now does the same translation, so `php -S` behaves like the host.

Two latent bugs surfaced while testing this, both pre-existing: `index.php` used `base_path()` and
`app/pages.php` used `site_title()` without importing them into the namespace — either would have
been a fatal error on a fresh install. `base_path()` also cached its answer for the whole process
rather than per script path, so one CLI run could not look at two installations.

**Tests.** `page_from_path()` is unit-tested against roots, subdirectory installs, `index.php`,
`/install/`, `/api/`, nested pages and encoded traversal; the smoke harness asserts the rewrite rule
exists, that it maps a bare segment, that it leaves asset paths alone, and that it is guarded by the
`!-f`/`!-d` conditions — and that assertion was verified to fail when the rule is deleted.

### 2.3g The tab bar and the offline notice

**Tab bar.** It was seven text glyphs (▤ ▦ ⌂ ₦ ◈ ⚙ ⏻) in a flat strip. Text glyphs are the wrong
material for a tab bar: they have different widths and baseline heights, and several render as a
different picture — or a missing box — depending on the device's fonts. Rebuilt as a phone-style
bar:

- **drawn SVG icons** from `icon()` in `Functions.php`, a fixed allow-list so an icon name is never
  interpolated into markup, all on the same 24px grid and stroke weight;
- a **floating translucent slab** with a hairline border and a layered shadow, instead of a
  full-width strip welded to the bottom edge;
- a **sliding pill** under the active tab, driven by `--active-index` and `--tab-count` so adding a
  tab needs no CSS change, and hidden entirely when no tab is current;
- **safe-area inset** honoured, labels floored at 11px, marks shrinking rather than targets below
  360px, and `prefers-reduced-motion` respected.

One real defect fell out of this: a `min-width: 720px` rule set `.bottom-nav` to `position: static`,
so on any desktop the bar became a block under the page content. That rule was written for the flat
strip; with a floating bar it would have looked broken on every desktop. Removed, and a test now
asserts the rule cannot come back.

**Offline notice.** The old version toggled `navigator.onLine` and nothing else, and had no way to
close it. `navigator.onLine` is a hint, not an answer: it reports "connected" to a router with no
internet. Now:

| Requirement | How |
|---|---|
| Automatic | `offline`/`online` events plus a 5s same-origin `HEAD` probe; no user action needed |
| Only while actually offline | `onLine === false` is trusted as a negative; "online" is confirmed by the probe |
| Removed with no perceptible delay | cleared in the `online` handler itself, before any probe resolves |
| Manual close | a × button sets a flag that survives repeated probes and clears on the next recovery |

Probing stops once the connection is confirmed and resumes on `offline`, so a page left open on a
phone does not make a request every five seconds indefinitely.

**Verified** with `tests/support/offline-behaviour.mjs`, which loads the real `app.js` in a stubbed
browser and asserts all four behaviours — and was checked to fail when the `online` handler or the
dismiss handler is deliberately broken.

### 2.3h The close button that did nothing

Reported: the new offline bar appeared with the new copy, the × was visible, and pressing it had no
effect. The tab-bar redesign in the same release was confirmed working — so the release had shipped.

Three faults, all of them invisible from the page:

1. **A stale script.** The HTML is served fresh (`no-store` for signed-in pages), but
   `/public/assets/app.js` was cached un-fingerprinted, and `SHELL_VERSION` in `sw.js` was a literal
   (`'rise63-v2'`) that nobody bumped. The browser therefore paired the **new markup** with the
   **previous release's javascript**. The button existed; nothing was listening to it. There is no
   error to see — the old script runs fine and knows nothing about the new element.
2. **An inline script blocked by our own CSP.** `script-src 'self'` has no `'unsafe-inline'`, so the
   pre-paint theme script in `layout.php` was silently dropped. That is why the theme flashed on
   every load for a signed-in user. A blocked script and a script that ran are indistinguishable
   from the outside, which is the same trap as (1).
3. **A single point of failure.** The binder lived only in `app.js`, so any cache problem removed the
   behaviour entirely.

**Fixes.**

- `bin/build-assets.php` fingerprints every asset (`app.f6d1df85.js`), so a browser cannot reuse an
  older copy; the deploy step is documented as mandatory and why.
- `sw.js` derives its cache name from the shell list and a generated release id. A deploy cannot
  leave the old cache in place, because the name changes without anyone remembering to change it.
- The offline close button ships its **own** handler in the same HTML response, so it works even
  beside a stale `app.js`. `app.js` keeps its richer handler (which also re-tests the connection).
- `Security::INLINE_SCRIPT_HASHES` allow-lists the two inline snippets by SHA-256, so the policy
  stays strict (no `'unsafe-inline'`, no nonce bookkeeping) while those scripts execute.

**Tests.** `tests/render.php` hashes every inline script in the *rendered* page — not the template,
because a PHP expression inside a `<script>` emits different bytes than the source — and requires a
matching entry in the allow-list, with no orphans. `tests/support/assets-and-cache.mjs` proves the
cache name changes with both the release and the shell list, that every pre-cached file exists, and
that one changed byte yields a different asset URL.

### 2.3i The notice that would not close, and a footer that never arrived

Two reports, one root cause, plus a shape problem.

**"The top bar notice is stubborn, it won't close."** A top bar was the wrong shape for this
message: it covered the header (hiding the brand and the theme control at the moment the user was
looking for a way out), and a connectivity notice is the one thing on a page you cannot navigate
away from — so being unable to close it is worse than never showing it. It is now a **modal bottom
sheet**: rises from the bottom on a phone, centres as a dialog on a desktop, dismissible three ways
(Dismiss, backdrop, Escape), and closing sticks until the connection actually drops again. While it
is open the page behind cannot scroll.

Two real bugs surfaced while building it:

- `var dismiss` in the new code collided with the install prompt's `var dismiss` in the same
  function scope. `var` is function-scoped, so the later assignment replaced the modal's helper
  with a DOM element, and every close path threw. The behaviour harness caught it on the backdrop
  case — the one path the inline fallback does not cover.
- The inline handler is a *second* implementation, so it can go stale independently. The CSP hash
  test blocks that: change the snippet without updating `Security::INLINE_SCRIPT_HASHES` and the
  build fails.

**"The footer-menu redesign was never applied."** It was: the CSS was in the tree and every test
asserted on it. What the browser received was the *previous* stylesheet, because
`public/assets/manifest.php` — written by the manual `bin/build-assets.php` step — did not exist on
the deployed host. `asset()` then returned the bare `app.css`, which is cacheable, so the new HTML
was paired with the old CSS. The symptom is indistinguishable from "the change was never made".

`asset()` no longer depends on the build having run: with no manifest entry it hashes the file and
returns `app.css?v=<hash>`, so the browser-side cache is correct regardless. The build still writes
fingerprinted filenames (`app.f6d1df85.js`), which is the faster path; the fallback is the safety
net. Both are tested.

**Sticky.** The bar was already `position: fixed`, but the request kept coming back, so it is now
asserted rather than assumed: one tab bar per page (not a second copy in the footer), pinned with
`position: fixed` at every width, never `static` or `relative`, sitting above the home-indicator
inset, with the page reserving room beneath it. Three of those assertions would have failed against
the previous stylesheet.

### 2.3j The directory: one list, three columns, all capitals

**The request.** Adding zones, areas and parishes went one record at a time, through separate
forms. The province already maintains that hierarchy in a spreadsheet, so the task was to accept it
in the shape it already exists: *zone, area, parish*, one church per line, in a paragraph box.

**Why a paragraph box is the right input here.** The hierarchy is a tree, and a tree is awkward to
enter row by row: to add four parishes to one area you open the area form four times. Written as
lines, the same thing is four sentences that all start the same way, and the repetition *is* the
structure:

```
GRACE ZONE, HOPE AREA, LIVING WATER PARISH
GRACE ZONE, HOPE AREA
GRACE ZONE, FAITH AREA, GOOD SHEPHERD PARISH
```

The second line is the part that matters most: an area whose parishes are not known yet. It creates
the area and stops, which is exactly what "add the parish later" means in practice.

**What the parser accepts** (`app/Core/DirectoryImport.php`):

| Line | Result |
|---|---|
| `ZONE, AREA, PARISH` | creates whatever is missing |
| `ZONE, AREA` | creates the area, no parish |
| `ZONE` | creates the zone only |
| repeated zone or area | matched and reused — repeats are how children are grouped |
| blank line, `#` line | ignored (an exported sheet header can stay) |
| four columns, or a 400-character line | reported by line number, never guessed at |

Commas, tabs, semicolons and pipes all separate columns, because a paste from Excel is tab-separated
and a paste from a PDF is not. The columns are trimmed, single-spaced and upper-cased on the way in.

**Preview before write.** Two buttons, not one. "Preview changes" parses and counts — *new zones,
new areas, new parishes, already present, duplicates* — and lists the rows it would create. Nothing
is written. "Add to directory" then writes. This exists because the thing that goes wrong with a
200-line paste is not a crash, it is a typo in a zone name that quietly creates a fourth zone; a
count is checkable against the sheet, "imported" is not. The pasted text is parked in the session
and comes back into the box after the redirect, so the second button can be pressed without pasting
again, and it works with JavaScript disabled.

**Idempotent by name, not by id.** Every step looks the zone up (`UPPER(zone_name) = ?`), then the
area within that zone, then the parish within that area, before creating anything. So a re-paste
creates nothing, a paste in mixed case creates nothing, and the unique key
`(zone_id, area_name)` / `(area_id, parish_name)` is never reached as an error the operator has to
interpret. The whole run is one transaction: a paste that fails halfway would otherwise leave three
orphan areas and no way to tell which lines were applied.

**All capitals, everywhere.** `RISE63\caps()` — collapse whitespace, upper-case, trim — is applied in
one place and used by every path: the paragraph box, the single-record forms, the parish update form
and member self-registration. The rule matters for more than looks: names are matched by exact
equality on the upper-cased form, so "Hope Area" typed today and "HOPE AREA" pasted last month are
one row, not two. The typed fields also upper-case as you type (`data-caps` in `app.js`), preserving
the caret — not because the server needs the help, but because a member who types in lower case
should see what will actually be saved rather than be surprised later.

`mb_strtoupper` is aliased in `polyfills.php` on hosts without mbstring; the shim now maps the
Latin-1 and Latin Extended-A ranges rather than falling back to `strtoupper`, which leaves accented
letters untouched and would split a parish called Ọ̀ṢỌ́SA into two rows.

**Registration: search and select, or type it.** The picker already searched the directory; what was
missing was a way forward when the church is not in it. Now:

- the member searches and picks — the normal path, one row, no typing of the full name;
- if nothing matches, the last row of the list reads *"My church is not listed"* and files the term
  they typed, in capitals, as `users.parish_name_raw` (migration `0002`);
- the same field is available without JavaScript, in a `<details>` block that needs no script;
- if the church has been added by the time they submit, the typed name resolves to the real row via
  `ParishDirectory::findByName()` rather than becoming a duplicate request.

`parish_name_raw` is deliberately free text and deliberately not a foreign key: it is a request to
add a parish, not a parish. It shows on the person's row in People & roles as **Not in the
directory**, and the top of that page lists *Churches members asked for* with a member count — the
queue the "the admin can add it later" promise depends on. Without it, nobody ever adds them.

**Search is case-insensitive in SQL, not by collation.** `ParishDirectory::search()`,
`directory()` and the People search now compare `UPPER(column) LIKE UPPER(term)`. A stock MySQL 8
install uses a `_ci` collation where `LIKE` would already ignore case, but that is a property of the
host's schema, not of the code, and a `_bin` or `_as_cs` collation would quietly make the picker
miss every church.

**Two bugs found while building it, both caught by the new browser harness:**

1. The manual row was pushed onto the keyboard option list *before* the list was built from the DOM,
   so arrow keys and Enter saw a one-item list while the screen showed fifteen parishes.
2. Enter or tap on that row reached the normal `choose()` path and set `parish_id` to the string
   `"undefined"` — a parish id that would fail a foreign key or, worse, silently attach nobody.

`tests/support/combobox-behaviour.mjs` (23 checks) drives the real `app.js` through both paths,
including that picking a parish after typing a name clears the typed name, so the two fields can
never both carry a value into the same submission.

### 2.3k "The registration page did not list the churches"

The report is one sentence and the cause could have been any of six things — a broken endpoint, a
Content-Security-Policy block, a session-cookie path, a service-worker cache, an exception in the
query, or JavaScript not running on the member's phone. **The design was wrong, not just the code:**
the picker fetched its list, so *every* one of those failures produced the same screen — an empty
search box that looks exactly like "your church is not listed". The member then typed the name,
which is a reasonable conclusion from what they were shown.

So the list is no longer something the page hopes to receive. It is rendered by PHP into the page:

- a `<select>` of every church in the directory (capped at 200 for a 2G phone), which needs no
  script, no network round trip and no CSP allowance, and submits by itself where JavaScript
  never runs;
- a native `<datalist>` so the browser's own autocomplete knows the names too;
- the live-search picker on top, for filtering past 200 and for the "my church is not listed"
  route.

`app.js` now mirrors a choice from the `<select>` into the hidden `parish_id` field and into the
search box, so the two controls cannot disagree, and picking from the list clears any half-typed
name.

**Two real faults were fixed underneath it:**

1. `api/index.php` called `Auth::user()` **without ever starting the session**. For a signed-in
   caller the front controller has already opened it, so this went unnoticed; for the one caller
   that matters — a member on the registration page — `$_SESSION` does not exist, `Auth::user()`
   returns null, and the request proceeds as anonymous. It is now started explicitly, and a
   failure there degrades to anonymous instead of failing the search.
2. That endpoint also **echoed an HTML error page with a 500 status** when the query threw. The
   picker's `response.json()` failed, the `.catch()` closed the list, and the member saw nothing.
   It now always answers JSON with a `parishes` key — empty, with a `hint`, when it cannot.

The picker's client side follows the same rule: a search that cannot run now *says so*
("The parish list could not be loaded just now…") and still offers the manual route, rather than
rendering an empty list. An empty answer and a broken answer used to look identical.

**What is asserted now** — `tests/render.php` requires the churches to be present in the rendered
HTML with no fetch at all, and requires the empty-directory case to explain itself;
`tests/support/phpwasm-smoke.mjs` renders the registration view with no database and checks it
still offers a usable route, and that both API replies (success shape and failure shape) are JSON
with a `parishes` key; `tests/support/combobox-behaviour.mjs` drives the `<select>` mirroring and
the failed-search message. That last harness is what caught the `selectedIndex` assumption:
without a `change` event carrying the new value, a real browser behaves differently from the stub,
and the stub had to be corrected rather than the assertion relaxed.

### 2.4 Updates from the licence server

Until now the only way to get a new release onto an installation was to copy files by hand, which
silently makes the tree disagree with the version the provider has published. There is now a real
update path:

- `app/Core/Updater.php` — requests `check-update.php`, downloads the one-time URL, verifies the
  sha256, inspects the archive and applies it with a per-file backup and rollback.
- `app/Core/ZipReader.php` — a read-only ZIP reader, because relying on `ZipArchive` would make
  updates work on some hosts and fail on others (this sandbox has no `zip` extension at all).
- **Updates** page (provincial administrator, ⇪ in the header): check → review every file that
  changes → apply.
- `bin/update.php --check|--plan|--apply|--history` for the cases where the admin page cannot be
  reached.

Refusals are deliberate and listed in `docs/DEPLOYMENT.md` §3c: a non-provider download host,
a checksum mismatch, `../` or absolute paths, writes into `config/` or `storage/`, or a release that
would replace the updater itself. A failed write restores every file already written. A failed SQL
statement leaves the files in place but reports the exact statement, because a half-migrated schema
is something the operator has to know about immediately.

### 2.4b A bug the update tests found in the test harness

`tests/support/phpwasm-test.mjs` decided success by grepping the output for "FAIL" and ignored
PHP's exit status, so a suite that failed internally could still report success. It now honours the
exit code as well — verified by injecting a deliberate failure and watching the harness return 1.

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
| Licensing | **The API has never been called from this environment** — this sandbox blocks `manager.pmhserver.name.ng` (outbound HTTPS is limited to package registries). The response handling is tested against the documented contract; the network call itself is not. | Run `php bin/license-check.php` on the host with a real key. Then `php bin/license-check.php --domain=wrong.example` to confirm `status: 0`, and `--key=… --save` to confirm a key is stored. `--probe` identifies which exact domain string the provider holds for the key. For the grace window: confirm once, block outbound HTTPS, and reload. |
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

### 2.3l What 1.0.1 got wrong, and what 1.0.2 fixed

The release that added the audit pager and the member lifecycle took three pages down in production.
This is recorded in full because the failure was in the *testing*, not only in the code.

| Symptom | Cause | Fix | Now covered by |
|---|---|---|---|
| `/settings` returned "Something went wrong on our side." | `Audit::page()` built its paginator with `new Paginator(...)`; the constructor is private so every page goes through a validating factory. Fatal on every load, filtered or not. | `Paginator::fromState()` | A test that reads the source of both callers, plus the e2e suite |
| `/people` ditto, and the member tools were unreachable | Same fault in `Members::directory()` | Same fix | Same |
| Filtering either page by anything | One named placeholder (`:q`, `:term`) bound into three positions. With `ATTR_EMULATE_PREPARES = false` PDO sends a native prepare, which binds positionally — "Invalid parameter number" | One placeholder per occurrence | A scan of every SQL literal in `app/`, plus e2e searches that run the queries |
| "The admin is yet to be able to manage members" | The People page worked, the forms worked, and **nothing in the interface linked to it**. Settings and Updates were behind a bare `⇪` glyph | People is a tab for administrators; Settings, Activity, Security and Updates are labelled links in the top bar | Tests that assert each destination is linked and routed |

Two lessons were applied rather than noted:

* **A test that builds its own fixture cannot catch a fault in the code that builds the fixture.**
  The render tests passed a hand-made `Paginator` into the views, so the line that constructs the
  real one never ran. The new test reads the source of the callers, which is crude and would have
  stopped the deploy.
* **"Written but unverified" has a cost, and it was paid.** The database suite existed and was not
  runnable; both faults were in SQL-adjacent code. It is runnable now.

### 2.3m What changed in 1.0.1 — the six field requests

| Request | What was actually wrong | What was built | Verified how |
|---|---|---|---|
| Paginate the audit trail responsively | `Audit::recent(30)` with a hard `LIMIT 30` and no paging at all | `Paginator` (keyset, not OFFSET: the trail is written while it is read, so OFFSET repeats rows), a wrapping `pager` partial that works with JavaScript off, and filters for actor / action / kind / free text | View + unit tests; the preview `admin-settings-audit.html` |
| Automate the current year | `current_cycle_year` was a plain int; the fallback only applied when the key was *absent*, so a December visit kept filing last year's cycle all year | `Settings::cycleYear()` + `rolloverCycleYear()` called once per request; automatic by default, with an explicit "hold a year" mode | Unit tests on the resolver; migration seeds `auto` |
| Does "Programs & funds" match the blueprint? | **No.** Three defects: the graduation gate was documented at 80% but defaulted to 50%; the ₦100,000/month pool defaulted to **0.00**; and the pool field could not be saved at all — a `money` definition with no bounds rejected every value, so the first time an operator tried to set the documented figure they got "Enter a valid amount." | Declared defaults are now honoured (80%, ₦100,000, ₦100,000 ceiling), `money` validation enforces real bounds, four program pools exist, and the disbursement path takes a named advisory lock so two treasurers cannot overdraw the same pool | Unit tests; the accounting change is in the ledger code |
| Where does the admin set "Seed grant pool left"? | Nowhere. The figure was derived from the calendar and had no writer; `pool_deposit` was an unused enum value | **Treasury → Program funds.** An administrator sets the year's total with a reason (audited); a treasurer can record money received as a deposit (ledgered) | View + unit tests; preview `admin-treasury-funds.html` |
| Add / edit / delete / suspend / reinstate / log in as | Only role change, temporary password and suspend existed; there was no way to correct a name or remove a mistake | `Members` core class + a rebuilt People screen; deletion is refused once an account has history and says to suspend instead; impersonation is members-only, bannered and audited | View + unit tests; preview `admin-people-lifecycle.html` |
| Create another admin, report all activity | `provincial_admin` existed but there was no creation flow and no consolidated report | "Create another administrator" on People; a new **Activity report** page (`/audit`) with per-person totals and a filterable, paged event log | View tests; preview `admin-activity-report.html` |

---

## 4. Deliberate design decisions (and the trade-offs)

| Decision | Why | Trade-off |
|---|---|---|
| No obfuscation for the activation check | Obfuscated PHP hides bugs, not secrets; a server owner can always read the code | A determined operator can see the check. Real enforcement lives in the response of the manager, not the shape of the code. |
| Recognition is an indicator, never a bypass | A whitelist that skips authentication is how shared-host sites get owned | A recognised IP can still be blocked by failures. |
| Country "unknown" defaults to allow | Most Nigerian mobile traffic is CGNAT; denying unknown countries would lock out real members | Blacklisting therefore only acts on *resolved* countries. |
| Firewall work is queued, never executed in a request | Running privileged shell commands from a web request is the classic privilege-escalation path | Blocks are application-level until the worker is installed and configured. |
| Members cannot apply twice to the same program per cycle | The ₦100,000 pool is small; duplicates would silently consume it | Repeat applications need a new cycle year (or an admin withdrawal). |
| A pool is a *set* balance, not a calendar derivation | ₦100,000/month ÷ 12 accrual could not be set by anyone and moved on its own at the turn of the year | The monthly allowance still accrues, but only as the *default* until an administrator sets the year's figure. |
| Restating a pool is audited, not ledgered | The ledger records money moving (deposits, disbursements); a budget decision is not an event | "Why is the pool this number" is answered by the audit trail, not the ledger; the two are read together on Treasury. |
| Disbursement confirmation takes a named advisory lock | Two treasurers confirming two different grants both passed the balance check and overdrew the pool | A 5-second `GET_LOCK` timeout can refuse a confirmation with "try again in a moment" during a genuine collision. |
| Impersonation is bounded | "Sign in as" is the fastest way to reproduce a member's problem, and the fastest way to act invisibly | Members only (never another administrator), banner cannot be dismissed, both parties recorded. |
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
  tests/support/             php-wasm harnesses: lint, logic, views, smoke, preview generator
  docs/preview/              static snapshots of the setup wizard (open in a browser)
  docs/                      DEPLOYMENT.md, IMPLEMENTATION_STATUS.md, archive/
```

---

## 7. Recommended order of work from here

1. Run the installer on staging MySQL; then write `tests/integration.php` with the §3 cases.
2. Self-service password reset with signed, single-use, 30-minute tokens, once mail delivery is confirmed.
3. CSV import for the parish directory.
4. Exports (CSV/PDF) for the ledger and applications.
5. Accessibility audit with a real screen reader, then a penetration test before real money moves.
