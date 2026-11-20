<?php
// A browser return is never proof of payment. Do not consume query parameters.
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment processing</title><body style="background:#101010;color:#fff;font:16px/1.6 system-ui;padding:48px 24px"><main style="max-width:500px;margin:auto"><h1>Thank you.</h1><p>If your payment was completed, the recipient's wallet will update after verification. Returning to this page does not confirm payment.</p><p>You may close this page. Keep the payment provider's reference for your records.</p></main></body></html>
