<?php
$root = dirname(__DIR__);

$n = 0;
function t6_check($ok, $label) {
    global $n;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $n++;
}

$GLOBALS['t6_test_disabled'] = array();
function isServiceEnabled($name, $vid = null) {
    return !in_array(strtolower(trim((string)$name)), $GLOBALS['t6_test_disabled'], true);
}

$_SERVER['HTTP_HOST'] = 'plate.example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'on';
$connection_server = null;

// 1. Edition parity & distinctness from Templates 2 and 3
t6_check(
    file_get_contents($root . '/DGV7.0-SAAS/index-bc-style-template-6.php') ===
    file_get_contents($root . '/DGV7.0-NON-SAAS/index-bc-style-template-6.php'),
    'index-bc-style-template-6.php matches across SAAS and NON-SAAS'
);
$hashes = array(
    hash_file('sha256', $root . '/DGV7.0-SAAS/index-bc-style-template-2.php'),
    hash_file('sha256', $root . '/DGV7.0-SAAS/index-bc-style-template-3.php'),
    hash_file('sha256', $root . '/DGV7.0-SAAS/index-bc-style-template-6.php'),
);
t6_check(count(array_unique($hashes)) === 3, 'template 6 has unique SHA-256 distinct from templates 2 and 3');

// 2. Render offline with all services enabled
ob_start();
require $root . '/DGV7.0-SAAS/index-bc-style-template-6.php';
$html = ob_get_clean();
while (ob_get_level() > 0) {
    $inner = ob_get_clean();
    if ($inner !== false) $html .= $inner;
}

t6_check(strpos($html, 'backdrop-filter: blur(') !== false, 'uses modern glassmorphism styling');
t6_check(strpos($html, 'Warning:') === false && strpos($html, 'Undefined array key') === false && strpos($html, 'foreach() argument') === false, 'renders cleanly with zero PHP warnings');
t6_check(strpos($html, 'USD Wallet') !== false, 'renders Plate personal partition cards cleanly');
t6_check(strpos($html, 'pk-hero') !== false && strpos($html, 't6-deck') !== false, 'uses obsidian plate + deck layout');
t6_check(strpos($html, 'id="pswitch"') !== false && strpos($html, 'data-w="personal"') !== false && strpos($html, 'data-w="business"') !== false, 'includes Meridian-1 Personal/Business switcher');
t6_check(strpos($html, '.m-brand-mark') !== false && strpos($html, '.pswitch button[aria-checked="true"]') !== false && strpos($html, '.menu-btn { display: none; }') !== false, 'styles Meridian-1 brand mark, active switcher pill contrast, and desktop menu button');
t6_check(strpos($html, 'window.MERIDIAN_CONFIG') !== false, 'wires MERIDIAN_CONFIG');

// Meridian-1 menu items
foreach (array(
    '>Home</a>',
    '>Services</a>',
    '>Business</a>',
    '>Refer &amp; Earn</a>',
    '>Security</a>',
    '>Support</a>',
    '>Personal Home</a>',
    '>All Services</a>',
    '>Developers &amp; API</a>',
    '>Merchant &amp; Resellers</a>',
    '>Get the App</a>',
) as $menu_needle) {
    t6_check(strpos($html, $menu_needle) !== false, 'menu includes Meridian-1 item: ' . $menu_needle);
}

// 3. Personal Features categories & items
foreach (array(
    'Personal Features',
    'Accounts &amp; Payments',
    'Everyday Services',
    'Cards &amp; Digital Assets',
    'Rewards &amp; Tools',
    'Security &amp; Support',
    'Personal wallet and account dashboard',
    'Wallet funding and payment receipts',
    'Local transfers and wallet-to-wallet transfers',
    'International transfer requests',
    'Virtual bank accounts',
    'Transaction history and payment tracking',
    'Live currency conversion and indicative FX rates',
    'Airtime purchase',
    'Mobile data purchase',
    'Electricity bill payments',
    'Cable TV subscriptions',
    'Betting account funding',
    'Examination PINs',
    'Travel eSIM services',
    'Tuition payment requests',
    'Virtual dollar cards',
    'Gift card services',
    'Crypto Buy/Sell and stablecoin services',
    'Referral programme',
    'Loyalty points and rewards',
    'Transaction calculator',
    'AI assistant and budgeting tools',
    'Identity verification—KYC',
    'Security PIN',
    'Google Authenticator and email OTP options',
    'Account recovery and security settings',
    'Customer support and dispute assistance',
) as $feature_needle) {
    t6_check(strpos($html, $feature_needle) !== false, 'renders Personal Feature text: ' . $feature_needle);
}

t6_check(strpos($html, 'id="coming-soon"') !== false && strpos($html, 'Pending replacement integration') !== false, 'crypto_hub is routed to Coming Soon');

// All internal links exist in both SAAS and NON-SAAS
preg_match_all('/href="([^"]+)"/', $html, $matches);
foreach (array_unique($matches[1]) as $url) {
    $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
    if (preg_match('~^(https://|#|mailto:|tel:)~', $url)) continue;
    $path = parse_url($url, PHP_URL_PATH);
    t6_check($path === '/' || (is_file($root . '/DGV7.0-SAAS' . $path) && is_file($root . '/DGV7.0-NON-SAAS' . $path)), 'destination exists in both editions: ' . $path);
}

// 4. Verify Service Control Center gating dynamically moves disabled services into Coming Soon
$GLOBALS['t6_test_disabled'] = array('airtime', 'virtual_card', 'virtual_bank_display', 'ai_suite', 'password_reset');
ob_start();
require $root . '/DGV7.0-SAAS/index-bc-style-template-6.php';
$gated_html = ob_get_clean();
while (ob_get_level() > 0) {
    $inner = ob_get_clean();
    if ($inner !== false) $gated_html .= $inner;
}

$cs_pos = strpos($gated_html, 'id="coming-soon"');
t6_check($cs_pos !== false, 'coming-soon section rendered for disabled services');
$active_section = substr($gated_html, 0, $cs_pos);
$cs_section = substr($gated_html, $cs_pos);
t6_check(strpos($active_section, 'Airtime purchase') === false && strpos($cs_section, 'Airtime purchase') !== false, 'disabled airtime moved to Coming Soon');
t6_check(strpos($active_section, 'Virtual dollar cards') === false && strpos($cs_section, 'Virtual dollar cards') !== false, 'disabled virtual_card moved to Coming Soon');
t6_check(strpos($active_section, 'Virtual bank accounts') === false && strpos($cs_section, 'Virtual bank accounts') !== false, 'disabled virtual_bank_display moved to Coming Soon');
t6_check(strpos($active_section, 'AI assistant and budgeting tools') === false && strpos($cs_section, 'AI assistant and budgeting tools') !== false, 'disabled ai_suite moved to Coming Soon');

echo "$n template-6 glassmorphism & service control checks passed\n";
