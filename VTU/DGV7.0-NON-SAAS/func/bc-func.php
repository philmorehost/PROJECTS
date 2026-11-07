<?php
/**
 * AI Edition: Smart Re-query Failure Explanation
 */
/**
 * AI Edition: Smart Re-query Failure Explanation
 */
function bc_get_ai_failure_explanation($status_msg, $tx_name = '') {
    $msg = strtolower($status_msg);
    $tx = strtolower($tx_name);
    
    // Context Detection
    $is_data = (strpos($tx, 'data') !== false || strpos($tx, 'bundle') !== false);
    $is_bank = (strpos($tx, 'bank') !== false || strpos($tx, 'transfer') !== false || strpos($tx, 'withdraw') !== false);
    $is_airtime = (strpos($tx, 'airtime') !== false || strpos($tx, 'recharge') !== false);

    if (strpos($msg, 'insufficient') !== false || strpos($msg, 'stock') !== false) {
        if ($is_data) return "The provider is currently out of stock for this specific data plan. I recommend trying a different data volume or a different network provider if available.";
        if ($is_bank) return "The bank's settlement account is currently low on liquidity. This is common during peak hours. Please try again in 30 minutes.";
        return "The provider is temporarily out of stock for this service. Please try a different amount or provider.";
    }

    if (strpos($msg, 'invalid') !== false || strpos($msg, 'number') !== false || strpos($msg, 'account') !== false) {
        if ($is_bank) return "The recipient bank account number or bank selection appears incorrect. Please double-check the account details and try again.";
        return "The recipient phone number appears to be invalid or incorrectly formatted. Please double-check the number and try again.";
    }

    if (strpos($msg, 'timeout') !== false || strpos($msg, 'congested') !== false || strpos($msg, 'delayed') !== false) {
        return "The service provider's gateway is currently slow or congested. This is a network-wide issue. Please wait 5-10 minutes before retrying to avoid double-charging.";
    }

    if (strpos($msg, 'duplicate') !== false) {
        return "It looks like you just sent this exact same request. To protect your wallet from double-charging, the system blocked this duplicate. Please check your transaction history.";
    }

    if (strpos($msg, 'balance') !== false && strpos($msg, 'wallet') !== false) {
        return "Your wallet balance is too low for this " . ($is_bank ? "withdrawal" : ($is_data ? "data purchase" : "transaction")) . ". You can fund your wallet via Bank Transfer or Card in the 'Fund Wallet' section.";
    }

    // Only return fallback if it actually looks like an error message
    $error_triggers = ['error', 'failed', 'could not', 'unable', 'invalid', 'retry', 'wrong', 'blocked', 'denied', 'exception'];
    foreach($error_triggers as $trigger) {
        if (strpos($msg, $trigger) !== false) {
            return "The provider returned an unexpected error. Switching to a backup route or trying again in a few minutes often solves this. How can I help you complete your purchase?";
        }
    }

    return ""; // Not an error or unrecognized
}

/**
 * Generates a rich business context for the AI to understand the user's current situation.
 */
function bc_get_ai_user_context($user_details) {
    global $connection_server;
    if (!$user_details || !isset($user_details['username'])) return [];

    $username = mysqli_real_escape_string($connection_server, $user_details['username']);
    $vendor_id = (int)($user_details['vendor_id'] ?? 0);

    // 1. Get recent failed transactions
    // Note: Using type_alternative as 'name' and description as 'status_description'
    $failed_q = mysqli_query($connection_server, "SELECT type_alternative as name, description as status_description, amount, date FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$username' AND status=3 ORDER BY id DESC LIMIT 1");
    $last_fail = ($failed_q) ? mysqli_fetch_assoc($failed_q) : null;

    // 2. Identify current wallet state
    $balance = (float)($user_details['balance'] ?? 0);
    $status_label = $balance < 100 ? "Low" : "Healthy";

    // 3. Check for immediate session error (most recent attempt)
    $immediate_error = $_SESSION['product_purchase_response'] ?? '';

    // 4. Get recent successful transactions for "Faster Processing" context
    $recent_history = [];
    $history_q = mysqli_query($connection_server, "SELECT type_alternative as name, description as target, amount FROM sas_transactions WHERE vendor_id='$vendor_id' AND username='$username' AND status=1 ORDER BY id DESC LIMIT 5");
    if ($history_q) {
        while($h = mysqli_fetch_assoc($history_q)) {
            $recent_history[] = $h['name'] . " to " . $h['target'] . " (₦" . $h['amount'] . ")";
        }
    }

    // 5. Get current service prices for context (Data, Cable, Exam, etc.)
    $services_prices = [];
    $levels = [1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values"];
    $acc_level = (int)($user_details['account_level'] ?? 1);
    $price_table = $levels[$acc_level] ?? $levels[1];
    
    $price_q = mysqli_query($connection_server, "
        SELECT p.product_name, v.val_1 as item, v.val_2 as price, a.api_type 
        FROM $price_table v
        JOIN sas_products p ON v.product_id = p.id
        JOIN sas_apis a ON v.api_id = a.id
        WHERE v.vendor_id = '$vendor_id' 
        AND p.status = 1
        AND v.status = 1
        AND a.status = 1
        ORDER BY a.api_type ASC, p.product_name ASC
    ");
    
    if ($price_q) {
        $type_net_count = [];
        while($p = mysqli_fetch_assoc($price_q)) {
            $type = strtoupper($p['api_type']);
            $net  = strtolower($p['product_name']);

            if (!isset($services_prices[$type])) $services_prices[$type] = [];
            if (!isset($type_net_count[$type])) $type_net_count[$type] = [];
            if (!isset($type_net_count[$type][$net])) $type_net_count[$type][$net] = 0;

            // Ensure a balanced distribution: max 5 plans per network within each data type
            // This ensures all networks (MTN, Airtel, Glo, 9mobile) are represented in the AI context
            if ($type_net_count[$type][$net] < 5 && count($services_prices[$type]) < 40) {
                $label = strtoupper($net) . " " . $p['item'] . ": ₦" . (float)$p['price'];
                $services_prices[$type][] = $label;
                $type_net_count[$type][$net]++;
            }
        }
    }

    $final_prices = [];
    foreach($services_prices as $type => $list) {
        $final_prices[] = "[$type] " . implode(" | ", $list);
    }

    return [
        'username' => $user_details['username'],
        'vendor_id' => $vendor_id,
        'wallet_balance' => $balance,
        'wallet_status' => $status_label,
        'last_fail_reason' => $last_fail ? $last_fail['status_description'] : '',
        'last_fail_plan' => $last_fail ? $last_fail['name'] : '',
        'recent_history' => $recent_history,
        'session_error' => $immediate_error,
        'current_data_prices' => $final_prices,
        'smart_explanation' => $immediate_error ? bc_get_ai_failure_explanation($immediate_error, $last_fail ? $last_fail['name'] : '') : ''
    ];
}

function bc_cache_directory() {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return sys_get_temp_dir();
    }
    return $dir;
}

function bc_cache_get($key, $ttl = 300) {
    $safeKey = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
    $path = bc_cache_directory() . '/' . $safeKey . '.json';
    if (!is_file($path)) {
        return null;
    }
    if (filemtime($path) + (int)$ttl < time()) {
        @unlink($path);
        return null;
    }
    $payload = @file_get_contents($path);
    if ($payload === false) {
        return null;
    }
    return json_decode($payload, true);
}

function bc_cache_set($key, $value) {
    $safeKey = preg_replace('/[^a-z0-9_\-]/i', '_', $key);
    $path = bc_cache_directory() . '/' . $safeKey . '.json';
    @file_put_contents($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

if (!function_exists('bc_app_brand_slug')) {
    /**
     * Brand slug for this vendor, derived from sas_site_details.site_title.
     *
     * Used to build the default APK filename "<brand>-<version>.apk", which is the naming the
     * app itself expects (see AppUpdateManager.downloadAndInstall). This default used to be a
     * hardcoded "datagifting-" in every installation, so a JIKABIZ or MZEEVTU update check
     * pointed at a file that could never exist - and because app-update.php only reports an
     * update when the APK is actually on disk, those brands were silently told "up to date".
     *
     * Returns "" when the brand cannot be determined, so callers fall back to "<version>.apk".
     */
    function bc_app_brand_slug($db, $vendor_id) {
        if (!$db || (int) $vendor_id <= 0) return "";
        $row = null;
        $q = mysqli_query($db, "SELECT site_title FROM sas_site_details WHERE vendor_id='" . (int) $vendor_id . "' LIMIT 1");
        if ($q && mysqli_num_rows($q) > 0) { $row = mysqli_fetch_assoc($q); }
        $title = trim((string) ($row['site_title'] ?? ''));
        if ($title === '') return "";
        return trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title)), '-');
    }
}

// STANDALONE: resolveVendorID() always returns 1 - single tenant installation
if (function_exists('resolveVendorID')) return;
function resolveVendorID($force_recompute = false) {
    return 1;
}

/**
 * AI Intelligence Memory: Stores platform-specific knowledge for reports and blueprints.
 */
function bc_log_ai_intelligence($vendor_id, $type, $content, $metadata = []) {
    global $connection_server;
    $v_id = (int)$vendor_id;
    $esc_type = mysqli_real_escape_string($connection_server, $type);
    $esc_content = mysqli_real_escape_string($connection_server, $content);
    $esc_meta = mysqli_real_escape_string($connection_server, json_encode($metadata));
    
    mysqli_query($connection_server, "INSERT INTO sas_ai_intelligence (vendor_id, intel_type, content, metadata) VALUES ('$v_id', '$esc_type', '$esc_content', '$esc_meta')");
}

// STANDALONE: SA global service control removed — only vendor-local setting applies.
function isServiceEnabled($name, $vid = null) {
    global $connection_server;
    static $static_service_cache = [];

    $name = strtolower(trim($name));

    if ($vid === null) $vid = resolveVendorID();
    if ($vid <= 0 || !$connection_server) return true;

    // Fetch Local Vendor Settings only
    if (!isset($static_service_cache[$vid])) {
        $static_service_cache[$vid] = [];
        $sc_q = mysqli_query($connection_server, "SELECT service_name, status FROM sas_service_control WHERE vendor_id='$vid'");
        if ($sc_q) {
            while ($sc_r = mysqli_fetch_assoc($sc_q)) {
                $static_service_cache[$vid][$sc_r['service_name']] = (int)$sc_r['status'];
            }
        }
    }

    // Aliases
    if ($name == 'print_hub') $name = 'data_card';

    // If vendor HAS a local setting, use it
    if (isset($static_service_cache[$vid][$name])) {
        return (bool)$static_service_cache[$vid][$name];
    }

    // Default: enabled (true) if no row exists for this service
    return true;
}

/**
 * bc_get_app_redirect_info()
 * Reads the per-vendor App redirect mode (off / prompt / force) and the App Download
 * URL from sas_site_details. Returns ['mode' => ..., 'link' => ...]. The mode is forced
 * to 'off' when no download URL is set, since there is nothing to redirect to.
 */
function bc_get_app_redirect_info($vendor_id) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    $result = ['mode' => 'off', 'link' => ''];
    if (!$connection_server || $vendor_id <= 0) return $result;

    // Idempotent safety net — the landing page loads bc-connect.php directly and may
    // run before bc-tables.php migrations have added the column.
    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_site_details LIKE 'app_redirect_mode'");
    if ($check_col && mysqli_num_rows($check_col) == 0) {
        @mysqli_query($connection_server, "ALTER TABLE sas_site_details ADD COLUMN app_redirect_mode VARCHAR(10) DEFAULT 'off'");
    }

    $q = mysqli_query($connection_server, "SELECT apk_download_url, app_redirect_mode FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $link = trim($r['apk_download_url'] ?? '');
        $mode = strtolower(trim($r['app_redirect_mode'] ?? 'off'));
        if (!in_array($mode, ['off', 'prompt', 'force'], true)) $mode = 'off';
        if (empty($link)) $mode = 'off'; // nothing to redirect/prompt to
        $result = ['mode' => $mode, 'link' => $link];
    }
    return $result;
}

function isKYCEnforced($v_id = null) {
    global $connection_server;
    static $kyc_enforced_cache = [];

    if ($v_id === null) $v_id = resolveVendorID();
    if ($v_id <= 0) return false;

    // Check config page bypass
    $current_uri = explode("?", trim($_SERVER["REQUEST_URI"] ?? ""))[0];
    $is_config_page = in_array($current_uri, ["/web/AccountSettings.php", "/web/KYCVerification.php", "/web/SecurityQuest.php"]);

    if (!$is_config_page && isset($kyc_enforced_cache[$v_id])) return $kyc_enforced_cache[$v_id];

    // Branch DG6.7 Optimization: Session Cache with 30s TTL
    if (!$is_config_page && isset($_SESSION['kyc_enforced_cache'][$v_id]) && isset($_SESSION['kyc_enforced_time'][$v_id]) && (time() - $_SESSION['kyc_enforced_time'][$v_id] < 30)) {
        $kyc_enforced_cache[$v_id] = $_SESSION['kyc_enforced_cache'][$v_id];
        return $kyc_enforced_cache[$v_id];
    }

    // Check Global Super Admin Toggle
    $global_force_kyc = getSuperAdminOption('force_kyc', '0');
    if ($global_force_kyc == 1) {
        $kyc_enforced_cache[$v_id] = true;
        if (isset($_SESSION)) {
            $_SESSION['kyc_enforced_cache'][$v_id] = true;
            $_SESSION['kyc_enforced_time'][$v_id] = time();
        }
        return true;
    }

    // Check Vendor Local Toggle
    $v_id_esc = mysqli_real_escape_string($connection_server, $v_id);
    $q_v = mysqli_query($connection_server, "SELECT force_kyc FROM sas_vendors WHERE id='$v_id_esc' LIMIT 1");
    if ($r = mysqli_fetch_assoc($q_v)) {
        if ($r['force_kyc'] == 1) {
            $kyc_enforced_cache[$v_id] = true;
            if (isset($_SESSION)) {
                $_SESSION['kyc_enforced_cache'][$v_id] = true;
                $_SESSION['kyc_enforced_time'][$v_id] = time();
            }
            return true;
        }
    }

    $kyc_enforced_cache[$v_id] = false;
    if (isset($_SESSION)) {
        $_SESSION['kyc_enforced_cache'][$v_id] = false;
        $_SESSION['kyc_enforced_time'][$v_id] = time();
    }
    return false;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Verifies a user's transaction PIN.
 * Handles both modern hashed security_pin and legacy numeric transaction_pin.
 */
function verifyUserPIN($input_pin, $user_details) {
    if (empty($input_pin) && $input_pin !== '0' && $input_pin !== '0000') return false;

    $db_security_pin = $user_details['security_pin'] ?? '';
    // transaction_pin is BIGINT in DB, so it comes as a string representation of a number or null
    $db_transaction_pin = $user_details['transaction_pin'] ?? null;

    // 1. Try hashed security pin first
    if (!empty($db_security_pin)) {
        if (password_verify($input_pin, $db_security_pin)) {
            return true;
        }

        // Special case: If migration hashed the integer value (stripping leading zeros)
        // e.g. input "0123" -> stored "123". Check if casting matches.
        $stripped_input = (string)(int)$input_pin;
        if ($stripped_input !== (string)$input_pin && password_verify($stripped_input, $db_security_pin)) {
            return true;
        }
    }

    // 2. Fallback to legacy numeric transaction_pin (BIGINT column)
    if ($db_transaction_pin !== null && $db_transaction_pin !== '') {
        // Use numeric comparison to handle leading zeros (e.g. "0123" matches 123)
        // and specifically handle "0000" which is stored as 0 in BIGINT.
        return (int)$input_pin === (int)$db_transaction_pin;
    }

    return false;
}

/**
 * Gate for the vendor's "force transaction PIN" toggle (sas_vendors.force_security_pin).
 *
 * WHO IS ASKED: a PERSON buying - on our own website (WEB) or in the mobile app (APP). A merchant
 * api_key (API) is deliberately EXEMPT: one key serves that merchant's own customers, so there is no
 * single PIN to ask for, and demanding one made every external API purchase fail with
 * "Invalid transaction PIN.". Callers express that by only invoking this gate for those methods
 * (see web/func/*.php).
 */
function requireTransactionPin($vendor_details, $user_details, $input, &$error_msg) {
    $vendor_details = is_array($vendor_details) ? $vendor_details : array();

    // Web pages render their forms from sas_site_details, so a caller may hold only a partial vendor
    // row - or none at all. Resolve the toggle straight from sas_vendors in that case: the setting must
    // never be silently skipped just because a caller passed a row without the column.
    if (!array_key_exists('force_security_pin', $vendor_details)) {
        global $connection_server;
        $pin_vendor_id = 0;
        if (!empty($vendor_details['id'])) {
            $pin_vendor_id = (int)$vendor_details['id'];
        } elseif (is_array($user_details) && !empty($user_details['vendor_id'])) {
            $pin_vendor_id = (int)$user_details['vendor_id'];
        }
        if ($pin_vendor_id > 0 && $connection_server) {
            $q_pin_vendor = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$pin_vendor_id' LIMIT 1");
            if ($q_pin_vendor && ($row_pin_vendor = mysqli_fetch_assoc($q_pin_vendor))) {
                $vendor_details = $row_pin_vendor;
            }
        }
    }

    if (($vendor_details['force_security_pin'] ?? 0) != 1) {
        return true;
    }
    if (empty($user_details['security_pin']) && empty($user_details['transaction_pin'])) {
        $error_msg = "Transaction PIN required. Please set a PIN in Security Settings first.";
        return false;
    }
    if (!verifyUserPIN($input['pin'] ?? '', $user_details)) {
        $error_msg = "Invalid transaction PIN.";
        return false;
    }
    return true;
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - (strlen($data) % 4)) % 4));
}

function sanitize_phone_number($phone) {
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);

    // If it starts with 234 and is 13 or 14 digits, convert to 0 format
    // This ensures compatibility with legacy code using "234".substr($phone, 1)
    if (substr($phone, 0, 3) == "234" && (strlen($phone) == 13 || strlen($phone) == 14)) {
        return "0" . substr($phone, 3);
    }

    return $phone;
}

function validateAPIDomain($user_details) {
    // If no domain is configured, we allow it (for backward compatibility and ease of use)
    if (empty($user_details['api_domain'])) return true;

    $whitelisted_input = strtolower(preg_replace('/^https?:\/\/(www\.)?/', '', trim($user_details['api_domain'])));
    $whitelisted_domain = explode('/', $whitelisted_input)[0];

    // Get current visitor IP
    $visitor_ip = $_SERVER['REMOTE_ADDR'];

    // 1. Direct IP comparison
    if ($visitor_ip === $whitelisted_domain) return true;

    // 2. Hostname resolution (Check if whitelisted domain resolves to visitor IP)
    // This supports server-to-server calls from a domain even if REMOTE_ADDR is an IP
    if (!filter_var($whitelisted_domain, FILTER_VALIDATE_IP)) {
        $resolved_ips = @gethostbynamel($whitelisted_domain);
        if ($resolved_ips && in_array($visitor_ip, $resolved_ips)) return true;
    }

    // 3. Check Referer (for AJAX/Browser-based calls)
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    if (!empty($referer)) {
        $referer_host = strtolower(preg_replace('/^www\./', '', parse_url($referer, PHP_URL_HOST)));
        if ($referer_host === $whitelisted_domain) return true;
    }

    // 4. Check Origin
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (!empty($origin)) {
        $origin_host = strtolower(preg_replace('/^www\./', '', parse_url($origin, PHP_URL_HOST)));
        if ($origin_host === $whitelisted_domain) return true;
    }

    return false;
}

function accountLevel($levelNumber)
{
	if (is_numeric($levelNumber)) {
		$account_level_details = array(1 => "Smart User", 2 => "Agent Vendor", 3 => "API Vendor");
		if ($account_level_details[$levelNumber] == true) {
			return $account_level_details[$levelNumber];
		} else {
			return "invalid level id";
		}
	} else {
		return "non-numeric string";
	}
}

function accountStatus($statusNumber)
{
	if (is_numeric($statusNumber)) {
		$account_status_details = array(1 => "Active", 2 => "Deactivated", 3 => "Deleted");
		if ($account_status_details[$statusNumber] == true) {
			return $account_status_details[$statusNumber];
		} else {
			return "invalid status id";
		}
	} else {
		return "non-numeric string";
	}
}

function tranStatus($statusNumber)
{
	if (is_numeric($statusNumber)) {
		$trans_status_details = array(1 => "Success", 2 => "Pending", 3 => "Failed", 4 => "Cancelled");
		if (isset($trans_status_details[$statusNumber])) {
			return $trans_status_details[$statusNumber];
		} else {
			return "invalid status code";
		}
	} else {
		return "non-numeric string";
	}
}

function itemStatus($statusNumber)
{
	if (is_numeric($statusNumber)) {
		$item_status_details = array(0 => "Disabled", 1 => "Enabled");
		if ($item_status_details[$statusNumber] == true) {
			return $item_status_details[$statusNumber];
		} else {
			return "invalid status code";
		}
	} else {
		return "non-numeric string";
	}
}

function checkIfEmpty($text, $addbef, $addafter)
{
	if (!empty(trim($text))) {
		return $addbef . $text . $addafter;
	} else {
		return "";
	}
}

function checkTextEmpty($text)
{
	if (!empty(trim($text))) {
		return $text;
	} else {
		return "No Comment";
	}
}

function userBalance($decimalIndex)
{
	global $get_logged_user_details;
	if (!empty($get_logged_user_details["balance"])) {
		$exp_number = array_filter(explode(".", trim($get_logged_user_details["balance"])));
		$firstNumber = $exp_number[0];
		$decimalNumber = $exp_number[1];

		if (is_numeric($get_logged_user_details["balance"]) && is_numeric($decimalIndex)) {
			return ($firstNumber + 0) . "." . sprintf("%0" . $decimalIndex . "d", $decimalNumber);
		} else {
			if (trim($get_logged_user_details["balance"]) == "") {
				return "0." . sprintf("%0" . $decimalIndex . "d", 0);
			}
		}
	} else {
		return "0." . sprintf("%0" . $decimalIndex . "d", 0);
	}
}

function vendorBalance($decimalIndex)
{
	global $get_logged_admin_details;
	if (!empty($get_logged_admin_details["balance"])) {
		$exp_number = array_filter(explode(".", trim($get_logged_admin_details["balance"])));
		$firstNumber = $exp_number[0];
		$decimalNumber = $exp_number[1];

		if (is_numeric($get_logged_admin_details["balance"]) && is_numeric($decimalIndex)) {
			return ($firstNumber + 0) . "." . sprintf("%0" . $decimalIndex . "d", $decimalNumber);
		} else {
			if (trim($get_logged_admin_details["balance"]) == "") {
				return "0." . sprintf("%0" . $decimalIndex . "d", 0);
			}
		}
	} else {
		return "0." . sprintf("%0" . $decimalIndex . "d", 0);
	}
}

function chargeUser($type, $product_unique_id, $type_alternative, $reference, $api_reference, $amount, $discounted_amount, $description, $mode, $api_website, $status)
{
	global $connection_server;

	$type = mysqli_real_escape_string($connection_server, trim(strip_tags($type)));
	$product_unique_id = mysqli_real_escape_string($connection_server, trim(strip_tags($product_unique_id)));
	$type_alternative = mysqli_real_escape_string($connection_server, trim(strip_tags($type_alternative)));
	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$api_reference = mysqli_real_escape_string($connection_server, trim(strip_tags($api_reference)));
	$amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($amount))));
	$discounted_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($discounted_amount))));
	$description = mysqli_real_escape_string($connection_server, trim(strip_tags($description)));
	$mode = mysqli_real_escape_string($connection_server, trim(strip_tags($mode)));
	$api_website = mysqli_real_escape_string($connection_server, trim(strip_tags($api_website)));
	$status = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($status))));

	$transactionTypeArray = array("credit", "debit");
	$statusArray = array(1, 2, 3);

	// Robust vendor lookup
	$v_id = resolveVendorID();
    if($v_id <= 0) return "failed";

    // Robust user lookup
	$u_name = isset($_SESSION["user_session"]) ? $_SESSION["user_session"] : (isset($GLOBALS["get_logged_user_details"]["username"]) ? $GLOBALS["get_logged_user_details"]["username"] : "");
	$get_logged_user_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $v_id . "' && username='" . mysqli_real_escape_string($connection_server, $u_name) . "' LIMIT 1"));


	if (isset($get_logged_user_det["balance"]) && is_numeric($get_logged_user_det["balance"]) && !empty($amount) && is_numeric($amount) && !empty($discounted_amount) && is_numeric($discounted_amount) && ($discounted_amount > 0) && $get_logged_user_det["status"] == 1) {
		if (in_array($type, $transactionTypeArray) && !empty($product_unique_id) && !empty($type_alternative) && !empty($reference) && !empty($description) && is_numeric($status) && in_array($status, $statusArray)) {
			if ($type === "debit") {
				// ─── AI Edition: Atomic Wallet Debit (Race Condition Fix) ───────────────
				// Use bc_atomic_debit_user() which wraps the balance read+write in a
				// MySQL transaction with SELECT FOR UPDATE. This prevents double-spend
				// when concurrent requests hit simultaneously.
				// Pre-check: fast balance check before acquiring the lock
				if (($get_logged_user_det["balance"] > 0) && ($amount > 0) && ($get_logged_user_det["balance"] >= $discounted_amount)) {

					// Acquire lock and debit atomically
					$atomic_result = function_exists('bc_atomic_debit_user')
						? bc_atomic_debit_user((int)$get_logged_user_det["vendor_id"], $get_logged_user_det["username"], (float)$discounted_amount, $reference)
						: 'legacy'; // Fallback if security lib not loaded (shouldn't happen)

					if ($atomic_result === 'insufficient_balance') {
						return "failed"; // Balance changed between pre-check and lock — safe rejection
					}
					if ($atomic_result === 'failed') {
						return "failed"; // DB error — safe rejection
					}

					// Atomic debit succeeded — fetch fresh balance for accurate records
					$fresh_user_q = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE vendor_id='" . (int)$get_logged_user_det["vendor_id"] . "' AND username='" . mysqli_real_escape_string($connection_server, $get_logged_user_det["username"]) . "' LIMIT 1");
					$fresh_user   = $fresh_user_q ? mysqli_fetch_assoc($fresh_user_q) : null;
					$user_balance_before_debit = $get_logged_user_det["balance"]; // Original read (for email/log)
					$user_balance_after_debit  = $fresh_user ? (float)$fresh_user["balance"] : ($user_balance_before_debit - (float)$discounted_amount);

					// Legacy fallback path (if bc_atomic_debit_user not available)
					if ($atomic_result === 'legacy') {
						$user_balance_after_debit = $user_balance_before_debit - (float)$discounted_amount;
						mysqli_query($connection_server, "UPDATE sas_users SET balance='$user_balance_after_debit' WHERE vendor_id='" . $get_logged_user_det["vendor_id"] . "' && username='" . $get_logged_user_det["username"] . "'");
					}

					// Insert transaction record
					$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('" . $get_logged_user_det["vendor_id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$api_reference', '" . $get_logged_user_det["username"] . "', '$amount', '$discounted_amount', '$user_balance_before_debit', '$user_balance_after_debit', '$description', '$mode', '$api_website', '$status')");

					$charge_user = true; // Debit already committed by atomic function
					if ($insert_transaction) {
						// Email Beginning
						$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_funding_template_subject = getUserEmailTemplate('user-funding', 'subject');
						$raw_funding_template_body = getUserEmailTemplate('user-funding', 'body');
						foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
							$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
							$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
						}

						$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_vendor_det["firstname"], "{admin_lastname}" => $get_vendor_det["lastname"], "{username}" => $get_logged_user_det["username"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_transaction_template_subject = getUserEmailTemplate('user-transactions', 'subject');
						$raw_transaction_template_body = getUserEmailTemplate('user-transactions', 'body');
						foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
							$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
							$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
						}
						sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
						sendVendorEmail($get_vendor_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
						// Email End

						// --- First Purchase Referral Bonus ---
						if ($status == 1 && !empty($get_logged_user_det["referral_id"]) && $get_logged_user_det["referral_bonus_awarded"] == 0) {
							// Check if this is the user's first successful transaction
							$query_first_trans = "SELECT id FROM sas_transactions WHERE vendor_id = ? AND username = ? AND status = 1 LIMIT 2";
							$stmt_first_trans = mysqli_prepare($connection_server, $query_first_trans);
							mysqli_stmt_bind_param($stmt_first_trans, "is", $get_logged_user_det["vendor_id"], $get_logged_user_det["username"]);
							mysqli_stmt_execute($stmt_first_trans);
							$result_first_trans = mysqli_stmt_get_result($stmt_first_trans);

							if (mysqli_num_rows($result_first_trans) == 1) {
								// Fetch referral bonus amount
								$query_bonus_settings = "SELECT first_purchase_bonus FROM sas_loyalty_bonus_settings WHERE vendor_id = ? LIMIT 1";
								$stmt_bonus_settings = mysqli_prepare($connection_server, $query_bonus_settings);
								mysqli_stmt_bind_param($stmt_bonus_settings, "i", $get_logged_user_det["vendor_id"]);
								mysqli_stmt_execute($stmt_bonus_settings);
								$result_bonus_settings = mysqli_stmt_get_result($stmt_bonus_settings);
								$bonus_settings = mysqli_fetch_assoc($result_bonus_settings);
								$bonus_amount = $bonus_settings['first_purchase_bonus'] ?? 100;

								// Get referrer details
								$query_referrer = "SELECT * FROM sas_users WHERE id = ? AND vendor_id = ?";
								$stmt_referrer = mysqli_prepare($connection_server, $query_referrer);
								mysqli_stmt_bind_param($stmt_referrer, "ii", $get_logged_user_det["referral_id"], $get_logged_user_det["vendor_id"]);
								mysqli_stmt_execute($stmt_referrer);
								$result_referrer = mysqli_stmt_get_result($stmt_referrer);
								$referrer = mysqli_fetch_assoc($result_referrer);

								if ($referrer) {
									// Award bonus to referrer
									$log_type_bonus = 'REFERRAL_BONUS';
									$query_log_bonus = "INSERT INTO sas_points_log (vendor_id, username, point_amount, log_type) VALUES (?, ?, ?, ?)";
									$stmt_log_bonus = mysqli_prepare($connection_server, $query_log_bonus);
									mysqli_stmt_bind_param($stmt_log_bonus, "isis", $referrer['vendor_id'], $referrer['username'], $bonus_amount, $log_type_bonus);
									mysqli_stmt_execute($stmt_log_bonus);

									// Notify the referrer if their coins crossed the conversion-email threshold
									bc_maybe_send_coin_guide_email($referrer['vendor_id'], $referrer['username']);

									// Mark bonus as awarded
									$query_update_user = "UPDATE sas_users SET referral_bonus_awarded = 1 WHERE id = ?";
									$stmt_update_user = mysqli_prepare($connection_server, $query_update_user);
									mysqli_stmt_bind_param($stmt_update_user, "i", $get_logged_user_det["id"]);
									mysqli_stmt_execute($stmt_update_user);
								}
							}
						}

						return "success";
					} else {
						return "failed";
					}
				} else {
					return "failed";
				}
			}

			if ($type === "credit") {
				$user_balance_before_credit = $get_logged_user_det["balance"];
				$user_balance_after_credit = ($user_balance_before_credit + $discounted_amount);

				$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('" . $get_logged_user_det["vendor_id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$api_reference', '" . $get_logged_user_det["username"] . "', '$amount', '$discounted_amount', '$user_balance_before_credit', '$user_balance_after_credit', '$description', '$mode', '$api_website', '$status')");
				$charge_user = mysqli_query($connection_server, "UPDATE sas_users SET balance='$user_balance_after_credit' WHERE vendor_id='" . $get_logged_user_det["vendor_id"] . "' && username='" . $get_logged_user_det["username"] . "' ");
				if (($user_balance_before_credit !== false) && ($charge_user == true)) {
					// Email Beginning
					$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_funding_template_subject = getUserEmailTemplate('user-funding', 'subject');
					$raw_funding_template_body = getUserEmailTemplate('user-funding', 'body');
					foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
						$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
						$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
					}

					$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_vendor_det["firstname"], "{admin_lastname}" => $get_vendor_det["lastname"], "{username}" => $get_logged_user_det["username"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_transaction_template_subject = getUserEmailTemplate('user-transactions', 'subject');
					$raw_transaction_template_body = getUserEmailTemplate('user-transactions', 'body');
					foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
						$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
						$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
					}
					sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
					sendVendorEmail($get_vendor_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
					// Email End
					return "success";
				} else {
					return "failed";
				}
			}

			if (!in_array($type, $transactionTypeArray)) {
				return "failed";
			}
		} else {
			return "failed";
		}
	} else {
		return "failed";
	}
}

function chargeOtherUser($user_id, $type, $product_unique_id, $type_alternative, $reference, $api_reference, $amount, $discounted_amount, $description, $mode, $api_website, $status)
{
	global $connection_server;

	$user_id = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($user_id))));
	$type = mysqli_real_escape_string($connection_server, trim(strip_tags($type)));
	$product_unique_id = mysqli_real_escape_string($connection_server, trim(strip_tags($product_unique_id)));
	$type_alternative = mysqli_real_escape_string($connection_server, trim(strip_tags($type_alternative)));
	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$api_reference = mysqli_real_escape_string($connection_server, trim(strip_tags($api_reference)));
	$amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($amount))));
	$discounted_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($discounted_amount))));
	$description = mysqli_real_escape_string($connection_server, trim(strip_tags($description)));
	$mode = mysqli_real_escape_string($connection_server, trim(strip_tags($mode)));
	$api_website = mysqli_real_escape_string($connection_server, trim(strip_tags($api_website)));
	$status = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($status))));

	$transactionTypeArray = array("credit", "debit");
	$statusArray = array(1, 2, 3);

	// Robust vendor lookup
	$v_id = resolveVendorID();
	if($v_id <= 0) return "failed";

	$get_logged_user_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $v_id . "' && username='" . $user_id . "' LIMIT 1"));


	if (isset($get_logged_user_det["balance"]) && is_numeric($get_logged_user_det["balance"]) && !empty($amount) && is_numeric($amount) && !empty($discounted_amount) && is_numeric($discounted_amount) && ($discounted_amount > 0) && $get_logged_user_det["status"] == 1) {
		if (in_array($type, $transactionTypeArray) && !empty($product_unique_id) && !empty($type_alternative) && !empty($reference) && !empty($description) && is_numeric($status) && in_array($status, $statusArray)) {
			if ($type === "debit") {
				if (($get_logged_user_det["balance"] > 0) && ($amount > 0) && ($get_logged_user_det["balance"] >= $amount) && ($get_logged_user_det["balance"] >= $discounted_amount)) {
					$user_balance_before_debit = $get_logged_user_det["balance"];
					$user_balance_after_debit = ($user_balance_before_debit - $discounted_amount);

					$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('" . $get_logged_user_det["vendor_id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$api_reference', '" . $user_id . "', '$amount', '$discounted_amount', '$user_balance_before_debit', '$user_balance_after_debit', '$description', '$mode', '$api_website', '$status')");
					$charge_user = mysqli_query($connection_server, "UPDATE sas_users SET balance='$user_balance_after_debit' WHERE vendor_id='" . $get_logged_user_det["vendor_id"] . "' && username='" . $user_id . "'");
					if (($user_balance_before_debit !== false) && ($charge_user == true)) {
						// Email Beginning
						$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_funding_template_subject = getUserEmailTemplate('user-funding', 'subject');
						$raw_funding_template_body = getUserEmailTemplate('user-funding', 'body');
						foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
							$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
							$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
						}

						$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_vendor_det["firstname"], "{admin_lastname}" => $get_vendor_det["lastname"], "{username}" => $get_logged_user_det["username"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_transaction_template_subject = getUserEmailTemplate('user-transactions', 'subject');
						$raw_transaction_template_body = getUserEmailTemplate('user-transactions', 'body');
						foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
							$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
							$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
						}
						sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
						sendVendorEmail($get_vendor_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
						// Email End
						return "success";
					} else {
						return "failed";
					}
				} else {
					return "failed";
				}
			}

			if ($type === "credit") {
				$user_balance_before_credit = $get_logged_user_det["balance"];
				$user_balance_after_credit = ($user_balance_before_credit + $discounted_amount);

				$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('" . $get_logged_user_det["vendor_id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$api_reference', '" . $user_id . "', '$amount', '$discounted_amount', '$user_balance_before_credit', '$user_balance_after_credit', '$description', '$mode', '$api_website', '$status')");
				$charge_user = mysqli_query($connection_server, "UPDATE sas_users SET balance='$user_balance_after_credit' WHERE vendor_id='" . $get_logged_user_det["vendor_id"] . "' && username='" . $user_id . "' ");
				if (($user_balance_before_credit !== false) && ($charge_user == true)) {
					// Email Beginning
					$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_funding_template_subject = getUserEmailTemplate('user-funding', 'subject');
					$raw_funding_template_body = getUserEmailTemplate('user-funding', 'body');
					foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
						$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
						$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
					}

					$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_vendor_det["firstname"], "{admin_lastname}" => $get_vendor_det["lastname"], "{username}" => $get_logged_user_det["username"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_transaction_template_subject = getUserEmailTemplate('user-transactions', 'subject');
					$raw_transaction_template_body = getUserEmailTemplate('user-transactions', 'body');
					foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
						$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
						$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
					}
					sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
					sendVendorEmail($get_vendor_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
					// Email End
					return "success";
				} else {
					return "failed";
				}
			}

			if (!in_array($type, $transactionTypeArray)) {
				return "failed";
			}
		} else {
			return "failed";
		}
	} else {
		return "failed";
	}
}

// STANDALONE: chargeVendor is a no-op — no billing in standalone
function chargeVendor($type, $service, $description, $ref, $amount, $fee, $note, $domain, $status) {
    return 'success';
}

function chargeVendor_original_unused($type, $product_unique_id, $type_alternative, $reference, $amount, $discounted_amount, $description, $api_website, $status)
{
	global $connection_server;

	$type = mysqli_real_escape_string($connection_server, trim(strip_tags($type)));
	$product_unique_id = mysqli_real_escape_string($connection_server, trim(strip_tags($product_unique_id)));
	$type_alternative = mysqli_real_escape_string($connection_server, trim(strip_tags($type_alternative)));
	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($amount))));
	$discounted_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($discounted_amount))));
	$description = mysqli_real_escape_string($connection_server, trim(strip_tags($description)));
	$api_website = mysqli_real_escape_string($connection_server, trim(strip_tags($api_website)));
	$status = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($status))));

	$transactionTypeArray = array("credit", "debit");
	$statusArray = array(1, 2, 3);

	$get_spadmin_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin LIMIT 1"));

	// Robust vendor lookup
	$v_id = resolveVendorID();
	if($v_id <= 0) return "failed";

	$get_logged_user_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . $v_id . "' LIMIT 1"));


	if (isset($get_logged_user_det["balance"]) && is_numeric($get_logged_user_det["balance"]) && !empty($amount) && is_numeric($amount) && !empty($discounted_amount) && is_numeric($discounted_amount) && ($discounted_amount > 0)) {
		if (in_array($type, $transactionTypeArray) && !empty($product_unique_id) && !empty($type_alternative) && !empty($reference) && !empty($description) && is_numeric($status) && in_array($status, $statusArray)) {
			if ($type === "debit") {
				if (($get_logged_user_det["balance"] > 0) && ($amount > 0) && ($get_logged_user_det["balance"] >= $amount) && ($get_logged_user_det["balance"] >= $discounted_amount)) {
					$user_balance_before_debit = $get_logged_user_det["balance"];
					$user_balance_after_debit = ($user_balance_before_debit - $discounted_amount);

					$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) VALUES ('" . $get_logged_user_det["id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$amount', '$discounted_amount', '$user_balance_before_debit', '$user_balance_after_debit', '$description', '$api_website', '$status')");
					$charge_user = mysqli_query($connection_server, "UPDATE sas_vendors SET balance='$user_balance_after_debit' WHERE id='" . $get_logged_user_det["id"] . "'");
					if (($user_balance_before_debit !== false) && ($charge_user == true)) {
						// Email Beginning
						$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_funding_template_subject = getSuperAdminEmailTemplate('vendor-funding', 'subject');
						$raw_funding_template_body = getSuperAdminEmailTemplate('vendor-funding', 'body');
						foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
							$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
							$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
						}

						$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_spadmin_det["firstname"], "{admin_lastname}" => $get_spadmin_det["lastname"], "{email}" => $get_logged_user_det["email"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_transaction_template_subject = getSuperAdminEmailTemplate('vendor-transactions', 'subject');
						$raw_transaction_template_body = getSuperAdminEmailTemplate('vendor-transactions', 'body');
						foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
							$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
							$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
						}
						sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
						sendVendorEmail($get_spadmin_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
						// Email End
						return "success";
					} else {
						return "failed";
					}
				} else {
					return "failed";
				}
			}

			if ($type === "credit") {
				$user_balance_before_credit = $get_logged_user_det["balance"];
				$user_balance_after_credit = ($user_balance_before_credit + $discounted_amount);

				$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) VALUES ('" . $get_logged_user_det["id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$amount', '$discounted_amount', '$user_balance_before_credit', '$user_balance_after_credit', '$description', '$api_website', '$status')");
				$charge_user = mysqli_query($connection_server, "UPDATE sas_vendors SET balance='$user_balance_after_credit' WHERE id='" . $get_logged_user_det["id"] . "'");
				if (($user_balance_before_credit !== false) && ($charge_user == true)) {
					// Email Beginning
					$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_funding_template_subject = getSuperAdminEmailTemplate('vendor-funding', 'subject');
					$raw_funding_template_body = getSuperAdminEmailTemplate('vendor-funding', 'body');
					foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
						$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
						$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
					}

					$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_spadmin_det["firstname"], "{admin_lastname}" => $get_spadmin_det["lastname"], "{email}" => $get_logged_user_det["email"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_transaction_template_subject = getSuperAdminEmailTemplate('vendor-transactions', 'subject');
					$raw_transaction_template_body = getSuperAdminEmailTemplate('vendor-transactions', 'body');
					foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
						$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
						$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
					}
					sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
					sendVendorEmail($get_spadmin_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
					// Email End
					return "success";
				} else {
					return "failed";
				}
			}

			if (!in_array($type, $transactionTypeArray)) {
				return "failed";
			}
		} else {
			return "failed";
		}
	} else {
		return "failed";
	}
}

function chargeOtherVendor($vendor_id, $type, $product_unique_id, $type_alternative, $reference, $amount, $discounted_amount, $description, $api_website, $status)
{
	global $connection_server;

	$type = mysqli_real_escape_string($connection_server, trim(strip_tags($type)));
	$product_unique_id = mysqli_real_escape_string($connection_server, trim(strip_tags($product_unique_id)));
	$type_alternative = mysqli_real_escape_string($connection_server, trim(strip_tags($type_alternative)));
	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($amount))));
	$discounted_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($discounted_amount))));
	$description = mysqli_real_escape_string($connection_server, trim(strip_tags($description)));
	$api_website = mysqli_real_escape_string($connection_server, trim(strip_tags($api_website)));
	$status = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($status))));

	$transactionTypeArray = array("credit", "debit");
	$statusArray = array(1, 2, 3);

	$get_spadmin_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin LIMIT 1"));
	$get_logged_user_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='" . $vendor_id . "' LIMIT 1"));


	if (isset($get_logged_user_det["balance"]) && is_numeric($get_logged_user_det["balance"]) && !empty($amount) && is_numeric($amount) && !empty($discounted_amount) && is_numeric($discounted_amount) && ($discounted_amount > 0)) {
		if (in_array($type, $transactionTypeArray) && !empty($product_unique_id) && !empty($type_alternative) && !empty($reference) && !empty($description) && is_numeric($status) && in_array($status, $statusArray)) {
			if ($type === "debit") {
				if (($get_logged_user_det["balance"] > 0) && ($amount > 0) && ($get_logged_user_det["balance"] >= $amount) && ($get_logged_user_det["balance"] >= $discounted_amount)) {
					$user_balance_before_debit = $get_logged_user_det["balance"];
					$user_balance_after_debit = ($user_balance_before_debit - $discounted_amount);

					$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) VALUES ('" . $get_logged_user_det["id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$amount', '$discounted_amount', '$user_balance_before_debit', '$user_balance_after_debit', '$description', '$api_website', '$status')");
					$charge_user = mysqli_query($connection_server, "UPDATE sas_vendors SET balance='$user_balance_after_debit' WHERE id='" . $get_logged_user_det["id"] . "'");
					if (($user_balance_before_debit !== false) && ($charge_user == true)) {
						// Email Beginning
						$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_funding_template_subject = getSuperAdminEmailTemplate('vendor-funding', 'subject');
						$raw_funding_template_body = getSuperAdminEmailTemplate('vendor-funding', 'body');
						foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
							$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
							$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
						}

						$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_spadmin_det["firstname"], "{admin_lastname}" => $get_spadmin_det["lastname"], "{email}" => $get_logged_user_det["email"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_debit, 2), "{balance_after}" => toDecimal($user_balance_after_debit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
						$raw_transaction_template_subject = getSuperAdminEmailTemplate('vendor-transactions', 'subject');
						$raw_transaction_template_body = getSuperAdminEmailTemplate('vendor-transactions', 'body');
						foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
							$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
							$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
						}
						sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
						sendSuperAdminEmail($get_spadmin_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
						// Email End
						return "success";
					} else {
						return "failed";
					}
				} else {
					return "failed";
				}
			}

			if ($type === "credit") {
				$user_balance_before_credit = $get_logged_user_det["balance"];
				$user_balance_after_credit = ($user_balance_before_credit + $discounted_amount);

				$insert_transaction = mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) VALUES ('" . $get_logged_user_det["id"] . "', '$product_unique_id', '$type_alternative', '$reference', '$amount', '$discounted_amount', '$user_balance_before_credit', '$user_balance_after_credit', '$description', '$api_website', '$status')");
				$charge_user = mysqli_query($connection_server, "UPDATE sas_vendors SET balance='$user_balance_after_credit' WHERE id='" . $get_logged_user_det["id"] . "'");
				if (($user_balance_before_credit !== false) && ($charge_user == true)) {
					// Email Beginning
					$funding_template_encoded_text_array = array("{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_funding_template_subject = getSuperAdminEmailTemplate('vendor-funding', 'subject');
					$raw_funding_template_body = getSuperAdminEmailTemplate('vendor-funding', 'body');
					foreach ($funding_template_encoded_text_array as $array_key => $array_val) {
						$raw_funding_template_subject = str_replace($array_key, $array_val, $raw_funding_template_subject);
						$raw_funding_template_body = str_replace($array_key, $array_val, $raw_funding_template_body);
					}

					$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_spadmin_det["firstname"], "{admin_lastname}" => $get_spadmin_det["lastname"], "{email}" => $get_logged_user_det["email"], "{firstname}" => $get_logged_user_det["firstname"], "{lastname}" => $get_logged_user_det["lastname"], "{balance_before}" => toDecimal($user_balance_before_credit, 2), "{balance_after}" => toDecimal($user_balance_after_credit, 2), "{amount}" => toDecimal($amount, 2) . " @ " . toDecimal($discounted_amount, 2), "{type}" => $type, "{description}" => $description);
					$raw_transaction_template_subject = getSuperAdminEmailTemplate('vendor-transactions', 'subject');
					$raw_transaction_template_body = getSuperAdminEmailTemplate('vendor-transactions', 'body');
					foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
						$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
						$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
					}
					sendVendorEmail($get_logged_user_det["email"], $raw_funding_template_subject, $raw_funding_template_body);
					sendSuperAdminEmail($get_spadmin_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
					// Email End
					return "success";
				} else {
					return "failed";
				}
			}

			if (!in_array($type, $transactionTypeArray)) {
				return "failed";
			}
		} else {
			return "failed";
		}
	} else {
		return "failed";
	}
}

function addUserVirtualBank($reference, $bank_code, $bank_name, $account_number, $account_name, $vid = 0, $username = "", $gateway_name = "")
{
	global $connection_server;

	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags($bank_code)));
	$bank_name = mysqli_real_escape_string($connection_server, trim(strip_tags($bank_name)));
	$account_number = mysqli_real_escape_string($connection_server, trim(strip_tags($account_number)));
	$account_name = mysqli_real_escape_string($connection_server, trim(strip_tags($account_name)));
    $gateway_name = mysqli_real_escape_string($connection_server, trim(strip_tags($gateway_name)));

    if (empty($gateway_name)) {
        // Auto-detect gateway if not provided
        $bn = strtoupper($bank_name);
        $an = strtoupper($account_name);
        if (strpos($bn, 'WEMA') !== false || strpos($bn, 'VFD') !== false || strpos($bn, 'MONNIFY') !== false || strpos($bn, 'STERLING') !== false) $gateway_name = 'monnify';
        elseif (strpos($bn, 'GLOBUS') !== false || strpos($bn, 'TITAN') !== false || strpos($bn, 'PAYVESSEL') !== false) $gateway_name = 'payvessel';
        elseif (strpos($bn, 'BEEWAVE') !== false || strpos($an, 'BEEWAVE') !== false) $gateway_name = 'beewave';
        elseif (strcasecmp($bank_code, 'PayHub') === 0) $gateway_name = 'payhub';
    }

    if ($vid <= 0) $vid = resolveVendorID();
    if (empty($username)) {
        $username = isset($_SESSION["user_session"]) ? $_SESSION["user_session"] : (isset($GLOBALS["get_logged_user_details"]["username"]) ? $GLOBALS["get_logged_user_details"]["username"] : "");
    }

    $vid = (int)$vid;
    $username_esc = mysqli_real_escape_string($connection_server, $username);

	$get_logged_user_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_users WHERE vendor_id='$vid' && username='$username_esc' LIMIT 1"));

	if ($get_logged_user_det && !empty($bank_code) && !empty($account_number) && !empty($account_name)) {
        if (empty($reference)) $reference = "VA_".time().rand(100,999);
        $v_id = $get_logged_user_det["vendor_id"];
        $u_name = $get_logged_user_det["username"];

		$select_banks = mysqli_query($connection_server, "SELECT 1 FROM sas_user_banks WHERE vendor_id='$v_id' AND username='$u_name' AND account_number='$account_number' LIMIT 1");
		if (($select_banks !== false) && (mysqli_num_rows($select_banks) == 0)) {
			mysqli_query($connection_server, "INSERT INTO sas_user_banks (vendor_id, username, reference, gateway_name, bank_code, bank_name, account_number, account_name, status) VALUES ('$v_id', '$u_name', '$reference', '$gateway_name', '$bank_code', '$bank_name', '$account_number', '$account_name', 1)");
		}
	}
}

function getUserVirtualBank($vid = 0, $uname = "")
{
	global $connection_server, $get_logged_user_details;
    if ($vid <= 0) $vid = $get_logged_user_details["vendor_id"] ?? resolveVendorID();
    if (empty($uname)) $uname = $get_logged_user_details["username"] ?? ($_SESSION["user_session"] ?? "");

	if($vid <= 0 || empty($uname)) return false;

    $vid = (int)$vid;
    $uname = mysqli_real_escape_string($connection_server, $uname);

	$select_banks = mysqli_query($connection_server, "SELECT * FROM sas_user_banks WHERE vendor_id='$vid' AND username='$uname' AND status=1");
	if (($select_banks == true) && (mysqli_num_rows($select_banks) >= 1)) {
		$banks_json_array_list = array();
		while ($get_bank_details = mysqli_fetch_array($select_banks)) {
            // Check if gateway is enabled (if gateway_name is set)
            if (!empty($get_bank_details['gateway_name'])) {
                if (!isServiceEnabled($get_bank_details['gateway_name'], $vid)) continue;
            }

			$banks_json_array = array("reference" => $get_bank_details["reference"], "bank_code" => $get_bank_details["bank_code"], "bank_name" => $get_bank_details["bank_name"], "account_name" => $get_bank_details["account_name"], "account_number" => $get_bank_details["account_number"]);
			$banks_json_array = json_encode($banks_json_array, true);
			array_push($banks_json_array_list, $banks_json_array);
		}
		return !empty($banks_json_array_list) ? $banks_json_array_list : false;
	} else {
		return false;
	}
}
function addVendorVirtualBank($reference, $bank_code, $bank_name, $account_number, $account_name, $vid = 0, $gateway_name = "")
{
	global $connection_server;

	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags($bank_code)));
	$bank_name = mysqli_real_escape_string($connection_server, trim(strip_tags($bank_name)));
	$account_number = mysqli_real_escape_string($connection_server, trim(strip_tags($account_number)));
	$account_name = mysqli_real_escape_string($connection_server, trim(strip_tags($account_name)));
    $gateway_name = mysqli_real_escape_string($connection_server, trim(strip_tags($gateway_name)));

    if (empty($gateway_name)) {
        // Auto-detect gateway if not provided
        $bn = strtoupper($bank_name);
        if (strpos($bn, 'WEMA') !== false || strpos($bn, 'VFD') !== false || strpos($bn, 'MONNIFY') !== false || strpos($bn, 'STERLING') !== false) $gateway_name = 'monnify';
        elseif (strpos($bn, 'GLOBUS') !== false || strpos($bn, 'TITAN') !== false || strpos($bn, 'PAYVESSEL') !== false) $gateway_name = 'payvessel';
        elseif (strcasecmp($bank_code, 'PayHub') === 0) $gateway_name = 'payhub';
    }

    if ($vid <= 0) $vid = resolveVendorID();
	$get_vendor_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vid' LIMIT 1"));

	if ($get_vendor_det && !empty($bank_code) && !empty($account_number) && !empty($account_name)) {
        if (empty($reference)) $reference = "VA_".time().rand(100,999);
		$select_banks = mysqli_query($connection_server, "SELECT * FROM sas_vendor_banks WHERE vendor_id='" . $get_vendor_det["id"] . "' && account_number='" . $account_number . "'");
		if (($select_banks == true) && (mysqli_num_rows($select_banks) == 0)) {
			mysqli_query($connection_server, "INSERT INTO sas_vendor_banks (vendor_id, reference, gateway_name, bank_code, bank_name, account_number, account_name, status) VALUES ('" . $get_vendor_det["id"] . "', '$reference', '$gateway_name', '$bank_code', '$bank_name', '$account_number', '$account_name', 1)");
		}
	}
}

function getVendorVirtualBank()
{
	global $connection_server;

	$vendor_id_func = resolveVendorID();
	$get_vendor_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id_func' LIMIT 1"));
	$get_logged_user_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . $get_vendor_det["id"] . "' LIMIT 1"));

	$select_banks = mysqli_query($connection_server, "SELECT * FROM sas_vendor_banks WHERE vendor_id='" . $get_logged_user_det["id"] . "' AND (status = 1 OR status IS NULL)");
	if (($select_banks == true) && (mysqli_num_rows($select_banks) >= 1)) {
		$banks_json_array_list = array();
		while ($get_bank_details = mysqli_fetch_array($select_banks)) {
			// Ensure disabled gateways do not display
			if (!empty($get_bank_details['gateway_name'])) {
				if (!isServiceEnabled($get_bank_details['gateway_name'], $get_logged_user_det["id"])) continue;
			}
			$banks_json_array = array("reference" => $get_bank_details["reference"], "bank_code" => $get_bank_details["bank_code"], "bank_name" => $get_bank_details["bank_name"], "account_name" => $get_bank_details["account_name"], "account_number" => $get_bank_details["account_number"]);
			$banks_json_array = json_encode($banks_json_array, true);
			array_push($banks_json_array_list, $banks_json_array);
		}
		return $banks_json_array_list;
	} else {
		return false;
	}
}

function alterTransaction($reference, $column_name, $column_value)
{
	global $connection_server;

	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$column_name = mysqli_real_escape_string($connection_server, trim(strip_tags($column_name)));
	$column_value = mysqli_real_escape_string($connection_server, trim(strip_tags($column_value)));

	if (!empty($reference) && !empty($column_name) && ($column_value !== "")) {
		// Use reference directly as it is unique. This supports both web and cron contexts.
		mysqli_query($connection_server, "UPDATE sas_transactions SET " . $column_name . "='" . $column_value . "' WHERE reference='" . $reference . "'");
	}
}

function alterVendorTransaction($reference, $column_name, $column_value)
{
	global $connection_server;

	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$column_name = mysqli_real_escape_string($connection_server, trim(strip_tags($column_name)));
	$column_value = mysqli_real_escape_string($connection_server, trim(strip_tags($column_value)));

	if (!empty($reference) && !empty($column_name) && ($column_value !== "")) {
		// Use reference directly as it is unique.
		mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET " . $column_name . "='" . $column_value . "' WHERE reference='" . $reference . "'");
	}
}

function getTransaction($reference, $column_name)
{
	global $connection_server;

	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$column_name = mysqli_real_escape_string($connection_server, trim(strip_tags($column_name)));

	if (!empty($reference) && !empty($column_name)) {
		// Use reference directly as it is unique. This supports both web and cron contexts.
		$select_transaction = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE reference='" . $reference . "' LIMIT 1");
		if (($select_transaction == true) && (mysqli_num_rows($select_transaction) == 1)) {
			$get_transaction_column = mysqli_fetch_array($select_transaction);
			return $get_transaction_column[$column_name];
		}

		// Guest Mode orders (web/guest-api/*) live in sas_guest_orders, a differently-shaped
		// table — but func/api-gateway/requery/*.php files were only ever written against
		// sas_transactions and call this exact helper directly (they were never designed to
		// accept injected variables the way the purchase-time gateway files are), so alias the
		// guest schema onto the columns those files actually read rather than duplicating
		// requery logic per provider. identity (recipient phone/IUC/meter) -> product_unique_id
		// is the one real name difference; every other column name already matches.
		$select_guest_order = mysqli_query($connection_server, "SELECT *, identity AS product_unique_id FROM sas_guest_orders WHERE reference='" . $reference . "' LIMIT 1");
		if (($select_guest_order == true) && (mysqli_num_rows($select_guest_order) == 1)) {
			$get_guest_order_column = mysqli_fetch_array($select_guest_order);
			return $get_guest_order_column[$column_name] ?? false;
		}
	}
	return false;
}

function transactionActionButton($api_id, $product_id, $transaction_ref, $transaction_status, $transaction_type, $transaction_desc = '')
{
	global $connection_server, $get_logged_user_details;
	if (!empty($api_id) && !empty($product_id) && !empty($get_logged_user_details['vendor_id'])) {
		$get_user_product_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $product_id . "' LIMIT 1"));
		$get_user_api_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $api_id . "' LIMIT 1"));


		$product_transaction_action_button = '-';

		if (in_array($get_user_api_details["api_type"] ?? '', array("electric")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/ViewElectric.php?ref=' . $transaction_ref . '" style="text-decoration: underline; color: green;" class="a-cursor">View Receipt</a>';

			// If token is likely missing or pending, allow manual refresh
			if(stripos($transaction_desc, 'PENDING') !== false || stripos($transaction_desc, 'TOKEN:') === false || empty($transaction_desc)) {
				$product_transaction_action_button .= ' | <a href="/web/Transactions.php?requery=' . $transaction_ref . '" style="text-decoration: underline; color: blue;" class="a-cursor">Refresh Token</a>';
			}
		}

		if (in_array($get_user_api_details["api_type"] ?? '', array("datacard", "rechargecard")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/ViewCard.php?ref=' . $transaction_ref . '" style="text-decoration: underline; color: green;" class="a-cursor">View Card</a>';
		}

		if (in_array($get_user_api_details["api_type"] ?? '', array("chimoney")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/VirtualCard.php" style="text-decoration: underline; color: green;" class="a-cursor">View Virtual Card</a>';
		}

		if (!in_array($get_user_api_details["api_type"] ?? '', array("electric", "datacard", "rechargecard", "chimoney")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '-';
		}
		$product_transaction_requery_button = '<a href="/web/Transactions.php?requery=' . $transaction_ref . '" style="text-decoration: underline; color: red;" class="a-cursor">Requery</a>';

		$all_product_transaction_actions_button = '-';
		//Successful
		if (in_array($transaction_status, array(1))) {
			$all_product_transaction_actions_button = $product_transaction_action_button;
		} else {
			//Pending
			if (in_array($transaction_status, array(2))) {
				$all_product_transaction_actions_button = $product_transaction_requery_button;
			} else {
				//Failed
				if (in_array($transaction_status, array(3))) {
					$all_product_transaction_actions_button = '-';
				}
			}
		}

		return $all_product_transaction_actions_button;
	} else {
		if (in_array(strtolower($transaction_type), array("bank transfer")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/ViewBankReceipt.php?ref=' . $transaction_ref . '" style="text-decoration: underline; color: green;" class="a-cursor">View Receipt</a>';
		} else {
			$product_transaction_action_button = "-";
		}

		return $product_transaction_action_button;
	}

}

function adminTransactionActionButton($api_id, $product_id, $transaction_ref, $transaction_status, $transaction_type, $is_payment_order = false, $transaction_desc = '')
{
	global $connection_server, $get_logged_admin_details;

	if ($is_payment_order && $transaction_status == 2) {
		$p_actions = '<div class="d-flex gap-2">';
		$p_actions .= '<a href="PaymentOrders.php?order-ref=' . $transaction_ref . '&order-status=2" class="btn btn-primary btn-sm">Approve</a>';
		$p_actions .= '<a href="PaymentOrders.php?order-ref=' . $transaction_ref . '&order-status=1" class="btn btn-danger btn-sm">Reject</a>';
		$p_actions .= '</div>';
		return $p_actions;
	}

	if (!empty($api_id) && !empty($product_id)) {
		$get_user_product_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && id='" . $product_id . "' LIMIT 1"));
		$get_user_api_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && id='" . $api_id . "' LIMIT 1"));

		$product_transaction_action_button = '-';

		if (in_array($get_user_api_details["api_type"] ?? '', array("electric")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/ViewElectric.php?ref=' . $transaction_ref . '" style="text-decoration: underline; color: green;" class="a-cursor">View Receipt</a>';

			if(stripos($transaction_desc, 'PENDING') !== false || stripos($transaction_desc, 'TOKEN:') === false || empty($transaction_desc)) {
				$product_transaction_action_button .= ' | <a href="/bc-admin/Transactions.php?requery=' . $transaction_ref . '" style="text-decoration: underline; color: blue;" class="a-cursor">Refresh Token</a>';
			}
		}

		if (in_array($get_user_api_details["api_type"] ?? '', array("datacard", "rechargecard")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/ViewCard.php?ref=' . $transaction_ref . '" style="text-decoration: underline; color: green;" class="a-cursor">View Card</a>';
		}

		if (in_array($get_user_api_details["api_type"] ?? '', array("chimoney")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/VirtualCard.php" style="text-decoration: underline; color: green;" class="a-cursor">View Virtual Card</a>';
		}

		if (!in_array($get_user_api_details["api_type"] ?? '', array("electric", "datacard", "rechargecard", "chimoney")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = 'Successful';
		}

		$product_transaction_requery_button = '<a href="/bc-admin/Transactions.php?requery=' . $transaction_ref . '" style="text-decoration: underline; color: red;" class="a-cursor">Requery</a>';

		$all_product_transaction_actions_button = '-';
		//Successful
		if (in_array($transaction_status, array(1))) {
			$all_product_transaction_actions_button = $product_transaction_action_button;
		} else {
			//Pending
			if (in_array($transaction_status, array(2))) {
				$all_product_transaction_actions_button = $product_transaction_requery_button;
			} else {
				//Failed
				if (in_array($transaction_status, array(3))) {
					$all_product_transaction_actions_button = '-';
				}
			}
		}

		return $all_product_transaction_actions_button;
	} else {
		if (in_array(strtolower($transaction_type), array("bank transfer")) && strpos($transaction_type, "refund") === false) {
			$product_transaction_action_button = '<a href="/web/ViewBankReceipt.php?ref=' . $transaction_ref . '" style="text-decoration: underline; color: green;" class="a-cursor">View Receipt</a>';
		} else {
			$product_transaction_action_button = "-";
		}

		return $product_transaction_action_button;
	}

}

function alterUser($userID, $column_name, $column_value)
{
	global $connection_server;

	$userID = mysqli_real_escape_string($connection_server, trim(strip_tags($userID)));
	$column_name = mysqli_real_escape_string($connection_server, trim(strip_tags($column_name)));
	$column_value = mysqli_real_escape_string($connection_server, trim(strip_tags($column_value)));

	if (!empty($userID) && !empty($column_name) && !empty($column_value)) {
		$vendor_id_func = resolveVendorID();
		$get_logged_user_det = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id_func' && username='$userID'");
		if (mysqli_num_rows($get_logged_user_det) == 1) {
			while ($user_details = mysqli_fetch_assoc($get_logged_user_det)) {
				$vendor_id = $user_details["vendor_id"];
				$username_id = $user_details["username"];
				$update_user_details = mysqli_query($connection_server, "UPDATE sas_users SET $column_name='$column_value' WHERE vendor_id='$vendor_id' && username='$username_id'");
				if ($update_user_details == true) {
					return "success";
				} else {
					return "failed";
				}
			}
		} else {
			return "failed";
		}
	}
}

function alterVendor($userID, $column_name, $column_value)
{
	global $connection_server;

	$userID = mysqli_real_escape_string($connection_server, trim(strip_tags($userID)));
	$column_name = mysqli_real_escape_string($connection_server, trim(strip_tags($column_name)));
	$column_value = mysqli_real_escape_string($connection_server, trim(strip_tags($column_value)));

	if (!empty($userID) && !empty($column_name) && !empty($column_value)) {
		$get_logged_user_det = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$userID'");
		if (mysqli_num_rows($get_logged_user_det) == 1) {
			while ($user_details = mysqli_fetch_assoc($get_logged_user_det)) {
				$vendor_id = $user_details["id"];
				$update_user_details = mysqli_query($connection_server, "UPDATE sas_vendors SET $column_name='$column_value' WHERE id='$vendor_id'");
				if ($update_user_details == true) {
					return "success";
				} else {
					return "failed";
				}
			}
		} else {
			return "failed";
		}
	}
}

function alterAPI($userID, $apiID, $column_name, $column_value)
{
	global $connection_server;

	$userID = mysqli_real_escape_string($connection_server, trim(strip_tags($userID)));
	$apiID = mysqli_real_escape_string($connection_server, trim(strip_tags($apiID)));
	$column_name = mysqli_real_escape_string($connection_server, trim(strip_tags($column_name)));
	$column_value = mysqli_real_escape_string($connection_server, trim($column_value));

	if (!empty($userID) && !empty($apiID) && !empty($column_name) && in_array($column_value, array(0, 1))) {
		$get_logged_user_det = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$userID'");
		if (mysqli_num_rows($get_logged_user_det) == 1) {
			while ($user_details = mysqli_fetch_assoc($get_logged_user_det)) {
				$vendor_id = $user_details["id"];
				$update_user_details = mysqli_query($connection_server, "UPDATE sas_apis SET $column_name='$column_value' WHERE id='$apiID' && vendor_id='$vendor_id'");
				if ($update_user_details == true) {
					return "success";
				} else {
					return "failed";
				}
			}
		} else {
			return "failed";
		}
	}
}

function toDecimal($number, $decimalIndex)
{
	if (is_numeric($number) && is_numeric($decimalIndex)) {
		return number_format((float)$number, (int)$decimalIndex, '.', '');
	} else {
		return "non-numeric string";
	}
}

function productIDBlockChecker($item_id)
{
	global $connection_server, $get_logged_user_details;

	$item_id = mysqli_real_escape_string($connection_server, trim(strip_tags($item_id)));
	if (!empty($item_id) && is_numeric($item_id)) {
		$select_item_query = mysqli_query($connection_server, "SELECT * FROM sas_id_blocking_system WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_id='$item_id'");
		if (mysqli_num_rows($select_item_query) == 0) {
			return "success";
		} else {
			if (mysqli_num_rows($select_item_query) > 0) {
				return "failed";
			}
		}
	} else {
		return "failed";
	}
}

function productIDPurchaseChecker($item_id, $product_type, $purchase_method = "WEB")
{
	global $connection_server, $get_logged_user_details;
    static $limits_cache = [];

	$item_id = mysqli_real_escape_string($connection_server, trim(strip_tags($item_id)));
	$product_type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($product_type))));
	$purchase_method = strtoupper($purchase_method);

	if (!empty($item_id) && is_numeric($item_id) && !empty($product_type)) {
        $vid = $get_logged_user_details["vendor_id"];
        $uname = $get_logged_user_details["username"];

        // Cache limits for current vendor to avoid redundant queries in bulk checks
        if(!isset($limits_cache[$vid])) {
            $limits_cache[$vid] = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_limit WHERE vendor_id='$vid' LIMIT 1"));
        }
		$get_user_daily_purchase_limit_details = $limits_cache[$vid];

        $shared_types = array('sme-data','cg-data','dd-data','shared-data','airtime','data');
        if (in_array($product_type, $shared_types)) {
            $shared_types_str = "'" . implode("','", $shared_types) . "'";
            $type_condition = "product_type IN ($shared_types_str)";
        } else {
            $type_condition = "product_type='$product_type'";
        }

        $today = date("Y-m-d");
		// Optimization: Using optimized indexes added in Branch DG6.7
		$count_query = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_daily_purchase_tracker WHERE vendor_id='$vid' AND username='$uname' AND product_id='$item_id' AND $type_condition AND date_purchased='$today'");
        $tx_count = mysqli_fetch_assoc($count_query)['count'] ?? 0;

		$select_validated_item_query = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_validated_user_purchase_id_list WHERE vendor_id='$vid' AND product_id='$item_id'");
        $is_validated = (mysqli_fetch_assoc($select_validated_item_query)['count'] ?? 0) > 0;

		$limit = $get_user_daily_purchase_limit_details["limit"] ?? 5;
		if ($product_type == "cable") $limit = $get_user_daily_purchase_limit_details["limit_cable"] ?? $limit;
		elseif ($product_type == "betting") $limit = $get_user_daily_purchase_limit_details["limit_betting"] ?? $limit;
		elseif ($product_type == "electric") $limit = $get_user_daily_purchase_limit_details["limit_electric"] ?? $limit;
		elseif (in_array($product_type, $shared_types)) $limit = $get_user_daily_purchase_limit_details["limit_phone"] ?? $limit;

		if (($tx_count < $limit) || $is_validated) {
			return "success";
		} else {
            // Only block if it's an actual transaction attempt
            if ($purchase_method !== "WEB_CHECK") {
                // Record abuse attempt for IP blocking
                recordServiceAbuse($get_logged_user_details["username"], $_SERVER['REMOTE_ADDR'], $get_logged_user_details["vendor_id"]);

                // Strict enforcement for ALL methods: Disable API and block account immediately if limit exceeded
                alterUser($get_logged_user_details["username"], "api_status", "2");
                alterUser($get_logged_user_details["username"], "status", "2");

                // Block IP immediately
                $settings = getBruteForceSettings($get_logged_user_details["vendor_id"]);
                blockIP($_SERVER['REMOTE_ADDR'], $get_logged_user_details["vendor_id"], $settings['block_duration'], "Exceeded Daily Transaction Limit for $product_type ($purchase_method)");

                // Send notification to Admin
                $get_vendor_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . $get_logged_user_details["vendor_id"] . "' LIMIT 1"));
                $subject = "SECURITY ALERT: Service Abuse Detected - " . $get_logged_user_details["username"];
                $body = "Dear Admin,<br><br>The user <b>" . $get_logged_user_details["username"] . "</b> has exceeded the Daily Transaction Limit of <b>$limit</b> for <b>$product_type</b> (ID: $item_id) via $purchase_method.<br><br>As per security policy, their access has been disabled, their account locked, and their IP address (" . $_SERVER['REMOTE_ADDR'] . ") has been blocked.<br><br>Please review this activity.";
                sendVendorEmail($get_vendor_det["email"], $subject, $body);
            }

			return "LIMIT_REACHED";
		}
	} else {
		return "failed";
	}
}

function updateProductPurchaseList($reference, $item_id, $product_type)
{
	global $connection_server, $get_logged_user_details;

	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));
	$item_id = mysqli_real_escape_string($connection_server, trim(strip_tags($item_id)));
	$product_type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($product_type))));

	if (!empty($reference) && is_numeric($reference) && !empty($item_id) && is_numeric($item_id) && !empty($product_type)) {
        $vid = $get_logged_user_details["vendor_id"];
        $uname = $get_logged_user_details["username"];
        $today = date("Y-m-d");

		mysqli_query($connection_server, "INSERT INTO sas_daily_purchase_tracker (vendor_id, reference, product_type, product_id, username, date_purchased) VALUES ('$vid','$reference','$product_type','$item_id','$uname','$today')");

        $shared_types = array('sme-data','cg-data','dd-data','shared-data','airtime','data');
        if (in_array($product_type, $shared_types)) {
            $shared_types_str = "'" . implode("','", $shared_types) . "'";
            $type_condition = "product_type IN ($shared_types_str)";
        } else {
            $type_condition = "product_type='$product_type'";
        }

		$count_query = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_daily_purchase_tracker WHERE vendor_id='$vid' AND username='$uname' AND product_id='$item_id' AND $type_condition AND date_purchased='$today'");
        $tx_count = mysqli_fetch_assoc($count_query)['count'] ?? 0;

		$get_user_daily_purchase_limit_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_limit WHERE vendor_id='$vid' LIMIT 1"));

		$limit = $get_user_daily_purchase_limit_details["limit"] ?? 5;
		if ($product_type == "cable") $limit = $get_user_daily_purchase_limit_details["limit_cable"] ?? $limit;
		elseif ($product_type == "betting") $limit = $get_user_daily_purchase_limit_details["limit_betting"] ?? $limit;
		elseif ($product_type == "electric") $limit = $get_user_daily_purchase_limit_details["limit_electric"] ?? $limit;
		elseif (in_array($product_type, $shared_types)) $limit = $get_user_daily_purchase_limit_details["limit_phone"] ?? $limit;

		if ($tx_count > $limit) {
			//Block Suspicious Accounts & Disable API immediately
			alterUser($get_logged_user_details["username"], "status", "2");
			alterUser($get_logged_user_details["username"], "api_status", "2");

			// Block IP immediately
			$settings = getBruteForceSettings($get_logged_user_details["vendor_id"]);
			blockIP($_SERVER['REMOTE_ADDR'], $get_logged_user_details["vendor_id"], $settings['block_duration'], "Exceeded Daily Transaction Limit for $product_type after purchase");

			// Email Beginning
			$vendor_id_func = resolveVendorID();
			$get_vendor_det = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id_func' LIMIT 1"));
			$transaction_template_encoded_text_array = array("{admin_firstname}" => $get_vendor_det["firstname"], "{admin_lastname}" => $get_vendor_det["lastname"], "{username}" => $get_logged_user_details["username"], "{firstname}" => $get_logged_user_details["firstname"], "{lastname}" => $get_logged_user_details["lastname"], "{limit}" => $limit, "{type}" => $product_type, "{id}" => $item_id);
			$raw_transaction_template_subject = "URGENT: Max Daily Tx Limit Reached - User: {username}";
			$raw_transaction_template_body = "Dear {admin_firstname}, " . "\n\n" . "User {username} ({firstname} {lastname}) has hit the Max Daily Limit ({limit}) for {type} ID: {id}." . "\n\n" . "Actions taken automatically:" . "\n" . "- User account status set to Suspended" . "\n" . "- User API access DISABLED" . "\n" . "- Abuse attempt logged for IP blocking" . "\n\n" . "Please review this activity immediately.";
			foreach ($transaction_template_encoded_text_array as $array_key => $array_val) {
				$raw_transaction_template_subject = str_replace($array_key, $array_val, $raw_transaction_template_subject);
				$raw_transaction_template_body = str_replace($array_key, $array_val, $raw_transaction_template_body);
			}
			sendVendorEmail($get_vendor_det["email"], $raw_transaction_template_subject, $raw_transaction_template_body);
			// Email End
		}
		return "success";
	} else {
		return "failed";
	}
}

function recordServiceAbuse($username, $ip, $vendor_id) {
    global $connection_server;
    // We reuse recordLoginAttempt logic to trigger IP blocking
    recordLoginAttempt($username, $ip, 0, $vendor_id);
}

function sendUnblockNotification($username, $ip, $vendor_id, $reason) {
    global $connection_server;

    $identity = !empty($username) ? $username : "IP: $ip";

    // Check if it is a vendor/admin or a regular user
    // If username is empty, we check the context (directory) or just notify both?
    // Actually, if called from bc-admin directory, it should be for vendor.

    $is_vendor = (strpos($username, '@') !== false) || (strpos($_SERVER['SCRIPT_NAME'], '/bc-admin/') !== false);

    if ($is_vendor) {
        // Likely a vendor (uses email or called from admin panel)
        $get_spadmin = mysqli_fetch_array(mysqli_query($connection_server, "SELECT email FROM sas_super_admin LIMIT 1"));
        $target_email = $get_spadmin['email'] ?? 'admin@philmorecodes.com';
        $subject = "URGENT: Vendor Unblock Request - $identity";
        $body = "Dear Super Admin,<br><br>Vendor <b>$identity</b> has requested an account/IP unblock.<br><br><b>Reason:</b> $reason<br><br>Please login to the Super Admin panel to review and approve/reject this request.";
        sendVendorEmail($target_email, $subject, $body);
    } else {
        // Likely a regular user
        $get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT email, firstname, lastname FROM sas_vendors WHERE id='$vendor_id' LIMIT 1"));
        $target_email = $get_vendor['email'];
        $subject = "SECURITY: User Unblock Request - $identity";
        $body = "Dear " . $get_vendor['firstname'] . ",<br><br>Your user <b>$identity</b> has been blocked due to multiple failed attempts or security policy violations.<br><br><b>Reason:</b> $reason<br><br><b>How to unblock:</b><br>1. Login to your Admin Dashboard.<br>2. Go to <b>Security Settings > Brute Force Security</b>.<br>3. Locate the blocked IP or Account under <b>Active Restrictions</b> or <b>Unblock Requests</b>.<br>4. Click <b>Unblock</b> or <b>Approve</b> to restore access.";
        sendVendorEmail($target_email, $subject, $body);
    }
}

function removeProductPurchaseList($reference)
{
	global $connection_server;

	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($reference)));

	if (!empty($reference)) {
		// Robust removal by reference alone as it is unique.
		$select_item_query = mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_tracker WHERE reference='$reference' LIMIT 1");
		if ($row = mysqli_fetch_assoc($select_item_query)) {
			mysqli_query($connection_server, "DELETE FROM sas_daily_purchase_tracker WHERE reference='$reference'");
			return "success";
		}
	}
	return "failed";
}

function formDate($date_time)
{
	if (!empty($date_time) || ($date_time !== NULL)) {
		$exp_date_time = array_filter(explode(" ", trim($date_time)));
		$date = $exp_date_time[0];
		$time = $exp_date_time[1];

		$month = array("01" => "January", "02" => "Febuary", "03" => "March", "04" => "April", "05" => "May", "06" => "June", "07" => "July", "08" => "August", "09" => "September", "10" => "October", "11" => "November", "12" => "December");
		$exp_date = explode("-", trim($date));
		$post_date = $month[$exp_date[1]] . ", " . $exp_date[2] . " " . $exp_date[0];
		$exp_time = explode(":", trim($time));
		if ($exp_time[0] > 12) {
			$hour = ($exp_time[0] - 12);
			$period = "pm";
		} else {
			$hour = $exp_time[0];
			$period = "am";
		}
		$min = $exp_time[1];
		$sec = $exp_time[2];

		return $post_date . " " . $hour . ":" . $min . "." . $sec . $period;
	} else {
		return "DateTime Not Available";
	}
}

function formDateWithoutTime($date)
{
	if (!empty($date) || ($date !== NULL)) {
		$month = array("01" => "January", "02" => "Febuary", "03" => "March", "04" => "April", "05" => "May", "06" => "June", "07" => "July", "08" => "August", "09" => "September", "10" => "October", "11" => "November", "12" => "December");
		$exp_date = explode("-", trim($date));
		$post_date = $month[$exp_date[1]] . " " . $exp_date[2] . ", " . $exp_date[0];

		return $post_date;
	} else {
		return "Date Not Available";
	}
}

function timeFrame($time)
{
	if (!empty($time) || ($time !== NULL)) {
		$exp_time = array_filter(explode(":", trim($time)));
		$hr = $exp_time[0];
		$min = $exp_time[1];
		if (in_array($hr, range(0, 11))) {
			return $hr . ":" . $min . "am";
		}
		if (in_array($hr, range(12, 24))) {
			return ($hr - 12) . ":" . $min . "pm";
		}
	} else {
		return "Time Not Available";
	}
}

function getUserEmailTemplate($row_id, $column_name)
{
	global $connection_server;
	$vendor_id = resolveVendorID();
	if($vendor_id <= 0) return getSuperAdminEmailTemplate($row_id, $column_name);

	$template_details = mysqli_query($connection_server, "SELECT * FROM sas_email_templates WHERE vendor_id='$vendor_id' && email_type='$row_id'");
	if (mysqli_num_rows($template_details) >= 1) {
		$template_array = mysqli_fetch_array($template_details);
		if (isset($template_array[$column_name]) && !empty($template_array[$column_name])) {
			return $template_array[$column_name];
		}
	}

	// Fallback to Super Admin Template
	return getSuperAdminEmailTemplate($row_id, $column_name);
}

function getVendorEmailTemplate($row_id, $column_name)
{
	global $connection_server;
	$vendor_id = resolveVendorID();
	if($vendor_id <= 0) return getSuperAdminEmailTemplate($row_id, $column_name);

	$template_details = mysqli_query($connection_server, "SELECT * FROM sas_email_templates WHERE vendor_id='$vendor_id' && email_type='$row_id'");
	if (mysqli_num_rows($template_details) >= 1) {
		$template_array = mysqli_fetch_array($template_details);
		if (isset($template_array[$column_name]) && !empty($template_array[$column_name])) {
			return $template_array[$column_name];
		}
	}

	// Fallback to Super Admin Template
	return getSuperAdminEmailTemplate($row_id, $column_name);
}

function getSuperAdminEmailTemplate($row_id, $column_name)
{
	global $connection_server;
	$template_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_email_templates WHERE email_type='$row_id'");
	if (mysqli_num_rows($template_details) == 1) {
		$template_array = mysqli_fetch_array($template_details);
		if (isset($template_array[$column_name])) {
			return $template_array[$column_name];
		} else {
			//Column Mismatch
			return "";
		}
	} else {
		if (mysqli_num_rows($template_details) > 1) {
			//Duplicated Details
			return "";
		} else {
			if (mysqli_num_rows($template_details) == 0) {
				//Null
				return "";
			}
		}
	}
}

function createSuperAdminEmailTemplateIfNotExists($email_type, $subject, $body)
{
	global $connection_server;

	$email_type = mysqli_real_escape_string($connection_server, trim(strip_tags($email_type)));
	$subject = mysqli_real_escape_string($connection_server, trim(strip_tags($subject)));
	$body = mysqli_real_escape_string($connection_server, trim($body)); // Body allows HTML

	if (!empty($subject) && !empty($body) && !empty($email_type)) {
		$template_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_email_templates WHERE email_type='$email_type'");
		if (mysqli_num_rows($template_details) == 0) {
			mysqli_query($connection_server, "INSERT INTO sas_super_admin_email_templates (email_type, subject, body) VALUES ('$email_type', '$subject', '$body')");
			return "success";
		} else {
			return "failed";
		}
	}
}

function get_admin_info($email, $column_name)
{
	global $connection_server;
	$vendor_id = resolveVendorID();
	if($vendor_id <= 0) return "";
	$checkadmin = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id = '$vendor_id' AND email='" . $email . "'");
	if ($checkadmin && mysqli_num_rows($checkadmin) == 1) {
		$get_admin_details = mysqli_fetch_array($checkadmin);
		$column_exists = false;
		$describe_table = mysqli_query($connection_server, "DESCRIBE sas_vendors");
		while ($row = mysqli_fetch_assoc($describe_table)) {
			if ($row["Field"] === strtolower($column_name)) {
				$column_exists = true;
			}
		}
		if ($column_exists) {
			return $get_admin_details[$column_name];
		} else {
			return "Error: Requested field not exists";
		}
	} else {
		return "Error: Vendor not exists";
	}
}

function get_user_info($username_or_email, $column_name)
{
	global $connection_server;
	$vendor_id = resolveVendorID();
	if ($vendor_id > 0) {
		$checkuser = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '$vendor_id' AND (username = '" . $username_or_email . "' OR email='" . $username_or_email . "')");
		if (mysqli_num_rows($checkuser) == 1) {
			$get_user_details = mysqli_fetch_array($checkuser);
			$column_exists = false;
			$describe_table = mysqli_query($connection_server, "DESCRIBE sas_users");
			while ($row = mysqli_fetch_assoc($describe_table)) {
				if ($row["Field"] === strtolower($column_name)) {
					$column_exists = true;
				}
			}
			if ($column_exists) {
				return $get_user_details[$column_name];
			} else {
				return "Error: Requested field not exists";
			}
		} else {
			return "Error: User not exists";
		}
	} else {
		return "Error: Vendor not exists";
	}
}


/**
 * getSMTPUserForHeaders()
 * Returns a valid smtp_user email address for use in From: headers.
 * This ensures RFC 5322 compliance even in CLI/cron contexts where
 * $_SERVER["HTTP_HOST"] is empty, which was causing Gmail 550 5.7.1 rejections.
 *
 * Ported from the SAAS edition: NON-SAAS called this from beeMailer() and
 * sendVendorEmail() but never defined it, so any code path that sent mail -
 * including saving the Account Settings security tab - died with
 * "Call to undefined function getSMTPUserForHeaders()".
 */
function getSMTPUserForHeaders($connection_server) {
    // Try vendor SMTP first
    $vid = resolveVendorID();
    if ($vid > 0) {
        $q = mysqli_query($connection_server, "SELECT smtp_user FROM sas_vendors WHERE id='$vid' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['smtp_user'])) {
            return $r['smtp_user'];
        }
    }
    // Fall back to super admin SMTP
    $q = mysqli_query($connection_server, "SELECT smtp_user FROM sas_super_admin LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['smtp_user'])) {
        return $r['smtp_user'];
    }
    // Last resort default (same as bc-mailer.php)
    return 'notification@cheaperdata.com.ng';
}

function beeMailer($recipient_email, $email_subject, $email_body)
{

	global $connection_server, $get_all_site_details, $get_all_super_admin_site_details;
	// Always set content-type when sending HTML email
	$mail_headers = "MIME-Version: 1.0" . "\r\n";
	$mail_headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

	// More headers
    $from_name = $get_all_site_details["site_title"] ?? $get_all_super_admin_site_details["site_title"] ?? "System Mailer";
    // Use smtp_user for From address so it is valid even in CLI/cron (HTTP_HOST is empty there)
    $smtp_from_addr = getSMTPUserForHeaders($connection_server);
	$mail_headers .= 'From: ' . $from_name . ' <' . $smtp_from_addr . '>' . "\r\n";
	// Only add a Cc: when we actually have a valid admin address. get_admin_info(1, "email")
	// returns the literal string "Error: Vendor not exists" when the lookup fails; that bogus
	// value used to flow into PHPMailer's addCC() → "Invalid address" exception → every
	// beeMailer() email (mobile login/register/forgot) silently fell back to the raw mail()
	// path — exactly where the Gmail 550 5.7.1 "missing From" rejections come from.
	$admin_cc = get_admin_info(1, "email");
	if (!empty($admin_cc) && filter_var($admin_cc, FILTER_VALIDATE_EMAIL)) {
		$mail_headers .= 'Cc: ' . $admin_cc . "\r\n";
	}
	//$mail_headers .= 'Subject: '.$email_subject."\r\n";

	$website_admin_phone_number = "234" . substr(get_admin_info(1, "phone_number"), 1, 11);
	$details_array = array($website_admin_phone_number);
	$mail_html_body = mailDesignTemplate($email_subject, $email_body, $details_array, true);
	$sent = customBCMailSender('', $recipient_email, $email_subject, $mail_html_body, $mail_headers);
	@fwrite(@fopen("./email-msg.txt", "a++"), "\n" . $recipient_email . " || " . strtoupper($email_subject) . " || " . $email_body . "\n");
	return $sent;
}

function sendVendorEmail($recipient_email, $email_subject, $email_body)
{
	global $connection_server, $get_logged_user_details, $get_logged_admin_details, $get_all_site_details, $get_all_super_admin_site_details;
	if (isset($get_logged_user_details) && !empty($get_logged_user_details["username"])) {
		$logged_account_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . $get_logged_user_details["vendor_id"] . "'"));
	} else {
		if (isset($get_logged_admin_details) && !empty($get_logged_admin_details["email"])) {
			$logged_account_details = $get_logged_admin_details;
		} else {
			$vendor_id = resolveVendorID();
			if($vendor_id <= 0) return;
			$logged_account_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id'"));
		}
	}

    // Toggle off transaction emails if disabled by vendor
    if (stripos($email_subject, "Transaction") !== false || stripos($email_subject, "Purchase") !== false || stripos($email_subject, "Fulfillment") !== false) {
        if (isset($logged_account_details['trans_email_enabled']) && $logged_account_details['trans_email_enabled'] == 0) {
            return true;
        }
    }
	// Always set content-type when sending HTML email
	$mail_headers = "MIME-Version: 1.0" . "\r\n";
	$mail_headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

	// More headers
    $from_name = $get_all_site_details["site_title"] ?? $get_all_super_admin_site_details["site_title"] ?? "System Admin";
    // Use smtp_user for From address so it is valid even in CLI/cron (HTTP_HOST is empty there)
    $smtp_from_addr = getSMTPUserForHeaders($connection_server);
	$mail_headers .= 'From: ' . $from_name . ' <' . $smtp_from_addr . '>' . "\r\n";
	//$mail_headers .= 'Cc: ' . $logged_account_details["email"] . "\r\n";
	//$mail_headers .= 'Subject: '.$email_subject."\r\n";

	$website_admin_phone_number = "234" . substr($logged_account_details["phone_number"], 1, 11);
	$details_array = array($website_admin_phone_number);
	$mail_html_body = mailDesignTemplate($email_subject, $email_body, $details_array, true);

    // Branch DG6.7: Don't block login UI for background email notifications
    $is_background = (stripos($email_subject, "Login") !== false || stripos($email_subject, "Account") !== false);
	return customBCMailSender('', $recipient_email, $email_subject, $mail_html_body, $mail_headers, $is_background);
	//fwrite(fopen("./email-msg.txt", "a++"), "\n".$recipient_email." || ".strtoupper($email_subject)." || ".$email_body."\n");
}

function sendSuperAdminEmail($recipient_email, $email_subject, $email_body)
{
	global $connection_server, $get_logged_spadmin_details, $get_all_super_admin_site_details;
	if (isset($get_logged_spadmin_details) && !empty($get_logged_spadmin_details["email"])) {
		$logged_account_details = $get_logged_spadmin_details;
	} else {
		$logged_account_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$recipient_email' LIMIT 1"));
	}

    // Toggle off transaction emails if disabled by super admin
    if (stripos($email_subject, "Transaction") !== false || stripos($email_subject, "Purchase") !== false || stripos($email_subject, "Fulfillment") !== false) {
        if (getSuperAdminOption('spadmin_trans_email_enabled', '1') == 0) {
            return true;
        }
    }

	// Always set content-type when sending HTML email
	$mail_headers = "MIME-Version: 1.0" . "\r\n";
	$mail_headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

	// More headers
    $from_name = $get_all_super_admin_site_details["site_title"] ?? "Super Admin";
	$mail_headers .= 'From: ' . $from_name . ' <no-reply@' . $_SERVER["HTTP_HOST"] . '>' . "\r\n";
	//$mail_headers .= 'Cc: ' . $logged_account_details["email"] . "\r\n";
	//$mail_headers .= 'Subject: '.$email_subject."\r\n";

	$website_admin_phone_number = "234" . substr($logged_account_details["phone_number"], 1, 11);
	$details_array = array($website_admin_phone_number);
	$mail_html_body = mailDesignTemplate($email_subject, $email_body, $details_array, false);

    // Branch DG6.7: Don't block UI for background email notifications
    $is_background = (stripos($email_subject, "Login") !== false || stripos($email_subject, "Account") !== false);
	return customBCMailSender('', $recipient_email, $email_subject, $mail_html_body, $mail_headers, $is_background);
	//fwrite(fopen("./email-msg.txt", "a++"), "\n".$recipient_email." || ".strtoupper($email_subject)." || ".$email_body."\n");
}

function sendVendorEmailSpecific($mailto_type, $email_subject, $email_body)
{
	global $connection_server, $get_logged_admin_details;
	$mailto_array = array("all" => "(status='1' OR status='2' OR status='3')", "a" => "status='1'", "b" => "status='2'", "d" => "status='3'", "bd" => "(status='2' OR status='3')");
	if (in_array($mailto_type, array_keys($mailto_array))) {
		$select_users = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && " . $mailto_array[$mailto_type]);
		if (mysqli_num_rows($select_users) >= 1) {
			while ($each_user = mysqli_fetch_assoc($select_users)) {
				$reg_template_encoded_text_array = array("{firstname}" => $each_user["firstname"], "{lastname}" => $each_user["lastname"], "{username}" => $each_user["username"], "{address}" => $each_user["home_address"], "{email}" => $each_user["email"], "{phone}" => $each_user["phone_number"], "{balance}" => toDecimal($each_user["balance"], 2), "{website}" => $get_logged_admin_details["website_url"]);
				$raw_reg_template_subject = $email_subject;
				$raw_reg_template_body = $email_body;
				foreach ($reg_template_encoded_text_array as $array_key => $array_val) {
					$raw_reg_template_subject = str_replace($array_key, $array_val, $raw_reg_template_subject);
					$raw_reg_template_body = str_replace($array_key, $array_val, $raw_reg_template_body);
				}
				sendVendorEmail($each_user["email"], $raw_reg_template_subject, $raw_reg_template_body);
			}
			return "success";
		} else {
			return "failed";
		}
	} else {
		return "error";
	}

}

function sendSuperAdminEmailSpecific($mailto_type, $email_subject, $email_body)
{
	global $connection_server;
	$mailto_array = array("all" => "(status='1' OR status='2' OR status='3')", "a" => "status='1'", "b" => "status='2'", "d" => "status='3'", "bd" => "(status='2' OR status='3')");
	if (in_array($mailto_type, array_keys($mailto_array))) {
		$select_vendors = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE " . $mailto_array[$mailto_type]);
		if (mysqli_num_rows($select_vendors) >= 1) {
			while ($each_vendor = mysqli_fetch_assoc($select_vendors)) {
				$reg_template_encoded_text_array = array("{firstname}" => $each_vendor["firstname"], "{lastname}" => $each_vendor["lastname"], "{address}" => $each_vendor["home_address"], "{email}" => $each_vendor["email"], "{phone}" => $each_vendor["phone_number"], "{website}" => $each_vendor["website_url"], "{balance}" => toDecimal($each_vendor["balance"], 2));
				$raw_reg_template_subject = $email_subject;
				$raw_reg_template_body = $email_body;
				foreach ($reg_template_encoded_text_array as $array_key => $array_val) {
					$raw_reg_template_subject = str_replace($array_key, $array_val, $raw_reg_template_subject);
					$raw_reg_template_body = str_replace($array_key, $array_val, $raw_reg_template_body);
				}
				sendSuperAdminEmail($each_vendor["email"], $raw_reg_template_subject, $raw_reg_template_body);
			}
			return "success";
		} else {
			return "failed";
		}
	} else {
		return "error";
	}

}


function createVendorEmailTemplateIfNotExists($email_type, $subject, $body)
{
	global $connection_server;

	$email_type = mysqli_real_escape_string($connection_server, trim(strip_tags($email_type)));
	$subject = mysqli_real_escape_string($connection_server, trim(strip_tags($subject)));
	$body = mysqli_real_escape_string($connection_server, trim($body)); // Body allows HTML

	if (!empty($subject) && !empty($body) && !empty($email_type)) {
		$vendor_id = resolveVendorID();
		if ($vendor_id > 0) {
			$template_details = mysqli_query($connection_server, "SELECT * FROM sas_email_templates WHERE vendor_id='$vendor_id' && email_type='$email_type'");
			if (mysqli_num_rows($template_details) == 0) {
				mysqli_query($connection_server, "INSERT INTO sas_email_templates (vendor_id, email_type, subject, body) VALUES ('$vendor_id', '$email_type', '$subject', '$body')");
				return "success";
			} else {
				return "failed";
			}
		} else {
			return "failed";
		}
	} else {
		return "failed";
	}
}

//Payment Gateways
//User Token
function getUserMonnifyAccessToken()
{
	if (isset($_SESSION["monnify_user_token"]) && isset($_SESSION["monnify_user_token_expiry"]) && time() < $_SESSION["monnify_user_token_expiry"]) {
		return json_encode(["status" => "success", "message" => "Token from Cache", "token" => $_SESSION["monnify_user_token"]], true);
	}

	global $connection_server, $get_logged_user_details;
	$select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && gateway_name='monnify'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);
		if ($get_gateway_details["status"] == "1") {
			$monnify_public_key = trim($get_gateway_details["public_key"]);
			$monnify_secret_key = trim($get_gateway_details["secret_key"]);

			if (!empty($monnify_public_key) && !empty($monnify_secret_key)) {

				$curl_url = "https://api.monnify.com/api/v1/auth/login";
				$curl_request = curl_init($curl_url);
				curl_setopt($curl_request, CURLOPT_POST, true);

				// $post_field_array = array("username" => $username, "password" => $password);
				// curl_setopt($curl_request, CURLOPT_POSTFIELDS, json_encode($post_field_array, true));
				curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

				$header_field_array = array("Authorization: Basic " . base64_encode($monnify_public_key . ":" . $monnify_secret_key), "Content-Type: application/json", "Content-Length: 0");
				curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
				curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

				curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
				curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
				$curl_result = curl_exec($curl_request);
				$curl_json_result = json_decode($curl_result, true);

				if ($curl_json_result !== null) {
					if (($curl_json_result["responseMessage"] == "success") && isset($curl_json_result["responseBody"]["accessToken"]) && !empty($curl_json_result["responseBody"]["accessToken"])) {
						$accessToken = $curl_json_result["responseBody"]["accessToken"];
						$_SESSION["monnify_user_token"] = $accessToken;
						$_SESSION["monnify_user_token_expiry"] = time() + ($curl_json_result["responseBody"]["expiresIn"] ?? 3600) - 60;
						$monnify_json_response_array = array("status" => "success", "message" => "Token Generated", "token" => $accessToken);
						return json_encode($monnify_json_response_array, true);
					} else {
						$monnify_json_response_array = array("status" => "failed", "message" => "No Access Token");
						return json_encode($monnify_json_response_array, true);
					}
				}

				if ($curl_result === false) {
					$curl_error = curl_error($curl_request);
					$monnify_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
					return json_encode($monnify_json_response_array, true);
				}

				if ($curl_json_result === null) {
					$curl_error = curl_error($curl_request);
					$monnify_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
					return json_encode($monnify_json_response_array, true);
				}

				curl_close($curl_request);
			} else {
				$monnify_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
				return json_encode($monnify_json_response_array, true);
			}
		} else {
			$monnify_json_response_array = array("status" => "failed", "message" => "Monnify Is Down/Disabled");
			return json_encode($monnify_json_response_array, true);
		}
	} else {
		$monnify_json_response_array = array("status" => "failed", "message" => "Monnify Has Not Been Installed");
		return json_encode($monnify_json_response_array, true);
	}
}

//Vendor Admin Token
function getVendorUserMonnifyAccessToken()
{
	if (isset($_SESSION["monnify_vendor_user_token"]) && isset($_SESSION["monnify_vendor_user_token_expiry"]) && time() < $_SESSION["monnify_vendor_user_token_expiry"]) {
		return json_encode(["status" => "success", "message" => "Token from Cache", "token" => $_SESSION["monnify_vendor_user_token"]], true);
	}

	global $connection_server, $get_logged_admin_details;
	$select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && gateway_name='monnify'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);
		if ($get_gateway_details["status"] == "1") {
			$monnify_public_key = trim($get_gateway_details["public_key"]);
			$monnify_secret_key = trim($get_gateway_details["secret_key"]);

			if (!empty($monnify_public_key) && !empty($monnify_secret_key)) {

				$curl_url = "https://api.monnify.com/api/v1/auth/login";
				$curl_request = curl_init($curl_url);
				curl_setopt($curl_request, CURLOPT_POST, true);

				// $post_field_array = array("username" => $username, "password" => $password);
				// curl_setopt($curl_request, CURLOPT_POSTFIELDS, json_encode($post_field_array, true));
				curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

				$header_field_array = array("Authorization: Basic " . base64_encode($monnify_public_key . ":" . $monnify_secret_key), "Content-Type: application/json", "Content-Length: 0");
				curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
				curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

				curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
				curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
				$curl_result = curl_exec($curl_request);
				$curl_json_result = json_decode($curl_result, true);

				if ($curl_json_result !== null) {
					if (($curl_json_result["responseMessage"] == "success") && isset($curl_json_result["responseBody"]["accessToken"]) && !empty($curl_json_result["responseBody"]["accessToken"])) {
						$accessToken = $curl_json_result["responseBody"]["accessToken"];
						$_SESSION["monnify_vendor_user_token"] = $accessToken;
						$_SESSION["monnify_vendor_user_token_expiry"] = time() + ($curl_json_result["responseBody"]["expiresIn"] ?? 3600) - 60;
						$monnify_json_response_array = array("status" => "success", "message" => "Token Generated", "token" => $accessToken);
						return json_encode($monnify_json_response_array, true);
					} else {
						$monnify_json_response_array = array("status" => "failed", "message" => "No Access Token");
						return json_encode($monnify_json_response_array, true);
					}
				}

				if ($curl_result === false) {
					$curl_error = curl_error($curl_request);
					$monnify_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
					return json_encode($monnify_json_response_array, true);
				}

				if ($curl_json_result === null) {
					$curl_error = curl_error($curl_request);
					$monnify_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
					return json_encode($monnify_json_response_array, true);
				}

				curl_close($curl_request);
			} else {
				$monnify_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
				return json_encode($monnify_json_response_array, true);
			}
		} else {
			$monnify_json_response_array = array("status" => "failed", "message" => "Monnify Is Down/Disabled");
			return json_encode($monnify_json_response_array, true);
		}
	} else {
		$monnify_json_response_array = array("status" => "failed", "message" => "Monnify Has Not Been Installed");
		return json_encode($monnify_json_response_array, true);
	}
}

//Vendor Token
function getVendorMonnifyAccessToken()
{
	if (isset($_SESSION["monnify_vendor_token"]) && isset($_SESSION["monnify_vendor_token_expiry"]) && time() < $_SESSION["monnify_vendor_token_expiry"]) {
		return json_encode(["status" => "success", "message" => "Token from Cache", "token" => $_SESSION["monnify_vendor_token"]], true);
	}

	global $connection_server, $get_logged_admin_details;
    $select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && gateway_name='monnify'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);
		if ($get_gateway_details["status"] == "1") {
			$monnify_public_key = trim($get_gateway_details["public_key"]);
			$monnify_secret_key = trim($get_gateway_details["secret_key"]);

			if (!empty($monnify_public_key) && !empty($monnify_secret_key)) {

				$curl_url = "https://api.monnify.com/api/v1/auth/login";
				$curl_request = curl_init($curl_url);
				curl_setopt($curl_request, CURLOPT_POST, true);

				// $post_field_array = array("username" => $username, "password" => $password);
				// curl_setopt($curl_request, CURLOPT_POSTFIELDS, json_encode($post_field_array, true));
				curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

				$header_field_array = array("Authorization: Basic " . base64_encode($monnify_public_key . ":" . $monnify_secret_key), "Content-Type: application/json", "Content-Length: 0");
				curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
				curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

				curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
				curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
				$curl_result = curl_exec($curl_request);
				$curl_json_result = json_decode($curl_result, true);

				if ($curl_json_result !== null) {
					if (($curl_json_result["responseMessage"] == "success") && isset($curl_json_result["responseBody"]["accessToken"]) && !empty($curl_json_result["responseBody"]["accessToken"])) {
						$accessToken = $curl_json_result["responseBody"]["accessToken"];
						$_SESSION["monnify_vendor_token"] = $accessToken;
						$_SESSION["monnify_vendor_token_expiry"] = time() + ($curl_json_result["responseBody"]["expiresIn"] ?? 3600) - 60;
						$monnify_json_response_array = array("status" => "success", "message" => "Token Generated", "token" => $accessToken);
						return json_encode($monnify_json_response_array, true);
					} else {
						$monnify_json_response_array = array("status" => "failed", "message" => "No Access Token");
						return json_encode($monnify_json_response_array, true);
					}
				}

				if ($curl_result === false) {
					$curl_error = curl_error($curl_request);
					$monnify_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
					return json_encode($monnify_json_response_array, true);
				}

				if ($curl_json_result === null) {
					$curl_error = curl_error($curl_request);
					$monnify_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
					return json_encode($monnify_json_response_array, true);
				}

				curl_close($curl_request);
			} else {
				$monnify_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
				return json_encode($monnify_json_response_array, true);
			}
		} else {
			$monnify_json_response_array = array("status" => "failed", "message" => "Monnify Is Down/Disabled");
			return json_encode($monnify_json_response_array, true);
		}
	} else {
		$monnify_json_response_array = array("status" => "failed", "message" => "Monnify Has Not Been Installed");
		return json_encode($monnify_json_response_array, true);
	}
}

//Super Admin Token
function getSuperAdminMonnifyAccessToken()
{
	if (isset($_SESSION["monnify_super_admin_token"]) && isset($_SESSION["monnify_super_admin_token_expiry"]) && time() < $_SESSION["monnify_super_admin_token_expiry"]) {
		return json_encode(["status" => "success", "message" => "Token from Cache", "token" => $_SESSION["monnify_super_admin_token"]], true);
	}

	global $connection_server;
	$select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='monnify'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);

		$monnify_public_key = trim($get_gateway_details["public_key"]);
		$monnify_secret_key = trim($get_gateway_details["secret_key"]);

		if (!empty($monnify_public_key) && !empty($monnify_secret_key)) {

			$curl_url = "https://api.monnify.com/api/v1/auth/login";
			$curl_request = curl_init($curl_url);
			curl_setopt($curl_request, CURLOPT_POST, true);

			// $post_field_array = array("username" => $username, "password" => $password);
			// curl_setopt($curl_request, CURLOPT_POSTFIELDS, json_encode($post_field_array, true));
			curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

			$header_field_array = array("Authorization: Basic " . base64_encode($monnify_public_key . ":" . $monnify_secret_key), "Content-Type: application/json", "Content-Length: 0");
			curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
			curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

			curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
			curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
			$curl_result = curl_exec($curl_request);
			$curl_json_result = json_decode($curl_result, true);

			if ($curl_json_result !== null) {
				if (($curl_json_result["responseMessage"] == "success") && isset($curl_json_result["responseBody"]["accessToken"]) && !empty($curl_json_result["responseBody"]["accessToken"])) {
					$accessToken = $curl_json_result["responseBody"]["accessToken"];
					$_SESSION["monnify_super_admin_token"] = $accessToken;
					$_SESSION["monnify_super_admin_token_expiry"] = time() + ($curl_json_result["responseBody"]["expiresIn"] ?? 3600) - 60;
					$monnify_json_response_array = array("status" => "success", "message" => "Token Generated", "token" => $accessToken);
					return json_encode($monnify_json_response_array, true);
				} else {
					$monnify_json_response_array = array("status" => "failed", "message" => "No Access Token");
					return json_encode($monnify_json_response_array, true);
				}
			}

			if ($curl_result === false) {
				$curl_error = curl_error($curl_request);
				$monnify_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
				return json_encode($monnify_json_response_array, true);
			}

			if ($curl_json_result === null) {
				$curl_error = curl_error($curl_request);
				$monnify_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
				return json_encode($monnify_json_response_array, true);
			}

			curl_close($curl_request);
		} else {
			$monnify_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
			return json_encode($monnify_json_response_array, true);
		}
	} else {
		$monnify_json_response_array = array("status" => "failed", "message" => "Monnify Has Not Been Installed");
		return json_encode($monnify_json_response_array, true);
	}
}

function getMonnifyBanks($generatedAccessToken)
{
	global $connection_server;

	$curl_url = "https://api.monnify.com/api/v1/banks";
	$curl_request = curl_init($curl_url);
	curl_setopt($curl_request, CURLOPT_HTTPGET, true);

	curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

	$header_field_array = array("Authorization: Bearer " . $generatedAccessToken, "Content-Type: application/json");
	curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
	curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

	curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
	$curl_result = curl_exec($curl_request);
	$curl_json_result = json_decode($curl_result, true);

	if ($curl_json_result !== null) {
		if (($curl_json_result["responseMessage"] == "success")) {
			$bankArrayList = $curl_json_result["responseBody"];
			$monnify_json_response_array = array("status" => "success", "message" => "Banks Generated", "banks" => $bankArrayList);
			return json_encode($monnify_json_response_array, true);
		} else {
			$monnify_json_response_array = array("status" => "failed", "message" => "No Banks");
			return json_encode($monnify_json_response_array, true);
		}
	}

	if ($curl_result === false) {
		$curl_error = curl_error($curl_request);
		$monnify_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
		return json_encode($monnify_json_response_array, true);
	}

	if ($curl_json_result === null) {
		$curl_error = curl_error($curl_request);
		$monnify_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
		return json_encode($monnify_json_response_array, true);
	}

	curl_close($curl_request);


}

function makeMonnifyRequest($req_method, $generatedAccessToken, $parameter_url, $req_body)
{
	global $connection_server;

	$curl_url = "https://api.monnify.com/" . $parameter_url;
	$curl_request = curl_init($curl_url);
	if ($req_method == "post") {
		curl_setopt($curl_request, CURLOPT_POST, true);
	} else {
		if ($req_method == "get") {
			curl_setopt($curl_request, CURLOPT_HTTPGET, true);
		}
	}

	if (is_array($req_body)) {
		$post_field_array = $req_body;
		curl_setopt($curl_request, CURLOPT_POSTFIELDS, json_encode($post_field_array, true));
	}
	curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

	$header_field_array = array("Authorization: Bearer " . $generatedAccessToken, "Content-Type: application/json");
	curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
	curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

	curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
	$curl_result = curl_exec($curl_request);
	$curl_json_result = json_decode($curl_result, true);

	if ($curl_json_result !== null) {
		if (($curl_json_result["responseMessage"] == "success")) {
			$encoded_json_result = json_encode($curl_json_result, true);
			$monnify_json_response_array = array("status" => "success", "message" => "Request Successful", "json_result" => $encoded_json_result);
			return json_encode($monnify_json_response_array, true);
		} else {
            $err_msg = $curl_json_result["responseMessage"] ?? "Request Failed";
            if(!empty($curl_json_result["responseBody"]["message"])) $err_msg .= ": " . $curl_json_result["responseBody"]["message"];
			$monnify_json_response_array = array("status" => "failed", "message" => $err_msg);
			return json_encode($monnify_json_response_array, true);
		}
	}

	if ($curl_result === false) {
		$curl_error = curl_error($curl_request);
		$monnify_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
		return json_encode($monnify_json_response_array, true);
	}

	if ($curl_json_result === null) {
		$curl_error = curl_error($curl_request);
		$monnify_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
		return json_encode($monnify_json_response_array, true);
	}

	curl_close($curl_request);


}

// ============================================================
// Identity Verification Provider Functions (BVN / NIN)
// ============================================================

/**
 * Returns the configured identity verification provider for a vendor.
 * Falls back to the global super admin option, then to 'monnify'.
 */
function getIdentityProvider($vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();

    // Session Cache
    if (isset($_SESSION['identity_provider_cache']) && isset($_SESSION['identity_provider_vid']) && $_SESSION['identity_provider_vid'] == $vid) {
        return $_SESSION['identity_provider_cache'];
    }

    $provider = '';
    if ($vid > 0) {
        $vid_esc = mysqli_real_escape_string($connection_server, $vid);
        $r = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT identity_provider FROM sas_vendors WHERE id='$vid_esc' LIMIT 1"));
        if ($r && !empty($r['identity_provider'])) $provider = $r['identity_provider'];
    }

    if (empty($provider)) {
        $provider = getSuperAdminOption('identity_provider', 'monnify');
        $vendor_monnify_gateway = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='monnify' AND status='1' LIMIT 1"));
        if ($vendor_monnify_gateway) {
            $provider = 'monnify';
        }
    }

    if (isset($_SESSION)) {
        $_SESSION['identity_provider_cache'] = $provider;
        $_SESSION['identity_provider_vid'] = $vid;
    }

    return $provider;
}

/**
 * Returns the configured identity verification API ID for a vendor.
 */
function getIdentityApiId($vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $r = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT identity_api_id FROM sas_vendors WHERE id='$vid_esc' LIMIT 1"));
    return $r['identity_api_id'] ?? 0;
}

/**
 * Fuzzy name matching: returns true if names share >= 70% similarity.
 */
function namesMatch($name1, $name2) {
    $clean = function($s) { return strtolower(preg_replace('/[^a-zA-Z]/', '', $s)); };
    $n1 = $clean($name1);
    $n2 = $clean($name2);
    if (empty($n1) || empty($n2)) return false;
    if ($n1 === $n2) return true;
    similar_text($n1, $n2, $percent);
    return $percent >= 70;
}

/**
 * Check if a returned full-name (from API) matches the expected first+last name.
 */
function fullNameMatchesExpected($returned_firstname, $returned_lastname, $expected_firstname, $expected_lastname) {
    $first_ok = namesMatch($returned_firstname, $expected_firstname);
    $last_ok  = namesMatch($returned_lastname,  $expected_lastname);
    if ($first_ok && $last_ok) return true;
    // Allow crossed first/last (some APIs return them swapped)
    if (namesMatch($returned_firstname, $expected_lastname) && namesMatch($returned_lastname, $expected_firstname)) return true;
    return false;
}

/**
 * Verify BVN or NIN using Dojah API.
 * public_key = App ID, secret_key = Private Key.
 * Returns array ["status"=>"success"|"failed", "message"=>..., "firstname"=>..., "lastname"=>..., "phone"=>...]
 */
function verifyBvnNinWithDojah($bvn_nin, $type, $vid) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='dojah' LIMIT 1"));
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        // Fallback to super-admin keys
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='dojah' LIMIT 1"));
    }
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        return ["status" => "failed", "message" => "Dojah API keys not configured"];
    }
    $app_id  = trim($gw['public_key']);
    $priv_key = trim($gw['secret_key']);

    $endpoint = ($type === 'bvn') ? "/api/v1/kyc/bvn?bvn=$bvn_nin" : "/api/v1/kyc/nin?nin=$bvn_nin";
    $ch = curl_init("https://api.dojah.io" . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["AppId: $app_id", "Authorization: $priv_key", "Content-Type: application/json"],
    ]);
    $res  = curl_exec($ch);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch);

    if (!$body || empty($body['entity'])) {
        $err = isset($body['error']) ? $body['error'] : "No response from Dojah";
        return ["status" => "failed", "message" => $err];
    }
    $e = $body['entity'];
    if ($type === 'bvn') {
        return ["status" => "success", "firstname" => $e['firstName'] ?? '', "lastname" => $e['lastName'] ?? '', "phone" => $e['phoneNumber1'] ?? $e['phoneNumber'] ?? ''];
    } else {
        return ["status" => "success", "firstname" => $e['firstname'] ?? $e['firstName'] ?? '', "lastname" => $e['surname'] ?? $e['lastName'] ?? '', "phone" => $e['telephone'] ?? $e['phone'] ?? ''];
    }
}

/**
 * Verify BVN or NIN using QoreID (formerly VerifyMe) API.
 * public_key = Client ID, secret_key = Client Secret.
 */
function verifyBvnNinWithQoreID($bvn_nin, $type, $vid) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='qoreid' LIMIT 1"));
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='qoreid' LIMIT 1"));
    }
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        return ["status" => "failed", "message" => "QoreID API keys not configured"];
    }
    $client_id = trim($gw['public_key']);
    $secret    = trim($gw['secret_key']);

    // Step 1: Get bearer token
    $ch = curl_init("https://api.qoreid.com/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(["clientId" => $client_id, "secret" => $secret]),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    ]);
    $tok_res  = curl_exec($ch);
    $tok_body = $tok_res ? json_decode($tok_res, true) : null;
    curl_close($ch);

    $token = $tok_body['accessToken'] ?? null;
    if (empty($token)) {
        return ["status" => "failed", "message" => "QoreID: failed to obtain access token"];
    }

    // Step 2: Lookup
    $endpoint = ($type === 'bvn') ? "/v1/ng/identities/bvn/$bvn_nin" : "/v1/ng/identities/nin/$bvn_nin";
    $ch2 = curl_init("https://api.qoreid.com" . $endpoint);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
    ]);
    $res  = curl_exec($ch2);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch2);

    if (!$body || empty($body['applicant'])) {
        $err = isset($body['message']) ? $body['message'] : "No response from QoreID";
        return ["status" => "failed", "message" => $err];
    }
    $a = $body['applicant'];
    return ["status" => "success", "firstname" => $a['firstname'] ?? '', "lastname" => $a['lastname'] ?? '', "phone" => $a['phone'] ?? ''];
}

/**
 * Verify BVN or NIN using Smile Identity API.
 * public_key = Partner ID, secret_key = API Key.
 */
function verifyBvnNinWithSmileID($bvn_nin, $type, $vid) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='smileid' LIMIT 1"));
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='smileid' LIMIT 1"));
    }
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        return ["status" => "failed", "message" => "Smile ID API keys not configured"];
    }
    $partner_id = trim($gw['public_key']);
    $api_key    = trim($gw['secret_key']);

    $timestamp = date('Y-m-d\TH:i:s\Z');
    $signature = base64_encode(hash_hmac('sha256', $timestamp . $partner_id . "sid_request", $api_key, true));
    $id_type   = ($type === 'bvn') ? 'BVN' : 'NIN';

    $payload = [
        "country"    => "NG",
        "id_type"    => $id_type,
        "id_number"  => $bvn_nin,
        "partner_id" => $partner_id,
        "timestamp"  => $timestamp,
        "signature"  => $signature,
    ];
    $ch = curl_init("https://api.smileidentity.com/v1/id_verification");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    ]);
    $res  = curl_exec($ch);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch);

    if (!$body || ($body['ResultCode'] ?? '') === '1012') {
        $err = $body['ResultText'] ?? "No response from Smile ID";
        return ["status" => "failed", "message" => $err];
    }
    if (($body['Actions']['Return_Personal_Info'] ?? '') !== 'Returned') {
        return ["status" => "failed", "message" => $body['ResultText'] ?? "Smile ID verification failed"];
    }
    return [
        "status"    => "success",
        "firstname" => $body['FullData']['FirstName']  ?? $body['FirstName']  ?? '',
        "lastname"  => $body['FullData']['LastName']   ?? $body['LastName']   ?? '',
        "phone"     => $body['FullData']['PhoneNumber'] ?? '',
    ];
}

/**
 * Main BVN/NIN verification dispatcher.
 * Selects provider based on vendor config, verifies the BVN/NIN, performs name matching,
 * and returns ["status"=>"success"|"failed", "message"=>..., "firstname"=>..., "lastname"=>..., "phone"=>...]
 *
 * @param string $bvn_nin     11-digit BVN or NIN
 * @param string $type        'bvn' or 'nin'
 * @param string $bank_code   Bank code (only required for Monnify BVN)
 * @param string $account_number  Account number (only required for Monnify BVN)
 * @param string $expected_firstname  First name to match against
 * @param string $expected_lastname   Last name to match against
 * @param int    $vid         Vendor ID
 * @param bool   $use_vendor_token  Whether to use vendor-level Monnify token (true=vendor admin, false=super admin)
 */
function verifyBvnNin($bvn_nin, $type, $bank_code, $account_number, $expected_firstname, $expected_lastname, $vid, $use_vendor_token = true) {
    global $connection_server;
    $provider = getIdentityProvider($vid);

    if ($provider === 'dojah') {
        $result = verifyBvnNinWithDojah($bvn_nin, $type, $vid);
    } elseif ($provider === 'qoreid') {
        $result = verifyBvnNinWithQoreID($bvn_nin, $type, $vid);
    } elseif ($provider === 'smileid') {
        $result = verifyBvnNinWithSmileID($bvn_nin, $type, $vid);
    } else {
        // Default: Monnify
        $result = verifyBvnNinWithMonnify($bvn_nin, $type, $bank_code, $account_number, $vid, $use_vendor_token);
    }

    if ($result['status'] !== 'success') return $result;

    // Name matching
    if (!empty($expected_firstname) && !empty($expected_lastname)) {
        if (!fullNameMatchesExpected($result['firstname'], $result['lastname'], $expected_firstname, $expected_lastname)) {
            return ["status" => "failed", "message" => "Identity verification failed: provided name does not match records. Expected: " . ucwords(strtolower($expected_firstname)) . " " . ucwords(strtolower($expected_lastname)) . ", Got: " . ucwords(strtolower($result['firstname'])) . " " . ucwords(strtolower($result['lastname']))];
        }
    }

    return $result;
}

/**
 * Monnify BVN/NIN verification (extracted for use by dispatcher).
 */
function verifyBvnNinWithMonnify($bvn_nin, $type, $bank_code, $account_number, $vid, $use_vendor_token = true) {
    global $connection_server, $get_logged_user_details, $get_logged_admin_details;
    if ($use_vendor_token) {
        if (isset($get_logged_admin_details["id"])) {
            $token_result = json_decode(getVendorUserMonnifyAccessToken(), true);
        } else {
            $token_result = json_decode(getVendorMonnifyAccessToken(), true);
        }
    } else {
        $token_result = json_decode(getUserMonnifyAccessToken(), true);
    }

    if ($token_result["status"] !== "success") {
        return ["status" => "failed", "message" => $token_result["message"] ?? "Monnify token error"];
    }
    $token = $token_result["token"];

    $mobileNo = preg_replace("/[^0-9]/", "", $get_logged_user_details["phone"] ?? "");
    if (empty($mobileNo)) $mobileNo = "08000000000";

    if ($type === "bvn") {
        // Validate account number first
        $nuban_res_raw = makeMonnifyRequest("get", $token, "api/v1/disbursements/account/validate?accountNumber=" . $account_number . "&bankCode=" . $bank_code, "");
        $nuban_res = json_decode($nuban_res_raw, true);
        if ($nuban_res["status"] !== "success") {
            return ["status" => "failed", "message" => "Invalid bank account number: " . ($nuban_res["message"] ?? "Unknown error")];
        }

        $res_raw = makeMonnifyRequest("post", $token, "api/v1/vas/bvn-account-match", ["bankCode" => $bank_code, "accountNumber" => $account_number, "bvn" => $bvn_nin, "mobileNo" => $mobileNo]);
        $res = json_decode($res_raw, true);
        if ($res["status"] !== "success") {
            return ["status" => "failed", "message" => "BVN and account number do not match: " . ($res["message"] ?? "Unknown error")];
        }

        $res_data = json_decode($res["json_result"], true);
        $body = $res_data["responseBody"] ?? [];
        $firstname = $body["firstName"] ?? $body["firstname"] ?? "";
        $lastname = $body["lastName"] ?? $body["surname"] ?? "";

        return ["status" => "success", "firstname" => $firstname, "lastname" => $lastname, "phone" => "", "bank_validated" => true];
    } else {
        $res_raw = makeMonnifyRequest("post", $token, "api/v1/vas/nin-details", ["nin" => $bvn_nin, "mobileNo" => $mobileNo]);
        $res = json_decode($res_raw, true);
        if ($res["status"] !== "success") {
            return ["status" => "failed", "message" => "NIN lookup failed: " . ($res["message"] ?? "Unknown error")];
        }
        $nin_data = json_decode($res["json_result"], true);
        $body = $nin_data["responseBody"] ?? [];
        $phone = $body["mobileNumber"] ?? $body["phone"] ?? "";
        $phone_len = strlen($phone);
        if ($phone_len >= 10) $phone = "0" . substr($phone, $phone_len - 10, 10);
        return ["status" => "success", "firstname" => $body["firstname"] ?? $body["firstName"] ?? "", "lastname" => $body["surname"] ?? $body["lastName"] ?? "", "phone" => $phone];
    }
}

/**
 * Fetch full NIN profile (name, DOB, gender, photo, address, etc.) for NIN Card/Slip generation.
 * Supports Dojah and QoreID. Falls back to basic info from Monnify.
 *
 * @param string $nin  11-digit NIN number
 * @param int    $vid  Vendor ID
 * @return array  ["status"=>"success"|"failed", "message"=>..., "firstname"=>..., "middlename"=>...,
 *                 "lastname"=>..., "birthdate"=>..., "gender"=>..., "photo_data"=>...(base64),
 *                 "phone"=>..., "address"=>..., "residence_state"=>..., "state_of_origin"=>..., "provider"=>...]
 */
function fetchNINProfile($nin, $vid) {
    global $connection_server;
    $provider = getIdentityProvider($vid);

    if ($provider === "dojah") {
        return fetchNINProfileWithDojah($nin, $vid);
    } elseif ($provider === "qoreid") {
        return fetchNINProfileWithQoreID($nin, $vid);
    } elseif ($provider === "localhost") {
        return fetchNINProfileWithLocalhost($nin, $vid);
    } else {
        return fetchNINProfileWithMonnify($nin, $vid);
    }
}

/**
 * Fetch NIN profile from a Local Marketplace API provider.
 */
function fetchNINProfileWithLocalhost($nin, $vid) {
    global $connection_server;
    $api_id = getIdentityApiId($vid);
    $api_details = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE id='$api_id' AND vendor_id='$vid' LIMIT 1"));
    
    if (!$api_details) {
        return ["status" => "failed", "message" => "Local API provider not configured or not found"];
    }

    $api_url = "https://" . $api_details['api_base_url'] . "/api/nin-card.php";
    $api_key = $api_details['api_key'];

    $payload = json_encode(["api_key" => $api_key, "nin" => $nin]);

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    ]);
    $res = curl_exec($ch);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch);

    if (!$body || ($body['status'] ?? '') !== 'success') {
        return ["status" => "failed", "message" => $body['desc'] ?? "Failed to connect to local API"];
    }

    // Map the standard DGV API response back to our internal profile format
    return [
        "status"          => "success",
        "provider"        => "localhost:" . $api_details['api_base_url'],
        "firstname"       => $body['firstname'] ?? '',
        "middlename"      => $body['middlename'] ?? '',
        "lastname"        => $body['lastname'] ?? '',
        "birthdate"       => $body['birthdate'] ?? '',
        "gender"          => $body['gender'] ?? '',
        "photo_data"      => $body['photo_data'] ?? '',
        "phone"           => $body['phone'] ?? '',
        "address"         => $body['address'] ?? '',
        "residence_state" => $body['residence_state'] ?? '',
        "state_of_origin" => $body['state_of_origin'] ?? '',
    ];
}

/**
 * Fetch full NIN profile from Dojah's /api/v1/kyc/nin endpoint.
 */
function fetchNINProfileWithDojah($nin, $vid) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='dojah' LIMIT 1"));
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='dojah' LIMIT 1"));
    }
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        return ["status" => "failed", "message" => "Dojah API keys not configured"];
    }

    $app_id   = trim($gw['public_key']);
    $priv_key = trim($gw['secret_key']);

    $ch = curl_init("https://api.dojah.io/api/v1/kyc/nin?nin=" . urlencode($nin));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["AppId: $app_id", "Authorization: $priv_key", "Content-Type: application/json"],
    ]);
    $res  = curl_exec($ch);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch);

    if (!$body || empty($body['entity'])) {
        $err = isset($body['error']) ? $body['error'] : "No response from Dojah";
        return ["status" => "failed", "message" => $err];
    }
    $e = $body['entity'];

    $gender_raw = strtolower($e['gender'] ?? '');
    $gender = ($gender_raw === 'm' || $gender_raw === 'male') ? 'Male' : (($gender_raw === 'f' || $gender_raw === 'female') ? 'Female' : ucfirst($gender_raw));

    $address_parts = array_filter([
        $e['residence_address'] ?? '',
        $e['residence_town'] ?? '',
        $e['residence_lga'] ?? '',
        $e['residence_state'] ?? '',
    ]);
    $address = implode(', ', $address_parts);

    return [
        "status"          => "success",
        "provider"        => "dojah",
        "firstname"       => strtoupper($e['firstname'] ?? ''),
        "middlename"      => strtoupper($e['middlename'] ?? ''),
        "lastname"        => strtoupper($e['surname'] ?? $e['lastName'] ?? ''),
        "birthdate"       => $e['birthdate'] ?? '',
        "gender"          => $gender,
        "photo_data"      => $e['photo'] ?? '',
        "phone"           => $e['telephone'] ?? $e['phone'] ?? '',
        "address"         => $address,
        "residence_state" => $e['residence_state'] ?? '',
        "state_of_origin" => $e['state_of_origin'] ?? '',
    ];
}

/**
 * Fetch full NIN profile from QoreID's /v1/ng/identities/nin/{nin} endpoint.
 */
function fetchNINProfileWithQoreID($nin, $vid) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='qoreid' LIMIT 1"));
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='qoreid' LIMIT 1"));
    }
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        return ["status" => "failed", "message" => "QoreID API keys not configured"];
    }

    $client_id = trim($gw['public_key']);
    $secret    = trim($gw['secret_key']);

    // Get bearer token
    $ch = curl_init("https://api.qoreid.com/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(["clientId" => $client_id, "secret" => $secret]),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    ]);
    $tok_res  = curl_exec($ch);
    $tok_body = $tok_res ? json_decode($tok_res, true) : null;
    curl_close($ch);

    $token = $tok_body['accessToken'] ?? null;
    if (empty($token)) {
        return ["status" => "failed", "message" => "QoreID: failed to obtain access token"];
    }

    $ch2 = curl_init("https://api.qoreid.com/v1/ng/identities/nin/" . urlencode($nin));
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
    ]);
    $res  = curl_exec($ch2);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch2);

    if (!$body || empty($body['applicant'])) {
        $err = isset($body['message']) ? $body['message'] : "No response from QoreID";
        return ["status" => "failed", "message" => $err];
    }
    $a = $body['applicant'];

    $gender_raw = strtolower($a['gender'] ?? '');
    $gender = ($gender_raw === 'm' || $gender_raw === 'male') ? 'Male' : (($gender_raw === 'f' || $gender_raw === 'female') ? 'Female' : ucfirst($gender_raw));

    return [
        "status"          => "success",
        "provider"        => "qoreid",
        "firstname"       => strtoupper($a['firstname'] ?? ''),
        "middlename"      => strtoupper($a['middlename'] ?? ''),
        "lastname"        => strtoupper($a['lastname'] ?? ''),
        "birthdate"       => $a['dob'] ?? $a['birthdate'] ?? '',
        "gender"          => $gender,
        "photo_data"      => $a['photo'] ?? '',
        "phone"           => $a['phone'] ?? '',
        "address"         => $a['address'] ?? '',
        "residence_state" => $a['residence_state'] ?? $a['state'] ?? '',
        "state_of_origin" => $a['state_of_origin'] ?? '',
    ];
}


//User Token
function getUserPayvesselAccessToken()
{
	global $connection_server, $get_logged_user_details;
	$select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && gateway_name='payvessel'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);
		if ($get_gateway_details["status"] == "1") {
			$payvessel_public_key = trim($get_gateway_details["public_key"]);
			$payvessel_secret_key = trim($get_gateway_details["secret_key"]);
			$payvessel_encrypt_key = trim($get_gateway_details["encrypt_key"]);

			if (!empty($payvessel_public_key) && !empty($payvessel_secret_key)) {
				$accessToken = base64_encode($payvessel_public_key . ":" . $payvessel_secret_key);
				$payvessel_json_response_array = array("status" => "success", "message" => "Token Generated", "encrypt_key" => $payvessel_encrypt_key, "token" => $accessToken);
				return json_encode($payvessel_json_response_array, true);
			} else {
				$payvessel_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
				return json_encode($payvessel_json_response_array, true);
			}
		} else {
			$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Is Down/Disabled");
			return json_encode($payvessel_json_response_array, true);
		}
	} else {
		$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Has Not Been Installed");
		return json_encode($payvessel_json_response_array, true);
	}
}

//Vendor User Token
function getVendorUserPayvesselAccessToken()
{
	global $connection_server, $get_logged_admin_details;
	$select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && gateway_name='payvessel'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);
		if ($get_gateway_details["status"] == "1") {
			$payvessel_public_key = trim($get_gateway_details["public_key"]);
			$payvessel_secret_key = trim($get_gateway_details["secret_key"]);
			$payvessel_encrypt_key = trim($get_gateway_details["encrypt_key"]);

			if (!empty($payvessel_public_key) && !empty($payvessel_secret_key)) {
				$accessToken = base64_encode($payvessel_public_key . ":" . $payvessel_secret_key);
				$payvessel_json_response_array = array("status" => "success", "message" => "Token Generated", "encrypt_key" => $payvessel_encrypt_key, "token" => $accessToken);
				return json_encode($payvessel_json_response_array, true);
			} else {
				$payvessel_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
				return json_encode($payvessel_json_response_array, true);
			}
		} else {
			$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Is Down/Disabled");
			return json_encode($payvessel_json_response_array, true);
		}
	} else {
		$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Has Not Been Installed");
		return json_encode($payvessel_json_response_array, true);
	}
}

//Vendor Token
function getVendorPayvesselAccessToken()
{
	global $connection_server, $get_logged_admin_details;
	$select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='payvessel'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);
		if ($get_gateway_details["status"] == "1") {
			$payvessel_public_key = trim($get_gateway_details["public_key"]);
			$payvessel_secret_key = trim($get_gateway_details["secret_key"]);
			$payvessel_encrypt_key = trim($get_gateway_details["encrypt_key"]);

			if (!empty($payvessel_public_key) && !empty($payvessel_secret_key)) {
				$accessToken = base64_encode($payvessel_public_key . ":" . $payvessel_secret_key);
				$payvessel_json_response_array = array("status" => "success", "message" => "Token Generated", "encrypt_key" => $payvessel_encrypt_key, "token" => $accessToken);
				return json_encode($payvessel_json_response_array, true);
			} else {
				$payvessel_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
				return json_encode($payvessel_json_response_array, true);
			}
		} else {
			$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Is Down/Disabled");
			return json_encode($payvessel_json_response_array, true);
		}
	} else {
		$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Has Not Been Installed");
		return json_encode($payvessel_json_response_array, true);
	}
}

//Super Admin Token
function getSuperAdminPayvesselAccessToken()
{
	global $connection_server;
	$select_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='payvessel'");

	if ((mysqli_num_rows($select_gateway_details) == 1)) {
		$get_gateway_details = mysqli_fetch_array($select_gateway_details);
		if ($get_gateway_details["status"] == "1") {
			$payvessel_public_key = trim($get_gateway_details["public_key"]);
			$payvessel_secret_key = trim($get_gateway_details["secret_key"]);
			$payvessel_encrypt_key = trim($get_gateway_details["encrypt_key"]);

			if (!empty($payvessel_public_key) && !empty($payvessel_secret_key)) {
				$accessToken = base64_encode($payvessel_public_key . ":" . $payvessel_secret_key);
				$payvessel_json_response_array = array("status" => "success", "message" => "Token Generated", "encrypt_key" => $payvessel_encrypt_key, "token" => $accessToken);
				return json_encode($payvessel_json_response_array, true);
			} else {
				$payvessel_json_response_array = array("status" => "failed", "message" => "Required Keys Are Empty");
				return json_encode($payvessel_json_response_array, true);
			}
		} else {
			$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Is Down/Disabled");
			return json_encode($payvessel_json_response_array, true);
		}
	} else {
		$payvessel_json_response_array = array("status" => "failed", "message" => "Payvessel Has Not Been Installed");
		return json_encode($payvessel_json_response_array, true);
	}
}

function makePayvesselRequest($req_method, $generatedAccessToken, $parameter_url, $req_body)
{
	global $connection_server;
	$key_explode = array_filter(explode(":", trim(base64_decode($generatedAccessToken))));
	$curl_url = "https://api.payvessel.com/" . $parameter_url;
	$curl_request = curl_init($curl_url);
	if ($req_method == "post") {
		curl_setopt($curl_request, CURLOPT_POST, true);
	} else {
		if ($req_method == "get") {
			curl_setopt($curl_request, CURLOPT_HTTPGET, true);
		}
	}

	if (is_array($req_body)) {
		$post_field_array = $req_body;
		curl_setopt($curl_request, CURLOPT_POSTFIELDS, json_encode($post_field_array, true));
	}
	curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

	$header_field_array = array("api-key: " . $key_explode[0], "api-secret: Bearer " . $key_explode[1], "Content-Type: application/json");
	curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
	curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

	curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
	$curl_result = curl_exec($curl_request);
	$curl_json_result = json_decode($curl_result, true);

	if ($curl_json_result !== null) {
		if (($curl_json_result["status"] === true)) {
			$encoded_json_result = json_encode($curl_json_result, true);
			$payvessel_json_response_array = array("status" => "success", "message" => "Request Successful", "json_result" => $encoded_json_result);
			return json_encode($payvessel_json_response_array, true);
		} else {
            $err_msg = $curl_json_result["message"] ?? ($curl_json_result["error"] ?? "Request Failed");
			$payvessel_json_response_array = array("status" => "failed", "message" => $err_msg);
			return json_encode($payvessel_json_response_array, true);
		}
	}

	if ($curl_result === false) {
		$curl_error = curl_error($curl_request);
		$payvessel_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
		return json_encode($payvessel_json_response_array, true);
	}

	if ($curl_json_result === null) {
		$curl_error = curl_error($curl_request);
		$payvessel_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
		return json_encode($payvessel_json_response_array, true);
	}

	curl_close($curl_request);


}

function makeBeewaveRequest($req_method, $generatedAccessToken, $parameter_url, $req_body)
{
	global $connection_server;
	// $key_explode = array_filter(explode(":", trim(base64_decode($generatedAccessToken))));
	$curl_url = "https://merchant.beewave.ng/" . $parameter_url;
	$curl_request = curl_init($curl_url);
	if ($req_method == "post") {
		curl_setopt($curl_request, CURLOPT_POST, true);
	} else {
		if ($req_method == "get") {
			curl_setopt($curl_request, CURLOPT_HTTPGET, true);
		}
	}

	if (is_array($req_body)) {
		$post_field_array = $req_body;
		curl_setopt($curl_request, CURLOPT_POSTFIELDS, json_encode($post_field_array, true));
	}
	curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);

	$header_field_array = array("Accept: application/json", "Content-Type: application/json");
	curl_setopt($curl_request, CURLOPT_HTTPHEADER, $header_field_array);
	curl_setopt($curl_request, CURLOPT_TIMEOUT, 60);

	curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, true);
	$curl_result = curl_exec($curl_request);
	$curl_json_result = json_decode($curl_result, true);

	if ($curl_json_result !== null) {
		if (($curl_json_result["status"] === true)) {
			$encoded_json_result = json_encode($curl_json_result, true);
			$beewave_json_response_array = array("status" => "success", "message" => "Request Successful", "json_result" => $encoded_json_result);
			return json_encode($beewave_json_response_array, true);
		} else {
			$beewave_json_response_array = array("status" => "failed", "message" => "Request Failed");
			return json_encode($beewave_json_response_array, true);
		}
	}

	if ($curl_result === false) {
		$curl_error = curl_error($curl_request);
		$beewave_json_response_array = array("status" => "failed", "message" => "Error: " . $curl_error);
		return json_encode($beewave_json_response_array, true);
	}

	if ($curl_json_result === null) {
		$curl_error = curl_error($curl_request);
		$beewave_json_response_array = array("status" => "failed", "message" => "Null Error: " . $curl_error);
		return json_encode($beewave_json_response_array, true);
	}

	curl_close($curl_request);

}




function makePaystackRequest($req_method, $parameter_url, $req_body, $vid = null, $is_super = null, $is_withdrawal = false)
{
    global $connection_server, $get_logged_user_details, $get_logged_admin_details;

    if ($vid === null) {
        $vid = $get_logged_user_details["vendor_id"] ?? $get_logged_admin_details["id"] ?? 0;
    }

    if ($is_super === null) {
        $is_super = isset($_SESSION['spadmin_session']);
    }

    if ($is_super) {
        $get_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='paystack' LIMIT 1"));
        if($get_gateway_details) $get_gateway_details['source_table'] = 'sas_super_admin_payment_gateways';
    } else {
        if ($is_withdrawal) {
            $get_gateway_details = getWithdrawalGatewayDetails('paystack', $vid);
        } else {
            $get_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid' && gateway_name='paystack'"));
            if($get_gateway_details) $get_gateway_details['source_table'] = 'sas_payment_gateways';
        }
    }

    if (!$get_gateway_details) return json_encode(["status" => "failed", "message" => "Paystack not configured"]);

    $source_tag = $get_gateway_details['source_table'] ?? "unknown";
    $paystack_secret_key = isset($get_gateway_details["secret_key"]) ? $get_gateway_details["secret_key"] : '';
    $paystack_secret_key = trim($paystack_secret_key);
    $paystack_secret_key = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $paystack_secret_key); // Remove non-ASCII/hidden characters
    $paystack_secret_key = str_replace(array("\r", "\n", " ", "\t"), '', $paystack_secret_key); // Remove accidental spaces, tabs, newlines

    $log_entry = "================================================\n";
    $log_entry .= "[" . date('Y-m-d H:i:s') . "] PAYSTACK REQUEST\n";
    $log_entry .= "Method: $req_method | URL: $parameter_url\n";
    $log_entry .= "VID: $vid | Source: $source_tag | Withdrawal: " . ($is_withdrawal ? 'YES' : 'NO') . "\n";
    $masked_key = !empty($paystack_secret_key) ? substr($paystack_secret_key, 0, 8) . "..." : "MISSING";
    $log_entry .= "Using Key: $masked_key\n";
    $url = "https://api.paystack.co/" . ltrim($parameter_url, '/');

    $ch = curl_init($url);
    $req_method_up = strtoupper($req_method);
    if ($req_method_up == "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
    } else if ($req_method_up == "GET") {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $req_method_up);
    }

    if (!empty($req_body)) {
        $payload = is_array($req_body) ? json_encode($req_body) : $req_body;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $paystack_secret_key",
        "Content-Type: application/json",
        "Accept: application/json",
        "Cache-Control: no-cache",
        "User-Agent: Mozilla/5.0 (Platform API)"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);


    if ($http_code >= 200 && $http_code < 300) {
        $json = json_decode($result, true);
        if ($json && (isset($json['status']) && $json['status'] === true)) {
            return json_encode([
                "status" => "success",
                "message" => "Request Successful",
                "json_result" => $result
            ]);
        }
    }

    $msg = "HTTP $http_code - Paystack Error";
    if (!empty($result)) {
        $json_err = json_decode($result, true);
        $msg .= ": " . ($json_err['message'] ?? substr(strip_tags($result), 0, 100));
    }
    return json_encode(["status" => "failed", "message" => $msg]);
}

/**
 * Paystack Transfer Functions (Added in Branch DG6.7)
 */
function paystackResolveAccount($account_number, $bank_code, $vid = null) {
    $log = function($m) {};

    $code = getStandardBankCode($bank_code);
    // For resolution, we try standard code, full NIP, and also just the last 3 digits
    $last_3 = substr($bank_code, -3);
    $trimmed = ltrim($bank_code, '0');
    $try_codes = array_unique([$code, $bank_code, $trimmed, $last_3]);
    if (strlen($bank_code) == 10) $try_codes = array_unique([$code, $last_3, $trimmed]); // Fix for bank_code being account_number if mis-passed

    $log("Resolve: Acc=$account_number | Bank=$bank_code | VID=$vid | TryCodes=" . implode(',', $try_codes));

    $last_res = null;
    foreach ($try_codes as $code) {
        $log("Trying Code: $code");
        $res_json = makePaystackRequest("GET", "bank/resolve?account_number=$account_number&bank_code=$code", "", $vid, null, true);
        $res = json_decode($res_json, true);

        $log("Raw Response: " . $res_json);

        if (($res['status'] ?? '') == 'success') {
            $inner = json_decode($res['json_result'] ?? '{}', true);
            if (isset($inner['status']) && $inner['status'] === true) {
                // Robust account name extraction
                $account_name = extractNameFromResolutionResponse($inner);

                if (!empty($account_name)) {
                    $log("Success: Found Name=$account_name");
                    return [
                        'status' => 'success',
                        'account_name' => $account_name,
                        'data' => $inner['data'] ?? [],
                        'json_result' => $res['json_result'],
                        'mapped_bank_code' => $code
                    ];
                }
            }
            $last_res = $inner;
        } else {
            $last_res = $res;
        }
    }

    // If we reached here, it failed to find a name
    $log("Failed to resolve account");
    if (is_array($last_res)) {
        $last_res['status'] = 'failed';
        if (empty($last_res['message'])) $last_res['message'] = 'Account name not found';
    }
    return $last_res;
}

function paystackCreateTransferRecipient($name, $account_number, $bank_code, $vid = null) {
    $payload = [
        "type" => "nuban",
        "name" => $name,
        "account_number" => $account_number,
        "bank_code" => $bank_code,
        "currency" => "NGN"
    ];
    $res = makePaystackRequest("POST", "transferrecipient", $payload, $vid, null, true);
    return json_decode($res, true);
}

function paystackInitiateTransfer($amount_kobo, $recipient_code, $reason = "Wallet Withdrawal", $vid = null) {
    $payload = [
        "source" => "balance",
        "amount" => $amount_kobo,
        "recipient" => $recipient_code,
        "reason" => $reason
    ];
    $res = makePaystackRequest("POST", "transfer", $payload, $vid, null, true);
    return json_decode($res, true);
}

function paystackVerifyTransfer($reference, $vid = null) {
    $res = makePaystackRequest("GET", "transfer/verify/$reference", "", $vid, null, true);
    return json_decode($res, true);
}

function isGatewayEnabled($gateway, $vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();

    // Branch DG6.81: Check Service Control Center first
    if (!isServiceEnabled($gateway, $vid)) return false;

    $gateway_search = mysqli_real_escape_string($connection_server, strtolower(trim($gateway)));

    $restricted_gateways = ['plisio'];
    $is_restricted = in_array($gateway_search, $restricted_gateways);

    // Standalone: strictly check vendor 1 or local config
    if ($connection_server) {
        $q = mysqli_query($connection_server, "SELECT status FROM sas_payment_gateways WHERE vendor_id='1' AND (LOWER(TRIM(gateway_name)) = '$gateway_search' OR gateway_name LIKE '%$gateway_search%') LIMIT 1");
        if ($q && $r = mysqli_fetch_assoc($q)) {
            return ($r['status'] == 1);
        }
    }
    return false;
}

function getGatewayDetails($gateway, $vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();
    $gateway_search = mysqli_real_escape_string($connection_server, strtolower(trim($gateway)));

    $restricted_gateways = ['plisio'];
    $is_restricted = in_array($gateway_search, $restricted_gateways);

    $details = null;

    if ($connection_server) {
        $vid = (int)$vid;
        if ($vid <= 0) {
            $vid = 1;
        }
        // Row preference (why the ORDER BY matters): a vendor can end up with MORE THAN ONE row for
        // the same gateway (a duplicate left behind by re-seeding, or a blank row written by a settings
        // save). A bare LIMIT 1 let MySQL hand back the blank row, and the provider then answered
        // 401 "Invalid Secret Key" even though a correctly configured row existed. Prefer, in order:
        // an exact gateway_name match, then a row that actually carries a secret key, then the oldest.
        $q = mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid' AND (LOWER(TRIM(gateway_name)) = '$gateway_search' OR gateway_name LIKE '%$gateway_search%') ORDER BY (LOWER(TRIM(gateway_name)) = '$gateway_search') DESC, (secret_key IS NULL OR secret_key = '') ASC, date ASC LIMIT 1");
        if ($q && $r = mysqli_fetch_assoc($q)) {
            $details = $r;
            $details['source_table'] = 'sas_payment_gateways';
        }
    }

    return $details;
}

function getWithdrawalGatewayDetails($gateway, $vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();
    $gateway_search = mysqli_real_escape_string($connection_server, strtolower(trim($gateway)));

    $details = null;

    // 1. Check Vendor Isolated Withdrawal Table
    if ($vid > 0) {
        // ORDER BY matters here. This table was keyless for a while (no PRIMARY KEY on
        // (vendor_id, gateway_name)), so every save appended a duplicate row instead of updating.
        // A plain LIMIT 1 could then return an EMPTY-secret row, the guard below reads that as
        // "no credentials", and the lookup silently falls through to vendor 0 and then to the
        // funding keys - payouts fail with "Invalid Secret Key" while the admin's correctly saved
        // keys sit unread in the table. Preferring a row that actually holds a secret, newest
        // first, makes the pick deterministic.
        $q = mysqli_query($connection_server, "SELECT * FROM sas_bank_transfer_gateways WHERE vendor_id='$vid' AND (LOWER(TRIM(gateway_name)) = '$gateway_search' OR gateway_name LIKE '%$gateway_search%') ORDER BY (secret_key IS NULL OR secret_key = '') ASC, date DESC LIMIT 1");
        if ($q && $r = mysqli_fetch_assoc($q)) {
            if (!empty($r['secret_key'])) {
                $details = $r;
                $details['source_table'] = 'sas_bank_transfer_gateways';
            }
        }
    }

    // 1b. Fallback to Super Admin Isolated Withdrawal Table
    if (!$details || empty($details['secret_key'])) {
        $q = mysqli_query($connection_server, "SELECT * FROM sas_bank_transfer_gateways WHERE vendor_id='0' AND (LOWER(TRIM(gateway_name)) = '$gateway_search' OR gateway_name LIKE '%$gateway_search%') ORDER BY (secret_key IS NULL OR secret_key = '') ASC, date DESC LIMIT 1");
        if ($q && $r = mysqli_fetch_assoc($q)) {
            if (!empty($r['secret_key'])) {
                $details = $r;
                $details['source_table'] = 'sas_bank_transfer_gateways_sa';
            }
        }
    }

    // 2. Fallback to normal payment gateways if no isolated record exists
    if (!$details || empty($details['secret_key'])) {
        $details = getGatewayDetails($gateway, $vid);
    }

    return $details;
}

function makePayhubRequest($req_method, $parameter_url, $req_body, $vid = null, $is_super = null, $is_withdrawal = false)
{
    global $connection_server, $get_logged_user_details, $get_logged_admin_details;

    $debug_file = __DIR__ . "/../logs/payhub_api_debug.txt";

    if ($vid === null) {
        $vid = $get_logged_user_details["vendor_id"] ?? $get_logged_admin_details["id"] ?? resolveVendorID();
    }

    // If is_super is explicitly true, we target the platform keys (vid=0)
    $lookup_vid = ($is_super === true) ? 0 : $vid;

    if ($is_withdrawal) {
        $get_gateway_details = getWithdrawalGatewayDetails('payhub', $lookup_vid);
    } else {
        $get_gateway_details = getGatewayDetails('payhub', $lookup_vid);
    }

    $source_tag = $get_gateway_details['source_table'] ?? "sas_payment_gateways";
    $config_source = ($lookup_vid > 0) ? "Auto (Vendor $lookup_vid fallback to Platform via $source_tag)" : "Platform (Super Admin via $source_tag)";


    $req_method_up = strtoupper($req_method);

    $payhub_secret_key = isset($get_gateway_details["secret_key"]) ? $get_gateway_details["secret_key"] : '';
    $payhub_secret_key = trim($payhub_secret_key);
    $payhub_secret_key = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $payhub_secret_key); // Remove non-ASCII/hidden characters
    $payhub_secret_key = str_replace(array("\r", "\n", " ", "\t"), '', $payhub_secret_key); // Remove accidental spaces, tabs, newlines
    $final_param_url = ltrim($parameter_url, '/');

    // PayHub API Path Normalization (Branch DG6.7 Optimization)
    // Some endpoints require .php, some don't. We honor the input but ensure logic for missing extensions.
    if (strpos($final_param_url, '.php') === false && strpos($final_param_url, '?') === false) {
        // Known clean-URL endpoints
        $clean_endpoints = [
            'api/transaction/initialize',
            'api/transaction/verify',
            'api/transaction/reconcile',
            'api/payout/initialize',
            'api/payout/resolve',
            'api/payout/resolve-account',
            'api/resolve-account',
            'api/resolve_account',
            'api/resolve',
            'api/banks',
            'api/bank/resolve',
            'api/bank',
            'api/virtual-account/create',
            'api/virtual-accounts',
            'api/verify-account',
            'api/verify_account',
            'api/payout/verify-account',
            'api/v1',
            'api/v2',
            'api/' // Dynamic email endpoints like api/user@host are clean
        ];
        $is_clean = false;
        foreach($clean_endpoints as $ce) {
            if(strpos($final_param_url, $ce) === 0) {
                $is_clean = true;
                break;
            }
        }

        if (!$is_clean) {
            $final_param_url .= '.php';
        }
    }

    $base_url = getSuperAdminOption('payhub_base_url', 'https://merchant.payhub.com.ng/');
    $base_url = rtrim($base_url, '/') . '/';
    $final_param_url = ltrim($final_param_url, '/');
    $url = $base_url . $final_param_url;

    // Convert array body to query string for GET requests
    if ($req_method_up == "GET" && !empty($req_body)) {
        $query = is_array($req_body) ? http_build_query($req_body) : $req_body;
        $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
    }

    $log_entry = "================================================\n";
    $log_entry .= "[" . date('Y-m-d H:i:s') . "] PAYHUB REQUEST\n";
    $log_entry .= "Method: $req_method_up | URL: $url\n";
    $log_entry .= "VID: $vid | Source: $config_source\n";
    // Identify the key (prefix + last 4). Masking it to the first 8 characters alone is useless as a
    // diagnostic: every PayHub key starts with "sk_live_", so a rotated/blank/wrong key looked
    // identical to the correct one in this log.
    $masked_key = !empty($get_gateway_details['secret_key'])
        ? substr($get_gateway_details['secret_key'], 0, 8) . "..." . substr($get_gateway_details['secret_key'], -4)
        : "MISSING";
    $log_entry .= "Using Key: $masked_key | Isolated Payout Key: ".($is_withdrawal ? 'YES' : 'NO')."\n";
    $log_entry .= "Body: " . (is_array($req_body) ? json_encode($req_body) : $req_body) . "\n";

    if (!$get_gateway_details) {
        $log_entry .= "CRITICAL: No PayHub configuration found in database.\n";
        @file_put_contents($debug_file, $log_entry . "================================================\n", FILE_APPEND);
        return json_encode(["status" => "failed", "message" => "PayHub not configured"]);
    }

    // Fail fast on a missing secret key. Without this the call goes out as "Authorization: Bearer "
    // (empty value), PayHub answers 401 "Invalid Secret Key" and the person funding a wallet sees a
    // message that names no cause. A blank key means the gateway was never configured (or was saved
    // blank) for this vendor - say exactly that and point at the fix.
    if ($payhub_secret_key === '') {
        $log_entry .= "CRITICAL: PayHub secret key is EMPTY. vid={$lookup_vid} | source={$source_tag}. "
                    . "Enter the LIVE Secret Key (sk_live_...) from merchant.payhub.com.ng -> API Keys in Payment Gateway settings.\n";
        @file_put_contents($debug_file, $log_entry . "================================================\n", FILE_APPEND);
        return json_encode([
            "status" => "failed",
            "message" => "PayHub is not configured: the PayHub Secret Key is empty for this account. "
                       . "Copy the LIVE Secret Key from your PayHub dashboard (API Keys) into Payment Gateway settings."
        ]);
    }

    // A PayHub secret key always starts with sk_. If it does not, the most common cause is the PUBLIC
    // key (pk_live_...) having been pasted into the secret field - PayHub answers 401 for that too, so
    // leave a breadcrumb in the log instead of letting it look like the gateway is down.
    if (strpos($payhub_secret_key, 'sk_') !== 0) {
        $log_entry .= "WARNING: stored key does not look like a PayHub SECRET key (expected sk_live_.../sk_test_...). "
                    . "Prefix found: " . substr($payhub_secret_key, 0, 8) . "...\n";
    }

    $ch = curl_init($url);
    if ($req_method_up == "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
    } else if ($req_method_up == "GET") {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $req_method_up);
    }

    if ($req_method_up !== "GET" && !empty($req_body)) {
        // Switch to modern JSON default for PayHub
        $payload = is_array($req_body) ? json_encode($req_body) : $req_body;
        $content_type = "application/json";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    } else {
        $content_type = "application/json";
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    // Comprehensive headers for PayHub/Paystack proxy compatibility
    $headers = [
        "Authorization: Bearer " . $payhub_secret_key,
        "Content-Type: application/json",
        "Accept: application/json",
        "Cache-Control: no-cache",
        "User-Agent: Mozilla/5.0 (Platform API)"
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    // Record the outcome. Previously $log_entry was only written on the early-return paths, so a real
    // request that PayHub rejected (the 401 case) left NO trace and the outgoing key was invisible.
    $log_entry .= "HTTP: $http_code" . (!empty($err) ? " | cURL: $err" : "") . "\n";
    $log_entry .= "Response: " . substr(str_replace(["\r", "\n"], ' ', (string)$result), 0, 500) . "\n";
    @file_put_contents($debug_file, $log_entry . "================================================\n", FILE_APPEND);

    if ($http_code >= 200 && $http_code < 300) {
        $json = json_decode($result, true);
        $status = strtolower($json['status'] ?? "");
        if ($json && ($json['status'] === true || $status === 'success' || $status === 'successful' || ($json['status'] ?? null) === 200)) {
            return json_encode([
                "status" => "success",
                "message" => $json['message'] ?? "Request Successful",
                "json_result" => $result
            ]);
        }
    }

    $msg = "HTTP $http_code - PayHub Error";
    if (!empty($err)) $msg .= " (cURL Error: $err)";
    if (!empty($result)) {
        $json_err = json_decode($result, true);
        if($json_err && isset($json_err['message'])) $msg .= ": " . $json_err['message'];
        else $msg .= ": " . substr(strip_tags($result), 0, 100);
    }

    // PayHub rejects an unknown/inactive key with 401 "Invalid Secret Key". The stored key was sent
    // intact (it is logged above), so the only remaining cause is that the key itself is no longer
    // valid: it was regenerated in the PayHub dashboard, or the wrong key was pasted in.
    if ($http_code == 401) {
        $msg .= " - the PayHub Secret Key saved in Payment Gateway settings was rejected; "
              . "copy the current LIVE Secret Key from your PayHub dashboard (API Keys) and save it there.";
    }

    return json_encode(["status" => "failed", "message" => $msg]);
}

function processPayhubSuccess($vendor_id, $transaction_ref, $data, $payhub_keys, $username = "") {
    global $connection_server;
    $log = function($m) {};

    $log("Starting processing for ref: $transaction_ref | Initial VID: $vendor_id | Initial User: $username");

    // 1. Context Resolution & Metadata Fallback
    $meta = [];
    if (!empty($data['metadata'])) {
        $meta = is_array($data['metadata']) ? $data['metadata'] : json_decode($data['metadata'], true);
        if ($meta) {
            $log("Metadata found: " . json_encode($meta));
            if (empty($username) && ($meta['target'] ?? '') == 'user') $username = $meta['username'] ?? "";
            if (empty($vendor_id) || $vendor_id <= 0) $vendor_id = (int)($meta['vendor_id'] ?? 0);

            // Check for original reference in metadata or referrer
            if (!empty($meta['reference'])) {
                $orig_ref = $meta['reference'];
                $log("Original reference found in metadata: $orig_ref");

                // If current reference is different from metadata reference, try to find record by metadata reference
                if ($orig_ref !== $transaction_ref) {
                    $log("Cross-referencing metadata reference: $orig_ref");
                    $ref_esc = mysqli_real_escape_string($connection_server, $orig_ref);

                    // Try to resolve context from checkouts if still missing
                    if (empty($username) || $vendor_id <= 0) {
                         $q_c = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
                         if ($r_c = mysqli_fetch_assoc($q_c)) {
                             $vendor_id = (int)$r_c['vendor_id'];
                             $username = $r_c['username'];
                             $log("Resolved context via metadata reference lookup: $username (VID: $vendor_id)");
                         }
                    }
                }
            }

            if (!empty($meta['referrer'])) {
                // Referrer example: https://merchant.payhub.com.ng/checkout.php?key=...&ref=17734855528636&embed=1
                $parts = parse_url($meta['referrer']);
                if (!empty($parts['query'])) {
                    parse_str($parts['query'], $q_params);
                    $ref_from_url = $q_params['ref'] ?? ($q_params['reference'] ?? "");
                    if (!empty($ref_from_url)) {
                        $log("Referrer reference found: " . $ref_from_url);

                        if (empty($username) || $vendor_id <= 0) {
                            $ref_esc = mysqli_real_escape_string($connection_server, $ref_from_url);
                            $q_c = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
                            if ($r_c = mysqli_fetch_assoc($q_c)) {
                                $vendor_id = (int)$r_c['vendor_id'];
                                $username = $r_c['username'];
                                $log("Resolved context via referrer URL lookup: $username (VID: $vendor_id)");
                            }
                        }

                        // Add to searchable references for crediting logic below
                        $meta['url_reference'] = $ref_from_url;
                    }
                }
            }
        }
    }

    // 2. Account Number lookup fallback (for direct VA payments where we only have receiver account)
    // Support both receiver_bank_account_number and account_number fields, even nested.
    // $meta['receiver_account_number'] is the copy PayHub stores in the transaction metadata, so
    // checking it keeps VA resolution working now that callers pass the verified PayHub response
    // rather than the forwarded Paystack payload (which carried the top-level field).
    $acc_no = $data['receiver_bank_account_number'] ?? ($data['account_number'] ?? ($data['virtual_account']['account_number'] ?? ($meta['receiver_account_number'] ?? "")));
    if ((empty($username) || $vendor_id <= 0) && !empty($acc_no)) {
        $log("Attempting resolution via account number: $acc_no");
        $acc_no_esc = mysqli_real_escape_string($connection_server, $acc_no);
        $uq = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_banks WHERE account_number='$acc_no_esc' LIMIT 1");
        if ($ur = mysqli_fetch_assoc($uq)) {
            $vendor_id = (int)$ur['vendor_id'];
            $username = $ur['username'];
            $log("Resolved User via Account: $username (VID: $vendor_id)");
        } else {
            $vq = mysqli_query($connection_server, "SELECT vendor_id FROM sas_vendor_banks WHERE account_number='$acc_no_esc' LIMIT 1");
            if ($vr = mysqli_fetch_assoc($vq)) {
                $vendor_id = (int)$vr['vendor_id'];
                $username = "";
                $log("Resolved Vendor via Account (VID: $vendor_id)");
            }
        }
    }

    // 3. Final identification fallback by email pattern or direct lookup
    if (empty($username) || $vendor_id <= 0) {
        $customer_email = $data["email"] ?? ($data["customer"]["email"] ?? "");
        if (!empty($customer_email)) {
            $log("Attempting resolution via email: $customer_email");
            // Check for derived email pattern v{vid}.{email} or v{vid}u{username}@{host}
            if (preg_match('/^v(\d+)\.(.+)$/', $customer_email, $matches)) {
                $vendor_id = (int)$matches[1];
                $real_email = $matches[2];
                if (empty($username)) {
                    $email_esc = mysqli_real_escape_string($connection_server, $real_email);
                    $uq = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND email='$email_esc' LIMIT 1");
                    if ($ur = mysqli_fetch_assoc($uq)) $username = $ur['username'];
                }
                $log("Resolved via v pattern. VID: $vendor_id | User: $username");
            } elseif (preg_match('/^v(\d+)u([^@]+)@/', $customer_email, $matches)) {
                $vendor_id = (int)$matches[1];
                $username = $matches[2];
                $log("Resolved via u pattern. VID: $vendor_id | User: $username");
            } else {
                $email_esc = mysqli_real_escape_string($connection_server, $customer_email);
                // Search users first (more likely)
                $uq = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_users WHERE email='$email_esc' LIMIT 1");
                if ($ur = mysqli_fetch_assoc($uq)) {
                    $vendor_id = (int)$ur['vendor_id'];
                    $username = $ur['username'];
                    $log("Resolved User via direct email. User: $username | VID: $vendor_id");
                } else {
                    // Search vendors
                    $vq = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE email='$email_esc' LIMIT 1");
                    if ($vr = mysqli_fetch_assoc($vq)) {
                        $vendor_id = (int)$vr['id'];
                        $username = "";
                        $log("Resolved Vendor via direct email (VID: $vendor_id)");
                    }
                }
            }
        }
    }

    // Ensure global context is set for sub-functions
    if ($vendor_id > 0) {
        $GLOBALS['vendor_id'] = $vendor_id;
        resolveVendorID(true);
    } else {
        $log("CRITICAL: Could not resolve context. Aborting.");
        return false;
    }

    // 4. Amount Calculation
    $customer_email = $data["email"] ?? ($data["customer"]["email"] ?? "");
    $raw_amount = (float)($data["amount"] ?? ($data["amount_paid"] ?? 0));

    // SECURITY: only the test/live marker PayHub returns may gate crediting. This $data comes
    // from OUR server-to-server verify call, so unlike a browser callback it cannot be forged
    // by the payer. An absent value means an older PayHub build - default to live rather than
    // breaking real payments.
    $payhub_domain = strtolower(trim((string)($data["domain"] ?? '')));
    if ($payhub_domain === 'test') {
        $log("BLOCKED: PayHub reports this payment as TEST/sandbox (ref: $transaction_ref). Refusing to fund a real wallet with sandbox money.");
        return false;
    }

    // PayHub reports the amount the GATEWAY ACTUALLY SETTLED separately from the amount we
    // asked for. `amount` is our own requested figure echoed back, so comparing it with the
    // record below can never detect an underpayment - it matches by construction. Only the
    // settled figure is evidence that money arrived. PayHub reports it in minor units (kobo).
    $settled_naira = 0;
    if (isset($data["gateway_amount"]) && is_numeric($data["gateway_amount"]) && (float)$data["gateway_amount"] > 0) {
        $settled_naira = (float)$data["gateway_amount"] / 100;
    }

    $channel = strtolower($data['channel'] ?? '');

    // Try to find expected amount from existing record to resolve Naira/Kobo ambiguity
    $expected_naira = 0;
    $ref_match_sql_basic = "(api_reference='$transaction_ref' OR reference='$transaction_ref')";
    if (!empty($meta['reference'])) {
        $m_ref_esc = mysqli_real_escape_string($connection_server, $meta['reference']);
        $ref_match_sql_basic = "($ref_match_sql_basic OR reference='$m_ref_esc' OR api_reference='$m_ref_esc')";
    }

    $q_expected = mysqli_query($connection_server, "SELECT amount FROM sas_transactions WHERE vendor_id='$vendor_id' AND $ref_match_sql_basic LIMIT 1");
    if (!$q_expected || mysqli_num_rows($q_expected) == 0) {
        $q_expected = mysqli_query($connection_server, "SELECT amount FROM sas_vendor_transactions WHERE vendor_id='$vendor_id' AND $ref_match_sql_basic LIMIT 1");
    }

    if ($q_expected && $row_ex = mysqli_fetch_assoc($q_expected)) {
        $expected_naira = (float)$row_ex['amount'];
        $log("Expected amount from DB: $expected_naira");
    }

    if ($expected_naira > 0) {
        // Compare what the gateway SETTLED against what this reference was created for.
        // Older PayHub builds do not send gateway_amount, so fall back to our requested
        // figure read as both Naira and Kobo for backwards compatibility.
        // Each candidate is [naira value, whether the incoming figure was in minor units].
        $candidates = ($settled_naira > 0)
            ? [[$settled_naira, true]]
            : [[$raw_amount, false], [$raw_amount / 100, true]];

        $amount_paid = null;
        $reported_in_minor_units = false;
        $settled_seen = null;
        foreach ($candidates as $candidate) {
            $settled_seen = (float)$candidate[0];

            // When we know what the gateway actually SETTLED, accept any figure that COVERS the
            // recorded amount. A payer is normally charged MORE than was asked for - the gateway
            // adds its fee on top at the moment of payment, so a record for 100 settles as
            // 101.53 - and requiring equality refused a payment the customer had already made:
            // their money left the account and the wallet was never funded, which is the
            // "Payment confirmed but crediting failed" report. Without a settled figure all we
            // have is our own requested amount echoed back, which matches by construction, so the
            // older exact comparison still applies there.
            $matches = ($settled_naira > 0)
                ? bc_gateway_amount_covers($expected_naira, $settled_seen)
                : (abs($settled_seen - $expected_naira) < 0.1);

            if ($matches) {
                $reported_in_minor_units = $candidate[1];
                // Credit what this reference was CREATED FOR, never the inflated settled figure:
                // the difference is the gateway's own fee, charged to the payer on top, and is not
                // money the merchant received.
                $amount_paid = ($settled_naira > 0) ? $expected_naira : $settled_seen;
                if ($settled_naira > 0 && $settled_seen > $expected_naira + 0.01) {
                    $log("FEE ON TOP: gateway settled $settled_seen against a record for $expected_naira - accepted as paid, credited the recorded amount.");
                }
                break;
            }
        }

        if ($amount_paid === null) {
            // SECURITY: a record exists for this reference, so the amount the customer was
            // asked to pay is known exactly. Do NOT fall back to a heuristic and credit
            // anyway - that is how a manipulated payment (PayHub reporting 10,175 against a
            // settled 102.54) funds a wallet that was never paid for. Leave the record
            // pending so it can be reviewed and credited by hand if the payment is genuine.
            $log("BLOCKED amount short: gateway settled " . ($settled_seen === null ? 'n/a' : $settled_seen) . " (gateway_amount=" . ($data["gateway_amount"] ?? 'n/a') . ", amount=$raw_amount) but the recorded amount for this reference is $expected_naira. Refusing to credit.");
            return false;
        }

        $fees = $reported_in_minor_units ? ((float)($data["fees"] ?? 0) / 100) : (float)($data["fees"] ?? 0);
        $log("Match Found: $amount_paid (expected $expected_naira)");
    } else {
        // No local record (e.g. a dedicated-account deposit): the amount received IS the
        // amount to credit, so the Naira/Kobo heuristic is the correct reading here.
        $is_kobo = ($raw_amount >= 500 && strpos((string)$raw_amount, '.') === false) || ($channel == 'dedicated_account');
        $amount_paid = $is_kobo ? ($raw_amount / 100) : $raw_amount;
        $fees = $is_kobo ? ((float)($data["fees"] ?? 0) / 100) : (float)($data["fees"] ?? 0);
        $log("New Record: Heuristic " . ($is_kobo ? "Kobo" : "Naira") . " -> $amount_paid");
    }

    $charge_percent = (float)($payhub_keys['percentage'] ?? 0);
    if ($charge_percent > 0) {
        $amount_deposited = $amount_paid * (1 - ($charge_percent / 100));
    } else {
        $amount_deposited = $amount_paid - $fees;
    }

    // Fixed fee for small amounts if applicable (Standard industry practice)
    if ($amount_paid < 1 && $amount_deposited <= 0) $amount_deposited = $amount_paid;

    $log("Final Calc - Amount: $amount_paid | Fees: $fees | Crediting: $amount_deposited");

    $payment_method = $data["channel"] ?? "PAYHUB";
    $desc = "PayHub Wallet Credit - ".str_replace("_"," ",$payment_method);

    if ((!empty($username) && (isset($meta['target']) && $meta['target'] == 'user')) || (!isset($meta['target']) && !empty($username) && $vendor_id > 0)) {
        // User Funding
        $log("Target: USER ($username)");
        $u_esc = mysqli_real_escape_string($connection_server, $username);

        // Escape the gateway reference before it enters SQL: it comes from PayHub's response and
        // was previously interpolated raw, so a quote in it would break the query outright.
        $tx_ref_esc_match = mysqli_real_escape_string($connection_server, $transaction_ref);
        $ref_match_sql = "(api_reference='$tx_ref_esc_match' OR reference='$tx_ref_esc_match')";
        if (!empty($meta['reference'])) {
            $m_ref_esc = mysqli_real_escape_string($connection_server, $meta['reference']);
            $ref_match_sql = "($ref_match_sql OR reference='$m_ref_esc' OR api_reference='$m_ref_esc')";
        }
        if (!empty($meta['url_reference'])) {
            $u_ref_esc = mysqli_real_escape_string($connection_server, $meta['url_reference']);
            $ref_match_sql = "($ref_match_sql OR reference='$u_ref_esc' OR api_reference='$u_ref_esc')";
        }

        // Final fallback: metadata might be a JSON string inside data['metadata']
        // processPayhubSuccess already handles this, but let's be explicit in the log
        $log("Matching via SQL: $ref_match_sql");

        // sas_transactions has NO `id` column - it is keyless (see the table definition in
        // func/bc-tables.php, and the live schema). Selecting `id` made MySQL reject the statement
        // with "Unknown column 'id'", mysqli_query() returned false, and the unguarded
        // mysqli_fetch_assoc(false) raised a TypeError that white-screened the success page for
        // every paying customer. Identify the row by `reference` instead: it is NOT NULL and it is
        // the same key the INSERT and alterTransaction() already use.
        $q_existing = mysqli_query($connection_server, "SELECT reference, status FROM sas_transactions WHERE vendor_id='$vendor_id' AND $ref_match_sql LIMIT 1");
        if (!$q_existing) {
            $log("SQL ERROR (user transaction lookup): " . mysqli_error($connection_server) . " | ref_match_sql: $ref_match_sql");
        }
        if ($q_existing && ($tx = mysqli_fetch_assoc($q_existing))) {
            if ($tx['status'] != 1) {
                $user_q = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE vendor_id='$vendor_id' AND username='$u_esc' LIMIT 1");
                $user_r = $user_q ? mysqli_fetch_assoc($user_q) : null;
                $bal_before = (float)($user_r['balance'] ?? 0);
                $bal_after = $bal_before + $amount_deposited;

                mysqli_query($connection_server, "UPDATE sas_users SET balance='$bal_after' WHERE vendor_id='$vendor_id' AND username='$u_esc'");
                $tx_local_ref_esc = mysqli_real_escape_string($connection_server, $tx['reference']);
                if (!mysqli_query($connection_server, "UPDATE sas_transactions SET status=1, balance_before='$bal_before', balance_after='$bal_after', discounted_amount='$amount_deposited', api_reference='$tx_ref_esc_match' WHERE vendor_id='$vendor_id' AND reference='$tx_local_ref_esc'")) {
                    $log("SQL ERROR (user credit update): " . mysqli_error($connection_server));
                }

                $log("User Credited (Existing record updated). New Bal: $bal_after");
                syncPayhubVirtualAccounts($vendor_id, $customer_email, false, $username);
                return $tx['reference']; // Return local reference
            } else {
                 $log("SKIP: User transaction already credited.");
                 return $tx['reference'];
            }
        } else {
            $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
            $log("Creating new User credit record. Ref: $new_ref");
            chargeOtherUser($username, "credit", "wallet_funding", "Wallet Funding", $new_ref, $transaction_ref, $amount_paid, $amount_deposited, $desc, "WEB", $_SERVER['HTTP_HOST'], 1);
            syncPayhubVirtualAccounts($vendor_id, $customer_email, false, $username);
            return $new_ref;
        }
    } elseif ((isset($meta['target']) && $meta['target'] == 'vendor') || (empty($username) && $vendor_id > 0 && !isset($meta['target']))) {
        // Vendor Funding
        $log("Target: VENDOR ($vendor_id)");
        $q_vendor = mysqli_query($connection_server, "SELECT id, email, balance FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
        if (!$q_vendor) {
            $log("SQL ERROR (vendor lookup): " . mysqli_error($connection_server));
        }
        if ($q_vendor && ($rv = mysqli_fetch_assoc($q_vendor))) {

            // Check for service activation in metadata or transaction record
            $is_plisio_activation = (($meta['product_unique_id'] ?? '') == 'plisio_activation' || ($meta['target'] ?? '') == 'plisio_activation');
            $is_payout_activation = (($meta['product_unique_id'] ?? '') == 'payout_activation' || ($meta['target'] ?? '') == 'payout_activation');

            $ref_match_sql = "(reference='$transaction_ref' OR product_unique_id='$transaction_ref')";
            if (!empty($meta['reference'])) {
                $m_ref_esc = mysqli_real_escape_string($connection_server, $meta['reference']);
                $ref_match_sql = "($ref_match_sql OR reference='$m_ref_esc' OR product_unique_id='$m_ref_esc')";
            }
            if (!empty($meta['url_reference'])) {
                $u_ref_esc = mysqli_real_escape_string($connection_server, $meta['url_reference']);
                $ref_match_sql = "($ref_match_sql OR reference='$u_ref_esc' OR product_unique_id='$u_ref_esc')";
            }
            $log("Matching Vendor via SQL: $ref_match_sql");
            // product_unique_id must be SELECTed: the activation branches below read it from this
            // row, and reading a column that was never fetched yielded an undefined-key warning and
            // silently treated a plisio/payout activation as ordinary wallet funding.
            // Same reason as the user branch above: sas_vendor_transactions is keyless too, so
            // selecting `id` made this query fail and the unguarded fetch_assoc() fatal. Key on
            // `reference`, which the UPDATEs below now use as well.
            $select_transaction_history = mysqli_query($connection_server,"SELECT reference, status, product_unique_id FROM sas_vendor_transactions WHERE vendor_id='$vendor_id' && $ref_match_sql LIMIT 1");
            if (!$select_transaction_history) {
                $log("SQL ERROR (vendor transaction lookup): " . mysqli_error($connection_server));
            }
            if ($select_transaction_history && ($vtx = mysqli_fetch_assoc($select_transaction_history))) {
                $vtx_ref_esc = mysqli_real_escape_string($connection_server, $vtx['reference']);
                if ($vtx['status'] != 1) {
                    $bal_before = (float)($rv['balance'] ?? 0);
                    $bal_after = $bal_before + $amount_deposited;

                    if ($is_plisio_activation || $vtx['product_unique_id'] == 'plisio_activation') {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET plisio_activated=1 WHERE id='$vendor_id'");
                        mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, balance_before='$bal_before', balance_after='$bal_before', discounted_amount='$amount_deposited' WHERE vendor_id='$vendor_id' AND reference='$vtx_ref_esc'");
                        $log("Vendor Plisio Activated (Existing record).");
                    } elseif ($is_payout_activation || $vtx['product_unique_id'] == 'payout_activation') {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET payout_activated=1 WHERE id='$vendor_id'");
                        mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, balance_before='$bal_before', balance_after='$bal_before', discounted_amount='$amount_deposited' WHERE vendor_id='$vendor_id' AND reference='$vtx_ref_esc'");
                        $log("Vendor Payout Module Activated (Existing record).");
                    } else {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET balance='$bal_after' WHERE id='$vendor_id'");
                        mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, balance_before='$bal_before', balance_after='$bal_after', discounted_amount='$amount_deposited' WHERE vendor_id='$vendor_id' AND reference='$vtx_ref_esc'");
                        $log("Vendor Credited (Existing record updated). New Bal: $bal_after");
                    }

                    syncPayhubVirtualAccounts($vendor_id, $customer_email, true);
                    return $vtx['reference'];
                } else {
                    $log("SKIP: Vendor transaction already credited.");
                    return $vtx['reference'];
                }
            } else {
                $bal_before = (float)($rv['balance'] ?? 0);
                $bal_after = $bal_before + $amount_deposited;

                $new_v_ref = "V".time().rand(10,99);
                mysqli_query($connection_server, "UPDATE sas_vendors SET balance='$bal_after' WHERE id='$vendor_id'");
                mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) VALUES ('$vendor_id', 'wallet_funding', 'Wallet Funding', '$new_v_ref', '$amount_paid', '$amount_deposited', '$bal_before', '$bal_after', '$desc', '".bc_safe_host_sql($connection_server)."', '1')");

                // If transaction exists in pending status, update it
                if (!empty($meta['reference'])) {
                    $m_ref_esc = mysqli_real_escape_string($connection_server, $meta['reference']);
                    mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, balance_before='$bal_before', balance_after='$bal_after', discounted_amount='$amount_deposited' WHERE reference='$m_ref_esc' AND vendor_id='$vendor_id'");
                }

                $log("Vendor Credited. New Bal: $bal_after | Local Ref: $new_v_ref");
                syncPayhubVirtualAccounts($vendor_id, $customer_email, true);
                return $new_v_ref;
            }
        }
    }
    return false;
}

function getStandardBankCode($nip_code) {
    $mapping = [
        '000013' => '058',   // GTBank
        '000014' => '044',   // Access
        '000015' => '057',   // Zenith
        '000004' => '033',   // UBA
        '000016' => '011',   // First Bank
        '000017' => '094',   // Wema
        '000003' => '214',   // FCMB
        '000007' => '070',   // Fidelity
        '000001' => '030',   // Sterling
        '000008' => '076',   // Polaris
        '000010' => '050',   // Ecobank
        '000011' => '215',   // Unity
        '000012' => '039',   // Stanbic
        '000018' => '032',   // Union
        '000020' => '020',   // Heritage
        '000023' => '076',   // Providus
        '000006' => '301',   // Jaiz
        '000021' => '068',   // Standard Chartered
        '000022' => '100',   // Suntrust
        '000026' => '302',   // Taj
        '000027' => '103',   // Globus
        '000025' => '102',   // Titan
        '000029' => '303',   // Lotus
        '000030' => '526',   // Parallex
        '000031' => '105',   // Premium Trust
        '000032' => '50615', // Firmus
        '000033' => '50968', // Caneland
        '000034' => '50744', // Signature
        '000035' => '000035',// Optimus
        '090267' => '50211', // Kuda
        '100033' => '999991',// PalmPay
        '100004' => '999992',// OPay
        '090405' => '50515', // Moniepoint
        '100011' => '999992',// OPay Digital
        '090110' => '566',   // VFD
        '090151' => 'MutualTrust',
        '090175' => '090175',// Rubies
        '090112' => '090112',// SEED CAPITAL
        '070001' => '070001',// NPF
        '058' => '058', '044' => '044', '057' => '057', '033' => '033', '011' => '011', '094' => '094', '214' => '214', '070' => '070', '030' => '030', '076' => '076', '050' => '050', '215' => '215', '039' => '039', '032' => '032'
    ];

    $trimmed_nip = ltrim($nip_code, '0');
    // Ensure 3-digit normalization for major legacy banks
    if (strlen($trimmed_nip) == 2 && in_array($trimmed_nip, ['11', '30', '32', '33', '39', '44', '50', '57', '58', '70', '76', '94'])) {
        $trimmed_nip = '0' . $trimmed_nip;
    }

    // Special Handlers
    if ($nip_code == '100033' || $nip_code == '999991' || $nip_code == '329') return '999991'; // PalmPay
    if ($nip_code == '100004' || $nip_code == '100011' || $nip_code == '999992' || $nip_code == '307') return '999992'; // OPay
    if ($nip_code == '000016' || $nip_code == '011') return '011'; // First Bank
    if ($nip_code == '000013' || $nip_code == '058') return '058'; // GTB
    if ($nip_code == '000015' || $nip_code == '057') return '057'; // Zenith
    if ($nip_code == '000014' || $nip_code == '044') return '044'; // Access
    if ($nip_code == '000004' || $nip_code == '033') return '033'; // UBA

    return $mapping[$nip_code] ?? ($mapping['0000'.$trimmed_nip] ?? $nip_code);
}

function payhubResolveBank($account_number, $bank_code, $vid = null) {
    $log = function($m) {};

    // Workaround Strategy: If PayHub is known to be returning Null, attempt a cross-provider resolution first if possible
    // This ensures the customer sees a name even if PayHub's lookup service is down.
    $log("Initiating Resolution: Acc=$account_number | Bank=$bank_code | VID=$vid");

    $code = getStandardBankCode($bank_code);
    $last_3 = substr($bank_code, -3);
    $trimmed = ltrim($bank_code, '0');
    $try_codes = array_unique([$code, $bank_code, $trimmed, $last_3]);
    if (strlen($bank_code) == 10) $try_codes = array_unique([$code, $last_3, $trimmed]); // Fix for bank_code being account_number if mis-passed

    $log("Resolve: Acc=$account_number | Bank=$bank_code | VID=$vid | TryCodes=" . implode(',', $try_codes));

    $last_res = null;
    $fallback_res = null;
    $final_account_name = "";
    $final_mapped_code = "";
    $final_json = "";

    // Comprehensive Strategy Engine: prioritizing working GET structures found in logs
    $strategies = [
        // Strategy 1: Standard resolve-account with AI-suggested parameter names
        ['ep' => 'api/resolve-account', 'method' => 'GET', 'params' => ['account' => $account_number, 'bank' => '']],
        ['ep' => 'api/resolve-account', 'method' => 'GET', 'params' => ['account_number' => $account_number, 'bank_code' => '']],
        ['ep' => 'api/resolve-account', 'method' => 'GET', 'params' => ['accountNumber' => $account_number, 'bankCode' => '']],

        // Strategy 2: verify-account endpoint
        ['ep' => 'api/verify-account', 'method' => 'GET', 'params' => ['account' => $account_number, 'bank' => '']],
        ['ep' => 'api/verify-account', 'method' => 'GET', 'params' => ['account_number' => $account_number, 'bank_code' => '']],

        // Strategy 3: resolve endpoint
        ['ep' => 'api/resolve', 'method' => 'GET', 'params' => ['account' => $account_number, 'bank' => '']],
        ['ep' => 'api/resolve', 'method' => 'GET', 'params' => ['account_number' => $account_number, 'bank_code' => '']],

        // Strategy 4: Versioned Endpoints (v1/v2)
        ['ep' => 'api/v1/resolve-account', 'method' => 'GET', 'params' => ['account' => $account_number, 'bank' => '']],
        ['ep' => 'api/v2/resolve-account', 'method' => 'GET', 'params' => ['account' => $account_number, 'bank' => '']],

        // Strategy 5: Path Segment Variants (api/resolve-account/058/0123456789)
        ['ep' => "api/resolve-account/{code}/$account_number", 'method' => 'GET', 'params' => []],
        ['ep' => "api/verify-account/{code}/$account_number", 'method' => 'GET', 'params' => []],

        // Strategy 6: payout endpoints
        ['ep' => 'api/payout/resolve-account', 'method' => 'GET', 'params' => ['account_number' => $account_number, 'bank_code' => '']],
    ['ep' => 'api/payout/resolve', 'method' => 'GET', 'params' => ['account_number' => $account_number, 'bank_code' => '']],
    ];


    foreach ($try_codes as $code) {
        $log("Trying Code: $code");

        foreach ($strategies as $strategy) {
            $ep = str_replace('{code}', $code, $strategy['ep']);
            $method = $strategy['method'];
            $params = $strategy['params'];

            // Map the code to the correct key in the strategy
            if (array_key_exists('bank_code', $params)) $params['bank_code'] = $code;
            elseif (array_key_exists('bankCode', $params)) $params['bankCode'] = $code;
            elseif (array_key_exists('bankcode', $params)) $params['bankcode'] = $code;
            elseif (array_key_exists('bank_id', $params)) $params['bank_id'] = $code;
            elseif (array_key_exists('bank', $params)) $params['bank'] = $code;

            if (array_key_exists('bank_name', $params)) {
                $retrieve_bank_list = file_get_contents($_SERVER["DOCUMENT_ROOT"] . "/func/banks.json");
                $banks = json_decode($retrieve_bank_list, true);
                $found_bank_name = "";
                if (is_array($banks)) {
                    foreach($banks as $b) {
                        if ($b['bankCode'] == $bank_code || $b['bankCode'] == $code) {
                            $found_bank_name = $b['bankName'];
                            break;
                        }
                    }
                }
                $params['bank_name'] = $found_bank_name ?: $code;
            }

            if (array_key_exists('label', $params)) {
                 $retrieve_bank_list = file_get_contents($_SERVER["DOCUMENT_ROOT"] . "/func/banks.json");
                 $banks = json_decode($retrieve_bank_list, true);
                 $found_bank_name = "";
                 if (is_array($banks)) {
                     foreach($banks as $b) {
                         if ($b['bankCode'] == $bank_code || $b['bankCode'] == $code) {
                             $found_bank_name = $b['bankName'];
                             break;
                         }
                     }
                 }
                 $params['label'] = $found_bank_name ?: $code;
            }

            if (array_key_exists('key', $params)) {
                $ghd = getGatewayDetails('payhub', $vid);
                $params['key'] = $ghd['secret_key'] ?? '';
            }
            if (array_key_exists('token', $params)) {
                $ghd = getGatewayDetails('payhub', $vid);
                $params['token'] = $ghd['secret_key'] ?? '';
            }

            $res_json = makePayhubRequest($method, $ep, $params, $vid, null, true);
            $log("Attempt: $method $ep | Params: ".json_encode($params)." | Res: $res_json");
            $res = json_decode($res_json, true);

            if (($res['status'] ?? '') == 'success') {
                $inner = json_decode($res['json_result'] ?? '{}', true);
                $fallback_res = $inner;
                // Check if PayHub returned success status in their inner JSON
                if (isset($inner['status']) && ($inner['status'] === true || $inner['status'] === 'success' || $inner['status'] == 200)) {
                        // Exhaustive account name extraction from all possible keys
                        $account_name = extractNameFromResolutionResponse($inner);

                        // Final check before returning success
                        if (!empty($account_name)) {
                            $log("Success: Code=$code | EP=$ep | Method=$method | Params=".json_encode($params)." | Name=$account_name");
                            return [
                            'status' => 'success',
                            'account_name' => $account_name,
                            'data' => $inner['data'] ?? $inner,
                            'json_result' => $res['json_result'],
                            'mapped_bank_code' => $code
                            ];
                        }
                }
                $last_res = $inner;
            } else {
                $last_res = $res;
            }
        }
    }

    // Final Critical Workaround: If PayHub continues to return null data, attempt resolution via Paystack
    // even though PayHub is the target payout provider.
    $log("✗ All PayHub strategies returned null. Attempting Paystack Fallback resolution...");
    $ps_res = paystackResolveAccount($account_number, $bank_code, $vid);

    if (($ps_res['status'] ?? '') == 'success' && !empty($ps_res['account_name'])) {
        $log("✓ Paystack Fallback SUCCESS: Name=" . $ps_res['account_name']);
        return [
            'status' => 'success',
            'account_name' => $ps_res['account_name'],
            'data' => $ps_res['data'] ?? [],
            'json_result' => $ps_res['json_result'] ?? '',
            'mapped_bank_code' => $ps_res['mapped_bank_code'] ?? $code,
            'is_fallback' => true
        ];
    }

    $log("✗ All resolution attempts failed.");
    $final_res = $fallback_res ?? $last_res;
    if (!is_array($final_res)) $final_res = [];

    // Explicitly set status to failed if we are here (name was never found)
    $final_res['status'] = 'failed';
    if (empty($final_res['message'])) $final_res['message'] = 'Account name not found (Provider returned empty data)';
    return $final_res;
}

function payhubInitiatePayout($amount, $bank_code, $account_number, $account_name, $narration = "Payout", $vid = null) {
    // Attempt to resolve mapped bank code if not already mapped
    $effective_code = getStandardBankCode($bank_code);

    $payload = [
        "amount" => $amount,
        "bank_code" => $effective_code,
        "account_number" => $account_number,
        "account_name" => $account_name,
        "narration" => $narration,
        "reference" => "PO_" . time() . "_" . rand(100, 999)
    ];
    $res_json = makePayhubRequest("POST", "api/payout/initialize", $payload, $vid, null, true);
    $res = json_decode($res_json, true);
    if (($res['status'] ?? '') == 'success') {
        $inner = json_decode($res['json_result'] ?? '{}', true);
        if (!empty($inner)) return $inner;
    }
    return $res;
}

function syncPayhubVirtualAccounts($vendor_id, $email, $is_vendor = false, $target_username = "", $is_manual = false) {
    global $connection_server;
    $result = ["success" => false, "message" => "Account not found"];

    $log = function($m) {};

    if (empty($email)) {
        $result["message"] = "Customer email is missing";
        return $result;
    }

    if (!$is_vendor && empty($target_username)) {
        $u_q = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND email='".mysqli_real_escape_string($connection_server, $email)."' LIMIT 1");
        $u_r = mysqli_fetch_assoc($u_q);
        $target_username = $u_r['username'] ?? "";
    }

    // Determine effective email for PayHub API (derived email prevents cross-vendor collisions)
    $sanitized_host = strtolower(preg_replace('/^www\./', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'vtuplatform.com')[0]));
    if (strpos($sanitized_host, '.') === false) $sanitized_host .= ".com";

    if ($is_vendor) {
        $pay_email = "v" . $vendor_id . "." . $email;
    } else {
        $pay_email = !empty($target_username)
            ? "v" . $vendor_id . "u" . $target_username . "@" . $sanitized_host
            : "v" . $vendor_id . "." . $email;
    }
    $email_to_use = $pay_email;

    $log("Initiating Sync for VID: $vendor_id | User: $target_username | PayEmail: $email_to_use | IsVendor: " . ($is_vendor ? 'Yes' : 'No'));

    // ── Helper: save a single account record ─────────────────────────────────
    $save_account = function($acc_data, $ref_override = "") use ($is_vendor, $vendor_id, $target_username, $log) {
        $ref   = !empty($ref_override) ? $ref_override : ($acc_data["reference"] ?? ($acc_data["id"] ?? ""));
        $bname = $acc_data["bank_name"] ?? ($acc_data["bankName"] ?? "PayHub Bank");
        $accno = $acc_data["account_number"] ?? ($acc_data["accountNumber"] ?? "");
        $accnm = $acc_data["account_name"] ?? ($acc_data["accountName"] ?? "");
        if (empty($accno)) return false;
        $log("Saving account: $accno ($bname) for " . ($is_vendor ? "vendor $vendor_id" : "user $target_username"));
        if ($is_vendor) {
            addVendorVirtualBank($ref, "PayHub", $bname, $accno, $accnm, $vendor_id, 'payhub');
        } elseif (!empty($target_username)) {
            addUserVirtualBank($ref, "PayHub", $bname, $accno, $accnm, $vendor_id, $target_username, 'payhub');
        } else {
            return false;
        }
        return true;
    };

    // ── Helper: flatten any PayHub response into a list of account objects ────
    // Handles: data[], records[], accounts[], list[], virtual_accounts[], virtual_account{}, root{}
    $extract_accounts = function($parsed) use ($log) {
        if (!is_array($parsed)) return [];
        // Keys that commonly wrap account data in payment gateway responses
        $wrapper_keys = ['data', 'virtual_accounts', 'virtual_account', 'accounts', 'records', 'list', 'customers', 'result'];
        foreach ($wrapper_keys as $k) {
            if (!isset($parsed[$k]) || empty($parsed[$k])) continue;
            $val = $parsed[$k];
            if (!is_array($val)) continue;
            // Single account object (has account_number at root of this value)
            if (isset($val['account_number']) || isset($val['accountNumber'])) {
                $log("Found single account under key '$k'");
                return [$val];
            }
            // Indexed list of accounts
            if (isset($val[0])) {
                $log("Found " . count($val) . " account(s) under key '$k'");
                return $val;
            }
            // One more level of nesting (e.g. data.records[])
            foreach ($wrapper_keys as $k2) {
                if (!isset($val[$k2]) || !is_array($val[$k2])) continue;
                $inner = $val[$k2];
                if (isset($inner[0])) {
                    $log("Found " . count($inner) . " account(s) under key '$k.$k2'");
                    return $inner;
                }
                if (isset($inner['account_number']) || isset($inner['accountNumber'])) {
                    return [$inner];
                }
            }
        }
        // Root is a single account object
        if (isset($parsed['account_number']) || isset($parsed['accountNumber'])) {
            $log("Found single account at response root");
            return [$parsed];
        }
        // Root is an indexed list
        if (isset($parsed[0])) {
            $log("Found " . count($parsed) . " account(s) at response root");
            return $parsed;
        }
        return [];
    };

    // ── Helper: determine ownership of an account record ─────────────────────
    // $is_specific = true when the endpoint URL was email-targeted (e.g. api/{email})
    $is_account_match = function($acc, $is_specific) use ($email_to_use, $email, $log) {
        $accno = $acc['account_number'] ?? ($acc['accountNumber'] ?? '');
        if (empty($accno)) return false;
        $acc_email = $acc["email"] ?? ($acc["customer_email"] ?? ($acc["customer"]["email"] ?? ""));
        if (!empty($acc_email)) {
            $match = strtolower(trim($acc_email)) === strtolower(trim($email_to_use))
                  || strtolower(trim($acc_email)) === strtolower(trim($email));
            if ($match) $log("Email match confirmed: $acc_email");
            return $match;
        }
        // Account has no email field — trust the endpoint if it was email-specific
        if ($is_specific) {
            $log("No email in record; email-specific endpoint — treating as match.");
            return true;
        }
        return false;
    };

    // ── Phase 1: Fetch existing accounts ─────────────────────────────────────
    // Ordered from most-specific (email in path/query) to broadest (full list)
    $fetch_endpoints = [
        ["ep" => "api/" . urlencode($email_to_use),                              "specific" => true],
        ["ep" => "api/virtual-accounts?email=" . urlencode($email_to_use),       "specific" => true],
        ["ep" => "api/virtual-accounts",                                          "specific" => false],
    ];

    foreach ($fetch_endpoints as $ep_info) {
        $ep          = $ep_info["ep"];
        $is_specific = $ep_info["specific"];
        $log("Trying fetch endpoint: $ep");
        $res_json = makePayhubRequest("GET", $ep, "", $vendor_id, $is_vendor);
        $res = json_decode($res_json, true);
        if (($res["status"] ?? "") !== "success") {
            $log("Endpoint $ep returned: " . ($res["message"] ?? "non-success"));
            continue;
        }
        $inner    = json_decode($res["json_result"] ?? "", true);
        $accounts = $extract_accounts($inner);
        if (empty($accounts)) {
            $log("Endpoint $ep — no extractable accounts in response.");
            continue;
        }
        $log("Endpoint $ep — " . count($accounts) . " candidate(s) to check.");
        foreach ($accounts as $acc) {
            if ($is_account_match($acc, $is_specific)) {
                if ($save_account($acc)) {
                    $result["success"] = true;
                    $result["message"] = "Accounts synced via $ep";
                }
            }
        }
        if ($result["success"] && !$is_manual) break;
    }

    // ── Phase 2: Create / generate if still not found (or forced by manual) ──
    if (!$result["success"] || $is_manual) {
        if (!$result["success"]) $log("No existing accounts found. Attempting generation...");
        else $log("Manual sync: running generation pass as well.");

        $customer_data = [];
        if ($is_vendor) {
            $q = mysqli_query($connection_server, "SELECT firstname, lastname, phone_number FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
            if ($r = mysqli_fetch_assoc($q)) {
                $customer_data = ["email" => $email, "first_name" => $r['firstname'], "last_name" => $r['lastname'], "phone" => $r['phone_number']];
            }
        } else {
            $q = mysqli_query($connection_server, "SELECT firstname, lastname, phone_number, username FROM sas_users WHERE vendor_id='$vendor_id' AND email='".mysqli_real_escape_string($connection_server, $email)."' LIMIT 1");
            if ($r = mysqli_fetch_assoc($q)) {
                $customer_data = ["email" => $email, "first_name" => $r['firstname'], "last_name" => $r['lastname'], "phone" => $r['phone_number'], "username" => $r['username']];
            }
        }

        if (empty($customer_data)) {
            $log("Customer profile not found in DB for email: $email");
            if (!$result["success"]) $result["message"] = "Customer profile not found";
        } else {
            $customer_data["name"] = trim($customer_data["first_name"] . " " . $customer_data["last_name"]);
            $meta_payload = json_encode([
                "vendor_id" => $vendor_id,
                "target"    => $is_vendor ? "vendor" : "user",
                "username"  => $target_username,
                "site_url"  => $_SERVER['HTTP_HOST'] ?? 'unknown'
            ]);

            // Attempt A: dedicated VA creation endpoint
            $log("Attempting dedicated VA creation (api/virtual-account/create)...");
            $create_res_json = makePayhubRequest("POST", "api/virtual-account/create", [
                "email"    => $email_to_use,
                "name"     => $customer_data["name"],
                "phone"    => $customer_data["phone"],
                "metadata" => $meta_payload
            ], $vendor_id, $is_vendor);
            $create_res = json_decode($create_res_json, true);

            // Attempt B: transaction initialization fallback
            // Uses 100 (100 kobo = ₦1, PayHub minimum) — amount=0 is rejected by the API
            if (($create_res["status"] ?? "") !== "success") {
                $log("Dedicated creation unsupported/failed. Falling back to transaction/initialize...");
                $create_res_json = makePayhubRequest("POST", "api/transaction/initialize", [
                    "email"     => $email_to_use,
                    "amount"    => 100,
                    "name"      => $customer_data["name"],
                    "phone"     => $customer_data["phone"],
                    "reference" => "VA_GEN_" . $vendor_id . "_" . time(),
                    "metadata"  => $meta_payload
                ], $vendor_id, $is_vendor);
                $create_res = json_decode($create_res_json, true);
            }

            if (($create_res["status"] ?? "") === "success") {
                $inner_raw = $create_res["json_result"] ?? "";
                $inner     = json_decode($inner_raw, true);
                $log("Creation/init API success. Parsing for VA details...");

                // Walk all known VA nesting patterns in the creation response
                $va_candidates = [
                    $inner["data"]["virtual_account"] ?? null,
                    $inner["virtual_account"] ?? null,
                    $inner["data"]["data"] ?? null,
                    $inner["data"] ?? null,
                    $inner ?? null,
                ];

                $va_saved = false;
                foreach ($va_candidates as $va) {
                    if (!is_array($va)) continue;
                    $accno = $va["account_number"] ?? ($va["accountNumber"] ?? "");
                    if (empty($accno)) continue;
                    $ref = $inner["data"]["reference"] ?? ($inner["reference"] ?? ($inner["data"]["id"] ?? ""));
                    if ($save_account($va, $ref)) {
                        $result["success"] = true;
                        $result["message"] = "Account generated successfully";
                        $va_saved = true;
                        $log("Saved VA from creation response: $accno");
                        break;
                    }
                }

                // VA details not in creation response — re-run fetch endpoints
                if (!$va_saved) {
                    $log("VA absent in creation response. Re-running fetch endpoints...");
                    foreach ($fetch_endpoints as $ep_info) {
                        $rep         = $ep_info["ep"];
                        $rep_specific = $ep_info["specific"];
                        $rj   = makePayhubRequest("GET", $rep, "", $vendor_id, $is_vendor);
                        $rl   = json_decode($rj, true);
                        if (($rl["status"] ?? "") !== "success") continue;
                        $accs = $extract_accounts(json_decode($rl["json_result"] ?? "", true));
                        foreach ($accs as $acc) {
                            if ($is_account_match($acc, $rep_specific)) {
                                if ($save_account($acc)) {
                                    $result["success"] = true;
                                    $result["message"] = "Account fetched post-generation via $rep";
                                    $va_saved = true;
                                    $log("Saved via post-creation retry from $rep");
                                    break 2;
                                }
                            }
                        }
                    }
                    if (!$va_saved && !$result["success"]) {
                        $result["message"] = "Account may exist in PayHub but details could not be retrieved. Click RE-SYNC to retry.";
                    }
                }
            } else {
                $err_msg = $create_res["message"] ?? ($res["message"] ?? "API Error");
                $log("Generation failed: " . $err_msg);
                if (!$result["success"]) $result["message"] = "Generation failed: " . $err_msg;
            }
        }
    }

    return $result;
}

function identifyISP($phoneNumber)
{
	// Define the carrier prefixes
	$carrierMTN = array("702", "703", "704", "706", "707", "803", "806", "810", "813", "814", "816", "903", "906", "913", "916");
	$carrierAirtel = array("701", "708", "802", "808", "812", "901", "902", "904", "907", "911", "912");
	$carrierGlo = array("705", "805", "807", "811", "815", "905", "915");
	$carrier9mobile = array("809", "817", "818", "908", "909");

	// Extract the 2nd to 4th digits from the phone number (stems)
	$prefix = substr($phoneNumber, 1, 3);

	// Identify the ISP
	if (in_array($prefix, $carrierMTN)) {
		return "mtn";
	} elseif (in_array($prefix, $carrierAirtel)) {
		return "airtel";
	} elseif (in_array($prefix, $carrierGlo)) {
		return "glo";
	} elseif (in_array($prefix, $carrier9mobile)) {
		return "9mobile";
	} else {
		return "Unknown";
	}
}

function calculate_purchase_streak($user_id) {
    global $connection_server;
    $vendor_id = get_user_info($user_id, 'vendor_id');

    $stmt = mysqli_prepare($connection_server, "SELECT DISTINCT DATE(`date`) as purchase_date FROM `sas_transactions` WHERE `vendor_id` = ? AND `username` = ? AND `status` = 1 ORDER BY `purchase_date` DESC");
    mysqli_stmt_bind_param($stmt, "is", $vendor_id, $user_id);
    mysqli_stmt_execute($stmt);
    $streak_query = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($streak_query) == 0) {
        return 0;
    }

    $dates = [];
    while ($row = mysqli_fetch_assoc($streak_query)) {
        $dates[] = $row['purchase_date'];
    }

    try {
        $latest_purchase_date = new DateTime($dates[0]);
        $today = new DateTime('today');
        $yesterday = new DateTime('yesterday');
    } catch (Exception $e) {
        return 0;
    }

    if ($latest_purchase_date < $yesterday) {
        return 0;
    }

    $streak = 0;
    $check_date = ($latest_purchase_date->format('Y-m-d') == $today->format('Y-m-d')) ? $today : $yesterday;

    foreach ($dates as $purchase_date_str) {
        if ($purchase_date_str == $check_date->format('Y-m-d')) {
            $streak++;
            $check_date->modify('-1 day');
        } else {
            break;
        }
    }

    return $streak;
}

function award_daily_bonus($user_id, $transaction_timestamp) {
    global $connection_server;
    $vendor_id = get_user_info($user_id, 'vendor_id');

    $stmt = mysqli_prepare($connection_server, "SELECT `date` FROM `sas_points_log` WHERE `vendor_id` = ? AND `username` = ? AND `log_type` = 'DAILY_PURCHASE_BONUS' ORDER BY `date` DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "is", $vendor_id, $user_id);
    mysqli_stmt_execute($stmt);
    $last_bonus_query = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($last_bonus_query) > 0) {
        $last_bonus_date = date('Y-m-d', strtotime(mysqli_fetch_assoc($last_bonus_query)['date']));
        $current_date = date('Y-m-d', $transaction_timestamp);
        if ($last_bonus_date == $current_date) {
            return false;
        }
    }

    $streak_day = calculate_purchase_streak($user_id);

    $stmt = mysqli_prepare($connection_server, "SELECT * FROM `sas_loyalty_bonus_settings` WHERE `vendor_id` = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $vendor_id);
    mysqli_stmt_execute($stmt);
    $loyalty_settings_query = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($loyalty_settings_query) == 0) {
        $stmt = mysqli_prepare($connection_server, "INSERT INTO `sas_loyalty_bonus_settings` (`vendor_id`) VALUES (?)");
        mysqli_stmt_bind_param($stmt, "i", $vendor_id);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($connection_server, "SELECT * FROM `sas_loyalty_bonus_settings` WHERE `vendor_id` = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $vendor_id);
        mysqli_stmt_execute($stmt);
        $loyalty_settings_query = mysqli_stmt_get_result($stmt);
    }
    $loyalty_settings = mysqli_fetch_assoc($loyalty_settings_query);

    $streak_day_for_bonus = $streak_day > 0 ? (($streak_day - 1) % 7) + 1 : 1;
    $coins_to_award = $loyalty_settings['day_' . $streak_day_for_bonus . '_bonus'] ?? $loyalty_settings['day_1_bonus'];

    $log_type = 'DAILY_PURCHASE_BONUS';
    $stmt = mysqli_prepare($connection_server, "INSERT INTO `sas_points_log` (`vendor_id`, `username`, `point_amount`, `log_type`, `date`) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))");
    mysqli_stmt_bind_param($stmt, "isisi", $vendor_id, $user_id, $coins_to_award, $log_type, $transaction_timestamp);
    $insert_log_query = mysqli_stmt_execute($stmt);

    if ($insert_log_query) {
        $_SESSION['bonus_message'] = "🎉 Bonus Earned! You received $coins_to_award VTU Coins for maintaining your $streak_day-day purchase streak!";
        bc_maybe_send_coin_guide_email($vendor_id, $user_id);
        return true;
    }
    return false;
}

/**
 * bc_get_coin_settings()
 * Returns the vendor's coin conversion settings: points_conversion_rate,
 * min_points_conversion, and coins_email_threshold (the balance at which the
 * "how to convert" guide email is sent automatically).
 */
if (!function_exists('bc_resolve_referral_id')) {
    /**
     * Resolve a referral code to the referrer's sas_users.id, or "" when it matches nobody.
     *
     * The code is the referrer's USERNAME - that is what web/Register.php matches on, what the web
     * dashboard shares, and what web/api/referral.php hands out. The legacy api/app-backend/ stack
     * instead issued base64_encode(username) (see api/app-backend/app-info.php "referral-code"), so
     * a code copied out of an older build would silently fail to credit anyone. Both forms are
     * accepted here, which is what makes the two generations of app interoperable.
     */
    function bc_resolve_referral_id($db, $vendor_id, $code) {
        if (!$db) return "";
        $code = strtolower(trim((string) $code));
        if ($code === '') return "";

        $candidates = [$code];

        // Legacy base64(username). Only attempted when it decodes to something username-shaped, so
        // a genuine username that happens to be valid base64 is still matched directly first.
        $decoded = base64_decode($code, true);
        if ($decoded !== false && $decoded !== '' && preg_match('/^[A-Za-z0-9_.\-]{3,225}$/', $decoded)) {
            $candidates[] = strtolower($decoded);
        }

        $vid = (int) $vendor_id;
        foreach ($candidates as $candidate) {
            $stmt = mysqli_prepare($db, "SELECT id FROM sas_users WHERE vendor_id = ? AND LOWER(username) = ? LIMIT 1");
            if (!$stmt) continue;
            mysqli_stmt_bind_param($stmt, "is", $vid, $candidate);
            mysqli_stmt_execute($stmt);
            $q = mysqli_stmt_get_result($stmt);
            $row = $q ? mysqli_fetch_assoc($q) : null;
            if ($row) return (string) $row['id'];
        }
        return "";
    }
}

function bc_get_coin_settings($vendor_id) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    $settings = ['points_conversion_rate' => 500, 'min_points_conversion' => 100, 'coins_email_threshold' => 0];
    if (!$connection_server || $vendor_id <= 0) return $settings;
    $stmt = mysqli_prepare($connection_server, "SELECT setting_name, setting_value FROM sas_settings WHERE vendor_id = ? AND setting_name IN ('points_conversion_rate', 'min_points_conversion', 'coins_email_threshold')");
    mysqli_stmt_bind_param($stmt, "i", $vendor_id);
    mysqli_stmt_execute($stmt);
    $q = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($q)) {
        if ($row['setting_name'] === 'points_conversion_rate') $settings['points_conversion_rate'] = (float)$row['setting_value'];
        elseif ($row['setting_name'] === 'min_points_conversion') $settings['min_points_conversion'] = (int)$row['setting_value'];
        elseif ($row['setting_name'] === 'coins_email_threshold') $settings['coins_email_threshold'] = (int)$row['setting_value'];
    }
    return $settings;
}

/**
 * bc_get_user_email()
 * Returns a user's email address.
 */
function bc_get_user_email($vendor_id, $username) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    if (!$connection_server || $vendor_id <= 0 || empty($username)) return '';
    $stmt = mysqli_prepare($connection_server, "SELECT email FROM sas_users WHERE vendor_id = ? AND username = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
    mysqli_stmt_execute($stmt);
    $q = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($q);
    return $row ? $row['email'] : '';
}

/**
 * bc_get_vendor_admin_email()
 * Returns the vendor's admin email address.
 */
function bc_get_vendor_admin_email($vendor_id) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    if (!$connection_server || $vendor_id <= 0) return '';
    $q = mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id = '$vendor_id' LIMIT 1");
    $r = mysqli_fetch_assoc($q);
    return $r ? $r['email'] : '';
}

/**
 * bc_maybe_send_coin_guide_email()
 * Sends the "how to convert your coins" guide email once the user's coin balance
 * reaches the coins_email_threshold set by bc-admin. Tracked in sas_coin_notify_log
 * so the guide is sent only once per user.
 */
function bc_maybe_send_coin_guide_email($vendor_id, $username) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    if (!$connection_server || $vendor_id <= 0 || empty($username)) return;

    $settings = bc_get_coin_settings($vendor_id);
    $threshold = (int)$settings['coins_email_threshold'];
    if ($threshold <= 0) return; // feature disabled

    mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_coin_notify_log (id INT AUTO_INCREMENT PRIMARY KEY, vendor_id INT NOT NULL, username VARCHAR(225) NOT NULL, points_at_time INT NOT NULL, notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_coin_notify (vendor_id, username))");

    $total_points = (int)(get_user_vtu_details($username)['total_points'] ?? 0);
    if ($total_points < $threshold) return;

    $stmt = mysqli_prepare($connection_server, "SELECT id FROM sas_coin_notify_log WHERE vendor_id = ? AND username = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
    mysqli_stmt_execute($stmt);
    $check = mysqli_stmt_get_result($stmt);
    if (mysqli_num_rows($check) > 0) return;

    $user_email = bc_get_user_email($vendor_id, $username);
    if (empty($user_email)) return;

    $rate = (float)$settings['points_conversion_rate'];
    $min_points = (int)$settings['min_points_conversion'];
    $value = $total_points / ($rate > 0 ? $rate : 1);

    $subject = "You can now convert your coins to wallet funds!";
    $body = "Hi " . htmlspecialchars($username) . ",<br><br>"
        . "You now have <b>" . number_format($total_points) . " coins</b>, worth about <b>&#8358;" . number_format($value, 2) . "</b>.<br><br>"
        . "You can convert your coins into wallet funds in a few easy steps:<br>"
        . "<ol>"
        . "<li>Log in to your account.</li>"
        . "<li>Open <b>Coin Conversion</b> (or the Coins section in the app).</li>"
        . "<li>Enter the number of coins to convert (minimum " . number_format($min_points) . " coins).</li>"
        . "<li>Submit your request — our team will review and approve it.</li>"
        . "</ol>"
        . "Current rate: <b>" . number_format($rate) . " coins = &#8358;1.00</b>.<br><br>"
        . "Once approved, the equivalent amount is credited directly to your wallet balance.<br><br>"
        . "Best regards,<br>Support Team";

    $sent = sendVendorEmail($user_email, $subject, $body);
    if ($sent) {
        $stmt = mysqli_prepare($connection_server, "INSERT IGNORE INTO sas_coin_notify_log (vendor_id, username, points_at_time) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isi", $vendor_id, $username, $total_points);
        mysqli_stmt_execute($stmt);
    }
}

/**
 * bc_send_admin_coin_conversion_email()
 * Notifies the vendor admin that a user submitted a coin conversion request.
 */
function bc_send_admin_coin_conversion_email($vendor_id, $username, $points, $amount) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    if (!$connection_server || $vendor_id <= 0) return;
    $admin_email = bc_get_vendor_admin_email($vendor_id);
    if (empty($admin_email)) return;

    $subject = "New Coin Conversion Request";
    $body = "A user has submitted a coin conversion request:<br><br>"
        . "<b>Username:</b> " . htmlspecialchars($username) . "<br>"
        . "<b>Points:</b> " . number_format((int)$points) . "<br>"
        . "<b>Amount:</b> &#8358;" . number_format((float)$amount, 2) . "<br><br>"
        . "Please review and approve or reject it from the admin panel (Coin Conversions).<br><br>"
        . "Best regards,<br>System Notification";
    sendVendorEmail($admin_email, $subject, $body);
}

/**
 * bc_send_user_coin_conversion_status_email()
 * Notifies the user when their coin conversion request is approved or rejected.
 */
function bc_send_user_coin_conversion_status_email($vendor_id, $username, $status, $points, $amount) {
    global $connection_server;
    $vendor_id = (int)$vendor_id;
    if (!$connection_server || $vendor_id <= 0 || empty($username)) return;
    $user_email = bc_get_user_email($vendor_id, $username);
    if (empty($user_email)) return;

    if ($status === 'approved') {
        $subject = "Your coin conversion was approved";
        $body = "Good news! Your coin conversion request has been <b>approved</b>.<br><br>"
            . "<b>Points converted:</b> " . number_format((int)$points) . "<br>"
            . "<b>Amount credited:</b> &#8358;" . number_format((float)$amount, 2) . "<br><br>"
            . "The amount has been added to your wallet balance and is ready to use.<br><br>"
            . "Best regards,<br>Support Team";
    } else {
        $subject = "Your coin conversion was declined";
        $body = "Your coin conversion request was <b>declined</b>.<br><br>"
            . "<b>Points requested:</b> " . number_format((int)$points) . "<br>"
            . "<b>Amount:</b> &#8358;" . number_format((float)$amount, 2) . "<br><br>"
            . "Your coins remain in your balance. Please contact support if you have questions.<br><br>"
            . "Best regards,<br>Support Team";
    }
    sendVendorEmail($user_email, $subject, $body);
}

function get_user_vtu_details($username) {
    global $connection_server;
    $vendor_id = get_user_info($username, 'vendor_id');

    // Calculate earned points
    $stmt = mysqli_prepare($connection_server, "SELECT SUM(point_amount) as total_points FROM sas_points_log WHERE vendor_id = ? AND username = ?");
    mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
    mysqli_stmt_execute($stmt);
    $points_query = mysqli_stmt_get_result($stmt);
    $earned_points = mysqli_fetch_assoc($points_query)['total_points'] ?: 0;

    // Calculate points tied up in pending conversions
    $stmt = mysqli_prepare($connection_server, "SELECT SUM(points) as total_pending FROM sas_conversions WHERE vendor_id = ? AND username = ? AND status = 'pending'");
    mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
    mysqli_stmt_execute($stmt);
    $conversions_query = mysqli_stmt_get_result($stmt);
    $pending_points = mysqli_fetch_assoc($conversions_query)['total_pending'] ?: 0;

    $total_points = $earned_points - $pending_points;

    $stmt = mysqli_prepare($connection_server, "SELECT date FROM sas_points_log WHERE vendor_id = ? AND username = ? AND log_type = 'DAILY_PURCHASE_BONUS' ORDER BY date DESC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
    mysqli_stmt_execute($stmt);
    $last_bonus_query = mysqli_stmt_get_result($stmt);

    $last_bonus_time = null;
    if (mysqli_num_rows($last_bonus_query) > 0) {
        $last_bonus_time = strtotime(mysqli_fetch_assoc($last_bonus_query)['date']);
    }

    $is_eligible = true;
    $next_bonus_time = null;
    if ($last_bonus_time) {
        $next_bonus_time = $last_bonus_time + (24 * 60 * 60);
        if (time() < $next_bonus_time) {
            $is_eligible = false;
        }
    }

    $streak_day = calculate_purchase_streak($username);

    return [
        'total_points' => $total_points,
        'is_eligible' => $is_eligible,
        'next_bonus_time' => $next_bonus_time ? date('h:i A \o\n l', $next_bonus_time) : null,
        'streak_day' => $streak_day,
    ];
}

// Anti-BruteForce Functions
function getBruteForceSettings($vendor_id) {
    global $connection_server;
    $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_bruteforce_settings WHERE vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $vendor_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        return $row;
    }
    // Return defaults if not set
    return [
        'is_enabled' => 1,
        'period_mins' => 10,
        'max_failures_account' => 5,
        'max_failures_ip' => 10,
        'block_duration' => 'one-day',
        'lock_admin' => 0,
        'notify_admin' => 1
    ];
}

function isIPBlocked($ip, $vendor_id) {
    global $connection_server;

    if ($vendor_id == 0) return false; // Exempt Super Admin

    $settings = getBruteForceSettings($vendor_id);
    if ($settings['is_enabled'] == 0) return false;

    // Check country-based block first
    $country_code = getCountryCodeFromIP($ip);
    if ($country_code) {
        $stmt = mysqli_prepare($connection_server, "SELECT status FROM sas_country_security WHERE country_code = ? AND vendor_id = ?");
        mysqli_stmt_bind_param($stmt, "si", $country_code, $vendor_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            if ($row['status'] == 'Blacklisted') return "Blocked by Country Policy";
        }
    }

    $stmt = mysqli_prepare($connection_server, "SELECT block_until FROM sas_blocked_ips WHERE ip_address = ? AND vendor_id = ? AND block_until > NOW()");
    mysqli_stmt_bind_param($stmt, "si", $ip, $vendor_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        return "Blocked until " . $row['block_until'];
    }
    // No active block — lazily release any expired auto-block so the IP recovers automatically.
    bc_release_expired_blocks('', $ip, $vendor_id);
    return false;
}

function isAccountLocked($username, $vendor_id) {
    global $connection_server;

    if ($vendor_id == 0) return false; // Exempt Super Admin

    $settings = getBruteForceSettings($vendor_id);
    if ($settings['is_enabled'] == 0) return false;

    $stmt = mysqli_prepare($connection_server, "SELECT block_until FROM sas_blocked_accounts WHERE username = ? AND vendor_id = ? AND block_until > NOW()");
    mysqli_stmt_bind_param($stmt, "si", $username, $vendor_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        return "Locked until " . $row['block_until'];
    }
    // No active block — lazily release any expired auto-block so the account recovers automatically.
    bc_release_expired_blocks($username, '', $vendor_id);
    return false;
}

function recordLoginAttempt($username, $ip, $success, $vendor_id) {
    global $connection_server;

    if ($vendor_id == 0) return; // Exempt Super Admin

    $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_login_attempts (vendor_id, username, ip_address, success) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "issi", $vendor_id, $username, $ip, $success);
    mysqli_stmt_execute($stmt);

    $settings = getBruteForceSettings($vendor_id);
    if ($settings['is_enabled'] == 0 && !$success) return; // Don't process failures if disabled

    $period_mins = $settings['period_mins'];

    if ($success) {
        // Success logic: automatic whitelisting after 5 successful unique sessions
        $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_ip_whitelist (ip_address, vendor_id, success_count) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE success_count = success_count + 1");
        mysqli_stmt_bind_param($stmt, "si", $ip, $vendor_id);
        mysqli_stmt_execute($stmt);

        // Reset failed counts on successful login
        mysqli_query($connection_server, "UPDATE sas_users SET failed_login_count = 0, is_blocked = 0 WHERE username = '$username' AND vendor_id = '$vendor_id'");
        mysqli_query($connection_server, "UPDATE sas_vendors SET failed_login_count = 0, is_blocked = 0 WHERE email = '$username' AND id = '$vendor_id'");
        mysqli_query($connection_server, "UPDATE sas_super_admin SET failed_login_count = 0, is_blocked = 0 WHERE email = '$username'");
        return;
    }

    // Failure logic
    // 1. Check IP failures
    $stmt = mysqli_prepare($connection_server, "SELECT COUNT(*) as failures FROM sas_login_attempts WHERE vendor_id = ? AND ip_address = ? AND success = 0 AND timestamp > (NOW() - INTERVAL ? MINUTE)");
    mysqli_stmt_bind_param($stmt, "isi", $vendor_id, $ip, $period_mins);
    mysqli_stmt_execute($stmt);
    $ip_failures = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['failures'];

    if ($ip_failures >= $settings['max_failures_ip']) {
        blockIP($ip, $vendor_id, $settings['block_duration'], "Exceeded max IP failures ($ip_failures)");
    }

    // 2. Check Account failures
    if (!empty($username)) {
        $user_found = false;

        // Check sas_users
        $check_user = mysqli_query($connection_server, "SELECT id FROM sas_users WHERE username = '$username' AND vendor_id = '$vendor_id'");
        if ($check_user && mysqli_num_rows($check_user) > 0) {
            $user_found = true;
            mysqli_query($connection_server, "UPDATE sas_users SET failed_login_count = failed_login_count + 1, last_failed_login = NOW() WHERE username = '$username' AND vendor_id = '$vendor_id'");
        }

        // Check sas_vendors
        $check_vendor = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE email = '$username' AND id = '$vendor_id'");
        if ($check_vendor && mysqli_num_rows($check_vendor) > 0) {
            $user_found = true;
            mysqli_query($connection_server, "UPDATE sas_vendors SET failed_login_count = failed_login_count + 1, last_failed_login = NOW() WHERE email = '$username' AND id = '$vendor_id'");
        }

        // Check sas_super_admin
        $check_spadmin = mysqli_query($connection_server, "SELECT id FROM sas_super_admin WHERE email = '$username'");
        if ($check_spadmin && mysqli_num_rows($check_spadmin) > 0) {
            $user_found = true;
            mysqli_query($connection_server, "UPDATE sas_super_admin SET failed_login_count = failed_login_count + 1, last_failed_login = NOW() WHERE email = '$username'");
        }

        if ($user_found) {
            $stmt = mysqli_prepare($connection_server, "SELECT COUNT(*) as failures FROM sas_login_attempts WHERE vendor_id = ? AND username = ? AND success = 0 AND timestamp > (NOW() - INTERVAL ? MINUTE)");
            mysqli_stmt_bind_param($stmt, "isi", $vendor_id, $username, $period_mins);
            mysqli_stmt_execute($stmt);
            $acc_failures = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['failures'];

            if ($acc_failures >= $settings['max_failures_account']) {
                lockAccount($username, $vendor_id, $settings['block_duration'], "Exceeded max account failures ($acc_failures)");
            }
        } else {
            // User does not exist, block IP immediately
            blockIP($ip, $vendor_id, $settings['block_duration'], "Attempting to login with non-existent user: $username");
        }
    }
}

function blockIP($ip, $vendor_id, $duration_type, $reason) {
    global $connection_server;
    $durations = ['one-day' => 1, 'one-week' => 7, 'one-month' => 30, 'one-year' => 365];
    $days = $durations[$duration_type] ?? 1;
    $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_blocked_ips (ip_address, vendor_id, block_until, reason) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?) ON DUPLICATE KEY UPDATE block_until = VALUES(block_until), reason = VALUES(reason)");
    mysqli_stmt_bind_param($stmt, "siis", $ip, $vendor_id, $days, $reason);
    mysqli_stmt_execute($stmt);

    // Send Alert
    $get_vendor = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='$vendor_id'"));
    $admin_email = $get_vendor['email'] ?? 'admin@philmorecodes.com';
    $subject = "URGENT SECURITY ALERT: IP Blocked";
    $body = "The IP address <b>$ip</b> has been blocked.<br><b>Reason:</b> $reason<br><b>Duration:</b> $duration_type";
    sendVendorEmail($admin_email, $subject, $body);
}

function lockAccount($username, $vendor_id, $duration_type, $reason) {
    global $connection_server;
    $durations = ['one-day' => 1, 'one-week' => 7, 'one-month' => 30, 'one-year' => 365];
    $days = $durations[$duration_type] ?? 1;
    $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_blocked_accounts (username, vendor_id, block_until, reason) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?) ON DUPLICATE KEY UPDATE block_until = VALUES(block_until), reason = VALUES(reason)");
    mysqli_stmt_bind_param($stmt, "siis", $username, $vendor_id, $days, $reason);
    mysqli_stmt_execute($stmt);

    // Also set is_blocked = 1
    mysqli_query($connection_server, "UPDATE sas_users SET is_blocked = 1 WHERE username = '$username' AND vendor_id = '$vendor_id'");
    mysqli_query($connection_server, "UPDATE sas_vendors SET is_blocked = 1 WHERE email = '$username' AND id = '$vendor_id'");
    mysqli_query($connection_server, "UPDATE sas_super_admin SET is_blocked = 1 WHERE email = '$username'");

    // Send Alert to User
    $user_email = "";
    $check_u = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_users WHERE username='$username' AND vendor_id='$vendor_id'"));
    if($check_u) $user_email = $check_u['email'];
    else {
        $check_v = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE email='$username' AND id='$vendor_id'"));
        if($check_v) $user_email = $check_v['email'];
        else {
            $check_s = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_super_admin WHERE email='$username'"));
            if($check_s) $user_email = $check_s['email'];
        }
    }

    if(!empty($user_email)) {
        $subject = "URGENT SECURITY ALERT: Account Locked";
        $body = "Your account <b>$username</b> has been locked due to security policy violations.<br><b>Reason:</b> $reason<br>Please use the Self-Help PIN option or contact support.";
        sendVendorEmail($user_email, $subject, $body);
    }
}

/**
 * bc_is_global_service_enabled()
 * Reads the Super-Admin GLOBAL service control (sas_global_service_control).
 * Used by the bc-spadmin "general control" (e.g. the password-reset kill switch).
 * In standalone (NON-SAAS) deployments there is no global table, so this returns
 * true and the vendor-local sas_service_control setting applies instead.
 */
function bc_is_global_service_enabled($name) {
    global $connection_server;
    static $global_service_cache = null;
    if ($global_service_cache === null) {
        $global_service_cache = [];
        if ($connection_server) {
            $gc_q = mysqli_query($connection_server, "SELECT service_name, status FROM sas_global_service_control");
            if ($gc_q) {
                while ($gc_r = mysqli_fetch_assoc($gc_q)) {
                    $global_service_cache[$gc_r['service_name']] = (int)$gc_r['status'];
                }
            }
        }
    }
    $name = strtolower(trim($name));
    return isset($global_service_cache[$name]) ? (bool)$global_service_cache[$name] : true;
}

/**
 * bc_handle_password_reset_attempt()
 * Anti-bruteforce for the password-reset flow (web users, admins and the app API).
 * Mirrors recordLoginAttempt() but on a dedicated table (sas_password_reset_attempts)
 * so login and reset counters never interfere with each other.
 *
 * Every reset request and every failed code verification counts as an attempt; once
 * the configured threshold is reached the TARGET ACCOUNT is locked (sas_blocked_accounts
 * + is_blocked=1) and/or the SOURCE IP is blocked. This is the exact same lock that the
 * Security-PIN self-help page (web/LockoutResolution.php) clears — so the real account
 * owner can always unlock "as usual", but a brute-forcing attacker cannot.
 */
function bc_handle_password_reset_attempt($username, $ip, $success, $vendor_id, $type = 'request') {
    global $connection_server;
    if ($vendor_id <= 0 || !$connection_server) return; // Super admin exempt (matches login)

    // Idempotent one-time-per-request table ensure (also created by bc-tables.php).
    static $reset_table_checked = false;
    if (!$reset_table_checked) {
        $reset_table_checked = true;
        mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_password_reset_attempts (
            id INT NOT NULL AUTO_INCREMENT,
            vendor_id INT UNSIGNED NOT NULL,
            username VARCHAR(225) DEFAULT NULL,
            ip_address VARCHAR(50) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempt_type VARCHAR(30) NOT NULL DEFAULT 'request',
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_reset_lookup (vendor_id, ip_address, timestamp),
            KEY idx_reset_user (vendor_id, username, timestamp)
        )");
    }

    $vendor_id = (int)$vendor_id;
    $username  = mysqli_real_escape_string($connection_server, trim((string)$username));
    $ip        = mysqli_real_escape_string($connection_server, trim((string)$ip));
    $type      = ($type === 'verify') ? 'verify' : 'request';
    $success   = $success ? 1 : 0;

    mysqli_query($connection_server, "INSERT INTO sas_password_reset_attempts (vendor_id, username, ip_address, success, attempt_type) VALUES ('$vendor_id', '$username', '$ip', $success, '$type')");

    $settings = getBruteForceSettings($vendor_id);
    if (empty($settings['is_enabled'])) return; // master brute-force switch turned off

    $period = max(1, (int)$settings['period_mins']);

    // A fully successful reset (correct code + password actually changed) clears the
    // anti-bruteforce failure counters, but keeps the success row so the per-account
    // DAILY reset limit (bc_get_password_reset_daily_limit) can be enforced.
    if ($success) {
        mysqli_query($connection_server, "DELETE FROM sas_password_reset_attempts WHERE vendor_id='$vendor_id' AND success=0 AND (ip_address='$ip' OR (username='$username' AND username <> ''))");
        return;
    }

    // Block the source IP after repeated probes (e.g. non-existent usernames).
    $r = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_password_reset_attempts WHERE vendor_id='$vendor_id' AND ip_address='$ip' AND success=0 AND timestamp > (NOW() - INTERVAL $period MINUTE)"));
    if ($r && (int)$r['c'] >= (int)$settings['max_failures_ip']) {
        blockIP($ip, $vendor_id, $settings['block_duration'], "Exceeded max password-reset IP failures (" . $r['c'] . ")");
    }

    // Lock the target account after repeated reset attempts against it.
    if (!empty($username)) {
        $r2 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_password_reset_attempts WHERE vendor_id='$vendor_id' AND username='$username' AND success=0 AND timestamp > (NOW() - INTERVAL $period MINUTE)"));
        if ($r2 && (int)$r2['c'] >= (int)$settings['max_failures_account']) {
            lockAccount($username, $vendor_id, $settings['block_duration'], "Exceeded max password-reset account failures (" . $r2['c'] . ")");
        }
    }
}

/**
 * bc_get_password_reset_daily_limit()
 * Returns how many times an account password may be reset per day. Controlled globally
 * by the Super Admin (SAAS bc-spadmin AccountSettings → Security tab) or by the
 * standalone admin (NON-SAAS bc-admin AccountSettings → Security tab), stored in
 * sas_super_admin_options. Defaults to 1 when unset.
 */
function bc_get_password_reset_daily_limit($vendor_id = null) {
    global $connection_server;
    if (!$connection_server) return 1;
    $q = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='password_reset_daily_limit' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $limit = (int)$r['option_value'];
        return $limit >= 1 ? $limit : 1;
    }
    return 1;
}

/**
 * bc_is_password_reset_blocked()
 * Returns a human-readable lock message (or false) when password reset must be refused
 * for this account/IP. Reuses the exact same sas_blocked_ips / sas_blocked_accounts
 * tables that login checks and that LockoutResolution.php clears with the Security PIN.
 * Also enforces the per-account DAILY password-reset limit.
 */
function bc_is_password_reset_blocked($username, $ip, $vendor_id) {
    global $connection_server;
    if ($vendor_id <= 0 || !$connection_server) return false;
    if ($msg = isIPBlocked($ip, $vendor_id)) return $msg;
    if (!empty($username)) {
        if ($msg = isAccountLocked($username, $vendor_id)) return $msg;
        // Per-account DAILY password-reset limit.
        $daily_limit = bc_get_password_reset_daily_limit($vendor_id);
        if ($daily_limit > 0) {
            $esc_user = mysqli_real_escape_string($connection_server, $username);
            $r = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_password_reset_attempts WHERE vendor_id='$vendor_id' AND username='$esc_user' AND success=1 AND DATE(timestamp)=CURDATE()"));
            if ($r && (int)$r['c'] >= $daily_limit) {
                return "Daily password-reset limit reached (" . $daily_limit . " per day). Please try again tomorrow.";
            }
        }
    }
    return false;
}

/**
 * bc_release_expired_blocks()
 * Lazy auto-release of EXPIRED brute-force auto-blocks — this is what makes a
 * "1-day block" automatically lift WITHOUT a cronjob.
 *
 * If an account/IP has a sas_blocked_accounts / sas_blocked_ips row whose
 * block_until has already passed, that row is removed and the is_blocked flag on
 * the matching user/vendor is cleared. MANUAL blocks (is_blocked=1 with no block
 * row, e.g. set from the admin panel) are never touched.
 */
function bc_release_expired_blocks($username, $ip, $vendor_id) {
    global $connection_server;
    if ($vendor_id <= 0 || !$connection_server) return;

    // 1) Account auto-block
    if ($username !== '') {
        $esc_user = mysqli_real_escape_string($connection_server, (string)$username);
        $row = @mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT block_until FROM sas_blocked_accounts WHERE username='$esc_user' AND vendor_id='$vendor_id' LIMIT 1"));
        if ($row && isset($row['block_until']) && strtotime($row['block_until']) <= time()) {
            mysqli_query($connection_server, "DELETE FROM sas_blocked_accounts WHERE username='$esc_user' AND vendor_id='$vendor_id'");
            // Release the flag only if no OTHER active auto-block remains for this account.
            mysqli_query($connection_server, "UPDATE sas_users SET is_blocked=0 WHERE username='$esc_user' AND vendor_id='$vendor_id' AND is_blocked=1 AND NOT EXISTS (SELECT 1 FROM sas_blocked_accounts b WHERE b.username='$esc_user' AND b.vendor_id='$vendor_id' AND b.block_until > NOW())");
            mysqli_query($connection_server, "UPDATE sas_vendors SET is_blocked=0 WHERE email='$esc_user' AND id='$vendor_id' AND is_blocked=1 AND NOT EXISTS (SELECT 1 FROM sas_blocked_accounts b WHERE b.username='$esc_user' AND b.vendor_id='$vendor_id' AND b.block_until > NOW())");
        }
    }

    // 2) IP auto-block
    if ($ip !== '') {
        $esc_ip = mysqli_real_escape_string($connection_server, (string)$ip);
        $row = @mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT block_until FROM sas_blocked_ips WHERE ip_address='$esc_ip' AND vendor_id='$vendor_id' LIMIT 1"));
        if ($row && isset($row['block_until']) && strtotime($row['block_until']) <= time()) {
            mysqli_query($connection_server, "DELETE FROM sas_blocked_ips WHERE ip_address='$esc_ip' AND vendor_id='$vendor_id'");
        }
    }
}

function getCountryCodeFromIP($ip) {
    // Basic mock for country detection, in production use GeoIP2
    return 'NG';
}

function isIPWhitelisted($ip, $vendor_id) {
    global $connection_server;
    $stmt = mysqli_prepare($connection_server, "SELECT success_count FROM sas_ip_whitelist WHERE ip_address = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "si", $ip, $vendor_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) {
        return $row['success_count'] >= 5;
    }
    return false;
}

function verifyGoogleToken($id_token) {
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $id_token;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function generateOTP($length = 6) {
    return substr(str_shuffle("0123456789"), 0, $length);
}

function seedVendorBlog($vendor_id) {
    global $connection_server;

    // 1. Ensure categories exist
    $categories = [
        ['name' => 'Platform Overview', 'slug' => 'platform-overview'],
        ['name' => 'User Guide', 'slug' => 'user-guide']
    ];

    $cat_ids = [];
    foreach ($categories as $cat) {
        $name = $cat['name'];
        $slug = $cat['slug'];
        $check = mysqli_query($connection_server, "SELECT id FROM blog_categories WHERE slug='$slug' LIMIT 1");
        if (mysqli_num_rows($check) == 0) {
            mysqli_query($connection_server, "INSERT INTO blog_categories (name, slug) VALUES ('$name', '$slug')");
            $cat_ids[$slug] = mysqli_insert_id($connection_server);
        } else {
            $cat_ids[$slug] = mysqli_fetch_assoc($check)['id'];
        }
    }

    // 2. Platform Overview Post
    $title1 = "Platform Features & Services Overview";
    $slug1 = "platform-features-services-overview";
    $content1 = "<h3>Welcome to the Ultimate Fintech & VTU Platform</h3>
    <p>Our script is a comprehensive solution designed for multi-tenant VTU business operations. Below is a detailed overview of the features and services available.</p>

    <h4>Core Features</h4>
    <ul>
        <li><strong>Automated Fulfillment:</strong> Seamless integration with major API providers like VTPass, ClubKonnect, and more.</li>
        <li><strong>Multi-Level Pricing:</strong> Support for Smart User, Agent, and API Vendor pricing tiers.</li>
        <li><strong>Security Hardening:</strong> Anti-BruteForce protection, IP blocking, and KYC validation (BVN/NIN).</li>
        <li><strong>Fintech Modern UI:</strong> Clean, responsive Bootstrap 5 design optimized for mobile and desktop.</li>
    </ul>

    <h4>Services Offered</h4>
    <ul>
        <li><strong>Airtime VTU:</strong> Instant top-up for MTN, Airtel, Glo, and 9mobile.</li>
        <li><strong>Data Bundles:</strong> Comprehensive data types including SME, CG (Cloud Gifting), Direct Data, Corporate Gifting, and Shared Data.</li>
        <li><strong>Cable TV:</strong> Subscriptions for DSTV, GOTV, and Startimes.</li>
        <li><strong>Electricity Tokens:</strong> Prepaid and Postpaid meter tokens for all major DISCOs.</li>
        <li><strong>Exam PINs:</strong> Result checker pins for WAEC, NECO, and NABTEB.</li>
        <li><strong>Bulk SMS:</strong> Personalized sender ID support for mass messaging.</li>
    </ul>

    <h4>Management Panels</h4>
    <ul>
        <li><strong>Super Portal (bc-spadmin):</strong> Master control for managing vendors, site-wide subscriptions, API marketplace, and system health.</li>
        <li><strong>Vendor Portal (bc-admin):</strong> Dedicated panel for vendors to manage their users, set service prices, configure API gateways, and view detailed sales reports.</li>
        <li><strong>User Portal (web):</strong> Intuitive dashboard for end-users to fund wallets and purchase services with ease.</li>
    </ul>

    <h4>Vendor Account Creation</h4>
    <p>New vendors can subscribe to the platform through a structured billing system. Administrators can create packages with varying durations and prices in the Super Portal. Once registered and verified, vendors get their own sub-domain/website url with a full administrative panel.</p>";

    $check_post1 = mysqli_query($connection_server, "SELECT id FROM blog_posts WHERE author_id='$vendor_id' AND title='$title1' LIMIT 1");
    $img1 = "https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=1200";
    if (mysqli_num_rows($check_post1) == 0) {
        $encoded_content1 = base64_encode($content1);
        mysqli_query($connection_server, "INSERT INTO blog_posts (author_id, title, content, status, featured_image) VALUES ('$vendor_id', '$title1', '$encoded_content1', 'published', '$img1')");
        $post_id1 = mysqli_insert_id($connection_server);
        $cat_id1 = $cat_ids['platform-overview'];
        mysqli_query($connection_server, "INSERT INTO blog_post_categories (post_id, category_id) VALUES ('$post_id1', '$cat_id1')");
    }

    // 3. User Guide Post
    $title2 = "Ultimate User Guide: How to Buy & Transact";
    $slug2 = "ultimate-user-guide-how-to-buy";
    $img2 = "https://images.unsplash.com/photo-1556742111-a301076d9d18?w=1200";
    $content2 = "<h3>Getting Started with Your Wallet</h3>
    <p>Transacting on our platform is fast and secure. Follow these simple steps to get started.</p>

    <h4>1. Register & Verify</h4>
    <p>Create an account by providing your details. Ensure you set a strong transaction PIN to secure your funds.</p>

    <h4>2. Funding Your Wallet</h4>
    <p>Navigate to the 'Fund Wallet' page. You can use automated payment gateways like Monnify, Paystack, or manual bank transfers. Your balance updates instantly after successful payment.</p>

    <h4>3. Buying Services</h4>
    <ul>
        <li><strong>Buy Airtime:</strong> Enter the phone number, select network, and input amount. Confirm with your PIN.</li>
        <li><strong>Buy Data:</strong> Select the data type (e.g., SME), select plan, and enter phone number.</li>
        <li><strong>Utility Bills:</strong> For Electricity and Cable, validate your Meter/IUC number first to ensure accuracy before paying.</li>
    </ul>

    <h4>4. Monitoring Transactions</h4>
    <p>All your activities are recorded in the 'Transactions' page. You can view receipts, check statuses, and even download payment proofs for every transaction.</p>";

    $check_post2 = mysqli_query($connection_server, "SELECT id FROM blog_posts WHERE author_id='$vendor_id' AND title='$title2' LIMIT 1");
    if (mysqli_num_rows($check_post2) == 0) {
        $encoded_content2 = base64_encode($content2);
        mysqli_query($connection_server, "INSERT INTO blog_posts (author_id, title, content, status, featured_image) VALUES ('$vendor_id', '$title2', '$encoded_content2', 'published', '$img2')");
        $post_id2 = mysqli_insert_id($connection_server);
        $cat_id2 = $cat_ids['user-guide'];
        mysqli_query($connection_server, "INSERT INTO blog_post_categories (post_id, category_id) VALUES ('$post_id2', '$cat_id2')");
    }

    // 4. API Integration Post
    $title3 = "How to integrate our API";
    $slug3 = "how-to-integrate-our-api";
    $img3 = "https://images.unsplash.com/photo-1558494949-ef010cbdcc51?w=1200";
    $content3 = "<h3>Developer Integration Guide</h3>
    <p>Scale your business by integrating our lightning-fast VTU & Bill Payment API into your own applications. We provide a RESTful API that is easy to implement and robust.</p>

    <h4>Getting Started</h4>
    <p>To start using our API, you must first be upgraded to the <b>API Vendor</b> level. You can request access via the 'API Docs' page in your dashboard.</p>

    <h4>Authentication</h4>
    <p>All API requests must include your unique <code>api_key</code>. This key should be kept secret and never shared publicly.</p>

    <h4>Sample Code (PHP)</h4>
    <pre>
    \$payload = [
        'api_key' => 'YOUR_API_KEY',
        'network' => 'mtn',
        'amount'  => 500,
        'phone_no' => '08012345678'
    ];

    \$ch = curl_init('https://' . \$_SERVER['HTTP_HOST'] . '/web/api/airtime.php');
    curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt(\$ch, CURLOPT_POSTFIELDS, json_encode(\$payload));
    curl_setopt(\$ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    \$response = curl_exec(\$ch);
    echo \$response;
    </pre>

    <h4>Key Endpoints</h4>
    <ul>
        <li><strong>Airtime:</strong> <code>/web/api/airtime.php</code></li>
        <li><strong>Data:</strong> <code>/web/api/data.php</code></li>
        <li><strong>Cable TV:</strong> <code>/web/api/cable.php</code></li>
        <li><strong>Electricity:</strong> <code>/web/api/electric.php</code></li>
    </ul>

    <p>For a full list of parameters and interactive testing, please visit our <a href='/web/APIDocs.php'>API Documentation</a>.</p>";

    $check_post3 = mysqli_query($connection_server, "SELECT id FROM blog_posts WHERE author_id='$vendor_id' AND title='$title3' LIMIT 1");
    if (mysqli_num_rows($check_post3) == 0) {
        $encoded_content3 = base64_encode($content3);
        mysqli_query($connection_server, "INSERT INTO blog_posts (author_id, title, content, status, featured_image) VALUES ('$vendor_id', '$title3', '$encoded_content3', 'published', '$img3')");
        $post_id3 = mysqli_insert_id($connection_server);
        // Reuse user-guide category or create developer-api
        mysqli_query($connection_server, "INSERT INTO blog_post_categories (post_id, category_id) VALUES ('$post_id3', '".$cat_ids['user-guide']."')");
    }

    // 5. Migration: Update old broken image paths to new URLs
    mysqli_query($connection_server, "UPDATE blog_posts SET featured_image='$img1' WHERE author_id='$vendor_id' AND featured_image='asset/platform-overview.jpg'");
    mysqli_query($connection_server, "UPDATE blog_posts SET featured_image='$img2' WHERE author_id='$vendor_id' AND featured_image='asset/user-guide.jpg'");
}


function reconcileDeposit($gateway, $reference, $account_number, $date, $pin, $vid, $username, $target_type) {
    global $connection_server;

    // 1. PIN Verification
    $is_super_admin = isset($_SESSION['spadmin_session']);
    $vid = (int)$vid;

    if (!$is_super_admin || $pin !== 'SUPER') {
        if ($target_type == 'vendor') {
            $q = mysqli_query($connection_server, "SELECT security_pin FROM sas_vendors WHERE id='$vid' LIMIT 1");
        } else {
            $u_esc = mysqli_real_escape_string($connection_server, $username);
            $q = mysqli_query($connection_server, "SELECT security_pin FROM sas_users WHERE vendor_id='$vid' AND username='$u_esc' LIMIT 1");
        }

        $r = mysqli_fetch_assoc($q);
        if (!$r || !verifyUserPIN($pin, $r)) {
            return ["status" => "error", "message" => "Invalid Transaction PIN"];
        }
    }

    // 2. Daily Limit Enforcement
    if (!$is_super_admin) {
        $today = date('Y-m-d');
        $limit = 5; // Configurable?
        $q_limit = mysqli_query($connection_server, "SELECT recheck_count FROM sas_reconciliation_limits WHERE vendor_id='$vid' AND username='".mysqli_real_escape_string($connection_server, $username)."' AND recheck_date='$today'");
        $r_limit = mysqli_fetch_assoc($q_limit);
        if ($r_limit && $r_limit['recheck_count'] >= $limit) {
            return ["status" => "error", "message" => "Daily reconciliation limit reached ($limit attempts per day)"];
        }

        // Increment limit
        if ($r_limit) {
            mysqli_query($connection_server, "UPDATE sas_reconciliation_limits SET recheck_count = recheck_count + 1 WHERE vendor_id='$vid' AND username='".mysqli_real_escape_string($connection_server, $username)."' AND recheck_date='$today'");
        } else {
            mysqli_query($connection_server, "INSERT INTO sas_reconciliation_limits (vendor_id, username, recheck_date, recheck_count) VALUES ('$vid', '".mysqli_real_escape_string($connection_server, $username)."', '$today', 1)");
        }
    }

    // 3. Gateway Logic
    $gateway = strtolower($gateway);
    $found_new = 0;
    $credited_refs = [];

    if ($gateway == 'payhub') {
        $is_vendor_recon = ($target_type == 'vendor');
        $payhub_keys = getGatewayDetails('payhub', $is_vendor_recon ? 0 : $vid);
        if (!$payhub_keys) return ["status" => "error", "message" => "PayHub not configured"];

        // 1. If reference is provided, verify it directly first
        if (!empty($reference)) {
            $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($reference), "", $vid, $is_vendor_recon);
            $v_data = json_decode($verify_res, true);
            if (($v_data['status'] ?? "") == "success") {
                $tx_raw = json_decode($v_data['json_result'], true);
                $tx_data = $tx_raw['data'] ?? $tx_raw;
                // SECURITY: PayHub verify returns top-level `status:true` = "retrieved"; the
                // real payment status is `data.status`. Credit only on success/successful.
                $tx_status = strtolower($tx_data['status'] ?? "");
                if ($tx_status == "success" || $tx_status == "successful") {
                    if (processPayhubSuccess($vid, $tx_data['reference'], $tx_data, $payhub_keys, $username)) {
                        $found_new++;
                        $credited_refs[] = $tx_data['reference'];
                    }
                }
            }
        }

        // 2. Use the official Reconcile endpoint to scan for missing payments by date
        // Official endpoint: POST api/transaction/reconcile
        $recon_payload = [
            "date" => $date,
            "channel" => (!empty($account_number)) ? "dedicated_account" : "all"
        ];

        $recon_res = makePayhubRequest("POST", "api/transaction/reconcile", $recon_payload, $vid, $is_vendor_recon);
        $r_data = json_decode($recon_res, true);

        if (($r_data['status'] ?? "") == "success" || ($r_data['status'] ?? false) === true) {
            $json_res = json_decode($r_data['json_result'], true);
            $transactions = $json_res['data'] ?? [];
            if (!empty($transactions)) {
                foreach ($transactions as $tx) {
                    // Safety: handle status being 'success' or 'successful'
                    $tx_status = strtolower($tx['status'] ?? "");
                    if ($tx_status == "success" || $tx_status == "successful") {
                        // If account number is provided, filter by it
                        if (!empty($account_number)) {
                            // Check both top level and nested receiver fields
                            $tx_acc = $tx['receiver_bank_account_number'] ?? ($tx['account_number'] ?? "");
                            if (trim($tx_acc) !== trim($account_number)) continue;
                        }

                        if (processPayhubSuccess($vid, $tx['reference'], $tx, $payhub_keys, $username)) {
                            $found_new++;
                            $credited_refs[] = $tx['reference'];
                        }
                    }
                }
            }
        }
    } elseif ($gateway == 'paystack') {
        if (!empty($reference)) {
             $v_res = json_decode(makePaystackRequest("GET", "transaction/verify/".urlencode($reference), "", $vid), true);
             if (($v_res['status'] ?? "") == 'success') {
                 $data = json_decode($v_res['json_result'], true)['data'];
                 if (($data['status'] ?? "") == 'success') {
                    $ref_esc = mysqli_real_escape_string($connection_server, $reference);

                    if (!empty($username)) {
                        $q_tx = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE (api_reference='$ref_esc' OR reference='$ref_esc') AND vendor_id='$vid' AND status=1 LIMIT 1");
                        if (mysqli_num_rows($q_tx) == 0) {
                            $amount = $data['amount'] / 100;
                            chargeOtherUser($username, "credit", "Paystack", "Wallet Reconciliation", substr(str_shuffle("1234567890"),0,12), $reference, $amount, $amount, "Recovered Paystack Payment", "WEB", $_SERVER['HTTP_HOST'], 1);
                            $found_new++;
                            $credited_refs[] = $reference;
                        }
                    } else {
                        $q_tx = mysqli_query($connection_server, "SELECT id FROM sas_vendor_transactions WHERE (reference='$ref_esc' OR product_unique_id='$ref_esc') AND vendor_id='$vid' AND status=1 LIMIT 1");
                        if (mysqli_num_rows($q_tx) == 0) {
                            $amount = $data['amount'] / 100;
                            $rv = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='$vid'"));
                            $_SESSION["admin_session"] = $rv["email"];
                            chargeVendor("credit", "Paystack", "Wallet Reconciliation", $reference, $amount, $amount, "Recovered Paystack Payment", $_SERVER["HTTP_HOST"], "1");
                            unset($_SESSION["admin_session"]);
                            $found_new++;
                            $credited_refs[] = $reference;
                        }
                    }
                 }
             }
        }
    }

    if ($found_new > 0) {
        return ["status" => "success", "message" => "Successfully recovered $found_new transaction(s): " . implode(', ', $credited_refs)];
    } else {
        return ["status" => "info", "message" => "No uncredited successful transactions found for the specified criteria."];
    }
}

/**
 * Advanced Account Name Extraction logic (Added in Branch DG6.79)
 * Used by payhubResolveBank and paystackResolveAccount to robustly parse provider responses.
 */
function extractNameFromResolutionResponse($inner) {
    if (!is_array($inner)) return '';

    // Explicitly exclude non-name keys
    $blacklisted_keys = ['message', 'desc', 'status', 'account_number', 'accountNumber', 'bank_code', 'bankCode', 'reference', 'responseMessage', 'responseBody', 'reconciled', 'errors', 'error'];

    // Helper to extract from a flat array
    $extract_flat = function($arr) use ($blacklisted_keys) {
        if (!is_array($arr)) return '';
        $keys_to_try = ['account_name', 'accountName', 'AccountName', 'customer_name', 'customerName', 'name', 'account_bearer', 'bearer', 'customer'];

        foreach($keys_to_try as $k) {
            if (!empty($arr[$k]) && !is_array($arr[$k])) {
                $val = trim((string)$arr[$k]);
                if (!empty($val) && !in_array(strtolower($val), ['null', 'none', 'n/a', 'nil', 'undefined'])) {
                    return $val;
                }
            }
        }

        // AI Extreme Fallback: Any string containing a space (excluding blacklisted keys)
        foreach($arr as $k => $val) {
            if (in_array($k, $blacklisted_keys)) continue;
            if (is_string($val)) {
                $val = trim($val);
                if (strpos($val, ' ') !== false && strlen($val) > 5 && !in_array(strtolower($val), ['null', 'none', 'n/a', 'nil', 'undefined'])) {
                    return $val;
                }
            }
        }
        return '';
    };

    // Try primary search objects first
    $search_targets = [];
    if (isset($inner['data']) && is_array($inner['data'])) $search_targets[] = $inner['data'];
    $search_targets[] = $inner;

    foreach($search_targets as $target) {
        $found = $extract_flat($target);
        if ($found) return $found;

        // One level deeper for common nesting like 'account' => ['name' => ...]
        foreach($target as $k => $sub) {
            if (is_array($sub) && !in_array($k, $blacklisted_keys)) {
                $found = $extract_flat($sub);
                if ($found) return $found;
            }
        }
    }

    return '';
}

function getVendorOption($vid, $name, $default = '') {
    global $connection_server;
    $vid = (int)$vid;
    $name = mysqli_real_escape_string($connection_server, $name);
    $q = mysqli_query($connection_server, "SELECT setting_value FROM sas_settings WHERE vendor_id='$vid' AND setting_name='$name' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r['setting_value'];
    }
    return $default;
}

function setVendorOption($vid, $name, $value) {
    global $connection_server;
    $vid = (int)$vid;
    $name = mysqli_real_escape_string($connection_server, $name);
    $value = mysqli_real_escape_string($connection_server, $value);
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', '$name', '$value') ON DUPLICATE KEY UPDATE setting_value='$value'");
}

// STANDALONE: Returns setting from sas_settings for vendor_id=1, falling back to hardcoded defaults
function getSuperAdminOption($key, $default = '') {
    global $connection_server;
    static $standalone_cache = [];
    
    if (isset($standalone_cache[$key])) return $standalone_cache[$key];
    
    if ($connection_server) {
        $key_esc = mysqli_real_escape_string($connection_server, $key);
        $q = mysqli_query($connection_server, "SELECT setting_value FROM sas_settings WHERE vendor_id=1 AND setting_name='$key_esc' LIMIT 1");
        if ($q && $r = mysqli_fetch_assoc($q)) {
            $standalone_cache[$key] = $r['setting_value'];
            return $r['setting_value'];
        }
    }
    
    $standalone_defaults = [
        'force_kyc'                  => '0',
        'force_vendor_pin'           => '0',
        'allow_self_registration'    => '1',
        'ai_model_access'            => '255',
        'max_apis_per_vendor'        => '9999',
        'max_products_per_vendor'    => '9999',
        'payout_activation_fee'      => '0',
        'plisio_activation_fee'      => '0',
        'ai_provider'                => 'gemini',
        'ai_api_key'                 => '',
    ];
    return $standalone_defaults[$key] ?? $default;
}

/**
 * Fetch BVN profile from identity provider (Dojah or QoreID).
 */
function fetchBVNProfile($bvn, $vid) {
    global $connection_server;
    $provider = getIdentityProvider($vid);

    if ($provider === "dojah") {
        return fetchBVNProfileWithDojah($bvn, $vid);
    } elseif ($provider === "qoreid") {
        return fetchBVNProfileWithQoreID($bvn, $vid);
    } elseif ($provider === "localhost") {
        return fetchBVNProfileWithLocalhost($bvn, $vid);
    } else {
        return fetchBVNProfileWithMonnify($bvn, $vid);
    }
}

/**
 * Fetch BVN profile from a Local Marketplace API provider.
 */
function fetchBVNProfileWithLocalhost($bvn, $vid) {
    global $connection_server;
    $api_id = getIdentityApiId($vid);
    $api_details = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE id='$api_id' AND vendor_id='$vid' LIMIT 1"));
    
    if (!$api_details) {
        return ["status" => "failed", "message" => "Local API provider not configured or not found"];
    }

    $api_url = "https://" . $api_details['api_base_url'] . "/api/bvn-verify.php";
    $api_key = $api_details['api_key'];

    $payload = json_encode(["api_key" => $api_key, "bvn" => $bvn]);

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    ]);
    $res = curl_exec($ch);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch);

    if (!$body || ($body['status'] ?? '') !== 'success') {
        return ["status" => "failed", "message" => $body['desc'] ?? "Failed to connect to local API"];
    }

    return [
        "status"          => "success",
        "provider"        => "localhost:" . $api_details['api_base_url'],
        "firstname"       => $body['firstname'] ?? '',
        "middlename"      => $body['middlename'] ?? '',
        "lastname"        => $body['lastname'] ?? '',
        "birthdate"       => $body['birthdate'] ?? '',
        "gender"          => $body['gender'] ?? '',
        "photo_data"      => $body['photo_data'] ?? '',
        "phone"           => $body['phone'] ?? '',
        "address"         => $body['address'] ?? '',
        "residence_state" => $body['residence_state'] ?? '',
        "state_of_origin" => $body['state_of_origin'] ?? '',
    ];
}
function fetchBVNProfileWithDojah($bvn, $vid) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='dojah' LIMIT 1"));
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='dojah' LIMIT 1"));
    }
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        return ["status" => "failed", "message" => "Dojah API keys not configured"];
    }

    $app_id   = trim($gw['public_key']);
    $priv_key = trim($gw['secret_key']);

    $ch = curl_init("https://api.dojah.io/api/v1/kyc/bvn?bvn=" . urlencode($bvn));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["AppId: $app_id", "Authorization: $priv_key", "Content-Type: application/json"],
    ]);
    $res  = curl_exec($ch);
    $body = $res ? json_decode($res, true) : null;
    curl_close($ch);

    if (!$body || empty($body['entity'])) {
        $err = isset($body['error']) ? $body['error'] : "No response from Dojah";
        return ["status" => "failed", "message" => $err];
    }
    $e = $body['entity'];

    $gender_raw = strtolower($e['gender'] ?? '');
    $gender = ($gender_raw === 'm' || $gender_raw === 'male') ? 'Male' : (($gender_raw === 'f' || $gender_raw === 'female') ? 'Female' : ucfirst($gender_raw));

    return [
        "status"             => "success",
        "provider"           => "dojah",
        "firstname"          => strtoupper($e['first_name'] ?? $e['firstname'] ?? ''),
        "middlename"         => strtoupper($e['middle_name'] ?? $e['middlename'] ?? ''),
        "lastname"           => strtoupper($e['last_name'] ?? $e['surname'] ?? ''),
        "birthdate"          => $e['date_of_birth'] ?? $e['birthdate'] ?? '',
        "gender"             => $gender,
        "phone"              => $e['phone_number1'] ?? $e['phone'] ?? '',
        "bank_of_enrolment"  => $e['enrollment_bank'] ?? '',
        "level_of_account"   => $e['level_of_account'] ?? '',
    ];
}

function fetchBVNProfileWithQoreID($bvn, $vid) {
    global $connection_server;
    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$vid_esc' AND gateway_name='qoreid' LIMIT 1"));
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='qoreid' LIMIT 1"));
    }
    if (!$gw || empty($gw['public_key']) || empty($gw['secret_key'])) {
        return ["status" => "failed", "message" => "QoreID API keys not configured"];
    }

    $client_id = trim($gw['public_key']);
    $secret    = trim($gw['secret_key']);

    // Get bearer token
    $ch = curl_init("https://api.qoreid.com/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(["clientId" => $client_id, "secret" => $secret]),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    ]);
    $tok_res  = curl_exec($ch);
    $tok_body = $tok_res ? json_decode($tok_res, true) : null;
    curl_close($ch);

    $token = $tok_body['accessToken'] ?? null;
    if (empty($token)) {
        return ["status" => "failed", "message" => "QoreID: failed to obtain access token"];
    }

    $ch2 = curl_init("https://api.qoreid.com/v1/ng/identities/bvn/" . urlencode($bvn));
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
    ]);
    $res2  = curl_exec($ch2);
    $body2 = $res2 ? json_decode($res2, true) : null;
    curl_close($ch2);

    if (!$body2 || empty($body2['bvn'])) {
        $err = isset($body2['message']) ? $body2['message'] : "No BVN data returned from QoreID";
        return ["status" => "failed", "message" => $err];
    }

    $b = $body2['bvn'];
    $fullname = $b['fullName'] ?? '';
    $parts = explode(' ', trim($fullname), 3);
    $firstname  = strtoupper($parts[0] ?? '');
    $middlename = strtoupper($parts[2] ?? '');
    $lastname   = strtoupper($parts[1] ?? '');

    if (!empty($b['firstName'])) $firstname  = strtoupper($b['firstName']);
    if (!empty($b['middleName'])) $middlename = strtoupper($b['middleName']);
    if (!empty($b['lastName'])) $lastname   = strtoupper($b['lastName']);

    $gender_raw = strtolower($b['gender'] ?? '');
    $gender = ($gender_raw === 'm' || $gender_raw === 'male') ? 'Male' : (($gender_raw === 'f' || $gender_raw === 'female') ? 'Female' : ucfirst($gender_raw));

    return [
        "status"             => "success",
        "provider"           => "qoreid",
        "firstname"          => $firstname,
        "middlename"         => $middlename,
        "lastname"           => $lastname,
        "birthdate"          => $b['dateOfBirth'] ?? '',
        "gender"             => $gender,
        "phone"              => $b['phoneNumber'] ?? '',
        "bank_of_enrolment"  => $b['enrollmentBank'] ?? '',
        "level_of_account"   => $b['levelOfAccount'] ?? '',
    ];
}

function getCountryNameByCode($code) {
    $countries = [
        "AF" => "Afghanistan", "AL" => "Albania", "DZ" => "Algeria", "AS" => "American Samoa", "AD" => "Andorra", "AO" => "Angola", "AI" => "Anguilla", "AQ" => "Antarctica", "AG" => "Antigua and Barbuda", "AR" => "Argentina", "AM" => "Armenia", "AW" => "Aruba", "AU" => "Australia", "AT" => "Austria", "AZ" => "Azerbaijan",
        "BS" => "Bahamas", "BH" => "Bahrain", "BD" => "Bangladesh", "BB" => "Barbados", "BY" => "Belarus", "BE" => "Belgium", "BZ" => "Belize", "BJ" => "Benin", "BM" => "Bermuda", "BT" => "Bhutan", "BO" => "Bolivia", "BA" => "Bosnia and Herzegovina", "BW" => "Botswana", "BR" => "Brazil", "IO" => "British Indian Ocean Territory", "BN" => "Brunei Darussalam", "BG" => "Bulgaria", "BF" => "Burkina Faso", "BI" => "Burundi",
        "KH" => "Cambodia", "CM" => "Cameroon", "CA" => "Canada", "CV" => "Cape Verde", "KY" => "Cayman Islands", "CF" => "Central African Republic", "TD" => "Chad", "CL" => "Chile", "CN" => "China", "CX" => "Christmas Island", "CC" => "Cocos (Keeling) Islands", "CO" => "Colombia", "KM" => "Comoros", "CG" => "Congo", "CD" => "Congo, The Democratic Republic of the", "CK" => "Cook Islands", "CR" => "Costa Rica", "CI" => "Cote D'Ivoire", "HR" => "Croatia", "CU" => "Cuba", "CY" => "Cyprus", "CZ" => "Czech Republic",
        "DK" => "Denmark", "DJ" => "Djibouti", "DM" => "Dominica", "DO" => "Dominican Republic", "EC" => "Ecuador", "EG" => "Egypt", "SV" => "El Salvador", "GQ" => "Equatorial Guinea", "ER" => "Eritrea", "EE" => "Estonia", "ET" => "Ethiopia", "FK" => "Falkland Islands (Malvinas)", "FO" => "Faroe Islands", "FJ" => "Fiji", "FI" => "Finland", "FR" => "France", "GF" => "French Guiana", "PF" => "French Polynesia", "TF" => "French Southern Territories",
        "GA" => "Gabon", "GM" => "Gambia", "GE" => "Georgia", "DE" => "Germany", "GH" => "Ghana", "GI" => "Gibraltar", "GR" => "Greece", "GL" => "Greenland", "GD" => "Grenada", "GP" => "Guadeloupe", "GU" => "Guam", "GT" => "Guatemala", "GN" => "Guinea", "GW" => "Guinea-Bissau", "GY" => "Guyana", "HT" => "Haiti", "HM" => "Heard Island and Mcdonald Islands", "VA" => "Holy See (Vatican City State)", "HN" => "Honduras", "HK" => "Hong Kong", "HU" => "Hungary",
        "IS" => "Iceland", "IN" => "India", "ID" => "Indonesia", "IR" => "Iran", "IQ" => "Iraq", "IE" => "Ireland", "IL" => "Israel", "IT" => "Italy", "JM" => "Jamaica", "JP" => "Japan", "JO" => "Jordan", "KZ" => "Kazakhstan", "KE" => "Kenya", "KI" => "Kiribati", "KP" => "Korea", "KR" => "Korea", "KW" => "Kuwait", "KG" => "Kyrgyzstan", "LA" => "Lao", "LV" => "Latvia", "LB" => "Lebanon", "LS" => "Lesotho", "LR" => "Liberia", "LY" => "Libya", "LI" => "Liechtenstein", "LT" => "Lithuania", "LU" => "Luxembourg",
        "MO" => "Macao", "MK" => "Macedonia", "MG" => "Madagascar", "MW" => "Malawi", "MY" => "Malaysia", "MV" => "Maldives", "ML" => "Mali", "MT" => "Malta", "MH" => "Marshall Islands", "MQ" => "Martinique", "MR" => "Mauritania", "MU" => "Mauritius", "YT" => "Mayotte", "MX" => "Mexico", "FM" => "Micronesia", "MD" => "Moldova", "MC" => "Monaco", "MN" => "Mongolia", "MS" => "Montserrat", "MA" => "Morocco", "MZ" => "Mozambique", "MM" => "Myanmar",
        "NA" => "Namibia", "NR" => "Nauru", "NP" => "Nepal", "NL" => "Netherlands", "AN" => "Netherlands Antilles", "NC" => "New Caledonia", "NZ" => "New Zealand", "NI" => "Nicaragua", "NE" => "Niger", "NG" => "Nigeria", "NU" => "Niue", "NF" => "Norfolk Island", "MP" => "Northern Mariana Islands", "NO" => "Norway", "OM" => "Oman", "PK" => "Pakistan", "PW" => "Palau", "PS" => "Palestine", "PA" => "Panama", "PG" => "Papua New Guinea", "PY" => "Paraguay", "PE" => "Peru", "PH" => "Philippines", "PN" => "Pitcairn", "PL" => "Poland", "PT" => "Portugal", "PR" => "Puerto Rico", "QA" => "Qatar",
        "RE" => "Reunion", "RO" => "Romania", "RU" => "Russia", "RW" => "Rwanda", "SH" => "Saint Helena", "KN" => "Saint Kitts and Nevis", "LC" => "Saint Lucia", "PM" => "Saint Pierre and Miquelon", "VC" => "Saint Vincent and the Grenadines", "WS" => "Samoa", "SM" => "San Marino", "ST" => "Sao Tome and Principe", "SA" => "Saudi Arabia", "SN" => "Senegal", "CS" => "Serbia", "SC" => "Seychelles", "SL" => "Sierra Leone", "SG" => "Singapore", "SK" => "Slovakia", "SI" => "Slovenia", "SB" => "Solomon Islands", "SO" => "Somalia", "ZA" => "South Africa", "GS" => "South Georgia", "ES" => "Spain", "LK" => "Sri Lanka", "SD" => "Sudan", "SR" => "Suriname", "SJ" => "Svalbard", "SZ" => "Swaziland", "SE" => "Sweden", "CH" => "Switzerland", "SY" => "Syria",
        "TW" => "Taiwan", "TJ" => "Tajikistan", "TZ" => "Tanzania", "TH" => "Thailand", "TL" => "Timor-Leste", "TG" => "Togo", "TK" => "Tokelau", "TO" => "Tonga", "TT" => "Trinidad and Barbuda", "TN" => "Tunisia", "TR" => "Turkey", "TM" => "Turkmenistan", "TC" => "Turks and Caicos Islands", "TV" => "Tuvalu", "UG" => "Uganda", "UA" => "Ukraine", "AE" => "United Arab Emirates", "GB" => "United Kingdom", "US" => "United States", "UM" => "United States", "UY" => "Uruguay", "UZ" => "Uzbekistan",
        "VU" => "Vanuatu", "VE" => "Venezuela", "VN" => "Viet Nam", "VG" => "Virgin Islands", "VI" => "Virgin Islands", "WF" => "Wallis and Futuna", "EH" => "Western Sahara", "YE" => "Yemen", "ZM" => "Zambia", "ZW" => "Zimbabwe"
    ];
    return $countries[strtoupper($code)] ?? $code;
}

/**
 * Returns the HTML for the Android App Download button if a URL is configured for the vendor.
 */
function getAndroidDownloadButton($vid = null) {
    global $connection_server;
    if ($vid === null) $vid = resolveVendorID();
    if ($vid <= 0 || !$connection_server) return '';

    $vid_esc = mysqli_real_escape_string($connection_server, $vid);
    $q = mysqli_query($connection_server, "SELECT apk_download_url FROM sas_site_details WHERE vendor_id='$vid_esc' LIMIT 1");
    if ($r = mysqli_fetch_assoc($q)) {
        $url = $r['apk_download_url'] ?? '';
        if (!empty($url)) {
            return '
            <a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener" class="d-inline-block">
                <img src="asset/google-play-download.png" alt="Get it on Google Play" style="height: 52px; width: auto; border-radius: 8px;">
            </a>';
        }
    }
    return '';
}

/**
 * Fetch full NIN profile from Monnify.
 * Note: Monnify provides limited fields compared to Dojah/QoreID.
 */
function fetchNINProfileWithMonnify($nin, $vid) {
    // We use the already corrected verifyBvnNinWithMonnify
    $res = verifyBvnNinWithMonnify($nin, "nin", "", "", $vid, true);
    if ($res["status"] !== "success") return $res;

    return [
        "status" => "success",
        "firstname" => $res["firstname"],
        "middlename" => "",
        "lastname" => $res["lastname"],
        "birthdate" => "",
        "gender" => "",
        "photo_data" => "",
        "phone" => $res["phone"],
        "address" => "Address not provided by Monnify",
        "residence_state" => "",
        "state_of_origin" => "",
        "provider" => "monnify"
    ];
}

/**
 * Fetch BVN profile from Monnify.
 */
function fetchBVNProfileWithMonnify($bvn, $vid) {
    // Monnify BVN match requires bank account, but basic BVN lookup might not be directly available for full profile without match.
    // However, if the user is using Monnify for KYC/Verification, we'll attempt a dummy match or use their registered details if possible.
    // Actually, Monnify has a BVN lookup API if enabled.

    global $get_logged_user_details;
    $res = verifyBvnNinWithMonnify($bvn, "nin", "", "", $vid, true); // Use NIN endpoint for BVN if it works or just return error if not supported

    if ($res["status"] !== "success") {
        return ["status" => "failed", "message" => "Monnify BVN Lookup failed. Please use Dojah or QoreID for full BVN profile retrieval."];
    }

    return [
        "status" => "success",
        "firstname" => $res["firstname"],
        "middlename" => "",
        "lastname" => $res["lastname"],
        "birthdate" => "",
        "gender" => "",
        "phone" => $res["phone"],
        "bank_of_enrolment" => "",
        "level_of_account" => "",
        "provider" => "monnify"
    ];
}
