<?php
    $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_submitted_payments WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$_SESSION["user_session"]."' ORDER BY date DESC LIMIT 5");
?>
<div class="card info-card px-5 py-5">
    <div class="col-12">
        <form method="get" action="PaymentOrders.php">
            <input style="user-select: auto;" name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"])); ?>" placeholder="Reference No e.t.c" class="form-control" required/>
            <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                <i class="bi bi-search"></i> Search
            </button>
        </form>
    </div>
    
<div style="user-select: auto; cursor: grab;" class="overflow-auto">
    <table style="" class="table table-responsive table-striped table-bordered" title="Horizontal Scroll: Shift + Mouse Scroll Button">
        <thead class="thead-dark">
          <tr>
              <th>S/N</th><th>Reference</th><th style="">Description</th><th>Amount</th><th>Amount Paid</th><th>Mode</th><th>Status</th><th>Date</th>
          </tr>
        </thead>
        <tbody>
            <?php
                if(mysqli_num_rows($get_user_transaction_details) >= 1){
                    while($user_transaction = mysqli_fetch_assoc($get_user_transaction_details)){
                        $transaction_type = ucwords($user_transaction["type_alternative"]);
                        $countTransaction += 1;
                        echo 
                        '<tr>
                            <td>'.$countTransaction.'</td><td>'.$user_transaction["reference"].'</td><td>'.$user_transaction["description"].'</td><td>'.toDecimal($user_transaction["amount"], 2).'</td><td>'.toDecimal($user_transaction["discounted_amount"], 2).'</td><td>'.$user_transaction["mode"].'</td><td>'.tranStatus($user_transaction["status"]).'</td><td>'.formDate($user_transaction["date"]).'</td>
                        </tr>';
                    }
                }
            ?>
            </tbody>
        </table>
    </div>
</div>