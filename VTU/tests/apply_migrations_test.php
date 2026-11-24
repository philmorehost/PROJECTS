<?php
$_SERVER['SCRIPT_FILENAME'] = '';
require_once __DIR__ . '/../bin/apply-migrations.php';

$n = 0;
function amt_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$plan = bc_migrations_plan(dirname(__DIR__, 2));
amt_ok($plan['ok'] === true && $plan['count'] >= 17, 'manifest-verified migration plan loads all migrations');
foreach ($plan['migrations'] as $item) {
    amt_ok(strlen($item['sha256']) === 64 && $item['bytes'] > 0, "verified sha256 for {$item['path']}");
}

$stmts = bc_migrations_split_sql("-- comment\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (id INT);");
amt_ok(count($stmts) === 2 && strpos($stmts[0], 'CREATE TABLE a') === 0, 'SQL splitter strips comments and splits statements');

echo "PASS $n apply-migrations offline policy checks\n";
