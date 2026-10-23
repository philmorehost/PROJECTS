<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    $billing_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["billingID"]))));
    $select_billing_with_id = mysqli_query($connection_server, "SELECT * FROM sas_vendor_billings WHERE id='$billing_id_number'");
    if(mysqli_num_rows($select_billing_with_id) > 0){
        $get_billing_details = mysqli_fetch_array($select_billing_with_id);
    }

    if(isset($_POST["update-billing"])){
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["type"])));
        $starting_date = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["starting-date"])));
        $ending_date = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ending-date"])));
        $desc = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["desc"])));
        $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags(strtolower($_POST["amount"])))));
        if(!empty($type) && !empty($starting_date) && strtotime($starting_date) && !empty($ending_date) && strtotime($ending_date) && !empty($amount) && is_numeric($amount) && (strtotime($starting_date) <= strtotime($ending_date))){
            $check_billing_details = mysqli_query($connection_server, "SELECT * FROM sas_vendor_billings WHERE id='$billing_id_number'");
            $cross_check_billing_details = mysqli_query($connection_server, "SELECT * FROM sas_vendor_billings WHERE starting_date='$starting_date' && ending_date='$ending_date'");
            $update_billing_details = false;
            if(mysqli_num_rows($check_billing_details) == 1){
                if(mysqli_num_rows($cross_check_billing_details) == 0){
                    $update_billing_details = true;
                }else{
                    if(mysqli_num_rows($cross_check_billing_details) == 1){
                        $get_billing_info = mysqli_fetch_array($cross_check_billing_details);
                        if($get_billing_info["id"] == $billing_id_number){
                            $update_billing_details = true;
                        }else{
                            //Billing With Same Starting And Ending Date Already Exists
                            $json_response_array = array("desc" => "Billing With Same Starting And Ending Date Already Exists");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }else{
                        if(mysqli_num_rows($cross_check_billing_details) > 1){
                            //Duplicated Date Details, Contact Admin
                            $json_response_array = array("desc" => "Duplicated Date Details, Contact Admin");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                }
                if($update_billing_details == true){
                    mysqli_query($connection_server, "UPDATE sas_vendor_billings SET bill_type='$type', starting_date='$starting_date', ending_date='$ending_date', description='$desc', amount='$amount' WHERE id='".$billing_id_number."'");
                    //Billing Information Updated Successfully
                    $json_response_array = array("desc" => "Billing Information Updated Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }
                
            }else{
                if(mysqli_num_rows($check_billing_details) == 0){
                    //Billing Information Not Exists
                    $json_response_array = array("desc" => "Billing Information Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_billing_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($type)){
                //API Type Field Empty
                $json_response_array = array("desc" => "API Type Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($starting_date)){
                    //Starting Date Field Empty
                    $json_response_array = array("desc" => "Starting Date Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(!strtotime($starting_date)){
                        //Invalid Starting Date String
                        $json_response_array = array("desc" => "Invalid Starting Date String");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(empty($ending_date)){
                            //Ending Date Field Empty
                            $json_response_array = array("desc" => "Ending Date Field Empty");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(!strtotime($ending_date)){
                                //Invalid Ending Date String
                                $json_response_array = array("desc" => "Invalid Ending Date String");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(empty($amount)){
                                    //Amount Field Empty
                                    $json_response_array = array("desc" => "Amount Field Empty");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }else{
                                    if(!is_numeric($amount)){
                                        //Non-numeric Amount
                                        $json_response_array = array("desc" => "Non-numeric Amount");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }else{
                                        if(strtotime($starting_date) > strtotime($ending_date)){
                                            //Ending Date Must Be Greater Than Or Equals Starting Date
                                            $json_response_array = array("desc" => "Ending Date Must Be Greater Than Or Equals Starting Date");
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
      <h1>EDIT BILLING</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Edit Billing</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
    
    <?php if(!empty($get_billing_details['id'])){ ?>
        <div class="card info-card px-5 py-5">
            <form method="post" enctype="multipart/form-data" action="">
                <div style="text-align: center;" class="container">
                    <img src="<?php echo $web_http_host; ?>/asset/billing-icon.png" class="col-2" style="pointer-events: none; user-select: auto; filter: invert(1);"/>
                </div><br/>
                <div style="text-align: center;" class="container">
                    <span id="api-status-span" class="h5" style="user-select: auto;">BILLING INFORMATION</span>
                </div><br/>
                <input style="text-align: center;" name="type" type="text" value="<?php echo $get_billing_details['bill_type']; ?>" placeholder="Billing Type e.g Maintenance Fee" class="form-control mb-1" required/><br/>
                
                <div style="text-align: center;" class="container">
                    <span id="api-status-span" class="h5" style="user-select: auto;">BILLING AMOUNT</span>
                </div><br/>
                <input style="text-align: center;" name="amount" type="number" value="<?php echo $get_billing_details['amount']; ?>" placeholder="Amount" step="0.0001" min="1" class="form-control mb-1" required/><br/>
                <div style="text-align: center;" class="container">
                    <span id="api-status-span" class="h5" style="user-select: auto;">STARTING DATE</span>
                </div><br/>
                <input style="text-align: center;" name="starting-date" type="date" value="<?php echo $get_billing_details['starting_date']; ?>" placeholder="" class="form-control mb-1" required/><br/>
                
                <div style="text-align: center;" class="container">
                    <span id="api-status-span" class="h5" style="user-select: auto;">ENDING DATE</span>
                </div><br/>
                <input style="text-align: center;" name="ending-date" type="date" value="<?php echo $get_billing_details['ending_date']; ?>" placeholder="" class="form-control mb-1" required/><br/>
                
                <div style="text-align: center;" class="container">
                    <span id="api-status-span" class="h5" style="user-select: auto;">DESCRIPTION</span>
                </div><br/>
                <textarea style="text-align: center; resize: none;" id="" name="desc" onkeyup="" placeholder="Description (Optional)" class="form-control mb-1" rows="10" ><?php echo $get_billing_details['description']; ?></textarea><br/>
                
                <button name="update-billing" type="submit" style="user-select: auto;" class="btn btn-primary col-12" >
                    UPDATE BILLING
                </button><br>
            </form>
        </div>
    <?php }else{ ?>
        <div class="container d-flex flex-column align-items-center justify-items-center justify-content-center">
            <img src="<?php echo $web_http_host; ?>/asset/ooops.gif" class="col-4" style="user-select: auto;"/><br/>
            <div style="text-align: center;" class="container">
                <span id="api-status-span" class="h3" style="user-select: auto;">Ooops</span><br/>
                <span id="api-status-span" class="h5" style="user-select: auto;">Billing Not Exists or has been deleted</span>
            </div><br/>
        </div>
    <?php } ?>
    </div>
  </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>