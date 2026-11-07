<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    if(isset($_GET["order-ref"])){
    	$status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["order-status"])));
    	$reference = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["order-ref"])));
    	$statusArray = array(1, 2);
    	if(is_numeric($status)){
    		if(in_array($status, $statusArray)){
    			$select_payment_order = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_submitted_payments WHERE reference='".$reference."'");
    			if(mysqli_num_rows($select_payment_order) == 1){
    				$get_payment_order = mysqli_fetch_array($select_payment_order);
					$get_vendors_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".$get_payment_order["vendor_id"]."'"));
					if(isset($get_vendors_details["id"])){
						$verified_vendors_details = $get_vendors_details;
					}else{
						$verified_vendors_details = "User Not Exists";
					}
                    		
    				if($status == 1){
    					$update_payment_status = mysqli_query($connection_server, "UPDATE sas_super_admin_submitted_payments SET status='3' WHERE reference='".$reference."'");
    					$json_response_array = array("desc" => ucwords($verified_vendors_details["email"]." Order with N".toDecimal($get_payment_order["discounted_amount"],2)." rejected successfully"));
    					$json_response_encode = json_encode($json_response_array,true);
    				}
    				
    				if($status == 2){
						if(in_array($get_payment_order["status"], array("2","3"))){
							$purchase_method = "web";
							$purchase_method = strtoupper($purchase_method);
							$user = $verified_vendors_details["email"];
							$type = "credit";
							$amount = $get_payment_order["amount"];
							$discounted_amount = $get_payment_order["discounted_amount"];
							$type_alternative = ucwords("wallet ".$type);
							$reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
							$description = ucwords("account ".$type."ed by admin ( payment order )");
							$transType = $type;

							$credit_other_user = chargeOtherVendor($user, $transType, $user, ucwords("wallet ".$type), $reference_2, $amount, $discounted_amount, $description, $_SERVER["HTTP_HOST"], "1");
							if(in_array($credit_other_user, array("success"))){
								$update_payment_status = mysqli_query($connection_server, "UPDATE sas_super_admin_submitted_payments SET status='1' WHERE reference='".$reference."'");
								$json_response_array = array("desc" => ucwords($verified_vendors_details["email"]." Credited with N".toDecimal($get_payment_order["discounted_amount"],2)." successfully"));
								$json_response_encode = json_encode($json_response_array,true);
							}
							
							if($credit_other_user == "failed"){
								$json_response_array = array("desc" => "Cannot Proceed Processing Transaction");
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
    	header("Location: /bc-spadmin/PaymentOrders.php");
    }
?>
<!DOCTYPE html>
<head>
    <title>Payment Orders</title>
    <meta charset="UTF-8" />
    <meta name="description" content="" />
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
	<?php include("../func/bc-spadmin-header.php"); ?>
	<div class="pagetitle">
      <h1>PAYMENT ORDERS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Payment Orders</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

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
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light"><tr class="small text-uppercase text-muted"><th class="ps-4">Ref / Vendor</th><th>Details</th><th>Amount</th><th>Status</th><th class="text-end pe-4">Actions</th></tr></thead>
                                <tbody>
                                    <?php if($get_user_pending_transaction_details && mysqli_num_rows($get_user_pending_transaction_details) > 0):
                                        while($row = mysqli_fetch_assoc($get_user_pending_transaction_details)):
                                            $v_q = mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='".$row["vendor_id"]."' LIMIT 1");
                                            $v_email = ($v_row = mysqli_fetch_assoc($v_q)) ? $v_row['email'] : "Unknown Vendor";
                                    ?>
                                    <tr>
                                        <td class="ps-4">
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
                                                <button onclick="superAdminPaymentOrderStatus('2','<?php echo $row['reference']; ?>','<?php echo $v_email; ?>')" class="btn btn-success px-3">Approve</button>
                                                <button onclick="superAdminPaymentOrderStatus('1','<?php echo $row['reference']; ?>','<?php echo $v_email; ?>')" class="btn btn-danger px-3">Reject</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="text-center py-5 text-muted">No pending requests found</td></tr>
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
                                        <td class="text-end pe-4"><button onclick="superAdminPaymentOrderStatus('2','<?php echo $row['reference']; ?>','<?php echo $v_email; ?>')" class="btn btn-outline-success btn-sm rounded-pill px-3">Re-Approve</button></td>
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
		
	<?php include("../func/bc-spadmin-footer.php"); ?>
	
</body>
</html>