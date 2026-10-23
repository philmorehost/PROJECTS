<?php session_start();
include("../func/bc-config.php");

if(!isServiceEnabled('data_card')){
    $_SESSION["product_purchase_response"] = "Print Hub service is currently unavailable.";
    header("Location: Dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<title>Print Hub | <?php echo $get_all_site_details["site_title"]; ?></title>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
	<link rel="stylesheet" href="/cssfile/bc-style.css">
	<link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
	<link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
	<link href="../assets-2/css/style.css" rel="stylesheet">
	<style>
		.service-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            text-align: center;
            padding: 1.5rem 1rem;
            height: 100%;
            border-radius: 1.25rem;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .service-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); }
        .icon-box {
            width: 64px;
            height: 64px;
            border-radius: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.75rem;
        }
        .bg-airtime { background: #fff1f2; color: #e11d48; }
        .bg-data { background: #eff6ff; color: #2563eb; }
        .bg-cable { background: #faf5ff; color: #9333ea; }
        .bg-electric { background: #fffbeb; color: #d97706; }
        .bg-exam { background: #f0fdf4; color: #16a34a; }
        .bg-betting { background: #fdf2f8; color: #db2777; }
        .bg-nin { background: #ecfdf5; color: #059669; }
        .service-card.locked { opacity: 0.65; cursor: not-allowed; }
        .service-card.locked:hover { transform: none; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
	</style>
</head>
<body>
	<?php include("../func/bc-header.php"); ?>
	<div class="pagetitle">
		<h1>PRINT HUB</h1>
		<nav>
			<ol class="breadcrumb">
				<li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
				<li class="breadcrumb-item active">Print Hub</li>
			</ol>
		</nav>
	</div>
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
	<?php include("../func/bc-footer.php"); ?>
</body>
</html>
