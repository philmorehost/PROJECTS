<?php session_start();
include("../func/bc-config.php");
include("../func/bc-crypto-func.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}

$vid = (int)$get_logged_user_details['vendor_id'];
$username = mysqli_real_escape_string($connection_server, $get_logged_user_details['username']);

$sql = "SELECT * FROM `sas_crypto_transactions` WHERE `vendor_id`='$vid' AND `username`='$username' ORDER BY `id` DESC";
$invoices = mysqli_query($connection_server, $sql);
if (!$invoices) {
    error_log("CryptoInvoices Query Error: " . mysqli_error($connection_server));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Invoice History | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle px-4 pt-4">
      <h1>INVOICE HISTORY</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item"><a href="CryptoHub.php">Crypto</a></li>
          <li class="breadcrumb-item active">Invoices</li>
        </ol>
      </nav>
    </div>

    <section class="section p-4">
        <!-- Temporary Debug Info (Request by user) -->
        <!-- SQL Debug: <?php echo htmlspecialchars($sql); ?> -->
        <div class="alert alert-secondary border-0 rounded-4 mb-3 small py-2 px-3">
            <strong>Debug Information:</strong>
            VID: <code><?php echo $vid; ?></code> |
            User: <code><?php echo htmlspecialchars($username); ?></code> |
            Found: <code><?php echo $invoices ? mysqli_num_rows($invoices) : "ERROR"; ?></code>
        </div>

        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0">My Crypto Invoices</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover small mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Reference</th>
                                <th>Currency</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th class="pe-4">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($invoices && mysqli_num_rows($invoices) > 0): ?>
                                <?php while($row = mysqli_fetch_assoc($invoices)): ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-primary"><?php echo $row['reference']; ?></td>
                                    <td><?php echo $row['currency_code']; ?></td>
                                    <td><?php echo (float)$row['amount']; ?></td>
                                    <td>
                                        <?php
                                            $s = (int)$row['status'];
                                            $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                            $txt = ($s == 1) ? 'Paid' : (($s == 2) ? 'Unpaid' : 'Expired');
                                            echo "<span class='badge bg-$c bg-opacity-10 text-$c'>$txt</span>";
                                        ?>
                                    </td>
                                    <td><?php echo date('M d, Y | H:i', strtotime($row['created_at'])); ?></td>
                                    <td class="pe-4">
                                        <a href="ViewCryptoInvoice.php?ref=<?php echo $row['reference']; ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                                            <i class="bi bi-eye me-1"></i> View / Share
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-receipt fs-1 d-block mb-2 opacity-25"></i>
                                        No invoices generated yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-footer.php"); ?>
</body>
</html>