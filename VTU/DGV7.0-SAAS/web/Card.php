<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["buy-card"])){
        $purchase_method = "web";
        include_once("func/card.php");
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        $_SESSION["product_purchase_status"] = $json_response_decode["status"] ?? null;
        if (isset($json_response_decode["ref"])) {
            $_SESSION["last_transaction_ref"] = $json_response_decode["ref"];
        }
        //echo '<script>alert("'.$json_response_decode["status"].': '.$json_response_decode["desc"].'");</script>';
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
?>
<!DOCTYPE html>
<head>
    <title>Card Printing | <?php echo $get_all_site_details["site_title"]; ?></title>
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
      <h1>BUY CARD</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Buy Card</li>
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
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickDataRechargeCarrier('airtel'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickDataRechargeCarrier('mtn'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickDataRechargeCarrier('glo'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickDataRechargeCarrier('9mobile'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required/>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Card Type</label>
                    <select id="internet-data-type" name="type" onchange="tickDataRechargeCarrier(); resetDataRechargeQuantity();" class="form-select form-control-lg" required>
                        <option value="" default hidden selected>Select Type</option>
                        <option value="datacard">Data Card</option>
                        <option value="rechargecard">Recharge Card</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Package</label>
                    <select id="product-amount" name="quantity" onchange="tickDataRechargeCarrier();" class="form-select form-control-lg" required>
                    <option product-category="" value="" default hidden selected>Card Quantity</option>
                    <?php
                        $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                        $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]] ?? "sas_smart_parameter_values";
                        $vid = $get_logged_user_details["vendor_id"];

                        // Optimized Single Query to fetch all active card plans
                        $plans_sql = "SELECT v.*, p.product_name, a.api_type
                            FROM $acc_level_table_name v
                            JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
                            JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id
                            WHERE v.vendor_id = '$vid' AND v.status = 1 AND p.status = 1 AND a.status = 1
                            AND a.api_type IN ('datacard', 'rechargecard')";

                        $plans_query = mysqli_query($connection_server, $plans_sql);
                        if ($plans_query) {
                            while($row = mysqli_fetch_assoc($plans_query)){
                                if($row["val_2"] > 0){
                                    $pname = $row['product_name'];
                                    $dtype = $row['api_type'];
                                    $cat = $pname . "-" . $dtype;
                                    if($dtype == "datacard"){
                                        $label = strtoupper($pname) . " DATACARD " . $row["val_1"] . " @ ₦" . number_format($row["val_2"], 2) . " (" . $row["val_3"] . " days)";
                                    } else {
                                        $label = strtoupper($pname) . " RECHARGECARD ₦" . $row["val_1"] . " @ ₦" . number_format($row["val_2"], 2);
                                    }
                                    echo '<option product-category="'.$cat.'" value="'.$row["val_1"].'" hidden>'.$label.'</option>';
                                }
                            }
                        } else {
                            error_log('[DGV-DB-ERROR] Card plans_query failed: ' . mysqli_error($connection_server));
                        }
                    ?>
                </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Quantity</label>
                    <input style="text-align: center;" id="quantity" name="qty-number" onkeyup="tickDataRechargeCarrier();" type="text" value="" placeholder="e.g 1" pattern="[0-9]{1,}" title="Quantity must be at least 1" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase">Business/Card Name (Optional)</label>
                    <input style="text-align: center;" id="" name="card-name" type="text" value="" placeholder="Name to show on card" class="form-control form-control-lg" />
                </div>

                <button id="proceedBtn" name="buy-card" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm" >
                    BUY CARD
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small text-danger fw-bold"></span>
                </div>
            </form>
        </div>
      </div>
    </section>

        <?php include("../func/short-trans.php"); ?>
    <?php include("../func/bc-footer.php"); ?>
    
</body>
</html>
