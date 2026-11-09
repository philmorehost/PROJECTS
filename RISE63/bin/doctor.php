<?php
declare(strict_types=1);

/**
 * Read-only post-install self-check. Run it after setup and after any change to the host:
 *
 *   php bin/doctor.php             full report
 *   php bin/doctor.php --quiet     problems and notes only (for cron / monitoring)
 *   php bin/doctor.php --no-net    skip the licence call
 *
 * Exit codes: 0 nothing blocking · 1 something is broken · 2 not installed yet.
 *
 * It writes nothing: no migrations, no settings, no cache. Safe on a live site.
 */

// cli, but also phpdbg and embed: those are never a web request, and the test harness runs the
// embedded engine. A real request arrives as apache2handler, fpm-fcgi or cli-server.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
    http_response_code(404);
    exit;
}

// Named streams rather than the STDOUT/STDERR constants: those constants are absent in some
// embedded SAPIs, and a fatal here would hide the report this script exists to print.
$stdout = @fopen('php://stdout', 'w') ?: null;
$stderr = @fopen('php://stderr', 'w') ?: null;
$out = static function (string $text) use ($stdout): void {
    if ($stdout === null) {
        echo $text;
        return;
    }
    fwrite($stdout, $text);
};
$err = static function (string $text) use ($stderr, $stdout): void {
    if ($stderr === null) {
        $stdout === null ? print($text) : fwrite($stdout, $text);
        return;
    }
    fwrite($stderr, $text);
};

require dirname(__DIR__) . '/app/bootstrap.php';

use RISE63\Core\Activation;
use RISE63\Core\Database;
use RISE63\Core\Mailer;
use RISE63\Core\Migrator;
use RISE63\Core\Security;

use function RISE63\config_dir;

$argvList = $_SERVER['argv'] ?? [];
$quiet    = in_array('--quiet', $argvList, true);
$net      = !in_array('--no-net', $argvList, true);

/** @var list<array{ok:bool|null, name:string, detail:string}> $report */
$report = [];
$add = static function (?bool $ok, string $name, string $detail) use (&$report): void {
    $report[] = ['ok' => $ok, 'name' => $name, 'detail' => $detail];
};

if (!is_file(config_dir() . '/installed.lock')) {
    $err("RISE 63 is not installed yet — finish the setup wizard first.\n");
    exit(2);
}

/* ───────────────────────────── PHP and extensions ───────────────────────────── */

// PHP's own version constant carries the name of a bootstrap constant as a substring, which the
// bare-constant guard in tests/render.php reads as a reference. Read it through a variable.
$running = phpversion() ?: 'unknown';

$add(version_compare($running, \RISE63\MIN_PHP, '>='), 'PHP runtime',
    $running . ' running; this build needs ' . \RISE63\MIN_PHP . ' or newer');

foreach (['pdo' => true, 'pdo_mysql' => true, 'json' => true,
          'curl' => false, 'gd' => false, 'mbstring' => false] as $ext => $required) {
    $loaded = extension_loaded($ext);
    $add(
        $loaded || !$required,
        'Extension ' . $ext,
        $loaded ? 'loaded' : ($required ? 'MISSING (required)' : 'not loaded (recommended)')
    );
}

/* ───────────────────────────── Files, modes, config ────────────────────────── */

foreach (['config', 'storage'] as $folder) {
    $path = \RISE63\ROOT . '/' . $folder;
    $add(
        is_dir($path) && is_writable($path),
        $folder . '/ writable',
        is_dir($path) ? (is_writable($path) ? 'yes' : 'NO') : 'missing'
    );
}

// The web server must refuse these folders. .htaccess is what does it on Apache shared hosting.
foreach (['config', 'storage'] as $folder) {
    $guard = \RISE63\ROOT . '/' . $folder . '/.htaccess';
    $add(is_file($guard), $folder . '/.htaccess present',
        is_file($guard) ? 'yes' : 'MISSING — add it, or deny the folder in the vhost');
}

$keyFile = config_dir() . '/' . Activation::KEY_FILE;
if (is_file($keyFile)) {
    clearstatcache(true, $keyFile);
    $mode = substr(sprintf('%o', fileperms($keyFile)), -4);
    $add($mode === '0600', 'Key file permissions', $mode . ' (should be 0600)');
} else {
    $add(false, 'Licence key file', 'missing: ' . Activation::KEY_FILE . ' in config/');
}

// Migration state: the failure this check exists for was a People page that read a column from
// migration 0002 on a database that had never received it. Nothing said so; the page just 500'd.
try {
    $migrations = (new Migrator(RISE63\ROOT))->pending();
    $add(
        $migrations === [],
        'Database migrations',
        $migrations === []
            ? 'all applied'
            : count($migrations) . ' pending — run: php bin/migrate.php'
    );
} catch (Throwable $error) {
    $add(false, 'Database migrations', 'could not be read: ' . $error->getMessage());
}

if (Mailer::configured()) {
    $config     = @include config_dir() . '/smtp.php';
    $host       = is_array($config) ? trim((string) ($config['host'] ?? '')) : '';
    $port       = is_array($config) ? (int) ($config['port'] ?? 587) : 587;
    $encryption = is_array($config) ? strtolower((string) ($config['encryption'] ?? 'tls')) : 'tls';
    $from       = is_array($config) ? trim((string) ($config['from_email'] ?? '')) : '';

    $add(true, 'SMTP host', $host . ':' . $port . ' ' . strtoupper($encryption)
        . ($from !== '' ? ' — sending as ' . $from : ''));

    // ── Does mail actually leave this machine?
    //
    // The old check stopped at "a host is configured", which is why a broken transport could look
    // healthy from every screen in the application while every message queued and then failed. This
    // performs the real handshake — connect, EHLO, STARTTLS, AUTH, QUIT — and sends nothing.
    //
    // With --no-net it reports what it would have tried, because a monitoring cron must not depend
    // on a remote mail server being reachable.
    if (!$net) {
        $add(null, 'SMTP connection', 'not tested (--no-net)');
    } elseif (!Mailer::tlsAvailable()) {
        // Without the openssl extension every TLS mode fails with a message about a missing socket
        // transport, which reads like an application bug rather than a PHP build.
        $add(false, 'SMTP connection',
            'this PHP has no "ssl" stream transport, so no TLS mail can be sent — enable the openssl extension');
    } else {
        try {
            $mailer = Mailer::fromConfig();
            $mailer->quit();
            $add(true, 'SMTP connection', 'connected to ' . $host . ':' . $port
                . ' and authenticated — mail can leave this server');
        } catch (Throwable $error) {
            $add(false, 'SMTP connection', $error->getMessage());
        }
    }
} else {
    $add(false, 'SMTP host', 'config/smtp.php is missing or has no host — mail will queue and never send');
}

/* ──────────────────────────────────── Database ─────────────────────────────── */

try {
    $tables = (int) Database::value(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    );
    $add($tables > 0, 'Database connection', $tables . ' table(s) in this schema');

    $admins = (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'provincial_admin'");
    $add($admins > 0, 'Administrator account', $admins . ' provincial administrator(s)');

    $pending = (int) Database::value("SELECT COUNT(*) FROM notification_outbox WHERE status = 'pending'");
    $add(true, 'Mail queue', $pending . ' message(s) waiting');

    // Triggers are optional: some hosts deny CREATE TRIGGER to the application user, and the
    // ledger stays append-only in application code. Reported, never failed.
    $triggers = (int) Database::value(
        'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()'
    );
    $add($triggers > 0 ? true : null, 'Ledger triggers',
        $triggers > 0 ? $triggers . ' installed' : 'none — the ledger is still append-only in code');

    $title = (string) Database::value(
        "SELECT setting_value FROM security_settings WHERE setting_key = 'site_title'"
    );
    $add($title !== '', 'Site title', $title !== '' ? $title : 'not set');

    $notify = (string) Database::value(
        "SELECT setting_value FROM security_settings WHERE setting_key = 'notification_email'"
    );
    $add(
        $notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL) !== false,
        'Security e-mail',
        $notify !== '' ? $notify : 'not set — security alerts have nowhere to go'
    );
} catch (Throwable $error) {
    $add(false, 'Database connection', $error->getMessage());
}

/* ─────────────────────────────────── Transport ─────────────────────────────── */

// A CLI run has no request, so it cannot tell whether the site is served over TLS. Reporting
// "not detected" there would be a false alarm on every cron run; report a note instead.
$isWebRequest = !in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
if ($isWebRequest) {
    $add(Security::isHttps(), 'HTTPS', Security::isHttps()
        ? 'this request arrived over TLS'
        : 'not detected — secure cookies are only issued over TLS. Behind a proxy/CDN, set HTTPS=on');
} else {
    $add(null, 'HTTPS', 'not a web request, so it cannot be judged here: open https://<your-domain>/ '
        . 'in a browser and confirm the padlock');
}

/* ──────────────────────────────────── Licence ──────────────────────────────── */

$activation = new Activation(config_dir());
$stored     = $activation->storedKey();
if ($stored === null) {
    $add(false, 'Licence key', 'none stored — the site will not sign anyone in');
} else {
    $add(true, 'Licence key', 'stored, mode 0600, never printed');

    $host   = (string) (getenv('RISE63_DOMAIN') ?: gethostname() ?: '');
    $domain = Activation::domainFromHost($host);

    if (!$net) {
        $add(null, 'Licence call', 'skipped (--no-net)');
    } elseif (!Activation::validDomain($domain)) {
        $add(null, 'Licence call', 'no usable hostname here (got "' . $host . '"); pass RISE63_DOMAIN=example.org');
    } elseif ($activation->domainAccepted($domain)) {
        $add(true, 'Licence for ' . $domain, 'already confirmed by the provider');
    } else {
        $state = $activation->check($host);
        $add($state['valid'], 'Licence for ' . $domain, $state['valid'] || $state['grace']
            ? $state['message']
            : $state['message'] . ' — run bin/license-check.php --probe');
    }
}

/* ──────────────────────────────────── Report ───────────────────────────────── */

$failures = 0;
$notes    = 0;
foreach ($report as $row) {
    if ($row['ok'] === false) {
        $failures++;
    } elseif ($row['ok'] === null) {
        $notes++;
    }
}

if (!$quiet) {
    $out("RISE 63 self-check — " . gmdate('j M Y H:i') . " UTC\n" . str_repeat('-', 74) . "\n");
}

foreach ($report as $row) {
    if ($quiet && $row['ok'] === true) {
        continue;
    }
    $mark = $row['ok'] === true ? 'ok  ' : ($row['ok'] === false ? 'FAIL' : 'note');
    $out(sprintf("%s  %-30s %s\n", $mark, $row['name'], $row['detail']));
}

if (!$quiet) {
    $out(str_repeat('-', 74) . "\n");
    $out($failures === 0
        ? 'No blocking problems' . ($notes > 0 ? " ({$notes} note(s))" : '') . "\n"
        : $failures . ' problem(s) to fix' . "\n");
}

exit($failures === 0 ? 0 : 1);
