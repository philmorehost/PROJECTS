<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");

if(empty($get_logged_user_details["transaction_pin"])){
    header("Location: SecurityPIN.php");
    exit();
}

$select_user_vendor_status_message = mysqli_query($connection_server, "SELECT * FROM sas_vendor_status_messages WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "'");
if ($select_user_vendor_status_message && mysqli_num_rows($select_user_vendor_status_message) == 1) {
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
	.db-action, .db-action:hover, .db-action:focus { color: #fff !important; text-decoration: none; }
	.db-action span { display: block; color: #fff; font-size: .78rem; font-weight: 700; letter-spacing: .02em; line-height: 1.2; }
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

	/* All Services grid tiles: one tile per hub, icon over label over a short
	   hint. The grid itself is Bootstrap row-cols (2-up on phones, 3-up from
	   sm, 4-up from lg); tiles stretch to the tallest in their row so every
	   row stays even on any device. */
	.svc-tile {
		display: flex; flex-direction: column; align-items: center; text-align: center;
		height: 100%; min-height: 138px; padding: 1.15rem .65rem 1rem; border-radius: var(--radius);
		background: var(--surface); border: 1px solid var(--line); transition: all .2s;
	}
	a.svc-tile, a.svc-tile:hover, a.svc-tile:focus { text-decoration: none; }
	.svc-tile:hover { transform: translateY(-3px); box-shadow: var(--shadow); border-color: color-mix(in srgb, var(--brand) 35%, var(--line)); }
	.svc-tile:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
	.svc-ic { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; font-size: 1.3rem; margin-bottom: .55rem; flex: none; }
	.svc-name { font-size: .84rem; font-weight: 700; color: var(--ink); line-height: 1.25; }
	.svc-sub { font-size: .68rem; color: var(--muted); margin-top: 2px; }
	.svc-tile .svc-name, .svc-tile .svc-sub { display: block; }
	.svc-tile .svc-sub { line-height: 1.35; }
	@media (max-width: 575px) {
		.svc-tile { min-height: 128px; padding: .95rem .4rem .85rem; }
		.svc-tile .svc-ic { width: 42px; height: 42px; border-radius: 12px; font-size: 1.15rem; }
		.svc-tile .svc-name { font-size: .8rem; }
		.svc-tile .svc-sub { font-size: .64rem; }
	}
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
                        <div class="col"><a href="Fund.php" class="db-action"><i class="bi bi-plus-lg"></i><span>Fund</span></a></div>
                        <div class="col"><a href="SubmitPayment.php" class="db-action"><i class="bi bi-cash-coin"></i><span>Submit</span></a></div>
                        <?php if(isServiceEnabled('share_fund')): ?>
                        <div class="col"><a href="ShareFund.php" class="db-action"><i class="bi bi-send"></i><span>Share</span></a></div>
                        <?php endif; ?>
                        <?php if(isServiceEnabled('crypto_hub')): ?>
                        <div class="col"><a href="CryptoHub.php" class="db-action"><i class="bi bi-currency-bitcoin"></i><span>Crypto</span></a></div>
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
                        <div class="rowline"><span class="k">Account Type</span><span class="v"><?php echo function_exists('bc_fintech_account_type') ? ucfirst(bc_fintech_account_type($get_logged_user_details) ?: 'personal') : accountLevel($get_logged_user_details["account_level"]); ?></span></div>
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

        <!-- New Fintech Services -->
        <div class="row g-3 mb-4">
            <?php if(isServiceEnabled('intl_transfer')): ?>
            <div class="col-md-4">
                <a href="IntlTransfer.php" class="text-decoration-none">
                    <div class="db-feature">
                        <div class="svc-ic ic-teal mb-0"><i class="bi bi-globe"></i></div>
                        <div><div class="svc-name">Send Money Abroad</div><div class="svc-sub">China payout, wire &amp; global transfers</div></div>
                        <i class="bi bi-chevron-right chev"></i>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(isServiceEnabled('tuition')): ?>
            <div class="col-md-4">
                <a href="Tuition.php" class="text-decoration-none">
                    <div class="db-feature">
                        <div class="svc-ic ic-sky mb-0"><i class="bi bi-mortarboard"></i></div>
                        <div><div class="svc-name">Pay Tuition Worldwide</div><div class="svc-sub">160+ universities, ID validated</div></div>
                        <i class="bi bi-chevron-right chev"></i>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            <?php if(isServiceEnabled('esim')): ?>
            <div class="col-md-4">
                <a href="ESIM.php" class="text-decoration-none">
                    <div class="db-feature">
                        <div class="svc-ic ic-violet mb-0"><i class="bi bi-sim"></i></div>
                        <div><div class="svc-name">Travel eSIM</div><div class="svc-sub">Data in 190+ countries, QR activation</div></div>
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
                    <div class="row row-cols-2 row-cols-sm-3 row-cols-lg-4 g-3">
                        <div class="col">
                            <a href="Hub.php?g=pay-bills" class="svc-tile">
                                <span class="svc-ic ic-amber"><i class="bi bi-lightning-charge"></i></span>
                                <span class="svc-name">Pay Bills</span>
                                <span class="svc-sub">Data, airtime, cable, power &amp; more</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="Hub.php?g=wallet-transfers" class="svc-tile">
                                <span class="svc-ic ic-blue"><i class="bi bi-wallet2"></i></span>
                                <span class="svc-name">Wallet &amp; Transfers</span>
                                <span class="svc-sub">Fund, send, cards &amp; crypto</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="Hub.php?g=verify-print" class="svc-tile">
                                <span class="svc-ic ic-purple"><i class="bi bi-briefcase"></i></span>
                                <span class="svc-name">Verify &amp; Print</span>
                                <span class="svc-sub">NIN slips &amp; BVN checks</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="PrintHub.php" class="svc-tile">
                                <span class="svc-ic ic-teal"><i class="bi bi-printer"></i></span>
                                <span class="svc-name">PrintHub</span>
                                <span class="svc-sub">Data cards &amp; printing</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="Hub.php?g=tools-api" class="svc-tile">
                                <span class="svc-ic ic-indigo"><i class="bi bi-building"></i></span>
                                <span class="svc-name">Tools &amp; API</span>
                                <span class="svc-sub">Bulk SMS, referrals &amp; developer API</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="Hub.php?g=history-tools" class="svc-tile">
                                <span class="svc-ic ic-cyan"><i class="bi bi-clock-history"></i></span>
                                <span class="svc-name">History &amp; Tools</span>
                                <span class="svc-sub">Transactions, points &amp; calculators</span>
                            </a>
                        </div>
                        <div class="col">
                            <a href="Hub.php?g=settings-help" class="svc-tile">
                                <span class="svc-ic ic-slate"><i class="bi bi-gear"></i></span>
                                <span class="svc-name">Settings &amp; Help</span>
                                <span class="svc-sub">Account, guide &amp; support</span>
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


                <!-- VTU Coins Card -->
                <?php if(isServiceEnabled('vtu_coins')): ?>
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
                <?php endif; ?>


                <!-- Stats Cards -->
                <?php
                    // Optimization DG6.7: 10-minute persistent cache using sas_dashboard_cache
                    $vid_stats = $get_logged_user_details["vendor_id"];
                    $uname_stats = $get_logged_user_details["username"];

                    $stats_cache_key = 'user_stats_v2';
                    $q_cache = mysqli_query($connection_server, "SELECT cache_value FROM sas_dashboard_cache WHERE vendor_id='$vid_stats' AND username='$uname_stats' AND cache_key='$stats_cache_key' AND expiry > NOW() ORDER BY expiry DESC LIMIT 1");

                    if ($q_cache && mysqli_num_rows($q_cache) > 0) {
                        $stats_cache = json_decode(mysqli_fetch_assoc($q_cache)['cache_value'], true) ?: ['deposit' => 0, 'spent' => 0];
                    } else {
                        $stmt_dep = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND username=? AND " . bc_sql_successful_deposit());
                        mysqli_stmt_bind_param($stmt_dep, "is", $vid_stats, $uname_stats);
                        mysqli_stmt_execute($stmt_dep);
                        $total_dep = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_dep))['total'] ?? 0);
                        mysqli_stmt_close($stmt_dep);

                        $stmt_spent = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND username=? AND " . bc_sql_successful_sale());
                        mysqli_stmt_bind_param($stmt_spent, "is", $vid_stats, $uname_stats);
                        mysqli_stmt_execute($stmt_spent);
                        $total_spent = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_spent))['total'] ?? 0);
                        mysqli_stmt_close($stmt_spent);

                        $stats_cache = ['deposit' => $total_dep, 'spent' => $total_spent];
                        $cache_val = mysqli_real_escape_string($connection_server, json_encode($stats_cache));
                        mysqli_query($connection_server, "DELETE FROM sas_dashboard_cache WHERE vendor_id='$vid_stats' AND username='$uname_stats' AND cache_key IN ('$stats_cache_key','user_stats')");
                        mysqli_query($connection_server, "INSERT INTO sas_dashboard_cache (vendor_id, username, cache_key, cache_value, expiry) VALUES ('$vid_stats', '$uname_stats', '$stats_cache_key', '$cache_val', DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
                    }
                ?>
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="p-3 rounded-4 h-100 text-white" style="background: linear-gradient(135deg, #047857, #065f46);">
                            <div class="d-flex justify-content-between align-items-start"><span class="small fw-bold text-uppercase" style="letter-spacing:.08em; opacity:.85;">Total Deposit</span><i class="bi bi-arrow-down-circle-fill fs-5 opacity-75"></i></div>
                            <div class="h5 fw-bold mb-0 mt-2">₦<?php echo number_format($stats_cache['deposit'], 2); ?></div>
                            <span class="small" style="opacity:.8;">All money in</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-4 h-100 text-white" style="background: linear-gradient(135deg, #0f172a, #334155);">
                            <div class="d-flex justify-content-between align-items-start"><span class="small fw-bold text-uppercase" style="letter-spacing:.08em; opacity:.85;">Total Spent</span><i class="bi bi-arrow-up-circle-fill fs-5 opacity-75"></i></div>
                            <div class="h5 fw-bold mb-0 mt-2">₦<?php echo number_format($stats_cache['spent'], 2); ?></div>
                            <span class="small" style="opacity:.8;">All money out</span>
                        </div>
                    </div>
                </div>

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
