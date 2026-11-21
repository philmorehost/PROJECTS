<?php
require_once __DIR__ . '/../func/bc-secure-session.php';

bc_secure_session_start();
include("../func/bc-admin-config.php");

require_once __DIR__.'/../func/bc-referral-coins.php';
// All mutations are CSRF-protected POSTs, serialised with the points owner.
if (isset($_POST['action'], $_POST['id'])) {
    bc_validate_csrf();
    $vendor_id=(int)$get_logged_admin_details['id'];
    $id=(int)$_POST['id'];
    $stmt=mysqli_prepare($connection_server, "SELECT username FROM sas_conversions WHERE id=? AND vendor_id=?");
    mysqli_stmt_bind_param($stmt,'ii',$id,$vendor_id);
    mysqli_stmt_execute($stmt);
    $conversion=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($conversion) {
        $r=bc_coin_conversion_change($vendor_id,$conversion['username'],null,$id,(string)$_POST['action']);
        $_SESSION['product_purchase_response']=$r['message'];
        if ($r['ok']) bc_send_user_coin_conversion_status_email($vendor_id,$conversion['username'],$_POST['action']==='approve'?'approved':'rejected',$r['points'],$r['amount']);
    }
    header('Location: CoinConversions.php'); exit;
}

?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Coin Conversions | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "Coin Conversions", "crumb" => "Home / Coin Conversions")); ?>

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
                                echo '<td><form method="post">'.bc_csrf_field().'<input type="hidden" name="id" value="'.(int)$row['id'].'"><button name="action" value="approve" class="btn btn-primary btn-sm">Approve</button> <button name="action" value="reject" class="btn btn-danger btn-sm">Reject</button></form></td>';
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

    


<?php bc_adm_foot(array("area" => "admin")); ?>
