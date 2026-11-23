<?php
/** Vendor compliance review tickets bind the evidence actually rendered, not just vendor id. */
function bc_vcr_state($vendor, $docs) {
    $v=[]; foreach(['id','email','bvn','nin','kyc_status','kyc_reject_reason'] as $k)$v[$k]=$vendor[$k]??null;
    usort($docs,fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
    return hash('sha256',serialize([$v,$docs]));
}
function bc_vcr_actor($actor) {
    return hash('sha256',serialize([$actor['id']??0,$actor['email']??'', $actor['password']??'', $actor['security_pin']??'']));
}
function bc_vcr_ticket($vendor,$docs,$actor) {
    $tickets=$_SESSION['vendor_compliance_tickets']??[];
    foreach($tickets as $k=>$t)if($t['until']<=time())unset($tickets[$k]);
    while(count($tickets)>=100)array_shift($tickets);
    $key=bin2hex(random_bytes(32));$tickets[$key]=['state'=>bc_vcr_state($vendor,$docs),'actor'=>bc_vcr_actor($actor),'until'=>time()+600];
    $_SESSION['vendor_compliance_tickets']=$tickets;return $key;
}
function bc_vcr_consume($key,$actor) {
    if(!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key))return null;
    $t=$_SESSION['vendor_compliance_tickets'][$key]??null;unset($_SESSION['vendor_compliance_tickets'][$key]);
    return is_array($t)&&$t['until']>time()&&hash_equals($t['actor'],bc_vcr_actor($actor))?$t['state']:null;
}
/** Call ONLY after authenticated superadmin CSRF and step-up verification. No schema DDL here. */
function bc_vcr_decide($vid,$decision,$reason,$actor,$expected) {
    global $connection_server;
    $db=$connection_server;$begun=false;$vid=(int)$vid;$reason=trim($reason);
    if(!$expected||$vid<=0||!in_array($decision,['approve','reject'],true)||($decision==='reject'&&$reason==='')||strlen($reason)>255)return 'Reload the review or supply a rejection reason (maximum 255 bytes).';
    try {
        $auto=$db->query('SELECT @@autocommit AS enabled')->fetch_assoc();if((int)$auto['enabled']!==1)return 'Review unavailable inside another transaction.';
        $db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $engines=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sas_vendors','sas_vendor_kyc_documents')")->fetch_all(MYSQLI_ASSOC);
        if(count($engines)!==2)return 'Review schema unavailable.';
        foreach($engines as $e)if(strtolower($e['ENGINE'])!=='innodb')return 'Operator migration to InnoDB is required before review.';
        $db->begin_transaction();$begun=true;
        $v=$db->query("SELECT * FROM sas_vendors WHERE id=$vid FOR UPDATE")->fetch_assoc();
        $docs=$db->query("SELECT * FROM sas_vendor_kyc_documents WHERE vendor_id=$vid ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if(!$v||!hash_equals($expected,bc_vcr_state($v,$docs)))throw new RuntimeException('Evidence changed or the review expired. Reload and review the current submission.');
        if(!empty($v['email'])&&!empty($actor['email'])&&strcasecmp(trim($v['email']),trim($actor['email']))===0)throw new RuntimeException('An independent super administrator must review this vendor.');
        if($decision==='approve') {
            if((int)$v['kyc_status']!==1)throw new RuntimeException('Only an under-review submission can be approved.');
            if(bc_vendor_kyc_pending($v))throw new RuntimeException('Required evidence is missing. Approval refused.');
        }
        $status=$decision==='approve'?2:3;$docstatus=$decision==='approve'?'approved':'rejected';$by=(int)$actor['id'];
        $stmt=$db->prepare('UPDATE sas_vendors SET kyc_status=?,kyc_reject_reason=? WHERE id=?');$note=$decision==='reject'?$reason:null;
        $stmt->bind_param('isi',$status,$note,$vid);$stmt->execute();$stmt->close();
        $stmt=$db->prepare("UPDATE sas_vendor_kyc_documents SET status=?,reviewed_at=NOW(),reviewed_by=?,review_note=? WHERE vendor_id=? AND status='pending'");
        $stmt->bind_param('sisi',$docstatus,$by,$reason,$vid);$stmt->execute();$stmt->close();
        $db->commit();$begun=false;return true;
    } catch(Throwable $e) {
        if($begun)try{$db->rollback();}catch(Throwable $ignored){}
        return $e instanceof RuntimeException&&!($e instanceof mysqli_sql_exception)?$e->getMessage():'Decision could not be confirmed. Reload the queue before retrying.';
    }
}
