<?php
require_once __DIR__ . "/../../func/bc-live-capture.php";
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With, X-App-Source");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "failed", "desc" => "Method not allowed"]);
    exit;
}

include_once("../../func/bc-connect.php");
// Shared KYC helpers (bc_kyc_checks_pending / bc_kyc_check_label) live in bc-security.php, which
// bc-connect.php does not pull in by itself. Without this the status/upload replies fatal on an
// undefined function.
include_once(__DIR__ . "/../../func/bc-security.php");
// AI KYC Compliance Engine + Pre-Upload Antivirus Scanner
include_once(__DIR__ . "/../../func/bc-kyc-ai-analyzer.php");
include_once(__DIR__ . "/../../func/bc-kyc-av-scanner.php");

$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server,
    "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$select_vendor_table) {
    echo json_encode(["status" => "failed", "desc" => "Vendor not found"]);
    exit;
}

$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($_POST['api_key'] ?? '')), $vendor_id));
if (empty($api_key)) {
    echo json_encode(["status" => "failed", "desc" => "Missing API key"]);
    exit;
}

$user_q = mysqli_query($connection_server,
    "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' AND status=1 AND (is_blocked IS NULL OR is_blocked=0) LIMIT 1");
$user = $user_q ? mysqli_fetch_assoc($user_q) : null;
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

        echo json_encode([
            "status"      => "success",
            "kyc_status"  => (int)$user['kyc_status'],
            "kyc_name"    => $kyc_names[$user['kyc_status']] ?? "Unknown",
            "kyc_verified"=> ($user['kyc_status'] == 2) ? "Yes" : "No",
            "bvn_set"     => !empty($user['bvn']) ? "Yes" : "No",
            "nin_set"     => !empty($user['nin']) ? "Yes" : "No",
        // Manual (non-API) KYC: what the reviewer decided, why, and what is still outstanding, so the
        // app can show the same information the website does instead of just "Unverified".
        "kyc_reject_reason" => (string)($user['kyc_reject_reason'] ?? ''),
        "submitted_at"      => (string)($user['kyc_submitted_at'] ?? ''),
        "has_documents"     => (empty($user['govt_id_card']) && empty($user['kyc_face_image'])) ? "No" : "Yes",
        "still_needed"      => array_map('bc_kyc_check_label', bc_kyc_checks_pending($user, $enabled_checks)),
    ]);
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
    // this vendor requires. Manual-upload checks stay
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
        'selfie'           => ['kyc_face_image',   ['jpg'], 0.5],
        'proof_of_address' => ['proof_of_address', ['jpg', 'jpeg', 'png', 'webp'], 8],
        'liveliness_video' => ['liveliness_video', ['mp4', 'webm'], 3],
    ];

    if (!empty($_FILES['liveliness_video']['name'])) {
        $v_err = $_FILES['liveliness_video']['error'] ?? UPLOAD_ERR_NO_FILE;
        $v_tmp = $_FILES['liveliness_video']['tmp_name'] ?? '';
        if ($v_err === UPLOAD_ERR_INI_SIZE || $v_err === UPLOAD_ERR_FORM_SIZE) {
            http_response_code(422);echo json_encode(['status'=>'failed','code'=>'VIDEO_TOO_LARGE','desc'=>'Liveliness video exceeds the server upload limit (' . ini_get('upload_max_filesize') . '). Record a shorter video (max 3 MB).']);exit;
        }
        if ($v_err !== UPLOAD_ERR_OK || !is_uploaded_file($v_tmp) || !bc_live_media_prepare($v_tmp, 'liveliness_video')) {
            $v_detail = function_exists('bc_live_media_last_error') ? bc_live_media_last_error() : '';
            http_response_code(422);echo json_encode(['status'=>'failed','code'=>'VIDEO_EVIDENCE_INVALID','desc'=>'Video could not be validated.' . ($v_detail !== '' ? ' ' . $v_detail : '') . ' Record a clear 1–6 second video with audio, maximum 3 MB (MP4/WebM). If retry fails, contact support; do not assume it was saved.']);exit;
        }
    }
    $av_errors = [];
    $av_scan_reports = [];
    foreach ($kinds as $input_name => $spec) {
        list($db_col, $exts, $max_mb) = $spec;
        if (empty($_FILES[$input_name]['name'])) continue;
        if (($_FILES[$input_name]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = $_FILES[$input_name]['tmp_name'];
        if (!is_uploaded_file($tmp)) continue;
        if ($input_name === 'selfie' && !bc_live_media_prepare($tmp, $input_name)) continue;
        if (filesize($tmp) > $max_mb * 1024 * 1024) continue;

        $ext = strtolower(pathinfo($_FILES[$input_name]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) continue;
        // Images must really be images - the extension comes from the client.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($tmp) === false) continue;

        // Pre-upload Antivirus, Trojan & Blank-File Scanner BEFORE moving into storage
        $av_scan = bc_kyc_av_scan_upload($tmp, (string)$_FILES[$input_name]['name'], $ext, [
            'connection' => $connection_server,
            'vendor_id'  => $vendor_id,
            'user_id'    => $user_id,
            'doc_kind'   => $input_name,
        ]);
        if (empty($av_scan['allowed'])) {
            $av_errors[] = $input_name . ': ' . ($av_scan['reason'] ?? 'Blocked by security scanner');
            continue;
        }

        $filename = "kyc_{$user_id}_{$input_name}_" . bin2hex(random_bytes(6)) . ".$ext";
        if (move_uploaded_file($tmp, $upload_dir . $filename)) {
            $fn_esc = mysqli_real_escape_string($connection_server, $filename);
            $updates[] = "$db_col='$fn_esc'";
            if ($input_name === 'selfie') {
                $updates[] = "liveliness_picture='$fn_esc'";
            }
            $uploaded[] = $input_name;
            $av_scan_reports[$input_name] = [
                'scan_mode'      => $av_scan['scan_mode'] ?? 'standard_antivirus',
                'security_score' => (int)($av_scan['security_score'] ?? 95),
                'reason'         => (string)($av_scan['reason'] ?? 'Clean'),
            ];
        }
    }

    if (!empty($av_errors) && empty($updates)) {
        http_response_code(422);
        echo json_encode([
            "status" => "failed",
            "code"   => "AV_SCAN_REJECTED",
            "desc"   => implode(' | ', $av_errors),
        ]);
        exit;
    }

    if (!empty($updates)) {
        // Replaced evidence is a new review, never grandfather an earlier approval.
        $updates[] = "kyc_status=0";
        $doc_type = (string)($_POST['doc_type'] ?? '');
        if (in_array($doc_type, ['BVN', 'NIN', 'Passport', "Driver's Licence", "Voter's Card", 'National ID'], true)) {
            $updates[] = "kyc_id_type='" . mysqli_real_escape_string($connection_server, $doc_type) . "'";
        }
        if (in_array('govt_id', $uploaded, true))          $updates[] = "kyc_id_ok=1";
        if (in_array('selfie', $uploaded, true))           $updates[] = "kyc_picture_ok=1";
        if (in_array('liveliness_video', $uploaded, true)) $updates[] = "kyc_video_ok=1";
        if (in_array('proof_of_address', $uploaded, true)) $updates[] = "kyc_address_ok=1";
        $updates[] = "kyc_reject_reason=NULL"; // a new submission clears the old rejection
        mysqli_query($connection_server,
            "UPDATE sas_users SET " . implode(", ", $updates) . " WHERE id='$user_id'");

        $q_after = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
        $after = $q_after ? (mysqli_fetch_assoc($q_after) ?: []) : [];
        $still = $enabled_checks ? bc_kyc_checks_pending($after, $enabled_checks) : [];

        // §33: enter the admin review queue only when EVERY required item
        // has been provided; partial uploads are saved but stay unverified.
        $in_review = false;
        if (empty($still) && (int)($after['kyc_status'] ?? 0) !== 2) {
            mysqli_query($connection_server, "UPDATE sas_users SET kyc_status=1, kyc_submitted_at=NOW() WHERE id='$user_id'");
            $in_review = true;
        }

        // Auto-trigger AI compliance review & per-doc ratings for admin when AI is enabled
        $ai_summary = null;
        $ai_gate = bc_kyc_is_ai_enabled($connection_server, (int)$vendor_id);
        if (!empty($ai_gate['enabled']) && $after) {
            $analysis = bc_kyc_ai_analyze_all($after, $enabled_checks);
            $analysis['username'] = $after['username'] ?? ($user['username'] ?? '');
            if (!empty($av_scan_reports)) {
                $analysis['decision']['av_scans'] = $av_scan_reports;
            }
            $store_result = bc_kyc_ai_store_result($connection_server, $user_id, $vendor_id, $analysis, false);
            $ai_summary = [
                'status'         => $store_result['decision']['status'] ?? 'review',
                'confidence'     => (int)($store_result['decision']['confidence'] ?? 0),
                'overall_rating' => (int)($store_result['decision']['overall_rating'] ?? 0),
                'overall_grade'  => (string)($store_result['decision']['overall_grade'] ?? 'C'),
                'reason'         => (string)($store_result['decision']['reason'] ?? ''),
                'ratings'        => $store_result['decision']['ratings'] ?? [],
            ];
        }

        echo json_encode([
            "status" => "success",
            "desc"   => $in_review ? "Documents submitted and waiting for admin review" : "Documents saved",
            "in_review" => $in_review,
            "accepted_media" => $uploaded,
            "still_needed" => array_map('bc_kyc_check_label', $still),
            "av_scans" => $av_scan_reports,
            "av_warnings" => $av_errors,
            "ai_analysis" => $ai_summary,
        ]);
    } else {
        echo json_encode(["status" => "failed", "desc" => "No valid documents received"]);
    }
    exit;
}

// ─── AI KYC Compliance Analysis ───────────────────────────────────────────────────────────────────

if ($action === 'ai_analyze_documents') {
    $ai_gate = bc_kyc_is_ai_enabled($connection_server, (int)$vendor_id);
    if (empty($ai_gate['enabled'])) {
        echo json_encode(["status" => "failed", "desc" => $ai_gate['reason'] ?? "AI KYC analysis is not enabled for this vendor."]);
        exit;
    }
    $user_id = (int)$user['id'];
    $q_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
    $user_row = $q_user ? (mysqli_fetch_assoc($q_user) ?: $user) : $user;
    $has_docs = !empty($user_row['govt_id_card']) || !empty($user_row['kyc_face_image'])
        || !empty($user_row['proof_of_address']) || !empty($user_row['liveliness_video']);
    if (!$has_docs) {
        echo json_encode(["status" => "failed", "desc" => "No documents uploaded to analyze."]);
        exit;
    }
    $analysis = bc_kyc_ai_analyze_all($user_row, $enabled_checks);
    $analysis['username'] = $user_row['username'];
    // NON-SAAS: no billing, pays directly
    $billing = bc_kyc_ai_billing_cost($analysis['total_tokens'] ?? 0, $vendor_row);
    $billing['billed'] = false; // NON-SAAS pays directly, no billing
    $analysis['billing'] = $billing;
    $auto_apply = false; // §33: AI advises the human reviewer; it never approves on its own.
    $store_result = bc_kyc_ai_store_result($connection_server, $user_id, $vendor_id, $analysis, $auto_apply);
    echo json_encode([
        "status"        => "success",
        "desc"          => $store_result['decision']['reason'] ?? "AI analysis complete",
        "ai_status"     => $store_result['decision']['status'] ?? 'error',
        "kyc_status"    => $store_result['decision']['kyc_status'] ?? 0,
        "confidence"    => $store_result['decision']['confidence'] ?? 0,
        "auto_approved" => $store_result['applied'],
        "reasons"       => $store_result['decision']['reasons'] ?? [],
        "errors"        => $analysis['errors'] ?? [],
        "results"       => $analysis['results'] ?? [],
        "duration_ms"   => $analysis['total_duration_ms'] ?? 0,
    ]);
    exit;
}

if ($action === 'ai_get_status') {
    $decision = bc_kyc_ai_get_decision($connection_server, (int)$user['id']);
    if ($decision === null) {
        echo json_encode(["status" => "success", "has_analysis" => false, "desc" => "No AI analysis has been performed yet."]);
        exit;
    }
    echo json_encode([
        "status"        => "success",
        "has_analysis"  => true,
        "ai_status"     => $decision['status'],
        "reason"        => $decision['reason'],
        "confidence"    => $decision['confidence'],
        "kyc_status"    => $decision['decision']['kyc_status'] ?? 0,
        "reasons"       => $decision['decision']['reasons'] ?? [],
        "results"       => $decision['results'],
        "analyzed_at"   => $decision['analyzed_at'],
        "tokens_used"   => $decision['tokens_used'],
    ]);
    exit;
}

echo json_encode(["status" => "failed", "desc" => "Unknown action"]);
