<?php
require_once __DIR__ . '/func/bc-public-meridian.php';
$ctx = bc_meridian_context($vendor_account_details ?? null);
$site_name = $ctx['site_name'];
$short_brand = $ctx['short_brand'];
$site_e = bc_meridian_e($site_name);
$short_e = bc_meridian_e($short_brand);
$personal_vars = bc_meridian_personal_style_vars($ctx['primary_color']);
$app_badge = $ctx['app_badge_html'];
$js_config = array(
    'siteName' => $site_name,
    'appUrl' => $ctx['app_url'],
    'downloadPageUrl' => '/DownloadApp.php',
    'businessUrl' => '/Business.php',
    'personalUrl' => '/',
    'registerUrl' => '/web/Register.php',
    'loginUrl' => '/web/Login.php',
    'rates' => $ctx['rates'],
);
?><!DOCTYPE html>
<html lang="en" data-theme="dark" data-world="personal" style="<?php echo bc_meridian_e($personal_vars); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo $site_e; ?> | Move and manage your money across borders</title>
<meta name="description" content="<?php echo bc_meridian_e($ctx['site_desc']); ?>">
<link rel="stylesheet" href="/asset/meridian-1/meridian-1.css">
<link rel="stylesheet" href="/asset/meridian-1/meridian-theme.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap">
<?php if (!empty($ctx['logo_url'])): ?>
<link rel="icon" href="<?php echo bc_meridian_e($ctx['logo_url']); ?>">
<?php else: ?>
<link rel="icon" href="/asset/meridian-1/images/fintechflow-mark.svg" type="image/svg+xml">
<?php endif; ?>
<?php
if (is_file(__DIR__ . '/func/bc-seo-head.php') && !empty($ctx['vendor_id']) && ($connection_server ?? null) instanceof mysqli) {
    @include_once __DIR__ . '/func/bc-seo-head.php';
}
?>
<script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config, JSON_UNESCAPED_SLASHES); ?>;</script>
</head>
<body>
<div id="glass-root" style="display:contents">
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="fl-ng" viewBox="0 0 512 512"><g fill-rule="evenodd" stroke-width="1pt"><path fill="#fff" d="M0 0h512v512H0z"/><path fill="#008753" d="M341.3 0H512v512H341.3zM0 0h170.7v512H0z"/></g></symbol>
  <symbol id="fl-gb" viewBox="0 0 512 512"><path fill="#012169" d="M0 0h512v512H0z"/><path fill="#FFF" d="M512 0v64L322 256l190 187v69h-67L254 324 68 512H0v-68l186-187L0 74V0h62l192 188L440 0z"/><path fill="#C8102E" d="m184 324 11 34L42 512H0v-3zm124-12 54 8 150 147v45zM512 0 320 196l-4-44L466 0zM0 1l193 189-59-8L0 49z"/><path fill="#FFF" d="M176 0v512h160V0zM0 176v160h512V176z"/><path fill="#C8102E" d="M0 208v96h512v-96zM208 0v512h96V0z"/></symbol>
  <symbol id="fl-us" viewBox="0 0 512 512"><path fill="#bd3d44" d="M0 0h512v512H0"/><path stroke="#fff" stroke-width="40" d="M0 58h512M0 137h512M0 216h512M0 295h512M0 374h512M0 453h512"/><path fill="#192f5d" d="M0 0h390v275H0z"/></symbol>
  <symbol id="fl-eu" viewBox="0 0 512 512"><path fill="#039" d="M0 0h512v512H0z"/><circle cx="256" cy="256" r="110" fill="none" stroke="#fc0" stroke-width="24" stroke-dasharray="18 39.5"/></symbol>
  <symbol id="fl-ca" viewBox="0 0 512 512"><path fill="#fff" d="M128 0h256v512H128z"/><path fill="#d52b1e" d="M0 0h128v512H0zm384 0h128v512H384z"/><circle cx="256" cy="256" r="64" fill="#d52b1e"/></symbol>
  <symbol id="fl-fr" viewBox="0 0 512 512"><path fill="#fff" d="M0 0h512v512H0z"/><path fill="#000091" d="M0 0h170.7v512H0z"/><path fill="#e1000f" d="M341.3 0H512v512H341.3z"/></symbol>
  <symbol id="fl-za" viewBox="0 0 512 512"><path fill="#007847" d="M0 0h512v512H0z"/><path fill="#e1392d" d="M0 0h512v170H0z"/><path fill="#000c8a" d="M0 342h512v170H0z"/><path fill="#ffb915" d="M0 90l220 166L0 422z"/></symbol>
</svg>

<div class="world" aria-hidden="true"><div class="orb a"></div><div class="orb b"></div><div class="orb c"></div><div class="grain"></div></div>

<?php echo bc_meridian_header_and_menu($ctx, 'personal'); ?>

<div class="dock glass" id="dock" aria-live="polite">
  <div class="m" aria-hidden="true"><span class="m-brand-mark" style="width:26px;height:26px;font-size:12px"><?php echo bc_meridian_e(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $site_name) ?: 'F', 0, 1))); ?></span></div>
  <div><div class="lab">Preview wallet</div><div class="amt" id="dockAmt">₦250,000</div></div>
  <span class="tag demo">Interactive</span>
</div>

<main id="main">
  <section class="hero" id="hero">
    <div class="wrap split">
      <div class="hero-copy">
        <p class="eyebrow"><i></i><?php echo $site_e; ?> · Bills, Top-Ups &amp; Global Money</p>
        <h1>Everyday top-ups and bill payments, together.</h1>
        <p class="lede">Instant airtime, mobile data bundles, electricity tokens, cable TV, exam PINs, betting funding, gift cards, and travel eSIMs in one place.</p>
        <div class="ctas" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
          <?php if ($ctx['registration_open']): ?>
            <a class="btn primary" href="/web/Register.php">Create free account</a>
          <?php else: ?>
            <a class="btn primary" href="/web/Login.php">Sign in to account</a>
          <?php endif; ?>
          <a class="btn ghost" href="/BillsAndAirtime.php">Bills &amp; airtime</a>
          <a class="btn ghost" href="/Services.php">Explore services</a>
          <?php if ($app_badge !== ''): ?>
            <?php echo $app_badge; ?>
          <?php else: ?>
            <a class="btn ghost" href="/DownloadApp.php">Get the app</a>
          <?php endif; ?>
        </div>
        <?php echo bc_meridian_support_bar($ctx); ?>
        <div class="scroll-hint" aria-hidden="true"><i></i>Scroll to explore your wallet tools</div>
      </div>

      <div class="stage-wrap">
        <div class="stage" id="stage">
          <div class="slab"><div class="glass tint"><div class="mesh"></div></div><div class="sweep"></div></div>
          <div class="layer portrait"><img class="hero-customer-photo" src="/asset/meridian-1/images/fintechflow-portrait.webp" alt="<?php echo $site_e; ?> customer" width="640" height="594" decoding="async" fetchpriority="high"></div>
          <div class="phone-glow" id="phoneGlow"></div>
          <div class="float f2"><div class="chipf glass"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M5 20h14"/></svg></div><div><b>Instant Wallet</b><span>Bills, data &amp; peer transfers</span></div></div></div>
          <div class="float f1"><div class="chipf glass"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg></div><div><b>Virtual USD Card</b><span>Global online checkout</span></div></div></div>
          <div class="float f3"><div class="chipf glass"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7h12l-3-3M17 17H5l3 3"/></svg></div><div><b><svg class="flag" aria-hidden="true"><use href="#fl-gb"/></svg> Multi-Currency</b><span>NGN · USD · GBP · EUR</span></div></div></div>
          <div class="hero-wallet glass tint" id="heroWallet" aria-hidden="true">
            <div class="row"><span class="small" style="color:var(--text2)"><?php echo $site_e; ?> wallet</span><span class="tag demo">Preview</span></div>
            <div class="big"><svg class="flag" aria-hidden="true"><use href="#fl-ng"/></svg> ₦250,000.00</div>
            <p class="small" style="color:var(--text2)">Everyday utilities, global transfers, cards and rewards under one roof.</p>
            <div class="acts"><span>Swap</span><span>Send</span><span>Card</span><span>Bills</span></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="chapter" id="cross-border-ch">
    <div class="wrap split">
      <div>
        <div class="chap-head">
          <h2>Move and manage your money across borders with ease.</h2>
          <p class="lede"><?php echo bc_meridian_e($ctx['site_desc']); ?></p>
        </div>
        <div class="flags" aria-hidden="true">
          <svg class="flag"><use href="#fl-ng"/></svg>
          <svg class="flag"><use href="#fl-us"/></svg>
          <svg class="flag"><use href="#fl-gb"/></svg>
          <svg class="flag"><use href="#fl-eu"/></svg>
        </div>
        <div class="chips" style="margin-top:18px">
          <a class="btn primary" href="/Services.php">Explore services</a>
          <a class="btn ghost" href="/MultiCurrencyWallet.php">Multi-currency wallet</a>
          <a class="btn ghost" href="/services/international-transfer.php">International transfers</a>
        </div>
      </div>
      <div class="glass panel stack">
        <div class="row" style="justify-content:space-between;align-items:center">
          <span class="small" style="color:var(--text2)">Global Money Corridors</span>
          <span class="tag demo">Multi-Currency</span>
        </div>
        <div class="kv"><span class="small"><svg class="flag" aria-hidden="true"><use href="#fl-ng"/></svg> Naira Wallet (NGN)</span><span class="num">Instant Bills &amp; Transfers</span></div>
        <div class="kv"><span class="small"><svg class="flag" aria-hidden="true"><use href="#fl-us"/></svg> US Dollar (USD)</span><span class="num">Virtual Cards &amp; Wires</span></div>
        <div class="kv"><span class="small"><svg class="flag" aria-hidden="true"><use href="#fl-gb"/></svg> British Pound (GBP)</span><span class="num">Remittance &amp; Tuition</span></div>
        <div class="kv"><span class="small"><svg class="flag" aria-hidden="true"><use href="#fl-eu"/></svg> Euro (EUR)</span><span class="num">Cross-Border Settlement</span></div>
        <p class="small" style="margin:0;color:var(--text3)">Confirmed FX quotes, recipient verification, and fees are displayed before you confirm in your <?php echo $site_e; ?> account.</p>
      </div>
    </div>
  </section>

  <?php if (bc_meridian_service_enabled('currency_conversion', $ctx['vendor_id'])): ?>
  <section class="chapter" id="swap-ch">
    <div class="wrap split">
      <div class="chap-head">
        <h2>Keep multiple currency balances together.</h2>
        <p class="lede">Preview conversions across Naira, US Dollars, Pounds and Euros before initiating a transaction in your <?php echo $site_e; ?> wallet.</p>
        <div class="flags" aria-hidden="true"><svg class="flag"><use href="#fl-ng"/></svg><svg class="flag"><use href="#fl-us"/></svg><svg class="flag"><use href="#fl-gb"/></svg><svg class="flag"><use href="#fl-eu"/></svg></div>
        <a class="link" href="/MultiCurrencyWallet.php">Explore multi-currency wallet</a>
      </div>
      <div class="glass panel stack">
        <div class="coins" aria-hidden="true">
          <div class="coin glass tint"><span>₦</span><small><svg class="flag"><use href="#fl-ng"/></svg> NGN</small></div>
          <div class="stream" id="stream"></div>
          <div class="coin glass" id="coinTo"><span data-sym="">$</span><small><svg class="flag"><use href="#fl-us"/></svg> USD</small></div>
        </div>
        <div class="field"><label for="swapAmt">Amount <span id="swapAmtLbl" class="num" style="color:var(--text)">₦50,000</span></label><input class="range" id="swapAmt" type="range" min="5000" max="100000" step="5000" value="50000"></div>
        <div class="chips" role="group" aria-label="Swap to" id="swapChips">
          <button type="button" class="chip" aria-pressed="true" data-cur="USD"><svg class="flag"><use href="#fl-us"/></svg>Dollars</button>
          <button type="button" class="chip" aria-pressed="false" data-cur="GBP"><svg class="flag"><use href="#fl-gb"/></svg>Pounds</button>
          <button type="button" class="chip" aria-pressed="false" data-cur="EUR"><svg class="flag"><use href="#fl-eu"/></svg>Euros</button>
        </div>
        <div class="kv"><span class="small">Estimated value</span><span class="num big-out" id="swapOut">$0.00</span></div>
        <div class="note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.01"/></svg><span id="swapRate">Illustrative preview; sign in for confirmed live quotes.</span></div>
        <button type="button" class="btn primary" id="swapBtn" style="width:100%">Preview swap</button>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if (bc_meridian_service_enabled('intl_transfer', $ctx['vendor_id'])): ?>
  <section class="chapter" id="send-ch">
    <div class="wrap split flip">
      <div>
        <div class="chap-head">
          <h2>Send across borders with the details up front.</h2>
          <p class="lede">Choose a corridor to preview international transfers from Lagos to London, New York, Toronto, Paris, or Johannesburg — or pay tuition and suppliers worldwide.</p>
        </div>
        <div class="chips" role="group" aria-label="Destination" id="destChips"></div>
        <div class="chips" style="margin-top:18px">
          <button type="button" class="btn primary" id="sendBtn">Preview ₦20,000 to London</button>
          <button type="button" class="btn ghost" id="transferInfoBtn" aria-expanded="false" aria-controls="transferInfo">Transfer details</button>
          <a class="btn ghost" href="/services/international-transfer.php">Learn more</a>
        </div>
        <p class="small" id="sendStatus" role="status" aria-live="polite"></p>
        <p class="small" id="transferInfo" hidden="">Confirmed FX quotes, recipient checks, and settlement timelines are displayed in your verified <?php echo $site_e; ?> account before you send.</p>
      </div>
      <div class="globe-box" id="homeGlobe"><canvas aria-label="Interactive transfer globe" role="img"></canvas><div class="globe-label glass" id="globeLabel"><svg class="flag"><use href="#fl-ng"/></svg> Lagos to <svg class="flag"><use href="#fl-gb"/></svg> London</div></div>
    </div>
  </section>
  <?php endif; ?>

  <?php if (bc_meridian_service_enabled('virtual_card', $ctx['vendor_id'])): ?>
  <section class="chapter" id="card-ch">
    <div class="wrap split">
      <div>
        <div class="chap-head">
          <h2>Virtual dollar cards for global online spending.</h2>
          <p class="lede">Create a virtual USD card from your <?php echo $site_e; ?> wallet for subscriptions, cloud services, and international checkout with instant freeze controls.</p>
        </div>
        <div class="chips">
          <button type="button" class="btn ghost" id="flipBtn">Flip the card</button>
          <a class="btn primary" href="/services/virtual-cards.php">Explore virtual cards</a>
        </div>
      </div>
      <div class="card-scene">
        <div class="card3d" id="card3d" role="img" aria-label="<?php echo $site_e; ?> virtual USD card">
          <div class="face front"><div class="fill"></div><div class="holo"></div><div class="gl"></div>
            <div class="top"><span class="m-brand-mark" aria-hidden="true"><?php echo bc_meridian_e(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $site_name) ?: 'F', 0, 1))); ?></span><span class="tag">Virtual USD</span></div><div class="chipset"></div>
            <div><div class="pan">•••• •••• •••• 4821</div><div class="bot" style="margin-top:10px"><span><?php echo $short_e; ?><br><b>USD Card</b></span><span style="text-align:right">Exp<br><b>09/29</b></span></div></div>
          </div>
          <div class="face back"><div class="fill"></div><div class="holo"></div><div class="gl"></div><div class="stripe"></div>
            <div class="sig"><div></div><b>•••</b></div>
            <p style="font-size:12px;opacity:.85;margin-top:12px">Card details remain masked until you authenticate in your <?php echo $site_e; ?> account.</p>
          </div>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if (bc_meridian_service_enabled('airtime', $ctx['vendor_id']) || bc_meridian_service_enabled('data', $ctx['vendor_id'])): ?>
  <section class="chapter" id="bills-ch">
    <div class="wrap split flip">
      <div>
        <div class="chap-head">
          <h2>Instant airtime &amp; mobile data preview.</h2>
          <p class="lede">Pick a network, enter a Nigerian mobile number, and preview airtime, mobile data bundles, electricity tokens, cable TV, exam PINs, and travel eSIMs.</p>
        </div>
        <div class="chips">
          <a class="btn primary" href="/BillsAndAirtime.php">Bills &amp; airtime</a>
          <a class="btn ghost" href="/services/gift-cards.php">Gift cards</a>
          <a class="btn ghost" href="/services/esim.php">Travel eSIM</a>
        </div>
      </div>
      <form class="glass panel stack" id="topupForm" novalidate="">
        <div class="chips" role="group" aria-label="Network" id="netChips"><button type="button" class="chip" aria-pressed="true">MTN</button><button type="button" class="chip" aria-pressed="false">Airtel</button><button type="button" class="chip" aria-pressed="false">Glo</button><button type="button" class="chip" aria-pressed="false">9mobile</button></div>
        <div class="field"><label for="phone">Phone number</label><input class="input" id="phone" inputmode="tel" autocomplete="off" value="0803 000 0000"></div>
        <div class="chips" role="group" aria-label="Amount" id="amtChips"><button type="button" class="chip" aria-pressed="true" data-v="1000">₦1,000</button><button type="button" class="chip" aria-pressed="false" data-v="2000">₦2,000</button><button type="button" class="chip" aria-pressed="false" data-v="5000">₦5,000</button></div>
        <div class="result" id="topupRes" aria-live="polite"><svg class="check" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="32" cy="32" r="29"/><path d="M20 33l8 8 16-17"/></svg><span class="small" id="topupMsg">Pick a network and amount, then preview.</span></div>
        <button class="btn primary" type="submit" style="width:100%">Preview top up ₦1,000</button>
      </form>
    </div>
  </section>
  <?php endif; ?>

  <section class="chapter" id="vault-ch">
    <div class="wrap split">
      <div>
        <div class="chap-head">
          <h2>Real friends.<br>Real progress.</h2>
          <p class="lede">Organize funds separately from daily spending and follow your progress alongside <?php echo $site_e; ?> referral and loyalty rewards.</p>
        </div>
        <div class="chips">
          <a class="btn primary" href="/Savings.php">Learn More</a>
        </div>
      </div>
      <div class="jar glass" id="jar" style="--fill:0.24">
        <div class="liquid">
          <svg class="wave" viewBox="0 0 1200 26" preserveAspectRatio="none" aria-hidden="true"><path d="M0 13 Q150 0 300 13 T600 13 T900 13 T1200 13 V26 H0Z" fill="currentColor"/></svg>
          <svg class="wave w2" viewBox="0 0 1200 26" preserveAspectRatio="none" aria-hidden="true"><path d="M0 13 Q150 26 300 13 T600 13 T900 13 T1200 13 V26 H0Z" fill="currentColor"/></svg>
          <div class="bubbles"><i style="left:20%"></i><i style="left:55%;animation-delay:2s"></i><i style="left:75%;animation-delay:3.6s"></i></div>
        </div>
        <div class="readout"><span class="small" style="color:#fff">Goal progress</span><span class="num" id="jarAmt">₦62,000</span><span class="small" style="color:#fff"><?php echo $short_e; ?> Vault</span></div>
        <div class="shine"></div>
      </div>
    </div>
  </section>

  <?php
  echo bc_meridian_render_catalog_section(
      'personal',
      $ctx['vendor_id'],
      'Personal feature catalogue',
      'Live services automatically reflect your Site Owner Service Control Center settings. Disabled or pending services are grouped under Coming Soon.'
  );
  ?>

  <section class="chapter" id="trust">
    <div class="wrap split">
      <div class="lockbox" aria-hidden="true">
        <svg class="rings" viewBox="0 0 400 400" fill="none">
          <g class="ring r1" style="transform-origin:200px 200px"><circle cx="200" cy="200" r="190" stroke="currentColor" stroke-width="2" stroke-dasharray="300 40 60 40" opacity=".55"/></g>
          <g class="ring r2" style="transform-origin:200px 200px"><circle cx="200" cy="200" r="160" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-dasharray="120 30 220 60" opacity=".4"/></g>
          <g class="ring r3" style="transform-origin:200px 200px"><circle cx="200" cy="200" r="130" stroke="currentColor" stroke-width="1.5" stroke-dasharray="4 10" opacity=".7"/></g>
        </svg>
        <div class="core glass tint m-shield-core">
          <svg viewBox="0 0 64 68" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="mShieldGrad" x1="10" y1="6" x2="54" y2="62" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="currentColor" stop-opacity="0.34"/>
                <stop offset="100%" stop-color="currentColor" stop-opacity="0.08"/>
              </linearGradient>
            </defs>
            <path d="M32 5L10 13.5V31.5C10 47.2 19.6 58.6 32 63.5C44.4 58.6 54 47.2 54 31.5V13.5L32 5Z" fill="url(#mShieldGrad)" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
            <path d="M32 11.5L16 17.8V31.2C16 43.3 23.1 52.3 32 56.5C40.9 52.3 48 43.3 48 31.2V17.8L32 11.5Z" stroke="currentColor" stroke-width="1.5" stroke-opacity="0.55" stroke-linejoin="round"/>
            <path d="M23.5 33.5L29.2 39.2L41.5 26.8" stroke="currentColor" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
      </div>
      <div>
        <div class="chap-head">
          <h2>Security and support, built into every step.</h2>
          <p class="lede">Protect your <?php echo $site_e; ?> account with transaction PINs, authenticator / email OTP verification, KYC checks, and direct support channels.</p>
        </div>
        <ul class="feat">
          <li class="glass"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 5-3.5 8.8-7 10-3.5-1.2-7-5-7-10V6z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg></div><div><b>Security PIN &amp; 2FA</b><span>Transaction PIN and configured Google Authenticator or email OTP protection.</span></div></li>
          <li class="glass"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/></svg></div><div><b>Identity Verification</b><span>KYC tier checks to unlock higher limits and global corridors.</span></div></li>
          <li class="glass"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 5-3.5 8.8-7 10-3.5-1.2-7-5-7-10V6z"/></svg></div><div><b>Account Recovery</b><span>Guided recovery and step-up checks when changing devices.</span></div></li>
          <li class="glass"><div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 20l1.1-5.6A8.4 8.4 0 1 1 21 11.5z"/></svg></div><div><b>Responsive Support</b><span>Reach our team via support chat<?php if (!empty($ctx['whatsapp_url'])): ?> or WhatsApp<?php endif; ?>.</span></div></li>
        </ul>
        <div class="chips" style="margin-top:16px">
          <a class="btn ghost" href="/Security.php">Security details</a>
          <a class="btn ghost" href="/Contact.php">Contact support</a>
        </div>
      </div>
    </div>
  </section>

  <section class="chapter" id="download">
    <div class="wrap">
      <div class="finale glass tint">
        <div class="row" style="align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap">
          <div class="stack" style="flex:1;min-width:260px">
            <h2>Get started with <?php echo $site_e; ?> today.</h2>
            <p class="lede">Use the mobile app or log into the web dashboard for instant top-ups, bills, transfers, and virtual cards.</p>
            <div class="chips" style="margin-top:18px;align-items:center">
              <?php if ($ctx['registration_open']): ?>
                <a class="btn primary" href="/web/Register.php">Create free account</a>
              <?php endif; ?>
              <a class="btn ghost" href="/web/Login.php">Log in</a>
              <?php if ($app_badge !== ''): ?>
                <?php echo $app_badge; ?>
              <?php else: ?>
                <a class="btn ghost" href="/DownloadApp.php">App download info</a>
              <?php endif; ?>
              <button type="button" class="btn ghost" id="resetBtn">Reset preview</button>
            </div>
          </div>
          <div style="flex:0 0 auto;text-align:center">
            <img src="/asset/meridian-1/images/spark.webp" alt="<?php echo $site_e; ?> assistant" width="180" height="180" loading="lazy" decoding="async" style="border-radius:24px">
          </div>
        </div>
      </div>
    </div>
  </section>
</main>

<?php echo bc_meridian_footer($ctx); ?>

<button type="button" class="pill glass" id="pill" data-open-get="1">
  <span id="pillTxt"><?php echo !empty($ctx['app_url']) ? 'Get the app' : 'Create account'; ?></span>
  <span class="btn primary" style="min-height:40px;padding:0 14px" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
  </span>
</button>
</div>
<script src="/asset/meridian-1/meridian-1.js"></script>
</body>
</html>
