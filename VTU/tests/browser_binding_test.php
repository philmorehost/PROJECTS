<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-browser-binding.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-webauthn.php';
$_SESSION=[];$n=0;function bb_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
foreach(['user','vendor','spadmin'] as $role){
 $row=['id'=>11,'vendor_id'=>7,'username'=>'fixture','email'=>'fixture@example.test','password'=>'hash','security_pin'=>'pin-hash','transaction_pin'=>'9362','google_id'=>'subject'];
 bb_check(!bc_browser_binding_valid($role,$row),'no trust on first use');
 bb_check(bc_browser_binding_stamp($role,$row) && bc_browser_binding_valid($role,$row),'explicit authenticated stamp');
 foreach(['id'=>12,'vendor_id'=>8,'username'=>'other','email'=>'other@example.test','password'=>'reset','security_pin'=>'changed','transaction_pin'=>'4528','google_id'=>'other'] as $field=>$value){$other=$row;$other[$field]=$value;bb_check(!bc_browser_binding_valid($role,$other),'identity/credential change '.$field);}
 $other=$row;$other['balance']='2500';bb_check(bc_browser_binding_valid($role,$other),'balance unrelated');
 $other=$row;unset($other['password']);bb_check(!bc_browser_binding_stamp($role,$other) && !bc_browser_binding_valid($role,$row),'failed stamp clears old binding');
}
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed;
 foreach(['func/bc-config.php','func/bc-admin-config.php','func/bc-enrollment-policy.php'] as $path)bb_check(strpos(file_get_contents($root.'/'.$path),'bc_browser_binding_enforce(')!==false,'bootstrap enforcement');
 foreach(['web/Login.php','bc-admin/Login.php','web/AppPortal.php','bc-admin/Users.php'] as $path)bb_check(strpos(file_get_contents($root.'/'.$path),'bc_browser_binding_stamp(')!==false,'auth entry stamp');
 foreach(['web/Register.php','web/api/send-reg-otp.php','web/api/verify-reg-otp.php'] as $path)bb_check(strpos(file_get_contents($root.'/'.$path),'bc_browser_binding_new_user(')!==false,'registration authoritative stamp');
 $s=file_get_contents($root.'/web/biometric-ajax.php');
 $authPos=strpos($s,"if (\$action === 'verify_authentication')");
 $verifyPos=strpos($s,'bc_webauthn_verify_assertion(',$authPos);
 $sessPos=strrpos($s,"\$_SESSION['user_session']");
 bb_check($authPos!==false && $verifyPos!==false && $verifyPos<$sessPos,'biometric must verify cryptographic assertion before granting session');
 bb_check(strpos($s,'bc_webauthn_verify_attestation(')!==false,'biometric registration verifies attestation');
 bb_check(strpos(file_get_contents($root.'/web/Login.php'),'$expected_auth = md5')===false,'no predictable impersonation secret');
}
// Pure-PHP CBOR + COSE -> SPKI PEM conversion check (runs even without ext-openssl)
$sampleX=str_repeat("\x11",32);$sampleY=str_repeat("\x22",32);
$sampleCose="\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20".$sampleX."\x22\x58\x20".$sampleY;
$off=0;$decodedCose=bc_cbor_decode($sampleCose,$off);
bb_check(is_array($decodedCose) && ($decodedCose[1]??0)===2 && ($decodedCose[-2]??'')===$sampleX,'CBOR decodes ES256 COSE key');
$samplePem=bc_webauthn_cose_to_pem($decodedCose);
bb_check(strpos($samplePem,'-----BEGIN PUBLIC KEY-----')===0 && strpos($samplePem,'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE')!==false,'COSE ES256 encodes valid P-256 SPKI PEM');
// Cryptographic ES256 CBOR/COSE + OpenSSL verification roundtrip when ext-openssl is available
$ecKey=function_exists('openssl_pkey_new')?@openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]):false;
if($ecKey){
 $det=openssl_pkey_get_details($ecKey);
 $x=str_pad((string)$det['ec']['x'],32,"\x00",STR_PAD_LEFT);
 $y=str_pad((string)$det['ec']['y'],32,"\x00",STR_PAD_LEFT);
 $cose="\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20".$x."\x22\x58\x20".$y;
 $rp='example.com';
 $credId="cred-1234";
 $authData=hash('sha256',$rp,true)."\x41\x00\x00\x00\x01".str_repeat("\x00",16).pack('n',strlen($credId)).$credId.$cose;
 // Minimal CBOR map {"fmt":"none","authData":bstr}
 $attCbor="\xa2\x63fmt\x64none\x68authData\x58".chr(strlen($authData)).$authData;
 $chal=bc_webauthn_b64u_encode(random_bytes(16));
 $regClient=bc_webauthn_b64u_encode(json_encode(['type'=>'webauthn.create','challenge'=>$chal,'origin'=>'https://'.$rp]));
 $attOut=bc_webauthn_verify_attestation($regClient,bc_webauthn_b64u_encode($attCbor),$chal,$rp);
 bb_check(!empty($attOut['ok']),'ES256 attestation verified');
 $getAuthData=hash('sha256',$rp,true)."\x05\x00\x00\x00\x02";
 $getClientJson=json_encode(['type'=>'webauthn.get','challenge'=>$chal,'origin'=>'https://'.$rp]);
 $sig='';openssl_sign($getAuthData.hash('sha256',$getClientJson,true),$sig,$ecKey,OPENSSL_ALGO_SHA256);
 $assOk=bc_webauthn_verify_assertion(bc_webauthn_b64u_encode($getClientJson),bc_webauthn_b64u_encode($getAuthData),bc_webauthn_b64u_encode($sig),$attOut['public_key_pem'],$chal,$rp);
 bb_check(!empty($assOk['ok']),'ES256 assertion signature verified');
 $assBad=bc_webauthn_verify_assertion(bc_webauthn_b64u_encode($getClientJson),bc_webauthn_b64u_encode($getAuthData),bc_webauthn_b64u_encode($sig^str_repeat("\x01",strlen($sig))),$attOut['public_key_pem'],$chal,$rp);
 bb_check(empty($assBad['ok']),'tampered ES256 assertion rejected');
}
echo "$n browser credential binding checks passed\n";
