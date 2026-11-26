<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../../func/bc-connect.php';
require_once __DIR__ . '/../../func/bc-theme.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); echo json_encode(array('status'=>'error')); exit; }
$host=bc_theme_host($_SERVER['HTTP_HOST'] ?? '');
$vendor=null;
if ($host && $connection_server) {
    $www='www.'.$host;
    $stmt=mysqli_prepare($connection_server, 'SELECT v.id, s.primary_color FROM sas_vendors v LEFT JOIN sas_vendor_style_templates s ON s.vendor_id=v.id WHERE (v.website_url=? OR v.website_url=?) AND v.status=1 LIMIT 1');
    if($stmt) {
        mysqli_stmt_bind_param($stmt,'ss',$host,$www);
        if(mysqli_stmt_execute($stmt)) { $q=mysqli_stmt_get_result($stmt); if($q)$vendor=mysqli_fetch_assoc($q); }
        mysqli_stmt_close($stmt);
    }
    if (!$vendor) {
        $q_fb = mysqli_query($connection_server, 'SELECT v.id, s.primary_color FROM sas_vendors v LEFT JOIN sas_vendor_style_templates s ON s.vendor_id=v.id WHERE v.status=1 ORDER BY v.id ASC LIMIT 1');
        if ($q_fb) $vendor = mysqli_fetch_assoc($q_fb);
    }
}
if (!$vendor) { http_response_code(404); echo json_encode(array('status'=>'error','message'=>'Platform not found')); exit; }
echo json_encode(array('status'=>'success','data'=>bc_theme_palette($vendor['primary_color'] ?? null)));
