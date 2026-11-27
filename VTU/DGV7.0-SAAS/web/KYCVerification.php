<?php
require_once __DIR__ . "/../func/bc-live-capture.php";
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");

$username = $get_logged_user_details['username'];
$vid = $get_logged_user_details['vendor_id'];

// Get Vendor Specific KYC Settings. Read through the shared helper so this page, the mobile app and
// both review consoles cannot disagree about which checks are on (and so duplicate settings rows
// cannot turn into duplicate requirements here).
$kyc_settings = bc_kyc_effective_statuses($connection_server, $vid);

// The checks this vendor actually requires - shared mapping in func/bc-security.php.
$enabled_checks = bc_kyc_enabled_checks($connection_server, $vid);
// The checks a user can satisfy by uploading something from this page.
$manual_checks = array_values(array_intersect($enabled_checks, ['govt_id', 'liveliness_picture', 'liveliness_video', 'proof_of_address']));

// This user's own KYC row, read fresh: the session copy is not guaranteed to carry the KYC columns.
$kyc_self = [];
$q_self = mysqli_query($connection_server, "SELECT kyc_status, kyc_reject_reason, kyc_submitted_at, kyc_reviewed_at,
        govt_id_card, kyc_face_image, proof_of_address, liveliness_video, liveliness_picture, kyc_id_type
    FROM sas_users WHERE id='" . (int)$get_logged_user_details['id'] . "' LIMIT 1");
if (!$q_self) {
    // Safe fallback if the timeline columns are not migrated on this database yet.
    $q_self = mysqli_query($connection_server, "SELECT kyc_status, kyc_reject_reason, govt_id_card, kyc_face_image,
            proof_of_address, liveliness_video, liveliness_picture, kyc_id_type
        FROM sas_users WHERE id='" . (int)$get_logged_user_details['id'] . "' LIMIT 1");
}
if ($q_self) $kyc_self = mysqli_fetch_assoc($q_self) ?: [];
$kyc_status = (int)($kyc_self['kyc_status'] ?? $get_logged_user_details['kyc_status']);
$kyc_still_needed = $enabled_checks ? bc_kyc_checks_pending($kyc_self, $enabled_checks) : [];

$is_kyc_enabled = isKYCEnforced($vid);

// AI KYC Compliance Engine + Pre-Upload Antivirus Scanner
include_once(__DIR__ . "/../func/bc-kyc-ai-analyzer.php");
include_once(__DIR__ . "/../func/bc-kyc-av-scanner.php");

// Handle Submissions
if (isset($_POST['submit_bvn_nin'])) {
    $type = ($_POST['type'] == 'nin') ? 'nin' : 'bvn'; // Whitelist to prevent SQL injection
    $value = preg_replace('/[^0-9]/', '', (string)($_POST['value'] ?? ''));
    $uid = (int)$get_logged_user_details['id'];
    $value_esc = mysqli_real_escape_string($connection_server, $value);

    if (strlen($value) < 10) {
        $_SESSION['product_purchase_response'] = "Error: Invalid $type format.";
        header("Location: KYCVerification.php");
        exit();
    }

    // Ask the vendor's identity provider (Dojah / QoreID / Smile ID / Monnify) whether this number
    // belongs to THIS account holder. verifyBvnNin() does the name match for us and only returns
    // success when the provider's record matches the name on the account.
    $verification = verifyBvnNin($value, $type, '', '', $get_logged_user_details['firstname'], $get_logged_user_details['lastname'], $vid);
    $outcome = bc_kyc_provider_verify($connection_server, $vid, $uid, $type, $value, $verification, 'KYC-' . strtoupper($type) . '-' . $uid);

    if ($outcome['status'] === 'verified') {
        $_SESSION['product_purchase_response'] = "Success: " . $outcome['message'];
    } else {
        // Not verified. The number is still kept (so the reviewer sees what the user typed) but it is
        // explicitly marked as UNVERIFIED - it must never look like a checked identity, and a person
        // decides what to do with it.
        mysqli_query($connection_server, "UPDATE sas_users SET $type='$value_esc', kyc_api_verified='0', kyc_provider=NULL, kyc_provider_ref=NULL, kyc_api_verified_at=NULL WHERE id='$uid'");
        $_SESSION['product_purchase_response'] = "Notice: " . strtoupper($type) . " saved, but it could not be verified with the identity provider (" . $outcome['message'] . "). Your submission will be reviewed manually.";
    }
    header("Location: KYCVerification.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['product_purchase_response'] = "Error: Your upload exceeded the server's maximum request size (" . ini_get('post_max_size') . "). Please upload smaller files or submit one document at a time.";
    header("Location: KYCVerification.php");
    exit();
}

if (isset($_POST['submit_media'])) {
    $manual_still_needed = array_values(array_intersect($kyc_still_needed, ['govt_id', 'liveliness_picture', 'liveliness_video', 'proof_of_address']));
    $check_to_field = [
        'govt_id'            => 'govt_id',
        'liveliness_picture' => 'selfie',
        'liveliness_video'   => 'liveliness_video',
        'proof_of_address'   => 'proof_of_address',
    ];
    $skipped_checks = [];
    foreach ($manual_still_needed as $req_chk) {
        $fld = $check_to_field[$req_chk] ?? '';
        if ($fld !== '' && empty($_FILES[$fld]['name'])) {
            $skipped_checks[] = $req_chk;
        }
    }
    if (!empty($skipped_checks)) {
        $skipped_names = array_map('bc_kyc_check_label', $skipped_checks);
        $_SESSION['product_purchase_response'] = "Error: All activated KYC checks are compulsory. Please complete and submit: " . implode(', ', $skipped_names) . ".";
        header("Location: KYCVerification.php");
        exit();
    }

    $upload_dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . "/uploads/kyc/";
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

    // Uploaded IDs and live photos are private evidence. Deny direct HTTP access to the directory and
    // serve them only through the authenticated viewers (here for the owner, bc-admin/KYCManagement.php
    // for the vendor). Ignored where AllowOverride is off, so the viewers work regardless.
    $htaccess_file = $upload_dir . '.htaccess';
    if (is_dir($upload_dir) && !file_exists($htaccess_file)) {
        @file_put_contents($htaccess_file, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    $user_id = (int)$get_logged_user_details['id'];
    $updates = [];
    $uploaded = [];
    $upload_errors = [];
    $selfie_filename = '';

    // input name => [column, allowed extensions, max MB]. The selfie is images only: it is meant to be
    // taken with the camera, and a PDF cannot be one.
    $kinds = [
        'govt_id'          => ['govt_id_card',     ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 8],
        'selfie'           => ['kyc_face_image',   ['jpg', 'jpeg'], 0.5],
        'proof_of_address' => ['proof_of_address', ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 8],
        'liveliness_video' => ['liveliness_video', ['mp4', 'webm'], 3],
    ];
    $field_labels = [
        'govt_id'          => 'Government ID',
        'selfie'           => 'Live photo',
        'proof_of_address' => 'Proof of address',
        'liveliness_video' => 'Liveliness video',
    ];

    if (!empty($_FILES['liveliness_video']['name'])) {
        $v_err = $_FILES['liveliness_video']['error'] ?? UPLOAD_ERR_NO_FILE;
        $v_tmp = $_FILES['liveliness_video']['tmp_name'] ?? '';
        if ($v_err === UPLOAD_ERR_INI_SIZE || $v_err === UPLOAD_ERR_FORM_SIZE) {
            $_SESSION['product_purchase_status'] = 'failed';
            $_SESSION['product_purchase_response'] = 'Error: Liveliness video exceeds the server upload limit (' . ini_get('upload_max_filesize') . '). Record a shorter video (max 3 MB).';
            header('Location: KYCVerification.php');
            exit;
        }
        if ($v_err !== UPLOAD_ERR_OK || !is_uploaded_file($v_tmp) || !bc_live_media_prepare($v_tmp, 'liveliness_video')) {
            $v_detail = function_exists('bc_live_media_last_error') ? bc_live_media_last_error() : '';
            $_SESSION['product_purchase_status'] = 'failed';
            $_SESSION['product_purchase_response'] = 'Video could not be validated.' . ($v_detail !== '' ? ' ' . $v_detail : '') . ' Record a clear 1–6 second video with audio, maximum 3 MB (MP4/WebM). If retry fails, contact support; do not assume it was saved.';
            header('Location: KYCVerification.php');
            exit;
        }
    }
    foreach ($kinds as $input_name => $spec) {
        list($db_col, $exts, $max_mb) = $spec;
        $label = $field_labels[$input_name] ?? $input_name;
        if (empty($_FILES[$input_name]['name'])) continue;
        $err = $_FILES[$input_name]['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $upload_errors[] = "$label exceeds the server upload limit (" . ini_get('upload_max_filesize') . "). Please upload a smaller file.";
            } elseif ($err === UPLOAD_ERR_PARTIAL) {
                $upload_errors[] = "$label was only partially uploaded. Please try again.";
            } else {
                $upload_errors[] = "$label failed to upload (error code $err).";
            }
            continue;
        }
        $tmp = $_FILES[$input_name]['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            $upload_errors[] = "$label upload could not be verified.";
            continue;
        }
        if ($input_name === 'selfie' && !bc_live_media_prepare($tmp, $input_name)) {
            $upload_errors[] = "Live photo could not be validated (must be a live camera JPEG between 160px and 960px, max 512 KB).";
            continue;
        }
        if (filesize($tmp) > $max_mb * 1024 * 1024) {
            $upload_errors[] = "$label exceeds the {$max_mb} MB size limit.";
            continue;
        }

        $ext = strtolower(pathinfo($_FILES[$input_name]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) {
            $upload_errors[] = "$label has an unsupported file type (." . ($ext !== '' ? $ext : 'unknown') . "). Allowed: " . implode(', ', $exts) . ".";
            continue;
        }
        // The extension is attacker-controlled, so confirm an image really is one.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($tmp) === false) {
            $upload_errors[] = "$label is not a valid image file.";
            continue;
        }

        // Pre-upload Antivirus, Trojan & Blank-File Scanner (AI-assisted when enabled, else standard local scanner)
        $av_scan = bc_kyc_av_scan_upload($tmp, (string)$_FILES[$input_name]['name'], $ext, [
            'connection' => $connection_server,
            'vendor_id'  => $vid,
            'user_id'    => $user_id,
            'doc_kind'   => $input_name,
        ]);
        if (empty($av_scan['allowed'])) {
            $upload_errors[] = "$label blocked by security scanner: " . ($av_scan['reason'] ?? 'Invalid or suspicious file.');
            continue;
        }

        $filename = 'kyc_' . $user_id . '_' . $input_name . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (move_uploaded_file($tmp, $upload_dir . $filename)) {
            $fn_esc = mysqli_real_escape_string($connection_server, $filename);
            $updates[] = "$db_col = '$fn_esc'";
            $uploaded[] = $input_name;
            $av_scan_reports[$input_name] = [
                'scan_mode'      => $av_scan['scan_mode'] ?? 'standard_antivirus',
                'security_score' => (int)($av_scan['security_score'] ?? 95),
                'reason'         => (string)($av_scan['reason'] ?? 'Clean'),
            ];
            if ($input_name === 'selfie') $selfie_filename = $filename;
        } else {
            $upload_errors[] = "$label could not be saved on the server. Please contact support.";
        }
    }

    if (!empty($updates)) {
        // Replaced evidence is a new review, never grandfather an earlier approval.
        $updates[] = "kyc_status=0";
        // What the user says the document is (passport, licence, ...): the manual flow must accept IDs
        // that carry no BVN/NIN number.
        $doc_type = (string)($_POST['doc_type'] ?? '');
        if (in_array($doc_type, ['BVN', 'NIN', 'Passport', "Driver's Licence", "Voter's Card", 'National ID'], true)) {
            $updates[] = "kyc_id_type = '" . mysqli_real_escape_string($connection_server, $doc_type) . "'";
        }
        // Flag what has now been provided, so the vendor's checklist and any gating stay accurate.
        if (in_array('govt_id', $uploaded, true))         $updates[] = "kyc_id_ok = 1";
        if (in_array('selfie', $uploaded, true))          $updates[] = "kyc_picture_ok = 1";
        if (in_array('liveliness_video', $uploaded, true)) $updates[] = "kyc_video_ok = 1";
        if (in_array('proof_of_address', $uploaded, true)) $updates[] = "kyc_address_ok = 1";
        // A selfie captured live in the browser is mirrored into the purpose-built liveliness column, so
        // the reviewer can tell a live capture from an uploaded photo without guessing.
        if ($selfie_filename !== '') {
            $updates[] = "liveliness_picture = '" . mysqli_real_escape_string($connection_server, $selfie_filename) . "'";
        }
        $updates[] = "kyc_reject_reason = NULL"; // a new submission clears the previous rejection

        mysqli_query($connection_server, "UPDATE sas_users SET " . implode(", ", $updates) . " WHERE id='$user_id' AND vendor_id='$vid'");

        // §33: a submission only enters the admin review queue once EVERY
        // required item has been provided. Partial uploads are saved, the
        // account stays unverified, and the user is told what is missing.
        $q_after = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
        $after = $q_after ? (mysqli_fetch_assoc($q_after) ?: []) : [];
        $still = $enabled_checks ? bc_kyc_checks_pending($after, $enabled_checks) : [];
        if (empty($still) && (int)($after['kyc_status'] ?? 0) !== 2) {
            mysqli_query($connection_server, "UPDATE sas_users SET kyc_status = 1, kyc_submitted_at = NOW() WHERE id='$user_id' AND vendor_id='$vid'");
            $msg = "Documents submitted and waiting for admin review. Every required item is now provided.";
        } elseif ((int)($after['kyc_status'] ?? 0) === 2) {
            $msg = "Documents updated. Your account remains verified.";
        } else {
            $still_names = array_map('bc_kyc_check_label', $still);
            $msg = "Documents saved. Still needed before your submission can be reviewed: " . implode(', ', $still_names) . ".";
        }
        if (!empty($upload_errors)) {
            $msg .= " Note: " . implode(' ', $upload_errors);
        }
        $_SESSION['product_purchase_response'] = $msg;

        // ─── Auto-trigger AI KYC analysis after document submission ────────────────────────────
        $ai_gate = bc_kyc_is_ai_enabled($connection_server, (int)$vid);
        if (!empty($updates) && !empty($ai_gate['enabled'])) {
            // Re-read the user row to get the latest document paths
            $q_reread = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
            $user_reread = $q_reread ? (mysqli_fetch_assoc($q_reread) ?: []) : [];
            if ($user_reread) {
                $analysis = bc_kyc_ai_analyze_all($user_reread, $enabled_checks);
                $analysis['username'] = $user_reread['username'];
                if (!empty($av_scan_reports)) {
                    $analysis['decision']['av_scans'] = $av_scan_reports;
                }
                $auto_apply = false; // §33: AI advises the human reviewer; it never approves on its own.
                $store_result = bc_kyc_ai_store_result($connection_server, $user_id, $vid, $analysis, $auto_apply);

                if ($store_result['applied']) {
                    $_SESSION['product_purchase_response'] = "🎉 AI has verified your documents and approved your KYC automatically! Confidence: " . ($store_result['decision']['confidence'] ?? 0) . "%.";
                } elseif (($store_result['decision']['status'] ?? '') === 'reject') {
                    $_SESSION['product_purchase_response'] = "⚠️ AI reviewed your documents and flagged issues for the admin report: " . ($store_result['decision']['reason'] ?? 'Please check your documents and resubmit.');
                } else {
                    $_SESSION['product_purchase_response'] = "📋 Your documents passed antivirus screening and were analyzed by our AI Compliance Engine. An admin will review the AI report shortly.";
                }
            }
        }
    } else {
        if (!empty($upload_errors)) {
            $_SESSION['product_purchase_status'] = 'failed';
            $_SESSION['product_purchase_response'] = "Error: " . implode(' ', $upload_errors);
        } else {
            $_SESSION['product_purchase_status'] = 'failed';
            $_SESSION['product_purchase_response'] = "Error: no file was selected or captured. Please attach a document or capture your live photo/video before submitting.";
        }
    }

    header("Location: KYCVerification.php");
    exit();
}

// ─── Manual AI analysis trigger ──────────────────────────────────────────────────────────────────
if (isset($_POST['ai_analyze'])) {
    $ai_gate = bc_kyc_is_ai_enabled($connection_server, (int)$vid);
    if (empty($ai_gate['enabled'])) {
        $_SESSION['product_purchase_response'] = $ai_gate['reason'] ?? "AI KYC analysis is not enabled for your vendor.";
        header("Location: KYCVerification.php");
        exit();
    }
    $user_id = (int)$get_logged_user_details['id'];
    $q_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
    $user_row = $q_user ? (mysqli_fetch_assoc($q_user) ?: []) : [];
    if (!$user_row) {
        $_SESSION['product_purchase_response'] = "Account not found.";
        header("Location: KYCVerification.php");
        exit();
    }
    $has_docs = !empty($user_row['govt_id_card']) || !empty($user_row['kyc_face_image'])
        || !empty($user_row['proof_of_address']) || !empty($user_row['liveliness_video']);
    if (!$has_docs) {
        $_SESSION['product_purchase_response'] = "Please upload your KYC documents first before running AI analysis.";
        header("Location: KYCVerification.php");
        exit();
    }
    $analysis = bc_kyc_ai_analyze_all($user_row, $enabled_checks);
    $analysis['username'] = $user_row['username'];
    $auto_apply = false; // §33: AI advises the human reviewer; it never approves on its own.
    $store_result = bc_kyc_ai_store_result($connection_server, $user_id, $vid, $analysis, $auto_apply);

    if ($store_result['applied']) {
        $_SESSION['product_purchase_response'] = "🎉 AI has verified your documents and approved your KYC! Confidence: " . ($store_result['decision']['confidence'] ?? 0) . "%. You now have full access to all services.";
    } elseif (($store_result['decision']['status'] ?? '') === 'reject') {
        $_SESSION['product_purchase_response'] = "❌ AI rejected your documents: " . ($store_result['decision']['reason'] ?? 'Please fix the issues and resubmit.');
    } else {
        $_SESSION['product_purchase_response'] = "📋 AI analysis complete. Confidence: " . ($store_result['decision']['confidence'] ?? 0) . "%. " . ($store_result['decision']['reason'] ?? 'Manual review recommended.');
    }
    header("Location: KYCVerification.php");
    exit();
}

// Private evidence viewer: the signed-in user, their own files only. Mirrors the vendor console viewer.
if (isset($_GET['doc'])) {
    $doc_map = ['id' => 'govt_id_card', 'selfie' => 'kyc_face_image', 'poa' => 'proof_of_address', 'video' => 'liveliness_video'];
    $kind = (string)$_GET['doc'];
    if (!isset($doc_map[$kind])) { http_response_code(400); exit('Unknown document'); }

    $col = $doc_map[$kind];
    $q_doc = mysqli_query($connection_server, "SELECT `$col` AS f FROM sas_users WHERE id='" . (int)$get_logged_user_details['id'] . "' LIMIT 1");
    $row_doc = $q_doc ? mysqli_fetch_assoc($q_doc) : null;
    $doc_file = trim($row_doc['f'] ?? '');
    if ($doc_file === '') { http_response_code(404); exit('No such document'); }

    $doc_name = basename($doc_file);
    $doc_base = false;
    $doc_path = false;
    foreach ([rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/uploads/kyc/', dirname(__DIR__) . '/uploads/kyc/'] as $dir_candidate) {
        if ($dir_candidate === '/uploads/kyc/') continue;
        $cand_base = realpath($dir_candidate);
        $cand_path = $cand_base ? realpath($cand_base . DIRECTORY_SEPARATOR . $doc_name) : false;
        if ($cand_base && $cand_path && strpos($cand_path, $cand_base) === 0 && is_file($cand_path)) {
            $doc_base = $cand_base;
            $doc_path = $cand_path;
            break;
        }
    }
    if (!$doc_base || !$doc_path) {
        http_response_code(404); exit('Document missing on disk');
    }

    $doc_mimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
    ];
    $doc_ext = strtolower(pathinfo($doc_path, PATHINFO_EXTENSION));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: ' . ($doc_mimes[$doc_ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($doc_path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="' . basename($doc_path) . '"');
    readfile($doc_path);
    exit();
}

?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Identity Verification (KYC) | " . ($get_all_site_details["site_title"]));
?>
    <style>
        .kyc-card { border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); transition: 0.3s; }
        .kyc-card:hover { transform: translateY(-5px); }
        .status-badge { font-size: 0.75rem; padding: 5px 12px; border-radius: 50px; font-weight: 700; }
    </style>
    <?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "IDENTITY VERIFICATION (KYC)", "crumb" => "Home / KYC")); ?>

    <section class="section">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card kyc-card p-4 text-center">
                    <div class="mb-3">
                        <?php if($kyc_status == 2): ?>
                            <i class="bi bi-patch-check-fill text-success display-1"></i>
                            <h4 class="fw-bold mt-2">Fully Verified</h4>
                            <p class="text-muted small">Your identity has been confirmed. You have unrestricted access to all services.</p>
                        <?php elseif($kyc_status == 1): ?>
                            <i class="bi bi-clock-history text-warning display-1"></i>
                            <h4 class="fw-bold mt-2">Under Review</h4>
                            <p class="text-muted small">Your documents are with a reviewer. Services that need verification unlock once they approve.</p>
                        <?php elseif($kyc_status == 3): ?>
                            <i class="bi bi-x-octagon-fill text-danger display-1"></i>
                            <h4 class="fw-bold mt-2">Not Accepted</h4>
                            <p class="text-muted small">Your last submission was rejected. Fix what is listed below and submit again.</p>
                        <?php else: ?>
                            <i class="bi bi-shield-lock text-primary display-1"></i>
                            <h4 class="fw-bold mt-2">Unverified</h4>
                            <p class="text-muted small">Please complete the required steps below to secure your account.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($kyc_status == 3 && !empty($kyc_self['kyc_reject_reason'])): ?>
                      <div class="alert alert-danger small text-start">
                        <strong>Reviewer said:</strong><br/>
                        <?php echo nl2br(htmlspecialchars($kyc_self['kyc_reject_reason'])); ?>
                      </div>
                    <?php endif; ?>

                    <?php if (!empty($kyc_still_needed)): ?>
                      <div class="alert alert-warning small text-start">
                        <strong>Still needed:</strong>
                        <ul class="mb-0 ps-3">
                          <?php foreach ($kyc_still_needed as $need): ?>
                            <li><?php echo htmlspecialchars(bc_kyc_check_label($need)); ?></li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    <?php endif; ?>

                    <?php
                      // Evidence the user has already sent, so they can see the same things the reviewer sees.
                      $self_docs = [
                        'id'     => ['label' => 'Government ID',    'file' => $kyc_self['govt_id_card'] ?? ''],
                        'selfie' => ['label' => 'Live photo',       'file' => $kyc_self['kyc_face_image'] ?? ''],
                        'poa'    => ['label' => 'Proof of address', 'file' => $kyc_self['proof_of_address'] ?? ''],
                        'video'  => ['label' => 'Liveliness video', 'file' => $kyc_self['liveliness_video'] ?? ''],
                      ];
                    ?>
                    <?php if (array_filter(array_column($self_docs, 'file'))): ?>
                      <div class="text-start mt-3">
                        <h6 class="fw-bold small mb-2">What you have submitted</h6>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                          <?php foreach ($self_docs as $kind => $doc): ?>
                            <?php if (trim((string)$doc['file']) === '') continue; ?>
                            <?php $self_ext = strtolower(pathinfo($doc['file'], PATHINFO_EXTENSION)); ?>
                            <?php $self_url = 'KYCVerification.php?doc=' . urlencode($kind); ?>
                            <?php if (in_array($self_ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)): ?>
                              <a href="<?php echo $self_url; ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($doc['label']); ?>">
                                <img src="<?php echo $self_url; ?>" alt="<?php echo htmlspecialchars($doc['label']); ?>"
                                     class="rounded-3 border" style="width:78px;height:78px;object-fit:cover;">
                              </a>
                            <?php else: ?>
                              <a href="<?php echo $self_url; ?>" target="_blank" rel="noopener" class="badge bg-light text-dark border text-decoration-none">
                                <?php echo htmlspecialchars($doc['label']); ?>
                              </a>
                            <?php endif; ?>
                          <?php endforeach; ?>
                        </div>
                        <?php if (!empty($kyc_self['kyc_submitted_at'])): ?>
                          <p class="small text-muted mt-2 mb-0">Submitted <?php echo date('M d, Y H:i', strtotime($kyc_self['kyc_submitted_at'])); ?>.</p>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-8">
                <?php if(!$is_kyc_enabled): ?>
                    <div class="alert alert-info border-0 rounded-4 shadow-sm p-4">
                        <h6 class="fw-bold"><i class="bi bi-info-circle me-2"></i>KYC is Optional</h6>
                        <p class="mb-0 small">The administrator has not enforced mandatory KYC. You can continue using services, but we recommend verifying for enhanced security.</p>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <!-- BVN/NIN Section -->
                    <?php if(($kyc_settings['bvn'] ?? 0) == 1 || ($kyc_settings['nin'] ?? 0) == 1): ?>
                    <div class="col-md-6">
                        <div class="card kyc-card h-100">
                            <div class="card-body p-4">
                                <h6 class="fw-bold mb-3"><i class="bi bi-fingerprint me-2 text-primary"></i>Basic Verification</h6>
                                <form method="post">
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Select ID Type</label>
                                        <select name="type" class="form-select rounded-3 shadow-sm">
                                            <?php if(($kyc_settings['bvn'] ?? 0) == 1): ?>
                                                <option value="bvn" <?php echo !empty($get_logged_user_details['bvn']) ? 'selected' : ''; ?>>Bank Verification Number (BVN)</option>
                                            <?php endif; ?>
                                            <?php if(($kyc_settings['nin'] ?? 0) == 1): ?>
                                                <option value="nin" <?php echo !empty($get_logged_user_details['nin']) ? 'selected' : ''; ?>>National Identity Number (NIN)</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <input type="text" name="value" class="form-control rounded-3 shadow-sm" placeholder="Enter 11-digit number" value="<?php echo $get_logged_user_details['bvn'] ?: $get_logged_user_details['nin']; ?>" required>
                                    </div>
                                    <button name="submit_bvn_nin" type="submit" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm">Update ID</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Manual document upload (non-API KYC) -->
                    <?php if (!empty($manual_checks)): ?>
                    <div class="col-12">
                        <div class="card kyc-card">
                            <div class="card-body p-4">
                                <h6 class="fw-bold mb-1"><i class="bi bi-camera me-2 text-primary"></i>Upload your documents</h6>
                                <p class="small text-muted mb-3">
                                    These are checked by a person, not an algorithm. Take the photo now where you can - screenshots or
                                    photos of an old photo are rejected.
                                </p>
                                <form method="post" enctype="multipart/form-data">
                                    <?php
                                        $req_govt_id  = in_array('govt_id', $kyc_still_needed, true);
                                        $req_selfie   = in_array('liveliness_picture', $kyc_still_needed, true);
                                        $req_poa      = in_array('proof_of_address', $kyc_still_needed, true);
                                        $req_video    = in_array('liveliness_video', $kyc_still_needed, true);
                                        $GLOBALS['bc_live_capture_required_fields'] = [
                                            'selfie'           => $req_selfie,
                                            'liveliness_video' => $req_video,
                                        ];
                                    ?>
                                    <?php if (in_array('govt_id', $manual_checks, true)): ?>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">Document type<?php echo $req_govt_id ? ' <span class="text-danger">*</span>' : ''; ?></label>
                                            <select name="doc_type" class="form-select rounded-3 shadow-sm"<?php echo $req_govt_id ? ' required' : ''; ?>>
                                                <option value="">Select...</option>
                                                <?php foreach (['BVN', 'NIN', 'Passport', "Driver's Licence", "Voter's Card", 'National ID'] as $dt): ?>
                                                    <option value="<?php echo htmlspecialchars($dt); ?>" <?php echo (($kyc_self['kyc_id_type'] ?? '') === $dt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label small fw-bold">Government ID (photo or PDF, max 8 MB)<?php echo $req_govt_id ? ' <span class="text-danger">*</span>' : ''; ?></label>
                                            <input type="file" name="govt_id" accept="image/*,application/pdf" class="form-control rounded-3 shadow-sm"<?php echo $req_govt_id ? ' required data-kyc-required="1"' : ''; ?>>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('liveliness_picture', $manual_checks, true)): ?>
                                    <div class="mt-3">
                                        <label class="form-label small fw-bold">Take a live photo of yourself now (camera only, max 512 KB)<?php echo $req_selfie ? ' <span class="text-danger">*</span>' : ''; ?></label>
                                        <?php bc_live_capture_widget('selfie'); ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('proof_of_address', $manual_checks, true)): ?>
                                    <div class="mt-3">
                                        <label class="form-label small fw-bold">Proof of address (utility bill, bank statement, last 3 months)<?php echo $req_poa ? ' <span class="text-danger">*</span>' : ''; ?></label>
                                        <input type="file" name="proof_of_address" accept="image/*,application/pdf" class="form-control rounded-3 shadow-sm"<?php echo $req_poa ? ' required data-kyc-required="1"' : ''; ?>>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('liveliness_video', $manual_checks, true)): ?>
                                    <div class="mt-3">
                                        <label class="form-label small fw-bold">Short video - say your full name and today's date<?php echo $req_video ? ' <span class="text-danger">*</span>' : ''; ?></label>
                                        <?php bc_live_capture_widget('liveliness_video', true); ?>
                                    </div>
                                    <?php endif; ?>

                                    <button name="submit_media" type="submit" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm mt-3">
                                        Submit for review
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- AI KYC Analysis Section -->
                    <?php
                    $ai_decision = bc_kyc_ai_get_decision($connection_server, (int)$get_logged_user_details['id']);
                    $ai_gate_ui = bc_kyc_is_ai_enabled($connection_server, (int)$vid);
                    $vendor_ai_enabled = !empty($ai_gate_ui['enabled']);
                    ?>
                    <?php if ($vendor_ai_enabled): ?>
                    <div class="col-md-12">
                        <div class="card kyc-card" style="background: linear-gradient(135deg, #0f172a, #1e293b); color: white;">
                            <div class="card-body p-4">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h5 class="fw-bold mb-2"><i class="bi bi-cpu me-2"></i>AI Compliance Engine</h5>
                                        <?php if ($ai_decision && $ai_decision['status'] !== ''): ?>
                                            <?php
                                            $ai_status_icon = ['approve' => 'bi-patch-check-fill text-success', 'reject' => 'bi-x-octagon-fill text-danger', 'review' => 'bi-clock-history text-warning', 'error' => 'bi-exclamation-triangle text-warning'];
                                            $ai_status_label = ['approve' => 'Approved', 'reject' => 'Rejected', 'review' => 'Needs Review', 'error' => 'Analysis Error'];
                                            $ai_icon = $ai_status_icon[$ai_decision['status']] ?? 'bi-exclamation-triangle text-warning';
                                            $ai_label = $ai_status_label[$ai_decision['status']] ?? 'Unknown';
                                            ?>
                                            <p class="small text-white-50 mb-2">
                                                <i class="bi <?php echo $ai_icon; ?> me-2"></i>
                                                AI Status: <?php echo htmlspecialchars($ai_label); ?>
                                                <?php if ($ai_decision['confidence'] > 0): ?>&middot; Confidence: <?php echo (int)$ai_decision['confidence']; ?>%<?php endif; ?>
                                                <?php if ($ai_decision['analyzed_at']): ?>&middot; Analyzed <?php echo date('M d, Y H:i', strtotime($ai_decision['analyzed_at'])); ?><?php endif; ?>
                                            </p>
                                            <?php if ($ai_decision['reason']): ?>
                                                <p class="small text-white-50"><?php echo htmlspecialchars($ai_decision['reason']); ?></p>
                                            <?php endif; ?>
                                            <?php if ($ai_decision['status'] === 'review' || $ai_decision['status'] === 'error'): ?>
                                                <button type="button" class="btn btn-light btn-sm rounded-pill px-3 fw-bold shadow-sm mt-2" onclick="document.getElementById('ai-analyze-form').submit()">
                                                    <i class="bi bi-arrow-repeat me-1"></i>Re-run AI Analysis
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="small text-white-50 mb-2">Upload your documents above and submit — our AI will analyze them automatically. You can also run a manual analysis below.</p>
                                            <form method="post" id="ai-analyze-form">
                                                <button type="submit" name="ai_analyze" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm">
                                                    <i class="bi bi-magic me-1"></i>Run AI Analysis
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4 text-center d-none d-md-block">
                                        <i class="bi bi-cpu-fill display-4 opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- AI Interview Section -->
                    <div class="col-md-12">
                        <div class="card kyc-card" style="background: linear-gradient(135deg, #1e1b4b, #312e81); color: white;">
                            <div class="card-body p-4">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h5 class="fw-bold mb-2"><i class="bi bi-robot me-2"></i>Titanium AI Interview</h5>
                                        <p class="small text-white-50">Short on time? Complete your KYC by simply talking to our AI Compliance Officer. No forms required.</p>
                                        <button type="button" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#aiKycModal">
                                            Start AI Interview
                                        </button>
                                    </div>
                                    <div class="col-md-4 text-center d-none d-md-block">
                                        <i class="bi bi-mic-fill display-4 opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- AI KYC Modal -->
    <div class="modal fade" id="aiKycModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-bold">AI Compliance Interview</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="aiChatLog" class="mb-3 p-3 bg-light rounded-3" style="height: 300px; overflow-y: auto;">
                        <p class="small mb-2"><b>AI:</b> Hello! I'm here to help you complete your KYC. What is your full name as it appears on your ID?</p>
                    </div>
                    <div class="input-group">
                        <input type="text" id="aiKycInput" class="form-control rounded-start-pill border-2" placeholder="Type or speak...">
                        <button class="btn btn-primary rounded-end-pill px-4" onclick="sendToAiKyc()">Send</button>
                    </div>
                </div>
            </div>
        </div>
    </div>



<script>
(function(){
  ['govt_id', 'proof_of_address'].forEach(function(name){
    var inp = document.querySelector('input[type="file"][name="' + name + '"]');
    if (!inp) return;
    inp.addEventListener('change', function(){
      var f = inp.files && inp.files[0];
      if (!f) return;
      if (f.size < 256) {
        alert('Blank or empty file detected (' + f.size + ' bytes). Please select a valid document.');
        inp.value = '';
        return;
      }
      if (/\.(php\d*|phtml|exe|dll|bat|cmd|sh|js|vbs|ps1|scr)\./i.test(f.name)) {
        alert('Suspicious filename detected. Upload blocked.');
        inp.value = '';
        return;
      }
      if (/^image\//i.test(f.type)) {
        var img = new Image();
        var url = URL.createObjectURL(f);
        img.onload = function(){
          try {
            var c = document.createElement('canvas');
            c.width = 24; c.height = 24;
            var ctx = c.getContext('2d');
            ctx.drawImage(img, 0, 0, 24, 24);
            var d = ctx.getImageData(0, 0, 24, 24).data;
            var lums = [], minL = 255, maxL = 0;
            for (var i = 0; i < d.length; i += 4) {
              var l = 0.299 * d[i] + 0.587 * d[i+1] + 0.114 * d[i+2];
              lums.push(l);
              if (l < minL) minL = l;
              if (l > maxL) maxL = l;
            }
            var mean = lums.reduce(function(a,b){return a+b;},0) / lums.length;
            var variance = lums.reduce(function(a,b){return a + (b-mean)*(b-mean);},0) / lums.length;
            if (Math.sqrt(variance) < 4.0 || (maxL - minL) < 12) {
              alert('Blank or solid-color image detected. Please upload a clear, readable document.');
              inp.value = '';
            }
          } catch(e) {}
          URL.revokeObjectURL(url);
        };
        img.onerror = function(){ URL.revokeObjectURL(url); };
        img.src = url;
      }
    });
  });
})();
</script>

<?php bc_app_foot(); ?>
