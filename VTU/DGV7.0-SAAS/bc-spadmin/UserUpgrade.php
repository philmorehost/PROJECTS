<?php session_start();
    include("../func/bc-spadmin-config.php");

    $user_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["userID"]))));
    $select_user = mysqli_query($connection_server, "SELECT u.*, v.website_url FROM sas_users u LEFT JOIN sas_vendors v ON u.vendor_id = v.id WHERE u.id='$user_id_number'");
    if($select_user && mysqli_num_rows($select_user) > 0){
        $get_user_details = mysqli_fetch_array($select_user);
    }

    if(isset($_POST["upgrade-level"])){
	$account_level_upgrade_array = array("smart" => 1, "agent" => 2, "api" => 3);
        $upgrade_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["upgrade-type"])));

        if(!empty($upgrade_type) && in_array($upgrade_type, array_keys($account_level_upgrade_array))){
            $new_level = $account_level_upgrade_array[$upgrade_type];
            mysqli_query($connection_server, "UPDATE sas_users SET account_level='$new_level' WHERE id='$user_id_number'");
            $_SESSION["product_purchase_response"] = "Account Upgraded to ".accountLevel($new_level)." Successfully";
        }else{
            $_SESSION["product_purchase_response"] = "Invalid Upgrade Level";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }
?>
<!DOCTYPE html>
<head>
    <title>Upgrade User | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>
    <div class="pagetitle">
      <h1>USER UPGRADE (@<?php echo $get_user_details['username'] ?? 'Unknown'; ?>)</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item"><a href="Users.php">Users</a></li>
          <li class="breadcrumb-item active">Upgrade</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-5 col-md-8">
            <?php if(!empty($get_user_details['id'])){ ?>
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden p-4">
                    <div class="text-center mb-4">
                        <div class="bg-primary bg-opacity-10 p-4 rounded-circle d-inline-block mb-3">
                            <i class="bi bi-arrow-up-circle text-dark-primary display-4"></i>
                        </div>
                        <h4 class="fw-bold mb-1"><?php echo strtoupper($get_user_details['username']); ?></h4>
                        <p class="small text-muted mb-0">Current Level: <span class="badge bg-light text-dark fw-bold border"><?php echo accountLevel($get_user_details['account_level']); ?></span></p>
                    </div>

                    <form method="post">
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase mb-2">Target Account Level</label>
                            <select name="upgrade-type" class="form-select" required>
                                <option value="" default hidden selected>Select new level...</option>
                                <option value="smart" <?php echo ($get_user_details['account_level'] == 1) ? 'selected' : ''; ?>>Smart Earner</option>
                                <option value="agent" <?php echo ($get_user_details['account_level'] == 2) ? 'selected' : ''; ?>>Agent Vendor</option>
                                <option value="api" <?php echo ($get_user_details['account_level'] == 3) ? 'selected' : ''; ?>>API Vendor</option>
                            </select>
                        </div>

                        <div class="alert alert-warning border-0 rounded-3 small mb-4">
                            Note: Super Admin upgrades bypass payment. No wallet deduction will occur for this user.
                        </div>

                        <button name="upgrade-level" type="submit" class="btn btn-primary btn-lg w-100 rounded-3 py-3 fw-bold">
                            APPLY UPGRADE
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <a href="UserEdit.php?userID=<?php echo $user_id_number; ?>" class="btn btn-link btn-sm text-decoration-none text-muted">Back to Profile Edit</a>
                    </div>
                </div>
            <?php } ?>
        </div>
      </div>
    </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>