<?php session_start();
    include("../func/bc-admin-config.php");
?>
<!DOCTYPE html>
<head>
    <title>Users Batch Transaction | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
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
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
      <h1>BATCH TRANSACTIONS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Batch Transactions</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">

        <?php
            $select_user_requeried_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='".trim(strip_tags($_GET["requery"] ?? ''))."'");
            if(mysqli_num_rows($select_user_requeried_transaction_details) == 1){
            	$get_selected_user_requeried_transaction_details = mysqli_fetch_array($select_user_requeried_transaction_details);
            	$get_logged_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && username='".$get_selected_user_requeried_transaction_details["username"]."' LIMIT 1");
            	if(mysqli_num_rows($get_logged_user_query) == 1){
            		$get_logged_user_details = mysqli_fetch_array($get_logged_user_query);
            	}
            	$purchase_method = "web";
		include("../web/func/requery-transaction.php");
            	$json_response_decode = json_decode($json_response_encode,true);
            	$_SESSION["product_purchase_response"] = $json_response_decode["desc"];
            }
            
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                $page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
                $offset_statement = " OFFSET ".((20 * $page_num) - 20);
            }else{
                $offset_statement = "";
            }
            
            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $search_statement = " && (product_name LIKE '%" . trim(strip_tags($_GET["searchq"])) . "%' OR batch_number LIKE '%" . trim(strip_tags($_GET["searchq"])) . "%' OR username LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_bulk_product_purchase WHERE vendor_id='".$get_logged_admin_details["id"]."' $search_statement ORDER BY date DESC LIMIT 20 $offset_statement");
            
        ?>
        <div class="card info-card px-5 py-5">
            <div class="row">
                <form method="get" action="BatchTransactions.php" class="">
                    <input style="user-select: auto;" name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"] ?? '')); ?>" placeholder="Username, Batch number" class="form-control mt-3" />
                    <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                        <i class="bi bi-search"></i> Search
                    </button>
                </form>
            </div>
            
        <?php
        $query_result = $get_user_transaction_details;
        $is_admin = true;
        $is_batch = true;
        include("../func/history-table.php");
        ?>
            <div class="mt-2 justify-content-between justify-items-center">
                <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
                <a href="BatchTransactions.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Prev</button>
                </a>
                <?php } ?>
                <?php
                	if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                		$trans_next = (trim(strip_tags($_GET["page"])) +1);
                	}else{
                		$trans_next = 2;
                	}
                ?>
                <a href="BatchTransactions.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Next</button>
                </a>
            </div>
        </div>
      </div>
    </section>
    <?php include("../func/bc-admin-footer.php"); ?>
    
</body>
</html>