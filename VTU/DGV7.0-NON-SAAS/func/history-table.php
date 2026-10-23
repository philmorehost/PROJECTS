<?php
// Reusable transaction history design component
// Expects: $query_result (mysqli result), $is_admin (bool), $is_batch (bool)

$is_batch = $is_batch ?? false;

if ($query_result && mysqli_num_rows($query_result) >= 1) {
    $show_actions = ($is_admin && ((isset($is_payment_order) && $is_payment_order) || (isset($is_fund_transfer) && $is_fund_transfer))) || (!$is_admin);
    echo '<div class="card border-0 shadow-sm rounded-4 overflow-hidden mt-3">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover align-middle mb-0">';
    echo '<thead class="bg-light"><tr><th class="border-0 px-4 py-3">Type</th><th class="border-0 py-3">' . ($is_batch ? 'Batch ID' : 'Reference') . '</th><th class="border-0 py-3 text-end px-4">' . ($is_batch ? 'Details' : 'Amount') . '</th><th class="border-0 py-3 text-center">Status</th>' . ($show_actions ? '<th class="border-0 py-3 text-center">Action</th>' : '') . '</tr></thead>';
    echo '<tbody>';

    while ($row = mysqli_fetch_assoc($query_result)) {
        if ($is_batch) {
            $type = ucwords($row["product_name"]);
            $ref = $row["batch_number"];

            $vid = mysqli_real_escape_string($connection_server, $row['vendor_id']);
            $uname = mysqli_real_escape_string($connection_server, $row['username']);
            $bnum = mysqli_real_escape_string($connection_server, $row["batch_number"]);

            $get_successful_batch_transaction = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_transactions WHERE vendor_id='$vid' AND username='$uname' AND status='1' AND batch_number='$bnum'"))['count'];
            $get_pending_batch_transaction = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_transactions WHERE vendor_id='$vid' AND username='$uname' AND status='2' AND batch_number='$bnum'"))['count'];
            $get_failed_batch_transaction = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_transactions WHERE vendor_id='$vid' AND username='$uname' AND status='3' AND batch_number='$bnum'"))['count'];

            $status_summary = "S:$get_successful_batch_transaction P:$get_pending_batch_transaction F:$get_failed_batch_transaction";
            $amount_display = '<div class="small text-muted">'.$status_summary.'</div>';
            $status_text = "Batch Summary";
            $status_class = "text-primary";
            $onclick = "window.location.href='BatchDetails.php?batch=$ref'";
        } else {
            if (isset($row["product_name"]) && isset($row["api_type"])) {
                $type = ucwords(($row["product_name"] ?? '') . " " . str_replace(["-", "_"], " ", ($row["api_type"] ?? '')));
            } else if (!empty($row["api_id"]) && !empty($row["product_id"])) {
                $vid = $row['vendor_id'];
                $get_prod = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='$vid' && id='".$row["product_id"]."' LIMIT 1"));
                $get_api = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='$vid' && id='".$row["api_id"]."' LIMIT 1"));
                $type = ucwords(($get_prod["product_name"] ?? '') . " " . str_replace(["-", "_"], " ", ($get_api["api_type"] ?? '')));
            } else {
                $type = ucwords($row["type_alternative"] ?? $row["description"] ?? 'Transaction');
            }
            $ref = $row['reference'];
            $amount = number_format($row['discounted_amount'] ?? $row['amount'] ?? 0, 2);
            $amount_display = '<div class="fw-bold">₦'.$amount.'</div>';
            $status_text = tranStatus($row['status']);
            $status_class = ($row['status'] == 1 ? 'text-success' : ($row['status'] == 2 ? 'text-warning' : 'text-danger'));
            $onclick = "showTransactionDetails('$ref', ".($is_admin ? 'true' : 'false').")";
        }

        $date = date('M d, Y H:i', strtotime($row['date']));

        echo '<tr class="cursor-pointer" onclick="' . $onclick . '">';
        echo '<td class="px-4">
                ' . (!empty($row["product_unique_id"]) ? '<div class="text-primary fw-bold mb-1" style="font-size:15px; letter-spacing: 0.5px;">' . htmlspecialchars($row["product_unique_id"]) . '</div>' : '') . '
                <div class="fw-bold text-dark" style="font-size:13px;">' . htmlspecialchars($type) . '</div>
                <div class="text-muted" style="font-size:11px;">' . $date . '</div>
              </td>';
        echo '<td><span class="badge bg-light text-dark fw-normal border">' . htmlspecialchars($ref) . '</span></td>';
        echo '<td class="text-end px-4">
                ' . $amount_display . '
              </td>';
        echo '<td class="text-center px-4">
                <div class="small ' . $status_class . ' fw-bold">' . strtoupper($status_text) . '</div>
                ' . ($row['status'] == 3 && function_exists('bc_get_ai_failure_explanation') ? 
                    '<div class="mt-1"><span class="badge bg-info bg-opacity-10 text-info border-info border" style="font-size:9px; cursor:help;" title="' . htmlspecialchars(bc_get_ai_failure_explanation($row['description'] ?? '')) . '"><i class="bi bi-robot me-1"></i>SMART ASSIST</span></div>' 
                    : '') . '
              </td>';
        if ($show_actions) {
            echo '<td class="text-center" onclick="event.stopPropagation();">';
            if ($row['status'] == 2) {
                echo '<div class="d-flex gap-1 justify-content-center">';
                if ($is_admin) {
                    // Use inline_approve_page for Transactions.php-sourced wallet-funding entries
                    $approve_page = isset($inline_approve_page) ? $inline_approve_page : null;
                    if ($approve_page && (
                        $row['product_unique_id'] == 'wallet_funding' ||
                        $row['product_unique_id'] == 'manual_funding' ||
                        stripos($row['type_alternative'] ?? '', 'Wallet Funding') !== false ||
                        stripos($row['type_alternative'] ?? '', 'Wallet Credit') !== false
                    )) {
                        echo '<a href="' . $approve_page . '?order-ref=' . $ref . '&order-status=approve" class="btn btn-primary btn-sm py-1 px-2" style="font-size:11px;">Approve</a>';
                        echo '<a href="' . $approve_page . '?order-ref=' . $ref . '&order-status=cancel" class="btn btn-danger btn-sm py-1 px-2" style="font-size:11px;">Cancel</a>';
                    } else if (isset($is_payment_order) && $is_payment_order) {
                        echo '<a href="PaymentOrders.php?order-ref=' . $ref . '&order-status=2" class="btn btn-primary btn-sm py-1 px-2" style="font-size:11px;">Approve</a>';
                        echo '<a href="PaymentOrders.php?order-ref=' . $ref . '&order-status=1" class="btn btn-danger btn-sm py-1 px-2" style="font-size:11px;">Reject</a>';
                    } else if (isset($is_fund_transfer) && $is_fund_transfer) {
                        echo '<a href="FundTransferRequests.php?order-ref=' . $ref . '&order-status=2" class="btn btn-primary btn-sm py-1 px-2" style="font-size:11px;">Approve</a>';
                        echo '<a href="FundTransferRequests.php?order-ref=' . $ref . '&order-status=1" class="btn btn-danger btn-sm py-1 px-2" style="font-size:11px;">Reject</a>';
                        echo '<a href="FundTransferRequests.php?order-ref=' . $ref . '&order-status=3" class="btn btn-dark btn-sm py-1 px-2" style="font-size:11px;">Cancel</a>';
                    }
                } else {
                    // User Actions
                    if ($row['type_alternative'] == 'Wallet Funding' || $row['product_unique_id'] == 'wallet_funding') {
                        $retry_amt = (float)($row['amount'] ?? 0);
                        echo '<a href="Fund.php?retry_ref=' . $ref . '&amount=' . $retry_amt . '" class="btn btn-success btn-sm py-1 px-2" style="font-size:11px;">Pay Now</a>';
                    } else {
                        echo '<span class="text-muted small">Pending</span>';
                    }
                }
                echo '</div>';
            } else if ($row['status'] == 3 && $is_admin && strpos($_SERVER['SCRIPT_NAME'], 'Transactions.php') !== false) {
                echo '<div class="d-flex gap-1 justify-content-center">';
                echo '<a href="Transactions.php?order-ref=' . $ref . '&order-status=mark-success" class="btn btn-success btn-sm py-1 px-2" style="font-size:11px;">Mark Success</a>';
                echo '<a href="Transactions.php?requery=' . $ref . '" class="btn btn-primary btn-sm py-1 px-2" style="font-size:11px;">Requery</a>';
                echo '</div>';
            } else {
                echo '-';
            }
            echo '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table></div></div>';
} else {
    echo '<div class="text-center py-5 text-muted">No transactions found</div>';
}
?>
<style>
.cursor-pointer { cursor: pointer; }
.rounded-4 { border-radius: 1rem !important; }
</style>
