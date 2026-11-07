<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    $product_type_array = array("airtime", "shared-data", "sme-data", "cg-data", "dd-data", "betting", "datacard", "rechargecard", "electric", "cable", "exam", "bulk-sms", "gift-card", "chimoney", "bsicards");
    $api_status_array = array(1 => "Public", 2 => "Private");

    $api_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["apiID"]))));
    $select_api = mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE id='$api_id_number'");
    if(mysqli_num_rows($select_api) > 0){
        $get_api_details = mysqli_fetch_array($select_api);
    }

    if(isset($_POST["update-profile"])){
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"]))));
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["status"])));
        $desc = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["desc"])));
        $price = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags(strtolower($_POST["price"])))));
        $unrefined_website_url = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["website-url"]))));
        $refined_website_url = trim(str_replace(["https","http",":/","/","www."," "],"",$unrefined_website_url));
        $website_url = $refined_website_url;
        if(!empty($type) && in_array($type, $product_type_array) && !empty($status) && in_array($status, array_keys($api_status_array)) && !empty($price) && is_numeric($price) && !empty($website_url)){
            $check_api_details = mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE id='$api_id_number'");

            if(mysqli_num_rows($check_api_details) == 1){
                mysqli_query($connection_server, "UPDATE sas_api_marketplace_listings SET api_type='$type', status='$status', description='$desc', price='$price', api_website='$website_url' WHERE id='".$api_id_number."'");
                //API Information Updated Successfully
                $json_response_array = array("desc" => "API Information Updated Successfully");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(mysqli_num_rows($check_api_details) == 0){
                    //Api Not Exists
                    $json_response_array = array("desc" => "Api Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_api_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($type)){
                //API Type Field Empty
                $json_response_array = array("desc" => "API Type Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(!in_array($type, $product_type_array)){
                    //Invalid API Type
                    $json_response_array = array("desc" => "Invalid API Type");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($status)){
                        //API Status Field Empty
                        $json_response_array = array("desc" => "API Status Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(!in_array($status, array_keys($api_status_array))){
                            //Invalid API Status
                            $json_response_array = array("desc" => "Invalid API Status");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(empty($price)){
                                //Price Field Empty
                                $json_response_array = array("desc" => "Price Field Empty");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(!is_numeric($price)){
                                    //Non-numeric Price
                                    $json_response_array = array("desc" => "Non-numeric Price");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }else{
                                    if(empty($website_url)){
                                        //Website Url Field Empty
                                        $json_response_array = array("desc" => "Website Url Field Empty");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
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
      <h1>EDIT API</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Edit API</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-8">
    
    <?php if(!empty($get_api_details['id'])){ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-4 border-0 text-center">
                <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                    <i class="bi bi-pencil-square text-dark-primary fs-1"></i>
                </div>
                <h4 class="fw-bold mb-0">Edit Marketplace API</h4>
                <p class="text-muted small">Update configuration for listing #<?php echo $get_api_details['id']; ?></p>
            </div>
            <div class="card-body p-4 p-md-5">
                <form method="post">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Service Category</label>
                            <select name="type" class="form-select rounded-3 py-2" required>
                                <option value="">Choose Type</option>
                                <?php foreach($product_type_array as $type):
                                    $selected = ($get_api_details["api_type"] == $type) ? "selected" : "";
                                ?>
                                    <option value="<?php echo $type; ?>" <?php echo $selected; ?>><?php echo str_replace(["_","-"]," ",strtoupper($type)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Listing Status</label>
                            <select name="status" class="form-select rounded-3 py-2" required>
                                <?php foreach($api_status_array as $code => $text):
                                    $selected = ($get_api_details["status"] == $code) ? "selected" : "";
                                ?>
                                    <option value="<?php echo $code; ?>" <?php echo $selected; ?>><?php echo strtoupper($text); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Selling Price (₦)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 rounded-start-3">₦</span>
                                <input name="price" type="number" step="0.01" value="<?php echo $get_api_details['price']; ?>" class="form-control rounded-end-3 py-2" required />
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Provider Base URL</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 rounded-start-3">https://</span>
                                <input name="website-url" type="text" value="<?php echo $get_api_details['api_website']; ?>" class="form-control rounded-end-3 py-2" required />
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted text-uppercase">Integration Description</label>
                            <textarea name="desc" class="form-control rounded-3" rows="5" placeholder="Details about this API integration..."><?php echo $get_api_details["description"]; ?></textarea>
                        </div>
                    </div>

                    <div class="mt-5">
                        <button name="update-profile" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                            <i class="bi bi-save2 me-2"></i>Apply Listing Updates
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php }else{ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden py-5">
            <div class="card-body text-center py-5">
                <img src="<?php echo $web_http_host; ?>/asset/ooops.gif" class="img-fluid mb-4" style="max-height: 200px;"/>
                <h3 class="fw-bold text-primary">Ooops!</h3>
                <p class="text-muted">The API listing you are looking for does not exist.</p>
                <a href="MarketPlace.php" class="btn btn-primary px-5 rounded-pill fw-bold mt-3">Back to Marketplace</a>
            </div>
        </div>
    <?php } ?>
        </div>
      </div>
    </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>