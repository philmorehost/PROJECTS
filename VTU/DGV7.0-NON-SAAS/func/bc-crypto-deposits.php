<?php
/** Internal normalized observation intake ONLY. No HTTP, provider transport, address allocation or credits. */
require_once __DIR__.'/bc-crypto-governance.php';
require_once __DIR__.'/bc-crypto-custody.php';
function bc_crypto_deposit_digest($value) {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D',$value)) throw new InvalidArgumentException('crypto_deposit_digest_invalid');
    return $value;
}
function bc_crypto_deposit_text($value,$max,$empty=false) {
    if (!is_string($value) || strlen($value)>$max || (!$empty && $value==='') || ($value!=='' && (!preg_match('/^[\x21-\x7e]+$/D',$value)))) throw new InvalidArgumentException('crypto_deposit_text_invalid');
    return $value; // Never trim, lowercase or discard a network memo/tag.
}
function bc_crypto_deposit_route_hash($asset,$address,$tag) {
    bc_crypto_gov_asset($asset);
    return hash('sha256',bc_crypto_gov_json([$asset,bc_crypto_deposit_text($address,256),bc_crypto_deposit_text($tag,128,true)]));
}
function bc_crypto_deposit_normalize($input) {
    $asset=bc_crypto_gov_asset($input['asset_network']??null);
    $scale=bc_crypto_scale($input['scale']??null);
    $units=bc_crypto_to_units($input['amount']??null,$scale);
    if ($units==='0') throw new InvalidArgumentException('crypto_zero_deposit');
    $record=bc_crypto_deposit_text($input['record_id']??null,128);
    $tx=bc_crypto_deposit_text($input['txid']??null,256);
    $routing=bc_crypto_deposit_route_hash($asset,$input['address']??null,$input['tag']??null);
    $confirm=bc_crypto_gov_uint($input['confirmations']??null,1000000000);
    $status=$input['status']??null;$risk=$input['risk']??null;
    if (!in_array($status,['pending','complete','failed','reorged'],true) || !in_array($risk,['unknown','clear','blocked'],true)) throw new InvalidArgumentException('crypto_deposit_status_invalid');
    $evidence=bc_crypto_deposit_digest($input['evidence_hash']??null);
    $identity=hash('sha256',bc_crypto_gov_json([$asset,$scale,$units,$routing,hash('sha256',$tx)]));
    $hash=hash('sha256',bc_crypto_gov_json([$record,$identity,$confirm,$status,$risk,$evidence]));
    return ['record_id'=>$record,'asset_network'=>$asset,'scale'=>$scale,'units'=>$units,'routing_hash'=>$routing,'identity_hash'=>$identity,'confirmations'=>$confirm,'status'=>$status,'risk'=>$risk,'evidence_hash'=>$evidence,'observation_hash'=>$hash];
}
/** Pure conservative state machine. Quarantine is sticky; no automated unfreeze/reassignment. */
function bc_crypto_deposit_decide($old,$observation,$failure,$required_confirmations) {
    if (($old['state']??'')==='quarantined') return ['state'=>'quarantined','reason'=>$old['reason']];
    if (!hash_equals($old['identity_hash'],$observation['identity_hash'])) return ['state'=>'quarantined','reason'=>'identity_conflict'];
    if ($observation['confirmations']<(int)$old['confirmations'] || (($old['state']??'')==='review_ready' && $observation['status']!=='complete')) return ['state'=>'quarantined','reason'=>'provider_regression'];
    if (in_array($observation['status'],['failed','reorged'],true)) return ['state'=>'quarantined','reason'=>'provider_'.$observation['status']];
    if ($failure!==null) return ['state'=>'quarantined','reason'=>$failure];
    if ($observation['risk']!=='clear') return ['state'=>'quarantined','reason'=>'risk_not_cleared'];
    if ($observation['status']!=='complete' || $observation['confirmations']<$required_confirmations) return ['state'=>'observed','reason'=>'awaiting_confirmations'];
    return ['state'=>'review_ready','reason'=>'manual_settlement_required'];
}
function bc_crypto_deposit_must($sql,$params=[]) {
    $r=bc_q($sql,$params);
    if ($r===null || $r===false) throw new RuntimeException('crypto_deposit_storage_failed');
    return $r;
}
/**
 * Context comes from a future trusted worker's account configuration, NEVER a browser/callback.
 * Evidence hashes and normalized risk/status are assertions for review, not authenticated provider proof.
 * Routes must already exist via a separately reviewed attribution process; no route provisioning here.
 */
function bc_crypto_deposit_observe($context,$input) {
    global $connection_server;
    $vid=$context['vendor_id']??null;
    if (!is_int($vid)||$vid<=0) throw new InvalidArgumentException('crypto_scope_invalid');
    $env=bc_crypto_gov_environment($context['environment']??null);
    $custody=bc_crypto_deposit_digest($context['custody_account']??null);
    $o=bc_crypto_deposit_normalize($input);
    if (!bc_crypto_transaction_ready() || !mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_transaction_unavailable');
    try {
        $custody_allowed=bc_crypto_custody_observation_allowed($vid,$env,$custody);
        // Global per external account/environment, NOT per tenant: one external record cannot be claimed twice.
        bc_crypto_deposit_must('INSERT INTO sas_crypto_v2_deposits(vendor_id,environment,custody_account,provider_record_id,asset_network,scale,units,identity_hash,last_observation_hash) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',[$vid,$env,$custody,$o['record_id'],$o['asset_network'],$o['scale'],$o['units'],$o['identity_hash'],$o['observation_hash']]);
        $row=bc_q_row('SELECT * FROM sas_crypto_v2_deposits WHERE environment=? AND custody_account=? AND provider_record_id=? FOR UPDATE',[$env,$custody,$o['record_id']]);
        if (!$row) throw new RuntimeException('crypto_deposit_storage_failed');
        if ((int)$row['vendor_id']!==$vid) throw new RuntimeException('crypto_deposit_scope_conflict');
        $route=bc_q_row('SELECT * FROM sas_crypto_v2_deposit_routes WHERE environment=? AND custody_account=? AND asset_network=? AND routing_hash=? FOR UPDATE',[$env,$custody,$o['asset_network'],$o['routing_hash']]);
        $failure=null;$required=1000000000;$user_id=(int)$row['user_id'];$route_id=$row['route_id'];
        if (!$route || (int)$route['vendor_id']!==$vid || (int)$route['enabled']!==1 || (int)$route['valid_until_epoch']<=time() || !preg_match('/^[a-f0-9]{64}$/D',$route['attribution_evidence_hash']) || (int)$route['user_id']<=0) $failure='attribution_unapproved';
        elseif ((int)$route['scale']!==$o['scale']) $failure='route_scale_mismatch';
        elseif ((int)$row['revision']>0 && ((int)$row['route_id']!==(int)$route['id'] || $user_id!==(int)$route['user_id'])) $failure='attribution_changed';
        else {
            $user_id=(int)$route['user_id'];$route_id=(int)$route['id'];
            $user=bc_q_row('SELECT id,status,is_blocked,force_password_change,kyc_status FROM sas_users WHERE vendor_id=? AND id=? FOR UPDATE',[$vid,$user_id]);
            if (!$user || (int)$user['status']!==1 || !empty($user['is_blocked']) || !empty($user['force_password_change']) || (int)$user['kyc_status']!==2) $failure='customer_restricted';
            else {
                $pc=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,'control',true));
                $tc=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,'control',true));
                $p=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,$o['asset_network'],true));
                $t=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,$o['asset_network'],true));
                try {
                    $network=bc_crypto_gov_network(bc_crypto_gov_snapshot($vid,$env,true),$o['asset_network'],time());
                    $policy=bc_crypto_gov_evaluate($p,$t,$pc,$tc,$network,time(),'deposit');
                    $required=$policy['confirmations'];
                    if ($policy['scale']!==$o['scale']) $failure='policy_scale_mismatch';
                    elseif (bc_crypto_compare($o['units'],$policy['min_units'])<0 || bc_crypto_compare($o['units'],$policy['max_units'])>0) $failure='deposit_policy_limit';
                } catch(Throwable $e) {
                    if (!preg_match('/^crypto_[a-z_]+$/D',$e->getMessage())) throw $e;
                    $failure=$e->getMessage();
                }
            }
        }
        if (!$custody_allowed) $failure='custody_unapproved';
        $next=bc_crypto_deposit_decide($row,$o,$failure,$required);
        $duplicate=(int)$row['revision']>0 && hash_equals($row['last_observation_hash'],$o['observation_hash']) && $row['state']===$next['state'] && $row['reason']===$next['reason'];
        if (!$duplicate) {
            // Never reassign original ownership/economics, including on mismatching or quarantined repeats.
            if ((int)$row['revision']>0) {$user_id=(int)$row['user_id'];$route_id=$row['route_id'];}
            $revision=(int)$row['revision']+1;
            bc_crypto_deposit_must('UPDATE sas_crypto_v2_deposits SET user_id=?,route_id=?,confirmations=?,state=?,reason=?,revision=?,last_observation_hash=? WHERE id=?',[$user_id,$route_id,max((int)$row['confirmations'],$o['confirmations']),$next['state'],$next['reason'],$revision,$o['observation_hash'],(int)$row['id']]);
            bc_crypto_deposit_must('INSERT INTO sas_crypto_v2_deposit_events(deposit_id,revision,observation_hash,evidence_hash,observation_document,before_state,after_state,reason) VALUES(?,?,?,?,?,?,?,?)',[(int)$row['id'],$revision,$o['observation_hash'],$o['evidence_hash'],bc_crypto_gov_json($o),(int)$row['revision']===0?'new':$row['state'],$next['state'],$next['reason']]);
        }
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_deposit_storage_failed');
        return ['deposit_id'=>(int)$row['id'],'state'=>$next['state'],'reason'=>$next['reason'],'duplicate'=>$duplicate,'settlement_enabled'=>false];
    } catch(Throwable $e) {mysqli_rollback($connection_server);throw $e;}
}
