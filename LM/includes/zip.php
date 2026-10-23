<?php
/**
 * Pure-PHP ZIP reader / writer.
 *
 * The License Manager rewrites update packages (strip the GitHub zipball
 * wrapper folder, inject manifest.json). Some shared hosts do not compile
 * the zip extension, so every operation here works directly on the ZIP
 * structures and only needs zlib (gzinflate/gzdeflate), which is core.
 *
 * Entries are copied in compressed form, so a 100 MB package never has to be
 * held in memory.
 */

if (!defined('LM_ZIP_MAX_ENTRIES')) {
    define('LM_ZIP_MAX_ENTRIES', 30000);
}

if (!defined('LM_ZIP_MAX_UNCOMPRESSED')) {
    define('LM_ZIP_MAX_UNCOMPRESSED', 1073741824); // 1 GB
}

/**
 * Whether ZIP handling is possible on this host.
 *
 * @return bool
 */
function lm_zip_supported()
{
    return function_exists('gzdeflate') && function_exists('gzinflate');
}

/**
 * Parse the central directory of a ZIP file.
 *
 * @param string $file
 * @return array|false List of entries: name, size, compressed, method, crc, offset
 */
function lm_zip_entries($file)
{
    if (!is_file($file) || !is_readable($file)) {
        return false;
    }

    $size = filesize($file);
    if ($size < 22) {
        return false;
    }

    $fp = @fopen($file, 'rb');
    if (!$fp) {
        return false;
    }

    $tailLength = min($size, 66560);
    fseek($fp, $size - $tailLength);
    $tail = fread($fp, $tailLength);
    $pos = strrpos($tail, "PK\x05\x06");
    if ($pos === false) {
        fclose($fp);
        return false;
    }

    $eocd = unpack('vdisk/vcddisk/vdiskentries/ventries/Vcdsize/Vcdoffset/vcommentlen', substr($tail, $pos + 4, 18));
    if (!$eocd) {
        fclose($fp);
        return false;
    }

    if ($eocd['entries'] === 0xFFFF || $eocd['cdoffset'] === 0xFFFFFFFF || $eocd['cdsize'] === 0xFFFFFFFF) {
        // ZIP64 — practically never used for source packages.
        fclose($fp);
        return false;
    }

    $count = (int) $eocd['entries'];
    if ($count > LM_ZIP_MAX_ENTRIES) {
        fclose($fp);
        return false;
    }

    fseek($fp, (int) $eocd['cdoffset']);
    $directory = fread($fp, (int) $eocd['cdsize'] + 22);
    fclose($fp);

    if ($directory === false || strlen($directory) < 46) {
        return false;
    }

    $entries = array();
    $p = 0;
    $length = strlen($directory);

    for ($i = 0; $i < $count && $p + 46 <= $length; $i++) {
        if (substr($directory, $p, 4) !== "PK\x01\x02") {
            break;
        }

        $h = unpack(
            'vmadeby/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Voffset',
            substr($directory, $p + 4, 42)
        );
        if (!$h) {
            break;
        }

        $name = substr($directory, $p + 46, $h['namelen']);
        $entries[] = array(
            'name' => $name,
            'size' => (int) $h['uncompressed'],
            'compressed' => (int) $h['compressed'],
            'method' => (int) $h['method'],
            'crc' => $h['crc'],
            'offset' => (int) $h['offset'],
        );

        $p += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
    }

    return $entries;
}

/**
 * Reject absolute paths, parent traversal, NTFS streams and control bytes.
 *
 * @param string $name
 * @return bool
 */
function lm_zip_is_safe_entry($name)
{
    $name = (string) $name;
    if ($name === '' || $name === false) {
        return false;
    }
    if (strpos($name, "\0") !== false) {
        return false;
    }
    $normalised = str_replace('\\', '/', $name);
    if ($normalised[0] === '/') {
        return false;
    }
    if (preg_match('/^[A-Za-z]:/', $normalised)) {
        return false;
    }
    foreach (explode('/', $normalised) as $segment) {
        if ($segment === '..') {
            return false;
        }
    }
    return true;
}

/**
 * Normalise an entry name to a clean relative path (no leading ./ or /).
 *
 * @param string $name
 * @return string
 */
function lm_zip_clean_name($name)
{
    $name = str_replace('\\', '/', (string) $name);
    $name = preg_replace('#/+#', '/', $name);
    $name = preg_replace('#^\./+#', '', $name);
    return ltrim($name, '/');
}

/**
 * Read a single entry into memory.
 *
 * @param string $file
 * @param array  $entry Entry from lm_zip_entries()
 * @return string|false
 */
function lm_zip_read_entry($file, array $entry)
{
    $fp = @fopen($file, 'rb');
    if (!$fp) {
        return false;
    }

    fseek($fp, (int) $entry['offset']);
    $local = fread($fp, 30);
    if ($local === false || strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
        fclose($fp);
        return false;
    }

    $h = unpack('vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnamelen/vextralen', substr($local, 4, 26));
    if (!$h) {
        fclose($fp);
        return false;
    }

    $dataOffset = (int) $entry['offset'] + 30 + $h['namelen'] + $h['extralen'];
    fseek($fp, $dataOffset);

    $compressedSize = (int) $entry['compressed'];
    $raw = '';
    $remaining = $compressedSize;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(1048576, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);

    if ($entry['method'] === 0) {
        return $raw;
    }
    if ($entry['method'] === 8) {
        $inflated = @gzinflate($raw);
        return $inflated === false ? false : $inflated;
    }

    return false; // bzip2 / lzma / encrypted entries are not supported.
}

/**
 * Locate an entry by name (case-sensitive first, then case-insensitive).
 *
 * @param array  $entries
 * @param string $name
 * @return array|null
 */
function lm_zip_find_entry(array $entries, $name)
{
    $wanted = lm_zip_clean_name($name);
    foreach ($entries as $entry) {
        if (lm_zip_clean_name($entry['name']) === $wanted) {
            return $entry;
        }
    }
    foreach ($entries as $entry) {
        if (strcasecmp(lm_zip_clean_name($entry['name']), $wanted) === 0) {
            return $entry;
        }
    }
    return null;
}

/**
 * DOS timestamp used inside ZIP headers.
 *
 * @param int|null $timestamp
 * @return array{time:int,date:int}
 */
function lm_zip_dos_time($timestamp = null)
{
    $timestamp = $timestamp === null ? time() : (int) $timestamp;
    $parts = getdate($timestamp);
    $year = max(1980, min(2107, (int) $parts['year']));

    return array(
        'time' => (($parts['hours'] & 0x1F) << 11) | (($parts['minutes'] & 0x3F) << 5) | (($parts['seconds'] >> 1) & 0x1F),
        'date' => (($year - 1980) << 9) | (($parts['mon'] & 0x0F) << 5) | ($parts['mday'] & 0x1F),
    );
}

/**
 * Copy $bytes from $in to $out in chunks.
 *
 * @param resource $in
 * @param resource $out
 * @param int      $bytes
 * @return int Bytes copied
 */
function lm_zip_stream_copy($in, $out, $bytes)
{
    $copied = 0;
    $remaining = (int) $bytes;
    while ($remaining > 0 && !feof($in)) {
        $chunk = fread($in, min(1048576, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        fwrite($out, $chunk);
        $copied += strlen($chunk);
        $remaining -= strlen($chunk);
    }
    return $copied;
}

/**
 * Write a local + central header pair for one entry.
 *
 * @param resource $out
 * @param string   $name
 * @param int      $method
 * @param string|int $crc
 * @param int      $compressedSize
 * @param int      $size
 * @param int      $offset
 * @param array    $dos
 * @param string   $central Central directory accumulator (by reference)
 */
function lm_zip_write_header($out, $name, $method, $crc, $compressedSize, $size, $offset, array $dos, &$central)
{
    $flags = 0x0800; // UTF-8 names
    $nameLength = strlen($name);

    fwrite($out, "PK\x03\x04" . pack(
        'vvvvvVVVvv',
        20,
        $flags,
        $method,
        $dos['time'],
        $dos['date'],
        $crc,
        $compressedSize,
        $size,
        $nameLength,
        0
    ) . $name);

    $central .= "PK\x01\x02" . pack(
        'vvvvvvVVVvvvvvVV',
        0x031E,
        20,
        $flags,
        $method,
        $dos['time'],
        $dos['date'],
        $crc,
        $compressedSize,
        $size,
        $nameLength,
        0,
        0,
        0,
        0,
        0100644 << 16,
        $offset
    ) . $name;
}

/**
 * Rewrite a ZIP, optionally removing a wrapper folder, keeping a sub folder
 * and adding/replacing in-memory entries.
 *
 * @param string $source
 * @param string $destination
 * @param array  $options {
 *     wrapper: string  GitHub "owner-repo-sha" folder to strip (others dropped)
 *     include: array   Exact archive paths to keep (empty = keep everything)
 *     root:    string  Prefix removed after filtering, so its contents land at the root
 *     add:     array   name => contents to add/replace
 *     remove:  array   list of names to drop
 * }
 * @return array|false array{count:int, skipped:int, bytes:int}
 */
function lm_zip_copy($source, $destination, array $options = array())
{
    $entries = lm_zip_entries($source);
    if ($entries === false) {
        return false;
    }

    // wrapper — GitHub's "owner-repo-sha/" folder. Files outside it are dropped.
    $wrapper = isset($options['wrapper']) ? trim((string) $options['wrapper'], '/') : '';
    // root — prefix removed after filtering (e.g. "LM" so its contents sit at the ZIP root).
    $root = isset($options['root']) ? trim((string) $options['root'], '/') : '';
    // include — exact archive paths to keep (folder selection is expanded by the caller).
    $include = isset($options['include']) && is_array($options['include']) ? $options['include'] : array();
    $includeSet = array();
    foreach ($include as $name) {
        $clean = lm_zip_clean_name($name);
        if ($clean !== '') {
            $includeSet[$clean] = true;
        }
    }
    $add = isset($options['add']) && is_array($options['add']) ? $options['add'] : array();
    $remove = isset($options['remove']) && is_array($options['remove']) ? $options['remove'] : array();
    $removeNames = array();
    foreach ($remove as $name) {
        $removeNames[lm_zip_clean_name($name)] = true;
    }

    $in = @fopen($source, 'rb');
    $out = @fopen($destination, 'wb');
    if (!$in || !$out) {
        if ($in) {
            fclose($in);
        }
        if ($out) {
            fclose($out);
        }
        return false;
    }

    $dos = lm_zip_dos_time();
    $central = '';
    $offset = 0;
    $count = 0;
    $skipped = 0;
    $totalBytes = 0;
    $addedNames = array();

    foreach ($entries as $entry) {
        $name = lm_zip_clean_name($entry['name']);
        if ($name === '' || substr($name, -1) === '/') {
            continue;
        }
        if (strpos($name, '__MACOSX/') === 0) {
            continue;
        }
        if (!lm_zip_is_safe_entry($name)) {
            $skipped++;
            continue;
        }
        // 1. Drop GitHub's wrapper folder (and anything beside it).
        if ($wrapper !== '') {
            $prefix = $wrapper . '/';
            if (strpos($name, $prefix) !== 0) {
                continue;
            }
            $name = substr($name, strlen($prefix));
        }
        // 2. Keep only the selected files (repo-relative, post-wrapper).
        if (!empty($includeSet) && !isset($includeSet[$name])) {
            continue;
        }
        // 3. Re-root: strip the selected folder so its contents land at the ZIP root.
        if ($root !== '') {
            $prefix = $root . '/';
            if (strpos($name, $prefix) !== 0) {
                continue;
            }
            $name = substr($name, strlen($prefix));
        }
        if ($name === '' || isset($removeNames[$name])) {
            continue;
        }

        $inMemory = array_key_exists($name, $add);

        if (!$inMemory) {
            $readOffset = (int) $entry['offset'];
            fseek($in, $readOffset);
            $local = fread($in, 30);
            if ($local === false || strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
                $skipped++;
                continue;
            }
            $h = unpack('vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnamelen/vextralen', substr($local, 4, 26));
            if (!$h) {
                $skipped++;
                continue;
            }
            $dataOffset = $readOffset + 30 + $h['namelen'] + $h['extralen'];
            fseek($in, $dataOffset);

            lm_zip_write_header($out, $name, $entry['method'], $entry['crc'], $entry['compressed'], $entry['size'], $offset, $dos, $central);
            $offset += 30 + strlen($name);
            $copied = lm_zip_stream_copy($in, $out, $entry['compressed']);
            $offset += $copied;
            $totalBytes += $entry['size'];
            if ($copied !== $entry['compressed']) {
                // Truncated source — abort to avoid shipping a corrupt package.
                fclose($in);
                fclose($out);
                @unlink($destination);
                return false;
            }
        } else {
            $data = (string) $add[$name];
            $size = strlen($data);
            $crc = sprintf('%u', crc32($data));
            $compressed = @gzdeflate($data, 6);
            $method = 8;
            if ($compressed === false || strlen($compressed) >= $size) {
                $compressed = $data;
                $method = 0;
            }
            lm_zip_write_header($out, $name, $method, $crc, strlen($compressed), $size, $offset, $dos, $central);
            $offset += 30 + strlen($name);
            fwrite($out, $compressed);
            $offset += strlen($compressed);
            $totalBytes += $size;
            $addedNames[$name] = true;
        }

        $count++;
    }

    // Append in-memory entries that were not already present.
    foreach ($add as $name => $data) {
        $name = lm_zip_clean_name($name);
        if ($name === '' || isset($addedNames[$name])) {
            continue;
        }
        $data = (string) $data;
        $size = strlen($data);
        $crc = sprintf('%u', crc32($data));
        $compressed = @gzdeflate($data, 6);
        $method = 8;
        if ($compressed === false || strlen($compressed) >= $size) {
            $compressed = $data;
            $method = 0;
        }
        lm_zip_write_header($out, $name, $method, $crc, strlen($compressed), $size, $offset, $dos, $central);
        $offset += 30 + strlen($name);
        fwrite($out, $compressed);
        $offset += strlen($compressed);
        $totalBytes += $size;
        $count++;
    }

    $centralOffset = $offset;
    fwrite($out, $central);
    fwrite($out, "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), $centralOffset, 0));

    fclose($in);
    fclose($out);

    return array(
        'count' => $count,
        'skipped' => $skipped,
        'bytes' => $totalBytes,
    );
}

/**
 * Build a ZIP from an array of name => contents.
 *
 * @param string $destination
 * @param array  $files
 * @return bool
 */
function lm_zip_write_files($destination, array $files)
{
    $out = @fopen($destination, 'wb');
    if (!$out) {
        return false;
    }

    $dos = lm_zip_dos_time();
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ($files as $name => $data) {
        $name = lm_zip_clean_name($name);
        if ($name === '' || !lm_zip_is_safe_entry($name)) {
            continue;
        }
        $data = (string) $data;
        $size = strlen($data);
        $crc = sprintf('%u', crc32($data));
        $compressed = @gzdeflate($data, 6);
        $method = 8;
        if ($compressed === false || strlen($compressed) >= $size) {
            $compressed = $data;
            $method = 0;
        }
        lm_zip_write_header($out, $name, $method, $crc, strlen($compressed), $size, $offset, $dos, $central);
        $offset += 30 + strlen($name);
        fwrite($out, $compressed);
        $offset += strlen($compressed);
        $count++;
    }

    fwrite($out, $central);
    fwrite($out, "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), $offset, 0));
    fclose($out);

    return $count > 0;
}
