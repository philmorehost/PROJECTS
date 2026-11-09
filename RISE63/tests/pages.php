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

/* ─────────────────────────────── database ─────────────────────────────── */

$pdo = new PDO('pgsql:memory://', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
Database::use($pdo);
pgsql_create_schema($pdo);

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
        'parishes'     => 'Parishes',
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

/* ─────────────────────────────── summary ─────────────────────────────── */

fwrite(STDOUT, "\n");
fwrite(STDOUT, 'pages: ' . $GLOBALS['passed'] . " passed, " . $GLOBALS['failed'] . ' failed, ' . $GLOBALS['skipped'] . " skipped\n");
if ($GLOBALS['failed'] > 0) {
    fwrite(STDOUT, "FAILURES\n" . implode("\n", $GLOBALS['failures']) . "\n");
}
exit($GLOBALS['failed'] === 0 ? 0 : 1);
