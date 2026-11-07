<?php session_start();
    include("../func/bc-config.php");
    //alterTransaction("6176424889","status","2");
    if(isset($_POST["buy-data"])){
        $purchase_method = "web";
		include_once("func/data.php");
        $json_response_decode = json_decode($json_response_encode,true);
        $response_message = $json_response_decode["desc"];
        if (isset($json_response_decode["bonus_message"])) {
            $response_message .= "<br>" . $json_response_decode["bonus_message"];
        }
        $_SESSION["product_purchase_response"] = $response_message;
        $_SESSION["product_purchase_status"] = $json_response_decode["status"] ?? null;
        if (isset($json_response_decode["ref"])) {
            $_SESSION["last_transaction_ref"] = $json_response_decode["ref"];
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
?>
<!DOCTYPE html>
<head>
    <title>Shared Data, SME Data, Direct Data, Corporate Data | <?php echo $get_all_site_details["site_title"]; ?></title>
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
      <h1>BUY DATA</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Buy Data</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
              <form method="post" action="">
                <div class="carrier-grid d-flex justify-content-center gap-3 mb-4">
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickDataCarrier('airtel');" class="rounded-4 border p-2"/>
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickDataCarrier('mtn');" class="rounded-4 border p-2"/>
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickDataCarrier('glo');" class="rounded-4 border p-2"/>
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickDataCarrier('9mobile');" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required/>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Phone Number</label>
                    <input style="text-align: center;" id="phone-number" name="phone-number" onkeyup="tickDataCarrier(); resetDataQuantity();" type="text" value="" placeholder="e.g 08124232128" pattern="[0-9]{11}" title="Charater must be an 11 digit" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Data Type</label>
                    <select style="text-align: center;" id="internet-data-type" name="type" onchange="tickDataCarrier(); resetDataQuantity();" class="form-select form-control-lg" required>
                        <option value="" default hidden selected>Select Type</option>
                        <option value="shared-data">Shared Data</option>
                        <option value="sme-data">SME Data</option>
                        <option value="cg-data">Corporate Gifting Data</option>
                        <option value="dd-data">Direct Data</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Plan</label>
                    <select style="text-align: center;" id="product-amount" name="quantity" onchange="tickDataCarrier();" class="form-select form-control-lg" required>
                        <option product-category="" value="" default hidden selected>Select Plan</option>
                    <?php
                        $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                        $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]] ?? "sas_smart_parameter_values";
                        $vid = $get_logged_user_details["vendor_id"];

                        // Only list plans belonging to the api_id each product's status table currently
                        // designates as active. Without this, a second enabled sas_apis row of the same
                        // type (e.g. a leftover provider never fully cleared) makes duplicate-looking plans
                        // show up here whose purchase-time lookup (web/func/data.php) can never resolve,
                        // since that lookup is pinned to the status table's single active api_id.
                        $data_type_status_tables = array(
                            "shared-data" => "sas_shared_data_status",
                            "sme-data"    => "sas_sme_data_status",
                            "cg-data"     => "sas_cg_data_status",
                            "dd-data"     => "sas_dd_data_status",
                        );
                        $plans_sql_parts = array();
                        foreach ($data_type_status_tables as $dtype => $status_table) {
                            $plans_sql_parts[] = "SELECT v.*, p.product_name, a.api_type
                                FROM $acc_level_table_name v
                                JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
                                JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id
                                JOIN $status_table st ON st.vendor_id = v.vendor_id AND st.product_name = p.product_name AND st.api_id = v.api_id AND st.status = 1
                                WHERE v.vendor_id = '$vid' AND v.status = 1 AND p.status = 1 AND a.status = 1 AND a.api_type = '$dtype'";
                        }
                        $plans_sql = implode(" UNION ALL ", $plans_sql_parts);

                        $plans_query = mysqli_query($connection_server, $plans_sql);
                        while($row = mysqli_fetch_assoc($plans_query)){
                            if($row["val_2"] > 0){
                                $pname = $row['product_name'];
                                $dtype = $row['api_type'];
                                $cat = $pname . "-" . $dtype;
                                $descriptive_name = !empty($row["val_4"]) ? $row["val_4"] : str_replace("_", " ", $row["val_1"]);
                                $label = strtoupper($pname) . " " . strtoupper(str_replace("-", " ", $dtype)) . " " . $descriptive_name . " @ ₦" . number_format($row["val_2"], 2) . " (" . $row["val_3"] . " days)";
                                echo '<option product-category="'.$cat.'" value="'.$row["val_1"].'" hidden>'.$label.'</option>';
                            }
                        }
                    ?>
                </select>
                </div>

                <div class="form-check mb-4 mt-2">
                    <input id="phone-bypass" onclick="tickDataCarrier('airtel');" type="checkbox" class="form-check-input" />
                    <label for="phone-bypass" class="form-check-label small fw-semibold text-muted">
                        Bypass Phone Verification
                    </label>
                </div>

                <?php if ($force_security_pin_for_users === 1): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase" for="transaction-pin">Transaction PIN</label>
                    <input id="transaction-pin" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center" placeholder="Enter your 4-digit PIN" required />
                    <div class="form-text">Your account requires a transaction PIN for every purchase.</div>
                </div>
                <?php endif; ?>
                <button id="proceedBtn" name="buy-data" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm" >
                    BUY DATA
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small text-danger fw-bold"></span>
                </div>
            </form>    
          </div>
        </div>
      </div>
</section>
		<?php include("../func/short-trans.php"); ?>
	<?php include("../func/bc-footer.php"); ?>
	
</body>
</html>
