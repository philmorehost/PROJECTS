<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-schema-autoheal.php';

$n = 0;
function sah_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$saas = file_get_contents(__DIR__ . '/../DGV7.0-SAAS/func/bc-schema-autoheal.php');
$non  = file_get_contents(__DIR__ . '/../DGV7.0-NON-SAAS/func/bc-schema-autoheal.php');
sah_ok($saas === $non, 'edition parity for bc-schema-autoheal.php');

$stmts = bc_schema_autoheal_split_sql("-- comment\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (id INT);");
sah_ok(count($stmts) === 2 && strpos($stmts[0], 'CREATE TABLE a') === 0, 'SQL splitter strips comments and splits statements');

$dir = bc_schema_autoheal_resolve_migrations_dir(__DIR__ . '/../migrations');
sah_ok($dir !== null && is_dir($dir), 'resolves VTU/migrations directory');
$files = bc_schema_autoheal_list_files($dir);
sah_ok(count($files) >= 10, 'lists all VTU/migrations SQL files');

// Standalone edition migrations/ mirrors must match VTU/migrations byte-for-byte
$canonicalSqls = glob(__DIR__ . '/../migrations/20261118_*.sql');
sort($canonicalSqls, SORT_STRING);
foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $edMigDir = __DIR__ . '/../' . $ed . '/migrations';
    sah_ok(is_dir($edMigDir), "standalone migrations directory exists in $ed");
    sah_ok(is_file($edMigDir . '/.htaccess') && strpos((string)file_get_contents($edMigDir . '/.htaccess'), 'Require all denied') !== false, "migrations/.htaccess denies HTTP in $ed");
    foreach ($canonicalSqls as $srcSql) {
        $base = basename($srcSql);
        $copy = $edMigDir . '/' . $base;
        sah_ok(is_file($copy) && hash_file('sha256', $srcSql) === hash_file('sha256', $copy), "3-way migration parity for $ed/$base");
    }
    $edList = bc_schema_autoheal_list_files($edMigDir);
    sah_ok(count($edList) === count($canonicalSqls), "standalone list matches canonical count in $ed");
}

foreach ([
    'DGV7.0-SAAS/func/bc-tables.php',
    'DGV7.0-NON-SAAS/func/bc-tables.php',
    'DGV7.0-SAAS/bc-spadmin/execute_update.php',
    'DGV7.0-SAAS/bc-spadmin/cron_update.php',
    'DGV7.0-NON-SAAS/bc-admin/execute_update.php',
    'DGV7.0-NON-SAAS/bc-admin/cron_update.php',
] as $rel) {
    $src = file_get_contents(__DIR__ . '/../' . $rel);
    sah_ok(strpos($src, 'bc_schema_autoheal') !== false, "autoheal wired into $rel");
}

echo "PASS $n schema auto-heal policy checks\n";
