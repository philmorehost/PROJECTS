<?php
// execute_update.php

// Buffer ALL output immediately — bc-spadmin-config.php is a full page router
// that may emit headers/HTML before we can output JSON.
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

session_start();
if (!isset($_SESSION["spadmin_session"])) {
    ob_end_clean();
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized Access']));
}

// Prevent timeout on slow networks
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once("../func/bc-spadmin-config.php");
// Discard any HTML/redirect output from the config include
ob_clean();

define('ROOT_DIR', dirname(__DIR__)); // SAAS root directory
define('TEMP_DIR', ROOT_DIR . '/tmp_update');
define('BACKUP_DIR', ROOT_DIR . '/backups');
define('ZIP_FILE', TEMP_DIR . '/update.zip');
define('STAGING_DIR', TEMP_DIR . '/staging');

// Grab backup filenames from POST
$backup_zip = $_POST['backup_zip'] ?? null;
$backup_sql = $_POST['backup_sql'] ?? null;

// Helper functions for directory copy and clean up
function recursive_move($src, $dst) {
    if (!is_dir($src)) return;
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if (($file !== '.') && ($file !== '..')) {
            if (is_dir($src . '/' . $file)) {
                recursive_move($src . '/' . $file, $dst . '/' . $file);
            } else {
                // Ensure target is removed before rename for Windows compatibility
                if (file_exists($dst . '/' . $file)) {
                    @unlink($dst . '/' . $file);
                }
                rename($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
    @rmdir($src); // Clean up empty directories as we go
}

function cleanup_temp_files() {
    $dir = TEMP_DIR;
    if (!is_dir($dir)) return;
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($dir);
}

// Rollback function
function restore_backup($zip_filename, $sql_filename, $connection_server) {
    if (!$zip_filename || !$sql_filename) {
        return "Backup filenames missing.";
    }

    $zip_path = BACKUP_DIR . '/' . $zip_filename;
    $sql_path = BACKUP_DIR . '/' . $sql_filename;

    // 1. Restore Files
    if (file_exists($zip_path)) {
        $zip = new ZipArchive;
        if ($zip->open($zip_path) === TRUE) {
            $zip->extractTo(ROOT_DIR);
            $zip->close();
        } else {
            return "Could not open or extract file backup ZIP archive.";
        }
    } else {
        return "File backup ZIP archive not found.";
    }

    // 2. Restore Database
    if (file_exists($sql_path)) {
        $sql_contents = file_get_contents($sql_path);
        if (!$connection_server) {
            return "Database connection is not available during restore.";
        }
        
        // Execute restore SQL script using multi_query
        if (mysqli_multi_query($connection_server, $sql_contents)) {
            // Flush multi-query buffers
            do {
                if ($res = mysqli_store_result($connection_server)) {
                    mysqli_free_result($res);
                }
            } while (mysqli_more_results($connection_server) && mysqli_next_result($connection_server));
        } else {
            return "Database restore query failed: " . mysqli_error($connection_server);
        }
    } else {
        return "Database backup SQL file not found.";
    }

    return true;
}

try {
    // ---------------------------------------------------------
    // 1. EXTRACT UPDATE ZIP TO STAGING
    // ---------------------------------------------------------
    if (!file_exists(ZIP_FILE)) {
        throw new Exception("Downloaded update package does not exist.");
    }

    $zip = new ZipArchive;
    if ($zip->open(ZIP_FILE) === TRUE) {
        // Write ZIP contents to a log file for debugging
        $zip_files_log = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zip_files_log[] = $zip->getNameIndex($i);
        }
        file_put_contents('zip_contents.log', implode("\n", $zip_files_log));

        if (is_dir(STAGING_DIR)) {
            remove_directory(STAGING_DIR);
        }
        mkdir(STAGING_DIR, 0755, true);
        $zip->extractTo(STAGING_DIR);
        $zip->close();
    } else {
        throw new Exception("Failed to open or extract the update ZIP file.");
    }

    $staging_dir_actual = STAGING_DIR;
    $manifest_path = STAGING_DIR . '/manifest.json';
    if (!file_exists($manifest_path)) {
        // Look for manifest.json in immediate subdirectories (e.g. if the folder was zipped instead of its contents)
        $subdirs = glob(STAGING_DIR . '/*', GLOB_ONLYDIR);
        $found = false;
        if (!empty($subdirs)) {
            foreach ($subdirs as $subdir) {
                if (file_exists($subdir . '/manifest.json')) {
                    $staging_dir_actual = $subdir;
                    $manifest_path = $subdir . '/manifest.json';
                    $found = true;
                    break;
                }
            }
        }
        if (!$found) {
            throw new Exception("Update manifest file (manifest.json) is missing from the package.");
        }
    }
    
    $manifest = json_decode(file_get_contents($manifest_path), true);
    if (!$manifest || !isset($manifest['version'])) {
        throw new Exception("Manifest file is corrupted or version is not specified.");
    }

    // ---------------------------------------------------------
    // 2. DELETE OLD FILES (GitHub effect)
    // ---------------------------------------------------------
    if (!empty($manifest['files_to_delete'])) {
        foreach ($manifest['files_to_delete'] as $file) {
            // Directory Traversal Prevention: Clean paths to contain only allowed sub-files
            $clean_path = str_replace(['../', '..\\'], '', $file);
            $target_file = ROOT_DIR . '/' . ltrim($clean_path, '/\\');
            if (file_exists($target_file) && is_file($target_file)) {
                @unlink($target_file);
            }
        }
    }

    // ---------------------------------------------------------
    // 3. COPY NEW FILES TO LIVE ROOT
    // ---------------------------------------------------------
    $source_files = $staging_dir_actual . '/files';
    if (is_dir($source_files)) {
        recursive_move($source_files, ROOT_DIR);
    } else {
        // Fallback: If no "files" folder exists, assume the staging directory itself contains the update files.
        // We must exclude the manifest and other update metadata.
        $dir = opendir($staging_dir_actual);
        while (false !== ($file = readdir($dir))) {
            if ($file !== '.' && $file !== '..' && $file !== 'manifest.json' && $file !== 'update.sql') {
                $src_path = $staging_dir_actual . '/' . $file;
                $dst_path = ROOT_DIR . '/' . $file;
                if (is_dir($src_path)) {
                    recursive_move($src_path, $dst_path);
                } else {
                    if (file_exists($dst_path)) @unlink($dst_path);
                    rename($src_path, $dst_path);
                }
            }
        }
        closedir($dir);
    }

    // ---------------------------------------------------------
    // 4. DATABASE MIGRATIONS
    // ---------------------------------------------------------
    if (!empty($manifest['database_queries'])) {
        if (!$connection_server) {
            throw new Exception("Database connection is not available for running migrations.");
        }
        foreach ($manifest['database_queries'] as $query) {
            if (empty(trim($query))) continue;
            
            // Execute table modifications with error suppression for pre-existing items
            try {
                $q_res = mysqli_query($connection_server, $query);
                if (!$q_res) {
                    $err_msg = mysqli_error($connection_server);
                    // Ignore safe errors (such as pre-existing columns or tables)
                    $ignore_error = false;
                    $safe_patterns = [
                        'duplicate column name',
                        'already exists',
                        'duplicate key name',
                        'multiple primary keys'
                    ];
                    foreach ($safe_patterns as $pattern) {
                        if (stripos($err_msg, $pattern) !== false) {
                            $ignore_error = true;
                            break;
                        }
                    }
                    if (!$ignore_error) {
                        throw new Exception("Migration query failed: {$query}. Error: {$err_msg}");
                    }
                }
            } catch (Exception $db_ex) {
                // If it is a real failure, bubble up
                throw new Exception($db_ex->getMessage());
            }
        }
    }

    // ---------------------------------------------------------
    // 5. UPDATE SYSTEM SETTINGS (VERSION)
    // ---------------------------------------------------------
    $new_version = trim($manifest['version']);
    
    // Save version inside options table
    mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('system_version', '{$new_version}') ON DUPLICATE KEY UPDATE option_value='{$new_version}'");

    // Clean up temporary files on success
    cleanup_temp_files();

    ob_end_clean();
    echo json_encode([
        'status' => 'success',
        'message' => 'System successfully updated to version ' . $new_version
    ]);
    exit;

} catch (Exception $e) {
    // ---------------------------------------------------------
    // CRITICAL EXCEPTION: EXECUTE ROLLBACK
    // ---------------------------------------------------------
    $error_msg = $e->getMessage();
    $rollback_status = restore_backup($backup_zip, $backup_sql, $connection_server);
    
    cleanup_temp_files();

    ob_end_clean();
    if ($rollback_status === true) {
        echo json_encode([
            'status' => 'error',
            'message' => "Update failed: {$error_msg}.<br><b>Your system files and database have been successfully restored to their pre-update state.</b>"
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => "Update failed: {$error_msg}.<br><b>CRITICAL WARNING:</b> Automated rollback failed ({$rollback_status}). Please restore your files and database manually using the backups in the '/backups' directory."
        ]);
    }
    exit;
}

// Helper to remove directory recursively (used for staging cleanup)
function remove_directory($dir) {
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($dir);
}
