<?php
declare(strict_types=1);
require __DIR__.'/church-portal.php';
mkdir(ROOT,0700,true);
try{
 $db->exec("CREATE TABLE payment_receipts(notification_id INTEGER PRIMARY KEY,proof_key TEXT UNIQUE,byte_size INTEGER,thumb_bytes INTEGER,width INTEGER,height INTEGER);INSERT INTO security_settings(setting_key,setting_value) VALUES('payment_proof_schema','1')");
 check(PaymentProof::supported(),'GD WebP support');
 $src=ROOT.'/original.png';$im=imagecreatetruecolor(2400,1000);imagefill($im,0,0,imagecolorallocate($im,240,250,255));imagestring($im,5,20,20,'TEST PAYMENT RECEIPT',imagecolorallocate($im,20,30,40));imagepng($im,$src);unset($im);
 $proof=PaymentProof::convert($src);$main=PaymentProof::path($proof['proof_key']);$thumb=PaymentProof::path($proof['proof_key'],true);
 check(getimagesize($main)[2]===IMAGETYPE_WEBP,'real WebP conversion');check(getimagesize($main)[0]===2000,'bounded full image');check(getimagesize($thumb)[0]===320,'bounded thumbnail');check($proof['byte_size']<2097152&&$proof['thumb_bytes']<2097152,'storage bound');check(count(glob(ROOT.'/storage/receipts/*'))===2,'only two optimized files');
 foreach(['jpg','webp'] as $format){$im=imagecreatetruecolor(20,30);$path=ROOT.'/original.'.$format;if($format==='jpg')imagejpeg($im,$path);else imagewebp($im,$path);unset($im);$converted=PaymentProof::convert($path);check(getimagesize(PaymentProof::path($converted['proof_key']))[2]===IMAGETYPE_WEBP,$format.' converts');PaymentProof::remove($converted['proof_key']);unlink($path);}
 refuses(fn()=>PaymentProof::store(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$src]),'local files not trusted as multipart upload');
 refuses(fn()=>PaymentProof::store(['error'=>UPLOAD_ERR_PARTIAL]),'partial upload rejected');
 file_put_contents(ROOT.'/bad.pdf','%PDF-1.7 not an image');refuses(fn()=>PaymentProof::convert(ROOT.'/bad.pdf'),'document rejected');
 $im=imagecreatetruecolor(8001,1);imagepng($im,ROOT.'/wide.png');unset($im);refuses(fn()=>PaymentProof::convert(ROOT.'/wide.png'),'oversized dimensions rejected');
 refuses(fn()=>PaymentProof::path('../bad'),'path traversal rejected');
 $large=ROOT.'/large.png';copy($src,$large);$handle=fopen($large,'ab');fwrite($handle,str_repeat('x',8388608));fclose($handle);refuses(fn()=>PaymentProof::convert($large),'8 MiB input cap');

 $notification=(int)$db->query('SELECT id FROM payment_notifications LIMIT 1')->fetchColumn();
 $db->prepare('INSERT INTO payment_receipts VALUES(?,?,?,?,?,?)')->execute([$notification,$proof['proof_key'],$proof['byte_size'],$proof['thumb_bytes'],$proof['width'],$proof['height']]);
 check(PaymentProof::authorized($db,['role'=>'provincial_admin'],$notification)===$proof['proof_key'],'admin access');check(PaymentProof::authorized($db,['role'=>'provincial_treasurer'],$notification)===$proof['proof_key'],'treasurer access');
 check(PaymentProof::authorized($db,['role'=>'parish_officer','hierarchy_id'=>1],$notification)===$proof['proof_key'],'own church access');check(PaymentProof::authorized($db,['role'=>'parish_officer','hierarchy_id'=>2],$notification)===null,'other parish denied');check(PaymentProof::authorized($db,['role'=>'zonal_rep','hierarchy_id'=>3],$notification)===null,'supervisor private proof denied');
 $db->exec("UPDATE remittance_ledgers SET gross_income='100000.00',period_year=2025,period_month=12 WHERE gross_income IS NULL");
 for($i=0;$i<145;$i++){$db->prepare('INSERT INTO remittance_ledgers(parish_id,provincial_levy_due,amount_paid,status,payment_reference,gross_income,period_month,period_year) VALUES(?,?,?,?,?,?,?,?)')->execute([1,'80.00','0.00','pending','HISTORY-'.$i,'100.00',($i%12)+1,2030+intdiv($i,12)]);$id=(int)$db->lastInsertId();$db->prepare('INSERT INTO payment_notifications(ledger_id,submitted_by,amount,transfer_reference,transfer_date) VALUES(?,?,?,?,?)')->execute([$id,1,'10.00','ARCHIVE-'.$i,'2026-01-01']);}
 $before=(int)$db->query('SELECT COUNT(*) FROM payment_notifications')->fetchColumn();
 refuses(fn()=>ChurchPortal::submitPayment($db,['id'=>1,'role'=>'parish_officer','hierarchy_id'=>1],['ledger_id'=>$id,'amount'=>'10.00','transfer_reference'=>'FAILED-UPLOAD','transfer_date'=>date('Y-m-d')],['error'=>UPLOAD_ERR_OK,'tmp_name'=>$src]),'invalid multipart submission rolls back');
 check(!$db->inTransaction()&&(int)$db->query('SELECT COUNT(*) FROM payment_notifications')->fetchColumn()===$before,'failed upload leaves no notification');
 foreach(['reports','payments'] as $kind){$h=new FinancialHistory($db,[1],[],$kind);check($h->total>100&&count($h->items)===12,'all '.$kind.' paginated');$h=new FinancialHistory($db,[1],[$kind.'_q'=>$kind==='reports'?'HISTORY-0':'ARCHIVE-0'],$kind);check($h->total===1,'old '.$kind.' searchable beyond 100');$h=new FinancialHistory($db,[2],[$kind.'_q'=>'HISTORY'],$kind);check($h->total===0,'scope enforced');$h=new FinancialHistory($db,[],[],$kind);check($h->total===0,'empty scope');$h=new FinancialHistory($db,[1],[$kind.'_q'=>"%' OR 1=1 --"],$kind);check($h->total===0,'SQL metacharacters safe');$h=new FinancialHistory($db,[1],[$kind.'_page'=>999999],$kind);check($h->page===$h->pages&&count($h->items)>0,'page clamped');$h=new FinancialHistory($db,[1],[$kind.'_q'=>['bad'],$kind.'_status'=>['bad'],$kind.'_year'=>['bad']],$kind);check($h->total>100,'malformed filters ignored');}
 $h=new FinancialHistory($db,[1],['reports_year'=>2030,'reports_month'=>2,'reports_status'=>'pending'],'reports');check($h->total===1,'report date and status');$h=new FinancialHistory($db,[1],['payments_year'=>2026,'payments_month'=>1,'payments_status'=>'pending'],'payments');check($h->total===145,'payment transfer date and status');
 $orphan=str_repeat('a',32);file_put_contents(PaymentProof::path($orphan),'orphan');touch(PaymentProof::path($orphan),time()-90000);touch($main,time()-90000);check(PaymentProof::prune($db)===1,'old orphan pruned');check(file_exists($main),'referenced file retained');
 PaymentProof::remove($proof['proof_key']);check(!file_exists($main)&&!file_exists($thumb),'receipt pair cleanup');
 echo "PASS: $checks combined payment proof/history assertions\n";
}finally{
 foreach(glob(ROOT.'/storage/receipts/*')?:[] as $file)unlink($file);if(is_dir(ROOT.'/storage/receipts'))rmdir(ROOT.'/storage/receipts');if(is_dir(ROOT.'/storage'))rmdir(ROOT.'/storage');foreach(glob(ROOT.'/*')?:[] as $file)if(is_file($file))unlink($file);rmdir(ROOT);
}
