<?php session_start();
include("../func/bc-admin-config.php");

$vid = (int)$get_logged_admin_details['id'];

// KYC status model - identical to web/api/kyc.php and the mobile app's KYCVerification screen:
//   0 = Unverified, 1 = Under review (pending), 2 = Verified, 3 = Rejected.
$kyc_status_labels = [0 => 'Unverified', 1 => 'Under review', 2 => 'Verified', 3 => 'Rejected'];
$kyc_status_badges = [0 => 'secondary', 1 => 'warning', 2 => 'success', 3 => 'danger'];

// The manual (non-API) evidence a user can send. These are the columns the submission paths write:
// web/KYCVerification.php, web/api/kyc.php (mobile app) and the AI interview flow.
$kyc_doc_columns = [
    'id'     => ['col' => 'govt_id_card',     'label' => 'Government ID'],
    'selfie' => ['col' => 'kyc_face_image',   'label' => 'Selfie / live photo'],
    'poa'    => ['col' => 'proof_of_address', 'label' => 'Proof of address'],
    'video'  => ['col' => 'liveliness_video', 'label' => 'Liveliness video'],
];

// Which column satisfies which check, and the labels shown in the UI, live in func/bc-security.php
// (bc_kyc_check_map / bc_kyc_check_satisfied / bc_kyc_check_label) so that the user pages, the mobile
// endpoint and both review consoles cannot drift apart.

// This vendor's enabled KYC checks, so the reviewer can see whether a submission is complete.
// Read through the shared helper: a plain SELECT appended one entry per row, so once the settings
// table accumulated duplicate rows this list held dozens of copies of the same three checks and the
// console rendered a badge per copy. The cap is belt and braces - the page stays a fixed size even
// if the table is fed junk again.
$vendor_kyc_checks = bc_kyc_enabled_checks($connection_server, $vid);
if (count($vendor_kyc_checks) > 12) $vendor_kyc_checks = array_slice($vendor_kyc_checks, 0, 12);

// ── Reviewer decision ────────────────────────────────────────────────────────────────────────────
// POST + CSRF. These were GET links, so any page the signed-in admin loaded (or a link prefetch)
// could approve or reject a user without their knowledge. The reject path also wrote kyc_status 0
// (Unverified) rather than 3 (Rejected) and stored no reason, which made a rejected submission
// indistinguishable from an account that never submitted anything.
if (isset($_POST['kyc_decision'])) {
    bc_validate_csrf();
    $uid      = (int)($_POST['uid'] ?? 0);
    $reason   = trim(strip_tags($_POST['reason'] ?? ''));
    $decision = bc_kyc_decision($_POST['kyc_decision'] ?? '', $reason);

    $owned_q = mysqli_query($connection_server, "SELECT username, email, firstname, lastname FROM sas_users WHERE id='$uid' AND vendor_id='$vid' LIMIT 1");
    $owned   = $owned_q ? mysqli_fetch_assoc($owned_q) : null;

    if (!$decision || !$owned) {
        $_SESSION['product_purchase_response'] = 'Error: unknown action, or that account is not one of your users.';
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
            WHERE id='$uid' AND vendor_id='$vid'");
        $flash = 'KYC ' . $decision['label'] . ' for @' . $owned['username'] . '.';

        // Tell the user. Without this a rejection is only discovered if they happen to reopen the KYC
        // page, so a rejected submission looked like nothing had happened at all. The wording can be
        // overridden with an email template of type kyc_decision.
        if (!empty($owned['email'])) {
            list($mail_subject, $mail_body) = bc_kyc_notification_message(
                $decision,
                $owned,
                (string) getUserEmailTemplate('kyc_decision', 'subject'),
                (string) getUserEmailTemplate('kyc_decision', 'body'),
                (string) ($get_all_super_admin_site_details['site_title'] ?? '')
            );
            $flash .= sendVendorEmail($owned['email'], $mail_subject, $mail_body)
                ? ' The user has been emailed.'
                : ' The email to the user could not be sent.';
        }
        $_SESSION['product_purchase_response'] = $flash;
    }
    header("Location: KYCManagement.php?status=" . (int)($_POST['return_status'] ?? 1)
        . "&page=" . max(1, (int)($_POST['return_page'] ?? 1))
        . "&per_page=" . (int)($_POST['return_per_page'] ?? 25));
    exit();
}

// ── Evidence viewer ──────────────────────────────────────────────────────────────────────────────
// ID documents and selfies sit in /uploads/kyc, which is denied at the web server (the upload paths
// write an .htaccess there), so this is the only way a vendor can read them - and it is scoped to a
// user that belongs to this vendor.
if (isset($_GET['doc'], $_GET['uid'])) {
    $uid  = (int)$_GET['uid'];
    $kind = (string)$_GET['doc'];
    if (!isset($kyc_doc_columns[$kind])) { http_response_code(400); exit('Unknown document'); }

    $col = $kyc_doc_columns[$kind]['col'];
    $q_doc = mysqli_query($connection_server, "SELECT `$col` AS f FROM sas_users WHERE id='$uid' AND vendor_id='$vid' LIMIT 1");
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

    // Streaming a large video used to keep BOTH the PHP worker and the session file lock busy for as
    // long as the browser felt like downloading. Every other page in the same admin session then
    // blocked on session_start() and Chrome reported the tab as hung (RESULT_CODE_HUNG). So: release
    // the session first, advertise byte ranges, and send the file in chunks so a seek/scrub request
    // finishes immediately instead of pulling tens of megabytes. The session is no longer needed here
    // - authorisation above has already been decided.
    session_write_close();

    $file_size = (int)filesize($path);
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('Accept-Ranges: bytes');

    $start = 0;
    $end   = $file_size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $file_size - (int)$m[2]);
        } else {
            $start = (int)($m[1] !== '' ? $m[1] : 0);
            if ($m[2] !== '') $end = min($end, (int)$m[2]);
        }
        if ($file_size === 0 || $start > $end || $start >= $file_size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $file_size);
            exit();
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
    }

    $remaining = $end - $start + 1;
    header('Content-Length: ' . $remaining);

    while (ob_get_level() > 0) { ob_end_clean(); }

    $fp = fopen($path, 'rb');
    if ($fp === false) { http_response_code(500); exit(); }
    if ($start > 0) fseek($fp, $start);

    while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, (int)min(262144, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit();
}

// ── Listing ──────────────────────────────────────────────────────────────────────────────────────
$status_filter = isset($_GET['status']) ? max(0, min(3, (int)$_GET['status'])) : 1;
$search        = trim(strip_tags($_GET['q'] ?? ''));

$where = "vendor_id='$vid' AND kyc_status='" . (int)$status_filter . "'";
if ($search !== '') {
    $s_esc = mysqli_real_escape_string($connection_server, $search);
    $where .= " AND (firstname LIKE '%$s_esc%' OR lastname LIKE '%$s_esc%' OR username LIKE '%$s_esc%'
                     OR email LIKE '%$s_esc%' OR phone_number LIKE '%$s_esc%')";
}
// If the kyc_submitted_at column has not been migrated yet, fall back to reg_date ordering rather
// than letting the whole list fail.
$order = "reg_date DESC";
$has_submitted_col = false;
$q_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_users LIKE 'kyc_submitted_at'");
if ($q_col && mysqli_num_rows($q_col) > 0) {
    $has_submitted_col = true;
    // NULLs sort last in a DESC order, so the old "kyc_submitted_at IS NULL" first key was doing
    // nothing except forcing MySQL to filesort every matching row before it could apply the LIMIT.
    $order = "kyc_submitted_at DESC, reg_date DESC";
}

// ── Paging ────────────────────────────────────────────────────────────────────────────────────────
// This queue used to render the newest 300 rows in one request: thousands of table cells, one PHP
// request per evidence link, and every row's checks evaluated in between. On a real user base the
// tab itself dies (Chrome RESULT_CODE_HUNG). Anything that is "too many" is now a page size, so the
// page stays the same size whoever is in the queue.
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, array(25, 50, 100, 200), true)) $per_page = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$total_rows = 0;
$q_total = mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_users WHERE $where");
if ($q_total && ($r_total = mysqli_fetch_assoc($q_total))) $total_rows = (int)$r_total['c'];

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$first_on_page = $total_rows > 0 ? $offset + 1 : 0;
$last_on_page  = min($offset + $per_page, $total_rows);

$q_list = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE $where ORDER BY $order LIMIT $per_page OFFSET $offset");

// Counts per status, for the tabs - one grouped query, not one per tab.
$counts = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
$q_counts = mysqli_query($connection_server, "SELECT kyc_status, COUNT(*) AS c FROM sas_users WHERE vendor_id='$vid' GROUP BY kyc_status");
while ($q_counts && $r = mysqli_fetch_assoc($q_counts)) {
    $counts[(int)$r['kyc_status']] = (int)$r['c'];
}

// Keeps the current filter, search and page size on every link this page builds, so paging or
// opening a user never silently loses the filter you are working in.
$kyc_link = function (array $over = array()) use ($status_filter, $search, $per_page) {
    $qs = array_merge(array('status' => $status_filter, 'per_page' => $per_page), $over);
    if ($search !== '') $qs['q'] = $search;
    foreach ($qs as $k => $v) { if ($v === null || $v === '') unset($qs[$k]); }
    return 'KYCManagement.php?' . http_build_query($qs);
};

// The single user being reviewed, when the reviewer opened the detail view.
$review_user = null;
if (isset($_GET['view'])) {
    $v_uid = (int)$_GET['view'];
    $q_view = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE id='$v_uid' AND vendor_id='$vid' LIMIT 1");
    if ($q_view && mysqli_num_rows($q_view) == 1) $review_user = mysqli_fetch_assoc($q_view);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>KYC Management | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1>KYC MANAGEMENT</h1>
      <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li><li class="breadcrumb-item active">KYC Management</li></ol></nav>
    </div>

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">

        <?php if ($review_user): ?>
          <?php
            $ru_status = (int)$review_user['kyc_status'];
            $ru_evidence = [];
            foreach ($kyc_doc_columns as $kind => $meta) {
                if (!empty($review_user[$meta['col']])) { $ru_evidence[$kind] = $review_user[$meta['col']]; }
            }
            $ru_id_label = trim((string)($review_user['kyc_id_type'] ?? ''));
            if ($ru_id_label === '') {
                $ru_id_label = !empty($review_user['bvn']) ? 'BVN' : (!empty($review_user['nin']) ? 'NIN' : 'Not stated');
            }
            $ru_id_value = !empty($review_user['bvn']) ? $review_user['bvn'] : (!empty($review_user['nin']) ? $review_user['nin'] : '');
          ?>
          <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h5 class="fw-bold mb-1 text-primary">Reviewing <?php echo htmlspecialchars($review_user['firstname'] . ' ' . $review_user['lastname']); ?></h5>
                <p class="text-muted small mb-0">
                  @<?php echo htmlspecialchars($review_user['username']); ?>
                  &middot; <?php echo htmlspecialchars($review_user['email']); ?>
                  &middot; <?php echo htmlspecialchars($review_user['phone_number']); ?>
                </p>
              </div>
              <div class="text-end">
                <span class="badge bg-<?php echo $kyc_status_badges[$ru_status] ?? 'secondary'; ?> px-3 py-2">
                  <?php echo $kyc_status_labels[$ru_status] ?? 'Unknown'; ?>
                </span>
                <div class="mt-2"><a href="<?php echo htmlspecialchars($kyc_link(array('view' => null, 'page' => $page))); ?>" class="btn btn-sm btn-outline-secondary rounded-pill">&larr; Back to the queue</a></div>
              </div>
            </div>
            <div class="card-body">
              <div class="row g-4">
                <div class="col-lg-7">
                  <h6 class="fw-bold mb-3">Submitted evidence</h6>
                  <?php if (empty($ru_evidence)): ?>
                    <div class="alert alert-warning mb-0">
                      <strong>Nothing uploaded.</strong> This account has no ID document, selfie, proof of address or video on file
                      <?php echo $ru_id_value !== '' ? '- only the ID number below.' : 'and no ID number either.'; ?>
                      Ask them to submit through the app or the website KYC page.
                    </div>
                  <?php else: ?>
                    <div class="row g-3">
                      <?php foreach ($ru_evidence as $kind => $fname): ?>
                        <?php
                          $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                          $is_img = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                          $is_vid = in_array($ext, ['mp4', 'mov', 'webm', 'mkv'], true);
                          $doc_url = 'KYCManagement.php?uid=' . (int)$review_user['id'] . '&doc=' . urlencode($kind);
                        ?>
                        <div class="col-md-6">
                          <div class="border rounded-4 p-2 h-100" style="background:#f8f9fa;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                              <span class="small fw-bold"><?php echo htmlspecialchars($kyc_doc_columns[$kind]['label']); ?><?php echo ($kind === 'selfie' && !empty($review_user['liveliness_picture'])) ? ' <span class="badge bg-success">live capture</span>' : ''; ?></span>
                              <a href="<?php echo $doc_url; ?>" target="_blank" rel="noopener" class="small">Open full size</a>
                            </div>
                            <?php if ($is_img): ?>
                              <a href="<?php echo $doc_url; ?>" target="_blank" rel="noopener">
                                <img src="<?php echo $doc_url; ?>" alt="<?php echo htmlspecialchars($kyc_doc_columns[$kind]['label']); ?>"
                                     loading="lazy" decoding="async"
                                     class="img-fluid rounded-3 border" style="max-height:230px; object-fit:contain; width:100%; background:#fff;">
                              </a>
                            <?php elseif ($is_vid): ?>
                              <!-- preload="none": a liveliness video is tens of megabytes, and letting the browser
                                   fetch it while the admin is still reading the page is what pinned a PHP worker
                                   (and the session lock) for minutes. -->
                              <video src="<?php echo $doc_url; ?>" controls preload="none" class="w-100 rounded-3 border" style="max-height:230px; background:#000;"></video>
                              <div class="small text-muted mt-2">Click play to load the video, or open it in a new tab.</div>
                            <?php else: ?>
                              <a href="<?php echo $doc_url; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-file-earmark-text"></i> Open <?php echo strtoupper($ext) ?: 'file'; ?>
                              </a>
                              <div class="small text-muted mt-2"><?php echo htmlspecialchars($fname); ?></div>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <p class="small text-muted mt-3 mb-0">
                      Compare the face in the selfie with the photo on the government ID before approving.
                    </p>
                  <?php endif; ?>

                  <h6 class="fw-bold mt-4 mb-2">Identity number</h6>
                  <p class="mb-1">
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ru_id_label); ?></span>
                    <span class="ms-2 fw-bold"><?php echo $ru_id_value !== '' ? htmlspecialchars($ru_id_value) : '<span class="text-muted">not provided</span>'; ?></span>
                  </p>
                  <?php if (!empty($review_user['kyc_api_verified'])): ?>
                    <p class="small text-success mb-1">
                      <i class="bi bi-patch-check-fill"></i>
                      Verified with <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($review_user['kyc_provider'] ?? '')))); ?>
                      <?php echo !empty($review_user['kyc_api_verified_at']) ? 'on ' . date('M d, Y H:i', strtotime($review_user['kyc_api_verified_at'])) : ''; ?>
                      <?php if (!empty($review_user['kyc_provider_ref'])): ?>&middot; ref <?php echo htmlspecialchars($review_user['kyc_provider_ref']); ?><?php endif; ?>
                      &mdash; the provider matched this number to this account holder, so it needs no second look.
                    </p>
                  <?php elseif ($ru_id_value !== ''): ?>
                    <p class="small text-warning mb-1">
                      <i class="bi bi-exclamation-triangle"></i>
                      Typed in by the user and <strong>not</strong> checked with an identity provider. Confirm it against the document before approving.
                    </p>
                  <?php endif; ?>
                  <p class="small text-muted">
                    A missing number is not a rejection on its own: an ID document can carry the number instead.
                  </p>
                </div>

                <div class="col-lg-5">
                  <h6 class="fw-bold mb-3">Your required checks</h6>
                  <?php if (empty($vendor_kyc_checks)): ?>
                    <p class="small text-muted">No checks are enabled for this vendor, so this submission is optional.</p>
                  <?php else: ?>
                    <ul class="list-unstyled mb-4">
                      <?php foreach ($vendor_kyc_checks as $chk): ?>
                        <?php $ok = bc_kyc_check_satisfied($chk, $review_user); ?>
                        <li class="mb-2">
                          <i class="bi <?php echo $ok ? 'bi-check-circle-fill text-success' : 'bi-dash-circle text-muted'; ?> me-2"></i>
                          <?php echo htmlspecialchars(bc_kyc_check_label($chk)); ?>
                          <span class="small text-muted"><?php echo $ok ? '- submitted' : '- missing'; ?></span>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>

                  <h6 class="fw-bold mb-2">Timeline</h6>
                  <ul class="list-unstyled small text-muted mb-4">
                    <li class="mb-1">Registered: <?php echo date('M d, Y H:i', strtotime($review_user['reg_date'])); ?></li>
                    <li class="mb-1">Submitted for review:
                      <?php echo !empty($review_user['kyc_submitted_at']) ? date('M d, Y H:i', strtotime($review_user['kyc_submitted_at'])) : '<em>not recorded</em>'; ?>
                    </li>
                    <li class="mb-1">Last reviewed:
                      <?php echo !empty($review_user['kyc_reviewed_at']) ? date('M d, Y H:i', strtotime($review_user['kyc_reviewed_at'])) : '<em>not reviewed yet</em>'; ?>
                    </li>
                    <li>Approved on:
                      <?php echo !empty($review_user['kyc_approved_date']) ? date('M d, Y H:i', strtotime($review_user['kyc_approved_date'])) : '<em>&mdash;</em>'; ?>
                    </li>
                  </ul>

                  <?php if ($ru_status === 3 && !empty($review_user['kyc_reject_reason'])): ?>
                    <div class="alert alert-danger small">
                      <strong>Rejection reason sent to the user:</strong><br/>
                      <?php echo nl2br(htmlspecialchars($review_user['kyc_reject_reason'])); ?>
                    </div>
                  <?php endif; ?>

                  <h6 class="fw-bold mb-2">Decision</h6>
                  <form method="post" action="KYCManagement.php">
                    <?php echo bc_csrf_field(); ?>
                    <input type="hidden" name="uid" value="<?php echo (int)$review_user['id']; ?>">
                    <input type="hidden" name="return_status" value="<?php echo $status_filter; ?>">
                    <div class="mb-2">
                      <textarea name="reason" class="form-control" rows="2"
                        placeholder="Reason (required when rejecting) - the user is shown this."><?php echo htmlspecialchars($ru_status === 3 ? (string)$review_user['kyc_reject_reason'] : ''); ?></textarea>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                      <button type="submit" name="kyc_decision" value="approve" class="btn btn-success rounded-pill px-4"
                        onclick="return confirm('Approve this user\'s KYC?');">Approve</button>
                      <button type="submit" name="kyc_decision" value="reject" class="btn btn-danger rounded-pill px-4">Reject</button>
                      <button type="submit" name="kyc_decision" value="reopen" class="btn btn-outline-primary rounded-pill px-4">Re-open</button>
                      <button type="submit" name="kyc_decision" value="unverify" class="btn btn-outline-secondary rounded-pill px-4">Reset to unverified</button>
                    </div>
                  </form>
                  <p class="small text-muted mt-2 mb-0">
                    Approving gives the user full access (kyc_status = Verified). Rejecting stores the reason so they can fix it and resubmit.
                  </p>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0">
                    <h5 class="fw-bold mb-1 text-primary">Identity Verifications</h5>
                    <p class="text-muted small mb-0">
                        Manual (non-API) KYC: review the ID document and live photo a user uploaded, then approve or reject.
                    </p>
                </div>
                <div class="card-body border-bottom">
                    <ul class="nav nav-pills gap-2 mb-3">
                        <?php foreach ([1, 2, 3, 0] as $st): ?>
                            <li class="nav-item">
                                <a class="nav-link rounded-pill <?php echo $status_filter === $st ? 'active' : 'text-dark'; ?>"
                                   href="<?php echo htmlspecialchars($kyc_link(array('status' => $st, 'page' => null))); ?>">
                                    <?php echo $kyc_status_labels[$st]; ?>
                                    <span class="badge bg-<?php echo $status_filter === $st ? 'light text-dark' : $kyc_status_badges[$st]; ?> ms-1"><?php echo $counts[$st]; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="get" action="KYCManagement.php" class="row g-2 align-items-center">
                        <input type="hidden" name="status" value="<?php echo (int)$status_filter; ?>">
                        <input type="hidden" name="per_page" value="<?php echo (int)$per_page; ?>">
                        <div class="col-md-5">
                            <input type="text" name="q" class="form-control rounded-pill" placeholder="Search name, username, email or phone"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary rounded-pill w-100">Search</button>
                        </div>
                        <?php if ($search !== ''): ?>
                        <div class="col-md-2">
                            <a href="<?php echo htmlspecialchars($kyc_link(array('q' => null, 'page' => null))); ?>" class="btn btn-outline-secondary rounded-pill w-100">Clear</a>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3 ms-auto">
                            <label class="small text-muted d-block mb-1">Rows per page</label>
                            <select class="form-select form-select-sm rounded-pill" onchange="if(this.value){location.href=this.value;}">
                                <?php foreach ([25, 50, 100, 200] as $pp): ?>
                                    <option value="<?php echo htmlspecialchars($kyc_link(array('per_page' => $pp, 'page' => null))); ?>" <?php echo $per_page === $pp ? 'selected' : ''; ?>><?php echo $pp; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <p class="small text-muted mb-0 mt-2">
                                <?php if ($total_rows > 0): ?>
                                    Showing <strong><?php echo $first_on_page; ?>&ndash;<?php echo $last_on_page; ?></strong> of
                                    <strong><?php echo number_format($total_rows); ?></strong>
                                    <?php echo strtolower($kyc_status_labels[$status_filter]); ?> account<?php echo $total_rows === 1 ? '' : 's'; ?>
                                    <?php if ($search !== '') echo ' matching &ldquo;' . htmlspecialchars($search) . '&rdquo;'; ?>
                                    <?php if ($total_pages > 1) echo ' &middot; page ' . (int)$page . ' of ' . (int)$total_pages; ?>
                                <?php else: ?>
                                    No <?php echo strtolower($kyc_status_labels[$status_filter]); ?> accounts<?php echo $search !== '' ? ' matching that search' : ''; ?>.
                                <?php endif; ?>
                            </p>
                        </div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">User</th>
                                    <th>ID</th>
                                    <th>Evidence</th>
                                    <th>Checks</th>
                                    <th>Submitted</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($q_list && mysqli_num_rows($q_list) > 0): ?>
                                    <?php while ($user = mysqli_fetch_assoc($q_list)): ?>
                                    <?php
                                        $u_status = (int)$user['kyc_status'];
                                        $u_docs = [];
                                        foreach ($kyc_doc_columns as $kind => $meta) {
                                            if (!empty($user[$meta['col']])) $u_docs[$kind] = $user[$meta['col']];
                                        }
                                        $u_id_label = trim((string)($user['kyc_id_type'] ?? ''));
                                        if ($u_id_label === '') {
                                            $u_id_label = !empty($user['bvn']) ? 'BVN' : (!empty($user['nin']) ? 'NIN' : '');
                                        }
                                        $u_id_value = !empty($user['bvn']) ? $user['bvn'] : (!empty($user['nin']) ? $user['nin'] : '');
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold"><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></div>
                                            <div class="small text-muted">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($u_id_label !== ''): ?><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($u_id_label); ?></span><?php endif; ?>
                                            <div class="fw-bold small"><?php echo $u_id_value !== '' ? htmlspecialchars($u_id_value) : '<span class="text-muted">no number</span>'; ?><?php if (!empty($user['kyc_api_verified'])): ?><span class="badge bg-success ms-1" title="Checked with <?php echo htmlspecialchars((string)($user['kyc_provider'] ?? 'the provider')); ?>">API</span><?php elseif ($u_id_value !== ''): ?><span class="badge bg-warning text-dark ms-1" title="Not checked with an identity provider">unverified</span><?php endif; ?></div>
                                        </td>
                                        <td>
                                            <?php if (empty($u_docs)): ?>
                                                <span class="badge bg-secondary">None uploaded</span>
                                            <?php else: ?>
                                                <?php foreach ($u_docs as $kind => $fname): ?>
                                                    <?php $doc_url = 'KYCManagement.php?uid=' . (int)$user['id'] . '&doc=' . urlencode($kind); ?>
                                                    <a href="<?php echo $doc_url; ?>" target="_blank" rel="noopener"
                                                       class="badge bg-primary text-decoration-none">
                                                        <?php echo htmlspecialchars($kyc_doc_columns[$kind]['label']); ?><?php echo ($kind === 'selfie' && !empty($user['liveliness_picture'])) ? ' &middot; live' : ''; ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (empty($vendor_kyc_checks)): ?>
                                                <span class="small text-muted">optional</span>
                                            <?php else: ?>
                                                <?php foreach ($vendor_kyc_checks as $chk): ?>
                                                    <?php $ok = bc_kyc_check_satisfied($chk, $user); ?>
                                                    <span class="badge bg-<?php echo $ok ? 'success' : 'light text-muted border'; ?>" title="<?php echo htmlspecialchars(bc_kyc_check_label($chk)); ?>">
                                                        <?php echo htmlspecialchars(bc_kyc_check_label($chk)); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($user['kyc_submitted_at'])): ?>
                                                <div><?php echo date('M d, Y', strtotime($user['kyc_submitted_at'])); ?></div>
                                                <div class="small text-muted"><?php echo date('H:i', strtotime($user['kyc_submitted_at'])); ?></div>
                                            <?php else: ?>
                                                <div class="small text-muted">not recorded</div>
                                                <div class="small text-muted">(profile <?php echo date('M d, Y', strtotime($user['reg_date'])); ?>)</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center text-nowrap">
                                            <a href="<?php echo htmlspecialchars($kyc_link(array('view' => (int)$user['id'], 'page' => $page))); ?>"
                                               class="btn btn-outline-primary btn-sm rounded-pill px-3">Review</a>
                                            <?php if ($u_status === 1): ?>
                                            <form method="post" action="KYCManagement.php" class="d-inline">
                                                <?php echo bc_csrf_field(); ?>
                                                <input type="hidden" name="uid" value="<?php echo (int)$user['id']; ?>">
                                                <input type="hidden" name="return_status" value="<?php echo (int)$status_filter; ?>">
                                                <input type="hidden" name="return_page" value="<?php echo (int)$page; ?>">
                                                <input type="hidden" name="return_per_page" value="<?php echo (int)$per_page; ?>">
                                                <button type="submit" name="kyc_decision" value="approve" class="btn btn-success btn-sm rounded-pill px-3"
                                                        onclick="return confirm('Approve @<?php echo htmlspecialchars($user['username']); ?> without opening the file?');">Approve</button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">
                                        No <?php echo strtolower($kyc_status_labels[$status_filter]); ?> accounts<?php echo $search !== '' ? ' matching that search' : ''; ?>.
                                    </td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3 border-top">
                        <div class="small text-muted">
                            Page <strong><?php echo (int)$page; ?></strong> of <?php echo (int)$total_pages; ?>
                            &middot; <?php echo number_format($total_rows); ?> account<?php echo $total_rows === 1 ? '' : 's'; ?>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($kyc_link(array('page' => 1))); ?>">&laquo; First</a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($kyc_link(array('page' => $page - 1))); ?>">Prev</a>
                                </li>
                                <?php
                                    // Five page numbers centred on the current page, so the strip stays short
                                    // however many pages the queue has.
                                    $win_start = max(1, $page - 2);
                                    $win_end   = min($total_pages, $win_start + 4);
                                    $win_start = max(1, $win_end - 4);
                                    for ($p = $win_start; $p <= $win_end; $p++):
                                ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($kyc_link(array('page' => $p))); ?>"><?php echo $p; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($kyc_link(array('page' => min($total_pages, $page + 1)))); ?>">Next</a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($kyc_link(array('page' => $total_pages))); ?>">Last &raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
      </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>