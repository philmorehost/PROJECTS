<?php session_start();
    include("../func/bc-config.php");
    $service_type = mysqli_real_escape_string($connection_server, $_GET['type'] ?? 'data');
    $service_titles = [
        "data" => "Data Bundle",
        "airtime" => "Airtime Recharge",
        "cable" => "Cable TV",
        "electric" => "Electricity Token",
        "exam" => "Exam Pin",
        "betting" => "Betting Voucher"
    ];
    $current_title = $service_titles[$service_type] ?? "Print Hub";
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head(($current_title) . " Card History | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => (strtoupper($current_title)) . " BATCH HISTORY", "crumb" => "Home / Print Hub / Batch History")); ?>

    <section class="section dashboard">
      <div class="col-12">
        <div class="card info-card px-4 py-4">
            <div class="table-responsive">
                <table class="table table-striped datatable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Batch Ref</th>
                            <th>Network</th>
                            <th>Plan</th>
                            <th>Quantity</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $get_batches = mysqli_query($connection_server, "SELECT batch_reference, network, plan_name, date_sold, COUNT(*) as qty FROM sas_databundle_cards WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && user_id='".$get_logged_user_details["id"]."' AND service_type='$service_type' GROUP BY batch_reference ORDER BY date_sold DESC");
                            while($batch = mysqli_fetch_assoc($get_batches)){
                                echo '<tr>
                                        <td>'.date("Y-m-d H:i", strtotime($batch['date_sold'])).'</td>
                                        <td>'.$batch['batch_reference'].'</td>
                                        <td>'.strtoupper($batch['network']).'</td>
                                        <td>'.strtoupper($batch['plan_name']).'</td>
                                        <td>'.$batch['qty'].'</td>
                                        <td><a href="ViewDataBundleCard.php?batch='.$batch['batch_reference'].'" class="btn btn-primary btn-sm">View/Print</a></td>
                                      </tr>';
                            }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
      </div>
    </section>

	
	<?php bc_app_foot(); ?>
