<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["share-fund"])){
        $purchase_method = "WEB";
        $user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"]))));
        $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["amount"]))));
        $pin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pin"])));

        if (!verifyUserPIN($pin, $get_logged_user_details)) {
            $_SESSION["product_purchase_response"] = "Incorrect Security PIN!";
            header("Location: ShareFund.php");
            exit();
        }

        $discounted_amount = $amount;
        $type_alternative = ucwords("shared fund");
        $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
        $description = "Fund Sharing";
        if(!empty(userBalance(1)) && is_numeric(userBalance(1)) && (userBalance(1) > 0)){
            if(!empty($user) && !empty($amount) && is_numeric($amount)){
                if(userBalance(1) >= $amount){
                	if($get_logged_user_details["username"] !== $user){
						$debit_user = chargeUser("debit", $user, $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], "1");
                        if($debit_user === "success"){
                            $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                            $api_response_text = strtolower($api_response_text);
                            $api_response_description = "Shared Fund To User: ".ucwords($user);
                            
                            $create_submitted_share_fund_table = mysqli_query($connection_server, "INSERT INTO sas_fund_transfer_requests (vendor_id, username, recipient_username, reference, amount, discounted_amount, description, mode, api_website, status) VALUES ('".$get_logged_user_details["vendor_id"]."', '".$get_logged_user_details["username"]."', '$user', '$reference', '$amount', '$discounted_amount', '$description', '$purchase_method', '".bc_safe_host_sql($connection_server)."', '2')");
                            if($create_submitted_share_fund_table == true){
                                alterTransaction($reference, "status", "2");
                                alterTransaction($reference, "description", $api_response_description);
                                alterTransaction($reference, "api_website", $_SERVER["HTTP_HOST"]);

                                $_SESSION["transfer_result"] = [
                                    "status" => "success",
                                    "title" => "Transfer Submitted",
                                    "message" => "Your fund sharing request to @$user has been submitted successfully.",
                                    "details" => [
                                        "amount" => $amount,
                                        "recipient" => $user,
                                        "ref" => $reference
                                    ]
                                ];

                                //Fund Submitted Successfully
                                $json_response_array = array("desc" => "Fund Submitted Successfully");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                $reference_3 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                alterTransaction($reference, "description", $api_response_description);
                                chargeUser("credit", $user, "Refund", $reference_3, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
                                
                                //Request Initiation Failed
                                $json_response_array = array("desc" => "Request Initiation Failed");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }else{
                            //Unable to proceed with charges
                            $json_response_array = array("desc" => "Unable to proceed with charges");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
					}else{
						//Cannot share fund
						$json_response_array = array("desc" => "Cannot share fund");
						$json_response_encode = json_encode($json_response_array,true);
					}
                }else{
                    //Insufficient Wallet Balance
                    $json_response_array = array("desc" => "Insufficient Wallet Balance");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
            	//Incomplete Parameters
            	$json_response_array = array("desc" => "Incomplete Parameters");
            	$json_response_encode = json_encode($json_response_array,true);
            }
        }else{
        	//Balance is LOW
        	$json_response_array = array("desc" => "Balance is LOW");
        	$json_response_encode = json_encode($json_response_array,true);
        }
    }
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Share Fund | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "SHARE FUND", "crumb" => "Home / Share Fund")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <h5 class="fw-bold mb-4 text-center">Transfer Funds to Another User</h5>

            <form method="post" action="">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Recipient Username</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-person text-primary"></i></span>
                        <input id="share-fund-user" name="user" onkeyup="confirmUser();" type="text" placeholder="Enter username" class="form-control border-start-0" required/>
                    </div>
                    <div id="user-status-span" class="small mt-2 text-center text-primary fw-bold"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Amount to Transfer (NGN)</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">₦</span>
                        <input id="share-fund-amount" name="amount" onkeyup="confirmUser();" type="number" placeholder="Min 10" class="form-control border-start-0 fw-bold" inputmode="numeric" required/>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase text-muted">Security PIN</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-shield-lock text-primary"></i></span>
                        <input name="pin" type="password" maxlength="4" placeholder="4-digit PIN" class="form-control border-start-0 fw-bold text-center" inputmode="numeric" required/>
                    </div>
                    <p class="text-center small text-muted mt-2">Enter your 4-digit transaction PIN to authorize this transfer.</p>
                </div>

                <button id="proceedBtn" name="share-fund" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none; opacity: 0.7;">
                    TRANSFER NOW
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small fw-bold text-danger"></span>
                </div>
            </form>
          </div>
        </div>
      </div>
    </section>
		<?php include("../func/short-fund-transfer-request.php"); ?>
	<script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Swal === 'undefined') return;

            const resData = <?php echo json_encode($res); ?>;
            const status = resData.status;
            const title = resData.title;
            const message = resData.message;

            let htmlContent = `<div class="text-center">`;

            if (status === 'success') {
                const details = resData.details || {};
                htmlContent += `
                    <div class="mb-3"><i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i></div>
                    <h3 class="fw-bold mb-2">₦${Number(details.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</h3>
                    <p class="text-muted small mb-4">${message}</p>
                    <div class="bg-light p-3 rounded-4 text-start mb-0 border">
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-white">
                            <span class="text-muted small">Recipient</span>
                            <span class="fw-bold small text-end">@${details.recipient}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Reference</span>
                            <span class="fw-bold small text-end">${details.ref}</span>
                        </div>
                    </div>
                `;
            } else {
                htmlContent += `
                    <div class="mb-3"><i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3.5rem;"></i></div>
                    <h4 class="fw-bold mb-2">${title}</h4>
                    <p class="text-muted mb-0">${message}</p>
                `;
            }

            htmlContent += `</div>`;

            Swal.fire({
                title: '',
                html: htmlContent,
                showConfirmButton: true,
                confirmButtonText: status === 'success' ? 'DONE' : 'TRY AGAIN',
                confirmButtonColor: status === 'success' ? '#28a745' : '#dc3545',
                customClass: {
                    popup: 'rounded-4 border-0 shadow-lg',
                    confirmButton: 'rounded-pill px-5 py-2 fw-bold'
                }
            });
        });
    </script>
	<?php bc_app_foot(); ?>
