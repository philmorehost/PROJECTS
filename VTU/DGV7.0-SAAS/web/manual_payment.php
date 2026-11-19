<?php session_start();
    // Use basic configs
    include("../func/bc-connect.php");
	include("../func/bc-tables.php");
	require_once(__DIR__ . "/../func/bc-plate-auth.php");

    // Fetch bank details for manual payment
    $bank_details_res = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payments LIMIT 1");
    $bank_details = mysqli_fetch_assoc($bank_details_res);

?>
<?php
bc_plate_auth_head('Manual payment | ' . $get_all_site_details["site_title"]);
bc_plate_auth_top(array(
    'icon'  => 'bank',
    'title' => 'Thank you for registering!',
    'sub'   => 'Your registration is pending payment. Make a transfer to the account below.',
));
?>
        <?php if ($bank_details): ?>
        <div class="alert alert-info">
            <strong>Bank account details</strong>
            <p class="mb-1"><span class="text-muted">Bank name</span><br><?php echo htmlspecialchars($bank_details['bank_name']); ?></p>
            <p class="mb-1"><span class="text-muted">Account name</span><br><?php echo htmlspecialchars($bank_details['account_name']); ?></p>
            <p class="mb-0"><span class="text-muted">Account number</span><br><?php echo htmlspecialchars($bank_details['account_number']); ?></p>
        </div>
        <div class="alert alert-warning">
            <strong>Important</strong>
            Use your <strong>email address</strong> as the payment reference or narration.
            Your account is activated by an administrator once payment is confirmed — this
            can take up to 24 hours.
        </div>
        <?php else: ?>
        <div class="alert alert-danger">
            Manual payment details are not available at the moment. Please contact support.
        </div>
        <?php endif; ?>

        <a class="pk-auth__btn" href="/"><?php echo bc_plate_auth_icon('home', 18); ?> Go to homepage</a>
<?php
bc_plate_auth_end(array(
    'note' => 'You will be able to sign in once your payment is confirmed.',
));
?>
