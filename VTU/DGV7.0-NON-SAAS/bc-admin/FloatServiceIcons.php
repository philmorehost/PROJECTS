<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");

    $services_list = [
        "Virtual Card" => "VirtualCard.php",
        "Buy Data Bundle" => "Data.php",
        "Data Bundle Card" => "DataBundleCard.php",
        "Buy Airtime VTU" => "Airtime.php",
        "Buy Bulk Data Bundle" => "BulkData.php",
        "Buy Bulk Airtime VTU" => "BulkAirtime.php",
        "Buy CableTv Sub(s)" => "Cable.php",
        "Buy Electric Token" => "Electric.php",
        "Fund Betting" => "Betting.php",
        "Card Printing" => "DataBundleCard.php",
        "Send SMS" => "BulkSMS.php",
        "Buy Exam Pin(s)" => "Exam.php",
        "Gift Card" => "GiftCard.php",
    ];

    if(isset($_POST["save-float-icons"])){
        bc_validate_csrf();
        $vid=(int)$get_logged_admin_details['id'];
        $selection=$_POST['enabled_services']??[];
        $ok=is_array($selection);
        if($ok)foreach($selection as $name)if(!is_string($name) || !isset($services_list[$name])){$ok=false;break;}
        if($ok && mysqli_begin_transaction($connection_server)) {
            $ok=(bool)mysqli_query($connection_server,"DELETE FROM sas_float_services WHERE vendor_id='$vid'");
            foreach(array_unique($selection) as $name) {
                $name=mysqli_real_escape_string($connection_server,$name);
                if(!mysqli_query($connection_server,"INSERT INTO sas_float_services (vendor_id,service_name,status) VALUES ('$vid','$name',1)"))$ok=false;
            }
            if($ok)$ok=mysqli_commit($connection_server);
            if(!$ok)mysqli_rollback($connection_server);
        } else $ok=false;
        $_SESSION['product_purchase_response']=$ok?'Floating icons configuration saved.':'Could not save floating icons. Please retry.';
        header("Location: FloatServiceIcons.php");
        exit();
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Float Service Icons | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "Float Service Icons", "crumb" => "Home / Float Service Icons")); ?>

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-4 border-0">
                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-stack me-2"></i>Quick Access Icons</h5>
                <p class="small text-muted mb-0">Choose which services appear in the floating quick-action menu</p>
            </div>
            <div class="card-body p-4 p-md-5">
                <form method="post"><?php echo bc_csrf_field(); ?>
                    <div class="row g-3">
                        <?php
                            $enabled_services = [];
                            $get_enabled = mysqli_query($connection_server, "SELECT service_name FROM sas_float_services WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                            while($row = mysqli_fetch_assoc($get_enabled)){
                                $enabled_services[] = $row['service_name'];
                            }

                            foreach($services_list as $display_name => $file_name):
                                $checked = in_array($display_name, $enabled_services) ? 'checked' : '';
                                $id = md5($display_name);
                        ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="p-3 border rounded-4 bg-light d-flex align-items-center justify-content-between h-100">
                                    <label class="fw-bold small mb-0 cursor-pointer" for="service_<?php echo $id; ?>"><?php echo $display_name; ?></label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input fs-5" type="checkbox" name="enabled_services[]" value="<?php echo $display_name; ?>" id="service_<?php echo $id; ?>" <?php echo $checked; ?>>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-5">
                        <button type="submit" name="save-float-icons" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                            <i class="bi bi-check-lg me-2"></i>Save Quick Access Settings
                        </button>
                    </div>
                </form>
            </div>
          </div>
        </div>
      </div>
    </section>

    



<?php bc_adm_foot(array("area" => "admin")); ?>
