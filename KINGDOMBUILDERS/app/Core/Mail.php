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
                error_log('KB mail delivery failed for queue id ' . $mail['id'] . ($e instanceof MailError
                    ? ' stage=' . $e->stage . ' code=' . ($e->smtpCode ?? 'none') . ' message=' . $safe
                    : ' message=' . $safe));
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
            throw self::withTransportHint(new MailError('Could not connect to '.$host.':'.$port.'. Check the host/port, firewall, and whether your host allows outbound SMTP.','connection'),$host,$port,$security);
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
        } catch (MailError $e) {
            throw self::withTransportHint($e,$host,$port,$security);
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

    /** Explain a transport failure using the port and security mode that were used. */
    private static function withTransportHint(MailError $error,string $host,int $port,string $security): MailError {
        $message = $error->getMessage();
        $timedOut = str_contains($message,'did not answer');
        $closed = str_contains($message,'closed the connection');
        $refused = str_contains($message,'Could not connect');
        if (!$timedOut && !$closed && !$refused) return $error;
        $mode = self::securityLabel($security);
        $hint = $refused
            ? 'Nothing accepted a connection on '.$host.':'.$port.' using '.$mode.' — the port is closed, blocked by the host firewall, or not the port this mail server listens on.'
            : ($timedOut
                ? 'No reply on '.$host.':'.$port.' using '.$mode.' — the connection opened but the server stayed silent.'
                : 'The server at '.$host.':'.$port.' closed the connection before sending a reply ('.$mode.').');
        if ($port === 465 && $security !== 'ssl') {
            $hint .= ' Port 465 speaks TLS from the first byte: set Connection security to “Implicit TLS (usually port 465)”, or use port 587 with STARTTLS.';
        } elseif ($port === 587 && $security === 'ssl') {
            $hint .= ' Port 587 starts unencrypted and upgrades with STARTTLS: set Connection security to “STARTTLS (usually port 587)”, or use port 465 with implicit TLS.';
        } elseif ($port === 25) {
            $hint .= ' Many hosts block outbound port 25 for new accounts: use 587 (STARTTLS) or 465 (implicit TLS) instead.';
        } else {
            $hint .= ' Check the port and security pair, and whether your host allows outbound SMTP on that port.';
        }
        return new MailError(rtrim($error->getMessage(),'.').'. '.$hint.' Run “Diagnose connection” on the Email & SMTP page to see which port answers.',$error->stage,$error->smtpCode,$error->retryable);
    }

    private static function securityLabel(string $security): string {
        return match ($security) {
            'ssl' => 'implicit TLS',
            'tls' => 'STARTTLS',
            'none' => 'no encryption',
            default => $security,
        };
    }

    /**
     * Network diagnosis for the Email & SMTP page. Opens the configured port and
     * the usual alternatives, reads the SMTP greeting and capabilities, and never
     * sends credentials. Each probe is bounded so the request stays responsive.
     *
     * @return array{host:string,ips:array<int,string>,resolved:bool,configured:array<string,mixed>,probes:array<int,array<string,mixed>>,recommendation:array<string,mixed>}
     */
    public static function diagnose(?string $host = null, ?int $port = null, ?string $security = null): array {
        $host = strtolower(trim($host ?? (string) Settings::get('smtp_host')));
        $port = $port ?? (int) Settings::get('smtp_port');
        $security = $security ?? (string) Settings::get('smtp_security');
        if ($host === '' || preg_match('/[\r\n\/\s]/', $host)) {
            throw new MailError('Enter the SMTP host on this page before diagnosing the connection.','configuration',null,false);
        }
        $ips = self::resolveHost($host);
        $pairs = [[$port,$security]];
        foreach ([[465,'ssl'],[587,'tls'],[25,'tls']] as $alternate) {
            if (count($pairs) >= 3) break;
            if ($alternate[0] === $port) continue;
            $pairs[] = $alternate;
        }
        $probes = [];
        foreach ($pairs as $index=>[$candidatePort,$candidateSecurity]) {
            $probe = self::probeHost($host,(int)$candidatePort,(string)$candidateSecurity,4);
            $probe['configured'] = $index === 0;
            $probe['port'] = (int)$candidatePort;
            $probe['security'] = (string)$candidateSecurity;
            $probe['label'] = self::securityLabel((string)$candidateSecurity);
            $probes[] = $probe;
            // A working configuration makes the remaining probes unnecessary.
            if ($index === 0 && $probe['ok'] === true) break;
        }
        return [
            'host' => $host,
            'ips' => $ips,
            'resolved' => $ips !== [],
            'configured' => ['port' => $port, 'security' => $security, 'label' => self::securityLabel($security)],
            'probes' => $probes,
            'recommendation' => self::recommendation($probes,$ips,$port,$security),
        ];
    }

    /** @return array<int,string> */
    private static function resolveHost(string $host): array {
        $ips = [];
        if (function_exists('dns_get_record')) {
            foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type=>$key) {
                $records = @dns_get_record($host,$type);
                if (is_array($records)) foreach ($records as $record) if (!empty($record[$key])) $ips[] = (string) $record[$key];
            }
        }
        if ($ips === [] && filter_var($host,FILTER_VALIDATE_IP)) $ips[] = $host;
        if ($ips === [] && filter_var($host,FILTER_VALIDATE_IP) === false) {
            $address = @gethostbyname($host);
            if (is_string($address) && $address !== $host && filter_var($address,FILTER_VALIDATE_IP)) $ips[] = $address;
        }
        return array_values(array_unique($ips));
    }

    /** @return array<string,mixed> */
    private static function probeHost(string $host,int $port,string $security,float $timeout): array {
        $started = microtime(true);
        $result = ['ok'=>false,'stage'=>'connection','detail'=>'','capabilities'=>[],'banner'=>'','ms'=>0];
        if ($port < 1 || $port > 65535) {
            $result['detail'] = 'That port number is not valid.';
            return $result;
        }
        $context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'peer_name'=>$host]]);
        $address = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0; $error = '';
        $stream = @stream_socket_client($address,$errno,$error,$timeout,STREAM_CLIENT_CONNECT,$context);
        $result['ms'] = (int) round((microtime(true) - $started) * 1000);
        if (!$stream) {
            $result['detail'] = self::connectFailure($errno,$error,$host,$port,$security);
            return $result;
        }
        stream_set_timeout($stream,(int) ceil($timeout));
        try {
            [$code,$lines] = self::readResponse($stream,'greeting');
            $result['banner'] = mb_substr((string) end($lines),0,120);
            if ($code !== 220) {
                $result['stage'] = 'greeting';
                $result['detail'] = 'The port answered, but not with an SMTP greeting (reply '.$code.'). This is probably not a mail submission port.';
                return $result;
            }
            $caps = self::ehlo($stream);
            $result['capabilities'] = array_keys($caps);
            if ($security === 'tls') {
                if (!isset($caps['STARTTLS'])) {
                    $result['stage'] = 'starttls';
                    $result['detail'] = 'The server does not offer STARTTLS on this port. Use port 465 with implicit TLS if your provider supports it.';
                    return $result;
                }
                self::command($stream,'STARTTLS',[220],'starttls');
                if (@stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    $result['stage'] = 'tls';
                    $result['detail'] = 'STARTTLS was accepted but the TLS handshake failed. The certificate may be invalid for this hostname.';
                    return $result;
                }
                $caps = self::ehlo($stream);
                $result['capabilities'] = array_keys($caps);
            }
            $result['ok'] = true;
            $result['stage'] = 'ready';
            $result['detail'] = 'Greeting and EHLO succeeded with '.self::securityLabel($security).'. Authentication was not attempted.';
            $result['auth'] = array_values($caps['AUTH'] ?? []);
            return $result;
        } catch (MailError $e) {
            $result['stage'] = $e->stage;
            $result['detail'] = $e->getMessage();
            return $result;
        } finally {
            @fwrite($stream,"QUIT\r\n");
            fclose($stream);
        }
    }

    private static function connectFailure(int $errno,string $error,string $host,int $port,string $security): string {
        if ($errno === 111) return 'Connection refused: nothing is listening on '.$host.':'.$port.'.';
        if ($errno === 110 || $errno === 60) return 'Connection timed out: a firewall or your hosting provider is very likely blocking outbound traffic to port '.$port.'.';
        if ($security === 'ssl' && stripos($error,'ssl') !== false) return 'TLS handshake failed before SMTP started: '.mb_substr($error,0,120).'. Port 465 needs implicit TLS; port 587 needs STARTTLS.';
        if ($errno === 0 && $error !== '') return 'Could not connect to '.$host.':'.$port.' — '.mb_substr($error,0,120).'.';
        return 'Could not connect to '.$host.':'.$port.($errno ? ' (error '.$errno.')' : '').'. The port may be blocked by your host.';
    }

    /**
     * @param array<int,array<string,mixed>> $probes
     * @param array<int,string> $ips
     * @return array<string,mixed>
     */
    private static function recommendation(array $probes,array $ips,int $port,string $security): array {
        if ($ips === []) {
            return ['code'=>'dns','text'=>'The host name did not resolve. Check the spelling — it is usually something like mail.yourdomain.com, without https:// or a port.'];
        }
        $working = null; $silent = 0; $refused = 0;
        foreach ($probes as $probe) {
            if ($probe['ok']) { $working = $probe; break; }
            if ($probe['stage'] === 'connection') $refused++;
            if (str_contains((string) $probe['detail'],'did not answer')) $silent++;
        }
        if ($working !== null) {
            if ($working['configured']) {
                return ['code'=>'ok','text'=>'The saved port and security mode answer correctly, so the connection itself is fine. If a test still fails, look at the username, app password or sender address.'];
            }
            return ['code'=>'switch','port'=>(int)$working['port'],'security'=>(string)$working['security'],
                'text'=>'Port '.$working['port'].' with '.$working['label'].' answers correctly but your saved setting is port '.$port.' with '.self::securityLabel($security).'. Switch and save, then send a test message.'];
        }
        if ($silent > 0 && $refused === 0) {
            return ['code'=>'silent','text'=>'Something accepts the connection but never speaks SMTP. That usually means the wrong port or security mode; try the alternative below.'];
        }
        if ($refused === count($probes)) {
            return ['code'=>'blocked','text'=>'None of the mail ports answered from this server. Shared hosts often block outbound SMTP — ask your provider to open port 587 or 465, or give you their relay host, then enter it here.'];
        }
        return ['code'=>'unknown','text'=>'No usable mail port was found. Send the results below to your email or hosting provider.'];
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
        $lines = []; $deadline = time()+8; $code = 0; $first = true;
        while (($line=fgets($stream,1024)) !== false) {
            $lines[] = rtrim($line,"\r\n");
            // A port that is not SMTP answers with something else (POP3, HTTP, a
            // TLS alert). Report it at once instead of waiting for a timeout.
            if ($first && !preg_match('/^\d{3}/', $line)) {
                throw new MailError('The server answered with something that is not SMTP during '.$stage.': “'.mb_substr(trim($line),0,80).'”. This is usually the wrong port.',$stage,null,true);
            }
            $first = false;
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') return [$code,$lines];
            }
            if (strlen(implode('', $lines)) > 16384 || time() > $deadline) break;
        }
        $meta = stream_get_meta_data($stream);
        // Keep the real stage (greeting, auth, …) so logs identify where it stopped.
        if (!empty($meta['timed_out'])) throw new MailError('The SMTP server did not answer during '.$stage.' within the time limit.',$stage,null,true);
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
