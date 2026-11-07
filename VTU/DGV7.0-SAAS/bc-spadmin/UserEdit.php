<?php session_start();
    include("../func/bc-spadmin-config.php");

    $user_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["userID"]))));
    $select_user = mysqli_query($connection_server, "SELECT u.*, v.website_url FROM sas_users u LEFT JOIN sas_vendors v ON u.vendor_id = v.id WHERE u.id='$user_id_number'");
    if($select_user && mysqli_num_rows($select_user) > 0){
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

        if(!empty($first) && !empty($last) && !empty($quest) && is_numeric($quest) && !empty($answer) && !empty($address) && !empty($email) && !empty($phone)){
            if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                mysqli_query($connection_server, "UPDATE sas_users SET security_quest='$quest', security_answer='$answer', firstname='$first', lastname='$last', othername='$other', home_address='$address', email='$email', phone_number='$phone' WHERE id='".$user_id_number."'");
                $_SESSION["product_purchase_response"] = "Profile Information Updated Successfully";
            }else{
                $_SESSION["product_purchase_response"] = "Invalid Email";
            }
        }else{
            $_SESSION["product_purchase_response"] = "Please fill all required fields";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["change-password"])){
        $new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["new-pass"])));
        $con_new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["con-new-pass"])));

        if(!empty($new_pass) && !empty($con_new_pass)){
            if($new_pass == $con_new_pass){
                $md5_new_pass = md5($new_pass);
                mysqli_query($connection_server, "UPDATE sas_users SET password='$md5_new_pass' WHERE id='".$user_id_number."'");
                $_SESSION["product_purchase_response"] = "Account Password Updated Successfully";
            }else{
                $_SESSION["product_purchase_response"] = "Passwords do not match";
            }
        }else{
            $_SESSION["product_purchase_response"] = "Please fill all password fields";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }
?>
<!DOCTYPE html>
<head>
    <title>Edit User | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>
    <div class="pagetitle">
      <h1>EDIT USER (@<?php echo $get_user_details['username'] ?? 'Unknown'; ?>)</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item"><a href="Users.php">Users</a></li>
          <li class="breadcrumb-item active">Edit</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <div class="row">
        <div class="col-lg-7">
            <?php if(!empty($get_user_details['id'])){ ?>
                <div class="card info-card p-4 mb-4 shadow-sm border-0 rounded-4">
                    <div class="d-flex align-items-center mb-4">
                        <div class="bg-primary bg-opacity-10 p-3 rounded-4 me-3 shadow-sm">
                            <i class="bi bi-person-bounding-box text-dark-primary fs-3"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0">Personal Information</h5>
                            <p class="small text-muted mb-0">Vendor: <b><?php echo $get_user_details['website_url'] ?? ''; ?></b></p>
                        </div>
                    </div>

                    <form method="post">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Firstname</label>
                                <input name="first" type="text" value="<?php echo $get_user_details['firstname']; ?>" class="form-control" required/>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Lastname</label>
                                <input name="last" type="text" value="<?php echo $get_user_details['lastname']; ?>" class="form-control" required/>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Othername</label>
                                <input name="other" type="text" value="<?php echo $get_user_details['othername']; ?>" class="form-control" />
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Home Address</label>
                                <input name="address" type="text" value="<?php echo $get_user_details['home_address']; ?>" class="form-control" required/>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Email</label>
                                <input name="email" type="email" value="<?php echo $get_user_details['email']; ?>" class="form-control" required/>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Phone Number</label>
                                <input name="phone" type="text" value="<?php echo $get_user_details['phone_number']; ?>" class="form-control" required/>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Security Question</label>
                                <select name="quest" class="form-select" required>
                                    <?php
                                        $qs = mysqli_query($connection_server, "SELECT * FROM sas_security_quests");
                                        while($q = mysqli_fetch_assoc($qs)){
                                            $sel = ($q["id"] == $get_user_details['security_quest']) ? 'selected' : '';
                                            echo '<option value="'.$q["id"].'" '.$sel.'>'.$q["quest"].'</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Security Answer</label>
                                <input name="answer" type="text" value="<?php echo $get_user_details['security_answer']; ?>" class="form-control" required/>
                            </div>

                            <div class="col-12 mt-4">
                                <button name="update-profile" type="submit" class="btn btn-primary w-100 py-2 fw-bold rounded-3">UPDATE PROFILE</button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php } ?>
        </div>

        <div class="col-lg-5">
            <div class="card info-card p-4 shadow-sm border-0 rounded-4">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-danger bg-opacity-10 p-3 rounded-4 me-3 shadow-sm">
                        <i class="bi bi-shield-lock fs-3 text-danger"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0">Reset Password</h5>
                        <p class="small text-muted mb-0">Force a new password for this user.</p>
                    </div>
                </div>

                <form method="post">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">New Password</label>
                        <input name="new-pass" type="password" class="form-control" required/>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold">Confirm New Password</label>
                        <input name="con-new-pass" type="password" class="form-control" required/>
                    </div>
                    <button name="change-password" type="submit" class="btn btn-danger w-100 py-2 fw-bold rounded-3">CHANGE PASSWORD</button>
                </form>

                <hr class="my-4">

                <div class="d-grid gap-2">
                    <a href="UserUpgrade.php?userID=<?php echo $user_id_number; ?>" class="btn btn-outline-primary fw-bold"><i class="bi bi-arrow-up-circle me-2"></i>Upgrade User Level</a>
                    <a href="Users.php" class="btn btn-light"><i class="bi bi-arrow-left me-2"></i>Back to List</a>
                </div>
            </div>
        </div>
      </div>
    </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>