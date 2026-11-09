<?php
// Include the database connection first
require_once 'includes/db_connect.php';
require_once 'includes/helpers.php';
// Include the header
include 'includes/header.php';

// Pagination variables
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$records_per_page = 8; // Show 8 products per page
$offset = ($page - 1) * $records_per_page;

// --- Filter Logic ---
$category_filter = isset($_GET['category']) && is_numeric($_GET['category']) ? (int)$_GET['category'] : null;
$min_price_filter = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$max_price_filter = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;

$where_conditions = [];
$params = [];
$types = '';

if($category_filter) {
    $where_conditions[] = 'category_id = ?';
    $params[] = $category_filter;
    $types .= 'i';
}
if($min_price_filter !== null) {
    $where_conditions[] = 'price >= ?';
    $params[] = $min_price_filter;
    $types .= 'd';
}
if($max_price_filter !== null) {
    $where_conditions[] = 'price <= ?';
    $params[] = $max_price_filter;
    $types .= 'd';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get total number of products (with filter)
$total_records_sql = "SELECT COUNT(*) FROM products " . $where_clause;
$stmt_total = $mysqli->prepare($total_records_sql);
if ($types !== '') { // More reliable check for parameters
    $stmt_total->bind_param($types, ...$params);
}
$stmt_total->execute();
$total_records_result = $stmt_total->get_result();
$total_records = $total_records_result ? $total_records_result->fetch_row()[0] : 0;
$total_pages = ceil($total_records / $records_per_page);
$stmt_total->close();


// Fetch products for the current page (with filter)
$sql = "SELECT * FROM products " . $where_clause . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $products = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $products = [];
}

// Fetch categories for the filter sidebar
$sql_cat = "SELECT c.id, c.name, COUNT(p.id) as product_count FROM categories c LEFT JOIN products p ON c.id = p.category_id GROUP BY c.id ORDER BY c.name ASC";
$result_cat = $mysqli->query($sql_cat);
$filter_categories = $result_cat->fetch_all(MYSQLI_ASSOC);

// The "All Products" badge must show the *unfiltered* total; $total_records is the
// filtered count, so it contradicted the per-category counts next to it.
$all_products_result = $mysqli->query("SELECT COUNT(*) FROM products");
$all_products_count = $all_products_result ? (int)$all_products_result->fetch_row()[0] : 0;

?>

<div class="row">
    <!-- Sidebar for Filters -->
    <div class="col-lg-3">
        <div class="filter-sidebar">
            <h4>Filters</h4>
            <hr>
            <!-- Category filter will go here -->
            <h6>Categories</h6>
            <ul class="list-group list-group-flush">
                <li class="list-group-item <?php echo !$category_filter ? 'active' : ''; ?>"><a href="products.php" class="text-decoration-none d-flex justify-content-between align-items-center">All Products <span class="badge bg-primary rounded-pill"><?php echo $all_products_count; ?></span></a></li>
                <?php foreach($filter_categories as $category): ?>
                    <li class="list-group-item <?php echo ($category_filter == $category['id']) ? 'active' : ''; ?>"><a href="products.php?category=<?php echo $category['id']; ?>" class="text-decoration-none d-flex justify-content-between align-items-center"><?php echo htmlspecialchars($category['name']); ?> <span class="badge bg-primary rounded-pill"><?php echo $category['product_count']; ?></span></a></li>
                <?php endforeach; ?>
            </ul>
            <hr>
            <!-- Price filter will go here -->
            <h6>Price Range</h6>
            <form action="products.php" method="get" id="price-filter-form">
                <?php if($category_filter): ?>
                    <input type="hidden" name="category" value="<?php echo $category_filter; ?>">
                <?php endif; ?>
                <div id="price-slider" class="my-4"></div>
                <div class="d-flex justify-content-between">
                    <input type="number" name="min_price" id="min-price-input" class="form-control me-2">
                    <input type="number" name="max_price" id="max-price-input" class="form-control ms-2">
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-3">Filter</button>
            </form>
        </div>
    </div>

    <!-- Product Grid -->
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>All Products</h2>
        </div>

        <div id="product-grid-container">
            <div class="row">
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $product): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <?php include 'includes/product_card.php'; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col">
                        <p>No products have been added yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <div id="pagination-container">
                <?php
                    $query_params = [];
                    if ($category_filter) $query_params['category'] = $category_filter;
                    if ($min_price_filter !== null) $query_params['min_price'] = $min_price_filter;
                    if ($max_price_filter !== null) $query_params['max_price'] = $max_price_filter;
                    $query_string = http_build_query($query_params);
                    if (!empty($query_string)) $query_string .= '&';

                    render_pagination('products.php', $total_pages, $page, $query_string);
                ?>
            </div>
</div>

<?php
// Get min and max price for the slider range
$price_range_result = $mysqli->query("SELECT MIN(price) as min, MAX(price) as max FROM products");
$price_range = $price_range_result->fetch_assoc();
$min_price_limit = $price_range['min'] ?? 0;
$max_price_limit = $price_range['max'] ?? 1000;
?>
<?php
// Include the footer
include 'includes/footer.php';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const priceSlider = document.getElementById('price-slider');
    const minPriceInput = document.getElementById('min-price-input');
    const maxPriceInput = document.getElementById('max-price-input');

    if (priceSlider) {
        noUiSlider.create(priceSlider, {
            // Use the already-validated filter values. Echoing raw $_GET here broke the
            // whole slider initialisation (e.g. ?min_price=abc -> "abc is not defined").
            start: [<?php echo $min_price_filter ?? $min_price_limit; ?>, <?php echo $max_price_filter ?? $max_price_limit; ?>],
            connect: true,
            range: {
                'min': <?php echo $min_price_limit; ?>,
                'max': <?php echo $max_price_limit; ?>
            },
            step: 1,
            format: {
                to: function (value) {
                    return Math.round(value);
                },
                from: function (value) {
                    return Number(value);
                }
            }
        });

        priceSlider.noUiSlider.on('update', function (values, handle) {
            if (handle === 0) {
                minPriceInput.value = values[0];
            } else {
                maxPriceInput.value = values[1];
            }
        });

        minPriceInput.addEventListener('change', function () {
            priceSlider.noUiSlider.set([this.value, null]);
        });

        maxPriceInput.addEventListener('change', function () {
            priceSlider.noUiSlider.set([null, this.value]);
        });
    }

    // --- AJAX Filtering Logic ---
    const filterSidebar = document.querySelector('.filter-sidebar');
    const productGridContainer = document.getElementById('product-grid-container');
    let currentCategory = '<?php echo $category_filter ?? ""; ?>';
    let currentPage = 1;

    function fetchProducts() {
        const minPrice = minPriceInput.value;
        const maxPrice = maxPriceInput.value;

        const urlParams = new URLSearchParams({
            action: 'filter_products',
            category: currentCategory,
            min_price: minPrice,
            max_price: maxPrice,
            page: currentPage
        });

        // Update browser URL
        const newUrl = `products.php?${urlParams.toString().replace(/&?action=filter_products/g, '')}`;
        history.pushState(null, '', newUrl);

        // Fetch new content
        fetch('ajax_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.fromEntries(urlParams))
        })
        .then(response => response.json())
        .then(data => {
            if(data.status === 'success') {
                const newContent = data.products_html + data.pagination_html;
                productGridContainer.innerHTML = newContent;
            }
        });
    }

    // Event listener for category links
    filterSidebar.addEventListener('click', function(e) {
        if (e.target.tagName === 'A' && e.target.closest('.list-group-item')) {
            e.preventDefault();
            const url = new URL(e.target.href);
            currentCategory = url.searchParams.get('category') || '';
            currentPage = 1;
            fetchProducts();
        }
    });

    // Event listener for price filter form
    document.getElementById('price-filter-form').addEventListener('submit', function(e) {
        e.preventDefault();
        currentPage = 1;
        fetchProducts();
    });

    // Event listener for pagination links (delegated)
    productGridContainer.addEventListener('click', function(e) {
        if (e.target.tagName === 'A' && e.target.closest('.pagination')) {
            e.preventDefault();
            const url = new URL(e.target.href);
            currentPage = url.searchParams.get('page') || 1;
            fetchProducts();
        }
    });
});
</script>
