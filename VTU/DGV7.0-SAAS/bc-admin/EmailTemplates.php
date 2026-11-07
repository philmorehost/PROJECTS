<?php session_start();
    include("../func/bc-admin-config.php");
    
    if(isset($_POST["update-template"])){
        $subject = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["subject"])));
        $body = mysqli_real_escape_string($connection_server, trim($_POST["body"]));
        $body_json = mysqli_real_escape_string($connection_server, trim($_POST["body_json"]));
        $email_type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"]))));
        
        if(!empty($subject) && !empty($body) && !empty($email_type)){
            $template_details = mysqli_query($connection_server, "SELECT * FROM sas_email_templates WHERE vendor_id='".$get_logged_admin_details["id"]."' && email_type='$email_type'");
            if(mysqli_num_rows($template_details) == 1){
                mysqli_query($connection_server, "UPDATE sas_email_templates SET subject='$subject', body='$body', body_json='$body_json' WHERE vendor_id='".$get_logged_admin_details["id"]."' && email_type='$email_type'");
                //Email Template Updated Successfully
                $json_response_array = array("desc" => "Email Template Updated Successfully");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(mysqli_num_rows($template_details) > 1){
                    //Duplicated Details
                    $json_response_array = array("desc" => "Duplicated Details");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($template_details) == 0){
                        mysqli_query($connection_server, "INSERT INTO sas_email_templates (vendor_id, email_type, subject, body, body_json) VALUES ('".$get_logged_admin_details["id"]."', '$email_type', '$subject', '$body', '$body_json')");
                        //Email Template Created Successfully
                        $json_response_array = array("desc" => "Email Template Created Successfully");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($subject)){
                //Subject Field Empty
                $json_response_array = array("desc" => "Subject Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($body)){
                    //Body Field Empty
                    $json_response_array = array("desc" => "Body Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($email_type)){
                        //Email Type Field Empty
                        $json_response_array = array("desc" => "Email Type Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
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
    <title>Email Template | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

  <!-- GrapesJS -->
  <link href="https://unpkg.com/grapesjs/dist/css/grapes.min.css" rel="stylesheet">
  <script src="https://unpkg.com/grapesjs"></script>
  <script src="https://unpkg.com/grapesjs-preset-newsletter"></script>

  <style>
    .gjs-cv-canvas {
        top: 0;
        width: 100%;
        height: 100%;
    }
    #gjs {
        border: 3px solid #444;
    }
    .modal-full {
        min-width: 100%;
        margin: 0;
    }
    .modal-full .modal-content {
        min-height: 100vh;
    }
  </style>

</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>    
    
    <div class="pagetitle">
      <h1>EMAIL TEMPLATE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Email Template</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

<?php
// Asset Upload Handler
if (isset($_GET['action']) && $_GET['action'] == 'upload_asset' && isset($_FILES['files'])) {
    // Security: Verify Vendor session
    if (!isset($_SESSION['admin_session'])) {
        header("HTTP/1.1 403 Forbidden");
        echo json_encode(['error' => 'Unauthorized access']);
        exit;
    }

    $vid = $get_logged_admin_details['id'];
    $upload_dir = '../uploaded-image/vendor_' . $vid . '/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $responses = [];
    foreach ($_FILES['files']['name'] as $key => $name) {
        $tmp_name = $_FILES['files']['tmp_name'][$key];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_extensions)) continue;

        $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $upload_dir . $filename;

        if (move_uploaded_file($tmp_name, $target)) {
            $responses[] = [
                'src' => $web_http_host . '/uploaded-image/vendor_' . $vid . '/' . $filename,
                'type' => 'image'
            ];
        }
    }
    echo json_encode(['data' => $responses]);
    exit;
}
?>
    <section class="section dashboard">
      <div class="col-12">

        <!-- GrapesJS Modal -->
        <div class="modal fade" id="grapesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-full">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Email Builder</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div id="gjs"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="save-builder">Save Template</button>
                    </div>
                </div>
            </div>
        </div>

    	<div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER REGISTRATION TEMPLATE</span><br>
            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
                    <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Email address:</span> <span id="" class="" style="user-select: auto;">{email}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Phone number:</span> <span id="" class="" style="user-select: auto;">{phone}</span></span><br/>
					<span id="" class="h6"><span style="user-select: auto;">Username:</span> <span id="" class="" style="user-select: auto;">{username}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Home address:</span> <span id="" class="" style="user-select: auto;">{address}</span></span><br/>
    			</div><br/>
                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-reg" placeholder="Email Type" hidden readonly required/>
                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-reg','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
                <textarea style="text-align: left; resize: none;" id="body-user-reg" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-reg','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-reg" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-reg', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-reg')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>
    	
        <div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER LOGIN TEMPLATE</span><br>
            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
                    <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
					<span id="" class="h6"><span style="user-select: auto;">Username:</span> <span id="" class="" style="user-select: auto;">{username}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">IP address:</span> <span id="" class="" style="user-select: auto;">{ip_address}</span></span><br/>
                </div><br/>
                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-log" placeholder="Email Type" hidden readonly required/>
                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-log','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
                <textarea style="text-align: left; resize: none;" id="body-user-log" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-log','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-log" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-log', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-log')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>
    	
		<div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER PASSWORD UPDATE TEMPLATE</span><br>
    	            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
    	                    <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
    	                </div><br/>
    	                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-pass-update" placeholder="Email Type" hidden readonly required/>
    	                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-pass-update','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
	                <textarea style="text-align: left; resize: none;" id="body-user-pass-update" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-pass-update','body'); ?></textarea>
                    <input type="hidden" name="body_json" id="json-user-pass-update" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-pass-update', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-pass-update')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>
		
		<div style="text-align: center;" class="card info-card px-5 py-5">
			<span style="user-select: auto;" class="text-dark h5">USER ACCOUNT UPDATE TEMPLATE</span><br>
			<form method="post" enctype="multipart/form-data" action="">
				<div style="text-align: center;" class="container">
					<span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
					<span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
					<span id="" class="h6"><span style="user-select: auto;">Email address:</span> <span id="" class="" style="user-select: auto;">{email}</span></span>, 
					<span id="" class="h6"><span style="user-select: auto;">Phone number:</span> <span id="" class="" style="user-select: auto;">{phone}</span></span><br/>
					<span id="" class="h6"><span style="user-select: auto;">Home address:</span> <span id="" class="" style="user-select: auto;">{address}</span></span>, 
					<span id="" class="h6"><span style="user-select: auto;">Security answer:</span> <span id="" class="" style="user-select: auto;">{security_answer}</span></span><br/>
					
				</div><br/>
				<input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-account-update" placeholder="Email Type" hidden readonly required/>
				<input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-account-update','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
				<textarea style="text-align: left; resize: none;" id="body-user-account-update" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-account-update','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-account-update" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-account-update', 'body_json'), ENT_QUOTES); ?>'><br>
				<div class="d-flex gap-2 mb-2">
                    <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-account-update')">
                        <i class="bi bi-brush"></i> BUILDER
                    </button>
                    <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
                        UPDATE
                    </button>
                </div>
			</form>	
		</div><br/>
		
        <div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER PASSWORD RECOVERY TEMPLATE</span><br>
            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
                    <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Recovery Code:</span> <span id="" class="" style="user-select: auto;">{recovery_code}</span></span><br/>
                </div><br/>
                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-account-recovery" placeholder="Email Type" hidden readonly required/>
                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-account-recovery','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
                <textarea style="text-align: left; resize: none;" id="body-user-account-recovery" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-account-recovery','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-account-recovery" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-account-recovery', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-account-recovery')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>
        
        <div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER ACCOUNT STATUS TEMPLATE</span><br>
            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
                    <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Account Status:</span> <span id="" class="" style="user-select: auto;">{account_status}</span></span><br/>
                </div><br/>
                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-account-status" placeholder="Email Type" hidden readonly required/>
                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-account-status','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
                <textarea style="text-align: left; resize: none;" id="body-user-account-status" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-account-status','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-account-status" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-account-status', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-account-status')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>

        <div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER API STATUS TEMPLATE</span><br>
            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
                    <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">API Status:</span> <span id="" class="" style="user-select: auto;">{api_status}</span></span><br/>
                </div><br/>
                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-api-status" placeholder="Email Type" hidden readonly required/>
                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-api-status','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
                <textarea style="text-align: left; resize: none;" id="body-user-api-status" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-api-status','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-api-status" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-api-status', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-api-status')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>
    	
    	<div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER UPGRADE TEMPLATE</span><br>
    		<form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
    				<span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    				<span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
    				<span id="" class="h6"><span style="user-select: auto;">Account Level:</span> <span id="" class="" style="user-select: auto;">{account_level}</span></span><br/>
    			</div><br/>
    			<input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-upgrade" placeholder="Email Type" hidden readonly required/>
    			<input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-upgrade','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
			<textarea style="text-align: left; resize: none;" id="body-user-upgrade" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-upgrade','body'); ?></textarea>
            <input type="hidden" name="body_json" id="json-user-upgrade" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-upgrade', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-upgrade')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>
		
		<div style="text-align: center;" class="card info-card px-5 py-5">
			<span style="user-select: auto;" class="text-dark h5">USER REFERRAL COMMISSION TEMPLATE</span><br>
			<form method="post" enctype="multipart/form-data" action="">
				<div style="text-align: center;" class="container">
					<span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
					<span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
					<span id="" class="h6"><span style="user-select: auto;">Referral Commission:</span> <span id="" class="" style="user-select: auto;">{referral_commission}</span></span><br/>
					<span id="" class="h6"><span style="user-select: auto;">Referree:</span> <span id="" class="" style="user-select: auto;">{referree}</span></span>, 
					<span id="" class="h6"><span style="user-select: auto;">Account Level:</span> <span id="" class="" style="user-select: auto;">{account_level}</span></span><br/>
				</div><br/>
				<input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-referral-commission" placeholder="Email Type" hidden readonly required/>
				<input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-referral-commission','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
				<textarea style="text-align: left; resize: none;" id="body-user-referral-commission" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-referral-commission','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-referral-commission" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-referral-commission', 'body_json'), ENT_QUOTES); ?>'><br>
				<div class="d-flex gap-2 mb-2">
                    <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-referral-commission')">
                        <i class="bi bi-brush"></i> BUILDER
                    </button>
                    <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
                        UPDATE
                    </button>
                </div>
			</form>	
		</div><br/>
		
        <div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER TRANSACTION (ADMIN) TEMPLATE</span><br>
            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
					<span id="" class="h6"><span style="user-select: auto;">Admin Fullname:</span> <span id="" class="" style="user-select: auto;">{admin_firstname}</span>, <span id="" class="" style="user-select: auto;">{admin_lastname}</span></span><br/>
    		        <span id="" class="h6"><span style="user-select: auto;">Username:</span> <span id="" class="" style="user-select: auto;">{username}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Balance Before:</span> <span id="" class="" style="user-select: auto;">{balance_before}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Balance After:</span> <span id="" class="" style="user-select: auto;">{balance_after}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Amount Charged:</span> <span id="" class="" style="user-select: auto;">{amount}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Description:</span> <span id="" class="" style="user-select: auto;">{description}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Transaction Type:</span> <span id="" class="" style="user-select: auto;">{type}</span></span><br/>
                </div><br/>
                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-transactions" placeholder="Email Type" hidden readonly required/>
                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-transactions','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
                <textarea style="text-align: left; resize: none;" id="body-user-transactions" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-transactions','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-transactions" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-transactions', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-transactions')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>

        <div style="text-align: center;" class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="text-dark h5">USER CREDIT/DEBIT TEMPLATE</span><br>
            <form method="post" enctype="multipart/form-data" action="">
    			<div style="text-align: center;" class="container">
                    <span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Balance Before:</span> <span id="" class="" style="user-select: auto;">{balance_before}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Balance After:</span> <span id="" class="" style="user-select: auto;">{balance_after}</span></span><br/>
                    <span id="" class="h6"><span style="user-select: auto;">Amount Charged:</span> <span id="" class="" style="user-select: auto;">{amount}</span></span>, 
    		        <span id="" class="h6"><span style="user-select: auto;">Description:</span> <span id="" class="" style="user-select: auto;">{description}</span></span><br/>
    		        <span id="" class="h6"><span style="user-select: auto;">Transaction Type:</span> <span id="" class="" style="user-select: auto;">{type}</span></span><br/>
                </div><br/>
                <input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-funding" placeholder="Email Type" hidden readonly required/>
                <input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-funding','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
                <textarea style="text-align: left; resize: none;" id="body-user-funding" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-funding','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-funding" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-funding', 'body_json'), ENT_QUOTES); ?>'><br>
			<div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-funding')">
                    <i class="bi bi-brush"></i> BUILDER
                </button>
                <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
				UPDATE
			</button>
            </div>
    		</form>	
    	</div><br/>

		
		<div style="text-align: center;" class="card info-card px-5 py-5">
			<span style="user-select: auto;" class="text-dark h5">USER REFUND TEMPLATE</span><br>
			<form method="post" enctype="multipart/form-data" action="">
				<div style="text-align: center;" class="container">
					<span id="" class="h6"><span style="user-select: auto;">Firstname:</span> <span id="" class="" style="user-select: auto;">{firstname}</span></span>, 
					<span id="" class="h6"><span style="user-select: auto;">Lastname:</span> <span id="" class="" style="user-select: auto;">{lastname}</span></span><br/>
					<span id="" class="h6"><span style="user-select: auto;">Amount:</span> <span id="" class="" style="user-select: auto;">{amount}</span></span>, 
					<span id="" class="h6"><span style="user-select: auto;">Description:</span> <span id="" class="" style="user-select: auto;">{description}</span></span><br/>
				</div><br/>
				<input style="text-align: left;" id="" name="type" onkeyup="" type="text" value="user-refund" placeholder="Email Type" hidden readonly required/>
				<input style="text-align: left;" id="" name="subject" onkeyup="" type="text" value="<?php echo getVendorEmailTemplate('user-refund','subject'); ?>" placeholder="Email Subject" class="form-control mb-1" required/><br/>
				<textarea style="text-align: left; resize: none;" id="body-user-refund" name="body" onkeyup="" placeholder="Email Body" class="form-control mb-1" rows="10" required><?php echo getVendorEmailTemplate('user-refund','body'); ?></textarea>
                <input type="hidden" name="body_json" id="json-user-refund" value='<?php echo htmlspecialchars(getVendorEmailTemplate('user-refund', 'body_json'), ENT_QUOTES); ?>'><br>
				<div class="d-flex gap-2 mb-2">
                    <button type="button" class="btn btn-info col-6 text-white" onclick="openBuilder('user-refund')">
                        <i class="bi bi-brush"></i> BUILDER
                    </button>
                    <button name="update-template" type="submit" style="user-select: auto;" class="btn btn-primary col-6" >
                        UPDATE
                    </button>
                </div>
			</form>	
		</div><br/>
		
        
      </div>
      </section>
        
    <?php include("../func/bc-admin-footer.php"); ?>
    
    <script>
        let editor;
        let currentKey;

        function openBuilder(type) {
            currentKey = type;
            const content = document.getElementById('body-' + type).value;
            const jsonContent = document.getElementById('json-' + type).value;

            if (!editor) {
                editor = grapesjs.init({
                    container: '#gjs',
                    fromElement: false,
                    height: '70vh',
                    width: 'auto',
                    storageManager: false,
                    plugins: ['grapesjs-preset-newsletter'],
                    pluginsOpts: {
                        'grapesjs-preset-newsletter': {}
                    },
                    assetManager: {
                        upload: '?action=upload_asset',
                        params: { vid: '<?php echo $get_logged_admin_details["id"]; ?>' }
                    }
                });
            }

            if (jsonContent && jsonContent.trim() !== '') {
                try {
                    editor.setComponents(JSON.parse(jsonContent));
                } catch (e) {
                    editor.setComponents(content);
                }
            } else {
                editor.setComponents(content);
            }

            const modal = new bootstrap.Modal(document.getElementById('grapesModal'));
            modal.show();
        }

        document.getElementById('save-builder').addEventListener('click', function() {
            const html = editor.runCommand('gjs-get-inlined-html');
            const json = JSON.stringify(editor.getComponents());

            document.getElementById('body-' + currentKey).value = html;
            document.getElementById('json-' + currentKey).value = json;

            bootstrap.Modal.getInstance(document.getElementById('grapesModal')).hide();
        });
    </script>
</body>
</html>
