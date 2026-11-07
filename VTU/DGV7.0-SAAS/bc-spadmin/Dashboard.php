<?php session_start();
    include("../func/bc-spadmin-config.php");
?>
<!DOCTYPE html>
<head>
    <title></title>
    <meta charset="UTF-8" />
    <meta name="description" content="" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

      <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">
  <style>
    .info-card.card-blue { background-color: #eef7ff; }
    .info-card.card-red { background-color: #fceeed; }
    .info-card.card-green { background-color: #eefcef; }
    .info-card.card-yellow { background-color: #fff9e6; }
    .info-card {
        border-radius: 15px;
    }
    .shadow {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
    }
  </style>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?> 
    
  	<div class="pagetitle">
      <h1>DASHBOARD</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Dashboard</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
    <?php
    // Quick Actions / Urgent Notifications for Super Admin
    $pending_unblocks = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_unblock_requests WHERE vendor_id=0 AND status='pending'");
    $unblock_count = mysqli_fetch_assoc($pending_unblocks)['count'] ?? 0;

    $blocked_vendors = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_vendors WHERE is_blocked=1");
    $blocked_count = mysqli_fetch_assoc($blocked_vendors)['count'] ?? 0;

    if ($unblock_count > 0 || $blocked_count > 0):
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-danger bg-opacity-10 border-danger border-start border-4 rounded-4 shadow-sm">
                <div class="card-body py-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-shield-lock-fill text-danger fs-2 me-3"></i>
                        <div>
                            <h6 class="mb-0 fw-bold text-dark-primary">Platform Security Alert</h6>
                            <p class="mb-0 small text-dark-primary" style="opacity: 0.8;">
                                <?php if($unblock_count > 0) echo "<b>$unblock_count</b> vendor unblock requests. "; ?>
                                <?php if($blocked_count > 0) echo "<b>$blocked_count</b> vendor accounts locked."; ?>
                            </p>
                        </div>
                    </div>
                    <a href="UnblockRequests.php" class="btn btn-danger btn-sm fw-bold px-3 rounded-pill">Take Action</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

        <!-- Consolidated Super Admin Stats Card -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-0 rounded-4 shadow-sm overflow-hidden" style="background: linear-gradient(135deg, <?php echo $spadmin_primary_color; ?> 0%, <?php echo $spadmin_primary_color; ?>cc 100%);">
                    <div class="card-body p-4 p-lg-5 text-white">
                        <div class="row align-items-center">
                            <div class="col-lg-7">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-white bg-opacity-20 p-3 rounded-4 me-3">
                                        <i class="bi bi-gear-fill fs-3 text-white"></i>
                                    </div>
                                    <div>
                                        <h4 class="fw-bold mb-0">Platform Overview</h4>
                                        <p class="mb-0 opacity-75 small">Comprehensive system metrics and revenue tracking.</p>
                                    </div>
                                </div>

                                <div class="row mt-4 g-3">
                                    <?php
                                        // Super Admin persistent stats caching (Branch DG6.7 Optimization)
                                        // v2 cache key: the previous SQL counted pending (status 2) and
                                        // refunds in the outflow, and every credit-like row in revenue.
                                        $stats_cache_key = 'platform_stats_v2';
                                        $q_cache = mysqli_query($connection_server, "SELECT cache_value FROM sas_dashboard_cache WHERE vendor_id=0 AND username='SPADMIN' AND cache_key='$stats_cache_key' AND expiry > NOW() ORDER BY expiry DESC LIMIT 1");
                                        if ($q_cache && mysqli_num_rows($q_cache) > 0) {
                                            $stats = json_decode(mysqli_fetch_assoc($q_cache)['cache_value'], true) ?: ['total_vendors' => 0, 'active_subs' => 0, 'manual' => 0, 'revenue' => 0, 'outflow' => 0];
                                        } else {
                                            $q_v_count = mysqli_query($connection_server, "SELECT COUNT(id) as total FROM sas_vendors");
                                            $total_vendors = (int)(mysqli_fetch_assoc($q_v_count)['total'] ?? 0);

                                            $q_v_active = mysqli_query($connection_server, "SELECT COUNT(id) as total FROM sas_vendors WHERE expiry_date > NOW() AND status=1");
                                            $active_subs = (int)(mysqli_fetch_assoc($q_v_active)['total'] ?? 0);

                                            // Completed rows only. status=1 is the only state that represents
                                            // money that actually moved; status 2 is pending and status 3 failed.
                                            $q_manual = mysqli_query($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_vendor_transactions WHERE status=1 AND type_alternative LIKE '%credit%' AND description LIKE '%credit%' AND description LIKE '%spadmin%' AND type_alternative NOT LIKE '%refund%'");
                                            $total_manual = $q_manual ? (float)(mysqli_fetch_assoc($q_manual)['total'] ?? 0) : 0.0;

                                            $q_revenue = mysqli_query($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_vendor_transactions WHERE status=1 AND (type_alternative LIKE '%credit%' OR type_alternative LIKE '%received%' OR type_alternative LIKE '%commission%') AND type_alternative NOT LIKE '%refund%'");
                                            $total_revenue = $q_revenue ? (float)(mysqli_fetch_assoc($q_revenue)['total'] ?? 0) : 0.0;

                                            $q_outflow = mysqli_query($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_vendor_transactions WHERE status=1 AND type_alternative NOT LIKE '%credit%' AND type_alternative NOT LIKE '%refund%' AND type_alternative NOT LIKE '%received%' AND type_alternative NOT LIKE '%commission%'");
                                            $total_outflow = $q_outflow ? (float)(mysqli_fetch_assoc($q_outflow)['total'] ?? 0) : 0.0;

                                            $stats = [
                                                'total_vendors' => $total_vendors,
                                                'active_subs' => $active_subs,
                                                'manual' => $total_manual,
                                                'revenue' => $total_revenue,
                                                'outflow' => $total_outflow
                                            ];
                                            $cache_val = mysqli_real_escape_string($connection_server, json_encode($stats));
                                            mysqli_query($connection_server, "DELETE FROM sas_dashboard_cache WHERE vendor_id=0 AND username='SPADMIN' AND cache_key IN ('$stats_cache_key','platform_stats')");
                                            mysqli_query($connection_server, "INSERT INTO sas_dashboard_cache (vendor_id, username, cache_key, cache_value, expiry) VALUES (0, 'SPADMIN', '$stats_cache_key', '$cache_val', DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
                                        }
                                    ?>
                                    <div class="col-6 col-md-3">
                                        <p class="mb-1 small opacity-75 text-uppercase fw-bold">Total Vendors</p>
                                        <h2 class="fw-bold mb-0"><?php echo number_format($stats['total_vendors']); ?></h2>
                                    </div>
                                    <div class="col-6 col-md-3 border-start border-white border-opacity-25 ps-3 ps-md-4">
                                        <p class="mb-1 small opacity-75 text-uppercase fw-bold">Manual</p>
                                        <h3 class="fw-bold mb-0" style="word-break: break-all; font-size: clamp(0.9rem, 3vw, 1.4rem);">₦<?php echo toDecimal($stats['manual'], 2); ?></h3>
                                    </div>
                                    <div class="col-6 col-md-3 border-start border-white border-opacity-25 ps-3 ps-md-4">
                                        <p class="mb-1 small opacity-75 text-uppercase fw-bold">Revenue</p>
                                        <h3 class="fw-bold mb-0" style="word-break: break-all; font-size: clamp(0.9rem, 3vw, 1.4rem);">₦<?php echo toDecimal($stats['revenue'], 2); ?></h3>
                                    </div>
                                    <div class="col-6 col-md-3 border-start border-white border-opacity-25 ps-3 ps-md-4">
                                        <p class="mb-1 small opacity-75 text-uppercase fw-bold">Outflow</p>
                                        <h3 class="fw-bold mb-0" style="word-break: break-all; font-size: clamp(0.9rem, 3vw, 1.4rem);">₦<?php echo toDecimal($stats['outflow'], 2); ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-5 d-none d-lg-flex justify-content-end">
                                <div class="bg-white bg-opacity-10 p-5 rounded-circle position-relative overflow-hidden" style="width: 240px; height: 240px;">
                                    <i class="bi bi-graph-up-arrow position-absolute top-50 start-50 translate-middle" style="font-size: 100px; opacity: 0.15;"></i>
                                    <div class="text-center position-relative z-1">
                                        <p class="mb-1 small opacity-75 text-uppercase fw-bold">Active Subscriptions</p>
                                        <h1 class="display-5 fw-bold mb-0"><?php echo number_format($stats['active_subs']); ?></h1>
                                        <a href="Vendors.php" class="btn btn-light btn-sm mt-3 fw-bold rounded-pill px-4 text-primary shadow-sm border-0">Manage Vendors</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Link Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 rounded-4 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h5 class="card-title fw-bold m-0 text-dark-primary d-flex align-items-center">
                            <i class="bi bi-lightning-fill text-warning me-2"></i> Quick Actions
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-6 col-md-4 col-lg-2">
                                <a href="AIManagement.php" class="btn btn-outline-primary w-100 py-3 rounded-4 border-2 d-flex flex-column align-items-center gap-2 hover-lift">
                                    <i class="bi bi-cpu-fill fs-3"></i>
                                    <span class="small fw-bold">AI Manager</span>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <a href="Vendors.php" class="btn btn-outline-primary w-100 py-3 rounded-4 border-2 d-flex flex-column align-items-center gap-2 hover-lift">
                                    <i class="bi bi-people-fill fs-3"></i>
                                    <span class="small fw-bold">Vendors</span>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <a href="BillingPackages.php" class="btn btn-outline-primary w-100 py-3 rounded-4 border-2 d-flex flex-column align-items-center gap-2 hover-lift">
                                    <i class="bi bi-box-seam-fill fs-3"></i>
                                    <span class="small fw-bold">Billing</span>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <a href="Transactions.php" class="btn btn-outline-primary w-100 py-3 rounded-4 border-2 d-flex flex-column align-items-center gap-2 hover-lift">
                                    <i class="bi bi-wallet2 fs-3"></i>
                                    <span class="small fw-bold">Transactions</span>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <a href="AccountSettings.php" class="btn btn-outline-primary w-100 py-3 rounded-4 border-2 d-flex flex-column align-items-center gap-2 hover-lift">
                                    <i class="bi bi-gear-fill fs-3"></i>
                                    <span class="small fw-bold">Settings</span>
                                </a>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <a href="PaymentGateway.php" class="btn btn-outline-primary w-100 py-3 rounded-4 border-2 d-flex flex-column align-items-center gap-2 hover-lift">
                                    <i class="bi bi-credit-card-fill fs-3"></i>
                                    <span class="small fw-bold">Gateway</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .hover-lift { transition: transform 0.2s ease, box-shadow 0.2s ease; }
            .hover-lift:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(var(--bs-primary-rgb), 0.1); background-color: var(--primary-color); color: white !important; }
        </style>
    </section>
		
		<?php include("../func/spadmin-short-trans.php"); ?>
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>