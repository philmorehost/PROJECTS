<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");
    
    if(isset($_SESSION["spadmin_vendor_auth"]) && ($_SESSION["spadmin_vendor_auth"] == true)){
		$status_statement = "(status='1' OR status='2')";
	}else{
		$status_statement = "status='1'";
	}
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("API MarketPlace | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "MARKETPLACE", "crumb" => "Home / MarketPlace")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="col-12">
        
        <?php
            if(!isset($_GET["searchq"]) && isset($_GET["page"]) && !empty(trim(strip_tags($_GET["page"]))) && is_numeric(trim(strip_tags($_GET["page"]))) && (trim(strip_tags($_GET["page"])) >= 1)){
                $page_num = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["page"])));
                $offset_statement = " OFFSET ".((10 * $page_num) - 10);
            }else{
                $offset_statement = "";
            }
            
            // §28 marketplace category filter (chips below). Values map to api_type slugs.
        $market_cat = trim(strip_tags(strtolower($_GET["cat"] ?? "")));
        $market_cat_allowed = array("airtime","shared-data","sme-data","cg-data","dd-data","betting","datacard","rechargecard","electric","cable","exam","bulk-sms","finance-hub","gift-card","identity-verification","esim","virtual-card","intl-transfer","payment-gateway");
        if (!in_array($market_cat, $market_cat_allowed, true)) $market_cat = "";
        $cat_statement = ($market_cat !== "") ? " && api_type='".mysqli_real_escape_string($connection_server, $market_cat)."'" : "";
        $cat_parameter = ($market_cat !== "") ? "cat=".$market_cat."&&" : "";

        if(isset($_GET["searchq"]) && !empty(mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"]))))){
                $search_statement = " && (api_website LIKE '%".mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])))."%' OR api_type LIKE '%".mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])))."%' OR description LIKE '%".mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])))."%' OR price LIKE '%".mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])))."%')";
                $search_parameter = "searchq=".mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["searchq"])))."&&";
            }else{
                $search_statement = "";
                $search_parameter = "";
            }
            $get_active_user_details = mysqli_query($connection_server, "SELECT * FROM sas_api_marketplace_listings WHERE $status_statement $search_statement $cat_statement ORDER BY date DESC LIMIT 20 $offset_statement");
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
                            <input name="searchq" type="text" value="<?php echo htmlspecialchars((string)($_GET["searchq"] ?? ""), ENT_QUOTES, "UTF-8"); ?>" placeholder="Search by Product Name, API Website, Type..." class="form-control border-start-0" />
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Search</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
        // §28: category filter chips. eSIM included; the Custom Gateway card below is SAAS-only.
        $market_chips = array("" => "All APIs", "airtime" => "Airtime", "shared-data" => "Data", "electric" => "Electricity", "cable" => "Cable TV", "exam" => "Exam Pins", "bulk-sms" => "Bulk SMS", "identity-verification" => "Identity", "esim" => "eSIM", "virtual-card" => "Virtual Card", "intl-transfer" => "International Transfer", "payment-gateway" => "Payment Gateway");
        ?>
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body py-3 px-4">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($market_chips as $chip_value => $chip_label): ?>
                    <a href="MarketPlace.php<?php echo $chip_value === "" ? "" : ("?cat=" . $chip_value); ?>" class="btn btn-sm rounded-pill px-3 fw-bold<?php echo $market_cat === $chip_value ? " btn-primary" : " btn-light border"; ?>"><?php echo $chip_label; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        // §28: SAAS-only Custom Gateway API listing. NON-SAAS installs get the
        // gateway APIs by default, so the paid listing is not offered there.
        if (!function_exists("bc_is_saas_edition") && is_file(__DIR__ . "/../func/bc-edition.php")) include_once(__DIR__ . "/../func/bc-edition.php");
        if (function_exists("bc_is_saas_edition") ? bc_is_saas_edition() : false) {
            if (!function_exists("bc_fintech_api_providers") && is_file(__DIR__ . "/../func/bc-fintech-api-access.php")) include_once(__DIR__ . "/../func/bc-fintech-api-access.php");
            if (function_exists("bc_fintech_api_providers")) foreach (bc_fintech_api_providers() as $mkt_slug => $mkt_provider):
                if (bc_fintech_api_access((int)($get_logged_admin_details["id"] ?? 0), $mkt_slug)) continue;
                $mkt_price = (float)bc_fintech_api_price($mkt_slug);
        ?>
        <div class="card shadow-sm border-0 rounded-4 mb-4" style="background: linear-gradient(120deg, #0f172a, #1e293b); color:#fff;">
            <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-4 d-flex align-items-center justify-content-center" style="width:56px;height:56px;background:rgba(255,255,255,.12);"><i class="bi bi-plug fs-3"></i></div>
                    <div>
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars((string)$mkt_provider["label"]); ?> API Access</h5>
                        <p class="mb-0 small text-white-50">Custom gateway API for payments, cards & transfers. <?php echo $mkt_price > 0 ? ("One-time activation: ₦" . number_format($mkt_price, 2)) : "Free activation."; ?></p>
                    </div>
                </div>
                <a href="PaymentGateway.php#fintech-api-access" class="btn btn-light rounded-pill px-4 fw-bold flex-shrink-0"><i class="bi bi-cart-plus me-2"></i>Get Access</a>
            </div>
        </div>
        <?php endforeach; } ?>

        <style>
            .product-card { transition: all 0.3s cubic-bezier(.25,.8,.25,1); border: 1px solid #edf2f7; }
            .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1) !important; }
            .hover-lift:active { transform: scale(0.98); }
            .module-icon { width: 60px; height: 60px; background: color-mix(in srgb, var(--brand, #0f766e) 12%, transparent); color: var(--brand-ink, var(--brand, #0f766e)); display: flex; align-items: center; justify-content: center; border-radius: 15px; margin-bottom: 20px; }
            .price-tag { font-size: 1.25rem; font-weight: 800; color: #1a202c; }
        </style>

        <div class="row g-4 mb-5">
            <?php
            if(mysqli_num_rows($get_active_user_details) >= 1){
                while($user_details = mysqli_fetch_assoc($get_active_user_details)){
                    if (bc_retired_vtu_provider($user_details['api_website'] ?? '')) continue;
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
                    <a href="MarketPlace.php?<?php echo $search_parameter . $cat_parameter; ?>page=<?php echo ($_GET["page"] - 1); ?>" class="btn btn-outline-primary btn-sm rounded-pill px-4">Previous</a>
                <?php endif; ?>
                <a href="MarketPlace.php?<?php echo $search_parameter . $cat_parameter; ?>page=<?php echo (isset($_GET["page"]) ? $_GET["page"] + 1 : 2); ?>" class="btn btn-primary btn-sm rounded-pill px-4">Next Page</a>
            </div>
        </div>
      </div>
    </section>

        
    
    


<?php bc_adm_foot(array("area" => "admin")); ?>
