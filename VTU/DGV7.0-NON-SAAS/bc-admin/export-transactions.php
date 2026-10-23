<?php session_start();
include("../func/bc-admin-config.php");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Admin-Transactions-' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, array('Date', 'Username', 'Reference', 'Type', 'Amount', 'Status', 'Description'));

$search_statement = "";

if (isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))) {
    $search_esc = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])));
    $search_statement .= " AND (product_unique_id LIKE '%$search_esc%' OR reference LIKE '%$search_esc%' OR batch_number LIKE '%$search_esc%' OR type_alternative LIKE '%$search_esc%' OR description LIKE '%$search_esc%' OR username LIKE '%$search_esc%')";
}

if (isset($_GET["category"]) && !empty($_GET["category"])) {
    $cat = mysqli_real_escape_string($connection_server, $_GET["category"]);
    if($cat == 'wallet-funding') $search_statement .= " AND type_alternative LIKE '%credit%'";
    elseif($cat == 'wallet-refund') $search_statement .= " AND type_alternative LIKE '%refund%'";
    else $search_statement .= " AND (type_alternative LIKE '%$cat%' OR api_type LIKE '%$cat%')";
}

if (isset($_GET["start_date"]) && !empty($_GET["start_date"])) {
    $sd = mysqli_real_escape_string($connection_server, $_GET["start_date"]);
    $search_statement .= " AND DATE(date) >= '$sd'";
}

if (isset($_GET["end_date"]) && !empty($_GET["end_date"])) {
    $ed = mysqli_real_escape_string($connection_server, $_GET["end_date"]);
    $search_statement .= " AND DATE(date) <= '$ed'";
}

$vid = $get_logged_admin_details["id"];

$query = "SELECT * FROM sas_transactions WHERE vendor_id='$vid' $search_statement ORDER BY date DESC";

$result = mysqli_query($connection_server, $query);

while ($row = mysqli_fetch_assoc($result)) {
    $status = tranStatus($row['status']);

    fputcsv($output, array(
        $row['date'],
        $row['username'],
        $row['reference'],
        $row['type_alternative'] ?? 'Transaction',
        $row['discounted_amount'] ?? $row['amount'],
        $status,
        strip_tags($row['description'])
    ));
}

fclose($output);
exit();
?>
