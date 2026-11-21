<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");

if (isset($_POST["buy-airtime"])) {
    //Ilterate Bulk Phone
    $bulk_phone_no = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bulk-phone-number"])));
    $bulk_phone_no = array_filter(explode(",", trim($bulk_phone_no)));
    $bulk_phone_no = array_unique($bulk_phone_no);

    $all_isps = $_POST["isp"];
    $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["amount"] ?? ""))));
    $multi_carrier = count(array_filter(explode(",", trim($all_isps)))) > 1;

    $queue_items = array();
    foreach ($bulk_phone_no as $each_phone_number) {
        $queue_items[] = array(
            "phone"  => sanitize_phone_number(trim(strip_tags($each_phone_number))),
            "isp"    => $multi_carrier ? identifyISP($each_phone_number) : $all_isps,
            "amount" => $amount,
        );
    }

    if (!empty($queue_items)) {
        $enqueue_result = bc_enqueue_bulk_batch($connection_server, $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], $get_logged_user_details["id"], "airtime", "WEB", $queue_items);
        $_SESSION["product_purchase_response"] = "Your bulk airtime order (Batch #" . $enqueue_result["batch_number"] . ", " . $enqueue_result["total"] . " numbers) has been queued and is processing in the background. Check the Batch Details page for live progress — you don't need to keep this page open.";
        $_SESSION["product_purchase_status"] = "success";
    } else {
        $_SESSION["product_purchase_response"] = "No valid phone numbers submitted.";
        $_SESSION["product_purchase_status"] = "failed";
    }

    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Bulk Airtime | " . ($get_all_site_details["site_title"]));
?>

<style data-pkx="page-redesign">
/* BulkAirtime — fintech redesign (2026-11): shell bill card, carrier tiles, steps.
   Automation (network auto-detection, plan filtering, proceed gating) is
   jsfile/bc-custom-all.js, loaded by the shell; all ids/handlers are kept. */
.pk-app__wrap .pk-bill{border-radius:22px!important}
</style>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "BULK AIRTIME", "crumb" => "Home / Bulk Airtime")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card pk-bill shadow-sm border-0 overflow-hidden">
                <div class="pk-bill__head">
                    <span class="pk-bill__ic"><i class="bi bi-collection-play"></i></span>
                    <div><h5>Buy Bulk Airtime</h5><p>One amount for many numbers — each network is matched automatically.</p></div>
                    <div class="pk-bill__steps"><span class="on">1 · Numbers</span><span class="">2 · Amount</span><span class="">3 · Confirm</span></div>
                </div>

            <form method="post" action="" class="p-4">
                <div class="carrier-grid d-flex justify-content-center gap-3 mb-4">
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickBulkAirtimeCarrier('mtn');" class="rounded-4 border p-2"/>
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickBulkAirtimeCarrier('airtel');" class="rounded-4 border p-2"/>
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickBulkAirtimeCarrier('glo');" class="rounded-4 border p-2"/>
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickBulkAirtimeCarrier('9mobile');" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required />

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Recipient Phone Numbers</label>
                    <textarea id="phone-number" name="" onkeyup="tickBulkAirtimeCarrier();" placeholder="Enter numbers separated by commas or new lines" class="form-control" style="min-height: 120px; border-radius: 12px;"></textarea>
                    <textarea id="filtered-phone-number" name="bulk-phone-number" hidden readonly required></textarea>
                    <div class="text-end mt-1">
                        <span id="phone-numbers-span" class="badge bg-primary rounded-pill">Numbers: 0</span>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Amount per Number (NGN)</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">₦</span>
                        <input id="product-amount" name="amount" onkeyup="tickBulkAirtimeCarrier();" type="number" placeholder="Min 100" class="form-control border-start-0 fw-bold" required />
                    </div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" id="phone-bypass" onclick="tickBulkAirtimeCarrier();">
                    <label class="form-check-label small fw-bold text-muted" for="phone-bypass">Bypass Phone Verification</label>
                </div>

                <button id="proceedBtn" name="buy-airtime" type="submit" class="btn btn-secondary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none;">
                    PROCESS BULK AIRTIME
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small fw-bold text-danger"></span>
                </div>
            </form>
          </div>
        </div>
      </div>
    </section>

    <?php include("../func/short-trans.php"); ?>
    
	<?php bc_app_foot(); ?>
