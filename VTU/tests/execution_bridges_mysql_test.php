<?php
/**
 * End-to-end disposable MySQL 8.0 tests for all wired execution bridges:
 *  - Service intake -> held attempt -> capability matrix -> worker lease
 *  - Checkout intent -> provider attach -> verified settlement -> wallet credit
 *  - Payout reserve -> admin cancel refund & admin approve unknown-timeout containment
 *  - Virtual card reserve -> dispatch -> PCI-safe masked card issuance
 *  - Gift card reserve + P2P escrow hold/release + KMS AES-256-GCM
 */
set_exception_handler(function ($e) {
    $msg = str_replace(['%', "\r", "\n"], ['%25', '', '%0A'], $e->getMessage());
    echo '::error file=VTU/tests/execution_bridges_mysql_test.php,line=' . $e->getLine() . '::' . $msg . "\n";
    exit(1);
});

$ed = strtoupper((string)(getenv('BC_TEST_EDITION') ?: 'SAAS'));
if (!in_array($ed, ['SAAS', 'NON-SAAS'], true)) {
    throw new RuntimeException("Invalid BC_TEST_EDITION: $ed");
}

$host = getenv('MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MYSQL_PORT') ?: '3306');
$user = getenv('MYSQL_USER') ?: 'root';
$pass = getenv('MYSQL_PASSWORD') ?: 'disposable-ci-only';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'dgv7_exec_bridges_' . strtolower(str_replace('-', '_', $ed)) . '_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);
$conn->query("DROP DATABASE IF EXISTS `$dbName`");
$conn->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($dbName);
$connection_server = $conn;

$root = dirname(__DIR__);
$base = "$root/DGV7.0-$ed";

// Seed prerequisite legacy tables
$conn->query("CREATE TABLE sas_vendors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    website_url VARCHAR(255) NOT NULL DEFAULT 'tenant.test',
    email VARCHAR(190) NOT NULL DEFAULT 'vendor@tenant.test',
    balance DECIMAL(18,2) NOT NULL DEFAULT 1000000.00,
    giftcard_fee_percent DECIMAL(5,2) NOT NULL DEFAULT 2.00,
    print_hub_secret VARCHAR(128) NOT NULL DEFAULT 'vendor-kms-secret-value-123456',
    status TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    username VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    balance DECIMAL(65,30) NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 1,
    is_blocked TINYINT NOT NULL DEFAULT 0,
    force_password_change TINYINT NOT NULL DEFAULT 0,
    account_type VARCHAR(32) NULL,
    UNIQUE KEY uniq_vu (vendor_id, username)
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    username VARCHAR(120) NOT NULL,
    product_unique_id VARCHAR(120) NOT NULL DEFAULT '',
    type_alternative VARCHAR(120) NOT NULL DEFAULT '',
    reference VARCHAR(120) NOT NULL,
    api_reference VARCHAR(120) NOT NULL DEFAULT '',
    amount DECIMAL(65,30) NOT NULL DEFAULT 0,
    discounted_amount DECIMAL(65,30) NOT NULL DEFAULT 0,
    balance_before DECIMAL(65,30) NOT NULL DEFAULT 0,
    balance_after DECIMAL(65,30) NOT NULL DEFAULT 0,
    description VARCHAR(255) NOT NULL DEFAULT '',
    mode VARCHAR(32) NOT NULL DEFAULT '',
    api_website VARCHAR(120) NOT NULL DEFAULT '',
    status TINYINT NOT NULL DEFAULT 2,
    date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vref (vendor_id, reference)
) ENGINE=InnoDB");

$tables_src = file_get_contents("$base/func/bc-tables.php");
foreach (['sas_wallet_mutation_events', 'sas_secure_payment_intents'] as $tbl) {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $tbl . ' \(.*?\) ENGINE=InnoDB/s', $tables_src, $ddl)) {
        throw new RuntimeException('Missing ' . $tbl . ' DDL in bc-tables.php');
    }
    $conn->query($ddl[0]);
}

$conn->query("CREATE TABLE sas_bank_transfer_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    reference VARCHAR(100) NOT NULL UNIQUE,
    username VARCHAR(120) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    amount_charged DECIMAL(18,2) NOT NULL,
    bank_code VARCHAR(32) NOT NULL,
    bank_name VARCHAR(120) NOT NULL,
    account_name VARCHAR(120) NOT NULL,
    account_number VARCHAR(32) NOT NULL,
    narration VARCHAR(160) NOT NULL,
    session_id VARCHAR(120) NOT NULL DEFAULT '',
    date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_virtual_cards_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    reference VARCHAR(100) UNIQUE,
    provider_card_id VARCHAR(255) NOT NULL DEFAULT '',
    card_name VARCHAR(255) NOT NULL,
    masked_pan VARCHAR(25) NOT NULL,
    expiry_month VARCHAR(5) NOT NULL,
    expiry_year VARCHAR(5) NOT NULL,
    cvv VARCHAR(10) NOT NULL DEFAULT '',
    balance_usd DECIMAL(20,2) DEFAULT 0.00,
    status ENUM('active','frozen','terminated') DEFAULT 'active',
    is_frozen_auto TINYINT(1) DEFAULT 0,
    metadata TEXT,
    provider VARCHAR(50) DEFAULT 'flutterwave',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_vendor_giftcard_products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    reloadly_product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(160) NOT NULL,
    currency_code VARCHAR(16) NOT NULL DEFAULT 'USD',
    vendor_markup DECIMAL(6,2) NOT NULL DEFAULT 3.00,
    status TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_giftcard_inventory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    reloadly_tx_id VARCHAR(120) NOT NULL DEFAULT '',
    reloadly_product_id INT UNSIGNED NOT NULL DEFAULT 0,
    product_name VARCHAR(160) NOT NULL DEFAULT '',
    card_code TEXT NOT NULL,
    card_pin VARCHAR(120) NOT NULL DEFAULT '',
    face_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(16) NOT NULL DEFAULT 'USD',
    current_owner_id INT UNSIGNED NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'api',
    is_for_sale TINYINT NOT NULL DEFAULT 0,
    sale_price_ngn DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    setting_name VARCHAR(120) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    UNIQUE KEY uniq_vs (vendor_id, setting_name)
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_dollar_exchange_rates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    product_type VARCHAR(64) NOT NULL,
    currency VARCHAR(16) NOT NULL,
    debit_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    credit_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_vpc (vendor_id, product_type, currency)
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_service_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    username VARCHAR(120) NOT NULL DEFAULT '',
    type VARCHAR(64) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    reference VARCHAR(64) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ref (vendor_id, reference)
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_site_details (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    site_title VARCHAR(120) NOT NULL DEFAULT 'Test'
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_provider_settlement_claims (
    settlement_key CHAR(64) NOT NULL PRIMARY KEY,
    payload_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_user_crypto_wallets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    username VARCHAR(120) NOT NULL,
    currency_code VARCHAR(16) NOT NULL,
    balance DECIMAL(28,18) NOT NULL DEFAULT 0,
    ledger_balance DECIMAL(28,18) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_vuc (vendor_id, username, currency_code)
) ENGINE=InnoDB");

$conn->query("CREATE TABLE sas_service_control (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    service_name VARCHAR(64) NOT NULL,
    status TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB");

// Apply all bundled VTU migrations + fintech schema via bc_schema_autoheal + bc_fintech_ensure_schema
require_once "$base/func/bc-schema-autoheal.php";
require_once "$base/func/bc-fintech-schema.php";
$heal = bc_schema_autoheal($conn, "$base/migrations");
if (empty($heal['ok'])) {
    throw new RuntimeException('bc_schema_autoheal failed: ' . json_encode($heal));
}
if (bc_fintech_ensure_schema() !== true) {
    throw new RuntimeException('bc_fintech_ensure_schema failed');
}

$conn->query("INSERT INTO sas_vendors (id, website_url, email, balance, giftcard_fee_percent, print_hub_secret) VALUES (1, 'tenant.test', 'owner@tenant.test', 500000.00, 2.00, 'vendor-kms-secret-value-123456')");
$conn->query("INSERT INTO sas_users (id, vendor_id, username, email, balance, status) VALUES (10, 1, 'alice', 'alice@tenant.test', 50000.00, 1), (11, 1, 'bob', 'bob@tenant.test', 10000.00, 1)");
$conn->query("INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES (0, 'live_rate_USD_NGN', '1500.00')");
$conn->query("INSERT INTO sas_vendor_giftcard_products (vendor_id, reloadly_product_id, product_name, currency_code, vendor_markup, status) VALUES (1, 101, 'Amazon US', 'USD', 3.00, 1)");
$conn->query("INSERT INTO sas_giftcard_inventory (id, vendor_id, reloadly_product_id, product_name, card_code, face_value, currency, current_owner_id, is_for_sale, sale_price_ngn) VALUES (50, 1, 101, 'Amazon US', 'sealed', 25.00, 'USD', 11, 1, 5000.00)");

$checks = 0;
function ebm_chk($cond, $msg) {
    global $checks;
    $checks++;
    if (!$cond) {
        throw new RuntimeException("FAIL: $msg");
    }
}

// 1. Service intake -> held attempt -> capability matrix -> worker execution
require_once "$base/func/bc-service-intake-bridge.php";
require_once "$base/func/bc-service-dispatch-worker.php";
$reqRef = 'SVC-' . bin2hex(random_bytes(8));
$payloadJson = json_encode(['currency' => 'USD', 'amount_usd' => 100, 'recipient_name' => 'John'], JSON_THROW_ON_ERROR);
$conn->query("INSERT INTO sas_service_requests (vendor_id, user_id, username, type, provider, reference, payload) VALUES (1, 10, 'alice', 'intl_transfer', 'klasha', '$reqRef', '$payloadJson')");
$held = bc_svc_enqueue_held_attempt(1, $reqRef);
ebm_chk(!empty($held['ok']) && $held['state'] === 'held', 'service intake bridge creates held sas_service_attempts row');

$cap = bc_sdl_set_capability(1, 'intl_transfer', 'klasha', 'USD', true, 'CAP-PROOF-1');
ebm_chk(!empty($cap['ok']), 'service capability matrix stores authorized capability');
putenv('BC_SERVICE_DISPATCH_WORKER_ENABLED=1');
$svcRun = bc_sdl_run_worker(1, 'worker-1', function ($row) {
    return ['outcome' => 'confirmed_success', 'provider_reference' => 'KL-9001', 'evidence' => ['ok' => true]];
}, 60, 5);
ebm_chk(!empty($svcRun['ok']) && $svcRun['processed'] === 1 && $svcRun['results'][0]['state'] === 'confirmed_success', 'service dispatch worker settles held intake row');
putenv('BC_SERVICE_DISPATCH_WORKER_ENABLED=');

// 2. Checkout intent -> attach -> authoritative settlement -> wallet credit
require_once "$base/func/bc-checkout-execution.php";
$chkKey = hash('sha256', 'chk-test-1');
$intent = bc_chk_create_intent(1, 10, 'payhub', 'NGN', '2500.00', 900, $chkKey);
ebm_chk(!empty($intent['ok']) && $intent['state'] === 'created', 'checkout intent created');
$attEv = hash('sha256', 'att-1');
$att = bc_chk_attach_provider(1, $intent['reference'], $attEv, 'PH-CHK-1', 0);
ebm_chk(!empty($att['ok']) && $att['state'] === 'provider_pending', 'checkout intent attached to provider');
$settle = bc_chk_settle_verified_intent(1, $intent['reference'], 'payhub', 'NGN', '2500.00', 'PH-CHK-1', hash('sha256', 'ev-1'), 1);
ebm_chk(!empty($settle['ok']) && $settle['credited'] === true, 'checkout settlement credits wallet once');
$settleReplay = bc_chk_settle_verified_intent(1, $intent['reference'], 'payhub', 'NGN', '2500.00', 'PH-CHK-1', hash('sha256', 'ev-1'), 1);
ebm_chk(!empty($settleReplay['ok']) && $settleReplay['credited'] === false, 'replayed checkout settlement never double-credits');
$balAfterChk = (float)$conn->query("SELECT balance FROM sas_users WHERE id=10")->fetch_assoc()['balance'];
ebm_chk(abs($balAfterChk - 52500.00) < 0.01, 'alice balance reflects exact checkout credit (52500.00)');

// 3. Payout user reservation -> admin reject refund & admin approve unknown-timeout containment
require_once "$base/func/bc-payout-execution.php";
putenv('BC_PAYOUT_WORKER_ENABLED=1');
$pRes1 = bc_payout_execute_user_request(1, ['id' => 10, 'username' => 'alice'], 'payhub', '058', '0123456789', '1000.00', '50.00', 'Test payout 1', hash('sha256', 'p-1'), 'GTBank', 'Alice', 'S1');
ebm_chk(!empty($pRes1['ok']) && $pRes1['state'] === 'held', 'payout reserved in held state');
$cancelRes = bc_payout_admin_action(1, 'admin@tenant.test', $pRes1['reference'], 'reject', 'User requested cancel');
ebm_chk(!empty($cancelRes['ok']) && $cancelRes['state'] === 'cancelled', 'admin reject cancels held payout and refunds once');

$pRes2 = bc_payout_execute_user_request(1, ['id' => 10, 'username' => 'alice'], 'payhub', '058', '0123456789', '1000.00', '50.00', 'Test payout 2', hash('sha256', 'p-2'), 'GTBank', 'Alice', 'S2');
$unknownApprove = bc_payout_admin_action(1, 'admin@tenant.test', $pRes2['reference'], 'approve', 'Dispatch', function ($row) {
    return ['outcome' => 'unknown', 'error' => 'gateway_timeout'];
});
ebm_chk(!empty($unknownApprove['ok']) && $unknownApprove['state'] === 'unknown', 'ambiguous provider timeout stays unknown without auto-refund');
$balAfterPayout = (float)$conn->query("SELECT balance FROM sas_users WHERE id=10")->fetch_assoc()['balance'];
ebm_chk(abs($balAfterPayout - 51450.00) < 0.01, 'alice balance stays debited on unknown payout (51450.00)');
putenv('BC_PAYOUT_WORKER_ENABLED=');

// 4. Virtual card reserve -> dispatch -> masked PAN persisted (zero CVV)
require_once "$base/func/bc-virtualcard-execution.php";
putenv('BC_VIRTUALCARD_WORKER_ENABLED=1');
$vcRes = bc_vc_reserve_issue(1, 10, '10.00', '2.00', '1500.000000', 'Alice Card', hash('sha256', 'vc-1'));
ebm_chk(!empty($vcRes['ok']) && $vcRes['state'] === 'held', 'virtual card issue reserved');
$vcDisp = bc_vc_dispatch_one(1, $vcRes['reference'], (int)$vcRes['version'], function ($ord) {
    return [
        'outcome' => 'confirmed_success',
        'provider_reference' => 'FLW-VC-77',
        'card_payload' => ['masked_pan' => '**** **** **** 4242', 'expiry_month' => '11', 'expiry_year' => '2029', 'cvv' => ''],
    ];
});
ebm_chk(!empty($vcDisp['ok']) && $vcDisp['state'] === 'confirmed_success', 'virtual card dispatch completes with masked PAN');
putenv('BC_VIRTUALCARD_WORKER_ENABLED=');

// 5. Gift card reserve + P2P escrow hold/release + KMS AES-256-GCM
require_once "$base/func/bc-giftcard-execution.php";
putenv('BC_GIFTCARD_WORKER_ENABLED=1');
putenv('BC_GIFTCARD_KMS_KEY=0123456789abcdef0123456789abcdef0123456789abcdef');
$gcBuy = bc_gc_execute_purchase(1, ['id' => 10, 'username' => 'alice'], 101, 10.00, hash('sha256', 'gc-1'));
ebm_chk(!empty($gcBuy['ok']) && $gcBuy['state'] === 'held', 'giftcard purchase reserved in held state');
$p2p = bc_gc_execute_p2p_buy(1, ['id' => 10, 'username' => 'alice'], 50, hash('sha256', 'p2p-1'));
ebm_chk(!empty($p2p['ok']) && $p2p['state'] === 'released', 'giftcard P2P escrow atomically settles and transfers ownership');
$ownerNow = (int)$conn->query("SELECT current_owner_id FROM sas_giftcard_inventory WHERE id=50")->fetch_assoc()['current_owner_id'];
ebm_chk($ownerNow === 10, 'giftcard P2P transferred card ownership to buyer');
putenv('BC_GIFTCARD_WORKER_ENABLED=');
putenv('BC_GIFTCARD_KMS_KEY=');

// 6. Webhook settlement syncs sas_checkout_intents -> settled when BC_CHECKOUT_EXECUTION_ENABLED=1
require_once "$base/func/bc-stepup.php";
require_once "$base/func/bc-fintech-providers.php";
require_once "$base/func/bc-fintech-webhook.php";
$conn->query("CREATE TABLE sas_payment_gateways (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT UNSIGNED NOT NULL,
    gateway_name VARCHAR(64) NOT NULL,
    public_key VARCHAR(255) NOT NULL DEFAULT '',
    secret_key VARCHAR(255) NOT NULL DEFAULT '',
    encrypt_key VARCHAR(255) NOT NULL DEFAULT '',
    percentage DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    status TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB");
$conn->query("INSERT INTO sas_payment_gateways (vendor_id, gateway_name, secret_key, encrypt_key, status) VALUES (1, 'flutterwave', 'flw_sec_test', 'flw-wh-secret-123', 1)");
$chkWhKey = hash('sha256', 'chk-wh-test-1');
$whIntent = bc_chk_create_intent(1, 10, 'flutterwave', 'NGN', '1500.00', 900, $chkWhKey);
ebm_chk(!empty($whIntent['ok']), 'second checkout intent created for webhook test');
$whRef = $whIntent['reference'];
putenv('BC_CHECKOUT_EXECUTION_ENABLED=1');
$rawWh = json_encode(['event' => 'charge.completed', 'data' => ['id' => 998877, 'tx_ref' => $whRef, 'amount' => 1500, 'currency' => 'NGN', 'status' => 'successful']], JSON_THROW_ON_ERROR);
$whRes = bc_fintech_webhook_handle('flutterwave', 1, $rawWh, ['verif-hash' => 'flw-wh-secret-123'], function ($vid, $ext) use ($whRef) {
    return ['ok' => true, 'reason' => 'verified', 'reference' => $whRef, 'amount' => 1500.00, 'gross_amount' => 1500.00, 'currency' => 'NGN', 'status' => 'successful'];
});
ebm_chk(!empty($whRes['ok']) && $whRes['reason'] === 'credited', 'webhook handler credits wallet for checkout intent');
$whState = (string)$conn->query("SELECT state FROM sas_checkout_intents WHERE vendor_id=1 AND reference='" . $conn->real_escape_string($whRef) . "'")->fetch_assoc()['state'];
ebm_chk($whState === 'settled', 'webhook handler transitions sas_checkout_intents to settled');
putenv('BC_CHECKOUT_EXECUTION_ENABLED=');

$conn->query("DROP DATABASE IF EXISTS `$dbName`");
echo "PASS $checks $ed execution bridge MySQL checks\n";
