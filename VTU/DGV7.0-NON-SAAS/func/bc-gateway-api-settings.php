<?php
require_once __DIR__.'/bc-paid-entitlements.php';
function bc_gateway_api_entitled($db,$vid,$slug) {
    $providers=bc_fintech_api_providers();if(!isset($providers[$slug]) || (int)$vid<=0)return false;
    if(!bc_is_saas_edition())return true;
    $price=bc_paid_money(bc_paid_option($db,'sas_super_admin_options','fintech_api_price_'.$slug,null,(string)$providers[$slug]['default_price']));
    return $price==='0.00' || bc_paid_option($db,'sas_settings','fintech_api_access_'.$slug,(int)$vid)!=='';
}
function bc_gateway_api_read($db,$vid,$slug) {
    if(!in_array($slug,['klasha'],true))throw new RuntimeException('invalid_gateway');
    if(!bc_gateway_api_entitled($db,$vid,$slug))throw new RuntimeException('purchase_required');
    $vid=(int)$vid;
    $q=bc_paid_query($db,"SELECT sealed_config FROM sas_gateway_api_credentials WHERE vendor_id=$vid AND gateway_slug='$slug' LIMIT 2");
    if(mysqli_num_rows($q)===0)return null;
    if(mysqli_num_rows($q)!==1 || !function_exists('bc_stepup_open_secret'))throw new RuntimeException('config_unavailable');
    $raw=bc_stepup_open_secret(mysqli_fetch_assoc($q)['sealed_config']);$doc=json_decode($raw,true);
    if(!is_array($doc) || ($doc['vendor_id']??0)!==$vid || ($doc['gateway']??'')!==$slug)throw new RuntimeException('config_unavailable');
    return $doc;
}
function bc_gateway_api_save($db,$actor,$slug,$input) {
    if(empty($GLOBALS['bc_paid_action_verified']))return 'verification_required';
    if(!in_array($slug,['klasha'],true))return 'invalid_gateway';
    $vid=(int)$actor['id'];$begun=false;
    try{
        if(empty($GLOBALS['mySqlPass']) || !function_exists('openssl_encrypt'))throw new RuntimeException('encryption_unavailable');
        $tx=mysqli_fetch_assoc(bc_paid_query($db,'SELECT @@session.autocommit AS auto_commit'));
        if((int)$tx['auto_commit']!==1 || mysqli_query($db,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==true)throw new RuntimeException('outer_transaction');
        foreach(['sas_vendors','sas_gateway_api_credentials','sas_super_admin_options','sas_settings'] as $t){
            $r=mysqli_fetch_assoc(bc_paid_query($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'"));
            if(!$r || strtoupper($r['ENGINE'])!=='INNODB')throw new RuntimeException('schema_unavailable');
        }
        if(!mysqli_begin_transaction($db))throw new RuntimeException('transaction_failed');$begun=true;
        $row=mysqli_fetch_assoc(bc_paid_query($db,"SELECT * FROM sas_vendors WHERE id=$vid LIMIT 1 FOR UPDATE"));
        if(!$row || (int)$row['status']!==1 || !empty($row['is_blocked']) || !empty($row['force_password_change']))throw new RuntimeException('restricted');
        if(function_exists('bc_enrollment_reason') && bc_enrollment_reason($row,'vendor',true)!=='')throw new RuntimeException('restricted');
        // Bind the action to the security identity that completed step-up.
        foreach(['security_pin','password','totp_secret','two_factor_type'] as $field)if(($row[$field]??'')!==($actor[$field]??''))throw new RuntimeException('identity_changed');
        $old=bc_gateway_api_read($db,$vid,$slug)??[];
        $doc=['vendor_id'=>$vid,'gateway'=>$slug];
        $fields=['public_key','secret_key','encrypt_key','login_email','business_id'];
        foreach($fields as $key){
            $value=$input['gateway_'.$key]??'';
            if(!is_string($value))throw new RuntimeException('invalid_fields');
            if(strlen($value)>2048 || preg_match('/[\x00-\x1f\x7f]/',$value))throw new RuntimeException('invalid_fields');
            $doc[$key]=$value!==''?$value:(string)($old[$key]??'');
        }
        if($doc['public_key']==='' || $doc['secret_key']==='' || $doc['encrypt_key']==='' || !filter_var($doc['login_email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('invalid_fields');
        $sealed=bc_stepup_seal_secret(json_encode($doc,JSON_THROW_ON_ERROR));
        if(strpos($sealed,'gcm:')!==0)throw new RuntimeException('encryption_unavailable');
        $value=mysqli_real_escape_string($db,$sealed);
        bc_paid_query($db,"INSERT INTO sas_gateway_api_credentials (vendor_id,gateway_slug,sealed_config) VALUES ($vid,'$slug','$value') ON DUPLICATE KEY UPDATE sealed_config='$value',updated_at=CURRENT_TIMESTAMP");
        if(bc_gateway_api_read($db,$vid,$slug)!==$doc)throw new RuntimeException('save_unconfirmed');
        if(!mysqli_commit($db))throw new RuntimeException('commit_failed');
        return 'success';
    }catch(Throwable $e){if($begun)mysqli_rollback($db);return $e->getMessage()==='purchase_required'?'purchase_required':'unavailable';}
}
function bc_gateway_api_cards($db,$actor) {
    foreach(['klasha'=>'Klasha'] as $slug=>$label){
        $ready=false;$access=false;try{$access=bc_gateway_api_entitled($db,(int)$actor['id'],$slug);if($access)$ready=bc_gateway_api_read($db,(int)$actor['id'],$slug)!==null;}catch(Throwable $ignored){}
        ?>
        <div class="card p-4 mb-4" id="gateway-<?php echo $slug; ?>"><h5><?php echo $label; ?> API Settings</h5>
        <?php if (function_exists('bc_adm_render_api_info_box')) bc_adm_render_api_info_box('klasha-gateway'); ?>
        <p><?php echo $ready?'Encrypted configuration saved.':'No encrypted configuration saved.'; ?> Blank fields retain the previously saved values. Keys are never sent back to this form.</p>
        <p>Saving configuration does not enable withdrawals or bypass compliance, Service Control or financial holds. Crypto staging and live activation remain paused.</p>
        <?php if(!$access): ?><p>Purchase <?php echo $label; ?> access in <a href="#fintech-api-access">Fintech API Access</a> before saving keys.</p><?php else: ?>
        <form method="post" autocomplete="off" data-paid-save>
        <?php $fields=['public_key'=>'Merchant Public Key','secret_key'=>'Account Password / Token','encrypt_key'=>'Encryption Key','login_email'=>'Login Email','business_id'=>'Business ID (optional)']; ?>
        <?php foreach($fields as $key=>$title): ?><label class="d-block my-2"><?php echo $title; ?><input class="form-control" name="gateway_<?php echo $key; ?>" type="password" value="" autocomplete="new-password" maxlength="2048"></label><?php endforeach; ?>
        <?php bc_paid_fields($actor,'paid_gateway_'.$slug); ?>
        <button class="btn btn-primary" name="save-paid-gateway" value="<?php echo $slug; ?>">Save <?php echo $label; ?> API Settings</button>
        </form><?php endif; ?></div>
        <?php
    }
}
function bc_gateway_prices_save($db,$actor,$input) {
    if(empty($GLOBALS['bc_paid_action_verified']) || !bc_gauth_enrolled($actor))return false;
    $begun=false;
    try{
        $prices=[];foreach(bc_fintech_api_providers() as $slug=>$meta)$prices[$slug]=bc_paid_money($input['fintech_api_price_'.$slug]??'');
        $tx=mysqli_fetch_assoc(bc_paid_query($db,'SELECT @@session.autocommit AS auto_commit'));
        if((int)$tx['auto_commit']!==1 || mysqli_query($db,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==true)throw new RuntimeException('outer_transaction');
        foreach(['sas_super_admin','sas_super_admin_options'] as $t){$r=mysqli_fetch_assoc(bc_paid_query($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'"));if(!$r || strtoupper($r['ENGINE'])!=='INNODB')throw new RuntimeException('schema_unavailable');}
        if(!mysqli_begin_transaction($db))throw new RuntimeException('transaction_failed');$begun=true;$id=(int)$actor['id'];
        $row=mysqli_fetch_assoc(bc_paid_query($db,"SELECT * FROM sas_super_admin WHERE id=$id LIMIT 1 FOR UPDATE"));
        if(!$row || (int)$row['status']!==1 || !empty($row['is_blocked']) || !empty($row['force_password_change']))throw new RuntimeException('restricted');
        foreach(['security_pin','password','totp_secret','two_factor_type'] as $field)if(($row[$field]??'')!==($actor[$field]??''))throw new RuntimeException('identity_changed');
        foreach($prices as $slug=>$price){
            $name='fintech_api_price_'.$slug;
            bc_paid_option($db,'sas_super_admin_options',$name); // fail closed on legacy duplicates
            bc_paid_query($db,"INSERT INTO sas_super_admin_options (option_name,option_value) VALUES ('$name','$price') ON DUPLICATE KEY UPDATE option_value='$price'");
            if(bc_paid_option($db,'sas_super_admin_options',$name)!==$price)throw new RuntimeException('save_unconfirmed');
        }
        if(!mysqli_commit($db))throw new RuntimeException('commit_failed');
        unset($_SESSION['super_admin_options_cache']);return true;
    }catch(Throwable $e){if($begun)mysqli_rollback($db);return false;}
}
