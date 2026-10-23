<?php
/**
 * Reloadly Gift Card API Implementation
 */

function encryptGiftCode($data, $vendor_id) {
    global $connection_server;
    $q = mysqli_query($connection_server, "SELECT print_hub_secret FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
    $r = mysqli_fetch_assoc($q);
    $key = $r['print_hub_secret'] ?? 'GIFT-DEFAULT-KEY';
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decryptGiftCode($data, $vendor_id) {
    global $connection_server;
    $q = mysqli_query($connection_server, "SELECT print_hub_secret FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
    $r = mysqli_fetch_assoc($q);
    $key = $r['print_hub_secret'] ?? 'GIFT-DEFAULT-KEY';
    $parts = explode('::', base64_decode($data));
    if (count($parts) === 2) {
        return openssl_decrypt($parts[0], 'aes-256-cbc', $key, 0, $parts[1]);
    }
    return $data; // Fallback to raw if not encrypted
}

function getReloadlyAccessToken($vendor_id = 0) {
    global $connection_server;

    $vid = (int)$vendor_id;
    $q = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' AND api_type='gift-card' AND status='1' LIMIT 1");
    if(!$q || mysqli_num_rows($q) == 0) {
        // Fallback to Super Admin keys
        $q = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='gift-card' AND status='1' LIMIT 1");
        if (!$q || mysqli_num_rows($q) == 0) return false;
    }

    $r = mysqli_fetch_assoc($q);
    $combined_key = $r['api_key'] ?? ($r['secret_key'] ?? ''); // Compatibility with both tables
    if (!strpos($combined_key, ':')) return false;

    list($client_id, $client_secret) = explode(':', $combined_key);

    // Cache check
    $cache_key = "reloadly_token_" . $vid;
    if (isset($_SESSION[$cache_key]) && isset($_SESSION[$cache_key."_exp"]) && time() < $_SESSION[$cache_key."_exp"]) {
        return $_SESSION[$cache_key];
    }

    $url = "https://auth.reloadly.com/oauth/token";
    $data = [
        "client_id" => $client_id,
        "client_secret" => $client_secret,
        "grant_type" => "client_credentials",
        "audience" => "https://giftcards.reloadly.com"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);

    $response = curl_exec($ch);
    $res_data = json_decode($response, true);
    curl_close($ch);

    if (isset($res_data['access_token'])) {
        $_SESSION[$cache_key] = $res_data['access_token'];
        $_SESSION[$cache_key."_exp"] = time() + ($res_data['expires_in'] ?? 3600) - 60;
        return $res_data['access_token'];
    }

    return false;
}

function fetchReloadlyProducts($accessToken, $countryCode = 'US', $page = 1, $size = 20, $search = '') {
    $url = "https://giftcards.reloadly.com/products?countryCode=$countryCode&page=$page&size=$size";
    if(!empty($search)) $url .= "&productName=" . urlencode($search);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Accept: application/com.reloadly.giftcards-v1+json"
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function fetchReloadlyCategories($accessToken) {
    $url = "https://giftcards.reloadly.com/product-categories";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Accept: application/com.reloadly.giftcards-v1+json"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function placeReloadlyOrder($accessToken, $productId, $amount, $quantity = 1, $recipientEmail = '') {
    if (!$accessToken) {
        return [
            "status" => "FAILED",
            "message" => "Invalid or missing API credentials."
        ];
    }

    $url = "https://giftcards.reloadly.com/orders";

    $data = [
        "productId" => $productId,
        "amount" => $amount,
        "quantity" => $quantity,
        "recipientEmail" => $recipientEmail,
        "customIdentifier" => "GC_" . time()
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json",
        "Accept: application/com.reloadly.giftcards-v1+json"
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function getLiveExchangeRate($from_currency, $to_currency = 'NGN', $vendor_id = 0, $product_type = 'generic') {
    global $connection_server;
    $from = strtoupper(trim($from_currency));
    $to = strtoupper(trim($to_currency));

    if ($from === $to) return 1.0;

    // 1. Check Cache
    $cache_key = "live_rate_{$from}_{$to}";
    $q_cache = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='0' AND setting_name='$cache_key' LIMIT 1");
    $cache = mysqli_fetch_assoc($q_cache);

    // Check if cache is fresh (within last 12 hours)
    // For simplicity in this script, we'll just use the value if exists, but in production, check timestamps.

    $fallback_rates = ['USD' => 1600, 'GBP' => 2000, 'EUR' => 1750, 'CAD' => 1100, 'ZAR' => 85, 'GHS' => 100, 'KES' => 12, 'AED' => 430];
    $current_rate = $fallback_rates[$from] ?? 1.0;

    if ($cache) {
        $current_rate = (float)$cache['setting_value'];
    }

    // 2. Fetch from API (Multi-provider strategy)
    $api_rate = 0;

    // Provider A: Chimoney (Preferred if available)
    require_once(__DIR__ . "/api-gateway/chimoney.php");
    $chimoney_api = getChimoneyDetails($vendor_id);
    if ($chimoney_api) {
        $bridge = new ChimoneyBridge($chimoney_api['api_key']);
        $c_res = $bridge->getExchangeRates();
        if ($c_res['status'] === 'success' && isset($c_res['data']['data'][$from])) {
            // Chimoney rates are usually relative to USD. If from is USD, use NGN.
            // Adjust based on Chimoney's response structure
            if ($from === 'USD' && isset($c_res['data']['data']['NGN'])) {
                $api_rate = (float)$c_res['data']['data']['NGN'];
            }
        }
    }

    // Provider B: ExchangeRate-API (Fallback)
    if ($api_rate <= 0) {
        $url = "https://api.exchangerate-api.com/v4/latest/$from";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['rates'][$to])) {
                $api_rate = (float)$data['rates'][$to];
            }
        }
    }

    if ($api_rate > 0) {
        $current_rate = $api_rate;
        // Update cache
        mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value)
            VALUES (0, '$cache_key', '$current_rate')
            ON DUPLICATE KEY UPDATE setting_value='$current_rate'");

        // Also update legacy table for compatibility
        mysqli_query($connection_server, "INSERT INTO sas_dollar_exchange_rates (vendor_id, product_type, currency, debit_amount, credit_amount)
            VALUES (0, 'gift-card', 'NGN', '$current_rate', '$current_rate')
            ON DUPLICATE KEY UPDATE debit_amount='$current_rate', credit_amount='$current_rate'");
    }

    // 3. Apply Vendor Spread (Hidden fee for NGN conversions)
    if ($vendor_id > 0 && $to === 'NGN') {
        $spread_key = ($product_type === 'virtual-card') ? 'vc_conversion_spread' : 'gc_conversion_spread';
        $q_spread = mysqli_query($connection_server, "SELECT setting_value FROM sas_settings WHERE vendor_id='$vendor_id' AND setting_name='$spread_key' LIMIT 1");
        if ($rs = mysqli_fetch_assoc($q_spread)) {
            $spread = (float)$rs['setting_value'];
            // Spread is in NGN per unit of 'from' currency (usually USD)
            $current_rate += $spread;
        }
    }

    return $current_rate;
}

function getLiveUSDToNGNRate($vendor_id) {
    return getLiveExchangeRate('USD', 'NGN', $vendor_id);
}

/**
 * Escrow Engine: Hold Funds
 */
function holdP2PFunds($username, $amount) {
    global $connection_server;
    $u = mysqli_real_escape_string($connection_server, $username);
    $amt = (float)$amount;

    // Check balance
    $q = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE username='$u' LIMIT 1");
    $r = mysqli_fetch_assoc($q);
    if($r && $r['balance'] >= $amt){
        // Move to escrow
        mysqli_query($connection_server, "UPDATE sas_users SET balance = balance - $amt, pending_escrow = pending_escrow + $amt WHERE username='$u'");
        return true;
    }
    return false;
}

/**
 * Escrow Engine: Release Funds
 */
function releaseP2PFunds($tradeId) {
    global $connection_server;
    $tid = (int)$tradeId;

    $q = mysqli_query($connection_server, "SELECT * FROM sas_p2p_trades WHERE id = '$tid' AND status = 'funded'");
    $trade = mysqli_fetch_assoc($q);

    if ($trade) {
        $amount = (float)$trade['amount_ngn'];
        $seller_id = (int)$trade['seller_id'];
        $buyer_id = (int)$trade['buyer_id'];
        $card_id = (int)$trade['card_id'];
        $vid = (int)$trade['vendor_id'];

        // Get Usernames
        $qs = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE id='$seller_id' LIMIT 1");
        $qb = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE id='$buyer_id' LIMIT 1");
        $seller_user = mysqli_fetch_assoc($qs)['username'] ?? "";
        $buyer_user = mysqli_fetch_assoc($qb)['username'] ?? "";

        // Fee was already calculated and stored in sas_p2p_trades during buy_p2p action
        $fee = (float)$trade['fee_ngn'];
        $total_buyer_cost = $amount + $fee;

        // 1. Debit Buyer's Escrow
        mysqli_query($connection_server, "UPDATE sas_users SET pending_escrow = pending_escrow - $total_buyer_cost WHERE id = '$buyer_id'");

        // 2. Credit Seller's Wallet (using ledger)
        $finalSellerAmount = $amount;
        chargeOtherUser($seller_user, "credit", "p2p_sale", "P2P Gift Card Sale", "TRD_".$tid, "INTERNAL", $amount, $finalSellerAmount, "Sale of Gift Card via P2P (Trade ID: $tid)", "WEB", $_SERVER["HTTP_HOST"], 1);

        // 3. Credit Vendor for the Fee
        mysqli_query($connection_server, "UPDATE sas_vendors SET balance = balance + $fee WHERE id = '$vid'");
        mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status)
            SELECT '$vid', 'p2p_fee', 'P2P Trade Commission', 'FEE_$tid', '$fee', '$fee', balance - $fee, balance, 'Commission from P2P Gift Card Trade #$tid', '".$_SERVER["HTTP_HOST"]."', '1' FROM sas_vendors WHERE id='$vid'");

        // Record Profit for Platform (Super Admin tracking)
        mysqli_query($connection_server, "INSERT INTO sas_platform_earnings (vendor_id, amount, source, reference) VALUES ('$vid', '$fee', 'P2P_Giftcard', '$tid')");

        // 4. Transfer Card Ownership
        mysqli_query($connection_server, "UPDATE sas_giftcard_inventory SET current_owner_id = '$buyer_id', is_for_sale = 0 WHERE id = '$card_id'");

        // 5. Update Trade Status
        mysqli_query($connection_server, "UPDATE sas_p2p_trades SET status = 'released', released_at = CURRENT_TIMESTAMP WHERE id = '$tid'");

        return true;
    }
    return false;
}
?>
