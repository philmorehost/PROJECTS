/**
 * Runs bin/build-assets.php inside php-wasm.
 *
 * There is no PHP CLI in this environment, and the asset manifest has to be regenerated after any
 * change to app.css or app.js — otherwise the content-hashed filenames in the markup point at the
 * previous build and the browser serves the old stylesheet from its immutable cache. That failure
 * mode looks exactly like "my CSS change did nothing", which has cost this project time before.
 *
 *   npm i php-wasm
 *   node tests/support/phpwasm-build-assets.mjs
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

const SKIP = new Set(['node_modules', '.git', 'docs']);

async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else out.push(full);
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
  await stage('/src/' + relative(root, file), await readFile(file));
}

// The builder refuses to run outside the CLI SAPI, correctly: it must never be reachable from a web
// request. php-wasm reports itself as "embed", so the staged copy has that one guard removed. The
// file in the repository keeps it.
const builder = await readFile(join(root, 'bin', 'build-assets.php'), 'utf8');
if (!builder.includes("PHP_SAPI !== 'cli'")) {
  console.error('build-assets.php no longer has the CLI guard — update this harness');
  process.exit(1);
}
await runtime.writeFile('/src/bin/build-assets.php', builder.replace(/if \(PHP_SAPI !== 'cli'\) \{/, 'if (false) {'));

await runtime.run(`<?php
  $_SERVER['argv'] = ['build-assets.php'];
  $GLOBALS['argv'] = $_SERVER['argv'];
  $GLOBALS['argc'] = 1;
  chdir('/src');
  // build-assets.php has no bootstrap of its own: it expects \RISE63\VERSION to exist, which is
  // how it is run in practice (after bootstrap, from the same shell). Declaring it here mirrors
  // that rather than editing the builder.
  // php-wasm's embed SAPI does not define the CLI stream constants.
  if (!defined('STDOUT')) { define('STDOUT', fopen('php://stdout', 'w')); }
  if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
  require '/src/app/bootstrap.php';
  require '/src/bin/build-assets.php';
`);

// Pull the regenerated assets back out. The fingerprinted names are decided inside the virtual
// filesystem, so the manifest is read first and each file it names is copied to the real tree —
// walking the real tree would never find them, because they do not exist there yet.
const { writeFile } = await import('node:fs/promises');
const failed = [];

// readFile hands back bytes, not text; decoding explicitly is what makes the file on disk a
// stylesheet rather than a comma-separated list of its own byte values.
const decoder = new TextDecoder();

async function readText(path) {
  const raw = await runtime.readFile('/src/' + path);
  return typeof raw === 'string' ? raw : decoder.decode(raw);
}

async function pull(path) {
  try {
    const contents = await readText(path);
    await writeFile(join(root, path), contents);
    console.log(`wrote ${path}`);
  } catch (error) {
    failed.push(`${path}: ${error?.message ?? 'not written by the builder'}`);
  }
}

await pull('public/assets/manifest.php');
await pull('public/assets/sw-assets.js');

let manifest = {};
try {
  const source = await readText('public/assets/manifest.php');
  const matches = String(source).match(/'([^']+\.(?:css|js))'\s*=>\s*'([^']+)'/g) ?? [];
  for (const line of matches) {
    const [, from, to] = line.match(/'([^']+)'\s*=>\s*'([^']+)'/) ?? [];
    if (from && to) manifest[from] = to;
  }
} catch {
  /* handled by the manifest.php pull above */
}

for (const hashed of Object.values(manifest)) {
  await pull('public/assets/' + hashed);
}

if (failed.length > 0) {
  console.error('\nCould not retrieve:\n  ' + failed.join('\n  '));
  process.exitCode = 1;
}

process.stdout.write(output);
process.exit(process.exitCode ?? 0);
