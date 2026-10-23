<?php
    if(isset($_GET["requery"]) && !empty($_GET["requery"])){
        $select_user_requeried_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$get_logged_user_details["username"]."' && reference='".trim(strip_tags($_GET["requery"]))."'");
        if(mysqli_num_rows($select_user_requeried_transaction_details) == 1){
            $purchase_method = "web";
            include("../web/func/requery-transaction.php");
            $json_response_decode = json_decode($json_response_encode,true);
            $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        }
    }    
    $vid = $get_logged_user_details["vendor_id"];
    $uname = $get_logged_user_details["username"];
    $get_user_transaction_details = mysqli_query($connection_server, "SELECT t.*, p.product_name, a.api_type
        FROM sas_transactions t
        LEFT JOIN sas_products p ON t.product_id = p.id AND t.vendor_id = p.vendor_id
        LEFT JOIN sas_apis a ON t.api_id = a.id AND t.vendor_id = a.vendor_id
        WHERE t.vendor_id='$vid' AND t.username='$uname'
        ORDER BY t.date DESC LIMIT 5");
?>
<div class="card border-0 shadow-sm rounded-4 overflow-hidden mt-4">
    <div class="card-header bg-white py-3 border-0 d-flex align-items-center justify-content-between">
        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>Recent Transactions</h6>
        <a href="Transactions.php" class="small fw-bold text-decoration-none">View All</a>
    </div>
    <div class="px-4 pt-3">
        <form method="get" action="Transactions.php" class="d-flex gap-2">
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