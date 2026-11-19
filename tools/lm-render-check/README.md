# LM render check

Gates for the License Manager that catch bugs PHP's own parser cannot see. Each
one exists because a real release shipped with that bug.

## Why

**9.7.0 — PHP source printed on every console page.** `LM/includes/app_ui.php`
prints the console shell from inside `lm_app_start()`, so it leaves PHP mode with
`?>`. The `<?php` that reopens PHP went missing: the function's closing braces and
the whole `lm_app_end()` definition were left as HTML and printed on the page, and
every console page then died with *Call to undefined function `lm_app_end()`*.
The file parses perfectly, so `php -l` was green.

**9.7.0 — cron dead for three weeks, no notification.** `cron.php` line 218:

```php
lm_log('cron: completed in ' . $lmReport['duration_ms'] . ' ms — ' . json_encode(array(
```

`?> ` (the `?` and `>` of `?>` with a space) ends PHP mode in the middle of the
concatenation. The rest of the line becomes HTML, and PHP sees
`lm_log('cron: completed in 42 ms — ')` — **one argument** for a two-argument
function. That is a runtime `ArgumentCountError`, not a compile error, so the
file parses cleanly and the crash only happens at the end of each cron run. It
logged 3,031 identical fatals in `storage/logs/php.log`, one per run, every ten
minutes, killing the mail queue, the pull queue and the retention sweep with it.

Neither bug is visible to a syntax check. Both are caught here.

## Setup

```bash
cd tools/lm-render-check
npm install
```

## Gates

| Command | Catches | Expected |
|---|---|---|
| `npm run arity` | A call passing fewer arguments than the function declares — the `?>`-in-a-string trap | `0 call(s) with too few arguments` |
| `npm run leaks` | PHP syntax sitting in a file's HTML regions (the 9.7.0 console leak) | `0 leaked line(s)` |
| `npm run unit` | Regressions in `health.php`'s own scanner, 7 cases incl. the real leak | `0 of 7 case(s) failed` |
| `npm run tests` | The release-retention rule and its failure path, 18 checks | `0 of N check(s) failed` |
| `npm run settings` | Saves the Storage & cron form for real and reloads: values stick, clamps apply, no other setting is lost | `0 of 17 check(s) failed` |
| `npm run render` | Renders every page under PHP 8.3 and scans the output for source | `0 leaked, 0 died` |
| `npm run manifest` | `build.json` matches the tree: nothing stale, missing, or unlisted | `stale: none` |

`npm run all` runs them in that order.

## What each one does

- **`arity-check.cjs`** parses every file, records how many required parameters
  each function declares, then checks every call site. It found both historical
  cron defects (`cron.php:112` and `cron.php:218` each passing 1 of 2 arguments).

- **`mode-leak-check.js`** uses php-parser's `inline` nodes — the actual HTML
  regions of a file, which is exactly what PHP prints — and flags lines there
  that are pure PHP syntax. Blocks that legitimately print code are masked first:
  `<script>`, `<style>`, `<pre>`, `<code>`, `<textarea>`, and `data-copy="…"`.

- **`render-check.mjs`** is the heavyweight one: it loads PHP 8.3 (WebAssembly),
  stubs an admin session, and renders every console and public page, then scans
  the **output**. Against the broken `app_ui.php` it reports 16 pages leaking
  source and 16 fatals; against the fix, 0 and 0.

  It warns when `db.php` opens a real MySQL connection, because then console
  pages stop at the 503 "database is not answering" page and their shell is never
  rendered — a false all-clear. Point it at a copy whose `db.php` sets
  `$pdo = null` for a meaningful run.

- **`run-tests.mjs`** runs `LM/tests/*_test.php` under the same PHP 8.3 runtime.

- **`settings-save.mjs`** is the one that would have caught a silent retention
  bug: the whole rule is a setting, so if the form does not save, every release is
  kept forever and nothing looks broken. It GETs the page, POSTs the form, GETs it
  again, then reads `settings.json` directly and checks that the other 38 settings
  and the cron secret are byte-identical afterwards.

## The checks that ship in the product

`LM/health.php` carries the mode-leak scanner as plain PHP
(`lm_health_stray_html()` — no includes, no extensions), so a deployed install
reports this class of bug itself under **Files printing PHP source**, with the
line numbers where code starts appearing on the page.

It cannot check arity (that needs the whole tree parsed together), so the arity
gate stays here, in CI or on the release checklist.

## Notes

- `@php-wasm` needs `emscriptenOptions.processId` passed explicitly, or it throws
  `PHPLoader.processId must be set before init`.
- A `?>` inside a PHP string or comment is not a mode switch *for the file being
  scanned*, but it **is** one for the file that contains it as source. Writing
  `health.php`'s scanner needed `'<' . '?php'` to avoid closing its own file, and
  its first version had a `?>` in a comment that did exactly that.
- The retention tests drive `lm_delete_release()` with a plain stub object rather
  than a `PDO` subclass: `PDO`'s method signatures are fixed in PHP 8 and cannot
  be re-declared with different ones.
