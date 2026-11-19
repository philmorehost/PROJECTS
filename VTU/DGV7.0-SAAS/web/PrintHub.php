<?php session_start();
include("../func/bc-config.php");

if(!isServiceEnabled('data_card')){
    $_SESSION["product_purchase_response"] = "Print Hub service is currently unavailable.";
    header("Location: Dashboard.php");
    exit();
}
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Print Hub | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "PRINT HUB", "crumb" => "Home / Print Hub")); ?>
	<section class="section dashboard">
        <div class="row g-4 justify-content-center">
            <div class="col-md-10">
                <div class="card p-4 border-0 shadow-sm rounded-4 mb-4" style="background: linear-gradient(to right, #f8f9fa, #ffffff);">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="fw-bold mb-1">Digital Vending Center</h4>
                            <p class="text-muted mb-0">Generate and print top-up cards for all VTU services. Quick, easy, and professional.</p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <a href="DataBundleCardHistory.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                                <i class="bi bi-clock-history me-2"></i>Printing History
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row row-cols-2 row-cols-md-3 g-4">
                    <?php if(isServiceEnabled('print_airtime')): ?>
                    <div class="col">
                        <a href="DataBundleCard.php?type=airtime" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box bg-airtime"><i class="bi bi-phone"></i></div>
                                <h6 class="fw-bold text-dark mb-1">Print Airtime</h6>
                                <p class="small text-muted mb-0">Recharge cards</p>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if(isServiceEnabled('print_data')): ?>
                    <div class="col">
                        <a href="DataBundleCard.php?type=data" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box bg-data"><i class="bi bi-wifi"></i></div>
                                <h6 class="fw-bold text-dark mb-1">Print Data</h6>
                                <p class="small text-muted mb-0">Internet bundles</p>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if(isServiceEnabled('print_cable')): ?>
                    <div class="col">
                        <a href="DataBundleCard.php?type=cable" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box bg-cable"><i class="bi bi-tv"></i></div>
                                <h6 class="fw-bold text-dark mb-1">Print Cable</h6>
                                <p class="small text-muted mb-0">TV subscriptions</p>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if(isServiceEnabled('print_electric')): ?>
                    <div class="col">
                        <a href="DataBundleCard.php?type=electric" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box bg-electric"><i class="bi bi-lightbulb"></i></div>
                                <h6 class="fw-bold text-dark mb-1">Print Electric</h6>
                                <p class="small text-muted mb-0">Utility tokens</p>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if(isServiceEnabled('print_exam')): ?>
                    <div class="col">
                        <a href="DataBundleCard.php?type=exam" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box bg-exam"><i class="bi bi-card-checklist"></i></div>
                                <h6 class="fw-bold text-dark mb-1">Print Exam</h6>
                                <p class="small text-muted mb-0">WAEC/NECO pins</p>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if(isServiceEnabled('print_betting')): ?>
                    <div class="col">
                        <a href="DataBundleCard.php?type=betting" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box bg-betting"><i class="bi bi-trophy"></i></div>
                                <h6 class="fw-bold text-dark mb-1">Print Betting</h6>
                                <p class="small text-muted mb-0">Wallet funding</p>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php
                    $nin_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT nin_card_enabled FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."' LIMIT 1"));
                    $nin_enabled = ($nin_vendor && $nin_vendor['nin_card_enabled'] == 1) && isServiceEnabled('nin_card');
                    ?>
                    <div class="col">
                        <?php if($nin_enabled): ?>
                        <a href="NINCard.php" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box bg-nin"><i class="bi bi-person-badge"></i></div>
                                <h6 class="fw-bold text-dark mb-1">NIN Slip</h6>
                                <p class="small text-muted mb-0">Digital NIN slip</p>
                            </div>
                        </a>
                        <?php else: ?>
                        <div class="text-decoration-none" title="NIN Card service is not enabled on this platform">
                            <div class="card service-card locked">
                                <div class="icon-box bg-nin"><i class="bi bi-lock-fill"></i></div>
                                <h6 class="fw-bold text-dark mb-1">NIN Slip</h6>
                                <p class="small text-muted mb-0">Not activated</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                    $bvn_vendor_row = mysqli_fetch_array(mysqli_query($connection_server, "SELECT bvn_verify_enabled FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."' LIMIT 1"));
                    $bvn_enabled = ($bvn_vendor_row && $bvn_vendor_row['bvn_verify_enabled'] == 1) && isServiceEnabled('bvn_verify');
                    ?>
                    <div class="col">
                        <?php if($bvn_enabled): ?>
                        <a href="BVNVerification.php" class="text-decoration-none">
                            <div class="card service-card">
                                <div class="icon-box" style="background:#eff6ff;color:#2563eb;"><i class="bi bi-fingerprint"></i></div>
                                <h6 class="fw-bold text-dark mb-1">BVN Verify</h6>
                                <p class="small text-muted mb-0">BVN identity check</p>
                            </div>
                        </a>
                        <?php else: ?>
                        <div class="text-decoration-none" title="BVN Verification service is not enabled on this platform">
                            <div class="card service-card locked">
                                <div class="icon-box" style="background:#eff6ff;color:#2563eb;"><i class="bi bi-lock-fill"></i></div>
                                <h6 class="fw-bold text-dark mb-1">BVN Verify</h6>
                                <p class="small text-muted mb-0">Not activated</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
	</section>
	
	<?php bc_app_foot(); ?>
