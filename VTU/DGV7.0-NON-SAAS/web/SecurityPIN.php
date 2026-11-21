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
        $pin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pin"])));
        $confirm_pin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["confirm_pin"])));

        if(!empty($pin) && is_numeric($pin) && strlen($pin) == 4){
            if($pin === $confirm_pin){
                $username = $_SESSION["user_session"];
                $vid = $get_logged_user_details["vendor_id"];
                $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
                $update = mysqli_query($connection_server, "UPDATE sas_users SET transaction_pin='$pin', security_pin='$hashed_pin' WHERE username='$username' AND vendor_id='$vid'");
                if($update){
                    $_SESSION["product_purchase_response"] = "Security PIN set successfully!";
                    header("Location: Dashboard.php");
                    exit();
                } else {
                    $error = "Failed to update PIN. Please try again.";
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
