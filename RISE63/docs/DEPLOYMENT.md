# RISE 63 — Deployment guide

Audience: the person who will put this on the cPanel/LiteSpeed host and keep it running.

**Read this before the first upload.** Section 1 takes ten minutes and prevents the two mistakes that cause most incidents: a public `/config` directory and an unactivated install.

---

## 0. What you need

| Item | Detail |
|---|---|
| Hosting | cPanel or WHM, Apache 2.4 or LiteSpeed, **`mod_rewrite` and `mod_headers` enabled**, PHP 8.2+ as a CGI/FPM handler (not the "PHP as static" legacy handler). |
| PHP extensions | `pdo`, `pdo_mysql`, `json`, `curl`, `gd`, `mbstring` (recommended; polyfills exist), `openssl`, `fileinfo`, `zip` (only for software updates). |
| Database | MySQL 8.0+ or MariaDB 10.6+, one **empty** database, one user limited to that database. |
| Cron | Two entries, every five minutes, plus one hourly. See §5. |
| Domain | A dedicated domain or subdomain, e.g. `rise63.yourdomain.org`. **Do not** point a domain at the repository root: sibling projects would become reachable. |
| Licence | A key from `manager.pmhserver.name.ng`, issued for **this domain**. Create it during setup — the installer links to the order form — or copy an existing one from your account. |
| Certificate | Let's Encrypt or better, installed **before** setup. |

Memory: 256 MB PHP memory limit is plenty; 512 MB is comfortable. Disk: allow 2 GB for documents and logs.

---

## 1. Before you upload (ten-minute checklist)

1. Create the empty database and user in cPanel → MySQL Databases. Note the names; cPanel prefixes them, e.g. `acct_rise63`.
2. Grant **all** privileges on that database only (the installer needs `CREATE`, `ALTER`, `INDEX`, and `TRIGGER` for the ledger guards).
3. Create the subdomain, enable HTTPS (AutoSSL), and force HTTPS in cPanel if you can.
4. Decide the document root. The cleanest layout is:

   ```
   /home/ACCOUNT/rise63/            ← upload the contents of RISE63/ here
   /home/ACCOUNT/public_html/rise63 → symlink or set the subdomain document root to /home/ACCOUNT/rise63
   ```

   If your host insists the document root be `public_html/rise63`, upload there and accept §2 as mandatory.
5. Have the activation token and the administrator e-mail ready.

---

## 2. Upload and permissions

Upload everything **except** the `tests/` and `docs/` folders (keeping them is harmless — they are denied by `.htaccess` — but they add no value on a live server).

```
config/        0750   writable during setup, 0550 afterwards
storage/       0750   writable always (sessions, documents, logs)
storage/*      0750   one directory per purpose, created for you if missing
public/        0755
everything else 0644 files, 0755 directories
```

`config/*.php`, `config/installed.lock` and `config/activation-key.php` are written with mode **0600**. If your PHP user differs from the file owner, `chmod 0640` and add the PHP user to the file's group.

> **Never** run the application with the database root account. Never store the activation token in source control.

---

## 3. Run the installer

Open `https://rise63.yourdomain.org/`. With no `config/installed.lock` the front controller hands off to `install/index.php`.

| Stage | What it does | If it fails |
|---|---|---|
| 1. System check | PHP version, extensions, writable `config/` and `storage/`, HTTPS, activation | Fix each red row; the token can be pasted again. If activation says the domain is not authorized, add the domain in the manager. |
| 2. Database | Connects, refuses a non-empty database, runs `schema.sql` + `countries.sql` + `seed.sql` + the ledger triggers, writes `config/database.php` | The message is deliberately generic. Check the cPanel MySQL log; confirm the user has DDL rights; confirm the database really is empty. |
| 3. Administrator | Creates the first `provincial_admin`, sets the site title (drives the SVG icon), the notification e-mail and the transaction PIN | Password and PIN rules are enforced here, not just in the browser. A rejected PIN (0000/1234/2026…) is explained in the message. |
| 4. Complete | Writes `config/installed.lock` (0600), deletes the activation cache, prints the post-install checklist | If stage 4 is interrupted, re-open the installer: it resumes at stage 4 without touching data. |

**Interrupted stage 2 (important).** MySQL DDL auto-commits, so a half-created schema is possible. Do **not** retry on top of it. Drop the new database's tables with phpMyAdmin (they were all empty) and re-run with an empty database. Never apply this to an installation that already holds real applications.

---

## 3a. Mail decides whether self-service reset exists

The password-reset page checks for `config/smtp.php` and reads its `host`. Two honest outcomes:

| `config/smtp.php` | Member experience |
|---|---|
| present with a host | "Send reset link" — a 30-minute single-use link is e-mailed and queued by the worker |
| absent | The page says self-service reset is unavailable and directs the member to the province office |

Either way, an administrator can issue a temporary password from **People** → *New temporary
password*. That password is shown **once**, never e-mailed, and forces a change at next sign-in.

Requirements if you enable e-mail reset:

1. `config/smtp.php` present, mode `0600`, host reachable from the server.
2. Cron for `bin/mail-worker.php` running (§5) — without it, the link is never delivered.
3. SPF and DKIM published for the sending domain, or the link lands in spam and someone phones you.
4. Send one real reset to a mailbox you control before announcing the feature.

## 3b. The licence, start to finish

RISE 63 is licensed per domain. The check is a server-side POST to the provider's API — a browser
check can be skipped, so it is never trusted:

```
POST https://manager.pmhserver.name.ng/api.php
     key=<your key>&domain=<this domain>
 ->  {"status":1,"message":"License is valid."}              licensed
 ->  {"status":0,"message":"Invalid license key or domain."} not licensed
```

**Getting a key**

1. Open <https://manager.pmhserver.name.ng/order.php> (the installer links to it on stage 1).
2. Enter your e-mail and the exact domain shown in the installer's **Domain on the licence** field,
   which is read from the request and has any `www.` removed. If the order form was completed with a
   different spelling, type that spelling into the field — it is editable.
3. The key arrives by e-mail. Existing customers can copy it from
   <https://manager.pmhserver.name.ng/user/login.php>.

**During setup**

- Paste the key into **Licence key** and press **Check this key first** to see the answer without
  committing — useful if you want to confirm the domain before creating an administrator.
- **Save key and continue** writes `config/activation-key.php` (mode 0600) and moves on.
- Nothing is enabled until the manager returns `status: 1` for this domain.
- **The domain is checked once, at stage 1.** A later stage does not ask the manager again — it
  compares against the domain the manager already accepted, which is recorded in
  `config/activation-key.php`. Earlier builds forced a fresh check when the administrator form was
  submitted; a rate-limited or briefly unreachable manager then blocked a key it had just accepted.

**The key is not in the provider's database**

`api.php` answers `Invalid license key or domain.` for every mismatch, so it cannot tell you that.
`check-update.php` can, and it is read-only for a rejected key:

```sh
curl -sS "https://manager.pmhserver.name.ng/check-update.php?license_key=YOUR-KEY&domain=your-domain"
```

| Answer | Meaning |
|---|---|
| `{"status":"error","message":"Invalid license key."}` | The key is **not in the provider's database at all**. No domain spelling will help: re-copy it, or ask the provider which key belongs to this domain |
| `{"status":"success","latest_version":"…"}` | The key **and** the domain are both accepted, so the refusal from `api.php` is about the licence's state — check it is active in the console |
| `{"status":"suspended",…}` | Suspended by the provider |
| Empty answer / TLS error | The host cannot reach the provider; a firewall question |

`php bin/license-check.php` runs this check for you and tells you which of the four it is.

**A note on two different endpoints**

The API reference documents `api.php` (validate a key). Updates come from `check-update.php`, which
is a separate script on the same host with its own contract, shown in §3c. They behave differently:

- `api.php` registers an unknown domain automatically while the licence has spare domain slots.
- `check-update.php` registers the domain only if it is already listed, otherwise it **rejects** the
  request when the domain limit is reached. It never grants a licence, so it is not a substitute
  for the check in §3b.

**After setup**

| Situation | Behaviour |
|---|---|
| Confirmed within 6 hours | No network call; the cached result is used |
| Manager unreachable, last success < 24 hours | Site keeps running on the grace window, and the banner says so |
| Manager unreachable, never confirmed | Sign-in stays paused — a fresh install cannot run unlicensed through an outage |
| Key invalid or domain changed | Activation page, with links to the order form and to sign in |

Diagnose from the server:

```sh
php bin/license-check.php                      # stored key + this host
php bin/license-check.php --domain=example.org # what the manager says for another domain
php bin/license-check.php --key=NEWKEY --save  # test a new key, keep it only if valid
php bin/license-check.php --probe              # try the likely spellings; says which one is accepted
php bin/license-check.php --probe --save       # ...and record that one, then skip the licence step
```

`--probe` exists because *"Invalid license key or domain"* is the provider's answer to every
mismatch, and the wizard has to send one exact string. It tries the bare domain, `www.`, the parent
domain and (when it is a real domain) the server's own hostname, and prints the provider's answer
for each.

The three outcomes are deliberately kept apart:

| What happened | What the tool says | Exit |
|---|---|---|
| The provider answered and accepted a spelling | Names that spelling; `--save` records it | `0` |
| The provider answered and refused every spelling | The key is unknown, expired, suspended or out of domain slots — not a spelling problem | `1` |
| The provider could not be contacted | **Nothing was decided.** Prints the DNS result and a `curl` command to prove outbound HTTPS | `2` |

That third case matters: an unreachable manager is a hosting firewall question, and reporting it as
a licence refusal would send you off to buy a key you already own.

The output masks the key (first and last four characters only) and never writes it to a log.
Exit codes: `0` licensed · `1` refused · `2` configuration or transport problem.

Hosts without cURL are fine: activation falls back to PHP's HTTPS stream wrapper. If both fail,
run `curl -sS -o /dev/null -w '%{http_code}\n' https://manager.pmhserver.name.ng/api.php` from the
server to prove outbound HTTPS is open.

Check the installation itself — read-only, safe on a live site:

```sh
php bin/doctor.php           # full report; exits 0 only when nothing blocks launch
php bin/doctor.php --quiet   # problems and notes only, for cron or monitoring
php bin/doctor.php --no-net  # skip the licence call (e.g. a maintenance window)
```

`doctor` covers the PHP build and extensions, writable `config/` and `storage/`, the `.htaccess`
guards that stop a browser reading them, key-file permissions, the SMTP host, table and
administrator presence, whether the ledger triggers were installed, the security-e-mail setting and
the licence. It reports rather than guesses: a CLI run cannot see whether HTTPS is on, so it says so
instead of claiming the site is insecure.

## 3c. Getting updates (the licence server, not GitHub)

Upgrades arrive from the provider, not from the repository. Their admin pulls the project from
GitHub and publishes a version; an installation then asks for it by key and domain:

```
POST https://manager.pmhserver.name.ng/check-update.php
     key=<licence key>&domain=<this domain>[&force_version=1.1.0]
 ->  {"status":"success","latest_version":"1.1.0","checksum":"<sha256>","changelog":"…",
      "download_url":"https://manager.pmhserver.name.ng/download.php?token=…","tier_detected":"NON-SAAS"}
 ->  {"status":"suspended"|"error","message":"…"}            HTTP 400 / 403 / 500
```

The download URL is single-use and expires after 30 minutes.

**In the browser:** sign in as a provincial administrator → **Updates** (the ⇪ button in the header).

1. **Check now** asks the provider what is published for this licence. It shows the version, the
   licence tier and the changelog.
2. **Download and review what changes** fetches the archive, verifies its sha256 against the
   manifest, and lists every file that will be added, replaced or removed — plus any SQL. Nothing in
   the installation is touched at this point.
3. **Apply this release** backs up every file it is about to replace, writes the new ones, then runs
   the SQL.

**From a shell** — use this when the admin page cannot be reached, or from a maintenance window:

```sh
php bin/update.php --check     # what is published
php bin/update.php --plan      # download, verify and list what changes
php bin/update.php --apply     # apply what --plan downloaded (asks for confirmation)
php bin/update.php --history   # what has been applied before
```

**What the updater refuses, by design**

| Situation | Behaviour |
|---|---|
| The archive is not from `manager.pmhserver.name.ng` over HTTPS | Refused before a byte is written |
| sha256 does not match what the provider published | Refused; the download is deleted |
| The archive contains `../`, an absolute path, `config/` or `storage/` | Refused; the whole release is rejected |
| The archive would replace `app/Core/Updater.php` or `bin/update.php` | Refused — an update cannot rewrite the thing that installs updates |
| A file cannot be written | Every file already written is restored from the backup |
| A SQL statement fails | The files stay installed, the failure is shown with the exact statement, and it is logged |

**Rolling back by hand.** Backups are in `storage/updates/backup/<UTC stamp>/`, one file per path
that was replaced, next to a `manifest.json` listing them. Copy them back over the installation. The
applied version is recorded in `config/update-state.json`; delete that file to make the version
report read the build's own constant again.

**Never** edit files on the server by hand or copy them from GitHub. The next release assumes it is
upgrading the version it last installed, and a hand-patched tree makes that assumption false.

## 4. Immediately after setup

1. Sign in, open **Security** and confirm the **notification e-mail** is correct.
2. Open `/config/` and `/storage/` in a private browser window. **Both must return 403 or 404.** If they return a directory listing, `AllowOverride` is off: fix the vhost (see §6) or move the private folders above the document root.
3. Open `/favicon.svg`: it must show the first letter of your site title.
4. Open `/manifest.webmanifest` — it must be served as `application/manifest+json` or `application/json` (add a MIME type in cPanel if it downloads instead).
5. Set the **site title**, **current program year** and **Seed Grant pool** in Settings.
6. Create the zones and areas, then the parishes (Parishes → Manage zones and areas).
7. Appoint the `csr_rep` accounts and the `provincial_treasurer`.
8. Ask the treasurer to sign in and set the transaction PIN from **My account**.

---

## 5. Cron

Use the PHP binary path from cPanel ("Select PHP Version → current → binary path"), typically `/usr/local/bin/php` or `/opt/cpanel/ea-php83/root/usr/bin/php`.

```cron
# Mail delivery (transactional queue) — every 5 minutes
*/5 * * * * /usr/local/bin/php /home/ACCOUNT/rise63/bin/mail-worker.php >> /home/ACCOUNT/rise63-mail.log 2>&1

# Firewall queue (only if you configure an adapter) — every 5 minutes
*/5 * * * * /usr/local/bin/php /home/ACCOUNT/rise63/bin/security-worker.php >> /home/ACCOUNT/rise63-fw.log 2>&1

# Housekeeping: retention, expired blocks, stale leases — hourly
0 * * * * /usr/local/bin/php /home/ACCOUNT/rise63/bin/maintenance.php >> /home/ACCOUNT/rise63-maintenance.log 2>&1
```

Exit codes are meaningful: the mail worker exits `1` when every attempted delivery failed; `maintenance.php` exits `2` when the mail backlog exceeds two hours. Wire those into your host's monitoring.

**Without cron** nothing breaks, but nothing is delivered either: notifications accumulate in **Settings → Notifications** with a `pending` badge. That is the honest behaviour; do not claim mail works until the worker runs.

---

## 6. Web server hardening (beyond `.htaccess`)

`.htaccess` is ignored when `AllowOverride None` is set. Put these in the vhost as well:

```apache
<Directory /home/ACCOUNT/rise63>
    Options -Indexes -MultiViews
    AllowOverride All
    Require all granted

    # Private application paths
    RedirectMatch 403 ^/home/ACCOUNT/rise63/(app|config|storage|database|tests|bin|docs)(/|$)
</Directory>

# PHP must never be executed inside uploads
<Directory /home/ACCOUNT/rise63/storage>
    Require all denied
    php_flag engine off
</Directory>
```

Nginx equivalent (if the host uses Nginx in front of PHP-FPM):

```nginx
location ~ ^/(app|config|storage|database|tests|bin|docs)/ { deny all; return 403; }
location ~* ^/storage/ { deny all; return 403; }
location ~* \.(sql|md|lock|log|json|sh)$ { deny all; return 403; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php-fpm.sock; }
```

**Uploads.** Documents live in `storage/documents` with random 32-hex prefixes and are only served through `index.php?action=document-download`, which re-checks the signed-in user's scope. There is no public URL that maps onto a stored file.

**Trusted proxy.** If HTTPS terminates at Cloudflare or a load balancer, create `config/trusted_proxies.php`:

```php
<?php
return ['173.245.48.0/20', '103.21.244.0/22', '2400:cb00::/32']; // Cloudflare ranges, trimmed to what you use
```

Until that file exists, the application ignores every `X-Forwarded-*` header and uses `REMOTE_ADDR`. That is why a proxy without this file makes every visitor look like one IP — a self-inflicted brute-force lockout.

---

## 7. Country filtering

`database/countries.sql` seeds all 250 ISO codes as **not specified** and whitelists **NG**.

Three resolution modes, set in `config/geo.php`:

```php
<?php
return ['mode' => 'header', 'header' => 'CF-IPCountry']; // behaviour: trusted-proxy header
// return ['mode' => 'table'];                            // operator CIDR table (geo_ip_ranges)
// return ['mode' => 'off'];                              // country filtering disabled
```

* `header` works on Cloudflare without extra data, but only when the request provably arrives from a trusted proxy address (§6).
* `table` suits hosts that supply a country list you can import into `geo_ip_ranges` (`cidr_start`, `cidr_end`, `country_code` as integers).
* If the country cannot be determined, the **Unknown country policy** setting decides: `allow` (default) or `deny`. Denying unknown countries will lock out members on shared mobile networks — turn it on only with evidence.

Every refusal is written to the security log with the reason (`country_blocked`), so a false positive can be explained.

---

## 8. Firewall integration (optional)

Application-level blocks work immediately with no server access. Real firewall blocks need an adapter:

```php
<?php // config/firewall.php
return [
    'adapter' => 'command',
    'command' => '/usr/local/csf/bin/csf -d %s -t', // %s is replaced by the escaped IP only
];
```

`bin/security-worker.php` then applies queued jobs. Safety rails are in the worker, not in your hands: the IP is validated with `filter_var`, whitelisted and loopback addresses are skipped, the command must be an absolute path containing `%s`, each job is attempted at most three times, and expired blocks generate automatic `unblock` jobs. Do not expose `csf` to the web user beyond this path; test on a staging vhost first.

---

## 9. Performance

1. Enable OPcache in cPanel → Select PHP Version → Options: `opcache.enable=1`, `opcache.memory_consumption=128`, `opcache.max_accelerated_files=10000`, `opcache.validate_timestamps=1` (keep revalidation on; turn it off only if you have a deploy hook that reloads PHP-FPM).
2. Enable gzip/Brotli (`.htaccess` already asks for `mod_deflate`).
3. Run `php bin/build-assets.php` after any change to `public/assets/*`. It writes fingerprinted copies, `manifest.php` and the service-worker shell list. Only fingerprinted files are cached for a year.
4. Persistent PDO connections are **off by default**. Set `RISE63_DB_PERSISTENT=1` in the PHP environment only after confirming your FPM pool does not leave connections mid-transaction (a crashed request can). The single largest speed win is OPcache plus the asset build, not persistent connections.
5. Sessions are stored in `storage/sessions`. On a single server that is correct; for multiple web nodes use a shared session handler first (documented gap).

---

## 10. Backups & restore

| What | How often | Retention |
|---|---|---|
| Database | hourly (cPanel backup or `mysqldump --single-transaction`) | 14 days hourly + 12 months monthly |
| `storage/documents` | daily | 90 days |
| `config/` (contains the activation token and DB credentials) | on change, encrypted | keep the last 5 |

Restore drill (do it once before launch): restore the database into a scratch schema, point a staging copy at it, sign in, and confirm an application's timeline and the ledger total match the backup date. A backup you have never restored is a hope, not a backup.

`remittance_ledger` is append-only at the database level (triggers reject `UPDATE` and `DELETE`). If your host denies `CREATE TRIGGER`, the installer continues and says so — the application still never updates the ledger, but the structural guarantee is missing. Note it in your hand-over.

---

## 11. Updating the application

Treat every update as a five-minute maintenance window, even when the release looks harmless.

```sh
# 1. Back up first. No exceptions.   (§10)
# 2. Upload the new files over the old ones. Never delete config/ or storage/.
# 3. Check what the schema will do — this changes nothing:
php bin/migrate.php --status
php bin/migrate.php --dry-run
# 4. Apply, once the output above has been read:
php bin/migrate.php
# 5. Housekeeping, so expired locks and leases are cleared under the new code:
php bin/maintenance.php
# 6. Only if the release notes say the shell changed:
php bin/build-assets.php
```

Notes that matter:

* `bin/migrate.php` exits **3** when a migration file changed after being applied. Do not work
  around that; the fix is a new migration file, not an edited one.
* Migrations are not one atomic unit: MySQL commits DDL immediately, so a failure part-way through
  a single file leaves that file half applied and *not* recorded. The message says so. Read the
  file, finish what is missing by hand, then insert its version into `schema_migrations` yourself
  (`INSERT INTO schema_migrations (version, checksum) VALUES ('0002', '<sha256 of the file>')`).
* Bump `SHELL_VERSION` in `sw.js` when shell assets change: installed clients then show the
  "Reload" toast instead of running a stale shell.
* Take the site out of maintenance only after step 5 succeeds; otherwise refresh the sign-in page
  in a private window and confirm you get a login form rather than an error page.

---

## 12. Incident playbook

| Symptom | Likely cause | Action |
|---|---|---|
| Everyone is locked out | An untrusted proxy makes all visitors look like one IP, or `unknown country` is denied | Check **Security → blocked addresses** (one IP, many usernames is the signature). Add the proxy to `config/trusted_proxies.php`, or whitelist the address, and set the unknown-country policy back to allow. |
| "Activation required" page | Key revoked, domain changed, or outbound HTTPS blocked | Run `php bin/license-check.php` — it prints the masked key, the domain, the transport and the manager's answer. Check the domain on your order form matches exactly (no `https://`, no `www.`), and that the server can reach the endpoint. A site that was confirmed in the last 24 hours keeps running while the manager is unreachable. |
| 503 for everyone | `storage/maintenance.json` present or `maintenance_mode` on | Remove the file / switch the setting off. Administrators are not blocked by maintenance mode. |
| No e-mails | cron not installed, or SMTP unconfigured | **Settings → Notifications** shows queue state; run `php bin/mail-worker.php --verbose` manually and read the error. |
| Blank page / 500 | PHP fatal | Read `error_log` in cPanel. `RISE63_DEBUG=1` in the PHP environment adds the exception message to the error page — never leave it on. |
| Installer says "already installed" | `config/installed.lock` exists | Correct: it is a lock. Do not delete it on a live site. |

**Removing the installation lock** is only legitimate on a copy you are discarding. On a live site it reopens the wizard and is a security incident.

---

## 13. Hand-over checklist

- [ ] HTTPS forced; secure cookies confirmed in the browser.
- [ ] `/config/`, `/storage/`, `/database/`, `/app/` all return 403/404.
- [ ] Installer locked; `config/installed.lock` mode 0600.
- [ ] Notification e-mail receives a test message from the mail worker.
- [ ] Three cron entries installed; the mail worker has run at least once.
- [ ] Zones, areas and every parish loaded; representatives appointed.
- [ ] Treasurer has a PIN; a test disbursement was recorded and then reversed only through a documented adjustment.
- [ ] Backups scheduled **and** a restore tested.
- [ ] Two administrators named; each knows how to suspend the other.
- [ ] `docs/IMPLEMENTATION_STATUS.md` read: what is verified and what is still a gap.
