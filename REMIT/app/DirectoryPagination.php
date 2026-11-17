<?php
declare(strict_types=1);

/** Paginates only the directory records already authorized by the portal's role scope. */
final class DirectoryPagination
{
    public const SIZES=[6,12,24];
    public array $state;
    public array $matches;
    public array $items;
    public int $total;
    public int $pages;
    public int $page;
    public int $start;
    public int $end;

    public function __construct(array $nodes,array $query,bool $admin)
    {
        $q=is_string($query['directory_q'] ?? null)?trim($query['directory_q']):'';
        if(preg_match('/^.{0,200}$/us',$q)!==1)$q='';
        $type=in_array($query['directory_type'] ?? '',['zone','area','parish'],true)?$query['directory_type']:'';
        $zone=is_string($query['directory_zone'] ?? null)?$query['directory_zone']:'';
        if(!in_array($zone,array_column($nodes,'zone_name'),true))$zone='';
        $size=filter_var($query['directory_size'] ?? 6,FILTER_VALIDATE_INT);
        if(!in_array($size,self::SIZES,true))$size=6;
        $this->state=['directory_q'=>$q,'directory_type'=>$type,'directory_zone'=>$zone,'directory_size'=>$size];
        $this->matches=array_values(array_filter($nodes,function(array $unit)use($q,$type,$zone,$admin):bool{
            if($type!=='' && $unit['node_type']!==$type)return false;
            if($zone!=='' && ($unit['zone_name'] ?? '')!==$zone)return false;
            $text=self::searchText($unit,$admin);
            return $q==='' || preg_match('/'.preg_quote($q,'/').'/iu',$text)===1;
        }));
        $this->total=count($this->matches);$this->pages=max(1,(int)ceil($this->total/$size));
        $page=filter_var($query['directory_page'] ?? 1,FILTER_VALIDATE_INT);$this->page=min($this->pages,max(1,$page===false?1:$page));
        $this->items=array_slice($this->matches,($this->page-1)*$size,$size);
        $this->start=$this->total?($this->page-1)*$size+1:0;$this->end=min($this->page*$size,$this->total);
    }
    public static function searchText(array $unit,bool $admin): string
    {
        return implode(' ',[$unit['node_name'],$unit['node_type'],$unit['parish_code'] ?? 'Unit #'.$unit['id'],$unit['parent_name'] ?? 'Provincial treasury',$unit['zone_name'] ?? '',$admin?($unit['contact_person'] ?? ''):'',$admin?($unit['phone_number'] ?? ''):'']);
    }
    public function url(array $changes=[],string $anchor='church-directory'): string
    {
        $query=array_merge(['page'=>'hierarchy'],$this->state,['directory_page'=>$this->page],$changes);
        return 'index.php?'.http_build_query($query).'#'.$anchor;
    }
    public function numbers(): array
    {
        $numbers=[];$last=0;
        $candidates=array_unique([1,max(1,$this->page-1),$this->page,min($this->pages,$this->page+1),$this->pages]);sort($candidates);
        foreach($candidates as $page){
            if($last && $page>$last+1)$numbers[]=null;
            $numbers[]=$page;$last=$page;
        }
        return $numbers;
    }
}
