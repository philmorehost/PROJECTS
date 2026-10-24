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
  ok('the banner names the file and the fix', has(banner, 'admin/ajax_github.php') && has(banner, 'Re-upload'), banner.slice(0, 500));
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

  console.log('\nHealth check');
  await withEditedFile('/lm/admin/ajax_github.php', '\n// edited after upload\n');
  const health = await run(`require '/lm/health.php';`, '/lm/health.php', { chdir: '/lm' });
  ok('health.php names the installed version', has(health, manifest.version), '');
  ok('health.php reports the manifest it checks against',
    has(health, 'Release manifest') && has(health, String(files.length)), health.slice(0, 400));
  ok('health.php lists the stale file', has(health, 'admin/ajax_github.php'), '');
  ok('health.php shows no PHP diagnostic',
    !/Parse error|Fatal error|Undefined variable|Warning:/.test(health), health.slice(0, 400));

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('FAILED', e); process.exit(1); });
