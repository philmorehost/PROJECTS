<?php
/**
 * Release/operator schema & dual-edition parity manifest verifier.
 * --offline: verifies all VTU/migrations/*.sql and PAYHUB/install/*.sql files are
 *            manifested with matching SHA-256 and all paired SAAS/NON-SAAS helpers match.
 * --db-check: read-only InnoDB table-engine check via mysqli (never executes DDL).
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

function bc_manifest_root() {
    return realpath(__DIR__ . '/../..');
}

function bc_manifest_verify_offline($manifestPath) {
    $root = bc_manifest_root();
    $raw = file_get_contents($manifestPath);
    if ($raw === false) throw new RuntimeException('Missing manifest: ' . $manifestPath);
    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || empty($manifest['migrations']) || empty($manifest['paired_helpers']) || empty($manifest['required_innodb_tables'])) {
        throw new RuntimeException('Malformed schema manifest.');
    }

    // 1. Every .sql file in VTU/migrations and PAYHUB/install must appear in manifest
    $seenSql = [];
    foreach ($manifest['migrations'] as $entry) {
        $rel = (string)($entry['path'] ?? '');
        $sha = (string)($entry['sha256'] ?? '');
        if ($rel === '' || !preg_match('/\A[a-f0-9]{64}\z/D', $sha)) {
            throw new RuntimeException('Invalid migration manifest entry: ' . $rel);
        }
        $abs = $root . '/' . $rel;
        if (!is_file($abs)) throw new RuntimeException('Missing manifested SQL: ' . $rel);
        $actual = hash_file('sha256', $abs);
        if (!hash_equals($sha, $actual)) {
            throw new RuntimeException('SQL SHA-256 drift for ' . $rel . ' (expected ' . $sha . ', got ' . $actual . ')');
        }
        $seenSql[$rel] = true;
    }

    foreach (['VTU/migrations/*.sql', 'PAYHUB/install/*.sql'] as $glob) {
        foreach (glob($root . '/' . $glob) ?: [] as $file) {
            $rel = ltrim(substr($file, strlen($root)), '/');
            if (!isset($seenSql[$rel])) {
                throw new RuntimeException('Unmanifested SQL migration detected: ' . $rel);
            }
        }
    }

    // 2. Every paired helper must exist in both SAAS and NON-SAAS and match SHA-256
    foreach ($manifest['paired_helpers'] as $entry) {
        $name = (string)($entry['name'] ?? '');
        $sha  = (string)($entry['sha256'] ?? '');
        if ($name === '' || !preg_match('/\A[a-f0-9]{64}\z/D', $sha)) {
            throw new RuntimeException('Invalid helper manifest entry: ' . $name);
        }
        $saas = $root . '/VTU/DGV7.0-SAAS/func/' . $name;
        $non  = $root . '/VTU/DGV7.0-NON-SAAS/func/' . $name;
        if (!is_file($saas) || !is_file($non)) {
            throw new RuntimeException('Missing paired helper in SAAS or NON-SAAS: ' . $name);
        }
        $sSha = hash_file('sha256', $saas);
        $nSha = hash_file('sha256', $non);
        if (!hash_equals($sSha, $nSha)) {
            throw new RuntimeException('Edition parity mismatch for ' . $name);
        }
        if (!hash_equals($sha, $sSha)) {
            throw new RuntimeException('Helper SHA-256 drift for ' . $name . ' (manifest stale; update MANIFEST.json)');
        }
    }

    return [
        'ok'             => true,
        'migrations'     => count($manifest['migrations']),
        'paired_helpers' => count($manifest['paired_helpers']),
        'tables'         => count($manifest['required_innodb_tables']),
    ];
}

function bc_manifest_verify_db(mysqli $db, $manifestPath) {
    $raw = file_get_contents($manifestPath);
    $manifest = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    $tables = $manifest['required_innodb_tables'] ?? [];
    if (!$tables) throw new RuntimeException('No required tables listed.');
    $quoted = array_map(fn($t) => "'" . $db->real_escape_string((string)$t) . "'", $tables);
    $res = $db->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', $quoted) . ')');
    $engines = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $engines[(string)$row['TABLE_NAME']] = strtoupper((string)$row['ENGINE']);
    }
    $missing = [];
    $nonInnodb = [];
    foreach ($tables as $t) {
        if (!isset($engines[$t])) $missing[] = $t;
        elseif ($engines[$t] !== 'INNODB') $nonInnodb[] = $t . ':' . $engines[$t];
    }
    return ['ok' => !$missing && !$nonInnodb, 'missing' => $missing, 'non_innodb' => $nonInnodb];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $mode = $argv[1] ?? '--offline';
    $manifestPath = __DIR__ . '/../migrations/MANIFEST.json';
    if ($mode === '--help') {
        echo "Usage: php VTU/bin/verify-schema-manifest.php [--offline|--db-check]\n";
        exit(0);
    }
    if ($mode === '--offline') {
        $r = bc_manifest_verify_offline($manifestPath);
        echo json_encode($r, JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    if ($mode === '--db-check') {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $name = getenv('DB_NAME') ?: '';
        if ($user === '' || $name === '') {
            fwrite(STDERR, "Set DB_HOST, DB_USER, DB_PASS, DB_NAME for --db-check.\n");
            exit(2);
        }
        $db = new mysqli($host, $user, $pass, $name);
        $r = bc_manifest_verify_db($db, $manifestPath);
        echo json_encode($r, JSON_THROW_ON_ERROR) . "\n";
        exit($r['ok'] ? 0 : 1);
    }
    fwrite(STDERR, "Unknown option. Use --help.\n");
    exit(2);
}
