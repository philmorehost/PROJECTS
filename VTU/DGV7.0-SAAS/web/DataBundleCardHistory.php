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
<!DOCTYPE html>
<head>
    <title><?php echo $current_title; ?> Card History | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
	<?php include("../func/bc-header.php"); ?>

	<div class="pagetitle">
      <h1><?php echo strtoupper($current_title); ?> BATCH HISTORY</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item"><a href="PrintHub.php">Print Hub</a></li>
          <li class="breadcrumb-item active">Batch History</li>
        </ol>
      </nav>
    </div>

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

	<?php include("../func/bc-footer.php"); ?>
</body>
</html>
