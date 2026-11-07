<?php session_start();
include("../func/bc-config.php");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=Transactions-' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, array('Date', 'Reference', 'Type', 'Amount', 'Status', 'Description'));

$search_statement = "";

if (isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))) {
    $search_statement .= " && (product_unique_id LIKE '%" . trim(strip_tags($_GET["searchq"])) . "%' OR reference LIKE '%" . trim(strip_tags($_GET["searchq"])) . "%' OR batch_number LIKE '%" . trim(strip_tags($_GET["searchq"])) . "%' OR type_alternative LIKE '%" . trim(strip_tags($_GET["searchq"])) . "%' OR description LIKE '%" . trim(strip_tags($_GET["searchq"])) . "%')";
}

if (isset($_GET["category"]) && !empty($_GET["category"])) {
    $cat = mysqli_real_escape_string($connection_server, $_GET["category"]);
    if($cat == 'wallet-funding') $search_statement .= " AND type_alternative LIKE '%credit%'";
    elseif($cat == 'wallet-refund') $search_statement .= " AND type_alternative LIKE '%refund%'";
    else $search_statement .= " AND (type_alternative LIKE '%$cat%' OR a.api_type LIKE '%$cat%')";
}

if (isset($_GET["start_date"]) && !empty($_GET["start_date"])) {
    $sd = mysqli_real_escape_string($connection_server, $_GET["start_date"]);
    $search_statement .= " AND DATE(t.date) >= '$sd'";
}

if (isset($_GET["end_date"]) && !empty($_GET["end_date"])) {
    $ed = mysqli_real_escape_string($connection_server, $_GET["end_date"]);
    $search_statement .= " AND DATE(t.date) <= '$ed'";
}

$vid = $get_logged_user_details["vendor_id"];
$uname = $get_logged_user_details["username"];

$query = "SELECT t.*, p.product_name, a.api_type
    FROM sas_transactions t
    LEFT JOIN sas_products p ON t.product_id = p.id AND t.vendor_id = p.vendor_id
    LEFT JOIN sas_apis a ON t.api_id = a.id AND t.vendor_id = a.vendor_id
    WHERE t.vendor_id='$vid' AND t.username='$uname' $search_statement
    ORDER BY t.date DESC";

$result = mysqli_query($connection_server, $query);

while ($row = mysqli_fetch_assoc($result)) {
    if (isset($row["product_name"]) && isset($row["api_type"])) {
        $type = ucwords($row["product_name"] . " " . str_replace(["-", "_"], " ", $row["api_type"]));
    } else {
        $type = ucwords($row["type_alternative"] ?? $row["description"]);
    }

    $status = tranStatus($row['status']);

    fputcsv($output, array(
        $row['date'],
        $row['reference'],
        $type,
        $row['discounted_amount'] ?? $row['amount'],
        $status,
        strip_tags($row['description'])
    ));
}

fclose($output);
exit();
?>
