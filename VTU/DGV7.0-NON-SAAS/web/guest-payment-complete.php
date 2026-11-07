<?php
/**
 * PayHub checkout callback landing page for Guest Mode.
 * The Android/iOS WebView watches for this URL to detect checkout completion (same pattern
 * as the existing wallet-funding WebView flow), then calls guest-api/status.php via API for
 * the authoritative result — actual fulfillment always happens server-side via
 * guest-api/webhook.php, never from this page being loaded.
 */
$reference = htmlspecialchars(trim($_GET["reference"] ?? ''), ENT_QUOTES);
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Complete</title>
</head>
<body style="font-family:sans-serif;padding:40px 20px;text-align:center;">
    <h2>Thank you!</h2>
    <p>Your payment is being confirmed. This may take a few seconds.</p>
    <p style="color:#888;font-size:13px;">Reference: <?php echo $reference; ?></p>
</body>
</html>
