<?php
/** Read-only tenant-scoped projections. No dispatch, write, migration or provider call. */
function bc_sar_id($value) {
    if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)||(float)$value>2147483647)return null;
    return (int)$value;
}
function bc_sar_select($sql,$params) {
    global $connection_server;
    if(!($connection_server instanceof mysqli))throw new RuntimeException('review_unavailable');
    $stmt=$connection_server->prepare($sql);if(!$stmt)throw new RuntimeException('review_unavailable');
    try {
        if($params){$types=str_repeat('i',count($params));$stmt->bind_param($types,...$params);}
        if(!$stmt->execute())throw new RuntimeException('review_unavailable');
        $res=$stmt->get_result();if(!$res)throw new RuntimeException('review_unavailable');
        return $res->fetch_all(MYSQLI_ASSOC);
    }finally{$stmt->close();}
}
function bc_sar_snapshot($read) {
    global $connection_server;$begun=false;
    try {
        $auto=bc_sar_select('SELECT @@autocommit AS enabled',[]);
        if((int)($auto[0]['enabled']??0)!==1)throw new RuntimeException('review_unavailable');
        if(!$connection_server->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'))throw new RuntimeException('review_unavailable');
        $engines=bc_sar_select("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sas_service_attempts','sas_service_attempt_events','sas_service_requests')",[]);
        if(count($engines)!==3)throw new RuntimeException('review_unavailable');
        foreach($engines as $engine)if(strtolower($engine['ENGINE'])!=='innodb')throw new RuntimeException('review_unavailable');
        if(!$connection_server->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT))throw new RuntimeException('review_unavailable');
        $begun=true;$value=$read();
        if(!$connection_server->commit())throw new RuntimeException('review_unavailable');$begun=false;return $value;
    }catch(Throwable $e){if($begun)try{$connection_server->rollback();}catch(Throwable $ignored){}throw new RuntimeException('review_unavailable');}
}
function bc_sar_columns() {
    // Deliberately exclude payload, username, provider keys, evidence digests and observation hashes.
    return "r.id,r.reference,r.type,r.provider AS requested_provider,r.status AS intake_status,
        a.provider AS attempt_provider,a.state AS journal_state,a.version,
        (a.request_id IS NOT NULL AND (a.provider<>r.provider OR a.user_id<>r.user_id)) AS routing_owner_changed,
        EXISTS(SELECT 1 FROM sas_service_attempt_events e WHERE e.vendor_id=r.vendor_id AND e.request_id=r.id AND e.disposition IN ('conflict','stale')) AS has_review_events";
}
function bc_sar_list($vendor,$before=null) {
    if(!is_int($vendor)||$vendor<=0||($before!==null&&(!is_int($before)||$before<=0)))throw new InvalidArgumentException('invalid_scope');
    return bc_sar_snapshot(function()use($vendor,$before){
    $sql='SELECT '.bc_sar_columns().' FROM sas_service_requests r LEFT JOIN sas_service_attempts a ON a.vendor_id=r.vendor_id AND a.request_id=r.id WHERE r.vendor_id=?';
    $params=[$vendor];if($before!==null){$sql.=' AND r.id<?';$params[]=$before;}
    $rows=bc_sar_select($sql.' ORDER BY r.id DESC LIMIT 51',$params);$more=count($rows)>50;$rows=array_slice($rows,0,50);
    return ['rows'=>$rows,'next'=>$more?(int)$rows[count($rows)-1]['id']:null];
    });
}
function bc_sar_detail($vendor,$request_id) {
    if(!is_int($vendor)||$vendor<=0||!is_int($request_id)||$request_id<=0)throw new InvalidArgumentException('invalid_scope');
    return bc_sar_snapshot(function()use($vendor,$request_id){
    $row=bc_sar_select('SELECT '.bc_sar_columns().' FROM sas_service_requests r LEFT JOIN sas_service_attempts a ON a.vendor_id=r.vendor_id AND a.request_id=r.id WHERE r.vendor_id=? AND r.id=? LIMIT 1',[$vendor,$request_id])[0]??null;
    if(!$row)return null;
    $events=bc_sar_select('SELECT observed_outcome,disposition,result_state,result_version,created_at FROM sas_service_attempt_events WHERE vendor_id=? AND request_id=? ORDER BY created_at DESC,event_key DESC LIMIT 101',[$vendor,$request_id]);
    return ['request'=>$row,'events'=>array_slice($events,0,100),'events_truncated'=>count($events)>100];
    });
}
function bc_sar_label($state) {
    $labels=['held'=>'Held — not dispatched','in_flight'=>'Recorded in flight — do not resend','unknown'=>'Unknown outcome — reconcile before action','confirmed_success'=>'Journaled success observation — not settlement proof','confirmed_failure'=>'Journaled failure observation — no retry authorization'];
    if($state===null)return 'No journal entry — execution not established';
    return $labels[$state]??'Unrecognized journal state — requires review';
}
