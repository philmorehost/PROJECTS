import { readdir, readFile } from 'node:fs/promises';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PGlite } = require('@electric-sql/pglite');
const { PhpNode } = require('php-wasm/PhpNode.mjs');

globalThis.phpSettings = { ...(globalThis.phpSettings ?? {}), PGlite };

const SKIP = new Set(['node_modules', '.git', '.preview-data']);

async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else if (/\.(php|sql|css|js|svg|json|webmanifest)$/.test(entry.name) || entry.name === 'artisan') out.push(full);
  }
  return out;
}

async function runScript(scriptRelPath) {
  const runtime = new PhpNode({ version: '8.4', PGlite });
  let output = '';
  runtime.addEventListener('output', (e) => { output += e.detail[0] ?? ''; });
  runtime.addEventListener('error', (e) => { output += String(e.detail?.[0] ?? ''); });
  await runtime.refresh();

  const made = new Set();
  const ensureDir = async (dir) => {
    let cur = '';
    for (const part of dir.split('/').filter(Boolean)) {
      cur += '/' + part;
      if (made.has(cur)) continue;
      try { await runtime.mkdir(cur); } catch {}
      made.add(cur);
    }
  };

  for (const file of (await walk(root)).sort()) {
    const rel = relative(root, file);
    const target = '/src/' + rel;
    await ensureDir(target.split('/').slice(0, -1).join('/'));
    await runtime.writeFile(target, await readFile(file, 'utf8'));
  }
  for (const d of ['/src/storage', '/src/storage/cache', '/src/storage/sessions', '/src/storage/logs', '/tmp']) {
    await ensureDir(d);
  }

  await runtime.run(`<?php
error_reporting(E_ALL);
chdir('/src');
$_SESSION = [];
require '/src/${scriptRelPath}';
`);
  await new Promise((r) => setTimeout(r, 1500));
  process.stdout.write(output);
  if (/FAIL \[|Fatal error|Uncaught/.test(output)) {
    process.exit(1);
  }
}

await runScript('tests/unit.php');
await runScript('tests/e2e.php');
