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
            return 'Set a Security PIN in Account Settings before managing transfer requests.';
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


    // §29: emailed step-up code sender (shared by the approval forms).
    if (isset($_POST['send_stepup_code'])) {
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp((int)$get_logged_admin_details['id'], (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'fund_transfer_approve', 'Fund Transfer Approval')
            : 'Verification is unavailable.';
        $_SESSION["product_purchase_response"] = $send_err === '' ? "Verification code sent to your email. Enter it with your PIN in the action row." : "Error: $send_err";
        header("Location: /bc-admin/FundTransferRequests.php");
        exit();
    }

    if(isset($_POST["order-ref"])){
        $admin_vid = (int)$get_logged_admin_details["id"];
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-status"] ?? '')));
        $reference = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["order-ref"] ?? '')));
        $statusArray = array(1, 2, 3);
        if(is_numeric($status)){
            if(in_array((int)$status, $statusArray, true)){
                $select_payment_order = mysqli_query($connection_server, "SELECT * FROM sas_fund_transfer_requests WHERE vendor_id='$admin_vid' && reference='".$reference."' LIMIT 1");
                if($select_payment_order && mysqli_num_rows($select_payment_order) == 1){
                    $get_payment_order = mysqli_fetch_array($select_payment_order);
                    if((int)$status == 1){
					if((string)$get_payment_order["status"] !== "2"){
						$json_response_encode = json_encode(array("desc" => "This request is no longer pending."), true);
					} else {
						// Reject + Refund credits the sender wallet — require full step-up.
						$sec29_err = sec29_require_stepup($get_logged_admin_details, 'fund_transfer_approve');
						if ($sec29_err !== '') {
							$json_response_encode = json_encode(array("desc" => $sec29_err), true);
						} else {
							// Atomic claim: exactly one concurrent request may transition status 2 -> 3.
							mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='3' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='2' LIMIT 1");
							if (mysqli_affected_rows($connection_server) !== 1) {
								$json_response_encode = json_encode(array("desc" => "This request is no longer pending."), true);
							} else {
								$purchase_method = "WEB";
								$user = $get_payment_order["username"];
								$type = "credit";
								$amount = $get_payment_order["amount"];
								$discounted_amount = $get_payment_order["discounted_amount"];
								$reference_2 = 'FTRFD_' . substr(hash('sha256', $admin_vid . ':' . $reference), 0, 20);
								$refund_res = chargeOtherUser($user, $type, $user, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
								if ($refund_res === "success") {
									mysqli_query($connection_server, "UPDATE sas_transactions SET status='3', description='Transfer Rejected' WHERE vendor_id='$admin_vid' AND reference='".$reference."'");
									if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'fund_transfer_rejected_refund', (string)$reference, 'Refunded NGN ' . number_format((float)$get_payment_order["discounted_amount"], 2) . ' to ' . $user);
									$json_response_array = array("desc" => ucwords($get_payment_order["username"]." Order with N".toDecimal($get_payment_order["discounted_amount"],2)." rejected successfully"));
									$json_response_encode = json_encode($json_response_array,true);
								} else {
									mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='2' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='3' LIMIT 1");
									$json_response_encode = json_encode(array("desc" => "Cannot Proceed Processing Transaction"), true);
								}
							}
						}
					}
                    }

                    if((int)$status == 2){
						if((string)$get_payment_order["status"] === "2"){
							$sec29_err = sec29_require_stepup($get_logged_admin_details, 'fund_transfer_approve');
							if ($sec29_err !== '') {
								$json_response_encode = json_encode(array("desc" => $sec29_err), true);
							} else {
								// Atomic claim: exactly one concurrent request may transition status 2 -> 1.
								mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='1' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='2' LIMIT 1");
								if (mysqli_affected_rows($connection_server) !== 1) {
									$json_response_array = array("desc" => "This request is no longer pending and cannot be approved again.");
									$json_response_encode = json_encode($json_response_array,true);
								} else {
									if (function_exists('bc_ensure_wallet_transactional_schema')) {
										bc_ensure_wallet_transactional_schema($connection_server);
									}
									$GLOBALS['vendor_id'] = $admin_vid;
									if (function_exists('resolveVendorID')) resolveVendorID(true);
									$purchase_method = "WEB";
									$user = $get_payment_order["username"];
									$recipient_user = $get_payment_order["recipient_username"];
									$type = "credit";
									$amount = $get_payment_order["amount"];
									$discounted_amount = $get_payment_order["discounted_amount"];
									$reference_2 = 'FTAPR_' . substr(hash('sha256', $admin_vid . ':' . $reference), 0, 20);
									$description = "Fund Received From User: ".ucwords($user)." - Approved By Admin";
									$credit_other_user = chargeOtherUser($recipient_user, $type, $recipient_user, ucwords("fund received"), $reference_2, "fund_transfer_approve:" . $reference, $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"] ?? "WEB", "1");
									if($credit_other_user === "success"){
										if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'fund_transfer_approved', (string)$reference, 'Credited NGN ' . number_format((float)$get_payment_order["discounted_amount"], 2) . ' to ' . $get_payment_order["recipient_username"] . ' from ' . $get_payment_order["username"]);
										mysqli_query($connection_server, "UPDATE sas_transactions SET status='1' WHERE vendor_id='$admin_vid' AND reference='".$reference."'");
										$json_response_array = array("desc" => ucwords($get_payment_order["recipient_username"]." Credited with N".toDecimal($get_payment_order["discounted_amount"],2)." successfully"));
										$json_response_encode = json_encode($json_response_array,true);
									} else {
										$ft_fail_reason = !empty($GLOBALS['bc_charge_failure_reason']) ? (string)$GLOBALS['bc_charge_failure_reason'] : '';
										$refund_ref = 'FTRFD_' . substr(hash('sha256', $admin_vid . ':' . $reference), 0, 20);
										$refund_res = chargeOtherUser($user, $type, $user, "Refund", $refund_ref, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"] ?? "WEB", "1");
										if ($refund_res === "success") {
											mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='3' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='1' LIMIT 1");
											mysqli_query($connection_server, "UPDATE sas_transactions SET status='3', description='Transfer Failed' WHERE vendor_id='$admin_vid' AND reference='".$reference."'");
											if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'fund_transfer_failed_refund', (string)$reference, 'Recipient credit failed; refunded NGN ' . number_format((float)$get_payment_order["discounted_amount"], 2) . ' to ' . $user);
											$json_response_array = array("desc" => "Cannot Proceed Processing Transaction: Fund Refunded" . ($ft_fail_reason !== '' ? " ($ft_fail_reason)" : ""));
										} else {
											mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='2' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='1' LIMIT 1");
											$json_response_array = array("desc" => $ft_fail_reason !== '' ? $ft_fail_reason : "Cannot Proceed Processing Transaction");
										}
										$json_response_encode = json_encode($json_response_array,true);
									}
								}
							}
						}else{
							$json_response_array = array("desc" => "This request is no longer pending and cannot be approved again.");
							$json_response_encode = json_encode($json_response_array,true);
						}
                    }

					if((int)$status == 3){
					if((string)$get_payment_order["status"] !== "2"){
						$json_response_encode = json_encode(array("desc" => "This request is no longer pending."), true);
					} else {
						$sec29_pin_err = sec29_verify_admin_pin_only($get_logged_admin_details, $_POST, 'fund_transfer_reject');
						if ($sec29_pin_err !== '') {
							$json_response_encode = json_encode(array("desc" => $sec29_pin_err), true);
						} else {
							mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='3' WHERE vendor_id='$admin_vid' AND reference='".$reference."' AND status='2' LIMIT 1");
							if (mysqli_affected_rows($connection_server) === 1) {
								mysqli_query($connection_server, "UPDATE sas_transactions SET status='3', description='Transfer Rejected (No Refund)' WHERE vendor_id='$admin_vid' AND reference='".$reference."'");
								if (function_exists('bc_sec_audit')) bc_sec_audit($admin_vid, 'admin', (string)$get_logged_admin_details["email"], 'fund_transfer_rejected', (string)$reference, 'Rejected fund transfer request without refund');
								$json_response_array = array("desc" => ucwords($get_payment_order["username"]." Order with N".toDecimal($get_payment_order["discounted_amount"],2)." rejected successfully"));
								$json_response_encode = json_encode($json_response_array,true);
							} else {
								$json_response_encode = json_encode(array("desc" => "This request is no longer pending."), true);
							}
						}
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
        header("Location: /bc-admin/FundTransferRequests.php");
        exit;
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Fund Transfer Requests | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
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
<?php bc_adm_topbar(array("area" => "admin", "title" => "FUND TRANSFER REQUESTS", "crumb" => "Home / Fund Transfer Requests")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <h5 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-2"></i>Admin Processing Guide</h5>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border-start border-primary border-4 h-100">
                            <h6 class="fw-bold mb-1">Approve</h6>
                            <p class="small text-muted mb-0">The recipient will receive the funds in their wallet immediately.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border-start border-danger border-4 h-100">
                            <h6 class="fw-bold mb-1">Reject</h6>
                            <p class="small text-muted mb-0">The sender will receive a full refund of the amount in their wallet.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border-start border-dark border-4 h-100">
                            <h6 class="fw-bold mb-1">Cancel</h6>
                            <p class="small text-muted mb-0">Transaction is voided. No funds will be credited to either user.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php

            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                $page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
                $offset_statement = " OFFSET ".((10 * $page_num) - 10);
            }else{
                $offset_statement = "";
            }

            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $search_statement = " && (reference LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR description LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR username LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR recipient_username LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR amount LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_user_pending_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_fund_transfer_requests WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='2' $search_statement ORDER BY date DESC LIMIT 10 $offset_statement");
            $get_user_successful_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_fund_transfer_requests WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='1' $search_statement ORDER BY date DESC LIMIT 10 $offset_statement");
            $get_user_failed_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_fund_transfer_requests WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='3' $search_statement ORDER BY date DESC LIMIT 10 $offset_statement");

        ?>
        <div class="card info-card px-5 py-5">
            <div class="row mb-3">
                 <form method="get" action="FundTransferRequests.php" class="">
                     <input style="user-select: auto;" name="searchq" type="text" value="<?php echo htmlspecialchars(isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : '', ENT_QUOTES); ?>" placeholder="Reference No, Username, amount e.t.c" class="form-control mt-3" />
                     <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                         <i class="bi bi-search"></i> Search
                     </button>
                 </form>
            </div>

            <div class="mt-4">
                <span style="user-select: auto;" class="fw-bold h5 text-warning"><i class="bi bi-clock-history me-2"></i>PENDING REQUEST</span>
                <span class="d-block mt-2"><form method="post" class="d-inline-block m-0">
    <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
    <button type="submit" name="send_stepup_code" value="1" class="btn btn-outline-primary btn-sm rounded-pill">Email me an approval code</button>
</form>
<span class="small text-muted d-block mt-1">Approve/Credit actions need your Security PIN + authenticator/email code.</span>
</span>
                <?php
                $query_result = $get_user_pending_transaction_details;
                $is_admin = true;
                $is_fund_transfer = true;
                include("../func/history-table.php");
                ?>
            </div>

            <div class="mt-5">
                <span style="user-select: auto;" class="fw-bold h5 text-success"><i class="bi bi-check-circle me-2"></i>SUCCESSFUL REQUEST</span>
                <?php
                $query_result = $get_user_successful_transaction_details;
                $is_admin = true;
                $is_fund_transfer = true;
                include("../func/history-table.php");
                ?>
            </div>

            <div class="mt-5">
                <span style="user-select: auto;" class="fw-bold h5 text-danger"><i class="bi bi-x-circle me-2"></i>REJECTED REQUEST</span>
                <?php
                $query_result = $get_user_failed_transaction_details;
                $is_admin = true;
                $is_fund_transfer = true;
                include("../func/history-table.php");
                ?>
            </div>

            <div class="mt-2 justify-content-between justify-items-center">
                <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
                <a href="FundTransferRequests.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Prev</button>
                </a>
                <?php } ?>
                <?php
                    if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                        $trans_next = (trim(strip_tags($_GET["page"])) +1);
                    }else{
                        $trans_next = 2;
                    }
                ?>
                <a href="FundTransferRequests.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Next</button>
                </a>
            </div>
        </div>
      </div>
    </section>






<?php bc_adm_foot(array("area" => "admin")); ?>
