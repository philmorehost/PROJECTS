<?php session_start();
    include("../func/bc-admin-config.php");

    if(isset($_GET["action"]) && isset($_GET["product_id"]) && isset($_GET["api_id"])){
        $action = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["action"])));
        $product_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["product_id"])));
        $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["api_id"])));
        $pricing_tables = ['sas_smart_parameter_values', 'sas_agent_parameter_values', 'sas_api_parameter_values'];
    
        if($action == "enable" || $action == "disable"){
            $new_status = ($action == "enable") ? 1 : 0;
            foreach($pricing_tables as $table){
                mysqli_query($connection_server, "UPDATE $table SET status='$new_status' WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$product_id' && api_id='$api_id'");
            }
            $_SESSION["product_purchase_response"] = "Package status updated successfully.";
    
        } elseif($action == "delete"){
            foreach($pricing_tables as $table){
                mysqli_query($connection_server, "DELETE FROM $table WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$product_id' && api_id='$api_id'");
            }
            $_SESSION["product_purchase_response"] = "Package deleted successfully.";
    
        } else {
            $_SESSION["product_purchase_response"] = "Invalid action.";
        }
        header("Location: Airtime.php");
        exit();
    }
        
    if(isset($_POST["update-key"])){
        $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-id"])));
        $apikey = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-key"])));
        $apistatus = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-status"])));
        
        if(!empty($api_id) && is_numeric($api_id)){
            if(!empty($apikey)){
            	if(is_numeric($apistatus) && in_array($apistatus, array("0", "1"))){
            		$select_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id' && api_type='airtime'");
                	if(mysqli_num_rows($select_api_lists) == 1){
                    	mysqli_query($connection_server, "UPDATE sas_apis SET api_key='$apikey', status='$apistatus' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id' && api_type='airtime'");
                    	//APIkey Updated Successfully
                    	$json_response_array = array("desc" => "APIkey Updated Successfully");
                    	$json_response_encode = json_encode($json_response_array,true);
                	}else{
                    	//API Doesnt Exists
                    	$json_response_array = array("desc" => "API Doesnt Exists");
                    	$json_response_encode = json_encode($json_response_array,true);
                	}
                }else{
                	//Invalid API Status
                	$json_response_array = array("desc" => "Invalid API Status");
                	$json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                //Apikey Field Empty
                $json_response_array = array("desc" => "Apikey Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Invalid Apikey Website
            $json_response_array = array("desc" => "Invalid Apikey Website");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    include_once("../func/bc-product-actions.php");
    handle_product_actions($connection_server, $get_logged_admin_details);

    // ─── AI Security Sentinel Hook ───────────────────────────────────────────────
    // Non-breaking: silently skipped if AI is off or sentinel not loaded.
    if (function_exists('ai_sentinel_evaluate') && !empty($get_logged_admin_details['ai_status'])) {
        $s_username  = $_SESSION['admin_session'] ?? '';
        $s_vendor_id = (int)($get_logged_admin_details['id'] ?? 0);
        $s_amount    = (float)($_POST['amount'] ?? $_GET['amount'] ?? 0);
        $sentinel_decision = ai_sentinel_evaluate($s_username, $s_vendor_id, 'airtime', $s_amount);
        if ($sentinel_decision === 'BLOCK') {
            $_SESSION['product_purchase_response'] = '🔒 Transaction blocked by AI Security Sentinel. Contact your administrator.';
            header('Location: Airtime.php'); exit();
            exit;
        }
        if ($sentinel_decision === 'FLAG_FOR_APPROVAL') {
            $_SESSION['product_purchase_response'] = '⏳ This transaction has been flagged for manual review. Please try again shortly.';
            header('Location: Airtime.php'); exit();
            exit;
        }
    }
    // ─────────────────────────────────────────────────────────────────────────────

    if(isset($_POST["clear-all-plans"])){
        $vid = $get_logged_admin_details["id"];
        $api_q = mysqli_query($connection_server, "SELECT id FROM sas_apis WHERE vendor_id='$vid' AND api_type='airtime'");
        $api_ids = [];
        while($r = mysqli_fetch_assoc($api_q)) $api_ids[] = $r['id'];

        if(!empty($api_ids)){
            $api_list = implode(',', $api_ids);
            mysqli_query($connection_server, "DELETE FROM sas_smart_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            mysqli_query($connection_server, "DELETE FROM sas_agent_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            mysqli_query($connection_server, "DELETE FROM sas_api_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            $_SESSION["product_purchase_response"] = "All Airtime plans cleared successfully.";
        } else {
            $_SESSION["product_purchase_response"] = "No Airtime API configured to clear plans for.";
        }
        header("Location: Airtime.php");
        exit();
    }

    if(isset($_POST["install-product"])){
        $products_array = array("mtn", "airtel", "glo", "9mobile");
        $account_level_table_name_arrays = array("sas_smart_parameter_values", "sas_agent_parameter_values", "sas_api_parameter_values");
        install_product($connection_server, $get_logged_admin_details, "airtime", "sas_airtime_status", $products_array, array(), $account_level_table_name_arrays);
    }

            
    if(isset($_POST["update-price"])){
        $api_id_array = $_POST["api-id"];
        $product_id_array = $_POST["product-id"];
        $product_name_array = $_POST["product-name"];
        $smart_price_array = $_POST["smart-price"];
        $agent_price_array = $_POST["agent-price"];
        $api_price_array = $_POST["api-price"];
        $account_level_table_name_arrays = array("sas_smart_parameter_values", "sas_agent_parameter_values", "sas_api_parameter_values");
        if(count($api_id_array) == count($product_id_array)){
        	foreach($api_id_array as $index => $api_id){
        		$api_id = $api_id_array[$index];
        		$product_id = $product_id_array[$index];
                $product_name_4 = mysqli_real_escape_string($connection_server, $product_name_array[$index]);
        		$smart_price = $smart_price_array[$index];
        		$agent_price = $agent_price_array[$index];
        		$api_price = $api_price_array[$index];
                $get_selected_api_list = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id'"));
                $select_api_list_with_api_type = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='".$get_selected_api_list["api_type"]."'");
                if(mysqli_num_rows($select_api_list_with_api_type) > 0){
                    while($refined_api_id = mysqli_fetch_assoc($select_api_list_with_api_type)){
                        $smart_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[0]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id'");                    		
                        if(mysqli_num_rows($smart_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[0]." (vendor_id, api_id, product_id, val_1, val_4) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$smart_price', '$product_name_4')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[0]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$smart_price', val_4='$product_name_4' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id'");
                        }
                        
                        $agent_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[1]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id'");                    		
                        if(mysqli_num_rows($agent_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[1]." (vendor_id, api_id, product_id, val_1, val_4) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$agent_price', '$product_name_4')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[1]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$agent_price', val_4='$product_name_4' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id'");
                        }
                        
                        $api_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[2]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id'");                    		
                        if(mysqli_num_rows($api_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[2]." (vendor_id, api_id, product_id, val_1, val_4) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$api_price', '$product_name_4')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[2]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$api_price', val_4='$product_name_4' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id'");
                        }
                    }
                }
        	}
        	//Price Updated Successfully
        	$json_response_array = array("desc" => "Price Updated Successfully");
        	$json_response_encode = json_encode($json_response_array,true);
        }else{
            //Product Connection Error
            $json_response_array = array("desc" => "Product Connection Error");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
    $csv_price_level_array = [];
    $csv_price_level_array[] = "product_name,smart_level,agent_level,api_level";
?>
<!DOCTYPE html>
<head>
    <title>Airtime API | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
      <h1>AIRTIME API</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Airtime</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4">
        <?php
            $is_fetcher_allowed = false;
            $current_api_type = 'airtime';
            // Only these gateways actually support live plan/price fetching -- every other configured
            // API has no fetch endpoint, so it's deliberately excluded from this dropdown rather than
            // listed and left to fail when selected.
            $installed_gateways = [];
            $check_fetcher_query = mysqli_query($connection_server, "SELECT id, api_base_url FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' AND api_type='$current_api_type'");
            while($api_row = mysqli_fetch_assoc($check_fetcher_query)){
                $url = $api_row['api_base_url'];
                if(stripos($url, 'vtpass.com') !== false) {
                    $installed_gateways['vtpass'] = 'VTPASS';
                    $is_fetcher_allowed = true;
                } elseif(stripos($url, 'clubkonnect.com') !== false || stripos($url, 'nellobytesystems.com') !== false) {
                    $installed_gateways['clubkonnect'] = 'CLUBKONNECT';
                    $is_fetcher_allowed = true;
                } elseif(stripos($url, 'v6.datagifting.com.ng') !== false) {
                    $installed_gateways[$url] = 'V6.DATAGIFTING.COM.NG';
                    $is_fetcher_allowed = true;
                }
            }
        ?>
        <?php if($is_fetcher_allowed): ?>
        <!-- Variation Fetcher Card -->
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Airtime Plan Variation Fetcher</h5>
                    <button class="btn btn-sm btn-outline-info rounded-pill px-3" type="button" data-bs-toggle="collapse" data-bs-target="#fetcherGuide">
                        <i class="bi bi-info-circle me-1"></i> How to use
                    </button>
                </div>
                <div class="card-body p-4">
                    <div class="collapse mb-3" id="fetcherGuide">
                        <div class="alert alert-info border-0 shadow-sm rounded-4 mb-0">
                            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-lightbulb me-2"></i>Usage Guide:</h6>
                            <ol class="small mb-0 text-dark">
                                <li>Select the <strong>Network</strong> and <strong>Gateway</strong> provider.</li>
                                <li>Enter your desired <strong>Adjustment</strong> percentages (e.g., set Smart % to 3 to apply a 3% discount to the vendor rate).</li>
                                <li>Click <strong>Fetch Plans</strong> to load the latest discount settings from the provider.</li>
                                <li>Click <strong>Apply</strong> on a plan to update the discount field in the pricing table below, or click <strong>Apply All</strong> to save directly to your database.</li>
                                <li><strong>Important:</strong> If you use "Apply" for individual plans, remember to click <strong>Save All Changes</strong> at the bottom of the pricing table to permanently save them.</li>
                            </ol>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Select Network</label>
                            <select id="fetch-network" class="form-select">
                                <option value="mtn">MTN</option>
                                <option value="airtel">AIRTEL</option>
                                <option value="glo">GLO</option>
                                <option value="9mobile">9MOBILE</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Select Gateway</label>
                            <select id="fetch-gateway" class="form-select">
                                <?php foreach($installed_gateways as $val => $name): ?>
                                    <option value="<?php echo $val; ?>"><?php echo $name; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-bold">Prov. %</label>
                            <input type="number" id="provider-disc" class="form-control" value="0" step="any">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-bold">Smart %</label>
                            <input type="number" id="smart-disc" class="form-control" value="0" step="any">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-bold">Agent %</label>
                            <input type="number" id="agent-disc" class="form-control" value="0" step="any">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label small fw-bold">API %</label>
                            <input type="number" id="api-disc" class="form-control" value="0" step="any">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" onclick="fetchVariations();" class="btn btn-primary w-100 fw-bold">Fetch Plans</button>
                        </div>
                    </div>

                    <div id="fetch-results" class="mt-4" style="display:none;">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Network</th><th>Code</th><th>Vendor Rate (%)</th><th>Your Rate (%)</th><th>Margin</th><th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="fetch-tbody"></tbody>
                            </table>
                        </div>
                        <div class="mt-3 text-end">
                            <button type="button" onclick="applyAllFetched();" class="btn btn-success fw-bold">Apply All to Pricing Table</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- API Setting & Installation Column -->
        <div class="col-lg-4">
          <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title mb-0 text-danger"><i class="bi bi-trash me-2"></i>Danger Zone</h5>
            </div>
            <div class="card-body p-4 text-center">
                <p class="small text-muted mb-4">Wipe all Airtime plans from the pricing table. Use this when switching API providers.</p>
                <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear ALL Airtime pricing plans? This cannot be undone.');">
                    <button name="clear-all-plans" type="submit" class="btn btn-outline-danger w-100 rounded-3 fw-bold">
                        <i class="bi bi-eraser-fill me-2"></i>Clear All Airtime Plans
                    </button>
                </form>
            </div>
          </div>
          <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title mb-0"><i class="bi bi-gear-wide-connected me-2 text-primary"></i>API Setting</h5>
            </div>
            <div class="card-body p-4">
              <form method="post" action="">
                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Gateway API</label>
                    <select id="" name="api-id" onchange="getWebApikey(this);" class="form-select rounded-3" required>
                        <?php
                            $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='airtime'");
                            if(mysqli_num_rows($get_api_lists) >= 1){
                                echo '<option value="" default hidden selected>Choose API</option>';
                                while($api_details = mysqli_fetch_assoc($get_api_lists)){
                                    $apikey_status = empty(trim($api_details["api_key"])) ? "(Empty Key)" : "";
                                    echo '<option value="'.$api_details["id"].'" api-key="'.$api_details["api_key"].'" api-status="'.$api_details["status"].'">'.strtoupper($api_details["api_base_url"]).' '.$apikey_status.'</option>';
                                }
                            } else {
                                echo '<option value="" disabled>No API available</option>';
                            }
                        ?>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select id="web-apikey-status" name="api-status" class="form-select rounded-3" required>
                        <option value="" default hidden selected>Select Status</option>
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                  </div>
                  <div class="mb-4">
                    <label class="form-label small fw-bold text-muted text-uppercase">Secret Key</label>
                    <input id="web-apikey-input" name="api-key" type="text" placeholder="Paste API Key here" class="form-control rounded-3" required/>
                  </div>
                  <button name="update-key" type="submit" class="btn btn-primary w-100 rounded-3 fw-bold py-2 shadow-sm">
                      <i class="bi bi-save me-2"></i>Update Key
                  </button>
              </form>
            </div>
          </div>

          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>Product Installation</h5>
            </div>
            <div class="card-body p-4 text-center">
              <div class="mb-4">
                <button type="button" class="btn btn-light btn-sm w-100 mb-3 border rounded-pill" onclick="tickProduct(this, 'all', 'api-product-name', 'install-product', 'png');">Toggle All Networks</button>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <img alt="Airtel" id="airtel-lg" src="/asset/airtel.png" onclick="tickProduct(this, 'airtel', 'api-product-name', 'install-product', 'png');" class="rounded-3 border p-1 cursor-pointer" style="width: 60px; height: 60px; object-fit: contain;"/>
                    <img alt="MTN" id="mtn-lg" src="/asset/mtn.png" onclick="tickProduct(this, 'mtn', 'api-product-name', 'install-product', 'png');" class="rounded-3 border p-1 cursor-pointer" style="width: 60px; height: 60px; object-fit: contain;"/>
                    <img alt="Glo" id="glo-lg" src="/asset/glo.png" onclick="tickProduct(this, 'glo', 'api-product-name', 'install-product', 'png');" class="rounded-3 border p-1 cursor-pointer" style="width: 60px; height: 60px; object-fit: contain;"/>
                    <img alt="9mobile" id="9mobile-lg" src="/asset/9mobile.png" onclick="tickProduct(this, '9mobile', 'api-product-name', 'install-product', 'png');" class="rounded-3 border p-1 cursor-pointer" style="width: 60px; height: 60px; object-fit: contain;"/>
                </div>
              </div>
              <form method="post" action="">
                  <input id="api-product-name" name="product-name" type="text" hidden required/>
                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Assign to Gateway</label>
                    <select name="api-id" class="form-select rounded-3" required>
                        <?php
                            $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='airtime'");
                            if(mysqli_num_rows($get_api_lists) >= 1){
                                echo '<option value="" default hidden selected>Choose API</option>';
                                while($api_details = mysqli_fetch_assoc($get_api_lists)) echo '<option value="'.$api_details["id"].'">'.strtoupper($api_details["api_base_url"]).'</option>';
                            }
                        ?>
                    </select>
                  </div>
                  <div class="mb-4">
                    <label class="form-label small fw-bold text-muted text-uppercase">Initial Status</label>
                    <select name="item-status" class="form-select rounded-3" required>
                        <option value="1">Enabled</option>
                        <option value="0">Disabled</option>
                    </select>
                  </div>
                  <button id="install-product" name="install-product" type="submit" class="btn btn-outline-primary w-100 rounded-3 fw-bold border-2" style="pointer-events: none; opacity: 0.6;">
                      <i class="bi bi-cloud-download me-2"></i>Install Service
                  </button>
              </form>
            </div>
          </div>
        </div>

        <!-- Tables Column -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-primary">Installed Networks Status</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Network</th><th>Gateway Route</th><th>Status</th><th class="pe-4 text-end">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                            $item_name_array = array("mtn", "airtel", "glo", "9mobile");
                            $items_statement = "product_name IN ('" . implode("','", $item_name_array) . "')";
                            $select_item_lists = mysqli_query($connection_server, "SELECT * FROM sas_airtime_status WHERE vendor_id='".$get_logged_admin_details["id"]."' && $items_statement");
                            if(mysqli_num_rows($select_item_lists) >= 1){
                                while($list_details = mysqli_fetch_assoc($select_item_lists)){
                                    $api_q = mysqli_query($connection_server, "SELECT api_base_url FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$list_details["api_id"]."' LIMIT 1");
                                    $api_route = ($row = mysqli_fetch_assoc($api_q)) ? strtoupper($row["api_base_url"]) : "None";
                                    $status_badge = ($list_details["status"] == 1) ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Active</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3">Inactive</span>';

                                    echo '
                                    <tr>
                                        <td class="ps-4 fw-bold">'.strtoupper($list_details["product_name"]).'</td>
                                        <td class="small text-muted">'.$api_route.'</td>
                                        <td>'.$status_badge.'</td>
                                        <td class="pe-4 text-end">'.render_action_buttons($list_details["product_name"], "airtime", $list_details["status"]).'</td>
                                    </tr>';
                                }
                            }
                        ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        
        
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-primary">Airtime Commissions (%)</h6>
                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3" type="button" data-bs-toggle="collapse" data-bs-target="#bulkPriceCollapse">
                        <i class="bi bi-lightning me-1"></i> Bulk Update
                    </button>
                </div>
                <div class="card-body p-4">
                    <div class="collapse mb-4" id="bulkPriceCollapse">
                        <div class="bg-light p-3 rounded-3 border">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-5"><input id="price-upgrade-input" type="number" placeholder="Value" class="form-control"></div>
                                <div class="col-md-5">
                                    <select id="price-upgrade-type" class="form-select">
                                        <option value="amount+">Increase By Amount</option>
                                        <option value="amount-">Decrease By Amount</option>
                                        <option value="percent+">Increase By %</option>
                                        <option value="percent-">Decrease By %</option>
                                    </select>
                                </div>
                                <div class="col-md-2"><button onclick="upgradeePriceDiscount();" class="btn btn-primary w-100">Apply</button></div>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="">
                        <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                            <tr>
                                <th>Service</th><th>Smart (%)</th><th>Agent (%)</th><th>API (%)</th><th>Status</th><th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                                <?php
                                    foreach($item_name_array as $products){
                                        $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_airtime_status WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name='$products'"));
                                        $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name='$products' LIMIT 1"));
                                        $product_smart_table = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".($get_item_status_details["api_id"] ?? "")."' && product_id='".($product_table["id"] ?? "")."'");
                                        $product_agent_table = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".($get_item_status_details["api_id"] ?? "")."' && product_id='".($product_table["id"] ?? "")."'");
                                        $product_api_table = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".($get_item_status_details["api_id"] ?? "")."' && product_id='".($product_table["id"] ?? "")."'");

                                        if(mysqli_num_rows($product_smart_table) > 0){
                                            while(($product_smart_details = mysqli_fetch_assoc($product_smart_table)) && ($product_agent_details = mysqli_fetch_assoc($product_agent_table)) && ($product_api_details = mysqli_fetch_assoc($product_api_table))){
                                                $status_badge = ($product_smart_details['status'] == 1) ? '<span class="text-success small fw-bold">Active</span>' : '<span class="text-muted small fw-bold">Hidden</span>';
                                                $action_link = ($product_smart_details['status'] == 1) ? 'disable' : 'enable';
                                                $action_btn_class = ($product_smart_details['status'] == 1) ? 'text-warning' : 'text-primary';

                                                $package_display_name = !empty($product_smart_details["val_4"]) ? $product_smart_details["val_4"] : strtoupper($products);

                                                echo '
                                                <tr>
                                                    <td class="fw-bold">
                                                        <span class="package-name-text">'.$package_display_name.'</span>
                                                        <input name="api-id[]" type="hidden" value="'.$product_smart_details["api_id"].'"/>
                                                        <input name="product-id[]" type="hidden" value="'.$product_smart_details["product_id"].'"/>
                                                        <input name="product-name[]" type="hidden" class="product-name-val" value="'.$product_smart_details["val_4"].'"/>
                                                    </td>
                                                    <td><input id="'.strtolower($products).'_smart_level" name="smart-price[]" type="number" step="any" value="'.$product_smart_details["val_1"].'" class="form-control form-control-sm text-center" style="max-width:80px"></td>
                                                    <td><input id="'.strtolower($products).'_agent_level" name="agent-price[]" type="number" step="any" value="'.$product_agent_details["val_1"].'" class="form-control form-control-sm text-center" style="max-width:80px"></td>
                                                    <td><input id="'.strtolower($products).'_api_level" name="api-price[]" type="number" step="any" value="'.$product_api_details["val_1"].'" class="form-control form-control-sm text-center" style="max-width:80px"></td>
                                                    <td>'.$status_badge.'</td>
                                                    <td class="text-end pe-3">
                                                        <a href="Airtime.php?action='.$action_link.'&product_id='.$product_smart_details["product_id"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-sm btn-light border '.$action_btn_class.'"><i class="bi bi-power"></i></a>
                                                        <a href="Airtime.php?action=delete&product_id='.$product_smart_details["product_id"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-sm btn-light border text-danger" onclick="return confirm(\'Delete this package?\')"><i class="bi bi-trash"></i></a>
                                                    </td>
                                                </tr>';
                                                $csv_price_level_array[] = strtolower($products).",".$product_smart_details["val_1"].",".$product_agent_details["val_1"].",".$product_api_details["val_1"];
                                            }
                                        }
                                    }
                                ?>
                            </tbody>
                        </table>
                        </div>
                        <div class="mt-4 pt-4 border-top">
                            <button name="update-price" type="submit" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow-sm">Save All Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-primary">Bulk Upload via CSV</h6>
                    <a href="javascript:void(0)" onclick='downloadFile(`<?php echo implode("\n",$csv_price_level_array); ?>`, "airtime.csv");' class="btn btn-sm btn-light border"><i class="bi bi-download me-1"></i>Sample Template</a>
                </div>
                <div class="card-body p-4">
                    <form method="post" enctype="multipart/form-data">
                        <div class="input-group">
                            <input id="csv-chooser" type="file" class="form-control rounded-start-3" required/>
                            <button data-no-lock onclick="getCSVDetails('4');" type="button" class="btn btn-primary px-4 fw-bold">Process CSV Upload</button>
                        </div>
                        <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Use the sample template above to format your data correctly before uploading.</p>
                    </form>
                </div>
            </div>
        </div>
      </div>
        
        </div>
      </div>
    </section>
	<?php include("../func/bc-admin-footer.php"); ?>

    <?php if($is_fetcher_allowed): ?>
    <script>
    let fetchedPlans = [];

    async function fetchVariations() {
        const network = document.getElementById('fetch-network').value;
        const gateway = document.getElementById('fetch-gateway').value;
        const pDisc = parseFloat(document.getElementById('provider-disc').value) || 0;
        const sDisc = parseFloat(document.getElementById('smart-disc').value) || 0;
        const aDisc = parseFloat(document.getElementById('agent-disc').value) || 0;
        const apiDisc = parseFloat(document.getElementById('api-disc').value) || 0;

        const resultsDiv = document.getElementById('fetch-results');
        const tbody = document.getElementById('fetch-tbody');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-primary"></div><br>Fetching latest plans...</td></tr>';
        resultsDiv.style.display = 'block';

        try {
            const response = await fetch(`ajax-fetch-plans.php?gateway=${encodeURIComponent(gateway)}&network=${encodeURIComponent(network)}&type=airtime`);
            const data = await response.json();

            if(!data.success) throw new Error(data.message || 'Fetch failed');

            fetchedPlans = data.plans;
            tbody.innerHTML = '';

            fetchedPlans.forEach((plan, index) => {
                const vendorRate = parseFloat(plan.price);
                const costRate = vendorRate * (1 - (pDisc / 100));

                plan.smartPrice = vendorRate * (1 - (sDisc / 100));
                plan.agentPrice = vendorRate * (1 - (aDisc / 100));
                plan.apiPrice = vendorRate * (1 - (apiDisc / 100));

                const margin = plan.smartPrice - costRate;

                const row = `
                    <tr>
                        <td class="fw-bold">${plan.name}</td>
                        <td><code>${plan.code}</code></td>
                        <td>${vendorRate.toFixed(2)}%</td>
                        <td class="text-primary fw-bold">${plan.smartPrice.toFixed(2)}%</td>
                        <td class="text-success fw-bold">${margin.toFixed(2)}%</td>
                        <td>
                            <button onclick="applySinglePlan(${index})" class="btn btn-sm btn-outline-primary">Apply</button>
                        </td>
                    </tr>
                `;
                tbody.insertAdjacentHTML('beforeend', row);
            });

        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4"><i class="bi bi-exclamation-triangle fs-2"></i><br>${err.message}</td></tr>`;
        }
    }

    function applySinglePlan(index) {
        const plan = fetchedPlans[index];
        const network = document.getElementById('fetch-network').value;
        const inputId = `${network.replace(/-/g, '_')}_smart_level`;
        const input = document.getElementById(inputId);
        if(input) {
            input.value = plan.smartPrice.toFixed(2);
            const agentInput = document.getElementById(inputId.replace('smart_level', 'agent_level'));
            const apiInput = document.getElementById(inputId.replace('smart_level', 'api_level'));
            if(agentInput) agentInput.value = plan.agentPrice.toFixed(2);
            if(apiInput) apiInput.value = plan.apiPrice.toFixed(2);

            const row = input.closest('tr');
            const nameText = row ? row.querySelector('.package-name-text') : null;
            const nameInput = row ? row.querySelector('.product-name-val') : null;
            if(nameText) nameText.innerText = plan.name;
            if(nameInput) nameInput.value = plan.name;

            alert(`Applied ${plan.name} discount rate to pricing table. Remember to click Save All Changes.`);
        } else {
            alert(`Network ${network.toUpperCase()} not found in current installation. Install it first.`);
        }
    }

    async function applyAllFetched() {
        const network = document.getElementById('fetch-network').value;
        const api_id = document.querySelector('select[name="api-id"]').value;

        if(!api_id) {
            alert("Please select an API Gateway first in the API Setting card.");
            return;
        }

        if(!confirm(`This will save/update ${fetchedPlans.length} plan(s) for ${network.toUpperCase()} to the database. Continue?`)) return;

        try {
            const response = await fetch('ajax-save-plans.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    network: network,
                    api_id: api_id,
                    type: 'airtime',
                    plans: fetchedPlans
                })
            });
            const data = await response.json();
            if(data.success) {
                alert(data.message);
                location.reload();
            } else {
                throw new Error(data.message);
            }
        } catch (err) {
            alert("Error saving plans: " + err.message);
        }
    }
    </script>
    <?php endif; ?>
</body>
</html>
