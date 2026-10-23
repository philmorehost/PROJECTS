<?php session_start();
    include("../func/bc-admin-config.php");

    if(isset($_GET["action"]) && isset($_GET["product_id"]) && isset($_GET["val_1"]) && isset($_GET["api_id"])){
        $action = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["action"])));
        $product_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["product_id"])));
        $val_1 = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["val_1"])));
        $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["api_id"])));
        $pricing_tables = ['sas_smart_parameter_values', 'sas_agent_parameter_values', 'sas_api_parameter_values'];
    
        if($action == "enable" || $action == "disable"){
            $new_status = ($action == "enable") ? 1 : 0;
            foreach($pricing_tables as $table){
                mysqli_query($connection_server, "UPDATE $table SET status='$new_status' WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$product_id' && val_1='$val_1' && api_id='$api_id'");
            }
            $_SESSION["product_purchase_response"] = "Package status updated successfully.";
    
        } elseif($action == "delete"){
            foreach($pricing_tables as $table){
                mysqli_query($connection_server, "DELETE FROM $table WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$product_id' && val_1='$val_1' && api_id='$api_id'");
            }
            $_SESSION["product_purchase_response"] = "Package deleted successfully.";
    
        } else {
            $_SESSION["product_purchase_response"] = "Invalid action.";
        }
        header("Location: DirectData.php");
        exit();
    }
        
    if(isset($_POST["update-key"])){
        $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-id"])));
        $apikey = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-key"])));
        $apistatus = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-status"])));
        
        if(!empty($api_id) && is_numeric($api_id)){
            if(!empty($apikey)){
                if(is_numeric($apistatus) && in_array($apistatus, array("0", "1"))){
                    $select_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id' && api_type='dd-data'");
                    if(mysqli_num_rows($select_api_lists) == 1){
                        mysqli_query($connection_server, "UPDATE sas_apis SET api_key='$apikey', status='$apistatus' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id' && api_type='dd-data'");
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

    if(isset($_POST["install-product"])){
        $products_array = array("mtn", "airtel", "glo", "9mobile");
        $product_varieties = array(
            "mtn" => array("110mb_awoof_1day","230mb_awoof_1day","500mb_awoof_1day","1gb_1.5mins_awoof_1day","2.5gb_awoof_2days","3.2gb_awoof_2days","1gb_weekly","11gb_weekly","2gb_monthly","2.7gb_10mins_monthly","3.5gb_monthly","7gb_monthly","10gb_10mins_monthly","12.5gb_monthly","16.5gb_plus_10mins_monthly","20gb_monthly","25gb_monthly","36gb_30days","75gb_30days","165gb_30days","150gb_60days","480gb_90days"),
            "airtel" => array("1gb_awoof_2days","1.5gb_awoof_2days","2gb_awoof_2days","3gb_awoof_2days","5gb_awoof_2days","500mb_7days","1gb_7days","1.5gb_7days","3.5gb_7days","6gb_7days","10gb_7days","18gb_7days","2gb_30days","3gb_30days","4gb_30days","8gb_30days","10gb_30days","13gb_30days","18gb_30days","25gb_30days","35gb_30days","60gb_30days","100gb_30days","160gb_30days","210gb_30days","300gb_30days","350gb_30days"),
            "glo" => array("875mb_awoof_weekend_sun","2.5gb_awoof_weekend_sat-sun","125mb_awoof_1day","2gb_awoof_1day","260mb_awoof_2days","6gb_7days","1.5gb_14days","2.6gb_30days","5gb_30days","6.15gb_30days","7.5gb_30days","10gb_30days","12.5gb_30days","16gb_30days","28gb_30days","38gb_30days","64gb_30days","107gb_30days","165gb_30days","220gb_30days","320gb_30days","380gb_30days","475gb_30days"),
            "9mobile" => array("100mb_awoof_1day","180mb_awoof_1day","250mb_awoof_1day","450mb_awoof_1day","650mb_awoof_3days","1.75gb_7days","650mb_14days","1.1gb_30days","1.4gb_30days","2.44gb_30days","3.17gb_30days","3.91gb_30days","5.10_30days","6.5gb_30days","16gb_30days","24.3gb_30days","26.5gb_30days","39gb_60days","78gb_90days","190gb_180days")
        );
        $account_level_table_name_arrays = array("sas_smart_parameter_values", "sas_agent_parameter_values", "sas_api_parameter_values");
        install_product($connection_server, $get_logged_admin_details, "dd-data", "sas_dd_data_status", $products_array, $product_varieties, $account_level_table_name_arrays);
    }

    if(isset($_POST["clear-all-plans"])){
        $vid = $get_logged_admin_details["id"];
        $api_q = mysqli_query($connection_server, "SELECT id FROM sas_apis WHERE vendor_id='$vid' AND api_type='dd-data'");
        $api_ids = [];
        while($r = mysqli_fetch_assoc($api_q)) $api_ids[] = $r['id'];

        if(!empty($api_ids)){
            $api_list = implode(',', $api_ids);
            mysqli_query($connection_server, "DELETE FROM sas_smart_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            mysqli_query($connection_server, "DELETE FROM sas_agent_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            mysqli_query($connection_server, "DELETE FROM sas_api_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            $_SESSION["product_purchase_response"] = "All Direct Data plans cleared successfully.";
        } else {
            $_SESSION["product_purchase_response"] = "No Direct Data API configured to clear plans for.";
        }
        header("Location: DirectData.php");
        exit();
    }

    if(isset($_POST["update-price"])){
        $api_id_array = $_POST["api-id"];
        $product_id_array = $_POST["product-id"];
        $product_code_1_array = $_POST["product-code-1"];
        $product_name_array = $_POST["product-name"];
        $product_days_array = $_POST["product-days"];
        $smart_price_array = $_POST["smart-price"];
        $agent_price_array = $_POST["agent-price"];
        $api_price_array = $_POST["api-price"];
        $account_level_table_name_arrays = array("sas_smart_parameter_values", "sas_agent_parameter_values", "sas_api_parameter_values");
        if(count($api_id_array) == count($product_id_array)){
            foreach($api_id_array as $index => $api_id){
                $api_id = $api_id_array[$index];
                $product_id = $product_id_array[$index];
                $product_code_1 = $product_code_1_array[$index];
                $product_name_4 = mysqli_real_escape_string($connection_server, $product_name_array[$index]);
                $product_days = $product_days_array[$index];
                $smart_price = $smart_price_array[$index];
                $agent_price = $agent_price_array[$index];
                $api_price = $api_price_array[$index];
                $get_selected_api_list = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id'"));
                $select_api_list_with_api_type = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='".$get_selected_api_list["api_type"]."'");
                if(mysqli_num_rows($select_api_list_with_api_type) > 0){
                    while($refined_api_id = mysqli_fetch_assoc($select_api_list_with_api_type)){
                        $smart_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[0]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");                          
                        if(mysqli_num_rows($smart_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[0]." (vendor_id, api_id, product_id, val_1, val_2, val_3, val_4) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$product_code_1', '$smart_price', '$product_days', '$product_name_4')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[0]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$product_code_1', val_2='$smart_price', val_3='$product_days', val_4='$product_name_4' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");
                        }
                        
                        $agent_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[1]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");                          
                        if(mysqli_num_rows($agent_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[1]." (vendor_id, api_id, product_id, val_1, val_2, val_3, val_4) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$product_code_1', '$agent_price', '$product_days', '$product_name_4')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[1]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$product_code_1', val_2='$agent_price', val_3='$product_days', val_4='$product_name_4' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");
                        }
                        
                        $api_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[2]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");                            
                        if(mysqli_num_rows($api_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[2]." (vendor_id, api_id, product_id, val_1, val_2, val_3, val_4) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$product_code_1', '$api_price', '$product_days', '$product_name_4')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[2]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$product_code_1', val_2='$api_price', val_3='$product_days', val_4='$product_name_4' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");
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
    $csv_price_level_array[] = "product_name,smart_level,agent_level,api_level,days";
    
?>
<!DOCTYPE html>
<head>
    <title>Direct Data API | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
      <h1>DIRECT DATA API</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Direct Data</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4">
        <?php
            $is_fetcher_allowed = false;
            $current_api_type = 'dd-data';
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
                    <h5 class="card-title mb-0"><i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Package Plan Variation Fetcher</h5>
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
                                <li>Enter your desired <strong>Discount/Profit</strong> percentages. <strong>Note:</strong> Negative pricing logic is used to fetch the correct price with your Markup profit (e.g., use -1% for Smart, -1.5% for Agent, and -1.5% for API).</li>
                                <li>Click <strong>Fetch Plans</strong> to load the latest available packages from the provider.</li>
                                <li>Click <strong>Apply</strong> on a single plan to preview it in the pricing table below, or click <strong>Apply All</strong> to save all fetched plans directly to your database.</li>
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
                                        <th>Plan Name</th><th>Code</th><th>Provider Price</th><th>New Price</th><th>Profit</th><th>Validity</th><th>Action</th>
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
                <p class="small text-muted mb-4">Wipe all Direct Data plans from the pricing table. Use this when switching API providers.</p>
                <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear ALL Direct Data pricing plans? This cannot be undone.');">
                    <button name="clear-all-plans" type="submit" class="btn btn-outline-danger w-100 rounded-3 fw-bold">
                        <i class="bi bi-eraser-fill me-2"></i>Clear All DD Plans
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
                            $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='dd-data'");
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
                <button type="button" class="btn btn-info btn-sm w-100 mb-3 rounded-pill text-white fw-bold" onclick="tickProduct(this, 'all', 'api-product-name', 'install-product', 'png');">Select All Carriers</button>
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
                            $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='dd-data'");
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
                    <h6 class="fw-bold mb-0 text-primary">Installed Carrier Status</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Carrier</th><th>Gateway Route</th><th>Status</th><th class="pe-4 text-end">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                            $item_name_array = array("mtn", "airtel", "glo", "9mobile");
                            $items_statement = "product_name IN ('" . implode("','", $item_name_array) . "')";
                            $select_item_lists = mysqli_query($connection_server, "SELECT * FROM sas_dd_data_status WHERE vendor_id='".$get_logged_admin_details["id"]."' && $items_statement");
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
                                        <td class="pe-4 text-end">'.render_action_buttons($list_details["product_name"], "dd-data", $list_details["status"]).'</td>
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
                    <h6 class="fw-bold mb-0 text-primary">Direct Data Package Pricing (₦)</h6>
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
                                <th>Package Name</th><th>Smart (₦)</th><th>Agent (₦)</th><th>API (₦)</th><th>Days</th><th>Status</th><th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                                <?php
                                    foreach($item_name_array as $products){
                                        $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_dd_data_status WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name='$products'"));
                                        $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name='$products' LIMIT 1"));

                                        $product_smart_table = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".($get_item_status_details["api_id"] ?? "")."' && product_id='".($product_table["id"] ?? "")."'");
                                        $product_agent_table = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".($get_item_status_details["api_id"] ?? "")."' && product_id='".($product_table["id"] ?? "")."'");
                                        $product_api_table = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".($get_item_status_details["api_id"] ?? "")."' && product_id='".($product_table["id"] ?? "")."'");

                                        if(mysqli_num_rows($product_smart_table) > 0){
                                            while(($product_smart_details = mysqli_fetch_assoc($product_smart_table)) && ($product_agent_details = mysqli_fetch_assoc($product_agent_table)) && ($product_api_details = mysqli_fetch_assoc($product_api_table))){
                                                $status_badge = ($product_smart_details['status'] == 1) ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">Active</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2">Inactive</span>';

                                                $package_display_name = !empty($product_smart_details["val_4"]) ? $product_smart_details["val_4"] : strtoupper(str_replace(["_","-"]," ",$product_smart_details["val_1"]));
                                                echo '
                                                <tr>
                                                    <td class="fw-bold">
                                                        <div class="small text-muted text-uppercase" style="font-size:0.65rem">'.strtoupper($products).'</div>
                                                        <span class="package-name-text">'.$package_display_name.'</span>
                                                        <input name="api-id[]" type="hidden" value="'.$product_smart_details["api_id"].'"/>
                                                        <input name="product-id[]" type="hidden" value="'.$product_smart_details["product_id"].'"/>
                                                        <input name="product-code-1[]" type="hidden" value="'.$product_smart_details["val_1"].'"/>
                                                        <input name="product-name[]" type="hidden" class="product-name-val" value="'.$product_smart_details["val_4"].'"/>
                                                    </td>
                                                    <td><input id="'.strtolower($products).'_direct_data_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_smart_level" name="smart-price[]" type="number" step="any" value="'.$product_smart_details["val_2"].'" class="form-control form-control-sm text-center product-price" style="max-width:90px"></td>
                                                    <td><input id="'.strtolower($products).'_direct_data_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_agent_level" name="agent-price[]" type="number" step="any" value="'.$product_agent_details["val_2"].'" class="form-control form-control-sm text-center product-price" style="max-width:90px"></td>
                                                    <td><input id="'.strtolower($products).'_direct_data_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_api_level" name="api-price[]" type="number" step="any" value="'.$product_api_details["val_2"].'" class="form-control form-control-sm text-center product-price" style="max-width:90px"></td>
                                                    <td><input id="'.strtolower($products).'_direct_data_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_days" name="product-days[]" type="number" value="'.$product_api_details["val_3"].'" class="form-control form-control-sm text-center" style="max-width:60px"></td>
                                                    <td>'.$status_badge.'</td>
                                                    <td class="text-end pe-3">
                                                        <div class="btn-group btn-group-sm">
                                                            '.(($product_smart_details['status'] == 1) ?
                                                            '<a href="DirectData.php?action=disable&product_id='.$product_smart_details["product_id"].'&val_1='.$product_smart_details["val_1"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-outline-warning" title="Disable"><i class="bi bi-pause-fill"></i></a>' :
                                                            '<a href="DirectData.php?action=enable&product_id='.$product_smart_details["product_id"].'&val_1='.$product_smart_details["val_1"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-outline-success" title="Enable"><i class="bi bi-play-fill"></i></a>'
                                                            ).'
                                                            <a href="DirectData.php?action=delete&product_id='.$product_smart_details["product_id"].'&val_1='.$product_smart_details["val_1"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-outline-danger" onclick="return confirm(\'Delete this package?\')" title="Delete"><i class="bi bi-trash"></i></a>
                                                        </div>
                                                    </td>
                                                </tr>';
                                                $csv_price_level_array[] = strtolower($products).'_direct_data_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).",".$product_smart_details["val_2"].",".$product_agent_details["val_2"].",".$product_api_details["val_2"].",".$product_api_details["val_3"];
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
                    <a href="javascript:void(0)" onclick='downloadFile(`<?php echo implode("\n",$csv_price_level_array); ?>`, "direct-data.csv");' class="btn btn-sm btn-light border"><i class="bi bi-download me-1"></i>Sample Template</a>
                </div>
                <div class="card-body p-4">
                    <form method="post" enctype="multipart/form-data">
                        <div class="input-group">
                            <input id="csv-chooser" type="file" class="form-control rounded-start-3" required/>
                            <button data-no-lock onclick="getCSVDetails('5');" type="button" class="btn btn-primary px-4 fw-bold">Process CSV Upload</button>
                        </div>
                        <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Ensure the package names match exactly with the CSV header column.</p>
                    </form>
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
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary"></div><br>Fetching latest plans...</td></tr>';
        resultsDiv.style.display = 'block';

        try {
            const response = await fetch(`ajax-fetch-plans.php?gateway=${gateway}&network=${network}&type=dd`);
            const data = await response.json();

            if(!data.success) throw new Error(data.message || 'Fetch failed');

            fetchedPlans = data.plans;
            tbody.innerHTML = '';

            fetchedPlans.forEach((plan, index) => {
                const providerPrice = parseFloat(plan.price);
                const costPrice = providerPrice * (1 - (pDisc / 100));

                plan.smartPrice = providerPrice * (1 - (sDisc / 100));
                plan.agentPrice = providerPrice * (1 - (aDisc / 100));
                plan.apiPrice = providerPrice * (1 - (apiDisc / 100));

                const profit = plan.smartPrice - costPrice;

                const row = `
                    <tr>
                        <td class="fw-bold">${plan.name}</td>
                        <td><code>${plan.code}</code></td>
                        <td>₦${providerPrice.toLocaleString()}</td>
                        <td class="text-primary fw-bold">₦${plan.smartPrice.toLocaleString()}</td>
                        <td class="text-success fw-bold">₦${profit.toLocaleString()}</td>
                        <td class="fw-bold text-dark">${plan.days} Days</td>
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
        // Logic to update the main table inputs
        const inputId = `${network}_direct_data_${plan.code.toLowerCase().replace(/ /g, '_')}_smart_level`.replace(/-/g, '_');
        const input = document.getElementById(inputId);
        if(input) {
            input.value = plan.smartPrice;
            // Also update agent and api levels
            document.getElementById(inputId.replace('smart_level', 'agent_level')).value = plan.agentPrice;
            document.getElementById(inputId.replace('smart_level', 'api_level')).value = plan.apiPrice;

            // Update validity
            const dayInput = document.getElementById(inputId.replace('smart_level', 'days'));
            if(dayInput) dayInput.value = plan.days;

            // Update Name
            const row = input.closest('tr');
            const nameText = row.querySelector('.package-name-text');
            const nameInput = row.querySelector('.product-name-val');
            if(nameText) nameText.innerText = plan.name;
            if(nameInput) nameInput.value = plan.name;

            alert(`Applied ${plan.name} with tiered pricing to pricing table. Remember to click Save All Changes.`);
        } else {
            alert(`Plan code ${plan.code} not found in current installation for ${network.toUpperCase()}. Install it first.`);
        }
    }

    async function applyAllFetched() {
        const network = document.getElementById('fetch-network').value;
        const api_id = document.querySelector('select[name="api-id"]').value;

        if(!api_id) {
            alert("Please select an API Gateway first in the API Setting card.");
            return;
        }

        if(!confirm(`This will save/update ${fetchedPlans.length} plans for ${network.toUpperCase()} to the database. Continue?`)) return;

        try {
            const response = await fetch('ajax-save-plans.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    network: network,
                    api_id: api_id,
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
