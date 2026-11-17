<?php
// We need to start the session on all admin pages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Basic login check. Specific permissions are checked on each page.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Corrected the redirection to point to the admin login page.
    header("location: " . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/index.php");
    exit;
}

// Include database and auth check
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/auth_check.php';


// Fetch all site settings
$settings_sql = "SELECT setting_key, setting_value FROM settings";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
$site_name = $settings['site_name'] ?? 'Eflex';
$oneSignalAppId = $settings['onesignal_app_id'] ?? '';

// Set currency from settings into session for consistent use across the admin panel
$_SESSION['currency_symbol'] = $settings['currency_symbol'] ?? '$';
$_SESSION['currency_code'] = $settings['currency_code'] ?? 'USD';

// Determine the active page to highlight the nav link
$active_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?php echo htmlspecialchars($site_name); ?></title>
    <!-- Bootstrap CSS -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Custom Admin CSS -->
    <link href="css/admin_style.css" rel="stylesheet">
</head>
<body>

<div class="admin-wrapper">
    <div class="sidebar-overlay"></div>
    <!-- Sidebar -->
    <nav class="admin-sidebar">
        <div class="sidebar-header">
            <h3><a href="dashboard.php" class="text-white text-decoration-none"><?php echo htmlspecialchars($site_name); ?> Admin</a></h3>
        </div>

        <ul class="list-unstyled components">
            <li class="<?php echo ($active_page == 'dashboard.php') ? 'active' : ''; ?>">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </li>

            <?php if(has_permission('manage_products') || has_permission('manage_categories')): ?>
            <li>
                <a href="#productSubmenu" data-bs-toggle="collapse" aria-expanded="false" class="dropdown-toggle"><i class="fas fa-box"></i> Products</a>
                <ul class="collapse list-unstyled" id="productSubmenu">
                    <?php if(has_permission('manage_products')): ?><li class="<?php echo ($active_page == 'manage_products.php' || $active_page == 'add_product.php' || $active_page == 'edit_product.php') ? 'active' : ''; ?>">
                        <a href="manage_products.php">All Products</a>
                    </li><?php endif; ?>
                    <?php if(has_permission('manage_categories')): ?><li class="<?php echo ($active_page == 'manage_categories.php') ? 'active' : ''; ?>">
                        <a href="manage_categories.php">Categories</a>
                    </li><?php endif; ?>
                     <?php if(has_permission('manage_products')): ?><li class="<?php echo ($active_page == 'manage_attributes.php') ? 'active' : ''; ?>">
                        <a href="manage_attributes.php">Attributes</a>
                    </li><?php endif; ?>
                </ul>
            </li>
            <?php endif; ?>
            <?php if(has_permission('manage_banners')): ?><li class="<?php echo ($active_page == 'manage_banners.php') ? 'active' : ''; ?>">
                <a href="manage_banners.php"><i class="fas fa-images"></i> Banners</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_hero_slider')): ?><li class="<?php echo ($active_page == 'manage_hero.php') ? 'active' : ''; ?>">
                <a href="manage_hero.php"><i class="fas fa-film"></i> Hero Slider</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_modal_ads')): ?><li class="<?php echo ($active_page == 'manage_modal_ads.php') ? 'active' : ''; ?>">
                <a href="manage_modal_ads.php"><i class="fas fa-window-maximize"></i> Modal Ads</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_pages')): ?><li class="<?php echo ($active_page == 'manage_pages.php' || $active_page == 'edit_page.php') ? 'active' : ''; ?>">
                <a href="manage_pages.php"><i class="fas fa-file-alt"></i> Custom Pages</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_orders')): ?><li class="<?php echo ($active_page == 'manage_orders.php' || $active_page == 'order_detail.php') ? 'active' : ''; ?>">
                <a href="manage_orders.php"><i class="fas fa-shopping-cart"></i> Orders</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_roles')): ?><li class="<?php echo ($active_page == 'manage_staff.php') ? 'active' : ''; ?>">
                <a href="manage_staff.php"><i class="fas fa-user-tie"></i> Staff</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_roles')): ?><li class="<?php echo ($active_page == 'manage_roles.php') ? 'active' : ''; ?>">
                <a href="manage_roles.php"><i class="fas fa-user-shield"></i> Roles & Permissions</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_users')): ?><li class="<?php echo ($active_page == 'manage_users.php' || $active_page == 'edit_user.php') ? 'active' : ''; ?>">
                <a href="manage_users.php"><i class="fas fa-users"></i> Customers</a>
            </li><?php endif; ?>
            <?php if(has_permission('manage_site_settings')): ?><li class="<?php echo ($active_page == 'site_settings.php') ? 'active' : ''; ?>">
                <a href="site_settings.php"><i class="fas fa-cog"></i> Site Settings</a>
            </li><?php endif; ?>
        </ul>
        <ul class="list-unstyled CTAs">
             <li><a href="../index.php" class="btn btn-info w-100 text-white" target="_blank">View Live Site</a></li>
        </ul>
    </nav>

    <!-- Page Content -->
    <div class="admin-content">
        <nav class="navbar navbar-expand-lg navbar-light bg-light">
            <div class="container-fluid">
                <button type="button" id="sidebarCollapse" class="btn btn-info">
                    <i class="fas fa-align-left"></i>
                </button>
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="#"><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?></a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
        <div class="container-fluid">