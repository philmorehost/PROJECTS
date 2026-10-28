/**
 * The database driver check in db.php.
 *
 * What the log said:
 *
 *   PHP Fatal error: Uncaught Error: Class 'PDO' not found in .../db.php:50
 *
 * That is not a panel bug — the PHP version the account was switched to has no
 * PDO — but it is a panel *failure*: the whole console returned a 500 and the
 * only explanation was a log line nobody sees. Worse, "Class not found" is an
 * Error, not a PDOException, so the existing `catch (PDOException)` could not
 * catch it and the request died before a single page rendered.
 *
 * These checks pin down the guard that now runs first: it must recognise both
 * faults (no PDO at all, and PDO without its mysql driver), explain each one in
 * its own words with the fix, answer JSON to an AJAX caller, and never print a
 * database credential.
 *
 * Usage: node qa/db-driver.cjs
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

/**
 * Load db.php's function definitions without running the connection at the
 * bottom: the connection either ends the request with exit() (which php-wasm
 * cannot report) or, on a host with a driver, tries to reach MySQL.
 */
const HEAD = `
    $src = file_get_contents('/lm/.qa/db-source.txt');
    $cut = strpos($src, '$lmPdoProblem = lm_pdo_problem();');
    if ($cut === false) { echo json_encode(array('error' => 'guard marker missing')); return; }
    $head = str_replace('__DIR__', "'/lm'", substr($src, 0, $cut));
    eval(substr($head, 5));
`;

const call = (expr, extra = '') => `
    error_reporting(E_ALL);
    ${HEAD}
    ${extra}
    echo json_encode(${expr});
`;

(async () => {
  const { run, write } = await boot();
  // The harness replaces /lm/db.php with a stub, so the real file is planted
  // under .qa/ for the probe to read.
  await write('/lm/.qa/db-source.txt', fs.readFileSync(path.join(LM, 'db.php')));
  const json = raw => {
    const trimmed = String(raw).trim();
    // A JSON string payload starts with a quote, so try the whole output first;
    // only fall back to slicing when something else printed around it.
    try { return JSON.parse(trimmed); } catch (e) { /* keep looking */ }
    const m = trimmed.match(/[{[][\s\S]*[}\]]/);
    if (!m) return null;
    try { return JSON.parse(m[0]); } catch (e) { return null; }
  };

  console.log('\nThe two faults are told apart');
  ok('no PDO class at all → missing',
    json(await run(call(`lm_pdo_problem(false, array())`), '/lm/admin/index.php', { fresh: true })) === 'missing');
  ok('PDO with only pgsql → no_mysql',
    json(await run(call(`lm_pdo_problem(true, array('pgsql'))`), '/lm/admin/index.php', { fresh: true })) === 'no_mysql');
  ok('PDO with mysql → no problem',
    json(await run(call(`lm_pdo_problem(true, array('pgsql', 'mysql'))`), '/lm/admin/index.php', { fresh: true })) === '');
  ok('PDO with an empty driver list → no_mysql',
    json(await run(call(`lm_pdo_problem(true, array())`), '/lm/admin/index.php', { fresh: true })) === 'no_mysql');

  const here = json(await run(call(`array('problem' => lm_pdo_problem(), 'drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : array())`),
    '/lm/admin/index.php', { fresh: true }));
  ok('this runtime (pgsql only) is reported as no_mysql', here && here.problem === 'no_mysql', JSON.stringify(here));

  console.log('\nThe page explains the fix, and only the fix');
  const missingPage = json(await run(call(`lm_db_unavailable_html('missing')`), '/lm/admin/index.php', { fresh: true }));
  const missingText = String(missingPage)
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ');

  ok('it names PDO as the missing piece', /does not have PDO/i.test(missingText), missingText.slice(0, 200));
  ok('it says where to enable it', /MultiPHP Manager/i.test(missingText), missingText.slice(0, 400));
  ok('it names the extension to switch on', /pdo_mysql/.test(missingText), missingText.slice(0, 400));
  ok('it offers PHP 7.4 as the one-click way back', /switch the domain back to PHP 7\.4/i.test(missingText), missingText.slice(0, 500));
  ok('it says an upload cannot fix it', /uploading files will not help/i.test(missingText), missingText.slice(0, 500));
  ok('it reports the PHP version it is running on', /PHP \d+\.\d+/.test(missingText), missingText.slice(0, 300));
  ok('it never prints a database name, user or password',
    !/pmhmanager_license|DB_PASS|DB_USER|password/i.test(missingText), missingText.slice(0, 400));
  ok('it is a complete page, not a fragment', /^<!DOCTYPE html>/i.test(String(missingPage).trim()), String(missingPage).slice(0, 40));
  ok('it links to health.php', /href="health\.php"/.test(String(missingPage)));

  const noMysqlPage = String(json(await run(call(`lm_db_unavailable_html('no_mysql')`), '/lm/admin/index.php', { fresh: true })));
  const noMysqlText = noMysqlPage.replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
  ok('the driver-only case says the driver, not the extension',
    /pdo_mysql/i.test(noMysqlText) && /driver/i.test(noMysqlText), noMysqlText.slice(0, 300));
  ok('it lists the drivers that are available', /driver\(s\):/.test(noMysqlText), noMysqlText.slice(0, 300));

  const failedPage = String(json(await run(call(`lm_db_unavailable_html('failed', 'SQLSTATE[HY000] [1045] Access denied')`), '/lm/admin/index.php', { fresh: true })));
  const failedText = failedPage.replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
  ok('a credential failure is not blamed on extensions', /not an extension problem/i.test(failedText), failedText.slice(0, 300));
  ok('the server line is shown, escaped', /Access denied/.test(failedText) && /<pre>/.test(failedPage), failedPage.slice(-400));
  ok('it does not offer the extension steps for that case', !/MultiPHP Manager → Extensions/.test(failedText), failedText.slice(0, 400));

  console.log('\nAn AJAX caller gets JSON, not HTML');
  const message = json(await run(call(`lm_db_unavailable_message('missing')`), '/lm/admin/index.php', { fresh: true }));
  ok('the payload has a stable code', message && message.code === 'database_unavailable', JSON.stringify(message));
  ok('the sentence names PDO and says nothing was lost',
    message && /PDO/i.test(message.message) && /nothing you did was lost/i.test(message.message), JSON.stringify(message));
  ok('the payload survives json_encode', message && message.status === 'error', JSON.stringify(message));

  const expects = json(await run(call(`
    array(
      'ajax_header' => (function () { $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['SCRIPT_NAME'] = '/LM/admin/index.php'; return lm_request_expects_json(); })(),
      'accept_json' => (function () { $s = $_SERVER; $_SERVER = array();
        $_SERVER['HTTP_ACCEPT'] = 'application/json'; return lm_request_expects_json(); })(),
      'ajax_script' => (function () { $s = $_SERVER; $_SERVER = array();
        $_SERVER['SCRIPT_NAME'] = '/LM/admin/ajax_projects.php'; return lm_request_expects_json(); })(),
      'plain_page' => (function () { $s = $_SERVER; $_SERVER = array();
        $_SERVER['SCRIPT_NAME'] = '/LM/admin/settings.php'; return lm_request_expects_json(); })(),
    )
  `), '/lm/admin/index.php', { fresh: true }));
  ok('an XHR gets JSON', expects && expects.ajax_header === true, JSON.stringify(expects));
  ok('Accept: application/json gets JSON', expects && expects.accept_json === true, JSON.stringify(expects));
  ok('admin/ajax_*.php gets JSON', expects && expects.ajax_script === true, JSON.stringify(expects));
  ok('a normal page gets the HTML explanation', expects && expects.plain_page === false, JSON.stringify(expects));

  console.log('\nThe guard runs before the line that used to fatal');
  const db = fs.readFileSync(path.join(LM, 'db.php'), 'utf8');
  const guardAt = db.indexOf('$lmPdoProblem = lm_pdo_problem();');
  const connectAt = db.indexOf('$pdo = new PDO(');
  ok('db.php is parsed by the guard before it connects', guardAt > 0 && connectAt > guardAt, `${guardAt} vs ${connectAt}`);
  ok('the guard ends the request through one function', db.includes("if ($lmPdoProblem !== '') {") && db.includes('lm_db_fail('));
  ok('the connection failure is caught as a Throwable too', /catch \(Throwable \$e\)/.test(db));
  ok('"could not find driver" is treated as the driver fault', /could not find driver/.test(db));

  let otherConnects = [];
  const walk = dir => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      if (e.name === 'storage' || e.name === 'docs' || e.name === '.git') continue;
      const full = path.join(dir, e.name);
      if (e.isDirectory()) walk(full);
      else if (e.name.endsWith('.php') && full !== path.join(LM, 'db.php')) {
        const body = fs.readFileSync(full, 'utf8');
        if (/new PDO\s*\(/.test(body)) otherConnects.push(path.relative(LM, full));
      }
    }
  };
  walk(LM);
  ok('nothing else opens a PDO connection behind its back', otherConnects.length === 0, otherConnects.join(', '));

  console.log('\nhealth.php answers when the panel cannot');
  const health = String(await run("require '/lm/health.php';", '/lm/health.php', { fresh: true }));
  const healthText = health.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
  ok('it has a PDO row', /PDO \(database access\)/.test(healthText), healthText.slice(0, 200));
  ok('it has a pdo_mysql row', /PDO MySQL driver/.test(healthText), healthText.slice(0, 200));
  ok('it names the drivers it does have', /available: \w+/.test(healthText), healthText.slice(0, 300));
  ok('it renders without a PHP diagnostic', !/(Fatal error|Parse error|Warning:|Deprecated:)/.test(health), health.slice(0, 200));

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('FAILED', e); process.exit(1); });
