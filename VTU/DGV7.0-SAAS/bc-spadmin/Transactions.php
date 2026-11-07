<?php session_start();
    include("../func/bc-spadmin-config.php");
?>
<!DOCTYPE html>
<head>
    <title></title>
    <meta charset="UTF-8" />
    <meta name="description" content="" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
  
        <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>
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
      <div class="col-12">

        <?php
            $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
            $limit = 20;
            $offset = ($page_num - 1) * $limit;
            $offset_statement = " OFFSET $offset";
            
            $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
            $search_statement = "";
            $search_parameter = "";

            if(!empty($searchq)){
                $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                $search_statement = "WHERE (product_unique_id LIKE '%$search_esc%' OR reference LIKE '%$search_esc%' OR type_alternative LIKE '%$search_esc%' OR description LIKE '%$search_esc%')";
                $search_parameter = "searchq=".urlencode($searchq)."&";
            }
            
            $get_vendor_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_vendor_transactions $search_statement ORDER BY date DESC LIMIT $limit $offset_statement");
        ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0">
                <div class="row align-items-center g-3">
                    <div class="col-md-6">
                        <h5 class="fw-bold mb-0 text-primary">Vendor Transactions</h5>
                        <p class="text-muted small mb-0">Monitor all vendor funding and service activities</p>
                    </div>
                    <div class="col-md-6">
                        <form method="get" action="Transactions.php" class="d-flex gap-2 justify-content-md-end">
                            <input name="searchq" type="text" value="<?php echo htmlspecialchars($searchq); ?>" placeholder="Ref, Email, Service..." class="form-control rounded-pill px-3" style="max-width: 250px;" />
                            <input hidden name="page" type="number" value="1" />
                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Filter</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <?php
                    $query_result = $get_vendor_transaction_details;
                    $is_admin = true;
                    // For vendor transactions, we might want to show approval actions if it's wallet funding
                    $inline_approve_page = "Transactions.php"; 
                    include("../func/history-table.php");
                ?>
            </div>
            <div class="card-footer bg-white py-4 border-0">
                <div class="d-flex justify-content-center gap-2">
                    <?php if($page_num > 1): ?>
                    <a href="Transactions.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-4 rounded-pill">Previous Page</a>
                    <?php endif; ?>

                    <?php
                        $trans_next = $page_num + 1;
                        // Check if there are more records for next page (optional but good)
                    ?>
                    <a href="Transactions.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">Next Page</a>
                </div>
            </div>
        </div>
      </div>
    </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>

