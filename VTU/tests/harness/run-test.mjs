// DGV7 PHP-wasm test harness.
//
// WHY THIS EXISTS
//   The DGV7 test suites are plain PHP scripts (`php -n VTU/tests/fintech_gate_test.php`)
//   and run in CI on a normal PHP install. Some development sandboxes have no native PHP
//   binary at all, so this harness runs the very same files under php-wasm instead of a
//   paraphrase of them. It only mirrors the tree into the wasm filesystem and requires the
//   real test file by path, so __DIR__ and the shipped source behave exactly as in CI.
//
// SETUP (node_modules is git-ignored)
//   cd VTU/tests/harness && npm install
//
// USAGE (from the repository root)
//   node VTU/tests/harness/run-test.mjs tests/fintech_gate_test.php
//   node VTU/tests/harness/run-test.mjs --lint DGV7.0-SAAS/func/bc-header.php
//
// One PhpNode instance per invocation: a reused instance degrades after thousands of files
// and starts reporting phantom parse failures.
import { PhpNode } from 'php-wasm/PhpNode';
import fs from 'fs';
import path from 'path';

// harness/ -> tests/ -> VTU/ -> repository root
const REPO = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..', '..', '..');
const VTU = path.join(REPO, 'VTU');
const args = process.argv.slice(2);
const lintMode = args[0] === '--lint';
// Targets are VTU-relative on purpose (that is how the mirror lays them out); accept a
// leading "VTU/" too so the path can be copied straight from the repo root.
const target = (lintMode ? args[1] : args[0]).replace(/^VTU\//, '');
if (!target) {
  console.error('usage: node VTU/tests/harness/run-test.mjs [--lint] <repo-relative-path>');
  process.exit(2);
}

function collect() {
  const out = [];
  const walk = (dir, rel) => {
    let entries;
    try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
    for (const e of entries) {
      if (e.name === '.git' || e.name === 'node_modules') continue;
      const abs = path.join(dir, e.name);
      const r = rel ? rel + '/' + e.name : e.name;
      if (e.isDirectory()) walk(abs, r);
      else out.push({ abs, rel: r });
    }
  };
  for (const ed of ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS']) walk(path.join(VTU, ed), ed);
  walk(path.join(VTU, 'tests'), 'tests');
  // The License Manager lives outside VTU/ but its edition guard is tested too.
  walk(path.join(REPO, 'LM'), 'LM');
  // Top-level VTU docs (DGV7_FINTECH_UPGRADE.md is read by the tests).
  for (const e of fs.readdirSync(VTU, { withFileTypes: true })) {
    if (e.isFile() && e.name.endsWith('.md')) out.push({ abs: path.join(VTU, e.name), rel: e.name });
  }
  return out;
}

const files = collect();
const php = new PhpNode();
let out = '';
php.onoutput = (e) => { out += e.detail[0]; };
php.onerror = (e) => { out += e.detail[0]; };

async function mirror() {
  await php.mkdir('/host');
  const made = new Set(['/host']);
  for (const f of files) {
    const isPhp = f.rel.endsWith('.php');
    const isDoc = f.rel.endsWith('.md');
    const isHtaccess = f.rel.endsWith('.htaccess');
    // The Pay Bills automation library is asserted against the pages that call it.
    const isJs = f.rel.endsWith('.js') && f.rel.includes('/jsfile/');
    if (!isPhp && !isDoc && !isHtaccess && !isJs) continue;
    const dest = '/host/' + f.rel;
    // mkdir on an existing directory fails in this FS, so create each segment once.
    const parts = path.dirname(f.rel).split('/').filter((p) => p && p !== '.');
    let acc = '/host';
    for (const p of parts) {
      acc += '/' + p;
      if (made.has(acc)) continue;
      await php.mkdir(acc);
      made.add(acc);
    }
    await php.writeFile(dest, fs.readFileSync(f.abs));
  }
}

await php.ready;
await mirror();

if (lintMode) {
  const dest = '/host/' + target;
  const runner = '/host/__lint__.php';
  await php.writeFile(runner, `<?php
$src = file_get_contents(${JSON.stringify(dest)});
try {
    token_get_all($src, TOKEN_PARSE);
    echo "PARSE_OK " . strlen($src) . "\\n";
} catch (\\ParseError $e) {
    echo "PARSE_FAIL " . $e->getMessage() . "\\n";
    exit(1);
}
`);
  await php.run(`<?php require ${JSON.stringify(runner)};`);
  php.flush();
  process.stdout.write(out);
  process.exit(out.includes('PARSE_OK') ? 0 : 1);
}

const runner = '/host/__run__.php';
await php.writeFile(runner, `<?php require ${JSON.stringify('/host/' + target)};`);
try {
  await php.run(`<?php require ${JSON.stringify(runner)};`);
} catch (e) {
  out += '\n[uncaught] ' + (e && e.message ? e.message : String(e)) + '\n';
}
php.flush();
process.stdout.write(out);
// PHP-wasm can report a fatal via output rather than a rejected run promise.
if (/Fatal error:|Parse error:|\[uncaught\]|^FAIL(?:\s|:)/m.test(out)) process.exitCode = 1;
