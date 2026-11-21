<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("My Transaction | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
<link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "SELF TRANSACTIONS", "crumb" => "Home / Self Transactions")); ?><!-- End Page Title -->

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
                $search_statement = " && (product_unique_id LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR reference LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR type_alternative LIKE '%".trim(strip_tags($_GET["searchq"]))."%' OR description LIKE '%".trim(strip_tags($_GET["searchq"]))."%')";
                $search_parameter = "searchq=".trim(strip_tags($_GET["searchq"]))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_user_transaction_details = mysqli_query($connection_server, "SELECT * FROM sas_vendor_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' $search_statement ORDER BY date DESC LIMIT 20 $offset_statement");
            
        ?>
        <div class="card info-card px-5 py-5">
            <div class="row">
                <form method="get" action="SelfTransactions.php" class="">
                    <input style="user-select: auto;" name="searchq" type="text" value="<?php echo htmlspecialchars(trim(strip_tags($_GET["searchq"] ?? '')), ENT_QUOTES); ?>" placeholder="Reference No, Type, Description e.t.c" class="form-control mt-3" />
                    <button style="user-select: auto;" type="submit" class="btn btn-primary d-inline col-12 col-lg-auto my-2" >
                        <i class="bi bi-search"></i> Search
                    </button>
                </form>
            </div>
            <?php
            $query_result = $get_user_transaction_details;
            $is_admin = true;
            include("../func/history-table.php");
            ?>
            
            <div class="mt-2 justify-content-between justify-items-center">
                <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
                <a href="SelfTransactions.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>">
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
                <a href="SelfTransactions.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>">
                    <button style="user-select: auto;" class="btn btn-primary col-auto">Next</button>
                </a>
            </div>
        </div>
    
    


<?php bc_adm_foot(array("area" => "admin")); ?>
