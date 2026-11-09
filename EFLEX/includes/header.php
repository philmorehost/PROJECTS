<?php
// We need to start the session on all pages to access session variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Initial Setup Checks ---
$base_path = __DIR__ . '/../';
$uploads_dir = $base_path . 'uploads';
$pwa_dir = $uploads_dir . '/pwa';
$proofs_dir = $uploads_dir . '/payment_proofs';
if (!is_dir($uploads_dir)) mkdir($uploads_dir, 0777, true);
if (!is_dir($pwa_dir)) mkdir($pwa_dir, 0777, true);
if (!is_dir($proofs_dir)) mkdir($proofs_dir, 0777, true);

// Include and run database setup/migration
require_once 'db_connect.php';

// --- Handle Remember Me ---
require_once 'helpers.php';
check_remember_me($mysqli);

// --- Fetch all site settings ---
$settings_sql = "SELECT setting_key, setting_value FROM settings";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
// Define variables for easier access, with defaults
$site_name = $settings['site_name'] ?? 'Eflex';
$site_logo = $settings['site_logo'] ?? '';
$meta_title = $settings['meta_title'] ?? $site_name;
$meta_description = $settings['meta_description'] ?? 'An e-commerce website.';
$meta_keywords = $settings['meta_keywords'] ?? 'e-commerce, shop, online store';
$pwa_enabled = !empty($settings['pwa_enabled']);
$theme_primary_color = $settings['theme_primary_color'] ?? '#ae8e6a';
$theme_secondary_color = $settings['theme_secondary_color'] ?? '#f2f2f2';
$_SESSION['currency_symbol'] = $settings['currency_symbol'] ?? '$';
$_SESSION['currency_code'] = $settings['currency_code'] ?? 'USD';


// --- Fetch categories for the navigation dropdown ---
$category_sql = "SELECT * FROM categories ORDER BY parent_id, name ASC";
$category_result = $mysqli->query($category_sql);
$all_categories = $category_result->fetch_all(MYSQLI_ASSOC);

function build_nav_category_tree(array &$elements, $parentId = null) {
    $branch = [];
    foreach ($elements as &$element) {
        if ($element['parent_id'] == $parentId) {
            $children = build_nav_category_tree($elements, $element['id']);
            if ($children) {
                $element['children'] = $children;
            }
            $branch[] = $element;
            unset($element);
        }
    }
    return $branch;
}

function display_nav_menu_items($categories) {
    foreach ($categories as $category) {
        echo '<li>';
        echo '<a href="category.php?id=' . $category['id'] . '">' . htmlspecialchars($category['name']) . '</a>';
        if (!empty($category['children'])) {
            echo '<ul class="dropdown-menu-custom-sub">';
            display_nav_menu_items($category['children']);
            echo '</ul>';
        }
        echo '</li>';
    }
}

function display_mobile_nav_menu_items($categories, $level = 0) {
    foreach ($categories as $category) {
        $has_children = !empty($category['children']);
        $padding_left = 15 * $level;

        echo '<li>';
        echo '<a href="' . ($has_children ? '#' : 'category.php?id=' . $category['id']) . '" ';
        if ($has_children) {
            echo 'data-bs-toggle="collapse" data-bs-target="#mobile-cat-' . $category['id'] . '" role="button" aria-expanded="false" aria-controls="mobile-cat-' . $category['id'] . '"';
        }
        echo ' style="padding-left: ' . $padding_left . 'px;">';
        echo htmlspecialchars($category['name']);
        if ($has_children) {
            echo ' <i class="fas fa-chevron-down float-end"></i>';
        }
        echo '</a>';

        if ($has_children) {
            echo '<ul class="collapse" id="mobile-cat-' . $category['id'] . '">';
            display_mobile_nav_menu_items($category['children'], $level + 1);
            echo '</ul>';
        }
        echo '</li>';
    }
}

$nav_category_tree = build_nav_category_tree($all_categories);


// --- Fetch published pages for the navigation ---
$pages_sql = "SELECT title, slug FROM pages WHERE is_published = 1 ORDER BY title ASC";
$pages_result = $mysqli->query($pages_sql);
$nav_pages = $pages_result->fetch_all(MYSQLI_ASSOC);

// --- Calculate cart item count ---
$cart_item_count = 0;
if(isset($_SESSION['cart']) && is_array($_SESSION['cart'])){
    $cart_item_count = array_sum($_SESSION['cart']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo $settings['language'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($meta_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($meta_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($meta_keywords); ?>">

    <?php if($pwa_enabled): ?>
    <link rel="manifest" href="/manifest.php">
    <meta name="theme-color" content="<?php echo htmlspecialchars($settings['pwa_theme_color'] ?? '#ffffff'); ?>">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').then(registration => {
                    console.log('ServiceWorker registration successful with scope: ', registration.scope);
                }).catch(err => {
                    console.log('ServiceWorker registration failed: ', err);
                });
            });
        }
    </script>
    <?php endif; ?>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap" rel="stylesheet">
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <!-- noUiSlider CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.css" />
    <!-- Custom CSS -->
    <link href="css/custom_style.css" rel="stylesheet">

    <!-- Dynamic Theme Colors -->
    <style>
        :root {
            --woodmart-primary-color: <?php echo htmlspecialchars($theme_primary_color); ?>;
            --woodmart-light-gray: <?php echo htmlspecialchars($theme_secondary_color); ?>;
        }
    </style>
    <!-- Google Analytics -->
    <?php if(!empty($settings['ga_enabled']) && !empty($settings['ga_tracking_id'])): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($settings['ga_tracking_id']); ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '<?php echo htmlspecialchars($settings['ga_tracking_id']); ?>');
        </script>
    <?php endif; ?>
</head>
<body>

<header class="site-header">
    <div class="header-top-bar bg-dark text-white py-1">
        <div class="container d-flex justify-content-between">
            <div class="header-social-links">
                <?php if(!empty($settings['social_links_enabled'])): ?>
                    <?php if(!empty($settings['social_facebook'])): ?><a href="<?php echo htmlspecialchars($settings['social_facebook']); ?>" class="text-white me-2"><i class="fab fa-facebook-f"></i></a><?php endif; ?>
                    <?php if(!empty($settings['social_twitter'])): ?><a href="<?php echo htmlspecialchars($settings['social_twitter']); ?>" class="text-white me-2"><i class="fab fa-twitter"></i></a><?php endif; ?>
                    <?php if(!empty($settings['social_instagram'])): ?><a href="<?php echo htmlspecialchars($settings['social_instagram']); ?>" class="text-white me-2"><i class="fab fa-instagram"></i></a><?php endif; ?>
                    <?php if(!empty($settings['social_youtube'])): ?><a href="<?php echo htmlspecialchars($settings['social_youtube']); ?>" class="text-white me-2"><i class="fab fa-youtube"></i></a><?php endif; ?>
                    <?php if(!empty($settings['social_threads'])): ?><a href="<?php echo htmlspecialchars($settings['social_threads']); ?>" class="text-white"><i class="fab fa-threads"></i></a><?php endif; ?>
                <?php endif; ?>
            </div>
            <div>
                <!-- Can add other top bar info here -->
            </div>
        </div>
    </div>
    <div class="header-main">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div class="header-logo">
                    <a class="navbar-brand" href="index.php">
                        <?php if(!empty($site_logo)): ?>
                            <img src="/uploads/<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" style="max-height: 50px;">
                        <?php else: ?>
                            <?php echo htmlspecialchars($site_name); ?>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="header-search flex-grow-1 mx-lg-4 d-none d-lg-block">
                    <form class="d-flex position-relative" action="search.php" method="get">
                        <input class="form-control me-2" type="search" name="query" id="header-search-input-desktop" placeholder="Search Products" aria-label="Search" autocomplete="off">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                        <div class="search-suggestions" id="search-suggestions-desktop" style="display: none;"></div>
                    </form>
                </div>
                <div class="header-actions d-none d-lg-flex">
                    <?php if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                        <a href="account.php" class="header-action-btn"><i class="fas fa-user"></i><span><?php echo htmlspecialchars($_SESSION["username"]); ?></span></a>
                    <?php else: ?>
                        <a href="login.php" class="header-action-btn"><i class="fas fa-user"></i><span>Login</span></a>
                    <?php endif; ?>
                    <a href="wishlist.php" class="header-action-btn"><i class="fas fa-heart"></i><span>Wishlist</span></a>
                    <a href="compare.php" class="header-action-btn"><i class="fas fa-exchange-alt"></i><span class="compare-badge">0</span><span>Compare</span></a>
                    <a href="cart.php" class="header-action-btn">
                        <i class="fas fa-shopping-cart"></i>
                        <span class="badge rounded-pill bg-primary cart-badge"><?php echo $cart_item_count; ?></span>
                        <span>Cart</span>
                    </a>
                </div>
                <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavCollapse" aria-controls="mainNavCollapse" aria-expanded="false" aria-label="Toggle navigation">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="header-mobile-search d-lg-none">
        <div class="container">
            <form class="d-flex position-relative" action="search.php" method="get">
                <input class="form-control me-2" type="search" name="query" id="header-search-input-mobile" placeholder="Search Products" aria-label="Search" autocomplete="off">
                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                <div class="search-suggestions" id="search-suggestions-mobile" style="display: none;"></div>
            </form>
        </div>
    </div>
    <!-- Desktop Navigation -->
    <nav class="header-nav d-none d-lg-block">
        <div class="container">
            <ul class="nav-list">
                <li><a href="index.php" class="nav-link">Home</a></li>
                <li><a href="products.php" class="nav-link">Shop</a></li>
                <li class="nav-item-dropdown">
                    <a href="#" class="nav-link">Categories <i class="fas fa-chevron-down"></i></a>
                    <ul class="dropdown-menu-custom">
                        <li><a href="products.php">All Products</a></li>
                        <li class="divider"></li>
                        <?php display_nav_menu_items($nav_category_tree); ?>
                    </ul>
                </li>
                <?php if(count($nav_pages) > 0): ?>
                    <?php foreach ($nav_pages as $nav_page): ?>
                        <li><a href="page.php?slug=<?php echo $nav_page['slug']; ?>" class="nav-link"><?php echo htmlspecialchars($nav_page['title']); ?></a></li>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if(isset($_SESSION["role_id"])): ?>
                    <li><a href="admin/dashboard.php" class="nav-link">Admin Dashboard</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Mobile Collapsed Menu -->
    <div class="collapse navbar-collapse d-lg-none" id="mainNavCollapse">
        <div class="container">
            <div class="mobile-menu-content">
                <ul class="nav-list-mobile">
                    <li><a href="index.php" class="nav-link">Home</a></li>
                    <li><a href="products.php" class="nav-link">Shop</a></li>
                    <?php display_mobile_nav_menu_items($nav_category_tree); ?>
                     <?php if(count($nav_pages) > 0): ?>
                        <?php foreach ($nav_pages as $nav_page): ?>
                            <li><a href="page.php?slug=<?php echo $nav_page['slug']; ?>" class="nav-link"><?php echo htmlspecialchars($nav_page['title']); ?></a></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if(isset($_SESSION["role_id"])): ?>
                        <li><a href="admin/dashboard.php" class="nav-link">Admin Dashboard</a></li>
                    <?php endif; ?>
                </ul>
                <hr>
                <div class="header-actions-mobile">
                <hr>
                <?php if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                    <a href="account.php" class="header-action-btn"><i class="fas fa-user"></i><span><?php echo htmlspecialchars($_SESSION["username"]); ?></span></a>
                    <a href="logout.php" class="header-action-btn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
                <?php else: ?>
                    <a href="login.php" class="header-action-btn"><i class="fas fa-user"></i><span>Login</span></a>
                <?php endif; ?>
                <a href="wishlist.php" class="header-action-btn"><i class="fas fa-heart"></i><span>Wishlist</span></a>
                <a href="compare.php" class="header-action-btn"><i class="fas fa-exchange-alt"></i><span class="compare-badge">0</span><span>Compare</span></a>
                <a href="cart.php" class="header-action-btn">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="badge rounded-pill bg-primary cart-badge"><?php echo $cart_item_count; ?></span>
                    <span>Cart</span>
                </a>
            </div>
        </div>
    </div>
</header>

<div class="container mt-4">
