<?php
/**
 * Idempotent migration helper for PAYHUB/install/index.php and PAYHUB/install/migrate.php.
 * Applies PAYHUB/install/20261118_*.sql in explicit dependency order outside transactions.
 */

if (!function_exists('payhub_install_ordered_migrations')) {
    function payhub_install_ordered_migrations() {
        return [
            '20261118_webhook_log_compat.sql',
            '20261118_webhook_retry_claims.sql',
            '20261118_webhook_initial_claims.sql',
            '20261118_webhook_outbox.sql',
            '20261118_webhook_reviews.sql',
            '20261118_webhook_reconciliations.sql',
            '20261118_deposit_evidence.sql',
        ];
    }
}

if (!function_exists('payhub_install_split_sql')) {
    function payhub_install_split_sql($sql) {
        $lines = preg_split('/\R/', (string)$sql);
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        $joined = implode("\n", $clean);
        $stmts = explode(";\n", $joined . "\n");
        $out = [];
        foreach ($stmts as $stmt) {
            $trimmed = trim($stmt, "; \t\r\n");
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }
}

if (!function_exists('payhub_install_apply_migrations')) {
    function payhub_install_apply_migrations(PDO $pdo, $installDir = __DIR__) {
        if ($pdo->inTransaction()) {
            return ['ok' => false, 'code' => 'outer_transaction_refused', 'applied' => [], 'skipped' => []];
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations_applied (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration_path VARCHAR(255) NOT NULL,
                sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_migration_path (migration_path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $already = [];
        $q = $pdo->query('SELECT migration_path, sha256 FROM schema_migrations_applied');
        if ($q) {
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $already[(string)$row['migration_path']] = (string)$row['sha256'];
            }
        }

        $applied = [];
        $skipped = [];
        $benign = ['already exists', 'duplicate column name', 'duplicate key name', 'multiple primary keys'];
        $dir = rtrim((string)$installDir, '/\\');

        foreach (payhub_install_ordered_migrations() as $file) {
            $full = $dir . '/' . $file;
            if (!is_file($full)) {
                return ['ok' => false, 'code' => 'missing_migration', 'path' => $file, 'applied' => $applied, 'skipped' => $skipped];
            }
            $key = 'PAYHUB/install/' . $file;
            $sha = hash_file('sha256', $full);
            if (isset($already[$key]) && hash_equals($already[$key], $sha)) {
                $skipped[] = $key;
                continue;
            }
            $sql = (string)file_get_contents($full);
            foreach (payhub_install_split_sql($sql) as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    $msg = $e->getMessage();
                    $ignore = false;
                    foreach ($benign as $pat) {
                        if (stripos($msg, $pat) !== false) {
                            $ignore = true;
                            break;
                        }
                    }
                    if (!$ignore) {
                        return ['ok' => false, 'code' => 'migration_sql_failed', 'path' => $key, 'error' => $msg, 'applied' => $applied, 'skipped' => $skipped];
                    }
                }
            }
            $ins = $pdo->prepare('INSERT INTO schema_migrations_applied (migration_path, sha256) VALUES (?, ?) ON DUPLICATE KEY UPDATE sha256 = VALUES(sha256)');
            $ins->execute([$key, $sha]);
            $applied[] = $key;
        }

        return ['ok' => true, 'code' => 'healed', 'applied' => $applied, 'skipped' => $skipped];
    }
}
