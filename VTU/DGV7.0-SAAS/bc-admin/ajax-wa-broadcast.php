<?php session_start();
include("../func/bc-admin-config.php");
include("../func/bc-whatsapp.php");
require_once("../func/bc-giftcard-func.php"); // For Live USD to NGN rate

header('Content-Type: application/json');

$vid = $get_logged_admin_details['id'];
if (!$vid) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$phones = $input['phones'] ?? [];
$template_name = $input['template_name'] ?? '';

if (empty($phones) || empty($template_name)) {
    echo json_encode(['success' => false, 'error' => 'Missing recipients or template']);
    exit();
}

$count = count($phones);
if ($count > 200) {
    echo json_encode(['success' => false, 'error' => 'Maximum 200 recipients allowed per batch.']);
    exit();
}

// 1. Calculate Cost
$live_rate = getLiveUSDToNGNRate(0);
$marketing_usd = getSuperAdminOption('wa_marketing_base_usd', '0.022');
$profit_margin = getSuperAdminOption('wa_profit_margin_percent', '15');
$markup_multiplier = 1 + ($profit_margin / 100);

$rate_per_message = round($marketing_usd * $live_rate * $markup_multiplier, 2);
$total_cost = round($rate_per_message * $count, 2);

// 2. Check Wallet Balance
$vendor_q = mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1");
$vendor_r = mysqli_fetch_assoc($vendor_q);

if (!$vendor_r || $vendor_r['balance'] < $total_cost) {
    echo json_encode(['success' => false, 'error' => "Insufficient wallet balance. You need ₦" . number_format($total_cost, 2)]);
    exit();
}

// 3. Deduct from Wallet & Record Transactions
mysqli_query($connection_server, "UPDATE sas_vendors SET balance = balance - $total_cost WHERE id='$vid'");

$ref = "WA_BRD_" . time() . "_" . rand(100, 999);
$desc = "WhatsApp Broadcast Campaign ($count users)";
mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) 
    VALUES ('$vid', 'whatsapp', 'WhatsApp Marketing', '$ref', '$total_cost', '$total_cost', '{$vendor_r['balance']}', '{$vendor_r['balance']}' - $total_cost, '$desc', '".$_SERVER["HTTP_HOST"]."', '1')");

// 4. Record Super Admin Profit
$base_cost_ngn = round($marketing_usd * $live_rate * $count, 2);
$profit = $total_cost - $base_cost_ngn;
if ($profit > 0) {
    mysqli_query($connection_server, "INSERT INTO sas_platform_earnings (vendor_id, amount, source, reference) VALUES ('$vid', '$profit', 'WhatsApp Broadcast', '$ref')");
}

// 5. Fetch Template Details
$tmpl_q = mysqli_query($connection_server, "SELECT header_type, media_url FROM sas_wa_templates WHERE vendor_id='$vid' AND template_name='$template_name' LIMIT 1");
$tmpl_data = mysqli_fetch_assoc($tmpl_q);
$media_url = ($tmpl_data && $tmpl_data['header_type'] === 'IMAGE') ? $tmpl_data['media_url'] : null;

// 6. Dispatch Templates
$sent = 0;
$failed = 0;
foreach ($phones as $phone) {
    if (sendWhatsAppTemplate($phone, $template_name, 'en_US', $media_url)) {
        $sent++;
    } else {
        $failed++;
    }
    usleep(100000); // 100ms delay
}

echo json_encode([
    'success' => true,
    'sent' => $sent,
    'failed' => $failed,
    'cost' => number_format($total_cost, 2)
]);
