<?php session_start();
    include("../func/bc-spadmin-config.php");

    // --- Fetch Report Data ---

    // 1. Estimated Total Revenue from active subscriptions
    $revenue_sql = "SELECT SUM(bp.price) as total_revenue
                    FROM sas_vendors v
                    JOIN sas_billing_packages bp ON v.current_billing_id = bp.id
                    WHERE v.status = 1 AND v.expiry_date >= CURDATE()";
    $revenue_res = mysqli_query($connection_server, $revenue_sql);
    $total_revenue = mysqli_fetch_assoc($revenue_res)['total_revenue'] ?? 0;

    // 2. Number of Active Subscriptions
    $active_sql = "SELECT COUNT(id) as active_count FROM sas_vendors WHERE status = 1 AND expiry_date >= CURDATE()";
    $active_res = mysqli_query($connection_server, $active_sql);
    $active_subscriptions = mysqli_fetch_assoc($active_res)['active_count'] ?? 0;

    // 3. New Vendors in the last 30 days
    $new_vendors_sql = "SELECT COUNT(id) as new_count FROM sas_vendors WHERE reg_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $new_vendors_res = mysqli_query($connection_server, $new_vendors_sql);
    $new_vendors_last_30_days = mysqli_fetch_assoc($new_vendors_res)['new_count'] ?? 0;

    // 4. Most Popular Subscription Package
    $popular_sql = "SELECT bp.name, COUNT(v.id) as count
                    FROM sas_vendors v
                    JOIN sas_billing_packages bp ON v.current_billing_id = bp.id
                    GROUP BY v.current_billing_id
                    ORDER BY count DESC
                    LIMIT 1";
    $popular_res = mysqli_query($connection_server, $popular_sql);
    $popular_package = mysqli_fetch_assoc($popular_res);
    $most_popular_package = $popular_package ? $popular_package['name'] . " (" . $popular_package['count'] . " vendors)" : "N/A";

?>
<!DOCTYPE html>
<head>
    <title>Subscription Reports</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .info-card { border-radius: 15px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .card-blue { background-color: #eef7ff; }
        .card-red { background-color: #fceeed; }
        .card-green { background-color: #eefcef; }
        .card-yellow { background-color: #fff9e6; }
    </style>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
        <h1>Subscription Reports</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row g-4">

            <!-- Total Revenue Card -->
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 rounded-4 h-100" style="background: linear-gradient(135deg, #287bff 0%, #1a56cc 100%);">
                    <div class="card-body p-4 text-white">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0 opacity-75">Estimated Revenue</h6>
                            <i class="bi bi-currency-exchange fs-4"></i>
                        </div>
                        <h3 class="fw-bold mb-1">₦<?php echo number_format($total_revenue, 0); ?></h3>
                        <p class="small mb-0 opacity-75">From active subscriptions</p>
                    </div>
                </div>
            </div>

            <!-- Active Subscriptions Card -->
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 rounded-4 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0 text-muted">Active Users</h6>
                            <i class="bi bi-people text-primary fs-4"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo number_format($active_subscriptions); ?></h3>
                        <p class="small mb-0 text-success fw-bold">Live Vendors</p>
                    </div>
                </div>
            </div>

            <!-- New Vendors Card -->
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 rounded-4 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0 text-muted">Recent Growth</h6>
                            <i class="bi bi-graph-up-arrow text-info fs-4"></i>
                        </div>
                        <h3 class="fw-bold mb-1">+<?php echo $new_vendors_last_30_days; ?></h3>
                        <p class="small mb-0 text-muted">New vendors (30d)</p>
                    </div>
                </div>
            </div>

            <!-- Most Popular Package Card -->
            <div class="col-xl-3 col-md-6">
                <div class="card shadow-sm border-0 rounded-4 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0 text-muted">Top Package</h6>
                            <i class="bi bi-award text-warning fs-4"></i>
                        </div>
                        <h6 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars(explode(" (", $most_popular_package)[0]); ?></h6>
                        <p class="small mb-0 text-muted"><?php echo $popular_package ? $popular_package['count'] . " active users" : "No active data"; ?></p>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>