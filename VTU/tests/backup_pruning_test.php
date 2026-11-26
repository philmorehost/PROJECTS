<?php
/**
 * Behavioral & contract tests for system update backup pruning.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$checks = 0;
function bp_ok($cond, $label) {
    global $checks;
    $checks++;
    if (!$cond) {
        throw new RuntimeException("FAIL: $label");
    }
}

$saas = file_get_contents("$root/DGV7.0-SAAS/func/bc-backup-prune.php");
$non  = file_get_contents("$root/DGV7.0-NON-SAAS/func/bc-backup-prune.php");
bp_ok($saas !== false && $saas === $non, 'SAAS and NON-SAAS bc-backup-prune.php are identical');

require_once "$root/DGV7.0-SAAS/func/bc-backup-prune.php";

$tmp = sys_get_temp_dir() . '/bc_backup_prune_' . bin2hex(random_bytes(4));
mkdir($tmp, 0755, true);

try {
    file_put_contents("$tmp/.htaccess", "Deny from all\n");
    file_put_contents("$tmp/index.php", "<?php http_response_code(403);\n");

    $now = time();
    $stamps = [
        ['ts' => '2026-10-01_10-00-00', 'mtime' => $now - (30 * 86400)], // expired > 14 days
        ['ts' => '2026-11-20_10-00-00', 'mtime' => $now - (6 * 86400)],
        ['ts' => '2026-11-22_10-00-00', 'mtime' => $now - (4 * 86400)],
        ['ts' => '2026-11-24_10-00-00', 'mtime' => $now - (2 * 86400)],
        ['ts' => '2026-11-25_10-00-00', 'mtime' => $now - 3600],
    ];
    foreach ($stamps as $s) {
        $z = "$tmp/files_backup_{$s['ts']}.zip";
        $d = "$tmp/db_backup_{$s['ts']}.sql";
        file_put_contents($z, 'zip');
        file_put_contents($d, 'sql');
        touch($z, $s['mtime']);
        touch($d, $s['mtime']);
    }
    // Stray legacy archive
    file_put_contents("$tmp/legacy_manual_backup.zip", 'old');
    touch("$tmp/legacy_manual_backup.zip", $now - (3 * 86400));

    // Pre-backup prune: keep at most 1 prior pair
    $deletedPre = bc_prune_old_backups($tmp, 1, 14);
    bp_ok($deletedPre === 9, "Pre-backup prune deleted 9 files (got $deletedPre)");
    bp_ok(is_file("$tmp/.htaccess") && is_file("$tmp/index.php"), '.htaccess and index.php preserved');
    bp_ok(is_file("$tmp/files_backup_2026-11-25_10-00-00.zip") && is_file("$tmp/db_backup_2026-11-25_10-00-00.sql"), 'Newest prior backup pair kept');
    bp_ok(!is_file("$tmp/legacy_manual_backup.zip"), 'Legacy stray backup deleted');

    // Simulate new backup created now + 2 more recent ones
    foreach (['2026-11-26_07-00-00' => $now - 600, '2026-11-26_07-30-00' => $now - 300] as $ts => $mt) {
        file_put_contents("$tmp/files_backup_{$ts}.zip", 'zip');
        file_put_contents("$tmp/db_backup_{$ts}.sql", 'sql');
        touch("$tmp/files_backup_{$ts}.zip", $mt);
        touch("$tmp/db_backup_{$ts}.sql", $mt);
    }
    $newZip = 'files_backup_2026-11-26_08-00-00.zip';
    $newSql = 'db_backup_2026-11-26_08-00-00.sql';
    file_put_contents("$tmp/$newZip", 'zip');
    file_put_contents("$tmp/$newSql", 'sql');
    touch("$tmp/$newZip", $now - 10);
    touch("$tmp/$newSql", $now - 10);

    // Post-backup prune: keep at most 2 pairs while protecting the current run's pair
    $deletedPost = bc_prune_old_backups($tmp, 2, 14, [$newZip, $newSql]);
    bp_ok($deletedPost === 4, "Post-backup prune deleted 4 older files (got $deletedPost)");
    bp_ok(is_file("$tmp/$newZip") && is_file("$tmp/$newSql"), 'Protected current backup pair preserved');
    bp_ok(is_file("$tmp/files_backup_2026-11-26_07-30-00.zip") && is_file("$tmp/db_backup_2026-11-26_07-30-00.sql"), 'Second newest pair preserved');
    bp_ok(!is_file("$tmp/files_backup_2026-11-25_10-00-00.zip") && !is_file("$tmp/files_backup_2026-11-26_07-00-00.zip"), 'Older pairs pruned');

    // Verify expired backup (> 14 days) is pruned even when keep=5
    file_put_contents("$tmp/files_backup_2026-10-10_00-00-00.zip", 'old');
    touch("$tmp/files_backup_2026-10-10_00-00-00.zip", $now - (20 * 86400));
    $deletedExp = bc_prune_old_backups($tmp, 5, 14, [$newZip, $newSql]);
    bp_ok($deletedExp === 1 && !is_file("$tmp/files_backup_2026-10-10_00-00-00.zip"), 'Expired backup pruned even under keep cap');
} finally {
    foreach (glob("$tmp/*") ?: [] as $f) {
        @unlink($f);
    }
    @unlink("$tmp/.htaccess");
    @rmdir($tmp);
}

// Contract checks across all 6 update scripts
$scripts = [
    "$root/DGV7.0-SAAS/bc-spadmin/ajax_backup.php",
    "$root/DGV7.0-SAAS/bc-spadmin/execute_update.php",
    "$root/DGV7.0-SAAS/bc-spadmin/cron_update.php",
    "$root/DGV7.0-NON-SAAS/bc-admin/ajax_backup.php",
    "$root/DGV7.0-NON-SAAS/bc-admin/execute_update.php",
    "$root/DGV7.0-NON-SAAS/bc-admin/cron_update.php",
];
foreach ($scripts as $s) {
    $c = file_get_contents($s);
    bp_ok($c !== false && strpos($c, 'bc-backup-prune.php') !== false && strpos($c, 'bc_prune_old_backups(') !== false, "Wired bc_prune_old_backups in $s");
}

$ex = bc_backup_excluded_prefixes();
bp_ok(in_array('backups/', $ex, true) && in_array('jikabiz-android/', $ex, true) && in_array('func/cache/', $ex, true), 'Excluded prefixes cover backups and large/cache dirs');

echo "PASS $checks backup pruning checks\n";
