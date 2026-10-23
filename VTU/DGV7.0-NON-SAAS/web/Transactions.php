<?php session_start();
include("../func/bc-config.php");
?>
<!DOCTYPE html>

<head>
    <title>Transactions | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link
        href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
        rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">

</head>

<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
        <h1>TRANSACTIONS</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Transactions</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <?php
            $select_user_requeried_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && username='" . $get_logged_user_details["username"] . "' && reference='" . trim(strip_tags($_GET["requery"])) . "'");
            if (mysqli_num_rows($select_user_requeried_transaction_details) == 1) {
                $purchase_method = "web";
                include("func/requery-transaction.php");
                $json_response_decode = json_decode($json_response_encode, true);
                $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
            }

            if (!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)) {
                $page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
                $offset_statement = " OFFSET " . ((50 * $page_num) - 50);
            } else {
                $offset_statement = "";
            }

            if (isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))) {
                $search_q_val = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])));
                $search_statement = " AND (t.product_unique_id LIKE '%$search_q_val%' OR t.reference LIKE '%$search_q_val%' OR t.batch_number LIKE '%$search_q_val%' OR t.type_alternative LIKE '%$search_q_val%' OR t.description LIKE '%$search_q_val%')";
                $search_parameter = "searchq=" . urlencode($search_q_val) . "&";
            } else {
                $search_statement = "";
                $search_parameter = "";
            }

            if (isset($_GET["category"]) && !empty($_GET["category"])) {
                $cat = mysqli_real_escape_string($connection_server, $_GET["category"]);
                if($cat == 'wallet-funding') $search_statement .= " AND t.type_alternative LIKE '%credit%'";
                elseif($cat == 'wallet-refund') $search_statement .= " AND t.type_alternative LIKE '%refund%'";
                else $search_statement .= " AND (t.type_alternative LIKE '%$cat%' OR a.api_type LIKE '%$cat%')";
                $search_parameter .= "category=$cat&";
            }

            if (isset($_GET["start_date"]) && !empty($_GET["start_date"])) {
                $sd = mysqli_real_escape_string($connection_server, $_GET["start_date"]);
                $search_statement .= " AND DATE(t.date) >= '$sd'";
                $search_parameter .= "start_date=$sd&";
            }

            if (isset($_GET["end_date"]) && !empty($_GET["end_date"])) {
                $ed = mysqli_real_escape_string($connection_server, $_GET["end_date"]);
                $search_statement .= " AND DATE(t.date) <= '$ed'";
                $search_parameter .= "end_date=$ed&";
            }

            $vid = $get_logged_user_details["vendor_id"];
            $uname = $get_logged_user_details["username"];
            $get_user_transaction_details = mysqli_query($connection_server, "SELECT t.*, p.product_name, a.api_type
                FROM sas_transactions t
                LEFT JOIN sas_products p ON t.product_id = p.id AND t.vendor_id = p.vendor_id
                LEFT JOIN sas_apis a ON t.api_id = a.id AND t.vendor_id = a.vendor_id
                WHERE t.vendor_id='$vid' AND t.username='$uname' $search_statement
                ORDER BY t.date DESC LIMIT 50 $offset_statement");
        ?>

        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-4">
                        <form method="get" action="Transactions.php" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Search Keywords</label>
                                <input name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"])); ?>" placeholder="Ref, Phone, Meter..." class="form-control" />
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold">Category</label>
                                <select name="category" class="form-select">
                                    <option value="">All Categories</option>
                                    <option value="airtime" <?php echo ($_GET['category'] == 'airtime') ? 'selected' : ''; ?>>Airtime</option>
                                    <option value="data" <?php echo ($_GET['category'] == 'data') ? 'selected' : ''; ?>>Data</option>
                                    <option value="cable" <?php echo ($_GET['category'] == 'cable') ? 'selected' : ''; ?>>Cable TV</option>
                                    <option value="electric" <?php echo ($_GET['category'] == 'electric') ? 'selected' : ''; ?>>Electricity</option>
                                    <option value="wallet-funding" <?php echo ($_GET['category'] == 'wallet-funding') ? 'selected' : ''; ?>>Funding</option>
                                    <option value="transfer" <?php echo ($_GET['category'] == 'transfer') ? 'selected' : ''; ?>>Transfers</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold">From Date</label>
                                <input name="start_date" type="date" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>" class="form-control" />
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold">To Date</label>
                                <input name="end_date" type="date" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>" class="form-control" />
                            </div>
                            <div class="col-md-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                                <a href="export-transactions.php?<?php echo $search_parameter; ?>" class="btn btn-success"><i class="bi bi-download"></i></a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm overflow-hidden rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>Recent Transactions</h6>
                    </div>

                    <?php
                        $query_result = $get_user_transaction_details;
                        $is_admin = false;
                        include("../func/history-table.php");
                    ?>

                    <div class="card-footer bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <?php if (isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)) { ?>
                            <a href="Transactions.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                            <?php } ?>
                        </div>
                        <?php
                            if (isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)) {
                                $trans_next = (trim(strip_tags($_GET["page"])) + 1);
                            } else {
                                $trans_next = 2;
                            }
                        ?>
                        <a href="Transactions.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php include("../func/bc-footer.php"); ?>

</body>

</html>