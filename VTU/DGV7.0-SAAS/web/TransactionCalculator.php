<?php session_start();
include("../func/bc-config.php");
?>
<!DOCTYPE html>

<head>
    <title>Transactions Calculator | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    
                  <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link
    href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
    rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>

<body>
    <?php include("../func/bc-header.php"); ?>
    
  <div class="pagetitle">
      <h1>TRANSACTION CALCULATOR</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Transaction Calculator</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 p-4 mb-4">
                <h5 class="fw-bold mb-4">Filter by Date</h5>
                <form method="get" action="TransactionCalculator.php">
                    <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
                        <?php
                        $presets = [
                            '7 DAYS' => '-7 days',
                            '2 WEEKS' => '-14 days',
                            '1 MONTH' => '-1 month',
                            '3 MONTHS' => '-3 months',
                            '6 MONTHS' => '-6 months',
                            '1 YEAR' => '-1 year'
                        ];
                        foreach($presets as $label => $offset): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3"
                                onclick="updateTransactionCalculatorCustomDate('<?php echo date('Y-m-d', strtotime($offset)); ?>', '<?php echo date('Y-m-d'); ?>');">
                                <?php echo $label; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $search_starting_date = $_GET["starts"] ?? date("Y-m-d");
                    $search_ending_date = $_GET["ends"] ?? date("Y-m-d");
                    ?>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-uppercase">Starting Date</label>
                        <input id="starting-date" name="starts" type="date" value="<?php echo $search_starting_date; ?>" class="form-control" required />
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-uppercase">Ending Date</label>
                        <input id="ending-date" name="ends" type="date" value="<?php echo $search_ending_date; ?>" class="form-control" required />
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 shadow-sm fw-bold">
                        CALCULATE
                    </button>
                </form>
            </div>
        </div>
      
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 p-4">
                <h5 class="fw-bold mb-4">Analysis Results</h5>
                <div class="row g-3">
                    <?php
                    $vid = $get_logged_user_details["vendor_id"];
                    $uname = $get_logged_user_details["username"];
                    $starts = mysqli_real_escape_string($connection_server, $search_starting_date . " 00:00:00");
                    $ends = mysqli_real_escape_string($connection_server, $search_ending_date . " 23:59:59");

                    // Optimized Single Query using CASE WHEN and GROUP BY
                    $calc_sql = "SELECT
                        CASE
                            WHEN LOWER(type_alternative) LIKE '%airtime%' THEN 'Airtime'
                            WHEN LOWER(type_alternative) LIKE '%sme data%' THEN 'SME Data'
                            WHEN LOWER(type_alternative) LIKE '%cg data%' THEN 'Corporate Data'
                            WHEN LOWER(type_alternative) LIKE '%shared data%' THEN 'Shared Data'
                            WHEN LOWER(type_alternative) LIKE '%dd data%' THEN 'Direct Data'
                            WHEN LOWER(type_alternative) LIKE '%electric%' THEN 'Electricity'
                            WHEN LOWER(type_alternative) LIKE '%exam%' THEN 'Exam PIN'
                            WHEN LOWER(type_alternative) LIKE '%cable%' THEN 'Cable TV'
                            WHEN LOWER(type_alternative) LIKE '%sms%' THEN 'Bulk SMS'
                            WHEN LOWER(type_alternative) LIKE '%card%' THEN 'Data Card'
                            WHEN LOWER(type_alternative) LIKE '%betting%' THEN 'Betting'
                            ELSE 'Other'
                        END as product_group,
                        COUNT(*) as qty,
                        SUM(amount) as total_amount,
                        SUM(discounted_amount) as total_paid
                    FROM sas_transactions
                    WHERE vendor_id='$vid' AND username='$uname' AND status='1'
                    AND date BETWEEN '$starts' AND '$ends'
                    GROUP BY product_group";

                    $calc_query = mysqli_query($connection_server, $calc_sql);
                    $found = false;
                    while($res = mysqli_fetch_assoc($calc_query)):
                        $found = true;
                    ?>
                        <div class="col-md-6">
                            <div class="p-3 border rounded-4 bg-light h-100">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-primary"><?php echo $res['product_group']; ?></span>
                                    <span class="badge bg-white text-primary border rounded-pill">Qty: <?php echo $res['qty']; ?></span>
                                </div>
                                <div class="mb-1">
                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 9px;">Total Value</small><br>
                                    <span class="h5 fw-bold mb-0">₦<?php echo number_format($res['total_amount'], 2); ?></span>
                                </div>
                                <div>
                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 9px;">Amount Paid</small><br>
                                    <span class="fw-bold text-success">₦<?php echo number_format($res['total_paid'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endwhile;
                    if(!$found) echo '<div class="col-12 text-center py-5 text-muted">No successful transactions found for the selected period.</div>';
                    ?>
                </div>
            </div>
        </div>
      </div>
  </div>
</section>
    <script>
        function updateTransactionCalculatorCustomDate(start_date, end_date) {
            var starting_date = document.getElementById("starting-date");
            var ending_date = document.getElementById("ending-date");
            starting_date.value = start_date;
            ending_date.value = end_date;
        }
    </script>
    <?php include("../func/bc-footer.php"); ?>

</body>

</html>