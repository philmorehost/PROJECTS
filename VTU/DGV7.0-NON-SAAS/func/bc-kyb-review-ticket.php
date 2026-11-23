<?php
function bc_kyb_review_fingerprint($row){
    $state=[];foreach(['id','vendor_id','username','email','account_type','kyb_status','kyb_reference','kyb_document','kyb_business_name','kyb_registration_no','kyb_category','kyb_submitted_at','kyc_status','kyc_refresh_required','kyc_id_expiry','status','is_blocked'] as $field)$state[$field]=$row[$field]??null;
    return hash('sha256',serialize($state));
}
function bc_kyb_reviewer_binding($actor){
    return hash('sha256',serialize([(int)($actor['id']??0),(string)($actor['email']??''),(string)($actor['password']??''),(string)($actor['security_pin']??'')]));
}
function bc_kyb_review_is_self($row,$actor){
    foreach(['email','username'] as $field){$a=trim((string)($actor[$field]??''));$b=trim((string)($row[$field]??''));if($a!==''&&$b!==''&&strcasecmp($a,$b)===0)return true;}
    return false;
}
function bc_kyb_review_ticket($row,$actor){
    $tickets=$_SESSION['kyb_review_tickets']??[];
    foreach($tickets as $key=>$entry)if((int)($entry['until']??0)<=time())unset($tickets[$key]);
    while(count($tickets)>=200)array_shift($tickets);
    $token=bin2hex(random_bytes(32));
    $tickets[$token]=['until'=>time()+600,'actor'=>bc_kyb_reviewer_binding($actor),'state'=>bc_kyb_review_fingerprint($row)];
    $_SESSION['kyb_review_tickets']=$tickets;return $token;
}
function bc_kyb_review_consume($token,$row,$actor){
    if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token))return null;
    $entry=$_SESSION['kyb_review_tickets'][$token]??null;unset($_SESSION['kyb_review_tickets'][$token]);
    if(!is_array($row)||!is_array($entry)||(int)($row['vendor_id']??0)!==(int)($actor['id']??0)||(int)($entry['until']??0)<=time()||bc_kyb_review_is_self($row,$actor))return null;
    if(!hash_equals($entry['actor'],bc_kyb_reviewer_binding($actor))||!hash_equals($entry['state'],bc_kyb_review_fingerprint($row)))return null;
    return $entry['state'];
}
