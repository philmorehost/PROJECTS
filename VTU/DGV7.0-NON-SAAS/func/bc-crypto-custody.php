<?php
/** INTERNAL registry workflow. No HTTP/CLI mutations, provider calls, routes or wallet writes.
 * Future controller MUST supply a fresh authenticated platform actor after CSRF/PIN/TOTP.
 * Local dual review references evidence; it does not authenticate a provider account or prove custody eligibility.
 */
require_once __DIR__.'/bc-crypto-governance.php';
function bc_crypto_custody_digest($value) {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D',$value)) throw new InvalidArgumentException('crypto_custody_digest_invalid');
    return $value;
}
function bc_crypto_custody_identity($environment,$uid) {
    bc_crypto_gov_environment($environment);
    // UID is a provider-issued physical account identity, not API key, tenant ID or address.
    // No trimming/leading zero aliasing or float conversion. Caller must verify its source.
    if (!is_string($uid) || !preg_match('/^[1-9][0-9]{0,19}$/D',$uid)) throw new InvalidArgumentException('crypto_custody_uid_invalid');
    return hash('sha256',bc_crypto_gov_json(['bybit',$environment,$uid]));
}
function bc_crypto_custody_actor($authority) {
    if (($authority['role']??'')!=='spadmin' || !is_int($authority['id']??null) || $authority['id']<=0) throw new RuntimeException('crypto_custody_platform_authority_required');
    return $authority['id'];
}
/** Pure dual-control transition with explicit revision check in storage layer. */
function bc_crypto_custody_next($old,$actor,$action,$input,$now) {
    if (!is_int($actor)||$actor<=0 || !is_int($now)||$now<=0) throw new InvalidArgumentException('crypto_custody_actor_invalid');
    if ($action==='propose') {
        if (!in_array($input['model']??null,['dedicated_subaccount','omnibus_exclusive_routes'],true)) throw new InvalidArgumentException('crypto_custody_model_invalid');
        return ['state'=>'proposed','model'=>$input['model'],'evidence_hash'=>bc_crypto_custody_digest($input['evidence_hash']??null),
            'proposed_by'=>$actor,'reviewed_by'=>0,'valid_until'=>0,'bindings'=>[]];
    }
    if (!$old || !in_array($old['state']??null,['proposed','review_only','suspended'],true)) throw new RuntimeException('crypto_custody_proposal_required');
    if ($action==='suspend') { $old['state']='suspended';$old['valid_until']=0;return $old; }
    if ($action==='review') {
        if ($old['state']!=='proposed' || $actor===($old['proposed_by']??0)) throw new RuntimeException('crypto_custody_independent_review_required');
        $ttl=bc_crypto_gov_uint($input['valid_seconds']??null,86400);
        if ($ttl===0) throw new InvalidArgumentException('crypto_custody_expiry_invalid');
        // Bind the review to the exact proposal evidence; a stale reviewer cannot approve changed evidence.
        if (!hash_equals($old['evidence_hash'],bc_crypto_custody_digest($input['evidence_hash']??null))) throw new RuntimeException('crypto_custody_evidence_conflict');
        $old['state']='review_only';$old['reviewed_by']=$actor;$old['valid_until']=$now+$ttl;return $old;
    }
    if ($old['state']!=='review_only' || ($old['valid_until']??0)<=$now) throw new RuntimeException('crypto_custody_review_expired_or_missing');
    $vendor=$input['vendor_id']??null;
    if (!is_int($vendor)||$vendor<=0) throw new InvalidArgumentException('crypto_custody_vendor_invalid');
    $binding=$old['bindings'][(string)$vendor]??null;
    if ($action==='propose_binding') {
        $old['bindings'][(string)$vendor]=['state'=>'proposed','proposed_by'=>$actor,'reviewed_by'=>0,'valid_until'=>0,
            'evidence_hash'=>bc_crypto_custody_digest($input['evidence_hash']??null)];
    } elseif ($action==='review_binding') {
        if (!$binding || $binding['state']!=='proposed' || $binding['proposed_by']===$actor) throw new RuntimeException('crypto_custody_independent_review_required');
        if (!hash_equals($binding['evidence_hash'],bc_crypto_custody_digest($input['evidence_hash']??null))) throw new RuntimeException('crypto_custody_evidence_conflict');
        $ttl=bc_crypto_gov_uint($input['valid_seconds']??null,86400);
        if ($ttl===0) throw new InvalidArgumentException('crypto_custody_expiry_invalid');
        $binding['state']='review_only';$binding['reviewed_by']=$actor;$binding['valid_until']=min($now+$ttl,$old['valid_until']);
        $old['bindings'][(string)$vendor]=$binding;
    } elseif ($action==='revoke_binding') {
        if (!$binding) throw new RuntimeException('crypto_custody_binding_missing');
        $binding['state']='revoked';$binding['valid_until']=0;$old['bindings'][(string)$vendor]=$binding;
    } else throw new InvalidArgumentException('crypto_custody_action_invalid');
    return $old;
}
function bc_crypto_custody_eligible($document,$vendor,$now) {
    if (!is_int($vendor)||$vendor<=0 || !is_int($now)||$now<=0 || !in_array($document['model']??null,['dedicated_subaccount','omnibus_exclusive_routes'],true) || !is_array($document['bindings']??null)) return false;
    $b=$document['bindings'][(string)$vendor]??null;
    foreach([$document,$b] as $d) {
        if (!is_array($d) || ($d['state']??null)!=='review_only' || !is_int($d['valid_until']??null) || $d['valid_until']<=$now || !is_int($d['proposed_by']??null) || !is_int($d['reviewed_by']??null) || $d['proposed_by']<=0 || $d['reviewed_by']<=0 || $d['proposed_by']===$d['reviewed_by'] || !is_string($d['evidence_hash']??null) || !preg_match('/^[a-f0-9]{64}$/D',$d['evidence_hash'])) return false;
    }
    return true;
}
function bc_crypto_custody_must($sql,$params) {
    if (bc_q($sql,$params)!==true) throw new RuntimeException('crypto_custody_storage_failed');
}
function bc_crypto_custody_engines() {
    foreach(['sas_crypto_v2_custody_accounts','sas_crypto_v2_custody_audit'] as $table) {
        $r=bc_q_row('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (!$r || strtoupper($r['engine']??'')!=='INNODB') throw new RuntimeException('crypto_custody_transactional_schema_required');
    }
}
/** Trusted internal caller only. No user-submitted identity/authority may reach this function. */
function bc_crypto_custody_save($authority,$env,$uid,$revision,$action,$input) {
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);$identity=bc_crypto_custody_identity($env,$uid);
    if (!is_int($revision)||$revision<0) throw new InvalidArgumentException('crypto_custody_revision_invalid');
    if (!bc_crypto_transaction_ready()) throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_custody_engines();
    if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_transaction_unavailable');
    try {
        bc_crypto_custody_must("INSERT INTO sas_crypto_v2_custody_accounts(custody_account,provider,environment,document) VALUES(?,'bybit',?,'{}') ON DUPLICATE KEY UPDATE custody_account=custody_account",[$identity,$env]);
        $row=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if (!$row || $row['provider']!=='bybit' || $row['environment']!==$env || (int)$row['revision']!==$revision) throw new RuntimeException('crypto_custody_edit_conflict');
        $before=bc_crypto_gov_decode($row);
        $next=bc_crypto_custody_next($before,$actor,$action,$input,time());
        $json=bc_crypto_gov_json($next);$changed=null;
        if (bc_q('UPDATE sas_crypto_v2_custody_accounts SET document=?,revision=revision+1 WHERE custody_account=? AND revision=?',[$json,$identity,$revision],$changed)!==true || $changed!==1) throw new RuntimeException('crypto_custody_edit_conflict');
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_custody_audit(custody_account,revision,actor_id,action,before_document,after_document) VALUES(?,?,?,?,?,?)',[$identity,$revision+1,$actor,$action,$row['document'],$json]);
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_custody_commit_failed');
        return ['custody_account'=>$identity,'revision'=>$revision+1,'state'=>$next['state'],'live_enabled'=>false];
    } catch(Throwable $e) {mysqli_rollback($connection_server);throw $e;}
}
/** Called while observation owns its transaction; lock makes suspension/expiry reevaluation atomic. */
function bc_crypto_custody_observation_allowed($vendor,$env,$identity) {
    if (!is_int($vendor)||$vendor<=0) throw new InvalidArgumentException('crypto_custody_vendor_invalid');
    bc_crypto_gov_environment($env);bc_crypto_custody_digest($identity);
    $row=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
    if (!$row || $row['provider']!=='bybit' || $row['environment']!==$env || !bc_crypto_custody_eligible(bc_crypto_gov_decode($row),$vendor,time())) return false;
    return true;
}
