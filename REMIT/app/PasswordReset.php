<?php
declare(strict_types=1);
final class PasswordReset
{
    public const MESSAGE='If an eligible account exists, a reset code will be emailed. Check your inbox and spam folder. Codes expire in 10 minutes; please wait before requesting another.';
    public static function ready(PDO $db): bool { return setting($db,'password_reset_schema')==='1'; }
    public static function enabled(PDO $db): bool { return self::ready($db) && (bool)filter_var(setting($db,'password_reset_sender'),FILTER_VALIDATE_EMAIL); }
    public static function migrate(PDO $db): void
    {
        if((int)$db->query("SELECT GET_LOCK('remit_password_reset_migration',5)")->fetchColumn()!==1)throw new RuntimeException('Another upgrade is running. Try again shortly.');
        try{
            $db->exec("CREATE TABLE IF NOT EXISTS password_resets (email VARCHAR(150) PRIMARY KEY,user_id INT NULL,request_key CHAR(32) NOT NULL,code_hash VARCHAR(255) NULL,password_stamp CHAR(64) NULL,expires_at DATETIME NOT NULL,sent_at DATETIME NOT NULL,attempts INT NOT NULL DEFAULT 0,INDEX(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("CREATE TABLE IF NOT EXISTS password_reset_limits (bucket_key CHAR(64) PRIMARY KEY,window_started DATETIME NOT NULL,hits INT NOT NULL DEFAULT 0,INDEX(window_started)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("INSERT INTO security_settings(setting_key,setting_value) VALUES('password_reset_schema','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        }finally{$db->query("SELECT RELEASE_LOCK('remit_password_reset_migration')");}
    }
    /** Called only inside the caller's transaction; row lock serializes concurrent requests. */
    private static function limit(PDO $db,string $scope,int $max,int $now): bool
    {
        $key=hash('sha256',$scope);$date=date('Y-m-d H:i:s',$now);
        $db->prepare('INSERT IGNORE INTO password_reset_limits(bucket_key,window_started,hits) VALUES(?,?,0)')->execute([$key,$date]);
        $s=$db->prepare('SELECT * FROM password_reset_limits WHERE bucket_key=? FOR UPDATE');$s->execute([$key]);$row=$s->fetch();
        $hits=strtotime($row['window_started'])<=$now-900?0:(int)$row['hits'];$start=$hits===0?$date:$row['window_started'];
        $db->prepare('UPDATE password_reset_limits SET hits=?,window_started=? WHERE bucket_key=?')->execute([min($hits+1,$max+1),$start,$key]);return $hits<$max;
    }
    public static function email(mixed $value): string
    {
        return is_string($value)&&strlen($value)<=150?strtolower(trim($value)):'';
    }
    /** The optional transport is a test seam, never supplied from HTTP input. No OTP enters the outbox/logs. */
    public static function request(PDO $db,string $email,?Closure $transport=null,?int $clock=null): void
    {
        if(!self::enabled($db)||!filter_var($email,FILTER_VALIDATE_EMAIL))return;
        $now=$clock??time();$code=(string)random_int(100000,999999);$hash=password_hash($code,PASSWORD_DEFAULT);$key=bin2hex(random_bytes(16));$send=false;
        $db->beginTransaction();
        try{
            $ipAllowed=self::limit($db,'request-ip:'.Security::ip(),5,$now);
            // Bound allocations under IP floods before creating per-address rows.
            if(!$ipAllowed){$db->commit();return;}
            $accountAllowed=self::limit($db,'request-email:'.$email,3,$now);
            if(!$accountAllowed){$db->commit();return;}
            $date=date('Y-m-d H:i:s',$now);$expiry=date('Y-m-d H:i:s',$now+600);
            $db->prepare('INSERT IGNORE INTO password_resets(email,request_key,expires_at,sent_at) VALUES(?,?,?,?)')->execute([$email,$key,$expiry,date('Y-m-d H:i:s',$now-61)]);
            $s=$db->prepare('SELECT * FROM password_resets WHERE email=? FOR UPDATE');$s->execute([$email]);$row=$s->fetch();
            if(strtotime($row['sent_at'])>$now-60){$db->commit();return;}
            $s=$db->prepare('SELECT id,password_hash,is_suspended FROM users WHERE email=?');$s->execute([$email]);$user=$s->fetch();$send=$user&&!$user['is_suspended'];
            $db->prepare('UPDATE password_resets SET user_id=?,request_key=?,code_hash=?,password_stamp=?,expires_at=?,sent_at=?,attempts=0 WHERE email=?')->execute([$send?$user['id']:null,$key,$send?$hash:null,$send?hash('sha256',$user['password_hash']):null,$expiry,$date,$email]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        if(!$send)return;
        $body="Your REMIT password reset code is: $code\n\nThis code expires in 10 minutes and can be used once. Enter it on the REMIT password reset page where you made this request. Never share this code.\n\nIf you did not request this, ignore this email. Your password has not changed.";
        try{$sent=$transport?$transport($email,'REMIT password reset code',$body):self::deliver($db,$email,'REMIT password reset code',$body);}catch(Throwable $e){$sent=false;}
        if(!$sent){
            $db->prepare('UPDATE password_resets SET code_hash=NULL WHERE email=? AND request_key=?')->execute([$email,$key]);
            error_log('REMIT password reset mail was not accepted by the mail transport. Check server mail configuration.');
        }
    }
    private static function deliver(PDO $db,string $to,string $subject,string $body): bool
    {
        $from=setting($db,'password_reset_sender');
        if(strlen($from)>150||str_contains($from,"\r")||str_contains($from,"\n")||!filter_var($from,FILTER_VALIDATE_EMAIL))return false;
        return @mail($to,$subject,$body,['From'=>$from,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8']);
    }
    public static function complete(PDO $db,string $email,string $code,string $password,string $confirmation,?int $clock=null): bool
    {
        if(!self::enabled($db)||!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
        if(strlen($password)<12||strlen($password)>72||str_contains($password,"\0")||$password!==$confirmation)throw new InvalidArgumentException('Use a 12–72 byte password and enter the same password in both fields.');
        $now=$clock??time();$newHash=password_hash($password,PASSWORD_DEFAULT);
        $db->beginTransaction();
        try{
            if(!self::limit($db,'verify-ip:'.Security::ip(),30,$now)){$db->commit();return false;}
            $s=$db->prepare('SELECT * FROM password_resets WHERE email=? FOR UPDATE');$s->execute([$email]);$reset=$s->fetch();
            if(!$reset||!$reset['code_hash']||strtotime($reset['expires_at'])<=$now||(int)$reset['attempts']>=5){$db->commit();return false;}
            $db->prepare('UPDATE password_resets SET attempts=attempts+1 WHERE email=?')->execute([$email]);
            if(!preg_match('/^[0-9]{6}$/D',$code)||!password_verify($code,$reset['code_hash'])){$db->commit();return false;}
            $s=$db->prepare('SELECT id,email,password_hash,is_suspended FROM users WHERE id=? FOR UPDATE');$s->execute([$reset['user_id']]);$user=$s->fetch();
            if(!$user||$user['is_suspended']||self::email($user['email'])!==$email||!hash_equals($reset['password_stamp'],hash('sha256',$user['password_hash']))){$db->prepare('UPDATE password_resets SET code_hash=NULL WHERE email=?')->execute([$email]);$db->commit();return false;}
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$newHash,$user['id']]);
            $db->prepare('UPDATE password_resets SET code_hash=NULL,password_stamp=NULL WHERE email=?')->execute([$email]);
            audit($db,'password_reset',$email,'Password reset by email OTP; existing sessions invalidated.');
            $db->commit();return true;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
