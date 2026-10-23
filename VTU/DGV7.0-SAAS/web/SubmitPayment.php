<?php session_start();
    include("../func/bc-config.php");
	$get_admin_payment_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_admin_payments WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' LIMIT 1"));
	$get_admin_payment_order_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_admin_payment_orders WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' LIMIT 1"));
	
    if(isset($_POST["submit-payment"])){
        $purchase_method = "web";
        $purchase_method = strtoupper($purchase_method);
    	$purchase_method_array = array("WEB");
    	if(in_array($purchase_method, $purchase_method_array)){
            if($purchase_method === "WEB"){
                $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["amount"]))));
            }

            $discounted_amount = ($amount - $get_admin_payment_details["amount_charged"]);
            $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
            $description = "Request Sent";
            if(!empty($amount) && is_numeric($amount)){
                if(($amount > $get_admin_payment_details["amount_charged"]) && ($amount > 0) && ($get_admin_payment_details["amount_charged"] == true) && ($get_admin_payment_details["amount_charged"] > 0)){
                    if(isset($get_admin_payment_order_details["min_amount"]) && isset($get_admin_payment_order_details["max_amount"]) && ($amount >= $get_admin_payment_order_details["min_amount"]) && ($amount <= $get_admin_payment_order_details["max_amount"])){
                        $create_submitted_payment_table = mysqli_query($connection_server, "INSERT INTO sas_submitted_payments (vendor_id, username, reference, amount, discounted_amount, description, mode, api_website, status) VALUES ('".$get_logged_user_details["vendor_id"]."', '".$get_logged_user_details["username"]."', '$reference', '$amount', '$discounted_amount', '$description', '$purchase_method', '".bc_safe_host_sql($connection_server)."', '2')");
                        if($create_submitted_payment_table == true){
                            //Request Sent Successfully
                            $json_response_array = array("desc" => "Request Sent Successfully");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            //Request Initiation Failed
                            $json_response_array = array("desc" => "Request Initiation Failed");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }else{
                        if(!isset($get_admin_payment_order_details["min_amount"])){
                            //Minimum Amount Not Set
                            $json_response_array = array("desc" => "Minimum Amount Not Set");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(!isset($get_admin_payment_order_details["max_amount"])){
                                //Maximum Amount Not Set
                                $json_response_array = array("desc" => "Maximum Amount Not Set");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(($amount < $get_admin_payment_order_details["min_amount"])){
                                    //Minimum Amount Is ...
                                    $json_response_array = array("desc" => "USE THE ONLINE AUTO-FUND BANK TRANSFER");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }else{
                                    if(($amount > $get_admin_payment_order_details["max_amount"])){
                                        //Maximum Amount Is ...
                                        $json_response_array = array("desc" => "Maximum Amount Is N".$get_admin_payment_order_details["max_amount"]);
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }
                                }
                            }
                        }
                    }
                }else{
                	//Amount Too LOW
                	$json_response_array = array("desc" => "Amount Too LOW");
                	$json_response_encode = json_encode($json_response_array,true);
                }
            }else{
            	//Incomplete Parameters
            	$json_response_array = array("desc" => "Incomplete Parameters");
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
    }

?>
<!DOCTYPE html>
<head>
    <title>Submit Payment | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    
         <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link
    href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
    rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
	<?php include("../func/bc-header.php"); ?>
	
	<div class="pagetitle">
      <h1>SUBMIT PAYMENT ORDER</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Submit Payment Order</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <!-- Bank Details Card -->
        <div class="col-lg-6">
          <div class="card info-card p-4">
            <h5 class="card-title fw-bold text-success mb-3"><i class="bi bi-bank me-2"></i>Manual Bank Funding</h5>
            <div class="alert alert-info py-2" style="font-size: 14px;">
              <i class="bi bi-info-circle me-2"></i>Send money to the account below, then submit your payment info here.
            </div>

            <div class="bg-light p-3 rounded-4 mb-3 border">
              <div class="mb-3">
                <small class="text-muted d-block">Bank Name</small>
                <span class="h6 fw-bold text-dark"><?php echo strtoupper($get_admin_payment_details["bank_name"]); ?></span>
              </div>
              <div class="mb-3">
                <small class="text-muted d-block">Account Number</small>
                <div class="d-flex align-items-center">
                  <span class="h4 fw-bold text-primary mb-0 me-2"><?php echo $get_admin_payment_details["account_number"]; ?></span>
                  <button class="btn btn-sm btn-outline-primary py-0" onclick="copyText('Copied', '<?php echo $get_admin_payment_details['account_number']; ?>')"><i class="bi bi-copy"></i></button>
                </div>
              </div>
              <div class="mb-0">
                <small class="text-muted d-block">Account Name</small>
                <span class="h6 fw-bold text-dark"><?php echo strtoupper($get_admin_payment_details["account_name"]); ?></span>
              </div>
            </div>

            <div class="small text-muted mb-0">
              <p class="mb-2"><i class="bi bi-clock me-2"></i>Wallet funded within 15 mins of confirmation.</p>
              <p class="mb-0"><i class="bi bi-telephone-fill me-2"></i>Support: <span class="fw-bold text-success"><?php echo $get_admin_payment_details["phone_number"]; ?></span></p>
            </div>
          </div>
        </div>

        <!-- Submit Form Card -->
        <div class="col-lg-6">
          <div class="card info-card p-4">
            <h5 class="card-title fw-bold mb-3">Submit Payment Info</h5>

            <div class="d-flex justify-content-between mb-4 border-bottom pb-2">
              <div class="text-center">
                <small class="text-muted d-block">Min Amount</small>
                <span class="fw-bold text-dark">₦<?php echo number_format($get_admin_payment_order_details["min_amount"], 2); ?></span>
              </div>
              <div class="text-center">
                <small class="text-muted d-block">Max Amount</small>
                <span class="fw-bold text-dark">₦<?php echo number_format($get_admin_payment_order_details["max_amount"], 2); ?></span>
              </div>
              <div class="text-center">
                <small class="text-muted d-block">Fee</small>
                <span class="fw-bold text-danger">₦<?php echo number_format($get_admin_payment_details["amount_charged"], 2); ?></span>
              </div>
            </div>

            <form method="post" action="">
              <div class="mb-3">
                <label class="form-label small fw-bold">Amount Paid (NGN)</label>
                <input style="text-align: center; font-size: 1.5rem; font-weight: 700;" name="amount" onkeyup="submitPayment(this);" pattern="[0-9]{2, }" title="Digit must be around 2 digit upward naira" value="" placeholder="0.00" class="form-control form-control-lg border-primary" inputmode="numeric" required/>
              </div>

              <button id="proceedBtn" name="submit-payment" type="submit" style="user-select: auto;" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm" >
                SUBMIT PAYMENT ORDER
              </button>

              <div class="text-center mt-3">
                <span id="product-status-span" class="small text-muted"></span>
              </div>
            </form>
          </div>
        </div>
      </div>
    </section>
		<?php include("../func/short-payment-order.php"); ?>
	<?php include("../func/bc-footer.php"); ?>
	
</body>
</html>