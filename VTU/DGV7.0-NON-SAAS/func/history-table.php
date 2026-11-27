<?php
// Reusable transaction history design component
// Expects: $query_result (mysqli result), $is_admin (bool), $is_batch (bool)

$is_batch = $is_batch ?? false;
$show_vendor_col = !empty($show_vendor_info) && !$is_batch;
$tx_receipt_source = isset($tx_receipt_source) ? (string)$tx_receipt_source : '';

if ($query_result && mysqli_num_rows($query_result) >= 1) {
    $show_actions = ($is_admin && ((isset($is_payment_order) && $is_payment_order) || (isset($is_fund_transfer) && $is_fund_transfer) || (isset($inline_approve_page) && $inline_approve_page !== ''))) || (!$is_admin);
    echo '<div class="card border-0 shadow-sm rounded-4 overflow-hidden mt-3">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover align-middle mb-0">';
    echo '<thead class="bg-light"><tr><th class="border-0 px-4 py-3">Type</th>' . ($show_vendor_col ? '<th class="border-0 py-3">Vendor</th>' : '') . '<th class="border-0 py-3">' . ($is_batch ? 'Batch ID' : 'Reference') . '</th><th class="border-0 py-3 text-end px-4">' . ($is_batch ? 'Details' : 'Amount') . '</th><th class="border-0 py-3 text-center">Status</th>' . ($show_actions ? '<th class="border-0 py-3 text-center">Action</th>' : '') . '</tr></thead>';
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
            $onclick = 'window.location.href=' . json_encode('BatchDetails.php?batch=' . rawurlencode($ref), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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
            if (!$is_admin && function_exists('bc_mask_provider_names')) {
                $type = bc_mask_provider_names($type);
            }
            $ref = $row['reference'];
            $raw_amt = $row['discounted_amount'] ?? ($row['amount'] ?? 0);
            $amount = number_format(is_numeric($raw_amt) ? (float)$raw_amt : 0.0, 2);
            $amount_display = '<div class="fw-bold">₦'.$amount.'</div>';
            $status_text = tranStatus($row['status']);
            $status_class = ($row['status'] == 1 ? 'text-success' : ($row['status'] == 2 ? 'text-warning' : 'text-danger'));
            $onclick = 'showTransactionDetails(' . json_encode((string)$ref, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', ' . ($is_admin ? 'true' : 'false') . ($tx_receipt_source !== '' ? ', ' . json_encode($tx_receipt_source, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : '') . ')';
        }

        $date = date('M d, Y H:i', strtotime($row['date']));

        echo '<tr class="cursor-pointer" tabindex="0" role="button" onkeydown="if(event.target===this &amp;&amp; (event.key===&quot;Enter&quot; || event.key===&quot; &quot;)){event.preventDefault();this.click();}" onclick="' . htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8') . '">';
        echo '<td class="px-4">
                ' . (!empty($row["product_unique_id"]) ? '<div class="text-primary fw-bold mb-1" style="font-size:15px; letter-spacing: 0.5px;">' . htmlspecialchars($row["product_unique_id"]) . '</div>' : '') . '
                <div class="fw-bold text-dark" style="font-size:13px;">' . htmlspecialchars($type) . '</div>
                <div class="text-muted" style="font-size:11px;">' . $date . ((!empty($is_admin) || !empty($show_vendor_col)) && !empty($row['username']) ? ' · Customer: <strong>' . htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8') . '</strong>' . (!empty($row['recipient_username']) ? ' → <strong>' . htmlspecialchars((string)$row['recipient_username'], ENT_QUOTES, 'UTF-8') . '</strong>' : '') : '') . '</div>
              </td>';
        if ($show_vendor_col) {
            $v_name = trim((string)($row['vendor_name'] ?? ''));
            if ($v_name === '' && !empty($row['vendor_business_name'])) {
                $v_name = trim((string)$row['vendor_business_name']);
            }
            if ($v_name === '' && !empty($row['vendor_id'])) {
                $v_name = 'Vendor #' . (int)$row['vendor_id'];
            }
            $v_email = trim((string)($row['vendor_email'] ?? ''));
            $v_site = trim((string)($row['vendor_website'] ?? ''));
            echo '<td>';
            echo '<div class="fw-semibold text-dark" style="font-size:13px;">' . htmlspecialchars($v_name !== '' ? $v_name : 'Unknown Vendor', ENT_QUOTES, 'UTF-8') . '</div>';
            if ($v_email !== '') {
                echo '<div class="text-muted" style="font-size:11px;">' . htmlspecialchars($v_email, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            if ($v_site !== '') {
                echo '<div class="text-primary" style="font-size:11px;">' . htmlspecialchars($v_site, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            echo '</td>';
        }
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
                // §29: approvals are POST forms (CSRF token + PIN + 2FA code),
                // never GET links — a GET approval can be fired by an <img>
                // tag on any page a logged-in admin happens to open.
                $bc_order_csrf = function_exists('bc_csrf_field') ? bc_csrf_field() : '';
                $bc_mk_order_form = function ($page, $buttons) use ($bc_order_csrf, $ref) {
                    $h = '<form method="post" action="' . $page . '" class="d-flex gap-1 align-items-center justify-content-center flex-wrap" onclick="event.stopPropagation();">'
                        . $bc_order_csrf
                        . '<input type="hidden" name="order-ref" value="' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '">'
                        . '<input type="password" name="pin" maxlength="8" inputmode="numeric" autocomplete="off" placeholder="PIN" title="Security PIN" style="width:68px;min-width:68px;font-size:11px;" class="form-control form-control-sm py-0">'
                        . '<input type="text" name="otp_code" maxlength="8" inputmode="numeric" autocomplete="off" placeholder="2FA" title="Authenticator or email code" style="width:68px;min-width:68px;font-size:11px;" class="form-control form-control-sm py-0">'
                        . '<input type="hidden" name="totp_code" value="">';
                    foreach ($buttons as $b) {
                        $h .= '<button type="submit" name="order-status" value="' . $b[0] . '" class="' . $b[2] . '" style="font-size:11px;">' . $b[1] . '</button>';
                    }
                    return $h . '</form>';
                };
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
                        echo $bc_mk_order_form($approve_page, array(array('approve', 'Approve', 'btn btn-primary btn-sm py-1 px-2'), array('cancel', 'Cancel', 'btn btn-danger btn-sm py-1 px-2')));
                    } else if (isset($is_payment_order) && $is_payment_order) {
                        echo $bc_mk_order_form('PaymentOrders.php', array(array('2', 'Approve', 'btn btn-primary btn-sm py-1 px-2'), array('1', 'Reject', 'btn btn-danger btn-sm py-1 px-2')));
                    } else if (isset($is_fund_transfer) && $is_fund_transfer) {
                        echo $bc_mk_order_form('FundTransferRequests.php', array(array('2', 'Approve', 'btn btn-primary btn-sm py-1 px-2'), array('1', 'Reject', 'btn btn-danger btn-sm py-1 px-2'), array('3', 'Cancel', 'btn btn-dark btn-sm py-1 px-2')));
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
                echo $bc_mk_order_form('Transactions.php', array(array('mark-success', 'Mark Success', 'btn btn-success btn-sm py-1 px-2')));
                echo '<a href="Transactions.php?review_requery=' . $ref . '" class="btn btn-primary btn-sm py-1 px-2" style="font-size:11px;">Requery</a>';
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
