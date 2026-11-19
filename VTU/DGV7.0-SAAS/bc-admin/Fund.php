<?php session_start();
    include("../func/bc-admin-config.php");
        
    $payment_gateway_array = array("monnify", "flutterwave", "paystack", "payvessel", "payhub");
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Fund Wallet | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style>
    @media (min-width: 992px) {
        .gateway-option img { width: 150px !important; height: 150px !important; }
    }
    @media (max-width: 991px) {
        .gateway-option img { width: 50px !important; height: 50px !important; }
    }
  </style>
<script type="text/javascript" src="https://sdk.monnify.com/plugin/monnify.js"></script>
<script src="https://checkout.flutterwave.com/v3.js"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "FUND WALLET", "crumb" => "Home / Fund Wallet")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-6">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="display-6 fw-bold text-primary mb-2">Fund Account</div>
                    <p class="text-muted">Select a payment gateway and enter amount to fund your wallet instantly.</p>
                </div>

                <form method="post">
                    <div class="d-flex justify-content-center gap-3 mb-4 flex-wrap">
                        <?php
                            foreach($payment_gateway_array as $gateway_name){
                                // For Vendors funding the platform, we use platform config (vid=0)
                                $gw_details = getGatewayDetails($gateway_name, 0);
                                $is_enabled = isGatewayEnabled($gateway_name, 0);

                                if ($gw_details) {
                                    $gw_display_name = ucwords(trim($gateway_name));
                                    $gw_id = strtolower(trim($gateway_name));
                                    $gw_public = trim($gw_details["public_key"] ?? '');
                                    $gw_encrypt = trim($gw_details["encrypt_key"] ?? '');
                                    $gw_int = trim($gw_details["percentage"] ?? '0');

                                    if ($is_enabled) {
                                        echo '<div class="gateway-option">
                                            <img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="enabled" gateway-public="' . $gw_public . '" gateway-encrypt="' . $gw_encrypt . '" gateway-int="' . $gw_int . '" product-name-array="' . implode(",",$payment_gateway_array) . '" src="/asset/' . $gw_id . '.jpg" onclick="vtickPaymentGateway(this, \'' . $gw_id . '\', \'gatewayname\', \'fundProceedBtn\', \'jpg\');" class="rounded-4 border shadow-sm a-cursor" style="width: 80px; height: 80px; object-fit: contain; padding: 10px;"/>
                                            <div class="small text-center mt-1 fw-bold">' . $gw_display_name . '</div>
                                        </div>';
                                    } else {
                                        echo '<div class="gateway-option opacity-50">
                                            <img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="disabled" src="/asset/' . $gw_id . '.jpg" class="rounded-4 border grayscale" style="width: 80px; height: 80px; object-fit: contain; padding: 10px; cursor: not-allowed;"/>
                                            <div class="small text-center mt-1">' . $gw_display_name . '</div>
                                        </div>';
                                    }
                                }
                            }
                        ?>
                    </div>

                    <?php if (isset($_GET['purpose']) && $_GET['purpose'] == 'plisio_activation'): ?>
                        <div class="alert alert-primary rounded-4 mb-4">
                             <h6 class="fw-bold mb-1"><i class="bi bi-rocket-takeoff me-2"></i>Plisio Activation</h6>
                             <p class="small mb-0">You are completing the payment to unlock the Plisio Crypto Gateway.</p>
                        </div>
                    <?php elseif (isset($_GET['purpose']) && $_GET['purpose'] == 'payout_activation'): ?>
                        <div class="alert alert-primary rounded-4 mb-4">
                             <h6 class="fw-bold mb-1"><i class="bi bi-bank2 me-2"></i>Withdrawal Module Activation</h6>
                             <p class="small mb-0">You are completing the payment to unlock the Automated Withdrawal Module.</p>
                        </div>
                    <?php endif; ?>

                    <div class="form-floating mb-4">
                        <input id="fund-amount" type="number" class="form-control form-control-lg rounded-3 border-0 bg-light" onkeyup="vcheckPaymentGatewayDetails('fundProceedBtn','2');" placeholder="Amount" step="1" min="100" required value="<?php echo htmlspecialchars($_GET['amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo isset($_GET['amount']) ? 'readonly' : ''; ?>>
                        <label for="fund-amount" class="text-muted ps-3">Amount to Fund (₦)</label>
                        <div class="form-text small mt-2 ps-2 text-primary fw-bold" id="product-status-span"></div>
                    </div>

                    <input id="gatewayname" hidden readonly required/>
                    <input id="amount-to-pay" hidden readonly required/>
                    <input id="user-name" value="<?php echo $get_logged_admin_details['firstname']." ".$get_logged_admin_details['lastname']." ".(isset($get_logged_admin_details['othername']) ? $get_logged_admin_details['othername'] : ""); ?>" hidden readonly required/>
                    <input id="user-email" value="<?php echo $get_logged_admin_details['email']; ?>" hidden readonly required/>
                    <input id="user-phone" value="<?php echo $get_logged_admin_details['phone_number']; ?>" hidden readonly required/>
                    <input id="num-ref" value="<?php echo htmlspecialchars($_GET['ref'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" hidden readonly required/>
                    <input id="gateway-public" hidden readonly required/>
                    <input id="gateway-encrypt" hidden readonly required/>

                    <button id="fundProceedBtn" type="button" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold py-3 shadow" style="pointer-events: none; opacity: 0.6;">
                        CONTINUE TO PAYMENT
                    </button>

                    <div class="mt-4 text-center">
                        <div class="small text-muted mb-2">Secure Payment Gateway</div>
                        <div class="d-flex justify-content-center gap-2 opacity-75">
                            <i class="bi bi-shield-lock-fill text-success"></i>
                            <i class="bi bi-credit-card-2-front-fill text-primary"></i>
                            <i class="bi bi-bank2 text-info"></i>
                        </div>
                    </div>
                </form>
            </div>
          </div>
        </div>
      </div>
    </section>

	
	


<?php bc_adm_foot(array("area" => "admin")); ?>
