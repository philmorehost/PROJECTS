<?php
/**
 * SchoolApp (SMSP) Central OTA Update Helper
 *
 * Handles update checks against License Manager, SHA-256 verified downloads,
 * streaming PDO database + code backups, protected file/SQL application,
 * automatic rollback on failure, concurrency locking, and Super Admin notifications.
 */

if (!defined('PORTAL_PATH')) {
    define('PORTAL_PATH', dirname(__DIR__));
}

class UpdateHelper
{
    public const DEFAULT_UPDATE_SERVER = 'https://manager.pmhserver.name.ng';
    public const PROJECT_FAMILY        = 'SMSP';

    /** @var resource|null */
    private static $lockHandle = null;

    /**
     * Paths relative to PORTAL_PATH that an OTA update must NEVER overwrite or delete.
     */
    private static array $protectedPrefixes = [
        'config/config.php',
        'config/.sys_cache',
        'install/',
        'dataimg/',
        'uploads/',
        'backups/',
        'tmp_update/',
    ];

    /**
     * Directories relative to PORTAL_PATH skipped during code backup ZIP creation
     * to keep backups fast and avoid archiving gigabytes of student uploads or SDKs.
     */
    private static array $backupExcludedPrefixes = [
        'backups/',
        'tmp_update/',
        'dataimg/',
        'uploads/',
        'mobile-android/',
        'mobile-ios/',
        'logs/',
        'scratch/',
        '.git/',
    ];

    /**
     * Load OTA-related settings from sm_global_settings.
     */
    public static function getSettings(PDO $pdo): array
    {
        $defaults = [
            'license_key'         => defined('LICENSE_KEY') ? LICENSE_KEY : '',
            'main_domain'         => '',
            'system_version'      => '2.0.0',
            'edition_tier'        => 'SMSP-SAAS',
            'auto_update_enabled' => '1',
            'ota_signal_enabled'  => '1',
            'update_server_url'   => self::DEFAULT_UPDATE_SERVER,
            'last_update_check'   => '',
            'last_update_status'  => '',
            'platform_name'       => 'SchoolApp',
            'platform_support_email' => '',
        ];

        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM sm_global_settings");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $defaults[$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (Throwable $e) {
            // Fall back to defaults
        }

        if (trim($defaults['update_server_url']) === '') {
            $defaults['update_server_url'] = self::DEFAULT_UPDATE_SERVER;
        }
        if (trim($defaults['system_version']) === '') {
            $defaults['system_version'] = '2.0.0';
        }
        if (trim($defaults['edition_tier']) === '') {
            $defaults['edition_tier'] = 'SMSP-SAAS';
        }

        return $defaults;
    }

    /**
     * Upsert a single key in sm_global_settings.
     */
    public static function setSetting(PDO $pdo, string $key, string $value): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO sm_global_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key, $value]);
    }

    /**
     * Ensure directory exists and carries Apache/Nginx/index deny guards.
     */
    public static function protectDirectory(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        $htFile = rtrim($dir, '/\\') . '/.htaccess';
        if (!file_exists($htFile)) {
            @file_put_contents(
                $htFile,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }
        $idxFile = rtrim($dir, '/\\') . '/index.php';
        if (!file_exists($idxFile)) {
            @file_put_contents($idxFile, "<?php http_response_code(403); exit('Forbidden');\n");
        }
        return true;
    }

    /**
     * Acquire non-blocking exclusive OTA lock so concurrent signal, cron, and UI runs never collide.
     */
    public static function acquireLock(): bool
    {
        $tmpDir = PORTAL_PATH . '/tmp_update';
        if (!self::protectDirectory($tmpDir)) {
            return false;
        }
        $lockFile = $tmpDir . '/ota.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return false;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return false;
        }
        @ftruncate($fp, 0);
        @fwrite($fp, (string)getmypid() . ' @ ' . date('c'));
        @fflush($fp);
        self::$lockHandle = $fp;
        return true;
    }

    /**
     * Release the OTA file lock.
     */
    public static function releaseLock(): void
    {
        if (is_resource(self::$lockHandle)) {
            @flock(self::$lockHandle, LOCK_UN);
            @fclose(self::$lockHandle);
            self::$lockHandle = null;
        }
    }

    /**
     * Resolve canonical licensed domain for web or CLI execution.
     */
    public static function resolveDomain(array $settings = []): string
    {
        $candidates = [
            $settings['main_domain'] ?? '',
            $_SERVER['HTTP_HOST'] ?? '',
            defined('APP_URL') ? (parse_url(APP_URL, PHP_URL_HOST) ?: '') : '',
        ];

        foreach ($candidates as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }
            $clean = preg_replace('#^https?://#i', '', strtolower($raw));
            $clean = explode('/', $clean)[0];
            $clean = preg_replace('/:\d+$/', '', $clean);
            $clean = preg_replace('/^www\./i', '', $clean);
            if ($clean !== '') {
                return $clean;
            }
        }
        return 'localhost';
    }

    /**
     * Query License Manager /check-update.php for available SMSP updates.
     */
    public static function checkUpdate(PDO $pdo, ?string $forceVersion = null): array
    {
        $settings = self::getSettings($pdo);
        $licenseKey = trim($settings['license_key'] ?? '');
        $currentVersion = ($forceVersion !== null && trim($forceVersion) !== '')
            ? trim($forceVersion)
            : trim($settings['system_version'] ?? '2.0.0');
        $expectedTier = strtoupper(trim($settings['edition_tier'] ?? 'SMSP-SAAS'));
        $serverBase = rtrim(trim($settings['update_server_url'] ?? self::DEFAULT_UPDATE_SERVER), '/');
        $domain = self::resolveDomain($settings);

        $endpoint = $serverBase . '/check-update.php';
        $payload = [
            'license_key'     => $licenseKey,
            'domain'          => $domain,
            'current_version' => $currentVersion,
            'project_family'  => self::PROJECT_FAMILY,
            'expected_tier'   => $expectedTier,
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::setSetting($pdo, 'last_update_check', date('Y-m-d H:i:s'));

        if ($raw === false || $raw === '') {
            return [
                'status'  => 'error',
                'message' => 'Could not connect to update server: ' . ($curlErr ?: 'Empty response'),
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'status'  => 'error',
                'message' => "Invalid JSON response from update server (HTTP {$httpCode}).",
            ];
        }

        // Synchronize edition_tier if LM returns an SMSP tier code
        $detectedTierRaw = $decoded['tier_detected'] ?? $decoded['tier'] ?? '';
        if ($detectedTierRaw !== '') {
            $returnedTier = strtoupper(trim((string)$detectedTierRaw));
            if (strpos($returnedTier, 'SMSP') === 0 && $returnedTier !== $expectedTier) {
                self::setSetting($pdo, 'edition_tier', $returnedTier);
                $expectedTier = $returnedTier;
            }
        }

        if (($decoded['status'] ?? '') !== 'success') {
            return [
                'status'  => 'error',
                'message' => $decoded['message'] ?? "Update check rejected (HTTP {$httpCode}).",
                'tier'    => $expectedTier,
            ];
        }

        $latestVer = (string)($decoded['latest_version'] ?? $currentVersion);
        $cleanLatest = ltrim(trim($latestVer), 'vV');
        $cleanReported = ltrim(trim($currentVersion), 'vV');
        $hasUpdate = array_key_exists('update_available', $decoded)
            ? (!empty($decoded['update_available']) && $cleanLatest !== '' && $cleanLatest !== '0.00')
            : ($cleanLatest !== '' && $cleanLatest !== '0.00' && version_compare($cleanLatest, $cleanReported, '>'));

        return [
            'status'            => 'success',
            'update_available'  => $hasUpdate,
            'current_version'   => $settings['system_version'] ?? '2.0.0',
            'reported_version'  => $currentVersion,
            'latest_version'    => $latestVer,
            'tier'              => (string)($detectedTierRaw !== '' ? $detectedTierRaw : $expectedTier),
            'release_date'      => (string)($decoded['release_date'] ?? ''),
            'changelog'         => (string)($decoded['changelog'] ?? ''),
            'download_url'      => (string)($decoded['download_url'] ?? ''),
            'checksum'          => (string)($decoded['checksum'] ?? ''),
            'message'           => (string)($decoded['message'] ?? 'Check completed.'),
        ];
    }

    /**
     * Download OTA package to tmp_update/update_package.zip and verify SHA-256 checksum.
     */
    public static function downloadUpdate(string $downloadUrl, string $expectedChecksum): array
    {
        $downloadUrl = trim($downloadUrl);
        $expectedChecksum = strtolower(trim($expectedChecksum));

        if ($downloadUrl === '' || !preg_match('#^https?://#i', $downloadUrl)) {
            return ['status' => 'error', 'message' => 'Invalid download URL provided.'];
        }
        if ($expectedChecksum === '' || strlen($expectedChecksum) !== 64) {
            return ['status' => 'error', 'message' => 'Missing or invalid SHA-256 checksum metadata.'];
        }

        $tmpDir = PORTAL_PATH . '/tmp_update';
        if (!self::protectDirectory($tmpDir)) {
            return ['status' => 'error', 'message' => 'Unable to create temporary update directory (tmp_update).'];
        }

        $targetZip = $tmpDir . '/update_package.zip';
        if (file_exists($targetZip)) {
            @unlink($targetZip);
        }

        $fp = @fopen($targetZip, 'wb');
        if (!$fp) {
            return ['status' => 'error', 'message' => 'Cannot open tmp_update/update_package.zip for writing.'];
        }

        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $httpCode !== 200 || !file_exists($targetZip) || filesize($targetZip) < 100) {
            @unlink($targetZip);
            return [
                'status'  => 'error',
                'message' => "Package download failed (HTTP {$httpCode})" . ($err ? ": {$err}" : '. Check if download token expired.'),
            ];
        }

        $actualChecksum = strtolower((string)hash_file('sha256', $targetZip));
        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            @unlink($targetZip);
            return [
                'status'  => 'error',
                'message' => 'SHA-256 checksum verification failed! Package discarded for security.',
            ];
        }

        return [
            'status'   => 'success',
            'zip_file' => $targetZip,
            'size'     => filesize($targetZip),
            'checksum' => $actualChecksum,
            'message'  => 'Update package downloaded and SHA-256 verified.',
        ];
    }

    /**
     * Create streaming PDO SQL dump + application code ZIP backup prior to update.
     */
    public static function createBackup(PDO $pdo, string $currentVersion): array
    {
        $backupDir = PORTAL_PATH . '/backups';
        if (!self::protectDirectory($backupDir)) {
            return ['status' => 'error', 'message' => 'Unable to create backups/ directory. Check permissions.'];
        }

        $safeVer = preg_replace('/[^a-zA-Z0-9._-]/', '_', $currentVersion ?: '2.0.0');
        $stamp = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $sqlFile = $backupDir . "/backup_v{$safeVer}_{$stamp}.sql";
        $zipFile = $backupDir . "/backup_v{$safeVer}_{$stamp}.zip";

        // 1. Streaming PDO SQL Backup
        try {
            self::dumpDatabaseToFile($pdo, $sqlFile);
        } catch (Throwable $e) {
            @unlink($sqlFile);
            return ['status' => 'error', 'message' => 'Database backup failed: ' . $e->getMessage()];
        }

        // 2. Codebase ZIP Backup
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($sqlFile);
            return ['status' => 'error', 'message' => 'Failed to create code backup archive.'];
        }

        $rootReal = realpath(PORTAL_PATH);
        if ($rootReal === false) {
            $zip->close();
            @unlink($sqlFile);
            return ['status' => 'error', 'message' => 'Could not resolve PORTAL_PATH for backup.'];
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootReal, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $fullPath = str_replace('\\', '/', $item->getPathname());
            if (strpos($fullPath, $rootPrefix) !== 0) {
                continue;
            }
            $relPath = substr($fullPath, strlen($rootPrefix));
            if ($relPath === '' || self::shouldSkipFromBackup($relPath, $item->isDir())) {
                continue;
            }

            if ($item->isDir()) {
                $zip->addEmptyDir($relPath);
            } elseif ($item->isFile() && is_readable($item->getPathname())) {
                $zip->addFile($item->getPathname(), $relPath);
            }
        }

        $zip->close();

        // Keep the 3 newest backup pairs to avoid filling disk
        self::pruneOldBackups($backupDir, 3);

        return [
            'status'   => 'success',
            'sql_file' => $sqlFile,
            'zip_file' => $zipFile,
            'sql_name' => basename($sqlFile),
            'zip_name' => basename($zipFile),
            'message'  => 'Full database and code backup completed.',
        ];
    }

    /**
     * Extract update ZIP, validate SMSP manifest, protect tenant configs/uploads,
     * apply file deletions & updates, run SQL migrations, and auto-rollback on error.
     */
    public static function applyUpdate(
        PDO $pdo,
        string $zipFile,
        array $backupInfo = [],
        string $triggerSource = 'manual'
    ): array {
        $settings = self::getSettings($pdo);
        $fromVersion = $settings['system_version'] ?? '2.0.0';

        if (!file_exists($zipFile)) {
            return ['status' => 'error', 'message' => 'Downloaded update package not found at ' . basename($zipFile)];
        }

        $extractDir = PORTAL_PATH . '/tmp_update/extracted_' . bin2hex(random_bytes(4));
        if (!@mkdir($extractDir, 0755, true)) {
            return ['status' => 'error', 'message' => 'Could not create temporary extraction directory.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            self::removeDir($extractDir);
            return ['status' => 'error', 'message' => 'Failed to open downloaded update ZIP archive.'];
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            self::removeDir($extractDir);
            return ['status' => 'error', 'message' => 'Failed to extract update ZIP archive.'];
        }
        $zip->close();

        // Locate manifest.json (root or one directory deep)
        $manifestPath = null;
        $packageRoot = $extractDir;
        if (file_exists($extractDir . '/manifest.json')) {
            $manifestPath = $extractDir . '/manifest.json';
        } else {
            foreach (scandir($extractDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $sub = $extractDir . '/' . $entry;
                if (is_dir($sub) && file_exists($sub . '/manifest.json')) {
                    $manifestPath = $sub . '/manifest.json';
                    $packageRoot = $sub;
                    break;
                }
            }
        }

        if ($manifestPath === null) {
            self::removeDir($extractDir);
            return ['status' => 'error', 'message' => 'Invalid update package: manifest.json is missing.'];
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['version'])) {
            self::removeDir($extractDir);
            return ['status' => 'error', 'message' => 'Invalid manifest.json format inside update package.'];
        }

        // Cross-project guard: only accept SMSP* packages
        $manifestTier = strtoupper(trim((string)($manifest['tier'] ?? '')));
        $manifestFamily = strtoupper(trim((string)($manifest['project_family'] ?? '')));
        if ($manifestTier !== '' && strpos($manifestTier, 'SMSP') !== 0 && $manifestFamily !== 'SMSP') {
            self::removeDir($extractDir);
            return [
                'status'  => 'error',
                'message' => "Cross-project package blocked! Manifest tier '{$manifestTier}' is not an SMSP update.",
            ];
        }

        $toVersion = ltrim(trim((string)$manifest['version']), 'vV');
        $filesSourceDir = is_dir($packageRoot . '/files') ? ($packageRoot . '/files') : $packageRoot;

        try {
            // 1. Process safe file deletions
            if (!empty($manifest['files_to_delete']) && is_array($manifest['files_to_delete'])) {
                foreach ($manifest['files_to_delete'] as $delRel) {
                    $cleanRel = self::sanitizeRelativePath((string)$delRel);
                    if ($cleanRel === null || self::isProtectedPath($cleanRel)) {
                        continue;
                    }
                    $targetDel = PORTAL_PATH . '/' . $cleanRel;
                    if (is_file($targetDel)) {
                        @unlink($targetDel);
                    }
                }
            }

            // 2. Copy updated files over PORTAL_PATH while skipping protected paths & root manifest.json
            $srcReal = realpath($filesSourceDir);
            if ($srcReal === false) {
                throw new RuntimeException('Extracted files source directory could not be resolved.');
            }
            $srcPrefix = rtrim(str_replace('\\', '/', $srcReal), '/') . '/';

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcReal, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            $copiedCount = 0;
            foreach ($it as $item) {
                $full = str_replace('\\', '/', $item->getPathname());
                if (strpos($full, $srcPrefix) !== 0) {
                    continue;
                }
                $rel = substr($full, strlen($srcPrefix));
                $cleanRel = self::sanitizeRelativePath($rel);
                if ($cleanRel === null) {
                    continue;
                }
                // If package placed files at root alongside manifest.json, don't copy manifest.json to web root
                if ($filesSourceDir === $packageRoot && $cleanRel === 'manifest.json') {
                    continue;
                }
                if (self::isProtectedPath($cleanRel)) {
                    continue;
                }

                $destPath = PORTAL_PATH . '/' . $cleanRel;
                if ($item->isDir()) {
                    if (!is_dir($destPath) && !@mkdir($destPath, 0755, true) && !is_dir($destPath)) {
                        throw new RuntimeException("Failed to create directory: {$cleanRel}");
                    }
                } elseif ($item->isFile()) {
                    $destParent = dirname($destPath);
                    if (!is_dir($destParent) && !@mkdir($destParent, 0755, true) && !is_dir($destParent)) {
                        throw new RuntimeException("Failed to create parent directory for: {$cleanRel}");
                    }
                    if (!@copy($item->getPathname(), $destPath)) {
                        throw new RuntimeException("Failed to write updated file: {$cleanRel}");
                    }
                    $copiedCount++;
                }
            }

            // 3. Run SQL migrations from manifest
            $executedQueries = 0;
            if (!empty($manifest['database_queries']) && is_array($manifest['database_queries'])) {
                foreach ($manifest['database_queries'] as $sqlQuery) {
                    $sqlQuery = trim((string)$sqlQuery);
                    if ($sqlQuery === '') {
                        continue;
                    }
                    $pdo->exec($sqlQuery);
                    $executedQueries++;
                }
            }

            // 4. Persist updated version & audit log
            self::setSetting($pdo, 'system_version', $toVersion);
            if ($manifestTier !== '' && strpos($manifestTier, 'SMSP') === 0) {
                self::setSetting($pdo, 'edition_tier', $manifestTier);
            }
            $summaryMsg = "Updated v{$fromVersion} -> v{$toVersion} ({$copiedCount} files, {$executedQueries} SQL queries).";
            self::setSetting($pdo, 'last_update_status', 'success: ' . $summaryMsg . ' @ ' . date('Y-m-d H:i:s'));

            self::logUpdateEvent(
                $pdo,
                $fromVersion,
                $toVersion,
                $triggerSource,
                'success',
                $backupInfo['zip_name'] ?? null,
                $backupInfo['sql_name'] ?? null,
                $summaryMsg
            );

            // Cleanup extracted files & downloaded zip
            self::removeDir($extractDir);
            @unlink($zipFile);

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return [
                'status'           => 'success',
                'from_version'     => $fromVersion,
                'to_version'       => $toVersion,
                'files_updated'    => $copiedCount,
                'queries_executed' => $executedQueries,
                'message'          => "SchoolApp successfully updated to v{$toVersion}!",
            ];
        } catch (Throwable $e) {
            self::removeDir($extractDir);
            @unlink($zipFile);

            $rollbackNote = self::rollbackFromBackup($pdo, $backupInfo);
            $errMsg = 'Update failed: ' . $e->getMessage() . ' | ' . $rollbackNote;

            try {
                self::setSetting($pdo, 'last_update_status', 'rolled_back: ' . $errMsg);
                self::logUpdateEvent(
                    $pdo,
                    $fromVersion,
                    $toVersion,
                    $triggerSource,
                    'rolled_back',
                    $backupInfo['zip_name'] ?? null,
                    $backupInfo['sql_name'] ?? null,
                    $errMsg
                );
            } catch (Throwable $ignore) {
            }

            return [
                'status'  => 'error',
                'message' => $errMsg,
            ];
        }
    }

    /**
     * Full unattended update pipeline used by both the instant LM signal endpoint and CLI cron fallback.
     */
    public static function runAutomatedUpdate(PDO $pdo, string $triggerSource = 'cron'): array
    {
        $settings = self::getSettings($pdo);
        if (($settings['auto_update_enabled'] ?? '1') !== '1') {
            return [
                'status'  => 'skipped',
                'message' => 'Automated updates are disabled in Super Admin settings.',
            ];
        }

        if (!self::acquireLock()) {
            return [
                'status'  => 'locked',
                'message' => 'Another OTA update operation is currently in progress.',
            ];
        }

        try {
            $check = self::checkUpdate($pdo);
            if (($check['status'] ?? '') !== 'success') {
                return $check;
            }

            if (empty($check['update_available'])) {
                return [
                    'status'          => 'up_to_date',
                    'current_version' => $check['current_version'],
                    'latest_version'  => $check['latest_version'],
                    'message'         => 'System is already running the latest version (v' . $check['current_version'] . ').',
                ];
            }

            $dl = self::downloadUpdate($check['download_url'], $check['checksum']);
            if (($dl['status'] ?? '') !== 'success') {
                self::notifySuperAdmins($pdo, false, $check['current_version'], $check['latest_version'], $triggerSource, $dl['message']);
                return $dl;
            }

            $backup = self::createBackup($pdo, $check['current_version']);
            if (($backup['status'] ?? '') !== 'success') {
                @unlink($dl['zip_file']);
                self::notifySuperAdmins($pdo, false, $check['current_version'], $check['latest_version'], $triggerSource, $backup['message']);
                return $backup;
            }

            $apply = self::applyUpdate($pdo, $dl['zip_file'], $backup, $triggerSource);
            $succeeded = ($apply['status'] ?? '') === 'success';
            self::notifySuperAdmins(
                $pdo,
                $succeeded,
                $check['current_version'],
                $check['latest_version'],
                $triggerSource,
                $apply['message'] ?? '',
                $check['changelog'] ?? ''
            );

            return $apply;
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Restore files + database from pre-update backup pair if applyUpdate fails mid-flight.
     */
    public static function rollbackFromBackup(PDO $pdo, array $backupInfo): string
    {
        $notes = [];

        if (!empty($backupInfo['zip_file']) && file_exists($backupInfo['zip_file'])) {
            $zip = new ZipArchive();
            if ($zip->open($backupInfo['zip_file']) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    $clean = self::sanitizeRelativePath($entry);
                    if ($clean === null || substr($entry, -1) === '/') {
                        continue;
                    }
                    // Keep config/config.php untouched even during rollback unless missing
                    if ($clean === 'config/config.php' && file_exists(PORTAL_PATH . '/config/config.php')) {
                        continue;
                    }
                    $dest = PORTAL_PATH . '/' . $clean;
                    $dir = dirname($dest);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    $stream = $zip->getStream($entry);
                    if ($stream) {
                        $out = @fopen($dest, 'wb');
                        if ($out) {
                            stream_copy_to_stream($stream, $out);
                            fclose($out);
                        }
                        fclose($stream);
                    }
                }
                $zip->close();
                $notes[] = 'Code files restored from backup';
            }
        }

        if (!empty($backupInfo['sql_file']) && file_exists($backupInfo['sql_file'])) {
            try {
                self::restoreDatabaseFromFile($pdo, $backupInfo['sql_file']);
                $notes[] = 'Database restored from SQL backup';
            } catch (Throwable $e) {
                $notes[] = 'SQL rollback warning: ' . $e->getMessage();
            }
        }

        return empty($notes) ? 'No backup archive provided for rollback.' : implode('; ', $notes) . '.';
    }

    /**
     * Stream all tables and rows to a .sql file without exhausting PHP memory.
     */
    private static function dumpDatabaseToFile(PDO $pdo, string $sqlFile): void
    {
        $fp = @fopen($sqlFile, 'wb');
        if (!$fp) {
            throw new RuntimeException('Cannot write SQL backup file.');
        }

        fwrite($fp, "-- SchoolApp OTA Pre-Update SQL Backup\n");
        fwrite($fp, "-- Generated: " . date('c') . "\n");
        fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as $tRow) {
            $table = $tRow[0];
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';

            $createRow = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch(PDO::FETCH_NUM);
            if (!$createRow || empty($createRow[1])) {
                continue;
            }

            fwrite($fp, "DROP TABLE IF EXISTS {$quotedTable};\n");
            fwrite($fp, $createRow[1] . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM {$quotedTable}");
            $batch = [];
            $colsHeader = null;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($colsHeader === null) {
                    $escapedCols = array_map(
                        static fn($c) => '`' . str_replace('`', '``', (string)$c) . '`',
                        array_keys($row)
                    );
                    $colsHeader = '(' . implode(', ', $escapedCols) . ')';
                }

                $vals = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $pdo->quote((string)$val);
                    }
                }
                $batch[] = '(' . implode(', ', $vals) . ')';

                if (count($batch) >= 300) {
                    fwrite($fp, "INSERT INTO {$quotedTable} {$colsHeader} VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }

            if (!empty($batch) && $colsHeader !== null) {
                fwrite($fp, "INSERT INTO {$quotedTable} {$colsHeader} VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($fp, "\n");
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fp);
    }

    /**
     * Replay a streaming SQL dump file statement-by-statement.
     */
    private static function restoreDatabaseFromFile(PDO $pdo, string $sqlFile): void
    {
        $fp = @fopen($sqlFile, 'rb');
        if (!$fp) {
            throw new RuntimeException('Cannot open SQL backup file for rollback.');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
        $buffer = '';
        $inSingleQuote = false;
        $escaped = false;

        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if (!$inSingleQuote && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
                continue;
            }

            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                $buffer .= $ch;

                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === "'") {
                    $inSingleQuote = !$inSingleQuote;
                    continue;
                }
                if ($ch === ';' && !$inSingleQuote) {
                    $stmtSql = trim($buffer);
                    $buffer = '';
                    if ($stmtSql !== '' && $stmtSql !== ';') {
                        $pdo->exec($stmtSql);
                    }
                }
            }
        }

        fclose($fp);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
    }

    /**
     * Send email alert to Super Admin(s) after automated update attempt.
     */
    private static function notifySuperAdmins(
        PDO $pdo,
        bool $succeeded,
        string $fromVer,
        string $toVer,
        string $triggerSource,
        string $statusDetail,
        string $changelog = ''
    ): void {
        $mailHelperFile = PORTAL_PATH . '/include/mail_helper.php';
        if (!file_exists($mailHelperFile)) {
            return;
        }
        require_once $mailHelperFile;
        if (!class_exists('MailHelper')) {
            return;
        }

        $settings = self::getSettings($pdo);
        $platformName = $settings['platform_name'] ?: 'SchoolApp';

        $emails = [];
        try {
            $rows = $pdo->query("SELECT email FROM sm_super_admins")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $em) {
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = strtolower(trim($em));
                }
            }
        } catch (Throwable $e) {
        }
        if (!empty($settings['platform_support_email']) && filter_var($settings['platform_support_email'], FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower(trim($settings['platform_support_email']));
        }
        $emails = array_values(array_unique($emails));
        if (empty($emails)) {
            return;
        }

        $badge = $succeeded ? 'SUCCESS' : 'ACTION REQUIRED';
        $subject = "[{$platformName} OTA] {$badge}: Update v{$toVer} ({$triggerSource})";
        $safeDetail = htmlspecialchars($statusDetail);
        $safeChangelog = nl2br(htmlspecialchars($changelog));

        $body = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;border:1px solid #e2e8f0;border-radius:12px;'>"
            . "<h2 style='margin-top:0;color:" . ($succeeded ? '#059669' : '#dc2626') . ";'>"
            . ($succeeded ? "✅ {$platformName} Updated to v{$toVer}" : "⚠️ {$platformName} Automated Update Failed")
            . "</h2>"
            . "<p><strong>Previous Version:</strong> v" . htmlspecialchars($fromVer) . "<br>"
            . "<strong>Target Version:</strong> v" . htmlspecialchars($toVer) . "<br>"
            . "<strong>Trigger Source:</strong> " . htmlspecialchars($triggerSource) . "<br>"
            . "<strong>Summary:</strong> {$safeDetail}</p>"
            . ($safeChangelog !== '' ? "<div style='background:#f8fafc;padding:12px;border-radius:8px;font-size:13px;'><strong>Changelog:</strong><br>{$safeChangelog}</div>" : '')
            . "</div>";

        foreach ($emails as $to) {
            try {
                MailHelper::sendHTML($to, $subject, $body);
            } catch (Throwable $e) {
            }
        }
    }

    private static function logUpdateEvent(
        PDO $pdo,
        string $fromVer,
        string $toVer,
        string $triggerSource,
        string $status,
        ?string $backupZip,
        ?string $backupSql,
        string $message
    ): void {
        $stmt = $pdo->prepare("
            INSERT INTO sm_update_logs (from_version, to_version, trigger_source, status, backup_zip, backup_sql, message)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$fromVer, $toVer, $triggerSource, $status, $backupZip, $backupSql, $message]);
    }

    private static function sanitizeRelativePath(string $rel): ?string
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
            return null;
        }
        return $rel;
    }

    public static function isProtectedPath(string $relPath): bool
    {
        $clean = trim(str_replace('\\', '/', $relPath), '/');
        foreach (self::$protectedPrefixes as $prefix) {
            $p = rtrim($prefix, '/');
            if (substr($prefix, -1) === '/') {
                if ($clean === $p || strpos($clean, $p . '/') === 0) {
                    return true;
                }
            } else {
                if ($clean === $p) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function shouldSkipFromBackup(string $relPath, bool $isDir): bool
    {
        $clean = trim(str_replace('\\', '/', $relPath), '/');
        foreach (self::$backupExcludedPrefixes as $prefix) {
            $p = rtrim($prefix, '/');
            if ($clean === $p || strpos($clean, $p . '/') === 0) {
                return true;
            }
        }
        if (!$isDir && strpos($clean, '/') === false) {
            $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
            if (in_array($ext, ['zip', 'sql'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function pruneOldBackups(string $backupDir, int $keepCount = 3): void
    {
        $zips = glob($backupDir . '/backup_v*.zip') ?: [];
        if (count($zips) <= $keepCount) {
            return;
        }
        usort($zips, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($zips, $keepCount) as $oldZip) {
            @unlink($oldZip);
            $matchingSql = preg_replace('/\.zip$/', '.sql', $oldZip);
            if ($matchingSql && file_exists($matchingSql)) {
                @unlink($matchingSql);
            }
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
