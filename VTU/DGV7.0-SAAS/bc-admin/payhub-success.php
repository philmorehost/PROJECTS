<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include_once(__DIR__ . "/../func/bc-config.php");
require_once __DIR__ . "/../func/bc-payment-gateway-settlement.php";

header('Cache-Control: no-store, private');

$callback_ref = bc_pg_sanitize_reference((string)($_GET['reference'] ?? ($_POST['reference'] ?? ($_GET['trxref'] ?? ''))));
$local_ref    = bc_pg_sanitize_reference((string)($_GET['local_reference'] ?? ($_POST['local_reference'] ?? $callback_ref)));
$payhub_ref   = bc_pg_sanitize_reference((string)($_GET['payhub_ref'] ?? ($_POST['payhub_ref'] ?? (!empty($_GET['local_reference']) ? $callback_ref : ''))));

$verify_ref = $payhub_ref !== '' ? $payhub_ref : $local_ref;
$credit_status = 'none';
$credit_message = '';
$redirect_target = 'Dashboard.php';

if ($verify_ref !== '' || $local_ref !== '') {
    $intent = ($verify_ref !== '' ? bc_pg_lookup_intent($connection_server, $verify_ref, 0) : null)
        ?: ($local_ref !== '' ? bc_pg_lookup_intent($connection_server, $local_ref, 0) : null);

    $vid = 0;
    if ($intent && (($intent['target'] ?? 'user') === 'vendor')) {
        $vid = (int)$intent['vendor_id'];
    } elseif (!empty($_SESSION['admin_session']) && ($connection_server instanceof mysqli)) {
        $adm_email = mysqli_real_escape_string($connection_server, (string)$_SESSION['admin_session']);
        $vq = @mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE email='$adm_email' LIMIT 1");
        if ($vq && ($vr = mysqli_fetch_assoc($vq))) {
            $vid = (int)$vr['id'];
        }
    }

    if ($vid > 0 && $verify_ref !== '') {
        $settlement = bc_pg_verify_and_settle_vendor($connection_server, $vid, 'payhub', $verify_ref, [
            'provider_reference' => $payhub_ref,
        ]);
        if (!empty($settlement['ok'])) {
            $credit_status = 'success';
            $credit_message = (string)($settlement['message'] ?? 'Your vendor wallet has been funded successfully.');
            if (!empty($settlement['payout_activated'])) {
                $redirect_target = 'PaymentGateway.php';
            }
        } else {
            $code = (string)($settlement['code'] ?? '');
            if ($code === 'TEST_MODE_REJECTED') {
                $credit_status = 'error';
                $credit_message = (string)($settlement['message'] ?? 'Sandbox payments cannot credit live wallets.');
            } elseif (in_array($code, ['NOT_PAID', 'VERIFY_FAILED'], true)) {
                $credit_status = 'pending';
                $credit_message = 'Your payment is being confirmed. Your vendor wallet will be credited shortly.';
            } else {
                $credit_status = 'error';
                $credit_message = (string)($settlement['message'] ?? 'Could not confirm this payment yet.');
            }
        }
    } else {
        $credit_status = 'pending';
        $credit_message = 'Your payment was received and is awaiting confirmation.';
    }
} else {
    $credit_status = 'pending';
    $credit_message = 'Your wallet will be credited automatically once the payment is confirmed.';
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
        <h2><?php echo $credit_status === 'success' ? 'Payment Received' : ($credit_status === 'error' ? 'Payment Not Credited' : 'Processing Payment'); ?></h2>
        <p><?php echo htmlspecialchars($credit_message ?: 'Returning to your dashboard...', ENT_QUOTES, 'UTF-8'); ?></p>
        <p><a href="<?php echo htmlspecialchars($redirect_target, ENT_QUOTES, 'UTF-8'); ?>">Continue</a></p>
    </noscript>
    <script>
        (function () {
            var status = <?php echo json_encode($credit_status); ?>;
            var msg = <?php echo json_encode($credit_message); ?>;
            var target = <?php echo json_encode($redirect_target); ?>;
            var title = status === 'success' ? 'Payment Received!' : (status === 'error' ? 'Payment Not Credited' : 'Payment Status');
            var icon = status === 'success' ? 'success' : (status === 'error' ? 'error' : 'info');
            function done() {
                try {
                    if (window.parent && window.parent !== window) {
                        window.parent.location.href = target;
                        return;
                    }
                } catch (e) {}
                window.location.href = target;
            }
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: title,
                    text: msg || 'Returning to your dashboard.',
                    icon: icon,
                    confirmButtonText: target === 'PaymentGateway.php' ? 'Go to Payment Settings' : 'Back to Dashboard'
                }).then(done);
            } else {
                done();
            }
        })();
    </script>
</body>
</html>
