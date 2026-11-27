<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-config.php");
include("../func/bc-crypto-func.php");
include_once(__DIR__ . "/../func/bc-fintech-services.php");
require_once __DIR__ . "/../func/bc-crypto-a2p.php";

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}

$vid = (int)$get_logged_user_details['vendor_id'];
$username = (string)$get_logged_user_details['username'];

if (!isServiceEnabled('crypto_hub')) {
    header("Location: Dashboard.php");
    exit();
}

// §28: legacy provider mode gate — retired external API endpoints remain disabled.
$crypto_provider = bc_svc_active_provider($vid);
$crypto_provider_mode = true;
$crypto_provider_label = bc_svc_provider_label($crypto_provider);
if ($crypto_provider_mode && (isset($_POST['action']) || isset($_GET['get_rate']))) {
    echo json_encode(['status' => 'error', 'message' => 'Crypto services are being provisioned for this platform. No trade was executed.']);
    exit();
}

$kycBlocked = (function_exists('isKYCEnforced') && isKYCEnforced() && (int)($get_logged_user_details['kyc_status'] ?? 0) !== 2);
$activeTab = in_array(($_GET['tab'] ?? 'buy'), ['buy', 'sell', 'swap'], true) ? $_GET['tab'] : 'buy';

// Handle A2P / P2A Desk actions (no external exchange API required)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['a2p_action'])) {
    if (function_exists('bc_validate_csrf')) {
        bc_validate_csrf();
    }
    $a2pAction = (string)$_POST['a2p_action'];
    $pin = (string)($_POST['pin'] ?? '');
    $redirectTab = 'buy';
    if ($a2pAction === 'p2a_sell') $redirectTab = 'sell';
    elseif ($a2pAction === 'a2p_swap') $redirectTab = 'swap';

    if ($kycBlocked) {
        $_SESSION['crypto_a2p_flash'] = ['ok' => false, 'tab' => $redirectTab, 'message' => 'Complete KYC verification before trading on CryptoHub.'];
    } elseif (!bc_verify_pin_secure($pin, $get_logged_user_details, 'crypto_a2p')) {
        $_SESSION['crypto_a2p_flash'] = ['ok' => false, 'tab' => $redirectTab, 'message' => 'Invalid Transaction PIN. Please verify and try again.'];
    } else {
        if ($a2pAction === 'a2p_buy') {
            $res = bc_crypto_a2p_submit_buy($connection_server, $vid, $get_logged_user_details, $_POST);
            $_SESSION['crypto_a2p_flash'] = ['ok' => $res['ok'], 'tab' => 'buy', 'message' => $res['message']];
        } elseif ($a2pAction === 'p2a_sell') {
            $res = bc_crypto_a2p_submit_sell($connection_server, $vid, $get_logged_user_details, $_POST);
            $_SESSION['crypto_a2p_flash'] = ['ok' => $res['ok'], 'tab' => 'sell', 'message' => $res['message']];
        } elseif ($a2pAction === 'a2p_swap') {
            $res = bc_crypto_a2p_execute_swap($connection_server, $vid, $get_logged_user_details, $_POST);
            $_SESSION['crypto_a2p_flash'] = ['ok' => $res['ok'], 'tab' => 'swap', 'to_is_ngn' => !empty($res['to_is_ngn']), 'message' => $res['message']];
        } else {
            $_SESSION['crypto_a2p_flash'] = ['ok' => false, 'tab' => 'buy', 'message' => 'Unknown CryptoHub action.'];
        }
    }
    header("Location: CryptoHub.php?tab=" . urlencode($redirectTab));
    exit();
}

$flash = $_SESSION['crypto_a2p_flash'] ?? null;
unset($_SESSION['crypto_a2p_flash']);
if (is_array($flash) && !empty($flash['tab']) && in_array($flash['tab'], ['buy', 'sell', 'swap'], true)) {
    $activeTab = $flash['tab'];
}

// Refresh user balance after any redirect
$u_bal_q = mysqli_query($connection_server, "SELECT balance FROM sas_users WHERE id='" . (int)$get_logged_user_details['id'] . "' AND vendor_id='$vid' LIMIT 1");
if ($u_bal_q && $u_bal_r = mysqli_fetch_assoc($u_bal_q)) {
    $get_logged_user_details['balance'] = $u_bal_r['balance'];
}
$ngnBalance = (float)($get_logged_user_details['balance'] ?? 0);

$currencies = []; // Legacy provider catalogue disabled
$userWallets = getUserCryptoWallets($vid, $username);
$a2pBundle = bc_crypto_a2p_all_quotes($vid, false);
$a2pCfg = $a2pBundle['config'];
$a2pRates = $a2pBundle['rates'];
$a2pQuotes = $a2pBundle['quotes'];

$enabledQuotes = [];
foreach ($a2pQuotes as $code => $q) {
    if (!empty($q['enabled'])) {
        $enabledQuotes[$code] = $q;
    }
}
$catalogue = bc_crypto_a2p_catalogue();

// Compute portfolio NGN value across user's crypto wallets
$portfolioNgn = 0.0;
$walletCards = [];
foreach ($a2pQuotes as $code => $q) {
    $bal = (float)($userWallets[$code]['balance'] ?? 0);
    if ($q['enabled'] || $bal > 0) {
        $valNgn = $bal * (float)$q['base_ngn'];
        $portfolioNgn += $valNgn;
        $walletCards[$code] = [
            'code' => $code,
            'symbol' => $q['symbol'],
            'name' => $q['name'],
            'network_label' => $q['network_label'],
            'icon' => $q['icon'],
            'balance' => $bal,
            'val_ngn' => $valNgn,
            'quote' => $q,
        ];
    }
}
// Include any non-zero legacy wallet code (e.g. USDT_TRX)
foreach ($userWallets as $wCode => $wRow) {
    $wBal = (float)($wRow['balance'] ?? 0);
    if ($wBal > 0 && !isset($walletCards[$wCode])) {
        $fallbackRate = ($wCode === 'USDT_TRX') ? ($a2pQuotes['USDT_TRC20']['base_ngn'] ?? 0) : 0;
        $portfolioNgn += ($wBal * $fallbackRate);
        $walletCards[$wCode] = [
            'code' => $wCode,
            'symbol' => ($wCode === 'USDT_TRX') ? 'USDT' : $wCode,
            'name' => ($wCode === 'USDT_TRX') ? 'Tether (Legacy TRC-20)' : $wCode,
            'network_label' => 'Legacy Wallet',
            'icon' => strtolower(str_replace('_', '-', $wCode)) . '.png',
            'balance' => $wBal,
            'val_ngn' => $wBal * $fallbackRate,
            'quote' => null,
        ];
    }
}

$supported = array_keys($enabledQuotes);
$csrfFieldHtml = function_exists('bc_csrf_field') ? bc_csrf_field() : '';
$rateSourceBadge = ($a2pRates['source'] === 'manual' || $a2pRates['source'] === 'manual_fallback')
    ? 'Admin Fixed Rate'
    : 'Live Market + Markup';

include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("CryptoHub | " . ($get_all_site_details["site_title"]));
?>
<style data-pkx="page-redesign">
/* CryptoHub A2P/P2A Desk — modern responsive layout using --brand tokens */
.pk-app__wrap .ch-hero {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-ink) 100%);
    color: var(--brand-fg, #fff);
    border-radius: 22px;
    padding: 1.5rem 1.65rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 18px 38px -22px color-mix(in srgb, var(--brand) 65%, #000);
}
.pk-app__wrap .ch-hero::after {
    content: "";
    position: absolute;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .08);
    top: -80px;
    right: -60px;
    pointer-events: none;
}
.pk-app__wrap .ch-hero__bal {
    font-size: clamp(1.65rem, 4vw, 2.25rem);
    font-weight: 800;
    letter-spacing: -.02em;
    line-height: 1.15;
}
.pk-app__wrap .ch-hero__pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 700;
    background: rgba(255, 255, 255, .16);
    border: 1px solid rgba(255, 255, 255, .24);
    color: #fff;
}
.pk-app__wrap .coin-icons-row { display: flex; align-items: center; flex-wrap: wrap; }
.pk-app__wrap .coin-icons-row img {
    width: 34px; height: 34px; border-radius: 50%; object-fit: contain; background: #fff; padding: 3px;
    border: 2px solid rgba(255,255,255,.8); box-shadow: 0 4px 10px rgba(2,6,23,.28); margin-left: -8px;
}
.pk-app__wrap .coin-icons-row img:first-child { margin-left: 0; }
.pk-app__wrap .ch-withdraw-strip {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 1rem 1.2rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.pk-app__wrap .ch-wallet-strip {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
    gap: 12px;
}
.pk-app__wrap .cw-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: .95rem 1rem;
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    cursor: pointer;
}
.pk-app__wrap .cw-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 26px -18px rgba(11, 18, 32, .3);
    border-color: color-mix(in srgb, var(--brand) 45%, var(--line));
}
.pk-app__wrap .cw-icon {
    width: 38px; height: 38px; border-radius: 12px; background: var(--wash); border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.pk-app__wrap .cw-icon img { width: 22px; height: 22px; object-fit: contain; }
.pk-app__wrap .ch-desk-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 20px;
    box-shadow: 0 12px 28px -20px rgba(11, 18, 32, .25);
    overflow: hidden;
}
.pk-app__wrap .ch-tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    background: var(--wash);
    padding: 6px;
    border-radius: 14px;
    border: 1px solid var(--line);
}
.pk-app__wrap .ch-tab {
    border: 0;
    background: transparent;
    color: var(--muted);
    font-weight: 700;
    font-size: .88rem;
    padding: 10px 8px;
    border-radius: 10px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background .15s ease, color .15s ease, box-shadow .15s ease;
}
.pk-app__wrap .ch-tab.is-active {
    background: var(--brand);
    color: var(--brand-fg, #fff);
    box-shadow: 0 6px 14px -8px color-mix(in srgb, var(--brand) 75%, #000);
}
.pk-app__wrap .ch-panel[hidden] { display: none !important; }
.pk-app__wrap .ch-quote-box {
    background: var(--wash);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: .95rem 1.05rem;
}
.pk-app__wrap .ch-addr-box {
    background: color-mix(in srgb, var(--brand) 6%, var(--surface));
    border: 1px dashed color-mix(in srgb, var(--brand) 45%, var(--line));
    border-radius: 14px;
    padding: 1rem;
}
.pk-app__wrap .ch-quick-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.pk-app__wrap .ch-chip {
    border: 1px solid var(--line);
    background: var(--surface);
    color: var(--ink);
    font-size: .75rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 999px;
    cursor: pointer;
}
.pk-app__wrap .ch-chip:hover {
    border-color: var(--brand);
    color: var(--brand-ink);
}
.pk-app__wrap .ch-side-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 1.2rem;
}
.pk-app__wrap .ch-route-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 14px;
    border: 1px solid var(--line);
    background: var(--wash);
    color: var(--ink);
    text-decoration: none;
    transition: border-color .15s ease, transform .15s ease;
}
.pk-app__wrap .ch-route-btn:hover {
    border-color: var(--brand);
    transform: translateY(-1px);
    color: var(--brand-ink);
}
@media (max-width: 575.98px) {
    .pk-app__wrap .ch-hero { padding: 1.2rem; border-radius: 18px; }
    .pk-app__wrap .ch-tab { font-size: .8rem; padding: 9px 4px; }
}
</style>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "CRYPTO HUB", "crumb" => "Home / Crypto Hub")); ?>

<section class="section dashboard">
    <?php if (is_array($flash) && !empty($flash['message'])): ?>
        <div class="alert alert-<?php echo !empty($flash['ok']) ? 'success' : 'danger'; ?> border-0 rounded-4 shadow-sm mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <i class="bi <?php echo !empty($flash['ok']) ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
                <?php echo htmlspecialchars((string)$flash['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php if (!empty($flash['ok']) && !empty($flash['to_is_ngn'])): ?>
                <div class="d-flex gap-2">
                    <a href="SendFund.php" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">Send Fund (Bank)</a>
                    <a href="IntlTransfer.php" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold">Intl Transfer</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Modern Hero -->
    <div class="ch-hero mb-4">
        <div class="row align-items-center g-3 position-relative" style="z-index: 2;">
            <div class="col-lg-7">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="ch-hero__pill"><i class="bi bi-shield-check"></i> Admin ↔ User Desk (A2P / P2A)</span>
                    <span class="ch-hero__pill"><i class="bi bi-graph-up-arrow"></i> <?php echo htmlspecialchars($rateSourceBadge, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="small opacity-75 fw-semibold">NAIRA WALLET BALANCE (FOR BUY, SWAP &amp; WITHDRAWAL)</div>
                <div class="ch-hero__bal mb-2">₦<?php echo number_format($ngnBalance, 2); ?></div>
                <div class="small opacity-90 mb-3">
                    Crypto Portfolio Value: <strong>≈ ₦<?php echo number_format($portfolioNgn, 2); ?></strong>
                </div>
                <div class="coin-icons-row">
                    <img src="../asset/ngn-icon.png" alt="NGN" title="Naira">
                    <img src="../asset/crypto/btc.png" alt="BTC" title="Bitcoin">
                    <img src="../asset/crypto/eth.png" alt="ETH" title="Ethereum">
                    <img src="../asset/crypto/ltc.png" alt="LTC" title="Litecoin">
                    <img src="../asset/crypto/trx.png" alt="TRX" title="Tron">
                    <img src="../asset/crypto/usdt-trx.png" alt="USDT" title="Tether TRC-20">
                    <img src="../asset/crypto/usdc.png" alt="USDC" title="USD Coin">
                    <img src="../asset/crypto/bch.png" alt="BCH" title="Bitcoin Cash">
                    <img src="../asset/crypto/doge.png" alt="DOGE" title="Dogecoin">
                </div>
            </div>
            <div class="col-lg-5 text-lg-end">
                <div class="bg-white bg-opacity-10 p-3 rounded-4 border border-white border-opacity-20 text-start d-inline-block w-100" style="max-width: 360px;">
                    <div class="small fw-bold mb-1"><i class="bi bi-arrow-right-circle me-1"></i> How Withdrawals Work</div>
                    <div class="small opacity-90 mb-3">Sell or swap your crypto into Naira (`NGN`), then withdraw locally or abroad in one click:</div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="SendFund.php" class="btn btn-light btn-sm rounded-pill px-3 fw-bold text-primary">
                            <i class="bi bi-bank me-1"></i> Local Bank (Send Fund)
                        </a>
                        <a href="IntlTransfer.php" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-bold">
                            <i class="bi bi-globe2 me-1"></i> Intl Transfer
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($a2pCfg['desk_notice'])): ?>
    <div class="ch-withdraw-strip mb-4">
        <div class="d-flex align-items-center gap-2 small">
            <i class="bi bi-info-circle-fill text-primary fs-5"></i>
            <span><?php echo htmlspecialchars((string)$a2pCfg['desk_notice'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
            <a href="CryptoOverview.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Overview</a>
            <a href="CryptoInvoices.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">Invoices</a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($kycBlocked): ?>
    <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3 p-4">
        <div class="d-flex align-items-center">
            <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3 me-3">
                <i class="bi bi-shield-lock-fill fs-3"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1">KYC Verification Required</h6>
                <p class="small mb-0 text-muted">CryptoHub Buy, Sell, and Swap actions are locked until your identity is verified.</p>
            </div>
        </div>
        <a href="KYCVerification.php" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">Complete KYC</a>
    </div>
    <?php endif; ?>

    <!-- My Crypto Wallets -->
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="fw-bold mb-0">My Crypto Wallets</h6>
        <span class="text-muted small">Click any asset to trade</span>
    </div>
    <div class="ch-wallet-strip mb-4">
        <?php foreach ($walletCards as $wc): ?>
        <div class="cw-card" onclick="selectCryptoAsset('<?php echo htmlspecialchars($wc['code'], ENT_QUOTES, 'UTF-8'); ?>')">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="cw-icon">
                        <img src="../asset/crypto/<?php echo htmlspecialchars($wc['icon'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($wc['symbol'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <div class="fw-bold small lh-sm"><?php echo htmlspecialchars($wc['symbol'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-muted" style="font-size:.68rem;"><?php echo htmlspecialchars($wc['network_label'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <?php if ($wc['quote']): ?>
                <span class="badge bg-light text-dark rounded-pill" style="font-size:.65rem;">$<?php echo number_format((float)$wc['quote']['usd_price'], $wc['quote']['usd_price'] >= 10 ? 2 : 4); ?></span>
                <?php endif; ?>
            </div>
            <div class="h6 fw-bold mb-0"><?php echo rtrim(rtrim(sprintf('%.8f', (float)$wc['balance']), '0'), '.') ?: '0'; ?> <?php echo htmlspecialchars($wc['symbol'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="text-muted" style="font-size:.72rem;">≈ ₦<?php echo number_format((float)$wc['val_ngn'], 2); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Main 3-Tab Desk + Side Withdrawal / Rates Guide -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="ch-desk-card p-3 p-md-4 mb-4">
                <div class="ch-tabs mb-4" role="tablist" aria-label="CryptoHub Desk">
                    <button type="button" class="ch-tab <?php echo $activeTab === 'buy' ? 'is-active' : ''; ?>" data-ch-tab="buy" onclick="switchDeskTab('buy')">
                        <i class="bi bi-bag-plus"></i> Buy
                    </button>
                    <button type="button" class="ch-tab <?php echo $activeTab === 'sell' ? 'is-active' : ''; ?>" data-ch-tab="sell" onclick="switchDeskTab('sell')">
                        <i class="bi bi-cash-coin"></i> Sell
                    </button>
                    <button type="button" class="ch-tab <?php echo $activeTab === 'swap' ? 'is-active' : ''; ?>" data-ch-tab="swap" onclick="switchDeskTab('swap')">
                        <i class="bi bi-arrow-left-right"></i> Swap
                    </button>
                </div>

                <!-- TAB 1: BUY CRYPTO (A2P) -->
                <div class="ch-panel" id="chPanelBuy" <?php echo $activeTab !== 'buy' ? 'hidden' : ''; ?>>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h6 class="fw-bold mb-0">Buy Crypto from Admin (A2P)</h6>
                            <small class="text-muted">Pay from your Naira balance; admin fulfills to your wallet or internal balance.</small>
                        </div>
                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2">
                            <?php echo $a2pCfg['rate_mode'] === 'live' ? 'Live Rate' : 'Fixed Rate'; ?>
                        </span>
                    </div>

                    <form method="post" id="a2pBuyForm">
                        <?php echo $csrfFieldHtml; ?>
                        <input type="hidden" name="a2p_action" value="a2p_buy">
                        <input type="hidden" name="amount_unit" id="buyUnitInput" value="NGN">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Cryptocurrency &amp; Network</label>
                                <select name="asset" id="buyAsset" class="form-select" required onchange="recalcBuy()">
                                    <?php foreach ($enabledQuotes as $code => $q): if (!$q['buy_enabled']) continue; ?>
                                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($q['name'], ENT_QUOTES, 'UTF-8'); ?> — ₦<?php echo number_format((float)$q['buy_rate_ngn'], 2); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Delivery Destination</label>
                                <select name="delivery_mode" id="buyDelivery" class="form-select" onchange="toggleBuyAddress()">
                                    <option value="external">External Wallet Address</option>
                                    <option value="internal">My CryptoHub Wallet (Internal)</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold mb-0" id="buyAmtLabel">Amount to Spend (NGN)</label>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-bold" onclick="toggleBuyUnit()">Switch to <span id="buyAltUnit">Crypto</span></button>
                                </div>
                                <div class="input-group">
                                    <span class="input-group-text fw-bold" id="buyUnitBadge">NGN</span>
                                    <input type="number" step="any" min="0" name="amount" id="buyAmount" class="form-control" placeholder="e.g. 20000" required oninput="recalcBuy()">
                                </div>
                                <div class="ch-quick-chips mt-2">
                                    <button type="button" class="ch-chip" onclick="setBuyQuick(5000)">₦5,000</button>
                                    <button type="button" class="ch-chip" onclick="setBuyQuick(20000)">₦20,000</button>
                                    <button type="button" class="ch-chip" onclick="setBuyQuick(50000)">₦50,000</button>
                                    <button type="button" class="ch-chip" onclick="setBuyQuick(100000)">₦100,000</button>
                                </div>
                            </div>

                            <div class="col-12" id="buyAddrWrap">
                                <label class="form-label small fw-bold">Receiving Wallet Address</label>
                                <input type="text" name="destination_address" id="buyDestAddr" class="form-control font-monospace" placeholder="Enter your wallet address">
                                <div class="form-text" id="buyAddrHint">Match the exact network above to avoid loss of funds.</div>
                            </div>

                            <div class="col-12">
                                <div class="ch-quote-box">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Buy Rate</span>
                                        <strong id="buyRatePreview">—</strong>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Total Debited (NGN Wallet)</span>
                                        <strong id="buyNgnPreview">₦0.00</strong>
                                    </div>
                                    <div class="d-flex justify-content-between pt-2 border-top">
                                        <span class="small fw-bold">Estimated You Receive</span>
                                        <strong class="text-primary" id="buyCryptoPreview">0.00</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Transaction PIN</label>
                                <input type="password" name="pin" class="form-control" maxlength="8" inputmode="numeric" placeholder="Enter PIN" required <?php echo $kycBlocked ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100 py-2 rounded-3 fw-bold" <?php echo ($kycBlocked || empty($enabledQuotes)) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-check2-circle me-1"></i> Place Buy Order
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- TAB 2: SELL CRYPTO (P2A) -->
                <div class="ch-panel" id="chPanelSell" <?php echo $activeTab !== 'sell' ? 'hidden' : ''; ?>>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h6 class="fw-bold mb-0">Sell Crypto to Admin (P2A)</h6>
                            <small class="text-muted">Send crypto to the admin wallet (or sell from your CryptoHub wallet) to receive Naira.</small>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2">
                            <?php echo $a2pCfg['rate_mode'] === 'live' ? 'Live Rate' : 'Fixed Rate'; ?>
                        </span>
                    </div>

                    <form method="post" id="p2aSellForm">
                        <?php echo $csrfFieldHtml; ?>
                        <input type="hidden" name="a2p_action" value="p2a_sell">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Cryptocurrency &amp; Network</label>
                                <select name="asset" id="sellAsset" class="form-select" required onchange="recalcSell()">
                                    <?php foreach ($enabledQuotes as $code => $q): if (!$q['sell_enabled']) continue; ?>
                                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($q['name'], ENT_QUOTES, 'UTF-8'); ?> — ₦<?php echo number_format((float)$q['sell_rate_ngn'], 2); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Source of Funds</label>
                                <select name="sell_source" id="sellSource" class="form-select" onchange="toggleSellSource()">
                                    <option value="external">External Wallet (Send to Admin Address)</option>
                                    <option value="internal">My CryptoHub Wallet Balance</option>
                                </select>
                            </div>

                            <div class="col-12" id="sellExternalWrap">
                                <div class="ch-addr-box">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                        <span class="small fw-bold text-primary"><i class="bi bi-wallet2 me-1"></i> Admin Receiving Address (<span id="sellNetBadge">—</span>)</span>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="copySellAdminAddr()">
                                            <i class="bi bi-clipboard me-1"></i> Copy Address
                                        </button>
                                    </div>
                                    <div class="input-group mb-2">
                                        <input type="text" id="sellAdminAddr" class="form-control font-monospace bg-white" readonly value="">
                                    </div>
                                    <div class="small text-muted" id="sellAdminNote"></div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Crypto Amount to Sell</label>
                                <input type="number" step="any" min="0" name="amount" id="sellAmount" class="form-control" placeholder="e.g. 50" required oninput="recalcSell()">
                                <div class="form-text" id="sellWalletAvail"></div>
                            </div>
                            <div class="col-md-6" id="sellTxWrap">
                                <label class="form-label small fw-bold">Blockchain TX Hash / Reference</label>
                                <input type="text" name="tx_hash" id="sellTxHash" class="form-control font-monospace" placeholder="Paste TXID after sending">
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold">Note for Admin (Optional)</label>
                                <input type="text" name="sender_note" class="form-control" maxlength="180" placeholder="Optional sender wallet or memo">
                            </div>

                            <div class="col-12">
                                <div class="ch-quote-box">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Sell Rate</span>
                                        <strong id="sellRatePreview">—</strong>
                                    </div>
                                    <div class="d-flex justify-content-between pt-2 border-top">
                                        <span class="small fw-bold">Payout to Your Naira (`NGN`) Wallet</span>
                                        <strong class="text-success" id="sellNgnPreview">₦0.00</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Transaction PIN</label>
                                <input type="password" name="pin" class="form-control" maxlength="8" inputmode="numeric" placeholder="Enter PIN" required <?php echo $kycBlocked ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="submit" class="btn btn-success w-100 py-2 rounded-3 fw-bold" <?php echo ($kycBlocked || empty($enabledQuotes)) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-send-check me-1"></i> Submit Sell Order
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- TAB 3: SWAP (INSTANT BOOK-ENTRY) -->
                <div class="ch-panel" id="chPanelSwap" <?php echo $activeTab !== 'swap' ? 'hidden' : ''; ?>>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h6 class="fw-bold mb-0">Instant Wallet Swap</h6>
                            <small class="text-muted">Convert between your CryptoHub wallets and your Naira (`NGN`) wallet instantaneously.</small>
                        </div>
                        <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-2">
                            Swap Fee: <?php echo number_format((float)$a2pCfg['swap_fee_pct'], 2); ?>%
                        </span>
                    </div>

                    <form method="post" id="a2pSwapForm">
                        <?php echo $csrfFieldHtml; ?>
                        <input type="hidden" name="a2p_action" value="a2p_swap">

                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label small fw-bold">From Wallet</label>
                                <select name="swap_from" id="swapFrom" class="form-select" onchange="recalcSwap()">
                                    <?php foreach ($enabledQuotes as $code => $q): if (!$q['swap_enabled']) continue; $wBal = (float)($userWallets[$code]['balance'] ?? 0); ?>
                                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($q['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo rtrim(rtrim(sprintf('%.8f', $wBal), '0'), '.') ?: '0'; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                    <option value="NGN">Naira Wallet (₦<?php echo number_format($ngnBalance, 2); ?>)</option>
                                </select>
                            </div>
                            <div class="col-md-2 text-center">
                                <button type="button" class="btn btn-outline-secondary rounded-circle" title="Flip Swap Direction" onclick="flipSwapPair()">
                                    <i class="bi bi-arrow-left-right"></i>
                                </button>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-bold">To Wallet</label>
                                <select name="swap_to" id="swapTo" class="form-select" onchange="recalcSwap()">
                                    <option value="NGN" selected>Naira Wallet (NGN — Withdraw via Send Fund / Intl Transfer)</option>
                                    <?php foreach ($enabledQuotes as $code => $q): if (!$q['swap_enabled']) continue; ?>
                                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($q['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold">Amount to Swap</label>
                                <input type="number" step="any" min="0" name="amount" id="a2pSwapAmount" class="form-control" placeholder="0.00" required oninput="recalcSwap()">
                            </div>

                            <div class="col-12">
                                <div class="ch-quote-box">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Gross NGN Equivalent</span>
                                        <strong id="swapGrossPreview">₦0.00</strong>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Swap Fee (<?php echo number_format((float)$a2pCfg['swap_fee_pct'], 2); ?>%)</span>
                                        <strong class="text-danger" id="swapFeePreview">₦0.00</strong>
                                    </div>
                                    <div class="d-flex justify-content-between pt-2 border-top">
                                        <span class="small fw-bold">Estimated You Receive</span>
                                        <strong class="text-success" id="swapOutPreview">0.00</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Transaction PIN</label>
                                <input type="password" name="pin" class="form-control" maxlength="8" inputmode="numeric" placeholder="Enter PIN" required <?php echo $kycBlocked ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-md-6">
                                <button type="submit" class="btn btn-primary w-100 py-2 rounded-3 fw-bold" <?php echo ($kycBlocked || empty($enabledQuotes)) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-lightning-charge me-1"></i> Convert Now
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Orders & Activity History -->
            <div class="card crypto-card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="fw-bold mb-0">CryptoHub Orders &amp; Activity</h6>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="openSelfHelpModal()">Check Invoice</button>
                        <a href="CryptoInvoices.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">All Invoices</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover small mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 border-0">Order / Date</th>
                                    <th class="border-0">Type</th>
                                    <th class="border-0">Asset &amp; NGN</th>
                                    <th class="pe-4 border-0">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $res_orders = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `vendor_id`='$vid' AND `username`='" . mysqli_real_escape_string($connection_server, $username) . "' ORDER BY `id` DESC LIMIT 12");
                                if ($res_orders && mysqli_num_rows($res_orders) > 0):
                                    while ($row = mysqli_fetch_assoc($res_orders)):
                                        $meta = json_decode((string)$row['metadata'], true);
                                        if (!is_array($meta)) $meta = [];
                                        $s = (string)$row['status'];
                                        $c = ($s === '1') ? 'success' : (($s === '2') ? 'warning' : 'danger');
                                        $txt = ($s === '1') ? 'Completed' : (($s === '2') ? 'Pending Review' : 'Rejected');
                                        $ngnVal = (float)($meta['ngn_amount'] ?? $meta['net_ngn'] ?? 0);
                                        $typeLabel = strtoupper(str_replace('_', ' ', (string)$row['type']));
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold font-monospace" style="font-size:.76rem;"><?php echo htmlspecialchars((string)$row['reference'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-muted" style="font-size:.7rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <span class="fw-bold"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if (!empty($row['blockchain_txid'])): ?>
                                        <div class="text-muted text-truncate" style="max-width:130px; font-size:.68rem;">TX: <?php echo htmlspecialchars((string)$row['blockchain_txid'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo rtrim(rtrim(sprintf('%.8f', (float)$row['amount']), '0'), '.'); ?> <?php echo htmlspecialchars((string)$row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php if ($ngnVal > 0): ?><div class="text-muted" style="font-size:.72rem;">₦<?php echo number_format($ngnVal, 2); ?></div><?php endif; ?>
                                    </td>
                                    <td class="pe-4">
                                        <span class="badge bg-<?php echo $c; ?> bg-opacity-10 text-<?php echo $c; ?> rounded-pill"><?php echo $txt; ?></span>
                                        <?php if (!empty($meta['reject_reason'])): ?>
                                        <div class="text-danger mt-1" style="font-size:.68rem;"><?php echo htmlspecialchars((string)$meta['reject_reason'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No CryptoHub orders yet. Start with Buy, Sell, or Swap above.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Legacy Invoice & Activity Compatibility Feed (for existing history window) -->
            <?php
            $res_other = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `vendor_id`='$vid' AND `username`='" . mysqli_real_escape_string($connection_server, $username) . "' ORDER BY `id` DESC LIMIT 3, 7");
            if ($res_other && mysqli_num_rows($res_other) > 0):
            ?>
            <div class="card crypto-card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-3">
                    <h6 class="fw-bold mb-0">Older Activity &amp; Legacy Invoices</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover small mb-0">
                            <tbody>
                                <?php while ($row = mysqli_fetch_assoc($res_other)):
                                    $s = (string)$row['status'];
                                    $c = ($s === '1') ? 'success' : (($s === '2') ? 'warning' : 'danger');
                                    $txt = ($s === '1') ? 'Paid' : (($s === '2') ? 'Pending' : 'Failed');
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <span class="text-uppercase small fw-bold"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$row['type']), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <div class="text-muted" style="font-size:0.7rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="fw-bold"><?php echo (float)$row['amount']; ?></td>
                                    <td class="pe-4">
                                        <a href="ViewCryptoInvoice.php?ref=<?php echo urlencode((string)$row['reference']); ?>" class="badge bg-<?php echo $c; ?> bg-opacity-10 text-<?php echo $c; ?> rounded-pill text-decoration-none"><?php echo $txt; ?></a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Withdrawal Routes & Live Rate Board -->
        <div class="col-lg-4">
            <div class="ch-side-card mb-4">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-primary bg-opacity-10 text-primary p-2 rounded-3"><i class="bi bi-cash-stack fs-5"></i></span>
                    <div>
                        <h6 class="fw-bold mb-0">Withdraw Swapped Funds</h6>
                        <small class="text-muted">Settles to your Naira (NGN) Wallet</small>
                    </div>
                </div>
                <p class="small text-muted mb-3">
                    Once you <strong>Sell</strong> or <strong>Swap</strong> crypto to Naira, your money is available in your NGN Wallet (<strong>₦<?php echo number_format($ngnBalance, 2); ?></strong>) and can be withdrawn anywhere:
                </p>
                <div class="d-grid gap-2">
                    <a href="SendFund.php" class="ch-route-btn">
                        <div>
                            <div class="fw-bold small"><i class="bi bi-bank text-primary me-1"></i> Local Bank Withdrawal</div>
                            <div class="text-muted" style="font-size:.73rem;">Transfer NGN to any Nigerian bank via Send Fund</div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                    <a href="IntlTransfer.php" class="ch-route-btn">
                        <div>
                            <div class="fw-bold small"><i class="bi bi-globe2 text-success me-1"></i> International Transfer</div>
                            <div class="text-muted" style="font-size:.73rem;">Spend NGN for USD, GBP, EUR &amp; CNY transfers</div>
                        </div>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                </div>
            </div>

            <div class="ch-side-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0">Desk Rates (NGN)</h6>
                    <span class="badge bg-light text-dark rounded-pill" style="font-size:.68rem;"><?php echo htmlspecialchars($rateSourceBadge, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($enabledQuotes as $code => $q): ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                        <div>
                            <div class="fw-bold"><?php echo htmlspecialchars($q['symbol'], ENT_QUOTES, 'UTF-8'); ?> <span class="text-muted fw-normal" style="font-size:.72rem;">(<?php echo htmlspecialchars($q['network'], ENT_QUOTES, 'UTF-8'); ?>)</span></div>
                            <div class="text-muted" style="font-size:.7rem;">$<?php echo number_format((float)$q['usd_price'], $q['usd_price'] >= 10 ? 2 : 4); ?></div>
                        </div>
                        <div class="text-end" style="font-size:.74rem;">
                            <div>Buy: <strong class="text-primary">₦<?php echo number_format((float)$q['buy_rate_ngn'], 2); ?></strong></div>
                            <div>Sell: <strong class="text-success">₦<?php echo number_format((float)$q['sell_rate_ngn'], 2); ?></strong></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-muted mt-2" style="font-size:.7rem;">
                    Limits: ₦<?php echo number_format((float)$a2pCfg['min_trade_ngn'], 0); ?> – ₦<?php echo number_format((float)$a2pCfg['max_trade_ngn'], 0); ?> · Updated: <?php echo htmlspecialchars((string)$a2pRates['updated_at'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Legacy Self-Help & Compatibility Modals (retained for legacy invoice sync / regression guards) -->
<div class="modal fade" id="selfHelpModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold">Check Legacy Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small">Have a legacy invoice reference? Enter it below to open its details or check status.</p>
                <input type="text" id="syncRef" class="form-control mb-3" placeholder="Invoice Reference">
                <button class="btn btn-warning w-100 py-2 rounded-3 fw-bold" onclick="triggerManualSync(this)">Open Invoice</button>
            </div>
        </div>
    </div>
</div>

<div class="d-none" aria-hidden="true">
    <div id="depositModal"></div>
    <div id="swapModal"><form id="swapForm"></form></div>
    <div id="withdrawModal">
        <form id="withdrawForm">
            <input type="hidden" name="currency" id="withdrawCid">
            <button type="button" class="btn btn-outline-primary" disabled title="Crypto services are not provisioned; no code will be sent">Unavailable</button>
        </form>
    </div>
</div>

<script>
const CH_QUOTES = <?php
$publicQuotes = [];
foreach ($enabledQuotes as $qCode => $qRow) {
    unset($qRow['buy_markup_pct'], $qRow['sell_markdown_pct']);
    $publicQuotes[$qCode] = $qRow;
}
echo json_encode($publicQuotes, JSON_UNESCAPED_SLASHES);
?>;
const CH_WALLETS = <?php echo json_encode(array_map(function($w) { return (float)($w['balance'] ?? 0); }, $userWallets), JSON_UNESCAPED_SLASHES); ?>;
const CH_SWAP_FEE_PCT = <?php echo json_encode((float)$a2pCfg['swap_fee_pct']); ?>;
const CH_NGN_BAL = <?php echo json_encode((float)$ngnBalance); ?>;

function switchDeskTab(tab) {
    ['buy', 'sell', 'swap'].forEach(t => {
        const btn = document.querySelector(`[data-ch-tab="${t}"]`);
        const panel = document.getElementById('chPanel' + t.charAt(0).toUpperCase() + t.slice(1));
        if (btn) btn.classList.toggle('is-active', t === tab);
        if (panel) panel.hidden = (t !== tab);
    });
}

function selectCryptoAsset(code) {
    if (CH_QUOTES[code]) {
        const buySel = document.getElementById('buyAsset');
        const sellSel = document.getElementById('sellAsset');
        const swapFrom = document.getElementById('swapFrom');
        if (buySel && buySel.querySelector(`option[value="${code}"]`)) buySel.value = code;
        if (sellSel && sellSel.querySelector(`option[value="${code}"]`)) sellSel.value = code;
        if (swapFrom && swapFrom.querySelector(`option[value="${code}"]`)) swapFrom.value = code;
        recalcBuy();
        recalcSell();
        recalcSwap();
        document.querySelector('.ch-desk-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function formatNgn(v) {
    return '₦' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatCrypto(v, sym) {
    if (!v || v <= 0) return '0.00 ' + (sym || '');
    return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 8 }) + ' ' + (sym || '');
}

function toggleBuyUnit() {
    const unitEl = document.getElementById('buyUnitInput');
    const next = (unitEl.value === 'NGN') ? 'CRYPTO' : 'NGN';
    unitEl.value = next;
    const code = document.getElementById('buyAsset')?.value;
    const sym = CH_QUOTES[code]?.symbol || 'Crypto';
    document.getElementById('buyUnitBadge').textContent = (next === 'NGN') ? 'NGN' : sym;
    document.getElementById('buyAmtLabel').textContent = (next === 'NGN') ? 'Amount to Spend (NGN)' : `Crypto Quantity to Buy (${sym})`;
    document.getElementById('buyAltUnit').textContent = (next === 'NGN') ? sym : 'NGN';
    recalcBuy();
}

function setBuyQuick(ngn) {
    const unitEl = document.getElementById('buyUnitInput');
    if (unitEl.value !== 'NGN') toggleBuyUnit();
    document.getElementById('buyAmount').value = ngn;
    recalcBuy();
}

function toggleBuyAddress() {
    const mode = document.getElementById('buyDelivery')?.value || 'external';
    const wrap = document.getElementById('buyAddrWrap');
    const inp = document.getElementById('buyDestAddr');
    if (wrap) wrap.style.display = (mode === 'external') ? 'block' : 'none';
    if (inp) inp.required = (mode === 'external');
}

function recalcBuy() {
    const code = document.getElementById('buyAsset')?.value;
    const q = CH_QUOTES[code];
    if (!q) return;
    document.getElementById('buyAddrHint').textContent = q.address_hint || 'Match the exact network above.';
    if (document.getElementById('buyUnitInput').value === 'CRYPTO') {
        document.getElementById('buyUnitBadge').textContent = q.symbol;
    }
    const amt = parseFloat(document.getElementById('buyAmount')?.value || '0') || 0;
    const rate = parseFloat(q.buy_rate_ngn || '0') || 0;
    const unit = document.getElementById('buyUnitInput').value;
    let ngnTotal = 0, cryptoAmt = 0;
    if (amt > 0 && rate > 0) {
        if (unit === 'NGN') {
            ngnTotal = amt;
            cryptoAmt = amt / rate;
        } else {
            cryptoAmt = amt;
            ngnTotal = amt * rate;
        }
    }
    document.getElementById('buyRatePreview').textContent = formatNgn(rate) + ' / ' + q.symbol;
    document.getElementById('buyNgnPreview').textContent = formatNgn(ngnTotal);
    document.getElementById('buyCryptoPreview').textContent = formatCrypto(cryptoAmt, q.symbol);
}

function toggleSellSource() {
    const src = document.getElementById('sellSource')?.value || 'external';
    document.getElementById('sellExternalWrap').style.display = (src === 'external') ? 'block' : 'none';
    document.getElementById('sellTxWrap').style.display = (src === 'external') ? 'block' : 'none';
    const txInp = document.getElementById('sellTxHash');
    if (txInp) txInp.required = (src === 'external');
    recalcSell();
}

function recalcSell() {
    const code = document.getElementById('sellAsset')?.value;
    const q = CH_QUOTES[code];
    if (!q) return;
    document.getElementById('sellNetBadge').textContent = q.network_label;
    document.getElementById('sellAdminAddr').value = q.deposit_address || 'Not configured by admin yet';
    document.getElementById('sellAdminNote').textContent = q.network_note || ('Send only ' + q.name + ' on ' + q.network_label + '.');
    const wBal = parseFloat(CH_WALLETS[code] || '0') || 0;
    const src = document.getElementById('sellSource')?.value || 'external';
    document.getElementById('sellWalletAvail').textContent = (src === 'internal')
        ? ('Available CryptoHub balance: ' + formatCrypto(wBal, q.symbol))
        : ('Send ' + q.symbol + ' on ' + q.network_label);

    const amt = parseFloat(document.getElementById('sellAmount')?.value || '0') || 0;
    const rate = parseFloat(q.sell_rate_ngn || '0') || 0;
    const payout = amt * rate;
    document.getElementById('sellRatePreview').textContent = formatNgn(rate) + ' / ' + q.symbol;
    document.getElementById('sellNgnPreview').textContent = formatNgn(payout);
}

function copySellAdminAddr() {
    const addr = document.getElementById('sellAdminAddr')?.value || '';
    if (!addr || addr.startsWith('Not configured')) return;
    navigator.clipboard?.writeText(addr);
    if (window.Swal) {
        Swal.fire({ title: 'Copied', text: 'Admin deposit address copied.', icon: 'success', timer: 1600, showConfirmButton: false });
    }
}

function flipSwapPair() {
    const fromEl = document.getElementById('swapFrom');
    const toEl = document.getElementById('swapTo');
    const f = fromEl.value;
    const t = toEl.value;
    if (fromEl.querySelector(`option[value="${t}"]`) && toEl.querySelector(`option[value="${f}"]`)) {
        fromEl.value = t;
        toEl.value = f;
        recalcSwap();
    }
}

function recalcSwap() {
    const from = document.getElementById('swapFrom')?.value || '';
    const to = document.getElementById('swapTo')?.value || '';
    const amt = parseFloat(document.getElementById('a2pSwapAmount')?.value || '0') || 0;
    let grossNgn = 0;
    if (amt > 0) {
        if (from === 'NGN') grossNgn = amt;
        else if (CH_QUOTES[from]) grossNgn = amt * (parseFloat(CH_QUOTES[from].base_ngn || '0') || 0);
    }
    const feeNgn = grossNgn * (CH_SWAP_FEE_PCT / 100);
    const netNgn = Math.max(0, grossNgn - feeNgn);
    let outText = '0.00';
    if (from === to) {
        outText = 'Choose different wallets';
    } else if (to === 'NGN') {
        outText = formatNgn(netNgn);
    } else if (CH_QUOTES[to] && CH_QUOTES[to].base_ngn > 0) {
        outText = formatCrypto(netNgn / CH_QUOTES[to].base_ngn, CH_QUOTES[to].symbol);
    }
    document.getElementById('swapGrossPreview').textContent = formatNgn(grossNgn);
    document.getElementById('swapFeePreview').textContent = formatNgn(feeNgn);
    document.getElementById('swapOutPreview').textContent = outText;
}

function openSelfHelpModal(ref = '') {
    document.getElementById('syncRef').value = ref;
    new bootstrap.Modal(document.getElementById('selfHelpModal')).show();
}

function triggerManualSync() {
    const ref = (document.getElementById('syncRef')?.value || '').trim();
    if (!ref) return;
    window.location.href = 'ViewCryptoInvoice.php?ref=' + encodeURIComponent(ref);
}

document.addEventListener('DOMContentLoaded', () => {
    toggleBuyAddress();
    recalcBuy();
    toggleSellSource();
    recalcSell();
    recalcSwap();
});
</script>

<?php bc_app_foot(); ?>
