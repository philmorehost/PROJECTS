<?php
/**
 * License Manager — OTA update storage, versioning and packaging.
 *
 * Handles:
 *   - the private update store (default: <public_html>/secure_updates/<TIER>/)
 *   - semantic-ish version comparison and auto-increment (7.02 → 7.03)
 *   - ZIP validation, manifest injection, GitHub zipball normalisation
 *   - publishing an update (DB + file + checksum)
 *   - deleting updates and physically removing their files from cPanel
 *
 * PHP 7.4 compatible.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';
require_once __DIR__ . '/zip.php';

if (!function_exists('lm_updates_root')) {
    /**
     * Absolute path of the private update store.
     * Override with settings.updates_storage_dir (absolute or relative to /LM).
     *
     * @return string
     */
    function lm_updates_root()
    {
        $settings = lm_settings();
        $configured = trim((string) ($settings['updates_storage_dir'] ?? ''));

        if ($configured !== '') {
            if ($configured[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $configured)) {
                $root = rtrim($configured, '/\\');
            } else {
                $root = rtrim(lm_project_root(), '/\\') . DIRECTORY_SEPARATOR . $configured;
                $root = rtrim($root, '/\\');
            }
        } else {
            $root = rtrim(lm_project_root(), '/\\') . DIRECTORY_SEPARATOR . 'secure_updates';
        }

        return $root;
    }
}

if (!function_exists('lm_safe_tier_code')) {
    /**
     * Filesystem-safe tier folder name.
     *
     * @param string $code
     * @return string
     */
    function lm_safe_tier_code($code)
    {
        $code = strtolower(trim((string) $code));
        $code = preg_replace('/[^a-z0-9_-]/', '_', $code);
        $code = trim($code, '_');
        return $code !== '' ? $code : 'general';
    }
}

if (!function_exists('lm_updates_storage_check')) {
    /**
     * Validate (and optionally create) an update-storage directory.
     *
     * Used by the dedicated "Save storage settings" button so a bad path is
     * reported at save time instead of failing later during a publish.
     *
     * @param string $dir    Value from the settings form (absolute or relative)
     * @param bool   $create Create the directory when it is missing
     * @return array{ok:bool,error:string,path:string,free:int,writable:bool}
     */
    function lm_updates_storage_check($dir, $create = false)
    {
        $out = array('ok' => false, 'error' => '', 'path' => '', 'free' => 0, 'writable' => false);
        $dir = trim((string) $dir);

        if ($dir === '') {
            $path = rtrim(lm_project_root(), '/\\') . DIRECTORY_SEPARATOR . 'secure_updates';
        } elseif ($dir[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $dir)) {
            $path = rtrim($dir, '/\\');
        } else {
            $path = rtrim(rtrim(lm_project_root(), '/\\') . DIRECTORY_SEPARATOR . $dir, '/\\');
        }

        $out['path'] = $path;

        // Packages must never end up inside the LM folder: everything below it
        // is reachable over HTTP, and an update ZIP is a download for customers.
        $lmDir = rtrim(str_replace('\\', '/', lm_lm_dir()), '/');
        $normal = str_replace('\\', '/', $path);
        if ($normal === $lmDir || strpos($normal . '/', $lmDir . '/') === 0) {
            $out['error'] = 'That folder is inside the LM web root, where anyone could download the packages. '
                . 'Use a folder outside it, e.g. ' . lm_updates_root() . '.';
            return $out;
        }

        if (!is_dir($path)) {
            if (!$create) {
                $out['ok'] = true;
                $out['writable'] = true; // will be created on the first publish
                return $out;
            }
            if (!lm_ensure_dir($path, 0755)) {
                $out['error'] = 'Could not create ' . $path . '. Check that the parent folder is writable, '
                    . 'or create it yourself in cPanel → File Manager.';
                return $out;
            }
        }

        if (!is_writable($path)) {
            $out['error'] = $path . ' exists but PHP cannot write to it. Set the folder permissions to 0755.';
            return $out;
        }

        $free = @disk_free_space($path);
        $out['free'] = $free === false ? 0 : (int) $free;
        $out['writable'] = true;
        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('lm_updates_storage_size')) {
    /**
     * How much space the stored packages use, for the settings card.
     *
     * @return array{files:int,bytes:int}
     */
    function lm_updates_storage_size()
    {
        $root = lm_updates_root();
        $out = array('files' => 0, 'bytes' => 0);
        if (!is_dir($root)) {
            return $out;
        }

        $paths = glob($root . DIRECTORY_SEPARATOR . '*.zip');
        $nested = glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.zip');
        foreach (array($paths, $nested) as $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $file) {
                if (is_file($file)) {
                    $out['files']++;
                    $out['bytes'] += (int) filesize($file);
                }
            }
        }

        return $out;
    }
}

if (!function_exists('lm_tier_storage_dir')) {
    /**
     * @param string|array $tier Tier code string or tier row.
     * @param bool         $create
     * @return string
     */
    function lm_tier_storage_dir($tier, $create = true)
    {
        $code = is_array($tier) ? ($tier['tier_code'] ?? '') : $tier;
        $dir = lm_updates_root() . DIRECTORY_SEPARATOR . lm_safe_tier_code($code);

        if ($create) {
            lm_ensure_dir(lm_updates_root(), 0755);
            $index = lm_updates_root() . DIRECTORY_SEPARATOR . 'index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php http_response_code(403); exit('Forbidden');\n");
            }
            lm_ensure_dir($dir, 0755);
            $tierIndex = $dir . DIRECTORY_SEPARATOR . 'index.php';
            if (!file_exists($tierIndex)) {
                @file_put_contents($tierIndex, "<?php http_response_code(403); exit('Forbidden');\n");
            }
        }

        return $dir;
    }
}

/* -------------------------------------------------------------------------
 * Versions
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_version_clean')) {
    /**
     * Normalise a version label: trims "v", spaces and unsafe characters.
     *
     * @param string $version
     * @return string
     */
    function lm_version_clean($version)
    {
        $version = trim((string) $version);
        $version = preg_replace('/^[vV]\s*/', '', $version);
        $version = preg_replace('/[^0-9A-Za-z._\-]/', '', $version);
        return trim($version, '.');
    }
}

if (!function_exists('lm_version_segments')) {
    /**
     * @param string $version
     * @return string[]
     */
    function lm_version_segments($version)
    {
        $clean = lm_version_clean($version);
        if ($clean === '') {
            return array();
        }
        return explode('.', $clean);
    }
}

if (!function_exists('lm_version_compare')) {
    /**
     * Compare two version labels numerically segment-by-segment.
     *
     * @param string $a
     * @param string $b
     * @return int -1 | 0 | 1
     */
    function lm_version_compare($a, $b)
    {
        $sa = lm_version_segments($a);
        $sb = lm_version_segments($b);
        $max = max(count($sa), count($sb));

        for ($i = 0; $i < $max; $i++) {
            $x = isset($sa[$i]) ? $sa[$i] : '0';
            $y = isset($sb[$i]) ? $sb[$i] : '0';

            if (is_numeric($x) && is_numeric($y)) {
                $xn = (float) $x;
                $yn = (float) $y;
                if ($xn < $yn) {
                    return -1;
                }
                if ($xn > $yn) {
                    return 1;
                }
                continue;
            }

            $cmp = strnatcasecmp($x, $y);
            if ($cmp !== 0) {
                return $cmp < 0 ? -1 : 1;
            }
        }

        return 0;
    }
}

if (!function_exists('lm_version_is_newer')) {
    /**
     * @param string $candidate
     * @param string $current
     * @return bool
     */
    function lm_version_is_newer($candidate, $current)
    {
        return lm_version_compare($candidate, $current) > 0;
    }
}

if (!function_exists('lm_bump_version')) {
    /**
     * Increment a version label while preserving zero padding ("7.02" → "7.03").
     *
     * @param string $version
     * @param string $mode major|minor|patch
     * @return string
     */
    function lm_bump_version($version, $mode = 'patch')
    {
        $mode = in_array($mode, array('major', 'minor', 'patch'), true) ? $mode : 'patch';
        $clean = lm_version_clean($version);

        if ($clean === '') {
            return $mode === 'major' ? '1.0.0' : '1.0.0';
        }

        $parts = explode('.', $clean);

        if ($mode === 'major') {
            $index = 0;
        } elseif ($mode === 'minor') {
            if (count($parts) < 2) {
                $parts[] = '0';
            }
            // 7.02 style (two segments): minor and patch both move the last
            // number. Three or more segments get a classic minor bump.
            $index = max(1, count($parts) - 2);
        } else {
            $index = count($parts) - 1;
        }

        $original = $parts[$index];
        $digits = preg_replace('/\D/', '', $original);
        $width = strlen($digits);
        $number = $digits === '' ? 0 : (int) $digits;
        $number++;

        if ($width > 0) {
            $parts[$index] = str_pad((string) $number, $width, '0', STR_PAD_LEFT);
        } else {
            $parts[$index] = (string) $number;
        }

        if ($mode !== 'patch') {
            for ($i = $index + 1; $i < count($parts); $i++) {
                $w = strlen(preg_replace('/\D/', '', $parts[$i]));
                $parts[$i] = $w > 0 ? str_pad('0', $w, '0', STR_PAD_LEFT) : '0';
            }
        }

        return implode('.', $parts);
    }
}

if (!function_exists('lm_latest_update_for_tier')) {
    /**
     * Newest released update for a tier (by version, then release date).
     *
     * @param PDO $pdo
     * @param int $tier_id
     * @param bool $released_only
     * @return array|null
     */
    function lm_latest_update_for_tier(PDO $pdo, $tier_id, $released_only = true)
    {
        $sql = "SELECT * FROM script_updates WHERE tier_id = ?";
        if ($released_only) {
            $sql .= " AND is_released = 1";
        }
        $sql .= " ORDER BY release_date DESC, id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array((int) $tier_id));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $latest = null;
        foreach ($rows as $row) {
            if ($latest === null || lm_version_compare($row['version_number'], $latest['version_number']) > 0) {
                $latest = $row;
            }
        }

        return $latest;
    }
}

if (!function_exists('lm_next_version')) {
    /**
     * Compute the next version number for a tier, based on what is already published.
     *
     * @param PDO    $pdo
     * @param int    $tier_id
     * @param string $mode      major|minor|patch
     * @param string $base      Optional base version (overrides the stored latest).
     * @return string
     */
    function lm_next_version(PDO $pdo, $tier_id, $mode = 'patch', $base = '')
    {
        if ($base === '') {
            $latest = lm_latest_update_for_tier($pdo, $tier_id, false);
            $base = $latest ? $latest['version_number'] : '';
        }

        if (lm_version_clean($base) === '') {
            return '1.0.0';
        }

        return lm_bump_version($base, $mode);
    }
}

/* -------------------------------------------------------------------------
 * ZIP helpers
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_zip_available')) {
    /**
     * ZIP handling works without the zip extension (pure-PHP engine).
     *
     * @return bool
     */
    function lm_zip_available()
    {
        return lm_zip_supported();
    }
}

if (!function_exists('lm_zip_inspect')) {
    /**
     * Inspect a ZIP without extracting it.
     *
     * @param string $path
     * @return array{ok:bool,error:string,files:int,entries:array,has_manifest:bool,manifest:array|null,uncompressed:int,unsafe:array}
     */
    function lm_zip_inspect($path)
    {
        $result = array(
            'ok' => false,
            'error' => '',
            'files' => 0,
            'entries' => array(),
            'has_manifest' => false,
            'manifest' => null,
            'uncompressed' => 0,
            'unsafe' => array(),
        );

        if (!lm_zip_available()) {
            $result['error'] = 'ZIP support is unavailable: the zlib extension is not enabled.';
            return $result;
        }
        if (!is_file($path)) {
            $result['error'] = 'ZIP file not found.';
            return $result;
        }

        $entries = lm_zip_entries($path);
        if ($entries === false) {
            $result['error'] = 'Could not read the ZIP archive (corrupt, ZIP64, or not a ZIP file).';
            return $result;
        }

        $manifestName = null;
        foreach ($entries as $entry) {
            $name = str_replace('\\', '/', $entry['name']);
            if (substr($name, -1) === '/') {
                continue;
            }
            if (strpos($name, '__MACOSX/') === 0 || basename($name) === '.DS_Store') {
                continue;
            }
            if (!lm_zip_is_safe_entry($name)) {
                $result['unsafe'][] = $name;
                continue;
            }

            $result['files']++;
            $result['uncompressed'] += (int) $entry['size'];

            if (count($result['entries']) < 500) {
                $result['entries'][] = $name;
            }

            if (basename($name) === 'manifest.json' && substr_count($name, '/') <= 1) {
                $result['has_manifest'] = true;
                $manifestName = $name;
            }
        }

        if (!empty($result['unsafe'])) {
            $result['error'] = 'The ZIP contains unsafe paths (path traversal) and was rejected.';
            return $result;
        }

        if ($manifestName !== null) {
            $found = lm_zip_find_entry($entries, $manifestName);
            if ($found) {
                $raw = lm_zip_read_entry($path, $found);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $result['manifest'] = $decoded;
                    }
                }
            }
        }

        if ($result['files'] === 0) {
            $result['error'] = 'The ZIP archive is empty.';
            return $result;
        }

        $result['ok'] = true;
        return $result;
    }
}

if (!function_exists('lm_zip_inject_manifest')) {
    /**
     * Add (or replace) manifest.json at the ZIP root.
     *
     * The archive is rewritten in place through a temporary file, so a broken
     * write can never destroy the uploaded package.
     *
     * @param string $zipPath
     * @param array  $manifest
     * @param bool   $replace
     * @return bool
     */
    function lm_zip_inject_manifest($zipPath, array $manifest, $replace = true)
    {
        if (!lm_zip_available() || !is_file($zipPath)) {
            return false;
        }

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $entries = lm_zip_entries($zipPath);
        if ($entries === false) {
            return false;
        }

        // Drop nested manifests so the generated one is authoritative.
        $remove = array();
        if ($replace) {
            foreach ($entries as $entry) {
                $name = str_replace('\\', '/', $entry['name']);
                // Only the manifest at the archive root belongs to LM — never
                // touch an app's own public/manifest.json (PWA) file.
                if (basename($name) === 'manifest.json' && strpos(lm_zip_clean_name($name), '/') === false) {
                    $remove[] = $name;
                }
            }
        }

        $temp = $zipPath . '.tmp' . bin2hex(substr(md5(uniqid('', true)), 0, 6));
        $copied = lm_zip_copy($zipPath, $temp, array(
            'remove' => $remove,
            'add' => array('manifest.json' => $json),
        ));

        if (!$copied) {
            @unlink($temp);
            return false;
        }

        if (!@rename($temp, $zipPath)) {
            @unlink($temp);
            return false;
        }

        @chmod($zipPath, 0644);
        return true;
    }
}

if (!function_exists('lm_zip_rewrite')) {
    /**
     * Re-pack a downloaded archive into an update package.
     *
     * Strips GitHub's wrapper folder, keeps only the selected files and
     * re-roots the package so the selected folder's contents land at the ZIP
     * root (that is what the target script expects).
     *
     * @param string $source
     * @param string $destination
     * @param bool   $stripTopLevel  Remove the "owner-repo-sha/" wrapper
     * @param string $onlyUnder      Legacy single sub-folder (kept + stripped)
     * @param int    $maxFiles
     * @param int    $maxBytes
     * @param array  $options {
     *     files:    array  Exact repo-relative paths to include (checked boxes)
     *     root:     string Folder to strip after filtering (auto-detected)
     *     manifest: array  Written as manifest.json in the same pass, so a
     *                      pull costs one copy of the archive instead of two
     * }
     * @return array{ok:bool,error:string,files:int,bytes:int,root:string,skipped:int}
     */
    function lm_zip_rewrite($source, $destination, $stripTopLevel = true, $onlyUnder = '', $maxFiles = 25000, $maxBytes = 524288000, array $options = array())
    {
        $out = array('ok' => false, 'error' => '', 'files' => 0, 'bytes' => 0, 'root' => '', 'skipped' => 0);

        if (!lm_zip_available()) {
            $out['error'] = 'ZIP support is unavailable: the zlib extension is not enabled.';
            return $out;
        }
        if (!is_file($source)) {
            $out['error'] = 'The downloaded archive could not be found.';
            return $out;
        }

        $entries = lm_zip_entries($source);
        if ($entries === false) {
            $out['error'] = 'Could not read the downloaded archive (corrupt, ZIP64, or not a ZIP).';
            return $out;
        }
        if (count($entries) > $maxFiles * 4) {
            $out['error'] = 'Archive contains more than ' . ($maxFiles * 4) . ' entries.';
            return $out;
        }

        // Which top-level folders exist? A GitHub zipball has exactly one
        // ("owner-repo-sha"); a hand-made archive may have several, in which
        // case stripping would lose files, so it is left alone.
        $topLevels = array();
        foreach ($entries as $entry) {
            $name = lm_zip_clean_name($entry['name']);
            $slash = strpos($name, '/');
            if ($slash !== false) {
                $topLevels[substr($name, 0, $slash)] = true;
            }
        }

        $wrapper = '';
        if ($stripTopLevel && count($topLevels) === 1) {
            $candidate = (string) key($topLevels);
            $prefix = $candidate . '/';
            $allUnder = true;
            foreach ($entries as $entry) {
                $check = lm_zip_clean_name($entry['name']);
                if (substr($check, -1) === '/' || strpos($check, '__MACOSX/') === 0) {
                    continue;
                }
                if (strpos($check, $prefix) !== 0) {
                    $allUnder = false;
                    break;
                }
            }
            if ($allUnder) {
                $wrapper = $candidate;
            }
        }

        // An explicit file list wins; otherwise fall back to the legacy
        // "only under this folder" option.
        $files = isset($options['files']) && is_array($options['files']) ? $options['files'] : array();
        $root = isset($options['root']) ? trim(str_replace('\\', '/', (string) $options['root']), '/') : '';

        if (empty($files) && trim((string) $onlyUnder) !== '') {
            $root = trim(str_replace('\\', '/', (string) $onlyUnder), '/');
        }

        // If a file list is given without an explicit root, derive the root
        // from the common parent directory of those files.
        if (!empty($files) && $root === '') {
            $root = lm_zip_common_root($files);
        }

        $include = array();
        if (!empty($files)) {
            foreach ($files as $file) {
                $clean = lm_zip_clean_name($file);
                if ($clean !== '' && lm_zip_is_safe_entry($clean)) {
                    $include[] = $clean;
                }
            }
        }

        // Manifest in the same pass: this used to be two full copies of the
        // archive (rewrite, then inject), which doubled the time of a pull.
        $add = array();
        $remove = array();
        if (isset($options['manifest']) && is_array($options['manifest'])) {
            $json = json_encode($options['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $out['error'] = 'Could not encode manifest.json.';
                return $out;
            }
            $add['manifest.json'] = $json;
            $remove[] = 'manifest.json';
        }

        $result = lm_zip_copy($source, $destination, array(
            'wrapper' => $wrapper,
            'include' => $include,
            'root' => $root,
            'add' => $add,
            'remove' => $remove,
        ));

        if (!$result) {
            @unlink($destination);
            $out['error'] = 'Could not write the normalised archive.';
            return $out;
        }

        if ($result['count'] < 1) {
            @unlink($destination);
            if (!empty($include)) {
                $out['error'] = 'None of the selected files were found in the repository archive. Refresh the file list and try again.';
            } elseif ($root !== '') {
                $out['error'] = 'No files were found below "' . $root . '" in the repository.';
            } else {
                $out['error'] = 'The repository archive appears to be empty.';
            }
            return $out;
        }

        if ($result['count'] > $maxFiles) {
            @unlink($destination);
            $out['error'] = 'The selection contains ' . $result['count'] . ' files — the limit is ' . $maxFiles . '. Select fewer folders.';
            return $out;
        }

        if ($result['bytes'] > $maxBytes) {
            @unlink($destination);
            $out['error'] = 'The unpacked package exceeds ' . lm_format_bytes($maxBytes) . '. Select fewer files.';
            return $out;
        }

        @chmod($destination, 0644);

        $out['ok'] = true;
        $out['files'] = (int) $result['count'];
        $out['bytes'] = (int) $result['bytes'];
        $out['skipped'] = (int) $result['skipped'];
        $out['root'] = $root;
        return $out;
    }
}

if (!function_exists('lm_zip_common_root')) {
    /**
     * Deepest directory that contains every given path.
     *
     * lm_zip_common_root(array('LM/a.php', 'LM/b/c.php')) === 'LM'
     * lm_zip_common_root(array('LM/a.php'))               === 'LM'
     * lm_zip_common_root(array('LM/a.php', 'VTU/x.php'))  === ''
     *
     * @param array $paths
     * @return string
     */
    function lm_zip_common_root(array $paths)
    {
        $common = null;

        foreach ($paths as $path) {
            $path = lm_zip_clean_name($path);
            if ($path === '') {
                continue;
            }

            $slash = strrpos($path, '/');
            $dir = $slash === false ? '' : substr($path, 0, $slash);

            if ($common === null) {
                $common = $dir;
                continue;
            }
            if ($common === '') {
                return '';
            }

            $a = explode('/', $common);
            $b = $dir === '' ? array() : explode('/', $dir);
            $shared = array();

            $limit = min(count($a), count($b));
            for ($i = 0; $i < $limit; $i++) {
                if ($a[$i] !== $b[$i]) {
                    break;
                }
                $shared[] = $a[$i];
            }

            $common = implode('/', $shared);
        }

        return $common === null ? '' : $common;
    }
}

if (!function_exists('lm_resolve_update_path')) {
    /**
     * Locate a stored update ZIP even if its absolute path changed
     * (server migration, folder rename, legacy relative path…).
     *
     * @param string      $storedPath
     * @param string|null $tierCode
     * @return array{path:string|null,exists:bool,candidates:string[]}
     */
    function lm_resolve_update_path($storedPath, $tierCode = null)
    {
        $candidates = array();
        $storedPath = trim((string) $storedPath);

        if ($storedPath !== '') {
            $candidates[] = $storedPath;

            if ($storedPath[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $storedPath)) {
                $candidates[] = lm_project_root() . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');
                $candidates[] = lm_lm_dir() . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');
            }

            $base = basename($storedPath);
            if ($base !== '' && $base !== '.' && $base !== '..') {
                $roots = array(lm_updates_root());
                if ($tierCode !== null && $tierCode !== '') {
                    array_unshift($roots, lm_tier_storage_dir($tierCode, false));
                }
                foreach ($roots as $root) {
                    $candidates[] = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $base;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return array('path' => $candidate, 'exists' => true, 'candidates' => $candidates);
            }
        }

        // Last resort: walk tier folders (2 levels deep) looking for the basename.
        $base = basename($storedPath);
        if ($base !== '' && is_dir(lm_updates_root())) {
            $tierDirs = glob(lm_updates_root() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if (is_array($tierDirs)) {
                foreach ($tierDirs as $dir) {
                    $candidate = $dir . DIRECTORY_SEPARATOR . $base;
                    $candidates[] = $candidate;
                    if (is_file($candidate)) {
                        return array('path' => $candidate, 'exists' => true, 'candidates' => $candidates);
                    }
                }
            }
        }

        return array('path' => null, 'exists' => false, 'candidates' => $candidates);
    }
}

/* -------------------------------------------------------------------------
 * Publish / delete
 * ---------------------------------------------------------------------- */

if (!function_exists('lm_publish_update')) {
    /**
     * Register a stored ZIP as the released update for a tier.
     *
     * @param PDO    $pdo
     * @param array  $tier       script_tiers row
     * @param string $version
     * @param string $zipPath
     * @param string $changelog
     * @param array  $options    source, commit_sha, changelog_format, notify, notify_delay_minutes, actor, changelog_text
     * @return array{ok:bool,error:string,id:int,version:string,created:bool,zip_path:string,checksum:string,file_size:int}
     */
    function lm_publish_update(PDO $pdo, array $tier, $version, $zipPath, $changelog, array $options = array())
    {
        $result = array(
            'ok' => false,
            'error' => '',
            'id' => 0,
            'version' => '',
            'created' => false,
            'zip_path' => '',
            'checksum' => '',
            'file_size' => 0,
        );

        $version = lm_version_clean($version);
        if ($version === '') {
            $result['error'] = 'A valid version number is required (e.g. 7.03 or 1.4.0).';
            return $result;
        }
        if (strlen($version) > 20) {
            $result['error'] = 'Version number is too long (max 20 characters).';
            return $result;
        }
        if (!is_file($zipPath)) {
            $result['error'] = 'Update archive not found at: ' . $zipPath;
            return $result;
        }
        if (isset($tier['is_active']) && (int) $tier['is_active'] === 0) {
            $result['error'] = 'This project is archived. Restore it under Script Projects before publishing.';
            return $result;
        }

        $checksum = hash_file('sha256', $zipPath);
        $size = (int) filesize($zipPath);
        $source = isset($options['source']) ? substr(preg_replace('/[^a-z_]/', '', strtolower($options['source'])), 0, 20) : 'manual';
        if ($source === '') {
            $source = 'manual';
        }
        $commitSha = isset($options['commit_sha']) ? preg_replace('/[^a-f0-9]/i', '', (string) $options['commit_sha']) : '';
        $changelogFormat = (isset($options['changelog_format']) && $options['changelog_format'] === 'text') ? 'text' : 'html';
        $changelogText = isset($options['changelog_text']) ? (string) $options['changelog_text'] : '';
        $actor = isset($options['actor']) ? substr((string) $options['actor'], 0, 100) : 'admin';

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM script_updates WHERE tier_id = ? AND version_number = ? FOR UPDATE");
            $stmt->execute(array((int) $tier['id'], $version));
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $pdo->prepare(
                    "UPDATE script_updates
                     SET zip_path = ?, checksum = ?, changelog = ?, changelog_text = ?, changelog_format = ?,
                         file_size = ?, source = ?, commit_sha = ?, is_released = 1,
                         release_date = CURRENT_TIMESTAMP
                     WHERE id = ?"
                );
                $stmt->execute(array(
                    $zipPath, $checksum, $changelog, $changelogText, $changelogFormat,
                    $size, $source, $commitSha !== '' ? $commitSha : null, (int) $existing['id'],
                ));
                $updateId = (int) $existing['id'];
                $created = false;
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO script_updates
                        (tier_id, version_number, zip_path, checksum, changelog, changelog_text, changelog_format,
                         file_size, source, commit_sha, is_released, release_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP)"
                );
                $stmt->execute(array(
                    (int) $tier['id'], $version, $zipPath, $checksum, $changelog, $changelogText,
                    $changelogFormat, $size, $source, $commitSha !== '' ? $commitSha : null,
                ));
                $updateId = (int) $pdo->lastInsertId();
                $created = true;
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['error'] = 'Database error while publishing the update: ' . $e->getMessage();
            lm_log('updates', $result['error']);
            return $result;
        }

        $result['ok'] = true;
        $result['id'] = $updateId;
        $result['version'] = $version;
        $result['created'] = $created;
        $result['zip_path'] = $zipPath;
        $result['checksum'] = $checksum;
        $result['file_size'] = $size;

        lm_log('updates', ($created ? 'Published' : 'Re-published') . ' v' . $version . ' for ' . $tier['tier_code'] . ' (' . $source . ') → ' . $zipPath);

        // Notify customers unless explicitly disabled.
        $notify = array_key_exists('notify', $options) ? (bool) $options['notify'] : true;
        if ($notify) {
            require_once __DIR__ . '/notifications.php';
            $delay = isset($options['notify_delay_minutes']) ? (int) $options['notify_delay_minutes'] : null;
            $queue = lm_queue_update_notifications($pdo, $updateId, array('delay_minutes' => $delay));
            $result['notification_queue'] = $queue;
        }

        return $result;
    }
}

if (!function_exists('lm_delete_update')) {
    /**
     * Delete an update and REMOVE its files from the cPanel file manager.
     *
     * @param PDO $pdo
     * @param int $updateId
     * @return array{ok:bool,error:string,version:string,removed:string[],missing:string[],notifications_deleted:int}
     */
    function lm_delete_update(PDO $pdo, $updateId)
    {
        $report = array(
            'ok' => false,
            'error' => '',
            'version' => '',
            'removed' => array(),
            'missing' => array(),
            'notifications_deleted' => 0,
        );

        $stmt = $pdo->prepare(
            "SELECT u.*, t.tier_code FROM script_updates u
             LEFT JOIN script_tiers t ON u.tier_id = t.id
             WHERE u.id = ?"
        );
        $stmt->execute(array((int) $updateId));
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            $report['error'] = 'Update record not found.';
            return $report;
        }

        $report['version'] = $update['version_number'];
        $tierCode = $update['tier_code'] ?? null;

        $resolved = lm_resolve_update_path($update['zip_path'], $tierCode);
        $zipPath = $resolved['path'];

        if ($zipPath !== null) {
            if (@unlink($zipPath)) {
                $report['removed'][] = $zipPath;
            } else {
                $report['error'] = 'Could not delete ' . $zipPath . ' (permission denied).';
                lm_log('updates', 'Failed to unlink ' . $zipPath);
                return $report;
            }
            $report['missing'] = array_values(array_diff($resolved['candidates'], array($zipPath)));
        } else {
            $report['missing'] = $resolved['candidates'];
        }

        // Remove extracted / staging folders created for this version.
        $searchDirs = array();
        if ($zipPath !== null) {
            $searchDirs[] = dirname($zipPath);
        }
        if ($tierCode) {
            $searchDirs[] = lm_tier_storage_dir($tierCode, false);
        }

        $needles = array(
            $update['version_number'],
            'update_' . $update['version_number'],
            'extracted_' . (int) $update['id'],
            'build_' . (int) $update['id'],
        );

        foreach (array_unique($searchDirs) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $items = @scandir($dir);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === 'index.php') {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $item;
                $matched = false;
                foreach ($needles as $needle) {
                    if ($needle !== '' && strpos($item, $needle) !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                if (is_dir($full)) {
                    if (lm_remove_tree($full)) {
                        $report['removed'][] = $full;
                    }
                } elseif (is_file($full) && @unlink($full)) {
                    $report['removed'][] = $full;
                }
            }
        }

        // Staging folder used by GitHub pulls.
        $work = lm_storage_dir() . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'update_' . (int) $update['id'];
        if (is_dir($work) && lm_remove_tree($work)) {
            $report['removed'][] = $work;
        }

        try {
            $del = $pdo->prepare("DELETE FROM update_notifications WHERE update_id = ?");
            $del->execute(array((int) $update['id']));
            $report['notifications_deleted'] = $del->rowCount();

            $pdo->prepare("DELETE FROM download_tokens WHERE update_id = ?")->execute(array((int) $update['id']));
            $pdo->prepare("DELETE FROM script_updates WHERE id = ?")->execute(array((int) $update['id']));
        } catch (PDOException $e) {
            $report['error'] = 'Files were removed but the database record could not be deleted: ' . $e->getMessage();
            return $report;
        }

        $report['ok'] = true;
        lm_log('updates', 'Deleted update v' . $report['version'] . ' (' . (int) $updateId . '); files removed: ' . implode(', ', $report['removed']));

        return $report;
    }
}

if (!function_exists('lm_remove_tree')) {
    /**
     * Recursively delete a directory.
     *
     * @param string $dir
     * @return bool
     */
    function lm_remove_tree($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                lm_remove_tree($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
}

if (!function_exists('lm_cleanup_old_updates')) {
    /**
     * Keep only the newest update per tier and physically delete the rest.
     *
     * @param PDO      $pdo
     * @param int|null $tierId  Limit to one tier (null = all).
     * @return array{ok:bool,deleted:int,files:int,removed:array,errors:array}
     */
    function lm_cleanup_old_updates(PDO $pdo, $tierId = null)
    {
        $result = array('ok' => true, 'deleted' => 0, 'files' => 0, 'removed' => array(), 'errors' => array());

        $sql = "SELECT u.*, t.tier_code FROM script_updates u LEFT JOIN script_tiers t ON u.tier_id = t.id";
        $params = array();
        if ($tierId !== null) {
            $sql .= " WHERE u.tier_id = ?";
            $params[] = (int) $tierId;
        }
        $sql .= " ORDER BY u.tier_id ASC, u.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $latestByTier = array();
        foreach ($rows as $row) {
            $tier = (int) $row['tier_id'];
            if (!isset($latestByTier[$tier]) || lm_version_compare($row['version_number'], $latestByTier[$tier]['version_number']) > 0) {
                $latestByTier[$tier] = $row;
            }
        }

        foreach ($rows as $row) {
            $tier = (int) $row['tier_id'];
            if (isset($latestByTier[$tier]) && (int) $latestByTier[$tier]['id'] === (int) $row['id']) {
                continue;
            }

            $delete = lm_delete_update($pdo, (int) $row['id']);
            if ($delete['ok']) {
                $result['deleted']++;
                $result['files'] += count($delete['removed']);
                foreach ($delete['removed'] as $path) {
                    $result['removed'][] = $path;
                }
            } else {
                $result['ok'] = false;
                $result['errors'][] = 'v' . $row['version_number'] . ': ' . $delete['error'];
            }
        }

        // Tidy up empty tier folders.
        foreach ($latestByTier as $row) {
            if (!empty($row['tier_code'])) {
                $dir = lm_tier_storage_dir($row['tier_code'], false);
                if (is_dir($dir)) {
                    $left = array_values(array_diff(@scandir($dir) ?: array(), array('.', '..', 'index.php')));
                    if (empty($left)) {
                        @rmdir($dir);
                    }
                }
            }
        }

        lm_log('updates', 'Cleanup: deleted ' . $result['deleted'] . ' update(s), ' . $result['files'] . ' file(s).');
        return $result;
    }
}

if (!function_exists('lm_tier_counts')) {
    /**
     * Number of active licenses per tier (used by the project/update screens).
     *
     * @param PDO $pdo
     * @param int $tierId
     * @return int
     */
    function lm_tier_license_count(PDO $pdo, $tierId)
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE tier_id = ? AND status = 'active'");
            $stmt->execute(array((int) $tierId));
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}
