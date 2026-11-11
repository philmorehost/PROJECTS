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
        $ch=curl_init(self::BASE.'/api/virtual-accounts');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Content-Type: application/json','Accept: application/json'],
            CURLOPT_TIMEOUT=>20,
            CURLOPT_CONNECTTIMEOUT=>10
        ]);
        $response=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        $error=curl_error($ch);
        curl_close($ch);
        PayHub::log($db,'virtual_accounts_request',[
            'http'=>$http,'curl_error'=>(string)$error,'response'=>(string)$response
        ]);
        if($errno||$http!==200) {
            return []; // Logged above, return empty so UI shows "Generating..." or fallback
        }
        $json=json_decode((string)$response,true);
        $list=[];
        // Check if data is an array and contains account_number
        if(is_array($json)&&isset($json['data'])&&is_array($json['data'])) {
            foreach($json['data'] as $ac){
                if(is_array($ac)&&!empty($ac['account_number'])) {
                    $list[]=[
                        'bank_name'=>(string)($ac['bank_name']??'PayHub Virtual Bank'),
                        'account_number'=>(string)$ac['account_number'],
                        'account_name'=>(string)($ac['account_name']??'REMIT Remittance'),
                        'reference'=>(string)($ac['reference']??'')
    /** @return array<int,array<string,string>> */
    public static function virtualAccounts(PDO $db): array
    {
        if(!self::enabled($db))return [];
        $secret=trim(setting($db,'payhub_secret_key'));
        $ch=curl_init(self::BASE.'/api/virtual-accounts');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.trim($secret),'Content-Type: application/json','Accept: application/json'],
            CURLOPT_TIMEOUT=>20,
            CURLOPT_CONNECTTIMEOUT=>10
        ]);
        $response=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        $error=curl_error($ch);
        curl_close($ch);
        PayHub::log($db,'virtual_accounts_request',[
            'http'=>(string)$http,'curl_error'=>(string)$error,'response'=>(string)$response
        ]);
        if($errno||$http!==200) {
            return []; // Logged above, return empty so UI shows "Generating..." or fallback
        }
        $json=json_decode((string)$response,true);
        $list=[];
        // Check if data is an array and contains account_number
        if(is_array($json)&&isset($json['data'])&&is_array($json['data'])) {
            foreach($json['data'] as $ac){
                if(is_array($ac)&&!empty($ac['account_number'])) {
                    $list[]=[
                        'bank_name'=>(string)($ac['bank_name']??'PayHub Virtual Bank'),
                        'account_number'=>(string)$ac['account_number'],
                        'account_name'=>(string)($ac['account_name']??'REMIT Remittance'),
                        'reference'=>(string)($ac['reference']??'')
                    ];
                }
            }
        }
        return $list;
    }
    /** @return array{reference:string,authorization_url:string} */
    public static function initializeTransaction(PDO $db,int $ledgerId,array $user,int $kobo,string $email,string $name,string $phone): array
    {
        if(!self::enabled($db))throw new RuntimeException('PayHub is not enabled by the administrator.');
        if($kobo<10000)throw new InvalidArgumentException('Online payments require a minimum amount of 100.00 NGN.');
        $secret=trim(setting($db,'payhub_secret_key'));
        $ref='RMT-'.date('YmdHis').'-'.$ledgerId.'-'.bin2hex(random_bytes(3));
        $payload=['email'=>$email,'amount'=>(string)$kobo,'name'=>$name,'phone'=>$phone,'reference'=>$ref,'metadata'=>['ledger_id'=>(int)$ledgerId,'user_id'=>(int)$user['id']]];
        if(setting($db,'payhub_virtual_accounts')!=='0')$payload['channels']=['card','bank_transfer','virtual_account'];
        $ch=curl_init(self::BASE.'/api/transaction/initialize');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.trim($secret),'Content-Type: application/json','Accept: application/json'],
            CURLOPT_TIMEOUT=>30,
            CURLOPT_CONNECTTIMEOUT=>15
        ]);
        $response=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        $error=curl_error($ch);
        curl_close($ch);
        PayHub::log($db,'initialize_request',[
            'reference'=>(string)$ref,'kobo'=>(string)$kobo,'http'=>(string)$http,'curl_error'=>(string)$error,'response'=>(string)$response
        ]);
        if($errno||$http<200||$http>=300) {
            throw new RuntimeException('Could not connect to PayHub (HTTP '.($http?:'0').'). Check logs and try manual transfer if needed. Error: '.($error?:'Unknown'));
        }
        $json=json_decode((string)$response,true);
        if(!is_array($json)||empty($json['status'])||!is_array($json['data']??null)||empty($json['data']['reference'])||empty($json['data']['authorization_url'])) {
            PayHub::log($db,'initialize_error',[
                'reference'=>(string)$ref,'kobo'=>(string)$kobo,'http'=>(string)$http,'response'=>(string)$response,'json'=>(string)json_encode($json)
            ]);
            throw new RuntimeException('PayHub did not return a valid checkout session. Check PayHub logs.');
        }
        $payhubReference=(string)$json['data']['reference'];
        $authUrl=(string)$json['data']['authorization_url'];
        parse_str((string)parse_url($authUrl,PHP_URL_QUERY),$urlQuery);
        if(!empty($urlQuery['ref']))$payhubReference=(string)$urlQuery['ref'];
        
        $virtual=[];
        // Handle both 'virtual_accounts' (list) and 'dedicated_account' (single) from PayHub API
        $vaList = (array)($json['data']['virtual_accounts']??[]);
        if(empty($vaList) && isset($json['data']['dedicated_account'])) {
            $vaList = [(array)$json['data']['dedicated_account']];
        }
        
        foreach($vaList as $ac){
            if(is_array($ac)&&!empty($ac['account_number'])) {
                $virtual[]=[\n                    'bank_name'=>(string)($ac['bank_name']??$ac['bank']??'PayHub Virtual Bank'),\n                    'account_number'=>(string)$ac['account_number'],\n                    'account_name'=>(string)($ac['account_name']??$ac['customer_name']??'REMIT Remittance'),\n                    'reference'=>(string)($ac['reference']??$payhubReference)\n                ];\n            }\n        }\n        \n        if(empty($virtual)) {\n            // Fallback to existing virtual accounts if the initialization didn't return specific ones for this session\n            $virtual=self::virtualAccounts($db);\n            if($virtual&&empty($virtual[0]['reference']))$virtual[0]['reference']=$$payhubReference;\n        }\n        \n        return [\n            'reference'=>(string)$json['data']['reference'],\n            'payhub_reference'=>(string)$payhubReference,\n            'authorization_url'=>(string)$authUrl,\n            'virtual_accounts'=>$virtual,\n            'public_key'=>(string)trim(setting($db,'payhub_public_key'))\n        ];\n    }
    public static function verify(PDO $db,string $reference): ?array
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
