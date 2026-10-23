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
        header("Location: Datacard.php");
        exit();
    }
        
    if(isset($_POST["update-key"])){
        $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-id"])));
        $apikey = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-key"])));
        $apistatus = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api-status"])));
        
        if(!empty($api_id) && is_numeric($api_id)){
            if(!empty($apikey)){
                if(is_numeric($apistatus) && in_array($apistatus, array("0", "1"))){
                    $select_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id' && api_type='datacard'");
                    if(mysqli_num_rows($select_api_lists) == 1){
                        mysqli_query($connection_server, "UPDATE sas_apis SET api_key='$apikey', status='$apistatus' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$api_id' && api_type='datacard'");
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

    if(isset($_POST["clear-all-plans"])){
        $vid = $get_logged_admin_details["id"];
        $api_q = mysqli_query($connection_server, "SELECT id FROM sas_apis WHERE vendor_id='$vid' AND api_type='datacard'");
        $api_ids = [];
        while($r = mysqli_fetch_assoc($api_q)) $api_ids[] = $r['id'];

        if(!empty($api_ids)){
            $api_list = implode(',', $api_ids);
            mysqli_query($connection_server, "DELETE FROM sas_smart_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            mysqli_query($connection_server, "DELETE FROM sas_agent_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            mysqli_query($connection_server, "DELETE FROM sas_api_parameter_values WHERE vendor_id='$vid' AND api_id IN ($api_list)");
            $_SESSION["product_purchase_response"] = "All Data Card plans cleared successfully.";
        } else {
            $_SESSION["product_purchase_response"] = "No Data Card API configured to clear plans for.";
        }
        header("Location: Datacard.php");
        exit();
    }

    if (isset($_POST["install-product"])) {
        $products_array = array("mtn", "airtel", "glo", "9mobile");
        $product_varieties = array(
            "mtn" => array("500mb", "1gb", "1.5gb", "2gb", "3gb", "5gb", "10gb"),
            "airtel" => array("500mb", "1gb", "2gb", "5gb"),
            "glo" => array("500mb", "1gb"),
            "9mobile" => array()
        );
        $account_level_table_name_arrays = array("sas_smart_parameter_values", "sas_agent_parameter_values", "sas_api_parameter_values");
        install_product($connection_server, $get_logged_admin_details, 'datacard', 'sas_datacard_status', $products_array, $product_varieties, $account_level_table_name_arrays);
    }

    if(isset($_POST["update-price"])){
        $api_id_array = $_POST["api-id"];
        $product_id_array = $_POST["product-id"];
        $product_code_1_array = $_POST["product-code-1"];
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
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[0]." (vendor_id, api_id, product_id, val_1, val_2, val_3) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$product_code_1', '$smart_price', '$product_days')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[0]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$product_code_1', val_2='$smart_price', val_3='$product_days' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");
                        }
                        
                        $agent_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[1]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");                          
                        if(mysqli_num_rows($agent_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[1]." (vendor_id, api_id, product_id, val_1, val_2, val_3) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$product_code_1', '$agent_price', '$product_days')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[1]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$product_code_1', val_2='$agent_price', val_3='$product_days' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");
                        }
                        
                        $api_product_pricing_table = mysqli_query($connection_server, "SELECT * FROM ".$account_level_table_name_arrays[2]." WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");                            
                        if(mysqli_num_rows($api_product_pricing_table) == 0){
                            mysqli_query($connection_server, "INSERT INTO ".$account_level_table_name_arrays[2]." (vendor_id, api_id, product_id, val_1, val_2, val_3) VALUES ('".$get_logged_admin_details["id"]."', '".$refined_api_id["id"]."', '$product_id', '$product_code_1', '$api_price', '$product_days')");
                        }else{
                            mysqli_query($connection_server, "UPDATE ".$account_level_table_name_arrays[2]." SET vendor_id='".$get_logged_admin_details["id"]."', api_id='".$refined_api_id["id"]."', product_id='$product_id', val_1='$product_code_1', val_2='$api_price', val_3='$product_days' WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$refined_api_id["id"]."' && product_id='$product_id' && val_1='$product_code_1'");
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

    if(isset($_POST["upload-product"])){
        $product_name = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["isp"]))));
        $product_qty = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["qty"]))));
        $dial_code = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["dial-code"]))));
        $card_list = $_POST["cards"];
        $card_list_replace_space = str_replace(" ","",trim($card_list));
        $card_list_replace = str_replace("\r\n",",",trim($card_list_replace_space));
        $card_list_array = array_filter(explode(",",trim($card_list_replace)));
        foreach($card_list_array as $each_card){
            $card_list_alter .= $each_card."\n";
        }
        $new_card_lists = mysqli_real_escape_string($connection_server, trim(strip_tags(str_replace("\n",",",trim($card_list_alter)))));
        $products_array = array("mtn", "airtel", "glo", "9mobile");
        if(!empty($product_name)){
            if(in_array($product_name, $products_array)){
                if(!empty($product_qty)){
                    $card_name = $product_name."_".$product_qty;
                    $select_datacard_products = mysqli_query($connection_server, "SELECT * FROM sas_cards WHERE vendor_id='".$get_logged_admin_details["id"]."' && card_name='$card_name'");
                    if(mysqli_num_rows($select_datacard_products) == 0){
                        mysqli_query($connection_server, "INSERT INTO sas_cards (vendor_id, card_name, cards, dial_code) VALUES ('".$get_logged_admin_details["id"]."', '$card_name', '$new_card_lists', '$dial_code')");
                    }else{
                        mysqli_query($connection_server, "UPDATE sas_cards SET cards='$new_card_lists', dial_code='$dial_code' WHERE vendor_id='".$get_logged_admin_details["id"]."' && card_name='$card_name'");
                    }
                    //Card Uploaded Successfully
                    $json_response_array = array("desc" => ucwords($product_name)." Card Uploaded Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    //Product Quantity Field Empty
                    $json_response_array = array("desc" => "Product Quantity Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                //Invalid Product Name
                $json_response_array = array("desc" => "Invalid Product Name");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Product Name Field Empty
            $json_response_array = array("desc" => "Product Name Field Empty");
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
    <title>Datacard API | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
      <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
          <h1>DATACARD API</h1>
          <nav>
            <ol class="breadcrumb">
              <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
              <li class="breadcrumb-item active">Data Card</li>
            </ol>
          </nav>
        </div>
        <div class="mb-3 mb-md-0">
            <a href="ProductSetUp.php" class="btn btn-outline-primary rounded-pill px-4 shadow-sm">
                <i class="bi bi-gear-fill me-1"></i> Product Setup
            </a>
        </div>
      </div>
    </div>

    <section class="section dashboard">
      <div class="row g-4">
        <!-- API Setting & Installation Column -->
        <div class="col-lg-4">
          <div class="card shadow-sm border-0 rounded-4 mb-4 overflow-hidden">
            <div class="card-header bg-primary bg-opacity-10 py-3 border-0">
                <h5 class="card-title mb-0 fs-6"><i class="bi bi-gear-wide-connected me-2 text-primary"></i>API Gateway Settings</h5>
            </div>
            <div class="card-body p-4">
              <form method="post" action="">
                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Gateway API</label>
                    <select id="" name="api-id" onchange="getWebApikey(this);" class="form-select rounded-3" required>
                        <?php
                            $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='datacard'");
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

          <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title mb-0 fs-6"><i class="bi bi-box-seam me-2 text-primary"></i>Product Installation</h5>
            </div>
            <div class="card-body p-4 text-center pt-0">
              <div class="mb-4">
                <div class="alert alert-info small border-0 py-2 mb-3">Select carriers to install Data PIN plans automatically.</div>
                <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
                    <div class="p-1 rounded-3 border bg-light" style="width: 60px; height: 60px;">
                        <img alt="Airtel" id="airtel-lg" src="/asset/airtel.png" onclick="tickProduct(this, 'airtel', 'api-product-name', 'install-product', 'png');" class="cursor-pointer" style="width: 100%; height: 100%; object-fit: contain;"/>
                    </div>
                    <div class="p-1 rounded-3 border bg-light" style="width: 60px; height: 60px;">
                        <img alt="MTN" id="mtn-lg" src="/asset/mtn.png" onclick="tickProduct(this, 'mtn', 'api-product-name', 'install-product', 'png');" class="cursor-pointer" style="width: 100%; height: 100%; object-fit: contain;"/>
                    </div>
                    <div class="p-1 rounded-3 border bg-light" style="width: 60px; height: 60px;">
                        <img alt="Glo" id="glo-lg" src="/asset/glo.png" onclick="tickProduct(this, 'glo', 'api-product-name', 'install-product', 'png');" class="cursor-pointer" style="width: 100%; height: 100%; object-fit: contain;"/>
                    </div>
                    <div class="p-1 rounded-3 border bg-light" style="width: 60px; height: 60px;">
                        <img alt="9mobile" id="9mobile-lg" src="/asset/9mobile.png" onclick="tickProduct(this, '9mobile', 'api-product-name', 'install-product', 'png');" class="cursor-pointer" style="width: 100%; height: 100%; object-fit: contain;"/>
                    </div>
                </div>
                <button type="button" class="btn btn-light btn-sm w-100 rounded-pill border" onclick="tickProduct(this, 'all', 'api-product-name', 'install-product', 'png');"><i class="bi bi-check-all me-1"></i>Toggle Select All</button>
              </div>
              <form method="post" action="">
                  <input id="api-product-name" name="product-name" type="text" hidden required/>
                  <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Assign to Gateway</label>
                    <select name="api-id" class="form-select rounded-3" required>
                        <?php
                            $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_type='datacard'");
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

          <div class="card shadow-sm border-0 rounded-4 mb-4 border-start border-danger border-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0 pb-0">
                <h5 class="card-title mb-0 text-danger fs-6"><i class="bi bi-trash me-2"></i>Danger Zone</h5>
            </div>
            <div class="card-body p-4 text-center pt-2">
                <p class="small text-muted mb-3">Wipe all Data Card plans from the pricing table.</p>
                <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear ALL Data Card pricing plans? This cannot be undone.');">
                    <button name="clear-all-plans" type="submit" class="btn btn-outline-danger w-100 rounded-pill fw-bold small">
                        <i class="bi bi-eraser-fill me-1"></i>Clear All Plans
                    </button>
                </form>
            </div>
          </div>
        </div>

        <!-- Tables Column -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-primary fs-6"><i class="bi bi-broadcast me-2"></i>Installed Carrier Status</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light small">
                        <tr>
                            <th class="ps-4">Carrier</th><th>Gateway Route</th><th>Status</th><th class="pe-4 text-end">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                            $item_name_array = array("mtn", "airtel", "glo", "9mobile");
                            $items_statement = "product_name IN ('" . implode("','", $item_name_array) . "')";
                            $select_item_lists = mysqli_query($connection_server, "SELECT * FROM sas_datacard_status WHERE vendor_id='".$get_logged_admin_details["id"]."' && $items_statement");
                            if(mysqli_num_rows($select_item_lists) >= 1){
                                while($list_details = mysqli_fetch_assoc($select_item_lists)){
                                    $api_q = mysqli_query($connection_server, "SELECT api_base_url FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$list_details["api_id"]."' LIMIT 1");
                                    $api_route = ($row = mysqli_fetch_assoc($api_q)) ? strtoupper($row["api_base_url"]) : "None";
                                    $status_badge = ($list_details["status"] == 1) ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Active</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3">Inactive</span>';
                                    
                                    echo '
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark">'.strtoupper($list_details["product_name"]).'</td>
                                        <td><span class="badge bg-light text-dark border rounded-pill px-3">'.$api_route.'</span></td>
                                        <td>'.$status_badge.'</td>
                                        <td class="pe-4 text-end">'.render_action_buttons($list_details["product_name"], "datacard", $list_details["status"]).'</td>
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
                    <h6 class="fw-bold mb-0 text-primary fs-6"><i class="bi bi-tag-fill me-2"></i>Data PIN Pricing (₦)</h6>
                    <button class="btn btn-sm btn-outline-primary rounded-pill px-4 shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#bulkPriceCollapse">
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
                            <thead class="bg-light small">
                            <tr>
                                <th class="ps-3">Package Name</th><th>Smart (₦)</th><th>Agent (₦)</th><th>API (₦)</th><th>Days</th><th>Status</th><th class="text-end pe-3">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                                <?php
                                    foreach($item_name_array as $products){
                                        $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_datacard_status WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name='$products'"));
                                        $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name='$products' LIMIT 1"));

                                        if(empty($get_item_status_details) || empty($product_table)) continue;

                                        $product_smart_table = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$get_item_status_details["api_id"]."' && product_id='".$product_table["id"]."'");
                                        $product_agent_table = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$get_item_status_details["api_id"]."' && product_id='".$product_table["id"]."'");
                                        $product_api_table = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='".$get_item_status_details["api_id"]."' && product_id='".$product_table["id"]."'");

                                        if(mysqli_num_rows($product_smart_table) > 0){
                                            while(($product_smart_details = mysqli_fetch_assoc($product_smart_table)) && ($product_agent_details = mysqli_fetch_assoc($product_agent_table)) && ($product_api_details = mysqli_fetch_assoc($product_api_table))){
                                                $status_badge = ($product_smart_details['status'] == 1) ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">Active</span>' : '<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2">Inactive</span>';

                                                echo '
                                                <tr>
                                                    <td class="ps-3 fw-bold">
                                                        <div class="small text-muted text-uppercase" style="font-size:0.6rem">'.strtoupper($products).'</div>
                                                        <div class="text-dark">'.strtoupper(str_replace(["_","-"]," ",$product_smart_details["val_1"])).'</div>
                                                        <input name="api-id[]" type="hidden" value="'.$product_smart_details["api_id"].'"/>
                                                        <input name="product-id[]" type="hidden" value="'.$product_smart_details["product_id"].'"/>
                                                        <input name="product-code-1[]" type="hidden" value="'.$product_smart_details["val_1"].'"/>
                                                    </td>
                                                    <td><input id="'.strtolower($products).'_datacard_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_smart_level" name="smart-price[]" type="number" step="any" value="'.$product_smart_details["val_2"].'" class="form-control form-control-sm text-center shadow-none border-0 bg-light" style="max-width:90px"></td>
                                                    <td><input id="'.strtolower($products).'_datacard_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_agent_level" name="agent-price[]" type="number" step="any" value="'.$product_agent_details["val_2"].'" class="form-control form-control-sm text-center shadow-none border-0 bg-light" style="max-width:90px"></td>
                                                    <td><input id="'.strtolower($products).'_datacard_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_api_level" name="api-price[]" type="number" step="any" value="'.$product_api_details["val_2"].'" class="form-control form-control-sm text-center shadow-none border-0 bg-light" style="max-width:90px"></td>
                                                    <td><input id="'.strtolower($products).'_datacard_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).'_days" name="product-days[]" type="number" value="'.$product_api_details["val_3"].'" class="form-control form-control-sm text-center shadow-none border-0 bg-light" style="max-width:60px"></td>
                                                    <td>'.$status_badge.'</td>
                                                    <td class="text-end pe-3">
                                                        <div class="btn-group btn-group-sm">
                                                            '.(($product_smart_details['status'] == 1) ?
                                                            '<a href="Datacard.php?action=disable&product_id='.$product_smart_details["product_id"].'&val_1='.$product_smart_details["val_1"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-outline-secondary" title="Disable"><i class="bi bi-pause-fill"></i></a>' :
                                                            '<a href="Datacard.php?action=enable&product_id='.$product_smart_details["product_id"].'&val_1='.$product_smart_details["val_1"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-outline-success" title="Enable"><i class="bi bi-play-fill"></i></a>'
                                                            ).'
                                                            <a href="Datacard.php?action=delete&product_id='.$product_smart_details["product_id"].'&val_1='.$product_smart_details["val_1"].'&api_id='.$product_smart_details["api_id"].'" class="btn btn-outline-danger" onclick="return confirm(\'Delete this package?\')" title="Delete"><i class="bi bi-trash"></i></a>
                                                        </div>
                                                    </td>
                                                </tr>';
                                                $csv_price_level_array[] = strtolower($products).'_datacard_'.str_replace(["_","-"],"_",$product_smart_details["val_1"]).",".$product_smart_details["val_2"].",".$product_agent_details["val_2"].",".$product_api_details["val_2"].",".$product_api_details["val_3"];
                                            }
                                        }
                                    }
                                ?>
                            </tbody>
                        </table>
                        </div>
                        <div class="mt-4 pt-4 border-top text-end">
                            <button name="update-price" type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">Save All Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-primary fs-6"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Bulk Update via CSV</h6>
                    <a href="javascript:void(0)" onclick='downloadFile(`<?php echo implode("\n",$csv_price_level_array); ?>`, "datacard.csv");' class="btn btn-sm btn-light border rounded-pill px-3"><i class="bi bi-download me-1"></i>CSV Template</a>
                </div>
                <div class="card-body p-4">
                    <form method="post" enctype="multipart/form-data">
                        <div class="input-group">
                            <input id="csv-chooser" type="file" class="form-control rounded-start-3" required/>
                            <button data-no-lock onclick="getCSVDetails('5');" type="button" class="btn btn-primary px-4 fw-bold shadow-sm">Process CSV Upload</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="fw-bold mb-0 text-primary fs-6"><i class="bi bi-cloud-upload me-2"></i>Upload Data PINs (Manual)</h6>
                </div>
                <div class="card-body p-4 bg-light bg-opacity-10">
                    <form method="post" action="">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Carrier</label>
                                <select id="admin-cards-isp" name="isp" onchange="adminCardsSwitch(); adminCardsSwitchReset();" class="form-select" required>
                                    <?php
                                        $get_datacard_products = mysqli_query($connection_server, "SELECT * FROM sas_datacard_status WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                                        if(mysqli_num_rows($get_datacard_products) >= 1){
                                            echo '<option value="" hidden selected>Choose Carrier</option>';
                                            while($product_details = mysqli_fetch_assoc($get_datacard_products)){
                                                $p_status = ($product_details["status"] == 1) ? " (Enabled)" : " (Disabled)";
                                                echo '<option value="'.strtolower($product_details["product_name"]).'">'.strtoupper($product_details["product_name"]).$p_status.'</option>';
                                            }
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Data Plan / Qty</label>
                                <select id="admin-cards-qty" name="qty" onchange="adminCardsSwitch();" class="form-select" required>
                                    <option value="" hidden selected>Choose Plan</option>
                                    <?php echo datacardQty(); ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Dial Code Configuration</label>
                            <input id="admin-cards-input" name="dial-code" type="text" placeholder="e.g. *460*PIN#, *461#" class="form-control" required/>
                            <p class="small text-muted mt-1">Recharge Code, Balance Code (separated by commas)</p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">PIN List</label>
                            <textarea id="admin-cards-textarea" name="cards" placeholder="Paste Data PINs here, separated by commas" class="form-control" rows="8" style="font-family: monospace;"></textarea>
                        </div>

                        <?php echo datacardTextarea(); ?>

                        <button name="upload-product" type="submit" class="btn btn-primary w-100 rounded-3 fw-bold py-2 shadow-sm">
                            <i class="bi bi-cloud-upload me-2"></i>Upload Data PINs
                        </button>
                    </form>
                </div>
            </div>
        </div>
      </div>
      </div>
    </section>
    <?php include("../func/bc-admin-footer.php"); ?>
    
</body>
</html>
