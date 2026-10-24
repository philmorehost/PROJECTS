/**
 * The System Check integrity scanner, driven against a planted shell.
 *
 * The page that reported two false positives in three findings is not useful:
 * an operator who learns to ignore it will ignore the real one too. These
 * checks pin down both sides — it must catch a dropper, and it must not accuse
 * the files that make up the panel.
 *
 * Usage: node qa/system-check.cjs
 */
const fs = require('fs');
const path = require('path');
const { boot, LM } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;
const ok = (name, condition, detail) => {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 320) : '')); }
};

const SHELL = `<?php
/**
 * WordPress Recovery Mode Background Service
 * @package WordPress
 */
class WP_Theme_Worker_slhi {
    private $secure_key;
    public function __construct() {
        $this->secure_key = @hex2bin( '19d8d3c5351406ca2876e62c69387babdc398439b3795631edbd4b5ee6adfb5e' );
    }
    public function do_maintenance_cron() { return $this->route_request( $_REQUEST ); }
    private function route_request($args) { return shell_exec('id'); }
}
`;

const PRELUDE = `
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_pdo'] = new QAPdo();
    require_once '/lm/includes/security.php';
    require_once '/lm/includes/lm_config.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['lm_csrf'] = 'qa-token';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = array();
`;

async function page(run, extra = '') {
  return run(PRELUDE + extra + "\n    require '/lm/admin/system-check.php';", '/lm/admin/system-check.php');
}

const text = html => String(html)
  .replace(/<style[\s\S]*?<\/style>/gi, ' ')
  .replace(/<[^>]+>/g, ' ')
  .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&')
  .replace(/\s+/g, ' ');

(async () => {
  const { run, write } = await boot();
  await write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  console.log('\nA clean release is not accused');
  const clean = await page(run);
  const cleanText = text(clean);
  ok('no web-shell finding on the shipped tree', !/Web shell found/i.test(cleanText), cleanText.slice(0, 300));
  ok('the scanner does not report itself', !/system-check\.php[^.]*WP_Theme_Worker/i.test(cleanText), '');
  ok('lm_config.php is not flagged', !/lm_config\.php/.test(cleanText), cleanText.slice(0, 400));
  ok('it says how many files it read', /\d+ PHP files scanned/.test(cleanText), cleanText.slice(0, 300));
  ok('there is no quarantine form when nothing is wrong', !/name="remove_file"/.test(clean), '');

  console.log('\nA planted shell is caught');
  await write('/lm/admin-cron-uvdv.php', SHELL);
  const dirty = await page(run);
  const dirtyText = text(dirty);
  ok('the shell is named', /admin-cron-uvdv\.php/.test(dirtyText), dirtyText.slice(0, 400));
  ok('it says what the file is', /remote-access shell/i.test(dirtyText), dirtyText.slice(0, 400));
  ok('the operator gets a quarantine control', /name="remove_file"/.test(dirty), '');
  ok('the control asks for the file name', /type admin-cron-uvdv\.php/.test(dirty), '');
  ok('the quarantine button starts disabled', /<button type="submit" disabled>/.test(dirty), '');
  ok('credential rotation is spelled out',
    /database password/i.test(dirtyText) && /GitHub token/i.test(dirtyText), dirtyText.slice(0, 500));

  console.log('\nA file that is not in the release is caught');
  await write('/lm/admin/tmp-upload.php', '<?php echo "hello";');
  const extra = await page(run);
  const extraText = text(extra);
  ok('an unexpected file is listed', /tmp-upload\.php/.test(extraText), extraText.slice(0, 500));
  ok('it is labelled as not part of the release', /not in build\.json/i.test(extraText), extraText.slice(0, 500));

  console.log('\nOnly a deliberate click removes anything');
  const before = await run("<?php echo file_exists('/lm/admin-cron-uvdv.php') ? 'yes' : 'no';");

  const wrongName = await page(run, `
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('csrf_token' => 'qa-token', 'remove_file' => 'admin-cron-uvdv.php', 'confirm_name' => 'admin-cron-uvdv');`);
  ok('a mistyped name is refused', /Type the file name exactly/i.test(text(wrongName)), text(wrongName).slice(0, 200));

  const noCsrf = await page(run, `
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('remove_file' => 'admin-cron-uvdv.php', 'confirm_name' => 'admin-cron-uvdv.php');`);
  ok('a request without a CSRF token is refused', /session expired/i.test(text(noCsrf)), text(noCsrf).slice(0, 200));

  const traversal = await page(run, `
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('csrf_token' => 'qa-token', 'remove_file' => '../includes/lm_config.php', 'confirm_name' => 'lm_config.php');`);
  ok('a path outside the folder is refused', /not on the list of findings|not a file inside/i.test(text(traversal)),
    text(traversal).slice(0, 200));
  const configSafe = await run("<?php echo file_exists('/lm/includes/lm_config.php') ? 'yes' : 'no';");
  ok('lm_config.php is still there after that attempt', configSafe.trim() === 'yes', configSafe);

  const unknown = await page(run, `
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('csrf_token' => 'qa-token', 'remove_file' => 'admin/ping.php', 'confirm_name' => 'ping.php');`);
  ok('a file that is not a finding cannot be removed',
    /not on the list of findings/i.test(text(unknown)), text(unknown).slice(0, 200));
  const pingSafe = await run("<?php echo file_exists('/lm/admin/ping.php') ? 'yes' : 'no';");
  ok('a release file survives that attempt', pingSafe.trim() === 'yes', pingSafe);

  const stillThere = await run("<?php echo file_exists('/lm/admin-cron-uvdv.php') ? 'yes' : 'no';");
  ok('the shell survived every refused attempt', before.trim() === 'yes' && stillThere.trim() === 'yes', stillThere);

  console.log('\nQuarantine, not delete');
  const removed = await page(run, `
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('csrf_token' => 'qa-token', 'remove_file' => 'admin-cron-uvdv.php', 'confirm_name' => 'admin-cron-uvdv.php');`);
  const removedText = text(removed);
  ok('the shell is gone from its original name',
    (await run("<?php echo file_exists('/lm/admin-cron-uvdv.php') ? 'yes' : 'no';")).trim() === 'no');
  ok('it is kept as evidence', /moved to .*admin-cron-uvdv\.php\.removed-/i.test(removedText), removedText.slice(0, 300));
  ok('the page says it is no longer executable', /no longer executable/i.test(removedText), removedText.slice(0, 300));
  ok('the page tells you to rotate credentials', /[Rr]otate every credential/.test(removedText), removedText.slice(0, 300));
  ok('the finding disappears on the same load', !/Web shell found/i.test(removedText), removedText.slice(0, 300));

  const backup = (await run("<?php $g = glob('/lm/.*.removed-*'); echo $g ? $g[0] : '';")).trim();
  ok('the backup sits in the same folder', /\/lm\/\.[^/]*\.removed-/.test(backup), backup);
  const backupBody = await run(`<?php echo file_exists(${JSON.stringify(backup)}) ? substr(file_get_contents(${JSON.stringify(backup)}), 0, 400) : 'missing';`);
  ok('the backup has the original bytes', /WP_Theme_Worker/.test(backupBody), backupBody);

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('FAILED', e); process.exit(1); });
