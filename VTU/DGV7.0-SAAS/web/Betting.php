<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["buy-betting"])){
        $purchase_method = "web";
        $action_function = 1;
        include_once("func/betting.php");
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        $_SESSION["product_purchase_status"] = $json_response_decode["status"] ?? null;
        if (isset($json_response_decode["ref"])) {
            $_SESSION["last_transaction_ref"] = $json_response_decode["ref"];
        }
        unset($_SESSION["customer_amount"]);
        unset($_SESSION["customer_id"]);
        unset($_SESSION["customer_provider"]);
        unset($_SESSION["customer_type"]);
        unset($_SESSION["customer_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["verify-customer"])){
        $purchase_method = "web";
        $action_function = 3;
        include_once("func/betting.php");
        $json_response_decode = json_decode($json_response_encode,true);
        if($json_response_decode["status"] == "success"){
            $_SESSION["customer_amount"] = $amount;
            $_SESSION["customer_id"] = $customer_id;
            $_SESSION["customer_provider"] = $epp;
            $_SESSION["customer_name"] = $json_response_decode["desc"];
        }

        if($json_response_decode["status"] == "failed"){
            $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["reset-betting"])){
        unset($_SESSION["customer_amount"]);
        unset($_SESSION["customer_id"]);
        unset($_SESSION["customer_provider"]);
        unset($_SESSION["customer_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
?>
<!DOCTYPE html>
<head>
    <title>Fund Betting | <?php echo $get_all_site_details["site_title"]; ?></title>
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
      <h1>FUND BETTING</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Fund Betting</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <form method="post" action="">
                <?php if(!isset($_SESSION["customer_name"])){ ?>
                <div class="carrier-grid d-flex flex-wrap justify-content-center gap-3 mb-4">
                    <img alt="msport" id="msport-lg" product-status="enabled" src="/asset/msport.jpg" onclick="tickBettingCarrier('msport'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="naijabet" id="naijabet-lg" product-status="enabled" src="/asset/naijabet.jpg" onclick="tickBettingCarrier('naijabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="nairabet" id="nairabet-lg" product-status="enabled" src="/asset/nairabet.jpg" onclick="tickBettingCarrier('nairabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="bet9ja-agent" id="bet9ja-agent-lg" product-status="enabled" src="/asset/bet9ja-agent.jpg" onclick="tickBettingCarrier('bet9ja-agent'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betland" id="betland-lg" product-status="enabled" src="/asset/betland.jpg" onclick="tickBettingCarrier('betland'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betlion" id="betlion-lg" product-status="enabled" src="/asset/betlion.jpg" onclick="tickBettingCarrier('betlion'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="supabet" id="supabet-lg" product-status="enabled" src="/asset/supabet.jpg" onclick="tickBettingCarrier('supabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="bet9ja" id="bet9ja-lg" product-status="enabled" src="/asset/bet9ja.jpg" onclick="tickBettingCarrier('bet9ja'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="bangbet" id="bangbet-lg" product-status="enabled" src="/asset/bangbet.jpg" onclick="tickBettingCarrier('bangbet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betking" id="betking-lg" product-status="enabled" src="/asset/betking.jpg" onclick="tickBettingCarrier('betking'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="1xbet" id="1xbet-lg" product-status="enabled" src="/asset/1xbet.jpg" onclick="tickBettingCarrier('1xbet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betway" id="betway-lg" product-status="enabled" src="/asset/betway.jpg" onclick="tickBettingCarrier('betway'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="merrybet" id="merrybet-lg" product-status="enabled" src="/asset/merrybet.jpg" onclick="tickBettingCarrier('merrybet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="mlotto" id="mlotto-lg" product-status="enabled" src="/asset/mlotto.jpg" onclick="tickBettingCarrier('mlotto'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="western-lotto" id="western-lotto-lg" product-status="enabled" src="/asset/western-lotto.jpg" onclick="tickBettingCarrier('western-lotto'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="hallabet" id="hallabet-lg" product-status="enabled" src="/asset/hallabet.jpg" onclick="tickBettingCarrier('hallabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="green-lotto" id="green-lotto-lg" product-status="enabled" src="/asset/green-lotto.jpg" onclick="tickBettingCarrier('green-lotto'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                </div>

                <input id="bettingname" name="epp" type="text" placeholder="betting Name" hidden readonly required/>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Customer ID</label>
                    <input style="text-align: center;" id="customer-id" name="customer-id" onkeyup="pickBettingQty();" type="text" placeholder="Enter ID" pattern="[0-9]{5,}" title="Charater must be atleast 5 digit" class="form-control form-control-lg" required/>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase">Amount (NGN)</label>
                    <input style="text-align: center;" id="product-amount" name="amount" onkeyup="pickBettingQty();" type="text" placeholder="Min 100" pattern="[0-9]{3,}" title="Charater must be atleast 3 digit" class="form-control form-control-lg" required/>
                </div>
                <?php }else{ ?>
                <div class="text-center mb-4">
                  <img alt="<?php echo $_SESSION['customer_provider']; ?>" id="<?php echo $_SESSION['customer_provider']; ?>-lg" src="/asset/<?php echo $_SESSION['customer_provider']; ?>.jpg" class="service-logo rounded-4 shadow-sm border mb-3" /><br/>
                  <div class="bg-light p-3 rounded-4 border text-start">
                      <div class="mb-2"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Customer Name</small><br><span class="fw-bold text-dark"><?php echo strtoupper($_SESSION['customer_name']); ?></span></div>
                      <div class="mb-2"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Customer ID</small><br><span class="fw-bold text-dark"><?php echo $_SESSION['customer_id']; ?></span></div>
                      <div class="mb-0"><small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Amount To Fund</small><br><span class="h4 fw-bold text-success">₦<?php echo number_format($_SESSION['customer_amount'], 2); ?></span></div>
                  </div>
                </div>
                <?php } ?>

                <?php if(!isset($_SESSION["customer_name"])){ ?>
                <button id="proceedBtn" name="verify-customer" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm" >
                    VERIFY CUSTOMER
                </button>
                <?php }else{ ?>
                <?php if ($force_security_pin_for_users === 1): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase" for="transaction-pin">Transaction PIN</label>
                    <input id="transaction-pin" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center" placeholder="Enter your 4-digit PIN" required />
                    <div class="form-text">Your account requires a transaction PIN for every purchase.</div>
                </div>
                <?php endif; ?>
                <button name="buy-betting" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm mb-3" >
                    CONFIRM & FUND
                </button>
                <button name="reset-betting" type="submit" class="btn btn-outline-secondary w-100 border-0" >
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
