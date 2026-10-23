<?php
    $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_fund_transfer_requests WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$get_logged_user_details["username"]."' ORDER BY date DESC LIMIT 5");
?>
<div class="card border-0 shadow-sm rounded-4 overflow-hidden mt-4">
    <div class="card-header bg-white py-3 border-0 d-flex align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>Recent Transfers</h6>
        <a href="FundTransferRequests.php" class="small fw-bold text-decoration-none">View All</a>
    </div>
    <div class="px-4 pt-3">
        <form method="get" action="FundTransferRequests.php" class="d-flex gap-2">
            <input name="searchq" type="text" placeholder="Search reference..." class="form-control form-control-sm rounded-pill px-3" required/>
            <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3">Search</button>
        </form>
    </div>
    <?php
    $query_result = $get_user_transaction_details;
    $is_admin = false;
    include("../func/history-table.php");
    ?>
</div>