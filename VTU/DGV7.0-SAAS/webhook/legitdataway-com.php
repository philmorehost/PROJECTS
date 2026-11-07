<?php session_start();
	include($_SERVER["DOCUMENT_ROOT"]."/func/bc-connect.php");
	
	$catch_incoming_request = json_decode(file_get_contents("php://input"),true);
	
	//Select Vendor Table
    $vendor_id = resolveVendorID();
	$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
	if($select_vendor_table){
	
		$webhook_status = $catch_incoming_request["status"];
		$webhook_request_id = $catch_incoming_request["request-id"];
	
		$select_transaction_history = mysqli_query($connection_server,"SELECT * FROM sas_transactions WHERE vendor_id='".$select_vendor_table["id"]."' && api_reference='$webhook_request_id'");
	
		if(($webhook_status == "success") && !empty($webhook_request_id)){
			if(mysqli_num_rows($select_transaction_history) == 1){
				$get_transaction_history_details = mysqli_fetch_array($select_transaction_history);
				if($get_transaction_history_details["status"] == "2"){
					$_SESSION["user_session"] = $get_transaction_history_details["username"];
					$api_response_description = str_replace(["pending","failed"], "successful", str_replace(["Transaction Pending","Transaction Failed"], "Transaction Successful", getTransaction($get_transaction_history_details["reference"], "description")));
					alterTransaction($get_transaction_history_details["reference"], "status", "1");
					alterTransaction($get_transaction_history_details["reference"], "description", $api_response_description);
				}
			}
		}
		
		if(($webhook_status == "fail") && !empty($webhook_request_id)){
			if(mysqli_num_rows($select_transaction_history) == 1){
				$get_transaction_history_details = mysqli_fetch_array($select_transaction_history);
				if($get_transaction_history_details["status"] == "2"){
					$_SESSION["user_session"] = $get_transaction_history_details["username"];
					$get_logged_user_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$select_vendor_table["id"]."' && username='".$get_transaction_history_details["username"]."'"));
					removeProductPurchaseList($get_transaction_history_details["reference"]);
					$reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
					$phone_no = getTransaction($get_transaction_history_details["reference"], "product_unique_id");
					$amount = getTransaction($get_transaction_history_details["reference"], "amount");
					$discounted_amount = getTransaction($get_transaction_history_details["reference"], "discounted_amount");
					$previous_purchase_method = getTransaction($get_transaction_history_details["reference"], "mode");
					$reference = $get_transaction_history_details["reference"];
					
					chargeUser("credit", $phone_no, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$requery_reference'</i>", $previous_purchase_method, $_SERVER["HTTP_HOST"], "1");
					// Email Beginning
					$log_template_encoded_text_array = array("{firstname}" => $get_logged_user_details["firstname"], "{lastname}" => $get_logged_user_details["lastname"], "{amount}" => "N".$discounted_amount, "{description}" => "Refund for Ref No: $reference");
					$raw_log_template_subject = getUserEmailTemplate('user-refund','subject');
					$raw_log_template_body = getUserEmailTemplate('user-refund','body');
					foreach($log_template_encoded_text_array as $array_key => $array_val){
						$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
						$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
					}
					sendVendorEmail($get_logged_user_details["email"], $raw_log_template_subject, $raw_log_template_body);
					// Email End
					$api_response_description = "Transaction Failed";
					alterTransaction($get_transaction_history_details["reference"], "status", "3");
					alterTransaction($get_transaction_history_details["reference"], "description", $api_response_description);
				}
			}
		}
	}
	
?>