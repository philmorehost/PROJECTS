<?php session_start();
include("../func/bc-config.php");

if (isset($_POST["buy-airtime"])) {
    //Ilterate Bulk Phone
    $bulk_phone_no = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bulk-phone-number"])));
    $bulk_phone_no = array_filter(explode(",", trim($bulk_phone_no)));
    $bulk_phone_no = array_unique($bulk_phone_no);

    $all_isps = $_POST["isp"];
    $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["amount"] ?? ""))));
    $multi_carrier = count(array_filter(explode(",", trim($all_isps)))) > 1;

    $queue_items = array();
    foreach ($bulk_phone_no as $each_phone_number) {
        $queue_items[] = array(
            "phone"  => sanitize_phone_number(trim(strip_tags($each_phone_number))),
            "isp"    => $multi_carrier ? identifyISP($each_phone_number) : $all_isps,
            "amount" => $amount,
        );
    }

    if (!empty($queue_items)) {
        $enqueue_result = bc_enqueue_bulk_batch($connection_server, $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], $get_logged_user_details["id"], "airtime", "WEB", $queue_items);
        $_SESSION["product_purchase_response"] = "Your bulk airtime order (Batch #" . $enqueue_result["batch_number"] . ", " . $enqueue_result["total"] . " numbers) has been queued and is processing in the background. Check the Batch Details page for live progress — you don't need to keep this page open.";
        $_SESSION["product_purchase_status"] = "success";
    } else {
        $_SESSION["product_purchase_response"] = "No valid phone numbers submitted.";
        $_SESSION["product_purchase_status"] = "failed";
    }

    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

?>
<!DOCTYPE html>

<head>
    <title>Bulk Airtime | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    
          <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link
    href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
    rel="stylesheet">

    <script src="https://merchant.beewave.ng/checkout.min.js" defer></script>
  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>

<body>
    <?php include("../func/bc-header.php"); ?>
    
	<div class="pagetitle">
      <h1>BULK AIRTIME</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Bulk Airtime</li>
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
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickBulkAirtimeCarrier('mtn');" class="rounded-4 border p-2"/>
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickBulkAirtimeCarrier('airtel');" class="rounded-4 border p-2"/>
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickBulkAirtimeCarrier('glo');" class="rounded-4 border p-2"/>
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickBulkAirtimeCarrier('9mobile');" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required />

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Recipient Phone Numbers</label>
                    <textarea id="phone-number" name="" onkeyup="tickBulkAirtimeCarrier();" placeholder="Enter numbers separated by commas or new lines" class="form-control" style="min-height: 120px; border-radius: 12px;"></textarea>
                    <textarea id="filtered-phone-number" name="bulk-phone-number" hidden readonly required></textarea>
                    <div class="text-end mt-1">
                        <span id="phone-numbers-span" class="badge bg-primary rounded-pill">Numbers: 0</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Amount per Number (NGN)</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">₦</span>
                        <input id="product-amount" name="amount" onkeyup="tickBulkAirtimeCarrier();" type="number" placeholder="Min 100" class="form-control border-start-0 fw-bold" required />
                    </div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" id="phone-bypass" onclick="tickBulkAirtimeCarrier();">
                    <label class="form-check-label small fw-bold text-muted" for="phone-bypass">Bypass Phone Verification</label>
                </div>

                <button id="proceedBtn" name="buy-airtime" type="submit" class="btn btn-secondary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none;">
                    PROCESS BULK AIRTIME
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
