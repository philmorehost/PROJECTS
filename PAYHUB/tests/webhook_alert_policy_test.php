<?php
require_once __DIR__.'/../includes/webhook-alert.php';
class AlertFixturePDO extends PDO {public function __construct(){}public function inTransaction():bool{return false;}}
class AlertTransactionPDO extends AlertFixturePDO {public function inTransaction():bool{return true;}}
$n=0;
function alertcheck($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function alertreject($f,$label){try{$f();}catch(Throwable $e){alertcheck(true,$label);return;}throw new RuntimeException('accepted '.$label);}
function alertpage($after,$scanned,$more=false,$category='claimed_succeeded',$aged=0){
 $counts=array_fill_keys(['unattempted','existing_evidence','claimed_attempting','claimed_unknown','claimed_succeeded','requires_review'],0);$old=$counts;$counts[$category]=$scanned;$old[$category]=$aged;
 return ['scope'=>'bounded_outbox_page_not_global_totals','advisory_only'=>true,'scanned'=>$scanned,'after'=>$after,'next_after'=>$after+$scanned,'has_more'=>$more,'age_seconds'=>900,'counts'=>$counts,'aged_counts'=>$old];
}
$db=new AlertFixturePDO();
$single=static fn($page)=>static fn()=> $page;
$r=payhub_outbox_alert($db,5,100,900,$single(alertpage(0,0)));alertcheck($r['exit_code']===0&&$r['complete']&&$r['scanned']===0,'empty scan is no signal not settlement health');
foreach(['unattempted','existing_evidence','claimed_attempting','claimed_succeeded'] as $state){$r=payhub_outbox_alert($db,5,100,900,$single(alertpage(0,1,false,$state)));alertcheck($r['exit_code']===0,'fresh state '.$state);}
foreach(['unattempted'=>1,'existing_evidence'=>1,'claimed_attempting'=>2,'claimed_succeeded'=>0,'claimed_unknown'=>2,'requires_review'=>2] as $state=>$code){$r=payhub_outbox_alert($db,5,100,900,$single(alertpage(0,1,false,$state,1)));alertcheck($r['exit_code']===$code,'aged severity '.$state);}
foreach(['claimed_unknown','requires_review'] as $state){$r=payhub_outbox_alert($db,5,100,900,$single(alertpage(0,1,false,$state)));alertcheck($r['exit_code']===2,'fresh uncertainty visible '.$state);}
$calls=0;$reader=function($db,$after,$limit,$age)use(&$calls){$calls++;alertcheck($limit===2&&$age===900,'bounded reader arguments');return alertpage($after,2,$calls<3,'unattempted',1);};
$r=payhub_outbox_alert($db,3,2,900,$reader);alertcheck($calls===3&&$r['complete']&&$r['counts']['unattempted']===6&&$r['aged_counts']['unattempted']===3&&$r['next_after']===6,'aggregate disjoint pages');
$calls=0;$r=payhub_outbox_alert($db,2,2,900,$reader);alertcheck($calls===2&&!$r['complete']&&$r['exit_code']===3&&in_array('scan_limit_reached',$r['reasons'],true),'cap cannot produce green');
$r=payhub_outbox_alert($db,1,1,900,$single(alertpage(0,1,true,'claimed_unknown')));alertcheck($r['exit_code']===3&&in_array('uncertain_delivery_requires_review',$r['reasons'],true),'incomplete retains critical evidence');
foreach([[0,100,900],[51,100,900],[1,0,900],[1,201,900],[1,100,59],[1,100,604801],['5',100,900]] as $args)alertreject(fn()=>payhub_outbox_alert($db,...$args),'invalid bounds');
alertreject(fn()=>payhub_outbox_alert(new AlertTransactionPDO()),'caller transaction');
$mutations=[['scope'=>'wrong'],['advisory_only'=>false],['after'=>1],['age_seconds'=>60],['has_more'=>1],['scanned'=>-1],['scanned'=>101],['next_after'=>0],['next_after'=>2147483648],['next_after'=>'1'],['counts'=>[]],['aged_counts'=>[]]];
foreach($mutations as $change){$page=array_replace(alertpage(0,1),$change);alertreject(fn()=>payhub_outbox_alert($db,5,100,900,$single($page)),'malformed observation');}
$page=alertpage(0,1);$page['counts']['claimed_succeeded']=-1;alertreject(fn()=>payhub_outbox_alert($db,5,100,900,$single($page)),'negative counts');
$page=alertpage(0,1);$page['aged_counts']['claimed_succeeded']=2;alertreject(fn()=>payhub_outbox_alert($db,5,100,900,$single($page)),'aged exceeds count');
$page=alertpage(0,1);$page['counts']['unattempted']=1;alertreject(fn()=>payhub_outbox_alert($db,5,100,900,$single($page)),'inconsistent total');
alertreject(fn()=>payhub_outbox_alert($db,5,100,900,$single(alertpage(0,0,true))),'empty progressing page refused');
alertreject(fn()=>payhub_outbox_alert($db,5,100,900,$single(alertpage(0,1,true))),'short more page refused');
alertreject(fn()=>payhub_outbox_alert($db,5,100,900,static function(){throw new RuntimeException('fixture confidential');}),'reader failure propagates not empty/healthy');
$encoded=json_encode($r);foreach(['payload','webhook_url','secret_key','customer','fixture confidential'] as $field)alertcheck(strpos($encoded,$field)===false,'no sensitive field '.$field);
echo "PASS $n outbox alert policy checks (no database, DNS or HTTP)\n";
