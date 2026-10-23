<?php
/**
 * bc-gateway.php - shared helpers for upstream API gateway responses.
 *
 * A purchase is debited BEFORE its upstream gateway is called, and the provider debits ITS own
 * side the moment it accepts the request. So an unreadable reply must NOT be treated as proof
 * of failure: refunding it while the provider has already delivered and charged our wallet is a
 * straight loss. The helpers here encode that:
 *
 *  - bc_gateway_json_decode()        decode tolerantly - a remote host with display_errors on
 *                                    wraps its JSON in deprecation/notice text, which made
 *                                    json_decode() fail and hid a perfectly good status.
 *  - bc_gateway_curl_outcome()       classify a transport error: "could not connect" means the
 *                                    request was never sent (safe to fail), while a timeout or a
 *                                    lost reply means the provider may have processed it.
 *  - bc_gateway_provider_verdict()   map the provider's own status word onto
 *                                    successful|pending|failed, or NULL when it is not a verdict
 *                                    at all (an unknown word is not a failure).
 *  - bc_gateway_settle_purchase()    settle whatever the gateway left behind: an unresolved
 *                                    purchase becomes PENDING (reconciled by the requery queue),
 *                                    never a refund on its own.
 *  - bc_gateway_refund_is_safe()     a refund may only be given on a DEFINITIVE provider
 *                                    failure, never on an unreadable/absent reply.
 *  - bc_gateway_amount_covers()      did the money that settled COVER the amount the reference
 *                                    was created for? One-sided: paying more is the gateway's fee
 *                                    added on top and is still a real payment; paying less is not.
 *  - bc_gateway_log_raw_response()   keep the unreadable body for diagnosis.
 *
 * Both editions (SAAS / NON-SAAS) ship an identical copy of this file.
 */

if (!function_exists('bc_gateway_json_decode')) {
    /**
     * Decode a gateway response body, tolerating noise printed around or ahead of the
     * JSON (PHP notices/deprecations, BOM, stray HTML) by the remote installation.
     *
     * @return array decoded payload, or an empty array when nothing decodable was found
     */
    function bc_gateway_json_decode($raw)
    {
        $raw = (string)$raw;

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }
}

if (!function_exists('bc_gateway_log_raw_response')) {
    /**
     * Append (a bounded copy of) a raw gateway response to logs/gateway-raw.log.
     */
    function bc_gateway_log_raw_response($raw, $context = '')
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return;
        }
        if (strlen($raw) > 1000) {
            $raw = substr($raw, 0, 1000) . '...[truncated]';
        }
        $line = "[" . date('Y-m-d H:i:s') . "] " . ($context !== '' ? $context . " " : "") . $raw . "\n";
        @file_put_contents(__DIR__ . "/../logs/gateway-raw.log", $line, FILE_APPEND);
    }
}

if (!function_exists('bc_gateway_curl_outcome')) {
    /**
     * Classify a cURL transport error.
     *
     * "failed"  - the request never left us (DNS or connect failure), so nothing can have been
     *             charged provider-side and refunding is correct.
     * "pending" - anything else (timeout, empty/lost reply, reset after send). The provider may
     *             have received, processed and charged the request, so the transaction must be
     *             reconciled by a requery instead of refunded blind.
     *
     * @return string "failed" or "pending"
     */
    function bc_gateway_curl_outcome($curl_errno)
    {
        $never_sent = array(6 /* COULDNT_RESOLVE_HOST */, 7 /* COULDNT_CONNECT */, 3 /* URL_MALFORMAT */);
        return in_array((int)$curl_errno, $never_sent, true) ? "failed" : "pending";
    }
}

if (!function_exists('bc_gateway_provider_verdict')) {
    /**
     * Map the provider's own status word to one of the three states the handlers know.
     *
     * @return string|null "successful" | "pending" | "failed", or NULL when the payload carries
     *                     no verdict we recognise. NULL must NEVER be treated as a failure: a
     *                     word we do not know (or a missing key, an auth error, an HTML error
     *                     page) is not evidence that the provider did nothing.
     */
    function bc_gateway_provider_verdict($payload, $status_key = 'status')
    {
        if (!is_array($payload)) {
            return null;
        }

        $raw = null;
        foreach (array($status_key, 'status', 'Status', 'STATUS', 'state', 'State') as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $candidate = strtolower(trim((string)$payload[$key]));
                if ($candidate !== '') {
                    $raw = $candidate;
                    break;
                }
            }
        }
        if ($raw === null) {
            return null;
        }

        if (in_array($raw, array('successful', 'success', 'successfull', 'completed', 'complete', 'delivered', 'done', 'ok', '200'), true)) {
            return "successful";
        }
        if (in_array($raw, array('pending', 'processing', 'process', 'queued', 'queue', 'in-progress', 'in_progress', 'awaiting', 'submitted'), true)) {
            return "pending";
        }
        if (in_array($raw, array('failed', 'fail', 'failure', 'cancelled', 'canceled', 'rejected', 'reverse', 'reversed', 'refunded', 'refund', 'error', 'declined', 'invalid', 'expired'), true)) {
            return "failed";
        }

        return null;
    }
}

if (!function_exists('bc_gateway_refund_is_safe')) {
    /**
     * May this outcome be refunded?
     *
     * Only a definitive provider failure may. Anything else - a timeout, an unreadable body, a
     * missing status, a transport error during a requery - must leave the transaction where it is
     * so the requery queue can still resolve it, because the provider may already have delivered
     * the service and charged our wallet for it.
     *
     * @return bool
     */
    function bc_gateway_refund_is_safe(&$api_response, &$api_response_text, $require_provider_word = true)
    {
        if ($api_response !== "failed") {
            return false;
        }
        if (!$require_provider_word) {
            return true;
        }

        // "1"/"0"/"true"/"false" are what the gateways write when the request transport failed -
        // that is a sentinel, not a provider verdict, so it must not authorise a refund.
        $word = trim(strtolower((string)$api_response_text));

        return $word !== '' && !in_array($word, array('1', '0', 'true', 'false'), true);
    }
}

if (!function_exists('bc_gateway_amount_covers')) {
    /**
     * Did the money that actually settled COVER what this reference was created for?
     *
     * One-sided on purpose. A payer is normally charged MORE than the merchant asked for, because
     * the gateway adds its transaction fee on top at the moment of payment: a reference created for
     * N100 settles as N101.53. Demanding equality read that as an amount-manipulation attempt and
     * refused to credit a payment that had already been taken - the customer's money had left their
     * account and the wallet was never funded ("Payment confirmed but crediting failed").
     *
     * The property worth keeping is UNDERPAYMENT. The amount is rendered into the page that drives
     * the payment, so it can be rewritten there - or the gateway called directly with the same
     * reference - to settle a token sum while the record still holds the larger figure. Paying MORE
     * than was asked cannot be turned against the merchant and cannot conjure a credit that was
     * never funded, so it is accepted; the caller credits the RECORDED amount, never the inflated
     * settled figure, so the gateway's fee is not credited to the customer.
     *
     * Comparison is done in integer minor units (kobo) so binary floating-point drift cannot turn an
     * exact payment into a mismatch.
     *
     * @param float $expected Amount the reference was created for, in major units.
     * @param float $settled  Amount the gateway reports as settled, in major units.
     * @param int   $tolerance_minor Allowed shortfall in minor units (rounding noise only).
     * @return bool
     */
    function bc_gateway_amount_covers($expected, $settled, $tolerance_minor = 1)
    {
        $expected_minor = (int)round(((float)$expected) * 100);
        $settled_minor  = (int)round(((float)$settled) * 100);

        return $settled_minor >= ($expected_minor - (int)$tolerance_minor);
    }
}

if (!function_exists('bc_gateway_settle_purchase')) {
    /**
     * Settle whatever a gateway left behind, so no purchase can stay unclassified.
     *
     * An unrecognised/unparseable upstream reply becomes PENDING, not failed: the provider may
     * have accepted the request and charged our wallet, and the requery queue will establish the
     * real outcome (and refund then, if the provider says it failed). A reply the gateway already
     * classified is never overridden.
     *
     * @return bool true when the outcome had to be forced
     */
    function bc_gateway_settle_purchase(&$api_response, &$api_response_text, &$api_response_description, &$api_response_status, $label = '', $raw_response = '', $on_unknown = 'pending')
    {
        if (in_array($api_response, array("successful", "pending", "failed"), true)) {
            return false;
        }

        $previous = is_null($api_response) ? 'NULL' : (string)$api_response;

        $api_response = ($on_unknown === 'failed') ? "failed" : "pending";
        $api_response_text = "";
        if (trim((string)$api_response_description) === '') {
            $api_response_description = ($label !== '' ? $label : (($api_response === "failed") ? "Transaction Failed | no valid response from the upstream gateway" : "Transaction Pending | awaiting confirmation from the upstream gateway"));
        }
        $api_response_status = ($api_response === "failed") ? 3 : 2;

        bc_gateway_log_raw_response($raw_response, "UNRESOLVED(" . $previous . ")");

        return true;
    }
}
