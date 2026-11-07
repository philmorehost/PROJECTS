<?php session_start();
include("../func/bc-config.php");

$history = mysqli_query($connection_server, "SELECT * FROM sas_nin_card_requests WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' AND user_id='".$get_logged_user_details["id"]."' ORDER BY date_created DESC LIMIT 50");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>NIN Slip History | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
        <h1>NIN SLIP HISTORY</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="PrintHub.php">Print Hub</a></li>
                <li class="breadcrumb-item"><a href="NINCard.php">NIN Slip</a></li>
                <li class="breadcrumb-item active">History</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <?php include("../func/service-header.php"); ?>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Recent NIN Slip Requests</h6>
                <a href="NINCard.php" class="btn btn-success btn-sm rounded-pill px-3">
                    <i class="bi bi-plus-lg me-1"></i>New Request
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">#</th>
                                <th>Reference</th>
                                <th>Name</th>
                                <th>NIN</th>
                                <th>Fee</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($history) == 0): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No NIN slip requests yet.</td></tr>
                            <?php else:
                            $i = 1;
                            while ($row = mysqli_fetch_assoc($history)):
                                $fullname = trim($row['firstname'] . ' ' . $row['middlename'] . ' ' . $row['lastname']) ?: '—';
                                $nin_masked = substr($row['nin_input'], 0, 3) . '****' . substr($row['nin_input'], -2);
                            ?>
                            <tr>
                                <td class="ps-4"><?php echo $i++; ?></td>
                                <td><code><?php echo htmlspecialchars($row['reference']); ?></code></td>
                                <td><?php echo htmlspecialchars($fullname); ?></td>
                                <td><?php echo htmlspecialchars($nin_masked); ?></td>
                                <td>₦<?php echo number_format($row['price'], 2); ?></td>
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
                                <td>
                                    <?php if ($row['status'] == 'success'): ?>
                                    <a href="ViewNINCard.php?ref=<?php echo urlencode($row['reference']); ?>" class="btn btn-outline-success btn-sm rounded-pill">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-footer.php"); ?>
</body>
</html>
