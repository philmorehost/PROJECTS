<?php session_start();
    include("../func/bc-admin-config.php");
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Users Batch Transaction | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* BatchTransactions - modern fintech redesign (2026-11) */
.bt-hero{border-radius:22px;padding:28px;color:#fff;margin-bottom:20px;background:radial-gradient(600px 220px at 85% -10%,color-mix(in srgb,var(--brand) 55%,transparent),transparent 60%),linear-gradient(135deg,#0b1220,#101c33 55%,color-mix(in srgb,var(--brand-dark) 55%,#0b1220));box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 20px 40px -18px rgba(2,6,23,.7)}
.bt-hero h2{font-size:1.45rem;font-weight:800;margin:0 0 4px}
.bt-hero p{margin:0;opacity:.75;font-size:.9rem}
.bt-stats{display:flex;flex-wrap:wrap;gap:12px;margin-top:18px}
.bt-stat{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:16px;padding:12px 16px;min-width:140px;box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 10px 20px -12px rgba(0,0,0,.6)}
.bt-stat .k{font-size:.68rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;opacity:.65}
.bt-stat .v{font-size:1.15rem;font-weight:800}
.bt-search{display:flex;gap:10px;flex-wrap:wrap}
.bt-search .form-control{flex:1 1 260px;border-radius:14px;padding:.75rem 1rem;font-weight:600}
.bt-table .card{border-radius:20px!important}
.bt-table .table thead th{letter-spacing:.05em;text-transform:uppercase;font-size:.7rem}
.bt-table tbody tr{cursor:pointer;transition:background .12s ease}
.bt-pager{display:flex;align-items:center;gap:10px;margin-top:18px}
.bt-pager .bt-page{font-weight:800;color:var(--muted);font-size:.85rem}
</style>
<link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
<link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "BATCH TRANSACTIONS", "crumb" => "Home / Batch Transactions")); ?><!-- End Page Title -->

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
?>

<div class="bt-hero">
    <h2><i class="bi bi-layers me-2"></i>Batch Transactions</h2>
    <p>Every bulk purchase batch for your store. Select a batch to see its items and per-item status.</p>
    <div class="bt-stats">
        <div class="bt-stat"><div class="k">Page</div><div class="v"><?php echo isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) ? (int)trim(strip_tags($_GET["page"])) : 1; ?></div></div>
        <div class="bt-stat"><div class="k">Per page</div><div class="v">20</div></div>
        <div class="bt-stat"><div class="k">Filter</div><div class="v" style="font-size:.95rem;"><?php echo !empty(trim(strip_tags($_GET["searchq"] ?? ''))) ? htmlspecialchars(trim(strip_tags($_GET["searchq"]))) : 'All batches'; ?></div></div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <form method="get" action="BatchTransactions.php" class="bt-search">
            <input name="searchq" type="text" value="<?php echo htmlspecialchars(trim(strip_tags($_GET["searchq"] ?? '')), ENT_QUOTES); ?>" placeholder="Search by username or batch number" class="form-control" />
            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-search me-1"></i> Search</button>
            <?php if(!empty(trim(strip_tags($_GET["searchq"] ?? '')))){ ?>
            <a href="BatchTransactions.php" class="btn btn-outline-secondary px-4">Clear</a>
            <?php } ?>
        </form>
    </div>
</div>

<div class="bt-table">
<?php
        $query_result = $get_user_transaction_details;
        $is_admin = true;
        $is_batch = true;
        include("../func/history-table.php");
        ?>
</div>

<div class="bt-pager">
    <?php if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) > 1)){ ?>
    <a href="BatchTransactions.php?<?php echo $search_parameter; ?>page=<?php echo (trim(strip_tags($_GET["page"])) - 1); ?>" class="btn btn-outline-primary px-4"><i class="bi bi-arrow-left me-1"></i> Prev</a>
    <?php } ?>
    <span class="bt-page">Page <?php echo isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) ? (int)trim(strip_tags($_GET["page"])) : 1; ?></span>
    <?php
        if(isset($_GET["page"]) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
            $trans_next = (trim(strip_tags($_GET["page"])) +1);
        }else{
            $trans_next = 2;
        }
    ?>
    <a href="BatchTransactions.php?<?php echo $search_parameter; ?>page=<?php echo $trans_next; ?>" class="btn btn-primary px-4 ms-auto">Next <i class="bi bi-arrow-right ms-1"></i></a>
</div>

<?php bc_adm_foot(array("area" => "admin")); ?>
