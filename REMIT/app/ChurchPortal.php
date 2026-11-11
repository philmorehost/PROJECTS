<?php
declare(strict_types=1);

final class ChurchPortal
{
    public static function ready(PDO $db): bool { return setting($db,'church_portal_schema')==='2'; }
    public static function officerRegistrationReady(PDO $db): bool { return self::ready($db) && setting($db,'officer_registration_schema')==='1'; }
    public static function migrate(PDO $db): void
    {
        $lock=$db->query("SELECT GET_LOCK('remit_church_portal_migration', 5)")->fetchColumn();
        if ((int)$lock!==1) throw new RuntimeException('Another schema upgrade is running. Retry shortly.');
        try {
            if(self::officerRegistrationReady($db))return;
            if(!self::ready($db))foreach(explode(';',file_get_contents(ROOT.'/database/migrations/002-church-portal.sql')) as $sql) if(trim($sql)!=='') $db->exec($sql);
            // DDL auto-commits. Column checks make interrupted upgrades safely resumable.
            $columns=$db->query('SHOW COLUMNS FROM church_registrations')->fetchAll(PDO::FETCH_COLUMN);
            $db->exec('ALTER TABLE church_registrations MODIFY parish_id INT NULL');
            if(!in_array('requested_church_name',$columns,true))$db->exec('ALTER TABLE church_registrations ADD requested_church_name VARCHAR(150) NULL');
            if(!in_array('requested_church_type',$columns,true))$db->exec("ALTER TABLE church_registrations ADD requested_church_type ENUM('parish','area','zone') NULL");
            $db->exec("INSERT INTO security_settings(setting_key,setting_value) VALUES ('officer_registration_schema','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        }
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
    /** Public discovery data deliberately excludes contacts, officers and bank details. */
    public static function directory(PDO $db): array
    {
        return array_map([ChurchNames::class,'labels'],$db->query("SELECT h.id,h.node_name,h.node_type,h.parish_code,p.node_name parent_name,z.node_name zone_name FROM church_hierarchy h LEFT JOIN church_hierarchy p ON h.parent_id=p.id LEFT JOIN church_hierarchy z ON p.parent_id=z.id WHERE h.node_type IN ('parish','area','zone') ORDER BY h.node_name,h.id")->fetchAll());
    }
    public static function nameKey(string $name): string
    {
        return ChurchNames::upper(preg_replace('/[^\p{L}\p{N}]+/u','',trim($name)) ?? '');
    }
    public static function resolveChurch(PDO $db,array $input): array
    {
        $id=filter_var($input['parish_id'] ?? 0,FILTER_VALIDATE_INT);
        if($id && $id>0 && ($input['church_unlisted'] ?? '')!=='1'){
            $s=$db->prepare("SELECT id,node_type FROM church_hierarchy WHERE id=? AND node_type IN ('parish','area','zone')");$s->execute([$id]);
            if($unit=$s->fetch())return [(int)$unit['id'],null,null];
            throw new InvalidArgumentException('Select a church that is still in the directory.');
        }
        if(($input['church_unlisted'] ?? '')!=='1')throw new InvalidArgumentException('Select your Parish, Area or Zone from the directory, or choose church not listed.');
        $raw=is_string($input['church_name'] ?? null)?trim($input['church_name']):'';
        if($raw==='' || strlen($raw)>150 || preg_match('//u',$raw)!==1 || preg_match('/[\x00-\x1f\x7f]/',$raw))throw new InvalidArgumentException('Enter a church name of up to 150 bytes.');
        $key=self::nameKey($raw);if($key==='' || !preg_match('/\p{L}/u',$key))throw new InvalidArgumentException('Enter a valid church name.');
        $matches=[];
        foreach(self::directory($db) as $unit)if(preg_match('/^'.preg_quote($key,'/').'$/iu',self::nameKey($unit['node_name']))===1)$matches[]=$unit;
        if(count($matches)===1)return [(int)$matches[0]['id'],null,null];
        if(count($matches)>1)throw new InvalidArgumentException('More than one church has this name. Select the correct level and parent from the directory.');
        $name=ChurchNames::normalize($raw);
        if(preg_match('/\p{Ll}/u',$name))throw new InvalidArgumentException('Enter the unlisted church name in CAPS.');
        $type=$input['church_type'] ?? '';if(!in_array($type,['parish','area','zone'],true))throw new InvalidArgumentException('Choose whether the unlisted church is a Parish, Area or Zone.');
        return [null,$name,$type];
    }
    public static function register(PDO $db,array $input): void
    {
        if(!self::officerRegistrationReady($db) || setting($db,'registration_enabled')!=='1') throw new RuntimeException('Church registration is not available. Contact the provincial administrator.');
        // Count every attempt, including invalid forms, before password hashing or account queries.
        $db->beginTransaction();
        try {
            $ip=Security::ip();$db->prepare('INSERT IGNORE INTO registration_limits(ip_address,window_started) VALUES(?,NOW())')->execute([$ip]);
            $s=$db->prepare('SELECT * FROM registration_limits WHERE ip_address=? FOR UPDATE');$s->execute([$ip]);$limit=$s->fetch();
            if(strtotime($limit['window_started'])<time()-3600){$db->prepare('UPDATE registration_limits SET attempts=0,window_started=NOW() WHERE ip_address=?')->execute([$ip]);$limit['attempts']=0;}
            if((int)$limit['attempts']>=30){$db->commit();throw new RuntimeException('Too many registration attempts. Please try again in an hour.');}
            $address=is_string($input['email']??null)?strtolower(trim($input['email'])):'';
            if(filter_var($address,FILTER_VALIDATE_EMAIL)&&strlen($address)<=150){
                $db->query("SELECT id FROM security_settings WHERE setting_key='site_title' FOR UPDATE")->fetch();
                $s=$db->prepare("SELECT COUNT(*) FROM security_audit_logs WHERE event_type='registration_attempt' AND username_attempted=? AND created_at>=?");$s->execute([$address,date('Y-m-d H:i:s',time()-3600)]);
                if((int)$s->fetchColumn()>=5){$db->commit();throw new RuntimeException('Too many attempts for this email. Please try again in an hour.');}
                audit($db,'registration_attempt',$address,'Public officer registration attempt.');
            }
            $db->prepare('UPDATE registration_limits SET attempts=attempts+1 WHERE ip_address=?')->execute([$ip]);$db->commit();
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        foreach(['full_name','email','phone','password','password_confirm'] as $field)if(!is_string($input[$field] ?? null))throw new InvalidArgumentException('Provide valid officer account details.');
        $name=trim($input['full_name'] ?? '');$email=strtolower(trim($input['email'] ?? ''));$phone=trim($input['phone'] ?? '');$password=$input['password'] ?? '';
        if($name==='' || strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>150 || !preg_match('/^[+0-9 ()-]{7,25}$/D',$phone) || strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0") || $password!==($input['password_confirm'] ?? '')) throw new InvalidArgumentException('Check your name, email and phone. Use a 12–72 byte password and matching confirmation.');
        // No public request ever creates a church. Resolve exact normalized names server-side,
        // even if JavaScript was bypassed; ambiguous/fuzzy names require a human choice.
        [$unit,$requested,$type]=self::resolveChurch($db,$input);
        $s=$db->prepare('SELECT id FROM users WHERE email=?');$s->execute([$email]);if($s->fetchColumn())return;
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT * FROM church_registrations WHERE email=? FOR UPDATE');$s->execute([$email]);$existing=$s->fetch();
            $s=$db->prepare('SELECT id FROM users WHERE email=?');$s->execute([$email]);if($s->fetchColumn()){$db->commit();return;}
            if($existing && $existing['status']==='pending'){$db->commit();return;}
            $values=[$unit,$requested,$type,$name,$email,$phone,password_hash($password,PASSWORD_DEFAULT),'','','',''];
            if($existing){
                $db->prepare("UPDATE church_registrations SET parish_id=?,requested_church_name=?,requested_church_type=?,full_name=?,email=?,phone=?,password_hash=?,bank_name=?,account_name=?,account_ciphertext=?,account_last4=?,status='pending',reviewed_by=NULL WHERE id=?")->execute([...$values,$existing['id']]);
                audit($db,'registration_reapplied',$email,json_encode(['application'=>$existing['id'],'previous_status'=>$existing['status'],'previous_reviewer'=>$existing['reviewed_by']]));
            }else {$db->prepare('INSERT INTO church_registrations(parish_id,requested_church_name,requested_church_type,full_name,email,phone,password_hash,bank_name,account_name,account_ciphertext,account_last4) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute($values);}
            $applicationId=$existing?(int)$existing['id']:(int)$db->lastInsertId();
            ApprovalNotifications::queue($db,$applicationId);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}

    }
    public static function reviewRegistration(PDO $db,array $admin,int $id,string $decision,int $resolvedUnit=0): string
    {
        requireRole($admin,['provincial_admin']);
        if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Invalid review decision.');
        $db->beginTransaction();
        try {
            $db->query("SELECT id FROM security_settings WHERE setting_key='site_title' FOR UPDATE")->fetch();
            $s=$db->prepare('SELECT * FROM church_registrations WHERE id=? FOR UPDATE');$s->execute([$id]);$row=$s->fetch();
            if(!$row || $row['status']!=='pending')throw new RuntimeException('This registration has already been reviewed or is unavailable.');
            if($decision==='approved') {
                $unit=(int)($row['parish_id'] ?? $resolvedUnit);
                $s=$db->prepare('SELECT node_type FROM church_hierarchy WHERE id=?');$s->execute([$unit]);$level=$s->fetchColumn();
                $role=match($level){'parish'=>'parish_officer','area'=>'area_rep','zone'=>'zonal_rep',default=>throw new RuntimeException('Link this application to an existing Parish, Area or Zone before approval. Add any genuinely missing church using the directory tools first.')};
                $db->prepare('UPDATE church_registrations SET parish_id=? WHERE id=?')->execute([$unit,$id]);
                $db->prepare('INSERT INTO users(hierarchy_id,full_name,email,password_hash,role) VALUES(?,?,?,?,?)')->execute([$unit,$row['full_name'],$row['email'],$row['password_hash'],$role]);
                $uid=$db->lastInsertId();
                $db->prepare('INSERT INTO officer_profiles(user_id,phone,bank_name,account_name,account_ciphertext,account_last4) VALUES(?,?,?,?,?,?)')->execute([$uid,$row['phone'],$row['bank_name'],$row['account_name'],$row['account_ciphertext'],$row['account_last4']]);
            }
            $db->prepare('UPDATE church_registrations SET status=?,reviewed_by=?,password_hash=NULL WHERE id=?')->execute([$decision,$admin['id'],$id]);
            notify($db,$row['email'],'Officer registration '.$decision,$decision==='approved'?'Your registration has been approved. Sign in using the password you chose.':'Your registration was not approved. Please contact the provincial administrator.');
            $db->commit();return 'Registration '.$decision.'. Notification queued.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function submitPayment(PDO $db,array $user,array $input,?array $upload=null): string
    {
        $ownUnit=remittingUnit($db,$user);
        if(setting($db,'provincial_account_number')==='')throw new RuntimeException('The province has not configured its receiving bank account.');
        $amount=Money::cents(trim($input['amount'] ?? ''));$ref=trim($input['transfer_reference'] ?? '');$date=$input['transfer_date'] ?? '';
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if($amount<=0 || $ref==='' || strlen($ref)>100 || !$dt || $dt->format('Y-m-d')!==$date || $date>date('Y-m-d') || $date<'2020-01-01')throw new InvalidArgumentException('Provide a positive transferred amount, bank reference and valid transfer date (not in the future).');
        $proof=null;$hasUpload=$upload!==null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
        if($hasUpload && !PaymentProof::ready($db))throw new RuntimeException('The administrator must enable receipt uploads in Portal settings.');
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT * FROM remittance_ledgers WHERE id=? AND parish_id=? FOR UPDATE');$s->execute([(int)($input['ledger_id'] ?? 0),$ownUnit]);$row=$s->fetch();
            if(!$row)throw new RuntimeException('Report does not belong to your church.');
            $s=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_notifications WHERE ledger_id=? AND status='pending'");$s->execute([$row['id']]);$pending=Money::cents((string)$s->fetchColumn());
            if($amount+$pending>Money::cents($row['provincial_levy_due'])-Money::cents($row['amount_paid']))throw new RuntimeException('Amount exceeds the outstanding balance after pending notifications.');
            if($hasUpload)$proof=PaymentProof::store($upload);
            $db->prepare('INSERT INTO payment_notifications(ledger_id,submitted_by,amount,transfer_reference,transfer_date) VALUES(?,?,?,?,?)')->execute([$row['id'],$user['id'],Money::decimal($amount),$ref,$date]);
            if($proof){$notificationId=(int)$db->lastInsertId();$db->prepare('INSERT INTO payment_receipts(notification_id,proof_key,byte_size,thumb_bytes,width,height) VALUES(?,?,?,?,?,?)')->execute([$notificationId,$proof['proof_key'],$proof['byte_size'],$proof['thumb_bytes'],$proof['width'],$proof['height']]);}
            notify($db,setting($db,'notification_email'),'Payment notification pending review',$row['payment_reference'].' has a new manual transfer notification. Verify against your bank statement before approval.');
            $db->commit();return 'Payment submitted. Awaiting treasury confirmation.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($proof)PaymentProof::remove($proof['proof_key']);throw $e;}
    }
    public static function deletePendingReports(PDO $db,array $user,array $input): int
    {
        requireRole($user,['provincial_admin','provincial_treasurer']);
        $ids=array_values(array_unique(array_map('intval',(array)($input['report_ids']??[]))));
        if(!$ids)throw new InvalidArgumentException('Select at least one pending remittance to delete.');
        $marks=implode(',',array_fill(0,count($ids),'?'));$params=$ids;
        $check=$db->prepare("SELECT COUNT(*) FROM remittance_ledgers WHERE id IN ($marks) AND status='pending'");$check->execute($params);if((int)$check->fetchColumn()!==count($ids))throw new RuntimeException('Only pending remittances can be deleted. Refresh and try again.');
        $db->beginTransaction();
        try{
            if(PaymentProof::ready($db)){
              $proofs=$db->prepare("SELECT r.proof_key FROM payment_receipts r JOIN payment_notifications n ON n.id=r.notification_id WHERE n.ledger_id IN ($marks)");$proofs->execute($params);
              foreach($proofs->fetchAll(PDO::FETCH_COLUMN) as $key)PaymentProof::remove($key);
              $db->prepare("DELETE FROM payment_receipts WHERE notification_id IN (SELECT id FROM payment_notifications WHERE ledger_id IN ($marks))")->execute($params);
            }
            $db->prepare("DELETE FROM payment_notifications WHERE ledger_id IN ($marks)")->execute($params);
            $db->prepare("DELETE FROM report_allocations WHERE ledger_id IN ($marks)")->execute($params);
            $db->prepare("DELETE FROM remittance_ledgers WHERE id IN ($marks)")->execute($params);
            audit($db,'remittance_reports_deleted',$user['email'],json_encode(['ids'=>$ids,'actor'=>$user['id']]));
            $db->commit();return count($ids);
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function reviewPayment(PDO $db,array $user,array $input): string
    {
        $auto=($input['auto']??'')==='1';
        if(!$auto)requireRole($user,['provincial_admin','provincial_treasurer']);
        else requireRole($user,['provincial_admin','provincial_treasurer','parish_officer','area_rep','zonal_rep']);
        $decision=$input['decision'] ?? '';if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Invalid review decision.');
        $db->beginTransaction();
        try{
            $s=$db->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$s->execute([$user['id']]);$fresh=$s->fetch();
            if(!$auto){
              if(!Security::verifyPin($db,$fresh,(string)($input['pin'] ?? ''))){$db->commit();throw new RuntimeException('PIN invalid or temporarily locked.');}
            }else{
              if(!in_array($fresh['role'],['provincial_admin','provincial_treasurer'],true))throw new RuntimeException('Automatic PayHub approval requires an administrator reviewer.');
            }
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
