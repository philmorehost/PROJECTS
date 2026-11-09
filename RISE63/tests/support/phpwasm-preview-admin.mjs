/**
 * Static previews of the three admin screens this change rewrote, with the real stylesheet inlined
 * so the layout can be reviewed in a browser without a server, a database or a login.
 *
 *   node tests/support/phpwasm-preview-admin.mjs
 *
 * Each is rendered through the real layout.php and the real view file with fixtures — not hand-
 * written HTML — so what is reviewed is what the application actually emits. One process per
 * screen: php-wasm shares an interpreter and a stale fixture bleeding into the next render is
 * exactly the kind of thing that makes a preview lie.
 */
import { readdir, readFile, writeFile, mkdir } from 'node:fs/promises';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');

const { PhpNode } = require('php-wasm/PhpNode.mjs');
const phpModule = require('php-wasm/php8.3-node.mjs');

const SKIP = new Set(['node_modules', '.git', 'docs']);
async function walk(dir, out = []) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = join(dir, entry.name);
    if (entry.isDirectory()) await walk(full, out);
    else if (/\.(php|sql|css|js|svg|webmanifest)$/.test(entry.name)) out.push(full);
  }
  return out;
}

const files = (await walk(root)).sort();
const css = await readFile(join(root, 'public/assets/app.css'), 'utf8');
const js = await readFile(join(root, 'public/assets/app.js'), 'utf8');

await mkdir(join(root, 'docs/preview'), { recursive: true });

/** Fixtures shared by every screen. */
const FIXTURES = `
$account = array(
    'id' => 1, 'full_name' => 'Adaeze Okonkwo', 'email' => 'admin@example.org',
    'role' => 'provincial_admin', 'theme_preference' => 'system', 'large_text' => 0,
    'parish_id' => 1, 'is_suspended' => 0,
);
$admin = $account;
$roles = RISE63\\Core\\Auth::ROLES;
$money = static fn (string $v): RISE63\\Core\\Money => RISE63\\Core\\Money::fromNaira($v);
`;

const SCREENS = [
  {
    slug: 'admin-treasury-funds',
    page: 'treasury',
    comment: 'Treasury, with the Seed Grant pool an administrator can now set.',
    code: `
RISE63\\Core\\View::render('staff/treasury', array(
    'title' => 'Treasury', 'page' => 'treasury', 'account' => $account, 'user' => $account,
    'flashes' => array(array('message' => 'Seed Grants pool for 2026 set to ₦600,000.00. Remaining: ₦300,000.00.', 'type' => 'success')),
    'csrf' => str_repeat('a', 64),
    'pending' => array(array(
        'id' => 88, 'amount' => '50000.00', 'payee_name' => 'GRACE COLLEGE', 'bank_name' => 'GTBank',
        'account_number' => '0123456789', 'payment_reference' => 'RS63-ABCD-261105', 'status' => 'pending',
        'applicant' => 'CHINEDU EZE', 'program_type' => 'education_bridge', 'cycle_year' => 2026,
        'parish_name' => 'LIVING WATER PARISH', 'created_at' => '2026-11-05 10:00:00',
    )),
    'entries' => array(array(
        'id' => 14, 'entry_type' => 'disbursement', 'program_type' => 'seed_grant', 'amount' => '100000.00',
        'balance_after' => '300000.00', 'reference' => 'LED-RS63-AAAA-261101',
        'description' => 'Seed grant to CHINEDU EZE', 'created_by' => 1,
        'created_at' => '2026-11-01 09:00:00', 'actor_name' => 'Adaeze Okonkwo', 'application_id' => 9,
    )),
    'totals' => array(
        'disbursed' => $money('300000'), 'pending' => $money('50000'), 'pool' => $money('250000'),
    ),
    'pinLock' => 0,
    'cycleYear' => 2026,
    'funds' => array(
        array('program' => 'seed_grant', 'label' => 'Seed Grants', 'configured' => $money('600000'),
              'balance' => array('opening' => $money('600000'), 'deposited' => $money('0'),
                                 'adjusted' => $money('0'), 'disbursed' => $money('300000'),
                                 'remaining' => $money('300000'), 'funded' => true)),
        array('program' => 'academy', 'label' => 'RISE Academy', 'configured' => $money('0'),
              'balance' => array('opening' => $money('0'), 'deposited' => $money('150000'),
                                 'adjusted' => $money('0'), 'disbursed' => $money('40000'),
                                 'remaining' => $money('110000'), 'funded' => true)),
        array('program' => 'cooperative', 'label' => 'Cooperative Access', 'configured' => $money('0'),
              'balance' => array('opening' => $money('0'), 'deposited' => $money('0'),
                                 'adjusted' => $money('0'), 'disbursed' => $money('0'),
                                 'remaining' => $money('0'), 'funded' => false)),
        array('program' => 'education_bridge', 'label' => 'Education Bridge', 'configured' => $money('0'),
              'balance' => array('opening' => $money('0'), 'deposited' => $money('0'),
                                 'adjusted' => $money('0'), 'disbursed' => $money('50000'),
                                 'remaining' => $money('250000'), 'funded' => true)),
    ),
    'poolEntries' => array(
        array('id' => 12, 'entry_type' => 'adjustment', 'program_type' => 'seed_grant', 'amount' => '600000.00',
              'balance_after' => '600000.00', 'reference' => 'POOL-9F2A-2026',
              'description' => 'Pool restated to ₦600,000.00 for 2026 · Provincial board allocation',
              'created_by' => 1, 'created_at' => '2026-11-08 08:40:00', 'full_name' => 'Adaeze Okonkwo'),
        array('id' => 9, 'entry_type' => 'pool_deposit', 'program_type' => 'academy', 'amount' => '150000.00',
              'balance_after' => '150000.00', 'reference' => 'FUND-77B1',
              'description' => 'Deposit to RISE Academy · Partner transfer',
              'created_by' => 2, 'created_at' => '2026-11-02 14:10:00', 'full_name' => 'Emeka Nwosu'),
    ),
    'approved' => array(array(
        'id' => 9, 'full_name' => 'CHINEDU EZE', 'program_type' => 'seed_grant',
        'requested_amount' => '100000.00', 'status' => 'approved',
    )),
));`,
  },
  {
    slug: 'admin-people-lifecycle',
    page: 'people',
    comment: 'People & roles: create, edit, suspend, sign in as, delete, and a second administrator.',
    code: `
RISE63\\Core\\View::render('staff/people', array(
    'title' => 'People & roles', 'page' => 'people', 'account' => $account, 'user' => $account,
    'flashes' => array(array('message' => 'Administrator created: yemi@example.org. Temporary password: kf7Qm2XpRt9wZ4!38 — write it down now; it cannot be shown again.', 'type' => 'success')),
    'csrf' => str_repeat('a', 64),
    'people' => array(
        array('id' => 2, 'full_name' => 'CHINEDU EZE', 'email' => 'chinedu@example.ng', 'phone' => '08031234567',
              'role' => 'youth_member', 'parish_name' => 'LIVING WATER PARISH', 'parish_name_raw' => null,
              'last_login_at' => '2026-11-07 19:22:00', 'is_suspended' => 0, 'must_change_password' => 0,
              'created_at' => '2026-09-14 10:00:00'),
        array('id' => 3, 'full_name' => 'GRACE ADEYEMI', 'email' => 'grace@example.ng', 'phone' => null,
              'role' => 'csr_rep', 'parish_name' => null, 'parish_name_raw' => 'CHAPEL OF WONDERS PARISH',
              'last_login_at' => null, 'is_suspended' => 0, 'must_change_password' => 1,
              'created_at' => '2026-10-20 08:30:00'),
        array('id' => 4, 'full_name' => 'EMEKA NWOSU', 'email' => 'emeka@example.ng', 'phone' => null,
              'role' => 'provincial_treasurer', 'parish_name' => 'GRACE PARISH', 'parish_name_raw' => null,
              'last_login_at' => '2026-11-08 07:05:00', 'is_suspended' => 1, 'must_change_password' => 0,
              'created_at' => '2026-08-02 11:00:00'),
        array('id' => 1, 'full_name' => 'ADAEZE OKONKWO', 'email' => 'admin@example.org', 'phone' => null,
              'role' => 'provincial_admin', 'parish_name' => 'HOPE PARISH', 'parish_name_raw' => null,
              'last_login_at' => '2026-11-08 09:00:00', 'is_suspended' => 0, 'must_change_password' => 0,
              'created_at' => '2026-07-01 09:00:00'),
    ),
    'pager' => RISE63\\Core\\Paginator::fromState(20, null, 4, array('per_page' => '20'), 'people', true),
    'term' => '', 'roleFilter' => '', 'statusFilter' => '',
    'counts' => array('total' => 214, 'suspended' => 1, 'temporary' => 1, 'admins' => 2,
                      'treasurers' => 1, 'reps' => 9, 'members' => 202),
    'roles' => $roles,
    'parishes' => array(
        array('id' => 1, 'parish_name' => 'LIVING WATER PARISH'),
        array('id' => 2, 'parish_name' => 'GRACE PARISH'),
        array('id' => 3, 'parish_name' => 'HOPE PARISH'),
    ),
    'editing' => array('id' => 2, 'full_name' => 'CHINEDU EZE', 'email' => 'chinedu@example.ng',
                       'phone' => '08031234567', 'role' => 'youth_member', 'parish_id' => 1,
                       'parish_name_raw' => null, 'created_at' => '2026-09-14 10:00:00'),
    'unlisted' => array(array('parish_name_raw' => 'CHAPEL OF WONDERS PARISH', 'member_count' => 3)),
));`,
  },
  {
    slug: 'admin-settings-audit',
    page: 'settings',
    comment: 'Settings: the program year panel and the paginated, filterable audit card.',
    code: `
$events = array();
$names = array('Adaeze Okonkwo', 'Emeka Nwosu', 'GRACE ADEYEMI', 'CHINEDU EZE');
$actions = array('user.role', 'ledger.adjust', 'settings.update', 'user.suspend', 'remittance.confirm',
                 'user.create', 'user.impersonate.start', 'admin.create');
$summaries = array(
    'Role set to provincial_treasurer', 'Seed Grants pool for 2026 set to ₦600,000.00 (Provincial board allocation)',
    'academy_graduation_percent changed', 'GRACE ADEYEMI <grace@example.ng>',
    'Disbursement confirmed: ₦100,000.00', 'Yemi Abiose <yemi@example.org> as Provincial administrator',
    'admin@example.org signed in as chinedu@example.ng', 'Yemi Abiose <yemi@example.org> as Provincial administrator',
);
for ($i = 0; $i < 25; $i++) {
    $events[] = array(
        'id' => 400 - $i,
        'created_at' => gmdate('Y-m-d H:i:s', strtotime('2026-11-08 09:00:00') - $i * 417),
        'actor_email' => array('admin@example.org', 'emeka@example.ng', 'grace@example.ng', 'chinedu@example.ng')[$i % 4],
        'actor_name' => $names[$i % 4],
        'actor_role' => array('provincial_admin', 'provincial_treasurer', 'csr_rep', 'youth_member')[$i % 4],
        'action' => $actions[$i % 8],
        'entity' => array('user', 'fund', 'setting', 'user')[ $i % 4 ],
        'entity_id' => (string) (1 + $i % 12),
        'summary' => $summaries[$i % 8],
    );
}
RISE63\\Core\\View::render('staff/settings', array(
    'title' => 'Settings', 'page' => 'settings', 'account' => $account, 'user' => $account,
    'flashes' => array(),
    'csrf' => str_repeat('a', 64),
    'settings' => array(
        'site_title' => 'RISE 63', 'site_tagline' => 'RCCG Lagos Province 63 empowerment platform',
        'notification_email' => 'ops@example.org', 'theme_color' => '#2563eb',
        'pwa_install_prompt' => '1', 'maintenance_mode' => '0', 'session_idle_minutes' => '30',
        'login_alerts_enabled' => '1', 'academy_graduation_percent' => '80',
        'seed_grant_monthly_pool' => '100000.00', 'seed_grant_max_per_application' => '100000.00',
        'education_bridge_sla_hours' => '72', 'current_cycle_year' => '2026',
        'cycle_year_mode' => 'auto', 'seed_grant_opening_balance' => '600000.00',
    ),
    'outbox' => array(array('id' => 1, 'recipient' => 'ops@example.org', 'subject' => 'RISE 63 notification test',
                            'status' => 'pending', 'attempts' => 0, 'created_at' => '2026-11-08 09:00:00')),
    'queue' => array('pending' => 3, 'failed' => 0),
    'audit' => $events,
    'auditPager' => RISE63\\Core\\Paginator::fromState(25, null, 25, array('per_page' => '25'), 'settings', true),
    'auditFilters' => array('actor' => '', 'action' => '', 'entity' => '', 'kind' => '', 'q' => ''),
    'auditActors' => array(
        array('id' => 1, 'full_name' => 'Adaeze Okonkwo', 'email' => 'admin@example.org', 'role' => 'provincial_admin'),
        array('id' => 2, 'full_name' => 'Emeka Nwosu', 'email' => 'emeka@example.ng', 'role' => 'provincial_treasurer'),
    ),
    'auditActions' => array('admin.create', 'ledger.adjust', 'remittance.confirm', 'settings.update',
                            'user.create', 'user.impersonate.start', 'user.role', 'user.suspend'),
    'auditTotal' => 1842,
    'cycleYear' => 2026, 'autoYear' => '2026', 'yearMode' => 'auto',
    'funds' => array(),
));`,
  },
  {
    slug: 'admin-activity-report',
    page: 'audit',
    comment: 'Activity report: who has been doing what, across every administrator and member.',
    code: `
$summary = array(
    array('actor_id' => 1, 'actor_name' => 'Adaeze Okonkwo', 'actor_email' => 'admin@example.org',
          'actor_role' => 'provincial_admin', 'event_count' => 412, 'money_count' => 96, 'account_count' => 41,
          'last_seen' => '2026-11-08 09:00:00'),
    array('actor_id' => 2, 'actor_name' => 'Emeka Nwosu', 'actor_email' => 'emeka@example.ng',
          'actor_role' => 'provincial_treasurer', 'event_count' => 233, 'money_count' => 118, 'account_count' => 3,
          'last_seen' => '2026-11-08 07:05:00'),
    array('actor_id' => 3, 'actor_name' => 'GRACE ADEYEMI', 'actor_email' => 'grace@example.ng',
          'actor_role' => 'csr_rep', 'event_count' => 64, 'money_count' => 0, 'account_count' => 12,
          'last_seen' => '2026-11-06 16:40:00'),
    array('actor_id' => 2, 'actor_name' => 'CHINEDU EZE', 'actor_email' => 'chinedu@example.ng',
          'actor_role' => 'youth_member', 'event_count' => 18, 'money_count' => 4, 'account_count' => 0,
          'last_seen' => '2026-11-07 19:22:00'),
);
RISE63\\Core\\View::render('staff/audit', array(
    'title' => 'Activity report', 'page' => 'audit', 'account' => $account, 'user' => $account,
    'flashes' => array(),
    'csrf' => str_repeat('a', 64),
    'summary' => $summary,
    'events' => array(array(
        'id' => 902, 'created_at' => '2026-11-08 09:00:00', 'actor_email' => 'admin@example.org',
        'actor_name' => 'Adaeze Okonkwo', 'actor_role' => 'provincial_admin', 'actor_suspended' => 0,
        'action' => 'ledger.adjust', 'entity' => 'fund', 'entity_id' => 'seed_grant',
        'summary' => 'Seed Grants pool for 2026 set to ₦600,000.00 (Provincial board allocation)',
    ), array(
        'id' => 901, 'created_at' => '2026-11-08 08:52:00', 'actor_email' => 'admin@example.org',
        'actor_name' => 'Adaeze Okonkwo', 'actor_role' => 'provincial_admin', 'actor_suspended' => 0,
        'action' => 'admin.create', 'entity' => 'user', 'entity_id' => '14',
        'summary' => 'Yemi Abiose <yemi@example.org> as Provincial administrator',
    ), array(
        'id' => 900, 'created_at' => '2026-11-07 19:22:00', 'actor_email' => 'chinedu@example.ng',
        'actor_name' => 'CHINEDU EZE', 'actor_role' => 'youth_member', 'actor_suspended' => 0,
        'action' => 'application.submit', 'entity' => 'application', 'entity_id' => '31',
        'summary' => 'Seed Grant request submitted',
    )),
    'pager' => RISE63\\Core\\Paginator::fromState(25, null, 3, array('per_page' => '25'), 'audit', true),
    'filters' => array('actor' => '', 'action' => '', 'entity' => '', 'kind' => 'admins', 'q' => ''),
    'actors' => array(
        array('id' => 1, 'full_name' => 'Adaeze Okonkwo', 'email' => 'admin@example.org', 'role' => 'provincial_admin'),
        array('id' => 2, 'full_name' => 'Emeka Nwosu', 'email' => 'emeka@example.ng', 'role' => 'provincial_treasurer'),
    ),
    'actions' => array('admin.create', 'ledger.adjust', 'remittance.confirm', 'settings.update', 'user.role'),
    'total' => 1842, 'financial' => 214, 'adminEvents' => 645, 'cycleYear' => 2026,
));`,
  },
];

let failures = 0;

for (const screen of SCREENS) {
  const runtime = new PhpNode(phpModule);
  let output = '';
  runtime.addEventListener('output', (event) => { output += event.detail[0] ?? ''; });
  runtime.addEventListener('error', (event) => { output += String(event.detail?.[0] ?? ''); });
  await runtime.refresh();

  const madeDirs = new Set();
  const ensure = async (path) => {
    let current = '';
    for (const part of path.split('/').filter(Boolean)) {
      current += '/' + part;
      if (madeDirs.has(current)) continue;
      try { await runtime.mkdir(current); } catch { /* exists */ }
      madeDirs.add(current);
    }
  };
  for (const dir of ['/src', '/src/config', '/src/storage']) await ensure(dir);
  for (const file of files) {
    const target = '/src/' + relative(root, file);
    await ensure(target.split('/').slice(0, -1).join('/'));
    await runtime.writeFile(target, await readFile(file, 'utf8'));
  }

  await runtime.run(`<?php
$_SERVER = array(
    'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?page=${screen.page}',
    'HTTP_HOST' => 'buildroom.rccglp63yaya.org.ng', 'HTTPS' => 'on',
    'REMOTE_ADDR' => '105.112.24.31', 'SCRIPT_NAME' => '/index.php',
    'HTTP_USER_AGENT' => 'preview',
);
$_GET = array('page' => '${screen.page}'); $_POST = array(); $_REQUEST = array('page' => '${screen.page}');
chdir('/src');
require '/src/app/bootstrap.php';
RISE63\\Core\\Security::csrf();
${FIXTURES}
${screen.code}`);

  await new Promise((resolve) => setTimeout(resolve, 1500));

  let html = output;
  if (/Fatal error|Uncaught/.test(html)) {
    console.error(`\n${screen.slug}: render failed\n${html.slice(0, 2000)}`);
    failures++;
    continue;
  }

  // The stylesheet and script URLs carry a content fingerprint, so match generally: a preview that
  // silently loses its styling looks like a layout bug and wastes the review.
  html = html.replace(/<link rel="stylesheet" href="[^"]*app(?:\.[0-9a-f]{8})?\.css(?:\?v=[0-9a-f]{8})?">/,
    '<style>\n' + css + '\n</style>');
  html = html.replace(/<script src="[^"]*app\.js"(?: defer)?><\/script>/, '<script defer>\n' + js + '\n</script>');
  html = html.replace('<head>',
    '<head>\n<!-- Static preview: ' + screen.comment + ' Generated from the real view with app.css inlined. -->');

  await writeFile(join(root, 'docs/preview', screen.slug + '.html'), html);
  console.log(`${screen.slug} → docs/preview/${screen.slug}.html (${html.length} bytes)`);
}

process.exit(failures > 0 ? 1 : 0);
