<?php
declare(strict_types=1);
final class ApprovalNotifications
{
    public const SUBJECT='Officer registration awaiting review';
    public const APPLICANT_SUBJECT='Your REMIT registration was received';

    private static function adminBody(int $id): array
    {
        $html='<p style="margin:0 0 16px">A new officer registration has been submitted and is now awaiting your approval before the representative can sign in.</p><table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 16px;border-collapse:separate;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;overflow:hidden"><tr><td style="padding:16px 20px"><p style="margin:0;font:700 12px/1.2 Inter,Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#2563eb">Pending review</p><p style="margin:6px 0 0;font:700 22px/1.2 Inter,Arial,sans-serif;color:#0f172a">Application #'.$id.'</p></td></tr></table><p style="margin:0 0 12px">Please verify the officer’s identity, confirm the correct church or unit, and approve or reject the request.</p><p style="margin:0"><strong style="color:#0f172a">Important:</strong> no account is activated automatically until you complete this review.</p>';
        $text="A new officer registration has been submitted and is now awaiting your approval before the representative can sign in.\n\nPending review\nApplication #$id\n\nPlease verify the officer’s identity, confirm the correct church or unit, and approve or reject the request.\n\nImportant: no account is activated automatically until you complete this review.";
        return EmailTemplates::wrap('Pending approval','Officer registration awaiting review',$html,$text,'Open pending approvals','index.php?page=registrations');
    }
    private static function applicantBody(string $name,string $church): array
    {
        $name=trim($name)===''?'Officer':trim($name);$church=trim($church)===''?'your church':trim($church);
        $html='<p style="margin:0 0 16px">Dear '.htmlspecialchars($name,ENT_QUOTES,'UTF-8').',</p><p style="margin:0 0 16px">Your registration for <strong style="color:#0f172a">'.htmlspecialchars($church,ENT_QUOTES,'UTF-8').'</strong> was received successfully.</p><div style="margin:0 0 16px;padding:18px 20px;border-radius:16px;background:#eff6ff;border:1px solid #bfdbfe"><p style="margin:0;font:700 12px/1.2 Inter,Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#1d4ed8">Status</p><p style="margin:6px 0 0;font:700 18px/1.3 Inter,Arial,sans-serif;color:#0f172a">Awaiting provincial approval</p><p style="margin:10px 0 0;color:#1e3a8a">You cannot sign in until an administrator reviews and approves your request.</p></div><p style="margin:0 0 12px">What happens next:</p><ul style="margin:0 0 16px;padding-left:20px;color:#334155"><li style="margin:0 0 8px">Provincial admins review your church affiliation and contact details.</li><li style="margin:0 0 8px">If anything needs correction, an administrator will contact you.</li><li style="margin:0">When approved, you will be able to sign in with the email and password you created.</li></ul><p style="margin:0">Please do not create duplicate registrations while this request is pending.</p>';
        $text="Dear $name,\n\nYour registration for $church was received successfully.\n\nStatus: Awaiting provincial approval\nYou cannot sign in until an administrator reviews and approves your request.\n\nWhat happens next:\n- Provincial admins review your church affiliation and contact details.\n- If anything needs correction, an administrator will contact you.\n- When approved, you will be able to sign in with the email and password you created.\n\nPlease do not create duplicate registrations while this request is pending.";
        return EmailTemplates::wrap('Registration received','Your registration is successful — awaiting approval',$html,$text,'Back to sign in','index.php?page=login');
    }
    public static function queue(PDO $db,int $id): void
    {
        $recipients=$db->query("SELECT email FROM users WHERE role='provincial_admin' AND is_suspended=0")->fetchAll(PDO::FETCH_COLUMN);
        $recipients[]=setting($db,'notification_email');$unique=[];
        foreach($recipients as $email){$email=strtolower(trim((string)$email));if(filter_var($email,FILTER_VALIDATE_EMAIL)&&strlen($email)<=150)$unique[$email]=true;}
        $admin=self::adminBody($id);
        foreach(array_keys($unique) as $email)notify($db,$email,self::SUBJECT,$admin['text'],$admin['html']);
        $app=$db->prepare('SELECT full_name,email,requested_church_name FROM church_registrations WHERE id=?');$app->execute([$id]);$row=$app->fetch();
        if($row && filter_var($row['email'],FILTER_VALIDATE_EMAIL)){$conf=self::applicantBody((string)$row['full_name'],(string)$row['requested_church_name']);notify($db,(string)$row['email'],self::APPLICANT_SUBJECT,$conf['text'],$conf['html']);}
    }
    /** Serialized, bounded delivery. Mail acceptance is not proof of inbox delivery. */
    public static function dispatch(PDO $db,int $limit=5,?Closure $transport=null): int
    {
        if($db->inTransaction())throw new LogicException('Deliver approval mail only after committing the application.');
        ensureNotificationSchema($db);
        if((int)$db->query("SELECT GET_LOCK('remit_approval_mail',0)")->fetchColumn()!==1)return 0;
        $sent=0;
        try{
            $limit=max(1,min(25,$limit));
            $s=$db->prepare("SELECT * FROM notification_outbox WHERE subject IN (?,?) AND status='pending' AND attempts<3 ORDER BY id LIMIT $limit");$s->execute([self::SUBJECT,self::APPLICANT_SUBJECT]);$jobs=$s->fetchAll();
            foreach($jobs as $job){
                // Skip obsolete alerts: admin alerts if that app was reviewed; applicant confirmation always follows submitted pending app.
                if($job['subject']===self::SUBJECT && preg_match('/Application #([0-9]+)/',(string)$job['body_html'],$m)){
                  $check=$db->prepare("SELECT COUNT(*) FROM church_registrations WHERE id=? AND status='pending'");$check->execute([(int)$m[1]]);$pending=(int)$check->fetchColumn();
                } elseif($job['subject']===self::APPLICANT_SUBJECT && preg_match('/Your registration for (.+?) was received/',(string)$job['body_html'],$m)){
                  $check=$db->prepare("SELECT COUNT(*) FROM church_registrations WHERE email=? AND status IN ('pending','approved','rejected')");$check->execute([$job['recipient']]);$exists=(int)$check->fetchColumn();$pending=$exists?1:0;
                } else $pending=1;
                if(!$pending){$db->prepare("UPDATE notification_outbox SET status='failed' WHERE id=?")->execute([$job['id']]);continue;}
                $db->prepare('UPDATE notification_outbox SET attempts=attempts+1 WHERE id=?')->execute([$job['id']]);
                $ok=false;
                try{
                    $from=PasswordReset::sender($db);$html=(string)($job['body_html'] ?? '');
                    if($html===''){$headers=['From'=>$from,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8'];$body=$job['body'];}
                    else{$boundary='----=_Part_'.bin2hex(random_bytes(8));$nl="\r\n";$body='--'.$boundary.$nl.'Content-Type: text/plain; charset=UTF-8'.$nl.'Content-Transfer-Encoding: quoted-printable'.$nl.$nl.str_replace("\n",$nl,$job['body']).$nl.'--'.$boundary.$nl.'Content-Type: text/html; charset=UTF-8'.$nl.'Content-Transfer-Encoding: quoted-printable'.$nl.$nl.str_replace("\n",$nl,$html).$nl.'--'.$boundary.'--';$headers=['From'=>$from,'MIME-Version'=>'1.0','Content-Type'=>'multipart/alternative; boundary="'.$boundary.'"'];}
                    if(filter_var($job['recipient'],FILTER_VALIDATE_EMAIL)&&filter_var($from,FILTER_VALIDATE_EMAIL))$ok=$transport?(bool)$transport($job['recipient'],$job['subject'],$body,$from,$html):@mail($job['recipient'],$job['subject'],$body,$headers);
                }catch(Throwable $e){$ok=false;}
                $status=$ok?'sent':((int)$job['attempts']+1>=3?'failed':'pending');$db->prepare('UPDATE notification_outbox SET status=? WHERE id=?')->execute([$status,$job['id']]);if($ok)$sent++;
            }
            return $sent;
        }finally{$db->query("SELECT RELEASE_LOCK('remit_approval_mail')");}
    }
}
