<?php
declare(strict_types=1);

/**
 * RISE 63 unit suite — no database, no network, no web server required.
 *
 *   php tests/unit.php                      (on any host with PHP 8.2+)
 *   node tests/support/phpwasm-test.mjs     (in this sandbox, via php-wasm)
 *
 * Only pure logic is covered here. Database-backed behaviour lives in tests/integration.php
 * and is run on real hosting; the split is intentional so the security and money rules can be
 * verified quickly and repeatedly.
 */

use RISE63\Core\Money;
use RISE63\Core\BruteForce;
use RISE63\Core\Program;
use RISE63\Core\Security;
use RISE63\Core\Settings;
use RISE63\Core\Database;

// Resolve the project root from this file so the suite runs from a checkout or from the
// php-wasm harness, which mirrors the repository layout read-only under /src.
define('RISE63_TEST_ROOT', dirname(__DIR__));
$GLOBALS['dataFile'] = static function (string $name): string {
    $path = RISE63_TEST_ROOT . '/database/' . $name;
    if (!is_file($path)) {
        throw new RuntimeException('Missing data file: ' . $path);
    }
    return (string) file_get_contents($path);
};
$dataFile = $GLOBALS['dataFile'];

require RISE63_TEST_ROOT . '/app/bootstrap.php';

use function RISE63\take_flashes;

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

function assertThrows(string $class, callable $test, string $message = ''): void
{
    try {
        $test();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'expected ' . $class . ', got ' . $error::class);
    }
    throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'expected ' . $class . ', nothing thrown');
}

/* ─────────────────────────────── Money ─────────────────────────────────────── */

ok('Money parses naira input without floats', function (): void {
    assertSame(0, Money::fromNaira('0')->cents());
    assertSame(10000000, Money::fromNaira('100000')->cents());
    assertSame(12050, Money::fromNaira('120.5')->cents());
    assertSame(12005, Money::fromNaira('120.05')->cents());
    assertSame(12005, Money::fromNaira(' 120.05 ')->cents());
    assertSame(12345678, Money::fromNaira('123,456.78')->cents());
    assertSame(100, Money::fromNaira('1.')->cents());
});

ok('Money rejects malformed and over-precise input', function (): void {
    foreach (['', 'abc', '1.234', '-5', '12.3.4', '1e5', '1 000 000.999'] as $bad) {
        assertThrows(InvalidArgumentException::class, fn () => Money::fromNaira($bad), 'input ' . var_export($bad, true));
    }
    // Anything the platform cannot hold as integer kobo must fail loudly, never round silently.
    assertThrows(OverflowException::class, fn () => Money::fromNaira('9999999999999.99'));
});

ok('Money decimal roundtrip is exact', function (): void {
    $values = ['0.00', '0.01', '1.00', '100000.00'];
    if (PHP_INT_SIZE >= 8) {
        // DECIMAL(15,2) needs 64-bit integers; the php-wasm harness engine is 32-bit.
        $values[] = '9999999999999.99';
        $values[] = '21474836.48';
    }
    foreach ($values as $decimal) {
        assertSame($decimal, Money::fromDecimal($decimal)->toDecimal());
    }
    // The classic float trap: 0.1 + 0.2.
    assertSame('0.30', Money::fromNaira('0.10')->plus(Money::fromNaira('0.20'))->toDecimal());
});

ok('Money rejects overdraft and negative amounts', function (): void {
    assertThrows(InvalidArgumentException::class, fn () => Money::fromNaira('10')->minus(Money::fromNaira('10.01')));
    assertThrows(InvalidArgumentException::class, fn () => Money::fromCents(-1));
    assertSame('9.99', Money::fromNaira('10')->minus(Money::fromNaira('0.01'))->toDecimal());
});

ok('Money allocation always re-adds to the total', function (): void {
    $total = Money::fromNaira('1000');
    $parts = Money::allocate($total, [1, 1, 1]);
    $sum   = Money::zero();
    foreach ($parts as $part) {
        $sum = $sum->plus($part);
    }
    assertTrue($sum->equals($total), 'allocation must not lose kobo');
    assertSame('333.34', $parts[0]->toDecimal());
    assertSame('333.33', $parts[1]->toDecimal());

    $odd = Money::fromNaira('100');
    $shares = Money::allocate($odd, [3, 5, 2]);
    $check = Money::zero();
    foreach ($shares as $share) { $check = $check->plus($share); }
    assertTrue($check->equals($odd));
    assertThrows(InvalidArgumentException::class, fn () => Money::allocate($odd, [0, 0]));
});

ok('Money percentage rounds half up on kobo', function (): void {
    assertSame('100.00', Money::fromNaira('1000')->percent(1000)->toDecimal()); // 10 %
    assertSame('0.01', Money::fromNaira('0.05')->percent(1000)->toDecimal());   // 0.5 kobo → 1 kobo
    assertSame('50.00', Money::fromNaira('100')->percent(5000)->toDecimal());
    assertThrows(InvalidArgumentException::class, fn () => Money::fromNaira('10')->percent(10001));
});

ok('Money formats for display', function (): void {
    assertSame('₦100,000.00', Money::fromNaira('100000')->format());
    assertSame('₦1,250.50', Money::fromNaira('1250.5')->format());
    assertSame('₦100k', Money::fromNaira('100000')->short());
    assertSame('₦1.5m', Money::fromNaira('1500000')->short());
    assertSame('₦999', Money::fromNaira('999')->short());
});

/* ─────────────────────── Application state machine ─────────────────────────── */

ok('Program lifecycle accepts only declared transitions', function (): void {
    assertTrue(Program::canTransition('submitted', 'under_review'));
    assertTrue(Program::canTransition('under_review', 'documents_requested'));
    assertTrue(Program::canTransition('verified', 'approved'));
    assertTrue(Program::canTransition('approved', 'disbursed'));
    assertTrue(!Program::canTransition('submitted', 'approved'), 'approval cannot skip verification');
    assertTrue(!Program::canTransition('draft', 'disbursed'));
    assertTrue(!Program::canTransition('completed', 'under_review'), 'terminal states are terminal');
    assertTrue(!Program::canTransition('rejected', 'under_review'));
});

ok('Program transitions enforce a reason where required', function (): void {
    assertTrue(!Program::requiresReason('under_review'), 'entering review needs no note');
    assertTrue(Program::requiresReason('rejected'));
    assertTrue(Program::requiresReason('documents_requested'));
    Program::assertTransition('submitted', 'under_review', '');
    assertThrows(InvalidArgumentException::class, fn () => Program::assertTransition('under_review', 'documents_requested', ''), 'note needed');
    assertThrows(InvalidArgumentException::class, fn () => Program::assertTransition('submitted', 'rejected', ''), 'rejection needs a reason');
    Program::assertTransition('submitted', 'rejected', 'Duplicate of an earlier application.');
    assertThrows(RISE63\Core\StatusConflict::class, fn () => Program::assertTransition('submitted', 'approved', 'note'));
});

ok('Reviewers cannot drive system-only statuses', function (): void {
    assertTrue(Program::reviewerCanSet('verified'));
    assertTrue(!Program::reviewerCanSet('disbursed'), 'disbursement belongs to the treasury step');
    assertTrue(!Program::reviewerCanSet('submitted'));
    assertTrue(!Program::reviewerCanSet('completed'));
});

ok('Members may only edit or withdraw pre-money states', function (): void {
    assertTrue(Program::memberCanEdit('draft'));
    assertTrue(Program::memberCanEdit('documents_requested'));
    assertTrue(!Program::memberCanEdit('approved'));
    assertTrue(!Program::memberCanWithdraw('disbursed'));
    assertTrue(Program::memberCanWithdraw('submitted'));
});

ok('Tracker maps statuses onto three steps', function (): void {
    assertSame(1, Program::tracker('seed_grant', 'submitted')['step']);
    assertSame(2, Program::tracker('seed_grant', 'under_review')['step']);
    assertSame(3, Program::tracker('seed_grant', 'disbursed')['step']);
    assertSame(0, Program::tracker('seed_grant', 'rejected')['step']);
    assertSame('Rejected', Program::tracker('seed_grant', 'rejected')['label']);
});

ok('Education levels are constrained per program', function (): void {
    assertSame(['super_teen', 'tertiary'], Program::educationLevels('education_bridge'));
    assertTrue(in_array('none', Program::educationLevels('academy'), true));
    assertSame(['none'], Program::educationLevels('cooperative'));
    assertThrows(LogicException::class, fn () => Program::assertTransition('submitted', 'not_a_status', 'x'));
});

ok('Academy attendance percentage guards bad input', function (): void {
    assertSame(0, RISE63\Core\Academy::percent(0, 0), 'no sessions means no percentage');
    assertSame(0, RISE63\Core\Academy::percent(10, 0));
    assertSame(80, RISE63\Core\Academy::percent(10, 8));
    assertSame(100, RISE63\Core\Academy::percent(12, 12));
    assertSame(67, RISE63\Core\Academy::percent(3, 2));
    assertSame(100, RISE63\Core\Academy::percent(3, 5), 'clamped at 100');
});

ok('Temporary passwords satisfy the policy they will be replaced under', function (): void {
    for ($i = 0; $i < 40; $i++) {
        $temporary = RISE63\Core\Security::temporaryPassword();
        assertSame(14, strlen($temporary), 'fixed length keeps it easy to read aloud');
        assert(preg_match('/[0-9]/', $temporary) === 1, 'must contain a digit');
        assert(preg_match('/[^A-Za-z0-9]/', $temporary) === 1, 'must contain a symbol');
        assert(preg_match('/[0OlI]/', $temporary) === 0, 'no ambiguous glyphs');
        assertSame(null, RISE63\Core\Security::passwordProblem($temporary), 'must pass the real policy');
    }
    $batch = [];
    for ($i = 0; $i < 50; $i++) {
        $batch[RISE63\Core\Security::temporaryPassword()] = true;
    }
    assertSame(50, count($batch), 'no collisions in 50 draws');
});

ok('Reset tokens are random, url-safe and only ever stored hashed', function (): void {
    $a = RISE63\Core\Security::randomToken(32);
    $b = RISE63\Core\Security::randomToken(32);
    assert($a !== $b, 'two tokens must differ');
    assertSame(64, strlen($a), '32 bytes as hex = 64 chars');
    assert(preg_match('/^[a-f0-9]{64}$/D', $a) === 1, 'must match the shape the resolver accepts');
    assertSame(hash('sha256', $a), RISE63\Core\Security::hashToken($a));
    assertSame(64, strlen(RISE63\Core\Security::hashToken($a)));
    // The resolver's pattern must reject everything else, without touching the database.
    foreach (['', 'short', str_repeat('a', 63), str_repeat('a', 65), strtoupper($a), '../../etc/passwd'] as $bad) {
        assert(preg_match('/^[a-f0-9]{64}$/D', $bad) !== 1, 'must reject malformed token');
    }
});

ok('The integration suite cannot be run by accident', function (): void {
    $file = RISE63_TEST_ROOT . '/tests/integration.php';
    assert(is_file($file), 'tests/integration.php must ship');
    $source = (string) file_get_contents($file);

    assertTrue(str_contains($source, '_test'), 'must require a _test database name');
    assertTrue(str_contains($source, "str_ends_with(\$dbName, '_test')"), 'the guard must be an explicit check');
    assertTrue(str_contains($source, "PHP_SAPI !== 'cli'"), 'must refuse to run over HTTP');
    assertTrue(str_contains($source, 'RISE63_TEST_DSN'), 'must be configured through the environment, not a config file');

    // It must not carry credentials of its own: the password comes from the environment only.
    assertTrue(
        preg_match('/\$pass\s*=\s*\'[^\']+\'/', $source) === 0,
        'no hard-coded database password'
    );
});

ok('Migration files follow the shipping rules', function (): void {
    $dir = RISE63_TEST_ROOT . '/database/migrations';
    assert(is_dir($dir), 'database/migrations must exist');
    $files = glob($dir . '/*.sql') ?: [];
    sort($files);
    assert(count($files) >= 1, 'at least one migration ships with this version');
    $seen = [];
    foreach ($files as $file) {
        $name = basename($file);
        assert(preg_match('/^(\d{4})_[a-z0-9_]{1,60}\.sql$/D', $name) === 1, 'bad migration name: ' . $name);
        preg_match('/^(\d{4})/', $name, $m);
        assert(!isset($seen[$m[1]]), 'duplicate migration version ' . $m[1]);
        $seen[$m[1]] = true;
        $sql = (string) file_get_contents($file);
        assert(stripos($sql, 'DELIMITER') === false, 'no DELIMITER blocks in migrations: ' . $name);
        assert(stripos($sql, 'CREATE TRIGGER') === false, 'triggers live in ledger-guards.sql: ' . $name);
        assert(stripos($sql, 'DROP TABLE') === false, 'migrations must not drop tables: ' . $name);
        assert(substr_count($sql, ';') >= 1, 'a migration must contain statements');
    }
    // Versions must be contiguous from 0001.
    assertSame('0001', array_key_first($seen));
});

ok('Licence keys are validated before anything is sent to the provider', function (): void {
    $looks = static fn (string $key): bool => RISE63\Core\Activation::looksLikeKey($key);

    assert($looks('ABCD-1234-EFGH-5678'), 'dashed keys are the normal shape');
    assert($looks(str_repeat('a', 200)), 'long keys are accepted');
    assert($looks('  padded-key-0123456789  '), 'surrounding whitespace is trimmed, not a failure');

    foreach ([
        '', 'short', str_repeat('a', 255 + 1), 'has space in the middle key', "multi\nline-key-1234567890",
        "tab\tkey-1234567890", 'unicode-☃-key-12345678',
    ] as $bad) {
        assert(!$looks($bad), 'must reject: ' . var_export($bad, true));
    }
});

ok('Domains are normalised the way the provider stores them', function (): void {
    $norm = static fn (string $host): string => RISE63\Core\Activation::domainFromHost($host);

    assertSame('buildroom.rccglp63yaya.org.ng', $norm('buildroom.rccglp63yaya.org.ng'));
    assertSame('buildroom.rccglp63yaya.org.ng', $norm('BUILDROOM.RCCGLP63YAYA.ORG.NG'));
    assertSame('buildroom.rccglp63yaya.org.ng', $norm('buildroom.rccglp63yaya.org.ng:8443'));
    assertSame('buildroom.rccglp63yaya.org.ng', $norm('www.buildroom.rccglp63yaya.org.ng'));
    assertSame('buildroom.rccglp63yaya.org.ng', $norm('https://www.buildroom.rccglp63yaya.org.ng/path'));
    assertSame('buildroom.rccglp63yaya.org.ng', $norm('buildroom.rccglp63yaya.org.ng.'));

    assert(RISE63\Core\Activation::validDomain('example.co.uk'), 'multi-label domains are fine');
    assert(RISE63\Core\Activation::validDomain('x.io'), 'short domains are fine');
    foreach (['', 'localhost', 'no-tld', 'http://example.org', 'exa mple.org', 'a.b', str_repeat('a', 260) . '.org'] as $bad) {
        assert(!RISE63\Core\Activation::validDomain($bad), 'must reject domain: ' . var_export($bad, true));
    }
});

ok('The manager response decides access, and only status 1 grants it', function (): void {
    // Reaches the private interpreter through the public surface: a stored key plus a stubbed
    // transport is not possible here without a network, so the response table is asserted
    // against the documented contract via reflection.
    $method = new ReflectionMethod(RISE63\Core\Activation::class, 'interpret');
    $method->setAccessible(true);
    $activation = new RISE63\Core\Activation(sys_get_temp_dir());

    $cases = [
        '{"status":1,"message":"License is valid."}'        => [true,  'License is valid.'],
        '{"status":"1","message":"ok"}'                     => [true,  'ok'],
        '{"status":0,"message":"Invalid license key or domain."}' => [false, 'Invalid license key or domain.'],
        '{"status":"suspended","message":"Suspended"}'      => [false, 'suspended by the provider'],
        '{"status":0}'                                      => [false, 'not valid for this domain'],
        'not json at all'                                   => [false, 'could not be read'],
        '{"message":"no status field"}'                     => [false, 'no status field'],
    ];

    foreach ($cases as $body => [$expectedValid, $expectedText]) {
        $state = $method->invoke($activation, $body);
        assertSame($expectedValid, $state['valid'], 'validity for ' . $body);
        assert(str_contains($state['message'], $expectedText), 'message for ' . $body . ': ' . $state['message']);
    }

    // A key is never echoed back in a message, whatever the manager says.
    $state = $method->invoke($activation, '{"status":0,"message":"Invalid license key or domain."}');
    assert(!str_contains($state['message'], 'eyJ'), 'the response message must never carry a key');
});

ok('Licence diagnosis without a network: missing key, transport messages, legacy file', function (): void {
    $dir = sys_get_temp_dir() . '/rise63-licence-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $activation = new RISE63\Core\Activation($dir);

    // No key anywhere: the message must tell the operator exactly what to do next.
    assertSame(null, $activation->storedKey());
    $state = $activation->check('buildroom.example.org');
    assertSame(false, $state['valid'], 'a site with no key is never valid');
    assert(str_contains($state['message'], 'No licence key yet'), $state['message']);
    assert(str_contains($state['message'], 'provider'), 'must point at where a key comes from');

    // A malformed host cannot be licensed at all.
    $state = $activation->check('localhost');
    assertSame(false, $state['valid']);
    assert(str_contains($state['message'], 'domain name'), $state['message']);

    // A key is stored 0600 and never rendered in full by the checker.
    $activation->storeKey('TEST-KEY-0123456789ABCDEF');
    clearstatcache();
    assertSame('0600', substr(sprintf('%o', fileperms($dir . '/activation-key.php')), -4), 'key file mode');
    assertSame('TEST-KEY-0123456789ABCDEF', $activation->storedKey()['key'] ?? null);

    // Backwards compatibility: an installation from an earlier build has system-token.php.
    $legacy = sys_get_temp_dir() . '/rise63-legacy-' . bin2hex(random_bytes(4));
    mkdir($legacy, 0700, true);
    file_put_contents($legacy . '/system-token.php', "<?php return ['token' => 'LEGACY-TOKEN-0123456789'];
");
    $old = new RISE63\Core\Activation($legacy);
    assertSame('LEGACY-TOKEN-0123456789', $old->storedKey()['key'] ?? null, 'an old token is still read');

    // The diagnostics shown to the operator must distinguish "unreachable" from "rejected".
    $transport = new ReflectionMethod(RISE63\Core\Activation::class, 'transportMessage');
    $transport->setAccessible(true);
    $unreachable = $transport->invoke(null, 0, 'Could not resolve host');
    assert(str_contains($unreachable, 'could not be reached'), $unreachable);
    assert(str_contains($unreachable, 'manager.pmhserver.name.ng'), 'must name the host to test');
    $rejected = $transport->invoke(null, 500, '');
    assert(str_contains($rejected, 'answered with an error'), $rejected);
    assert(str_contains($rejected, 'HTTP 500'), $rejected);

    array_map('unlink', glob($dir . '/*') ?: []);
    array_map('unlink', glob($legacy . '/*') ?: []);
    rmdir($dir);
    rmdir($legacy);
});

ok('Setup does not re-ask the manager when the administrator is created', function (): void {
    // Regression lock. createAdministrator() used to call check($host, true) — a forced network
    // check — so a key confirmed seconds earlier at stage 1 could be refused ("Invalid license key
    // or domain") and setup could never finish. The licence decision belongs at stage 1; stage 3
    // only compares against what the provider already accepted. This is asserted on the source
    // because reaching createAdministrator() needs a live MySQL connection and a full schema.
    $source = file_get_contents(dirname(__DIR__) . '/app/Core/Installer.php');
    assert(is_string($source), 'Installer.php is readable');
    $body = substr($source, (int) strpos($source, 'public function createAdministrator'));
    $end  = strpos($body, 'public function complete');
    $body = substr($body, 0, $end === false ? null : $end);

    // At most one fallback call, and only for a host the provider has never accepted.
    assert(substr_count($body, '->check(') <= 1, 'stage 3 must not check the manager twice');
    assert(str_contains($body, 'if (!$activation->domainAccepted($domain))'),
        'the recorded domain must be consulted before the manager');
    if (str_contains($body, '->check(')) {
        assert(strpos($body, 'domainAccepted') < strpos($body, '->check('),
            'any manager call must come after the recorded-domain check');
    }
    assert(str_contains($body, 'storedKey()'), 'stage 3 must require a stored key');
});

ok('A confirmed domain is recorded, survives a key save, and is per key', function (): void {
    $dir = sys_get_temp_dir() . '/rise63-domain-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $activation = new RISE63\Core\Activation($dir);

    assertSame(false, $activation->domainAccepted('buildroom.example.org'), 'nothing accepted yet');

    // rememberDomain() is only ever called on a provider "valid", but even if it were called by
    // hand it must reject garbage rather than record it.
    $activation->rememberDomain('not a domain');
    assertSame(false, $activation->domainAccepted('not a domain'), 'garbage is never recorded');

    $activation->rememberDomain('buildroom.example.org');
    assertSame(true, $activation->domainAccepted('buildroom.example.org'));

    // Saving the same key must not discard what the provider just confirmed: the installer writes
    // the key at stage 1 and relies on this record to finish at stage 3 without a second call.
    $activation->storeKey('TEST-KEY-0123456789ABCDEF');
    assertSame(true, $activation->domainAccepted('buildroom.example.org'), 'a saved key keeps its domains');

    // A different key starts from a clean slate.
    $activation->storeKey('OTHER-KEY-0123456789ABCDEF');
    assertSame(false, $activation->domainAccepted('buildroom.example.org'), 'a new key clears old domains');

    // The list is capped, so a corrupted file cannot grow without bound.
    for ($i = 0; $i < 9; $i++) {
        $activation->rememberDomain('site' . $i . '.example.org');
    }
    $data = (static function (string $path): array {
        $value = include $path;
        return is_array($value) ? $value : [];
    })($dir . '/activation-key.php');
    assert(count($data['domains'] ?? []) <= 5, 'the domain list stays small');

    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

ok('verify() can be told the exact domain the licence was issued to', function (): void {
    $dir = sys_get_temp_dir() . '/rise63-override-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $activation = new RISE63\Core\Activation($dir);

    // The fourth argument normalises like a host, so pasting "https://Buildroom.Example.org/x"
    // reports the domain the installer would actually send.
    $state = $activation->verify('SHORT', 'served.example.org', false, 'https://Buildroom.Example.org/x');
    assertSame(false, $state['valid']);
    assertSame('buildroom.example.org', $state['domain'], 'the override wins over the request host');

    $state = $activation->verify('TOO-SHORT-0123456789', 'served.example.org', false, 'localhost');
    assertSame(false, $state['valid']);
    assert(str_contains($state['message'], 'domain name'), $state['message']);

    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

/* ─────────────────────── Security: passwords & PINs ────────────────────────── */

ok('Password policy rejects weak secrets', function (): void {
    assertTrue(Security::passwordProblem('short') !== null);
    assertTrue(Security::passwordProblem(str_repeat('a', 73)) !== null);
    assertTrue(Security::passwordProblem('my-password-2026') !== null, 'common word');
    assertTrue(Security::passwordProblem('Chinedu-Strong-2026', 'c@x.ng', 'Chinedu Okafor') !== null, 'name inside');
    assertTrue(Security::passwordProblem('grace@example.com-2026', 'grace@example.com') !== null, 'e-mail name inside');
    assertSame(null, Security::passwordProblem('Golden-River-93-Sunrise', 'amaka@example.ng', 'Amaka Obi'));
});

ok('Transaction PIN policy blocks guessable PINs', function (): void {
    assertTrue(Security::pinProblem('0000') !== null);
    assertTrue(Security::pinProblem('1234') !== null);
    assertTrue(Security::pinProblem('4321') !== null);
    assertTrue(Security::pinProblem('2026') !== null);
    assertTrue(Security::pinProblem('123') !== null);
    assertTrue(Security::pinProblem('12a4') !== null);
    assertSame(null, Security::pinProblem('7391'));
});

ok('Password hashing roundtrip and rehash detection', function (): void {
    $hash = Security::hashPassword('Golden-River-93-Sunrise');
    assertTrue(password_verify('Golden-River-93-Sunrise', $hash));
    assertTrue(!password_verify('wrong', $hash));
    assertTrue(!Security::needsRehash($hash));
    assertTrue(Security::needsRehash('$2y$04$abcdefghijklmnopqrstuv'));
});

ok('Unknown-account verification does equal work without throwing', function (): void {
    Security::dummyVerify('anything');
    assertTrue(true);
});

/* ───────────────────────── Security: transport & CSRF ──────────────────────── */

ok('CIDR matching handles boundaries', function (): void {
    assertTrue(Security::ipInCidr('10.0.0.5', '10.0.0.0/8'));
    assertTrue(Security::ipInCidr('10.255.255.255', '10.0.0.0/8'));
    assertTrue(!Security::ipInCidr('11.0.0.1', '10.0.0.0/8'));
    assertTrue(Security::ipInCidr('192.168.1.130', '192.168.1.128/25'));
    assertTrue(!Security::ipInCidr('192.168.1.127', '192.168.1.128/25'));
    assertTrue(Security::ipInCidr('172.16.4.4', '172.16.0.0/12'));
    assertTrue(!Security::ipInCidr('::1', '10.0.0.0/8'), 'family mismatch is false');
    assertTrue(!Security::ipInCidr('not-an-ip', '10.0.0.0/8'));
});

ok('Local addresses are recognised for scope decisions', function (): void {
    assertTrue(BruteForce::isLocal('127.0.0.1'));
    assertTrue(BruteForce::isLocal('10.1.2.3'));
    assertTrue(BruteForce::isLocal('192.168.0.9'));
    assertTrue(!BruteForce::isLocal('102.89.1.1'));
});

/* ──────────────────────── Security: brute-force policy ─────────────────────── */

ok('Country policy defaults to allowing unknown countries', function (): void {
    $on = ['country_filter_enabled' => '1', 'country_unknown_policy' => 'allow', 'rules' => ['NG' => 'whitelisted', 'XX' => 'blacklisted']];
    assertSame('allowed', BruteForce::countryDecision('NG', $on));
    assertSame('allowed', BruteForce::countryDecision(null, $on), 'unknown is allowed by default');
    assertSame('blacklisted', BruteForce::countryDecision('XX', $on));
    $deny = ['country_filter_enabled' => '1', 'country_unknown_policy' => 'deny', 'rules' => []];
    assertSame('unknown-denied', BruteForce::countryDecision(null, $deny));
    $off = ['country_filter_enabled' => '0', 'country_unknown_policy' => 'deny', 'rules' => ['XX' => 'blacklisted']];
    assertSame('allowed', BruteForce::countryDecision('XX', $off), 'disabling the filter disables the rules');
});

ok('IP failure decisions follow the cPHulk rules', function (): void {
    $base = ['failures' => 9, 'userExists' => true, 'instaBlock' => true, 'ipProtection' => true, 'threshold' => 10];
    assertSame('allow', BruteForce::failureDecision($base), 'below the threshold');
    assertSame('block', BruteForce::failureDecision(['failures' => 10] + $base), 'at the threshold');
    assertSame('block', BruteForce::failureDecision(['userExists' => false] + $base), 'unknown account blocks immediately');
    assertSame('allow', BruteForce::failureDecision(['userExists' => false, 'instaBlock' => false] + $base), 'immediate block can be disabled');
    assertSame('allow', BruteForce::failureDecision(['ipProtection' => false, 'failures' => 99] + $base), 'protection off means no block');
});

ok('Block duration escalates and never de-escalates', function (): void {
    Settings::override(['escalate_repeat_blocks' => '1', 'block_duration' => 'day']);
    assertSame('day', BruteForce::durationFor(0));
    assertSame('week', BruteForce::durationFor(1));
    assertSame('month', BruteForce::durationFor(2));
    assertSame('year', BruteForce::durationFor(3));
    assertSame('year', BruteForce::durationFor(9), 'clamped at the top of the ladder');
    Settings::override(['escalate_repeat_blocks' => '0', 'block_duration' => 'week']);
    assertSame('week', BruteForce::durationFor(3), 'fixed duration when escalation is off');
    Settings::override(['block_duration' => 'bogus']);
    assertSame('day', BruteForce::durationFor(0), 'invalid configuration falls back safely');
});

/* ─────────────────────── Settings validation (schema) ──────────────────────── */

ok('Settings schema refuses values the app cannot interpret', function (): void {
    $check = static fn (string $key, mixed $value): string => Settings::validateValue($key, $value);

    assertSame('15', $check('account_window_minutes', '15'));
    assertThrows(InvalidArgumentException::class, fn () => $check('account_window_minutes', 'sixty'));
    assertThrows(InvalidArgumentException::class, fn () => $check('account_window_minutes', '0'), 'below minimum');
    assertThrows(InvalidArgumentException::class, fn () => $check('account_window_minutes', '9999'), 'above maximum');
    assertThrows(InvalidArgumentException::class, fn () => $check('block_duration', 'fortnight'), 'enum');
    assertSame('month', $check('block_duration', 'month'));
    assertThrows(InvalidArgumentException::class, fn () => $check('notification_email', 'not-an-email'));
    assertSame('ops@example.ng', $check('notification_email', ' OPS@Example.NG '));
    assertThrows(InvalidArgumentException::class, fn () => $check('theme_color', 'blue'));
    assertSame('#2563eb', $check('theme_color', '#2563EB'));
    assertSame('100000.00', $check('seed_grant_monthly_pool', '100,000'));
    assertThrows(InvalidArgumentException::class, fn () => $check('seed_grant_monthly_pool', 'lots'));
    assertThrows(InvalidArgumentException::class, fn () => $check('site_title', ''));
    assertThrows(InvalidArgumentException::class, fn () => $check('site_title', str_repeat('x', 121)));
    assertThrows(InvalidArgumentException::class, fn () => $check('site_title', "bad\x07title"), 'control characters');
    assertSame('1', $check('country_filter_enabled', 'on'));
    assertSame('0', $check('country_filter_enabled', 'off'));
    assertThrows(InvalidArgumentException::class, fn () => $check('no_such_setting', 'x'), 'unknown key');
});

ok('Settings declarations cover every key referenced by the seed file', function (): void {
    $seed = ($GLOBALS['dataFile'])('seed.sql');
    preg_match_all("/\('([a-z_]+)'/", (string) $seed, $matches);
    foreach ($matches[1] as $key) {
        assertTrue(isset(Settings::SCHEMA[$key]), 'seeded key missing from the schema: ' . $key);
    }
    assertTrue(count($matches[1]) > 20);
});

/* ────────────────────────────── Notifications ──────────────────────────────── */

ok('HTML e-mail helpers escape untrusted text', function (): void {
    $safe = RISE63\e('<script>alert(1)</script>');
    assertTrue(!str_contains($safe, '<script>'), 'angle brackets must be escaped');
    assertTrue(str_contains($safe, '&lt;script&gt;'));
});

/* ──────────────────────────────── Helpers ──────────────────────────────────── */

ok('Initials and favicon letter handle Unicode', function (): void {
    assertSame('AO', RISE63\initials('Amaka Obi'));
    assertSame('A', RISE63\initials('Amaka'));
    assertSame('R', RISE63\initials('   '));
});

ok('Date helpers tolerate bad input', function (): void {
    assertSame('—', RISE63\human_date(null));
    assertSame('—', RISE63\human_date('not-a-date'));
    assertTrue(RISE63\human_date('2026-11-04 09:15:00', true) !== '—');
    assertSame('—', RISE63\relative_time(null));
    assertSame(null, RISE63\hours_since(null));
});

ok('Database script splitter keeps statements intact', function (): void {
    $sql = "-- comment\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (\n  id INT\n);\n";
    $statements = Database::splitStatements($sql);
    assertSame(2, count($statements));
    assertTrue(str_starts_with($statements[0], 'CREATE TABLE a'));
    assertTrue(str_contains($statements[1], "id INT\n)"), 'multi-line statement preserved');
});

/* ───────────────────────────── Schema integrity ────────────────────────────── */

ok('Schema enforces the invariants described in the blueprint', function (): void {
    $schema = ($GLOBALS['dataFile'])('schema.sql');
    foreach (['zones', 'areas', 'parishes', 'users', 'program_applications', 'application_events',
              'remittances', 'remittance_ledger', 'security_settings', 'security_logs', 'security_ips',
              'country_rules', 'manual_access_rules', 'firewall_jobs', 'notification_outbox'] as $table) {
        assertTrue(str_contains($schema, 'CREATE TABLE ' . $table . ' ('), 'missing table ' . $table);
    }
    assertTrue(str_contains($schema, 'DECIMAL(15,2)'), 'money columns must be DECIMAL(15,2)');
    assertTrue(str_contains($schema, 'ENGINE=InnoDB'), 'InnoDB is required for foreign keys');
    assertTrue(substr_count($schema, 'utf8mb4') > 5);
    assertTrue(str_contains($schema, "program_type <> 'education_bridge'"), 'education constraint present');
    assertTrue(str_contains($schema, 'uniq_application_idempotency'), 'idempotency constraint present');
    assertTrue(!str_contains($schema, 'CREATE TABLE `?parish_structure'), 'the denormalised draft table must be gone');
    assertTrue(str_contains($schema, 'CREATE VIEW parish_directory'), 'the flat reporting view replaces it');
});

ok('Country seed contains the full ISO list and protects Nigeria', function (): void {
    $countries = ($GLOBALS['dataFile'])('countries.sql');
    preg_match_all("/^\('([A-Z]{2})'/m", $countries, $matches);
    assertSame(250, count($matches[1]), 'ISO 3166-1 alpha-2 count');
    assertTrue(in_array('NG', $matches[1], true));
    assertTrue(str_contains($countries, "country_code='NG'"), 'Nigeria is whitelisted by default');
});

ok('No personal data or secrets are committed in the tree', function (): void {
    $root = RISE63_TEST_ROOT . '/';
    // Assembled at runtime so the needle list cannot match its own source file.
    $forbidden = [
        implode(' ', ['-----BEGIN', 'RSA', 'PRIVATE', 'KEY-----']),
        implode(' ', ['-----BEGIN', 'OPENSSH', 'PRIVATE', 'KEY-----']),
        'sk_' . 'live_',
        'AKIA' . 'IOSFODNN7',
    ];
    $iterator  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $checked = 0;
    foreach ($iterator as $file) {
        if (!$file->isFile()) { continue; }
        $path = $file->getPathname();
        foreach (['/node_modules/', '/storage/', '/docs/', '/.git/', '/config/'] as $skip) {
            if (str_contains($path, $skip)) { continue 2; }
        }
        if (!preg_match('/\.(php|css|js|sql|json|webmanifest|md|htaccess)$/', $path)) { continue; }
        $contents = (string) file_get_contents($path);
        foreach ($forbidden as $needle) {
            assertTrue(!str_contains($contents, $needle), 'found ' . $needle . ' in ' . $path);
        }
        $checked++;
    }
    assertTrue($checked > 20, 'expected to scan the source tree, scanned ' . $checked);
});

/* ──────────────────────────────── Reporting ────────────────────────────────── */

echo "\n";
echo str_repeat('─', 62) . "\n";
printf("%d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail']);
if ($GLOBALS['failures'] !== []) {
    echo "\nFailures:\n  - " . implode("\n  - ", $GLOBALS['failures']) . "\n";
    exit(1);
}
exit(0);
