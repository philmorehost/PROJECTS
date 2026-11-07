<?php
$select_user_vendor_status_message = mysqli_query($connection_server, "SELECT * FROM sas_vendor_status_messages WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "'");
if (mysqli_num_rows($select_user_vendor_status_message) == 1) {
  $get_user_vendor_status_message = mysqli_fetch_array($select_user_vendor_status_message);
  if (!isset($_SESSION["product_purchase_response"]) && isset($_SESSION["user_session"])) {
    $user_vendor_status_message_template_encoded_text_array = array("{username}" => $get_logged_user_details["username"]);
    foreach ($user_vendor_status_message_template_encoded_text_array as $array_key => $array_val) {
      $user_vendor_status_message_template_text = str_replace($array_key, $array_val, $get_user_vendor_status_message["message"]);
      $user_vendor_status_message_template_date = formDate($get_user_vendor_status_message["date"]);
    }
  }
}
?>
<style>
  :root {
    --primary-color: <?php echo $vendor_primary_color; ?>;
    --sidebar-bg: <?php echo $vendor_primary_color; ?>;
  }
  .bg-primary, .btn-primary, .nav-pills .nav-link.active, .badge.bg-primary, .card-blue {
    background-color: var(--primary-color) !important;
  }
  .btn-primary, .border-primary {
    border-color: var(--primary-color) !important;
  }
  .text-primary {
    color: var(--primary-color) !important;
  }
  .text-dark-primary {
    color: #1a1a1a !important; /* Specific dark color for high-contrast on light-primary backgrounds */
  }
  .btn-outline-primary {
    color: var(--primary-color) !important;
    border-color: var(--primary-color) !important;
  }
  .btn-outline-primary:hover {
    background-color: var(--primary-color) !important;
    color: #fff !important;
  }
  .sidebar-nav .nav-link.active_item {
    color: var(--primary-color) !important;
  }
  .sitemap-guide-btn {
    background-color: var(--primary-color);
    color: #fff !important;
    padding: 8px 16px;
    border-radius: 50px;
    font-size: 12px;
    font-weight: 800;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 10px var(--primary-color);
    animation: sitemap-glow 2s infinite;
    position: relative;
    margin-right: 15px;
    border: 2px solid #fff;
    white-space: nowrap;
  }
  @keyframes sitemap-glow {
    0% { box-shadow: 0 0 5px var(--primary-color); transform: scale(1); }
    50% { box-shadow: 0 0 20px var(--primary-color); transform: scale(1.05); }
    100% { box-shadow: 0 0 5px var(--primary-color); transform: scale(1); }
  }
  .guide-hand {
    position: absolute;
    left: -40px;
    top: -2px;
    font-size: 24px;
    animation: hand-wave-point-right 3s infinite;
    z-index: 1001;
    transform-origin: center;
  }
  @media (max-width: 767px) {
    .sitemap-guide-btn {
      padding: 6px 12px;
      font-size: 10px;
      margin-right: 5px;
    }
    .guide-hand {
      left: -30px;
      font-size: 18px;
    }
  }
  @keyframes hand-wave-point-right {
    0%, 100% { transform: rotate(0deg) translateX(0); }
    10%, 30% { transform: rotate(-20deg) translateX(0); }
    20%, 40% { transform: rotate(0deg) translateX(0); }
    60%, 80% { transform: rotate(0deg) translateX(15px); }
  }
  .badge-new {
    background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
    color: white;
    font-size: 0.65rem;
    padding: 2px 6px;
    border-radius: 50px;
    box-shadow: 0 0 10px rgba(255, 75, 43, 0.5);
    animation: pulse-new 2s infinite;
    font-weight: 800;
  }
  @keyframes pulse-new {
    0% { transform: scale(1); box-shadow: 0 0 5px rgba(255, 75, 43, 0.4); }
    50% { transform: scale(1.1); box-shadow: 0 0 15px rgba(255, 75, 43, 0.8); }
    100% { transform: scale(1); box-shadow: 0 0 5px rgba(255, 75, 43, 0.4); }
  }
  .nav-heading {
    padding: 15px 15px 5px 15px;
    font-size: 11px;
    text-transform: uppercase;
    color: #899bbd;
    font-weight: 700;
  }
</style>
<script>
  window.siteTitle = "<?php echo $get_all_site_details['site_title'] ?? ''; ?>";
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php if(basename($_SERVER['PHP_SELF']) == 'Fund.php'): ?>
<script src="https://merchant.beewave.ng/checkout.min.js" defer></script>
<?php endif; ?>
<script src="/web/js/ai-guides.js" defer></script>
<!-- ======= Header ======= -->
<header id="header" class="header fixed-top d-flex align-items-center">

  <div class="d-flex align-items-center justify-content-between">
    <a href="#" class="logo d-flex align-items-center">
      <span class="d-none d-lg-block"><img
          src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo str_replace(['.', ':'], '-', $_SERVER['HTTP_HOST']) . '_'; ?>logo.png"
          alt=""></span>
    </a>
    <i class="bi bi-list toggle-sidebar-btn"></i>
  </div>


  <nav class="header-nav ms-auto">
    <ul class="d-flex align-items-center">



      <li class="nav-item">
        <a class="sitemap-guide-btn" href="<?php echo $web_http_host; ?>/web/SiteMap.php" title="Platform Guide">
          <span class="guide-hand">👉</span>
          GUIDE
        </a>
      </li><!-- End SiteMap Icon -->

      <li class="nav-item dropdown">

        <a class="nav-link nav-icon" href="#" data-bs-toggle="dropdown">
          <i class="bi bi-bell"></i>
          <span class="badge bg-primary badge-number">1</span>
        </a><!-- End Notification Icon -->

        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow notifications">
          <li class="dropdown-header">
            Latest Notification
            <!-- <a href="#"><span class="badge rounded-pill bg-primary p-2 ms-2">View all</span></a> -->
          </li>
          <li>
            <hr class="dropdown-divider">
          </li>

          <li class="notification-item">
            <i class="bi bi-exclamation-circle text-warning"></i>
            <div>
              <h4>Message from Admin</h4>
              <p><?php echo str_replace("\n", "<br/>", $user_vendor_status_message_template_text); ?></p>
              <p><?php echo str_replace("\n", "<br/>", $user_vendor_status_message_template_date); ?></p>
            </div>
          </li>
        </ul><!-- End Notification Dropdown Items -->

      </li><!-- End Notification Nav -->

      <li class="nav-item d-block d-md-none">
        <a class="nav-link nav-icon" href="#" data-bs-toggle="modal" data-bs-target="#referralModal">
          <i class="bi bi-people"></i>
        </a>
      </li>

      <li class="nav-item dropdown pe-3">

        <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
          <img src="/asset/boy-icon.png" alt="Profile" class="rounded-circle">
          <span class="d-none d-md-block dropdown-toggle ps-2">
            <?php echo $_SESSION["user_session"]; ?>
          </span>
        </a><!-- End Profile Iamge Icon -->

        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
          <li class="dropdown-header">
            <h6><?php echo $_SESSION["user_session"]; ?></h6>

          </li>
          <li>
            <hr class="dropdown-divider">
          </li>

          <li>
            <a class="dropdown-item d-flex align-items-center" href="<?php echo $web_http_host; ?>/web/APIDocs.php">
              <i class="bi bi-person"></i>
              <span>API Documentation</span>
            </a>
          </li>
          <li>
            <hr class="dropdown-divider">
          </li>

          <li>
            <a class="dropdown-item d-flex align-items-center"
              href="<?php echo $web_http_host; ?>/web/AccountSettings.php">
              <i class="bi bi-gear"></i>
              <span>Account Settings</span>
            </a>
          </li>
          <li>
            <hr class="dropdown-divider">
          </li>

          <li>
            <hr class="dropdown-divider">
          </li>

          <li>
            <a class="dropdown-item d-flex align-items-center"
              onclick="javascript:if(confirm('Do you want to logout? ')){window.location.href='/logout.php'}">
              <i class="bi bi-box-arrow-right"></i>
              <span>Log Out</span>
            </a>
          </li>

        </ul><!-- End Profile Dropdown Items -->
      </li><!-- End Profile Nav -->

    </ul>
  </nav><!-- End Icons Navigation -->

</header><!-- End Header -->


<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">

  <ul class="sidebar-nav" id="sidebar-nav">
    <?php
    $current_page = basename($_SERVER['PHP_SELF']);
    if (isset($_SESSION["user_session"]) && isset($get_logged_user_details)) {
    ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'Dashboard.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/Dashboard.php">
          <i class="bi bi-grid"></i>
          <span>Dashboard</span>
        </a>
      </li><!-- End Dashboard Nav -->

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'AISuite.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/AISuite.php">
          <i class="bi bi-cpu-fill"></i>
          <div class="d-flex flex-column">
            <span class="d-flex align-items-center">AI Suite <span class="badge-new ms-2">NEW</span></span>
            <small class="text-muted" style="font-size:9px;line-height:1">Token Wallet & Voice Settings</small>
          </div>
        </a>
      </li><!-- End AI Suite Nav -->

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'AI-Assistant.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/AI-Assistant.php">
          <i class="bi bi-robot"></i>
          <div class="d-flex flex-column">
            <span class="d-flex align-items-center">AI Assistant Terminal</span>
            <small class="text-muted" style="font-size:9px;line-height:1">Dedicated Transaction Interface</small>
          </div>
        </a>
      </li><!-- End AI Assistant Terminal Nav -->

      <li class="nav-heading">Services</li>

      <li class="nav-item">
        <?php $payment_hub_active = in_array($current_page, ['Data.php', 'Airtime.php', 'BulkData.php', 'BulkAirtime.php', 'Cable.php', 'Electric.php', 'Betting.php', 'Card.php', 'Exam.php']); ?>
        <a class="nav-link <?php echo $payment_hub_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#payment-hub-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-lightning-charge"></i><span>Payment Hub</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="payment-hub-nav" class="nav-content collapse <?php echo $payment_hub_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <?php if(isServiceEnabled('data')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Data.php" class="<?php echo ($current_page == 'Data.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Buy Data Bundle</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('airtime')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Airtime.php" class="<?php echo ($current_page == 'Airtime.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Buy Airtime VTU</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('data')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/BulkData.php" class="<?php echo ($current_page == 'BulkData.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Buy Bulk Data Bundle</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('airtime')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/BulkAirtime.php" class="<?php echo ($current_page == 'BulkAirtime.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Buy Bulk Airtime VTU</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('cable')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Cable.php" class="<?php echo ($current_page == 'Cable.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Buy CableTv Sub(s)</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('electric')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Electric.php" class="<?php echo ($current_page == 'Electric.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Buy Electric Token</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('betting')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Betting.php" class="<?php echo ($current_page == 'Betting.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Fund Betting</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('recharge_card')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Card.php" class="<?php echo ($current_page == 'Card.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Card Printing</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('exam')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Exam.php" class="<?php echo ($current_page == 'Exam.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Buy Exam Pin(s)</span>
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </li>

      <?php if(isServiceEnabled('bank_transfer')): ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'SendFund.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/SendFund.php">
          <i class="bi bi-bank"></i>
          <span>Wallet to Bank</span>
        </a>
      </li>
      <?php endif; ?>

      <li class="nav-item">
        <?php $manage_fund_active = in_array($current_page, ['ShareFund.php', 'ShareFundHistory.php', 'CoinConversion.php']); ?>
        <a class="nav-link <?php echo $manage_fund_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#manage-fund-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-wallet2"></i><span>Manage Funds</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="manage-fund-nav" class="nav-content collapse <?php echo $manage_fund_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/web/ShareFund.php" class="<?php echo ($current_page == 'ShareFund.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Share Fund</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/ShareFundHistory.php" class="<?php echo ($current_page == 'ShareFundHistory.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Share Fund History</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/CoinConversion.php" class="<?php echo ($current_page == 'CoinConversion.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Convert Coins</span>
            </a>
          </li>
        </ul>
      </li>

      <?php if(isServiceEnabled('data_card')): ?>
      <li class="nav-item">
        <?php $print_hub_active = in_array($current_page, ['PrintHub.php', 'DataBundleCard.php', 'DataBundleCardHistory.php', 'ViewDataBundleCard.php']); ?>
        <a class="nav-link <?php echo $print_hub_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#print-hub-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-printer"></i><span>Print Hub</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="print-hub-nav" class="nav-content collapse <?php echo $print_hub_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/web/PrintHub.php" class="<?php echo ($current_page == 'PrintHub.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Vending Dashboard</span>
            </a>
          </li>
          <?php if(isServiceEnabled('print_airtime')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/DataBundleCard.php?type=airtime" class="<?php echo ($current_page == 'DataBundleCard.php' && isset($_GET['type']) && $_GET['type'] == 'airtime') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Print Airtime Card</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('print_data')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/DataBundleCard.php?type=data" class="<?php echo ($current_page == 'DataBundleCard.php' && isset($_GET['type']) && ($_GET['type'] == 'data' || !isset($_GET['type']))) ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Print Data Card</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('print_cable')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/DataBundleCard.php?type=cable" class="<?php echo ($current_page == 'DataBundleCard.php' && isset($_GET['type']) && $_GET['type'] == 'cable') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Print Cable Card</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('print_electric')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/DataBundleCard.php?type=electric" class="<?php echo ($current_page == 'DataBundleCard.php' && isset($_GET['type']) && $_GET['type'] == 'electric') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Print Electric Card</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('print_exam')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/DataBundleCard.php?type=exam" class="<?php echo ($current_page == 'DataBundleCard.php' && isset($_GET['type']) && $_GET['type'] == 'exam') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Print Exam Pin</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if(isServiceEnabled('print_betting')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/DataBundleCard.php?type=betting" class="<?php echo ($current_page == 'DataBundleCard.php' && isset($_GET['type']) && $_GET['type'] == 'betting') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Print Betting Card</span>
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>

      <?php if(isServiceEnabled('nin_card')): ?>
      <li class="nav-item">
        <?php $nin_active = in_array($current_page, ['NINCard.php', 'NINCardHistory.php']); ?>
        <a class="nav-link <?php echo $nin_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#nin-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-person-badge"></i><span>NIN Card</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="nin-nav" class="nav-content collapse <?php echo $nin_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/web/NINCard.php" class="<?php echo ($current_page == 'NINCard.php') ? 'active' : ''; ?>">
              <i class="bi bi-person-badge"></i><span>Print NIN Slip</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/NINCardHistory.php" class="<?php echo ($current_page == 'NINCardHistory.php') ? 'active' : ''; ?>">
              <i class="bi bi-clock-history"></i><span>NIN Slip History</span>
            </a>
          </li>
        </ul>
      </li>
      <?php endif; ?>

      <?php if(isServiceEnabled('bvn_verify')): ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'BVNVerification.php') ? 'active_item' : 'collapsed'; ?>"
           href="<?php echo $web_http_host; ?>/web/BVNVerification.php">
          <i class="bi bi-fingerprint"></i><span>BVN Verification</span>
        </a>
      </li>
      <?php endif; ?>

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'NumberFilter.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/NumberFilter.php">
          <i class="bi bi-filter-square"></i>
          <span>Number Filter</span>
        </a>
      </li>

      <li class="nav-item">
        <?php $payment_order_active = in_array($current_page, ['PaymentOrders.php', 'SubmitPayment.php']); ?>
        <a class="nav-link <?php echo $payment_order_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#payment-order-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-cart-check"></i><span>Payment Order</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="payment-order-nav" class="nav-content collapse <?php echo $payment_order_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/web/PaymentOrders.php" class="<?php echo ($current_page == 'PaymentOrders.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Payment Orders</span>
            </a>
          </li>
          <?php if(isServiceEnabled('manual_funding')): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/SubmitPayment.php" class="<?php echo ($current_page == 'SubmitPayment.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Submit Payment</span>
            </a>
          </li>
          <?php endif; ?>

        </ul>
      </li>

      <li class="nav-item">
        <?php $trans_active = in_array($current_page, ['Transactions.php', 'BatchTransactions.php', 'PointsHistory.php', 'TransactionCalculator.php', 'NumberChecker.php']); ?>
        <a class="nav-link <?php echo $trans_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#transaction-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-receipt"></i><span>Transactions</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="transaction-nav" class="nav-content collapse <?php echo $trans_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Transactions.php" class="<?php echo ($current_page == 'Transactions.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>All Transactions</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/BatchTransactions.php" class="<?php echo ($current_page == 'BatchTransactions.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Batch Transactions</span>
            </a>
          </li>

          <li>
            <a href="<?php echo $web_http_host; ?>/web/PointsHistory.php" class="<?php echo ($current_page == 'PointsHistory.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Points History</span>
            </a>
          </li>

          <li>
            <a href="<?php echo $web_http_host; ?>/web/TransactionCalculator.php" class="<?php echo ($current_page == 'TransactionCalculator.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Transaction Calculator</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/NumberChecker.php" class="<?php echo ($current_page == 'NumberChecker.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Number Credit Checker</span>
            </a>
          </li>

        </ul>
      </li>

      <li class="nav-item">
        <?php $add_money_active = in_array($current_page, ['Fund.php', 'VirtualBanks.php']); ?>
        <a class="nav-link <?php echo $add_money_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#tables-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-bank"></i><span>Add Money</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="tables-nav" class="nav-content collapse <?php echo $add_money_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/web/Fund.php" class="<?php echo ($current_page == 'Fund.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>With ATM Card (ATM)</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/VirtualBanks.php" class="<?php echo ($current_page == 'VirtualBanks.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Bank-Deposit/Transfer</span>
            </a>
          </li>
        </ul>

      </li><!-- End Tables Nav -->

      <?php if(isServiceEnabled('virtual_card')): ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'VirtualCard.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/VirtualCard.php">
          <i class="bi bi-credit-card"></i><span>Virtual Card</span>
        </a>
      </li>
      <?php endif; ?>


      <?php if(isServiceEnabled('bulk_sms')): ?>
      <li class="nav-item">
        <?php $sms_active = in_array($current_page, ['BulkSMS.php', 'SubmitSenderID.php']); ?>
        <a class="nav-link <?php echo $sms_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#bulksms-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-chat-dots"></i><span>Send SMS</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="bulksms-nav" class="nav-content collapse <?php echo $sms_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/web/BulkSMS.php" class="<?php echo ($current_page == 'BulkSMS.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Bulk SMS</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/web/SubmitSenderID.php" class="<?php echo ($current_page == 'SubmitSenderID.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Submit SMS ID</span>
            </a>
          </li>

        </ul>
      </li>
      <?php endif; ?>

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'Pricing.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/Pricing.php">
          <i class="bi bi-tags"></i>
          <span>Account Pricing</span>
        </a>
      </li><!-- End Contact Page Nav -->

      <?php if(isServiceEnabled('gift_card')): ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'GiftCard.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/GiftCard.php">
          <i class="bi bi-gift"></i>
          <span>Gift Card</span>
        </a>
      </li><!-- End Profile Page Nav -->
      <?php endif; ?>

      <?php if(isServiceEnabled('crypto_hub')): ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'CryptoHub.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/CryptoHub.php">
          <i class="bi bi-currency-bitcoin"></i>
          <span>Crypto</span>
        </a>
      </li>
      <?php endif; ?>



      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'AccountSettings.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/AccountSettings.php">
          <i class="bi bi-gear"></i>
          <span>Account Settings</span>
        </a>
      </li><!-- End Contact Page Nav -->

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'APIDocs.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/APIDocs.php">
          <i class="bi bi-code-slash"></i>
          <span>Developer API </span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="javascript:void(0)" onclick="startUserGuide()">
          <i class="bi bi-question-circle"></i>
          <span>Help / Guide</span>
        </a>
      </li>
    <?php } else { ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'index.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/index.php">
          <i class="bi bi-house"></i>
          <span>Home</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'blog.php' || $current_page == 'single-post.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/blog.php">
          <i class="bi bi-journal-text"></i>
          <span>Blog & Insights</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'APIDocs.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/web/APIDocs.php">
          <i class="bi bi-code-slash"></i>
          <span>Developer API</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link collapsed" href="<?php echo $web_http_host; ?>/web/Login.php">
          <i class="bi bi-box-arrow-in-right"></i>
          <span>Login</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link collapsed" href="<?php echo $web_http_host; ?>/web/Register.php">
          <i class="bi bi-person-plus"></i>
          <span>Register</span>
        </a>
      </li>
    <?php } ?>
  </ul>

</aside><!-- End Sidebar-->

<main id="main" class="main">
<?php
// DGV6.90 AI Edition: Inject AI assistant widget
// Now enabled for all users to provide contextual support
if (isset($get_logged_user_details)):
    $ai_token_bal = (int)($get_logged_user_details['ai_token_balance'] ?? 0);
    $ai_user_ctx  = bc_get_ai_user_context($get_logged_user_details);
    $current_page = basename($_SERVER["PHP_SELF"]);
?>
<script>
  window.__ai_enabled     = true;
  window.__ai_page_slug   = <?php echo json_encode(preg_replace("/[^a-z0-9_]/", "_", strtolower(basename($_SERVER["PHP_SELF"], ".php")))); ?>;
  window.__ai_handler_url = <?php echo json_encode($web_http_host . '/web/ai-handler.php?context=user'); ?>;
  window.__ai_guide_url   = <?php echo json_encode($web_http_host . '/web/ai-guide-cache.php?context=user'); ?>;
  window.__ai_tokens      = <?php echo (int)$ai_token_bal; ?>;
  window.__ai_context     = <?php echo json_encode($ai_user_ctx, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?: '{}'; ?>;
  
  <?php
    $init_msg = "Hello! I'm your AI Assistant. How can I help you?";
    $auto_open = false;

    $page_tips = [
        'Airtime.php' => 'Try: "Buy MTN 100 airtime for 08012345678"',
        'Data.php'    => 'Try: "Buy Airtel 2GB SME data for 08123456789"',
        'Electric.php'=> 'Try: "Pay 2000 for IKEDC Prepaid 010123456789"',
        'Cable.php'   => 'Try: "Renew DSTV Compact for 123456789"',
    ];
    if (isset($page_tips[$current_page])) {
        $init_msg .= " You can say something like: " . $page_tips[$current_page];
    }
  ?>
  window.__ai_auto_open   = <?php echo $auto_open ? 'true' : 'false'; ?>;
  window.__ai_init_msg    = <?php echo json_encode($init_msg, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo $web_http_host; ?>/jsfile/ai-assistant.js" defer></script>

<?php endif; ?>

<!-- Referral Modal -->
<div class="modal fade" id="referralModal" tabindex="-1" aria-labelledby="referralModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="referralModalLabel">Refer and Earn</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Share your referral link with friends and earn rewards!</p>
        <div class="input-group">
            <input type="text" class="form-control" value="<?php echo $web_http_host . "/web/Register.php?referral=" . $get_logged_user_details["username"]; ?>" id="referralLinkInput" readonly>
            <button class="btn btn-primary" type="button" onclick="copyReferLinkModal()">Copy</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function copyReferLinkModal() {
    var copyText = document.getElementById("referralLinkInput");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);

    // Optional: Add a visual confirmation
    alert("Referral link copied!");
}
</script>