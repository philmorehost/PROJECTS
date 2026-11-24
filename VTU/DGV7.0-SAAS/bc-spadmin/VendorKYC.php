<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-spadmin-config.php");
require_once __DIR__.'/../func/bc-admin-inline-otp.php';
bc_admin_inline_otp_dispatch($get_logged_spadmin_details,true,'vendor_kyc_decision');
include_once(__DIR__ . "/../func/bc-plate-admin.php"); // §42: bc_adm_head/sidebar/topbar/foot live here - this page used them without ever loading the plate (fatal on open)

require_once __DIR__.'/../func/bc-vendor-compliance-review.php';

// §33: vendor compliance review. Vendors submit BVN/NIN + documents from
// bc-admin (Account Settings → Vendor KYC); nothing self-approves. A decision
// here takes CSRF + step-up: approvals need Security PIN + authenticator /
// email OTP, rejections need the PIN and a reason (§29 pattern).
if (function_exists('bc_vendor_kyc_ensure_schema')) bc_vendor_kyc_ensure_schema();

$vkyc_labels = [0 => 'Unverified', 1 => 'Under review', 2 => 'Verified', 3 => 'Rejected'];
$vkyc_badges = [0 => 'secondary', 1 => 'warning', 2 => 'success', 3 => 'danger'];
$vkyc_check_labels = ['bvn' => 'BVN', 'nin' => 'NIN', 'govt_id' => 'Government ID', 'selfie' => 'Live photo (selfie)', 'liveliness_picture' => 'Live photo (selfie)', 'liveliness_video' => 'Liveliness video', 'proof_of_address' => 'Proof of address', 'business_registration' => 'KYB: Business registration / CAC', 'beneficial_ownership' => 'KYB: Beneficial ownership'];

// Stream one submitted document to the signed-in super admin only. The
// upload directory denies direct HTTP access (.htaccess), so this
// authenticated viewer is the only way in. Supports HTTP byte-range
// requests so liveliness videos stream and scrub smoothly in <video>.
if (isset($_GET['doc'])) {
    $doc_id = (int)$_GET['doc'];
    $dq = mysqli_query($connection_server, "SELECT * FROM sas_vendor_kyc_documents WHERE id='$doc_id' LIMIT 1");
    $doc = $dq ? mysqli_fetch_assoc($dq) : null;
    $doc_root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($doc_root === '') {
        $doc_root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    }
    $base = realpath($doc_root . '/uploads/kyc-vendor');
    $path = ($doc && $base) ? $base . DIRECTORY_SEPARATOR . basename((string)$doc['file_name']) : '';
    if (!$doc || $path === '' || !is_file($path)) {
        http_response_code(404);
        exit('Document not found.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
    ];
    $mime = $mimes[$ext] ?? '';
    if ($mime === '' && function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = @finfo_file($fi, $path);
            @finfo_close($fi);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
    }
    if ($mime === '') {
        $mime = !empty($doc['mime_type']) ? (string)$doc['mime_type'] : 'application/octet-stream';
    }
    $size = filesize($path);
    $start = 0;
    $end = $size > 0 ? $size - 1 : 0;

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');

    if ($size > 0 && !empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/i', trim((string)$_SERVER['HTTP_RANGE']), $rm)) {
        if ($rm[1] !== '') {
            $start = (int)$rm[1];
        }
        if ($rm[2] !== '') {
            $end = (int)$rm[2];
        }
        if ($rm[1] === '' && $rm[2] !== '') {
            $start = max(0, $size - (int)$rm[2]);
            $end = $size - 1;
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $end = min($end, $size - 1);
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . ($end - $start + 1));
    } else {
        header('Content-Length: ' . $size);
    }

    $fp = fopen($path, 'rb');
    if ($fp) {
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $size > 0 ? ($end - $start + 1) : 0;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($fp);
    }
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
            $step_input = ['factor_method'=>(string)($_POST['factor_method']??'auto'),'pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => $factor, 'totp_code' => $factor];
        } else {
            $step_input = ['factor_method'=>(string)($_POST['factor_method']??'auto'),'pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => (string)($_POST['sec_factor'] ?? ''), 'totp_code' => (string)($_POST['sec_factor'] ?? '')];
        }
        $step_err = function_exists('bc_stepup_spadmin_verify')
            ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $step_input, 'vendor_kyc_decision')
            : 'Verification is unavailable.';
        if ($step_err !== '') {
            $_SESSION['product_purchase_response'] = 'Error: ' . $step_err;
        } else {
            $new_status = $decision === 'approve' ? 2 : 3;
            $reason_esc = mysqli_real_escape_string($connection_server, $reason);
            $expected=bc_vcr_consume($_POST['review_ticket']??null,$get_logged_spadmin_details);
            $review_result=bc_vcr_decide($vid,$decision,$reason,$get_logged_spadmin_details,$expected);
            if($review_result!==true){
                $_SESSION['product_purchase_response']='Error: '.$review_result;
                header('Location: VendorKYC.php?status='.(int)($_POST['return_status']??1));exit;
            }
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
<?php bc_adm_head("Vendor KYC / KYB Review | " . ($get_all_super_admin_site_details["site_title"] ?? 'VTU Platform'), array("area" => "spadmin")); ?>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "VENDOR KYC / KYB REVIEW", "crumb" => "Home / Compliance / Vendor KYC")); ?>

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
                  $review_ticket=bc_vcr_ticket($vendor,$docs,$get_logged_spadmin_details);
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
                <div class="mt-3">
                  <div class="small fw-bold text-muted mb-2">Submitted KYC / KYB Documents &amp; Media</div>
                  <div class="row g-2">
                    <?php foreach ($docs as $doc):
                        $doc_id = (int)$doc['id'];
                        $doc_url = 'VendorKYC.php?doc=' . $doc_id;
                        $doc_label = $vkyc_check_labels[$doc['doc_type']] ?? $doc['doc_type'];
                        $doc_ext = strtolower(pathinfo((string)($doc['file_name'] ?? ''), PATHINFO_EXTENSION));
                        $doc_mime = strtolower((string)($doc['mime_type'] ?? ''));
                        $is_video = (strpos($doc_mime, 'video/') === 0) || in_array($doc_ext, ['mp4', 'mov', 'webm', 'mkv'], true) || $doc['doc_type'] === 'liveliness_video';
                        $is_image = !$is_video && ((strpos($doc_mime, 'image/') === 0) || in_array($doc_ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true));
                        $is_pdf = ($doc_mime === 'application/pdf') || $doc_ext === 'pdf';
                        $media_kind = $is_video ? 'video' : ($is_image ? 'image' : ($is_pdf ? 'pdf' : 'other'));
                        $badge_cls = $doc['status'] === 'approved' ? 'success' : ($doc['status'] === 'rejected' ? 'danger' : 'warning');
                    ?>
                    <div class="col-sm-6 col-lg-4">
                      <div class="border rounded-3 p-2 bg-light h-100 d-flex flex-column justify-content-between gap-2">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                          <span class="small fw-semibold text-truncate" title="<?php echo htmlspecialchars($doc_label, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($doc_label, ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="badge bg-<?php echo $badge_cls; ?>"><?php echo htmlspecialchars((string)$doc['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="rounded-2 overflow-hidden bg-white border d-flex align-items-center justify-content-center" style="min-height:130px;max-height:180px;">
                          <?php if ($is_image): ?>
                            <img src="<?php echo htmlspecialchars($doc_url, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($doc_label, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" class="img-fluid w-100" style="max-height:175px;object-fit:contain;cursor:pointer;" onclick="vkycOpenPreview(<?php echo htmlspecialchars(json_encode($doc_url), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($doc_label), ENT_QUOTES, 'UTF-8'); ?>, 'image')">
                          <?php elseif ($is_video): ?>
                            <video controls preload="metadata" playsinline class="w-100 bg-dark" style="max-height:175px;">
                              <source src="<?php echo htmlspecialchars($doc_url, ENT_QUOTES, 'UTF-8'); ?>"<?php if ($doc_mime !== ''): ?> type="<?php echo htmlspecialchars($doc_mime, ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                            </video>
                          <?php elseif ($is_pdf): ?>
                            <button type="button" class="btn btn-link text-decoration-none text-danger fw-semibold py-4" onclick="vkycOpenPreview(<?php echo htmlspecialchars(json_encode($doc_url), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($doc_label), ENT_QUOTES, 'UTF-8'); ?>, 'pdf')">
                              <i class="bi bi-file-earmark-pdf fs-2 d-block mb-1"></i>Click to preview PDF
                            </button>
                          <?php else: ?>
                            <a href="<?php echo htmlspecialchars($doc_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="btn btn-link text-decoration-none py-4">
                              <i class="bi bi-file-earmark-text fs-2 d-block mb-1"></i>Open file
                            </a>
                          <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2">
                          <?php if ($media_kind !== 'other'): ?>
                          <button type="button" class="btn btn-sm btn-outline-primary flex-fill" onclick="vkycOpenPreview(<?php echo htmlspecialchars(json_encode($doc_url), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($doc_label), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($media_kind), ENT_QUOTES, 'UTF-8'); ?>)">Preview</button>
                          <?php endif; ?>
                          <a class="btn btn-sm btn-outline-secondary flex-fill" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($doc_url, ENT_QUOTES, 'UTF-8'); ?>">Open</a>
                        </div>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endif; ?>
                <?php if ((int)($vendor['kyc_status'] ?? 0) !== 2): ?>
                <form method="post" class="row g-2 align-items-end mt-2">
                  <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                  <input type="hidden" name="review_ticket" value="<?php echo htmlspecialchars($review_ticket, ENT_QUOTES, 'UTF-8'); ?>">
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
                  <input type="hidden" name="review_ticket" value="<?php echo htmlspecialchars($review_ticket, ENT_QUOTES, 'UTF-8'); ?>">
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

    <div class="modal fade" id="vkycPreviewModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
          <div class="modal-header border-0">
            <h5 class="modal-title fw-bold" id="vkycPreviewTitle">Document Preview</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-center bg-light rounded-3 mx-3 mb-2 p-2" id="vkycPreviewBody" style="min-height:220px;"></div>
          <div class="modal-footer border-0">
            <a id="vkycPreviewOpen" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary rounded-pill px-3">Open in new tab</a>
            <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    function vkycOpenPreview(url, label, kind) {
        var modalEl = document.getElementById('vkycPreviewModal');
        var titleEl = document.getElementById('vkycPreviewTitle');
        var bodyEl = document.getElementById('vkycPreviewBody');
        var openEl = document.getElementById('vkycPreviewOpen');
        if (!modalEl || !bodyEl) {
            window.open(url, '_blank', 'noopener');
            return;
        }
        titleEl.textContent = label || 'Document Preview';
        openEl.href = url;
        bodyEl.innerHTML = '';
        if (kind === 'video') {
            var v = document.createElement('video');
            v.controls = true;
            v.autoplay = true;
            v.playsInline = true;
            v.className = 'w-100 rounded-3 bg-dark';
            v.style.maxHeight = '70vh';
            v.src = url;
            bodyEl.appendChild(v);
        } else if (kind === 'pdf') {
            var f = document.createElement('iframe');
            f.src = url;
            f.className = 'w-100 border-0 rounded-3';
            f.style.height = '70vh';
            bodyEl.appendChild(f);
        } else {
            var img = document.createElement('img');
            img.src = url;
            img.alt = label || 'Document';
            img.className = 'img-fluid rounded-3';
            img.style.maxHeight = '70vh';
            bodyEl.appendChild(img);
        }
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        } else {
            modalEl.style.display = 'block';
            modalEl.classList.add('show');
        }
    }
    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('vkycPreviewModal');
        if (!modalEl) return;
        modalEl.addEventListener('hidden.bs.modal', function () {
            var bodyEl = document.getElementById('vkycPreviewBody');
            if (bodyEl) bodyEl.innerHTML = '';
        });
    });
    </script>
<?php bc_adm_foot(array("area" => "spadmin")); ?>
<script src="../asset/bc-admin-inline-otp.js" defer></script>
