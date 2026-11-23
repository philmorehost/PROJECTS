<?php
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-giftcard-func.php';
$connection_server=null;$_SESSION=['sentinel'=>'unchanged'];$before=$_SESSION;$n=0;
function gc_hold_check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach(['purchase_card','purchase','buy_p2p','send_otp'] as $action)gc_hold_check(bc_giftcard_action_held($action),'financial/code action held');
foreach(['my_cards','list_products','p2p_market','list_for_sale','cancel_listing','transfer_card',null,[]] as $action)gc_hold_check(!bc_giftcard_action_held($action),'unrelated action not blocked by financial hold');
foreach(['valid-token',false] as $token){$result=placeReloadlyOrder($token,123,50,1,'fixture@example.test');gc_hold_check($result['status']==='FAILED' && $result['code']==='GIFTCARD_SETTLEMENT_HOLD','direct provider order never proceeds');}
foreach([['shared-name',100],['shared-name',-100],['',0]] as $input)gc_hold_check(holdP2PFunds(...$input)===false,'no new escrow obligations');
foreach([1,-1,0] as $id)gc_hold_check(releaseP2PFunds($id)===false,'no legacy release/refund');
gc_hold_check($_SESSION===$before,'no session/auth mutation');
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=__DIR__.'/../DGV7.0-'.$ed;
 $s=file_get_contents($root.'/web/GiftCard.php');gc_hold_check(strpos($s,'if(bc_giftcard_action_held($action))bc_giftcard_safety_reply();')<strpos($s,"if (\$action == 'purchase_card')"),'web gate before financial handlers');
 $s=file_get_contents($root.'/web/api/gift-card.php');gc_hold_check(strpos($s,'if(bc_giftcard_action_held($action))bc_giftcard_safety_reply();')<strpos($s,"elseif (\$action === 'purchase')"),'app/API gate before financial handlers');
 $s=file_get_contents($root.'/func/bc-giftcard-func.php');$start=strpos($s,'function holdP2PFunds(');gc_hold_check(strpos(substr($s,$start),'mysqli_')===false && strpos(substr($s,$start),'chargeOtherUser')===false,'old unscoped escrow/wallet implementation removed');
 $s=file_get_contents($root.'/web/GiftCard.php');gc_hold_check(strpos($s,'disabled>Purchase paused')!==false && strpos($s,'disabled title="P2P settlement is paused"')!==false,'UI communicates financial hold');
}
echo "$n $edition gift-card settlement hold checks passed\n";
