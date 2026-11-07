<?php session_start();
    // Use basic configs
    include("../func/bc-connect.php");
	include("../func/bc-tables.php");

    // Fetch bank details for manual payment
    $bank_details_res = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payments LIMIT 1");
    $bank_details = mysqli_fetch_assoc($bank_details_res);

?>
<!DOCTYPE html>
<head>
    <title>Manual Payment Instructions</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <main>
        <div class="container">
            <section class="section min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-lg-8 col-md-10">
                            <div class="card">
                                <div class="card-body p-5">
                                    <h2 class="card-title text-center pb-0 fs-4">Thank You for Registering!</h2>
                                    <p class="text-center">Your registration is pending payment. Please make a transfer to the account below.</p>
                                    
                                    <?php if($bank_details): ?>
                                    <div class="alert alert-info">
                                        <h4 class="alert-heading">Bank Account Details:</h4>
                                        <p><strong>Bank Name:</strong> <?php echo htmlspecialchars($bank_details['bank_name']); ?></p>
                                        <p><strong>Account Name:</strong> <?php echo htmlspecialchars($bank_details['account_name']); ?></p>
                                        <p><strong>Account Number:</strong> <?php echo htmlspecialchars($bank_details['account_number']); ?></p>
                                    </div>
                                    <div class="alert alert-warning">
                                        <h4 class="alert-heading">Important Instructions:</h4>
                                        <p>Please use your **email address** as the payment reference or narration for the transfer.</p>
                                        <p>Your account will be activated by an administrator as soon as your payment is confirmed. This may take up to 24 hours.</p>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-danger">
                                        <p>Manual payment details are not available at the moment. Please contact support.</p>
                                    </div>
                                    <?php endif; ?>

                                    <div class="text-center mt-4">
                                        <a href="/" class="btn btn-primary">Go to Homepage</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
