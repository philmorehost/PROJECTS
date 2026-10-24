/**
 * Contract tests for the release-integrity work (9.0.2).
 *
 * Every check asserts on text a human would read, because each of these paths
 * ends in a message on screen: a bare "403 Forbidden" is the bug being fixed,
 * not the expected output.
 *
 * Two things about the harness matter when reading this file:
 *   - `lm_json()` exits, so a probe that calls it ends its runtime.
 *   - PHP statics survive between probes in the same runtime, so anything that
 *     needs a fresh look at the disk runs after `{ fresh: true }`.
 *
 * Usage: node qa/contracts.cjs
 */
const fs = require('fs');
const path = require('path');
const { boot, LM } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;

function ok(name, condition, detail) {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 320) : '')); }
}

const has = (haystack, needle) => String(haystack).toLowerCase().includes(String(needle).toLowerCase());
const BOOTSTRAP = "require_once '/lm/includes/lm_config.php';\n";
const GITHUB = BOOTSTRAP + "require_once '/lm/includes/github.php';\n";
const phpArray = obj => 'array('
  + Object.entries(obj).map(([k, v]) => JSON.stringify(k) + ' => ' + JSON.stringify(String(v))).join(', ')
  + ')';

(async () => {
  const { run, write } = await boot();

  /**
   * Fresh runtime with one file sabotaged.
   *
   * The reboot has to come first: lm_release_state() caches its verdict in a
   * static, and a reboot after the edit would restore the pristine file.
   */
  async function withEditedFile(file, append) {
    await run("<?php echo 'reset';", '/lm/.qa/reset.php', { fresh: true });
    const original = fs.readFileSync(path.join(LM, file.replace(/^\/lm\//, '')));
    await write(file, Buffer.concat([original, Buffer.from(append)]));
    return original;
  }

  console.log('\nJSON envelope');
  const json = await run(BOOTSTRAP + `lm_json(array('status' => 'success', 'message' => 'hello'));`);
  let parsed = null;
  try { parsed = JSON.parse(json); } catch (e) {}
  ok('every lm_json payload carries server_version', parsed && parsed.server_version, json);
  ok('server_version looks like a release', parsed && /^\d+\.\d+\.\d+/.test(parsed.server_version), json);

  console.log('\nRelease manifest');
  const manifest = JSON.parse(fs.readFileSync(path.join(LM, 'build.json'), 'utf8'));
  const files = Object.keys(manifest.files);
  ok('build.json lists the console endpoints',
    files.includes('admin/ajax_github.php') && files.includes('admin/ajax_projects.php'));
  ok('build.json lists the session bootstrap',
    files.includes('includes/security.php') && files.includes('includes/lm_config.php'));
  ok('build.json excludes settings, docs and runtime data',
    !files.includes('settings.json') && !files.some(f => /^(storage|docs|uploads)\//.test(f)));

  const stateClean = await run(BOOTSTRAP + `echo json_encode(lm_release_state());`, '/lm/admin/ajax_github.php', { fresh: true });
  let clean = null;
  try { clean = JSON.parse(stateClean); } catch (e) {}
  ok('a folder that matches build.json reports ok', clean && clean.ok === true && clean.checked === true, stateClean);

  console.log('\nMixed release detected before a click');
  await withEditedFile('/lm/admin/ajax_github.php', '\n// edited after upload\n');

  const stateStale = await run(BOOTSTRAP + `echo json_encode(lm_release_state());`, '/lm/admin/dashboard.php');
  let stale = null;
  try { stale = JSON.parse(stateStale); } catch (e) {}
  ok('one edited endpoint makes the release stale', stale && stale.ok === false && stale.reason === 'stale_file', stateStale);
  ok('the report names the stale file', stale && stale.files && stale.files.includes('admin/ajax_github.php'), stateStale);

  const banner = await run(`
    require_once '/lm/includes/security.php';
    require_once '/lm/admin/_ui.php';
    lm_admin_page_start('Pull update');
    lm_admin_page_end();`, '/lm/admin/github-updates.php');
  ok('the console says so on load, before the admin clicks Pull',
    has(banner, 'not from the release') || has(banner, 'Mixed release'), banner.slice(0, 500));
  ok('the banner names a file and the fix',
    (has(banner, 'admin/ajax_github.php') || has(banner, 'security.php')) && has(banner, 'Re-upload'),
    banner.slice(0, 500));
  ok('the banner links to the installation check', has(banner, 'health.php'), '');

  const login = await run("require '/lm/admin/index.php';", '/lm/admin/index.php');
  ok('the sign-in page warns before the first failed click', has(login, 'mix of two releases'), login.slice(0, 400));

  console.log('\nSession hint for a pre-9.0 console file');
  await write('/lm/admin/ajax_legacy.php', '<?php\nsession_start();\necho "old";\n');
  const hint = await run(BOOTSTRAP + `echo lm_stale_release_hint();`, '/lm/admin/ajax_legacy.php');
  ok('a legacy-session file gets a sentence, not a bare 403', has(hint, 'older release') && hint.trim().length > 40, hint);
  ok('the sentence says what to upload', has(hint, 'admin/') && has(hint, 'Re-upload'), hint);

  const hardened = await run(BOOTSTRAP + `
    @session_name(lm_session_name());
    @session_start();
    echo '[' . lm_stale_release_hint() . ']';`, '/lm/admin/dashboard.php', { fresh: true });
  ok('a file on the hardened bootstrap is not accused', hardened.trim() === '[]', hardened);

  console.log('\nGitHub 403 classification');
  const future = String(Math.floor(Date.now() / 1000) + 1800);
  const cases = [
    {
      name: 'rate limit gives the reset time and the anonymous limit',
      headers: { 'x-ratelimit-remaining': '0', 'x-ratelimit-reset': future },
      message: 'API rate limit exceeded',
      token: false,
      expect: ['rate limit', 'UTC', 'No token is saved'],
    },
    {
      name: 'a token that GitHub ignores is called out',
      headers: { 'x-ratelimit-remaining': '0', 'x-ratelimit-reset': '0' },
      message: 'API rate limit exceeded',
      token: true,
      expect: ['token is saved', 'clear it'],
    },
    {
      name: 'SSO authorisation is named',
      headers: { 'x-github-sso': 'required; url=https://github.com/orgs/x/sso' },
      message: 'Resource protected by organization SAML enforcement',
      token: true,
      expect: ['SAML SSO', 'authorise'],
    },
    {
      name: 'fine-grained access is explained',
      headers: {},
      message: 'Resource not accessible by personal access token',
      token: true,
      expect: ['Contents: Read', 'Repository access'],
    },
    {
      name: 'a classic token without repo scope is caught',
      headers: { 'x-oauth-scopes': 'read:user, gist' },
      message: 'Forbidden',
      token: true,
      expect: ['read:user, gist', 'needs "repo"'],
    },
    {
      name: 'a plain 403 still tells you what to check',
      headers: {},
      message: 'Forbidden',
      token: true,
      expect: ['GitHub refused', 'check the token'],
    },
  ];

  for (const c of cases) {
    const raw = await run(GITHUB + `
      $v = lm_github_forbidden_error(${phpArray(c.headers)}, ${JSON.stringify(c.message)}, ${c.token ? 'true' : 'false'});
      echo json_encode($v);`, '/lm/probe-github.php');
    let v = null;
    try { v = JSON.parse(raw); } catch (e) {}
    const text = v ? v.error + ' ' + (v.hints || []).join(' ') : raw;
    const missing = c.expect.filter(word => !has(text, word));
    ok(c.name, v && missing.length === 0, missing.length ? 'missing: ' + missing.join(', ') + ' — ' + text : '');
  }

  const rateFlag = await run(GITHUB + `
    $v = lm_github_forbidden_error(array('x-ratelimit-remaining' => '0'), 'API rate limit exceeded', false);
    echo $v['rate_limited'] ? 'yes' : 'no';`, '/lm/probe-github.php');
  ok('the caller can tell a rate limit from a permission problem', rateFlag.trim() === 'yes', rateFlag);

  console.log('\nEncoded retry (WAF workaround)');
  const decoded = await run("require_once '/lm/includes/security.php';\n" + `
    $_SERVER['HTTP_X_LM_ENCODING'] = 'base64';
    $_POST = array(
        'action' => base64_encode('pull'),
        'changelog' => base64_encode('[Fixed] ünïcode & <tags>'),
        'paths' => array(base64_encode('admin/'), base64_encode('includes/')),
        '_encoding' => 'base64',
    );
    $mode = lm_request_decode();
    echo json_encode(array('mode' => $mode, 'post' => $_POST));`, '/lm/admin/ajax_github.php');
  let d = null;
  try { d = JSON.parse(decoded); } catch (e) {}
  ok('an encoded request is decoded before CSRF is checked', d && d.mode === 'base64' && d.post.action === 'pull', decoded);
  ok('unicode and HTML survive the round trip', d && d.post.changelog === '[Fixed] ünïcode & <tags>', decoded);
  ok('repeated fields stay arrays', d && Array.isArray(d.post.paths) && d.post.paths[1] === 'includes/', decoded);
  ok('the marker field is removed', d && !('_encoding' in d.post), decoded);

  const plain = await run("require_once '/lm/includes/security.php';\n" + `
    unset($_SERVER['HTTP_X_LM_ENCODING']);
    $_POST = array('action' => 'pull');
    echo '[' . lm_request_decode() . ']' . $_POST['action'];`, '/lm/admin/ajax_github.php');
  ok('a plain request is left untouched', plain.trim() === '[]pull', plain);

  console.log('\nReachability probes');
  const ping = await run("require '/lm/admin/ping.php';", '/lm/admin/ping.php', { chdir: '/lm/admin' });
  let p = null;
  try { p = JSON.parse(ping); } catch (e) {}
  ok('admin/ping.php answers JSON without any include', p && p.ok === true && p.method === 'GET', ping);
  ok('admin/ping.php names the version it is running', p && /^\d+\.\d+\.\d+$/.test(p.lm_version), ping);

  const endpointPing = await run(`
    require_once '/lm/includes/security.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['lm_csrf'] = 'qa-token';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('action' => 'ping', 'csrf_token' => 'qa-token');
    require '/lm/admin/ajax_github.php';`, '/lm/admin/ajax_github.php', { fresh: true });
  let ep = null;
  try { ep = JSON.parse(endpointPing); } catch (e) {}
  ok('ajax_github.php answers a signed-in ping', ep && ep.status === 'success' && ep.csrf === 'ok', endpointPing);

  console.log('\nHealth check');
  await withEditedFile('/lm/admin/ajax_github.php', '\n// edited after upload\n');
  const health = await run(`require '/lm/health.php';`, '/lm/health.php', { chdir: '/lm' });
  ok('health.php names the installed version', has(health, manifest.version), '');
  ok('health.php reports the manifest it checks against',
    has(health, 'Release manifest') && has(health, String(files.length)), health.slice(0, 400));
  ok('health.php lists the stale file', has(health, 'admin/ajax_github.php'), '');
  ok('health.php shows no PHP diagnostic',
    !/Parse error|Fatal error|Undefined variable|Warning:/.test(health), health.slice(0, 400));

  console.log('\nInline JavaScript');
  /*
   * The console's retry and the health page's network test are long inline
   * scripts. A syntax error in one of them breaks the button silently, so
   * parse every <script> block with V8 before shipping.
   */
  const pages = [
    ['health.php', "require '/lm/health.php';", '/lm/health.php'],
    ['admin/index.php', "require '/lm/admin/index.php';", '/lm/admin/index.php'],
    ['admin/_ui.php', "require_once '/lm/includes/security.php';\n"
      + "require_once '/lm/admin/_ui.php';\n"
      + "lm_admin_page_start('QA');\nlm_admin_page_end();", '/lm/admin/dashboard.php'],
  ];

  for (const [file, code, identity] of pages) {
    const html = await run(code, identity, { fresh: true, chdir: '/lm' });
    const blocks = [...html.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1]);
    const broken = [];
    for (const block of blocks) {
      if (block.trim() === '') continue;
      try { new Function(block); } catch (e) { broken.push(e.message); }
    }
    ok(file + ' has valid inline JavaScript (' + blocks.length + ' block(s))', broken.length === 0, broken.join('; '));
  }

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('FAILED', e); process.exit(1); });
