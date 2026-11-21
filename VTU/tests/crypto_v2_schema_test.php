<?php
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-v2-schema.php';
$n=0;function schema_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$sql=implode(";\n",bc_crypto_v2_schema_statements());
schema_check(count(bc_crypto_v2_schema_statements())===4,'four isolated additive tables');
schema_check(strpos($sql,'UPDATE ')===false && strpos($sql,'DROP ')===false && strpos($sql,'TRUNCATE ')===false,'schema never rewrites legacy balances');
schema_check(substr_count($sql,'ENGINE=InnoDB')===4,'all transactional tables');
foreach(['operation_reference(vendor_id,environment,reference)','release_once(parent_id)','one_entry_per_account(operation_id,account_id)','wallet_bucket(vendor_id,user_id,environment,asset_network,bucket)','DECIMAL(65,0) UNSIGNED','DEFAULT 0',"DEFAULT 'blocked'",'COLLATE ascii_bin'] as $required)schema_check(strpos($sql,$required)!==false,'schema constraint '.$required);
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $ed){
 $root=dirname(__DIR__).'/'.$ed;
 foreach(['bc-crypto-decimal.php','bc-crypto-reservations.php','bc-crypto-v2-schema.php','bc-crypto-v2-reconcile.php','crypto-v2-manage.php'] as $file){
  $s=file_get_contents($root.'/func/'.$file);token_get_all($s,TOKEN_PARSE);
  schema_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/'.$file),'edition parity '.$file);
 }
 $bootstrap=file_get_contents($root.'/func/bc-connect.php');schema_check(strpos($bootstrap,'bc-crypto-v2-schema')===false,'no normal-page schema work');
 $entry=file_get_contents($root.'/func/crypto-v2-manage.php');schema_check(strpos($entry,"PHP_SAPI !== 'cli'")<strpos($entry,"bc-connect.php"),'CLI gate before application bootstrap');
 schema_check(strpos($entry,'BC_CRYPTO_V2_SCHEMA_APPROVED')!==false && strpos($entry,"'--apply'")!==false,'explicit reviewed migration opt-in');
 $store=file_get_contents($root.'/func/bc-crypto-reservations.php');
 schema_check(strpos($store,'FOR UPDATE')!==false && strpos($store,'mysqli_rollback')!==false,'row locks and rollback');
 schema_check(strpos($store,'sas_user_crypto_wallets')===false && strpos($store,'bc_bybit_')===false,'no legacy wallet or provider side effects');
 $reconcile=file_get_contents($root.'/func/bc-crypto-v2-reconcile.php');
 schema_check(strpos($reconcile,'MYSQLI_TRANS_START_READ_ONLY')!==false && strpos($reconcile,'REPEATABLE READ')!==false,'read-only consistent integrity snapshot');
 schema_check(strpos($reconcile,"'provider_reserves_verified'=>false")!==false,'integrity not misrepresented as proof of reserves');
 schema_check(strpos($reconcile,'UPDATE ')===false,'reconciliation never marks itself approved or mutates policies');
}
echo "$n schema/integrity source checks passed; live database tests remain required\n";
