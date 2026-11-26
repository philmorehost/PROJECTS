<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");
if (isset($_GET['mark_success']) || isset($_GET['requery'])) {
    http_response_code(405); exit('Financial state changes cannot be requested by GET. Use the authenticated reconciliation workflow.');
}


    if (empty($_SESSION["bc_admin_transactions_csrf"])) {
        $_SESSION["bc_admin_transactions_csrf"] = bin2hex(random_bytes(24));
    }

    // §29: emailed step-up code sender for transaction approvals, cancellations, and requery.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_stepup_code'])) {
        if (!hash_equals((string)$_SESSION['bc_admin_transactions_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        }
        $stepup_ctx_map = array(
            'wallet_funding_approve' => 'Wallet Funding Approval',
            'wallet_funding_cancel'  => 'Wallet Funding Cancellation',
            'transaction_requery'    => 'Transaction Requery',
        );
        $req_ctx = trim((string)($_POST['stepup_context'] ?? 'wallet_funding_approve'));
        if (!isset($stepup_ctx_map[$req_ctx])) {
            $req_ctx = 'wallet_funding_approve';
        }
        $send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp((int)$get_logged_admin_details['id'], (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], $req_ctx, $stepup_ctx_map[$req_ctx])
            : 'Verification is unavailable.';
        $_SESSION['product_purchase_response'] = $send_err === ''
            ? 'Verification code sent to your email for ' . $stepup_ctx_map[$req_ctx] . '. Enter it with your Security PIN.'
            : "Error: $send_err";
        $redir_qs = !empty($_POST['review_requery']) ? ('?review_requery=' . urlencode((string)$_POST['review_requery'])) : '';
        header('Location: /bc-admin/Transactions.php' . $redir_qs);
        exit();
    }

    // Manual reconciliation has the same financial step-up as other admin actions.
    // Background workers use their own CLI/cron-secret identity, never this UI.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['requery'])) {
        if (!hash_equals((string)$_SESSION['bc_admin_transactions_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403); exit('Invalid security token.');
        }
        $err = function_exists('bc_stepup_admin_verify') ? bc_stepup_admin_verify($get_logged_admin_details, $_POST, 'transaction_requery') : 'Verification unavailable.';
        if ($err !== '') {
            $_SESSION['product_purchase_response'] = $err;
        } else {
            $ref = mysqli_real_escape_string($connection_server, (string)$_POST['requery']);
            $vid = (int)$get_logged_admin_details['id'];
            $q = mysqli_query($connection_server, "SELECT u.* FROM sas_transactions t JOIN sas_users u ON u.vendor_id=t.vendor_id AND u.username=t.username WHERE t.vendor_id='$vid' AND t.reference='$ref' LIMIT 1");
            $get_logged_user_details = $q ? mysqli_fetch_assoc($q) : null;
            if ($get_logged_user_details) {
                $purchase_method = 'WEB';
                $is_admin_requery = true;
                include __DIR__ . '/../web/func/requery-transaction.php';
                $result = json_decode($json_response_encode ?? '{}', true);
                $_SESSION['product_purchase_response'] = $result['desc'] ?? 'No confirmed provider update.';
            } else $_SESSION['product_purchase_response'] = 'Transaction not found.';
        }
        header('Location: /bc-admin/Transactions.php'); exit;
    }

    // Handle approve / cancel of pending wallet-funding transactions in sas_transactions
    // and the DGV7.0 manual reversal / restore of successful VTU transactions.
    if (isset($_POST["order-ref"]) && isset($_POST["order-status"])) {
        // §29: wallet-funding approvals and VTU reversals move money — POST,
        // CSRF token and admin PIN + second-factor step-up are mandatory.
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $_tx_stepup_ctx = trim(strip_tags((string)($_POST["order-status"] ?? ''))) === 'cancel' ? 'wallet_funding_cancel' : 'wallet_funding_approve';
        $sec29_tx_err = function_exists('bc_stepup_admin_verify')
            ? bc_stepup_admin_verify($get_logged_admin_details, $_POST, $_tx_stepup_ctx)
            : 'Verification is unavailable.';
        if ($sec29_tx_err !== '') {
            $_SESSION["product_purchase_response"] = $sec29_tx_err;
            header("Location: /bc-admin/Transactions.php");
            exit();
        }
        if (function_exists('bc_sec_audit')) bc_sec_audit((int)$get_logged_admin_details['id'], 'admin', (string)$get_logged_admin_details['email'], 'transaction_admin_action', trim(strip_tags((string)($_POST["order-ref"] ?? ''))), 'action=' . trim(strip_tags((string)($_POST["order-status"] ?? ''))));

        $admin_vid = (int)$get_logged_admin_details["id"];
        $t_ref    = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-ref"])));
        $t_status = trim(strip_tags($_POST["order-status"]));

        if (in_array($t_status, array("approve", "cancel", "mark-success", "reverse", "restore"))) {
            $select_tx = mysqli_query($connection_server,
                "SELECT * FROM sas_transactions
                 WHERE vendor_id='$admin_vid'
                   AND reference='$t_ref'
                 LIMIT 1"
            );

            if ($select_tx && mysqli_num_rows($select_tx) == 1) {
                $tx_row = mysqli_fetch_array($select_tx);
                if (function_exists('bc_ensure_wallet_transactional_schema')) {
                    bc_ensure_wallet_transactional_schema($connection_server);
                }
                $GLOBALS['vendor_id'] = $admin_vid;
                if (function_exists('resolveVendorID')) resolveVendorID(true);

                if ($t_status === "mark-success" && $tx_row["status"] == '3') {
                    mysqli_query($connection_server,
                        "UPDATE sas_transactions SET status='1'
                         WHERE vendor_id='$admin_vid'
                           AND reference='$t_ref'
                           AND status='3'
                         LIMIT 1"
                    );
                    if (mysqli_affected_rows($connection_server) !== 1) {
                        $_SESSION["product_purchase_response"] = "Transaction is no longer failed and cannot be marked successful.";
                    } else {
                        $debit_ref = 'TXMS_' . substr(hash('sha256', $admin_vid . ':' . $t_ref), 0, 20);
                        $debit = chargeOtherUser(
                            $tx_row["username"], "debit",
                            $tx_row["product_unique_id"], "Reversed Refund",
                            $debit_ref,
                            "mark_success:" . $t_ref,
                            $tx_row["amount"], $tx_row["discounted_amount"],
                            "Reversed refund for Ref: $t_ref",
                            "WEB", $_SERVER["HTTP_HOST"] ?? "WEB", 1
                        );
                        if ($debit === "success") {
                            $_SESSION["product_purchase_response"] = "Transaction marked as successful and user billed appropriately.";
                        } else {
                            mysqli_query($connection_server,
                                "UPDATE sas_transactions SET status='3'
                                 WHERE vendor_id='$admin_vid'
                                   AND reference='$t_ref'
                                   AND status='1'
                                 LIMIT 1"
                            );
                            $tx_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : "Failed to bill user wallet. Transaction status not changed.";
                            $_SESSION["product_purchase_response"] = $tx_fail_reason;
                        }
                    }
                } else if ($t_status === "reverse" || $t_status === "restore") {
                    // DGV7.0 manual reversal of a successful VTU transaction.
                    //   reverse  -> refund the customer and flag the original row reversed=1
                    //   restore -> undo a reversal (admin's mistake): re-debit the refund and
                    //              flag the original row reversed=0 ("mark successful" again)
                    $is_wallet_funding = in_array($tx_row['product_unique_id'], ['wallet_funding', 'manual_funding'])
                        || stripos($tx_row['type_alternative'] ?? '', 'Wallet Funding') !== false
                        || stripos($tx_row['type_alternative'] ?? '', 'Wallet Credit') !== false
                        || stripos($tx_row['type_alternative'] ?? '', 'Refund') !== false
                        || stripos($tx_row['type_alternative'] ?? '', 'Credit') !== false
                        || stripos($tx_row['type_alternative'] ?? '', 'Received') !== false;

                    if ($is_wallet_funding) {
                        $_SESSION["product_purchase_response"] = "Only successful VTU service transactions can be reversed.";
                    } elseif ($t_status === "reverse" && (int)$tx_row["status"] === 1 && (int)$tx_row["reversed"] !== 1) {
                        mysqli_query($connection_server,
                            "UPDATE sas_transactions SET reversed=1 WHERE vendor_id='$admin_vid' AND reference='$t_ref' AND status='1' AND reversed=0 LIMIT 1"
                        );
                        if (mysqli_affected_rows($connection_server) !== 1) {
                            $_SESSION["product_purchase_response"] = "This transaction has already been reversed.";
                        } else {
                            if (function_exists('bc_ensure_wallet_transactional_schema')) {
                                bc_ensure_wallet_transactional_schema($connection_server);
                            }
                            $GLOBALS['vendor_id'] = $admin_vid;
                            if (function_exists('resolveVendorID')) resolveVendorID(true);
                            $rev_ref = 'TXREV_' . substr(hash('sha256', $admin_vid . ':' . $t_ref), 0, 20);
                            $refund = chargeOtherUser(
                                $tx_row["username"], "credit",
                                $tx_row["product_unique_id"], "Reversed Refund",
                                $rev_ref,
                                "reverse_tx:" . $t_ref,
                                $tx_row["amount"], $tx_row["discounted_amount"],
                                "Transaction reversed by admin. Refund for Ref: $t_ref",
                                "WEB", $_SERVER["HTTP_HOST"] ?? "WEB", 1
                            );
                            if ($refund === "success") {
                                mysqli_query($connection_server,
                                    "DELETE FROM sas_dashboard_cache WHERE vendor_id='$admin_vid' AND cache_key='admin_stats'"
                                );
                                $_SESSION["product_purchase_response"] = ucwords($tx_row["username"] . " refunded ₦" . number_format((float)$tx_row["discounted_amount"], 2) . " — transaction reversed");
                            } else {
                                mysqli_query($connection_server,
                                    "UPDATE sas_transactions SET reversed=0 WHERE vendor_id='$admin_vid' AND reference='$t_ref' AND reversed=1 LIMIT 1"
                                );
                                $tx_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : "Failed to reverse transaction. Please try again.";
                                $_SESSION["product_purchase_response"] = $tx_fail_reason;
                            }
                        }
                    } elseif ($t_status === "restore" && (int)$tx_row["reversed"] === 1) {
                        mysqli_query($connection_server,
                            "UPDATE sas_transactions SET reversed=0 WHERE vendor_id='$admin_vid' AND reference='$t_ref' AND reversed=1 LIMIT 1"
                        );
                        if (mysqli_affected_rows($connection_server) !== 1) {
                            $_SESSION["product_purchase_response"] = "This transaction is no longer marked reversed.";
                        } else {
                            if (function_exists('bc_ensure_wallet_transactional_schema')) {
                                bc_ensure_wallet_transactional_schema($connection_server);
                            }
                            $GLOBALS['vendor_id'] = $admin_vid;
                            if (function_exists('resolveVendorID')) resolveVendorID(true);
                            $rest_ref = 'TXRST_' . substr(hash('sha256', $admin_vid . ':' . $t_ref), 0, 20);
                            $re_debit = chargeOtherUser(
                                $tx_row["username"], "debit",
                                $tx_row["product_unique_id"], "Restored Transaction",
                                $rest_ref,
                                "restore_tx:" . $t_ref,
                                $tx_row["amount"], $tx_row["discounted_amount"],
                                "Reversal undone by admin. Re-billed for Ref: $t_ref",
                                "WEB", $_SERVER["HTTP_HOST"] ?? "WEB", 1
                            );
                            if ($re_debit === "success") {
                                mysqli_query($connection_server,
                                    "DELETE FROM sas_dashboard_cache WHERE vendor_id='$admin_vid' AND cache_key='admin_stats'"
                                );
                                $_SESSION["product_purchase_response"] = ucwords($tx_row["username"] . " re-billed ₦" . number_format((float)$tx_row["discounted_amount"], 2) . " — transaction marked successful");
                            } else {
                                mysqli_query($connection_server,
                                    "UPDATE sas_transactions SET reversed=1 WHERE vendor_id='$admin_vid' AND reference='$t_ref' AND reversed=0 LIMIT 1"
                                );
                                $tx_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : "Failed to restore transaction. Please try again.";
                                $_SESSION["product_purchase_response"] = $tx_fail_reason;
                            }
                        }
                    } else {
                        $_SESSION["product_purchase_response"] = "This transaction cannot be reversed or restored in its current state.";
                    }
                } else if ($tx_row["status"] == '2' && (in_array($tx_row['product_unique_id'], ['wallet_funding', 'manual_funding']) || stripos($tx_row['type_alternative'], 'Wallet Funding') !== false || stripos($tx_row['type_alternative'], 'Wallet Credit') !== false)) {
                    if ($t_status === "approve") {
                        mysqli_query($connection_server,
                            "UPDATE sas_transactions SET status='1'
                             WHERE vendor_id='$admin_vid'
                               AND reference='$t_ref'
                               AND status='2'
                             LIMIT 1"
                        );
                        if (mysqli_affected_rows($connection_server) !== 1) {
                            $_SESSION["product_purchase_response"] = "Transaction is no longer pending and cannot be approved again.";
                        } else {
                            $tx_reference_2 = 'TXAPP_' . substr(hash('sha256', $admin_vid . ':' . $t_ref), 0, 20);
                            $credit = chargeOtherUser(
                                $tx_row["username"], "credit",
                                "wallet_credit", "Wallet Credit",
                                $tx_reference_2,
                                "manual_funding_tx:" . $t_ref,
                                $tx_row["amount"], $tx_row["discounted_amount"],
                                "Account credited by admin (approved payment notification)",
                                "WEB", $_SERVER["HTTP_HOST"] ?? "WEB", 1
                            );
                            if ($credit === "success") {
                                $_SESSION["product_purchase_response"] = ucwords($tx_row["username"] . " credited with ₦" . number_format((float)$tx_row["discounted_amount"], 2) . " successfully");
                            } else {
                                mysqli_query($connection_server,
                                    "UPDATE sas_transactions SET status='2'
                                     WHERE vendor_id='$admin_vid'
                                       AND reference='$t_ref'
                                       AND status='1'
                                     LIMIT 1"
                                );
                                $tx_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : "Failed to credit user wallet. Please try again.";
                                $_SESSION["product_purchase_response"] = $tx_fail_reason;
                            }
                        }
                    } else if ($t_status === "cancel") {
                        mysqli_query($connection_server,
                            "UPDATE sas_transactions SET status='3'
                             WHERE vendor_id='$admin_vid'
                               AND reference='$t_ref'
                               AND status='2'
                             LIMIT 1"
                        );
                        $_SESSION["product_purchase_response"] = mysqli_affected_rows($connection_server) === 1
                            ? "Payment notification cancelled."
                            : "Transaction not found or already processed.";
                    }
                } else {
                    $_SESSION["product_purchase_response"] = "Transaction not found or already processed.";
                }
            } else {
                $_SESSION["product_purchase_response"] = "Transaction not found or already processed.";
            }
        }
        header("Location: /bc-admin/Transactions.php");
        exit();
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Users Transaction | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "TRANSACTIONS", "crumb" => "Home / Transactions")); ?><!-- End Page Title -->

    <?php if (isset($_GET['review_requery'])): ?>
    <div class="card card-body mb-3">
      <h2>Confirm provider reconciliation</h2>
      <p>This checks the provider and may update the transaction or refund a confirmed cancellation. It does not buy the service again.</p>
      <form method="post" action="Transactions.php" class="mb-2">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['bc_admin_transactions_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="stepup_context" value="transaction_requery">
        <input type="hidden" name="review_requery" value="<?php echo htmlspecialchars((string)$_GET['review_requery'], ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" name="send_stepup_code" value="1" class="btn btn-outline-primary btn-sm rounded-pill">Email me a requery code</button>
      </form>
      <form method="post" action="Transactions.php" class="d-flex flex-wrap gap-2 align-items-end">
        <input type="hidden" name="requery" value="<?php echo htmlspecialchars((string)$_GET['review_requery'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['bc_admin_transactions_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <label>Security PIN <input type="password" name="pin" inputmode="numeric" required autocomplete="off"></label>
        <label>Authenticator / verification code <input name="otp_code" inputmode="numeric" required autocomplete="one-time-code"></label>
        <button type="submit" class="btn btn-primary">Verify and requery</button>
      </form>
    </div>
    <?php endif; ?>
    <section class="section dashboard">
      <div class="row">
        <div class="col-12">
            <?php
                $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
                $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
                $limit = 50;
                $offset = ($page_num - 1) * $limit;

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
                            <p class="text-muted small mb-2">Monitor all user activities and payment statuses</p>
                            <form method="post" action="Transactions.php" class="d-inline-flex flex-wrap gap-2 align-items-center m-0">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['bc_admin_transactions_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                                <select name="stepup_context" class="form-select form-select-sm" style="width:auto;">
                                    <option value="wallet_funding_approve">Wallet Funding Approval</option>
                                    <option value="wallet_funding_cancel">Wallet Funding Cancellation</option>
                                </select>
                                <button type="submit" name="send_stepup_code" value="1" class="btn btn-outline-primary btn-sm rounded-pill">Email me an approval code</button>
                            </form>
                            <span class="small text-muted d-block mt-1">Approve/Cancel funding actions require your Security PIN + authenticator/email code.</span>
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
                                    <input name="start_date" type="date" value="<?php echo htmlspecialchars($_GET['start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="form-control" />
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold">To</label>
                                    <input name="end_date" type="date" value="<?php echo htmlspecialchars($_GET['end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="form-control" />
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
    
    


<?php bc_adm_foot(array("area" => "admin")); ?>
