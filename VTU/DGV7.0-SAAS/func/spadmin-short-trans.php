<?php
    $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_vendor_transactions ORDER BY date DESC LIMIT 5");
?>
<div class="card info-card px-5 py-5">
    <div class="col-12">
        <form method="get" action="Transactions.php">
            <input style="user-select: auto;" name="searchq" type="text" value="<?php echo isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : ''; ?>" placeholder="Email, Reference No e.t.c" class="form-control" required/>
            <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                <i class="bi bi-search"></i> Search
            </button>
        </form>
    </div>
    <?php
    $query_result = $get_user_transaction_details;
    $is_admin = true;
    include("../func/history-table.php");
    ?>
</div>