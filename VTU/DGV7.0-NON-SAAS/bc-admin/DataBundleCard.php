<?php session_start();
    include("../func/bc-admin-config.php");
    include_once("../func/bc-func.php");

    $service_type = mysqli_real_escape_string($connection_server, $_GET["type"] ?? 'data');
    $service_titles = ["data" => "Data Bundle", "airtime" => "Airtime", "cable" => "Cable TV", "electric" => "Electricity", "exam" => "Exam Pins", "betting" => "Betting"];
    $current_title = $service_titles[$service_type] ?? "Print Hub";

    if(isset($_GET["action"]) && $_GET["action"] == "get-plans" && isset($_GET["network_id"]) && isset($_GET["data_type"])){
        $network_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["network_id"])));
        $data_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["data_type"])));
        $s_type = mysqli_real_escape_string($connection_server, $_GET["service_type"] ?? 'data');

        if ($s_type == 'data') {
            $data_type_status_tables = ["sme-data" => "sas_sme_data_status", "shared-data" => "sas_shared_data_status", "cg-data" => "sas_cg_data_status", "dd-data" => "sas_dd_data_status"];
            $status_table = $data_type_status_tables[$data_type];
        } else {
            $service_status_tables = ["airtime" => "sas_airtime_status", "cable" => "sas_cable_status", "electric" => "sas_electric_status", "exam" => "sas_exam_status", "betting" => "sas_betting_status"];
            $status_table = $service_status_tables[$s_type];
        }

        $get_prod = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE id='$network_id'"));
        $network_name = $get_prod['product_name'];

        $get_status = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $status_table WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_name='$network_name'"));

        $api_id = $get_status['api_id'];

        $get_plans = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_id='$api_id' && product_id='$network_id' AND status=1");

        $plans = [];
        while($plan = mysqli_fetch_assoc($get_plans)){
            $plans[] = ["code" => $plan['val_1'], "price" => $plan['val_2'], "validity" => $plan['val_3'], "name" => $plan['val_4']];
        }
        echo json_encode($plans);
        exit();
    }

    if(isset($_POST["update-sms-bridge"])){
        $secret = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["print_hub_secret"])));
        mysqli_query($connection_server, "UPDATE sas_vendors SET print_hub_secret='$secret' WHERE id='".$get_logged_admin_details["id"]."'");

        $networks = ["mtn", "airtel", "glo", "9mobile", "dstv", "gotv", "startimes", "aedc", "ekedc", "ikedc", "ibedc", "phed", "kedco", "eedc", "yedc", "bedc", "waec", "neco", "nabteb", "bet9ja", "betking", "1xbet"];
        foreach($networks as $network){
            if(isset($_POST[$network."_sms"])){
                $sms_number = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST[$network."_sms"])));
                $check = mysqli_query($connection_server, "SELECT * FROM sas_databundle_config WHERE vendor_id='".$get_logged_admin_details["id"]."' && network='$network' AND service_type='$service_type'");
                if(mysqli_num_rows($check) == 0){
                    mysqli_query($connection_server, "INSERT INTO sas_databundle_config (vendor_id, network, service_type, sms_to_number) VALUES ('".$get_logged_admin_details["id"]."', '$network', '$service_type', '$sms_number')");
                }else{
                    mysqli_query($connection_server, "UPDATE sas_databundle_config SET sms_to_number='$sms_number' WHERE vendor_id='".$get_logged_admin_details["id"]."' && network='$network' AND service_type='$service_type'");
                }
            }
        }
        $_SESSION["product_purchase_response"] = "SMS Bridge settings updated successfully.";
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_POST["update-ussd-settings"])){
        $username = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["hollatags_username"])));
        $password = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["hollatags_password"])));
        $ussd_code = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["hollatags_ussd_code"])));
        $channel_mode = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ussd_channel_mode"])));

        mysqli_query($connection_server, "UPDATE sas_vendors SET hollatags_username='$username', hollatags_password='$password', hollatags_ussd_code='$ussd_code', ussd_channel_mode='$channel_mode' WHERE id='".$get_logged_admin_details["id"]."'");

        $networks = ["mtn", "airtel", "glo", "9mobile", "dstv", "gotv", "startimes", "aedc", "ekedc", "ikedc", "ibedc", "phed", "kedco", "eedc", "yedc", "bedc", "waec", "neco", "nabteb", "bet9ja", "betking", "1xbet"];
        foreach($networks as $network){
            $ussd_enabled = isset($_POST[$network."_ussd_enabled"]) ? 1 : 0;
            $ussd_code_val = isset($_POST[$network."_ussd_code"]) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_POST[$network."_ussd_code"]))) : '';

            $check = mysqli_query($connection_server, "SELECT * FROM sas_databundle_config WHERE vendor_id='".$get_logged_admin_details["id"]."' && network='$network' AND service_type='$service_type'");
            if(mysqli_num_rows($check) == 0){
                mysqli_query($connection_server, "INSERT INTO sas_databundle_config (vendor_id, network, service_type, ussd_channel_enabled, ussd_code) VALUES ('".$get_logged_admin_details["id"]."', '$network', '$service_type', '$ussd_enabled', '$ussd_code_val')");
            }else{
                mysqli_query($connection_server, "UPDATE sas_databundle_config SET ussd_channel_enabled='$ussd_enabled', ussd_code='$ussd_code_val' WHERE vendor_id='".$get_logged_admin_details["id"]."' && network='$network' AND service_type='$service_type'");
            }
        }
        $_SESSION["product_purchase_response"] = "USSD Channel settings updated successfully.";
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_POST["update-ussd-charges"])){
        $act_fee = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ussd_activation_fee"])));
        $call_charge = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ussd_per_call_charge"])));

        mysqli_query($connection_server, "UPDATE sas_vendors SET ussd_activation_fee='$act_fee', ussd_per_call_charge='$call_charge' WHERE id='".$get_logged_admin_details["id"]."'");
        $_SESSION["product_purchase_response"] = "USSD Fees updated successfully.";
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_GET["action"]) && $_GET["action"] == "revoke-ussd" && isset($_GET["id"])){
        $act_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["id"])));
        mysqli_query($connection_server, "UPDATE sas_ussd_activations SET status=0 WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$act_id'");
        $_SESSION["product_purchase_response"] = "USSD access revoked successfully.";
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_GET["action"]) && $_GET["action"] == "toggle-plan" && isset($_GET["id"])){
        $plan_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["id"])));
        $current_status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["status"])));
        $new_status = ($current_status == 1) ? 0 : 1;

        mysqli_query($connection_server, "UPDATE sas_databundle_plans SET status='$new_status' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$plan_id'");
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_GET["action"]) && $_GET["action"] == "delete-plan" && isset($_GET["id"])){
        $plan_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["id"])));

        mysqli_query($connection_server, "DELETE FROM sas_databundle_plans WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$plan_id'");
        $_SESSION["product_purchase_response"] = "Plan deleted successfully.";
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_POST["process-epin"])){
        include_once("../func/bc-epin-fulfillment.php");
        $epin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["epin_to_process"])));
        $recipient = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["recipient_phone"])));
        $extra = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["extra_data"] ?? "")));

        $result = fulfillEPIN($epin, $recipient, $extra);
        $_SESSION["product_purchase_response"] = ($result['status'] == 'success' ? "Processed: " : "Error: ") . $result['message'];

        header("Location: DataBundleCard.php?type=$service_type&query_pin=".$epin);
        exit();
    }

    if(isset($_POST["clear-all-epin-plans"])){
        mysqli_query($connection_server, "DELETE FROM sas_databundle_plans WHERE vendor_id='".$get_logged_admin_details["id"]."' AND service_type='$service_type'");
        $_SESSION["product_purchase_response"] = "All EPIN-enabled $service_type plans cleared successfully.";
        header("Location: DataBundleCard.php?type=$service_type");
        exit();
    }

    if(isset($_POST["add-epin-plan"])){
        $s_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["service_type"])));
        $d_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["data_type"])));
        $network_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["network_id"])));
        $plan_code = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["plan_code"])));
        $validity = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["validity_days"])));
        $price = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["price"])));
        $price_agent = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["price_agent"])));
        $price_api = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["price_api"])));

        $check = mysqli_query($connection_server, "SELECT * FROM sas_databundle_plans WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$network_id' && service_type='$s_type' && data_type='$d_type' && plan_code='$plan_code'");
        if(mysqli_num_rows($check) == 0){
            mysqli_query($connection_server, "INSERT INTO sas_databundle_plans (vendor_id, product_id, service_type, data_type, plan_code, validity_days, price, price_agent, price_api, status) VALUES ('".$get_logged_admin_details["id"]."', '$network_id', '$s_type', '$d_type', '$plan_code', '$validity', '$price', '$price_agent', '$price_api', 1)");
            $_SESSION["product_purchase_response"] = "Plan added to EPIN list.";
        }else{
            mysqli_query($connection_server, "UPDATE sas_databundle_plans SET validity_days='$validity', price='$price', price_agent='$price_agent', price_api='$price_api' WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$network_id' && service_type='$s_type' && data_type='$d_type' && plan_code='$plan_code'");
            $_SESSION["product_purchase_response"] = "Plan updated in EPIN list.";
        }
        header("Location: DataBundleCard.php?type=$s_type");
        exit();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $current_title; ?> Card EPIN | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1><?php echo $current_title; ?> Card EPIN</h1>
                <nav>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active"><?php echo $current_title; ?> Card</li>
                    </ol>
                </nav>
            </div>
            <div class="d-flex gap-2 mb-3 mb-md-0">
                <a href="PrintHub.php" class="btn btn-outline-primary rounded-pill px-4">
                    <i class="bi bi-arrow-left me-1"></i> Back to Hub
                </a>
                <button type="button" class="btn btn-info rounded-pill px-4 text-white" data-bs-toggle="modal" data-bs-target="#setupGuideModal">
                    <i class="bi bi-info-circle me-1"></i> Setup Guide
                </button>
            </div>
        </div>
    </div>

    <section class="section dashboard">
      <div class="row g-4">
        <div class="col-lg-4">
          <div class="card shadow-sm border-0 rounded-4 mb-4 overflow-hidden">
            <div class="card-header bg-light p-2 border-bottom">
              <ul class="nav nav-tabs card-header-tabs" id="printHubSettingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active fw-bold small py-2 px-3" id="sms-bridge-tab" data-bs-toggle="tab" data-bs-target="#sms-bridge-pane" type="button" role="tab"><i class="bi bi-phone me-1 text-success"></i>SMS Bridge</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link fw-bold small py-2 px-3" id="ussd-channel-tab" data-bs-toggle="tab" data-bs-target="#ussd-channel-pane" type="button" role="tab"><i class="bi bi-cpu me-1 text-primary"></i>USSD Channel</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link fw-bold small py-2 px-3" id="ussd-charges-tab" data-bs-toggle="tab" data-bs-target="#ussd-charges-pane" type="button" role="tab"><i class="bi bi-wallet2 me-1 text-warning"></i>USSD Fees</button>
                </li>
              </ul>
            </div>
            <div class="card-body p-3 tab-content" id="printHubSettingsContent">
              <!-- Tab 1: SMS Bridge -->
              <div class="tab-pane fade show active" id="sms-bridge-pane" role="tabpanel">
                <p class="small text-muted mb-3 mt-1">Configure automated SMS APK Bridge fulfillment settings.</p>
                <form method="post" action="">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Hub Secret Key</label>
                        <input type="text" name="print_hub_secret" class="form-control" value="<?php echo $get_logged_admin_details['print_hub_secret'] ?? ''; ?>" placeholder="Enter secret key">
                    </div>
                    <div class="alert alert-light border small py-2 mb-3">
                        <strong>Webhook URL:</strong><br>
                        <span class="user-select-all text-break">https://<?php echo $_SERVER['HTTP_HOST']; ?>/web/api/endpoint.php</span>
                    </div>
                    <h6 class="fw-bold small border-bottom pb-2 text-uppercase text-muted"><i class="bi bi-telephone me-2"></i>Gateway Numbers</h6>
                    <?php
                    if($service_type == 'data' || $service_type == 'airtime'){
                        $networks = ["mtn", "airtel", "glo", "9mobile"];
                    } elseif($service_type == 'cable') {
                        $networks = ["dstv", "gotv", "startimes"];
                    } elseif($service_type == 'electric') {
                        $networks = ["aedc", "ekedc", "ikedc", "ibedc", "phed", "kedco", "eedc", "yedc", "bedc"];
                    } elseif($service_type == 'exam') {
                        $networks = ["waec", "neco", "nabteb"];
                    } elseif($service_type == 'betting') {
                        $networks = ["bet9ja", "betking", "1xbet"];
                    } else {
                        $networks = [];
                    }
                    foreach($networks as $network){
                        $get_config = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_databundle_config WHERE vendor_id='".$get_logged_admin_details["id"]."' && network='$network' AND service_type='$service_type'"));
                        echo '<div class="mb-2">
                                <label class="form-label small text-muted mb-1">'.strtoupper($network).' SMS-To Number</label>
                                <input type="text" name="'.$network.'_sms" class="form-control form-control-sm" value="'.($get_config ? $get_config['sms_to_number'] : '').'" placeholder="Enter phone number">
                              </div>';
                    }
                    ?>
                    <button type="submit" name="update-sms-bridge" class="btn btn-success btn-sm w-100 rounded-pill fw-bold shadow-sm mt-3 py-2">Save SMS Settings</button>
                </form>
              </div>

              <!-- Tab 2: USSD Channel -->
              <div class="tab-pane fade" id="ussd-channel-pane" role="tabpanel">
                <p class="small text-muted mb-3 mt-1">Configure Hollatags Shared USSD parameters.</p>
                <form method="post" action="">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Hollatags Username</label>
                        <input type="text" name="hollatags_username" class="form-control form-control-sm" value="<?php echo $get_logged_admin_details['hollatags_username'] ?? ''; ?>" placeholder="Enter username">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Hollatags Password</label>
                        <input type="password" name="hollatags_password" class="form-control form-control-sm" value="<?php echo $get_logged_admin_details['hollatags_password'] ?? ''; ?>" placeholder="Enter password">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">USSD Shortcode / Dial String</label>
                        <input type="text" name="hollatags_ussd_code" class="form-control form-control-sm" value="<?php echo $get_logged_admin_details['hollatags_ussd_code'] ?? ''; ?>" placeholder="e.g. *384*241#">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Redemption Channel Mode</label>
                        <select name="ussd_channel_mode" class="form-select form-select-sm">
                            <option value="Both" <?php echo (($get_logged_admin_details['ussd_channel_mode'] ?? 'Both') == 'Both') ? 'selected' : ''; ?>>Both (SMS & USSD)</option>
                            <option value="SMS Only" <?php echo (($get_logged_admin_details['ussd_channel_mode'] ?? 'Both') == 'SMS Only') ? 'selected' : ''; ?>>SMS Only</option>
                            <option value="USSD Only" <?php echo (($get_logged_admin_details['ussd_channel_mode'] ?? 'Both') == 'USSD Only') ? 'selected' : ''; ?>>USSD Only</option>
                        </select>
                    </div>
                    <div class="alert alert-light border small py-2 mb-3">
                        <strong>Hollatags Callback URL:</strong><br>
                        <span class="user-select-all text-break">https://<?php echo $_SERVER['HTTP_HOST']; ?>/web/api/ussd-endpoint.php</span>
                    </div>

                    <h6 class="fw-bold small border-bottom pb-2 text-uppercase text-muted"><i class="bi bi-cpu me-2"></i>Network Activation</h6>
                    <?php
                    foreach($networks as $network){
                        $get_config = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_databundle_config WHERE vendor_id='".$get_logged_admin_details["id"]."' && network='$network' AND service_type='$service_type'"));
                        $checked = ($get_config && $get_config['ussd_channel_enabled'] == 1) ? 'checked' : '';
                        $ussd_code_val = $get_config ? $get_config['ussd_code'] : '';
                        echo '<div class="border p-2 rounded mb-2 bg-light">
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" name="'.$network.'_ussd_enabled" id="'.$network.'_ussd_enabled" '.$checked.'>
                                    <label class="form-check-label small fw-bold" for="'.$network.'_ussd_enabled">Enable USSD ('.strtoupper($network).')</label>
                                </div>
                                <div>
                                    <label class="small text-muted">Sub-code Identifier (Optional)</label>
                                    <input type="text" name="'.$network.'_ussd_code" class="form-control form-control-sm" value="'.$ussd_code_val.'" placeholder="e.g. '.$network.'">
                                </div>
                              </div>';
                    }
                    ?>
                    <button type="submit" name="update-ussd-settings" class="btn btn-primary btn-sm w-100 rounded-pill fw-bold shadow-sm mt-3 py-2">Save USSD Settings</button>
                </form>
              </div>

              <!-- Tab 3: USSD Charges -->
              <div class="tab-pane fade" id="ussd-charges-pane" role="tabpanel">
                <p class="small text-muted mb-3 mt-1">Set fees charged to resellers for USSD access.</p>
                <form method="post" action="">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">One-time USSD Activation Fee (₦)</label>
                        <input type="number" name="ussd_activation_fee" step="any" class="form-control" value="<?php echo $get_logged_admin_details['ussd_activation_fee'] ?? '0.00'; ?>" placeholder="0.00">
                        <div class="form-text small text-muted">Deducted when reseller enables USSD on their hub settings.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Per-USSD Call Charge (₦)</label>
                        <input type="number" name="ussd_per_call_charge" step="any" class="form-control" value="<?php echo $get_logged_admin_details['ussd_per_call_charge'] ?? '0.00'; ?>" placeholder="0.00">
                        <div class="form-text small text-muted">Deducted from reseller wallet balance per successful USSD delivery.</div>
                    </div>
                    <button type="submit" name="update-ussd-charges" class="btn btn-warning btn-sm w-100 rounded-pill fw-bold shadow-sm py-2 mb-3">Update Fees</button>
                </form>

                <h6 class="fw-bold small border-bottom pb-2 text-uppercase text-muted"><i class="bi bi-people me-2"></i>Activated Resellers</h6>
                <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr class="small text-muted">
                                <th>Reseller</th>
                                <th>Date</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php
                            $get_activations = mysqli_query($connection_server, "SELECT a.*, u.username FROM sas_ussd_activations a JOIN sas_users u ON a.user_id = u.id WHERE a.vendor_id='".$get_logged_admin_details["id"]."' AND a.status=1");
                            if (mysqli_num_rows($get_activations) > 0) {
                                while($act = mysqli_fetch_assoc($get_activations)) {
                                    echo '<tr>
                                            <td>@'.$act['username'].'</td>
                                            <td>'.date("d M y", strtotime($act['activated_at'])).'</td>
                                            <td class="text-end"><a href="?type='.$service_type.'&action=revoke-ussd&id='.$act['id'].'" class="btn btn-outline-danger btn-xs" onclick="return confirm(\'Revoke USSD access for this reseller?\')">Revoke</a></td>
                                          </tr>';
                                }
                            } else {
                                echo '<tr><td colspan="3" class="text-center py-3 text-muted">No activated resellers.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow-sm border-0 rounded-4 mb-4 overflow-hidden border-start border-danger border-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title mb-0 text-danger fs-6"><i class="bi bi-exclamation-triangle-fill me-2"></i>Danger Zone</h5>
            </div>
            <div class="card-body p-4 text-center">
                <p class="small text-muted mb-3">Wipe all EPIN-enabled <strong><?php echo $service_type; ?></strong> plans from the configuration.</p>
                <form method="post" action="" onsubmit="return confirm('Are you sure you want to clear ALL EPIN plans for this service?');">
                    <button name="clear-all-epin-plans" type="submit" class="btn btn-outline-danger w-100 rounded-pill fw-bold small py-2">
                        <i class="bi bi-eraser-fill me-2"></i>Clear All EPIN Plans
                    </button>
                </form>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fs-6 text-primary"><i class="bi bi-list-task me-2"></i>Enabled <?php echo $current_title; ?> Plans</h5>
            </div>
            <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                  <tr>
                    <th class="ps-4">Provider</th>
                    <th>Type</th>
                    <th>Plan</th>
                    <th>Validity</th>
                    <th>Smart (N)</th>
                    <th>Agent (N)</th>
                    <th>API (N)</th>
                    <th>Status</th>
                    <th class="pe-4 text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $get_epin_plans = mysqli_query($connection_server, "SELECT p.*, prod.product_name FROM sas_databundle_plans p JOIN sas_products prod ON p.product_id = prod.id WHERE p.vendor_id='".$get_logged_admin_details["id"]."' AND p.service_type='$service_type'");
                    while($plan = mysqli_fetch_assoc($get_epin_plans)){
                      $status_text = ($plan['status'] == 1) ? '<span class="text-success">Enabled</span>' : '<span class="text-danger">Disabled</span>';
                      $btn_class = ($plan['status'] == 1) ? 'btn-warning' : 'btn-primary';
                      $btn_text = ($plan['status'] == 1) ? 'Disable' : 'Enable';
                      echo '<tr>
                              <td class="ps-4 fw-bold">'.strtoupper($plan['product_name']).'</td>
                              <td><span class="badge bg-info bg-opacity-10 text-info rounded-pill px-2">'.strtoupper(str_replace("-"," ",$plan['data_type'])).'</span></td>
                              <td class="small fw-semibold">'.strtoupper($plan['plan_code']).'</td>
                              <td class="small text-muted">'.$plan['validity_days'].' Days</td>
                              <td class="fw-bold text-primary">'.number_format($plan['price'], 2).'</td>
                              <td class="fw-bold text-success">'.number_format($plan['price_agent'], 2).'</td>
                              <td class="fw-bold text-info">'.number_format($plan['price_api'], 2).'</td>
                              <td>'.$status_text.'</td>
                              <td class="pe-4 text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="?type='.$service_type.'&action=toggle-plan&id='.$plan['id'].'&status='.$plan['status'].'" class="btn btn-outline-secondary" title="'.$btn_text.'"><i class="bi bi-'.($plan['status'] == 1 ? 'pause' : 'play').'-fill"></i></a>
                                    <a href="?type='.$service_type.'&action=delete-plan&id='.$plan['id'].'" class="btn btn-outline-danger" onclick="return confirm(\'Are you sure you want to delete this plan?\')" title="Delete"><i class="bi bi-trash"></i></a>
                                </div>
                              </td>
                            </tr>';
                    }
                  ?>
                </tbody>
              </table>
            </div>
            </div>
            </div>
            <div class="card-footer bg-light p-4 border-0">
            <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Add New EPIN-enabled <?php echo $current_title; ?></h6>
            <form method="post" class="row g-3">
              <input type="hidden" name="service_type" value="<?php echo $service_type; ?>">
              <div class="col-md-4">
                <label class="form-label small fw-bold">Network Provider</label>
                <select id="new_network_id" name="network_id" class="form-select rounded-3" onchange="loadPlans()" required>
                  <option value="">Select Provider</option>
                  <?php
                    $provider_match = "";
                    if($service_type == 'data' || $service_type == 'airtime') $provider_match = "(product_name='mtn' OR product_name='airtel' OR product_name='glo' OR product_name='9mobile')";
                    elseif($service_type == 'cable') $provider_match = "(product_name='dstv' OR product_name='gotv' OR product_name='startimes')";
                    elseif($service_type == 'electric') $provider_match = "(product_name='aedc' OR product_name='ekedc' OR product_name='ikedc' OR product_name='ibedc' OR product_name='phed' OR product_name='kedco' OR product_name='eedc' OR product_name='yedc' OR product_name='bedc')";
                    elseif($service_type == 'exam') $provider_match = "(product_name='waec' OR product_name='neco' OR product_name='nabteb')";
                    elseif($service_type == 'betting') $provider_match = "(product_name='bet9ja' OR product_name='betking' OR product_name='1xbet')";

                    $get_prods = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='".$get_logged_admin_details["id"]."' && $provider_match");
                    while($prod = mysqli_fetch_assoc($get_prods)){
                      echo '<option value="'.$prod['id'].'">'.strtoupper($prod['product_name']).'</option>';
                    }
                  ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-bold">Service Type</label>
                <select id="new_data_type" name="data_type" class="form-select rounded-3" onchange="loadPlans()" required>
                  <?php if($service_type == 'data'): ?>
                    <option value="">Select Type</option>
                    <option value="sme-data">SME Data</option>
                    <option value="shared-data">Shared Data</option>
                    <option value="cg-data">Corporate Data</option>
                    <option value="dd-data">Direct Data</option>
                  <?php else: ?>
                    <option value="<?php echo $service_type; ?>"><?php echo ucwords($service_type); ?></option>
                  <?php endif; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-bold">Select Plan / Denomination</label>
                <?php if($service_type == 'airtime'): ?>
                    <input type="text" id="new_plan_code" name="plan_code" class="form-control rounded-3" placeholder="e.g. 100, 200" required>
                    <input type="hidden" id="new_validity_days" name="validity_days" value="0">
                <?php else: ?>
                    <select id="new_plan_select" name="plan_select" class="form-select rounded-3" onchange="updatePlanDetails()" required>
                        <option value="">Select Plan</option>
                    </select>
                    <input type="hidden" id="new_plan_code" name="plan_code">
                    <input type="hidden" id="new_validity_days" name="validity_days">
                <?php endif; ?>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold text-primary">Smart Level (N)</label>
                <input type="number" step="any" id="new_price" name="price" class="form-control rounded-3" placeholder="0.00" required>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold text-success">Agent Level (N)</label>
                <input type="number" step="any" id="new_price_agent" name="price_agent" class="form-control rounded-3" placeholder="0.00" required>
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-bold text-info">API Level (N)</label>
                <input type="number" step="any" id="new_price_api" name="price_api" class="form-control rounded-3" placeholder="0.00" required>
              </div>
              <div class="col-12">
                <button type="submit" name="add-epin-plan" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm py-2 mt-2">
                    <i class="bi bi-cloud-plus me-2"></i>Add Plan to Printing Configuration
                </button>
              </div>
            </form>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-2">
        <div class="col-lg-12">
          <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fs-6 text-primary"><i class="bi bi-cpu me-2"></i>Manual Processing & Tracking</h5>
            </div>
            <div class="card-body p-4">
            <div class="row g-4">
              <div class="col-md-6 border-end">
                <h6 class="fw-bold mb-3">Query EPIN Status</h6>
                <form method="get" class="d-flex mb-4">
                  <input type="hidden" name="type" value="<?php echo $service_type; ?>">
                  <input type="text" name="query_pin" class="form-control me-2" placeholder="Enter EPIN (e.g. 5002-0648-4709)" value="<?php echo isset($_GET['query_pin']) ? $_GET['query_pin'] : ''; ?>">
                  <button type="submit" class="btn btn-primary px-4">Query</button>
                </form>

                <?php
                  if(isset($_GET['query_pin']) && !empty($_GET['query_pin'])){
                    $pin = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET['query_pin'])));
                    $get_pin = mysqli_query($connection_server, "SELECT c.*, u.username FROM sas_databundle_cards c LEFT JOIN sas_users u ON c.user_id = u.id WHERE c.vendor_id='".$get_logged_admin_details["id"]."' && c.epin='$pin'");
                    if(mysqli_num_rows($get_pin) == 1){
                      $card = mysqli_fetch_assoc($get_pin);
                      echo '<div class="card border-0 bg-light p-3 rounded-4 mb-4">
                              <h6 class="fw-bold text-primary mb-3">PIN Analysis:</h6>
                              <div class="row g-2">
                                <div class="col-6 small text-muted">Provider:</div><div class="col-6 small fw-bold text-end text-uppercase">'.$card['network'].'</div>
                                <div class="col-6 small text-muted">Service:</div><div class="col-6 small fw-bold text-end">'.ucwords($card['service_type']).' ('.strtoupper(str_replace("-"," ",$card['data_type'])).')</div>
                                <div class="col-6 small text-muted">Plan:</div><div class="col-6 small fw-bold text-end">'.$card['plan_name'].($card['validity'] > 0 ? " (".$card['validity']." Days)" : "").'</div>
                                <div class="col-6 small text-muted">Price:</div><div class="col-6 small fw-bold text-end text-primary">N'.number_format($card['price'], 2).'</div>
                                <div class="col-6 small text-muted">Status:</div><div class="col-6 text-end"><span class="badge bg-'.($card['status'] == 'Available' ? 'success' : ($card['status'] == 'Sold' ? 'primary' : 'secondary')).' px-3 rounded-pill">'.$card['status'].'</span></div>
                                <div class="col-6 small text-muted">Purchased By:</div><div class="col-6 small fw-bold text-end">@'.$card['username'].'</div>
                              </div>
                            </div>';

                      if($card['status'] == 'Sold'){
                        $needs_extra = in_array($card['service_type'], ['cable', 'electric', 'betting']);
                        $extra_placeholder = "Meter/IUC/UserID";
                        if($card['service_type'] == 'cable') $extra_placeholder = "SmartCard / IUC Number";
                        elseif($card['service_type'] == 'electric') $extra_placeholder = "Meter Number";
                        elseif($card['service_type'] == 'betting') $extra_placeholder = "Betting User ID";

                        echo '<form method="post" action="" class="bg-white p-3 rounded-4 border">
                                <h6 class="fw-bold mb-3 text-success"><i class="bi bi-lightning-charge me-2"></i>Trigger Fulfillment</h6>
                                <input type="hidden" name="epin_to_process" value="'.$card['epin'].'">
                                <div class="mb-3">
                                  <label class="form-label small fw-bold">Recipient Phone Number (Notification)</label>
                                  <input type="text" name="recipient_phone" class="form-control rounded-3" placeholder="081..." required>
                                </div>';
                        if($needs_extra){
                            echo '<div class="mb-3">
                                  <label class="form-label small fw-bold">'.$extra_placeholder.'</label>
                                  <input type="text" name="extra_data" class="form-control" placeholder="Enter ID" required>
                                </div>';
                        }
                        echo '<button type="submit" name="process-epin" class="btn btn-success w-100 rounded-pill fw-bold shadow-sm py-2">PROCESS & DELIVER SERVICE</button>
                              </form>';
                      }elseif($card['status'] == 'Used'){
                        echo '<div class="alert alert-warning">This PIN has already been used on '.$card['date_used'].' for <strong>'.$card['processed_phone_number'].'</strong></div>';
                      }
                    }else{
                      echo '<div class="alert alert-danger">PIN not found in database.</div>';
                    }
                  }
                ?>
              </div>
              <div class="col-md-6 ps-md-4">
                <h6 class="fw-bold mb-3">Recent EPIN Activity</h6>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light small text-muted">
                      <tr>
                        <th class="border-0">PIN</th>
                        <th class="border-0">Provider</th>
                        <th class="border-0">Status</th>
                        <th class="border-0">Date</th>
                      </tr>
                    </thead>
                    <tbody class="small">
                      <?php
                        $get_recent = mysqli_query($connection_server, "SELECT * FROM sas_databundle_cards WHERE vendor_id='".$get_logged_admin_details["id"]."' AND service_type='$service_type' ORDER BY date_generated DESC LIMIT 15");
                        if(mysqli_num_rows($get_recent) > 0){
                          while($recent = mysqli_fetch_assoc($get_recent)){
                            echo '<tr>
                                    <td class="fw-bold">'.$recent['epin'].'</td>
                                    <td>'.strtoupper($recent['network']).'</td>
                                    <td><span class="badge bg-'.($recent['status'] == 'Available' ? 'success' : ($recent['status'] == 'Sold' ? 'primary' : 'secondary')).' rounded-pill px-2" style="font-size: 0.65rem;">'.$recent['status'].'</span></td>
                                    <td class="text-muted" style="font-size: 0.65rem;">'.date("M d, H:i", strtotime($recent['date_generated'])).'</td>
                                  </tr>';
                          }
                        } else {
                          echo '<tr><td colspan="4" class="text-center py-4 text-muted small">No recent activity found.</td></tr>';
                        }
                      ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Setup Guide Modal -->
    <div class="modal fade" id="setupGuideModal" tabindex="-1" aria-labelledby="setupGuideModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
          <div class="modal-header bg-info text-white border-0">
            <h5 class="modal-title fw-bold" id="setupGuideModalLabel"><?php echo $current_title; ?> EPIN Setup Guide</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <div class="accordion accordion-flush" id="setupAccordion">
              <div class="accordion-item">
                <h2 class="accordion-header" id="headingOne">
                  <button class="accordion-button fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne">
                    1. Configure Gateway Numbers
                  </button>
                </h2>
                <div id="collapseOne" class="accordion-collapse collapse show" data-bs-parent="#setupAccordion">
                  <div class="accordion-body">
                    In the <strong>Gateway Numbers</strong> section, enter the phone number for each provider where users will send their EPIN via SMS. These numbers will be printed on the cards.
                  </div>
                </div>
              </div>
              <div class="accordion-item">
                <h2 class="accordion-header" id="headingTwo">
                  <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo">
                    2. Enable Plans for EPIN
                  </button>
                </h2>
                <div id="collapseTwo" class="accordion-collapse collapse" data-bs-parent="#setupAccordion">
                  <div class="accordion-body">
                    Select a Provider and Type. The system fetches plans from your pricing config. Add the plan to offer it as an EPIN.
                  </div>
                </div>
              </div>
              <div class="accordion-item">
                <h2 class="accordion-header" id="headingThree">
                  <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree">
                    3. User Experience
                  </button>
                </h2>
                <div id="collapseThree" class="accordion-collapse collapse" data-bs-parent="#setupAccordion">
                  <div class="accordion-body">
                    Users buy the card from their dashboard, print it, and follow instructions to recharge via SMS.
                  </div>
                </div>
              </div>
              <div class="accordion-item">
                <h2 class="accordion-header" id="headingFour">
                  <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFour">
                    4. Automated Fulfillment
                  </button>
                </h2>
                <div id="collapseFour" class="accordion-collapse collapse" data-bs-parent="#setupAccordion">
                  <div class="accordion-body">
                    Use our <strong>Android Bridge</strong> (API endpoint) to automate fulfillment. When an SMS is received by your phone, the app sends it to the site, which triggers the API instantly.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php include("../func/bc-admin-footer.php"); ?>
    <script>
        function loadPlans() {
            const networkId = document.getElementById('new_network_id').value;
            const dataType = document.getElementById('new_data_type').value;
            const planSelect = document.getElementById('new_plan_select');

            if (!networkId || !dataType) return;

            planSelect.innerHTML = '<option value="">Loading...</option>';

            fetch(`?action=get-plans&network_id=${networkId}&data_type=${dataType}&service_type=<?php echo $service_type; ?>`)
                .then(response => response.json())
                .then(data => {
                    planSelect.innerHTML = '<option value="">Select Plan</option>';
                    data.forEach(plan => {
                        const option = document.createElement('option');
                        option.value = plan.code;
                        option.dataset.validity = plan.validity;
                        option.dataset.price = plan.price;
                        const planName = plan.name || plan.code;
                        option.textContent = `${planName.toUpperCase()} - N${plan.price}`;
                        planSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error loading plans:', error);
                    planSelect.innerHTML = '<option value="">Error loading plans</option>';
                });
        }

        function updatePlanDetails() {
            const planSelect = document.getElementById('new_plan_select');
            if(!planSelect) return;
            const selectedOption = planSelect.options[planSelect.selectedIndex];

            document.getElementById('new_plan_code').value = selectedOption.value;
            document.getElementById('new_validity_days').value = selectedOption.dataset.validity || '0';
            document.getElementById('new_price').value = selectedOption.dataset.price || '0';
            document.getElementById('new_price_agent').value = selectedOption.dataset.price || '0';
            document.getElementById('new_price_api').value = selectedOption.dataset.price || '0';
        }
    </script>
</body>
</html>
