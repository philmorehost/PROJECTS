<?php
declare(strict_types=1);
require __DIR__.'/../app/DirectoryPagination.php';
$count=0;
function check(bool $ok,string $message):void{global $count;$count++;if(!$ok)throw new RuntimeException($message);}
$nodes=[];
for($i=1;$i<=65;$i++)$nodes[]=['id'=>$i,'node_name'=>'Church '.$i,'node_type'=>$i%2?'parish':'area','parish_code'=>'C'.$i,'parent_name'=>'Hope Area','zone_name'=>$i<=30?'North':'South','contact_person'=>'Private Officer','phone_number'=>'08012345678'];
$p=new DirectoryPagination($nodes,[],true);
check($p->page===1 && $p->pages===11 && count($p->items)===6,'default six per page');check($p->start===1 && $p->end===6 && $p->total===65,'default counts');
$p=new DirectoryPagination($nodes,['directory_page'=>11],true);check(count($p->items)===5 && $p->start===61 && $p->end===65,'last partial page');
foreach([-1,0,'oops',[],['1'],'999999999999999999999999'] as $page){$p=new DirectoryPagination($nodes,['directory_page'=>$page],false);check($p->page===1,'invalid page defaults safely');}
$p=new DirectoryPagination($nodes,['directory_page'=>999],false);check($p->page===11,'page beyond end clamps');
foreach([6,12,24] as $size){$p=new DirectoryPagination($nodes,['directory_size'=>$size],false);check(count($p->items)===$size,'allowed page sizes');}
foreach([0,-6,100000,'abc',[],['12']] as $size){$p=new DirectoryPagination($nodes,['directory_size'=>$size],false);check(count($p->items)===6,'unsupported page sizes default');}
$p=new DirectoryPagination([],['directory_page'=>100],false);check($p->page===1 && $p->pages===1 && $p->start===0 && $p->end===0 && !$p->items,'empty state');
$p=new DirectoryPagination($nodes,['directory_q'=>'Church 65','directory_page'=>10],false);check($p->total===1 && $p->items[0]['id']===65 && $p->page===1,'search spans all pages and clamps');
$p=new DirectoryPagination($nodes,['directory_q'=>'c65'],false);check($p->total===1,'case insensitive code search');
foreach(['Private Officer','08012345678'] as $query){check((new DirectoryPagination($nodes,['directory_q'=>$query],true))->total===65,'admin contact search');check((new DirectoryPagination($nodes,['directory_q'=>$query],false))->total===0,'representative contact privacy');}
$p=new DirectoryPagination($nodes,['directory_type'=>'parish','directory_zone'=>'North'],false);check($p->total===15,'combined level/zone filter');
$p=new DirectoryPagination($nodes,['directory_type'=>'<script>','directory_zone'=>'unscoped'],false);check($p->total===65,'unknown filters default without expanding input scope');
foreach([[],str_repeat('a',201),"\xFF"] as $query){check((new DirectoryPagination($nodes,['directory_q'=>$query],false))->total===65,'invalid query resets safely');}
check((new DirectoryPagination($nodes,['directory_q'=>'.*'],false))->total===0,'regex metacharacters are literal');
check((new DirectoryPagination([$nodes[64]],[],false))->total===1,'only supplied authorized scope is searched');
$unicode=[array_replace($nodes[0],['node_name'=>'Église de Grâce'])];check((new DirectoryPagination($unicode,['directory_q'=>'église'],false))->total===1,'Unicode case search');
$p=new DirectoryPagination($nodes,['directory_q'=>'Hope Area','directory_zone'=>'South','directory_size'=>12,'directory_page'=>2],true);
$url=$p->url(['directory_page'=>3]);parse_str(parse_url($url,PHP_URL_QUERY),$params);check($params['page']==='hierarchy' && $params['directory_q']==='Hope Area' && $params['directory_zone']==='South' && $params['directory_page']==='3' && $params['directory_size']==='12','links preserve filter state');check(parse_url($url,PHP_URL_FRAGMENT)==='church-directory','link target');
$url=$p->url(['edit_unit'=>65],'hierarchy-editor');check(str_contains($url,'edit_unit=65') && str_ends_with($url,'#hierarchy-editor'),'edit retains current page and filters');
$p->pages=1000000;$p->page=500000;check($p->numbers()===[1,null,499999,500000,500001,null,1000000],'bounded page strip');
foreach([1,2,999999,1000000] as $page){$p->page=$page;$numbers=$p->numbers();check(count($numbers)<=7 && $numbers[0]===1 && end($numbers)===1000000,'boundary numbers bounded');}
echo "PASS: $count directory pagination assertions\n";
