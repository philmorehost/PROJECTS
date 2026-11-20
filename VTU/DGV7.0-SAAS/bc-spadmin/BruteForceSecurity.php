<?php session_start();
include("../func/bc-spadmin-config.php");

// §33: platform-wide "logout all sessions". Bumps the session epoch (every
// PHP session stamped under an older epoch is destroyed at bootstrap) and
// revokes every mobile-app token. The acting super admin re-stamps their own
// session and stays signed in on this device only. Requires CSRF + step-up
// (security PIN + Google Authenticator code, or an email OTP).
if (isset($_POST["sec33_send_code"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_send_err = function_exists('bc_stepup_send_email_otp')
        ? bc_stepup_send_email_otp(0, (string)$get_logged_spadmin_details['email'], (string)$get_logged_spadmin_details['email'], 'logout_all_sessions', 'Logout All Sessions')
        : 'Verification is unavailable.';
    $_SESSION["product_purchase_response"] = $sec33_send_err === '' ? "Verification code sent to your email." : "Error: $sec33_send_err";
    header("Location: BruteForceSecurity.php");
    exit();
}

if (isset($_POST["logout-all-sessions"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_input = array(
        'pin'       => (string)($_POST['sec33_pin'] ?? ''),
        'otp_code'  => (string)($_POST['sec33_factor'] ?? ''),
        'totp_code' => (string)($_POST['sec33_factor'] ?? ''),
    );
    $sec33_err = function_exists('bc_stepup_spadmin_verify')
        ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $sec33_input, 'logout_all_sessions')
        : 'Verification is unavailable.';
    if ($sec33_err === '') {
        $sec33_epoch = function_exists('bc_auth_epoch_bump') ? bc_auth_epoch_bump() : 0;
        if ($sec33_epoch > 0) {
            if (function_exists('bc_auth_epoch_stamp')) bc_auth_epoch_stamp(); // stay signed in here
            if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details['email'], 'logout_all_sessions', 'auth_epoch', (string)$sec33_epoch);
            $_SESSION["product_purchase_response"] = 'All sessions logged out (website, vendor panels, super-admin panel and mobile apps). New epoch: ' . $sec33_epoch . '.';
        } else {
            $_SESSION["product_purchase_response"] = 'Could not revoke sessions (storage error).';
        }
    } else {
        $_SESSION["product_purchase_response"] = 'Session revocation rejected: ' . $sec33_err;
    }
    header("Location: BruteForceSecurity.php");
    exit();
}
$sec33_notice = '';
if (!empty($_SESSION["product_purchase_response"])) {
    $sec33_notice = (string)$_SESSION["product_purchase_response"];
    unset($_SESSION["product_purchase_response"]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php bc_adm_head("Session Security | " . ($get_all_super_admin_site_details["site_title"] ?? 'VTU Platform'), array("area" => "spadmin")); ?>
</head>
<body>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "SESSION SECURITY", "crumb" => "Home / Session Security")); ?>

    <section class="section dashboard">
      <div class="row g-4">
        <div class="col-12">
          <div class="card border-danger border-opacity-50 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex align-items-center gap-2"><i class="bi bi-eject text-danger"></i><h5 class="fw-bold mb-0">Logout All Sessions (Platform-wide)</h5></div>
            <div class="card-body p-4">
              <?php if ($sec33_notice !== ''): ?>
              <div class="alert alert-info rounded-3"><?php echo htmlspecialchars($sec33_notice, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php endif; ?>
              <p class="text-muted">Sign out <strong>every</strong> session on the platform — customer website and mobile apps (app tokens revoked instantly), every vendor's bc-admin and every super-admin session. Use this after a security incident. You stay signed in on this device only.</p>
              <p class="small text-muted mb-4">Current session epoch: <strong><?php echo function_exists('bc_auth_epoch') ? (int)bc_auth_epoch() : 1; ?></strong> — every logout-all raises it by one, which is what invalidates older sessions.</p>
              <form method="post" onsubmit="return (event.submitter && event.submitter.name === 'logout-all-sessions') ? confirm('This logs out EVERYONE everywhere including the mobile apps. Continue?') : true;">
                <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                <div class="row g-3 align-items-end">
                  <div class="col-md-4"><label class="form-label small fw-bold text-muted">SECURITY PIN</label><input type="password" name="sec33_pin" class="form-control rounded-3" required></div>
                  <div class="col-md-4"><label class="form-label small fw-bold text-muted">AUTHENTICATOR CODE (or email OTP)</label><input name="sec33_factor" class="form-control rounded-3" required placeholder="6-digit code"></div>
                  <div class="col-md-4"><button name="logout-all-sessions" class="btn btn-danger rounded-pill px-4 fw-bold w-100"><i class="bi bi-eject"></i> Logout ALL Sessions</button><button name="sec33_send_code" class="btn btn-outline-secondary rounded-pill px-4 fw-bold w-100 mt-2" formnovalidate>Email me a code instead</button></div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>
<?php bc_adm_foot(array("area" => "spadmin")); ?>
</body>
</html>
