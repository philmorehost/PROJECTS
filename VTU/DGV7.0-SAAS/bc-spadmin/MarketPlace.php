<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    // Handle Delete Action
    if (isset($_GET['deleteID']) && is_numeric($_GET['deleteID'])) {
        $did = (int)$_GET['deleteID'];
        if (mysqli_query($connection_server, "DELETE FROM sas_api_marketplace_listings WHERE id='$did'")) {
            $_SESSION['product_purchase_response'] = "Marketplace listing removed successfully.";
        } else {
            $_SESSION['product_purchase_response'] = "Error: Could not remove listing.";
        }
        header("Location: MarketPlace.php");
        exit();
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
      <h1>MARKETPLACE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">MarketPlace</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-12">
                <?php
                    $page_num = (isset($_GET["page"]) && is_numeric($_GET["page"]) && $_GET["page"] >= 1) ? (int)$_GET["page"] : 1;
                    $limit = 20;
                    $offset = ($page_num - 1) * $limit;

                    $searchq = isset($_GET["searchq"]) ? trim(strip_tags($_GET["searchq"])) : "";
                    $search_statement = " WHERE 1 ";
                    $search_parameter = "";
                    if(!empty($searchq)){
                        $search_esc = mysqli_real_escape_string($connection_server, $searchq);
                        $search_statement .= " AND (api_website LIKE '%$search_esc%' OR api_type LIKE '%$search_esc%' OR description LIKE '%$search_esc%' OR price LIKE '%$search_esc%')";
                        $search_parameter = "searchq=".urlencode($searchq)."&";
                    }

                    $get_listings = mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings $search_statement ORDER BY id DESC LIMIT $limit OFFSET $offset");
                ?>

                <style>
                    .product-card { transition: all 0.3s cubic-bezier(.25,.8,.25,1); border: 1px solid #edf2f7; position: relative; }
                    .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1) !important; }
                    .module-icon { width: 60px; height: 60px; background: rgba(13, 110, 253, 0.1); color: #0d6efd; display: flex; align-items: center; justify-content: center; border-radius: 15px; margin-bottom: 20px; }
                    .price-tag { font-size: 1.25rem; font-weight: 800; color: #1a202c; }
                    .status-badge { position: absolute; top: 20px; right: 20px; }
                </style>

                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-body p-4">
                        <div class="row align-items-center g-3">
                            <div class="col-md-4">
                                <h5 class="fw-bold mb-0 text-primary">Marketplace Management</h5>
                                <p class="text-muted small mb-0">Overview of all service modules for vendors</p>
                            </div>
                            <div class="col-md-5">
                                <form method="get" action="MarketPlace.php" class="d-flex gap-2">
                                    <input name="searchq" type="text" value="<?php echo $searchq; ?>" placeholder="Search providers or services..." class="form-control rounded-pill px-3" />
                                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Filter</button>
                                </form>
                            </div>
                            <div class="col-md-3 text-md-end">
                                <a href="MarketPlace.php" class="btn btn-light rounded-pill px-4 border"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4 mb-5">
                    <?php if(mysqli_num_rows($get_listings) > 0):
                        while($listing = mysqli_fetch_assoc($get_listings)):
                            $api_website = str_replace(["//www.","/","http:","https:"],"",$listing["api_website"]);
                            $api_type = strtoupper(str_replace(["_","-"]," ",$listing["api_type"]));
                            $status_label = ($listing["status"] == 1) ? '<span class="badge bg-success rounded-pill">Public</span>' : '<span class="badge bg-secondary rounded-pill">Private</span>';
                    ?>
                    <div class="col-md-6 col-lg-4 col-xl-3">
                        <div class="card h-100 shadow-sm border-0 rounded-4 p-2 product-card bg-white">
                            <div class="status-badge"><?php echo $status_label; ?></div>
                            <div class="card-body d-flex flex-column p-4">
                                <div class="module-icon">
                                    <i class="bi bi-cpu-fill fs-3"></i>
                                </div>

                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 small fw-bold mb-2 align-self-start"><?php echo $api_type; ?></span>
                                <h6 class="fw-bold mb-1 text-dark">https://<?php echo $api_website; ?></h6>
                                <div class="price-tag mb-3">₦<?php echo number_format($listing["price"], 2); ?></div>

                                <p class="small text-muted mb-4 flex-grow-1" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.6;">
                                    <?php echo strip_tags($listing["description"]); ?>
                                </p>

                                <div class="mt-auto pt-3 border-top">
                                    <div class="d-flex gap-2 mb-2">
                                        <a href="/bc-spadmin/ApiEdit.php?apiID=<?php echo $listing['id']; ?>" class="btn btn-primary flex-grow-1 rounded-pill fw-bold small"><i class="bi bi-pencil me-1"></i>EDIT</a>
                                        <a href="/bc-spadmin/ApiUpload.php?apiID=<?php echo $listing['id']; ?>" class="btn btn-outline-info flex-grow-1 rounded-pill fw-bold small"><i class="bi bi-upload me-1"></i>FILES</a>
                                    </div>
                                    <button class="btn btn-outline-danger w-100 rounded-pill fw-bold small" onclick="if(confirm('Are you sure you want to delete this listing?')) window.location.href='MarketPlace.php?deleteID=<?php echo $listing['id']; ?>'"><i class="bi bi-trash me-1"></i>DELETE</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="col-12 text-center py-5">
                        <img src="../asset/ooops.gif" class="img-fluid mb-4" style="max-height: 200px;">
                        <h4 class="text-muted fw-bold">No Listings Found</h4>
                        <p class="text-muted">Your search did not return any results.</p>
                    </div>
                    <?php endif; ?>
                </div>
                    <div class="card-footer bg-white py-4 border-0">
                        <div class="d-flex justify-content-center gap-2">
                            <?php if($page_num > 1): ?>
                            <a href="MarketPlace.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num - 1); ?>" class="btn btn-outline-primary btn-sm px-4 rounded-pill">Previous</a>
                            <?php endif; ?>
                            <a href="MarketPlace.php?<?php echo $search_parameter; ?>page=<?php echo ($page_num + 1); ?>" class="btn btn-primary btn-sm px-4 rounded-pill shadow-sm">Next Page</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>