<?php session_start();
    include("../func/bc-admin-config.php");
    
    // ── Admin Edit Sender ID ──────────────────────────────────────────────────
    if(isset($_POST["admin-edit-sender-id"])){
        $ae_sender_id     = mysqli_real_escape_string($connection_server, preg_replace("/[^a-zA-Z]+/","",trim(strip_tags(strtolower($_POST["ae-sender-id-value"])))));
        $ae_sample_msg    = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ae-sample-message"])));
        if(!empty($ae_sender_id) && !empty($ae_sample_msg) && strlen($ae_sample_msg) >= 10 && strlen($ae_sample_msg) <= 160){
            $ae_exists = mysqli_query($connection_server, "SELECT id FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_admin_details["id"]."' && sender_id='$ae_sender_id' LIMIT 1");
            if(mysqli_num_rows($ae_exists) == 1){
                mysqli_query($connection_server, "UPDATE sas_bulk_sms_sender_id SET sample_message='$ae_sample_msg', status='2' WHERE vendor_id='".$get_logged_admin_details["id"]."' && sender_id='$ae_sender_id'");
                $json_response_array = array("desc" => "Sender ID updated and reset to Pending for review.");
            } else {
                $json_response_array = array("desc" => "Sender ID not found.");
            }
        } else {
            $json_response_array = array("desc" => "Invalid data. Sample message must be 10–160 characters.");
        }
        $json_response_decode = json_decode(json_encode($json_response_array),true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: /bc-admin/SenderIDRequests.php");
        exit;
    }
    
    if(isset($_GET["sender-id"])){
    	$status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["sender-id-status"])));
    	$sender_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["sender-id"])));
    	$statusArray = array(1, 2, 3);
    	if(is_numeric($status)){
    		if(in_array($status, $statusArray)){
    			$select_sender_id = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_admin_details["id"]."' && sender_id='".$sender_id."'");
    			if(mysqli_num_rows($select_sender_id) == 1){
    				$get_sender_id = mysqli_fetch_array($select_sender_id);
    				if($status == 1){
    					$update_sender_id_status = mysqli_query($connection_server, "UPDATE sas_bulk_sms_sender_id SET status='3' WHERE vendor_id='".$get_logged_admin_details["id"]."' && sender_id='".$sender_id."'");
    					$json_response_array = array("desc" => ucwords($get_sender_id["username"]."(".$sender_id.") Sender ID rejected successfully"));
    					$json_response_encode = json_encode($json_response_array,true);
    				}
    				
    				if($status == 2){
						$update_sender_id_status = mysqli_query($connection_server, "UPDATE sas_bulk_sms_sender_id SET status='1' WHERE vendor_id='".$get_logged_admin_details["id"]."' && sender_id='".$sender_id."'");
						
                        // ── Auto-submit to PhilmoreSMS when approving ───────────────────────
                        $pms_api_q = mysqli_query($connection_server, "SELECT api_key FROM sas_api_gateway WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_provider_name LIKE '%philmoresms.com%' LIMIT 1");
                        if(mysqli_num_rows($pms_api_q) > 0){
                            $pms_api_row = mysqli_fetch_assoc($pms_api_q);
                            $pms_token   = trim($pms_api_row['api_key']);
                            if(!empty($pms_token)){
                                $pms_post = http_build_query(array(
                                    'token'    => $pms_token,
                                    'senderID' => $sender_id,
                                    'message'  => $get_sender_id['sample_message']
                                ));
                                $pms_ch = curl_init('https://app.philmoresms.com/api/senderID.php');
                                curl_setopt($pms_ch, CURLOPT_POST, true);
                                curl_setopt($pms_ch, CURLOPT_POSTFIELDS, $pms_post);
                                curl_setopt($pms_ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($pms_ch, CURLOPT_TIMEOUT, 15);
                                curl_setopt($pms_ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($pms_ch, CURLOPT_SSL_VERIFYPEER, false);
                                curl_setopt($pms_ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
                                curl_exec($pms_ch);
                                curl_close($pms_ch);
                            }
                        }

                        // ── Auto-submit to KudiSMS when approving ───────────────────────
                        $kudi_api_q = mysqli_query($connection_server, "SELECT api_key FROM sas_api_gateway WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_provider_name LIKE '%kudisms.net%' LIMIT 1");
                        if(mysqli_num_rows($kudi_api_q) > 0) {
                            $kudi_api_detail = mysqli_fetch_array($kudi_api_q);
                            $explode_kudisms_apikey = array_filter(explode(":", trim($kudi_api_detail['api_key'])));
                            $kudi_api_token = $explode_kudisms_apikey[0];
                            if(!empty($kudi_api_token)){
                                $kudi_curl_url = "https://my.kudisms.net/api/senderID";
                                $kudi_post_data = http_build_query(array(
                                    'token' => $kudi_api_token,
                                    'senderID' => $sender_id,
                                    'message' => $get_sender_id["sample_message"]
                                ));
                                $kudi_ch = curl_init($kudi_curl_url);
                                curl_setopt($kudi_ch, CURLOPT_POST, true);
                                curl_setopt($kudi_ch, CURLOPT_POSTFIELDS, $kudi_post_data);
                                curl_setopt($kudi_ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($kudi_ch, CURLOPT_TIMEOUT, 15);
                                curl_setopt($kudi_ch, CURLOPT_SSL_VERIFYHOST, false);
                                curl_setopt($kudi_ch, CURLOPT_SSL_VERIFYPEER, false);
                                curl_setopt($kudi_ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
                                curl_exec($kudi_ch);
                                curl_close($kudi_ch);
                            }
                        }

						$json_response_array = array("desc" => ucwords($get_sender_id["username"]."(".$sender_id.") Approved successfully"));
						$json_response_encode = json_encode($json_response_array,true);
    				}

					if($status == 3){
						$update_sender_id_status = mysqli_query($connection_server, "UPDATE sas_bulk_sms_sender_id SET status='2' WHERE vendor_id='".$get_logged_admin_details["id"]."' && sender_id='".$sender_id."'");
						$json_response_array = array("desc" => ucwords($get_sender_id["username"]."(".$sender_id.") Disabled successfully"));
						$json_response_encode = json_encode($json_response_array,true);
    				}
    			}else{
    				if(mysqli_num_rows($select_sender_id) > 1){
    					//Duplicated Sender ID
    					$json_response_array = array("desc" => "Duplicated Sender ID");
    					$json_response_encode = json_encode($json_response_array,true);
    				}else{
    					//Sender ID Not Exists
    					$json_response_array = array("desc" => "Sender ID Not Exists");
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
    	header("Location: /bc-admin/SenderIDRequests.php");
    	exit;
    }
?>
<!DOCTYPE html>
<head>
    <title>Sender ID Requests | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
	<?php include("../func/bc-admin-header.php"); ?>
		<div class="pagetitle">
      <h1>PAYMENT ORDERS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Payment Orders</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">

        <?php
            
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
            	$page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
            	$offset_statement = " OFFSET ".((10 * $page_num) - 10);
            }else{
            	$offset_statement = "";
            }
            
            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $search_statement = " && (sender_id LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR username LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_user_pending_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='2' $search_statement ORDER BY date DESC LIMIT 10 $offset_statement");
            $get_user_successful_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='1' $search_statement ORDER BY date DESC LIMIT 10 $offset_statement");
            $get_user_failed_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_admin_details["id"]."' && status='3' $search_statement ORDER BY date DESC LIMIT 10 $offset_statement");
            
        ?>
        <div class="card info-card px-5 py-5">
            <div class="row mb-3">
                <form method="get" action="SenderIDRequests.php" class="m-margin-tp-1 s-margin-tp-1">
                    <input style="user-select: auto;" name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"] ?? '')); ?>" placeholder="Sender ID, Username e.t.c" class="form-control mt-3" />
                    <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                        <i class="bi bi-search"></i> Search
                    </button>
                </form>
            </div>

            <span style="user-select: auto;" class="fw-bold h4">PENDING REQUEST</span><br>
			
            <div style="user-select: auto; cursor: grab;" class="overflow-auto mt-1">
              <table style="" class="table table-responsive table-striped table-bordered" title="Horizontal Scroll: Shift + Mouse Scroll Button">
                  <thead class="thead-dark">
                    <tr>
                    	<th>S/N</th><th>Username ID</th><th>Sender ID</th><th>Sample Message</th><th>Status</th><th>Date</th><th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    if(mysqli_num_rows($get_user_pending_transaction_details) >= 1){
                    	while($user_transaction = mysqli_fetch_assoc($get_user_pending_transaction_details)){
                    		$transaction_type = ucwords($user_transaction["type_alternative"]);
                    		$countTransaction += 1;
                    		$reject_sender_id = '<span onclick="adminSenderIDStatus(`1`,`'.$user_transaction["sender_id"].'`,`'.$user_transaction["username"].'`);" style="text-decoration: underline; color: red;" class="a-cursor">Reject Request</span>';
                    		$accept_sender_id = '<span onclick="adminSenderIDStatus(`2`,`'.$user_transaction["sender_id"].'`,`'.$user_transaction["username"].'`);" style="text-decoration: underline; color: green;" class="a-cursor">Accept Request</span>';
                    		$edit_link = '<span onclick="adminOpenEdit(`'.addslashes($user_transaction["sender_id"]).'`,`'.addslashes($user_transaction["sample_message"]).'`);" style="text-decoration:underline;color:#f0ad4e;cursor:pointer;">Edit</span>';
                    		$all_sender_id_action = $reject_sender_id." | ".$accept_sender_id." | ".$edit_link;
                    		echo 
                    		'<tr>
                    			<td>'.$countTransaction.'</td><td>'.ucwords($user_transaction["username"]).'</td><td>'.$user_transaction["sender_id"].'</td><td>'.htmlspecialchars($user_transaction["sample_message"]).'</td><td>'.tranStatus($user_transaction["status"]).'</td><td>'.formDate($user_transaction["date"]).'</td><td>'.$all_sender_id_action.'</td>
                    		</tr>';
                    	}
                    }
                    ?>
                  </tbody>
                </table>
            </div><br/>

            <span style="user-select: auto;" class="fw-bold h4">SUCCESSFUL REQUEST</span><br>
			
            <div style="user-select: auto; cursor: grab;" class="overflow-auto mt-1">
              <table style="" class="table table-responsive table-striped table-bordered" title="Horizontal Scroll: Shift + Mouse Scroll Button">
                  <thead class="thead-dark">
                    <tr>
                    	<th>S/N</th><th>Username ID</th><th>Sender ID</th><th>Sample Message</th><th>Status</th><th>Date</th><th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    if(mysqli_num_rows($get_user_successful_transaction_details) >= 1){
                    	while($user_transaction = mysqli_fetch_assoc($get_user_successful_transaction_details)){
                    		$transaction_type = ucwords($user_transaction["type_alternative"]);
                    		$countTransaction += 1;
                    		$disable_sender_id = '<span onclick="adminSenderIDStatus(`3`,`'.$user_transaction["sender_id"].'`,`'.$user_transaction["username"].'`);" style="text-decoration: underline; color: green;" class="a-cursor">Disable Request</span>';
                    		$reject_sender_id = '<span onclick="adminSenderIDStatus(`1`,`'.$user_transaction["sender_id"].'`,`'.$user_transaction["username"].'`);" style="text-decoration: underline; color: red;" class="a-cursor">Reject Request</span>';
                    		$edit_link = '<span onclick="adminOpenEdit(`'.addslashes($user_transaction["sender_id"]).'`,`'.addslashes($user_transaction["sample_message"]).'`);" style="text-decoration:underline;color:#f0ad4e;cursor:pointer;">Edit</span>';
                    		$all_sender_id_action = $disable_sender_id." | ".$reject_sender_id." | ".$edit_link;
                    		echo 
                    		'<tr>
                    			<td>'.$countTransaction.'</td><td>'.ucwords($user_transaction["username"]).'</td><td>'.$user_transaction["sender_id"].'</td><td>'.htmlspecialchars($user_transaction["sample_message"]).'</td><td>'.tranStatus($user_transaction["status"]).'</td><td>'.formDate($user_transaction["date"]).'</td><td>'.$all_sender_id_action.'</td>
                    		</tr>';
                    	}
                    }
                    ?>
                  </tbody>
                </table>
            </div><br/>

            <span style="user-select: auto;" class="fw-bold h4">REJECTED REQUEST</span><br>
			
            <div style="user-select: auto; cursor: grab;" class="overflow-auto mt-1">
              <table style="" class="table table-responsive table-striped table-bordered" title="Horizontal Scroll: Shift + Mouse Scroll Button">
                  <thead class="thead-dark">
                    <tr>
                    	<th>S/N</th><th>Username ID</th><th>Sender ID</th><th>Sample Message</th><th>Status</th><th>Date</th><th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    if(mysqli_num_rows($get_user_failed_transaction_details) >= 1){
                    	while($user_transaction = mysqli_fetch_assoc($get_user_failed_transaction_details)){
                    		$countTransaction += 1;
                    		$disable_sender_id = '<span onclick="adminSenderIDStatus(`3`,`'.$user_transaction["sender_id"].'`,`'.$user_transaction["username"].'`);" style="text-decoration: underline; color: green;" class="a-cursor">Disable Request</span>';
                    		$accept_sender_id = '<span onclick="adminSenderIDStatus(`2`,`'.$user_transaction["sender_id"].'`,`'.$user_transaction["username"].'`);" style="text-decoration: underline; color: green;" class="a-cursor">Accept Request</span>';
                    		$edit_link = '<span onclick="adminOpenEdit(`'.addslashes($user_transaction["sender_id"]).'`,`'.addslashes($user_transaction["sample_message"]).'`);" style="text-decoration:underline;color:#f0ad4e;cursor:pointer;">Edit</span>';
                    		$all_sender_id_action = $disable_sender_id." | ".$accept_sender_id." | ".$edit_link;
                    		echo 
                    		'<tr>
                    			<td>'.$countTransaction.'</td><td>'.ucwords($user_transaction["username"]).'</td><td>'.$user_transaction["sender_id"].'</td><td>'.htmlspecialchars($user_transaction["sample_message"]).'</td><td>'.tranStatus($user_transaction["status"]).'</td><td>'.formDate($user_transaction["date"]).'</td><td>'.$all_sender_id_action.'</td>
                    		</tr>';
                    	}
                    }
                    ?>
                  </tbody>
                </table>
            </div><br/>
            
            <div class="mt-2 justify-content-between justify-items-center">
                <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
                <a href="SenderIDRequests.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Prev</button>
                </a>
                <?php } ?>
                <?php
                	if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                		$trans_next = (trim(strip_tags($_GET["page"])) +1);
                	}else{
                		$trans_next = 2;
                	}
                ?>
                <a href="SenderIDRequests.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Next</button>
                </a>
            </div>
        </div>
      </div>
    </section>


    <!-- ── Admin Edit Sender ID Modal ──────────────────────────────────────── -->
    <div class="modal fade" id="adminEditSenderIDModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow border-0">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-warning"></i>Admin Edit Sender ID</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form method="post" action="">
            <div class="modal-body p-4">
                <div class="alert alert-warning border-0 small rounded-3 mb-3">
                    <i class="bi bi-info-circle me-1"></i>Saving will <strong>reset status to Pending</strong>. You can then re-approve to trigger PhilmoreSMS submission.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Sender ID</label>
                    <input id="ae-sender-id-display" type="text" class="form-control fw-bold text-center bg-light" readonly/>
                    <input id="ae-sender-id-value" name="ae-sender-id-value" type="hidden"/>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold text-uppercase text-muted">Sample Message</label>
                    <textarea id="ae-sample-message" name="ae-sample-message" rows="4" class="form-control" minlength="10" maxlength="160" placeholder="Enter a corrected sample message" required></textarea>
                    <div class="d-flex justify-content-end mt-1">
                        <small id="ae-msg-counter" class="text-muted" style="font-size:10px;">0/160</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button name="admin-edit-sender-id" type="submit" class="btn btn-warning rounded-pill px-4 fw-bold">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>

	<?php include("../func/bc-admin-footer.php"); ?>

<script>
function adminOpenEdit(senderId, sampleMsg) {
    document.getElementById('ae-sender-id-display').value = senderId.toUpperCase();
    document.getElementById('ae-sender-id-value').value = senderId;
    var ta = document.getElementById('ae-sample-message');
    ta.value = sampleMsg;
    document.getElementById('ae-msg-counter').textContent = sampleMsg.length + '/160';
    ta.addEventListener('input', function(){ document.getElementById('ae-msg-counter').textContent = this.value.length + '/160'; });
    new bootstrap.Modal(document.getElementById('adminEditSenderIDModal')).show();
}
</script>

</body>
</html>
