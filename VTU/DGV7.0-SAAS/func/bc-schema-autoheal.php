<?php
/**
 * Idempotent, lock-serialized schema self-healing helper for DGV7.0.
 * Paired byte-for-byte between SAAS and NON-SAAS.
 *
 * Safe to call from bc-tables.php (when BC_TABLES_VERSION advances),
 * install.php, execute_update.php, and cron_update.php. Refuses to run
 * if an active transaction is open (autocommit=0) because MySQL DDL
 * causes an implicit commit.
 */

if (!function_exists('bc_schema_autoheal_split_sql')) {
    function bc_schema_autoheal_split_sql($sql) {
        $lines = preg_split('/\R/', (string)$sql);
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        $joined = implode("\n", $clean);
        $rawStmts = explode(";\n", $joined . "\n");
        $out = [];
        foreach ($rawStmts as $stmt) {
            $trimmed = trim($stmt, "; \t\r\n");
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }
}

if (!function_exists('bc_schema_autoheal_resolve_migrations_dir')) {
    function bc_schema_autoheal_resolve_migrations_dir($migrationsDir = null) {
        if (is_string($migrationsDir) && $migrationsDir !== '' && is_dir($migrationsDir)) {
            return rtrim($migrationsDir, '/\\');
        }
        $candidates = [
            dirname(__DIR__, 2) . '/migrations',
            dirname(__DIR__) . '/migrations',
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }
}

if (!function_exists('bc_schema_autoheal_list_files')) {
    function bc_schema_autoheal_list_files($dir) {
        $manifestPath = $dir . '/MANIFEST.json';
        $repoRoot = dirname($dir, 2);
        if (is_file($manifestPath)) {
            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            if (is_array($manifest) && !empty($manifest['migrations'])) {
                $items = [];
                foreach ($manifest['migrations'] as $entry) {
                    $rel = (string)($entry['path'] ?? '');
                    if (strpos($rel, 'VTU/migrations/') !== 0) {
                        continue;
                    }
                    $base = basename($rel);
                    $full = $dir . '/' . $base;
                    if (!is_file($full) && is_file($repoRoot . '/' . $rel)) {
                        $full = $repoRoot . '/' . $rel;
                    }
                    if (!is_file($full)) {
                        throw new RuntimeException('Missing manifested migration: ' . $rel);
                    }
                    $actual = hash_file('sha256', $full);
                    if (!hash_equals((string)($entry['sha256'] ?? ''), $actual)) {
                        throw new RuntimeException('Migration checksum mismatch: ' . $rel);
                    }
                    $items[] = ['key' => $rel, 'path' => $full, 'sha256' => $actual];
                }
                return $items;
            }
        }

        $files = glob($dir . '/*.sql');
        if (!is_array($files)) {
            return [];
        }
        sort($files, SORT_STRING);
        $items = [];
        foreach ($files as $full) {
            if (!is_file($full)) continue;
            $items[] = [
                'key'    => 'VTU/migrations/' . basename($full),
                'path'   => $full,
                'sha256' => hash_file('sha256', $full),
            ];
        }
        return $items;
    }
}

if (!function_exists('bc_schema_autoheal')) {
    function bc_schema_autoheal(mysqli $db, $migrationsDir = null) {
        $txRes = @mysqli_query($db, 'SELECT @@autocommit AS ac');
        if ($txRes instanceof mysqli_result) {
            $txRow = mysqli_fetch_assoc($txRes);
            mysqli_free_result($txRes);
            if ($txRow && (int)($txRow['ac'] ?? 1) === 0) {
                return ['ok' => false, 'code' => 'outer_transaction_refused', 'applied' => [], 'skipped' => []];
            }
        }

        $dir = bc_schema_autoheal_resolve_migrations_dir($migrationsDir);
        if ($dir === null) {
            return ['ok' => true, 'code' => 'no_migrations_dir', 'applied' => [], 'skipped' => []];
        }

        $lockAcquired = false;
        try {
            $lockRes = @mysqli_query($db, "SELECT GET_LOCK('dgv7_schema_autoheal', 2) AS got_lock");
            if ($lockRes instanceof mysqli_result) {
                $lr = mysqli_fetch_assoc($lockRes);
                mysqli_free_result($lockRes);
                $lockAcquired = ((int)($lr['got_lock'] ?? 0) === 1);
            }
            if (!$lockAcquired) {
                return ['ok' => false, 'code' => 'lock_busy', 'applied' => [], 'skipped' => []];
            }

            $list = bc_schema_autoheal_list_files($dir);
            if (!$list) {
                return ['ok' => true, 'code' => 'no_migrations', 'applied' => [], 'skipped' => []];
            }

            $okTable = mysqli_query(
                $db,
                "CREATE TABLE IF NOT EXISTS schema_migrations_applied (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    migration_path VARCHAR(255) NOT NULL,
                    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_migration_path (migration_path)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            if (!$okTable) {
                return ['ok' => false, 'code' => 'ledger_table_failed', 'error' => mysqli_error($db), 'applied' => [], 'skipped' => []];
            }

            $already = [];
            $q = mysqli_query($db, 'SELECT migration_path, sha256 FROM schema_migrations_applied');
            if ($q instanceof mysqli_result) {
                while ($row = mysqli_fetch_assoc($q)) {
                    $already[(string)$row['migration_path']] = (string)$row['sha256'];
                }
                mysqli_free_result($q);
            }

            // Ensure sas_service_requests exists before index migrations (e.g.
            // 20261118_service_attempt_review_indexes.sql) alter it; otherwise installations
            // that have not yet lazily initialized bc_svc_ensure_schema() fail during OTA updates.
            $okSvcReq = mysqli_query(
                $db,
                "CREATE TABLE IF NOT EXISTS sas_service_requests (
                    id INT NOT NULL AUTO_INCREMENT,
                    vendor_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NOT NULL DEFAULT 0,
                    username VARCHAR(191) NOT NULL DEFAULT '',
                    type VARCHAR(32) NOT NULL DEFAULT '',
                    provider VARCHAR(32) NOT NULL DEFAULT '',
                    reference VARCHAR(64) NOT NULL DEFAULT '',
                    payload TEXT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NULL,
                    PRIMARY KEY (id),
                    KEY idx_vendor_user (vendor_id, user_id),
                    KEY idx_vendor_type (vendor_id, type, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            if (!$okSvcReq) {
                return ['ok' => false, 'code' => 'service_requests_table_failed', 'error' => mysqli_error($db), 'applied' => [], 'skipped' => []];
            }

            $applied = [];
            $skipped = [];
            $benignPatterns = ['already exists', 'duplicate column name', 'duplicate key name', 'multiple primary keys'];
            foreach ($list as $item) {
                $key = $item['key'];
                $sha = $item['sha256'];
                if (isset($already[$key]) && hash_equals($already[$key], $sha)) {
                    $skipped[] = $key;
                    continue;
                }
                $sql = (string)file_get_contents($item['path']);
                foreach (bc_schema_autoheal_split_sql($sql) as $stmt) {
                    $r = @mysqli_query($db, $stmt);
                    if (!$r) {
                        $err = mysqli_error($db);
                        $ignore = false;
                        foreach ($benignPatterns as $pat) {
                            if (stripos($err, $pat) !== false) {
                                $ignore = true;
                                break;
                            }
                        }
                        if (!$ignore) {
                            return [
                                'ok'      => false,
                                'code'    => 'migration_sql_failed',
                                'path'    => $key,
                                'error'   => $err,
                                'applied' => $applied,
                                'skipped' => $skipped,
                            ];
                        }
                    }
                }

                $stmtIns = mysqli_prepare(
                    $db,
                    'INSERT INTO schema_migrations_applied (migration_path, sha256) VALUES (?, ?) ON DUPLICATE KEY UPDATE sha256 = VALUES(sha256)'
                );
                if (!$stmtIns) {
                    return ['ok' => false, 'code' => 'record_failed', 'error' => mysqli_error($db), 'applied' => $applied, 'skipped' => $skipped];
                }
                mysqli_stmt_bind_param($stmtIns, 'ss', $key, $sha);
                $execOk = mysqli_stmt_execute($stmtIns);
                $execErr = mysqli_stmt_error($stmtIns);
                mysqli_stmt_close($stmtIns);
                if (!$execOk) {
                    return ['ok' => false, 'code' => 'record_failed', 'error' => $execErr, 'applied' => $applied, 'skipped' => $skipped];
                }
                $applied[] = $key;
            }

            // Also ensure phase-1B fintech schema (sas_app_tokens, sas_webhook_events, sas_fintech_ledger,
            // account_type/kyb_* columns) when prerequisite legacy tables exist.
            $fintechSchemaFile = __DIR__ . '/bc-fintech-schema.php';
            if (is_file($fintechSchemaFile)) {
                $scChk = @mysqli_query($db, "SHOW TABLES LIKE 'sas_service_control'");
                $hasServiceControl = ($scChk instanceof mysqli_result && mysqli_num_rows($scChk) > 0);
                if ($scChk instanceof mysqli_result) {
                    mysqli_free_result($scChk);
                }
                if ($hasServiceControl) {
                    $prevConn = $GLOBALS['connection_server'] ?? null;
                    $GLOBALS['connection_server'] = $db;
                    require_once $fintechSchemaFile;
                    $fintechOk = bc_fintech_ensure_schema();
                    if ($prevConn !== null) {
                        $GLOBALS['connection_server'] = $prevConn;
                    }
                    if (!$fintechOk) {
                        return ['ok' => false, 'code' => 'fintech_schema_failed', 'applied' => $applied, 'skipped' => $skipped];
                    }
                }
            }

            return ['ok' => true, 'code' => 'healed', 'applied' => $applied, 'skipped' => $skipped];
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'exception', 'error' => $e->getMessage(), 'applied' => [], 'skipped' => []];
        } finally {
            if ($lockAcquired) {
                @mysqli_query($db, "SELECT RELEASE_LOCK('dgv7_schema_autoheal')");
            }
        }
    }
}
