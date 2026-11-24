<?php
/** Read-only bounded inventory. Advisory snapshot, never delivery eligibility. */
function payhub_outbox_monitor(PDO $db,$after=0,$limit=100,$ageSeconds=900){
    if(!is_int($after)||$after<0||$after>2147483647||!is_int($limit)||$limit<1||$limit>200||!is_int($ageSeconds)||$ageSeconds<60||$ageSeconds>604800)throw new InvalidArgumentException('Invalid monitor bounds.');
    if($db->inTransaction())throw new RuntimeException('Monitor requires independent connection scope.');
    // Only IDs, timestamps and delivery state; never select payload, endpoint, key or customer data.
    $q=$db->prepare("SELECT o.transaction_id, o.created_at, c.state,
        EXISTS(SELECT 1 FROM webhook_logs w WHERE w.transaction_id=o.transaction_id) AS has_log,
        (o.created_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ".$ageSeconds." SECOND)) AS aged
        FROM webhook_outbox o LEFT JOIN webhook_retry_claims c ON c.transaction_id=o.transaction_id
        WHERE o.transaction_id>? ORDER BY o.transaction_id LIMIT ".($limit+1));
    $q->execute([$after]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>$limit;if($more)array_pop($rows);
    $counts=array_fill_keys(['unattempted','existing_evidence','claimed_attempting','claimed_unknown','claimed_succeeded','requires_review'],0);$aged=$counts;$last=$after;
    foreach($rows as $row){
        $last=(int)$row['transaction_id'];
        if($row['state']===null)$category=(int)$row['has_log']===1?'existing_evidence':'unattempted';
        else $category=in_array($row['state'],['attempting','unknown','succeeded'],true)?'claimed_'.$row['state']:'requires_review';
        $counts[$category]++;if((int)$row['aged']===1)$aged[$category]++;
    }
    return ['scope'=>'bounded_outbox_page_not_global_totals','advisory_only'=>true,'scanned'=>count($rows),'after'=>$after,'next_after'=>$last,'has_more'=>$more,'age_seconds'=>$ageSeconds,'counts'=>$counts,'aged_counts'=>$aged];
}
