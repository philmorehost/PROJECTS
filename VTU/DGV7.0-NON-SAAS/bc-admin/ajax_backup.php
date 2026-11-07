<?php
// ajax_backup.php
session_start();
if (!isset($_SESSION["admin_session"])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access']));
}

// Prevent timeout on slow shared hosting
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once("../func/bc-admin-config.php");

define('ROOT_DIR', dirname(__DIR__)); // NON-SAAS root directory
define('BACKUP_DIR', ROOT_DIR . '/backups');
$timestamp = date('Y-m-d_H-i-s');

// 1. Secure the Backup Directory
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
    // Block direct web access to backups
    file_put_contents(BACKUP_DIR . '/.htaccess', "Order allow,deny\nDeny from all");
}

// Function to delete backups older than 14 days
function prune_old_backups($backup_dir, $max_days = 14) {
    if (!is_dir($backup_dir)) return 0;

    $now = time();
    $cutoff_time = $now - ($max_days * 24 * 60 * 60);
    $deleted_count = 0;

    $iterator = new DirectoryIterator($backup_dir);
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isFile()) {
            $extension = strtolower($fileinfo->getExtension());
            // ONLY target .sql and .zip backup archives to protect htaccess
            if (in_array($extension, ['sql', 'zip'])) {
                if ($fileinfo->getMTime() < $cutoff_time) {
                    @unlink($fileinfo->getRealPath());
                    $deleted_count++;
                }
            }
        }
    }
    return $deleted_count;
}

try {
    if (!$connection_server) {
        throw new Exception("Database connection is not available.");
    }

    // ---------------------------------------------------------
    // STEP A: DATABASE BACKUP (Pure PHP)
    // ---------------------------------------------------------
    $sql_dump = "-- System Backup: {$timestamp}\n";
    $sql_dump .= "-- Database: {$mySqlDBName}\n\n";
    
    // Disable foreign keys checks during restore to avoid constraint errors
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    // Get all tables
    $tables = [];
    $result = mysqli_query($connection_server, "SHOW TABLES");
    if (!$result) {
        throw new Exception("Failed to query database tables: " . mysqli_error($connection_server));
    }
    while ($row = mysqli_fetch_row($result)) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        
        $create_res = mysqli_query($connection_server, "SHOW CREATE TABLE `$table`");
        if ($create_res) {
            $row2 = mysqli_fetch_row($create_res);
            $sql_dump .= $row2[1] . ";\n\n";
        }
        
        $data_res = mysqli_query($connection_server, "SELECT * FROM `$table`");
        if ($data_res) {
            $num_columns = mysqli_num_fields($data_res);
            while ($row = mysqli_fetch_row($data_res)) {
                $sql_dump .= "INSERT INTO `$table` VALUES(";
                for ($j = 0; $j < $num_columns; $j++) {
                    if (isset($row[$j])) {
                        $escaped_val = mysqli_real_escape_string($connection_server, $row[$j]);
                        $sql_dump .= '"' . $escaped_val . '"';
                    } else {
                        $sql_dump .= 'NULL';
                    }
                    if ($j < ($num_columns - 1)) {
                        $sql_dump .= ',';
                    }
                }
                $sql_dump .= ");\n";
            }
            $sql_dump .= "\n\n";
        }
    }
    
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $db_backup_path = BACKUP_DIR . "/db_backup_{$timestamp}.sql";
    if (file_put_contents($db_backup_path, $sql_dump) === false) {
        throw new Exception("Failed to write database backup SQL file.");
    }

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
                $exclude_prefixes = [
                    'backups/',
                    'tmp_update/',
                    'dg7-android/',
                    'dg7-ios/',
                    'dg6-android/',
                    'dg6-ios/',
                    'mzeevtu/',
                    'mzeevtu-android/',
                    'mzeevtu-ios/',
                    'payhub-android/',
                    'payhub-ios/',
                    'uploaded-image/',
                    'downloads/',
                    'logs/',
                    'scratch/',
                    'tests/',
                    '.git/',
                    '.github/'
                ];
                
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
    // STEP C: CLEANUP (Prune backups older than 14 days)
    // ---------------------------------------------------------
    $deleted_files = prune_old_backups(BACKUP_DIR, 14);

    echo json_encode([
        'status' => 'success',
        'message' => 'System and database backup created successfully.',
        'backup_zip' => "files_backup_{$timestamp}.zip",
        'backup_sql' => "db_backup_{$timestamp}.sql",
        'pruned_count' => $deleted_files
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Backup failed: ' . $e->getMessage()]);
    exit;
}
