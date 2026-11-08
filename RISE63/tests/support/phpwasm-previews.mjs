/**
 * Regenerates docs/preview/*.html — static snapshots of the setup wizard with the real
 * stylesheet inlined, so the layout can be reviewed without a server or a screenshot tool.
 *
 *   node tests/support/phpwasm-previews.mjs
 *
 * One PHP process per stage on purpose: php-wasm shares an interpreter, and the installer reads
 * its stage from $_SESSION. Session state must not leak between renders.
 */
import { readdir, readFile, writeFile, mkdir } from 'node:fs/promises';
import { join, relative, dirname } from 'node:path';
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
    else if (/\.(php|sql|css|js|svg|webmanifest)$/.test(entry.name)) out.push(full);
  }
  return out;
}

const files = (await walk(root)).sort();
const css = await readFile(join(root, 'public/assets/app.css'), 'utf8');
const js  = await readFile(join(root, 'public/assets/app.js'), 'utf8');

const NAMES = {
  1: 'installer-stage-1-system-check-and-licence',
  2: 'installer-stage-2-database',
  3: 'installer-stage-3-administrator',
  4: 'installer-stage-4-ready-to-finish',
};

await mkdir(join(root, 'docs/preview'), { recursive: true });

for (const stage of Object.keys(NAMES).map(Number)) {
  const runtime = new PhpNode(phpModule);
  let output = '';
  runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
  runtime.addEventListener('error', (event) => { output += String(event.detail?.[0] ?? ''); });
  await runtime.refresh();

  const madeDirs = new Set();
  const ensure = async (path) => {
    let current = '';
    for (const part of path.split('/').filter(Boolean)) {
      current += '/' + part;
      if (madeDirs.has(current)) continue;
      try { await runtime.mkdir(current); } catch { /* exists */ }
      madeDirs.add(current);
    }
  };

  for (const file of files) {
    const target = '/src/' + relative(root, file);
    await ensure(target.split('/').slice(0, -1).join('/'));
    await runtime.writeFile(target, await readFile(file, 'utf8'));
  }
  for (const dir of ['/src/config', '/src/storage', '/src/storage/sessions', '/src/storage/documents']) {
    await ensure(dir);
  }

  // No output before the installer runs: echo() marks headers as sent, and then its own
  // Security::startSession() cannot keep the session we seeded.
  await runtime.run(`<?php
$_SERVER = array(
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/install/index.php',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/install/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array(); $_POST = array(); $_REQUEST = array(); $_FILES = array();
chdir('/src');
require '/src/app/bootstrap.php';
$_SESSION = array('rise63_install_stage' => ${stage}, 'csrf' => str_repeat('a', 64));
require '/src/install/index.php';`);

  await new Promise((resolve) => setTimeout(resolve, 1200));

  let html = output;
  html = html.replace(/<link rel="stylesheet" href="[^"]*app\.css">/, '<style>\n' + css + '\n</style>');
  html = html.replace(/<script src="[^"]*app\.js" defer><\/script>/, '<script>\n' + js + '\n</script>');
  html = html.replace('<head>', '<head>\n<!-- Static preview of the RISE 63 setup wizard, stage ' + stage
    + '. Generated from install/index.php with the real app.css inlined. No server needed. -->');

  await writeFile(join(root, 'docs/preview', NAMES[stage] + '.html'), html);
  console.log(`stage ${stage} → docs/preview/${NAMES[stage]}.html (${html.length} bytes)`);
}
