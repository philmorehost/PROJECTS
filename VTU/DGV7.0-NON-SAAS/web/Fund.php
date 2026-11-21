<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");

$payment_gateway_array = array("monnify", "flutterwave", "paystack", "beewave", "payhub");

$retry_ref = $_GET['retry_ref'] ?? '';
$retry_amount = $_GET['amount'] ?? '';
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Fund Wallet | " . ($get_all_site_details["site_title"]));
?>

<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "ATM FUNDING", "crumb" => "Home / ATM Funding")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <h5 class="fw-bold mb-4 text-center">Top Up with ATM Card / Bank Transfer</h5>

            <form method="post" action="">
                <div class="gateway-grid d-flex flex-wrap justify-content-center gap-3 mb-4">
                    <?php
                    foreach ($payment_gateway_array as $gateway_name) {
                        if (!isServiceEnabled($gateway_name)) continue;

                        // Optimization DG6.7: Use robust gateway detection with platform fallback
                        $gw_details = getGatewayDetails($gateway_name, $get_logged_user_details["vendor_id"]);
                        $is_enabled = isGatewayEnabled($gateway_name, $get_logged_user_details["vendor_id"]);

                        if ($gw_details) {
                            $gw_display_name = ucwords(trim($gateway_name));
                            $gw_id = strtolower(trim($gateway_name));
                            $gw_public = trim($gw_details["public_key"] ?? '');
                            $gw_encrypt = trim($gw_details["encrypt_key"] ?? '');
                            $gw_int = trim($gw_details["percentage"] ?? '0');

                            if ($is_enabled) {
                                echo '<img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="enabled" gateway-public="' . $gw_public . '" gateway-encrypt="' . $gw_encrypt . '" gateway-int="' . $gw_int . '" product-name-array="' . implode(",", $payment_gateway_array) . '" src="/asset/' . $gw_id . '.jpg" onclick="tickPaymentGateway(this, \'' . $gw_id . '\', \'gatewayname\', \'fundProceedBtn\', \'jpg\');" class="rounded-3 border shadow-sm" style="width: 80px; height: 50px; object-fit: contain; cursor: pointer;"/>';
                            } else {
                                echo '<img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="disabled" src="/asset/' . $gw_id . '.jpg" class="rounded-3 border opacity-50 grayscale" style="width: 80px; height: 50px; object-fit: contain; filter: grayscale(1);"/>';
                            }
                        }
                    }
                    ?>
                </div>

                <input id="gatewayname" name="" type="text" placeholder="Gateway Name" hidden readonly required />
                <input id="amount-to-pay" name="" type="text" placeholder="" hidden readonly required />
                <?php
                    $full_name = trim($get_logged_user_details['firstname'] . " " . $get_logged_user_details['lastname'] . " " . ($get_logged_user_details['othername'] ?? ''));
                    $full_name = preg_replace('/\s+/', ' ', $full_name);
                ?>
                <input id="user-name" name="" type="text" value="<?php echo htmlspecialchars($full_name); ?>" hidden readonly required />
                <input id="user-email" name="" type="email" value="<?php echo $get_logged_user_details['email']; ?>" hidden readonly required />
                <input id="user-phone" name="" type="number" value="<?php echo $get_logged_user_details['phone_number']; ?>" hidden readonly required />
                <input id="num-ref" name="" type="text" value="<?php echo !empty($retry_ref) ? htmlspecialchars($retry_ref) : time().rand(1000,9999); ?>" hidden readonly required />
                <input id="gateway-public" name="" type="text" hidden readonly required />
                <input id="gateway-encrypt" name="" type="text" hidden readonly required />

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase text-muted">Amount to Fund (NGN)</label>
                    <?php if(!empty($retry_ref)): ?>
                        <div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i> Retrying payment for Ref: <?php echo htmlspecialchars($retry_ref); ?></div>
                    <?php endif; ?>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">₦</span>
                        <input id="fund-amount" name="" type="number" onkeyup="checkPaymentGatewayDetails('fundProceedBtn','2');" placeholder="Min 100" step="1" min="100" class="form-control border-start-0 fw-bold" value="<?php echo htmlspecialchars($retry_amount); ?>" <?php echo !empty($retry_amount) ? 'readonly' : ''; ?> required />
                    </div>
                </div>

                <button id="fundProceedBtn" name="" type="button" class="btn btn-primary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none; opacity: 0.7;">
                    PAY NOW
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small fw-bold text-danger"></span>
                </div>
            </form>
          </div>
        </div>
      </div>
    </section>
    <script>
        window.addEventListener('load', () => {
            if(document.getElementById('fund-amount').value) {
                checkPaymentGatewayDetails('fundProceedBtn','2');
            }
        });
    </script>
	<?php bc_app_foot(); ?>
