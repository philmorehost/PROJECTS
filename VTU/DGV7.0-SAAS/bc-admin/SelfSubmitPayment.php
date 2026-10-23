<?php session_start();
    include("../func/bc-admin-config.php");
	$get_admin_payment_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payments LIMIT 1"));
	$get_admin_payment_order_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_orders LIMIT 1"));
	
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
            $description = "Request Sent By ".$get_logged_admin_details["email"];
            if(!empty($amount) && is_numeric($amount)){
                if(($amount > $get_admin_payment_details["amount_charged"]) && ($amount > 0) && ($get_admin_payment_details["amount_charged"] == true) && ($get_admin_payment_details["amount_charged"] > 0)){
                    if(isset($get_admin_payment_order_details["min_amount"]) && isset($get_admin_payment_order_details["max_amount"]) && ($amount >= $get_admin_payment_order_details["min_amount"]) && ($amount <= $get_admin_payment_order_details["max_amount"])){
                        $create_submitted_payment_table = mysqli_query($connection_server, "INSERT INTO sas_super_admin_submitted_payments (vendor_id, reference, amount, discounted_amount, description, mode, api_website, status) VALUES ('".$get_logged_admin_details["id"]."', '$reference', '$amount', '$discounted_amount', '$description', '$purchase_method', '".$_SERVER["HTTP_HOST"]."', '2')");
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
        exit;
    }

?>
<!DOCTYPE html>
<head>
    <title>Submit Payment | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
      <h1>SUBMIT PAYMENT ORDER</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Submit Payment Order</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
  
        <div style="text-align: center;" class="card info-card px-5 py-5">

            <form method="post" action="">
                <div style="text-align: left;" class="text-dark h5 lh-lg">
                        WE ACCEPT: Bank Transfer, ATM Payment to our Bank.<br/>
                        Send Money to the below Account:<br/>
                        <span class="m-inline-block-dp s-inline-block-dp m-margin-lt-10 s-margin-lt-5">
                            <span class="text-bold-600">Account Number:</span> <span style="user-select: auto;" class="color-10 text-bold-600"><?php echo $get_admin_payment_details["account_number"]; ?></span><br/>
                            <span class="text-bold-600">Account Name:</span> <span class="color-10 text-bold-600"><?php echo $get_admin_payment_details["account_name"]; ?></span><br>
                            <span class="text-bold-600">Bank Name:</span> <span class="color-10 text-bold-600"><?php echo $get_admin_payment_details["bank_name"]; ?></span>
                        </span><br/>
                        Make sure to send payment information like(Bank Account Name[The one use to make Payment], Amount, Order ID[Reference Number]) to <span style="user-select: auto;" class="color-10 text-bold-600"><?php echo $get_admin_payment_details["phone_number"]; ?></span> then your wallet will be fund within 15minutes after Payment Confirmation<br/>
                        <span class="text-bold-600">Minimum Amount:</span> <span class="color-10 text-bold-600">N<?php echo toDecimal($get_admin_payment_order_details["min_amount"], 2); ?></span>, 
                        <span class="text-bold-600">Maximum Amount:</span> <span class="color-10 text-bold-600">N<?php echo toDecimal($get_admin_payment_order_details["max_amount"], 2); ?></span><br/>
                        Note: <span class="color-10 text-bold-600">N<?php echo toDecimal($get_admin_payment_details["amount_charged"], "2"); ?></span> flat rate apply
                    </span>
                </div><br/>
                <input style="text-align: center;" name="amount" onkeyup="submitPayment(this);" pattern="[0-9]{2, }" title="Digit must be around 2 digit upward naira" value="" placeholder="Amount" class="form-control my-2" required/>
                <button id="proceedBtn" name="submit-payment" type="button" style="pointer-events: none; user-select: auto;" class="btn btn-primary col-12" >
                    SUBMIT
                </button><br>
                <div style="text-align: center;" class="color-4 bg-3 m-inline-block-dp s-inline-block-dp m-font-size-14 s-font-size-16 m-width-60 s-width-45">
                    <span id="product-status-span" class="a-cursor" style="user-select: auto;"></span>
                </div>
            </form>
        </div>
      </div>
    </section>
        
		<?php include("../func/admin-short-payment-order.php"); ?>
	<?php include("../func/bc-admin-footer.php"); ?>
	
</body>
</html>
