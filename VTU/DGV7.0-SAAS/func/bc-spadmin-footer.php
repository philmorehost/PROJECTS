
<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<?php if(isset($_SESSION["product_purchase_response"])): ?>
<script>
  (function() {
    const msg = <?php echo json_encode($_SESSION["product_purchase_response"]); ?>;
    const msgHash = btoa(msg).substring(0, 32);
    const lastMsg = localStorage.getItem('last_swal_msg');
    const lastTime = localStorage.getItem('last_swal_time');
    const now = Date.now();

    // Only show if it's a new message or more than 5 seconds have passed (to allow re-showing same error if intentional)
    if (lastMsg !== msgHash || (now - lastTime > 5000)) {
        Swal.fire({
            title: 'Message!',
            text: msg,
            icon: 'success',
            confirmButtonColor: 'var(--primary-color)'
        });
        localStorage.setItem('last_swal_msg', msgHash);
        localStorage.setItem('last_swal_time', now);
    }
  })();
</script>
<?php unset($_SESSION["product_purchase_response"]); ?>
<?php endif; ?>

<div class="w-100 mh-25 mt-3 d-block d-xl-none d-md-none d-lg-none" style="height: 80px;"></div>
</main>

<div class="bg-white w-100 mh-25 py-2 position-fixed border bottom-0 d-flex flex-row justify-items-center justify-content-between d-block d-xl-none d-md-none d-lg-none" style="height: 80px; z-index: 1000;">
  
  <div class="col-2 d-flex flex-column align-items-center">
      <button class="btn btn-secondary rounded-circle mb-1" page-name="Vendors.php">
        <a href="<?php echo $web_http_host; ?>/bc-spadmin/Vendors.php" class="text-decoration-none">
          <i class="bi bi-people text-white"></i>
        </a>
      </button>
      <span class="text-dark" style="font-size: 10px; font-weight: 600;">Vendors</span>
  </div>

  <div class="col-2 d-flex flex-column align-items-center">
      <button class="btn btn-secondary rounded-circle mb-1" page-name="Dashboard.php">
        <a href="<?php echo $web_http_host; ?>/bc-spadmin/Dashboard.php" class="text-decoration-none">
          <i class="bi bi-grid text-white"></i>
        </a>
      </button>
      <span class="text-dark" style="font-size: 10px; font-weight: 600;">Home</span>
  </div>

  <div class="col-2 d-flex flex-column align-items-center">
      <button class="btn btn-secondary rounded-circle mb-1" page-name="Transactions.php">
        <a href="<?php echo $web_http_host; ?>/bc-spadmin/Transactions.php" class="text-decoration-none">
          <i class="bi bi-wallet text-white"></i>
        </a>
      </button>
      <span class="text-dark" style="font-size: 10px; font-weight: 600;">Trans.</span>
  </div>

  <div class="col-2 d-flex flex-column align-items-center">
      <button class="btn btn-secondary rounded-circle mb-1" page-name="Users.php">
        <a href="<?php echo $web_http_host; ?>/bc-spadmin/Users.php" class="text-decoration-none">
          <i class="bi bi-person text-white"></i>
        </a>
      </button>
      <span class="text-dark" style="font-size: 10px; font-weight: 600;">Users</span>
  </div>

  <div class="col-2 d-flex flex-column align-items-center">
      <button class="btn btn-secondary rounded-circle mb-1" page-name="AccountSettings.php">
        <a href="<?php echo $web_http_host; ?>/bc-spadmin/AccountSettings.php" class="text-decoration-none">
          <i class="bi bi-gear text-white"></i>
        </a>
      </button>
      <span class="text-dark" style="font-size: 10px; font-weight: 600;">Settings</span>
  </div>

</div>


  <!-- Vendor JS Files -->
  <script src="../assets-2/vendor/apexcharts/apexcharts.min.js"></script>
  <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="../assets-2/vendor/chart.js/chart.umd.js"></script>
  <script src="../assets-2/vendor/echarts/echarts.min.js"></script>
  <!-- The repo ships the full Quill build as quill.js; quill.min.js was never committed, so this
       reference 404'd on all 42 super-admin pages that include this footer. -->
  <script src="../assets-2/vendor/quill/quill.js"></script>
  <script src="../assets-2/vendor/simple-datatables/simple-datatables.js"></script>
  <script src="../assets-2/vendor/tinymce/tinymce.min.js"></script>
  <script src="../assets-2/vendor/php-email-form/validate.js"></script>

  <!-- Template Main JS File -->
  <script src="../assets-2/js/main.js"></script>

<script src="/jsfile/bc-custom-all.js"></script>
<script src="/jsfile/bc-custom-vendor.js"></script>
<script src="/jsfile/bc-custom-super.js"></script>

<script>
    function startSpadminGuide() {
        const guideSteps = [
            { element: '.pagetitle', intro: 'This is where you see the current page title.' },
            { element: '.sidebar-nav', intro: 'Use the sidebar to navigate through management modules.' },
            { element: '.header-nav', intro: 'Quick access to notifications and profile settings.' },
            { element: '.sitemap-guide-btn', intro: 'Click here anytime to see the platform guide.' }
        ];
        // Note: Logic to initiate a visual guide would go here (e.g. using intro.js or custom implementation)
        alert('Guide initialized! Explore the management modules on the left to get started.');
    }
</script>