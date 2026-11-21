<?php
/** Mail-safe assets only. No remote downloads and no changes to stored template content. */
function bc_email_asset_host($raw) {
    $raw=trim((string)$raw);
    if ($raw==='' || preg_match('/[\x00-\x20\\\\]/',$raw)) return '';
    $parts=parse_url(strpos($raw,'://')===false ? 'https://'.$raw : $raw);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true)
        || !empty($parts['user']) || !empty($parts['pass']) || isset($parts['port'])
        || !empty($parts['query']) || !empty($parts['fragment']) || !in_array($parts['path'] ?? '',['','/'],true)) return '';
    $host=strtolower(rtrim($parts['host'] ?? '','.'));
    return preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host) && strpos($host,'..')===false ? $host : '';
}
function bc_email_asset_url($raw,$host) {
    $url=html_entity_decode(trim((string)$raw),ENT_QUOTES,'UTF-8');
    if ($url==='' || preg_match('/[\x00-\x20\\\\]/',$url)) return '';
    if (preg_match('/^cid:[a-z0-9@._-]+$/iD',$url)) return $url;
    if (strpos($url,'//')===0) $url='https:'.$url;
    if (preg_match('~^https?://~i',$url)) {
        $p=parse_url($url);
        if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return '';
        return preg_replace('~^http://~i','https://',$url);
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i',$url) || $host==='') return '';
    // Saved editor exports commonly use ../uploaded-image or ../asset from admin pages.
    $parts=[];
    foreach (explode('/',ltrim($url,'/')) as $part) {
        if ($part==='.' || $part==='') continue;
        if ($part==='..') { array_pop($parts); continue; }
        $parts[]=$part;
    }
    return 'https://'.$host.'/'.implode('/',$parts);
}
function bc_email_without_background_images($css) {
    // Split declarations only outside strings/functions, including data URLs with semicolons.
    $out=[];$part='';$quote='';$depth=0;$length=strlen($css);
    for($i=0;$i<$length;$i++) {
        $ch=$css[$i];
        if ($quote!=='') { $part.=$ch; if ($ch===$quote && ($i===0 || $css[$i-1]!=='\\')) $quote=''; continue; }
        if ($ch==='"' || $ch==="'") $quote=$ch;
        elseif ($ch==='(') $depth++;
        elseif ($ch===')') $depth=max(0,$depth-1);
        if ($ch===';' && $depth===0) { $out[]=$part;$part=''; } else $part.=$ch;
    }
    $out[]=$part;
    return implode(';',array_filter($out,function($decl){
        return !preg_match('/^\s*background-image\s*:/i',$decl)
            && !preg_match('/^\s*background\s*:.*(?:url\s*\(|gradient\s*\()/is',$decl);
    }));
}
function bc_email_prepare_assets($html,$host) {
    $host=bc_email_asset_host($host);
    $html=preg_replace('/<v:background\b[^>]*>.*?<\/v:background>/is','',(string)$html);
    $html=preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/is',function($block){
        return '<style>'.preg_replace_callback('/\{([^{}]*)\}/s',function($rule){ return '{'.bc_email_without_background_images($rule[1]).'}'; },$block[1]).'</style>';
    },$html);
    $html=preg_replace_callback('/<[^!][^>]*>/s',function($m)use($host){
        $tag=$m[0];
        $tag=preg_replace('/\sbackground\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i','',$tag);
        $tag=preg_replace_callback('/\bstyle\s*=\s*(["\'])(.*?)\1/is',function($a){
            return 'style='.$a[1].bc_email_without_background_images($a[2]).$a[1];
        },$tag);
        if (!preg_match('/^<img\b/i',$tag)) return $tag;
        if (!preg_match('/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',$tag,$src)) return '';
        $url=bc_email_asset_url(($src[1]??'')!==''?$src[1]:(($src[2]??'')!==''?$src[2]:($src[3]??'')),$host);
        if ($url==='' || preg_match('/\.svg(?:[?#]|$)/i',$url)) {
            preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is',$tag,$alt);
            return '<span>'.htmlspecialchars(html_entity_decode($alt[2]??'',ENT_QUOTES,'UTF-8'),ENT_QUOTES,'UTF-8').'</span>';
        }
        $tag=str_replace($src[0],'src="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'"',$tag);
        return preg_replace('/\s(?:srcset|loading|onerror|onload)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i','',$tag);
    },(string)$html);
    return $html;
}
function bc_email_logo_mime($path) {
    if (!is_file($path)) return '';
    $sig=@file_get_contents($path,false,null,0,12);
    if (substr((string)$sig,0,8)==="\x89PNG\r\n\x1a\n") return 'image/png';
    if (substr((string)$sig,0,3)==="\xff\xd8\xff") return 'image/jpeg';
    if (in_array(substr((string)$sig,0,6),['GIF87a','GIF89a'],true)) return 'image/gif';
    return '';
}
function bc_email_embed_logo($mailer,$html) {
    $brand=bc_email_brand();
    if (!empty($brand['logo_path']) && is_file($brand['logo_path']) && strpos($html,htmlspecialchars($brand['logo_url'],ENT_QUOTES,'UTF-8'))!==false) {
        try {
            if ($mailer->addEmbeddedImage($brand['logo_path'],'bc-brand-logo','logo.'.(['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif'][$brand['logo_mime']] ?? 'png'),'base64',$brand['logo_mime'])) {
                return str_replace('src="'.htmlspecialchars($brand['logo_url'],ENT_QUOTES,'UTF-8').'"','src="cid:bc-brand-logo"',$html);
            }
        } catch (Throwable $e) { /* Public HTTPS image remains usable on transport failure. */ }
    }
    return $html;
}
