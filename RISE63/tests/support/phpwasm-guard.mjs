/**
 * Runs bin/guard-namespaces.php inside php-wasm.
 *
 * The guard has to load the real bootstrap so it knows which functions exist, which needs a PHP
 * runtime. This is the shortest possible harness for it.
 *
 *   node tests/support/phpwasm-guard.mjs
 */
import { readdir, readFile } from 'node:fs/promises';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here    = dirname(fileURLToPath(import.meta.url));
const root    = join(here, '..', '..');

const { PhpNode } = require('php-wasm/PhpNode.mjs');
const runtime = new PhpNode(require('php-wasm/php8.3-node.mjs'));

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
    else if (entry.name.endsWith('.php')) out.push(full);
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

await runtime.run(`<?php
if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
chdir('/src');
require '/src/bin/guard-namespaces.php';`);
await new Promise((resolve) => setTimeout(resolve, 6000));

process.stdout.write(output);
process.exit(/GUARD-PROBLEMS|Fatal error|Uncaught/.test(output) ? 1 : 0);
