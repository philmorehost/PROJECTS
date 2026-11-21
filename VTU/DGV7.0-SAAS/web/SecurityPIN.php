<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-config.php");
require_once(__DIR__ . "/../func/bc-plate-auth.php");

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

<?php
bc_plate_auth_head('Set Security PIN | ' . $get_all_site_details["site_title"]);
bc_plate_auth_top(array(
    'icon'      => 'lock',
    'title'     => 'Set security PIN',
    'sub'       => 'Create a 4-digit PIN to secure your transactions and fund transfers.',
    'alert'     => !empty($error) ? array(array('type' => 'error', 'body' => $error)) : null,
));
?>
        <form method="post" action="">
            <?php echo bc_csrf_field(); ?>
            <?php if (!empty($get_logged_user_details["security_pin"]) || !empty($get_logged_user_details["transaction_pin"])): ?>
            <div class="pk-auth__field">
                <label class="pk-auth__label" for="current_pin">Current PIN</label>
                <input id="current_pin" name="current_pin" type="password" maxlength="4" pattern="[0-9]{4}"
                       class="pk-auth__input pk-auth__otp" placeholder="****" inputmode="numeric" required>
            </div>
            <?php endif; ?>
            <div class="pk-auth__field">
                <label class="pk-auth__label" for="pin">New 4-digit PIN</label>
                <input id="pin" name="pin" type="password" maxlength="4" pattern="[0-9]{4}"
                       class="pk-auth__input pk-auth__otp" placeholder="****" inputmode="numeric" required>
            </div>
            <div class="pk-auth__field">
                <label class="pk-auth__label" for="confirm_pin">Confirm PIN</label>
                <input id="confirm_pin" name="confirm_pin" type="password" maxlength="4" pattern="[0-9]{4}"
                       class="pk-auth__input pk-auth__otp" placeholder="****" inputmode="numeric" required>
            </div>
            <div class="pk-auth__field">
                <button name="set-pin" type="submit" class="pk-auth__btn">Save security PIN</button>
            </div>
        </form>
<?php
bc_plate_auth_end(array(
    'note' => 'Your PIN is required to change an existing one. Never share it.',
));
?>
