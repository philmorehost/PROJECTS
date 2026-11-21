<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
include("../func/bc-admin-config.php");
require_once(__DIR__ . "/../func/bc-account-deletion.php");

// §43 — Account Deletion Requests. Approving a request moves the account to
// status 3 ("Deleted": it can no longer sign in, API access is disabled and
// app sessions are revoked) WITHOUT erasing anything. Permanent erasure is a
// separate, deliberate action below, available only for accounts that are
// already Deleted. Every decision needs the admin Security PIN + a second
// factor (authenticator or emailed code) — the same step-up gate Users.php
// uses for account actions.
$del_vendor_id = (int)$get_logged_admin_details["id"];
$del_admin_name = (string)($get_logged_admin_details["email"] ?? ('admin#' . $del_vendor_id));

$del_stepup_verify = function () use ($get_logged_admin_details) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $input = array(
        'pin' => (string)($_POST['sec_pin'] ?? ''),
        'otp_code' => (string)($_POST['sec_factor'] ?? ''),
        'totp_code' => (string)($_POST['sec_factor'] ?? ''),
    );
    return function_exists('bc_stepup_admin_verify')
        ? bc_stepup_admin_verify($get_logged_admin_details, $input, 'account_deletion')
        : 'Verification is unavailable.';
};

if (isset($_POST["del_send_code"])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $err = function_exists('bc_stepup_send_email_otp')
        ? bc_stepup_send_email_otp($del_vendor_id, (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'account_deletion', 'Account Deletion Decision')
        : 'Verification is unavailable.';
    $_SESSION["product_purchase_response"] = $err === '' ? "Verification code sent to your email." : "Error: $err";
    header("Location: AccountDeletionRequests.php");
    exit();
}

if (isset($_POST["del_decide"])) {
    $err = $del_stepup_verify();
    if ($err !== '') {
        $_SESSION["product_purchase_response"] = "Error: " . $err;
        header("Location: AccountDeletionRequests.php");
        exit();
    }
    $approve = ($_POST["del_decide"] === 'approve');
    $done = bc_deletion_decide($del_vendor_id, (int)($_POST["request_id"] ?? 0), $approve, $del_admin_name);
    if (function_exists('bc_sec_audit')) bc_sec_audit($del_vendor_id, 'admin', $del_admin_name, $approve ? 'account_deletion_approved' : 'account_deletion_rejected', 'request#' . (int)($_POST["request_id"] ?? 0), '');
    $_SESSION["product_purchase_response"] = $done
        ? ($approve ? "Request approved. The account is now Deleted and can no longer sign in." : "Request rejected. The account is untouched.")
        : "That request is no longer pending.";
    header("Location: AccountDeletionRequests.php");
    exit();
}

if (isset($_POST["del_purge"])) {
    $err = $del_stepup_verify();
    if ($err !== '') {
        $_SESSION["product_purchase_response"] = "Error: " . $err;
        header("Location: AccountDeletionRequests.php");
        exit();
    }
    $purge_user_id = (int)($_POST["user_id"] ?? 0);
    $confirm = strtolower(trim((string)($_POST["confirm_username"] ?? '')));
    $target = null;
    foreach (bc_deletion_deleted_users($del_vendor_id) as $row) {
        if ((int)$row['id'] === $purge_user_id) { $target = $row; break; }
    }
    if (!$target || strtolower((string)$target['username']) !== $confirm) {
        $_SESSION["product_purchase_response"] = "Error: type the exact username of the Deleted account to permanently erase it.";
        header("Location: AccountDeletionRequests.php");
        exit();
    }
    $done = bc_deletion_purge($del_vendor_id, $purge_user_id);
    if (function_exists('bc_sec_audit')) bc_sec_audit($del_vendor_id, 'admin', $del_admin_name, 'account_permanently_deleted', 'user#' . $purge_user_id, (string)$target['username']);
    $_SESSION["product_purchase_response"] = $done
        ? "Account @" . $target['username'] . " permanently deleted. This cannot be undone."
        : "Could not permanently delete that account (it must be in Deleted status first).";
    header("Location: AccountDeletionRequests.php");
    exit();
}

$del_pending = bc_deletion_pending($del_vendor_id);
$del_deleted = bc_deletion_deleted_users($del_vendor_id);
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Account Deletion Requests | " . ($get_all_super_admin_site_details["site_title"] ?? ""), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "Account Deletion Requests", "crumb" => "Home / Account Deletion Requests")); ?>

    <section class="section dashboard">
        <div class="col-12">
            <div class="card px-4 py-4 mb-4">
                <h5 class="card-title">How this works</h5>
                <p class="mb-2">Users request deletion from the public <b>Delete My Account</b> page and prove ownership with a code emailed to their registered address.
                <b>Approving</b> moves the account to <b>Deleted</b> — it can no longer sign in and nothing is erased yet.
                <b>Rejecting</b> closes the request and leaves the account active.
                <b>Permanently delete</b> (bottom table) erases an already-Deleted account forever.</p>
                <p class="mb-0">Every decision on this page needs your <b>Security PIN</b> plus an authenticator code — or use the emailed code:</p>
                <form method="post" class="mt-2">
                    <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                    <button type="submit" name="del_send_code" value="1" class="btn btn-sm btn-outline-primary">Email me a verification code</button>
                </form>
            </div>

            <div class="card px-4 py-4 mb-4">
                <h5 class="card-title">Pending Requests (<?php echo count($del_pending); ?>)</h5>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead><tr><th>Username</th><th>Email</th><th>Reason</th><th>Requested</th><th>Decision</th></tr></thead>
                        <tbody>
                        <?php if (!$del_pending): ?>
                            <tr><td colspan="5" class="text-muted">No pending deletion requests.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($del_pending as $req): ?>
                            <tr>
                                <td><b>@<?php echo htmlspecialchars((string)$req['username']); ?></b></td>
                                <td><?php echo htmlspecialchars((string)$req['email']); ?></td>
                                <td><?php echo htmlspecialchars((string)($req['reason'] ?? '')) ?: '<span class="text-muted">—</span>'; ?></td>
                                <td><?php echo htmlspecialchars((string)$req['requested_at']); ?></td>
                                <td>
                                    <form method="post" class="d-flex flex-wrap gap-1" onsubmit="return confirm('Apply this decision? Approving closes the account immediately.');">
                                        <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                                        <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                                        <input type="password" name="sec_pin" class="form-control form-control-sm" placeholder="Security PIN" required style="max-width:130px;" autocomplete="off">
                                        <input type="text" name="sec_factor" class="form-control form-control-sm" placeholder="Auth / email code" required style="max-width:150px;" autocomplete="one-time-code">
                                        <button type="submit" name="del_decide" value="approve" class="btn btn-sm btn-danger">Approve &amp; Delete</button>
                                        <button type="submit" name="del_decide" value="reject" class="btn btn-sm btn-outline-secondary">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card px-4 py-4">
                <h5 class="card-title">Deleted Accounts — Permanent Erasure (<?php echo count($del_deleted); ?>)</h5>
                <p class="text-muted">These accounts are already closed (status: Deleted). Permanent erasure removes the account row forever and cannot be undone. Type the exact username to confirm. Consider your legal record-keeping duties before erasing.</p>
                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead><tr><th>Username</th><th>Email</th><th>Name</th><th>Balance</th><th>Permanently delete</th></tr></thead>
                        <tbody>
                        <?php if (!$del_deleted): ?>
                            <tr><td colspan="5" class="text-muted">No accounts are in Deleted status.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($del_deleted as $u): ?>
                            <tr>
                                <td><b>@<?php echo htmlspecialchars((string)$u['username']); ?></b></td>
                                <td><?php echo htmlspecialchars((string)$u['email']); ?></td>
                                <td><?php echo htmlspecialchars(trim((string)$u['firstname'] . ' ' . (string)$u['lastname'])); ?></td>
                                <td><?php echo htmlspecialchars((string)$u['balance']); ?></td>
                                <td>
                                    <form method="post" class="d-flex flex-wrap gap-1" onsubmit="return confirm('PERMANENTLY erase this account? This cannot be undone.');">
                                        <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                        <input type="text" name="confirm_username" class="form-control form-control-sm" placeholder="Type username" required style="max-width:140px;" autocomplete="off">
                                        <input type="password" name="sec_pin" class="form-control form-control-sm" placeholder="Security PIN" required style="max-width:130px;" autocomplete="off">
                                        <input type="text" name="sec_factor" class="form-control form-control-sm" placeholder="Auth / email code" required style="max-width:150px;" autocomplete="one-time-code">
                                        <button type="submit" name="del_purge" value="1" class="btn btn-sm btn-dark">Permanently Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
<?php bc_adm_foot(array("area" => "admin")); ?>
