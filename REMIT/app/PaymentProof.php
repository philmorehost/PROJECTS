<?php
declare(strict_types=1);
final class PaymentProof
{
    public static function ready(PDO $db): bool { return setting($db,'payment_proof_schema')==='1'; }
    public static function supported(): bool { return extension_loaded('gd') && function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false); }
    public static function migrate(PDO $db): void
    {
        if(!ChurchPortal::ready($db))throw new RuntimeException('Enable the church portal first.');
        if((int)$db->query("SELECT GET_LOCK('remit_payment_proof_migration',5)")->fetchColumn()!==1)throw new RuntimeException('Another upgrade is running. Retry shortly.');
        try{
            $db->exec("CREATE TABLE IF NOT EXISTS payment_receipts (notification_id INT PRIMARY KEY,proof_key CHAR(32) UNIQUE NOT NULL,byte_size INT NOT NULL,thumb_bytes INT NOT NULL,width INT NOT NULL,height INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(notification_id) REFERENCES payment_notifications(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("INSERT INTO security_settings(setting_key,setting_value) VALUES('payment_proof_schema','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        }finally{$db->query("SELECT RELEASE_LOCK('remit_payment_proof_migration')");}
    }
    public static function path(string $key,bool $thumb=false): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new RuntimeException('Invalid receipt key.');
        return ROOT.'/storage/receipts/'.$key.($thumb?'-thumb':'').'.webp';
    }
    /** Only real PHP multipart uploads reach the decoder. Original files are never retained. */
    public static function store(array $upload): array
    {
        if(($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Receipt upload failed. Choose an image up to 8 MB.');
        $tmp=$upload['tmp_name'] ?? null;
        if(!is_string($tmp) || !is_uploaded_file($tmp))throw new InvalidArgumentException('Choose a valid uploaded receipt image.');
        return self::convert($tmp);
    }
    /** Trusted local-file decoder, also exercised by CLI tests. Never accepts a public path parameter. */
    public static function convert(string $tmp): array
    {
        if(!self::supported())throw new RuntimeException('Receipt upload needs PHP GD with WebP support. Contact the administrator.');
        $size=filesize($tmp);$info=@getimagesize($tmp);
        if(!$size || $size>8388608 || !$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))throw new InvalidArgumentException('Use a JPEG, PNG or WebP receipt up to 8 MB. PDFs and documents are not accepted.');
        [$w,$h]=$info;
        if($w<1 || $h<1 || $w>8000 || $h>8000 || $w*$h>12000000)throw new InvalidArgumentException('Receipt image is too large. Use at most 12 megapixels and 8000 pixels per side.');
        $limit=ini_get('memory_limit');$bytes=(int)$limit;if(preg_match('/([KMG])$/i',$limit,$m))$bytes*=1024**(strpos('KMG',strtoupper($m[1]))+1);
        if($bytes>0 && memory_get_usage(true)+$w*$h*10+$size+16777216>$bytes)throw new InvalidArgumentException('Receipt is too large for this server. Resize it and retry.');
        $source=@imagecreatefromstring(file_get_contents($tmp));if(!$source)throw new InvalidArgumentException('The receipt image could not be decoded.');
        $key=bin2hex(random_bytes(16));$written=[];
        try{
            // Honour JPEG orientation before stripping EXIF during re-encoding.
            if($info[2]===IMAGETYPE_JPEG && function_exists('exif_read_data')){
                $exif=@exif_read_data($tmp);$orientation=(int)($exif['Orientation'] ?? 1);
                if(in_array($orientation,[2,4,5,7],true))imageflip($source,IMG_FLIP_HORIZONTAL);
                $angle=match($orientation){3,4=>180,5,6=>-90,7,8=>90,default=>0};
                if($angle){$rotated=imagerotate($source,$angle,0);if(!$rotated)throw new RuntimeException('Could not orient receipt.');$source=$rotated;unset($rotated);}
            }
            $dir=dirname(self::path($key));if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))throw new RuntimeException('Cannot create private receipt storage.');
            $result=['proof_key'=>$key];
            foreach([false=>2000,true=>320] as $thumb=>$edge){
                $scale=min(1,$edge/max(imagesx($source),imagesy($source)));$width=max(1,(int)round(imagesx($source)*$scale));$height=max(1,(int)round(imagesy($source)*$scale));
                $image=imagecreatetruecolor($width,$height);$white=imagecolorallocate($image,255,255,255);imagefill($image,0,0,$white);imagecopyresampled($image,$source,0,0,0,0,$width,$height,imagesx($source),imagesy($source));
                $path=self::path($key,(bool)$thumb);$old=umask(0077);try{$handle=fopen($path,'xb');}finally{umask($old);}
                if(!$handle){unset($image);throw new RuntimeException('Cannot save receipt.');}$written[]=$path;
                try{if(!imagewebp($image,$handle,$thumb?75:82))throw new RuntimeException('Could not convert receipt to WebP.');}finally{fclose($handle);unset($image);}
                $length=filesize($path);if(!$length || $length>2097152)throw new InvalidArgumentException('Receipt remains too large after conversion. Resize the image and retry.');
                if($thumb)$result['thumb_bytes']=$length;else{$result['byte_size']=$length;$result['width']=$width;$result['height']=$height;}
            }
            return $result;
        }catch(Throwable $e){foreach($written as $file)@unlink($file);throw $e;}
        finally{unset($source);}
    }
    public static function remove(string $key): void { foreach([false,true] as $thumb)if(is_file(self::path($key,$thumb)))@unlink(self::path($key,$thumb)); }
    /** Remove only unreferenced optimized files older than a day (crash/rollback remnants). */
    public static function prune(PDO $db): int
    {
        if(!self::ready($db))return 0;$count=0;
        $lookup=$db->prepare('SELECT notification_id FROM payment_receipts WHERE proof_key=?');
        foreach(glob(ROOT.'/storage/receipts/*.webp')?:[] as $path){
            if(is_link($path)||!preg_match('/^([a-f0-9]{32})(?:-thumb)?\.webp$/D',basename($path),$m)||filemtime($path)>time()-86400)continue;
            $lookup->execute([$m[1]]);if($lookup->fetchColumn()!==false)continue;
            if(unlink($path))$count++;
        }
        return $count;
    }
    public static function authorized(PDO $db,array $user,int $id): ?string
    {
        if(!self::ready($db) || $id<1)return null;
        $s=$db->prepare('SELECT r.proof_key,l.parish_id FROM payment_receipts r JOIN payment_notifications n ON n.id=r.notification_id JOIN remittance_ledgers l ON l.id=n.ledger_id WHERE n.id=?');$s->execute([$id]);$row=$s->fetch();
        if(!$row)return null;
        if(in_array($user['role'],['provincial_admin','provincial_treasurer'],true))return $row['proof_key'];
        if(canRemit($user) && remittingUnit($db,$user)===(int)$row['parish_id'])return $row['proof_key'];
        return null;
    }
    public static function serve(PDO $db,?array $user): never
    {
        if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
        $id=filter_var($_GET['notification_id'] ?? 0,FILTER_VALIDATE_INT);$key=$user?self::authorized($db,$user,(int)$id):null;
        if(!$key){http_response_code(404);exit('Receipt unavailable.');}
        $path=self::path($key,($_GET['thumb'] ?? '')==='1');
        if(!is_file($path) || is_link($path)){http_response_code(404);exit('Receipt unavailable.');}
        header('Content-Type: image/webp');header('Content-Disposition: inline; filename="payment-receipt.webp"');header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($path));readfile($path);exit;
    }
}
