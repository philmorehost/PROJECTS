<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    $vendor_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["vendorID"]))));
    $select_vendor = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id_number'");
    if(mysqli_num_rows($select_vendor) > 0){
        $get_vendor_details = mysqli_fetch_array($select_vendor);
    }

    if(isset($_POST["update-profile"])){
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
        $last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $phone = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["phone"]))));
        $unrefined_website_url = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["website-url"]))));
        $refined_website_url = trim(str_replace(["https","http",":/","/","www."," "],"",$unrefined_website_url));
        $website_url = $refined_website_url;
        if (bc_is_temporary_vendor_host($website_url)) {
            $_SESSION["product_purchase_response"] = "This domain is reserved for temporary vendor access. Please choose a different website domain.";
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
        if(!empty($first) && !empty($last) && !empty($address) && !empty($email) && !empty($phone) && !empty($website_url)){
            $check_vendor_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".$vendor_id_number."'");
            if(mysqli_num_rows($check_vendor_details) == 1){
                $get_vendor_details = mysqli_fetch_array($check_vendor_details);
                $check_vendor_new_email = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='$email'");
                $check_vendor_new_website = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE website_url='$website_url'");
                $proceed_to_email_check = false;

                if((mysqli_num_rows($check_vendor_new_website) == 1) || (mysqli_num_rows($check_vendor_new_website) < 1)){
                    if(mysqli_num_rows($check_vendor_new_website) == 1){
                        $get_new_vendor_details = mysqli_fetch_array($check_vendor_new_website);
                        if($get_new_vendor_details["id"] == $get_vendor_details["id"]){
                            mysqli_query($connection_server, "UPDATE sas_vendors SET firstname='$first', lastname='$last', home_address='$address', email='$email', phone_number='$phone' WHERE id='".$vendor_id_number."'");
                            $proceed_to_email_check = true;
                        }else{
                            //Website Address Taken By Another Vendor
                            $json_response_array = array("desc" => "Website Address Taken By Another Vendor");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }else{
                        if(mysqli_num_rows($check_vendor_new_website) < 1){
                            $proceed_to_email_check = true;
                        }
                    }
                }else{
                    if(mysqli_num_rows($check_vendor_new_website) > 1){
                        //Duplicated Vendor Website Address, Contact Developer
                        $json_response_array = array("desc" => "Duplicated Vendor Website Address, Contact Developer");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
                if($proceed_to_email_check == true){
                    $email_check_verified = false;
                    if((mysqli_num_rows($check_vendor_new_email) == 1) || (mysqli_num_rows($check_vendor_new_email) < 1)){
                        if(mysqli_num_rows($check_vendor_new_email) == 1){
                            $get_new_vendor_details = mysqli_fetch_array($check_vendor_new_email);
                            if($get_new_vendor_details["id"] == $get_vendor_details["id"]){
                                $email_check_verified = true;
                            }else{
                                //Email Taken By Another Vendor
                                $json_response_array = array("desc" => "Email Taken By Another Vendor");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }else{
                            if(mysqli_num_rows($check_vendor_new_email) < 1){
                                $email_check_verified = true;
                            }
                        }
                    }else{
                        if(mysqli_num_rows($check_vendor_new_email) > 1){
                            //Duplicated Vendor Email, Contact Developer
                            $json_response_array = array("desc" => "Duplicated Vendor Email, Contact Developer");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                }

                if($email_check_verified == true){
                    mysqli_query($connection_server, "UPDATE sas_vendors SET firstname='$first', lastname='$last', home_address='$address', email='$email', phone_number='$phone', website_url='$website_url' WHERE id='".$vendor_id_number."'");
                    //Profile Information Updated Successfully
                    $json_response_array = array("desc" => "Profile Information Updated Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                if(mysqli_num_rows($check_vendor_details) == 0){
                    //Vendor Not Exists
                    $json_response_array = array("desc" => "Vendor Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_vendor_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
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

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
    
    if(isset($_POST["change-password"])){
        $new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["new-pass"])));
        $con_new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["con-new-pass"])));
        
        if(!empty($new_pass) && !empty($con_new_pass)){
            $check_vendor_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".$vendor_id_number."'");
            if(mysqli_num_rows($check_vendor_details) == 1){
                $new_vendor_pass_hash = mysqli_real_escape_string($connection_server, bc_hash_password($new_pass));
                // bc_verify_password() accepts both bcrypt and legacy raw-MD5 hashes; only bcrypt is written.
                $new_pass_matches_confirm = ($new_pass === $con_new_pass);
                $new_pass_matches_spadmin_pass = bc_verify_password($new_pass, $get_logged_spadmin_details["password"]);
                
                if(!$new_pass_matches_spadmin_pass){
                    if($new_pass_matches_confirm){
                        mysqli_query($connection_server, "UPDATE sas_vendors SET password='$new_vendor_pass_hash' WHERE id='".$vendor_id_number."'");
                        //Account Password Updated Successfully
                        $json_response_array = array("desc" => "Account Password Updated Successfully");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        //New & Confirm Password Not Match
                        $json_response_array = array("desc" => "New & Confirm Password Not Match");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }else{
                    //New & Old Password Must Be Different
                    $json_response_array = array("desc" => "New & Old Password Must Be Different");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                if(mysqli_num_rows($check_vendor_details) == 0){
                    //Vendor Not Exists
                    $json_response_array = array("desc" => "Vendor Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_vendor_details) > 1){
                    //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($new_pass)){
                //New Password Field Empty
                $json_response_array = array("desc" => "New Password Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($con_new_pass)){
                    //Confirm New Password Field Empty
                    $json_response_array = array("desc" => "Confirm New Password Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
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
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>    
    <div class="pagetitle">
      <h1>VENDOR API</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Vendor API</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-8">

    <?php if(!empty($get_vendor_details['id'])){ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0 text-center">
                <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                    <i class="bi bi-person-gear text-primary fs-1"></i>
                </div>
                <h4 class="fw-bold mb-0">Vendor Profile Editor</h4>
                <p class="text-muted small">Update vendor personal and business details</p>
            </div>
            <div class="card-body p-4 p-md-5">
                <form method="post">
                    <div class="row g-4 mb-4">
                        <div class="col-12 text-center">
                            <span class="badge bg-light text-primary px-3 py-2 rounded-pill fw-bold text-uppercase" style="letter-spacing: 1px;">Personal Information</span>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">First Name</label>
                            <input name="first" type="text" value="<?php echo $get_vendor_details['firstname']; ?>" class="form-control rounded-3 py-2" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Last Name</label>
                            <input name="last" type="text" value="<?php echo $get_vendor_details['lastname']; ?>" class="form-control rounded-3 py-2" required />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted text-uppercase">Residential Address</label>
                            <input name="address" type="text" value="<?php echo $get_vendor_details['home_address']; ?>" class="form-control rounded-3 py-2" required />
                        </div>

                        <div class="col-12 text-center mt-5">
                            <span class="badge bg-light text-primary px-3 py-2 rounded-pill fw-bold text-uppercase" style="letter-spacing: 1px;">Contact & Business</span>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Email Address</label>
                            <input name="email" type="email" value="<?php echo $get_vendor_details['email']; ?>" class="form-control rounded-3 py-2" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Phone Number</label>
                            <input name="phone" type="text" value="<?php echo $get_vendor_details['phone_number']; ?>" class="form-control rounded-3 py-2" required />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted text-uppercase">Business Website URL</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 rounded-start-3">https://</span>
                                <input name="website-url" type="text" value="<?php echo $get_vendor_details['website_url']; ?>" class="form-control rounded-end-3 py-2" required />
                            </div>
                        </div>
                    </div>

                    <div class="mt-5">
                        <button name="update-profile" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                            <i class="bi bi-save2 me-2"></i>Update Vendor Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="alert alert-warning border-0 rounded-4 p-4 d-flex align-items-center shadow-sm">
            <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
            <div>
                <h6 class="fw-bold mb-1">Administrative Support</h6>
                <p class="small mb-0">For advanced API configurations or direct server access, please contact the developer team immediately.</p>
            </div>
        </div>
    <?php }else{ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden py-5">
            <div class="card-body text-center py-5">
                <div class="mb-4">
                    <img src="<?php echo $web_http_host; ?>/asset/ooops.gif" class="img-fluid" style="max-height: 200px;"/>
                </div>
                <h2 class="fw-bold text-primary mb-2">Ooops!</h2>
                <h5 class="text-muted mb-4">Vendor Account Not Found</h5>
                <a href="Vendors.php" class="btn btn-primary px-5 rounded-pill fw-bold">Back to Vendors</a>
            </div>
        </div>
    <?php } ?>
        </div>
      </div>
    </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>