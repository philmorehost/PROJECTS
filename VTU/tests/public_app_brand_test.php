<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-public-app-brand.php';
$n=0;
function check($condition,$label){global $n;if(!$condition)throw new RuntimeException($label);$n++;}
foreach(['Acme Wallet'=>'Acme','  Acme   Global  '=>'Acme',"Acme\u{00a0}Global"=>'Acme','你好 钱包'=>'你好',''=>'Platform','   '=>'Platform','<script>x</script> Brand'=>'x'] as $name=>$expected)check(bc_public_short_brand($name)===$expected,'first word '.$name);
foreach(['','javascript:alert(1)','data:text/html,x','//evil.test/x','https://user:pass@example.com/x','https://example.com/ bad','http://example.com/app.apk','/../app.apk','/%2e%2e/app.apk','/%2fexample.com/app.apk',"https://example.com/\napp",'/foo\\bar'] as $url)check(bc_public_app_badge_html($url)==='','reject '.$url);
$url='https://play.google.com/store/apps/details?id=com.acme.wallet&hl=en';$html=bc_public_app_badge_html($url);
check(strpos($html,'Google Play')!==false,'store label');check(strpos($html,'<svg')!==false,'inline icon');check(strpos($html,'&amp;hl=en')!==false,'escaped query');
foreach(['/downloads/app.apk','https://example.com/app.apk','https://play.google.com.evil.test/store/apps/details?id=com.acme.app','https://play.google.com/store/apps/details?id[]=x','https://example.com/"onmouseover="x'] as $url){$html=bc_public_app_badge_html($url);check(strpos($html,'Download Android app')!==false,'generic Android label');check(strpos($html,'Google Play')===false,'no false store claim');}
check(bc_public_app_badge_html('https://example.com/"onmouseover="x')!=='' && strpos(bc_public_app_badge_html('https://example.com/"onmouseover="x'),'&quot;')!==false,'escaped quotes');
check(bc_public_saved_app_url(0,['android_app_url'=>$url])==='','no cross-tenant fallback');
check(bc_public_saved_app_url(1,['android_app_url'=>'https://example.com/app'])==='https://example.com/app','saved modern setting');
check(bc_public_saved_app_url(1,['apk_download_url'=>'/downloads/app.apk'])==='/downloads/app.apk','legacy download setting');
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $ed){
 $base=__DIR__.'/../'.$ed.'/';
 check(file_get_contents($base.'func/bc-public-app-brand.php')===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-public-app-brand.php'),'edition helper parity');
 foreach(['home','2','3','6'] as $t){$s=file_get_contents($base.'index-bc-style-template-'.$t.'.php');check(strpos($s,'bc_public_app_badge_html')!==false,'badge wired '.$ed.$t);check(stripos($s,'mzeevtu')===false,'no hardcoded tenant '.$ed.$t);}
 $s=file_get_contents($base.'index-bc-style-template-home.php');check(substr_count($s,'class="bc-card-brand"')===2,'both cards branded');check(strpos($s,'bc_landing_e($home_short_brand)')!==false,'escaped brand');
}
$_SESSION = ['admin_session' => 'vendor@example.test'];
check(bc_public_dashboard_url() === '', 'public landing dashboard url never leaks admin backoffice');
$_SESSION['user_session'] = 'alice';
check(bc_public_dashboard_url() === '/web/Dashboard.php', 'public landing dashboard url routes logged-in users to /web/Dashboard.php even when admin_session is set');
$_SESSION = [];
echo "PASS $n public app and short-brand checks\n";
