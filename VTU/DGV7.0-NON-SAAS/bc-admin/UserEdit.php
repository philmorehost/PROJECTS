<?php session_start();
    include("../func/bc-admin-config.php");
    
    $user_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["userID"]))));
    $select_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$user_id_number'");
    if(mysqli_num_rows($select_user) > 0){
        $get_user_details = mysqli_fetch_array($select_user);
    }

    if(isset($_POST["update-profile"])){
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
    	$last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $other = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["other"]))));
    	$quest = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quest"])));
    	$answer = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["answer"]))));
    	$address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $phone = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["phone"]))));
        $api_domain = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api_domain"])));
    	//$bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bank-code"])));
        //$account_number = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-number"])));
        //$bvn = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["bvn"]))));
    	//$nin = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["nin"]))));
    	
        if(!empty($first) && !empty($last) && !empty($quest) && is_numeric($quest) && !empty($answer) && !empty($address) && !empty($email) && !empty($phone)){
            if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
                if(mysqli_num_rows($check_user_details) == 1){
                    if((strlen($answer) >= 3) && (strlen($answer) <= 20)){
                        if(is_numeric($phone) && (strlen($phone) == 11)){
        	                /*if(!empty($bank_code) && is_numeric($bank_code) && (strlen($bank_code) >= 1)){
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
                	        }*/
		            mysqli_query($connection_server, "UPDATE sas_users SET security_quest='$quest', security_answer='$answer', firstname='$first', lastname='$last', othername='$other', home_address='$address', email='$email', phone_number='$phone', api_domain='$api_domain' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
        	                //Profile Information Updated Successfully
    	                    $json_response_array = array("desc" => "Profile Information Updated Successfully");
	                        $json_response_encode = json_encode($json_response_array,true);
                    	}else{
                    		//Phone number should be 11 digit long
                    		$json_response_array = array("desc" => "Phone number should be 11 digit long");
                    		$json_response_encode = json_encode($json_response_array,true);
                    	}
                    }else{
                        //Security Answer Must Be Between 3-20 Charaters Without Special Charaters
                        $json_response_array = array("desc" => "Security Answer Must Be Between 3-20 Charaters Without Special Charaters");
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
                    if(empty($quest)){
                        //Security Question Field Empty
                        $json_response_array = array("desc" => "Security Question Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(!is_numeric($quest)){
                            //Security Question Cannot Be String
                            $json_response_array = array("desc" => "Security Question Cannot Be String");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(empty($answer)){
                                //Security Answer Field Empty
                                $json_response_array = array("desc" => "Security Answer Field Empty");
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
                                        }
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
        exit;
    }
    
    if(isset($_POST["change-password"])){
        $new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["new-pass"])));
        $con_new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["con-new-pass"])));
        
        if(!empty($new_pass) && !empty($con_new_pass)){
            $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
            if(mysqli_num_rows($check_user_details) == 1){
                $md5_new_pass = md5($new_pass);
                $md5_con_new_pass = md5($con_new_pass);
                
                if($md5_new_pass !== $get_logged_admin_details["password"]){
                    if($md5_new_pass == $md5_con_new_pass){
                        mysqli_query($connection_server, "UPDATE sas_users SET password='$md5_new_pass' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
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
        exit;
    }    
?>
<!DOCTYPE html>
<head>
    <title>Edit User | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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

</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>EDIT USER</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">User Edit</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
    <?php if(!empty($get_user_details['id'])){ ?>
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-4 border-0">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-person-bounding-box text-dark-primary fs-3"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0">Edit User Account</h5>
                            <p class="text-muted small mb-0">Managing <b>@<?php echo $get_user_details['username']; ?></b> (ID: <?php echo $get_user_details['id']; ?>)</p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary text-uppercase small border-bottom pb-2 mb-3">Personal Information</h6>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">First Name</label>
                                <input name="first" type="text" value="<?php echo $get_user_details['firstname']; ?>" class="form-control rounded-3" pattern="[a-zA-Z ]{3,}" required/>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Last Name</label>
                                <input name="last" type="text" value="<?php echo $get_user_details['lastname']; ?>" class="form-control rounded-3" pattern="[a-zA-Z ]{3,}" required/>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Other Name</label>
                                <input name="other" type="text" value="<?php echo $get_user_details['othername']; ?>" class="form-control rounded-3" />
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Residential Address</label>
                                <input name="address" type="text" value="<?php echo $get_user_details['home_address']; ?>" class="form-control rounded-3" required/>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary text-uppercase small border-bottom pb-2 mb-3">Contact & Technical</h6>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="bi bi-envelope"></i></span>
                                    <input name="email" type="email" value="<?php echo $get_user_details['email']; ?>" class="form-control rounded-end-3" required/>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="bi bi-phone"></i></span>
                                    <input name="phone" type="text" value="<?php echo $get_user_details['phone_number']; ?>" class="form-control rounded-end-3" pattern="[0-9]{11}" required/>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">API Whitelist Domain</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="bi bi-globe"></i></span>
                                    <input name="api_domain" type="text" value="<?php echo $get_user_details['api_domain']; ?>" class="form-control rounded-end-3" placeholder="e.g. mysite.com (no https://)"/>
                                </div>
                                <small class="text-muted">Used to authorize requests if this user uses your API.</small>
                            </div>
                        </div>

                        <div class="row g-3 mb-5">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary text-uppercase small border-bottom pb-2 mb-3">Security Configuration</h6>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-bold">Security Question</label>
                                <select name="quest" class="form-select rounded-3" required>
                                    <option value="">Choose Question...</option>
                                    <?php
                                        $get_security_quest_details = mysqli_query($connection_server, "SELECT * FROM sas_security_quests");
                                        while($security_details = mysqli_fetch_assoc($get_security_quest_details)){
                                            $selected = ($security_details["id"] == $get_user_details['security_quest']) ? 'selected' : '';
                                            echo '<option value="'.$security_details["id"].'" '.$selected.'>'.$security_details["quest"].'</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-bold">Security Answer</label>
                                <input name="answer" type="text" value="<?php echo $get_user_details['security_answer']; ?>" class="form-control rounded-3" pattern="[0-9a-zA-Z ]{3,20}" required/>
                            </div>
                        </div>

                        <button name="update-profile" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm">
                            <i class="bi bi-save2 me-2"></i>SAVE USER CHANGES
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-danger bg-opacity-10 py-3 border-0">
                    <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-shield-lock me-2"></i>Reset User Password</h6>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">New Password</label>
                            <input name="new-pass" type="password" class="form-control rounded-3" required/>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Confirm Password</label>
                            <input name="con-new-pass" type="password" class="form-control rounded-3" required/>
                        </div>
                        <button name="change-password" type="submit" class="btn btn-outline-danger w-100 rounded-pill fw-bold">
                            Update Password
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 p-4 text-center">
                <div class="mb-3">
                    <img src="<?php echo $web_http_host; ?>/asset/user-icon.png" class="img-fluid rounded-circle bg-light p-3" style="width: 100px; height: 100px; object-fit: contain; filter: grayscale(1); opacity: 0.5;"/>
                </div>
                <h6 class="fw-bold"><?php echo $get_user_details['firstname'] . ' ' . $get_user_details['lastname']; ?></h6>
                <div class="badge bg-light text-dark border rounded-pill px-3 mb-3"><?php echo strtoupper(accountLevel($get_user_details['account_level'])); ?></div>
                <div class="text-muted small mb-1"><i class="bi bi-clock me-1"></i> Joined: <?php echo date('M d, Y', strtotime($get_user_details['reg_date'])); ?></div>
                <div class="text-muted small"><i class="bi bi-wallet2 me-1"></i> Balance: <b>₦<?php echo number_format($get_user_details['balance'], 2); ?></b></div>
            </div>
        </div>

    <?php }else{ ?>
      <div class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="h4">USER INFO</span><br>
    		<img src="<?php echo $web_http_host; ?>/asset/ooops.gif" class="h5 m-width-60 s-width-50" style="user-select: auto;"/><br/>
            <div style="text-align: center;" class="container">
                <span id="user-status-span" class="h5" style="user-select: auto;">Ooops</span><br/>
                <span id="user-status-span" class="h6" style="user-select: auto;">User Account Not Exists</span>
            </div><br/>
        </div>
    <?php } ?>
    </div>
  </section>
    <?php include("../func/bc-admin-footer.php"); ?>
    
</body>
</html>
