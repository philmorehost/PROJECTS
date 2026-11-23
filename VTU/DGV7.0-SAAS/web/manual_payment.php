<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    // Use basic configs
    include("../func/bc-connect.php");
	include("../func/bc-tables.php");
	require_once(__DIR__ . "/../func/bc-plate-auth.php");

    require_once __DIR__.'/../func/bc-vendor-registration.php';
    require_once __DIR__.'/../func/bc-vendor-registration-commerce.php';
    header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
    try{
        $session_application=$_SESSION['vreg_application']??null;
        if(!is_array($session_application)||(int)($session_application['until']??0)<=time())throw new RuntimeException('Application session expired. Contact support before transferring funds.');
        $application=bc_q_row('SELECT * FROM sas_pending_vendors WHERE id=? AND status=0',[(int)($session_application['id']??0)]);
        if(bc_vreg_policy()['payment_mode']!=='both'||!$application||$application['payment_method']!=='bank_deposit'||$application['payment_status']!=='pending'||empty($application['email_verified_at'])||!hash_equals(hash('sha256',$application['email']),(string)($session_application['email_hash']??'')))throw new RuntimeException('Manual payment is not authorized for this application. Do not transfer funds; contact support.');
        $invoice=bc_vreg_invoice($application);
    }catch(Throwable $e){http_response_code(403);exit('Manual payment instructions unavailable. Do not transfer funds; contact platform support.');}

    // Fetch bank details for manual payment
    $bank_details_res = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payments LIMIT 1");
    $bank_details = $bank_details_res?mysqli_fetch_assoc($bank_details_res):null;
    if(!$bank_details||empty($bank_details['bank_name'])||empty($bank_details['account_name'])||!preg_match('/\A[0-9]{10}\z/D',(string)($bank_details['account_number']??'')))$bank_details=null;

?>
<?php
bc_plate_auth_head('Manual payment | ' . ($get_all_site_details["site_title"]??"Platform"));
bc_plate_auth_top(array(
    'icon'  => 'bank',
    'title' => 'Thank you for registering!',
    'sub'   => 'Your registration is pending payment. Make a transfer to the account below.',
));
?>
        <div class="alert alert-info">Application #<?php echo (int)$application['id']; ?> — server invoice: <strong>NGN <?php echo htmlspecialchars(bc_vreg_decimal($invoice['total']),ENT_QUOTES,'UTF-8'); ?></strong>. Transfer exactly this amount only if the bank details below are available. Do not pay a browser-modified amount.</div>
        <?php if ($bank_details): ?>
        <div class="alert alert-info">
            <strong>Bank account details</strong>
            <p class="mb-1"><span class="text-muted">Bank name</span><br><?php echo htmlspecialchars($bank_details['bank_name']); ?></p>
            <p class="mb-1"><span class="text-muted">Account name</span><br><?php echo htmlspecialchars($bank_details['account_name']); ?></p>
            <p class="mb-0"><span class="text-muted">Account number</span><br><?php echo htmlspecialchars($bank_details['account_number']); ?></p>
        </div>
        <div class="alert alert-warning">
            <strong>Important</strong>
            Transfer only once. If you already paid, contact support before trying again. Use your <strong>email address</strong> as the payment reference or narration.
            A platform-owner bank-statement review, final approval and any required KYC/KYB must be completed. A transfer or uploaded receipt does not activate an account.
        </div>
        <?php else: ?>
        <div class="alert alert-danger">
            Manual payment details are not available at the moment. Please contact support.
        </div>
        <?php endif; ?>

        <a class="pk-auth__btn" href="/"><?php echo bc_plate_auth_icon('home', 18); ?> Go to homepage</a>
<?php
bc_plate_auth_end(array(
    'note' => 'Payment confirmation alone does not activate a vendor account.',
));
?>
