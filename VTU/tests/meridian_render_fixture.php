<?php
$root = dirname(__DIR__);
require $root . '/DGV7.0-SAAS/func/bc-public-meridian.php';
require $root . '/DGV7.0-SAAS/func/bc-landing-pages.php';
require $root . '/DGV7.0-SAAS/func/bc-public-pages.php';

$_SERVER['HTTP_HOST'] = 'meridian.example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'on';
$connection_server = null;

ob_start();
require $root . '/DGV7.0-SAAS/index-bc-style-template-meridian-1.php';
$personal = ob_get_clean();

// Stress test dynamic bright yellow accent (#EAB308) on Personal page
$yellow_vars = bc_meridian_personal_style_vars('#EAB308');
$personal_yellow = preg_replace('/style="[^"]*"/', 'style="' . bc_meridian_e($yellow_vars) . '"', $personal, 1);

$ctx = bc_landing_context();
$_SERVER['REQUEST_URI'] = '/Business.php';
ob_start();
bc_public_render($ctx, 'business');
$business = ob_get_clean();

$_SERVER['REQUEST_URI'] = '/ReferAndEarn.php';
ob_start();
bc_landing_render_referral($ctx);
$refer = ob_get_clean();

$_SERVER['REQUEST_URI'] = '/Services.php';
ob_start();
bc_landing_render_services_index($ctx);
$services = ob_get_clean();

$_SERVER['REQUEST_URI'] = '/Contact.php';
ob_start();
bc_public_render($ctx, 'contact');
$contact = ob_get_clean();

$_SERVER['REQUEST_URI'] = '/Developers.php';
ob_start();
bc_public_render($ctx, 'developers');
$developers = ob_get_clean();

$_SERVER['REQUEST_URI'] = '/services/airtime.php';
ob_start();
bc_landing_render_service($ctx, 'airtime');
$service_airtime = ob_get_clean();

$_SERVER['REQUEST_URI'] = '/Privacy.php';
ob_start();
bc_landing_render_legal($ctx, 'privacy');
$privacy = ob_get_clean();

echo json_encode(array(
    'personal' => $personal,
    'personal_yellow' => $personal_yellow,
    'business' => $business,
    'refer' => $refer,
    'services' => $services,
    'contact' => $contact,
    'developers' => $developers,
    'service_airtime' => $service_airtime,
    'privacy' => $privacy,
));
