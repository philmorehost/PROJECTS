<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-spadmin-config.php");

    function sec30_verify_spadmin_pin_only($spadmin_row, $input, $context) {
        global $connection_server;
        if (!is_array($spadmin_row) || empty($spadmin_row['id'])) return 'Step-up verification is unavailable.';
        $admin_id = (int)$spadmin_row['id'];
        $identity = (string)($spadmin_row['email'] ?? ('spadmin#' . $admin_id));
        if (empty($spadmin_row['security_pin'])) {
            return 'Set a Security PIN in Account Settings before managing payment orders.';
        }
        if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('spadmin_pin_fail_' . $context, (string)$admin_id, 5, 300)) {
            if (function_exists('bc_log_security_event')) bc_log_security_event('SPADMIN_PIN_LOCKED', $context, $identity, 'Too many wrong PIN attempts');
            return 'Too many wrong PIN attempts. Wait a few minutes and try again.';
        }
        $pin = (string)($input['pin'] ?? ($input['security_pin'] ?? ''));
        if (!function_exists('verifyUserPIN') || !verifyUserPIN($pin, $spadmin_row)) {
            if ($connection_server instanceof mysqli && function_exists('bc_ensure_security_tables')) {
                bc_ensure_security_tables();
                $action = mysqli_real_escape_string($connection_server, 'spadmin_pin_fail_' . $context);
                $key = mysqli_real_escape_string($connection_server, (string)$admin_id);
                mysqli_query($connection_server, "INSERT INTO sas_rate_limits (action, rate_key, attempted_at) VALUES ('$action', '$key', NOW())");
            }
            if (function_exists('bc_log_security_event')) bc_log_security_event('SPADMIN_PIN_FAILED', $context, $identity, 'Wrong PIN');
            return 'Invalid Security PIN';
        }
        return '';
    }
    
    // §30: approving a vendor payment order credits the vendor's wallet — it
    // now takes POST + CSRF + the super-admin step-up (PIN + authenticator/
    // email code), claims pending status atomically before crediting, and is audited.
    if(isset($_POST["send_stepup_code"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp(0, (string)$get_logged_spadmin_details['email'], (string)$get_logged_spadmin_details['email'], 'vendor_payment_approve', 'Vendor Payment Approval')
            : 'Verification is unavailable.';
        $_SESSION["product_purchase_response"] = $send_err === '' ? "Verification code sent to your email. Enter it in the approval dialog." : "Error: $send_err";
        header("Location: /bc-spadmin/PaymentOrders.php");
        exit();
    }

    if (isset($_POST["bulk_delete_pending"])) {
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $sp_redirect = "/bc-spadmin/PaymentOrders.php";
        $ret_sq = trim(strip_tags((string)($_POST['return_searchq'] ?? '')));
        if ($ret_sq !== '') {
            $sp_redirect .= '?searchq=' . urlencode($ret_sq);
        }
        $delete_scope = trim(strip_tags((string)($_POST['delete_scope'] ?? 'selected')));
        if (!in_array($delete_scope, array('selected', 'all'), true)) {
            $_SESSION["product_purchase_response"] = "Invalid bulk delete scope.";
            header("Location: " . $sp_redirect);
            exit();
        }
        $sec30_pin_err = sec30_verify_spadmin_pin_only($get_logged_spadmin_details, $_POST, 'vendor_payment_delete');
        if ($sec30_pin_err !== '') {
            $_SESSION["product_purchase_response"] = $sec30_pin_err;
            header("Location: " . $sp_redirect);
            exit();
        }
        $deleted_count = 0;
        if ($delete_scope === 'selected') {
            $raw_refs = is_array($_POST['selected_refs'] ?? null) ? $_POST['selected_refs'] : array();
            $refs = array_values(array_unique(array_filter(array_map(function ($r) {
                return is_string($r) ? trim(strip_tags($r)) : '';
            }, $raw_refs), function ($r) { return $r !== ''; })));
            if (empty($refs)) {
                $_SESSION["product_purchase_response"] = "Select at least one pending payment order to delete.";
                header("Location: " . $sp_redirect);
                exit();
            }
            foreach (array_chunk($refs, 200) as $chunk) {
                $in_sql = implode(',', array_map(function ($r) use ($connection_server) {
                    return "'" . mysqli_real_escape_string($connection_server, $r) . "'";
                }, $chunk));
                if (mysqli_query($connection_server, "DELETE FROM sas_super_admin_submitted_payments WHERE status='2' AND reference IN ($in_sql)")) {
                    $deleted_count += max(0, (int)mysqli_affected_rows($connection_server));
                }
            }
        } else {
            $filter_sq = trim(strip_tags((string)($_POST['filter_searchq'] ?? '')));
            $bulk_search_sql = "";
            if ($filter_sq !== '' && !empty($_POST['apply_search_filter'])) {
                $f_esc = mysqli_real_escape_string($connection_server, $filter_sq);
                $bulk_search_sql = " AND (reference LIKE '%$f_esc%' OR description LIKE '%$f_esc%' OR amount LIKE '%$f_esc%')";
            }
            if (mysqli_query($connection_server, "DELETE FROM sas_super_admin_submitted_payments WHERE status='2' $bulk_search_sql")) {
                $deleted_count += max(0, (int)mysqli_affected_rows($connection_server));
            }
        }
        if ($deleted_count > 0 && function_exists('bc_sec_audit')) {
            bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details["email"], 'vendor_payment_orders_bulk_deleted', $delete_scope, "Deleted $deleted_count pending vendor payment order(s)");
        }
        $_SESSION["product_purchase_response"] = $deleted_count > 0
            ? "Deleted $deleted_count pending vendor payment order(s)."
            : "No matching pending payment orders were found to delete.";
        header("Location: " . $sp_redirect);
        exit();
    }

    if(isset($_POST["order-ref"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-status"] ?? '')));
        $reference = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-ref"] ?? '')));
        $statusArray = array(1, 2);
        if(is_numeric($status)){
            if(in_array((int)$status, $statusArray, true)){
                $select_payment_order = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_submitted_payments WHERE reference='".$reference."' LIMIT 1");
                if($select_payment_order && mysqli_num_rows($select_payment_order) == 1){
                    $get_payment_order = mysqli_fetch_array($select_payment_order);
					$get_vendors_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".(int)$get_payment_order["vendor_id"]."' LIMIT 1"));
					if(!isset($get_vendors_details["id"])){
						$json_response_encode = json_encode(array("desc" => "Vendor Not Exists"), true);
					} elseif((string)$get_payment_order["status"] !== "2"){
						$json_response_encode = json_encode(array("desc" => "This order is no longer pending and cannot be processed again."),true);
					} elseif((int)$status === 1) {
						// Rejection moves no money: PIN-checked only (same throttle, matches modal guidance).
						$sec30_err2 = sec30_verify_spadmin_pin_only($get_logged_spadmin_details, $_POST, 'vendor_payment_reject');
						if ($sec30_err2 !== '') {
							$json_response_encode = json_encode(array("desc" => $sec30_err2),true);
						} else {
							mysqli_query($connection_server, "UPDATE sas_super_admin_submitted_payments SET status='3' WHERE reference='".$reference."' AND status='2' LIMIT 1");
							if (mysqli_affected_rows($connection_server) === 1) {
								if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details["email"], 'vendor_payment_rejected', (string)$reference, 'Rejected vendor payment order');
								$json_response_array = array("desc" => ucwords($get_vendors_details["email"]." Order with N".toDecimal($get_payment_order["discounted_amount"],2)." rejected successfully"));
								$json_response_encode = json_encode($json_response_array,true);
							} else {
								$json_response_encode = json_encode(array("desc" => "This order is no longer pending and cannot be processed again."),true);
							}
						}
					} elseif((int)$status === 2) {
						$sec30_err = function_exists('bc_stepup_spadmin_verify') ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $_POST, 'vendor_payment_approve') : 'Verification is unavailable.';
						if ($sec30_err !== '') {
							$json_response_encode = json_encode(array("desc" => $sec30_err),true);
						} else {
							@set_time_limit(120);
							if (function_exists('bc_ensure_wallet_transactional_schema')) {
								bc_ensure_wallet_transactional_schema($connection_server);
							}
							// Atomic claim: exactly one concurrent request may transition 2 (pending) -> 1 (approved).
							mysqli_query($connection_server, "UPDATE sas_super_admin_submitted_payments SET status='1' WHERE reference='".$reference."' AND status='2' LIMIT 1");
							if (mysqli_affected_rows($connection_server) !== 1) {
								$json_response_encode = json_encode(array("desc" => "This order is no longer pending and cannot be processed again."),true);
							} else {
								$GLOBALS['bc_vpo_pending_claim'] = array(
									'reference' => $reference,
									'done' => false,
								);
								register_shutdown_function(function () use ($connection_server) {
									$claim = $GLOBALS['bc_vpo_pending_claim'] ?? null;
									if (is_array($claim) && empty($claim['done']) && $connection_server instanceof mysqli) {
										@mysqli_query($connection_server, "UPDATE sas_super_admin_submitted_payments SET status='2' WHERE reference='" . $claim['reference'] . "' AND status='1' LIMIT 1");
									}
								});
								$user = $get_vendors_details["email"];
								$type = "credit";
								$amount = $get_payment_order["amount"];
								$discounted_amount = $get_payment_order["discounted_amount"];
								// Deterministic ledger reference prevents duplicate credits across retries.
								$reference_2 = 'VPO_' . substr(hash('sha256', (string)$get_payment_order["vendor_id"] . ':' . $reference), 0, 20);
								$description = ucwords("account ".$type."ed by admin ( payment order )");
								$credit_other_user = chargeOtherVendor($get_payment_order["vendor_id"], $type, $user, ucwords("wallet ".$type), $reference_2, $amount, $discounted_amount, $description, $_SERVER["HTTP_HOST"] ?? "WEB", "1");
								if($credit_other_user === "success"){
									$GLOBALS['bc_vpo_pending_claim']['done'] = true;
									try {
										if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details["email"], 'vendor_payment_approved', (string)$reference, 'Credited NGN ' . number_format((float)$get_payment_order["discounted_amount"], 2) . ' to ' . ($get_vendors_details["email"] ?? 'vendor'));
									} catch (Throwable $ignored) {}
									$json_response_array = array("desc" => ucwords($get_vendors_details["email"]." Credited with N".toDecimal($get_payment_order["discounted_amount"],2)." successfully"));
									$json_response_encode = json_encode($json_response_array,true);
								} else {
									// Revert claim back to pending if wallet mutation did not succeed.
									mysqli_query($connection_server, "UPDATE sas_super_admin_submitted_payments SET status='2' WHERE reference='".$reference."' AND status='1' LIMIT 1");
									$GLOBALS['bc_vpo_pending_claim']['done'] = true;
									$vpo_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : ("Cannot Proceed Processing Transaction (" . (string)$credit_other_user . "). Verify the vendor account is active and wallet tables are ready.");
									$json_response_array = array("desc" => $vpo_fail_reason);
									$json_response_encode = json_encode($json_response_array,true);
								}
							}
						}
					}
                }else{
                    if($select_payment_order && mysqli_num_rows($select_payment_order) > 1){
                        //Duplicated Orders
                        $json_response_array = array("desc" => "Duplicated Orders");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        //Order Not Exists
                        $json_response_array = array("desc" => "Order Not Exists");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }else{
                //Invalid Status Code
                $json_response_array = array("desc" => "Invalid Status Code");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Non-numeric string
            $json_response_array = array("desc" => "Non-numeric string");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: /bc-spadmin/PaymentOrders.php");
        exit();
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Payment Orders", array("area" => "spadmin"));
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
<link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
<link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "PAYMENT ORDERS", "crumb" => "Home / Payment Orders")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">

        <?php
            $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
            $limit = 10;
            $offset = ($page_num - 1) * $limit;
            $offset_statement = " OFFSET $offset";
            
            $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
            $search_statement = "";
            $search_parameter = "";

            if(!empty($searchq)){
                $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                $search_statement = " AND (reference LIKE '%$search_esc%' OR description LIKE '%$search_esc%' OR amount LIKE '%$search_esc%')";
                $search_parameter = "searchq=".urlencode($searchq)."&";
            }
            
            $get_user_pending_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_submitted_payments WHERE status='2' $search_statement ORDER BY date DESC LIMIT $limit $offset_statement");
            $get_user_successful_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_submitted_payments WHERE status='1' $search_statement ORDER BY date DESC LIMIT $limit $offset_statement");
            $get_user_failed_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_submitted_payments WHERE status='3' $search_statement ORDER BY date DESC LIMIT $limit $offset_statement");
            $sp_pending_count_q = mysqli_query($connection_server, "SELECT COUNT(*) c FROM sas_super_admin_submitted_payments WHERE status='2'");
            $sp_pending_total = ($sp_pending_count_q && ($sp_pending_r = mysqli_fetch_assoc($sp_pending_count_q))) ? (int)($sp_pending_r['c'] ?? 0) : 0;
        ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0">
                <div class="row align-items-center g-3">
                    <div class="col-md-6">
                        <h5 class="fw-bold mb-0 text-primary">Super Admin Payment Orders</h5>
                        <p class="text-muted small mb-0">Approve or reject manual funding requests from vendors</p>
                    </div>
                    <div class="col-md-6">
                        <form method="get" action="PaymentOrders.php" class="d-flex gap-2 justify-content-md-end">
                            <input name="searchq" type="text" value="<?php echo htmlspecialchars($searchq); ?>" placeholder="Ref, Email, Amount..." class="form-control rounded-pill px-3" style="max-width: 250px;" />
                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Filter</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="card-body p-0">
                <div class="nav nav-tabs nav-tabs-bordered mb-3 px-4 pt-3" id="orderTabs" role="tablist">
                    <button class="nav-link active small fw-bold" data-bs-toggle="tab" data-bs-target="#tab-pending">Pending Requests</button>
                    <button class="nav-link small fw-bold" data-bs-toggle="tab" data-bs-target="#tab-successful">Successful</button>
                    <button class="nav-link small fw-bold" data-bs-toggle="tab" data-bs-target="#tab-rejected">Rejected</button>
                </div>

                <div class="tab-content p-0">
                    <!-- Pending Tab -->
                    <div class="tab-pane fade show active" id="tab-pending">
                        <?php if ($sp_pending_total > 0): ?>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 bg-light border-top border-bottom px-4 py-3">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge bg-secondary-subtle text-dark border px-3 py-2 rounded-pill" id="spPoSelectedBadge">0 selected</span>
                                <span class="small text-muted">Select pending orders or wipe all pending requests (approved &amp; rejected orders are never affected).</span>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" id="spPoDeleteSelectedBtn" disabled data-bs-toggle="modal" data-bs-target="#bulkDeleteSpOrdersModal" data-mode="selected">
                                    <i class="bi bi-trash3 me-1"></i>Delete Selected
                                </button>
                                <button type="button" class="btn btn-danger btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#bulkDeleteSpOrdersModal" data-mode="all">
                                    <i class="bi bi-trash3-fill me-1"></i>Delete All Pending (<?php echo $sp_pending_total; ?>)
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light"><tr class="small text-uppercase text-muted"><th class="ps-4" style="width:38px;"><input type="checkbox" class="form-check-input" id="spPoSelectAll" title="Select all visible pending orders"></th><th>Ref / Vendor</th><th>Details</th><th>Amount</th><th>Status</th><th class="text-end pe-4">Actions</th></tr></thead>
                                <tbody>
                                    <?php if($get_user_pending_transaction_details && mysqli_num_rows($get_user_pending_transaction_details) > 0):
                                        while($row = mysqli_fetch_assoc($get_user_pending_transaction_details)):
                                            $v_q = mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='".$row["vendor_id"]."' LIMIT 1");
                                            $v_email = ($v_row = mysqli_fetch_assoc($v_q)) ? $v_row['email'] : "Unknown Vendor";
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <input type="checkbox" class="form-check-input sp-po-check" value="<?php echo htmlspecialchars($row['reference'], ENT_QUOTES, 'UTF-8'); ?>">
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark small"><?php echo $row["reference"]; ?></div>
                                            <div class="small text-muted"><?php echo $v_email; ?></div>
                                        </td>
                                        <td class="small text-muted">Mode: <?php echo $row["mode"]; ?><br>Date: <?php echo formDate($row["date"]); ?></td>
                                        <td>
                                            <div class="fw-bold">₦<?php echo number_format($row["discounted_amount"], 2); ?></div>
                                            <div class="small text-muted text-decoration-line-through">₦<?php echo number_format($row["amount"], 2); ?></div>
                                        </td>
                                        <td><?php echo tranStatus($row["status"]); ?></td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-success px-3" data-bs-toggle="modal" data-bs-target="#approveOrderModal" data-ref="<?php echo htmlspecialchars($row['reference'], ENT_QUOTES, 'UTF-8'); ?>" data-status="2" data-vendor="<?php echo htmlspecialchars($v_email, ENT_QUOTES, 'UTF-8'); ?>">Approve</button>
                                                <button type="button" class="btn btn-danger px-3" data-bs-toggle="modal" data-bs-target="#approveOrderModal" data-ref="<?php echo htmlspecialchars($row['reference'], ENT_QUOTES, 'UTF-8'); ?>" data-status="1" data-vendor="<?php echo htmlspecialchars($v_email, ENT_QUOTES, 'UTF-8'); ?>">Reject</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">No pending requests found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Successful Tab -->
                    <div class="tab-pane fade" id="tab-successful">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light"><tr class="small text-uppercase text-muted"><th class="ps-4">Ref / Vendor</th><th>Details</th><th>Amount</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php if($get_user_successful_transaction_details && mysqli_num_rows($get_user_successful_transaction_details) > 0):
                                        while($row = mysqli_fetch_assoc($get_user_successful_transaction_details)):
                                            $v_q = mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='".$row["vendor_id"]."' LIMIT 1");
                                            $v_email = ($v_row = mysqli_fetch_assoc($v_q)) ? $v_row['email'] : "Unknown Vendor";
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark small"><?php echo $row["reference"]; ?></div>
                                            <div class="small text-muted"><?php echo $v_email; ?></div>
                                        </td>
                                        <td class="small text-muted">Mode: <?php echo $row["mode"]; ?><br>Date: <?php echo formDate($row["date"]); ?></td>
                                        <td><div class="fw-bold text-success">₦<?php echo number_format($row["discounted_amount"], 2); ?></div></td>
                                        <td><?php echo tranStatus($row["status"]); ?></td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">No successful orders found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Rejected Tab -->
                    <div class="tab-pane fade" id="tab-rejected">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light"><tr class="small text-uppercase text-muted"><th class="ps-4">Ref / Vendor</th><th>Details</th><th>Amount</th><th>Status</th><th class="text-end pe-4">Action</th></tr></thead>
                                <tbody>
                                    <?php if($get_user_failed_transaction_details && mysqli_num_rows($get_user_failed_transaction_details) > 0):
                                        while($row = mysqli_fetch_assoc($get_user_failed_transaction_details)):
                                            $v_q = mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='".$row["vendor_id"]."' LIMIT 1");
                                            $v_email = ($v_row = mysqli_fetch_assoc($v_q)) ? $v_row['email'] : "Unknown Vendor";
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark small"><?php echo $row["reference"]; ?></div>
                                            <div class="small text-muted"><?php echo $v_email; ?></div>
                                        </td>
                                        <td class="small text-muted">Mode: <?php echo $row["mode"]; ?><br>Date: <?php echo formDate($row["date"]); ?></td>
                                        <td><div class="fw-bold text-danger">₦<?php echo number_format($row["discounted_amount"], 2); ?></div></td>
                                        <td><?php echo tranStatus($row["status"]); ?></td>
                                        <td class="text-end pe-4"><span class="text-muted small" title="Rejected orders are final and cannot be re-approved">—</span></td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="text-center py-5 text-muted">No rejected orders found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-footer bg-white py-4 border-0">
                <div class="d-flex justify-content-center gap-2">
                    <?php if($page_num > 1): ?>
                    <a href="PaymentOrders.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-4 rounded-pill">Prev</a>
                    <?php endif; ?>
                    <a href="PaymentOrders.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num + 1); ?>" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">Next</a>
                </div>
            </div>
        </div>
      </div>
    </section>
		
	
	


<!-- §30: step-up approval dialog (PIN + authenticator/email code) -->
<div class="modal fade" id="approveOrderModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content border-0 shadow">
      <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
      <input type="hidden" name="order-ref" id="aoRef" value="">
      <input type="hidden" name="order-status" id="aoStatus" value="">
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold" id="aoTitle">Confirm Approval</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted" id="aoDesc"></p>
        <div class="mb-3">
          <label class="form-label small fw-bold text-muted">SECURITY PIN</label>
          <input name="pin" type="password" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" class="form-control text-center fw-bold" placeholder="****" required>
        </div>
        <div class="mb-1">
          <label class="form-label small fw-bold text-muted">AUTHENTICATOR / EMAIL CODE</label>
          <div class="input-group">
            <input name="otp_code" type="text" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" class="form-control text-center fw-bold" placeholder="6-digit code">
            <input type="hidden" name="totp_code" value="">
            <button type="submit" name="send_stepup_code" value="1" formnovalidate class="btn btn-outline-primary fw-bold">Email code</button>
          </div>
          <div class="form-text">Approvals need the code as well as your PIN. Rejections need the PIN only — press Email code first if you have no authenticator.</div>
        </div>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="aoSubmit">Confirm</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('approveOrderModal').addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    if (!btn) return;
    var status = btn.getAttribute('data-status');
    var ref = btn.getAttribute('data-ref');
    var vendor = btn.getAttribute('data-vendor') || '';
    document.getElementById('aoRef').value = ref;
    document.getElementById('aoStatus').value = status;
    var approve = status === '2';
    document.getElementById('aoTitle').textContent = approve ? 'Confirm Approval' : 'Confirm Rejection';
    document.getElementById('aoDesc').textContent = (approve ? 'Credit ' : 'Reject the order from ') + vendor + ' (Ref: ' + ref + ').';
    var submit = document.getElementById('aoSubmit');
    submit.textContent = approve ? 'Approve & Credit' : 'Reject Order';
    submit.className = 'btn rounded-pill px-4 fw-bold ' + (approve ? 'btn-success' : 'btn-danger');
});
</script>

<?php if ($sp_pending_total > 0): ?>
<div class="modal fade" id="bulkDeleteSpOrdersModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="PaymentOrders.php" class="modal-content border-0 shadow">
      <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
      <input type="hidden" name="bulk_delete_pending" value="1">
      <input type="hidden" name="return_searchq" value="<?php echo htmlspecialchars($searchq, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="filter_searchq" value="<?php echo htmlspecialchars($searchq, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="delete_scope" id="spPoBulkDeleteScope" value="selected">
      <div id="spPoBulkSelectedInputs"></div>
      <div class="modal-header border-0">
        <h5 class="modal-title fw-bold text-danger" id="spPoBulkTitle">Delete Pending Payment Orders</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3" id="spPoBulkDesc">Permanently delete pending payment orders. Approved and rejected orders are never affected.</p>
        <?php if ($searchq !== ''): ?>
        <div class="form-check mb-3 d-none" id="spPoFilterWrap">
          <input class="form-check-input" type="checkbox" name="apply_search_filter" value="1" id="spPoApplySearchFilter" checked>
          <label class="form-check-label small" for="spPoApplySearchFilter">
            Only delete pending orders matching filter "<strong><?php echo htmlspecialchars($searchq, ENT_QUOTES, 'UTF-8'); ?></strong>"
          </label>
        </div>
        <?php endif; ?>
        <div class="mb-1">
          <label class="form-label small fw-bold text-muted">SECURITY PIN</label>
          <input name="pin" type="password" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" class="form-control text-center fw-bold" placeholder="****" required autocomplete="off">
        </div>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Delete Permanently</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
    var selectAll = document.getElementById('spPoSelectAll');
    var badge = document.getElementById('spPoSelectedBadge');
    var delBtn = document.getElementById('spPoDeleteSelectedBtn');
    var modalEl = document.getElementById('bulkDeleteSpOrdersModal');
    if (!badge || !delBtn || !modalEl) return;

    function updateSpCount() {
        var rows = document.querySelectorAll('.sp-po-check');
        var checked = document.querySelectorAll('.sp-po-check:checked');
        badge.textContent = checked.length + ' selected';
        delBtn.disabled = checked.length === 0;
        if (selectAll) {
            selectAll.checked = rows.length > 0 && checked.length === rows.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < rows.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.sp-po-check').forEach(function (cb) {
                cb.checked = selectAll.checked;
            });
            updateSpCount();
        });
    }
    document.querySelectorAll('.sp-po-check').forEach(function (cb) {
        cb.addEventListener('change', updateSpCount);
    });

    modalEl.addEventListener('show.bs.modal', function (ev) {
        var btn = ev.relatedTarget;
        var mode = btn ? btn.getAttribute('data-mode') : 'selected';
        var selectedBox = document.getElementById('spPoBulkSelectedInputs');
        var scopeInput = document.getElementById('spPoBulkDeleteScope');
        var title = document.getElementById('spPoBulkTitle');
        var desc = document.getElementById('spPoBulkDesc');
        var filterWrap = document.getElementById('spPoFilterWrap');
        selectedBox.innerHTML = '';

        if (mode === 'all') {
            scopeInput.value = 'all';
            if (filterWrap) filterWrap.classList.remove('d-none');
            title.textContent = 'Delete All Pending Vendor Orders';
            desc.textContent = 'This will permanently delete all matching pending vendor payment orders. Approved and rejected orders will never be touched.';
        } else {
            scopeInput.value = 'selected';
            if (filterWrap) filterWrap.classList.add('d-none');
            var checked = document.querySelectorAll('.sp-po-check:checked');
            checked.forEach(function (cb) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'selected_refs[]';
                inp.value = cb.value;
                selectedBox.appendChild(inp);
            });
            title.textContent = 'Delete ' + checked.length + ' Selected Pending Order(s)';
            desc.textContent = 'Permanently delete ' + checked.length + ' selected pending vendor payment order(s). Approved and rejected orders are never affected.';
        }
    });
})();
</script>
<?php endif; ?>

<?php bc_adm_foot(array("area" => "spadmin")); ?>
