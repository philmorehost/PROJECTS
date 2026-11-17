/**
 * Runs tests/pages.php against a real SQL database inside php-wasm.
 *
 * tests/e2e.php exercises the domain layer; this exercises the router. Both are needed: the two
 * faults that took /settings and /people down in production lived in the call site that gathers a
 * page's data, which only a real require of app/pages.php will ever execute.
 *
 *   npm i php-wasm @electric-sql/pglite
 *   node tests/support/phpwasm-pages.mjs
 */
import { readdir, readFile } from 'node:fs/promises';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here    = dirname(fileURLToPath(import.meta.url));
const root    = join(here, '..', '..');

const { PGlite } = require('@electric-sql/pglite');
const { PhpNode } = require('php-wasm/PhpNode.mjs');

// php-wasm reads the PGlite class off the Emscripten module object, which comes from the runtime
// settings. Both are set because PhpNode merges `args` into globalThis.phpSettings.
globalThis.phpSettings = { ...(globalThis.phpSettings ?? {}), PGlite };
const runtime = new PhpNode({ version: '8.4', PGlite });

let output = '';
runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
runtime.addEventListener('error',  (event) => { output += String(event.detail?.[0] ?? ''); });
await runtime.refresh();

const SKIP = new Set(['node_modules', '.git', 'docs', 'storage']);
async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else if (/\.(php|sql|css|js|svg|webmanifest)$/.test(entry.name)) out.push(full);
  }
  return out;
}

const made = new Set();
async function ensure(dir) {
  let current = '';
  for (const part of dir.split('/').filter(Boolean)) {
    current += '/' + part;
    if (made.has(current)) continue;
    try { await runtime.mkdir(current); } catch { /* exists */ }
    made.add(current);
  }
}

await ensure('/src');
for (const file of (await walk(root)).sort()) {
  const target = '/src/' + relative(root, file);
  await ensure(target.split('/').slice(0, -1).join('/'));
  await runtime.writeFile(target, await readFile(file, 'utf8'));
}
await ensure('/src/config');
await ensure('/src/storage');
await runtime.writeFile('/src/config/.keep', '');

// php-wasm's embed SAPI does not define the CLI stream constants.
await runtime.run(`<?php
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
chdir('/src');
require '/src/tests/pages.php';`);
await new Promise((resolve) => setTimeout(resolve, 12000));

process.stdout.write(output);
process.exit(/FAIL|Fatal error|Uncaught/.test(output) ? 1 : 0);
