<?php
session_start();
include_once(__DIR__ . "/../func/bc-config.php");

// PayHub success handler (vendor wallet funding via bc-admin/Fund.php).
// Reached via the PayHub callback_url AND via the admin Fund.php modal's postMessage redirect.
// Verifies the payment with the PayHub API and credits the vendor wallet idempotently.

$reference  = trim($_GET['reference'] ?? ($_POST['reference'] ?? ($_GET['trxref'] ?? '')));
$payhub_ref = trim((string)($_GET['payhub_ref'] ?? ($_POST['payhub_ref'] ?? '')));
$amount     = (float)($_GET['amount'] ?? 0);

$credit_status = 'none';
$credit_message = '';

if (!empty($reference) || !empty($payhub_ref)) {
    $vid = 0;

    // PayHub generates its own "PH_..." reference (it ignores our local one), so we must verify
    // with THAT. Prefer the passed payhub_ref; for vendor funding there is no api_reference column,
    // so fall back to the most recent PayHub checkout row for the resolved vendor.
    $verify_ref = $payhub_ref;

    // Resolve context from the checkout record (keyed by PayHub ref or local ref).
    if (!empty($verify_ref)) {
        $ref_esc = mysqli_real_escape_string($connection_server, $verify_ref);
        $q_c = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
        if ($q_c && ($r_c = mysqli_fetch_assoc($q_c))) {
            $vid = (int)$r_c['vendor_id'];
        }
    }
    if ($vid <= 0 && !empty($reference)) {
        $ref_esc2 = mysqli_real_escape_string($connection_server, $reference);
        $q_c2 = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$ref_esc2' LIMIT 1");
        if ($q_c2 && ($r_c2 = mysqli_fetch_assoc($q_c2))) {
            $vid = (int)$r_c2['vendor_id'];
        }
    }
    if ($vid <= 0 && !empty($reference)) {
        $q_tx = mysqli_query($connection_server, "SELECT vendor_id FROM sas_vendor_transactions WHERE reference='" . mysqli_real_escape_string($connection_server, $reference) . "' LIMIT 1");
        if ($q_tx && ($r_tx = mysqli_fetch_assoc($q_tx))) {
            $vid = (int)$r_tx['vendor_id'];
        }
    }

    if (empty($verify_ref) && $vid > 0) {
        // Most recent PayHub-generated checkout row for this vendor.
        $q_ph = mysqli_query($connection_server, "SELECT reference FROM sas_user_payment_checkouts WHERE vendor_id='$vid' AND reference LIKE 'PH_%' ORDER BY id DESC LIMIT 1");
        if ($q_ph && ($r_ph = mysqli_fetch_assoc($q_ph))) {
            $verify_ref = $r_ph['reference'];
        }
    }
    if (empty($verify_ref)) $verify_ref = $reference;

    if ($vid > 0 && !empty($verify_ref)) {
        // If already credited (status poll, webhook, or previous visit), confirm success.
        // NOTE: sas_vendor_transactions has NO api_reference column — only match by reference.
        $loc_already = mysqli_real_escape_string($connection_server, $reference);
        $ph_already  = mysqli_real_escape_string($connection_server, $verify_ref);
        $q_already = mysqli_query($connection_server, "SELECT id FROM sas_vendor_transactions WHERE vendor_id='$vid' AND (reference='$loc_already' OR reference='$ph_already') AND status=1 LIMIT 1");
        if ($q_already && mysqli_num_rows($q_already) > 0) {
            $credit_status = 'success';
            $credit_message = 'Your vendor wallet has been funded successfully.';
        } else {
            // Vendor funding is paid to the platform, so use the super admin PayHub keys.
            $payhub_keys = getGatewayDetails('payhub', 0);
            if ($payhub_keys) {
                $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($verify_ref), "", $vid, true);
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
                            'target'    => 'vendor',
                            'reference' => $reference,
                        ]);
                        $local_ref = processPayhubSuccess($vid, $tx_data['reference'], $tx_data, $payhub_keys, '');
                        if ($local_ref) {
                            $credit_status = 'success';
                            $credit_message = 'Your vendor wallet has been funded successfully.';
                        } else {
                            $credit_status = 'error';
                            $credit_message = 'Payment confirmed but crediting failed. Please contact support.';
                        }
                    } else {
                        // A DEFINITIVE gateway failure is not the same as "not confirmed yet", and it
                        // must not read as "still being confirmed": the vendor may already have been
                        // charged, so they must be told now instead of waiting for a credit that
                        // will never arrive. PayHub marks a transaction failed when one of ITS own
                        // integrity checks blocks fulfilment - including an amount-mismatch block.
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
                $credit_message = 'PayHub is not configured.';
            }
        }
    } else {
        $credit_status = 'error';
        $credit_message = 'Could not identify your vendor account for this payment.';
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
