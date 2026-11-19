<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["buy-betting"])){
        $purchase_method = "web";
        $action_function = 1;
        include_once("func/betting.php");
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        $_SESSION["product_purchase_status"] = $json_response_decode["status"] ?? null;
        if (isset($json_response_decode["ref"])) {
            $_SESSION["last_transaction_ref"] = $json_response_decode["ref"];
        }
        unset($_SESSION["customer_amount"]);
        unset($_SESSION["customer_id"]);
        unset($_SESSION["customer_provider"]);
        unset($_SESSION["customer_type"]);
        unset($_SESSION["customer_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["verify-customer"])){
        $purchase_method = "web";
        $action_function = 3;
        include_once("func/betting.php");
        $json_response_decode = json_decode($json_response_encode,true);
        if($json_response_decode["status"] == "success"){
            $_SESSION["customer_amount"] = $amount;
            $_SESSION["customer_id"] = $customer_id;
            $_SESSION["customer_provider"] = $epp;
            $_SESSION["customer_name"] = $json_response_decode["desc"];
        }

        if($json_response_decode["status"] == "failed"){
            $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if(isset($_POST["reset-betting"])){
        unset($_SESSION["customer_amount"]);
        unset($_SESSION["customer_id"]);
        unset($_SESSION["customer_provider"]);
        unset($_SESSION["customer_name"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Fund Betting | " . ($get_all_site_details["site_title"]));
?>
<style data-pkx="page-redesign">
/* Betting — fintech redesign (2026-11): shell bill card, carrier tiles, steps.
   Automation (network auto-detection, plan filtering, proceed gating) is
   jsfile/bc-custom-all.js, loaded by the shell; all ids/handlers are kept. */
.pk-app__wrap .pk-bill{border-radius:22px!important}
</style>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "FUND BETTING", "crumb" => "Home / Fund Betting")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card pk-bill shadow-sm border-0 overflow-hidden">
                <div class="pk-bill__head">
                    <span class="pk-bill__ic"><i class="bi bi-trophy"></i></span>
                    <div><h5>Fund Betting Wallet</h5><p>Verify the customer first, then confirm the amount.</p></div>
                    <div class="pk-bill__steps"><span class="">1 · Provider</span><span class="">2 · Verify Customer</span><span class="">3 · Confirm</span></div>
                </div>

            <form method="post" action="" class="p-4">
                <?php if(!isset($_SESSION["customer_name"])){ ?>
                <div class="carrier-grid d-flex flex-wrap justify-content-center gap-3 mb-4">
                    <img alt="msport" id="msport-lg" product-status="enabled" src="/asset/msport.jpg" onclick="tickBettingCarrier('msport'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="naijabet" id="naijabet-lg" product-status="enabled" src="/asset/naijabet.jpg" onclick="tickBettingCarrier('naijabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="nairabet" id="nairabet-lg" product-status="enabled" src="/asset/nairabet.jpg" onclick="tickBettingCarrier('nairabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="bet9ja-agent" id="bet9ja-agent-lg" product-status="enabled" src="/asset/bet9ja-agent.jpg" onclick="tickBettingCarrier('bet9ja-agent'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betland" id="betland-lg" product-status="enabled" src="/asset/betland.jpg" onclick="tickBettingCarrier('betland'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betlion" id="betlion-lg" product-status="enabled" src="/asset/betlion.jpg" onclick="tickBettingCarrier('betlion'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="supabet" id="supabet-lg" product-status="enabled" src="/asset/supabet.jpg" onclick="tickBettingCarrier('supabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="bet9ja" id="bet9ja-lg" product-status="enabled" src="/asset/bet9ja.jpg" onclick="tickBettingCarrier('bet9ja'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="bangbet" id="bangbet-lg" product-status="enabled" src="/asset/bangbet.jpg" onclick="tickBettingCarrier('bangbet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betking" id="betking-lg" product-status="enabled" src="/asset/betking.jpg" onclick="tickBettingCarrier('betking'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="1xbet" id="1xbet-lg" product-status="enabled" src="/asset/1xbet.jpg" onclick="tickBettingCarrier('1xbet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="betway" id="betway-lg" product-status="enabled" src="/asset/betway.jpg" onclick="tickBettingCarrier('betway'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="merrybet" id="merrybet-lg" product-status="enabled" src="/asset/merrybet.jpg" onclick="tickBettingCarrier('merrybet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="mlotto" id="mlotto-lg" product-status="enabled" src="/asset/mlotto.jpg" onclick="tickBettingCarrier('mlotto'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="western-lotto" id="western-lotto-lg" product-status="enabled" src="/asset/western-lotto.jpg" onclick="tickBettingCarrier('western-lotto'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="hallabet" id="hallabet-lg" product-status="enabled" src="/asset/hallabet.jpg" onclick="tickBettingCarrier('hallabet'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                    <img alt="green-lotto" id="green-lotto-lg" product-status="enabled" src="/asset/green-lotto.jpg" onclick="tickBettingCarrier('green-lotto'); resetBettingQuantity();" class="rounded-4 border p-2"/>
                </div>

                <input id="bettingname" name="epp" type="text" placeholder="betting Name" hidden readonly required/>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Customer ID</label>
                    <input style="text-align: center;" id="customer-id" name="customer-id" onkeyup="pickBettingQty();" type="text" placeholder="Enter ID" pattern="[0-9]{5,}" title="Charater must be atleast 5 digit" class="form-control form-control-lg" required/>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase">Amount (NGN)</label>
                    <input style="text-align: center;" id="product-amount" name="amount" onkeyup="pickBettingQty();" type="text" placeholder="Min 100" pattern="[0-9]{3,}" title="Charater must be atleast 3 digit" class="form-control form-control-lg" required/>
                </div>
                <?php }else{ ?>
                <div class="text-center mb-4">
                  <img alt="<?php echo htmlspecialchars($_SESSION['customer_provider'], ENT_QUOTES, 'UTF-8'); ?>" id="<?php echo htmlspecialchars($_SESSION['customer_provider'], ENT_QUOTES, 'UTF-8'); ?>-lg" src="/asset/<?php echo htmlspecialchars($_SESSION['customer_provider'], ENT_QUOTES, 'UTF-8'); ?>.jpg" class="service-logo rounded-4 shadow-sm border mb-3" /><br/>
                  <div class="pk-verify text-start">
                      <div class="row-line"><span class="k">Customer Name</span><span class="v"><?php echo strtoupper($_SESSION['customer_name']); ?></span></div>
                      <div class="row-line"><span class="k">Customer ID</span><span class="v"><?php echo htmlspecialchars($_SESSION['customer_id'], ENT_QUOTES, 'UTF-8'); ?></span></div>
                      <div class="row-line"><span class="k">Amount To Fund</span><span class="v text-success">₦<?php echo number_format($_SESSION['customer_amount'], 2); ?></span></div>
                  </div>
                </div>
                <?php } ?>

                <?php if(!isset($_SESSION["customer_name"])){ ?>
                <button id="proceedBtn" name="verify-customer" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm rounded-pill fw-bold" >
                    VERIFY CUSTOMER
                </button>
                <?php }else{ ?>
                <?php if ($force_security_pin_for_users === 1): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase" for="transaction-pin">Transaction PIN</label>
                    <input id="transaction-pin" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center" placeholder="Enter your 4-digit PIN" required />
                    <div class="form-text">Your account requires a transaction PIN for every purchase.</div>
                </div>
                <?php endif; ?>
                <button name="buy-betting" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-pill fw-bold mb-3" >
                    CONFIRM & FUND
                </button>
                <button name="reset-betting" type="submit" class="btn btn-outline-secondary w-100 border-0" >
                    Cancel and Start Over
                </button>
                <?php } ?>

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
