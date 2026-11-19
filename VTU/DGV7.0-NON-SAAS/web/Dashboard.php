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
<?php
// Plate app shell: one shared chrome for the signed-in console. The page keeps its own
// logic; only the <head>, the navigation and the footer come from the shell.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Dashboard | " . $get_all_site_details["site_title"]);
bc_app_sidebar();
bc_app_topbar(array("title" => "Dashboard", "crumb" => "Home / Dashboard"));
?>
<style>
	/* Dashboard (Platnova) — page primitives on the shell's tokens: --brand, --ink, --muted,
	   --line, --surface, --radius, --shadow. Grid/cards/buttons come from the shell compat layer. */
	.db-hero {
		background: linear-gradient(135deg, var(--brand) 0%, color-mix(in srgb, var(--brand) 76%, #0b1220) 100%);
		border-radius: 20px; color: #fff; padding: 1.75rem; position: relative; overflow: hidden;
		box-shadow: var(--shadow); margin-bottom: 1.5rem;
	}
	.db-hero::before, .db-hero::after {
		content: ''; position: absolute; border-radius: 50%; pointer-events: none;
		background: rgba(255,255,255,.10);
	}
	.db-hero::before { width: 340px; height: 340px; top: -160px; right: -60px; }
	.db-hero::after { width: 200px; height: 200px; bottom: -110px; right: 180px; background: rgba(255,255,255,.07); }
	.db-eyebrow { font-size: .72rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; opacity: .75; }
	.db-balance { font-size: 2.6rem; font-weight: 800; letter-spacing: -1.5px; line-height: 1.1; }
	.db-round {
		width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center;
		background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); color: #fff;
		font-size: 1.2rem; transition: all .2s; backdrop-filter: blur(8px);
	}
	.db-round:hover { background: rgba(255,255,255,.28); color: #fff; transform: translateY(-2px); }
	.db-round-label { font-size: .62rem; font-weight: 700; letter-spacing: .08em; margin-top: 4px; opacity: .85; }
	.db-action {
		display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
		min-height: 62px; padding: .5rem .25rem; border-radius: 14px; text-align: center;
		background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24); color: #fff;
		font-size: .72rem; font-weight: 700; backdrop-filter: blur(8px); transition: all .2s;
	}
	.db-action i { font-size: 1.2rem; }
	.db-action:hover { background: rgba(255,255,255,.26); color: #fff; transform: translateY(-2px); }
	.db-meta {
		background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.16);
		border-radius: var(--radius); padding: 1rem 1.1rem; backdrop-filter: blur(8px); position: relative; z-index: 2;
	}
	.db-meta .rowline { display: flex; justify-content: space-between; gap: 1rem; padding: .3rem 0; font-size: .85rem; }
	.db-meta .k { opacity: .72; } .db-meta .v { font-weight: 700; }
	.db-fund {
		display: block; text-align: center; padding: .8rem; border-radius: 999px; font-weight: 700; letter-spacing: .04em;
		background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.26); color: #fff; backdrop-filter: blur(8px); transition: all .2s;
	}
	.db-fund:hover { background: rgba(255,255,255,.28); color: #fff; }

	.db-card {
		background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
		box-shadow: 0 10px 30px rgba(16,24,40,.05); padding: 1.4rem; margin-bottom: 1.5rem;
	}
	.db-card__title { font-size: 1.05rem; font-weight: 800; color: var(--ink); margin-bottom: .2rem; }
	.db-card__sub { font-size: .8rem; color: var(--muted); margin-bottom: 1.1rem; }

	.db-feature {
		display: flex; align-items: center; gap: .9rem; height: 100%;
		background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
		padding: 1.1rem 1.2rem; transition: all .2s;
	}
	.db-feature:hover { transform: translateY(-3px); box-shadow: var(--shadow); border-color: color-mix(in srgb, var(--brand) 35%, var(--line)); }
	.db-feature .chev { margin-left: auto; color: var(--muted); }

	.svc-tile {
		display: flex; flex-direction: column; align-items: center; text-align: center;
		height: 100%; padding: 1.05rem .4rem .9rem; border-radius: var(--radius);
		background: var(--surface); border: 1px solid var(--line); transition: all .2s;
	}
	.svc-tile:hover { transform: translateY(-3px); box-shadow: var(--shadow); border-color: color-mix(in srgb, var(--brand) 35%, var(--line)); }
	.svc-ic { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: .55rem; }
	.svc-name { font-size: .84rem; font-weight: 700; color: var(--ink); line-height: 1.25; }
	.svc-sub { font-size: .68rem; color: var(--muted); margin-top: 2px; }
	.ic-blue { background: #eff6ff; color: #2563eb; } .ic-green { background: #ecfdf5; color: #059669; }
	.ic-purple { background: #f5f3ff; color: #7c3aed; } .ic-orange { background: #fff7ed; color: #ea580c; }
	.ic-pink { background: #fdf2f8; color: #db2777; } .ic-red { background: #fef2f2; color: #dc2626; }
	.ic-teal { background: #f0fdfa; color: #0d9488; } .ic-amber { background: #fffbeb; color: #d97706; }
	.ic-indigo { background: #eef2ff; color: #4f46e5; } .ic-cyan { background: #ecfeff; color: #0891b2; }
	.ic-slate { background: #f8fafc; color: #475569; } .ic-lime { background: #f7fee7; color: #65a30d; }
	.ic-violet { background: #f5f3ff; color: #8b5cf6; } .ic-sky { background: #f0f9ff; color: #0284c7; }

	.db-side-title { font-size: .95rem; font-weight: 800; color: var(--ink); margin-bottom: .9rem; }
	.db-stat { border: 1px solid var(--line); border-radius: var(--radius); padding: 1rem; height: 100%; background: var(--surface); }
	.db-stat .k { font-size: .7rem; font-weight: 700; letter-spacing: .08em; color: var(--muted); text-transform: uppercase; }
	.db-stat .v { font-size: 1.15rem; font-weight: 800; color: var(--ink); word-break: break-all; }
	.badge-new {
		background: #fff; color: #312e81; font-size: .62rem; font-weight: 800; letter-spacing: .06em;
		border-radius: 999px; padding: 2px 8px; vertical-align: middle;
	}
	@media (max-width: 767px) {
		.db-balance { font-size: 1.9rem; }
		.db-hero { padding: 1.3rem; }
	}
</style>
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
                                <p class="text-white opacity-75 small mb-0">Buy <b>Airtime, Data, Cable</b> &amp; pay <b>Bills</b> just by chatting with our AI Engine.</p>
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

        <!-- DGV7 Phase 2: wallets, tier badge, live rates, upgrade trigger -->
        <?php include(__DIR__ . "/../func/bc-dashboard-accounts.php"); ?>

        <!-- Balance Hero -->
        <div class="db-hero">
            <div class="row align-items-center position-relative" style="z-index: 2;">
                <div class="col-md-7 mb-4 mb-md-0 text-center text-md-start">
                    <div class="db-eyebrow mb-1">Available Balance</div>
                    <div class="d-flex align-items-start flex-wrap justify-content-center justify-content-md-start gap-2">
                        <div class="db-balance">₦<?php echo toDecimal($get_logged_user_details["balance"], "2"); ?></div>
                        <div class="d-flex gap-3 ms-md-3 mt-1">
                            <div class="text-center">
                                <a href="CoinConversion.php" class="db-round" title="Get Bonus"><i class="bi bi-gift-fill"></i></a>
                                <div class="db-round-label">BONUS</div>
                            </div>
                            <div class="text-center">
                                <a href="SendFund.php" class="db-round" title="W2B - Withdraw"><i class="bi bi-wallet2"></i></a>
                                <div class="db-round-label">W2B</div>
                            </div>
                        </div>
                    </div>

                    <div class="row row-cols-4 g-2 mt-4">
                        <div class="col"><a href="Fund.php" class="db-action"><i class="bi bi-plus-lg"></i>Fund</a></div>
                        <div class="col"><a href="SubmitPayment.php" class="db-action"><i class="bi bi-cash-coin"></i>Submit</a></div>
                        <div class="col"><a href="ShareFund.php" class="db-action"><i class="bi bi-send"></i>Share</a></div>
                        <?php if(isServiceEnabled('crypto_hub')): ?>
                        <div class="col"><a href="CryptoHub.php" class="db-action"><i class="bi bi-currency-bitcoin"></i>Crypto</a></div>
                        <?php endif; ?>
                    </div>

                    <?php if(isServiceEnabled('virtual_bank_display')): ?>
                    <div class="mt-3">
                        <a href="VirtualBanks.php" class="db-fund"><i class="bi bi-bank2 me-2"></i>Fund via Virtual Bank</a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-5">
                    <div class="db-meta">
                        <div class="rowline"><span class="k">Account Type</span><span class="v"><?php echo accountLevel($get_logged_user_details["account_level"]); ?></span></div>
                        <div class="rowline"><span class="k">Username</span><span class="v">@<?php echo $get_logged_user_details["username"]; ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Money & Cards -->
        <div class="row g-3 mb-4">
            <?php if(isServiceEnabled('crypto_hub')): ?>
            <div class="col-md-4">
                <a href="CryptoHub.php" class="text-decoration-none">
                    <div class="db-feature">
                        <div class="svc-ic ic-amber mb-0"><i class="bi bi-currency-bitcoin"></i></div>
                        <div><div class="svc-name">Crypto Hub</div><div class="svc-sub">Buy, sell &amp; swap crypto</div></div>
                        <i class="bi bi-chevron-right chev"></i>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(isServiceEnabled('virtual_card')): ?>
            <div class="col-md-4">
                <a href="VirtualCard.php" class="text-decoration-none">
                    <div class="db-feature">
                        <div class="svc-ic ic-indigo mb-0"><i class="bi bi-credit-card-2-front"></i></div>
                        <div><div class="svc-name">Virtual Dollar Card</div><div class="svc-sub">Global payments, simplified</div></div>
                        <i class="bi bi-chevron-right chev"></i>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(isServiceEnabled('gift_card')): ?>
            <div class="col-md-4">
                <a href="GiftCard.php" class="text-decoration-none">
                    <div class="db-feature">
                        <div class="svc-ic ic-pink mb-0"><i class="bi bi-gift"></i></div>
                        <div><div class="svc-name">Gift Cards</div><div class="svc-sub">Buy &amp; trade gift cards</div></div>
                        <i class="bi bi-chevron-right chev"></i>
                    </div>
                </a>
            </div>
            <?php endif; ?>
        </div>

        <div class="row g-4">
            <!-- Services Grid -->
            <div class="col-lg-8">
                <div class="db-card">
                    <div class="db-card__title">All Services</div>
                    <p class="db-card__sub">Everything you can buy, pay and print — in one place.</p>
                    <div class="row row-cols-3 row-cols-md-4 row-cols-xl-5 g-3">
                        <?php if(isServiceEnabled('data')): ?>
                        <div class="col"><a href="Data.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-blue"><i class="bi bi-wifi"></i></div><div class="svc-name">Data</div><div class="svc-sub">Top-up data</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('airtime')): ?>
                        <div class="col"><a href="Airtime.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-green"><i class="bi bi-telephone"></i></div><div class="svc-name">Airtime</div><div class="svc-sub">Buy airtime</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('cable')): ?>
                        <div class="col"><a href="Cable.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-purple"><i class="bi bi-tv"></i></div><div class="svc-name">Cable TV</div><div class="svc-sub">Subscriptions</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('electric')): ?>
                        <div class="col"><a href="Electric.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-orange"><i class="bi bi-lightbulb"></i></div><div class="svc-name">Electricity</div><div class="svc-sub">Buy tokens</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('exam')): ?>
                        <div class="col"><a href="Exam.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-pink"><i class="bi bi-card-list"></i></div><div class="svc-name">Exam Pins</div><div class="svc-sub">Result checkers</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('betting')): ?>
                        <div class="col"><a href="Betting.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-red"><i class="bi bi-trophy"></i></div><div class="svc-name">Betting</div><div class="svc-sub">Fund wallets</div></div></a></div>
                        <?php endif; ?>
                        <div class="col"><a href="ShareFund.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-teal"><i class="bi bi-send"></i></div><div class="svc-name">Transfer</div><div class="svc-sub">Share funds</div></div></a></div>
                        <?php if(isServiceEnabled('bank_transfer')): ?>
                        <div class="col"><a href="SendFund.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-indigo"><i class="bi bi-bank"></i></div><div class="svc-name">Wallet to Bank</div><div class="svc-sub">Withdraw</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('crypto_hub')): ?>
                        <div class="col"><a href="CryptoHub.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-amber"><i class="bi bi-currency-bitcoin"></i></div><div class="svc-name">Crypto</div><div class="svc-sub">Trade assets</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('virtual_card')): ?>
                        <div class="col"><a href="VirtualCard.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-indigo"><i class="bi bi-credit-card-2-front"></i></div><div class="svc-name">Virtual Card</div><div class="svc-sub">Dollar cards</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('gift_card')): ?>
                        <div class="col"><a href="GiftCard.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-cyan"><i class="bi bi-gift"></i></div><div class="svc-name">Gift Card</div><div class="svc-sub">Buy &amp; trade</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('data_card')): ?>
                        <div class="col"><a href="PrintHub.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-slate"><i class="bi bi-printer"></i></div><div class="svc-name">Print Cards</div><div class="svc-sub">Recharge &amp; data pins</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('bulk_sms')): ?>
                        <div class="col"><a href="BulkSMS.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-sky"><i class="bi bi-chat-square-text"></i></div><div class="svc-name">Bulk SMS</div><div class="svc-sub">Send campaigns</div></div></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('ai_suite')): ?>
                        <div class="col"><a href="AISuite.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-violet"><i class="bi bi-robot"></i></div><div class="svc-name">AI Suite</div><div class="svc-sub">Chat &amp; automate</div></div></a></div>
                        <?php endif; ?>
                        <div class="col"><a href="Transactions.php" class="text-decoration-none h-100 d-block"><div class="svc-tile"><div class="svc-ic ic-lime"><i class="bi bi-receipt"></i></div><div class="svc-name">History</div><div class="svc-sub">Transactions</div></div></a></div>
                    </div>
                </div>

                <!-- Identity Services Card -->
                <?php
                $v_id_dash = $get_logged_user_details["vendor_id"];
                $nin_dash_enabled = isServiceEnabled('nin_card', $v_id_dash);
                $bvn_dash_enabled = isServiceEnabled('bvn_verify', $v_id_dash);

                if($nin_dash_enabled || $bvn_dash_enabled): ?>
                <div class="db-card">
                    <div class="db-card__title">Identity Services</div>
                    <p class="db-card__sub">Verify and print official identity documents.</p>
                    <div class="row g-3">
                        <?php if($nin_dash_enabled): ?>
                        <div class="col-md-6">
                            <a href="NINCard.php" class="text-decoration-none">
                                <div class="db-feature">
                                    <div class="svc-ic ic-violet mb-0"><i class="bi bi-person-badge"></i></div>
                                    <div><div class="svc-name">NIN Slip</div><div class="svc-sub">Generate &amp; print NIN card</div></div>
                                    <i class="bi bi-chevron-right chev"></i>
                                </div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if($bvn_dash_enabled): ?>
                        <div class="col-md-6">
                            <a href="BVNVerification.php" class="text-decoration-none">
                                <div class="db-feature">
                                    <div class="svc-ic ic-blue mb-0"><i class="bi bi-shield-check"></i></div>
                                    <div><div class="svc-name">BVN Verification</div><div class="svc-sub">Secure identity check</div></div>
                                    <i class="bi bi-chevron-right chev"></i>
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
                        <div class="db-card" style="background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; border: none;">
                            <h6 class="fw-bold mb-2 text-white"><i class="bi bi-cpu-fill me-2"></i>AI Access Active/Pending</h6>
                            <p class="small opacity-75 mb-0">Your AI Assistant access is active or pending review.</p>
                            <a href="AISuite.php" class="btn btn-light btn-sm w-100 rounded-pill fw-bold text-primary shadow-sm mt-3">GO TO AI SUITE</a>
                        </div>
                    <?php elseif ($v_status == 0): ?>
                        <div class="db-card" style="<?php echo ($tx_count >= $threshold) ? 'background: linear-gradient(135deg, #0b1220, #4338ca); color: white; border: none;' : ''; ?>">
                            <h6 class="fw-bold mb-2">
                                <i class="bi <?php echo ($tx_count >= $threshold) ? 'bi-stars' : 'bi-lock-fill'; ?> me-2"></i>
                                <?php echo ($tx_count >= $threshold) ? 'AI Reward Unlocked!' : 'Autonomous AI'; ?>
                            </h6>
                            <p class="small opacity-75 mb-3">
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
                    <div class="db-card" style="background: #fffbeb;">
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
                <div class="db-card">
                    <div class="db-side-title">Refer and Earn</div>
                    <?php
                    $stmt = mysqli_prepare($connection_server, "SELECT COUNT(*) as referral_count FROM sas_users WHERE referral_id = ?");
                    mysqli_stmt_bind_param($stmt, "i", $get_logged_user_details["id"]);
                    mysqli_stmt_execute($stmt);
                    $referral_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['referral_count'];
                    ?>
                    <div class="d-flex align-items-center mb-3">
                        <div class="svc-ic ic-blue mb-0 me-3"><i class="bi bi-people"></i></div>
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
                        <div class="db-stat" style="border-left: 4px solid var(--ok);">
                            <div class="k">Total Deposit</div>
                            <div class="v">₦<?php echo number_format($stats_cache['deposit'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="db-stat" style="border-left: 4px solid var(--bad);">
                            <div class="k">Total Spent</div>
                            <div class="v">₦<?php echo number_format($stats_cache['spent'], 2); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Upgrade Card -->
                <?php if ($get_logged_user_details["account_level"] < 2) : ?>
                <div class="db-card">
                    <div class="db-side-title">Upgrade Account</div>
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
	<div class="d-none d-md-block">
		<?php include("../func/short-trans.php"); ?>
	</div>
	<script>
		function copyAccount(accountNumber) {
			navigator.clipboard.writeText(accountNumber).then(() => {
				Swal.fire({ icon: 'success', title: 'Copied!', text: 'Account number copied: ' + accountNumber, timer: 2000, showConfirmButton: false });
			}).catch(err => { console.error('Failed to copy: ', err); });
		}
		let ReferLink = '<?php echo bc_app_root_url("Register.php"); ?>?referral=<?php echo $get_logged_user_details["username"]; ?>';
		const copyReferLink = async () => {
			try {
				await navigator.clipboard.writeText(ReferLink);
				Swal.fire({ icon: 'success', title: 'Copied!', text: 'Referral link copied: ' + ReferLink, timer: 2000, showConfirmButton: false });
			} catch (err) { console.error('Failed to copy: ' + err); }
		}
	</script>
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
	<?php bc_app_foot(); ?>
