<?php session_start();
    include("../func/bc-admin-config.php");
    
    if(isset($_SESSION["spadmin_vendor_auth"]) && ($_SESSION["spadmin_vendor_auth"] == true)){
		$status_statement = "(status='1' OR status='2')";
	}else{
		$status_statement = "status='1'";
	}
?>
<!DOCTYPE html>
<head>
    <title>API MarketPlace | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
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
      <h1>MARKETPLACE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">MarketPlace</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
        
        <?php
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                $page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
                $offset_statement = " OFFSET ".((10 * $page_num) - 10);
            }else{
                $offset_statement = "";
            }
            
            if(isset($_GET["searchq"]) && !empty(trim(strip_tags($_GET["searchq"])))){
                $search_statement = " && (api_website LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR api_type LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR description LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR price LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_active_user_details = mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE $status_statement $search_statement ORDER BY date DESC LIMIT 20 $offset_statement");
        ?>

        <div class="alert alert-info border-0 rounded-4 shadow-sm mb-4 p-4 d-flex align-items-start">
            <div class="bg-info bg-opacity-10 text-info rounded-circle p-3 me-3">
                <i class="bi bi-info-circle-fill fs-3"></i>
            </div>
            <div>
                <h5 class="fw-bold mb-1">Important Notice for Admins</h5>
                <p class="mb-0 opacity-75 small">All the API lists in the MarketPlace are <b>preconfigured</b> within the system. Simply adding an ordinary URL for a third-party API will not work unless the corresponding API integration files have been pre-mapped in the script's API Manager. Please consult the documentation before purchasing new modules.</p>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-primary">API Marketplace</h5>
                    <a href="Cart.php" class="btn btn-light rounded-pill px-4 position-relative">
                        <i class="bi bi-cart3 me-2"></i>View Cart
                        <span id="count-cart-items" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">0</span>
                    </a>
                </div>

                <form method="get" action="MarketPlace.php" class="row g-2">
                    <div class="col-md-10">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input name="searchq" type="text" value="<?php echo isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : ""; ?>" placeholder="Search by Product Name, API Website, Type..." class="form-control border-start-0" />
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Search</button>
                    </div>
                </form>
            </div>
        </div>

        <style>
            .product-card { transition: all 0.3s cubic-bezier(.25,.8,.25,1); border: 1px solid #edf2f7; }
            .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1) !important; }
            .hover-lift:active { transform: scale(0.98); }
            .module-icon { width: 60px; height: 60px; background: rgba(13, 110, 253, 0.1); color: #0d6efd; display: flex; align-items: center; justify-content: center; border-radius: 15px; margin-bottom: 20px; }
            .price-tag { font-size: 1.25rem; font-weight: 800; color: #1a202c; }
        </style>

        <div class="row g-4 mb-5">
            <?php
            if(mysqli_num_rows($get_active_user_details) >= 1){
                while($user_details = mysqli_fetch_assoc($get_active_user_details)){
                    $api_website = str_replace(["//www.","/","http:","https:"],"",$user_details["api_website"]);
                    $api_type = strtoupper(str_replace(["_","-"]," ",$user_details["api_type"]));
                    $product_description = strip_tags(checkTextEmpty($user_details["description"]));
                    $product_api_price = number_format($user_details["price"], 2);

                    $select_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' && api_base_url='".$api_website."' && api_type='".$user_details["api_type"]."'");
                    $is_installed = (mysqli_num_rows($select_api_lists) > 0);

                    if($api_website != $_SERVER["HTTP_HOST"]){
                        ?>
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="card h-100 shadow-sm border-0 rounded-4 p-2 product-card bg-white">
                                <div class="card-body d-flex flex-column p-4">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div class="module-icon">
                                            <i class="bi bi-cpu-fill fs-3"></i>
                                        </div>
                                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 small fw-bold"><?php echo $api_type; ?></span>
                                    </div>

                                    <h6 class="fw-bold mb-1 text-dark">https://<?php echo $api_website; ?></h6>
                                    <div class="price-tag mb-3">₦<?php echo $product_api_price; ?></div>

                                    <p class="small text-muted mb-4 flex-grow-1" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.6;">
                                        <?php echo $product_description; ?>
                                    </p>

                                    <div class="mt-auto">
                                        <?php if($is_installed): ?>
                                            <button class="btn btn-light w-100 disabled rounded-pill py-2 fw-bold border-0" style="background: #f8fafc; color: #94a3b8;">
                                                <i class="bi bi-check-circle-fill text-success me-2"></i>INSTALLED
                                            </button>
                                        <?php else: ?>
                                            <button onclick="addAPIToCart(this, '<?php echo $user_details['id']; ?>', '<?php echo $get_logged_admin_details['id']; ?>');"
                                                    id="cart-<?php echo $user_details['id']; ?>-<?php echo $get_logged_admin_details['id']; ?>"
                                                    class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm hover-lift transition-all cart-spans">
                                                <i class="bi bi-cart-plus me-2"></i>ADD TO CART
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php
                    }
                }
            } else {
                ?>
                <div class="col-12 text-center py-5">
                    <img src="../asset/ooops.gif" class="img-fluid mb-4" style="max-height: 200px;">
                    <h4 class="text-muted fw-bold">No API Modules Found</h4>
                    <p class="text-muted">Try adjusting your search or check back later.</p>
                </div>
                <?php
            }
            ?>
        </div>

        <div class="d-flex justify-content-between align-items-center">
            <div class="small text-muted">Showing page <?php echo isset($_GET["page"]) ? $_GET["page"] : 1; ?></div>
            <div class="d-flex gap-2">
                <?php if(isset($_GET["page"]) && $_GET["page"] > 1): ?>
                    <a href="MarketPlace.php?<?php echo $search_parameter; ?>page=<?php echo ($_GET["page"] - 1); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-4">Previous</a>
                <?php endif; ?>
                <a href="MarketPlace.php?<?php echo $search_parameter; ?>page=<?php echo (isset($_GET["page"]) ? $_GET["page"] + 1 : 2); ?>" class="btn btn-primary btn-sm rounded-pill px-4">Next Page</a>
            </div>
        </div>
      </div>
    </section>

        
    <?php include("../func/bc-admin-footer.php"); ?>
    
</body>
</html>