<?php
/** Server-side authority boundary for platform custody UI; never accept a posted actor/role. */
require_once __DIR__.'/bc-crypto-custody-evidence.php';
require_once __DIR__.'/bc-crypto-bybit-identity.php';
require_once __DIR__.'/bc-crypto-bybit-address-read.php';
require_once __DIR__.'/bc-crypto-routes.php';
function bc_crypto_custody_authorize($session_id,$input) {
    if (!is_int($session_id)||$session_id<=0) throw new RuntimeException('crypto_custody_access_denied');
    if (bc_is_rate_limited('crypto_custody_sensitive','spadmin:'.$session_id,5,300)) throw new RuntimeException('crypto_custody_rate_limited');
    $fresh=bc_q_row('SELECT * FROM sas_super_admin WHERE id=? LIMIT 1',[$session_id]);
    if (!$fresh||(int)($fresh['id']??0)!==$session_id||(int)($fresh['status']??0)!==1||!empty($fresh['is_blocked'])||!empty($fresh['force_password_change'])||!bc_gauth_enrolled($fresh)) throw new RuntimeException('crypto_custody_fresh_authenticator_required');
    if (bc_stepup_spadmin_verify($fresh,$input,'crypto_custody_sensitive')!=='') throw new RuntimeException('crypto_custody_stepup_failed');
    return ['role'=>'spadmin','id'=>$session_id];
}
/** POST/CSRF enforced here as well as authenticated route configuration. */
function bc_crypto_custody_manage($session_id,$input) {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new RuntimeException('crypto_custody_post_required');
    bc_validate_csrf();
    $authority=bc_crypto_custody_authorize($session_id,$input);
    $env=bc_crypto_gov_environment($input['environment']??null);
    $action=$input['action']??null;
    if (!in_array($action,['propose_route','review_route','revoke_route','inspect_master_address','verify_provider','store_evidence','view_evidence','propose','review','suspend','propose_binding','review_binding','revoke_binding'],true)) throw new InvalidArgumentException('crypto_custody_action_invalid');
    $identity=$input['custody_account']??'';$uid=$input['uid']??'';$new=false;
    if ($identity==='') {
        if (!in_array($action,['store_evidence','propose'],true)) throw new RuntimeException('crypto_custody_proposal_required');
        $identity=bc_crypto_custody_identity($env,$uid);$new=true;
    } else {
        if ($uid!=='') throw new InvalidArgumentException('crypto_custody_identity_ambiguous');
        bc_crypto_custody_digest($identity);
        $account=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$identity]);
        if (!$account||$account['environment']!==$env) throw new RuntimeException('crypto_custody_proposal_required');
    }
    if ($action==='store_evidence') {
        $hash=bc_crypto_custody_evidence_store($authority,$identity,$env,$input['evidence_text']??null);
        return ['custody_account'=>$identity,'evidence_hash'=>$hash,'live_enabled'=>false];
    }
    $revision=bc_crypto_gov_uint($input['revision']??null,1000000000);
    if (in_array($action,['propose_route','review_route','revoke_route'],true)) {
        $route=[];
        if ($action==='propose_route') {
            foreach(['vendor_id','user_id'] as $key) $route[$key]=bc_crypto_gov_uint($input[$key]??null,1000000000);
            $route['scale']=bc_crypto_gov_uint($input['scale']??null,30);
            foreach(['asset_network','address','tag','note'] as $key) $route[$key]=$input[$key]??null;
        } else {
            foreach(['candidate_id','candidate_revision'] as $key) $route[$key==='candidate_revision'?'revision':$key]=bc_crypto_gov_uint($input[$key]??null,1000000000);
            $route['valid_seconds']=$input['valid_seconds']??null;
        }
        $result=bc_crypto_route_save($authority,$env,$identity,$revision,['propose_route'=>'propose','review_route'=>'review','revoke_route'=>'revoke'][$action],$route);
        $result['custody_account']=$identity;$result['route_candidate_updated']=true;
        return $result;
    }
    if (in_array($action,['verify_provider','inspect_master_address'],true)) {
        $source=bc_crypto_gov_uint($input['source_vendor_id']??null,1000000000);
        if ($source===0) throw new InvalidArgumentException('crypto_custody_vendor_invalid');
        $vendor=bc_q_row('SELECT id,status,is_blocked FROM sas_vendors WHERE id=? LIMIT 1',[$source]);
        if (!$vendor||(int)$vendor['status']!==1||!empty($vendor['is_blocked'])) throw new RuntimeException('crypto_custody_vendor_inactive');
        if ((int)$account['revision']!==$revision) throw new RuntimeException('crypto_custody_edit_conflict');
        if ($action==='inspect_master_address') bc_crypto_bybit_address_scope($input['coin']??null,$input['chain']??null);
        bc_crypto_custody_evidence_engines();
        bc_crypto_custody_evidence_key(getenv('BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY')?:'V1');
        // Persist a sanitized request audit BEFORE a provider read. No raw provider response is stored.
        if (!bc_sec_audit(0,'spadmin',(string)$authority['id'],$action==='inspect_master_address'?'crypto_address_requested':'crypto_identity_requested',$identity,$action==='inspect_master_address'?'Read-only Bybit master address inventory requested; not customer allocation.':'Read-only Bybit identity comparison requested.')) throw new RuntimeException('crypto_bybit_audit_unavailable');
        $snapshot=$action==='inspect_master_address'?bc_crypto_bybit_read_master_address($source,$env,$identity,$input['coin'],$input['chain']):bc_crypto_bybit_verify_identity($source,$env,$identity);
        if ($action==='inspect_master_address') {
            $current_vendor=bc_q_row('SELECT id,status,is_blocked FROM sas_vendors WHERE id=? LIMIT 1',[$source]);
            if (!$current_vendor||(int)$current_vendor['status']!==1||!empty($current_vendor['is_blocked'])) throw new RuntimeException('crypto_custody_vendor_inactive');
        }
        $current=bc_q_row('SELECT revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$identity]);
        if (!$current||(int)$current['revision']!==$revision) throw new RuntimeException('crypto_custody_edit_conflict');
        $snapshot['registry_revision']=$revision;
        $snapshot['verified_by_actor']=$authority['id'];
        $hash=bc_crypto_custody_evidence_store($authority,$identity,$env,bc_crypto_gov_json($snapshot),$revision);
        return ['custody_account'=>$identity,'evidence_hash'=>$hash,'provider_identity_compared'=>$action==='verify_provider','master_address_inspected'=>$action==='inspect_master_address','live_enabled'=>false];
    }
    if ($action==='view_evidence') return ['custody_account'=>$identity,'text'=>bc_crypto_custody_evidence_view($authority,$identity,$env,bc_crypto_custody_digest($input['evidence_hash']??null),$revision),'live_enabled'=>false];
    $values=[];
    if (in_array($action,['propose','review','propose_binding','review_binding'],true)) {
        $hash=bc_crypto_custody_digest($input['evidence_hash']??null);
        bc_crypto_custody_evidence_require($identity,$env,$hash,$authority['id'],$revision,in_array($action,['review','review_binding'],true));
        $values['evidence_hash']=$hash;
    }
    if ($action==='propose') $values['model']=$input['model']??null;
    if (in_array($action,['review','review_binding'],true)) $values['valid_seconds']=$input['valid_seconds']??null;
    if (in_array($action,['propose_binding','review_binding','revoke_binding'],true)) {
        $vendor=bc_crypto_gov_uint($input['vendor_id']??null,1000000000);
        if ($vendor===0) throw new InvalidArgumentException('crypto_custody_vendor_invalid');
        $row=bc_q_row('SELECT id,status,is_blocked FROM sas_vendors WHERE id=? LIMIT 1',[$vendor]);
        if (!$row || ($action!=='revoke_binding' && ((int)$row['status']!==1||!empty($row['is_blocked'])))) throw new RuntimeException('crypto_custody_vendor_inactive');
        $values['vendor_id']=$vendor;
    }
    return $new?bc_crypto_custody_save($authority,$env,$uid,$revision,$action,$values):bc_crypto_custody_save_identity($authority,$env,$identity,$revision,$action,$values);
}
