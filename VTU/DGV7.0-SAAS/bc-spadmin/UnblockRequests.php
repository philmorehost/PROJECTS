<?php session_start();
include("../func/bc-spadmin-config.php");

// §33/§10: unblock decisions are POST + CSRF + step-up only — approvals need the
// full Security PIN + authenticator/email code; rejections need the PIN.
// Bare GET links can no longer perform them.
$sec33_gate_ok = false;
if (isset($_POST['sec33_send_code'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_send_err = function_exists('bc_stepup_send_email_otp')
        ? bc_stepup_send_email_otp(0, (string)$get_logged_spadmin_details['email'], (string)$get_logged_spadmin_details['email'], 'unblock_decision', 'Unblock Decision')
        : 'Verification is unavailable.';
    $_SESSION["product_purchase_response"] = $sec33_send_err === '' ? "Verification code sent to your email." : "Error: $sec33_send_err";
    header("Location: UnblockRequests.php");
    exit();
}
if (isset($_POST['sec33_action']) && isset($_POST['sec33_id'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_action = (string)$_POST['sec33_action'];
    $sec33_factor = (string)($_POST['sec_factor'] ?? '');
    if ($sec33_action === 'approve') {
        $sec33_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => $sec33_factor, 'totp_code' => $sec33_factor];
    } else {
        $sec33_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'pin_check_only' => 1];
    }
    $sec33_err = function_exists('bc_stepup_spadmin_verify')
            ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $sec33_input, 'unblock_decision')
            : 'Verification is unavailable.';
    if ($sec33_err !== '') {
        $_SESSION["product_purchase_response"] = "Error: " . $sec33_err;
        header("Location: UnblockRequests.php");
        exit();
    }
    $sec33_gate_ok = true;
    if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details['email'], 'unblock_' . $sec33_action, 'request#' . (int)$_POST['sec33_id'], '');
    $_GET['action'] = $sec33_action;
    $_GET['id'] = (string)(int)$_POST['sec33_id'];
}
if (!$sec33_gate_ok && (isset($_GET["action"]) && isset($_GET["id"]))) {
    $_SESSION["product_purchase_response"] = "Error: that action now needs the secure confirmation form (PIN + authenticator code).";
    header("Location: UnblockRequests.php");
    exit();
}

if (isset($_GET["action"]) && isset($_GET["id"])) {
    $id = (int)$_GET["id"];
    $action = $_GET["action"];

    $req_q = mysqli_query($connection_server, "SELECT * FROM sas_unblock_requests WHERE id='$id'");
    if($req = mysqli_fetch_assoc($req_q)){
        if($action == 'approve'){
            $ip = $req['ip_address'];
            $user = $req['username'];
            $vid = $req['vendor_id'];

            // Unblock IP
            mysqli_query($connection_server, "DELETE FROM sas_blocked_ips WHERE ip_address='$ip' AND vendor_id='$vid'");
            mysqli_query($connection_server, "INSERT INTO sas_ip_whitelist (ip_address, vendor_id, success_count) VALUES ('$ip', '$vid', 5) ON DUPLICATE KEY UPDATE success_count = GREATEST(success_count, 5)");

            // Unblock Account if applicable
            if(!empty($user)){
                mysqli_query($connection_server, "DELETE FROM sas_blocked_ips WHERE ip_address IN (SELECT ip_address FROM sas_login_attempts WHERE username='$user' AND vendor_id='$vid') AND vendor_id='$vid'");
                mysqli_query($connection_server, "DELETE FROM sas_blocked_accounts WHERE username='$user' AND vendor_id='$vid'");
                if (strpos($user, '@') !== false) {
                    mysqli_query($connection_server, "UPDATE sas_vendors SET status=1, is_blocked=0, failed_login_count=0, failed_pin_count=0 WHERE email='$user' AND id='$vid'");
                    // If it is spadmin
                    mysqli_query($connection_server, "UPDATE sas_super_admin SET is_blocked=0, failed_login_count=0, failed_pin_count=0 WHERE email='$user'");
                } else {
                    mysqli_query($connection_server, "UPDATE sas_users SET status=1, is_blocked=0, failed_login_count=0, failed_pin_count=0 WHERE username='$user' AND vendor_id='$vid'");
                }
            }

            mysqli_query($connection_server, "UPDATE sas_unblock_requests SET status='approved' WHERE id='$id'");
            $_SESSION["product_purchase_response"] = "Request Approved and identity whitelisted.";
        } elseif($action == 'reject'){
            mysqli_query($connection_server, "UPDATE sas_unblock_requests SET status='rejected' WHERE id='$id'");
            $_SESSION["product_purchase_response"] = "Request Rejected.";
        }
    }
    header("Location: UnblockRequests.php");
    exit();
}

if (isset($_POST['sec33_action']) && (string)$_POST['sec33_action'] === 'unblock-all') {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_factor = (string)($_POST['sec_factor'] ?? '');
    $sec33_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => $sec33_factor, 'totp_code' => $sec33_factor];
    $sec33_err = function_exists('bc_stepup_spadmin_verify')
        ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $sec33_input, 'unblock_decision')
        : 'Verification is unavailable.';
    if ($sec33_err !== '') {
        $_SESSION["product_purchase_response"] = "Error: " . $sec33_err;
        header("Location: UnblockRequests.php");
        exit();
    }
    if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details['email'], 'unblock_all', 'platform', '');
    $_GET["action"] = 'unblock-all';
}
if (isset($_GET["action"]) && $_GET["action"] == 'unblock-all' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION["product_purchase_response"] = "Error: unblock-all now needs the secure confirmation form (PIN + authenticator code).";
    header("Location: UnblockRequests.php");
    exit();
}
if (isset($_GET["action"]) && $_GET["action"] == 'unblock-all') {
    // Truncate block tables
    mysqli_query($connection_server, "TRUNCATE TABLE sas_blocked_ips");
    mysqli_query($connection_server, "TRUNCATE TABLE sas_blocked_accounts");

    // Reset flags across all tables
    mysqli_query($connection_server, "UPDATE sas_users SET status=1, is_blocked=0, failed_login_count=0, failed_pin_count=0");
    mysqli_query($connection_server, "UPDATE sas_vendors SET status=1, is_blocked=0, failed_login_count=0, failed_pin_count=0");
    mysqli_query($connection_server, "UPDATE sas_super_admin SET is_blocked=0, failed_login_count=0, failed_pin_count=0");

    // Clear pending requests
    mysqli_query($connection_server, "UPDATE sas_unblock_requests SET status='approved' WHERE status='pending'");

    $_SESSION["product_purchase_response"] = "GLOBAL UNBLOCK: All restrictions cleared across the platform.";
    header("Location: UnblockRequests.php");
    exit();
}

?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Unblock Requests | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "spadmin"));
?>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "UNBLOCK REQUESTS", "crumb" => "Home / Unblock Requests")); ?>

    <section class="section dashboard">
        <div class="row mb-4">
            <div class="col-12 text-end">
                <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" onclick="sec33UbAction('unblock-all', 0, 'Lift ALL blocks?', 'This lifts ALL blocks and restrictions platform-wide (users, vendors, IPs). This needs your PIN + authenticator code and is audited.', 'Lift ALL blocks')">
                    <i class="bi bi-shield-slash me-2"></i> UNBLOCK ALL AT ONCE
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Blocked IPs Overview -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0 text-danger">Currently Blocked IPs</h5>
                        <span class="badge bg-danger rounded-pill"><?php echo mysqli_num_rows(mysqli_query($connection_server, "SELECT * FROM sas_blocked_ips")); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase">
                                        <th class="ps-4">IP Address</th>
                                        <th>Expiry</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q_ips = mysqli_query($connection_server, "SELECT * FROM sas_blocked_ips ORDER BY block_until DESC");
                                    if(mysqli_num_rows($q_ips) > 0){
                                        while($row = mysqli_fetch_assoc($q_ips)){
                                            echo "<tr>
                                                <td class='ps-4 fw-bold small'>{$row['ip_address']}</td>
                                                <td class='small'>{$row['block_until']}</td>
                                                <td class='small text-muted'>{$row['reason']}</td>
                                            </tr>";
                                        }
                                    } else {
                                        echo '<tr><td colspan="3" class="text-center py-4 text-muted small">No IPs blocked</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Blocked Accounts Overview -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0 text-danger">Currently Locked Accounts</h5>
                        <span class="badge bg-danger rounded-pill"><?php echo mysqli_num_rows(mysqli_query($connection_server, "SELECT * FROM sas_blocked_accounts")); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase">
                                        <th class="ps-4">Username</th>
                                        <th>Vendor</th>
                                        <th>Expiry</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q_accs = mysqli_query($connection_server, "SELECT * FROM sas_blocked_accounts ORDER BY block_until DESC");
                                    if(mysqli_num_rows($q_accs) > 0){
                                        while($row = mysqli_fetch_assoc($q_accs)){
                                            echo "<tr>
                                                <td class='ps-4 fw-bold small'>@{$row['username']}</td>
                                                <td class='small'>#{$row['vendor_id']}</td>
                                                <td class='small'>{$row['block_until']}</td>
                                            </tr>";
                                        }
                                    } else {
                                        echo '<tr><td colspan="3" class="text-center py-4 text-muted small">No accounts locked</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Requests -->
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0">Pending Security Requests</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase">
                                        <th class="ps-4">Vendor ID</th>
                                        <th>Identity</th>
                                        <th>Reason</th>
                                        <th>Date</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $q = mysqli_query($connection_server, "SELECT * FROM sas_unblock_requests WHERE status='pending' ORDER BY date DESC");
                                    if(mysqli_num_rows($q) > 0){
                                        while($row = mysqli_fetch_assoc($q)){
                                            $identity = !empty($row['username']) ? "@".$row['username']." (".$row['ip_address'].")" : $row['ip_address'];
                                            echo "<tr>
                                                <td class='ps-4'>#{$row['vendor_id']}</td>
                                                <td class='fw-bold'>$identity</td>
                                                <td class='small'>{$row['reason']}</td>
                                                <td class='text-muted small'>{$row['date']}</td>
                                                <td class='text-end pe-4'>
                                                    <button type='button' class='btn btn-success btn-sm rounded-pill px-3' onclick=\"sec33UbAction('approve', {$row['id']}, 'Approve unblock?', 'Unblock this account/IP? This needs your PIN + authenticator code and is audited.', 'Approve')\">Approve</button>
                                                    <button type='button' class='btn btn-danger btn-sm rounded-pill px-3' onclick=\"sec33UbAction('reject', {$row['id']}, 'Reject unblock request?', 'Reject this unblock request? This needs your Security PIN and is audited.', 'Reject')\">Reject</button>
                                                </td>
                                            </tr>";
                                        }
                                    } else {
                                        echo '<tr><td colspan="5" class="text-center py-5 text-muted">No pending unblock requests</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    




    <!-- §33: step-up confirmation -->
    <div class="modal fade" id="sec33Modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content rounded-4 border-0">
          <div class="modal-header border-0"><h5 class="modal-title fw-bold" id="sec33Title">Confirm action</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
            <input type="hidden" name="sec33_action" id="sec33Action" value="">
            <input type="hidden" name="sec33_id" id="sec33Id" value="">
            <p class="text-muted small" id="sec33Text"></p>
            <div class="mb-3"><label class="form-label small fw-bold text-muted">SECURITY PIN</label><input type="password" name="sec_pin" class="form-control rounded-3" required autocomplete="off"></div>
            <div class="mb-1"><label class="form-label small fw-bold text-muted">AUTHENTICATOR / EMAIL CODE <span class="fw-normal">(approvals)</span></label><input name="sec_factor" class="form-control rounded-3" placeholder="6-digit code"></div>
            <button type="submit" name="sec33_send_code" class="btn btn-link btn-sm p-0" formnovalidate>Email me a code instead</button>
          </div>
          <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold" id="sec33Btn">Confirm</button></div>
        </form>
      </div>
    </div>
    <script>
    function sec33UbAction(action, id, title, text, btn) {
        document.getElementById('sec33Action').value = action;
        document.getElementById('sec33Id').value = id;
        document.getElementById('sec33Title').textContent = title;
        document.getElementById('sec33Text').textContent = text;
        document.getElementById('sec33Btn').textContent = btn;
        new bootstrap.Modal(document.getElementById('sec33Modal')).show();
    }
    </script>

<?php bc_adm_foot(array("area" => "spadmin")); ?>
