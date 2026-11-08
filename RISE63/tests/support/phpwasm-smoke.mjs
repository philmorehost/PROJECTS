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
const check = (name, condition, detail = '') => {
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
require_once '/src/app/bootstrap.php';   // defines the constants once per runtime
require '${entry}';                // bootstrap.php is required_once there too
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
check('installer shows the stage-1 checklist', /Setup|Environment|System check/i.test(installer));
check('installer names the running PHP version', /PHP 8\.\d/.test(installer), installer.slice(0, 300));
check('installer has no PHP notices', !notice.test(installer), installer.slice(0, 600));

/* 2. CSRF protection on the installer POST. */
const noToken = await request({ method: 'POST', uri: '/', post: { stage: '1' } });
check('installer POST without a CSRF token is refused', !fatal.test(noToken) && /csrf|security|expired|reload/i.test(noToken),
  noToken.slice(0, 500));

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

console.log('\n' + '─'.repeat(62));
const total = 11;
console.log(`${total - failures.length} passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
