/**
 * Request-level smoke test: actually executes the pages a visitor hits first.
 *
 * This exists because a fatal on the installer's first screen (a bare ROOT constant in
 * install/index.php) passed lint, passed the unit suite and passed the view harness — none of
 * them execute install/index.php. Here the real front controller runs, exactly as a browser
 * would trigger it, and the output is inspected.
 *
 *   node tests/support/phpwasm-smoke.mjs
 *
 * php-wasm has no MySQL and no SMTP, so this deliberately covers only what can be true without
 * them: the installer renders, a POST without a CSRF token is refused, the already-installed
 * guard works once config/installed.lock exists, and the private directories are not served.
 */
import { readdir, readFile, writeFile } from 'node:fs/promises';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PhpNode } = require('php-wasm/PhpNode.mjs');
const phpModule = require('php-wasm/php8.3-node.mjs');

const SKIP = new Set(['node_modules', '.git']);

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
const payload = [];
for (const file of files) {
  payload.push([relative(root, file), await readFile(file, 'utf8')]);
}
console.log(`staged ${files.length} file(s) per request`);

/**
 * One request = one fresh PHP process, exactly like a browser hit. Sharing a runtime would let
 * constants and session state leak between requests and hide the very class of bug this test is
 * meant to catch.
 */
async function withRuntime(body) {
  const runtime = new PhpNode(phpModule);
  let output = '';
  runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
  runtime.addEventListener('error', (event) => { output += String(event.detail?.[0] ?? ''); });
  await runtime.refresh();

  const madeDirs = new Set();
  const ensureDir = async (path) => {
    const parts = path.split('/').filter(Boolean);
    let current = '';
    for (const part of parts) {
      current += '/' + part;
      if (madeDirs.has(current)) continue;
      try { await runtime.mkdir(current); } catch { /* exists */ }
      madeDirs.add(current);
    }
  };

  for (const [name, contents] of payload) {
    const path = '/src/' + name;
    await ensureDir(path.split('/').slice(0, -1).join('/'));
    await runtime.writeFile(path, contents);
  }
  for (const dir of ['/src/app', '/src/config', '/src/storage', '/src/storage/sessions',
                     '/src/storage/documents', '/src/storage/logs']) {
    await ensureDir(dir);
  }

  try {
    return await body(runtime);
  } finally {
    try { runtime.destroy?.(); } catch { /* older builds */ }
  }
}

const failures = [];
let checks = 0;
const check = (name, condition, detail = '') => {
  checks++;
  if (condition) {
    console.log(`PASS ${name}`);
  } else {
    failures.push(name);
    console.log(`FAIL ${name}${detail ? ' :: ' + String(detail).slice(0, 400) : ''}`);
  }
};

/** Simulate a request: set $_SERVER/$_GET/$_POST, include the front controller, capture output. */
async function request({ method = 'GET', uri = '/', query = {}, post = {}, cookies = '',
                        installed = false, env = {}, entry = '/src/index.php', script = '/index.php' } = {}) {
  const setup = {
    REQUEST_METHOD: method,
    REQUEST_URI: uri,
    HTTP_HOST: 'buildroom.rccglp63yaya.org.ng',
    HTTPS: 'on',
    REMOTE_ADDR: '105.112.24.31',
    HTTP_USER_AGENT: 'smoke-test',
    HTTP_COOKIE: cookies,
    SCRIPT_NAME: script,
  };

  // A JSON object literal is not a PHP array literal; build the arrays with array() syntax.
  const phpArray = (obj) => 'array(' + Object.entries(obj)
    .map(([k, v]) => `${JSON.stringify(k)} => ${JSON.stringify(v)}`)
    .join(', ') + ')';

  const php = `<?php
$_SERVER = ${phpArray(setup)};
$_GET = ${phpArray(query)};
$_POST = ${phpArray(post)};
$_REQUEST = ${phpArray({ ...query, ...post })};
$_FILES = array();
foreach (${phpArray(env)} as $k => $v) { putenv($k . '=' . $v); }
chdir('/src');
// Only the entry point runs, exactly as the web server does it. Pre-requiring bootstrap here would
// define every constant twice for entries that require it themselves (api/, install/), and the
// duplicate-definition warnings look like failures the application never produces.
require '${entry}';
`;
  return withRuntime(async (runtime) => {
    if (installed) {
      await runtime.writeFile('/src/config/installed.lock', 'test');
    }
    let output = '';
    runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
    runtime.addEventListener('error', (event) => { output += String(event.detail?.[0] ?? ''); });
    await runtime.run(php);
    await new Promise((resolve) => setTimeout(resolve, 900));
    return output;
  });
}

const fatal = /Fatal error|Uncaught Error|Uncaught TypeError|Undefined constant|Undefined variable|Call to undefined function/;
const notice = /Warning:|Notice:|Deprecated:/;

/* 1. The installer's first screen — the exact page that fataled. */
const installer = await request({ uri: '/', query: {} });
check('installer renders without a fatal', !fatal.test(installer), installer.slice(0, 600));
check('installer shows the stage-1 checklist', /Server check|System check/i.test(installer));

// The bug that made setup render as unstyled HTML: a stylesheet URL resolved against
// SCRIPT_NAME (/install/index.php) instead of the front controller.
// The URL now carries a content fingerprint (?v=… or a hashed filename) so a deploy cannot leave a
// stale stylesheet in the browser. What matters here is still the *root*, not /install/.
check('installer asks for the stylesheet at the site root',
  /href="\/public\/assets\/app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?"/.test(installer),
  installer.match(/<link[^>]*app\.css[^>]*>/)?.[0] ?? 'no stylesheet tag found');
check('installer never asks for /install/public',
  !/\/install\/public\//.test(installer), 'assets must not be resolved relative to the installer');
check('installer loads the stylesheet before any content',
  installer.indexOf('app.css') < installer.indexOf('<body'), 'CSS must be in the head, not injected later');
check('installer is marked up with the wizard shell',
  /class="wizard-wrap"/.test(installer) && /class="wizard-steps"/.test(installer),
  'the wizard layout must be present');
check('installer names the running PHP version', /PHP 8\.\d/.test(installer), installer.slice(0, 300));
check('installer has no PHP notices', !notice.test(installer), installer.slice(0, 600));

// The licence step: the operator must be able to reach the provider and paste a key here.
check('installer offers a link to request a licence',
  /Request a licence/i.test(installer) && /manager\.pmhserver\.name\.ng\/order\.php/.test(installer),
  installer.slice(0, 400));
check('installer links to the sign-in page for an existing key',
  /manager\.pmhserver\.name\.ng\/user\/login\.php/.test(installer));
check('installer asks for a licence key, not a system token',
  /name="license_key"/.test(installer) && !/name="system_token"/.test(installer));
check('installer shows the domain it will license, and lets it be corrected',
  /id="license_domain"/.test(installer) && /name="license_domain"/.test(installer)
  && !/id="domain"/.test(installer));

// Same resolution question for the API and for a subdirectory install.
const apiCss = await request({ uri: '/api/index.php', entry: '/src/api/index.php', script: '/api/index.php' });
check('the api endpoint never borrows the installer path', !/\/api\/public\//.test(apiCss), apiCss.slice(0, 200));

/* 1b. bin/doctor.php must actually run. The stage-4 checklist tells the operator to run it, so a
   script that merely parses is not enough: it has to reach a verdict and say what it checked.
   php-wasm has no MySQL, so the database rows are expected to FAIL here — the assertion is that
   the script survives that and still prints a summary. */
const doctor = await withRuntime(async (runtime) => {
  await runtime.writeFile('/src/config/installed.lock', 'test');
  let out = '';
  runtime.addEventListener('output', (event) => { out += event.detail[0] ?? ''; });
  runtime.addEventListener('error', (event) => { out += String(event.detail?.[0] ?? ''); });
  await runtime.run(`<?php
chdir('/src');
$_SERVER['argv'] = array('doctor.php', '--quiet', '--no-net');
$_SERVER['argc'] = 3;
include '/src/bin/doctor.php';
`);
  await new Promise((resolve) => setTimeout(resolve, 1200));
  return out;
});
check('doctor.php runs to a verdict instead of fataling', !fatal.test(doctor), doctor.slice(0, 600));
check('doctor.php reports what it checked', /Extension pdo|config\/ writable|Licence key/.test(doctor),
  doctor.slice(0, 600));
check('doctor.php checks that config/ and storage/ are not web-readable', /\.htaccess/i.test(doctor),
  doctor.slice(0, 600));
check('doctor.php does not claim to know whether HTTPS is on from a CLI run',
  !/^ok\s+HTTPS/m.test(doctor), doctor.slice(0, 600));

/* 2. CSRF protection on the installer POST. */
const noToken = await request({ method: 'POST', uri: '/', post: { stage: '1' } });
check('installer POST without a CSRF token is refused', !fatal.test(noToken) && /csrf|security|expired|reload/i.test(noToken),
  noToken.slice(0, 500));

/* 2c. The two anonymous endpoints the registration form depends on.
   "The registration page did not list the churches" is a symptom with several possible causes, so
   what is asserted here is the property that must hold whatever the cause: the page still renders
   and still carries a usable list or a usable explanation. php-wasm has no MySQL, so the directory
   query cannot succeed — which makes this the worst case, and the right one to pin. */
{
  // /register is behind the activation gate in this harness (there is no licence key in a test
  // copy), so the view is rendered directly — the same View::capture the page controller uses. The
  // point is the view's behaviour with no database, which is the worst case for the directory.
  const registerPage = await withRuntime(async (runtime) => {
    await runtime.writeFile('/src/config/installed.lock', 'test');
    let out = '';
    runtime.addEventListener('output', (event) => { out += event.detail[0] ?? ''; });
    runtime.addEventListener('error', (event) => { out += String(event.detail?.[0] ?? ''); });
    await runtime.run(`<?php
$_SERVER = array('REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/register',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php', 'HTTP_USER_AGENT' => 'smoke');
$_GET = array(); $_POST = array(); $_REQUEST = array(); $_FILES = array();
chdir('/src');
require '/src/app/bootstrap.php';
$choosable = array();
try { $choosable = RISE63\\Core\\ParishDirectory::search('', 200); }
catch (\\Throwable $e) { $choosable = array(); }
echo RISE63\\Core\\View::capture('/src/app/Views/auth/register.php',
    array('flashes' => array(), 'page' => 'register', 'parishes' => $choosable), false);
`);
    await new Promise((resolve) => setTimeout(resolve, 900));
    return out;
  });
  check('the registration page renders without a fatal when the directory is unavailable',
    !fatal.test(registerPage), registerPage.replace(/\s+/g, ' ').slice(0, 400));
  check('the registration page still offers the manual church field',
    /name="parish_name_raw"/.test(registerPage), registerPage.slice(0, 400));
  // An empty picker with no explanation was the reported fault: the directory had no churches, so
  // the form rendered a search box that matched nothing and no way to type a church instead. The
  // page must always offer a route forward, whichever state the directory is in.
  check('the registration page never renders a bare empty picker with no explanation',
    /My church is not in the list|church directory is empty|parish_name_raw/i.test(registerPage),
    registerPage.replace(/\s+/g, ' ').slice(0, 400));

  // The API must answer JSON with a `parishes` key even when it cannot reach the database, so the
  // picker shows "could not be loaded" instead of a silent blank.
  const apiAnonymous = await request({
    uri: '/api/index.php', query: { q: 'chapel' }, script: '/api/index.php', entry: '/src/api/index.php',
    installed: true,
  });
  check('the anonymous parish API returns JSON with a parishes key',
    /\{"parishes":/.test(apiAnonymous.replace(/\s+/g, '')), apiAnonymous.slice(0, 300));
  check('the anonymous parish API does not fatal without a database',
    !fatal.test(apiAnonymous), apiAnonymous.slice(0, 400));

  // An empty term used to be refused for anonymous callers ("type three characters"), which made
  // tapping the box show nothing at all. It must now be answered.
  const apiEmpty = await request({
    uri: '/api/index.php', query: { q: '' }, script: '/api/index.php', entry: '/src/api/index.php',
    installed: true,
  });
  check('an empty search is answered rather than refused',
    /"parishes"/.test(apiEmpty) && !/"hint":"Type at least three/.test(apiEmpty.replace(/\s+/g, '')),
    apiEmpty.replace(/\s+/g, ' ').slice(0, 300));
}

/* 3. request.php / private directories are not reachable through the front controller. */
const traversal = await request({ uri: '/', query: { page: '../../config/database' } });
check('a crafted page parameter does not leak configuration',
  !fatal.test(traversal) && !/'password'\s*=>|DB_PASSWORD|mysql:host=/i.test(traversal),
  traversal.slice(0, 400));
check('a crafted page parameter still renders the expected screen',
  /Setup|Environment|System check/i.test(traversal), traversal.slice(0, 300));

/* 4. Once installed, the wizard is no longer the entry point: / shows the activation gate and
      the wizard itself refuses to run again. */
const locked = await request({ uri: '/', installed: true });
check('an installed site no longer serves the wizard', !/Setup wizard|System check/i.test(locked),
  locked.replace(/\s+/g, ' ').slice(0, 300));
check('an installed site asks for activation instead', /Activation required/i.test(locked),
  locked.replace(/\s+/g, ' ').slice(0, 300));
check('the installed guard still has no fatal', !fatal.test(locked), locked.slice(0, 400));

const wizardAgain = await request({ uri: '/install/index.php', installed: true, script: '/install/index.php', entry: '/src/install/index.php' });
check('the wizard refuses to run a second time', /already installed/i.test(wizardAgain),
  wizardAgain.replace(/\s+/g, ' ').slice(0, 300));

/* 5. Clean URLs. Every link this application emits is a path segment (/login), so the URL path —
      not just ?page= — has to select the page. This regressed into an Apache "Not Found" on the
      first real installation, where the root loaded (DirectoryIndex) and every other page 404ed. */
// No database exists in this harness, so an installed request cannot reach the login form; what
// matters is *which page* the router chose. The error screen names the page it failed on, so the
// absence of a "no page named …" error is the signal, alongside the absence of an Apache 404.
const clean = await request({ uri: '/login', installed: true, env: { RISE63_ACTIVATION: 'local-fixture' } });
check('a clean URL path selects the page without ?page=',
  !/no page named|Not Found|404 Not Found/i.test(clean), clean.replace(/\s+/g, ' ').slice(0, 300));
check('a clean URL does not fall through to a 404', !fatal.test(clean) || /database/i.test(clean),
  clean.replace(/\s+/g, ' ').slice(0, 300));

// ?page= must keep working: the rewrite, the development router and the preview harnesses use it.
const query = await request({ uri: '/', query: { page: 'login' }, installed: true, env: { RISE63_ACTIVATION: 'local-fixture' } });
check('?page=login still selects the same page',
  !/no page named/i.test(query), query.replace(/\s+/g, ' ').slice(0, 300));

// /index.php is reached directly by some hosts and must not be mistaken for a page named index.
const direct = await request({ uri: '/index.php', installed: true, env: { RISE63_ACTIVATION: 'local-fixture' } });
check('/index.php is not treated as a page of its own', !/no page named/i.test(direct), direct.slice(0, 300));

/* 6. The rewrite rule that makes those paths reach PHP. Apache is not available in this
      harness, so this tests the rule *as written* against the URLs it must and must not touch —
      not Apache's own behaviour, which only a host can demonstrate. */
const htaccess = await readFile(new URL('../../.htaccess', import.meta.url), 'utf8');
const rule = htaccess.match(/^\s*RewriteRule\s+(\^\S+)\s+index\.php\?page=\$1/m);
check('the front-controller rewrite exists in .htaccess', rule !== null,
  'without it Apache answers /login with its own 404 before PHP runs');
if (rule) {
  const pattern = new RegExp(rule[1]);
  check('the rewrite maps a bare page segment', pattern.test('login') && pattern.test('treasury/'),
    rule[1]);
  check('the rewrite leaves nested asset paths alone', !pattern.test('public/assets/app.css'),
    'assets are served directly, never through the router');
  // Real files and directories are excluded by the RewriteCond !-f / !-d pairs above the rule.
  check('the rewrite is guarded by !-f and !-d conditions',
    /RewriteCond %\{REQUEST_FILENAME\} !-f/.test(htaccess) && /RewriteCond %\{REQUEST_FILENAME\} !-d/.test(htaccess),
    'without these, real files such as manifest.webmanifest would be routed through PHP');
}

console.log('\n' + '─'.repeat(62));
const total = checks;
console.log(`${total - failures.length} passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
