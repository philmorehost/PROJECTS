<?php
declare(strict_types=1);
namespace KB;

final class Mail {
    public static function queue(string $recipient, string $subject, string $body, string $key): void {
        if (!valid_email($recipient)) throw new \InvalidArgumentException('Invalid notification recipient');
        DB::run('INSERT IGNORE INTO mail_queue(idempotency_key,recipient,subject,body,available_at,created_at) VALUES (?,?,?,?,?,?)', [substr($key,0,160),$recipient,$subject,$body,now(),now()]);
    }

    /**
     * Deliver queued messages. A deadline lets the web-triggered path stop
     * cleanly before PHP's request time limit instead of being killed mid-send.
     */
    public static function process(int $limit = 20, ?int $deadline = null): array {
        $stats = ['sent'=>0,'failed'=>0,'processed'=>0,'pending'=>0];
        if (!Settings::get('smtp_enabled')) {
            $stats['pending'] = (int) DB::value('SELECT COUNT(*) FROM mail_queue WHERE status IN ("pending","failed")');
            return $stats;
        }
        DB::run('UPDATE mail_queue SET status="failed",last_error="Worker lease expired; manual retry required" WHERE status="processing" AND leased_at < ?', [gmdate('Y-m-d H:i:s',time()-900)]);
        for ($i = 0; $i < min(50,$limit); $i++) {
            if ($deadline !== null && time() >= $deadline) break;
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
            $stats['processed']++;
        }
        $stats['pending'] = (int) DB::value('SELECT COUNT(*) FROM mail_queue WHERE status IN ("pending","failed")');
        return $stats;
    }

    /** Attempt one specific queued message immediately and report the real result. */
    public static function deliver(int $id): array {
        $mail = DB::one('SELECT * FROM mail_queue WHERE id=?', [$id]);
        if (!$mail) return ['ok'=>false,'message'=>'That message no longer exists.'];
        if ($mail['status'] === 'sent') return ['ok'=>true,'message'=>'That message was already delivered.'];
        if (!Settings::get('smtp_enabled')) return ['ok'=>false,'message'=>'SMTP delivery is disabled. Enable it on Email & SMTP first.'];
        $token = bin2hex(random_bytes(16));
        DB::run('UPDATE mail_queue SET status="processing",lease_token=?,leased_at=?,attempts=attempts+1 WHERE id=?', [$token,now(),$id]);
        try {
            self::send($mail['recipient'],$mail['subject'],$mail['body']);
            DB::run('UPDATE mail_queue SET status="sent",deliveries=deliveries+1,sent_at=?,lease_token=NULL,leased_at=NULL,last_error=NULL WHERE id=?', [now(),$id]);
            return ['ok'=>true,'message'=>'Delivered to '.$mail['recipient'].'.'];
        } catch (\Throwable $e) {
            $safe = $e instanceof MailError ? $e->getMessage() : 'Delivery could not be completed. Check the server error log.';
            DB::run('UPDATE mail_queue SET status=? ,available_at=?,lease_token=NULL,leased_at=NULL,last_error=? WHERE id=?', ['failed',now(),mb_substr($safe,0,240),$id]);
            return ['ok'=>false,'message'=>$safe];
        }
    }

    /** Synchronous, administrator-initiated test. Does not depend on cron or the queue. */
    public static function sendTest(string $to): array {
        if (!Settings::get('smtp_enabled')) throw new MailError('SMTP delivery is disabled. Enable and save SMTP settings first.','configuration',null,false);
        $subject = Settings::get('site_title').' · SMTP test';
        $body = "This is a test notification from your website.\n\nIf you received this message, SMTP delivery is working and activity email can be delivered.\n\nHost: ".Settings::get('smtp_host')."\nPort: ".(int)Settings::get('smtp_port')."\nSecurity: ".strtoupper((string)Settings::get('smtp_security'))."\nTime: ".now()." UTC";
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
            if ($user !== '') self::authenticateUser($stream,$caps,$user,$secret);
            self::command($stream,'MAIL FROM:<'.$from.'>',[250],'sender');
            self::command($stream,'RCPT TO:<'.$to.'>',[250,251],'recipient');
            self::command($stream,'DATA',[354],'data');
            $messageId = bin2hex(random_bytes(16)).'@'.preg_replace('/[^a-z0-9.-]/','',Security::domain());
            self::write($stream,self::stuff(self::message($to,$subject,$body,$from,(string)Settings::get('from_name'),$messageId)).".\r\n");
            self::expect($stream,[250],'data');
            @fwrite($stream,"QUIT\r\n");
            return $messageId;
        } finally { fclose($stream); }
    }

    /**
     * Build a complete RFC 5322 message: multipart/alternative with the queued
     * plain text and a modern HTML rendering of the same content.
     *
     * Public so the MIME structure can be tested without opening a socket.
     * Dot-stuffing is applied by send() at the SMTP boundary, not here.
     */
    public static function message(string $to,string $subject,string $body,string $from,string $fromName,?string $messageId=null): string {
        $messageId ??= bin2hex(random_bytes(16)).'@'.preg_replace('/[^a-z0-9.-]/','',Security::domain());
        $boundary = '=_kb_'.bin2hex(random_bytes(12));
        $html = MailTemplate::html($subject,$body,$to);
        $plain = preg_replace('/\r?\n/',"\r\n",str_replace(["\r\n","\r"],"\n",$body)) ?? $body;
        $quoted = preg_replace('/\r?\n/',"\r\n",quoted_printable_encode($html)) ?? '';
        $headers = [
            'Date: '.date('r'),
            'From: '.self::encodeHeader($fromName).' <'.$from.'>',
            'To: <'.$to.'>',
            'Subject: '.self::encodeHeader($subject),
            'Message-ID: <'.$messageId.'>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="'.$boundary.'"',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        $payload = "--{$boundary}\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: 8bit\r\n\r\n"
            .$plain."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: text/html; charset=UTF-8\r\n"
            ."Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            .$quoted."\r\n"
            ."--{$boundary}--\r\n";
        return implode("\r\n",$headers)."\r\n\r\n".$payload;
    }

    /**
     * SMTP transparency: a line beginning with a dot is doubled on the wire and
     * the receiving server removes the extra dot. Public for the unit tests.
     */
    public static function stuff(string $message): string {
        return preg_replace('/^\./m','..',$message) ?? $message;
    }

    private static function ehlo($stream): array {
        $name = preg_replace('/[^a-z0-9.-]/','',Security::domain()) ?: 'localhost';
        self::write($stream,'EHLO '.$name."\r\n");
        [$code,$lines] = self::readResponse($stream,'ehlo');
        if ($code !== 250) throw new MailError('The SMTP server rejected EHLO.','ehlo',$code);
        return self::parseEhlo($lines);
    }

    /**
     * Turn raw EHLO reply lines into capability => mechanism lists.
     *
     * Servers send "250-AUTH PLAIN LOGIN" and "250-STARTTLS"; the numeric reply
     * code is part of the line and must be removed before the capability name is
     * read, otherwise every capability is stored as "250-AUTH" and authentication
     * appears to be unsupported. AUTH may also be written "AUTH=PLAIN LOGIN",
     * spelled in lower case, or split over more than one line.
     *
     * @param array<int,string> $lines Raw reply lines, including the reply code.
     * @return array<string,array<int,string>>
     */
    public static function parseEhlo(array $lines): array {
        $caps = [];
        foreach ($lines as $index => $line) {
            $line = preg_replace('/^\d{3}[ -]/','',trim((string)$line)) ?? '';
            if ($line === '') continue;
            $parts = preg_split('/\s+/',$line) ?: [];
            $key = strtoupper(array_shift($parts) ?? '');
            if ($key === '') continue;
            $isAuth = $key === 'AUTH' || str_starts_with($key,'AUTH=');
            // The first line is the greeting ("mail.example.com ESMTP …"), not a capability.
            if ($index === 0 && !$isAuth && $key !== 'STARTTLS') continue;
            if (str_starts_with($key,'AUTH=')) { $parts = array_merge([substr($key,5)],$parts); $isAuth = true; }
            $values = array_values(array_filter(array_map('strtoupper',$parts),'strlen'));
            if ($isAuth) $caps['AUTH'] = array_values(array_unique(array_merge($caps['AUTH'] ?? [],$values)));
            elseif (!isset($caps[$key])) $caps[$key] = $values;
        }
        return $caps;
    }

    /**
     * Mechanisms to try, in preference order. PLAIN needs one round trip; LOGIN is
     * used when PLAIN is unavailable. When a server hides AUTH from its EHLO reply
     * both are probed, because some providers only accept credentials without
     * advertising them.
     *
     * @param array<int,string> $advertised Mechanisms from the AUTH capability.
     * @return array<int,string>
     */
    public static function authCandidates(array $advertised): array {
        if ($advertised === []) return ['PLAIN','LOGIN'];
        $usable = array_values(array_intersect(['PLAIN','LOGIN'],$advertised));
        if ($usable === []) {
            throw new MailError('The server offers only '.implode('/',array_slice($advertised,0,4)).' sign-in, which this mailer does not support. Ask your provider for SMTP authentication with PLAIN or LOGIN.','auth',null,false);
        }
        return $usable;
    }

    /** @param array<string,array<int,string>> $caps */
    private static function authenticateUser($stream,array $caps,string $user,string $secret): void {
        $candidates = self::authCandidates($caps['AUTH'] ?? []);
        $last = null;
        foreach ($candidates as $mechanism) {
            try { self::authenticate($stream,$mechanism,$user,$secret); return; }
            catch (MailError $e) {
                $code = $e->smtpCode;
                // 530/538: the provider wants TLS before any credentials are accepted.
                if ($code === 530 || $code === 538) throw new MailError('The server requires an encrypted connection before signing in. Use STARTTLS on port 587 or SSL on port 465.','auth',$code,false);
                // 500/504: this mechanism or command is not implemented here; try the next one.
                if ($code === 500 || $code === 504) { $last = $e; continue; }
                throw $e;
            }
        }
        $code = $last instanceof MailError ? $last->smtpCode : null;
        throw new MailError('The server did not accept '.implode(' or ',$candidates).' sign-in'.($code ? ' (SMTP '.$code.')' : '').'. Check the username, app password and security mode.','auth',$code,false);
    }

    private static function authenticate($stream,string $mechanism,string $user,string $secret): void {
        if ($mechanism === 'PLAIN') {
            self::command($stream,'AUTH PLAIN '.base64_encode("\0".$user."\0".$secret),[235],'auth');
            return;
        }
        self::command($stream,'AUTH LOGIN',[334],'auth');
        self::command($stream,base64_encode($user),[334],'auth');
        self::command($stream,base64_encode($secret),[235],'auth');
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
