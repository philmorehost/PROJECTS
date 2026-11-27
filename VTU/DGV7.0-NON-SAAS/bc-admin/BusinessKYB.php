<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-admin-config.php");
include_once(__DIR__ . "/../func/bc-kyc-ai-analyzer.php");
include_once(__DIR__ . "/../func/bc-kyc-av-scanner.php");

/**
 * DGV7: business upgrade (KYB) review queue.
 *
 * The only place inside bc-admin where an account becomes a business account. Approval calls
 * bc_fintech_kyb_approve(), which sets account_type, records the reviewer AND revokes live app
 * sessions — business accounts are web-only, so leaving app tokens alive would contradict the
 * access policy.
 *
 * Documents are served through this page only (like KYCManagement.php): /uploads/kyb/ is denied at
 * the web server, and every read is scoped to a user that belongs to this vendor.
 */

$vid = (int)$get_logged_admin_details['id'];
$reviewer = trim((string)($get_logged_admin_details['username'] ?? $get_logged_admin_details['email'] ?? 'admin'));

// ── Evidence viewer ──────────────────────────────────────────────────────────
if (isset($_GET['doc'], $_GET['uid'])) {
    $uid = (int)$_GET['uid'];

    $q_doc = mysqli_query($connection_server, "SELECT kyb_document FROM sas_users WHERE id='$uid' AND vendor_id='$vid' LIMIT 1");
    $row_doc = $q_doc ? mysqli_fetch_assoc($q_doc) : null;
    $file = trim((string)($row_doc['kyb_document'] ?? ''));
    if ($file === '') { http_response_code(404); exit('No such document'); }

    $doc_name = basename($file);
    $base = false;
    $path = false;
    foreach ([rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/uploads/kyb/', dirname(__DIR__) . '/uploads/kyb/'] as $dir_candidate) {
        if ($dir_candidate === '/uploads/kyb/') continue;
        $cand_base = realpath($dir_candidate);
        $cand_path = $cand_base ? realpath($cand_base . DIRECTORY_SEPARATOR . $doc_name) : false;
        if ($cand_base && $cand_path && strpos($cand_path, $cand_base) === 0 && is_file($cand_path)) {
            $base = $cand_base;
            $path = $cand_path;
            break;
        }
    }
    if (!$base || !$path || strpos($path, $base) !== 0) {
        http_response_code(404); exit('Document missing on disk');
    }

    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!isset($mimes[$ext])) { http_response_code(415); exit('Unsupported document type'); }

    // Release the session lock before streaming, so a slow download cannot block this admin's
    // other tabs (same reasoning as the KYC evidence viewer).
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . $mimes[$ext]);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="kyb-document.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// ── Decisions ────────────────────────────────────────────────────────────────
$bc_review_message = '';
$bc_review_type = 'info';

if (isset($_POST['run_kyb_ai_review'])) {
    bc_validate_csrf();
    $uid = (int)($_POST['uid'] ?? 0);
    $q_target = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$uid' AND vendor_id='$vid' LIMIT 1");
    $target_user = $q_target ? mysqli_fetch_assoc($q_target) : null;
    $ai_gate = bc_kyc_is_ai_enabled($connection_server, (int)$vid);
    if (!$target_user || empty($target_user['kyb_document'])) {
        $_SESSION['product_purchase_response'] = 'Error: KYB applicant or registration document not found.';
    } elseif (empty($ai_gate['enabled'])) {
        $_SESSION['product_purchase_response'] = 'AI Compliance disabled: ' . ($ai_gate['reason'] ?? 'Enable Global AI & KYC/KYB AI to run automated document rating.');
    } else {
        $doc_name = basename((string)$target_user['kyb_document']);
        $doc_path = '';
        foreach ([rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/uploads/kyb/', dirname(__DIR__) . '/uploads/kyb/'] as $dir_cand) {
            if ($dir_cand !== '/uploads/kyb/' && is_file($dir_cand . $doc_name)) {
                $doc_path = $dir_cand . $doc_name;
                break;
            }
        }
        if ($doc_path === '') {
            $_SESSION['product_purchase_response'] = 'Error: KYB document file not found on disk.';
        } else {
            $applicant_name = trim((string)($target_user['firstname'] ?? '') . ' ' . (string)($target_user['lastname'] ?? ''));
            $report = bc_kyb_ai_analyze_document(
                $doc_path,
                (string)($target_user['kyb_business_name'] ?? ''),
                (string)($target_user['kyb_registration_no'] ?? ''),
                (string)($target_user['kyb_category'] ?? ''),
                $applicant_name
            );
            if (function_exists('bc_kyb_ai_store_report')) {
                bc_kyb_ai_store_report($connection_server, $uid, $vid, (string)($target_user['username'] ?? ''), $report);
            }
            $_SESSION['product_purchase_response'] = 'AI KYB Review complete for @' . ($target_user['username'] ?? '')
                . ' — Rating: ' . (int)($report['rating'] ?? 0) . '/100 (Grade ' . ($report['grade'] ?? 'C') . ').';
        }
    }
    header("Location: BusinessKYB.php");
    exit();
}

if (isset($_POST['kyb_decision'])) {
    bc_validate_csrf();
    if(bc_is_rate_limited('kyb_review',(string)$vid,30,300)){http_response_code(429);exit('Too many review attempts. Please wait.');}
    $uid = (int)($_POST['uid'] ?? 0);
    $decision = (string)($_POST['kyb_decision'] ?? '');
    $reason = (string)($_POST['kyb_reason'] ?? '');

    $target=bc_q_row('SELECT * FROM sas_users WHERE id=? AND vendor_id=? LIMIT 1',[$uid,$vid]);
    $expected_review=bc_kyb_review_consume($_POST['review_ticket']??null,$target,$get_logged_admin_details);
    if($expected_review===null){
        $result='Review expired, account changed, or self-review is not permitted. Reload the queue; an independent administrator must review their own account.';
    } elseif ($decision === 'approve') {
        $result = bc_fintech_kyb_approve($uid, $vid, $reviewer, $expected_review);
    } elseif ($decision === 'reject') {
        if (trim($reason) === '') {
            $result = 'Give the applicant a reason before rejecting.';
        } else {
            $result = bc_fintech_kyb_reject($uid, $vid, $reviewer, $reason, $expected_review);
        }
    } else {
        $result = 'Unknown decision.';
    }

    if ($result === true) {
        $_SESSION['product_purchase_response'] = ($decision === 'approve')
            ? 'Business account approved. App sessions for that account were revoked.'
            : 'Business upgrade request rejected.';
    } else {
        $_SESSION['product_purchase_response'] = is_string($result) ? $result : 'The decision could not be saved.';
    }
    header("Location: BusinessKYB.php");
    exit();
}

$bc_pending = bc_fintech_kyb_pending($vid, 200);

// Recently decided, for context.
$bc_decided = [];
$q_decided = mysqli_query($connection_server, "SELECT id, username, firstname, lastname, kyb_business_name, kyb_status, kyb_reviewed_at, kyb_reviewed_by
    FROM sas_users WHERE vendor_id='$vid' AND kyb_status IN (2,3) AND kyb_reviewed_at IS NOT NULL
    ORDER BY kyb_reviewed_at DESC LIMIT 20");
if ($q_decided) { while ($r = mysqli_fetch_assoc($q_decided)) $bc_decided[] = $r; }

$bc_categories = bc_fintech_kyb_categories();
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Business KYB Review | " . ($get_all_super_admin_site_details["site_title"] ?? ''), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "BUSINESS KYB REVIEW", "crumb" => "Home / Business KYB")); ?>

<section class="section dashboard">
    <?php if (!empty($_SESSION['product_purchase_response'])): ?>
        <div class="alert alert-info py-2 small" role="alert"><?php echo htmlspecialchars($_SESSION['product_purchase_response']); ?></div>
        <?php unset($_SESSION['product_purchase_response']); ?>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-briefcase me-2"></i>Pending requests (<?php echo count($bc_pending); ?>)</h5>
            <p class="small text-muted mb-0">
                Approving promotes the account to business and revokes its mobile app sessions.
                The account's pricing level is not changed.
            </p>
        </div>
        <div class="card-body p-0">
            <?php if (empty($bc_pending)): ?>
                <div class="p-4 text-muted small">No business upgrade requests are waiting.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Applicant</th>
                            <th>Business</th>
                            <th>Document</th>
                            <th>AI Review &amp; Rating</th>
                            <th>Submitted</th>
                            <th class="pe-4 text-end">Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $ai_gate_kyb = bc_kyc_is_ai_enabled($connection_server, (int)$vid);
                        foreach ($bc_pending as $row):
                        $uid = (int)$row['id'];
                        $cat = $bc_categories[$row['kyb_category'] ?? ''] ?? ($row['kyb_category'] ?? '—');
                        $kyb_report = function_exists('bc_kyb_ai_get_report') ? bc_kyb_ai_get_report($connection_server, $uid, $vid) : null;
                    ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold small"><?php echo htmlspecialchars(trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''))); ?></div>
                                <code class="small text-muted"><?php echo htmlspecialchars((string)$row['username']); ?></code>
                                <?php if (!empty($row['kyb_reference'])): ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($row['kyb_reference']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="small fw-bold"><?php echo htmlspecialchars((string)($row['kyb_business_name'] ?? '—')); ?></div>
                                <div class="small text-muted">Reg: <?php echo htmlspecialchars((string)($row['kyb_registration_no'] ?? '—')); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars((string)$cat); ?></div>
                            </td>
                            <td>
                                <?php if (!empty($row['kyb_document'])): ?>
                                    <a class="small" target="_blank" rel="noopener"
                                       href="BusinessKYB.php?doc=1&amp;uid=<?php echo $uid; ?>">
                                        <i class="bi bi-file-earmark-text me-1"></i>View document
                                    </a>
                                <?php else: ?>
                                    <span class="small text-danger">Missing</span>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:230px; max-width:300px;">
                                <?php if ($kyb_report):
                                    $kst = (string)($kyb_report['ai_status'] ?? 'kyb_review');
                                    $kbadge = $kst === 'kyb_approve' ? 'success' : ($kst === 'kyb_reject' ? 'danger' : 'warning');
                                    $krating = (int)($kyb_report['rating'] ?? 0);
                                    $kgrade = (string)($kyb_report['grade'] ?? '');
                                    $kdoc = is_array($kyb_report['document'] ?? null) ? $kyb_report['document'] : [];
                                ?>
                                    <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                                        <span class="badge bg-<?php echo $kbadge; ?>"><?php echo htmlspecialchars((string)($kyb_report['recommendation'] ?? 'AI Reviewed')); ?></span>
                                        <span class="badge bg-dark"><?php echo $krating; ?>/100<?php echo $kgrade !== '' ? ' (' . htmlspecialchars($kgrade) . ')' : ''; ?></span>
                                    </div>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)($kyb_report['reason'] ?? '')); ?></div>
                                    <?php if (!empty($kdoc)): ?>
                                        <div class="x-small text-muted mt-1">
                                            <?php if (($kdoc['business_name_matches'] ?? null) !== null): ?>
                                                Name: <strong class="text-<?php echo $kdoc['business_name_matches'] ? 'success' : 'danger'; ?>"><?php echo $kdoc['business_name_matches'] ? 'Matched' : 'Mismatch'; ?></strong> &middot;
                                            <?php endif; ?>
                                            <?php if (($kdoc['reg_no_matches'] ?? null) !== null): ?>
                                                Reg#: <strong class="text-<?php echo $kdoc['reg_no_matches'] ? 'success' : 'danger'; ?>"><?php echo $kdoc['reg_no_matches'] ? 'Matched' : 'Mismatch'; ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($kyb_report['av_scan']['scan_mode'])): ?>
                                        <div class="x-small text-success mt-1"><i class="bi bi-shield-check me-1"></i>AV: <?php echo htmlspecialchars((string)$kyb_report['av_scan']['scan_mode']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="small text-muted d-block">No AI report yet</span>
                                <?php endif; ?>
                                <?php if (!empty($ai_gate_kyb['enabled']) && !empty($row['kyb_document'])): ?>
                                    <form method="post" action="BusinessKYB.php" class="mt-1">
                                        <?php echo bc_csrf_field(); ?>
                                        <input type="hidden" name="uid" value="<?php echo $uid; ?>">
                                        <button type="submit" name="run_kyb_ai_review" value="1" class="btn btn-xs btn-outline-primary rounded-pill py-0 px-2" style="font-size:0.75rem;">
                                            <i class="bi bi-cpu me-1"></i><?php echo $kyb_report ? 'Re-run AI' : 'Run AI Review'; ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)($row['kyb_submitted_at'] ?? '')); ?></td>
                            <td class="pe-4 text-end">
                                <?php $bc_review_ticket=bc_kyb_review_ticket($row,$get_logged_admin_details); ?><form method="post" action="" class="d-inline"><?php echo bc_csrf_field(); ?><input type="hidden" name="review_ticket" value="<?php echo htmlspecialchars($bc_review_ticket,ENT_QUOTES,'UTF-8'); ?>">
                                    <input type="hidden" name="uid" value="<?php echo $uid; ?>">
                                    <button name="kyb_decision" value="approve" class="btn btn-sm btn-success rounded-pill px-3"
                                            onclick="return confirm('Approve this business and revoke its app sessions?');">
                                        Approve
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3"
                                        data-bs-toggle="collapse" data-bs-target="#reject-<?php echo $uid; ?>">
                                    Reject
                                </button>
                                <div class="collapse mt-2" id="reject-<?php echo $uid; ?>">
                                    <form method="post" action=""><?php echo bc_csrf_field(); ?><input type="hidden" name="review_ticket" value="<?php echo htmlspecialchars($bc_review_ticket,ENT_QUOTES,'UTF-8'); ?>">
                                        <input type="hidden" name="uid" value="<?php echo $uid; ?>">
                                        <textarea name="kyb_reason" rows="2" required class="form-control form-control-sm mb-2"
                                                  placeholder="Reason shown to the applicant"></textarea>
                                        <button name="kyb_decision" value="reject" class="btn btn-sm btn-danger rounded-pill px-3">
                                            Confirm rejection
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($bc_decided)): ?>
    <div class="card shadow-sm border-0 rounded-4 mt-4">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-clock-history me-2"></i>Recently decided</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th class="ps-4">Applicant</th><th>Business</th><th>Outcome</th><th>Reviewed by</th><th class="pe-4">When</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bc_decided as $row): ?>
                        <tr>
                            <td class="ps-4 small"><?php echo htmlspecialchars((string)$row['username']); ?></td>
                            <td class="small"><?php echo htmlspecialchars((string)($row['kyb_business_name'] ?? '—')); ?></td>
                            <td>
                                <?php if ((int)$row['kyb_status'] === 2): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success">Approved</span>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger">Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)($row['kyb_reviewed_by'] ?? '')); ?></td>
                            <td class="small text-muted pe-4"><?php echo htmlspecialchars((string)($row['kyb_reviewed_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</section>

    



<?php bc_adm_foot(array("area" => "admin")); ?>
