<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

// ajax_backup.php
bc_secure_session_start();
if (!isset($_SESSION["spadmin_session"])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access']));
}

// Prevent timeout on slow shared hosting
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once("../func/bc-spadmin-config.php");
require_once __DIR__ . '/../func/bc-backup-prune.php';

define('ROOT_DIR', dirname(__DIR__)); // SAAS root directory
define('BACKUP_DIR', ROOT_DIR . '/backups');
$timestamp = date('Y-m-d_H-i-s');

// 1. Secure the Backup Directory
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
    // Block direct web access to backups
    file_put_contents(BACKUP_DIR . '/.htaccess', "Order allow,deny\nDeny from all");
}

function prune_old_backups($backup_dir, $max_days = 14, $max_keep_sets = 2, $protected_files = []) {
    return bc_prune_old_backups($backup_dir, (int)$max_keep_sets, (int)$max_days, $protected_files);
}

$db_backup_path = '';
$file_backup_path = '';

try {
    if (!$connection_server) {
        throw new Exception("Database connection is not available.");
    }

    // Reclaim disk space BEFORE writing a new snapshot (keep at most 1 prior backup set)
    $pruned_before = bc_prune_old_backups(BACKUP_DIR, 1, 14);

    // ---------------------------------------------------------
    // STEP A: DATABASE BACKUP (Pure PHP, memory-safe streaming)
    // ---------------------------------------------------------
    $db_backup_path = BACKUP_DIR . "/db_backup_{$timestamp}.sql";
    $fh = fopen($db_backup_path, 'w');
    if (!$fh) {
        throw new Exception("Failed to open database backup SQL file for writing.");
    }

    fwrite($fh, "-- System Backup: {$timestamp}\n");
    fwrite($fh, "-- Database: {$mySqlDBName}\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    // Get all tables
    $result = mysqli_query($connection_server, "SHOW TABLES");
    if (!$result) {
        fclose($fh);
        throw new Exception("Failed to query database tables: " . mysqli_error($connection_server));
    }

    $tables = [];
    while ($row = mysqli_fetch_row($result)) {
        $tables[] = $row[0];
    }
    mysqli_free_result($result);

    foreach ($tables as $table) {
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");

        $create_res = mysqli_query($connection_server, "SHOW CREATE TABLE `$table`");
        if ($create_res) {
            $row2 = mysqli_fetch_row($create_res);
            fwrite($fh, $row2[1] . ";\n\n");
            mysqli_free_result($create_res);
        }

        // Stream data row-by-row without loading entire table into memory
        $data_res = mysqli_query($connection_server, "SELECT * FROM `$table`", MYSQLI_USE_RESULT);
        if ($data_res) {
            $num_columns = mysqli_num_fields($data_res);
            while ($row = mysqli_fetch_row($data_res)) {
                $values = [];
                for ($j = 0; $j < $num_columns; $j++) {
                    if (isset($row[$j])) {
                        $values[] = '"' . mysqli_real_escape_string($connection_server, $row[$j]) . '"';
                    } else {
                        $values[] = 'NULL';
                    }
                }
                fwrite($fh, "INSERT INTO `$table` VALUES(" . implode(',', $values) . ");\n");
                // Free row memory explicitly
                unset($row, $values);
            }
            mysqli_free_result($data_res);
            fwrite($fh, "\n\n");
        }
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    // ---------------------------------------------------------
    // STEP B: FILE BACKUP (Using ZipArchive)
    // ---------------------------------------------------------
    $file_backup_path = BACKUP_DIR . "/files_backup_{$timestamp}.zip";
    $zip = new ZipArchive();

    if ($zip->open($file_backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOT_DIR),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen(ROOT_DIR) + 1);
                
                // Normalise separators
                $norm_rel = str_replace('\\', '/', $relative_path);
                
                // Exclude folders that are not part of the web application deployment or are too large
                $norm_rel_lower = strtolower($norm_rel);
                $exclude_prefixes = bc_backup_excluded_prefixes();
                
                $should_exclude = false;
                foreach ($exclude_prefixes as $prefix) {
                    if (strpos($norm_rel_lower, $prefix) === 0) {
                        $should_exclude = true;
                        break;
                    }
                }
                
                // Also exclude any zip archives to avoid zipping nested massive backups
                if (strtolower(pathinfo($norm_rel_lower, PATHINFO_EXTENSION)) === 'zip') {
                    $should_exclude = true;
                }
                
                if ($should_exclude) {
                    continue;
                }
                
                $zip->addFile($file_path, $relative_path);
            }
        }
        $zip->close();
    } else {
        throw new Exception("Failed to create file backup ZIP archive.");
    }

    // ---------------------------------------------------------
    // STEP C: CLEANUP (Keep at most 2 newest backup sets & <= 14 days)
    // ---------------------------------------------------------
    $pruned_after = bc_prune_old_backups(BACKUP_DIR, 2, 14, [basename($file_backup_path), basename($db_backup_path)]);
    $deleted_files = $pruned_before + $pruned_after;

    echo json_encode([
        'status' => 'success',
        'message' => 'System and database backup created successfully.',
        'backup_zip' => "files_backup_{$timestamp}.zip",
        'backup_sql' => "db_backup_{$timestamp}.sql",
        'pruned_count' => $deleted_files
    ]);
    exit;

} catch (Exception $e) {
    if ($db_backup_path !== '' && is_file($db_backup_path)) {
        @unlink($db_backup_path);
    }
    if ($file_backup_path !== '' && is_file($file_backup_path)) {
        @unlink($file_backup_path);
    }
    echo json_encode(['status' => 'error', 'message' => 'Backup failed: ' . $e->getMessage()]);
    exit;
}