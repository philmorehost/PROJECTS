<?php
// cron/sync_sender_ids.php
// This script should be run periodically (e.g., hourly) via a cron job.

if (PHP_SAPI !== 'cli') {
    die("Direct access forbidden. This script must be run from the command line.");
}

include_once(__DIR__ . "/../func/bc-connect.php");

echo "========================================================\n";
echo "Starting Sender ID Sync (SAAS) - " . date('Y-m-d H:i:s') . "\n";

// 1. Get all pending sender IDs
$pending_q = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE status='2'");

if(mysqli_num_rows($pending_q) == 0) {
    echo "No pending Sender IDs found.\n";
    exit;
}

while ($row = mysqli_fetch_assoc($pending_q)) {
    $vendor_id = $row['vendor_id'];
    $sender_id = $row['sender_id'];
    
    echo "Checking status for Sender ID: '$sender_id' (Vendor: $vendor_id)... ";
    
    // 2. Get the PhilmoreSMS API token for this vendor
    $api_q = mysqli_query($connection_server, "SELECT api_key FROM sas_apis WHERE vendor_id='$vendor_id' AND api_type='bulk-sms' AND api_base_url LIKE '%philmoresms.com%' AND status='1' LIMIT 1");
    
    if ($api_q && mysqli_num_rows($api_q) > 0) {
        $api_row = mysqli_fetch_assoc($api_q);
        $token = trim($api_row['api_key']);
        
        if (empty($token)) {
            echo "[SKIP] API Key empty\n";
            continue;
        }
        
        // 3. Call PhilmoreSMS check API
        $url = "https://app.philmoresms.com/api/check_senderID.php?senderID=" . urlencode($sender_id);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token"
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $data = json_decode($response, true);
            if (isset($data['status']) && $data['status'] === 'success') {
                $msg = strtolower($data['msg']);
                
                if (strpos($msg, 'approved') !== false) {
                    echo "[APPROVED] Updating DB...\n";
                    mysqli_query($connection_server, "UPDATE sas_bulk_sms_sender_id SET status='1' WHERE id='" . $row['id'] . "'");
                } elseif (strpos($msg, 'rejected') !== false) {
                    echo "[REJECTED] Updating DB...\n";
                    mysqli_query($connection_server, "UPDATE sas_bulk_sms_sender_id SET status='3' WHERE id='" . $row['id'] . "'");
                } else {
                    echo "[PENDING]\n";
                }
            } else {
                echo "[ERROR] Invalid response: $response\n";
            }
        } elseif ($http_code == 404) {
            echo "[NOT FOUND]\n";
        } else {
            echo "[HTTP $http_code]\n";
        }
    } else {
        echo "[SKIP] API not active\n";
    }
}

echo "Sender ID Sync Completed - " . date('Y-m-d H:i:s') . "\n";
echo "========================================================\n";
?>
