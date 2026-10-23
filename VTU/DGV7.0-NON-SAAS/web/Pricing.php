<?php session_start();
    include("../func/bc-config.php");
    $vid = $get_logged_user_details["vendor_id"] ?? resolveVendorID();

    if(isset($_POST["regenerate"]) && isset($_SESSION["user_session"])){
        $api_key = substr(str_shuffle("abdcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ12345678901234567890"), 0, 50);
		mysqli_query($connection_server, "UPDATE sas_users SET api_key='$api_key' WHERE vendor_id='$vid' && username='".$get_logged_user_details["username"]."'");
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Product Pricing & Discounts | <?php echo htmlspecialchars($get_all_site_details["site_title"]); ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr(htmlspecialchars($get_all_site_details["site_desc"] ?? ''), 0, 160); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <meta name="theme-color" content="#1a1a2e" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    
    <!-- Google Fonts & Icons -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --glass-bg: rgba(255, 255, 255, 0.85);
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            --border-radius: 16px;
        }

        body.public-pricing {
            background-color: #f4f6f9;
            color: #333;
            min-height: 100vh;
        }

        .public-navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .pricing-header-card {
            background: var(--primary-gradient);
            border: none;
            border-radius: var(--border-radius);
            color: white;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(30, 60, 114, 0.2);
            position: relative;
            overflow: hidden;
        }

        .pricing-header-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        .nav-pricing {
            border: none;
            gap: 10px;
            margin-bottom: 25px;
        }

        .nav-pricing .nav-link {
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 50px;
            color: #495057;
            font-weight: 600;
            padding: 12px 24px;
            background: white;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        }

        .nav-pricing .nav-link.active {
            background: var(--primary-gradient) !important;
            color: white !important;
            border-color: transparent;
            box-shadow: 0 5px 15px rgba(30, 60, 114, 0.25);
        }

        .pricing-table-card {
            background: var(--glass-bg);
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            backdrop-filter: blur(5px);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .table-responsive {
            border-radius: var(--border-radius);
        }

        .table th {
            background: #f8f9fa;
            font-weight: 700;
            color: #2a5298;
            border-bottom: 2px solid #eef2f6;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            padding: 15px;
        }

        .table td {
            padding: 14px 15px;
            border-bottom: 1px solid #eef2f6;
            font-size: 0.9rem;
            color: #495057;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(42, 82, 152, 0.015);
        }

        .search-box-wrap {
            position: relative;
            max-width: 400px;
        }

        .search-box-wrap i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .search-input {
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 50px;
            padding: 12px 20px 12px 45px;
            font-size: 0.9rem;
            width: 100%;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: #2a5298;
            box-shadow: 0 0 0 3px rgba(42, 82, 152, 0.15);
        }

        .empty-pricing-card {
            border: 2px dashed rgba(0,0,0,0.08);
            border-radius: var(--border-radius);
            padding: 60px 40px;
            background: white;
        }

        .badge-discount {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.82rem;
        }

        .badge-tier {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .badge-tier-smart { background: rgba(0, 123, 255, 0.1); color: #007bff; }
        .badge-tier-agent { background: rgba(253, 126, 20, 0.1); color: #fd7e14; }
        .badge-tier-api { background: rgba(111, 66, 193, 0.1); color: #6f42c1; }
    </style>
</head>
<body class="<?php echo !isset($_SESSION['user_session']) ? 'public-pricing' : ''; ?>">

    <?php 
    if(isset($_SESSION["user_session"])) {
        include("../func/bc-header.php"); 
    } else {
        // Render Beautiful Standalone Public Navigation Bar
        ?>
        <nav class="public-navbar shadow-sm mb-4">
            <div class="container d-flex justify-content-between align-items-center">
                <a href="/" class="d-flex align-items-center text-decoration-none">
                    <img src="<?php echo htmlspecialchars($get_all_site_details['site_logo'] ?? '/assets-2/img/logo.png'); ?>" alt="Logo" style="max-height: 40px;">
                    <span class="fs-5 fw-bold text-primary ms-2 d-none d-sm-inline-block"><?php echo htmlspecialchars($get_all_site_details['site_title']); ?></span>
                </a>
                <div>
                    <a href="/" class="btn btn-outline-secondary btn-sm rounded-pill px-3 me-2 fw-semibold"><i class="bi bi-house me-1"></i> Home</a>
                    <a href="Login.php" class="btn btn-primary btn-sm rounded-pill px-4 fw-semibold shadow-sm">Login <i class="bi bi-box-arrow-in-right ms-1"></i></a>
                </div>
            </div>
        </nav>
        <?php
    }
    ?>

    <?php
        // ── Pricing Generator Helper Functions ──
        function airtimeAPIDoc(){
            global $connection_server, $vid;
            $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
            $product_name_arrays = array(1 => "mtn", 2 => "airtel", 3 => "9mobile", 4 => "glo");
            $acc_smart_level_table_name = $account_level_table_name_arrays[1];
            $acc_agent_level_table_name = $account_level_table_name_arrays[2];
            $acc_api_level_table_name = $account_level_table_name_arrays[3];

            $product_tr_list = "";
            foreach($product_name_arrays as $pname){
                $status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_airtime_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                if(!$status) continue;
                $api_id = $status['api_id'];
                $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$pname' && status=1 LIMIT 1"));
                if(!empty($product_table["id"])){
                    $q1 = mysqli_query($connection_server, "SELECT * FROM $acc_smart_level_table_name WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q2 = mysqli_query($connection_server, "SELECT * FROM $acc_agent_level_table_name WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q3 = mysqli_query($connection_server, "SELECT * FROM $acc_api_level_table_name WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");

                    if($q1 && mysqli_num_rows($q1) > 0){
                        while(($d1 = mysqli_fetch_assoc($q1)) && ($d2 = mysqli_fetch_assoc($q2)) && ($d3 = mysqli_fetch_assoc($q3))){
                            $product_tr_list .= '<tr>
                                    <td class="fw-bold"><i class="bi bi-telephone text-primary me-2"></i>Airtime</td>
                                    <td><span class="badge bg-secondary">'.strtoupper($pname).'</span></td>
                                    <td class="text-muted font-monospace small">'.$product_table["product_name"].'</td>
                                    <td><span class="badge-discount">'.toDecimal($d1["val_1"], 2).'% Off</span></td>
                                    <td><span class="badge-discount bg-warning bg-opacity-10 text-warning">'.toDecimal($d2["val_1"], 2).'% Off</span></td>
                                    <td><span class="badge-discount bg-success bg-opacity-10 text-success">'.toDecimal($d3["val_1"], 2).'% Off</span></td>
                                </tr>';
                        }
                    }
                }
            }
            return $product_tr_list;
        }

        function dataAPIDoc(){
            global $connection_server, $vid;
            $product_name_arrays = array(1 => "mtn", 2 => "airtel", 3 => "9mobile", 4 => "glo");

            $product_tr_list = "";
            foreach($product_name_arrays as $pname){
                $shared = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_shared_data_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                $sme = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_sme_data_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                $cg = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_cg_data_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                $dd = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_dd_data_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));

                $api_ids = array_unique(array_filter([$shared['api_id'] ?? null, $sme['api_id'] ?? null, $cg['api_id'] ?? null, $dd['api_id'] ?? null]));
                if(empty($api_ids)) continue;

                $api_id_stmt = "api_id IN ('" . implode("','", $api_ids) . "')";
                $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$pname' && status=1 LIMIT 1"));

                if($product_table){
                    $q1 = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='$vid' && $api_id_stmt && product_id='".$product_table["id"]."' && status=1");
                    $q2 = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='$vid' && $api_id_stmt && product_id='".$product_table["id"]."' && status=1");
                    $q3 = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='$vid' && $api_id_stmt && product_id='".$product_table["id"]."' && status=1");

                    while(($d1 = mysqli_fetch_assoc($q1)) && ($d2 = mysqli_fetch_assoc($q2)) && ($d3 = mysqli_fetch_assoc($q3))){
                        $api_info = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_type FROM sas_apis WHERE id='".$d1["api_id"]."' LIMIT 1"));
                        $descriptive_name = !empty($d1["val_4"]) ? $d1["val_4"] : $d1["val_1"];
                        $type_display = strtoupper(str_replace("-", " ", $api_info["api_type"] ?? 'DATA'));
                        $product_tr_list .= '<tr>
                                <td class="fw-bold"><i class="bi bi-wifi text-success me-2"></i>Internet Data</td>
                                <td><span class="badge bg-secondary">'.strtoupper($pname).'</span></td>
                                <td class="small fw-semibold text-muted">'.$type_display.'</td>
                                <td class="fw-bold text-dark">'.str_replace("_", " ", $descriptive_name).'</td>
                                <td class="fw-bold text-primary">₦'.number_format($d1["val_2"], 2).'</td>
                                <td class="fw-bold text-warning">₦'.number_format($d2["val_2"], 2).'</td>
                                <td class="fw-bold text-success">₦'.number_format($d3["val_2"], 2).'</td>
                            </tr>';
                    }
                }
            }
            return $product_tr_list;
        }

        function cableAPIDoc(){
            global $connection_server, $vid;
            $product_name_arrays = ["startimes", "dstv", "gotv", "showmax"];
            $product_tr_list = "";
            foreach($product_name_arrays as $pname){
                $status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_cable_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                if(!$status) continue;
                $api_id = $status['api_id'];
                $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$pname' && status=1 LIMIT 1"));
                if($product_table){
                    $q1 = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q2 = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q3 = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    while(($d1 = mysqli_fetch_assoc($q1)) && ($d2 = mysqli_fetch_assoc($q2)) && ($d3 = mysqli_fetch_assoc($q3))){
                        $descriptive_name = !empty($d1["val_4"]) ? $d1["val_4"] : $d1["val_1"];
                        $product_tr_list .= '<tr>
                                <td class="fw-bold"><i class="bi bi-tv text-warning me-2"></i>Cable TV</td>
                                <td><span class="badge bg-dark">'.strtoupper($pname).'</span></td>
                                <td class="small fw-semibold text-muted">'.str_replace("_", " ", $descriptive_name).'</td>
                                <td class="fw-bold text-primary">₦'.number_format($d1["val_2"], 2).'</td>
                                <td class="fw-bold text-warning">₦'.number_format($d2["val_2"], 2).'</td>
                                <td class="fw-bold text-success">₦'.number_format($d3["val_2"], 2).'</td>
                            </tr>';
                    }
                }
            }
            return $product_tr_list;
        }

        function examAPIDoc(){
            global $connection_server, $vid;
            $product_name_arrays = ["waec", "neco", "nabteb", "jamb"];
            $product_tr_list = "";
            foreach($product_name_arrays as $pname){
                $status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_exam_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                if(!$status) continue;
                $api_id = $status['api_id'];
                $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$pname' && status=1 LIMIT 1"));
                if($product_table){
                    $q1 = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q2 = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q3 = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    while(($d1 = mysqli_fetch_assoc($q1)) && ($d2 = mysqli_fetch_assoc($q2)) && ($d3 = mysqli_fetch_assoc($q3))){
                        $descriptive_name = !empty($d1["val_4"]) ? $d1["val_4"] : $d1["val_1"];
                        $product_tr_list .= '<tr>
                                <td class="fw-bold"><i class="bi bi-mortarboard text-danger me-2"></i>Exam Pin</td>
                                <td><span class="badge bg-danger">'.strtoupper($pname).'</span></td>
                                <td class="small fw-semibold text-muted">'.str_replace("_", " ", $descriptive_name).'</td>
                                <td class="fw-bold text-primary">₦'.number_format($d1["val_2"], 2).'</td>
                                <td class="fw-bold text-warning">₦'.number_format($d2["val_2"], 2).'</td>
                                <td class="fw-bold text-success">₦'.number_format($d3["val_2"], 2).'</td>
                            </tr>';
                    }
                }
            }
            return $product_tr_list;
        }

        function electricAPIDoc(){
            global $connection_server, $vid;
            $product_name_arrays = ["ekedc", "eedc", "ikedc", "jedc", "kedco", "ibedc", "phed", "aedc", "yedc"];
            $product_tr_list = "";
            foreach($product_name_arrays as $pname){
                $status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_electric_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                if(!$status) continue;
                $api_id = $status['api_id'];
                $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$pname' && status=1 LIMIT 1"));
                if($product_table){
                    $q1 = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q2 = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q3 = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    while(($d1 = mysqli_fetch_assoc($q1)) && ($d2 = mysqli_fetch_assoc($q2)) && ($d3 = mysqli_fetch_assoc($q3))){
                        $product_tr_list .= '<tr>
                                <td class="fw-bold"><i class="bi bi-lightning text-info me-2"></i>Electricity</td>
                                <td><span class="badge bg-info text-dark">'.strtoupper($pname).'</span></td>
                                <td class="small fw-semibold text-muted">PREPAID / POSTPAID</td>
                                <td><span class="badge-discount bg-primary bg-opacity-10 text-primary">'.toDecimal($d1["val_1"], 2).'% Fee</span></td>
                                <td><span class="badge-discount bg-warning bg-opacity-10 text-warning">'.toDecimal($d2["val_1"], 2).'% Fee</span></td>
                                <td><span class="badge-discount bg-success bg-opacity-10 text-success">'.toDecimal($d3["val_1"], 2).'% Fee</span></td>
                            </tr>';
                    }
                }
            }
            return $product_tr_list;
        }

        function dataRechargeAPIDoc(){
            global $connection_server, $vid;
            $product_name_arrays = ["mtn", "airtel", "9mobile", "glo"];
            $product_tr_list = "";
            foreach($product_name_arrays as $pname){
                $dc = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_datacard_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                $rc = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_rechargecard_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                $api_ids = array_unique(array_filter([$dc['api_id'] ?? null, $rc['api_id'] ?? null]));
                if(empty($api_ids)) continue;
                $api_id_stmt = "api_id IN ('" . implode("','", $api_ids) . "')";
                $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$pname' && status=1 LIMIT 1"));
                if($product_table){
                    $q1 = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='$vid' && $api_id_stmt && product_id='".$product_table["id"]."' && status=1");
                    $q2 = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='$vid' && $api_id_stmt && product_id='".$product_table["id"]."' && status=1");
                    $q3 = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='$vid' && $api_id_stmt && product_id='".$product_table["id"]."' && status=1");
                    while(($d1 = mysqli_fetch_assoc($q1)) && ($d2 = mysqli_fetch_assoc($q2)) && ($d3 = mysqli_fetch_assoc($q3))){
                        $api_info = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_type FROM sas_apis WHERE id='".$d1["api_id"]."' LIMIT 1"));
                        $product_tr_list .= '<tr>
                                <td class="fw-bold"><i class="bi bi-printer text-secondary me-2"></i>Recharge Card</td>
                                <td><span class="badge bg-secondary">'.strtoupper($pname).'</span></td>
                                <td class="small fw-semibold text-muted">'.strtoupper($api_info["api_type"] ?? 'CARD').' (Qty: '.$d1["val_1"].')</td>
                                <td class="fw-bold text-primary">₦'.number_format($d1["val_2"], 2).'</td>
                                <td class="fw-bold text-warning">₦'.number_format($d2["val_2"], 2).'</td>
                                <td class="fw-bold text-success">₦'.number_format($d3["val_2"], 2).'</td>
                            </tr>';
                    }
                }
            }
            return $product_tr_list;
        }

        function bulksmsAPIDoc(){
            global $connection_server, $vid;
            $product_name_arrays = ["mtn", "airtel", "9mobile", "glo"];
            $product_tr_list = "";
            foreach($product_name_arrays as $pname){
                $status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT api_id FROM sas_bulk_sms_status WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                if(!$status) continue;
                $api_id = $status['api_id'];
                $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && product_name='$pname' && status=1 LIMIT 1"));
                if($product_table){
                    $q1 = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q2 = mysqli_query($connection_server, "SELECT * FROM sas_agent_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    $q3 = mysqli_query($connection_server, "SELECT * FROM sas_api_parameter_values WHERE vendor_id='$vid' && api_id='$api_id' && product_id='".$product_table["id"]."' && status=1");
                    while(($d1 = mysqli_fetch_assoc($q1)) && ($d2 = mysqli_fetch_assoc($q2)) && ($d3 = mysqli_fetch_assoc($q3))){
                        $product_tr_list .= '<tr>
                                <td class="fw-bold"><i class="bi bi-chat-dots text-dark me-2"></i>Bulk SMS</td>
                                <td><span class="badge bg-secondary">'.strtoupper($pname).'</span></td>
                                <td class="small fw-semibold text-muted">PER UNIT</td>
                                <td class="fw-bold text-primary">₦'.number_format($d1["val_2"], 2).'</td>
                                <td class="fw-bold text-warning">₦'.number_format($d2["val_2"], 2).'</td>
                                <td class="fw-bold text-success">₦'.number_format($d3["val_2"], 2).'</td>
                            </tr>';
                    }
                }
            }
            return $product_tr_list;
        }

        function bundleCardAPIDoc(){
            global $connection_server, $vid;
            $product_tr_list = "";
            $q = mysqli_query($connection_server, "SELECT p.*, prod.product_name FROM sas_databundle_plans p JOIN sas_products prod ON p.product_id = prod.id WHERE p.vendor_id='$vid' && p.status=1");

            while($plan = mysqli_fetch_assoc($q)){
                $dtype = $plan['data_type'];
                $pname = $plan['product_name'];
                $pid = $plan['product_id'];
                $pcode = $plan['plan_code'];

                $type_tables = ["sme-data" => "sas_sme_data_status", "shared-data" => "sas_shared_data_status", "cg-data" => "sas_cg_data_status", "dd-data" => "sas_dd_data_status"];
                $status_res = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT api_id FROM ".$type_tables[$dtype]." WHERE vendor_id='$vid' && product_name='$pname' && status=1"));
                if(!$status_res) continue;

                $api_id = $status_res['api_id'];

                $d1 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_2 FROM sas_smart_parameter_values WHERE vendor_id='$vid' AND api_id='$api_id' AND product_id='$pid' AND val_1='$pcode' AND status=1"));
                $d2 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_2 FROM sas_agent_parameter_values WHERE vendor_id='$vid' AND api_id='$api_id' AND product_id='$pid' AND val_1='$pcode' AND status=1"));
                $d3 = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT val_2 FROM sas_api_parameter_values WHERE vendor_id='$vid' AND api_id='$api_id' AND product_id='$pid' AND val_1='$pcode' AND status=1"));

                if($d1){
                    $product_tr_list .= '<tr>
                        <td class="fw-bold"><i class="bi bi-phone text-muted me-2"></i>Bundle Card</td>
                        <td><span class="badge bg-secondary">'.strtoupper($pname).'</span></td>
                        <td class="small fw-semibold text-muted">'.strtoupper(str_replace("-"," ",$dtype)).'</td>
                        <td class="fw-bold text-dark">Code: '.$pcode.'</td>
                        <td class="fw-bold text-primary">₦'.number_format($d1["val_2"], 2).'</td>
                        <td class="fw-bold text-warning">₦'.number_format($d2["val_2"], 2).'</td>
                        <td class="fw-bold text-success">₦'.number_format($d3["val_2"], 2).'</td>
                    </tr>';
                }
            }
            return $product_tr_list;
        }

        // Fetch All pricing data synchronously
        $airtime_html = airtimeAPIDoc();
        $data_html = dataAPIDoc();
        $cable_html = cableAPIDoc();
        $exam_html = examAPIDoc();
        $electric_html = electricAPIDoc();
        $recharge_html = dataRechargeAPIDoc();
        $sms_html = bulksmsAPIDoc();
        $bundle_card_html = bundleCardAPIDoc();

        // Empty state check
        $total_items = strlen($airtime_html) + strlen($data_html) + strlen($cable_html) + strlen($exam_html) + strlen($electric_html) + strlen($recharge_html) + strlen($sms_html) + strlen($bundle_card_html);
        $is_pricing_empty = ($total_items === 0);
    ?>

    <div class="container py-4 <?php echo !isset($_SESSION['user_session']) ? '' : 'main-content-vtu'; ?>">
        
        <!-- Header Banner Card -->
        <div class="card pricing-header-card mb-4 text-center text-sm-start">
            <div class="row align-items-center g-4">
                <div class="col-sm-8">
                    <h2 class="fw-bold mb-2">Service Pricing & Discounts</h2>
                    <p class="mb-0 opacity-75">Explore our transparent pricing tiers for retail users, registered agents, and developers using our high-speed APIs.</p>
                </div>
                <div class="col-sm-4 text-sm-end text-center">
                    <span class="badge bg-light text-primary py-2 px-3 fw-bold rounded-pill shadow-sm"><i class="bi bi-shield-check me-1"></i> Best VTU Rates</span>
                </div>
            </div>
        </div>

        <?php if($is_pricing_empty): ?>
            <!-- Fallback Empty State if Admin hasn't setup prices yet -->
            <?php $vendor_contact_email = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='$vid' LIMIT 1"))['email'] ?? ''; ?>
            <div class="empty-pricing-card text-center my-5 bg-white shadow-sm border rounded-4 py-5">
                <i class="bi bi-tags-fill text-muted mb-3 opacity-50" style="font-size: 4rem;"></i>
                <h4 class="fw-bold text-dark mb-2">Pricing Currently Unavailable</h4>
                <p class="text-muted mx-auto mb-4" style="max-width: 500px;">The administrator is currently configuring the pricing and discount tables for this portal. Please contact our support team or try again later.</p>
                <a href="mailto:<?php echo htmlspecialchars($vendor_contact_email); ?>" class="btn btn-primary rounded-pill px-4 fw-bold shadow"><i class="bi bi-envelope-fill me-2"></i>Contact Administrator</a>
            </div>
        <?php else: ?>
            
            <!-- Modern Responsive Search and Navigation Tabs -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
                <div class="search-box-wrap w-100">
                    <i class="bi bi-search"></i>
                    <input type="text" id="pricingSearch" onkeyup="filterPricingTable()" placeholder="Search networks, services, or plans..." class="search-input">
                </div>
                
                <!-- Pricing Tier Key Legend -->
                <div class="d-flex flex-wrap gap-2 text-muted small">
                    <span class="d-inline-flex align-items-center gap-1"><span class="badge-tier badge-tier-smart">Tier 1</span> Smart User</span>
                    <span class="d-inline-flex align-items-center gap-1"><span class="badge-tier badge-tier-agent">Tier 2</span> Agent Vendor</span>
                    <span class="d-inline-flex align-items-center gap-1"><span class="badge-tier badge-tier-api">Tier 3</span> API Partner</span>
                </div>
            </div>

            <!-- Tab Buttons -->
            <ul class="nav nav-pricing flex-wrap justify-content-center justify-content-md-start" id="pricingTabs" role="tablist">
                <?php
                    $tab_index = 0;
                    $tabs = [
                        ['id' => 'airtime', 'title' => 'Airtime', 'icon' => 'bi-telephone', 'html' => $airtime_html],
                        ['id' => 'data', 'title' => 'Data Bundle', 'icon' => 'bi-wifi', 'html' => $data_html],
                        ['id' => 'cable', 'title' => 'Cable TV', 'icon' => 'bi-tv', 'html' => $cable_html],
                        ['id' => 'electric', 'title' => 'Electricity', 'icon' => 'bi-lightning', 'html' => $electric_html],
                        ['id' => 'exam', 'title' => 'Exam Pin', 'icon' => 'bi-mortarboard', 'html' => $exam_html],
                        ['id' => 'recharge', 'title' => 'Recharge Card', 'icon' => 'bi-printer', 'html' => $recharge_html],
                        ['id' => 'sms', 'title' => 'Bulk SMS', 'icon' => 'bi-chat-dots', 'html' => $sms_html],
                        ['id' => 'bundle-card', 'title' => 'Bundle Card', 'icon' => 'bi-phone', 'html' => $bundle_card_html]
                    ];

                    foreach($tabs as $tab) {
                        if(empty($tab['html'])) continue;
                        $active_class = ($tab_index === 0) ? 'active' : '';
                        echo '<li class="nav-item" role="presentation">
                            <button class="nav-link '.$active_class.'" id="'.$tab['id'].'-tab" data-bs-toggle="tab" data-bs-target="#'.$tab['id'].'" type="button" role="tab" aria-selected="'.($tab_index === 0 ? 'true' : 'false').'" data-no-lock>
                                <i class="bi '.$tab['icon'].' me-1"></i> '.$tab['title'].'
                            </button>
                        </li>';
                        $tab_index++;
                    }
                ?>
            </ul>

            <!-- Tab Contents -->
            <div class="tab-content" id="pricingTabContent">
                <?php
                    $tab_index = 0;
                    foreach($tabs as $tab) {
                        if(empty($tab['html'])) continue;
                        $active_class = ($tab_index === 0) ? 'show active' : '';
                        ?>
                        <div class="tab-pane fade <?php echo $active_class; ?>" id="<?php echo $tab['id']; ?>" role="tabpanel" aria-labelledby="<?php echo $tab['id']; ?>-tab">
                            <div class="card pricing-table-card">
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped align-middle mb-0" id="<?php echo $tab['id']; ?>-table">
                                        <thead>
                                            <tr>
                                                <th>Service</th>
                                                <th>Brand</th>
                                                <th>Plan / Desc</th>
                                                <th>Detail</th>
                                                <th>Smart (T1)</th>
                                                <th>Agent (T2)</th>
                                                <th>API (T3)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php echo $tab['html']; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php
                        $tab_index++;
                    }
                ?>
            </div>

        <?php endif; ?>

        <!-- Secure Pricing Information Footer -->
        <div class="text-center mt-5 text-muted small pb-4">
            <p><i class="bi bi-shield-lock me-1"></i> All prices are standard and automatically converted inside our database. Secure connections powered by SSL.</p>
        </div>

    </div>

    <!-- Pricing Filter and Search Script -->
    <script>
        function filterPricingTable() {
            const input = document.getElementById("pricingSearch");
            const filter = input.value.toUpperCase();
            
            // Get all visible tab panes
            const activeTab = document.querySelector(".tab-pane.active");
            if (!activeTab) return;

            const table = activeTab.querySelector("table");
            if (!table) return;

            const tr = table.getElementsByTagName("tr");

            // Loop through all table rows in the active tab (skip header row 0)
            for (let i = 1; i < tr.length; i++) {
                let showRow = false;
                const tds = tr[i].getElementsByTagName("td");
                
                // Search across all text fields in the row
                for (let j = 0; j < tds.length; j++) {
                    const td = tds[j];
                    if (td) {
                        const txtValue = td.textContent || td.innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            showRow = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = showRow ? "" : "none";
            }
        }

        // Reset search filter on tab change
        document.addEventListener('shown.bs.tab', function (e) {
            document.getElementById("pricingSearch").value = "";
            const activeTab = document.querySelector(".tab-pane.active");
            if(activeTab) {
                const table = activeTab.querySelector("table");
                if(table) {
                    const tr = table.getElementsByTagName("tr");
                    for (let i = 1; i < tr.length; i++) {
                        tr[i].style.display = "";
                    }
                }
            }
        });
    </script>

    <?php include("../func/bc-footer.php"); ?>
</body>
</html>