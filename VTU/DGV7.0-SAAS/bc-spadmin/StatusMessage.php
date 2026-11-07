<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    if(isset($_POST["update-status"])){
        $message = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["message"])));
        if(!empty($message)){
            $select_vendor_status_message = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_status_messages");
            if(mysqli_num_rows($select_vendor_status_message) == 1){
            	mysqli_query($connection_server, "UPDATE sas_super_admin_status_messages SET message='$message'");
            	//Hurray! Status Message Updated Successfully
            	$json_response_array = array("desc" => "Hurray! Status Message Updated Successfully");
            	$json_response_encode = json_encode($json_response_array,true);
            }else{
            	if(mysqli_num_rows($select_vendor_status_message) > 1){
            		mysqli_query($connection_server, "DELETE FROM sas_super_admin_status_messages");
            		mysqli_query($connection_server, "INSERT INTO sas_super_admin_status_messages (message) VALUES ('$message')");
            		//Hurray! Status Message Recreated Successfully
            		$json_response_array = array("desc" => "Hurray! Status Message Recreated Successfully");
            		$json_response_encode = json_encode($json_response_array,true);
            	}else{
            		mysqli_query($connection_server, "INSERT INTO sas_super_admin_status_messages (message) VALUES ('$message')");
            		//Hurray! Status Message Created Successfully
            		$json_response_array = array("desc" => "Hurray! Status Message Created Successfully");
            		$json_response_encode = json_encode($json_response_array,true);
            	}
            }
		}else{
			if(empty($message)){
                //Message Field Empty
				$json_response_array = array("desc" => "Message Field Empty");
				$json_response_encode = json_encode($json_response_array,true);
            }
		}
        
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
    
    $select_vendor_super_admin_status_message = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_status_messages");
    if(mysqli_num_rows($select_vendor_super_admin_status_message) == 1){
    	$get_vendor_super_admin_status_message = mysqli_fetch_array($select_vendor_super_admin_status_message);
    	$get_vendor_super_admin_status_message_text = 	$get_vendor_super_admin_status_message["message"];
    }else{
    	$get_vendor_super_admin_status_message_text = "";
    }
?>
<!DOCTYPE html>
<head>
    <title>Status Message</title>
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
      <h1>STATUS MESSAGE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Status Message</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0 text-center">
                    <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 80px; height: 80px;">
                        <i class="bi bi-megaphone text-dark-primary fs-1"></i>
                    </div>
                    <h4 class="fw-bold mb-0">Global Notification Message</h4>
                    <p class="text-muted small">This message will be visible to all vendors upon login</p>
                </div>
                <div class="card-body p-4 p-md-5">
                    <form method="post">
                        <div class="alert alert-info border-0 rounded-4 d-flex align-items-center mb-4">
                            <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                            <div class="small">Use <code class="fw-bold">{firstname}</code> to personalize the message with each vendor's name.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase">Broadcast Content</label>
                            <textarea name="message" class="form-control rounded-4 p-3" rows="8" placeholder="Type your message to vendors here..." required><?php echo $get_vendor_super_admin_status_message_text; ?></textarea>
                        </div>

                        <button name="update-status" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                            <i class="bi bi-send-check me-2"></i>Publish Broadcast
                        </button>
                    </form>
                </div>
            </div>
        </div>
      </div>
    </section>

	<?php include("../func/bc-spadmin-footer.php"); ?>
	
</body>
</html>