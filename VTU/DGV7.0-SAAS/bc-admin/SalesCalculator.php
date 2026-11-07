<?php session_start();
include("../func/bc-admin-config.php");
$product_type_array = array("all", "airtime", "sme-data", "cg-data", "dd-data", "datacard", "rechargecard", "electric", "cable", "exam", "bulk-sms");
?>
<!DOCTYPE html>

<head>
    <title>Sales Calculator | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"] ?? '', 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
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
      <h1>SALES CALCULATOR</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Sales Calculator</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4">

    
    <div class="col-lg-12">
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0 text-center">
                <h5 class="fw-bold mb-0 text-primary">Sales Analytics Calculator</h5>
                <p class="text-muted small mb-0">Select a date range to generate sales performance reports</p>
            </div>
            <div class="card-body p-4 p-md-5 bg-light bg-opacity-50">
                <form method="get" action="SalesCalculator.php">
                    <div class="row justify-content-center mb-4">
                        <div class="col-lg-10">
                            <div class="d-flex flex-wrap justify-content-center gap-2">
                                <?php
                                $presets = [
                                    ['7 Days', '- 7 days'], ['2 Weeks', '- 14 days'], ['1 Month', '- 1 months'],
                                    ['2 Months', '- 2 months'], ['3 Months', '- 3 months'], ['6 Months', '- 6 months'],
                                    ['1 Year', '- 1 years']
                                ];
                                foreach($presets as $p): ?>
                                    <button type="button"
                                            onclick="updateTransactionCalculatorCustomDate('<?php echo date('Y-m-d', strtotime($p[1])); ?>', '<?php echo date('Y-m-d'); ?>');"
                                            class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold"><?php echo $p[0]; ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="row justify-content-center g-3">
                        <?php
                        $search_starts = $_GET["starts"] ?? date("Y-m-d");
                        $search_ends = $_GET["ends"] ?? date("Y-m-d");
                        ?>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Starting From</label>
                            <input id="starting-date" name="starts" type="date" value="<?php echo htmlspecialchars($search_starts); ?>" class="form-control form-control-lg rounded-3 border-0 shadow-sm" required />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Ending At</label>
                            <input id="ending-date" name="ends" type="date" value="<?php echo htmlspecialchars($search_ends); ?>" class="form-control form-control-lg rounded-3 border-0 shadow-sm" required />
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-lg w-100 rounded-3 fw-bold shadow-sm">Calculate</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-12">
        <div class="row g-4">
            <?php
            $airtime_array = array("product_name" => "Airtime", "amount" => 0, "amount_paid" => 0, "qty" => 0);
            $sme_data_array = array("product_name" => "SME Data", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $cg_data_array = array("product_name" => "Corporate Data", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $dd_data_array = array("product_name" => "Direct Data", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $shared_data_array = array("product_name" => "Shared Data", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $electric_array = array("product_name" => "Electricity Bill", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $exam_array = array("product_name" => "Exam PIN", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $cable_array = array("product_name" => "Cable TV", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $sms_array = array("product_name" => "Bulk SMS", "amount" => 0, "amount_paid" => 0, "qty" => 0);
            $card_array = array("product_name" => "Card Transactions", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $betting_array = array("product_name" => "Betting", "amount" => 0, "amount_paid" => 0, "qty" => 0);

            $product_type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_GET["type"] ?? ''))));
            $starting_date = strtotime(mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_GET["starts"] ?? '')))) . " 00:00:00");
            $ending_date = strtotime(mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_GET["ends"] ?? '')))) . " 23:59:59");

            if (($starting_date !== false) && ($ending_date !== false) && ($starting_date < $ending_date)) {
                $transaction_calculator_reference_array = array();
                $select_transaction_based_on_date_provided = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && status='1'");
                if (mysqli_num_rows($select_transaction_based_on_date_provided) > 0) {
                    while ($transaction_details = mysqli_fetch_assoc($select_transaction_based_on_date_provided)) {
                        if ((strtotime($transaction_details["date"]) >= $starting_date) && (strtotime($transaction_details["date"]) <= $ending_date)) {
                            if (!empty($transaction_details["api_id"]) && is_numeric($transaction_details["api_id"]) && !empty($transaction_details["product_id"]) && is_numeric($transaction_details["product_id"])) {
                                $select_api_list_with_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && id='" . $transaction_details["api_id"] . "'");
                                $select_product_list_with_id = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && id='" . $transaction_details["product_id"] . "'");
                                if ((mysqli_num_rows($select_api_list_with_id) == 1) && (mysqli_num_rows($select_product_list_with_id) == 1)) {
                                    //AIRTIME SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "airtime") !== false) {
                                        $airtime_array["amount"] += $transaction_details["amount"];
                                        $airtime_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $airtime_array["qty"] += 1;
                                    }

                                    //SME SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "sme data") !== false) {
                                        $sme_data_array["amount"] += $transaction_details["amount"];
                                        $sme_data_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $sme_data_array["qty"] += 1;
                                    }

                                    //CG SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "cg data") !== false) {
                                        $cg_data_array["amount"] += $transaction_details["amount"];
                                        $cg_data_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $cg_data_array["qty"] += 1;
                                    }

                                    //SHARED SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "shared data") !== false) {
                                        $shared_data_array["amount"] += $transaction_details["amount"];
                                        $shared_data_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $shared_data_array["qty"] += 1;
                                    }

                                    //DIRECT DATA SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "dd data") !== false) {
                                        $shared_data_array["amount"] += $transaction_details["amount"];
                                        $shared_data_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $shared_data_array["qty"] += 1;
                                    }

                                    //ELECTRIC SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "electric") !== false) {
                                        $electric_array["amount"] += $transaction_details["amount"];
                                        $electric_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $electric_array["qty"] += 1;
                                    }

                                    //EXAM SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "exam") !== false) {
                                        $exam_array["amount"] += $transaction_details["amount"];
                                        $exam_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $exam_array["qty"] += 1;
                                    }

                                    //CABLE SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "cable") !== false) {
                                        $cable_array["amount"] += $transaction_details["amount"];
                                        $cable_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $cable_array["qty"] += 1;
                                    }

                                    //SMS SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "sms") !== false) {
                                        $sms_array["amount"] += $transaction_details["amount"];
                                        $sms_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $sms_array["qty"] += 1;
                                    }

                                    //CARD SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "card") !== false) {
                                        $card_array["amount"] += $transaction_details["amount"];
                                        $card_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $card_array["qty"] += 1;
                                    }
                                    
                                    //BETTING SALES
                                    if (strpos(strtolower($transaction_details["type_alternative"]), "betting") !== false) {
                                        $betting_array["amount"] += $transaction_details["amount"];
                                        $betting_array["amount_paid"] += $transaction_details["discounted_amount"];
                                        $betting_array["qty"] += 1;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $product_detail_json = array($airtime_array, $sme_data_array, $cg_data_array, $dd_data_array, $shared_data_array, $electric_array, $exam_array, $cable_array, $sms_array, $card_array, $betting_array);

            foreach ($product_detail_json as $trans_array) {
                if($trans_array["qty"] == 0) continue;
                echo '
                <div class="col-xl-4 col-md-6">
                    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0 text-primary">'.ucwords($trans_array["product_name"]).'</h6>
                            <span class="badge bg-primary bg-opacity-10 text-dark-primary rounded-pill px-3">'.$trans_array["qty"].' Sales</span>
                        </div>
                        <div class="card-body p-4 bg-light bg-opacity-50">
                            <div class="mb-3">
                                <small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Total Face Value</small>
                                <div class="h4 fw-bold text-dark mb-0">₦'.number_format($trans_array["amount"], 2).'</div>
                            </div>
                            <div class="mb-3">
                                <small class="text-muted text-uppercase fw-bold" style="font-size: 10px;">Total Cost to Users</small>
                                <div class="h5 fw-bold text-success mb-0">₦'.number_format($trans_array["amount_paid"], 2).'</div>
                            </div>
                            <div class="pt-3 border-top d-flex justify-content-between align-items-center">
                                <small class="text-muted">Est. Profit Margin</small>
                                <span class="fw-bold text-dark">₦'.number_format($trans_array["amount"] - $trans_array["amount_paid"], 2).'</span>
                            </div>
                        </div>
                    </div>
                </div>';
            }
            if(array_sum(array_column($product_detail_json, 'qty')) == 0) {
                echo '<div class="col-12"><div class="card p-5 text-center border-0 rounded-4 shadow-sm text-muted">No transactions found for the selected period.</div></div>';
            }
            ?>
        </div>
    </div>
  </div>
</section>
    <script>
        function updateTransactionCalculatorCustomDate(start_date, end_date) {
            var starting_date = document.getElementById("starting-date");
            var ending_date = document.getElementById("ending-date");
            starting_date.value = start_date;
            ending_date.value = end_date;
        }
    </script>
    <?php include("../func/bc-admin-footer.php"); ?>

</body>

</html>