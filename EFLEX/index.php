<?php
// Include the database connection first
require_once 'includes/db_connect.php';

// Include the header
include 'includes/header.php';

// Check for the admin creation flash message
if(isset($_SESSION['admin_created']) && $_SESSION['admin_created'] === true){
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Admin Account Created!</strong> A default admin account has been created.
            Username: <strong>admin</strong>, Password: <strong>password</strong>. Please change the password immediately.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['admin_created']);
}

// Fetch site settings
$settings_sql = "SELECT setting_key, setting_value FROM settings";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
$how_it_works_bg_color = $settings['how_it_works_bg_color'] ?? '#f8f9fa';

// Fetch hero slides
$hero_slides_sql = "SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order ASC";
$hero_slides_result = $mysqli->query($hero_slides_sql);
$hero_slides = $hero_slides_result->fetch_all(MYSQLI_ASSOC);


// Fetch categories for the slider
$slider_categories_sql = "SELECT * FROM categories WHERE image IS NOT NULL ORDER BY name ASC";
$slider_categories_result = $mysqli->query($slider_categories_sql);
$slider_categories = $slider_categories_result->fetch_all(MYSQLI_ASSOC);

// Fetch active banners for the slider
$banners_sql = "SELECT * FROM banners WHERE is_active = 1";
$banners_result = $mysqli->query($banners_sql);
$banners = $banners_result->fetch_all(MYSQLI_ASSOC);

// Fetch products for the tabs
// New Products
$new_products_sql = "SELECT * FROM products ORDER BY created_at DESC LIMIT 8";
$new_products_result = $mysqli->query($new_products_sql);
$new_products = $new_products_result->fetch_all(MYSQLI_ASSOC);

// Featured Products
$featured_products_sql = "SELECT * FROM products WHERE is_featured = 1 ORDER BY created_at DESC LIMIT 8";
$featured_products_result = $mysqli->query($featured_products_sql);
$featured_products = $featured_products_result->fetch_all(MYSQLI_ASSOC);

// Top Sellers
$top_sellers_sql = "SELECT * FROM products WHERE is_top_seller = 1 ORDER BY created_at DESC LIMIT 8";
$top_sellers_result = $mysqli->query($top_sellers_sql);
$top_sellers = $top_sellers_result->fetch_all(MYSQLI_ASSOC);

?>

<!-- Hero Section -->
<div id="heroCarousel" class="carousel slide hero-section" data-bs-ride="carousel">
    <div class="carousel-inner">
        <?php foreach($hero_slides as $index => $slide): ?>
            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                <?php if($slide['type'] === 'video'):
                    // Convert YouTube watch URL to embed URL
                    $video_url = str_replace("watch?v=", "embed/", $slide['content_url']);
                    $video_url .= "?autoplay=1&mute=1&loop=1&controls=0&playlist=" . basename($video_url);
                ?>
                    <div class="video-background-wrapper">
                        <iframe src="<?php echo htmlspecialchars($video_url); ?>" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>
                    </div>
                <?php else: ?>
                    <div class="image-background" style="background-image: url('<?php echo htmlspecialchars($slide['content_url']); ?>');"></div>
                <?php endif; ?>

                <?php
                    // Convert hex color to RGB
                    list($r, $g, $b) = sscanf($slide['overlay_color'], "#%02x%02x%02x");
                    $rgba_color = "rgba($r, $g, $b, " . $slide['overlay_opacity'] . ")";
                ?>
                <div class="container d-flex h-100 align-items-center justify-content-center">
                    <div class="carousel-caption-overlay" style="background-color: <?php echo $rgba_color; ?>;">
                        <h1><?php echo htmlspecialchars($slide['title']); ?></h1>
                        <p class="lead"><?php echo htmlspecialchars($slide['description']); ?></p>
                        <a class="btn btn-primary btn-lg" href="products.php" role="button">Shop Now</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if(count($hero_slides) > 1): ?>
    <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Previous</span>
    </button>
    <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Next</span>
    </button>
    <?php endif; ?>
</div>

<!-- Top Categories Section -->
<?php if(count($slider_categories) > 0): ?>
<div class="container my-5">
    <h2 class="text-center mb-4">Top Categories</h2>
    <div class="category-slider">
        <div class="slider-track">
            <?php foreach($slider_categories as $category): ?>
            <div class="category-slide">
                <a href="category.php?id=<?php echo $category['id']; ?>">
                    <img src="uploads/<?php echo htmlspecialchars($category['image']); ?>" alt="<?php echo htmlspecialchars($category['name']); ?>">
                    <span><?php echo htmlspecialchars($category['name']); ?></span>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Special Offer & Product Tabs Section -->
<div class="container my-5">
    <div class="row">
        <!-- Side 1: Special Offer Banner -->
        <div class="col-lg-4 mb-4 mb-lg-0">
            <div class="special-offer-banner h-100">
                <?php if(count($banners) > 0): ?>
                <div id="bannerCarousel" class="carousel slide h-100" data-bs-ride="carousel">
                    <div class="carousel-inner h-100">
                        <?php foreach($banners as $index => $banner): ?>
                        <div class="carousel-item h-100 <?php echo $index === 0 ? 'active' : ''; ?>">
                            <a href="<?php echo htmlspecialchars($banner['link_url']); ?>" target="_blank">
                                <img src="uploads/<?php echo htmlspecialchars($banner['image_url']); ?>" class="d-block w-100 h-100" alt="Special Offer">
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if(count($banners) > 1): ?>
                    <button class="carousel-control-prev" type="button" data-bs-target="#bannerCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#bannerCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center h-100 bg-light">
                        <p>Special Offers Coming Soon!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Side 2: Product Tabs -->
        <div class="col-lg-8">
            <div class="product-tabs">
                <ul class="nav nav-tabs justify-content-center" id="productTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="new-tab" data-bs-toggle="tab" data-bs-target="#new" type="button" role="tab" aria-controls="new" aria-selected="true">New Arrivals</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="featured-tab" data-bs-toggle="tab" data-bs-target="#featured" type="button" role="tab" aria-controls="featured" aria-selected="false">Featured</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="topsellers-tab" data-bs-toggle="tab" data-bs-target="#topsellers" type="button" role="tab" aria-controls="topsellers" aria-selected="false">Top Sellers</button>
                    </li>
                </ul>
                <div class="tab-content mt-4" id="productTabContent">
                    <!-- New Arrivals Pane -->
                    <div class="tab-pane fade show active" id="new" role="tabpanel" aria-labelledby="new-tab">
                        <div class="row">
                            <?php foreach($new_products as $product): ?>
                                <div class="col-md-4 col-lg-3 mb-4">
                                    <?php include 'includes/product_card.php'; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Featured Pane -->
                    <div class="tab-pane fade" id="featured" role="tabpanel" aria-labelledby="featured-tab">
                        <div class="row">
                            <?php foreach($featured_products as $product): ?>
                                <div class="col-md-4 col-lg-3 mb-4">
                                    <?php include 'includes/product_card.php'; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Top Sellers Pane -->
                    <div class="tab-pane fade" id="topsellers" role="tabpanel" aria-labelledby="topsellers-tab">
                        <div class="row">
                             <?php foreach($top_sellers as $product): ?>
                                <div class="col-md-4 col-lg-3 mb-4">
                                   <?php include 'includes/product_card.php'; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- This is the old featured section. The user wants this removed and replaced by a slider. -->
<!-- For now, I will comment it out. It will be removed completely when the slider is built. -->
<!--
<h2>Featured Products</h2>
<div class="row">
    ...
</div>
-->

<!-- Featured Products Section -->
<div class="container my-5">
    <h2 class="text-center mb-4">Featured Products</h2>
    <div class="row">
        <?php foreach(array_slice($featured_products, 0, 4) as $product): ?>
            <div class="col-md-4 col-lg-3 mb-4">
                <?php include 'includes/product_card.php'; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Top Sellers Section -->
<div class="container my-5">
    <h2 class="text-center mb-4">Top Sellers</h2>
     <div class="row">
        <?php foreach(array_slice($top_sellers, 0, 4) as $product): ?>
            <div class="col-md-4 col-lg-3 mb-4">
                <?php include 'includes/product_card.php'; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- How It Works Section -->
<div class="how-it-works-section py-5" style="background-color: <?php echo htmlspecialchars($how_it_works_bg_color); ?>;">
<div class="container">
    <h2 class="text-center mb-4">How It Works</h2>
    <div class="row text-center">
        <div class="col-md-3">
            <div class="how-it-works-step">
                <div class="step-icon"><i class="fas fa-search"></i></div>
                <h5>1. Browse Products</h5>
                <p>Search and Click through Products</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="how-it-works-step">
                <div class="step-icon"><i class="fas fa-shopping-cart"></i></div>
                <h5>2. Add to Cart</h5>
                <p>Click on ADD TO CART to Purchase</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="how-it-works-step">
                <div class="step-icon"><i class="fas fa-credit-card"></i></div>
                <h5>3. Payments</h5>
                <p>Checkout to make Payments using any of our payment Options.</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="how-it-works-step">
                <div class="step-icon"><i class="fas fa-shipping-fast"></i></div>
                <h5>4. Shipping & Delivery</h5>
                <p>Delivery within Lagos takes 24-72hrs. Outside Lagos takes 3-5 working days.</p>
            </div>
        </div>
    </div>
</div>
</div>


<?php
// Include the footer
include 'includes/footer.php';
?>
