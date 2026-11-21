<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");
include("../func/bc-crypto-func.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}

$vid = $get_logged_user_details['vendor_id'];
$username = $get_logged_user_details['username'];

if(!isServiceEnabled('crypto_hub')){
    header("Location: Dashboard.php");
    exit();
}

// §28: this page adapts to the vendor's ACTIVE provider (bc-admin → Service
// Control). Only that provider's assets/services exist here and every trade
// action fails closed through the provider seam.
include_once(__DIR__ . "/../func/bc-fintech-services.php");
$crypto_provider = bc_svc_active_provider($vid);
$crypto_provider_mode = true; // §28: the legacy provider is removed; provider mode is the only mode.
$crypto_provider_label = bc_svc_provider_label($crypto_provider);
if ($crypto_provider_mode && (isset($_POST['action']) || isset($_GET['get_rate']))) {
    echo json_encode(['status' => 'error', 'message' => 'Crypto services are being provisioned for this platform. No trade was executed.']);
    exit();
}

// §28: the legacy crypto provider was removed entirely. Every trade action
// (rate/deposit/swap/withdraw/manual sync) is refused by the gate above until
// the service is provisioned on the vendor's ACTIVE gateway API — no call is
// ever routed to the retired provider behind the scenes. Wallet balances and
// history below are local records and stay fully visible.

$currencies = []; // §28: no provider currency catalogue is consulted.
$userWallets = getUserCryptoWallets($vid, $username);
$supported = bc_svc_provider_assets($vid);

// Keep only assets that exist in the active provider's catalogue
$active_supported = [];
foreach($supported as $s) {
    foreach($currencies as $c) {
        if($c['cid'] == $s) {
            $active_supported[] = $s;
            break;
        }
    }
}
if (empty($active_supported)) $active_supported = $supported; // Fallback
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Crypto | " . ($get_all_site_details["site_title"]));
?>
<style data-pkx="page-redesign">
/* CryptoHub — fintech redesign (2026-11): sized coin art, glass hero actions,
   wallet cards and an action rail on the shared console shell. */
.pk-app__wrap .coin-icons-row{display:flex;align-items:center;flex-wrap:wrap}
.pk-app__wrap .coin-icons-row img{width:40px;height:40px;border-radius:50%;object-fit:contain;background:#fff;padding:3px;border:2px solid rgba(255,255,255,.75);box-shadow:0 4px 10px rgba(2,6,23,.35);margin-left:-10px}
.pk-app__wrap .coin-icons-row img:first-child{margin-left:0}
.pk-app__wrap .quick-action-btn{display:flex;flex-direction:column;align-items:center;gap:6px;padding:12px 4px;border-radius:16px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22);color:#fff;font-weight:700;font-size:.78rem;text-decoration:none;backdrop-filter:blur(6px);transition:transform .15s ease,background .15s ease,box-shadow .15s ease}
.pk-app__wrap .quick-action-btn:hover{background:rgba(255,255,255,.2);color:#fff;transform:translateY(-2px);box-shadow:0 14px 26px -14px rgba(2,6,23,.6)}
.pk-app__wrap .quick-action-btn i{font-size:1.1rem}
.pk-app__wrap .cw-card{transition:transform .15s ease,box-shadow .15s ease}
.pk-app__wrap .cw-card:hover{transform:translateY(-3px);box-shadow:0 16px 30px -16px rgba(2,6,23,.35)!important}
.pk-app__wrap .cw-icon{width:46px;height:46px;border-radius:14px;background:var(--wash);border:1px solid var(--line);display:flex;align-items:center;justify-content:center}
.pk-app__wrap .cw-icon img{width:26px;height:26px;object-fit:contain}
.pk-app__wrap .crypto-side-icon{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem}
.pk-app__wrap .crypto-side-icon img{width:100%;height:100%;object-fit:cover;border-radius:50%}
</style>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "CRYPTO", "crumb" => "Home / Crypto")); ?>
<?php if ($crypto_provider_mode): ?>
<div class="alert alert-info border-0 rounded-4 shadow-sm d-flex align-items-center gap-3">
    <i class="bi bi-diagram-3 fs-4"></i>
    <div><strong>Crypto Services</strong><br>
    <span class="small">Showing exactly the crypto services enabled on this platform: <?php echo htmlspecialchars(implode(', ', bc_svc_provider_assets($vid))); ?>. Where a swap pair is not offered, it is hidden. Trades activate as soon as the service connection is configured by admin.</span></div>
</div>
<?php endif; ?>

    <section class="section dashboard">

        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
            <div class="small">
                <strong>Safety Tip:</strong> Please always keep your <b>Transaction Reference</b> number. You will need it to manually sync your payment if it doesn't reflect within 5-10 minutes.
            </div>
        </div>

        <!-- Balance Hero -->
        <div class="balance-hero shadow mb-4">
            <div class="row align-items-center">
                <div class="col-md-7 mb-3 mb-md-0" style="position: relative; z-index: 10;">
                    <div class="small fw-semibold opacity-75 mb-1">AVAILABLE BALANCE</div>
                    <div class="balance-amount mb-2 d-flex align-items-center">
                        ₦<?php echo toDecimal($get_logged_user_details["balance"], "2"); ?>
                        <a href="CoinConversion.php" class="ms-3 d-flex align-items-center justify-content-center text-decoration-none" title="Get Bonus" style="width: 42px; height: 42px; background: rgba(255, 215, 0, 0.15); color: #FFD700; border-radius: 50%; border: 1px solid rgba(255, 215, 0, 0.3); transition: all 0.3s ease; backdrop-filter: blur(5px);">
                            <i class="bi bi-gift-fill" style="font-size: 1.25rem;"></i>
                        </a>
                    </div>

                    <div class="coin-icons-row mt-3 mb-2">
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

                    <div class="row row-cols-3 row-cols-md-5 g-2 mt-4" style="position: relative; z-index: 20;">
                        <div class="col"><a href="javascript:void(0)" onclick="openGeneralDepositModal()" class="quick-action-btn"><i class="bi bi-plus-lg"></i>Deposit</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openGeneralWithdrawModal()" class="quick-action-btn"><i class="bi bi-send"></i>Send</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openSwapModal()" class="quick-action-btn"><i class="bi bi-arrow-left-right"></i>Swap</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openSwapModal('NGN')" class="quick-action-btn"><i class="bi bi-bank"></i>To NGN</a></div>
                        <div class="col"><a href="javascript:void(0)" onclick="openSelfHelpModal()" class="quick-action-btn"><i class="bi bi-patch-question"></i>Self Help</a></div>
                    </div>
                </div>
                <div class="col-md-5 text-md-end">
                    <div class="bg-white bg-opacity-10 p-3 rounded-4 backdrop-blur border border-white border-opacity-10 d-inline-block text-start shadow-sm" style="min-width: 220px;">
                        <div class="d-flex justify-content-between mb-2 gap-3">
                            <span class="small opacity-75">Account Type</span>
                            <span class="fw-bold small"><?php echo accountLevel($get_logged_user_details["account_level"]); ?></span>
                        </div>
                        <div class="d-flex justify-content-between gap-3">
                            <span class="small opacity-75">Username</span>
                            <span class="fw-bold small">@<?php echo $get_logged_user_details["username"]; ?></span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="VirtualBanks.php" class="btn btn-light btn-sm rounded-pill px-4 fw-bold shadow-sm py-2">
                            <i class="bi bi-bank me-2 text-primary"></i> Account Details
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wallet Balances Section -->
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="fw-bold mb-0">My Wallets</h6>
            <span class="text-muted small">Balances update after network confirmation</span>
        </div>
        <div class="row g-3 mb-4">
            <?php
            $display_coins = $crypto_provider_mode ? bc_svc_provider_assets($vid) : ['BTC', 'ETH', 'LTC', 'USDT_TRX', 'TRX', 'USDC', 'BCH', 'DOGE'];
            foreach($display_coins as $cid):
                $bal = $userWallets[$cid]['balance'] ?? 0;
                $label = ($cid == 'USDT_TRX') ? 'USDT' : $cid;
                $icon = strtolower(str_replace('_', '-', $cid)) . ".png";
                // Only show if asset is in the active provider catalogue or has balance
                $is_active = false; foreach($currencies as $c) if($c['cid'] == $cid) { $is_active = true; break; }
                if(!$is_active && $bal <= 0) continue;
            ?>
            <div class="col-lg-3 col-md-4 col-6">
                <div class="card cw-card shadow-sm border-0 h-100 position-relative overflow-hidden">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="cw-icon">
                                <img src="../asset/crypto/<?php echo $icon; ?>" alt="<?php echo $label; ?>">
                            </div>
                            <span class="badge bg-light text-dark rounded-pill" style="font-size:0.65rem;"><?php echo $cid; ?></span>
                        </div>
                        <div class="text-muted small fw-bold mb-1"><?php echo $label; ?></div>
                        <div class="h5 fw-bold mb-0"><?php echo (float)$bal; ?></div>
                    </div>
                    <div class="position-absolute bottom-0 end-0 p-1 opacity-10">
                        <i class="bi bi-wallet2 fs-1"></i>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if(isKYCEnforced() && $get_logged_user_details['kyc_status'] != 2): ?>
        <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between p-4">
            <div class="d-flex align-items-center">
                <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3 me-3">
                    <i class="bi bi-shield-lock-fill fs-3"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-1">KYC Verification Required</h6>
                    <p class="small mb-0 text-muted">Crypto features are restricted until your identity is verified.</p>
                </div>
            </div>
            <a href="KYCVerification.php" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">Complete KYC</a>
        </div>
        <style>.crypto-card button, .col-lg-4 button { pointer-events: none; opacity: 0.6; }</style>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Main Content Area -->
            <div class="col-lg-8">
                <div class="card crypto-card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0">Latest Invoices</h6>
                        <a href="CryptoInvoices.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">View History</a>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php
                            $res = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `vendor_id`='$vid' AND `username`='$username' ORDER BY `id` DESC LIMIT 3");
                            if($res && mysqli_num_rows($res) > 0):
                            while($row = mysqli_fetch_assoc($res)):
                                $s = $row['status'];
                                $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                $txt = ($s == 1) ? 'Success' : (($s == 2) ? 'Pending' : 'Expired');
                            ?>
                            <div class="col-md-4">
                                <div class="card border bg-light shadow-none h-100 mb-0">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between mb-3">
                                            <span class="badge bg-<?php echo $c; ?> bg-opacity-10 text-<?php echo $c; ?>"><?php echo $txt; ?></span>
                                            <small class="text-muted" style="font-size:0.65rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></small>
                                        </div>
                                        <div class="h6 fw-bold mb-1"><?php echo (float)$row['amount']; ?> <?php echo $row['currency_code']; ?></div>
                                        <div class="text-muted small text-truncate mb-3" style="font-size:0.7rem;"><?php echo $row['reference']; ?></div>
                                        <?php if($s == 2): ?>
                                            <a href="ViewCryptoInvoice.php?ref=<?php echo $row['reference']; ?>" class="btn btn-primary btn-sm w-100 rounded-pill">Pay Now</a>
                                        <?php else: ?>
                                            <a href="ViewCryptoInvoice.php?ref=<?php echo $row['reference']; ?>" class="btn btn-outline-secondary btn-sm w-100 rounded-pill">Details</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; else: ?>
                            <div class="col-12 text-center py-4 text-muted">No invoices generated yet.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card crypto-card shadow-sm border-0 rounded-4 mt-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0">Other Activity</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover small mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-4">Type</th>
                                        <th>Asset</th>
                                        <th>Amount</th>
                                        <th class="pe-4">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // Exclude the 3 deposits shown above to show other types or older deposits
                                    $res_other = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `vendor_id`='$vid' AND `username`='$username' ORDER BY `id` DESC LIMIT 3, 7");
                                    if($res_other && mysqli_num_rows($res_other) > 0):
                                    while($row = mysqli_fetch_assoc($res_other)):
                                    ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="text-uppercase small fw-bold"><?php echo str_replace('_', ' ', $row['type']); ?></span>
                                            <div class="text-muted" style="font-size:0.7rem;"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></div>
                                        </td>
                                        <td><?php echo $row['currency_code']; ?></td>
                                        <td class="fw-bold"><?php echo (float)$row['amount']; ?></td>
                                        <td class="pe-4">
                                            <?php
                                                $s = $row['status'];
                                                $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                                $txt = ($s == 1) ? 'Paid' : (($s == 2) ? 'Pending' : 'Failed');
                                                echo "<span class='badge bg-$c bg-opacity-10 text-$c rounded-pill'>$txt</span>";
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">No other transactions</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions Sidebar -->
            <div class="col-lg-4">
                <div class="card crypto-card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-body p-4 text-center">
                        <div class="crypto-side-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-arrow-left-right fs-3"></i>
                        </div>
                        <h5 class="fw-bold">Instant Swap</h5>
                        <p class="small text-muted">Exchange your crypto instantly at current market rates.</p>
                        <button class="btn btn-success w-100 rounded-pill py-2" onclick="openSwapModal()">Start Swap</button>
                    </div>
                </div>

                <div class="card crypto-card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-body p-4 text-center">
                        <div class="crypto-side-icon bg-primary bg-opacity-10 overflow-hidden">
                            <img src="../asset/crypto/bank.png" alt="Withdraw">
                        </div>
                        <h5 class="fw-bold">Withdraw Funds</h5>
                        <p class="small text-muted">Withdraw your NGN balance to any Nigerian bank account.</p>
                        <a href="SendFund.php" class="btn btn-primary w-100 rounded-pill py-2">Go to Withdrawal</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Deposit Modal -->
    <div class="modal fade" id="depositModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Deposit <span id="depCurrencyName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <div id="depInit">
                        <p class="text-muted">Enter amount to deposit (optional) or click generate to get address.</p>
                        <input type="number" id="depAmount" class="form-control mb-3" placeholder="0.00 (Optional)">
                        <button class="btn btn-primary w-100 py-2 rounded-3" id="btnGenDep" onclick="generateDeposit()">Generate Address</button>
                    </div>
                    <div id="depResult" style="display:none;">
                        <img id="depQR" src="" class="img-fluid mb-3 shadow-sm rounded-3">
                        <div class="input-group mb-3">
                            <input type="text" id="depAddr" class="form-control bg-light" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyValue('depAddr')"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <div class="alert alert-info small text-start">
                            <i class="bi bi-info-circle me-2"></i>Send exactly the amount shown on the invoice. Funds will be credited after 1 confirmation.
                        </div>
                        <a href="" id="depLocalInvoice" class="btn btn-primary w-100 rounded-pill py-2 mb-2">View My Invoice</a>
                        <a href="" id="depInvoiceLink" target="_blank" class="btn btn-link btn-sm">View Payment Invoice</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Swap Modal -->
    <div class="modal fade" id="swapModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Instant Crypto Swap</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="swapForm">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">From</label>
                            <select name="from" class="form-select" required>
                                <?php foreach($supported as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo ($s == 'USDT_TRX') ? 'USDT (TRC-20)' : $s; ?> (Bal: <?php echo $userWallets[$s]['balance'] ?? 0; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3 text-center">
                            <i class="bi bi-arrow-down-up fs-4 text-muted"></i>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">To</label>
                            <select name="to" class="form-select" id="swapToSelect" required>
                                <?php foreach($supported as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo ($s == 'USDT_TRX') ? 'USDT (TRC-20)' : $s; ?></option>
                                <?php endforeach; ?>
                                <option value="NGN">Main Wallet (NGN)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Amount to Swap</label>
                            <input type="number" name="amount" id="swapAmount" class="form-control" step="0.00000001" required>
                        </div>

                        <div id="swapEstimate" class="mb-3 p-3 bg-light rounded-3 text-center d-none">
                            <div class="row align-items-center">
                                <div class="col-12 mb-2">
                                    <span class="text-muted small">You will receive:</span><br>
                                    <span class="h4 fw-bold text-success" id="swapVal">0.00</span>
                                </div>
                                <div id="swapFeeRow" class="col-12 border-top pt-2 d-none">
                                    <div class="d-flex justify-content-between px-2">
                                        <span class="text-muted small">Service Fee:</span>
                                        <span class="text-danger small fw-bold" id="swapFeeVal">₦ 0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success w-100 py-2 rounded-3 mt-2">Execute Swap</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Withdraw Modal (restored: the Send flow posts currency/amount/address/pin/otp) -->
    <div class="modal fade" id="withdrawModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Send Crypto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="withdrawForm">
                    <div class="modal-body p-4">
                        <input type="hidden" name="currency" id="withdrawCid">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Amount</label>
                            <input type="number" name="amount" class="form-control" step="0.00000001" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Destination Address</label>
                            <input type="text" name="address" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Email OTP</label>
                            <div class="input-group">
                                <input type="text" name="otp" class="form-control" required>
                                <button type="button" class="btn btn-outline-primary" onclick="sendWithdrawalOTP(this)">Get Code</button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Transaction PIN</label>
                            <input type="password" name="pin" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-danger w-100 py-2 rounded-3">Send Now</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Self Help Modal (restored: manual_sync posts the reference to CryptoHub.php) -->
    <div class="modal fade" id="selfHelpModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold">Sync a Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small">Paid but not credited after 5-10 minutes? Enter your Transaction Reference or Blockchain TXID and we will check the status with the provider.</p>
                    <input type="text" id="syncRef" class="form-control mb-3" placeholder="Transaction Reference or Blockchain TXID">
                    <button class="btn btn-warning w-100 py-2 rounded-3 fw-bold" onclick="triggerManualSync(this)">Check Status</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentDepCid = '';

        function openWalletListModal() {
            new bootstrap.Modal(document.getElementById('walletListModal')).show();
        }

        function openSelfHelpModal(ref = '') {
            document.getElementById('syncRef').value = ref;
            new bootstrap.Modal(document.getElementById('selfHelpModal')).show();
        }

        function triggerManualSync(btn) {
            const ref = document.getElementById('syncRef').value;
            if(!ref) {
                Swal.fire('Required', 'Please enter a transaction reference.', 'warning');
                return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking Status...';

            fetch('CryptoHub.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=manual_sync&reference=${ref}`
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;

                Swal.fire({
                    title: res.status.toUpperCase(),
                    text: res.message,
                    icon: res.status === 'info' ? 'info' : (res.status === 'success' ? 'success' : 'error')
                }).then(() => {
                    if(res.status === 'success') location.reload();
                });
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                Swal.fire('Error', 'An unexpected error occurred.', 'error');
            });
        }

        function openGeneralDepositModal() {
            let options = '';
            <?php foreach($supported as $cid):
                $cur = null; foreach($currencies as $c) if($c['cid'] == $cid) { $cur = $c; break; }
                if($cur):
            ?>
            options += `<div class="col-6 mb-3">
                            <button class="btn btn-outline-primary w-100 p-3 rounded-4" onclick="Swal.close(); openDepositModal('<?php echo $cid; ?>', '<?php echo $cur['name']; ?>')">
                                <img src="../asset/crypto/<?php echo strtolower(str_replace('_', '-', $cid)) . ".png"; ?>" style="width:30px; height:30px; margin-bottom:5px;"><br>
                                <small class="fw-bold"><?php echo $cur['name']; ?></small>
                            </button>
                        </div>`;
            <?php endif; endforeach; ?>

            Swal.fire({
                title: 'Select Currency to Deposit',
                html: `<div class="row g-2 p-2">${options}</div>`,
                showConfirmButton: false,
                showCloseButton: true
            });
        }

        function openDepositModal(cid, name) {
            currentDepCid = cid;
            document.getElementById('depCurrencyName').innerText = name;
            document.getElementById('depInit').style.display = 'block';
            document.getElementById('depResult').style.display = 'none';
            new bootstrap.Modal(document.getElementById('depositModal')).show();
        }

        function openGeneralWithdrawModal() {
            let options = '';
            <?php foreach($supported as $cid):
                $cur = null; foreach($currencies as $c) if($c['cid'] == $cid) { $cur = $c; break; }
                if($cur):
            ?>
            options += `<div class="col-6 mb-3">
                            <button class="btn btn-outline-danger w-100 p-3 rounded-4" onclick="Swal.close(); openWithdrawModal('<?php echo $cid; ?>')">
                                <img src="../asset/crypto/<?php echo strtolower(str_replace('_', '-', $cid)) . ".png"; ?>" style="width:30px; height:30px; margin-bottom:5px;"><br>
                                <small class="fw-bold"><?php echo $cur['name']; ?></small>
                            </button>
                        </div>`;
            <?php endif; endforeach; ?>

            Swal.fire({
                title: 'Select Currency to Send',
                html: `<div class="row g-2 p-2">${options}</div>`,
                showConfirmButton: false,
                showCloseButton: true
            });
        }

        function generateDeposit() {
            const btn = document.getElementById('btnGenDep');
            const amt = document.getElementById('depAmount').value;
            btn.disabled = true; btn.innerText = 'Generating...';

            fetch('CryptoHub.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=create_invoice&currency=${currentDepCid}&amount=${amt}`
            }).then(r => r.json()).then(res => {
                btn.disabled = false; btn.innerText = 'Generate Address';
                if(res.status == 'success') {
                    document.getElementById('depInit').style.display = 'none';
                    document.getElementById('depResult').style.display = 'block';
                    if(res.data.wallet_hash) {
                        document.getElementById('depQR').src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${res.data.wallet_hash}`;
                        document.getElementById('depAddr').value = res.data.wallet_hash;
                    } else {
                        document.getElementById('depQR').style.display = 'none';
                        document.getElementById('depAddr').parentElement.style.display = 'none';
                    }
                    document.getElementById('depInvoiceLink').href = res.data.invoice_url;
                    document.getElementById('depLocalInvoice').href = res.data.local_invoice_url;
                } else {
                    alert(res.message);
                }
            });
        }

        function copyValue(id) {
            const el = document.getElementById(id);
            el.select();
            document.execCommand('copy');
            alert('Copied to clipboard!');
        }

        function openSwapModal(target = '') {
            if(target) document.getElementById('swapToSelect').value = target;
            new bootstrap.Modal(document.getElementById('swapModal')).show();
        }

        document.getElementById('swapForm').onsubmit = function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            const to = fd.get('to');
            fd.append('action', 'swap');
            fetch('CryptoHub.php', {
                method: 'POST',
                body: new URLSearchParams(fd)
            }).then(r => r.json()).then(res => {
                if(res.status == 'success') {
                    let confirmBtn = 'Done';
                    let showCancel = false;
                    if(to === 'NGN') {
                        confirmBtn = 'Withdraw Now';
                        showCancel = true;
                    }

                    Swal.fire({
                        title: 'Success!',
                        text: res.message,
                        icon: 'success',
                        showCancelButton: showCancel,
                        confirmButtonText: confirmBtn,
                        cancelButtonText: 'Stay Here',
                        confirmButtonColor: '#287bff'
                    }).then((result) => {
                        if (result.isConfirmed && to === 'NGN') {
                            window.location.href = 'SendFund.php';
                        } else {
                            location.reload();
                        }
                    });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        }

        function openWithdrawModal(cid) {
            document.getElementById('withdrawCid').value = cid;
            new bootstrap.Modal(document.getElementById('withdrawModal')).show();
        }

        function sendWithdrawalOTP(btn) {
            const originalText = btn.innerHTML;
            btn.innerHTML = 'Sending...';
            btn.disabled = true;

            fetch('CryptoHub.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=send_otp'
            }).then(r => r.json()).then(res => {
                alert(res.message);
                if(res.status == 'success') {
                    let count = 60;
                    const timer = setInterval(() => {
                        btn.innerHTML = `Resend in ${count}s`;
                        count--;
                        if(count < 0) {
                            clearInterval(timer);
                            btn.innerHTML = 'Get Code';
                            btn.disabled = false;
                        }
                    }, 1000);
                } else {
                    btn.innerHTML = 'Get Code';
                    btn.disabled = false;
                }
            });
        }

        document.getElementById('withdrawForm').onsubmit = function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action', 'withdraw');
            fetch('CryptoHub.php', {
                method: 'POST',
                body: new URLSearchParams(fd)
            }).then(r => r.json()).then(res => {
                alert(res.message);
                if(res.status == 'success') location.reload();
            });
        }

        // Rate estimation
        document.getElementById('swapAmount').oninput = function() {
            const amt = parseFloat(this.value || 0);
            const from = document.querySelector('#swapForm select[name="from"]').value;
            const to = document.querySelector('#swapForm select[name="to"]').value;
            const est = document.getElementById('swapEstimate');
            const valEl = document.getElementById('swapVal');

            if(amt > 0) {
                est.classList.remove('d-none');
                valEl.innerText = '...';
                fetch(`CryptoHub.php?get_rate=1&from=${from}&to=${to}`)
                .then(r => r.json()).then(res => {
                    if(res.rate) {
                        const gross = amt * res.rate;
                        if(to === 'NGN') {
                            const feePercent = <?php echo (float)($select_vendor_table['crypto_swap_fee'] ?? 0); ?>;
                            const fee = (feePercent / 100) * gross;
                            const net = gross - fee;
                            valEl.innerText = '₦ ' + net.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            document.getElementById('swapFeeVal').innerText = '₦ ' + fee.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            document.getElementById('swapFeeRow').classList.remove('d-none');
                        } else {
                            valEl.innerText = gross.toFixed(8) + ' ' + to;
                            document.getElementById('swapFeeRow').classList.add('d-none');
                        }
                    }
                });
            } else {
                est.classList.add('d-none');
            }
        }

    </script>
	<?php bc_app_foot(); ?>
