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

  console.log('\nDeleting a project');

  /*
   * project delete touches five tables and unlinks files, so it is worth
   * driving with a fake PDO: php-wasm has no MySQL. The fake answers by table
   * name, and the endpoint's own response tells us which statements ran —
   * "licences_detached" means the UPDATE path, "licences_deleted" the DELETE.
   */
  const phpMap = obj => 'array(' + Object.entries(obj)
    .map(([k, v]) => JSON.stringify(String(k)) + ' => ' + JSON.stringify(String(v))).join(', ') + ')';

  const FAKE_DB = `
    require_once '/lm/includes/updates.php';
    if (!class_exists('QAStmt')) {
        class QAStmt implements IteratorAggregate {
            public $sql; public $rows = array(); public $affected = 0;
            public function __construct($sql) { $this->sql = $sql; }
            // PDOStatement is iterable, and projects.php loops over the statement.
            public function getIterator(): Traversable { return new ArrayIterator($this->rows); }
            public function execute(array $args = array()) {
                $q = strtolower(preg_replace('/\\s+/', ' ', $this->sql));
                $c = $GLOBALS['qa_counts'];
                if (strpos($q, 'select * from script_tiers') !== false) { $this->rows = array($GLOBALS['qa_tier']); return true; }
                if (strpos($q, 'select id from licenses') !== false) {
                    $this->rows = array();
                    for ($i = 1; $i <= (int) $c['licenses']; $i++) { $this->rows[] = array($i); }
                    return true;
                }
                foreach (array('licenses', 'script_updates', 'github_pulls', 'github_pull_jobs', 'update_notifications') as $table) {
                    if (strpos($q, 'count(*) from ' . $table) !== false) {
                        $n = isset($c[$table]) ? (int) $c[$table] : 0;
                        if ($table === 'github_pull_jobs' && strpos($q, 'queued') !== false) {
                            $n = isset($c['jobs_running']) ? (int) $c['jobs_running'] : 0;
                        }
                        $this->rows = array(array($n));
                        return true;
                    }
                }
                if (strpos($q, 'update licenses set tier_id') !== false) {
                    $this->affected = (int) $c['licenses'];
                    $this->rows = array(array((int) $args[0]));
                    return true;
                }
                if (strpos($q, 'select id, tier_name from script_tiers') !== false) {
                    $this->rows = array(array('id' => '9', 'tier_name' => 'DGV7.0 NON-SAAS'));
                    return true;
                }
                if (strpos($q, 'select id, tier_name, tier_code from script_tiers') !== false) {
                    $this->rows = array(array('id' => '9', 'tier_name' => 'DGV7.0 NON-SAAS', 'tier_code' => 'NON-SAAS'));
                    return true;
                }
                if (strpos($q, 'delete from licenses') !== false) { $this->affected = (int) $c['licenses']; return true; }
                if (strpos($q, 'delete from script_updates') !== false) { $this->affected = (int) $c['script_updates']; return true; }
                if (strpos($q, 'delete from update_notifications') !== false) { $this->affected = (int) $c['update_notifications']; return true; }
                if (strpos($q, 'delete from github_pulls') !== false) { $this->affected = (int) $c['github_pulls']; return true; }
                if (strpos($q, 'delete from github_pull_jobs') !== false) { $this->affected = (int) $c['github_pull_jobs']; return true; }
                if (strpos($q, 'delete from script_tiers') !== false) { $this->affected = 1; return true; }
                return true;
            }
            public function fetch($mode = 0) { return $this->rows ? $this->rows[0] : false; }
            public function fetchColumn() { return $this->rows ? (int) $this->rows[0][0] : 0; }
            public function fetchAll($mode = 0) {
                if ($mode === PDO::FETCH_COLUMN) { return array_map(function ($r) { return $r[0]; }, $this->rows); }
                return $this->rows;
            }
            public function rowCount() { return $this->affected; }
        }
    }
    if (!class_exists('QAPdo')) {
        class QAPdo {
            public $inTx = false;
            public function prepare($sql) { return new QAStmt($sql); }
            public function query($sql) { return new QAStmt($sql); }
            public function exec($sql) { return 0; }
            public function beginTransaction() { $this->inTx = true; return true; }
            public function commit() { $this->inTx = false; return true; }
            public function rollBack() { $this->inTx = false; return true; }
            public function inTransaction() { return $this->inTx; }
        }
    }
    $GLOBALS['qa_pdo'] = new QAPdo();
  `;

  async function deleteCall(counts, post, extra = '') {
    const raw = await run(`
      ${FAKE_DB}
      $GLOBALS['qa_counts'] = ${phpMap(counts)};
      $GLOBALS['qa_tier'] = array('id' => '7', 'tier_name' => 'QA Project', 'tier_code' => 'QA', 'project_key' => 'PRJ-QA');
      ${extra}
      require_once '/lm/includes/security.php';
      lm_session_start();
      $_SESSION['admin_logged_in'] = true;
      $_SESSION['lm_csrf'] = 'qa-token';
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $_POST = ${phpMap(Object.assign({ action: 'delete', id: '7', csrf_token: 'qa-token' }, post))};
      require '/lm/admin/ajax_projects.php';`, '/lm/admin/ajax_projects.php', { fresh: true });
    try { return JSON.parse(raw); } catch (e) { return { status: 'unparsed', raw: raw }; }
  }

  const busy = { licenses: '14', script_updates: '3', github_pulls: '2', github_pull_jobs: '1', jobs_running: '1', update_notifications: '5' };

  const plan = await deleteCall(busy, {});
  ok('the first call returns a plan instead of deleting', plan.status === 'confirm', JSON.stringify(plan).slice(0, 300));
  ok('the plan counts what is attached',
    plan.impact && plan.impact.licences === 14 && plan.impact.updates === 3 && plan.impact.notifications === 5,
    JSON.stringify(plan.impact));

  const noChoice = await deleteCall(busy, { confirm: '1' });
  ok('a confirmed delete without a licence decision is refused',
    noChoice.status === 'error' && noChoice.code === 'licence_mode_required', JSON.stringify(noChoice).slice(0, 300));

  const detach = await deleteCall(busy, { confirm: '1', licence_mode: 'detach' });
  ok('detach deletes the project', detach.status === 'success', JSON.stringify(detach).slice(0, 300));
  ok('detach keeps every licence', detach.deleted && detach.deleted.licences_detached === 14 && detach.deleted.licences_deleted === 0,
    JSON.stringify(detach.deleted));
  ok('detach says the licences were kept', has(detach.message || '', 'kept'), detach.message);
  ok('detach removes the versions', detach.deleted.updates === 3 && has(detach.message || '', '3 versions'), detach.message);

  const moved = await deleteCall(busy, { confirm: '1', licence_mode: 'detach', move_to: '9' });
  ok('licences can be handed to another project',
    moved.status === 'success' && moved.deleted.licences_moved === 14 && moved.deleted.licences_detached === 0,
    JSON.stringify(moved.deleted));
  ok('the operator is told where they went', has(moved.message || '', 'moved to DGV7.0 NON-SAAS'), moved.message);

  const planTargets = await deleteCall(busy, {});
  ok('the plan offers the other projects as destinations',
    Array.isArray(planTargets.targets) && planTargets.targets.length === 1 && planTargets.targets[0].id === 9,
    JSON.stringify(planTargets.targets));

  const purge = await deleteCall(busy, { confirm: '1', licence_mode: 'delete' });
  ok('the delete mode is honoured when chosen', purge.status === 'success' && purge.deleted.licences_deleted === 14 && purge.deleted.licences_detached === 0,
    JSON.stringify(purge.deleted));

  const empty = await deleteCall(
    { licenses: '0', script_updates: '0', github_pulls: '0', github_pull_jobs: '0', jobs_running: '0', update_notifications: '0' },
    { confirm: '1' },
    `$dir = lm_tier_storage_dir(array('tier_code' => 'QA'), true);
     file_put_contents($dir . '/v1.zip', str_repeat('x', 2048));`
  );
  ok('a project with nothing attached deletes without a licence question', empty.status === 'success', JSON.stringify(empty).slice(0, 300));
  ok('its package files are removed from disk', empty.deleted && empty.deleted.files === 1, JSON.stringify(empty.deleted));

  console.log('\nInline JavaScript');
  /*
   * The console's retry and the health page's network test are long inline
   * scripts. A syntax error in one of them breaks the button silently, so
   * parse every <script> block with V8 before shipping.
   */
  const pages = [
    ['health.php', "require '/lm/health.php';", '/lm/health.php'],
    ['admin/index.php', "require '/lm/admin/index.php';", '/lm/admin/index.php'],
    ['admin/projects.php', "require_once '/lm/includes/security.php';\n"
      + "require_once '/lm/admin/_ui.php';\n"
      + FAKE_DB + "\n"
      + "$GLOBALS['qa_counts'] = array('licenses' => '0', 'script_updates' => '0', 'github_pulls' => '0', 'github_pull_jobs' => '0', 'jobs_running' => '0', 'update_notifications' => '0');\n"
      + "lm_session_start();\n"
      + "$_SESSION['admin_logged_in'] = true;\n"
      + "require '/lm/admin/projects.php';", '/lm/admin/projects.php'],
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
