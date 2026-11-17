<?php
/**
 * OTAGitHubHelper
 * Central helper for pulling project subdirectories from GitHub, packaging OTA update ZIPs
 * with auto-generated manifests & SHA-256 checksums, and broadcasting signed push signals
 * to all websites licensed under that project tier.
 */

class OTAGitHubHelper
{
    /**
     * Load global LM settings from settings.json
     */
    public static function loadSettings(): array
    {
        $settingsFile = dirname(__DIR__) . '/settings.json';
        if (file_exists($settingsFile)) {
            $decoded = json_decode((string)file_get_contents($settingsFile), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Resolve secure_updates root directory outside the LM web folder
     */
    public static function getSecureUpdatesDir(): string
    {
        $lmParent = dirname(dirname(__DIR__));
        return $lmParent . DIRECTORY_SEPARATOR . 'secure_updates';
    }

    /**
     * Auto-increment version string (supports both SemVer 1.0.0 -> 1.0.1 and DGV style 7.02 -> 7.03)
     */
    public static function bumpVersion(string $version): string
    {
        $clean = trim(ltrim($version, 'vV'));
        if ($clean === '' || $clean === '0' || $clean === '0.00' || $clean === '0.0.0') {
            return '1.0.0';
        }

        $parts = explode('.', $clean);
        $lastIdx = count($parts) - 1;
        $lastPart = $parts[$lastIdx];

        if (ctype_digit($lastPart)) {
            $width = strlen($lastPart);
            $nextVal = (int)$lastPart + 1;
            if ($width > 1 && $lastPart[0] === '0') {
                $parts[$lastIdx] = str_pad((string)$nextVal, $width, '0', STR_PAD_LEFT);
            } else {
                $parts[$lastIdx] = (string)$nextVal;
            }
            return implode('.', $parts);
        }

        return $clean . '.1';
    }

    /**
     * Parse comma- or newline-separated exclude rules into normalized prefixes/files
     */
    public static function parseExcludeRules(?string $rawExcludes): array
    {
        $alwaysExclude = [
            '.git/',
            '.github/',
            'backups/',
            'tmp_update/',
            'logs/',
        ];

        if (!empty($rawExcludes)) {
            $pieces = preg_split('/[\r\n,]+/', $rawExcludes) ?: [];
            foreach ($pieces as $piece) {
                $norm = ltrim(str_replace('\\', '/', trim($piece)), '/');
                if ($norm !== '') {
                    $alwaysExclude[] = $norm;
                }
            }
        }

        return array_values(array_unique($alwaysExclude));
    }

    /**
     * Check whether a relative project path matches any exclude rule
     */
    public static function isPathExcluded(string $relPath, array $excludeRules): bool
    {
        $norm = ltrim(str_replace('\\', '/', $relPath), '/');
        if ($norm === '') {
            return true;
        }

        $normLower = strtolower($norm);
        foreach ($excludeRules as $rule) {
            $ruleNorm = strtolower(ltrim(str_replace('\\', '/', $rule), '/'));
            if ($ruleNorm === '') {
                continue;
            }
            if (str_ends_with($ruleNorm, '/')) {
                if (str_starts_with($normLower, $ruleNorm) || $normLower . '/' === $ruleNorm) {
                    return true;
                }
            } else {
                if ($normLower === $ruleNorm || str_starts_with($normLower, $ruleNorm . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Perform an authenticated GitHub REST API GET request
     */
    public static function githubApiGet(string $url, string $token = '', int $timeout = 30): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: PMH-LicenseManager-OTA/2.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'http_code' => $code, 'error' => 'cURL error: ' . $err, 'data' => null];
        }

        $decoded = json_decode((string)$body, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : "HTTP {$code}";
            return ['ok' => false, 'http_code' => $code, 'error' => "GitHub API error ({$code}): {$msg}", 'data' => $decoded];
        }

        return ['ok' => true, 'http_code' => $code, 'error' => '', 'data' => $decoded];
    }

    /**
     * Stream a GitHub zipball archive to a temporary file
     */
    public static function githubDownloadZipball(string $repo, string $ref, string $destFile, string $token = ''): array
    {
        $url = 'https://api.github.com/repos/' . trim($repo, '/') . '/zipball/' . rawurlencode($ref);
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: PMH-LicenseManager-OTA/2.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $fp = fopen($destFile, 'wb');
        if (!$fp) {
            return ['ok' => false, 'error' => 'Unable to open temporary file for GitHub zipball download.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $ok   = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $code < 200 || $code >= 300 || !file_exists($destFile) || filesize($destFile) === 0) {
            @unlink($destFile);
            return ['ok' => false, 'error' => "Failed to download GitHub archive (HTTP {$code})" . ($err ? ": {$err}" : '')];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Pull a project tier from its configured GitHub repository/subdirectory,
     * build its OTA update ZIP with manifest.json + SHA-256 checksum, publish to script_updates,
     * and optionally broadcast signed update signals to all licensed websites.
     */
    public static function pullProjectFromGitHub(
        PDO $pdo,
        int $tierId,
        string $versionOverride = '',
        string $changelogOverride = '',
        array $extraDbQueries = [],
        ?bool $sendSignalsOverride = null,
        bool $forceRebuild = false
    ): array {
        $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ? LIMIT 1");
        $stmt->execute([$tierId]);
        $tier = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tier) {
            return ['status' => 'error', 'message' => 'Project tier not found.'];
        }

        $settings = self::loadSettings();
        $token = trim((string)($settings['github_token'] ?? ''));
        $repo = trim((string)($tier['github_repo'] ?: ($settings['github_default_repo'] ?? '')));
        $branch = trim((string)($tier['github_branch'] ?: ($settings['github_default_branch'] ?? 'main')));
        $subdir = trim(str_replace('\\', '/', (string)($tier['github_subdir'] ?? '')), '/');

        if ($repo === '' || strpos($repo, '/') === false) {
            return ['status' => 'error', 'message' => "GitHub repository is not configured for project '{$tier['tier_name']}' (expected owner/repo)."];
        }

        // 1. Fetch latest commit touching this project subdirectory (or repo root if subdir is empty)
        $commitsUrl = 'https://api.github.com/repos/' . $repo . '/commits?sha=' . rawurlencode($branch) . '&per_page=1';
        if ($subdir !== '') {
            $commitsUrl .= '&path=' . rawurlencode($subdir);
        }

        $commitRes = self::githubApiGet($commitsUrl, $token);
        if (!$commitRes['ok']) {
            return ['status' => 'error', 'message' => $commitRes['error']];
        }

        $commits = $commitRes['data'];
        if (!is_array($commits) || empty($commits[0]['sha'])) {
            $loc = $subdir !== '' ? "{$repo}:{$branch}/{$subdir}" : "{$repo}:{$branch}";
            return ['status' => 'error', 'message' => "No commits found on GitHub at {$loc}. Verify the repository, branch, and subdirectory name."];
        }

        $latestSha = (string)$commits[0]['sha'];
        $latestMsg = trim((string)($commits[0]['commit']['message'] ?? 'Update pulled from GitHub'));
        $previousSha = trim((string)($tier['last_github_sha'] ?? ''));

        // Check if this exact commit was already packaged for this tier
        if (!$forceRebuild && $previousSha !== '' && $previousSha === $latestSha && $versionOverride === '') {
            $dupStmt = $pdo->prepare("SELECT version_number FROM script_updates WHERE tier_id = ? AND commit_sha = ? AND is_released = 1 LIMIT 1");
            $dupStmt->execute([$tierId, $latestSha]);
            $existingVer = $dupStmt->fetchColumn();
            if ($existingVer) {
                return [
                    'status'         => 'up_to_date',
                    'message'        => "Project '{$tier['tier_code']}' is already at latest GitHub commit (" . substr($latestSha, 0, 7) . ", v{$existingVer}).",
                    'commit_sha'     => $latestSha,
                    'latest_version' => $existingVer,
                ];
            }
        }

        // 2. Inspect GitHub Compare API if previous SHA exists to auto-build changelog & files_to_delete
        $detectedDeletes = [];
        $autoChangelogItems = [];
        $excludeRules = self::parseExcludeRules($tier['exclude_paths'] ?? '');

        if ($previousSha !== '' && $previousSha !== $latestSha) {
            $compareUrl = 'https://api.github.com/repos/' . $repo . '/compare/' . rawurlencode($previousSha) . '...' . rawurlencode($latestSha);
            $compareRes = self::githubApiGet($compareUrl, $token);
            if ($compareRes['ok'] && is_array($compareRes['data'])) {
                $cmpData = $compareRes['data'];

                if (!empty($cmpData['commits']) && is_array($cmpData['commits'])) {
                    foreach (array_slice(array_reverse($cmpData['commits']), 0, 15) as $c) {
                        $firstLine = trim( strtok((string)($c['commit']['message'] ?? ''), "\r\n") ?: '' );
                        if ($firstLine !== '') {
                            $autoChangelogItems[] = '<li>' . htmlspecialchars($firstLine, ENT_QUOTES, 'UTF-8') . '</li>';
                        }
                    }
                }

                if (!empty($cmpData['files']) && is_array($cmpData['files'])) {
                    $prefix = $subdir !== '' ? ($subdir . '/') : '';
                    foreach ($cmpData['files'] as $f) {
                        $fStatus = (string)($f['status'] ?? '');
                        $fName   = (string)($f['filename'] ?? '');
                        $prevName = (string)($f['previous_filename'] ?? '');

                        if ($fStatus === 'removed') {
                            if ($prefix === '' || str_starts_with($fName, $prefix)) {
                                $rel = $prefix !== '' ? substr($fName, strlen($prefix)) : $fName;
                                if ($rel !== '' && !self::isPathExcluded($rel, $excludeRules)) {
                                    $detectedDeletes[] = $rel;
                                }
                            }
                        } elseif ($fStatus === 'renamed' && $prevName !== '') {
                            if ($prefix === '' || str_starts_with($prevName, $prefix)) {
                                $relPrev = $prefix !== '' ? substr($prevName, strlen($prefix)) : $prevName;
                                if ($relPrev !== '' && !self::isPathExcluded($relPrev, $excludeRules)) {
                                    $detectedDeletes[] = $relPrev;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (empty($autoChangelogItems)) {
            $firstLine = trim(strtok($latestMsg, "\r\n") ?: 'Latest updates pulled from GitHub');
            $autoChangelogItems[] = '<li>' . htmlspecialchars($firstLine, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        // 3. Download GitHub repository zipball to temporary file
        $secureUpdatesDir = self::getSecureUpdatesDir();
        $safeTierFolder = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $tier['tier_code']));
        $tierDir = $secureUpdatesDir . DIRECTORY_SEPARATOR . $safeTierFolder;

        if (!is_dir($secureUpdatesDir)) {
            if (!@mkdir($secureUpdatesDir, 0755, true)) {
                return ['status' => 'error', 'message' => "Cannot create secure_updates directory at {$secureUpdatesDir}."];
            }
            @file_put_contents($secureUpdatesDir . DIRECTORY_SEPARATOR . 'index.php', '<?php exit("Forbidden"); ?>');
        }
        if (!is_dir($tierDir) && !@mkdir($tierDir, 0755, true)) {
            return ['status' => 'error', 'message' => "Cannot create project archive directory at {$tierDir}."];
        }

        $tmpZipball = $tierDir . DIRECTORY_SEPARATOR . '.gh_tmp_' . bin2hex(random_bytes(8)) . '.zip';
        $dlRes = self::githubDownloadZipball($repo, $latestSha, $tmpZipball, $token);
        if (!$dlRes['ok']) {
            return ['status' => 'error', 'message' => $dlRes['error']];
        }

        // 4. Repackage only the target project's subdirectory into the OTA update ZIP
        $buildRes = self::buildReleaseZipFromGitHubArchive(
            $pdo,
            $tier,
            $tmpZipball,
            $tierDir,
            $latestSha,
            $versionOverride,
            $changelogOverride,
            implode("\n", array_values(array_unique($autoChangelogItems))),
            $detectedDeletes,
            $extraDbQueries,
            $excludeRules
        );

        @unlink($tmpZipball);

        if ($buildRes['status'] !== 'success') {
            return $buildRes;
        }

        $updateId = (int)$buildRes['update_id'];
        $finalVersion = (string)$buildRes['version'];

        // 5. Broadcast update signal to licensed websites if enabled
        $shouldSignal = $sendSignalsOverride !== null ? $sendSignalsOverride : ((int)($tier['auto_signal'] ?? 1) === 1);
        $signalSummary = null;
        if ($shouldSignal && $updateId > 0) {
            $signalSummary = self::signalLicensedWebsites($pdo, $updateId);
        }

        $msg = "Pulled {$tier['tier_code']} v{$finalVersion} from GitHub (" . substr($latestSha, 0, 7) . ", {$buildRes['file_count']} files)";
        if ($signalSummary !== null) {
            $msg .= " — Signaled {$signalSummary['total_domains']} domain(s) ({$signalSummary['accepted']} accepted, {$signalSummary['up_to_date']} up-to-date, {$signalSummary['failed']} unreachable/failed).";
        }

        return [
            'status'         => 'success',
            'message'        => $msg,
            'update_id'      => $updateId,
            'version'        => $finalVersion,
            'commit_sha'     => $latestSha,
            'checksum'       => $buildRes['checksum'],
            'file_count'     => $buildRes['file_count'],
            'signal_summary' => $signalSummary,
        ];
    }

    /**
     * Extract target subdirectory from a GitHub zipball (or similar archive),
     * merge optional in-repo ota_manifest.json, build final update_<ver>.zip, and save DB rows.
     */
    public static function buildReleaseZipFromGitHubArchive(
        PDO $pdo,
        array $tier,
        string $sourceArchivePath,
        string $tierDir,
        string $commitSha,
        string $versionOverride,
        string $changelogOverride,
        string $fallbackAutoChangelog,
        array $detectedDeletes,
        array $extraDbQueries,
        array $excludeRules
    ): array {
        $srcZip = new ZipArchive();
        if ($srcZip->open($sourceArchivePath) !== true) {
            return ['status' => 'error', 'message' => 'Failed to open downloaded GitHub zipball archive.'];
        }

        // Detect top-level folder prefix created by GitHub zipballs (e.g. "philmorehost-PROJECTS-abc1234/")
        $rootPrefix = '';
        if ($srcZip->numFiles > 0) {
            $firstEntry = str_replace('\\', '/', (string)$srcZip->getNameIndex(0));
            $slashPos = strpos($firstEntry, '/');
            if ($slashPos !== false) {
                $rootPrefix = substr($firstEntry, 0, $slashPos + 1);
            }
        }

        $subdir = trim(str_replace('\\', '/', (string)($tier['github_subdir'] ?? '')), '/');
        $targetPrefix = $rootPrefix . ($subdir !== '' ? ($subdir . '/') : '');

        // Inspect optional ota_manifest.json inside the project directory in GitHub
        $repoOtaManifest = [];
        $otaManifestEntry = $targetPrefix . 'ota_manifest.json';
        $rawOtaManifest = $srcZip->getFromName($otaManifestEntry);
        if ($rawOtaManifest !== false) {
            $decodedManifest = json_decode($rawOtaManifest, true);
            if (is_array($decodedManifest)) {
                $repoOtaManifest = $decodedManifest;
            }
        }

        // Resolve final version number: explicit UI override > repo ota_manifest.json (if newer than DB) > auto-bump latest DB version
        $tierId = (int)$tier['id'];
        $latestVerStmt = $pdo->prepare("SELECT version_number FROM script_updates WHERE tier_id = ? ORDER BY id DESC LIMIT 1");
        $latestVerStmt->execute([$tierId]);
        $dbLatestVer = (string)($latestVerStmt->fetchColumn() ?: '');

        $versionNumber = trim($versionOverride);
        if ($versionNumber === '') {
            $manifestVer = trim((string)($repoOtaManifest['version'] ?? ''));
            if ($manifestVer !== '' && ($dbLatestVer === '' || version_compare(ltrim($manifestVer, 'vV'), ltrim($dbLatestVer, 'vV'), '>'))) {
                $versionNumber = $manifestVer;
            } else {
                $versionNumber = self::bumpVersion($dbLatestVer);
            }
        }
        $versionNumber = ltrim($versionNumber, 'vV');

        // Resolve final changelog
        $changelog = trim($changelogOverride);
        if ($changelog === '') {
            if (!empty($repoOtaManifest['changelog'])) {
                $changelog = is_array($repoOtaManifest['changelog'])
                    ? implode("\n", array_map(fn($line) => '<li>' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') . '</li>', $repoOtaManifest['changelog']))
                    : (string)$repoOtaManifest['changelog'];
            } else {
                $changelog = $fallbackAutoChangelog;
            }
        }

        // Merge files_to_delete & database_queries
        $repoDeletes = is_array($repoOtaManifest['files_to_delete'] ?? null) ? $repoOtaManifest['files_to_delete'] : [];
        $filesToDelete = array_values(array_unique(array_filter(array_merge($detectedDeletes, $repoDeletes), function ($p) use ($excludeRules) {
            $clean = str_replace(['../', '..\\'], '', (string)$p);
            return $clean !== '' && !self::isPathExcluded($clean, $excludeRules);
        })));

        $repoQueries = is_array($repoOtaManifest['database_queries'] ?? null) ? $repoOtaManifest['database_queries'] : [];
        $dbQueries = array_values(array_filter(array_map('trim', array_merge($repoQueries, $extraDbQueries)), fn($q) => $q !== ''));

        // Create output release ZIP
        $safeVersion = preg_replace('/[^a-zA-Z0-9._-]/', '_', $versionNumber);
        $targetZipPath = $tierDir . DIRECTORY_SEPARATOR . "update_{$safeVersion}.zip";
        $tempBuildZip = $tierDir . DIRECTORY_SEPARATOR . ".build_{$safeVersion}_" . bin2hex(random_bytes(4)) . ".zip";

        $outZip = new ZipArchive();
        if ($outZip->open($tempBuildZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $srcZip->close();
            return ['status' => 'error', 'message' => 'Could not create output update ZIP archive.'];
        }

        $fileCount = 0;
        for ($i = 0; $i < $srcZip->numFiles; $i++) {
            $entryName = str_replace('\\', '/', (string)$srcZip->getNameIndex($i));
            if (str_ends_with($entryName, '/')) {
                continue; // skip directory entries
            }
            if ($targetPrefix !== '' && !str_starts_with($entryName, $targetPrefix)) {
                continue;
            }

            $relPath = $targetPrefix !== '' ? substr($entryName, strlen($targetPrefix)) : $entryName;
            if ($relPath === '' || $relPath === 'ota_manifest.json' || $relPath === 'manifest.json') {
                continue;
            }
            if (self::isPathExcluded($relPath, $excludeRules)) {
                continue;
            }

            $contents = $srcZip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }

            $outZip->addFromString('files/' . $relPath, $contents);
            $fileCount++;
        }

        $srcZip->close();

        if ($fileCount === 0) {
            $outZip->close();
            @unlink($tempBuildZip);
            return [
                'status'  => 'error',
                'message' => "No deployable files found under subdirectory '{$subdir}' in the GitHub archive.",
            ];
        }

        $manifest = [
            'version'          => $versionNumber,
            'tier'             => strtoupper((string)$tier['tier_code']),
            'commit_sha'       => $commitSha,
            'changelog'        => $changelog,
            'files_to_delete'  => $filesToDelete,
            'database_queries' => $dbQueries,
        ];
        $outZip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $outZip->close();

        if (file_exists($targetZipPath)) {
            @unlink($targetZipPath);
        }
        if (!@rename($tempBuildZip, $targetZipPath)) {
            @unlink($tempBuildZip);
            return ['status' => 'error', 'message' => 'Failed to finalize update ZIP in secure storage.'];
        }

        $checksum = hash_file('sha256', $targetZipPath);

        // Save in script_updates & update script_tiers tracking columns
        try {
            $checkStmt = $pdo->prepare("SELECT id FROM script_updates WHERE tier_id = ? AND version_number = ? LIMIT 1");
            $checkStmt->execute([$tierId, $versionNumber]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                $updStmt = $pdo->prepare("UPDATE script_updates SET zip_path = ?, checksum = ?, changelog = ?, source = 'github', commit_sha = ?, release_date = CURRENT_TIMESTAMP, is_released = 1 WHERE id = ?");
                $updStmt->execute([$targetZipPath, $checksum, $changelog, $commitSha, $existingId]);
                $updateId = (int)$existingId;
            } else {
                $insStmt = $pdo->prepare("INSERT INTO script_updates (tier_id, version_number, zip_path, checksum, changelog, source, commit_sha, is_released) VALUES (?, ?, ?, ?, ?, 'github', ?, 1)");
                $insStmt->execute([$tierId, $versionNumber, $targetZipPath, $checksum, $changelog, $commitSha]);
                $updateId = (int)$pdo->lastInsertId();
            }

            $tierUpd = $pdo->prepare("UPDATE script_tiers SET last_github_sha = ?, last_pulled_at = NOW() WHERE id = ?");
            $tierUpd->execute([$commitSha, $tierId]);
        } catch (PDOException $e) {
            @unlink($targetZipPath);
            return ['status' => 'error', 'message' => 'Database error while saving GitHub update: ' . $e->getMessage()];
        }

        return [
            'status'     => 'success',
            'update_id'  => $updateId,
            'version'    => $versionNumber,
            'checksum'   => $checksum,
            'file_count' => $fileCount,
            'zip_path'   => $targetZipPath,
        ];
    }

    /**
     * Normalize a domain name and filter out local/placeholder hosts that cannot receive remote webhooks
     */
    public static function normalizeTargetDomain(?string $rawDomain): string
    {
        $d = strtolower(trim((string)$rawDomain));
        if ($d === '' || $d === 'n/a') {
            return '';
        }
        $d = preg_replace('#^https?://#i', '', $d);
        $d = explode('/', $d)[0];
        $d = preg_replace('/:(80|443)$/', '', $d);
        if (
            $d === '' ||
            $d === 'localhost' ||
            $d === '127.0.0.1' ||
            $d === '::1' ||
            str_starts_with($d, '*.') ||
            strpos($d, '.') === false
        ) {
            return '';
        }
        return $d;
    }

    /**
     * Broadcast signed HMAC-SHA256 update push signals to all active licensed websites for an update's tier
     */
    public static function signalLicensedWebsites(PDO $pdo, int $updateId): array
    {
        $stmt = $pdo->prepare("
            SELECT u.id AS update_id, u.tier_id, u.version_number, u.checksum,
                   t.tier_code, t.tier_name, t.signal_endpoint
            FROM script_updates u
            JOIN script_tiers t ON u.tier_id = t.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$updateId]);
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            return [
                'status'        => 'error',
                'message'       => 'Update record not found.',
                'total_domains' => 0,
                'accepted'      => 0,
                'up_to_date'    => 0,
                'failed'        => 0,
                'results'       => [],
            ];
        }

        $tierId = (int)$update['tier_id'];
        $endpointPath = '/' . ltrim(trim((string)($update['signal_endpoint'] ?: '/api/v1/ota-signal.php')), '/');

        // Collect all active licenses for this project tier (including legacy fallback for IDs 1 & 2 if tier_id is NULL)
        if ($tierId === 1) {
            $licStmt = $pdo->prepare("SELECT id, license_key, domain FROM licenses WHERE status = 'active' AND (tier_id = 1 OR (tier_id IS NULL AND license_type = 'extended'))");
            $licStmt->execute();
        } elseif ($tierId === 2) {
            $licStmt = $pdo->prepare("SELECT id, license_key, domain FROM licenses WHERE status = 'active' AND (tier_id = 2 OR (tier_id IS NULL AND (license_type IS NULL OR license_type != 'extended')))");
            $licStmt->execute();
        } else {
            $licStmt = $pdo->prepare("SELECT id, license_key, domain FROM licenses WHERE status = 'active' AND tier_id = ?");
            $licStmt->execute([$tierId]);
        }
        $licenses = $licStmt->fetchAll(PDO::FETCH_ASSOC);

        $targets = []; // keyed by lowercase domain to avoid duplicate signals to the same host
        $domStmt = $pdo->prepare("SELECT domain_name FROM licensed_domains WHERE license_id = ?");

        foreach ($licenses as $lic) {
            $licId = (int)$lic['id'];
            $licKey = (string)$lic['license_key'];
            $candidateDomains = [];

            $primaryNorm = self::normalizeTargetDomain($lic['domain'] ?? '');
            if ($primaryNorm !== '') {
                $candidateDomains[] = $primaryNorm;
            }

            $domStmt->execute([$licId]);
            foreach ($domStmt->fetchAll(PDO::FETCH_COLUMN) as $regDom) {
                $norm = self::normalizeTargetDomain((string)$regDom);
                if ($norm !== '') {
                    $candidateDomains[] = $norm;
                }
            }

            foreach ($candidateDomains as $dom) {
                if (!isset($targets[$dom])) {
                    $targets[$dom] = [
                        'license_id'  => $licId,
                        'license_key' => $licKey,
                        'domain'      => $dom,
                        'url'         => 'https://' . $dom . $endpointPath,
                    ];
                }
            }
        }

        if (empty($targets)) {
            return [
                'status'        => 'success',
                'message'       => 'No active licensed domains found to signal for ' . $update['tier_code'] . '.',
                'total_domains' => 0,
                'total'         => 0,
                'sent'          => 0,
                'accepted'      => 0,
                'up_to_date'    => 0,
                'failed'        => 0,
                'results'       => [],
            ];
        }

        // Dispatch parallel signed POST requests in batches of 10 via curl_multi
        $batches = array_chunk(array_values($targets), 10);
        $accepted = 0;
        $upToDate = 0;
        $failed = 0;
        $results = [];

        $logStmt = $pdo->prepare("
            INSERT INTO ota_signal_logs (update_id, tier_id, license_id, domain_name, endpoint_url, http_code, status, response_body)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($batches as $batch) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($batch as $idx => $target) {
                $payload = [
                    'event'          => 'ota.update_available',
                    'project_tier'   => strtoupper((string)$update['tier_code']),
                    'latest_version' => (string)$update['version_number'],
                    'checksum'       => (string)$update['checksum'],
                    'domain'         => $target['domain'],
                    'timestamp'      => time(),
                    'nonce'          => bin2hex(random_bytes(16)),
                ];
                $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
                $sig = 'sha256=' . hash_hmac('sha256', $jsonPayload, $target['license_key']);

                $ch = curl_init($target['url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $jsonPayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'X-LM-Event: ota.update_available',
                        'X-LM-Signature: ' . $sig,
                        'User-Agent: PMH-LicenseManager-Signal/2.0',
                    ],
                ]);

                curl_multi_add_handle($mh, $ch);
                $handles[$idx] = ['ch' => $ch, 'target' => $target];
            }

            $running = null;
            do {
                $mrc = curl_multi_exec($mh, $running);
                if ($running > 0 && $mrc === CURLM_OK) {
                    if (curl_multi_select($mh, 1.0) === -1) {
                        usleep(50000);
                    }
                }
            } while ($running > 0 && $mrc === CURLM_OK);

            foreach ($handles as $item) {
                $ch = $item['ch'];
                $target = $item['target'];
                $rawResp = (string)curl_multi_getcontent($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                $decoded = json_decode($rawResp, true);
                $clientStatus = is_array($decoded) ? (string)($decoded['status'] ?? '') : '';

                if ($httpCode >= 200 && $httpCode < 300) {
                    if ($clientStatus === 'up_to_date') {
                        $statusLabel = 'up_to_date';
                        $upToDate++;
                    } elseif ($clientStatus === 'skipped') {
                        $statusLabel = 'skipped';
                        $accepted++;
                    } else {
                        $statusLabel = 'accepted';
                        $accepted++;
                    }
                } else {
                    $statusLabel = 'failed';
                    $failed++;
                }

                $respStore = $rawResp !== '' ? substr($rawResp, 0, 1000) : ($curlErr ?: "HTTP {$httpCode}");
                try {
                    $logStmt->execute([
                        $updateId,
                        $tierId,
                        $target['license_id'],
                        $target['domain'],
                        $target['url'],
                        $httpCode,
                        $statusLabel,
                        $respStore,
                    ]);
                } catch (PDOException $e) {
                    error_log("Signal log insert error: " . $e->getMessage());
                }

                $results[] = [
                    'domain'    => $target['domain'],
                    'http_code' => $httpCode,
                    'status'    => $statusLabel,
                    'detail'    => is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : substr($respStore, 0, 160),
                ];
            }

            curl_multi_close($mh);
        }

        return [
            'status'        => 'success',
            'message'       => "Broadcasted update signal to " . count($targets) . " licensed domain(s): {$accepted} accepted, {$upToDate} already up-to-date, {$failed} failed.",
            'total_domains' => count($targets),
            'total'         => count($targets),
            'sent'          => $accepted + $upToDate,
            'accepted'      => $accepted,
            'up_to_date'    => $upToDate,
            'failed'        => $failed,
            'results'       => $results,
        ];
    }
}
