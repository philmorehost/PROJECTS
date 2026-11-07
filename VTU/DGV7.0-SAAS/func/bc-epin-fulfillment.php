<?php
function fulfillEPIN($epin, $recipient, $extra_data = "") {
    global $connection_server;

    $epin = trim(strip_tags($epin));
    $recipient = trim(strip_tags($recipient));
    // Standardize recipient phone: remove non-numeric and convert 234 format to 0 format
    // This ensures compatibility with gateway scripts that use substr($phone, 1)
    $recipient = sanitize_phone_number($recipient);
    $recipient = mysqli_real_escape_string($connection_server, $recipient);

    // Normalize EPIN: Remove dashes and re-apply if 12 digits (Standard format: XXXX-XXXX-XXXX)
    $normalized_epin = str_replace("-", "", $epin);
    if(strlen($normalized_epin) == 12){
        $epin = substr($normalized_epin, 0, 4) . "-" . substr($normalized_epin, 4, 4) . "-" . substr($normalized_epin, 8, 4);
    }
    $epin = mysqli_real_escape_string($connection_server, $epin);

    $vendor_id = resolveVendorID();
    $get_card = mysqli_query($connection_server, "SELECT c.*, u.username, v.website_url FROM sas_databundle_cards c JOIN sas_users u ON c.user_id = u.id JOIN sas_vendors v ON c.vendor_id = v.id WHERE c.vendor_id='$vendor_id' AND c.epin='$epin' && c.status='Sold'");

    if(mysqli_num_rows($get_card) == 1){
        $card = mysqli_fetch_assoc($get_card);
        $vendor_id = $card['vendor_id'];
        $isp = strtolower($card['network']);
        $service_type = $card['service_type'];

        // Network Check for Airtime/Data to prevent using card on wrong network
        if (in_array($service_type, array('airtime', 'data'))) {
            $identified_network = identifyISP($recipient);
            // If identified as a different known network, block it.
            if ($identified_network != "Unknown" && $identified_network != $isp) {
                return array("status" => "failed", "message" => "Network mismatch: This EPIN is for ".strtoupper($isp).", but your number ($recipient) is identified as ".strtoupper($identified_network).".");
            }
        }
        $data_type = strtolower($card['data_type']);
        $plan_code = strtolower($card['plan_name']);

        $phone_no = $recipient;
        $product_name = $isp;

        // Complex services use extra_data as identifier
        if (in_array($service_type, ['cable', 'electric', 'betting'])) {
            if (empty($extra_data)) return ["status" => "failed", "message" => "This service requires an additional identifier (Meter/Smartcard/UserID)."];
            $phone_no = $extra_data; // Override for gateway
        }

        $purchase_method = "FULFILLMENT";

        // Fetch API details
        if ($service_type == 'data') {
            $data_type_table_name_arrays = array("sme-data" => "sas_sme_data_status", "cg-data" => "sas_cg_data_status", "dd-data" => "sas_dd_data_status", "shared-data" => "sas_shared_data_status");
            $st_table = $data_type_table_name_arrays[$data_type];
        } else {
            $service_type_table_name_arrays = ["airtime" => "sas_airtime_status", "cable" => "sas_cable_status", "electric" => "sas_electric_status", "exam" => "sas_exam_status", "betting" => "sas_betting_status"];
            $st_table = $service_type_table_name_arrays[$service_type];
        }

        $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $st_table WHERE vendor_id='$vendor_id' && product_name='$isp'"));

        if (!$get_item_status_details) return ["status" => "failed", "message" => "Service provider not configured for $isp"];

        $get_api_detail = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vendor_id' && id='" . $get_item_status_details["api_id"] . "'"));

        if($get_api_detail && $get_api_detail['status'] == 1){
            $api_detail = $get_api_detail;

            // Set global user and vendor context for gateway scripts
            $GLOBALS['vendor_id'] = $vendor_id;
            $get_logged_user_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='".$card['user_id']."' LIMIT 1"));

            // Build web_data_size_array
            $get_all_plans = mysqli_query($connection_server, "SELECT * FROM sas_smart_parameter_values WHERE vendor_id='$vendor_id' && api_id='".$api_detail['id']."' && product_id='".$card['product_id']."'");
            $web_data_size_array = [];
            while($ap = mysqli_fetch_assoc($get_all_plans)){
                $web_data_size_array[$ap['val_1']] = $ap['val_1'];
            }

            $api_type_for_file = ($service_type == 'data') ? $data_type : $service_type;
            // Normalise the admin-typed domain (scheme, "www.", case, trailing slash) before
            // building the filename, so all those spellings reach the same gateway instead of
            // silently missing and fulfilling through the local-server file.
            $api_gateway_base_host = strtolower(trim($api_detail["api_base_url"]));
            $api_gateway_base_host = preg_replace('#^https?://#i', '', $api_gateway_base_host);
            $api_gateway_base_host = preg_replace('#^www\.#i', '', $api_gateway_base_host);
            $api_gateway_base_host = rtrim($api_gateway_base_host, "/");
            $api_gateway_name_file_exists = $api_type_for_file . "-" . str_replace(".", "-", $api_gateway_base_host) . ".php";
            if (file_exists(__DIR__ . "/api-gateway/" . $api_gateway_name_file_exists)) {
                $api_gateway_name = $api_gateway_name_file_exists;
            } else {
                error_log("[DGV-GATEWAY] No gateway file for api_base_url='" . $api_detail["api_base_url"] . "' (looked for " . $api_gateway_name_file_exists . "); falling back to " . $api_type_for_file . "-localserver.php");
                $api_gateway_name = $api_type_for_file . "-localserver.php";
            }

            // For gateways that need it
            $quantity = $plan_code;
            $amount = $card['price']; // Or fetch from parameter values

            // Extra variables for specific gateways
            if ($service_type == 'electric') {
                $epp = $isp;
                $meter_number = $extra_data;
                $type = "prepaid"; // Default
                $action_function = 1;
            } elseif ($service_type == 'cable') {
                $cpp = $isp;
                $iuc_number = $extra_data;
                $action_function = 1;
            } elseif ($service_type == 'betting') {
                $epp = $isp;
                $customer_id = $extra_data;
                $action_function = 1;
            }

            $api_response = null; $api_response_description = null; $api_response_reference = null;

            // We need to pass $connection_server to the included file
            include(__DIR__ . "/api-gateway/" . $api_gateway_name);

            if (isset($api_response) && in_array($api_response, array("successful", "pending"))) {
                mysqli_query($connection_server, "UPDATE sas_databundle_cards SET status='Used', processed_phone_number='$recipient', date_used=CURRENT_TIMESTAMP WHERE id='".$card['id']."'");

                // Record fulfillment transaction
                $reference_f = substr(str_shuffle("12345678901234567890"), 0, 15);
                $type_alt = ucwords($isp . " " . str_replace("-", " ", $service_type) . " Fulfillment");
                $fulfill_desc = ucwords($service_type)." Delivery for EPIN: ".$card['epin']." to $recipient".(!empty($extra_data) ? " (ID: $extra_data)" : "");

                $get_user = mysqli_fetch_array(mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE id='".$card['user_id']."'"));
                $u_balance = $get_user ? $get_user['balance'] : 0;

                mysqli_query($connection_server, "INSERT INTO sas_transactions (vendor_id, api_id, product_id, product_unique_id, type_alternative, reference, api_reference, username, amount, discounted_amount, balance_before, balance_after, description, mode, api_website, status)
                VALUES ('$vendor_id', '".$api_detail['id']."', '".$card['product_id']."', '".$card['epin']."', '$type_alt', '$reference_f', '".$api_response_reference."', '".$card['username']."', '0', '0', '$u_balance', '$u_balance', '$fulfill_desc', 'AUTO', '".$api_detail["api_base_url"]."', 1)");

                return ["status" => "success", "message" => $api_response_description ?? "Successful", "token" => $api_response_reference];
            } else {
                return ["status" => "failed", "message" => $api_response_description ?? "API Error"];
            }
        } else {
            return ["status" => "failed", "message" => "API not active for this service."];
        }
    } else {
        return ["status" => "failed", "message" => "EPIN not found or already used."];
    }
}
?>
