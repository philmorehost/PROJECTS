<?php
require_once(__DIR__ . "/../../includes/BSICardsBridge.php");

function getBSICardsDetails($vid = null) {
    global $connection_server, $get_logged_admin_details;
    if (!$vid) $vid = $get_logged_admin_details["id"];

    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $q = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid_esc' AND api_type='bsicards' LIMIT 1");
    if ($r = mysqli_fetch_assoc($q)) {
        $keys = explode("|", $r['api_key']);
        return [
            'id' => $r['id'],
            'public_key' => $keys[0] ?? '',
            'secret_key' => $keys[1] ?? '',
            'status' => $r['status']
        ];
    }
    return null;
}

function issueVirtualCardBSICards($vid, $username, $product_id, $amount_usd) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $uname_esc = mysqli_real_escape_string($connection_server, $username);
    $pid_esc = mysqli_real_escape_string($connection_server, $product_id);

    $api_details = getBSICardsDetails($vid);
    if (!$api_details) return ['status' => 'error', 'message' => 'BSICards API not configured'];

    $bridge = new BSICardsBridge($api_details['public_key'], $api_details['secret_key']);

    $u_q = mysqli_query($connection_server, "SELECT email, firstname, lastname, transaction_pin FROM sas_users WHERE username='$uname_esc' AND vendor_id='$vid_esc' LIMIT 1");
    $user = mysqli_fetch_assoc($u_q);
    $email = $user['email'];
    $name = $user['firstname'] . ' ' . $user['lastname'];
    $pin = !empty($user['transaction_pin']) ? substr($user['transaction_pin'], 0, 4) : "1234";

    if (strpos($product_id, 'visa') !== false) {
        // Visa requires KYC files normally, but for this automated flow,
        // we'll attempt issuance or fallback with a clear error if BSI rejects.
        $res = $bridge->createDigitalVisa($email, $user['firstname'], $user['lastname']);
    } else {
        $res = $bridge->createMasterCard($email, $name, $pin);
    }

    if ($res['status'] == 'success') {
        $card_data = $res['data'];
        $ref = "BSI_" . substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 10);

        $card_id = $card_data['cardid'] ?? ($card_data['data']['cardid'] ?? '');
        $pan = $card_data['pan'] ?? ($card_data['data']['pan'] ?? '**** **** **** ****');

        $q = mysqli_query($connection_server, "INSERT INTO sas_virtual_cards_v2
            (vendor_id, username, reference, chimoney_card_id, card_name, masked_pan, balance_usd, status, provider)
            VALUES ('$vid_esc', '$uname_esc', '$ref', '$card_id', 'BSICards Virtual', '$pan', '0', 'active', 'bsicards')");

        if ($q) {
            if ($amount_usd > 0) {
                if (strpos($product_id, 'visa') !== false) {
                    $fund_res = $bridge->fundDigitalVisa($email, $card_id, $amount_usd);
                } else {
                    $fund_res = $bridge->fundMasterCard($email, $card_id, $amount_usd);
                }

                if ($fund_res['status'] == 'success') {
                    mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET balance_usd = $amount_usd WHERE reference='$ref'");
                }
            }
            return ['status' => 'success', 'reference' => $ref, 'card_id' => $card_id];
        }
    }
    return ['status' => 'error', 'message' => $res['data']['message'] ?? 'Issuance failed'];
}

function fundVirtualCardBSICards($vid, $ref, $amount_usd) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $ref_esc = mysqli_real_escape_string($connection_server, $ref);

    $api_details = getBSICardsDetails($vid);
    if (!$api_details) return ['status' => 'error', 'message' => 'BSICards API not configured'];

    $q_card = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_esc' LIMIT 1");
    if ($card = mysqli_fetch_assoc($q_card)) {
        $u_q = mysqli_query($connection_server, "SELECT email FROM sas_users WHERE username='".$card['username']."' AND vendor_id='$vid_esc' LIMIT 1");
        $user = mysqli_fetch_assoc($u_q);

        $bridge = new BSICardsBridge($api_details['public_key'], $api_details['secret_key']);

        // Use Mastercard or Visa funding based on card metadata/pan if needed,
        // here we assume Mastercard bridge methods for general BSI funding.
        $res = $bridge->fundMasterCard($user['email'], $card['chimoney_card_id'], $amount_usd);

        if ($res['status'] == 'success') {
            $amt_esc = (float)$amount_usd;
            mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET balance_usd = balance_usd + $amt_esc WHERE id='".$card['id']."'");
            return ['status' => 'success'];
        }
        return ['status' => 'error', 'message' => $res['data']['message'] ?? 'Funding failed'];
    }
    return ['status' => 'error', 'message' => 'Card not found'];
}
