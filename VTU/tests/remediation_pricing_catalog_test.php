<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-price-input.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-marketplace-catalog.php';
$n=0;
function repair_check($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
foreach(['0','0.00','12','12.50',0,'9999999999.99'] as $v)repair_check(bc_price_input($v)!==null,'valid nonnegative price');
foreach(['','-1','-0.01','NaN','INF','1e3','1,000','12x','1.234','10000000000',[],null] as $v)repair_check(bc_price_input($v)===null,'reject malformed price without stripping into a different amount');
$entries=bc_marketplace_bundled_catalog();
repair_check(count($entries)>30,'bundled inventory populated');
$keys=[];
foreach($entries as $entry){
 repair_check(!isset($keys[$entry['type'].':'.$entry['host']]),'deduplicated transport variants');
 $keys[$entry['type'].':'.$entry['host']]=true;
 repair_check(strpos($entry['host'],'localserver')===false && !bc_retired_vtu_provider($entry['host']),'exclude local dispatcher and retired providers');
 repair_check(strpos($entry['host'],'/')===false,'host not a path');
}
repair_check(isset($keys['airtime:clubkonnect.com']),'real airtime adapter recognized');
repair_check(isset($keys['sme-data:clubkonnect.com']),'hyphenated category recognized');
repair_check(isset($keys['bulk-sms:philmoresms.com']),'SMS variants canonicalized');
$src=file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-marketplace-catalog.php');
repair_check(strpos($src,"'2' WHERE NOT EXISTS")!==false,'new bundled listings private by default');
repair_check(strpos($src,'UPDATE sas_api_marketplace_listings')===false,'existing admin changes never overwritten');
repair_check(strpos($src,'INSERT INTO sas_apis')===false,'inventory never activates tenant APIs');
repair_check(!file_exists(__DIR__.'/../DGV7.0-NON-SAAS/func/bc-marketplace-catalog.php'),'NON-SAAS marketplace untouched');
foreach(['SAAS','NON-SAAS'] as $ed){
 $src=file_get_contents(__DIR__.'/../DGV7.0-'.$ed.'/bc-admin/UserUpgrade.php');
 repair_check(strpos($src,'bc_paid_level_change(')!==false,'admin level upgrade uses atomic entitlement purchase');
 $core=file_get_contents(__DIR__.'/../DGV7.0-'.$ed.'/func/bc-paid-entitlements.php');
 repair_check(strpos($core,"if(\$price!=='0.00')")!==false,'free admin level upgrade skips wallet debit');
 $src=file_get_contents(__DIR__.'/../DGV7.0-'.$ed.'/bc-admin/AccountSettings.php');
 repair_check(strpos($src,"if ((string)\$each_level_id === '1') \$each_level_percent = '0';")!==false,'Smart Earner remains free');
}
echo "$n strict price and bundled catalogue checks passed\n";
