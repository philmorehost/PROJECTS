<?php
// Include header and database connection
include 'includes/header.php';
require_once 'includes/db_connect.php';

// Get search query
$query = isset($_GET['query']) ? $_GET['query'] : '';
$safe_query = htmlspecialchars($query);
$search_results = [];

if(!empty($query)){
    // Prepare the search term for a LIKE query
    $search_term = "%" . $query . "%";

    // SQL to search in product name and description
    $sql = "SELECT * FROM products WHERE name LIKE ? OR description LIKE ?";

    if($stmt = $mysqli->prepare($sql)){
        $stmt->bind_param("ss", $search_term, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        $search_results = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>

<div class="container mt-5">
    <h2>Search Results for: "<?php echo $safe_query; ?>"</h2>
    <hr>

    <div class="row">
        <?php if (count($search_results) > 0): ?>
            <?php foreach ($search_results as $product): ?>
                <div class="col-md-4 col-lg-3 mb-4">
                    <?php include 'includes/product_card.php'; ?>
                </div>
            <?php endforeach; ?>
        <?php elseif(!empty($query)): ?>
            <div class="col">
                <p>No products found matching your search criteria.</p>
            </div>
        <?php else: ?>
             <div class="col">
                <p>Please enter a search term to find products.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>
