# License Manager — security notes (8.9.0)

This release is a security pass over the whole console. Read the first section
even if you skip the rest.

---

## 1. Act now: a web shell was found in the package

`LM/admin-cron-uvdv.php` was not part of the License Manager. It was a
remote-access trojan dressed as a WordPress file (`WP_Theme_Worker_slhi`,
"Recovery Mode Background Service") with a hard-coded AES key and eleven
handlers:

| Handler | What it did |
|---|---|
| `shell` | Run any command via `proc_open`, `popen` or `pcntl_exec` |
| `read` / `write` / `upload` / `download` / `rm` | Full file-manager access |
| `ls` / `find` | Enumerate the filesystem |
| `db` | Connect to any MySQL host and run arbitrary SQL |
| `info` / `ping` | Fingerprint the server |

It answered any request carrying a JSON envelope, and returned a fake 404 to
everything else, so it looked like a broken page in a browser.

It is deleted from the tree in 8.9.0, and `.htaccess` denies the name in case a
backup restores it.

**Because the file was present, assume the credentials on that server are
known.** On every installation that ever contained it:

1. Delete `admin-cron-uvdv.php` (and confirm nothing else new sits in the LM
   folder).
2. Change the **admin password** — from Admin → Profile.
3. Change the **database password**, then update `DB_PASS` (see §4).
4. Change the **Paystack secret and public keys** in Settings.
5. Change the **SMTP password** and the **GitHub token** in Settings.
6. Rotate the **API secret** used by CodeVault, and the **cron secret**
   (Settings → Regenerate).
7. Check the Paystack dashboard for transactions you do not recognise, and the
   admin audit trail in `storage/logs/`.

Then open **System Check → File integrity**: it scans every PHP file in the
tree for the patterns these shells use and lists anything suspicious.

---

## 2. What changed

### Authentication

| Before | Now |
|---|---|
| `admin` / `password` created automatically on first run | A random password, written once to `storage/initial-admin.txt` (0600), or `LM_ADMIN_PASSWORD` if set |
| Unlimited sign-in attempts | 5 per username and 20 per address per 15 minutes, checked *before* the password |
| Session id kept across sign-in | Regenerated on sign-in and on password change |
| Factory password never expires | Sign-in is allowed once, then the panel is locked to the profile screen until it is replaced |
| No CSRF token on the login form | Token required, and the response is identical for unknown users and wrong passwords |

### Sessions and responses

* Session cookie is `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, with
  `use_strict_mode` so an unknown id is never adopted.
* Cookie renamed from `PHPSESSID` to `lm_session` so another app on the same
  host cannot share it. *(Everyone signs in once after upgrading.)*
* Sign-out clears `$_SESSION` and expires the cookie, not just the id.
* `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy` and HSTS-on-HTTPS are sent
  on every page, plus the Apache equivalents for static files.

### Access control

* **CSRF tokens on every state-changing form**: approve/decline a request,
  revoke an auto-login token, toggle a licence, add/edit a licence, run a
  repair, patch ZIP manifests, save the webhook tester, customer profile and
  domain change, the public licence request, and admin login.
* **`request_license.php` no longer signs anyone in.** It used to set
  `$_SESSION['user_email']` from an unverified form field and redirect to the
  dashboard — typing a customer's address was enough to read their licences.
  It now lands on a neutral "request submitted" page.
* **`user/login.php` no longer accepts `?email=…&license_key=…`.** Credentials
  in a URL end up in access logs, `Referer` headers and history; the link could
  also sign a visitor into someone else's account. POST only, throttled, CSRF
  protected.
* **`lm_client_ip()` no longer trusts `X-Forwarded-For` blindly.** This was an
  authorisation bypass: the OTA whitelist compared an attacker-controlled
  header against its list, so `X-Forwarded-For: 127.0.0.1` skipped licence
  validation. The header is now read only when the direct peer is a configured
  proxy, and the chain is walked from the right.
* **`api-verify-payment.php` and `api-check-license.php` no longer hand out
  licence keys to anyone holding a transaction reference.** A reference travels
  in redirect URLs and logs. The key and the auto-login token are returned only
  when the matching customer e-mail is supplied or the request comes from the
  checkout session; otherwise the response says the key was e-mailed, with the
  address masked.
* Settings → **Trusted proxies** validates its input and defaults to empty.

### Abuse limits

| Endpoint | Limit |
|---|---|
| `check-update.php` | 240/hour per address, 30/hour per licence key |
| `download.php` | 60 per 10 minutes per address |
| `admin/index.php` | 5 per username, 20 per address per 15 minutes |
| `user/login.php` | 8 per e-mail per 15 minutes |
| `request_license.php` | 5 per hour per address |
| `api.php` | 120 per minute |
| `api-create-license.php`, `api-upgrade-license.php` | 30 per 5 minutes |
| `api-verify-payment.php` | 30 per 5 minutes per address, 15 per reference |
| `unsubscribe.php`, `user/auto_login.php` | 30 and 20 per 10/15 minutes |

Limits are file-backed under `storage/ratelimit/`, so they work without a new
database table. If that folder is not writable the request is allowed and a
line is written to `storage/logs/security.log` — a read-only disk must not lock
customers out.

### Secrets and logs

* `webhook.log`, `error.log`, `success.log`, `api_debug.log`,
  `api_create_license.log` and `api_upgrade_license.log` are gone. Everything
  goes through `lm_log()` into `storage/logs/`, outside the web root.
* Licence keys are never written in clear text; log lines show `VALID-…`.
* The webhook payload is capped at 256 KB before the signature is computed.
* `settings.json` is still denied by `.htaccess`, and `db.local.php` is now
  ignored by git.

### Files

* Removed `admin-cron-uvdv.php` (see §1) and `includes/get_oauth_token.php`
  (PHPMailer's OAuth sample, which is not meant to be reachable on a
  production host).
* `includes/`, `migrations/` deny all; `uploads/` and `docs/` refuse to execute
  PHP; `uploads/` also rejects `.php`, `.phtml`, `.phar`, `.pl`, `.py`, `.cgi`.
* Logo uploads must be an image extension, at most 2 MB, and an SVG containing
  script is refused.
* Directory listing is off; dot-files and backups are denied.

### Configuration

* `db.php` reads `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` from the
  environment, or from a sibling `db.local.php` that stays out of git. The
  hard-coded constants remain only as a fallback for existing cPanel installs.

---

## 3. Things only you can do

1. **Move the database password out of the source.** Create `LM/db.local.php`:

   ```php
   <?php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'your_db');
   define('DB_USER', 'your_user');
   define('DB_PASS', 'the real password');
   ```

   `db.local.php` is in `.gitignore`.

2. **Check the trusted-proxy setting** if the site is behind Cloudflare or a
   load balancer. With it empty, the IP whitelist sees the proxy, not the
   customer.

3. **Use nginx?** `.htaccess` does nothing there. Reproduce the rules: deny
   `settings.json`, `db.local.php`, `storage/`, `*.log`, `*.sql`, `*.key`, and
   `includes/`, then disable PHP in `uploads/`.

   **Use LiteSpeed?** It reads `.htaccess`, but only the Apache subset it
   implements. `<If>` / `<ElseIf>` / `<Else>` expression blocks are not in that
   subset and make the server reject *every* request in the folder with a bare
   500 — that is what broke 9.0.0 on this host. Keep the file to `<IfModule>`,
   `<FilesMatch>`, `RewriteRule` and `Header`; never `Options`, `DirectoryIndex`
   or expression containers.

4. **Serve the whole site over HTTPS** and redirect HTTP to it. HSTS and the
   `Secure` cookie flag only switch on when the request is already TLS.

---

## 4. Verifying a deployment

**System Check** covers curl, Paystack, SMTP, schema and file integrity.

The test suites used while building 8.9.0 live outside the package, but the
invariants they check are easy to re-run by hand:

* `LM/admin-cron-uvdv.php` does not exist.
* No PHP file in the tree contains `WP_Theme_Worker`, `do_maintenance_cron`,
  `pcntl_exec` or `gzinflate(base64_decode`.
* `grep -rn "session_start()" LM --include=*.php` returns only
  `includes/security.php`.
* `grep -rn "file_put_contents('.*\.log'" LM --include=*.php` returns nothing.
* `curl -I https://your-domain/lm/settings.json` returns 403.
* `curl "https://your-domain/lm/check-update.php?license_key=x&domain=y"` with
  `-H "X-Forwarded-For: 127.0.0.1"` still rejects the licence.
