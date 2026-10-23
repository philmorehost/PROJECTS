<?php session_start();
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

// AI KYC Compliance Engine
include_once(__DIR__ . "/../func/bc-kyc-ai-analyzer.php");

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

if (isset($_POST['submit_media'])) {
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kyc/";
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
    $selfie_filename = '';

    // input name => [column, allowed extensions, max MB]. The selfie is images only: it is meant to be
    // taken with the camera, and a PDF cannot be one.
    $kinds = [
        'govt_id'          => ['govt_id_card',     ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 8],
        'selfie'           => ['kyc_face_image',   ['jpg', 'jpeg', 'png', 'webp'], 8],
        'proof_of_address' => ['proof_of_address', ['jpg', 'jpeg', 'png', 'webp', 'pdf'], 8],
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
        // The extension is attacker-controlled, so confirm an image really is one.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($tmp) === false) continue;

        $filename = 'kyc_' . $user_id . '_' . $input_name . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (move_uploaded_file($tmp, $upload_dir . $filename)) {
            $fn_esc = mysqli_real_escape_string($connection_server, $filename);
            $updates[] = "$db_col = '$fn_esc'";
            $uploaded[] = $input_name;
            if ($input_name === 'selfie') $selfie_filename = $filename;
        }
    }

    if (!empty($updates)) {
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
        if (!empty($_POST['selfie_live']) && $selfie_filename !== '') {
            $updates[] = "liveliness_picture = '" . mysqli_real_escape_string($connection_server, $selfie_filename) . "'";
        }
        $updates[] = "kyc_status = 1";           // back into the review queue
        $updates[] = "kyc_submitted_at = NOW()"; // the submission clock, not the account's reg_date
        $updates[] = "kyc_reject_reason = NULL"; // a new submission clears the previous rejection

        mysqli_query($connection_server, "UPDATE sas_users SET " . implode(", ", $updates) . " WHERE id='$user_id' AND vendor_id='$vid'");

        // Tell the user exactly what is still missing instead of a bare "submitted".
        $q_after = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
        $after = $q_after ? (mysqli_fetch_assoc($q_after) ?: []) : [];
        $still = $enabled_checks ? bc_kyc_checks_pending($after, $enabled_checks) : [];
        $msg = "Documents submitted and waiting for review.";
        if (!empty($still)) {
            $still_names = array_map('bc_kyc_check_label', $still);
            $msg .= " Still needed: " . implode(', ', $still_names) . ".";
        } else {
            $msg .= " Everything your provider requires is now with them.";
        }
        $_SESSION['product_purchase_response'] = $msg;

        // ─── Auto-trigger AI KYC analysis after document submission ────────────────────────────
        if (!empty($updates) && function_exists('ai_engine')) {
            $vendor_kyc_ai_q = mysqli_query($connection_server, "SELECT kyc_ai_enabled, kyc_ai_auto_approve FROM sas_vendors WHERE id='$vid' LIMIT 1");
            $vendor_kyc_ai = $vendor_kyc_ai_q ? mysqli_fetch_assoc($vendor_kyc_ai_q) : null;
            if ($vendor_kyc_ai && (int)($vendor_kyc_ai['kyc_ai_enabled'] ?? 0) === 1) {
                // Re-read the user row to get the latest document paths
                $q_reread = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$user_id' LIMIT 1");
                $user_reread = $q_reread ? (mysqli_fetch_assoc($q_reread) ?: []) : [];
                if ($user_reread) {
                    $analysis = bc_kyc_ai_analyze_all($user_reread, $enabled_checks);
                    $analysis['username'] = $user_reread['username'];
                    $auto_apply = (int)($vendor_kyc_ai['kyc_ai_auto_approve'] ?? 1) === 1;
                    $store_result = bc_kyc_ai_store_result($connection_server, $user_id, $vid, $analysis, $auto_apply);

                    if ($store_result['applied']) {
                        $_SESSION['product_purchase_response'] = "🎉 AI has verified your documents and approved your KYC automatically! Confidence: " . ($store_result['decision']['confidence'] ?? 0) . "%.";
                    } elseif (($store_result['decision']['status'] ?? '') === 'reject') {
                        $_SESSION['product_purchase_response'] = "⚠️ AI has reviewed your documents but could not approve them: " . ($store_result['decision']['reason'] ?? 'Please check your documents and resubmit.');
                    } else {
                        $_SESSION['product_purchase_response'] = "📋 Your documents have been submitted and are being analyzed by our AI Compliance Engine. You can also run a manual AI analysis below.";
                    }
                }
            }
        }
    } else {
        $_SESSION['product_purchase_response'] = "Error: no valid file received. Check the file type and that it is under the size limit.";
    }

    header("Location: KYCVerification.php");
    exit();
}

// ─── Manual AI analysis trigger ──────────────────────────────────────────────────────────────────
if (isset($_POST['ai_analyze'])) {
    if (!function_exists('ai_engine')) {
        $_SESSION['product_purchase_response'] = "AI engine is not available on this installation.";
        header("Location: KYCVerification.php");
        exit();
    }
    $vendor_kyc_ai_q = mysqli_query($connection_server, "SELECT kyc_ai_enabled, kyc_ai_auto_approve FROM sas_vendors WHERE id='$vid' LIMIT 1");
    $vendor_kyc_ai = $vendor_kyc_ai_q ? mysqli_fetch_assoc($vendor_kyc_ai_q) : null;
    if (!$vendor_kyc_ai || (int)($vendor_kyc_ai['kyc_ai_enabled'] ?? 0) !== 1) {
        $_SESSION['product_purchase_response'] = "AI KYC analysis is not enabled for your vendor.";
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
    $auto_apply = (int)($vendor_kyc_ai['kyc_ai_auto_approve'] ?? 1) === 1;
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

    $doc_base = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kyc/');
    $doc_path = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kyc/' . basename($doc_file));
    if (!$doc_base || !$doc_path || strpos($doc_path, $doc_base) !== 0 || !is_file($doc_path)) {
        http_response_code(404); exit('Document missing on disk');
    }

    $doc_mimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
    ];
    $doc_ext = strtolower(pathinfo($doc_path, PATHINFO_EXTENSION));

    header('Content-Type: ' . ($doc_mimes[$doc_ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($doc_path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="' . basename($doc_path) . '"');
    readfile($doc_path);
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>KYC Verification | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .kyc-card { border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); transition: 0.3s; }
        .kyc-card:hover { transform: translateY(-5px); }
        .status-badge { font-size: 0.75rem; padding: 5px 12px; border-radius: 50px; font-weight: 700; }
    </style>
</head>
<body class="bg-light">
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
      <h1>IDENTITY VERIFICATION (KYC)</h1>
      <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li><li class="breadcrumb-item active">KYC</li></ol></nav>
    </div>

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
                                    <?php if (in_array('govt_id', $manual_checks, true)): ?>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">Document type</label>
                                            <select name="doc_type" class="form-select rounded-3 shadow-sm">
                                                <option value="">Select...</option>
                                                <?php foreach (['BVN', 'NIN', 'Passport', "Driver's Licence", "Voter's Card", 'National ID'] as $dt): ?>
                                                    <option value="<?php echo htmlspecialchars($dt); ?>" <?php echo (($kyc_self['kyc_id_type'] ?? '') === $dt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label small fw-bold">Government ID (photo or PDF, max 8 MB)</label>
                                            <input type="file" name="govt_id" accept="image/*,application/pdf" class="form-control rounded-3 shadow-sm">
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('liveliness_picture', $manual_checks, true)): ?>
                                    <div class="mt-3">
                                        <label class="form-label small fw-bold">Take a live photo of yourself now</label>
                                        <input type="hidden" name="selfie_live" id="selfie_live" value="0">
                                        <div id="liveSelfieBox" class="border rounded-3 p-2 d-none" style="max-width:340px;background:#000;">
                                            <video id="liveSelfieVideo" autoplay playsinline muted class="w-100 rounded-2" style="max-height:240px;"></video>
                                            <canvas id="liveSelfieCanvas" class="d-none"></canvas>
                                            <img id="liveSelfieShot" class="w-100 rounded-2 d-none" alt="Captured selfie" style="max-height:240px;object-fit:cover;">
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                            <button type="button" id="liveSelfieStart" class="btn btn-outline-primary btn-sm rounded-pill">Start camera</button>
                                            <button type="button" id="liveSelfieCapture" class="btn btn-primary btn-sm rounded-pill d-none">Capture photo</button>
                                            <button type="button" id="liveSelfieRetake" class="btn btn-outline-secondary btn-sm rounded-pill d-none">Retake</button>
                                        </div>
                                        <div id="liveSelfieMsg" class="form-text"></div>
                                        <div class="mt-2">
                                            <input type="file" name="selfie" id="selfieFile" accept="image/*" capture="user" class="form-control rounded-3 shadow-sm">
                                            <div class="form-text">No camera here, or prefer to upload? Use the box above. A screenshot or a photo of a photo is not a live photo.</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('proof_of_address', $manual_checks, true)): ?>
                                    <div class="mt-3">
                                        <label class="form-label small fw-bold">Proof of address (utility bill, bank statement, last 3 months)</label>
                                        <input type="file" name="proof_of_address" accept="image/*,application/pdf" class="form-control rounded-3 shadow-sm">
                                    </div>
                                    <?php endif; ?>

                                    <?php if (in_array('liveliness_video', $manual_checks, true)): ?>
                                    <div class="mt-3">
                                        <label class="form-label small fw-bold">Short video - say your full name and today's date</label>
                                        <input type="file" name="liveliness_video" accept="video/*" capture="user" class="form-control rounded-3 shadow-sm">
                                        <div class="form-text">MP4/MOV/WebM, max 25 MB.</div>
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
                    $vendor_ai_enabled = false;
                    $v_ai_q = mysqli_query($connection_server, "SELECT kyc_ai_enabled FROM sas_vendors WHERE id='$vid' LIMIT 1");
                    $v_ai = $v_ai_q ? mysqli_fetch_assoc($v_ai_q) : null;
                    $vendor_ai_enabled = ($v_ai && (int)($v_ai['kyc_ai_enabled'] ?? 0) === 1);
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
    // Live selfie capture. getUserMedia needs a secure context and a camera, so on an http host (or an
    // unsupported browser) the capture buttons explain themselves and stay out of the way - the plain
    // file input above is always rendered and still works.
    (function () {
        var startBtn = document.getElementById('liveSelfieStart');
        if (!startBtn) return;

        var video      = document.getElementById('liveSelfieVideo');
        var canvas     = document.getElementById('liveSelfieCanvas');
        var shot       = document.getElementById('liveSelfieShot');
        var box        = document.getElementById('liveSelfieBox');
        var capBtn     = document.getElementById('liveSelfieCapture');
        var retakeBtn  = document.getElementById('liveSelfieRetake');
        var msg        = document.getElementById('liveSelfieMsg');
        var fileInput  = document.getElementById('selfieFile');
        var liveFlag   = document.getElementById('selfie_live');
        var stream     = null;

        function stopStream() {
            if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
            video.srcObject = null;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.isSecureContext) {
            startBtn.classList.add('d-none');
            msg.textContent = 'Live capture is not available here (it needs https and a camera). Please upload a photo instead.';
            return;
        }

        startBtn.addEventListener('click', function () {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 720 } }, audio: false })
                .then(function (s) {
                    stream = s;
                    video.srcObject = s;
                    box.classList.remove('d-none');
                    startBtn.classList.add('d-none');
                    capBtn.classList.remove('d-none');
                    msg.textContent = 'Look straight at the camera, then press Capture photo.';
                })
                .catch(function () {
                    msg.textContent = 'Camera permission was refused. Please upload a photo instead.';
                });
        });

        capBtn.addEventListener('click', function () {
            var w = video.videoWidth, h = video.videoHeight;
            if (!w || !h) { msg.textContent = 'The camera is still starting - try again in a second.'; return; }
            canvas.width = w;
            canvas.height = h;
            canvas.getContext('2d').drawImage(video, 0, 0, w, h);
            canvas.toBlob(function (blob) {
                if (!blob) { msg.textContent = 'Could not capture the frame. Please upload a photo instead.'; return; }
                var file = new File([blob], 'selfie_live_' + Date.now() + '.jpg', { type: 'image/jpeg' });
                try {
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    fileInput.files = dt.files;
                } catch (e) {
                    msg.textContent = 'This browser cannot attach the captured photo. Please upload a file instead.';
                    return;
                }
                shot.src = URL.createObjectURL(blob);
                shot.classList.remove('d-none');
                video.classList.add('d-none');
                if (liveFlag) liveFlag.value = '1';
                capBtn.classList.add('d-none');
                retakeBtn.classList.remove('d-none');
                msg.textContent = 'Captured. Press Retake if you blinked or moved.';
                stopStream();
            }, 'image/jpeg', 0.92);
        });

        retakeBtn.addEventListener('click', function () {
            if (liveFlag) liveFlag.value = '0';
            fileInput.value = '';
            shot.classList.add('d-none');
            video.classList.remove('d-none');
            retakeBtn.classList.add('d-none');
            startBtn.classList.remove('d-none');
            msg.textContent = '';
            stopStream();
        });
    })();
    </script>
