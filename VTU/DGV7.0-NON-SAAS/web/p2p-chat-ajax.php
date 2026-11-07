<?php
session_start();
include("../func/bc-config.php");

if (!isset($_SESSION["user_session"]) && !isset($_SESSION["admin_session"])) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$vid = (int)($get_logged_user_details['vendor_id'] ?? $get_logged_admin_details['id']);
$user_id = (int)($get_logged_user_details['id'] ?? 0);
$is_admin = isset($_SESSION["admin_session"]);

if (isset($_POST['action']) && $_POST['action'] == 'send_message') {
    $trade_id = (int)$_POST['trade_id'];
    $message = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['message'])));
    $sender_type = $is_admin ? 'admin' : 'user';

    // Get Trade Details to identify parties
    $q_t = mysqli_query($connection_server, "SELECT * FROM `sas_p2p_trades` WHERE id='$trade_id' AND vendor_id='$vid' LIMIT 1");
    $trade = mysqli_fetch_assoc($q_t);

    if (!$trade) die(json_encode(['status' => 'error', 'message' => 'Trade not found']));

    // Logic for receiver:
    // If user is buyer, receiver could be seller or admin (default to admin for support or other party)
    // For this simplified P2P chat, we'll allow targeting a specific receiver_id
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);

    $sql = "INSERT INTO `sas_p2p_messages` (vendor_id, trade_id, sender_id, receiver_id, sender_type, message)
            VALUES ('$vid', '$trade_id', '$user_id', '$receiver_id', '$sender_type', '$message')";

    if (mysqli_query($connection_server, $sql)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($connection_server)]);
    }
    return;
}

if (isset($_GET['action']) && $_GET['action'] == 'fetch_messages') {
    $trade_id = (int)$_GET['trade_id'];
    $q = mysqli_query($connection_server, "SELECT * FROM `sas_p2p_messages` WHERE trade_id='$trade_id' AND vendor_id='$vid' ORDER BY id ASC");
    $msgs = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $r['is_mine'] = ($is_admin && $r['sender_type'] == 'admin') || (!$is_admin && $r['sender_type'] == 'user' && $r['sender_id'] == $user_id);
        $msgs[] = $r;
    }
    echo json_encode(['status' => 'success', 'messages' => $msgs]);
    return;
}
?>
