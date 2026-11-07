<?php session_start();
    include("../func/bc-admin-config.php");

    if(isset($_POST["import-users"])){
        if(isset($_FILES['user-csv']) && $_FILES['user-csv']['error'] == 0){
            $file_tmp = $_FILES['user-csv']['tmp_name'];

            $file = fopen($file_tmp, "r");
            fgetcsv($file); // Skip header row

            while (($column = fgetcsv($file, 10000, ",")) !== FALSE) {
                $fullname = explode(" ", $column[1]);
                $firstname = $fullname[0] ?? '';
                $lastname = $fullname[1] ?? '';
                $othername = $fullname[2] ?? '';

                $username = $column[2];
                $level = array_search(strtolower($column[3]), array_map('strtolower', array(1 => "Smart Earner", 2 => "Agent Vendor", 3 => "API Vendor")));
                $balance = $column[4];
                $phone = $column[5];
                $address = $column[6];
                $referral_username = $column[7];
                $api_status = (strtolower($column[8]) == 'enabled') ? 1 : 0;
                $api_key = $column[9];
                $security_answer = $column[10];
                $reg_date = date("Y-m-d H:i:s", strtotime($column[11]));

                $referral_id = 0;
                if($referral_username != "Not Referred"){
                    $get_referral = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_users WHERE username = '$referral_username' AND vendor_id = '".$get_logged_admin_details["id"]."'"));
                    if($get_referral){
                        $referral_id = $get_referral['id'];
                    }
                }

                // For simplicity, let's assume email is the same as username and set a default password
                $email = $username;
                $password = password_hash("password123", PASSWORD_DEFAULT);

                $firstname = mysqli_real_escape_string($connection_server, $firstname);
                $lastname = mysqli_real_escape_string($connection_server, $lastname);
                $othername = mysqli_real_escape_string($connection_server, $othername);
                $username = mysqli_real_escape_string($connection_server, $username);
                $email = mysqli_real_escape_string($connection_server, $email);
                $level = mysqli_real_escape_string($connection_server, $level);
                $balance = mysqli_real_escape_string($connection_server, $balance);
                $phone = mysqli_real_escape_string($connection_server, $phone);
                $address = mysqli_real_escape_string($connection_server, $address);
                $api_key = mysqli_real_escape_string($connection_server, $api_key);
                $security_answer = mysqli_real_escape_string($connection_server, $security_answer);

                $sql = "INSERT INTO sas_users (vendor_id, firstname, lastname, othername, username, email, password, account_level, balance, phone_number, home_address, referral_id, api_status, api_key, security_answer, reg_date, status) VALUES ('".$get_logged_admin_details["id"]."', '$firstname', '$lastname', '$othername', '$username', '$email', '$password', '$level', '$balance', '$phone', '$address', '$referral_id', '$api_status', '$api_key', '$security_answer', '$reg_date', '1')";
                mysqli_query($connection_server, $sql);
            }

            fclose($file);
            $_SESSION["product_purchase_response"] = "Users imported successfully.";
        }else{
            $_SESSION["product_purchase_response"] = "Error uploading file.";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["export-users"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["status"])));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=users.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('S/N', 'Fullname', 'Username ID', 'Level', 'Balance', 'Phone number', 'Address', 'Referral', 'API Status', 'APIKey', 'Security Answer', 'Reg Date'));

        $sql = "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."'";
        if($status != 'all'){
            $sql .= " && status='$status'";
        }
        $result = mysqli_query($connection_server, $sql);
        $sn = 1;
        while($row = mysqli_fetch_assoc($result)){
            $fullname = $row['firstname'] . ' ' . $row['lastname'] . ' ' . $row['othername'];
            $referral_username = "Not Referred";
            if(!empty($row["referral_id"]) && is_numeric($row["referral_id"])){
                $get_user_referral_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$row["referral_id"]."'"));
                $referral_username = $get_user_referral_details["username"];
            }

            $api_status = ($row['api_status'] == 1) ? 'Enabled' : 'Disabled';

            fputcsv($output, array(
                $sn++,
                $fullname,
                $row['username'],
                accountLevel($row['account_level']),
                $row['balance'],
                $row['phone_number'],
                $row['home_address'],
                $referral_username,
                $api_status,
                $row['api_key'],
                $row['security_answer'],
                formDate($row['reg_date'])
            ));
        }
        fclose($output);
        exit();
    }
    
    if(isset($_GET["account-status"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-status"])));
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-username"])));
        $statusArray = array(1, 2, 3);
        if(is_numeric($status)){
            if(in_array($status, $statusArray)){
            	$send_mail_to_user = false;
            	$get_user_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && username='$account_user' LIMIT 1"));
            	
                if($status == 1){
                    $alter_user_account_details = alterUser($account_user, "status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_user = true;
                        $json_response_array = array("desc" => ucwords($account_user." account activated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($get_payment_order["username"]." account cannot be activated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                
                if($status == 2){
                    $alter_user_account_details = alterUser($account_user, "status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_user = true;
                        $json_response_array = array("desc" => ucwords($account_user." account deactivated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($get_payment_order["username"]." account cannot be deactivated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }

                if($status == 3){
                    $alter_user_account_details = alterUser($account_user, "status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_user = true;
                        $json_response_array = array("desc" => ucwords($account_user." account deleted successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($get_payment_order["username"]." account cannot be deleted"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                
                if($send_mail_to_user == true){
                	// Email Beginning
                	$log_template_encoded_text_array = array("{firstname}" => $get_user_details["firstname"], "{lastname}" => $get_user_details["lastname"], "{account_status}" => accountStatus($status));
                	$raw_log_template_subject = getUserEmailTemplate('user-account-status','subject');
                	$raw_log_template_body = getUserEmailTemplate('user-account-status','body');
                	foreach($log_template_encoded_text_array as $array_key => $array_val){
                		$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                		$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                	}
                	sendVendorEmail($get_user_details["email"], $raw_log_template_subject, $raw_log_template_body);
                	// Email End
                }
            }else{
                //Invalid Status Code
                $json_response_array = array("desc" => "Invalid Status Code");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Non-numeric string
            $json_response_array = array("desc" => "Non-numeric string");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: /bc-admin/Users.php");
        exit;
    }

    if(isset($_POST["permanent-delete-user"])){
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-username"])));
        $delete_user = mysqli_query($connection_server, "DELETE FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && username='$account_user' && status='3'");

        if($delete_user){
            $_SESSION["product_purchase_response"] = ucwords($account_user." account permanently deleted successfully");
        } else {
            $_SESSION["product_purchase_response"] = "Error: Could not permanently delete ".$account_user;
        }
        header("Location: /bc-admin/Users.php");
        exit();
    }

    if(isset($_GET["account-api-status"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-api-status"])));
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-username"])));
        $statusArray = array(1, 2);
        $statusArrayValue = array(1 => "Activated", 2 => "Deactivated");
        
        if(is_numeric($status)){
            if(in_array($status, $statusArray)){
            	$send_mail_to_user = false;
            	$get_user_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && username='$account_user' LIMIT 1"));
            	
                if($status == 1){
                    $alter_user_account_details = alterUser($account_user, "api_status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_user = true;
                        $json_response_array = array("desc" => ucwords($account_user." account status activated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($get_payment_order["username"]." account status cannot be activated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                
                if($status == 2){
                    $alter_user_account_details = alterUser($account_user, "api_status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_user = true;
                        $json_response_array = array("desc" => ucwords($account_user." account status deactivated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($get_payment_order["username"]." account status cannot be deactivated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                
                if($send_mail_to_user == true){
                	// Email Beginning
                	$log_template_encoded_text_array = array("{firstname}" => $get_user_details["firstname"], "{lastname}" => $get_user_details["lastname"], "{api_status}" => $statusArrayValue[$status]);
                	$raw_log_template_subject = getUserEmailTemplate('user-api-status','subject');
                	$raw_log_template_body = getUserEmailTemplate('user-api-status','body');
                	foreach($log_template_encoded_text_array as $array_key => $array_val){
                		$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                		$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                	}
                	sendVendorEmail($get_user_details["email"], $raw_log_template_subject, $raw_log_template_body);
                	// Email End
                }
            }else{
                //Invalid Status Code
                $json_response_array = array("desc" => "Invalid Status Code");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Non-numeric string
            $json_response_array = array("desc" => "Non-numeric string");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: /bc-admin/Users.php");
        exit;
    }

    if(isset($_GET["account-log"])){
        $account_log = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-log"])));
        if(is_numeric($account_log)){
            if($account_log >= 1){
			    $get_logged_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$account_log'");
                if(mysqli_num_rows($get_logged_user_query) == 1){
                    $get_user_info = mysqli_fetch_array($get_logged_user_query);
                    $_SESSION["user_session"] = $get_user_info["username"];
                    $_SESSION["admin_to_user_redirect"] = true;
                }else{
                    if(mysqli_num_rows($get_logged_user_query) < 1){
                        $json_response_array = array("desc" => "Error: User not Exists");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($get_logged_user_query) > 1){
                            $json_response_array = array("desc" => "Error: Duplicate User Accounts");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                }
            }else{
                //Invalid Account ID
                $json_response_array = array("desc" => "Invalid Account ID");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Non-numeric string
            $json_response_array = array("desc" => "Non-numeric string");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: /bc-admin/Users.php");
        exit;
    }
    
?>
<!DOCTYPE html>
<head>
    <title>Users | <?php echo $get_all_super_admin_site_details["site_title"] ?? 'DGV7 Platform'; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"] ?? 'The ultimate VTU and automated platform.', 0, 160); ?>" />
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

    <?php
    	//Redirect To User Page
    	if(isset($_SESSION["admin_to_user_redirect"]) && ($_SESSION["admin_to_user_redirect"] == true)){
    		echo '<script>	window.onload = function(){	window.open("'.$web_http_host.'/web/Dashboard.php","_blank");	}	</script>';
    	}
    ?>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    
    
    	<div class="pagetitle">
      <h1>USERS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Users</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <!-- Stats Summary Cards -->
        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card sales-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Total Users <span>| Platform</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary">
                  <i class="bi bi-people"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-total-users">0</h6>
                  <span class="text-muted small pt-2">Registered</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card revenue-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Active <span>| Accounts</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success">
                  <i class="bi bi-check-circle"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-active-users">0</h6>
                  <span class="text-muted small pt-2">Enabled</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card customers-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Blocked <span>| Restrictions</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning">
                  <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-blocked-users">0</h6>
                  <span class="text-muted small pt-2">Suspended</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card customers-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Deleted <span>| Archived</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger">
                  <i class="bi bi-trash"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-deleted-users">0</h6>
                  <span class="text-muted small pt-2">Removed</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0">Admin Tools</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm rounded-pill px-3" type="button" data-bs-toggle="collapse" data-bs-target="#importExportCollapse">
                            <i class="bi bi-tools me-1"></i> Import/Export
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-7">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input id="user-search-input" type="text" placeholder="Search by Email, Username, Phone, Name..." class="form-control border-start-0" />
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select id="user-status-filter" class="form-select">
                            <option value="1">Active Accounts</option>
                            <option value="2">Blocked Accounts</option>
                            <option value="3">Deleted Accounts</option>
                            <option value="all">All Accounts</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" onclick="fetchUsers(1)" class="btn btn-primary w-100">Apply Filter</button>
                    </div>
                </div>

                <div class="collapse" id="importExportCollapse">
                    <div class="row g-4 pt-3 border-top">
                        <div class="col-md-6 border-end">
                            <h6 class="fw-bold small text-muted text-uppercase mb-3">Import Users (CSV)</h6>
                            <form method="post" action="Users.php" enctype="multipart/form-data">
                                <div class="input-group">
                                    <input type="file" name="user-csv" class="form-control" accept=".csv" required>
                                    <button name="import-users" type="submit" class="btn btn-primary">Import</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold small text-muted text-uppercase mb-3">Export Users</h6>
                            <form method="post" action="Users.php" class="d-flex gap-2">
                                <select name="status" class="form-select">
                                    <option value="all">All Status</option>
                                    <option value="1">Active Only</option>
                                    <option value="2">Blocked Only</option>
                                    <option value="3">Deleted Only</option>
                                </select>
                                <button name="export-users" type="submit" class="btn btn-primary text-nowrap">Export CSV</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-primary" id="user-table-header">Users List</h6>
                <div id="pagination-top"></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="bg-light">
                      <tr>
                          <th class="ps-4">S/N</th><th>User Info</th><th>Account Details</th><th>Financials</th><th>Security & API</th><th>Dates</th><th class="pe-4">Actions</th>
                      </tr>
                    </thead>
                    <tbody id="user-table-body">
                        <!-- Content loaded via AJAX -->
                        <tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3">
                <div id="pagination-bottom" class="d-flex justify-content-center gap-2"></div>
            </div>
        </div>
            
        </div>
      </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        fetchUsers(1);

        // Live search with debounce
        let searchTimeout = null;
        document.getElementById("user-search-input").addEventListener("input", function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                fetchUsers(1);
            }, 500);
        });
    });

    function fetchUsers(page) {
        const search = document.getElementById("user-search-input").value;
        const status = document.getElementById("user-status-filter").value;
        const body = document.getElementById("user-table-body");

        // Show loading state
        body.innerHTML = `<tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>`;

        fetch(`ajax-users.php?page=${page}&searchq=${encodeURIComponent(search)}&status=${status}`)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    renderTable(res.users, res.pagination.current_page);
                    renderPagination(res.pagination);
                    updateStats(res.stats);
                } else {
                    body.innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger">${res.message}</td></tr>`;
                }
            })
            .catch(e => {
                body.innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger">Network Error</td></tr>`;
            });
    }

    function renderTable(users, currentPage) {
        const body = document.getElementById("user-table-body");
        if (users.length === 0) {
            body.innerHTML = `<tr><td colspan="7" class="text-center py-5 text-muted">No users found matching criteria.</td></tr>`;
            return;
        }

        let html = '';
        users.forEach((u, i) => {
            const sn = ((currentPage - 1) * 10) + (i + 1);

            let statusActions = '';
            if (u.status == '1') {
                statusActions = `<button class="btn btn-sm btn-light border text-danger" onclick="updateUserAccountStatus('2','${u.username}')" title="Block User"><i class="bi bi-ban"></i></button>
                                 <button class="btn btn-sm btn-light border text-success" onclick="updateUserAccountStatus('3','${u.username}')" title="Delete User"><i class="bi bi-trash"></i></button>`;
            } else if (u.status == '2') {
                statusActions = `<button class="btn btn-sm btn-light border text-primary" onclick="updateUserAccountStatus('1','${u.username}')" title="Activate User"><i class="bi bi-check-circle"></i></button>
                                 <button class="btn btn-sm btn-light border text-success" onclick="updateUserAccountStatus('3','${u.username}')" title="Delete User"><i class="bi bi-trash"></i></button>`;
            } else {
                statusActions = `<button class="btn btn-sm btn-light border text-primary" onclick="updateUserAccountStatus('1','${u.username}')" title="Activate User"><i class="bi bi-check-circle"></i></button>
                                 <button class="btn btn-sm btn-light border text-danger" onclick="permanentlyDeleteUser('${u.username}')" title="Permanently Delete"><i class="bi bi-trash-fill"></i></button>`;
            }

            const apiBadge = (u.api_status == '1')
                ? `<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-1" style="cursor:pointer" onclick="updateUserAccountAPIStatus('2','${u.username}')">API Enabled <i class="bi bi-toggle-on ms-1"></i></span>`
                : `<span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2 py-1" style="cursor:pointer" onclick="updateUserAccountAPIStatus('1','${u.username}')">API Disabled <i class="bi bi-toggle-off ms-1"></i></span>`;

            html += `
                <tr>
                    <td class="ps-4 fw-bold">${sn}</td>
                    <td>
                        <div class="fw-bold text-dark">${u.fullname}</div>
                        <div class="small text-muted">@${u.username} <button class="btn p-0 text-primary small" onclick="customJsRedirect('/bc-admin/UserEdit.php?userID=${u.id}', 'Edit @${u.username}?')"><i class="bi bi-pencil-square ms-1"></i></button></div>
                        <div class="small text-muted text-break" style="max-width: 150px;">${u.email}</div>
                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i>${u.phone_number}</div>
                    </td>
                    <td>
                        <div class="small fw-bold">${u.level_name} <button class="btn p-0 text-primary small" onclick="customJsRedirect('/bc-admin/UserUpgrade.php?userID=${u.id}', 'Upgrade @${u.username}?')"><i class="bi bi-arrow-down-up ms-1"></i></button></div>
                        <div class="small text-muted">Ref: ${u.referral_username}</div>
                        <div class="small text-muted text-truncate" style="max-width: 150px;" title="${u.home_address}"><i class="bi bi-geo-alt me-1"></i>${u.home_address}</div>
                    </td>
                    <td>
                        <div class="h6 fw-bold mb-1">₦${u.balance_formatted}</div>
                        <div class="small text-muted">Wallet Balance</div>
                    </td>
                    <td>
                        <div class="mb-2">${apiBadge}</div>
                        <div class="small text-muted">Ans: <span class="fw-bold text-dark">${u.security_answer || 'N/A'}</span></div>
                        <div class="small text-muted">Key: <span class="fw-bold text-dark">${u.api_key.substring(0,8)}...</span> <i class="bi bi-copy text-primary a-cursor" onclick="copyText('API Key copied','${u.api_key}')"></i></div>
                    </td>
                    <td>
                        <div class="small"><span class="text-muted">Reg:</span> ${u.reg_date_formatted}</div>
                        <div class="small"><span class="text-muted">Last:</span> ${u.last_login_formatted}</div>
                    </td>
                    <td>
                        <div class="d-flex gap-2 justify-content-end pe-3 mb-2">
                            ${statusActions}
                            <button class="btn btn-sm btn-light border text-primary" onclick="loginUserAccount('${u.id}', '${u.username}')" title="Login as User"><i class="bi bi-box-arrow-in-right"></i></button>
                        </div>
                        <div class="d-flex gap-2 justify-content-end pe-3">
                            <a href="Transactions.php?searchq=${u.username}" class="btn btn-sm btn-outline-info" title="View Transactions"><i class="bi bi-list-task"></i></a>
                            <a href="ShareFund.php?searchq=${u.username}" class="btn btn-sm btn-outline-success" title="Fund User"><i class="bi bi-plus-circle"></i></a>
                        </div>
                    </td>
                </tr>`;
        });
        body.innerHTML = html;
    }

    function renderPagination(p) {
        const container = document.getElementById("pagination-bottom");
        if (p.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '';
        if (p.current_page > 1) {
            html += `<button class="btn btn-outline-primary btn-sm" onclick="fetchUsers(${p.current_page - 1})">Prev</button>`;
        }

        // Show max 5 page numbers
        let start = Math.max(1, p.current_page - 2);
        let end = Math.min(p.total_pages, start + 4);
        if (end - start < 4) start = Math.max(1, end - 4);

        for (let i = start; i <= end; i++) {
            html += `<button class="btn btn-sm ${i === p.current_page ? 'btn-primary' : 'btn-outline-primary'}" onclick="fetchUsers(${i})">${i}</button>`;
        }

        if (p.current_page < p.total_pages) {
            html += `<button class="btn btn-outline-primary btn-sm" onclick="fetchUsers(${p.current_page + 1})">Next</button>`;
        }
        container.innerHTML = html;
    }

    function updateStats(s) {
        document.getElementById("stat-total-users").textContent = s.total;
        document.getElementById("stat-active-users").textContent = s.active;
        document.getElementById("stat-blocked-users").textContent = s.blocked;
        document.getElementById("stat-deleted-users").textContent = s.deleted;
    }

    function updateUserAccountStatus(status, username) {
        let action = '';
        if(status == 1) action = 'Activate';
        else if(status == 2) action = 'Block';
        else if(status == 3) action = 'Delete';

        Swal.fire({
            title: action + ' Account?',
            text: `Are you sure you want to ${action.toLowerCase()} account for @${username}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, proceed'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `Users.php?account-status=${status}&account-username=${username}`;
            }
        });
    }

    function updateUserAccountAPIStatus(status, username) {
        let action = (status == 1) ? 'Enable' : 'Disable';
        Swal.fire({
            title: action + ' API?',
            text: `Are you sure you want to ${action.toLowerCase()} API access for @${username}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, proceed'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `Users.php?account-api-status=${status}&account-username=${username}`;
            }
        });
    }

    function loginUserAccount(id, username) {
        Swal.fire({
            title: 'Login as User?',
            text: `You are about to login to @${username}'s account. Continue?`,
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Yes, login'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `Users.php?account-log=${id}`;
            }
        });
    }

    function permanentlyDeleteUser(username) {
        Swal.fire({
            title: 'Are you sure?',
            text: "This will permanently delete the account for @" + username + ". This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it permanently!'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'Users.php';

                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'permanent-delete-user';
                hiddenInput.value = '1';

                const usernameInput = document.createElement('input');
                usernameInput.type = 'hidden';
                usernameInput.name = 'account-username';
                usernameInput.value = username;

                form.appendChild(hiddenInput);
                form.appendChild(usernameInput);
                document.body.appendChild(form);
                form.submit();
            }
        })
    }
    </script>
    
</body>
</html>
