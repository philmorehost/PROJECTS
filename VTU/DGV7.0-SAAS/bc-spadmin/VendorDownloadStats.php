<?php session_start();
include("../func/bc-spadmin-config.php");

// Fetch overall stats
$overall_stats = mysqli_query($connection_server, "SELECT COUNT(*) as total, SUM(download_count) as total_dl FROM sas_vendor_downloads");
$overall = mysqli_fetch_assoc($overall_stats);

// Fetch detailed logs
$sql = "SELECT vd.*, v.firstname, v.lastname, v.website_url,
               COALESCE(a.name, bp.name, 'Unknown Asset') as addon_name
        FROM sas_vendor_downloads vd
        JOIN sas_vendors v ON vd.vendor_id = v.id
        LEFT JOIN sas_billing_addons a ON vd.addon_id = a.id
        LEFT JOIN sas_billing_packages bp ON vd.package_id = bp.id
        ORDER BY vd.last_download_at DESC";
$logs = mysqli_query($connection_server, $sql) or die(mysqli_error($connection_server));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Vendor Download Analytics</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
        <h1>Download Analytics</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="BillingPackages.php">Billing Packages</a></li>
                <li class="breadcrumb-item active">Download Stats</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-4 bg-primary text-white p-3 mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-white bg-opacity-25 p-3 rounded-4 me-3">
                            <i class="bi bi-cloud-download fs-3"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-bold"><?php echo number_format($overall['total_dl'] ?? 0); ?></h3>
                            <p class="mb-0 small">Total Successful Downloads</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-4 bg-dark text-white p-3 mb-4">
                    <div class="d-flex align-items-center">
                        <div class="bg-white bg-opacity-25 p-3 rounded-4 me-3">
                            <i class="bi bi-person-check fs-3"></i>
                        </div>
                        <div>
                            <h3 class="mb-0 fw-bold"><?php echo number_format($overall['total'] ?? 0); ?></h3>
                            <p class="mb-0 small">Active Managed Links</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-4 border-0">
                <h5 class="fw-bold mb-0 text-primary">Managed Distribution Logs</h5>
                <p class="text-muted small mb-0">Track which vendors are accessing their digital assets</p>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="small text-uppercase text-muted">
                                <th class="ps-4">Vendor</th>
                                <th>Digital Asset (Addon)</th>
                                <th>Total DLs</th>
                                <th>Last Downloaded</th>
                                <th>Last Known IP</th>
                                <th class="text-end pe-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(mysqli_num_rows($logs) > 0): while($log = mysqli_fetch_assoc($logs)): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($log['firstname'] . ' ' . $log['lastname']); ?></div>
                                    <div class="extra-small text-muted"><?php echo htmlspecialchars($log['website_url']); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3">
                                        <i class="bi bi-file-earmark-zip me-1"></i><?php echo htmlspecialchars($log['addon_name']); ?>
                                    </span>
                                </td>
                                <td><span class="fw-bold"><?php echo $log['download_count']; ?></span></td>
                                <td class="small"><?php echo date('M d, Y H:i', strtotime($log['last_download_at'])); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                                <td class="text-end pe-4">
                                    <?php 
                                    $is_expired = strtotime($log['expiry']) < time();
                                    if($is_expired): ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-2">Link Expired</span>
                                    <?php else: ?>
                                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">Link Active</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No download activity recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
