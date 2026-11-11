<?php
declare(strict_types=1);
final class PayHub
{
    private const BASE='https://merchant.payhub.com.ng';
    /** @var ?WeakMap<PDO,bool> */
    private static ?WeakMap $feeSchemaChecked=null;
    public static function migrate(PDO $db): void
    {
        ensureNotificationSchema($db);
        foreach([
            "ALTER TABLE payment_notifications ADD COLUMN payhub_reference VARCHAR(100) NULL",
            "ALTER TABLE payment_notifications ADD COLUMN payhub_channel VARCHAR(30) NULL",
            "ALTER TABLE payment_notifications ADD COLUMN payhub_paid_at DATETIME NULL",
            "ALTER TABLE payment_notifications ADD COLUMN gateway_payload MEDIUMTEXT NULL",
            "ALTER TABLE payment_notifications ADD COLUMN payhub_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            "ALTER TABLE payment_notifications ADD COLUMN payhub_net_amount DECIMAL(15,2) NULL",
            "ALTER TABLE payment_notifications ADD UNIQUE KEY unq_payhub_reference (payhub_reference)",
        ] as $sql){try{$db->exec($sql);}catch(Throwable){}}
        self::$feeSchemaChecked ??= new WeakMap();
        self::$feeSchemaChecked[$db]=true;
        foreach(['payhub_enabled'=>'0','payhub_public_key'=>'','payhub_secret_key'=>'','payhub_virtual_accounts'=>'1','payhub_auto_approve'=>'1'] as $k=>$v){try{$db->prepare('INSERT INTO security_settings(setting_key,setting_value) VALUES(?,?)')->execute([$k,$v]);}catch(Throwable){}}
    }
    public static function ensureFeeColumns(PDO $db): void
    {
        self::$feeSchemaChecked ??= new WeakMap();
        if(self::$feeSchemaChecked->offsetExists($db))return;
        try{$db->query('SELECT payhub_fee,payhub_net_amount FROM payment_notifications LIMIT 0');self::$feeSchemaChecked[$db]=true;return;}catch(Throwable){}
        if(method_exists($db,'inTransaction')&&$db->inTransaction())return;
        foreach([
            "ALTER TABLE payment_notifications ADD COLUMN payhub_fee DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            "ALTER TABLE payment_notifications ADD COLUMN payhub_net_amount DECIMAL(15,2) NULL",
        ] as $sql){try{$db->exec($sql);}catch(Throwable){}}
        self::$feeSchemaChecked[$db]=true;
    }
    public static function enabled(PDO $db): bool { return self::ready($db) && setting($db,'payhub_enabled')==='1'; }
    public static function ready(PDO $db): bool { return trim(setting($db,'payhub_public_key'))!=='' && trim(setting($db,'payhub_secret_key'))!==''; }
    public static function webhookUrl(): string { return 'https://'.($_SERVER['HTTP_HOST']??'your-domain.example').'/index.php?payhub_webhook=1'; }
    public static function callbackUrl(int $ledgerId=0): string
    {
        $host=trim((string)($_SERVER['HTTP_HOST']??''));
        if($host===''||!preg_match('/^[a-zA-Z0-9.\-]+(:\d+)?$/D',$host))return '';
        $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https'?'https':'https';
        $qs=['page'=>'remittances','pay'=>'payhub'];
        if($ledgerId>0)$qs['ledger_id']=$ledgerId;
        return $scheme.'://'.$host.'/index.php?'.http_build_query($qs);
    }
    public static function checkoutUrl(string $ref): string { return self::BASE.'/checkout.php?ref='.rawurlencode($ref); }
    public static function autoApproveEnabled(PDO $db): bool { return setting($db,'payhub_auto_approve')!=='0'; }
    private static function redact(mixed $value): mixed
    {
        if(is_array($value)){$out=[];foreach($value as $k=>$v){if(is_string($k)&&preg_match('/secret|token|authorization|password|pin/i',$k))$out[$k]='[REDACTED]';else $out[$k]=self::redact($v);}return $out;}
        if(is_string($value))return preg_replace('/\b(?:sk|pk)_(?:live|test)_[A-Za-z0-9]+/','[REDACTED_KEY]',$value)??$value;
        return $value;
    }
    public static function log(PDO $db,string $event,array $payload=[]): void
    {
        try{
            $dir=ROOT.'/storage/logs';if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))return;
            if(!is_file($dir.'/.htaccess'))@file_put_contents($dir.'/.htaccess',"Require all denied\n");
            $line=json_encode(['time'=>date('c'),'event'=>$event,'payload'=>self::redact($payload)],JSON_UNESCAPED_SLASHES);
            @file_put_contents($dir.'/payhub.log',$line."\n",FILE_APPEND|LOCK_EX);
        }catch(Throwable){}
    }
    /** @return array<int,array{time:string,event:string,payload:array<string,mixed>}> */
    public static function recentLogs(int $limit=40): array
    {
        $file=ROOT.'/storage/logs/payhub.log';
        if(!is_file($file))return [];
        $lines=@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if(!is_array($lines)||!$lines)return [];
        $slice=array_slice($lines,-max(1,$limit));
        $entries=[];
        foreach(array_reverse($slice) as $raw){
            $decoded=json_decode($raw,true);
            if(is_array($decoded))$entries[]=['time'=>(string)($decoded['time']??''),'event'=>(string)($decoded['event']??''),'payload'=>is_array($decoded['payload']??null)?$decoded['payload']:[]];
        }
        return $entries;
    }
    public static function decodeMetadata(mixed $raw): array
    {
        if(is_array($raw))return $raw;
        if(!is_string($raw)||trim($raw)==='')return [];
        $d=json_decode($raw,true);
        if(is_string($d))$d=json_decode($d,true);
        return is_array($d)?$d:[];
    }
    /** @return array<int,array<string,string>> */
    public static function virtualAccounts(PDO $db): array
    {
        if(!self::enabled($db)||!function_exists('curl_init'))return [];
        $secret=trim(setting($db,'payhub_secret_key'));
        $ch=curl_init(self::BASE.'/api/virtual-accounts');
        if($ch===false)return [];
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
        self::log($db,'virtual_accounts_request',[
            'http'=>$http,'curl_error'=>(string)$error,'response'=>(string)$response
        ]);
        if($errno||$http!==200){
            return []; // Logged above, return empty so UI shows "Generating..." or fallback
        }
        $json=json_decode((string)$response,true);
        $list=[];
        if(is_array($json)&&isset($json['data'])&&is_array($json['data'])){
            $accounts=isset($json['data']['account_number'])?[$json['data']]:$json['data'];
            foreach($accounts as $ac){
                if(is_array($ac)&&!empty($ac['account_number'])){
                    $list[]=[
                        'bank_name'=>(string)($ac['bank_name']??$ac['bank']??'PayHub Virtual Bank'),
                        'account_number'=>(string)$ac['account_number'],
                        'account_name'=>(string)($ac['account_name']??$ac['customer_name']??'REMIT Remittance'),
                        'reference'=>(string)($ac['reference']??'')
                    ];
                }
            }
        }
        return $list;
    }
    /** @return array{reference:string,payhub_reference:string,authorization_url:string,virtual_accounts:array<int,array<string,string>>,public_key:string} */
    public static function initializeTransaction(PDO $db,int $ledgerId,array $user,int $kobo,string $email,string $name,string $phone): array
    {
        if(!self::enabled($db))throw new RuntimeException('PayHub is not enabled by the administrator.');
        if($kobo<10000)throw new InvalidArgumentException('Online payments require a minimum amount of 100.00 NGN.');
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required to connect to PayHub.');
        $secret=trim(setting($db,'payhub_secret_key'));
        $ref='RMT-'.date('YmdHis').'-'.$ledgerId.'-'.bin2hex(random_bytes(3));
        $amountNaira=Money::decimal($kobo);
        $payload=['email'=>$email,'amount'=>$amountNaira,'amount_unit'=>'naira','name'=>$name,'phone'=>$phone,'reference'=>$ref,'metadata'=>['ledger_id'=>(int)$ledgerId,'user_id'=>(int)$user['id'],'remit_reference'=>$ref,'amount_kobo'=>(int)$kobo]];
        $cb=self::callbackUrl($ledgerId);
        if($cb!=='')$payload['callback_url']=$cb;
        if(setting($db,'payhub_virtual_accounts')!=='0')$payload['channels']=['card','bank_transfer','virtual_account'];
        $ch=curl_init(self::BASE.'/api/transaction/initialize');
        if($ch===false)throw new RuntimeException('Could not initialize PayHub request.');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($payload),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Content-Type: application/json','Accept: application/json'],
            CURLOPT_TIMEOUT=>30,
            CURLOPT_CONNECTTIMEOUT=>15
        ]);
        $response=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        $error=curl_error($ch);
        curl_close($ch);
        self::log($db,'initialize_request',[
            'reference'=>$ref,'ledger_id'=>$ledgerId,'amount_ngn'=>$amountNaira,'kobo'=>(string)$kobo,'http'=>(string)$http,'curl_error'=>(string)$error,'response'=>(string)$response
        ]);
        if($errno||$http<200||$http>=300){
            $errJson=json_decode((string)$response,true);
            $apiMessage=is_array($errJson)&&!empty($errJson['message'])?(string)$errJson['message']:'';
            $detail=$error!==''?$error:($apiMessage!==''?$apiMessage:'Unknown');
            throw new RuntimeException('Could not connect to PayHub (HTTP '.($http?:'0').'). Check logs and try manual transfer if needed. Error: '.$detail);
        }
        $json=json_decode((string)$response,true);
        if(!is_array($json)||empty($json['status'])||!is_array($json['data']??null)||empty($json['data']['reference'])||empty($json['data']['authorization_url'])){
            self::log($db,'initialize_error',[
                'reference'=>$ref,'ledger_id'=>$ledgerId,'kobo'=>(string)$kobo,'http'=>(string)$http,'response'=>(string)$response,'json'=>(string)json_encode($json)
            ]);
            throw new RuntimeException('PayHub did not return a valid checkout session. Check PayHub logs.');
        }
        $payhubReference=(string)$json['data']['reference'];
        $authUrl=(string)$json['data']['authorization_url'];
        parse_str((string)parse_url($authUrl,PHP_URL_QUERY),$urlQuery);
        if(!empty($urlQuery['ref']))$payhubReference=(string)$urlQuery['ref'];

        $virtual=[];
        // Handle 'virtual_accounts' (list/object), 'virtual_account' (single), and 'dedicated_account' (single) from PayHub API
        $vaList=[];
        if(!empty($json['data']['virtual_accounts'])&&is_array($json['data']['virtual_accounts'])){
            $vaList=isset($json['data']['virtual_accounts']['account_number'])
                ?[$json['data']['virtual_accounts']]
                :$json['data']['virtual_accounts'];
        }elseif(!empty($json['data']['virtual_account'])&&is_array($json['data']['virtual_account'])){
            $vaList=[$json['data']['virtual_account']];
        }elseif(!empty($json['data']['dedicated_account'])&&is_array($json['data']['dedicated_account'])){
            $vaList=[$json['data']['dedicated_account']];
        }

        foreach($vaList as $ac){
            if(is_array($ac)&&!empty($ac['account_number'])){
                $virtual[]=[
                    'bank_name'=>(string)($ac['bank_name']??$ac['bank']??'PayHub Virtual Bank'),
                    'account_number'=>(string)$ac['account_number'],
                    'account_name'=>(string)($ac['account_name']??$ac['customer_name']??'REMIT Remittance'),
                    'reference'=>(string)($ac['reference']??$payhubReference)
                ];
            }
        }

        if(empty($virtual)){
            // Fallback to existing virtual accounts if the initialization didn't return specific ones for this session
            $virtual=self::virtualAccounts($db);
            if($virtual&&empty($virtual[0]['reference']))$virtual[0]['reference']=$payhubReference;
        }

        return [
            'reference'=>(string)$json['data']['reference'],
            'payhub_reference'=>$payhubReference,
            'authorization_url'=>$authUrl,
            'virtual_accounts'=>$virtual,
            'public_key'=>trim(setting($db,'payhub_public_key'))
        ];
    }
    public static function verify(PDO $db,string $reference): ?array
    {
        if(!self::ready($db)||!function_exists('curl_init'))return null;
        $secret=trim(setting($db,'payhub_secret_key'));
        $ch=curl_init(self::BASE.'/api/transaction/verify/'.rawurlencode($reference));
        if($ch===false)return null;
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Accept: application/json'],CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>10]);
        $response=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $errno=curl_errno($ch);
        $error=curl_error($ch);
        curl_close($ch);
        $json=json_decode((string)$response,true);
        $data=is_array($json['data']??null)?$json['data']:[];
        self::log($db,'verify_request',[
            'reference'=>$reference,'http'=>$http,'curl_error'=>$error,'errno'=>$errno,
            'paid'=>is_array($json)?self::isPaid($json):false,
            'status'=>$data['status']??null,'channel'=>$data['channel']??null,
            'amount'=>$data['amount']??null,'gateway_amount'=>$data['gateway_amount']??null,
            'fee_amount'=>$data['fee_amount']??null,'settled_amount'=>$data['settled_amount']??null
        ]);
        return is_array($json)?$json:null;
    }
    public static function isPaid(array $verification): bool { return !empty($verification['paid']) || (!empty($verification['data']['paid']) || (!empty($verification['data']['status']) && $verification['data']['status']==='success')); }
    /** @return array{gross_kobo:int,credit_kobo:int,fee_kobo:int,net_kobo:int,requested_kobo:int} */
    public static function extractAmounts(array $data,int $expectedKobo=0): array
    {
        $parseMinor=static function(mixed $val): int {
            if($val===null||$val===''||!is_numeric($val))return 0;
            $s=trim((string)$val);
            if(str_contains($s,'.')){
                $f=(float)$s;
                return round($f)!=$f ? (int)round($f*100) : (int)round($f);
            }
            return (int)$s;
        };
        $gatewayKobo=$parseMinor($data['gateway_amount']??null);
        $feeKobo=max(0,$parseMinor($data['fee_amount']??null));
        $netKobo=max(0,$parseMinor($data['settled_amount']??null));
        $requestedKobo=max(0,$parseMinor($data['requested_amount']??null));

        if(isset($data['amount_kobo'])&&is_numeric($data['amount_kobo'])){
            $amountKobo=(int)round((float)$data['amount_kobo']);
        }else{
            $raw=$data['amount']??0;
            if(is_string($raw)&&preg_match('/^\d+\.\d{1,2}$/',trim($raw))){
                $amountKobo=Money::cents(trim($raw));
            }elseif(is_float($raw)&&floor($raw)!==$raw){
                $amountKobo=(int)round($raw*100);
            }elseif(is_numeric($raw)){
                $v=(int)round((float)$raw);
                $hasMinorSiblings=isset($data['gateway_amount'])||isset($data['settled_amount'])||isset($data['fee_amount']);
                if($gatewayKobo>0&&$v>0&&$v*100===$gatewayKobo){
                    $amountKobo=$gatewayKobo;
                }elseif(!$hasMinorSiblings&&$v>0&&$v<10000&&($expectedKobo<=0||$v*100<=$expectedKobo*2)){
                    $amountKobo=$v*100;
                }else{
                    $amountKobo=$v;
                }
            }else{
                $amountKobo=0;
            }
        }

        $grossKobo=$gatewayKobo>0?$gatewayKobo:$amountKobo;
        if($grossKobo<=0&&$netKobo>0)$grossKobo=$netKobo+$feeKobo;
        if($grossKobo===$netKobo&&$feeKobo>0&&($expectedKobo<=0||($netKobo+$feeKobo)<=$expectedKobo)){
            $grossKobo=$netKobo+$feeKobo;
        }
        if($feeKobo<=0&&$netKobo>0&&$grossKobo>$netKobo)$feeKobo=$grossKobo-$netKobo;
        if($netKobo<=0&&$grossKobo>0)$netKobo=max(0,$grossKobo-$feeKobo);

        if($requestedKobo>0&&$grossKobo>$requestedKobo){
            $creditKobo=$expectedKobo>0?min($expectedKobo,$requestedKobo):$requestedKobo;
        }else{
            $creditKobo=$expectedKobo>0?min($expectedKobo,$grossKobo):$grossKobo;
        }
        if($netKobo>$creditKobo&&$feeKobo<=0)$netKobo=$creditKobo;
        return [
            'gross_kobo'=>max(0,$grossKobo),
            'credit_kobo'=>max(0,$creditKobo),
            'fee_kobo'=>max(0,$feeKobo),
            'net_kobo'=>max(0,$netKobo),
            'requested_kobo'=>max(0,$requestedKobo),
        ];
    }
    /** @return array{status:string,message:string} */
    public static function handleWebhook(PDO $db): array
    {
        if(!self::ready($db)){self::log($db,'webhook_ignored',['reason'=>'PayHub not configured']);return ['status'=>'ignored','message'=>'PayHub not configured'];}
        $body=(string)file_get_contents('php://input');$sig=(string)($_SERVER['HTTP_X_PAYHUB_SIGNATURE']??'');
        $expected=hash_hmac('sha256',$body,trim(setting($db,'payhub_secret_key')));
        if(!hash_equals($expected,$sig)){
            self::log($db,'webhook_invalid_signature',['ip'=>$_SERVER['REMOTE_ADDR']??'','sig_present'=>$sig!=='','sig_len'=>strlen($sig),'body_len'=>strlen($body)]);
            http_response_code(401);return ['status'=>'invalid','message'=>'Invalid signature'];
        }
        $event=json_decode($body,true);
        if(!is_array($event)||($event['event']??'')!=='charge.success'||!is_array($event['data']??null)){
            self::log($db,'webhook_ignored',['reason'=>'Unhandled or malformed event','event'=>is_array($event)?($event['event']??''):'invalid_json']);
            return ['status'=>'ignored','message'=>'Unhandled event'];
        }
        $data=$event['data'];$ref=(string)($data['reference']??'');
        self::log($db,'webhook_received',[
            'reference'=>$ref,'channel'=>$data['channel']??null,'domain'=>$data['domain']??null,
            'amount'=>$data['amount']??null,'gateway_amount'=>$data['gateway_amount']??null,
            'fee_amount'=>$data['fee_amount']??null,'settled_amount'=>$data['settled_amount']??null
        ]);
        if($ref===''){self::log($db,'webhook_ignored',['reason'=>'Missing reference']);return ['status'=>'ignored','message'=>'Missing reference'];}
        $res=self::recordSuccessfulCharge($db,$ref,$data,'webhook');
        if(in_array($res['status'],['queued','updated'],true)){
            $approved=self::autoApproveIfEnabled($db,$ref,'webhook');
            if($approved)$res['message'].=' (auto-approved)';
        }
        return $res;
    }
    public static function autoApproveIfEnabled(PDO $db,string $ref,string $source='webhook'): bool
    {
        $ref=trim($ref);
        if($ref===''||!self::autoApproveEnabled($db)){
            self::log($db,'charge_pending_manual_review',['reference'=>$ref,'source'=>$source,'auto_approve'=>false]);
            return false;
        }
        try{
            $s=$db->prepare('SELECT * FROM payment_notifications WHERE payhub_reference=?');$s->execute([$ref]);$note=$s->fetch();
            if(!$note)return false;
            if($note['status']==='approved')return true;
            if($note['status']!=='pending')return false;
            $adminId=(int)$db->query("SELECT id FROM users WHERE role IN ('provincial_admin','provincial_treasurer') AND is_suspended=0 ORDER BY CASE WHEN role='provincial_admin' THEN 0 ELSE 1 END, id LIMIT 1")->fetchColumn();
            if($adminId<=0){
                self::log($db,'charge_auto_approve_error',['reference'=>$ref,'source'=>$source,'reason'=>'No active provincial_admin or provincial_treasurer found']);
                return false;
            }
            $reviewer=['id'=>$adminId,'role'=>'provincial_admin','email'=>'system@remit.local'];
            ChurchPortal::reviewPayment($db,$reviewer,[
                'notification_id'=>(int)$note['id'],
                'decision'=>'approved',
                'review_note'=>'Auto-approved by PayHub verified charge/virtual account ('.$source.').',
                'pin'=>'skip',
                'auto'=>'1'
            ]);
            self::log($db,'charge_auto_approved',['notification_id'=>(int)$note['id'],'ledger_id'=>(int)$note['ledger_id'],'reference'=>$ref,'amount'=>$note['amount'],'fee'=>$note['payhub_fee']??'0.00','net'=>$note['payhub_net_amount']??$note['amount'],'source'=>$source]);
            return true;
        }catch(Throwable $e){
            self::log($db,'charge_auto_approve_error',['reference'=>$ref,'source'=>$source,'error'=>$e->getMessage()]);
            return false;
        }
    }
    /** @return array{status:string,message:string} */
    public static function recordSuccessfulCharge(PDO $db,string $ref,array $data,string $source): array
    {
        $ref=trim($ref);if($ref==='')return ['status'=>'ignored','message'=>'Empty reference'];
        self::ensureFeeColumns($db);
        if($source==='webhook'&&!array_key_exists('fee_amount',$data)&&!array_key_exists('settled_amount',$data)&&self::ready($db)){
            $verifyRes=self::verify($db,$ref);
            if(is_array($verifyRes)&&self::isPaid($verifyRes)&&is_array($verifyRes['data']??null)){
                $vData=$verifyRes['data'];
                foreach(['gateway_amount','fee_amount','settled_amount','requested_amount','domain','channel','paid_at'] as $k){
                    if(isset($vData[$k])&&!isset($data[$k]))$data[$k]=$vData[$k];
                }
            }
        }
        $secret=trim(setting($db,'payhub_secret_key'));
        if(str_starts_with($secret,'sk_live_')&&strtolower((string)($data['domain']??''))==='test'){
            self::log($db,'charge_rejected_test_mode',['reference'=>$ref,'source'=>$source]);
            return ['status'=>'ignored','message'=>'Sandbox test payment ignored in live mode'];
        }
        try{$lock=$db->query("SELECT GET_LOCK('remit_payhub_charge',5)")->fetchColumn();if((int)$lock!==1)return ['status'=>'busy','message'=>'Retry later'];}catch(Throwable){return ['status'=>'busy','message'=>'Retry later'];}
        try{
            $meta=self::decodeMetadata($data['metadata']??[]);
            $s=$db->prepare('SELECT * FROM payment_notifications WHERE payhub_reference=? FOR UPDATE');$s->execute([$ref]);$row=$s->fetch();
            if(!$row&&!empty($meta['checkout_reference'])){
                $s=$db->prepare('SELECT * FROM payment_notifications WHERE payhub_reference=? FOR UPDATE');$s->execute([(string)$meta['checkout_reference']]);$row=$s->fetch();
            }
            if(!$row){
                $ledgerId=(int)($meta['ledger_id']??0);$userId=(int)($meta['user_id']??0);
                if($ledgerId<=0){
                    $custEmail=strtolower(trim((string)($data['customer']['email']??($data['email']??''))));
                    if($custEmail!==''){
                        $uStmt=$db->prepare("SELECT id,hierarchy_id,role FROM users WHERE email=? AND role IN ('parish_officer','area_rep','zonal_rep') AND is_suspended=0 LIMIT 1");
                        $uStmt->execute([$custEmail]);$uMatch=$uStmt->fetch();
                        if($uMatch&&!empty($uMatch['hierarchy_id'])){
                            $userId=$userId?:(int)$uMatch['id'];
                            $openStmt=$db->prepare("SELECT id,provincial_levy_due,amount_paid FROM remittance_ledgers WHERE parish_id=? AND status IN ('pending','partial') ORDER BY id DESC");
                            $openStmt->execute([(int)$uMatch['hierarchy_id']]);$openLedgers=$openStmt->fetchAll();
                            $probeAmounts=self::extractAmounts($data,0);
                            $exactMatches=array_values(array_filter($openLedgers,fn($ol)=>(Money::cents($ol['provincial_levy_due'])-Money::cents($ol['amount_paid']))===$probeAmounts['gross_kobo']));
                            if(count($exactMatches)===1)$ledgerId=(int)$exactMatches[0]['id'];
                            elseif(count($openLedgers)===1)$ledgerId=(int)$openLedgers[0]['id'];
                            if($ledgerId>0)self::log($db,'charge_ledger_fallback_matched',['reference'=>$ref,'ledger_id'=>$ledgerId,'email'=>$custEmail]);
                            else self::log($db,'charge_ledger_fallback_ambiguous',['reference'=>$ref,'open_count'=>count($openLedgers),'email'=>$custEmail]);
                        }
                    }
                }
                if($ledgerId<=0){self::log($db,'charge_ignored_unknown_ledger',['reference'=>$ref,'source'=>$source]);return ['status'=>'ignored','message'=>'Unknown reference'];}
                $ledger=$db->prepare('SELECT l.*,h.node_name,h.id parish_id FROM remittance_ledgers l JOIN church_hierarchy h ON h.id=l.parish_id WHERE l.id=?');$ledger->execute([$ledgerId]);$l=$ledger->fetch();
                if(!$l){self::log($db,'charge_ignored_ledger_not_found',['reference'=>$ref,'ledger_id'=>$ledgerId]);return ['status'=>'ignored','message'=>'Ledger not found'];}
                $expected=max(0,Money::cents($l['provincial_levy_due'])-Money::cents($l['amount_paid']));
                if($expected<=0){self::log($db,'charge_ignored_already_settled',['reference'=>$ref,'ledger_id'=>$ledgerId]);return ['status'=>'updated','message'=>'Ledger already fully settled'];}
                $amounts=self::extractAmounts($data,$expected);
                $creditKobo=$amounts['credit_kobo'];$feeKobo=$amounts['fee_kobo'];$netKobo=$amounts['net_kobo'];
                if($creditKobo<=0){self::log($db,'charge_rejected_zero_amount',['reference'=>$ref,'ledger_id'=>$ledgerId,'raw'=>$data['amount']??null]);return ['status'=>'ignored','message'=>'Invalid payment amount'];}
                $submitterId=0;
                if($userId>0){$uChk=$db->prepare('SELECT id FROM users WHERE id=? LIMIT 1');$uChk->execute([$userId]);$submitterId=(int)$uChk->fetchColumn();}
                if($submitterId<=0){$uChk=$db->prepare("SELECT id FROM users WHERE hierarchy_id=? AND role IN ('parish_officer','area_rep','zonal_rep') AND is_suspended=0 ORDER BY id LIMIT 1");$uChk->execute([(int)$l['parish_id']]);$submitterId=(int)$uChk->fetchColumn();}
                if($submitterId<=0){$submitterId=(int)$db->query("SELECT id FROM users WHERE role='provincial_admin' ORDER BY id LIMIT 1")->fetchColumn();}
                $amount=Money::decimal($creditKobo);$feeDecimal=Money::decimal($feeKobo);$netDecimal=Money::decimal($netKobo);
                $paidTs=strtotime((string)($data['paid_at']??'now'))?:time();
                $db->prepare('INSERT INTO payment_notifications(ledger_id,submitted_by,amount,transfer_reference,transfer_date,status,payhub_reference,payhub_channel,payhub_paid_at,gateway_payload,review_note,payhub_fee,payhub_net_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$ledgerId,$submitterId?:1,$amount,$ref,date('Y-m-d',$paidTs),'pending',$ref,(string)($data['channel']??'virtual_account'),date('Y-m-d H:i:s',$paidTs),json_encode(['source'=>$source,'event'=>$data,'extracted'=>$amounts],JSON_UNESCAPED_SLASHES),'PayHub confirmed · '.$source.' · virtual account/card payment.',$feeDecimal,$netDecimal]);
                self::log($db,'charge_recorded',['reference'=>$ref,'ledger_id'=>$ledgerId,'credit'=>$amount,'fee'=>$feeDecimal,'net'=>$netDecimal,'channel'=>(string)($data['channel']??'virtual_account'),'source'=>$source]);
                return ['status'=>'queued','message'=>'Payment notification created from PayHub webhook'];
            }
            $amounts=self::extractAmounts($data,0);
            $feeDecimal=Money::decimal($amounts['fee_kobo']);$netDecimal=Money::decimal($amounts['net_kobo']);
            $existingFeeCents=Money::cents((string)($row['payhub_fee']??'0'));
            $paidTs=strtotime((string)($data['paid_at']??($row['payhub_paid_at']??'now')))?:time();
            if($row['status']==='pending'){
                $db->prepare('UPDATE payment_notifications SET payhub_channel=COALESCE(?,payhub_channel),payhub_paid_at=COALESCE(?,payhub_paid_at),gateway_payload=?,review_note=?,payhub_fee=CASE WHEN ?>0 THEN ? ELSE payhub_fee END,payhub_net_amount=CASE WHEN ?>0 THEN ? ELSE payhub_net_amount END WHERE id=?')->execute([(string)($data['channel']??$row['payhub_channel']),date('Y-m-d H:i:s',$paidTs),json_encode(['source'=>$source,'event'=>$data],JSON_UNESCAPED_SLASHES),'PayHub confirmed · '.$source.'. Treasury may verify statement before approval.',$amounts['fee_kobo'],$feeDecimal,$amounts['net_kobo'],$netDecimal,(int)$row['id']]);
            }elseif($existingFeeCents===0&&$amounts['fee_kobo']>0){
                $db->prepare('UPDATE payment_notifications SET payhub_fee=?,payhub_net_amount=?,gateway_payload=? WHERE id=?')->execute([$feeDecimal,$netDecimal,json_encode(['source'=>$source,'event'=>$data],JSON_UNESCAPED_SLASHES),(int)$row['id']]);
            }
            self::log($db,'charge_updated',['reference'=>$ref,'notification_id'=>(int)$row['id'],'status'=>$row['status'],'fee'=>$feeDecimal,'net'=>$netDecimal,'source'=>$source]);
            return ['status'=>'updated','message'=>'PayHub webhook recorded'];
        }finally{$db->query("SELECT RELEASE_LOCK('remit_payhub_charge')");}
    }
    private static function parseMoneyCents(mixed $val): int
    {
        if($val===null||$val==='')return 0;
        $s=trim((string)$val);
        if($s==='')return 0;
        if(preg_match('/^\d+\.\d{3,}$/',$s))$s=number_format((float)$s,2,'.','');
        return Money::cents($s);
    }
    public static function attachLedgerFees(PDO $db,array &$rows): void
    {
        if(!$rows)return;
        self::ensureFeeColumns($db);
        $ids=array_values(array_unique(array_filter(array_map(fn($r)=>(int)($r['id']??0),$rows))));
        if(!$ids)return;
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $s=$db->prepare("SELECT ledger_id,amount,payhub_fee,payhub_net_amount,payhub_reference FROM payment_notifications WHERE ledger_id IN ($marks) AND status='approved'");
        $s->execute($ids);
        $byLedger=[];
        foreach($s->fetchAll() as $n){
            $lid=(int)$n['ledger_id'];
            if(!isset($byLedger[$lid]))$byLedger[$lid]=['fee'=>0,'gross'=>0,'net'=>0,'count'=>0];
            $amtCents=self::parseMoneyCents($n['amount']??0);
            $feeCents=self::parseMoneyCents($n['payhub_fee']??0);
            $netCents=($n['payhub_net_amount']!==null&&$n['payhub_net_amount']!=='')?self::parseMoneyCents($n['payhub_net_amount']):$amtCents;
            $byLedger[$lid]['fee']+=$feeCents;
            $byLedger[$lid]['gross']+=$amtCents;
            $byLedger[$lid]['net']+=$netCents;
            if(!empty($n['payhub_reference']))$byLedger[$lid]['count']++;
        }
        foreach($rows as &$r){
            $lid=(int)($r['id']??0);$agg=$byLedger[$lid]??null;
            $paidCents=self::parseMoneyCents($r['amount_paid']??'0.00');
            $feeCents=$agg?$agg['fee']:0;
            $notifGrossCents=$agg?$agg['gross']:0;
            $notifNetCents=$agg?$agg['net']:0;
            $directCents=max(0,$paidCents-$notifGrossCents);
            $r['payhub_fee_total']=Money::decimal($feeCents);
            $r['net_settled_total']=Money::decimal($notifNetCents+$directCents);
            $r['has_payhub_payment']=$agg&&($agg['count']>0);
        }
        unset($r);
    }
    /** @return array{gross_confirmed:string,payhub_fee_total:string,net_settled_total:string,payhub_count:int,payhub_gross:string,payhub_net:string} */
    public static function treasuryFeeSummary(PDO $db,array $ids,int $totalPaidCents): array
    {
        self::ensureFeeColumns($db);
        $ids=array_values(array_unique(array_map('intval',$ids)));
        $marks=implode(',',array_fill(0,count($ids),'?'))?:'NULL';
        $s=$db->prepare("SELECT n.amount,n.payhub_fee,n.payhub_net_amount,n.payhub_reference FROM payment_notifications n JOIN remittance_ledgers l ON l.id=n.ledger_id WHERE l.parish_id IN ($marks) AND n.status='approved'");
        $s->execute($ids);
        $feeCents=0;$notifGrossCents=0;$notifNetCents=0;$payhubCount=0;$payhubGrossCents=0;$payhubNetCents=0;
        foreach($s->fetchAll() as $n){
            $amtCents=self::parseMoneyCents($n['amount']??0);
            $fCents=self::parseMoneyCents($n['payhub_fee']??0);
            $nCents=($n['payhub_net_amount']!==null&&$n['payhub_net_amount']!=='')?self::parseMoneyCents($n['payhub_net_amount']):$amtCents;
            $feeCents+=$fCents;$notifGrossCents+=$amtCents;$notifNetCents+=$nCents;
            if(!empty($n['payhub_reference'])){$payhubCount++;$payhubGrossCents+=$amtCents;$payhubNetCents+=$nCents;}
        }
        $directCents=max(0,$totalPaidCents-$notifGrossCents);
        return [
            'gross_confirmed'=>Money::decimal($totalPaidCents),
            'payhub_fee_total'=>Money::decimal($feeCents),
            'net_settled_total'=>Money::decimal($notifNetCents+$directCents),
            'payhub_count'=>$payhubCount,
            'payhub_gross'=>Money::decimal($payhubGrossCents),
            'payhub_net'=>Money::decimal($payhubNetCents),
        ];
    }
}
