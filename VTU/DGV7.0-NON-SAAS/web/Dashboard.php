<?php session_start();
include("../func/bc-config.php");

if(empty($get_logged_user_details["transaction_pin"])){
    header("Location: SecurityPIN.php");
    exit();
}

$select_user_vendor_status_message = mysqli_query($connection_server, "SELECT * FROM sas_vendor_status_messages WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "'");
if (mysqli_num_rows($select_user_vendor_status_message) == 1) {
	$get_user_vendor_status_message = mysqli_fetch_array($select_user_vendor_status_message);
	if (!isset($_SESSION["product_purchase_response"]) && isset($_SESSION["user_session"])) {
		$user_vendor_status_message_template_encoded_text_array = array("{username}" => $get_logged_user_details["username"]);
		foreach ($user_vendor_status_message_template_encoded_text_array as $array_key => $array_val) {
			$user_vendor_status_message_template_text = str_replace($array_key, $array_val, $get_user_vendor_status_message["message"]);
		}
		$_SESSION["product_purchase_response"] = str_replace("\n", "<br/>", $user_vendor_status_message_template_text);
	}
}

// Master KYC Compliance Check
$is_kyc_compliant = ($get_logged_user_details['kyc_status'] == 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<title>Dashboard | <?php echo $get_all_site_details["site_title"]; ?></title>
	<meta charset="UTF-8" />
	<meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
	<meta http-equiv="Content-Type" content="text/html; " />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
	<link rel="stylesheet" href="/cssfile/bc-style.css">
	<meta name="author" content="Philmore Codes">
	<meta name="dc.creator" content="Philmore Codes">
	<link href="https://fonts.gstatic.com" rel="preconnect">
	<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">
	<link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
	<link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
	<link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
	<link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
	<link href="../assets-2/css/style.css" rel="stylesheet">
	<style>
		body { background-color: #f8fafc; color: #1e293b; }
        .card { border-radius: 1.25rem; border: none; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
        .balance-hero {
            background: linear-gradient(135deg, <?php echo $vendor_primary_color; ?> 0%, <?php echo $vendor_primary_color; ?>cc 100%);
            border-radius: 1.25rem;
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .balance-hero::after {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            pointer-events: none;
        }
        .balance-amount { font-size: 2.5rem; font-weight: 800; letter-spacing: -1px; }
        .quick-action-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            padding: 0.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.75rem;
            backdrop-filter: blur(10px);
            transition: all 0.2s;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 60px;
            position: relative;
            z-index: 5;
        }
        .quick-action-btn i { font-size: 1.25rem; margin-bottom: 2px; }
        .quick-action-btn:hover { background: rgba(255,255,255,0.25); color: white; transform: translateY(-2px); }

        .service-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            text-align: center;
            padding: 1.25rem 0.5rem;
        }
        .service-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .service-icon-box {
            width: 56px;
            height: 56px;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 1.5rem;
        }
        .icon-data { background: #eff6ff; color: #3b82f6; }
        .icon-airtime { background: #fef2f2; color: #ef4444; }
        .icon-cable { background: #fdf2f7; color: #db2777; }
        .icon-electric { background: #fffbeb; color: #f59e0b; }
        .icon-transfer { background: #eef2ff; color: #287bff; }
        .icon-exam { background: #f5f3ff; color: #8b5cf6; }
        .icon-betting { background: #fff7ed; color: #f97316; }
        .icon-crypto { background: #fff7ed; color: #f59e0b; }
        .icon-vcard { background: #eef2ff; color: #3b82f6; }
        .icon-giftcard { background: #fdf2f7; color: #db2777; }
        .icon-nin { background: #f5f3ff; color: #8b5cf6; }
        .icon-bvn { background: #eef2ff; color: #3b82f6; }
        .icon-more { background: #f8fafc; color: #64748b; }

        /* Modern Glassmorphism Service Cards */
        .service-quick-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 1.5rem;
            padding: 1.5rem;
            transition: all 0.3s ease;
            height: 100%;
        }
        .service-quick-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
            background: rgba(255, 255, 255, 0.9);
        }

        .stats-label { font-size: 0.875rem; color: #64748b; font-weight: 500; }
        .stats-value { font-size: 1.25rem; font-weight: 700; color: #0f172a; word-break: break-all; }

        @media (max-width: 767px) {
            .balance-amount { font-size: 1.75rem; }
            .balance-hero { padding: 1.25rem; }
            .pagetitle { display: none; }
            .quick-action-btn { font-size: 0.65rem; padding: 0.4rem; }
        }
	</style>
</head>
<body>
	<?php include("../func/bc-header.php"); ?>
	<div class="pagetitle">
		<h1>DASHBOARD</h1>
		<nav>
			<ol class="breadcrumb">
				<li class="breadcrumb-item"><a href="#">Home</a></li>
				<li class="breadcrumb-item active">Dashboard</li>
			</ol>
		</nav>
	</div>
	<section class="section dashboard">
        <!-- AI Awareness Spotlight -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 rounded-4 shadow-sm overflow-hidden" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);">
                    <div class="card-body p-4 d-flex align-items-center justify-content-between position-relative">
                        <div class="position-absolute end-0 top-0 opacity-10" style="font-size: 8rem; transform: translate(20%, -20%); pointer-events: none;">
                            <i class="bi bi-cpu"></i>
                        </div>
                        <div class="d-flex align-items-center gap-3 position-relative z-1">
                            <div class="bg-white bg-opacity-20 rounded-circle p-3 shadow-sm d-none d-md-block">
                                <i class="bi bi-robot text-white fs-2"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-white mb-1">Meet Your AI Assistant! <span class="badge-new ms-2">NEW</span></h5>
                                <p class="text-white opacity-75 small mb-0">Buy <b>Airtime, Data, Cable</b> & pay <b>Bills</b> just by chatting with our AI Engine.</p>
                            </div>
                        </div>
                        <a href="AISuite.php" class="btn btn-light rounded-pill px-4 fw-bold text-primary shadow-sm position-relative z-1">Try AI Now</a>
                    </div>
                </div>
            </div>
        </div>
        <?php if(!$is_kyc_compliant && $get_logged_user_details["kyc_status"] != 1 && isKYCEnforced()): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between p-4" style="background: linear-gradient(45deg, #fff3cd, #fff8e1);">
            <div class="d-flex align-items-center">
                <div class="bg-warning bg-opacity-25 text-warning rounded-circle p-3 me-3 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-1 text-dark">Identity Verification Required</h6>
                    <p class="small mb-0 text-muted">To comply with regulations and unlock full bank transfer features, please complete your KYC.</p>
                </div>
            </div>
            <a href="KYCVerification.php" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm border-0 text-dark">Start KYC Now</a>
        </div>
        <?php elseif(isset($needs_media_kyc) && $needs_media_kyc && isKYCEnforced()): ?>
        <div class="alert alert-primary border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between p-4">
            <div class="d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle p-3 me-3">
                    <i class="bi bi-shield-lock-fill fs-3"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-1">Identity Verification Required</h6>
                    <p class="small mb-0 text-muted">Complete your KYC verification to unlock full transaction access.</p>
                </div>
            </div>
            <a href="KYCVerification.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Verify Now</a>
        </div>
        <?php endif; ?>

        <?php if($get_logged_user_details["kyc_status"] == 1): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center p-4">
            <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3 me-3">
                <i class="bi bi-clock-history fs-3"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1">Verification in Progress</h6>
                <p class="small mb-0 text-muted">Your identity documents are currently being reviewed. We will notify you once approved.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Balance Hero -->
        <div class="balance-hero shadow">
            <div class="row align-items-center">
                <div class="col-md-7 mb-3 mb-md-0 text-center text-md-start" style="position: relative; z-index: 10;">
                    <div class="small fw-semibold opacity-75 mb-1">AVAILABLE BALANCE</div>
                    <div class="balance-amount mb-2 d-flex align-items-center flex-wrap justify-content-center justify-content-md-start" style="word-break: break-all;">
                        ₦<?php echo toDecimal($get_logged_user_details["balance"], "2"); ?>
                        <div class="ms-3 d-flex flex-column align-items-center">
                            <a href="CoinConversion.php" class="d-flex align-items-center justify-content-center text-decoration-none" title="Get Bonus" style="width: 42px; height: 42px; background: rgba(255, 255, 255, 0.15); color: #fff; border-radius: 50%; border: 1px solid rgba(255, 255, 255, 0.25); transition: all 0.3s ease; backdrop-filter: blur(10px);">
                                <i class="bi bi-gift-fill" style="font-size: 1.25rem;"></i>
                            </a>
                            <span style="font-size: 11px; font-weight: 600; color: #fff; margin-top: 4px; opacity: 0.85;">BONUS</span>
                        </div>
                        <div class="ms-3 d-flex flex-column align-items-center">
                            <a href="SendFund.php" class="d-flex align-items-center justify-content-center text-decoration-none" title="W2B - Withdraw" style="width: 42px; height: 42px; background: rgba(255, 255, 255, 0.15); color: #fff; border-radius: 50%; border: 1px solid rgba(255, 255, 255, 0.25); transition: all 0.3s ease; backdrop-filter: blur(10px);">
                                <i class="bi bi-wallet2" style="font-size: 1.25rem;"></i>
                            </a>
                            <span style="font-size: 11px; font-weight: 600; color: #fff; margin-top: 4px; opacity: 0.85;">W2B</span>
                        </div>
                    </div>

                    <div class="row row-cols-4 g-2 mt-4" style="position: relative; z-index: 20;">
                        <div class="col"><a href="Fund.php" class="quick-action-btn"><i class="bi bi-plus-lg"></i>Fund</a></div>
                        <div class="col"><a href="SubmitPayment.php" class="quick-action-btn"><i class="bi bi-cash-coin"></i>Submit</a></div>
                        <div class="col"><a href="ShareFund.php" class="quick-action-btn"><i class="bi bi-send"></i>Share</a></div>
                        <?php if(isServiceEnabled('crypto_hub')): ?>
                        <div class="col"><a href="CryptoHub.php" class="quick-action-btn"><i class="bi bi-currency-bitcoin"></i>Crypto</a></div>
                        <?php endif; ?>
                    </div>

                    <?php if(isServiceEnabled('virtual_bank_display')): ?>
                    <div class="mt-4" style="position: relative; z-index: 20;">
                        <a href="VirtualBanks.php" class="btn w-100 rounded-pill fw-bold shadow-sm" style="background: rgba(255, 255, 255, 0.15); color: #fff; border: 1px solid rgba(255, 255, 255, 0.25); padding: 0.85rem; transition: all 0.3s ease; backdrop-filter: blur(10px); letter-spacing: 0.5px;">
                            <i class="bi bi-bank2 me-2 fs-5"></i> FUND VIA VIRTUAL BANK
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5">
                    <div class="bg-white bg-opacity-10 p-3 rounded-4 backdrop-blur border border-white border-opacity-10">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="small opacity-75">Account Type</span>
                            <span class="fw-bold small"><?php echo accountLevel($get_logged_user_details["account_level"]); ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="small opacity-75">Username</span>
                            <span class="fw-bold small">@<?php echo $get_logged_user_details["username"]; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Quick Access Section -->
        <div class="row g-4 mb-4">
            <?php if(isServiceEnabled('crypto_hub')): ?>
            <div class="col-md-4">
                <a href="CryptoHub.php" class="text-decoration-none">
                    <div class="service-quick-card d-flex align-items-center">
                        <div class="service-icon-box icon-crypto m-0 me-3 shadow-sm" style="width: 60px; height: 60px; font-size: 1.75rem;">
                            <i class="bi bi-currency-bitcoin"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Crypto Hub</h6>
                            <p class="small text-muted mb-0">Buy, Sell & Swap Crypto</p>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if(isServiceEnabled('virtual_card')): ?>
            <div class="col-md-4">
                <a href="VirtualCard.php" class="text-decoration-none">
                    <div class="service-quick-card d-flex align-items-center">
                        <div class="service-icon-box icon-vcard m-0 me-3 shadow-sm" style="width: 60px; height: 60px; font-size: 1.75rem;">
                            <i class="bi bi-credit-card-2-front"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Virtual Dollar Card</h6>
                            <p class="small text-muted mb-0">Global Payments Simplified</p>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <?php if(isServiceEnabled('gift_card')): ?>
            <div class="col-md-4">
                <a href="GiftCard.php" class="text-decoration-none">
                    <div class="service-quick-card d-flex align-items-center">
                        <div class="service-icon-box icon-giftcard m-0 me-3 shadow-sm" style="width: 60px; height: 60px; font-size: 1.75rem;">
                            <i class="bi bi-gift"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Gift Cards</h6>
                            <p class="small text-muted mb-0">Buy & Trade Gift Cards</p>
                        </div>
                    </div>
                </a>
            </div>
            <?php endif; ?>

        </div>

        <div class="row g-4">
            <!-- Services Grid -->
            <div class="col-lg-8">
                <div class="card p-4 border-0 shadow-sm rounded-4">
                    <h5 class="fw-bold mb-4">All Services</h5>
                    <div class="row row-cols-2 row-cols-sm-3 row-cols-xl-4 g-4 justify-content-center">
                        <?php if(isServiceEnabled('data')): ?>
                        <div class="col">
                            <a href="Data.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(37, 99, 235, 0.1); color: #2563eb;">
                                        <i class="bi bi-wifi"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Data</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Top-up Data</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('airtime')): ?>
                        <div class="col">
                            <a href="Airtime.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(22, 163, 74, 0.1); color: #16a34a;">
                                        <i class="bi bi-telephone"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Airtime</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Buy Airtime</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('cable')): ?>
                        <div class="col">
                            <a href="Cable.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(147, 51, 234, 0.1); color: #9333ea;">
                                        <i class="bi bi-tv"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Cable</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">TV Subscriptions</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('electric')): ?>
                        <div class="col">
                            <a href="Electric.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(234, 88, 12, 0.1); color: #ea580c;">
                                        <i class="bi bi-lightbulb"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Electric</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Pay Bills</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="col">
                            <a href="ShareFund.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(13, 148, 136, 0.1); color: #0d9488;">
                                        <i class="bi bi-send"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Transfer</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Send Funds</div>
                                </div>
                            </a>
                        </div>
                        <?php if(isServiceEnabled('exam')): ?>
                        <div class="col">
                            <a href="Exam.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(219, 39, 119, 0.1); color: #db2777;">
                                        <i class="bi bi-card-list"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Exam</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Result Pins</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('betting')): ?>
                        <div class="col">
                            <a href="Betting.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(220, 38, 38, 0.1); color: #dc2626;">
                                        <i class="bi bi-tag"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Betting</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Fund Wallets</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('crypto_hub')): ?>
                        <div class="col">
                            <a href="CryptoHub.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(217, 119, 6, 0.1); color: #d97706;">
                                        <i class="bi bi-currency-bitcoin"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Crypto</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Trade Assets</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('virtual_card')): ?>
                        <div class="col">
                            <a href="VirtualCard.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(79, 70, 229, 0.1); color: #4f46e5;">
                                        <i class="bi bi-credit-card-2-front"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">V-Card</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Dollar Cards</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('gift_card')): ?>
                        <div class="col">
                            <a href="GiftCard.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(8, 145, 178, 0.1); color: #0891b2;">
                                        <i class="bi bi-gift"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Gift Card</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Buy & Trade</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('data_card')): ?>
                        <div class="col">
                            <a href="PrintHub.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(71, 85, 105, 0.1); color: #475569;">
                                        <i class="bi bi-printer"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">Print Hub</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Data Pins</div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <div class="col">
                            <a href="Transactions.php" class="text-decoration-none h-100 d-block">
                                <div class="card h-100 border border-light shadow-sm rounded-4 p-4 text-center transition-hover" style="background-color: #f8fafc;">
                                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 shadow-sm" style="width: 65px; height: 65px; font-size: 1.8rem; background: rgba(101, 163, 13, 0.1); color: #65a30d;">
                                        <i class="bi bi-receipt"></i>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1">History</h6>
                                    <div class="text-muted" style="font-size: 0.7rem;">Transactions</div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Identity Services Card -->
                <?php
                $v_id_dash = $get_logged_user_details["vendor_id"];
                $nin_dash_enabled = isServiceEnabled('nin_card', $v_id_dash);
                $bvn_dash_enabled = isServiceEnabled('bvn_verify', $v_id_dash);

                if($nin_dash_enabled || $bvn_dash_enabled): ?>
                <div class="card p-4 mt-4">
                    <h5 class="fw-bold mb-4">Identity Services</h5>
                    <div class="row g-3">
                        <?php if($nin_dash_enabled): ?>
                        <div class="col-md-6">
                            <a href="NINCard.php" class="text-decoration-none">
                                <div class="d-flex align-items-center p-3 rounded-4 border border-light shadow-sm transition-hover" style="background: #fdf4ff;">
                                    <div class="service-icon-box icon-nin m-0 me-3 shadow-sm"><i class="bi bi-person-badge"></i></div>
                                    <div>
                                        <div class="small fw-bold text-dark">NIN Slip</div>
                                        <div class="text-muted" style="font-size: 10px;">Generate/Print NIN Card</div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if($bvn_dash_enabled): ?>
                        <div class="col-md-6">
                            <a href="BVNVerification.php" class="text-decoration-none">
                                <div class="d-flex align-items-center p-3 rounded-4 border border-light shadow-sm transition-hover" style="background: #f0f9ff;">
                                    <div class="service-icon-box icon-bvn m-0 me-3 shadow-sm"><i class="bi bi-shield-check"></i></div>
                                    <div>
                                        <div class="small fw-bold text-dark">BVN Verify</div>
                                        <div class="text-muted" style="font-size: 10px;">Secure Identity Verification</div>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Side Cards -->
            <div class="col-lg-4">
                <!-- AI ELIGIBILITY CARD (NEW) -->
                <?php
                $v_ai_q = mysqli_query($connection_server, "SELECT ai_status, voice_tx_threshold FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."'");
                $v_ai = mysqli_fetch_assoc($v_ai_q);
                
                if (($v_ai['ai_status'] ?? 0) == 1):
                    $tx_count_q = mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_transactions WHERE username='".$get_logged_user_details["username"]."' AND status=1");
                    $tx_count = ($tx_count_q && $row_c = mysqli_fetch_assoc($tx_count_q)) ? (int)$row_c['c'] : 0;
                    $threshold = (int)($v_ai['voice_tx_threshold'] ?? 50);
                    $v_status = (int)$get_logged_user_details['ai_voice_status'];

                    if ($v_status == 1 || (int)$get_logged_user_details['ai_status'] == 1): ?>
                        <div class="card p-4 mb-4 border-0 shadow-sm animate__animated animate__pulse animate__infinite" style="background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white;">
                            <h6 class="fw-bold mb-2"><i class="bi bi-cpu-fill me-2"></i>AI Access Active/Pending</h6>
                            <p class="x-small opacity-75 mb-0">Your AI Assistant access is active or pending review.</p>
                            <a href="AISuite.php" class="btn btn-light btn-sm w-100 rounded-pill fw-bold text-primary shadow-sm mt-3">GO TO AI SUITE</a>
                        </div>
                    <?php elseif ($v_status == 0): ?>
                        <div class="card p-4 mb-4 border-0 shadow-sm" style="background: <?php echo ($tx_count >= $threshold) ? 'linear-gradient(135deg, #000, #4338ca)' : '#fff'; ?>; color: <?php echo ($tx_count >= $threshold) ? 'white' : '#64748b'; ?>;">
                            <h6 class="fw-bold mb-2 <?php echo ($tx_count >= $threshold) ? 'text-white' : 'text-dark'; ?>">
                                <i class="bi <?php echo ($tx_count >= $threshold) ? 'bi-stars' : 'bi-lock-fill'; ?> me-2"></i>
                                <?php echo ($tx_count >= $threshold) ? 'AI Reward Unlocked!' : 'Autonomous AI'; ?>
                            </h6>
                            <p class="x-small opacity-75 mb-3">
                                <?php if ($tx_count >= $threshold): ?>
                                    You've completed <?php echo $tx_count; ?> transactions! Apply now for "Zero-Click" Voice commands.
                                <?php else: ?>
                                    Complete <?php echo $threshold; ?> successful transactions to unlock Voice AI. 
                                    <strong>(<?php echo $tx_count; ?>/<?php echo $threshold; ?> done)</strong>
                                <?php endif; ?>
                            </p>
                            <?php if ($tx_count >= $threshold): ?>
                                <a href="AISuite.php" class="btn btn-light btn-sm w-100 rounded-pill fw-bold text-primary shadow-sm">APPLY FOR AI ACCESS</a>
                            <?php else: ?>
                                <div class="progress rounded-pill" style="height: 6px; background: #e2e8f0;">
                                    <div class="progress-bar bg-primary" style="width: <?php echo min(100, ($tx_count/$threshold)*100); ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- VTU Coins Card -->
                <a href="PointsHistory.php" class="text-decoration-none">
                    <div class="card p-4 mb-4 shadow-sm border-0 transition-hover" style="background: #fffbeb;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-warning mb-0">VTU Coins</h6>
                            <i class="bi bi-gem text-warning fs-4"></i>
                        </div>
                        <?php $vtu_details = get_user_vtu_details($get_logged_user_details["username"]); ?>
                        <div class="h3 fw-bold text-dark mb-1"><?php echo number_format($vtu_details['total_points']); ?></div>
                        <div class="small text-muted mb-3">
                            <?php echo ($vtu_details['streak_day'] > 0) ? "🔥 " . $vtu_details['streak_day'] . " Day Streak" : "No active streak"; ?>
                        </div>
                        <?php if ($vtu_details['is_eligible']): ?>
                            <div class="alert alert-warning py-2 small mb-0 border-0">Earn today's bonus!</div>
                        <?php else: ?>
                            <div class="small text-success fw-bold"><i class="bi bi-check2-circle me-1"></i>Bonus Claimed</div>
                        <?php endif; ?>
                    </div>
                </a>

                <!-- Referral Card -->
                <div class="card p-4 mb-4">
                    <h6 class="fw-bold mb-3">Refer and Earn</h6>
                    <?php
                    $stmt = mysqli_prepare($connection_server, "SELECT COUNT(*) as referral_count FROM sas_users WHERE referral_id = ?");
                    mysqli_stmt_bind_param($stmt, "i", $get_logged_user_details["id"]);
                    mysqli_stmt_execute($stmt);
                    $referral_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['referral_count'];
                    ?>
                    <div class="d-flex align-items-center mb-3">
                        <div class="service-icon-box icon-data m-0 me-3"><i class="bi bi-people"></i></div>
                        <div>
                            <div class="h4 fw-bold text-dark mb-0"><?php echo $referral_count; ?></div>
                            <div class="small text-muted">Successful Referrals</div>
                        </div>
                    </div>
                    <button class="btn btn-outline-primary w-100 rounded-pill fw-bold small" onclick="copyReferLink();">COPY REFERRAL LINK</button>
                </div>

                <!-- Stats Cards -->
                <?php
                    // Optimization DG6.7: 10-minute persistent cache using sas_dashboard_cache
                    $vid_stats = $get_logged_user_details["vendor_id"];
                    $uname_stats = $get_logged_user_details["username"];

                    $q_cache = mysqli_query($connection_server, "SELECT cache_value FROM sas_dashboard_cache WHERE vendor_id='$vid_stats' AND username='$uname_stats' AND cache_key='user_stats' AND expiry > NOW() LIMIT 1");

                    if ($q_cache && mysqli_num_rows($q_cache) > 0) {
                        $stats_cache = json_decode(mysqli_fetch_assoc($q_cache)['cache_value'], true);
                    } else {
                        $stmt_dep = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND username=? AND status=1 AND (type_alternative LIKE '%credit%' OR type_alternative LIKE '%received%' OR type_alternative LIKE '%commission%')");
                        mysqli_stmt_bind_param($stmt_dep, "is", $vid_stats, $uname_stats);
                        mysqli_stmt_execute($stmt_dep);
                        $total_dep = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_dep))['total'] ?? 0);

                        $stmt_spent = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND username=? AND status=1 AND (type_alternative NOT LIKE '%credit%' AND type_alternative NOT LIKE '%refund%' AND type_alternative NOT LIKE '%received%' AND type_alternative NOT LIKE '%commission%')");
                        mysqli_stmt_bind_param($stmt_spent, "is", $vid_stats, $uname_stats);
                        mysqli_stmt_execute($stmt_spent);
                        $total_spent = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_spent))['total'] ?? 0);

                        $stats_cache = ['deposit' => $total_dep, 'spent' => $total_spent];
                        $cache_val = mysqli_real_escape_string($connection_server, json_encode($stats_cache));
                        mysqli_query($connection_server, "INSERT INTO sas_dashboard_cache (vendor_id, username, cache_key, cache_value, expiry) VALUES ('$vid_stats', '$uname_stats', 'user_stats', '$cache_val', DATE_ADD(NOW(), INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE cache_value=VALUES(cache_value), expiry=VALUES(expiry)");
                    }
                ?>
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="card p-3 h-100 border-start border-success border-4">
                            <div class="stats-label">TOTAL DEPOSIT</div>
                            <div class="stats-value">₦<?php echo number_format($stats_cache['deposit'], 0); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="card p-3 h-100 border-start border-danger border-4">
                            <div class="stats-label">TOTAL SPENT</div>
                            <div class="stats-value">₦<?php echo number_format($stats_cache['spent'], 0); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Upgrade Card -->
                <?php if ($get_logged_user_details["account_level"] < 2) : ?>
                <div class="card p-4">
                    <h6 class="fw-bold mb-3">Upgrade Account</h6>
                    <form method="post" action="handle_upgrade.php">
                        <select name="upgrade-type" class="form-select mb-3 rounded-3" required>
                            <option value="" hidden>Choose Level</option>
                            <?php
                            $account_level_upgrade_array = array(1 => "smart", 2 => "agent");
                            foreach ($account_level_upgrade_array as $index => $account_levels) {
                                if ($index > $get_logged_user_details["account_level"]) {
                                    $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id=? AND account_type=? LIMIT 1");
                                    mysqli_stmt_bind_param($stmt, "is", $get_logged_user_details["vendor_id"], $index);
                                    mysqli_stmt_execute($stmt);
                                    $get_upgrade_price = mysqli_fetch_array(mysqli_stmt_get_result($stmt));
                                    echo '<option value="' . $account_levels . '">' . accountLevel($index) . ' @ N' . number_format($get_upgrade_price["price"], 2) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <button name="upgrade-user" type="submit" class="btn btn-dark w-100 rounded-pill fw-bold">PROCEED UPGRADE</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
	</section>
	<script>
		function copyDashboardTextFallback(text) {
			var textArea = document.createElement("textarea");
			textArea.value = text;
			textArea.style.position = "fixed";
			textArea.style.top = "0";
			textArea.style.left = "0";
			textArea.style.opacity = "0";
			document.body.appendChild(textArea);
			textArea.focus();
			textArea.select();
			var successful = false;
			try {
				successful = document.execCommand('copy');
			} catch (err) {
				console.error('Fallback copy failed', err);
			}
			document.body.removeChild(textArea);
			return successful;
		}

		function copyAccount(accountNumber) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(accountNumber).then(() => {
					Swal.fire({ icon: 'success', title: 'Copied!', text: 'Account number copied: ' + accountNumber, timer: 2000, showConfirmButton: false });
				}).catch(err => {
					if (copyDashboardTextFallback(accountNumber)) {
						Swal.fire({ icon: 'success', title: 'Copied!', text: 'Account number copied: ' + accountNumber, timer: 2000, showConfirmButton: false });
					} else {
						console.error('Failed to copy: ', err);
					}
				});
			} else {
				if (copyDashboardTextFallback(accountNumber)) {
					Swal.fire({ icon: 'success', title: 'Copied!', text: 'Account number copied: ' + accountNumber, timer: 2000, showConfirmButton: false });
				} else {
					console.error('Failed to copy: Clipboard API not supported');
				}
			}
		}
		let ReferLink = '<?php echo $web_http_host . "/web/Register.php?referral=" . $get_logged_user_details["username"]; ?>';
		const copyReferLink = async () => {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				try {
					await navigator.clipboard.writeText(ReferLink);
					Swal.fire({ icon: 'success', title: 'Copied!', text: 'Referral link copied: ' + ReferLink, timer: 2000, showConfirmButton: false });
				} catch (err) {
					if (copyDashboardTextFallback(ReferLink)) {
						Swal.fire({ icon: 'success', title: 'Copied!', text: 'Referral link copied: ' + ReferLink, timer: 2000, showConfirmButton: false });
					} else {
						console.error('Failed to copy: ' + err);
					}
				}
			} else {
				if (copyDashboardTextFallback(ReferLink)) {
					Swal.fire({ icon: 'success', title: 'Copied!', text: 'Referral link copied: ' + ReferLink, timer: 2000, showConfirmButton: false });
				} else {
					console.error('Failed to copy: Clipboard API not supported');
				}
			}
		}
	</script>
	<div class="d-none d-md-block">
		<?php include("../func/short-trans.php"); ?>
	</div>
	<?php include("../func/bc-footer.php"); ?>
    <script src="/asset/biometric-handler.js"></script>
    <script>
        // Async Virtual Account Sync with throttling (Added in Branch DG6.7)
        fetch('ajax-sync-accounts.php?source=dashboard')
            .then(response => response.json())
            .then(data => {
                console.log('Sync result:', data);
                // If any gateway returned 'success', it means new accounts were likely added
                const hasNew = ['payhub', 'paystack', 'monnify', 'payvessel'].some(gw => data[gw] && data[gw].status === 'success');
                if (hasNew) {
                    setTimeout(() => { window.location.reload(); }, 2000);
                }
            })
            .catch(error => console.error('Sync error:', error));

        document.addEventListener('DOMContentLoaded', () => {
            if (window.PublicKeyCredential && !localStorage.getItem('biometric_registered')) {
                // Check if they just logged in (Welcome back message)
                const hasWelcome = document.body.innerText.includes('Welcome Back');
                if (hasWelcome) {
                    setTimeout(() => {
                        Swal.fire({
                            title: 'Enable Biometric Login?',
                            text: 'Login faster next time using your fingerprint or face recognition.',
                            icon: 'info',
                            showCancelButton: true,
                            confirmButtonText: 'Enable Now',
                            cancelButtonText: 'Maybe Later',
                            confirmButtonColor: '#0d6efd'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                registerBiometric();
                            }
                        });
                    }, 2000);
                }
            }
        });
    </script>
</body>
</html>
