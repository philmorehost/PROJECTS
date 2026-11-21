<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-admin-config.php");
include_once(__DIR__ . "/../func/bc-edition.php");

// §28 Vendor Portal: subscription + domain management for the vendor (bc-admin).
// Domain changes are REQUESTS: the super admin reviews them in
// bc-spadmin → Domain Requests, and on approval the domain is switched (with
// cPanel addon-domain automation when the super admin has configured cPanel).

if (!isset($get_logged_admin_details)) {
    header("Location: Login.php");
    exit();
}

$portal_vendor_id = (int)$get_logged_admin_details['id'];
$portal_vendor    = $get_logged_admin_details;

// Current domain, from the vendor record (SAAS) or the live host as a fallback.
$portal_current_domain = '';
if (!empty($portal_vendor['app_base_url'])) $portal_current_domain = (string)$portal_vendor['app_base_url'];
if ($portal_current_domain === '' && !empty($portal_vendor['website_url'])) $portal_current_domain = (string)$portal_vendor['website_url'];
if ($portal_current_domain === '' && !empty($_SERVER['HTTP_HOST'])) $portal_current_domain = (string)$_SERVER['HTTP_HOST'];
$portal_current_domain = function_exists('bc_normalize_vendor_host') ? bc_normalize_vendor_host($portal_current_domain) : strtolower(trim($portal_current_domain));

// Submit a domain change request.
if (isset($_POST['submit_domain_request'])) {
    $requested = strtolower(trim(strip_tags((string)($_POST['requested_domain'] ?? ''))));
    $requested = function_exists('bc_normalize_vendor_host') ? bc_normalize_vendor_host($requested) : $requested;
    $note = trim(substr(strip_tags((string)($_POST['vendor_note'] ?? '')), 0, 500));

    if (!function_exists('bc_is_valid_domain_name') || !bc_is_valid_domain_name($requested)) {
        $_SESSION["product_purchase_response"] = "Please enter a valid domain name, e.g. example.com (without https://).";
    } elseif ($requested === strtolower($portal_current_domain)) {
        $_SESSION["product_purchase_response"] = "That is already your current domain.";
    } else {
        // One open request at a time keeps the review queue unambiguous.
        $open_q = mysqli_query($connection_server, "SELECT id FROM sas_vendor_domain_requests WHERE vendor_id='$portal_vendor_id' AND status IN ('pending','processing') LIMIT 1");
        if ($open_q && mysqli_num_rows($open_q) > 0) {
            $_SESSION["product_purchase_response"] = "You already have a domain request awaiting review. Please wait for the super admin's decision.";
        } else {
            $cur_e  = mysqli_real_escape_string($connection_server, $portal_current_domain);
            $req_e  = mysqli_real_escape_string($connection_server, $requested);
            $note_e = mysqli_real_escape_string($connection_server, $note);
            $ok = mysqli_query($connection_server, "INSERT INTO sas_vendor_domain_requests (vendor_id, request_type, current_domain, requested_domain, vendor_note, status) VALUES ('$portal_vendor_id', 'change', '$cur_e', '$req_e', '$note_e', 'pending')");
            $_SESSION["product_purchase_response"] = $ok
                ? "Domain change request submitted. The super admin will review it; on approval your domain is switched automatically."
                : "Could not submit the request. Please try again.";
        }
    }
    header("Location: VendorPortal.php");
    exit();
}

// Subscription snapshot.
$portal_package = null;
if (!empty($portal_vendor['current_billing_id'])) {
    $pkg_q = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages WHERE id='" . (int)$portal_vendor['current_billing_id'] . "' LIMIT 1");
    $portal_package = $pkg_q ? mysqli_fetch_assoc($pkg_q) : null;
}
$portal_expiry = $portal_vendor['expiry_date'] ?? null;
$portal_days_left = $portal_expiry ? (int)floor((strtotime($portal_expiry) - time()) / 86400) : null;

// Past requests.
$portal_requests = [];
$req_q = mysqli_query($connection_server, "SELECT * FROM sas_vendor_domain_requests WHERE vendor_id='$portal_vendor_id' ORDER BY id DESC LIMIT 10");
if ($req_q) while ($r = mysqli_fetch_assoc($req_q)) $portal_requests[] = $r;

$status_badge = function ($status) {
    $map = ['pending' => 'bg-warning text-dark', 'processing' => 'bg-info', 'approved' => 'bg-success', 'rejected' => 'bg-danger'];
    $cls = $map[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . ' rounded-pill">' . strtoupper(htmlspecialchars((string)$status)) . '</span>';
};
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Vendor Portal | " . ($get_all_super_admin_site_details["site_title"] ?? 'Admin'), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "VENDOR PORTAL", "crumb" => "Home / Portal")); ?>

<section class="section dashboard">
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-box-seam me-2"></i>My Subscription</h5>
                </div>
                <div class="card-body p-4">
                    <?php if ($portal_package): ?>
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div class="h4 fw-bold mb-1"><?php echo htmlspecialchars((string)$portal_package['name']); ?> Plan</div>
                                <div class="text-muted small">Package price: ₦<?php echo number_format((float)($portal_package['price'] ?? 0), 2); ?></div>
                            </div>
                            <?php if ($portal_days_left === null): ?>
                                <span class="badge bg-success rounded-pill">Active</span>
                            <?php elseif ($portal_days_left >= 0): ?>
                                <span class="badge bg-success rounded-pill"><?php echo $portal_days_left; ?> days left</span>
                            <?php else: ?>
                                <span class="badge bg-danger rounded-pill">Expired</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($portal_expiry): ?>
                            <p class="mb-1"><span class="text-muted small">Current period ends</span><br><strong><?php echo htmlspecialchars(date('F j, Y', strtotime($portal_expiry))); ?></strong></p>
                        <?php endif; ?>
                    <?php elseif (bc_is_saas_edition()): ?>
                        <p class="text-muted">No subscription package is attached to this vendor account yet.</p>
                    <?php else: ?>
                        <p class="text-muted mb-0">This is a standalone (NON-SAAS) install — there is no vendor subscription to manage. Your licence and updates are handled inside this admin.</p>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <?php if (bc_is_saas_edition()): ?>
                        <a href="RenewSubscription.php" class="btn btn-primary rounded-pill px-4 fw-bold">Renew / Upgrade</a>
                        <a href="SubscriptionHistory.php" class="btn btn-light border rounded-pill px-4 fw-bold">Subscription History</a>
                        <?php endif; ?>
                        <?php if (is_file(__DIR__ . '/Fund.php')): ?><a href="Fund.php" class="btn btn-light border rounded-pill px-4 fw-bold">Fund Wallet</a><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-globe me-2"></i>My Domain</h5>
                </div>
                <div class="card-body p-4">
                    <p class="mb-3"><span class="text-muted small">Current domain</span><br>
                        <strong class="h5"><?php echo htmlspecialchars($portal_current_domain !== '' ? $portal_current_domain : '—'); ?></strong>
                    </p>
                    <?php if (bc_is_saas_edition()): ?>
                    <form method="post" class="mb-2">
                        <label class="form-label small fw-bold text-muted">REQUEST A NEW DOMAIN</label>
                        <div class="input-group mb-2">
                            <span class="input-group-text bg-white"><i class="bi bi-globe2"></i></span>
                            <input type="text" name="requested_domain" class="form-control" placeholder="e.g. mynewdomain.com" required>
                        </div>
                        <input type="text" name="vendor_note" class="form-control mb-3" maxlength="500" placeholder="Note for the super admin (optional)">
                        <button type="submit" name="submit_domain_request" class="btn btn-primary rounded-pill px-4 fw-bold" onclick="return confirm('Submit this domain change request for super-admin approval?')">
                            <i class="bi bi-send me-1"></i> Submit Request
                        </button>
                    </form>
                    <p class="small text-muted mb-0">The super admin reviews every request. On approval your site switches to the new domain automatically (including cPanel addon-domain setup where configured); the old domain is removed.</p>
                    <?php else: ?>
                    <p class="small text-muted mb-0">This standalone install serves from its own host. To change domains, point the new domain at this server and update your web server configuration.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (bc_is_saas_edition()): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-clock-history me-2"></i>Domain Requests</h5>
                </div>
                <div class="card-body p-0">
                    <?php if ($portal_requests): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light"><tr><th class="ps-4">Requested Domain</th><th>From</th><th>Status</th><th>Super Admin Note</th><th class="pe-4">Submitted</th></tr></thead>
                            <tbody>
                                <?php foreach ($portal_requests as $r): ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?php echo htmlspecialchars((string)$r['requested_domain']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$r['current_domain']); ?></td>
                                    <td><?php echo $status_badge((string)$r['status']); ?></td>
                                    <td class="small text-muted"><?php echo htmlspecialchars((string)($r['decision_note'] ?? '')); ?></td>
                                    <td class="pe-4 small text-muted"><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted p-4 mb-0">No domain requests yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php bc_adm_foot(array("area" => "admin")); ?>
