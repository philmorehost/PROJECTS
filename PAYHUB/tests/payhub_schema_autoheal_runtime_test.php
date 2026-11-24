<?php
$n = 0;
function ph_heal_check($ok, $msg) {
    global $n;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    $n++;
}

$dbSrc = file_get_contents(__DIR__ . '/../includes/db.php');
$whSrc = file_get_contents(__DIR__ . '/../admin/webhooks.php');

ph_heal_check(strpos($dbSrc, 'schema-autoheal.php') !== false, 'Database::connect loads schema-autoheal.php');
ph_heal_check(strpos($dbSrc, 'payhub_install_apply_migrations(') !== false, 'Database::connect invokes payhub_install_apply_migrations');
ph_heal_check(strpos($dbSrc, 'inTransaction()') !== false, 'Database::connect avoids auto-healing inside active transactions');
ph_heal_check(strpos($whSrc, 'schema-autoheal.php') !== false, 'admin/webhooks.php loads schema-autoheal.php on missing table');
ph_heal_check(strpos($whSrc, 'payhub_install_apply_migrations(') !== false, 'admin/webhooks.php invokes payhub_install_apply_migrations');
ph_heal_check(strpos($whSrc, 'json_encode(') === false, 'admin/webhooks.php keeps raw payload signing contract (no json_encode)');

if (extension_loaded('pdo_sqlite')) {
    require_once __DIR__ . '/../install/schema-autoheal.php';
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $refused = payhub_install_apply_migrations($pdo);
    ph_heal_check(($refused['ok'] ?? true) === false && ($refused['code'] ?? '') === 'outer_transaction_refused', 'auto-heal refuses inner transaction');
    $pdo->rollBack();
}

echo "PASS $n PayHub runtime schema auto-heal checks\n";
