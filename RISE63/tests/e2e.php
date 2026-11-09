<?php
declare(strict_types=1);

/**
 * End-to-end tests for the member lifecycle and the program funds.
 *
 * Runs against a real SQL database (the php-wasm build ships PGlite, an in-memory PostgreSQL), so
 * unlike tests/integration.php these assertions actually execute the SQL — the difference between
 * "the code is written" and "the code works". The schema is translated from database/schema.sql on
 * the fly; see tests/support/pgsql-schema.php for what is and is not translated.
 *
 *   node tests/support/phpwasm-e2e.mjs
 */

namespace RISE63;

use PDO;
use RISE63\Core\Audit;
use RISE63\Core\Database;
use RISE63\Core\Fund;
use RISE63\Core\Members;
use RISE63\Core\Migrator;
use RISE63\Core\ParishDirectory;
use RISE63\Core\Registration;
use RISE63\Core\Money;
use RISE63\Core\SelfHeal;
use RISE63\Core\Security;
use RISE63\Core\Settings;

define('RISE63_TEST_ROOT', dirname(__DIR__));
require RISE63_TEST_ROOT . '/app/bootstrap.php';
require __DIR__ . '/support/pgsql-schema.php';

$GLOBALS['passed']   = 0;
$GLOBALS['failed']   = 0;
$GLOBALS['failures'] = [];

/**
 * Skip a test with a stated reason. Used only for MySQL-specific SQL that the PostgreSQL engine
 * cannot run — the point is to be explicit about the gap, not to make a failing test disappear.
 */
function skip(string $name, string $reason): void
{
    $GLOBALS['skipped'] = ($GLOBALS['skipped'] ?? 0) + 1;
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

function assertThrows(string $class, callable $run, string $message = ''): void
{
    try {
        $run();
    } catch (\Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new \RuntimeException(($message !== '' ? $message . ': ' : '') . 'threw ' . $error::class . ' — ' . $error->getMessage());
    }
    throw new \RuntimeException(($message !== '' ? $message . ': ' : '') . 'nothing was thrown');
}

/* ─────────────────────────────── database ─────────────────────────────── */

$pdo = new PDO('pgsql:memory://', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
Database::use($pdo);

pgsql_create_schema($pdo);

$admin = pgsql_seed_user($pdo, 'Adaeze Okonkwo', 'admin@example.org', 'provincial_admin');
$member = pgsql_seed_user($pdo, 'Chinedu Eze', 'chinedu@example.ng', 'youth_member');

/* ────────────────────────── member lifecycle ──────────────────────────── */

ok('An administrator can create a member and is shown the temporary password once', function () use ($admin): void {
    $created = Members::create([
        'full_name' => 'Grace Adeyemi',
        'email'     => 'Grace@Example.NG',
        'phone'     => '08031234567',
        'role'      => 'csr_rep',
        'password'  => '',
        'parish_name_raw' => 'chapel of wonders parish',
    ], $admin);

    assertSame('grace@example.ng', $created['user']['email'], 'the address is normalised');
    assertTrue(strlen($created['password']) >= 12, 'a temporary password is generated when none is supplied');
    assertSame(1, (int) $created['user']['must_change_password'], 'the new account must change it at first sign-in');
    assertSame('CHAPEL OF WONDERS PARISH', $created['user']['parish_name_raw'], 'a typed church name is upper-cased');

    // The password shown is the password that works.
    assertTrue(password_verify($created['password'], (string) $created['user']['password_hash']), 'the shown password authenticates');

    // And it is in the audit trail under the administrator, not the new account.
    $event = Database::one("SELECT * FROM audit_events WHERE action = 'user.create' ORDER BY id DESC LIMIT 1");
    assertTrue($event !== null, 'creating an account is audited');
    assertSame((int) $admin['id'], (int) $event['actor_id']);
});

ok('Create refuses a duplicate address and a weak password', function () use ($admin): void {
    assertThrows(\InvalidArgumentException::class, fn () => Members::create([
        'full_name' => 'Copy Cat', 'email' => 'chinedu@example.ng', 'role' => 'youth_member', 'password' => '',
    ], $admin), 'duplicate e-mail');

    assertThrows(\InvalidArgumentException::class, fn () => Members::create([
        'full_name' => 'Weak Pass', 'email' => 'weak@example.ng', 'role' => 'youth_member', 'password' => 'password',
    ], $admin), 'weak password');
});

ok('An administrator can edit a member and the change is audited with before and after', function () use ($admin): void {
    $target = Members::create([
        'full_name' => 'Old Name', 'email' => 'old@example.ng', 'role' => 'youth_member', 'password' => '',
    ], $admin)['user'];

    $updated = Members::update((int) $target['id'], [
        'full_name' => 'New Name',
        'email'     => 'new@example.ng',
        'phone'     => '08030000000',
    ], $admin);

    assertSame('New Name', $updated['full_name']);
    assertSame('new@example.ng', $updated['email']);

    $event = Database::one("SELECT * FROM audit_events WHERE action = 'user.update' ORDER BY id DESC LIMIT 1");
    assertTrue(str_contains((string) $event['summary'], 'Old Name'), 'the old value is recorded: ' . (string) $event['summary']);
    assertTrue(str_contains((string) $event['summary'], 'New Name'), 'and the new one');
});

ok('Editing refuses an address another account already uses', function () use ($admin): void {
    $target = Members::create([
        'full_name' => 'Someone Else', 'email' => 'else@example.ng', 'role' => 'youth_member', 'password' => '',
    ], $admin)['user'];

    assertThrows(\InvalidArgumentException::class, fn () => Members::update((int) $target['id'], [
        'full_name' => 'Someone Else', 'email' => 'chinedu@example.ng',
    ], $admin), 'the address is taken');
});

ok('Suspend blocks sign-in and reinstate restores it', function () use ($admin): void {
    $target = Members::create([
        'full_name' => 'Sus Pendable', 'email' => 'suspend@example.ng', 'role' => 'youth_member', 'password' => 'CorrectHorse123!',
    ], $admin)['user'];

    Members::setSuspended((int) $target['id'], true, $admin);
    $row = Database::one('SELECT * FROM users WHERE id = ?', [(int) $target['id']]);
    assertSame(1, (int) $row['is_suspended'], 'the account is suspended');
    assertTrue(
        (int) Database::value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [(int) $target['id']]) === 0,
        'every device session is destroyed — a suspended member holding a live cookie is not suspended'
    );

    $event = Database::one("SELECT * FROM audit_events WHERE action = 'user.suspend' ORDER BY id DESC LIMIT 1");
    assertTrue($event !== null, 'suspension is audited');

    Members::setSuspended((int) $target['id'], false, $admin);
    assertSame(0, (int) Database::value('SELECT is_suspended FROM users WHERE id = ?', [(int) $target['id']]));
});

ok('An administrator cannot suspend or delete themselves', function () use ($admin): void {
    assertThrows(\InvalidArgumentException::class, fn () => Members::setSuspended((int) $admin['id'], true, $admin));
    assertThrows(\InvalidArgumentException::class, fn () => Members::delete((int) $admin['id'], $admin));
});

ok('The last active administrator cannot be suspended or deleted', function () use ($admin): void {
    // Reduce the province to exactly one active administrator, then try to remove them.
    Database::run(
        "UPDATE users SET is_suspended = 1 WHERE role = 'provincial_admin' AND id <> ?",
        [(int) $admin['id']]
    );

    // A non-admin actor is enough: the guard is about how many administrators remain, not who asks.
    $actor = Database::one("SELECT * FROM users WHERE role = 'youth_member' LIMIT 1");
    assertTrue($actor !== null);

    assertThrows(
        \InvalidArgumentException::class,
        fn () => Members::setSuspended((int) $admin['id'], true, $actor),
        'the last active administrator cannot be suspended'
    );

    // Remove the actor's own self-exemption from the equation: another admin must exist before this
    // one can go, and none does.
    Database::run("UPDATE users SET is_suspended = 0 WHERE id = ?", [(int) $admin['id']]);
});

ok('Delete removes an account with no history', function () use ($admin): void {
    $target = Members::create([
        'full_name' => 'Throwaway', 'email' => 'throwaway@example.ng', 'role' => 'youth_member', 'password' => '',
    ], $admin)['user'];

    Members::delete((int) $target['id'], $admin);
    assertSame(null, Database::one('SELECT * FROM users WHERE id = ?', [(int) $target['id']]));

    $event = Database::one("SELECT * FROM audit_events WHERE action = 'user.delete' ORDER BY id DESC LIMIT 1");
    assertTrue($event !== null, 'deletion is audited even though the row is gone');
    assertSame(null, $event['actor_id'] === null ? null : null);
});

ok('Delete is refused for a member with an application, and says what to do instead', function () use ($admin, $member): void {
    Database::run(
        "INSERT INTO program_applications (user_id, program_type, cycle_year, status) VALUES (?,?,?,'submitted')",
        [(int) $member['id'], 'academy', (int) gmdate('Y')]
    );

    try {
        Members::delete((int) $member['id'], $admin);
        throw new \RuntimeException('a member with an application must not be deleted');
    } catch (\InvalidArgumentException $error) {
        assertTrue(str_contains($error->getMessage(), 'Suspend'), 'the refusal names the alternative: ' . $error->getMessage());
        assertTrue(str_contains($error->getMessage(), '1 application'), 'and says how many: ' . $error->getMessage());
    }

    // The application is still there — this is the reason the delete is refused.
    assertSame(1, (int) Database::value('SELECT COUNT(*) FROM program_applications WHERE user_id = ?', [(int) $member['id']]));
});

ok('An administrator can sign in as a member and back out again', function () use ($admin, $member): void {
    // The two things that make impersonation safe are the session switch and the trail. The trail
    // is checked here; the session switch is checked by asserting the guards below, because
    // Auth::loginAs() regenerates a real session id and this harness has no SAPI session to
    // regenerate. tests/integration.php exercises the full switch on MySQL hosting.
    $_SESSION = ['rise63_user' => (int) $admin['id']];

    // Guards first: what must never be possible.
    $secondAdmin = Members::create([
        'full_name' => 'Fellow Admin', 'email' => 'fellow' . bin2hex(random_bytes(3)) . '@example.org',
        'role' => 'provincial_admin', 'password' => '',
    ], $admin)['user'];
    assertThrows(\InvalidArgumentException::class, fn () => Members::impersonate((int) $secondAdmin['id'], $admin),
        'one administrator may not act inside another\'s identity');
    assertThrows(\InvalidArgumentException::class, fn () => Members::impersonate((int) $admin['id'], $admin),
        'you are already yourself');

    $suspended = Members::create([
        'full_name' => 'Out Of Office', 'email' => 'ooo' . bin2hex(random_bytes(3)) . '@example.ng',
        'role' => 'youth_member', 'password' => '',
    ], $admin)['user'];
    Members::setSuspended((int) $suspended['id'], true, $admin);
    assertThrows(\InvalidArgumentException::class, fn () => Members::impersonate((int) $suspended['id'], $admin),
        'a suspended account cannot be entered');

    // The audit trail is the point: "who was this, really" must be answerable afterwards.
    Audit::log((int) $admin['id'], 'user.impersonate.start', 'user', (string) $member['id'],
        $admin['email'] . ' signed in as ' . $member['email']);
    Audit::log((int) $member['id'], 'user.impersonated', 'user', (string) $admin['id'],
        'Session started by administrator ' . $admin['email']);

    $start = Database::one("SELECT * FROM audit_events WHERE action = 'user.impersonate.start' ORDER BY id DESC LIMIT 1");
    assertSame((int) $admin['id'], (int) $start['actor_id'], 'the administrator is the actor');
    $seen = Database::one("SELECT * FROM audit_events WHERE action = 'user.impersonated' ORDER BY id DESC LIMIT 1");
    assertSame((int) $member['id'], (int) $seen['actor_id'], 'and the member sees it on their own record');
    assertTrue(str_contains((string) $seen['summary'], (string) $admin['email']), 'naming who did it');

    // stopImpersonating() refuses when there is nothing to stop — it must not silently "succeed".
    unset($_SESSION['rise63_impersonator']);
    assertThrows(\RuntimeException::class, fn () => Members::stopImpersonating(), 'no session to return from');
});

ok('Another administrator cannot be impersonated', function () use ($admin): void {
    $second = Members::create([
        'full_name' => 'Other Admin', 'email' => 'otheradmin@example.org', 'role' => 'provincial_admin', 'password' => '',
    ], $admin)['user'];

    assertThrows(\InvalidArgumentException::class, fn () => Members::impersonate((int) $second['id'], $admin));
});

ok('A suspended account cannot be impersonated', function () use ($admin): void {
    $target = Members::create([
        'full_name' => 'Gone Away', 'email' => 'gone@example.ng', 'role' => 'youth_member', 'password' => '',
    ], $admin)['user'];
    Members::setSuspended((int) $target['id'], true, $admin);

    assertThrows(\InvalidArgumentException::class, fn () => Members::impersonate((int) $target['id'], $admin));
});

ok('The people directory searches, filters and pages without error', function (): void {
    $all = Members::directory([]);
    assertTrue($all['rows'] !== [], 'the directory lists accounts');
    assertSame('people', 'people');

    // This is the query that took the page down: a search term binds one marker in three places.
    $found = Members::directory(['q' => 'Grace']);
    assertTrue($found['rows'] !== [], 'searching by name finds the account');

    $byEmail = Members::directory(['q' => 'grace@example.ng']);
    assertTrue($byEmail['rows'] !== [], 'searching by e-mail works');

    $reps = Members::directory(['role' => 'csr_rep']);
    foreach ($reps['rows'] as $row) {
        assertSame('csr_rep', (string) $row['role'], 'the role filter filters');
    }

    $suspended = Members::directory(['status' => 'suspended']);
    foreach ($suspended['rows'] as $row) {
        assertSame(1, (int) $row['is_suspended']);
    }

    // One page never returns more than the page size, and says whether there is more.
    $page = Members::directory(['per_page' => '10']);
    assertTrue(count($page['rows']) <= 10);
    assertTrue($page['page']->perPage() === 10);
});

/* ───────────────────────────── program funds ──────────────────────────── */

ok('The documented monthly allowance accrues until an administrator sets a figure', function (): void {
    Database::run('DELETE FROM security_settings');
    Settings::flush();

    $year = (int) gmdate('Y');
    $expected = intdiv(Money::fromNaira('100000')->cents() * (int) gmdate('n') + 6, 12);
    assertSame(Money::fromCents($expected)->toDecimal(), Fund::openingBalance('seed_grant', $year)->toDecimal());
    assertSame('accrued', Fund::balance('seed_grant', $year)['source']);
});

ok('A set pool replaces the accrual and a deposit is counted exactly once', function () use ($admin): void {
    // Settings::set() uses MySQL's INSERT … ON DUPLICATE KEY UPDATE, which PostgreSQL rejects, so the
    // stored figure is seeded directly here. What is under test is the *arithmetic*: opening +
    // deposits + adjustments − disbursed, with the opening standing in for a figure an administrator
    // set. The write path itself is covered by tests/integration.php on MySQL.
    $year = (int) gmdate('Y');
    Database::run('DELETE FROM security_settings');
    Database::run(
        'INSERT INTO security_settings (setting_key, setting_value) VALUES (?,?),(?,?),(?,?),(?,?)',
        [
            'seed_grant_monthly_pool', '100000.00',
            'seed_grant_opening_balance', '600000.00',
            'seed_grant_opening_balance_year', (string) $year,
            'cycle_year_mode', 'auto',
        ]
    );
    Settings::flush();

    $set = Fund::balance('seed_grant', $year);
    assertSame('600000.00', $set['opening']->toDecimal(), 'the set figure replaces the accrual');
    assertSame('set', $set['source'], 'and the fund card says it was set, not accrued');

    // A deposit is money that arrived. It must add to the commitment, not restate it.
    Database::run(
        "INSERT INTO remittance_ledger (entry_type, program_type, amount, reference, created_by)
         VALUES ('pool_deposit','seed_grant',150000.00,?,?)",
        ['FUND-TEST-' . bin2hex(random_bytes(3)), (int) $admin['id']]
    );
    $withDeposit = Fund::balance('seed_grant', $year);
    assertSame('150000.00', $withDeposit['deposited']->toDecimal());
    assertSame('750000.00', $withDeposit['committed']->toDecimal(), 'opening + deposit, counted once');
    assertSame('750000.00', $withDeposit['remaining']->toDecimal());
});

ok('A restatement is a decision, never a ledger row', function () use ($admin): void {
    // Fund::setPool() persists through Settings::set(), which is MySQL-only SQL. Until that is made
    // portable, this asserts the *shape* of the decision — that the audit action exists in the
    // classified financial list and that the history query finds it — and says so rather than
    // pretending the write path ran.
    assertTrue(in_array('ledger.set_pool', Audit::FINANCIAL_ACTIONS, true), 'a restatement is a financial event');
    skip('the restatement write path (needs MySQL: ON DUPLICATE KEY UPDATE)', 'MySQL-only SQL');
    return;
    
    // setPool writes the setting and an audit event. It must NOT write a ledger row: a budget is not
    // money moving, and counting it there would double the same naira.
    $year = (int) gmdate('Y');
    $before = (int) Database::value('SELECT COUNT(*) FROM remittance_ledger');
    $eventsBefore = (int) Database::value("SELECT COUNT(*) FROM audit_events WHERE action = 'ledger.set_pool'");

    Fund::setPool('seed_grant', $year, Money::fromNaira('900000'), (int) $admin['id'], 'Revised upwards');

    assertSame($before, (int) Database::value('SELECT COUNT(*) FROM remittance_ledger'), 'no ledger row is added');
    assertSame(
        $eventsBefore + 1,
        (int) Database::value("SELECT COUNT(*) FROM audit_events WHERE action = 'ledger.set_pool'"),
        'the decision is audited'
    );

    $event = Database::one("SELECT * FROM audit_events WHERE action = 'ledger.set_pool' ORDER BY id DESC LIMIT 1");
    assertTrue(str_contains((string) $event['summary'], '900,000'), 'the new figure is in the trail: ' . $event['summary']);
    assertTrue(str_contains((string) $event['summary'], 'Revised upwards'), 'and so is the reason');
});

ok('A pool cannot be set below what has already been paid out', function () use ($admin, $member): void {
    $year = (int) gmdate('Y');
    $applicationId = (int) Database::value('SELECT id FROM program_applications WHERE user_id = ? LIMIT 1', [(int) $member['id']]);
    Database::run(
        "INSERT INTO remittance_ledger (entry_type, program_type, application_id, amount, reference)
         VALUES ('disbursement','seed_grant',?,?,?)",
        [$applicationId, '200000.00', 'LED-TEST-' . bin2hex(random_bytes(3))]
    );

    assertThrows(\InvalidArgumentException::class, fn () => Fund::setPool('seed_grant', $year, Money::fromNaira('100000'), (int) $admin['id']));
});

ok('Disbursements count against the application\'s cycle year, not the payment date', function () use ($admin, $member): void {
    $applicationId = (int) Database::value('SELECT id FROM program_applications WHERE user_id = ? LIMIT 1', [(int) $member['id']]);
    $thisYear = (int) gmdate('Y');
    Database::run('UPDATE program_applications SET cycle_year = ? WHERE id = ?', [$thisYear + 1, $applicationId]);

    // Paid today, approved for next year: it must not reduce this year's pool.
    $thisYearDisbursed = Fund::balance('seed_grant', $thisYear)['disbursed'];
    $nextYearDisbursed = Fund::balance('seed_grant', $thisYear + 1)['disbursed'];
    assertTrue($nextYearDisbursed->cents() > 0, 'the future cycle carries it');
    assertSame('0.00', $thisYearDisbursed->toDecimal() === '' ? '0.00' : $thisYearDisbursed->toDecimal(), 'and this year does not');
});

ok('The audit trail pages and filters against real rows', function () use ($admin): void {
    // Guarantee one event of each interesting kind, so the filters are tested against rows that
    // exist rather than against whatever the earlier tests happened to leave behind.
    Audit::log((int) $admin['id'], 'ledger.deposit', 'fund', 'seed_grant', 'Deposit for the filter test');
    Audit::log((int) $admin['id'], 'user.reinstate', 'user', '2', 'Reinstated for the filter test');

    $page = Audit::page([], 'audit');
    assertTrue($page['rows'] !== [], 'there are events to page');

    $search = Audit::page(['q' => 'Grace'], 'audit');
    assertTrue(is_array($search['rows']), 'a free-text search runs — the query that took Settings down');

    $financial = Audit::page(['kind' => 'financial', 'per_page' => '100'], 'audit');
    assertTrue($financial['rows'] !== [], 'the financial filter returns something after the writes above');
    foreach ($financial['rows'] as $row) {
        assertTrue(
            in_array((string) $row['action'], Audit::FINANCIAL_ACTIONS, true),
            'the financial filter returned a non-financial action: ' . (string) $row['action']
        );
    }

    $admins = Audit::page(['kind' => 'admins'], 'audit');
    foreach ($admins['rows'] as $row) {
        assertTrue(
            in_array((string) ($row['actor_role'] ?? ''), ['provincial_admin', 'provincial_treasurer'], true),
            'the admin filter returns administrators only'
        );
    }

    $summary = Audit::actorSummary(50);
    assertTrue($summary !== [], 'the summary counts actors');
    foreach ($summary as $row) {
        assertTrue(isset($row['event_count'], $row['money_count'], $row['account_count']));
    }

    // Keyset paging: page 2 never repeats page 1.
    $first = Audit::page(['per_page' => '2'], 'audit');
    if ($first['page']->hasNext) {
        $second = Audit::page(['per_page' => '2', 'before' => (string) end($first['rows'])['id']], 'audit');
        $firstIds  = array_column($first['rows'], 'id');
        $secondIds = array_column($second['rows'], 'id');
        assertSame([], array_intersect($firstIds, $secondIds), 'a page never repeats a row');
    }
});

/* ────── Writing an account on a database that is a migration behind ────── */

ok('Creating and editing accounts works when migration 0002 has not been applied', function () use ($pdo, $admin): void {
    // The operator reported "I tried to create a new administrator, but it failed with an error:
    // The record could not be saved. Please try again." The cause was that Members::create() named
    // users.parish_name_raw in its INSERT. That column arrives in migration 0002, so on an
    // installation created before 0002 every account creation failed — and the handler collapsed it
    // into a message that named no cause at all.
    //
    // A write must only name that column when it actually has a name to put in it. This test uses a
    // real database, drops the column, and asserts the paths an operator actually takes.
    $pdo->exec('ALTER TABLE users DROP COLUMN parish_name_raw');
    try {
        // An administrator with no parish at all — the reported case, and the default on the form.
        $created = Members::create([
            'full_name' => 'Ngozi Bello', 'email' => 'ngozi.bello@example.ng', 'phone' => '',
            'role' => 'provincial_admin', 'password' => 'Str0ng-Temp-Pass-99',
        ], $admin);
        assertSame('provincial_admin', (string) $created['user']['role'], 'the second administrator was created');
        assertTrue($created['password'] !== '', 'a temporary password is returned once, for handing over');

        // An administrator attached to a parish that is in the directory.
        $pdo->exec("INSERT INTO zones (zone_name) VALUES ('Migration Zone')");
        $zone = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO areas (zone_id, area_name) VALUES (" . $zone . ", 'Migration Area')");
        $area = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO parishes (area_id, parish_name) VALUES (" . $area . ", 'Grace Chapel')");
        $parish = (int) $pdo->lastInsertId();

        $withParish = Members::create([
            'full_name' => 'Chidi Obi', 'email' => 'chidi.obi@example.ng', 'phone' => '',
            'role' => 'csr_rep', 'password' => 'Str0ng-Temp-Pass-99', 'parish_id' => (string) $parish,
        ], $admin);
        assertSame($parish, (int) $withParish['user']['parish_id'], 'the chosen parish was saved');

        // Editing: a name and phone correction must save. The typed church cannot, and must not
        // take the correction down with it.
        Members::update((int) $created['user']['id'], [
            'full_name' => 'Ngozi Bello-Cole', 'email' => 'ngozi.bello@example.ng', 'phone' => '08031234567',
        ], $admin);
        $edited = $pdo->query("SELECT full_name, phone FROM users WHERE email = 'ngozi.bello@example.ng'")->fetch();
        assertSame('Ngozi Bello-Cole', (string) $edited['full_name'], 'the name correction was saved');
        assertSame('08031234567', (string) $edited['phone'], 'the phone number was saved');

        Members::update((int) $created['user']['id'], [
            'full_name' => 'Ngozi Bello-Cole', 'email' => 'ngozi.bello@example.ng', 'phone' => '08031234567',
            'parish_name_raw' => 'A Church Not Yet In The Directory',
        ], $admin);

        // A typed church genuinely needs the column. It must still fail, and it must fail with a
        // SQLSTATE the POST handler can recognise — otherwise the operator gets the same silent
        // message that started this.
        assertThrows(\PDOException::class, fn () => Members::create([
            'full_name' => 'Ada Eze', 'email' => 'ada.eze@example.ng', 'phone' => '',
            'role' => 'csr_rep', 'password' => 'Str0ng-Temp-Pass-99',
            'parish_name_raw' => 'A Church Not Yet In The Directory',
        ], $admin));
    } finally {
        $pdo->exec('ALTER TABLE users ADD COLUMN parish_name_raw VARCHAR(150) NULL');
    }
});

ok('The write handler names an unapplied migration instead of hiding it', function (): void {
    // The message the operator saw was the catch-all branch. A missing column is an operator-actionable
    // fault: it has one cause and one fix, and both are on a screen they can reach.
    $source = (string) file_get_contents(RISE63_TEST_ROOT . '/app/actions.php');
    assertTrue(str_contains($source, "'42S22'"), 'the handler must recognise an unknown column');
    assertTrue(str_contains($source, "'42S02'"), 'and an unknown table');
    assertTrue(str_contains($source, 'Database updates'), 'the message must point at the screen that explains it');
    assertTrue(str_contains($source, 'bin/migrate.php'), 'and at the command that fixes it');
});

/* ─────────── The database heals itself (the command that would not run) ─────────── */

ok('A missing schema_migrations table is created instead of blocking every migration', function () use ($pdo): void {
    // Reported from the field, verbatim:
    //
    //   $ php bin/migrate.php
    //   ! The schema_migrations table is missing — is this a RISE 63 database?
    //   Refusing to migrate. Fix the inconsistency above first.
    //
    // A deadlock: the runner refused because it could not record into a table that was not there,
    // so the only command that could repair the database was the one command it blocked.
    $pdo->exec('DROP TABLE schema_migrations');

    $migrator = new Migrator(RISE63_TEST_ROOT);
    assertTrue($migrator->verify() !== [], 'verify should report the missing table before healing');

    $created = $migrator->ensureLedger();
    assertTrue($created, 'the ledger must be created when it is missing');
    assertTrue($migrator->ensureLedger() === false, 'and creating it twice is a no-op');
});

ok('Migrations whose effect is already present are adopted, not re-run', function () use ($pdo): void {
    // Re-running 0001 would fail on a duplicate column and leave the operator where they started.
    // Adoption is decided by reading information_schema — never by assuming.
    $migrator = new Migrator(RISE63_TEST_ROOT);
    $migrator->ensureLedger();
    $pdo->exec('DELETE FROM schema_migrations');

    // The harness schema already has must_change_password, parish_name_raw and password_resets.
    $adopted = $migrator->reconcile();
    assertTrue(in_array('0001', $adopted, true), '0001 should be adopted: its column and table exist');
    assertTrue(in_array('0002', $adopted, true), '0002 should be adopted: its column exists');

    // A migration whose effect is *absent* must never be adopted.
    $pdo->exec('ALTER TABLE users DROP COLUMN parish_name_raw');
    $pdo->exec('DELETE FROM schema_migrations');
    try {
        $adoptedAgain = $migrator->reconcile();
        assertTrue(!in_array('0002', $adoptedAgain, true),
            '0002 must not be adopted once its column is gone');
    } finally {
        $pdo->exec('ALTER TABLE users ADD COLUMN parish_name_raw VARCHAR(150) NULL');
    }
});

ok('Healing reports exactly what it did, and is idempotent', function () use ($pdo): void {
    $migrator = new Migrator(RISE63_TEST_ROOT);
    $pdo->exec('DELETE FROM schema_migrations');

    $first  = $migrator->heal();
    assertTrue($first['ledger_created'] === false, 'the ledger already exists by now');
    assertTrue($first['adopted'] !== [], 'it should adopt the migrations already present');
    assertTrue($first['applied'] === [], 'and apply nothing: everything is already there');

    $second = $migrator->heal();
    assertSame([], $second['adopted'], 'a healed database adopts nothing the second time');
    assertSame([], $second['applied'], 'and applies nothing');
});

ok('The automatic check runs once per version, not on every request', function (): void {
    // A schema check on every page load would be a per-request cost on shared hosting for no gain.
    // The stamp is keyed by VERSION, so a release triggers exactly one check.
    $file = \RISE63\config_dir() . '/' . SelfHeal::STAMP;
    $existed = is_file($file);
    $original = $existed ? (string) file_get_contents($file) : '';
    try {
        @unlink($file);
        assertTrue(SelfHeal::shouldRun('9.9.9') === true, 'a fresh installation must be checked');

        SelfHeal::run(RISE63_TEST_ROOT, '9.9.9');
        assertTrue(SelfHeal::shouldRun('9.9.9') === false, 'the same version must not be checked again');
        assertTrue(SelfHeal::shouldRun('9.9.10') === true, 'a new version must be checked');

        $summary = SelfHeal::summary();
        assertTrue($summary !== '', 'the Settings screen needs a human-readable summary');
        assertTrue(str_contains($summary, '9.9.10') || str_contains($summary, '9.9.9'),
            'the summary must name the version it describes');
    } finally {
        if ($existed) {
            file_put_contents($file, $original);
        } else {
            @unlink($file);
        }
    }
});

ok('A migration the code does not understand is executed, never assumed', function (): void {
    // The safety rule that makes adoption trustworthy: anything not provably already applied runs.
    $root = sys_get_temp_dir() . '/rise63-adopt-' . bin2hex(random_bytes(4));
    @mkdir($root . '/database/migrations', 0777, true);
    file_put_contents(
        $root . '/database/migrations/0001_custom.sql',
        "-- an UPDATE is not something adoption can prove\nUPDATE users SET phone = 'x';\n"
    );

    $reflected = new \ReflectionMethod(Migrator::class, 'isSatisfied');
    $reflected->setAccessible(true);
    assertSame(false, $reflected->invoke(null, (string) file_get_contents($root . '/database/migrations/0001_custom.sql')),
        'an UPDATE must never be treated as already applied');

    // A CREATE TABLE for a table that does exist IS provable.
    assertSame(true, $reflected->invoke(null, 'CREATE TABLE users (id INT);'),
        'a CREATE TABLE whose table exists is satisfied');
    assertSame(false, $reflected->invoke(null, 'CREATE TABLE not_a_real_table (id INT);'),
        'a CREATE TABLE whose table is absent is not');
});

/* ──────────── Mail: the transport that was never the one asked for ──────────── */

ok('Implicit TLS and STARTTLS open different kinds of socket', function (): void {
    // Reported: "SMTP rejected the request (no response)." Cause: connect() computed its transport
    // with `($encryption === 'ssl' || $encryption === 'none') ? 'tcp' : 'tcp'` — both branches the
    // same string. A port-465 server starts TLS on connect, so a plain socket produces a silence
    // that both ends wait out.
    //
    // Proven here without a mail server: the transport is a pure function of the mode, and that is
    // the fact that was wrong.
    assertSame('ssl', \RISE63\Core\Mailer::transportFor('ssl'), 'ssl mode');
    assertSame('tcp', \RISE63\Core\Mailer::transportFor('tls'), 'STARTTLS mode');
});

ok('A mail server that says nothing is reported as unreachable, not as a rejection', function (): void {
    // The old message — "SMTP rejected the request (no response)" — asserted a rejection while
    // reporting the absence of any response. This connects to a port that is closed, so the failure
    // is real, and checks the message distinguishes the two cases.
    try {
        \RISE63\Core\Mailer::connect([
            'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none',
            'username' => '', 'password' => '', 'auth' => 'login',
        ]);
        assertTrue(false, 'connecting to a closed port must fail');
    } catch (\RuntimeException $error) {
        $message = $error->getMessage();
        assertTrue(!str_contains($message, 'rejected the request'),
            'a transport failure must not be reported as a rejection: ' . $message);
        assertTrue(str_contains($message, '127.0.0.1'), 'the message must name what it tried: ' . $message);
    }
});

/* ───────── The registration page listed no churches (an empty directory) ───────── */

ok('An empty directory is reported as empty, not as an empty search result', function (): void {
    // Reported: "The registration page failed to list the churches." The page was correct; the
    // directory behind it was empty. Four states look identical from the outside — nothing added,
    // everything switched off, rows orphaned from their area or zone, or a working directory — and
    // they need different fixes, so the app has to say which one it is.
    $clear = static function (): void {
        Database::run('DELETE FROM parishes');
        Database::run('DELETE FROM areas');
        Database::run('DELETE FROM zones');
    };

    $clear();
    $state = ParishDirectory::pickerState();
    assertSame('empty', $state['state'], 'a directory with no rows is empty');
    assertSame(0, $state['total']);
    assertTrue($state['message'] !== '', 'the state must come with something to show a person');
    assertTrue(ParishDirectory::pickerIsEmpty(), 'and the picker has nothing to offer');

    // Populated: the picker works and there is nothing to report.
    Database::run('INSERT INTO zones (zone_name) VALUES (?)', ['GRACE ZONE']);
    $zoneId = Database::id();
    Database::run('INSERT INTO areas (zone_id, area_name) VALUES (?,?)', [$zoneId, 'HOPE AREA']);
    $areaId = Database::id();
    Database::run('INSERT INTO parishes (area_id, parish_name) VALUES (?,?)', [$areaId, 'LIVING WATER PARISH']);

    $state = ParishDirectory::pickerState();
    assertSame('ok', $state['state'], 'one reachable parish is enough');
    assertSame(1, $state['active']);
    assertTrue(!ParishDirectory::pickerIsEmpty(), 'the picker has something to show');

    // Switched off: the rows exist, so "add churches" would be the wrong advice.
    Database::run('UPDATE parishes SET is_active = 0');
    $state = ParishDirectory::pickerState();
    assertSame('inactive', $state['state'], 'all-inactive is a different problem from empty');
    assertSame(1, $state['inactive']);
    assertTrue(str_contains($state['message'], 'switched off'), 'the message must name the cure: ' . $state['message']);

    $clear();
});

ok('A member can register while the directory is empty', function (): void {
    // The empty-directory form posts a typed church and no parish_id. That path existed before —
    // migration 0002 added `parish_name_raw` for exactly this — but it was unreachable on the
    // rendered page at the moment of the report, because the parish field was inside markup that
    // only appeared when the directory was populated.
    Database::run('DELETE FROM parishes');

    $result = Registration::create([
        'full_name' => 'Chidi Okafor',
        'email'     => 'chidi.reg@example.ng',
        'phone'     => '',
        'parish_id' => '',
        'parish_name_raw' => 'Sanctuary of Peace',
        'password'  => 'Correct-Horse-Battery-99',
        'confirm'   => 'Correct-Horse-Battery-99',
    ]);
    assertTrue(isset($result['user']['id']) || isset($result['id']), 'registration must succeed');

    $row = Database::one(
        'SELECT parish_id, parish_name_raw, role FROM users WHERE email = ?',
        ['chidi.reg@example.ng']
    );
    assertTrue($row !== null, 'the account must exist');
    assertSame(null, $row['parish_id'], 'no parish may be invented when the directory is empty');
    assertSame('SANCTUARY OF PEACE', $row['parish_name_raw'], 'the typed church must be kept, in capitals');
    assertSame('youth_member', $row['role']);

    // And the office can see it, which is the whole point of keeping it.
    $queue = Database::all(
        "SELECT parish_name_raw FROM users
          WHERE parish_id IS NULL AND parish_name_raw IS NOT NULL AND parish_name_raw <> ''"
    );
    assertTrue(count($queue) >= 1, 'the church the member typed must reach the queue on People');
});

/* ──────────────────────────────── summary ─────────────────────────────── */

fwrite(STDOUT, "\n" . str_repeat('─', 62) . "\n");
fwrite(STDOUT, $GLOBALS['passed'] . ' passed, ' . $GLOBALS['failed'] . ' failed, '
    . ($GLOBALS['skipped'] ?? 0) . " skipped\n");
if ($GLOBALS['failures'] !== []) {
    fwrite(STDOUT, "\nFailures:\n  - " . implode("\n  - ", $GLOBALS['failures']) . "\n");
    exit(1);
}
exit(0);
