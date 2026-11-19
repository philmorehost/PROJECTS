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
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head(($page_title) . " | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => ($page_title), "crumb" => "Home")); ?><!-- End Page Title -->

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

    



<?php bc_adm_foot(array("area" => "admin")); ?>
