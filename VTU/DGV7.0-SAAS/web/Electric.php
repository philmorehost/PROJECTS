<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["buy-electric"])){
        $purchase_method = "web";
        $action_function = 1;
		include_once("func/electric.php");
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
        unset($_SESSION["meter_amount"]);
        unset($_SESSION["meter_number"]);
        unset($_SESSION["meter_provider"]);
        unset($_SESSION["meter_type"]);
        unset($_SESSION["meter_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["verify-meter"])){
        $purchase_method = "web";
        $action_function = 3;
		include_once("func/electric.php");
        $json_response_decode = json_decode($json_response_encode,true);
        if($json_response_decode["status"] == "success"){
            $_SESSION["meter_amount"] = $amount;
            $_SESSION["meter_number"] = $meter_number;
            $_SESSION["meter_provider"] = $epp;
            $_SESSION["meter_type"] = $type;
            $_SESSION["meter_name"] = $json_response_decode["desc"];
        }

        if($json_response_decode["status"] == "failed"){
            $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["reset-electric"])){
        unset($_SESSION["meter_amount"]);
        unset($_SESSION["meter_number"]);
        unset($_SESSION["meter_provider"]);
        unset($_SESSION["meter_type"]);
        unset($_SESSION["meter_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
?>
<!DOCTYPE html>
<head>
    <title>Utility Bills | <?php echo $get_all_site_details["site_title"]; ?></title>
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
      <h1>BUY ELECTRIC</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Buy Electric</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <form method="post" action="">
                <?php if(!isset($_SESSION["meter_name"])){ ?>
                <div class="carrier-grid d-flex flex-wrap justify-content-center gap-3 mb-4">
                    <img alt="ekedc" id="ekedc-lg" product-status="enabled" src="/asset/ekedc.jpg" onclick="tickElectricCarrier('ekedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                    <img alt="eedc" id="eedc-lg" product-status="enabled" src="/asset/eedc.jpg" onclick="tickElectricCarrier('eedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                    <img alt="ikedc" id="ikedc-lg" product-status="enabled" src="/asset/ikedc.jpg" onclick="tickElectricCarrier('ikedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                    <img alt="jedc" id="jedc-lg" product-status="enabled" src="/asset/jedc.jpg" onclick="tickElectricCarrier('jedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                    <img alt="kedco" id="kedco-lg" product-status="enabled" src="/asset/kedco.jpg" onclick="tickElectricCarrier('kedco'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                    <img alt="ibedc" id="ibedc-lg" product-status="enabled" src="/asset/ibedc.jpg" onclick="tickElectricCarrier('ibedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                    <img alt="phed" id="phed-lg" product-status="enabled" src="/asset/phed.jpg" onclick="tickElectricCarrier('phed'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                    <img alt="aedc" id="aedc-lg" product-status="enabled" src="/asset/aedc.jpg" onclick="tickElectricCarrier('aedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
			<img alt="yedc" id="yedc-lg" product-status="enabled" src="/asset/yedc.jpg" onclick="tickElectricCarrier('yedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
			<img alt="bedc" id="bedc-lg" product-status="enabled" src="/asset/bedc.jpg" onclick="tickElectricCarrier('bedc'); resetElectricQuantity();" class="rounded-4 border p-2"/>
			<img alt="aba" id="aba-lg" product-status="enabled" src="/asset/aba.jpg" onclick="tickElectricCarrier('aba'); resetElectricQuantity();" class="rounded-4 border p-2"/>
			<img alt="kaedco" id="kaedco-lg" product-status="enabled" src="/asset/kaedco.jpg" onclick="tickElectricCarrier('kaedco'); resetElectricQuantity();" class="rounded-4 border p-2"/>
                </div>

                <input id="electricname" name="epp" type="text" placeholder="electric Name" hidden readonly required/>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Meter Type</label>
                    <select style="text-align: center;" id="meter-type" name="type" onchange="pickElectricQty();" class="form-select form-control-lg" required>
                        <option value="" default hidden selected>Select Type</option>
                        <option value="prepaid">Prepaid</option>
                        <option value="postpaid">Postpaid</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Meter Number</label>
                    <input style="text-align: center;" id="meter-number" name="meter-number" onkeyup="pickElectricQty();" type="text" placeholder="Enter Meter No." pattern="[0-9]{10,}" title="Charater must be atleast 10 digit" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase">Amount (NGN)</label>
                    <input style="text-align: center;" id="product-amount" name="amount" onkeyup="pickElectricQty();" type="text" placeholder="Min 100" pattern="[0-9]{3,}" title="Charater must be atleast 3 digit" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>
                <?php }else{ ?>
                <div class="text-center mb-4">
                  <img alt="<?php echo $_SESSION['meter_provider']; ?>" id="<?php echo $_SESSION['meter_provider']; ?>-lg" src="/asset/<?php echo $_SESSION['meter_provider']; ?>.jpg" class="service-logo rounded-4 shadow-sm border mb-3" /><br/>
                  <div class="bg-light p-3 rounded-4 border text-start">
                      <div class="mb-2"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Full Name</small><br><span class="fw-bold text-dark"><?php echo strtoupper($_SESSION['meter_name']); ?></span></div>
                      <div class="mb-2"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Meter Number</small><br><span class="fw-bold text-dark"><?php echo $_SESSION['meter_number']; ?></span></div>
                      <div class="mb-2"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Meter Type</small><br><span class="fw-bold text-dark"><?php echo strtoupper($_SESSION['meter_type']); ?></span></div>
                      <div class="mb-0"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Amount To Pay</small><br><span class="h4 fw-bold text-success">₦<?php echo number_format($_SESSION['meter_amount'], 2); ?></span></div>
                  </div>
                </div>
                <?php } ?>

                <?php if(!isset($_SESSION["meter_name"])){ ?>
                <button id="proceedBtn" name="verify-meter" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm" >
                    VERIFY METER
                </button>
                <?php }else{ ?>
                <?php if ($force_security_pin_for_users === 1): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase" for="transaction-pin">Transaction PIN</label>
                    <input id="transaction-pin" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center" placeholder="Enter your 4-digit PIN" required />
                    <div class="form-text">Your account requires a transaction PIN for every purchase.</div>
                </div>
                <?php endif; ?>
                <button name="buy-electric" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm mb-3" >
                    COMPLETE PURCHASE
                </button>
                <button name="reset-electric" type="submit" class="btn btn-outline-secondary w-100 border-0" >
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
