<?php session_start();
    include("../func/bc-config.php");
?>
<!DOCTYPE html>
<head>
    <title>Payment Orders | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
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
      <h1>PAYMENT ORDERS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Payment Orders</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
  
        <?php
            
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
            	$page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
            	$offset_statement = " OFFSET ".((20 * $page_num) - 20);
            }else{
            	$offset_statement = "";
            }
            
            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $search_statement = " && (reference LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR description LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR amount LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_submitted_payments WHERE vendor_id='".$get_logged_user_details["vendor_id"]."' && username='".$_SESSION["user_session"]."' $search_statement ORDER BY date DESC LIMIT 20 $offset_statement");
            
        ?>
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <h6 class="m-0 fw-bold text-primary"><i class="bi bi-clock-history me-2"></i>Recent Orders</h6>
                <form method="get" action="PaymentOrders.php" class="d-flex gap-2 w-100" style="max-width: 400px;">
                    <input name="searchq" type="text" value="<?php echo trim(strip_tags($_GET["searchq"])); ?>" placeholder="Search orders..." class="form-control form-control-sm rounded-pill px-3" />
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3">Search</button>
                </form>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                      <tr>
                          <th class="border-0 px-4">Order Details</th>
                          <th class="border-0">Reference</th>
                          <th class="border-0 text-end px-4">Status</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php
                    if(mysqli_num_rows($get_user_transaction_details) >= 1){
                    	while($user_transaction = mysqli_fetch_assoc($get_user_transaction_details)){
                            $status_class = ($user_transaction['status'] == 1 ? 'text-success' : ($user_transaction['status'] == 2 ? 'text-warning' : 'text-danger'));
                    		echo 
                    		'<tr>
					<td class="px-4">
                                    <div class="fw-bold">₦'.number_format($user_transaction["amount"], 2).'</div>
                                    <div class="small text-muted" style="font-size:11px;">'.formDate($user_transaction["date"]).'</div>
                                </td>
                                <td>
                                    <div class="small fw-bold">'.$user_transaction["reference"].'</div>
                                    <div class="text-muted" style="font-size:10px;">'.$user_transaction["mode"].' | '.$user_transaction["description"].'</div>
                                </td>
                                <td class="text-end px-4 fw-bold '.$status_class.' small">
                                    '.tranStatus($user_transaction["status"]).'
                                </td>
                    		</tr>';
                    	}
                    } else {
                        echo '<tr><td colspan="3" class="text-center py-5 text-muted">No payment orders found.</td></tr>';
                    }
                    ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <div>
                <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
                    <a href="PaymentOrders.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                        <i class="bi bi-chevron-left"></i> Previous
                    </a>
                <?php } ?>
                </div>
                <?php
                	if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                		$trans_next = (trim(strip_tags($_GET["page"])) +1);
                	}else{
                		$trans_next = 2;
                	}
                ?>
                <a href="PaymentOrders.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>
  </section>
	<?php include("../func/bc-footer.php"); ?>
	
</body>
</html>