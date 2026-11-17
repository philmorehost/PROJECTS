# LM render check

Renders every page in `LM/` under a real PHP (PHP 8.3 compiled to WebAssembly),
with a stubbed admin session and no database, then scans the **output** for PHP
source. It exists because of LM 9.7.0.

## Why

`LM/includes/app_ui.php` prints the console shell, so it leaves PHP mode with
`?>` in the middle of `lm_app_start()`. A release shipped that file without the
`<?php` that reopens PHP afterwards: the function's closing braces and the whole
`lm_app_end()` definition were left as HTML, so PHP printed them as text. Every
console page ended with

```
} } if (!function_exists('lm_app_end')) {
```

on screen and then died with *Call to undefined function `lm_app_end()`*,
because the function had been swallowed into the page instead of declared.

The file **parses cleanly** — a syntax check cannot see this. Only rendering the
pages can. That is what this harness does.

## Setup

```bash
cd tools/lm-render-check
npm install            # @php-wasm/node + @php-wasm/universal
```

## Run

```bash
# Render gate: every console and public page, output scanned for source.
node render-check.mjs ../../LM
# --- 0 page(s) leaked PHP source, 0 page(s) died, 19 rendered, 20 skipped

# Unit gate: the scanner behind health.php, on fixed and broken inputs.
node stray-html-check.mjs
# --- 0 of 7 case(s) failed
```

`render-check.mjs` exits non-zero if any page prints PHP source or dies. Pages
that redirect (sign-in, logout) are skipped: there is nothing to scan.

## What counts as a leak

A line of output that is PHP and not markup — `}`, `{`, `function x()`,
`if (!function_exists(...)) {`, a docblock tag, a bare `require`. Blocks that
legitimately contain code are ignored first: `<script>`, `<style>`, `<pre>`,
`<code>`, `<textarea>`, and `data-copy="…"` payloads (the operator manual prints
PHP samples on purpose).

## The check that ships

`LM/health.php` carries the same scanner as plain PHP (`lm_health_stray_html()`,
no includes, no extensions) so a deployed install reports the bug itself, under
**Files printing PHP source**, with the line numbers where the code starts
appearing on the page. `stray-html-check.mjs` is its regression test.

## Notes

- The harness rewrites nothing: it copies nothing either. Point it at a tree
  where `db.php` leaves `$pdo` null (pages guard on `$pdo instanceof PDO`), or
  accept that database-backed pages report the 503 "database is not answering"
  page — that page is clean, so the gate still works, it just sees less.
- `@php-wasm` needs `emscriptenOptions.processId` passed explicitly, otherwise
  it throws `PHPLoader.processId must be set before init`.
- A `?>` inside a PHP string or comment is not a mode switch, but PHP's lexer
  treats it as one for the *source file that contains it* — writing this scanner
  needed `'<' . '?php'` to avoid closing its own file.
