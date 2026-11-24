<?php
/**
 * Manifest-verified SQL migration runner (dry-run by default).
 * Refuses to execute anything unless --apply is explicitly passed, verifies every
 * migration file SHA-256 against VTU/migrations/MANIFEST.json before connecting,
 * and invokes bc_release_readiness_report(..., true) after --apply.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/verify-release-readiness.php';

if (!function_exists('bc_migrations_plan')) {
    function bc_migrations_plan($repoRoot) {
        $repoRoot = rtrim((string)$repoRoot, '/\\');
        $manifestPath = $repoRoot . '/VTU/migrations/MANIFEST.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Missing VTU/migrations/MANIFEST.json');
        }
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['migrations'])) {
            throw new RuntimeException('Invalid schema manifest JSON.');
        }
        $items = [];
        foreach ($manifest['migrations'] as $entry) {
            $rel = (string)($entry['path'] ?? '');
            $expected = (string)($entry['sha256'] ?? '');
            $full = $repoRoot . '/' . $rel;
            if (!is_file($full)) {
                throw new RuntimeException("Missing migration file: $rel");
            }
            $actual = hash_file('sha256', $full);
            if ($actual !== $expected) {
                throw new RuntimeException("Checksum mismatch for $rel");
            }
            $items[] = [
                'path'   => $rel,
                'sha256' => $actual,
                'bytes'  => filesize($full),
            ];
        }
        return [
            'ok'               => true,
            'manifest_version' => (string)($manifest['version'] ?? ''),
            'count'            => count($items),
            'migrations'       => $items,
        ];
    }
}

if (!function_exists('bc_migrations_split_sql')) {
    function bc_migrations_split_sql($sql) {
        $lines = preg_split('/\R/', (string)$sql);
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        $joined = implode("\n", $clean);
        $stmts = array_filter(array_map('trim', explode(";\n", $joined . "\n")), static fn($s) => $s !== '');
        $out = [];
        foreach ($stmts as $stmt) {
            $out[] = rtrim($stmt, "; \t\r\n");
        }
        return $out;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && PHP_SAPI === 'cli') {
    $apply = false;
    $edition = 'SAAS';
    $repoRoot = dirname(__DIR__, 2);
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help') {
            echo "Usage: php VTU/bin/apply-migrations.php [--dry-run | --apply] [--edition=SAAS|NON-SAAS]\n";
            echo "Default is --dry-run (verifies MANIFEST.json SHA-256 checksums without touching DB).\n";
            exit(0);
        }
        if ($arg === '--apply') {
            $apply = true;
        } elseif ($arg === '--dry-run') {
            $apply = false;
        } elseif (preg_match('/\A--edition=(SAAS|NON-SAAS)\z/D', $arg, $m)) {
            $edition = $m[1];
        } else {
            fwrite(STDERR, "Invalid option: $arg. Use --help.\n");
            exit(2);
        }
    }

    try {
        $plan = bc_migrations_plan($repoRoot);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'code' => 'manifest_verification_failed', 'error' => $e->getMessage()], JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }

    if (!$apply) {
        echo json_encode([
            'ok'               => true,
            'mode'             => 'dry_run',
            'manifest_version' => $plan['manifest_version'],
            'count'            => $plan['count'],
            'migrations'       => $plan['migrations'],
        ], JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }

    $connectFile = $repoRoot . '/VTU/DGV7.0-' . $edition . '/func/bc-connect.php';
    if (!is_file($connectFile)) {
        fwrite(STDERR, "Missing DB connector: $connectFile\n");
        exit(2);
    }
    require_once $connectFile;
    global $connection_server;
    if (!($connection_server instanceof mysqli)) {
        fwrite(STDERR, "Database connection unavailable.\n");
        exit(2);
    }

    $connection_server->query(
        "CREATE TABLE IF NOT EXISTS schema_migrations_applied (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            migration_path VARCHAR(255) NOT NULL,
            sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_migration_path (migration_path)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $applied = [];
    $res = $connection_server->query('SELECT migration_path, sha256 FROM schema_migrations_applied');
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $applied[(string)$row['migration_path']] = (string)$row['sha256'];
        }
        $res->close();
    }

    $executed = [];
    $skipped  = [];
    foreach ($plan['migrations'] as $m) {
        $path = $m['path'];
        $sha  = $m['sha256'];
        if (isset($applied[$path])) {
            if ($applied[$path] !== $sha) {
                echo json_encode(['ok' => false, 'code' => 'applied_checksum_drift', 'path' => $path], JSON_PRETTY_PRINT) . "\n";
                exit(1);
            }
            $skipped[] = $path;
            continue;
        }
        $sql = (string)file_get_contents($repoRoot . '/' . $path);
        foreach (bc_migrations_split_sql($sql) as $stmt) {
            if (!$connection_server->query($stmt)) {
                echo json_encode([
                    'ok'    => false,
                    'code'  => 'migration_sql_failed',
                    'path'  => $path,
                    'error' => $connection_server->error,
                ], JSON_PRETTY_PRINT) . "\n";
                exit(1);
            }
        }
        $ins = $connection_server->prepare('INSERT INTO schema_migrations_applied(migration_path, sha256) VALUES(?, ?)');
        $ins->bind_param('ss', $path, $sha);
        $ins->execute();
        $ins->close();
        $executed[] = $path;
    }

    $readiness = bc_release_readiness_report($repoRoot, true);
    echo json_encode([
        'ok'        => !empty($readiness['ok']),
        'mode'      => 'applied',
        'executed'  => $executed,
        'skipped'   => $skipped,
        'readiness' => $readiness,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(!empty($readiness['ok']) ? 0 : 1);
}
