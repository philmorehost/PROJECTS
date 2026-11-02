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
        for ($i = 0; $i < min(50,$limit); $i++) {
            $token = bin2hex(random_bytes(16));
            DB::run('UPDATE mail_queue SET status="processing",lease_token=?,leased_at=?,attempts=attempts+1 WHERE status="pending" AND available_at<=? ORDER BY id LIMIT 1', [$token,now(),now()]);
            $mail = DB::one('SELECT * FROM mail_queue WHERE lease_token=? AND status="processing"', [$token]);
            if (!$mail) break;
            try {
                self::send($mail['recipient'],$mail['subject'],$mail['body']);
                DB::run('UPDATE mail_queue SET status="sent",deliveries=deliveries+1,sent_at=?,lease_token=NULL,leased_at=NULL,last_error=NULL WHERE id=?', [now(),$mail['id']]);
                $stats['sent']++;
            } catch (\Throwable $e) {
                $safe = $e instanceof MailError ? $e->getMessage() : 'SMTP delivery unsuccessful. Check connection, sender and credentials.';
                $retryable = $e instanceof MailError ? $e->retryable : true;
                $retry = $retryable && (int)$mail['attempts'] < 6;
                DB::run('UPDATE mail_queue SET status=?,available_at=?,lease_token=NULL,leased_at=NULL,last_error=? WHERE id=?', [$retry?'pending':'failed',gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**((int)$mail['attempts']-1)))),mb_substr($safe,0,240),$mail['id']]);
                $stats['failed']++;
                error_log('KB mail delivery failed for queue id ' . $mail['id'] . ($e instanceof MailError ? ' stage=' . $e->stage . ' code=' . ($e->smtpCode ?? 'none') : ''));
            }
        }
        return $stats;
    }

    /** Synchronous, administrator-initiated test. Does not depend on cron or the queue. */
    public static function sendTest(string $to): array {
        if (!Settings::get('smtp_enabled')) throw new MailError('SMTP delivery is disabled. Enable and save SMTP settings first.','configuration',null,false);
        $subject = Settings::get('site_title').' · SMTP test';
        $body = "This is a test notification from your website.\n\nIf you received this message, SMTP delivery is working.\n\nTime: ".now()." UTC";
        $messageId = self::send($to,$subject,$body);
        return ['message_id'=>$messageId];
    }

    public static function send(string $to, string $subject, string $body): string {
        $host = strtolower(trim((string)Settings::get('smtp_host')));
        $port = (int)Settings::get('smtp_port');
        $security = (string)Settings::get('smtp_security');
        $from = trim((string)Settings::get('from_email'));
        $user = (string)Settings::get('smtp_username');
        try { $secret = Settings::get('smtp_secret') ? (string) Security::unseal((string) Settings::get('smtp_secret'))['value'] : ''; }
        catch (\Throwable) { throw new MailError('The saved SMTP password could not be decrypted. Re-enter it and save the settings.','configuration',null,false); }
        if (!valid_email($to) || !valid_email($from) || $host === '' || preg_match('/[\r\n\/\s]/', $host) || $port < 1 || $port > 65535) {
            throw new MailError('SMTP configuration is incomplete. Check host, port and sender email.','configuration',null,false);
        }
        if (preg_match('/[\r\n]/', $subject . $user . $to . $from)) throw new MailError('Invalid mail header.','configuration',null,false);
        if (!in_array($security,['tls','ssl','none'],true) || ($security === 'none' && !config('local_preview', false))) {
            throw new MailError('TLS is required for SMTP outside a local preview.','configuration',null,false);
        }
        $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'peer_name'=>$host,'capture_peer_cert'=>false]]);
        $address = ($security==='ssl'?'ssl://':'tcp://') . $host . ':' . $port;
        $stream = @stream_socket_client($address, $errno, $error, 6, STREAM_CLIENT_CONNECT, $context);
        if (!$stream) {
            throw new MailError('Could not connect to '.$host.':'.$port.'. Check the host/port, firewall, and whether your host allows outbound SMTP.','connection');
        }
        stream_set_timeout($stream, 6);
        try {
            self::expect($stream,[220],'greeting');
            $caps = self::ehlo($stream);
            if ($security === 'tls') {
                if (!isset($caps['STARTTLS'])) throw new MailError('The server does not advertise STARTTLS on this port. Try implicit TLS on port 465 if your provider supports it.','starttls',null,false);
                self::command($stream,'STARTTLS',[220],'starttls');
                $ok = @stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($ok !== true) throw new MailError('TLS negotiation failed. Check the port/security mode and server certificate.','tls',null,false);
                $caps = self::ehlo($stream);
            }
            if ($user !== '') {
                $auth = $caps['AUTH'] ?? [];
                if (in_array('PLAIN',$auth,true)) {
                    self::command($stream,'AUTH PLAIN '.base64_encode("\0".$user."\0".$secret),[235],'auth');
                } elseif (in_array('LOGIN',$auth,true)) {
                    self::command($stream,'AUTH LOGIN',[334],'auth');
                    self::command($stream,base64_encode($user),[334],'auth');
                    self::command($stream,base64_encode($secret),[235],'auth');
                } else {
                    throw new MailError('The server does not offer a supported AUTH method (PLAIN/LOGIN). Check the port or ask your email provider.','auth',null,false);
                }
            }
            self::command($stream,'MAIL FROM:<'.$from.'>',[250],'sender');
            self::command($stream,'RCPT TO:<'.$to.'>',[250,251],'recipient');
            self::command($stream,'DATA',[354],'data');
            $messageId = bin2hex(random_bytes(16)).'@'.preg_replace('/[^a-z0-9.-]/','',Security::domain());
            $headers = ['Date: '.date('r'),'From: '.self::encodeHeader((string)Settings::get('from_name')).' <'.$from.'>','To: <'.$to.'>','Subject: '.self::encodeHeader($subject),'Message-ID: <'.$messageId.'>','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit'];
            $body = preg_replace('/^\./m','..',str_replace(["\r\n","\r"],"\n",$body));
            self::write($stream,implode("\r\n",$headers)."\r\n\r\n".$body."\r\n.\r\n");
            self::expect($stream,[250],'data');
            @fwrite($stream,"QUIT\r\n");
            return $messageId;
        } finally { fclose($stream); }
    }

    private static function ehlo($stream): array {
        $name = preg_replace('/[^a-z0-9.-]/','',Security::domain()) ?: 'localhost';
        self::write($stream,'EHLO '.$name."\r\n");
        [$code,$lines] = self::readResponse($stream,'ehlo');
        if ($code !== 250) throw new MailError('The SMTP server rejected EHLO.','ehlo',$code);
        $caps = [];
        foreach (array_slice($lines,1) as $line) {
            $parts = preg_split('/\s+/',trim($line));
            $key = strtoupper(array_shift($parts) ?? '');
            if ($key !== '') $caps[$key] = array_map('strtoupper',$parts);
        }
        return $caps;
    }

    private static function encodeHeader(string $value): string {
        $value = str_replace(["\r","\n"],'',$value);
        return preg_match('/[^\x20-\x7e]/',$value) ? '=?UTF-8?B?'.base64_encode($value).'?=' : '"'.addcslashes($value,'\\"').'"';
    }
    private static function write($stream,string $data): void {
        $written = @fwrite($stream,$data);
        if ($written === false || $written < strlen($data)) throw new MailError('The SMTP connection closed while sending a command.','connection');
    }
    private static function command($stream,string $command,array $codes,string $stage): void {
        self::write($stream,$command."\r\n");
        [$code] = self::readResponse($stream,$stage);
        if (!in_array($code,$codes,true)) self::throwStage($stage,$code);
    }
    private static function expect($stream,array $codes,string $stage): void {
        [$code] = self::readResponse($stream,$stage);
        if (!in_array($code,$codes,true)) self::throwStage($stage,$code);
    }
    /** @return array{0:int,1:array<int,string>} */
    private static function readResponse($stream,string $stage): array {
        $lines = []; $deadline = time()+8; $code = 0;
        while (($line=fgets($stream,1024)) !== false) {
            $lines[] = rtrim($line,"\r\n");
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') return [$code,$lines];
            }
            if (strlen(implode('', $lines)) > 16384 || time() > $deadline) break;
        }
        $meta = stream_get_meta_data($stream);
        if (!empty($meta['timed_out'])) throw new MailError('The SMTP server timed out during '.$stage.'. Check the port/security mode and provider status.','timeout');
        throw new MailError('The SMTP server closed the connection or returned an invalid response during '.$stage.'.','protocol',$code ?: null);
    }
    private static function throwStage(string $stage,int $code): never {
        $messages = [
            'greeting'=>'The SMTP server rejected the initial connection.',
            'starttls'=>'The SMTP server rejected STARTTLS. Try the provider’s recommended port/security mode.',
            'auth'=>'SMTP authentication failed. Check the username and app password.',
            'sender'=>'The SMTP server rejected the sender address. Use a mailbox authorized by your provider.',
            'recipient'=>'The SMTP server rejected the test recipient address.',
            'data'=>'The SMTP server rejected the message content.',
        ];
        $retryable = !in_array($stage,['auth','sender','recipient'],true) && $code < 500;
        throw new MailError(($messages[$stage] ?? 'The SMTP server rejected the request.').' (SMTP '.$code.')',$stage,$code,$retryable);
    }
}
