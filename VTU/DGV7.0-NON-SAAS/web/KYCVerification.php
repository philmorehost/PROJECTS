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

// Check if VoveID is enabled for this vendor
$voveid_enabled = false;
$voveid_public_key = '';
$voveid_env = 'production';
$voveid_q = mysqli_query($connection_server, "SELECT option_name, option_value FROM sas_vendor_settings WHERE vendor_id='$vid' AND option_name IN ('voveid_enabled', 'voveid_public_key', 'voveid_environment')");
while($r = mysqli_fetch_assoc($voveid_q)) {
    if ($r['option_name'] === 'voveid_enabled') $voveid_enabled = (int)$r['option_value'] === 1;
    if ($r['option_name'] === 'voveid_public_key') $voveid_public_key = $r['option_value'];
    if ($r['option_name'] === 'voveid_environment') $voveid_env = $r['option_value'];
}

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
    } else {
        $_SESSION['product_purchase_response'] = "Error: no valid file received. Check the file type and that it is under the size limit.";
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
    <?php if ($voveid_enabled && !empty($voveid_public_key)): ?>
    <!-- VoveID Web SDK -->
    <script src="https://cdn.voveid.com/web-sdk/voveid-web-sdk.min.js"></script>
    <?php endif; ?>
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
                    <!-- VoveID Verification Section -->
                    <?php if ($voveid_enabled && !empty($voveid_public_key)): ?>
                    <div class="col-12">
                        <div class="card kyc-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                            <div class="card-body p-4">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h5 class="fw-bold mb-2"><i class="bi bi-shield-check me-2"></i>VoveID Identity Verification</h5>
                                        <p class="small text-white-50 mb-3">Complete your KYC in minutes with VoveID's AI-powered verification. Secure, fast, and compliant.</p>
                                        <button type="button" id="btnStartVoveID" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm" onclick="startVoveIDVerification()">
                                            <i class="bi bi-shield-lock me-2"></i>Start VoveID Verification
                                        </button>
                                        <span id="voveidStatus" class="ms-3 small text-white-50"></span>
                                    </div>
                                    <div class="col-md-4 text-center d-none d-md-block">
                                        <i class="bi bi-shield-lock-fill display-4 opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

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

    <?php if ($voveid_enabled && !empty($voveid_public_key)): ?>
    <script>
        // VoveID Web SDK Integration
        let voveidInitialized = false;
        
        // Initialize VoveID SDK
        async function initVoveID() {
            if (typeof Vove === 'undefined') {
                document.getElementById('voveidStatus').textContent = 'Loading VoveID SDK...';
                // Wait for SDK to load
                let attempts = 0;
                while (typeof Vove === 'undefined' && attempts < 20) {
                    await new Promise(r => setTimeout(r, 100));
                    attempts++;
                }
            }
            
            if (typeof Vove !== 'undefined') {
                try {
                    await new Promise((resolve, reject) => {
                        Vove.initialize('<?php echo addslashes($voveid_public_key); ?>', '<?php echo $voveid_env; ?>', (result) => {
                            if (result === 'success' || result === true) {
                                voveidInitialized = true;
                                document.getElementById('voveidStatus').textContent = 'Ready';
                                resolve();
                            } else {
                                reject(new Error('VoveID initialization failed'));
                            }
                        });
                    });
                } catch (e) {
                    console.error('VoveID init error:', e);
                    document.getElementById('voveidStatus').textContent = 'SDK init failed';
                }
            } else {
                document.getElementById('voveidStatus').textContent = 'SDK not loaded';
            }
        }
        
        // Start VoveID Verification
        async function startVoveIDVerification() {
            const btn = document.getElementById('btnStartVoveID');
            const statusEl = document.getElementById('voveidStatus');
            
            if (!voveidInitialized) {
                statusEl.textContent = 'Initializing...';
                await initVoveID();
            }
            
            if (!voveidInitialized) {
                statusEl.textContent = 'VoveID SDK not available';
                return;
            }
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating session...';
            statusEl.textContent = 'Creating verification session...';
            
            try {
                // Create VoveID session via our backend
                const response = await fetch('/api/voveid-session.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Failed to create session');
                }
                
                const sessionToken = data.token;
                statusEl.textContent = 'Starting verification...';
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Launching...';
                
                // Start VoveID verification
                const config = {
                    showUI: true,
                    exitAfterEachStep: false,
                    maxAttemptsActionCallback: () => {
                        statusEl.textContent = 'Max attempts reached. Contact support.';
                    }
                };
                
                Vove.start(sessionToken, config, (payload) => {
                    if (!payload) return;
                    
                    console.log('VoveID payload:', payload);
                    
                    switch (payload.result) {
                        case 'success':
                        case 'SUCCESS':
                            statusEl.innerHTML = '<span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>Verification successful!</span>';
                            btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Verified';
                            btn.classList.remove('btn-light');
                            btn.classList.add('btn-success');
                            setTimeout(() => window.location.reload(), 3000);
                            break;
                        case 'pending':
                        case 'PENDING':
                            statusEl.textContent = 'Verification pending review...';
                            break;
                        case 'in_progress':
                        case 'IN_PROGRESS':
                            const nextStep = payload.nextStep?.name || 'processing';
                            statusEl.textContent = 'Step completed: ' + nextStep + '. Continuing...';
                            break;
                        case 'canceled':
                        case 'CANCELED':
                            statusEl.textContent = 'Verification canceled';
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-shield-lock me-2"></i>Start VoveID Verification';
                            break;
                        case 'max_attempts':
                        case 'MAX_ATTEMPTS_REACHED':
                            statusEl.textContent = 'Max attempts reached. Contact support.';
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-shield-lock me-2"></i>Start VoveID Verification';
                            break;
                    }
                    
                    if (payload.nextStep && payload.nextStep.name) {
                        console.log('Next step:', payload.nextStep.name);
                    }
                });
                
            } catch (e) {
                console.error('VoveID error:', e);
                statusEl.textContent = 'Error: ' + e.message;
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-shield-lock me-2"></i>Start VoveID Verification';
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', initVoveID);
    </script>
    <?php endif; ?>

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
