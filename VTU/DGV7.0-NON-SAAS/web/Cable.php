<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["buy-cable"])){
        $purchase_method = "web";
        $action_function = 1;
		include_once("func/cable.php");
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
        unset($_SESSION["iuc_number"]);
        unset($_SESSION["cable_provider"]);
        unset($_SESSION["cable_package"]);
        unset($_SESSION["cable_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["verify-cable"])){
        $purchase_method = "web";
        $action_function = 3;
		include_once("func/cable.php");
        $json_response_decode = json_decode($json_response_encode,true);
        if($json_response_decode["status"] == "success"){
            $_SESSION["iuc_number"] = $iuc_no;
            $_SESSION["cable_provider"] = $isp;
            $_SESSION["cable_package"] = $quantity;
            $_SESSION["cable_name"] = $json_response_decode["desc"];
        }
        
        if($json_response_decode["status"] == "failed"){
            $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["reset-cable"])){
        unset($_SESSION["iuc_number"]);
        unset($_SESSION["cable_provider"]);
        unset($_SESSION["cable_package"]);
        unset($_SESSION["cable_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
?>
<!DOCTYPE html>
<head>
    <title>Cable | <?php echo $get_all_site_details["site_title"]; ?></title>
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
      <h1>BUY CABLE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Buy Cable</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
	
            <form method="post" action="">
                <?php if(!isset($_SESSION["cable_name"])){ ?>
                <div class="carrier-grid d-flex justify-content-center gap-3 mb-4">
                    <img alt="Startimes" id="startimes-lg" product-status="enabled" src="/asset/startimes.jpg" onclick="tickCableCarrier('startimes');" class="rounded-4 border p-2"/>
                    <img alt="DSTV" id="dstv-lg" product-status="enabled" src="/asset/dstv.jpg" onclick="tickCableCarrier('dstv');" class="rounded-4 border p-2"/>
                    <img alt="GOTV" id="gotv-lg" product-status="enabled" src="/asset/gotv.jpg" onclick="tickCableCarrier('gotv');" class="rounded-4 border p-2"/>
                    <img alt="ShowMax" id="showmax-lg" product-status="enabled" src="/asset/showmax.jpg" onclick="tickCableCarrier('showmax');" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required/>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">IUC Number</label>
                    <input style="text-align: center;" id="iuc-number" name="iuc-number" onkeyup="tickCableCarrier(); resetCableQuantity();" type="text" value="" placeholder="Decoder IUC No." pattern="[0-9]{10,}" title="Charater must be atleast 10 digit long" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Package</label>
                    <select style="text-align: center;" id="product-amount" name="quantity" onchange="tickCableCarrier();" class="form-select form-control-lg" required>
                        <option product-category="" value="" default hidden selected>Select Package</option>
                    <?php
                        $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                        $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]] ?? "sas_smart_parameter_values";
                        $vid = $get_logged_user_details["vendor_id"];

                        // Optimized Single Query to fetch all active cable plans
                        $plans_sql = "SELECT v.*, p.product_name, a.api_type
                            FROM $acc_level_table_name v
                            JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
                            JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id
                            WHERE v.vendor_id = '$vid' AND v.status = 1 AND p.status = 1 AND a.status = 1
                            AND a.api_type = 'cable'";

                        $plans_query = mysqli_query($connection_server, $plans_sql);
                        while($row = mysqli_fetch_assoc($plans_query)){
                            if($row["val_2"] > 0){
                                $pname = $row['product_name'];
                                $cat = $pname . "-cable";
                                $display_name = !empty($row["val_4"]) ? $row["val_4"] : ucwords(trim(str_replace(["-", "_"], " ", $row["val_1"])));
                                $label = ucwords($pname) . " " . $display_name . " ₦" . number_format($row["val_2"], 2);
                                echo '<option product-category="'.$cat.'" value="'.$row["val_1"].'" hidden>'.$label.'</option>';
                            }
                        }
                    ?>
                </select><br/>
                <?php }else{ ?>
                <div class="text-center mb-4">
                  <img alt="<?php echo $_SESSION['cable_provider']; ?>" id="<?php echo $_SESSION['cable_provider']; ?>-lg" src="/asset/<?php echo $_SESSION['cable_provider']; ?>.jpg" class="service-logo rounded-4 shadow-sm border mb-3" /><br/>
                  <div class="bg-light p-3 rounded-4 border text-start">
                      <div class="mb-2"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Full Name</small><br><span class="fw-bold text-dark"><?php echo strtoupper($_SESSION['cable_name']); ?></span></div>
                      <div class="mb-2"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">IUC Number</small><br><span class="fw-bold text-dark"><?php echo $_SESSION['iuc_number']; ?></span></div>
                      <div class="mb-0"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Package</small><br><span class="fw-bold text-dark"><?php echo ucwords(trim(str_replace(["-", "_"], " ", strtoupper($_SESSION['cable_package'])))); ?></span></div>
                  </div>
                </div>
                <?php } ?>

                <?php if(!isset($_SESSION["cable_name"])){ ?>
                <button id="proceedBtn" name="verify-cable" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm" >
                    VERIFY DECODER
                </button>
                <?php }else{ ?>
                <?php if ($force_security_pin_for_users === 1): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase" for="transaction-pin">Transaction PIN</label>
                    <input id="transaction-pin" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center" placeholder="Enter your 4-digit PIN" required />
                    <div class="form-text">Your account requires a transaction PIN for every purchase.</div>
                </div>
                <?php endif; ?>
                <button name="buy-cable" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm mb-3" >
                    COMPLETE PURCHASE
                </button>
                <button name="reset-cable" type="submit" class="btn btn-outline-secondary w-100 border-0" >
                    Cancel and Start Over
                </button>
                <?php } ?>

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
