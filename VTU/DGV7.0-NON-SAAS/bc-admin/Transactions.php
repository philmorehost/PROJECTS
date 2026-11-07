<?php session_start();
    include("../func/bc-admin-config.php");

    // Handle approve / cancel of pending wallet-funding transactions in sas_transactions
    if (isset($_GET["order-ref"]) && isset($_GET["order-status"])) {
        $t_ref    = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["order-ref"])));
        $t_status = trim(strip_tags($_GET["order-status"]));

        if (in_array($t_status, array("approve", "cancel", "mark-success"))) {
            $select_tx = mysqli_query($connection_server,
                "SELECT * FROM sas_transactions
                 WHERE vendor_id='" . $get_logged_admin_details["id"] . "'
                   AND reference='$t_ref'
                 LIMIT 1"
            );

            if (mysqli_num_rows($select_tx) == 1) {
                $tx_row = mysqli_fetch_array($select_tx);
                
                if ($t_status === "mark-success" && $tx_row["status"] == '3') {
                    $debit = chargeOtherUser(
                        $tx_row["username"], "debit",
                        $tx_row["product_unique_id"], "Reversed Refund",
                        substr(str_shuffle("12345678901234567890"), 0, 15),
                        $t_ref,
                        $tx_row["amount"], $tx_row["discounted_amount"],
                        "Reversed refund for Ref: $t_ref",
                        "WEB", $_SERVER["HTTP_HOST"], 1
                    );
                    mysqli_query($connection_server,
                        "UPDATE sas_transactions SET status='1'
                         WHERE vendor_id='" . $get_logged_admin_details["id"] . "'
                           AND reference='$t_ref'"
                    );
                    $_SESSION["product_purchase_response"] = "Transaction marked as successful and user billed appropriately.";
                } else if ($tx_row["status"] == '2' && (in_array($tx_row['product_unique_id'], ['wallet_funding', 'manual_funding']) || stripos($tx_row['type_alternative'], 'Wallet Funding') !== false || stripos($tx_row['type_alternative'], 'Wallet Credit') !== false)) {
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
                } else if ($t_status === "cancel") {
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
                        include("../web/func/requery-transaction.php");
                        $json_response_decode = json_decode($json_response_encode,true);
                        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
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
            ?>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-0 text-primary">Transaction History</h5>
                            <p class="text-muted small mb-0">Monitor all user activities and payment statuses</p>
                        </div>
                        <div class="col-12 mt-4">
                            <form method="get" action="Transactions.php" class="row g-3 align-items-end">
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
    
</body>
</html>