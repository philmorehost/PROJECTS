<?php
function bc_public_short_brand($name) {
    $words=preg_split('/[\s\p{Z}]+/u',trim((string)$name),-1,PREG_SPLIT_NO_EMPTY);
    return is_array($words)&&$words?strip_tags($words[0]):'Platform';
}
function bc_public_saved_app_url($vendor,$details=[]) {
    if((int)$vendor<=0)return '';
    if(function_exists('bc_fintech_tenant_android_app_url')){
        try{$s=bc_fintech_tenant_android_app_url((int)$vendor);if(($s['state']??'')==='valid')return (string)$s['url'];if(($s['state']??'')==='invalid')return '';}catch(Throwable $e){return '';}
    }
    $url=$details['android_app_url']??'';
    if(is_string($url)&&trim($url)!=='')return $url;
    return is_string($details['apk_download_url']??null)?$details['apk_download_url']:'';
}
function bc_public_app_badge_html($url) {
    if(!is_string($url))return '';$url=trim($url);
    if($url===''||preg_match('/[\x00-\x20\x7f\\\\]/',$url))return '';
    $p=parse_url($url);if(!$p||isset($p['user'])||isset($p['pass']))return '';
    $local=substr($url,0,1)==='/'&&substr($url,0,2)!=='//';
    if(!$local&&(!filter_var($url,FILTER_VALIDATE_URL)||strtolower($p['scheme']??'')!=='https'))return '';
    if($local){foreach(explode('/',rawurldecode($p['path']??'')) as $part)if($part==='.'||$part==='..'||strpos($part,'\\')!==false)return '';if(substr(rawurldecode($url),0,2)==='//')return '';}
    parse_str($p['query']??'',$q);
    $play=!$local&&strtolower($p['host']??'')==='play.google.com'&&($p['path']??'')==='/store/apps/details'&&is_string($q['id']??null)&&preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+\z/D',$q['id']);
    $label=$play?'Get it on Google Play':'Download Android app';
    $e=fn($v)=>htmlspecialchars($v,ENT_QUOTES,'UTF-8');
    $icon=$play?'<svg width="30" height="34" viewBox="0 0 30 34" aria-hidden="true"><path fill="#32bbff" d="M1 1v32l17-16z"/><path fill="#00d26a" d="M1 1l22 12-5 4z"/><path fill="#ffce00" d="M23 13l6 4-6 4-5-4z"/><path fill="#ff4b55" d="M1 33l22-12-5-4z"/></svg>':'<svg width="30" height="34" viewBox="0 0 30 34" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M15 3v20m-7-7 7 7 7-7M3 24v7h24v-7"/></svg>';
    return '<div class="bc-landing-app" style="display:flex;justify-content:center;margin:24px auto;padding:0 16px;max-width:100%;box-sizing:border-box"><a class="bc-app-badge" href="'.$e($url).'" aria-label="'.$e($label).'" style="display:inline-flex;align-items:center;gap:12px;background:#080808;color:#fff!important;border:1px solid #777;border-radius:12px;padding:10px 18px;text-decoration:none;max-width:100%;box-sizing:border-box;min-height:60px">'.$icon.'<span style="color:#fff!important;font:600 18px/1.3 system-ui,sans-serif">'.$e($label).'</span></a></div>';
}
