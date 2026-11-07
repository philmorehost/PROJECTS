<?php session_start();
include("../func/bc-admin-config.php");

// Handle Actions
if (isset($_POST['withdrawal_action'])) {
    $action = $_POST['action_type'];
    $ref = mysqli_real_escape_string($connection_server, $_POST['reference']);
    $reason = mysqli_real_escape_string($connection_server, $_POST['reason'] ?? '');

    // Fetch transaction details
    $q_tx = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' AND reference='$ref' LIMIT 1");
    $tx = mysqli_fetch_assoc($q_tx);

    if (!$tx) {
        $_SESSION["product_purchase_response"] = "Error: Transaction not found";
    } else {
        $username = $tx['username'];
        $amount = (float)$tx['amount'];
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

                    // STRICT VENDOR ISOLATION: block the payout if this vendor has not activated
                    // their own withdrawal API for this provider — never fall back to the
                    // platform's or another vendor's keys.
                    if (!$gate_cfg) {
                        $can_proceed = false;
                        $_SESSION["product_purchase_response"] = "Error: Withdrawal provider not activated for this vendor. Withdrawal cannot be processed.";
                    } else {
                        $using_platform_keys = ($gate_cfg['vendor_id'] ?? -1) == 0;
                        $can_proceed = true;
                        if ($using_platform_keys) {
                            $check_v = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1"));
                            if (($check_v['balance'] ?? 0) < $amount) {
                                $can_proceed = false;
                                $_SESSION["product_purchase_response"] = "Error: Insufficient platform balance to process this withdrawal.";
                            }
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
    header("Location: Withdrawals.php");
    exit();
}
?>
<!DOCTYPE html>
<head>
    <title>Withdrawal Approvals | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>WITHDRAWAL APPROVALS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Withdrawals</li>
        </ol>
      </nav>
    </div>

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
                    <div class="modal-header border-0">
                        <h5 class="modal-title fw-bold" id="modalTitle">Rejection Reason</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="modalRef" name="reference">
                        <input type="hidden" id="modalAction" name="action_type">
                        <textarea name="reason" class="form-control rounded-3" rows="4" placeholder="Enter reason for the user..." required></textarea>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-light rounded-3" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="withdrawal_action" class="btn btn-primary rounded-3 px-4">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Hidden Approve Form -->
    <form id="approveForm" method="post" action="" style="display:none;">
        <input type="hidden" name="reference" id="approveRef">
        <input type="hidden" name="action_type" value="approve">
        <input type="hidden" name="withdrawal_action" value="1">
    </form>

    <?php include("../func/bc-admin-footer.php"); ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function openReasonModal(type, ref) {
            document.getElementById('modalRef').value = ref;
            document.getElementById('modalAction').value = type;
            document.getElementById('modalTitle').innerText = (type === 'reject') ? 'Rejection Reason' : 'Cancellation Reason';
            new bootstrap.Modal(document.getElementById('reasonModal')).show();
        }

        function confirmApproval(ref, amount) {
            Swal.fire({
                title: 'Confirm Approval',
                text: `Are you sure you want to approve and send ₦${amount} to this user?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Yes, Approve'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait while we complete the transfer.',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    document.getElementById('approveRef').value = ref;
                    document.getElementById('approveForm').submit();
                }
            })
        }
    </script>
</body>
</html>