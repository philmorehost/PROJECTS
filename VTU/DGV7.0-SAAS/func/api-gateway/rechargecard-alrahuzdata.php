<?php
$raw_number = "123456789012345678901234567890";
$reference = substr(str_shuffle($raw_number), 0, 15);
$check_authorized_user_before_recharge = mysqli_query($conn_server_db, "SELECT * FROM authorized_rechargecard_user WHERE email='$user_session'");
if (mysqli_num_rows($check_authorized_user_before_recharge) >= 1) {
 if ($carrier == "mtn") {
  $net_id = 1;
  $ePinsRechargePlan = array("100" => 13, "200" => 2, "500" => 3);
 }

 if ($carrier == "airtel") {
  $net_id = 4;
  $ePinsRechargePlan = array("100" => 10, "200" => 11, "500" => 12);
 }

 if ($carrier == "glo") {
  $net_id = 2;
  $ePinsRechargePlan = array("100" => 4, "200" => 5, "500" => 6);
 }

 if ($carrier == "9mobile") {
  $net_id = 3;
  $ePinsRechargePlan = array("100" => 7, "200" => 8);
 }

 $wallet_balance = $all_user_details["wallet_balance"];
 if ($wallet_balance >= $discounted_price_amount) {

  $rechargecardPurchase = curl_init();
  $rechargecardApiUrl = "https://alrahuzdata.com.ng/api/rechargepin/";
  curl_setopt($rechargecardPurchase, CURLOPT_URL, $rechargecardApiUrl);
  curl_setopt($rechargecardPurchase, CURLOPT_RETURNTRANSFER, true);
 curl_setopt($rechargecardPurchase, CURLOPT_TIMEOUT, 30);
 curl_setopt($rechargecardPurchase, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($rechargecardPurchase, CURLOPT_POST, true);
  $alrahuzdataTokenPostHeader = array("Authorization: Token " . $apikey, "Content-Type: application/json");
  curl_setopt($rechargecardPurchase, CURLOPT_HTTPHEADER, $alrahuzdataTokenPostHeader);
  $pay_loads = json_encode(array(
   "network" => $net_id,
   "quantity" => $qty,
   "network_amount" => $ePinsRechargePlan[$amount],
   "name_on_card" => $site_name
  ));
  curl_setopt($rechargecardPurchase, CURLOPT_POSTFIELDS, $pay_loads);
  curl_setopt($rechargecardPurchase, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($rechargecardPurchase, CURLOPT_SSL_VERIFYPEER, false);

  $GetrechargecardJSON = curl_exec($rechargecardPurchase);
  $rechargecardJSONObj = json_decode($GetrechargecardJSON, true);
  if(!is_array($rechargecardJSONObj)){ $rechargecardJSONObj = array(); }

  //Debugger
  // fwrite(fopen("rechargecard-alrahuzdata.txt", "a"), $GetrechargecardJSON . "\n\n");

  if ($GetrechargecardJSON == true) {

   if (in_array($rechargecardJSONObj["Status"], array("successful", "pending"))) {
    $alrahuzdataRechargeCardStr = "";
    $alrahuzdataRechargeCardArrJson = $rechargecardJSONObj["data_pin"];

    foreach($alrahuzdataRechargeCardArrJson as $each_card_json){
     $decode_each_card = $each_card_json;
     $alrahuzdataRechargeCardStr .= trim($decode_each_card["fields"]["pin"]).",";
    }

    $purchased_pin_in_line_break = implode("\n", array_filter(explode(",", trim($alrahuzdataRechargeCardStr))));

    if (mysqli_query($conn_server_db, "INSERT INTO recharge_card_history (email, id, network_name, card_quality, card_array) VALUES ('$user_session', '$reference', '$carrier', '$amount', '$purchased_pin_in_line_break')") == true) {
    }
    $log_rechargecard_message = "Recharge Card PINs generated Successfully";
    $checkout_amount = floatval($discounted_price_amount);
    $original_price_amount = ($amount * $qty);
    $remain_balance = $wallet_balance - $checkout_amount;
    $ref_id = $reference;
    if (mysqli_query($conn_server_db, "UPDATE users SET wallet_balance='$remain_balance' WHERE email='$user_session'") == true) {
     if (mysqli_query($conn_server_db, "INSERT INTO transaction_history (email, id, amount, d_amount, w_bef, w_aft, status, description, transaction_type, website) VALUES ('$user_session','$ref_id','$original_price_amount', '$checkout_amount', '$wallet_balance', '$remain_balance', 'Successful', 'N$amount " . strtoupper($carrier) . " Recharge Card Qty of $qty @ N$checkout_amount', 'recharge-card', '$site_name')")) {

     }
    }
   }

   if ($rechargecardJSONObj["error"][0] == true) {
    $log_rechargecard_message = "Error: " . $rechargecardJSONObj["error"][0];
   }

  } else {
   $log_rechargecard_message = "Server currently unavailable";
  }

 } else {
  $log_rechargecard_message = "Insufficient Funds";
 }
} else {
 $log_rechargecard_message = "Error: Account Has not been Activated for Recharge Card Printing, Contact The Admin to Activate it!";
}
?>