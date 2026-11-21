<?php
/** Pure bounded local-attempt policy, never permission to bypass current guards. */
function bc_crypto_poll_retry_decision($events,$now) {
    if (!is_array($events)||!is_int($now)||$now<1)throw new InvalidArgumentException('crypto_poll_retry_input_invalid');
    $failures=0;$last=0;$pending=[];
    // Input newest-first; claimed+failed are one unsuccessful attempt, not two.
    foreach($events as $e) {
        if (!in_array($e['action']??null,['claimed','released','failed'],true)||!is_int($e['at']??null)||$e['at']<1||!is_string($e['token']??null)||!preg_match('/^[a-f0-9]{64}$/D',$e['token']))throw new RuntimeException('crypto_poll_retry_history_invalid');
        $last=max($last,$e['at']);
        if ($e['action']==='released')break; // Successful checkpoint ends the consecutive failure sequence.
        $pending[$e['token']]=true;
    }
    $failures=count($pending);
    $delay=$failures===0?0:min(900,60*(2**min($failures-1,4)));
    $blocked=$failures>=3;
    $until=$last+$delay;
    return ['consecutive_unsuccessful_attempts'=>$failures,'retry_not_before'=>$failures?$until:0,
        'admission_allowed'=>!$blocked&&($failures===0||$now>=$until),
        'escalation_required'=>$blocked,'signal'=>$blocked?'repeated_attempts_require_investigation':(($failures&&$now<$until)?'retry_backoff':'manual_attempt_only'),
        'automatic_retry_authorized'=>false];
}
/** Current locking reads: invoked only with custody/stream lock inside claim transaction. */
function bc_crypto_poll_retry_history($id,$now) {
    $result=bc_q('SELECT action,token_hash,occurred_at_epoch FROM sas_crypto_v2_poll_lease_events WHERE stream_id=? ORDER BY id DESC LIMIT 101 FOR UPDATE',[$id]);
    if (!($result instanceof mysqli_result))throw new RuntimeException('crypto_poll_storage_failed');
    $events=[];while($r=mysqli_fetch_assoc($result))$events[]=['action'=>$r['action'],'token'=>$r['token_hash'],'at'=>(int)$r['occurred_at_epoch']];
    return bc_crypto_poll_retry_decision($events,$now);
}
function bc_crypto_poll_retry_check($id,$now) {
    $decision=bc_crypto_poll_retry_history($id,$now);
    if ($decision['escalation_required'])throw new RuntimeException('crypto_poll_retry_escalation_required');
    if (!$decision['admission_allowed'])throw new RuntimeException('crypto_poll_retry_backoff');
    return $decision;
}
