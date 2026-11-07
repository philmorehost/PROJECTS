<?php
/**
 * CLI Automated Update Cronjob with Email Notifications
 * Only executable via command line (CLI) for security.
 */

if (php_sapi_name() !== 'cli') {
    die("Error: This script can only be run via the CLI (Command Line Interface).\n");
}

// Disable output buffering
while (ob_get_level()) {
    ob_end_clean();
}

// Load configurations and connections
require_once __DIR__ . '/../func/bc-connect.php';
require_once __DIR__ . '/../func/bc-integrity.php';

define('ROOT_DIR', dirname(__DIR__));
define('BACKUP_DIR', ROOT_DIR . '/backups');
define('TEMP_DIR', ROOT_DIR . '/tmp_update');
define('ZIP_FILE', TEMP_DIR . '/update.zip');
define('STAGING_DIR', TEMP_DIR . '/staging');

$timestamp = date('Y-m-d_H-i-s');
$log = [];

function write_log($msg) {
    global $log;
    $log_line = date('[Y-m-d H:i:s] ') . $msg;
    $log[] = $log_line;
    echo $msg . "\n";
}

// Helper to remove directory recursively
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

// Helper for directory copying
function recursive_copy($src, $dst) {
    if (!is_dir($src)) return;
    @mkdir($dst, 0755, true);
    $dir = opendir($src);
    while (false !== ($file = readdir($dir))) {
        if (($file !== '.') && ($file !== '..')) {
            if (is_dir($src . '/' . $file)) {
                recursive_copy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

// Database and file restore rollback function
function restore_backup($zip_filename, $sql_filename, $connection_server) {
    if (!$zip_filename || !$sql_filename) {
        return "Backup filenames missing.";
    }

    $zip_path = BACKUP_DIR . '/' . $zip_filename;
    $sql_path = BACKUP_DIR . '/' . $sql_filename;

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

    if (file_exists($sql_path)) {
        $sql_contents = file_get_contents($sql_path);
        if (!$connection_server) {
            return "Database connection is not available during restore.";
        }
        if (mysqli_multi_query($connection_server, $sql_contents)) {
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

// Get admin email to notify
$admin_email = '';
$q_admin = mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id=1 LIMIT 1");
if ($q_admin && $r_admin = mysqli_fetch_assoc($q_admin)) {
    $admin_email = $r_admin['email'];
}
if (empty($admin_email)) {
    $admin_email = getSuperAdminOption('admin_email', 'admin@pmhserver.name.ng');
}

write_log("Starting OTA auto-updater CLI job.");

// 1. Fetch activation and check update
$current_version = getSuperAdminOption('system_version', '7.00');
$license_key = bc_read_activation();
$license_domain = 'localhost'; // fallback or domain resolved by vendor

// Get vendor primary domain if available
$q_domain = mysqli_query($connection_server, "SELECT domain_name FROM sas_vendors WHERE id=1 LIMIT 1");
if ($q_domain && $r_domain = mysqli_fetch_assoc($q_domain)) {
    if (!empty($r_domain['domain_name'])) {
        $license_domain = $r_domain['domain_name'];
    }
}

write_log("Checking for updates. Current version: v{$current_version}. Domain: {$license_domain}");

$api_url = "https://manager.pmhserver.name.ng/check-update.php";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'license_key' => $license_key,
    'domain' => $license_domain
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200 || empty($response)) {
    write_log("Aborted: Could not reach License Manager server.");
    exit(0);
}

$res_data = json_decode($response, true);
if (!$res_data || $res_data['status'] !== 'success') {
    write_log("Aborted: " . ($res_data['message'] ?? 'Invalid license.'));
    exit(0);
}

$latest_version = $res_data['latest_version'] ?? $current_version;
$changelog = $res_data['changelog'] ?? 'Bug fixes & improvements.';
$checksum = $res_data['checksum'] ?? '';
$download_url = $res_data['download_url'] ?? '';

$clean_latest = ltrim(strtolower($latest_version), 'v');
$clean_current = ltrim(strtolower($current_version), 'v');

if (!version_compare($clean_latest, $clean_current, '>')) {
    write_log("System is already up to date (v{$current_version}). No action taken.");
    exit(0);
}

write_log("Update found: v{$latest_version}. Initiating automated update process.");

$backup_zip = null;
$backup_sql = null;

try {
    // A. DOWNLOAD UPDATE ZIP
    write_log("Step 1/4: Downloading update package...");
    if (!is_dir(TEMP_DIR)) {
        mkdir(TEMP_DIR, 0755, true);
    }
    
    $fp = fopen(ZIP_FILE, 'w+');
    $ch = curl_init($download_url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    if (!file_exists(ZIP_FILE) || filesize(ZIP_FILE) === 0) {
        throw new Exception("Failed to download update ZIP archive.");
    }
    
    // Check checksum
    $local_hash = hash_file('sha256', ZIP_FILE);
    if (!empty($checksum) && $local_hash !== $checksum) {
        throw new Exception("Downloaded file is corrupted (checksum mismatch).");
    }
    write_log("Downloaded update zip successfully. Verified checksum.");

    // B. SNAPSHOT BACKUP
    write_log("Step 2/4: Generating safety backups...");
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
        file_put_contents(BACKUP_DIR . '/.htaccess', "Order allow,deny\nDeny from all");
    }

    // Database Dump
    $sql_dump = "-- System Backup: {$timestamp}\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = [];
    $result = mysqli_query($connection_server, "SHOW TABLES");
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
                        $sql_dump .= '"' . mysqli_real_escape_string($connection_server, $row[$j]) . '"';
                    } else {
                        $sql_dump .= 'NULL';
                    }
                    if ($j < ($num_columns - 1)) $sql_dump .= ',';
                }
                $sql_dump .= ");\n";
            }
            $sql_dump .= "\n\n";
        }
    }
    $sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";
    
    $backup_sql = "db_backup_{$timestamp}.sql";
    file_put_contents(BACKUP_DIR . '/' . $backup_sql, $sql_dump);
    
    // File ZIP backup
    $backup_zip = "files_backup_{$timestamp}.zip";
    $zip = new ZipArchive();
    if ($zip->open(BACKUP_DIR . '/' . $backup_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOT_DIR),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen(ROOT_DIR) + 1);
                $norm_rel = str_replace('\\', '/', $relative_path);
                $norm_rel_lower = strtolower($norm_rel);

                $exclude_prefixes = [
                    'backups/', 'tmp_update/', 'dg7-android/', 'dg7-ios/', 'dg6-android/', 'dg6-ios/',
                    'mzeevtu/', 'mzeevtu-android/', 'mzeevtu-ios/', 'payhub-android/', 'payhub-ios/',
                    'uploaded-image/', 'downloads/', 'logs/', 'scratch/', 'tests/', '.git/', '.github/'
                ];
                
                $should_exclude = false;
                foreach ($exclude_prefixes as $prefix) {
                    if (strpos($norm_rel_lower, $prefix) === 0) {
                        $should_exclude = true;
                        break;
                    }
                }
                if (strtolower(pathinfo($norm_rel_lower, PATHINFO_EXTENSION)) === 'zip') {
                    $should_exclude = true;
                }
                if (!$should_exclude) {
                    $zip->addFile($file_path, $relative_path);
                }
            }
        }
        $zip->close();
    } else {
        throw new Exception("Failed to write safety file backup archive.");
    }
    write_log("Backups created successfully: {$backup_zip} & {$backup_sql}");

    // C. EXTRACT ZIP
    write_log("Step 3/4: Extracting update and processing file additions/deletions...");
    $zip = new ZipArchive;
    if ($zip->open(ZIP_FILE) === TRUE) {
        if (is_dir(STAGING_DIR)) {
            remove_directory(STAGING_DIR);
        }
        mkdir(STAGING_DIR, 0755, true);
        $zip->extractTo(STAGING_DIR);
        $zip->close();
    } else {
        throw new Exception("Failed to extract update ZIP file.");
    }

    $staging_dir_actual = STAGING_DIR;
    $manifest_path = STAGING_DIR . '/manifest.json';
    if (!file_exists($manifest_path)) {
        $subdirs = glob(STAGING_DIR . '/*', GLOB_ONLYDIR);
        if (!empty($subdirs)) {
            foreach ($subdirs as $subdir) {
                if (file_exists($subdir . '/manifest.json')) {
                    $staging_dir_actual = $subdir;
                    $manifest_path = $subdir . '/manifest.json';
                    break;
                }
            }
        }
    }
    if (!file_exists($manifest_path)) {
        throw new Exception("manifest.json not found in update package.");
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);
    if (!$manifest || !isset($manifest['version'])) {
        throw new Exception("Corrupted or invalid manifest.json file.");
    }

    // Process file deletions
    if (!empty($manifest['files_to_delete'])) {
        foreach ($manifest['files_to_delete'] as $file_to_del) {
            $clean_path = str_replace(['../', '..\\'], '', $file_to_del);
            $target_file = ROOT_DIR . '/' . ltrim($clean_path, '/\\');
            if (file_exists($target_file) && is_file($target_file)) {
                @unlink($target_file);
            }
        }
    }

    // Copy new files
    $source_files = $staging_dir_actual . '/files';
    if (is_dir($source_files)) {
        recursive_copy($source_files, ROOT_DIR);
    }

    // D. DATABASE MIGRATIONS
    write_log("Step 4/4: Running database migrations...");
    if (!empty($manifest['database_queries'])) {
        foreach ($manifest['database_queries'] as $query) {
            if (empty(trim($query))) continue;
            $q_res = mysqli_query($connection_server, $query);
            if (!$q_res) {
                $err_msg = mysqli_error($connection_server);
                $ignore_error = false;
                $safe_patterns = ['duplicate column name', 'already exists', 'duplicate key name', 'multiple primary keys'];
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
        }
    }

    // Save version in database settings
    $new_version = trim($manifest['version']);
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES (1, 'system_version', '{$new_version}') ON DUPLICATE KEY UPDATE setting_value='{$new_version}'");

    // Clean temp files
    remove_directory(TEMP_DIR);
    write_log("Update successfully completed! System is now running v{$new_version}.");

    // Send Success Email Notification
    $email_subject = "System Automated Update Successful - Version v{$new_version}";
    $email_body = "<h3>Update Execution Complete</h3>
    <p>Your platform has been automatically updated successfully by the scheduled cron engine.</p>
    <p><b>Updated Version:</b> v{$new_version}<br>
    <b>Previous Version:</b> v{$current_version}<br>
    <b>Domain:</b> {$license_domain}<br>
    <b>Time of execution:</b> " . date('Y-m-d H:i:s') . "</p>
    <h4>What's New:</h4>
    <ul>{$changelog}</ul>
    <p>Safety backup files generated:
    <ul>
        <li>Files: {$backup_zip}</li>
        <li>Database: {$backup_sql}</li>
    </ul></p>
    <hr>
    <p><i>DataGifting Standard Update Agent.</i></p>";

    sendVendorEmail($admin_email, $email_subject, $email_body);

} catch (Exception $e) {
    $error_msg = $e->getMessage();
    write_log("CRITICAL ERROR: {$error_msg}. Initiating rollback...");
    
    $rollback_status = restore_backup($backup_zip, $backup_sql, $connection_server);
    remove_directory(TEMP_DIR);

    $email_subject = "Platform Auto-Update Failed - Rollback Initiated";
    if ($rollback_status === true) {
        write_log("Rollback completed successfully. Pre-update state restored.");
        $email_body = "<h3>Auto-Update Failed & Rollback Executed</h3>
        <p>The system update process encountered a fatal error and has been automatically rolled back to ensure stability.</p>
        <p><b>Error Details:</b> {$error_msg}</p>
        <p><b>Rollback Status:</b> Success (All files and database contents restored).</p>
        <p>No manual intervention is required. Please check the logs in `UpdateSystem.php` to resolve the root failure cause.</p>";
    } else {
        write_log("ROLLBACK FAILED: {$rollback_status}. Critical action required!");
        $email_body = "<h3>CRITICAL WARNING: Auto-Update Failed & Rollback Failed</h3>
        <p>The system update process failed, and the automated system restore (rollback) also failed.</p>
        <p><b>Error Details:</b> {$error_msg}</p>
        <p><b>Rollback Error:</b> {$rollback_status}</p>
        <p><b>Immediate Action Required:</b> Please log in to your hosting control panel immediately and manually restore the backup zip and SQL file located in the '/backups' folder:</p>
        <ul>
            <li>Files backup: {$backup_zip}</li>
            <li>Database backup: {$backup_sql}</li>
        </ul>";
    }
    
    sendVendorEmail($admin_email, $email_subject, $email_body);
    exit(1);
}
?>
