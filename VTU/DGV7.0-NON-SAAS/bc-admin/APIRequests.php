<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-admin-config.php");

// §33/§10: API access decisions are POST + CSRF + step-up only — approvals need the
// full Security PIN + authenticator/email code; rejections need the PIN.
// Bare GET links can no longer perform them.
$sec33_gate_ok = false;
if (isset($_POST['sec33_send_code'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_send_err = function_exists('bc_stepup_send_email_otp')
        ? bc_stepup_send_email_otp((int)$get_logged_admin_details['id'], (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'api_request_decision', 'API Access Decision')
        : 'Verification is unavailable.';
    $_SESSION["product_purchase_response"] = $sec33_send_err === '' ? "Verification code sent to your email." : "Error: $sec33_send_err";
    header("Location: APIRequests.php");
    exit();
}
if (isset($_POST['sec33_action']) && isset($_POST['sec33_id'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $sec33_action = (string)$_POST['sec33_action'];
    $sec33_factor = (string)($_POST['sec_factor'] ?? '');
    if ($sec33_action === 'approve') {
        $sec33_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => $sec33_factor, 'totp_code' => $sec33_factor];
    } else {
        $sec33_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => (string)($_POST['sec_factor'] ?? ''), 'totp_code' => (string)($_POST['sec_factor'] ?? '')];
    }
    $sec33_err = function_exists('bc_stepup_admin_verify')
            ? bc_stepup_admin_verify($get_logged_admin_details, $sec33_input, 'api_request_decision')
            : 'Verification is unavailable.';
    if ($sec33_err !== '') {
        $_SESSION["product_purchase_response"] = "Error: " . $sec33_err;
        header("Location: APIRequests.php");
        exit();
    }
    $sec33_gate_ok = true;
    if (function_exists('bc_sec_audit')) bc_sec_audit((int)$get_logged_admin_details['id'], 'admin', (string)$get_logged_admin_details['email'], 'api_request_' . $sec33_action, 'request#' . (int)$_POST['sec33_id'], '');
    $_GET[$sec33_action] = (string)(int)$_POST['sec33_id'];
}
if (!$sec33_gate_ok && (isset($_GET["approve"]) || isset($_GET["reject"]))) {
    $_SESSION["product_purchase_response"] = "Error: that action now needs the secure confirmation form (PIN + authenticator code).";
    header("Location: APIRequests.php");
    exit();
}

if (isset($_GET["approve"])) {
	$req_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["approve"])));
	$get_req = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_api_requests WHERE id='$req_id' AND vendor_id='" . $get_logged_admin_details["id"] . "' AND status='pending'"));

	if ($get_req) {
		$uid = $get_req['user_id'];
		$domain = $get_req['api_domain'];
		// Upgrade user to level 3 (API Vendor) and status 1
		mysqli_query($connection_server, "UPDATE sas_users SET account_level='3', api_status='1', status='1', api_domain='$domain' WHERE id='$uid' AND vendor_id='" . $get_logged_admin_details["id"] . "'");
		mysqli_query($connection_server, "UPDATE sas_api_requests SET status='approved', date_approved=NOW() WHERE id='$req_id'");

		$_SESSION["product_purchase_response"] = "API Access Approved and User Upgraded Successfully!";
	}
	header("Location: APIRequests.php");
	exit();
}

if (isset($_GET["reject"])) {
	$req_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["reject"])));
	mysqli_query($connection_server, "UPDATE sas_api_requests SET status='rejected' WHERE id='$req_id' AND vendor_id='" . $get_logged_admin_details["id"] . "'");
	$_SESSION["product_purchase_response"] = "API Access Request Rejected.";
	header("Location: APIRequests.php");
	exit();
}

$api_requests = mysqli_query($connection_server, "SELECT r.*, u.email as user_email, u.phone_number FROM sas_api_requests r JOIN sas_users u ON r.user_id = u.id WHERE r.vendor_id='" . $get_logged_admin_details["id"] . "' ORDER BY r.date_requested DESC");
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("API Access Requests | Admin", array("area" => "admin"));
?>

<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "API ACCESS REQUESTS", "crumb" => "Home / API Requests")); ?>

	<section class="section">
		<div class="row">
			<div class="col-lg-12">
				<div class="card border-0 shadow-sm rounded-4">
					<div class="card-body p-4">
						<h5 class="fw-bold mb-4">Pending & Recent Requests</h5>

						<div class="table-responsive">
							<table class="table table-hover align-middle">
								<thead class="bg-light">
									<tr>
										<th>User Details</th>
										<th>Target Domain</th>
										<th>Date Requested</th>
										<th>Status</th>
										<th class="text-center">Action</th>
									</tr>
								</thead>
								<tbody>
									<?php if (mysqli_num_rows($api_requests) > 0) {
										while ($row = mysqli_fetch_assoc($api_requests)) { ?>
											<tr>
												<td>
													<div class="fw-bold text-dark"><?php echo htmlspecialchars($row['username']); ?></div>
													<div class="small text-muted"><?php echo htmlspecialchars($row['user_email']); ?></div>
													<div class="small text-muted"><?php echo htmlspecialchars($row['phone_number']); ?></div>
												</td>
												<td class="small">
													<div class="fw-bold text-primary"><?php echo !empty($row['api_domain']) ? htmlspecialchars($row['api_domain']) : '<span class="text-danger small">No Domain Set</span>'; ?></div>
												</td>
												<td class="small"><?php echo date('M d, Y H:i', strtotime($row['date_requested'])); ?></td>
												<td>
													<?php if ($row['status'] == 'pending') { ?>
														<span class="badge bg-warning text-dark px-3 py-2 rounded-pill">Pending</span>
													<?php } elseif ($row['status'] == 'approved') { ?>
														<span class="badge bg-success px-3 py-2 rounded-pill">Approved</span>
														<div class="small text-muted mt-1"><?php echo date('M d, Y', strtotime($row['date_approved'])); ?></div>
													<?php } else { ?>
														<span class="badge bg-danger px-3 py-2 rounded-pill">Rejected</span>
													<?php } ?>
												</td>
												<td class="text-center">
													<?php if ($row['status'] == 'pending') { ?>
														<div class="d-flex gap-2 justify-content-center">
															<button type="button" class="btn btn-primary btn-sm rounded-pill px-3" onclick="sec33ApiAction('approve', <?php echo (int)$row['id']; ?>, 'Approve API access?', 'The user will be upgraded to API level. This needs your PIN + authenticator code and is audited.', 'Approve')">Approve</button>
															<button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="sec33ApiAction('reject', <?php echo (int)$row['id']; ?>, 'Reject API request?', 'Reject this API access request? This needs your Security PIN and is audited.', 'Reject')">Reject</button>
														</div>
													<?php } else { ?>
														<span class="text-muted small">-</span>
													<?php } ?>
												</td>
											</tr>
										<?php }
									} else { ?>
										<tr>
											<td colspan="4" class="text-center py-5 text-muted">No API requests found.</td>
										</tr>
									<?php } ?>
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
    function sec33ApiAction(action, id, title, text, btn) {
        document.getElementById('sec33Action').value = action;
        document.getElementById('sec33Id').value = id;
        document.getElementById('sec33Title').textContent = title;
        document.getElementById('sec33Text').textContent = text;
        document.getElementById('sec33Btn').textContent = btn;
        new bootstrap.Modal(document.getElementById('sec33Modal')).show();
    }
    </script>

<?php bc_adm_foot(array("area" => "admin")); ?>
