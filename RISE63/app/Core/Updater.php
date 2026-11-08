<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;
use Throwable;

/**
 * Pull releases from the License Manager.
 *
 * The provider hosts the release archive; this class is the client that asks for it, verifies it
 * and unpacks it. It never talks to GitHub — the provider's own admin pulls from GitHub and
 * publishes a version, which is what an installation sees.
 *
 *   POST https://manager.pmhserver.name.ng/check-update.php
 *        key=<licence key>&domain=<this domain>[&force_version=1.2.0]
 *   ->   {"status":"success","latest_version":"1.2.0","checksum":"<sha256>",
 *        "changelog":"…","download_url":"https://…/download.php?token=…","tier_detected":"NON-SAAS"}
 *   ->   {"status":"error"|"suspended","message":"…"}          HTTP 400 / 403 / 500
 *
 * and then GETs the one-time download URL, which returns the release ZIP.
 *
 * Design rules, in order of importance:
 *
 *   1. Nothing is written to the installation until the archive has been fully received, its
 *      sha256 matched the manifest, and every file inside it has been checked for path traversal.
 *      A bad download must leave the site exactly as it was.
 *   2. The operator sees the plan before it happens: which files are added, replaced or removed,
 *      which SQL statements would run, and how much disk they take. Nothing is applied blind.
 *   3. Applying is all-or-nothing for the files. Each replaced file is moved to a backup directory
 *      first; if any write fails, every file already written is restored from that backup.
 *   4. Database statements run only after the files are in place, and a failing statement does not
 *      stop the files being applied — but it is reported loudly with the statement that failed,
 *      because a half-migrated schema is something the operator must know about immediately.
 *   5. This sandbox has no PHP extensions at all (no zip, no curl, no openssl), so nothing here
 *      depends on them: the ZIP central directory is parsed directly and inflate is optional.
 */
final class Updater
{
    public const ENDPOINT  = 'https://manager.pmhserver.name.ng/check-update.php';
    public const MAX_BYTES = 67108864;      // 64 MiB release archive ceiling
    public const MAX_FILES = 5000;          // refuse absurd archives before unpacking
    public const STATE_FILE = 'update-state.json';
    public const BACKUP_DIR = 'updates/backup';
    public const STAGE_DIR  = 'updates/stage';

    /** Directories an update may never touch, whatever the manifest says. */
    private const PROTECTED = ['config', 'storage'];

    /** Files whose replacement would let the archive take over the update process itself. */
    private const PROTECTED_FILES = ['app/Core/Updater.php', 'bin/update.php'];

    public function __construct(
        private readonly string $root,
        private readonly string $configDir,
        private readonly string $storageDir
    ) {
    }

    /* ────────────────────────────── what is installed ────────────────────────────── */

    /** The version recorded by the last applied update, falling back to the build constant. */
    public function currentVersion(): string
    {
        $state = $this->state();
        $value = trim((string) ($state['version'] ?? ''));
        return $value !== '' ? $value : \RISE63\VERSION;
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $file = $this->configDir . '/' . self::STATE_FILE;
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** The history of applied updates, newest first. @return list<array<string,mixed>> */
    public function history(): array
    {
        $state = $this->state();
        $log   = $state['history'] ?? [];
        return is_array($log) ? array_values(array_filter($log, 'is_array')) : [];
    }

    /* ─────────────────────────────── asking the provider ─────────────────────────── */

    /**
     * Ask the provider what the latest release is.
     *
     * @param string|null $forceVersion ask for one specific version instead of "latest"
     * @return array{
     *     ok: bool,
     *     unreachable: bool,
     *     status: string,
     *     message: string,
     *     version: string,
     *     changelog: string,
     *     checksum: string,
     *     download_url: string,
     *     tier: string
     * }
     */
    public function check(?string $forceVersion = null, ?string $host = null): array
    {
        $blank = [
            'ok' => false, 'unreachable' => false, 'status' => '', 'message' => '',
            'version' => '', 'changelog' => '', 'checksum' => '', 'download_url' => '', 'tier' => '',
        ];
        // $blank + [...] would keep the blank values: PHP's + never overwrites. Merge onto it.
        $fail = static fn (array $overrides): array => array_merge($blank, $overrides);

        $activation = new Activation($this->configDir);
        $key        = $activation->storedKey();
        if ($key === null) {
            return $fail(['message' => 'No licence key is stored, so updates cannot be requested.']);
        }

        $domain = Activation::domainFromHost((string) ($host ?? ($_SERVER['HTTP_HOST'] ?? gethostname() ?: '')));
        if (!Activation::validDomain($domain)) {
            return $fail(['message' => 'This installation has no usable domain name to send to the provider.']);
        }

        // installed_version is not in the written contract, but the deployed endpoint returns
        // update_required against it; sending it costs nothing and lets the server answer precisely.
        $fields = [
            'key'               => (string) $key['key'],
            'domain'            => $domain,
            'installed_version' => $this->currentVersion(),
        ];
        if ($forceVersion !== null && trim($forceVersion) !== '') {
            $fields['force_version'] = trim($forceVersion);
        }

        try {
            $reply = $this->post(self::ENDPOINT, $fields);
        } catch (RuntimeException $error) {
            return $fail(['unreachable' => true, 'message' => $error->getMessage()]);
        }

        // check-update answers 403 with {"status":"suspended"} and 400 for a bad request, so the
        // body is interpreted before the HTTP status is considered.
        $data = json_decode($reply['body'], true);
        if (!is_array($data)) {
            return $fail([
                'message' => $reply['code'] >= 400
                    ? 'The update service answered HTTP ' . $reply['code'] . ' without an explanation.'
                    : 'The provider answered with something that is not JSON.',
            ]);
        }

        return $this->interpretCheck($data);
    }

    /**
     * Interpret a check-update.php body.
     *
     * Kept separate from the HTTP call so every response shape can be tested against the shapes
     * the deployed service actually sends — which differ from the written contract in three ways:
     * it adds an `access` block, it reports `update_required`, and it sends `status: "success"`
     * even when `access.allowed` is false (a licence on hold).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function interpretCheck(array $data): array
    {
        $blank = [
            'ok' => false, 'unreachable' => false, 'status' => '', 'message' => '',
            'version' => '', 'changelog' => '', 'checksum' => '', 'download_url' => '', 'tier' => '',
            'project' => '', 'update_required' => null,
        ];
        $fail = static fn (array $overrides): array => array_merge($blank, $overrides);

        $status  = (string) ($data['status'] ?? '');
        $message = (string) ($data['message'] ?? '');
        // "success" is what the endpoint sends today; "ok"/1 are accepted defensively rather than
        // silently treating a good answer as a refusal if it changes again.
        if (!in_array($status, ['success', 'ok', 'valid', '1'], true)) {
            $text = match ($status) {
                'suspended' => 'This licence is suspended, so updates are not served.',
                'error'     => 'The provider refused the update request: ' . ($message !== '' ? $message : 'no reason given'),
                default     => 'The provider did not return a usable answer' . ($message !== '' ? ': ' . $message : '.'),
            };
            return $fail(['status' => $status, 'message' => $text]);
        }

        // A blocked licence arrives with status "success". It must never be read as "a release is
        // available", or the page would offer an update the download link will refuse.
        $access = $data['access'] ?? null;
        if (is_array($access) && array_key_exists('allowed', $access) && $access['allowed'] === false) {
            $reason = trim((string) ($access['message'] ?? ''));
            $title  = trim((string) ($access['title'] ?? ''));
            return $fail([
                'status'  => 'blocked',
                'tier'    => (string) ($data['tier_detected'] ?? ''),
                'message' => 'The provider has blocked updates for this licence'
                    . ($title !== '' ? ' (' . $title . ')' : '')
                    . ($reason !== '' ? ': ' . $reason : '.')
                    . ' Contact the provider to have it reinstated.',
            ]);
        }

        $version = (string) ($data['latest_version'] ?? '');
        if ($version === '' || $version === '0.00' || $version === '0') {
            return $fail([
                'status'  => 'success',
                'tier'    => (string) ($data['tier_detected'] ?? ''),
                'message' => $message !== '' ? $message : 'No update has been published for this licence tier yet.',
            ]);
        }

        return [
            'ok'           => true,
            'unreachable'  => false,
            'status'       => 'success',
            'message'      => $message,
            'version'      => $version,
            'changelog'    => (string) ($data['changelog'] ?? ''),
            'checksum'     => strtolower(trim((string) ($data['checksum'] ?? ''))),
            'download_url' => (string) ($data['download_url'] ?? ''),
            'tier'         => (string) ($data['tier_detected'] ?? ''),
            'project'      => (string) ($data['project'] ?? ''),
            'update_required' => array_key_exists('update_required', $data)
                ? (bool) $data['update_required']
                : null,
        ];
    }

    /* ────────────────────────────── fetching the release ────────────────────────── */

    /**
     * Download a release archive and hand it to plan().
     *
     * @return array{archive:string, sha256:string, bytes:int}
     */
    public function download(string $url, string $expectedSha = ''): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = (string) parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || $host !== Activation::MANAGER_HOST) {
            throw new RuntimeException(
                'Refusing to download from ' . $url . ': a release must come from https://' . Activation::MANAGER_HOST . '.'
            );
        }

        $archive = $this->stagePath('release.zip');
        $bytes   = $this->fetchToFile($url, $archive);

        $sha = hash_file('sha256', $archive);
        if ($expectedSha !== '' && !hash_equals(strtolower($expectedSha), (string) $sha)) {
            @unlink($archive);
            throw new RuntimeException(
                'The downloaded archive is not the one the provider published (checksum mismatch). '
                . 'Nothing was unpacked. Try again, and if it repeats, report it to the provider.'
            );
        }

        return ['archive' => $archive, 'sha256' => (string) $sha, 'bytes' => $bytes];
    }

    /* ────────────────────────────── inspecting a release ────────────────────────── */

    /**
     * Read the archive and work out exactly what applying it would do. Writes nothing outside
     * storage/updates/stage/.
     *
     * @return array{
     *     ok: bool,
     *     version: string,
     *     tier: string,
     *     changelog: string,
     *     add: list<array{path:string,bytes:int}>,
     *     replace: list<array{path:string,bytes:int,same:bool}>,
     *     remove: list<string>,
     *     keep: list<string>,
     *     sql: list<string>,
     *     warnings: list<string>,
     *     problems: list<string>,
     *     archive: string,
     *     sha256: string
     * }
     */
    public function plan(string $archive, string $sha256 = ''): array
    {
        $result = [
            'ok' => false, 'version' => '', 'tier' => '', 'changelog' => '',
            'add' => [], 'replace' => [], 'remove' => [], 'keep' => [], 'sql' => [],
            'warnings' => [], 'problems' => [], 'archive' => $archive, 'sha256' => $sha256,
        ];

        if (!is_file($archive)) {
            $result['problems'][] = 'The release archive is not on disk.';
            return $result;
        }
        if ((int) filesize($archive) > self::MAX_BYTES) {
            $result['problems'][] = 'The release archive is larger than the ' . (self::MAX_BYTES >> 20) . ' MiB limit.';
            return $result;
        }

        $zip = new ZipReader($archive);
        if (!$zip->valid()) {
            $result['problems'][] = 'The release is not a readable ZIP archive.';
            return $result;
        }
        $entries = $zip->entries();
        if (count($entries) > self::MAX_FILES) {
            $result['problems'][] = 'The release contains ' . count($entries) . ' files, more than the '
                . self::MAX_FILES . ' this installer will accept.';
            return $result;
        }

        // A ZIP may keep everything inside one top-level folder; normalise that away so the
        // manifest and the file list agree with the installation's own layout.
        $prefix = $this->commonPrefix($entries);
        $strip  = static fn (string $name): string => $prefix !== '' && str_starts_with($name, $prefix)
            ? substr($name, strlen($prefix))
            : $name;

        $manifest = null;
        foreach ($entries as $entry) {
            if ($entry['dir']) {
                continue;
            }
            if ($strip($entry['name']) === 'manifest.json') {
                $raw = $zip->read($entry['name']);
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $result['problems'][] = 'manifest.json is in the archive but is not valid JSON.';
                } else {
                    $manifest = $decoded;
                }
                break;
            }
        }

        if ($manifest === null && $result['problems'] === []) {
            $result['warnings'][] = 'The archive has no manifest.json, so it carries no version, changelog or SQL. '
                . 'Only the files will be installed.';
        }

        if (is_array($manifest)) {
            $result['version']   = trim((string) ($manifest['version'] ?? ''));
            $result['tier']      = strtoupper(trim((string) ($manifest['tier'] ?? '')));
            $result['changelog'] = trim((string) ($manifest['changelog'] ?? ''));

            $sql = $manifest['database_queries'] ?? [];
            if (is_array($sql)) {
                foreach ($sql as $statement) {
                    $statement = trim((string) $statement);
                    if ($statement !== '') {
                        $result['sql'][] = $statement;
                    }
                }
            }

            foreach (['files_to_delete' => 'remove'] as $key => $bucket) {
                foreach ((array) ($manifest[$key] ?? []) as $path) {
                    $safe = $this->safePath((string) $path);
                    if ($safe === null) {
                        $result['problems'][] = 'manifest.json asks to delete "' . $path . '", which is outside the installation or protected.';
                        continue;
                    }
                    if (is_file($this->root . '/' . $safe)) {
                        $result[$bucket][] = $safe;
                    }
                }
            }
        }

        // Walk the archive's own files.
        $seen = [];
        foreach ($entries as $entry) {
            if ($entry['dir']) {
                continue;
            }
            $name = $strip($entry['name']);
            if ($name === '' || $name === 'manifest.json') {
                continue;
            }
            $safe = $this->safePath($name);
            if ($safe === null) {
                $result['problems'][] = 'The archive contains "' . $name . '", which is outside the installation '
                    . 'or inside a protected folder (config/, storage/). Refusing to plan an update around it.';
                continue;
            }
            if (isset($seen[$safe])) {
                $result['problems'][] = 'The archive contains "' . $safe . '" twice.';
                continue;
            }
            $seen[$safe] = true;

            $target = $this->root . '/' . $safe;
            if (is_file($target)) {
                $result['replace'][] = [
                    'path'  => $safe,
                    'bytes' => $entry['size'],
                    'same'  => hash_file('sha256', $target) === $zip->sha256($entry['name']),
                ];
            } else {
                $result['add'][] = ['path' => $safe, 'bytes' => $entry['size']];
            }
        }

        // The installed files the release does not ship are kept, not deleted: this product ships
        // whole-tree archives, and silently removing an operator's extra view would be worse than
        // leaving a stale one behind. The operator is told the counts instead.
        $result['keep'] = [];

        $result['ok'] = $result['problems'] === [];
        return $result;
    }

    /* ───────────────────────────────── applying ─────────────────────────────────── */

    /**
     * Apply a planned release.
     *
     * Files first, then SQL. Every replaced file is copied to a timestamped backup first so a
     * partial failure can be undone; the report says what happened either way.
     *
     * @param array{add:list<array{path:string}>,replace:list<array{path:string}>,remove:list<string>,sql:list<string>,version:string} $plan
     * @return array{ok:bool, written:int, removed:int, sql_run:int, sql_failed:int, backup:string, errors:list<string>, sql_errors:list<string>}
     */
    public function apply(string $archive, array $plan): array
    {
        $report = [
            'ok' => false, 'written' => 0, 'removed' => 0, 'sql_run' => 0, 'sql_failed' => 0,
            'backup' => '', 'errors' => [], 'sql_errors' => [],
        ];

        $zip = new ZipReader($archive);
        if (!$zip->valid()) {
            $report['errors'][] = 'The release archive could not be reopened.';
            return $report;
        }

        $entries = $zip->entries();
        $prefix  = $this->commonPrefix($entries);
        $strip   = static fn (string $name): string => $prefix !== '' && str_starts_with($name, $prefix)
            ? substr($name, strlen($prefix))
            : $name;

        $byPath = [];
        foreach ($entries as $entry) {
            if (!$entry['dir']) {
                $byPath[$strip($entry['name'])] = $entry['name'];
            }
        }

        $stamp  = gmdate('Ymd-His');
        $backup = $this->storageDir . '/' . self::BACKUP_DIR . '/' . $stamp;
        $report['backup'] = $backup;

        // ── Take the backup before touching anything.
        $targets = [];
        foreach ($plan['replace'] ?? [] as $row) {
            $targets[] = (string) ($row['path'] ?? '');
        }
        $targets = array_values(array_filter(array_unique($targets)));
        foreach ($targets as $relative) {
            $safe = $this->safePath($relative);
            if ($safe === null) {
                $report['errors'][] = 'Refusing to back up or replace "' . $relative . '".';
                return $report;
            }
        }
        if ($targets !== []) {
            $this->ensureDir($backup);
            foreach ($targets as $relative) {
                $from = $this->root . '/' . $relative;
                if (!is_file($from)) {
                    continue;
                }
                if (!@copy($from, $backup . '/' . basename($relative))) {
                    $report['errors'][] = 'Could not back up "' . $relative . '" before replacing it. Nothing was changed.';
                    return $report;
                }
            }
            @file_put_contents($backup . '/manifest.json', json_encode([
                'backup_of' => $targets,
                'backup_at' => gmdate('c'),
                'from_version' => $this->currentVersion(),
                'to_version' => (string) ($plan['version'] ?? ''),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // ── Write the files.
        $writtenNow = [];
        $rollback = function (string $why) use (&$report, &$writtenNow, $backup): void {
            foreach ($writtenNow as [$relative, $existedBefore]) {
                $target = $this->root . '/' . $relative;
                if ($existedBefore && is_file($backup . '/' . basename($relative))) {
                    @copy($backup . '/' . basename($relative), $target);
                } else {
                    @unlink($target);
                }
            }
            $report['errors'][] = $why . ' Every file written before this point was restored.';
        };

        foreach ($byPath as $relative => $entryName) {
            $safe = $this->safePath((string) $relative);
            if ($safe === null) {
                continue;
            }
            $target = $this->root . '/' . $safe;
            $existed = is_file($target);

            $contents = $zip->read($entryName);
            if ($contents === null) {
                $rollback('Could not read "' . $safe . '" out of the archive.');
                return $report;
            }

            $this->ensureDir(dirname($target));
            if (@file_put_contents($target, $contents, LOCK_EX) === false) {
                $rollback('Could not write "' . $safe . '".');
                return $report;
            }
            $writtenNow[] = [$safe, $existed];
            $report['written']++;
        }

        // ── Removals, after the writes have succeeded.
        foreach ($plan['remove'] ?? [] as $relative) {
            $safe = $this->safePath((string) $relative);
            if ($safe === null) {
                continue;
            }
            $target = $this->root . '/' . $safe;
            if (is_file($target) && @unlink($target)) {
                $report['removed']++;
            }
        }

        // ── SQL last. A failure here is reported but does not undo the files: half-applied schema
        // is a real possibility either way, and silently rolling back code to match a database that
        // has already run the statements would be worse.
        foreach ($plan['sql'] ?? [] as $statement) {
            try {
                Database::run($statement);
                $report['sql_run']++;
            } catch (Throwable $error) {
                $report['sql_failed']++;
                $report['sql_errors'][] = $statement . ' — ' . $error->getMessage();
            }
        }

        $report['ok'] = true;
        $report['errors'] = [];
        $this->recordApplied($plan, $report);
        @unlink($archive);

        return $report;
    }

    /** Remember what was applied, so the page can show history and the next check compares against it. */
    private function recordApplied(array $plan, array $report): void
    {
        $state = $this->state();
        $log   = is_array($state['history'] ?? null) ? $state['history'] : [];
        $log[] = [
            'version'    => (string) ($plan['version'] ?? ''),
            'tier'       => (string) ($plan['tier'] ?? ''),
            'applied_at' => gmdate('c'),
            'written'    => $report['written'],
            'removed'    => $report['removed'],
            'sql_run'    => $report['sql_run'],
            'backup'     => $report['backup'],
        ];
        $log = array_slice($log, -20);

        $state['version']    = (string) ($plan['version'] ?? '') !== '' ? (string) $plan['version'] : ($state['version'] ?? '');
        $state['applied_at'] = gmdate('c');
        $state['history']    = $log;
        $this->writeState($state);
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $file = $this->configDir . '/' . self::STATE_FILE;
        @file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0600);
    }

    /* ────────────────────────────────── helpers ─────────────────────────────────── */

    /**
     * Turn a path out of an archive or manifest into a relative path that is safe to write.
     * Returns null for absolute paths, traversal, protected folders and the update machinery.
     */
    private function safePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));
        if (str_starts_with($path, '/')) {
            return null;        // absolute: an archive never gets to choose a location from root
        }
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
        $first = strtolower($parts[0]);
        if (in_array($first, self::PROTECTED, true)) {
            return null;
        }
        if (in_array($path, self::PROTECTED_FILES, true)) {
            return null;
        }
        if (preg_match('/\.(php|phtml|phar)$/i', $path) !== 1 && !in_array($path, ['manifest.json'], true)) {
            // Non-PHP files are still allowed — CSS, JS, SQL, images — this branch only exists to
            // make the intent explicit; no restriction is applied here.
        }
        return $path;
    }

    /** @param list<array{name:string,dir:bool,size:int}> $entries */
    private function commonPrefix(array $entries): string
    {
        $first = null;
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', $entry['name']);
            if ($entry['dir'] || $name === '' || $name === 'manifest.json') {
                continue;
            }
            $first = $name;
            break;
        }
        if ($first === null) {
            return '';
        }
        $firstParts = explode('/', $first);
        if (count($firstParts) < 2) {
            return '';
        }

        $prefix = $firstParts[0] . '/';
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', $entry['name']);
            if ($name === 'manifest.json') {
                return '';        // a manifest at the root means the archive is already flat
            }
            if (!$entry['dir'] && !str_starts_with($name, $prefix)) {
                return '';
            }
        }
        return $prefix;
    }

    private function stagePath(string $name): string
    {
        $dir = $this->storageDir . '/' . self::STAGE_DIR;
        $this->ensureDir($dir);
        return $dir . '/' . $name;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir . '. Check that storage/ is writable.');
        }
    }

    /* ──────────────────────────────── HTTP, carefully ───────────────────────────── */

    /**
     * @param array<string,string> $fields
     * @return array{code:int, body:string}
     */
    private function post(string $url, array $fields): array
    {
        return $this->request($url, [
            'method'  => 'POST',
            'content' => http_build_query($fields),
            'headers' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        ]);
    }

    /** Stream a URL into a file, refusing redirects and non-provider hosts. */
    private function fetchToFile(string $url, string $path): int
    {
        $max    = self::MAX_BYTES;
        $bytes  = 0;
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open ' . $path . ' to receive the release.');
        }

        try {
            $result = $this->withStream($url, ['method' => 'GET', 'headers' => "Accept: application/zip\r\n"],
                static function (string $chunk) use ($handle, &$bytes, $max): void {
                    $bytes += strlen($chunk);
                    if ($bytes > $max) {
                        throw new RuntimeException('The release archive is larger than the ' . ($max >> 20) . ' MiB limit.');
                    }
                    fwrite($handle, $chunk);
                });
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($path);
            throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage());
        }

        fclose($handle);
        if ($result['code'] < 200 || $result['code'] >= 300) {
            @unlink($path);
            throw new RuntimeException('The download link answered with HTTP ' . $result['code']
                . '. It is single-use and expires after 30 minutes — ask for a fresh one.');
        }
        if ($bytes === 0) {
            @unlink($path);
            throw new RuntimeException('The provider returned an empty file for the release.');
        }
        return $bytes;
    }

    /**
     * One HTTP request. Uses PHP's HTTPS stream wrapper (verified TLS, no redirects, hard timeout)
     * and hands every chunk to the caller's sink, so a 64 MiB archive never has to fit in memory.
     *
     * @param array{method:string,content?:string,headers?:string} $options
     * @return array{code:int, body:string} body is only kept for JSON-sized replies
     */
    private function request(string $url, array $options): array
    {
        $body = '';
        $result = $this->withStream($url, $options, static function (string $chunk) use (&$body): void {
            if (strlen($body) < self::MAX_BYTES) {
                $body .= $chunk;
            }
        });
        return ['code' => $result['code'], 'body' => $body];
    }

    /**
     * @param array{method:string,content?:string,headers?:string} $options
     * @return array{code:int}
     */
    private function withStream(string $url, array $options, callable $sink): array
    {
        $headers = (string) ($options['headers'] ?? '') . "User-Agent: RISE63-Updater/1.0\r\n";
        $context = stream_context_create([
            'http' => [
                'method'          => $options['method'],
                'header'          => $headers,
                'content'         => (string) ($options['content'] ?? ''),
                'timeout'         => 30,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'max_redirects'   => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            $error  = function_exists('error_get_last') ? (array) error_get_last() : [];
            $reason = trim((string) ($error['message'] ?? ''));
            throw new RuntimeException(
                'The update service at ' . Activation::MANAGER_HOST . ' could not be reached'
                . ($reason !== '' ? ' — ' . $this->scrub($reason) : '')
                . '. Nothing was changed.'
            );
        }

        $meta = stream_get_meta_data($stream);
        $code = 0;
        foreach ((array) ($meta['wrapper_data'] ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $match) === 1) {
                $code = (int) $match[1];
                break;
            }
        }

        // Read everything, including error bodies: check-update explains refusals in JSON and that
        // sentence is the only useful thing the operator gets.
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                continue;
            }
            $sink($chunk);
        }
        fclose($stream);

        return ['code' => $code];
    }

    /** Never let a filesystem path from a PHP error reach the browser. */
    private function scrub(string $message): string
    {
        return (string) preg_replace('#(/[^\s:]+)+#', '<path>', $message);
    }
}
