<?php
/** Internal candidate workflow only; never writes sas_crypto_v2_deposit_routes or a wallet. */
require_once __DIR__.'/bc-crypto-deposits.php';
require_once __DIR__.'/bc-crypto-custody-evidence.php';
require_once __DIR__.'/bc-crypto-bound-address.php';
function bc_crypto_route_normalize($input) {
    foreach(['vendor_id','user_id'] as $field) if (!is_int($input[$field]??null)||$input[$field]<=0) throw new InvalidArgumentException('crypto_route_scope_invalid');
    $asset=bc_crypto_gov_asset($input['asset_network']??null);$scale=bc_crypto_scale($input['scale']??null);
    $address=bc_crypto_deposit_text($input['address']??null,256);$tag=bc_crypto_deposit_text($input['tag']??null,128,true);
    $note=bc_crypto_custody_evidence_text($input['note']??null);
    if (strlen($note)>4096) throw new InvalidArgumentException('crypto_route_note_too_large');
    return ['vendor_id'=>$input['vendor_id'],'user_id'=>$input['user_id'],'asset_network'=>$asset,'scale'=>$scale,
        'address'=>$address,'tag'=>$tag,'routing_hash'=>bc_crypto_deposit_route_hash($asset,$address,$tag),'note'=>$note];
}
function bc_crypto_route_engines() {
    bc_crypto_custody_evidence_engines();
    foreach(['sas_crypto_v2_route_candidates','sas_crypto_v2_route_candidate_audit','sas_crypto_v2_deposit_routes'] as $table) {
        $row=bc_q_row('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (!$row||strtoupper($row['engine']??'')!=='INNODB') throw new RuntimeException('crypto_route_transactional_schema_required');
    }
}
/** Inside owner transaction, after custody lock. Current reviews never override custody/customer/policy stops. */
function bc_crypto_route_guard($custody,$env,$scope) {
    $vid=(int)$scope['vendor_id'];$asset=$scope['asset_network'];$now=time();
    $vendor=bc_q_row('SELECT id,status,is_blocked FROM sas_vendors WHERE id=? FOR UPDATE',[$vid]);
    if (!$vendor||(int)$vendor['status']!==1||!empty($vendor['is_blocked'])) throw new RuntimeException('crypto_route_vendor_restricted');
    $doc=bc_crypto_gov_decode($custody);
    if (!bc_crypto_custody_eligible($doc,$vid,$now)) throw new RuntimeException('crypto_route_custody_unapproved');
    $user=bc_q_row('SELECT id,status,is_blocked,force_password_change,kyc_status FROM sas_users WHERE vendor_id=? AND id=? FOR UPDATE',[$vid,(int)$scope['user_id']]);
    if (!$user||(int)$user['status']!==1||!empty($user['is_blocked'])||!empty($user['force_password_change'])||(int)$user['kyc_status']!==2) throw new RuntimeException('crypto_route_customer_restricted');
    $pc=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,'control',true));
    $tc=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,'control',true));
    $p=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,$asset,true));
    $t=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,$asset,true));
    $snapshot=bc_crypto_gov_snapshot($vid,$env,true);
    $network=bc_crypto_gov_network($snapshot,$asset,$now);
    $policy=bc_crypto_gov_evaluate($p,$t,$pc,$tc,$network,$now,'deposit');
    if ((int)$scope['scale']!==$policy['scale']) throw new RuntimeException('crypto_route_scale_mismatch');
    return min($doc['valid_until'],$doc['bindings'][(string)$vid]['valid_until'],$p['expires_at'],$t['expires_at'],(int)$snapshot['expires_at_epoch']);
}
function bc_crypto_route_review_next($candidate,$actor,$action,$seconds,$ceiling,$now) {
    if ($action==='revoke') { $candidate['state']='revoked';$candidate['valid_until_epoch']=0;return $candidate; }
    if ($action!=='review') throw new InvalidArgumentException('crypto_route_action_invalid');
    if ($candidate['state']!=='proposed'||(int)$candidate['proposed_by']===$actor) throw new RuntimeException('crypto_route_independent_review_required');
    $ttl=bc_crypto_gov_uint($seconds,86400);
    if ($ttl===0||$ceiling<=$now) throw new InvalidArgumentException('crypto_route_expiry_invalid');
    $candidate['state']='reviewed';$candidate['reviewed_by']=$actor;$candidate['valid_until_epoch']=min($now+$ttl,$ceiling);
    return $candidate;
}
/** Account/customer context is server-checked; addresses are claims, not provider allocation proof. */
function bc_crypto_route_save($authority,$env,$identity,$custody_revision,$action,$input) {
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);bc_crypto_gov_environment($env);bc_crypto_custody_digest($identity);
    if (!is_int($custody_revision)||$custody_revision<0) throw new InvalidArgumentException('crypto_custody_revision_invalid');
    if (!in_array($action,['propose_bound','propose','review','revoke'],true)) throw new InvalidArgumentException('crypto_route_action_invalid');
    if ($action==='propose') $scope=bc_crypto_route_normalize($input);
    elseif($action!=='propose_bound') foreach(['candidate_id','revision'] as $field) if (!is_int($input[$field]??null)||$input[$field]<=0) throw new InvalidArgumentException('crypto_route_revision_invalid');
    if (!bc_crypto_transaction_ready()) throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_route_engines();
    if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_transaction_unavailable');
    try {
        $custody=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if (!$custody||$custody['environment']!==$env||(int)$custody['revision']!==$custody_revision) throw new RuntimeException('crypto_custody_edit_conflict');
        if($action==='propose_bound'){
            foreach(['vendor_id','user_id','address','tag','asset_network','scale'] as $field)if(array_key_exists($field,$input))throw new RuntimeException('crypto_bound_address_override_forbidden');
            $scope=bc_crypto_bound_address_scope($custody,$identity,$env,$custody_revision,$actor,$input['evidence_hash']??null,bc_crypto_gov_uint($input['binding_revision']??null,1000000000));
        }
        if (in_array($action,['propose','propose_bound'],true)) {
            bc_crypto_route_guard($custody,$env,$scope);
            bc_crypto_route_conflicts($env,$scope['asset_network'],$scope['routing_hash']);
            $legacy=null;
            if ($legacy) throw new RuntimeException('crypto_route_already_reserved');
            $manifest=['type'=>'deposit_route_candidate','environment'=>$env,'custody_account'=>$identity,'custody_revision'=>$custody_revision,'route'=>$scope,'live_enabled'=>false];
            $sealed=bc_crypto_custody_evidence_seal($identity,$env,bc_crypto_gov_json($manifest));
            // Reserve the exact global route forever through this workflow, including revoked/expired candidates.
            // An exact duplicate INSERT intentionally fails; never update/reassign its customer or evidence.
            $ok=bc_q('INSERT INTO sas_crypto_v2_route_candidates(environment,custody_account,custody_revision,vendor_id,user_id,asset_network,routing_hash,scale,evidence_hash,proposed_by,created_at_epoch) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$env,$identity,$custody_revision,$scope['vendor_id'],$scope['user_id'],$scope['asset_network'],$scope['routing_hash'],$scope['scale'],$sealed['hash'],$actor,time()]);
            if ($ok!==true) throw new RuntimeException('crypto_route_already_reserved');
            $candidate=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE environment=? AND custody_account=? AND asset_network=? AND routing_hash=? FOR UPDATE',[$env,$identity,$scope['asset_network'],$scope['routing_hash']]);
            if (!$candidate) throw new RuntimeException('crypto_route_storage_failed');
            bc_crypto_custody_must('INSERT INTO sas_crypto_v2_custody_evidence(custody_account,evidence_hash,environment,sealed_document,stored_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE evidence_hash=evidence_hash',[$identity,$sealed['hash'],$env,$sealed['sealed'],$actor]);
            bc_crypto_custody_must("INSERT INTO sas_crypto_v2_custody_evidence_events(custody_account,evidence_hash,actor_id,revision,action,occurred_at_epoch) VALUES(?,?,?,?,'stored',?)",[$identity,$sealed['hash'],$actor,$custody_revision,time()]);
            $before=[];$next=$candidate;
        } else {
            $candidate=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=? AND custody_account=? AND environment=? FOR UPDATE',[$input['candidate_id'],$identity,$env]);
            if (!$candidate||(int)$candidate['revision']!==$input['revision']) throw new RuntimeException('crypto_route_edit_conflict');
            $before=$candidate;$ceiling=0;
            if ($action==='review') {
                if ($candidate['state']!=='proposed'||(int)$candidate['proposed_by']===$actor) throw new RuntimeException('crypto_route_independent_review_required');
                if ((int)$candidate['custody_revision']!==$custody_revision) throw new RuntimeException('crypto_route_custody_changed');
                $ceiling=bc_crypto_route_guard($custody,$env,$candidate);
                bc_crypto_custody_evidence_require($identity,$env,$candidate['evidence_hash'],$actor,$custody_revision,true);
                $manifestRow=bc_q_row('SELECT sealed_document FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND environment=? AND evidence_hash=?',[$identity,$env,$candidate['evidence_hash']]);
                $manifest=json_decode(bc_crypto_custody_evidence_open($identity,$env,$candidate['evidence_hash'],$manifestRow['sealed_document']),true);
                if(isset($manifest['route']['bound_address_evidence_hash'])){
                    $scope=bc_crypto_bound_address_scope($custody,$identity,$env,$custody_revision,$actor,$manifest['route']['bound_address_evidence_hash'],(int)$manifest['route']['binding_revision']);
                    foreach(['vendor_id','user_id','scale'] as $field)if((int)$candidate[$field]!==$scope[$field])throw new RuntimeException('crypto_bound_address_context_invalid');
                    if($candidate['asset_network']!==$scope['asset_network']||$candidate['routing_hash']!==$scope['routing_hash'])throw new RuntimeException('crypto_bound_address_context_invalid');
                    $ceiling=min($ceiling,$scope['evidence_expires_at']);
                }
                bc_crypto_route_conflicts($env,$candidate['asset_network'],$candidate['routing_hash'],(int)$candidate['id']);
                // Existing enabled/inactive route records may not be superseded by local candidate review.
                if (bc_q_row('SELECT id FROM sas_crypto_v2_deposit_routes WHERE environment=? AND custody_account=? AND asset_network=? AND routing_hash=? FOR UPDATE',[$env,$identity,$candidate['asset_network'],$candidate['routing_hash']])) throw new RuntimeException('crypto_route_already_reserved');
            }
            $next=bc_crypto_route_review_next($candidate,$actor,$action,$input['valid_seconds']??null,$ceiling,time());
            $next['revision']=(int)$candidate['revision']+1;$changed=null;
            if (bc_q('UPDATE sas_crypto_v2_route_candidates SET state=?,reviewed_by=?,valid_until_epoch=?,revision=revision+1 WHERE id=? AND revision=?',[$next['state'],(int)$next['reviewed_by'],(int)$next['valid_until_epoch'],(int)$candidate['id'],(int)$candidate['revision']],$changed)!==true||$changed!==1) throw new RuntimeException('crypto_route_edit_conflict');
        }
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_route_candidate_audit(candidate_id,revision,actor_id,action,before_document,after_document) VALUES(?,?,?,?,?,?)',[(int)$candidate['id'],(int)$next['revision'],$actor,$action,bc_crypto_gov_json($before),bc_crypto_gov_json($next)]);
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_route_commit_failed');
        return ['candidate_id'=>(int)$candidate['id'],'revision'=>(int)$next['revision'],'evidence_hash'=>$candidate['evidence_hash'],'state'=>$next['state'],'live_enabled'=>false];
    } catch(Throwable $e) {mysqli_rollback($connection_server);if ($e instanceof mysqli_sql_exception && (int)$e->getCode()===1062) throw new RuntimeException('crypto_route_already_reserved');throw $e;}
}

/** Global policy row is locked by route_guard before this check, serializing candidate writers. */
function bc_crypto_route_conflicts($env,$asset,$hash,$exclude=0){
    if(bc_q_row('SELECT id FROM sas_crypto_v2_route_candidates WHERE environment=? AND asset_network=? AND routing_hash=? AND id<>? LIMIT 1 FOR UPDATE',[$env,$asset,$hash,$exclude])||bc_q_row('SELECT id FROM sas_crypto_v2_deposit_routes WHERE environment=? AND asset_network=? AND routing_hash=? LIMIT 1 FOR UPDATE',[$env,$asset,$hash]))throw new RuntimeException('crypto_route_already_reserved');
}
