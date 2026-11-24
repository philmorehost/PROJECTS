<?php
/** Local-only read model. No provider call, evidence decryption, DDL or financial authority. */
function bc_crypto_dashboard_id($id) {
    if((!is_string($id)&&!is_int($id))||!preg_match('/^[1-9][0-9]{0,8}$/D',(string)$id))throw new RuntimeException('crypto_dashboard_access_denied');
    return (int)$id;
}
function bc_crypto_dashboard_identity($role,$actor) {
    $tables=['platform'=>'sas_super_admin','vendor'=>'sas_vendors','user'=>'sas_users'];
    if(!isset($tables[$role])||!is_array($actor))throw new RuntimeException('crypto_dashboard_access_denied');
    $id=bc_crypto_dashboard_id($actor['id']??null);
    $cols=$role==='user'?',vendor_id':'';
    $security=$role==='platform'?'0 AS force_password_change':'force_password_change';
    $fresh=bc_q_row('SELECT id,status,is_blocked,'.$security.$cols.' FROM '.$tables[$role].' WHERE id=? LIMIT 1',[$id]);
    if(!$fresh||(int)$fresh['id']!==$id||(int)$fresh['status']!==1||!empty($fresh['is_blocked'])||!empty($fresh['force_password_change']))throw new RuntimeException('crypto_dashboard_access_denied');
    $vendor=$role==='platform'?null:($role==='vendor'?$id:bc_crypto_dashboard_id($fresh['vendor_id']??null));
    if($role==='user'&&bc_crypto_dashboard_id($actor['vendor_id']??null)!==$vendor)throw new RuntimeException('crypto_dashboard_access_denied');
    if($role==='user'){
        $v=bc_q_row('SELECT id,status,is_blocked FROM sas_vendors WHERE id=? LIMIT 1',[$vendor]);
        if(!$v||(int)$v['status']!==1||!empty($v['is_blocked']))throw new RuntimeException('crypto_dashboard_access_denied');
    }
    return ['role'=>$role,'id'=>$id,'vendor_id'=>$vendor];
}
/** Format exact decimal units; never convert wallet amounts through float. */
function bc_crypto_dashboard_amount($units,$scale) {
    if(!is_string($units)||!preg_match('/^(?:0|[1-9][0-9]{0,64})$/D',$units)||!preg_match('/^(?:[0-9]|1[0-8])$/D',(string)$scale))return 'Unavailable';
    $scale=(int)$scale;if($scale===0)return $units;
    $p=str_pad($units,$scale+1,'0',STR_PAD_LEFT);
    return substr($p,0,-$scale).'.'.substr($p,-$scale);
}
function bc_crypto_dashboard_rows($sql,$params,$limit) {
    try{
        $result=bc_q($sql,$params);
        if(!($result instanceof mysqli_result))throw new RuntimeException('unavailable');
        $rows=[];while($r=mysqli_fetch_assoc($result))$rows[]=$r;
        return ['available'=>true,'rows'=>array_slice($rows,0,$limit),'truncated'=>count($rows)>$limit];
    }catch(Throwable $e){return ['available'=>false,'rows'=>[],'truncated'=>false];}
}
function bc_crypto_dashboard_load($identity,$env) {
    if(!in_array($env,['testnet','mainnet'],true))throw new RuntimeException('crypto_dashboard_environment_invalid');
    $role=$identity['role'];$params=[$env];$where='environment=?';
    if($role!=='platform'){$where.=' AND vendor_id=?';$params[]=$identity['vendor_id'];}
    $out=['environment'=>$env,'role'=>$role,'generated_at'=>gmdate('Y-m-d H:i:s').' UTC','live_actions_enabled'=>false];
    $out['policies']=bc_crypto_dashboard_rows('SELECT vendor_id,asset_network,scale,enabled,withdrawals_enabled,reconciliation_status,valid_until_epoch FROM sas_crypto_v2_asset_policies WHERE '.$where.' ORDER BY vendor_id,asset_network LIMIT 51',$params,50);
    $personal=$where;$personalParams=$params;
    if($role==='user'){$personal.=' AND user_id=?';$personalParams[]=$identity['id'];}
    $out['deposits']=bc_crypto_dashboard_rows('SELECT id,asset_network,scale,units,state,reason,confirmations,updated_at FROM sas_crypto_v2_deposits WHERE '.$personal.' ORDER BY id DESC LIMIT 51',$personalParams,50);
    $out['operations']=bc_crypto_dashboard_rows('SELECT id,asset_network,reference,kind,units,created_at FROM sas_crypto_v2_operations WHERE '.$personal.' ORDER BY id DESC LIMIT 51',$personalParams,50);
    if($role==='user')$out['balances']=bc_crypto_dashboard_rows('SELECT asset_network,scale,bucket,units FROM sas_crypto_v2_accounts WHERE '.$personal.' ORDER BY asset_network,bucket LIMIT 101',$personalParams,100);
    else $out['streams']=bc_crypto_dashboard_rows('SELECT stream_id,vendor_id,state,page_count,revision,valid_until_epoch FROM sas_crypto_v2_poll_streams WHERE '.$where.' ORDER BY created_at DESC,stream_id LIMIT 21',$params,20);
    if($role!=='user')$out['candidates']=bc_crypto_dashboard_rows('SELECT id,vendor_id,user_id,asset_network,scale,state,revision,valid_until_epoch FROM sas_crypto_v2_route_candidates WHERE '.$where.' ORDER BY id DESC LIMIT 51',$params,50);
    return $out;
}

function bc_crypto_buysell_dashboard_load($identity) {
    $role=$identity['role'];$cbsWhere='1=1';$cbsParams=[];
    if($role!=='platform'){$cbsWhere.=' AND vendor_id=?';$cbsParams[]=$identity['vendor_id'];}
    if($role==='user'){$cbsWhere.=' AND user_id=?';$cbsParams[]=$identity['id'];}
    return [
        'buysell_orders'=>bc_crypto_dashboard_rows('SELECT id,vendor_id,user_id,request_id,side,asset,network,crypto_units_1e8,fiat_minor,state,tx_hash,updated_at FROM sas_crypto_trade_orders WHERE '.$cbsWhere.' ORDER BY id DESC LIMIT 51',$cbsParams,50),
        'buysell_deposits'=>bc_crypto_dashboard_rows('SELECT id,vendor_id,user_id,order_id,network,expected_network,asset,observed_units_1e8,confirmations,required_confirmations,state,updated_at FROM sas_crypto_chain_deposits WHERE '.$cbsWhere.' ORDER BY id DESC LIMIT 51',$cbsParams,50),
    ];
}

/** A local observation receipt, never a settlement receipt. Scope is bound before lookup. */
function bc_crypto_dashboard_receipt($identity,$env,$depositId) {
    if(!in_array($env,['testnet','mainnet'],true))throw new RuntimeException('crypto_dashboard_environment_invalid');
    if(!is_string($depositId)||!preg_match('/^[1-9][0-9]{0,19}$/D',$depositId))throw new RuntimeException('crypto_dashboard_receipt_invalid');
    $where='environment=? AND id=?';$params=[$env,$depositId];
    if($identity['role']!=='platform'){$where.=' AND vendor_id=?';$params[]=$identity['vendor_id'];}
    if($identity['role']==='user'){$where.=' AND user_id=?';$params[]=$identity['id'];}
    return bc_crypto_dashboard_rows('SELECT id,asset_network,scale,units,state,reason,confirmations,revision,created_at,updated_at FROM sas_crypto_v2_deposits WHERE '.$where.' LIMIT 1',$params,1);
}
