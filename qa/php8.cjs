/**
 * Render the panel on PHP 8.x with display_errors on.
 *
 * The production host can run 7.4 through 8.5 (cPanel EA-PHP). php-wasm is
 * 8.4, which is close enough to the top of that range to catch the removal and
 * deprecation notices that 7.4 never printed — and a notice printed before
 * headers, or into a JSON body, is exactly what turns "sign in" into
 * "your session expired".
 *
 * Every page is rendered with error_reporting(E_ALL) and display_errors=1 so
 * anything PHP dislikes is visible instead of suppressed.
 *
 * Usage: node qa/php8.cjs [--verbose]
 */
const fs = require('fs');
const path = require('path');
const { boot } = require('./lib/boot.cjs');

const verbose = process.argv.includes('--verbose');

const PAGES = [
  'index.php',
  'order.php',
  'request_license.php',
  'api-docs.php',
  'success.php',
  'success_manual.php',
  'check-update.php',
  'unsubscribe.php',
  'user/index.php',
  'user/login.php',
  'admin/index.php',
  'admin/dashboard.php',
  'admin/projects.php',
  'admin/updates.php',
  'admin/licenses.php',
  'admin/settings.php',
  'admin/transactions.php',
  'admin/profile.php',
  'admin/guide.php',
  'admin/system-check.php',
  'admin/diagnostic.php',
  'admin/update-notifications.php',
  'admin/github-updates.php',
  'health.php',
  'admin/ping.php',
];

// PHP prints diagnostics at the start of a line (or inside <b> when html_errors
// is on). Anything else is page text that merely contains the word.
const SEVERITY = /^(<b>)?(PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards|Recoverable fatal)/;

(async () => {
  const { run, write } = await boot();
  await write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  const setup = `
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_pdo'] = new QAPdo();
    $GLOBALS['qa_counts'] = array('licenses' => 12, 'script_updates' => 4);
    $GLOBALS['qa_tiers'] = array(
      array('id'=>'1','tier_name'=>'DGV7.0-SAAS','tier_code'=>'SAAS','description'=>'Extended','project_key'=>'PRJ-SAAS','is_active'=>'1','github_repo'=>'philmorehost/PROJECTS','github_branch'=>'main','sort_order'=>'1','license_price'=>'5000','max_domains'=>'3'),
    );
    $GLOBALS['qa_updates'] = array(
      array('id'=>'5','tier_id'=>'1','version_number'=>'7.40','is_released'=>'1','release_date'=>'2026-10-20 10:00:00','zip_path'=>'/secure_updates/saas/7.40.zip','checksum'=>'abc','changelog'=>'notes'),
    );
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '0');
    require_once '/lm/includes/lm_config.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = 'admin';
    $_SESSION['lm_csrf'] = 'qa-token';
  `;

  let pages = 0;
  const findings = [];

  for (const page of PAGES) {
    const file = path.join(__dirname, '..', 'LM', page);
    if (!fs.existsSync(file)) {
      findings.push({ page, lines: ['(missing on disk)'] });
      continue;
    }

    const html = await run(setup + `
      $_SERVER['REQUEST_METHOD'] = 'GET';
      chdir('/lm/' . ${JSON.stringify(path.posix.dirname(page))});
      require '/lm/${page}';`, '/lm/' + page, { fresh: true, debug: true });

    pages++;
    const lines = String(html)
      .split('\n')
      .map(line => line.trim())
      .filter(line => SEVERITY.test(line))
      .slice(0, 12);

    if (lines.length) {
      findings.push({ page, lines });
    } else if (verbose) {
      console.log('  ok  ' + page);
    }
  }

  console.log('\nPHP ' + (await run("<?php echo PHP_VERSION;")) + ' — ' + pages + ' pages rendered with E_ALL\n');
  if (!findings.length) {
    console.log('No notices, warnings or deprecations on any page.');
    process.exit(0);
  }

  for (const f of findings) {
    console.log(f.page);
    f.lines.forEach(line => console.log('   ' + line.slice(0, 240)));
  }

  const total = findings.reduce((n, f) => n + f.lines.length, 0);
  console.log('\n' + total + ' diagnostic line(s) on ' + findings.length + ' page(s).');
  process.exit(1);
})().catch(e => { console.error('FAILED', e); process.exit(1); });
