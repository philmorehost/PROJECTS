/**
 * Render every screen of the panel to a standalone HTML file.
 *
 * The preview iframe has no network, so assets/lm-responsive.css and
 * assets/lm-responsive.js are inlined and the Google Fonts import is dropped.
 * Each file is a real render of the current tree — not a mock-up — so it can be
 * opened at any width and inspected with the browser's own device toolbar.
 *
 * Usage: node qa/preview.cjs <outDir> [page ...]
 */
const fs = require('fs');
const path = require('path');
const { boot } = require('./lib/boot.cjs');

const LM = path.join(__dirname, '..', 'LM');
const outDir = process.argv[2] || path.join(__dirname, '..', 'previews');
const only = process.argv.slice(3);

const PAGES = [
  'index.php', 'order.php', 'success.php', 'success_manual.php', 'api-docs.php',
  'unsubscribe.php', 'health.php',
  'user/index.php', 'user/login.php', 'user/license-details.php', 'user/profile.php',
  'user/change-domain.php',
  'admin/index.php', 'admin/dashboard.php', 'admin/licenses.php', 'admin/updates.php',
  'admin/settings.php', 'admin/transactions.php', 'admin/profile.php', 'admin/system-check.php',
  'admin/diagnostic.php', 'admin/database-repair.php', 'admin/webhook-tester.php',
  'admin/payment-debug.php', 'admin/fix_license_tiers.php', 'admin/patch_zip_manifests.php',
  'admin/projects.php', 'admin/github-updates.php', 'admin/guide.php',
  'admin/update-notifications.php',
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

(async () => {
  const css = fs.readFileSync(path.join(LM, 'assets', 'lm-responsive.css'), 'utf8');
  const js = fs.readFileSync(path.join(LM, 'assets', 'lm-responsive.js'), 'utf8');

  const probe = await boot();
  await probe.write('/lm/.qa/fakedb.php', fs.readFileSync(path.join(__dirname, 'lib', 'fakedb.php')));

  const list = only.length ? only : PAGES;
  const index = [];

  for (const page of list) {
    /* The two sign-in screens bounce a visitor who is already signed in. */
    const setup = page === 'user/login.php' || page === 'admin/index.php'
      ? SETUP.replace("$_SESSION['admin_logged_in'] = true;", '')
               .replace("$_SESSION['user_email'] = 'owner@example.com';", '')
      : SETUP;

    const out = String(await probe.run(setup + `\n    require '/lm/${page}';`, '/lm/' + page, { fresh: true, debug: true }));
    const at = out.indexOf('<!DOCTYPE') >= 0 ? out.indexOf('<!DOCTYPE') : out.indexOf('<html');
    let html = at === -1 ? '' : out.slice(at);

    if (!html) {
      console.log(page.padEnd(34), 'no output (guard or redirect)');
      continue;
    }

    html = html
      .replace(/<link[^>]*lm-responsive\.css[^>]*>/gi, '<style>\n' + css + '\n</style>')
      .replace(/<script[^>]*lm-responsive\.js[^>]*><\/script>/gi, '<script>\n' + js + '\n</script>')
      .replace(/@import url\('https:\/\/fonts\.googleapis\.com[^)]*\);/g, '')
      .replace(/<link[^>]*fonts\.googleapis\.com[^>]*>/gi, '');

    const folder = page.replace(/\//g, '__').replace(/\.php$/, '');
    const dir = path.join(outDir, folder);
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, 'index.html'), html);

    index.push({ page, folder, bytes: html.length });
    console.log(page.padEnd(34), String(html.length).padStart(7) + ' bytes');
  }

  fs.writeFileSync(path.join(outDir, 'index.json'), JSON.stringify(index, null, 2));

  /* The hub: every render in a frame whose width can be switched between a
     phone and a desktop, so the layout can be judged without a devtools
     device toolbar. */
  const items = index.map(i => '        <button type="button" data-src="' + i.folder + '/index.html">' +
    i.page.replace(/^admin\//, '🛠 ').replace(/^user\//, '👤 ') + '</button>').join('\n');

  const hub = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Responsive check — every screen</title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.5 -apple-system, 'Segoe UI', Roboto, sans-serif; background: #0b1220; color: #e2e8f0; }
  header { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 12px 18px; border-bottom: 1px solid #1e293b; background: #0f172a; position: sticky; top: 0; z-index: 5; }
  header strong { font-size: 15px; letter-spacing: -.2px; }
  header .hint { color: #94a3b8; font-size: 12px; }
  .widths { display: flex; gap: 6px; margin-left: auto; }
  .widths button { padding: 7px 12px; border-radius: 9px; border: 1px solid #1e293b; background: #111c30; color: #cbd5e1; font: inherit; font-size: 12.5px; cursor: pointer; }
  .widths button[aria-pressed="true"] { background: #4f46e5; border-color: #4f46e5; color: #fff; }
  main { display: grid; grid-template-columns: 260px 1fr; height: calc(100vh - 57px); }
  nav { overflow-y: auto; border-right: 1px solid #1e293b; padding: 10px; }
  nav button { display: block; width: 100%; text-align: left; padding: 9px 11px; margin-bottom: 3px; border: 0; border-radius: 8px; background: transparent; color: #cbd5e1; font: inherit; font-size: 13px; cursor: pointer; }
  nav button:hover { background: #111c30; }
  nav button[aria-current="true"] { background: #1e293b; color: #fff; }
  section { display: flex; justify-content: center; padding: 18px; overflow: auto; background: #0b1220; }
  .frame { width: 390px; max-width: 100%; height: 100%; border: 0; border-radius: 14px; background: #fff; box-shadow: 0 20px 60px rgba(0,0,0,.5); transition: width .18s ease; }
  .frame.wide { width: 100%; }
</style>
</head>
<body>
<header>
  <strong>Responsive check</strong>
  <span class="hint">every screen, rendered from the current tree — pick a width</span>
  <div class="widths">
    <button type="button" data-w="360">360 px</button>
    <button type="button" data-w="390" aria-pressed="true">390 px</button>
    <button type="button" data-w="414">414 px</button>
    <button type="button" data-w="768">768 px</button>
    <button type="button" data-w="0">Full width</button>
  </div>
</header>
<main>
  <nav>
${items}
  </nav>
  <section><iframe class="frame" id="frame" title="Preview"></iframe></section>
</main>
<script>
  var frame = document.getElementById('frame');
  var links = document.querySelectorAll('nav button');

  function show(button) {
    frame.src = button.dataset.src;
    links.forEach(function (b) { b.setAttribute('aria-current', String(b === button)); });
  }

  links.forEach(function (b) { b.addEventListener('click', function () { show(b); }); });
  if (links.length) { show(links[0]); }

  document.querySelectorAll('.widths button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.widths button').forEach(function (o) { o.setAttribute('aria-pressed', 'false'); });
      b.setAttribute('aria-pressed', 'true');
      var w = parseInt(b.dataset.w, 10);
      frame.classList.toggle('wide', w === 0);
      if (w) { frame.style.width = w + 'px'; }
    });
  });
</script>
</body>
</html>`;

  fs.writeFileSync(path.join(outDir, 'index.html'), hub);
  console.log('\n' + index.length + ' screens → ' + path.join(outDir, 'index.html'));
})();
