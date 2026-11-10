<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\BruteForceProtection;
use App\Services\LicenseService;
use App\Support\Database;
use App\Support\DynamicBrand;
use App\Support\Env;
use App\Support\Security;
use App\Support\Settings;

/**
 * Manages the 4-Stage Dynamic Web Installer:
 * Stage 1: System Requirements & License Manager Verification (manager.pmhserver.name.ng)
 * Stage 2: Database Connection, .env Auto-Writer & Schema Migrations
 * Stage 3: Super Admin Account & Dynamic First-Letter SVG Branding
 * Stage 4: Completion Lockfile (`storage/installed`) & Cron Instructions
 */
final class InstallController
{
    /**
     * Evaluate server requirements for Stage 1.
     *
     * @return array{all_passed:bool,checks:list<array{label:string,required:string,current:string,passed:bool,advisory?:bool}>}
     */
    public function checkRequirements(): array
    {
        $hasTransport = LicenseService::hasCustomTransport();
        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');

        $checks = [
            [
                'label'    => 'PHP Runtime Version',
                'required' => '>= 8.2.0',
                'current'  => PHP_VERSION,
                'passed'   => $phpOk,
            ],
            [
                'label'    => 'BCMath Extension (secp256k1 ECC)',
                'required' => 'Enabled',
                'current'  => extension_loaded('bcmath') ? 'Enabled' : 'Missing',
                'passed'   => extension_loaded('bcmath'),
            ],
            [
                'label'    => 'PDO Database Layer',
                'required' => 'Enabled',
                'current'  => extension_loaded('pdo') ? 'Enabled' : 'Missing',
                'passed'   => extension_loaded('pdo'),
            ],
            [
                'label'    => 'OpenSSL Extension',
                'required' => 'Enabled',
                'current'  => extension_loaded('openssl') ? 'Enabled' : ($hasTransport ? 'Transport Bound' : 'Missing'),
                'passed'   => extension_loaded('openssl') || $hasTransport,
            ],
            [
                'label'    => 'cURL Extension',
                'required' => 'Enabled',
                'current'  => extension_loaded('curl') ? 'Enabled' : ($hasTransport ? 'Transport Bound' : 'Missing'),
                'passed'   => extension_loaded('curl') || $hasTransport,
            ],
            [
                'label'    => 'Mbstring Support',
                'required' => 'Enabled',
                'current'  => extension_loaded('mbstring') ? 'Enabled' : 'Polyfill Active',
                'passed'   => extension_loaded('mbstring') || function_exists('mb_substr'),
            ],
            [
                'label'    => 'Redis Extension (Optional Cache)',
                'required' => 'Optional (File Cache Fallback)',
                'current'  => extension_loaded('redis') ? 'Enabled' : 'File Cache Active',
                'passed'   => true,
                'advisory' => !extension_loaded('redis'),
            ],
            [
                'label'    => 'Storage Directory Writable',
                'required' => 'Writable',
                'current'  => is_writable(BP_STORAGE) ? 'Writable' : 'Read-Only',
                'passed'   => is_writable(BP_STORAGE),
            ],
        ];

        $allPassed = true;
        foreach ($checks as $c) {
            if (!$c['passed']) {
                $allPassed = false;
                break;
            }
        }

        return [
            'all_passed' => $allPassed,
            'checks'     => $checks,
        ];
    }

    public function currentStage(): int
    {
        Security::startSession();
        return max(1, min(4, (int) ($_SESSION['bp_install_stage'] ?? 1)));
    }

    /**
     * Handle POST submission for Stages 1..3 and return state array.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool,stage:int,error?:string,message?:string}
     */
    public function submitStage(int $stage, array $input): array
    {
        Security::startSession();

        if ($stage === 1) {
            $reqs = $this->checkRequirements();
            if (!$reqs['all_passed']) {
                return ['ok' => false, 'stage' => 1, 'error' => 'Please resolve all failed server requirement checks before continuing.'];
            }

            $licenseKey = trim((string) ($input['license_key'] ?? ''));
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $licSvc = new LicenseService();
            $res = $licSvc->verifyKeyOnline($licenseKey, $host);

            if (!$res['valid']) {
                return ['ok' => false, 'stage' => 1, 'error' => $res['message']];
            }

            $licSvc->saveLicense($licenseKey, $res['domain']);
            $_SESSION['bp_install_stage'] = 2;
            $_SESSION['bp_install_domain'] = $res['domain'];
            return ['ok' => true, 'stage' => 2, 'message' => 'System checks passed and license verified.'];
        }

        if ($stage === 2) {
            if ($this->currentStage() < 2) {
                return ['ok' => false, 'stage' => 1, 'error' => 'Complete Stage 1 license verification first.'];
            }

            $driver = strtolower(trim((string) ($input['db_driver'] ?? Env::get('DB_CONNECTION', 'mysql'))));
            if (!in_array($driver, ['mysql', 'pglite'], true)) {
                $driver = 'mysql';
            }

            $host = trim((string) ($input['db_host'] ?? '127.0.0.1'));
            $port = trim((string) ($input['db_port'] ?? '3306'));
            $database = trim((string) ($input['db_database'] ?? ''));
            $username = trim((string) ($input['db_username'] ?? ''));
            $password = (string) ($input['db_password'] ?? '');

            if ($driver === 'mysql' && ($host === '' || $database === '' || $username === '')) {
                return ['ok' => false, 'stage' => 2, 'error' => 'Database host, database name, and username are required.'];
            }

            try {
                // If an in-memory PDO was pre-bound (test/preview harness), use it; otherwise test live credentials
                if ($driver === 'pglite' && Env::get('BP_PREBOUND_PDO', '0') === '1') {
                    $pdo = Database::connection();
                } else {
                    $pdo = Database::testCredentials([
                        'driver'   => $driver,
                        'host'     => $host,
                        'port'     => $port,
                        'database' => $database ?: '/tmp/blockpayne_pglite',
                        'username' => $username,
                        'password' => $password,
                    ]);
                    Database::setConnection($pdo);
                }

                $envPairs = [
                    'APP_NAME'      => 'BlockPayne',
                    'APP_ENV'       => 'production',
                    'APP_URL'       => bp_abs_url(''),
                    'DB_CONNECTION' => $driver,
                    'DB_HOST'       => $host,
                    'DB_PORT'       => $port,
                    'DB_DATABASE'   => $database ?: '/tmp/blockpayne_pglite',
                    'DB_USERNAME'   => $username,
                    'DB_PASSWORD'   => $password,
                ];

                if (!Env::write(BP_ROOT . '/.env', $envPairs)) {
                    return ['ok' => false, 'stage' => 2, 'error' => 'Could not write .env configuration file. Check directory permissions.'];
                }

                Database::migrate($pdo);
                $_SESSION['bp_install_stage'] = 3;
                return ['ok' => true, 'stage' => 3, 'message' => 'Database connected and schema migrated successfully.'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'stage' => 2, 'error' => 'Database error: ' . $e->getMessage()];
            }
        }

        if ($stage === 3) {
            if ($this->currentStage() < 3) {
                return ['ok' => false, 'stage' => $this->currentStage(), 'error' => 'Complete Stage 2 database setup first.'];
            }

            $siteTitle  = trim((string) ($input['site_title'] ?? 'BlockPayne'));
            $brandColor = DynamicBrand::accentColor((string) ($input['brand_color'] ?? '#10b981'));
            $feePercent = (float) ($input['gateway_fee_percent'] ?? 1.0);
            $adminName  = trim((string) ($input['admin_name'] ?? ''));
            $adminEmail = strtolower(trim((string) ($input['admin_email'] ?? '')));
            $adminPass  = (string) ($input['admin_password'] ?? '');

            if ($siteTitle === '' || mb_strlen($siteTitle) > 60) {
                return ['ok' => false, 'stage' => 3, 'error' => 'Enter a site title (1–60 characters).'];
            }
            if ($adminName === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'stage' => 3, 'error' => 'Enter a valid administrator name and email address.'];
            }
            if (strlen($adminPass) < 8) {
                return ['ok' => false, 'stage' => 3, 'error' => 'Administrator password must be at least 8 characters.'];
            }
            $feePercent = max(0.0, min(15.0, $feePercent));

            try {
                $pdo = Database::connection();
                Settings::setMany([
                    'site_title'          => $siteTitle,
                    'brand_color'         => $brandColor,
                    'gateway_fee_percent' => number_format($feePercent, 2, '.', ''),
                    'admin_alert_email'   => $adminEmail,
                    'mail_from_name'      => $siteTitle . ' Security',
                ]);

                // Generate and cache dynamic First-Letter SVG favicon
                $svg = DynamicBrand::faviconSvg($siteTitle, $brandColor);
                @file_put_contents(BP_STORAGE . '/cache/favicon.svg', $svg);

                // Create or update primary Super Admin account
                $apiKey = 'bp_live_' . bin2hex(random_bytes(20));
                $whSecret = 'whsec_' . bin2hex(random_bytes(18));
                $hashed = Security::hashPassword($adminPass);

                $existingStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
                $existingStmt->execute([$adminEmail]);
                $existingId = $existingStmt->fetchColumn();

                if ($existingId) {
                    $uStmt = $pdo->prepare("UPDATE users SET name = ?, password = ?, role = 'admin', status = 'active', updated_at = ? WHERE id = ?");
                    $uStmt->execute([$adminName, $hashed, bp_now(), (int) $existingId]);
                    $adminId = (int) $existingId;
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO users (name, email, password, role, status, enable_login_notifications, api_key, webhook_secret, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $ins->execute([
                        $adminName,
                        $adminEmail,
                        $hashed,
                        'admin',
                        'active',
                        Database::boolVal(true, $pdo),
                        $apiKey,
                        $whSecret,
                        bp_now(),
                        bp_now(),
                    ]);
                    $adminId = (int) $pdo->lastInsertId();
                }

                Database::upsert(
                    'merchant_balances',
                    [
                        'user_id'    => $adminId,
                        'currency'   => 'USD',
                        'balance'    => '100.00000000',
                        'updated_at' => bp_now(),
                    ],
                    ['user_id', 'currency'],
                    ['balance', 'updated_at'],
                    $pdo
                );

                // Whitelist installer's IP with King Crown green status immediately so owner isn't locked out
                BruteForceProtection::whitelistIp(Security::clientIp(), 'Primary Administrator Installation IP');
                BruteForceProtection::logEvent(
                    Security::clientIp(),
                    $adminEmail,
                    'whitelisted_success',
                    'Super Admin created & IP whitelisted during installation',
                    Security::clientCountry() ?: null,
                    true,
                    5
                );

                // Write installation lockfile (`storage/installed`)
                $lockPayload = json_encode([
                    'installed_at' => gmdate('c'),
                    'version'      => BP_VERSION,
                    'site_title'   => $siteTitle,
                ], JSON_PRETTY_PRINT);
                @file_put_contents(BP_STORAGE . '/installed', $lockPayload, LOCK_EX);
                @chmod(BP_STORAGE . '/installed', 0600);

                Env::write(BP_ROOT . '/.env', ['APP_NAME' => $siteTitle]);

                $_SESSION['bp_user_id'] = $adminId;
                $_SESSION['bp_install_stage'] = 4;

                return ['ok' => true, 'stage' => 4, 'message' => 'Installation complete!'];
            } catch (\Throwable $e) {
                return ['ok' => false, 'stage' => 3, 'error' => 'Setup error: ' . $e->getMessage()];
            }
        }

        return ['ok' => false, 'stage' => $this->currentStage(), 'error' => 'Invalid installation stage.'];
    }
}
