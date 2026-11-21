<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-theme.php';
$n=0;
function tc($ok,$label) {global $n;$n++;if(!$ok)throw new RuntimeException('FAIL '.$label);}
tc(abs(bc_theme_contrast('#000000','#FFFFFF')-21)<0.000001,'known black-white WCAG ratio');
tc(abs(bc_theme_contrast('#FF0000','#000000')-5.252)<0.001,'known red-black linear luminance');
tc(bc_theme_on('#777777')==='#000000','mid-grey chooses the stronger black foreground');
foreach(array(null,'','red','#FFF','#12345678',"#112233\n",'<script>') as $bad)tc(bc_theme_palette($bad)['primary_color']==='#287BFF','invalid color uses neutral seed');
$colors=array('#FFFFFF','#000000','#FFFF00','#00FF00','#777777','#FF0000','#0000FF','#287BFF');
for($r=0;$r<256;$r+=17)for($g=0;$g<256;$g+=17)for($b=0;$b<256;$b+=17)$colors[]=sprintf('#%02X%02X%02X',$r,$g,$b);
foreach($colors as $color) {
 $p=bc_theme_palette($color);
 tc($p['primary_color']===$color,'preserve actual admin primary');
 foreach($p['modes'] as $mode=>$tokens) {
  tc(bc_theme_contrast($tokens['primary'],$tokens['on_primary'])>=4.5,'filled button text WCAG '.$color);
  foreach(array('background','surface') as $surface)tc(bc_theme_contrast($tokens['primary_text'],$tokens[$surface])>=4.5,'brand icon/link WCAG '.$color);
  tc(bc_theme_contrast($tokens['on_surface'],$tokens['surface'])>=4.5,'surface foreground WCAG');
  tc(bc_theme_contrast($tokens['outline'],$tokens['surface'])>=3,'boundary WCAG');
 }
}
tc(bc_theme_palette('#FFFFFF')['version']!==bc_theme_palette('#000000')['version'],'color change changes version');
tc(bc_theme_palette('#abcdef')===bc_theme_palette('#ABCDEF'),'canonical version');
foreach(array('https://evil.test','evil.test/path',"tenant.test\r\nX:1",'a..b','user@tenant.test','tenant.test?vid=3') as $host)tc(bc_theme_host($host)===null,'reject malformed host');
tc(bc_theme_host('WWW.Tenant.test:443')==='tenant.test','canonical host');
foreach(array('DGV7.0-SAAS','DGV7.0-NON-SAAS') as $ed) {
 $r=dirname(__DIR__).'/'.$ed;
 $api=file_get_contents($r.'/web/api/theme.php');
 tc(strpos($api,'mysqli_stmt_bind_param')!==false,'bound host lookup');
 tc(strpos($api,'v.status=1')!==false,'active tenant only');
 tc(strpos($api,'resolveVendorID(')===false && strpos($api,'$_GET')===false,'no session/query tenant override');
 tc(strpos($api,"Cache-Control: no-store")!==false,'fresh config response');
 tc(strpos($api,"http_response_code(404)")!==false,'unknown tenant closed');
 tc(strpos(file_get_contents($r.'/bc-admin/AccountSettings.php'),"preg_match('/^#[0-9a-fA-F]{6}$/D', \$primary_color)")!==false,'save validates exact color');
}
tc(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-theme.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-theme.php'),'theme parity');
echo "$n dynamic-theme checks passed; native and live-DB tests still required.\n";
