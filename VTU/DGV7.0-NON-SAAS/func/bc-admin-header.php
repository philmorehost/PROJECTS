	
<?php
if (!function_exists('hex2rgb')) {
function hex2rgb($hex) {
   $hex = str_replace("#", "", $hex);
   if(strlen($hex) == 3) {
      $r = hexdec(substr($hex,0,1).substr($hex,0,1));
      $g = hexdec(substr($hex,1,1).substr($hex,1,1));
      $b = hexdec(substr($hex,2,1).substr($hex,2,1));
   } else {
      $r = hexdec(substr($hex,0,2));
      $g = hexdec(substr($hex,2,2));
      $b = hexdec(substr($hex,4,2));
   }
   return "$r, $g, $b";
}
}
?>
  <style>
    :root {
      --primary-color: <?php echo $vendor_primary_color; ?>;
      --primary-color-rgb: <?php echo hex2rgb($vendor_primary_color); ?>;
      --bs-primary-rgb: <?php echo hex2rgb($vendor_primary_color); ?>;
      --sidebar-bg: <?php echo $vendor_primary_color; ?>;
    }
    .bg-primary, .badge.bg-primary {
      background-color: rgba(var(--bs-primary-rgb), var(--bs-bg-opacity, 1)) !important;
    }
    .btn-primary, .nav-pills .nav-link.active, .card-blue {
      background-color: var(--primary-color) !important;
    }
    .btn-primary, .border-primary {
      border-color: var(--primary-color) !important;
    }
    .text-primary {
      color: rgba(var(--bs-primary-rgb), var(--bs-text-opacity, 1)) !important;
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
    .sidebar-nav .nav-link.active {
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
  </style>
  <script>
    window.siteTitle = "<?php echo $get_all_super_admin_site_details['site_title'] ?? ''; ?>";
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <?php
    $current_page_base = basename($_SERVER['PHP_SELF']);
    $load_editor_css = in_array($current_page_base, ['AccountSettings.php', 'SiteSettings.php', 'Announcement.php', 'StatusMessage.php', 'EmailTemplates.php', 'SendMail.php']);
    $load_tables_css = in_array($current_page_base, ['Transactions.php', 'UserManagement.php', 'PaymentOrders.php', 'GiftCard.php', 'Users.php', 'BatchTransactions.php', 'GuestOrders.php', 'Withdrawals.php', 'FundTransferRequests.php', 'MarketPlace.php', 'APIRequests.php', 'KnowledgeBase.php']);
  ?>
  <?php if($load_editor_css): ?>
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <?php endif; ?>
  <?php if($load_tables_css): ?>
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">
  <?php endif; ?>

  <!-- ======= Header ======= -->
  <header id="header" class="header fixed-top d-flex align-items-center">

  <div class="d-flex align-items-center justify-content-between">
      <a href="#" class="logo d-flex align-items-center">
        <span class="d-none d-lg-block"><img src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo str_replace(['.',':'],'-',$_SERVER['HTTP_HOST']).'_'; ?>logo.png" alt=""></span>
      </a>
      <i class="bi bi-list toggle-sidebar-btn"></i>
</div>


    <nav class="header-nav ms-auto">
      <ul class="d-flex align-items-center">

       

        <li class="nav-item">
          <a class="sitemap-guide-btn" href="<?php echo $web_http_host; ?>/bc-admin/SiteMap.php" title="Platform Guide">
            <span class="guide-hand">👉</span>
            GUIDE
          </a>
        </li><!-- End SiteMap Icon -->



        <li class="nav-item dropdown pe-3">

          <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
            <img src="<?php echo'/asset/boy-icon.png'; ?>"" alt="Profile" class="rounded-circle">
            <span class="d-none d-md-block dropdown-toggle ps-2">
              <?php echo $_SESSION["admin_session"]; ?>
            </span>
          </a><!-- End Profile Iamge Icon -->

          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
            <li class="dropdown-header">
              <h6><?php echo $_SESSION["admin_session"]; ?></h6>
              
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>

            <li>
              <a class="dropdown-item d-flex align-items-center" href="<?php echo $web_http_host; ?>/bc-admin/MarketPlace.php">
                <i class="bi bi-shop"></i>
                <span>MarketPlace</span>
              </a>
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>

            <li>
              <a class="dropdown-item d-flex align-items-center" href="<?php echo $web_http_host; ?>/bc-admin/AccountSettings.php">
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
              <a class="dropdown-item d-flex align-items-center" onclick="javascript:if(confirm('Do you want to logout? ')){window.location.href='/admin-logout.php'}">
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
    <?php if(isset($_SESSION["admin_session"]) && isset($get_logged_admin_details)){
        $current_page = basename($_SERVER['PHP_SELF']);
    ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'Dashboard.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-admin/Dashboard.php">
          <i class="bi bi-grid"></i>
          <span>Dashboard</span> 
        </a>
      </li><!-- End Dashboard Nav -->

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'SiteMap.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-admin/SiteMap.php">
          <i class="bi bi-grid-3x3-gap"></i>
          <span>Platform Guide</span>
        </a>
      </li><!-- End SiteMap Nav -->

      <!-- AI Business Suite Nav Item -->
      <?php if(isServiceEnabled('ai_suite')): ?>
      <?php if (isset($get_logged_admin_details) && !empty($get_logged_admin_details['ai_status'])): ?>
      <li class="nav-item">
        <a class="nav-link <?php echo in_array($current_page, ['AISettings.php','AIMarketing.php']) ? '' : 'collapsed'; ?>"
           data-bs-target="#ai-suite-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-cpu-fill"></i>
          <span>AI Business Suite</span>
          <span class="badge bg-primary ms-auto rounded-pill" style="font-size:.6rem;">ON</span>
          <i class="bi bi-chevron-down ms-1 small"></i>
        </a>
        <ul id="ai-suite-nav" class="nav-content collapse <?php echo in_array($current_page, ['AISettings.php','AIMarketing.php']) ? 'show' : ''; ?>">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/AISettings.php" class="<?php echo $current_page == 'AISettings.php' ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>AI Settings & Tokens</span>
            </a>
          </li>
          <li>
            <?php if (function_exists('bc_is_demo_mode') && function_exists('bc_demo_locked_link') && bc_is_demo_mode($connection_server)): ?>
              <?php echo bc_demo_locked_link($web_http_host . '/bc-admin/AIMarketing.php', 'AI Marketing Studio', 'bi bi-circle', 'AI Marketing'); ?>
            <?php else: ?>
              <a href="<?php echo $web_http_host; ?>/bc-admin/AIMarketing.php" class="<?php echo $current_page == 'AIMarketing.php' ? 'active' : ''; ?>">
                <i class="bi bi-circle"></i><span>AI Marketing Studio</span>
              </a>
            <?php endif; ?>
          </li>
        </ul>
      </li>
      <?php else: ?>
      <li class="nav-item">
        <a class="nav-link <?php echo in_array($current_page, ['AISettings.php','AIMarketing.php']) ? '' : 'collapsed'; ?>"
           data-bs-target="#ai-suite-nav-off" data-bs-toggle="collapse" href="#">
          <i class="bi bi-cpu"></i>
          <span>AI Business Suite</span>
          <span class="badge bg-secondary ms-auto rounded-pill" style="font-size:.6rem;">OFF</span>
          <i class="bi bi-chevron-down ms-1 small"></i>
        </a>
        <ul id="ai-suite-nav-off" class="nav-content collapse <?php echo in_array($current_page, ['AISettings.php','AIMarketing.php']) ? 'show' : ''; ?>">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/AISettings.php" class="<?php echo $current_page == 'AISettings.php' ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>AI Settings & Tokens</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/AIMarketing.php" class="<?php echo $current_page == 'AIMarketing.php' ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>AI Marketing Studio</span>
            </a>
          </li>
        </ul>
      </li>
      <?php endif; ?>
      <?php endif; ?>

      <li class="nav-heading">Business Management</li>

      <li class="nav-item">
        <?php $manage_user_active = in_array($current_page, ['CreateUser.php', 'KnowledgeBase.php', 'Users.php', 'Transactions.php', 'BatchTransactions.php', 'GuestOrders.php', 'PaymentOrders.php', 'FundTransferRequests.php', 'ShareFund.php', 'APIRequests.php', 'NumberChecker.php']); ?>
        <a class="nav-link <?php echo $manage_user_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#manage-user-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-menu-button-wide"></i><span>Manage User</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="manage-user-nav" class="nav-content collapse <?php echo $manage_user_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/CreateUser.php" class="<?php echo ($current_page == 'CreateUser.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Create User</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/APIRequests.php" class="<?php echo ($current_page == 'APIRequests.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>API Access Requests</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/KnowledgeBase.php" class="<?php echo ($current_page == 'KnowledgeBase.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Knowledge Base</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Users.php" class="<?php echo ($current_page == 'Users.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Users</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/CryptoInvoices.php" class="<?php echo ($current_page == 'CryptoInvoices.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Crypto Invoices</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Transactions.php" class="<?php echo ($current_page == 'Transactions.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Transactions</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/NumberChecker.php" class="<?php echo ($current_page == 'NumberChecker.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Number Credit Checker</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/BatchTransactions.php" class="<?php echo ($current_page == 'BatchTransactions.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Batch Transactions</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/GuestOrders.php" class="<?php echo ($current_page == 'GuestOrders.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Guest Orders</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/PaymentOrders.php" class="<?php echo ($current_page == 'PaymentOrders.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Payment Orders</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Withdrawals.php" class="<?php echo ($current_page == 'Withdrawals.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Withdrawal Requests</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/FundTransferRequests.php" class="<?php echo ($current_page == 'FundTransferRequests.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Fund Transfer Requests</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/ShareFund.php" class="<?php echo ($current_page == 'ShareFund.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Fund User</span>
            </a>
          </li>
        </ul>
        </li>
      
      <li class="nav-item">
        <?php $sys_func_active = in_array($current_page, ['AccountSettings.php', 'UpdateSystem.php', 'StatusMessage.php', 'EmailTemplates.php', 'SendMail.php', 'IDBlockingSystem.php', 'TransactionLimits.php', 'SalesCalculator.php', 'SenderIDRequests.php', 'PaymentGateway.php', 'LoyaltySettings.php', 'CoinConversions.php', 'FloatServiceIcons.php', 'BruteForceSecurity.php', 'AppUpdateBroadcast.php']); ?>
        <a class="nav-link <?php echo $sys_func_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#system-func-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-menu-button-wide"></i><span>System Function</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="system-func-nav" class="nav-content collapse <?php echo $sys_func_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/AccountSettings.php" class="<?php echo ($current_page == 'AccountSettings.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Account Settings</span>
            </a>
          </li>

          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/UpdateSystem.php" class="<?php echo ($current_page == 'UpdateSystem.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Update System</span>
            </a>
          </li>

          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/StatusMessage.php" class="<?php echo ($current_page == 'StatusMessage.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Status Message</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/EmailTemplates.php" class="<?php echo ($current_page == 'EmailTemplates.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Email Templates</span>
            </a>
          </li>
          <li>
            <?php if (function_exists('bc_is_demo_mode') && function_exists('bc_demo_locked_link') && bc_is_demo_mode($connection_server)): ?>
              <?php echo bc_demo_locked_link($web_http_host . '/bc-admin/SendMail.php', 'Mail Sender', 'bi bi-circle', 'Broadcast Email'); ?>
            <?php else: ?>
              <a href="<?php echo $web_http_host; ?>/bc-admin/SendMail.php" class="<?php echo ($current_page == 'SendMail.php') ? 'active' : ''; ?>">
                <i class="bi bi-circle"></i><span>Mail Sender</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <?php if (function_exists('bc_is_demo_mode') && function_exists('bc_demo_locked_link') && bc_is_demo_mode($connection_server)): ?>
              <?php echo bc_demo_locked_link($web_http_host . '/bc-admin/AppUpdateBroadcast.php', 'App Update Broadcast', 'bi bi-circle', 'App Update Broadcast'); ?>
            <?php else: ?>
              <a href="<?php echo $web_http_host; ?>/bc-admin/AppUpdateBroadcast.php" class="<?php echo ($current_page == 'AppUpdateBroadcast.php') ? 'active' : ''; ?>">
                <i class="bi bi-circle"></i><span>App Update Broadcast</span>
              </a>
            <?php endif; ?>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/IDBlockingSystem.php" class="<?php echo ($current_page == 'IDBlockingSystem.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>ID Blocking System</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/TransactionLimits.php" class="<?php echo ($current_page == 'TransactionLimits.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Transaction Limits</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/SalesCalculator.php" class="<?php echo ($current_page == 'SalesCalculator.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Sales Calculator</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/SenderIDRequests.php" class="<?php echo ($current_page == 'SenderIDRequests.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Sender ID Requests</span>
            </a>
          </li>

          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/PaymentGateway.php" class="<?php echo ($current_page == 'PaymentGateway.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Payment Gateway</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/LoyaltySettings.php" class="<?php echo ($current_page == 'LoyaltySettings.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Loyalty Settings</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/CoinConversions.php" class="<?php echo ($current_page == 'CoinConversions.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Coin Conversions</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/CryptoHub.php" class="<?php echo ($current_page == 'CryptoHub.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Crypto</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/FloatServiceIcons.php" class="<?php echo ($current_page == 'FloatServiceIcons.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Float Service Icons</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/KYCManagement.php" class="<?php echo ($current_page == 'KYCManagement.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>KYC Management</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/BruteForceSecurity.php" class="<?php echo ($current_page == 'BruteForceSecurity.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Brute Force Security</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/ServiceControl.php" class="<?php echo ($current_page == 'ServiceControl.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Service Control Center</span>
            </a>
          </li>
        </ul>
      </li>
      
      <li class="nav-heading">Service Operations</li>

      <li class="nav-item">
        <?php $api_manager_active = in_array($current_page, ['MarketPlace.php', 'ProductSetUp.php', 'Airtime.php', 'BulkSMS.php', 'SharedData.php', 'SmeData.php', 'CorporateData.php', 'DirectData.php', 'Electric.php', 'Betting.php', 'Exam.php', 'Cable.php', 'DataBundleCard.php', 'GiftCard.php', 'VirtualCard.php', 'NINCard.php', 'BVNVerification.php']); ?>
        <a class="nav-link <?php echo $api_manager_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#api-manager-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-menu-button-wide"></i><span>API Manager</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="api-manager-nav" class="nav-content collapse <?php echo $api_manager_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/MarketPlace.php" class="<?php echo ($current_page == 'MarketPlace.php') ? 'active' : ''; ?>">
              <i class="bi bi-shop"></i><span>MarketPlace</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/ProductSetUp.php" class="<?php echo ($current_page == 'ProductSetUp.php') ? 'active' : ''; ?>">
              <i class="bi bi-box-seam"></i><span>Product SetUp</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/IdentityAPI.php" class="<?php echo ($current_page == 'IdentityAPI.php') ? 'active' : ''; ?>">
              <i class="bi bi-shield-check"></i><span>Identity Services Manager</span>
            </a>
          </li>
          <li><hr class="sidebar-divider my-1"><small class="px-3 text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.05rem;">VTU Services</small></li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Airtime.php" class="<?php echo ($current_page == 'Airtime.php') ? 'active' : ''; ?>">
              <i class="bi bi-phone"></i><span>Airtime</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/SharedData.php" class="<?php echo ($current_page == 'SharedData.php') ? 'active' : ''; ?>">
              <i class="bi bi-wifi"></i><span>Shared Data</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/SmeData.php" class="<?php echo ($current_page == 'SmeData.php') ? 'active' : ''; ?>">
              <i class="bi bi-wifi"></i><span>SME Data</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/CorporateData.php" class="<?php echo ($current_page == 'CorporateData.php') ? 'active' : ''; ?>">
              <i class="bi bi-wifi"></i><span>Corporate Data</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/DirectData.php" class="<?php echo ($current_page == 'DirectData.php') ? 'active' : ''; ?>">
              <i class="bi bi-wifi"></i><span>Direct Data</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Cable.php" class="<?php echo ($current_page == 'Cable.php') ? 'active' : ''; ?>">
              <i class="bi bi-tv"></i><span>Cable TV</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Electric.php" class="<?php echo ($current_page == 'Electric.php') ? 'active' : ''; ?>">
              <i class="bi bi-lightning-charge"></i><span>Electricity</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Exam.php" class="<?php echo ($current_page == 'Exam.php') ? 'active' : ''; ?>">
              <i class="bi bi-pencil-square"></i><span>Exam Pins</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/Betting.php" class="<?php echo ($current_page == 'Betting.php') ? 'active' : ''; ?>">
              <i class="bi bi-trophy"></i><span>Betting</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/BulkSMS.php" class="<?php echo ($current_page == 'BulkSMS.php') ? 'active' : ''; ?>">
              <i class="bi bi-chat-dots"></i><span>Bulk SMS</span>
            </a>
          </li>
          <li><hr class="sidebar-divider my-1"><small class="px-3 text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.05rem;">Print Hub</small></li>
          <?php $ph_type = isset($_GET['type']) ? $_GET['type'] : ''; ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/DataBundleCard.php?type=data" class="<?php echo ($current_page == 'DataBundleCard.php' && ($ph_type == 'data' || $ph_type == '')) ? 'active' : ''; ?>">
              <i class="bi bi-printer"></i><span>Print Data Card</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/DataBundleCard.php?type=airtime" class="<?php echo ($current_page == 'DataBundleCard.php' && $ph_type == 'airtime') ? 'active' : ''; ?>">
              <i class="bi bi-printer"></i><span>Print Airtime Card</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/DataBundleCard.php?type=cable" class="<?php echo ($current_page == 'DataBundleCard.php' && $ph_type == 'cable') ? 'active' : ''; ?>">
              <i class="bi bi-printer"></i><span>Print Cable Card</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/DataBundleCard.php?type=electric" class="<?php echo ($current_page == 'DataBundleCard.php' && $ph_type == 'electric') ? 'active' : ''; ?>">
              <i class="bi bi-printer"></i><span>Print Electric Token</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/DataBundleCard.php?type=exam" class="<?php echo ($current_page == 'DataBundleCard.php' && $ph_type == 'exam') ? 'active' : ''; ?>">
              <i class="bi bi-printer"></i><span>Print Exam Pin</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/DataBundleCard.php?type=betting" class="<?php echo ($current_page == 'DataBundleCard.php' && $ph_type == 'betting') ? 'active' : ''; ?>">
              <i class="bi bi-printer"></i><span>Print Betting Card</span>
            </a>
          </li>
          <li><hr class="sidebar-divider my-1"><small class="px-3 text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.05rem;">Identity Services</small></li>
          <?php if (!empty($get_logged_admin_details['nin_card_enabled'])): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/NINCard.php" class="<?php echo ($current_page == 'NINCard.php') ? 'active' : ''; ?>">
              <i class="bi bi-person-badge"></i><span>NIN Slip Requests</span>
            </a>
          </li>
          <?php endif; ?>
          <?php if (!empty($get_logged_admin_details['bvn_verify_enabled'])): ?>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/BVNVerification.php" class="<?php echo ($current_page == 'BVNVerification.php') ? 'active' : ''; ?>">
              <i class="bi bi-fingerprint"></i><span>BVN Verify Requests</span>
            </a>
          </li>
          <?php endif; ?>
          <li><hr class="sidebar-divider my-1"><small class="px-3 text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.05rem;">Cards</small></li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/GiftCard.php" class="<?php echo ($current_page == 'GiftCard.php') ? 'active' : ''; ?>">
              <i class="bi bi-gift"></i><span>Gift Card</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/VirtualCard.php" class="<?php echo ($current_page == 'VirtualCard.php') ? 'active' : ''; ?>">
              <i class="bi bi-credit-card"></i><span>Virtual Card</span>
            </a>
          </li>
        </ul>
      </li>



      <li class="nav-item">
        <?php $blog_active = in_array($current_page, ['BlogPosts.php', 'BlogCategories.php']); ?>
        <a class="nav-link <?php echo $blog_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#blog-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-journal-text"></i><span>Blog Manager</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="blog-nav" class="nav-content collapse <?php echo $blog_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/BlogPosts.php" class="<?php echo ($current_page == 'BlogPosts.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Posts</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-admin/BlogCategories.php" class="<?php echo ($current_page == 'BlogCategories.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Categories</span>
            </a>
          </li>
        </ul>
      </li>




      <li class="nav-heading">System Control</li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="javascript:void(0)" onclick="startAdminGuide()">
          <i class="bi bi-question-circle"></i>
          <span>Help / Guide</span>
        </a>
      </li>
      <?php } ?>
  </aside><!-- End Sidebar-->

<?php
// DGV6.90 AI Edition: Inject AI assistant widget (deferred, zero performance impact)
$_ai_vendor_enabled = isset($get_logged_admin_details['ai_status']) && (int)$get_logged_admin_details['ai_status'] === 1;
if ($_ai_vendor_enabled):
    $ai_token_bal = (int)($get_logged_admin_details['ai_token_balance'] ?? 0);
?>
<script>
  window.__ai_enabled     = true;
  window.__ai_page_slug   = '<?php echo preg_replace("/[^a-z0-9_]/", "_", strtolower(basename($_SERVER["PHP_SELF"], ".php"))); ?>';
  window.__ai_handler_url = '/web/ai-handler.php?context=admin';
  window.__ai_guide_url   = '/web/ai-guide-cache.php?context=admin';
  window.__ai_tokens      = <?php echo $ai_token_bal; ?>;
</script>
<script src="../jsfile/ai-assistant.js" defer></script>
<?php endif; ?>

<?php if (!empty($GLOBALS['bc_admin_needs_pin'])): ?>
<!-- Forced Security PIN setup — required to self-unblock via the anti-brute-force
     lockout flow (web/LockoutResolution.php). Modal, not a redirect, so it works
     on whatever page the admin is already on. -->
<div class="modal fade" id="forcePinModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-body p-4">
        <div class="text-center mb-3">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger mb-2" style="width:56px;height:56px;">
            <i class="bi bi-shield-lock-fill fs-3"></i>
          </div>
          <h5 class="fw-bold mb-1">Set Your Security PIN</h5>
          <p class="small text-muted mb-0">You'll need this 4-digit PIN to unblock your own account if our anti-brute-force system ever locks you out. It only takes a second.</p>
        </div>
        <div id="forcePinError" class="alert alert-danger py-2 small rounded-3" style="display:none;"></div>
        <div class="mb-3">
          <label class="form-label small fw-bold text-muted">NEW 4-DIGIT PIN</label>
          <input type="password" id="forcePinInput" class="form-control form-control-lg text-center fw-bold" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="****" style="letter-spacing:10px;">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-bold text-muted">CONFIRM PIN</label>
          <input type="password" id="forcePinConfirm" class="form-control form-control-lg text-center fw-bold" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="****" style="letter-spacing:10px;">
        </div>
        <button type="button" id="forcePinSubmit" class="btn btn-primary w-100 rounded-pill fw-bold py-2">Save PIN &amp; Continue</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('forcePinModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    var modal = new bootstrap.Modal(modalEl);
    modal.show();

    var errBox = document.getElementById('forcePinError');
    var submitBtn = document.getElementById('forcePinSubmit');

    function showErr(msg) {
        errBox.textContent = msg;
        errBox.style.display = 'block';
    }

    submitBtn.addEventListener('click', function () {
        var pin = document.getElementById('forcePinInput').value.trim();
        var con = document.getElementById('forcePinConfirm').value.trim();
        errBox.style.display = 'none';

        if (!/^\d{4}$/.test(pin)) { showErr('PIN must be exactly 4 digits.'); return; }
        if (pin !== con) { showErr('PIN and Confirm PIN do not match.'); return; }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving…';

        fetch('AccountSettings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'quick_set_pin', admin_pin: pin, admin_pin_con: con })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                modal.hide();
            } else {
                showErr(data.msg || 'Could not save PIN. Please try again.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save PIN & Continue';
            }
        })
        .catch(function () {
            showErr('Network error — please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save PIN & Continue';
        });
    });
});
</script>
<?php endif; ?>

   <main id="main" class="main">