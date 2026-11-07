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

if (!isset($spadmin_primary_color) || empty($spadmin_primary_color)) {
    $spadmin_primary_color = "#0d6efd"; // Safe default blue color
}
if (!isset($get_all_super_admin_site_details) || empty($get_all_super_admin_site_details)) {
    global $connection_server;
    if (isset($connection_server) && $connection_server) {
        $get_all_super_admin_site_details_query = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_site_details LIMIT 1");
        $get_all_super_admin_site_details = ($get_all_super_admin_site_details_query && mysqli_num_rows($get_all_super_admin_site_details_query) > 0) ? mysqli_fetch_array($get_all_super_admin_site_details_query) : null;
    }
    if (empty($get_all_super_admin_site_details)) {
        $get_all_super_admin_site_details = [
            'site_title' => 'Super Admin Platform',
            'site_desc' => 'Super Admin Portal'
        ];
    }
}
?>
<style>
  :root {
    --primary-color: <?php echo $spadmin_primary_color; ?>;
    --primary-color-rgb: <?php echo hex2rgb($spadmin_primary_color); ?>;
    --bs-primary-rgb: <?php echo hex2rgb($spadmin_primary_color); ?>;
    --sidebar-bg: <?php echo $spadmin_primary_color; ?>;
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
</style>
<script>
  window.siteTitle = "<?php echo $get_all_super_admin_site_details['site_title']; ?>";
</script>
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
          <a class="sitemap-guide-btn" href="<?php echo $web_http_host; ?>/bc-spadmin/SiteMap.php" title="Platform Guide">
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
                <h4>Lorem Ipsum</h4>
                <p>Quae dolorem earum veritatis oditseno</p>
                <p>30 min. ago</p>
              </div>
            </li>
          </ul><!-- End Notification Dropdown Items -->

        </li><!-- End Notification Nav -->

        <li class="nav-item dropdown pe-3">

          <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
            <img src="<?php echo'/asset/boy-icon.png'; ?>"" alt="Profile" class="rounded-circle">
            <span class="d-none d-md-block dropdown-toggle ps-2">
              <?php echo $_SESSION["spadmin_session"]; ?>
            </span>
          </a><!-- End Profile Iamge Icon -->

          <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
            <li class="dropdown-header">
              <h6><?php echo $_SESSION["spadmin_session"]; ?></h6>
              
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>

            <li>
              <a class="dropdown-item d-flex align-items-center" href="<?php echo $web_http_host; ?>/bc-spadmin/MarketPlace.php">
                <i class="bi bi-shop"></i>
                <span>MarketPlace</span>
              </a>
            </li>
            <li>
              <hr class="dropdown-divider">
            </li>

            <li>
              <a class="dropdown-item d-flex align-items-center" href="<?php echo $web_http_host; ?>/bc-spadmin/AccountSettings.php">
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
              <a class="dropdown-item d-flex align-items-center" onclick="javascript:if(confirm('Do you want to logout? ')){window.location.href='/spadmin-logout.php'}">
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
    <?php if(isset($_SESSION["spadmin_session"]) && isset($get_logged_spadmin_details)){
        $current_page = basename($_SERVER['PHP_SELF']);
    ?>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'Dashboard.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/Dashboard.php">
          <i class="bi bi-grid"></i>
          <span>Dashboard</span> 
        </a>
      </li><!-- End Dashboard Nav -->

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'SiteMap.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/SiteMap.php">
          <i class="bi bi-grid-3x3-gap"></i>
          <span>Platform Guide</span>
        </a>
      </li><!-- End SiteMap Nav -->

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'ServiceControl.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/ServiceControl.php">
          <i class="bi bi-shield-lock"></i>
          <span>Global Service Control</span>
        </a>
      </li><!-- End Global Service Control Nav -->

      <li class="nav-item">
        <?php 
          $q_ai_req = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_vendors WHERE ai_request_status='pending'");
          $ai_req_count = ($q_ai_req && $r = mysqli_fetch_assoc($q_ai_req)) ? $r['count'] : 0;
        ?>
        <a class="nav-link <?php echo ($current_page == 'AIManagement.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/AIManagement.php">
          <i class="bi bi-cpu-fill"></i>
          <span>AI Manager</span> 
          <?php if($ai_req_count > 0): ?>
          <span class="badge bg-danger rounded-pill ms-auto me-2"><?php echo $ai_req_count; ?></span>
          <?php endif; ?>
        </a>
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'AutomationGuide.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/AutomationGuide.php">
          <i class="bi bi-robot"></i>
          <span>Automation & AI Guide</span> 
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'USSDAccessFee.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/USSDAccessFee.php">
          <i class="bi bi-phone-fill"></i>
          <span>USSD Channel Access</span> 
        </a>
      </li>

      <li class="nav-heading">Management</li>

      <li class="nav-item">
        <?php $manage_vendor_active = in_array($current_page, ['VendorReg.php', 'Vendors.php', 'VendorRegistrations.php', 'DomainSettings.php', 'UnblockRequests.php']); ?>
        <a class="nav-link <?php echo $manage_vendor_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#manage-vendor-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-menu-button-wide"></i><span>Manage Vendors</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="manage-vendor-nav" class="nav-content collapse <?php echo $manage_vendor_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/VendorReg.php" class="<?php echo ($current_page == 'VendorReg.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Add Vendor</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/Vendors.php" class="<?php echo ($current_page == 'Vendors.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>View Vendors</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/VendorRegistrations.php" class="<?php echo ($current_page == 'VendorRegistrations.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Pending Registrations</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/UnblockRequests.php" class="<?php echo ($current_page == 'UnblockRequests.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Unblock Requests</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/DomainSettings.php" class="<?php echo ($current_page == 'DomainSettings.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Domain Settings</span>
            </a>
          </li>
        </ul>
      </li>
      
      <li class="nav-item">
        <?php $manage_billing_active = in_array($current_page, ['BillingPackages.php', 'AllSubscriptions.php']); ?>
        <a class="nav-link <?php echo $manage_billing_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#manage-billing-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-menu-button-wide"></i><span>Manage Billings</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="manage-billing-nav" class="nav-content collapse <?php echo $manage_billing_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/BillingPackages.php" class="<?php echo ($current_page == 'BillingPackages.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Billing Packages</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/AllSubscriptions.php" class="<?php echo ($current_page == 'AllSubscriptions.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>All Subscriptions</span>
            </a>
          </li>
        </ul>
      </li>
      
      <li class="nav-item">
        <?php $manage_api_active = in_array($current_page, ['CreateAPI.php', 'MarketPlace.php']); ?>
        <a class="nav-link <?php echo $manage_api_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#manage-api-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-menu-button-wide"></i><span>Manage API</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="manage-api-nav" class="nav-content collapse <?php echo $manage_api_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/CreateAPI.php" class="<?php echo ($current_page == 'CreateAPI.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Add API</span>
            </a>
          </li>
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/MarketPlace.php" class="<?php echo ($current_page == 'MarketPlace.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>View MarketPlace</span>
            </a>
          </li>
        </ul>
      </li>
      
      <li class="nav-item">
        <?php $manage_users_active = in_array($current_page, ['Users.php', 'UserEdit.php', 'UserUpgrade.php']); ?>
        <a class="nav-link <?php echo $manage_users_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#manage-users-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-people"></i><span>Manage Users</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="manage-users-nav" class="nav-content collapse <?php echo $manage_users_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/Users.php" class="<?php echo ($current_page == 'Users.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>All Users</span>
            </a>
          </li>
        </ul>
      </li>

      <li class="nav-item">
        <?php $manage_notify_active = in_array($current_page, ['StatusMessage.php', 'SendMail.php']); ?>
        <a class="nav-link <?php echo $manage_notify_active ? 'active_item' : 'collapsed'; ?>" data-bs-target="#manage-notify-nav" data-bs-toggle="collapse" href="#">
          <i class="bi bi-menu-button-wide"></i><span>Manage Notification</span><i class="bi bi-chevron-down ms-auto"></i>
        </a>
        <ul id="manage-notify-nav" class="nav-content collapse <?php echo $manage_notify_active ? 'show' : ''; ?>" data-bs-parent="#sidebar-nav">
          <li>
            <a href="<?php echo $web_http_host; ?>/bc-spadmin/StatusMessage.php" class="<?php echo ($current_page == 'StatusMessage.php') ? 'active' : ''; ?>">
              <i class="bi bi-circle"></i><span>Update Status Message</span>
            </a>
          </li>
          <li>
            <?php if (function_exists('bc_is_demo_mode') && function_exists('bc_demo_locked_link') && bc_is_demo_mode($connection_server)): ?>
              <?php echo bc_demo_locked_link($web_http_host . '/bc-spadmin/SendMail.php', 'Send Mail', 'bi bi-circle', 'Broadcast Email'); ?>
            <?php else: ?>
              <a href="<?php echo $web_http_host; ?>/bc-spadmin/SendMail.php" class="<?php echo ($current_page == 'SendMail.php') ? 'active' : ''; ?>">
                <i class="bi bi-circle"></i><span>Send Mail</span>
              </a>
            <?php endif; ?>
          </li>
        </ul>
      </li>
      
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'PaymentGateway.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/PaymentGateway.php">
          <i class="bi bi-credit-card-fill"></i>
          <span>Payment Gateway</span> 
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'ShareFund.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/ShareFund.php">
          <i class="bi bi-cash"></i>
          <span>Fund Vendor</span> 
        </a>
      </li>
      
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'Transactions.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/Transactions.php">
          <i class="bi bi-wallet"></i>
          <span>Transactions</span> 
        </a>
      </li>
      
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'PaymentOrders.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/PaymentOrders.php">
          <i class="bi bi-cart-fill"></i>
          <span>Payment Orders</span> 
        </a>
      </li>
      
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'EmailTemplates.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/EmailTemplates.php">
          <i class="bi bi-envelope-at-fill"></i>
          <span>Email Templates</span> 
        </a>
      </li>
      
      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'SubscriptionReports.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/SubscriptionReports.php">
          <i class="bi bi-bar-chart-line"></i>
          <span>Reports</span>
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'CryptoHub.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/CryptoHub.php">
          <i class="bi bi-currency-bitcoin"></i>
          <span>Crypto</span>
        </a>
      </li>


      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'AccountSettings.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/AccountSettings.php">
          <i class="bi bi-gear"></i>
          <span>Account Settings</span> 
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'CronJobs.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/CronJobs.php">
          <i class="bi bi-clock-history"></i>
          <span>Cron Jobs</span> 
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'UpdateSystem.php') ? 'active_item' : 'collapsed'; ?>" href="<?php echo $web_http_host; ?>/bc-spadmin/UpdateSystem.php">
          <i class="bi bi-cloud-arrow-down-fill"></i>
          <span>Update System</span> 
        </a>
      </li>

      <li class="nav-item">
        <a class="nav-link collapsed" href="javascript:void(0)" onclick="startSpadminGuide()">
          <i class="bi bi-question-circle"></i>
          <span>Help / Guide</span>
        </a>
      </li>
      <?php } ?>
  </aside><!-- End Sidebar--> 
  
   <main id="main" class="main">
   <?php
   if (isset($GLOBALS['license_warning_msg']) && !empty($GLOBALS['license_warning_msg'])) {
       echo '<div class="alert alert-warning border-0 shadow-sm rounded-4 p-3 mb-4 d-flex align-items-center gap-3">
           <div class="rounded-circle bg-warning bg-opacity-10 p-2 d-flex align-items-center justify-content-center text-warning" style="width: 40px; height: 40px; min-width: 40px;">
               <i class="bi bi-exclamation-triangle-fill fs-5"></i>
           </div>
           <div>' . $GLOBALS['license_warning_msg'] . '</div>
       </div>';
   }
   ?>