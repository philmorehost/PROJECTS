<?php
/**
 * Synchronized update package & schema manifest preflight guard.
 * Paired byte-for-byte between SAAS and NON-SAAS.
 */

function bc_update_validate_manifest_staging(array $manifest, $stagingDir) {
    if (!empty($manifest['files_to_delete']) && is_array($manifest['files_to_delete'])) {
        foreach ($manifest['files_to_delete'] as $rel) {
            if (!is_string($rel) || $rel === '' || $rel[0] === '/' || $rel[0] === '\\'
                || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
                throw new Exception('Unsafe files_to_delete path in update manifest.');
            }
        }
    }

    if (!empty($manifest['file_sha256']) && is_array($manifest['file_sha256'])) {
        $filesDir = rtrim((string)$stagingDir, '/\\') . '/files';
        $baseDir = is_dir($filesDir) ? $filesDir : rtrim((string)$stagingDir, '/\\');
        foreach ($manifest['file_sha256'] as $rel => $expectedSha) {
            if (!is_string($rel) || $rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false
                || !is_string($expectedSha) || !preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha)) {
                throw new Exception('Invalid file_sha256 manifest entry.');
            }
            $target = $baseDir . '/' . ltrim($rel, '/\\');
            if (!is_file($target)) {
                throw new Exception('Staged update missing manifested file: ' . $rel);
            }
            $actual = hash_file('sha256', $target);
            if (!hash_equals($expectedSha, $actual)) {
                throw new Exception('Checksum mismatch in update package for: ' . $rel);
            }
        }
    }
}

function bc_update_verify_required_innodb(mysqli $db, array $manifest, $rootDir) {
    $tables = [];
    if (!empty($manifest['required_innodb_tables']) && is_array($manifest['required_innodb_tables'])) {
        $tables = $manifest['required_innodb_tables'];
    } else {
        $repoManifest = dirname(rtrim((string)$rootDir, '/\\')) . '/migrations/MANIFEST.json';
        if (is_file($repoManifest)) {
            $decoded = json_decode((string)file_get_contents($repoManifest), true);
            if (is_array($decoded) && !empty($decoded['required_innodb_tables'])) {
                $tables = $decoded['required_innodb_tables'];
            }
        }
    }
    if (!$tables) return;

    $clean = [];
    foreach ($tables as $t) {
        if (!is_string($t) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $t)) {
            throw new Exception('Invalid required_innodb_tables identifier.');
        }
        $clean[] = "'" . mysqli_real_escape_string($db, $t) . "'";
    }
    $res = mysqli_query($db, 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', $clean) . ')');
    $engines = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $engines[(string)$row['TABLE_NAME']] = strtoupper((string)$row['ENGINE']);
    }
    // Enforce InnoDB on any table that already exists (non-InnoDB tables fail closed).
    foreach ($engines as $t => $eng) {
        if ($eng !== 'INNODB') {
            throw new Exception("Update blocked: table $t has engine $eng instead of InnoDB.");
        }
    }
}
