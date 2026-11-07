<?php session_start();
include("../func/bc-spadmin-config.php");
include("../func/bc-crypto-func.php");

// Super Admin view of all crypto transactions across vendors
$vid_filter = isset($_GET['vid']) ? (int)$_GET['vid'] : 0;
$sql = "SELECT * FROM sas_crypto_transactions";
if ($vid_filter > 0) $sql .= " WHERE vendor_id='$vid_filter'";
$sql .= " ORDER BY created_at DESC LIMIT 100";
$transactions = mysqli_query($connection_server, $sql);

$vendors = mysqli_query($connection_server, "SELECT id, firstname, website_url FROM sas_vendors");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Crypto Management | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle px-4 pt-4">
      <h1>CRYPTO HUB (PLISIO)</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Crypto</li>
        </ol>
      </nav>
    </div>

    <section class="section p-4">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 rounded-4 p-4 text-center">
                    <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle p-3 d-inline-block mb-3">
                        <i class="bi bi-gear-fill fs-3"></i>
                    </div>
                    <h5 class="fw-bold">Global Config</h5>
                    <p class="small text-muted">Manage the master Plisio API key used for the entire platform.</p>
                    <a href="PaymentGateway.php" class="btn btn-primary w-100 rounded-pill">Configure Plisio Key</a>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0">Platform-Wide Transactions</h6>
                        <form method="get" class="d-flex gap-2">
                            <select name="vid" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="0">All Vendors</option>
                                <?php if($vendors) while($v = mysqli_fetch_assoc($vendors)) echo "<option value='{$v['id']}' ".($vid_filter==$v['id']?'selected':'').">{$v['firstname']} ({$v['website_url']})</option>"; ?>
                            </select>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover small mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Vendor</th>
                                        <th>User</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th class="pe-4">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($transactions) while($row = mysqli_fetch_assoc($transactions)): ?>
                                    <tr>
                                        <td class="ps-4">ID: <?php echo $row['vendor_id']; ?></td>
                                        <td><?php echo $row['username']; ?></td>
                                        <td class="text-uppercase"><?php echo str_replace('_', ' ', $row['type']); ?></td>
                                        <td><?php echo $row['amount']; ?> <?php echo $row['currency_code']; ?></td>
                                        <td>
                                            <?php
                                                $s = $row['status'];
                                                $c = ($s == 1) ? 'success' : (($s == 2) ? 'warning' : 'danger');
                                                $txt = ($s == 1) ? 'Success' : (($s == 2) ? 'Pending' : 'Failed');
                                                echo "<span class='badge bg-$c bg-opacity-10 text-$c'>$txt</span>";
                                            ?>
                                        </td>
                                        <td class="pe-4 text-muted"><?php echo date('M d, H:i', strtotime($row['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>