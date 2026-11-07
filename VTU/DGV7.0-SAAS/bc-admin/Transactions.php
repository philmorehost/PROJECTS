<?php session_start();
    include("../func/bc-admin-config.php");

    if (empty($_SESSION["bc_admin_transactions_csrf"])) {
        $_SESSION["bc_admin_transactions_csrf"] = bin2hex(random_bytes(24));
    }

    // Cancel old provider transactions in bulk. The customer is refunded only after the row is
    // atomically claimed, and a failed refund puts the transaction back into pending status.
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["bulk_action"] ?? "") === "cancel_old_pending") {
        $csrf = (string)($_POST["csrf_token"] ?? "");
        if (!hash_equals((string)$_SESSION["bc_admin_transactions_csrf"], $csrf)) {
            $_SESSION["product_purchase_response"] = "Invalid security token. Please refresh and try again.";
        } else {
            $age_days = filter_var($_POST["pending_age_days"] ?? 7, FILTER_VALIDATE_INT);
            $age_days = ($age_days === false) ? 7 : max(1, min(3650, (int)$age_days));
            $vendor_id = (int)$get_logged_admin_details["id"];
            $cancelled = 0;
            $failed = 0;
            $cutoff = date("Y-m-d H:i:s", time() - ($age_days * 86400));
            $cutoff_sql = mysqli_real_escape_string($connection_server, $cutoff);
            $old_pending = mysqli_query($connection_server,
                "SELECT * FROM sas_transactions
                 WHERE vendor_id='$vendor_id' AND status='2' AND api_id > 0 AND date < '$cutoff_sql'
                   AND product_unique_id NOT IN ('wallet_funding', 'manual_funding')
                   AND type_alternative NOT LIKE '%Wallet Funding%'
                   AND type_alternative NOT LIKE '%Wallet Credit%'
                 ORDER BY date ASC"
            );

            if ($old_pending) {
                while ($tx_row = mysqli_fetch_assoc($old_pending)) {
                    $tx_id = (int)$tx_row["id"];
                    $tx_ref = (string)$tx_row["reference"];
                    $ref_sql = mysqli_real_escape_string($connection_server, $tx_ref);
                    $old_description = mysqli_real_escape_string($connection_server, (string)$tx_row["description"]);
                    $cancel_description = mysqli_real_escape_string($connection_server, "Cancelled by admin after $age_days days pending");

                    $claimed = mysqli_query($connection_server,
                        "UPDATE sas_transactions SET status='3', description='$cancel_description'
                         WHERE id='$tx_id' AND vendor_id='$vendor_id' AND status='2'"
                    );
                    if (!$claimed || mysqli_affected_rows($connection_server) !== 1) {
                        continue;
                    }

                    $_SESSION['tmp_vendor_override'] = $vendor_id;
                    $refund_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
                    $refund = chargeOtherUser(
                        $tx_row["username"], "credit", $tx_row["product_unique_id"], "Refund",
                        $refund_ref, "", $tx_row["amount"], $tx_row["discounted_amount"],
                        "Refund for admin-cancelled old pending Ref:<i>'$tx_ref'</i>",
                        $tx_row["mode"], $_SERVER["HTTP_HOST"] ?? "WEB", "1"
                    );
                    unset($_SESSION['tmp_vendor_override']);

                    if ($refund === "success") {
                        $cancelled++;
                    } else {
                        // Do not leave a customer debited when the compensating credit failed.
                        mysqli_query($connection_server,
                            "UPDATE sas_transactions SET status='2', description='$old_description'
                             WHERE id='$tx_id' AND vendor_id='$vendor_id' AND status='3'"
                        );
                        error_log("Bulk cancel refund failed for vendor=$vendor_id transaction=$tx_ref");
                        $failed++;
                    }
                }
            }
            $_SESSION["product_purchase_response"] = "$cancelled old pending transaction(s) cancelled and refunded; $failed refund(s) failed and were left pending.";
        }
        header("Location: /bc-admin/Transactions.php");
        exit();
    }

    // Permanently remove this vendor's transaction-history rows older than the admin-selected age.
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["bulk_action"] ?? "") === "delete_old_history") {
        $csrf = (string)($_POST["csrf_token"] ?? "");
        if (!hash_equals((string)$_SESSION["bc_admin_transactions_csrf"], $csrf)) {
            $_SESSION["product_purchase_response"] = "Invalid security token. Please refresh and try again.";
        } else {
            $age_days = filter_var($_POST["history_age_days"] ?? 365, FILTER_VALIDATE_INT);
            $age_days = ($age_days === false) ? 365 : max(1, min(3650, (int)$age_days));
            $vendor_id = (int)$get_logged_admin_details["id"];
            $cutoff_sql = mysqli_real_escape_string($connection_server, date("Y-m-d H:i:s", time() - ($age_days * 86400)));
            $deleted = mysqli_query($connection_server, "DELETE FROM sas_transactions WHERE vendor_id='$vendor_id' AND date < '$cutoff_sql'");
            $count = $deleted ? mysqli_affected_rows($connection_server) : 0;
            if (!$deleted) error_log("Transaction history deletion failed for vendor=$vendor_id: " . mysqli_error($connection_server));
            $_SESSION["product_purchase_response"] = $deleted ? "$count transaction-history row(s) older than $age_days day(s) deleted permanently." : "Transaction-history deletion failed. No rows were deleted.";
        }
        header("Location: /bc-admin/Transactions.php");
        exit();
    }

    // Recover a delayed provider success. Pending rows were already debited at purchase time;
    // failed/refunded rows must be debited again before they can be marked successful.
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["bulk_action"] ?? "") === "recover_success") {
        $csrf = (string)($_POST["csrf_token"] ?? "");
        $ref = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["transaction_ref"] ?? "")));
        if (!hash_equals((string)$_SESSION["bc_admin_transactions_csrf"], $csrf)) {
            $_SESSION["product_purchase_response"] = "Invalid security token. Please refresh and try again.";
        } else {
            $vendor_id = (int)$get_logged_admin_details["id"];
            $tx_query = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='$vendor_id' AND reference='$ref' AND status IN ('2','3') AND api_id > 0 LIMIT 1");
            $tx_row = $tx_query ? mysqli_fetch_assoc($tx_query) : null;
            if (!$tx_row) {
                $_SESSION["product_purchase_response"] = "Pending/failed provider transaction not found or already completed.";
            } elseif ((string)$tx_row["status"] === "2") {
                $updated = mysqli_query($connection_server, "UPDATE sas_transactions SET status='1', description='Transaction Successful | manually confirmed delivered by admin' WHERE vendor_id='$vendor_id' AND reference='$ref' AND status='2'");
                $_SESSION["product_purchase_response"] = ($updated && mysqli_affected_rows($connection_server) === 1) ? "Transaction marked successful. The user was already billed when the purchase was created." : "Unable to update the pending transaction.";
            } else {
                $_SESSION['tmp_vendor_override'] = $vendor_id;
                $charge = chargeOtherUser($tx_row["username"], "debit", $tx_row["product_unique_id"], "Charge", substr(str_shuffle("12345678901234567890"), 0, 15), "", $tx_row["amount"], $tx_row["discounted_amount"], "Charge for restored successful Ref:<i>'$ref'</i>", $tx_row["mode"], $_SERVER["HTTP_HOST"] ?? "WEB", "1");
                unset($_SESSION['tmp_vendor_override']);
                if ($charge === "success") {
                    $updated = mysqli_query($connection_server, "UPDATE sas_transactions SET status='1', description='Transaction Successful | restored by admin after provider delivery' WHERE vendor_id='$vendor_id' AND reference='$ref' AND status='3'");
                    $_SESSION["product_purchase_response"] = ($updated && mysqli_affected_rows($connection_server) === 1) ? "Transaction restored to successful and the user was billed." : "The user was billed, but the transaction status update failed. Please verify reference $ref.";
                } else {
                    $_SESSION["product_purchase_response"] = "Unable to bill the user, so the failed transaction was not changed.";
                }
            }
        }
        header("Location: /bc-admin/Transactions.php");
        exit();
    }

    // Optional vendor-scoped fresh-start cleanup. This permanently removes every transaction row.
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["bulk_action"] ?? "") === "delete_all_history") {
        $csrf = (string)($_POST["csrf_token"] ?? "");
        $confirmation = trim((string)($_POST["delete_all_confirmation"] ?? ""));
        if (!hash_equals((string)$_SESSION["bc_admin_transactions_csrf"], $csrf)) {
            $_SESSION["product_purchase_response"] = "Invalid security token. Please refresh and try again.";
        } elseif ($confirmation !== "DELETE ALL TRANSACTIONS") {
            $_SESSION["product_purchase_response"] = "All transaction records were not deleted. Type DELETE ALL TRANSACTIONS exactly to confirm.";
        } else {
            $vendor_id = (int)$get_logged_admin_details["id"];
            $deleted = mysqli_query($connection_server, "DELETE FROM sas_transactions WHERE vendor_id='$vendor_id'");
            $count = $deleted ? mysqli_affected_rows($connection_server) : 0;
            if (!$deleted) error_log("All transaction history deletion failed for vendor=$vendor_id: " . mysqli_error($connection_server));
            $_SESSION["product_purchase_response"] = $deleted ? "$count transaction record(s) deleted. This vendor now has a fresh transaction history." : "Transaction deletion failed. No rows were deleted.";
        }
        header("Location: /bc-admin/Transactions.php");
        exit();
    }

    // Handle approve / cancel of pending wallet-funding transactions in sas_transactions
    if (isset($_GET["order-ref"]) && isset($_GET["order-status"])) {
        $t_ref    = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["order-ref"])));
        $t_status = trim(strip_tags($_GET["order-status"]));

        if (in_array($t_status, array("approve", "cancel"))) {
            $select_tx = mysqli_query($connection_server,
                "SELECT * FROM sas_transactions
                 WHERE vendor_id='" . $get_logged_admin_details["id"] . "'
                   AND reference='$t_ref'
                   AND status='2'
                   AND (product_unique_id='wallet_funding' OR product_unique_id='manual_funding' OR type_alternative LIKE '%Wallet Funding%' OR type_alternative LIKE '%Wallet Credit%')
                 LIMIT 1"
            );

            if (mysqli_num_rows($select_tx) == 1) {
                $tx_row = mysqli_fetch_array($select_tx);
                if ($t_status === "approve") {
                    $credit = chargeOtherUser(
                        $tx_row["username"], "credit",
                        "wallet_credit", "Wallet Credit",
                        substr(str_shuffle("12345678901234567890"), 0, 15),
                        $t_ref,
                        $tx_row["amount"], $tx_row["discounted_amount"],
                        "Account credited by admin (approved payment notification)",
                        "WEB", $_SERVER["HTTP_HOST"], 1
                    );
                    if ($credit === "success") {
                        mysqli_query($connection_server,
                            "UPDATE sas_transactions SET status='1'
                             WHERE vendor_id='" . $get_logged_admin_details["id"] . "'
                               AND reference='$t_ref'"
                        );
                        $_SESSION["product_purchase_response"] = ucwords($tx_row["username"] . " credited with ₦" . number_format($tx_row["discounted_amount"], 2) . " successfully");
                    } else {
                        $_SESSION["product_purchase_response"] = "Failed to credit user wallet. Please try again.";
                    }
                } else {
                    mysqli_query($connection_server,
                        "UPDATE sas_transactions SET status='3'
                         WHERE vendor_id='" . $get_logged_admin_details["id"] . "'
                           AND reference='$t_ref'"
                    );
                    $_SESSION["product_purchase_response"] = "Payment notification cancelled.";
                }
            } else {
                $_SESSION["product_purchase_response"] = "Transaction not found or already processed.";
            }
        }
        header("Location: /bc-admin/Transactions.php");
        exit();
    }
?>
<!DOCTYPE html>
<head>
    <title>Users Transaction | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    
      <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>TRANSACTIONS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Transactions</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">
            <?php
                $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
                $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
                $limit = 50;
                $offset = ($page_num - 1) * $limit;

                if(isset($_GET["requery"]) && !empty($_GET["requery"])){
                    $ref = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["requery"])));
                    $select_user_requeried_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='$ref'");
                    if(mysqli_num_rows($select_user_requeried_transaction_details) == 1){
                        $get_selected_user_requeried_transaction_details = mysqli_fetch_array($select_user_requeried_transaction_details);
                        $get_logged_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && username='".$get_selected_user_requeried_transaction_details["username"]."' LIMIT 1");
                        if(mysqli_num_rows($get_logged_user_query) == 1){
                            $get_logged_user_details = mysqli_fetch_array($get_logged_user_query);
                        }
                        $purchase_method = "web";
                        $is_admin_requery = true;
                        include("../web/func/requery-transaction.php");
                        $json_response_decode = json_decode($json_response_encode,true);
                        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
                    }
                }

                if(isset($_GET["mark_success"]) && !empty($_GET["mark_success"])){
                    $ref = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["mark_success"])));
                    $select_tx = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='$ref' && status='3'");
                    if(mysqli_num_rows($select_tx) == 1){
                        $tx_row = mysqli_fetch_array($select_tx);
                        $amount_to_charge = $tx_row["discounted_amount"];
                        $t_username = $tx_row["username"];
                        $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                        
                        $charge = chargeOtherUser($t_username, "debit", $tx_row["product_unique_id"], "Charge", $reference_2, "", $tx_row["amount"], $amount_to_charge, "Charge for manually marked successful Ref:<i>'$ref'</i>", $tx_row["mode"], $_SERVER["HTTP_HOST"] ?? "WEB", "1");
                        
                        if ($charge === "success") {
                            mysqli_query($connection_server, "UPDATE sas_transactions SET status='1', description='Transaction Successful' WHERE reference='$ref'");
                            $_SESSION["product_purchase_response"] = "Transaction marked as successful and user wallet debited ₦" . number_format($amount_to_charge, 2);
                        } else {
                            $_SESSION["product_purchase_response"] = "Failed to debit user wallet. Transaction status not changed.";
                        }
                    } else {
                        $_SESSION["product_purchase_response"] = "Failed transaction not found.";
                    }
                }

                $search_statement = "";
                $search_parameter = "";
                if(!empty($searchq)){
                    $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                    $search_statement .= " AND (product_unique_id LIKE '%$search_esc%' OR reference LIKE '%$search_esc%' OR batch_number LIKE '%$search_esc%' OR type_alternative LIKE '%$search_esc%' OR description LIKE '%$search_esc%' OR username LIKE '%$search_esc%')";
                    $search_parameter .= "searchq=".urlencode($searchq)."&";
                }

                if (isset($_GET["category"]) && !empty($_GET["category"])) {
                    $cat = mysqli_real_escape_string($connection_server, $_GET["category"]);
                    if($cat == 'wallet-funding') $search_statement .= " AND type_alternative LIKE '%credit%'";
                    elseif($cat == 'wallet-refund') $search_statement .= " AND type_alternative LIKE '%refund%'";
                    else $search_statement .= " AND (type_alternative LIKE '%$cat%' OR api_type LIKE '%$cat%')";
                    $search_parameter .= "category=$cat&";
                }

                if (isset($_GET["start_date"]) && !empty($_GET["start_date"])) {
                    $sd = mysqli_real_escape_string($connection_server, $_GET["start_date"]);
                    $search_statement .= " AND DATE(date) >= '$sd'";
                    $search_parameter .= "start_date=$sd&";
                }

                if (isset($_GET["end_date"]) && !empty($_GET["end_date"])) {
                    $ed = mysqli_real_escape_string($connection_server, $_GET["end_date"]);
                    $search_statement .= " AND DATE(date) <= '$ed'";
                    $search_parameter .= "end_date=$ed&";
                }

                $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                $transaction_summary = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) AS total_count, SUM(status='1') AS successful_count, SUM(status='2') AS pending_count, SUM(status='3') AS failed_count FROM sas_transactions WHERE vendor_id='".(int)$get_logged_admin_details["id"]."'")) ?: array();
            ?>

            <div class="row g-3 mb-4 tx-summary-row">
                <div class="col-6 col-xl-3"><div class="tx-summary-card tx-total"><span class="tx-summary-icon"><i class="bi bi-receipt"></i></span><div><div class="tx-summary-label">All transactions</div><div class="tx-summary-value"><?php echo number_format((int)($transaction_summary['total_count'] ?? 0)); ?></div></div></div></div>
                <div class="col-6 col-xl-3"><div class="tx-summary-card tx-success"><span class="tx-summary-icon"><i class="bi bi-check-circle"></i></span><div><div class="tx-summary-label">Successful</div><div class="tx-summary-value"><?php echo number_format((int)($transaction_summary['successful_count'] ?? 0)); ?></div></div></div></div>
                <div class="col-6 col-xl-3"><div class="tx-summary-card tx-pending"><span class="tx-summary-icon"><i class="bi bi-hourglass-split"></i></span><div><div class="tx-summary-label">Pending attention</div><div class="tx-summary-value"><?php echo number_format((int)($transaction_summary['pending_count'] ?? 0)); ?></div></div></div></div>
                <div class="col-6 col-xl-3"><div class="tx-summary-card tx-failed"><span class="tx-summary-icon"><i class="bi bi-exclamation-circle"></i></span><div><div class="tx-summary-label">Failed / refunded</div><div class="tx-summary-value"><?php echo number_format((int)($transaction_summary['failed_count'] ?? 0)); ?></div></div></div></div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-list-check me-2"></i>Transaction History</h5>
                            <p class="text-muted small mb-0">Search, recover, and safely manage customer transactions</p>
                        </div>
                        <div class="col-12 mt-4">
                            <form method="get" action="Transactions.php" class="row g-3 align-items-end tx-filter-bar">
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold">Keywords</label>
                                    <input name="searchq" type="text" value="<?php echo $searchq; ?>" placeholder="User, Ref, Batch..." class="form-control" />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold">Category</label>
                                    <select name="category" class="form-select">
                                        <option value="">All Categories</option>
                                        <option value="airtime" <?php echo (($_GET['category'] ?? '') == 'airtime') ? 'selected' : ''; ?>>Airtime</option>
                                        <option value="data" <?php echo (($_GET['category'] ?? '') == 'data') ? 'selected' : ''; ?>>Data</option>
                                        <option value="cable" <?php echo (($_GET['category'] ?? '') == 'cable') ? 'selected' : ''; ?>>Cable TV</option>
                                        <option value="electric" <?php echo (($_GET['category'] ?? '') == 'electric') ? 'selected' : ''; ?>>Electricity</option>
                                        <option value="wallet-funding" <?php echo (($_GET['category'] ?? '') == 'wallet-funding') ? 'selected' : ''; ?>>Funding</option>
                                        <option value="transfer" <?php echo (($_GET['category'] ?? '') == 'transfer') ? 'selected' : ''; ?>>Transfers</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold">From</label>
                                    <input name="start_date" type="date" value="<?php echo $_GET['start_date'] ?? ''; ?>" class="form-control" />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold">To</label>
                                    <input name="end_date" type="date" value="<?php echo $_GET['end_date'] ?? ''; ?>" class="form-control" />
                                </div>
                                <div class="col-md-3 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                                    <a href="export-transactions.php?<?php echo $search_parameter; ?>" class="btn btn-success"><i class="bi bi-download"></i></a>
                                </div>
                            </form>
                        </div>
                        <div class="col-12 mt-3">
                            <details class="tx-tools-panel">
                                <summary><span><i class="bi bi-sliders2 me-2"></i>Transaction tools</span><small>Recovery, cleanup and fresh-start options</small></summary>
                                <div class="tx-tools-content">
                        <div class="col-12 mt-3">
                            <form method="post" action="Transactions.php" class="row g-2 align-items-end border rounded-3 p-3 tx-action-card tx-recovery-card">
                                <input type="hidden" name="bulk_action" value="cancel_old_pending" />
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["bc_admin_transactions_csrf"], ENT_QUOTES, "UTF-8"); ?>" />
                                <div class="col-md-7">
                                    <div class="small fw-bold text-danger">Old pending VTU transactions</div>
                                    <div class="small text-muted">Only provider transactions older than the selected number of days are included. Wallet funding is excluded.</div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold">Older than (days)</label>
                                    <input name="pending_age_days" type="number" min="1" max="3650" value="7" class="form-control" required />
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Cancel and refund all matching old pending VTU transactions?');">Cancel Old Pending</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-12 mt-3">
                            <form method="post" action="Transactions.php" class="row g-2 align-items-end border rounded-3 p-3 tx-action-card tx-cleanup-card">
                                <input type="hidden" name="bulk_action" value="delete_old_history" />
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["bc_admin_transactions_csrf"], ENT_QUOTES, "UTF-8"); ?>" />
                                <div class="col-md-7"><div class="small fw-bold text-danger">Delete old transaction history</div><div class="small text-muted">This permanently deletes this vendor's transaction rows older than the selected age.</div></div>
                                <div class="col-md-2"><label class="form-label small fw-bold">Older than (days)</label><input name="history_age_days" type="number" min="1" max="3650" value="365" class="form-control" required /></div>
                                <div class="col-md-3"><button type="submit" class="btn btn-outline-danger w-100" onclick="return confirm('Permanently delete all transaction history older than the selected number of days? This cannot be undone.');">Delete Old History</button></div>
                            </form>
                        </div>
                        <div class="col-12 mt-3">
                            <form method="post" action="Transactions.php" class="row g-2 align-items-end border border-danger rounded-3 p-3 bg-danger bg-opacity-10">
                                <input type="hidden" name="bulk_action" value="delete_all_history" />
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["bc_admin_transactions_csrf"], ENT_QUOTES, "UTF-8"); ?>" />
                                <div class="col-md-5"><div class="small fw-bold text-danger">Fresh-start option: delete all transaction records</div><div class="small text-danger">Permanently deletes every transaction record belonging to this vendor, including pending records.</div></div>
                                <div class="col-md-4"><label class="form-label small fw-bold">Type DELETE ALL TRANSACTIONS</label><input name="delete_all_confirmation" type="text" class="form-control" autocomplete="off" required /></div>
                                <div class="col-md-3"><button type="submit" class="btn btn-danger w-100" onclick="return confirm('This permanently deletes ALL transaction records for this vendor. Continue?');">Delete Everything</button></div>
                            </form>
                        </div>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php
                        $query_result = $get_user_transaction_details;
                        $is_admin = true;
                        $is_payment_order = true;
                        $inline_approve_page = "Transactions.php";
                        include("../func/history-table.php");
                        unset($is_payment_order, $inline_approve_page);
                    ?>
                </div>
                <div class="card-footer bg-white py-3 border-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">Showing page <?php echo $page_num; ?></span>
                        <div class="btn-group">
                            <?php if($page_num > 1): ?>
                            <a href="Transactions.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-3"><i class="bi bi-chevron-left me-1"></i>Prev</a>
                            <?php endif; ?>
                            <a href="Transactions.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num + 1); ?>" class="btn btn-outline-primary btn-sm px-3">Next<i class="bi bi-chevron-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>
    <?php include("../func/bc-admin-footer.php"); ?>
    <style>
      .tx-summary-card { display:flex; align-items:center; gap:14px; min-height:82px; padding:15px 18px; border-radius:16px; background:#fff; box-shadow:0 8px 24px rgba(31,58,112,.08); border-left:4px solid #3867f2; }
      .tx-summary-icon { display:grid; place-items:center; width:44px; height:44px; border-radius:13px; font-size:21px; background:#eef3ff; color:#3867f2; }
      .tx-summary-label { color:#7280a0; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
      .tx-summary-value { color:#102d68; font-size:25px; font-weight:800; line-height:1.2; }
      .tx-success { border-left-color:#198754; }.tx-success .tx-summary-icon { color:#198754; background:#eaf8f0; }
      .tx-pending { border-left-color:#e0a800; }.tx-pending .tx-summary-icon { color:#b98200; background:#fff8df; }
      .tx-failed { border-left-color:#dc3545; }.tx-failed .tx-summary-icon { color:#dc3545; background:#fff0f1; }
      .tx-filter-bar { padding:18px; border:1px solid #e7ebf3; border-radius:14px; background:#f8faff; }
      .tx-action-card { transition:transform .18s ease, box-shadow .18s ease; }.tx-action-card:hover { transform:translateY(-1px); box-shadow:0 5px 18px rgba(31,58,112,.08); }
      .tx-recovery-card { border-left:4px solid #dc3545 !important; background:#fff9f9 !important; }.tx-cleanup-card { border-left:4px solid #f0ad00 !important; background:#fffdf5 !important; }
      .tx-tools-panel { margin-top:4px; border:1px solid #e4e9f3; border-radius:14px; background:#fbfcff; overflow:hidden; }
      .tx-tools-panel summary { display:flex; align-items:center; justify-content:space-between; gap:12px; cursor:pointer; list-style:none; padding:13px 17px; color:#234cbd; font-size:13px; font-weight:800; }
      .tx-tools-panel summary::-webkit-details-marker { display:none; }.tx-tools-panel summary:after { content:'+'; font-size:20px; line-height:1; color:#3867f2; }.tx-tools-panel[open] summary:after { content:'−'; }
      .tx-tools-panel summary small { margin-left:auto; color:#8390aa; font-weight:500; }.tx-tools-content { padding:0 8px 8px; }
      @media (max-width: 767px) { .tx-summary-card { min-height:78px; padding:13px; }.tx-summary-value { font-size:21px; } }
    </style>
    
</body>
</html>
