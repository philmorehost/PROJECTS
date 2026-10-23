<?php session_start();
    include("../func/bc-admin-config.php");
    
	if(isset($_SESSION["spadmin_vendor_auth"]) && ($_SESSION["spadmin_vendor_auth"] == true)){
		$status_statement = "(status='1' OR status='2')";
	}else{
		$status_statement = "status='1'";
	}
	
	$get_host_name = array_filter(explode(":",trim($_SERVER["HTTP_HOST"])));
	$get_host_name = $get_host_name[0];
    if(isset($_POST["checkout-cart"])){
		$get_cart_items = mysqli_real_escape_string($connection_server, $_COOKIE[str_replace([":","."],"_",$get_host_name)."_".$get_logged_admin_details["id"]."_cart_items"]);
		
        if(isset($get_cart_items) && (array_filter(explode(" ",trim($get_cart_items))) >= 1)){
			$exp_cart_items = array_filter(explode(" ",trim($get_cart_items)));
			if(count($exp_cart_items) >= 1){
				$count_old_cart_items = 0;
				$count_new_cart_items = 0;
				$count_old_cart_items_amount = 0;
				$count_new_cart_items_amount = 0;
				$api_type_cart_name = "";
				$api_type_cart_name_price = "";
				$api_type_cart_name_website = "";
				foreach($exp_cart_items as $item_id){
					if(is_numeric($item_id) && ($item_id > 0)){
						$get_active_cart_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE id='".$item_id."' && $status_statement"));
						
						if(isset($get_active_cart_details["api_type"])){
							$api_website = str_replace(["//www.","/","http:","https:"],"",$get_active_cart_details["api_website"]);
							$api_type = strtoupper(str_replace(["_","-"]," ",$get_active_cart_details["api_type"]));
							$product_description = $get_active_cart_details["description"];
							$product_api_price = toDecimal($get_active_cart_details["price"], 2);
							$count_new_cart_items += 1;
							$count_new_cart_items_amount += $get_active_cart_details["price"];
							$api_type_cart_name .= strtolower(str_replace(" ","-",trim($api_type))). " ";
							$api_type_cart_name_price .= $get_active_cart_details["price"]. " ";
							$api_type_cart_name_website .= strtolower(trim($api_website)). " ";

						}else{
							//Unknown Item_id
							// $count_old_cart_items += 1;
							// $count_old_cart_items_amount += $get_active_cart_details["price"];
						}
					}
				}


				if(is_numeric($count_new_cart_items_amount) && ($count_new_cart_items_amount > 0)){
					if(is_numeric(vendorBalance(2))){
						if((vendorBalance(2) > 0) && (vendorBalance(2) >= $count_new_cart_items_amount)){
							$product_unique_id = trim($api_type_cart_name);
							$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
							$type_alternative = ucwords(str_replace(" ", ", ", $product_unique_id));
							$amount = $count_new_cart_items_amount;
							$discounted_amount = $amount;
							$description = "API Checkout Charge: ".strtoupper($type_alternative);
							$status = 3;
							$debit_vendor = chargeVendor("debit", $get_logged_admin_details["email"]." ".$product_unique_id, $type_alternative, $reference, $amount, $discounted_amount, $description, $_SERVER["HTTP_HOST"], $status);

							if($debit_vendor == "success"){
								alterVendorTransaction($reference, "status", "1");
								$cart_api_name_array = array_filter(explode(" ", trim($api_type_cart_name)));
								$cart_api_name_price_array = array_filter(explode(" ", trim($api_type_cart_name_price)));
								$cart_api_name_website_array = array_filter(explode(" ", trim($api_type_cart_name_website)));
								
								if(count($cart_api_name_array) > 0){
									if((count($cart_api_name_array) == count($cart_api_name_price_array)) && (count($cart_api_name_price_array) == count($cart_api_name_website_array))){
										$installation_message = "";
										foreach($cart_api_name_array as $index => $api_name){
											$reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
											$select_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_base_url='".$cart_api_name_website_array[$index]."' && api_type='".strtolower($cart_api_name_array[$index])."'");
											if(mysqli_num_rows($select_api_lists) > 0){
												if(mysqli_num_rows($select_api_lists) == 1){
													$api_status = "Installed";
													$refund_amount = $cart_api_name_price_array[$index];
													$refund_discounted_amount = $refund_amount;
													$refund_description = "Refund for Ref:<i>'$reference'</i> Item ".ucwords(str_replace("-", " ",$cart_api_name_array[$index]))." API already Installed"."<br/>";
													$refund_status = 1;
													chargeVendor("credit", $get_logged_admin_details["email"]." ".$cart_api_name_array[$index], ucwords(str_replace("-", " ",$cart_api_name_array[$index])), $reference_2, $refund_amount, $refund_discounted_amount, $refund_description, $_SERVER["HTTP_HOST"], $refund_status);
													$installation_message .= "* [Failed: Refunded] ".ucwords(str_replace("-", " ",$cart_api_name_array[$index]))." (".$cart_api_name_website_array[$index].") already installed {N".$cart_api_name_price_array[$index]." Refunded}"."<br/>";
												}else{
													if(mysqli_num_rows($select_api_lists) > 1){
														//Installed (Duplicated API)
														$api_status = "Installed";
														$refund_amount = $cart_api_name_price_array[$index];
														$refund_discounted_amount = $refund_amount;
														$refund_description = "Refund for Ref:<i>'$reference'</i> Item ".ucwords(str_replace("-", " ",$cart_api_name_array[$index]))." API already Installed"."<br/>";
														$refund_status = 1;
														chargeVendor("credit", $get_logged_admin_details["email"]." ".$cart_api_name_array[$index], ucwords(str_replace("-", " ",$cart_api_name_array[$index])), $reference_2, $refund_amount, $refund_discounted_amount, $refund_description, $_SERVER["HTTP_HOST"], $refund_status);
														
														$installation_message .= "* "."[Failed: Duplicated API] ".ucwords(str_replace("-", " ",$cart_api_name_array[$index]))." (".$cart_api_name_website_array[$index].") already installed multiple times, Contact Admin for assistance {N".$cart_api_name_price_array[$index]." Refunded}"."<br/>";
													}
												}
											}else{
												//install code
												mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_base_url, api_type, api_key, status) VALUES ('".$get_logged_admin_details["id"]."', '".$cart_api_name_website_array[$index]."', '".strtolower($cart_api_name_array[$index])."', '', '1')");
												$installation_message .= "* "."[Success: API Installed] ".ucwords(str_replace("-", " ",$cart_api_name_array[$index]))." (".$cart_api_name_website_array[$index].") installed Successfully {N".$cart_api_name_price_array[$index]." Paid}"."<br/>";
											}
										}
										//Clear Cart Items Cookies
										setcookie(str_replace([":","."],"_",$get_host_name)."_".$get_logged_admin_details["id"]."_cart_items", "", (time() - 100));
										$json_response_array = array("desc" => $installation_message);
										$json_response_encode = json_encode($json_response_array,true);
										$marketplace_redirect = true;
									}else{
										$json_response_array = array("desc" => ucwords("Error: Incomplete Information"));
										$json_response_encode = json_encode($json_response_array,true);
									}
								}else{
									$json_response_array = array("desc" => ucwords("API List Is Empty, Contact Admin for Further Assistance"));
									$json_response_encode = json_encode($json_response_array,true);
								}
							}else{
								chargeVendor("debit", $product_unique_id, $type_alternative, $reference, $amount, $discounted_amount, $description, $_SERVER["HTTP_HOST"], $status);
								$json_response_array = array("desc" => ucwords("Error: Failed To Initiate Transaction"));
								$json_response_encode = json_encode($json_response_array,true);
							}
						}else{
							//Insufficient Fund
							$json_response_array = array("desc" => "Insufficient Fund");
							$json_response_encode = json_encode($json_response_array,true);
						}
					}else{
						//Non-numeric Balance
						$json_response_array = array("desc" => "Non-numeric Balance");
						$json_response_encode = json_encode($json_response_array,true);
					}
				}else{
					//Non-numeric Amount
					$json_response_array = array("desc" => "Non-numeric Amount");
					$json_response_encode = json_encode($json_response_array,true);
				}
			}else{
				//No Item In Cart
				$json_response_array = array("desc" => "No Item In Cart");
				$json_response_encode = json_encode($json_response_array,true);
			}
        }else{
            //Cart Is Empty
            $json_response_array = array("desc" => "Cart Is Empty");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
		if($marketplace_redirect == false){
        	header("Location: ".$_SERVER["REQUEST_URI"]);
        	exit;
		}else{
			header("Location: /bc-admin/MarketPlace.php");
			exit;
		}
    }
?>
<!DOCTYPE html>
<head>
    <title>Checkout Cart | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
      <h1>CART CHECKOUT</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Cart</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-cart3 me-2"></i>Shopping Cart</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <?php
                            $get_cart_items = $_COOKIE[str_replace([":","."],"_",$get_host_name)."_".$get_logged_admin_details["id"]."_cart_items"];
                            if(isset($get_cart_items) && !empty($get_cart_items)){
                                $exp_cart_items = array_filter(explode(" ",trim($get_cart_items)));

                                $count_old_cart_items = 0;
                                $count_new_cart_items = 0;
                                $count_old_cart_items_amount = 0;
                                $count_new_cart_items_amount = 0;
                                foreach($exp_cart_items as $item_id){
                                    if(is_numeric($item_id) && ($item_id > 0)){
                                        $get_active_cart_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE id='".$item_id."' && $status_statement"));

                                        if(isset($get_active_cart_details["api_type"])){
                                            $api_website = str_replace(["//www.","/","http:","https:"],"",$get_active_cart_details["api_website"]);
                                            $api_type = strtoupper(str_replace(["_","-"]," ",$get_active_cart_details["api_type"]));
                                            $product_description = checkTextEmpty($get_active_cart_details["description"]);
                                            $product_api_price = number_format($get_active_cart_details["price"], 2);

                                            $select_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_base_url='".$api_website."' && api_type='".$get_active_cart_details["api_type"]."'");
                                            $is_installed = (mysqli_num_rows($select_api_lists) > 0);

                                            if($is_installed){
                                                $count_old_cart_items += 1;
                                                $count_old_cart_items_amount += $get_active_cart_details["price"];
                                            }else{
                                                $count_new_cart_items += 1;
                                                $count_new_cart_items_amount += $get_active_cart_details["price"];
                                            }

                                            echo '
                                            <div class="col-12">
                                                <div class="d-flex align-items-center border-bottom pb-4 mb-2">
                                                    <div class="bg-primary bg-opacity-10 rounded-4 p-3 me-4 text-primary fs-3">
                                                        <i class="bi bi-cpu"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <h6 class="fw-bold mb-1">'.$api_type.' API MODULE</h6>
                                                                <p class="small text-muted mb-0">Website: https://'.$api_website.'</p>
                                                            </div>
                                                            <div class="text-end">
                                                                <div class="fw-bold text-dark fs-5">₦'.$product_api_price.'</div>
                                                                '.($is_installed ? '<span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-2" style="font-size: 10px;">ALREADY INSTALLED</span>' : '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2" style="font-size: 10px;">READY TO INSTALL</span>').'
                                                            </div>
                                                        </div>
                                                        <div class="d-flex justify-content-between align-items-center mt-3">
                                                            <button onclick="removeAPIFromCart(`'.$item_id.'`, `'.$get_logged_admin_details["id"].'`);" class="btn btn-link p-0 text-danger small text-decoration-none fw-bold"><i class="bi bi-trash3 me-1"></i>REMOVE</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>';
                                        }
                                    }
                                }
                            }else{
                                echo '
                                <div class="col-12 text-center py-5">
                                    <img src="'.$web_http_host.'/asset/ooops.gif" class="img-fluid mb-4" style="max-height: 200px;"/>
                                    <h4 class="fw-bold text-muted">Your Cart is Empty</h4>
                                    <p class="text-muted small">Go to the marketplace to discover powerful API modules.</p>
                                    <a href="MarketPlace.php" class="btn btn-primary rounded-pill px-5 mt-3 fw-bold">BROSWSE MARKETPLACE</a>
                                </div>';
                            }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0">Order Summary</h5>
                </div>
                <div class="card-body p-4">
                    <?php if($count_new_cart_items > 0 || $count_old_cart_items > 0): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">New Items (<?php echo $count_new_cart_items; ?>)</span>
                            <span class="fw-bold">₦<?php echo number_format($count_new_cart_items_amount, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Already Installed (<?php echo $count_old_cart_items; ?>)</span>
                            <span class="text-danger small" style="text-decoration: line-through;">₦<?php echo number_format($count_old_cart_items_amount, 2); ?></span>
                        </div>
                        <hr class="my-3 opacity-50">
                        <div class="d-flex justify-content-between mb-4">
                            <span class="fw-bold">Total Payable</span>
                            <span class="fw-bold text-primary fs-4">₦<?php echo number_format($count_new_cart_items_amount, 2); ?></span>
                        </div>

                        <div class="bg-light rounded-4 p-3 mb-4 border">
                            <div class="d-flex align-items-center text-muted small">
                                <i class="bi bi-wallet2 me-2"></i>
                                <span>Wallet Balance: <strong class="text-dark">₦<?php echo number_format(vendorBalance(2), 2); ?></strong></span>
                            </div>
                        </div>

                        <form method="post">
                            <button onclick="askPermissionSubBtn(this,'Proceed to purchase these API modules with your wallet balance?');"
                                    name="checkout-cart" type="button"
                                    class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-sm"
                                    <?php echo (vendorBalance(2) < $count_new_cart_items_amount || $count_new_cart_items == 0) ? 'disabled' : ''; ?>>
                                <i class="bi bi-shield-check me-2"></i>PAY WITH WALLET
                            </button>
                            <?php if(vendorBalance(2) < $count_new_cart_items_amount): ?>
                                <div class="alert alert-danger border-0 small mt-3 rounded-3 py-2 text-center">
                                    <i class="bi bi-exclamation-triangle me-1"></i> Insufficient balance to checkout.
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <p class="text-muted text-center small py-3">No items to process.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info border-0 rounded-4 p-4 shadow-sm">
                <h6 class="fw-bold"><i class="bi bi-info-circle me-2"></i>How it works</h6>
                <p class="small mb-0 opacity-75">Purchased API modules are automatically added to your "API Settings" page. You can configure your keys there after a successful payment.</p>
            </div>
        </div>
      </div>
    </section>
		
        
    <?php include("../func/bc-admin-footer.php"); ?>
    
</body>
</html>
