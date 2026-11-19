<?php session_start();
    include("../func/bc-admin-config.php");
    
    if(isset($_POST["update-status"])){
        $message = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["message"])));
        if(!empty($message)){
            $select_vendor_status_message = mysqli_query($connection_server, "SELECT * FROM sas_vendor_status_messages WHERE vendor_id='".$get_logged_admin_details["id"]."'");
            if(mysqli_num_rows($select_vendor_status_message) == 1){
            	mysqli_query($connection_server, "UPDATE sas_vendor_status_messages SET message='$message' WHERE vendor_id='".$get_logged_admin_details["id"]."'");
            	//Hurray! Status Message Updated Successfully
            	$json_response_array = array("desc" => "Hurray! Status Message Updated Successfully");
            	$json_response_encode = json_encode($json_response_array,true);
            }else{
            	if(mysqli_num_rows($select_vendor_status_message) > 1){
            		mysqli_query($connection_server, "DELETE FROM sas_vendor_status_messages WHERE vendor_id='".$get_logged_admin_details["id"]."'");
            		mysqli_query($connection_server, "INSERT INTO sas_vendor_status_messages (vendor_id, message) VALUES ('".$get_logged_admin_details["id"]."','$message')");
            		//Hurray! Status Message Recreated Successfully
            		$json_response_array = array("desc" => "Hurray! Status Message Recreated Successfully");
            		$json_response_encode = json_encode($json_response_array,true);
            	}else{
            		mysqli_query($connection_server, "INSERT INTO sas_vendor_status_messages (vendor_id, message) VALUES ('".$get_logged_admin_details["id"]."','$message')");
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
        exit;
    }
    
    $select_user_vendor_status_message = mysqli_query($connection_server, "SELECT * FROM sas_vendor_status_messages WHERE vendor_id='".$get_logged_admin_details["id"]."'");
    if(mysqli_num_rows($select_user_vendor_status_message) == 1){
    	$get_user_vendor_status_message = mysqli_fetch_array($select_user_vendor_status_message);
    	$get_user_vendor_status_message_text = 	$get_user_vendor_status_message["message"];
    }else{
    	$get_user_vendor_status_message_text = "";
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Status Message | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "STATUS MESSAGE", "crumb" => "Home / Status Message")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
        <div class="card info-card px-5 py-5">
            <form method="post" action="">
            	<div style="text-align: center;" class="container col-12 col-lg-8 justify-items-center justify-content-between">
                    <span id="" class="h5"><span style="user-select: auto;">Username:</span> <span id="" class="color-10" style="user-select: auto;">{username}</span></span><br/>
    		   	</div><br/>
                <textarea style="text-align: left; resize: none;" id="" name="message" onkeyup="" placeholder="Message" class="form-control mb-1" rows="10" required><?php echo $get_user_vendor_status_message_text; ?></textarea><br>
                <button name="update-status" type="submit" style="user-select: auto;" class="btn btn-primary col-12 mb-1" >
                    UPDATE STATUS
                </button><br>
                <div style="text-align: center;" class="text-dark h5">
                    <span id="product-status-span" class="" style="user-select: auto;"></span>
                </div>
            </form>
        </div>
      </div>
    </section>

	
	



<?php bc_adm_foot(array("area" => "admin")); ?>
