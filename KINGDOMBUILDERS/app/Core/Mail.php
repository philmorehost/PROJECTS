<?php
declare(strict_types=1);
namespace KB;

final class Mail {
    public static function queue(string $recipient, string $subject, string $body, string $key): void {
        if (!valid_email($recipient)) throw new \InvalidArgumentException('Invalid notification recipient');
        DB::run('INSERT IGNORE INTO mail_queue(idempotency_key,recipient,subject,body,available_at,created_at) VALUES (?,?,?,?,?,?)', [substr($key,0,160),$recipient,$subject,$body,now(),now()]);
    }
    public static function process(int $limit = 20): array {
        $stats = ['sent'=>0,'failed'=>0];
        if (!Settings::get('smtp_enabled')) return $stats;
        DB::run('UPDATE mail_queue SET status="failed",last_error="Worker lease expired; manual retry required" WHERE status="processing" AND leased_at < ?', [gmdate('Y-m-d H:i:s',time()-900)]);
        for ($i=0; $i<min(50,$limit); $i++) {
            $token = bin2hex(random_bytes(16));
            DB::run('UPDATE mail_queue SET status="processing",lease_token=?,leased_at=?,attempts=attempts+1 WHERE status="pending" AND available_at<=? ORDER BY id LIMIT 1', [$token,now(),now()]);
            $mail = DB::one('SELECT * FROM mail_queue WHERE lease_token=? AND status="processing"', [$token]);
            if (!$mail) break;
            try {
                self::send($mail['recipient'],$mail['subject'],$mail['body']);
                DB::run('UPDATE mail_queue SET status="sent",deliveries=deliveries+1,sent_at=?,lease_token=NULL,leased_at=NULL,last_error=NULL WHERE id=?', [now(),$mail['id']]);
                $stats['sent']++;
            } catch (\Throwable $e) {
                // Never persist SMTP transcripts: they can contain credentials or message content.
                $retry = (int)$mail['attempts'] < 6;
                DB::run('UPDATE mail_queue SET status=?,available_at=?,lease_token=NULL,leased_at=NULL,last_error=? WHERE id=?', [$retry?'pending':'failed',gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**((int)$mail['attempts']-1)))),'SMTP delivery unsuccessful. Check connection, sender and credentials.',$mail['id']]);
                $stats['failed']++;
                error_log('KB mail delivery failed for queue id ' . $mail['id']);
            }
        }
        return $stats;
    }
    public static function send(string $to, string $subject, string $body): void {
        $host = (string)Settings::get('smtp_host');
        $port = (int)Settings::get('smtp_port');
        $security = (string)Settings::get('smtp_security');
        $from = (string)Settings::get('from_email');
        $user = (string)Settings::get('smtp_username');
        $secret = Settings::get('smtp_secret') ? Security::unseal(Settings::get('smtp_secret'))['value'] : '';
        if (!valid_email($to) || !valid_email($from) || $host === '' || preg_match('/[\r\n\/]/', $host)) throw new \RuntimeException('SMTP configuration incomplete');
        if (preg_match('/[\r\n]/', $subject . $user . $to . $from)) throw new \RuntimeException('Invalid mail header');
        if ($security === 'none' && !config('local_preview', false)) throw new \RuntimeException('TLS is required');
        $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'peer_name'=>$host]]);
        $stream = @stream_socket_client(($security==='ssl'?'ssl://':'tcp://') . $host . ':' . $port, $errno, $error, 8, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) throw new \RuntimeException('SMTP connection failed');
        stream_set_timeout($stream, 8);
        try {
            self::expect($stream,[220]);
            self::command($stream,'EHLO localhost',[250]);
            if ($security === 'tls') {
                self::command($stream,'STARTTLS',[220]);
                if (!stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('TLS negotiation failed');
                self::command($stream,'EHLO localhost',[250]);
            }
            if ($user !== '') {
                self::command($stream,'AUTH LOGIN',[334]);
                self::command($stream,base64_encode($user),[334]);
                self::command($stream,base64_encode($secret),[235]);
            }
            self::command($stream,'MAIL FROM:<'.$from.'>',[250]);
            self::command($stream,'RCPT TO:<'.$to.'>',[250,251]);
            self::command($stream,'DATA',[354]);
            $headers = ['Date: '.date('r'),'From: '.self::encodeHeader((string)Settings::get('from_name')).' <'.$from.'>','To: <'.$to.'>','Subject: '.self::encodeHeader($subject),'Message-ID: <'.bin2hex(random_bytes(16)).'@'.preg_replace('/[^a-z0-9.-]/','',Security::domain()).'>','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit'];
            $body = preg_replace('/^\./m','..',str_replace(["\r\n","\r"],"\n",$body));
            fwrite($stream,implode("\r\n",$headers)."\r\n\r\n".$body."\r\n.\r\n");
            self::expect($stream,[250]);
            fwrite($stream,"QUIT\r\n");
        } finally { fclose($stream); }
    }
    private static function encodeHeader(string $value): string {
        $value = str_replace(["\r","\n"],'',$value);
        return preg_match('/[^\x20-\x7e]/',$value) ? '=?UTF-8?B?'.base64_encode($value).'?=' : '"'.addcslashes($value,'\\"').'"';
    }
    private static function command($stream,string $command,array $codes): void { fwrite($stream,$command."\r\n"); self::expect($stream,$codes); }
    private static function expect($stream,array $codes): void {
        $lines = ''; $deadline = time()+10;
        while (($line=fgets($stream,512)) !== false) {
            $lines .= $line;
            if (preg_match('/^(\d{3}) /m',$line,$match)) {
                if (!in_array((int)$match[1],$codes,true)) throw new \RuntimeException('SMTP rejected an operation');
                return;
            }
            if (strlen($lines)>16384 || time()>$deadline) break;
        }
        throw new \RuntimeException('SMTP response timeout');
    }
}
