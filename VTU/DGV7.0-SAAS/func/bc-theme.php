<?php
/** Shared WCAG theme contract. Brand colour is data, never arbitrary CSS. */
if (!function_exists('bc_theme_palette')) {
function bc_theme_hex($hex) { return is_string($hex) && preg_match('/^#[0-9a-fA-F]{6}$/D', $hex) ? strtoupper($hex) : null; }
function bc_theme_luminance($hex) {
    $rgb = array_map(function($offset) use ($hex) { $v=hexdec(substr($hex,$offset,2))/255; return $v<=0.04045 ? $v/12.92 : pow(($v+0.055)/1.055,2.4); }, array(1,3,5));
    return .2126*$rgb[0]+.7152*$rgb[1]+.0722*$rgb[2];
}
function bc_theme_contrast($a,$b) { $x=bc_theme_luminance($a);$y=bc_theme_luminance($b);return (max($x,$y)+.05)/(min($x,$y)+.05); }
function bc_theme_on($hex) { return bc_theme_contrast($hex,'#000000') >= bc_theme_contrast($hex,'#FFFFFF') ? '#000000' : '#FFFFFF'; }
function bc_theme_readable($brand, $surfaces) {
    $target=bc_theme_on($surfaces[0]);
    for($i=0;$i<=100;$i++) {
        $hex='#';
        foreach(array(1,3,5) as $pos) $hex.=sprintf('%02X',round(hexdec(substr($brand,$pos,2))*(1-$i/100)+hexdec(substr($target,$pos,2))*$i/100));
        $ok=true;foreach($surfaces as $surface) if(bc_theme_contrast($hex,$surface)<4.5)$ok=false;
        if($ok)return $hex;
    }
    return $target;
}
/** Resolve brand tints against the shell's neutral card surface, with matching ink. */
function bc_theme_tint($raw, $opacity) {
    $brand=bc_theme_hex($raw) ?: '#287BFF';
    $alpha=max(0,min(1,(float)$opacity));
    $background='#';
    foreach(array(1,3,5) as $pos) $background.=sprintf('%02X',round(hexdec(substr($brand,$pos,2))*$alpha+255*(1-$alpha)));
    return array('background'=>$background,'foreground'=>bc_theme_on($background),'link'=>bc_theme_readable($brand,array($background)));
}
function bc_theme_palette($raw) {
    $primary=bc_theme_hex($raw) ?: '#287BFF';
    $modes=array();
    foreach(array('light'=>array('#F8FAFC','#FFFFFF'),'dark'=>array('#020617','#0F172A')) as $mode=>$surfaces) {
        $ink=bc_theme_readable($primary,$surfaces);
        $modes[$mode]=array('primary'=>$primary,'on_primary'=>bc_theme_on($primary),'primary_text'=>$ink,'background'=>$surfaces[0],'surface'=>$surfaces[1],'on_surface'=>bc_theme_on($surfaces[1]),'outline'=>$ink);
    }
    return array('schema_version'=>1,'version'=>hash('sha256',json_encode($modes)),'primary_color'=>$primary,'refresh_after_seconds'=>60,'modes'=>$modes);
}
/** Strict host-derived tenant. No query-string, session or first-vendor override. */
function bc_theme_host($raw) {
    $host=strtolower(trim((string)$raw));
    if (!preg_match('/^([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::[0-9]{1,5})?$/D',$host,$m)) return null;
    $host=preg_replace('/^www\./','',$m[1]);
    if(strpos($host,'..')!==false || strlen($host)>253)return null;
    return $host;
}
}
