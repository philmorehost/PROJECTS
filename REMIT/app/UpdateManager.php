<?php
declare(strict_types=1);

/** File-only, administrator-triggered releases distributed by License Manager. */
final class UpdateManager
{
    public function __construct(private string $root, private ?ManagerClient $client = null, private ?LicenseManager $license = null) {}

    public function current(): array
    {
        $data = json_decode((string)file_get_contents($this->root . '/version.json'), true);
        if (($data['product'] ?? '') !== 'remit' || !self::validVersion($data['version'] ?? '')) throw new RuntimeException('Installed version metadata is invalid.');
        return $data;
    }

    public static function validVersion(mixed $version): bool
    {
        return is_string($version) && strlen($version) <= 40 && (bool)preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[a-zA-Z0-9.-]+)?$/D', $version);
    }

    public function read(string $name): array
    {
        if (!in_array($name, ['offer', 'pending', 'last'], true)) throw new LogicException('Invalid update state.');
        $data = json_decode((string)@file_get_contents($this->root . '/storage/update-' . $name . '.json'), true);
        return is_array($data) ? $data : [];
    }

    public function check(): array
    {
        $license = $this->license ?? new LicenseManager($this->root);
        if (!$license->check($_SERVER['HTTP_HOST'] ?? '', true)['valid']) throw new RuntimeException('An active licence is required for updates.');
        $credentials = $license->credentials();
        $data = ($this->client ?? new ManagerClient())->json('/check-update.php', ['license_key' => $credentials['key'], 'domain' => $credentials['domain']]);
        if (($data['status'] ?? '') !== 'success' || !self::validVersion($data['latest_version'] ?? '')) throw new RuntimeException('Manager could not provide an update. Check the licence tier and released packages in LM.');
        $available = version_compare($data['latest_version'], $this->current()['version'], '>');
        if ($available) {
            if (!preg_match('/^[a-f0-9]{64}$/Di', $data['checksum'] ?? '')) throw new RuntimeException('Manager did not provide a SHA-256 checksum.');
            ManagerClient::downloadUrl($data['download_url'] ?? '');
        }
        $offer = ['available' => $available, 'version' => $data['latest_version'], 'changelog' => substr((string)($data['changelog'] ?? ''), 0, 20000), 'checked_at' => gmdate('c')];
        LicenseManager::writeJson($this->root . '/storage/update-offer.json', $offer);
        // Download token is only returned to server-side callers, never rendered in HTML or logs.
        return $offer + ['url' => $data['download_url'] ?? '', 'checksum' => strtolower($data['checksum'] ?? '')];
    }

    public function pull(): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Enable the PHP ZIP extension to pull updates.');
        $mutex = fopen($this->root . '/storage/update.lock', 'c');
        if (!$mutex || !flock($mutex, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another update operation is running.');
        try {
            $offer = $this->check(); // Always obtain a fresh one-time token, not an expired check result.
            if (!$offer['available']) throw new RuntimeException('No newer release is available.');
            $id = bin2hex(random_bytes(12));
            $dir = $this->root . '/storage/updates/' . $id;
            if (!mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create the private staging directory.');
            ($this->client ?? new ManagerClient())->download($offer['url'], $dir . '/package.zip');
            if (!hash_equals($offer['checksum'], (string)hash_file('sha256', $dir . '/package.zip'))) throw new RuntimeException('Update checksum mismatch. Nothing was installed.');
            $files = $this->inspect($dir . '/package.zip', $offer['version']);
            LicenseManager::writeJson($this->root . '/storage/update-pending.json', ['id' => $id, 'version' => $offer['version'], 'checksum' => $offer['checksum'], 'files' => count($files), 'pulled_at' => gmdate('c')]);
            return 'Release ' . $offer['version'] . ' pulled and verified (' . count($files) . ' files). Review your backups, then install.';
        } finally { flock($mutex, LOCK_UN); fclose($mutex); }
    }

    /** Validate the entire archive before writing any application file. No extractTo() on untrusted paths. */
    public function inspect(string $archive, string $version): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) throw new RuntimeException('Update is not a valid ZIP archive.');
        try {
            if ($zip->numFiles > 5000) throw new RuntimeException('Update contains too many entries.');
            $entries = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i); $name = $stat['name'];
                self::safePath(rtrim($name, '/'));
                $opsys = 0; $attrs = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attrs);
                $mode = ($attrs >> 16) & 0170000;
                if ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000) throw new RuntimeException('Update contains a link or special file.');
                if (($stat['encryption_method'] ?? 0) !== 0) throw new RuntimeException('Encrypted updates are not supported.');
                $total += $stat['size'];
                if ($stat['size'] > 10 * 1024 * 1024 || $total > 100 * 1024 * 1024) throw new RuntimeException('Update exceeds the expanded size limit.');
                if (str_ends_with($name, '/')) continue;
                if (isset($entries[$name])) throw new RuntimeException('Update contains duplicate entries.');
                $entries[$name] = $i;
            }
            // Support a REMIT-only ZIP, REMIT/ ZIP, or a GitHub repository wrapper/REMIT/ ZIP.
            // Product identity is mandatory: an LM tier can otherwise accidentally serve another app.
            $prefix = null; $identity = [];
            foreach (array_keys($entries) as $name) {
                if ($name !== 'version.json' && !preg_match('~^(?:[a-zA-Z0-9_.-]+/)?REMIT/version\.json$~D', $name)) continue;
                $candidate = json_decode((string)$zip->getFromName($name), true);
                if (($candidate['product'] ?? '') !== 'remit') continue;
                if ($prefix !== null) throw new RuntimeException('Update has multiple REMIT payloads.');
                $prefix = substr($name, 0, -strlen('version.json')); $identity = $candidate;
            }
            if ($prefix === null) throw new RuntimeException('Package must contain REMIT version.json product metadata.');
            $manifestName = isset($entries[$prefix . 'manifest.json']) ? $prefix . 'manifest.json' : 'manifest.json';
            $manifest = json_decode((string)$zip->getFromName($manifestName), true);
            if (!is_array($manifest) || ($identity['product'] ?? '') !== 'remit' || ($identity['version'] ?? '') !== $version || ltrim((string)($manifest['version'] ?? ''), 'v') !== $version) throw new RuntimeException('Package product or version does not match the REMIT release.');
            if (!empty($manifest['database_queries']) || !empty($manifest['files_to_delete'])) throw new RuntimeException('This release needs migrations or deletions. Use an operator-reviewed deployment; automatic SQL/deletion is disabled.');
            $files = []; $seen = [];
            foreach ($entries as $name => $index) {
                if (!str_starts_with($name, $prefix)) continue; // Ignore sibling projects in a GitHub archive; never write them.
                $path = substr($name, strlen($prefix));
                if ($path === 'manifest.json' || in_array($path, ['config/.htaccess', 'storage/.htaccess'], true)) continue;
                if (!self::allowed($path)) throw new RuntimeException('Package contains an unsupported or protected path: ' . $path);
                if (isset($seen[strtolower($path)])) throw new RuntimeException('Case-colliding package paths are not allowed.');
                $seen[strtolower($path)] = true;
                $files[$path] = $index;
            }
            foreach (['index.php', 'version.json', 'app/bootstrap.php'] as $required) if (!isset($files[$required])) throw new RuntimeException('Package is missing required REMIT files.');
            return $files;
        } finally { $zip->close(); }
    }

    public static function safePath(string $path): void
    {
        if ($path === '' || strlen($path) > 240 || !preg_match('~^[a-zA-Z0-9_./-]+$~D', $path)) throw new RuntimeException('Unsafe archive path.');
        foreach (explode('/', $path) as $part) if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('Unsafe archive path.');
    }

    public static function allowed(string $path): bool
    {
        self::safePath($path);
        if (in_array($path, ['index.php', 'router.php', '.htaccess', '.gitignore', 'version.json', 'README.md'], true)) return true;
        if (!preg_match('~^(app|bin|tests|database|public/assets)/~', $path)) return false;
        foreach (explode('/', $path) as $part) if (str_starts_with($part, '.') && $part !== '.htaccess') return false;
        return (bool)preg_match('~(?:\.(?:php|css|js|json|md|sql)|/\.htaccess)$~D', $path);
    }

    private function destination(string $path): string
    {
        self::safePath($path);
        $cursor = $this->root;
        foreach (explode('/', $path) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) throw new RuntimeException('Update target contains a symbolic link.');
        }
        if (file_exists($cursor) && !is_file($cursor)) throw new RuntimeException('Update target is not a regular file.');
        return $cursor;
    }

    public function install(): string
    {
        // Force an online check: offline grace is not sufficient to install executable code.
        if (!($this->license ?? new LicenseManager($this->root))->check($_SERVER['HTTP_HOST'] ?? '', true)['valid']) throw new RuntimeException('An active licence is required to install updates.');
        $pending = $this->read('pending');
        if (!preg_match('/^[a-f0-9]{24}$/D', $pending['id'] ?? '') || !self::validVersion($pending['version'] ?? '') || !version_compare($pending['version'], $this->current()['version'], '>')) throw new RuntimeException('Pull a newer release before installing.');
        $mutex = fopen($this->root . '/storage/update.lock', 'c');
        if (!$mutex || !flock($mutex, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another update operation is running.');
        $requestLock = $GLOBALS['remit_request_lock'] ?? null;
        if (is_resource($requestLock)) { flock($requestLock, LOCK_UN); fclose($requestLock); unset($GLOBALS['remit_request_lock']); }
        $lock = fopen($this->root . '/storage/runtime.lock', 'c');
        $maintenance = $this->root . '/storage/maintenance.json'; $marked = false; $recover = false;
        try {
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Other portal requests are active. Please retry installation shortly.');
            $pending = $this->read('pending');
            if (!preg_match('/^[a-f0-9]{24}$/D', $pending['id'] ?? '') || !self::validVersion($pending['version'] ?? '') || !version_compare($pending['version'], $this->current()['version'], '>')) throw new RuntimeException('The staged release changed or is no longer newer. Pull a fresh release.');
            $dir = $this->root . '/storage/updates/' . $pending['id']; $archive = $dir . '/package.zip';
            if (!hash_equals($pending['checksum'], (string)hash_file('sha256', $archive))) throw new RuntimeException('Staged package checksum changed. Pull the release again.');
            $files = $this->inspect($archive, $pending['version']);
            $backup = $dir . '/backup';
            if (is_dir($backup)) throw new RuntimeException('This staging area has already been used. Pull a fresh package.');
            if (!mkdir($backup, 0700)) throw new RuntimeException('Cannot create a backup directory.');
            $original = [];
            // Complete backups and permission checks before touching any installed application file.
            foreach ($files as $path => $index) {
                $dest = $this->destination($path);
                $ancestor = dirname($dest); while (!is_dir($ancestor)) $ancestor = dirname($ancestor);
                if (!is_writable($ancestor)) throw new RuntimeException('Update target directory is not writable: ' . $path);
                $original[$path] = is_file($dest);
                if ($original[$path]) {
                    if (!is_dir(dirname($backup . '/' . $path)) && !mkdir(dirname($backup . '/' . $path), 0700, true)) throw new RuntimeException('Cannot create backup directories.');
                    if (!copy($dest, $backup . '/' . $path)) throw new RuntimeException('Cannot back up ' . $path);
                }
            }
            LicenseManager::writeJson($dir . '/rollback.json', $original);
            LicenseManager::writeJson($maintenance, ['started_at' => gmdate('c'), 'backup' => $pending['id']]); $marked = true;
            ignore_user_abort(true); set_time_limit(120);
            $zip = new ZipArchive(); if ($zip->open($archive) !== true) throw new RuntimeException('Cannot reopen package.');
            $written = [];
            try {
                foreach ($files as $path => $index) {
                    $dest = $this->destination($path);
                    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) throw new RuntimeException('Cannot create release directory.');
                    $body = $zip->getFromIndex($index);
                    if ($body === false) throw new RuntimeException('Cannot read package entry.');
                    self::replace($dest, $body); $written[] = $path;
                    if (function_exists('opcache_invalidate')) opcache_invalidate($dest, true);
                }
            } catch (Throwable $e) {
                foreach (array_reverse($written) as $path) {
                    try {
                        if ($original[$path]) self::replace($this->root . '/' . $path, (string)file_get_contents($backup . '/' . $path));
                        elseif (!unlink($this->root . '/' . $path)) throw new RuntimeException('Cannot remove new file.');
                        if (function_exists('opcache_invalidate')) opcache_invalidate($this->root . '/' . $path, true);
                    } catch (Throwable) { $recover = true; }
                }
                throw new RuntimeException($recover ? 'Update failed; rollback incomplete. Maintenance is retained. Restore the private backup using the README instructions.' : 'Update failed and original application files were restored.');
            } finally { $zip->close(); }
            $recover = true; // Keep maintenance on if post-install bookkeeping unexpectedly fails.
            LicenseManager::writeJson($this->root . '/storage/update-last.json', ['version' => $pending['version'], 'installed_at' => gmdate('c'), 'backup' => $pending['id']]);
            @unlink($this->root . '/storage/update-pending.json');
            $recover = false;
            error_log('REMIT release installed: ' . $pending['version']);
            return 'Release ' . $pending['version'] . ' installed. Previous files remain in the private backup. Refresh the portal.';
        } finally {
            if ($marked && !$recover) @unlink($maintenance);
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            flock($mutex, LOCK_UN); fclose($mutex);
        }
    }

    private static function replace(string $path, string $body): void
    {
        $tmp = dirname($path) . '/.remit-update-' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($tmp, $body, LOCK_EX) !== strlen($body) || !chmod($tmp, 0644) || !rename($tmp, $path)) throw new RuntimeException('Cannot replace an application file.');
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }
}
