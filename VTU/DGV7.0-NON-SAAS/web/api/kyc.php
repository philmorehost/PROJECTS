<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once("../../func/bc-connect.php");
// Shared KYC helpers (bc_kyc_checks_pending / bc_kyc_check_label) live in bc-security.php, which
// bc-connect.php does not pull in by itself. Without this the status/upload replies fatal on an
// undefined function.
include_once(__DIR__ . "/../../func/bc-security.php");

$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server,
    "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$select_vendor_table) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found"]);
    exit;
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['api_key'] ?? '')));
if (empty($api_key)) {
    echo json_encode(["status" => "failed", "desc" => "Missing API key"]);
    exit;
}

$user_q = mysqli_query($connection_server,
    "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' AND status=1 LIMIT 1");
$user = mysqli_fetch_assoc($user_q);
if (!$user) {
    echo json_encode(["status" => "failed", "desc" => "Unauthorized"]);
    exit;
}

$action = trim(strip_tags($_POST['action'] ?? ''));

// The checks this vendor requires. Used to tell the app what is still outstanding after a submission
// and to keep the app, the website and both review consoles answering the same question.
// The checks this vendor requires. Read through the shared helper: a plain SELECT appended one entry
// per row, so duplicate settings rows told the app to collect the same document several times over.
$enabled_checks = bc_kyc_enabled_checks($connection_server, $vendor_id);

// Return current KYC status
if ($action === 'status') {
    $kyc_names = [0 => "Unverified", 1 => "Under Review", 2 => "Verified", 3 => "Rejected"];
    
    // Check if VoveID is enabled for this vendor
    $voveid_enabled = false;
    $voveid_q = mysqli_query($connection_server, "SELECT option_value FROM sas_vendor_settings WHERE vendor_id='$vendor_id' AND option_name='voveid_enabled' LIMIT 1");
    if ($voveid_q && $r = mysqli_fetch_assoc($voveid_q)) {
        $voveid_enabled = (int)$r['option_value'] === 1;
    }
    
    echo json_encode([
        "status"      => "success",
        "kyc_status"  => (int)$user['kyc_status'],
        "kyc_name"    => $kyc_names[$user['kyc_status']] ?? "Unknown",
        "kyc_verified"=> ($user['kyc_status'] == 2) ? "Yes" : "No",
        "bvn_set"     => !empty($user['bvn']) ? "Yes" : "No",
        "nin_set"     => !empty($user['nin']) ? "Yes" : "No",
        "voveid_enabled" => $voveid_enabled ? "Yes" : "No",
        "voveid_status" => $user['voveid_status'] ?? 'unverified',
        // Manual (non-API) KYC: what the reviewer decided, why, and what is still outstanding, so the
        // app can show the same information the website does instead of just "Unverified".
        "kyc_reject_reason" => (string)($user['kyc_reject_reason'] ?? ''),
        "submitted_at"      => (string)($user['kyc_submitted_at'] ?? ''),
        "has_documents"     => (empty($user['govt_id_card']) && empty($user['kyc_face_image'])) ? "No" : "Yes",
        "still_needed"      => array_map('bc_kyc_check_label', bc_kyc_checks_pending($user, $enabled_checks)),
    ]);
    exit;
}

// Create VoveID session for mobile app
if ($action === 'voveid_session') {
    // Check if VoveID is enabled for this vendor
    $voveid_enabled = false;
    $voveid_q = mysqli_query($connection_server, "SELECT option_value FROM sas_vendor_settings WHERE vendor_id='$vendor_id' AND option_name='voveid_enabled' LIMIT 1");
    if ($voveid_q && $r = mysqli_fetch_assoc($voveid_q)) {
        $voveid_enabled = (int)$r['option_value'] === 1;
    }
    
    if (!$voveid_enabled) {
        echo json_encode(["status" => "failed", "desc" => "VoveID KYC is not enabled for this vendor"]);
        exit;
    }
    
    $user_id = (int)$user['id'];
    $refId = (string)$user['id'];
    
    // Check if user already has a VoveID ref_id stored
    $refId_q = mysqli_query($connection_server, "SELECT voveid_ref_id FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
    if ($refId_q && $r = mysqli_fetch_assoc($refId_q)) {
        if (!empty($r['voveid_ref_id'])) {
            $refId = $r['voveid_ref_id'];
        }
    }
    
    // Include VoveID client
    require_once __DIR__ . "/../../func/voveid-client.php";
    
    // Create VoveID session
    $result = voveid_create_session($vendor_id, $user_id, $refId);
    
    if ($result['success'] && isset($result['data']['token'])) {
        $sessionToken = $result['data']['token'];
        $sessionId = $result['data']['sessionId'] ?? '';
        $flowId = $result['data']['flowId'] ?? '';
        
        // Store session in database
        $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes
        $refIdEsc = mysqli_real_escape_string($connection_server, $refId);
        $sessionTokenEsc = mysqli_real_escape_string($connection_server, $sessionToken);
        $sessionIdEsc = mysqli_real_escape_string($connection_server, $sessionId);
        $flowIdEsc = mysqli_real_escape_string($connection_server, $flowId ?? '');
        
        mysqli_query($connection_server, "
            INSERT INTO sas_voveid_sessions (vendor_id, user_id, ref_id, session_token, session_id, flow_id, status, expires_at)
            VALUES ('$vendor_id', '$user_id', '$refIdEsc', '$sessionTokenEsc', '$sessionIdEsc', '$flowIdEsc', 'created', '$expiresAt')
            ON DUPLICATE KEY UPDATE 
                session_token='$sessionTokenEsc', 
                session_id='$sessionIdEsc', 
                flow_id='$flowIdEsc', 
                status='created', 
                expires_at='$expiresAt',
                updated_at=NOW()
        ");
        
        // Update user's voveid_ref_id if not set
        $refIdCheck = mysqli_query($connection_server, "SELECT voveid_ref_id FROM sas_users WHERE id='$user_id' AND vendor_id='$vendor_id' LIMIT 1");
        if ($refIdCheck && $r = mysqli_fetch_assoc($refIdCheck)) {
            if (empty($r['voveid_ref_id'])) {
                $refIdEsc = mysqli_real_escape_string($connection_server, $refId);
                mysqli_query($connection_server, "UPDATE sas_users SET voveid_ref_id='$refIdEsc' WHERE id='$user_id' AND vendor_id='$vendor_id'");
            }
        }
        
        echo json_encode([
            "status" => "success",
            "token" => $sessionToken,
            "session_id" => $sessionId,
            "ref_id" => $refId,
            "expires_at" => $expiresAt,
        ]);
    } else {
        echo json_encode([
            "status" => "failed",
            "desc" => $result['error'] ?? 'Failed to create VoveID session',
        ]);
    }
    exit;
}

// Submit BVN or NIN
if ($action === 'submit_bvn_nin') {
    $type  = ($_POST['type'] ?? '') === 'nin' ? 'nin' : 'bvn';
    $value = preg_replace('/[^0-9]/', '', (string)($_POST['value'] ?? ''));
    if (strlen($value) < 10) {
        echo json_encode(["status" => "failed", "desc" => "Invalid $type format"]);
        exit;
    }

    $uid = (int)$user['id'];
    $value_esc = mysqli_real_escape_string($connection_server, $value);

    // Verify with the vendor's provider and let it approve the account when it can prove everything
    // this vendor requires (VoveID does the same through its webhook). Manual-upload checks stay
    // manual: they keep the submission in the review queue either way.
    $verification = verifyBvnNin($value, $type, '', '', $user['firstname'] ?? '', $user['lastname'] ?? '', $vendor_id);
    $outcome = bc_kyc_provider_verify($connection_server, $vendor_id, $uid, $type, $value, $verification, 'KYC-' . strtoupper($type) . '-' . $uid);

    if ($outcome['status'] === 'verified') {
        echo json_encode([
            "status"       => "success",
            "desc"         => $outcome['message'],
            "kyc_status"   => $outcome['kyc_status'],
            "auto_approved"=> $outcome['auto_approved'],
            "still_needed" => array_map('bc_kyc_check_label', $outcome['still_needed']),
        ]);
        exit;
    }

    // Not verified: keep the number but mark it unverified so nothing downstream treats it as checked
    // identity, and leave the decision to the reviewer.
    mysqli_query($connection_server,
        "UPDATE sas_users SET $type='$value_esc', kyc_api_verified='0', kyc_provider=NULL, kyc_provider_ref=NULL, kyc_api_verified_at=NULL WHERE id='$uid'");
    echo json_encode(["status" => "failed", "desc" => $outcome['message'], "unverified_saved" => true]);
    exit;
}

// Upload document / selfie (multipart)
if ($action === 'upload_document') {
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kyc/";
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

    // Private evidence: deny direct HTTP access, serve only through the authenticated viewers.
    $htaccess_file = $upload_dir . '.htaccess';
    if (is_dir($upload_dir) && !file_exists($htaccess_file)) {
        @file_put_contents($htaccess_file, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    $user_id = (int)$user['id'];
    $updates = [];
    $uploaded = [];

    // input name => [column, allowed extensions, max MB]. Same contract as the website flow, except the
    // app sends camera images for the ID and never a PDF for the selfie.
    $kinds = [
        'govt_id'          => ['govt_id_card',     ['jpg', 'jpeg', 'png', 'webp'], 8],
        'selfie'           => ['kyc_face_image',   ['jpg', 'jpeg', 'png', 'webp'], 8],
        'proof_of_address' => ['proof_of_address', ['jpg', 'jpeg', 'png', 'webp'], 8],
        'liveliness_video' => ['liveliness_video', ['mp4', 'mov', 'webm', 'mkv'], 25],
    ];

    foreach ($kinds as $input_name => $spec) {
        list($db_col, $exts, $max_mb) = $spec;
        if (empty($_FILES[$input_name]['name'])) continue;
        if (($_FILES[$input_name]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = $_FILES[$input_name]['tmp_name'];
        if (!is_uploaded_file($tmp)) continue;
        if (filesize($tmp) > $max_mb * 1024 * 1024) continue;

        $ext = strtolower(pathinfo($_FILES[$input_name]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        // Images must really be images - the extension comes from the client.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($tmp) === false) continue;

        $filename = "kyc_{$user_id}_{$input_name}_" . bin2hex(random_bytes(6)) . ".$ext";
        if (move_uploaded_file($tmp, $upload_dir . $filename)) {
            $fn_esc = mysqli_real_escape_string($connection_server, $filename);
            $updates[] = "$db_col='$fn_esc'";
            $uploaded[] = $input_name;
        }
    }

    if (!empty($updates)) {
        $doc_type = (string)($_POST['doc_type'] ?? '');
        if (in_array($doc_type, ['BVN', 'NIN', 'Passport', "Driver's Licence", "Voter's Card", 'National ID'], true)) {
            $updates[] = "kyc_id_type='" . mysqli_real_escape_string($connection_server, $doc_type) . "'";
        }
        if (in_array('govt_id', $uploaded, true))          $updates[] = "kyc_id_ok=1";
        if (in_array('selfie', $uploaded, true))           $updates[] = "kyc_picture_ok=1";
        if (in_array('liveliness_video', $uploaded, true)) $updates[] = "kyc_video_ok=1";
        if (in_array('proof_of_address', $uploaded, true)) $updates[] = "kyc_address_ok=1";
        $updates[] = "kyc_status=1";           // Pending review
        $updates[] = "kyc_submitted_at=NOW()"; // submission clock, not the account's reg_date
        $updates[] = "kyc_reject_reason=NULL"; // a new submission clears the old rejection
        mysqli_query($connection_server,
            "UPDATE sas_users SET " . implode(", ", $updates) . " WHERE id='$user_id'");

        $q_after = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
        $after = $q_after ? (mysqli_fetch_assoc($q_after) ?: []) : [];
        $still = $enabled_checks ? bc_kyc_checks_pending($after, $enabled_checks) : [];

        echo json_encode([
            "status" => "success",
            "desc"   => "Documents submitted for review",
            "still_needed" => array_map('bc_kyc_check_label', $still),
        ]);
    } else {
        echo json_encode(["status" => "failed", "desc" => "No valid documents received"]);
    }
    exit;
}

echo json_encode(["status" => "failed", "desc" => "Unknown action"]);
