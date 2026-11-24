<?php
function bc_security_question_decode($raw) {
 $default=['web'=>true,'app'=>true];
 if(!is_string($raw)||$raw==='')return $default;
 $p=json_decode($raw,true);
 return is_array($p)&&count($p)===2&&is_bool($p['web']??null)&&is_bool($p['app']??null)?$p:$default;
}
function bc_security_question_policy($vid) {
 // Always fresh; malformed/unavailable settings can never silently disable a security gate.
 if((int)$vid<=0)return ['web'=>true,'app'=>true];
 try{return bc_security_question_decode(getVendorOption((int)$vid,'security_question_policy',''));}
 catch(Throwable $e){return ['web'=>true,'app'=>true];}
}
