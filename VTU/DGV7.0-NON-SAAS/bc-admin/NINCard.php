<?php session_start();
include("../func/bc-admin-config.php");

// Check NIN service is enabled
if (empty($get_logged_admin_details['nin_card_enabled'])) {
    $_SESSION["product_purchase_response"] = "NIN Card Service is not activated. Please activate it in Identity Services settings.";
    header("Location: IdentityAPI.php");
    exit();
}

$history = mysqli_query($connection_server, "SELECT r.*, u.username, u.firstname AS u_firstname, u.lastname AS u_lastname FROM sas_nin_card_requests r LEFT JOIN sas_users u ON u.id=r.user_id AND u.vendor_id=r.vendor_id WHERE r.vendor_id='".$get_logged_admin_details["id"]."' ORDER BY r.date_created DESC LIMIT 200");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>NIN Card Requests | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
        <h1>NIN Card Requests</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">NIN Card</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <?php // include("../func/bc-admin-service-header.php"); ?>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">All NIN Slip Requests</h6>
                <a href="IdentityAPI.php" class="btn btn-outline-secondary btn-sm rounded-pill">Settings</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">#</th>
                                <th>Reference</th>
                                <th>User</th>
                                <th>Name on NIN</th>
                                <th>NIN</th>
                                <th>Fee</th>
                                <th>Provider</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($history) == 0): ?>
                            <tr><td colspan="9" class="text-center py-5 text-muted">No NIN slip requests yet.</td></tr>
                            <?php else:
                            $i = 1;
                            while ($row = mysqli_fetch_assoc($history)):
                                $fullname = trim($row['firstname'] . ' ' . $row['middlename'] . ' ' . $row['lastname']) ?: '—';
                                $nin_masked = substr($row['nin_input'], 0, 3) . '****' . substr($row['nin_input'], -2);
                            ?>
                            <tr>
                                <td class="ps-4"><?php echo $i++; ?></td>
                                <td><code class="small"><?php echo htmlspecialchars($row['reference']); ?></code></td>
                                <td class="small">
                                    <?php echo htmlspecialchars($row['username'] ?? '—'); ?><br>
                                    <span class="text-muted"><?php echo htmlspecialchars(($row['u_firstname'] ?? '') . ' ' . ($row['u_lastname'] ?? '')); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($fullname); ?></td>
                                <td class="small"><?php echo htmlspecialchars($nin_masked); ?></td>
                                <td>₦<?php echo number_format($row['price'], 2); ?></td>
                                <td class="small text-capitalize"><?php echo htmlspecialchars($row['provider'] ?: '—'); ?></td>
                                <td>
                                    <?php if ($row['status'] == 'success'): ?>
                                        <span class="badge bg-success rounded-pill">Success</span>
                                    <?php elseif ($row['status'] == 'failed'): ?>
                                        <span class="badge bg-danger rounded-pill">Failed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark rounded-pill">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?php echo date('d M Y, H:i', strtotime($row['date_created'])); ?></td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
