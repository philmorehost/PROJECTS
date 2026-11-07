<?php
session_start();
include("../func/bc-spadmin-config.php");
$page_title = "Super Admin Platform Guide & Site Map";
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
}
</style>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>
<div class="pagetitle">
  <h1>Super Admin Guide &amp; Site Map</h1>
  <nav>
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
      <li class="breadcrumb-item active">Site Map</li>
    </ol>
  </nav>
</div>

<section class="sitemap-hero">
  <div class="container-fluid">
    <h1><i class="bi bi-grid-3x3-gap me-2"></i>Super Admin Platform Guide</h1>
    <p>A complete self-help reference for all super-admin functions. Click any card to access that page directly.</p>
  </div>
</section>

<div class="container-fluid">
<?php
function spa_card($icon, $icon_bg, $title, $desc, $url, $tag = null, $tag_class = '') {
    $tagHtml = $tag ? "<span class=\"sm-tag $tag_class\">$tag</span>" : '';
    echo "<a href=\"" . htmlspecialchars($url) . "\" class=\"sm-card\">";
    echo "<div class=\"sm-card-icon\" style=\"background:$icon_bg\"><i class=\"bi bi-$icon\" style=\"color:#fff\"></i></div>";
    echo "<div class=\"sm-card-body\"><h5>" . htmlspecialchars($title) . "</h5><p>" . htmlspecialchars($desc) . "</p>$tagHtml</div>";
    echo "</a>";
}
$h = $web_http_host . '/bc-spadmin/';
?>

<!-- ── Overview ───────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-grid me-2"></i>Overview</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('grid','#0d6efd','Dashboard','Platform-wide overview: total vendors, revenue, and activity.',$h.'Dashboard.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('graph-up','#198754','Subscription Reports','View subscription statistics across all vendors.',$h.'SubscriptionReports.php'); ?>
    </div>
  </div>
</div>

<!-- ── Vendor Management ──────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-building me-2"></i>Vendor Management</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('people','#0d6efd','All Vendors','View and manage all registered platform vendors.',$h.'Users.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('arrow-up-circle','#198754','User Upgrade','Upgrade a vendor account level or plan.',$h.'UserUpgrade.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php spa_card('pencil','#dc3545','Edit Vendor','Edit vendor account details and settings.',$h.'UserEdit.php'); ?>
    </div>
  </div>
</div>

<!-- ── Billing & Subscriptions ────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-gem me-2"></i>Billing &amp; Subscriptions</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('gem','#0d6efd','Billing Packages','Create and manage subscription plans available to vendors.',$h.'BillingPackages.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('receipt','#198754','All Subscriptions','View all active and expired vendor subscriptions.',$h.'AllSubscriptions.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php spa_card('pencil-square','#dc3545','Edit Billing','Edit an existing billing package.',$h.'BillingEdit.php'); ?>
    </div>
  </div>
</div>

<!-- ── Marketplace & APIs ─────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-code-slash me-2"></i>Marketplace &amp; APIs</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('shop','#0d6efd','Marketplace','Manage the API marketplace available to all bc-admin vendors.',$h.'MarketPlace.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('plus-circle','#198754','Create API','Add a new API product to the marketplace.',$h.'CreateAPI.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php spa_card('pencil','#dc3545','Edit API','Edit an existing marketplace API product.',$h.'ApiEdit.php'); ?>
    </div>
  </div>
</div>

<!-- ── Finance ────────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-cash-stack me-2"></i>Finance</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('receipt','#0d6efd','All Transactions','Browse all transactions across all vendors.',$h.'Transactions.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('arrow-left-right','#198754','Share Fund','Transfer funds between vendor accounts.',$h.'ShareFund.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php spa_card('cash-coin','#dc3545','Payment Orders','View payment orders submitted by vendors.',$h.'PaymentOrders.php'); ?>
    </div>
  </div>
</div>

<!-- ── KYC ────────────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-person-badge me-2"></i>KYC &amp; Identity</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('person-check','#0d6efd','KYC Management','Review and approve/reject KYC submissions from all vendors.',$h.'KYCManagement.php','Important','sm-tag-warn'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('slash-circle','#dc3545','Unblock Requests','Process account unblock requests from users.',$h.'UnblockRequests.php'); ?>
    </div>
  </div>
</div>

<!-- ── Crypto & Settings ──────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-currency-bitcoin me-2"></i>Crypto &amp; Settings</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('currency-bitcoin','#0d6efd','Crypto Hub','Configure platform-wide crypto settings.',$h.'CryptoHub.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('credit-card','#198754','Payment Gateway','Configure global payment gateway keys.',$h.'PaymentGateway.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php spa_card('globe','#dc3545','Domain Settings','Manage vendor domain and subdomain settings.',$h.'DomainSettings.php'); ?>
    </div>
  </div>
</div>

<!-- ── Communications ─────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-envelope me-2"></i>Communications</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('envelope-fill','#0d6efd','Send Mail','Broadcast email to all vendors or specific segments.',$h.'SendMail.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('megaphone','#198754','Status Message','Set a global platform announcement.',$h.'StatusMessage.php'); ?>
    </div>
    <div class="col-md-4 ps-md-2">
      <?php spa_card('envelope','#dc3545','Email Templates','Manage system-wide transactional email templates.',$h.'EmailTemplates.php'); ?>
    </div>
  </div>
</div>

<!-- ── Account Settings ───────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-gear me-2"></i>Account Settings</h4>
  <div class="row g-0">
    <div class="col-md-4 pe-md-2">
      <?php spa_card('gear','#0d6efd','Account Settings','Update super admin profile and platform-wide settings.',$h.'AccountSettings.php'); ?>
    </div>
    <div class="col-md-4 px-md-2">
      <?php spa_card('cart3','#198754','Cart / Orders','View and manage your own orders and subscriptions.',$h.'Cart.php'); ?>
    </div>
  </div>
</div>

</div><!-- /container-fluid -->

<?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
