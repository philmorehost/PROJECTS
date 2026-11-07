<?php session_start();
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
        "Card Printing" => "Card.php",
        "Send SMS" => "BulkSMS.php",
        "Buy Exam Pin(s)" => "Exam.php",
        "Gift Card" => "GiftCard.php",
    ];

    if(isset($_POST["save-float-icons"])){
        mysqli_query($connection_server, "DELETE FROM sas_float_services WHERE vendor_id='".$get_logged_admin_details["id"]."'");
        if(isset($_POST["enabled_services"]) && is_array($_POST["enabled_services"])){
            foreach($_POST["enabled_services"] as $service_name){
                $service_name = mysqli_real_escape_string($connection_server, $service_name);
                mysqli_query($connection_server, "INSERT INTO sas_float_services (vendor_id, service_name, status) VALUES ('".$get_logged_admin_details["id"]."', '$service_name', 1)");
            }
        }
        $_SESSION["product_purchase_response"] = "Floating icons configuration saved.";
        header("Location: FloatServiceIcons.php");
        exit();
    }
?>
<!DOCTYPE html>
<head>
    <title>Float Service Icons | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>Float Service Icons</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Float Service Icons</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-4 border-0">
                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-stack me-2"></i>Quick Access Icons</h5>
                <p class="small text-muted mb-0">Choose which services appear in the floating quick-action menu</p>
            </div>
            <div class="card-body p-4 p-md-5">
                <form method="post">
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

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
