<?php session_start();
    include("../func/bc-admin-config.php");
        
    if(isset($_POST["share-fund"])){
        $pin = mysqli_real_escape_string($connection_server, $_POST['security_pin']);
        if (empty($get_logged_admin_details['security_pin'])) {
             $_SESSION['product_purchase_response'] = "Error: Please set your Security PIN in Account Settings first.";
             header("Location: AccountSettings.php");
             exit();
        }

        if (!verifyUserPIN($pin, $get_logged_admin_details)) {
             $_SESSION['product_purchase_response'] = "Error: Invalid Security PIN.";
             header("Location: ShareFund.php");
             exit();
        }

        $purchase_method = "web";
        $purchase_method = strtoupper($purchase_method);
    	$purchase_method_array = array("WEB");
    	if(in_array($purchase_method, $purchase_method_array)){
        if($purchase_method === "WEB"){
            $user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"]))));
            $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"]))));
            $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["amount"]))));
        }

        $discounted_amount = $amount;
        $type_alternative = ucwords("wallet ".$type);
        $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
        $description = ucwords("account ".$type."ed by admin");
        if(in_array($type, array("debit"))){
        	$transType = "debit";
        }
        
        if(in_array($type, array("credit","refund"))){
        	$transType = "credit";
        }
        $get_logged_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && username='$user' LIMIT 1");
		if(in_array($type, array("debit","credit","refund"))){
			if(mysqli_num_rows($get_logged_user_query) == 1){
				$credit_other_user = chargeOtherUser($user, $transType, $user, ucwords("wallet ".$type), $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], "1");
				if(in_array($credit_other_user, array("success"))){
					$json_response_array = array("reference" => $reference, "status" => "success", "desc" => ucwords($user." ".$type."ed with N".$amount." successfully"));
                	$json_response_encode = json_encode($json_response_array,true);
				}
                                                    
            	if($credit_other_user == "failed"){
					$json_response_array = array("desc" => "Transaction Failed");
                	$json_response_encode = json_encode($json_response_array,true);
             	}		
			}else{
				//User not exists
				$json_response_array = array("desc" => "User not exists");
				$json_response_encode = json_encode($json_response_array,true);
			}
		}else{
			//Invalid Transaction Type
			$json_response_array = array("desc" => "Invalid Transaction Type");
			$json_response_encode = json_encode($json_response_array,true);
		}
    }else{
        //Purchase Method Not specified
        $json_response_array = array("desc" => "Purchase Method Not specified");
        $json_response_encode = json_encode($json_response_array,true);
    }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
?>
<!DOCTYPE html>
<head>
    <title>Share Fund | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
      <h1>SHARE FUND</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Share Fund</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-5 col-md-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0">
                    <div class="d-flex align-items-center justify-content-center gap-3">
                        <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-send text-dark-primary fs-3"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0">Share Funds</h4>
                            <p class="small text-muted mb-0">Admin to Customer Transfer</p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4 p-md-5">
                    <form method="post" action="">
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase mb-2">Recipient Username</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light border-0"><i class="bi bi-person text-primary"></i></span>
                                <input id="share-fund-user" name="user" onkeyup="adminConfirmUser();" type="text" placeholder="e.g. smartuser1" class="form-control bg-light border-0 py-3 rounded-end-3" required/>
                            </div>
                            <div class="mt-3">
                                <div id="user-status-container" class="p-3 bg-light rounded-3 text-center border border-dashed">
                                    <span id="user-status-span" class="small fw-bold text-muted">Awaiting recipient username...</span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Transaction Type</label>
                                <div class="row g-2">
                                    <div class="col-4">
                                        <input type="radio" class="btn-check" name="type" id="type-credit" value="credit" required>
                                        <label class="btn btn-outline-success w-100 py-3 rounded-3" for="type-credit">
                                            <i class="bi bi-plus-circle d-block mb-1 fs-4"></i>
                                            <span class="small fw-bold">Credit</span>
                                        </label>
                                    </div>
                                    <div class="col-4">
                                        <input type="radio" class="btn-check" name="type" id="type-debit" value="debit">
                                        <label class="btn btn-outline-danger w-100 py-3 rounded-3" for="type-debit">
                                            <i class="bi bi-dash-circle d-block mb-1 fs-4"></i>
                                            <span class="small fw-bold">Debit</span>
                                        </label>
                                    </div>
                                    <div class="col-4">
                                        <input type="radio" class="btn-check" name="type" id="type-refund" value="refund">
                                        <label class="btn btn-outline-primary w-100 py-3 rounded-3" for="type-refund">
                                            <i class="bi bi-arrow-counterclockwise d-block mb-1 fs-4"></i>
                                            <span class="small fw-bold">Refund</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Amount (₦)</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-light border-0 fw-bold text-primary">₦</span>
                                    <input id="share-fund-amount" name="amount" onkeyup="adminConfirmUser();" type="number" step="any" min="1" placeholder="0.00" class="form-control bg-light border-0 py-3 rounded-end-3" required/>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                             <div class="alert alert-warning border-0 rounded-4 small py-3 px-4 d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                                <div><b>Security Note:</b> This action is irreversible. Funds will be deducted/added immediately.</div>
                             </div>
                        </div>

                        <div class="mb-4 border-top pt-3">
                            <label class="form-label small fw-bold text-danger text-uppercase mb-2"><i class="bi bi-shield-lock me-1"></i>Admin Security PIN</label>
                            <input name="security_pin" type="password" maxlength="4" pattern="\d{4}" inputmode="numeric" placeholder="****" class="form-control form-control-lg text-center fw-bold bg-light border-0" style="letter-spacing: 10px; font-size: 24px;" required/>
                            <div class="form-text text-center mt-2 small">Verify your identity to authorize this transfer.</div>
                        </div>

                        <button id="proceedBtn" name="share-fund" type="submit" class="btn btn-primary btn-lg w-100 rounded-4 py-3 fw-bold shadow-sm mb-3" style="pointer-events: none; opacity:0.6;">
                            <i class="bi bi-check2-circle me-2"></i>CONFIRM & PROCESS
                        </button>

                        <div id="product-status-span" class="text-center small text-danger fw-bold"></div>
                    </form>
                </div>
            </div>
        </div>
      </div>

	<?php include("../func/bc-admin-footer.php"); ?>
	
</body>
</html>
