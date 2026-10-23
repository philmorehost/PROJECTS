<?php
include_once(__DIR__ . "/bc-config.php");
include_once(__DIR__ . "/bc-func.php");
include_once(__DIR__ . "/bc-epin-fulfillment.php");

function processUSSDSession($session_id, $session_msisdn, $session_operation, $session_msg, $session_from, $vendor_id) {
    global $connection_server;

    $session_id = mysqli_real_escape_string($connection_server, trim(strip_tags($session_id)));
    $session_msisdn = mysqli_real_escape_string($connection_server, trim(strip_tags($session_msisdn)));
    $session_operation = strtolower(trim(strip_tags($session_operation)));
    $session_msg = trim(strip_tags($session_msg));
    $session_from = mysqli_real_escape_string($connection_server, trim(strip_tags($session_from)));
    $vendor_id = (int)$vendor_id;

    if ($vendor_id <= 0) {
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Error: System not configured for this shortcode."
        ];
    }

    // Fetch vendor settings
    $get_vendor = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
    $vendor = mysqli_fetch_assoc($get_vendor);
    if (!$vendor) {
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Error: Vendor platform not found."
        ];
    }

    // If session_operation is end, clean up and exit
    if ($session_operation == 'end' || $session_operation == 'close') {
        mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Session closed."
        ];
    }

    // Get or create session
    $get_session = mysqli_query($connection_server, "SELECT * FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id' LIMIT 1");
    $session = mysqli_fetch_assoc($get_session);

    if (!$session) {
        $current_step = 'start';
        $session_data = json_encode([]);
        mysqli_query($connection_server, "INSERT INTO sas_ussd_sessions (vendor_id, session_id, msisdn, current_step, session_data) VALUES ('$vendor_id', '$session_id', '$session_msisdn', 'start', '$session_data')");
    } else {
        $current_step = $session['current_step'];
        $session_data = json_decode($session['session_data'], true);
    }

    // STATE MACHINE
    switch ($current_step) {
        case 'start':
            // Check if user dialed direct PIN (e.g. *123*100*123456789012#)
            $direct_epin = "";
            if (preg_match('/^\*\d+\*\d+\*([\d\-]+)#?$/', $session_msg, $matches)) {
                $direct_epin = $matches[1];
            }

            if (!empty($direct_epin)) {
                return handleEPINInput($direct_epin, $session_id, $session_msisdn, $vendor_id, $vendor);
            } else {
                // Update step to enter_epin
                mysqli_query($connection_server, "UPDATE sas_ussd_sessions SET current_step='enter_epin' WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
                return [
                    "session_operation" => "continue",
                    "session_type" => 1,
                    "session_id" => $session_id,
                    "session_msg" => "Welcome to USSD Redemption.\nPlease enter your Card PIN/EPIN:"
                ];
            }
            break;

        case 'enter_epin':
            return handleEPINInput($session_msg, $session_id, $session_msisdn, $vendor_id, $vendor);
            break;

        case 'select_recharge_option':
            // Expecting 1 or 2
            $choice = trim($session_msg);
            if (!isset($session_data['epin'])) {
                mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
                return [
                    "session_operation" => "end",
                    "session_type" => 4,
                    "session_id" => $session_id,
                    "session_msg" => "Error: Session timed out or invalid."
                ];
            }

            if ($choice == '1') {
                // Recharge self
                return triggerFulfillment($session_data['epin'], $session_msisdn, "", $session_id, $vendor_id, $vendor);
            } elseif ($choice == '2') {
                mysqli_query($connection_server, "UPDATE sas_ussd_sessions SET current_step='enter_recipient' WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
                return [
                    "session_operation" => "continue",
                    "session_type" => 1,
                    "session_id" => $session_id,
                    "session_msg" => "Please enter the target phone number:"
                ];
            } else {
                return [
                    "session_operation" => "continue",
                    "session_type" => 1,
                    "session_id" => $session_id,
                    "session_msg" => "Invalid choice.\n1. Recharge this line\n2. Recharge another number"
                ];
            }
            break;

        case 'enter_recipient':
            if (!isset($session_data['epin'])) {
                mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
                return [
                    "session_operation" => "end",
                    "session_type" => 4,
                    "session_id" => $session_id,
                    "session_msg" => "Error: Session timed out or invalid."
                ];
            }
            $recipient = trim($session_msg);
            if (empty($recipient) || !is_numeric($recipient) || strlen($recipient) < 10) {
                return [
                    "session_operation" => "continue",
                    "session_type" => 1,
                    "session_id" => $session_id,
                    "session_msg" => "Invalid phone number. Please enter a valid number:"
                ];
            }
            return triggerFulfillment($session_data['epin'], $recipient, "", $session_id, $vendor_id, $vendor);
            break;

        case 'enter_extra_id':
            if (!isset($session_data['epin'])) {
                mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
                return [
                    "session_operation" => "end",
                    "session_type" => 4,
                    "session_id" => $session_id,
                    "session_msg" => "Error: Session timed out or invalid."
                ];
            }
            $extra_id = trim($session_msg);
            if (empty($extra_id)) {
                return [
                    "session_operation" => "continue",
                    "session_type" => 1,
                    "session_id" => $session_id,
                    "session_msg" => "ID cannot be empty. Please enter target ID:"
                ];
            }
            return triggerFulfillment($session_data['epin'], $session_msisdn, $extra_id, $session_id, $vendor_id, $vendor);
            break;

        default:
            mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
            return [
                "session_operation" => "end",
                "session_type" => 4,
                "session_id" => $session_id,
                "session_msg" => "Session error. Please try again."
            ];
    }
}

function handleEPINInput($epin_msg, $session_id, $session_msisdn, $vendor_id, $vendor) {
    global $connection_server;

    // Normalize EPIN
    $epin = trim(str_replace("-", "", $epin_msg));
    if (strlen($epin) == 12) {
        $epin = substr($epin, 0, 4) . "-" . substr($epin, 4, 4) . "-" . substr($epin, 8, 4);
    }
    $epin_esc = mysqli_real_escape_string($connection_server, $epin);

    $get_card = mysqli_query($connection_server, "SELECT c.*, u.username FROM sas_databundle_cards c JOIN sas_users u ON c.user_id = u.id WHERE c.vendor_id='$vendor_id' AND c.epin='$epin_esc' LIMIT 1");

    if (mysqli_num_rows($get_card) == 0) {
        mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Error: Invalid Card EPIN."
        ];
    }

    $card = mysqli_fetch_assoc($get_card);

    if ($card['status'] == 'Used') {
        mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Error: This EPIN has already been used."
        ];
    }

    if ($card['status'] != 'Sold') {
        mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Error: Card not in a redeemable status."
        ];
    }

    // Check if vendor configured USSD channel for this network/service
    $network_esc = mysqli_real_escape_string($connection_server, $card['network']);
    $service_esc = mysqli_real_escape_string($connection_server, $card['service_type']);
    $check_config = mysqli_query($connection_server, "SELECT ussd_channel_enabled FROM sas_databundle_config WHERE vendor_id='$vendor_id' AND network='$network_esc' AND service_type='$service_esc' LIMIT 1");
    $config = mysqli_fetch_assoc($check_config);
    if (!$config || $config['ussd_channel_enabled'] != 1) {
        mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Error: USSD channel is not enabled for this service."
        ];
    }

    // Check USSD Activation for Reseller
    if (isset($vendor['ussd_activation_fee']) && $vendor['ussd_activation_fee'] > 0) {
        $check_act = mysqli_query($connection_server, "SELECT id FROM sas_ussd_activations WHERE vendor_id='$vendor_id' AND user_id='".$card['user_id']."' AND status=1 LIMIT 1");
        if (mysqli_num_rows($check_act) == 0) {
            mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
            return [
                "session_operation" => "end",
                "session_type" => 4,
                "session_id" => $session_id,
                "session_msg" => "Error: Reseller has not activated USSD redemption."
            ];
        }
    }

    // Check if card owner has enough wallet balance for per-call charge
    $per_call_charge = isset($vendor['ussd_per_call_charge']) ? (float)$vendor['ussd_per_call_charge'] : 0.00;
    if ($per_call_charge > 0) {
        $get_user = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE id='".$card['user_id']."' LIMIT 1");
        $user_row = mysqli_fetch_assoc($get_user);
        if (!$user_row || $user_row['balance'] < $per_call_charge) {
            mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
            return [
                "session_operation" => "end",
                "session_type" => 4,
                "session_id" => $session_id,
                "session_msg" => "Error: Insufficient reseller balance for USSD charge."
            ];
        }
    }

    // Save EPIN to session data
    $session_data = json_encode(['epin' => $epin]);
    mysqli_query($connection_server, "UPDATE sas_ussd_sessions SET session_data='$session_data' WHERE vendor_id='$vendor_id' AND session_id='$session_id'");

    $service_type = $card['service_type'];

    // Branch state transition depending on service type
    if (in_array($service_type, ['cable', 'electric', 'betting'])) {
        mysqli_query($connection_server, "UPDATE sas_ussd_sessions SET current_step='enter_extra_id' WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
        $placeholder = "Account/Meter/User ID";
        if ($service_type == 'cable') $placeholder = "IUC/SmartCard Number";
        elseif ($service_type == 'electric') $placeholder = "Meter Number";
        elseif ($service_type == 'betting') $placeholder = "Betting UserID";

        return [
            "session_operation" => "continue",
            "session_type" => 1,
            "session_id" => $session_id,
            "session_msg" => "PIN verified.\nEnter target " . $placeholder . ":"
        ];
    } else {
        // Airtime, Data, Exam
        mysqli_query($connection_server, "UPDATE sas_ussd_sessions SET current_step='select_recharge_option' WHERE vendor_id='$vendor_id' AND session_id='$session_id'");
        return [
            "session_operation" => "continue",
            "session_type" => 1,
            "session_id" => $session_id,
            "session_msg" => "PIN verified.\n1. Recharge this line\n2. Recharge another number"
        ];
    }
}

function triggerFulfillment($epin, $recipient, $extra_id, $session_id, $vendor_id, $vendor) {
    global $connection_server;

    // Set global vendor id for resolveVendorID
    $GLOBALS['vendor_id'] = $vendor_id;

    // Perform fulfillment
    $result = fulfillEPIN($epin, $recipient, $extra_id);

    // Clean up session
    mysqli_query($connection_server, "DELETE FROM sas_ussd_sessions WHERE vendor_id='$vendor_id' AND session_id='$session_id'");

    if ($result['status'] == 'success') {
        // Apply per-call charge if configured
        $per_call_charge = isset($vendor['ussd_per_call_charge']) ? (float)$vendor['ussd_per_call_charge'] : 0.00;
        if ($per_call_charge > 0) {
            // Retrieve card owner
            $epin_esc = mysqli_real_escape_string($connection_server, $epin);
            $get_card = mysqli_query($connection_server, "SELECT c.*, u.username FROM sas_databundle_cards c JOIN sas_users u ON c.user_id = u.id WHERE c.vendor_id='$vendor_id' AND c.epin='$epin_esc' LIMIT 1");
            $card = mysqli_fetch_assoc($get_card);
            if ($card) {
                $ref = "USSD-" . substr(str_shuffle("12345678901234567890"), 0, 15);
                $desc = "USSD Redemption Call Charge for EPIN: " . $epin;
                chargeOtherUser($card['username'], 'debit', $epin, 'USSD Call Fee', $ref, '', $per_call_charge, $per_call_charge, $desc, 'USSD', $_SERVER['HTTP_HOST'] ?? 'USSD', 1);
            }
        }

        $success_msg = "Recharge Successful.\n" . $result['message'];
        if ($extra_id !== "" && isset($result['token'])) {
            $success_msg .= "\nToken: " . $result['token'];
        }
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => $success_msg
        ];
    } else {
        return [
            "session_operation" => "end",
            "session_type" => 4,
            "session_id" => $session_id,
            "session_msg" => "Failed: " . $result['message']
        ];
    }
}
?>
