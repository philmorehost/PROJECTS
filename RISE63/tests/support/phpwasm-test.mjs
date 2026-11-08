/**
 * Runs the PHP unit suite (tests/unit.php) inside php-wasm, so the money, state-machine,
 * security-policy and schema assertions can be executed in an environment with no PHP binary.
 *
 * Everything is staged read-only under /src, mirroring the repository layout, so the tests
 * read database/schema.sql, database/countries.sql and database/seed.sql exactly as a real
 * server would. php-wasm has no PDO/MySQL, so the suite only exercises pure logic; database
 * behaviour is covered by tests/integration.php on real hosting.
 *
 *   npm i php-wasm
 *   node tests/support/phpwasm-test.mjs
 */
import { readdir, readFile } from 'node:fs/promises';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PhpNode } = require('php-wasm/PhpNode.mjs');
const phpModule = require('php-wasm/php8.3-node.mjs');

const SKIP = new Set(['node_modules', '.git', 'storage', 'docs', 'public']);

async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else if (/\.(php|sql)$/.test(entry.name)) out.push(full);
  }
  return out;
}

const files = (await walk(root)).sort();
const payload = {};
for (const file of files) payload[relative(root, file)] = await readFile(file, 'utf8');

const runtime = new PhpNode(phpModule);
let output = '';
runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
runtime.addEventListener('error', (event) => { output += String(event.detail?.[0] ?? ''); });
await runtime.refresh();

/** Write a file, creating each parent directory once (Emscripten has no recursive mkdir). */
const madeDirs = new Set();
async function stage(path, contents) {
  const parts = path.split('/').filter(Boolean).slice(0, -1);
  let current = '';
  for (const part of parts) {
    current += '/' + part;
    if (madeDirs.has(current)) continue;
    try { await runtime.mkdir(current); } catch { /* already exists */ }
    madeDirs.add(current);
  }
  await runtime.writeFile(path, contents);
}

for (const [name, contents] of Object.entries(payload)) {
  await stage('/src/' + name, contents);
}
console.log(`staged ${files.length} source file(s) under /src`);

if (process.env.RISE63_DEBUG_SCRIPT) {
  await runtime.writeFile('/src/tests/debug.php', await readFile(join(root, 'tests', 'debug.php'), 'utf8').catch(() => ''));
}
await runtime.run('<?php require "/src/tests/unit.php";');
await new Promise((resolve) => setTimeout(resolve, 1200));

process.stdout.write(output);
process.exit(/FAIL|Fatal|Uncaught/.test(output) ? 1 : 0);
