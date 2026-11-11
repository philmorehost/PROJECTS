<?php
declare(strict_types=1);
final class PayHub
{
    private const BASE='https://merchant.payhub.com.ng';
    public static function migrate(PDO $db): void
    {
        ensureNotificationSchema($db);
        foreach([
            "ALTER TABLE payment_notifications ADD COLUMN payhub_reference VARCHAR(100) NULL",
            "ALTER TABLE payment_notifications ADD COLUMN payhub_channel VARCHAR(30) NULL",
            "ALTER TABLE payment_notifications ADD COLUMN payhub_paid_at DATETIME NULL",
            "ALTER TABLE payment_notifications ADD COLUMN gateway_payload MEDIUMTEXT NULL",
            "ALTER TABLE payment_notifications ADD UNIQUE KEY unq_payhub_reference (payhub_reference)",
        ] as $sql){try{$db->exec($sql);}catch(Throwable){}}
        foreach(['payhub_enabled'=>'0','payhub_public_key'=>'','payhub_secret_key'=>'','payhub_virtual_accounts'=>'1','payhub_auto_approve'=>'1'] as $k=>$v){try{$db->prepare('INSERT INTO security_settings(setting_key,setting_value) VALUES(?,?)')->execute([$k,$v]);}catch(Throwable){}}
    }
    public static function enabled(PDO $db): bool { return self::ready($db) && setting($db,'payhub_enabled')==='1'; }
    public static function ready(PDO $db): bool { return trim(setting($db,'payhub_public_key'))!=='' && trim(setting($db,'payhub_secret_key'))!==''; }
    public static function webhookUrl(): string { return 'https://'.($_SERVER['HTTP_HOST']??'your-domain.example').'/index.php?payhub_webhook=1'; }
    public static function checkoutUrl(string $ref): string { return self::BASE.'/checkout.php?ref='.rawurlencode($ref); }
    public static function autoApproveEnabled(PDO $db): bool { return setting($db,'payhub_virtual_accounts')!=='0'; }
    public static function log(PDO $db,string $event,array $payload=[]): void
    {
        try{
            $dir=ROOT.'/storage/logs';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))return;
            $line=json_encode(['time'=>date('c'),'event'=>$event,'payload'=>$payload],JSON_UNESCAPED_SLASHES);
            @file_put_contents($dir.'/payhub.log',$line."\n",FILE_APPEND|LOCK_EX);
        }catch(Throwable){}
    }
    /** @return array<int,array<string,string>> */
    public static function virtualAccounts(PDO $db): array
    {
        if(!self::enabled($db))return [];
        $secret=trim(setting($db,'payhub_secret_key'));
        $ch=curl_init(self::BASE.'/api/virtual-accounts');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret],CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>10]);
        $response=curl_exec($ch);curl_close($ch);
        $json=json_decode((string)$response,true);$list=[];
        foreach((array)($json['data']??[]) as $ac){if(is_array($ac)&&!empty($ac['account_number']))$list[]=['bank_name'=>(string)($ac['bank_name']??'PayHub Virtual Bank'),'account_number'=>(string)$ac['account_number'],'account_name'=>(string)($ac['account_name']??'REMIT Remittance'),'reference'=>(string)($ac['reference']??'')];}
        return $list;
    }
    /** @return array{reference:string,authorization_url:string} */
    public static function initializeTransaction(PDO $db,int $ledgerId,array $user,int $kobo,string $email,string $name,string $phone): array
    {
        if(!self::enabled($db))throw new RuntimeException('PayHub is not enabled by the administrator.');
        if($kobo<10000)throw new InvalidArgumentException('Online payments require a minimum amount of 100.00 NGN.');
        $secret=trim(setting($db,'payhub_secret_key'));
        $ref='RMT-'.date('YmdHis').'-'.$ledgerId.'-'.bin2hex(random_bytes(3));
        $payload=['email'=>$email,'amount'=>(string)$kobo,'name'=>$name,'phone'=>$phone,'reference'=>$ref,'metadata'=>['ledger_id'=>$ledgerId,'user_id'=>(int)$user['id']]];
        if(setting($db,'payhub_virtual_accounts')!=='0')$payload['channels']=['card','bank_transfer','virtual_account'];
        $ch=curl_init(self::BASE.'/api/transaction/initialize');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Content-Type: application/json','Accept: application/json'],CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>15]);
        $response=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);$error=curl_error($ch);curl_close($ch);
        self::log($db,'initialize_request',['reference'=>$ref,'kobo'=>$kobo,'http'=>$http,'curl_error'=>$error,'response'=>(string)$response]);
        if($errno)throw new RuntimeException('Could not connect to PayHub. Check logs and try manual transfer if needed.');
        $json=json_decode((string)$response,true);
        if(!is_array($json)||empty($json['status'])||!is_array($json['data']??null)||empty($json['data']['reference'])||empty($json['data']['authorization_url']))throw new RuntimeException('PayHub did not return a valid checkout session. Check PayHub logs.');
        $virtual=[];foreach((array)($json['data']['virtual_accounts']??$json['data']['dedicated_account']??[]) as $ac){if(is_array($ac)&&!empty($ac['account_number']))$virtual[]=['bank_name'=>(string)($ac['bank_name']??$ac['bank']??'PayHub Virtual Bank'),'account_number'=>(string)$ac['account_number'],'account_name'=>(string)($ac['account_name']??$ac['customer_name']??'REMIT Remittance')];}
        if(!$virtual)$virtual=self::virtualAccounts($db);
        return ['reference'=>(string)$json['data']['reference'],'authorization_url'=>(string)$json['data']['authorization_url'],'virtual_accounts'=>$virtual,'public_key'=>trim(setting($db,'payhub_public_key'))];
    }
    public static function verify(PDO $db,string $reference): ?array
    {
        if(!self::ready($db))return null;
        $secret=trim(setting($db,'payhub_secret_key'));
        $ch=curl_init(self::BASE.'/api/transaction/verify/'.rawurlencode($reference));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret],CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>10]);
        $response=curl_exec($ch);curl_close($ch);
        $json=json_decode((string)$response,true);
        return is_array($json)?$json:null;
    }
    public static function isPaid(array $verification): bool { return !empty($verification['paid']) || (!empty($verification['data']['paid']) || (!empty($verification['data']['status']) && $verification['data']['status']==='success')); }
    /** @return array{status:string,message:string} */
    public static function handleWebhook(PDO $db): array
    {
        if(!self::ready($db))return ['status'=>'ignored','message'=>'PayHub not configured'];
        $body=(string)file_get_contents('php://input');$sig=(string)($_SERVER['HTTP_X_PAYHUB_SIGNATURE']??'');
        $expected=hash_hmac('sha256',$body,trim(setting($db,'payhub_secret_key')));
        if(!hash_equals($expected,$sig)){http_response_code(401);return ['status'=>'invalid','message'=>'Invalid signature'];}
        $event=json_decode($body,true);
        if(!is_array($event)||($event['event']??'')!=='charge.success'||!is_array($event['data']??null))return ['status'=>'ignored','message'=>'Unhandled event'];
        $data=$event['data'];$ref=(string)($data['reference']??'');
        if($ref==='')return ['status'=>'ignored','message'=>'Missing reference'];
        return self::recordSuccessfulCharge($db,$ref,$data,'webhook');
    }
    /** @return array{status:string,message:string} */
    public static function recordSuccessfulCharge(PDO $db,string $ref,array $data,string $source): array
    {
        $ref=trim($ref);if($ref==='')return ['status'=>'ignored','message'=>'Empty reference'];
        try{$lock=$db->query("SELECT GET_LOCK('remit_payhub_charge',5)")->fetchColumn();if((int)$lock!==1)return ['status'=>'busy','message'=>'Retry later'];}catch(Throwable){return ['status'=>'busy','message'=>'Retry later'];}
        try{
            $s=$db->prepare('SELECT * FROM payment_notifications WHERE payhub_reference=? FOR UPDATE');$s->execute([$ref]);$row=$s->fetch();
            if(!$row){
                $meta=is_array($data['metadata']??null)?$data['metadata']:[];$ledgerId=(int)($meta['ledger_id']??0);$userId=(int)($meta['user_id']??0);
                if($ledgerId<=0)return ['status'=>'ignored','message'=>'Unknown reference'];
                $ledger=$db->prepare('SELECT l.*,h.node_name,h.id parish_id FROM remittance_ledgers l JOIN church_hierarchy h ON h.id=l.parish_id WHERE l.id=?');$ledger->execute([$ledgerId]);$l=$ledger->fetch();
                if(!$l)return ['status'=>'ignored','message'=>'Ledger not found'];
                $kobo=(int)($data['amount']??0);$amount=Money::decimal((int)floor($kobo/100*100));
                $db->prepare('INSERT INTO payment_notifications(ledger_id,submitted_by,amount,transfer_reference,transfer_date,status,payhub_reference,payhub_channel,payhub_paid_at,gateway_payload,review_note) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$ledgerId,$userId?:null,$amount,$ref,date('Y-m-d',strtotime((string)($data['paid_at']??'now'))),'pending',$ref,(string)($data['channel']??'card'),date('Y-m-d H:i:s',strtotime((string)($data['paid_at']??'now'))),json_encode(['source'=>$source,'event'=>$data],JSON_UNESCAPED_SLASHES),'PayHub charge.success · '.$source.' · awaiting treasury review.']);
                return ['status'=>'queued','message'=>'Payment notification created from PayHub webhook'];
            }
            if($row['status']==='pending'){
                $db->prepare('UPDATE payment_notifications SET payhub_channel=COALESCE(?,payhub_channel),payhub_paid_at=COALESCE(?,payhub_paid_at),gateway_payload=?,review_note=? WHERE id=?')->execute([(string)($data['channel']??$row['payhub_channel']),date('Y-m-d H:i:s',strtotime((string)($data['paid_at']??($row['payhub_paid_at']??'now')))),json_encode(['source'=>$source,'event'=>$data],JSON_UNESCAPED_SLASHES),'PayHub confirmed · '.$source.'. Treasury may verify statement before approval.',(int)$row['id']]);
            }
            return ['status'=>'updated','message'=>'PayHub webhook recorded'];
        }finally{$db->query("SELECT RELEASE_LOCK('remit_payhub_charge')");}
    }
}
