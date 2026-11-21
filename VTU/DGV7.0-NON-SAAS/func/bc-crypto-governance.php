<?php
require_once __DIR__ . '/bc-crypto-decimal.php';
require_once __DIR__ . '/bc-crypto-transaction.php';
/** Governance is local approval, not live transaction activation or proof of reserves. */
function bc_crypto_gov_environment($env) {
    if (!in_array($env, ['testnet','mainnet'], true)) throw new InvalidArgumentException('crypto_environment_invalid');
    return $env;
}
function bc_crypto_gov_asset($asset) {
    if (!is_string($asset) || !preg_match('/^[A-Z0-9]{1,24}:[A-Za-z0-9_-]{1,48}$/D', $asset)) throw new InvalidArgumentException('crypto_asset_invalid');
    return $asset;
}
function bc_crypto_gov_uint($value, $max) {
    if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) || strlen($value)>strlen((string)$max) || (int)$value>$max) throw new InvalidArgumentException('crypto_policy_number_invalid');
    return (int)$value;
}
function bc_crypto_gov_json($value) {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json)>4194304) throw new RuntimeException('crypto_policy_document_invalid');
    return $json;
}
function bc_crypto_gov_decode($row) {
    if (!$row) return null;
    $data=json_decode($row['document'], true, 64);
    if (!is_array($data)) throw new RuntimeException('crypto_policy_document_invalid');
    return $data;
}
function bc_crypto_gov_snapshot($vendor_id, $env, $lock = false) {
    bc_crypto_gov_environment($env);
    if (!is_int($vendor_id) || $vendor_id<=0) throw new InvalidArgumentException('crypto_scope_invalid');
    return bc_q_row('SELECT * FROM sas_crypto_v2_catalogues WHERE vendor_id=? AND environment=? LIMIT 1' . ($lock?' FOR UPDATE':''), [$vendor_id,$env]);
}
function bc_crypto_gov_network($snapshot, $asset, $now) {
    bc_crypto_gov_asset($asset);
    if (!$snapshot || (int)$snapshot['expires_at_epoch']<=$now || (int)$snapshot['fetched_at_epoch']>$now || (int)$snapshot['expires_at_epoch']-(int)$snapshot['fetched_at_epoch']>900) throw new RuntimeException('crypto_catalogue_stale');
    $doc=bc_crypto_gov_decode($snapshot);
    if (!isset($doc[$asset]) || !is_array($doc[$asset])) throw new RuntimeException('crypto_network_not_discovered');
    return $doc[$asset];
}
function bc_crypto_gov_store_snapshot($vendor_id, $snapshot) {
    $env=bc_crypto_gov_environment($snapshot['environment']??'');
    if (!is_int($vendor_id)||$vendor_id<=0 || ($snapshot['provider']??'')!=='bybit' || empty($snapshot['security']['read_only']) || empty($snapshot['security']['ip_binding_verified']) || !is_array($snapshot['networks']??null)) throw new RuntimeException('crypto_discovery_unverified');
    $networks=[];
    foreach($snapshot['networks'] as $network) {
        $asset=bc_crypto_gov_asset($network['asset_network']??null);
        if (isset($networks[$asset]) || ($network['platform_enabled']??null)!==false) throw new RuntimeException('crypto_catalogue_invalid');
        $networks[$asset]=$network;
    }
    $now=time();
    // Only caller of this function is authenticated, stepped-up discovery; never accept a browser snapshot.
    $ok=bc_q('INSERT INTO sas_crypto_v2_catalogues(vendor_id,environment,document,fetched_at_epoch,expires_at_epoch) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE document=VALUES(document),fetched_at_epoch=VALUES(fetched_at_epoch),expires_at_epoch=VALUES(expires_at_epoch)',[$vendor_id,$env,bc_crypto_gov_json($networks),$now,$now+900]);
    if ($ok!==true) throw new RuntimeException('crypto_catalogue_storage_unavailable');
}
function bc_crypto_gov_build_rule($input, $network, $now) {
    if (($network['provider_deposit_available']??false)!==true && ($network['provider_withdraw_available']??false)!==true) throw new RuntimeException('crypto_network_suspended');
    $scale=bc_crypto_gov_uint($input['scale']??null,30);
    $minimum=bc_crypto_to_units($input['minimum']??null,$scale);
    $maximum=bc_crypto_to_units($input['maximum']??null,$scale);
    if ($maximum==='0'||bc_crypto_compare($minimum,$maximum)>0) throw new InvalidArgumentException('crypto_policy_limit_invalid');
    $confirm=bc_crypto_gov_uint($input['confirmations']??null,1000000000);
    if ($confirm < max((int)$network['confirmations'],(int)$network['withdrawal_confirmations'])) throw new InvalidArgumentException('crypto_confirmation_below_provider');
    $lifetime=bc_crypto_gov_uint($input['valid_seconds']??null,86400);
    if ($lifetime===0) throw new InvalidArgumentException('crypto_policy_expiry_invalid');
    return ['approved'=>true,'scale'=>$scale,'min_units'=>$minimum,'max_units'=>$maximum,
        'fee_bps'=>bc_crypto_gov_uint($input['fee_bps']??null,10000),
        'confirmations'=>$confirm,'cooldown_seconds'=>bc_crypto_gov_uint($input['cooldown_seconds']??null,2592000),
        'expires_at'=>$now+$lifetime,
        'provider_fingerprint'=>hash('sha256',bc_crypto_gov_json($network))];
}
/** Missing/malformed approvals or controls default closed; no external calls. */
function bc_crypto_gov_evaluate($platform, $tenant, $platform_control, $tenant_control, $network, $now, $activity='withdraw') {
    if (!in_array($activity,['withdraw','deposit'],true)) throw new InvalidArgumentException('crypto_activity_invalid');
    foreach([$platform_control,$tenant_control] as $control) if (($control['stopped']??true)!==false) throw new RuntimeException('crypto_emergency_stop');
    foreach([$platform,$tenant] as $rule) {
        if (($rule['approved']??false)!==true || (int)($rule['expires_at']??0)<=$now || !hash_equals((string)($rule['provider_fingerprint']??''), hash('sha256',bc_crypto_gov_json($network)))) throw new RuntimeException('crypto_approval_stale_or_missing');
        bc_crypto_scale($rule['scale']??null);bc_crypto_units($rule['min_units']??null);bc_crypto_units($rule['max_units']??null);
        if (!is_int($rule['expires_at']??null) || $rule['max_units']==='0' || bc_crypto_compare($rule['min_units'],$rule['max_units'])>0) throw new RuntimeException('crypto_approval_invalid');
        foreach (['fee_bps'=>10000,'confirmations'=>1000000000,'cooldown_seconds'=>2592000] as $field=>$ceiling) if (!is_int($rule[$field]??null) || $rule[$field]<0 || $rule[$field]>$ceiling) throw new RuntimeException('crypto_approval_invalid');
        if ($rule['confirmations']<max((int)($network['confirmations']??0),(int)($network['withdrawal_confirmations']??0))) throw new RuntimeException('crypto_confirmation_below_provider');
    }
    if ($platform['scale']!==$tenant['scale'] || bc_crypto_compare($tenant['max_units'],$platform['max_units'])>0 || bc_crypto_compare($tenant['min_units'],$platform['min_units'])<0 || $tenant['fee_bps']>$platform['fee_bps'] || $tenant['confirmations']<$platform['confirmations'] || $tenant['cooldown_seconds']<$platform['cooldown_seconds']) throw new RuntimeException('crypto_platform_ceiling');
    if (($network['provider_'.$activity.'_available']??false)!==true) throw new RuntimeException('crypto_provider_'.$activity.'_suspended');
    return ['approved'=>true,'scale'=>$tenant['scale'],'min_units'=>$tenant['min_units'],'max_units'=>$tenant['max_units'],
        'fee_bps'=>$tenant['fee_bps'],'confirmations'=>$tenant['confirmations'],'cooldown_seconds'=>$tenant['cooldown_seconds'],
        'live_money_movement_enabled'=>false];
}
function bc_crypto_gov_row($scope, $env, $key, $lock=false) {
    return bc_q_row('SELECT * FROM sas_crypto_v2_governance WHERE vendor_id=? AND environment=? AND policy_key=? LIMIT 1'.($lock?' FOR UPDATE':''),[$scope,$env,$key]);
}
function bc_crypto_gov_reservation_guard($scope, $units) {
    $vid=$scope['vendor_id'];$env=$scope['environment'];$asset=$scope['asset_network'];
    // Locks serialize emergency stops/policy edits against new admissions. No effect on historical holds.
    $pc=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,'control',true));
    $tc=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,'control',true));
    $p=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,$asset,true));
    $t=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,$asset,true));
    $network=bc_crypto_gov_network(bc_crypto_gov_snapshot($vid,$env,true),$asset,time());
    $effective=bc_crypto_gov_evaluate($p,$t,$pc,$tc,$network,time());
    if (bc_crypto_compare($units,$effective['min_units'])<0 || bc_crypto_compare($units,$effective['max_units'])>0) throw new RuntimeException('crypto_governance_limit');
    return $effective;
}
/** Controller supplies authority from the authenticated actor, never a submitted vendor ID. */
function bc_crypto_gov_save($authority, $scope, $env, $key, $revision, $input, $source_vendor) {
    global $connection_server;
    bc_crypto_gov_environment($env);
    if (!is_int($scope)||$scope<0 || !is_int($revision)||$revision<0 || !in_array($authority['role']??'', ['vendor','spadmin'],true) || !is_int($authority['id']??null)||$authority['id']<=0) throw new InvalidArgumentException('crypto_authority_invalid');
    if ($authority['role']==='vendor' && $scope!==$authority['id'] && !($scope===0 && ($authority['single_owner']??false)===true)) throw new RuntimeException('crypto_authority_denied');
    if ($key!=='control') bc_crypto_gov_asset($key);
    if (!bc_crypto_transaction_ready()||!mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_transaction_unavailable');
    try {
        if (bc_q("INSERT IGNORE INTO sas_crypto_v2_governance(vendor_id,environment,policy_key,revision,document) VALUES(?,?,?,0,'{}')",[$scope,$env,$key])!==true) throw new RuntimeException('crypto_policy_storage_failed');
        $before=bc_crypto_gov_row($scope,$env,$key,true);
        if (!$before || (int)$before['revision']!==$revision) throw new RuntimeException('crypto_policy_edit_conflict');
        $old=bc_crypto_gov_decode($before);
        if ($key==='control') {
            if (!in_array($input['mode']??'', ['stop','resume'],true)) throw new InvalidArgumentException('crypto_policy_mode_invalid');
            $next=['stopped'=>$input['mode']==='stop'];
        } elseif (($input['mode']??'')==='revoke') $next=['approved'=>false];
        elseif (($input['mode']??'')==='approve') {
            if ($authority['role']==='vendor' && $source_vendor!==$authority['id']) throw new RuntimeException('crypto_authority_denied');
            $network=bc_crypto_gov_network(bc_crypto_gov_snapshot($source_vendor,$env,true),$key,time());
            $next=bc_crypto_gov_build_rule($input,$network,time());
            // Never alter the accounting scale of existing approved records or accounts.
            $accounts=bc_q('SELECT scale FROM sas_crypto_v2_accounts WHERE vendor_id=? AND environment=? AND asset_network=? FOR UPDATE',[$scope,$env,$key]);
            if (!($accounts instanceof mysqli_result)) throw new RuntimeException('crypto_account_scale_check_failed');
            while ($account=mysqli_fetch_assoc($accounts)) if ((int)$account['scale']!==$next['scale']) throw new RuntimeException('crypto_policy_scale_immutable');
            if (isset($old['scale']) && $old['scale']!==$next['scale']) throw new RuntimeException('crypto_policy_scale_immutable');
        } else throw new InvalidArgumentException('crypto_policy_mode_invalid');
        $json=bc_crypto_gov_json($next);$changed=null;
        if (bc_q('UPDATE sas_crypto_v2_governance SET document=?,revision=revision+1 WHERE vendor_id=? AND environment=? AND policy_key=? AND revision=?',[$json,$scope,$env,$key,$revision],$changed)!==true || $changed!==1) throw new RuntimeException('crypto_policy_edit_conflict');
        // Dedicated already-migrated audit table: no lazy audit DDL inside this transaction.
        if (bc_q('INSERT INTO sas_crypto_v2_policy_audit(actor_role,actor_id,vendor_id,environment,policy_key,revision,before_document,after_document) VALUES(?,?,?,?,?,?,?,?)',[$authority['role'],$authority['id'],$scope,$env,$key,$revision+1,$before['document'],$json])!==true) throw new RuntimeException('crypto_policy_audit_failed');
        if (!mysqli_commit($connection_server)) throw new RuntimeException('crypto_policy_commit_failed');
        return $revision+1;
    } catch(Throwable $e) {mysqli_rollback($connection_server);throw $e;}
}
