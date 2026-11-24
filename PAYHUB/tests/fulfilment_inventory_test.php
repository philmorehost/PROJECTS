<?php
/** Reviewed source-surface inventory, not proof of runtime/provider/ledger correctness.
 * Adding a new fulfilment surface must update the manifest AND its transactional tests.
 */
$expected=[
 'api/transaction/reconcile.php'=>'api_reconcile',
 'api/transaction/simulate.php'=>'simulation',
 'api/transaction/verify.php'=>'api_verify',
 'api/virtual-accounts.php'=>'va_reconcile',
 'includes/functions.php'=>'dva_reconcile',
 'includes/transaction-expiry-cron.php'=>'expiry_cron',
 'merchant/reconcile.php'=>'merchant_reconcile',
 'verify.php'=>'browser_verify',
 'webhook-paystack.php'=>'paystack_forward'
];
$pattern=<<<'RX'
/\blog_ledger_entry\s*\(\s*[^,;]+,\s*[^,;]+,\s*['"]credit['"]\s*,\s*['"]payment['"]\s*,/
RX;
$root=dirname(__DIR__);$found=[];$sources=[];$n=0;
function inventorycheck($ok,$label){global $n;$n++;if(!$ok)throw new RuntimeException($label);}
$iterator=new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),static function($file){return !$file->isDir() || !in_array($file->getFilename(),['tests','vendor','node_modules','.git'],true);}));
foreach($iterator as $file){
 if(!$file->isFile() || $file->getExtension()!=='php')continue;
 $rel=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));$source='';
 foreach(token_get_all(file_get_contents($file->getPathname())) as $token){$source.=is_array($token)?(in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)?str_repeat("\n",substr_count($token[1],"\n")):$token[1]):$token;}
 if(preg_match_all($pattern,$source,$matches,PREG_OFFSET_CAPTURE)){
  inventorycheck(count($matches[0])===1,'review new payment-credit site in '.$rel);$found[$rel]=true;$sources[$rel]=[$source,$matches[0][0][1]];
 }
}
$paths=array_keys($found);$reviewed=array_keys($expected);sort($paths);sort($reviewed);inventorycheck($paths===$reviewed,'payment-credit surface changed; review inventory and add transactional tests');
foreach($expected as $path=>$producer){
 [$source,$credit]=$sources[$path];
 $call=match($producer){'browser_verify'=>'payhub_enqueue_verify_outbox(', 'paystack_forward'=>'payhub_enqueue_paystack_outbox(', 'api_verify'=>'payhub_enqueue_api_verify_outbox(', default=>'payhub_enqueue_generic_outbox('};
 $enqueue=strpos($source,$call,$credit);$commit=$enqueue===false?false:strpos($source,'$db->commit();',$enqueue);
 inventorycheck($enqueue!==false && $commit!==false && $credit<$enqueue && $enqueue<$commit,'payment credit must enqueue before commit: '.$path);
 if(!in_array($producer,['browser_verify','paystack_forward'],true)){
  $dispatch=strpos($source,'trigger_merchant_webhook(',$commit);inventorycheck($dispatch!==false && $commit<$dispatch,'generic dispatch after commit: '.$path);
 }
}
$simulation=file_get_contents($root.'/api/transaction/simulate.php');
foreach(["AND user_id = ?","AND is_test = 1","AND status = 'pending'","AND BINARY checkout_token = ?",'$stmt->rowCount() !== 1','SIMULATION_NOT_CLAIMED'] as $needle)inventorycheck(strpos($simulation,$needle)!==false,'atomic sandbox gate '.$needle);
echo "PASS $n fulfilment source inventory checks; 9 reviewed payment-credit surfaces (not full security certification)\n";
