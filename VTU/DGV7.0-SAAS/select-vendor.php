<?php session_start();
    //header("Content-Type", "application/json");
    include_once("func/bc-connect.php");

    //Select Vendor Table
    $get_api_post_info = json_decode(file_get_contents('php://input'),true);
    $post_vendor_id = mysqli_real_escape_string($connection_server, trim($get_api_post_info["vendor"]));
    if(isset($get_api_post_info["request_sender"])){
        $post_request_sender = mysqli_real_escape_string($connection_server, trim(strip_tags($get_api_post_info["request_sender"])));   
    }else{
        $post_request_sender = "";
    }
    
    $get_vendor_detail_via_vendor_id = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='".$post_vendor_id."' LIMIT 1");
    $get_logged_vendor_details = mysqli_fetch_array($get_vendor_detail_via_vendor_id);
    if(mysqli_num_rows($get_vendor_detail_via_vendor_id) == 1){
        if(isset($_SESSION["spadmin_session"]) && ($post_request_sender == "spadmin")){
            //Vendor exists
            $vendorname = strtoupper($get_logged_vendor_details["firstname"]." ".$get_logged_vendor_details["lastname"]).checkIfEmpty(ucwords($get_logged_vendor_details["othername"]),", ", "");
            $json_response_array = array("status" => "200", "text" => $vendorname);
            echo json_encode($json_response_array,true);
        }else{
            //Error: Fraudulent Transfer
            $json_response_array = array("status" => "201", "text" => "Error: Fraudulent Transfer");
            echo json_encode($json_response_array,true);
        }
    }else{
        //Vendor not exists
        $json_response_array = array("status" => "201", "text" => "Vendor Not Exists");
        echo json_encode($json_response_array,true);
    }
    
?>