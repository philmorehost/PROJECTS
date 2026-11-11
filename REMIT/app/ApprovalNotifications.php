<?php
declare(strict_types=1);
final class ApprovalNotifications
{
    public const SUBJECT='Officer registration awaiting review';
    public static function queue(PDO $db,int $id): void
    {
        $recipients=$db->query("SELECT email FROM users WHERE role='provincial_admin' AND is_suspended=0")->fetchAll(PDO::FETCH_COLUMN);
        $recipients[]=setting($db,'notification_email');$unique=[];
        foreach($recipients as $email){$email=strtolower(trim((string)$email));if(filter_var($email,FILTER_VALIDATE_EMAIL)&&strlen($email)<=150)$unique[$email]=true;}
        foreach(array_keys($unique) as $email)notify($db,$email,self::SUBJECT,"Registration #$id is awaiting church affiliation verification.\n\nSign in to REMIT, then open Church reps → Pending approvals. Verify the representative’s identity and church before approving. No account is activated automatically.");
    }
    /** Serialized, bounded delivery. Mail acceptance is not proof of inbox delivery. */
    public static function dispatch(PDO $db,int $limit=5,?Closure $transport=null): int
    {
        if($db->inTransaction())throw new LogicException('Deliver approval mail only after committing the application.');
        if((int)$db->query("SELECT GET_LOCK('remit_approval_mail',0)")->fetchColumn()!==1)return 0;
        $sent=0;
        try{
            $limit=max(1,min(25,$limit));
            $s=$db->prepare("SELECT * FROM notification_outbox WHERE subject=? AND status='pending' AND attempts<3 ORDER BY id LIMIT $limit");$s->execute([self::SUBJECT]);$jobs=$s->fetchAll();
            foreach($jobs as $job){
                // Do not send obsolete alerts after the application has already been reviewed.
                if(preg_match('/Registration #([0-9]+)/',$job['body'],$m)){$check=$db->prepare("SELECT COUNT(*) FROM church_registrations WHERE id=? AND status='pending'");$check->execute([(int)$m[1]]);$pending=(int)$check->fetchColumn();}
                else $pending=(int)$db->query("SELECT COUNT(*) FROM church_registrations WHERE status='pending'")->fetchColumn();
                if(!$pending){$db->prepare("UPDATE notification_outbox SET status='failed' WHERE id=?")->execute([$job['id']]);continue;}
                $db->prepare('UPDATE notification_outbox SET attempts=attempts+1 WHERE id=?')->execute([$job['id']]);
                $ok=false;
                try{
                    $from=PasswordReset::sender($db);
                    if(filter_var($job['recipient'],FILTER_VALIDATE_EMAIL)&&filter_var($from,FILTER_VALIDATE_EMAIL))$ok=$transport?(bool)$transport($job['recipient'],self::SUBJECT,$job['body'],$from):@mail($job['recipient'],self::SUBJECT,$job['body'],['From'=>$from,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8']);
                }catch(Throwable $e){$ok=false;}
                $status=$ok?'sent':((int)$job['attempts']+1>=3?'failed':'pending');$db->prepare('UPDATE notification_outbox SET status=? WHERE id=?')->execute([$status,$job['id']]);if($ok)$sent++;
            }
            return $sent;
        }finally{$db->query("SELECT RELEASE_LOCK('remit_approval_mail')");}
    }
}
