<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");

    // §29: full step-up is mandatory for every money-crediting action.
    function sec29_require_stepup($admin, $ctx) {
        if (!function_exists('bc_stepup_admin_verify')) {
            return 'Verification is unavailable. Refresh and try again.';
        }
        return bc_stepup_admin_verify($admin, $_POST, $ctx);
    }
    function sec29_verify_admin_pin_only($vendor_row, $input, $context) {
        global $connection_server;
        if (!is_array($vendor_row) || empty($vendor_row['id'])) return 'Step-up verification is unavailable.';
        $vendor_id = (int)$vendor_row['id'];
        $identity = (string)($vendor_row['email'] ?? ('vendor#' . $vendor_id));
        if (empty($vendor_row['security_pin'])) {
            return 'Set a Security PIN in Account Settings before managing payment orders.';
        }
        if (function_exists('bc_is_rate_limited') && bc_is_rate_limited('admin_pin_fail_' . $context, (string)$vendor_id, 5, 300)) {
            if (function_exists('bc_log_security_event')) bc_log_security_event('ADMIN_PIN_LOCKED', $context, $identity, 'Too many wrong PIN attempts');
            return 'Too many wrong PIN attempts. Wait a few minutes and try again.';
        }
        $pin = (string)($input['pin'] ?? '');
        if (!function_exists('verifyUserPIN') || !verifyUserPIN($pin, $vendor_row)) {
            if ($connection_server instanceof mysqli && function_exists('bc_ensure_security_tables')) {
                bc_ensure_security_tables();
                $action = mysqli_real_escape_string($connection_server, 'admin_pin_fail_' . $context);
                $key = mysqli_real_escape_string($connection_server, (string)$vendor_id);
                mysqli_query($connection_server, "INSERT INTO sas_rate_limits (action, rate_key, attempted_at) VALUES ('$action', '$key', NOW())");
            }
            if (function_exists('bc_log_security_event')) bc_log_security_event('ADMIN_PIN_FAILED', $context, $identity, 'Wrong PIN');
            return 'Invalid Security PIN';
        }
        return '';
    }
    if (isset($_POST["order-ref"])) {
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    }

    $po_redirect_qs = array();
    $po_ref_qs = array();
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $ref_query = parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
        if (is_string($ref_query) && $ref_query !== '') parse_str($ref_query, $po_ref_qs);
    }
    $cand_tab = $_POST['return_tab'] ?? ($_GET['tab'] ?? ($po_ref_qs['tab'] ?? ''));
    if ($cand_tab !== '' && in_array($cand_tab, array('pending', 'approved', 'rejected'), true)) {
        $po_redirect_qs['tab'] = $cand_tab;
    }
    $cand_sq = $_POST['return_searchq'] ?? ($_GET['searchq'] ?? ($po_ref_qs['searchq'] ?? ''));
    if (is_string($cand_sq) && trim($cand_sq) !== '') {
        $po_redirect_qs['searchq'] = trim(strip_tags($cand_sq));
    }
    $po_redirect_url = '/bc-admin/PaymentOrders.php' . (!empty($po_redirect_qs) ? '?' . http_build_query($po_redirect_qs) : '');


    // App (mobile) payment requests: mobile-app manual bank deposits are stored in
    // sas_transactions with product_unique_id='manual_funding' (status 2 = pending),
    // not in sas_submitted_payments. Approve/reject them here too, using the string
    // actions approve/cancel so they never collide with the numeric statuses the
    // website payment-order handler below expects.
    if(isset($_POST["order-ref"]) && in_array(trim(strip_tags($_POST["order-status"] ?? '')), array("approve", "cancel"))) {
        $admin_vid = (int)$get_logged_admin_details["id"];
        $app_ref = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-ref"])));
        $app_action = trim(strip_tags($_POST["order-status"]));
        $select_app_order = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='$admin_vid' && reference='".$app_ref."' && product_unique_id='manual_funding' LIMIT 1");
        if ($select_app_order && mysqli_num_rows($select_app_order) == 1) {
            $get_app_order = mysqli_fetch_array($select_app_order);
            if ($app_action === "approve") {
                if ((string)$get_app_order["status"] === "2") {
                    $sec29_err = sec29_require_stepup($get_logged_admin_details, 'payment_order_approve');
                    if ($sec29_err !== '') {
                        $_SESSION["product_purchase_response"] = $sec29_err;
                        header("Location: " . $po_redirect_url);
                        exit();
                    }
                    // Atomic claim: exactly one concurrent request may move status 2 -> 1.
                    mysqli_query($connection_server, "UPDATE sas_transactions SET status='1' WHERE vendor_id='$admin_vid' AND reference='".$app_ref."' AND product_unique_id='manual_funding' AND status='2' LIMIT 1");
                    if (mysqli_affected_rows($connection_server) !== 1) {
                        $json_response_array = array("desc" => "This order is no longer pending and cannot be approved again.");
                    } else {
                        if (function_exists('bc_ensure_wallet_transactional_schema')) {
                            bc_ensure_wallet_transactional_schema($connection_server);
                        }
                        $GLOBALS['vendor_id'] = $admin_vid;
                        if (function_exists('resolveVendorID')) resolveVendorID(true);
                        $app_reference_2 = 'POAPP_' . substr(hash('sha256', $admin_vid . ':' . $app_ref), 0, 20);
                        $app_credit = chargeOtherUser($get_app_order["username"], "credit", "wallet_credit", "Wallet Credit", $app_reference_2, "manual_funding_app:" . $app_ref, $get_app_order["amount"], $get_app_order["discounted_amount"], "Account credited by admin (approved app payment notification)", "APP", $_SERVER["HTTP_HOST"] ?? "WEB", "1");
                        if ($app_credit === "success") {
                            if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'payment_order_approved', (string)$app_ref, 'Credited NGN ' . number_format((float)$get_app_order["discounted_amount"], 2) . ' to ' . $get_app_order["username"] . ' (app manual funding)');
                            $json_response_array = array("desc" => ucwords($get_app_order["username"]." credited with N".toDecimal($get_app_order["discounted_amount"], 2)." successfully"));
                        } else {
                            mysqli_query($connection_server, "UPDATE sas_transactions SET status='2' WHERE vendor_id='$admin_vid' AND reference='".$app_ref."' AND product_unique_id='manual_funding' AND status='1' LIMIT 1");
                            $po_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : ("Cannot Proceed Processing Transaction (" . (string)$app_credit . "). Verify user account is active and wallet tables are ready.");
                            $json_response_array = array("desc" => $po_fail_reason);
                        }
                    }
                } else {
                    $json_response_array = array("desc" => "This order is no longer pending and cannot be approved again.");
                }
            } else {
                if ((string)$get_app_order["status"] !== "2") {
                    $json_response_array = array("desc" => "This order is no longer pending and cannot be processed again.");
                } else {
                    $sec29_pin_err = sec29_verify_admin_pin_only($get_logged_admin_details, $_POST, 'payment_order_reject');
                    if ($sec29_pin_err !== '') {
                        $_SESSION["product_purchase_response"] = $sec29_pin_err;
                        header("Location: " . $po_redirect_url);
                        exit();
                    }
                    mysqli_query($connection_server, "UPDATE sas_transactions SET status='3' WHERE vendor_id='$admin_vid' AND reference='".$app_ref."' AND product_unique_id='manual_funding' AND status='2' LIMIT 1");
                    if (mysqli_affected_rows($connection_server) === 1) {
                        if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'payment_order_rejected', (string)$app_ref, 'Rejected app manual funding order');
                        $json_response_array = array("desc" => ucwords($get_app_order["username"]." Order with N".toDecimal($get_app_order["discounted_amount"], 2)." rejected successfully"));
                    } else {
                        $json_response_array = array("desc" => "This order is no longer pending and cannot be processed again.");
                    }
                }
            }
        } else {
            $json_response_array = array("desc" => "Order Not Exists");
        }
        $_SESSION["product_purchase_response"] = $json_response_array["desc"];
        header("Location: " . $po_redirect_url);
        exit();
    }

    // §29: emailed step-up code sender (shared by the approval forms).
    if (isset($_POST['send_stepup_code'])) {
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp((int)$get_logged_admin_details['id'], (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'payment_order_approve', 'Payment Approval')
            : 'Verification is unavailable.';
        $_SESSION["product_purchase_response"] = $send_err === '' ? "Verification code sent to your email. Enter it with your PIN in the action row." : "Error: $send_err";
        header("Location: " . $po_redirect_url);
        exit();
    }

    if(isset($_POST["order-ref"])){
        $admin_vid = (int)$get_logged_admin_details["id"];
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-status"] ?? '')));
        $reference = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-ref"] ?? '')));
        $statusArray = array(1, 2);
        if(is_numeric($status)){
            if(in_array((int)$status, $statusArray, true)){
                $select_payment_order = mysqli_query($connection_server, "SELECT * FROM sas_submitted_payments WHERE vendor_id='$admin_vid' && reference='".$reference."' LIMIT 1");
                if($select_payment_order && mysqli_num_rows($select_payment_order) == 1){
                    $get_payment_order = mysqli_fetch_array($select_payment_order);
                    if((int)$status == 1){
						if((string)$get_payment_order["status"] !== "2"){
							$json_response_array = array("desc" => "This order is no longer pending and cannot be processed again.");
							$json_response_encode = json_encode($json_response_array,true);
						} else {
							$sec29_pin_err = sec29_verify_admin_pin_only($get_logged_admin_details, $_POST, 'payment_order_reject');
							if ($sec29_pin_err !== '') {
								$json_response_encode = json_encode(array("desc" => $sec29_pin_err), true);
							} else {
								mysqli_query($connection_server, "UPDATE sas_submitted_payments SET status='3' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='2' LIMIT 1");
								if (mysqli_affected_rows($connection_server) === 1) {
									if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'payment_order_rejected', (string)$reference, 'Rejected web payment order');
									$json_response_array = array("desc" => ucwords($get_payment_order["username"]." Order with N".toDecimal($get_payment_order["discounted_amount"],2)." rejected successfully"));
									$json_response_encode = json_encode($json_response_array,true);
								} else {
									$json_response_encode = json_encode(array("desc" => "This order is no longer pending and cannot be processed again."), true);
								}
							}
						}
                    }

                    if((int)$status == 2){
						// §29: only a still-pending order may be approved, and only
						// with the admin PIN + second-factor step-up.
						if((string)$get_payment_order["status"] === "2"){
							$sec29_err = sec29_require_stepup($get_logged_admin_details, 'payment_order_approve');
							if ($sec29_err !== '') {
								$json_response_encode = json_encode(array("desc" => $sec29_err), true);
							}
							else
							{
								// Atomic claim: exactly one concurrent request may transition status 2 -> 1.
								mysqli_query($connection_server, "UPDATE sas_submitted_payments SET status='1' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='2' LIMIT 1");
								if (mysqli_affected_rows($connection_server) !== 1) {
									$json_response_array = array("desc" => "This order is no longer pending and cannot be approved again.");
									$json_response_encode = json_encode($json_response_array,true);
								} else {
									if (function_exists('bc_ensure_wallet_transactional_schema')) {
										bc_ensure_wallet_transactional_schema($connection_server);
									}
									$GLOBALS['vendor_id'] = $admin_vid;
									if (function_exists('resolveVendorID')) resolveVendorID(true);
									$purchase_method = "WEB";
									$user = $get_payment_order["username"];
									$type = "credit";
									$amount = $get_payment_order["amount"];
									$discounted_amount = $get_payment_order["discounted_amount"];
									$reference_2 = 'POWEB_' . substr(hash('sha256', $admin_vid . ':' . $reference), 0, 20);
									$description = ucwords("account ".$type."ed by admin ( payment order )");
									$credit_other_user = chargeOtherUser($user, $type, $user, ucwords("wallet ".$type), $reference_2, "payment_order_web:" . $reference, $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"] ?? "WEB", "1");
									if($credit_other_user === "success"){
										if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'payment_order_approved', (string)$reference, 'Credited NGN ' . number_format((float)$get_payment_order["discounted_amount"], 2) . ' to ' . $get_payment_order["username"] . ' (web payment order)');
										$json_response_array = array("desc" => ucwords($get_payment_order["username"]." Credited with N".toDecimal($get_payment_order["discounted_amount"],2)." successfully"));
										$json_response_encode = json_encode($json_response_array,true);
									} else {
										mysqli_query($connection_server, "UPDATE sas_submitted_payments SET status='2' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='1' LIMIT 1");
										$po_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : ("Cannot Proceed Processing Transaction (" . (string)$credit_other_user . "). Verify user account is active and wallet tables are ready.");
										$json_response_array = array("desc" => $po_fail_reason);
										$json_response_encode = json_encode($json_response_array,true);
									}
								}
							}
						} else {
							// §29: anything not still pending can no longer be
							// (re-)approved — no credit, no state change.
							$json_response_array = array("desc" => "This order is no longer pending and cannot be approved again.");
							$json_response_encode = json_encode($json_response_array,true);
						}
                    }
                }else{
                    if(mysqli_num_rows($select_payment_order) > 1){
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
        header("Location: " . $po_redirect_url);
	exit();
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Payment Orders | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
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
<?php bc_adm_topbar(array("area" => "admin", "title" => "PAYMENT ORDERS", "crumb" => "Home / Payment Orders")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">
            <?php
                $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
                $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
                $limit = 20;
                $offset = ($page_num - 1) * $limit;

                $search_statement = "";
                $search_parameter = "";
                if(!empty($searchq)){
                    $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                    $search_statement = " AND (reference LIKE '%$search_esc%' OR description LIKE '%$search_esc%' OR username LIKE '%$search_esc%' OR amount LIKE '%$search_esc%')";
                    $search_parameter = "searchq=".urlencode($searchq)."&";
                }

                $get_user_pending_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_submitted_payments WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='2' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                $get_user_successful_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_submitted_payments WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='1' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                $get_user_failed_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_submitted_payments WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='3' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");

                // Mobile-app manual bank deposits (sas_transactions.manual_funding)
                $get_app_pending_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='2' && product_unique_id='manual_funding' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                $get_app_successful_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='1' && product_unique_id='manual_funding' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                $get_app_failed_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='3' && product_unique_id='manual_funding' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");

                // Totals for the summary tiles / tab badges.
                $vid_po = $get_logged_admin_details["id"];
                $po_count = function ($sql) use ($connection_server) {
                    $q = mysqli_query($connection_server, $sql);
                    if (!$q) return 0;
                    $r = mysqli_fetch_assoc($q);
                    return (int)($r['c'] ?? 0);
                };
                $count_web_pending  = $po_count("SELECT COUNT(*) c FROM sas_submitted_payments WHERE vendor_id='$vid_po' && status='2'");
                $count_web_approved = $po_count("SELECT COUNT(*) c FROM sas_submitted_payments WHERE vendor_id='$vid_po' && status='1'");
                $count_web_rejected = $po_count("SELECT COUNT(*) c FROM sas_submitted_payments WHERE vendor_id='$vid_po' && status='3'");
                $count_app_pending  = $po_count("SELECT COUNT(*) c FROM sas_transactions WHERE vendor_id='$vid_po' && status='2' && product_unique_id='manual_funding'");
                $count_app_approved = $po_count("SELECT COUNT(*) c FROM sas_transactions WHERE vendor_id='$vid_po' && status='1' && product_unique_id='manual_funding'");
                $count_app_rejected = $po_count("SELECT COUNT(*) c FROM sas_transactions WHERE vendor_id='$vid_po' && status='3' && product_unique_id='manual_funding'");
                $count_pending_all  = $count_web_pending + $count_app_pending;

                // Active tab (Pending by default so approvals are the first thing seen).
                $active_tab = (isset($_GET['tab']) && in_array($_GET['tab'], array('pending', 'approved', 'rejected'))) ? $_GET['tab'] : 'pending';
            ?>

            <!-- Summary tiles -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <a href="PaymentOrders.php?<?php echo $search_parameter; ?>tab=pending" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-warning bg-opacity-10 text-warning d-flex align-items-center justify-content-center" style="width:52px;height:52px;"><i class="bi bi-hourglass-split fs-4"></i></div>
                                <div><div class="fs-3 fw-bold text-dark"><?php echo $count_pending_all; ?></div><div class="small text-muted">Pending Approval</div></div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-lg-3">
                    <a href="PaymentOrders.php?<?php echo $search_parameter; ?>tab=pending" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width:52px;height:52px;"><i class="bi bi-globe2 fs-4"></i></div>
                                <div><div class="fs-3 fw-bold text-dark"><?php echo $count_web_pending; ?></div><div class="small text-muted">Website Pending</div></div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-lg-3">
                    <a href="PaymentOrders.php?<?php echo $search_parameter; ?>tab=pending" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-info bg-opacity-10 text-info d-flex align-items-center justify-content-center" style="width:52px;height:52px;"><i class="bi bi-phone fs-4"></i></div>
                                <div><div class="fs-3 fw-bold text-dark"><?php echo $count_app_pending; ?></div><div class="small text-muted">App Pending</div></div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-lg-3">
                    <a href="PaymentOrders.php?<?php echo $search_parameter; ?>tab=approved" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center" style="width:52px;height:52px;"><i class="bi bi-check2-circle fs-4"></i></div>
                                <div><div class="fs-3 fw-bold text-dark"><?php echo $count_web_approved + $count_app_approved; ?></div><div class="small text-muted">Approved</div></div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-0 text-primary">Payment Approval Portal</h5>
                            <p class="text-muted small mb-0">Approve website and mobile-app manual payments — pending items first</p>
                            <form method="post" class="d-inline-block m-0">
    <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
    <button type="submit" name="send_stepup_code" value="1" class="btn btn-outline-primary btn-sm rounded-pill">Email me an approval code</button>
</form>
<span class="small text-muted d-block mt-1">Approve/Credit actions need your Security PIN + authenticator/email code.</span>

                        </div>
                        <div class="col-md-6">
                            <form method="get" action="PaymentOrders.php" class="d-flex gap-2 justify-content-md-end">
                                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>" />
                                <input name="searchq" type="text" value="<?php echo $searchq; ?>" placeholder="User, Ref, Amount..." class="form-control" style="max-width: 250px;" />
                                <button type="submit" class="btn btn-primary px-4 fw-bold">Filter</button>
                                <a href="PaymentOrders.php" class="btn btn-outline-secondary" title="Refresh"><i class="bi bi-arrow-clockwise"></i></a>
                            </form>
                        </div>
                    </div>
                    <ul class="nav nav-pills gap-2 mt-3" role="tablist">
                        <li class="nav-item"><button class="nav-link fw-bold <?php echo $active_tab === 'pending' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#po-pending" type="button"><i class="bi bi-hourglass-split me-1"></i>Pending <span class="badge bg-warning text-dark ms-1"><?php echo $count_pending_all; ?></span></button></li>
                        <li class="nav-item"><button class="nav-link fw-bold <?php echo $active_tab === 'approved' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#po-approved" type="button"><i class="bi bi-check2-circle me-1"></i>Approved</button></li>
                        <li class="nav-item"><button class="nav-link fw-bold <?php echo $active_tab === 'rejected' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#po-rejected" type="button"><i class="bi bi-x-circle me-1"></i>Rejected</button></li>
                    </ul>
                </div>
                <div class="card-body p-4">
                    <div class="tab-content">

                        <!-- PENDING -->
                        <div class="tab-pane fade <?php echo $active_tab === 'pending' ? 'show active' : ''; ?>" id="po-pending">
                            <div class="row g-4">
                                <div class="col-xl-6">
                                    <h6 class="fw-bold mb-3 text-primary d-flex align-items-center"><i class="bi bi-globe2 me-2"></i>Website Pending <span class="badge bg-primary bg-opacity-10 text-primary ms-2"><?php echo $count_web_pending; ?></span></h6>
                                    <div class="table-responsive bg-light rounded-4 border p-2">
                                    <?php
                                        $query_result = $get_user_pending_transaction_details;
                                        $is_admin = true; $is_payment_order = true;
                                        include("../func/history-table.php");
                                        unset($is_payment_order);
                                    ?>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <h6 class="fw-bold mb-3 text-info d-flex align-items-center"><i class="bi bi-phone me-2"></i>App Pending <span class="badge bg-info bg-opacity-10 text-info ms-2"><?php echo $count_app_pending; ?></span></h6>
                                    <div class="table-responsive bg-light rounded-4 border p-2">
                                    <?php
                                        $query_result = $get_app_pending_transaction_details;
                                        $is_admin = true; $inline_approve_page = "PaymentOrders.php";
                                        include("../func/history-table.php");
                                        unset($inline_approve_page);
                                    ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- APPROVED -->
                        <div class="tab-pane fade <?php echo $active_tab === 'approved' ? 'show active' : ''; ?>" id="po-approved">
                            <div class="row g-4">
                                <div class="col-xl-6">
                                    <h6 class="fw-bold mb-3 text-primary d-flex align-items-center"><i class="bi bi-globe2 me-2"></i>Website Approved <span class="badge bg-primary bg-opacity-10 text-primary ms-2"><?php echo $count_web_approved; ?></span></h6>
                                    <div class="table-responsive bg-light rounded-4 border p-2">
                                    <?php
                                        $query_result = $get_user_successful_transaction_details;
                                        $is_admin = true; $is_payment_order = true;
                                        include("../func/history-table.php");
                                        unset($is_payment_order);
                                    ?>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <h6 class="fw-bold mb-3 text-info d-flex align-items-center"><i class="bi bi-phone me-2"></i>App Approved <span class="badge bg-info bg-opacity-10 text-info ms-2"><?php echo $count_app_approved; ?></span></h6>
                                    <div class="table-responsive bg-light rounded-4 border p-2">
                                    <?php
                                        $query_result = $get_app_successful_transaction_details;
                                        $is_admin = false;
                                        include("../func/history-table.php");
                                    ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- REJECTED -->
                        <div class="tab-pane fade <?php echo $active_tab === 'rejected' ? 'show active' : ''; ?>" id="po-rejected">
                            <div class="row g-4">
                                <div class="col-xl-6">
                                    <h6 class="fw-bold mb-3 text-primary d-flex align-items-center"><i class="bi bi-globe2 me-2"></i>Website Rejected <span class="badge bg-primary bg-opacity-10 text-primary ms-2"><?php echo $count_web_rejected; ?></span></h6>
                                    <div class="table-responsive bg-light rounded-4 border p-2">
                                    <?php
                                        $query_result = $get_user_failed_transaction_details;
                                        $is_admin = true; $is_payment_order = true;
                                        include("../func/history-table.php");
                                        unset($is_payment_order);
                                    ?>
                                    </div>
                                </div>
                                <div class="col-xl-6">
                                    <h6 class="fw-bold mb-3 text-info d-flex align-items-center"><i class="bi bi-phone me-2"></i>App Rejected <span class="badge bg-info bg-opacity-10 text-info ms-2"><?php echo $count_app_rejected; ?></span></h6>
                                    <div class="table-responsive bg-light rounded-4 border p-2">
                                    <?php
                                        $query_result = $get_app_failed_transaction_details;
                                        $is_admin = false;
                                        include("../func/history-table.php");
                                    ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="card-footer bg-white py-3 border-0 text-center">
                    <div class="d-flex justify-content-center gap-2">
                        <?php if($page_num > 1): ?>
                        <a href="PaymentOrders.php?<?php echo $search_parameter; ?>tab=<?php echo htmlspecialchars($active_tab); ?>&page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-4 rounded-pill">Previous Page</a>
                        <?php endif; ?>
                        <a href="PaymentOrders.php?<?php echo $search_parameter; ?>tab=<?php echo htmlspecialchars($active_tab); ?>&page=<?php echo ($page_num + 1); ?>" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">Next Page</a>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>






<?php bc_adm_foot(array("area" => "admin")); ?>
