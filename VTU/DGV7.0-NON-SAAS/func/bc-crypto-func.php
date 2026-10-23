<?php
/**
 * Plisio Crypto Hub Integration Functions
 * Reference: https://plisio.net/documentation
 */

function getPlisioMasterKey($vendor_id = 0) {
    global $connection_server;
    if (!$connection_server) return "";

    $vid = (int)$vendor_id;
    if ($vid > 0) {
        // Try vendor-specific keys first
        $q = mysqli_query($connection_server, "SELECT secret_key FROM sas_payment_gateways WHERE vendor_id='$vid' AND gateway_name LIKE '%plisio%' LIMIT 1");
        if ($r = mysqli_fetch_assoc($q)) {
            $key = trim($r['secret_key'] ?? "");
            if (!empty($key)) return $key;
        }
    }

    // Explicitly for platform context (Super Admin) or fallback
    $q = mysqli_query($connection_server, "SELECT secret_key FROM sas_super_admin_payment_gateways WHERE gateway_name LIKE '%plisio%' LIMIT 1");
    if ($r = mysqli_fetch_assoc($q)) {
        return trim($r['secret_key'] ?? "");
    }
    return "";
}

function makePlisioRequest($method, $endpoint, $params = [], $vendor_id = 0) {
    $apiKey = getPlisioMasterKey($vendor_id);
    if (empty($apiKey)) {
        return json_encode(['status' => 'error', 'message' => 'Plisio Master API Key not configured']);
    }

    $baseUrl = "https://api.plisio.net/api/v1/";
    $url = $baseUrl . $endpoint;

    $params['api_key'] = $apiKey;

    // Plisio documentation and environment history confirm GET is the reliable method for all endpoints
    $method = 'GET';
    if (!empty($params)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);


    if ($error) {
        return json_encode(['status' => 'error', 'message' => 'cURL Error: ' . $error]);
    }

    return $response;
}

/**
 * Get all supported currencies from Plisio
 */
function getPlisioCurrencies($vendor_id = 0) {
    $res = makePlisioRequest('GET', 'currencies', [], $vendor_id);
    $data = json_decode($res, true);
    if (($data['status'] ?? '') == 'success') {
        return $data['data'];
    }
    return [];
}

/**
 * Create a Plisio invoice (Deposit / Payment Link)
 */
function getPlisioTransactionDetails($txn_id, $vendor_id = 0) {
    $res = makePlisioRequest('GET', 'operations/' . $txn_id, [], $vendor_id);
    return json_decode($res, true);
}

function createPlisioInvoice($params, $vendor_id = 0) {
    // Required: currency, order_number, order_name, callback_url
    // Optional: amount, email, description, etc.
    $res = makePlisioRequest('GET', 'invoices/new', $params, $vendor_id);
    return json_decode($res, true);
}

/**
 * Fetch invoice details using the public view_key
 */
function getPlisioPublicInvoice($txn_id, $view_key) {
    $baseUrl = "https://api.plisio.net/api/v1/invoices/";
    $url = $baseUrl . $txn_id . "?view_key=" . $view_key;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

/**
 * Withdraw crypto to external address (Cash out)
 */
function plisioCashOut($params, $vendor_id = 0) {
    // Required: psys_cid, amount, to (address), type=cash_out
    if (isset($params['address'])) {
        $params['to'] = $params['address'];
        unset($params['address']);
    }
    // Debugging revealed GET is reachable for withdrawals (POST returns 500)
    $res = makePlisioRequest('GET', 'operations/withdraw', $params, $vendor_id);
    return json_decode($res, true);
}

/**
 * Get exchange rate between two currencies
 */
function getPlisioExchangeRate($from, $to, $vendor_id = 0) {
    $from = strtoupper($from);
    $to = strtoupper($to);

    // If TO is NGN or other fiat, use currencies/{fiat}
    if (in_array($to, ['NGN', 'EUR', 'GBP'])) {
        $res = makePlisioRequest('GET', 'currencies/' . $to, [], $vendor_id);
        $data = json_decode($res, true);
        if (($data['status'] ?? '') == 'success' && isset($data['data'])) {
            foreach ($data['data'] as $c) {
                if ($c['cid'] == $from || $c['currency'] == $from) {
                    $fiat_rate = (float)($c['fiat_rate'] ?? 0);
                    if ($fiat_rate > 0) return 1 / $fiat_rate;
                }
            }
        }
    }

    // For Crypto-to-Crypto or Crypto-to-USD, use base currencies endpoint
    $res = makePlisioRequest('GET', 'currencies', [], $vendor_id);
    $data = json_decode($res, true);
    if (($data['status'] ?? '') == 'success' && isset($data['data'])) {
        $price_from = 0;
        $price_to = 0;

        if ($from == 'USD') $price_from = 1.0;
        if ($to == 'USD') $price_to = 1.0;

        foreach ($data['data'] as $c) {
            if ($c['cid'] == $from || $c['currency'] == $from) $price_from = (float)$c['price_usd'];
            if ($c['cid'] == $to || $c['currency'] == $to) $price_to = (float)$c['price_usd'];
        }

        if ($price_to > 0 && $price_from > 0) return $price_from / $price_to;
    }

    return 0;
}

/**
 * Update user crypto balance
 */
function updateUserCryptoBalance($vendor_id, $username, $currency, $amount, $operation = 'credit') {
    global $connection_server;
    if (!$connection_server) return false;

    $vid = (int)$vendor_id;
    $u = mysqli_real_escape_string($connection_server, $username);
    $curr = mysqli_real_escape_string($connection_server, strtoupper($currency));
    $amt = (float)$amount;

    // Ensure wallet exists
    mysqli_query($connection_server, "INSERT IGNORE INTO sas_user_crypto_wallets (vendor_id, username, currency_code) VALUES ('$vid', '$u', '$curr')");

    if ($operation == 'credit') {
        $sql = "UPDATE sas_user_crypto_wallets SET balance = balance + $amt, ledger_balance = ledger_balance + $amt WHERE vendor_id='$vid' AND username='$u' AND currency_code='$curr'";
    } else {
        $sql = "UPDATE sas_user_crypto_wallets SET balance = balance - $amt, ledger_balance = ledger_balance - $amt WHERE vendor_id='$vid' AND username='$u' AND currency_code='$curr'";
    }

    $res = mysqli_query($connection_server, $sql);
    if (!$res) {
        $err = mysqli_error($connection_server);
        $errno = mysqli_errno($connection_server);
        error_log("[" . date('Y-m-d H:i:s') . "] Crypto Balance Update Failed ($errno): $err | Query: $sql");

        // Detailed log to file for easier access
        $log_dir = __DIR__ . "/../logs";
        if (!is_dir($log_dir)) @mkdir($log_dir, 0777, true);
        @file_put_contents($log_dir . "/crypto_db.log", "[" . date('Y-m-d H:i:s') . "] Update Balance Error ($errno): $err | SQL: $sql\n", FILE_APPEND);
    }
    return $res;
}

/**
 * Log crypto transaction
 */
function logCryptoTransaction($vendor_id, $username, $type, $currency, $amount, $status = 2, $plisio_tx_id = '', $invoice_url = '', $address = '', $metadata = [], $reference = '', $view_key = '', $blockchain_txid = '') {
    global $connection_server;
    if (!$connection_server) {
        return false;
    }

    $vid = (int)$vendor_id;
    $u = mysqli_real_escape_string($connection_server, trim($username));
    $t = mysqli_real_escape_string($connection_server, $type);
    $c = mysqli_real_escape_string($connection_server, strtoupper($currency));
    $a = (float)$amount;
    $s = (int)$status;
    $tid = mysqli_real_escape_string($connection_server, $plisio_tx_id);
    $btid = mysqli_real_escape_string($connection_server, $blockchain_txid);
    $iurl = mysqli_real_escape_string($connection_server, $invoice_url);
    $addr = mysqli_real_escape_string($connection_server, $address);
    $vkey = mysqli_real_escape_string($connection_server, $view_key);
    $meta = mysqli_real_escape_string($connection_server, json_encode($metadata));
    if (empty($reference)) $reference = 'CTX_' . time() . '_' . rand(100, 999);
    $ref = mysqli_real_escape_string($connection_server, $reference);

    $sql = "INSERT INTO `sas_crypto_transactions` (`vendor_id`, `username`, `reference`, `type`, `currency_code`, `amount`, `status`, `plisio_tx_id`, `blockchain_txid`, `invoice_url`, `address`, `view_key`, `metadata`)
            VALUES ('$vid', '$u', '$ref', '$t', '$c', '$a', '$s', '$tid', '$btid', '$iurl', '$addr', '$vkey', '$meta')";

    if (mysqli_query($connection_server, $sql)) {
        $insert_id = mysqli_insert_id($connection_server);
        return $insert_id;
    } else {
        return false;
    }
}

/**
 * Get user crypto wallets
 */
function getUserCryptoWallets($vendor_id, $username) {
    global $connection_server;
    if (!$connection_server) return [];

    $vid = (int)$vendor_id;
    $u = mysqli_real_escape_string($connection_server, $username);

    $wallets = [];
    $res = mysqli_query($connection_server, "SELECT * FROM `sas_user_crypto_wallets` WHERE `vendor_id`='$vid' AND `username`='$u'");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $wallets[$row['currency_code']] = $row;
        }
    }
    return $wallets;
}

/**
 * Verify Webhook Signature
 */
function verifyPlisioSignature($postData, $vendor_id = 0) {
    $apiKey = getPlisioMasterKey($vendor_id);
    if (empty($apiKey) || !isset($postData['verify_hash'])) {
        return false;
    }

    $verifyHash = $postData['verify_hash'];
    unset($postData['verify_hash']);
    ksort($postData);

    // Plisio documentation confirms SHA1 HMAC.
    // Important: Parameters MUST be in alphabetical order and the string must be raw key=value&key=value.
    // Nested arrays (like 'tx' or 'params') should be excluded from the signature string.
    $checkParams = [];
    foreach ($postData as $key => $value) {
        if (is_array($value)) continue;

        // Plisio signature can include "null" string for actual NULL values
        if (is_null($value)) {
            $valStr = 'null';
        } else {
            $valStr = (string)$value;
        }
        $checkParams[] = $key . '=' . $valStr;
    }
    // Plisio expects raw concatenation
    $checkString = implode('&', $checkParams);

    // Try SHA1 HMAC (Modern Plisio)
    $checkHash = hash_hmac('sha1', $checkString, $apiKey);
    if ($verifyHash === $checkHash) return true;

    // Try SHA1 Concatenation (Legacy Plisio)
    $checkHashConcat = sha1($checkString . $apiKey);
    if ($verifyHash === $checkHashConcat) return true;

    // Fallback to SHA512 if SHA1 fails (depends on Plisio API version/settings)
    $checkHash512 = hash_hmac('sha512', $checkString, $apiKey);
    if ($verifyHash === $checkHash512) {
        return true;
    }

    // SHA-512 with raw output fallback (uncommon but possible with some providers)
    $checkHash512Raw = hash_hmac('sha512', $checkString, $apiKey, true);
    if (bin2hex($checkHash512Raw) === $verifyHash) {
        return true;
    }


    return false;
}

function verifyPlisioWebhook($postData, $vendor_id = 0) {
    return verifyPlisioSignature($postData, $vendor_id);
}
?>