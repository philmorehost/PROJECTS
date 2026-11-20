<?php session_start();
include("../func/bc-admin-config.php");

// Handle Actions
if (isset($_POST['withdrawal_action']) || isset($_POST['send_stepup_code'])) {
    // §29: every money action on this page needs a CSRF token; approvals also
    // need step-up (security PIN + authenticator/email code), a pending-status
    // guard and an audit record. This page was the hole in the ₦1,000-repeat
    // withdrawal incident: session-only, replayable, unlogged approvals.
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $action = $_POST['action_type'] ?? '';
    $ref = mysqli_real_escape_string($connection_server, $_POST['reference'] ?? '');
    $reason = mysqli_real_escape_string($connection_server, $_POST['reason'] ?? '');
    $admin_vid = (int)$get_logged_admin_details["id"];

    if (isset($_POST['send_stepup_code'])) {
        $send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp($admin_vid, (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'withdrawal_approve', 'Withdrawal Approval')
            : 'Verification is unavailable.';
        $_SESSION["product_purchase_response"] = $send_err === '' ? "Verification code sent to your email." : "Error: $send_err";
        header("Location: Withdrawals.php");
        exit();
    }

    // Fetch transaction details (prepared: references are attacker-influenced)
    $tx = function_exists('bc_q_row')
        ? bc_q_row("SELECT * FROM sas_transactions WHERE vendor_id=? AND reference=? LIMIT 1", array($admin_vid, (string)($_POST['reference'] ?? '')))
        : null;
    if ($tx === null) {
        $q_tx = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' AND reference='$ref' LIMIT 1");
        $tx = $q_tx ? mysqli_fetch_assoc($q_tx) : null;
    }

    if (!$tx) {
        $_SESSION["product_purchase_response"] = "Error: Transaction not found";
    } else {
        $username = $tx['username'];
        $amount = (float)$tx['amount'];

        // §29 guards: state machine + step-up + separation of duties.
        $withdrawal_claimed = false;
        $guard_error = '';
        if ($action === 'approve' || $action === 'reject') {
            if ((int)$tx['status'] !== 2) {
                $guard_error = 'This withdrawal has already been processed.';
            } elseif (function_exists('bc_stepup_admin_verify')) {
                $guard_error = bc_stepup_admin_verify($get_logged_admin_details, $_POST, 'withdrawal_approve');
            }
            if ($guard_error === '' && $action === 'approve') {
                // Separation of duties: never approve a payout to your own account.
                $tx_user = function_exists('bc_q_row')
                    ? bc_q_row("SELECT email FROM sas_users WHERE vendor_id=? AND username=? LIMIT 1", array($admin_vid, (string)$username))
                    : null;
                if ($tx_user && !empty($tx_user['email']) && strcasecmp((string)$tx_user['email'], (string)$get_logged_admin_details['email']) === 0) {
                    $guard_error = 'You cannot approve a withdrawal from your own account. Another admin must approve it.';
                    if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details['email'], 'withdrawal_self_approval_blocked', $ref, 'Self-approval attempt stopped');
                }
            }
            if ($guard_error === '' && $action === 'approve') {
                // Atomic claim: exactly one request may move 2 (pending) -> 6
                // (processing). A replayed or double-submitted approval stops here.
                mysqli_query($connection_server, "UPDATE sas_transactions SET status=6 WHERE reference='$ref' AND vendor_id='$admin_vid' AND status=2");
                if (mysqli_affected_rows($connection_server) === 1) {
                    $withdrawal_claimed = true;
                } else {
                    $guard_error = 'This withdrawal has already been processed.';
                }
            }
        } elseif ($action === 'cancel') {
            if ((int)$tx['status'] !== 2) $guard_error = 'Only a pending withdrawal can be cancelled.';
        } elseif ($action === 'pending') {
            if ((int)$tx['status'] !== 4) $guard_error = 'Only a cancelled withdrawal can be moved back to pending.';
        } else {
            $guard_error = 'Unknown action.';
        }
        if ($guard_error !== '') {
            $_SESSION["product_purchase_response"] = "Error: $guard_error";
            header("Location: Withdrawals.php");
            exit();
        }

        $total_debit = (float)$tx['discounted_amount'];
        $account_number = $tx['product_unique_id'];
        $bank_code_display = $tx['type_alternative']; // Standardly "Bank Transfer" or code

        $total_debit = (float)$tx['discounted_amount'];
        $account_number = $tx['product_unique_id'];
        $bank_code_display = $tx['type_alternative']; // Standardly "Bank Transfer" or code

        if ($action == 'approve') {
            // Processing logic adapted from web/func/bank-transfer.php
            $vid = $get_logged_admin_details["id"];
            $select_v = mysqli_query($connection_server, "SELECT payout_provider, payout_activated FROM sas_vendors WHERE id='$vid' LIMIT 1");
            $rv = mysqli_fetch_assoc($select_v);
            $payout_provider = $rv['payout_provider'] ?? '';
            $payout_activated = ($rv['payout_activated'] == 1);

            if (!$payout_activated) {
                $_SESSION["product_purchase_response"] = "Error: Withdrawal service not activated for your account.";
            } else if (empty($payout_provider)) {
                $_SESSION["product_purchase_response"] = "Error: Payout provider not configured";
            } else {
                $q_hist = mysqli_query($connection_server, "SELECT * FROM sas_bank_transfer_history WHERE reference='$ref' LIMIT 1");
                $hist = mysqli_fetch_assoc($q_hist);

                if (!$hist) {
                    $_SESSION["product_purchase_response"] = "Error: Transfer details missing in history table";
                } else {
                    $bank_code = $hist['bank_code'];
                    $account_name = $hist['account_name'];
                    $narration = $hist['narration'];

                    $gate_cfg = getWithdrawalGatewayDetails($payout_provider, $vid);
                    $using_platform_keys = ($gate_cfg && ($gate_cfg['vendor_id'] ?? -1) == 0);

                    $can_proceed = true;
                    if ($using_platform_keys) {
                        $check_v = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1"));
                        if (($check_v['balance'] ?? 0) < $amount) {
                            $can_proceed = false;
                            $_SESSION["product_purchase_response"] = "Error: Insufficient platform balance to process this withdrawal.";
                        }
                    }

                    if ($can_proceed) {
                        $res = ['status' => 'failed', 'message' => 'Internal processing error'];

                        if ($payout_provider == 'payhub') {
                            $res = payhubInitiatePayout($amount, $bank_code, $account_number, $account_name, $narration, $vid);
                        } else if ($payout_provider == 'paystack') {
                            $raw_res = paystackResolveAccount($account_number, $bank_code, $vid);
                            if (($raw_res['status'] ?? '') == 'success') {
                                $acc_name = $raw_res['account_name'] ?? '';
                                if (empty($acc_name)) {
                                    $inner = json_decode($raw_res['json_result'] ?? '{}', true);
                                    $acc_name = $inner['data']['account_name'] ?? '';
                                }
                                $rec = paystackCreateTransferRecipient($acc_name, $account_number, $bank_code, $vid);
                                $rec_inner = json_decode($rec['json_result'] ?? '{}', true);
                                if (($rec_inner['status'] ?? false) === true && !empty($rec_inner['data']['recipient_code'])) {
                                    $ps_res = paystackInitiateTransfer($amount * 100, $rec_inner['data']['recipient_code'], $narration, $vid);
                                    $ps_inner = json_decode($ps_res['json_result'] ?? '{}', true);
                                    if (($ps_inner['status'] ?? false) === true) {
                                        $res = ['status' => 'success', 'data' => ['reference' => $ps_inner['data']['reference'] ?? ($ps_inner['data']['transfer_code'] ?? 'N/A')]];
                                    } else {
                                        $res['message'] = $ps_inner['message'] ?? ($ps_res['message'] ?? 'Transfer initiation failed');
                                    }
                                } else {
                                    $res['message'] = $rec_inner['message'] ?? ($rec['message'] ?? 'Recipient creation failed');
                                }
                            } else {
                                $res['message'] = $raw_res['message'] ?? 'Account resolution failed';
                            }
                        }

                        if (($res['status'] ?? '') == 'success') {
                            $api_ref = $res['data']['reference'] ?? ($res['reference'] ?? ($res['data']['data']['reference'] ?? 'N/A'));
                            alterTransaction($ref, "status", 1);
                            alterTransaction($ref, "api_reference", $api_ref);
                            $orig_desc = str_replace(" - Awaiting Admin Approval", "", $tx['description']);
                            alterTransaction($ref, "description", $orig_desc);

                            // Debit Vendor if using platform keys
                            if ($using_platform_keys) {
                                chargeVendor("debit", "payout", "Bank Transfer", $ref, $amount, $amount, "Platform Payout for user withdrawal $ref", $_SERVER['HTTP_HOST'], 1);
                            }

                            // Email User
                            $u_email = get_user_info($username, "email");
                            $subject = "Withdrawal Successful";
                            $body = "Dear $username, your withdrawal request ($ref) of ₦" . number_format($amount, 2) . " has been approved and processed successfully to your bank account ($account_number).";
                            sendVendorEmail($u_email, $subject, $body);

                            if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details['email'], 'withdrawal_approve', $ref, 'Approved ₦' . number_format($amount, 2) . ' to ' . $username);
                            $_SESSION["product_purchase_response"] = "Withdrawal Approved and Processed Successfully";
                        } else {
                            $err_msg = $res['message'] ?? ($res['json_result'] ? json_decode($res['json_result'], true)['message'] ?? 'API Provider Error' : 'API Provider Error');
                            $_SESSION["product_purchase_response"] = "Error: " . $err_msg;
                        }
                    }
                }
            }
        } else if ($action == 'reject') {
            // Reverse Funds
            $refund_ref = "REV" . time();
            $refund = chargeOtherUser($username, "credit", "Refund", "Withdrawal Rejection", $refund_ref, $ref, $amount, $total_debit, "Refund for rejected withdrawal $ref", "SYSTEM", $_SERVER['HTTP_HOST'], 1);

            if ($refund == "success") {
                alterTransaction($ref, "status", 3);
                alterTransaction($ref, "description", "Withdrawal Rejected: $reason");

                // Email User
                $u_email = get_user_info($username, "email");
                $subject = "Withdrawal Rejected";
                $body = "Dear $username, your withdrawal request ($ref) has been rejected.<br><b>Reason:</b> $reason<br>The funds have been reversed to your wallet.";
                sendVendorEmail($u_email, $subject, $body);

                $_SESSION["product_purchase_response"] = "Withdrawal Rejected and Funds Reversed";
            } else {
                $_SESSION["product_purchase_response"] = "Error: Failed to refund user wallet";
            }
        } else if ($action == 'cancel') {
            alterTransaction($ref, "status", 4);
            alterTransaction($ref, "description", "Withdrawal Cancelled: $reason");

            // Email User
            $u_email = get_user_info($username, "email");
            $subject = "Withdrawal Cancelled";
            $body = "Dear $username, your withdrawal request ($ref) has been cancelled.<br><b>Reason:</b> $reason<br>Please contact support for further information.";
            sendVendorEmail($u_email, $subject, $body);

            $_SESSION["product_purchase_response"] = "Withdrawal Cancelled";
        } else if ($action == 'pending') {
            alterTransaction($ref, "status", 2);
            alterTransaction($ref, "description", str_replace("Cancelled:", "Pending Review:", $tx['description']));
            $_SESSION["product_purchase_response"] = "Withdrawal moved back to Pending";
        }
    }
    // §29: an approval that did not reach a terminal state releases its claim
    // (6 -> 2) so the request returns to the pending queue untouched.
    if (!empty($withdrawal_claimed)) {
        $cur = function_exists('bc_q_row') ? bc_q_row("SELECT status FROM sas_transactions WHERE reference=? AND vendor_id=? LIMIT 1", array((string)($_POST['reference'] ?? ''), $admin_vid)) : null;
        if ($cur && (int)$cur['status'] === 6) {
            mysqli_query($connection_server, "UPDATE sas_transactions SET status=2 WHERE reference='$ref' AND vendor_id='$admin_vid' AND status=6");
        }
    }

    exit();
}
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Withdrawal Approvals | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "WITHDRAWAL APPROVALS", "crumb" => "Home / Withdrawals")); ?>

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-4 border-0">
                    <h5 class="fw-bold mb-0 text-primary">Pending Withdrawals</h5>
                    <p class="text-muted small mb-0">Manually approve or reject user withdrawal requests</p>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="border-0 small fw-bold">Date</th>
                                    <th class="border-0 small fw-bold">User</th>
                                    <th class="border-0 small fw-bold">Bank Details</th>
                                    <th class="border-0 small fw-bold">Amount</th>
                                    <th class="border-0 small fw-bold">Status</th>
                                    <th class="border-0 small fw-bold text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $get_pending = mysqli_query($connection_server, "SELECT t.*, h.bank_name, h.account_name, h.account_number as acc_no FROM sas_transactions t LEFT JOIN sas_bank_transfer_history h ON t.reference = h.reference WHERE t.vendor_id='".$get_logged_admin_details["id"]."' AND (t.status = 2 OR t.status = 4) AND t.type_alternative = 'Bank Transfer' ORDER BY t.date DESC");
                                if (mysqli_num_rows($get_pending) > 0) {
                                    while ($row = mysqli_fetch_assoc($get_pending)) {
                                        $status_label = tranStatus($row['status']);
                                        $status_class = ($row['status'] == 2) ? 'bg-warning' : 'bg-secondary';
                                ?>
                                <tr>
                                    <td class="small"><?php echo date('M d, H:i', strtotime($row['date'])); ?></td>
                                    <td>
                                        <div class="fw-bold small"><?php echo $row['username']; ?></div>
                                    </td>
                                    <td class="small">
                                        <div class="text-dark fw-bold"><?php echo $row['account_name'] ?? 'N/A'; ?></div>
                                        <div class="text-muted"><?php echo ($row['bank_name'] ?? 'N/A') . " - " . ($row['acc_no'] ?? $row['product_unique_id']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark small">₦<?php echo number_format($row['amount'], 2); ?></div>
                                        <div class="text-muted" style="font-size: 10px;">Fee: ₦<?php echo number_format($row['discounted_amount'] - $row['amount'], 2); ?></div>
                                    </td>
                                    <td><span class="badge <?php echo $status_class; ?> rounded-pill" style="font-size: 10px;"><?php echo $status_label; ?></span></td>
                                    <td class="text-end">
                                        <?php if ($row['status'] == 2) { ?>
                                        <button class="btn btn-success btn-sm rounded-3 px-3" onclick="confirmApproval('<?php echo $row['reference']; ?>', '<?php echo $row['amount']; ?>')">Approve</button>
                                        <button class="btn btn-danger btn-sm rounded-3" onclick="openReasonModal('reject', '<?php echo $row['reference']; ?>')">Reject</button>
                                        <button class="btn btn-outline-danger btn-sm rounded-3" onclick="openReasonModal('cancel', '<?php echo $row['reference']; ?>')">Cancel</button>
                                        <?php } else if ($row['status'] == 4) { ?>
                                        <form method="post" action="" style="display:inline;">
                                            <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                                            <input type="hidden" name="reference" value="<?php echo $row['reference']; ?>">
                                            <input type="hidden" name="action_type" value="pending">
                                            <button type="submit" name="withdrawal_action" class="btn btn-outline-primary btn-sm rounded-3">Set Pending</button>
                                        </form>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php
                                    }
                                } else {
                                    echo '<tr><td colspan="6" class="text-center py-5 text-muted small">No pending withdrawal requests found</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- History Section -->
            <div class="card mt-4 shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-4 border-0">
                    <h5 class="fw-bold mb-0">Withdrawal History</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="border-0 small fw-bold">Date</th>
                                    <th class="border-0 small fw-bold">User</th>
                                    <th class="border-0 small fw-bold">Amount</th>
                                    <th class="border-0 small fw-bold">Status</th>
                                    <th class="border-0 small fw-bold">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $get_history = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' AND (status = 1 OR status = 3) AND type_alternative = 'Bank Transfer' ORDER BY date DESC LIMIT 20");
                                while ($h = mysqli_fetch_assoc($get_history)) {
                                    $h_status = tranStatus($h['status']);
                                    $h_class = ($h['status'] == 1) ? 'bg-success' : 'bg-danger';
                                ?>
                                <tr>
                                    <td class="small"><?php echo date('M d, Y', strtotime($h['date'])); ?></td>
                                    <td class="small fw-bold"><?php echo $h['username']; ?></td>
                                    <td class="small fw-bold">₦<?php echo number_format($h['amount'], 2); ?></td>
                                    <td><span class="badge <?php echo $h_class; ?> rounded-pill" style="font-size: 10px;"><?php echo $h_status; ?></span></td>
                                    <td class="small text-muted"><?php echo $h['description']; ?></td>
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

    <!-- Reason Modal -->
    <div class="modal fade" id="reasonModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 shadow border-0">
                <form method="post" action="">
                    <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                    <div class="modal-header border-0">
                        <h5 class="modal-title fw-bold" id="modalTitle">Rejection Reason</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="modalRef" name="reference">
                        <input type="hidden" id="modalAction" name="action_type">
                        <textarea name="reason" class="form-control rounded-3" rows="4" placeholder="Enter reason for the user..." required></textarea>
                        <div class="row g-2 mt-2">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted">SECURITY PIN</label>
                                <input type="password" name="pin" class="form-control rounded-3 text-center" maxlength="4" inputmode="numeric" autocomplete="one-time-code" placeholder="••••">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted">AUTHENTICATOR / EMAIL CODE</label>
                                <input type="text" name="otp_code" class="form-control rounded-3 text-center" maxlength="6" inputmode="numeric" placeholder="6-digit code">
                            </div>
                        </div>
                        <p class="small text-muted mt-2 mb-0">Rejection refunds the user, so it needs your Security PIN plus a verification code. Use the "Send code" button in the Approve dialog if you have no authenticator app.</p>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="withdrawal_action" class="btn btn-primary rounded-3 px-4">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- §29 Approve Modal: approval pays out real money, so it requires the
         admin Security PIN plus a second factor (authenticator app code, or an
         emailed code via the Send button). -->
    <div class="modal fade" id="approveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 shadow border-0">
                <form method="post" action="">
                    <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                    <div class="modal-header border-0">
                        <h5 class="modal-title fw-bold">Approve Withdrawal</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">You are about to send <b id="approveAmountLabel">₦0</b> to the user's bank account. This cannot be undone from here.</p>
                        <input type="hidden" name="reference" id="approveRef">
                        <input type="hidden" name="action_type" value="approve">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">SECURITY PIN</label>
                            <input type="password" name="pin" class="form-control rounded-3 text-center" maxlength="4" inputmode="numeric" autocomplete="one-time-code" placeholder="••••" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">AUTHENTICATOR CODE <span class="fw-normal">(if enabled)</span></label>
                            <input type="text" name="totp_code" class="form-control rounded-3 text-center" maxlength="6" inputmode="numeric" placeholder="6-digit code">
                        </div>
                        <div class="mb-1">
                            <label class="form-label small fw-bold text-muted">OR EMAIL CODE</label>
                            <div class="input-group">
                                <input type="text" name="otp_code" class="form-control rounded-3 text-center" maxlength="6" inputmode="numeric" placeholder="6-digit code">
                                <button type="submit" name="send_stepup_code" value="1" class="btn btn-outline-primary rounded-3 ms-2" formnovalidate>Send code</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="withdrawal_action" value="1" class="btn btn-success rounded-3 px-4 fw-bold">Approve &amp; Pay</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function openReasonModal(type, ref) {
            document.getElementById('modalRef').value = ref;
            document.getElementById('modalAction').value = type;
            document.getElementById('modalTitle').innerText = (type === 'reject') ? 'Rejection Reason' : 'Cancellation Reason';
            new bootstrap.Modal(document.getElementById('reasonModal')).show();
        }

        function confirmApproval(ref, amount) {
            document.getElementById('approveRef').value = ref;
            document.getElementById('approveAmountLabel').textContent = '₦' + amount;
            new bootstrap.Modal(document.getElementById('approveModal')).show();
        }
    </script>


<?php bc_adm_foot(array("area" => "admin")); ?>
