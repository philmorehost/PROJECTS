<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-vendor-compliance-review.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-live-capture.php';
$n=0;function vc($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
$_SESSION=[];$v=['id'=>7,'email'=>'applicant@test','kyc_status'=>1];$docs=[['id'=>2,'status'=>'pending','file_name'=>'evidence.pdf'],['id'=>1,'status'=>'pending']];$a=['id'=>1,'email'=>'reviewer@test','password'=>'hash'];
$state=bc_vcr_state($v,$docs);vc($state===bc_vcr_state($v,array_reverse($docs)),'stable doc ordering');
$key=bc_vcr_ticket($v,$docs,$a);vc(bc_vcr_consume($key,$a)===$state,'review ticket');vc(bc_vcr_consume($key,$a)===null,'single use');
$key=bc_vcr_ticket($v,$docs,$a);$other=$a;$other['password']='new';vc(bc_vcr_consume($key,$other)===null,'credential binding');
$key=bc_vcr_ticket($v,$docs,$a);$_SESSION['vendor_compliance_tickets'][$key]['until']=time()-1;vc(bc_vcr_consume($key,$a)===null,'expiry');
$docs[0]['file_name']='replacement.pdf';vc(bc_vcr_state($v,$docs)!==$state,'changed evidence bound');
foreach([null,[],str_repeat('z',64)] as $bad)vc(bc_vcr_consume($bad,$a)===null,'bad ticket');
ob_start();bc_live_capture_widget('selfie');bc_live_capture_widget('liveliness_video',true);$html=ob_get_clean();vc(substr_count($html,'bc-live-capture.js')===1,'one script');vc(substr_count($html,'type="file" hidden')===2,'no picker UI');vc(strpos($html,'data-live-capture="video"')!==false,'live video');
vc(!bc_live_media_prepare(__FILE__,'selfie'),'PHP not image');vc(!bc_live_media_prepare(__FILE__,'liveliness_video'),'PHP not video');
foreach(['SAAS','NON-SAAS'] as $ed){$base=__DIR__.'/../DGV7.0-'.$ed.'/';foreach(['web/KYCVerification.php','bc-admin/AccountSettings.php'] as $f){$s=file_get_contents($base.$f);vc(strpos($s,"bc_live_capture_widget('selfie')")!==false&&strpos($s,"bc_live_capture_widget('liveliness_video', true)")!==false,'both capture widgets');vc(!preg_match('/<input[^>]+type="file"[^>]+name="(?:selfie|liveliness_video)"/',$s),'no gallery input');vc(strpos($s,'UPLOAD_ERR_INI_SIZE')!==false&&strpos($s,'post_max_size')!==false,'specific upload limit errors in '.$f);}foreach(['web/KYCVerification.php','web/api/kyc.php','bc-admin/AccountSettings.php'] as $f)vc(strpos(file_get_contents($base.$f),'bc_live_media_prepare($tmp, $input_name)')!==false,'alternate upload storage caps');$bu=file_get_contents($base.'web/BusinessUpgrade.php');vc(strpos($bu,'UPLOAD_ERR_INI_SIZE')!==false&&strpos($bu,'post_max_size')!==false,'KYB upload limit errors');}
vc(file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-live-capture.php')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/func/bc-live-capture.php'),'bc-live-capture.php edition parity');
vc(file_get_contents(__DIR__.'/../DGV7.0-SAAS/asset/bc-live-capture.js')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/asset/bc-live-capture.js'),'bc-live-capture.js edition parity');
if(class_exists('finfo')&&function_exists('imagecreatetruecolor')) {
 $tmp=tempnam(sys_get_temp_dir(),'bc_media_');
 try {
  $image=imagecreatetruecolor(640,480);imagejpeg($image,$tmp,95);imagedestroy($image);vc(bc_live_media_prepare($tmp,'selfie'),'valid bounded JPEG');
  $image=imagecreatetruecolor(1200,1000);imagejpeg($image,$tmp);imagedestroy($image);vc(!bc_live_media_prepare($tmp,'selfie'),'oversized dimensions');
  file_put_contents($tmp,str_repeat('x',512*1024+1));vc(!bc_live_media_prepare($tmp,'selfie'),'hard photo cap');
  file_put_contents($tmp,str_repeat('x',3*1024*1024+1));vc(!bc_live_media_prepare($tmp,'liveliness_video'),'hard video cap');
 }finally{unlink($tmp);}
}
echo "$n vendor compliance/capture boundary checks passed\n";
