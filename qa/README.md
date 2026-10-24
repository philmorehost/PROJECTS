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
| `node contracts.cjs` | The release-integrity contract: `build.json` matches the tree, a mixed folder is detected (console banner, sign-in banner, `lm_release_state()`), a legacy `session_start()` file is explained instead of 403'd, `lm_json()` carries `server_version`, and every GitHub 403 class (rate limit, SSO, fine-grained, scopes) produces the sentence that fixes it. |
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

None of this is shipped to the server: `LM/build.json` is the only file under
`LM/` these scripts produce, and it is part of the release on purpose.
