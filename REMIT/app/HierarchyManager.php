<?php
declare(strict_types=1);

final class HierarchyManager
{
    private static function lock(PDO $db): void
    {
        // Same singleton lock as the CSV importer: serialize every structural writer.
        $db->query("SELECT id FROM security_settings WHERE setting_key='site_title' FOR UPDATE")->fetch();
    }
    public static function validParent(string $type, ?string $parentType): bool
    {
        return match($type) {'zone'=>$parentType===null,'area'=>$parentType==='zone','parish'=>in_array($parentType,['area','zone'],true),default=>false};
    }
    public static function create(PDO $db,array $user,array $input): string
    {
        requireRole($user,['provincial_admin']);
        $name=trim($input['node_name'] ?? '');$type=$input['node_type'] ?? '';$parent=(int)($input['parent_id'] ?? 0);$code=trim($input['parish_code'] ?? '');
        if($name==='' || strlen($name)>150 || !in_array($type,['zone','area','parish'],true) || strlen($code)>20 || ($type==='parish' && $code===''))throw new InvalidArgumentException('Provide a unit name, valid level and a unique parish code for parishes.');
        $db->beginTransaction();
        try {
            self::lock($db);$parentType=null;
            if($type!=='zone') {$s=$db->prepare('SELECT node_type FROM church_hierarchy WHERE id=?');$s->execute([$parent]);$parentType=$s->fetchColumn();if($parentType===false)throw new RuntimeException('Select an existing parent unit.');}
            if(!self::validParent($type,$parentType))throw new RuntimeException('Areas belong to zones. Parishes may belong to an area or directly to a zone.');
            $db->prepare('INSERT INTO church_hierarchy(node_name,node_type,parent_id,parish_code) VALUES(?,?,?,?)')->execute([$name,$type,$type==='zone'?null:$parent,$type==='parish'?$code:null]);
            $db->commit();return 'Organizational unit created.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function move(PDO $db,array $user,array $input): string
    {
        requireRole($user,['provincial_admin']);
        $values=$input['unit_ids'] ?? [];if(!is_array($values) || !$values || count($values)>500)throw new InvalidArgumentException('Select between 1 and 500 areas or parishes to move.');
        $ids=[];foreach($values as $id){if(!is_scalar($id) || !preg_match('/^[1-9]\d{0,9}$/D',(string)$id))throw new InvalidArgumentException('Invalid selected church unit.');$ids[]=(int)$id;}$ids=array_values(array_unique($ids));sort($ids);
        $target=(int)($input['destination_id'] ?? 0);if(in_array($target,$ids,true))throw new InvalidArgumentException('A unit cannot be assigned beneath itself.');
        $db->beginTransaction();
        try {
            self::lock($db);
            $s=$db->prepare('SELECT * FROM church_hierarchy WHERE id=?');$s->execute([$target]);$destination=$s->fetch();
            if(!$destination || !in_array($destination['node_type'],['zone','area'],true))throw new InvalidArgumentException('Choose a zone or area as the destination.');
            $marks=implode(',',array_fill(0,count($ids),'?'));$s=$db->prepare("SELECT * FROM church_hierarchy WHERE id IN ($marks) ORDER BY id");$s->execute($ids);$units=$s->fetchAll();
            if(count($units)!==count($ids))throw new RuntimeException('One or more selected church units no longer exist. Refresh the directory.');
            foreach($units as $unit)if(!in_array($unit['node_type'],['area','parish'],true) || !self::validParent($unit['node_type'],$destination['node_type']))throw new RuntimeException('Only areas and parishes can be moved. Areas require a zone; parishes can be moved to either a zone or an area. Nothing was changed.');
            foreach($units as $unit){
                $ancestor=(int)$unit['parent_id'];$seen=[];
                while($ancestor){
                    if(isset($seen[$ancestor]))throw new RuntimeException('A selected unit has invalid cyclic ancestry.');
                    if(in_array($ancestor,$ids,true))throw new RuntimeException('Select the Area only to move it with its child parishes. Do not also select its descendants.');
                    $seen[$ancestor]=true;$s=$db->prepare('SELECT parent_id FROM church_hierarchy WHERE id=?');$s->execute([$ancestor]);$ancestor=(int)$s->fetchColumn();
                }
            }
            // Defensive check against malformed existing ancestry as well as cycles from selected ancestors.
            $cursor=$target;$visited=[];
            while($cursor){if(isset($visited[$cursor]) || in_array($cursor,$ids,true))throw new RuntimeException('This assignment would create or retain a hierarchy cycle.');$visited[$cursor]=true;$s=$db->prepare('SELECT parent_id FROM church_hierarchy WHERE id=?');$s->execute([$cursor]);$cursor=(int)$s->fetchColumn();}
            $count=0;$update=$db->prepare('UPDATE church_hierarchy SET parent_id=? WHERE id=?');
            foreach($units as $unit)if((int)$unit['parent_id']!==$target){$update->execute([$target,$unit['id']]);$count++;}
            $db->commit();error_log('REMIT hierarchy move by user '.(int)$user['id'].'; destination '.$target.'; units '.implode(',',$ids));
            return $count.' church unit(s) moved to '.$destination['node_name'].'. Child parishes move with their area; reports and officer accounts stay attached to their existing units.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function upgrade(PDO $db,array $user,array $input): string
    {
        requireRole($user,['provincial_admin']);$name=trim($input['area_name'] ?? '');$id=(int)($input['parish_id'] ?? 0);
        if($name==='' || strlen($name)>150)throw new InvalidArgumentException('Enter the new area name (up to 150 characters).');
        $db->beginTransaction();
        try{
            self::lock($db);$s=$db->prepare('SELECT p.*,z.node_type parent_type FROM church_hierarchy p LEFT JOIN church_hierarchy z ON p.parent_id=z.id WHERE p.id=?');$s->execute([$id]);$parish=$s->fetch();
            if(!$parish || $parish['node_type']!=='parish' || $parish['parent_type']!=='zone')throw new RuntimeException('Only a parish currently attached directly to a zone can be upgraded this way.');
            $s=$db->prepare("SELECT id FROM church_hierarchy WHERE node_type='area' AND parent_id=? AND node_name=?");$s->execute([$parish['parent_id'],$name]);if($s->fetchColumn())throw new RuntimeException('An area with that name already exists in this zone. Use the assignment tool instead.');
            $db->prepare("INSERT INTO church_hierarchy(node_name,node_type,parent_id) VALUES(?,'area',?)")->execute([$name,$parish['parent_id']]);$area=(int)$db->lastInsertId();
            $db->prepare('UPDATE church_hierarchy SET parent_id=? WHERE id=?')->execute([$area,$parish['id']]);
            $db->commit();error_log('REMIT area upgrade by user '.(int)$user['id'].'; area '.$area.'; headquarters parish '.$id);
            return 'Area created under the same zone. The original parish is retained as its headquarters parish, preserving its code, accounts and financial history. You can now assign other parishes to the area.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
