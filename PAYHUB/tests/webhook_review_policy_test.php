<?php
require_once __DIR__.'/../includes/webhook-review.php';
$n=0;function reviewcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function reviewreject($f){try{$f();}catch(Throwable $e){reviewcheck(true,'refused');return;}throw new RuntimeException('Invalid review accepted.');}
$input=['request_id'=>'review-fixture-0001','actor_ref'=>'operator-fixture','evidence_ref'=>'case-fixture-0001','evidence_sha256'=>str_repeat('a',64),'observation_sha256'=>str_repeat('b',64),'finding'=>'escalated'];
foreach(['recipient_reports_received','receipt_not_confirmed','escalated'] as $finding){$valid=$input;$valid['finding']=$finding;payhub_review_input($valid);reviewcheck(true,'valid finding');}
foreach(['request_id','actor_ref','evidence_ref'] as $field){foreach(['short','https://example.test/evidence',"reference\nsecret",'user@example.test',str_repeat('x',121),null,12,'../private'] as $value){$bad=$input;$bad[$field]=$value;reviewreject(fn()=>payhub_review_input($bad));}}
foreach(['evidence_sha256','observation_sha256'] as $field){foreach(['',str_repeat('A',64),str_repeat('a',63),str_repeat('a',65),null,42] as $value){$bad=$input;$bad[$field]=$value;reviewreject(fn()=>payhub_review_input($bad));}}
foreach(['settled','resolved','retry','succeeded',null] as $value){$bad=$input;$bad['finding']=$value;reviewreject(fn()=>payhub_review_input($bad));}
$bad=$input;$bad['notes']='raw sensitive evidence';reviewreject(fn()=>payhub_review_input($bad));$bad=$input;unset($bad['actor_ref']);reviewreject(fn()=>payhub_review_input($bad));
foreach([0,-1,'1',2147483648,null] as $id)reviewreject(fn()=>payhub_review_id($id));payhub_review_id(2147483647);reviewcheck(true,'bounded integer ID');
// Static containment guard: journal is not added to any dispatch/claim/settlement predicate.
foreach(['webhook-recovery.php','webhook-initial.php','webhook-retry.php','webhook-outbox.php'] as $file)reviewcheck(strpos(file_get_contents(__DIR__.'/../includes/'.$file),'webhook_review_journal')===false,'review cannot authorize delivery');
echo "PASS $n review policy checks (no database, network or financial operations)\n";
