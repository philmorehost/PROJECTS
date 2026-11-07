<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    if(isset($_GET["account-status"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-status"])));
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-username"])));
        $account_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-id"])));
        $statusArray = array(1, 2, 3);
        if(is_numeric($status)){
            if(in_array($status, $statusArray)){
            	$send_mail_to_admin = false;
            	$get_admin_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$account_id' LIMIT 1"));
            	
                if($status == 1){
                    $alter_user_account_details = alterVendor($account_id, "status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_admin = true;
                        $json_response_array = array("desc" => ucwords($account_user." account activated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($account_user." account cannot be activated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                
                if($status == 2){
                    $alter_user_account_details = alterVendor($account_id, "status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_admin = true;
                        $json_response_array = array("desc" => ucwords($account_user." account deactivated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($account_user." account cannot be deactivated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }

                if($status == 3){
                    $alter_user_account_details = alterVendor($account_id, "status", $status);
                    if($alter_user_account_details == "success"){
                    	$send_mail_to_admin = true;
                        $json_response_array = array("desc" => ucwords($account_user." account deleted successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($account_user." account cannot be deleted"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                
                if($send_mail_to_admin == true){
                	// Email Beginning
                	$log_template_encoded_text_array = array("{firstname}" => $get_admin_details["firstname"], "{lastname}" => $get_admin_details["lastname"], "{account_status}" => accountStatus($status));
                	$raw_log_template_subject = getSuperAdminEmailTemplate('vendor-account-status','subject');
                	$raw_log_template_body = getSuperAdminEmailTemplate('vendor-account-status','body');
                	foreach($log_template_encoded_text_array as $array_key => $array_val){
                		$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                		$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                	}
                	sendSuperAdminEmail($get_admin_details["email"], $raw_log_template_subject, $raw_log_template_body);
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
        header("Location: /bc-spadmin/Vendors.php");
    }

    
    if(isset($_GET["account-log"])){
        $account_log = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-log"])));
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["type"] ?? '')));
        $json_response_encode = json_encode(['desc' => 'Processing...']);
        if(is_numeric($account_log)){
            if($account_log >= 1){
			    $get_logged_user_query = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".$account_log."'");
                if(mysqli_num_rows($get_logged_user_query) == 1){
                    $get_user_info = mysqli_fetch_array($get_logged_user_query);
                    $_SESSION["vendor_email"] = $get_user_info["email"];
                    $_SESSION["vendor_pass"] = $get_user_info["password"];
                    $_SESSION["admin_to_vendor_redirect_hostname"] = $get_user_info["website_url"];
                }else{
                    if(mysqli_num_rows($get_logged_user_query) < 1){
                        $json_response_array = array("desc" => "Error: Vendor not Exists");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($get_logged_user_query) > 1){
                            $json_response_array = array("desc" => "Error: Duplicate Vendor Accounts");
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
        if(isset($_SESSION["admin_to_vendor_redirect_hostname"]) && ($_SESSION["admin_to_vendor_redirect_hostname"] == true)){
            $vendors_auth_email = $_SESSION["vendor_email"];
            $vendors_auth_pass = base64_encode($_SESSION["vendor_pass"]);
            $vendors_url = $_SESSION["admin_to_vendor_redirect_hostname"];
            $vendors_auth_text = base64_encode($vendors_auth_email.":".$vendors_auth_pass);
            //Unset Vendor Session
            unset($_SESSION["vendor_email"]);
            unset($_SESSION["vendor_pass"]);
            unset($_SESSION["admin_to_vendor_redirect_hostname"]);
            $type_manage_api = "";
            if($type == "manageapi"){
                $type_manage_api = "&&redirect=MarketPlace.php";
            }
            header("Location: /bc-spadmin/Vendors.php?vendorUrl=".$vendors_url."&&vendorLogAuth=".$vendors_auth_text.$type_manage_api);
        }else{
            header("Location: /bc-spadmin/Vendors.php");
        }
    }
    
    /*if(isset($_GET["api-status"])){
        $status = mysqli_real_escape_string($connection_server, trim($_GET["api-status"]));
        $api_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["api-id"])));
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-username"])));
        $account_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-id"])));
        $api_detail = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["api-detail"])));

        $statusArray = array(0, 1);
        if(is_numeric($status)){
            if(in_array($status, $statusArray)){
                if($status == 1){
                    $alter_user_account_details = alterAPI($account_id, $api_id, "status", $status);
                    if($alter_user_account_details == "success"){
                        $json_response_array = array("desc" => ucwords($account_user." ".$api_detail." api activated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($account_user." ".$api_detail." cannot be activated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                
                if($status == 0){
                    $alter_user_account_details = alterAPI($account_id, $api_id, "status", $status);
                    if($alter_user_account_details == "success"){
                        $json_response_array = array("desc" => ucwords($account_user." ".$api_detail." deactivated successfully"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        $json_response_array = array("desc" => ucwords($account_user." ".$api_detail." cannot be deactivated"));
                        $json_response_encode = json_encode($json_response_array,true);
                    }
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
        header("Location: /bc-spadmin/Vendors.php");
    }*/

    
?>
<!DOCTYPE html>
<head>
    <title></title>
    <meta charset="UTF-8" />
    <meta name="description" content="" />
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
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

    <?php
    	//Redirect To Vendor Page
        $getVendorUrl = isset($_GET["vendorUrl"]) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["vendorUrl"]))) : "";
    	$getVendorLogAuth = isset($_GET["vendorLogAuth"]) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["vendorLogAuth"]))) : "";
        $getRedirectUrl = isset($_GET["redirect"]) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["redirect"]))) : "";
    	
    	if(isset($_GET["vendorUrl"]) && !empty($getVendorUrl) && isset($_GET["vendorLogAuth"]) && !empty($getVendorLogAuth)){
            // Always open the vendor's admin over HTTPS.
            //  * This used to be a hard-coded "http://", so impersonating a vendor from the super admin
            //    dropped the whole vendor session onto plain http - mixed content on a site served over
            //    https, and the one-time logVendorAdmin token sent in the clear.
            //  * $getVendorUrl arrives in the query string, so only a bare host is accepted here: a scheme,
            //    a path or "user@host" left in it would let the link pick the destination of an
            //    authenticated window (open redirect).
            $vendor_host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', (string)$getVendorUrl);
            $vendor_host = trim((string)preg_replace('#[^A-Za-z0-9.\-:].*$#', '', $vendor_host), '.');
            $vendor_admin_url = '';
            // Nothing usable left (empty or garbage host): open nothing rather than "https:///bc-admin/...",
            // which the browser would resolve against the current host.
            if ($vendor_host !== '') {
                $vendor_admin_url = 'https://' . $vendor_host . '/bc-admin/Dashboard.php?logVendorAdmin=' . rawurlencode($getVendorLogAuth);
                if(isset($_GET["redirect"]) && !empty($getRedirectUrl)){
                    $vendor_admin_url .= '&redirectAdminTo=' . rawurlencode($getRedirectUrl);
                }
            }
            if ($vendor_admin_url !== '') {
                // json_encode() keeps the URL safe inside the JS string literal (a raw quote in it previously
                // broke out of the string).
                echo '<script>	window.onload = function(){	window.open(' . json_encode($vendor_admin_url) . ',"_blank"); window.open("/bc-spadmin/Vendors.php","_self");	}	</script>';
            }
    	}
    ?>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>
    <div class="pagetitle">
      <h1>VIEW  VENDORS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Vendors</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">
            <?php
                $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
                $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
                $limit = 20;
                $offset = ($page_num - 1) * $limit;

                $search_statement = "";
                $search_parameter = "";
                if(!empty($searchq)){
                    $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                    $search_statement = " AND (email LIKE '%$search_esc%' OR phone_number LIKE '%$search_esc%' OR firstname LIKE '%$search_esc%' OR lastname LIKE '%$search_esc%' OR website_url LIKE '%$search_esc%')";
                    $search_parameter = "searchq=".urlencode($searchq)."&";
                }

                $get_active_user_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE status='1' $search_statement ORDER BY reg_date DESC LIMIT $limit OFFSET $offset");
                $get_inactive_user_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE (status='2' OR status='0') $search_statement ORDER BY reg_date DESC LIMIT $limit OFFSET $offset");
                $get_deleted_user_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE status='3' $search_statement ORDER BY reg_date DESC LIMIT $limit OFFSET $offset");
            ?>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-4 border-0">
                    <div class="row align-items-center g-3">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-0 text-primary">Vendor Directory</h5>
                            <p class="text-muted small mb-0">Manage all sub-vendors and their site configurations</p>
                        </div>
                        <div class="col-md-6">
                            <form method="get" action="Vendors.php" class="d-flex gap-2 justify-content-md-end">
                                <input name="searchq" type="text" value="<?php echo $searchq; ?>" placeholder="Email, Name, URL..." class="form-control" style="max-width: 250px;" />
                                <button type="submit" class="btn btn-primary px-4 fw-bold">Filter</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <!-- Active Vendors -->
                    <div class="mb-5">
                        <h6 class="fw-bold mb-3 text-success d-flex align-items-center"><i class="bi bi-check-circle-fill me-2"></i>Active Vendors</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border rounded-3 overflow-hidden">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-3">Vendor</th><th>URL</th><th>Financials</th><th>Security</th><th>Joined</th><th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($user = mysqli_fetch_assoc($get_active_user_details)): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle p-2 me-3 fw-bold small"><?php echo strtoupper(substr($user['firstname'],0,1).substr($user['lastname'],0,1)); ?></div>
                                                <div>
                                                    <div class="fw-bold text-dark mb-0"><?php echo $user['firstname'].' '.$user['lastname']; ?></div>
                                                    <div class="small text-muted"><?php echo $user['email']; ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <a href="//<?php echo $user['website_url']; ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-link-45deg me-1"></i>Site: <?php echo $user['website_url']; ?></a>
                                                <?php if(!empty($user['access_hash'])): ?>
                                                    <?php $vendor_portal_url = bc_temporary_vendor_portal_url($user['access_hash']); ?>
                                                    <?php if($vendor_portal_url !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($vendor_portal_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="extra-small text-primary mt-1 fw-bold"><i class="bi bi-shield-lock me-1"></i>Secure Portal</a>
                                                    <?php else: ?><span class="extra-small text-muted mt-1">Configure a temporary vendor domain to enable portal access.</span><?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold">₦<?php echo number_format($user['balance'],2); ?></div>
                                            <div class="small text-muted"><?php echo $user['phone_number']; ?></div>
                                        </td>
                                        <td>
                                            <?php if(!empty($user['bvn'])): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border me-1" title="Submitted"><i class="bi bi-check-circle-fill me-1"></i>BVN</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border me-1" title="Not Submitted"><i class="bi bi-x-circle-fill me-1"></i>BVN</span>
                                            <?php endif; ?>

                                            <?php if(!empty($user['nin'])): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border" title="Submitted"><i class="bi bi-check-circle-fill me-1"></i>NIN</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border" title="Not Submitted"><i class="bi bi-x-circle-fill me-1"></i>NIN</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <div class="text-muted"><?php echo date('M d, Y', strtotime($user['reg_date'])); ?></div>
                                            <?php
                                            $expired = ($user['expiry_date'] && strtotime($user['expiry_date']) < time());
                                            if($expired): ?>
                                                <span class="badge bg-danger rounded-pill mt-1" style="font-size: 10px;">EXPIRED</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border rounded-pill mt-1" style="font-size: 10px;">Expires: <?php echo $user['expiry_date'] ?? 'N/A'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="VendorEdit.php?vendorID=<?php echo $user['id']; ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                                <button onclick="loginVendorAccount('<?php echo $user['id']; ?>', '<?php echo $user['email']; ?>')" class="btn btn-outline-warning" title="Login"><i class="bi bi-box-arrow-in-right"></i></button>
                                                <a href="ManageVendorSubscription.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-info" title="Billing"><i class="bi bi-credit-card"></i></a>
                                                <button onclick="updateVendorAccountStatus('2','<?php echo $user['id']; ?>','<?php echo $user['email']; ?>')" class="btn btn-outline-danger" title="Suspend"><i class="bi bi-slash-circle"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Suspended Vendors -->
                    <div class="mb-5">
                        <h6 class="fw-bold mb-3 text-warning d-flex align-items-center"><i class="bi bi-slash-circle-fill me-2"></i>Suspended / Inactive Vendors</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border rounded-3 overflow-hidden">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-3">Vendor</th><th>URL</th><th>Status</th><th>Expiry</th><th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($user = mysqli_fetch_assoc($get_inactive_user_details)): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-dark mb-0"><?php echo $user['firstname'].' '.$user['lastname']; ?></div>
                                            <div class="small text-muted"><?php echo $user['email']; ?></div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <div class="small fw-bold"><?php echo $user['website_url']; ?></div>
                                                <?php if(!empty($user['access_hash'])): ?>
                                                    <?php $vendor_portal_url = bc_temporary_vendor_portal_url($user['access_hash']); ?>
                                                    <?php if($vendor_portal_url !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($vendor_portal_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="extra-small text-primary mt-1 fw-bold"><i class="bi bi-shield-lock me-1"></i>Secure Portal</a>
                                                    <?php else: ?><span class="extra-small text-muted mt-1">Configure a temporary vendor domain to enable portal access.</span><?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if($user['status'] == 0): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border">Expired/Locked</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning border">Suspended</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small"><?php echo $user['expiry_date'] ?? 'N/A'; ?></td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group btn-group-sm">
                                                <a href="VendorEdit.php?vendorID=<?php echo $user['id']; ?>" class="btn btn-outline-primary" title="Edit/Renew"><i class="bi bi-pencil"></i></a>
                                                <button onclick="updateVendorAccountStatus('1','<?php echo $user['id']; ?>','<?php echo $user['email']; ?>')" class="btn btn-outline-success" title="Activate"><i class="bi bi-check-circle"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if(mysqli_num_rows($get_inactive_user_details) == 0): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted small">No suspended vendors found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Deleted Vendors -->
                    <div>
                        <h6 class="fw-bold mb-3 text-danger d-flex align-items-center"><i class="bi bi-trash-fill me-2"></i>Deleted Vendors</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border rounded-3 overflow-hidden">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-3">Vendor</th><th>URL</th><th>Deleted On</th><th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($user = mysqli_fetch_assoc($get_deleted_user_details)): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-dark mb-0"><?php echo $user['firstname'].' '.$user['lastname']; ?></div>
                                            <div class="small text-muted"><?php echo $user['email']; ?></div>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <div class="small fw-bold"><?php echo $user['website_url']; ?></div>
                                                <?php if(!empty($user['access_hash'])): ?>
                                                    <?php $vendor_portal_url = bc_temporary_vendor_portal_url($user['access_hash']); ?>
                                                    <?php if($vendor_portal_url !== ''): ?>
                                                        <a href="<?php echo htmlspecialchars($vendor_portal_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="extra-small text-primary mt-1 fw-bold"><i class="bi bi-shield-lock me-1"></i>Secure Portal</a>
                                                    <?php else: ?><span class="extra-small text-muted mt-1">Configure a temporary vendor domain to enable portal access.</span><?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="small text-muted"><?php echo $user['reg_date']; ?></td>
                                        <td class="text-end pe-3">
                                            <button onclick="updateVendorAccountStatus('1','<?php echo $user['id']; ?>','<?php echo $user['email']; ?>')" class="btn btn-outline-success btn-sm px-3 rounded-pill">Restore</button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <?php if(mysqli_num_rows($get_deleted_user_details) == 0): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted small">No deleted vendors found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white py-4 border-0 text-center">
                    <div class="d-flex justify-content-center gap-2">
                        <?php if($page_num > 1): ?>
                        <a href="Vendors.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-4 rounded-pill">Previous Page</a>
                        <?php endif; ?>
                        <a href="Vendors.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num + 1); ?>" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">Next Page</a>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    function loginVendorAccount(id, email) {
        Swal.fire({
            title: 'Login as Vendor?',
            text: `You will be logged into ${email}'s dashboard in a new tab.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Login',
            confirmButtonColor: '#ffc107',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `Vendors.php?account-log=${id}`;
            }
        });
    }

    function updateVendorAccountStatus(status, id, email) {
        const actions = {
            '1': { title: 'Activate Vendor?', text: `Restore ${email}'s access?`, color: '#198754', btn: 'Activate' },
            '2': { title: 'Suspend Vendor?', text: `Lock ${email}'s access temporarily?`, color: '#dc3545', btn: 'Suspend' },
            '3': { title: 'Delete Vendor?', text: `Are you sure? This is irreversible!`, color: '#000', btn: 'Delete' }
        };
        const config = actions[status];

        Swal.fire({
            title: config.title,
            text: config.text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: config.btn,
            confirmButtonColor: config.color,
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `Vendors.php?account-status=${status}&account-id=${id}&account-username=${email}`;
            }
        });
    }
    </script>
</body>
</html>