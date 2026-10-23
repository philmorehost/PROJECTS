<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["set-answer"])){
        $quest = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quest"])));
        $answer = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["answer"]))));
        if(!empty($quest) && is_numeric($quest) && !empty($answer)){
            if((strlen($answer) >= 3) && (strlen($answer) <= 20)){
                if(empty(trim($get_logged_user_details["security_quest"])) || empty(trim($get_logged_user_details["security_answer"])) || (strlen($get_logged_user_details["security_answer"]) < 3) || (strlen($get_logged_user_details["security_answer"]) > 20)){
                    alterUser($get_logged_user_details["username"], "security_quest", $quest);
                    alterUser($get_logged_user_details["username"], "security_answer", $answer);
                    //Security Details Sets Successfully, Proceed To Answer Security Questions
                    $json_response_array = array("desc" => "Security Details Sets Successfully, Proceed To Answer Security Questions");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    //Security Details Cannot Be Changed, Click On Recovery Button To Alter Details
                    $json_response_array = array("desc" => "Security Details Cannot Be Changed, Click On Recovery Button To Alter Details");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                //Security Answer Must Be Between 3-20 Charaters Without Special Charaters
                $json_response_array = array("desc" => "Security Answer Must Be Between 3-20 Charaters Without Special Charaters");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Security Answer Cannot Be Empty
            $json_response_array = array("desc" => "Security Answer Cannot Be Empty");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

    if(isset($_POST["submit-answer"])){
        $answer = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["answer"]))));
        if(!empty($answer)){
            if((strlen($answer) >= 3) && (strlen($answer) <= 20)){
                if($answer == $get_logged_user_details["security_answer"]){
                    setcookie("security_answer", $answer, time() + (6 * 60 * 60));
                    $_SESSION["product_purchase_response"] = "Hurray!!! Security Verification Successful";
                    // Redirect to PIN setup for users who haven't set a PIN yet
                    if (empty($get_logged_user_details["security_pin"]) && empty($get_logged_user_details["transaction_pin"])) {
                        header("Location: SecurityPIN.php");
                    } else {
                        header("Location: Dashboard.php");
                    }
                    exit();
                }else{
                    //Invalid Security Answer, Try Again
                    $json_response_array = array("desc" => "Invalid Security Answer, Try Again");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                //Security Answer Must Be Between 3-20 Charaters Without Special Charaters
                $json_response_array = array("desc" => "Security Answer Must Be Between 3-20 Charaters Without Special Charaters");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Security Answer Cannot Be Empty
            $json_response_array = array("desc" => "Security Answer Cannot Be Empty");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

    if(isset($_POST["reset-answer"])){
        $quest = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quest"])));
        $answer = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["answer"]))));
        $pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
        if(!empty($quest) && is_numeric($quest) && !empty($answer)){
            if((strlen($answer) >= 3) && (strlen($answer) <= 20)){
                $md5_pass = md5($pass);
    			$check_user_password_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$get_logged_user_details["username"]."' && password='$md5_pass'");
    			if(mysqli_num_rows($check_user_password_details) == 1){
    			    alterUser($get_logged_user_details["username"], "security_quest", $quest);
                    alterUser($get_logged_user_details["username"], "security_answer", $answer);
                    unset($_SESSION["reset-security-detail"]);
                    //Security Details Resets Successfully
                    $json_response_array = array("desc" => "Security Details Resets Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    //Incorrect Password
                    $json_response_array = array("desc" => "Incorrect Password");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                //Security Answer Must Be Between 3-20 Charaters Without Special Charaters
                $json_response_array = array("desc" => "Security Answer Must Be Between 3-20 Charaters Without Special Charaters");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Security Answer Cannot Be Empty
            $json_response_array = array("desc" => "Security Answer Cannot Be Empty");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

    if(isset($_POST["reset-detail"])){
        $_SESSION["reset-security-detail"] = "reset";
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

    if(isset($_POST["cancel-reset"])){
        unset($_SESSION["reset-security-detail"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
?>
<!DOCTYPE html>
<head>
    <title>Security Question | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link
    href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
    rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
	<?php include("../func/bc-header.php"); ?>	
	
  <div class="pagetitle">
      <h1>SECURITY QUESTION</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">SecurityQuest</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 p-4 rounded-4">
                <div class="text-center mb-4">
                    <div class="bg-primary bg-opacity-10 text-dark-primary d-inline-flex align-items-center justify-content-center rounded-circle mb-3" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock fs-2"></i>
                    </div>
                    <h4 class="fw-bold">Identity Verification</h4>
                    <p class="text-muted small">Please verify your identity to proceed to your dashboard.</p>
                </div>

                <?php if(!isset($_COOKIE["security_answer"]) || ($_COOKIE["security_answer"] !== $get_logged_user_details["security_answer"])){ ?>
                    <?php if(!isset($_SESSION["reset-security-detail"])){ ?>
                        <?php if(empty(trim($get_logged_user_details["security_quest"])) || empty(trim($get_logged_user_details["security_answer"])) || (strlen($get_logged_user_details["security_answer"]) < 3) || (strlen($get_logged_user_details["security_answer"]) > 20)){ ?>
                        <form method="post" action="">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">Set Security Question</label>
                                <select name="quest" class="form-select form-control-lg" required>
                                    <option value="" default hidden selected>Select a Question</option>
                                    <?php
                                        $get_security_quest_details = mysqli_query($connection_server, "SELECT * FROM sas_security_quests");
                                        while($security_details = mysqli_fetch_assoc($get_security_quest_details)){
                                            echo '<option value="'.$security_details["id"].'">'.$security_details["quest"].'</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-uppercase text-muted">Set Your Secret Answer</label>
                                <input name="answer" type="text" class="form-control form-control-lg" placeholder="e.g. Bulldog" pattern="[0-9a-zA-Z ]{3,20}" required/>
                            </div>
                            <button name="set-answer" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3">
                                SAVE SECURITY DETAILS
                            </button>
                        </form>
                        <?php } ?>

                        <?php if(!empty(trim($get_logged_user_details["security_quest"])) && !empty(trim($get_logged_user_details["security_answer"])) && (strlen($get_logged_user_details["security_answer"]) >= 3) && (strlen($get_logged_user_details["security_answer"]) <= 20)){
                            $get_security_quest = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_security_quests WHERE id='".$get_logged_user_details["security_quest"]."'"))
                        ?>
                        <div class="alert alert-light border-0 py-3 mb-4 text-center">
                            <h6 class="fw-bold text-primary mb-1">Question:</h6>
                            <div class="fs-5 text-dark"><?php echo $get_security_quest["quest"]; ?></div>
                        </div>

                        <form method="post" action="">
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-uppercase text-muted text-center d-block">Enter Your Answer</label>
                                <input name="answer" type="text" class="form-control form-control-lg text-center" placeholder="Answer here" pattern="[0-9a-zA-Z ]{3,20}" required/>
                            </div>
                            <button name="submit-answer" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3 mb-3 text-uppercase">
                                Verify & Proceed
                            </button>
                        </form>
                        <form method="post" action="">
                            <button name="reset-detail" type="submit" class="btn btn-outline-warning w-100 border-0 btn-sm text-decoration-none">
                                Reset Security Detail?
                            </button>
                        </form>
                        <?php } ?>
                    <?php }else{ ?>
                        <h5 class="fw-bold mb-4 text-center">Reset Security Details</h5>
                        <form method="post" action="">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">New Security Question</label>
                                <select name="quest" class="form-select" required>
                                    <option value="" default hidden selected>Choose Question</option>
                                    <?php
                                        $get_security_quest_details = mysqli_query($connection_server, "SELECT * FROM sas_security_quests");
                                        while($security_details = mysqli_fetch_assoc($get_security_quest_details)){
                                            echo '<option value="'.$security_details["id"].'">'.$security_details["quest"].'</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">New Secret Answer</label>
                                <input name="answer" type="text" class="form-control" placeholder="New Answer" pattern="[0-9a-zA-Z ]{3,20}" required/>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-uppercase text-muted">Account Password</label>
                                <input name="pass" type="password" class="form-control" placeholder="Enter password to confirm" required/>
                            </div>
                            <button name="reset-answer" type="submit" class="btn btn-warning btn-lg w-100 shadow-sm py-3 fw-bold rounded-3 mb-3">
                                CONFIRM RESET
                            </button>
                        </form>
                        <form method="post" action="">
                            <button name="cancel-reset" type="submit" class="btn btn-link w-100 text-danger text-decoration-none small">
                                Cancel
                            </button>
                        </form>
                    <?php } ?>
                <?php }else{ ?>
                    <div class="alert alert-info text-center">
                        <i class="bi bi-info-circle me-2"></i> Security question verified.
                    </div>
                <?php } ?>
            </div>
        </div>
      </div>
    </section>
	<?php include("../func/bc-footer.php"); ?>
	
</body>
</html>