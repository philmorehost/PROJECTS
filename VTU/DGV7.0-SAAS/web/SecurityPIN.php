<?php session_start();
    include("../func/bc-config.php");

    if(!isset($_SESSION["user_session"])){
        header("Location: Login.php");
        exit();
    }

    if(isset($_POST["set-pin"])){
        bc_validate_csrf();
        $error = "";
        $pin = trim(strip_tags($_POST["pin"] ?? ''));
        $confirm_pin = trim(strip_tags($_POST["confirm_pin"] ?? ''));
        $current_pin = trim(strip_tags($_POST["current_pin"] ?? ''));

        if(!empty($pin) && ctype_digit($pin) && strlen($pin) == 4){
            if($pin === $confirm_pin){
                $username = $get_logged_user_details["username"];
                $vid = $get_logged_user_details["vendor_id"];

                // A PIN may only be replaced after proving the current one. Otherwise a hijacked
                // session (or the old impersonation link) could simply install a new PIN and drain
                // the wallet - which is exactly what happened.
                $has_existing_pin = !empty($get_logged_user_details["security_pin"]) || !empty($get_logged_user_details["transaction_pin"]);
                if ($has_existing_pin) {
                    if (empty($current_pin)) {
                        $error = "Enter your current PIN to change it.";
                    } elseif (!bc_verify_pin_secure($current_pin, $get_logged_user_details, 'set_pin')) {
                        $error = "Current PIN is incorrect.";
                    }
                }

                if (empty($error) && in_array($pin, array('0000','1111','2222','3333','4444','5555','6666','7777','8888','9999','1234','4321'), true)) {
                    $error = "That PIN is too easy to guess. Please choose another.";
                }

                if (empty($error)) {
                    $username_sql = mysqli_real_escape_string($connection_server, $username);
                    $pin_int = (int)$pin;
                    $hashed_pin = mysqli_real_escape_string($connection_server, password_hash($pin, PASSWORD_DEFAULT));
                    $update = mysqli_query($connection_server, "UPDATE sas_users SET transaction_pin='$pin_int', security_pin='$hashed_pin' WHERE username='$username_sql' AND vendor_id='" . (int)$vid . "'");
                    if($update){
                        $_SESSION["product_purchase_response"] = "Security PIN set successfully!";
                        header("Location: Dashboard.php");
                        exit();
                    } else {
                        $error = "Failed to update PIN. Please try again.";
                    }
                }
            } else {
                $error = "PINs do not match!";
            }
        } else {
            $error = "PIN must be 4 digits.";
        }
    }
?>
<!DOCTYPE html>
<head>
    <title>Set Security PIN | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="card shadow border-0 rounded-4 p-4 p-md-5" style="max-width: 450px; width: 100%;">
            <div class="text-center mb-4">
                <div class="bg-primary bg-opacity-10 p-3 rounded-circle d-inline-block mb-3 shadow-sm">
                    <i class="bi bi-shield-lock text-primary fs-1"></i>
                </div>
                <h3 class="fw-bold">Set Security PIN</h3>
                <p class="text-muted">Create a 4-digit PIN to secure your transactions and fund transfers.</p>
            </div>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger py-2 small mb-3"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php echo bc_csrf_field(); ?>
                <?php if (!empty($get_logged_user_details["security_pin"]) || !empty($get_logged_user_details["transaction_pin"])): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Current PIN</label>
                    <input name="current_pin" type="password" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center fw-bold" placeholder="****" inputmode="numeric" required>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">New 4-Digit PIN</label>
                    <input name="pin" type="password" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center fw-bold" placeholder="****" inputmode="numeric" required>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase text-muted">Confirm PIN</label>
                    <input name="confirm_pin" type="password" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center fw-bold" placeholder="****" inputmode="numeric" required>
                </div>
                <button name="set-pin" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 py-3 fw-bold">
                    SAVE SECURITY PIN
                </button>
            </form>
        </div>
    </div>
</body>
</html>