<?php session_start();
    include("../func/bc-admin-config.php");

    if(!isset($_GET["batch"]) || empty(trim(strip_tags($_GET["batch"])))){
        header("Location: BatchTransactions.php");
        exit();
    }

    $batch = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["batch"])));

    // Handle cancellation requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $vendor_id = (int)$get_logged_admin_details["id"];
        
        if ($_POST['action'] === 'cancel_all') {
            $update = mysqli_query($connection_server, "UPDATE sas_bulk_queue_items SET status='done', response_desc='Cancelled by admin', processed_at=NOW() WHERE vendor_id='$vendor_id' AND batch_number='$batch' AND status IN ('pending', 'processing')");
            if ($update) {
                $_SESSION['batch_action_msg'] = ["status" => "success", "text" => "All pending transactions in this batch have been cancelled by admin."];
            } else {
                $_SESSION['batch_action_msg'] = ["status" => "danger", "text" => "Failed to cancel transactions."];
            }
        } elseif ($_POST['action'] === 'cancel_selected' && isset($_POST['cancel_ids']) && is_array($_POST['cancel_ids'])) {
            $ids = array_map('intval', $_POST['cancel_ids']);
            if (!empty($ids)) {
                $ids_csv = implode(',', $ids);
                $update = mysqli_query($connection_server, "UPDATE sas_bulk_queue_items SET status='done', response_desc='Cancelled by admin', processed_at=NOW() WHERE vendor_id='$vendor_id' AND batch_number='$batch' AND id IN ($ids_csv) AND status IN ('pending', 'processing')");
                if ($update) {
                    $_SESSION['batch_action_msg'] = ["status" => "success", "text" => "Selected pending transactions have been cancelled by admin."];
                } else {
                    $_SESSION['batch_action_msg'] = ["status" => "danger", "text" => "Failed to cancel selected transactions."];
                }
            }
        } elseif ($_POST['action'] === 'cancel_legacy_tx' && !empty($_POST['legacy_ref'])) {
            $legacy_ref = mysqli_real_escape_string($connection_server, $_POST['legacy_ref']);
            $check_tx = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='$vendor_id' AND batch_number='$batch' AND reference='$legacy_ref' AND status='2'");
            if ($check_tx && mysqli_num_rows($check_tx) == 1) {
                $tx_row = mysqli_fetch_assoc($check_tx);
                $amount_to_refund = (float)$tx_row["discounted_amount"];
                $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                
                $charge = chargeOtherUser($tx_row["username"], "credit", $tx_row["product_unique_id"], "Reversed", $reference_2, "", $tx_row["amount"], $amount_to_refund, "Refund for manually cancelled transaction Ref: '".$tx_row["reference"]."'", $tx_row["mode"], $_SERVER["HTTP_HOST"] ?? "WEB", "1");
                
                if ($charge === "success") {
                    mysqli_query($connection_server, "UPDATE sas_transactions SET status='0', description='Cancelled by admin' WHERE reference='$legacy_ref'");
                    $_SESSION['batch_action_msg'] = ["status" => "success", "text" => "Transaction successfully cancelled and refunded."];
                } else {
                    $_SESSION['batch_action_msg'] = ["status" => "danger", "text" => "Failed to refund user. Transaction was not cancelled."];
                }
            } else {
                $_SESSION['batch_action_msg'] = ["status" => "danger", "text" => "Pending transaction not found."];
            }
        }
        header("Location: BatchDetails.php?batch=" . urlencode($batch));
        exit();
    }

    $batch_progress = bc_get_bulk_batch_progress($connection_server, $get_logged_admin_details["id"], null, $batch);
?>
<!DOCTYPE html>
<head>
    <title>Batch Details | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <?php if ($batch_progress["status"] !== "completed"): ?>
    <meta http-equiv="refresh" content="10">
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>BATCH DETAILS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item"><a href="BatchTransactions.php">Batch Transactions</a></li>
          <li class="breadcrumb-item active"><?php echo $batch; ?></li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <div class="col-12">
        <?php
            if (isset($_SESSION['batch_action_msg'])) {
                $msg = $_SESSION['batch_action_msg'];
                unset($_SESSION['batch_action_msg']);
                echo '<div class="alert alert-' . $msg['status'] . ' alert-dismissible fade show" role="alert">' . htmlspecialchars($msg['text']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
            }

            $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' AND batch_number='$batch' ORDER BY date DESC");

            $tx_references = array();
            if ($get_user_transaction_details) {
                while ($tx_row = mysqli_fetch_assoc($get_user_transaction_details)) {
                    $tx_references[$tx_row['reference']] = $tx_row;
                }
            }
            $uncharged_queue_items = array();
            $has_pending = false;
            $get_queue_items = mysqli_query($connection_server, "SELECT * FROM sas_bulk_queue_items WHERE vendor_id='".$get_logged_admin_details["id"]."' AND batch_number='$batch' ORDER BY id DESC");
            if ($get_queue_items) {
                while ($qi_row = mysqli_fetch_assoc($get_queue_items)) {
                    if (empty($qi_row['reference']) || !isset($tx_references[$qi_row['reference']])) {
                        $uncharged_queue_items[] = $qi_row;
                        if (in_array($qi_row['status'], ['pending', 'processing'])) {
                            $has_pending = true;
                        }
                    }
                }
            }
        ?>
        <div class="card info-card px-5 py-5">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="card-title mb-0">Transactions in Batch: <?php echo $batch; ?></h5>
                <a href="BatchTransactions.php" class="btn btn-secondary btn-sm">Back to Batches</a>
            </div>

            <?php
                $bp_status = $batch_progress["status"];
                $bp_total = max(1, $batch_progress["total"]);
                $bp_done_pct = round((($batch_progress["successful"] + $batch_progress["failed"]) / $bp_total) * 100);
                $bp_badge_class = $bp_status === "completed" ? "bg-success" : ($bp_status === "processing" ? "bg-warning text-dark" : "bg-secondary");
            ?>
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="badge <?php echo $bp_badge_class; ?> text-uppercase"><?php echo htmlspecialchars($bp_status); ?></span>
                    <small class="text-muted"><?php echo $batch_progress["successful"]; ?> successful &middot; <?php echo $batch_progress["failed"]; ?> failed &middot; <?php echo $batch_progress["pending"]; ?> pending of <?php echo $batch_progress["total"]; ?></small>
                </div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar <?php echo $bp_status === 'completed' ? 'bg-success' : 'bg-primary progress-bar-striped progress-bar-animated'; ?>" role="progressbar" style="width: <?php echo $bp_done_pct; ?>%"></div>
                </div>
                <?php if (!empty($batch_progress["ai_diagnosis"])): ?>
                    <div class="alert alert-warning mt-3 mb-0"><strong>AI Diagnosis:</strong> <?php echo htmlspecialchars($batch_progress["ai_diagnosis"]); ?></div>
                <?php endif; ?>
            </div>

            <?php if ($has_pending): ?>
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-danger btn-sm" id="cancelSelectedBtn" disabled onclick="submitCancellation('cancel_selected')">Cancel Selected</button>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="submitCancellation('cancel_all')">Cancel Entire Batch</button>
            </div>
            <?php endif; ?>

            <form method="POST" id="batchActionForm" action="">
            <input type="hidden" name="action" id="formAction" value="">
            <input type="hidden" name="legacy_ref" id="formLegacyRef" value="">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <?php if ($has_pending): ?>
                                <th style="width: 40px;"><input type="checkbox" id="selectAllPending" class="form-check-input"></th>
                                <?php endif; ?>
                                <th>Recipient</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Description</th>
                                <th>Date/Time</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if (empty($tx_references) && empty($uncharged_queue_items)) {
                                $colspan = $has_pending ? 7 : 6;
                                echo '<tr><td colspan="'.$colspan.'" class="text-center py-4">No transactions found in this batch.</td></tr>';
                            } else {
                                foreach ($tx_references as $row) {
                                    $status_class = ($row['status'] == 1 ? 'text-success' : ($row['status'] == 2 ? 'text-warning' : 'text-danger'));
                                    $status_text = tranStatus($row['status']);
                                    $legacy_action = '<button type="button" class="btn btn-primary btn-sm" onclick="showTransactionDetails(\''.$row['reference'].'\')">Details</button>';
                                    if ($row['status'] == 2) {
                                        $legacy_action .= ' <button type="button" class="btn btn-danger btn-sm ms-1" onclick="cancelLegacyItem(\''.$row['reference'].'\')">Cancel</button>';
                                    }
                                    echo '<tr>';
                                    if ($has_pending) {
                                        echo '<td></td>';
                                    }
                                    echo '
                                        <td><strong>'.$row['product_unique_id'].'</strong><br><small class="text-muted">'.$row['reference'].'</small></td>
                                        <td>₦'.number_format($row['discounted_amount'], 2).'</td>
                                        <td class="'.$status_class.' fw-bold">'.$status_text.'</td>
                                        <td><small>'.$row['description'].'</small></td>
                                        <td>'.date('M d, Y H:i:s', strtotime($row['date'])).'</td>
                                        <td>'.$legacy_action.'</td>
                                    </tr>';
                                }
                                foreach ($uncharged_queue_items as $qi_row) {
                                    $is_item_pending = in_array($qi_row['status'], ['pending', 'processing']);
                                    if ($qi_row['status'] === 'done') {
                                        $qi_status_html = '<span class="text-danger fw-bold">Failed</span>';
                                        $qi_desc = htmlspecialchars($qi_row['response_desc'] ?? 'Not processed');
                                    } else {
                                        $qi_status_html = '<span class="text-warning fw-bold">'.ucfirst($qi_row['status']).'</span>';
                                        $qi_desc = 'Waiting to be processed';
                                        $qi_action = '<button type="button" class="btn btn-danger btn-sm" onclick="cancelSingleItem('.$qi_row['id'].')">Cancel</button>';
                                    }
                                    echo '<tr>';
                                    if ($has_pending) {
                                        if ($is_item_pending) {
                                            echo '<td><input type="checkbox" name="cancel_ids[]" value="'.$qi_row['id'].'" class="form-check-input pending-checkbox"></td>';
                                        } else {
                                            echo '<td></td>';
                                        }
                                    }
                                    echo '
                                        <td><strong>'.htmlspecialchars($qi_row['phone_number']).'</strong></td>
                                        <td>&mdash;</td>
                                        <td>'.$qi_status_html.'</td>
                                        <td><small class="text-muted">'.$qi_desc.'</small></td>
                                        <td>'.($qi_row['processed_at'] ? date('M d, Y H:i:s', strtotime($qi_row['processed_at'])) : '&mdash;').'</td>
                                        <td>'.(isset($qi_action) ? $qi_action : '&mdash;').'</td>
                                    </tr>';
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
      </div>
    </section>
    <?php include("../func/bc-admin-footer.php"); ?>

    <?php if ($has_pending): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('selectAllPending');
            const checkboxes = document.querySelectorAll('.pending-checkbox');
            const cancelBtn = document.getElementById('cancelSelectedBtn');

            function updateButtonState() {
                const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
                cancelBtn.disabled = !anyChecked;
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(cb => {
                        cb.checked = selectAll.checked;
                    });
                    updateButtonState();
                });
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    if (!cb.checked && selectAll) {
                        selectAll.checked = false;
                    }
                    updateButtonState();
                });
            });
        });

        function submitCancellation(action) {
            const confirmMsg = action === 'cancel_all' 
                ? 'Are you sure you want to cancel the entire batch? This cannot be undone.' 
                : 'Are you sure you want to cancel the selected pending transactions?';
                
            if (confirm(confirmMsg)) {
                document.getElementById('formAction').value = action;
                document.getElementById('batchActionForm').submit();
            }
        }

        function cancelSingleItem(id) {
            if (confirm('Are you sure you want to cancel this pending transaction?')) {
                // We reuse the cancel_selected logic but strictly for this one ID
                document.querySelectorAll('.pending-checkbox').forEach(cb => cb.checked = false);
                const checkbox = document.querySelector('input[value="' + id + '"].pending-checkbox');
                if (checkbox) checkbox.checked = true;
                
                document.getElementById('formAction').value = 'cancel_selected';
                document.getElementById('batchActionForm').submit();
            }
        }

        function cancelLegacyItem(ref) {
            if (confirm('Are you sure you want to cancel this pending transaction? Your wallet will be refunded.')) {
                document.getElementById('formAction').value = 'cancel_legacy_tx';
                document.getElementById('formLegacyRef').value = ref;
                document.getElementById('batchActionForm').submit();
            }
        }
    </script>
    <?php endif; ?>
</body>
</html>