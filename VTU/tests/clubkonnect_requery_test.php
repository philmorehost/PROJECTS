<?php
/** Offline contract checks: no credentials, database, wallet writes or provider requests. */
if (extension_loaded('curl')) throw new RuntimeException('Run php -n.');
foreach (array('CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYHOST','CURLOPT_SSL_VERIFYPEER','CURLOPT_FOLLOWLOCATION','CURLOPT_POST','CURLOPT_RETURNTRANSFER','CURLOPT_HTTPHEADER','CURLOPT_POSTFIELDS','CURLOPT_HTTPGET','CURLOPT_USERAGENT') as $i=>$c) define($c,100+$i);
define('CURLINFO_HTTP_CODE',901); define('CURLINFO_TOTAL_TIME',902);
function curl_init($url) { $GLOBALS['last_url']=$url; return (object)array(); }
function curl_setopt($h,$k,$v) { return true; }
function curl_exec($h) { $GLOBALS['calls']++; return $GLOBALS['body']; }
function curl_errno($h) { return $GLOBALS['err']; }
function curl_error($h) { return ''; }
function curl_getinfo($h,$k=null) { return $k===CURLINFO_HTTP_CODE ? $GLOBALS['http'] : 0.01; }
function curl_close($h) {}
function getTransaction($ref,$field) { return 'Transaction Pending | data purchase'; }
$n=0;
function ck_check($ok,$label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; }
function ck_case($ed,$body,$expected,$order='provider-order',$http=200,$err=0) {
    $api_detail=array('api_key'=>'synthetic user:key&with:colon');
    $requery_reference='123456789012345'; $get_api_reference_id=$order; $purchase_method='CRON_JOB';
    $GLOBALS['body']=is_array($body)?json_encode($body):$body;
    $GLOBALS['http']=$http; $GLOBALS['err']=$err; $GLOBALS['calls']=0;
    include dirname(__DIR__).'/DGV7.0-'.$ed.'/func/api-gateway/requery/dd-data-clubkonnect-com.php';
    ck_check($api_response===$expected,$ed.' verdict '.json_encode($body));
    ck_check($GLOBALS['calls']===1,'Query exactly once, never purchase/retry');
    parse_str(parse_url($GLOBALS['last_url'],PHP_URL_QUERY),$query);
    ck_check($query['APIKey']==='key&with:colon' && $query['UserID']==='synthetic user','Credentials encoded and not truncated');
    ck_check(strpos($GLOBALS['last_url'],'/APIQueryV1.asp?')!==false,'Query endpoint only');
    ck_check($order!=='' ? ($query['OrderID']===$order && !isset($query['RequestID'])) : ($query['RequestID']===$requery_reference && !isset($query['OrderID'])),'OrderID preferred, RequestID fallback');
}
foreach (array('SAAS','NON-SAAS') as $ed) {
    ck_case($ed,array('OrderID'=>'provider-order','StatusCode'=>200,'Status'=>'ORDER_COMPLETED'),'successful');
    ck_case($ed,array('orderid'=>'provider-order','statuscode'=>'200','orderstatus'=>'ORDER_COMPLETED'),'successful','');
    foreach (array(100,199,201,299,300,399,400,401,402,403,412,417,499,521,600,602,604,699,799) as $code)
        ck_case($ed,array('statuscode'=>$code,'status'=>'ORDER_COMPLETED'),'pending');
    foreach (array('NOT_COMPLETED','UNSUCCESSFUL','NOT_DELIVERED','INVALID_CREDENTIALS') as $state)
        ck_case($ed,array('statuscode'=>200,'status'=>$state),'pending');
    foreach (array(500,501,509,511,518,599) as $code)
        ck_case($ed,array('StatusCode'=>$code,'OrderStatus'=>'ORDER_CANCELLED'),'failed');
    ck_case($ed,array('statuscode'=>899,'orderstatus'=>'ORDER_REFUNDED'),'failed');
    ck_case($ed,array('statuscode'=>500,'status'=>'ORDER_COMPLETED'),'pending');
    ck_case($ed,array('orderid'=>'different-order','statuscode'=>200,'status'=>'ORDER_COMPLETED'),'pending');
    ck_case($ed,'<html>Transaction Successful</html>','pending');
    ck_case($ed,'{"statuscode":200','pending');
    ck_case($ed,array('status'=>'MISSING_CREDENTIALS'),'pending');
    ck_case($ed,array('statuscode'=>200,'status'=>'ORDER_COMPLETED'),'pending','provider-order',403);
    ck_case($ed,array('statuscode'=>200,'status'=>'ORDER_COMPLETED'),'pending','provider-order',200,28);
    $base=dirname(__DIR__).'/DGV7.0-'.$ed;
    $legacy=file_get_contents($base.'/automated-cron-requery.php');
    ck_check(strpos($legacy,"require __DIR__ . '/cron/process_requery_queue.php'")!==false,'Legacy route uses authenticated worker');
    $worker=file_get_contents($base.'/cron/process_requery_queue.php');
    ck_check(strpos($worker,"PHP_SAPI !== 'cli'")!==false && strpos($worker,'hash_equals')!==false,'Worker requires CLI or secret');
    $handler=file_get_contents($base.'/web/func/requery-transaction.php');
    ck_check(strpos($handler,'WHERE $requery_scope AND reference=')!==false,'Scoped reconciliation lookup');
    $admin=file_get_contents($base.'/bc-admin/Transactions.php');
    ck_check(strpos($admin,'http_response_code(405)')!==false && strpos($admin,"'transaction_requery'")!==false && strpos($admin,"hash_equals((string)\$_SESSION['bc_admin_transactions_csrf']")!==false,'Admin GET denial and POST step-up/CSRF');
}
echo "$n ClubKonnect requery checks passed; offline only.\n";
