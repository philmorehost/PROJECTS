<?php
/** Local-only paid entitlements: wallet, receipt, replay record and grant commit together.
 * No DDL, remote calls, nested transactions or asynchronous grants.
 */
require_once __DIR__.'/bc-fintech-api-access.php';
function bc_paid_money($value) {
    if(!is_string($value) && !is_int($value))throw new RuntimeException('invalid_price');
    if(!preg_match('/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/D',(string)$value))throw new RuntimeException('invalid_price');
    $p=explode('.',(string)$value);return (string)(int)$p[0].'.'.str_pad($p[1]??'',2,'0');
}
function bc_paid_query($db,$sql) {
    $q=mysqli_query($db,$sql);if($q===false)throw new RuntimeException('storage_failed');return $q;
}
function bc_paid_option($db,$table,$name,$vendor=null,$default='') {
    if(!in_array($table,['sas_settings','sas_super_admin_options'],true))throw new RuntimeException('invalid_table');
    $col=$table==='sas_settings'?'setting':'option';$name=mysqli_real_escape_string($db,$name);
    $where=$vendor===null?'':' AND vendor_id='.(int)$vendor;
    $q=bc_paid_query($db,"SELECT {$col}_value AS value FROM $table WHERE {$col}_name='$name'$where LIMIT 2 FOR UPDATE");
    if(mysqli_num_rows($q)>1)throw new RuntimeException('duplicate_option');
    $r=mysqli_fetch_assoc($q);return $r?(string)$r['value']:$default;
}
/** Call only after purpose-bound CSRF + PIN/second-factor verification. */
function bc_paid_purchase($db,$vendor,$user_id,$product,$quote,$decide,$grant,$extra_tables=[]) {
    if(empty($GLOBALS['bc_paid_action_verified']))return 'verification_required';
    $vid=(int)$vendor;$uid=(int)$user_id;$is_vendor=$uid===0;
    if($vid<=0 || $uid<0)return 'invalid_account';
    $table=$is_vendor?'sas_vendors':'sas_users';$history=$is_vendor?'sas_vendor_transactions':'sas_transactions';
    $tables=array_merge([$table,$history,'sas_wallet_mutation_events'],$extra_tables);
    $begun=false;
    try {
        $quote=bc_paid_money($quote);
        $tx=mysqli_fetch_assoc(bc_paid_query($db,'SELECT @@session.autocommit AS auto_commit'));
        if((int)$tx['auto_commit']!==1 || mysqli_query($db,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==true)throw new RuntimeException('outer_transaction');
        foreach($tables as $t){
            if(!preg_match('/\Asas_[a-z_]+\z/D',$t))throw new RuntimeException('invalid_table');
            $q=bc_paid_query($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'");$r=mysqli_fetch_assoc($q);
            if(!$r || strtoupper($r['ENGINE'])!=='INNODB')throw new RuntimeException('transactional_schema_required');
        }
        if(!mysqli_begin_transaction($db))throw new RuntimeException('transaction_failed');$begun=true;
        // Lock vendor first in every path; restrictions cannot change during purchase.
        $vendor_row=mysqli_fetch_assoc(bc_paid_query($db,"SELECT * FROM sas_vendors WHERE id=$vid LIMIT 1 FOR UPDATE"));
        if(!$vendor_row || (int)$vendor_row['status']!==1 || !empty($vendor_row['is_blocked']) || !empty($vendor_row['force_password_change']))throw new RuntimeException('account_restricted');
        $where=$is_vendor?"id=$vid":"id=$uid AND vendor_id=$vid";
        $row=$is_vendor?$vendor_row:mysqli_fetch_assoc(bc_paid_query($db,"SELECT * FROM sas_users WHERE $where LIMIT 1 FOR UPDATE"));
        if(!$row || (int)$row['status']!==1 || !empty($row['is_blocked']) || !empty($row['force_password_change']))throw new RuntimeException('account_restricted');
        $auth=$GLOBALS['bc_paid_action_verified'];
        if(!is_array($auth) || !isset($auth['actor']))throw new RuntimeException('verification_required');
        $identity=$auth['role']==='admin'?$vendor_row:$row;
        if(!in_array($auth['role'],['admin','user'],true) || ($auth['role']==='user' && ($is_vendor || (int)$auth['actor']['id']!==$uid || (int)$auth['actor']['vendor_id']!==$vid)) || ($auth['role']==='admin' && (int)$auth['actor']['id']!==$vid))throw new RuntimeException('verification_required');
        foreach(['password','security_pin','transaction_pin','totp_secret','two_factor_type'] as $field)if(($identity[$field]??'')!==($auth['actor'][$field]??''))throw new RuntimeException('identity_changed');
        $price=$decide($db,$row); // reads current price/state under the same transaction
        if($price===null){mysqli_rollback($db);return 'already_active';}
        $price=bc_paid_money($price);
        if($price!==$quote)throw new RuntimeException('price_changed');
        if(!$is_vendor && !empty($row['tx_lock_reason']))throw new RuntimeException('security_hold');
        $esc=function($v)use($db){return mysqli_real_escape_string($db,(string)$v);};
        $ref='ENT_'.bin2hex(random_bytes(16));$actor=$is_vendor?'vendor':'user:'.strtolower($row['username']);
        $key=hash('sha256',json_encode([$vid,$actor,'debit','reference:'.$ref]));
        $fingerprint=hash('sha256',json_encode([$vid,$actor,'debit',$price,$product]));
        $before=(string)$row['balance'];
        // Zero-price upgrades still have a receipt/event; they never invoke positive-only billing.
        if($price!=='0.00'){
            bc_paid_query($db,"UPDATE $table SET balance=balance-$price WHERE $where AND balance >= $price");
            if(mysqli_affected_rows($db)!==1)throw new RuntimeException('insufficient_balance');
        }
        $after=mysqli_fetch_assoc(bc_paid_query($db,"SELECT balance FROM $table WHERE $where LIMIT 1"))['balance'];
        $label=$is_vendor?'API Access':'Account Upgrade';
        $fields='vendor_id,product_unique_id,type_alternative,reference,amount,discounted_amount,balance_before,balance_after,description,api_website,status';
        $values="$vid,'".$esc($product)."','$label','$ref','$price','$price','".$esc($before)."','".$esc($after)."','".$esc($label.' - '.$product)."','LOCAL',1";
        if(!$is_vendor){$fields.=',username,api_reference,mode';$values.=",'".$esc($row['username'])."','','WEB'";}
        bc_paid_query($db,"INSERT INTO $history ($fields) VALUES ($values)");
        bc_paid_query($db,"INSERT INTO sas_wallet_mutation_events (event_key,payload_hash,vendor_id,actor,direction,reference,amount) VALUES ('$key','$fingerprint',$vid,'".$esc($actor)."','debit','$ref','$price')");
        $grant($db,$row,$ref);
        if(!mysqli_commit($db))throw new RuntimeException('commit_failed');
        return 'success';
    }catch(Throwable $e){
        if($begun)mysqli_rollback($db);
        return in_array($e->getMessage(),['price_changed','insufficient_balance','account_restricted','security_hold'],true)?$e->getMessage():'unavailable';
    }
}
function bc_paid_module_purchase($db,$vendor,$slug,$quote) {
    $providers=bc_fintech_api_providers();if(!isset($providers[$slug]))return 'invalid_module';
    if(!bc_is_saas_edition())return 'already_active';
    $vid=(int)$vendor;
    return bc_paid_purchase($db,$vid,0,'fintech_api_'.$slug,$quote,function($db,$row)use($slug,$vid,$providers){
        if(bc_paid_option($db,'sas_settings','fintech_api_access_'.$slug,$vid)!=='')return null;
        return bc_paid_option($db,'sas_super_admin_options','fintech_api_price_'.$slug,null,(string)$providers[$slug]['default_price']);
    },function($db,$row,$ref)use($slug,$vid){
        $raw=date('c').'|'.$ref;$value=mysqli_real_escape_string($db,$raw);$name='fintech_api_access_'.$slug;
        bc_paid_query($db,"INSERT INTO sas_settings (vendor_id,setting_name,setting_value) VALUES ($vid,'$name','$value') ON DUPLICATE KEY UPDATE setting_value='$value'");
        if(!hash_equals($raw,bc_paid_option($db,'sas_settings',$name,$vid)))throw new RuntimeException('grant_unconfirmed');
    },['sas_vendors','sas_settings','sas_super_admin_options']);
}
function bc_paid_level_change($db,$vendor,$uid,$level,$quote,$expected_level) {
    if((!is_string($expected_level) && !is_int($expected_level)) || !preg_match('/\A[0-3]\z/D',(string)$expected_level))return 'invalid_level';
    $levels=['smart'=>1,'agent'=>2,'api'=>3];if(!isset($levels[$level]))return 'invalid_level';$target=$levels[$level];$vid=(int)$vendor;$uid=(int)$uid;
    if($uid<=0)return 'invalid_account';
    return bc_paid_purchase($db,$vid,$uid,'pricing_level_'.$target,$quote,function($db,$row)use($vid,$target,$expected_level){
        if((int)$row['account_level']===$target)return null;
        if((int)$row['account_level']!==(int)$expected_level)throw new RuntimeException('price_changed');
        // Never promote account_type/API eligibility. Business approval remains a separate KYB flow.
        if(!in_array((string)$row['account_type'],['personal','business'],true))throw new RuntimeException('account_restricted');
        if($target===1 || $target<(int)$row['account_level'])return '0.00';
        $q=bc_paid_query($db,"SELECT price FROM sas_user_upgrade_price WHERE vendor_id=$vid AND account_type='$target' LIMIT 2 FOR UPDATE");
        if(mysqli_num_rows($q)!==1)throw new RuntimeException('price_unavailable');
        return (string)mysqli_fetch_assoc($q)['price'];
    },function($db,$row,$ref)use($vid,$uid,$target){
        bc_paid_query($db,"UPDATE sas_users SET account_level=$target WHERE vendor_id=$vid AND id=$uid");
        if(mysqli_affected_rows($db)!==1)throw new RuntimeException('grant_failed');
    },['sas_vendors','sas_user_upgrade_price']);
}
