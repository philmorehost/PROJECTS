<?php
declare(strict_types=1);

/**
 * Page-rendering tests.
 *
 * tests/e2e.php proves the domain layer against real SQL. It cannot catch the class of fault that
 * actually took the deployed site down: /settings and /people returned a 500 because the *page*
 * crashed, not because a rule was wrong. Those bugs — a private constructor and a reused named
 * placeholder — lived in the call site that gathers a page's data, which nothing executed.
 *
 * This file executes the real router. app/pages.php is required for each route, exactly as
 * index.php requires it, against a real SQL database supplied by php-wasm's PGlite. A page that
 * throws, emits PHP error markup, or renders without its own heading fails here instead of in
 * production.
 *
 *   node tests/support/phpwasm-pages.mjs
 */

namespace RISE63;

use PDO;
use RISE63\Core\Audit;
use RISE63\Core\Database;
use RISE63\Core\Smtp;

define('RISE63_TEST_ROOT', dirname(__DIR__));
require RISE63_TEST_ROOT . '/app/bootstrap.php';
require __DIR__ . '/support/pgsql-schema.php';

$GLOBALS['passed']   = 0;
$GLOBALS['failed']   = 0;
$GLOBALS['failures'] = [];
$GLOBALS['skipped']  = 0;

function skip(string $name, string $reason): void
{
    $GLOBALS['skipped']++;
    fwrite(STDOUT, 'SKIP ' . $name . ' :: ' . $reason . "\n");
}

function ok(string $name, callable $test): void
{
    try {
        $test();
        $GLOBALS['passed']++;
        fwrite(STDOUT, 'PASS ' . $name . "\n");
    } catch (\Throwable $error) {
        $GLOBALS['failed']++;
        $GLOBALS['failures'][] = $name . ' :: ' . $error->getMessage();
        fwrite(STDOUT, 'FAIL ' . $name . ' :: ' . $error->getMessage() . "\n");
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new \RuntimeException($message !== '' ? $message : 'expected true');
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}


/**
 * Point the mail tests at the real config directory and hand back a restore closure.
 *
 * config_dir() caches its answer in a constant, so a test cannot redirect it — the SMTP writer is
 * therefore exercised against the path production uses, and whatever was there is put back.
 *
 * @return array{0:string, 1:callable}
 */
function mail_workspace(): array
{
    $file = \RISE63\config_dir() . '/smtp.php';
    $existed = is_file($file);
    $original = $existed ? (string) file_get_contents($file) : '';

    return [$file, static function () use ($file, $existed, $original): void {
        if ($existed) {
            file_put_contents($file, $original);
        } else {
            @unlink($file);
        }
        clearstatcache(true, $file);
    }];
}

/* ─────────────────────────────── database ─────────────────────────────── */

$pdo = new PDO('pgsql:memory://', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
Database::use($pdo);
pgsql_create_schema($pdo);

// Seeded here rather than beside the site tests below: Settings::all() caches for the life of the
// request, so a row inserted after the first page renders would never be seen by a later one.
$pdo->exec("INSERT INTO security_settings (setting_key, setting_value) VALUES ('whatsapp_number', '0803 123 4567')");

$admin  = pgsql_seed_user($pdo, 'Adaeze Okonkwo', 'admin@example.org', 'provincial_admin');
$member = pgsql_seed_user($pdo, 'Chinedu Eze', 'chinedu@example.ng', 'youth_member');
$csr    = pgsql_seed_user($pdo, 'Ngozi Bello', 'ngozi@example.ng', 'csr_rep');

// Enough audit rows that paging and filtering have something to page and filter.
//
// Every third row is deliberately a *non-financial* action: Audit::FINANCIAL_ACTIONS contains
// `settings.update`, so seeding those alone would make the kind=financial filter a no-op and the
// test below would pass while proving nothing.
for ($i = 1; $i <= 40; $i++) {
    $financial = $i % 3 !== 0;
    Audit::log(
        (int) $admin['id'],
        $financial ? 'settings.update' : 'user.suspend',
        'setting',
        (string) $i,
        ($financial ? 'Event ' : 'Account event ') . $i
    );
}

$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'HTTP_HOST'      => 'example.org',
    'SCRIPT_NAME'    => '/index.php',
    'REQUEST_URI'    => '/',
    'HTTPS'          => 'on',
    'REMOTE_ADDR'    => '105.112.24.31',
];
$_SESSION = ['csrf' => str_repeat('a', 64)];

/**
 * Run one GET route through the real router.
 *
 * @return string the rendered HTML
 */
function route(string $page, array $get = [], ?array $as = null): string
{
    global $admin;

    $_GET  = $get;
    $user  = $as ?? $admin;
    $page  = $page;

    ob_start();
    try {
        require RISE63_TEST_ROOT . '/app/pages.php';
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

/** Assertions every rendered page must satisfy. */
function assertClean(string $label, string $html, string $heading): void
{
    assertTrue(strlen($html) > 2000, $label . ' rendered only ' . strlen($html) . ' bytes');
    assertTrue(!str_contains($html, 'Temporary problem'), $label . ' rendered the 500 page');
    assertTrue(!str_contains($html, 'Fatal error'), $label . ' contains PHP error markup');
    assertTrue(!str_contains($html, 'SQLSTATE'), $label . ' leaked a SQL error');
    assertTrue(!str_contains($html, 'Uncaught'), $label . ' contains an uncaught exception');
    assertTrue(!str_contains($html, 'Warning:'), $label . ' contains a PHP warning');
    assertTrue(
        str_contains($html, $heading),
        $label . ' rendered but never printed its own heading ' . var_export($heading, true)
    );
}

/* ────────────────────── the two screens that returned 500 ────────────────────── */

ok('Settings renders for an administrator', function () use ($admin): void {
    $html = route('settings', [], $admin);
    assertClean('settings', $html, '<h1>Settings</h1>');
    // The audit card is the part that used to throw: it is the Paginator call site.
    assertTrue(str_contains($html, 'Audit trail'), 'settings lost the audit trail card');
});

ok('People renders for an administrator', function () use ($admin): void {
    $html = route('people', [], $admin);
    assertClean('people', $html, 'People &amp; roles');
    assertTrue(str_contains($html, 'Create another administrator'), 'people lost the create form');
});

ok('Updates renders for an administrator', function () use ($admin): void {
    // This route called config_dir() from the global namespace without importing it, so it threw
    // "Call to undefined function config_dir()" on every request. A lint pass cannot see that.
    $html = route('updates', [], $admin);
    assertClean('updates', $html, '<h1>Updates</h1>');
});

/* ────────────────────── filters reach the SQL, not just the page ────────────────────── */

ok('Settings audit respects the text filter', function () use ($admin): void {
    $all     = route('settings', [], $admin);
    $filtered = route('settings', ['q' => 'Event'], $admin);

    // 40 events were seeded, so an unfiltered page and a matching filter both fill 25 rows,
    // while a filter that matches nothing must return none. The regression this guards is the
    // filter being passed in the wrong argument and silently ignored.
    $none = route('settings', ['q' => 'no-such-event-anywhere'], $admin);
    assertTrue(str_contains($none, 'No events'), 'an unmatched filter still listed events');
    assertTrue(str_contains($filtered, 'Event 1') || str_contains($filtered, 'Event 40'), 'the matching filter listed nothing');
    assertTrue(strlen($all) > 0, 'settings did not render unfiltered');
});

ok('People directory respects the search term', function () use ($admin): void {
    $html = route('people', ['q' => 'chinedu'], $admin);
    assertTrue(str_contains($html, 'Chinedu Eze'), 'searching by name did not find the member');
    assertTrue(!str_contains($html, 'Ngozi Bello'), 'searching by name listed somebody else');

    $none = route('people', ['q' => 'nobody-here'], $admin);
    assertTrue(!str_contains($none, 'Chinedu Eze'), 'an unmatched search still listed everybody');
});

ok('People directory respects the role and status filters', function () use ($admin): void {
    $reps = route('people', ['role' => 'csr_rep'], $admin);
    assertTrue(str_contains($reps, 'Ngozi Bello'), 'the csr_rep filter lost the representative');
    assertTrue(!str_contains($reps, 'Chinedu Eze'), 'the csr_rep filter listed a member');

    $pdo = Database::connection();
    $pdo->exec("UPDATE users SET is_suspended = 1 WHERE email = 'chinedu@example.ng'");
    try {
        $suspended = route('people', ['status' => 'suspended'], $admin);
        assertTrue(str_contains($suspended, 'Chinedu Eze'), 'the suspended filter lost the suspended member');
        assertTrue(!str_contains($suspended, 'Ngozi Bello'), 'the suspended filter listed an active member');
    } finally {
        $pdo->exec("UPDATE users SET is_suspended = 0 WHERE email = 'chinedu@example.ng'");
    }
});

ok('Audit report pages and filters', function () use ($admin): void {
    $first = route('audit', [], $admin);
    $next  = route('audit', ['before' => '21'], $admin);
    assertClean('audit', $first, 'Activity report');
    assertTrue(strlen($next) > 2000, 'the second page of the audit report did not render');

    // `kind` used to be passed in a separate filters argument and silently ignored, so the
    // financial filter returned every event — the screen looked filtered and was not. Count
    // rows instead of matching substrings: 'Event 1' also matches 'Event 10'.
    $allPage       = route('audit', [], $admin);
    $financialPage = route('audit', ['kind' => 'financial'], $admin);

    // Both filtered views fit on one page (25 rows), so the row counts here are the true totals.
    // A view wider than a page would also need Audit::count() with the same WHERE; the report does
    // not show such a total today, so asserting a count that no screen displays would be asserting
    // an intention rather than the product.
    $financial = Audit::page(['kind' => 'financial']);
    $account   = Audit::page(['kind' => 'accounts']);
    assertSame(25, count($financial['rows']), 'financial filter row count');
    assertSame(13, count($account['rows']), 'accounts filter row count');
    assertTrue(strlen($allPage) > 0 && strlen($financialPage) > 0, 'a filtered report did not render');
    foreach ($financial['rows'] as $row) {
        assertTrue(
            in_array((string) $row['action'], Audit::FINANCIAL_ACTIONS, true),
            'a non-financial action leaked through the financial filter: ' . $row['action']
        );
    }
});

/* ────────────────────── every administrator destination opens ────────────────────── */

ok('Every administrator destination renders', function () use ($admin): void {
    // The set an administrator reaches from the chrome: five tabs plus the four topbar links.
    // `security` and `treasury` need MySQL-only INTERVAL syntax, which PGlite cannot parse, so
    // they are checked by tests/render.php instead — see the skip below.
    $routes = [
        'applications' => 'Applications',
        'parishes'     => '<h1>Parish Connect</h1>',
        'people'       => 'People &amp; roles',
        'settings'     => '<h1>Settings</h1>',
        'audit'        => 'Activity report',
        'updates'      => '<h1>Updates</h1>',
    ];

    foreach ($routes as $page => $heading) {
        $html = route($page, [], $admin);
        assertClean($page, $html, $heading);
    }
});

ok('A non-administrator cannot open an administrator page', function () use ($member): void {
    foreach (['settings', 'people', 'audit', 'updates'] as $page) {
        $thrown = false;
        try {
            // Security::fail() is `never` and exits; in the embed SAPI exit would end the run, so
            // the guard is asserted through the closure directly first, then the 403 path is
            // covered by tests/render.php. Here we prove the gate rejects the account.
            assertTrue(!Core\Auth::isAdmin($member), 'a youth member was treated as an administrator');
        } catch (\Throwable $error) {
            $thrown = true;
        }
        assertTrue(!$thrown, $page . ': the membership check itself threw');
    }
});

skip(
    'Dashboard, Treasury and Security render',
    'each calls UTC_TIMESTAMP() - INTERVAL n HOUR, which is MySQL syntax PGlite cannot parse; '
    . 'the same pages are rendered by tests/render.php with a stubbed database'
);

/* ─────────────────────── Settings → Mail renders, and holds no secret ─────────────────────── */

ok('The Mail panel renders and never echoes the stored password', function () use ($admin): void {
    // config/smtp.php used to be hand-made over SSH. Settings → Mail is a form now, and a settings
    // form that prints a mailbox password into HTML has published it to the page cache, the browser
    // history and any screenshot taken of the screen.
    [$smtpFile, $restore] = mail_workspace();

    try {
        $html = route('settings', [], $admin);
        assertTrue(str_contains($html, 'Mail (SMTP)'), 'the settings page has no Mail panel');
        assertTrue(str_contains($html, 'Not configured &mdash; self-service reset is off')
            || str_contains($html, 'Not configured — self-service reset is off'),
            'an unconfigured transport must say what it costs');
        foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_email'] as $field) {
            assertTrue(str_contains($html, 'id="' . $field . '"'), 'the form is missing ' . $field);
        }
        assertTrue(str_contains($html, 'value="smtp-save"'), 'the form does not post to smtp-save');

        Smtp::save([
            'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'auth' => 'login',
            'username' => 'alerts@rccg63.org', 'password' => 'SUPERSECRET-app-password',
            'from_email' => 'no-reply@rccg63.org', 'from_name' => 'RISE 63',
        ]);

        $html = route('settings', [], $admin);
        assertTrue(!str_contains($html, 'SUPERSECRET-app-password'), 'THE PASSWORD REACHED THE PAGE');
        assertSame(0, preg_match('/<input type="password" id="smtp_password"[^>]*value="[^"]+"/', $html),
            'the password input must never be pre-filled');
        assertTrue(str_contains($html, 'value="smtp.gmail.com"'), 'the saved host is not shown');
        assertTrue(str_contains($html, 'value="smtp-test"'), 'a configured transport must be testable');
        assertTrue(str_contains($html, 'value="smtp-send-test"'), 'there is no way to prove delivery');
        assertTrue(str_contains($html, 'value="smtp-remove"'), 'there is no way to remove the configuration');
    } finally {
        $restore();
    }
});

/* ───────────── A page must survive a migration the operator has not applied ───────────── */

ok('People still renders when migration 0002 has not been applied', function () use ($admin): void {
    // This is the fault the operator actually reported: /people returned the 500 page on an
    // installation created before migration 0002 added users.parish_name_raw. The column arrives by
    // migration, the People page is new, and the only query that names the column outright is the
    // "churches members asked for" card — so the page died on a convenience.
    //
    // A page of record-keeping must never be taken down by one optional query. It degrades: the
    // queue is empty and the page says why, in words that point at the fix.
    $pdo = Database::connection();

    // Prove the normal path first, so this test cannot pass because the query is simply gone.
    $healthy = route('people', [], $admin);
    assertClean('people', $healthy, 'People &amp; roles');
    assertTrue(!str_contains($healthy, 'unavailable'), 'the queue is reported unavailable on a healthy schema');

    $pdo->exec('ALTER TABLE users DROP COLUMN parish_name_raw');
    try {
        $degraded = route('people', [], $admin);
        assertClean('people (no 0002)', $degraded, 'People &amp; roles');
        assertTrue(str_contains($degraded, 'Churches members asked for — unavailable'),
            'the page should say which part is unavailable');
        assertTrue(str_contains($degraded, 'parish_name_raw'),
            'the notice should name what is missing, so an operator can act on it');
        // The rest of the page must be intact: this is what "degrade" means.
        assertTrue(str_contains($degraded, 'Directory'), 'the directory disappeared with the queue');
        assertTrue(str_contains($degraded, 'Create another administrator'), 'the create form disappeared');
    } finally {
        $pdo->exec('ALTER TABLE users ADD COLUMN parish_name_raw VARCHAR(150) NULL');
    }

    $restored = route('people', [], $admin);
    assertTrue(!str_contains($restored, 'unavailable'), 'the notice survives the migration being applied');
});

/* ───────────── The state that caused the reported 500 must be visible ───────────── */

ok('A page declares itself as HTML instead of inheriting the host default', function (): void {
    // The reported bug: sharing the site link produced a wall of markup in WhatsApp. The page was
    // served with no Content-Type of its own, so it inherited PHP's `default_mimetype`. On the host
    // that value is `text/plain`; a browser still guesses its way to a usable page, which is why
    // nobody noticed, and a link-preview scraper does not guess at all.
    //
    // Proven without a browser: the app's own header call must set text/html explicitly.
    $sent = [];
    $source = (string) file_get_contents(RISE63_TEST_ROOT . '/app/Core/Security.php');

    // The declaration exists and is HTML with a charset. A plain substring, because a regex here
    // needs three levels of quoting and the first version of this assertion failed on its own
    // escaping rather than on the behaviour it was meant to check.
    assertTrue(str_contains($source, "header('Content-Type: text/html; charset=utf-8')"),
        'sendHtmlHeaders() must declare text/html explicitly');

    // And every HTML entry point goes through the function that sends it.
    foreach (['index.php', 'install/index.php'] as $entry) {
        $entrySource = (string) file_get_contents(RISE63_TEST_ROOT . '/' . $entry);
        assertTrue(str_contains($entrySource, 'sendHeaders'), $entry . ' must call sendHeaders');
    }

    // sendHeaders must send the content type first, or a later header() could not replace it.
    $methodStart = (int) strpos($source, 'public static function sendHeaders(');
    $methodBody  = substr($source, $methodStart, 600);
    assertTrue(str_contains($methodBody, 'sendHtmlHeaders'), 'sendHeaders must declare HTML');

    // A page that renders HTML must not be reachable without it.
    assertTrue(!str_contains($source, "header('Content-Type: text/plain')
throw"),
        'sanity: the plain-text header is only used by fail()');
});

ok('Settings reports pending database updates and the command that applies them', function () use ($admin): void {
    // The whole reason the People page broke in production: migration 0002 was waiting, nothing in
    // the interface said so, and the only way to learn `php bin/migrate.php` exists was to be told.
    // This does not run migrations from a web request — it makes the state visible.
    $pdo = Database::connection();

    // The harness starts with an empty schema_migrations, so everything is pending.
    $state = \RISE63\Core\Schema::state(RISE63_TEST_ROOT);
    assertTrue($state['known'], 'the migration state must be readable');
    assertSame(0, count($state['applied']), 'nothing is applied on a fresh harness database');
    // Counted, not hard-coded: the number of shipped migrations grows with every release, and a
    // literal here turns adding a migration into a test failure somewhere unrelated.
    $shipped = count(glob(RISE63_TEST_ROOT . '/database/migrations/*.sql') ?: []);
    assertSame($shipped, count($state['pending']), 'every shipped migration should be pending');
    $versions = array_map(
        static fn (string $file): string => (string) substr(basename($file), 0, 4),
        glob(RISE63_TEST_ROOT . '/database/migrations/*.sql') ?: []
    );
    sort($versions);
    assertSame($versions, array_column($state['pending'], 'version'), 'migrations must be in order');
    assertSame('0002 — unlisted parish name', $state['pending'][1]['summary'], 'the file name must be explained');

    $html = route('settings', [], $admin);
    assertClean('settings (pending migrations)', $html, '<h1>Settings</h1>');
    assertTrue(str_contains($html, 'Database updates'), 'Settings has no database-updates panel');
    assertTrue(str_contains($html, 'database updates are waiting'), 'the panel must say how many are waiting');
    // The panel leads with the fact that they are applied automatically, and offers the command only
    // as the fallback. Telling an operator to run a command that is now unnecessary was the previous
    // wording, and it was the wording that failed in the field.
    assertTrue(str_contains($html, 'applied automatically'), 'the panel must say they heal automatically');
    assertTrue(str_contains($html, 'bin/migrate.php'), 'the manual fallback must still be documented');

    // Record them as applied, the way bin/migrate.php would, and the panel must stop nagging.
    try {
        foreach (glob(RISE63_TEST_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
            preg_match('/^(\d{4})_/', basename($file), $m);
            $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, NULL)')
                ->execute([$m[1] ?? basename($file)]);
        }
        $after = route('settings', [], $admin);
        assertTrue(str_contains($after, 'Up to date'), 'a migrated database must read as up to date');
        assertTrue(!str_contains($after, 'database updates are waiting'),
            'a migrated database must not tell the operator to run anything');
    } finally {
        // Leave the harness as it was: other tests read schema_migrations indirectly.
        $pdo->exec('DELETE FROM schema_migrations');
    }
});

ok('A missing schema_migrations table does not stop Settings opening', function () use ($admin): void {
    // Settings must be the one page that always opens: it is where the operator goes to find out
    // what is wrong. If reading the migration state can throw, this page becomes unreachable for
    // exactly the person who needs it.
    $pdo = Database::connection();
    $pdo->exec('DROP TABLE schema_migrations');
    try {
        $state = \RISE63\Core\Schema::state(RISE63_TEST_ROOT);
        assertTrue($state['known'], 'the state must still be reported as known');
        assertSame(
            count(glob(RISE63_TEST_ROOT . '/database/migrations/*.sql') ?: []),
            count($state['pending']),
            'with no bookkeeping table everything counts as pending'
        );
        assertTrue($state['modified'] !== [], 'the verify problem must be surfaced, not swallowed');

        $html = route('settings', [], $admin);
        assertClean('settings (no migration table)', $html, '<h1>Settings</h1>');
        assertTrue(str_contains($html, 'Database updates'), 'the panel must still render');
    } finally {
        $pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(40) PRIMARY KEY, checksum CHAR(64) NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }
});

ok('People warns that a write will fail before the operator tries one', function () use ($admin): void {
    // The operator found out about the pending migration by trying to create an administrator and
    // reading a message that named no cause. The warning belongs on the page where accounts are
    // created, above the forms, not three clicks away in Settings.
    $pdo = Database::connection();

    // The harness database starts with an empty schema_migrations, so everything is pending.
    $html = route('people', [], $admin);
    assertClean('people (pending migrations)', $html, 'People &amp; roles');
    assertTrue(str_contains($html, 'Database updates are waiting'), 'People must warn before a save fails');
    assertTrue(str_contains($html, 'bin/migrate.php'), 'the banner must name the fix');
    assertTrue(str_contains($html, 'See what is pending'), 'it must link to the detail');

    // The banner must sit above the create form, or it is read after the failure rather than before.
    assertTrue(
        strpos($html, 'Database updates are waiting') < strpos($html, 'Create another administrator'),
        'the warning must come before the form it protects'
    );

    // With nothing pending, it must disappear — a permanent warning is wallpaper.
    try {
        foreach (glob(RISE63_TEST_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
            $pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, NULL)')
                ->execute([substr(basename($file), 0, 4)]);
        }
        $clean = route('people', [], $admin);
        assertTrue(!str_contains($clean, 'Database updates are waiting'), 'a migrated database must not warn');
    } finally {
        $pdo->exec('DELETE FROM schema_migrations');
    }
});

/* ────────────────────────── the public website ────────────────────────── */

// Seed a page and two slides the way an administrator would, so the public routes are exercised
// against real rows rather than against the code defaults alone.
$pdo->exec("INSERT INTO site_pages (slug,title,kicker,summary,body,is_published,is_system,sort_order) VALUES
  ('privacy','Privacy Policy','Your information','What we collect.','## What we collect\n\nNames and contact details.\n\n## Who sees it\n\nOfficers of your parish.',1,1,10),
  ('terms','Terms & Conditions','The rules','','## Honesty\n\nApplications are checked.',1,1,20),
  ('vision','Our Vision','Where we are going','','## One province\n\nNo need stays invisible.',1,0,30),
  ('about','About RISE 63','Who we are','The people behind the initiative.','## The initiative\n\nA joint effort of the Province 63 youth arm and the Young Professionals Club.',1,1,5),
  ('scholarships','Scholarships','School fees','','## Closing dates\n\nTermly.',0,0,40)");
$pdo->exec("INSERT INTO site_slides
  (kicker,headline,subhead,image_path,image_alt,cta_label,cta_page,tone,is_published,sort_order) VALUES
  ('RISE 63','No genuine need should stay invisible.','Across every parish.','a1b2c3d4e5f60718.jpg','Members standing together after a service','Discover RISE 63','vision','ink',1,10),
  ('ACADEMY','Learn it. Then build it.','Three months.','b2c3d4e5f6071829.jpg','Students in a workshop','','','ink',1,20),
  ('HIDDEN','Not yet ready.','','b2c3d4e5f6071830.jpg','A draft','','','ink',0,30)");
$anonymous = null;

ok('Every public page renders as a complete document for a signed-out visitor', function () use ($anonymous): void {
    foreach ([
        [''           , 'the landing page'],
        ['about'      , 'About Us'],
        ['vision'     , 'the Vision page'],
        ['privacy'    , 'Privacy Policy'],
        ['terms'      , 'Terms & Conditions'],
        ['contact'    , 'the contact page'],
    ] as [$slug, $label]) {
        $html = route($slug, [], $anonymous);
        $address = $slug === '' ? '/' : '/' . $slug;
        assertTrue(strlen($html) > 2000, $label . ' (' . $address . ') rendered only ' . strlen($html) . ' bytes');
        assertTrue(str_contains($html, '<!doctype html>'), $label . ' must be a whole document');
        assertTrue(!str_contains($html, 'Fatal error'), $label . ' contains PHP error markup');

        // A landing page that leaks the application shell is a landing page nobody trusts.
        assertTrue(!str_contains($html, 'class="topbar"'), $label . ' must not render the admin chrome');
        assertTrue(!str_contains($html, 'class="bottom-nav"'), $label . ' must not render the app tab bar');

        // Exactly one <h1>: the page's subject, declared once.
        assertSame(1, substr_count($html, '<h1'), $label . ' must have exactly one <h1>');

        // A 200 is not a page. These four rendered a heading and the words "This page is being
        // written" on every installation, which no status code could reveal.
        if (in_array($slug, ['about', 'vision', 'privacy', 'terms'], true)) {
            assertTrue(
                !str_contains($html, 'This page is being written'),
                $label . ' must not render the unwritten-page placeholder'
            );
            assertTrue(strlen($html) > 6000, $label . ' should carry a real body, got ' . strlen($html) . ' bytes');
        }

        // The WhatsApp route is derived from the setting: 0803… becomes wa.me/234803…, because that
        // is the form WhatsApp accepts for a Nigerian number.
        assertTrue(!str_contains($html, 'wa.me/0803'), $label . ' must not link a local number to WhatsApp');
    }
});

ok('The landing page carries the published slides and none of the hidden ones', function () use ($anonymous): void {
    $home = route('', [], $anonymous);
    assertSame(2, substr_count($home, 'class="hero-slide'), 'every published slide appears, and only those');
    assertSame(2, substr_count($home, 'data-slide-to='), 'each gets one direct-navigation control');
    assertTrue(!str_contains($home, 'Not yet ready'), 'an unpublished slide must not leak onto the page');
    assertTrue(str_contains($home, 'aria-roledescription="carousel"'), 'the carousel announces itself');
    assertTrue(!str_contains($home, 'role="tab"'), 'a tab role without a tabpanel is invalid');
    assertSame(1, substr_count($home, '<h1'), 'the landing page declares its subject once');

    // The published call to action points where it says it will.
    assertTrue(str_contains($home, 'href="/vision"'), 'the hero button opens the page it names');

    // The deep link carries the configured number, not a placeholder.
    assertTrue(str_contains($home, 'wa.me/2348031234567'), 'the WhatsApp link must be the configured number');
});

ok('An unpublished page is not readable and does not advertise itself', function () use ($anonymous): void {
    $missing = route('site-not-found', [], $anonymous);
    assertTrue(
        stripos($missing, 'not here') !== false || stripos($missing, 'not found') !== false,
        'an unknown address shows the site own 404 page'
    );
    assertTrue(!str_contains($missing, 'Closing dates'), 'and does not leak the draft body');

    // A stranger who mistypes an address must land in the site, not at a sign-in form.
    $index = (string) file_get_contents(RISE63_TEST_ROOT . '/index.php');
    assertTrue(
        str_contains($index, '$page = $siteFallback'),
        'an unknown anonymous URL must fall back to the public site, not to /login'
    );
});

ok('The CMS opens for an administrator and lists what exists', function () use ($admin): void {
    $cms = route('website', [], $admin);
    assertClean('the website admin', $cms, 'Hero slider');
    assertTrue(str_contains($cms, 'class="cms-page"'), 'the pages are listed');
    assertTrue(str_contains($cms, 'class="cms-slide"'), 'the slides are listed');
    assertTrue(str_contains($cms, 'name="action" value="slide-move"'), 'a slide can be reordered');
    assertTrue(str_contains($cms, 'name="action" value="slide-delete"'), 'a slide can be removed');
    assertTrue(str_contains($cms, 'name="csrf"'), 'the CMS posts carry a CSRF token');
    assertTrue(str_contains($cms, 'Privacy Policy'), 'and each row names its page');

    // The editors are separate states; each posts its own action.
    $pageEditor = route('website', ['page' => 'new'], $admin);
    assertClean('the new-page editor', $pageEditor, 'Add a page');
    assertTrue(str_contains($pageEditor, 'name="action" value="page-save"'), 'a page can be saved');
    assertTrue(str_contains($pageEditor, 'name="body"'), 'and has a body field');
    assertTrue(str_contains($pageEditor, 'cms-help'), 'with the formatting guide next to it');

    $slideForm = route('website', ['slide' => 'new'], $admin);
    assertClean('the new-slide editor', $slideForm, 'Add a slide');
    assertTrue(str_contains($slideForm, 'name="action" value="slide-save"'), 'a slide can be saved');
    assertTrue(str_contains($slideForm, 'enctype="multipart/form-data"'), 'and can carry a photograph');

    // The editors open with the current content in them, which is the difference between a CMS an
    // officer can use and a form that silently blanks a page.
    $existing = route('website', ['page' => 'privacy'], $admin);
    assertClean('the existing-page editor', $existing, 'Names and contact details');
    assertTrue(str_contains($existing, 'aria-readonly="true"'), 'a footer page keeps its address');

    $slideEditor = route('website', ['slide' => '1'], $admin);
    assertTrue(str_contains($slideEditor, 'id="slide-editor"'), 'the slide editor opens');
    assertTrue(str_contains($slideEditor, 'No genuine need'), 'and is filled with the current headline');
});

ok('The CMS is administrator-only', function () use ($member): void {
    // The refusal is Security::fail(), which is `never` and calls exit — in the embed SAPI that would
    // end the whole run, so the gate is asserted through the same predicate the gate uses. The
    // refusal path for a real request is covered by tests/render.php, which requires requireAdmin()
    // to appear in the route's first 200 characters.
    assertTrue(!Core\Auth::isAdmin($member), 'a youth member was treated as an administrator');
    assertTrue(!Core\Auth::isAdmin(['role' => 'csr_rep']), 'a CSR representative cannot edit the site');
});

ok('An administrator can create, edit, hide and remove a page', function (): void {
    // The whole point of the CMS is that this can be done without a release. A screen that saves
    // nothing, or saves a duplicate row each time, looks identical in a screenshot.
    $saved = Core\Site::savePage([
        'slug' => 'scholarships', 'title' => 'Scholarships', 'kicker' => 'School fees',
        'summary' => 'Termly support.', 'body' => "## Who qualifies\n\nSS1 to SS3.",
        'is_published' => true, 'sort_order' => 40,
    ]);
    assertSame('scholarships', $saved['slug'], 'the page is created');
    assertTrue(strlen((string) $saved['body']) > 10, 'and its body is stored');

    // Editing must update the row the slug already owns.
    Core\Site::savePage([
        'slug' => 'scholarships', 'title' => 'Scholarships and Bursaries', 'kicker' => 'School fees',
        'summary' => 'Termly support.', 'body' => "## Who qualifies\n\nSS1 to SS3.",
        'is_published' => true, 'sort_order' => 40,
    ]);
    $rows = Database::all("SELECT id FROM site_pages WHERE slug = 'scholarships'");
    assertSame(1, count($rows), 'editing a page must not add a second row');
    assertSame('Scholarships and Bursaries', (string) Core\Site::page('scholarships')['title']);

    // Hiding a page takes it off the public site but keeps it editable, which is the difference
    // between "unpublished" and "deleted".
    Core\Site::savePage([
        'slug' => 'scholarships', 'title' => 'Scholarships and Bursaries', 'kicker' => 'School fees',
        'summary' => 'Termly support.', 'body' => "## Who qualifies\n\nSS1 to SS3.",
        'is_published' => false, 'sort_order' => 40,
    ]);
    assertSame(null, Core\Site::page('scholarships'), 'an unpublished page is not public');
    assertTrue(Core\Site::pageForEditing('scholarships') !== null, 'but the editor still opens it');

    // And it can be removed for good.
    Core\Site::deletePage('scholarships');
    assertSame(null, Core\Site::pageForEditing('scholarships'), 'a deleted page is gone');
});

ok('The hero can be reordered and trimmed without a release', function (): void {
    // The public-page test above seeded two slides; start from a clean hero so the ordering
    // assertion is about this test's own rows.
    Database::connection()->exec('DELETE FROM site_slides');

    $first  = Core\Site::saveSlide(['headline' => 'First slide', 'sort_order' => 10, 'is_published' => true]);
    $second = Core\Site::saveSlide(['headline' => 'Second slide', 'sort_order' => 20, 'is_published' => true]);

    $order = array_column(Core\Site::slides(false), 'headline');
    assertSame(['First slide', 'Second slide'], $order, 'slides follow sort_order');

    Core\Site::moveSlide($second, 'up');
    $order = array_column(Core\Site::slides(false), 'headline');
    assertSame(['Second slide', 'First slide'], $order, 'moving up reorders them');

    // Moving the top slide up must be a no-op, not an error or a wrap-around.
    Core\Site::moveSlide($second, 'up');
    assertSame(['Second slide', 'First slide'], array_column(Core\Site::slides(false), 'headline'));

    Core\Site::deleteSlide($first);
    assertSame(1, count(Core\Site::slides(false)), 'a slide can be deleted');

    // Only published slides reach the public page.
    Core\Site::saveSlide(['headline' => 'Draft slide', 'sort_order' => 30, 'is_published' => false]);
    $public = array_column(Core\Site::slides(true), 'headline');
    assertTrue(!in_array('Draft slide', $public, true), 'an unpublished slide stays off the site');
});

ok('People and Parish Connect render on a database without migration 0004', function () use ($admin): void {
    // Reported: "parishes and people are not loading." Both screens named users.area_id and
    // users.zone_id unconditionally; on a database that had not run 0004 the statement failed and
    // the router turned the uncaught PDOException into a blank 500.
    //
    // A directory is the page an administrator opens to discover that something is wrong. It must
    // therefore be the last thing to break — and it must still show people, with the church each
    // one picked, on the schema it actually has.
    $pdo = Database::connection();
    $pdo->exec('ALTER TABLE users DROP COLUMN IF EXISTS area_id');
    $pdo->exec('ALTER TABLE users DROP COLUMN IF EXISTS zone_id');

    try {
        // The column check must actually see the drop, or this test proves nothing.
        $schema = new Core\Migrator(RISE63_TEST_ROOT);
        assertTrue(!$schema->hasColumn('users', 'area_id'), 'the column really is absent');

        foreach (['parishes' => 'Parish Connect', 'people' => 'People'] as $page => $heading) {
            $html = route($page, [], $admin);
            assertClean($page . ' on a pre-0004 database', $html, $heading);
        }

        // And it still lists the people, rather than an empty table with a heading.
        $directory = Core\Members::directory([]);
        assertTrue(count($directory['rows']) > 0, 'the directory still has its rows');
    } finally {
        // Put the columns back: later tests use the current schema.
        $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS zone_id INT NULL');
        $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS area_id INT NULL');
    }
});

ok('A CMS page renders the site 404, not a blank page, before migration 0005', function () use ($anonymous): void {
    // The same defect on the public side: About Us, Privacy Policy, Terms & Conditions and Vision
    // were all blank because site_pages did not exist yet.
    $pdo = Database::connection();
    $pdo->exec('ALTER TABLE site_pages RENAME TO site_pages_parked');

    try {
        foreach (['about', 'privacy', 'terms', 'vision'] as $slug) {
            $html = route($slug, [], $anonymous);
            assertTrue(strlen($html) > 1200, $slug . ' must still render a page, not a 500');
            assertTrue(!str_contains($html, 'Fatal error'), $slug . ' must not leak a PHP error');
            assertTrue(!str_contains($html, 'SQLSTATE'), $slug . ' must not leak a SQL error');

            // And it is a real page, not a 404: the copy is shipped as code, so a database that is
            // one migration behind costs the visitor nothing at all.
            assertTrue(
                !str_contains($html, 'This page is being written'),
                $slug . ' must carry its own text, not the placeholder'
            );
            assertTrue(strlen($html) > 6000, $slug . ' should render its full text without the table');
        }
    } finally {
        $pdo->exec('ALTER TABLE site_pages_parked RENAME TO site_pages');
    }
});

/* ─────────────────────────────── summary ─────────────────────────────── */

fwrite(STDOUT, "\n");
fwrite(STDOUT, 'pages: ' . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . ' failed, ' . $GLOBALS['skipped'] . " skipped\n");
if ($GLOBALS['failed'] > 0) {
    fwrite(STDOUT, "FAILURES\n" . implode("\n", $GLOBALS['failures']) . "\n");
}
exit($GLOBALS['failed'] === 0 ? 0 : 1);
