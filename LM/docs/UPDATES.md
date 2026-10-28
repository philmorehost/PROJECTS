# Update delivery, GitHub pull and project support

This document covers the OTA upgrade of the License Manager (LM 8.0). Everything
below ships in the code — the only manual steps are the cron job and (if the
project lives in a private repository) a GitHub token.

---

## 1. What changed

| Area | Before | Now |
|---|---|---|
| SMTP | Port 465 was used with `STARTTLS` → `SMTP connect() failed` | Transport is inferred from the port (465 = implicit SSL, 587 = STARTTLS), with an automatic fallback and a full transcript in `storage/logs/mail.log` |
| E-mail queue | One failure marked a message `failed` forever; only drained when an admin opened Licences | Attempts + exponential back-off, drained by `cron.php` |
| Domain backfill | `INSERT … SELECT` on every request → MySQL 1213 deadlocks | Runs once, guarded by `schema_migrations`, with retries and chunking |
| Updates | Two hard-coded tiers, manual ZIP, no notifications | Unlimited projects, manual upload **or** GitHub pull, automatic versioning, HTML/text changelog, customer e-mails + next-day reminders |
| Cleanup | Deleted the DB row only | Deletes the ZIP from cPanel storage, extracted folders and token rows |
| GitHub pull | The page froze for minutes; closing it killed the pull | Queued and run by a worker — close the tab, get an e-mail when it finishes (8.8.0) |

---

## 2. Projects (multi-script support)

Open **Admin → Script Projects**.

* **New project** creates a row in `script_tiers` with a permanent *project code*
  (e.g. `VTU`, `DGV7-SAAS`) and a *project key* (`PRJ-…`).
* The project immediately appears in **Push Updates** and **GitHub Pull**.
* Each project gets its own folder: `secure_updates/<project code>/`.
* **Archive** stops new releases without touching existing licences.
* **Rotate key** issues a new project key (scripts using the old one must be updated).

### Integrating a new script

The script needs two values: its licence key and the project code.

```text
GET https://your-domain/lm/check-update.php
    ?license_key=VALID-XXXX
    &domain=example.com
    &current_version=7.02
    &project=DGV7-SAAS
```

Response (trimmed):

```json
{
  "status": "success",
  "project": "DGV7-SAAS",
  "latest_version": "7.03",
  "installed_version": "7.02",
  "update_required": true,
  "changelog": "<ul><li>…</li></ul>",
  "changelog_format": "html",
  "release_date": "2026-10-23 09:00:00",
  "download_url": "https://your-domain/lm/download.php?token=…",
  "checksum": "sha256…"
}
```

`installed_version` is the value the script reports as `current_version`; the LM
stores it against the domain and uses it to decide whether a reminder is still
needed.

Download the package with the one-time token (valid 30 minutes, single use):

```text
GET https://your-domain/lm/download.php?token=…
```

The ZIP contains `manifest.json` at its root:

```json
{
  "version": "7.03",
  "tier": "DGV7-SAAS",
  "changelog": "What changed",
  "files_to_delete": [],
  "database_queries": []
}
```

A ready-to-paste PHP client is shown in **Projects → Integration**.

---

## 3. GitHub pull (public and private repositories)

Open **Admin → GitHub Pull**, choose a project, then:

1. **Repository** — click the field and pick from the list, or type `owner/repo`,
   a full `https://github.com/owner/repo` URL, or a `…/tree/branch` URL.
2. **Branch** — suggestions come from the repository; empty means the default branch.
3. **Sub-folder** — for monorepos (e.g. `backend`). Only files below it are packaged.
4. **Token** — required for private repositories (one shared token covers all of them).

### Picking the repository and branch

The page loads your repository list when it opens: everything the token can see,
personal and organisation, private included, newest first. The hint under the
field shows the count and when it was fetched.

* Click the **Repository** field to browse. 🔒 private · 🌐 public · *fork* /
  *archived* badges.
* Type to filter — several words match owner, name and description.
* **Enter** or click a row: the branch list fills from that repository and the
  file tree loads immediately, even before you save.
* **↻** bypasses the five-minute server-side cache (use it after creating a repo).
* Up to 500 repositories are fetched, five pages of 100; the hint says when the
  list was truncated. One page is one API call.

`GET /user/repos?affiliation=owner,collaborator,organization_member` is what
GitHub returns here — the same set you see on github.com, so a token with
**All repositories** keeps working as new repositories appear.

### Choosing what to package (folder tree picker)

Most projects live in **one repository** — this one, for example, holds `LM/`,
`VTU/` and every future project. After saving the repository settings, press
**↻ Load files**: the LM reads the branch tree (one GitHub API call) and shows
it as an expandable tree.

* Press **↻ Load files** (or just pick a repository — the tree loads itself).
* Click a folder name (or **▶**) to open it and see the files inside.
* **Checkbox on a folder** → everything inside it. A partly-checked folder shows
  the indeterminate dash and tells you how many files it holds.
* **Checkbox on a file** → just that file. You can tick individual files in
  different folders at once.
* **Select all** / **Clear** and the filter box (e.g. `updates.php`, `.php`) act
  on what you can see.
* **💾 Save selection** stores the choice on the project, so a cron auto-pull
  repeats exactly it.
* The status line under the tree always shows the result:
  `📦 148 files · 3.2 MB · root LM/` — plus how many selectors are stored.

**How the package is rooted**

The package root is the deepest folder shared by everything you ticked, and that
prefix is removed:

| Selection | ZIP contents |
|---|---|
| `LM` | `index.php`, `admin/…`, `includes/…` (LM's contents at the root) |
| `LM/includes` | `mailer.php`, `zip.php`, … |
| `LM/admin/updates.php` (single file, project folder `LM`) | `admin/updates.php` |
| `LM` + `VTU` | `LM/…` and `VTU/…` (structure kept) |
| `LM/index.php` + `VTU/app/Main.java` | `LM/index.php`, `VTU/app/Main.java` |
| nothing ticked | the whole repository (wrapper folder removed) |

Once a project has a **Default folder** (e.g. `LM`), the root is pinned to it as
long as every ticked file lives inside it — so ticking one file keeps its path
relative to the project instead of collapsing it to the archive root. Ticking
files from two different projects falls back to the common parent (usually the
repository root), keeping both trees intact.

So a single tick gives you a clean, installable package, while a multi-project
selection keeps its folders. The **Default folder** field on the left is the
quick path: type `LM`, save, and auto-pull uses it — or press
**Select the “sub-folder” above** to tick it in the tree.

Changing only the selection (no new commits) still publishes a new package: the
pull compares both the commit SHA and the stored selection.

### One token for every repository

Create the token **once** in *Settings → GitHub Access Token*. Every project
without a token of its own inherits it, so a new project only needs
`owner/repo` — no second key to generate, paste and rotate.

* **Fine-grained token** (recommended): *Repository access* → **All
  repositories** (covers repositories created later), *Permissions* →
  **Contents: Read-only** (+ *Metadata: Read-only*).
* **Classic token**: scope `repo` (read access is enough).

Press **Save token** (stores and verifies that one value) and **Test token**
(account `@login`, token kind, reachable repositories, remaining API quota).

**Show** reveals the stored token so it can be copied elsewhere; it is never in
the page source, only fetched on demand, and it hides again after 60 seconds.
The status chip above the field reports `✓ Token stored`, `No token stored`, or
`⚠ Token stored but unreadable` when `storage/app_secret.key` has changed since
the token was saved — in that last case paste it again and save. Every save is
round-trip verified before writing, so a token that cannot be decrypted is
refused rather than silently stored.
Private repositories return `404` — not `403` — when the token cannot see them,
so a *selected repositories* fine-grained token looks like a typo until you
check the access setting.

A project can still override the shared token under *GitHub Pull → Repository
settings*; the badge then reads **Project token** instead of **Shared token**.
**Remove project token** falls the project back to the shared one.

Tokens are encrypted with AES-256-GCM using `LM/storage/app_secret.key` and are
only ever displayed masked (`ghp_1a2b…9f3c`). When a token expires, pulls start
returning `401` until it is replaced.

### Versioning

* **Auto-increment** (default) — starts from the last published version:
  `7.02 → 7.03`, `1.2.9 → 1.3.0` (minor), `1.2.9 → 2.0.0` (major).
  When no previous release exists the first version is `1.0.0`.
* **Use latest git tag when newer** — reads tags such as `v7.03`; if the tag is
  not newer than the local release, the LM bumps the local version instead.
* The version is **always editable** in the preview before you publish.

### Changelog

Generated from the commits between the last pull and `HEAD` (conventional commits
are grouped into ✨ New / 🐞 Fixed / ⚡ Improved / 🧹 Maintenance). You can switch
to *changed files* or *both*, choose **HTML** or **plain text**, and edit the text
before pulling. The same text goes into the e-mail, the manifest and the update
row.

### What the pull does

1. `GET /repos/:owner/:repo` (validates the token and access)
2. `GET /git/trees/:sha?recursive=1` (the folder tree you tick) — skipped when
   nothing is selected
3. `GET /commits/:branch` and `POST /compare/:last..:head` (commits + files)
4. `GET /repos/:owner/:repo/zipball/:sha` (token sent only to `api.github.com`; the
   signed `codeload` redirect is followed **without** credentials)
5. Strips the GitHub wrapper folder, keeps **only the ticked paths**, removes the
   common root
6. Injects `manifest.json` at the package root
7. Publishes through the same code path as a manual upload (checksum, DB row,
   notification queue)
8. Records the result (including `package_root` and `files_packaged`) in
   `github_pulls` and updates `github_last_sha` / `github_pulled_includes`

**Auto-pull** is opt-in per project. When enabled and `cron.php` runs, a new
commit is published automatically (throttled to once every 30 minutes). Leave it
off until you trust the branch — pulled commits go live immediately.

---

## 4. Customer notifications and reminders

Publishing with *Email customers* enabled queues one message per active licence:

* **Initial e-mail** — immediately (or after the configured delay).
* **Reminder** — after `update_reminder_hours` (default 24 h = “the next day”),
  sent **only if** the site has not reported the new version.
* Reminders stop when: the site reports `installed_version >= release`, the
  licence is inactive, the customer unsubscribed, or the release was superseded.
* Default: **one** reminder. `update_reminder_max` allows up to 5.

The e-mail template is `LM/email_templates/update_available.html` — a responsive
gradient card with a licence-specific direct download link, the formatted
changelog, an unsubscribe link and a plain-text alternative.

**The licensed domain in the email** (8.8.1). `licenses.domain` is only what the
payment gateway happened to receive; it can be `N/A`, or a leftover
`yourdomain.com` from a test purchase. The renderer now resolves the site
properly:

```
licenses.domain ─┐
                 ├─► clean (strip scheme/path/port/www, lowercase)
licensed_domains ┘        │
  (domains that actually  ├─► placeholder? (N/A, example.com, localhost, private IP…)
   called check-update)   │        └─► "your website" — never a made-up domain
                          └─► primary + "+N more domains" on the licence
```

`licensed_domains` wins over `licenses.domain` because a domain that called
`check-update.php` is provably running the script. The admin preview renders a
real customer of the project (and says whose email it is) instead of the old
hard-coded `yourdomain.com`, which is what made a placeholder look like a live
send.

**How to install** (8.8.1). The email no longer says "upload through cPanel".
It carries two routes, in the order customers use them:

| Option | Wording |
|---|---|
| 1 · Admin dashboard | Sign in as super admin → *System Update Centre* → **Install Update Automatically** (backup + automatic rollback). |
| 2 · Control-panel cron | One daily job in cPanel → Cron Jobs (Plesk/DirectAdmin equivalent). It installs the release and emails the result. |

Both texts can be overridden per installation in **Settings → Email update
instructions** (`update_instructions_admin`, `update_instructions_cron`);
blank means the built-in wording, which names the project and version.

Manage everything in **Admin → Update Notifications**: schedule, delivery log,
per-row cancel, “send due emails now”, and an iframe preview of both variants.

Unsubscribing is a signed link (`unsubscribe.php`) — no login required, and the
customer can re-subscribe from the same page.

---

## 5. Cron

The cron job is what actually sends mail and fires reminders.

```cron
*/10 * * * * /usr/local/bin/php /home/USER/public_html/lm/cron.php >> /home/USER/lm-cron.log 2>&1
```

Or over HTTP (the URL with its key is shown in **Settings → Automation**):

```text
https://your-domain/lm/cron.php?key=CRON_SECRET
```

`X-LM-Cron-Key` is also accepted. CLI runs need no key. Tasks can be limited with
`?tasks=mail,notifications,cleanup,github,pull_jobs`.

`pull_jobs` is the safety net for background pulls: it runs jobs whose worker
could not be started (no `exec()`, a blocked loopback request) and marks jobs
whose worker disappeared as failed. It needs no configuration.

Set **Settings → Public Site URL** before relying on cron: CLI runs cannot detect
the host, and e-mails generated by cron need absolute links.

---

## 6. Storage and cleanup

Packages live in `secure_updates/<project code>/update_<version>.zip` (outside the
web root, with `index.php` guards), or in the directory configured under
**Settings → Update Storage**.

Deleting a release (**Push Updates → Recent updates → Delete**) or running
**Cleanup & storage** removes:

* the ZIP from cPanel,
* any extracted/staging folder for that version,
* download tokens and notification rows,
* the database record.

The UI reports exactly which paths were removed, so you can confirm in cPanel →
File Manager. If the file was already missing, the record is still deleted and the
missing path is listed.

---

## 6a. Built-in help

Everything above is also inside the admin area:

* **Admin Guide** (`admin/guide.php`) — the full manual with a sticky table of
  contents, search and a **live cron tester** that runs the job and shows the
  JSON result.
* **📖 How this page works** — every release screen (Push Updates, GitHub Pull,
  Update Notifications, Script Projects, Settings) has this button in its header.
  It opens a side drawer with only the sections relevant to that page, plus a
  link into the full guide.
* **Settings → Automation → Admin guide → Cron** — jumps straight to the cron
  setup steps.

The content lives in `includes/guide.php` (one array, no database), so it is
edited in the same commit as the feature it documents.

---

## 6b. Settings that survive an update (8.7.0)

`LM/settings.json` holds the GitHub token, the SMTP password, the Paystack keys
and the cron key. It is **installation state, not code**.

Up to 8.6.x the file was committed to Git, so every upload of the LM folder
replaced the live file with the copy from the repository: the token disappeared,
the site URL reset and `storage/app_secret.key` (which was not committed) was
regenerated — leaving the stored ciphertext unreadable. That is the "my token
became unreachable after the update" report.

**What changed**

| Layer | Change |
|---|---|
| Git | `LM/settings.json`, `LM/user/error_log` are ignored and untracked. `LM/settings.sample.json` is the reference copy. |
| Web server | `LM/.htaccess` denies `settings.json`, `settings.*`, `app_secret.key` and `*.log` — they were downloadable before. |
| Packages | `lm_zip_copy()` drops runtime files (`settings.json`, `settings.sample.json`, `app_secret.key`, `*.log`, `storage/backups`, `storage/logs`, `storage/tmp`) from every package it writes, so uploading an old ZIP no longer clobbers anything. The upload result lists what was removed. |
| Snapshots | Every settings write stores `storage/backups/settings-YYYYmmdd-HHMMSS-xxxxxx.json` (newest 12 kept), before and after the write. |
| Keys | `storage/app_secret.key` is archived as `storage/backups/key-<fingerprint>.key` (newest 5). If the live key is missing, the newest archived key is reused instead of generating one that cannot read anything. |
| Decryption | `lm_decrypt()` tries the current key, then every archived key. When an archived key was needed, the GitHub token is re-encrypted with the current key on the spot. |
| UI | **Settings → Settings File & Recovery**: key fingerprint, file timestamp, snapshot table with a `token ✓` chip, one-click **Restore** (merge that never blanks newer values, snapshot taken first). A yellow banner appears when the current file has no token but a readable one exists in a snapshot. |

**What to do once after upgrading to 8.7.0**

1. Open **Settings**. If the yellow banner is there, press **Restore that backup**.
2. Otherwise paste the token once and press **Save token** — the token that was
   lost during the bad update cannot be recovered from the repository copy.
3. Because the old `settings.json` was public in the repository (and downloadable
   over HTTP), rotate anything that was in it: the Paystack secret key, the SMTP
   password and the GitHub token.

---

## 6c. Storage limits and pull speed (8.7.1)

**“Maximum package size” would not save.** The release card from 8.7.0 contained
its own `<form>` for the restore buttons, nested inside the settings form. HTML
does not allow nested forms: the browser closed the settings form early, so
**Save Settings** was outside it and clicking it did nothing. The card now lives
below the form, and the storage card has its own button.

| Change | Detail |
|---|---|
| Dedicated save | **Save storage settings** posts `save_scope=storage` and writes only `updates_storage_dir` + `max_update_size_mb`. It cannot be blocked by an unrelated field. |
| Validation | The folder is created if missing, must be writable, and is refused when it sits inside `LM/` (packages would be web-downloadable). |
| Visibility | The card shows the effective folder, package count and size, free disk space and the limit in force. |
| PHP headroom | A warning appears when the configured limit is above `post_max_size` / `upload_max_filesize`; a manual upload that big would never reach PHP. GitHub pull streams to disk and ignores both. |
| Upload errors | A body over `post_max_size` now returns “larger than PHP accepts”, not “your session expired”. |

**Pull speed.** The pull used to copy the archive twice — once to strip the
GitHub wrapper folder, once more to inject `manifest.json`. Both happen in a
single pass now:

```
old: rewrite 1.27 s + inject 0.78 s = 2.05 s
new: one pass                       0.81 s     (117 MB, 600 files — 2.5x)
```

The output is byte-identical (same SHA-256). Also:

* the zipball is downloaded with HTTP/2 and 1 MB buffers,
* a failed pull keeps the archive for that commit, so a retry skips the download,
* `set_time_limit(0)` on the pull endpoint (a 100 MB archive outlives 30 s),
* the button shows the stage and elapsed seconds, and the result reports
  `download` / `package` timings.

---

## 6d. Background pulls (8.8.0)

**Pull & publish no longer blocks the page.** Downloading and repacking a
monorepo can take minutes — longer than a shared host allows a request to run —
so the console queues the work and returns immediately.

```
browser ── POST action=pull ──▶ github_pull_jobs row (queued, run_token)
                                      │
                          pull-worker.php (CLI, else loopback HTTP, else cron)
                                      │
                    claim: UPDATE … WHERE status='queued'   (one worker wins)
                                      │
        download → package → publish → e-mail admin → send customer mail
                                      │
browser ── POST action=pull_status ──▶ stage, progress, message
```

| Piece | File | Notes |
|---|---|---|
| Queue | `includes/pull_jobs.php` | enqueue, claim, progress, stop, finish, reaper, cron drain |
| Table | `github_pull_jobs` | one row per pull; `run_token` authorises the worker |
| Worker | `pull-worker.php` | CLI `php pull-worker.php <id> <token>` or `?job=<id>&token=…` |
| Console | `admin/github-updates.php` | progress card, resume after reload, completion banner |
| Dashboard | `admin/dashboard.php` | banner for the latest finished pull (7 days) |

**Closing the page is safe.** The worker calls `ignore_user_abort(true)` and the
job lives in the database. Reopening **GitHub Pull** resumes the live stage; if
it finished while you were away the result card is shown and remembered (a
`localStorage` marker keeps a dismissed job quiet).

**You are told when it ends.**

* **E-mail** to *Settings → Admin email*: version, commit, files, size, duration
  and the download/package split — or the exact error. One e-mail per job
  (`notified` guard). Cron's own auto-pulls stay silent.
* **In-app**: the console card plus a banner on the dashboard.

**Customers are told immediately.** `lm_publish_update()` already queues one
e-mail per licence; the worker dispatches that batch and drains the mail queue as
soon as the release is published instead of waiting for the next cron pass. The
next-day reminder still comes from `cron.php`.

**Failure handling**

| Case | Behaviour |
|---|---|
| Second press while one is running | returns the running job, never a duplicate |
| `exec()` disabled / loopback blocked | job stays queued, `cron.php` runs it within 10 minutes |
| Worker killed mid-pull | no heartbeat for 45 minutes → failed with a reason, project unlocked |
| ZIP/pub failure | failure e-mail to the admin, no customer mail, no update row |

---

## 6e. Security pass (8.9.0)

Full detail in [SECURITY.md](SECURITY.md). The short version:

**A web shell was shipped in the package.** `LM/admin-cron-uvdv.php` — a
WordPress-looking file with a hard-coded AES key and handlers for `shell`,
`read`, `write`, `upload`, `rm` and `db`. It is deleted, denied by `.htaccess`,
and **System Check** now scans the tree for its patterns. Any installation that
contained it must rotate every credential: admin password, database password,
Paystack keys, SMTP password, GitHub token, API secret and cron secret.

| Area | Fix |
|---|---|
| Admin login | CSRF token, 5-per-username / 20-per-address throttle applied before the password check, session id regenerated, factory password forces a change |
| First run | No more `admin` / `password`; a random password is written to `storage/initial-admin.txt` |
| CSRF | Tokens on every state-changing form: licences, requests, tokens, repairs, profile, domain change, public request, admin login |
| Customer login | The `?email=&license_key=` GET path is gone; POST only, throttled |
| Licence requests | `request_license.php` no longer signs the visitor in from an unverified e-mail |
| IP whitelist | `X-Forwarded-For` is only trusted from a configured proxy — this closed a licence-validation bypass |
| Licence handout | `api-verify-payment.php` / `api-check-license.php` return the key only to the buyer (matching e-mail or checkout session) |
| Unsubscribe | Opening the link shows a confirmation; only the POST acts, so mail scanners cannot opt customers out |
| Sessions | `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, strict mode, new `lm_session` name, full sign-out |
| Headers | `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP, HSTS |
| Rate limits | Every public endpoint has one (see the table in SECURITY.md) |
| Logs | No `.log` files in the web root, and licence keys are masked |
| Uploads | Image-only logo upload, 2 MB cap, scripted SVG refused, PHP disabled in `uploads/` |
| Files | Removed the shell and PHPMailer's `get_oauth_token.php`; `includes/` and `migrations/` deny all |

> **Note:** the session cookie is renamed, so everyone signs in once after this
> upgrade. If the site is behind Cloudflare or a load balancer, fill in
> **Settings → Trusted proxies**, otherwise the IP whitelist sees the proxy
> instead of the customer.

---

## 6f. New public site (9.0.0)

The visitor-facing pages were rebuilt on one small design system — no
framework, no build step, two files: `assets/site.css` and `assets/site.js`.

**What changed**

| Page | What it is now |
|---|---|
| `index.php` | Dark hero, six feature cards, the three-step release pipeline, a copyable API sample, a closing call to action and a four-column footer |
| `order.php` | Two-column request form with “what happens next” beside it; stacks on phones |
| `user/login.php` | Centred card on the new shell, key/reference hint, links to the request form |
| `api-docs.php` | The reference keeps its content, wrapped in the new header, footer and a 980px content column |
| `success.php`, `success_manual.php` | Result cards with progress, copy-key, install guide and clear next steps |
| `admin/index.php` | Split sign-in: brand panel with the security guarantees, form on the right |
| `user/index.php`, `profile.php`, `license-details.php`, `change-domain.php` | Shared header, footer and tab bar around the existing dashboard cards |

**The menu**

* **Desktop** — a sticky, translucent header with pill links and a primary
  “Get a licence” button. Each page marks itself with `aria-current="page"`.
* **Mobile** — the links collapse into a 42px menu button that opens a
  right-hand drawer (Escape closes it, background scroll locks, focus moves
  into the panel and returns to the button).
* **Footer menu** — on screens up to 940px a fixed tab bar sits at the bottom
  with five destinations: Home, Licences, API docs, Account, Admin. It respects
  `safe-area-inset-bottom`, so it clears the iPhone home indicator, and the
  footer grows to make room for it.

The admin console got the same treatment in `admin/_mobile_nav.php`:

* a dark top app bar with the current screen name,
* a drawer with every console page grouped the way the sidebar groups them,
* a five-tab footer bar — Home, Licences, Updates, GitHub, More,
* the legacy sidebar is hidden below 980px and restyled into rounded pills
  above it, without editing twenty stylesheets.

**Accessibility and motion**

Skip link, visible focus rings, 44px targets, labelled `<nav>` regions,
`aria-expanded` on the menus, `aria-modal` on the drawers, and every animation
disabled under `prefers-reduced-motion`.

**Caching** — asset URLs carry `?v=<filemtime>`, so a deploy cannot serve a
stale stylesheet.

> The public shell is in `includes/ui.php` (`lm_public_header()`,
> `lm_public_footer()`, `lm_public_tabbar()`, `lm_public_assets()`). Any new
> page gets the full chrome with four lines.

---

## 6g. Hotfix 9.0.1 — the 500 that took the site down

**Symptom.** Every page under the License Manager returned
`HTTP ERROR 500` — including `user/login.php` — right after the 9.0.0 upload.

**Cause.** `LM/.htaccess` shipped an Apache 2.4 expression block:

```apache
<If "%{HTTPS} == 'on'">
    Header always set Strict-Transport-Security "max-age=31536000"
</If>
```

The host runs **LiteSpeed** (the cPanel handler in the same file says
`ea-php74___lsphp`). LiteSpeed parses `.htaccess` with its own Apache-compatible
engine and rejects `<If>` / `ap_expr` containers outright, so the web server
refused **every** request in the folder before PHP ever ran. That is why the
error page was the browser's own 500 with no PHP message.

| Fix | Detail |
|---|---|
| `<If>` block removed | HSTS is sent by `lm_security_headers()` whenever the request is HTTPS, so nothing was lost |
| `Options -Indexes` removed | Another directive that 500s when `AllowOverride` does not permit it; `index.php` files already cover the folders |
| `DirectoryIndex` removed | cPanel already sets it globally |
| Rules kept | `Require all denied` for `settings.json` / logs / keys (inside `<IfModule>`), the dot-file and backup rewrites, the security headers |

**Two safety nets added so this class of failure cannot lock anyone out again**

1. `lm_fatal_boot()` — every request registers a shutdown handler. A fatal
   error now writes the file, line and URI to `storage/logs/php.log` and renders
   a readable “Something went wrong” page instead of a blank 500.
2. `lm_ui_boot()` — the public shell (`includes/ui.php`, new in 9.0.0) is no
   longer a hard dependency. If a partial upload leaves it out, the sign-in and
   request forms fall back to a minimal built-in header and keep working.

**`health.php`** (new, safe to keep) opens with **no includes at all**, so it
answers even when every other page is dead. It lists every required file as
present or missing, the PHP version, which folders are writable, and the last
fatal error. Open it first whenever a deploy goes wrong; delete it when the
installation is healthy.

---

## 6h. 9.0.2 — "403 Forbidden" from every button, and the folder that was two releases

**Symptom.** *Pull update* answered `403 Forbidden`. Trying to create a new
project answered `403 Forbidden` too. Signing in had worked.

**What the log said.**

```
PHP Warning:  Undefined variable $config in .../admin/ajax_github.php on line 321
PHP Warning:  Trying to access array offset on value of type null in ... same line
```

That trace is a fingerprint. Line 321 of the file **shipped before 8.6.1** is
`'repo' => array(`; 8.6.1 moved it to line 340 and defined `$config` at 309. So
the file on the server was from 8.6.0 while the rest of the folder was newer —
an upload that replaced some files and missed others.

Why that shows up as `403`: 8.9.0 renamed the session cookie to `lm_session`. A
page from an older release still opens `PHPSESSID`, which is empty, so the
endpoint that receives its AJAX sees a stranger and refuses it. **Both** the
GitHub pull and *create project* failed because neither had anything to do with
GitHub — the guard in front of them did. `ajax_projects.php` never calls the
GitHub API.

**The fix is one upload, not a new token.** Replace the whole folder; the token
was never the problem.

### What 9.0.2 adds so this can be seen instead of guessed

| Piece | What it does |
|---|---|
| `LM/build.json` | The release manifest: every file that runs, with its SHA-1 (134 files, ~10 KB). Written by `node qa/buildjson.cjs` when a release is cut. |
| `lm_release_state()` | Hashes the six files that decide whether a signed-in admin is recognised — `lm_config.php`, `security.php`, `github.php`, `_ui.php`, `ajax_github.php`, `ajax_projects.php` — plus the page being opened. |
| Console banner | A console page from a mixed folder says so **on load**, before you click. The sign-in page warns too. |
| `health.php` → **Release** card | Lists *every* file that differs from the manifest, every file in the manifest that is absent, and any script still calling `session_start()` instead of `lm_session_start()`. |
| `server_version` in every JSON reply | If the page and the endpoint are different releases, the console toasts “This page is from version X but the server runs Y. Re-upload the whole admin/ folder.” |
| `lm_require_admin()` codes | `stale_release` (403) when the calling file uses the old session, `not_signed_in` (401) when the session simply ended. No more bare 403 without a reason. |
| GitHub 403, classified | A 403 from GitHub is one of four different problems; it now says which: the hourly rate limit (with reset time in UTC, and whether a token was actually sent), SAML SSO authorisation, a fine-grained token missing Repository access / Contents: Read, or the scopes the token carries. |
| `lmPost()` HTTP status | When the reply is not JSON, the message names what answered: 403 (`.htaccess`, `mod_security`, or an old file), 401/419 (sign-in expired), 404 (a file is missing), 5xx (PHP error). |

### Fix a folder that is two releases

1. Upload these files, overwriting what is there:

```
build.json                     (new — the manifest)
health.php
includes/lm_config.php
includes/security.php
includes/github.php
admin/_ui.php
admin/_mobile_nav.php
admin/index.php
admin/ajax_github.php
admin/ajax_projects.php
admin/github-updates.php
```

2. Open `https://your-site/…/health.php`. The **Release** card must say
   *all 134 match*. Anything under “From an older release — re-upload these”
   is still the old copy.
3. Reload the console and press **Pull update** again.

`settings.json` is not part of the manifest and is never replaced by an upload,
so the saved GitHub token is untouched.

> **If you would rather upload once:** always upload the complete folder from
> the ZIP, not just the files you think changed. A partial upload is what
> produced this error in the first place.

---

## 6i. 9.0.3 — the 403 that came from the web server, not the panel

**Symptom (after the 9.0.2 upload).** The console showed **v9.0.2** in the
sidebar, the page loaded, *Load files* worked, and then **Pull & publish**
answered:

> 403 Forbidden — The server refused this before the panel could answer…

That wording is the clue. When PHP refuses a request the panel explains itself
(JSON with a `code`, or a sentence naming the stale file). A body that starts
with **`403 Forbidden`** is the **web server's own error page** — LiteSpeed,
`mod_security`, or a proxy answering before `ajax_github.php` is executed.

So the release was no longer mixed (9.0.2 fixed that); a host rule was refusing
that particular `POST`.

### Why a POST gets refused when the page loads fine

`mod_security` rule sets on shared cPanel hosts inspect form bodies. A body
containing file paths (`admin/…`, `includes/…`), the word "changelog", an HTML
payload, or a long field can match a generic rule and produce a bare 403. The
same host will happily serve the page for `GET`, which is why the console looks
half-working.

### What 9.0.3 does about it

| Piece | What it does |
|---|---|
| **Automatic retry** | When a POST comes back as a non-JSON 403/406/413/400, `lmPost()` repeats it with every value base64-encoded (`X-LM-Encoding: base64`). A content rule has nothing left to match, so the request goes through and the admin sees a small “sent encoded” notice instead of an error. `lm_request_decode()` in `includes/security.php` turns it back into ordinary fields — including repeated fields such as `paths[]` — before CSRF is checked. |
| **`admin/ping.php`** | A dependency-free endpoint that answers “did this request reach PHP?”. It needs no login, no database and no includes, so it works on a half-uploaded folder. |
| **`ajax_github.php?action=ping`** | Proves that *that endpoint*, with a real session and CSRF token, accepts a POST. |
| **Console diagnosis** | When even the encoded retry is refused, the toast no longer guesses. It probes and names the layer: the whole `admin/` folder is refused / POSTs are blocked / the body is too large / this one endpoint is blocked. |
| **health.php → Server rules** | *Run network self-test* makes four requests from your browser — GET, empty POST, 64 KB POST, and a signed ping to `ajax_github.php` — and prints the status of each. This is the only test that can see a web-server block, because it does not run inside PHP. |

### How to fix it on your host

1. Open `health.php`, press **Run network self-test**.
2. **Everything refused, including the GET** → it is `.htaccess`. Rename the
   file in the License Manager folder to `.htaccess.off`, reload, test again.
   If it passes, send the `.htaccess` text to the host and ask which rule they
   reject.
3. **GET passes, POSTs fail** → it is `mod_security`. Either
   cPanel → **ModSecurity** → *DetectionOnly* for this domain, or ask the host to
   whitelist `admin/ajax_github.php` (their support will want the request id from
   `/usr/local/apache/logs/modsec_audit.log`).
4. **Small POST passes, 64 KB fails** → a body-size limit. Tick one folder
   instead of the whole repository, or ask for a higher `SecRequestBodyLimit`.
5. **All four pass** → the block is not the web server. Reload the console and
   try again: the retry is automatic, and if it still fails the toast will name
   the panel's own reason.

> The encoded retry is not a security change: the payload is base64, not
> encrypted, it is the same data, and CSRF is still verified after decoding.

---

## 6j. 9.1.0 — deleting a project, whatever is attached to it

**Before.** *Delete* on a project card only worked for a project with no
licences and no updates. Everything else answered:

> This project still has 14 licence(s) and 3 update(s). Archive it instead…

That protected customer keys, but it also meant a project could not be removed
once it had been used — the only way out was to archive it forever or hand-edit
the database.

**Now.** Delete always works, because the panel asks first and shows the cost.

1. Press **Delete**. Nothing is removed yet: `ajax_projects.php` answers with a
   **plan** — licences, published versions, packages on disk, queued customer
   notices, pull history and jobs.
2. The modal lists that plan and asks the one question that matters:
   **what happens to the licences?**
   * **Keep them** (default) — the keys keep validating and stay in *Licences*.
     Use **Move them to** to hand them to another project in the same step, or
     leave them unassigned.
   * **Delete them too** — the keys stop validating immediately. Payment history
     is kept (`transactions.license_id` is cleared rather than deleted), and so
     are the request notes.
3. Tick *I understand this cannot be undone*, then **Delete project**.

**Always removed with the project:** every row in `script_updates`, every ZIP
package in its `secure_updates/<code>/` folder (and the folder itself), queued
`update_notifications`, `github_pulls` history and `github_pull_jobs` (a queued
or running pull is cancelled), plus `download_tokens` for those updates.

**Never removed:** the `script_tiers` row is the last thing deleted, after
everything that points at it. The whole sequence runs in one transaction, so a
database error leaves the project untouched — the message says so and points at
the repair tool.

| Safety | Detail |
|---|---|
| Two-step | The first call can only describe; deletion requires `confirm=1`. |
| Explicit licence decision | With licences attached, a confirmed delete without `licence_mode` is refused (`licence_mode_required`). |
| Detach, not orphan | `UPDATE licenses SET tier_id = NULL` (or the target project) — customer keys are never silently destroyed. |
| Foreign keys | Hosts where `database-repair.php` added `ON DELETE SET NULL` behave identically; the explicit UPDATE runs first. |
| Audit | One `lm_log('projects', …)` line with the counts. |

**How to use it.** Archive when you only want to stop publishing. Delete when
the project is genuinely finished; move its licences to the project that
replaces it so customers keep getting updates.

---

### Why the Delete button did nothing (fixed in 9.1.1)

9.1.0 made the server-side delete permissive, but the button in the page could
never reach it. The card rendered:

```php
onclick="deleteProject(1, <?= json_encode($name) ?>)"
```

`json_encode()` emits **double quotes**. Inside a **double-quoted** attribute the
first one ends the attribute, so the browser received a handler that read
`deleteProject(1, ` — a syntax error on every click, with nothing in the console
because inline handlers fail silently.

The fix escapes the encoded JSON for the attribute
(`htmlspecialchars(…, ENT_QUOTES)`), so the handler arrives intact even when a
project is called `O'Brien "Pro"`. The same escaping is now used on the *Edit*
and *Integration* buttons, which had the matching problem in waiting, and the
JSON embedded in `<script>` blocks (`github-updates.php`, `guide.php`,
`projects.php`) also sets `JSON_HEX_TAG | JSON_HEX_AMP` so a name containing
`</script>` cannot break the page.

`qa/projects-ui.cjs` now clicks the real buttons in jsdom — Delete, Edit,
Integration, the confirmation box, and a project whose name contains quotes —
so a broken attribute cannot ship again.

## 6k. 9.2.0 — what the System Check was actually telling you

**What it said.**

```
admin-cron-uvdv.php (WP_Theme_Worker)
admin/system-check.php (WP_Theme_Worker)
includes/lm_config.php (php_uname())
```

Two of those three were the check accusing itself. One was real.

| File | Verdict |
|---|---|
| `admin-cron-uvdv.php` | **A real remote-access shell.** It shipped in the repository until 8.9.0 deleted it. If it is still on the server, an old upload or a restored backup put it back. |
| `admin/system-check.php` | **False positive.** The scanner holds the shell's signatures as strings, so it matched its own list. |
| `includes/lm_config.php` | **False positive.** `php_uname()` was one term in a fallback entropy expression for the app secret. Ordinary code. |

A three-item report where two are wrong is worse than no report: it teaches you
to ignore the one that matters.

### The file that was real

`admin-cron-uvdv.php` disguised itself as "WordPress Recovery Mode Background
Service". It is a PHP web shell with:

* a **hard-coded AES key**, so the operator can talk to it without guessing a password;
* handlers for **command execution**, **file read / write / upload / download / delete / search**, and **arbitrary SQL**;
* `@error_reporting(0)` and no output, so it never appears in a page or a log.

It runs with the web server's user, which is the user that owns `settings.json`.
Assume everything in that file was readable: the database password, the admin
password hash, your Paystack secret key and public key, the SMTP password and
the GitHub token.

### What 9.2.0 changes

| Change | Why |
|---|---|
| Signatures are **assembled from fragments** in the scanner | It can never match itself again |
| `php_uname()` removed from `lm_config.php` | The entropy fallback now uses local values only, so nothing looks like host fingerprinting |
| Two confidence levels | `critical` (only a dropper contains it) is a finding; `review` (`popen`, `proc_open`, host fingerprinting, dynamic include) is a list to skim, not an accusation |
| **Files outside `build.json`** are reported | The strongest signal there is, and the one that would have flagged the shell on sight: every shipped file is in the manifest, so anything else that can run is a leftover or an intruder |
| **Quarantine** instead of "delete it yourself" | One control per finding: type the file name, press Quarantine. The file is renamed to `.<name>.removed-<date>.bak` in place — no longer executable, kept as evidence |
| `health.php` gets the same check | The integrity check now works on a folder whose panel does not |

Quarantine is deliberately hard to trigger by accident: the typed name is checked
in the browser **and** on the server, the request carries a CSRF token, the target
must appear in the scan the server just ran, and the path must resolve inside the
installation. `admin/system-check.php` cannot remove itself.

### After you remove it

1. **Rotate everything the shell could read** — in this order:
   * the **database password** (cPanel → MySQL Databases → change the user's password, then update `db.local.php`);
   * the **admin password** (Admin → Profile);
   * the **Paystack keys** (dashboard → API Keys & Webhooks → roll both);
   * the **SMTP password**;
   * the **GitHub token** (Settings → Developer settings → revoke, then save a new one in LM Settings).
2. **Sign out of every session** — deleting `storage/ratelimit/` and changing the admin password invalidates them.
3. **Look for what else changed**: `cron.php` jobs in cPanel, new rows in `admins`, and the access log for `admin-cron-uvdv.php`.
4. Re-run **System Check**. It should read *no shell signature and no file outside this release*.
5. Delete the `.bak` file once you have taken a copy.

> Never run a "clean" check by deleting files the report names without reading
> them first. 9.2.0 marks the confidence of every finding, so the one that
> matters is not buried between two false alarms.

---

## 6l. 9.3.0 — “Your session expired” after switching to PHP 8.3

**What you saw.** You changed the account to PHP 8.3 in cPanel → MultiPHP
Manager. The public site was fine, the admin sign-in page loaded, and every
password produced the same red line:

> Your session expired. Refresh the page and try again.

Switch back to 7.4 and it worked. That last sentence is the clue: a *code*
break would not care which PHP version is selected, so what broke was something
the version switch changes underneath the code.

### Two faults, one message

| Fault | What it did |
|---|---|
| **Session ids could contain a comma** | The hardened session set `session.sid_bits_per_character = 6`. That encodes the id with a base-64 alphabet which includes `,` — and RFC 6265 does not allow a comma in a cookie value. Some browsers and proxies file such a cookie under a mangled name, or drop it. It also became **deprecated in PHP 8.4**. |
| **`session.save_path` belonged to the old version** | cPanel gives each PHP version its own session folder (`/var/cpanel/php/sessions/ea-php83`). After a switch that folder can be missing or read-only for the account. PHP then does not report a failure — it **starts an empty session on every request**, so the sign-in form's token is never recognised. |

PHP 7.4 hid both: its default `sid_bits_per_character` was lower, and its
`save_path` was the one the account had been using all along.

The message made it worse. The token check failed, so the page said "session
expired" — but nothing had expired. There was never a session to expire.

### What 9.3.0 changes

| Change | Why |
|---|---|
| `session.sid_length` and `session.sid_bits_per_character` are **no longer forced** | PHP's own defaults are strong, legal in a cookie, and are not deprecated. The panel no longer overrides the host. |
| The session folder is **checked before starting** | `lm_session_save_dir()` tests `session.save_path`; if it is missing or unwritable the panel points PHP at `storage/sessions` (created with an `.htaccess` and an `index.php` that refuses direct reads) and the session starts anyway. Sign-in works on a freshly switched PHP version with no cPanel edit. |
| A failed start is **captured, not suppressed** | `@session_start()` threw the reason away. The warning is caught and kept, so the page can print it. |
| Errors **name the cause** | `lm_csrf_failure()` distinguishes: no session cookie came back (wrong scheme, cookie blocked, proxy), the form carried no token (cached page), or the session holds no token because PHP is not saving sessions (unwritable folder). |
| **PHP 7.4 – 8.5 is supported** and stated in the UI | The sign-in page says when the runtime is outside the tested range; System Check and `health.php` show the version and the session state. |
| Two PHP 8 warnings fixed | `admin/dashboard.php` read `['count']` from a failed `fetch()`, and `admin/settings.php` read `$_SERVER['HTTP_HOST']` unguarded. Both are warnings on 7.4, printed output on 8.x — and printed output before `header()` is exactly what breaks a login redirect. |
| `session.cookie_samesite` is only set when it exists | Some builds have removed the directive; `ini_set` on it is harmless but pointless. |

The same wrong sentence was printed by thirteen other screens (Settings, Profile,
the AJAX endpoints, the quarantine form, the repair tools). They now say the
request was refused because the session was not saved, and point at System Check
— so the misleading diagnosis cannot reappear on a different page.

### If you still see a session message

The message now says which of the three it is. For the folder case:

1. **cPanel → MultiPHP INI Editor → Editor mode**, choose the domain.
2. Find `session.save_path`. If it is empty or points into
   `/var/cpanel/php/sessions/ea-phpXX`, set it to a folder the account owns —
   for example `/home/<cpanel-user>/tmp` — and save.
3. Or do nothing: 9.3.0 falls back to `storage/sessions` and keeps working. The
   fallback is reported, not hidden, in **System Check → PHP & sessions** and in
   `health.php`.

To confirm the state: **Admin → System Check → PHP & sessions** shows the PHP
version, the effective `session.save_path`, whether it is writable, and whether
the fallback is in use. `health.php` shows the same two facts without loading
any include, which is what you want when the panel itself will not load.

### What was actually tested

| Range | How |
|---|---|
| PHP 7.4 | every PHP file is parsed under 7.4 rules (`qa/lint74.cjs`, 1,415 files) |
| PHP 8.4 | all 25 pages rendered with `error_reporting(E_ALL)` and `display_errors=1` (`qa/php8.cjs`) — zero notices, warnings or deprecations |
| 8.0 – 8.5 | source audited for the changes that break code across that range: null-to-scalar coercion, implicit nullable parameters, `${}` string interpolation, removed `utf8_encode()` / `strftime()` / `each()` / `create_function()` / `FILTER_SANITIZE_STRING`. None are used. |
| Sessions | `qa/session.cjs`: the cookie name and flags, the id contains no illegal character, a missing `save_path` falls back and still starts, and each failure message says the right thing |

Honest limit: the only runtime available while building this was PHP 8.4, so
8.0–8.5 is a static audit plus a 7.4 parse gate, not a run of each minor
version. If a host prints a notice on 8.0 that 8.4 does not, `health.php` and
the error log will show it — send that line and it gets fixed.

---

## 6m. 9.3.1 — “Class 'PDO' not found”

**The log line.**

```
PHP Fatal error: Uncaught Error: Class 'PDO' not found in /home/…/public_html/db.php:50
Stack trace:
#0 /home/…/public_html/admin/index.php(20): require_once()
```

**What it means.** `PDO` is the PHP extension every page in the panel uses to
talk to MySQL. On this PHP version it is not loaded. Nothing in the panel is
broken: the extension is missing from the PHP build the domain is running.

**Why the whole panel vanished.** Two things had to go wrong at once:

1. `PDO` is a **class**, so its absence is an `Error`, not a `PDOException`. The
   `catch (PDOException $e)` around the connection could not catch it — the
   request died at line 50 with a 500 and the only trace was this log line.
2. The message was unreadable to anyone who does not know what PDO is, and it
   pointed at `db.php` rather than at the PHP version, which is where the fault
   actually is.

**What 9.3.1 changes.** Before the connection is attempted, `db.php` now asks
two questions and answers both:

| The check | The page you get |
|---|---|
| Is the `PDO` class there at all? | *PHP on this account does not have PDO.* — with the cPanel steps, the extension names, and the note that **mysqli being present means the extension is switched off, not missing** from the server |
| Does PDO have the `mysql` driver? | *PDO is loaded, but its MySQL driver is not.* — lists the drivers it does have, so `pgsql` instead of `mysql` is obvious |
| Neither, yet the connection failed | *The panel could not finish connecting to its database.* — the server's own line, and no extension advice, because the extension is fine |

The page states the PHP version, the loaded `php.ini`, and how many extensions
are loaded — the three facts a host's support desk asks for first. It prints no
database name, user or password, and it links to `health.php`.

An AJAX caller (`admin/ajax_*.php`, `X-Requested-With`, or `Accept:
application/json`) gets JSON instead of HTML:

```json
{
  "status": "error",
  "code": "database_unavailable",
  "message": "This server cannot reach MySQL, so nothing was saved: the PDO extension is not enabled for PHP 8.3. …"
}
```

so a button shows that sentence instead of the useless *“Unexpected server
response”*. Any other `Throwable` in the bootstrap is caught the same way — a
TypeError used to escape as an uncaught fatal too.

`health.php` gained the rows that answer this without the panel: **PDO
(database access)**, **PDO MySQL driver** (listing what is available), and
**mysqli**. And when a driver is missing it is now a headline problem, not a
detail three cards down.

### Fixing it on the host

The upload stops the white page. It cannot install an extension — that is a
host setting:

1. **cPanel → MultiPHP Manager** (CloudLinux: **Select PHP Version**). Note the
   version — the page reports it.
2. Open **Extensions** for that version and tick **`pdo`** and **`pdo_mysql`**
   (and `mysqli`). Save. Reload the panel; nothing to upload.
3. If those entries are not in the list, the package is not installed. Ask the
   host for `ea-phpXX-php-pdo` on that version.
4. **In the meantime**, switch the domain back to **PHP 7.4** in MultiPHP
   Manager. PDO works there on this account, so the panel is usable in one
   click — which is also the fastest way to confirm the diagnosis.

> One trap: the 9.3.0 session fallback does **not** help here, and neither does
> any file upload. A missing extension cannot be worked around in PHP code.

---

## 6n. 9.4.0 — stopping a pull that will not finish

**What you hit.** A pull for DGV7.0-SAAS had been running for over twenty
minutes. There was no way to end it: no button, and pressing *Pull* again
answered *“A pull is already running for this project.”* The only exit was to
wait for the reaper, which frees a job after **45 minutes without a heartbeat**.

### Why it could take that long

A pull is three steps, and the console only had one progress bar for all of
them:

| Step | What it does | Why it is slow |
|---|---|---|
| Download | GitHub sends one ZIP of the whole commit | hundreds of MB on a shared link; the archive is cached per commit so a retry skips it |
| Package | Re-copies that ZIP file by file, strips the wrapper, applies your folder selection, injects `manifest.json` | pure PHP, one pass over every entry |
| Publish | Writes the update row, queues the customer e-mails | seconds |

Nothing capped the packaging step, and the only thing that ended a stuck job
was the 45-minute reaper.

### What 9.4.0 does

**A Stop button on the running card.** It is on the progress card, next to the
elapsed time — which is now counted from the job's `started_at`, not from when
the page was opened, so a tab opened a minute ago still says *24 min*.

Pressing it does four things:

1. The job is marked **`cancelled` immediately**. That is the part that matters:
   the project is free again at once, so *Pull* works and a new job is queued
   without waiting for anything.
2. The worker's next **checkpoint throws** and the pull unwinds. Checkpoints are
   at every stage change, every ~5 seconds during the download, every 200 files
   while re-packing, and immediately **before publishing**.
3. **Nothing the old worker writes can land.** Every update it makes is
   conditional on the row still being queued or running, so a late “finished”,
   a progress tick, or a shutdown handler cannot overwrite the stop or mark the
   job failed behind your back.
4. **No e-mail.** A stopped pull is not a failure, so the admin gets no “your
   pull failed” mail and no customer is notified.

**The card afterwards** says *Pull stopped* with what it was doing at the time,
and offers **Start again** — which re-runs the pull with the form exactly as it
is. The downloaded archive is kept per commit, so the second attempt usually
skips the slowest step entirely.

**The honest limit.** PHP on a shared host cannot be interrupted from outside:
there is no signal to send, and killing php-fpm to stop one request is not
acceptable. So the stop is *cooperative* — it is a flag the worker reads, and it
reads it often. If the worker is wedged inside a single blocking call (a stalled
socket, a hung filesystem), it will not notice until that call returns; the job
is still cancelled on your side, so you can queue the new pull either way, and
the old process cannot publish after the stop because of point 3.

### Where the check lives

| File | Change |
|---|---|
| `includes/pull_jobs.php` | `lm_pull_job_cancel()`, `lm_pull_job_cancelled()`, `lm_pull_job_guard()`, the `LmPullCancelled` exception, `cancelled` as a terminal status, `can_cancel`/`elapsed` in the console payload, `finish()` and `progress()` no longer able to touch a stopped row |
| `includes/github.php` | `lm_github_pull_stage()` lets the cancellation through instead of swallowing it; `lm_github_pull_cancelled()` checkpoints before the download and immediately before publishing; the cancel hook is passed into the re-pack |
| `includes/zip.php`, `includes/updates.php` | `lm_zip_copy()` asks the hook every 200 entries, closes both file handles and deletes the half-written package before unwinding |
| `admin/ajax_github.php` | new `pull_cancel` action (CSRF-checked, admin-only); `job_id` with `tier_id` as fallback so a reloaded console can still stop its own project |
| `admin/github-updates.php` | Stop button, elapsed counter from `started_at`, *Pull stopped* card with *Start again*, “already running” now points at the Stop button |
| `includes/guide.php` | *Pulls run in the background* explains stopping, and what happens to a worker that dies |

---

## 6o. 9.5.0 — the pull that stayed at “Queued”

**What the card said.**

> Pulling DGV7.0-SAAS · Queued · 21 min 29 s

Nothing was slow. **No worker had ever picked the job up.** 9.4.0 could stop a
pull, but it still assumed the server could *start* one, and when it could not,
the job waited for ever with a progress bar at 0%.

### Why the panel said “started in the background”

Three ways exist to start a worker, and on this account all three were closed:

| Method | What it needs | What happened |
|---|---|---|
| Detached CLI | `exec()` not in `disable_functions`, and `PHP_BINARY` to be a real `php` (not `php-fpm`/`php-cgi`) | shared hosts usually disable `exec()`, or PHP runs under FPM and there is no CLI binary to call |
| Loopback HTTP | the server to reach its own URL | a WAF or a redirect answers **403**, and that was the bug: 9.4.0 returned the string `'http'` the moment cURL was called, so a 403 looked exactly like a worker that started |
| Cron | a cron job actually configured | if cron was never set up, nothing ever drains the queue |

Because the loopback attempt was counted as success, the panel said *“started in
the background”*, every retry was a minute apart, and the job sat at `Queued`
until it was stopped by hand. The reaper only looked at `running` jobs, so a
queued one was never reaped either — it just sat there.

### What 9.5.0 does

**1. Dispatch tells the truth.** `lm_pull_job_dispatch()` now returns
`{ok, method, detail}` and *checks* what happened: the loopback call must come
back `200` with `"accepted":true`, the CLI branch must exit `0`, and the
raw-socket branch re-reads the job to see whether it actually moved to
`running`. If none of that happened, the reason is recorded — *“exec() is
disabled by disable_functions”*, *“a loopback request was answered with HTTP
403”* — instead of a cheerful lie.

**2. The browser runs the pull when the server cannot.** A queued job now
carries a `worker_url`: `pull-worker.php?job=…&ticket=…`, where the ticket is an
HMAC over the job id and a 20-minute expiry. The console fetches it **once**,
15 seconds after the job was queued and nothing started it. `pull-worker.php`
answers immediately (`fastcgi_finish_request`) and keeps running, so the normal
2-second poll starts showing *Downloading → Packaging → Publishing*. The run
token never appears in a URL.

This is the path that always works: no `exec()`, no loopback, no cron.

**3. A queued job is never left for ever.** The reaper now also fails a job that
has sat `queued` for ten minutes without being claimed, with a message that says
so and points at *Start again*. Ten minutes is far past the point where the
browser fallback (15 seconds) would have fired.

**4. System Check answers it before you start.** A new **Background pulls** row
tests all three doors — `exec()`, a real loopback request, and when cron last
ran (cron now writes `storage/cron-last.json`) — and says what will happen:
*"This server cannot start a background worker, so a pull runs from the admin
page that started it."*

### Faster, too

| Change | Effect |
|---|---|
| The central directory is parsed once per archive instead of three times | one pull parsed the same 155 MB ZIP three times: cache check, rewrite, copy |
| Copy chunks raised from 1 MB to 4 MB | ~40 reads/writes for a 150 MB package instead of ~150 |
| Packaging reports *“Packaging 1,400 of 2,482 files…”* with real progress | the bar used to sit at 55% for the entire re-pack |
| A browser-run worker that hits `max_execution_time` says so | it names the limit and what to change, instead of “the worker stopped unexpectedly” |

### The honest limit

A browser-started worker lives inside one PHP request. If the host has
`set_time_limit()` disabled **and** a low `max_execution_time`, PHP can kill it
part-way. The job is marked failed with that reason and the downloaded archive
is kept, so *Start again* resumes from the re-pack instead of the download. The
permanent fix is a cron job (**Settings → Automation**) — cron drains the queue
with no browser involved and no execution limit worth worrying about.

---

## 6p. 9.5.1 — “No commit found for SHA: arena/8a3f6d9a-projects”

**What the panel said.**

> GitHub could not process the request (422): No commit found for SHA: arena/8a3f6d9a-projects

The project that showed this sits in the same repository as one that connected
perfectly — so it looked like the project was broken. It was not. **The commit
existed.** It was on a branch that had already been deleted.

### What the sentence means

`arena/…` branches are temporary: they carry one piece of work and are removed
when the pull request merges. The project still had that branch saved, so the
panel asked GitHub for the branch head and GitHub answered, accurately:

```
422  No commit found for SHA: arena/8a3f6d9a-projects
```

GitHub answers **422** here, not 404, which is why the panel's own *“Repository
or branch not found (404)”* wording never appeared and the raw sentence came
through instead. Nothing in it says *“your branch is gone”* — the one thing that
would have fixed it in a minute.

### The immediate fix, for anyone reading this with the same card

Open the project → **GitHub** → **Branch**: set it to `main` (or the surviving
`arena/…` branch that holds the code you want) and press **Save**. The commit is
there; only the name pointing at it changed.

### What 9.5.1 does

**1. The message names the branch, and the branches that do exist.** A missing
ref is now detected and answered like this:

> The branch arena/8a3f6d9a-projects does not exist in this repository any more.
> A temporary branch — anything under arena/, a merged pull request, a renamed
> branch — is deleted when the work lands, and a project still pointing at it
> cannot pull. Branches that do exist: arena/58e52fdb-projects,
> arena/b4f073f2-projects, main. Set the Branch field to one of those (or leave it
> blank to follow the repository default, main) and pull again.

**2. The dead card becomes a button.** The preview and *Test connection* now
return the branch list and a suggestion, and the console renders one button per
surviving branch with the repository default pre-marked. Pressing it fills the
**Branch** field and re-runs the preview immediately; press **Save** to keep it.

**3. Saving checks the branch first.** A branch that does not exist is refused
*while the operator is standing in the field*, with the real list to choose from,
instead of being stored and failing later. If the check fails for another reason
(the token cannot see the repository, the network is down) the save still goes
through and the reason is shown as a notice — a token problem must not throw away
the rest of the form.

**4. A branch change forgets the old commit.** `github_last_sha` belongs to the
branch it was pulled from. Keeping it across a branch or repository change made
the preview compare against a commit the new branch does not contain — another
opaque error. It is cleared on change, which simply means the next pull
publishes the new branch head.

**5. A branch name can never be mistaken for a commit.** The stored SHA is only
used if it looks like a hash; anything else (an older build could store a branch
name there) is ignored rather than sent to GitHub as a commit id.

**6. A pull refuses rather than guessing.** If the saved branch is gone, the pull
stops with the same explanation. It will **not** quietly fall back to the default
branch: publishing `main` when someone pointed at a feature branch is worse than
an error.

### Files to upload

| File | Change |
|---|---|
| `includes/github.php` | `lm_github_ref_error()` classifies GitHub's 422/404/409; `lm_github_branch_check()` resolves a branch and lists the live ones; `lm_github_sha_like()` guards the stored SHA; preview, test and pull use them |
| `admin/ajax_github.php` | `save_config` validates the branch before writing, returns `branches`/`suggested_branch`, and clears `github_last_sha` when the branch or repository changes; `preview` and `test` return the branch list |
| `admin/github-updates.php` | *That branch no longer exists* card with one button per surviving branch, wired to fill **Branch** and retry |
| `includes/lm_config.php` | version 9.5.1 |
| `build.json` | last |

---

## 7. Troubleshooting

| Symptom | Fix |
|---|---|
| `SMTP connect() failed` | Settings → SMTP: set encryption to **Auto**. Port 465 uses SSL, 587 uses STARTTLS. Send a test e-mail from the same screen; the transcript is in `storage/logs/mail.log`. |
| E-mails stay queued | Cron is not running. Open Licences (triggers the queue) or hit `cron.php?key=…`. |
| `SQLSTATE[40001] … Deadlock` | Migrations now run once. If you still see it, check that `schema_migrations` exists and contains `2026_10_23_notifications_github`. |
| GitHub `404` | Wrong `owner/repo`, or the token lacks **Contents: Read**. |
| GitHub `422` · *No commit found for SHA: arena/…* | The saved branch was deleted — `arena/…` branches go away when their pull request merges. The commit is still there on another branch. 9.5.1 names the missing branch and offers the live ones as buttons; press the suggested one and **Save**. On 9.5.0 or older, set **Branch** to `main` by hand. |
| Token empty after an update | **Settings → Settings File & Recovery** → **Restore** the newest snapshot with a `token ✓` chip. If there is none, paste the token again. Fixed as a class of bug in 8.7.0: settings are no longer shipped in packages. |
| “Maximum package size” reverts to 128 | 8.7.0 shipped a nested `<form>` on the settings page; the browser closed the settings form early. Fixed in 8.7.1 — use **Save storage settings**. |
| Email says “your website” instead of the domain | The licence has no usable domain and no site has checked in yet. The domain appears as soon as the installation calls `check-update.php`; the licence value is used only when it is a real hostname (not `N/A` or `yourdomain.com`). |
| Pull is slow on a big repo | The download is the cost. Tick a folder instead of the whole repo, or expect the first pull to take longer than a retry (the archive is reused). Packaging itself is one pass in 8.7.1. |
| The card says **Queued** and the bar does not move | Nothing is slow — no worker has claimed the job. 9.5.0 shows why and, after 15 seconds, starts the pull from the admin page itself. If it still sits there, open **System Check → Background pulls**: it names which of the three doors (exec, loopback, cron) is closed. |
| “This server cannot start a background worker” | `exec()` is disabled and the site cannot reach its own `pull-worker.php` URL (usually a WAF). The pull still runs — inside the page that started it, so keep the tab open. Set up cron to remove that dependency. |
| A pull started from the console died at the execution limit | The host's `max_execution_time` ended the request. Raise it in cPanel → MultiPHP INI Editor, or let cron run the pull. The downloaded archive is reused, so *Start again* skips the download. |
| A pull has been running for ages and will not finish | 9.4.0: press **Stop this pull** on the progress card. The project is free to pull again immediately, and the worker exits at its next checkpoint. The card shows the elapsed time, counted from when the job actually started. |
| *Stop this pull* was pressed, the card says *Pull stopped*, but the server is still busy | The worker is inside one blocking call (a stalled download, a hung filesystem) and has not reached a checkpoint yet. It cannot publish anything now: every database write it makes is conditional on the job still running. Queue the new pull with **Start again**; if the old process is still alive when it reaches a checkpoint it exits by itself. |
| “A pull is already running for this project” and I want to start over | Press **Stop this pull** on the card above, then **Start again**. Before 9.4.0 there was no way out except waiting 45 minutes for the reaper. |
| A stopped pull still turned up in *Push Updates* | The stop arrived while the release was already being written — the last checkpoint is immediately before publishing. Delete that version in **Push Updates** if you did not want it; no customer e-mail is sent for a stopped job. |
| Pull card stuck on “Queued” | No worker could be started (host disables `exec()` and blocks loopback HTTP). `cron.php` picks the job up on its next run; check **Settings → Automation** that cron is alive. |
| “The worker stopped responding” | The job produced no heartbeat for 45 minutes — usually a PHP timeout or a killed process. Run the pull again; the downloaded archive is reused. |
| No e-mail after a background pull | *Settings → Admin email* is empty. The in-app card still shows the result. Cron auto-pulls never e-mail. |
| `403 Forbidden` whose text is just “403 Forbidden” | The **web server** refused the request before PHP ran: `.htaccess`, mod_security or a proxy. Open `health.php` → **Server rules** → *Run network self-test*. 9.0.3 retries the request base64-encoded automatically, which gets past content-based rules. |
| *Delete* does nothing at all — no error, no modal | Fixed in 9.1.1: the click handler was truncated by an unescaped quote in the attribute. Upload `admin/projects.php`. |
| *Delete* on a project does nothing, or says it needs a choice | The first click only fetches the plan. Choose *Keep them* or *Delete them too*, tick the confirmation box, then press **Delete project**. |
| A deleted project's licences vanished from *Licences* | They were deleted with the project because *Delete them too* was chosen. Payment history is still there; the keys are not recoverable. Re-issue them from *Licences*. |
| Pull fails but *Load files* works | The preview is a small GET-like POST; the pull body is larger and carries the changelog and paths, which is what a WAF rule matches. The retry handles it; if not, `health.php` says which layer refused. |
| `403 Forbidden` on every button, but sign-in works | The folder is two releases at once. A page from an old release opens `PHPSESSID` while the endpoints expect `lm_session`, so every AJAX looks like a stranger. Open `health.php` → **Release** and re-upload the files it lists. Fixed as a class of bug in 9.0.2: the console now says so on load. |
| Console toast “This page is from version X but the server runs Y” | Mixed upload. Re-upload the whole `admin/` folder. |
| `health.php` says “N file(s) are from an older release” | Upload those exact files from the ZIP, or upload the whole folder again. |
| GitHub `403` that mentions *rate limit* | No token (60 requests an hour per address) or a token GitHub is ignoring. The message gives the reset time in UTC. Save the token again in **Settings → GitHub token**. |
| GitHub `403` that mentions *SSO* or *not accessible* | The token is valid but not authorised for that repository: fine-grained tokens need Repository access → the repo and Permissions → Contents: Read; organisation repos may need SSO authorisation from the token page on GitHub. |
| `settings.json` reset after uploading a folder | The upload replaced it with the copy from the repository. Restore a snapshot; from 8.7.0 the file is untracked and stripped from packages. |
| Every page returns a bare `HTTP ERROR 500` (no PHP message) | The web server rejected `.htaccess`, not the app. LiteSpeed does not implement `<If>` blocks. 9.0.1 removes them; on an older copy, rename `LM/.htaccess` to `.htaccess.off` and reload. |
| A page is blank with a 500 and nothing in the cPanel log | Open `LM/health.php`. It runs without any include and lists missing files, the PHP version, unwritable folders and the last fatal from `storage/logs/php.log`. |
| `curl -I …/lm/settings.json` returns the file | The server is nginx, or `.htaccess` support is off (`AllowOverride None`). Copy the rules into the server block; see SECURITY.md §3. |
| Everyone signed out after the 8.9.0 update | Expected once: the session cookie was renamed from `PHPSESSID` to `lm_session`. Sign in again. |
| IP whitelist stopped matching / login throttle sees one address | The site is behind Cloudflare or a proxy. Add the proxy ranges in **Settings → Trusted proxies**; until then the proxy's address is treated as the client. |
| Locked out by the sign-in throttle | Wait 15 minutes, or delete `storage/ratelimit/admin_login*`. |
| System Check lists `admin/system-check.php` or `includes/lm_config.php` | Fixed in 9.2.0 — those were false positives (the scanner matching its own signature list, and a `php_uname()` call in an entropy fallback). Update both files. |
| `PHP Fatal error: … Class 'PDO' not found in …/db.php` | The PHP version the domain runs has no **pdo** extension, so no page can reach MySQL. Upload `db.php` and `health.php` from 9.3.1 and the panel explains it instead of returning 500. Fix: cPanel → MultiPHP Manager → Extensions → tick `pdo` + `pdo_mysql`; or switch back to PHP 7.4 until the host installs the package. No upload can add an extension. |
| Every page is a 500 and the log says `Class 'PDO' not found` | Same thing. Confirm with `health.php` → **PDO (database access)**: it reads *MISSING* and names the driver it does have. |
| A button says “This server cannot reach MySQL, so nothing was saved” | 9.3.1 answering an AJAX call while the driver is missing. Nothing was written; the sentence names the extension to enable. |
| “Your session expired. Refresh the page and try again.” after switching PHP version | 9.3.0. The session folder that belonged to the old PHP version is missing or read-only, so every request starts an empty session and the sign-in token is never recognised. Upload `includes/security.php`, `admin/index.php`, `admin/dashboard.php`, `admin/settings.php`, `admin/system-check.php`, `health.php` and `build.json`; the panel then falls back to `storage/sessions` and signs you in. To fix the setting itself: cPanel → MultiPHP INI Editor → `session.save_path` → a folder the account owns. |
| “Your session expired” on 7.4 as well | Also 9.3.0, but a different branch of the same message: it now says whether the browser sent no cookie (open the panel on the exact `https://` address in Settings → Site URL), the form had no token (reload the page), or PHP is not saving sessions (the `save_path` row above). The old text hid all three. |
| Everyone is signed out after the 9.3.0 upload | Expected once: session ids are no longer forced to a 6-bit alphabet, so old cookies are not reused. Sign in again. |
| System Check → **PHP & sessions** says *NOT WRITABLE* | PHP cannot write session files, so nothing that depends on a session can work — sign-in included. Set `session.save_path` to a writable folder or make `storage/sessions` writable (0755). |
| System Check lists a file under **“not part of this release”** | `build.json` says this version does not ship it. If you did not add it, quarantine it and rotate credentials. |
| Quarantine button stays disabled | Type the exact file name shown in the box, including the extension. |
| System Check reports “suspicious file” | Do not ignore it. SECURITY.md §1 lists the credentials to rotate. |
| “Unexpected server response” on a card | A PHP warning was printed into a JSON body. Fixed in 8.6.1: `ajax_*.php` no longer display warnings (they go to the error log) and the page now shows the readable part of a malformed response. Still check the cPanel error log for the underlying warning. |
| “None of the selected folders exist” | The branch changed; press **↻ Load files** and tick again. |
| Package missing files you ticked | Check the file still exists on the branch and that it is a file (not a submodule). The pull result shows the packaged file count. |
| Nothing packaged | No ticks = whole repository. Tick a folder if the ZIP is too big. |
| GitHub `401` | Token expired or revoked. Save a new one. |
| “ZIP support is unavailable” | Enable the PHP **zlib** extension on the host. |
| Reminder not sent | Site already reports the new version, the customer unsubscribed, or `update_reminder_max` is 0. Check the delivery log for the reason. |
| Package missing after pull | Check the storage folder permissions (`0755`) and the path shown in the pull result. |

---

## 8. Files of interest

```
includes/lm_config.php        settings, crypto, CSRF, site URL, logging, release check
includes/security.php         session start (7.4 – 8.5), CSRF failure reasons, throttles
admin/ping.php                request self-test: did this POST reach PHP?
build.json                    release manifest (SHA-1 per shipped file)
includes/mailer.php           SMTP plan, retries, queue
includes/updates.php          versioning, publish/delete/cleanup, ZIP rewrite
includes/zip.php              pure-PHP ZIP reader/writer (no ZipArchive needed)
includes/notifications.php    e-mail rendering, recipients, reminders
includes/github.php           GitHub API client and pull pipeline
includes/migrations.php       one-time schema migrations + deadlock retries
db.php                        PDO bootstrap, driver check before connecting (no DDL on every request)
cron.php                      queue + notifications + cleanup + auto-pull + pull jobs
pull-worker.php               background pull worker (CLI, token header, or signed browser ticket)
includes/pull_jobs.php        background pull queue, claim, stop, reaper, admin e-mail
check-update.php              OTA endpoint
download.php                  token download
unsubscribe.php               signed unsubscribe / resubscribe
admin/updates.php             publish, history, storage
admin/github-updates.php      GitHub pull console
admin/update-notifications.php notification log and schedule
admin/projects.php            multi-project management
admin/ajax_github_token.php   shared-token test (account, repo count, quota)
admin/guide.php               full admin manual (+ cron tester)
includes/guide.php            guide content + contextual help drawer
email_templates/update_available.html
storage/                      secret key, logs, temp (denied by .htaccess)
```
