<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-custody-evidence.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-custody-evidence-schema.php';
$n=0;
function ev_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function ev_reject($fn,$code){try{$fn();}catch(Throwable $e){ev_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$code);}
$identity=bc_crypto_custody_identity('testnet','777123');$text="Synthetic custody review: documented provider account scope and independent legal approval reference FIXTURE-001.\nNo live account was inspected.";
foreach(['short',str_repeat('a',16385),"01234567890123456789\x00",['invalid'],"Review: APIKey=do-not-store-credentials-here",'Synthetic mnemonic phrase must not be submitted'] as $bad)ev_reject(fn()=>bc_crypto_custody_evidence_text($bad),is_string($bad)&&preg_match('/APIKey|mnemonic/',$bad)?'crypto_evidence_sensitive_material':'crypto_evidence_text_invalid');
putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1');
ev_reject(fn()=>bc_crypto_custody_evidence_seal($identity,'testnet',$text),'crypto_evidence_key_unavailable');
if (function_exists('openssl_encrypt')) {
 putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1='.base64_encode(str_repeat('x',32)));
 putenv('BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY=V1');
 $a=bc_crypto_custody_evidence_seal($identity,'testnet',$text);$b=bc_crypto_custody_evidence_seal($identity,'testnet',$text);
 ev_check($a['sealed']!==$b['sealed']&&$a['hash']===$b['hash'],'Random nonces and stable exact evidence digest');
 ev_check(strpos($a['sealed'],$text)===false,'No plaintext stored');
 ev_check(bc_crypto_custody_evidence_open($identity,'testnet',$a['hash'],$a['sealed'])===$text,'Exact UTF-8 document roundtrip');
 ev_reject(fn()=>bc_crypto_custody_evidence_open(str_repeat('f',64),'testnet',$a['hash'],$a['sealed']),'crypto_evidence_unreadable');
 ev_reject(fn()=>bc_crypto_custody_evidence_open($identity,'mainnet',$a['hash'],$a['sealed']),'crypto_evidence_unreadable');
 ev_reject(fn()=>bc_crypto_custody_evidence_open($identity,'testnet',str_repeat('a',64),$a['sealed']),'crypto_evidence_unreadable');
 $damaged=json_decode($a['sealed'],true);$damaged['tag']=base64_encode(str_repeat('0',16));
 ev_reject(fn()=>bc_crypto_custody_evidence_open($identity,'testnet',$a['hash'],json_encode($damaged)),'crypto_evidence_unreadable');
 putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V2='.base64_encode(str_repeat('y',32)));putenv('BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY=V2');
 $rotated=bc_crypto_custody_evidence_seal($identity,'testnet',$text);
 ev_check(bc_crypto_custody_evidence_open($identity,'testnet',$a['hash'],$a['sealed'])===$text && bc_crypto_custody_evidence_open($identity,'testnet',$rotated['hash'],$rotated['sealed'])===$text,'Old key retained for decryption during rotation');
 putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1');
 ev_reject(fn()=>bc_crypto_custody_evidence_open($identity,'testnet',$a['hash'],$a['sealed']),'crypto_evidence_key_unavailable');
} else {echo "OpenSSL encryption cases require native CI; not executed in this runtime.\n";}
foreach(['SAAS','NON-SAAS'] as $ed){
 $base=dirname(__DIR__).'/DGV7.0-'.$ed.'/';
 foreach(['bc-crypto-custody-evidence.php','bc-crypto-custody-evidence-schema.php','bc-crypto-custody-management.php','bc-crypto-custody-page.php'] as $file){$src=file_get_contents($base.'func/'.$file);token_get_all($src,TOKEN_PARSE);ev_check($src===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$file),'edition parity '.$file);}
 $src=file_get_contents($base.'func/bc-crypto-custody-management.php');
 ev_check(strpos($src,'bc_validate_csrf();')<strpos($src,'bc_crypto_custody_authorize($session_id,$input);'),'CSRF before authority dispatch');
 $page=file_get_contents($base.'func/bc-crypto-custody-page.php');
 ev_check(strpos($page,"!\$platform && \$method==='POST'")!==false,'Vendor mutation denied');
 ev_check(strpos($page,'$e($evidence)')!==false&&strpos($page,'no-store, private')!==false,'Evidence escaped and response non-cacheable');
 ev_check(strpos($page,"\$_SESSION['evidence")===false,'No plaintext evidence in session');
 ev_check(strpos(file_get_contents($base.'func/crypto-v2-manage.php'),'bc_crypto_custody_evidence_schema_statements()')!==false,'Explicit schema preview includes evidence tables');
}
echo "$n protected custody evidence checks passed; no live provider or funds.\n";
