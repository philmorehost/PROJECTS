<?php session_start();
    include("../func/bc-admin-config.php");
    
    if(isset($_GET["order-ref"])){
    	$status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["order-status"])));
    	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["order-ref"])));
    	$statusArray = array(1, 2, 3);
    	if(is_numeric($status)){
    		if(in_array($status, $statusArray)){
    			$select_payment_order = mysqli_query($connection_server, "SELECT * FROM sas_fund_transfer_requests WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
    			if(mysqli_num_rows($select_payment_order) == 1){
    				$get_payment_order = mysqli_fetch_array($select_payment_order);
    				if($status == 1){
						$purchase_method = "web";
						$purchase_method = strtoupper($purchase_method);
						$user = $get_payment_order["username"];
						$type = "credit";
						$amount = $get_payment_order["amount"];
						$discounted_amount = $get_payment_order["discounted_amount"];
						$reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
    					$update_payment_status = mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='3' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
    					mysqli_query($connection_server, "UPDATE sas_transactions SET status='3', description='Transfer Rejected' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
						chargeOtherUser($user, $type, $user, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
						$json_response_array = array("desc" => ucwords($get_payment_order["username"]." Order with N".toDecimal($get_payment_order["discounted_amount"],2)." rejected successfully"));
    					$json_response_encode = json_encode($json_response_array,true);
    				}
    				
    				if($status == 2){
						if(in_array($get_payment_order["status"], array("2","3"))){
							$purchase_method = "web";
							$purchase_method = strtoupper($purchase_method);
							$user = $get_payment_order["username"];
							$recipient_user = $get_payment_order["recipient_username"];
							$type = "credit";
							$amount = $get_payment_order["amount"];
							$discounted_amount = $get_payment_order["discounted_amount"];
							$type_alternative = ucwords("wallet ".$type);
							$reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
							$description = "Fund Received From User: ".ucwords($user)." - Approved By Admin";
							$transType = $type;
							$credit_other_user = chargeOtherUser($recipient_user, $transType, $recipient_user, ucwords("fund received"), $reference_2, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], "1");
							if(in_array($credit_other_user, array("success"))){
								$update_payment_status = mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='1' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
								mysqli_query($connection_server, "UPDATE sas_transactions SET status='1' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
								$json_response_array = array("desc" => ucwords($get_payment_order["recipient_username"]." Credited with N".toDecimal($get_payment_order["discounted_amount"],2)." successfully"));
								$json_response_encode = json_encode($json_response_array,true);
							}

							if($credit_other_user == "failed"){
								$update_payment_status = mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='3' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
    							mysqli_query($connection_server, "UPDATE sas_transactions SET status='3', description='Transfer Failed' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
								chargeOtherUser($user, $transType, $user, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
								$json_response_array = array("desc" => "Cannot Proceed Processing Transaction: Fund Refunded");
								$json_response_encode = json_encode($json_response_array,true);
							}		
							
						}else{
							if(in_array($get_payment_order["status"], array("1"))){
								//Order Amount Had Already Been Deposited To User Account
								$json_response_array = array("desc" => "Order Amount Had Already Been Deposited To User Account");
								$json_response_encode = json_encode($json_response_array,true);
							}
						}
    				}

					if($status == 3){
						$update_payment_status = mysqli_query($connection_server, "UPDATE sas_fund_transfer_requests SET status='3' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
    					mysqli_query($connection_server, "UPDATE sas_transactions SET status='3', description='Transfer Rejected (No Refund)' WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".$reference."'");
						$json_response_array = array("desc" => ucwords($get_payment_order["username"]." Order with N".toDecimal($get_payment_order["discounted_amount"],2)." rejected successfully"));
						$json_response_encode = json_encode($json_response_array,true);
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
<!DOCTYPE html>
<head>
    <title>Fund Transfer Requests | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
	<?php include("../func/bc-admin-header.php"); ?>
	<div class="pagetitle">
      <h1>FUND TRANSFER REQUESTS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Fund Transfer Requests</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

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
                    <input style="user-select: auto;" name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"])); ?>" placeholder="Reference No, Username, amount e.t.c" class="form-control mt-3" />
                    <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                        <i class="bi bi-search"></i> Search
                    </button>
                </form>
            </div>

            <div class="mt-4">
                <span style="user-select: auto;" class="fw-bold h5 text-warning"><i class="bi bi-clock-history me-2"></i>PENDING REQUEST</span>
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

		
	<?php include("../func/bc-admin-footer.php"); ?>
	
</body>
</html>
