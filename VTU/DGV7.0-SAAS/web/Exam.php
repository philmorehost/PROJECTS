<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["buy-exam"])){
        $purchase_method = "web";
		include_once("func/exam.php");
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
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
bc_app_head("Exam PIN | " . ($get_all_site_details["site_title"]));
?>
<style data-pkx="page-redesign">
/* Exam — fintech redesign (2026-11): shell bill card, carrier tiles, steps.
   Automation (network auto-detection, plan filtering, proceed gating) is
   jsfile/bc-custom-all.js, loaded by the shell; all ids/handlers are kept. */
.pk-app__wrap .pk-bill{border-radius:22px!important}
</style>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "BUY EXAM PIN", "crumb" => "Home / Exam PIN")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card pk-bill shadow-sm border-0 overflow-hidden">
                <div class="pk-bill__head">
                    <span class="pk-bill__ic"><i class="bi bi-pencil-square"></i></span>
                    <div><h5>Buy Exam PIN</h5><p>Pick the exam body and package.</p></div>
                    <div class="pk-bill__steps"><span class="on">1 · Exam Body</span><span class="">2 · Package</span><span class="">3 · Confirm</span></div>
                </div>

            <form method="post" action="" class="p-4">
                <div class="carrier-grid d-flex flex-wrap justify-content-center gap-3 mb-4">
                    <img alt="Waec" id="waec-lg" product-status="enabled" src="/asset/waec.jpg" onclick="tickExamCarrier('waec'); resetExamQuantity();" class="rounded-4 border p-2"/>
                    <img alt="Neco" id="neco-lg" product-status="enabled" src="/asset/neco.jpg" onclick="tickExamCarrier('neco'); resetExamQuantity();" class="rounded-4 border p-2"/>
                    <img alt="Nabteb" id="nabteb-lg" product-status="enabled" src="/asset/nabteb.jpg" onclick="tickExamCarrier('nabteb'); resetExamQuantity();" class="rounded-4 border p-2"/>
			<img alt="Jamb" id="jamb-lg" product-status="enabled" src="/asset/jamb.jpg" onclick="tickExamCarrier('jamb'); resetExamQuantity();" class="rounded-4 border p-2"/>
                </div>

                <input id="examname" name="epp" type="text" placeholder="Exam Name" hidden readonly required/>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase">Select Exam Package</label>
                    <select id="product-amount" name="quantity" onchange="pickExamQty();" class="form-select form-control-lg" required>
			    <option product-category="" value="" default hidden selected>Choose Package</option>
                        <?php
                        $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                        $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]] ?? "sas_smart_parameter_values";
                        $vid = $get_logged_user_details["vendor_id"];

                        // Optimized Single Query to fetch all active exam plans
                        $plans_sql = "SELECT v.*, p.product_name, a.api_type
                            FROM $acc_level_table_name v
                            JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
                            JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id
                            WHERE v.vendor_id = '$vid' AND v.status = 1 AND p.status = 1 AND a.status = 1
                            AND a.api_type = 'exam'";

                        $plans_query = mysqli_query($connection_server, $plans_sql);
                        if ($plans_query) {
                            while($row = mysqli_fetch_assoc($plans_query)){
                                if($row["val_2"] > 0){
                                    $pname = $row['product_name'];
                                    $cat = $pname . "-exam";
                                    $display_name = !empty($row["val_4"]) ? $row["val_4"] : ucwords(trim(str_replace(["-", "_"], " ", $row["val_1"])));
                                    $label = strtoupper($pname) . " " . $display_name . " ₦" . number_format($row["val_2"], 2);
                                    echo '<option product-category="'.$cat.'" value="'.$row["val_1"].'" hidden>'.$label.'</option>';
                                }
                            }
                        } else {
                            error_log('[DGV-DB-ERROR] Exam plans_query failed: ' . mysqli_error($connection_server));
                        }
                        ?>
                    </select>
                </div>

                <?php if ($force_security_pin_for_users === 1): ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase" for="transaction-pin">Transaction PIN</label>
                    <input id="transaction-pin" name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="4" pattern="[0-9]{4}" class="form-control form-control-lg text-center" placeholder="Enter your 4-digit PIN" required />
                    <div class="form-text">Your account requires a transaction PIN for every purchase.</div>
                </div>
                <?php endif; ?>
                <button id="proceedBtn" name="buy-exam" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm rounded-pill" >
                    PURCHASE PIN
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
