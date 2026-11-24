<?php
require_once __DIR__.'/webhook-monitor.php';

/** Full bounded scan from zero, read-only. No external alert sink or delivery side effect.
 * Optional reader is an internal deterministic fixture seam, never CLI-controlled.
 */
function payhub_outbox_alert(PDO $db,$maxPages=5,$pageSize=100,$ageSeconds=900,?callable $reader=null){
    if(!is_int($maxPages)||$maxPages<1||$maxPages>50||!is_int($pageSize)||$pageSize<1||$pageSize>200||!is_int($ageSeconds)||$ageSeconds<60||$ageSeconds>604800)throw new InvalidArgumentException('Invalid alert bounds.');
    if($db->inTransaction())throw new RuntimeException('Alert scan requires independent connection scope.');
    $reader=$reader??'payhub_outbox_monitor';
    $keys=['unattempted','existing_evidence','claimed_attempting','claimed_unknown','claimed_succeeded','requires_review'];
    $counts=array_fill_keys($keys,0);$aged=$counts;$after=0;$scanned=0;$complete=false;
    for($pageNumber=1;$pageNumber<=$maxPages;$pageNumber++){
        $page=$reader($db,$after,$pageSize,$ageSeconds);
        // Refuse malformed/contradictory observations instead of publishing a healthy result.
        if(($page['scope']??null)!=='bounded_outbox_page_not_global_totals'||($page['advisory_only']??null)!==true||($page['after']??null)!==$after||($page['age_seconds']??null)!==$ageSeconds||!is_bool($page['has_more']??null)||!is_int($page['scanned']??null)||$page['scanned']<0||$page['scanned']>$pageSize||!is_int($page['next_after']??null)||$page['next_after']<$after||$page['next_after']>2147483647)throw new RuntimeException('Invalid monitor observation.');
        if(($page['scanned']>0 && $page['next_after']<=$after)||($page['scanned']===0 && ($page['next_after']!==$after||$page['has_more']))||($page['has_more'] && $page['scanned']!==$pageSize))throw new RuntimeException('Non-progressing monitor observation.');
        $total=0;
        foreach($keys as $key){
            $count=$page['counts'][$key]??null;$old=$page['aged_counts'][$key]??null;
            if(!is_int($count)||!is_int($old)||$count<0||$old<0||$old>$count||$count>$pageSize)throw new RuntimeException('Invalid monitor counts.');
            $total+=$count;$counts[$key]+=$count;$aged[$key]+=$old;
        }
        if($total!==$page['scanned'])throw new RuntimeException('Inconsistent monitor counts.');
        $scanned+=$total;$after=$page['next_after'];
        if(!$page['has_more']){$complete=true;break;}
    }
    $reasons=[];
    if($counts['claimed_unknown']>0)$reasons[]='uncertain_delivery_requires_review';
    if($counts['requires_review']>0)$reasons[]='unrecognized_claim_state';
    if($aged['claimed_attempting']>0)$reasons[]='aged_attempt_requires_review';
    if($aged['unattempted']>0)$reasons[]='aged_unattempted_event';
    if($aged['existing_evidence']>0)$reasons[]='aged_legacy_evidence_requires_review';
    if(!$complete)$reasons[]='scan_limit_reached';
    // Operational plugin convention: 0=no configured signal observed, 1=warning, 2=critical,
    // 3=unknown/incomplete. Incomplete takes precedence, while observed reasons remain visible.
    $code=($counts['claimed_unknown']>0||$counts['requires_review']>0||$aged['claimed_attempting']>0)?2:($reasons?1:0);
    if(!$complete)$code=3;
    return ['report_version'=>1,'scope'=>'bounded_outbox_scan_not_settlement_health','advisory_only'=>true,'complete'=>$complete,'exit_code'=>$code,'severity'=>[0=>'no_signal_observed',1=>'warning',2=>'critical',3=>'unknown'][$code],'reasons'=>$reasons,'scanned'=>$scanned,'pages'=>min($pageNumber,$maxPages),'max_pages'=>$maxPages,'page_size'=>$pageSize,'next_after'=>$after,'age_seconds'=>$ageSeconds,'counts'=>$counts,'aged_counts'=>$aged];
}
