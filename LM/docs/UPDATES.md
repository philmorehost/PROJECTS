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

1. **Repository** — `owner/repo`, a full `https://github.com/owner/repo` URL, or a
   `…/tree/branch` URL. The branch is picked up from `tree/` URLs.
2. **Branch** — empty means the repository default branch.
3. **Sub-folder** — for monorepos (e.g. `backend`). Only files below it are packaged.
4. **Token** — required for private repositories.

### Choosing what to package (folder tree picker)

Most projects live in **one repository** — this one, for example, holds `LM/`,
`VTU/` and every future project. After saving the repository settings, press
**↻ Load files**: the LM reads the branch tree (one GitHub API call) and shows
it as an expandable tree.

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

Press **Test token** to see the account (`@login`), the token kind, how many
repositories it can see and the remaining API quota before relying on it.
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
`?tasks=mail,notifications,cleanup,github`.

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

## 7. Troubleshooting

| Symptom | Fix |
|---|---|
| `SMTP connect() failed` | Settings → SMTP: set encryption to **Auto**. Port 465 uses SSL, 587 uses STARTTLS. Send a test e-mail from the same screen; the transcript is in `storage/logs/mail.log`. |
| E-mails stay queued | Cron is not running. Open Licences (triggers the queue) or hit `cron.php?key=…`. |
| `SQLSTATE[40001] … Deadlock` | Migrations now run once. If you still see it, check that `schema_migrations` exists and contains `2026_10_23_notifications_github`. |
| GitHub `404` | Wrong `owner/repo`, or the token lacks **Contents: Read**. |
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
includes/lm_config.php        settings, crypto, CSRF, site URL, logging
includes/mailer.php           SMTP plan, retries, queue
includes/updates.php          versioning, publish/delete/cleanup, ZIP rewrite
includes/zip.php              pure-PHP ZIP reader/writer (no ZipArchive needed)
includes/notifications.php    e-mail rendering, recipients, reminders
includes/github.php           GitHub API client and pull pipeline
includes/migrations.php       one-time schema migrations + deadlock retries
db.php                        PDO bootstrap (no DDL on every request)
cron.php                      queue + notifications + cleanup + auto-pull
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
