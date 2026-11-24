<?php
require_once __DIR__ . '/../install/schema-autoheal.php';

$n = 0;
function pim_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$ordered = payhub_install_ordered_migrations();
pim_ok(count($ordered) === 7, 'PayHub installer covers all 7 20261118 migrations');
pim_ok(array_search('20261118_webhook_log_compat.sql', $ordered, true) < array_search('20261118_webhook_retry_claims.sql', $ordered, true), 'log_compat precedes retry_claims');
pim_ok(array_search('20261118_webhook_retry_claims.sql', $ordered, true) < array_search('20261118_webhook_initial_claims.sql', $ordered, true), 'retry_claims precedes initial_claims');
pim_ok(array_search('20261118_webhook_reviews.sql', $ordered, true) < array_search('20261118_webhook_reconciliations.sql', $ordered, true), 'reviews precedes reconciliations');

foreach ($ordered as $f) {
    $path = __DIR__ . '/../install/' . $f;
    pim_ok(is_file($path), "migration file exists: $f");
    $stmts = payhub_install_split_sql((string)file_get_contents($path));
    pim_ok(count($stmts) >= 1, "SQL statements parsed cleanly for $f");
}

foreach (['install/index.php', 'install/migrate.php'] as $rel) {
    $src = (string)file_get_contents(__DIR__ . '/../' . $rel);
    pim_ok(strpos($src, 'payhub_install_apply_migrations') !== false, "PayHub migration helper wired into $rel");
}

echo "PASS $n PayHub installer migration policy checks\n";
