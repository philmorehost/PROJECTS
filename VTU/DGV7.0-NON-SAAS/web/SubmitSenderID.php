<?php session_start();
    include("../func/bc-config.php");
	
    // ── Trivial / banned one-liner messages ────────────────────────────────────
    function isTrivialMessage($msg) {
        $msg_clean = strtolower(trim($msg));
        $banned = ['hi','hello','hey','ok','okay','test','yes','no','hola','greetings',
                   'morning','good morning','thanks','thank you','bye','goodbye','welcome',
                   'sup','what','yo','howdy','ola'];
        if (in_array($msg_clean, $banned)) return true;
        if (str_word_count($msg_clean) < 5) return true;
        if (strlen($msg_clean) < 20) return true;
        return false;
    }

// ── Auto-submit function to upstream API ─────────────────────────────────────
function auto_submit_sender_id_to_api($connection_server, $vendor_id, $s_id, $s_msg) {
    $log_file = $_SERVER['DOCUMENT_ROOT'] . '/sender_id_debug.log';
    $log_data = date('Y-m-d H:i:s') . " - Submitting SenderID '$s_id' for Vendor '$vendor_id'\n";

    // PhilmoreSMS
    $pms_api_q = mysqli_query($connection_server, "SELECT api_key, api_provider_name FROM sas_api_gateway WHERE vendor_id='$vendor_id' AND api_provider_name LIKE '%philmoresms.com%' LIMIT 1");
    if($pms_api_q && mysqli_num_rows($pms_api_q) > 0){
        $pms_api_row = mysqli_fetch_assoc($pms_api_q);
        $pms_token   = trim($pms_api_row['api_key']);
        $log_data .= "Found PhilmoreSMS API in DB. Provider Name: " . $pms_api_row['api_provider_name'] . "\n";
        
        if(!empty($pms_token)){
            $pms_post = http_build_query(['token' => $pms_token, 'senderID' => $s_id, 'message' => $s_msg]);
            $pms_ch = curl_init('https://app.philmoresms.com/api/senderID.php');
            curl_setopt($pms_ch, CURLOPT_POST, true);
            curl_setopt($pms_ch, CURLOPT_POSTFIELDS, $pms_post);
            curl_setopt($pms_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($pms_ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($pms_ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($pms_ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($pms_ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            $response = curl_exec($pms_ch);
            $err = curl_error($pms_ch);
            $http_code = curl_getinfo($pms_ch, CURLINFO_HTTP_CODE);
            curl_close($pms_ch);
            $log_data .= "PhilmoreSMS Response (HTTP $http_code): $response\n";
            if($err) $log_data .= "PhilmoreSMS Curl Error: $err\n";
        } else {
            $log_data .= "PhilmoreSMS API Key is empty.\n";
        }
    } else {
        $log_data .= "PhilmoreSMS not active or not found in sas_api_gateway for this vendor.\n";
    }

    // KudiSMS
    $kudi_api_q = mysqli_query($connection_server, "SELECT api_key, api_provider_name FROM sas_api_gateway WHERE vendor_id='$vendor_id' AND api_provider_name LIKE '%kudisms.net%' LIMIT 1");
    if($kudi_api_q && mysqli_num_rows($kudi_api_q) > 0){
        $kudi_api_row = mysqli_fetch_assoc($kudi_api_q);
        $explode_kudisms_apikey = array_filter(explode(":", trim($kudi_api_row['api_key'])));
        $kudi_api_token = $explode_kudisms_apikey[0] ?? '';
        $log_data .= "Found KudiSMS API in DB. Provider Name: " . $kudi_api_row['api_provider_name'] . "\n";

        if(!empty($kudi_api_token)){
            $kudi_post = http_build_query(['token' => $kudi_api_token, 'senderID' => $s_id, 'message' => $s_msg]);
            $kudi_ch = curl_init('https://my.kudisms.net/api/senderID');
            curl_setopt($kudi_ch, CURLOPT_POST, true);
            curl_setopt($kudi_ch, CURLOPT_POSTFIELDS, $kudi_post);
            curl_setopt($kudi_ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($kudi_ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($kudi_ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($kudi_ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($kudi_ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            $response = curl_exec($kudi_ch);
            $err = curl_error($kudi_ch);
            $http_code = curl_getinfo($kudi_ch, CURLINFO_HTTP_CODE);
            curl_close($kudi_ch);
            $log_data .= "KudiSMS Response (HTTP $http_code): $response\n";
            if($err) $log_data .= "KudiSMS Curl Error: $err\n";
        } else {
            $log_data .= "KudiSMS API Key is empty.\n";
        }
    } else {
        $log_data .= "KudiSMS not active or not found in sas_api_gateway for this vendor.\n";
    }
    
    file_put_contents($log_file, $log_data . "\n", FILE_APPEND);
}

// ── Handle Edit Sender ID (user — only Pending/Rejected) ──────────────────
if(isset($_POST["edit-sender-id"])){
    $edit_sender_id  = mysqli_real_escape_string($connection_server, preg_replace("/[^a-zA-Z]+/","",trim(strip_tags(strtolower($_POST["edit-sender-id-value"])))));
    $edit_sample_msg = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["edit-sample-message"])));

    if(isTrivialMessage($edit_sample_msg)){
        $json_response_array = array("desc" => "Please submit a proper example message (at least 5 words, 20+ characters) that represents what you actually intend to send.");
    } elseif(!empty($edit_sender_id) && !empty($edit_sample_msg) && strlen($edit_sample_msg) <= 160){
        // Only allow edit on own records that are Pending(2) or Rejected(3)
        $check_edit = mysqli_query($connection_server, "SELECT id FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$get_logged_user_details["username"]."' && sender_id='$edit_sender_id' && status IN (2,3) LIMIT 1");
        if(mysqli_num_rows($check_edit) == 1){
            mysqli_query($connection_server, "UPDATE sas_bulk_sms_sender_id SET sample_message='$edit_sample_msg', status='2' WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$get_logged_user_details["username"]."' && sender_id='$edit_sender_id' && status IN (2,3)");
            
            // Auto-submit to API right away
            auto_submit_sender_id_to_api($connection_server, $get_logged_user_details["vendor_id"], $edit_sender_id, $edit_sample_msg);
            
            $json_response_array = array("desc" => "Sender ID updated and resubmitted for review.");
        } else {
            $json_response_array = array("desc" => "Unable to edit. Sender ID not found, already approved, or does not belong to you.");
        }
    } else {
        $json_response_array = array("desc" => "Invalid data. Sample message must be 20–160 characters.");
    }
    $json_response_decode = json_decode(json_encode($json_response_array),true);
    $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
    header("Location: ".$_SERVER["REQUEST_URI"]);
    exit;
}

    // ── Handle New Sender ID Submission ───────────────────────────────────────
    if(isset($_POST["submit-sender-id"])){
        $sender_id = mysqli_real_escape_string($connection_server, preg_replace("/[^a-zA-Z]+/","",trim(strip_tags(strtolower($_POST["sender-id"])))));
        $sample_message = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["sample-message"])));
        
        if(!empty($sender_id) && (strlen($sender_id) >= 3) && (strlen($sender_id) <= 11) && !empty($sample_message) && (strlen($sample_message) >= 20) && (strlen($sample_message) <= 160)) {
            if(isTrivialMessage($sample_message)){
                $json_response_array = array("desc" => "Please submit a proper example message (at least 5 words, 20+ characters) that represents what you actually intend to send.");
            } else {
                $check_sender_id = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && sender_id='$sender_id'");
                $check_sender_id_by_user = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$get_logged_user_details["username"]."' && sender_id='$sender_id'");
                if(mysqli_num_rows($check_sender_id) == 0){
                    mysqli_query($connection_server, "INSERT INTO sas_bulk_sms_sender_id (vendor_id, username, sender_id, sample_message, status) VALUES ('".$get_logged_user_details["vendor_id"]."', '".$get_logged_user_details["username"]."', '$sender_id', '$sample_message', '2')");
                    
                    // Auto-submit to API right away
                    auto_submit_sender_id_to_api($connection_server, $get_logged_user_details["vendor_id"], $sender_id, $sample_message);

                    $json_response_array = array("desc" => "Sender ID Submitted Successfully For Review");
                }else{
                    if(mysqli_num_rows($check_sender_id) == 1){
                        if(mysqli_num_rows($check_sender_id_by_user) == 1){
                            $json_response_array = array("desc" => "Sender ID Already Submitted by You");
                        }else{
                            $json_response_array = array("desc" => "Sender ID Already Exists");
                        }
                    }else{
                        if(mysqli_num_rows($check_sender_id) > 1){
                            $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        }
                    }
                }
            }
        }else{
            if(empty($sender_id)){
                $json_response_array = array("desc" => "Sender ID Field Is Empty");
            }else{
                if(strlen($sender_id) < 3){
                    $json_response_array = array("desc" => "Sender ID Must Not Be Less Than 3 Letters");
                }else{
                    if(strlen($sender_id) > 11){
                        $json_response_array = array("desc" => "Sender ID Must Not Be Greater Than 11 Letters");
                    }else{
                        if(empty($sample_message)){
                            $json_response_array = array("desc" => "Sample Message Field Is Empty");
                        }else{
                            if(strlen($sample_message) < 20){
                                $json_response_array = array("desc" => "Please submit a proper example message (at least 5 words, 20+ characters) that represents what you actually intend to send.");
                            }else{
                                if(strlen($sample_message) > 160){
                                    $json_response_array = array("desc" => "Sample Message Must Not Be Greater Than 160 Characters");
                                }
                            }
                        }
                    }
                }
            }
        }

        $json_response_decode = json_decode(json_encode($json_response_array),true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

?>
<!DOCTYPE html>
<head>
    <title>Sender ID | <?php echo $get_all_site_details["site_title"]; ?></title>
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
      <h1>SUBMIT SENDER ID</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Submit Sender ID</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 p-4 mb-4">
                <h5 class="fw-bold mb-4 text-center">Register New Sender ID</h5>
                <form method="post" action="">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase text-muted">Sender ID (3-11 Letters)</label>
                        <input name="sender-id" type="text" placeholder="e.g BEETECH" pattern="[a-zA-Z]{3,11}" class="form-control form-control-lg fw-bold text-center" required/>
                        <small class="text-muted" style="font-size: 10px;">Only letters allowed. No spaces or special characters.</small>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-uppercase text-muted">Sample Message</label>
                        <textarea name="sample-message" rows="3" id="new-sample-msg" placeholder="Enter a realistic sample of the message you intend to send e.g. 'Dear Customer, your order #12345 has been dispatched and will arrive within 24 hours. Visit our website for tracking.'" class="form-control" minlength="20" maxlength="160" required></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted" style="font-size: 10px;">Minimum 5 words &amp; 20 characters. No greetings or test messages.</small>
                            <small id="new-msg-counter" class="text-muted" style="font-size: 10px;">0/160</small>
                        </div>
                    </div>
                    <button name="submit-sender-id" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm fw-bold mt-3">
                        SUBMIT FOR REVIEW
                    </button>
                </form>
            </div>
        
		<?php
            
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
            	$page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
            	$offset_statement = " OFFSET ".((10 * $page_num) - 10);
            }else{
            	$offset_statement = "";
            }
            
            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $search_statement = " && (sender_id LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_bulk_sms_sender_id WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$_SESSION["user_session"]."' $search_statement ORDER BY date DESC LIMIT 10 $offset_statement");
            
        ?>
            <div class="card shadow-sm border-0 overflow-hidden rounded-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-primary">Your Sender IDs</h6>
                    <form method="get" action="SubmitSenderID.php" class="d-flex gap-2">
                        <input name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"] ?? '')); ?>" placeholder="Search ID..." class="form-control form-control-sm rounded-pill px-3" />
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3">Search</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="border-0 px-4">Sender ID</th>
                                <th class="border-0">Sample Message</th>
                                <th class="border-0">Status</th>
                                <th class="border-0 text-end px-4">Date</th>
                                <th class="border-0 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(mysqli_num_rows($get_user_transaction_details) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($get_user_transaction_details)):
                                    $status_class = ($row['status'] == 1 ? 'text-success' : ($row['status'] == 2 ? 'text-warning' : 'text-danger'));
                                ?>
                                    <tr>
                                        <td class="px-4 fw-bold text-primary"><?php echo strtoupper($row["sender_id"]); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($row["sample_message"]); ?></td>
                                        <td class="small <?php echo $status_class; ?> fw-bold"><?php echo tranStatus($row["status"]); ?></td>
                                        <td class="text-end px-4 small text-muted"><?php echo date("M d, Y", strtotime($row["date"])); ?></td>
                                        <td class="text-center">
                                            <?php if(in_array($row['status'], [2, 3])): ?>
                                                <button class="btn btn-sm btn-outline-warning rounded-pill px-3"
                                                    onclick="openEditModal('<?php echo htmlspecialchars($row['sender_id'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($row['sample_message'], ENT_QUOTES); ?>')">
                                                    <i class="bi bi-pencil-square me-1"></i>Edit
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted">No Sender IDs found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white py-3 border-0 d-flex justify-content-between">
                    <div>
                        <?php if(isset($_GET["page"]) && $_GET["page"] > 1): ?>
                            <a href="SubmitSenderID.php?<?php echo $search_parameter; ?>page=<?php echo ($_GET["page"] - 1); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">Previous</a>
                        <?php endif; ?>
                    </div>
                    <?php
                        $next_page = ($_GET["page"] ?? 1) + 1;
                    ?>
                    <a href="SubmitSenderID.php?<?php echo $search_parameter; ?>page=<?php echo $next_page; ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">Next</a>
                </div>
            </div>
        </div>
      </div>
    </section>

    <!-- ── Edit Sender ID Modal ───────────────────────────────────────────── -->
    <div class="modal fade" id="editSenderIDModal" tabindex="-1" aria-labelledby="editSenderIDModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow border-0">
          <div class="modal-header border-0 pb-0">
            <h5 class="modal-title fw-bold" id="editSenderIDModalLabel"><i class="bi bi-pencil-square me-2 text-warning"></i>Edit Sender ID</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form method="post" action="">
            <div class="modal-body p-4">
                <div class="alert alert-warning border-0 small rounded-3 mb-3">
                    <i class="bi bi-info-circle me-1"></i>Editing will <strong>reset the status to Pending</strong> and re-submit for admin review.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Sender ID</label>
                    <input id="edit-sender-id-display" type="text" class="form-control fw-bold text-center bg-light" readonly/>
                    <input id="edit-sender-id-value" name="edit-sender-id-value" type="hidden"/>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold text-uppercase text-muted">Sample Message</label>
                    <textarea id="edit-sample-message" name="edit-sample-message" rows="4" class="form-control" minlength="20" maxlength="160" placeholder="Enter a realistic sample message (minimum 5 words, 20 characters)" required></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted" style="font-size: 10px;">Minimum 5 words &amp; 20 characters.</small>
                        <small id="edit-msg-counter" class="text-muted" style="font-size: 10px;">0/160</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button name="edit-sender-id" type="submit" class="btn btn-warning rounded-pill px-4 fw-bold">Save &amp; Resubmit</button>
            </div>
          </form>
        </div>
      </div>
    </div>

	<?php include("../func/bc-footer.php"); ?>
	
<script>
function openEditModal(senderId, sampleMsg) {
    document.getElementById('edit-sender-id-display').value = senderId.toUpperCase();
    document.getElementById('edit-sender-id-value').value = senderId;
    document.getElementById('edit-sample-message').value = sampleMsg;
    updateCounter('edit-sample-message', 'edit-msg-counter');
    var modal = new bootstrap.Modal(document.getElementById('editSenderIDModal'));
    modal.show();
}

function updateCounter(textareaId, counterId) {
    var ta = document.getElementById(textareaId);
    var counter = document.getElementById(counterId);
    if(ta && counter) {
        counter.textContent = ta.value.length + '/160';
        ta.addEventListener('input', function(){ counter.textContent = this.value.length + '/160'; });
    }
}

document.addEventListener('DOMContentLoaded', function(){
    updateCounter('new-sample-msg', 'new-msg-counter');
});
</script>

</body>
</html>