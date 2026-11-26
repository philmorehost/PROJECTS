<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include_once(__DIR__ . "/../func/bc-config.php");
require_once(__DIR__ . "/../func/bc-payment-gateway-settlement.php");

header('Cache-Control: no-store, private');

$callback_ref = bc_pg_sanitize_reference((string)($_GET['reference'] ?? ($_POST['reference'] ?? ($_GET['trxref'] ?? ''))));
$local_ref    = bc_pg_sanitize_reference((string)($_GET['local_reference'] ?? ($_POST['local_reference'] ?? $callback_ref)));
$payhub_ref   = bc_pg_sanitize_reference((string)($_GET['payhub_ref'] ?? ($_POST['payhub_ref'] ?? (!empty($_GET['local_reference']) ? $callback_ref : ''))));

$verify_ref = $payhub_ref !== '' ? $payhub_ref : $local_ref;
$credit_status = 'none';
$credit_message = '';

$vid = resolveVendorID();
$username = '';

if ($verify_ref !== '' || $local_ref !== '') {
    $intent = ($verify_ref !== '' ? bc_pg_lookup_intent($connection_server, $verify_ref, $vid > 0 ? $vid : 0) : null)
        ?: ($local_ref !== '' ? bc_pg_lookup_intent($connection_server, $local_ref, $vid > 0 ? $vid : 0) : null);
    if ($intent) {
        if ($vid <= 0) $vid = (int)$intent['vendor_id'];
        $username = (string)$intent['username'];
    }
    if ($username === '' && !empty($get_logged_user_details['username']) && $vid > 0) {
        $username = (string)$get_logged_user_details['username'];
    }

    if ($vid > 0 && $username !== '' && $verify_ref !== '') {
        $settlement = bc_pg_verify_and_settle($connection_server, $vid, $username, 'payhub', $verify_ref);
        if (!empty($settlement['ok'])) {
            $credit_status = 'success';
            $credit_message = $settlement['message'] ?: 'Your wallet has been funded successfully.';
        } else {
            $code = (string)($settlement['code'] ?? '');
            if ($code === 'TEST_MODE_REJECTED') {
                $credit_status = 'error';
                $credit_message = $settlement['message'] ?? 'Sandbox payments cannot credit live wallets.';
            } elseif (in_array($code, ['NOT_PAID', 'VERIFY_FAILED'], true)) {
                $credit_status = 'pending';
                $credit_message = 'Your payment is being confirmed. Your wallet will be credited shortly.';
            } else {
                $credit_status = 'error';
                $credit_message = $settlement['message'] ?? 'Could not confirm this payment yet.';
            }
        }
    } else {
        $credit_status = 'pending';
        $credit_message = 'Your payment was received and is awaiting confirmation.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status</title>
    <script src="../assets-2/vendor/sweetalert2/sweetalert2.all.min.js?v=11.0.16"></script>
</head>
<body style="font-family:sans-serif;background:#f6f9fc;padding:32px;text-align:center;">
    <noscript>
        <h2><?php echo $credit_status === 'success' ? 'Payment Successful' : ($credit_status === 'error' ? 'Payment Not Credited' : 'Processing Payment'); ?></h2>
        <p><?php echo htmlspecialchars($credit_message ?: 'Returning to your dashboard...', ENT_QUOTES, 'UTF-8'); ?></p>
        <p><a href="Dashboard.php">Return to Dashboard</a></p>
    </noscript>
    <script>
        (function () {
            var status = <?php echo json_encode($credit_status); ?>;
            var msg = <?php echo json_encode($credit_message); ?>;
            var ref = <?php echo json_encode($verify_ref ?: $local_ref); ?>;
            if (window.AndroidBridge && typeof window.AndroidBridge.onSuccess === 'function' && status === 'success' && ref) {
                try { window.AndroidBridge.onSuccess(ref); } catch (e) {}
            }
            var redirectTarget = 'Dashboard.php';
            if (typeof Swal !== 'undefined') {
                if (status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Successful!',
                        text: msg || 'Your wallet has been funded successfully.',
                        confirmButtonText: 'Continue',
                        allowOutsideClick: false
                    }).then(function () { window.location.href = redirectTarget; });
                } else if (status === 'error') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Payment Not Credited',
                        text: msg,
                        confirmButtonText: 'Back to Fund Wallet',
                        allowOutsideClick: false
                    }).then(function () { window.location.href = 'Fund.php'; });
                } else {
                    Swal.fire({
                        icon: 'info',
                        title: 'Confirming Payment...',
                        text: msg || 'Your wallet will be credited shortly once PayHub confirms the transaction.',
                        confirmButtonText: 'Continue',
                        allowOutsideClick: false
                    }).then(function () { window.location.href = redirectTarget; });
                }
            } else {
                alert(msg || 'Payment processed.');
                window.location.href = status === 'error' ? 'Fund.php' : redirectTarget;
            }
        })();
    </script>
</body>
</html>
