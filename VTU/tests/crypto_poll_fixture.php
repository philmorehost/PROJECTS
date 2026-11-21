<?php
/** Offline fixture only, never provider-authenticated evidence. */
function poll_fixture($doc,$row,$next='',$record='1',$index='0') {
    $q=$doc['query'];if($doc['cursor']!=='')$q['cursor']=$doc['cursor'];$now=time();
    $r=['id'=>$record,'coin'=>'USDT','chain'=>'TRX','amount'=>'1.000001','txID'=>str_repeat('a',64),'txIndex'=>$index,'status'=>3,'toAddress'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','tag'=>'','depositFee'=>'','successAt'=>'1700000000000','confirmations'=>'50','depositType'=>'0'];
    return ['provider'=>'bybit','evidence_kind'=>'deposit_page_inspection_v1','environment'=>$row['environment'],'custody_account'=>$row['custody_account'],'source_vendor_id'=>(int)$row['vendor_id'],'source_config_fingerprint'=>$doc['config_fingerprint'],'authenticated_read_completed'=>true,'identity_checked_before_and_after'=>true,'query'=>$q,'page'=>bc_crypto_bybit_deposit_page(['rows'=>[$r],'nextPageCursor'=>$next],'USDT',6,$doc['cursor']),'started_at'=>$now,'verified_at'=>$now,'expires_at'=>$now+900,'import_enabled'=>false,'settlement_enabled'=>false,'live_enabled'=>false,'history_complete'=>false,'query_window_membership_verified'=>false,'cursor_chain_verified'=>false,'cursor_advance_authorized'=>false,'customer_attribution_verified'=>false,'custody_eligibility_verified'=>false,'reported_account_kind'=>'master','reported_parent_account'=>null];
}
