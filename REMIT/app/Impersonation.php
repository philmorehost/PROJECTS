<?php
declare(strict_types=1);
final class Impersonation
{
    public static function active(): bool {return isset($_SESSION['impersonation']);}
    public static function start(PDO $db,array $admin,int $id,string $pin): void
    {
        requireRole($admin,['provincial_admin']);if(self::active())throw new RuntimeException('Return to admin before switching accounts.');
        if(!Security::verifyPin($db,$admin,$pin))throw new RuntimeException('Transaction PIN rejected.');
        $target=RepresentativeManager::target($db,$id);if($target['is_suspended'])throw new RuntimeException('Unsuspend this representative before viewing their account.');
        audit($db,'impersonation_start',$admin['email'],json_encode(['actor'=>$admin['id'],'target'=>$id,'mode'=>'read-only']));
        $_SESSION=[];session_regenerate_id(true);
        $_SESSION=['user_id'=>$id,'password_stamp'=>hash('sha256',$target['password_hash']),'last_activity'=>time(),'csrf'=>bin2hex(random_bytes(32)),
            'impersonation'=>['admin_id'=>(int)$admin['id'],'admin_stamp'=>hash('sha256',$admin['password_hash']),'target_id'=>$id,'target_role'=>$target['role'],'target_unit'=>(int)$target['hierarchy_id'],'expires'=>time()+1800]];
    }
    /** Validate the original admin on every request, including returning from an invalidated target session. */
    public static function refresh(PDO $db,bool $return=false): bool
    {
        if(!self::active())return false;$state=$_SESSION['impersonation'];
        $s=$db->prepare('SELECT * FROM users WHERE id=?');$s->execute([$state['admin_id']]);$admin=$s->fetch();
        if(!$admin||$admin['role']!=='provincial_admin'||$admin['is_suspended']||!hash_equals($state['admin_stamp'],hash('sha256',$admin['password_hash']))||$state['expires']<=time()){
            $_SESSION=[];session_regenerate_id(true);return false;
        }
        $s=$db->prepare('SELECT * FROM users WHERE id=?');$s->execute([$state['target_id']]);$target=$s->fetch();
        if(!$target||!in_array($target['role'],RepresentativeManager::ROLES,true)||$target['role']!==$state['target_role']||(int)$target['hierarchy_id']!==$state['target_unit']||$target['is_suspended']||!Security::sessionPasswordMatches($target,$_SESSION))$return=true;
        if(!$return)return true;
        audit($db,'impersonation_end',$admin['email'],json_encode(['actor'=>$admin['id'],'target'=>$state['target_id']]));
        $_SESSION=[];session_regenerate_id(true);$_SESSION=['user_id'=>$admin['id'],'password_stamp'=>hash('sha256',$admin['password_hash']),'last_activity'=>time(),'csrf'=>bin2hex(random_bytes(32))];return true;
    }
}
