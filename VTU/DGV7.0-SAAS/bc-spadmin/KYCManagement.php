<?php session_start();
include("../func/bc-spadmin-config.php");

// KYC status model - identical to web/api/kyc.php, the app and the vendor console:
//   0 = Unverified, 1 = Under review (pending), 2 = Verified, 3 = Rejected.
$kyc_status_labels = [0 => 'Unverified', 1 => 'Under review', 2 => 'Verified', 3 => 'Rejected'];
$kyc_status_badges = [0 => 'secondary', 1 => 'warning', 2 => 'success', 3 => 'danger'];

$kyc_doc_columns = [
    'id'     => ['col' => 'govt_id_card',     'label' => 'Government ID'],
    'selfie' => ['col' => 'kyc_face_image',   'label' => 'Selfie / live photo'],
    'poa'    => ['col' => 'proof_of_address', 'label' => 'Proof of address'],
    'video'  => ['col' => 'liveliness_video', 'label' => 'Liveliness video'],
];

// ── Global reviewer decision ─────────────────────────────────────────────────────────────────────
// This page used to act on GET (a link anywhere on the internet could approve a user) and every
// non-approve action wrote status 0 instead of 3 with no reason stored.
if (isset($_POST['kyc_decision'])) {
    bc_validate_csrf();
    $uid      = (int)($_POST['uid'] ?? 0);
    $reason   = trim(strip_tags($_POST['reason'] ?? ''));
    $decision = bc_kyc_decision($_POST['kyc_decision'] ?? '', $reason);

    $target_q = mysqli_query($connection_server, "SELECT username, email, firstname, lastname, vendor_id FROM sas_users WHERE id='$uid' LIMIT 1");
    $target   = $target_q ? mysqli_fetch_assoc($target_q) : null;

    if (!$decision || !$target) {
        $_SESSION['product_purchase_response'] = 'Error: unknown action or unknown user.';
    } elseif ($decision['status'] === 3 && $reason === '') {
        $_SESSION['product_purchase_response'] = 'Error: a rejection needs a reason, so the user knows what to fix.';
    } else {
        $reason_esc = mysqli_real_escape_string($connection_server, $decision['reason']);
        mysqli_query($connection_server, "UPDATE sas_users SET
                kyc_status='" . (int)$decision['status'] . "',
                kyc_approved_date=" . ($decision['approved_date'] ? "NOW()" : "NULL") . ",
                kyc_reject_reason='$reason_esc',
                kyc_refresh_required='" . (int)$decision['refresh_required'] . "',
                kyc_reviewed_at=NOW()
            WHERE id='$uid'");
        $flash = 'Global KYC ' . $decision['label'] . ' for @' . $target['username'] . '.';

        // Tell the user (their own vendor's SMTP, and that vendor's template if one exists).
        // sendVendorEmail() otherwise picks its sending account from the logged-in vendor/admin or from
        // resolveVendorID() - which on this host is the platform, not the user's vendor. resolveVendorID()
        // honours $GLOBALS['vendor_id'] before the host lookup, so it is set for the duration of the send.
        if (!empty($target['email'])) {
            $previous_vendor_override = $GLOBALS['vendor_id'] ?? null;
            $GLOBALS['vendor_id'] = (int)$target['vendor_id'];
            list($mail_subject, $mail_body) = bc_kyc_notification_message(
                $decision,
                $target,
                (string) getUserEmailTemplate('kyc_decision', 'subject'),
                (string) getUserEmailTemplate('kyc_decision', 'body'),
                (string) ($get_all_super_admin_site_details['site_title'] ?? '')
            );
            $mail_sent = sendVendorEmail($target['email'], $mail_subject, $mail_body);
            if ($previous_vendor_override === null) {
                unset($GLOBALS['vendor_id']);
            } else {
                $GLOBALS['vendor_id'] = $previous_vendor_override;
            }
            $flash .= $mail_sent ? ' The user has been emailed.' : ' The email to the user could not be sent.';
        }
        $_SESSION['product_purchase_response'] = $flash;
    }
    header("Location: KYCManagement.php?status=" . (int)($_POST['return_status'] ?? 1));
    exit();
}

// ── Evidence viewer (super admin: no vendor restriction, the role is cross-tenant by design) ──────
if (isset($_GET['doc'], $_GET['uid'])) {
    $uid  = (int)$_GET['uid'];
    $kind = (string)$_GET['doc'];
    if (!isset($kyc_doc_columns[$kind])) { http_response_code(400); exit('Unknown document'); }

    $col = $kyc_doc_columns[$kind]['col'];
    $q_doc = mysqli_query($connection_server, "SELECT `$col` AS f FROM sas_users WHERE id='$uid' LIMIT 1");
    $row_doc = $q_doc ? mysqli_fetch_assoc($q_doc) : null;
    $file = trim($row_doc['f'] ?? '');
    if ($file === '') { http_response_code(404); exit('No such document'); }

    $base = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kyc/');
    $path = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kyc/' . basename($file));
    if (!$base || !$path || strpos($path, $base) !== 0 || !is_file($path)) {
        http_response_code(404); exit('Document missing on disk');
    }

    $mimes = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    readfile($path);
    exit();
}

$status_filter = isset($_GET['status']) ? max(0, min(3, (int)$_GET['status'])) : 1;
$search        = trim(strip_tags($_GET['q'] ?? ''));

$search_sql = '';
if ($search !== '') {
    $s_esc = mysqli_real_escape_string($connection_server, $search);
    $search_sql = " AND (u.firstname LIKE '%$s_esc%' OR u.lastname LIKE '%$s_esc%' OR u.username LIKE '%$s_esc%'
                        OR u.email LIKE '%$s_esc%' OR u.phone_number LIKE '%$s_esc%')";
}

// NOTE: sas_vendors has no company_name column and sas_users has no date column - the previous query
// selected/ordered by both, so it failed and this queue was always empty.
$q_list = mysqli_query($connection_server, "
    SELECT u.*, COALESCE(NULLIF(v.website_url, ''), v.email) AS vendor_label, v.id AS vendor_ref
    FROM sas_users u
    LEFT JOIN sas_vendors v ON u.vendor_id = v.id
    WHERE u.kyc_status='" . (int)$status_filter . "' $search_sql
    ORDER BY u.kyc_submitted_at IS NULL, u.kyc_submitted_at DESC, u.reg_date DESC
    LIMIT 300");

$counts = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
$q_counts = mysqli_query($connection_server, "SELECT kyc_status, COUNT(*) AS c FROM sas_users GROUP BY kyc_status");
while ($q_counts && $r = mysqli_fetch_assoc($q_counts)) {
    $counts[(int)$r['kyc_status']] = (int)$r['c'];
}

$review_user = null;
if (isset($_GET['view'])) {
    $v_uid = (int)$_GET['view'];
    $q_view = mysqli_query($connection_server, "
        SELECT u.*, COALESCE(NULLIF(v.website_url, ''), v.email) AS vendor_label, v.id AS vendor_ref
        FROM sas_users u LEFT JOIN sas_vendors v ON u.vendor_id = v.id
        WHERE u.id='$v_uid' LIMIT 1");
    if ($q_view && mysqli_num_rows($q_view) == 1) $review_user = mysqli_fetch_assoc($q_view);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Global KYC Management | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
      <h1>GLOBAL KYC REVIEW</h1>
      <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li><li class="breadcrumb-item active">KYC Review</li></ol></nav>
    </div>

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">

        <?php if ($review_user): ?>
          <?php
            $ru_status = (int)$review_user['kyc_status'];
            $ru_id_label = trim((string)($review_user['kyc_id_type'] ?? ''));
            if ($ru_id_label === '') {
                $ru_id_label = !empty($review_user['bvn']) ? 'BVN' : (!empty($review_user['nin']) ? 'NIN' : 'Not stated');
            }
            $ru_id_value = !empty($review_user['bvn']) ? $review_user['bvn'] : (!empty($review_user['nin']) ? $review_user['nin'] : '');
            $ru_docs = [];
            foreach ($kyc_doc_columns as $kind => $meta) {
                if (!empty($review_user[$meta['col']])) $ru_docs[$kind] = $review_user[$meta['col']];
            }
          ?>
          <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h5 class="fw-bold mb-1 text-primary"><?php echo htmlspecialchars($review_user['firstname'] . ' ' . $review_user['lastname']); ?></h5>
                <p class="text-muted small mb-0">
                  @<?php echo htmlspecialchars($review_user['username']); ?>
                  &middot; vendor: <?php echo htmlspecialchars($review_user['vendor_label'] ?? ('#' . (int)$review_user['vendor_id'])); ?>
                  &middot; <?php echo htmlspecialchars($review_user['email']); ?>
                </p>
              </div>
              <div class="text-end">
                <span class="badge bg-<?php echo $kyc_status_badges[$ru_status] ?? 'secondary'; ?> px-3 py-2">
                  <?php echo $kyc_status_labels[$ru_status] ?? 'Unknown'; ?>
                </span>
                <div class="mt-2"><a href="KYCManagement.php?status=<?php echo $status_filter; ?>" class="btn btn-sm btn-outline-secondary rounded-pill">&larr; Back to the queue</a></div>
              </div>
            </div>
            <div class="card-body">
              <div class="row g-4">
                <div class="col-lg-7">
                  <h6 class="fw-bold mb-3">Submitted evidence</h6>
                  <?php if (empty($ru_docs)): ?>
                    <div class="alert alert-warning mb-0">No document or selfie on file for this user.</div>
                  <?php else: ?>
                    <div class="row g-3">
                      <?php foreach ($ru_docs as $kind => $fname): ?>
                        <?php
                          $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                          $is_img = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                          $is_vid = in_array($ext, ['mp4', 'mov', 'webm', 'mkv'], true);
                          $doc_url = 'KYCManagement.php?uid=' . (int)$review_user['id'] . '&doc=' . urlencode($kind);
                        ?>
                        <div class="col-md-6">
                          <div class="border rounded-4 p-2 h-100" style="background:#f8f9fa;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                              <span class="small fw-bold"><?php echo htmlspecialchars($kyc_doc_columns[$kind]['label']); ?></span>
                              <a href="<?php echo $doc_url; ?>" target="_blank" rel="noopener" class="small">Open full size</a>
                            </div>
                            <?php if ($is_img): ?>
                              <a href="<?php echo $doc_url; ?>" target="_blank" rel="noopener">
                                <img src="<?php echo $doc_url; ?>" alt="evidence" class="img-fluid rounded-3 border"
                                     style="max-height:230px; object-fit:contain; width:100%; background:#fff;">
                              </a>
                            <?php elseif ($is_vid): ?>
                              <video src="<?php echo $doc_url; ?>" controls class="w-100 rounded-3 border" style="max-height:230px; background:#000;"></video>
                            <?php else: ?>
                              <a href="<?php echo $doc_url; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-file-earmark-text"></i> Open <?php echo strtoupper($ext) ?: 'file'; ?>
                              </a>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <p class="mt-3 mb-0">
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ru_id_label); ?></span>
                    <span class="ms-2 fw-bold"><?php echo $ru_id_value !== '' ? htmlspecialchars($ru_id_value) : '<span class="text-muted">no number provided</span>'; ?></span>
                  </p>
                </div>
                <div class="col-lg-5">
                  <h6 class="fw-bold mb-2">Timeline</h6>
                  <ul class="list-unstyled small text-muted mb-4">
                    <li class="mb-1">Registered: <?php echo date('M d, Y H:i', strtotime($review_user['reg_date'])); ?></li>
                    <li class="mb-1">Submitted: <?php echo !empty($review_user['kyc_submitted_at']) ? date('M d, Y H:i', strtotime($review_user['kyc_submitted_at'])) : '<em>not recorded</em>'; ?></li>
                    <li class="mb-1">Reviewed: <?php echo !empty($review_user['kyc_reviewed_at']) ? date('M d, Y H:i', strtotime($review_user['kyc_reviewed_at'])) : '<em>not reviewed yet</em>'; ?></li>
                    <li>Approved: <?php echo !empty($review_user['kyc_approved_date']) ? date('M d, Y H:i', strtotime($review_user['kyc_approved_date'])) : '<em>&mdash;</em>'; ?></li>
                  </ul>
                  <?php if ($ru_status === 3 && !empty($review_user['kyc_reject_reason'])): ?>
                    <div class="alert alert-danger small">
                      <strong>Reason given to the user:</strong><br/>
                      <?php echo nl2br(htmlspecialchars($review_user['kyc_reject_reason'])); ?>
                    </div>
                  <?php endif; ?>
                  <h6 class="fw-bold mb-2">Decision</h6>
                  <form method="post" action="KYCManagement.php">
                    <?php echo bc_csrf_field(); ?>
                    <input type="hidden" name="uid" value="<?php echo (int)$review_user['id']; ?>">
                    <input type="hidden" name="return_status" value="<?php echo $status_filter; ?>">
                    <textarea name="reason" class="form-control mb-2" rows="2"
                      placeholder="Reason (required when rejecting)"><?php echo htmlspecialchars($ru_status === 3 ? (string)$review_user['kyc_reject_reason'] : ''); ?></textarea>
                    <div class="d-flex flex-wrap gap-2">
                      <button type="submit" name="kyc_decision" value="approve" class="btn btn-success rounded-pill px-4">Approve</button>
                      <button type="submit" name="kyc_decision" value="reject" class="btn btn-danger rounded-pill px-4">Reject</button>
                      <button type="submit" name="kyc_decision" value="reopen" class="btn btn-outline-primary rounded-pill px-4">Re-open</button>
                      <button type="submit" name="kyc_decision" value="unverify" class="btn btn-outline-secondary rounded-pill px-4">Reset to unverified</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0">
                    <h5 class="fw-bold mb-1 text-primary">Cross-Platform Verification Queue</h5>
                    <p class="text-muted small mb-0">
                        Manual (non-API) KYC submissions from every vendor. Open a submission to see the ID document and selfie.
                    </p>
                </div>
                <div class="card-body border-bottom">
                    <ul class="nav nav-pills gap-2 mb-3">
                        <?php foreach ([1, 2, 3, 0] as $st): ?>
                            <li class="nav-item">
                                <a class="nav-link rounded-pill <?php echo $status_filter === $st ? 'active' : 'text-dark'; ?>"
                                   href="KYCManagement.php?status=<?php echo $st; ?><?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>">
                                    <?php echo $kyc_status_labels[$st]; ?>
                                    <span class="badge bg-<?php echo $status_filter === $st ? 'light text-dark' : $kyc_status_badges[$st]; ?> ms-1"><?php echo $counts[$st]; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="get" action="KYCManagement.php" class="row g-2">
                        <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
                        <div class="col-md-5">
                            <input type="text" name="q" class="form-control rounded-pill" placeholder="Search name, username, email or phone"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary rounded-pill w-100">Search</button>
                        </div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Vendor</th>
                                    <th>User</th>
                                    <th>ID</th>
                                    <th>Evidence</th>
                                    <th>Submitted</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($q_list && mysqli_num_rows($q_list) > 0): ?>
                                    <?php while ($user = mysqli_fetch_assoc($q_list)): ?>
                                    <?php
                                        $u_docs = [];
                                        foreach ($kyc_doc_columns as $kind => $meta) {
                                            if (!empty($user[$meta['col']])) $u_docs[$kind] = $user[$meta['col']];
                                        }
                                        $u_id_value = !empty($user['bvn']) ? $user['bvn'] : (!empty($user['nin']) ? $user['nin'] : '');
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-primary"><?php echo htmlspecialchars($user['vendor_label'] ?? ('#' . (int)$user['vendor_id'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></div>
                                            <div class="small text-muted">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        </td>
                                        <td class="small"><?php echo $u_id_value !== '' ? htmlspecialchars($u_id_value) : '<span class="text-muted">no number</span>'; ?></td>
                                        <td>
                                            <?php if (empty($u_docs)): ?>
                                                <span class="badge bg-secondary">None</span>
                                            <?php else: ?>
                                                <?php foreach ($u_docs as $kind => $fname): ?>
                                                    <a href="KYCManagement.php?uid=<?php echo (int)$user['id']; ?>&doc=<?php echo urlencode($kind); ?>"
                                                       target="_blank" rel="noopener" class="badge bg-primary text-decoration-none">
                                                        <?php echo htmlspecialchars($kyc_doc_columns[$kind]['label']); ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <?php echo !empty($user['kyc_submitted_at']) ? date('M d, Y', strtotime($user['kyc_submitted_at'])) : '<span class="text-muted">not recorded</span>'; ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <a href="KYCManagement.php?view=<?php echo (int)$user['id']; ?>&status=<?php echo $status_filter; ?>"
                                               class="btn btn-outline-primary btn-sm rounded-pill px-3">Review</a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">No <?php echo strtolower($kyc_status_labels[$status_filter]); ?> accounts found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
