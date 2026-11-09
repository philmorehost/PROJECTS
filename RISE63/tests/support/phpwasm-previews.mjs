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
  // The stylesheet URL now carries a content fingerprint, so match it rather than requiring the bare
  // filename; otherwise every preview silently loses its styling.
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
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
  // The stylesheet URL now carries a content fingerprint, so match it rather than requiring the bare
  // filename; otherwise every preview silently loses its styling.
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
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
  // The stylesheet URL now carries a content fingerprint, so match it rather than requiring the bare
  // filename; otherwise every preview silently loses its styling.
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
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
  // The stylesheet URL now carries a content fingerprint, so match it rather than requiring the bare
  // filename; otherwise every preview silently loses its styling.
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
  await writeFile(join(root, 'docs/preview/login-at-clean-url.html'), html);
  console.log(`sign-in at /login → docs/preview/login-at-clean-url.html (${html.length} bytes, page=${
    /page resolved from the path: login/.test(html) ? 'login' : 'NOT RESOLVED'})`);
}


/**
 * The registration screen, where the member searches for their church and — if it is not there —
 * types it. Rendered through the real view with the real stylesheet and script, so the picker can
 * be tried by hand in a browser.
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
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/register',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array(); $_POST = array(); $_REQUEST = array(); $_FILES = array();
chdir('/src');
require '/src/app/bootstrap.php';
echo RISE63\\Core\\View::capture('/src/app/Views/auth/register.php', array(
    'flashes' => array(), 'page' => 'register', 'title' => 'Create account', 'user' => null,
), false);`);

  await new Promise((resolve) => setTimeout(resolve, 1200));

  let html = output;
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
  // The picker needs a search endpoint, and a static file has no server behind it. Answer the
  // fetch from a fixed list so the list, the manual row and the capitals can all be tried here.
  const stub = `
  /* Preview only: stands in for /api/index.php so the picker works in a static file. */
  (function () {
    var realFetch = window.fetch;
    window.fetch = function (url, options) {
      if (String(url).indexOf('api/index.php') === -1) { return realFetch(url, options); }
      var term = decodeURIComponent(String(url).split('q=')[1] || '').toUpperCase();
      var all = [
        { id: 1, parish: 'LIVING WATER PARISH', area: 'HOPE AREA', zone: 'GRACE ZONE' },
        { id: 2, parish: 'GOOD SHEPHERD PARISH', area: 'HOPE AREA', zone: 'GRACE ZONE' },
        { id: 3, parish: 'CHAPEL OF WONDERS PARISH', area: 'CHAPEL OF WONDERS PARISH AREA', zone: 'CONQUERORS CHAPEL ZONE' }
      ];
      var hits = all.filter(function (p) {
        return term.length < 3 || (p.parish + ' ' + p.area + ' ' + p.zone).indexOf(term) !== -1;
      }).map(function (p) {
        p.label = p.parish + ' \\u00b7 ' + p.area + ' \\u00b7 ' + p.zone;
        return p;
      });
      return Promise.resolve({ ok: true, status: 200, json: function () { return Promise.resolve({ parishes: hits }); } });
    };
  }());
`;
  html = html.replace(/<script src="[^"]*app(?:\.js)?[^"]*" defer><\/script>/, '<script>' + stub + '</script>\n<script>\n' + js + '\n</script>');
  html = html.replace('<head>', '<head>\n<!-- Static preview of the registration form. Generated from app/Views/auth/register.php. The parish search answers from a fixed list so the picker can be tried here. -->');

  if (html.length < 20000) {
    throw new Error('register preview looks unstyled (' + html.length + ' bytes) — check the inliner');
  }

  await writeFile(join(root, 'docs/preview/register-parish-picker.html'), html);
  console.log(`register → docs/preview/register-parish-picker.html (${html.length} bytes)`);
}

/**
 * The bulk paste screen, mid-flow: a list has been pasted, previewed, and is waiting for the
 * second button. This is the screen the province will actually use to build the directory, so it
 * is worth being able to look at without a database.
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

  // The pasted list is the one from the field report: one area with no parishes yet, one zone
  // with two areas, and a parish repeated on a later line.
  const pasted = [
    'JOYFUL ASSEMBLY PROV. HQTR ZONE, CONQUERORS CHAPEL ZONE, CHAPEL OF WONDERS PARISH AREA',
    'JOYFUL ASSEMBLY PROV. HQTR ZONE, CONQUERORS CHAPEL ZONE, CONQUERORS CHAPEL AREA',
    'JOYFUL ASSEMBLY PROV. HQTR ZONE, CONQUERORS CHAPEL ZONE',
    'GRACE ZONE, HOPE AREA, LIVING WATER PARISH',
    'GRACE ZONE, HOPE AREA',
    'grace zone, faith area, good shepherd parish',
  ].join('\n');

  await runtime.writeFile('/src/storage/import-preview.txt', pasted);

  await runtime.run(`<?php
$_SERVER = array(
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/parishes',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array(); $_POST = array(); $_REQUEST = array(); $_FILES = array();
chdir('/src');
require '/src/app/bootstrap.php';
$text = (string) file_get_contents('/src/storage/import-preview.txt');
$parsed = RISE63\\Core\\DirectoryImport::parse($text);
$preview = RISE63\\Core\\DirectoryImport::summarise(
    $parsed['lines'],
    array('JOYFUL ASSEMBLY PROV. HQTR ZONE'),
    array(array('zone_name' => 'JOYFUL ASSEMBLY PROV. HQTR ZONE', 'area_name' => 'CONQUERORS CHAPEL ZONE')),
    array()
);
$account = array(
    'id' => 1, 'full_name' => 'Build Room', 'email' => 'yemi.abiose@gmail.com',
    'role' => 'provincial_admin', 'parish_id' => null, 'theme_preference' => 'system',
    'large_text' => 0, 'is_suspended' => 0,
);
$user = $account; $page = 'parishes'; $title = 'Parish Connect'; $flashes = array();
echo RISE63\\Core\\View::capture('/src/app/Views/staff/parishes.php', array(
    'content' => '', 'title' => 'Parish Connect', 'page' => 'parishes',
    'account' => $account, 'user' => $account, 'flashes' => array(),
    'parishes' => array(), 'term' => '', 'zones' => array(), 'areas' => array(),
    'totals' => array('zones' => 1, 'areas' => 2, 'parishes' => 0, 'youth' => 0),
    'canEdit' => true, 'importText' => $text, 'importPreview' => $preview,
    'importSkipped' => $parsed['skipped'],
), true);`);

  await new Promise((resolve) => setTimeout(resolve, 1200));

  let html = output;
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
  html = html.replace(/<script src="[^"]*app\.js" defer><\/script>/, '<script>\n' + js + '\n</script>');
  html = html.replace('<head>', '<head>\n<!-- Static preview of the bulk zone/area/parish paste, previewed and awaiting confirmation. Generated from app/Views/staff/parishes.php. -->');

  if (html.length < 20000) {
    throw new Error('bulk-paste preview looks unstyled (' + html.length + ' bytes) — check the inliner');
  }

  await writeFile(join(root, 'docs/preview/directory-bulk-paste.html'), html);
  console.log(`bulk paste → docs/preview/directory-bulk-paste.html (${html.length} bytes, ${
    (html.match(/badge-info/g) || []).length} new-parish rows)`);
}

/**
 * The signed-in shell: the redesigned tab bar on a real dashboard, and the offline bar in the two
 * states that matter. Rendered through layout.php with fixtures, so the preview is the same markup
 * the browser receives — this is the screen the redesign was asked for.
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

  const bar = await (async () => {
    await runtime.run(`<?php
$_SERVER = array(
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/dashboard',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array(); $_POST = array(); $_REQUEST = array(); $_FILES = array();
chdir('/src');
require '/src/app/bootstrap.php';
$content = '<section class="stack">'
    . '<div class="card"><p class="muted" style="margin:0">Province overview</p><h1>Dashboard</h1>'
    . '<p class="muted" style="margin:0">Signed in as Build Room. The bar below is the redesigned tab bar.</p></div>'
    . '<div class="grid-2">'
    . '<div class="stat"><strong>0</strong><span>Active parishes</span></div>'
    . '<div class="stat"><strong>0</strong><span>Applications</span></div>'
    . '</div></section>';
$account = array(
    'id' => 1, 'full_name' => 'Build Room', 'email' => 'yemi.abiose@gmail.com',
    'role' => 'provincial_admin', 'parish_id' => 3, 'theme_preference' => 'dark',
    'large_text' => 0, 'is_suspended' => 0,
);
$user = $account; $page = 'dashboard'; $title = 'Dashboard'; $flashes = array();
echo \\RISE63\\Core\\View::capture('/src/app/Views/layout.php', array(
    'content' => $content, 'title' => 'Dashboard', 'page' => 'dashboard',
    'account' => $account, 'user' => $account, 'flashes' => array(),
), false);
`);
    await new Promise((resolve) => setTimeout(resolve, 1200));
    return output;
  })();

  let html = bar;
  // The stylesheet URL now carries a content fingerprint, so match it rather than requiring the bare
  // filename; otherwise every preview silently loses its styling.
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
  html = html.replace('</body>', `<style>
  /* For the screenshot only: open the modal the way app.js would, without a network probe. */
  .offline-modal { display: flex !important; }
  html.offline-open, html.offline-open body { overflow: hidden; }
</style>
<script>
  document.getElementById('offline-modal').hidden = false;
  document.documentElement.classList.add('offline-open');
</script>
</body>`);
  await writeFile(join(root, 'docs/preview/nav-and-offline.html'), html);

  // Phones: the same bar at 390px and 360px, where seven tabs is the hard case. The media queries
  // are CSS-only, so the preview is the same document inside a fixed-width frame.
  const phone = (width, path) => html.replace('</head>',
    `<style>body{width:${width}px;margin:0 auto;outline:1px dashed #556;}</style>\n</head>`)
    .replace('border-radius: 22px', 'border-radius: 22px');
  await writeFile(join(root, 'docs/preview/nav-phone-390.html'), phone(390));
  await writeFile(join(root, 'docs/preview/nav-phone-360.html'), phone(360));

  console.log(`tab bar + offline bar → docs/preview/nav-and-offline.html (${html.length} bytes, ${
    (html.match(/class="nav-tab/g) || []).length} tabs)`);
  console.log('phone widths → docs/preview/nav-phone-390.html, docs/preview/nav-phone-360.html');
}
