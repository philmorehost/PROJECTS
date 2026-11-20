<?php
// §41 — dedicated landing page renderer for one service. Thin wrappers in
// services/<slug>.php set $bc_service_slug and include this file, so every
// service gets its own URL with its own copy.
if (!isset($bc_service_slug)) {
    $bc_service_slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['s'] ?? 'airtime')));
}
if (!function_exists('bc_landing_context')) {
    include(__DIR__ . "/func/bc-connect.php");
    require_once(__DIR__ . "/func/bc-landing-pages.php");
}
bc_landing_render_service(bc_landing_context(), $bc_service_slug);
