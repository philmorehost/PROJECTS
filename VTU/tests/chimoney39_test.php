<?php
/**
 * §39 — MarketPlace fatal fix + total Chimoney removal.
 *
 * The fatal: bc-admin/MarketPlace.php called bc_fintech_api_has_access(),
 * which exists nowhere — the real helper is bc_fintech_api_access($vendor_id,
 * $slug) in func/bc-fintech-api-access.php. A grep for the old name must stay
 * empty so the fatal can never come back.
 *
 * Chimoney: gateway + bridge deleted, giftcard FX provider removed,
 * ProductSetUp/marketplace/API-type lists cleaned, the app services no longer
 * read its product catalogue tables (no longer created), and the live
 * virtual-card table column is renamed chimoney_card_id -> provider_card_id
 * (in-place migration, data preserved — the old name survives ONLY inside
 * that migration shim in func/bc-tables.php).
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$roots = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];

foreach ($roots as $name => $root) {
    /* --- the MarketPlace fatal ------------------------------------------ */
    $market = file_get_contents($root . '/bc-admin/MarketPlace.php');
    chk(strpos($market, 'bc_fintech_api_has_access') === false, "$name MarketPlace: phantom function gone");
    chk(strpos($market, 'bc_fintech_api_access((int)($get_logged_admin_details["id"] ?? 0), $mkt_slug)') !== false,
        "$name MarketPlace: calls bc_fintech_api_access(vendor_id, slug)");
    chk(is_file($root . '/func/bc-fintech-api-access.php') && strpos(file_get_contents($root . '/func/bc-fintech-api-access.php'), 'function bc_fintech_api_access(') !== false,
        "$name: bc_fintech_api_access() is defined");

    /* --- Chimoney is gone ------------------------------------------------- */
    chk(!file_exists($root . '/func/api-gateway/chimoney.php'), "$name: api-gateway/chimoney.php deleted");
    chk(!file_exists($root . '/includes/ChimoneyBridge.php'), "$name: includes/ChimoneyBridge.php deleted");
    chk(stripos(file_get_contents($root . '/bc-admin/ProductSetUp.php'), 'chimoney') === false, "$name ProductSetUp: no chimoney product");
    chk(stripos($market, 'chimoney') === false, "$name MarketPlace: no chimoney category");
    chk(stripos(file_get_contents($root . '/func/bc-giftcard-func.php'), 'chimoney') === false, "$name giftcard FX: chimoney provider removed");
    chk(stripos(file_get_contents($root . '/func/bc-func.php'), 'chimoney') === false, "$name bc-func: no chimoney branches");
    chk(stripos(file_get_contents($root . '/web/api/services.php'), 'chimoney') === false, "$name app services: catalogue query removed");
    chk(stripos(file_get_contents($root . '/func/bc-virtualcard-flutterwave.php'), 'chimoney') === false, "$name flutterwave adapter: de-branded");

    $tables = file_get_contents($root . '/func/bc-tables.php');
    chk(strpos($tables, 'sas_global_virtual_card_products') === false && strpos($tables, 'sas_vendor_virtual_card_products') === false,
        "$name bc-tables: chimoney catalogue tables no longer created");
    chk(strpos($tables, '`provider_card_id` VARCHAR(255)') !== false, "$name bc-tables: cards table uses provider_card_id");
    chk(strpos($tables, "CHANGE `chimoney_card_id` `provider_card_id`") !== false, "$name bc-tables: in-place column rename migration for existing installs");
    chk(strpos($tables, "DEFAULT 'flutterwave'") !== false, "$name bc-tables: new cards default to the flutterwave provider");

    chk(strpos(file_get_contents($root . '/func/bc-func.php'), 'array("virtual-card")) && strpos($transaction_type, "refund") === false') !== false,
        "$name bc-func: transaction buttons key off the virtual-card api type");
}
chk(stripos(file_get_contents('DGV7.0-SAAS/bc-spadmin/ApiEdit.php'), 'chimoney') === false, 'spadmin ApiEdit: type list cleaned');
chk(stripos(file_get_contents('DGV7.0-SAAS/bc-spadmin/CreateAPI.php'), 'chimoney') === false, 'spadmin CreateAPI: type list cleaned');

echo PHP_EOL . ($fail === 0 ? 'chimoney39: all checks passed' : "chimoney39: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
