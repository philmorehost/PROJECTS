<?php
session_start();
include("../func/bc-admin-config.php");

// Check if user is logged in
if (!isset($_SESSION["admin_session"])) {
    header("Location: /");
    exit();
}

$vendor_id = $get_logged_admin_details['id'];

$page_title = "Subscription History";
?>
<!DOCTYPE html>
<head>
    <title><?php echo $page_title; ?> | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1><?php echo $page_title; ?></h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="/bc-admin/Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="col-12">
            <div class="card info-card px-5 py-5">
                <div class="overflow-auto">
                    <table class="table table-responsive table-striped table-bordered">
                        <thead class="thead-dark">
                          <tr>
                              <th>S/N</th>
                              <th>Package Name</th>
                              <th>Purchase Date</th>
                              <th>Expiry Date</th>
                              <th>Amount Paid</th>
                          </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $connection_server->prepare("SELECT s.purchase_date, s.expiry_date, s.amount_paid, p.name AS package_name FROM sas_vendor_subscriptions s JOIN sas_billing_packages p ON s.package_id = p.id WHERE s.vendor_id = ? ORDER BY s.purchase_date DESC");
                            $stmt->bind_param("i", $vendor_id);
                            $stmt->execute();
                            $result = $stmt->get_result();

                            if ($result->num_rows > 0) {
                                $count = 1;
                                while ($row = $result->fetch_assoc()) {
                                    echo '<tr>
                                            <td>' . $count++ . '</td>
                                            <td>' . htmlspecialchars($row['package_name']) . '</td>
                                            <td>' . htmlspecialchars(date("F j, Y, g:i a", strtotime($row['purchase_date']))) . '</td>
                                            <td>' . htmlspecialchars(date("F j, Y", strtotime($row['expiry_date']))) . '</td>
                                            <td>₦' . htmlspecialchars(number_format($row['amount_paid'], 2)) . '</td>
                                          </tr>';
                                }
                            } else {
                                echo '<tr><td colspan="5" class="text-center">No subscription history found.</td></tr>';
                            }
                            $stmt->close();
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
