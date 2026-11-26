<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
require __DIR__ . '/../func/bc-config.php';
require_once __DIR__ . '/../func/bc-wallet-funding-page.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
if (!bc_fund_actor_allowed($get_logged_user_details ?? null, $_SESSION)) {
    http_response_code(403);
    exit('Please sign in with an active customer account.');
}

$vendor_id = (int)$get_logged_user_details['vendor_id'];
$username = (string)$get_logged_user_details['username'];
$enabled_gateways = bc_pg_list_enabled_gateways($connection_server, $vendor_id);

$dva_available = false;
$dva_error = '';
try {
    if (getenv('BC_PAYHUB_DEPOSITS_ENABLED') === '1') {
        if (!bc_kyc_approval_current($get_logged_user_details)) {
            throw new RuntimeException('Complete your current KYC approval before using virtual-account funding.');
        }
        bc_fund_instructions($connection_server, $get_logged_user_details);
        $dva_available = true;
    }
} catch (Throwable $e) {
    $dva_error = bc_fund_public_error($e);
}

$model = [
    'available' => $dva_available || !empty($enabled_gateways),
    'dva_available' => $dva_available,
    'gateways' => $enabled_gateways,
    'error' => '',
    'success' => '',
    'bank' => null,
    'amount' => '',
    'selected_gateway' => '',
    'inline_beewave' => null,
    'retry' => isset($_GET['retry_ref']),
];

$prefill = $_GET['amount'] ?? '';
if (bc_fund_amount($prefill) !== null) {
    $model['amount'] = $prefill;
}

if ($method === 'GET' && !empty($_GET['verify_gateway'])) {
    $vgw = strtolower(trim((string)$_GET['verify_gateway']));
    $vref = (string)($_GET['reference'] ?? ($_GET['trxref'] ?? ($_GET['tx_ref'] ?? ($_GET['paymentReference'] ?? ''))));
    if ($vref !== '') {
        $settle = bc_pg_verify_and_settle($connection_server, $vendor_id, $username, $vgw, $vref, $_GET);
        if (!empty($settle['ok'])) {
            $model['success'] = $settle['message'] ?? 'Your wallet has been funded successfully.';
        } else {
            $model['error'] = $settle['message'] ?? 'Payment could not be confirmed yet.';
        }
    }
}

if ($method === 'POST') {
    $allowed_names = array_keys($enabled_gateways);
    if ($dva_available) {
        $allowed_names[] = 'payhub_dva';
        $allowed_names[] = 'payhub';
    }
    $chosen_gw = strtolower(trim((string)($_POST['gateway'] ?? '')));
    $model['selected_gateway'] = $chosen_gw;
    $require_kyc = ($chosen_gw === 'payhub_dva') || (function_exists('isKYCEnforced') && isKYCEnforced());

    try {
        $amount_minor = bc_fund_validate_post($get_logged_user_details, $_SESSION, $_POST, $allowed_names, $require_kyc);
        $model['amount'] = bc_ph_decimal($amount_minor);

        if ($chosen_gw === 'payhub_dva' || ($chosen_gw === 'payhub' && !isset($enabled_gateways['payhub']))) {
            $model['bank'] = bc_fund_instructions($connection_server, $get_logged_user_details);
            $model['available'] = true;
            $model['error'] = '';
        } else {
            $init = bc_pg_init_gateway_checkout(
                $connection_server,
                $vendor_id,
                $get_logged_user_details,
                $chosen_gw,
                $model['amount'],
                bc_pg_scheme_host(),
                '',
                true
            );
            if (empty($init['ok'])) {
                $model['error'] = $init['message'] ?? 'Unable to initialize payment checkout.';
            } elseif (($init['mode'] ?? '') === 'redirect' && !empty($init['authorization_url'])) {
                header('Location: ' . $init['authorization_url']);
                exit();
            } elseif (($init['mode'] ?? '') === 'inline_beewave') {
                $model['inline_beewave'] = $init;
            }
        }
    } catch (Throwable $e) {
        http_response_code(in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 503);
        $model['error'] = bc_fund_public_error($e);
        $model['bank'] = null;
        $posted = $_POST['amount'] ?? '';
        if (is_string($posted) && strlen($posted) <= 32) {
            $model['amount'] = $posted;
        }
    }
} elseif (empty($enabled_gateways) && !$dva_available && $dva_error !== '') {
    $model['error'] = $dva_error;
}

$model['csrf'] = bc_generate_csrf_token();
require_once __DIR__ . '/../func/bc-plate-app.php';
bc_app_head('Fund Wallet | ' . (string)($get_all_site_details['site_title'] ?? 'Wallet'));
bc_app_sidebar();
bc_app_topbar(['title' => 'FUND WALLET', 'crumb' => 'Home / Fund Wallet']);
bc_fund_render($model);
?>
<script src="/jsfile/bc-wallet-funding.js?v=2" defer></script>
<?php bc_app_foot(); ?>
