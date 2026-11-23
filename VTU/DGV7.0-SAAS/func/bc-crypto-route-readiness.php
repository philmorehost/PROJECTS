<?php
/** Protected point-in-time local preflight only. Never publish an address or activate a route. */
require_once __DIR__.'/bc-crypto-routes.php';
function bc_crypto_route_readiness($authority,$env,$identity,$custodyRevision,$candidateId,$candidateRevision){
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);bc_crypto_gov_environment($env);bc_crypto_custody_digest($identity);
    foreach([$custodyRevision,$candidateId,$candidateRevision] as $id)if(!is_int($id)||$id<=0)throw new RuntimeException('crypto_route_preflight_scope_invalid');
    if(!bc_crypto_transaction_ready())throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_route_engines();
    if(!mysqli_begin_transaction($connection_server))throw new RuntimeException('crypto_transaction_unavailable');
    try{
        $custody=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if(!$custody||$custody['environment']!==$env||(int)$custody['revision']!==$custodyRevision)throw new RuntimeException('crypto_custody_edit_conflict');
        $c=bc_q_row('SELECT * FROM sas_crypto_v2_route_candidates WHERE id=? AND custody_account=? AND environment=? FOR UPDATE',[$candidateId,$identity,$env]);
        if(!$c||(int)$c['revision']!==$candidateRevision)throw new RuntimeException('crypto_route_edit_conflict');
        if($c['state']!=='reviewed'||(int)$c['valid_until_epoch']<=time()||(int)$c['proposed_by']<=0||(int)$c['reviewed_by']<=0||(int)$c['proposed_by']===(int)$c['reviewed_by'])throw new RuntimeException('crypto_route_current_review_required');
        if((int)$c['custody_revision']!==$custodyRevision)throw new RuntimeException('crypto_route_custody_changed');
        bc_crypto_custody_evidence_require($identity,$env,$c['evidence_hash'],$actor,$custodyRevision,true);
        $row=bc_q_row('SELECT sealed_document FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND environment=? AND evidence_hash=?',[$identity,$env,$c['evidence_hash']]);
        $manifest=json_decode(bc_crypto_custody_evidence_open($identity,$env,$c['evidence_hash'],$row['sealed_document']),true);
        if(!isset($manifest['route']['bound_address_evidence_hash'],$manifest['route']['binding_revision']))throw new RuntimeException('crypto_route_bound_evidence_required');
        $scope=bc_crypto_bound_address_scope($custody,$identity,$env,$custodyRevision,$actor,$manifest['route']['bound_address_evidence_hash'],(int)$manifest['route']['binding_revision']);
        foreach(['vendor_id','user_id','scale'] as $field)if((int)$c[$field]!==$scope[$field])throw new RuntimeException('crypto_bound_address_context_invalid');
        if($c['asset_network']!==$scope['asset_network']||$c['routing_hash']!==$scope['routing_hash'])throw new RuntimeException('crypto_bound_address_context_invalid');
        $ceiling=bc_crypto_route_guard($custody,$env,$c);
        bc_crypto_route_conflicts($env,$c['asset_network'],$c['routing_hash'],(int)$c['id']);
        $until=min($ceiling,$scope['evidence_expires_at'],(int)$c['valid_until_epoch']);
        if($until<=time())throw new RuntimeException('crypto_route_current_review_required');
        if(!mysqli_commit($connection_server))throw new RuntimeException('crypto_route_preflight_commit_failed');
        return ['candidate_id'=>$candidateId,'candidate_revision'=>$candidateRevision,'environment'=>$env,'asset_network'=>$c['asset_network'],
            'checked_at_epoch'=>time(),'local_context_valid_until_epoch'=>$until,'local_checks_passed'=>true,
            'checks'=>['independent_candidate_review','fresh_candidate_address_relationship_views','server_attested_bound_address','current_customer_binding','current_custody_parent_config_customer_kyc','current_policy_and_catalogue','no_exact_route_conflict'],
            'live_provider_verified'=>false,'operational_readiness_verified'=>false,'customer_funding_enabled'=>false,'credit_posting_enabled'=>false,'live_enabled'=>false,
            'warning'=>'Local checks only, not a funding grant. No provider call was made. Revalidate on every later use; no address is published.'];
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
