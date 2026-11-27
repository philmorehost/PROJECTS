<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-admin-config.php");
require_once("../func/bc-virtualcard-flutterwave.php");

/**
 * DGV7 §25 — Virtual Card Manager (Flutterwave ONLY).
 * Retired third-party card integrations were removed platform-wide. Card
 * credentials are the vendor's Flutterwave gateway keys (Payment Gateway
 * page); issuance/funding/freeze run through func/bc-virtualcard-flutterwave.php
 * and fail closed until the Flutterwave card endpoints are configured.
 */

// 1. Profit settings
if (isset($_POST["update-profit-settings"])) {
    $issuance_profit = mysqli_real_escape_string($connection_server, (float)$_POST["vc_issuance_profit_usd"]);
    $funding_profit = mysqli_real_escape_string($connection_server, (float)$_POST["vc_funding_profit_percent"]);
    $conversion_spread = mysqli_real_escape_string($connection_server, (float)$_POST["vc_conversion_spread"]);
    $vid = $get_logged_admin_details["id"];

    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', 'vc_issuance_profit_usd', '$issuance_profit') ON DUPLICATE KEY UPDATE setting_value='$issuance_profit'");
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', 'vc_funding_profit_percent', '$funding_profit') ON DUPLICATE KEY UPDATE setting_value='$funding_profit'");
    mysqli_query($connection_server, "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES ('$vid', 'vc_conversion_spread', '$conversion_spread') ON DUPLICATE KEY UPDATE setting_value='$conversion_spread'");

    $_SESSION["product_purchase_response"] = "Profit Settings Updated Successfully";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

// 2. Manual freeze / unfreeze (Flutterwave only)
if (isset($_GET["control"]) && isset($_GET["ref"])) {
    $ref = $_GET["ref"];
    $vid = (int)$get_logged_admin_details["id"];
    if ($_GET["control"] == "freeze") {
        $res = freezeVirtualCardFlutterwave($vid, $ref, 0);
    } else {
        $res = unfreezeVirtualCardFlutterwave($vid, $ref);
    }
    $_SESSION["product_purchase_response"] = $res['status'] == 'success' ? "Card Status Updated Successfully" : "Error: " . $res['message'];
    header("Location: VirtualCard.php");
    exit();
}

$q_set = mysqli_query($connection_server, "SELECT * FROM sas_settings WHERE vendor_id='".$get_logged_admin_details["id"]."' AND setting_name LIKE 'vc_%'");
$vc_settings = []; while($rs = mysqli_fetch_assoc($q_set)) $vc_settings[$rs['setting_name']] = $rs['setting_value'];
$issuance_profit_val = $vc_settings['vc_issuance_profit_usd'] ?? '2.00';
$funding_profit_val = $vc_settings['vc_funding_profit_percent'] ?? '3.00';
$conversion_spread_val = $vc_settings['vc_conversion_spread'] ?? '0.00';

$vid = (int)$get_logged_admin_details["id"];
$cards = [];
$q_cards = mysqli_query($connection_server, "SELECT * FROM sas_virtual_cards_v2 WHERE vendor_id='$vid' ORDER BY id DESC LIMIT 200");
if ($q_cards) while ($r = mysqli_fetch_assoc($q_cards)) $cards[] = $r;
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Virtual Card Manager | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .table td{vertical-align:middle}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "VIRTUAL CARD MANAGER", "crumb" => "Home / Virtual Cards")); ?>

    <section class="section dashboard">
      <?php if (function_exists('bc_adm_render_api_info_box')) bc_adm_render_api_info_box('virtual-card'); ?>
      <div class="alert alert-info border-0 rounded-4 shadow-sm">
        <strong><i class="bi bi-credit-card-2-front me-2"></i>Flutterwave is the only virtual-card provider.</strong>
        Retired third-party providers have been removed. Card operations use your Flutterwave gateway keys —
        manage them under <a href="PaymentGateway.php" class="alert-link">Payment Gateway</a>. Legacy cards issued by retired
        providers are listed for reference and can no longer be funded or managed.
      </div>
      <div class="row">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between">
                    <h5 class="fw-bold mb-0 text-primary">Profit Margins</h5>
                    <i class="bi bi-cash-coin text-primary"></i>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Issuance Profit (USD)</label>
                            <div class="input-group"><span class="input-group-text">$</span>
                                <input name="vc_issuance_profit_usd" type="number" step="any" class="form-control" value="<?php echo $issuance_profit_val; ?>"></div>
                            <small class="text-xs text-muted">Added to the provider's cost.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Funding Fee (%)</label>
                            <div class="input-group"><span class="input-group-text">%</span>
                                <input name="vc_funding_profit_percent" type="number" step="any" class="form-control" value="<?php echo $funding_profit_val; ?>"></div>
                            <small class="text-xs text-muted">Percentage profit on every top-up.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Exchange Rate Spread (NGN)</label>
                            <div class="input-group"><span class="input-group-text">₦</span>
                                <input name="vc_conversion_spread" type="number" min="0" max="100" step="any" class="form-control" value="<?php echo $conversion_spread_val; ?>"></div>
                            <small class="text-xs text-muted">Added to the live conversion rate per $1.</small>
                        </div>
                        <button name="update-profit-settings" type="submit" class="btn btn-primary w-100 rounded-pill">Save Margins</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3"><h5 class="fw-bold mb-0 text-primary">Issued Cards</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Reference</th><th>User</th><th>Card</th><th>Balance</th><th>Provider</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php if (!$cards): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No virtual cards issued yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($cards as $card): ?>
                                <tr>
                                    <td><small class="fw-bold"><?php echo htmlspecialchars($card['reference']); ?></small></td>
                                    <td><?php echo htmlspecialchars($card['username']); ?></td>
                                    <td><?php echo htmlspecialchars($card['masked_pan']); ?></td>
                                    <td>$<?php echo number_format((float)$card['balance_usd'], 2); ?></td>
                                    <td><span class="badge <?php echo ($card['provider'] ?? '') === 'flutterwave' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo htmlspecialchars($card['provider'] ?? 'legacy'); ?></span></td>
                                    <td><?php echo htmlspecialchars($card['status']); ?></td>
                                    <td class="text-end">
                                        <?php if (($card['provider'] ?? '') === 'flutterwave' && $card['status'] !== 'terminated'): ?>
                                            <?php if ($card['status'] === 'frozen'): ?>
                                                <a class="btn btn-sm btn-outline-success rounded-pill" href="?control=unfreeze&ref=<?php echo urlencode($card['reference']); ?>">Unfreeze</a>
                                            <?php else: ?>
                                                <a class="btn btn-sm btn-outline-danger rounded-pill" href="?control=freeze&ref=<?php echo urlencode($card['reference']); ?>">Freeze</a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>
<?php bc_adm_foot(array("area" => "admin")); ?>
