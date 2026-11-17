<?php
// Include the header
include 'includes/header.php';

$product_ids = [];
if(isset($_GET['ids'])){
    $product_ids = explode(',', $_GET['ids']);
    // Sanitize to ensure they are all integers
    $product_ids = array_map('intval', $product_ids);
    $product_ids = array_filter($product_ids, function($id) { return $id > 0; });
}

$products = [];
if(!empty($product_ids)){
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $sql = "SELECT * FROM products WHERE id IN ($placeholders)";
    if($stmt = $mysqli->prepare($sql)){
        $types = str_repeat('i', count($product_ids));
        $stmt->bind_param($types, ...$product_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()){
            $products[$row['id']] = $row; // Use ID as key to maintain order
        }
        $stmt->close();
    }
}
// Reorder products based on the original ID order
$ordered_products = [];
foreach($product_ids as $id){
    if(isset($products[$id])){
        $ordered_products[] = $products[$id];
    }
}
?>

<div class="container mt-5">
    <h2>Compare Products</h2>
    <hr>
    <?php if(!empty($ordered_products)): ?>
        <div class="table-responsive">
            <table class="table table-bordered compare-table">
                <tbody>
                    <tr>
                        <th scope="row">Image</th>
                        <?php foreach($ordered_products as $product): ?>
                            <td class="text-center">
                                <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" style="max-height: 150px;">
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Name</th>
                        <?php foreach($ordered_products as $product): ?>
                            <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Price</th>
                        <?php foreach($ordered_products as $product): ?>
                            <td><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo htmlspecialchars($product['price']); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Description</th>
                        <?php foreach($ordered_products as $product): ?>
                            <td><?php echo substr(htmlspecialchars($product['description']), 0, 200); ?>...</td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row">Action</th>
                        <?php foreach($ordered_products as $product): ?>
                            <td class="text-center">
                                <a href="#" class="btn btn-primary ajax-add-to-cart" data-product-id="<?php echo $product['id']; ?>">Add to Cart</a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            You have not selected any products to compare. Click the <i class="fas fa-exchange-alt"></i> icon on products to add them to the comparison list.
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // If the page was loaded without IDs, get them from session storage and reload.
    if (!new URLSearchParams(window.location.search).has('ids')) {
        const compareItems = JSON.parse(sessionStorage.getItem('compareItems')) || [];
        if (compareItems.length > 0) {
            window.location.href = 'compare.php?ids=' + compareItems.join(',');
        }
    }
});
</script>

<?php
// Include the footer
include 'includes/footer.php';
?>
