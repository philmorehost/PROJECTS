<?php session_start();
include("../func/bc-config.php");

/**
 * DGV7: Personal -> Business upgrade request (KYB).
 *
 * WHAT THIS PAGE DOES NOT DO
 *   It never sets account_type. Submitting only records a request (kyb_status = 1); a reviewer in
 *   bc-admin/BusinessKYB.php makes the decision. That ordering is the blueprint's requirement and
 *   it is why a user cannot self-promote to a business account.
 *
 * MONEY
 *   No fee is charged here. The existing upgrade-price table (sas_user_upgrade_price) still bills
 *   account_LEVEL changes through web/handle_upgrade.php and is left alone; a Business upgrade fee,
 *   if one is configured later, belongs after approval - never before it.
 *
 * Auth: bc-config.php has already redirected anyone who is not signed in.
 */

$bc_upgrade_user   = $get_logged_user_details;
$bc_upgrade_vendor = (int)($bc_upgrade_user['vendor_id'] ?? 0);
$bc_upgrade_uid    = (int)($bc_upgrade_user['id'] ?? 0);

// Read this user's own row fresh: the session copy is not guaranteed to carry the kyb_* columns.
$bc_kyb_row = [];
$q_kyb_self = mysqli_query($connection_server, "SELECT account_type, kyb_status, kyb_business_name, kyb_registration_no,
        kyb_category, kyb_document, kyb_reference, kyb_note, kyb_submitted_at, kyb_reviewed_at
    FROM sas_users WHERE id='$bc_upgrade_uid' LIMIT 1");
if ($q_kyb_self) $bc_kyb_row = mysqli_fetch_assoc($q_kyb_self) ?: [];

$bc_current_type = bc_fintech_account_type(array_merge($bc_upgrade_user, $bc_kyb_row));
$bc_kyb_status   = (int)($bc_kyb_row['kyb_status'] ?? 0);

$bc_message = '';
$bc_message_type = 'info';
// Re-render the form with what the user typed when validation fails; never re-fill on success.
$bc_old = ['business_name' => '', 'registration_no' => '', 'category' => ''];

if (isset($_POST['submit_business_kyb'])) {
    if ($bc_current_type === 'business') {
        $bc_message = 'This account is not eligible for a business upgrade.';
        $bc_message_type = 'danger';
    } elseif ($bc_kyb_status === 1) {
        $bc_message = 'Your request is already under review.';
        $bc_message_type = 'warning';
    } else {
        $bc_old = [
            'business_name'   => trim((string)($_POST['business_name'] ?? '')),
            'registration_no' => trim((string)($_POST['registration_no'] ?? '')),
            'category'        => trim((string)($_POST['category'] ?? '')),
        ];

        // ── document upload ────────────────────────────────────────────────────
        $doc_filename = '';
        $upload_ok = false;
        if (!empty($_FILES['kyb_document']['name'])) {
            $upload_dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . "/uploads/kyb/";
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

            // Registration documents are private evidence, exactly like KYC IDs: deny direct HTTP
            // access and serve them only through the authenticated reviewer.
            $htaccess_file = $upload_dir . '.htaccess';
            if (is_dir($upload_dir) && !file_exists($htaccess_file)) {
                @file_put_contents($htaccess_file, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
            }

            $tmp  = $_FILES['kyb_document']['tmp_name'];
            $err  = $_FILES['kyb_document']['error'] ?? UPLOAD_ERR_NO_FILE;
            $size = (int)($_FILES['kyb_document']['size'] ?? 0);
            $ext  = strtolower(pathinfo((string)$_FILES['kyb_document']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $bc_message = 'The document did not upload. Please try again.';
                $bc_message_type = 'danger';
            } elseif ($size > 8 * 1024 * 1024) {
                $bc_message = 'The document must be 8MB or smaller.';
                $bc_message_type = 'danger';
            } elseif (!in_array($ext, $allowed, true)) {
                $bc_message = 'Upload a PDF or an image (jpg, png, webp).';
                $bc_message_type = 'danger';
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($tmp) === false) {
                // The extension is attacker-controlled, so confirm an image really is one.
                $bc_message = 'That file is not a valid image.';
                $bc_message_type = 'danger';
            } else {
                $doc_filename = 'kyb_' . $bc_upgrade_uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($tmp, $upload_dir . $doc_filename)) {
                    $upload_ok = true;
                } else {
                    $bc_message = 'Could not store the document. Please contact support.';
                    $bc_message_type = 'danger';
                }
            }
        } else {
            $bc_message = 'Upload your business registration document.';
            $bc_message_type = 'danger';
        }

        if ($upload_ok) {
            $result = bc_fintech_kyb_submit($bc_upgrade_user, $bc_upgrade_vendor, [
                'business_name'   => $bc_old['business_name'],
                'registration_no' => $bc_old['registration_no'],
                'category'        => $bc_old['category'],
                'document'        => $doc_filename,
            ]);

            if ($result === true) {
                // Re-read so the page shows the new state immediately.
                $q_refresh = mysqli_query($connection_server, "SELECT account_type, kyb_status, kyb_business_name, kyb_registration_no,
                        kyb_category, kyb_document, kyb_reference, kyb_note, kyb_submitted_at, kyb_reviewed_at
                    FROM sas_users WHERE id='$bc_upgrade_uid' LIMIT 1");
                if ($q_refresh) $bc_kyb_row = mysqli_fetch_assoc($q_refresh) ?: $bc_kyb_row;
                $bc_kyb_status = (int)($bc_kyb_row['kyb_status'] ?? 1);
                $bc_old = ['business_name' => '', 'registration_no' => '', 'category' => ''];
                $bc_message = 'Your business upgrade request has been submitted for review.';
                $bc_message_type = 'success';
            } else {
                // Validation failed: remove the orphaned file so retries do not litter the folder.
                if ($doc_filename !== '') @unlink(rtrim($_SERVER['DOCUMENT_ROOT'], '/') . "/uploads/kyb/" . $doc_filename);
                $bc_message = is_string($result) ? $result : 'Could not save the request.';
                $bc_message_type = 'danger';
            }
        }
    }
}

$bc_categories = bc_fintech_kyb_categories();
$bc_kyb_badges = [0 => 'secondary', 1 => 'warning', 2 => 'success', 3 => 'danger'];
$bc_kyb_badge  = $bc_kyb_badges[$bc_kyb_status] ?? 'secondary';
$bc_show_form  = ($bc_current_type === 'personal' && ($bc_kyb_status === 0 || $bc_kyb_status === 3));
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Business Upgrade | " . ($get_all_site_details["site_title"] ?? 'Account'));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "BUSINESS ACCOUNT UPGRADE", "crumb" => "Home / Business Upgrade")); ?>

<section class="section">
  <div class="row g-4">

    <div class="col-lg-4">
      <div class="card upgrade-card p-4 text-center">
        <div class="mb-3">
          <?php if ($bc_current_type === 'business'): ?>
            <i class="bi bi-briefcase-fill text-success display-1"></i>
            <h4 class="fw-bold mt-2">Business account</h4>
            <p class="text-muted small mb-0">Web-only access to developer tools, invoicing, POS and cross-border payouts.</p>
          <?php elseif ($bc_kyb_status === 1): ?>
            <i class="bi bi-hourglass-split text-warning display-1"></i>
            <h4 class="fw-bold mt-2">Under review</h4>
            <p class="text-muted small mb-0">A reviewer is checking your registration document.</p>
          <?php elseif ($bc_kyb_status === 3): ?>
            <i class="bi bi-x-octagon text-danger display-1"></i>
            <h4 class="fw-bold mt-2">Not approved</h4>
            <p class="text-muted small mb-0">Correct the details below and submit again.</p>
          <?php else: ?>
            <i class="bi bi-person-badge text-secondary display-1"></i>
            <h4 class="fw-bold mt-2">Personal account</h4>
            <p class="text-muted small mb-0">Individual services and the mobile apps.</p>
          <?php endif; ?>
        </div>
        <span class="badge bg-<?php echo $bc_kyb_badge; ?> bg-opacity-10 text-<?php echo $bc_kyb_badge; ?> py-2">
          Business verification: <?php echo htmlspecialchars(bc_fintech_kyb_status_label($bc_kyb_status)); ?>
        </span>
        <?php if (!empty($bc_kyb_row['kyb_reference'])): ?>
          <div class="small text-muted mt-3">Reference<br><code><?php echo htmlspecialchars($bc_kyb_row['kyb_reference']); ?></code></div>
        <?php endif; ?>
      </div>

      <div class="card upgrade-card p-4 mt-4">
        <h6 class="fw-bold mb-3">What changes</h6>
        <ul class="small text-muted ps-3 mb-0">
          <li class="mb-2">Unlocks invoicing, POS, bulk payouts and cross-border tools.</li>
          <li class="mb-2">Developer API, docs, keys and webhooks become available.</li>
          <li class="mb-2">Business accounts are used on the <strong>website only</strong>; the mobile apps stay personal.</li>
          <li>Your pricing level is not changed by this upgrade.</li>
        </ul>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card upgrade-card p-4">
        <h5 class="fw-bold mb-1">Upgrade to a Business account</h5>
        <p class="text-muted small mb-4">
          Business accounts are for registered companies. Tell us about the business and upload the
          registration document; a reviewer will approve or reject the request.
        </p>

        <?php if ($bc_message !== ''): ?>
          <div class="alert alert-<?php echo htmlspecialchars($bc_message_type); ?> py-2 small" role="alert">
            <?php echo htmlspecialchars($bc_message); ?>
          </div>
        <?php endif; ?>

        <?php if ($bc_current_type === 'business'): ?>
          <div class="alert alert-success small mb-0">
            This account is already a business account. Your merchant tools and developer dashboard are on the website.
          </div>

        <?php elseif ($bc_kyb_status === 1): ?>
          <div class="alert alert-warning small mb-2">
            Your request for <strong><?php echo htmlspecialchars((string)($bc_kyb_row['kyb_business_name'] ?? 'your business')); ?></strong>
            is under review.
          </div>
          <table class="table table-sm small mb-0">
            <tr><th class="text-muted fw-normal" style="width:45%">Submitted</th><td><?php echo htmlspecialchars((string)($bc_kyb_row['kyb_submitted_at'] ?? '—')); ?></td></tr>
            <tr><th class="text-muted fw-normal">Registration number</th><td><?php echo htmlspecialchars((string)($bc_kyb_row['kyb_registration_no'] ?? '—')); ?></td></tr>
            <tr><th class="text-muted fw-normal">Category</th><td><?php echo htmlspecialchars($bc_categories[$bc_kyb_row['kyb_category'] ?? ''] ?? '—'); ?></td></tr>
            <tr><th class="text-muted fw-normal">Document</th><td><?php echo !empty($bc_kyb_row['kyb_document']) ? 'Received' : 'Missing'; ?></td></tr>
          </table>
          <p class="small text-muted mt-3 mb-0">Your account stays personal until the review is complete. No fee has been charged.</p>

        <?php elseif ($bc_kyb_status === 2): ?>
          <div class="alert alert-success small mb-0">
            Your business has been approved. Business features are available on the website.
          </div>
        <?php endif; ?>

        <?php if ($bc_kyb_status === 3): ?>
          <div class="alert alert-danger small">
            Your previous request was not approved.
            <?php if (!empty($bc_kyb_row['kyb_note'])): ?>
              <div class="mt-2"><strong>Reason:</strong> <?php echo htmlspecialchars($bc_kyb_row['kyb_note']); ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($bc_show_form): ?>
          <form method="post" action="" enctype="multipart/form-data" class="mt-2">
            <div class="row g-3">
              <div class="col-md-7">
                <label class="form-label" for="business_name">REGISTERED BUSINESS NAME</label>
                <input id="business_name" name="business_name" type="text" maxlength="200" required class="form-control"
                       value="<?php echo htmlspecialchars($bc_old['business_name']); ?>" />
              </div>
              <div class="col-md-5">
                <label class="form-label" for="registration_no">REGISTRATION NUMBER</label>
                <input id="registration_no" name="registration_no" type="text" maxlength="80" required class="form-control"
                       value="<?php echo htmlspecialchars($bc_old['registration_no']); ?>" />
              </div>
              <div class="col-md-6">
                <label class="form-label" for="category">BUSINESS CATEGORY</label>
                <select id="category" name="category" class="form-select" required>
                  <option value="">— choose —</option>
                  <?php foreach ($bc_categories as $cat_key => $cat_label): ?>
                    <option value="<?php echo htmlspecialchars($cat_key); ?>"<?php echo ($bc_old['category'] === $cat_key) ? ' selected' : ''; ?>>
                      <?php echo htmlspecialchars($cat_label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="kyb_document">REGISTRATION DOCUMENT</label>
                <input id="kyb_document" name="kyb_document" type="file" required
                       accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control" />
                <div class="form-text text-muted" style="font-size:0.75rem;">
                  Certificate of incorporation or equivalent. PDF or image, 8MB max. Uploaded privately.
                </div>
              </div>
            </div>
            <button name="submit_business_kyb" class="btn btn-primary rounded-pill px-4 fw-bold mt-4">
              Submit for review
            </button>
            <p class="small text-muted mt-3 mb-0">
              Your account stays personal until a reviewer approves it. No fee is charged when you submit.
            </p>
          </form>
        <?php endif; ?>
      </div>
    </div>

  </div>
</section>


	<?php bc_app_foot(); ?>
