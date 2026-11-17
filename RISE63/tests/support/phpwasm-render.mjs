/**
 * Runs tests/render.php (the view harness) inside php-wasm.
 *
 *   npm i php-wasm
 *   node tests/support/phpwasm-render.mjs
 */
import { readdir, readFile, mkdir } from 'node:fs/promises';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PhpNode } = require('php-wasm/PhpNode.mjs');
const phpModule = require('php-wasm/php8.3-node.mjs');

const SKIP = new Set(['node_modules', '.git', 'storage', 'docs']);

async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else if (!/\.php-cs-fixer/.test(entry.name)) out.push(full);
  }
  return out;
}

const files = (await walk(root)).sort();
const runtime = new PhpNode(phpModule);
let output = '';
runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
runtime.addEventListener('error', (event) => { output += String(event.detail?.[0] ?? ''); });
await runtime.refresh();

const made = new Set();
async function stage(path, contents) {
  const parts = path.split('/').filter(Boolean).slice(0, -1);
  let current = '';
  for (const part of parts) {
    current += '/' + part;
    if (made.has(current)) continue;
    try { await runtime.mkdir(current); } catch { /* exists */ }
    made.add(current);
  }
  await runtime.writeFile(path, contents);
}

for (const file of files) {
  const name = relative(root, file);
  await stage('/src/' + name, await readFile(file, 'utf8'));
}
await stage('/src/config/.keep', '');
console.log(`staged ${files.length} file(s) under /src`);

await runtime.run('<?php require "/src/tests/render.php";');
await new Promise((resolve) => setTimeout(resolve, 1500));

process.stdout.write(output);
process.exit(/FAIL|Fatal|Uncaught/.test(output) ? 1 : 0);
