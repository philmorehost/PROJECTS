<?php
declare(strict_types=1);

/**
 * View render harness.
 *
 * Every view is rendered against fixed fixtures with a strict error handler, so an undefined
 * variable, a bad index or an unescaped value fails the run instead of appearing in production.
 * It also asserts the measurable parts of the UX contract (base type size, touch targets,
 * three-step tracker) and runs an XSS smoke test through user-controlled fields.
 *
 *   php tests/render.php
 *   node tests/support/phpwasm-render.mjs
 */

define('RISE63_TEST_ROOT', dirname(__DIR__));
define('RISE63_CONFIG_DIR', sys_get_temp_dir() . '/rise63-render-config');
@mkdir(RISE63_CONFIG_DIR, 0777, true);
putenv('RISE63_CONFIG_DIR=' . RISE63_CONFIG_DIR);

require RISE63_TEST_ROOT . '/app/bootstrap.php';

use RISE63\Core\Settings;
use RISE63\Core\View;

// Buffer everything: several views legitimately set headers (maintenance, gate, downloads)
// and the harness renders many pages in one process.
ob_start();

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['failures'] = [];

function ok(string $name, callable $test): void
{
    try {
        $test();
        $GLOBALS['pass']++;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $GLOBALS['fail']++;
        $GLOBALS['failures'][] = $name . ' :: ' . $error->getMessage();
        echo "FAIL {$name} :: " . $error->getMessage() . "\n";
    }
}

function assertTrue(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'missing “' . $needle . '”');
    }
}

function assertMissing(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'unexpected “' . $needle . '”');
    }
}

// Any PHP warning becomes a test failure.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

Settings::override([
    'site_title'                      => 'RISE 63',
    'site_tagline'                    => 'LP63 YAYA CSR & Young Professionals Club',
    'current_cycle_year'              => '2026',
    'theme_color'                     => '#2563eb',
    'seed_grant_monthly_pool'         => '100000.00',
    'seed_grant_max_per_application'  => '100000.00',
    'education_bridge_sla_hours'      => '72',
    'notification_email'              => 'ops@example.ng',
    'allow_self_registration'         => '1',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}

/** User-controlled strings carry an XSS probe on purpose. */
const XSS = '<script>alert("xss")</script>';

$admin = [
    'id' => 1, 'full_name' => 'Amaka Obi', 'email' => 'amaka@example.ng', 'role' => 'provincial_admin',
    'parish_id' => 3, 'phone' => '08031234567', 'theme_preference' => 'system', 'large_text' => 0,
    'login_notifications_enabled' => 1, 'transaction_pin_hash' => null, 'is_suspended' => 0,
    'successful_sessions_count' => 4, 'created_at' => '2026-10-01 09:00:00',
];
$member = ['id' => 2, 'full_name' => 'Chinedu Eze', 'email' => 'chinedu@example.ng', 'role' => 'youth_member',
    'parish_id' => 5, 'phone' => null, 'theme_preference' => 'dark', 'large_text' => 1,
    'login_notifications_enabled' => 1, 'transaction_pin_hash' => null, 'is_suspended' => 0];
$rep = ['id' => 3, 'full_name' => 'Grace Adeyemi', 'email' => 'grace@example.ng', 'role' => 'csr_rep',
    'parish_id' => 3, 'phone' => null, 'theme_preference' => 'light', 'large_text' => 0,
    'login_notifications_enabled' => 0, 'transaction_pin_hash' => null, 'is_suspended' => 0];

$application = [
    'id' => 12, 'user_id' => 2, 'parish_id' => 5, 'program_type' => 'education_bridge',
    'cycle_year' => 2026, 'education_level' => 'super_teen', 'school_name' => 'Grace College ' . XSS,
    'school_class' => 'SS2', 'requested_amount' => '75000.00', 'approved_amount' => '50000.00',
    'documents_json' => json_encode([['name' => 'invoice.pdf', 'stored' => str_repeat('a', 32) . '-invoice.pdf', 'size' => 204800, 'type' => 'school fee invoice']]),
    'answers_json' => json_encode(['fee_due' => '75000.00', 'session_year' => '2025/2026', 'guardian_phone' => '08030000000']),
    'status' => 'documents_requested', 'decision_note' => 'Upload the stamped invoice ' . XSS,
    'idempotency_key' => null, 'submitted_at' => '2026-11-01 08:30:00', 'verified_at' => null,
    'approved_at' => null, 'disbursed_at' => null,
    'created_at' => '2026-11-01 08:30:00', 'updated_at' => '2026-11-05 10:00:00',
    'full_name' => 'Chinedu Eze', 'email' => 'chinedu@example.ng', 'parish_name' => 'Living Water ' . XSS,
];

$parish = [
    'id' => 5, 'area_id' => 2, 'parish_name' => 'Living Water ' . XSS, 'parish_code' => 'LP63-005',
    'worship_address' => '12 Marina Road', 'yaya_rep_name' => 'Grace Adeyemi', 'yaya_rep_phone' => '08030000000',
    'youth_count' => 148, 'needs_summary' => 'Transport support for evening classes.', 'is_active' => 1,
    'area_name' => 'Hope Area', 'zone_name' => 'Grace Zone', 'zone_id' => 1,
];

$base = [
    'title' => 'Test page',
    'flashes' => [['message' => 'Saved.', 'type' => 'success']],
    'page' => 'test',
];

/**
 * The parts of the People screen that are not the roster itself: filters, counts, the parish picker
 * and the pager. Shared so each of the three people-related tests does not restate them — and so a
 * change to the view's contract fails in one place rather than three.
 */
$peopleFixture = [
    'term' => '', 'roleFilter' => '', 'statusFilter' => '',
    'counts' => ['total' => 12, 'suspended' => 1, 'temporary' => 2, 'admins' => 2, 'treasurers' => 1, 'reps' => 3, 'members' => 6],
    'parishes' => [['id' => 5, 'parish_name' => 'LIVING WATER PARISH']],
    'editing' => null,
    'pager' => RISE63\Core\Paginator::fromState(20, null, 2, ['per_page' => '20'], 'people', true),
];

$renderPage = static function (string $view, array $data, ?array $account = null) use ($base): string {
    $payload = $data + $base;
    $payload['account'] = $account;
    $payload['user'] = $account;
    return View::capture(RISE63_TEST_ROOT . '/app/Views/' . $view . '.php', $payload, true);
};

/* ───────────────────────────── Standalone screens ──────────────────────────── */

ok('Login screen renders with a form, CSRF and no inline script', function (): void {
    $html = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/login.php', ['flashes' => [], 'page' => 'login'], false);
    assertContains('<!doctype html>', $html);
    assertContains('name="action" value="login"', $html);
    assertContains('type="email"', $html);
    assertContains('value="' . RISE63\Core\Security::csrf() . '"', $html);
    assertMissing('<script>', $html);
    assertContains('rel="manifest"', $html);
});

ok('Password help adapts to whether mail is configured', function (): void {
    $withMail = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/forgot-password.php',
        ['title' => 'Password help', 'sent' => false, 'canEmail' => true], false);
    assertContains('password-reset-request', $withMail);
    assertContains('Send reset link', $withMail);
    assertMissing('province office so that every change', $withMail);

    $noMail = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/forgot-password.php',
        ['title' => 'Password help', 'sent' => false, 'canEmail' => false], false);
    assertMissing('password-reset-request', $noMail, 'must not offer a link that cannot be delivered');
    assertContains('Contact your parish CSR representative', $noMail);

    // The confirmation never reveals whether the address has an account.
    $sent = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/forgot-password.php',
        ['title' => 'Password help', 'sent' => true, 'canEmail' => true], false);
    assertContains('If that address belongs to a RISE 63 account', $sent);
});

ok('Reset and change-password screens never echo the token into visible text', function (): void {
    $token = str_repeat('a', 43);
    $reset = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/reset-password.php',
        ['title' => 'Choose a new password', 'valid' => true, 'token' => $token], false);
    assertContains('name="token" value="' . $token . '"', $reset);
    assertContains('autocomplete="new-password"', $reset);
    assertContains('maxlength="200"', $reset);

    $expired = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/reset-password.php',
        ['title' => 'Reset link expired', 'valid' => false, 'token' => ''], false);
    assertMissing($token, $expired);
    assertContains('Request a new link', $expired);

    $forced = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/change-password.php',
        ['title' => 'Choose a new password', 'forced' => true, 'account' => ['theme_preference' => 'dark']], false);
    assertContains('Set your own password to continue', $forced);
    assertMissing('Cancel', $forced, 'a forced change must not offer an escape hatch');
    assertContains('data-theme="dark"', $forced);
});

ok('Registration screen exposes a parish combobox with a noscript fallback', function (): void {
    $html = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/register.php', ['flashes' => [], 'page' => 'register'], false);
    assertContains('role="combobox"', $html);
    assertContains('<noscript>', $html);
    assertContains('name="parish_id"', $html);
    assertContains('data-endpoint=', $html);
});

ok('The parish list is in the HTML, not only behind a fetch', function (): void {
    // The field report: "the registration page did not list the churches". The picker fetched the
    // list, so a broken endpoint, a policy block or an exception left an empty box that looked
    // exactly like "your church is not listed". The list is now rendered by PHP.
    $parishes = [
        ['id' => 5, 'parish' => 'LIVING WATER PARISH', 'area' => 'HOPE AREA', 'zone' => 'GRACE ZONE',
         'label' => 'LIVING WATER PARISH · HOPE AREA · GRACE ZONE'],
        ['id' => 9, 'parish' => 'GOOD SHEPHERD PARISH', 'area' => 'FAITH AREA', 'zone' => 'GRACE ZONE',
         'label' => 'GOOD SHEPHERD PARISH · FAITH AREA · GRACE ZONE'],
    ];

    $html = View::capture(
        RISE63_TEST_ROOT . '/app/Views/auth/register.php',
        ['flashes' => [], 'page' => 'register', 'parishes' => $parishes],
        false
    );

    assertContains('LIVING WATER PARISH', $html, 'a church in the directory must be on the page');
    assertContains('GOOD SHEPHERD PARISH', $html);
    assertContains('data-parish-pick', $html);
    assertContains('<datalist id="parish-names">', $html, 'the native autocomplete list must exist');
    assertContains('data-label="LIVING WATER PARISH · HOPE AREA · GRACE ZONE"', $html);
    assertContains('2 churches', $html, 'the count tells the member the list is populated');

    // The select must carry its own name attribute path to the server: the visible picker is an
    // enhancement, so the plain <select> has to submit by itself when nothing else runs.
    preg_match('#<select id="parish-pick"([^>]*)>#', $html, $selectTag);
    assertTrue($selectTag !== [], 'the select must be rendered');

    // An empty directory must say so rather than render an empty box.
    $empty = View::capture(
        RISE63_TEST_ROOT . '/app/Views/auth/register.php',
        ['flashes' => [], 'page' => 'register', 'parishes' => []],
        false
    );
    assertMissing('<datalist', $empty, 'no datalist when there is nothing to list');
    assertContains('The directory is empty', $empty);
    assertContains('My church is not in the list', $empty, 'the manual route always exists');
});

ok('Registration offers a church that is not listed yet, and types it in capitals', function (): void {
    $html = View::capture(RISE63_TEST_ROOT . '/app/Views/auth/register.php', ['flashes' => [], 'page' => 'register'], false);

    // The manual path must be reachable without JavaScript: a <details> block the member can open
    // by hand if the picker never loads, not a control that only app.js can reveal.
    assertContains('id="parish-manual"', $html);
    assertContains('<details', $html);
    assertContains('My church is not in the list', $html);
    assertContains('name="parish_name_raw"', $html, 'the typed name must reach the server');
    assertContains('data-caps', $html, 'the field must capitalise as it is typed');
    assertContains('autocapitalize="characters"', $html, 'phones should start in capitals too');

    // Exactly one field may carry the parish id. The hidden input is it; the server-rendered
    // <select> sits beside it unnamed and mirrors its choice into the hidden field, so a browser
    // without JavaScript gets the hidden field as a static empty value — never two conflicting
    // parish_id parameters where whichever the browser sends last would silently win.
    assertMissing('name="parish-search"', $html);
    assertSame(1, substr_count($html, 'name="parish_id"'), 'only one field may submit the parish id');
    assertContains('id="parish-id-field"', $html);
    assertMissing('id="parish_id_fallback"', $html, 'the old duplicate select must be gone');
});

ok('The parish names in every admin form are upper-cased at the field', function () use ($renderPage, $admin, $parish): void {
    $directory = $renderPage('staff/parishes', [
        'parishes' => [],
        'term'     => '',
        'zones'    => [],
        'areas'    => [],
        'totals'   => ['zones' => 0, 'areas' => 0, 'parishes' => 0, 'youth' => 0],
        'canEdit'  => true,
    ], $admin);
    assertContains('id="parish_name"', $directory);
    assertContains('data-caps', $directory, 'the single-parish form must capitalise too');

    $detail = $renderPage('staff/parish', [
        'parish'  => $parish,
        'areas'   => [['id' => 2, 'area_name' => 'HOPE AREA', 'zone_name' => 'GRACE ZONE']],
        'members' => [],
        'apps'    => [],
    ], $admin);
    assertContains('data-caps', $detail);
});

ok('The bulk paste box explains its own format and previews before writing', function () use ($renderPage, $admin): void {
    $empty = $renderPage('staff/parishes', [
        'parishes' => [], 'term' => '', 'zones' => [], 'areas' => [],
        'totals' => ['zones' => 0, 'areas' => 0, 'parishes' => 0, 'youth' => 0], 'canEdit' => true,
    ], $admin);

    assertContains('name="hierarchy"', $empty);
    assertContains('action" value="directory-import"', $empty);
    assertContains('value="preview"', $empty);
    assertContains('value="apply"', $empty);
    // The three shapes the operator is told to use, spelled out on the page.
    assertContains('ZONE, AREA, PARISH', $empty);
    assertContains('ZONE, AREA', $empty);
    assertMissing('Preview — nothing has been saved yet', $empty, 'no preview before there is a paste');

    // With a parked preview the text comes back into the box and the counts are shown, so the
    // operator can compare them with the sheet before pressing the second button.
    $previewing = $renderPage('staff/parishes', [
        'parishes' => [], 'term' => '', 'zones' => [], 'areas' => [],
        'totals' => ['zones' => 0, 'areas' => 0, 'parishes' => 0, 'youth' => 0], 'canEdit' => true,
        'importText' => "GRACE ZONE, HOPE AREA, LIVING WATER PARISH",
        'importPreview' => [
            'zones' => ['GRACE ZONE'], 'areas' => [['zone' => 'GRACE ZONE', 'name' => 'HOPE AREA']],
            'parishes' => [['zone' => 'GRACE ZONE', 'area' => 'HOPE AREA', 'name' => 'LIVING WATER PARISH']],
            'new_zones' => 1, 'new_areas' => 1, 'new_parishes' => 1,
            'existing_zones' => 0, 'existing_areas' => 0, 'existing_parishes' => 0, 'duplicates' => 0,
        ],
        'importSkipped' => [['number' => 4, 'raw' => 'A, B, C, D', 'reason' => 'More than three columns.']],
    ], $admin);

    assertContains('Preview — nothing has been saved yet', $previewing);
    assertContains('LIVING WATER PARISH', $previewing, 'the pasted text is still in the box');
    assertContains('New zones', $previewing);
    assertContains('New parishes', $previewing);
    assertContains('Line 4', $previewing, 'a skipped line must be named by its number');
    assertContains('More than three columns.', $previewing);
});

ok('A member can name a church that is not in the directory, and admins see the queue', function () use ($renderPage, $admin, $member, $peopleFixture): void {
    $profile = $renderPage('member/profile', [
        'devices' => [], 'pinLock' => 0, 'parish' => null,
    ], $member);
    assertContains('name="parish_name_raw"', $profile);
    assertContains('data-caps', $profile);

    // "Add it later" is only honest if somebody is told what to add.
    $people = $renderPage('staff/people', array_merge($peopleFixture, [
        'people' => [],
        'unlisted' => [['parish_name_raw' => 'CHAPEL OF WONDERS PARISH', 'member_count' => 3]],
    ]), $admin);
    assertContains('Churches members asked for', $people);
    assertContains('CHAPEL OF WONDERS PARISH', $people);
    assertContains('3 member(s)', $people);

    $nothing = $renderPage('staff/people', array_merge($peopleFixture, ['people' => [], 'unlisted' => []]), $admin);
    assertMissing('Churches members asked for', $nothing, 'an empty queue is not a section');
});

ok('Impersonation shows a banner that cannot be confused with a normal session', function () use ($renderPage, $member): void {
    // A page rendered for the member, while the administrator's session is remembered.
    $_SESSION['rise63_impersonator'] = ['id' => 1, 'name' => 'Amaka Obi', 'email' => 'amaka@example.ng', 'at' => '2026-11-08T09:00:00+00:00'];
    $home = [
        'applications' => [],
        'summary' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'disbursed' => 0, 'rejected' => 0, 'requested' => 0],
        'eligibility' => [
            'academy' => ['label' => 'RISE Academy', 'available' => true, 'reason' => null],
            'seed_grant' => ['label' => 'Seed Grant', 'available' => false, 'reason' => 'Academy graduation required'],
        ],
    ];
    $html = $renderPage('member/home', $home, $member);
    unset($_SESSION['rise63_impersonator']);

    assertContains('impersonation-bar', $html);
    assertContains('started by Amaka Obi', $html);
    assertContains('value="user-login-as-stop"', $html, 'and a way back out');
    assertContains('Return to my account', $html);

    // Without the session flag there is no banner at all.
    $plain = $renderPage('member/home', $home, $member);
    assertMissing('impersonation-bar', $plain, 'a normal session never shows the banner');
});

ok('Error, maintenance and gate screens are self-contained', function (): void {
    $error = View::capture(RISE63_TEST_ROOT . '/app/Views/error.php', ['title' => 'Temporary problem', 'message' => XSS], false);
    assertMissing('<script>alert', $error, 'message must be escaped');

    // These two call http_response_code()/header(); buffer each render on its own so the
    // harness never trips "headers already sent", and drop their headers afterwards.
    ob_start();
    $maintenance = View::capture(RISE63_TEST_ROOT . '/app/Views/maintenance.php', [], false);
    ob_end_clean();
    header_remove();
    assertContains('Back shortly', $maintenance);

    ob_start();
    $gate = View::capture(RISE63_TEST_ROOT . '/app/Views/system-gate.php',
        ['activationState' => ['message' => 'Not activated', 'domain' => 'buildroom.example.org'], 'domain' => 'buildroom.example.org'], false);
    // The gate must tell the operator what to do, not just that something is wrong.
    assertContains('Request a licence for this domain', $gate);
    assertContains('manager.pmhserver.name.ng/order.php', $gate);
    assertContains('config/activation-key.php', $gate);
    assertContains('buildroom.example.org', $gate);
    assertMissing('system-token.php', $gate, 'the old token file must no longer be referenced');
    ob_end_clean();
    header_remove();
    assertContains('Activation required', $gate);
});

ok('Every form action in every view has a handler', function (): void {
    // "I press the button and nothing happens" is almost always a form posting an action string the
    // router does not have. Checking it statically costs nothing and covers forms no test renders.
    $handlers = [];
    $actions  = (string) file_get_contents(RISE63_TEST_ROOT . '/app/actions.php');
    preg_match_all("/case '([a-z0-9-]+)':/", $actions, $m);
    foreach ($m[1] as $name) {
        $handlers[$name] = true;
    }
    // Handled before the POST router, by index.php.
    $handlers['activation-retry'] = true;

    $missing = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(RISE63_TEST_ROOT . '/app/Views', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!str_ends_with($file->getPathname(), '.php')) {
            continue;
        }
        $html = (string) file_get_contents($file->getPathname());
        preg_match_all('/name="action"\s+value="([a-z0-9-]+)"/', $html, $found);
        foreach ($found[1] as $name) {
            if (!isset($handlers[$name])) {
                $missing[] = basename($file->getPathname()) . ' → ' . $name;
            }
        }
    }
    assertSame([], $missing, 'unhandled form actions: ' . implode(', ', $missing));
});

ok('The pages that crashed in production build their paginator through the factory', function (): void {
    // Two faults shipped in 1.0.1 and took /settings, /updates and /people down with a 500:
    //
    //   1. Audit::page() and Members::directory() called `new Paginator(...)`, but the constructor
    //      is private so every page is built through a validating factory. Every load of Settings
    //      and People died before rendering.
    //   2. Both reused one named placeholder in three positions. With ATTR_EMULATE_PREPARES = false
    //      PDO sends that as a native prepare, which binds positionally, so the statement fails with
    //      "Invalid parameter number" — a second 500 behind the first.
    //
    // The render tests could not catch either: they constructed the paginator by hand and never
    // executed the SQL. This test reads the source and runs the real calls against a PDO stub that
    // fails the way MySQL does. It costs nothing and it would have stopped the deploy.
    foreach ([RISE63\Core\Audit::class, RISE63\Core\Members::class] as $class) {
        $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        assertMissing('new Paginator(', $source, $class . ' must use Paginator::fromState()');
    }

    // Every SQL literal in the codebase binds each marker exactly once.
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(RISE63_TEST_ROOT . '/app', FilesystemIterator::SKIP_DOTS)
    );
    $reused = [];
    foreach ($files as $file) {
        if (!str_ends_with($file->getPathname(), '.php')) {
            continue;
        }
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            preg_match_all('/(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)/', $token[1], $m);
            $counts = array_count_values($m[1]);
            foreach ($counts as $name => $count) {
                if ($count > 1) {
                    $reused[] = basename($file->getPathname()) . ' :' . $name;
                }
            }
        }
    }
    assertSame([], $reused, 'a named placeholder bound once per occurrence: ' . implode(', ', $reused));
});

/* ─────────────────────────────── Member views ──────────────────────────────── */

ok('Member home shows the empty state and program cards', function () use ($renderPage, $member): void {
    $html = $renderPage('member/home', [
        'applications' => [],
        'summary' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'disbursed' => 0, 'rejected' => 0, 'requested' => 0],
        'eligibility' => [
            'academy' => ['label' => 'RISE Academy', 'available' => true, 'reason' => null],
            'seed_grant' => ['label' => 'Seed Grant', 'available' => false, 'reason' => 'Academy graduation required'],
        ],
    ], $member);
    assertContains('Start with a program', $html);
    assertContains('Academy graduation required', $html);
    assertContains('bottom-nav', $html, 'signed-in chrome');
    // The tab bar is drawn SVG, indexed by position, and the active tab is marked for CSS.
    assertContains('class="nav-indicator"', $html, 'sliding active pill');
    assertContains('--tab-count:', $html, 'tab count drives the pill width');
    assertContains('--active-index:', $html, 'active tab drives the pill position');
    assertContains('<svg class="icon"', $html, 'icons are drawn, not text glyphs');
    assertContains('nav-logout', $html, 'sign out stays a POST with a CSRF token');
});

ok('Member home lists applications with the three-step tracker', function () use ($renderPage, $member, $application): void {
    $html = $renderPage('member/home', [
        'applications' => [$application],
        'summary' => ['total' => 1, 'pending' => 1, 'approved' => 0, 'disbursed' => 0, 'rejected' => 0, 'requested' => 7500000],
        'eligibility' => [],
    ], $member);
    assertContains('class="tracker"', $html);
    assertContains('Step 2 of 3', $html);
    assertContains('Documents requested', $html);
    assertContains('₦75,000.00', $html);
});

ok('Application detail escapes user data and renders history', function () use ($renderPage, $member, $application): void {
    $html = $renderPage('member/application', [
        'application' => $application,
        'events' => [
            ['to_status' => 'submitted', 'note' => 'Application submitted.', 'created_at' => '2026-11-01 08:30:00', 'actor_name' => 'Chinedu Eze'],
            ['to_status' => 'documents_requested', 'note' => 'Invoice needed', 'created_at' => '2026-11-05 10:00:00', 'actor_name' => 'Amaka Obi'],
        ],
        'answers' => ['fee_due' => '75000.00', 'session_year' => '2025/2026', 'guardian_phone' => '08030000000'],
        'documents' => [['name' => 'invoice.pdf', 'stored' => str_repeat('a', 32) . '-invoice.pdf', 'size' => 204800, 'type' => 'school fee invoice']],
        'tracker' => RISE63\Core\Program::tracker('education_bridge', 'documents_requested', '2026-11-05 10:00:00'),
        'canReview' => false,
    ], $member);
    assertMissing(XSS, $html, 'XSS probe must be escaped');
    assertContains('&lt;script&gt;', $html);
    assertContains('class="timeline"', $html);
    assertContains('invoice.pdf', $html);
    assertMissing('Review decision', $html, 'members never see the reviewer form');
    assertContains('Withdraw application', $html);
});

ok('Reviewer sees the decision form with only legal next steps', function () use ($renderPage, $admin, $application): void {
    $html = $renderPage('member/application', [
        'application' => $application,
        'events' => [],
        'answers' => [],
        'documents' => [],
        'tracker' => RISE63\Core\Program::tracker('education_bridge', 'documents_requested'),
        'canReview' => true,
    ], $admin);
    assertContains('Review decision', $html);
    assertContains('value="under_review"', $html, 'return to review is legal');
    assertContains('value="rejected"', $html);
    assertMissing('value="disbursed"', $html, 'disbursement is not a reviewer action');
});

ok('Apply form adapts to the program', function () use ($renderPage, $member): void {
    $edu = $renderPage('member/apply', [
        'programType' => 'education_bridge',
        'eligibility' => [],
        'questions' => RISE63\Core\Program::questions('education_bridge'),
        'maxGrant' => RISE63\Core\Money::fromNaira('100000'),
    ], $member);
    assertContains('Level of study', $edu);
    assertContains('data-autosave', $edu);
    assertContains('name="idempotency_key"', $edu);

    assertContains('enctype="multipart/form-data"', $edu, 'the optional attachment needs a multipart form');

    $academy = $renderPage('member/apply', [
        'programType' => 'academy',
        'eligibility' => [],
        'questions' => RISE63\Core\Program::questions('academy'),
        'maxGrant' => RISE63\Core\Money::fromNaira('100000'),
    ], $member);
    assertContains('RISE Academy is free', $academy);
    assertMissing('Level of study', $academy);
});

ok('Profile exposes preferences, PIN and device controls by role', function () use ($renderPage, $member, $admin, $parish): void {
    $members = $renderPage('member/profile', [
        'devices' => [['user_agent' => 'Mozilla/5.0 (Linux; Android 13)', 'ip_address' => '102.89.1.1', 'last_seen_at' => '2026-11-08 10:00:00', 'created_at' => '2026-11-01 09:00:00']],
        'parish' => $parish,
        'pinLock' => 0,
    ], $member);
    assertMissing('Transaction PIN', $members, 'members have no disbursement PIN');
    assertContains('Larger text', $members);
    assertContains('Sign out other devices', $members);

    $treasurerView = $renderPage('member/profile', ['devices' => [], 'parish' => null, 'pinLock' => 600], $admin);
    assertContains('Transaction PIN', $treasurerView);
    assertContains('PIN locked', $treasurerView);
});

/* ──────────────────────────────── Staff views ──────────────────────────────── */

ok('Staff dashboard renders live figures and the security strip', function () use ($renderPage, $admin, $application): void {
    $html = $renderPage('staff/dashboard', [
        'summary' => ['total' => 24, 'pending' => 7, 'approved' => 3, 'disbursed' => 2, 'rejected' => 1, 'requested' => 250000000],
        'totals' => ['zones' => 3, 'areas' => 9, 'parishes' => 42, 'youth' => 3200],
        'ledger' => ['disbursed' => RISE63\Core\Money::fromNaira('250000'), 'pending' => RISE63\Core\Money::fromNaira('50000'), 'pool' => RISE63\Core\Money::fromNaira('100000')],
        'recent' => [$application],
        'security' => [['event_type' => 'failed_login', 'ip_address' => '102.89.1.1', 'created_at' => '2026-11-08 09:00:00', 'username_attempted' => 'x@example.ng']],
        'attention' => 7,
        'slaBreach' => 2,
    ], $admin);
    assertContains('Dashboard', $html);
    assertContains('past the service window', $html);
    assertMissing(XSS, $html);
});

ok('Applications queue filters and stacks on mobile', function () use ($renderPage, $admin, $application): void {
    $html = $renderPage('staff/applications', [
        'applications' => [$application],
        'filterType' => 'education_bridge',
        'filterStatus' => 'documents_requested',
        'programs' => RISE63\Core\Program::TYPES,
        'statuses' => RISE63\Core\Program::STATUSES,
    ], $admin);
    assertContains('table-stack', $html);
    assertContains('data-label="Applicant"', $html);
    assertContains('selected', $html, 'active filters stay selected');
});

ok('Parish directory screen renders the search combobox and add form', function () use ($renderPage, $admin, $parish): void {
    $html = $renderPage('staff/parishes', [
        'parishes' => [['id' => 5, 'parish_name' => $parish['parish_name'], 'area_name' => 'Hope Area', 'zone_name' => 'Grace Zone',
                        'parish_code' => 'LP63-005', 'yaya_rep_name' => 'Grace', 'yaya_rep_phone' => '0803', 'youth_count' => 148,
                        'account_count' => 4, 'is_active' => 1]],
        'term' => '',
        'zones' => [['id' => 1, 'zone_name' => 'Grace Zone']],
        'areas' => [['id' => 2, 'area_name' => 'Hope Area', 'zone_name' => 'Grace Zone']],
        'totals' => ['zones' => 1, 'areas' => 1, 'parishes' => 1, 'youth' => 148],
        'canEdit' => true,
    ], $admin);
    assertContains('data-combobox', $html);
    assertContains('Create zone', $html);
    assertMissing(XSS, $html);
});

ok('Parish detail hides admin-only controls from representatives', function () use ($renderPage, $admin, $rep, $parish): void {
    $adminView = $renderPage('staff/parish', ['parish' => $parish, 'areas' => [['id' => 2, 'area_name' => 'Hope Area', 'zone_name' => 'Grace Zone']], 'members' => [], 'apps' => []], $admin);
    assertContains('Parish is active in the directory', $adminView);
    $repView = $renderPage('staff/parish', ['parish' => $parish, 'areas' => [['id' => 2, 'area_name' => 'Hope Area', 'zone_name' => 'Grace Zone']], 'members' => [], 'apps' => []], $rep);
    assertMissing('Parish is active in the directory', $repView);
    assertContains('Save parish', $repView);
});

ok('Treasury screen shows pending payouts, PIN entry and the ledger', function () use ($renderPage, $admin): void {
    $html = $renderPage('staff/treasury', [
        'pending' => [[
            'id' => 88, 'amount' => '50000.00', 'payee_name' => 'Grace College', 'bank_name' => 'GTBank',
            'account_number' => '0123456789', 'payment_reference' => 'RS63-ABCD-261105', 'status' => 'pending',
            'applicant' => 'Chinedu Eze', 'program_type' => 'education_bridge', 'cycle_year' => 2026,
            'parish_name' => 'Living Water', 'created_at' => '2026-11-05 10:00:00',
        ]],
        'entries' => [[
            'id' => 1, 'entry_type' => 'disbursement', 'program_type' => 'seed_grant', 'amount' => '100000.00',
            'balance_after' => '0.00', 'reference' => 'LED-RS63-AAAA-261101', 'description' => null,
            'created_by' => 1, 'created_at' => '2026-11-01 09:00:00', 'actor_name' => 'Amaka Obi', 'application_id' => 9,
        ]],
        'totals' => ['disbursed' => RISE63\Core\Money::fromNaira('100000'), 'pending' => RISE63\Core\Money::fromNaira('50000'), 'pool' => RISE63\Core\Money::zero()],
        'pinLock' => 0,
        'cycleYear' => 2026,
        'funds' => [[
            'program' => 'seed_grant',
            'label' => 'Seed Grants',
            'balance' => [
                'opening' => RISE63\Core\Money::fromNaira('100000'), 'deposited' => RISE63\Core\Money::zero(),
                'adjusted' => RISE63\Core\Money::zero(), 'disbursed' => RISE63\Core\Money::fromNaira('100000'),
                'committed' => RISE63\Core\Money::fromNaira('100000'),
                'remaining' => RISE63\Core\Money::zero(), 'funded' => true, 'source' => 'set',
            ],
            'configured' => RISE63\Core\Money::fromNaira('100000'),
        ]],
        'poolEntries' => [[
            'id' => 5, 'action' => 'ledger.set_pool', 'entity' => 'fund', 'entity_id' => 'seed_grant',
            'actor_email' => 'amaka@example.ng', 'actor_name' => 'Amaka Obi',
            'created_at' => '2026-11-01 09:00:00',
            'summary' => 'Seed Grants pool for 2026 set to ₦100,000.00 (Provincial board allocation)',
        ]],
        'approved' => [[
            'id' => 9, 'full_name' => 'Chinedu Eze', 'program_type' => 'seed_grant', 'requested_amount' => '100000.00', 'status' => 'approved',
        ]],
    ], $admin);
    assertContains('Confirm payment', $html);
    assertContains('type="password"', $html);
    assertContains('Append-only', $html);
    assertContains('LED-RS63-AAAA-261101', $html);

    // "Where does the admin set the Seed Grant pool left?" — here, next to the ledger it governs.
    assertContains('id="funds"', $html, 'the fund panel is linkable from the dashboard');
    assertContains('Set the Seed Grant pool', $html);
    assertContains('value="fund-set-pool"', $html);
    assertContains('value="fund-deposit"', $html, 'money received is recorded as its own event');
    assertContains('Pool decisions', $html);
    assertContains('Pool = opening commitment + deposits + adjustments − disbursed', $html);
    // The restated-to figure appears, and is attributed.
    assertContains('Provincial board allocation', $html);
    assertContains('Amaka Obi', $html);
});

ok('People screen offers role changes and suspension', function () use ($renderPage, $admin, $peopleFixture): void {
    $html = $renderPage('staff/people', array_merge($peopleFixture, [
        'people' => [
            ['id' => 2, 'full_name' => 'Chinedu Eze', 'email' => 'chinedu@example.ng', 'role' => 'youth_member',
             'parish_name' => 'Living Water', 'last_login_at' => null, 'is_suspended' => 0],
            ['id' => 1, 'full_name' => 'Amaka Obi', 'email' => 'amaka@example.ng', 'role' => 'provincial_admin',
             'parish_name' => null, 'last_login_at' => '2026-11-08 08:00:00', 'is_suspended' => 0],
        ],
    ]), $admin);
    assertContains('Suspend', $html);
    assertContains('Never', $html, 'accounts that never signed in');
    assertContains('Temporary password', $html);
});

ok('People screen supports the whole member lifecycle from the admin side', function () use ($renderPage, $admin, $peopleFixture): void {
    $html = $renderPage('staff/people', array_merge($peopleFixture, [
        'people' => [
            ['id' => 2, 'full_name' => 'Chinedu Eze', 'email' => 'chinedu@example.ng', 'role' => 'youth_member',
             'parish_name' => 'Living Water', 'last_login_at' => null, 'is_suspended' => 0],
            ['id' => 1, 'full_name' => 'Amaka Obi', 'email' => 'amaka@example.ng', 'role' => 'provincial_admin',
             'parish_name' => null, 'last_login_at' => '2026-11-08 08:00:00', 'is_suspended' => 0],
        ],
    ]), $admin);

    // Create, including a church typed by hand and an optional password (one is generated).
    assertContains('value="user-create"', $html);
    assertContains('name="parish_name_raw"', $html, 'an unlisted church can be typed at creation');
    assertContains('Leave blank and one is generated', $html);

    // Edit: a link on every row, and the form itself when an account is opened.
    assertContains('Edit', $html);
    $editing = $renderPage('staff/people', array_merge($peopleFixture, [
        'people' => [],
        'editing' => ['id' => 2, 'full_name' => 'Chinedu Eze', 'email' => 'chinedu@example.ng',
                      'phone' => '08030000000', 'role' => 'youth_member', 'parish_id' => null,
                      'parish_name_raw' => 'CHAPEL OF WONDERS PARISH', 'created_at' => '2026-10-01 09:00:00'],
    ]), $admin);
    assertContains('value="user-update"', $editing);
    assertContains('Edit Chinedu Eze', $editing);
    assertContains('name="phone"', $editing, 'the editing phone field is present');
    // The view closes the quote with a typographic mark; the assertion spells it out so this
    // cannot silently pass on a curly-vs-straight substitution.
    assertContains('CHAPEL OF WONDERS PARISH' . "\u{201D}" . ', which is not in the directory', $editing);

    // Delete, with a confirmation that says it cannot be undone.
    assertContains('value="user-delete"', $html);
    assertContains('cannot be undone', $html);

    // Login as, only for the other (non-admin) person.
    assertContains('value="user-login-as"', $html);

    // A second administrator.
    assertContains('value="user-admin-create"', $html);
    assertContains('Create another administrator', $html);
    assertContains('one administrator cannot act invisibly', $html);

    // The row for yourself offers no suspend/delete — an admin cannot lock themselves out.
    assertContains('badge-info">you', $html);
    assertContains('Create an account', $html);

    // Filters and paging.
    assertContains('name="status"', $html);
    assertContains('name="role"', $html);
    assertContains('Per page', $html);
});

ok('Updates screen renders for an administrator and says what it is doing', function () use ($renderPage, $admin): void {
    // Never rendered in a test before this, which is how a page can be linked from the header and
    // still be broken. It reads only from disk and from a previous check.
    $html = $renderPage('staff/updates', [
        'currentVersion' => '1.0.1',
        'check' => null,
        'plan' => null,
        'result' => null,
        'history' => [],
    ], $admin);
    assertContains('Updates', $html);
    assertContains('1.0.1', $html, 'the installed version is stated');
    assertContains('value="update-check"', $html, 'there is a way to check');
});

ok('Activity report accounts for every administrator and member', function () use ($renderPage, $admin): void {
    $html = $renderPage('staff/audit', [
        'summary' => [
            ['actor_id' => 1, 'actor_name' => 'Amaka Obi', 'actor_email' => 'amaka@example.ng',
             'actor_role' => 'provincial_admin', 'event_count' => 42, 'money_count' => 9,
             'account_count' => 5, 'last_seen' => '2026-11-08 09:00:00'],
            ['actor_id' => 2, 'actor_name' => 'Chinedu Eze', 'actor_email' => 'chinedu@example.ng',
             'actor_role' => 'youth_member', 'event_count' => 3, 'money_count' => 1,
             'account_count' => 0, 'last_seen' => '2026-11-07 19:00:00'],
        ],
        'events' => [[
            'id' => 900, 'created_at' => '2026-11-08 09:00:00', 'actor_email' => 'amaka@example.ng',
            'actor_name' => 'Amaka Obi', 'actor_role' => 'provincial_admin', 'actor_suspended' => 0,
            'action' => 'ledger.set_pool', 'entity' => 'fund', 'entity_id' => 'seed_grant',
            'summary' => 'Seed Grants pool for 2026 set to ₦600,000.00',
        ]],
        'pager' => RISE63\Core\Paginator::fromState(25, null, 1, ['per_page' => '25'], 'audit', true),
        'filters' => ['actor' => '', 'action' => '', 'entity' => '', 'kind' => '', 'q' => ''],
        'actors' => [['id' => 1, 'full_name' => 'Amaka Obi', 'email' => 'amaka@example.ng', 'role' => 'provincial_admin']],
        'actions' => ['ledger.set_pool', 'user.role'],
        'total' => 1842, 'financial' => 214, 'adminEvents' => 645, 'cycleYear' => 2026,
    ], $admin);

    assertContains('Activity report', $html);
    assertContains('Who has been doing what', $html);
    assertContains('1842', $html, 'the total is stated');
    assertContains('645', $html, 'administrator actions are counted separately');
    // Members appear alongside administrators, not only staff.
    assertContains('Chinedu Eze', $html);
    assertContains('Youth Member', $html, 'the role is shown in words, not the raw enum');
    // Each person can be drilled into.
    assertContains('Their events', $html);
    assertContains('ledger.set_pool', $html);
    // And the log itself pages.
    assertContains('Older', $html);
});

ok('Security centre renders policy, blocks, countries and the log', function () use ($renderPage, $admin): void {
    $html = $renderPage('staff/security', [
        'logs' => [['created_at' => '2026-11-08 09:00:00', 'event_type' => 'ip_blocked', 'ip_address' => '102.89.1.1',
                    'country_code' => 'NG', 'username_attempted' => 'x@example.ng', 'details' => 'Failure threshold reached']],
        'ipFilter' => '',
        'blocked' => [['ip_address' => '102.89.1.1', 'blocked_until' => '2026-11-09 09:00:00', 'escalation_level' => 1, 'whitelisted' => 0]],
        'recognised' => [['ip_address' => '197.210.1.1', 'clean_sessions' => 5]],
        'rules' => [['id' => 3, 'rule_type' => 'ip', 'target_value' => '197.210.1.1', 'action' => 'whitelisted', 'full_name' => 'Amaka Obi']],
        'firewall' => [['ip_address' => '102.89.1.1', 'status' => 'pending', 'action' => 'block']],
        'countries' => [
            ['country_code' => 'NG', 'country_name' => 'Nigeria', 'action_code' => 'whitelisted'],
            ['country_code' => 'ZZ', 'country_name' => 'Testland', 'action_code' => 'blacklisted'],
        ],
        'suspended' => [['id' => 7, 'full_name' => 'Bad Actor', 'email' => 'bad@example.ng', 'role' => 'youth_member']],
        'counts' => ['failed' => 12, 'blockedIp' => 1, 'suspended' => 1],
        'settings' => ['username_protection' => '1', 'ip_protection' => '1', 'block_duration' => 'week',
                       'auto_whitelist_sessions' => '5', 'log_retention_minutes' => '60', 'country_unknown_policy' => 'allow'],
    ], $admin);
    assertContains('Security centre', $html);
    assertContains('♛', $html, 'crown badge for recognised addresses');
    assertContains('value="month"', $html, 'escalation ladder is offered');
    assertContains('Country rules', $html);
    assertContains('country-filter', $html, 'live filter input present');
    assertMissing('value="year" selected', $html);
});

ok('Settings screen groups every typed setting', function () use ($renderPage, $admin): void {
    $html = $renderPage('staff/settings', [
        'settings' => ['site_title' => 'RISE 63', 'site_tagline' => 'Tagline', 'notification_email' => 'ops@example.ng',
                       'theme_color' => '#2563eb', 'pwa_install_prompt' => '1', 'maintenance_mode' => '0',
                       'session_idle_minutes' => '30', 'login_alerts_enabled' => '1',
                       'academy_graduation_percent' => '80', 'seed_grant_monthly_pool' => '100000.00',
                       'seed_grant_max_per_application' => '100000.00', 'education_bridge_sla_hours' => '72', 'current_cycle_year' => '2026'],
        'outbox' => [['id' => 1, 'recipient' => 'ops@example.ng', 'subject' => 'Test', 'status' => 'pending', 'attempts' => 0, 'created_at' => '2026-11-08 09:00:00']],
        'queue' => ['pending' => 1, 'failed' => 0],
        'audit' => [['id' => 41, 'created_at' => '2026-11-08 09:00:00', 'actor_email' => 'amaka@example.ng',
                     'actor_name' => 'Amaka Obi', 'actor_role' => 'provincial_admin', 'action' => 'settings.update',
                     'entity' => 'setting', 'entity_id' => 'site_title', 'summary' => 'site_title changed']],
        'auditPager' => RISE63\Core\Paginator::fromState(25, null, 1, ['per_page' => '25'], 'settings', true),
        'auditFilters' => ['actor' => '', 'action' => '', 'entity' => '', 'kind' => '', 'q' => ''],
        'auditActors' => [['id' => 1, 'full_name' => 'Amaka Obi', 'email' => 'amaka@example.ng', 'role' => 'provincial_admin']],
        'auditActions' => ['settings.update', 'user.role'],
        'auditTotal' => 128,
        'cycleYear' => 2026,
        'autoYear' => '2026',
        'yearMode' => 'auto',
        'funds' => [],
    ], $admin);
    assertContains('Settings', $html);
    assertContains('name="settings[site_title]"', $html);
    assertContains('name="settings_bool[pwa_install_prompt]"', $html);
    assertContains('Queue a test message', $html);
    assertContains('Audit trail', $html);
    // The audit card pages, and says so without JavaScript.
    assertContains('Older', $html, 'the audit card offers a next page');
    assertContains('128 events recorded', $html);
    assertContains('Per page', $html);
    // The program year explains itself and does not ask to be typed while automatic.
    assertContains('Program year', $html);
    assertContains('advances by itself on 1 January', $html);
    assertContains('name="settings[cycle_year_mode]"', $html);
    // Bookkeeping keys are not offered as free-text fields.
    assertMissing('name="settings[seed_grant_opening_balance_year]"', $html, 'the pool year is written by the ledger, not typed');
});

ok('Settings offers mail configuration, and a secret never reaches the markup', function () use ($renderPage, $admin): void {
    // config/smtp.php was the one file an operator had to hand-write, and a mistake in it produced
    // no error at all — only password resets that never arrived. This panel replaced that, so the
    // things worth asserting are the fields, the honest empty state, and the absence of the password.
    $base = [
        'settings' => ['site_title' => 'RISE 63', 'theme_color' => '#2563eb', 'pwa_install_prompt' => '1',
                       'maintenance_mode' => '0', 'session_idle_minutes' => '30', 'login_alerts_enabled' => '1',
                       'cycle_year_mode' => 'auto', 'current_cycle_year' => '2026'],
        'outbox' => [], 'queue' => ['pending' => 0, 'failed' => 0],
        'audit' => [], 'auditFilters' => [], 'auditActors' => [], 'auditActions' => [],
        'auditTotal' => 0, 'cycleYear' => 2026, 'autoYear' => '2026', 'yearMode' => 'auto', 'funds' => [],
        // A value object, never null: production always hands one over, so a null fixture would be
        // testing a state that cannot occur.
        'auditPager' => RISE63\Core\Paginator::fromState(25, null, 0, ['per_page' => '25'], 'settings', false),
    ];

    // ── Unconfigured: the consequence is stated, not implied.
    $html = $renderPage('staff/settings', $base + ['smtp' => ['configured' => false, 'has_password' => false]], $admin);
    assertContains('Mail (SMTP)', $html);
    assertContains('Not configured', $html);
    assertContains('Forgot password?', $html, 'the panel must say what an empty config costs');
    foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth', 'smtp_username', 'smtp_password',
              'smtp_from_email', 'smtp_from_name', 'smtp_helo'] as $field) {
        assertContains('id="' . $field . '"', $html, 'the mail form is missing ' . $field);
    }
    assertContains('value="smtp-save"', $html);
    assertMissing('value="smtp-test"', $html, 'there is nothing to test until it is configured');

    // ── Configured: the actions appear, and the stored password is absent from the HTML.
    $html = $renderPage('staff/settings', $base + ['smtp' => [
        'configured' => true, 'has_password' => true, 'host' => 'smtp.gmail.com', 'port' => 587,
        'encryption' => 'tls', 'username' => 'alerts@rccg63.org', 'auth' => 'login',
        'from_email' => 'no-reply@rccg63.org', 'from_name' => 'RISE 63', 'helo' => 'rccg63.org',
        // Deliberately present in the payload: the template must refuse it even if a caller leaks it.
        'password' => 'SHOULD-NEVER-APPEAR',
    ]], $admin);
    assertContains('Configured', $html);
    assertContains('value="smtp-test"', $html, 'a configured transport must be testable');
    assertContains('value="smtp-send-test"', $html, 'delivery can only be proven by a message');
    assertContains('value="smtp-remove"', $html);
    assertMissing('SHOULD-NEVER-APPEAR', $html, 'THE PASSWORD REACHED THE MARKUP');
    assertContains('Saved — leave blank to keep it', $html, 'the empty field must explain why it is empty');
    assertContains('value="no-reply@rccg63.org"', $html);
});

ok('Settings shows the database-update state without ever applying one', function () use ($renderPage, $admin): void {
    // The reported 500 had one cause nothing in the interface reported: a pending migration. The
    // panel states the fact and the command. Applying a schema change from a web request would be
    // worse than the outage — it is the one operation that can leave a database half-modified.
    $base = [
        'settings' => ['site_title' => 'RISE 63', 'theme_color' => '#2563eb', 'pwa_install_prompt' => '1',
                       'maintenance_mode' => '0', 'session_idle_minutes' => '30', 'login_alerts_enabled' => '1',
                       'cycle_year_mode' => 'auto', 'current_cycle_year' => '2026'],
        'outbox' => [], 'queue' => ['pending' => 0, 'failed' => 0],
        'audit' => [], 'auditFilters' => [], 'auditActors' => [], 'auditActions' => [],
        'auditTotal' => 0, 'cycleYear' => 2026, 'autoYear' => '2026', 'yearMode' => 'auto', 'funds' => [],
        // A value object, never null: production always hands one over, so a null fixture would be
        // testing a state that cannot occur.
        'auditPager' => RISE63\Core\Paginator::fromState(25, null, 0, ['per_page' => '25'], 'settings', false),
        'smtp' => ['configured' => false, 'has_password' => false],
    ];

    $pending = $renderPage('staff/settings', $base + ['schemaState' => [
        'known' => true, 'error' => '',
        'pending' => [
            ['version' => '0001', 'file' => '0001_account_lifecycle.sql', 'summary' => '0001 — account lifecycle'],
            ['version' => '0002', 'file' => '0002_unlisted_parish_name.sql', 'summary' => '0002 — unlisted parish name'],
        ],
        'applied' => [], 'modified' => [], 'count' => 3,
    ]], $admin);
    assertContains('Database updates', $pending);
    assertContains('2 database updates are waiting', $pending);
    assertContains('php bin/migrate.php --status', $pending, 'the exact command must be shown');
    assertContains('0002 — unlisted parish name', $pending, 'the problem file must be named in plain words');
    // It must be instructions, never a button: no form posts a migration.
    assertMissing('value="run-migrations"', $pending, 'migrations must never be applied from the browser');

    $clean = $renderPage('staff/settings', $base + ['schemaState' => [
        'known' => true, 'error' => '',
        'pending' => [], 'applied' => [['version' => '0001', 'summary' => '0001 — account lifecycle']],
        'modified' => [], 'count' => 1,
    ]], $admin);
    assertContains('Up to date', $clean);
    assertMissing('database updates are waiting', $clean, 'a migrated database must not nag');

    $unreadable = $renderPage('staff/settings', $base + ['schemaState' => [
        'known' => false, 'error' => 'could not connect', 'pending' => [], 'applied' => [], 'modified' => [], 'count' => 0,
    ]], $admin);
    assertContains('could not connect', $unreadable, 'a failure must be reported, not hidden or thrown');
    assertContains('Database updates', $unreadable, 'the panel must still render');
});

ok('Academy attendance is recorded by staff and shown to the applicant', function () use ($renderPage, $admin, $member): void {
    $academyApplication = [
        'id' => 21, 'user_id' => 2, 'parish_id' => 5, 'program_type' => 'academy', 'cycle_year' => 2026,
        'education_level' => 'none', 'school_name' => null, 'school_class' => null,
        'requested_amount' => '0.00', 'approved_amount' => '0.00', 'documents_json' => null, 'answers_json' => null,
        'status' => 'under_review', 'decision_note' => null, 'idempotency_key' => null,
        'submitted_at' => '2026-10-01 09:00:00', 'verified_at' => null, 'approved_at' => null, 'disbursed_at' => null,
        'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-20 09:00:00',
        'full_name' => 'Chinedu Eze', 'email' => 'chinedu@example.ng', 'parish_name' => 'Living Water',
    ];
    $progress = ['application_id' => 21, 'sessions_held' => 12, 'sessions_attended' => 11, 'percent' => 92,
                 'graduated' => true, 'mentor_note' => 'Excellent pitch — keep the records tidy.', 'business_idea' => 'Tailoring'];

    $staff = $renderPage('member/application', [
        'application' => $academyApplication, 'events' => [], 'answers' => [], 'documents' => [],
        'tracker' => RISE63\Core\Program::tracker('academy', 'under_review'), 'canReview' => true,
        'academy' => $progress, 'canRecord' => true,
    ], $admin);
    assertContains('RISE Academy attendance', $staff);
    assertContains('92%', $staff);
    assertContains('Save attendance', $staff);
    assertContains('Excellent pitch', $staff);

    $applicant = $renderPage('member/application', [
        'application' => $academyApplication, 'events' => [], 'answers' => [], 'documents' => [],
        'tracker' => RISE63\Core\Program::tracker('academy', 'under_review'), 'canReview' => false,
        'academy' => $progress, 'canRecord' => false,
    ], $member);
    assertContains('graduated', $applicant);
    assertMissing('Save attendance', $applicant, 'members cannot edit their own attendance');
});

ok('Every bootstrap constant is referenced with its namespace', function (): void {
    // Regression guard for the installer fatal: "Undefined constant ROOT". A file outside the
    // RISE63 namespace must import the constants it uses, or qualify them. PHP's lint cannot see
    // this, and the render harness only loads Views, so it is checked textually here.
    $constants = ['ROOT', 'VERSION', 'PRODUCT', 'SESSION_NAME', 'IDLE_MINUTES', 'MAX_BODY_BYTES', 'MIN_PHP'];
    $files = [];
    foreach (['index.php', 'install/index.php', 'api/index.php', 'app/download.php', 'router.php'] as $file) {
        $files[] = RISE63_TEST_ROOT . '/' . $file;
    }
    foreach (glob(RISE63_TEST_ROOT . '/app/*.php') ?: [] as $file) {
        $files[] = $file;
    }
    foreach (glob(RISE63_TEST_ROOT . '/app/Views/*.php') ?: [] as $file) {
        $files[] = $file;
    }
    foreach (glob(RISE63_TEST_ROOT . '/app/Views/*/*.php') ?: [] as $file) {
        $files[] = $file;
    }
    foreach (glob(RISE63_TEST_ROOT . '/bin/*.php') ?: [] as $file) {
        $files[] = $file;
    }

    $problems = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $imported = [];
        if (preg_match_all('/use const RISE63\\\{([^}]*)\}/', $source, $m) > 0) {
            foreach ($m[1] as $list) {
                foreach (array_filter(array_map('trim', explode(',', $list))) as $name) {
                    $imported[$name] = true;
                }
            }
        }
        if (preg_match_all('/^use const RISE63\\\s*(\w+);/m', $source, $m2) > 0) {
            foreach ($m2[1] as $name) {
                $imported[$name] = true;
            }
        }
        // A `namespace RISE63;` header makes bare constants resolve on their own.
        $namespaced = preg_match('/^namespace RISE63;[\s\S]*?(?:const|require)/m', $source) === 1;

        foreach ($constants as $constant) {
            // Look for bare usage: not preceded by a backslash or namespace separators.
            if (preg_match('/(?<![\\\w])' . $constant . '(?![\w\'"])/', $source) !== 1) {
                continue;
            }
            // Ignore harmless mentions: the import list itself and comments/strings we cannot
            // parse here are filtered by requiring an identifier boundary before it.
            if (isset($imported[$constant])) {
                continue;
            }
            if ($namespaced) {
                continue;
            }
            if (str_contains($source, 'RISE63\\' . $constant)) {
                continue;
            }
            $problems[] = basename($file) . ' uses bare ' . $constant;
        }

        // And the import itself must exist where it is needed for install/index.php's constructor.
        if (str_ends_with($file, 'install/index.php')) {
            assertContains('use const RISE63\\{', $source, 'the installer must import bootstrap constants');
        }

        // The licence step must let the operator correct the domain: "Invalid license key or
        // domain" is indistinguishable from a wrong spelling, and the field is the only remedy
        // that does not involve editing files on the server.
        if (str_ends_with($file, 'install/index.php')) {
            assertContains('name="license_domain"', $source, 'the licence domain must be submittable');
            assertContains('id="license_domain"', $source, 'stage 1 needs an editable domain field');
            assertContains('id="license_domain_stage3"', $source, 'stage 3 needs an editable domain field');
            assertMissing('id="domain"', $source, 'the read-only domain field was replaced');
            assertContains('$probe3      = $activation3->verify($replacement, $host3);', $source,
                'a replacement key is probed against the typed domain');
            // Resuming setup must not force a fresh provider call for a domain already accepted.
            assertContains('$activation->domainAccepted($probeDomain)', $source,
                'resuming setup checks the recorded domain before calling the provider');
            // A refusal must say which string was sent, or the operator cannot tell a bad key from
            // a differently-spelled domain.
            assertContains("The domain sent was ", $source, 'refusals must name the domain sent');
        }
    }
    assertSame([], $problems, 'bare constant references outside the RISE63 namespace');
});

/* ───────────────────────────────── UX contract ─────────────────────────────── */

ok('Stylesheet defines every class the setup wizard uses', function (): void {
    // The wizard markup was rewritten; CSS that does not exist is worse than no markup at all.
    $css = (string) file_get_contents(RISE63_TEST_ROOT . '/public/assets/app.css');
    foreach ([
        'wizard-wrap', 'wizard-top', 'wizard-version', 'wizard-steps', 'step-num', 'step-label',
        'is-current', 'is-done', 'wizard-panel', 'checklist', 'check-icon', 'check-body', 'is-ok',
        'is-bad', 'wizard-fields', 'field-full', 'wizard-actions', 'wizard-note', 'wizard-done',
        'done-mark',
    ] as $class) {
        assertContains('.' . $class, $css, 'missing wizard style .' . $class);
    }

    // The wizard must stay usable on a phone: the step rail and the two-column fields collapse.
    assertContains('@media (max-width: 720px)', $css);
    assertContains('grid-template-columns: repeat(2, minmax(0, 1fr))', $css, 'step rail halves on small screens');
});

ok('Every inline script the browser receives is allowed by the CSP hash list', function () use ($renderPage, $admin): void {
    // script-src has no 'unsafe-inline', so an inline script whose hash is missing runs *nowhere*
    // and says nothing about it. That shipped: the pre-paint theme script was blocked, so a
    // signed-in user saw the wrong theme flash on every load, and the redundant offline-close
    // handler could not cover for a stale app.js either.
    //
    // The hash must be computed over the *rendered* markup, not the template file: a PHP
    // expression inside a <script> block emits different bytes than the source, and the browser
    // hashes what it receives.
    $html = $renderPage('member/home', [
        'applications' => [],
        'summary' => ['total' => 0, 'pending' => 0, 'approved' => 0, 'disbursed' => 0, 'rejected' => 0, 'requested' => 0],
        'eligibility' => [
            'academy' => ['label' => 'RISE Academy', 'available' => true, 'reason' => null],
            'seed_grant' => ['label' => 'Seed Grant', 'available' => false, 'reason' => 'Academy graduation required'],
        ],
    ], $admin);

    preg_match_all('#<script>(.*?)</script>#s', $html, $matches);
    assertTrue(count($matches[1]) >= 2, 'expected the theme and offline-close snippets, got ' . count($matches[1]));

    $declared = RISE63\Core\Security::INLINE_SCRIPT_HASHES;
    $policy   = RISE63\Core\Security::contentSecurityPolicy();
    assertContains("script-src 'self' 'sha256-", $policy, 'the policy carries the allow-list');
    assertMissing("'unsafe-inline'", $policy, 'the allow-list must not weaken the policy');

    foreach ($matches[1] as $index => $inline) {
        $hash = 'sha256-' . base64_encode(hash('sha256', $inline, true));
        assertTrue(in_array($hash, $declared, true),
            'inline script #' . $index . ' would be blocked by the CSP. hash=' . $hash);
        assertContains("'" . $hash . "'", $policy);
    }

    assertSame(count($matches[1]), count($declared), 'one declared hash per inline script, no orphans');
});

ok('Stylesheet meets the mobile-first contract', function (): void {
    $css = (string) file_get_contents(RISE63_TEST_ROOT . '/public/assets/app.css');
    assertContains('--t-base: 1rem', $css, 'base type is 16px');
    assertContains('--touch: 48px', $css, 'touch target token');
    assertContains('min-height: var(--touch)', $css);
    assertContains('font-size: var(--t-base); /* never below 16px', $css, 'inputs are 16px to stop iOS zoom');
    assertContains('prefers-reduced-motion', $css);
    assertContains('prefers-color-scheme: dark', $css);
    assertContains('[data-theme="dark"]', $css);
    assertContains('.tracker-step', $css);
    assertContains('.combo-list', $css);
    assertContains('.offline-modal', $css, 'the connectivity notice is a modal');
    assertContains('.offline-sheet', $css);
    assertContains('html.offline-open', $css, 'the page behind the modal does not scroll');
    assertContains('.bottom-nav .nav-indicator', $css);

    // Sticky, at every width: pinned to the viewport, never static and never a second copy inside
    // the page flow. Reported twice as "not sticky", so it is asserted rather than assumed.
    preg_match('/\.bottom-nav \{[^}]*\}/', $css, $navBlock);
    $nav = $navBlock[0] ?? '';
    assertContains('position: fixed', $nav, 'the tab bar is pinned to the viewport');
    assertContains('bottom: calc(', $nav, 'it sits above the home-indicator inset');
    assertMissing('.bottom-nav { position: static', $css, 'never static at any width');
    assertMissing('.bottom-nav { position: relative', $css, 'never in the document flow');
    // Exactly one tab bar per page: a second copy in the footer would scroll away while the fixed
    // one stayed, which reads as two nav bars.
    $layout = (string) file_get_contents(RISE63_TEST_ROOT . '/app/Views/layout.php');
    assertSame(1, substr_count($layout, 'class="bottom-nav"'), 'one tab bar, not two');
    assertContains('has-bottom-nav', $layout, 'the page reserves room for the bar');
    assertContains('.page.has-bottom-nav', $css, 'and the stylesheet defines that room');
    assertContains('.install-prompt', $css);
    assertContains('@media print', $css);
    // No unreadably small type. Checked by value, not by prefix: 0.6875rem (11px) is the floor a
    // tab-bar label may use, and the old 'font-size: 0.6' prefix would reject it while accepting
    // e.g. 0.65rem. Every rem size in the sheet is parsed and the smallest one must clear 11px.
    preg_match_all('/font-size:\s*([0-9.]+)rem/', $css, $remSizes);
    $smallest = $remSizes[1] === [] ? 1.0 : min(array_map('floatval', $remSizes[1]));
    assertTrue($smallest >= 0.6875, 'smallest rem font-size is ' . $smallest . 'rem, below the 11px floor');
    assertMissing('font-size:10px', $css);
    assertMissing('font-size: 10px', $css);
});

ok('Manifest and service worker satisfy the install requirements', function (): void {
    $manifest = json_decode((string) file_get_contents(RISE63_TEST_ROOT . '/manifest.webmanifest'), true);
    assertTrue(is_array($manifest), 'manifest must be valid JSON');
    assertSame('RISE 63', $manifest['name']);
    assertSame('standalone', $manifest['display']);
    assertSame('#2563eb', $manifest['theme_color']);
    $sizes = array_column($manifest['icons'], 'sizes');
    assertTrue(in_array('192x192', $sizes, true));
    assertTrue(in_array('512x512', $sizes, true));
    assertTrue(count(array_filter($manifest['icons'], static fn ($i) => $i['purpose'] === 'maskable')) >= 2, 'maskable icons');
    foreach ($manifest['icons'] as $icon) {
        assertTrue(is_file(RISE63_TEST_ROOT . '/' . $icon['src']), 'missing icon file ' . $icon['src']);
    }

    $sw = (string) file_get_contents(RISE63_TEST_ROOT . '/sw.js');
    assertContains('SHELL_VERSION', $sw);
    assertContains("caches.delete", $sw, 'old caches are removed');
    assertContains("url.pathname.indexOf('/api/')", $sw, 'API responses are never cached');
    assertContains("request.method !== 'GET'", $sw);
});

ok('Every administrator destination is reachable from the chrome', function (): void {
    // The complaint that started this: "the admin is yet to be able to manage members". The page
    // worked and nothing linked to it. A destination with no route to it does not exist.
    $layout = (string) file_get_contents(RISE63_TEST_ROOT . '/app/Views/layout.php');

    // People is a tab, for administrators only.
    assertContains("'people'       => ['People'", $layout, 'People is a primary tab for administrators');
    assertContains("unset(\$navItems['people'])", $layout, 'and only for administrators');

    // Settings, Activity and Updates live in the top bar as words, not glyphs.
    foreach (['settings' => 'Settings', 'audit' => 'Activity', 'updates' => 'Updates'] as $page => $label) {
        assertContains("url('" . $page . "')", $layout, $page . ' is linked');
        assertContains('>' . $label . '</a>', $layout, $page . ' is linked as a word');
    }
    assertMissing('aria-label="Updates" title="Updates">⇪', $layout, 'the unlabelled glyph is gone');

    // Administrators do not get a Security tab: it would make seven, and at 360 px the labels
    // collapse to "Treas…" and "Secur…". It is in the top bar instead, where there is room for the
    // whole word. Representatives have no top bar, so they keep the tab.
    assertContains("(string) \$account['role'] === 'csr_rep') {", $layout, 'the Security tab is a representative route');
    assertMissing("Auth::isAdmin(\$account) || (string) \$account['role'] === 'csr_rep')) {\n    \$tabs[]", $layout,
        'security is not a tab for administrators');

    // Every admin-only page is also routed, so the link does not 404.
    $router = (string) file_get_contents(RISE63_TEST_ROOT . '/app/pages.php');
    foreach (['people', 'settings', 'audit', 'updates'] as $page) {
        assertTrue(str_contains($router, "case '" . $page . "'"), 'the ' . $page . ' page is routed');
    }
});

ok('Every template referenced by the router exists', function (): void {
    $router = (string) file_get_contents(RISE63_TEST_ROOT . '/app/pages.php');
    preg_match_all("/render\\('([^']+)'/", $router, $matches);
    assertTrue(count($matches[1]) >= 10, 'expected the router to reference several views');
    foreach (array_unique($matches[1]) as $template) {
        assertTrue(is_file(RISE63_TEST_ROOT . '/app/Views/' . $template . '.php'), 'missing view ' . $template);
    }
});

ob_end_flush();

ok('Every routed page has matching handling and navigation', function (): void {
    $pages   = (string) file_get_contents(RISE63_TEST_ROOT . '/app/pages.php');
    $actions = (string) file_get_contents(RISE63_TEST_ROOT . '/app/actions.php');
    $layout  = (string) file_get_contents(RISE63_TEST_ROOT . '/app/Views/layout.php');

    preg_match_all("/case '([a-z0-9-]+)':/", $pages, $pageCases);
    preg_match_all("/case '([a-z0-9-]+)':/", $actions, $actionCases);

    // Every form action must exist in the POST handler.
    preg_match_all('/name="action" value="([a-z0-9-]+)"/', implode('', array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        glob(RISE63_TEST_ROOT . '/app/Views/*/*.php') ?: []
    )), $formActions);
    $formActions = array_unique($formActions[1]);
    assertTrue(count($formActions) >= 15, 'expected the UI to post several actions');
    foreach ($formActions as $action) {
        assertTrue(in_array($action, $actionCases[1], true), 'no POST handler for action ' . $action);
    }

    // Every navigation target must be a routable page.
    preg_match_all("/'([a-z0-9-]+)' => \['/", $layout, $navTargets);
    foreach ($navTargets[1] as $target) {
        assertTrue(in_array($target, $pageCases[1], true), 'navigation points at an unrouted page: ' . $target);
    }
    assertTrue(in_array('security', $pageCases[1], true));
    assertTrue(in_array('settings', $pageCases[1], true));

    // The update page is admin-only and must be reachable from the chrome, or an operator who is
    // told to "check for updates" has nowhere to click.
    assertTrue(in_array('updates', $pageCases[1], true), 'the updates page is routed');
    assertTrue(str_contains($layout, "url('updates')"), 'the updates page is linked from the header');
    foreach (['update-check', 'update-plan', 'update-apply'] as $action) {
        assertTrue(in_array($action, $actionCases[1], true), 'no POST handler for ' . $action);
    }
});

ok('Every privileged page declares its role gate', function (): void {
    $pages = (string) file_get_contents(RISE63_TEST_ROOT . '/app/pages.php');
    foreach (['people', 'security', 'settings'] as $adminPage) {
        $start = strpos($pages, "case '" . $adminPage . "':");
        assertTrue($start !== false, 'missing page ' . $adminPage);
        $block = substr($pages, $start, 200);
        assertContains('requireAdmin()', $block, $adminPage . ' must be admin-only');
    }
    $treasury = substr($pages, strpos($pages, "case 'treasury':"), 240);
    assertContains('requireRole', $treasury, 'treasury must be role-gated');
});

ok('Writes are never reachable by GET', function (): void {
    $actions = (string) file_get_contents(RISE63_TEST_ROOT . '/app/actions.php');
    $router  = (string) file_get_contents(RISE63_TEST_ROOT . '/index.php');
    assertContains('Security::assertPost()', $router, 'the front controller must gate every POST');
    assertContains('if ($method === \'POST\')', $router);
    // Exception: the authenticated document download is a GET, routed to its own file and
    // scope-checked there before anything is streamed.
    assertContains("\$action === 'document-download'", $router);
    $download = (string) file_get_contents(RISE63_TEST_ROOT . '/app/download.php');
    assertContains('Applications::find(', $download, 'downloads must be scope-checked');
    assertContains('realpath(', $download, 'the path must be resolved and contained');
    assertContains("header('Content-Disposition: attachment", $download);
    assertMissing('Application::submit', $download);
});

ok('Templates never echo raw request data', function (): void {
    $files = array_merge(glob(RISE63_TEST_ROOT . '/app/Views/*.php') ?: [], glob(RISE63_TEST_ROOT . '/app/Views/*/*.php') ?: []);
    $offenders = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        // Any bare echo of $_GET/$_POST/$_SERVER/$_COOKIE is an XSS foot-gun.
        if (preg_match('/<\?=\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/', $source) === 1) {
            $offenders[] = basename($file);
        }
        if (preg_match('/echo\s+\$_(GET|POST|REQUEST|COOKIE|SERVER)/', $source) === 1) {
            $offenders[] = basename($file);
        }
    }
    assertTrue($offenders === [], 'unescaped request output in: ' . implode(', ', array_unique($offenders)));
});

echo "\n" . str_repeat('─', 62) . "\n";
printf("%d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail']);
if ($GLOBALS['failures'] !== []) {
    echo "\nFailures:\n  - " . implode("\n  - ", $GLOBALS['failures']) . "\n";
    exit(1);
}
exit(0);
