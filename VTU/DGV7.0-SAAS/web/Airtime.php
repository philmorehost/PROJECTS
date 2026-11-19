<?php session_start();
    include("../func/bc-config.php");
    
    if(isset($_POST["buy-airtime"])){
        $purchase_method = "web";
		include_once("func/airtime.php");
        $json_response_decode = json_decode($json_response_encode,true);
        $response_message = $json_response_decode["desc"];
        if (isset($json_response_decode["bonus_message"])) {
            $response_message .= "<br>" . $json_response_decode["bonus_message"];
        }
        $_SESSION["product_purchase_response"] = $response_message;
        $_SESSION["product_purchase_status"] = $json_response_decode["status"] ?? null;
        if (isset($json_response_decode["ref"])) {
            $_SESSION["last_transaction_ref"] = $json_response_decode["ref"];
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Airtime | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "BUY AIRTIME", "crumb" => "Home / Buy Airtime")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <form method="post" action="">
                <div class="carrier-grid d-flex justify-content-center gap-3 mb-4">
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickAirtimeCarrier('airtel');" class="rounded-4 border p-2"/>
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickAirtimeCarrier('mtn');" class="rounded-4 border p-2"/>
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickAirtimeCarrier('glo');" class="rounded-4 border p-2"/>
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickAirtimeCarrier('9mobile');" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required/>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Phone Number</label>
                    <input style="text-align: center;" id="phone-number" name="phone-number" onkeyup="tickAirtimeCarrier();" type="text" value="" placeholder="e.g 08124232128" pattern="[0-9]{11}" title="Charater must be an 11 digit" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Amount (NGN)</label>
                    <input style="text-align: center;" id="product-amount" name="amount" onkeyup="tickAirtimeCarrier();" type="text" value="" placeholder="Min 100" pattern="[0-9]{3,}" title="Charater must be atleast 3 digit" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>

                <div class="form-check mb-4 mt-2">
                    <input id="phone-bypass" onclick="tickAirtimeCarrier('airtel');" type="checkbox" class="form-check-input" />
                    <label for="phone-bypass" class="form-check-label small fw-semibold text-muted">
                        Bypass Phone Verification
                    </label>
                </div>

                <?php if ($force_security_pin_for_users === 1): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase" for="transaction-pin">Transaction PIN</label>
                    <input id="transaction-pin" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center" placeholder="Enter your 4-digit PIN" required />
                    <div class="form-text">Your account requires a transaction PIN for every purchase.</div>
                </div>
                <?php endif; ?>
                <button id="proceedBtn" name="buy-airtime" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm" >
                    BUY AIRTIME
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small text-danger fw-bold"></span>
                </div>
            </form>
          </div>
        </div>
      </div>
    </section>
		<?php include("../func/short-trans.php"); ?>
	
	<?php bc_app_foot(); ?>
