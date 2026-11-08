<?php
declare(strict_types=1);

namespace RISE63\Core;

use PDO;
use RuntimeException;

/**
 * Four-stage silent installer.
 *
 * Stage 1 environment + system activation · Stage 2 database · Stage 3 administrator ·
 * Stage 4 lock. A filesystem mutex prevents two installers running at once, and once
 * config/installed.lock exists the wizard refuses to do anything but explain itself.
 */
final class Installer
{
    public const STAGES = [1 => 'System check', 2 => 'Database', 3 => 'Administrator', 4 => 'Complete'];

    public function __construct(private readonly string $configDir, private readonly string $root)
    {
    }

    public function installed(): bool
    {
        return is_file($this->configDir . '/installed.lock');
    }

    public function stage(): int
    {
        $stage = (int) ($_SESSION['rise63_install_stage'] ?? 1);
        return $stage >= 1 && $stage <= 4 ? $stage : 1;
    }

    public function advance(int $stage): void
    {
        $_SESSION['rise63_install_stage'] = max(1, min(4, $stage));
    }

    /**
     * Stage 1 facts. Requirements are reported, not enforced silently.
     *
     * @return array<string, array{label:string, ok:bool, detail:string}>
     */
    public function environment(): array
    {
        $checks = [];
        $checks['php'] = [
            'label'  => 'PHP ' . \RISE63\MIN_PHP . ' or newer',
            'ok'     => version_compare(PHP_VERSION, \RISE63\MIN_PHP, '>='),
            'detail' => 'Running PHP ' . PHP_VERSION,
        ];
        foreach (['pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL driver', 'json' => 'JSON', 'curl' => 'cURL (activation)', 'gd' => 'GD (image handling)', 'mbstring' => 'Multibyte strings'] as $ext => $label) {
            $loaded = extension_loaded($ext);
            $checks['ext_' . $ext] = [
                'label'  => $label,
                'ok'     => $loaded || $ext === 'mbstring',
                'detail' => $loaded ? 'Loaded' : ($ext === 'mbstring' ? 'Recommended' : 'Missing'),
            ];
        }
        $checks['config'] = [
            'label'  => 'config/ is writable',
            'ok'     => is_dir($this->configDir) && is_writable($this->configDir),
            'detail' => is_dir($this->configDir) ? ($this->isWritable($this->configDir) ? 'Writable' : 'Not writable') : 'Missing',
        ];
        $checks['storage'] = [
            'label'  => 'storage/ is writable',
            'ok'     => is_dir($this->root . '/storage') && $this->isWritable($this->root . '/storage'),
            'detail' => is_dir($this->root . '/storage') ? ($this->isWritable($this->root . '/storage') ? 'Writable' : 'Not writable') : 'Missing',
        ];
        $checks['https'] = [
            'label'  => 'HTTPS',
            'ok'     => Security::isHttps() || $this->isLocalhost(),
            'detail' => Security::isHttps() ? 'TLS active' : ($this->isLocalhost() ? 'Local development' : 'Enable HTTPS before going live'),
        ];
        $checks['pdo_options'] = [
            'label'  => 'Prepared-statement support',
            'ok'     => in_array('mysql', PDO::getAvailableDrivers(), true),
            'detail' => 'Drivers: ' . (implode(', ', PDO::getAvailableDrivers()) ?: 'none'),
        ];
        return $checks;
    }

    public function environmentOk(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Stage 2 — create the schema in an empty database only.
     *
     * @return array{host:string,port:int,name:string,user:string,password:string,socket:?string}
     */
    public function createDatabase(array $input): array
    {
        $host = trim((string) ($input['host'] ?? 'localhost'));
        $name = trim((string) ($input['name'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));
        $pass = (string) ($input['password'] ?? '');
        $port = (int) ($input['port'] ?? 3306);
        $socketRaw = trim((string) ($input['socket'] ?? ''));

        if ($host === '' || strlen($host) > 255) {
            throw new RuntimeException('Enter the database host provided by your host.');
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1) {
            throw new RuntimeException('Database names may only contain letters, digits and underscores.');
        }
        if ($user === '' || strlen($user) > 64) {
            throw new RuntimeException('Enter the database user name.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Enter a valid database port.');
        }

        $config = [
            'host'     => $host,
            'port'     => $port,
            'name'     => $name,
            'user'     => $user,
            'password' => $pass,
            'socket'   => $socketRaw === '' ? null : $socketRaw,
        ];

        $pdo = Database::connect($config);

        if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) {
            throw new RuntimeException('That database already contains tables. Use a dedicated empty database for RISE 63.');
        }

        $schemaFile = $this->root . '/database/schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException('The schema file is missing from the installation package.');
        }

        Database::runScript($pdo, (string) file_get_contents($schemaFile));

        $countries = $this->root . '/database/countries.sql';
        if (is_file($countries)) {
            Database::runScript($pdo, (string) file_get_contents($countries));
        }
        $seed = $this->root . '/database/seed.sql';
        if (is_file($seed)) {
            Database::runScript($pdo, (string) file_get_contents($seed));
        }
        $guards = $this->root . '/database/ledger-guards.sql';
        if (is_file($guards)) {
            try {
                Database::runScript($pdo, (string) file_get_contents($guards));
            } catch (\PDOException) {
                // Some managed hosts deny CREATE TRIGGER to the application user. The ledger is
                // still append-only in application code; see docs/DEPLOYMENT.md.
            }
        }

        // schema_migrations stays empty on a fresh install: database/migrations/ then runs from
        // 0001 on the next `php bin/migrate.php`, so a new install and a migrated one converge.

        $this->writeConfig('database.php', $config);
        Database::use($pdo);
        $this->advance(3);
        return $config;
    }

    /**
     * Stage 3 — first provincial administrator, site title, PIN.
     */
    public function createAdministrator(array $input): int
    {
        $name   = trim((string) ($input['full_name'] ?? ''));
        $email  = strtolower(trim((string) ($input['email'] ?? '')));
        $pass   = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        $pin    = (string) ($input['pin'] ?? '');
        $title  = trim((string) ($input['site_title'] ?? ''));
        $notify = strtolower(trim((string) ($input['notification_email'] ?? '')));

        if ($name === '' || mb_strlen($name) > 150) {
            throw new RuntimeException('Enter the administrator’s full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            throw new RuntimeException('Enter a valid administrator e-mail address.');
        }
        $problem = Security::passwordProblem($pass, $email, $name);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        if (!hash_equals($pass, $confirm)) {
            throw new RuntimeException('The two passwords do not match.');
        }
        $pinProblem = Security::pinProblem($pin);
        if ($pinProblem !== null) {
            throw new RuntimeException($pinProblem);
        }
        if ($title === '' || mb_strlen($title) > 120) {
            throw new RuntimeException('Enter a site title of up to 120 characters.');
        }
        if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid security notification e-mail address.');
        }

        // Re-check the licence here as well as in stage 1. It is normally answered from the 6-hour
        // cache; doing it again means a key revoked mid-setup cannot slip an administrator account
        // into a database.
        $activation = new Activation($this->configDir);
        $state      = $activation->check((string) ($_SERVER['HTTP_HOST'] ?? ''), true);
        if (!$state['valid']) {
            throw new RuntimeException('The licence is not confirmed for this domain: ' . $state['message']);
        }

        return Database::transaction(function () use ($name, $email, $pass, $pin, $title, $notify): int {
            if ((int) Database::value('SELECT COUNT(*) FROM users') > 0) {
                throw new RuntimeException('An administrator already exists for this installation.');
            }
            Database::run(
                'INSERT INTO users (full_name, email, password_hash, role, transaction_pin_hash) VALUES (?,?,?,?,?)',
                [$name, $email, Security::hashPassword($pass), 'provincial_admin', Security::hashPassword($pin)]
            );
            $id = Database::id();
            Settings::flush();
            if ($title !== (string) Settings::get('site_title')) {
                Settings::set('site_title', $title, $id);
            }
            Settings::set('notification_email', $notify !== '' ? $notify : $email, $id);
            Audit::log($id, 'install.administrator', 'user', (string) $id, 'First administrator created during setup.');
            $this->advance(4);
            return $id;
        });
    }

    /** Stage 4 — lock the installer. */
    public function complete(): void
    {
        if (!is_file($this->configDir . '/database.php')) {
            throw new RuntimeException('Database configuration is missing; setup cannot be completed.');
        }
        if ((int) Database::value('SELECT COUNT(*) FROM users') < 1) {
            throw new RuntimeException('Create the administrator account before finishing.');
        }
        $lock = $this->configDir . '/installed.lock';
        if (file_put_contents($lock, gmdate('c') . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not write config/installed.lock.');
        }
        @chmod($lock, 0600);
        // The cached token is no longer needed once the installation is locked.
        @unlink($this->configDir . '/activation-cache.json');
        unset($_SESSION['rise63_install_stage']);
        session_regenerate_id(true);
    }

    /** @param array<string, mixed> $config */
    public function writeConfig(string $file, array $config): void
    {
        $path = $this->configDir . '/' . $file;
        $php  = "<?php\n// Generated by the RISE 63 installer. Keep this file private (mode 0600).\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($path, $php, LOCK_EX) === false) {
            throw new RuntimeException('Could not write ' . $file . '. Check that config/ is writable.');
        }
        @chmod($path, 0600);
    }

    private function isWritable(string $path): bool
    {
        $probe = rtrim($path, '/') . '/.write-probe-' . bin2hex(random_bytes(4));
        $ok    = @file_put_contents($probe, 'ok') !== false;
        if ($ok) {
            @unlink($probe);
        }
        return $ok;
    }

    private function isLocalhost(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        return in_array(preg_replace('/:\d+$/', '', $host), ['localhost', '127.0.0.1', '::1'], true);
    }
}

/** Raised when setup steps arrive out of order or the wizard is locked. */
final class Blocked extends \RuntimeException
{
}
