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

ok('A member can name a church that is not in the directory, and admins see the queue', function () use ($renderPage, $admin, $member): void {
    $profile = $renderPage('member/profile', [
        'devices' => [], 'pinLock' => 0, 'parish' => null,
    ], $member);
    assertContains('name="parish_name_raw"', $profile);
    assertContains('data-caps', $profile);

    // "Add it later" is only honest if somebody is told what to add.
    $people = $renderPage('staff/people', [
        'people' => [], 'term' => '', 'roles' => RISE63\Core\Auth::ROLES,
        'unlisted' => [['parish_name_raw' => 'CHAPEL OF WONDERS PARISH', 'member_count' => 3]],
    ], $admin);
    assertContains('Churches members asked for', $people);
    assertContains('CHAPEL OF WONDERS PARISH', $people);
    assertContains('3 member(s)', $people);

    $nothing = $renderPage('staff/people', [
        'people' => [], 'term' => '', 'roles' => RISE63\Core\Auth::ROLES, 'unlisted' => [],
    ], $admin);
    assertMissing('Churches members asked for', $nothing, 'an empty queue is not a section');
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
        'approved' => [[
            'id' => 9, 'full_name' => 'Chinedu Eze', 'program_type' => 'seed_grant', 'requested_amount' => '100000.00', 'status' => 'approved',
        ]],
    ], $admin);
    assertContains('Confirm payment', $html);
    assertContains('type="password"', $html);
    assertContains('Append-only', $html);
    assertContains('LED-RS63-AAAA-261101', $html);
});

ok('People screen offers role changes and suspension', function () use ($renderPage, $admin): void {
    $html = $renderPage('staff/people', [
        'people' => [
            ['id' => 2, 'full_name' => 'Chinedu Eze', 'email' => 'chinedu@example.ng', 'role' => 'youth_member',
             'parish_name' => 'Living Water', 'last_login_at' => null, 'is_suspended' => 0],
            ['id' => 1, 'full_name' => 'Amaka Obi', 'email' => 'amaka@example.ng', 'role' => 'provincial_admin',
             'parish_name' => null, 'last_login_at' => '2026-11-08 08:00:00', 'is_suspended' => 0],
        ],
        'term' => '',
        'roles' => RISE63\Core\Auth::ROLES,
    ], $admin);
    assertContains('Suspend', $html);
    assertContains('Never', $html, 'accounts that never signed in');
    assertContains('Temporary password', $html);
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
        'audit' => [['created_at' => '2026-11-08 09:00:00', 'actor_email' => 'amaka@example.ng', 'action' => 'settings.update',
                     'entity' => 'setting', 'entity_id' => 'site_title', 'summary' => 'site_title changed']],
    ], $admin);
    assertContains('Settings', $html);
    assertContains('name="settings[site_title]"', $html);
    assertContains('name="settings_bool[pwa_install_prompt]"', $html);
    assertContains('Queue a test message', $html);
    assertContains('Audit trail', $html);
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
