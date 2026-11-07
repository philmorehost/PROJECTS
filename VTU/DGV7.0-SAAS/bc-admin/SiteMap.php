<?php
session_start();
include("../func/bc-admin-config.php");
$page_title = "Admin Platform Guide & Site Map";
?>
<!DOCTYPE html>
<head>
    <title><?php echo $page_title; ?> | <?php echo $get_all_super_admin_site_details["site_title"] ?? ''; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"] ?? '', 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">
<style>
.sitemap-hero{background:linear-gradient(135deg,var(--primary-color) 0%,#1a237e 100%);color:#fff;padding:2.5rem 1.5rem 2rem;border-radius:0 0 1.5rem 1.5rem;margin-bottom:1.5rem;}
.sitemap-hero h1{font-size:1.8rem;font-weight:800;margin-bottom:.4rem;}
.sitemap-hero p{opacity:.9;font-size:1rem;}
.sm-section{background:#fff;border-radius:1rem;box-shadow:0 2px 12px rgba(0,0,0,.07);padding:1.25rem 1.5rem;margin-bottom:1.5rem;}
.sm-section h4{font-weight:700;font-size:.9rem;color:var(--primary-color);text-transform:uppercase;letter-spacing:.06rem;border-bottom:2px solid var(--primary-color);padding-bottom:.4rem;margin-bottom:1rem;}
.sm-card{display:flex;align-items:flex-start;gap:.85rem;padding:.85rem;border:1px solid #e9ecef;border-radius:.75rem;transition:box-shadow .18s,transform .18s;text-decoration:none;color:inherit;background:#fafafa;margin-bottom:.75rem;}
.sm-card:hover{box-shadow:0 5px 18px rgba(0,0,0,.1);transform:translateY(-2px);text-decoration:none;color:inherit;}
.sm-card-icon{width:42px;height:42px;border-radius:.65rem;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0;}
.sm-card-body h5{margin:0 0 .15rem;font-size:.88rem;font-weight:700;}
.sm-card-body p{margin:0;font-size:.78rem;color:#6c757d;line-height:1.35;}
.sm-tag{display:inline-block;background:rgba(var(--bs-primary-rgb),.1);color:var(--primary-color);font-size:.68rem;border-radius:1rem;padding:.1rem .45rem;margin-top:.25rem;font-weight:600;}
.sm-tag-warn{background:#fff3cd;color:#856404;}
.sm-tag-info{background:#cff4fc;color:#055160;}
@media (max-width:575px){
  .sm-card{flex-direction:column;align-items:flex-start;}
  .sm-card-icon{width:36px;height:36px;font-size:1.1rem;}
  .sm-section .row .col-md-4{margin-bottom:.5rem;}
}
</style>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
<div class="pagetitle">
  <h1>Platform Guide &amp; Site Map</h1>
  <nav>
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
      <li class="breadcrumb-item active">Site Map</li>
    </ol>
  </nav>
</div>

<section class="sitemap-hero">
  <div class="container-fluid">
    <h1><i class="bi bi-grid-3x3-gap me-2"></i>Admin Platform Guide</h1>
    <p>A complete self-help reference for all admin functions. Click any card to go to that page directly.</p>
  </div>
</section>

<div class="container-fluid">
<?php
function adm_card($icon, $icon_bg, $title, $desc, $url, $tag = null, $tag_class = '') {
    $tagHtml = $tag ? "<span class=\"sm-tag $tag_class\">$tag</span>" : '';
    echo "<a href=\"" . htmlspecialchars($url) . "\" class=\"sm-card\">";
    echo "<div class=\"sm-card-icon\" style=\"background:$icon_bg\"><i class=\"bi bi-$icon\" style=\"color:#fff\"></i></div>";
    echo "<div class=\"sm-card-body\"><h5>" . htmlspecialchars($title) . "</h5><p>" . htmlspecialchars($desc) . "</p>$tagHtml</div>";
    echo "</a>";
}
$h = $web_http_host . '/bc-admin/';
?>

<!-- ── Dashboard ──────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-grid me-2"></i>Overview</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('grid','#0d6efd','Dashboard','Platform overview: revenue, active users, daily sales & charts.',$h.'Dashboard.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('bar-chart-line','#198754','Sales Calculator','Estimate expected profit based on plan costs and selling price.',$h.'SalesCalculator.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('globe','#dc3545','Marketplace','Browse and purchase data/service APIs from the marketplace.',$h.'MarketPlace.php'); ?>
    </div>
  </div>
</div>

<!-- ── User Management ───────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-people me-2"></i>User Management</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('person-plus','#0d6efd','Create User','Manually create a new user account under your platform.',$h.'CreateUser.php'); ?>
      <?php adm_card('people','#6f42c1','All Users','View, search, edit and manage all registered user accounts.',$h.'Users.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('person-check','#198754','KYC Management','Review and approve/reject user KYC verification documents.',$h.'KYCManagement.php','Important','sm-tag-warn'); ?>
      <?php adm_card('shield-lock','#dc3545','ID Blocking System','Manage blocked phone numbers and account ID restrictions.',$h.'IDBlockingSystem.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('lock','#fd7e14','Brute Force Security','Configure login attempt limits and account lockout rules.',$h.'BruteForceSecurity.php','Security','sm-tag-warn'); ?>
      <?php adm_card('question-circle','#20c997','Knowledge Base','Create help articles and FAQs visible to your users.',$h.'KnowledgeBase.php'); ?>
    </div>
  </div>
</div>

<!-- ── Financial ─────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-cash-stack me-2"></i>Financial &amp; Transactions</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('receipt','#0d6efd','All Transactions','Full transaction log with search, filter, and CSV export.',$h.'Transactions.php'); ?>
      <?php adm_card('receipt-cutoff','#198754','Batch Transactions','View grouped batch purchase records (bulk print jobs).',$h.'BatchTransactions.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('cash-coin','#dc3545','Payment Orders','Manage manual and gateway payment orders from users.',$h.'PaymentOrders.php'); ?>
      <?php adm_card('arrow-left-right','#fd7e14','Fund Transfer Requests','Review and approve user-to-user fund transfer requests.',$h.'FundTransferRequests.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('bank','#6f42c1','Withdrawals','Process and track user withdrawal requests to bank.',$h.'Withdrawals.php'); ?>
      <?php adm_card('wallet2','#20c997','Share Fund','Send or distribute funds between user accounts.',$h.'ShareFund.php'); ?>
    </div>
  </div>
</div>

<!-- ── API Manager ────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-menu-button-wide me-2"></i>API Manager — VTU Services</h4>
  <p class="text-muted small mb-3">Configure the API providers that power each service. Buy API access from the Marketplace, then set it up here.</p>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('box-seam','#0d6efd','Product SetUp','Configure products (networks, providers) and their parameters.',$h.'ProductSetUp.php','Setup Required','sm-tag-info'); ?>
      <?php adm_card('phone','#198754','Airtime API','Configure and test airtime top-up API for all networks.',$h.'Airtime.php'); ?>
      <?php adm_card('wifi','#dc3545','SME Data API','Set up SME data bundle API and plans.',$h.'SmeData.php'); ?>
      <?php adm_card('wifi','#fd7e14','Shared Data API','Configure shared data bundle API and plans.',$h.'SharedData.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('wifi','#6f42c1','Corporate Gifting Data','Set up Corporate Gifting data API and pricing.',$h.'CorporateData.php'); ?>
      <?php adm_card('wifi','#20c997','Direct Data API','Configure Direct Data API and plan pricing.',$h.'DirectData.php'); ?>
      <?php adm_card('tv','#0d6efd','Cable TV API','Set up DStv, GOtv, Startimes subscription API.',$h.'Cable.php'); ?>
      <?php adm_card('lightning-charge','#198754','Electricity API','Configure electricity token API for all DISCOs.',$h.'Electric.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('pencil-square','#dc3545','Exam Pins API','Configure WAEC/NECO/NABTEB result checker PIN API.',$h.'Exam.php'); ?>
      <?php adm_card('trophy','#fd7e14','Betting API','Set up betting wallet funding API for platforms.',$h.'Betting.php'); ?>
      <?php adm_card('chat-dots','#6f42c1','Bulk SMS API','Configure Bulk SMS API provider and sender ID settings.',$h.'BulkSMS.php'); ?>
      <?php adm_card('gift','#20c997','Gift Card Manager','Configure gift card product catalogue and pricing.',$h.'GiftCard.php'); ?>
    </div>
  </div>
</div>

<!-- ── Print Hub ──────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-printer me-2"></i>Print Hub — Card Printing Configuration</h4>
  <p class="text-muted small mb-3">
    Print Hub allows users to generate digital E-PIN cards. Configure the plan pricing for each card type below.
    <strong>Important:</strong> Pricing is managed via <a href="<?php echo $h; ?>DataBundleCard.php">Print Hub settings</a> — select the service type to configure.
  </p>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('printer','#0d6efd','Print Data Card Plans','Manage data bundle card plan pricing and availability.',$h.'DataBundleCard.php?type=data'); ?>
      <?php adm_card('printer','#198754','Print Airtime Card Plans','Manage airtime recharge card plan pricing.',$h.'DataBundleCard.php?type=airtime'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('printer','#dc3545','Print Cable Card Plans','Manage cable TV card plan pricing.',$h.'DataBundleCard.php?type=cable'); ?>
      <?php adm_card('printer','#fd7e14','Print Electric Token Plans','Manage electricity token card plan pricing.',$h.'DataBundleCard.php?type=electric'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('printer','#6f42c1','Print Exam PIN Plans','Manage exam scratch card plan pricing.',$h.'DataBundleCard.php?type=exam'); ?>
      <?php adm_card('printer','#20c997','Print Betting Card Plans','Manage betting card plan pricing.',$h.'DataBundleCard.php?type=betting'); ?>
    </div>
  </div>
</div>

<!-- ── Service Control ────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-toggles me-2"></i>Service Control &amp; Platform Settings</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('toggles','#dc3545','Service Control Center','Enable or disable individual services platform-wide. Disabled services are hidden from users.',$h.'ServiceControl.php','Critical','sm-tag-warn'); ?>
      <?php adm_card('gear','#0d6efd','Account Settings','Manage your admin profile, logo, colors, and platform info.',$h.'AccountSettings.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('credit-card','#198754','Payment Gateway','Configure payment gateway keys (Paystack, Flutterwave, etc.).',$h.'PaymentGateway.php'); ?>
      <?php adm_card('envelope','#fd7e14','Email Templates','Customise transactional email templates sent to users.',$h.'EmailTemplates.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('megaphone','#6f42c1','Status Message','Set a global banner/announcement shown to all users.',$h.'StatusMessage.php'); ?>
      <?php adm_card('star','#20c997','Loyalty Settings','Configure cashback and loyalty points rules.',$h.'LoyaltySettings.php'); ?>
    </div>
  </div>
</div>

<!-- ── NIN Card & Identity ─────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-person-badge me-2"></i>Identity &amp; Verification Services</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('person-badge','#0d6efd','NIN Card Management','View and manage user NIN slip printing requests.',$h.'NINCard.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('fingerprint','#1e3a8a','BVN Verification','View and manage user BVN verification requests.',$h.'BVNVerification.php'); ?>
    </div>
  </div>
</div>

<!-- ── Crypto Hub ─────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-currency-bitcoin me-2"></i>Crypto &amp; Digital Wallets</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('currency-bitcoin','#0d6efd','Crypto Hub Settings','Configure crypto exchange rates, wallet providers and fees.',$h.'CryptoHub.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('receipt','#198754','Crypto Invoices','View and manage crypto payment invoice history.',$h.'CryptoInvoices.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('currency-dollar','#dc3545','Virtual Card Manager','Configure virtual card products and provider integration.',$h.'VirtualCard.php'); ?>
    </div>
  </div>
</div>

<!-- ── Communications ─────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-envelope me-2"></i>Communications</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('envelope-fill','#0d6efd','Send Mail','Send broadcast emails to all users or a specific segment.',$h.'SendMail.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('broadcast','#198754','App Update Broadcast','Push an FCM notification to Android app users about an update.',$h.'AppUpdateBroadcast.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('chat-dots','#dc3545','SMS Sender ID Requests','Review user requests for custom SMS sender IDs.',$h.'SenderIDRequests.php'); ?>
    </div>
  </div>
</div>

<!-- ── Subscriptions ───────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-gem me-2"></i>Subscription Management</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('gem','#0d6efd','Renew Subscription','Renew your platform subscription package.',$h.'RenewSubscription.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('clock-history','#198754','Subscription History','View your subscription renewal history.',$h.'SubscriptionHistory.php'); ?>
    </div>
  </div>
</div>

<!-- ── API Requests & Blog ─────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-code-slash me-2"></i>Developer &amp; Blog</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php adm_card('code-slash','#0d6efd','API Requests Log','View and manage external API request logs and approvals.',$h.'APIRequests.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php adm_card('newspaper','#198754','Blog Posts','Create and manage blog posts visible on the platform.',$h.'BlogPosts.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php adm_card('tag','#dc3545','Blog Categories','Manage blog post categories.',$h.'BlogCategories.php'); ?>
    </div>
  </div>
</div>

</div><!-- /container-fluid -->

<?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
