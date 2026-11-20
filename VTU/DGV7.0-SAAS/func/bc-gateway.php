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

if (!function_exists('bc_hdk_provider_verdict')) {
    /**
     * HDK sometimes puts the failure reason in a message/description instead of using
     * Status=failed. Recognise only explicit provider wording; unknown or transport replies
     * remain unresolved and are reconciled by requery.
     */
    function bc_hdk_provider_verdict($payload)
    {
        $verdict = bc_gateway_provider_verdict($payload, 'Status');
        if ($verdict !== null || !is_array($payload)) {
            return $verdict;
        }

        $text = '';
        foreach (array('Status', 'status', 'message', 'Message', 'desc', 'description', 'error', 'error_message') as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $text .= ' ' . strtolower(trim((string)$payload[$key]));
            }
        }
        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(not available|unavailable|out of stock|sold out|no stock|insufficient balance|insufficient funds|not enough balance|invalid (plan|package|product|network|number)|plan (not found|does not exist|unavailable)|failed|failure|rejected|declined|cancelled|canceled|refunded|error)\b/i', $text)) {
            return 'failed';
        }
        if (preg_match('/\b(pending|processing|queued|awaiting|submitted|in[- ]progress)\b/i', $text)) {
            return 'pending';
        }
        if (preg_match('/\b(success|successful|completed|delivered|credited|done)\b/i', $text)) {
            return 'successful';
        }

        return null;
    }
}


if (!function_exists('bc_gateway_word_verdict')) {
    /**
     * Map a free-text provider status (a word, a short message, or a sentence like
     * "Transaction was successful" / "Insufficient balance, try again") onto
     * successful|pending|failed — or NULL when it carries no verdict we recognise.
     *
     * Gateway files historically parked ANY unrecognised reply in their catch-all
     * "failed" branch, so a provider that delivered while answering "success" or
     * "ORDER_COMPLETED" was recorded as failed AND refunded: the customer kept the
     * service and the money (§32). NULL means "not evidence of failure" — callers
     * must park the transaction as pending and let the requery queue decide.
     */
    function bc_gateway_word_verdict($text)
    {
        $t = strtolower(trim((string)$text));
        if ($t === '') {
            return null;
        }

        // Single-token answers reuse the vocabulary of bc_gateway_provider_verdict().
        $token = trim($t, " \t\n\r\0\x0B.:;,-");
        if (in_array($token, array('successful', 'success', 'successfull', 'completed', 'complete', 'delivered', 'done', 'ok', '200'), true)) {
            return 'successful';
        }
        if (in_array($token, array('pending', 'processing', 'process', 'queued', 'queue', 'in-progress', 'in_progress', 'awaiting', 'submitted', 'received'), true)) {
            return 'pending';
        }
        if (in_array($token, array('failed', 'fail', 'failure', 'cancelled', 'canceled', 'rejected', 'reverse', 'reversed', 'refunded', 'refund', 'error', 'declined', 'invalid', 'expired'), true)) {
            return 'failed';
        }

        // Sentences: hard failure signatures first, so "transaction was not
        // successful" or "completed but reversed" can never read as success.
        if (preg_match('/\b(insufficient|declined|rejected|cancelled|canceled|reversed|refunded|not successful|unsuccessful|unsuccessfully|failed|failure|out of stock|sold out|invalid|expired|denied|blocked)\b/', $t)) {
            return 'failed';
        }
        if (preg_match('/\b(successful|successfully|success|completed|complete|delivered|credited|approved|done)\b/', $t)) {
            return 'successful';
        }
        if (preg_match('/\b(pending|processing|queued|awaiting|submitted|in progress|received)\b/', $t)) {
            return 'pending';
        }

        return null;
    }
}

if (!function_exists('bc_gateway_failure_needs_operator')) {
    /** Failures caused by OUR side (balance, stock, credentials) need a human. */
    function bc_gateway_failure_needs_operator($text)
    {
        return (bool)preg_match('/\b(insufficient|out of stock|sold out|unauthori[sz]ed|invalid (api[- ]?key|key|credential)|low balance|account (suspended|disabled|blocked))\b/i', (string)$text);
    }
}

if (!function_exists('bc_gateway_notify_issue')) {
    /**
     * Email an upstream-provider problem to the operator of this install — the
     * super admin on SAAS, the vendor/admin on NON-SAAS — so it can be
     * investigated. End users never see provider internals; errors go here and
     * to logs/gateway-raw.log instead. Throttled to one mail per distinct
     * problem per 30 minutes so a provider outage cannot flood the inbox.
     * Best-effort: never breaks the purchase that triggered it.
     */
    function bc_gateway_notify_issue($kind, $transition, $provider_text = '', $raw = '', $label = '')
    {
        static $sent = false;
        if ($sent) {
            return false;
        }
        global $connection_server;
        if (!($connection_server instanceof mysqli)) {
            return false;
        }

        $vendor_id = (int)($GLOBALS['get_logged_user_details']['vendor_id'] ?? ($GLOBALS['get_transaction_data']['vendor_id'] ?? 0));
        $fingerprint = md5($kind . '|' . $transition . '|' . strtolower(trim((string)$provider_text)) . '|' . $vendor_id);
        $state_file = __DIR__ . '/../logs/gateway-notify.state';
        $now = time();
        $state = array();
        if (is_file($state_file)) {
            $decoded = json_decode((string)@file_get_contents($state_file), true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
        if (isset($state[$fingerprint]) && ($now - (int)$state[$fingerprint]) < 1800) {
            return false;
        }

        if (!function_exists('bc_is_saas_edition') && file_exists(__DIR__ . '/bc-edition.php')) {
            include_once(__DIR__ . '/bc-edition.php');
        }
        $is_saas = function_exists('bc_is_saas_edition') ? bc_is_saas_edition() : false;

        $recipients = array();
        if ($is_saas) {
            $q = @mysqli_query($connection_server, "SELECT email FROM sas_super_admin WHERE email<>'' ORDER BY id ASC LIMIT 5");
            if ($q) {
                while ($r = mysqli_fetch_assoc($q)) {
                    $recipients[] = $r['email'];
                }
            }
        }
        if (!$recipients) {
            $q = $vendor_id > 0
                ? @mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='$vendor_id' LIMIT 1")
                : @mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE email<>'' ORDER BY id ASC LIMIT 1");
            if ($q) {
                while ($r = mysqli_fetch_assoc($q)) {
                    $recipients[] = $r['email'];
                }
            }
        }
        if (!$recipients) {
            return false;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? 'cli');
        $reference = (string)($GLOBALS['reference'] ?? ($GLOBALS['requery_reference'] ?? ''));
        $username = (string)($GLOBALS['get_logged_user_details']['username'] ?? ($GLOBALS['get_transaction_data']['username'] ?? ''));
        $excerpt = trim((string)$raw);
        if (strlen($excerpt) > 600) {
            $excerpt = substr($excerpt, 0, 600) . '...[truncated]';
        }
        $excerpt = (string)preg_replace('/(api[_-]?key|token|authorization)(["\']?\s*[:=]\s*["\']?)[A-Za-z0-9._\-]+/i', '$1$2***', $excerpt);
        $esc = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        $body = '<p>An upstream provider reply could not be settled automatically and needs investigation.</p>'
            . '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;">'
            . '<tr><td><b>Site</b></td><td>' . $esc($host) . '</td></tr>'
            . '<tr><td><b>Time</b></td><td>' . $esc(date('Y-m-d H:i:s')) . '</td></tr>'
            . '<tr><td><b>Issue</b></td><td>' . $esc($kind) . ($label !== '' ? ' (' . $esc($label) . ')' : '') . '</td></tr>'
            . '<tr><td><b>Classification</b></td><td>' . $esc($transition) . '</td></tr>'
            . '<tr><td><b>Provider status</b></td><td>' . $esc($provider_text) . '</td></tr>'
            . ($reference !== '' ? '<tr><td><b>Reference</b></td><td>' . $esc($reference) . '</td></tr>' : '')
            . ($username !== '' ? '<tr><td><b>Customer</b></td><td>' . $esc($username) . '</td></tr>' : '')
            . ($vendor_id > 0 ? '<tr><td><b>Vendor ID</b></td><td>' . $esc($vendor_id) . '</td></tr>' : '')
            . '<tr><td><b>Raw response</b></td><td><pre style="white-space:pre-wrap;word-break:break-word;">' . $esc($excerpt) . '</pre></td></tr>'
            . '</table>'
            . '<p>The customer was NOT shown provider details. Any affected purchase has been left pending for requery (never refunded on an uncertain reply), so no money moves until the provider confirms the real outcome.</p>';

        $subject = 'Gateway provider issue - investigation needed';
        $delivered = false;
        foreach ($recipients as $to) {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($is_saas && function_exists('sendSuperAdminEmail')) {
                @sendSuperAdminEmail($to, $subject, $body);
                $delivered = true;
            } elseif (function_exists('sendVendorEmail')) {
                @sendVendorEmail($to, $subject, $body);
                $delivered = true;
            }
        }
        if (!$delivered) {
            return false;
        }

        $sent = true;
        $state[$fingerprint] = $now;
        foreach ($state as $k => $ts) {
            if (($now - (int)$ts) > 86400) {
                unset($state[$k]);
            }
        }
        @file_put_contents($state_file, json_encode($state), LOCK_EX);
        if (function_exists('bc_gateway_log_raw_response')) {
            bc_gateway_log_raw_response($kind . ' ' . $transition . ' text=' . trim((string)$provider_text), 'ISSUE-NOTIFIED');
        }
        return true;
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

        if ($word === '' || in_array($word, array('1', '0', 'true', 'false'), true)) {
            return false;
        }

        // §32: only a DEFINITIVE provider failure may authorise a refund. Any other
        // wording - including success words a gateway catch-all mislabelled as
        // "failed" - means the provider may already have delivered and charged our
        // wallet, so the money stays put and requery establishes the truth.
        return bc_gateway_word_verdict($word) === 'failed';
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
        // §32 second opinion: gateway catch-alls park ANY unrecognised provider
        // reply in the "failed" branch, including replies that mean the service
        // was DELIVERED ("success", "ORDER_COMPLETED", ...). Refunding those hands
        // the customer the service AND the money. A failure may only stand when
        // the provider's own words definitively say it failed; anything else is
        // corrected here, before any refund branch can run.
        if ($api_response === "failed") {
            $verdict = function_exists('bc_gateway_word_verdict') ? bc_gateway_word_verdict($api_response_text) : null;
            if ($verdict === 'successful') {
                $api_response = "successful";
                $api_response_status = 1;
                bc_gateway_log_raw_response($raw_response, "VERDICT-CORRECTED(failed=>successful)");
                bc_gateway_notify_issue('classification_corrected', 'failed=>successful', $api_response_text, $raw_response, $label);
                return true;
            }
            if ($verdict === 'pending') {
                $api_response = "pending";
                $api_response_status = 2;
                bc_gateway_notify_issue('provider_pending', 'failed=>pending', $api_response_text, $raw_response, $label);
                return true;
            }
            if ($verdict === null) {
                // Unknown wording is not proof of failure: park as pending for
                // the requery queue instead of letting the refund branch run.
                $failed_text = $api_response_text;
                $api_response = "pending";
                $api_response_text = "";
                $api_response_status = 2;
                if (trim((string)$api_response_description) === '' || stripos((string)$api_response_description, 'Transaction Failed') === 0) {
                    $api_response_description = "Transaction Pending | awaiting confirmation from the upstream gateway";
                }
                bc_gateway_log_raw_response($raw_response, "UNRESOLVED-FAILED");
                bc_gateway_notify_issue('unresolved_failure', 'failed=>pending', $failed_text, $raw_response, $label);
                return true;
            }
            // Definitive provider failure: the refund may proceed. Failures caused
            // by our own side (balance/stock/credentials) are still escalated.
            if (function_exists('bc_gateway_failure_needs_operator') && bc_gateway_failure_needs_operator($api_response_text)) {
                bc_gateway_notify_issue('provider_failure', 'failed', $api_response_text, $raw_response, $label);
            }
            return false;
        }

        if (in_array($api_response, array("successful", "pending"), true)) {
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
        bc_gateway_notify_issue('unresolved_reply', $previous . '=>' . $api_response, $api_response_text, $raw_response, $label);

        return true;
    }
}
