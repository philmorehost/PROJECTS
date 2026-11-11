/**
 * Click the Delete button in a real DOM.
 *
 * The delete flow is three promises deep (plan -> modal -> confirm), so a
 * syntax check is not enough: a missing element, a listener attached to null
 * or a button that never re-enables all look like "the button does nothing".
 * This loads the rendered projects page in jsdom, stubs fetch, and drives it
 * the way an admin would.
 *
 * Usage: node qa/projects-ui.cjs
 */
const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');
const { boot } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;
const ok = (name, condition, detail) => {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 300) : '')); }
};

const FAKE_PDO_SETUP = `
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_counts'] = array('licenses' => 12, 'script_updates' => 4, 'github_pulls' => 5, 'github_pull_jobs' => 1, 'jobs_running' => 1, 'update_notifications' => 7);
    $GLOBALS['qa_tiers'] = array(
      array('id'=>'1','tier_name'=>'DGV7.0-SAAS','tier_code'=>'SAAS','description'=>'Extended licence','project_key'=>'PRJ-SAAS','is_active'=>'1','github_repo'=>'philmorehost/PROJECTS','github_branch'=>'main','sort_order'=>'1','license_price'=>'5000','max_domains'=>'3'),
      array('id'=>'2','tier_name'=>'DGV7.0-NON-SAAS','tier_code'=>'NON-SAAS','description'=>'Standard licence','project_key'=>'PRJ-NON-SAAS','is_active'=>'1','github_repo'=>'','github_branch'=>'','sort_order'=>'2','license_price'=>'','max_domains'=>''),
      array('id'=>'3','tier_name'=>'O' . chr(39) . 'Brien "Pro" <b>','tier_code'=>'QUOTED','description'=>'A name with quotes','project_key'=>'PRJ-QUOTED','is_active'=>'1','github_repo'=>'','github_branch'=>'','sort_order'=>'3','license_price'=>'','max_domains'=>'')
    );
    $GLOBALS['qa_updates'] = array(
      array('id'=>'5','tier_id'=>'1','version_number'=>'7.40','is_released'=>'1','release_date'=>'2026-10-20 10:00:00','zip_path'=>'/secure_updates/saas/7.40.zip','checksum'=>'abc','changelog'=>'notes'),
      array('id'=>'6','tier_id'=>'2','version_number'=>'7.40','is_released'=>'1','release_date'=>'2026-10-20 10:00:00','zip_path'=>'/secure_updates/non-saas/7.40.zip','checksum'=>'def','changelog'=>'notes')
    );
    $GLOBALS['qa_pdo'] = new QAPdo();
`;

const PLAN = {
  status: 'confirm',
  code: 'delete_needs_confirmation',
  server_version: '9.1.0',
  project: { id: 1, name: 'DGV7.0-SAAS', code: 'SAAS' },
  impact: {
    licences: 12, updates: 4, package_files: 3, package_bytes: 2048,
    package_size: '2 KB', pulls: 5, jobs: 1, jobs_running: 1, notifications: 7,
  },
  targets: [{ id: 2, name: 'DGV7.0-NON-SAAS', code: 'NON-SAAS' }],
  message: 'This project has 12 licences. A GitHub pull is running for it and will be cancelled.',
};

const DONE = {
  status: 'success',
  server_version: '9.1.0',
  message: 'Project DGV7.0-SAAS deleted. 12 licence(s) moved to DGV7.0-NON-SAAS.',
  deleted: { licences_moved: 12, licences_detached: 0, licences_deleted: 0, updates: 4, files: 3, notifications: 7, jobs: 1 },
};

(async () => {
  const { run, write } = await boot();
  await write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));
  const html = await run(`
    ${FAKE_PDO_SETUP}
    require_once '/lm/includes/security.php';
    require_once '/lm/admin/_ui.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    require '/lm/admin/projects.php';`, '/lm/admin/projects.php');

  fs.writeFileSync('/tmp/projects-ui.html', html);

  const calls = [];
  const virtualConsole = new VirtualConsole();
  const errors = [];
  virtualConsole.on('jsdomError', e => {
    // jsdom cannot navigate; reload() is expected to land here.
    if (!/Not implemented: navigation/.test(String(e.message))) errors.push(String(e.message));
  });
  virtualConsole.on('error', msg => errors.push(String(msg)));

  const dom = new JSDOM(html, {
    runScripts: 'dangerously',
    pretendToBeVisual: true,
    url: 'https://manager.pmhserver.name.ng/admin/projects.php',
    virtualConsole,
    beforeParse(window) {
      window.TextEncoder = TextEncoder;
      window.fetch = (url, options) => {
        const form = options && options.body;
        const entries = [];
        if (form && typeof form.forEach === 'function') {
          form.forEach((value, key) => entries.push([key, String(value)]));
        }
        calls.push({ url: String(url), entries: entries, body: form });
        const payload = calls.length === 1 ? PLAN : DONE;
        return Promise.resolve({
          ok: true,
          status: 200,
          text: () => Promise.resolve(JSON.stringify(payload)),
        });
      };
      window.location.reload = () => {};
    },
  });

  // Let the page's own scripts run.
  await new Promise(resolve => setTimeout(resolve, 120));

  const { window } = dom;
  const document = window.document;
  const fatal = errors.filter(e => /is not defined|Cannot read|null|TypeError|ReferenceError/i.test(e));

  ok('the page loads without a JavaScript error', fatal.length === 0, fatal.join('; '));

  const buttons = [...document.querySelectorAll('button')];
  const deleteButton = buttons.find(b => b.textContent.trim() === 'Delete');
  ok('every project card has a Delete button', !!deleteButton, buttons.map(b => b.textContent.trim()).join(' | '));

  const modal = document.getElementById('deleteModal');
  ok('the confirmation modal exists in the page', !!modal);
  ok('the modal is closed before the click', modal && !modal.classList.contains('open'));

  const confirmButton = document.getElementById('deleteConfirmBtn');
  ok('the delete button starts disabled', confirmButton && confirmButton.disabled);

  if (deleteButton) deleteButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await new Promise(resolve => setTimeout(resolve, 120));

  ok('clicking Delete asks the server for a plan first', calls.length === 1, JSON.stringify(calls));
  ok('the modal opens', modal && modal.classList.contains('open'));
  ok('the plan is shown to the operator', /12/.test(document.getElementById('deleteImpact').textContent),
    document.getElementById('deleteImpact') && document.getElementById('deleteImpact').textContent);
  ok('a running pull is called out',
    /cancelled/.test(document.getElementById('deleteImpact').textContent || ''),
    document.getElementById('deleteImpact') && document.getElementById('deleteImpact').textContent);
  ok('the other project is offered as a destination',
    [...document.querySelectorAll('#deleteMoveTo option')].some(o => /NON-SAAS/.test(o.textContent)),
    document.getElementById('deleteMoveTo') && document.getElementById('deleteMoveTo').innerHTML);

  const understand = document.getElementById('deleteUnderstand');
  ok('the confirmation box is not ticked', understand && !understand.checked);
  ok('and the delete button is still disabled', confirmButton && confirmButton.disabled);

  understand.checked = true;
  understand.dispatchEvent(new window.Event('change', { bubbles: true }));
  ok('ticking the box enables the delete button', !confirmButton.disabled);

  document.getElementById('deleteMoveTo').value = '2';
  confirmButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await new Promise(resolve => setTimeout(resolve, 120));

  ok('confirming sends a second, confirmed request', calls.length === 2, String(calls.length));
  const fields = calls[1] ? Object.fromEntries(calls[1].entries) : {};
  ok('the request carries confirm=1', fields.confirm === '1', JSON.stringify(fields));
  ok('the request carries the licence decision', fields.licence_mode === 'detach', JSON.stringify(fields));
  ok('the request carries the destination project', fields.move_to === '2', JSON.stringify(fields));
  ok('the success message reaches the operator', /deleted/.test(document.getElementById('toast').textContent),
    document.getElementById('toast') && document.getElementById('toast').textContent);

  // The Edit and Integration buttons carry the same JSON-in-an-attribute, so
  // they get clicked too — a broken attribute there would be silent as well.
  const editButton = buttons.find(b => b.textContent.trim() === 'Edit');
  if (editButton) editButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await new Promise(resolve => setTimeout(resolve, 60));
  ok('Edit still opens with the project loaded',
    document.getElementById('projectModal').classList.contains('open')
      && document.getElementById('projectName').value === 'DGV7.0-SAAS',
    document.getElementById('projectName') && document.getElementById('projectName').value);

  const guideButton = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Integration');
  if (guideButton) guideButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  await new Promise(resolve => setTimeout(resolve, 60));
  ok('Integration still opens with the endpoint filled',
    document.getElementById('guideModal').classList.contains('open')
      && /check-update\.php/.test(document.getElementById('guideEndpoint').textContent),
    document.getElementById('guideEndpoint') && document.getElementById('guideEndpoint').textContent);

  // A project name with quotes is the case that broke the button in the first
  // place: json_encode() inside a double-quoted attribute ends the attribute.
  const quotedButton = [...document.querySelectorAll('button')].filter(b => b.textContent.trim() === 'Delete')[2];
  ok('a project name with quotes still gets a working Delete button', !!quotedButton);
  if (quotedButton) {
    const before = calls.length;
    quotedButton.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    await new Promise(resolve => setTimeout(resolve, 120));
    ok('that button reaches the server', calls.length === before + 1, String(calls.length));
    ok('the modal names the project exactly',
      /O'Brien "Pro"/.test(document.getElementById('deleteTitle').textContent),
      document.getElementById('deleteTitle') && document.getElementById('deleteTitle').textContent);
  }

  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('FAILED', e); process.exit(1); });
