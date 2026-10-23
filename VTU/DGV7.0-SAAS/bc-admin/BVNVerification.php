<?php session_start();
include("../func/bc-admin-config.php");

// Check BVN service is enabled
if (empty($get_logged_admin_details['bvn_verify_enabled'])) {
    $_SESSION["product_purchase_response"] = "BVN Verification Service is not activated. Please activate it in Identity Services settings.";
    header("Location: IdentityAPI.php");
    exit();
}

$history = mysqli_query($connection_server, "SELECT r.*, u.username, u.firstname AS u_firstname, u.lastname AS u_lastname FROM sas_bvn_verify_requests r LEFT JOIN sas_users u ON u.id=r.user_id AND u.vendor_id=r.vendor_id WHERE r.vendor_id='".$get_logged_admin_details["id"]."' ORDER BY r.date_created DESC LIMIT 200");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>BVN Verification Requests | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
        <h1>BVN Verification Requests</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">BVN Verification</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <?php // include("../func/bc-admin-service-header.php"); ?>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-fingerprint me-2 text-primary"></i>All BVN Verification Requests</h6>
                <a href="IdentityAPI.php" class="btn btn-outline-secondary btn-sm rounded-pill">
                    <i class="bi bi-gear me-1"></i>Settings
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Reference</th>
                                <th>User</th>
                                <th>BVN (Masked)</th>
                                <th>Full Name</th>
                                <th>Date of Birth</th>
                                <th>Gender</th>
                                <th>Phone</th>
                                <th>Bank of Enrolment</th>
                                <th>Fee</th>
                                <th>Provider</th>
                                <th>Status</th>
                                <th class="pe-4">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (mysqli_num_rows($history) > 0):
                            while ($row = mysqli_fetch_assoc($history)):
                                switch($row['status']) {
                                    case 'success':
                                        $status_badge = '<span class="badge bg-success-soft text-success rounded-pill">Success</span>';
                                        break;
                                    case 'failed':
                                        $status_badge = '<span class="badge bg-danger-soft text-danger rounded-pill">Failed</span>';
                                        break;
                                    default:
                                        $status_badge = '<span class="badge bg-warning-soft text-warning rounded-pill">Pending</span>';
                                }
                                $fullname = trim(implode(' ', array_filter([$row['firstname'], $row['middlename'], $row['lastname']])));
                        ?>
                            <tr>
                                <td class="ps-4 small font-monospace"><?php echo htmlspecialchars($row['reference']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($row['u_firstname'] . ' ' . $row['u_lastname']); ?></div>
                                    <div class="small text-muted">@<?php echo htmlspecialchars($row['username']); ?></div>
                                </td>
                                <td class="font-monospace"><?php echo htmlspecialchars($row['bvn_input']); ?></td>
                                <td><?php echo htmlspecialchars($fullname ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['birthdate'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['gender'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['phone'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['bank_of_enrolment'] ?: '—'); ?></td>
                                <td>₦<?php echo number_format($row['price'], 2); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo ucfirst($row['provider']); ?></span></td>
                                <td><?php echo $status_badge; ?></td>
                                <td class="pe-4 small text-muted"><?php echo date('M d, Y g:ia', strtotime($row['date_created'])); ?></td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                    No BVN verification requests yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-footer.php"); ?>
    <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
