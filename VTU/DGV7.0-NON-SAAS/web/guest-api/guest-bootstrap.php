<?php
/**
 * Guest API shared bootstrap.
 *
 * Every file in web/guest-api/ starts with:
 *   include_once(__DIR__ . "/guest-bootstrap.php");
 *
 * This is a stateless, unauthenticated API (no api_key / sas_users lookup) — guests
 * pay per-transaction via PayHub checkout instead of a wallet. Identity for rate-limiting
 * and abuse tracking is IP + the recipient identifier (phone/IUC/meter/customer_id),
 * never a username, since none exists in this flow.
 */

// Every response here must be pure JSON. A single stray PHP notice/warning printed inline
// (the host's display_errors default) breaks JSON parsing for every client and leaks server
// file paths — this happened in production (an undefined-index warning corrupted the Data/
// Cable/Exam catalog responses). Errors are still logged, just never echoed into the body.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-App-Source");
header("Content-Type: application/json");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include_once(__DIR__ . "/../../func/bc-connect.php");
// bc-connect.php does NOT pull this in (confirmed: no config file in its include chain) —
// every guest-api file needs it explicitly for bc_is_rate_limited()/bc_log_security_event()/bc_sanitize().
include_once(__DIR__ . "/../../func/bc-security.php");

// Guest checkout always prices at the base/retail tier — there is no agent/reseller
// account to attach a discounted tier to.
const GUEST_PRICE_TABLE = "sas_smart_parameter_values";
const GUEST_ACCOUNT_LEVEL = 1;

// sas_guest_orders.status values
const GUEST_STATUS_PENDING_PAYMENT = 0;
const GUEST_STATUS_PAID            = 1;
const GUEST_STATUS_SUCCESS         = 2;
const GUEST_STATUS_GATEWAY_PENDING = 3;
const GUEST_STATUS_FAILED          = 4;

function guest_json($arr, $http_code = 200) {
    global $connection_server;
    http_response_code($http_code);
    echo json_encode($arr);
    if (isset($connection_server) && $connection_server) {
        mysqli_close($connection_server);
    }
    exit;
}

function guest_fail($desc, $http_code = 400) {
    guest_json(["status" => "failed", "desc" => $desc], $http_code);
}

function guest_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function guest_gen_reference() {
    return substr(str_shuffle("12345678901234567890"), 0, 15);
}

/** Resolve + validate the (single-tenant) vendor, exiting with a JSON error if inactive. */
function guest_resolve_vendor() {
    global $connection_server;
    $vendor_id = resolveVendorID();
    $vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . (int)$vendor_id . "' AND status=1 LIMIT 1"));
    if (!$vendor) {
        guest_fail("Website not registered or inactive", 503);
    }
    return $vendor;
}

/**
 * IP-block + rate-limit gate. Call at the top of every guest-api endpoint.
 * $action should be a stable per-endpoint string (e.g. "guest_checkout_init").
 */
function guest_security_gate($vendor_id, $action, $max = 20, $window = 60) {
    $ip = guest_client_ip();

    $blocked = isIPBlocked($ip, $vendor_id);
    if ($blocked !== false) {
        bc_log_security_event('BLOCKED_IP_ATTEMPT', $action, $ip, is_string($blocked) ? $blocked : 'IP blocked');
        guest_fail("Access temporarily restricted. Please try again later.", 403);
    }

    if (bc_is_rate_limited($action, $ip, $max, $window)) {
        guest_fail("Too many requests. Please slow down and try again shortly.", 429);
    }
}

/**
 * Daily per-identity AND per-IP abuse limiter (guest-safe sibling of the username-keyed
 * productIDPurchaseChecker() in func/bc-func.php). Blocks the IP on the identity's own
 * repeated abuse the same way the authenticated flow blocks it — there is no account to
 * disable here, so blockIP() is the only enforcement lever.
 */
function guest_daily_abuse_check($vendor_id, $service_type, $identity) {
    global $connection_server;
    $ip = guest_client_ip();
    $today = date("Y-m-d");
    $vid = (int)$vendor_id;
    $identity_esc = mysqli_real_escape_string($connection_server, $identity);
    $type_esc = mysqli_real_escape_string($connection_server, $service_type);
    $ip_esc = mysqli_real_escape_string($connection_server, $ip);

    $limit_row = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_limit WHERE vendor_id='$vid' LIMIT 1"));
    $limit_col_map = [
        'cable' => 'limit_cable',
        'betting' => 'limit_betting',
        'electric' => 'limit_electric',
    ];
    $limit_col = $limit_col_map[$service_type] ?? 'limit_phone';
    $limit = (int)($limit_row[$limit_col] ?? $limit_row['limit'] ?? 5);
    if ($limit <= 0) $limit = 5;

    // Shared bucket for airtime/data types, same grouping as the authenticated flow.
    $shared_types = ['sme-data', 'cg-data', 'dd-data', 'shared-data', 'airtime', 'data'];
    $type_condition = in_array($service_type, $shared_types)
        ? "service_type IN ('sme-data','cg-data','dd-data','shared-data','airtime','data')"
        : "service_type='$type_esc'";

    $identity_count = mysqli_fetch_array(mysqli_query($connection_server, "SELECT COUNT(*) as cnt FROM sas_guest_abuse_tracker WHERE vendor_id='$vid' AND identity='$identity_esc' AND $type_condition AND date_tracked='$today'"))['cnt'] ?? 0;
    $ip_count = mysqli_fetch_array(mysqli_query($connection_server, "SELECT COUNT(*) as cnt FROM sas_guest_abuse_tracker WHERE vendor_id='$vid' AND ip_address='$ip_esc' AND $type_condition AND date_tracked='$today'"))['cnt'] ?? 0;

    if ($identity_count >= $limit || $ip_count >= ($limit * 3)) {
        bc_log_security_event('GUEST_ABUSE_LIMIT', $service_type, $identity . '/' . $ip, "identity_count=$identity_count ip_count=$ip_count limit=$limit");
        blockIP($ip, $vid, 'one-day', "Exceeded Guest daily transaction limit for $service_type");
        return false;
    }
    return true;
}

function guest_record_abuse_attempt($vendor_id, $service_type, $identity, $reference) {
    global $connection_server;
    $vid = (int)$vendor_id;
    $ip_esc = mysqli_real_escape_string($connection_server, guest_client_ip());
    $identity_esc = mysqli_real_escape_string($connection_server, $identity);
    $type_esc = mysqli_real_escape_string($connection_server, $service_type);
    $ref_esc = mysqli_real_escape_string($connection_server, $reference);
    $today = date("Y-m-d");
    mysqli_query($connection_server, "INSERT INTO sas_guest_abuse_tracker (vendor_id, service_type, identity, ip_address, reference, date_tracked) VALUES ('$vid','$type_esc','$identity_esc','$ip_esc','$ref_esc','$today')");
}

/** Mirrors func/bc-func.php's productIDBlockChecker() but vendor_id-parametrized (no $get_logged_user_details global). */
function guest_id_blocked($vendor_id, $item_id) {
    global $connection_server;
    $item_id_esc = mysqli_real_escape_string($connection_server, trim(strip_tags($item_id)));
    if (empty($item_id_esc)) return false;
    $rows = mysqli_query($connection_server, "SELECT * FROM sas_id_blocking_system WHERE vendor_id='" . (int)$vendor_id . "' && product_id='$item_id_esc'");
    return mysqli_num_rows($rows) > 0;
}

/**
 * Look up the single enabled gateway API for a product, mirroring the
 * "0 rows / 1 enabled / >1 enabled" branch used identically across every web/func/*.php file.
 * Returns ['ok'=>true,'api_detail'=>array] or ['ok'=>false,'error'=>string].
 */
function guest_resolve_enabled_api($vendor_id, $status_table, $product_name, $api_type) {
    global $connection_server;
    $vid = (int)$vendor_id;
    $product_name_esc = mysqli_real_escape_string($connection_server, $product_name);
    $api_type_esc = mysqli_real_escape_string($connection_server, $api_type);

    $item_status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $status_table WHERE vendor_id='$vid' && product_name='$product_name_esc'"));
    $api_id = (int)($item_status['api_id'] ?? 0);

    $all_apis = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' && id='$api_id' && api_type='$api_type_esc'");

    // Self-heal: sas_apis has no FK/cascade, so deleting or replacing a gateway in the
    // MarketPlace (bc-admin/MarketPlace.php's delete-api action) leaves this status row's
    // api_id dangling — every purchase for this product would otherwise fail with "Gateway
    // Error" forever, even though the vendor has a perfectly good active gateway of the same
    // type configured. Re-point the status row at whichever active gateway of this api_type
    // exists instead of hard-failing, and persist the repair so it's fixed for every future
    // request (guest or authenticated — this same status table backs both).
    if (mysqli_num_rows($all_apis) < 1 && $item_status) {
        $fallback = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' && api_type='$api_type_esc' && status='1' ORDER BY id DESC LIMIT 1");
        if ($fallback && mysqli_num_rows($fallback) > 0) {
            $fallback_api_id = (int)mysqli_fetch_array($fallback)['id'];
            mysqli_query($connection_server, "UPDATE $status_table SET api_id='$fallback_api_id' WHERE vendor_id='$vid' && product_name='$product_name_esc'");
            $api_id = $fallback_api_id;
            $all_apis = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' && id='$api_id' && api_type='$api_type_esc'");
        }
    }

    $enabled_apis = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' && id='$api_id' && api_type='$api_type_esc' && status='1'");

    if (mysqli_num_rows($all_apis) < 1) {
        return ['ok' => false, 'error' => 'Gateway Error'];
    }
    if (mysqli_num_rows($enabled_apis) > 1) {
        return ['ok' => false, 'error' => 'System is unavailable, try again later'];
    }
    if (mysqli_num_rows($enabled_apis) < 1) {
        return ['ok' => false, 'error' => 'Product Not Available'];
    }
    $api_detail = mysqli_fetch_array($all_apis);
    if (empty($api_detail['api_key'])) {
        return ['ok' => false, 'error' => 'Empty Gateway Key'];
    }
    if ($api_detail['status'] != 1) {
        return ['ok' => false, 'error' => 'System Is Busy'];
    }
    return ['ok' => true, 'api_detail' => $api_detail];
}

function guest_product_row($vendor_id, $product_name) {
    global $connection_server;
    $vid = (int)$vendor_id;
    $name_esc = mysqli_real_escape_string($connection_server, $product_name);
    return mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$name_esc' LIMIT 1"));
}

function guest_status_row($vendor_id, $status_table, $product_name) {
    global $connection_server;
    $vid = (int)$vendor_id;
    $name_esc = mysqli_real_escape_string($connection_server, $product_name);
    return mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $status_table WHERE vendor_id='$vid' && product_name='$name_esc' LIMIT 1"));
}

/** Fixed-price lookup (data/cable/exam): val_1 = plan code, val_2 = flat Naira price. */
function guest_price_fixed($vendor_id, $api_id, $product_id, $quantity) {
    global $connection_server;
    $vid = (int)$vendor_id;
    $api_id = (int)$api_id;
    $product_id = (int)$product_id;
    $qty_esc = mysqli_real_escape_string($connection_server, $quantity);
    return mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . GUEST_PRICE_TABLE . " WHERE vendor_id='$vid' && api_id='$api_id' && product_id='$product_id' && val_1='$qty_esc' && status='1' LIMIT 1"));
}

/** Percentage-discount lookup (airtime/electric/betting): val_1 = discount percent. */
function guest_price_percent($vendor_id, $api_id, $product_id) {
    global $connection_server;
    $vid = (int)$vendor_id;
    $api_id = (int)$api_id;
    $product_id = (int)$product_id;
    return mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . GUEST_PRICE_TABLE . " WHERE vendor_id='$vid' && api_id='$api_id' && product_id='$product_id' LIMIT 1"));
}

/** Creates the pending_payment guest order row and returns the generated reference. */
function guest_create_order($vendor_id, $service_type, $identity, $amount, $discounted_amount, $description, $extra_data) {
    global $connection_server;
    $reference = guest_gen_reference();
    $vid = (int)$vendor_id;
    $type_esc = mysqli_real_escape_string($connection_server, $service_type);
    $identity_esc = mysqli_real_escape_string($connection_server, $identity);
    $ref_esc = mysqli_real_escape_string($connection_server, $reference);
    $amount_esc = mysqli_real_escape_string($connection_server, $amount);
    $discounted_esc = mysqli_real_escape_string($connection_server, $discounted_amount);
    $desc_esc = mysqli_real_escape_string($connection_server, $description);
    $extra_esc = mysqli_real_escape_string($connection_server, json_encode($extra_data));
    $ip_esc = mysqli_real_escape_string($connection_server, guest_client_ip());

    mysqli_query($connection_server, "INSERT INTO sas_guest_orders (vendor_id, reference, service_type, identity, amount, discounted_amount, description, extra_data, status, ip_address) VALUES ('$vid','$ref_esc','$type_esc','$identity_esc','$amount_esc','$discounted_esc','$desc_esc','$extra_esc'," . GUEST_STATUS_PENDING_PAYMENT . ",'$ip_esc')");
    return $reference;
}

function guest_get_order($reference, $vendor_id = null) {
    global $connection_server;
    $ref_esc = mysqli_real_escape_string($connection_server, $reference);
    $sql = "SELECT * FROM sas_guest_orders WHERE reference='$ref_esc'";
    if ($vendor_id !== null) $sql .= " AND vendor_id='" . (int)$vendor_id . "'";
    $sql .= " LIMIT 1";
    return mysqli_fetch_array(mysqli_query($connection_server, $sql));
}

/** Looks up an order by PayHub's OWN reference (captured into payment_reference at checkout-init
 *  time) — this is how guest-webhook.php finds the order, since the webhook payload's own
 *  "reference" field is PayHub's, not ours. See checkout-init.php's payment_reference comment. */
function guest_get_order_by_payment_reference($payment_reference) {
    global $connection_server;
    $ref_esc = mysqli_real_escape_string($connection_server, $payment_reference);
    return mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_guest_orders WHERE payment_reference='$ref_esc' LIMIT 1"));
}

/** Mirrors alterTransaction() but targets sas_guest_orders and allows "0" values through. */
function guest_update_order($reference, $column_name, $column_value) {
    global $connection_server;
    $reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
    $column_name = preg_replace('/[^a-z_]/', '', $column_name);
    $column_value = mysqli_real_escape_string($connection_server, trim(strip_tags((string)$column_value)));
    if (!empty($reference) && !empty($column_name) && $column_value !== "") {
        mysqli_query($connection_server, "UPDATE sas_guest_orders SET `$column_name`='$column_value' WHERE reference='$reference'");
    }
}

/**
 * Runs a purchase/verify gateway file exactly like web/func/*.php does (reset the
 * $api_response* vars, include the file, read them back) — the gateway files set these
 * as plain variables in whatever scope includes them, so this must be a real include()
 * inside this function's scope, not eval'd output.
 *
 * $vars must supply every input variable the real provider gateway files read directly
 * (confirmed by reading several verbatim: $isp/$epp, $product_name — always set as an alias
 * for isp/epp in the original web/func/*.php scope — $phone_no/$iuc_no/$meter_number/
 * $customer_id, $quantity, $type, $amount, $api_detail). extract() puts them into this
 * function's local scope, which is what include() actually sees.
 */
function guest_run_gateway($mode, $api_gateway_name, array $vars = []) {
    extract($vars);
    $dir = $mode === 'verify' ? '/func/api-gateway/verify/' : ($mode === 'requery' ? '/func/api-gateway/requery/' : '/func/api-gateway/');
    $api_response = null;
    $api_response_description = null;
    $api_response_reference = null;
    $api_response_text = null;
    $api_response_status = null;
    $api_response_meter_number = null;
    $api_response_token = null;
    $api_response_token_unit = null;
    $api_response_customer_id = null;
    $api_response_customer_name = null;
    $api_response_customer_address = null;

    include($_SERVER['DOCUMENT_ROOT'] . $dir . $api_gateway_name);

    return [
        'api_response' => $api_response,
        'api_response_description' => $api_response_description,
        'api_response_reference' => $api_response_reference,
        'api_response_status' => $api_response_status,
        'api_response_meter_number' => $api_response_meter_number,
        'api_response_token' => $api_response_token,
        'api_response_token_unit' => $api_response_token_unit,
        'api_response_customer_id' => $api_response_customer_id,
        'api_response_customer_name' => $api_response_customer_name,
        'api_response_customer_address' => $api_response_customer_address,
    ];
}

/**
 * Atomically claims a pending_payment order for payment-processing via a compare-and-swap
 * UPDATE (status=PENDING_PAYMENT -> PAID only if it's still PENDING_PAYMENT). Returns true
 * only if THIS call performed the transition — guards against the webhook firing twice
 * concurrently (a real occurrence with payment-gateway webhook retries) and double-fulfilling.
 */
function guest_claim_order_for_payment($reference) {
    global $connection_server;
    $ref_esc = mysqli_real_escape_string($connection_server, $reference);
    mysqli_query($connection_server, "UPDATE sas_guest_orders SET status=" . GUEST_STATUS_PAID . " WHERE reference='$ref_esc' AND status=" . GUEST_STATUS_PENDING_PAYMENT);
    return mysqli_affected_rows($connection_server) === 1;
}

function guest_mark_fulfilled($reference) {
    global $connection_server;
    $ref_esc = mysqli_real_escape_string($connection_server, $reference);
    mysqli_query($connection_server, "UPDATE sas_guest_orders SET fulfilled_at=NOW() WHERE reference='$ref_esc'");
}

/** Merges new keys into the order's extra_data JSON blob (service-specific fulfillment outputs: token, meter_number, etc). */
function guest_merge_extra_data($reference, array $merge) {
    global $connection_server;
    $order = guest_get_order($reference);
    if (!$order) return;
    $current = json_decode($order['extra_data'] ?? '{}', true) ?: [];
    $updated = array_merge($current, $merge);
    $ref_esc = mysqli_real_escape_string($connection_server, $reference);
    $json_esc = mysqli_real_escape_string($connection_server, json_encode($updated));
    mysqli_query($connection_server, "UPDATE sas_guest_orders SET extra_data='$json_esc' WHERE reference='$ref_esc'");
}

/** Resolves the gateway filename exactly like web/func/*.php: normalized host name, fallback to -localserver.php. */
function guest_gateway_filename($mode, $type_prefix, $api_base_url) {
    $dir = $mode === 'verify' ? '/func/api-gateway/verify/' : ($mode === 'requery' ? '/func/api-gateway/requery/' : '/func/api-gateway/');
    $normalized = strtolower(trim($api_base_url));
    $candidate = $type_prefix . "-" . str_replace(".", "-", $normalized) . ".php";
    if (file_exists($_SERVER['DOCUMENT_ROOT'] . $dir . $candidate)) {
        return $candidate;
    }
    return $type_prefix . "-localserver.php";
}
