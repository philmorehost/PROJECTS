<?php
declare(strict_types=1);
final class HierarchyBatch
{
    public static function parse(mixed $text): array
    {
        if(!is_string($text) || strlen($text)>2097152 || preg_match('//u',$text)!==1)throw new InvalidArgumentException('Paste UTF-8 hierarchy lines, up to 2 MB.');
        $rows=[];
        foreach(preg_split('/\r\n|\n|\r/',$text) as $offset=>$line){
            if(trim($line)==='')continue;
            if(count($rows)>=5000)throw new InvalidArgumentException('Use at most 5,000 non-empty lines.');
            if(!preg_match('/^\s*(?:"(?:[^"]|"")*"|[^",]*)(?:,\s*(?:"(?:[^"]|"")*"|[^",]*))*\s*$/D',trim($line)))throw new InvalidArgumentException('Line '.($offset+1).': check comma-separated names and closing quotes.');
            $parts=array_map('trim',str_getcsv($line,',','"',''));
            // Trailing missing levels are fine; an empty Area with a Parish means a direct Zone parish.
            while($parts && end($parts)==='')array_pop($parts);
            if(!$parts || count($parts)>3 || $parts[0]==='')throw new InvalidArgumentException('Line '.($offset+1).': use ZONE, AREA, PARISH; ZONE, AREA; or ZONE.');
            try{foreach($parts as &$name)if($name!=='')$name=ChurchNames::normalize($name);unset($name);}
            catch(InvalidArgumentException $e){throw new InvalidArgumentException('Line '.($offset+1).': '.$e->getMessage());}
            $rows[]=['line'=>$offset+1,'names'=>$parts];
        }
        if(!$rows)throw new InvalidArgumentException('Enter at least one hierarchy line.');
        return $rows;
    }
    public static function add(PDO $db,array $user,mixed $text): array
    {
        requireRole($user,['provincial_admin']);$rows=self::parse($text);$db->beginTransaction();
        try{
            // Same lock/order used by creation, assignment, imports, deletion and approval.
            $db->query("SELECT id FROM security_settings WHERE setting_key='site_title' FOR UPDATE")->fetch();
            $index=[];$existing=$db->query('SELECT id,node_name,node_type,parent_id FROM church_hierarchy ORDER BY id')->fetchAll();
            foreach($existing as $unit){$name=ChurchNames::normalize($unit['node_name']);$key=$unit['node_type'].'|'.(int)$unit['parent_id'].'|'.$name;$index[$key][]=(int)$unit['id'];}
            $created=0;$reused=0;$seen=[];
            foreach($rows as $row){
                $parent=null;
                foreach($row['names'] as $i=>$name){
                    if($name==='')continue;
                    $type=['zone','area','parish'][$i];$key=$type.'|'.(int)$parent.'|'.$name;
                    if(count($index[$key] ?? [])>1)throw new InvalidArgumentException('Line '.$row['line'].': multiple '.$type.' units named '.$name.' exist under this parent. Resolve the ambiguity first. Nothing was added.');
                    if(isset($index[$key])){$id=$index[$key][0];if(!isset($seen[$id]))$reused++;}
                    else{
                        $db->prepare('INSERT INTO church_hierarchy(node_name,node_type,parent_id) VALUES(?,?,?)')->execute([$name,$type,$parent]);$id=(int)$db->lastInsertId();
                        if($type==='parish'){
                            $code='AUTO'.$id;$suffix=0;
                            do{$candidate=$code.($suffix?'-'.$suffix:'');$s=$db->prepare('SELECT id FROM church_hierarchy WHERE parish_code=?');$s->execute([$candidate]);$taken=$s->fetchColumn();$suffix++;}while($taken);
                            $db->prepare('UPDATE church_hierarchy SET parish_code=? WHERE id=?')->execute([$candidate,$id]);
                        }
                        $index[$key]=[$id];$created++;
                    }
                    $seen[$id]=true;$parent=$id;
                }
            }
            // Apply CAPS to pre-existing names too, without changing IDs, parents or parish codes.
            $update=$db->prepare('UPDATE church_hierarchy SET node_name=? WHERE id=?');
            foreach($existing as $unit){$name=ChurchNames::normalize($unit['node_name']);if($name!==$unit['node_name'])$update->execute([$name,$unit['id']]);}
            $db->commit();error_log('REMIT hierarchy batch by user '.(int)$user['id'].'; lines '.count($rows).'; created '.$created.'; reused '.$reused);return ['lines'=>count($rows),'created'=>$created,'reused'=>$reused];
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
