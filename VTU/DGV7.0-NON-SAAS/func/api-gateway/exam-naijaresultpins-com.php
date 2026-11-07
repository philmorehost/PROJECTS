<?php
// Two-level mapping: product_name (exam type) → quantity (sub-type) → NaijaResultPins card_type_id.
// card_type_ids confirmed live against GET https://www.naijaresultpins.com/api/v1:
// 1=WAEC Scratch Card, 2=NECO TOKEN, 3=NABTEB Scratch Card, 4=WAEC Verification Pin,
// 11=NECO e-Verification PIN. (NBAIS and EXAMINIFY card types also exist on the live account but
// have no matching product in this app yet, so they're intentionally left unmapped.)
// JAMB IDs (8/9/10) are not currently returned by the live product list for this account and are
// unverified — left as-is rather than guessed at.
$nrp_card_type_map = array(
    "waec"   => array("result_checker" => "1", "verification_pin" => "4"),
    "neco"   => array("result_checker" => "2", "verification_pin" => "11"),
    "nabteb" => array("result_checker" => "3"),
    "jamb"   => array(
        "utme_without_mock" => "8",
        "utme_with_mock"    => "9",
        "direct_entry"      => "10",
    ),
);
if (array_key_exists($product_name, $nrp_card_type_map) && array_key_exists($quantity, $nrp_card_type_map[$product_name])) {
    $card_type_id = $nrp_card_type_map[$product_name][$quantity];
    // The stored api_base_url is admin-typed and may arrive with a scheme and/or a "www."
    // prefix. Reduce it to a bare host before adding ours, otherwise a value like
    // "www.naijaresultpins.com" builds "https://www.www.naijaresultpins.com/..." and
    // "https://naijaresultpins.com" builds "https://www.https://..." - both dead hosts.
    $nrp_base_host = strtolower(trim($api_detail["api_base_url"]));
    $nrp_base_host = preg_replace('#^https?://#i', '', $nrp_base_host);
    $nrp_base_host = preg_replace('#^www\.#i', '', $nrp_base_host);
    $nrp_base_host = rtrim($nrp_base_host, "/");
    $curl_url = "https://www." . $nrp_base_host . "/api/v1/exam-card/buy";
    $curl_request = curl_init($curl_url);
    curl_setopt($curl_request, CURLOPT_POST, true);
    curl_setopt($curl_request, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl_request, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl_request, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl_request, CURLOPT_SSL_VERIFYPEER, false);
    $curl_http_headers = array("Authorization: Bearer " . $api_detail["api_key"], "Content-Type: application/json");
    curl_setopt($curl_request, CURLOPT_HTTPHEADER, $curl_http_headers);
    // NaijaResultPins "quantity" is the number of cards to buy — always 1 per transaction on this platform.
    // $quantity here is the exam sub-type (e.g. "result_checker") used for the card_type_id lookup above.
    $curl_postfields_data = json_encode(array("card_type_id" => $card_type_id, "quantity" => "1"));
    curl_setopt($curl_request, CURLOPT_POSTFIELDS, $curl_postfields_data);
    $curl_result = curl_exec($curl_request);
    // Must be read before curl_close(). It forms part of the success test below: this provider
    // signals failure through the HTTP status, so a 401 carrying a JSON body is still a failure.
    $nrp_http_code = (int)curl_getinfo($curl_request, CURLINFO_HTTP_CODE);
    $curl_json_result = json_decode($curl_result, true);
if(!is_array($curl_json_result) || !isset($curl_json_result["code"])){
    // "message" is what the failure branch below reads. Setting only "response_description"
    // meant this text was always discarded and every failure surfaced as "Unknown Error".
    $curl_json_result = array("code" => "999", "message" => "Invalid API response. Check your API credentials and try again.");
    }

    if (curl_errno($curl_request)) {
        $api_response = "failed";
        $api_response_text = curl_error($curl_request);
        $api_response_description = "Curl Error";
        $api_response_status = 3;
    } else {
        // Collect the purchased cards. The provider returns them either at the top level
        // ("cards") or wrapped in a "data" envelope, so accept both shapes instead of
        // assuming one and silently delivering a blank PIN.
        $nrp_card_items = array();
        if (isset($curl_json_result["cards"]) && is_array($curl_json_result["cards"])) {
            $nrp_card_items = $curl_json_result["cards"];
        } elseif (isset($curl_json_result["data"]["cards"]) && is_array($curl_json_result["data"]["cards"])) {
            $nrp_card_items = $curl_json_result["data"]["cards"];
        } elseif (isset($curl_json_result["data"][0]) && is_array($curl_json_result["data"])) {
            $nrp_card_items = $curl_json_result["data"];
        }

        $cards = array();
        foreach ($nrp_card_items as $card_item) {
            if (!is_array($card_item)) continue;
            $nrp_pin = "";
            foreach (array("pin", "card_pin", "token") as $nrp_pin_key) {
                if (isset($card_item[$nrp_pin_key]) && $card_item[$nrp_pin_key] !== "") { $nrp_pin = $card_item[$nrp_pin_key]; break; }
            }
            if ($nrp_pin === "") continue;
            $nrp_serial = "";
            foreach (array("serial_no", "serial", "card_serial") as $nrp_serial_key) {
                if (isset($card_item[$nrp_serial_key]) && $card_item[$nrp_serial_key] !== "") { $nrp_serial = $card_item[$nrp_serial_key]; break; }
            }
            $cards[] = "PIN: " . $nrp_pin . ($nrp_serial !== "" ? " | Serial: " . $nrp_serial : "");
        }

        // This provider answers with a Laravel-style envelope whose "status" is the HTTP code as
        // an integer (live: {"name":"Unauthorized","code":0,"status":401}), NOT a boolean.
        // The previous strict `status === true` test could therefore never be true, so a
        // successful purchase was always reported as failed and refunded. A card in the response
        // is the only unambiguous proof of success, so that is what we require here.
        $nrp_http_ok = ($nrp_http_code >= 200 && $nrp_http_code < 300);

        if (!empty($cards) && $nrp_http_ok) {
            $api_response = "successful";
            $api_response_reference = isset($curl_json_result["reference"]) ? $curl_json_result["reference"] : (isset($curl_json_result["data"]["reference"]) ? $curl_json_result["data"]["reference"] : "");
            $api_response_text = isset($curl_json_result["message"]) ? $curl_json_result["message"] : "Successful";
            $api_response_description = "Transaction Successful | " . implode(" | ", $cards);
            $api_response_status = 1;
        } elseif ($nrp_http_ok && isset($curl_json_result["data"]) && !empty($curl_json_result["data"]) && $nrp_card_items === array()) {
            // 2xx with a payload we could not read a card out of. Never claim success on a
            // purchase we cannot deliver: fail it so the customer is refunded rather than
            // charged for an empty PIN.
            $api_response = "failed";
            $api_response_text = isset($curl_json_result["message"]) ? $curl_json_result["message"] : "Successful response contained no card";
            $api_response_description = "Transaction Failed: provider returned no card";
            $api_response_status = 3;
        } else {
            $api_response = "failed";
            $api_response_text = isset($curl_json_result["message"]) ? $curl_json_result["message"] : "Unknown Error";
            $api_response_description = "Transaction Failed";
            $api_response_status = 3;
        }
    }
    curl_close($curl_request);
} else {
    //Service or sub-type not available
    $api_response = "failed";
    $api_response_text = "";
    $api_response_description = "Service not available";
    $api_response_status = 3;
}
?>