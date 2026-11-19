<?php session_start();
    include("../func/bc-config.php");
        
    if(isset($_POST["buy-card"])){
        $purchase_method = "web";
        include_once("func/card.php");
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        $_SESSION["product_purchase_status"] = $json_response_decode["status"] ?? null;
        if (isset($json_response_decode["ref"])) {
            $_SESSION["last_transaction_ref"] = $json_response_decode["ref"];
        }
        //echo '<script>alert("'.$json_response_decode["status"].': '.$json_response_decode["desc"].'");</script>';
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
    
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Card Printing | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "BUY CARD", "crumb" => "Home / Buy Card")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <form method="post" action="">
                <div class="carrier-grid d-flex justify-content-center gap-3 mb-4">
                    <img alt="Airtel" id="airtel-lg" product-status="enabled" src="/asset/airtel.png" onclick="tickDataRechargeCarrier('airtel'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                    <img alt="MTN" id="mtn-lg" product-status="enabled" src="/asset/mtn.png" onclick="tickDataRechargeCarrier('mtn'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                    <img alt="Glo" id="glo-lg" product-status="enabled" src="/asset/glo.png" onclick="tickDataRechargeCarrier('glo'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                    <img alt="9mobile" id="9mobile-lg" product-status="enabled" src="/asset/9mobile.png" onclick="tickDataRechargeCarrier('9mobile'); resetDataRechargeQuantity();" class="rounded-4 border p-2"/>
                </div>

                <input id="isprovider" name="isp" type="text" placeholder="Isp" hidden readonly required/>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Card Type</label>
                    <select id="internet-data-type" name="type" onchange="tickDataRechargeCarrier(); resetDataRechargeQuantity();" class="form-select form-control-lg" required>
                        <option value="" default hidden selected>Select Type</option>
                        <option value="datacard">Data Card</option>
                        <option value="rechargecard">Recharge Card</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Package</label>
                    <select id="product-amount" name="quantity" onchange="tickDataRechargeCarrier();" class="form-select form-control-lg" required>
                    <option product-category="" value="" default hidden selected>Card Quantity</option>
                    <?php
                        $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                        $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]] ?? "sas_smart_parameter_values";
                        $vid = $get_logged_user_details["vendor_id"];

                        // Optimized Single Query to fetch all active card plans
                        $plans_sql = "SELECT v.*, p.product_name, a.api_type
                            FROM $acc_level_table_name v
                            JOIN sas_products p ON v.product_id = p.id AND v.vendor_id = p.vendor_id
                            JOIN sas_apis a ON v.api_id = a.id AND v.vendor_id = a.vendor_id
                            WHERE v.vendor_id = '$vid' AND v.status = 1 AND p.status = 1 AND a.status = 1
                            AND a.api_type IN ('datacard', 'rechargecard')";

                        $plans_query = mysqli_query($connection_server, $plans_sql);
                        while($row = mysqli_fetch_assoc($plans_query)){
                            if($row["val_2"] > 0){
                                $pname = $row['product_name'];
                                $dtype = $row['api_type'];
                                $cat = $pname . "-" . $dtype;
                                if($dtype == "datacard"){
                                    $label = strtoupper($pname) . " DATACARD " . $row["val_1"] . " @ ₦" . number_format($row["val_2"], 2) . " (" . $row["val_3"] . " days)";
                                } else {
                                    $label = strtoupper($pname) . " RECHARGECARD ₦" . $row["val_1"] . " @ ₦" . number_format($row["val_2"], 2);
                                }
                                echo '<option product-category="'.$cat.'" value="'.$row["val_1"].'" hidden>'.$label.'</option>';
                            }
                        }
                    ?>
                </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase">Quantity</label>
                    <input style="text-align: center;" id="quantity" name="qty-number" onkeyup="tickDataRechargeCarrier();" type="text" value="" placeholder="e.g 1" pattern="[0-9]{1,}" title="Quantity must be at least 1" class="form-control form-control-lg" inputmode="numeric" required/>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase">Business/Card Name (Optional)</label>
                    <input style="text-align: center;" id="" name="card-name" type="text" value="" placeholder="Name to show on card" class="form-control form-control-lg" />
                </div>

                <button id="proceedBtn" name="buy-card" type="submit" style="pointer-events: none;" class="btn btn-primary btn-lg w-100 shadow-sm" >
                    BUY CARD
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small text-danger fw-bold"></span>
                </div>
            </form>
        </div>
      </div>
    </section>

        <?php include("../func/short-trans.php"); ?>
    
	<?php bc_app_foot(); ?>
