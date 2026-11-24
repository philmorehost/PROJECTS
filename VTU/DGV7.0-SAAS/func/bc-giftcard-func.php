<?php
require_once __DIR__.'/bc-giftcard-safety.php';
/**
 * Reloadly Gift Card API Implementation
 */

function bc_giftcard_kms_key($vendor_id) {
    global $connection_server;
    $envKey = trim((string)(getenv('BC_GIFTCARD_KMS_KEY') ?: ''));
    if ($envKey !== '' && strlen($envKey) >= 32) {
        return hash('sha256', $envKey . '|vendor:' . (int)$vendor_id, true);
    }
    if ($connection_server instanceof mysqli) {
        $q = mysqli_query($connection_server, "SELECT print_hub_secret FROM sas_vendors WHERE id='" . (int)$vendor_id . "' LIMIT 1");
        $r = $q ? mysqli_fetch_assoc($q) : null;
        $vSecret = trim((string)($r['print_hub_secret'] ?? ''));
        if ($vSecret !== '' && strlen($vSecret) >= 16) {
            return hash('sha256', $vSecret, true);
        }
    }
    return null;
}

function encryptGiftCode($data, $vendor_id) {
    $rawKey = bc_giftcard_kms_key($vendor_id);
    if ($rawKey !== null) {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt((string)$data, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct !== false && strlen($tag) === 16) {
            return 'v2:' . base64_encode($iv . $tag . $ct);
        }
    }
    if (getenv('BC_GIFTCARD_WORKER_ENABLED') === '1') {
        throw new RuntimeException('giftcard_kms_key_unconfigured');
    }
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', 'GIFT-DEFAULT-KEY', 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decryptGiftCode($data, $vendor_id) {
    global $connection_server;
    $str = (string)$data;
    if (strpos($str, 'v2:') === 0) {
        $rawKey = bc_giftcard_kms_key($vendor_id);
        if ($rawKey === null) return '';
        $bin = base64_decode(substr($str, 3), true);
        if (!is_string($bin) || strlen($bin) < 29) return '';
        $iv = substr($bin, 0, 12);
        $tag = substr($bin, 12, 16);
        $ct = substr($bin, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? '' : $pt;
    }
    $key = 'GIFT-DEFAULT-KEY';
    if ($connection_server instanceof mysqli) {
        $q = mysqli_query($connection_server, "SELECT print_hub_secret FROM sas_vendors WHERE id='" . (int)$vendor_id . "' LIMIT 1");
        $r = $q ? mysqli_fetch_assoc($q) : null;
        if (!empty($r['print_hub_secret'])) $key = $r['print_hub_secret'];
    }
    $parts = explode('::', base64_decode($str));
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
    $hold=bc_giftcard_safety_response();
    return ['status'=>'FAILED','code'=>$hold['code'],'message'=>$hold['message']];
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

    // Provider A: the vendor's own ExchangeRate-API key (bc-admin -> Service
    // Control -> Currency Conversion API). This is what "the API is configured"
    // means for live conversion: with a key saved, rates come from the keyed
    // v6 endpoint instead of the shared free one below.
    if ($api_rate <= 0 && $vendor_id > 0 && function_exists('getVendorOption')) {
        $fx_api_key = trim((string) getVendorOption($vendor_id, 'fx_api_key', ''));
        if ($fx_api_key !== '') {
            $fx_url = "https://v6.exchangerate-api.com/v6/" . urlencode($fx_api_key) . "/latest/" . $from;
            $fx_ch = curl_init();
            curl_setopt($fx_ch, CURLOPT_URL, $fx_url);
            curl_setopt($fx_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($fx_ch, CURLOPT_TIMEOUT, 5);
            $fx_response = curl_exec($fx_ch);
            curl_close($fx_ch);
            if ($fx_response) {
                $fx_data = json_decode($fx_response, true);
                if (isset($fx_data['conversion_rates'][$to])) {
                    $api_rate = (float)$fx_data['conversion_rates'][$to];
                }
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
    // Never execute the old username-only debit or create new escrow obligations.
    $GLOBALS['bc_giftcard_failure_code']='GIFTCARD_SETTLEMENT_HOLD';
    return false;
}

/** Existing funded trades require reconciliation, not an automatic release/refund. */
function releaseP2PFunds($tradeId) {
    $GLOBALS['bc_giftcard_failure_code']='GIFTCARD_SETTLEMENT_HOLD';
    return false;
}
