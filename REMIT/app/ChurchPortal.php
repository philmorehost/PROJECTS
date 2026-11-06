<?php
declare(strict_types=1);

final class ChurchPortal
{
    public static function ready(PDO $db): bool { return setting($db,'church_portal_schema')==='2'; }
    public static function migrate(PDO $db): void
    {
        $lock=$db->query("SELECT GET_LOCK('remit_church_portal_migration', 5)")->fetchColumn();
        if ((int)$lock!==1) throw new RuntimeException('Another schema upgrade is running. Retry shortly.');
        try { foreach(explode(';',file_get_contents(ROOT.'/database/migrations/002-church-portal.sql')) as $sql) if(trim($sql)!=='') $db->exec($sql); }
        finally { $db->query("SELECT RELEASE_LOCK('remit_church_portal_migration')"); }
    }
    public static function percentage(string $value): int
    {
        $bp=Money::cents($value);
        if ($bp>10000) throw new InvalidArgumentException('The percentage must be between 0 and 100.');
        return $bp;
    }
    public static function split(int $gross,int $payableBp): array
    {
        $payable=Money::levy($gross,$payableBp);
        return ['total'=>$gross,'payable'=>$payable,'retained'=>$gross-$payable];
    }
    public static function bank(array $input): array
    {
        $bank=trim($input['bank_name'] ?? '');$name=trim($input['account_name'] ?? '');$number=trim($input['account_number'] ?? '');
        if($bank==='' || strlen($bank)>100 || $name==='' || strlen($name)>150 || !preg_match('/^\d{10}$/D',$number)) throw new InvalidArgumentException('Provide bank name, account name and a 10-digit Nigerian account number. Do not enter a PIN, card number or BVN.');
        return [$bank,$name,$number];
    }
    private static function key(bool $create = true): string
    {
        $file=ROOT.'/config/bank.key';
        if(!is_file($file) && !$create) throw new RuntimeException('The original bank encryption key is missing. Restore config/bank.key from your secure backup.');
        if(!is_file($file)) {
            $mutex=fopen(ROOT.'/config/bank-key.lock','c');
            if(!$mutex || !flock($mutex,LOCK_EX)) throw new RuntimeException('Cannot initialize account encryption.');
            try {
                if(!is_file($file)) {
                    $old=umask(0077);
                    try { if(file_put_contents($file,random_bytes(32),LOCK_EX)!==32) throw new RuntimeException('Cannot save account encryption key.'); }
                    finally { umask($old); }
                }
            } finally {flock($mutex,LOCK_UN);fclose($mutex);}
        }
        $key=file_get_contents($file);
        if(strlen($key)!==32) throw new RuntimeException('Account encryption key is invalid. Restore it from your secure backup.');
        return $key;
    }
    public static function encrypt(string $number): string
    {
        if(!extension_loaded('openssl')) throw new RuntimeException('Enable OpenSSL for bank account encryption.');
        $iv=random_bytes(12);$tag='';$body=openssl_encrypt($number,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'remit-bank-v1');
        if($body===false) throw new RuntimeException('Could not encrypt bank account details.');
        return base64_encode($iv.$tag.$body);
    }
    public static function decrypt(string $cipher): string
    {
        $raw=base64_decode($cipher,true);
        if($raw===false || strlen($raw)<29) throw new RuntimeException('Bank account data is invalid.');
        $number=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(false),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'remit-bank-v1');
        if($number===false) throw new RuntimeException('Bank account details could not be decrypted. Restore the original bank.key if this site was moved.');
        return $number;
    }
    public static function register(PDO $db,array $input): void
    {
        if(!self::ready($db) || setting($db,'registration_enabled')!=='1') throw new RuntimeException('Church registration is not available. Contact the provincial administrator.');
        // Count every attempt, including invalid forms, before password hashing or account queries.
        $db->beginTransaction();
        try {
            $ip=Security::ip();$db->prepare('INSERT IGNORE INTO registration_limits(ip_address,window_started) VALUES(?,NOW())')->execute([$ip]);
            $s=$db->prepare('SELECT * FROM registration_limits WHERE ip_address=? FOR UPDATE');$s->execute([$ip]);$limit=$s->fetch();
            if(strtotime($limit['window_started'])<time()-3600){$db->prepare('UPDATE registration_limits SET attempts=0,window_started=NOW() WHERE ip_address=?')->execute([$ip]);$limit['attempts']=0;}
            if((int)$limit['attempts']>=5){$db->commit();throw new RuntimeException('Too many registration attempts. Please try again in an hour.');}
            $db->prepare('UPDATE registration_limits SET attempts=attempts+1 WHERE ip_address=?')->execute([$ip]);$db->commit();
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        $name=trim($input['full_name'] ?? '');$email=strtolower(trim($input['email'] ?? ''));$phone=trim($input['phone'] ?? '');$password=$input['password'] ?? '';
        if($name==='' || strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>150 || !preg_match('/^[+0-9 ()-]{7,25}$/D',$phone) || strlen($password)<12 || strlen($password)>72 || $password!==($input['password_confirm'] ?? '')) throw new InvalidArgumentException('Check your name, email and phone. Use a 12–72 byte password and matching confirmation.');
        [$bank,$account,$number]=self::bank($input);
        $parish=(int)($input['parish_id'] ?? 0);$s=$db->prepare("SELECT id FROM church_hierarchy WHERE id=? AND node_type='parish'");$s->execute([$parish]);if(!$s->fetchColumn())throw new InvalidArgumentException('Select a church from the parish directory.');
        $s=$db->prepare('SELECT id FROM users WHERE email=?');$s->execute([$email]);if($s->fetchColumn())return;
        $s=$db->prepare('INSERT IGNORE INTO church_registrations(parish_id,full_name,email,phone,password_hash,bank_name,account_name,account_ciphertext,account_last4) VALUES(?,?,?,?,?,?,?,?,?)');
        $s->execute([$parish,$name,$email,$phone,password_hash($password,PASSWORD_DEFAULT),$bank,$account,self::encrypt($number),substr($number,-4)]);
        if($s->rowCount())notify($db,setting($db,'notification_email'),'Church registration awaiting review','A new parish officer registration is awaiting verification in the church directory.');
    }
    public static function reviewRegistration(PDO $db,array $admin,int $id,string $decision): string
    {
        requireRole($admin,['provincial_admin']);
        if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Invalid review decision.');
        $db->beginTransaction();
        try {
            $s=$db->prepare('SELECT * FROM church_registrations WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch();
            if(!$row || $row['status']!=='pending')throw new RuntimeException('This registration has already been reviewed or is unavailable.');
            if($decision==='approved') {
                $db->prepare("INSERT INTO users(hierarchy_id,full_name,email,password_hash,role) VALUES(?,?,?,?,'parish_officer')")->execute([$row['parish_id'],$row['full_name'],$row['email'],$row['password_hash']]);
                $uid=$db->lastInsertId();
                $db->prepare('INSERT INTO officer_profiles(user_id,phone,bank_name,account_name,account_ciphertext,account_last4) VALUES(?,?,?,?,?,?)')->execute([$uid,$row['phone'],$row['bank_name'],$row['account_name'],$row['account_ciphertext'],$row['account_last4']]);
            }
            $db->prepare('UPDATE church_registrations SET status=?,reviewed_by=?,password_hash=NULL WHERE id=?')->execute([$decision,$admin['id'],$id]);
            notify($db,$row['email'],'Church registration '.$decision,$decision==='approved'?'Your registration has been approved. Sign in using the password you chose.':'Your registration was not approved. Please contact the provincial administrator.');
            $db->commit();return 'Registration '.$decision.'. Notification queued.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function submitPayment(PDO $db,array $user,array $input): string
    {
        requireRole($user,['parish_officer']);
        if(setting($db,'provincial_account_number')==='')throw new RuntimeException('The province has not configured its receiving bank account.');
        $amount=Money::cents(trim($input['amount'] ?? ''));$ref=trim($input['transfer_reference'] ?? '');$date=$input['transfer_date'] ?? '';
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if($amount<=0 || $ref==='' || strlen($ref)>100 || !$dt || $dt->format('Y-m-d')!==$date || $date>date('Y-m-d') || $date<'2020-01-01')throw new InvalidArgumentException('Provide a positive transferred amount, bank reference and valid transfer date (not in the future).');
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT * FROM remittance_ledgers WHERE id=? AND parish_id=? FOR UPDATE');$s->execute([(int)($input['ledger_id'] ?? 0),$user['hierarchy_id']]);$row=$s->fetch();
            if(!$row)throw new RuntimeException('Report is not in your parish.');
            $s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_notifications WHERE ledger_id=? AND status='pending'");$s->execute([$row['id']]);$pending=Money::cents((string)$s->fetchColumn());
            if($amount+$pending>Money::cents($row['provincial_levy_due'])-Money::cents($row['amount_paid']))throw new RuntimeException('Amount exceeds the outstanding balance after pending notifications.');
            $db->prepare('INSERT INTO payment_notifications(ledger_id,submitted_by,amount,transfer_reference,transfer_date) VALUES(?,?,?,?,?)')->execute([$row['id'],$user['id'],Money::decimal($amount),$ref,$date]);
            notify($db,setting($db,'notification_email'),'Payment notification pending review',$row['payment_reference'].' has a new manual transfer notification. Verify against your bank statement before approval.');
            $db->commit();return 'Payment notification submitted. It remains pending until the treasurer verifies the bank deposit.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function reviewPayment(PDO $db,array $user,array $input): string
    {
        requireRole($user,['provincial_admin','provincial_treasurer']);$decision=$input['decision'] ?? '';if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Invalid review decision.');
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$s->execute([$user['id']]);$fresh=$s->fetch();
            if(!Security::verifyPin($db,$fresh,(string)($input['pin'] ?? ''))){$db->commit();throw new RuntimeException('PIN invalid or temporarily locked.');}
            $s=$db->prepare('SELECT * FROM payment_notifications WHERE id=? FOR UPDATE');$s->execute([(int)($input['notification_id'] ?? 0)]);$payment=$s->fetch();
            if(!$payment || $payment['status']!=='pending')throw new RuntimeException('Notification is no longer pending.');
            $s=$db->prepare('SELECT * FROM remittance_ledgers WHERE id=? FOR UPDATE');$s->execute([$payment['ledger_id']]);$row=$s->fetch();
            if($decision==='approved') {
                $total=Money::cents($row['amount_paid'])+Money::cents($payment['amount']);$due=Money::cents($row['provincial_levy_due']);
                if($total>$due)throw new RuntimeException('Payment would exceed the balance. Reject and resolve this notification instead.');
                $db->prepare('UPDATE remittance_ledgers SET amount_paid=?,status=? WHERE id=?')->execute([Money::decimal($total),$total===$due?'verified':'partial',$row['id']]);
                audit($db,'remittance_verified',$user['email'],json_encode(['notification'=>$payment['id'],'ledger'=>$row['id'],'amount'=>$payment['amount'],'actor'=>$user['id']]));
            }
            $note=trim($input['review_note'] ?? '');if(strlen($note)>255)throw new InvalidArgumentException('Review note is too long.');
            $db->prepare('UPDATE payment_notifications SET status=?,review_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?')->execute([$decision,$note,$user['id'],$payment['id']]);
            $s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$payment['submitted_by']]);notify($db,(string)$s->fetchColumn(),'Payment notification '.$decision,$row['payment_reference'].' — Your transfer notification was '.$decision.'. '.$note);
            $db->commit();return 'Payment notification '.$decision.'.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
