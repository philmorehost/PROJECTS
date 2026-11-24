<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-service-attempt-review.php';
function bc_adm_head(...$args){}function bc_adm_sidebar(...$args){}function bc_adm_topbar(...$args){}function bc_adm_foot(...$args){}
$n=0;function rr($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
foreach(['SAAS','NON-SAAS'] as $edition){foreach(['list','detail','unavailable','empty'] as $mode){
 $bad='<img src=x onerror=alert(1)>';$row=['id'=>7,'reference'=>$bad,'type'=>$bad,'requested_provider'=>$bad,'intake_status'=>$bad,'journal_state'=>'unknown','version'=>0,'attempt_provider'=>$bad,'routing_owner_changed'=>1,'has_review_events'=>1];
 $detail=$mode==='detail'?['request'=>$row,'events'=>[['created_at'=>$bad,'observed_outcome'=>$bad,'disposition'=>$bad,'result_state'=>'unknown','result_version'=>0]],'events_truncated'=>true]:null;
 $page=['rows'=>$mode==='list'?[$row]:[],'next'=>$mode==='list'?7:null];$before=null;$error=$mode==='unavailable'?'Reconciliation view unavailable.':'';
 $source=file_get_contents(__DIR__.'/../DGV7.0-'.$edition.'/bc-admin/ServiceReconciliation.php');$template=substr($source,strpos($source,'$esc='));ob_start();eval($template);$html=ob_get_clean();
 rr(strpos($html,$bad)===false,'no unescaped adversarial values');rr(strpos($html,'<form')===false,'no write forms');rr(strpos($html,'Financial holds remain')!==false,'hold warning visible');
 if(in_array($mode,['list','detail'],true)){rr(strpos($html,'&lt;img')!==false,'escaped values visible');rr(strpos($html,'table-responsive')!==false,'responsive table');rr(strpos($html,'scope="col"')!==false,'table headers accessible');}
 if($mode==='detail')rr(strpos($html,'Older observations are omitted')!==false,'timeline truncation explicit');
 if($mode==='empty')rr(strpos($html,'No requests on this page')!==false,'empty not a failure claim');
 if($mode==='unavailable')rr(strpos($html,'role="alert"')!==false,'unavailable explicit');
}}
echo "$n service reconciliation render checks passed\n";
