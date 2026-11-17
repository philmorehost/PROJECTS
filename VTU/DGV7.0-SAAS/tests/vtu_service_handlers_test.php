<?php
/** Regression tests for the shared VTU purchase handlers.
 * Run with: php -n tests/vtu_service_handlers_test.php
 * No database or provider calls are made. The actual handler source is evaluated with only
 * mysqli_real_escape_string replaced by a stub, so the early authorization paths are exercised.
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function vtu_test_escape($connection, $value) { return $value; }
function sanitize_phone_number($value) { return $value; }
function bc_valid_mobile_phone($number) { return true; }
function isServiceEnabled($type, $vendor) { return true; }
function userBalance($id) { return 0; }
function requireTransactionPin($vendor, $user, $input, &$error) {
    $GLOBALS['pin_calls']++;
    $GLOBALS['pin_input'] = $input;
    $error = 'Test PIN rejected';
    return $GLOBALS['allow_pin'];
}

function run_handler($service, $method, $allowPin, $userStatus, $ai = false) {
    $cases = [
        'airtime' => [['isp' => 'mtn', 'phone-number' => '08012345678', 'amount' => '100'], ['network' => 'mtn', 'phone_number' => '08012345678', 'amount' => '100']],
        'data' => [['isp' => 'mtn', 'phone-number' => '08012345678', 'type' => 'sme-data', 'quantity' => '1gb'], ['network' => 'mtn', 'phone_number' => '08012345678', 'type' => 'sme-data', 'quantity' => '1gb']],
        'cable' => [['isp' => 'dstv', 'iuc-number' => '12345', 'quantity' => 'basic'], ['type' => 'dstv', 'iuc_number' => '12345', 'package' => 'basic']],
        'electric' => [['epp' => 'ekedc', 'type' => 'prepaid', 'amount' => '100', 'meter-number' => '12345'], ['provider' => 'ekedc', 'type' => 'prepaid', 'amount' => '100', 'meter_number' => '12345']],
        'betting' => [['epp' => 'bet9ja', 'amount' => '100', 'customer-id' => '12345'], ['provider' => 'bet9ja', 'amount' => '100', 'customer_id' => '12345']],
        'exam' => [['epp' => 'waec', 'quantity' => '1'], ['type' => 'waec', 'quantity' => '1']],
    ];
    $_SESSION = [];
    $_POST = $cases[$service][0] + ['pin' => '1111'];
    $get_api_post_info = $cases[$service][1] + ['pin' => '2222'];
    $purchase_method = $method;
    $connection_server = null;
    $get_vendor_details = ['id' => 7];
    $get_logged_user_details = ['status' => $userStatus, 'vendor_id' => 7];
    $action_function = 1;
    $GLOBALS['allow_pin'] = $allowPin;
    $GLOBALS['pin_calls'] = 0;
    $GLOBALS['pin_input'] = null;
    $GLOBALS['pin_required_for_api_purchase'] = $ai;
    $path = dirname(__DIR__) . '/web/func/' . $service . '.php';
    $source = file_get_contents($path);
    $source = str_replace('mysqli_real_escape_string(', 'vtu_test_escape(', $source);
    ob_start();
    try { eval('?>' . $source); } finally { ob_end_clean(); }
    return [json_decode($json_response_encode, true), $GLOBALS['pin_calls'], $GLOBALS['pin_input']];
}
function vtu_assert($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
foreach (['airtime', 'data', 'cable', 'electric', 'exam', 'betting'] as $service) {
    foreach (['WEB', 'APP'] as $method) {
        [$response, $calls, $input] = run_handler($service, $method, false, 1);
        vtu_assert($calls === 1, "$service $method must check PIN");
        vtu_assert($response === ['status' => 'failed', 'code' => 'pin_required', 'desc' => 'Test PIN rejected'], "$service $method must report the PIN error");
        vtu_assert($input['pin'] === ($method === 'WEB' ? '1111' : '2222'), "$service $method must use the correct PIN input");
        [$response, $calls] = run_handler($service, $method, true, 0);
        vtu_assert($calls === 1 && $response['desc'] === 'Your account is currently suspended. Please contact support.', "$service $method must reject suspended users after PIN");
        [$response] = run_handler($service, $method, true, 1);
        vtu_assert($response['desc'] === 'Balance is LOW', "$service $method must proceed to the balance check");
    }
    [$response, $calls] = run_handler($service, 'API', false, 0);
    vtu_assert($calls === 0 && $response['desc'] === 'Your account is currently suspended. Please contact support.', "$service API must remain PIN-exempt but reject suspended users");
    [$response, $calls] = run_handler($service, 'API', false, 1, true);
    vtu_assert($calls === 1 && ($response['code'] ?? '') === 'pin_required', "$service AI purchase must require PIN");
    echo "ok $service: WEB, APP, API and AI PIN/balance gates\n";
}
