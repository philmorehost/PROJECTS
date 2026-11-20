<?php session_start();
include("../func/bc-spadmin-config.php");
include_once(__DIR__ . "/../func/bc-plate-admin.php"); // §42: bc_adm_head/sidebar/topbar/foot live here - this page used them without ever loading the plate (fatal on open)

// §33: vendor compliance review. Vendors submit BVN/NIN + documents from
// bc-admin (Account Settings → Vendor KYC); nothing self-approves. A decision
// here takes CSRF + step-up: approvals need Security PIN + authenticator /
// email OTP, rejections need the PIN and a reason (§29 pattern).
if (function_exists('bc_vendor_kyc_ensure_schema')) bc_vendor_kyc_ensure_schema();

$vkyc_labels = [0 => 'Unverified', 1 => 'Under review', 2 => 'Verified', 3 => 'Rejected'];
$vkyc_badges = [0 => 'secondary', 1 => 'warning', 2 => 'success', 3 => 'danger'];
$vkyc_check_labels = ['bvn' => 'BVN', 'nin' => 'NIN', 'govt_id' => 'Government ID', 'liveliness_picture' => 'Live photo (selfie)', 'liveliness_video' => 'Liveliness video', 'proof_of_address' => 'Proof of address'];

// Stream one submitted document to the signed-in super admin only. The
// upload directory denies direct HTTP access (.htaccess), so this
// authenticated viewer is the only way in.
if (isset($_GET['doc'])) {
    $doc_id = (int)$_GET['doc'];
    $dq = mysqli_query($connection_server, "SELECT * FROM sas_vendor_kyc_documents WHERE id='$doc_id' LIMIT 1");
    $doc = $dq ? mysqli_fetch_assoc($dq) : null;
    $base = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kyc-vendor');
    $path = ($doc && $base) ? $base . DIRECTORY_SEPARATOR . basename((string)$doc['file_name']) : '';
    if (!$doc || $path === '' || !is_file($path)) {
        http_response_code(404);
        exit('Document not found.');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska'];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (isset($_POST['sec33_send_code'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $send_err = function_exists('bc_stepup_send_email_otp')
        ? bc_stepup_send_email_otp(0, (string)$get_logged_spadmin_details['email'], (string)$get_logged_spadmin_details['email'], 'vendor_kyc_decision', 'Vendor KYC Decision')
        : 'Verification is unavailable.';
    $_SESSION['product_purchase_response'] = $send_err === '' ? 'Verification code sent to your email.' : 'Error: ' . $send_err;
    header('Location: VendorKYC.php?status=' . (int)($_POST['return_status'] ?? 1));
    exit();
}

if (isset($_POST['vendor_kyc_decision'])) {
    if (function_exists('bc_validate_csrf')) bc_validate_csrf();
    $vid      = (int)($_POST['vendor_id'] ?? 0);
    $decision = (string)$_POST['vendor_kyc_decision'];
    $reason   = trim(strip_tags($_POST['reason'] ?? ''));
    $factor   = (string)($_POST['sec_factor'] ?? '');

    $target_q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vid' LIMIT 1");
    $target   = $target_q ? mysqli_fetch_assoc($target_q) : null;

    if (!$target || !in_array($decision, ['approve', 'reject'], true)) {
        $_SESSION['product_purchase_response'] = 'Error: unknown vendor or action.';
    } elseif ($decision === 'reject' && $reason === '') {
        $_SESSION['product_purchase_response'] = 'Error: a rejection needs a reason, so the vendor knows what to fix.';
    } else {
        if ($decision === 'approve') {
            $step_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => $factor, 'totp_code' => $factor];
        } else {
            $step_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'pin_check_only' => 1];
        }
        $step_err = function_exists('bc_stepup_spadmin_verify')
            ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $step_input, 'vendor_kyc_decision')
            : 'Verification is unavailable.';
        if ($step_err !== '') {
            $_SESSION['product_purchase_response'] = 'Error: ' . $step_err;
        } else {
            $new_status = $decision === 'approve' ? 2 : 3;
            $reason_esc = mysqli_real_escape_string($connection_server, $reason);
            mysqli_query($connection_server, "UPDATE sas_vendors SET kyc_status='$new_status', kyc_reject_reason=" . ($decision === 'reject' ? "'$reason_esc'" : "NULL") . " WHERE id='$vid'");
            $doc_status = $decision === 'approve' ? 'approved' : 'rejected';
            mysqli_query($connection_server, "UPDATE sas_vendor_kyc_documents SET status='$doc_status', reviewed_at=NOW(), reviewed_by='" . (int)$get_logged_spadmin_details['id'] . "', review_note='$reason_esc' WHERE vendor_id='$vid' AND status='pending'");
            if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details['email'], 'vendor_kyc_' . $decision, 'vendor#' . $vid, $decision === 'reject' ? $reason : 'Vendor compliance approved');

            $flash = 'Vendor KYC ' . ($decision === 'approve' ? 'approved' : 'rejected') . ' for ' . ($target['business_name'] ?? $target['email'] ?? ('#' . $vid)) . '.';
            if (!empty($target['email']) && function_exists('sendVendorEmail')) {
                $prev = $GLOBALS['vendor_id'] ?? null;
                $GLOBALS['vendor_id'] = $vid;
                $mail_body = $decision === 'approve'
                    ? 'Hello ' . htmlspecialchars((string)($target['firstname'] ?? 'Vendor'), ENT_QUOTES, 'UTF-8') . ',<br><br>Your compliance (KYC) verification has been <b>approved</b>. Full dashboard access is now unlocked.'
                    : 'Hello ' . htmlspecialchars((string)($target['firstname'] ?? 'Vendor'), ENT_QUOTES, 'UTF-8') . ',<br><br>Your compliance (KYC) submission needs another look.<br><br><b>Reason:</b> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '<br><br>Please sign in, correct the item above and resubmit.';
                $mail_sent = sendVendorEmail((string)$target['email'], 'Vendor KYC ' . ($decision === 'approve' ? 'Approved' : 'Action Required'), $mail_body);
                if ($prev === null) unset($GLOBALS['vendor_id']); else $GLOBALS['vendor_id'] = $prev;
                $flash .= $mail_sent ? ' The vendor has been emailed.' : ' The email to the vendor could not be sent.';
            }
            $_SESSION['product_purchase_response'] = $flash;
        }
    }
    header('Location: VendorKYC.php?status=' . (int)($_POST['return_status'] ?? 1));
    exit();
}

$status_filter = isset($_GET['status']) ? (int)$_GET['status'] : 1;
if (!isset($vkyc_labels[$status_filter])) $status_filter = 1;

$counts = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
$count_q = mysqli_query($connection_server, "SELECT kyc_status, COUNT(*) AS c FROM sas_vendors GROUP BY kyc_status");
while ($count_q && ($r = mysqli_fetch_assoc($count_q))) {
    if (isset($counts[(int)$r['kyc_status']])) $counts[(int)$r['kyc_status']] = (int)$r['c'];
}

$vendors = [];
$list_q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE kyc_status='$status_filter' ORDER BY id DESC LIMIT 100");
while ($list_q && ($r = mysqli_fetch_assoc($list_q))) $vendors[] = $r;

$required_checks = function_exists('bc_vendor_kyc_required_checks') ? bc_vendor_kyc_required_checks() : [];
$notice = '';
if (!empty($_SESSION['product_purchase_response'])) {
    $notice = (string)$_SESSION['product_purchase_response'];
    unset($_SESSION['product_purchase_response']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php bc_adm_head("Vendor KYC Review | " . ($get_all_super_admin_site_details["site_title"] ?? 'VTU Platform'), array("area" => "spadmin")); ?>
</head>
<body>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "VENDOR KYC REVIEW", "crumb" => "Home / Compliance / Vendor KYC")); ?>

    <section class="section dashboard">
      <div class="row g-4">
        <div class="col-12">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex flex-wrap gap-2 align-items-center justify-content-between">
              <h5 class="fw-bold mb-0">Vendor Compliance Queue</h5>
              <div class="btn-group">
                <?php foreach ($vkyc_labels as $st => $label): ?>
                <a class="btn btn-sm <?php echo $status_filter === $st ? 'btn-primary' : 'btn-outline-primary'; ?>" href="VendorKYC.php?status=<?php echo $st; ?>"><?php echo $label; ?> <span class="badge bg-light text-dark"><?php echo $counts[$st]; ?></span></a>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-body p-4">
              <?php if ($notice !== ''): ?>
              <div class="alert alert-info rounded-3"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php endif; ?>
              <p class="text-muted small">Approvals require your <strong>Security PIN + Google Authenticator code</strong> (or an emailed OTP — use “Email me a code”). Rejections require the PIN and a reason. A vendor stays locked out of bc-admin until approved.</p>
              <?php if (!$vendors): ?>
              <div class="text-center py-5 text-muted">No vendors in “<?php echo strtolower($vkyc_labels[$status_filter]); ?>”.</div>
              <?php endif; ?>
              <?php foreach ($vendors as $vendor):
                  $vid = (int)$vendor['id'];
                  $pending = function_exists('bc_vendor_kyc_pending') ? bc_vendor_kyc_pending($vendor, $required_checks) : [];
                  $docs = [];
                  $dq = mysqli_query($connection_server, "SELECT * FROM sas_vendor_kyc_documents WHERE vendor_id='$vid' ORDER BY submitted_at DESC");
                  while ($dq && ($dr = mysqli_fetch_assoc($dq))) $docs[] = $dr;
              ?>
              <div class="border rounded-4 p-3 mb-3">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                  <div>
                    <strong><?php echo htmlspecialchars((string)($vendor['business_name'] ?? $vendor['firstname'] ?? 'Vendor'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <span class="text-muted small">#<?php echo $vid; ?> · <?php echo htmlspecialchars((string)($vendor['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string)($vendor['phone_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                  <span class="badge bg-<?php echo $vkyc_badges[(int)($vendor['kyc_status'] ?? 0)]; ?> px-3 py-2"><?php echo $vkyc_labels[(int)($vendor['kyc_status'] ?? 0)]; ?></span>
                </div>
                <div class="row g-2 mt-2 small">
                  <div class="col-md-3">BVN: <strong><?php echo !empty($vendor['bvn']) ? '••••••' . substr((string)$vendor['bvn'], -4) : '<span class="text-danger">missing</span>'; ?></strong></div>
                  <div class="col-md-3">NIN: <strong><?php echo !empty($vendor['nin']) ? '••••••' . substr((string)$vendor['nin'], -4) : '<span class="text-danger">missing</span>'; ?></strong></div>
                  <div class="col-md-6">Still needed: <strong><?php echo $pending ? implode(', ', array_map(function ($c) use ($vkyc_check_labels) { return $vkyc_check_labels[$c] ?? $c; }, $pending)) : 'nothing — file complete'; ?></strong></div>
                </div>
                <?php if ($docs): ?>
                <div class="mt-2 small">
                  <?php foreach ($docs as $doc): ?>
                  <a class="btn btn-sm btn-outline-secondary rounded-pill me-1 mb-1" target="_blank" rel="noopener" href="VendorKYC.php?doc=<?php echo (int)$doc['id']; ?>"><?php echo htmlspecialchars($vkyc_check_labels[$doc['doc_type']] ?? $doc['doc_type'], ENT_QUOTES, 'UTF-8'); ?> <span class="badge bg-<?php echo $doc['status'] === 'approved' ? 'success' : ($doc['status'] === 'rejected' ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars((string)$doc['status'], ENT_QUOTES, 'UTF-8'); ?></span></a>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ((int)($vendor['kyc_status'] ?? 0) !== 2): ?>
                <form method="post" class="row g-2 align-items-end mt-2">
                  <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                  <input type="hidden" name="vendor_id" value="<?php echo $vid; ?>">
                  <input type="hidden" name="return_status" value="<?php echo $status_filter; ?>">
                  <div class="col-md-3"><label class="form-label small fw-bold text-muted">SECURITY PIN</label><input type="password" name="sec_pin" class="form-control rounded-3" required></div>
                  <div class="col-md-3"><label class="form-label small fw-bold text-muted">AUTHENTICATOR / EMAIL CODE (approve)</label><input name="sec_factor" class="form-control rounded-3" placeholder="6-digit code"></div>
                  <div class="col-md-3"><label class="form-label small fw-bold text-muted">REJECT REASON</label><input name="reason" class="form-control rounded-3" placeholder="Required when rejecting"></div>
                  <div class="col-md-3 d-flex gap-2">
                    <button name="vendor_kyc_decision" value="approve" class="btn btn-success rounded-pill fw-bold flex-fill" onclick="return confirm('Approve this vendor compliance file?');">Approve</button>
                    <button name="vendor_kyc_decision" value="reject" class="btn btn-outline-danger rounded-pill fw-bold flex-fill">Reject</button>
                    <button name="sec33_send_code" class="btn btn-outline-secondary rounded-pill fw-bold" formnovalidate title="Email me a code">✉</button>
                  </div>
                </form>
                <?php else: ?>
                <form method="post" class="row g-2 align-items-end mt-2">
                  <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                  <input type="hidden" name="vendor_id" value="<?php echo $vid; ?>">
                  <input type="hidden" name="return_status" value="<?php echo $status_filter; ?>">
                  <div class="col-md-3"><label class="form-label small fw-bold text-muted">SECURITY PIN (to revoke)</label><input type="password" name="sec_pin" class="form-control rounded-3" required></div>
                  <div class="col-md-4"><label class="form-label small fw-bold text-muted">REASON FOR REVOKING</label><input name="reason" class="form-control rounded-3" required></div>
                  <div class="col-md-3"><button name="vendor_kyc_decision" value="reject" class="btn btn-outline-danger rounded-pill fw-bold">Revoke verification</button></div>
                </form>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </section>
<?php bc_adm_foot(array("area" => "spadmin")); ?>
</body>
</html>
