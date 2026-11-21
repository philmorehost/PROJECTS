<?php
/** Shared VTU routing/transport. No retries, TLS bypass, authentication bypass or settlement decisions. */
function bc_vtu_base_parts($base) {
    $base=trim((string)$base);
    if (!preg_match('~^https?://~i',$base)) $base='https://'.$base;
    $p=parse_url($base);
    if (!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || preg_match('/[^a-z0-9.-]/i',$p['host'])) return null;
    if (isset($p['port']) && (int)$p['port']!==443) return null;
    return ['host'=>strtolower($p['host']),'path'=>rtrim($p['path']??'','/')];
}
function bc_vtu_gateway_candidate($service,$base) {
    if (!preg_match('/^[a-z][a-z-]*$/D',(string)$service)) return 'invalid-provider.php';
    $p=bc_vtu_base_parts($base);
    if (!$p) return 'invalid-provider.php';
    $host=preg_replace('/^www\./','',$p['host']);
    if ($host==='nellobytesystems.com') $host='clubkonnect.com';
    return $service.'-'.str_replace('.','-',$host).'.php';
}
function bc_vtu_local_url($base,$endpoint) {
    if (!preg_match('/^[a-z][a-z-]*$/D',(string)$endpoint)) return '';
    $p=bc_vtu_base_parts($base);
    if (!$p) return ''; // cURL refuses a malformed URL before any request can be sent.
    $path=preg_replace('~/web/api(?:/[a-z-]+\.php)?$~i','',$p['path']);
    if (strpos($path,'..')!==false || preg_match('/[^a-zA-Z0-9_\/-]/',$path)) return '';
    return 'https://'.$p['host'].$path.'/web/api/'.$endpoint.'.php';
}
function bc_vtu_provider_url($base,$path) {
    $p=bc_vtu_base_parts($base);
    return $p ? 'https://'.$p['host'].'/'.ltrim($path,'/') : '';
}
function bc_vtu_curl_exec($handle,$local=false) {
    // Override old duplicate 60-second settings at the last possible point.
    // A slow/uncertain response must still follow existing pending/requery rules.
    curl_setopt($handle,CURLOPT_CONNECTTIMEOUT,8);
    curl_setopt($handle,CURLOPT_TIMEOUT,$local?45:30);
    curl_setopt($handle,CURLOPT_SSL_VERIFYHOST,2);
    curl_setopt($handle,CURLOPT_SSL_VERIFYPEER,true);
    curl_setopt($handle,CURLOPT_FOLLOWLOCATION,false);
    $result=curl_exec($handle);
    $info=curl_getinfo($handle);$errno=curl_errno($handle);
    $metrics=['errno'=>$errno,'http'=>(int)($info['http_code']??0),'dns_ms'=>(int)round(1000*($info['namelookup_time']??0)),'connect_ms'=>(int)round(1000*($info['connect_time']??0)),'total_ms'=>(int)round(1000*($info['total_time']??0))];
    $GLOBALS['bc_vtu_last_transport']=$metrics;
    // Never log URL/query/body/headers/API key/phone. These metrics distinguish transport and application failures.
    if ($errno || $metrics['http']>=400 || $metrics['total_ms']>=2000) error_log('[VTU_TRANSPORT] '.json_encode($metrics));
    return $result;
}

function bc_vtu_forward_hop() {
    $hop=(string)($_SERVER['HTTP_X_BC_VTU_HOP']??'0');
    return preg_match('/^[0-3]$/D',$hop)?(int)$hop+1:4;
}
function bc_vtu_guard_hop() {
    if (preg_match('/^[0-3]$/D',(string)($_SERVER['HTTP_X_BC_VTU_HOP']??'0'))) return;
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['status'=>'failed','code'=>'VTU_ROUTING_LOOP','desc'=>'API routing loop detected. Contact the service administrator.']);
    exit;
}
