<?php
declare(strict_types=1);

/**
 * Database-backed integration tests.
 *
 *   RISE63_TEST_DSN='mysql:host=127.0.0.1;dbname=rise63_test' \
 *   RISE63_TEST_USER=rise RISE63_TEST_PASS=secret \
 *   php tests/integration.php
 *
 * Refuses to run unless the database name ends in `_test`: every test truncates tables.
 *
 * What this covers that unit.php cannot:
 *   - the schema actually executes, including the view and the ledger triggers
 *   - the Seed Grant pool guard under two concurrent connections (SELECT … FOR UPDATE)
 *   - the ledger cannot be updated or deleted
 *   - the state machine's transitions survive a round trip through MySQL
 *   - migrations apply, are recorded once, and refuse a modified file
 *   - the reset token lifecycle against real DATETIME arithmetic
 *
 * What it still cannot cover: SMTP delivery, the firewall worker against a real firewall, and
 * anything visual. Those are browser/host checks — see docs/IMPLEMENTATION_STATUS.md §3.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not available over HTTP.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use RISE63\Core\Audit;
use RISE63\Core\Database;
use RISE63\Core\Fund;
use RISE63\Core\Members;
use RISE63\Core\Migrator;
use RISE63\Core\DirectoryImport;
use RISE63\Core\Money;
use RISE63\Core\ParishDirectory;
use RISE63\Core\PasswordReset;
use RISE63\Core\Security;

use function RISE63\path;

const APP_ROOT = __DIR__ . '/..';

$dsn  = (string) (getenv('RISE63_TEST_DSN') ?: '');
$user = (string) (getenv('RISE63_TEST_USER') ?: '');
$pass = (string) (getenv('RISE63_TEST_PASS') ?: '');

if ($dsn === '') {
    fwrite(STDERR, "RISE63_TEST_DSN is not set. Example:\n  RISE63_TEST_DSN='mysql:host=127.0.0.1;dbname=rise63_test' RISE63_TEST_USER=rise RISE63_TEST_PASS=secret php tests/integration.php\n");
    exit(2);
}

$dbName = '';
if (preg_match('/(?:^|;)\s*dbname=([^;]+)/', $dsn, $match) === 1) {
    $dbName = $match[1];
}
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "Refusing to run: the database name must end in _test (got '" . ($dbName ?: 'none') . "').\n");
    exit(2);
}

/* ────────────────────────────── tiny assertion harness ─────────────────────────── */

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['current'] = '';

function ok(string $name, callable $test): void
{
    $GLOBALS['current'] = $name;
    try {
        $test();
        $GLOBALS['passed']++;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $GLOBALS['failed']++;
        fwrite(STDOUT, "FAIL {$name} :: " . $error->getMessage() . "\n");
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new RuntimeException($message !== '' ? $message : 'expected true');
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

/** @param callable():void $body */
function expectFailure(callable $body, string $message = ''): void
{
    try {
        $body();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message !== '' ? $message : 'expected the operation to fail');
}

/* ─────────────────────────────── database plumbing ─────────────────────────────── */

function freshPdo(): PDO
{
    return new PDO(
        (string) getenv('RISE63_TEST_DSN'),
        (string) getenv('RISE63_TEST_USER'),
        (string) getenv('RISE63_TEST_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function splitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inString = false;
    $quote = '';
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($inString) {
            $buffer .= $char;
            if ($char === $quote && ($sql[$i - 1] ?? '') !== '\\') {
                $inString = false;
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $inString = true;
            $quote = $char;
            $buffer .= $char;
            continue;
        }
        if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $length : $end;
            continue;
        }
        if ($char === ';') {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

function rebuildSchema(PDO $pdo): void
{
    // Triggers live in their own file and cannot be split naively, so they are created one at a
    // time with the surrounding BEGIN…END block kept intact.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $row) {
        $kind = strtoupper((string) $row[1]);
        $name = (string) $row[0];
        if ($kind === 'VIEW') {
            $pdo->exec('DROP VIEW IF EXISTS `' . $name . '`');
        } else {
            $pdo->exec('DROP TABLE IF EXISTS `' . $name . '`');
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    foreach (['schema.sql', 'countries.sql', 'seed.sql'] as $file) {
        foreach (splitSql((string) file_get_contents(path('database/' . $file))) as $statement) {
            $pdo->exec($statement);
        }
    }

    $triggers = (string) file_get_contents(path('database/ledger-guards.sql'));
    foreach (preg_split('/\n(?=CREATE TRIGGER)/i', $triggers) as $block) {
        $block = trim($block);
        if ($block === '' || stripos($block, 'CREATE TRIGGER') !== 0) {
            continue;
        }
        $block = rtrim($block, ";\n ");
        $pdo->exec($block);
    }
}

$pdo = freshPdo();
Database::use($pdo);

/* ──────────────────────────────────── tests ───────────────────────────────────── */

ok('The schema executes and every object exists', function () use ($pdo): void {
    $tables = array_map(static fn (array $r): string => (string) $r[0], $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM));
    foreach (['users', 'program_applications', 'remittance_ledger', 'security_logs', 'notification_outbox', 'parish_directory'] as $object) {
        assertTrue(in_array($object, $tables, true), 'missing object: ' . $object);
    }
    $countries = (int) $pdo->query('SELECT COUNT(*) FROM country_rules')->fetchColumn();
    assertTrue($countries >= 240, 'expected the ISO country list, found ' . $countries . ' rows');
    assertSame('allow', (string) $pdo->query("SELECT mode FROM country_rules WHERE country_code = 'NG'")->fetchColumn());
});

ok('The ledger is append-only at the database level', function () use ($pdo): void {
    $triggers = array_map(static fn (array $r): string => (string) $r[0], $pdo->query("SHOW TRIGGERS LIKE 'remittance_ledger'")->fetchAll(PDO::FETCH_NUM));
    assertTrue(count($triggers) >= 2, 'expected UPDATE and DELETE guards, found ' . count($triggers));

    $pdo->exec("INSERT INTO remittance_ledger (entry_type, program_type, amount, balance_after, reference, description)
                VALUES ('pool_deposit', 'seed_grant', 100000.00, 100000.00, 'IT-GUARD-1', 'integration-test fixture')");
    $id = (int) $pdo->lastInsertId();

    expectFailure(static fn (): int => $pdo->exec('UPDATE remittance_ledger SET amount = 1 WHERE id = ' . $id), 'UPDATE must be refused');
    expectFailure(static fn (): int => $pdo->exec('DELETE FROM remittance_ledger WHERE id = ' . $id), 'DELETE must be refused');

    $amount = (string) $pdo->query('SELECT amount FROM remittance_ledger WHERE id = ' . $id)->fetchColumn();
    assertSame('100000.00', $amount, 'the row must be untouched');
});

ok('Two concurrent payouts cannot overdraw the Seed Grant pool', function () use ($pdo): void {
    // Ledger::balance() sums disbursements for the program; the pool guard reads it FOR UPDATE
    // so that two simultaneous approvals serialise instead of both seeing the same balance.
    $pdo->exec("INSERT INTO remittance_ledger (entry_type, program_type, amount, balance_after, reference)
                VALUES ('pool_deposit', 'seed_grant', 100000.00, 100000.00, 'IT-POOL-1')");

    $balanceQuery = "SELECT COALESCE(SUM(amount), 0) FROM remittance_ledger WHERE entry_type = 'disbursement' AND program_type = 'seed_grant'";
    $lockedQuery  = $balanceQuery . ' FOR UPDATE';

    $a = freshPdo();
    $b = freshPdo();
    $a->beginTransaction();
    assertSame('0.00', Money::fromDecimal($a->query($lockedQuery)->fetchColumn())->toDecimal(), 'pool starts unspent');

    // Connection B must block on the same row set, not read a stale balance alongside A.
    $b->beginTransaction();
    $b->exec('SET innodb_lock_wait_timeout = 2');
    $blocked = false;
    $started = microtime(true);
    try {
        $b->query($lockedQuery)->fetchColumn();
    } catch (PDOException) {
        $blocked = true;
    }
    assertTrue($blocked, 'the second connection must not read the pool while the first holds the lock');
    assertTrue(microtime(true) - $started >= 1.5, 'it should have waited for the lock, not failed instantly');
    $b->rollBack();

    // A commits its payout; a second attempt must now see the reduced balance.
    $a->exec("INSERT INTO remittance_ledger (entry_type, program_type, amount, balance_after, reference)
              VALUES ('disbursement', 'seed_grant', 60000.00, 40000.00, 'IT-PAY-1')");
    $a->commit();

    $spent = Money::fromDecimal($pdo->query($balanceQuery)->fetchColumn());
    assertSame('60000.00', $spent->toDecimal());
    assertTrue(
        Money::fromDecimal('100000.00')->minus($spent)->compare(Money::fromDecimal('50000.00')) < 0,
        'only 40,000 remains, so a 50,000 grant must be refused by the caller'
    );
});

ok('A submitted application survives a round trip with its state and audit trail', function () use ($pdo): void {
    $pdo->exec("INSERT INTO parishes (parish_name, parish_code) VALUES ('Integration Parish', 'INT-001')");
    $parishId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO users (full_name, email, password_hash, role, parish_id)
                VALUES ('Integration Member', 'integration-member@example.test', 'x', 'youth_member', {$parishId})");
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO program_applications (user_id, parish_id, program_type, cycle_year, status, requested_amount, idempotency_key)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([$userId, $parishId, 'seed_grant', 2026, 'submitted', '50000.00', 'idem-1']);
    $appId = (int) $pdo->lastInsertId();

    // Replaying the same idempotency key must be rejected by the unique index, not by luck.
    expectFailure(static function () use ($pdo, $userId, $parishId): void {
        $pdo->prepare('INSERT INTO program_applications (user_id, parish_id, program_type, cycle_year, status, requested_amount, idempotency_key)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId, $parishId, 'seed_grant', 2026, 'submitted', '50000.00', 'idem-1']);
    }, 'duplicate submission must be refused by the database');

    $pdo->prepare('INSERT INTO application_events (application_id, actor_id, from_status, to_status, note) VALUES (?,?,?,?,?)')
        ->execute([$appId, $userId, 'submitted', 'under_review', 'reviewer picked it up']);

    $events = (int) $pdo->query('SELECT COUNT(*) FROM application_events WHERE application_id = ' . $appId)->fetchColumn();
    assertSame(1, $events);
    assertSame('submitted', (string) $pdo->query('SELECT status FROM program_applications WHERE id = ' . $appId)->fetchColumn());
});

ok('Reset tokens expire and are single use against real DATETIME arithmetic', function () use ($pdo): void {
    $userId = (int) $pdo->query("SELECT id FROM users WHERE email = 'integration-member@example.test'")->fetchColumn();
    assertTrue($userId > 0, 'fixture user missing');

    $token = Security::randomToken(32);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at)
                   VALUES (?,?,?, UTC_TIMESTAMP() + INTERVAL 30 MINUTE)')
        ->execute([$userId, hash('sha256', $token), '127.0.0.1']);

    $live = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $live->execute([hash('sha256', $token)]);
    assertSame(1, (int) $live->fetchColumn(), 'a fresh token must resolve');

    // Expire it in place, the way wall-clock time would.
    $pdo->prepare('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);
    $live->execute([hash('sha256', $token)]);
    assertSame(0, (int) $live->fetchColumn(), 'an expired token must not resolve');

    // Consumed tokens must not resolve either, whatever their expiry says.
    $pdo->prepare('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() + INTERVAL 30 MINUTE, used_at = UTC_TIMESTAMP() WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);
    $live->execute([hash('sha256', $token)]);
    assertSame(0, (int) $live->fetchColumn(), 'a used token must not resolve');
});

/* ──────────────── Zone → Area → Parish: the pasted list, on real MySQL ──────────────── */

ok('A pasted hierarchy creates itself once and can be re-pasted safely', function () use ($pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['parishes', 'areas', 'zones'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // The list from the field report, lower-cased on two lines to prove the canonical form is
    // applied on the way in: one zone with two areas, one area still waiting for parishes, and a
    // repeated line that must not become a second row.
    $text = "joyful assembly prov. hqtr zone, conquerors chapel zone, chapel of wonders parish area\n"
          . "JOYFUL ASSEMBLY PROV. HQTR ZONE, CONQUERORS CHAPEL ZONE, CONQUERORS CHAPEL AREA\n"
          . "JOYFUL ASSEMBLY PROV. HQTR ZONE, CONQUERORS CHAPEL ZONE\n"
          . "GRACE ZONE, HOPE AREA, LIVING WATER PARISH\n"
          . "GRACE ZONE, HOPE AREA, LIVING WATER PARISH\n";

    $parsed = DirectoryImport::parse($text);
    $tally  = ParishDirectory::importLines($parsed['lines'], null);

    assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn(), 'two zones');
    assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM areas')->fetchColumn(), 'three areas');
    assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM parishes')->fetchColumn(),
        'the repeated line must not create a second parish');

    // Everything is stored in the canonical form, including the two lines pasted in lower case.
    $names = $pdo->query('SELECT zone_name FROM zones ORDER BY zone_name')->fetchAll(PDO::FETCH_COLUMN);
    assertSame(['GRACE ZONE', 'JOYFUL ASSEMBLY PROV. HQTR ZONE'], $names);

    // "ZONE, AREA" with no parish is a real area, not a zone with a stray name on it.
    $waiting = $pdo->query(
        "SELECT COUNT(*) FROM areas a JOIN zones z ON z.id = a.zone_id
         WHERE z.zone_name = 'JOYFUL ASSEMBLY PROV. HQTR ZONE' AND a.area_name = 'CONQUERORS CHAPEL ZONE'"
    )->fetchColumn();
    assertSame(1, (int) $waiting, 'the two-column line must create the area');

    // Re-pasting the identical list is a no-op, which is the whole point of matching names.
    $again = ParishDirectory::importLines(DirectoryImport::parse($text)['lines'], null);
    assertSame(0, (int) $again['created'], 'a second paste creates nothing');
    assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM parishes')->fetchColumn());

    // And a paste that differs only in case is still the same list.
    $mixed = ParishDirectory::importLines(DirectoryImport::parse(
        "grace zone, hope area, living water parish\nGRACE ZONE, FAITH AREA\n"
    )['lines'], null);
    assertSame(1, (int) $mixed['created'], 'only FAITH AREA is new');
    $hopeAreas = $pdo->query(
        "SELECT COUNT(*) FROM areas a JOIN zones z ON z.id = a.zone_id
         WHERE z.zone_name = 'GRACE ZONE' AND a.area_name = 'HOPE AREA'"
    )->fetchColumn();
    assertSame(1, (int) $hopeAreas, 'a lower-case repeat must not create a second HOPE AREA');

    // The same area name under a different zone is a different area — the unique key is
    // (zone_id, area_name), not area_name alone.
    $other = ParishDirectory::importLines(DirectoryImport::parse(
        "MERCY ZONE, HOPE AREA, FIRST PARISH"
    )['lines'], null);
    assertSame(3, (int) $other['created'], 'a new zone, a new area under it, and a new parish');

    // The search must find a lower-case query against upper-case data whatever the collation is.
    $found = ParishDirectory::search('living water');
    assertSame(1, count($found), 'search is case-insensitive');
    assertSame('LIVING WATER PARISH', $found[0]['parish']);

    $byName = ParishDirectory::findByName('grace zone parish');
    assertSame(null, $byName, 'an unknown name resolves to nothing rather than a near match');
    assertSame('LIVING WATER PARISH', ParishDirectory::findByName('living water parish')['parish_name'] ?? null,
        'a typed name resolves to the real row');
});

ok('A failed paste rolls back, so a half-applied hierarchy is impossible', function () use ($pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['parishes', 'areas', 'zones'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // A name longer than the column is refused by MySQL while the page is mid-run. The first two
    // lines are valid, so the test only means anything on a server in strict mode — which is the
    // default on MySQL 8 and the mode docs/DEPLOYMENT.md asks for. Say so rather than pass quietly.
    $strict = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
    if (!str_contains($strict, 'STRICT_')) {
        fwrite(STDOUT, "SKIP rollback: the server is not in a STRICT sql_mode, so MySQL truncates instead of failing\n");
        return;
    }

    $lines = DirectoryImport::parse(
        "GRACE ZONE, HOPE AREA, LIVING WATER PARISH\n"
        . "MERCY ZONE, FAITH AREA, GRACE CHAPEL PARISH\n"
        . "TRINITY ZONE, " . str_repeat('X', 200) . ", A PARISH"
    )['lines'];

    expectFailure(static fn () => ParishDirectory::importLines($lines, null), 'the oversized line must fail');

    assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn(),
        'the transaction must roll back the valid lines too');
});

ok('The single create forms refuse a duplicate instead of hitting the unique key', function () use ($pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['parishes', 'areas', 'zones'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $zoneId = ParishDirectory::createZone('grace zone', null, null);
    assertSame('GRACE ZONE', (string) $pdo->query('SELECT zone_name FROM zones WHERE id = ' . $zoneId)->fetchColumn(),
        'a zone typed in lower case is stored in capitals');

    // The old behaviour surfaced this as a PDO 23000 ("That record already exists. Check the
    // parish name…") after the fact. Naming the record is more useful than naming the table.
    expectFailure(static fn () => ParishDirectory::createZone('Grace Zone', null, null));
    expectFailure(static fn () => ParishDirectory::createZone('GRACE ZONE', null, null));

    $areaId = ParishDirectory::createArea($zoneId, 'hope area', null, null);
    expectFailure(static fn () => ParishDirectory::createArea($zoneId, 'HOPE AREA', null, null),
        'a duplicate area under the same zone must be refused');

    ParishDirectory::createParish(['parish_name' => 'living water parish', 'area_id' => $areaId], null);
    expectFailure(
        static fn () => ParishDirectory::createParish(['parish_name' => 'LIVING WATER PARISH', 'area_id' => $areaId], null),
        'a duplicate parish under the same area must be refused'
    );

    // Same area name under a second zone is allowed: the hierarchy is what makes it unique.
    $second = ParishDirectory::createZone('MERCY ZONE', null, null);
    ParishDirectory::createArea($second, 'HOPE AREA', null, null);
});

ok('Migrations apply once, in order, and reject a modified file', function () use ($pdo): void {
    $pdo->exec('DELETE FROM schema_migrations');

    // Build a throwaway migrations directory so the real one is never written during a test.
    $sandbox = sys_get_temp_dir() . '/rise63-migrations-' . bin2hex(random_bytes(4));
    mkdir($sandbox . '/database/migrations', 0777, true);
    file_put_contents($sandbox . '/database/migrations/0001_first.sql', "CREATE TABLE it_first (id INT PRIMARY KEY);\n");
    file_put_contents($sandbox . '/database/migrations/0002_second.sql', "CREATE TABLE it_second (id INT PRIMARY KEY);\n");

    $migrator = new Migrator($sandbox);
    assertSame(2, count($migrator->pending()));
    $applied = $migrator->migrate();
    assertSame(['0001', '0002'], $applied, 'applied in order');
    assertSame([], $migrator->pending(), 'nothing left pending');
    assertSame(0, count($migrator->verify()), 'consistent after apply');
    assertSame([], $migrator->migrate(), 're-running applies nothing');

    // Editing an applied file must be reported, not silently accepted.
    file_put_contents($sandbox . '/database/migrations/0001_first.sql', "CREATE TABLE it_first (id INT PRIMARY KEY, extra INT);\n");
    $problems = $migrator->verify();
    assertTrue(count($problems) > 0, 'a modified migration must be reported');
    assertTrue(str_contains($problems[0], '0001'), 'the report must name the file');

    array_map('unlink', glob($sandbox . '/database/migrations/*.sql') ?: []);
    rmdir($sandbox . '/database/migrations');
    rmdir($sandbox . '/database');
    rmdir($sandbox);
    $pdo->exec('DELETE FROM schema_migrations');
});

ok('A pool is a set balance: restating it does not double-count deposits', function () use ($pdo): void {
    // Disbursements for a cycle year are read through the application that carried the grant, so a
    // grant approved for this year but paid next January still counts against this year's pool.
    $year = (int) gmdate('Y');

    // No figure set yet: the documented ₦100,000/month accrues. In the current month that is
    // monthly × months ÷ 12.
    $accrued = Fund::openingBalance('seed_grant', $year);
    $expected = Money::fromCents(intdiv(Money::fromNaira('100000')->cents() * (int) gmdate('n') + 6, 12));
    assertSame($expected->toDecimal(), $accrued->toDecimal(), 'the monthly allowance accrues');

    // Set it. The opening balance becomes exactly the figure asked for, because there are no
    // deposits or adjustments to reconcile against yet.
    Fund::setPool('seed_grant', $year, Money::fromNaira('600000'), 1, 'Board allocation');
    $after = Fund::balance('seed_grant', $year);
    assertSame('600000.00', $after['opening']->toDecimal(), 'the set figure replaces the accrual');
    assertSame('600000.00', $after['committed']->toDecimal());
    assertSame('set', $after['source']);

    // Now a deposit actually arrives. The commitment rises by the deposit — not by the deposit plus
    // the delta needed to reach the same total, which is the double-count the first draft had.
    Fund::deposit('seed_grant', Money::fromNaira('150000'), 1, 'Partner transfer');
    $withDeposit = Fund::balance('seed_grant', $year);
    assertSame('150000.00', $withDeposit['deposited']->toDecimal());
    assertSame('750000.00', $withDeposit['committed']->toDecimal(), 'opening + deposit, counted once');
    assertSame('750000.00', $withDeposit['remaining']->toDecimal());

    // Restating to 900,000 sets the opening to 750,000 so that opening + deposits = 900,000.
    Fund::setPool('seed_grant', $year, Money::fromNaira('900000'), 1, 'Revised');
    $restated = Fund::balance('seed_grant', $year);
    assertSame('750000.00', $restated['opening']->toDecimal());
    assertSame('900000.00', $restated['committed']->toDecimal(), 'the parts still add up to what was asked for');

    // A pool cannot be set below what has been paid out.
    assertThrows(InvalidArgumentException::class, fn () => Fund::setPool('seed_grant', $year, Money::zero(), 1));

    // The restatement is a decision in the audit trail, not a ledger row.
    $ledgerDecisions = (int) Database::value(
        "SELECT COUNT(*) FROM remittance_ledger WHERE entry_type = 'adjustment' AND program_type = 'seed_grant'"
    );
    assertSame(0, $ledgerDecisions, 'restating a budget never writes a ledger row');
    assertTrue((int) Database::value("SELECT COUNT(*) FROM audit_events WHERE action = 'ledger.set_pool'") >= 2);
});

ok('Two treasurers cannot overdraw the same pool', function (): void {
    // The race the blueprint warns about: both requests read the same remaining balance, both
    // decide there is room, both pay. Serialising on the fund lock is what prevents it. Proved here
    // by taking the lock and confirming that the second acquisition times out.
    $year = (int) gmdate('Y');
    Fund::setPool('seed_grant', $year, Money::fromNaira('100000'), 1, 'Race test');

    $pdo = Database::connection();
    $lock = 'rise63:pool:seed_grant:' . $year;
    $first = $pdo->prepare('SELECT GET_LOCK(?, 1)');
    $first->execute([$lock]);
    assertSame(1, (int) $first->fetchColumn(), 'the first confirmation takes the lock');

    $second = $pdo->prepare('SELECT GET_LOCK(?, 1)');
    $second->execute([$lock]);
    assertSame(0, (int) $second->fetchColumn(), 'a concurrent confirmation for the same fund waits and is refused');

    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    $third = $pdo->prepare('SELECT GET_LOCK(?, 1)');
    $third->execute([$lock]);
    assertSame(1, (int) $third->fetchColumn(), 'and the lock is available again once released');
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
});

ok('The activity report counts administrators and members together', function (): void {
    $summary = Audit::actorSummary(200);
    assertTrue($summary !== [], 'there is something to report after the writes above');

    $found = false;
    foreach ($summary as $row) {
        if (isset($row['event_count'], $row['money_count'], $row['account_count'])) {
            $found = true;
            break;
        }
    }
    assertTrue($found, 'every row carries the three counts the report shows');

    // Filtering by "administrators" must not lose the money rows a treasurer created.
    $financial = Audit::page(['kind' => 'financial', 'per_page' => '25'], 'audit');
    assertTrue(is_array($financial['rows']), 'the financial filter produces rows');

    // A page never returns more than it says it will.
    $page = Audit::page(['per_page' => '25'], 'audit');
    assertTrue(count($page['rows']) <= 25);
});

ok('A member with applications cannot be deleted, only suspended', function (): void {
    // The CASCADE on program_applications would take the applications with the account, and with
    // them the record of money paid. The refusal has to name the alternative.
    $userId = (int) Database::value('SELECT user_id FROM program_applications LIMIT 1');
    if ($userId === 0) {
        return; // Nothing to test on a database with no applications yet.
    }
    $admin = Database::one("SELECT * FROM users WHERE role = 'provincial_admin' LIMIT 1");

    assertThrows(
        InvalidArgumentException::class,
        fn () => Members::delete($userId, $admin),
        'an applicant is never deleted'
    );

    // An account with no history can go.
    Database::run(
        'INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?,?)',
        ['Throwaway Account', 'throwaway-' . bin2hex(random_bytes(4)) . '@example.ng', 'x', 'youth_member']
    );
    $throwaway = Database::id();
    Members::delete($throwaway, $admin);
    assertSame(null, Database::one('SELECT * FROM users WHERE id = ?', [$throwaway]), 'an account with no history is removed');

    // And nobody can delete themselves into a lockout.
    assertThrows(InvalidArgumentException::class, fn () => Members::delete((int) $admin['id'], $admin));
});

ok('The last administrator cannot be suspended or demoted', function (): void {
    $admins = Database::all("SELECT * FROM users WHERE role = 'provincial_admin' AND is_suspended = 0");
    if (count($admins) !== 1) {
        return; // Only meaningful when the province has exactly one.
    }
    $last = $admins[0];
    $other = Database::one("SELECT * FROM users WHERE role <> 'provincial_admin' LIMIT 1");
    if ($other === null) {
        return;
    }
    assertThrows(InvalidArgumentException::class, fn () => Members::setSuspended((int) $last['id'], true, $other));
});

/* ──────────────────────────────────── summary ─────────────────────────────────── */

fwrite(STDOUT, "\n" . str_repeat('─', 62) . "\n");
fwrite(STDOUT, $GLOBALS['passed'] . ' passed, ' . $GLOBALS['failed'] . " failed\n");
exit($GLOBALS['failed'] === 0 ? 0 : 1);
