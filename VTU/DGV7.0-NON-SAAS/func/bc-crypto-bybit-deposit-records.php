<?php
/** Offline result parser only. No HTTP, checkpoint, observation import or wallet writes. */
require_once __DIR__.'/bc-crypto-deposits.php';
require_once __DIR__.'/bc-crypto-bybit-address.php';
function bc_crypto_bybit_deposit_cursor($cursor) {
    if (!is_string($cursor)||strlen($cursor)>2048||($cursor!==''&&!preg_match('/^[\x21-\x7e]+$/D',$cursor))) throw new RuntimeException('crypto_bybit_deposit_cursor_invalid');
    return $cursor; // Opaque token, never decode, trim or rebuild it.
}
function bc_crypto_bybit_deposit_status($status) {
    if (!is_int($status)) throw new RuntimeException('crypto_bybit_deposit_status_unknown');
    $map=[0=>['pending','provider_unknown'],1=>['pending','awaiting_confirmation'],2=>['pending','provider_processing'],
        3=>['complete','provider_success_not_settlement'],4=>['failed','provider_failed'],
        7=>['reorged','rollback_processing'],70011=>['reorged','rollback_reversed'],
        70012=>['reorged','rollback_review_manual'],70013=>['reorged','rollback_failed_manual'],
        10011=>['pending','funding_pool_pending'],10012=>['pending','funding_pool_only']];
    if (!isset($map[$status])) throw new RuntimeException('crypto_bybit_deposit_status_unknown');
    return ['status'=>$map[$status][0],'reason'=>$map[$status][1]];
}
/** Deliberately supports only canonical TRON identifiers and six-decimal TRX/USDT. */
function bc_crypto_bybit_deposit_record($row,$coin,$scale) {
    if (!in_array($coin,['TRX','USDT'],true)||$scale!==6) throw new RuntimeException('crypto_bybit_deposit_scope_unsupported');
    if (!is_array($row)||($row['coin']??null)!==$coin||($row['chain']??null)!=='TRX') throw new RuntimeException('crypto_bybit_deposit_scope_mismatch');
    $id=$row['id']??null;$tx=$row['txID']??null;$index=$row['txIndex']??null;
    if (!is_string($id)||!preg_match('/^[1-9][0-9]{0,63}$/D',$id)||!is_string($tx)||!preg_match('/^[a-f0-9]{64}$/D',$tx)||!is_string($index)||!preg_match('/^(?:0|[1-9][0-9]{0,8})$/D',$index)) throw new RuntimeException('crypto_bybit_deposit_identity_invalid');
    $address=bc_crypto_tron_address_validate($row['toAddress']??null);
    if (($row['tag']??null)!=='') throw new RuntimeException('crypto_bybit_deposit_tag_unsupported');
    $units=bc_crypto_to_units($row['amount']??null,$scale);
    if ($units==='0') throw new RuntimeException('crypto_bybit_deposit_zero_amount');
    // An empty provider fee means unknown, not zero. No gross/net credit calculation here.
    $fee=$row['depositFee']??null;
    if (!is_string($fee)) throw new RuntimeException('crypto_bybit_deposit_fee_invalid');
    $fee_units=$fee===''?null:bc_crypto_to_units($fee,$scale);
    $confirm=$row['confirmations']??null;
    if (!is_string($confirm)||!preg_match('/^(?:0|[1-9][0-9]{0,8}|1000000000)$/D',$confirm)) throw new RuntimeException('crypto_bybit_deposit_confirmations_invalid');
    $success=$row['successAt']??null;
    if (!is_string($success)||!preg_match('/^(?:|0|[1-9][0-9]{12})$/D',$success)) throw new RuntimeException('crypto_bybit_deposit_time_invalid');
    $state=bc_crypto_bybit_deposit_status($row['status']??null);
    if (($row['status']??null)===3&&($success===''||$success==='0')) throw new RuntimeException('crypto_bybit_deposit_time_invalid');
    $type=$row['depositType']??null;
    if (!in_array($type,['0','10','20','50'],true)) throw new RuntimeException('crypto_bybit_deposit_type_unknown');
    $travel=array_key_exists('travelRuleStatus',$row)?$row['travelRuleStatus']:null;
    $tax=array_key_exists('taxStatus',$row)?$row['taxStatus']:null;
    if ((array_key_exists('travelRuleStatus',$row)&&!in_array($travel,[0,1,2,3],true))||(array_key_exists('taxStatus',$row)&&!in_array($tax,[0,1,2],true))) throw new RuntimeException('crypto_bybit_deposit_compliance_unknown');
    $blocked=in_array($type,['20','50'],true)||$travel===3;
    // Success, ordinary deposit type, tax/travel approval and confirmations NEVER imply risk clearance.
    return ['provider_record_key'=>'bybit:onchain:v1:'.$id,'provider_id'=>$id,'asset_network'=>$coin.':TRX','scale'=>$scale,
        'amount'=>$row['amount'],'units'=>$units,'deposit_fee'=>$fee,'fee_units'=>$fee_units,
        'txid'=>$tx,'tx_index'=>$index,'address'=>$address,'tag'=>'','confirmations'=>$confirm,'success_at_ms'=>$success,
        'provider_status'=>$row['status'],'status'=>$state['status'],'status_reason'=>$state['reason'],
        'deposit_type'=>$type,'travel_rule_status'=>$travel,'tax_status'=>$tax,'risk'=>$blocked?'blocked':'unknown',
        'transfer_fingerprint'=>hash('sha256',bc_crypto_gov_json([$coin.':TRX',$tx,$index])),
        'provider_authenticated'=>false,'customer_attribution_verified'=>false,'chain_finality_verified'=>false,
        'amount_semantics_verified'=>false,'settlement_enabled'=>false];
}
/** Whole-page failure: a malformed row may not be skipped to advance a polling cursor. */
function bc_crypto_bybit_deposit_page($result,$coin,$scale,$requested_cursor='') {
    if (!in_array($coin,['TRX','USDT'],true)||$scale!==6) throw new RuntimeException('crypto_bybit_deposit_scope_unsupported');
    bc_crypto_bybit_deposit_cursor($requested_cursor);
    if (!is_array($result)||!is_array($result['rows']??null)||count($result['rows'])>50||($result['rows']!==[]&&array_keys($result['rows'])!==range(0,count($result['rows'])-1))) throw new RuntimeException('crypto_bybit_deposit_page_invalid');
    $next=bc_crypto_bybit_deposit_cursor($result['nextPageCursor']??null);
    if ($next!==''&&$next===$requested_cursor) throw new RuntimeException('crypto_bybit_deposit_cursor_repeated');
    $out=[];$ids=[];$transfers=[];
    foreach ($result['rows'] as $row) {
        $record=bc_crypto_bybit_deposit_record($row,$coin,$scale);
        if (isset($ids[$record['provider_record_key']])||isset($transfers[$record['transfer_fingerprint']])) throw new RuntimeException('crypto_bybit_deposit_duplicate_identity');
        $ids[$record['provider_record_key']]=true;$transfers[$record['transfer_fingerprint']]=true;$out[]=$record;
    }
    return ['records'=>$out,'next_cursor'=>$next,'has_next_page'=>$next!=='',
        'provider_authenticated'=>false,'cursor_advance_authorized'=>false,'import_enabled'=>false,'settlement_enabled'=>false];
}
