<?php session_start();
include("../func/bc-config.php");

if (isset($_POST["buy-data"])) {
    //Ilterate Bulk Phone
    $bulk_phone_no = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bulk-phone-number"])));
    $bulk_phone_no = array_filter(explode(",", trim($bulk_phone_no)));
    $bulk_phone_no = array_unique($bulk_phone_no);

    $all_isps = $_POST["isp"];
    $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"] ?? ""))));
    $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quantity"] ?? "")));
    $multi_carrier = count(array_filter(explode(",", trim($all_isps)))) > 1;

    $queue_items = array();
    foreach ($bulk_phone_no as $each_phone_number) {
        $queue_items[] = array(
            "phone"    => sanitize_phone_number(trim(strip_tags($each_phone_number))),
            "isp"      => $multi_carrier ? identifyISP($each_phone_number) : $all_isps,
            "type"     => $type,
            "quantity" => $quantity,
        );
    }

    if (!empty($queue_items)) {
        $enqueue_result = bc_enqueue_bulk_batch($connection_server, $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], $get_logged_user_details["id"], strtolower($type), "WEB", $queue_items);
        $_SESSION["product_purchase_response"] = "Your bulk data order (Batch #" . $enqueue_result["batch_number"] . ", " . $enqueue_result["total"] . " numbers) has been queued and is processing in the background. Check the Batch Details page for live progress — you don't need to keep this page open.";
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
    <title>Bulk Shared Data, SME Data, Direct Data, Corporate Data | <?php echo $get_all_site_details["site_title"]; ?>
    </title>
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
      <h1>BULK DATA</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Bulk Data</li>
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
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickBulkDataCarrier('mtn');" class="rounded-4 border p-2"/>
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickBulkDataCarrier('airtel');" class="rounded-4 border p-2"/>
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickBulkDataCarrier('glo');" class="rounded-4 border p-2"/>
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickBulkDataCarrier('9mobile');" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required />

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Recipient Phone Numbers</label>
                    <textarea id="phone-number" name="" onkeyup="tickBulkDataCarrier();" placeholder="Enter numbers separated by commas or new lines" class="form-control" style="min-height: 120px; border-radius: 12px;"></textarea>
                    <textarea id="filtered-phone-number" name="bulk-phone-number" hidden readonly required></textarea>
                    <div class="text-end mt-1">
                        <span id="phone-numbers-span" class="badge bg-primary rounded-pill">Numbers: 0</span>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-uppercase text-muted">Data Type</label>
                        <select id="internet-data-type" name="type" onchange="tickBulkDataCarrier(); resetDataQuantity();" class="form-select form-control-lg" required>
                            <option value="" default hidden selected>Select Type</option>
                            <option value="shared-data">Shared Data</option>
                            <option value="sme-data">SME Data</option>
                            <option value="cg-data">Corporate Gifting</option>
                            <option value="dd-data">Direct Data</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-uppercase text-muted">Plan/Quantity</label>
                        <select id="product-amount" name="quantity" onchange="tickBulkDataCarrier();" class="form-select form-control-lg" required>
                            <option product-category="" value="" default hidden selected>Choose Plan</option>
                            <?php
                            $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                            $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]] ?? "sas_smart_parameter_values";
                            $vid = $get_logged_user_details["vendor_id"];

                            // Optimized Single Query to fetch all active data plans
                            $plans_sql = "SELECT v.*, p.product_name, a.api_type
                                FROM $acc_level_table_name v
                                JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
                                JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id
                                WHERE v.vendor_id = '$vid' AND v.status = 1 AND p.status = 1 AND a.status = 1
                                AND a.api_type IN ('shared-data', 'sme-data', 'cg-data', 'dd-data')";

                            $plans_query = mysqli_query($connection_server, $plans_sql);
                            if ($plans_query) {
                                while($row = mysqli_fetch_assoc($plans_query)){
                                    if($row["val_2"] > 0){
                                        $pname = $row['product_name'];
                                        $dtype = $row['api_type'];
                                        $cat = $pname . "-" . $dtype;
                                        $label = strtoupper($pname) . " " . strtoupper(str_replace("-", " ", $dtype)) . " " . str_replace("_", " ", $row["val_1"]) . " @ ₦" . number_format($row["val_2"], 2) . " (" . $row["val_3"] . " days)";
                                        echo '<option product-category="'.$cat.'" value="'.$row["val_1"].'" hidden>'.$label.'</option>';
                                    }
                                }
                            } else {
                                error_log('[DGV-DB-ERROR] BulkData plans_query failed: ' . mysqli_error($connection_server));
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" id="phone-bypass" onclick="tickBulkDataCarrier();">
                    <label class="form-check-label small fw-bold text-muted" for="phone-bypass">Bypass Phone Verification</label>
                </div>

                <button id="proceedBtn" name="buy-data" type="submit" class="btn btn-secondary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none;">
                    PROCESS BULK DATA
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
