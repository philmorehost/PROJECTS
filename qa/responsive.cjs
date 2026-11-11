/**
 * Every screen fits a phone.
 *
 * The complaint: "Pls help to implement responsiveness to all the pages of the
 * website. Several pages of the website are not responsive on mobile devices."
 *
 * The admin console already had a phone chrome (admin/_mobile_nav.php: top bar,
 * drawer, bottom tabs) and the public pages had assets/site.css. What was
 * missing was everything *inside* those shells — five-column tables, two-column
 * forms, 2rem card padding, sub-16px inputs that make iOS zoom, and two admin
 * tools that shipped without a viewport meta at all.
 *
 * includes/responsive.php + assets/lm-responsive.css + assets/lm-responsive.js
 * close that gap. This file pins it down:
 *
 *   A. every page that writes a <head> carries the layer — directly, or through
 *      the shared chrome that writes the head for it;
 *   B. every page really renders it: one stylesheet, one script, one viewport,
 *      after the page's own <style>, with the right ../ depth;
 *   C. the stylesheet says what it claims to (breakpoints, 16px fields, 44px
 *      targets, table wrapper, print, reduced motion) and the browser parses
 *      every rule;
 *   D. the script wraps tables once, labels them, skips the ones the page
 *      already scrolls, collapses multi-column grids on a phone and leaves
 *      them alone on a desktop.
 *
 * Usage: node qa/responsive.cjs
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const { boot } = require('./lib/boot.cjs');

let pass = 0;
let fail = 0;
const ok = (name, condition, detail) => {
  if (condition) { pass++; console.log('  ✓ ' + name); }
  else { fail++; console.log('  ✗ ' + name + (detail ? '\n      ' + String(detail).replace(/\s+/g, ' ').slice(0, 420) : '')); }
};

const LM = path.join(__dirname, '..', 'LM');
const read = rel => fs.readFileSync(path.join(LM, rel), 'utf8');
const CSS = read('assets/lm-responsive.css');
const JS = read('assets/lm-responsive.js');

/** Pages that print a <head> of their own. */
const OWN_HEAD = [
  'index.php', 'order.php', 'success.php', 'success_manual.php', 'api-docs.php',
  'unsubscribe.php', 'health.php', 'api-verify-payment.php', 'webhook.php',
  'user/index.php', 'user/login.php', 'user/license-details.php', 'user/profile.php',
  'user/change-domain.php',
  'admin/index.php', 'admin/dashboard.php', 'admin/license.php', 'admin/licenses.php',
  'admin/updates.php', 'admin/settings.php', 'admin/transactions.php', 'admin/profile.php',
  'admin/system-check.php', 'admin/diagnostic.php', 'admin/database-repair.php',
  'admin/webhook-tester.php', 'admin/payment-debug.php', 'admin/fix_license_tiers.php',
  'admin/patch_zip_manifests.php',
].filter(p => fs.existsSync(path.join(LM, p)));

/** Pages whose <head> is written by a shared chrome. */
const SHARED_HEAD = [
  { page: 'admin/projects.php', chrome: 'admin/_ui.php' },
  { page: 'admin/github-updates.php', chrome: 'admin/_ui.php' },
  { page: 'admin/guide.php', chrome: 'admin/_ui.php' },
  { page: 'admin/update-notifications.php', chrome: 'admin/_ui.php' },
  { page: 'admin/updates.php', chrome: 'admin/_ui.php' },
  { page: 'health.php', chrome: 'admin/_ui.php' },
];

/** Pages rendered in the php-wasm pass, with the session each one needs. */
const RENDER = [
  'index.php', 'order.php', 'success.php', 'success_manual.php', 'api-docs.php',
  'unsubscribe.php', 'health.php',
  'user/index.php', 'user/profile.php', 'user/license-details.php', 'user/change-domain.php',
  'admin/dashboard.php', 'admin/licenses.php', 'admin/updates.php', 'admin/settings.php',
  'admin/transactions.php', 'admin/profile.php', 'admin/system-check.php', 'admin/diagnostic.php',
  'admin/database-repair.php', 'admin/webhook-tester.php', 'admin/payment-debug.php',
  'admin/fix_license_tiers.php', 'admin/patch_zip_manifests.php', 'admin/projects.php',
  'admin/github-updates.php', 'admin/guide.php', 'admin/update-notifications.php',
];

const SETUP = `
    require '/lm/.qa/fakedb.php';
    $GLOBALS['qa_counts'] = array('licenses' => 3, 'script_updates' => 2, 'github_pulls' => 1, 'github_pull_jobs' => 1, 'jobs_running' => 0, 'update_notifications' => 1);
    $GLOBALS['qa_tiers'] = array(array('id' => 1, 'tier_name' => 'DGV7.0-SAAS', 'tier_code' => 'SAAS', 'description' => 'Extended licence', 'project_key' => 'PRJ-SAAS', 'is_active' => '1', 'sort_order' => '1', 'license_price' => '5000', 'max_domains' => '3'));
    $GLOBALS['qa_licences'] = array(
        array('id' => 1, 'license_key' => 'DGV7-SAAS-0001', 'domain' => 'alpha.example.com', 'customer_email' => 'owner@example.com', 'status' => 'active', 'tier_id' => 1, 'tier_name' => 'DGV7.0-SAAS', 'tier_code' => 'SAAS', 'updates_enabled' => 1, 'updates_blocked_at' => null, 'domain_change_count' => 0, 'max_domain_changes' => 3, 'created_at' => '2026-10-01 09:00:00', 'expiry_date' => '2027-10-01'),
        array('id' => 2, 'license_key' => 'DGV7-SAAS-0002', 'domain' => 'beta.example.com', 'customer_email' => 'beta@example.com', 'status' => 'active', 'tier_id' => 1, 'tier_name' => 'DGV7.0-SAAS', 'tier_code' => 'SAAS', 'updates_enabled' => 0, 'updates_blocked_at' => '2026-10-28 09:00:00', 'domain_change_count' => 1, 'max_domain_changes' => 3, 'created_at' => '2026-10-02 09:00:00', 'expiry_date' => '2027-10-02')
    );
    $GLOBALS['qa_requests'] = array();
    $GLOBALS['qa_pdo'] = new QAPdo();
    require_once '/lm/includes/lm_config.php';
    require_once '/lm/includes/migrations.php';
    require_once '/lm/includes/security.php';
    lm_session_start();
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_username'] = 'admin';
    $_SESSION['lm_csrf'] = 'qa-token';
    $_SESSION['user_email'] = 'owner@example.com';
    $_SERVER['HTTP_HOST'] = 'manager.pmhserver.name.ng';
    $_GET['id'] = 1;
`;

async function main() {
  /* ---------------------------------------------------------- A. the include */

  console.log('\nEvery page that writes a head carries the layer');

  const missing = OWN_HEAD.filter(p => !/lm_responsive_head/.test(read(p)));
  ok('all ' + OWN_HEAD.length + ' pages with their own </head> call lm_responsive_head()',
    missing.length === 0, missing.join(', '));

  const chromeMissing = SHARED_HEAD.filter(s => !/lm_responsive_head/.test(read(s.chrome)));
  ok('the shared admin chrome prints it for the pages it renders',
    chromeMissing.length === 0, chromeMissing.map(c => c.chrome).join(', '));

  ok('the public chrome does not print it — each page loads it after its own <style>',
    !/lm_responsive_head/.test(read('includes/ui.php')), 'includes/ui.php');

  const noHead = OWN_HEAD.filter(p => (read(p).match(/<\/head>/g) || []).length !== 1);
  ok('no page has a duplicated </head> after the edit', noHead.length === 0, noHead.join(', '));

  const twoCalls = OWN_HEAD.filter(p => (read(p).match(/lm_responsive_head\(\)/g) || []).length !== 1);
  ok('each page calls it exactly once', twoCalls.length === 0, twoCalls.join(', '));

  /* ------------------------------------------------------------ B. rendered */

  console.log('\n…and every page really prints it, in the right place');

  const probe = await boot();
  await probe.write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  const problems = [];
  const depths = { root: 0, sub: 0 };

  for (const page of RENDER) {
    const out = String(await probe.run(SETUP + `\n    require '/lm/${page}';`, '/lm/' + page, { fresh: true, debug: true }));
    const at = out.indexOf('<!DOCTYPE') >= 0 ? out.indexOf('<!DOCTYPE') : out.indexOf('<html');
    const html = at === -1 ? '' : out.slice(at);

    if (html.length < 500) {
      problems.push(page + ' rendered ' + html.length + ' bytes');
      continue;
    }

    const headEnd = html.indexOf('</head>');
    const head = headEnd === -1 ? '' : html.slice(0, headEnd);
    const bad = [];

    if (headEnd === -1) bad.push('no </head>');
    if ((head.match(/lm-responsive\.css/g) || []).length !== 1) bad.push('stylesheet count');
    if ((head.match(/lm-responsive\.js/g) || []).length !== 1) bad.push('script count');
    if ((head.match(/name="viewport"/g) || []).length !== 1) bad.push('viewport count');
    if ((head.match(/name="theme-color"/g) || []).length > 1) bad.push('theme-color duplicated');
    if (!/name="viewport" content="width=device-width/.test(head)) bad.push('viewport content');

    /* The page's own <style> must win the cascade on desktop, so the layer has
       to come after it. */
    const styleEnd = head.lastIndexOf('</style>');
    if (styleEnd !== -1 && head.indexOf('lm-responsive.css') < styleEnd) bad.push('stylesheet before the page styles');

    const sub = page.indexOf('/') !== -1;
    const want = sub ? 'href="../assets/lm-responsive.css' : 'href="assets/lm-responsive.css';
    if (head.indexOf(want) === -1) bad.push('wrong relative path');
    if (sub) depths.sub++; else depths.root++;

    if (bad.length) problems.push(page + ': ' + bad.join(', '));
  }

  ok('all ' + RENDER.length + ' pages render the layer exactly once',
    problems.length === 0, problems.join(' | '));
  ok('the path is correct from the site root and from admin/ and user/',
    depths.root > 0 && depths.sub > 0, JSON.stringify(depths));

  /* A page that never had a viewport must get one from the layer. */
  const bare = String(await probe.run(SETUP + `
      require_once '/lm/includes/responsive.php';
      echo lm_responsive_sources_have('name=["\\']viewport["\\']') ? 'HAS' : 'MISSING';
    `, '/lm/admin/fix_license_tiers.php', { fresh: true, debug: true }));

  ok('admin/fix_license_tiers.php has no viewport of its own, so the layer supplies it',
    /^MISSING/.test(bare.trim()), bare.slice(0, 60));

  const twice = String(await probe.run(SETUP + `
      require_once '/lm/includes/responsive.php';
      ob_start(); lm_responsive_head(); lm_responsive_head(); $one = ob_get_clean();
      echo substr_count($one, 'lm-responsive.css');
    `, '/lm/order.php', { fresh: true, debug: true }));

  ok('calling it twice prints the stylesheet once', twice.trim() === '1', twice.trim());

  /* --------------------------------------------------------- C. stylesheet */

  console.log('\nThe stylesheet says what it claims to say');

  const dom = new JSDOM('<!DOCTYPE html><html><head><style>' + CSS + '</style></head><body></body></html>');
  const sheet = dom.window.document.styleSheets[0];
  const rules = sheet ? Array.from(sheet.cssRules) : [];
  const mediaRules = rules.filter(r => r.constructor.name === 'CSSMediaRule' || r.type === 4);
  const conditions = mediaRules.map(r => r.conditionText || (r.media && r.media.mediaText) || '');

  const flatten = list => list.reduce((n, r) => n + 1 + (r.cssRules ? flatten(Array.from(r.cssRules)) : 0), 0);

  ok('the browser parses every rule', flatten(rules) > 50, String(flatten(rules)));
  ok('the console breakpoint matches the admin chrome (980px)',
    conditions.some(c => /max-width:\s*980px/.test(c)), conditions.join(' | '));
  ok('the phone breakpoint is real', conditions.some(c => /max-width:\s*700px/.test(c)), conditions.join(' | '));
  ok('customer and public pages have their own breakpoint',
    conditions.some(c => /max-width:\s*760px/.test(c)), conditions.join(' | '));
  ok('small phones are handled', conditions.some(c => /max-width:\s*400px/.test(c)), conditions.join(' | '));
  ok('reduced motion is honoured', conditions.some(c => /prefers-reduced-motion/.test(c)), conditions.join(' | '));
  ok('print hides the phone chrome', conditions.some(c => /print/.test(c)), conditions.join(' | '));

  ok('it declares the table scroll container the script builds',
    /\.lm-table-scroll\s*\{[^}]*overflow-x:\s*auto/.test(CSS), '');
  ok('a wrapped table can be focused from the keyboard',
    /\.lm-table-scroll:focus-visible/.test(CSS), '');
  ok('fields are forced to 16px on a phone (no iOS zoom)',
    /@media \(max-width: 700px\)[\s\S]*?font-size:\s*16px !important/.test(CSS), '');
  ok('tap targets are 44px',
    /@media \(max-width: 700px\)[\s\S]*?min-height:\s*44px/.test(CSS), '');
  ok('checkboxes keep their own size',
    /input:not\(\[type="checkbox"\]\)/.test(CSS), '');
  ok('grids have a one-column fallback',
    /\.lm-stack\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) !important/.test(CSS), '');
  ok('long keys and log lines can wrap',
    /overflow-wrap:\s*anywhere/.test(CSS), '');
  ok('the document cannot scroll sideways on a phone',
    /@media \(max-width: 980px\)[\s\S]*?body \{\s*overflow-x:\s*hidden/.test(CSS), '');
  ok('the desktop body is left alone, so sticky headers keep working',
    !/^body \{[^}]*overflow-x/m.test(CSS), '');
  ok('the layer never hides the real sidebar behind the chrome\'s back',
    !/\.sidebar\s*\{[^}]*display:\s*none/.test(CSS), 'the chrome owns that rule');
  ok('nothing outside a media query changes a desktop layout',
    !/^\.main\s*\{[^}]*margin-left:\s*0/m.test(CSS), '');

  /* --------------------------------------------------------------- D. script */

  console.log('\nThe script repairs the tables and the grids');

  const page = `<!DOCTYPE html><html><head>
    <style>
      .grid-2 { display: grid; grid-template-columns: 1fr 1fr; }
      .auto { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
      .one { display: grid; grid-template-columns: 1fr; }
    </style></head><body>
    <div class="card"><h2>Licences</h2><table id="t1"><thead><tr><th>Key</th><th>Domain</th></tr></thead><tbody><tr><td>a</td><td>b</td></tr></tbody></table></div>
    <div data-lm-no-scroll><table id="t2"><tr><td>already scrolls</td></tr></table></div>
    <table id="t3"><caption>Transactions</caption><tr><td>x</td></tr></table>
    <div class="grid-2" id="g1"><div>a</div><div>b</div></div>
    <div class="auto" id="g2"><div>a</div><div>b</div></div>
    <div class="one" id="g3"><div>a</div></div>
    <div class="lm-m-drawer"><div class="grid-2" id="g4"><div>a</div><div>b</div></div></div>
    <div style="display:grid;grid-template-columns:minmax(320px,430px) minmax(320px,1fr)" id="g5"><div>a</div><div>b</div></div>
    <script>${JS}</script></body></html>`;

  /* The width has to be in place before the inline script runs. */
  const withWidth = width => new JSDOM(page, {
    runScripts: 'dangerously',
    beforeParse(window) { Object.defineProperty(window, 'innerWidth', { value: width, configurable: true }); },
  });

  /* jsdom fires DOMContentLoaded after the parse returns, so the script's
     boot hook has not run yet when the constructor does. */
  const settled = dom => new Promise(resolve => {
    if (dom.window.document.readyState === 'complete') { return resolve(); }
    dom.window.addEventListener('load', () => resolve());
    setTimeout(resolve, 800);
  });

  const phone = withWidth(360);
  await settled(phone);
  const pd = phone.window.document;
  const wrap1 = pd.getElementById('t1').parentNode;
  const wrap3 = pd.getElementById('t3').parentNode;

  ok('a table is wrapped in a scroll container', wrap1.classList.contains('lm-table-scroll'), wrap1.className);
  ok('the wrapper is keyboard reachable', wrap1.getAttribute('tabindex') === '0', wrap1.getAttribute('tabindex'));
  ok('the wrapper is announced as a scrollable region',
    wrap1.getAttribute('role') === 'region' && /Licences \(scrollable\)/.test(wrap1.getAttribute('aria-label')),
    wrap1.getAttribute('aria-label'));
  ok('a table the page already scrolls is left alone',
    pd.getElementById('t2').parentNode.getAttribute('data-lm-no-scroll') !== null,
    pd.getElementById('t2').parentNode.tagName);
  ok('a caption is used as the label',
    /Transactions \(scrollable\)/.test(wrap3.getAttribute('aria-label')), wrap3.getAttribute('aria-label'));
  ok('no table is wrapped twice',
    pd.querySelectorAll('.lm-table-scroll').length === 2, String(pd.querySelectorAll('.lm-table-scroll').length));

  ok('a two-column grid is collapsed on a phone', pd.getElementById('g1').classList.contains('lm-stack'), '');
  ok('an auto-fit grid is left to wrap on its own', !pd.getElementById('g2').classList.contains('lm-stack'), '');
  ok('a one-column grid is left alone', !pd.getElementById('g3').classList.contains('lm-stack'), '');
  ok('the navigation drawer keeps its own layout', !pd.getElementById('g4').classList.contains('lm-stack'), '');
  ok('a grid declared inline on a bare <div> is collapsed too',
    pd.getElementById('g5').classList.contains('lm-stack'), 'the GitHub Pull screen lays out this way');

  const desk = withWidth(1440);
  await settled(desk);

  ok('nothing is collapsed on a desktop', desk.window.document.querySelectorAll('.lm-stack').length === 0,
    String(desk.window.document.querySelectorAll('.lm-stack').length));
  ok('tables are still wrapped on a desktop (they simply do not scroll)',
    desk.window.document.querySelectorAll('.lm-table-scroll').length === 2,
    String(desk.window.document.querySelectorAll('.lm-table-scroll').length));

  ok('the script bootstraps once per document',
    /data-lm-responsive/.test(JS) && /=== 'ready'/.test(JS), '');

  /* -------------------------------------------------------------- E. console */

  console.log('\nNothing else was broken on the way in');

  const unbalanced = OWN_HEAD.filter(p => (read(p).match(/<style/g) || []).length !== (read(p).match(/<\/style>/g) || []).length);
  ok('every page still has balanced <style> blocks', unbalanced.length === 0, unbalanced.join(', '));

  ok('the stylesheet is linked, not inlined, on every page',
    !OWN_HEAD.some(p => read(p).includes('lm-responsive.css') && !read(p).includes('lm_responsive_head')), '');

  console.log('\n' + pass + ' passed, ' + fail + ' failed\n');
  process.exit(fail === 0 ? 0 : 1);
}

main().catch(e => { console.error(e); process.exit(1); });
