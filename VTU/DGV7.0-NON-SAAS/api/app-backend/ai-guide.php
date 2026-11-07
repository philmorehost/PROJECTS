<?php
/**
 * Mobile AI Guide — Contextual Tips for App Pages
 * DGV6.90 AI Edition
 *
 * Returns page-specific AI tips for the mobile app.
 * Same 24h caching as web/ai-guide-cache.php.
 *
 * GET  /api/app-backend/ai-guide.php?page=airtime&lang=en
 * Headers: Authorization: Bearer <token>
 */

header('Content-Type: application/json');
include_once('../../func/bc-connect.php');
include_once('../../func/bc-ai-engine.php');
require_once('app-config.php');

function guide_json(int $code, array $data): never { http_response_code($code); echo json_encode($data); exit; }

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) guide_json(401, ['success'=>false,'error'=>'Auth required']);
$tok = mysqli_real_escape_string($connection_server, trim($m[1]));
$u   = mysqli_fetch_assoc(mysqli_query($connection_server,
    "SELECT u.*, v.id vid, v.ai_status FROM sas_users u JOIN sas_vendors v ON v.id=u.vendor_id WHERE u.api_token='$tok' AND u.status=1 LIMIT 1"));
if (!$u) guide_json(401, ['success'=>false,'error'=>'Invalid token']);
if (empty($u['ai_status'])) guide_json(403, ['success'=>false,'error'=>'AI not enabled']);

$page    = preg_replace('/[^a-z_]/', '', strtolower($_GET['page'] ?? 'home'));
$lang    = in_array($_GET['lang'] ?? 'en', ['en', 'yo', 'ha', 'ig']) ? ($_GET['lang'] ?? 'en') : 'en';
$cache_k = "mobile_guide_{$page}_{$lang}_{$u['vid']}";

// Check 24h cache
$cached = mysqli_fetch_assoc(mysqli_query($connection_server,
    "SELECT guide_text FROM sas_ai_page_guides WHERE cache_key='".mysqli_real_escape_string($connection_server,$cache_k)."' AND expires_at > NOW() LIMIT 1"));
if ($cached) guide_json(200, ['success'=>true,'guide'=>$cached['guide_text'],'cached'=>true,'page'=>$page]);

// Generate fresh tip
$page_labels = [
    'airtime'=>'buying airtime/recharge', 'data'=>'buying data bundles',
    'cable'=>'paying cable TV bills (DSTV/GOTV/Startimes)', 'electric'=>'paying electricity bills',
    'betting'=>'funding betting wallet accounts', 'wallet'=>'funding wallet from bank',
    'dashboard'=>'managing your VTU business account', 'referral'=>'the referral bonus program',
    'home'=>'VTU (virtual top-up) services in Nigeria',
];
$label = $page_labels[$page] ?? "the $page feature";
$lang_note = $lang !== 'en' ? " Reply in $lang language." : '';
$prompt = "You are a helpful Nigerian fintech guide. Give one concise, practical tip (max 2 sentences) for a user on the '$label' page of a VTU app.$lang_note";

$engine  = ai_engine();
$model   = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');
$result  = $engine->chat($model, $prompt);
$tip     = $result['response'] ?? '';

if (empty($tip)) guide_json(200, ['success'=>true,'guide'=>"Tip: Always double-check the phone number or account details before confirming your transaction.",'cached'=>false,'page'=>$page]);

// Cache for 24h
$tip_esc = mysqli_real_escape_string($connection_server, $tip);
$key_esc = mysqli_real_escape_string($connection_server, $cache_k);
$vid     = (int)$u['vid'];
mysqli_query($connection_server,
    "INSERT INTO sas_ai_page_guides (vendor_id, cache_key, guide_text, expires_at) VALUES ('$vid','$key_esc','$tip_esc', DATE_ADD(NOW(), INTERVAL 24 HOUR))
     ON DUPLICATE KEY UPDATE guide_text='$tip_esc', expires_at=DATE_ADD(NOW(), INTERVAL 24 HOUR)");

guide_json(200, ['success'=>true,'guide'=>$tip,'cached'=>false,'page'=>$page,'lang'=>$lang]);
