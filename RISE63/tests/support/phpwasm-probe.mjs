/**
 * Runs an arbitrary PHP file against PGlite inside php-wasm — a scratch harness for investigating
 * a reported fault before it is turned into a test.
 *
 *   PROBE=tests/probe.php node tests/support/phpwasm-probe.mjs
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

const probe = process.env.PROBE;
if (!probe) {
  console.error('Set PROBE to the PHP file to run, e.g. PROBE=tests/probe.php');
  process.exit(2);
}
const source = await readFile(join(root, probe), 'utf8');
await runtime.writeFile('/probe.php', source);
await runtime.run(`<?php
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
chdir('/src');
require '/probe.php';`);
await new Promise((resolve) => setTimeout(resolve, 12000));

process.stdout.write(output);
