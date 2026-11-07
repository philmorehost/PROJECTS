<?php session_start();
    include("../func/bc-admin-config.php");
    
    $user_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["userID"]))));
    $select_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$user_id_number'");
    if(mysqli_num_rows($select_user) > 0){
        $get_user_details = mysqli_fetch_array($select_user);
    }

    if(isset($_POST["upgrade-level"])){
    	$account_level_upgrade_array = array("smart" => 1, "agent" => 2, "api" => 3);
    	
        $upgrade_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["upgrade-type"])));
        
        if(!empty($upgrade_type) && in_array($upgrade_type, array_keys($account_level_upgrade_array))){
            $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
            if(mysqli_num_rows($check_user_details) == 1){
                $upgrade_signal = false;
                $get_user_info = mysqli_fetch_array($check_user_details);
                
                if($get_user_info["account_level"] == $account_level_upgrade_array[$upgrade_type]){
                    $upgrade_signal = true;
                }else{
                    if($get_user_info["account_level"] > $account_level_upgrade_array[$upgrade_type]){
                        $upgrade_signal = true;
                    }else{
                        if($get_user_info["account_level"] < $account_level_upgrade_array[$upgrade_type]){
                            $get_upgrade_price = mysqli_query($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id='".$get_logged_admin_details["id"]."' && account_type='".$account_level_upgrade_array[$upgrade_type]."'");
                            if(mysqli_num_rows($get_upgrade_price) == 1){
                                $upgrade_price = mysqli_fetch_array($get_upgrade_price);
                                if(!empty($upgrade_price["price"]) && is_numeric($upgrade_price["price"]) && ($upgrade_price["price"] > 0)){
                                    $transType = "debit";
                                    $userID = strtolower($get_user_info["username"]);
                                    $purchase_method = "WEB";
                                    $amount = $upgrade_price["price"];
                                    $discounted_amount = $amount;
                                    $type_alternative = ucwords("Account Upgrade");
                                    $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
                                    $description = ucwords(accountLevel($account_level_upgrade_array[$upgrade_type])." Upgrade charges By Admin");
                                    $status = 1;
                                    
                                    $debit_other_user = chargeOtherUser($userID, $transType, ucwords(accountLevel($account_level_upgrade_array[$upgrade_type])), $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], $status);
                                    if(in_array($debit_other_user, array("success"))){
                                        $upgrade_signal = true;
                                        $json_response_array = array("desc" => ucwords($get_user_info["username"])."`s Account Upgraded Successfully");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }
                                                                        
                                    if($debit_other_user == "failed"){
                                        $upgrade_signal = false;
                                        $json_response_array = array("desc" => "Upgrade Failed, Insufficient User Fund");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }		
                                }else{
                                    //Pricing Error, Contact Admin
                                    $json_response_array = array("desc" => "Pricing Error, Contact Admin");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }
                            }else{
                                //Error: Pricing Not Available, Contact Admin
                                $json_response_array = array("desc" => "Error: Pricing Not Available, Contact Admin");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }
                    }
                }

                if($upgrade_signal == true){
                    mysqli_query($connection_server, "UPDATE sas_users SET account_level='".$account_level_upgrade_array[$upgrade_type]."' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
                    //Account Upgraded To ... Level Successfully
                    $json_response_array = array("desc" => "Account Upgraded To ".accountLevel($account_level_upgrade_array[$upgrade_type])." Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                if(mysqli_num_rows($check_user_details) == 0){
                    //User Not Exists
                    $json_response_array = array("desc" => "User Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_user_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($upgrade_type)){
                //Upgrade Field Empty
                $json_response_array = array("desc" => "Upgrade Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(!in_array($upgrade_type, array_keys($account_level_upgrade_array))){
                    //Invalid Upgrade Level Code
                    $json_response_array = array("desc" => "Invalid Upgrade Level Code");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }
        }
    
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }    
?>
<!DOCTYPE html>
<head>
    <title>Upgrade User | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
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
      <h1>USER UPGRADE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">User Upgrade</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-5 col-md-8">
            <?php if(!empty($get_user_details['id'])){ ?>
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-4 border-0 text-center">
                        <div class="position-relative d-inline-block mb-3">
                            <div class="bg-primary bg-opacity-10 p-4 rounded-circle">
                                <i class="bi bi-arrow-up-circle text-dark-primary display-4"></i>
                            </div>
                            <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-success border border-2 border-white">
                                <i class="bi bi-check"></i>
                            </span>
                        </div>
                        <h4 class="fw-bold mb-1"><?php echo strtoupper($get_user_details['username']); ?></h4>
                        <p class="small text-muted mb-0">Current Level: <span class="badge bg-light text-dark fw-bold border"><?php echo accountLevel($get_user_details['account_level']); ?></span></p>
                    </div>
                    <div class="card-body p-4 p-md-5">
                        <form method="post" action="">
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Target Account Level</label>
                                <select name="upgrade-type" class="form-select form-select-lg bg-light border-0 py-3 rounded-3" required>
                                    <option value="" default hidden selected>Select new level...</option>
                                    <?php
                                        $account_level_upgrade_array = array(1 => "smart", 2 => "agent", 3 => "api");
                                        foreach($account_level_upgrade_array as $index => $account_levels){
                                            $get_upgrade_price = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id='".$get_logged_admin_details["id"]."' && account_type='".$index."' LIMIT 1"));
                                            $price = toDecimal($get_upgrade_price["price"] ?? 0, 2);
                                            $selected = ($index == $get_user_details['account_level']) ? 'selected' : '';
                                            $disabled = ($index < $get_user_details['account_level']) ? 'disabled' : '';
                                            echo '<option value="'.$account_levels.'" '.$selected.' '.$disabled.'>'.accountLevel($index).' (₦'.$price.')</option>';
                                        }
                                    ?>
                                </select>
                                <div class="mt-2 small text-muted text-center italic">
                                    <i class="bi bi-info-circle me-1"></i> Downgrading is handled manually by changing status.
                                </div>
                            </div>

                            <div class="mb-4">
                                 <div class="alert alert-info border-0 rounded-4 small py-3 px-4">
                                    <ul class="mb-0 ps-3">
                                        <li>User will be debited the upgrade fee automatically.</li>
                                        <li>Upgrade is permanent until manually reversed.</li>
                                        <li>New pricing will apply immediately to user.</li>
                                    </ul>
                                 </div>
                            </div>

                            <button name="upgrade-level" type="submit" class="btn btn-primary btn-lg w-100 rounded-4 py-3 fw-bold shadow-sm">
                                <i class="bi bi-rocket-takeoff-fill me-2"></i>APPLY UPGRADE
                            </button>
                        </form>
                    </div>
                </div>
            <?php }else{ ?>
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center">
                    <div class="bg-danger bg-opacity-10 p-4 rounded-circle d-inline-block mb-4 mx-auto">
                        <i class="bi bi-exclamation-octagon fs-1 text-danger"></i>
                    </div>
                    <h3 class="fw-bold">User Not Found</h3>
                    <p class="text-muted">The requested user account does not exist or has been removed from the system.</p>
                    <a href="Users.php" class="btn btn-outline-secondary rounded-pill px-4 mt-3">Back to Users</a>
                </div>
            <?php } ?>
        </div>
      </div>
    </section>
    <?php include("../func/bc-admin-footer.php"); ?>
    
</body>
</html>
