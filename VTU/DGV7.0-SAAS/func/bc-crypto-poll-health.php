<?php
/** Advisory only: never authorizes a fetch, releases a lease or creates a replacement window. */
function bc_crypto_poll_health($stream,$now) {
    if (!is_int($now)||$now<1)throw new InvalidArgumentException('crypto_poll_health_time_invalid');
    $state=$stream['state']??null;
    if (!in_array($state,['ready','exhausted','stopped'],true))throw new RuntimeException('crypto_poll_health_state_invalid');
    $held=(int)($stream['lease_until']??0)>$now;
    $failed=(int)($stream['failed_attempts']??0);
    if ($state==='stopped') {$health='stopped';$action='preserve_evidence_no_restart';}
    elseif ($state==='exhausted') {$health='window_exhausted';$action='review_coverage_before_new_window';}
    elseif ((int)$stream['valid_until_epoch']<=$now) {$health='expired';$action='stop_then_review_replacement_window';}
    elseif ((int)$stream['page_count']>=100) {$health='page_limit';$action='stop_and_investigate_coverage_gap';}
    elseif ($held) {$health='read_lease_held';$action='wait_or_explicitly_stop_no_parallel_retry';}
    elseif ($failed>0) {$health='attempt_review_required';$action='review_evidence_and_failures_before_manual_retry';}
    elseif ((int)($stream['lease_until']??0)>0) {$health='lease_expired_unknown_outcome';$action='refresh_revision_before_manual_retry';}
    else {$health='ready_for_manual_attempt';$action='fresh_stepup_and_current_guards_required';}
    return ['health'=>$health,'recommended_action'=>$action,'lease_currently_held'=>$held,
        'has_recorded_failures'=>$failed>0,'automatic_retry_authorized'=>false,'replacement_window_authorized'=>false,
        'history_complete'=>false,'settlement_enabled'=>false];
}
