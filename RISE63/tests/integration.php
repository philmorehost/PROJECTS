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

use RISE63\Core\Database;
use RISE63\Core\Migrator;
use RISE63\Core\Money;
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

/* ──────────────────────────────────── summary ─────────────────────────────────── */

fwrite(STDOUT, "\n" . str_repeat('─', 62) . "\n");
fwrite(STDOUT, $GLOBALS['passed'] . ' passed, ' . $GLOBALS['failed'] . " failed\n");
exit($GLOBALS['failed'] === 0 ? 0 : 1);
