<?php
/**
 * Safe Platform Cleanup & Database Optimizer Utility (SAAS Version)
 * Executable via CLI or Web (requires super admin login).
 * Defaults to safe "Dry-Run" Preview Mode.
 */

// Determine execution environment
$is_cli = (php_sapi_name() === 'cli');

if ($is_cli) {
    // CLI arguments
    $execute = in_array('--execute', $argv);
} else {
    // Web execution session verification (SAAS session is admin_session too)
    session_start();
    if (!isset($_SESSION["admin_session"])) {
        http_response_code(403);
        die("<h3>Error 403: Unauthorized Access</h3><p>Only logged-in administrators can execute this utility.</p>");
    }
    $execute = isset($_GET['execute']) && $_GET['execute'] == '1';
}

// Load configurations and connections
require_once __DIR__ . '/../func/bc-connect.php';

define('ROOT_DIR', dirname(__DIR__));
define('BACKUP_DIR', ROOT_DIR . '/backups');
define('TEMP_DIR', ROOT_DIR . '/tmp_update');

$timestamp = date('Y-m-d H:i:s');
$pruned_files = [];
$pruned_db = [];
$errors = [];

// Header formatting
if ($is_cli) {
    echo "========================================================\n";
    echo "    DataGifting SAAS Platform Cleanup Tool\n";
    echo "    Mode: " . ($execute ? "EXECUTE (Pruning files & DB)" : "DRY-RUN (Preview Only)") . "\n";
    echo "========================================================\n\n";
} else {
    echo "<!DOCTYPE html><html><head><title>System Cleanup Utility</title>";
    echo "<link href='../assets-2/vendor/bootstrap/css/bootstrap.min.css' rel='stylesheet'></head><body class='container p-4 bg-light'>";
    echo "<div class='card shadow-sm border-0 rounded-4 p-5 mb-4 bg-white'>";
    echo "<h2 class='fw-bold mb-1 text-primary'>SAAS Platform Cleanup & Optimization Tool</h2>";
    echo "<p class='text-muted'>Mode: <strong>" . ($execute ? "EXECUTE (Applying changes)" : "DRY-RUN (Preview Mode)") . "</strong></p><hr>";
}

// ---------------------------------------------------------
// 1. FILE CLEANUP AUDIT
// ---------------------------------------------------------
// Audit Temp Staging Directory
if (is_dir(TEMP_DIR)) {
    $staging_size = 0;
    $staging_files_count = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(TEMP_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $staging_size += $fileinfo->getSize();
        $staging_files_count++;
    }
    
    $pruned_files[] = [
        'path' => TEMP_DIR,
        'description' => "Leftover OTA update temporary directory ({$staging_files_count} files, " . number_format($staging_size / 1024 / 1024, 2) . " MB)",
        'action' => 'delete_dir'
    ];
}

// Audit Old Backups (older than 14 days)
if (is_dir(BACKUP_DIR)) {
    $now = time();
    $cutoff = $now - (14 * 24 * 60 * 60); // 14 days
    $iterator = new DirectoryIterator(BACKUP_DIR);
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isFile() && in_array(strtolower($fileinfo->getExtension()), ['zip', 'sql'])) {
            if ($fileinfo->getMTime() < $cutoff) {
                $pruned_files[] = [
                    'path' => $fileinfo->getRealPath(),
                    'description' => "System backup archive older than 14 days (Created: " . date('Y-m-d', $fileinfo->getMTime()) . ", Size: " . number_format($fileinfo->getSize() / 1024 / 1024, 2) . " MB)",
                    'action' => 'delete_file'
                ];
            }
        }
    }
}

// Audit Leftover Debug Logs
$log_files = [
    ROOT_DIR . '/bc-spadmin/update_debug.log',
    ROOT_DIR . '/bc-spadmin/api_debug.log',
    ROOT_DIR . '/bc-spadmin/zip_contents.log',
    ROOT_DIR . '/fail-log.txt',
    ROOT_DIR . '/api_debug.log',
    ROOT_DIR . '/update_debug.log'
];

foreach ($log_files as $lf) {
    if (file_exists($lf) && is_file($lf)) {
        $pruned_files[] = [
            'path' => $lf,
            'description' => "Leftover server debug/failure log file (Size: " . number_format(filesize($lf) / 1024, 2) . " KB)",
            'action' => 'delete_file'
        ];
    }
}

// ---------------------------------------------------------
// 2. DATABASE LOG AUDIT (Older than 90 days)
// ---------------------------------------------------------
if ($connection_server) {
    $cutoff_date = date('Y-m-d H:i:s', time() - (90 * 24 * 60 * 60));

    // Audit sas_vendor_status_messages
    $q1 = mysqli_query($connection_server, "SELECT COUNT(*) as cnt FROM sas_vendor_status_messages WHERE date < '$cutoff_date'");
    $cnt1 = ($q1 && $r1 = mysqli_fetch_assoc($q1)) ? (int)$r1['cnt'] : 0;
    if ($cnt1 > 0) {
        $pruned_db[] = [
            'table' => 'sas_vendor_status_messages',
            'description' => "Vendor status/log messages older than 90 days ({$cnt1} records)",
            'query' => "DELETE FROM sas_vendor_status_messages WHERE date < '$cutoff_date'"
        ];
    }

    // Audit sas_super_admin_status_messages
    $q2 = mysqli_query($connection_server, "SELECT COUNT(*) as cnt FROM sas_super_admin_status_messages WHERE date < '$cutoff_date'");
    $cnt2 = ($q2 && $r2 = mysqli_fetch_assoc($q2)) ? (int)$r2['cnt'] : 0;
    if ($cnt2 > 0) {
        $pruned_db[] = [
            'table' => 'sas_super_admin_status_messages',
            'description' => "Super Admin audit messages older than 90 days ({$cnt2} records)",
            'query' => "DELETE FROM sas_super_admin_status_messages WHERE date < '$cutoff_date'"
        ];
    }
}

// ---------------------------------------------------------
// 2.5. INTELLIGENT SCAN (ADVISORY ONLY — never wired to $execute)
// ---------------------------------------------------------
// Everything below is report-only. It never deletes a file or drops/alters a
// table itself — the worst it can suggest is a reversible table *rename* to a
// `_deprecated_` prefix, and even that requires a separate manual step, not a
// button in this tool. This is intentional: a naive "0 references found" check
// is not reliable enough to drive automatic deletion on this codebase (dynamic
// includes and string-built table/gateway names are used throughout), so these
// findings are always framed as "review manually," never "safe to auto-clean."

define('SCAN_TIME_BUDGET_SECONDS', 25);
$scan_start_time = microtime(true);
$scan_truncated = false;

function scan_collect_php_files($dirs) {
    $files = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $files[] = $f->getRealPath();
            }
        }
    }
    return array_values(array_unique($files));
}

$intel_scan_dirs = [
    ROOT_DIR . '/bc-admin',
    ROOT_DIR . '/bc-spadmin',
    ROOT_DIR . '/web',
    ROOT_DIR . '/func',
    ROOT_DIR . '/api',
    ROOT_DIR . '/cron',
];
$intel_scan_files = scan_collect_php_files($intel_scan_dirs);
foreach (glob(ROOT_DIR . '/*.php') as $rootPhp) { $intel_scan_files[] = $rootPhp; }
$intel_scan_files = array_values(array_unique($intel_scan_files));

// Read every candidate file's contents once, and build one combined haystack for fast
// substring counting instead of comparing every file against every other file (O(n^2)).
$intel_file_contents = [];
foreach ($intel_scan_files as $path) {
    $c = @file_get_contents($path);
    $intel_file_contents[$path] = $c === false ? '' : strtolower($c);
}
$intel_combined_haystack = implode("\n", $intel_file_contents);

// Never flag entry points, this tool itself, or files legitimately only reached via
// cron/CLI (no other .php file would ever mention them by name).
$intel_never_flag = [
    'index.php', 'cleanup_platform.php', 'install.php', 'db.php', 'config.php',
];
$intel_never_flag_dirs = ['/cron/']; // cron scripts are invoked by the OS crontab, not referenced in-app

$unreferenced_files = [];
foreach ($intel_scan_files as $path) {
    if ((microtime(true) - $scan_start_time) > SCAN_TIME_BUDGET_SECONDS) { $scan_truncated = true; break; }

    $basename = basename($path);
    if (in_array(strtolower($basename), $intel_never_flag, true)) continue;
    $skip_dir = false;
    foreach ($intel_never_flag_dirs as $nd) { if (strpos($path, $nd) !== false) { $skip_dir = true; break; } }
    if ($skip_dir) continue;

    $needle = strtolower($basename);
    $self_count = substr_count($intel_file_contents[$path], $needle);
    $total_count = substr_count($intel_combined_haystack, $needle);

    if (($total_count - $self_count) <= 0) {
        $unreferenced_files[] = [
            'relative' => str_replace(ROOT_DIR . DIRECTORY_SEPARATOR, '', str_replace(ROOT_DIR . '/', '', $path)),
            'size_kb'  => round(filesize($path) / 1024, 1),
            'modified' => date('Y-m-d', filemtime($path)),
        ];
    }
}

// Duplicate CREATE TABLE IF NOT EXISTS statements, and the full set of table names this
// install actually defines (used again below for the zero-reference table scan).
$create_table_locations = []; // table_name => [ [file, line], ... ]
foreach ($intel_scan_files as $path) {
    if ((microtime(true) - $scan_start_time) > SCAN_TIME_BUDGET_SECONDS) { $scan_truncated = true; break; }
    $raw = @file_get_contents($path);
    if ($raw === false) continue;
    if (stripos($raw, 'CREATE TABLE') === false) continue; // fast skip before the more expensive per-line regex
    $lines = explode("\n", $raw);
    foreach ($lines as $i => $line) {
        if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s*`?(\w+)`?/i', $line, $m)) {
            $create_table_locations[$m[1]][] = [
                'file' => str_replace(ROOT_DIR . '/', '', $path),
                'line' => $i + 1,
            ];
        }
    }
}

$duplicate_table_definitions = [];
foreach ($create_table_locations as $table => $locations) {
    if (count($locations) > 1) {
        $duplicate_table_definitions[$table] = $locations;
    }
}

// Zero-reference tables: a table's own CREATE TABLE statement(s) account for exactly
// count($create_table_locations[$table]) occurrences of its name — anything beyond that
// in the combined haystack means it's actually queried/written somewhere.
$unreferenced_tables = [];
foreach ($create_table_locations as $table => $locations) {
    if ((microtime(true) - $scan_start_time) > SCAN_TIME_BUDGET_SECONDS) { $scan_truncated = true; break; }
    $total_count = substr_count($intel_combined_haystack, strtolower($table));
    $create_count = count($locations);
    if (($total_count - $create_count) <= 0) {
        $unreferenced_tables[] = $table;
    }
}

// Platform-specific: duplicate active gateways per vendor+type, and pricing rows orphaned
// from the status table's currently-active api_id (see web/Data.php's dropdown fix — this
// surfaces the underlying data problem that fix works around, so it can be cleaned up too).
$duplicate_active_gateways = [];
$orphaned_pricing_rows = [];
if ($connection_server) {
    $dup_q = mysqli_query($connection_server, "SELECT vendor_id, api_type, COUNT(*) as cnt FROM sas_apis WHERE status=1 GROUP BY vendor_id, api_type HAVING cnt > 1");
    if ($dup_q) {
        while ($row = mysqli_fetch_assoc($dup_q)) { $duplicate_active_gateways[] = $row; }
    }

    $data_type_status_tables = [
        'shared-data' => 'sas_shared_data_status',
        'sme-data'    => 'sas_sme_data_status',
        'cg-data'     => 'sas_cg_data_status',
        'dd-data'     => 'sas_dd_data_status',
    ];
    $pricing_tables = ['sas_smart_parameter_values', 'sas_agent_parameter_values', 'sas_api_parameter_values'];
    foreach ($data_type_status_tables as $dtype => $status_table) {
        foreach ($pricing_tables as $ptable) {
            $sql = "SELECT v.vendor_id, p.product_name, v.api_id, COUNT(*) as cnt
                    FROM $ptable v
                    JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id AND a.api_type = '$dtype'
                    JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
                    LEFT JOIN $status_table st ON st.vendor_id = v.vendor_id AND st.product_name = p.product_name AND st.api_id = v.api_id
                    WHERE st.vendor_id IS NULL
                    GROUP BY v.vendor_id, p.product_name, v.api_id";
            $orphan_q = mysqli_query($connection_server, $sql);
            if ($orphan_q) {
                while ($row = mysqli_fetch_assoc($orphan_q)) {
                    $row['table'] = $ptable;
                    $row['type'] = $dtype;
                    $orphaned_pricing_rows[] = $row;
                }
            }
        }
    }
}

// ---------------------------------------------------------
// 3. EXECUTE PRUNING (IF REQUESTED)
// ---------------------------------------------------------
if ($execute) {
    // A. Perform File Deletions
    foreach ($pruned_files as $f) {
        if ($f['action'] === 'delete_file') {
            if (@unlink($f['path'])) {
                $f['status'] = 'deleted';
            } else {
                $f['status'] = 'failed';
                $errors[] = "Failed to delete file: " . $f['path'];
            }
        } elseif ($f['action'] === 'delete_dir') {
            // Recursively remove dir
            $dir = $f['path'];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            $success = true;
            foreach ($iterator as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                if (!@$todo($fileinfo->getRealPath())) {
                    $success = false;
                }
            }
            if ($success && @rmdir($dir)) {
                $f['status'] = 'deleted';
            } else {
                $f['status'] = 'failed';
                $errors[] = "Failed to fully remove directory: " . $f['path'];
            }
        }
    }

    // B. Perform Database Deletions
    if ($connection_server) {
        foreach ($pruned_db as &$db_item) {
            if (mysqli_query($connection_server, $db_item['query'])) {
                $db_item['status'] = 'pruned';
            } else {
                $db_item['status'] = 'failed';
                $errors[] = "Database prune failed on table {$db_item['table']}: " . mysqli_error($connection_server);
            }
        }
        
        // C. Database Optimize Tables
        $opt_tables = ['sas_transactions', 'sas_vendor_transactions', 'sas_vendor_status_messages', 'sas_super_admin_status_messages'];
        foreach ($opt_tables as $tbl) {
            @mysqli_query($connection_server, "OPTIMIZE TABLE `$tbl`");
        }
    }
}

// ---------------------------------------------------------
// 4. PRINT REPORT
// ---------------------------------------------------------
if ($is_cli) {
    echo "FILE CLEANUP DETAILS:\n";
    if (empty($pruned_files)) {
        echo " - No redundant files or temporary update folders found.\n";
    } else {
        foreach ($pruned_files as $f) {
            $status_str = $execute ? " [" . strtoupper($f['status'] ?? 'pending') . "]" : "";
            echo " - " . $f['description'] . $status_str . "\n   Path: " . $f['path'] . "\n";
        }
    }
    
    echo "\nDATABASE LOG OPTIMIZATION DETAILS:\n";
    if (empty($pruned_db)) {
        echo " - No logs older than 90 days found to prune.\n";
    } else {
        foreach ($pruned_db as $db_item) {
            $status_str = $execute ? " [" . strtoupper($db_item['status'] ?? 'pending') . "]" : "";
            echo " - " . $db_item['description'] . $status_str . "\n";
        }
        if ($execute) {
            echo " - Optimization ran on transactional tables.\n";
        }
    }
    
    echo "\n========================================================\n";
    echo "INTELLIGENT SCAN — ADVISORY ONLY, REVIEW MANUALLY BEFORE ACTING\n";
    echo "(nothing in this section is deleted or modified by this tool)\n";
    echo "========================================================\n";

    echo "\nUnreferenced PHP files (no other .php file mentions this filename):\n";
    if (empty($unreferenced_files)) {
        echo " - None found.\n";
    } else {
        foreach ($unreferenced_files as $uf) {
            echo " - {$uf['relative']} ({$uf['size_kb']} KB, modified {$uf['modified']})\n";
        }
    }

    echo "\nDuplicate 'CREATE TABLE IF NOT EXISTS' statements (same table defined in multiple places — harmless at runtime, but redundant migration code):\n";
    if (empty($duplicate_table_definitions)) {
        echo " - None found.\n";
    } else {
        foreach ($duplicate_table_definitions as $table => $locations) {
            echo " - $table:\n";
            foreach ($locations as $loc) {
                echo "     {$loc['file']}:{$loc['line']}\n";
            }
        }
    }

    echo "\nTables with no references outside their own CREATE statement:\n";
    if (empty($unreferenced_tables)) {
        echo " - None found.\n";
    } else {
        foreach ($unreferenced_tables as $table) {
            echo " - $table\n";
        }
    }

    echo "\nVendors with more than one ENABLED API gateway of the same type (causes duplicate/failing plans on the customer Data page):\n";
    if (empty($duplicate_active_gateways)) {
        echo " - None found.\n";
    } else {
        foreach ($duplicate_active_gateways as $g) {
            echo " - Vendor #{$g['vendor_id']}, type '{$g['api_type']}': {$g['cnt']} enabled gateways\n";
        }
    }

    echo "\nPricing rows orphaned from their product's active gateway (customer could select these but a purchase will always fail):\n";
    if (empty($orphaned_pricing_rows)) {
        echo " - None found.\n";
    } else {
        foreach ($orphaned_pricing_rows as $o) {
            echo " - Vendor #{$o['vendor_id']}, {$o['product_name']} {$o['type']}, api_id {$o['api_id']}, table {$o['table']}: {$o['cnt']} row(s)\n";
        }
    }

    if ($scan_truncated) {
        echo "\nNOTE: The intelligent scan hit its time budget and stopped early — results above are partial. Re-run to continue checking.\n";
    }

    if (!empty($errors)) {
        echo "\nWARNINGS / ERRORS ENCOUNTERED:\n";
        foreach ($errors as $err) {
            echo " - [ERROR] " . $err . "\n";
        }
    }

    if (!$execute) {
        echo "\nTo apply this cleanup and optimization, run this script with the --execute flag:\n";
        echo "php cleanup_platform.php --execute\n";
    }
} else {
    // HTML Output for Admin Web Dashboard
    echo "<h4>File Cleanup Details</h4>";
    if (empty($pruned_files)) {
        echo "<div class='alert alert-light border text-muted py-2 px-3 small'>No redundant files or temporary update folders found.</div>";
    } else {
        echo "<table class='table table-sm table-striped small border'>";
        echo "<thead class='table-dark'><tr><th>Description</th><th>Path</th><th>Action Status</th></tr></thead><tbody>";
        foreach ($pruned_files as $f) {
            $status_badge = '';
            if ($execute) {
                $color = ($f['status'] ?? '') === 'deleted' ? 'success' : 'danger';
                $status_badge = "<span class='badge bg-{$color}'>" . strtoupper($f['status'] ?? 'failed') . "</span>";
            } else {
                $status_badge = "<span class='badge bg-warning'>SLATED FOR DELETION</span>";
            }
            echo "<tr><td>{$f['description']}</td><td><code style='font-size:0.85em;'>{$f['path']}</code></td><td>{$status_badge}</td></tr>";
        }
        echo "</tbody></table>";
    }

    echo "<h4 class='mt-4'>Database Log Pruning Details (Older than 90 days)</h4>";
    if (empty($pruned_db)) {
        echo "<div class='alert alert-light border text-muted py-2 px-3 small'>No log records older than 90 days found.</div>";
    } else {
        echo "<table class='table table-sm table-striped small border'>";
        echo "<thead class='table-dark'><tr><th>Table</th><th>Description</th><th>Action Status</th></tr></thead><tbody>";
        foreach ($pruned_db as $db_item) {
            $status_badge = '';
            if ($execute) {
                $color = ($db_item['status'] ?? '') === 'pruned' ? 'success' : 'danger';
                $status_badge = "<span class='badge bg-{$color}'>" . strtoupper($db_item['status'] ?? 'failed') . "</span>";
            } else {
                $status_badge = "<span class='badge bg-warning'>SLATED FOR PRUNING</span>";
            }
            echo "<tr><td><code>{$db_item['table']}</code></td><td>{$db_item['description']}</td><td>{$status_badge}</td></tr>";
        }
        echo "</tbody></table>";
    }

    echo "</div>"; // close the auto-cleanup card

    // -----------------------------------------------------
    // INTELLIGENT SCAN — advisory only, no execute affordance
    // -----------------------------------------------------
    echo "<div class='card shadow-sm border-0 rounded-4 p-5 mb-4 bg-white'>";
    echo "<h2 class='fw-bold mb-1 text-warning'><i class='bi bi-search'></i> Intelligent Scan — Advisory</h2>";
    echo "<p class='text-muted'>These findings are for manual review only. This tool does not delete files or drop/alter tables based on this section — verify each item yourself before acting on it.</p>";
    if ($scan_truncated) {
        echo "<div class='alert alert-warning small py-2 px-3'>Scan hit its time budget and stopped early — results below are partial. Re-run to continue checking.</div>";
    }
    echo "<hr>";

    echo "<h5 class='fw-bold'>Unreferenced PHP Files</h5>";
    echo "<p class='small text-muted'>No other .php file in the install mentions this filename. Could be genuinely dead code, or a file only reached via cron/CLI/external URL — verify before deleting.</p>";
    if (empty($unreferenced_files)) {
        echo "<div class='alert alert-light border text-muted py-2 px-3 small'>No unreferenced files found.</div>";
    } else {
        echo "<table class='table table-sm table-striped small border'>";
        echo "<thead class='table-dark'><tr><th>File</th><th>Size</th><th>Modified</th></tr></thead><tbody>";
        foreach ($unreferenced_files as $uf) {
            echo "<tr><td><code>{$uf['relative']}</code></td><td>{$uf['size_kb']} KB</td><td>{$uf['modified']}</td></tr>";
        }
        echo "</tbody></table>";
    }

    echo "<h5 class='fw-bold mt-4'>Duplicate Table-Creation Code</h5>";
    echo "<p class='small text-muted'>Same table defined by more than one <code>CREATE TABLE IF NOT EXISTS</code> statement. Harmless at runtime, but worth consolidating into a single migration file.</p>";
    if (empty($duplicate_table_definitions)) {
        echo "<div class='alert alert-light border text-muted py-2 px-3 small'>No duplicate table-creation statements found.</div>";
    } else {
        echo "<table class='table table-sm table-striped small border'>";
        echo "<thead class='table-dark'><tr><th>Table</th><th>Defined At</th></tr></thead><tbody>";
        foreach ($duplicate_table_definitions as $table => $locations) {
            $locs = implode('<br>', array_map(function($l) { return "<code>{$l['file']}:{$l['line']}</code>"; }, $locations));
            echo "<tr><td><code>{$table}</code></td><td>{$locs}</td></tr>";
        }
        echo "</tbody></table>";
    }

    echo "<h5 class='fw-bold mt-4'>Tables With No References Outside Their Own Creation</h5>";
    echo "<p class='small text-muted'>Verify manually before touching — this tool will never drop a table automatically. If genuinely unused, consider renaming it with a <code>_deprecated_</code> prefix instead of dropping it, so it can still be restored.</p>";
    if (empty($unreferenced_tables)) {
        echo "<div class='alert alert-light border text-muted py-2 px-3 small'>No unreferenced tables found.</div>";
    } else {
        echo "<div class='alert alert-light border py-2 px-3 small'>" . implode(', ', array_map(function($t) { return "<code>$t</code>"; }, $unreferenced_tables)) . "</div>";
    }

    echo "<h5 class='fw-bold mt-4'>Duplicate Active Data-Plan Gateways</h5>";
    echo "<p class='small text-muted'>More than one enabled API gateway of the same type for a vendor causes duplicate-looking, sometimes-failing plans on the customer Data page (the dropdown lists every enabled gateway's plans, but a purchase only resolves against the one the product's status page currently points to).</p>";
    if (empty($duplicate_active_gateways)) {
        echo "<div class='alert alert-light border text-muted py-2 px-3 small'>No vendor has duplicate active gateways of the same type.</div>";
    } else {
        echo "<table class='table table-sm table-striped small border'>";
        echo "<thead class='table-dark'><tr><th>Vendor ID</th><th>API Type</th><th>Enabled Gateways</th></tr></thead><tbody>";
        foreach ($duplicate_active_gateways as $g) {
            echo "<tr><td>{$g['vendor_id']}</td><td><code>{$g['api_type']}</code></td><td>{$g['cnt']}</td></tr>";
        }
        echo "</tbody></table>";
    }

    echo "<h5 class='fw-bold mt-4'>Orphaned Pricing Rows</h5>";
    echo "<p class='small text-muted'>Pricing rows whose <code>api_id</code> no longer matches the product's currently-active gateway per its status table. A customer could still see and select these, but the purchase will always fail.</p>";
    if (empty($orphaned_pricing_rows)) {
        echo "<div class='alert alert-light border text-muted py-2 px-3 small'>No orphaned pricing rows found.</div>";
    } else {
        echo "<table class='table table-sm table-striped small border'>";
        echo "<thead class='table-dark'><tr><th>Vendor ID</th><th>Product</th><th>Type</th><th>api_id</th><th>Table</th><th>Rows</th></tr></thead><tbody>";
        foreach ($orphaned_pricing_rows as $o) {
            echo "<tr><td>{$o['vendor_id']}</td><td>" . strtoupper($o['product_name']) . "</td><td><code>{$o['type']}</code></td><td>{$o['api_id']}</td><td><code>{$o['table']}</code></td><td>{$o['cnt']}</td></tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div>"; // close intelligent scan card

    if (!empty($errors)) {
        echo "<div class='alert alert-danger mt-4'><h5>Errors Encountered:</h5><ul class='mb-0 small'>";
        foreach ($errors as $err) {
            echo "<li>" . htmlspecialchars($err) . "</li>";
        }
        echo "</ul></div>";
    }

    if (!$execute) {
        echo "<div class='mt-4 p-4 border rounded-3 bg-light text-center'>";
        echo "<p class='mb-3'>Click below to securely execute the slated file and database cleanups:</p>";
        echo "<a href='cleanup_platform.php?execute=1' class='btn btn-primary px-4 py-2 fw-bold'>Execute Live Cleanup</a>";
        echo "</div>";
    } else {
        echo "<div class='alert alert-success mt-4 py-3 text-center'><b>Cleanup complete!</b> Database optimized successfully.</div>";
        echo "<div class='text-center mt-3'><a href='cleanup_platform.php' class='btn btn-outline-secondary btn-sm'>Back to Preview</a></div>";
    }
    echo "</body></html>";
}
?>
