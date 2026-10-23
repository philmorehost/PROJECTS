# PROJECTS

---

## License Manager (LM)

PHP 7.4 application for selling, validating and updating licensed scripts.

Recent work (LM 8.1):

- OTA updates for **any number of script projects** — add one under `LM/admin/projects.php`
  and it appears in the Push Updates dropdown, with its own storage folder and version history.
- **GitHub Pull** (`LM/admin/github-updates.php`) — publish straight from a branch, public or
  private (personal access token, encrypted at rest). Version numbers increment automatically
  from the previous release and the changelog is generated from the commits.
- **Folder tree picker** — one repository can hold every project. Open the branch tree, expand
  folders, tick whole folders or individual files (select all / filter), and only those files
  are packaged, re-rooted so the chosen folder's contents sit at the ZIP root.
- **Customer notifications** (`LM/admin/update-notifications.php`) — a designed HTML e-mail on
  publish, plus one reminder the next day for anyone whose site has not reported the new version.
  Unsubscribe links are signed; delivery is retried with back-off.
- **SMTP fixed** — port 465 now uses implicit SSL instead of STARTTLS, which is what produced
  `SMTP connect() failed`. Encryption is inferred from the port, with an automatic fallback.
- **Deadlock fixed** — schema changes run once (`schema_migrations`) instead of on every request,
  which removes the `1213 Deadlock` during the domain backfill.
- Deleting or cleaning up a release now removes the actual ZIP from cPanel storage.

**In-app guide** — `LM/admin/guide.php` (nav: *Admin Guide*) is the manual: pipeline,
projects, uploads, GitHub tokens and the folder picker, notifications, SMTP, and
cron setup with a live tester. Every release screen also has a *📖 How this page
works* button that opens the relevant sections in a side drawer.

GitHub pulls need a token only once: *Settings → GitHub Access Token* stores one
encrypted token for the whole installation, and every project without its own
token inherits it.

Setup, integration snippets and troubleshooting: [`LM/docs/UPDATES.md`](LM/docs/UPDATES.md).
Cron: `*/10 * * * * /usr/local/bin/php /home/USER/public_html/lm/cron.php`.
