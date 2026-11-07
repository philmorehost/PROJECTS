<?php session_start();
    include("../func/bc-config.php");
    
    if(isset($_POST["place-order"])){
        $vendor_id = resolveVendorID();
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
        $last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
        $phone = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["phone"]))));
        $bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bank-code"])));
        $account_number = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-number"])));
        $bvn = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["bvn"]))));
        $nin = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["nin"]))));
        
        $unrefined_website_url = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["website-url"]))));
        $refined_website_url = trim(str_replace(["https","http",":/","/","www."," "],"",$unrefined_website_url));
        $website_url = $refined_website_url;
        
        // Fetch valid extensions from database
        $ext_q = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY extension ASC");
        $valid_exts = [];
        while ($row = mysqli_fetch_assoc($ext_q)) {
            $valid_exts[] = strtolower(trim($row['extension']));
        }
        if (empty($valid_exts)) {
            $valid_exts = ['.com', '.ng', '.com.ng', '.org', '.net'];
        }

        $has_extension = false;
        foreach ($valid_exts as $ext) {
            if (substr($website_url, -strlen($ext)) === $ext) {
                $has_extension = true;
                break;
            }
        }

        if (!$has_extension) {
            $ext_examples = implode(', ', $valid_exts);
            $json_response_array = array("desc" => "Please enter your domain name along with its extension (e.g., website" . $valid_exts[0] . "). Available extensions: " . $ext_examples);
            $json_response_encode = json_encode($json_response_array, true);
            $json_response_decode = json_decode($json_response_encode, true);
            $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
        
        if(!empty($first) && !empty($last) && !empty($address) && !empty($email) && !empty($pass) && !empty($phone) && !empty($website_url)){
            if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                $check_vendor_details_with_email = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='$email'");
                if(mysqli_num_rows($check_vendor_details_with_email) == 0){
                    $check_vendor_details_with_url = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE website_url='$website_url'");
                    if(mysqli_num_rows($check_vendor_details_with_url) == 0){
                        $md5_pass = bc_hash_password($pass); // stored as password_hash(); bc-admin/Login.php also accepts legacy md5
                        if(!empty($bank_code) && is_numeric($bank_code) && (strlen($bank_code) >= 1)){
                    		$refined_bank_code = $bank_code;
                    	}else{
                    		$refined_bank_code = "";
                    	}
                    	
                    	if(!empty($account_number) && is_numeric($account_number) && (strlen($account_number) == 10)){
                    		$refined_account_number = $account_number;
                    	}else{
                    		$refined_account_number = "";
                    	}

                        if(!empty($bvn) && is_numeric($bvn) && (strlen($bvn) == 11)){
                        	$refined_bvn = $bvn;
                        }else{
                        	$refined_bvn = "";
                        }
                        
                        if(!empty($nin) && is_numeric($nin) && (strlen($nin) == 11)){
                        	$refined_nin = $nin;
                        }else{
                        	$refined_nin = "";
                        }
                        
                        mysqli_query($connection_server, "INSERT INTO sas_vendors (website_url, email, password, firstname, lastname, phone_number, balance, home_address, bank_code, account_number, bvn, nin, status) VALUES ('$website_url', '$email', '$md5_pass', '$first', '$last', '$phone', '0', '$address', '$refined_bank_code', '$refined_account_number', '$refined_bvn', '$refined_nin', '1')");
                        $new_vendor_id = mysqli_insert_id($connection_server);
                        seedVendorBlog($new_vendor_id);
                        // Email Beginning
                        $reg_template_encoded_text_array = array("{firstname}" => $first, "{lastname}" => $last, "{address}" => $address, "{email}" => $email, "{phone}" => $phone, "{website}" => $website_url);
                        $raw_reg_template_subject = getSuperAdminEmailTemplate('vendor-reg','subject');
                        $raw_reg_template_body = getSuperAdminEmailTemplate('vendor-reg','body');
                        foreach($reg_template_encoded_text_array as $array_key => $array_val){
                        	$raw_reg_template_subject = str_replace($array_key, $array_val, $raw_reg_template_subject);
                        	$raw_reg_template_body = str_replace($array_key, $array_val, $raw_reg_template_body);
                        }
                        sendVendorEmail($email, $raw_reg_template_subject, $raw_reg_template_body);
                        // Email End
                        //Vendor Profile Information Created Successfully
                        $json_response_array = array("desc" => "Vendor Profile Information Created Successfully");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($check_vendor_details_with_url) == 1){
                            //Vendor With Same Website Url Exists
                            $json_response_array = array("desc" => "Vendor With Same Website Url Exists");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(mysqli_num_rows($check_vendor_details_with_url) > 1){
                                //Duplicated Website Url Details, Contact Admin
                                $json_response_array = array("desc" => "Duplicated Website Url  Details, Contact Admin");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }
                    }
                }else{
                    if(mysqli_num_rows($check_vendor_details_with_email) == 1){
                        //Vendor With Same Email Exists
                        $json_response_array = array("desc" => "Vendor With Same Email Exists");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($check_vendor_details_with_email) > 1){
                            //Duplicated Vendors Email Details, Contact Admin
                            $json_response_array = array("desc" => "Duplicated Vendors Email Details, Contact Admin");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                }
            }else{
                //Invalid Email
                $json_response_array = array("desc" => "Invalid Email");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            if(empty($first)){
                //Firstname Field Empty
                $json_response_array = array("desc" => "Firstname Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($last)){
                    //Lastname Field Empty
                    $json_response_array = array("desc" => "Lastname Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($address)){
                        //Home Address Field Empty
                        $json_response_array = array("desc" => "Home Address Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(empty($email)){
                            //Email Field Empty
                            $json_response_array = array("desc" => "Email Field Empty");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(empty($pass)){
                                //Password Field Empty
                                $json_response_array = array("desc" => "Password Field Empty");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(empty($phone)){
                                    //Phone Number Field Empty
                                    $json_response_array = array("desc" => "Phone Number Field Empty");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }else{
                                    if(empty($website_url)){
                                        //Website Url Field Empty
                                        $json_response_array = array("desc" => "Website Url Field Empty");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
    
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
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>   
    <div class="pagetitle">
      <h1>ORDER WEBSITE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">WebsiteOrderRequest</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
        <div class="card info-card px-5 py-5">
          <div class="row mb-3">
            <form method="post" enctype="multipart/form-data" action="">
              <div style="text-align: center;" class="container">
                  <img src="<?php echo $web_http_host; ?>/asset/user-icon.png" class="col-2" style="pointer-events: none; user-select: auto; filter: invert(1);"/>
              </div><br/>
              <?php
                $host_name = gethostname();
                $ip_address = gethostbyname($host_name);
              ?>
              
              <?php if(isset($_SESSION["product_purchase_response"]) && !empty($_SESSION["product_purchase_response"])): ?>
                  <div class="alert alert-warning alert-dismissible fade show text-center fw-bold rounded-3 mb-4 shadow-sm" role="alert">
                      <i class="bi bi-exclamation-triangle-fill me-2 text-warning"></i>
                      <?php 
                      echo htmlspecialchars($_SESSION["product_purchase_response"]); 
                      unset($_SESSION["product_purchase_response"]);
                      ?>
                      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                  </div>
              <?php endif; ?>

              <div style="text-align: center;" class="container">
                  <span id="user-status-span" class="h5 fw-bold text-dark" style="user-select: auto;">PERSONAL INFORMATION</span>
              </div><br/>
              <input style="text-align: center;" name="first" type="text" value="" placeholder="Firstname" pattern="[a-zA-Z ]{3,}" title="Firstname must be atleast 3 letters long" class="form-control mb-1" required/><br/>
              <input style="text-align: center;" name="last" type="text" value="" placeholder="Lastname" pattern="[a-zA-Z ]{3,}" title="Lastname must be atleast 3 letters long" class="form-control mb-1" required/><br/>
              <input style="text-align: center;" name="address" type="text" value="" placeholder="Home Address" class="form-control mb-1" required/><br/>
  
              <div style="text-align: center;" class="container">
                  <span id="user-status-span" class="h5 fw-bold text-dark" style="user-select: auto;">CONTACT INFORMATION</span>
              </div><br/>
              <input style="text-align: center;" name="email" type="email" value="" placeholder="Email" class="form-control mb-1" required/><br/>
              <input style="text-align: center;" name="phone" type="text" value="" placeholder="Phone Number" pattern="[0-9]{11}" title="Phone number must be 11 digit long" class="form-control mb-1" required/><br/>
              
              <div style="text-align: center;" class="container">
                  <span id="user-status-span" class="h5 fw-bold text-dark" style="user-select: auto;">SET PASSWORD</span>
              </div><br/>
              <input style="text-align: center;" name="pass" type="password" value="" placeholder="Password" class="form-control mb-1" required/><br/>
              <input style="text-align: center;" name="confirm-pass" type="password" value="" placeholder="Confirm Password" class="form-control mb-1" required/><br/>
              
              <div style="text-align: center;" class="container">
                  <span id="user-status-span" class="h5 fw-bold text-dark" style="user-select: auto;">WEBSITE URL</span><br/>
                  <div id="user-status-span" class="container h6 fw-normal text-dark mt-2" style="user-select: auto;">Mask / Point your domain to our Server IP: <span class="fw-bold"><?php echo $ip_address; ?></span> </div>
              </div><br/>
              <div style="text-align: center;" class="col-12 d-md-flex d-lg-flex d-xl-flex flew-row flex-lg-row">
                <div style="text-align: center;" class="d-inline-block col-12 col-md-8 col-lg-8 col-xl-8">
                  <input style="text-align: center;" id="website-url-field" name="website-url" type="text" value="https://" placeholder="Website Url" class="form-control mb-1" required/>
                </div>
                <div style="text-align: center;" id="ext-select-container" class="d-inline-block col-12 col-md-4 col-lg-4 col-xl-4 d-none">
                  <select style="text-align: center;" id="domain-select-field" name="website-url-select" class="form-select mb-1">
                    <option value="">Choose Extension</option>
                    <?php
                    $ext_res = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY extension ASC");
                    while($ext = mysqli_fetch_assoc($ext_res)) {
                        echo "<option value='".htmlspecialchars($ext['extension'])."'>".htmlspecialchars(strtoupper($ext['extension']))."</option>";
                    }
                    ?>
                  </select>
                </div>
              </div><br/>
              <div style="text-align: right;" class="container">
                <label class="">Have an Existing Domain?</label>
                <input style="text-align: center;" id="existing-domain-chk" name="existing-domain" type="checkbox" placeholder="" class="form-check-input mb-1" checked/>
              </div><br/>
             
              <button name="place-order" id="order-submit-btn" type="submit" style="user-select: auto;" class="btn btn-primary col-12 mb-1" >
                  PLACE ORDER
              </button><br>
          </form>
        </div>
      </div>
    </div>
  </section><br/>
</main>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const extChk = document.getElementById("existing-domain-chk");
    const selectContainer = document.getElementById("ext-select-container");
    const domainSelect = document.getElementById("domain-select-field");
    const urlField = document.getElementById("website-url-field");
    const orderForm = document.querySelector("form");

    // Fetch extensions dynamically from PHP
    const availableExtensions = <?php
        $ext_res = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY extension ASC");
        $exts = [];
        while($ext = mysqli_fetch_assoc($ext_res)) {
            $exts[] = strtolower(trim($ext['extension']));
        }
        if (empty($exts)) {
            $exts = ['.com', '.ng', '.com.ng', '.org', '.net'];
        }
        echo json_encode($exts);
    ?>;

    function handleCheckboxToggle() {
        if (extChk.checked) {
            selectContainer.classList.add("d-none");
            domainSelect.required = false;
        } else {
            selectContainer.classList.remove("d-none");
            domainSelect.required = true;
        }
    }

    extChk.addEventListener("change", handleCheckboxToggle);
    handleCheckboxToggle(); // Init state

    // Automatically append extension when select is chosen and box is not checked
    domainSelect.addEventListener("change", function() {
        if (!extChk.checked && domainSelect.value) {
            let currentVal = urlField.value.trim();
            // Remove common scheme
            let cleanedVal = currentVal.replace(/^(https?:\/\/)?(www\.)?/, '');
            // Strip any existing dots/extensions if selected
            let rawName = cleanedVal.split('.')[0];
            if (rawName === '') {
                rawName = 'mybrand';
            }
            urlField.value = "https://" + rawName + domainSelect.value;
        }
    });

    orderForm.addEventListener("submit", function(e) {
        let urlVal = urlField.value.trim().toLowerCase();
        // Remove scheme and www
        let domainOnly = urlVal.replace(/^(https?:\/\/)?(www\.)?/, '');
        
        if (domainOnly === '') {
            e.preventDefault();
            alert('Please enter your website URL.');
            return;
        }

        // Validate password match
        const pass = document.getElementsByName("pass")[0].value;
        const confirmPass = document.getElementsByName("confirm-pass")[0].value;
        if (pass !== confirmPass) {
            e.preventDefault();
            alert('Passwords do not match!');
            return;
        }

        let hasExt = false;
        for (let ext of availableExtensions) {
            if (domainOnly.endsWith(ext)) {
                hasExt = true;
                break;
            }
        }

        if (!hasExt) {
            e.preventDefault();
            const friendlyExample = domainOnly.split('.')[0] || "mybrand";
            alert("Please include the domain extension in your website URL (e.g., " + friendlyExample + availableExtensions[0] + ").\n\nAvailable extensions:\n" + availableExtensions.join(", "));
        }
    });
});
</script>
</body>
</html>