/**
 * One licence, one switch.
 *
 * The complaint: "Please allow individual license Updates to be controllable.
 * I see that the function controls all. So, I need a single license to be
 * updatable."
 *
 * 9.6.0 did keep the state per licence, but the page led with a project-wide
 * "Hold all / Allow all" panel and hid the per-row action in an Actions column
 * — so the only obvious control was the one that changed everything. This file
 * pins the corrected page down:
 *
 *   A. every row carries its own switch, and that switch posts that row's id;
 *   B. the project-wide action is a closed disclosure, worded as such, and asks
 *     for confirmation before it runs;
 *   C. a real POST through the page updates exactly one row;
 *   D. filtering to "On hold" leaves the held licence alone on screen.
 *
 * Usage: node qa/licence-updates.cjs
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const { boot } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;
const ok = (name, condition, detail) => {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 380) : '')); }
};

const LICENCES = [
  {
    id: '1', license_key: 'DGV7-SAAS-0001', domain: 'alpha.example.com',
    customer_email: 'alpha@example.com', status: 'active', license_type: 'extended',
    tier_id: '1', updates_enabled: '1', updates_blocked_at: null, updates_block_note: null,
    created_at: '2026-10-01 09:00:00',
  },
  {
    id: '2', license_key: 'DGV7-SAAS-0002', domain: 'beta.example.com',
    customer_email: 'beta@example.com', status: 'active', license_type: 'standard',
    tier_id: '1', updates_enabled: '0', updates_blocked_at: '2026-10-28 09:00:00',
    updates_block_note: 'Balance NGN 15,000 for v7.41', created_at: '2026-10-02 09:00:00',
  },
  {
    id: '3', license_key: 'DGV7-SAAS-0003', domain: 'gamma.example.com',
    customer_email: 'gamma@example.com', status: 'active', license_type: 'standard',
    tier_id: '1', updates_enabled: '1', updates_blocked_at: null, updates_block_note: null,
    created_at: '2026-10-03 09:00:00',
  },
];

const TIERS = [
  {
    id: '1', tier_name: 'DGV7.0-SAAS', tier_code: 'SAAS', description: 'Extended licence',
    project_key: 'PRJ-SAAS', is_active: '1', sort_order: '1', license_price: '5000', max_domains: '3',
  },
];

// Fixtures go into a PHP file, not JSON: object keys are not valid PHP.
const phpValue = v => {
  if (Array.isArray(v)) return 'array(' + v.map(phpValue).join(', ') + ')';
  if (v === null || v === undefined) return 'null';
  if (typeof v === 'object') {
    return 'array(' + Object.entries(v).map(([k, val]) => JSON.stringify(k) + ' => ' + phpValue(val)).join(', ') + ')';
  }
  return JSON.stringify(v);
};

const SETUP = `
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_counts'] = array('licenses' => 3, 'script_updates' => 2, 'github_pulls' => 0, 'github_pull_jobs' => 0, 'jobs_running' => 0, 'update_notifications' => 0);
    $GLOBALS['qa_tiers'] = ${phpValue(TIERS)};
    $GLOBALS['qa_licences'] = ${phpValue(LICENCES)};
    $GLOBALS['qa_requests'] = array();
    $GLOBALS['qa_pdo'] = new QAPdo();
    // db.php is a stub here, so load what the page expects from it.
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/migrations.php';
    require_once '/lm/includes/security.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['lm_csrf'] = 'qa-token';
`;

const read = rel => fs.readFileSync(path.join(__dirname, '..', 'LM', rel), 'utf8');

async function main() {
  const probe = await boot();
  await probe.write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  /* ------------------------------------------------------------- A. page */

  console.log('Every row has its own switch');

  const html = String(await probe.run(`
    ${SETUP}
    require '/lm/admin/licenses.php';
  `, '/lm/admin/licenses.php', { fresh: true, debug: true }));

  ok('the page rendered', html.length > 2000, 'only ' + html.length + ' bytes');

  const dom = new JSDOM(html);
  const doc = dom.window.document;

  const rows = Array.from(doc.querySelectorAll('#licences tbody tr'));
  ok('all three licences are listed', rows.length === 3, String(rows.length));

  const switches = Array.from(doc.querySelectorAll('.updates-switch'));
  ok('each licence has a switch of its own', switches.length === 3, String(switches.length));

  const formOf = button => button.closest('form');
  const idOf = button => {
    const input = formOf(button).querySelector('input[name="id"]');
    return input ? input.value : '';
  };

  ok('the switches post their own row id',
    switches.map(idOf).join(',') === '1,2,3', switches.map(idOf).join(','));

  const currentOf = button => {
    const input = formOf(button).querySelector('input[name="current_updates"]');
    return input ? input.value : '';
  };
  ok('each switch carries its own current state',
    switches.map(currentOf).join(',') === '1,0,1', switches.map(currentOf).join(','));

  ok('the switch says which way it will flip',
    Array.from(doc.querySelectorAll('.switch-label')).map(n => n.textContent.trim()).join(',') === 'Allowed,On hold,Allowed',
    Array.from(doc.querySelectorAll('.switch-label')).map(n => n.textContent.trim()).join(','));

  const held = switches[1];
  ok('the held licence is visibly off', held.classList.contains('is-off'), held.className);
  ok('the allowed ones are visibly on',
    switches[0].classList.contains('is-on') && switches[2].classList.contains('is-on'),
    switches[0].className + ' | ' + switches[2].className);

  ok('the hint names the licence, not the project',
    /Only this licence/.test(held.getAttribute('title') || ''), held.getAttribute('title'));
  ok('its accessible name names the licence key',
    /DGV7-SAAS-0002/.test(held.getAttribute('aria-label') || ''), held.getAttribute('aria-label'));
  ok('the row explains what the click does',
    /click to allow this one/.test(rows[1].textContent), rows[1].textContent.replace(/\s+/g, ' ').slice(0, 200));

  ok('the note is shown on the row that is on hold',
    /Balance NGN 15,000 for v7.41/.test(rows[1].textContent), '');
  ok('the note is not shown on the other rows',
    !/Balance NGN/.test(rows[0].textContent) && !/Balance NGN/.test(rows[2].textContent), '');

  ok('the old Actions link no longer duplicates the control',
    !/Hold updates/.test(rows[0].textContent) && !/Allow updates/.test(rows[1].textContent),
    rows[0].textContent.replace(/\s+/g, ' ').slice(0, 200));

  /* ----------------------------------------------------------- B. bulk */

  console.log('\nThe project-wide action is no longer the obvious one');

  const details = doc.querySelector('.bulk-box');
  ok('it lives in a closed disclosure', !!details && details.tagName.toLowerCase() === 'details', details ? details.tagName : 'missing');
  ok('it is closed by default', details && !details.hasAttribute('open'), '');
  ok('its summary says it is project-wide',
    details && /every licence in one project/i.test(details.querySelector('summary').textContent),
    details ? details.querySelector('summary').textContent : '');
  ok('it sits after the table, not above it',
    !!details && !!details.closest('#licences') &&
    rows[rows.length - 1].compareDocumentPosition(details) & dom.window.Node.DOCUMENT_POSITION_FOLLOWING,
    '');
  ok('running it asks for confirmation first',
    /confirm\(/.test(details.querySelector('form').getAttribute('onsubmit') || ''),
    details.querySelector('form').getAttribute('onsubmit'));
  ok('the confirmation says EVERY licence',
    /EVERY licence/.test(details.querySelector('form').getAttribute('onsubmit') || ''), '');
  ok('the buttons say "every licence" too',
    /Hold every licence/.test(details.textContent) && /Allow every licence/.test(details.textContent), '');

  /* --------------------------------------------------------- C. filtering */

  console.log('\nFinding one licence among many');

  const chips = Array.from(doc.querySelectorAll('.chip'));
  ok('there are three filters', chips.length === 3, String(chips.length));
  ok('they count the states', /Allowed\s*2/.test(chips[1].textContent) && /On hold\s*1/.test(chips[2].textContent),
    chips.map(c => c.textContent.trim()).join(' | '));

  const heldHtml = String(await probe.run(`
    ${SETUP}
    $_GET['updates'] = 'hold';
    require '/lm/admin/licenses.php';
  `, '/lm/admin/licenses.php', { fresh: true, debug: true }));

  const heldDoc = new JSDOM(heldHtml).window.document;
  const heldRows = Array.from(heldDoc.querySelectorAll('#licences tbody tr'));
  ok('"On hold" shows only the held licence', heldRows.length === 1, String(heldRows.length));
  ok('and it is the right one', /DGV7-SAAS-0002/.test(heldRows[0].textContent), heldRows[0].textContent.replace(/\s+/g, ' ').slice(0, 160));
  ok('the filter chip is marked active', /chip-active/.test(heldDoc.querySelector('.chip-active').outerHTML), '');

  /* ------------------------------------------------------- D. real POST */

  console.log('\nSaving changes exactly one licence');

  const post = async (fields, label) => {
    const out = await probe.run(`
      ${SETUP}
      @unlink('/lm/.qa/sql.log');
      $GLOBALS['qa_sql_file'] = '/lm/.qa/sql.log';
      $_SERVER['REQUEST_METHOD'] = 'POST';
      $_POST = ${phpValue(Object.assign({ csrf_token: 'qa-token' }, fields))};
      require '/lm/admin/licenses.php';
    `, '/lm/admin/licenses.php', { fresh: true, debug: true });
    let log = '';
    try {
      log = await probe.php().readFile('/lm/.qa/sql.log', { encoding: 'utf8' });
    } catch (e) {
      log = '';
    }
    return { out: String(out || ''), log: String(log || '') };
  };

  // current_updates is the state BEFORE the click: 1 = allowed, so this click holds it.
  const holdOne = await post({ toggle_updates: '1', id: '2', current_updates: '1' }, 'hold');
  const holdSql = holdOne.log;
  ok('the save wrote to the database', /update licenses/i.test(holdSql), holdSql.slice(0, 300));
  ok('it held the updates switch',
    /set updates_enabled = 0/i.test(holdSql), holdSql.slice(0, 300));
  ok('it stamped the hold',
    /updates_blocked_at = now\(\)/i.test(holdSql), holdSql.slice(0, 300));
  ok('it targeted one row by id', /where id = \?/i.test(holdSql), holdSql.slice(0, 300));
  ok('and the row was licence 2', /-- \["2"\]/.test(holdSql), holdSql.slice(-220));
  ok('no UPDATE touched a whole project',
    !/where tier_id/i.test(holdSql), holdSql.slice(0, 300));

  const allowOne = await post({ toggle_updates: '1', id: '2', current_updates: '0' }, 'allow');
  ok('allowing writes the opposite value',
    /set updates_enabled = 1/i.test(allowOne.log), allowOne.log.slice(0, 260));
  ok('allowing clears the hold date',
    /updates_blocked_at = null/i.test(allowOne.log), allowOne.log.slice(0, 260));
  ok('allowing also targets that one row',
    /-- \["2"\]/.test(allowOne.log) && !/where tier_id/i.test(allowOne.log), allowOne.log.slice(-200));

  const bulk = await post({ bulk_updates: '1', tier_id: '1', bulk_mode: 'hold' }, 'bulk');
  ok('the bulk action is the only thing that writes by project',
    /where tier_id = \?/i.test(bulk.log), bulk.log.slice(0, 260));
  ok('and it is clearly a different statement',
    /set updates_enabled = 0, updates_blocked_at = now\(\) where tier_id/i.test(bulk.log.replace(/\s+/g, ' ')),
    bulk.log.slice(0, 260));

  /* --------------------------------------------------------- E. no column */

  console.log('\nA partial upload says so instead of breaking');

  const missing = String(await probe.run(`
    ${SETUP}
    $GLOBALS['qa_columns'] = array('licenses.updates_blocked_at');
    require '/lm/admin/licenses.php';
  `, '/lm/admin/licenses.php', { fresh: true, debug: true }));

  const missingDoc = new JSDOM(missing).window.document;
  ok('the page still renders', missingDoc.querySelectorAll('#licences tbody tr').length === 3, '');
  ok('it explains what is missing',
    /includes\/migrations\.php/.test(missingDoc.body.textContent), '');
  ok('and shows a read-only badge rather than a dead switch',
    missingDoc.querySelectorAll('.updates-switch').length === 0
    && missingDoc.querySelectorAll('.status-onhold').length === 1,
    String(missingDoc.querySelectorAll('.updates-switch').length));

  /* --------------------------------------------------------- F. contracts */

  console.log('\nThe page and the handler agree');

  const page = read('admin/licenses.php');
  ok('the row switch is inside its own form', /<form method="POST" class="switch-form">/.test(page), '');
  ok('the handler reads the id from the form', /WHERE id = \?\"\)->execute\(\[\$_POST\['id'\]\]\)/.test(page), '');
  ok('the project-wide path is a separate action',
    /isset\(\$_POST\['bulk_updates'\]\)/.test(page) && /isset\(\$_POST\['toggle_updates'\]\)/.test(page), '');
  ok('the bulk action refuses to run without a project',
    /if \(\$tierId > 0\) \{/.test(page), '');
  ok('the licence gate still reads the per-licence column',
    /lm_license_updates_allowed/.test(read('includes/updates.php')), '');

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail ? 1 : 0);
}

main().catch(err => {
  console.error(err);
  process.exit(1);
});
