<div class="card h-100">
    <a href="product_detail.php?id=<?php echo $product['id']; ?>">
        <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($product['name']); ?>" style="height: 200px; object-fit: cover;">
    </a>
    <div class="card-body d-flex flex-column">
        <h5 class="card-title"><a href="product_detail.php?id=<?php echo $product['id']; ?>" class="text-dark text-decoration-none"><?php echo htmlspecialchars($product['name']); ?></a></h5>
        <p class="card-text text-muted"><?php echo htmlspecialchars($_SESSION['currency_symbol'] ?? '$'); ?><?php echo htmlspecialchars($product['price']); ?></p>
        <div class="mt-auto d-flex justify-content-between align-items-center">
            <?php if ($product['has_variants']): ?>
                <a href="product_detail.php?id=<?php echo $product['id']; ?>" class="btn btn-secondary">Select Options</a>
            <?php else: ?>
                <form action="cart.php" method="post" class="d-inline">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" name="add_to_cart" class="btn btn-primary">Add to Cart</button>
                </form>
            <?php endif; ?>
             <div>
                <a href="#" class="btn-wishlist" data-product-id="<?php echo $product['id']; ?>"><i class="far fa-heart"></i></a>
                <a href="#" class="btn-compare" data-product-id="<?php echo $product['id']; ?>"><i class="fas fa-exchange-alt"></i></a>
             </div>
        </div>
    </div>
</div>
