/**
 * Sessions on a host that can change PHP version under the panel.
 *
 * The failure this pins down: cPanel switches the account from PHP 7.4 to 8.3,
 * session.save_path points at a per-version folder that does not exist, and
 * every request therefore starts an empty session. The sign-in form posts its
 * token, the new session has never heard of it, and the panel answers
 * "Your session expired. Refresh the page and try again." — a sentence that
 * sends the operator looking for a timeout that never happened.
 *
 * So: the session must still start when the configured folder is unusable, the
 * id must be legal to put in a cookie (6 bits/char can emit "," — RFC 6265
 * forbids it), and a failed check must name its cause.
 *
 * Usage: node qa/session.cjs
 */
const fs = require('fs');
const path = require('path');
const { boot } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;
const ok = (name, condition, detail) => {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 320) : '')); }
};

const text = html => String(html)
  .replace(/<style[\s\S]*?<\/style>/gi, ' ')
  .replace(/<[^>]+>/g, ' ')
  .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&')
  .replace(/\s+/g, ' ');

const START = `
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    lm_session_start();
`;

const json = raw => {
  const m = String(raw).match(/\{[\s\S]*\}/);
  if (!m) return null;
  try { return JSON.parse(m[0]); } catch (e) { return null; }
};

(async () => {
  const { run, write } = await boot();
  await write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  console.log('\nA healthy session');
  const healthy = json(await run(START + `
    $c = session_get_cookie_params();
    echo json_encode(array(
      'status' => session_status(),
      'name' => session_name(),
      'id' => session_id(),
      'httponly' => !empty($c['httponly']),
      'samesite' => (string) ($c['samesite'] ?? ''),
      'path' => (string) ($c['path'] ?? ''),
      'error' => (string) ($GLOBALS['lm_session_error'] ?? ''),
      'failure' => lm_session_failure(),
      'php' => PHP_VERSION,
    ));
  `, '/lm/admin/index.php', { fresh: true }));
  ok('the session starts', healthy && healthy.status === 2, JSON.stringify(healthy));
  ok('under a name of its own', healthy && healthy.name === 'lm_session', healthy && healthy.name);
  ok('the id is legal in a cookie (no comma)', healthy && /^[A-Za-z0-9,-]+$/.test(healthy.id) && !healthy.id.includes(','),
    healthy && healthy.id);
  ok('the cookie is HttpOnly, SameSite=Lax, path=/',
    healthy && healthy.httponly && healthy.samesite.toLowerCase() === 'lax' && healthy.path === '/',
    JSON.stringify(healthy && { httponly: healthy.httponly, samesite: healthy.samesite, path: healthy.path }));
  ok('nothing is reported as broken', healthy && healthy.failure === '', healthy && healthy.failure);
  ok('the runtime is 7.4 or newer', healthy && /^[78]\./.test(healthy.php), healthy && healthy.php);

  console.log('\nA save_path that a PHP switch left behind');
  const broken = json(await run(`
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    ini_set('session.save_path', '/var/cpanel/php/sessions/ea-php99');
    $dir = lm_session_save_dir();
    lm_session_start();
    echo json_encode(array(
      'dir' => $dir,
      'ini' => (string) ini_get('session.save_path'),
      'status' => session_status(),
      'failure' => lm_session_failure(),
      'guarded' => file_exists(lm_storage_dir() . '/sessions/index.php'),
    ));
  `, '/lm/admin/index.php', { fresh: true }));
  ok('the missing folder is detected', broken && broken.dir && broken.dir.fallback === true, JSON.stringify(broken && broken.dir));
  ok('a folder the panel owns is used instead',
    broken && /storage[\/\\\\]sessions$/.test(String(broken.dir.path).replace(/\\\\/g, '/')),
    broken && broken.dir && broken.dir.path);
  ok('PHP is actually pointed at it', broken && /storage[\/\\\\]sessions$/.test(String(broken.ini)), broken && broken.ini);
  ok('the session still starts', broken && broken.status === 2, JSON.stringify(broken));
  ok('and sign-in is not blocked by a notice', broken && broken.failure === '', broken && broken.failure);

  console.log('\nWhy a CSRF check failed');
  const cases = [
    {
      name: 'both tokens present but different',
      session: { lm_csrf: 'stored-value' },
      post: { csrf_token: 'posted-value' },
      cookie: true,
      expect: value => value === '',
    },
    {
      name: 'the POST carried no token at all',
      session: {},
      post: {},
      cookie: true,
      expect: value => /without its security token/i.test(value),
    },
    {
      name: 'the browser sent no session cookie',
      session: {},
      post: {},
      cookie: false,
      expect: value => /did not send the session cookie/i.test(value),
    },
    {
      name: 'a token arrived for a session that knows nothing',
      session: {},
      post: { csrf_token: 'from-the-form' },
      cookie: true,
      expect: value => /not saving session data/i.test(value),
    },
  ];

  for (const c of cases) {
    const got = await run(`
      require_once '/lm/includes/security.php';
      require_once '/lm/includes/lm_config.php';
      lm_session_start();
      $_SESSION = json_decode(${JSON.stringify(JSON.stringify(c.session))}, true);
      $_POST = json_decode(${JSON.stringify(JSON.stringify(c.post))}, true);
      ${c.cookie ? "$_COOKIE['lm_session'] = session_id();" : 'unset($_COOKIE["lm_session"]);'}
      echo lm_csrf_failure();
    `, '/lm/admin/index.php', { fresh: true });
    ok(c.name, c.expect(got), got);
  }

  console.log('\nThe sign-in page says what went wrong');
  const login = text(await run(`
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_pdo'] = new QAPdo();
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    lm_session_start();
    $_SESSION = array();               // the session the browser came back with was empty
    $_POST = array('username' => 'admin', 'password' => 'whatever', 'csrf_token' => 'stale-form-token');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    require '/lm/admin/index.php';
  `, '/lm/admin/index.php', { fresh: true }));

  ok('it no longer claims the session expired', !/Your session expired/i.test(login), login.slice(0, 400));
  ok('it names the real cause', /not saving session data|session\.save_path/i.test(login), login.slice(0, 400));
  ok('it points somewhere actionable', /MultiPHP INI Editor|health\.php/i.test(login), login.slice(0, 400));

  console.log('\nSystem Check shows the session state');
  await write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));
  const check = text(await run(`
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_pdo'] = new QAPdo();
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['lm_csrf'] = 'qa-token';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = array();
    require '/lm/admin/system-check.php';
  `, '/lm/admin/system-check.php', { fresh: true }));

  ok('there is a PHP & sessions row', /PHP & sessions/i.test(check), check.slice(0, 400));
  ok('it names the PHP version on the server', new RegExp('PHP ' + (healthy ? healthy.php.replace(/\./g, '\\.') : '\\d+\\.\\d+')).test(check), check.slice(0, 400));
  ok('it shows session.save_path and whether it is writable', /session\.save_path.*?(writable|NOT WRITABLE)/i.test(check), check.slice(0, 600));

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('FAILED', e); process.exit(1); });
