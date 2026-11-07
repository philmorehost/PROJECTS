<?php
/**
 * Guest order fulfillment — called ONLY from guest-webhook.php after PayHub payment has
 * been independently verified. Mirrors web/func/{airtime,data,cable,electric,exam,betting}.php's
 * gateway-calling sequence exactly (same reset-vars -> include gateway file -> branch on
 * $api_response pattern) but with chargeUser()/bc_atomic_debit_user() removed entirely —
 * the guest already paid via PayHub before this ever runs, there is no wallet to debit.
 *
 * On gateway failure there is no wallet to refund into (unlike the authenticated flow's
 * chargeUser("credit", ...) refund) — the order is marked GUEST_STATUS_FAILED and the
 * vendor is emailed so the PayHub payment can be refunded manually. This is a deliberate,
 * documented gap (see Phase 1 plan note), not an oversight.
 *
 * Requires guest-bootstrap.php to already be included by the caller.
 */

/**
 * Emails the guest a simple HTML receipt after successful delivery — only when they typed a
 * real email at checkout (extra_data.guest_email; the synthesized guest+ref@host placeholder
 * used for PayHub's initialize call is never stored there). Subject deliberately avoids the
 * words Transaction/Purchase/Fulfillment so sendVendorEmail()'s vendor-side "transaction
 * emails disabled" toggle doesn't suppress it — this is the guest's only durable server-sent
 * copy of their purchase.
 */
function guest_send_receipt_email($order, $delivery_desc, $service_outputs = []) {
    $extra = json_decode($order['extra_data'] ?? '{}', true) ?: [];
    $to = $extra['guest_email'] ?? '';
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $rows = [
        "Reference" => $order['reference'],
        "Service" => ucwords(str_replace(['-', '_'], ' ', $order['service_type'])),
        "Recipient" => $order['identity'],
        "Amount Paid" => "NGN " . number_format((float)$order['discounted_amount'], 2),
        "Status" => "Successful",
        "Details" => $delivery_desc,
    ];
    if (!empty($service_outputs['token'])) $rows["Token"] = $service_outputs['token'];
    if (!empty($service_outputs['token_unit'])) $rows["Units"] = $service_outputs['token_unit'];

    $body = "<div style='font-family:sans-serif;max-width:520px;margin:auto'>"
          . "<h2 style='color:#0D6EFD'>Payment Receipt</h2>"
          . "<p>Thank you for your payment. Here is your receipt:</p>"
          . "<table style='width:100%;border-collapse:collapse'>";
    foreach ($rows as $label => $value) {
        $body .= "<tr><td style='padding:8px;border-bottom:1px solid #eee;color:#64748B'>" . htmlspecialchars($label) . "</td>"
               . "<td style='padding:8px;border-bottom:1px solid #eee;font-weight:bold;text-align:right'>" . htmlspecialchars((string)$value) . "</td></tr>";
    }
    $body .= "</table><p style='color:#94A3B8;font-size:12px;margin-top:16px'>Keep this email as proof of payment.</p></div>";

    sendVendorEmail($to, "Your payment receipt — " . $order['reference'], $body);
}

/**
 * Requeries the provider for a GATEWAY_PENDING guest order and settles it to its true status —
 * some providers (notably Airtime and Direct Data) always answer "pending" at purchase time and
 * only settle on requery, usually within a minute. Mirrors web/func/requery-transaction.php's
 * gateway sequence (func/api-gateway/requery/{type}-{host}.php reading $get_api_reference_id)
 * minus the wallet refund logic — a failed guest order has no wallet to refund into, so it's
 * flagged to the vendor for a manual PayHub refund exactly like a fulfillment-time failure.
 *
 * Throttled via extra_data.last_requery_at: the app polls status.php every 2s, and hitting the
 * upstream provider that often would be abusive — one real requery per 15s window is plenty.
 */
function guest_requery_pending_order($order) {
    global $connection_server;

    if ((int)$order['status'] !== GUEST_STATUS_GATEWAY_PENDING) return;
    $reference = $order['reference'];
    $extra = json_decode($order['extra_data'] ?? '{}', true) ?: [];

    $last = (int)($extra['last_requery_at'] ?? 0);
    if (time() - $last < 15) return;
    guest_merge_extra_data($reference, ['last_requery_at' => time()]);

    $api_detail = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE id='" . (int)$order['api_id'] . "' LIMIT 1"));
    if (!$api_detail || empty($api_detail['api_key'])) return;

    $gw_name = guest_gateway_filename('requery', $api_detail['api_type'], $api_detail['api_base_url']);
    try {
        $res = guest_run_gateway('requery', $gw_name, [
            'api_detail' => $api_detail,
            // The requery gateway files were written only against the authenticated flow and
            // call getTransaction($requery_reference, ...) directly rather than accepting
            // injected data — getTransaction() now falls back to sas_guest_orders (func/bc-func.php)
            // when the reference isn't in sas_transactions, so this must be OUR reference, not
            // the provider's api_reference.
            'requery_reference' => $reference,
            'get_api_reference_id' => $order['api_reference'],
            'reference' => $reference,
            'get_logged_user_details' => ['vendor_id' => $order['vendor_id'], 'username' => 'guest:' . $order['identity']],
        ]);
    } catch (\Throwable $e) {
        // A bug in any one provider's gateway file (confirmed to happen — see the
        // airtime-localserver.php $curl_request crash this was built to survive) must never
        // take down the app's whole status-poll response with a fatal 500. Leave the order
        // exactly as it was; the next throttled poll tries again.
        bc_log_security_event('GUEST_REQUERY_GATEWAY_ERROR', $order['service_type'], $reference, $e->getMessage());
        return;
    }
    $api_response = $res['api_response'] ? strtolower($res['api_response']) : $res['api_response'];

    if ($api_response === 'successful') {
        if (!empty($res['api_response_description'])) guest_update_order($reference, 'description', $res['api_response_description']);
        guest_update_order($reference, 'status', (string)GUEST_STATUS_SUCCESS);
        guest_mark_fulfilled($reference);
        $order = guest_get_order($reference);
        guest_send_receipt_email($order, $order['description']);
    } elseif ($api_response === 'failed') {
        if (!empty($res['api_response_description'])) guest_update_order($reference, 'description', $res['api_response_description']);
        guest_update_order($reference, 'status', (string)GUEST_STATUS_FAILED);
        bc_log_security_event('GUEST_FULFILLMENT_FAILED_AFTER_PAYMENT', $order['service_type'], $reference, "Paid order failed on requery — manual PayHub refund required. Identity: " . $order['identity']);
        $vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . (int)$order['vendor_id'] . "' LIMIT 1"));
        if ($vendor && !empty($vendor['email'])) {
            sendVendorEmail($vendor['email'], "ACTION REQUIRED: Guest order $reference failed after payment",
                "A Guest Mode order settled as FAILED on requery AFTER the customer's PayHub payment was captured.<br><br>"
              . "Reference: $reference<br>Service: " . $order['service_type'] . "<br>Identity: " . $order['identity'] . "<br>Amount: " . $order['discounted_amount'] . "<br><br>"
              . "Please issue a manual refund via PayHub for this reference.");
        }
    }
    // 'pending' or null: leave as-is, the next throttled requery will try again.
}

/**
 * Independently verifies a still-pending order against PayHub and, if genuinely paid, atomically
 * claims + fulfills it. This is what guest-webhook.php calls when PayHub's server-to-server
 * webhook arrives — but a guest checkout should not be a single point of failure on PayHub's
 * webhook actually being registered/reachable for this merchant. status.php calls this exact
 * same function on every poll too, so the app's own "confirming your payment" screen drives
 * completion by itself if the webhook never shows up. guest_claim_order_for_payment() is an
 * atomic compare-and-swap, so it's safe for the webhook and several concurrent polls to all
 * call this for the same reference — only one ever wins the claim and fulfills.
 */
function guest_attempt_paid_fulfillment($reference) {
    $order = guest_get_order($reference);
    if (!$order || (int)$order['status'] !== GUEST_STATUS_PENDING_PAYMENT) {
        return;
    }

    $vendor_id = $order['vendor_id'];
    // PayHub's verify endpoint is keyed by ITS OWN reference (captured into payment_reference
    // at checkout-init time — see the comment there), not the one we originally submitted.
    // Fall back to our own reference only for pre-fix orders that never got a payment_reference.
    $verify_ref = !empty($order['payment_reference']) ? $order['payment_reference'] : $reference;
    $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($verify_ref), "", $vendor_id, false);
    $v_data = json_decode($verify_res, true);
    $verified_tx = null;
    if (($v_data['status'] ?? "") == "success") {
        $tx_raw = json_decode($v_data['json_result'], true);
        $tx_data = $tx_raw['data'] ?? $tx_raw;
        // SECURITY: PayHub verify returns top-level `status:true` = "retrieved"; the real
        // payment status is `data.status`. Only fulfill on success/successful.
        $tx_status = strtolower($tx_data['status'] ?? "");
        if ($tx_status == "success" || $tx_status == "successful") {
            $verified_tx = $tx_data;
        }
    }
    if (!$verified_tx) return;

    // PayHub's verify response returns amounts in kobo (confirmed live: a real ₦197 guest
    // charge verified with "amount":19700) — the same Naira/Kobo ambiguity processPayhubSuccess()
    // already documents for the authenticated flow (func/bc-func.php ~3713). Unlike that generic
    // reconciliation path, a guest order always has a known expected amount to check against, so
    // just test whether dividing by 100 lands on it rather than guessing from magnitude alone.
    $verified_amount = (float)($verified_tx['amount'] ?? 0);
    if ($verified_amount > 0 && abs(($verified_amount / 100) - (float)$order['discounted_amount']) < 0.5) {
        $verified_amount = $verified_amount / 100;
    }
    if ($verified_amount <= 0 || abs($verified_amount - (float)$order['discounted_amount']) > 0.5) {
        bc_log_security_event('SECURITY', 'guest_status_verify', $reference, "Amount mismatch: quoted {$order['discounted_amount']}, paid $verified_amount");
        return;
    }

    if (!guest_claim_order_for_payment($reference)) return;
    guest_update_order($reference, 'payment_reference', $verified_tx['reference'] ?? $verify_ref);

    $order = guest_get_order($reference);
    guest_fulfill_order($order);
}

function guest_fulfill_order($order) {
    global $connection_server;

    $vendor_id = $order['vendor_id'];
    $service = $order['service_type'];
    $identity = $order['identity'];
    $reference = $order['reference'];
    $extra = json_decode($order['extra_data'] ?? '{}', true) ?: [];
    $api_id = $order['api_id'];
    $product_id = $order['product_id'];
    $api_base_url = $order['api_website'];

    $type_prefix_map = [
        'airtime' => 'airtime',
        'data' => $extra['data_type'] ?? 'data',
        'cable' => 'cable',
        'electric' => 'electric',
        'exam' => 'exam',
        'betting' => 'betting',
    ];
    $type_prefix = $type_prefix_map[$service] ?? $service;

    // Real provider gateway files (func/api-gateway/*.php) are included directly and read
    // plain variables out of the including scope — $isp/$epp, $product_name (always set as
    // an isp/epp alias in the original web/func/*.php scope), $phone_no/$iuc_no/$meter_number/
    // $customer_id, $quantity, $type, $amount, $api_detail. These must be rebuilt here since
    // sas_guest_orders only stores api_id/product_id, not the full sas_apis row.
    // $reference and $get_logged_user_details are also read directly by several gateway files
    // (e.g. every electric-*.php provider writes an sas_electric_purchaseds audit row keyed off
    // $get_logged_user_details["vendor_id"/"username"] and $reference; dd-data-clubkonnect-com.php
    // builds its RequestID from $reference) — without these, those inserts/requests silently use
    // blank values instead of erroring, corrupting audit data or breaking provider idempotency keys.
    $api_detail = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE id='" . (int)$api_id . "' LIMIT 1"));
    $vars = [
        'api_detail' => $api_detail,
        'amount' => $order['amount'],
        'discounted_amount' => $order['discounted_amount'],
        'reference' => $reference,
        'get_logged_user_details' => ['vendor_id' => $vendor_id, 'username' => 'guest:' . $identity],
    ];

    switch ($service) {
        case 'airtime':
            $vars += ['isp' => $extra['network'], 'product_name' => $extra['network'], 'phone_no' => $identity];
            break;
        case 'data':
            $vars += ['isp' => $extra['network'], 'product_name' => $extra['network'], 'phone_no' => $identity, 'quantity' => $extra['quantity'], 'type' => $extra['data_type']];
            break;
        case 'cable':
            $vars += ['isp' => $extra['type'], 'product_name' => $extra['type'], 'iuc_no' => $identity, 'quantity' => $extra['package']];
            break;
        case 'electric':
            $vars += ['epp' => $extra['provider'], 'product_name' => $extra['provider'], 'meter_number' => $identity, 'type' => $extra['meter_type']];
            break;
        case 'exam':
            $vars += ['epp' => $extra['type'], 'product_name' => $extra['type'], 'quantity' => $extra['quantity']];
            break;
        case 'betting':
            $vars += ['epp' => $extra['provider'], 'product_name' => $extra['provider'], 'customer_id' => $identity];
            break;
    }

    $gw_name = guest_gateway_filename('purchase', $type_prefix, $api_base_url);
    try {
        $res = guest_run_gateway('purchase', $gw_name, $vars);
        $api_response = $res['api_response'] ? strtolower($res['api_response']) : $res['api_response'];
    } catch (\Throwable $e) {
        // A bug in any one provider's gateway file must never crash the whole checkout/poll
        // response with a fatal 500 (confirmed to happen — see the airtime-localserver.php
        // requery crash this pattern was built to survive). Payment was already captured by
        // PayHub at this point, so treat this exactly like a failed gateway response: no
        // wallet to auto-refund into, flag the vendor for a manual PayHub refund.
        bc_log_security_event('GUEST_FULFILLMENT_GATEWAY_ERROR', $service, $reference, $e->getMessage());
        $res = ['api_response' => 'failed', 'api_response_description' => 'Gateway error', 'api_response_reference' => null];
        $api_response = 'failed';
    }

    if ($api_response === 'successful') {
        guest_update_order($reference, 'api_reference', $res['api_response_reference']);
        guest_update_order($reference, 'description', $res['api_response_description']);
        guest_update_order($reference, 'status', (string)GUEST_STATUS_SUCCESS);
        guest_mark_fulfilled($reference);
        guest_record_abuse_attempt($vendor_id, $service, $identity, $reference);

        $service_outputs = [];
        if ($service === 'electric') {
            $service_outputs = [
                'meter_number' => $res['api_response_meter_number'],
                'token' => $res['api_response_token'],
                'token_unit' => $res['api_response_token_unit'],
            ];
        } elseif ($service === 'betting') {
            $service_outputs = [
                'gateway_customer_id' => $res['api_response_customer_id'],
                'token' => $res['api_response_token'],
            ];
        }
        if ($service_outputs) guest_merge_extra_data($reference, $service_outputs);

        guest_send_receipt_email($order, $res['api_response_description'], $service_outputs);

        return ['status' => 'success', 'desc' => $res['api_response_description']];
    }

    if ($api_response === 'pending') {
        guest_update_order($reference, 'api_reference', $res['api_response_reference']);
        guest_update_order($reference, 'description', $res['api_response_description']);
        guest_update_order($reference, 'status', (string)GUEST_STATUS_GATEWAY_PENDING);
        guest_record_abuse_attempt($vendor_id, $service, $identity, $reference);
        return ['status' => 'pending', 'desc' => $res['api_response_description']];
    }

    // Failed: payment was already captured by PayHub — there is no wallet to auto-refund into.
    // Flag loudly for manual PayHub refund + support follow-up rather than silently losing the money.
    guest_update_order($reference, 'api_reference', $res['api_response_reference'] ?? '');
    guest_update_order($reference, 'description', $res['api_response_description'] ?? 'Gateway failed');
    guest_update_order($reference, 'status', (string)GUEST_STATUS_FAILED);
    bc_log_security_event('GUEST_FULFILLMENT_FAILED_AFTER_PAYMENT', $service, $reference, "Paid order failed at gateway — manual PayHub refund required. Identity: $identity");

    $vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . (int)$vendor_id . "' LIMIT 1"));
    if ($vendor && !empty($vendor['email'])) {
        $subject = "ACTION REQUIRED: Guest order $reference failed after payment";
        $body = "A Guest Mode order failed at the delivery gateway AFTER the customer's PayHub payment was captured.<br><br>"
              . "Reference: $reference<br>Service: $service<br>Identity: $identity<br>Amount: " . $order['discounted_amount'] . "<br><br>"
              . "Please issue a manual refund via PayHub for this reference.";
        sendVendorEmail($vendor['email'], $subject, $body);
    }

    return ['status' => 'failed', 'desc' => 'Transaction Failed'];
}
