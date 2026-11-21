<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-spadmin-config.php");
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head('', array("area" => "spadmin"));
?>
<style>
    /* Dashboard (Platnova) — page primitives on the shell's tokens. */
    .sp-hero {
        background: linear-gradient(135deg, <?php echo $spadmin_primary_color; ?> 0%, color-mix(in srgb, <?php echo $spadmin_primary_color; ?> 74%, #0b1220) 100%);
        border-radius: 20px; color: #fff; padding: 2rem; position: relative; overflow: hidden;
        box-shadow: var(--shadow); margin-bottom: 1.5rem;
    }
    .sp-hero::before {
        content: ''; position: absolute; width: 340px; height: 340px; border-radius: 50%;
        background: rgba(255,255,255,.10); top: -160px; right: -60px; pointer-events: none;
    }
    .sp-eyebrow { font-size: .72rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; opacity: .75; }
    .sp-metric .k { font-size: .7rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .75; margin-bottom: .2rem; }
    .sp-metric .v { font-size: 1.45rem; font-weight: 800; word-break: break-all; line-height: 1.15; }
    .sp-active {
        background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.22); backdrop-filter: blur(8px);
        border-radius: 20px; padding: 1.6rem; text-align: center; position: relative; z-index: 2;
    }
    .sp-card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        box-shadow: 0 10px 30px rgba(16,24,40,.05); padding: 1.4rem;
    }
    .sp-tile {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .45rem;
        height: 100%; min-height: 96px; padding: 1rem .4rem; text-decoration: none;
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .sp-tile i { font-size: 1.5rem; color: var(--brand); }
    .sp-tile span { font-size: .74rem; font-weight: 700; color: var(--ink); text-align: center; }
    .sp-tile:hover { transform: translateY(-3px); box-shadow: var(--shadow); border-color: color-mix(in srgb, var(--brand) 35%, var(--line)); }
    .ad-alert {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        border-radius: var(--radius); padding: 1rem 1.2rem; border: 1px solid var(--line);
        border-left: 4px solid var(--bad); background: var(--surface); box-shadow: 0 10px 30px rgba(16,24,40,.05);
    }
    @media (max-width: 767px) { .sp-hero { padding: 1.3rem; } }
  </style>
<link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
<link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "DASHBOARD", "crumb" => "Home / Dashboard")); ?><!-- End Page Title -->

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
            <div class="ad-alert">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:46px;height:46px;border-radius:14px;display:grid;place-items:center;background:#fef2f2;color:#dc2626;font-size:1.25rem;">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold">Platform Security Alert</h6>
                        <p class="mb-0 small text-muted">
                            <?php if($unblock_count > 0) echo "<b>$unblock_count</b> vendor unblock requests. "; ?>
                            <?php if($blocked_count > 0) echo "<b>$blocked_count</b> vendor accounts locked."; ?>
                        </p>
                    </div>
                </div>
                <a href="UnblockRequests.php" class="btn btn-danger btn-sm fw-bold px-3 rounded-pill">Take Action</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

        <!-- Platform Overview -->
        <div class="sp-hero">
            <div class="row align-items-center position-relative" style="z-index: 2;">
                <div class="col-lg-7">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div style="width:46px;height:46px;border-radius:14px;display:grid;place-items:center;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.26);font-size:1.25rem;">
                            <i class="bi bi-gear-fill"></i>
                        </div>
                        <div>
                            <div class="sp-eyebrow">Super Admin</div>
                            <h4 class="fw-bold mb-0 text-white">Platform Overview</h4>
                        </div>
                    </div>
                    <p class="small mb-4" style="opacity:.8;">Comprehensive system metrics and revenue tracking across every vendor on the platform.</p>

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
                    <div class="row g-3">
                        <div class="col-6 col-md-3 sp-metric">
                            <div class="k">Total Vendors</div>
                            <div class="v"><?php echo number_format($stats['total_vendors']); ?></div>
                        </div>
                        <div class="col-6 col-md-3 sp-metric">
                            <div class="k">Manual</div>
                            <div class="v">₦<?php echo toDecimal($stats['manual'], 2); ?></div>
                        </div>
                        <div class="col-6 col-md-3 sp-metric">
                            <div class="k">Revenue</div>
                            <div class="v">₦<?php echo toDecimal($stats['revenue'], 2); ?></div>
                        </div>
                        <div class="col-6 col-md-3 sp-metric">
                            <div class="k">Outflow</div>
                            <div class="v">₦<?php echo toDecimal($stats['outflow'], 2); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 mt-4 mt-lg-0">
                    <div class="sp-active">
                        <div class="sp-eyebrow mb-1">Active Subscriptions</div>
                        <div style="font-size: 3rem; font-weight: 800; letter-spacing: -1.5px; line-height: 1.1;"><?php echo number_format($stats['active_subs']); ?></div>
                        <a href="Vendors.php" class="btn btn-light btn-sm mt-3 fw-bold rounded-pill px-4 text-primary shadow-sm border-0">Manage Vendors</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="sp-card mb-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="bi bi-lightning-fill text-warning fs-5"></i>
                <h5 class="fw-bold m-0" style="color: var(--ink); font-size: 1rem;">Quick Actions</h5>
            </div>
            <div class="row g-3">
                <div class="col-6 col-md-4 col-lg-2"><a href="AIManagement.php" class="sp-tile"><i class="bi bi-cpu-fill"></i><span>AI Manager</span></a></div>
                <div class="col-6 col-md-4 col-lg-2"><a href="Vendors.php" class="sp-tile"><i class="bi bi-people-fill"></i><span>Vendors</span></a></div>
                <div class="col-6 col-md-4 col-lg-2"><a href="BillingPackages.php" class="sp-tile"><i class="bi bi-box-seam-fill"></i><span>Billing</span></a></div>
                <div class="col-6 col-md-4 col-lg-2"><a href="Transactions.php" class="sp-tile"><i class="bi bi-wallet2"></i><span>Transactions</span></a></div>
                <div class="col-6 col-md-4 col-lg-2"><a href="AccountSettings.php" class="sp-tile"><i class="bi bi-gear-fill"></i><span>Settings</span></a></div>
                <div class="col-6 col-md-4 col-lg-2"><a href="PaymentGateway.php" class="sp-tile"><i class="bi bi-credit-card-fill"></i><span>Gateway</span></a></div>
            </div>
        </div>
    </section>
		
		<?php include("../func/spadmin-short-trans.php"); ?>
    
    


<?php bc_adm_foot(array("area" => "spadmin")); ?>
