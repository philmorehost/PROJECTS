# DGV7.0 post-hardening HTTP 500 investigation

**Date:** 2026-10-24  
**Affected editions:** `DGV7.0-SAAS`, `DGV7.0-NON-SAAS`  
**Trigger:** security commit `ee9edeea0194e8f2397ad6edef5f1257e69d6b6a` (`fix(security): comprehensive security audit and bug fixes across both editions`)

## Summary

Both editions began returning **HTTP 500 Internal Server Error** because the security commit deployed an identical root `.htaccess` file to both. That file ended with:

```apache
<DirectoryMatch "^.*(backups?|logs?|tmp|temp|debug|cache)/">
    Options -Indexes
    Require all denied
</DirectoryMatch>
```

`<DirectoryMatch>` is valid only in Apache server configuration or a virtual host. It is **not valid inside `.htaccess`**. When Apache/LiteSpeed parses an `.htaccess` file containing it, the request fails config validation before PHP is started, producing a 500 for the whole document root. This explains why both editions failed at the same time: the SAAS root file was newly added and the NON-SAAS root file was replaced by the same 97-line contents in the security commit.

Reference: Apache 2.4 directive context for `<DirectoryMatch>` is server config / virtual host, not `.htaccess` ([Apache core docs](https://httpd.apache.org/docs/2.4/mod/core.html#directorymatch); [same 500 failure mode](https://stackoverflow.com/questions/40769830/apache2-4-internal-server-error-directorymatch)).

## Evidence from this checkout

- `git diff a9b446d9..ee9edeea -- VTU/DGV7.0-SAAS/.htaccess VTU/DGV7.0-NON-SAAS/.htaccess` shows:
  - SAAS: `.htaccess` added with 97 lines.
  - NON-SAAS: existing 45-line `.htaccess` replaced with the same new 97 lines.
- Before the fix, both root files had SHA-256 `6cfec95e84d928ce85908ca2aa3d52f9085887243ce06d4a7ed044f1093657ab`.
- The 50-file security commit changed PHP pages, but no changed entry-point PHP patch shows a shared parse error. The identical Apache configuration error is the common failure.

## Secondary issues found in the same `.htaccess`

These did not explain the initial 500 but would have caused problems after it was removed:

1. `Require all denied` was used directly instead of being wrapped in `<IfModule mod_authz_core.c>` with an Apache 2.2 `Order/Deny` fallback. The previous NON-SAAS file had that compatibility guard.
2. `RedirectMatch 403 ^/(backups?|logs?|...)/` and `RedirectMatch 403 ^\.git` are unreliable in per-directory context. URL-relative `RewriteRule` guards are safer.
3. The query-string filter used a broken `OR` chain. It blocked any query containing innocent words such as `select`, `update`, `create`, `script` or `sleep`, while requiring traversal and `eval`-style patterns to occur together. That would create false 403 responses after the 500 was fixed.
4. `mod_rewrite` rules in a parent `.htaccess` are not inherited automatically by child `.htaccess` files that enable their own rewrite engine ([Apache docs](https://httpd.apache.org/docs/current/rewrite/htaccess.html)). The core `FilesMatch`/header protections are inherited; the URL guards may not be inside `/web`, `/bc-admin` or `/bc-spadmin`. For complete subdirectory enforcement, move those guards into the vhost/WAF or explicitly add `RewriteOptions InheritBefore` to the child `.htaccess` files after confirming Apache 2.4+.

## Fix applied

- Removed `<DirectoryMatch>` from both root `.htaccess` files.
- Replaced directory/VCS `RedirectMatch` lines with `RewriteRule ... [F]` rules that work in `.htaccess`.
- Restored Apache 2.4/2.2 authorization compatibility with `<IfModule mod_authz_core.c>` and an `Order allow,deny` fallback.
- Kept security headers and direct-download protection for config, log, backup, archive and lock files.
- Narrowed the query-string filter to high-signature attacks and corrected the `OR` chain so legitimate DGV7 URLs are not blocked.
- Added `tests/htaccess_context_test.py`, which rejects server/vhost-only directives in any `.htaccess`, validates container balance, and simulates benign/malicious query strings and protected paths.

## Deployment verification

After deploying the updated `.htaccess` to both document roots:

```bash
# From the repository root
python3 VTU/tests/htaccess_context_test.py

# On the server, check the web-server error log for config parse errors
sudo tail -n 100 /usr/local/apache/logs/error_log 2>/dev/null || \
sudo tail -n 100 /var/log/apache2/error.log 2>/dev/null

# Basic smoke test (replace with the real host)
curl -sS -o /dev/null -w '%{http_code}\n' https://example.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://example.com/web/Login.php
```

If a 500 remains after this file is deployed, the next step is to read the first matching entry in the server error log. A message such as `Options not allowed here`, `Require not allowed here` or `Invalid command 'RewriteEngine'` means the hosting account's `AllowOverride` set is too restrictive or `mod_rewrite`/`mod_headers` is not enabled; that is a host configuration issue, not a PHP code issue.
