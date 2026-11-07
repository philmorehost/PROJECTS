<?php
/**
 * Guest checkout initializer — POST JSON body: {"service": "...", ...service params}
 *
 * Validates params + computes the price exactly like web/func/*.php (minus wallet/username),
 * creates a pending_payment sas_guest_orders row, then initializes a PayHub checkout session
 * (mirrors web/api/payhub-checkout.php's makePayhubRequest call) and returns the checkout URL.
 * Actual fulfillment happens later, only after the guest webhook (guest-webhook.php)
 * independently re-verifies the payment with PayHub — never here.
 */
include_once(__DIR__ . "/guest-bootstrap.php");

// No documented per-product min/max exists in this codebase for the three client-supplied-amount
// services (airtime/electric/betting) — only a discount PERCENT is configured. This ceiling is a
// safety net against typos/abuse, not a product-configured limit.
const GUEST_MAX_CLIENT_AMOUNT = 100000;

$vendor = guest_resolve_vendor();
$vendor_id = $vendor['id'];
guest_security_gate($vendor_id, "guest_checkout_init", 10, 60);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($input)) $input = array_merge($_GET, $_POST);

$service = strtolower(trim(strip_tags($input['service'] ?? '')));
$guest_email = trim(strip_tags($input['email'] ?? ''));
$guest_phone_contact = sanitize_phone_number(trim(strip_tags($input['contact_phone'] ?? '')));

$identity = null;
$amount = null;
$discounted_amount = null;
$description = null;
$api_id = null;
$product_id = null;
$api_base_url = null;
$extra_data = ['service' => $service];

// Persist a REAL guest email with the order so fulfill.php can send a receipt after delivery.
// The synthesized guest+ref@host placeholder (generated below only for PayHub's initialize
// call) must never end up here — receipts to a fake mailbox just bounce.
if (!empty($guest_email) && filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
    $extra_data['guest_email'] = $guest_email;
}

switch ($service) {

    case 'airtime': {
        $isp = strtolower(trim(strip_tags($input['network'] ?? '')));
        $phone_no = sanitize_phone_number(trim(strip_tags($input['phone_number'] ?? $input['phone_no'] ?? '')));
        $amount_in = preg_replace("/[^0-9.]+/", "", trim(strip_tags($input['amount'] ?? '')));

        if (empty($isp) || empty($phone_no) || !is_numeric($phone_no) || empty($amount_in) || !is_numeric($amount_in)) {
            guest_fail("Incomplete Parameters");
        }
        if ((float)$amount_in <= 0 || (float)$amount_in > GUEST_MAX_CLIENT_AMOUNT) {
            guest_fail("Invalid amount");
        }
        if (guest_id_blocked($vendor_id, $phone_no)) guest_fail("Error: Phone number has been blocked");
        if (!guest_daily_abuse_check($vendor_id, 'airtime', $phone_no)) guest_fail("ABUSE LIMIT: You have reached the maximum number of times you can buy airtime or data on this particular phone number ($phone_no) today. Please try again tomorrow.");

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_airtime_status', $isp, 'airtime');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $isp);
        $status_row = guest_status_row($vendor_id, 'sas_airtime_status', $isp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) guest_fail("Product Locked");

        $price = guest_price_percent($vendor_id, $api_detail['id'], $product['id']);
        if (!is_numeric($price['val_1'] ?? null)) guest_fail("Airtime size not available");

        $amount = (float)$amount_in;
        $discounted_amount = $amount - ($amount * (($price['val_1'] ?? 0) / 100));

        $identity = $phone_no;
        $description = "Airtime charges";
        $api_id = $api_detail['id'];
        $product_id = $product['id'];
        $api_base_url = $api_detail['api_base_url'];
        $extra_data += ['network' => $isp, 'phone_number' => $phone_no];
        break;
    }

    case 'data': {
        $isp = strtolower(trim(strip_tags($input['network'] ?? '')));
        $phone_no = sanitize_phone_number(trim(strip_tags($input['phone_number'] ?? $input['phone_no'] ?? '')));
        $type = strtolower(trim(strip_tags($input['type'] ?? $input['data_type'] ?? '')));
        $quantity = strtolower(trim(strip_tags($input['quantity'] ?? $input['plan_code'] ?? '')));

        if (!in_array($type, ['sme-data', 'cg-data', 'dd-data', 'shared-data'])) guest_fail("Invalid data type");
        if (empty($isp) || empty($phone_no) || !is_numeric($phone_no) || empty($quantity)) guest_fail("Incomplete Parameters");
        if (guest_id_blocked($vendor_id, $phone_no)) guest_fail("Error: Phone number has been blocked");
        if (!guest_daily_abuse_check($vendor_id, 'data', $phone_no)) guest_fail("ABUSE LIMIT: You have reached the maximum number of times you can buy airtime or data on this particular phone number ($phone_no) today. Please try again tomorrow.");

        $status_table_map = ['sme-data' => 'sas_sme_data_status', 'cg-data' => 'sas_cg_data_status', 'dd-data' => 'sas_dd_data_status', 'shared-data' => 'sas_shared_data_status'];
        $resolved = guest_resolve_enabled_api($vendor_id, $status_table_map[$type], $isp, $type);
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $isp);
        $status_row = guest_status_row($vendor_id, $status_table_map[$type], $isp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) guest_fail("Product Locked");

        $price = guest_price_fixed($vendor_id, $api_detail['id'], $product['id'], $quantity);
        if (empty($price) || !is_numeric($price['val_2'] ?? null)) guest_fail("Data size not available");

        $amount = (float)$price['val_2'];
        $discounted_amount = $amount;
        $identity = $phone_no;
        $description = ucwords(str_replace("-", " ", $type) . " charges");
        $api_id = $api_detail['id'];
        $product_id = $product['id'];
        $api_base_url = $api_detail['api_base_url'];
        $extra_data += ['network' => $isp, 'phone_number' => $phone_no, 'data_type' => $type, 'quantity' => $quantity];
        break;
    }

    case 'cable': {
        $isp = strtolower(trim(strip_tags($input['type'] ?? '')));
        $iuc_no = sanitize_phone_number(trim(strip_tags($input['iuc_number'] ?? $input['iuc_no'] ?? '')));
        $quantity = trim(strip_tags($input['package'] ?? $input['plan_code'] ?? ''));

        if (!in_array($isp, ['startimes', 'dstv', 'gotv', 'showmax'])) guest_fail("Invalid cable type");
        if (empty($iuc_no) || !is_numeric($iuc_no) || empty($quantity)) guest_fail("Incomplete Parameters");
        if (guest_id_blocked($vendor_id, $iuc_no)) guest_fail("Error: Cable IUC Number has been blocked");
        if (!guest_daily_abuse_check($vendor_id, 'cable', $iuc_no)) guest_fail("ABUSE LIMIT: You have reached the maximum number of times you can buy Cable TV subscription on this particular IUC number ($iuc_no) today. Please try again tomorrow.");

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_cable_status', $isp, 'cable');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $isp);
        $status_row = guest_status_row($vendor_id, 'sas_cable_status', $isp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) guest_fail("Product Locked");

        $price = guest_price_fixed($vendor_id, $api_detail['id'], $product['id'], $quantity);
        if (empty($price) || !is_numeric($price['val_2'] ?? null)) guest_fail("Cable size not available");

        $amount = (float)$price['val_2'];
        $discounted_amount = $amount;
        $identity = $iuc_no;
        $description = "Cable charges";
        $api_id = $api_detail['id'];
        $product_id = $product['id'];
        $api_base_url = $api_detail['api_base_url'];
        $extra_data += ['type' => $isp, 'iuc_number' => $iuc_no, 'package' => $quantity];
        break;
    }

    case 'electric': {
        $epp = strtolower(trim(strip_tags($input['provider'] ?? '')));
        $type = strtolower(trim(strip_tags($input['type'] ?? '')));
        $meter_number = sanitize_phone_number(trim(strip_tags($input['meter_number'] ?? $input['meter_no'] ?? '')));
        $amount_in = preg_replace("/[^0-9.]+/", "", trim(strip_tags($input['amount'] ?? '')));

        $electric_types = ["ekedc", "eedc", "ikedc", "jedc", "kedco", "ibedc", "phed", "aedc", "yedc", "bedc", "aba", "kaedco"];
        if (!in_array($epp, $electric_types)) guest_fail("Invalid electric type");
        if (empty($amount_in) || !is_numeric($amount_in) || empty($type) || empty($meter_number) || !is_numeric($meter_number)) guest_fail("Incomplete Parameters");
        if ((float)$amount_in <= 0 || (float)$amount_in > GUEST_MAX_CLIENT_AMOUNT) guest_fail("Invalid amount");
        if (guest_id_blocked($vendor_id, $meter_number)) guest_fail("Error: Meter number has been blocked");
        if (!guest_daily_abuse_check($vendor_id, 'electric', $meter_number)) guest_fail("ABUSE LIMIT: You have reached the maximum number of times you can buy token on this particular electricity meter number ($meter_number) today. Please try again tomorrow.");

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_electric_status', $epp, 'electric');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $epp);
        $status_row = guest_status_row($vendor_id, 'sas_electric_status', $epp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) guest_fail("Product Locked");

        $price = guest_price_percent($vendor_id, $api_detail['id'], $product['id']);
        if (!is_numeric($price['val_1'] ?? null)) guest_fail("Electric size not available");

        $amount = (float)$amount_in;
        $discounted_amount = $amount - ($amount * (($price['val_1'] ?? 0) / 100));
        $identity = $meter_number;
        $description = "Electric Charges";
        $api_id = $api_detail['id'];
        $product_id = $product['id'];
        $api_base_url = $api_detail['api_base_url'];
        $extra_data += ['provider' => $epp, 'meter_type' => $type, 'meter_number' => $meter_number];
        break;
    }

    case 'exam': {
        $epp = strtolower(trim(strip_tags($input['type'] ?? '')));
        $quantity = trim(strip_tags($input['quantity'] ?? ''));

        if (!in_array($epp, ['waec', 'neco', 'nabteb', 'jamb'])) guest_fail("Invalid exam type");
        if (empty($quantity)) guest_fail("Incomplete Parameters");
        // Exam abuse tracking is keyed per exam-type in the authenticated flow too (no phone/meter identity exists).
        if (!guest_daily_abuse_check($vendor_id, 'exam', $epp)) guest_fail("ABUSE LIMIT: You have reached the maximum number of times you can buy exam pins today. Please try again tomorrow.");

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_exam_status', $epp, 'exam');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $epp);
        $status_row = guest_status_row($vendor_id, 'sas_exam_status', $epp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) guest_fail("Product Locked");

        $price = guest_price_fixed($vendor_id, $api_detail['id'], $product['id'], $quantity);
        if (empty($price) || !is_numeric($price['val_2'] ?? null)) guest_fail("Exam size not available");

        $amount = (float)$price['val_2'];
        $discounted_amount = $amount;
        $identity = $epp;
        $description = "Exam Charges";
        $api_id = $api_detail['id'];
        $product_id = $product['id'];
        $api_base_url = $api_detail['api_base_url'];
        $extra_data += ['type' => $epp, 'quantity' => $quantity];
        break;
    }

    case 'betting': {
        $epp = strtolower(trim(strip_tags($input['provider'] ?? '')));
        $customer_id = sanitize_phone_number(trim(strip_tags($input['customer_id'] ?? '')));
        $amount_in = preg_replace("/[^0-9.]+/", "", trim(strip_tags($input['amount'] ?? '')));

        $betting_providers = ['msport', 'naijabet', 'nairabet', 'bet9ja-agent', 'betland', 'betlion', 'supabet', 'bet9ja', 'bangbet', 'betking', '1xbet', 'betway', 'merrybet', 'mlotto', 'western-lotto', 'hallabet', 'green-lotto'];
        if (!in_array($epp, $betting_providers)) guest_fail("Invalid betting type");
        if (empty($amount_in) || !is_numeric($amount_in) || empty($customer_id) || !is_numeric($customer_id)) guest_fail("Incomplete Parameters");
        if ((float)$amount_in <= 0 || (float)$amount_in > GUEST_MAX_CLIENT_AMOUNT) guest_fail("Invalid amount");
        if (guest_id_blocked($vendor_id, $customer_id)) guest_fail("Error: Customer number has been blocked");
        if (!guest_daily_abuse_check($vendor_id, 'betting', $customer_id)) guest_fail("ABUSE LIMIT: You have reached the maximum number of times you can fund betting wallet on this particular Betting ID ($customer_id) today. Please try again tomorrow.");

        $resolved = guest_resolve_enabled_api($vendor_id, 'sas_betting_status', $epp, 'betting');
        if (!$resolved['ok']) guest_fail($resolved['error']);
        $api_detail = $resolved['api_detail'];

        $product = guest_product_row($vendor_id, $epp);
        $status_row = guest_status_row($vendor_id, 'sas_betting_status', $epp);
        if (!$product || $product['status'] != 1 || !$status_row || $status_row['status'] != 1) guest_fail("Product Locked");

        $price = guest_price_percent($vendor_id, $api_detail['id'], $product['id']);
        if (!is_numeric($price['val_1'] ?? null)) guest_fail("Betting size not available");

        $amount = (float)$amount_in;
        $discounted_amount = $amount - ($amount * (($price['val_1'] ?? 0) / 100));
        $identity = $customer_id;
        $description = "Betting Charges";
        $api_id = $api_detail['id'];
        $product_id = $product['id'];
        $api_base_url = $api_detail['api_base_url'];
        $extra_data += ['provider' => $epp, 'customer_id' => $customer_id];
        break;
    }

    default:
        guest_fail("Unknown or missing service. Use one of: airtime, data, cable, electric, exam, betting");
}

if ($discounted_amount === null || $discounted_amount <= 0) {
    guest_fail("Unable to determine transaction amount");
}

$reference = guest_create_order($vendor_id, $service, $identity, $amount, $discounted_amount, $description, $extra_data);
guest_update_order($reference, 'api_id', $api_id);
guest_update_order($reference, 'product_id', $product_id);
guest_update_order($reference, 'api_website', $api_base_url);

if (empty($guest_email)) {
    $guest_email = "guest+" . $reference . "@" . preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'guest.local');
}

$callback_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST'] . '/web/guest-payment-complete.php?reference=' . urlencode($reference);

$res_json = makePayhubRequest("POST", "api/transaction/initialize", [
    "email"        => $guest_email,
    "amount"       => $discounted_amount,
    "name"         => $guest_phone_contact ?: "Guest Customer",
    "reference"    => $reference,
    "callback_url" => $callback_url,
    "metadata"     => json_encode([
        "vendor_id" => $vendor_id,
        "reference" => $reference,
        "source"    => "guest-app",
        "target"    => "guest"
    ])
], $vendor_id, false);

$res = json_decode($res_json, true);
$inner = isset($res['json_result']) ? json_decode($res['json_result'], true) : $res;
$checkout_url = $inner['data']['authorization_url']
    ?? ($inner['authorization_url']
    ?? ($inner['data']['checkout_url']
    ?? ($inner['checkout_url'] ?? '')));

if (empty($checkout_url)) {
    guest_update_order($reference, 'status', (string)GUEST_STATUS_FAILED);
    guest_fail($res['message'] ?? "Could not initialize payment. Please try again.", 502);
}

// PayHub assigns its OWN transaction reference on initialize (observed live: format "PH_...",
// unrelated to the one we submitted) — every later verify/webhook call is keyed by PayHub's
// reference, not ours. Capture it now, while we have it; guest_attempt_paid_fulfillment() and
// guest-webhook.php both verify against this column, falling back to our own reference only if
// PayHub genuinely never returned one (defensive — the metadata.reference we also send below
// exists specifically as the reconciliation fallback for that case, mirroring
// processPayhubSuccess()'s proven metadata-reference matching for the authenticated flow).
$payhub_reference = $inner['data']['reference'] ?? ($inner['reference'] ?? null);
guest_update_order($reference, 'payment_reference', !empty($payhub_reference) ? $payhub_reference : $reference);

guest_json([
    "status" => "success",
    "reference" => $reference,
    "amount" => $discounted_amount,
    "checkout_url" => $checkout_url
]);
