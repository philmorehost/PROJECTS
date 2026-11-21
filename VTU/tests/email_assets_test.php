<?php
// Isolated brand/transport test doubles. No SMTP or live HTTP requests.
if (extension_loaded('mysqli')) throw new RuntimeException('Run with php -n or PHP-WASM.');
class mysqli {}
$connection_server=new mysqli();$GLOBALS['vendor_id']=7;
function mysqli_query($db,$sql){
    if(strpos($sql,'SELECT site_title, primary_color, apk_download_url')!==false)return ['site_title'=>'Test Brand','primary_color'=>'#680099','apk_download_url'=>'/Downloads/app.apk'];
    if(strpos($sql,'SELECT email, website_url')!==false)return ['email'=>'support@shop.example.test','website_url'=>'https://shop.example.test/'];
    return false;
}
function mysqli_fetch_assoc($row){return $row;}
$_SERVER['HTTP_HOST']='temporary.example.test';
require dirname(__DIR__).'/DGV7.0-SAAS/func/email-design.php';
$count=0;
function asset_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);$count++;}
foreach(['shop.example.test','https://shop.example.test/','http://SHOP.EXAMPLE.TEST/'] as $raw) asset_check(bc_email_asset_host($raw)==='shop.example.test','canonical host normalizes');
foreach(['https://bad.test/path','https://user:pass@bad.test','bad.test/../x',"bad.test\r\nBcc:x",'javascript:bad'] as $raw)asset_check(bc_email_asset_host($raw)==='','unsafe host rejected');
foreach(['../uploaded-image/photo.png','/uploaded-image/photo.png','uploaded-image/photo.png'] as $src)asset_check(bc_email_asset_url($src,'shop.example.test')==='https://shop.example.test/uploaded-image/photo.png','relative image absolute');
asset_check(bc_email_asset_url('//cdn.example.test/image.png','shop.example.test')==='https://cdn.example.test/image.png','protocol relative uses HTTPS');
asset_check(bc_email_asset_url('javascript:alert(1)','shop.example.test')==='','unsafe image scheme rejected');
$body='<table background="/old.jpg" style="color:#123;background-image:url(\'data:image/png;base64,abc\');padding:20px;background:#fff"><tr><td><img src="../uploaded-image/photo.png" alt="Photo"><a href="https://example.test/app"><img src="https://example.test/badge.svg" alt="Download app"></a>Reference ABC123</td></tr></table><style>.old{background:url(old.png);color:red}</style><v:background><v:fill src="old.png"/></v:background>';
$clean=bc_email_prepare_assets($body,'shop.example.test');
asset_check(strpos($clean,'background-image')===false && strpos($clean,'url(')===false && strpos($clean,'background="')===false && strpos($clean,'v:background')===false,'background imagery removed');
asset_check(strpos($clean,'padding:20px')!==false && strpos($clean,'background:#fff')!==false,'solid backgrounds and content styles preserved');
asset_check(strpos($clean,'https://shop.example.test/uploaded-image/photo.png')!==false,'body image public HTTPS');
asset_check(strpos($clean,'.svg')===false && strpos($clean,'Download app</span></a>')!==false,'SVG fallback keeps linked text');
asset_check(strpos($clean,'Reference ABC123')!==false,'transaction content retained');
$logo=dirname(__DIR__).'/DGV7.0-SAAS/uploaded-image/shop-example-test_logo.png';
if(!is_dir(dirname($logo)))mkdir(dirname($logo),0777,true);
if(is_file($logo))throw new RuntimeException('Fixture path unexpectedly exists');
file_put_contents($logo,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII='));
class AssetMailer {public $embedded=[];public $ok=true;function addEmbeddedImage(...$args){$this->embedded=$args;return $this->ok;}}
try{
    $brand=bc_email_brand();
    asset_check($brand['host']==='shop.example.test','queued/temporary-host brand uses stored canonical URL');
    asset_check(strpos($brand['logo_url'],'https://shop.example.test/uploaded-image/shop-example-test_logo.png?v=')===0,'logo uses actual upload naming');
    $html=mailDesignTemplate('API updated',$body,[],true);
    asset_check(strpos($html,'badge_EN.svg')===false && strpos($html,'Download the app')!==false,'app CTA no longer depends on SVG image');
    asset_check(strpos($html,'href="https://shop.example.test/Downloads/app.apk"')!==false,'download URL normalized');
    asset_check(strpos($html,'background-image')===false && strpos($html,'linear-gradient')===false,'all outgoing background imagery removed');
    $mailer=new AssetMailer();$smtp=bc_email_embed_logo($mailer,$html);
    asset_check(strpos($smtp,'src="cid:bc-brand-logo"')!==false && $mailer->embedded[4]==='image/png','SMTP embeds local PNG logo');
    asset_check(strpos($html,'src="https://shop.example.test/uploaded-image/')!==false,'original fallback message retains HTTPS not CID');
    $mailer->ok=false;asset_check(bc_email_embed_logo($mailer,$html)===$html,'embedding failure retains working absolute fallback');
    asset_check(substr_count(mailDesignTemplate('Ignored',$html,[],true),'bc-email-shell:v2')===1,'already-wrapped messages not double wrapped');
}finally{unlink($logo);}
$brand=bc_email_brand();asset_check($brand['logo_url']==='' && $brand['logo_path']==='','missing logo never generates broken header image');
$html=mailDesignTemplate('No image','Hello',[],false);
asset_check(strpos($html,'<img')===false && strpos($html,'Test Brand')!==false,'readable text branding when missing or blocked');
foreach(['func/email-design.php','func/bc-email-assets.php'] as $rel)asset_check(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/'.$rel)===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/'.$rel),'edition parity');
function createVendorEmailTemplateIfNotExists($type,$subject,$body){$GLOBALS['asset_defaults'][]=[$subject,$body];}
function createSuperAdminEmailTemplateIfNotExists($type,$subject,$body){$GLOBALS['asset_defaults'][]=[$subject,$body];}
$GLOBALS['asset_defaults']=[];
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-email-templates.php';
foreach($GLOBALS['asset_defaults'] as $template){
    $rendered=mailDesignTemplate($template[0],$template[1],[],true);
    asset_check(strpos($rendered,'background-image')===false && strpos($rendered,'.svg')===false,'default renders without background images or SVG dependencies');
}
echo "$count email asset checks passed\n";
