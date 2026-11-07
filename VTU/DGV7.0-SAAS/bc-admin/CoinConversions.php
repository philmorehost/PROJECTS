<?php
session_start();
include("../func/bc-admin-config.php");

// Handle status updates
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $conversion_id = (int)$_GET['id'];
    $vendor_id = $get_logged_admin_details["id"];

    // Fetch the conversion request
    $query = "SELECT * FROM sas_conversions WHERE id = ? AND vendor_id = ?";
    $stmt = mysqli_prepare($connection_server, $query);
    mysqli_stmt_bind_param($stmt, "ii", $conversion_id, $vendor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $conversion = mysqli_fetch_assoc($result);

    if ($conversion && $conversion['status'] === 'pending') {
        if ($action === 'approve') {
            // --- Approval Logic ---
            mysqli_begin_transaction($connection_server);
            try {
                // 1. Get user's point balance from sas_points_log
                $points_query = "SELECT SUM(point_amount) as total_points FROM sas_points_log WHERE username = ? AND vendor_id = ?";
                $points_stmt = mysqli_prepare($connection_server, $points_query);
                mysqli_stmt_bind_param($points_stmt, "si", $conversion['username'], $vendor_id);
                mysqli_stmt_execute($points_stmt);
                $points_result = mysqli_stmt_get_result($points_stmt);
                $user_points = mysqli_fetch_assoc($points_result)['total_points'];

                if ($user_points >= $conversion['points']) {
                    // 2. Log the point debit transaction
                    $log_type_debit = 'CONVERSION_DEBIT';
                    $sql_log_debit = "INSERT INTO sas_points_log (username, vendor_id, point_amount, log_type) VALUES (?, ?, ?, ?)";
                    $stmt_log_debit = mysqli_prepare($connection_server, $sql_log_debit);
                    $negative_points = -$conversion['points'];
                    mysqli_stmt_bind_param($stmt_log_debit, "sids", $conversion['username'], $vendor_id, $negative_points, $log_type_debit);
                    mysqli_stmt_execute($stmt_log_debit);

                    // 3. Credit user's main balance
                    $sql_credit_balance = "UPDATE sas_users SET balance = balance + ? WHERE username = ? AND vendor_id = ?";
                    $stmt_credit = mysqli_prepare($connection_server, $sql_credit_balance);
                    mysqli_stmt_bind_param($stmt_credit, "dsi", $conversion['amount'], $conversion['username'], $vendor_id);
                    mysqli_stmt_execute($stmt_credit);

                    // 4. Update conversion status
                    $update_query = "UPDATE sas_conversions SET status = 'approved', completion_date = NOW() WHERE id = ?";
                    $update_stmt = mysqli_prepare($connection_server, $update_query);
                    mysqli_stmt_bind_param($update_stmt, "i", $conversion_id);
                    mysqli_stmt_execute($update_stmt);

                    mysqli_commit($connection_server);
                    $_SESSION["product_purchase_response"] = "Conversion approved successfully.";
                    // Notify the user that their conversion was approved
                    bc_send_user_coin_conversion_status_email($vendor_id, $conversion['username'], 'approved', $conversion['points'], $conversion['amount']);
                } else {
                    // Not enough points, reject it
                    $update_query = "UPDATE sas_conversions SET status = 'rejected', completion_date = NOW() WHERE id = ?";
                    $update_stmt = mysqli_prepare($connection_server, $update_query);
                    mysqli_stmt_bind_param($update_stmt, "i", $conversion_id);
                    mysqli_stmt_execute($update_stmt);
                    mysqli_commit($connection_server);
                    $_SESSION["product_purchase_response"] = "Conversion rejected due to insufficient points.";
                    // Notify the user that their conversion was rejected
                    bc_send_user_coin_conversion_status_email($vendor_id, $conversion['username'], 'rejected', $conversion['points'], $conversion['amount']);
                }

            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($connection_server);
                $_SESSION["product_purchase_response"] = "An error occurred during approval.";
            }
        } elseif ($action === 'reject') {
            // --- Rejection Logic ---
            $update_query = "UPDATE sas_conversions SET status = 'rejected', completion_date = NOW() WHERE id = ?";
            $update_stmt = mysqli_prepare($connection_server, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $conversion_id);
            mysqli_stmt_execute($update_stmt);
            $_SESSION["product_purchase_response"] = "Conversion rejected successfully.";
            // Notify the user that their conversion was rejected
            bc_send_user_coin_conversion_status_email($vendor_id, $conversion['username'], 'rejected', $conversion['points'], $conversion['amount']);
        }
    }

    header("Location: CoinConversions.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Coin Conversions | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
        <h1>Coin Conversions</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Coin Conversions</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="col-12">
            <div class="card info-card px-5 py-5">
                <h5 class="card-title">Pending Conversion Requests</h5>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Points</th>
                                <th>Amount (₦)</th>
                                <th>Request Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $vendor_id = $get_logged_admin_details["id"];
                        $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_conversions WHERE vendor_id = ? AND status = 'pending' ORDER BY request_date ASC");
                        mysqli_stmt_bind_param($stmt, "i", $vendor_id);
                        mysqli_stmt_execute($stmt);
                        $pending_query = mysqli_stmt_get_result($stmt);
                        if (mysqli_num_rows($pending_query) > 0) {
                            while($row = mysqli_fetch_assoc($pending_query)) {
                                echo "<tr>";
                                echo "<td>" . $row['id'] . "</td>";
                                echo "<td>" . $row['username'] . "</td>";
                                echo "<td>" . number_format($row['points']) . "</td>";
                                echo "<td>" . number_format($row['amount'], 2) . "</td>";
                                echo "<td>" . $row['request_date'] . "</td>";
                                echo '<td>
                                        <a href="?action=approve&id=' . $row['id'] . '" class="btn btn-primary btn-sm">Approve</a>
                                        <a href="?action=reject&id=' . $row['id'] . '" class="btn btn-danger btn-sm">Reject</a>
                                      </td>';
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6' class='text-center'>No pending requests.</td></tr>";
                        }
                        ?>
                        </tbody>
                    </table>
                </div>

                <h5 class="card-title mt-5">Completed Conversion Requests</h5>
                <div class="table-responsive">
                     <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Points</th>
                                <th>Amount (₦)</th>
                                <th>Request Date</th>
                                <th>Status</th>
                                <th>Completion Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_conversions WHERE vendor_id = ? AND status != 'pending' ORDER BY completion_date DESC LIMIT 50");
                        mysqli_stmt_bind_param($stmt, "i", $vendor_id);
                        mysqli_stmt_execute($stmt);
                        $completed_query = mysqli_stmt_get_result($stmt);
                        if (mysqli_num_rows($completed_query) > 0) {
                            while($row = mysqli_fetch_assoc($completed_query)) {
                                $status_badge = $row['status'] == 'approved' ? 'success' : 'danger';
                                echo "<tr>";
                                echo "<td>" . $row['id'] . "</td>";
                                echo "<td>" . $row['username'] . "</td>";
                                echo "<td>" . number_format($row['points']) . "</td>";
                                echo "<td>" . number_format($row['amount'], 2) . "</td>";
                                echo "<td>" . $row['request_date'] . "</td>";
                                echo "<td><span class='badge bg-" . $status_badge . "'>" . ucfirst($row['status']) . "</span></td>";
                                echo "<td>" . $row['completion_date'] . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='text-center'>No completed requests.</td></tr>";
                        }
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