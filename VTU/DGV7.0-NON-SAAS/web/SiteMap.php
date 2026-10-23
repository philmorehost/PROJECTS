<?php
session_start();
include("../func/bc-config.php");

function svc_enabled($svc) {
    return isServiceEnabled($svc);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Services Guide &amp; Site Map | <?php echo $get_all_site_details["site_title"] ?? ''; ?></title>
<link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
<link rel="stylesheet" href="/cssfile/bc-style.css">
<link href="https://fonts.gstatic.com" rel="preconnect">
<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">
<link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
<link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
<link href="../assets-2/css/style.css" rel="stylesheet">
<style>
.sitemap-hero{background:linear-gradient(135deg,var(--primary-color) 0%,#1a237e 100%);color:#fff;padding:3rem 1.5rem 2rem;border-radius:0 0 2rem 2rem;}
.sitemap-hero h1{font-size:2rem;font-weight:800;margin-bottom:.5rem;}
.sitemap-hero p{opacity:.9;font-size:1.05rem;}
.sm-section{background:#fff;border-radius:1rem;box-shadow:0 2px 16px rgba(0,0,0,.07);padding:1.5rem;margin-bottom:1.5rem;}
.sm-section h4{font-weight:700;font-size:1rem;color:var(--primary-color);text-transform:uppercase;letter-spacing:.06rem;border-bottom:2px solid var(--primary-color);padding-bottom:.5rem;margin-bottom:1.2rem;}
.sm-card{display:flex;align-items:flex-start;gap:1rem;padding:1rem;border:1px solid #e9ecef;border-radius:.75rem;transition:box-shadow .2s,transform .2s;text-decoration:none;color:inherit;background:#fafafa;margin-bottom:.85rem;}
.sm-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.12);transform:translateY(-2px);text-decoration:none;color:inherit;}
.sm-card.disabled{opacity:.45;pointer-events:none;cursor:not-allowed;}
.sm-card-icon{width:48px;height:48px;border-radius:.75rem;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;}
.sm-card-body h5{margin:0 0 .2rem;font-size:.95rem;font-weight:700;}
.sm-card-body p{margin:0;font-size:.82rem;color:#6c757d;line-height:1.4;}
.badge-off{background:#e9ecef;color:#888;font-size:.7rem;border-radius:1rem;padding:.15rem .5rem;margin-left:.5rem;vertical-align:middle;}
.sm-tag{display:inline-block;background:rgba(var(--bs-primary-rgb),.1);color:var(--primary-color);font-size:.7rem;border-radius:1rem;padding:.1rem .5rem;margin-top:.3rem;font-weight:600;}
@media (max-width:575px){
  .sm-card{flex-direction:column;align-items:flex-start;}
  .sm-card-icon{width:40px;height:40px;font-size:1.2rem;}
}
</style>
</head>
<body>
<?php include("../func/bc-header.php"); ?>
<section class="sitemap-hero mb-4">
  <div class="container">
    <h1><i class="bi bi-grid-3x3-gap me-2"></i>Services &amp; Features Guide</h1>
    <p>All available services on this platform. Click any card to access it directly.</p>
  </div>
</section>

<div class="container">

<?php
// Helper to render a service card
function sm_card($icon, $icon_bg, $title, $desc, $url, $service_key = null, $tag = null) {
    $enabled = ($service_key === null) ? true : isServiceEnabled($service_key);
    $cls = $enabled ? '' : ' disabled';
    $badge = $enabled ? '' : '<span class="badge-off">Unavailable</span>';
    $tagHtml = $tag ? "<span class=\"sm-tag\">$tag</span>" : '';
    echo "<a href=\"" . ($enabled ? htmlspecialchars($url) : '#') . "\" class=\"sm-card$cls\">";
    echo "<div class=\"sm-card-icon\" style=\"background:$icon_bg\"><i class=\"bi bi-$icon\" style=\"color:#fff\"></i></div>";
    echo "<div class=\"sm-card-body\"><h5>" . htmlspecialchars($title) . "$badge</h5><p>" . htmlspecialchars($desc) . "</p>$tagHtml</div>";
    echo "</a>";
}
?>

<!-- ── Wallet & Finance ──────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-wallet2 me-2"></i>Wallet &amp; Finance</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('wallet2','#0d6efd','My Dashboard','View account balance, quick stats and recent transactions.','/web/Dashboard.php'); ?>
      <?php sm_card('cash-stack','#198754','Fund Wallet','Top up your wallet via bank transfer, card or USSD.','/web/Fund.php','bank_transfer'); ?>
      <?php sm_card('arrow-up-circle','#dc3545','Withdraw','Withdraw funds directly to any Nigerian bank account.','/web/Withdrawals.php','payout'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('arrow-left-right','#fd7e14','Transfer to User','Send funds instantly to another user on the platform.','/web/SendFund.php','bank_transfer'); ?>
      <?php sm_card('clock-history','#6f42c1','Transaction History','View all your transactions with filters and export.','/web/Transactions.php'); ?>
      <?php sm_card('calculator','#20c997','Transaction Calculator','Estimate costs and profits before making a purchase.','/web/TransactionCalculator.php'); ?>
    </div>
  </div>
</div>

<!-- ── VTU Services ──────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-phone me-2"></i>VTU Services</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('phone','#0d6efd','Buy Airtime','Instantly recharge any Nigerian network (MTN, Airtel, Glo, 9mobile).','/web/Airtime.php','airtime'); ?>
      <?php sm_card('wifi','#198754','Buy Data Bundle','Purchase data plans: SME, CG, Direct Data and Shared Data.','/web/Data.php','data'); ?>
      <?php sm_card('tv','#dc3545','Cable TV Subscription','Pay DStv, GOtv or Startimes subscription easily.','/web/Cable.php','cable'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('lightning-charge','#fd7e14','Electricity Token','Purchase prepaid electricity tokens for any DISCOS.','/web/Electric.php','electric'); ?>
      <?php sm_card('pencil-square','#6f42c1','Exam Scratch Cards','Buy WAEC, NECO and NABTEB result checker PINs.','/web/Exam.php','exam'); ?>
      <?php sm_card('trophy','#20c997','Betting Wallet Funding','Fund Bet9ja, BetKing, 1xBet and other betting wallets.','/web/Betting.php','betting'); ?>
    </div>
  </div>
</div>

<!-- ── Print Hub ─────────────────────────────────────────────────── -->
<?php if (svc_enabled('data_card')): ?>
<div class="sm-section">
  <h4><i class="bi bi-printer me-2"></i>Print Hub — Card Printing</h4>
  <p class="text-muted small mb-3">Generate printable E-PIN cards for bulk distribution or reselling.</p>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('printer','#0d6efd','Print Data Cards','Generate data bundle cards ready to print and sell.','/web/PrintHub.php?type=data','print_data','Card Printing'); ?>
      <?php sm_card('printer','#198754','Print Airtime Cards','Generate recharge PIN cards for all networks.','/web/PrintHub.php?type=airtime','print_airtime','Card Printing'); ?>
      <?php sm_card('printer','#dc3545','Print Cable Cards','Generate DStv/GOtv subscription PINs for printing.','/web/PrintHub.php?type=cable','print_cable','Card Printing'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('printer','#fd7e14','Print Electricity Tokens','Generate prepaid electricity tokens for distribution.','/web/PrintHub.php?type=electric','print_electric','Card Printing'); ?>
      <?php sm_card('printer','#6f42c1','Print Exam PINs','Generate WAEC/NECO scratch card PINs in bulk.','/web/PrintHub.php?type=exam','print_exam','Card Printing'); ?>
      <?php sm_card('printer','#20c997','Print Betting Cards','Generate betting wallet funding cards.','/web/PrintHub.php?type=betting','print_betting','Card Printing'); ?>
      <?php sm_card('person-badge','#0d6efd','Digital NIN Slip','Generate a printable digital NIN slip using an 11-digit NIN.','/web/NINCard.php','nin_card','Identity'); ?>
      <?php sm_card('fingerprint','#1e3a8a','BVN Verification','Verify a Bank Verification Number and retrieve identity details.','/web/BVNVerification.php','bvn_verify','Identity'); ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── NIN Card ──────────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-person-badge me-2"></i>Identity Services</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('person-badge','#0d6efd','NIN Slip Printing','Print a digital NIN slip using your 11-digit NIN number.','/web/NINCard.php','nin_card'); ?>
      <?php sm_card('clock-history','#198754','NIN Card History','View all your previous NIN slip requests.','/web/NINCardHistory.php','nin_card'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('fingerprint','#1e3a8a','BVN Verification','Instantly verify any Bank Verification Number and retrieve identity details.','/web/BVNVerification.php','bvn_verify'); ?>
    </div>
  </div>
</div>

<!-- ── Cards & Crypto ────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-credit-card me-2"></i>Cards &amp; Digital Assets</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('gift','#dc3545','Gift Cards','Buy international gift cards (Amazon, Steam, iTunes, etc.).','/web/GiftCard.php','gift_card'); ?>
      <?php sm_card('credit-card','#fd7e14','Virtual Dollar Card','Get a virtual Visa/Mastercard for online purchases.','/web/VirtualCard.php','virtual_card'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('currency-bitcoin','#6f42c1','Crypto Hub','Buy, sell and swap cryptocurrencies instantly.','/web/CryptoHub.php','crypto_hub'); ?>
    </div>
  </div>
</div>

<!-- ── Bulk Services ─────────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-broadcast me-2"></i>Bulk &amp; Business Services</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('chat-dots','#0d6efd','Bulk SMS','Send promotional or transactional SMS to thousands at once.','/web/BulkSMS.php','bulk_sms'); ?>
      <?php sm_card('people','#198754','Bulk Airtime Top-Up','Send airtime to multiple numbers in a single action.','/web/BulkAirtime.php','airtime'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('database','#dc3545','Bulk Data Purchase','Distribute data to multiple numbers at once.','/web/BulkData.php','data'); ?>
    </div>
  </div>
</div>

<!-- ── Account & Settings ────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-gear me-2"></i>Account &amp; Settings</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('gear','#6c757d','Account Settings','Update profile, change password and manage preferences.','/web/AccountSettings.php'); ?>
      <?php sm_card('shield-lock','#0d6efd','Security PIN','Set or change your 4-digit transaction security PIN.','/web/SecurityPIN.php'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('key','#198754','Security Question','Set a recovery question for account protection.','/web/SecurityQuest.php'); ?>
      <?php sm_card('person-check','#fd7e14','KYC Verification','Verify your identity to unlock higher limits.','/web/KYCVerification.php'); ?>
    </div>
  </div>
</div>

<!-- ── Orders & History ──────────────────────────────────────────── -->
<div class="sm-section">
  <h4><i class="bi bi-receipt me-2"></i>Orders &amp; History</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('receipt','#0d6efd','Payment Orders','Track all pending and completed payment orders.','/web/PaymentOrders.php'); ?>
      <?php sm_card('clock-history','#198754','Data Card History','View history of all your printed data/recharge cards.','/web/DataBundleCardHistory.php'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('share','#dc3545','Share Fund History','Track all fund transfers made to other users.','/web/ShareFundHistory.php'); ?>
      <?php sm_card('bar-chart','#6f42c1','Sales Calculator','Calculate your potential profit from reselling.','/web/SalesCalculator.php'); ?>
    </div>
  </div>
</div>

<!-- ── Developer API ─────────────────────────────────────────────── -->
<?php if (($get_logged_user_details['account_level'] ?? 1) >= 2): ?>
<div class="sm-section">
  <h4><i class="bi bi-code-slash me-2"></i>Developer &amp; API</h4>
  <div class="row g-0">
    <div class="col-md-6 pe-md-2">
      <?php sm_card('code-slash','#0d6efd','API Documentation','Complete guide to integrate our services via REST API.','/web/APIDocs.php'); ?>
    </div>
    <div class="col-md-6 ps-md-2">
      <?php sm_card('key','#198754','API Keys & Settings','Manage your API key and enable/disable API access.','/web/AccountSettings.php'); ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /container -->

<?php include("../func/bc-footer.php"); ?>
</body>
</html>
