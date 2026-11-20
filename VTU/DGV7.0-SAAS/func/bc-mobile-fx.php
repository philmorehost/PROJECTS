<?php
/** Display-only fresh FX quotation. No hard-coded rates or stale cache fallback. */
function bc_mobile_live_quote($vid,$from,$to,$amount) {
    $allowed=array('NGN','USD','GBP','EUR','GHS','ZAR','CAD','KES','AED');
    if(!in_array($from,$allowed,true)||!in_array($to,$allowed,true)||!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||(float)$amount>1000000000) return null;
    if($from===$to)return array('rate'=>1.0,'result'=>(float)$amount,'as_of'=>gmdate('c'));
    if(!function_exists('curl_init'))return null;
    $key=trim((string)getVendorOption((int)$vid,'fx_api_key',''));
    $url=$key!=='' ? 'https://v6.exchangerate-api.com/v6/'.rawurlencode($key).'/latest/'.$from : 'https://api.exchangerate-api.com/v4/latest/'.$from;
    $ch=curl_init($url);
    curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2));
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $data=is_string($raw)?json_decode($raw,true):null;
    $rate=is_array($data)?($data['conversion_rates'][$to]??$data['rates'][$to]??null):null;
    if($http!==200||!is_numeric($rate)||!is_finite((float)$rate)||(float)$rate<=0)return null;
    $timestamp=$data['time_last_update_unix']??$data['time_last_updated']??null;
    if(!is_numeric($timestamp)||time()-(int)$timestamp>172800||(int)$timestamp>time()+300)return null;
    return array('rate'=>(float)$rate,'result'=>(float)$amount*(float)$rate,'as_of'=>gmdate('c',(int)$timestamp));
}
