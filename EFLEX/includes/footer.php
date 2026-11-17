</div> <!-- /container -->

<footer class="site-footer bg-dark text-white pt-5 pb-4">
    <div class="container text-center text-md-start">
        <div class="row">
            <div class="col-md-3 col-lg-4 col-xl-3 mx-auto mb-4">
                <h6 class="text-uppercase fw-bold"><?php echo htmlspecialchars($settings['site_name'] ?? 'Eflex'); ?></h6>
                <hr class="mb-4 mt-0 d-inline-block mx-auto" style="width: 60px; background-color: var(--woodmart-primary-color); height: 2px"/>
                <p>
                    <?php echo htmlspecialchars($settings['footer_about_us'] ?? 'Your one-stop shop for the best products at the best prices. We are committed to providing quality and value.'); ?>
                </p>
            </div>

            <div class="col-md-2 col-lg-2 col-xl-2 mx-auto mb-4">
                <h6 class="text-uppercase fw-bold">Products</h6>
                <hr class="mb-4 mt-0 d-inline-block mx-auto" style="width: 60px; background-color: #7c4dff; height: 2px"/>
                <p><a href="products.php" class="text-white">All Products</a></p>
                <!-- You can dynamically list categories here if you want -->
            </div>

            <div class="col-md-3 col-lg-2 col-xl-2 mx-auto mb-4">
                <h6 class="text-uppercase fw-bold">Useful links</h6>
                <hr class="mb-4 mt-0 d-inline-block mx-auto" style="width: 60px; background-color: #7c4dff; height: 2px"/>
                <p><a href="account.php" class="text-white">Your Account</a></p>
                <p><a href="my_orders.php" class="text-white">My Orders</a></p>
                <p><a href="#" class="text-white">Shipping Rates</a></p>
                <p><a href="#" class="text-white">Help</a></p>
            </div>

            <div class="col-md-4 col-lg-3 col-xl-3 mx-auto mb-md-0 mb-4">
                <h6 class="text-uppercase fw-bold">Contact</h6>
                <hr class="mb-4 mt-0 d-inline-block mx-auto" style="width: 60px; background-color: var(--woodmart-primary-color); height: 2px"/>
                <p><i class="fas fa-home me-3"></i> <?php echo htmlspecialchars($settings['company_address'] ?? 'New York, NY 10012, US'); ?></p>
                <p><i class="fas fa-envelope me-3"></i> <?php echo htmlspecialchars($settings['from_email'] ?? 'info@example.com'); ?></p>
                <p><i class="fas fa-phone me-3"></i> <?php echo htmlspecialchars($settings['company_phone'] ?? '+ 01 234 567 88'); ?></p>
            </div>
        </div>
    </div>
    <div class="text-center p-3" style="background-color: rgba(0, 0, 0, 0.2);">
        <?php echo $settings['copyright_text'] ?? ('© ' . date("Y") . ' Copyright: <a class="text-white" href="index.php">Eflex.com</a>'); ?>
    </div>
</footer>

<!-- Mobile Sticky Footer Nav -->
<div class="mobile-footer-nav d-lg-none">
    <a href="products.php" class="nav-item active">
        <i class="fas fa-store"></i>
        <span>Shop</span>
    </a>
    <a href="wishlist.php" class="nav-item">
        <i class="fas fa-heart"></i>
        <span>Wishlist</span>
    </a>
    <a href="#" class="nav-item" data-bs-toggle="modal" data-bs-target="#miniCartModal">
        <i class="fas fa-shopping-cart"></i>
        <span>Cart</span>
    </a>
    <a href="account.php" class="nav-item">
        <i class="fas fa-user"></i>
        <span>Account</span>
    </a>
</div>

<!-- Mini Cart Modal -->
<div class="modal fade" id="miniCartModal" tabindex="-1" aria-labelledby="miniCartModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-end">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="miniCartModalLabel">Your Cart</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="mini-cart-body">
        <!-- Cart items will be loaded here by JavaScript -->
        <div class="text-center">
            <p>Your cart is currently empty.</p>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <h5 class="mb-0">Subtotal: <span id="mini-cart-subtotal"><?php echo htmlspecialchars($_SESSION['currency_symbol'] ?? '$'); ?>0.00</span></h5>
        <a href="checkout.php" class="btn btn-primary">Checkout</a>
      </div>
    </div>
  </div>
</div>

<!-- Add to Cart Side-Drawer Modal -->
<div class="modal fade" id="addToCartModal" tabindex="-1" aria-labelledby="addToCartModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-end">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addToCartModalLabel">Select Options</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- View 1: Variant Selection -->
                <div id="variant-selection-view">
                    <div class="text-center mb-3">
                        <img src="" id="modal-product-image" class="img-fluid rounded" alt="Product Image" style="max-height: 150px;">
                    </div>
                    <h5 id="modal-product-name"></h5>
                    <p id="modal-product-price" class="fs-4"></p>
                    <hr>
                    <div id="variant-options-container">
                        <!-- Variant options will be dynamically inserted here -->
                    </div>
                    <div class="d-flex align-items-center mt-3">
                        <label for="modal-quantity" class="form-label me-2">Qty:</label>
                        <input type="number" class="form-control" id="modal-quantity" value="1" min="1" style="width: 70px;">
                    </div>
                     <button id="modal-add-to-cart-btn" class="btn btn-primary w-100 mt-3">Add to Cart</button>
                </div>

                <!-- View 2: Confirmation -->
                <div id="confirmation-view" class="d-none">
                    <div class="text-center">
                        <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                        <h5>Product Added to Cart!</h5>
                    </div>
                    <div class="d-flex align-items-center my-3">
                        <img src="" id="confirm-product-image" class="img-fluid rounded me-3" alt="Product Image" style="width: 60px;">
                        <div>
                            <h6 id="confirm-product-name" class="mb-0"></h6>
                            <small id="confirm-product-variant" class="text-muted"></small>
                        </div>
                    </div>
                    <p>Your cart subtotal is now <strong id="confirm-cart-subtotal"></strong>.</p>
                </div>
            </div>
            <div class="modal-footer">
                 <div id="selection-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
                <div id="confirmation-footer" class="d-none w-100">
                    <div class="d-grid gap-2">
                        <a href="cart.php" class="btn btn-primary">View Cart & Checkout</a>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Continue Shopping</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// --- Modal Ad Logic ---
$active_ad = null;
$current_page = basename($_SERVER['PHP_SELF']);
$now = new DateTime();

$sql_ad = "SELECT * FROM modal_ads WHERE is_active = 1
           AND (start_time IS NULL OR start_time <= NOW())
           AND (end_time IS NULL OR end_time >= NOW())
           ORDER BY created_at DESC LIMIT 1";
$ad_result = $mysqli->query($sql_ad);
if($ad_result->num_rows > 0){
    $ad_candidate = $ad_result->fetch_assoc();
    $display_pages = json_decode($ad_candidate['display_pages'], true);
    if(empty($display_pages) || in_array($current_page, $display_pages)){
        $active_ad = $ad_candidate;
    }
}
?>

<!-- Modal Ad -->
<?php if($active_ad): ?>
<div class="modal fade" id="promoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background: url(uploads/<?php echo htmlspecialchars($active_ad['image_url']); ?>) no-repeat center center; background-size: cover;">
      <div class="modal-body text-center text-white" style="background-color: rgba(0,0,0,0.5); padding: 4rem;">
        <h2 class="modal-title"><?php echo htmlspecialchars($active_ad['title']); ?></h2>
        <div><?php echo $active_ad['content']; ?></div>
        <?php if($active_ad['show_countdown'] && !empty($active_ad['end_time'])): ?>
            <h4 id="countdown-timer" class="mt-3"></h4>
        <?php endif; ?>
         <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" style="position: absolute; top: 10px; right: 10px;"></button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- PWA Install Modal -->
<div class="modal fade" id="installPwaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Install Eflex App</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>For a better experience, install the Eflex web app on your device. It's fast, reliable, and works offline!</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Later</button>
        <button type="button" class="btn btn-primary" id="installPwaBtn">Install</button>
      </div>
    </div>
  </div>
</div>


<!-- WhatsApp Chat Widget -->
<?php if(!empty($settings['whatsapp_enabled']) && !empty($settings['whatsapp_number'])): ?>
<div class="whatsapp-chat-widget">
    <a href="#" data-bs-toggle="modal" data-bs-target="#whatsappModal">
        <i class="fab fa-whatsapp"></i>
    </a>
</div>
<!-- WhatsApp Modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-dark">Chat with us on WhatsApp</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-dark">
        <p>Hi there! How can we help you?</p>
        <textarea id="whatsappMessage" class="form-control" rows="3" placeholder="Type your message..."></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" id="sendWhatsappBtn" class="btn btn-success">Send Message</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>


<!-- Bootstrap JS Bundle with Popper -->
<script src="js/bootstrap.bundle.min.js"></script>
<!-- noUiSlider JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/noUiSlider/15.7.1/nouislider.min.js"></script>
<!-- Custom JS -->
<script src="js/main.js"></script>

<!-- Modal Ad JS -->
<?php if($active_ad): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const promoModal = new bootstrap.Modal(document.getElementById('promoModal'));
    // Show modal only once per session
    if(!sessionStorage.getItem('promoModalShown')) {
        promoModal.show();
        sessionStorage.setItem('promoModalShown', 'true');
    }

    <?php if($active_ad['show_countdown'] && !empty($active_ad['end_time'])): ?>
    const countDownDate = new Date("<?php echo $active_ad['end_time']; ?>").getTime();
    const timerElement = document.getElementById("countdown-timer");

    const x = setInterval(function() {
        const now = new Date().getTime();
        const distance = countDownDate - now;

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        timerElement.innerHTML = days + "d " + hours + "h " + minutes + "m " + seconds + "s ";

        if (distance < 0) {
            clearInterval(x);
            timerElement.innerHTML = "EXPIRED";
        }
    }, 1000);
    <?php endif; ?>
});
</script>
<?php endif; ?>

<!-- PWA Registration -->
<?php if($pwa_enabled): ?>
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
        .then(() => console.log('Service Worker Registered'))
        .catch(error => console.log('Service Worker registration failed:', error));
    }
</script>
<script src="js/install-pwa.js"></script>
<?php endif; ?>

<!-- WhatsApp JS -->
<?php if(!empty($settings['whatsapp_enabled']) && !empty($settings['whatsapp_number'])): ?>
<script>
document.getElementById('sendWhatsappBtn').addEventListener('click', function() {
    const message = document.getElementById('whatsappMessage').value;
    const whatsappNumber = "<?php echo htmlspecialchars($settings['whatsapp_number']); ?>";
    const url = `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(message)}`;
    window.open(url, '_blank');
});
</script>
<?php endif; ?>

</body>
</html>
