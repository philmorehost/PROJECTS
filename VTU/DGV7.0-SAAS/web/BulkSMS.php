<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["send-sms"])){
        $purchase_method = "web";
		include_once("func/sms.php");
        $json_response_decode = json_decode($json_response_encode,true);
        if(!empty($_POST["ajax"])){
            header("Content-Type: application/json");
            echo $json_response_encode;
            exit;
        }
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        //echo '<script>alert("'.$json_response_decode["status"].': '.$json_response_decode["desc"].'");</script>';
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
    
?>
<!DOCTYPE html>
<head>
<title>Bulk SMS | <?php echo $get_all_site_details["site_title"]; ?></title>
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
      <h1>BULK SMS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Bulk SMS</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <form method="post" action="">
                <div class="carrier-grid d-flex flex-wrap justify-content-center gap-2 mb-4">
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickBulkSMSCarrier('mtn');" class="rounded-4 border p-1" />
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickBulkSMSCarrier('airtel');" class="rounded-4 border p-1" />
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickBulkSMSCarrier('glo');" class="rounded-4 border p-1" />
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickBulkSMSCarrier('9mobile');" class="rounded-4 border p-1" />
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required/>
                <input id="filtered-phone-numbers" name="filtered-phone-numbers" type="text" hidden readonly required/>
                <input id="network-groups-data" name="network-groups-data" type="text" hidden readonly/>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-uppercase text-muted mb-0">Sender ID</label>
                        <a href="SubmitSenderID.php" class="btn btn-primary btn-sm rounded-pill px-3 py-1 fw-bold"><i class="bi bi-plus-circle me-1"></i>Register New</a>
                    </div>
                    <select id="sender-id" onchange="tickBulkSMSCarrier('');" name="sender-id" class="form-select form-control-lg" required>
                        <option value="" default hidden selected>Select Sender ID</option>
                        <?php
                            $get_sms_sender_id_lists = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$get_logged_user_details["username"]."'");
                            if(mysqli_num_rows($get_sms_sender_id_lists) > 0){
                                while($sender_id_details = mysqli_fetch_assoc($get_sms_sender_id_lists)){
                                    if($sender_id_details["status"] == 1){
                                        echo '<option value="'.$sender_id_details["sender_id"].'" >'.$sender_id_details["sender_id"].'</option>';
                                    }else if($sender_id_details["status"] == 2){
                                        echo '<option value="" disabled>'.$sender_id_details["sender_id"].' (Pending)</option>';
                                    }
                                }
                            }
                        ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Recipients</label>
                    <textarea id="phone-numbers" name="" onkeyup="filterBulkSMSPhoneNumbers();" placeholder="Enter numbers separated by commas" class="form-control" style="border-radius: 12px; min-height: 100px;"></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <a href="javascript:void(0)" onclick="restructureBulkSMSPhoneNumbers();" class="small fw-bold text-decoration-none">Auto Format</a>
                        <span id="phone-numbers-span" class="badge bg-primary rounded-pill">Numbers: 0</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Message Body</label>
                    <textarea id="text-message" name="text-message" onkeyup="filterBulkSMSMessage(); tickBulkSMSCarrier('');" placeholder="Type your message here..." class="form-control" style="border-radius: 12px; min-height: 120px;" maxlength="459"></textarea>
                    <div class="text-end mt-1">
                        <span id="text-message-span" class="small fw-bold text-muted">0/459</span>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold text-uppercase text-muted">SMS Route</label>
                        <select id="sms-type" name="sms-type" onchange="tickBulkSMSCarrier('');" class="form-select form-control-lg" required>
                            <option value="standard_sms" selected>Standard Route (DND Active)</option>
                        </select>
                    </div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" id="phone-bypass" onclick="bypassBulkSMSPhoneNumbers();" checked>
                    <label class="form-check-label small fw-bold text-muted" for="phone-bypass">Bypass Verification</label>
                </div>

                <button id="proceedBtn" name="send-sms" type="submit" data-no-lock class="btn btn-primary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none; opacity: 0.7;">
                    SEND BULK SMS
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small fw-bold text-danger"></span>
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
