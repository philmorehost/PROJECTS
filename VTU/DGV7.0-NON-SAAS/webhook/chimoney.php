<?php
require_once("../func/bc-connect.php");
require_once("../func/api-gateway/chimoney.php");

$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Log webhook for debugging
@file_put_contents("../logs/chimoney_webhook.log", "[" . date('Y-m-d H:i:s') . "] RAW: $payload\n", FILE_APPEND);

if (!$data) exit();

$event = $data['event'] ?? '';
$card_id = $data['data']['card_id'] ?? ($data['data']['id'] ?? '');

if ($event === 'card.transaction.failed' && !empty($card_id)) {
    $reason = $data['data']['failure_reason'] ?? '';
    if (stripos($reason, 'insufficient') !== false) {
        // Auto-freeze logic
        $card_id_esc = mysqli_real_escape_string($connection_server, $card_id);
        $q = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE chimoney_card_id='$card_id_esc' LIMIT 1");
        if ($card = mysqli_fetch_assoc($q)) {
            freezeVirtualCardV2($card['vendor_id'], $card['reference'], 1);

            // Notify user via email
            $u_q = mysqli_query($connection_server, "SELECT email, firstname FROM sas_users WHERE vendor_id='".$card['vendor_id']."' AND username='".$card['username']."' LIMIT 1");
            if ($user = mysqli_fetch_assoc($u_q)) {
                $subject = "URGENT: Your Virtual Card has been Auto-Frozen";
                $body = "Hi " . $user['firstname'] . ",<br><br>Your virtual card (Ref: " . $card['reference'] . ") has been auto-frozen due to a failed transaction (Insufficient Funds).<br><br>To prevent further failed transaction fees from the provider, we have temporarily locked the card. Please fund your main wallet and reactivate the card from your dashboard.<br><br>Thank you.";
                // Assuming sendVendorEmail handles context correctly when $GLOBALS['vendor_id'] is set
                $GLOBALS['vendor_id'] = $card['vendor_id'];
                sendVendorEmail($user['email'], $subject, $body);
            }
        }
    }
}

echo json_encode(['status' => 'received']);
