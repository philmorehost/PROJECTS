<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-config.php");
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Share Fund History | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "FUND HISTORY", "crumb" => "Home / Share Fund History")); ?><!-- End Page Title -->

    <section class="section dashboard">
        <?php
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
            	$page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
            	$offset_statement = " OFFSET ".((20 * $page_num) - 20);
            }else{
            	$offset_statement = "";
            }
            
            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $search_statement = " && (reference LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR recipient_username LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR description LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR amount LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_fund_transfer_requests WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$_SESSION["user_session"]."' $search_statement ORDER BY date DESC LIMIT 20 $offset_statement");
        ?>

        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm overflow-hidden rounded-4">
                    <div class="card-header bg-white py-3 border-0 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-arrow-left-right me-2"></i>Fund Transfer History</h6>
                        <form method="get" action="ShareFundHistory.php" class="d-flex gap-2 w-100" style="max-width: 400px;">
                            <input name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"])); ?>" placeholder="Ref, Username, amount..." class="form-control form-control-sm rounded-pill px-3" />
                            <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3">Search</button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 12px;">
                            <thead class="bg-light text-uppercase">
                                <tr>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold">Transaction</th>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold">Receiver</th>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold">Amount</th>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold">Status/Mode</th>
                                    <th class="border-0 px-3 py-3 text-muted fw-bold">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if(mysqli_num_rows($get_user_transaction_details) >= 1){
                                    while($user_transaction = mysqli_fetch_assoc($get_user_transaction_details)){
                                        ?>
                                        <tr>
                                            <td class="px-3 py-3">
                                                <div class="fw-bold text-dark"><?php echo $user_transaction["reference"]; ?></div>
                                                <div class="small text-muted text-truncate" style="max-width: 200px;"><?php echo $user_transaction["description"]; ?></div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="fw-bold"><i class="bi bi-person-circle me-1 text-primary"></i><?php echo $user_transaction["recipient_username"]; ?></div>
                                            </td>
                                            <td class="px-3 py-3 fw-bold">
                                                ₦<?php echo number_format($user_transaction["amount"], 2); ?>
                                                <div class="small text-muted font-monospace" style="font-size: 10px;">Paid: ₦<?php echo number_format($user_transaction["discounted_amount"], 2); ?></div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <span class="badge bg-primary bg-opacity-10 text-dark-primary rounded-pill px-2 py-1"><?php echo strtoupper($user_transaction["mode"]); ?></span>
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="text-dark"><?php echo date("M j, Y", strtotime($user_transaction["date"])); ?></div>
                                                <div class="small text-muted"><?php echo date("g:i a", strtotime($user_transaction["date"])); ?></div>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    echo "<tr><td colspan='5' class='text-center py-5 text-muted'>No fund transfer history found.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="card-footer bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
                            <a href="ShareFundHistory.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                            <?php } ?>
                        </div>
                        <?php
                            if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                                $trans_next = (trim(strip_tags($_GET["page"])) + 1);
                            } else {
                                $trans_next = 2;
                            }
                        ?>
                        <a href="ShareFundHistory.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
	
	<?php bc_app_foot(); ?>
