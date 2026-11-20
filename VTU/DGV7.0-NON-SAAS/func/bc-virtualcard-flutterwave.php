<?php
/**
 * DGV7 §25 — Virtual Dollar Card, Flutterwave ONLY.
 *
 * Every previously integrated virtual-card provider (Chimoney cards, BSICards)
 * has been removed. Card issuance, funding, freeze and unfreeze all go through
 * the Flutterwave provider seam (bc-fintech-providers.php), so credentials come
 * from the gateway tables the vendor already maintains and every call fails
 * closed — with a machine-readable refusal — while the Flutterwave card
 * endpoints are not configured in the registry. No other provider is consulted,
 * on any code path.
 *
 * §26 STATUS (official docs re-checked 2026-11-20): developer.flutterwave.com's
 * current API index no longer publishes a Virtual Cards API, so the registry's
 * virtual_cards* endpoints stay EMPTY and this adapter keeps refusing with
 * 'endpoint_not_configured' rather than calling an undocumented URL. The day
 * Flutterwave documents (or re-documents) card issuance, fill those three
 * registry paths and this adapter goes live unchanged.
 *
 * Return shape matches the legacy adapters so callers keep working:
 *   ['status' => 'success'|'error', 'message' => ..., 'reference' => ..., 'card_id' => ...]
 */

require_once(__DIR__ . '/bc-fintech-providers.php');

if (!function_exists('bc_vc_flw_call')) {
    /** One Flutterwave card call through the registry seam. Never guesses a URL. */
    function bc_vc_flw_call($vendor_id, $endpoint, $method, $payload = null) {
        if (!function_exists('bc_fintech_provider_request')) {
            return ['status' => 'error', 'message' => 'Virtual card service is not available on this installation.'];
        }
        $res = bc_fintech_provider_request((int)$vendor_id, 'flutterwave', $endpoint, $method, $payload);
        if (!is_array($res) || empty($res['ok'])) {
            $reason = is_array($res) ? (string)($res['reason'] ?? 'provider_error') : 'provider_error';
            $message = function_exists('bc_fintech_provider_refusal_message')
                ? bc_fintech_provider_refusal_message($reason)
                : 'Virtual card provider is not configured yet.';
            return ['status' => 'error', 'message' => $message, 'reason' => $reason];
        }
        return ['status' => 'success', 'data' => $res['data'] ?? []];
    }
}

if (!function_exists('issueVirtualCardFlutterwave')) {
    function issueVirtualCardFlutterwave($vid, $username, $name, $email, $amount_usd) {
        global $connection_server;
        $vid = (int)$vid;
        $res = bc_vc_flw_call($vid, 'virtual_cards', 'POST', [
            'name_on_card' => (string)$name,
            'email' => (string)$email,
            'currency' => 'USD',
            'amount' => (float)$amount_usd,
        ]);
        if ($res['status'] !== 'success') return $res;

        $data = is_array($res['data']) ? $res['data'] : [];
        $card_id = (string)($data['id'] ?? $data['card_id'] ?? '');
        $reference = 'FLWVC-' . date('ymdHis') . '-' . $vid;
        $masked = (string)($data['masked_pan'] ?? $data['card_pan'] ?? '**** **** **** ****');
        $exp_m = (string)($data['expiry_month'] ?? '');
        $exp_y = (string)($data['expiry_year'] ?? '');
        $cvv = (string)($data['cvv'] ?? '');

        $uname_e = mysqli_real_escape_string($connection_server, (string)$username);
        $ref_e = mysqli_real_escape_string($connection_server, $reference);
        $cid_e = mysqli_real_escape_string($connection_server, $card_id);
        $name_e = mysqli_real_escape_string($connection_server, (string)$name);
        $pan_e = mysqli_real_escape_string($connection_server, $masked);
        $em_e = mysqli_real_escape_string($connection_server, $exp_m);
        $ey_e = mysqli_real_escape_string($connection_server, $exp_y);
        $cvv_e = mysqli_real_escape_string($connection_server, $cvv);
        $amt = (float)$amount_usd;
        mysqli_query($connection_server, "INSERT INTO sas_virtual_cards_v2
            (vendor_id, username, reference, chimoney_card_id, card_name, masked_pan, expiry_month, expiry_year, cvv, balance_usd, status, provider)
            VALUES ('$vid', '$uname_e', '$ref_e', '$cid_e', '$name_e', '$pan_e', '$em_e', '$ey_e', '$cvv_e', '$amt', 'active', 'flutterwave')");

        return ['status' => 'success', 'message' => 'Card issued', 'reference' => $reference, 'card_id' => $card_id !== '' ? $card_id : $reference];
    }
}

if (!function_exists('fundVirtualCardFlutterwave')) {
    function fundVirtualCardFlutterwave($vid, $card_ref, $amount_usd) {
        global $connection_server;
        $vid = (int)$vid;
        $ref_e = mysqli_real_escape_string($connection_server, (string)$card_ref);
        $q = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_e' AND vendor_id='$vid' LIMIT 1");
        $card = $q ? mysqli_fetch_assoc($q) : null;
        if (!$card) return ['status' => 'error', 'message' => 'Card not found.'];
        if (($card['provider'] ?? '') !== 'flutterwave') {
            return ['status' => 'error', 'message' => 'This is a legacy card from a retired provider and can no longer be funded. Please issue a new Flutterwave card.'];
        }
        $res = bc_vc_flw_call($vid, 'virtual_cards_fund', 'POST', [
            'card_id' => (string)$card['chimoney_card_id'],
            'reference' => (string)$card_ref,
            'currency' => 'USD',
            'amount' => (float)$amount_usd,
        ]);
        if ($res['status'] !== 'success') return $res;
        $amt = (float)$amount_usd;
        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET balance_usd = balance_usd + '$amt' WHERE id='" . (int)$card['id'] . "'");
        return ['status' => 'success', 'message' => 'Card funded'];
    }
}

if (!function_exists('freezeVirtualCardFlutterwave')) {
    function freezeVirtualCardFlutterwave($vid, $card_ref, $is_auto = 0) {
        global $connection_server;
        $vid = (int)$vid;
        $ref_e = mysqli_real_escape_string($connection_server, (string)$card_ref);
        $q = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_e' AND vendor_id='$vid' LIMIT 1");
        $card = $q ? mysqli_fetch_assoc($q) : null;
        if (!$card) return ['status' => 'error', 'message' => 'Card not found.'];
        if (($card['provider'] ?? '') !== 'flutterwave') {
            return ['status' => 'error', 'message' => 'Legacy cards from retired providers cannot be managed here.'];
        }
        $res = bc_vc_flw_call($vid, 'virtual_cards_status', 'PUT', [
            'card_id' => (string)$card['chimoney_card_id'],
            'reference' => (string)$card_ref,
            'status' => 'frozen',
        ]);
        if ($res['status'] !== 'success') return $res;
        $auto = (int)$is_auto;
        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET status='frozen', is_frozen_auto='$auto' WHERE id='" . (int)$card['id'] . "'");
        return ['status' => 'success', 'message' => 'Card frozen'];
    }
}

if (!function_exists('unfreezeVirtualCardFlutterwave')) {
    function unfreezeVirtualCardFlutterwave($vid, $card_ref) {
        global $connection_server;
        $vid = (int)$vid;
        $ref_e = mysqli_real_escape_string($connection_server, (string)$card_ref);
        $q = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE reference='$ref_e' AND vendor_id='$vid' LIMIT 1");
        $card = $q ? mysqli_fetch_assoc($q) : null;
        if (!$card) return ['status' => 'error', 'message' => 'Card not found.'];
        if (($card['provider'] ?? '') !== 'flutterwave') {
            return ['status' => 'error', 'message' => 'Legacy cards from retired providers cannot be managed here.'];
        }
        $res = bc_vc_flw_call($vid, 'virtual_cards_status', 'PUT', [
            'card_id' => (string)$card['chimoney_card_id'],
            'reference' => (string)$card_ref,
            'status' => 'active',
        ]);
        if ($res['status'] !== 'success') return $res;
        mysqli_query($connection_server, "UPDATE sas_virtual_cards_v2 SET status='active', is_frozen_auto=0 WHERE id='" . (int)$card['id'] . "'");
        return ['status' => 'success', 'message' => 'Card reactivated'];
    }
}
