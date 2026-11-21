<?php
$root = dirname(__DIR__);
require $root . '/DGV7.0-SAAS/func/bc-landing-pages.php';
require $root . '/DGV7.0-SAAS/func/bc-public-pages.php';
$n = 0;
function public_check($ok, $label) { global $n; if (!$ok) throw new RuntimeException('FAIL '.$label); $n++; }
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'on';
$connection_server = null;
ob_start(); require $root . '/DGV7.0-SAAS/index-bc-style-template-home.php'; $home = ob_get_clean();
public_check(strpos($home, 'example.test') !== false, 'home has dynamic branding');
public_check(strpos($home, 'file:///') === false && strpos($home, 'Home_files') === false, 'no missing saved-file URLs');
public_check(strpos($home, 'react.js') === false && strpos($home, 'text/x-dc') === false, 'no editor runtime');
public_check(strpos($home, '[FEE]') === false && strpos($home, '[NN]') === false, 'no placeholder commercial figures');
public_check(strpos($home, '1,950,000.00') === false, 'no frozen quoted conversion');
public_check(strpos($home, 'data-fx-enabled="0"') !== false, 'unconfigured public FX fails closed');
$ctx = bc_landing_context();
$rendered = array('home' => $home);
foreach (bc_public_catalog() as $key => $p) {
    ob_start(); bc_public_render($ctx, $key); $rendered[$key] = ob_get_clean();
    public_check(strpos($rendered[$key], '<h1>'.bc_landing_e($p[0]).'</h1>') !== false, $key.' dedicated content');
    public_check(strpos($rendered[$key], '/Freelancers.php') !== false, $key.' links previous home');
    public_check(strpos($rendered[$key], '/DownloadApp.php') !== false, $key.' links downloads');
    public_check(strpos($rendered[$key], '/ReferAndEarn.php') !== false, $key.' preserves old referral link');
}
public_check(strpos($rendered['download'], 'has not been published') !== false, 'missing download has an honest browser fallback');
foreach (array('javascript:alert(1)', '//evil.test/app', 'http://example.test/app', '/\\evil.test', "https://example.test/\r\napp", 'https://name:pass@example.test/app') as $url) public_check(bc_public_safe_url($url) === '', 'reject unsafe download URL');
foreach (array('/Downloads/app.apk', 'https://play.google.com/store/apps/details?id=test.app') as $url) public_check(bc_public_safe_url($url) === $url, 'allow safe configured URL');
foreach ($rendered as $page => $html) {
    preg_match_all('/(?:href|src)="([^"]+)"/', $html, $matches);
    foreach ($matches[1] as $url) {
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        if (preg_match('~^(https://|#|data:)~', $url)) continue;
        public_check(substr($url, 0, 1) === '/' && substr($url, 0, 2) !== '//', "$page root-relative URL $url");
        foreach (array('DGV7.0-SAAS','DGV7.0-NON-SAAS') as $ed) public_check($url === '/' || is_file($root.'/'.$ed.parse_url($url, PHP_URL_PATH)), "$ed $page destination exists: $url");
    }
}
foreach (array('DGV7.0-SAAS','DGV7.0-NON-SAAS') as $ed) {
    $r = $root.'/'.$ed;
    $index = file_get_contents($r.'/index.php');
    public_check(strpos($index, "? 'index-freelancers-template.php' : 'index-bc-style-template-home.php'") !== false, "$ed explicit home/freelancers routing");
    public_check(strpos(file_get_contents($r.'/Freelancers.php'), "define('BC_PUBLIC_FREELANCERS', true)") !== false, "$ed freelancer entry point");
    public_check(strpos(file_get_contents($r.'/index-freelancers-template.php'), 'id="who"') !== false, "$ed original freelancer section retained");
    foreach (array('index-freelancers-template.php','index-bc-style-template-2.php','index-bc-style-template-3.php','index-bc-style-template-6.php','func/bc-landing-pages.php') as $p) public_check(strpos(file_get_contents($r.'/'.$p), 'bc_public_directory_html()') !== false, "$ed $p includes old and new links");
}
public_check(file_get_contents($root.'/DGV7.0-SAAS/index-bc-style-template-home.php') === file_get_contents($root.'/DGV7.0-NON-SAAS/index-bc-style-template-home.php'), 'home edition parity');
public_check(file_get_contents($root.'/DGV7.0-SAAS/func/bc-public-pages.php') === file_get_contents($root.'/DGV7.0-NON-SAAS/func/bc-public-pages.php'), 'catalogue edition parity');
echo "$n public home/page checks passed\n";
