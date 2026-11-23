<?php
require_once __DIR__.'/bc-crypto-custody-evidence.php';
require_once __DIR__.'/bc-bybit.php';
/** Domain-separated server attestation distinguishes provider reads from manually stored JSON. */
function bc_crypto_parent_attest($manifest){
    unset($manifest['server_attestation']);$key=getenv('BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY')?:'V1';
    $manifest['attestation_key']=$key;
    $manifest['server_attestation']=hash_hmac('sha256','crypto-parent-evidence-v1\n'.bc_crypto_gov_json($manifest),bc_crypto_custody_evidence_key($key));
    return $manifest;
}
function bc_crypto_binding_evidence_validate($m,$identity,$env,$revision,$now){
    if(!is_array($m)||!is_string($m['server_attestation']??null)||!is_string($m['attestation_key']??null))throw new RuntimeException('crypto_binding_attestation_required');
    $mac=$m['server_attestation'];unset($m['server_attestation']);
    if(!hash_equals(hash_hmac('sha256','crypto-parent-evidence-v1\n'.bc_crypto_gov_json($m),bc_crypto_custody_evidence_key($m['attestation_key'])),$mac))throw new RuntimeException('crypto_binding_attestation_invalid');
    if(($m['evidence_kind']??null)!=='parent_relationship_v1'||($m['provider']??null)!=='bybit'||($m['custody_account']??null)!==$identity||($m['environment']??null)!==$env||($m['registry_revision']??null)!==$revision||($m['reported_parent_relationship_compared']??null)!==true||($m['authenticated_read_completed']??null)!==true||($m['identity_checked_before_and_after']??null)!==true||($m['live_enabled']??null)!==false)throw new RuntimeException('crypto_binding_evidence_invalid');
    if(!is_int($m['started_at']??null)||!is_int($m['verified_at']??null)||!is_int($m['expires_at']??null)||$m['started_at']>$m['verified_at']||$m['verified_at']>$now||$m['verified_at']-$m['started_at']>30||$m['expires_at']!==$m['started_at']+900||$m['expires_at']<=$now)throw new RuntimeException('crypto_binding_evidence_stale');
    bc_crypto_custody_digest($m['reported_parent_account']??null);
    foreach(['sub','master'] as $kind){
        $source=$m[$kind.'_source_vendor_id']??null;if(!is_int($source)||$source<=0)throw new RuntimeException('crypto_binding_evidence_invalid');
        $config=bc_bybit_config($source);$ips=$config['expected_ips'];sort($ips);
        if($config['environment']!==$env||!hash_equals(hash('sha256',bc_crypto_gov_json([$source,$env,$config['key'],$ips])),$m[$kind.'_config_fingerprint']??''))throw new RuntimeException('crypto_binding_config_changed');
    }
    return $m;
}
/** Trusted internal caller: platform POST/CSRF/fresh step-up enforced by management boundary. */
function bc_crypto_customer_binding_save($authority,$identity,$env,$custodyRevision,$action,$input){
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);
    if(!in_array($action,['propose','renew','review','revoke'],true))throw new RuntimeException('crypto_binding_action_invalid');
    if(!bc_crypto_transaction_ready())throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_custody_evidence_engines();
    foreach(['sas_crypto_v2_customer_bindings','sas_crypto_v2_customer_binding_audit','sas_users','sas_vendors'] as $t){$e=bc_q_row('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t]);if(!$e||strtoupper($e['engine'])!=='INNODB')throw new RuntimeException('crypto_binding_schema_required');}
    if(!mysqli_begin_transaction($connection_server))throw new RuntimeException('crypto_transaction_unavailable');
    try{
        $c=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if(!$c||$c['environment']!==$env||(int)$c['revision']!==$custodyRevision)throw new RuntimeException('crypto_custody_edit_conflict');
        $old=bc_q_row('SELECT * FROM sas_crypto_v2_customer_bindings WHERE custody_account=? FOR UPDATE',[$identity]);
        if($action==='propose'){
            if($old)throw new RuntimeException('crypto_binding_already_reserved');
            $vendor=bc_crypto_gov_uint($input['vendor_id']??null,1000000000);$user=bc_crypto_gov_uint($input['user_id']??null,1000000000);
            if(!$vendor||!$user)throw new RuntimeException('crypto_binding_customer_restricted');
            $hash=bc_crypto_custody_digest($input['evidence_hash']??null);
            $next=['custody_account'=>$identity,'environment'=>$env,'vendor_id'=>$vendor,'user_id'=>$user,'custody_revision'=>$custodyRevision,'evidence_hash'=>$hash,'state'=>'proposed','revision'=>1,'proposed_by'=>$actor,'reviewed_by'=>0,'valid_until_epoch'=>0];
        }else{
            if(!$old||(int)$old['revision']!==bc_crypto_gov_uint($input['binding_revision']??null,1000000000))throw new RuntimeException('crypto_binding_edit_conflict');
            $next=$old;$next['revision']=(int)$old['revision']+1;$vendor=(int)$old['vendor_id'];$user=(int)$old['user_id'];$hash=$old['evidence_hash'];
            if($action==='renew'){
                if($old['state']==='revoked')throw new RuntimeException('crypto_binding_revoked');
                if(isset($input['vendor_id'])||isset($input['user_id']))throw new RuntimeException('crypto_binding_reassignment_forbidden');
                $hash=bc_crypto_custody_digest($input['evidence_hash']??null);
                if(hash_equals($hash,$old['evidence_hash']))throw new RuntimeException('crypto_binding_fresh_evidence_required');
                $next['evidence_hash']=$hash;$next['custody_revision']=$custodyRevision;$next['state']='proposed';
                $next['proposed_by']=$actor;$next['reviewed_by']=0;$next['valid_until_epoch']=0;
            }
            if($action==='review'&&($old['state']!=='proposed'||(int)$old['proposed_by']===$actor))throw new RuntimeException('crypto_binding_independent_review_required');
        }
        if($action==='revoke'){$next['state']='revoked';$next['valid_until_epoch']=0;}
        else{
            $context=bc_crypto_binding_context_locked($c,$next,$actor,$identity,$env,$custodyRevision);$m=$context['relationship'];$doc=$context['custody'];
            if($action==='review'){$next['state']='review_only';$next['reviewed_by']=$actor;$next['valid_until_epoch']=min($m['expires_at'],$doc['valid_until'],$doc['bindings'][(string)$vendor]['valid_until']);}
        }
        if($action==='propose')bc_crypto_custody_must('INSERT INTO sas_crypto_v2_customer_bindings(custody_account,environment,vendor_id,user_id,custody_revision,evidence_hash,state,revision,proposed_by) VALUES(?,?,?,?,?,?,?,?,?)',[$identity,$env,$vendor,$user,$custodyRevision,$hash,'proposed',1,$actor]);
        else bc_crypto_custody_must('UPDATE sas_crypto_v2_customer_bindings SET state=?,reviewed_by=?,valid_until_epoch=?,revision=?,evidence_hash=?,custody_revision=?,proposed_by=? WHERE custody_account=?',[$next['state'],(int)$next['reviewed_by'],(int)$next['valid_until_epoch'],$next['revision'],$next['evidence_hash'],(int)$next['custody_revision'],(int)$next['proposed_by'],$identity]);
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_customer_binding_audit(custody_account,revision,actor_id,action,before_document,after_document) VALUES(?,?,?,?,?,?)',[$identity,$next['revision'],$actor,$action,bc_crypto_gov_json($old?:[]),bc_crypto_gov_json($next)]);
        if(!mysqli_commit($connection_server))throw new RuntimeException('crypto_binding_commit_failed');
        return ['custody_account'=>$identity,'binding_state'=>$next['state'],'binding_revision'=>$next['revision'],'live_enabled'=>false];
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}

function bc_crypto_binding_context_locked($c,$next,$actor,$identity,$env,$custodyRevision){
    $vendor=(int)$next['vendor_id'];$user=(int)$next['user_id'];$hash=$next['evidence_hash'];
    if((int)$next['custody_revision']!==$custodyRevision)throw new RuntimeException('crypto_binding_context_changed');
    $v=bc_q_row('SELECT id,status,is_blocked FROM sas_vendors WHERE id=? FOR UPDATE',[$vendor]);
    $u=bc_q_row('SELECT id,status,is_blocked,force_password_change,kyc_status FROM sas_users WHERE vendor_id=? AND id=? FOR UPDATE',[$vendor,$user]);
    if(!$v||(int)$v['status']!==1||!empty($v['is_blocked'])||!$u||(int)$u['status']!==1||!empty($u['is_blocked'])||!empty($u['force_password_change'])||(int)$u['kyc_status']!==2)throw new RuntimeException('crypto_binding_customer_restricted');
    $doc=bc_crypto_gov_decode($c);if(($doc['model']??null)!=='dedicated_subaccount'||!bc_crypto_custody_eligible($doc,$vendor,time()))throw new RuntimeException('crypto_binding_custody_unapproved');
    bc_crypto_custody_evidence_require($identity,$env,$hash,$actor,$custodyRevision,true);
    $e=bc_q_row('SELECT sealed_document FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND environment=? AND evidence_hash=?',[$identity,$env,$hash]);
    $m=bc_crypto_binding_evidence_validate(json_decode(bc_crypto_custody_evidence_open($identity,$env,$hash,$e['sealed_document']),true),$identity,$env,$custodyRevision,time());
    $parent=bc_q_row('SELECT environment,revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$m['reported_parent_account']]);
    if(!$parent||$parent['environment']!==$env||(int)$parent['revision']!==$m['parent_registry_revision'])throw new RuntimeException('crypto_binding_context_changed');
    foreach(['sub','master'] as $kind){$source=bc_q_row('SELECT status,is_blocked FROM sas_vendors WHERE id=? FOR UPDATE',[$m[$kind.'_source_vendor_id']]);if(!$source||(int)$source['status']!==1||!empty($source['is_blocked']))throw new RuntimeException('crypto_binding_customer_restricted');}
    return ['relationship'=>$m,'custody'=>$doc];
}
/** Short transaction before/after network, never keep DB locks across provider reads. */
function bc_crypto_binding_address_checkpoint($authority,$identity,$env,$revision,$bindingRevision,$expected=null,$snapshot=null){
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);
    if(!bc_crypto_transaction_ready())throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_custody_evidence_engines();
    foreach(['sas_crypto_v2_customer_bindings','sas_users','sas_vendors'] as $t){$r=bc_q_row('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t]);if(!$r||strtoupper($r['engine'])!=='INNODB')throw new RuntimeException('crypto_binding_schema_required');}
    if(!mysqli_begin_transaction($connection_server))throw new RuntimeException('crypto_transaction_unavailable');
    try{
        $c=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        $b=bc_q_row('SELECT * FROM sas_crypto_v2_customer_bindings WHERE custody_account=? FOR UPDATE',[$identity]);
        if(!$c||$c['environment']!==$env||(int)$c['revision']!==$revision||!$b||$b['environment']!==$env||(int)$b['revision']!==$bindingRevision||$b['state']!=='review_only'||(int)$b['valid_until_epoch']<=time())throw new RuntimeException('crypto_binding_address_unavailable');
        $context=bc_crypto_binding_context_locked($c,$b,$actor,$identity,$env,$revision);
        $context['binding']=$b;
        if($expected!==null&&$context!==$expected)throw new RuntimeException('crypto_binding_context_changed');
        $hash=null;
        if($snapshot!==null){
            $snapshot['binding_revision']=$bindingRevision;$snapshot['registry_revision']=$revision;
            $snapshot['vendor_id']=(int)$b['vendor_id'];$snapshot['user_id']=(int)$b['user_id'];
            $snapshot['relationship_evidence_hash']=$b['evidence_hash'];$snapshot['verified_by_actor']=$actor;
            $snapshot['expires_at']=min($snapshot['expires_at'],(int)$b['valid_until_epoch']);
            // Domain-separated server proof; manual evidence JSON cannot impersonate this read.
            require_once __DIR__.'/bc-crypto-bound-address.php';
            $snapshot=bc_crypto_bound_address_attest($snapshot);
            $sealed=bc_crypto_custody_evidence_seal($identity,$env,bc_crypto_gov_json($snapshot));$hash=$sealed['hash'];
            bc_crypto_custody_must('INSERT INTO sas_crypto_v2_custody_evidence(custody_account,evidence_hash,environment,sealed_document,stored_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE evidence_hash=evidence_hash',[$identity,$hash,$env,$sealed['sealed'],$actor]);
            bc_crypto_custody_must("INSERT INTO sas_crypto_v2_custody_evidence_events(custody_account,evidence_hash,actor_id,revision,action,occurred_at_epoch) VALUES(?,?,?,?,'stored',?)",[$identity,$hash,$actor,$revision,time()]);
        }
        if(!mysqli_commit($connection_server))throw new RuntimeException('crypto_evidence_commit_failed');
        return $snapshot===null?$context:$hash;
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
