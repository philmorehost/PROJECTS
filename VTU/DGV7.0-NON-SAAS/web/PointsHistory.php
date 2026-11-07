<?php session_start();
include("../func/bc-config.php");

// Function to format the log_type for display
function formatLogType($log_type) {
    return ucwords(str_replace('_', ' ', strtolower($log_type)));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Points History | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
        <h1>POINTS HISTORY</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Points History</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm overflow-hidden rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-star-fill me-2"></i>Point Earned & Redeemed</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
                            <thead class="bg-light">
                                <tr>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold">DATE</th>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold">DESCRIPTION</th>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold text-end">POINTS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $username = $get_logged_user_details["username"];
                                $vendor_id = $get_logged_user_details["vendor_id"];

                                $query = "
                                    SELECT id, username, vendor_id, point_amount, log_type, date
                                    FROM sas_points_log
                                    WHERE username = ? AND vendor_id = ? AND log_type <> 'DAILY_PURCHASE_BONUS'
                                    UNION
                                    SELECT l1.id, l1.username, l1.vendor_id, l1.point_amount, l1.log_type, l1.date
                                    FROM sas_points_log l1
                                    INNER JOIN (
                                        SELECT MAX(id) as max_id
                                        FROM sas_points_log
                                        WHERE username = ? AND vendor_id = ? AND log_type = 'DAILY_PURCHASE_BONUS'
                                        GROUP BY DATE(date)
                                    ) l2 ON l1.id = l2.max_id
                                    ORDER BY date DESC";

                                $stmt = mysqli_prepare($connection_server, $query);
                                mysqli_stmt_bind_param($stmt, "sisi", $username, $vendor_id, $username, $vendor_id);
                                mysqli_stmt_execute($stmt);
                                $result = mysqli_stmt_get_result($stmt);

                                if (mysqli_num_rows($result) > 0) {
                                    while ($row = mysqli_fetch_assoc($result)) {
                                        $points_display = ($row['point_amount'] > 0) ? '+' . number_format($row['point_amount']) : number_format($row['point_amount']);
                                        $points_class = ($row['point_amount'] > 0) ? 'text-success' : 'text-danger';
                                        $bg_class = ($row['point_amount'] > 0) ? 'bg-primary' : 'bg-danger';
                                        ?>
                                        <tr>
                                            <td class="px-3 py-3">
                                                <div class="fw-bold text-dark"><?php echo date("M j, Y", strtotime($row['date'])); ?></div>
                                                <div class="small text-muted"><?php echo date("g:i a", strtotime($row['date'])); ?></div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <span class="badge bg-opacity-10 <?php echo $bg_class; ?> <?php echo $points_class; ?> rounded-pill px-3 py-2">
                                                    <?php echo formatLogType($row['log_type']); ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-3 text-end fw-bold <?php echo $points_class; ?>">
                                                <?php echo $points_display; ?>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    echo "<tr><td colspan='3' class='text-center py-5 text-muted'>No points history found.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-footer.php"); ?>
</body>
</html>