<?php session_start();
    include("../func/bc-config.php");

    $service_type = mysqli_real_escape_string($connection_server, $_GET['type'] ?? 'data');
    $service_titles = [
        "data" => "Data Bundle Card",
        "airtime" => "Airtime Recharge Card",
        "cable" => "Cable TV Card",
        "electric" => "Electricity Token Card",
        "exam" => "Exam Pin Card",
        "betting" => "Betting Voucher Card"
    ];
    $current_title = $service_titles[$service_type] ?? "Print Hub Card";

    // Map service_type to its service control key
    $service_control_map = [
        "data"     => "print_data",
        "airtime"  => "print_airtime",
        "cable"    => "print_cable",
        "electric" => "print_electric",
        "exam"     => "print_exam",
        "betting"  => "print_betting",
    ];

    if(!isServiceEnabled('data_card')){
        $_SESSION["product_purchase_response"] = "Print Hub service is currently unavailable.";
        header("Location: PrintHub.php");
        exit();
    }

    $sub_service_key = $service_control_map[$service_type] ?? null;
    if($sub_service_key && !isServiceEnabled($sub_service_key)){
        $_SESSION["product_purchase_response"] = "$current_title service is currently unavailable.";
        header("Location: PrintHub.php");
        exit();
    }

    if(isset($_POST["buy-bundle-card"])){
        $network = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["network"])));
        $data_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["data_type"])));
        $plan_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["plan_id"])));
        $quantity = (int)$_POST["quantity"];
        $brand_name = isset($_POST["brand_name"]) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["brand_name"]))) : "";
        $custom_price = isset($_POST["custom_price"]) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["custom_price"]))) : "";

        if($quantity < 1 || $quantity > 40){
            $_SESSION["product_purchase_response"] = "Invalid quantity. Max 40.";
            header("Location: DataBundleCard.php?type=$service_type");
            exit();
        }

        $get_plan = mysqli_fetch_array(mysqli_query($connection_server, "SELECT p.*, prod.product_name FROM sas_databundle_plans p JOIN sas_products prod ON p.product_id = prod.id WHERE p.vendor_id='".$get_logged_user_details["vendor_id"]."' && p.id='$plan_id'"));

        if($get_plan){
            $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
            $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]];

            // Map data_type to status table based on service_type
            if ($get_plan['service_type'] == 'data') {
                $data_type_status_tables = ["sme-data" => "sas_sme_data_status", "shared-data" => "sas_shared_data_status", "cg-data" => "sas_cg_data_status", "dd-data" => "sas_dd_data_status"];
                $status_table = $data_type_status_tables[$get_plan['data_type']] ?? "sas_sme_data_status";
            } else {
                $service_status_tables = [
                    "airtime" => "sas_airtime_status",
                    "cable" => "sas_cable_status",
                    "electric" => "sas_electric_status",
                    "exam" => "sas_exam_status",
                    "betting" => "sas_betting_status"
                ];
                $status_table = $service_status_tables[$get_plan['service_type']] ?? "sas_airtime_status";
            }

            $get_status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $status_table WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && product_name='".$get_plan['product_name']."'"));

            $acc_level = $get_logged_user_details["account_level"];
            if ($acc_level == 3) $unit_price = $get_plan['price_api'];
            elseif ($acc_level == 2) $unit_price = $get_plan['price_agent'];
            else $unit_price = $get_plan['price'];

            $total_price = $unit_price * $quantity;

            if(userBalance(1) >= $total_price){
                $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
                $batch_prefix = strtoupper(substr($service_type, 0, 1));
                $batch_reference = $batch_prefix."BATCH-".substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 10);
                $description = "Purchase of $quantity $current_title (".strtoupper($get_plan['product_name'])." ".strtoupper($get_plan['plan_code']).")";

                $debit = chargeUser("debit", $batch_reference, $current_title, $reference, "", $total_price, $total_price, $description, "WEB", $_SERVER["HTTP_HOST"], 1);

                if($debit === "success"){
                    // Get SMS Number for this network and service
                    $get_sms = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_databundle_config WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && network='".$get_plan['product_name']."' AND service_type='".$get_plan['service_type']."'"));
                    $sms_number = $get_sms ? $get_sms['sms_to_number'] : "N/A";

                    for($i = 0; $i < $quantity; $i++){
                        $epin = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT)."-".str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT)."-".str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                        $sn = str_pad(random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

                        mysqli_query($connection_server, "INSERT INTO sas_databundle_cards (vendor_id, user_id, product_id, service_type, data_type, network, plan_name, validity, price, epin, serial_number, sms_number, brand_name, custom_price, status, batch_reference, date_sold) VALUES ('".$get_logged_user_details["vendor_id"]."', '".$get_logged_user_details["id"]."', '".$get_plan['product_id']."', '".$get_plan['service_type']."', '".$get_plan['data_type']."', '".$get_plan['product_name']."', '".$get_plan['plan_code']."', '".$get_plan['validity_days']."', '$unit_price', '$epin', '$sn', '$sms_number', " . ($brand_name !== "" ? "'$brand_name'" : "NULL") . ", " . ($custom_price !== "" ? "'$custom_price'" : "NULL") . ", 'Sold', '$batch_reference', CURRENT_TIMESTAMP)");
                    }

                    $_SESSION["product_purchase_response"] = "Cards generated successfully.";
                    header("Location: ViewDataBundleCard.php?batch=$batch_reference");
                    exit();
                }else{
                    $_SESSION["product_purchase_response"] = "Transaction failed. Please try again.";
                }
            }else{
                $_SESSION["product_purchase_response"] = "Insufficient balance.";
            }
        }else{
            $_SESSION["product_purchase_response"] = "Invalid plan selected.";
        }
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_POST["activate-ussd-channel"])){
        $get_vendor_ussd = mysqli_fetch_array(mysqli_query($connection_server, "SELECT hollatags_ussd_code, ussd_activation_fee FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."' LIMIT 1"));
        $fee = $get_vendor_ussd ? (float)$get_vendor_ussd['ussd_activation_fee'] : 0.00;
        
        if (userBalance(1) >= $fee) {
            $ref = "USSD-ACT-" . substr(str_shuffle("12345678901234567890"), 0, 15);
            $desc = "USSD Redemption Channel One-time Activation Fee";
            
            // Debit user
            $debit = chargeUser("debit", $ref, "USSD Activation", $ref, "", $fee, $fee, $desc, "WEB", $_SERVER["HTTP_HOST"], 1);
            if ($debit === "success") {
                mysqli_query($connection_server, "INSERT INTO sas_ussd_activations (vendor_id, user_id, amount_paid, status) VALUES ('".$get_logged_user_details["vendor_id"]."', '".$get_logged_user_details["id"]."', '$fee', 1)");
                $_SESSION["product_purchase_response"] = "USSD Redemption Channel activated successfully!";
            } else {
                $_SESSION["product_purchase_response"] = "Activation failed. Please try again.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Insufficient balance to pay USSD activation fee of N" . number_format($fee, 2);
        }
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    // Fetch USSD settings and status
    $get_vendor_ussd = mysqli_fetch_array(mysqli_query($connection_server, "SELECT hollatags_ussd_code, ussd_activation_fee, ussd_channel_mode FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."' LIMIT 1"));
    $ussd_code = $get_vendor_ussd ? $get_vendor_ussd['hollatags_ussd_code'] : '';
    $ussd_fee = $get_vendor_ussd ? (float)$get_vendor_ussd['ussd_activation_fee'] : 0.00;
    $ussd_channel_mode = $get_vendor_ussd ? $get_vendor_ussd['ussd_channel_mode'] : 'SMS Bridge Only';

    $is_ussd_activated = false;
    if (!empty($ussd_code) && $ussd_channel_mode !== 'SMS Bridge Only') {
        $check_activation = mysqli_query($connection_server, "SELECT status FROM sas_ussd_activations WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' AND user_id='".$get_logged_user_details["id"]."' LIMIT 1");
        if ($check_activation && mysqli_num_rows($check_activation) > 0) {
            $act_row = mysqli_fetch_assoc($check_activation);
            if ($act_row['status'] == 1 || $act_row['status'] == 'active') {
                $is_ussd_activated = true;
            }
        }
    }
?>
<!DOCTYPE html>
<head>
    <title>Buy <?php echo $current_title; ?> | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
	<?php include("../func/bc-header.php"); ?>

	<div class="pagetitle">
      <h1>BUY <?php echo strtoupper($current_title); ?></h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item"><a href="PrintHub.php">Print Hub</a></li>
          <li class="breadcrumb-item active"><?php echo $current_title; ?></li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row">
        <div class="col-lg-8">
          <div class="mb-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold m-0">Print <?php echo ucwords($service_type); ?> Cards</h5>
            <a href="DataBundleCardHistory.php?type=<?php echo $service_type; ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">View History</a>
          </div>
          <div class="card shadow-sm border-0 p-4">
            <form method="post" action="">
                <div class="carrier-grid d-flex flex-wrap justify-content-center gap-3 mb-4">
                    <?php if(in_array($service_type, ['data', 'airtime'])): ?>
                        <img alt="MTN" id="mtn-lg" src="/asset/mtn.png" onclick="selectNetwork('mtn')" class="network-logo rounded-4 border p-2">
                        <img alt="Airtel" id="airtel-lg" src="/asset/airtel.png" onclick="selectNetwork('airtel')" class="network-logo rounded-4 border p-2">
                        <img alt="Glo" id="glo-lg" src="/asset/glo.png" onclick="selectNetwork('glo')" class="network-logo rounded-4 border p-2">
                        <img alt="9mobile" id="9mobile-lg" src="/asset/9mobile.png" onclick="selectNetwork('9mobile')" class="network-logo rounded-4 border p-2">
                    <?php elseif($service_type == 'cable'): ?>
                        <img alt="DStv" id="dstv-lg" src="/asset/dstv.png" onclick="selectNetwork('dstv')" class="network-logo rounded-4 border p-2">
                        <img alt="GOtv" id="gotv-lg" src="/asset/gotv.png" onclick="selectNetwork('gotv')" class="network-logo rounded-4 border p-2">
                        <img alt="Startimes" id="startimes-lg" src="/asset/startimes.png" onclick="selectNetwork('startimes')" class="network-logo rounded-4 border p-2">
                    <?php elseif($service_type == 'electric'): ?>
                        <img alt="AEDC" id="aedc-lg" src="/asset/aedc.jpg" onclick="selectNetwork('aedc')" class="network-logo rounded-4 border p-2">
                        <img alt="EKEDC" id="ekedc-lg" src="/asset/ekedc.jpg" onclick="selectNetwork('ekedc')" class="network-logo rounded-4 border p-2">
                        <img alt="IKEDC" id="ikedc-lg" src="/asset/ikedc.jpg" onclick="selectNetwork('ikedc')" class="network-logo rounded-4 border p-2">
                        <img alt="IBEDC" id="ibedc-lg" src="/asset/ibedc.jpg" onclick="selectNetwork('ibedc')" class="network-logo rounded-4 border p-2">
                    <?php elseif($service_type == 'exam'): ?>
                        <img alt="WAEC" id="waec-lg" src="/asset/waec.png" onclick="selectNetwork('waec')" class="network-logo rounded-4 border p-2">
                        <img alt="NECO" id="neco-lg" src="/asset/neco.png" onclick="selectNetwork('neco')" class="network-logo rounded-4 border p-2">
                        <img alt="NABTEB" id="nabteb-lg" src="/asset/nabteb.png" onclick="selectNetwork('nabteb')" class="network-logo rounded-4 border p-2">
                    <?php elseif($service_type == 'betting'): ?>
                        <img alt="Bet9ja" id="bet9ja-lg" src="/asset/bet9ja.jpg" onclick="selectNetwork('bet9ja')" class="network-logo rounded-4 border p-2">
                        <img alt="BetKing" id="betking-lg" src="/asset/betking.jpg" onclick="selectNetwork('betking')" class="network-logo rounded-4 border p-2">
                        <img alt="1xBet" id="1xbet-lg" src="/asset/1xbet.jpg" onclick="selectNetwork('1xbet')" class="network-logo rounded-4 border p-2">
                    <?php endif; ?>
                </div>

                <input type="hidden" name="network" id="selected-network" required>

                <div class="mb-3" <?php echo ($service_type != 'data') ? 'style="display:none;"' : ''; ?>>
                    <label class="form-label small fw-bold text-uppercase">Type / Category</label>
                    <select name="data_type" id="data-type" class="form-select form-control-lg" onchange="filterPlans()">
                        <?php if($service_type == 'data'): ?>
                            <option value="">Select Type</option>
                            <option value="sme-data" selected>SME Data</option>
                            <option value="shared-data">Shared Data</option>
                            <option value="cg-data">Corporate Data</option>
                            <option value="dd-data">Direct Data</option>
                        <?php else: ?>
                            <option value="<?php echo $service_type; ?>" selected><?php echo ucwords($service_type); ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Select Plan / Value</label>
                    <select name="plan_id" id="data-plan" class="form-select form-control-lg" required>
                        <option value="">Select Option</option>
                        <?php
                            $vid = $get_logged_user_details["vendor_id"];
                            $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                            $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]] ?? "sas_smart_parameter_values";

                            $bundle_plans_sql = "SELECT p.*, prod.product_name FROM sas_databundle_plans p JOIN sas_products prod ON p.product_id = prod.id WHERE p.vendor_id='$vid' && p.service_type='$service_type' && p.status=1";
                            $bundle_plans_query = mysqli_query($connection_server, $bundle_plans_sql);

                            $status_map = [];
                            if ($service_type == 'data') {
                                $types = ["sme-data", "shared-data", "cg-data", "dd-data"];
                                $type_tables = ["sme-data" => "sas_sme_data_status", "shared-data" => "sas_shared_data_status", "cg-data" => "sas_cg_data_status", "dd-data" => "sas_dd_data_status"];
                                foreach($types as $t){
                                    $st_res = mysqli_query($connection_server, "SELECT product_name, api_id FROM ".$type_tables[$t]." WHERE vendor_id='$vid'");
                                    while($st_row = mysqli_fetch_assoc($st_res)){
                                        $status_map[$t][$st_row['product_name']] = $st_row['api_id'];
                                    }
                                }
                            } else {
                                $service_tables = ["airtime" => "sas_airtime_status", "cable" => "sas_cable_status", "electric" => "sas_electric_status", "exam" => "sas_exam_status", "betting" => "sas_betting_status"];
                                $st_table = $service_tables[$service_type];
                                $st_res = mysqli_query($connection_server, "SELECT product_name, api_id FROM $st_table WHERE vendor_id='$vid'");
                                while($st_row = mysqli_fetch_assoc($st_res)){
                                    $status_map[$service_type][$st_row['product_name']] = $st_row['api_id'];
                                }
                            }

                            $pricing_map = [];
                            $pricing_res = mysqli_query($connection_server, "SELECT api_id, product_id, val_1, val_2 FROM $acc_level_table_name WHERE vendor_id='$vid' AND status = 1");
                            while($pr_row = mysqli_fetch_assoc($pricing_res)){
                                $pricing_map[$pr_row['api_id']][$pr_row['product_id']][$pr_row['val_1']] = $pr_row['val_2'];
                            }

                            while($plan = mysqli_fetch_assoc($bundle_plans_query)){
                                $dtype = $plan['data_type'];
                                $pname = $plan['product_name'];
                                $pid = $plan['product_id'];
                                $pcode = $plan['plan_code'];

                                $acc_level = $get_logged_user_details["account_level"];
                                if ($acc_level == 3) $price = $plan['price_api'];
                                elseif ($acc_level == 2) $price = $plan['price_agent'];
                                else $price = $plan['price'];

                                if($price > 0){
                                    $label = strtoupper($pname).' '.str_upper_replace_dash($dtype).' '.strtoupper($pcode);
                                    if ($plan['validity_days'] > 0) $label .= ' ('.$plan['validity_days'].' Days)';
                                    $label .= ' - N'.number_format($price, 2);
                                    echo '<option value="'.$plan['id'].'" data-network="'.$pname.'" data-type="'.$dtype.'" style="display:none">'.$label.'</option>';
                                }
                            }

                            function str_upper_replace_dash($str){
                                return strtoupper(str_replace("-", " ", $str));
                            }
                        ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Custom Brand Name (Optional)</label>
                    <input type="text" name="brand_name" class="form-control form-control-lg" placeholder="e.g. My Business Name" maxlength="200">
                    <div class="form-text text-muted small">This name will be displayed at the top of each printed card. Leave blank for default.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Custom Selling Price (Optional)</label>
                    <input type="text" name="custom_price" class="form-control form-control-lg" placeholder="e.g. 500" pattern="[0-9]+(\.[0-9]{1,2})?">
                    <div class="form-text text-muted small">This retail price will display on the printed cards. Leave blank to show the wholesale purchase cost.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase">Quantity (Max 40)</label>
                    <input type="number" name="quantity" class="form-control form-control-lg" min="1" max="40" value="1" required>
                </div>

                <button type="submit" id="proceedBtn" name="buy-bundle-card" class="btn btn-primary btn-lg w-100 shadow-sm">
                    GENERATE EPINs
                </button>
            </form>
          </div>
        </div>
        
        <?php if (!empty($ussd_code) && $ussd_channel_mode !== 'SMS Bridge Only'): ?>
        <div class="col-lg-4">
          <div class="card shadow-sm border-0 p-4 text-center">
            <div class="d-flex align-items-center justify-content-center mb-3">
              <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary">
                <i class="bi bi-phone-vibrate fs-3"></i>
              </div>
            </div>
            <h5 class="fw-bold mb-2">USSD Card Redemption</h5>
            <p class="text-muted small mb-4">
              Dial code directly from any mobile phone to instantly redeem your cards. No app or internet connection required.
            </p>
            
            <?php if ($is_ussd_activated): ?>
              <div class="bg-success bg-opacity-10 text-success rounded-3 p-3 mb-3 border border-success border-opacity-25">
                <div class="small fw-bold text-uppercase tracking-wider mb-1">Status: Active</div>
                <div class="fs-4 fw-bold font-monospace"><?php echo htmlspecialchars($ussd_code); ?></div>
                <div class="small text-muted mt-1">Shortcode for card redemption</div>
              </div>
              <p class="text-muted small mb-0">Dial the code above and enter card EPIN when prompted.</p>
            <?php else: ?>
              <div class="bg-warning bg-opacity-10 text-warning-emphasis rounded-3 p-3 mb-4 border border-warning border-opacity-25">
                <div class="small fw-bold text-uppercase tracking-wider mb-1">Status: Inactive</div>
                <div class="small">Activation Fee: <strong>₦<?php echo number_format($ussd_fee, 2); ?></strong></div>
              </div>
              
              <form method="post" action="">
                <button type="submit" name="activate-ussd-channel" class="btn btn-primary w-100 py-2.5 rounded-3 fw-semibold shadow-sm">
                  ACTIVATE USSD CHANNEL
                </button>
              </form>
              <p class="text-muted small mt-3 mb-0">Fee is charged once from your wallet balance.</p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <script>
        function selectNetwork(network) {
            document.getElementById('selected-network').value = network;
            const logos = document.querySelectorAll('.network-logo');
            logos.forEach(logo => {
                logo.classList.remove('selected');
                logo.style.filter = "grayscale(100%) opacity(0.4)";
                if(logo.id === network + '-lg') {
                    logo.classList.add('selected');
                    logo.style.filter = "none";
                }
            });
            filterPlans();
        }

        function filterPlans() {
            const network = document.getElementById('selected-network').value;
            const type = document.getElementById('data-type').value;
            const planSelect = document.getElementById('data-plan');
            const options = planSelect.options;

            for (let i = 1; i < options.length; i++) {
                const optNetwork = options[i].getAttribute('data-network');
                const optType = options[i].getAttribute('data-type');

                if (optNetwork === network && optType === type) {
                    options[i].style.display = 'block';
                } else {
                    options[i].style.display = 'none';
                }
            }
            planSelect.value = "";
        }
    </script>

	<?php include("../func/bc-footer.php"); ?>
</body>
</html>
