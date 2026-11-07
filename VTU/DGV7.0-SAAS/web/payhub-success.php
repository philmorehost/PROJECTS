<?php
session_start();
include_once(__DIR__ . "/../func/bc-config.php");

// PayHub success handler (user wallet funding).
// Reached via the PayHub callback_url AND via the Fund.php modal's postMessage redirect.
// Verifies the payment with the PayHub API and credits the wallet idempotently
// (processPayhubSuccess skips transactions already marked paid), so a user is credited even
// if the PayHub webhook is not configured or not delivered.

$reference  = trim($_GET['reference'] ?? ($_POST['reference'] ?? ($_GET['trxref'] ?? '')));
$payhub_ref = trim((string)($_GET['payhub_ref'] ?? ($_POST['payhub_ref'] ?? '')));
$amount     = (float)($_GET['amount'] ?? 0);

$credit_status = 'none';
$credit_message = '';

if (!empty($reference) || !empty($payhub_ref)) {
    $vid = resolveVendorID();
    $username = '';

    // PayHub generates its own "PH_..." reference (it ignores our local one), so we must verify
    // with THAT. Prefer the explicitly passed payhub_ref; otherwise look it up via api_reference.
    $verify_ref = $payhub_ref;
    if (empty($verify_ref) && !empty($reference)) {
        $ref_esc0 = mysqli_real_escape_string($connection_server, $reference);
        $q_ap0 = mysqli_query($connection_server, "SELECT api_reference FROM sas_transactions WHERE reference='$ref_esc0' LIMIT 1");
        if ($q_ap0 && ($r_ap0 = mysqli_fetch_assoc($q_ap0)) && !empty($r_ap0['api_reference'])) {
            $verify_ref = $r_ap0['api_reference'];
        } else {
            $verify_ref = $reference;
        }
    }
    if (empty($verify_ref)) $verify_ref = $reference;

    // Resolve context from the checkout record (keyed by PayHub ref or local ref).
    if (!empty($verify_ref)) {
        $ref_esc = mysqli_real_escape_string($connection_server, $verify_ref);
        $q_c = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
        if ($q_c && ($r_c = mysqli_fetch_assoc($q_c))) {
            $vid = (int)$r_c['vendor_id'];
            $username = $r_c['username'];
        }
    }
    if (($vid <= 0 || empty($username)) && !empty($reference)) {
        $ref_esc2 = mysqli_real_escape_string($connection_server, $reference);
        $q_c2 = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$ref_esc2' LIMIT 1");
        if ($q_c2 && ($r_c2 = mysqli_fetch_assoc($q_c2))) {
            if ($vid <= 0) $vid = (int)$r_c2['vendor_id'];
            if (empty($username)) $username = $r_c2['username'];
        }
    }
    if (empty($username) && isset($get_logged_user_details['username']) && !empty($get_logged_user_details['username']) && $vid > 0) {
        $username = $get_logged_user_details['username'];
    }

    if ($vid > 0 && !empty($username)) {
        // If the payment was already credited (status poll, webhook, or a previous visit to
        // this page), confirm success without re-verifying — avoids a confusing "still
        // confirming" message after the wallet has already been funded.
        $ref_already = mysqli_real_escape_string($connection_server, $reference);
        $ph_already  = mysqli_real_escape_string($connection_server, $verify_ref);
        $q_already = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vid' AND (reference='$ref_already' OR api_reference='$ph_already' OR api_reference='$ref_already') AND status=1 LIMIT 1");
        if ($q_already && mysqli_num_rows($q_already) > 0) {
            $credit_status = 'success';
            $credit_message = 'Your wallet has been funded successfully.';
        } else {
            $payhub_keys = getGatewayDetails('payhub', $vid);
            if ($payhub_keys) {
                // Verify the payment with PayHub before crediting (same contract as the reconciliation flow).
                $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($verify_ref), "", $vid, false);
                $v_data = json_decode($verify_res, true);
                if (($v_data['status'] ?? '') == 'success') {
                    $tx_raw = json_decode($v_data['json_result'], true);
                    $tx_data = (isset($tx_raw['data']) && is_array($tx_raw['data'])) ? $tx_raw['data'] : $tx_raw;
                    $tx_status = strtolower($tx_data['status'] ?? '');
                    // PayHub's top-level status:true only means "transaction retrieved"; the real
                    // payment status is data.status. Only credit on an explicit success/successful.
                    if (strtolower(trim((string)($tx_data['domain'] ?? ''))) === 'test') {
                        // Sandbox money must never fund a real wallet.
                        $credit_status = 'error';
                        $credit_message = 'This payment was made in TEST/sandbox mode, so your wallet was not funded.';
                    } elseif ($tx_status == 'success' || $tx_status == 'successful') {
                        if (empty($tx_data['reference'])) $tx_data['reference'] = $verify_ref;
                        $tx_data['metadata'] = json_encode([
                            'vendor_id' => $vid,
                            'username'  => $username,
                            'target'    => 'user',
                            'reference' => $reference,
                        ]);
                        $local_ref = processPayhubSuccess($vid, $tx_data['reference'], $tx_data, $payhub_keys, $username);
                        if ($local_ref) {
                            $credit_status = 'success';
                            $credit_message = 'Your wallet has been funded successfully.';
                        } else {
                            $credit_status = 'error';
                            $credit_message = 'Payment confirmed but crediting failed. Please contact support.';
                        }
                    } else {
                        // A DEFINITIVE gateway failure is not the same as "not confirmed yet", and
                        // it must not read as "still being confirmed": the customer may already have
                        // been charged, so they must be told to contact support now instead of
                        // waiting for a credit that will never arrive. PayHub marks a transaction
                        // failed when one of ITS own integrity checks blocks fulfilment - including
                        // an amount-mismatch block - and this is where that verdict surfaces.
                        if (bc_gateway_provider_verdict(array('status' => $tx_status)) === 'failed') {
                            $credit_status = 'error';
                            $credit_message = 'The payment was not completed by the gateway, so your wallet was not funded. If money left your account, contact support with reference ' . ($reference !== '' ? $reference : $verify_ref) . '.';
                        } else {
                            $credit_status = 'pending';
                            $credit_message = 'Payment is still being confirmed. Your wallet will be credited automatically.';
                        }
                    }
                } else {
                    $credit_status = 'pending';
                    $credit_message = 'Could not confirm the payment yet. Your wallet will be credited automatically once confirmed.';
                }
            } else {
                $credit_status = 'error';
                $credit_message = 'PayHub is not configured for your account.';
            }
        }
    } else {
        $credit_status = 'error';
        $credit_message = 'Could not identify your account for this payment.';
    }
} else {
    $credit_status = 'pending';
    $credit_message = 'Your wallet will be credited automatically once the payment is confirmed.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment Status</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <script>
        Swal.fire({
            title: <?php echo json_encode($credit_status === 'success' ? 'Payment Received!' : 'Payment Status'); ?>,
            text: <?php echo json_encode($credit_message); ?>,
            icon: <?php echo json_encode($credit_status === 'success' ? 'success' : ($credit_status === 'error' ? 'error' : 'info')); ?>,
            confirmButtonText: 'Back to Dashboard'
        }).then(() => {
            window.parent.location.href = 'Dashboard.php';
        });
    </script>
</body>
</html>
