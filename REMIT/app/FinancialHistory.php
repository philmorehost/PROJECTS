<?php
declare(strict_types=1);
/** Database filtering/pagination, always bounded by the existing financial read scope. */
final class FinancialHistory
{
    public array $state;public array $items;public int $total;public int $page;public int $pages;public string $prefix;
    public function __construct(PDO $db,array $ids,array $query,string $kind)
    {
        if(!in_array($kind,['reports','payments'],true))throw new InvalidArgumentException('Invalid history.');
        $this->prefix=$kind;$q=$query[$kind.'_q'] ?? '';$q=is_string($q)&&preg_match('/^.{0,100}$/us',$q)?trim($q):'';
        $statuses=$kind==='reports'?['pending','partial','paid','verified']:['pending','approved','rejected'];$status=$query[$kind.'_status'] ?? '';if(!in_array($status,$statuses,true))$status='';
        $year=filter_var($query[$kind.'_year'] ?? 0,FILTER_VALIDATE_INT);if(!$year||$year<2020||$year>2100)$year=0;
        $month=filter_var($query[$kind.'_month'] ?? 0,FILTER_VALIDATE_INT);if(!$month||$month<1||$month>12)$month=0;
        $this->state=[$kind.'_q'=>$q,$kind.'_status'=>$status,$kind.'_year'=>$year?:'',$kind.'_month'=>$month?:''];
        $ids=array_values(array_unique(array_map('intval',$ids)));$marks=implode(',',array_fill(0,count($ids),'?'))?:'NULL';$params=$ids;
        $from=$kind==='reports'?'remittance_ledgers l JOIN church_hierarchy h ON h.id=l.parish_id':'payment_notifications n JOIN remittance_ledgers l ON l.id=n.ledger_id JOIN church_hierarchy h ON h.id=l.parish_id';
        $alias=$kind==='reports'?'l':'n';$where="l.parish_id IN ($marks)";
        if($q!==''){$value='%'.strtr($q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';$fields=['h.node_name','h.parish_code','l.payment_reference'];if($kind==='payments')$fields=array_merge($fields,['n.transfer_reference','n.review_note','CAST(n.amount AS CHAR)']);else $fields[]='CAST(l.gross_income AS CHAR)';$where.=' AND ('.implode(' OR ',array_map(fn($f)=>"$f LIKE ? ESCAPE '!'",$fields)).')';foreach($fields as $_)$params[]=$value;}
        if($status!==''){$where.=" AND $alias.status=?";$params[]=$status;}
        if($kind==='reports'){
            if($year){$where.=' AND l.period_year=?';$params[]=$year;}if($month){$where.=' AND l.period_month=?';$params[]=$month;}
        }else{
            if($year){$start=sprintf('%04d-%02d-01',$year,$month?:1);$end=(new DateTimeImmutable($start))->modify($month?'+1 month':'+1 year')->format('Y-m-d');$where.=' AND n.transfer_date>=? AND n.transfer_date<?';array_push($params,$start,$end);}
            elseif($month){$where.=' AND SUBSTR(n.transfer_date,6,2)=?';$params[]=sprintf('%02d',$month);}
        }
        $s=$db->prepare("SELECT COUNT(*) FROM $from WHERE $where");$s->execute($params);$this->total=(int)$s->fetchColumn();$this->pages=max(1,(int)ceil($this->total/12));
        $page=filter_var($query[$kind.'_page'] ?? 1,FILTER_VALIDATE_INT);$this->page=min($this->pages,max(1,$page?:1));$offset=($this->page-1)*12;
        $proof=$kind==='payments'&&PaymentProof::ready($db);$join=$proof?' LEFT JOIN payment_receipts r ON r.notification_id=n.id':'';
        $select=$kind==='reports'?'l.*,h.node_name,h.parish_code':"n.*,l.payment_reference,l.parish_id,h.node_name,".($proof?'r.proof_key':'NULL AS proof_key');
        $order=$kind==='reports'?'l.period_year DESC,l.period_month DESC,l.id DESC':'n.id DESC';
        $s=$db->prepare("SELECT $select FROM $from $join WHERE $where ORDER BY $order LIMIT 12 OFFSET $offset");$s->execute($params);$this->items=$s->fetchAll();
    }
    public function url(array $query,array $changes=[]): string
    {
        $allowed=[];foreach(['reports','payments'] as $prefix)foreach(['q','status','year','month','page'] as $field){$key=$prefix.'_'.$field;if(is_scalar($query[$key] ?? null))$allowed[$key]=(string)$query[$key];}
        return 'index.php?'.http_build_query(array_merge(['page'=>'remittances'],$allowed,$this->state,[$this->prefix.'_page'=>$this->page],$changes)).'#'.$this->prefix.'-history';
    }
}
