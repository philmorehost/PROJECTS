# QA harness

Small scripts that check the License Manager without a database, a web server
or a network connection. They run on Node and a PHP-wasm build, so they work on
a laptop and in CI.

```bash
cd qa
npm install php-wasm php-parser jsdom
```

| Script | What it checks |
|---|---|
| `node lint74.cjs` | Every `.php` file parses **as PHP 7.4** (the host runs `ea-php74`). The php-wasm linter is PHP 8 and misses 8-only syntax, so this uses `php-parser` instead. |
| `node contracts.cjs` | The 403 contract: `build.json` matches the tree, a mixed folder is detected (console banner, sign-in banner, `lm_release_state()`), a legacy `session_start()` file is explained instead of 403'd, `lm_json()` carries `server_version`, every GitHub 403 class (rate limit, SSO, fine-grained, scopes) produces the sentence that fixes it, encoded retry bodies decode correctly, `admin/ping.php` answers, and every inline `<script>` parses. |
| `node system-check.cjs` | Drives the integrity scanner against a planted web shell and a planted stray file: it must catch both, must not accuse `system-check.php` or `lm_config.php`, and must refuse to remove anything unless the name is typed, the CSRF token matches and the path is one the scanner just reported. |
| `node projects-ui.cjs` | Clicks the real buttons in a jsdom page: Delete (plan → modal → confirmation → request), Edit, Integration, and a project whose name contains quotes. Catches broken inline handlers, which fail silently in a browser. |
| `node render.cjs <page.php>` | Renders a page in PHP-wasm and prints any PHP diagnostic it produced. Flags: `--no-ui` (delete `includes/ui.php` first, to test a partial upload), `--session` (seed a signed-in admin), `--stale <file>` / `--absent <file>` (simulate a partial upload), `--out <file>`. |
| `node buildjson.cjs` | Regenerates `LM/build.json`. Run it as the last step before committing a release; `--check` verifies the manifest on disk still matches the tree. |

`lib/boot.cjs` boots PHP-wasm with a copy of `LM/` in a virtual filesystem and
patches `db.php` with a stub, because php-wasm has no MySQL driver. Two
quirks are handled there and are easy to trip over when adding tests:

* `lm_json()` ends with `exit`, which leaves the PHP-wasm instance unable to
  produce output again — `boot()` starts a new runtime after any probe that can
  exit.
* PHP statics persist between probes in the same runtime, so a test that needs
  a fresh look at the disk calls `run(..., { fresh: true })` **before** it edits
  a file, not after.
* `lib/fakedb.php` is a real `PDO`/`PDOStatement` subclass with no driver behind
  it, because the app type-hints `PDO`. Tests seed `$GLOBALS['qa_tiers']`,
  `qa_counts`, `qa_updates` and set `$GLOBALS['qa_pdo']`; `db.php` picks it up.
* `boot().write()` remembers what it planted, so a file survives the runtime
  restart that follows any page calling `exit()` — otherwise a test that plants
  a shell loses it the moment it renders an admin page.

None of this is shipped to the server: `LM/build.json` is the only file under
`LM/` these scripts produce, and it is part of the release on purpose.
