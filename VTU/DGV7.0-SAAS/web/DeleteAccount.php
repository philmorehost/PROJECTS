<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-config.php");
    require_once(__DIR__ . "/../func/bc-plate-auth.php");
    require_once(__DIR__ . "/../func/bc-account-deletion.php");

    // §43 — Delete My Account. Public, ownership-proved: username -> one-time
    // code to the REGISTERED email -> submit. Nothing is deleted here: the
    // request goes to bc-admin for approval; only an approved request closes
    // the account (and permanent erasure is a separate, later admin action).
    $delete_vendor_id = resolveVendorID();
    $delete_step = !empty($_SESSION['bc_delete_pending']['username']) ? 2 : 1;
    $delete_prefill = '';
    if (isset($get_logged_user_details["username"]) && ($get_logged_user_details["status"] ?? 0) == 1) {
        $delete_prefill = strtolower((string)$get_logged_user_details["username"]);
    }

    if (isset($_POST["send-delete-code"])) {
        $user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"] ?? ''))));
        $generic = "If an active account matches that username, a verification code has been sent to its registered email.";
        if ($user !== '' && function_exists('bc_is_rate_limited') && bc_is_rate_limited('account_delete', $delete_vendor_id . ':' . $user, 5, 900)) {
            $_SESSION["product_purchase_response"] = "Too many code requests. Please wait a few minutes and try again.";
            header("Location: " . $_SERVER["REQUEST_URI"]);
            exit();
        }
        $found = null;
        if ($user !== '') {
            $q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . (int)$delete_vendor_id . "' && username='$user' LIMIT 1");
            $found = ($q && mysqli_num_rows($q) == 1) ? mysqli_fetch_assoc($q) : null;
        }
        // Only active accounts can be deleted; the response stays generic so
        // the page never confirms whether a username exists.
        if ($found && (int)$found["status"] === 1 && filter_var((string)$found["email"], FILTER_VALIDATE_EMAIL)) {
            $code = generateOTP();
            $_SESSION['bc_delete_pending'] = array(
                'username' => strtolower((string)$found["username"]),
                'vendor_id' => (int)$delete_vendor_id,
                'reason' => substr(trim(strip_tags((string)($_POST["reason"] ?? ''))), 0, 1000),
                'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                'expires' => time() + 600,
                'tries' => 0,
            );
            sendVendorEmail(
                $found["email"],
                "Confirm your account deletion request",
                "<p>Hello " . htmlspecialchars((string)$found["firstname"]) . ",</p>"
                . "<p>Your account deletion verification code is <b style=\"font-size:20px;letter-spacing:3px;\">" . $code . "</b></p>"
                . "<p>It expires in 10 minutes. Submitting it only SENDS a deletion request to our team for review — your account stays active until an administrator approves it. If you did not ask for this, ignore this email and change your password.</p>"
            );
        }
        $_SESSION["product_purchase_response"] = $generic;
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit();
    }

    if (isset($_POST["confirm-delete-request"])) {
        $pending = $_SESSION['bc_delete_pending'] ?? null;
        $code = preg_replace('/\D+/', '', (string)($_POST["code"] ?? ''));
        $fail = '';
        if (!is_array($pending) || empty($pending['username'])) {
            $fail = "Your request session expired. Please start again.";
        } elseif (($pending['expires'] ?? 0) < time()) {
            $fail = "That code has expired. Please request a new one.";
        } elseif (($pending['tries'] ?? 0) >= 5) {
            $fail = "Too many incorrect codes. Please request a new one.";
        } elseif (empty($_POST["understand"])) {
            $fail = "Please tick the confirmation box so we know you understand what deletion means.";
        } elseif (!password_verify($code, (string)$pending['code_hash'])) {
            $_SESSION['bc_delete_pending']['tries'] = (int)($pending['tries'] ?? 0) + 1;
            $fail = "Incorrect code. Please check your email and try again.";
        }
        if ($fail !== '') {
            if (!is_array($pending) || ($pending['tries'] ?? 0) >= 5 || ($pending['expires'] ?? 0) < time()) {
                unset($_SESSION['bc_delete_pending']);
            }
            $_SESSION["product_purchase_response"] = $fail;
            header("Location: " . $_SERVER["REQUEST_URI"]);
            exit();
        }
        $uname = mysqli_real_escape_string($connection_server, (string)$pending['username']);
        $q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . (int)$pending['vendor_id'] . "' && username='$uname' LIMIT 1");
        $user_row = ($q && mysqli_num_rows($q) == 1) ? mysqli_fetch_assoc($q) : null;
        unset($_SESSION['bc_delete_pending']);
        if (!$user_row || (int)$user_row["status"] !== 1) {
            $_SESSION["product_purchase_response"] = "That account can no longer receive deletion requests.";
            header("Location: " . $_SERVER["REQUEST_URI"]);
            exit();
        }
        $res = bc_deletion_create_request((int)$pending['vendor_id'], $user_row, (string)($pending['reason'] ?? ''));
        if (!empty($res['ok'])) {
            sendVendorEmail(
                $user_row["email"],
                "We received your account deletion request",
                "<p>Hello " . htmlspecialchars((string)$user_row["firstname"]) . ",</p><p>Your account deletion request has been submitted for review. Your account remains active until an administrator approves it. If approved, the account will be closed (you will no longer be able to sign in) and later permanently erased, subject to legal record-keeping duties. If you did not request this, contact support immediately.</p>"
            );
            $_SESSION["product_purchase_response"] = !empty($res['duplicate'])
                ? "You already have a deletion request awaiting review. Our team will decide on it soon."
                : "Deletion request submitted. Our team will review it — your account stays active until it is approved.";
        } else {
            $_SESSION["product_purchase_response"] = "Could not submit your request right now. Please try again later or contact support.";
        }
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit();
    }

    if (isset($_POST["cancel-delete-request"])) {
        unset($_SESSION['bc_delete_pending']);
        $_SESSION["product_purchase_response"] = "Deletion request cancelled. Nothing was submitted.";
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit();
    }

    bc_plate_auth_head("Delete My Account | " . ($get_all_site_details["site_title"] ?? "Account"), $get_all_site_details ?? null);
    bc_plate_auth_top(array(
        'icon' => 'warn',
        'title' => 'Delete My Account',
        'sub' => $delete_step === 2
            ? 'Enter the 6-digit code we emailed to the registered address for <b>' . htmlspecialchars((string)$_SESSION['bc_delete_pending']['username']) . '</b>.'
            : 'This closes your account after review. It cannot be undone once permanently erased.',
    ));
?>
                    <?php if ($delete_step === 2): ?>
                    <form method="post" action="">
                        <div class="pk-auth__field">
                            <label class="pk-auth__label" for="code">Verification Code</label>
                            <input id="code" name="code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" class="pk-auth__input pk-auth__otp" placeholder="******" required autofocus>
                        </div>
                        <div class="pk-auth__field">
                            <label style="display:flex; gap:10px; align-items:flex-start; font-size:.9rem; line-height:1.5;">
                                <input type="checkbox" name="understand" value="1" required style="margin-top:4px;">
                                <span>I understand my account will be closed once an administrator approves this request, I will lose access to my wallet and services, and my data will later be permanently erased subject to legal record-keeping duties.</span>
                            </label>
                        </div>
                        <button name="confirm-delete-request" type="submit" class="pk-auth__btn pk-auth__btn--danger">Submit Deletion Request</button>
                        <button name="cancel-delete-request" type="submit" formnovalidate class="pk-auth__btn pk-auth__btn--ghost" style="margin-top:10px;">Cancel</button>
                        <p class="pk-auth__note" style="margin-top:14px;">Changed your mind? Cancel — nothing is submitted until you confirm with the code.</p>
                    </form>
                    <?php else: ?>
                    <form method="post" action="">
                        <div class="pk-auth__field">
                            <label class="pk-auth__label" for="user">Username</label>
                            <input id="user" name="user" type="text" class="pk-auth__input" placeholder="Enter your username" style="text-transform: lowercase;" value="<?php echo htmlspecialchars($delete_prefill, ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="username">
                            <p class="pk-auth__note" style="margin-top:8px; text-align:left;">We will email a one-time code to the address registered on the account. Submitting the code files a request — an administrator reviews it before anything is deleted.</p>
                        </div>
                        <div class="pk-auth__field">
                            <label class="pk-auth__label" for="reason">Reason (optional)</label>
                            <input id="reason" name="reason" type="text" maxlength="200" class="pk-auth__input" placeholder="Help us improve — optional">
                        </div>
                        <button name="send-delete-code" type="submit" class="pk-auth__btn pk-auth__btn--danger">Email Me a Code</button>
                        <p class="pk-auth__note" style="margin-top:16px;">
                            Keep your account? <a href="Login.php" class="pk-auth__link">Back to Sign In</a>
                        </p>
                    </form>
                    <?php endif; ?>
<?php if (isset($_SESSION["product_purchase_response"])) { ?>
<script>
  window.addEventListener('DOMContentLoaded', function () {
    if (window.Swal) Swal.fire('Notification', <?php echo json_encode((string)$_SESSION["product_purchase_response"], JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG); ?>, 'info');
  });
  setTimeout(() => { fetch('/func/unset-product-response.php').then(r => r.text()); }, 1000);
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>
<?php } ?>
<?php bc_plate_auth_end(array('links' => array(array('href' => 'Login.php', 'label' => 'Back to sign in'), array('href' => '../Privacy.php', 'label' => 'Privacy Policy')))); ?>
