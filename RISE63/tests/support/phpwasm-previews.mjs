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

/**
 * The admin update screen is rendered the same way: a real View::render through layout.php with
 * fixed fixtures, so the page can be reviewed without a database or a login.
 */
{
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
  for (const dir of ['/src/config', '/src/storage']) await ensure(dir);

  await runtime.run(`<?php
$_SERVER = array(
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?page=updates',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array('page' => 'updates'); $_POST = array(); $_REQUEST = array('page' => 'updates');
chdir('/src');
require '/src/app/bootstrap.php';
RISE63\\Core\\Security::csrf();
$account = array(
    'id' => 1, 'full_name' => 'Adaeze Okonkwo', 'email' => 'admin@example.org',
    'role' => 'provincial_admin', 'theme_preference' => 'system', 'large_text' => 0,
    'parish_id' => null, 'is_suspended' => 0,
);
RISE63\\Core\\View::render('staff/updates', array(
    'title' => 'Updates', 'page' => 'updates', 'account' => $account, 'user' => $account,
    'flashes' => array(), 'csrf' => str_repeat('a', 64),
    'currentVersion' => '1.0.0',
    'check' => array(
        'ok' => true, 'unreachable' => false, 'status' => 'success', 'message' => '',
        'version' => '1.1.0', 'tier' => 'NON-SAAS', 'checksum' => str_repeat('9', 64),
        'download_url' => '', 'changelog' => "- Academy attendance panel is now read-only for members\\n- Treasury export includes the parish code",
    ),
    'plan' => null, 'result' => null,
    'history' => array(
        array('version' => '1.0.1', 'tier' => 'NON-SAAS', 'applied_at' => '2026-11-01T09:12:00+00:00',
              'written' => 12, 'removed' => 1, 'sql_run' => 2, 'backup' => '/src/storage/updates/backup/20261101-091200'),
    ),
));`);

  await new Promise((resolve) => setTimeout(resolve, 1200));

  let html = output;
  html = html.replace(/<link rel="stylesheet" href="[^"]*app\.css">/, '<style>\n' + css + '\n</style>');
  html = html.replace(/<script src="[^"]*app\.js" defer><\/script>/, '<script>\n' + js + '\n</script>');
  html = html.replace('<head>', '<head>\n<!-- Static preview of the admin Updates screen. Generated from app/Views/staff/updates.php with the real app.css inlined. -->');

  await writeFile(join(root, 'docs/preview', 'admin-updates.html'), html);
  console.log(`admin updates → docs/preview/admin-updates.html (${html.length} bytes)`);
}

/**
 * The activation gate, with a refusal that actually happened in the field: the provider answered
 * "Invalid license key or domain." for a key that was never in its database. The preview exists so
 * the screen can be checked for the thing that matters — it says what was sent and what came back,
 * instead of relaying that one sentence.
 */
{
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
  for (const dir of ['/src/config', '/src/storage']) await ensure(dir);

  await runtime.run(`<?php
$_SERVER = array(
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array(); $_POST = array(); $_REQUEST = array(); $_FILES = array();
chdir('/src');
require '/src/app/bootstrap.php';
$activationState = array(
    'valid' => false, 'grace' => false,
    'domain' => 'buildroom.rccglp63yaya.org.ng',
    'key' => 'MAN-749B9B757500',
    'message' => 'Invalid license key or domain.',
);
$domain = 'buildroom.rccglp63yaya.org.ng';
require '/src/app/Views/system-gate.php';`);

  await new Promise((resolve) => setTimeout(resolve, 1200));

  let html = output;
  html = html.replace(/<link rel="stylesheet" href="[^"]*app\.css">/, '<style>\n' + css + '\n</style>');
  html = html.replace('<head>', '<head>\n<!-- Static preview of the activation gate after a refusal. Generated from app/Views/system-gate.php. -->');

  await writeFile(join(root, 'docs/preview/activation-gate.html'), html);
  console.log(`activation gate → docs/preview/activation-gate.html (${html.length} bytes)`);
}

/**
 * The sign-in page, reached at /login — as a path, with no ?page= anywhere. This is the exact
 * request that returned Apache's "Not Found" on the first real installation, so the preview is
 * the proof that the URL now resolves to the page rather than a 404.
 */
{
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
  for (const dir of ['/src/config', '/src/storage']) await ensure(dir);

  // Rendered through the same view the controller uses, with the fixtures that page needs. The
  // routing itself is what the request shows: REQUEST_URI is /login, and $_GET carries no page.
  await runtime.run(`<?php
$_SERVER = array(
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/login',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array(); $_POST = array(); $_REQUEST = array(); $_FILES = array();
chdir('/src');
require '/src/app/bootstrap.php';
echo '<!-- page resolved from the path: ' . \\RISE63\\page_from_path('/login') . ' -->';
\\RISE63\\Core\\View::render('auth/login', array(
    'title' => 'Sign in', 'parishes' => array(),
    'flashes' => array(), 'page' => 'login', 'user' => null,
), true);`);

  await new Promise((resolve) => setTimeout(resolve, 1200));

  let html = output;
  html = html.replace(/<link rel="stylesheet" href="[^"]*app\.css">/, '<style>\n' + css + '\n</style>');
  await writeFile(join(root, 'docs/preview/login-at-clean-url.html'), html);
  console.log(`sign-in at /login → docs/preview/login-at-clean-url.html (${html.length} bytes, page=${
    /page resolved from the path: login/.test(html) ? 'login' : 'NOT RESOLVED'})`);
}
