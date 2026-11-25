<?php
$root = dirname(__DIR__);
require $root . '/DGV7.0-SAAS/func/bc-public-meridian.php';
require $root . '/DGV7.0-SAAS/func/bc-landing-pages.php';
require $root . '/DGV7.0-SAAS/func/bc-public-pages.php';

$n = 0;
function m_check($ok, $label) {
    global $n;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $n++;
}

$_SERVER['HTTP_HOST'] = 'meridian.example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'on';
$connection_server = null;

// 1. Default brand fallback when site_title is empty/unset
$ctx = bc_meridian_context();
m_check($ctx['site_name'] === 'FintechFlow', 'default brand falls back to FintechFlow');
m_check($ctx['short_brand'] === 'FintechFlow', 'short brand falls back to FintechFlow');
m_check(array_key_exists('app_badge_html', $ctx), 'app badge html key present');
m_check(strpos(file_get_contents($root . '/DGV7.0-SAAS/func/bc-public-meridian.php'), 'bc_public_app_badge_html(') !== false, 'meridian resolver calls bc_public_app_badge_html');

// 2. Render Meridian-1 Personal landing page offline
ob_start();
require $root . '/DGV7.0-SAAS/index-bc-style-template-meridian-1.php';
$personal_html = ob_get_clean();
m_check(strpos($personal_html, 'FintechFlow') !== false, 'personal page renders FintechFlow default');
m_check(strpos($personal_html, '/asset/meridian-1/meridian-1.css') !== false, 'personal page loads meridian-1.css');
m_check(strpos($personal_html, '/asset/meridian-1/meridian-theme.css') !== false, 'personal page loads meridian-theme.css');
m_check(strpos($personal_html, '/asset/meridian-1/meridian-1.js') !== false, 'personal page loads meridian-1.js');
m_check(strpos($personal_html, 'data-theme="dark"') !== false && strpos($personal_html, 'data-world="personal"') !== false, 'personal page uses meridian dark glass personal world');
m_check(strpos($personal_html, 'aria-label="Explore more"') !== false, 'personal page includes bc_public_directory_html');
m_check(strpos($personal_html, 'Pending replacement integration') !== false, 'personal page puts retired crypto integration in Coming Soon');

// 2.5 Homepage rearrangement, #topupForm inside #hero, vault copy, removed duo card, shield, and zero-404 Personal feature catalogue links
$hero_pos = strpos($personal_html, 'Everyday top-ups and bill payments, together.');
$topup_pos = strpos($personal_html, 'id="topupForm"');
$cross_pos = strpos($personal_html, 'Move and manage your money across borders with ease.');
m_check($hero_pos !== false && $topup_pos !== false && $cross_pos !== false && $hero_pos < $topup_pos && $topup_pos < $cross_pos, 'Everyday top-ups hero and #topupForm appear before Move and manage your money across borders');
m_check(strpos($personal_html, 'Pick a network, enter a Nigerian mobile number, and preview airtime, mobile data bundles, electricity tokens, cable TV, exam PINs, and travel eSIMs.') !== false, 'hero includes interactive bills & topup lede');
m_check(strpos($personal_html, 'id="bills-ch"') === false, 'duplicate #bills-ch removed after moving #topupForm into #hero');
m_check(strpos($personal_html, 'Real friends.<br>Real progress.') !== false, 'vault section uses Real friends. Real progress.');
m_check(strpos($personal_html, 'Set savings goals and track rewards in one view.') === false, 'old savings card title removed');
m_check(strpos($personal_html, 'Learn More') !== false && strpos($personal_html, 'About savings') === false && strpos($personal_html, 'id="vaultBtn"') === false, 'vault CTA updated to Learn More without manual preview button');
m_check(preg_match('/<section class="chapter" id="vault-ch">.*?<div class="flags"/s', $personal_html) === 0, 'vault section country flags removed');
m_check(strpos($personal_html, 'id="duo-ch"') === false && strpos($personal_html, 'Personal and business workflows, connected.') === false, 'duo interactive preview card removed');
m_check(strpos($personal_html, 'm-shield-core') !== false && strpos($personal_html, 'class="shackle"') === false, 'trust section uses fintech shield instead of padlock/key');

$personal_cat = bc_meridian_service_catalog('personal', 0);
foreach ($personal_cat['categories'] as $cat) {
    foreach ($cat['items'] as $item) {
        $p = parse_url($item['href'], PHP_URL_PATH);
        m_check(is_file($root . '/DGV7.0-SAAS' . $p) && is_file($root . '/DGV7.0-NON-SAAS' . $p), 'personal catalogue target exists in both editions: ' . $p);
    }
}
foreach ($personal_cat['coming_soon'] as $cs) {
    $p = parse_url($cs['href'], PHP_URL_PATH);
    m_check(is_file($root . '/DGV7.0-SAAS' . $p) && is_file($root . '/DGV7.0-NON-SAAS' . $p), 'coming soon target exists in both editions: ' . $p);
}
foreach (array('/services/bank-transfer.php', '/services/share-fund.php', '/services/nin-card.php', '/services/bvn-verification.php', '/web/BankTransfer.php', '/web/BVNVerify.php') as $new_rel) {
    m_check(is_file($root . '/DGV7.0-SAAS' . $new_rel) && is_file($root . '/DGV7.0-NON-SAAS' . $new_rel), 'new endpoint exists in both editions: ' . $new_rel);
}

// 3. Render Meridian-1 Business Dark & Personal subpages offline with active switcher & nav state
$_SERVER['REQUEST_URI'] = '/Business.php';
ob_start();
bc_public_render(bc_landing_context(), 'business');
$business_html = ob_get_clean();
m_check(strpos($business_html, 'data-theme="dark"') !== false && strpos($business_html, 'data-world="business"') !== false, 'business page uses Meridian-1 dark theme');
m_check(strpos($business_html, 'aria-checked="true" tabindex="0" data-w="business"') !== false, 'business page marks Business active in switcher');
m_check(strpos($business_html, 'href="/Business.php" class="is-active" aria-current="page"') !== false, 'business page marks Business nav link active');
m_check(strpos($business_html, 'id="bstory"') !== false, 'business page includes interactive story workspace');
m_check(strpos($business_html, 'id="vtu-api"') !== false, 'business page includes VTU & API guide');
m_check(strpos($business_html, '/Developers.php') !== false && strpos($business_html, '/Resellers.php') !== false, 'business page links developers & resellers');

$_SERVER['REQUEST_URI'] = '/Developers.php';
ob_start();
bc_public_render(bc_landing_context(), 'developers');
$dev_html = ob_get_clean();
m_check(strpos($dev_html, 'data-world="business"') !== false && strpos($dev_html, 'href="/Developers.php" class="is-active" aria-current="page"') !== false, 'Developers page activates business world and Developers nav link');

$_SERVER['REQUEST_URI'] = '/Contact.php';
ob_start();
bc_public_render(bc_landing_context(), 'contact');
$contact_html = ob_get_clean();
m_check(strpos($contact_html, 'data-world="personal"') !== false && strpos($contact_html, 'aria-checked="true" tabindex="0" data-w="personal"') !== false, 'Contact page stays in Personal world with Personal switcher active');
m_check(strpos($contact_html, 'href="/Contact.php" class="is-active" aria-current="page"') !== false, 'Contact page marks Support nav link active');

// 4. ServiceControl gating + Coming Soon + empty-category omission
$crypto_cat = bc_meridian_service_catalog('personal', 0);
$found_crypto_live = false;
foreach ($crypto_cat['categories'] as $cat) {
    m_check(count($cat['items']) > 0, 'rendered live category is non-empty: ' . $cat['id']);
    foreach ($cat['items'] as $it) {
        if (($it['service'] ?? '') === 'crypto_hub') $found_crypto_live = true;
    }
}
m_check(!$found_crypto_live, 'crypto_hub is never live while Plisio replacement is pending');
m_check(count($crypto_cat['coming_soon']) >= 1, 'coming soon contains retired crypto items');

// 5. ReferAndEarn uses dark cards (no bright pastel inline backgrounds)
ob_start();
bc_landing_render_referral(bc_landing_context());
$refer_html = ob_get_clean();
m_check(strpos($refer_html, 'data-theme="dark"') !== false, 'ReferAndEarn uses dark theme');
m_check(strpos($refer_html, '#b8f1cc') === false && strpos($refer_html, '#d9d6fe') === false && strpos($refer_html, '#fde68a') === false, 'ReferAndEarn removed low-contrast inline pastels');

// 5.5. Services.php and services/crypto.php gate crypto_hub into Coming Soon
ob_start();
bc_landing_render_services_index(bc_landing_context());
$services_html = ob_get_clean();
m_check(strpos($services_html, 'Pending replacement integration') !== false, 'Services.php moves crypto_hub to Coming Soon');

ob_start();
bc_landing_render_service(bc_landing_context(), 'crypto');
$crypto_svc_html = ob_get_clean();
m_check(strpos($crypto_svc_html, 'Coming Soon') !== false && strpos($crypto_svc_html, '/web/CryptoHub.php') === false, 'services/crypto.php gates CTA while replacement integration is pending');

// 6. AccountSettings, site-info, and edition parity checks
$shared_files = array(
    'index-bc-style-template-meridian-1.php',
    'func/bc-public-meridian.php',
    'func/bc-public-template.php',
    'func/bc-public-pages.php',
    'func/bc-landing-pages.php',
    'blog.php',
    'single-post.php',
    'Developers.php',
    'Security.php',
    'Resellers.php',
    'web/api/site-info.php',
    'asset/meridian-1/meridian-theme.css',
    'asset/meridian-1/meridian-1.js',
    'cssfile/template/bc-style-template-meridian-1.css',
);
foreach ($shared_files as $rel) {
    m_check(is_file($root . '/DGV7.0-SAAS/' . $rel), 'SAAS has ' . $rel);
    m_check(is_file($root . '/DGV7.0-NON-SAAS/' . $rel), 'NON-SAAS has ' . $rel);
    m_check(
        file_get_contents($root . '/DGV7.0-SAAS/' . $rel) === file_get_contents($root . '/DGV7.0-NON-SAAS/' . $rel),
        'edition parity for ' . $rel
    );
}
foreach (array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS') as $ed) {
    $acct = file_get_contents($root . '/' . $ed . '/bc-admin/AccountSettings.php');
    m_check(strpos($acct, '"bc-style-template-meridian-1.css"') !== false, $ed . ' AccountSettings allows meridian-1');
    m_check(strpos($acct, 'MERIDIAN-1 (DEFAULT)') !== false && strpos($acct, '$style_templates as $tmpl') !== false, $ed . ' AccountSettings select includes meridian-1');
    $si = file_get_contents($root . '/' . $ed . '/web/api/site-info.php');
    m_check(strpos($si, "'FintechFlow'") !== false, $ed . ' site-info falls back to FintechFlow');
    $sp = file_get_contents($root . '/' . $ed . '/single-post.php');
    m_check(strpos($sp, 'data-theme="dark" data-world="personal"') !== false && strpos($sp, 'bc_meridian_footer(') !== false && strpos($sp, 'bootstrap.min.css') === false, $ed . ' single-post uses Meridian personal dark theme without bootstrap CSS conflicts');
    $bl = file_get_contents($root . '/' . $ed . '/blog.php');
    m_check(strpos($bl, 'data-theme="dark" data-world="personal"') !== false && strpos($bl, 'bootstrap.min.css') === false, $ed . ' blog uses Meridian personal dark theme without bootstrap CSS conflicts');
    foreach (array('dormant_user_alert.php', 'ai_daily_briefing.php') as $cron_file) {
        $cron_src = file_get_contents($root . '/' . $ed . '/cron/' . $cron_file);
        m_check(strpos($cron_src, 'instanceof mysqli_result') !== false, $ed . ' cron/' . $cron_file . ' guards mysqli_fetch_assoc');
    }
}

echo "$n meridian template & service gating checks passed\n";
