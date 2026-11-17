<?php
/**
 * SchoolApp (SMSP) — Scheduled Tasks (Cron) Helper
 *
 * Single source of truth for every background job the platform needs:
 *   • the job catalogue (name, purpose, script, recommended schedule)
 *   • automatic logging into `sm_cron_logs`
 *   • in-process execution for the Super Admin "Run now" button
 *   • environment detection so we can print the correct crontab line
 *     (PHP binary path, portal path, log path)
 */

require_once __DIR__ . '/mail_helper.php';

class CronHelper
{
    /** Default log retention (days) when the setting is missing. */
    public const DEFAULT_RETENTION_DAYS = 30;

    /** @var bool Guard so schema creation only runs once per request. */
    private static bool $schemaReady = false;

    /* ---------------------------------------------------------------------
     |  Schema & settings
     * ------------------------------------------------------------------- */

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaReady) {
            return;
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sm_cron_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `job_key` VARCHAR(60) NOT NULL,
                `job_name` VARCHAR(150) NOT NULL,
                `trigger_source` VARCHAR(30) NOT NULL DEFAULT 'cli',
                `status` VARCHAR(20) NOT NULL DEFAULT 'running',
                `exit_code` INT NOT NULL DEFAULT 0,
                `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `finished_at` DATETIME DEFAULT NULL,
                `duration_ms` INT NOT NULL DEFAULT 0,
                `output` LONGTEXT NULL,
                INDEX `idx_cron_job` (`job_key`),
                INDEX `idx_cron_started` (`started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // Logging is best-effort — never break the job itself.
        }

        self::$schemaReady = true;
    }

    public static function getSetting(PDO $pdo, string $key, ?string $default = null): ?string
    {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? $default : (string)$value;
        } catch (Throwable $e) {
            return $default;
        }
    }

    public static function setSetting(PDO $pdo, string $key, string $value): void
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$key, $value]);
        } catch (Throwable $e) {
            // Ignore — settings are non fatal.
        }
    }

    public static function getSettings(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        return [
            'cron_ota_enabled'          => self::getSetting($pdo, 'cron_ota_enabled', '1') === '1',
            'cron_maintenance_enabled'  => self::getSetting($pdo, 'cron_maintenance_enabled', '1') === '1',
            'cron_email_reports'        => self::getSetting($pdo, 'cron_email_reports', '0') === '1',
            'cron_report_email'         => (string)self::getSetting($pdo, 'cron_report_email', ''),
            'cron_log_retention_days'   => (int)self::getSetting($pdo, 'cron_log_retention_days', (string)self::DEFAULT_RETENTION_DAYS),
            'cron_web_token'            => (string)self::getSetting($pdo, 'cron_web_token', ''),
        ];
    }

    /* ---------------------------------------------------------------------
     |  Environment detection
     * ------------------------------------------------------------------- */

    /** Best-effort path to the PHP CLI binary for crontab lines. */
    public static function phpBinary(): string
    {
        if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '') {
            return PHP_BINARY;
        }

        $candidates = ['/usr/local/bin/php', '/usr/bin/php', '/opt/cpanel/ea-php81/root/usr/bin/php', '/usr/bin/php8.1', '/usr/bin/php8.2', '/usr/bin/php8.3'];
        foreach ($candidates as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        if (self::canExec()) {
            $found = @trim((string)@shell_exec('command -v php 2>/dev/null'));
            if ($found !== '' && strpos($found, '/') === 0) {
                return $found;
            }
        }

        return '/usr/bin/php';
    }

    public static function canExec(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('shell_exec', $disabled, true) && !in_array('exec', $disabled, true);
    }

    public static function portalPath(): string
    {
        return defined('PORTAL_PATH') ? PORTAL_PATH : dirname(__DIR__);
    }

    public static function logPath(string $file): string
    {
        return rtrim(str_replace('\\', '/', self::portalPath()), '/') . '/logs/' . $file;
    }

    /** Resolve the public base URL of this portal (for the web-cron fallback). */
    public static function publicBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' && defined('APP_URL')) {
            $host = (string)parse_url(APP_URL, PHP_URL_HOST);
            $https = (string)parse_url(APP_URL, PHP_URL_SCHEME) ?: 'https';
        }
        $host = $host !== '' ? $host : 'localhost';
        return $https . '://' . $host;
    }

    public static function environment(): array
    {
        return [
            'php_binary'   => self::phpBinary(),
            'php_version'  => PHP_VERSION,
            'sapi'         => PHP_SAPI,
            'portal_path'  => str_replace('\\', '/', self::portalPath()),
            'server_time'  => date('D, d M Y · H:i:s T'),
            'timezone'     => date_default_timezone_get(),
            'can_exec'     => self::canExec(),
            'logs_writable'=> @is_writable(self::portalPath() . '/logs') || @is_writable(self::portalPath()),
            'os_family'    => (DIRECTORY_SEPARATOR === '\\') ? 'windows' : 'unix',
        ];
    }

    /* ---------------------------------------------------------------------
     |  Job catalogue
     * ------------------------------------------------------------------- */

    /**
     * Every background job the platform can run.
     * `schedule` is the recommended crontab expression; `cadence_hours` is used
     * by the web-cron fallback to decide if a job is due.
     */
    public static function jobCatalog(): array
    {
        $php = self::phpBinary();
        $base = rtrim(str_replace('\\', '/', self::portalPath()), '/');
        $otaLog = self::logPath('ota_cron.log');
        $maintLog = self::logPath('maintenance_cron.log');
        $otaCmd = "{$php} {$base}/cron/cron_update.php >> {$otaLog} 2>&1";
        $maintCmd = "{$php} {$base}/cron/cron_maintenance.php >> {$maintLog} 2>&1";

        return [
            'ota_update' => [
                'key'            => 'ota_update',
                'name'           => 'OTA Auto-Update Check',
                'icon'           => '🔄',
                'script'         => $base . '/cron/cron_update.php',
                'relative'       => 'cron/cron_update.php',
                'schedule'       => '30 2 * * *',
                'schedule_human' => 'Every day at 02:30',
                'cadence_hours'  => 20,
                'log_file'       => $otaLog,
                'command'        => $otaCmd,
                'crontab'        => "30 2 * * * {$otaCmd}",
                'description'    => 'Contacts the central License Manager, verifies the SHA-256 package and applies any released update with automatic backup and rollback.',
                'setting'        => 'cron_ota_enabled',
                'requirement'    => 'Active SMSP licence key (System Update Centre).',
            ],
            'maintenance' => [
                'key'            => 'maintenance',
                'name'           => 'Subscription & Platform Maintenance',
                'icon'           => '🧹',
                'script'         => $base . '/cron/cron_maintenance.php',
                'relative'       => 'cron/cron_maintenance.php',
                'schedule'       => '0 * * * *',
                'schedule_human' => 'Every hour (at minute 0)',
                'cadence_hours'  => 1,
                'log_file'       => $maintLog,
                'command'        => $maintCmd,
                'crontab'        => "0 * * * * {$maintCmd}",
                'description'    => 'Sweeps subscription expiry reminders, auto-renewals, grace-period suspensions, stale OTA locks/temp files and old cron logs.',
                'setting'        => 'cron_maintenance_enabled',
                'requirement'    => 'No extra configuration required.',
            ],
        ];
    }

    public static function job(string $jobKey): ?array
    {
        $catalog = self::jobCatalog();
        return $catalog[$jobKey] ?? null;
    }

    /** Full crontab block (all enabled jobs), ready to paste. */
    public static function crontabBlock(PDO $pdo): string
    {
        $settings = self::getSettings($pdo);
        $lines = [];
        foreach (self::jobCatalog() as $key => $job) {
            if ($key === 'ota_update' && !$settings['cron_ota_enabled']) {
                continue;
            }
            if ($key === 'maintenance' && !$settings['cron_maintenance_enabled']) {
                continue;
            }
            $lines[] = '# ' . $job['name'] . ' — ' . $job['schedule_human'];
            $lines[] = $job['crontab'];
            $lines[] = '';
        }
        return trim(implode("\n", $lines));
    }

    /* ---------------------------------------------------------------------
     |  Web cron fallback
     * ------------------------------------------------------------------- */

    public static function webCronToken(PDO $pdo, bool $regenerate = false): string
    {
        $token = self::getSetting($pdo, 'cron_web_token', '');
        if ($regenerate || $token === '' || strlen($token) < 32) {
            $token = bin2hex(random_bytes(24));
            self::setSetting($pdo, 'cron_web_token', $token);
        }
        return $token;
    }

    public static function webCronUrl(PDO $pdo, string $jobKey = 'all'): string
    {
        return self::publicBaseUrl() . '/cron-web.php?token=' . self::webCronToken($pdo) . '&job=' . urlencode($jobKey);
    }

    /* ---------------------------------------------------------------------
     |  Run history
     * ------------------------------------------------------------------- */

    public static function lastRun(PDO $pdo, string $jobKey): ?array
    {
        self::ensureSchema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT * FROM sm_cron_logs WHERE job_key = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$jobKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function recentRuns(PDO $pdo, int $limit = 25): array
    {
        self::ensureSchema($pdo);
        try {
            $limit = max(1, min(200, $limit));
            $stmt = $pdo->query("SELECT * FROM sm_cron_logs ORDER BY id DESC LIMIT {$limit}");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Is a job due according to its cadence? Used by cron-web.php?job=all. */
    public static function isDue(PDO $pdo, string $jobKey): bool
    {
        $job = self::job($jobKey);
        if (!$job) {
            return false;
        }
        $last = self::lastRun($pdo, $jobKey);
        if (!$last) {
            return true;
        }
        $lastTs = strtotime($last['started_at'] ?? '');
        if ($lastTs === false) {
            return true;
        }
        return (time() - $lastTs) >= ((int)$job['cadence_hours'] * 3600);
    }

    public static function clearLogs(PDO $pdo, int $days = 0): int
    {
        self::ensureSchema($pdo);
        try {
            if ($days <= 0) {
                return (int)$pdo->exec("DELETE FROM sm_cron_logs");
            }
            $stmt = $pdo->prepare("DELETE FROM sm_cron_logs WHERE started_at < (NOW() - INTERVAL ? DAY)");
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* ---------------------------------------------------------------------
     |  Execution
     * ------------------------------------------------------------------- */

    /**
     * Run a job in-process and record it in sm_cron_logs.
     *
     * @return array{status:string,job_key:string,output:string,duration_ms:int,exit_code:int}
     */
    public static function runJob(PDO $pdo, string $jobKey, string $source = 'manual'): array
    {
        self::ensureSchema($pdo);

        $job = self::job($jobKey);
        if (!$job) {
            return [
                'status'      => 'error',
                'job_key'     => $jobKey,
                'output'      => 'Unknown job: ' . $jobKey,
                'duration_ms' => 0,
                'exit_code'   => 1,
            ];
        }

        $logId = self::openLog($pdo, $jobKey, $job['name'], $source);
        $startedAt = microtime(true);

        ob_start();
        $status = 'success';
        $exitCode = 0;
        try {
            echo '[' . date('Y-m-d H:i:s') . '] Starting ' . $job['name'] . " (source: {$source})\n";

            if ($jobKey === 'ota_update') {
                require_once __DIR__ . '/update_helper.php';
                $result = UpdateHelper::runAutomatedUpdate($pdo, $source === 'manual' ? 'manual' : 'cron');
                $status = (($result['status'] ?? '') === 'error') ? 'error' : 'success';
                $exitCode = $status === 'error' ? 1 : 0;
                echo 'Result: ' . strtoupper((string)($result['status'] ?? 'unknown')) . ' — ' . (string)($result['message'] ?? '') . "\n";
            } elseif ($jobKey === 'maintenance') {
                $result = self::runMaintenance($pdo);
                $status = $result['status'];
                $exitCode = $status === 'error' ? 1 : 0;
                foreach ($result['lines'] as $line) {
                    echo $line . "\n";
                }
            } else {
                $status = 'error';
                $exitCode = 1;
                echo "No handler registered for this job.\n";
            }

            echo '[' . date('Y-m-d H:i:s') . "] Finished with status: {$status}\n";
        } catch (Throwable $e) {
            $status = 'error';
            $exitCode = 1;
            echo '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . "\n";
        }

        $output = (string)ob_get_clean();
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        self::closeLog($pdo, $logId, $status, $exitCode, $durationMs, $output);

        // Optional e-mail digest for the Super Admin
        try {
            $settings = self::getSettings($pdo);
            if ($settings['cron_email_reports'] && $settings['cron_report_email'] !== '') {
                $subject = ($status === 'error' ? '❌' : '✅') . ' SchoolApp cron: ' . $job['name'];
                $body = '<h2>' . htmlspecialchars($job['name']) . '</h2>'
                    . '<p>Status: <strong>' . htmlspecialchars(strtoupper($status)) . '</strong> · '
                    . 'Duration: ' . $durationMs . ' ms · Source: ' . htmlspecialchars($source) . '</p>'
                    . '<pre style="background:#f5f5f5;padding:12px;border-radius:8px;font-size:12px;white-space:pre-wrap;">'
                    . htmlspecialchars($output) . '</pre>';
                MailHelper::sendHTML($settings['cron_report_email'], $subject, $body);
            }
        } catch (Throwable $e) {
            // Never fail the job because of mail issues.
        }

        return [
            'status'      => $status,
            'job_key'     => $jobKey,
            'output'      => $output,
            'duration_ms' => $durationMs,
            'exit_code'   => $exitCode,
        ];
    }

    /** Run every enabled job that is due (used by the web-cron fallback). */
    public static function runDueJobs(PDO $pdo, string $source = 'cron_web'): array
    {
        $settings = self::getSettings($pdo);
        $results = [];

        foreach (self::jobCatalog() as $key => $job) {
            if ($key === 'ota_update' && !$settings['cron_ota_enabled']) {
                continue;
            }
            if ($key === 'maintenance' && !$settings['cron_maintenance_enabled']) {
                continue;
            }
            if (!self::isDue($pdo, $key)) {
                continue;
            }
            $results[$key] = self::runJob($pdo, $key, $source);
        }

        return $results;
    }

    /* ---------------------------------------------------------------------
     |  Job implementation: subscription & platform maintenance
     * ------------------------------------------------------------------- */

    public static function runMaintenance(PDO $pdo): array
    {
        $lines = [];
        $status = 'success';

        // 1. Subscription lifecycle sweep (reminders, auto-renew, grace suspension)
        try {
            require_once __DIR__ . '/subscription_helper.php';
            SubscriptionHelper::checkAndProcess($pdo, true);
            $lines[] = '• Subscription sweep completed (expiry reminders, auto-renewals, grace period).';
        } catch (Throwable $e) {
            $status = 'error';
            $lines[] = '• Subscription sweep failed: ' . $e->getMessage();
        }

        // 2. Clear stale OTA locks and leftover staging files
        try {
            $tmpDir = self::portalPath() . '/tmp_update';
            $removed = 0;
            if (is_dir($tmpDir)) {
                foreach (glob($tmpDir . '/*') ?: [] as $path) {
                    if (!is_file($path)) {
                        continue;
                    }
                    $age = time() - (int)@filemtime($path);
                    if (str_ends_with($path, '.lock')) {
                        // A lock older than 30 minutes means the running process died.
                        if ($age > 1800) {
                            @unlink($path);
                            $removed++;
                        }
                    } elseif ($age > 86400) {
                        @unlink($path);
                        $removed++;
                    }
                }
            }
            $lines[] = "• OTA staging cleanup: {$removed} stale file(s) removed.";
        } catch (Throwable $e) {
            $lines[] = '• OTA staging cleanup skipped: ' . $e->getMessage();
        }

        // 3. Trim the cron log itself
        try {
            $settings = self::getSettings($pdo);
            $days = max(1, (int)$settings['cron_log_retention_days']);
            $deleted = self::clearLogs($pdo, $days);
            $lines[] = "• Cron log retention: {$deleted} row(s) older than {$days} day(s) removed.";
        } catch (Throwable $e) {
            $lines[] = '• Cron log retention skipped: ' . $e->getMessage();
        }

        return [
            'status' => $status,
            'lines'  => $lines,
        ];
    }

    /* ---------------------------------------------------------------------
     |  Log bookkeeping
     * ------------------------------------------------------------------- */

    private static function openLog(PDO $pdo, string $jobKey, string $jobName, string $source): int
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO sm_cron_logs (job_key, job_name, trigger_source, status, started_at) VALUES (?, ?, ?, 'running', NOW())");
            $stmt->execute([$jobKey, $jobName, $source]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function closeLog(PDO $pdo, int $logId, string $status, int $exitCode, int $durationMs, string $output): void
    {
        if ($logId <= 0) {
            return;
        }
        try {
            $stmt = $pdo->prepare("UPDATE sm_cron_logs SET status = ?, exit_code = ?, duration_ms = ?, output = ?, finished_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $exitCode, $durationMs, $output, $logId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
