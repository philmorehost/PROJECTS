<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    $get_host_name = array_filter(explode(":",trim($_SERVER["HTTP_HOST"])));
    $get_host_name = $get_host_name[0];
    if(isset($_POST["delete-cart"])){
        $get_cart_items = mysqli_real_escape_string($connection_server, $_COOKIE[str_replace([":","."],"_",$get_host_name)."_".$get_logged_spadmin_details["id"]."_cart_items"]);
        $marketplace_redirect = false;
        if(isset($get_cart_items)){
            $exp_cart_items = array_filter(explode(" ",trim($get_cart_items)));
            if(count($exp_cart_items) >= 1){
                foreach($exp_cart_items as $items){
                    $all_refined_cart_items .= "id='$items' ";
                }
                $exp_all_refined_cart_items = array_filter(explode(" ",trim($all_refined_cart_items)));
                $implode_cart_items = implode(" OR ", $exp_all_refined_cart_items);
                //Clear Cart Items Cookies
                setcookie(str_replace([":","."],"_",$get_host_name)."_".$get_logged_spadmin_details["id"]."_cart_items", "", (time() - 100));
                mysqli_query($connection_server, "DELETE FROM sas_api_marketplace_listings WHERE $implode_cart_items");
                //Cart Item Deleted Successfully
                $json_response_array = array("desc" => "Cart Item Deleted Successfully");
                $json_response_encode = json_encode($json_response_array,true);
                $marketplace_redirect = true;
            }else{
                //No Item In Cart
                $json_response_array = array("desc" => "No Item In Cart");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Cart Is Empty
            $json_response_array = array("desc" => "Cart Is Empty");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        if($marketplace_redirect == false){
            header("Location: ".$_SERVER["REQUEST_URI"]);
        }else{
            header("Location: /bc-spadmin/MarketPlace.php");
        }
    }
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
      <h1>CART CHECKOUT</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Cart</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-cart3 me-2"></i>Admin Selection Cart</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <?php
                            $get_cart_items = $_COOKIE[str_replace([":","."],"_",$get_host_name)."_".$get_logged_spadmin_details["id"]."_cart_items"];
                            
                            if(isset($get_cart_items) && !empty($get_cart_items)){
                                $exp_cart_items = array_filter(explode(" ",trim($get_cart_items)));

                                $count_new_cart_items = 0;
                                $count_new_cart_items_amount = 0;
                                foreach($exp_cart_items as $item_id){
                                    if(is_numeric($item_id) && ($item_id > 0)){
                                        $get_active_cart_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE id='".$item_id."'"));

                                        if(isset($get_active_cart_details["api_type"])){
                                            $api_website = str_replace(["//www.","/","http:","https:"],"",$get_active_cart_details["api_website"]);
                                            $api_type = strtoupper(str_replace(["_","-"]," ",$get_active_cart_details["api_type"]));
                                            $product_description = checkTextEmpty($get_active_cart_details["description"]);
                                            $product_api_price = number_format($get_active_cart_details["price"], 2);
                                            $api_status_array = array(1 => "Public", 2 => "Private");
                                            $count_new_cart_items += 1;
                                            $count_new_cart_items_amount += $get_active_cart_details["price"];
                                            $api_status = $api_status_array[$get_active_cart_details["status"]];

                                            echo '
                                            <div class="col-12">
                                                <div class="d-flex align-items-center border-bottom pb-4 mb-2">
                                                    <div class="bg-primary bg-opacity-10 rounded-4 p-3 me-4 text-primary fs-3">
                                                        <i class="bi bi-cpu"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <div>
                                                                <h6 class="fw-bold mb-1">'.$api_type.' API MODULE</h6>
                                                                <p class="small text-muted mb-0">Website: https://'.$api_website.'</p>
                                                            </div>
                                                            <div class="text-end">
                                                                <div class="fw-bold text-dark fs-5">₦'.$product_api_price.'</div>
                                                                <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-2" style="font-size: 10px;">'.$api_status.'</span>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex justify-content-between align-items-center mt-3">
                                                            <button onclick="removeAPIFromCart(`'.$item_id.'`, `'.$get_logged_spadmin_details["id"].'`);" class="btn btn-link p-0 text-danger small text-decoration-none fw-bold"><i class="bi bi-trash3 me-1"></i>REMOVE</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>';
                                        }
                                    }
                                }
                            }else{
                                echo '
                                <div class="col-12 text-center py-5">
                                    <img src="'.$web_http_host.'/asset/ooops.gif" class="img-fluid mb-4" style="max-height: 200px;"/>
                                    <h4 class="fw-bold text-muted">Selection Cart is Empty</h4>
                                    <p class="text-muted small">Go to the marketplace to manage API listings.</p>
                                    <a href="MarketPlace.php" class="btn btn-primary rounded-pill px-5 mt-3 fw-bold">BROSWSE MARKETPLACE</a>
                                </div>';
                            }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="fw-bold mb-0">Bulk Action</h5>
                </div>
                <div class="card-body p-4">
                    <?php if($count_new_cart_items > 0): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Selected Items</span>
                            <span class="fw-bold"><?php echo $count_new_cart_items; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-4">
                            <span class="text-muted small">Combined Value</span>
                            <span class="fw-bold text-primary">₦<?php echo number_format($count_new_cart_items_amount, 2); ?></span>
                        </div>

                        <form method="post">
                            <button onclick="askPermissionSubBtn(this,'Are you sure you want to PERMANENTLY DELETE these listings from the marketplace?');"
                                    name="delete-cart" type="button"
                                    class="btn btn-danger w-100 rounded-pill py-3 fw-bold shadow-sm">
                                <i class="bi bi-trash3 me-2"></i>DELETE SELECTED
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="text-muted text-center small py-3">No items selected.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-warning border-0 rounded-4 p-4 shadow-sm">
                <h6 class="fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Admin Note</h6>
                <p class="small mb-0 opacity-75">Deleting items from the marketplace cart will remove them permanently from the public listing. This action cannot be undone.</p>
            </div>
        </div>
      </div>
    </section>
        
        
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>