<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    $api_type_array = array(1 => "api", 2 => "requery", 3 => "verify");

    $api_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["apiID"]))));
    $select_api = mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE id='$api_id_number'");
    if(mysqli_num_rows($select_api) > 0){
        $get_api_details = mysqli_fetch_array($select_api);
    }

    if(isset($_POST["update-api-file"])){
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"]))));
        $api_file = $_FILES["api-file"]["name"];
        $api_file_tmp = $_FILES["api-file"]["tmp_name"];
        $api_file_ext = pathinfo($api_file)["extension"];
        $acceptable_ext_array = array("php");
        if(!empty($type) && in_array($type, array_keys($api_type_array)) && !empty($api_file) && in_array($api_file_ext, $acceptable_ext_array)){
            $check_api_details = mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE id='$api_id_number'");

            if(mysqli_num_rows($check_api_details) == 1){
            	$api_details = mysqli_fetch_array($check_api_details);
            	$refined_website_url = trim(str_replace(["https","http",":/","/","www."," "],"",$api_details["api_website"]));
            	$api_name = strtolower(str_replace([" ","."], "-", trim($api_details["api_type"]."-".$refined_website_url)).".php");
                if($type == 1){
                	$api_folder_path = "../func/api-gateway/";
                }else{
                	if($type == 2){
                		$api_folder_path = "../func/api-gateway/requery/";
                	}else{
                		if($type == 3){
                			$api_folder_path = "../func/api-gateway/verify/";
                		}
                	}
                }
                
                if(isset($api_folder_path) && !empty(trim($api_folder_path)) && is_dir($api_folder_path)){
                	if(!file_exists($api_folder_path.$api_name)){
                		move_uploaded_file($api_file_tmp, $api_folder_path.$api_name);
                		//API File Uploaded Successfully
                		$json_response_array = array("desc" => "API File Uploaded Successfully");
                		$json_response_encode = json_encode($json_response_array,true);
                	}else{
                		//Api Already Exists, Delete Previous File and Try Again
                		$json_response_array = array("desc" => "Api Already Exists, Delete Previous File and Try Again");
                		$json_response_encode = json_encode($json_response_array,true);
                	}
                }else{
                	//API Path Not Set, Contact Developer
                	$json_response_array = array("desc" => "API Path Not Set, Contact Developer");
                	$json_response_encode = json_encode($json_response_array,true);
                }
                
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
                if(!in_array($type, array_keys($api_type_array))){
                    //Invalid API Type
                    $json_response_array = array("desc" => "Invalid API Type");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($api_file)){
                        //API File Field Empty
                        $json_response_array = array("desc" => "API File Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                    	if(!in_array($api_file_ext, $acceptable_ext_array)){
                    		//Error: File Extension must be ()
                    		$json_response_array = array("desc" => "Error: File Extension must be (".implode(", ", $acceptable_ext_array).")");
                    		$json_response_encode = json_encode($json_response_array,true);
                    	}
                    }
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
    
    
    if(isset($_GET["api-file-link"])){
    	$api_file_link = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["api-file-link"])));
    	if(file_exists($api_file_link)){
    		unlink($api_file_link);
    		//API File Deleted Successfully
    		$json_response_array = array("desc" => "API File Deleted Successfully");
    		$json_response_encode = json_encode($json_response_array,true);
    	}else{
    		//API File Not Exists
    		$json_response_array = array("desc" => "API File Not Exists");
    		$json_response_encode = json_encode($json_response_array,true);
    	}
    	
    	$json_response_decode = json_decode($json_response_encode,true);
    	$_SESSION["product_purchase_response"] = $json_response_decode["desc"];
    	header("Location: /bc-spadmin/ApiUpload.php?apiID=".$get_api_details["id"]);
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
      <h1>API UPLOAD</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">API Upload</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-8">
    
    <?php if(!empty($get_api_details['id'])){ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0 text-center">
                <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                    <i class="bi bi-cloud-upload text-dark-primary fs-1"></i>
                </div>
                <h4 class="fw-bold mb-0">Gateway File Manager</h4>
                <p class="text-muted small">Upload PHP logic files for this API listing</p>
            </div>
            <div class="card-body p-4 p-md-5">
                <form method="post" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted text-uppercase">Target Listing</label>
                        <input type="text" value="<?php echo strtoupper(str_replace('-', ' ',$get_api_details['api_type']).' - '.$get_api_details['api_website']); ?>" class="form-control rounded-3 bg-light" readonly/>
                    </div>

                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">File Purpose</label>
                            <select name="type" class="form-select rounded-3 py-2" required>
                                <option value="" default hidden selected>Choose Type</option>
                                <?php foreach($api_type_array as $code => $text): ?>
                                    <option value="<?php echo $code; ?>"><?php echo strtoupper($text); ?> LOGIC</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Select PHP File</label>
                            <input name="api-file" type="file" accept=".php" class="form-control rounded-3 py-2" required/>
                        </div>
                    </div>

                    <button name="update-api-file" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                        <i class="bi bi-upload me-2"></i>Upload Logic File
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="fw-bold mb-0 text-primary">Active Implementation Files</h6>
            </div>
		<div class="card-body p-4">
		    <div class="list-group list-group-flush border rounded-3">
                    <?php
                        $refined_website_url = trim(str_replace(["https","http",":/","/","www."," "],"",$get_api_details["api_website"]));
                        $api_name = strtolower(str_replace([" ","."], "-", trim($get_api_details["api_type"]."-".$refined_website_url)).".php");
                        $has_files = false;
                        foreach($api_type_array as $index => $type){
                            if($index == 1) $path = "../func/api-gateway/".$api_name;
                            elseif($index == 2) $path = "../func/api-gateway/requery/".$api_name;
                            elseif($index == 3) $path = "../func/api-gateway/verify/".$api_name;

                            if(file_exists($path)){
                                $has_files = true;
                                $label = strtoupper($api_type_array[$index]);
                                echo "
                                <div class='list-group-item d-flex justify-content-between align-items-center py-3'>
                                    <div>
                                        <div class='fw-bold text-dark small'>$label Logic</div>
                                        <div class='small text-muted'>$api_name</div>
                                    </div>
                                    <a href='/bc-spadmin/ApiUpload.php?apiID={$get_api_details['id']}&api-file-link=$path' class='btn btn-outline-danger btn-sm rounded-pill px-3' onclick=\"return confirm('Delete this implementation file?')\">
                                        <i class='bi bi-trash'></i>
                                    </a>
                                </div>";
                            }
                        }
                        if(!$has_files) echo "<div class='p-5 text-center text-muted'>No implementation files found for this listing</div>";
                    ?>
                </div>
            </div>
        </div>
    <?php }else{ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden py-5">
            <div class="card-body text-center py-5">
                <img src="<?php echo $web_http_host; ?>/asset/ooops.gif" class="img-fluid mb-4" style="max-height: 200px;"/>
                <h4 class="fw-bold text-primary">Listing Not Found</h4>
                <a href="MarketPlace.php" class="btn btn-primary px-5 rounded-pill fw-bold mt-3">Return to Marketplace</a>
            </div>
        </div>
    <?php } ?>
        </div>
      </div>
    </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>