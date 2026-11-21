<?php
// Render shared public pages offline; verify every emitted local link in both editions.
$base = dirname(__DIR__);
require $base . '/DGV7.0-SAAS/func/bc-landing-pages.php';
$checks = 0;
function link_check($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++;
}
link_check(file_get_contents($base.'/DGV7.0-SAAS/func/bc-landing-pages.php') === file_get_contents($base.'/DGV7.0-NON-SAAS/func/bc-landing-pages.php'), 'shared renderer parity');
$ctx = bc_landing_context();
$pages = array('services', 'referral', 'privacy', 'terms');
foreach (array_keys(bc_landing_services()) as $slug) $pages[] = $slug;
foreach ($pages as $page) {
    ob_start();
    if ($page === 'services') bc_landing_render_services_index($ctx);
    elseif ($page === 'referral') bc_landing_render_referral($ctx);
    elseif ($page === 'privacy' || $page === 'terms') bc_landing_render_legal($ctx, $page);
    else bc_landing_render_service($ctx, $page);
    $html = ob_get_clean();
    preg_match_all('/href="([^"]+)"/', $html, $matches);
    foreach ($matches[1] as $url) {
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        if (preg_match('~^(https?://|#)~', $url)) continue;
        link_check(substr($url, 0, 1) === '/' && substr($url, 0, 2) !== '//', "$page root-relative link: $url");
        link_check(strpos($url, '/services/services/') === false && strpos($url, '/services/web/') === false, "$page no nested path: $url");
        foreach (array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS') as $edition) {
            $target = parse_url($url, PHP_URL_PATH);
            link_check($target === '/' || is_file($base.'/'.$edition.$target), "$edition $page destination exists: $url");
        }
    }
    link_check(strpos($html, 'href="/blog.php"') !== false, "$page root blog link");
    link_check(strpos($html, 'href="/ReferAndEarn.php"') !== false, "$page public referral link");
    link_check(strpos($html, 'href="/services/CoinConversion.php"') !== false, "$page requested coin conversion URL");
    link_check(strpos($html, 'href="/web/Referral.php"') === false, "$page no obsolete referral target");
}
foreach (array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS') as $edition) {
    $alias = file_get_contents($base.'/'.$edition.'/services/CoinConversion.php');
    link_check(strpos($alias, "header('Location: /web/CoinConversion.php', true, 302)") !== false, "$edition coin alias delegates to existing authenticated route");
    link_check(strpos($alias, 'exit;') !== false, "$edition redirect stops execution");
}
echo "$checks public landing link checks passed\n";
