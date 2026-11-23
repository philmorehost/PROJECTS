<?php
/** Action verification preferences. Never changes passwords, PINs, enrolments or login/recovery challenges. */
function bc_action_channel_decode($raw) {
    if($raw===null || $raw==='')return null;
    $p=json_decode($raw,true);
    if(!is_array($p) || count($p)!==2 || !is_bool($p['google']??null) || !is_bool($p['email']??null))throw new RuntimeException('Invalid security policy.');
    return $p;
}
function bc_action_channels($table,$actor) {
    global $connection_server;
    $default=['google'=>true,'email'=>true];
    if(!isset($connection_server) || !($connection_server instanceof mysqli))return $default;
    $q=mysqli_query($connection_server,"SELECT option_value FROM sas_super_admin_options WHERE option_name='action_security_channels' LIMIT 2");
    if(!$q || mysqli_num_rows($q)>1)throw new RuntimeException('Security policy unavailable.');
    $r=mysqli_fetch_assoc($q);$p=bc_action_channel_decode($r?$r['option_value']:null)??$default;
    if($table!=='sas_super_admin'){
        $vid=$table==='sas_vendors'?(int)$actor['id']:(int)$actor['vendor_id'];
        if($vid<=0)throw new RuntimeException('Invalid security scope.');
        $q=mysqli_query($connection_server,"SELECT setting_value FROM sas_settings WHERE vendor_id=$vid AND setting_name='action_security_channels' LIMIT 2");
        if(!$q || mysqli_num_rows($q)>1)throw new RuntimeException('Security policy unavailable.');
        $r=mysqli_fetch_assoc($q);$p=bc_action_channel_decode($r?$r['setting_value']:null)??$p;
    }
    return $p;
}
function bc_action_channel_choice($p,$enrolled,$requested='') {
    if(!in_array($requested,['','auto','google','email'],true))return 'invalid';
    if(!$p['google'] && !$p['email'])return 'none';
    if($requested==='google')return $p['google'] && $enrolled?'google':'invalid';
    if($requested==='email')return $p['email']?'email':'invalid';
    if($p['google'] && $enrolled)return 'google';
    return $p['email']?'email':'invalid';
}
