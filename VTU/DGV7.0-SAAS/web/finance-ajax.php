<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("../func/bc-connect.php");

header('Content-Type: application/json');

// Determine context
$is_admin = isset($_SESSION['admin_session']);
$is_spadmin = isset($_SESSION['spadmin_session']);
$is_user = isset($_SESSION['user_session']);

if (isset($_POST['action']) && $_POST['action'] == 'create_checkout') {
    $username = mysqli_real_escape_string($connection_server, $_POST['username']);
    $reference = mysqli_real_escape_string($connection_server, $_POST['reference']);
    $amount = (float)$_POST['amount'];
    $vid = (int)$_POST['vendor_id'];
    $is_vendor = (isset($_POST['is_vendor']) && $_POST['is_vendor'] == '1');
    $target = $_POST['target'] ?? '';

    if (empty($username) || empty($reference) || $amount <= 0 || $vid <= 0) {
        if (ob_get_length()) ob_clean();
        echo json_encode(array('status' => 'error', 'message' => 'Invalid parameters'));
        exit;
    }

    // 1. Log in checkouts
    $check = mysqli_query($connection_server, "SELECT id FROM sas_user_payment_checkouts WHERE reference='$reference' AND vendor_id='$vid'");
    if (mysqli_num_rows($check) == 0) {
        $ins_chk = mysqli_query($connection_server, "INSERT INTO sas_user_payment_checkouts (vendor_id, username, reference, status) VALUES ('$vid', '$username', '$reference', '1')");
        if (!$ins_chk) {
            @file_put_contents(__DIR__ . '/../logs/funding_errors.log', '[' . date('Y-m-d H:i:s') . '] create_checkout checkout insert failed (ref=' . $reference . '): ' . mysqli_error($connection_server) . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    // 2. Log in transactions as pending (status 2)
    if ($is_vendor) {
        $check_trans = mysqli_query($connection_server, "SELECT id FROM sas_vendor_transactions WHERE reference='$reference' AND vendor_id='$vid'");
        if (mysqli_num_rows($check_trans) == 0) {
             $v_q = mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1");
             $v_r = mysqli_fetch_assoc($v_q);
             $bal = $v_r['balance'] ?? 0;

             $p_uid = ($target == 'plisio_activation') ? 'plisio_activation' : (($target == 'payout_activation') ? 'payout_activation' : 'wallet_funding');
             $type_alt = ($target == 'plisio_activation' || $target == 'payout_activation') ? 'Service Activation' : 'Wallet Funding';
             $desc = ($target == 'plisio_activation') ? 'Plisio Crypto Gateway Activation Fee' : (($target == 'payout_activation') ? 'Withdrawal Module Activation Fee' : 'Wallet funding via ATM/Transfer');

             $ins_vtx = mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) VALUES ('$vid', '$p_uid', '$type_alt', '$reference', '$amount', '$amount', '$bal', '$bal', '$desc', '".bc_safe_host_sql($connection_server)."', '2')");
             if (!$ins_vtx) {
                 @file_put_contents(__DIR__ . '/../logs/funding_errors.log', '[' . date('Y-m-d H:i:s') . '] create_checkout vendor insert failed (ref=' . $reference . '): ' . mysqli_error($connection_server) . PHP_EOL, FILE_APPEND | LOCK_EX);
             }
        }
    } else {
        $check_trans = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE reference='$reference' AND vendor_id='$vid'");
        if (mysqli_num_rows($check_trans) == 0) {
             $user_q = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE vendor_id='$vid' AND username='$username' LIMIT 1");
             $user_r = mysqli_fetch_assoc($user_q);
             $bal = $user_r['balance'] ?? 0;
                 // api_website is NOT NULL — omitting it makes the INSERT fail silently under
                 // strict MySQL sql_mode, so the transaction is never logged and PayHub's
                 // gateway_redirect then reports "Transaction not found". Always supply it.
                 $ins_utx = mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('$vid', 'wallet_funding', 'Wallet Funding', '$reference', '$username', '$amount', '$amount', '$bal', '$bal', 'Wallet funding via ATM/Transfer', 'WEB', '" . bc_safe_host_sql($connection_server) . "', '2')");
                 if (!$ins_utx) {
                     @file_put_contents(__DIR__ . '/../logs/funding_errors.log', '[' . date('Y-m-d H:i:s') . '] create_checkout user insert failed (ref=' . $reference . '): ' . mysqli_error($connection_server) . PHP_EOL, FILE_APPEND | LOCK_EX);
                 }
            }
        }

    if (ob_get_length()) ob_clean();
    echo json_encode(array('status' => 'success'));
    exit;
}

if (!$is_admin && !$is_user && !$is_spadmin) {
    if (ob_get_length()) ob_clean();
    echo json_encode(array('status' => 'error', 'message' => 'Session expired'));
    exit;
}

// Fetch details based on session
$vendor_id = resolveVendorID();
if ($is_admin) {
    $admin_email = $_SESSION['admin_session'];
    $get_logged_admin_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' && email='$admin_email' LIMIT 1"));
    $vendor_id = $get_logged_admin_details['id'] ?? 0;
} else if ($is_spadmin) {
    $vendor_id = null; // SPAdmin can see all vendors
} else {
    $session_user = mysqli_real_escape_string($connection_server, $_SESSION["user_session"] ?? "");
    $get_logged_user_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$session_user' LIMIT 1"));
    $user_id = $get_logged_user_details['id'] ?? 0;
    $vendor_id = $get_logged_user_details['vendor_id'] ?? 0;
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action == 'gateway_redirect') {
        $gateway = $_GET['gateway'] ?? '';
        $reference = mysqli_real_escape_string($connection_server, $_GET['reference'] ?? '');
        // The front-end also sends the intended amount so we can rebuild a missing pending
        // transaction on the fly if the create_checkout INSERT was skipped/failed.
        $amount_param = (float)($_GET['amount'] ?? 0);

        // Find transaction
        $q = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE reference='$reference' LIMIT 1");
        $is_vendor_funding = false;
        if (!$q || mysqli_num_rows($q) == 0) {
            $q = mysqli_query($connection_server, "SELECT * FROM sas_vendor_transactions WHERE reference='$reference' LIMIT 1");
            $is_vendor_funding = true;
        }

        $tx = mysqli_fetch_assoc($q);

        // If no transaction row exists, rebuild it from the checkout record (which create_checkout
        // creates). This makes the PayHub flow work even when the sas_transactions INSERT failed
        // silently earlier (strict MySQL / missing column / etc.).
        if (!$tx) {
            $ref_esc2 = mysqli_real_escape_string($connection_server, $reference);
            $q_c = mysqli_query($connection_server, "SELECT * FROM sas_user_payment_checkouts WHERE reference='$ref_esc2' LIMIT 1");
            $checkout = $q_c ? mysqli_fetch_assoc($q_c) : null;
            if ($checkout) {
                $rc_vid = (int)$checkout['vendor_id'];
                $rc_user = $checkout['username'];
                $rc_amount = $amount_param > 0 ? $amount_param : 0;

                // Decide user vs vendor funding by checking where the username lives.
                $q_u = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE vendor_id='$rc_vid' AND username='" . mysqli_real_escape_string($connection_server, $rc_user) . "' LIMIT 1");
                if ($q_u && mysqli_num_rows($q_u) > 0) {
                    $u_r2 = mysqli_fetch_assoc($q_u);
                    $bal = $u_r2['balance'] ?? 0;
                    $ins = mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, product_unique_id, type_alternative, reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status) VALUES ('$rc_vid', 'wallet_funding', 'Wallet Funding', '$ref_esc2', '" . mysqli_real_escape_string($connection_server, $rc_user) . "', '$rc_amount', '$rc_amount', '$bal', '$bal', 'Wallet funding via ATM/Transfer', 'WEB', '" . bc_safe_host_sql($connection_server) . "', '2')");
                    $tx = ['vendor_id' => $rc_vid, 'username' => $rc_user, 'amount' => $rc_amount, 'product_unique_id' => 'wallet_funding'];
                } else {
                    $v_q2 = mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$rc_vid' LIMIT 1");
                    $v_r2 = mysqli_fetch_assoc($v_q2);
                    $bal = $v_r2['balance'] ?? 0;
                    $ins = mysqli_query($connection_server, "INSERT INTO sas_vendor_transactions (vendor_id, product_unique_id, type_alternative, reference, amount, discounted_amount, balance_before, balance_after, description, api_website, status) VALUES ('$rc_vid', 'wallet_funding', 'Wallet Funding', '$ref_esc2', '$rc_amount', '$rc_amount', '$bal', '$bal', 'Wallet funding via ATM/Transfer', '" . bc_safe_host_sql($connection_server) . "', '2')");
                    $tx = ['vendor_id' => $rc_vid, 'username' => $rc_user, 'amount' => $rc_amount, 'product_unique_id' => 'wallet_funding'];
                    $is_vendor_funding = true;
                }
                if (!$ins) {
                    @file_put_contents(__DIR__ . '/../logs/funding_errors.log', '[' . date('Y-m-d H:i:s') . '] gateway_redirect reconstruct insert failed (ref=' . $reference . '): ' . mysqli_error($connection_server) . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            }
        }

        if (!$tx) {
            // Always return valid JSON — a plain-text die() here made the front-end's
            // response.json() throw "Unexpected token 'T', \"Transactio\"... is not valid JSON".
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Transaction not found for reference: ' . $reference]);
            exit;
        }

        $vid = (int)$tx['vendor_id'];
        $amount = (float)$tx['amount'];
        $email = ""; $phone = ""; $name = "";

        if (!$is_vendor_funding) {
            $u_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vid' AND username='".mysqli_real_escape_string($connection_server, $tx['username'])."' LIMIT 1");
            $u = mysqli_fetch_assoc($u_q);
            $email = $u['email'] ?? '';
            $phone = $u['phone_number'] ?? '';
            $name = ($u['firstname'] ?? '') . " " . ($u['lastname'] ?? '');
        } else {
            $v_q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vid' LIMIT 1");
            $v = mysqli_fetch_assoc($v_q);
            $email = $v['email'] ?? '';
            $phone = $v['phone_number'] ?? '';
            $name = ($v['firstname'] ?? '') . " " . ($v['lastname'] ?? '');
        }

        if ($gateway == 'payhub') {
            // CRITICAL (PayHub API contract): PayHub's api/transaction/initialize IGNORES any
            // merchant-supplied "reference" / "callback_url" and always generates its own
            // "PH_<hex>" reference (returned in data.reference / data.access_code). We MUST adopt
            // that PayHub reference as the canonical one for the whole flow (verify, poll, success
            // page, webhook). Calling api/transaction/verify/{local_ref} returns "Transaction not
            // found" -> the inline modal resets and the wallet is never credited. So we persist the
            // PayHub ref on the local transaction + a checkout row and return it to the front-end
            // so it polls/redirects with the RIGHT reference.
            $callback_url = $is_vendor_funding ? $web_http_host . "/bc-admin/payhub-success.php" : $web_http_host . "/web/payhub-success.php";
            $res_json = makePayhubRequest("POST", "api/transaction/initialize", [
                "email" => $email,
                "amount" => $amount,
                "name" => $name,
                "phone" => $phone,
                // NOTE: do NOT send "reference"/"callback_url" at the top level — PayHub ignores
                // them. The local reference is carried inside metadata for cross-referencing.
                "metadata" => json_encode([
                    "vendor_id" => $vid,
                    "username" => $tx['username'] ?? '',
                    "target" => $is_vendor_funding ? "vendor" : "user",
                    "reference" => $reference,
                    "product_unique_id" => $tx['product_unique_id'] ?? '',
                    "callback_url" => $callback_url
                ])
            ], $vid, $is_vendor_funding);

            $res = json_decode($res_json, true);
            if (($res['status'] ?? '') == 'success') {
                $inner = json_decode($res['json_result'], true);
                // Support both nested and flat structures for the authorization_url
                $url = $inner['data']['authorization_url'] ?? ($inner['authorization_url'] ?? ($inner['data']['checkout_url'] ?? ($inner['checkout_url'] ?? '')));

                // The reference PayHub will verify against — the ONE we must use everywhere.
                $payhub_ref = $inner['data']['reference'] ?? ($inner['data']['access_code'] ?? '');
                if (empty($payhub_ref) && !empty($url)) {
                    $pu = parse_url($url);
                    if (!empty($pu['query'])) { parse_str($pu['query'], $pq); $payhub_ref = $pq['ref'] ?? ''; }
                }
                $payhub_ref = trim((string)$payhub_ref);

                if (!empty($url)) {
                    // Persist the PayHub reference so the webhook / poll / success page can
                    // reconcile the payment back to this local pending transaction.
                    if (!empty($payhub_ref)) {
                        $ph_ref_esc = mysqli_real_escape_string($connection_server, $payhub_ref);
                        $loc_ref_esc = mysqli_real_escape_string($connection_server, $reference);

                        if ($is_vendor_funding) {
                            // sas_vendor_transactions has no api_reference column — the checkout row
                            // (keyed by the PayHub ref) is what carries the mapping.
                            @file_put_contents(__DIR__ . '/../logs/payhub_ref_map.log', '[' . date('Y-m-d H:i:s') . "] vendor local=$loc_ref_esc ph=$ph_ref_esc vid=$vid\n", FILE_APPEND | LOCK_EX);
                        } else {
                            mysqli_query($connection_server, "UPDATE sas_transactions SET api_reference='$ph_ref_esc' WHERE reference='$loc_ref_esc' AND vendor_id='$vid'");
                            @file_put_contents(__DIR__ . '/../logs/payhub_ref_map.log', '[' . date('Y-m-d H:i:s') . "] user local=$loc_ref_esc ph=$ph_ref_esc vid=$vid\n", FILE_APPEND | LOCK_EX);
                        }

                        // Checkout row keyed by the PayHub ref (context resolution for webhook/poll).
                        $q_ph = mysqli_query($connection_server, "SELECT id FROM sas_user_payment_checkouts WHERE reference='$ph_ref_esc' LIMIT 1");
                        if (!$q_ph || mysqli_num_rows($q_ph) == 0) {
                            mysqli_query($connection_server, "INSERT INTO sas_user_payment_checkouts (vendor_id, username, reference, status) VALUES ('$vid', '" . mysqli_real_escape_string($connection_server, $tx['username'] ?? '') . "', '$ph_ref_esc', '1')");
                        }
                    }

                    if (ob_get_length()) ob_clean();
                    echo json_encode(['status' => 'success', 'checkout_url' => $url, 'payhub_ref' => $payhub_ref]);
                    exit;
                }
            }
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => $res['message'] ?? 'Initialization failed']);
            exit;
        }
        if (ob_get_length()) ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Invalid Gateway']);
        exit;
    }

    // ─── PayHub payment status poll (verify + idempotent credit) ─────────────
    // The PayHub inline modal does not reliably postMessage success back to the parent
    // page, so the Fund page polls this endpoint while the modal is open. It verifies the
    // payment with PayHub and credits the wallet once confirmed, so the user is credited
    // and redirected to the dashboard just like Paystack.
    if ($action == 'payhub_status') {
        $reference   = mysqli_real_escape_string($connection_server, $_GET['reference'] ?? '');
        $payhub_ref  = trim((string)($_GET['payhub_ref'] ?? ''));
        $is_vendor_funding = (isset($_GET['is_vendor']) && $_GET['is_vendor'] == '1');

        if (empty($reference) && empty($payhub_ref)) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Missing reference']);
            exit;
        }

        // Resolve context from the checkout record. PayHub's initialize generates its own
        // "PH_..." reference (it ignores the local one), so key on that when available.
        $vid = 0;
        $username = '';
        $q_c = null;
        if (!empty($payhub_ref)) {
            $ph_ref_esc = mysqli_real_escape_string($connection_server, $payhub_ref);
            $q_c = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$ph_ref_esc' LIMIT 1");
        }
        if ((!$q_c || mysqli_num_rows($q_c) == 0) && !empty($reference)) {
            $q_c = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$reference' LIMIT 1");
        }
        if ($q_c && ($r_c = mysqli_fetch_assoc($q_c))) {
            $vid = (int)$r_c['vendor_id'];
            $username = $r_c['username'];
        }

        // If the checkout row is missing, resolve the vendor from the local pending transaction.
        if ($vid <= 0 && !empty($reference)) {
            $q_tx = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_transactions WHERE reference='$reference' LIMIT 1");
            if (!$q_tx || mysqli_num_rows($q_tx) == 0) {
                $q_tx = mysqli_query($connection_server, "SELECT vendor_id FROM sas_vendor_transactions WHERE reference='$reference' LIMIT 1");
                if ($q_tx && mysqli_num_rows($q_tx) > 0) $is_vendor_funding = true;
            }
            if ($q_tx && ($r_tx = mysqli_fetch_assoc($q_tx))) {
                $vid = (int)$r_tx['vendor_id'];
                if (!empty($r_tx['username'])) $username = $r_tx['username'];
            }
        }
        if ($vid <= 0) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
            exit;
        }

        // Determine the PayHub reference to verify — NEVER our local reference, because PayHub
        // only knows its own "PH_..." reference (initialize ignored ours).
        $verify_ref = $payhub_ref;
        if (empty($verify_ref)) {
            $q_ap = mysqli_query($connection_server, "SELECT api_reference FROM sas_transactions WHERE reference='$reference' AND vendor_id='$vid' LIMIT 1");
            if ($q_ap && ($r_ap = mysqli_fetch_assoc($q_ap)) && !empty($r_ap['api_reference'])) {
                $verify_ref = $r_ap['api_reference'];
            }
        }
        if (empty($verify_ref) && $is_vendor_funding) {
            // Vendor transactions have no api_reference column — fall back to the most recent
            // PayHub-generated checkout row for this vendor (created by gateway_redirect).
            $q_ph = mysqli_query($connection_server, "SELECT reference FROM sas_user_payment_checkouts WHERE vendor_id='$vid' AND reference LIKE 'PH_%' ORDER BY id DESC LIMIT 1");
            if ($q_ph && ($r_ph = mysqli_fetch_assoc($q_ph))) {
                $verify_ref = $r_ph['reference'];
            }
        }
        if (empty($verify_ref)) {
            $verify_ref = $reference;
        }
        if (empty($verify_ref)) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Missing PayHub reference']);
            exit;
        }

        // Vendor funding is paid to the platform (super admin keys); user funding to the vendor.
        $payhub_keys = getGatewayDetails('payhub', $is_vendor_funding ? 0 : $vid);
        if (!$payhub_keys) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'PayHub not configured']);
            exit;
        }

        $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($verify_ref), "", $vid, $is_vendor_funding);
        $v_data = json_decode($verify_res, true);
        if (($v_data['status'] ?? '') != 'success') {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'pending', 'payhub_ref' => $verify_ref]);
            exit;
        }

        $tx_raw = json_decode($v_data['json_result'], true);
        $tx_data = (isset($tx_raw['data']) && is_array($tx_raw['data'])) ? $tx_raw['data'] : $tx_raw;
        $tx_status = strtolower($tx_data['status'] ?? '');
        // NOTE: PayHub's verify returns a TOP-LEVEL status:true merely to mean "transaction
        // retrieved" — the ACTUAL payment status lives in data.status ('pending'/'success').
        // Never treat the top-level boolean as proof of payment (that let unpaid transactions
        // get credited the moment the user clicked Pay).
        $is_paid = ($tx_status == 'success' || $tx_status == 'successful');

        if (!$is_paid) {
            // Tell a DEFINITIVE failure apart from "not confirmed yet". Both used to answer
            // "pending", so the Fund page kept polling for its whole 10-minute window on a payment
            // that was already over, and the customer waited for a credit that was never coming.
            $verdict = bc_gateway_provider_verdict(array('status' => $tx_status));
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'status'     => ($verdict === 'failed') ? 'failed' : 'pending',
                'message'    => ($verdict === 'failed') ? (string)($tx_data['gateway_response'] ?? '') : '',
                'payhub_ref' => $verify_ref,
            ]);
            exit;
        }

        // Paid — credit idempotently (processPayhubSuccess skips already-credited).
        if (empty($tx_data['reference'])) $tx_data['reference'] = $verify_ref;
        $tx_data['metadata'] = json_encode([
            'vendor_id' => $vid,
            'username'  => $username,
            'target'    => $is_vendor_funding ? 'vendor' : 'user',
            'reference' => $reference,
        ]);
        $local_ref = processPayhubSuccess($vid, $tx_data['reference'], $tx_data, $payhub_keys, $username);

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'status'   => 'paid',
            'credited' => $local_ref ? true : false,
            'local_ref'=> $local_ref ?: null,
            'payhub_ref' => $verify_ref,
        ]);
        exit;
    }

    // ─── Paystack funding confirm (server-side verify + idempotent credit) ─────
    // The Paystack webhook (users-paystack.php) is the authoritative credit path. This action is
    // the secure fallback the Fund page calls after the inline popup reports success — it
    // re-verifies with Paystack's API server-side BEFORE crediting, so the wallet is funded
    // immediately even if webhook delivery is delayed or not configured. Never credits unless
    // Paystack itself confirms the transaction.
    if ($action == 'verify_paystack') {
        $reference = mysqli_real_escape_string($connection_server, trim((string)($_GET['reference'] ?? '')));
        $is_vendor_funding = (isset($_GET['is_vendor']) && $_GET['is_vendor'] == '1');
        if (empty($reference)) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Missing reference']);
            exit;
        }

        // Resolve vendor (and user) from the checkout / pending transaction record.
        $vid = 0; $username = '';
        $q = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$reference' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) { $vid = (int)$r['vendor_id']; $username = $r['username']; }
        if ($vid <= 0) {
            $q = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_transactions WHERE reference='$reference' LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q))) { $vid = (int)$r['vendor_id']; $username = $r['username'] ?? ''; }
        }
        if ($vid <= 0) {
            $q = mysqli_query($connection_server, "SELECT vendor_id FROM sas_vendor_transactions WHERE reference='$reference' LIMIT 1");
            if ($q && mysqli_num_rows($q) > 0) $is_vendor_funding = true;
            if ($q && ($r = mysqli_fetch_assoc($q))) $vid = (int)$r['vendor_id'];
        }
        if ($vid <= 0) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
            exit;
        }
        $GLOBALS['vendor_id'] = $vid;

        // Vendor (admin) funding is paid to the platform (super admin keys); user funding to the vendor.
        $gw_vid = $is_vendor_funding ? 0 : $vid;
        $gw = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='$gw_vid' AND gateway_name='paystack' AND status=1 LIMIT 1"));
        if (!$gw || empty(trim($gw['secret_key'] ?? ''))) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Paystack not configured']);
            exit;
        }

        // Verify with Paystack BEFORE crediting.
        $ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($reference));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer " . trim($gw['secret_key'])],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $paid = (($resp['status'] ?? false) === true) && (strtolower($resp['data']['status'] ?? '') === 'success');
        if (!$paid) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'pending', 'message' => 'Payment not yet confirmed']);
            exit;
        }

        $verified_amount = (float)(($resp['data']['amount'] ?? 0) / 100);
        $gw_ref = (string)($resp['data']['reference'] ?? $reference);
        $fee_percent = (float)($gw['percentage'] ?? 0);
        $amount_deposited = $verified_amount * (1 - ($fee_percent / 100));
        $desc = "Paystack Wallet Credit (Ref: " . $gw_ref . ")";
        $host = $_SERVER['HTTP_HOST'] ?? 'WEB';

        // Idempotent: only credit if no COMPLETED transaction already exists for this reference.
        $q_check = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vid' AND (api_reference='$reference' OR reference='$reference') AND status=1 LIMIT 1");
        if (mysqli_num_rows($q_check) > 0) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'success', 'message' => 'Payment already recorded']);
            exit;
        }

        if ($is_vendor_funding) {
            $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
            $res = chargeVendor("credit", "Paystack", "Wallet Credit", $new_ref, $verified_amount, $amount_deposited, $desc, $host, 1);
        } else {
            if (empty($username)) {
                $cust_email = mysqli_real_escape_string($connection_server, ($resp['data']['customer']['email'] ?? ''));
                $q_u = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vid' AND email='$cust_email' LIMIT 1");
                if ($q_u && ($r_u = mysqli_fetch_assoc($q_u))) $username = $r_u['username'];
            }
            $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
            $res = chargeOtherUser($username, "credit", "Paystack", "Wallet Credit", $new_ref, $reference, $verified_amount, $amount_deposited, $desc, "WEB", $host, 1);
        }

        if ($res === "success") {
            // Close out the original pending transaction + checkout record.
            mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vid' AND reference='$reference' AND status=2");
            mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1 WHERE vendor_id='$vid' AND reference='$reference' AND status=2");
            mysqli_query($connection_server, "UPDATE sas_user_payment_checkouts SET status=2 WHERE vendor_id='$vid' AND reference='$reference'");
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'success', 'message' => 'Wallet funded', 'amount' => $amount_deposited]);
        } else {
            if (ob_get_length()) ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Failed to credit wallet']);
        }
        exit;
    }

    if ($action == 'get_transaction_details') {
        try {
            $ref = $_GET['reference'] ?? '';
            $admin_param = isset($_GET['admin']) && $_GET['admin'] == '1';

            if ($is_spadmin) {
                $tables = array('sas_transactions', 'sas_vendor_transactions', 'sas_submitted_payments', 'sas_fund_transfer_requests', 'sas_super_admin_submitted_payments', 'sas_vendor_paid_bills');
            } else if ($is_admin) {
                $tables = array('sas_transactions', 'sas_vendor_transactions', 'sas_submitted_payments', 'sas_fund_transfer_requests');
            } else {
                $tables = array('sas_transactions', 'sas_submitted_payments', 'sas_fund_transfer_requests');
            }

            $row = null;
            $found_table = '';

            foreach ($tables as $table) {
                $stmt = false;
                if ($is_spadmin) {
                    $stmt = mysqli_prepare($connection_server, "SELECT * FROM $table WHERE reference = ?");
                    if ($stmt) mysqli_stmt_bind_param($stmt, "s", $ref);
                } else if ($is_admin) {
                    $stmt = mysqli_prepare($connection_server, "SELECT * FROM $table WHERE vendor_id = ? AND reference = ?");
                    if ($stmt) mysqli_stmt_bind_param($stmt, "is", $vendor_id, $ref);
                } else {
                    $stmt = mysqli_prepare($connection_server, "SELECT * FROM $table WHERE vendor_id = ? AND username = ? AND reference = ?");
                    if ($stmt) mysqli_stmt_bind_param($stmt, "iss", $vendor_id, $get_logged_user_details['username'], $ref);
                }

                if ($stmt) {
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) {
                        $found_table = $table;
                        break;
                    }
                }
            }

            if ($row) {
                if (!empty($row["api_id"]) && !empty($row["product_id"])) {
                    $vid_esc = (int)($row['vendor_id'] ?? 0);
                    $prod_id_esc = (int)($row["product_id"]);
                    $api_id_esc = (int)($row["api_id"]);
                    $stmt_prod = mysqli_prepare($connection_server, "SELECT * FROM sas_products WHERE vendor_id = ? AND id = ? LIMIT 1");
                    if ($stmt_prod) {
                        mysqli_stmt_bind_param($stmt_prod, "ii", $vid_esc, $prod_id_esc);
                        mysqli_stmt_execute($stmt_prod);
                        $get_prod = mysqli_fetch_array(mysqli_stmt_get_result($stmt_prod));
                    } else {
                        $get_prod = false;
                    }
                    $stmt_api = mysqli_prepare($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = ? AND id = ? LIMIT 1");
                    if ($stmt_api) {
                        mysqli_stmt_bind_param($stmt_api, "ii", $vid_esc, $api_id_esc);
                        mysqli_stmt_execute($stmt_api);
                        $get_api = mysqli_fetch_array(mysqli_stmt_get_result($stmt_api));
                    } else {
                        $get_api = false;
                    }
                    $type = ucwords(($get_prod["product_name"] ?? '') . " " . str_replace(array("-", "_"), " ", ($get_api["api_type"] ?? '')));
                } else {
                    $type = ucwords($row["type_alternative"] ?? $row["description"] ?? 'Transaction');
                }

                $details = array(
                    'Type' => $type,
                    'Reference' => $row['reference'] ?? 'N/A',
                    'Username' => isset($row['username']) ? $row['username'] : (isset($row['recipient_username']) ? 'To: '.$row['recipient_username'] : 'N/A'),
                    'Description' => isset($row['description']) ? $row['description'] : 'N/A',
                    'Amount' => '₦' . number_format($row['amount'] ?? 0, 2),
                    'Amount Paid' => '₦' . number_format($row['discounted_amount'] ?? 0, 2),
                    'Balance Before' => isset($row['balance_before']) ? '₦' . number_format($row['balance_before'], 2) : 'N/A',
                    'Balance After' => isset($row['balance_after']) ? '₦' . number_format($row['balance_after'], 2) : 'N/A',
                    'Mode' => isset($row['mode']) ? $row['mode'] : 'N/A',
                    'Status' => tranStatus($row['status'] ?? 0),
                    'Date' => formDate($row['date'] ?? ''),
                    'API Website' => isset($row['api_website']) ? $row['api_website'] : 'N/A'
                );

                if (!$admin_param) {
                    unset($details['Status']);
                    unset($details['API Website']);
                }

                if ($admin_param && $is_admin) {
                    $is_payment_order_flag = ($found_table == 'sas_submitted_payments');
                    $actions = adminTransactionActionButton($row["api_id"] ?? null, $row["product_id"] ?? null, $row["reference"] ?? '', $row["status"] ?? 0, $type, $is_payment_order_flag, $row["description"] ?? '');
                } else {
                    $actions = transactionActionButton($row["api_id"] ?? null, $row["product_id"] ?? null, $row["reference"] ?? '', $row["status"] ?? 0, $type, $row["description"] ?? '');
                }

                $json = json_encode(array('status' => 'success', 'data' => $details, 'actions' => (string)$actions), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json === false) {
                    $json = json_encode(array('status' => 'error', 'message' => 'Could not encode transaction details.'));
                }
                if (ob_get_length()) ob_clean();
                echo $json;
            } else {
                if (ob_get_length()) ob_clean();
                echo json_encode(array('status' => 'error', 'message' => 'Transaction not found'));
            }
        } catch (Throwable $e) {
            if (ob_get_length()) ob_clean();
            echo json_encode(array('status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()));
        }
        exit;
    }
}

if (ob_get_length()) ob_clean();
echo json_encode(array('status' => 'error', 'message' => 'Invalid request'));
