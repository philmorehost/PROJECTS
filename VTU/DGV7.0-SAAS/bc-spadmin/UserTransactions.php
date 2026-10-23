<?php session_start();
    include("../func/bc-spadmin-config.php");

    // Super Admin authorization check
    if (!isset($get_logged_spadmin_details["id"])) {
        header("Location: Login.php");
        exit;
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>User Transactions | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>
    <div class="pagetitle">
      <h1>USER TRANSACTIONS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Transactions</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">
            <?php
                $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
                $vid = isset($_GET["vid"]) ? (int)$_GET["vid"] : 0;
                $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
                $limit = 50;
                $offset = ($page_num - 1) * $limit;

                $search_statement = "";
                $search_parameter = "";
                
                if($vid > 0){
                    $search_statement .= " AND vendor_id='$vid'";
                    $search_parameter .= "vid=$vid&";
                }

                if(!empty($searchq)){
                    $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                    $search_statement .= " AND (product_unique_id LIKE '%$search_esc%' OR reference LIKE '%$search_esc%' OR description LIKE '%$search_esc%' OR username LIKE '%$search_esc%')";
                    $search_parameter .= "searchq=".urlencode($searchq)."&";
                }

                if (isset($_GET["category"]) && !empty($_GET["category"])) {
                    $cat = mysqli_real_escape_string($connection_server, $_GET["category"]);
                    $search_statement .= " AND type_alternative LIKE '%$cat%'";
                    $search_parameter .= "category=$cat&";
                }

                $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE 1=1 $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                
                if (!$get_user_transaction_details) {
                    // Critical fallback: Check if vendor_id column exists
                    $check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_transactions LIKE 'vendor_id'");
                    if (mysqli_num_rows($check_col) == 0) {
                        mysqli_query($connection_server, "ALTER TABLE sas_transactions ADD COLUMN vendor_id INT UNSIGNED NOT NULL AFTER id");
                        // Retry query
                        $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE 1=1 $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                    }
                }
            ?>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-4 border-0">
                    <form method="get" action="UserTransactions.php" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Select Vendor</label>
                            <select name="vid" class="form-select">
                                <option value="0">All Vendors</option>
                                <?php
                                    $vs = mysqli_query($connection_server, "SELECT id, website_url FROM sas_vendors WHERE status=1 ORDER BY website_url ASC");
                                    if ($vs) {
                                        while($vrow = mysqli_fetch_assoc($vs)){
                                            $is_sel = ($vid == $vrow['id']) ? 'selected' : '';
                                            echo '<option value="'.$vrow['id'].'" '.$is_sel.'>'.$vrow['website_url'].'</option>';
                                        }
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Search User / Ref</label>
                            <input name="searchq" type="text" value="<?php echo htmlspecialchars($searchq); ?>" placeholder="e.g. smartuser1" class="form-control" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Category</label>
                            <select name="category" class="form-select">
                                <option value="">All Services</option>
                                <option value="airtime" <?php echo ($_GET['category'] == 'airtime') ? 'selected' : ''; ?>>Airtime</option>
                                <option value="data" <?php echo ($_GET['category'] == 'data') ? 'selected' : ''; ?>>Data</option>
                                <option value="funding" <?php echo ($_GET['category'] == 'funding') ? 'selected' : ''; ?>>Funding</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100 fw-bold">FILTER RECORDS</button>
                        </div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <?php
                        $query_result = $get_user_transaction_details;
                        $is_admin = true;
                        include("../func/history-table.php");
                    ?>
                </div>
                <div class="card-footer bg-white py-3 border-0">
                    <div class="d-flex justify-content-center gap-2">
                        <?php if($page_num > 1): ?>
                        <a href="UserTransactions.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-4 rounded-pill">Prev</a>
                        <?php endif; ?>
                        <a href="UserTransactions.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num + 1); ?>" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">Next</a>
                    </div>
                </div>
            </div>
        </div>
      </div>
    </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
