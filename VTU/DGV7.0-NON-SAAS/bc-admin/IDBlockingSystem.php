<?php session_start();
    include("../func/bc-admin-config.php");
        
    if(isset($_POST["take-action"])){
        $id = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["id"]))));
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"]))));
        $type_array = array(1 => "BLOCKED", 2 => "UNBLOCKED");
        $select_item_query = mysqli_query($connection_server, "SELECT * FROM sas_id_blocking_system WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$id'");
		if(!empty($id) && is_numeric($id)){
			if(in_array($type, array_keys($type_array))){
				if(mysqli_num_rows($select_item_query) == 1){
					
					if($type == 1){
						$json_response_array = array("desc" => "ID Has Already BLOCKED");
                		$json_response_encode = json_encode($json_response_array,true);
					}
    	                                                
        	   		if($type == 2){
        	   			mysqli_query($connection_server, "DELETE FROM sas_id_blocking_system WHERE vendor_id='".$get_logged_admin_details["id"]."' && product_id='$id'");
        	   			$json_response_array = array("desc" => "ID UNBLOCKED");
        	   			$json_response_encode = json_encode($json_response_array,true);
        	    	}
        	    			
				}else{
					if(mysqli_num_rows($select_item_query) == 0){
						if($type == 1){
							mysqli_query($connection_server, "INSERT INTO sas_id_blocking_system (vendor_id, product_id) VALUES ('".$get_logged_admin_details["id"]."', '$id')");
							$json_response_array = array("desc" => "ID BLOCKED");
							$json_response_encode = json_encode($json_response_array,true);
						}
						
						if($type == 2){
							$json_response_array = array("desc" => "ID Was Not On BLOCKED List");
							$json_response_encode = json_encode($json_response_array,true);
						}
						
					}
				}
			}else{
				//Invalid Action Type
				$json_response_array = array("desc" => "Invalid Action Type");
				$json_response_encode = json_encode($json_response_array,true);
			}
		}else{
			//Invalid ID (Empty/Non-numeric)
			$json_response_array = array("desc" => "Invalid ID (Empty/Non-numeric)");
			$json_response_encode = json_encode($json_response_array,true);
		}
        
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
?>
<!DOCTYPE html>
<head>
    <title>Share Fund | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
	<?php include("../func/bc-admin-header.php"); ?>

	<div class="pagetitle">
      <h1>ID BLOCKING SYSTEM</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">ID Blocking System</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary">Manage Blocklist</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="post" action="">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">ID NUMBER</label>
                                <input name="id" type="number" class="form-control form-control-lg rounded-3 text-center" placeholder="Phone / Meter / IUC" required/>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted">ACTION TYPE</label>
                                <select name="type" class="form-select form-select-lg rounded-3 text-center" required>
                                    <option value="" selected hidden>Choose Action...</option>
                                    <option value="1">Block ID</option>
                                    <option value="2">Unblock ID</option>
                                </select>
                            </div>
                            <button name="take-action" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                                <i class="bi bi-shield-lock me-2"></i> Update Security
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <?php
                    $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
                    $limit = 10;
                    $offset = ($page_num - 1) * $limit;

                    $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
                    $search_statement = "";
                    $search_parameter = "";
                    if(!empty($searchq)){
                        $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                        $search_statement = " && (product_id LIKE '%$search_esc%')";
                        $search_parameter = "searchq=".urlencode($searchq)."&";
                    }
                    $get_blocked = mysqli_query($connection_server, "SELECT * FROM sas_id_blocking_system WHERE vendor_id='".$get_logged_admin_details["id"]."' $search_statement ORDER BY date DESC LIMIT $limit OFFSET $offset");
                ?>
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-4 border-0">
                        <div class="row align-items-center g-3">
                            <div class="col-md-5">
                                <h5 class="fw-bold mb-0 text-primary">Blocked IDs</h5>
                                <p class="text-muted small mb-0">List of restricted numbers/accounts</p>
                            </div>
                            <div class="col-md-7">
                                <form method="get" action="IDBlockingSystem.php" class="d-flex gap-2">
                                    <input name="searchq" type="text" value="<?php echo $searchq; ?>" placeholder="Search IDs..." class="form-control rounded-pill px-3" />
                                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Filter</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-4">S/N</th>
                                        <th>ID Number</th>
                                        <th>Date Added</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(mysqli_num_rows($get_blocked) > 0):
                                        $count = $offset + 1;
                                        while($blocked = mysqli_fetch_assoc($get_blocked)):
                                    ?>
                                    <tr>
                                        <td class="ps-4 text-muted"><?php echo $count; ?></td>
                                        <td><span class="fw-bold text-dark"><?php echo $blocked["product_id"]; ?></span></td>
                                        <td class="small text-muted"><?php echo date('M d, Y h:ia', strtotime($blocked["date"])); ?></td>
                                        <td class="text-end pe-4">
                                            <button onclick="customJsRedirect('/bc-admin/IDBlockingSystem.php?take-action=1&id=<?php echo $blocked['product_id']; ?>&type=2', 'Unblock this ID?')" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-bold">Unblock</button>
                                        </td>
                                    </tr>
                                    <?php $count++; endwhile; else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">No blocked IDs found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-white py-4 border-0">
                        <div class="d-flex justify-content-center gap-2">
                            <?php if($page_num > 1): ?>
                            <a href="IDBlockingSystem.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-4 rounded-pill">Previous</a>
                            <?php endif; ?>
                            <a href="IDBlockingSystem.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num + 1); ?>" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">Next Page</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

	<?php include("../func/bc-admin-footer.php"); ?>
	
</body>
</html>
