<?php
require_once(__DIR__ . "/../../includes/ChimoneyBridge.php");

function getChimoneyDetails($vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();

    // Check for "chimoney" in sas_apis for this vendor
    $q = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' AND api_type='chimoney' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    // Fallback to platform keys if no vendor keys are present
    $q_p = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='0' AND api_type='chimoney' LIMIT 1");
    if ($q_p && $r_p = mysqli_fetch_assoc($q_p)) {
        return $r_p;
    }

    return null;
}

function issueVirtualCard($vid, $username, $name, $email, $amount_usd) {
    global $connection_server;
    $api_details = getChimoneyDetails($vid);
    if (!$api_details) return ['status' => 'error', 'message' => 'Chimoney API not configured'];

    $bridge = new ChimoneyBridge($api_details['api_key']);
    $payload = [
        "name" => $name,
        "email" => $email,
        "amount" => $amount_usd,
        "type" => "virtual_card",
        "currency" => "USD"
    ];

    $res = $bridge->createVirtualCard($payload);
    if ($res['status'] === 'success') {
        $card_data = $res['data']['data'] ?? $res['data'];
        $card_id = $card_data['card_id'] ?? ($card_data['id'] ?? "");
        $pan = $card_data['masked_pan'] ?? ($card_data['pan'] ?? "");
        $expiry = $card_data['expiry'] ?? "12/26";
        $cvv = $card_data['cvv'] ?? "000";
        $exp_parts = explode("/", $expiry);
        $month = $exp_parts[0];
        $year = $exp_parts[1] ?? "";

        $ref = "VC" . time() . rand(100, 999);
        $meta = mysqli_real_escape_string($connection_server, json_encode($card_data));

        $q = mysqli_query($connection_server, "INSERT INTO sas_virtual_cards_v2
            (vendor_id, username, reference, chimoney_card_id, card_name, masked_pan, expiry_month, expiry_year, cvv, balance_usd, status, metadata)
            VALUES
            ('$vid', '$username', '$ref', '$card_id', '$name', '$pan', '$month', '$year', '$cvv', '$amount_usd', 'active', '$meta')");

        if ($q) return ['status' => 'success', 'reference' => $ref, 'card_id' => $card_id];
        else return ['status' => 'error', 'message' => 'Database update failed: ' . mysqli_error($connection_server)];
    }

    return ['status' => 'error', 'message' => $res['data']['message'] ?? 'Issuance failed'];
}

function fundVirtualCardV2($vid, $username, $card_ref, $amount_usd) {
    global $connection_server;
    $api_details = getChimoneyDetails($vid);
    if (!$api_details) return ['status' => 'error', 'message' => 'Chimoney API not configured'];

    $ref_esc = mysqli_real_escape_string($connection_server, $card_ref);
    $q_card = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_esc' LIMIT 1");
    if (!$q_card || mysqli_num_rows($q_card) == 0) return ['status' => 'error', 'message' => 'Card not found'];
    $card = mysqli_fetch_assoc($q_card);

    if ($card['status'] !== 'active') return ['status' => 'error', 'message' => 'Card is not active'];

    $bridge = new ChimoneyBridge($api_details['api_key']);
    $res = $bridge->fundVirtualCard($card['chimoney_card_id'], $amount_usd);

    if ($res['status'] === 'success') {
        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET balance_usd = balance_usd + $amount_usd WHERE id='".$card['id']."'");
        return ['status' => 'success'];
    }

    return ['status' => 'error', 'message' => $res['data']['message'] ?? 'Funding failed'];
}

function freezeVirtualCardV2($vid, $card_ref, $is_auto = 0) {
    global $connection_server;
    $api_details = getChimoneyDetails($vid);
    if (!$api_details) return ['status' => 'error', 'message' => 'Chimoney API not configured'];

    $ref_esc = mysqli_real_escape_string($connection_server, $card_ref);
    $q_card = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_esc' LIMIT 1");
    if (!$q_card || mysqli_num_rows($q_card) == 0) return ['status' => 'error', 'message' => 'Card not found'];
    $card = mysqli_fetch_assoc($q_card);

    $bridge = new ChimoneyBridge($api_details['api_key']);
    $res = $bridge->freezeCard($card['chimoney_card_id']);

    if ($res['status'] === 'success' || (isset($res['data']['message']) && stripos($res['data']['message'], 'already frozen') !== false)) {
        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET status='frozen', is_frozen_auto='$is_auto' WHERE id='".$card['id']."'");
        return ['status' => 'success'];
    }

    return ['status' => 'error', 'message' => $res['data']['message'] ?? 'Freeze operation failed'];
}

function unfreezeVirtualCardV2($vid, $card_ref) {
    global $connection_server;
    $api_details = getChimoneyDetails($vid);
    if (!$api_details) return ['status' => 'error', 'message' => 'Chimoney API not configured'];

    $ref_esc = mysqli_real_escape_string($connection_server, $card_ref);
    $q_card = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_esc' LIMIT 1");
    if (!$q_card || mysqli_num_rows($q_card) == 0) return ['status' => 'error', 'message' => 'Card not found'];
    $card = mysqli_fetch_assoc($q_card);

    $bridge = new ChimoneyBridge($api_details['api_key']);
    $res = $bridge->unfreezeCard($card['chimoney_card_id']);

    if ($res['status'] === 'success' || (isset($res['data']['message']) && stripos($res['data']['message'], 'not frozen') !== false)) {
        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET status='active', is_frozen_auto='0' WHERE id='".$card['id']."'");
        return ['status' => 'success'];
    }

    return ['status' => 'error', 'message' => $res['data']['message'] ?? 'Unfreeze operation failed'];
}
