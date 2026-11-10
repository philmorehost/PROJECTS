<?php
declare(strict_types=1);
final class RepresentativeManager
{
    public const ROLES=['parish_officer','area_rep','zonal_rep'];
    public static function target(PDO $db,int $id,bool $lock=false): array
    {
        $s=$db->prepare('SELECT * FROM users WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);$row=$s->fetch();
        if(!$row||!in_array($row['role'],self::ROLES,true))throw new RuntimeException('Choose a church representative, not a privileged account.');return $row;
    }
    public static function manage(PDO $db,array $admin,array $input): string
    {
        requireRole($admin,['provincial_admin']);
        if(!ChurchPortal::officerRegistrationReady($db))throw new RuntimeException('Upgrade church registration in Portal settings first.');
        if(!Security::verifyPin($db,$admin,is_string($input['pin']??null)?$input['pin']:''))throw new RuntimeException('Transaction PIN rejected.');
        $operation=$input['operation']??'';if(!in_array($operation,['save','suspend','unsuspend','delete'],true))throw new InvalidArgumentException('Invalid representative operation.');
        $id=(int)($input['rep_id']??0);$db->beginTransaction();
        try{
            $old=$id?self::target($db,$id,true):null;
            if($operation==='save'){
                foreach(['full_name','email','phone','password'] as $key)if(!is_string($input[$key]??null))throw new InvalidArgumentException('Provide valid representative details.');
                $name=trim($input['full_name']);$email=strtolower(trim($input['email']));$phone=trim($input['phone']);$password=$input['password'];$unit=(int)($input['hierarchy_id']??0);
                if($name===''||strlen($name)>100||strlen($email)>150||!filter_var($email,FILTER_VALIDATE_EMAIL)||!preg_match('/^[+0-9 ()-]{7,25}$/D',$phone))throw new InvalidArgumentException('Check the name, email and phone number.');
                if((!$old||$password!=='')&&(strlen($password)<12||strlen($password)>72||str_contains($password,"\0")))throw new InvalidArgumentException('Use a 12–72 byte password; leave blank only to keep an existing password.');
                $s=$db->prepare('SELECT node_type FROM church_hierarchy WHERE id=? FOR UPDATE');$s->execute([$unit]);$role=match($s->fetchColumn()){'parish'=>'parish_officer','area'=>'area_rep','zone'=>'zonal_rep',default=>throw new InvalidArgumentException('Choose an existing Parish, Area or Zone.')};
                if($old){
                    $hash=$password===''?$old['password_hash']:password_hash($password,PASSWORD_DEFAULT);
                    $db->prepare('UPDATE users SET full_name=?,email=?,hierarchy_id=?,role=?,password_hash=? WHERE id=?')->execute([$name,$email,$unit,$role,$hash,$id]);
                }else{
                    $db->prepare('INSERT INTO users(full_name,email,hierarchy_id,role,password_hash) VALUES(?,?,?,?,?)')->execute([$name,$email,$unit,$role,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$db->lastInsertId();
                }
                $s=$db->prepare('SELECT user_id FROM officer_profiles WHERE user_id=?');$s->execute([$id]);
                if($s->fetchColumn())$db->prepare('UPDATE officer_profiles SET phone=? WHERE user_id=?')->execute([$phone,$id]);
                else $db->prepare("INSERT INTO officer_profiles(user_id,phone,bank_name,account_name,account_ciphertext,account_last4) VALUES(?,?,'','','','')")->execute([$id,$phone]);
            }elseif(!$old)throw new RuntimeException('Choose a representative.');
            elseif($operation==='delete'){
                if(($input['confirm_delete']??'')!=='DELETE')throw new InvalidArgumentException('Type DELETE to confirm.');
                $s=$db->prepare('SELECT COUNT(*) FROM payment_notifications WHERE submitted_by=? OR reviewed_by=?');$s->execute([$id,$id]);if((int)$s->fetchColumn())throw new RuntimeException('This representative has payment history. Suspend the account instead.');
                $s=$db->prepare('SELECT COUNT(*) FROM church_registrations WHERE reviewed_by=?');$s->execute([$id]);if((int)$s->fetchColumn())throw new RuntimeException('This account has review history. Suspend it instead.');
                $db->prepare('DELETE FROM officer_profiles WHERE user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            }else $db->prepare('UPDATE users SET is_suspended=? WHERE id=?')->execute([$operation==='suspend'?1:0,$id]);
            audit($db,'representative_'.$operation,$admin['email'],json_encode(['actor'=>$admin['id'],'representative'=>$id]));
            $db->commit();return 'Representative '.($operation==='save'?'saved':($operation==='delete'?'deleted':($operation==='suspend'?'suspended':'unsuspended'))).'.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
