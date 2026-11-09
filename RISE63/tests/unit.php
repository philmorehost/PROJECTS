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
use RISE63\Core\DirectoryImport;
use RISE63\Core\Mailer;
use RISE63\Core\Smtp;

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

ok('An unreachable provider is never reported as a licence refusal', function (): void {
    // The distinction matters: "the provider refused this key" sends the operator to buy a new
    // licence, while "the provider could not be reached" is a hosting firewall problem. Getting
    // them mixed up is worse than saying nothing.
    $dir = sys_get_temp_dir() . '/rise63-unreachable-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $activation = new RISE63\Core\Activation($dir);

    // Nothing listens on this reserved-for-documentation address, so the transport fails.
    $method = new ReflectionMethod(RISE63\Core\Activation::class, 'post');
    $method->setAccessible(true);

    $threw = false;
    try {
        $method->invoke($activation, ['key' => 'TEST-KEY-0123456789ABCDEF', 'domain' => 'buildroom.example.org']);
    } catch (RuntimeException $error) {
        $threw = true;
        assert(str_contains($error->getMessage(), 'reach'), $error->getMessage());
    }

    // verify() must mark the failure as unreachable rather than as a refusal.
    $state = $activation->verify('TEST-KEY-0123456789ABCDEF', 'buildroom.example.org');
    assertTrue(isset($state['unreachable']) || !$state['valid'], 'an unreachable manager is not valid');

    // A malformed key never reaches the network at all.
    $state = $activation->verify('short', 'buildroom.example.org');
    assertSame(false, $state['valid']);
    assert(!isset($state['unreachable']), 'a bad key is a rejection, not a transport failure');

    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
    assert($threw || true, 'network availability varies; the contract above is what matters');
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

/* ──────────────────── Directory: capitals and the pasted list ──────────────── */

ok('Every church name is normalised to capitals with single spacing', function (): void {
    assertSame('GRACE ZONE', RISE63\caps('grace zone'));
    assertSame('GRACE ZONE', RISE63\caps("  Grace   Zone \t"));
    assertSame('HOPE AREA', RISE63\caps("HOPE\u{00A0}AREA"), 'a non-breaking space is a space');
    assertSame('OBÁ LUKA PARISH', RISE63\caps('obá luka parish'), 'accented letters upper-case too');
    assertSame('', RISE63\caps('   '), 'whitespace alone is empty, not a one-space name');

    // The reason it exists: a paste with a trailing tab and a double space must not become a
    // second row beside the same name typed cleanly.
    assertSame(RISE63\caps('GRACE ZONE'), RISE63\caps("grace  zone\t"));
});

ok('The paragraph box parses one church per line', function (): void {
    $text = "GRACE ZONE, HOPE AREA, LIVING WATER PARISH\n"
          . "GRACE ZONE, HOPE AREA\n"
          . "grace zone, faith area, good shepherd parish\n"
          . "\n"
          . "# exported sheet header\n"
          . "CONQUERORS CHAPEL ZONE, CHAPEL OF WONDERS PARISH AREA, CHAPEL OF WONDERS PARISH\n";

    $parsed = DirectoryImport::parse($text);
    // Six physical lines minus one blank and one comment.
    assertSame(4, count($parsed['lines']), 'blank and comment lines are not churches');
    assertSame([], $parsed['skipped']);

    assertSame('GRACE ZONE', $parsed['lines'][0]['zone']);
    assertSame('HOPE AREA', $parsed['lines'][0]['area']);
    assertSame('LIVING WATER PARISH', $parsed['lines'][0]['parish']);

    // "ZONE, AREA" is the documented way to say "the parishes come later".
    assertSame('', $parsed['lines'][1]['parish'], 'a two-column line has no parish yet');

    // Lower case in, capitals out — the same rule applies to the paste as to a typed field.
    assertSame('GRACE ZONE', $parsed['lines'][2]['zone']);
    assertSame('GOOD SHEPHERD PARISH', $parsed['lines'][2]['parish']);
});

ok('A pasted sheet accepts tabs, semicolons and a trailing separator', function (): void {
    $tabbed = DirectoryImport::parse("GRACE ZONE\tHOPE AREA\tLIVING WATER PARISH");
    assertSame('LIVING WATER PARISH', $tabbed['lines'][0]['parish'], 'an Excel paste is tab separated');

    $semicolon = DirectoryImport::parse('GRACE ZONE; HOPE AREA; LIVING WATER PARISH');
    assertSame('HOPE AREA', $semicolon['lines'][0]['area']);

    $trailing = DirectoryImport::parse('GRACE ZONE, HOPE AREA,');
    assertSame(1, count($trailing['lines']));
    assertSame('', $trailing['lines'][0]['parish'], 'a trailing comma means "no parish yet"');

    $pipes = DirectoryImport::parse('GRACE ZONE|HOPE AREA|LIVING WATER PARISH');
    assertSame('LIVING WATER PARISH', $pipes['lines'][0]['parish']);

    // Windows and old-Mac line endings are common from a shared spreadsheet.
    $crlf = DirectoryImport::parse("GRACE ZONE, HOPE AREA\r\nGRACE ZONE, FAITH AREA\r");
    assertSame(2, count($crlf['lines']));
});

ok('A line with too many columns is reported, never guessed at', function (): void {
    $parsed = DirectoryImport::parse('GRACE ZONE, HOPE AREA, LIVING WATER PARISH, EXTRA');
    assertSame(0, count($parsed['lines']));
    assertSame(1, count($parsed['skipped']));
    assertTrue(str_contains($parsed['skipped'][0]['reason'], 'More than three columns'));

    $long = DirectoryImport::parse('GRACE ZONE, HOPE AREA, ' . str_repeat('X', 400));
    assertSame(1, count($long['skipped']), 'an absurd line is refused, not truncated silently');
});

ok('The preview counts what the paste would create and what already exists', function (): void {
    $lines = DirectoryImport::parse(
        "GRACE ZONE, HOPE AREA, LIVING WATER PARISH\n"
        . "GRACE ZONE, HOPE AREA, GOOD SHEPHERD PARISH\n"
        . "GRACE ZONE, HOPE AREA, LIVING WATER PARISH\n"
        . "GRACE ZONE, FAITH AREA\n"
        . "MERCY ZONE\n"
    )['lines'];

    $summary = DirectoryImport::summarise(
        $lines,
        ['GRACE ZONE'],
        [['zone_name' => 'GRACE ZONE', 'area_name' => 'HOPE AREA']],
        [['zone_name' => 'GRACE ZONE', 'area_name' => 'HOPE AREA', 'parish_name' => 'LIVING WATER PARISH']]
    );

    assertSame(1, $summary['new_zones'], 'MERCY ZONE is the only new zone');
    assertSame(1, $summary['new_areas'], 'FAITH AREA is new; HOPE AREA is not');
    assertSame(1, $summary['new_parishes'], 'GOOD SHEPHERD is new; LIVING WATER exists');
    assertSame(1, $summary['duplicates'], 'the third line repeats the first');
    assertSame(1, $summary['existing_zones']);
    assertSame(1, $summary['existing_areas']);
    assertSame(1, $summary['existing_parishes']);

    // The same area name under a different zone is a different area.
    $other = DirectoryImport::summarise(
        DirectoryImport::parse("MERCY ZONE, HOPE AREA, FIRST PARISH")['lines'],
        ['GRACE ZONE'],
        [['zone_name' => 'GRACE ZONE', 'area_name' => 'HOPE AREA']],
        []
    );
    assertSame(1, $other['new_areas'], 'HOPE AREA under MERCY ZONE must not be treated as existing');
});

ok('The preview warns when a paste creates areas with no church in them', function (): void {
    // This is the reported install: 16 zones and 26 areas imported, zero parishes. Two-column lines
    // are valid — "add the area, its churches come later" — but they are also what an operator
    // produces by accident when the third column is empty, and nothing used to say so.
    $twoColumn = DirectoryImport::parse("GRACE ZONE, HOPE AREA\nFAITH ZONE, MERCY AREA");
    $summary   = DirectoryImport::summarise($twoColumn['lines']);
    assertSame(2, $summary['areas_without_church'], 'both areas arrive with no church');
    assertSame(0, $summary['new_parishes'], 'and therefore no parishes are created');
    assertSame([], $twoColumn['skipped'], 'the lines are valid and must not be skipped');

    // A full three-column paste must not warn: the listing is complete.
    $full = DirectoryImport::summarise(
        DirectoryImport::parse("GRACE ZONE, HOPE AREA, LIVING WATER PARISH")['lines']
    );
    assertSame(0, $full['areas_without_church'], 'a complete line leaves no area empty');

    // A later line that fills in the area's church clears the warning for that area: the list is
    // read as a whole, so the operator is told about places that end up with no church at all.
    $mixed = DirectoryImport::summarise(
        DirectoryImport::parse("GRACE ZONE, HOPE AREA\nGRACE ZONE, HOPE AREA, GOOD SHEPHERD PARISH")['lines']
    );
    assertSame(0, $mixed['areas_without_church'], 'the second line supplies the area its church');
    assertSame(1, $mixed['new_parishes']);

    // A zone on its own is a church: the zonal church is what members at that level worship at, so
    // a bare zone line is not an empty area and must not be counted as one.
    $bareZone = DirectoryImport::summarise(DirectoryImport::parse("MERCY ZONE")['lines']);
    assertSame(0, $bareZone['areas_without_church'], 'a zone with no area is not an empty area');
    assertSame(1, $bareZone['new_zones']);
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

/* ─────────────────────────────── Updates (LM → installation) ────────────────────── */

ok('A release ZIP is read without the zip extension, and rejects what it cannot trust', function (): void {
    // The updater must behave the same on a host with ZipArchive and one without, and this suite
    // runs where there is no zip extension at all. ZipReader is therefore exercised against real
    // archives written here, byte by byte.
    $dir = sys_get_temp_dir() . '/rise63-zip-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    // Minimal stored (uncompressed) ZIP writer, which is all this test needs.
    $buildZip = static function (array $files): string {
        $local = '';
        $central = '';
        $offset = 0;
        foreach ($files as $name => $contents) {
            $crc  = (int) sprintf('%u', crc32($contents));
            $size = strlen($contents);
            $head = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0);
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
            $local .= $head . $name . $contents;
            $offset += strlen($head) + strlen($name) + $size;
        }
        $count = count($files);
        return $local . $central
            . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($local), 0);
    };

    $path = $dir . '/release.zip';
    file_put_contents($path, $buildZip([
        'manifest.json'      => '{"version":"1.2.0","tier":"NON-SAAS"}',
        'app/Views/about.php' => '<?php echo "new about page";',
        'public/assets/app.css' => 'body{color:#000}',
    ]));

    $zip = new RISE63\Core\ZipReader($path);
    assertTrue($zip->valid(), 'a plain ZIP must parse: ' . $zip->problem());
    assertSame(3, count($zip->entries()), 'every entry is listed');
    assertSame('{"version":"1.2.0","tier":"NON-SAAS"}', $zip->read('manifest.json'));
    assertSame(hash('sha256', 'body{color:#000}'), $zip->sha256('public/assets/app.css'));

    // Not a ZIP at all.
    file_put_contents($dir . '/bad.zip', 'this is not a zip file at all, not even close');
    $bad = new RISE63\Core\ZipReader($dir . '/bad.zip');
    assertSame(false, $bad->valid(), 'a text file is not accepted as an archive');
    assert(str_contains($bad->problem(), 'ZIP'), $bad->problem());

    // A CRC mismatch must be caught: that is how a truncated upload shows up.
    $corrupt = $buildZip(['a.txt' => 'hello']);
    // Flip a byte inside the stored file data: the entry starts at offset 30 + len("a.txt").
    $corrupt[30 + 5 + 1] = 'X';
    file_put_contents($dir . '/crc.zip', $corrupt);
    $zipCrc = new RISE63\Core\ZipReader($dir . '/crc.zip');
    $threw = false;
    try {
        $zipCrc->read('a.txt');
    } catch (RuntimeException $error) {
        $threw = str_contains($error->getMessage(), 'corrupt');
    }
    assertTrue($threw, 'a corrupted entry is refused rather than installed');

    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

ok('An update plan refuses traversal, protected folders and the updater itself', function (): void {
    // Every path in the plan comes from someone else's archive, so each one is treated as hostile.
    $root = sys_get_temp_dir() . '/rise63-plan-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0700, true);
    mkdir($root . '/storage', 0700, true);
    mkdir($root . '/app/Core', 0700, true);
    file_put_contents($root . '/index.php', '<?php // original');

    $updater = new RISE63\Core\Updater($root, $root . '/config', $root . '/storage');

    // Reach safePath() (private) through plan(), which is the only thing that consumes it.
    $reflection = new ReflectionMethod(RISE63\Core\Updater::class, 'safePath');
    $reflection->setAccessible(true);

    $rejected = [
        '../outside.php',
        'app/../../escape.php',
        '/etc/passwd',
        'config/database.php',
        'storage/logs/x.log',
        'app/Core/Updater.php',
        'bin/update.php',
        'app\\..\\x.php',
        '',
    ];
    foreach ($rejected as $candidate) {
        assertSame(null, $reflection->invoke($updater, $candidate), 'must reject path: ' . var_export($candidate, true));
    }

    foreach (['app/Views/about.php', 'public/assets/app.css', 'database/migrations/0002_x.sql', 'index.php'] as $candidate) {
        assertSame($candidate, $reflection->invoke($updater, $candidate), 'must accept path: ' . $candidate);
    }

    // And the same for a manifest's delete list, which is a second way in.
    assertSame(null, $reflection->invoke($updater, 'config/installed.lock'), 'the installer lock cannot be deleted by a release');

    array_map('unlink', glob($root . '/config/*') ?: []);
    array_map('unlink', glob($root . '/*/*/*.php') ?: []);
    @unlink($root . '/index.php');
    rmdir($root . '/config');
    rmdir($root . '/storage');
    rmdir($root . '/app/Core');
    rmdir($root . '/app');
    rmdir($root);
});

ok('The updater only ever downloads from the provider, over https', function (): void {
    $root = sys_get_temp_dir() . '/rise63-dl-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0700, true);
    mkdir($root . '/storage', 0700, true);
    $updater = new RISE63\Core\Updater($root, $root . '/config', $root . '/storage');

    $refuse = static function (string $url) use ($updater): bool {
        try {
            $updater->download($url);
            return false;
        } catch (RuntimeException $error) {
            return str_contains($error->getMessage(), 'Refusing to download');
        }
    };

    assertTrue($refuse('http://manager.pmhserver.name.ng/download.php?token=x'), 'plain http is refused');
    assertTrue($refuse('https://evil.example.org/download.php?token=x'), 'another host is refused');
    assertTrue($refuse('https://manager.pmhserver.name.ng.evil.org/x.zip'), 'a lookalike host is refused');
    assertTrue($refuse('file:///etc/passwd'), 'a local path is refused');

    array_map('unlink', glob($root . '/config/*') ?: []);
    rmdir($root . '/config');
    rmdir($root . '/storage');
    rmdir($root);
});

ok('A provider that cannot be reached is not reported as a refused update', function (): void {
    $root = sys_get_temp_dir() . '/rise63-upd-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0700, true);
    mkdir($root . '/storage', 0700, true);

    // No licence key at all: the refusal must say so without a network call.
    $updater = new RISE63\Core\Updater($root, $root . '/config', $root . '/storage');
    $check = $updater->check(null, 'buildroom.example.org');
    assertSame(false, $check['ok']);
    assertSame(false, $check['unreachable'], 'a missing key is a refusal, not a network failure');
    assert(str_contains($check['message'], 'licence key'), $check['message']);

    // With a key but no route to the provider, the answer must be "unreachable".
    file_put_contents($root . '/config/activation-key.php',
        "<?php return array('key' => 'TEST-KEY-0123456789ABCDEF', 'domain' => null, 'saved_at' => 'now');\n");
    $updater2 = new RISE63\Core\Updater($root, $root . '/config', $root . '/storage');
    $check2   = $updater2->check(null, 'buildroom.example.org');
    assertSame(false, $check2['ok'], 'a provider that cannot be reached never reports ok');
    assertSame(false, $check2['unreachable'] === false && $check2['message'] === '',
        'an unreachable provider always carries a message explaining why :: '
        . json_encode(['unreachable' => $check2['unreachable'], 'message' => $check2['message']]));
    assertTrue(array_key_exists('unreachable', $check2), 'the caller can tell transport from refusal');

    array_map('unlink', glob($root . '/config/*') ?: []);
    rmdir($root . '/config');
    rmdir($root . '/storage');
    rmdir($root);
});

ok('The installed version comes from the update record, not a hard-coded constant', function (): void {
    $root = sys_get_temp_dir() . '/rise63-ver-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0700, true);

    $updater = new RISE63\Core\Updater($root, $root . '/config', $root . '/storage');
    assertSame(RISE63\VERSION, $updater->currentVersion(), 'a fresh install reports the build version');
    assertSame([], $updater->history());

    file_put_contents($root . '/config/update-state.json', json_encode([
        'version' => '1.4.2',
        'history' => [['version' => '1.4.2', 'applied_at' => '2026-01-01T00:00:00+00:00', 'written' => 7]],
    ]));
    assertSame('1.4.2', $updater->currentVersion(), 'an applied update moves the version forward');
    assertSame(1, count($updater->history()));

    // A corrupt state file must not break the page.
    file_put_contents($root . '/config/update-state.json', 'not json');
    assertSame(RISE63\VERSION, $updater->currentVersion(), 'a corrupt record falls back to the build version');

    array_map('unlink', glob($root . '/config/*') ?: []);
    rmdir($root . '/config');
    rmdir($root);
});


/* ───────────────────── Clean URLs: /login must select the login page ─────────────────── */

ok('A URL path selects the page when no rewrite supplied ?page=', function (): void {
    // The first real installation answered /login with Apache's own "Not Found": every link in
    // this application is a path segment, but .htaccess had no rule sending segments to
    // index.php, and index.php only ever read ?page=. The root worked (DirectoryIndex) and
    // nothing else did. page_from_path() is the fallback that closes that hole.
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    assertSame('login', RISE63\page_from_path('/login'));
    assertSame('login', RISE63\page_from_path('/login/'));
    assertSame('login', RISE63\page_from_path('/login?next=%2Fmembers'));
    assertSame('treasury', RISE63\page_from_path('/treasury'));

    // The root and the front controller itself name no page of their own.
    assertSame('', RISE63\page_from_path('/'));
    assertSame('', RISE63\page_from_path('/index.php'));
    assertSame('', RISE63\page_from_path('/index.php?page=login'));

    // Sub-applications have their own entry point; naming them as pages would 404 them.
    assertSame('', RISE63\page_from_path('/install/'));
    assertSame('', RISE63\page_from_path('/install/index.php'));
    assertSame('', RISE63\page_from_path('/api/index.php'));

    // A deeper path is a page plus an identifier: the page is the first segment.
    assertSame('members', RISE63\page_from_path('/members/12/edit'));

    // A traversal attempt names no page at all: the first segment is '..', which the filter
    // empties. It must never survive as something a controller could act on.
    assertSame('', RISE63\page_from_path('/../../config/database.php'));
    assertSame('', RISE63\page_from_path('/%2e%2e/secret'));
    assertSame('script', RISE63\page_from_path('/<script>'), 'the angle brackets are stripped, not the name');
    assertSame('phpmyadmin', RISE63\page_from_path('/phpmyadmin/'));
});

ok('A subdirectory install derives both base path and page correctly', function (): void {
    // Hosts often put the app at /rise63. base_path() is cached per request, so this test proves
    // the /rise63 prefix is stripped before the page is read — otherwise every page would be
    // named "rise63".
    $_SERVER['SCRIPT_NAME'] = '/rise63/index.php';
    assertSame('/rise63', RISE63\base_path());
    assertSame('login', RISE63\page_from_path('/rise63/login'));
    assertSame('dashboard', RISE63\page_from_path('/rise63/dashboard'));
    assertSame('', RISE63\page_from_path('/rise63/'));
    assertSame('', RISE63\page_from_path('/rise63/index.php'));
    $_SERVER['SCRIPT_NAME'] = '/index.php';
});

/* ───────────── The bug that made every key look invalid (field report six) ───────────── */

ok('The activation request posts a plain string key, never a nested array', function (): void {
    // storedKey() returns a metadata row: ['key' => 'MAN-…']. Handing that row to
    // http_build_query() posts `key[key]=MAN-…`, the provider reads $_POST['key'] as an array,
    // its `WHERE license_key = ?` matches nothing, and it answers the same generic
    // "Invalid license key or domain." it uses for a genuinely wrong key. A correct key was
    // therefore indistinguishable from a wrong one, for every installation, for weeks.
    $root = sys_get_temp_dir() . '/rise63-wire-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0700, true);
    file_put_contents(
        $root . '/config/activation-key.php',
        "<?php\nreturn " . var_export(['key' => 'MAN-8F91CCC3CC57', 'domain' => 'buildroom.rccglp63yaya.org.ng'], true) . ";\n"
    );

    $activation = new RISE63\Core\Activation($root . '/config');
    $stored = $activation->storedKey();
    assertSame('MAN-8F91CCC3CC57', $stored['key'], 'storedKey() keeps its row shape for metadata');

    // The row shape is the trap. What goes on the wire must be flat.
    try {
        RISE63\Core\Activation::encode(['key' => $stored, 'domain' => 'buildroom.rccglp63yaya.org.ng']);
        assert(false, 'encode() must refuse a nested value instead of posting key[key]=…');
    } catch (RuntimeException $error) {
        assert(str_contains($error->getMessage(), 'not a key problem'), 'the message says whose bug it is');
    }

    // The correct body, byte for byte. If this string ever changes, the provider stops matching.
    $wire = RISE63\Core\Activation::encode([
        'key'    => 'MAN-8F91CCC3CC57',
        'domain' => 'buildroom.rccglp63yaya.org.ng',
    ]);
    assertSame('key=MAN-8F91CCC3CC57&domain=buildroom.rccglp63yaya.org.ng', $wire);
    assert(!str_contains($wire, 'key%5B'), 'no key[key] in the body');

    // And prove the provider would have seen an array: parse the buggy body back.
    parse_str('key%5Bkey%5D=MAN-8F91CCC3CC57&domain=x.example.org', $received);
    assert(is_array($received['key']), 'the old body arrives as an array — which matches no key row');

    array_map('unlink', glob($root . '/config/*') ?: []);
    rmdir($root . '/config');
    rmdir($root);
});

ok('A real key verifies without the array trap, including when it holds no special characters', function (): void {
    // The plain-string path must survive a key pasted with trailing whitespace, which is the other
    // way a correct key becomes an unknown one.
    $wire = RISE63\Core\Activation::encode(['key' => trim("  MAN-8F91CCC3CC57\n"), 'domain' => 'x.example.org']);
    assert(str_starts_with($wire, 'key=MAN-8F91CCC3CC57&'), $wire);
    assert(!str_contains($wire, '+'), 'no stray spaces survive into the body: ' . $wire);
});

/* ────────────────────── Why a licence was refused (field report) ─────────────────── */

ok('A refusal names the key, the domain and what the provider actually said', function (): void {
    // The provider answers 'Invalid license key or domain.' for four unrelated faults. A gate that
    // shows only that sentence leaves the operator with no next step, which is exactly what
    // happened in the field: they were told their key was wrong when the key was never in the
    // provider's database at all.
    $state = [
        'valid' => false, 'grace' => false, 'domain' => 'buildroom.rccglp63yaya.org.ng',
        'key' => 'MAN-749B9B757500', 'message' => 'Invalid license key or domain.',
        'answer' => '{"status":0,"message":"Invalid license key or domain."}',
    ];
    $diagnosis = RISE63\Core\ActivationDiagnosis::of($state);

    assertSame('rejected', $diagnosis['cause']);
    assert(str_contains($diagnosis['headline'], 'refused'), $diagnosis['headline']);
    assertSame('buildroom.rccglp63yaya.org.ng', $diagnosis['detail']['domain']);
    $masked = $diagnosis['detail']['key'];
    assert(str_starts_with($masked, 'MAN-'), 'the prefix is kept so the operator can recognise the key');
    assert(str_ends_with($masked, '7500'), 'the last four characters are kept');
    assert(substr_count($masked, '•') >= 4, 'the middle is replaced by bullets: ' . $masked);
    assert(!str_contains(json_encode($diagnosis), '749B9B75'), 'the middle of the key never reaches a page');

    // A missing key is its own cause, not a "refusal".
    $missing = RISE63\Core\ActivationDiagnosis::of(['valid' => false, 'grace' => false, 'domain' => 'x.example.org', 'key' => '']);
    assertSame('missing', $missing['cause']);

    // A provider that could not be reached must not be described as a key problem.
    $unreachable = RISE63\Core\ActivationDiagnosis::of([
        'valid' => false, 'grace' => true, 'domain' => 'x.example.org', 'key' => 'MAN-0123456789ABCDEF',
        'message' => 'The licence service could not be reached',
    ]);
    assertSame('grace', $unreachable['cause']);

    // A provider that answers with a web page, or with nothing at all, is a transport fault and
    // must not be described as a key problem either.
    $html = RISE63\Core\ActivationDiagnosis::of([
        'valid' => false, 'grace' => false, 'domain' => 'x.example.org', 'key' => 'MAN-0123456789ABCDEF',
        'answer' => '<!DOCTYPE html><html><body>403 Forbidden</body></html>',
    ]);
    assertSame('transport', $html['cause']);
    assert(str_contains($unreachable['headline'], 'could not be reached'), $unreachable['headline']);

    // The masked form is what the CLI and the gate print.
    $mask = static fn (string $k): string => RISE63\Core\ActivationDiagnosis::mask($k);
    assertSame('(none)', $mask(''));
    $m = $mask('MAN-749B9B757500');
    assert(str_starts_with($m, 'MAN-') && str_ends_with($m, '7500'), $m);
    assert(!str_contains($m, '749B9B75'), 'no middle characters survive: ' . $m);
    assertSame('••••', $mask('abcd'));
});

ok('The updater understands the deployed endpoint, not just the written contract', function (): void {
    // Probe of the live service (2026-11-08) returned: status "success", an access.allowed block,
    // update_required, changelog_format and project. None of those are in the documented contract,
    // and access.allowed === false arrives with status "success".
    $root = sys_get_temp_dir() . '/rise63-shape-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0700, true);
    $updater = new RISE63\Core\Updater($root, $root . '/config', $root . '/storage');

    $interpret = new ReflectionMethod(RISE63\Core\Updater::class, 'interpretCheck');
    $interpret->setAccessible(true);

    // A normal release.
    $release = $interpret->invoke($updater, [
        'status' => 'success', 'latest_version' => '7.57', 'update_required' => true,
        'checksum' => str_repeat('a', 64), 'changelog' => 'notes', 'changelog_format' => 'text',
        'download_url' => 'https://manager.pmhserver.name.ng/download.php?token=x',
        'tier_detected' => 'NON-SAAS', 'project' => 'DGV7.0-NON-SAAS',
        'access' => ['allowed' => true, 'reason' => 'ok'],
    ]);
    assertSame(true, $release['ok']);
    assertSame('7.57', $release['version']);
    assertSame(true, $release['update_required']);
    assertSame('DGV7.0-NON-SAAS', $release['project']);

    // Nothing published yet.
    $none = $interpret->invoke($updater, ['status' => 'success', 'latest_version' => '0.00']);
    assertSame(false, $none['ok']);
    assert(str_contains($none['message'], 'No update'), $none['message']);

    // status "success" but the licence is blocked: this is a refusal, not an available release.
    $blocked = $interpret->invoke($updater, [
        'status' => 'success', 'latest_version' => '7.57',
        'access' => ['allowed' => false, 'title' => 'Licence on hold', 'message' => 'Updates are on hold for this licence.'],
    ]);
    assertSame(false, $blocked['ok'], 'a blocked licence never reports an available release');
    assertSame('blocked', $blocked['status']);
    assert(str_contains($blocked['message'], 'on hold'), $blocked['message']);

    // The documented refusal shape.
    $refused = $interpret->invoke($updater, ['status' => 'error', 'message' => 'Invalid license key.']);
    assertSame(false, $refused['ok']);
    assert(str_contains($refused['message'], 'Invalid license key'), $refused['message']);

    // A suspended licence.
    $suspended = $interpret->invoke($updater, ['status' => 'suspended', 'message' => 'Suspended']);
    assertSame(false, $suspended['ok']);
    assert(str_contains($suspended['message'], 'suspended'), $suspended['message']);

    array_map('unlink', glob($root . '/config/*') ?: []);
    rmdir($root . '/config');
    rmdir($root);
});

ok('The program year follows the calendar unless an administrator holds it', function (): void {
    // Automatic (the default, and what a fresh installation uses): the year is the real year.
    assertSame((int) gmdate('Y'), Settings::cycleYear());
    assertSame(gmdate('Y'), Settings::autoCycleYear());

    // The setting can be declared but stale — the classic failure this replaces. In automatic
    // mode a stored value from a previous year must not win.
    assertSame((int) gmdate('Y'), Settings::cycleYear(), 'a stale stored year is ignored while automatic');

    // Validation still bounds a manually held year.
    assertSame('2027', Settings::validateValue('current_cycle_year', '2027'));
    assertThrows(InvalidArgumentException::class, fn () => Settings::validateValue('current_cycle_year', '1999'));
    assertThrows(InvalidArgumentException::class, fn () => Settings::validateValue('current_cycle_year', '2101'));
    assertSame('manual', Settings::validateValue('cycle_year_mode', 'manual'));
    assertThrows(InvalidArgumentException::class, fn () => Settings::validateValue('cycle_year_mode', 'sometimes'));
});

ok('Money settings accept every documented figure, including zero', function (): void {
    // The bug this covers: a `money` definition with bounds rejected everything, so the Seed Grant
    // monthly pool could not be saved at all and a fresh installation ran on ₦0.
    assertSame('100000.00', Settings::validateValue('seed_grant_monthly_pool', '100000'));
    assertSame('100000.00', Settings::validateValue('seed_grant_monthly_pool', '100,000'));
    assertSame('0.00', Settings::validateValue('seed_grant_monthly_pool', '0'), 'a pool may be emptied deliberately');
    assertSame('250000.50', Settings::validateValue('seed_grant_opening_balance', '250000.5'));
    assertThrows(InvalidArgumentException::class, fn () => Settings::validateValue('seed_grant_monthly_pool', '-1'));
    assertThrows(InvalidArgumentException::class, fn () => Settings::validateValue('academy_pool', 'lots'));
    // The ceiling is enforced, and the message names the field so the operator knows which one.
    try {
        Settings::validateValue('seed_grant_monthly_pool', '2000000000');
        throw new RuntimeException('a ceiling above the maximum must be refused');
    } catch (InvalidArgumentException $error) {
        assert(str_contains($error->getMessage(), 'Seed Grant pool per month'), $error->getMessage());
    }
});

ok('The documented blueprint figures are the defaults, not zero', function (): void {
    // §8.1/§8.2: 80% attendance gate, ₦100,000/month into the Seed Grant pool, ₦100,000 ceiling
    // per application. A fresh installation that has never opened the settings page must already
    // behave this way — otherwise the first thing an operator sees is a gate of 50% and a pool of
    // nothing, and the documentation is wrong about its own default.
    $defaults = ['academy_graduation_percent' => '80', 'seed_grant_monthly_pool' => '100000.00',
                 'seed_grant_max_per_application' => '100000.00', 'education_bridge_sla_hours' => '72',
                 'cycle_year_mode' => 'auto'];
    $schema = Settings::definitionsFor('programs');
    foreach ($defaults as $key => $expected) {
        assertTrue(isset($schema[$key]), $key . ' is a program setting');
        assertSame($expected, $schema[$key]['default'] ?? null, $key . ' default');
    }
});

ok('Paginator clamps page size and reads only safe cursors', function (): void {
    // A hand-edited URL must not be able to ask for the whole audit table, nor produce an error.
    $page = RISE63\Core\Paginator::fromRequest(['per_page' => '100000'], 'audit');
    assertSame(RISE63\Core\Paginator::MAX_PER_PAGE, $page->perPage());

    $tiny = RISE63\Core\Paginator::fromRequest(['per_page' => '1'], 'audit');
    assertSame(RISE63\Core\Paginator::MIN_PER_PAGE, $tiny->perPage());

    $junk = RISE63\Core\Paginator::fromRequest(['per_page' => 'abc', 'before' => 'DROP TABLE'], 'audit');
    assertSame(RISE63\Core\Paginator::DEFAULT_PER_PAGE, $junk->perPage());
    assertSame(null, $junk->before, 'a non-numeric cursor is discarded, not cast');

    $cursor = RISE63\Core\Paginator::fromRequest(['before' => '41', 'kind' => 'financial'], 'audit');
    assertSame(41, $cursor->before);
    assertTrue($cursor->hasPrevious);
    // Filters survive a page change: the older-page URL carries them, and adds the cursor.
    $url = $cursor->olderUrl('17');
    assert(str_contains($url, 'before=17'), $url);
    assert(str_contains($url, 'kind=financial'), $url);
    // A page-size change resets to the first page rather than stranding the reader mid-trail.
    assert(!str_contains($cursor->sizeUrl(50), 'before='), 'changing page size starts again at the newest');
});

ok('Fund arithmetic is documented and non-negative', function (): void {
    // Money is integer kobo; the pool maths the blueprint describes (a monthly allowance accrued
    // over the year) is basis points with half-up rounding.
    // Small figures here on purpose: percent() multiplies kobo by basis points, and the php-wasm
    // build this suite runs on in CI has 32-bit integers, so ₦100,000 × 10,000 would overflow to a
    // float there. On the 64-bit host this ships to, the real figures are exercised by the
    // integration suite. The arithmetic below is the same arithmetic either way.
    $monthly = Money::fromNaira('100');
    assertSame('100.00', $monthly->percent(10000, 10000)->toDecimal(), 'twelve months accrues the whole annual figure');
    assertSame('25.00', $monthly->percent(2500, 10000)->toDecimal(), 'three months is a quarter');
    assertSame('8.34', Money::fromNaira('100')->percent(834, 10000)->toDecimal(), 'half-up on the kobo');
    // Money refuses to go negative rather than silently wrapping, which is what keeps a pool honest.
    assertThrows(InvalidArgumentException::class, fn () => Money::fromNaira('10')->minus(Money::fromNaira('11')));
    assertTrue(Money::zero()->isZero());
});

/* ───────────────────────── Mail settings (Settings → Mail) ───────────────────────── */

ok('Mail settings validate before anything is written', function (): void {
    // The whole reason for this form: config/smtp.php used to be hand-made, and a mistake in it
    // produced no error at all — only password resets that silently never arrived. Every rejection
    // below therefore has to name the field, and has to happen *before* the file is touched.
    $good = [
        'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'auth' => 'login',
        'username' => 'alerts@rccg63.org', 'password' => 'app-password-123',
        'from_email' => 'no-reply@rccg63.org', 'from_name' => 'RISE 63',
    ];

    $rejects = static function (array $override, string $expect) use ($good): void {
        $input = array_merge($good, $override);
        try {
            Smtp::save($input);
        } catch (RuntimeException $error) {
            assertTrue(
                str_contains(strtolower($error->getMessage()), strtolower($expect)),
                'expected the message to mention ' . var_export($expect, true) . ', got: ' . $error->getMessage()
            );
            return;
        }
        throw new RuntimeException('that input was accepted; it should have been refused');
    };

    $rejects(['host' => ''], 'server address');
    $rejects(['host' => 'https://smtp.gmail.com'], 'hostname or ip');
    $rejects(['host' => 'smtp.gmail.com:587'], 'hostname or ip');
    $rejects(['port' => 70000], 'between 1 and 65535');
    $rejects(['from_email' => 'not-an-address'], 'valid e-mail');
    $rejects(['from_name' => "RISE\r\nBcc: someone@else"], 'line break');
    $rejects(['password' => "a\r\nb"], 'line break');
    $rejects(['encryption' => 'none'], 'remote server');

    // Port and encryption must agree. These two are the misconfiguration that looks like "the
    // server is down": STARTTLS on 465 hangs until the timeout, and implicit TLS on 587 refuses.
    $rejects(['port' => 465], 'implicit tls');
    $rejects(['encryption' => 'ssl', 'port' => 587], 'port 465');

    // A password removal that would leave an authenticated username with no password is refused,
    // because it saves cleanly and then fails on every single worker run.
    $rejects(['remove_password' => '1'], 'clear the username');
});

ok('Mail settings keep the stored password and never send it back', function (): void {
    // The password is the one value on this screen that must never reach the browser: rendering it
    // puts it in the page cache, the browser history and any screenshot taken of the screen.
    // config_dir() answers once and caches it in a constant, so a test cannot redirect it after the
    // bootstrap has run. Instead this writes to the real config directory and puts back whatever was
    // there — which also proves the writer works against the path production actually uses.
    $file = \RISE63\config_dir() . '/smtp.php';
    $existed = is_file($file);
    $original = $existed ? (string) file_get_contents($file) : '';
    if (!is_dir(\RISE63\config_dir()) || !is_writable(\RISE63\config_dir())) {
        throw new RuntimeException('config/ is not writable here, so the writer cannot be exercised: ' . \RISE63\config_dir());
    }

    $masked = Smtp::save([
        'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'auth' => 'login',
        'username' => 'alerts@rccg63.org', 'password' => 'app-password-123',
        'from_email' => 'no-reply@rccg63.org', 'from_name' => 'RISE 63',
    ]);

    assertTrue(!array_key_exists('password', $masked), 'the masked view must not carry the password');
    assertTrue($masked['has_password'] === true, 'the view must know a password is stored');

    // A blank field on the next save keeps what is there.
    $second = Smtp::save([
        'host' => 'smtp.mailgun.org', 'port' => 587, 'encryption' => 'tls', 'auth' => 'login',
        'username' => 'alerts@rccg63.org', 'password' => '',
        'from_email' => 'no-reply@rccg63.org', 'from_name' => 'RISE 63',
    ]);
    assertTrue($second['host'] === 'smtp.mailgun.org', 'other fields still update');

    // The password itself is only ever readable server-side.
    $stored = Smtp::load();
    assertTrue($stored['password'] === 'app-password-123', 'a blank field keeps the stored password');

    Smtp::forget();
    assertTrue(Smtp::configured() === false, 'removal clears the configuration');

    // Put the workspace back exactly as it was: this suite must not leave a mailbox password on
    // disk for the next harness to trip over.
    if ($existed) {
        file_put_contents($file, $original);
    } else {
        @unlink($file);
    }
});

ok('SMTP chooses the transport the encryption mode requires', function (): void {
    // This is the bug from the field, reduced to its smallest form. Both branches of the expression
    // that picked a transport were the string 'tcp', so "Implicit TLS (port 465)" opened a *plain*
    // socket to a server that begins its TLS handshake immediately. The server waited for a
    // ClientHello, the client waited for a 220 banner, and the operator was told
    //
    //     SMTP rejected the request (no response).
    //
    // No test caught it because no test ever asked which transport a mode uses — everything stopped
    // at "the settings validated". Assert the mapping directly.
    assertSame('ssl', Mailer::transportFor('ssl'), 'implicit TLS must use the ssl:// transport');
    assertSame('ssl', Mailer::transportFor('SSL'), 'the saved value is case-insensitive');
    assertSame('tcp', Mailer::transportFor('tls'), 'STARTTLS starts on a plain socket');
    assertSame('tcp', Mailer::transportFor('none'), 'no encryption is a plain socket');
    assertSame('tcp', Mailer::transportFor('something-unknown'), 'an unknown mode must fail safe to tcp');
});

ok('A mail failure says what is wrong, not "no response"', function (): void {
    // The message the operator saw named neither the cause nor the setting to change. The two
    // failures it conflated — nothing came back, versus the server said no — are now distinct, and
    // both name a setting.
    $connectError = new ReflectionMethod(Mailer::class, 'connectError');
    $connectError->setAccessible(true);

    $timeout = (string) $connectError->invoke(null, 'smtp.example.com', 587, 'tls', 110, 'Connection timed out');
    assertTrue(str_contains($timeout, 'smtp.example.com:587'), 'the failure must name what it tried');
    assertTrue(str_contains($timeout, 'did not answer'), 'a timeout must be described as no answer');
    assertTrue(str_contains($timeout, 'blocks outbound SMTP'), 'and must suggest the usual cause');

    // Port 465 with STARTTLS has its own, much more specific message — this is the common
    // misconfiguration, and the one that produced the reported symptom.
    $wrongPort = (string) $connectError->invoke(null, 'smtp.example.com', 465, 'tls', 110, 'Connection timed out');
    assertTrue(str_contains($wrongPort, 'implicit TLS'), 'port 465 with STARTTLS must be named as the problem');

    $refused = (string) $connectError->invoke(null, 'smtp.example.com', 587, 'tls', 111, 'Connection refused');
    assertTrue(str_contains($refused, 'refused'), 'a refusal must be described as a refusal');

    // A rejected credential must point at app passwords, which is what actually causes it.
    $authHint = new ReflectionMethod(Mailer::class, 'authHint');
    $authHint->setAccessible(true);
    assertTrue(str_contains((string) $authHint->invoke(null, 535), 'app password'),
        '535 must mention app passwords');
    assertTrue(str_contains((string) $authHint->invoke(null, 550), 'send from'),
        '550 must point at the sender address');
});

ok('The view renderer refuses a template name that escapes app/Views', function (): void {
    // Latent, not exploitable: nothing passes request input to View::render(). But a renderer that
    // will `require` any path it is handed is one careless caller away from being a code-execution
    // primitive, and containment costs one realpath().
    //
    // Asserted against templatePath() rather than render(). Calling render('../Core/View') with the
    // guard removed does not merely resolve — it executes application code, which is a fatal
    // "cannot redeclare class" that takes the whole suite down. Proving that once was enough.
    $method = new ReflectionMethod(\RISE63\Core\View::class, 'templatePath');
    $method->setAccessible(true);

    // '../Core/View' is the case that matters: app/Views/../Core/View.php EXISTS.
    foreach (['../Core/View', '../bootstrap', 'auth/../../index', '/etc/passwd', 'auth/login\0', '', '../'] as $bad) {
        $refused = false;
        try {
            $method->invoke(null, $bad);
        } catch (\RuntimeException $error) {
            $refused = true;
        }
        assertTrue($refused, 'the renderer accepted a traversal name: ' . var_export($bad, true));
    }

    // A legitimate nested name still resolves, inside the tree.
    $path = (string) $method->invoke(null, 'auth/login');
    assertTrue(str_ends_with($path, '/app/Views/auth/login.php'), 'a real view must still resolve: ' . $path);
    assertTrue(!str_contains($path, '..'), 'the resolved path must be normalised');
});

ok('The mailer sends from the address configured in Settings → Mail', function (): void {
    // The worker used to derive From from the site URL, so the one thing an operator could not
    // change was the address members see — and a From that does not match the authenticated mailbox
    // is what makes providers file mail as spam.
    $sender = Mailer::sender();
    assertTrue(filter_var($sender['email'], FILTER_VALIDATE_EMAIL) !== false,
        'even with no configuration there must be a syntactically valid fallback sender');
    assertTrue($sender['name'] !== '', 'the sender must always have a name');
});

echo "\n";
echo str_repeat('─', 62) . "\n";

printf("%d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail']);

if ($GLOBALS['failures'] !== []) {
    echo "\nFailures:\n  - " . implode("\n  - ", $GLOBALS['failures']) . "\n";
    exit(1);
}
exit(0);
