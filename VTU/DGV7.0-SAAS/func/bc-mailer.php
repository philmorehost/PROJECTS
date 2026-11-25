<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// PHPMailer is vendored in func/vendor/phpmailer. Requiring those three files unconditionally meant
// a deployment that was missing that folder (a partial upload, or a tool/pipeline that skips the
// name "vendor") died with a FATAL ERROR right here - and because bc-connect.php includes this file,
// that took down EVERY page including the admin login, even though mail is only needed to send a
// message. Require only the files that are actually present; the senders fall back to mail().
foreach (array('Exception.php', 'PHPMailer.php', 'SMTP.php') as $bc_phpmailer_part) {
    $bc_phpmailer_path = __DIR__ . '/vendor/phpmailer/src/' . $bc_phpmailer_part;
    if (is_readable($bc_phpmailer_path)) {
        require_once $bc_phpmailer_path;
    }
}

/**
 * True when the vendored PHPMailer library is present and usable. When it is not, the senders go
 * straight to their mail() fallback instead of touching a class that does not exist.
 */
function bc_mailer_library_available()
{
    return class_exists('PHPMailer\\PHPMailer\\PHPMailer') && class_exists('PHPMailer\\PHPMailer\\SMTP');
}

/**
 * bc_mail_log_error()
 * Appends a line to logs/mail-errors.log. The old code swallowed the PHPMailer exception
 * and fell back to mail() with no trace, which made "works on server A, fails on server B"
 * issues nearly impossible to diagnose. Log the real failure so it is visible.
 */
function bc_mail_log_error($message)
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/mail-errors.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Say it once per request, in the log support already checks, so "emails are not being sent" names
// its cause instead of looking like a broken SMTP configuration.
if (!bc_mailer_library_available()) {
    bc_mail_log_error('PHPMailer is NOT installed at func/vendor/phpmailer/src/ - SMTP sending is disabled and mail() is being used. Upload that folder to enable SMTP.');
}

/**
 * bc_sanitize_from_name()
 * Strips control characters and RFC 5322 "specials" ( " ( ) , : ; < > @ [ \ ] ) from a
 * display name so it can be embedded in a raw From: header without breaking header parsing.
 * Returns a non-empty, safe string (defaults to "System Notification").
 */
function bc_sanitize_from_name($name)
{
    $name = (string)$name;
    $name = preg_replace('/[\x00-\x1F\x7F"(),:;<>@\[\]\\\\]/', '', $name);
    $name = trim($name);
    return ($name !== '') ? $name : 'System Notification';
}

/**
 * bc_resolve_safe_from_address()
 * Returns a guaranteed RFC-5322-valid sender address for the current context (vendor first,
 * then super admin, then a known-good default). An invalid/empty smtp_user stored in the DB
 * is the #1 cause of Gmail 550 5.7.1 "missing a valid address in From" — if the raw DB value
 * is reused verbatim, PHPMailer rejects it, the code falls back to mail(), and the same broken
 * address is echoed into the raw From: header. Validate here so every path sends a compliant
 * address.
 */
function bc_resolve_safe_from_address($connection_server, $preferred = null)
{
    $candidates = [];
    if (is_string($preferred) && $preferred !== '') $candidates[] = $preferred;

    $vid = resolveVendorID();
    if ($vid > 0) {
        $q = mysqli_query($connection_server, "SELECT smtp_user FROM sas_vendors WHERE id='$vid' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['smtp_user'])) $candidates[] = $r['smtp_user'];
    }
    $q = mysqli_query($connection_server, "SELECT smtp_user FROM sas_super_admin LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['smtp_user'])) $candidates[] = $r['smtp_user'];

    $candidates[] = 'notification@cheaperdata.com.ng';
    foreach ($candidates as $addr) {
        if (is_string($addr) && filter_var(trim($addr), FILTER_VALIDATE_EMAIL)) {
            return trim($addr);
        }
    }
    return 'notification@cheaperdata.com.ng';
}

/**
 * bc_resolve_from_name()
 * Picks the display name for the From: header: the explicit $from argument if given,
 * otherwise the name embedded in the caller's own "From: Name <addr>" header, otherwise
 * the default. Sanitized so it is safe to use in a raw header.
 */
function bc_resolve_from_name($from, $headers)
{
    $name = '';
    if (is_string($from) && $from !== '') {
        $name = $from;
    } elseif (preg_match('/From:\s*([^<\r\n]+)</i', (string)$headers, $m)) {
        $name = trim($m[1]);
    }
    return bc_sanitize_from_name($name);
}

if (!function_exists('bc_resolve_smtp_config')) {
    function bc_resolve_smtp_config($platform_only = false)
    {
        global $connection_server;
        $vid = function_exists('resolveVendorID') ? resolveVendorID() : 0;
        $smtp_config = null;

        if ($connection_server instanceof mysqli) {
            if (!$platform_only && $vid > 0) {
                $q = @mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_vendors WHERE id='$vid' LIMIT 1");
                if ($q && ($r = mysqli_fetch_assoc($q))) {
                    if (!empty($r['smtp_host']) && !empty($r['smtp_user'])) $smtp_config = $r;
                }
            }

            if (!$smtp_config) {
                $q = @mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_super_admin LIMIT 1");
                if ($q && ($r = mysqli_fetch_assoc($q))) {
                    if (!empty($r['smtp_host']) && !empty($r['smtp_user'])) $smtp_config = $r;
                }
            }
        }
        return $smtp_config;
    }
}

if (!function_exists('bc_flush_deferred_mail_queue')) {
    function bc_flush_deferred_mail_queue()
    {
        if (empty($GLOBALS['bc_deferred_mail_queue']) || !is_array($GLOBALS['bc_deferred_mail_queue'])) {
            return;
        }
        // Release any active PHP session lock and flush the HTTP response to the browser
        // BEFORE opening SMTP sockets so Pay Bills / redirects return immediately.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        $queue = $GLOBALS['bc_deferred_mail_queue'];
        $GLOBALS['bc_deferred_mail_queue'] = array();
        foreach ($queue as $job) {
            bc_deliver_prepared_mail(
                $job['from'],
                $job['to'],
                $job['subject'],
                $job['message'],
                $job['headers'],
                $job['platform_only'],
                $job['smtp_config'] ?? null,
                $job['safe_from'] ?? null
            );
        }
    }
}

function customBCMailSender($from,$to,$subject,$message,$headers, $background = true, $platform_only = false){
    global $connection_server;
    require_once __DIR__ . '/email-design.php';
    $message = mailDesignTemplate($subject, $message, array(), true);

    if ($background && PHP_SAPI !== 'cli' && !$platform_only) {
        $preloaded_smtp = bc_resolve_smtp_config(false);
        $preloaded_from = bc_resolve_safe_from_address($connection_server, $preloaded_smtp['smtp_user'] ?? null);
        if (!isset($GLOBALS['bc_deferred_mail_queue']) || !is_array($GLOBALS['bc_deferred_mail_queue'])) {
            $GLOBALS['bc_deferred_mail_queue'] = array();
        }
        $GLOBALS['bc_deferred_mail_queue'][] = array(
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'platform_only' => false,
            'smtp_config' => $preloaded_smtp,
            'safe_from' => $preloaded_from,
        );
        if (empty($GLOBALS['bc_deferred_mail_registered'])) {
            $GLOBALS['bc_deferred_mail_registered'] = true;
            register_shutdown_function('bc_flush_deferred_mail_queue');
        }
        return true;
    }

    return bc_deliver_prepared_mail($from, $to, $subject, $message, $headers, $platform_only);
}

function bc_deliver_prepared_mail($from, $to, $subject, $message, $headers, $platform_only = false, $preloaded_smtp = null, $preloaded_safe_from = null) {
    global $connection_server;
    $sent = false;
	try {
        if (!bc_mailer_library_available()) {
            // Thrown INSIDE the try on purpose: the catch below then runs the mail() fallback
            // instead of "Class PHPMailer not found" escaping as a fatal error. It also has to be
            // checked before the object is constructed, or that Error happens outside the try.
            throw new RuntimeException('PHPMailer library is not installed');
        }
		$smtpMAIL = new PHPMailer(true);
        $smtp_config = is_array($preloaded_smtp) ? $preloaded_smtp : bc_resolve_smtp_config($platform_only);

        if ($platform_only && (!$smtp_config || !filter_var($smtp_config['smtp_user']??'',FILTER_VALIDATE_EMAIL))) return false;

		 //Server settings
		$smtp_host = $smtp_config['smtp_host'] ?? 'mail.cheaperdata.com.ng';
        $smtp_user = $smtp_config['smtp_user'] ?? 'notification@cheaperdata.com.ng';
        $smtp_pass = $smtp_config['smtp_pass'] ?? '';
        $smtp_port = (int)($smtp_config['smtp_port'] ?? 25);
        $smtp_sec = $smtp_config['smtp_sec'] ?? 'tls';
        if ($platform_only && !in_array($smtp_sec,['tls','ssl'],true)) return false;

        // GUARANTEE a valid From address (see bc_resolve_safe_from_address). Using the raw
        // DB value here let a bad smtp_user poison both the SMTP path AND the mail() fallback.
        if (is_string($preloaded_safe_from) && filter_var($preloaded_safe_from, FILTER_VALIDATE_EMAIL)) {
            $smtp_user = $preloaded_safe_from;
        } else {
            $smtp_user = bc_resolve_safe_from_address($connection_server, $smtp_user);
        }

		$smtpMAIL->isSMTP();
		$smtpMAIL->Host = $smtp_host;
		$smtpMAIL->SMTPAuth = true;
		if ($smtp_sec == 'ssl') $smtpMAIL->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        elseif ($smtp_sec == 'tls') $smtpMAIL->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

		$smtpMAIL->Port = $smtp_port;
        $smtpMAIL->CharSet = 'UTF-8';
        $smtpMAIL->Timeout = 5;
        $smtpMAIL->getSMTPInstance()->Timelimit = 8;
		
		$smtpMAIL->Username = $smtp_user;
		$smtpMAIL->Password = $smtp_pass;
		
		 //Sender and recipient settings
		// $from was accepted but never used — every email looked like it came from a
		// generic "System Notification" regardless of caller. Callers that care about a
		// proper display name (e.g. a marketing campaign, which should show the vendor's
		// own site name) now get it; existing callers that still pass '' keep the old
		// default unchanged.
		$smtpMAIL->setFrom($smtp_user, bc_resolve_from_name($from, $headers));

        // addAddress() throws on a malformed recipient. One bad address must not force the
        // whole message onto the mail() fallback (or kill the loop in process_mail_queue.php).
        try {
            $smtpMAIL->addAddress($to);
        } catch (Exception $e) {
            bc_mail_log_error("customBCMailSender: invalid recipient skipped: " . var_export($to, true));
            return false;
        }
		$smtpMAIL->addReplyTo($smtp_user);

        // Extract Cc from headers if present — validate each one. An invalid address here
        // (e.g. the literal string "Error: Vendor not exists" produced by get_admin_info())
        // used to make PHPMailer throw and silently divert the mail to the broken fallback.
        if (preg_match('/Cc:\s*([^\r\n]+)/i', $headers, $cc_matches)) {
            $cc_emails = explode(',', $cc_matches[1]);
            foreach($cc_emails as $cc_email) {
                $cc_email = trim($cc_email);
                if (!empty($cc_email) && filter_var($cc_email, FILTER_VALIDATE_EMAIL)) {
                    try { $smtpMAIL->addCC($cc_email); } catch (Exception $e) { /* skip bad cc */ }
                }
            }
        }
		
		 //Setting the email content
		$smtpMAIL->IsHTML(true);
        $smtpMAIL->Encoding = 'base64'; // Fix "lines too long" error
		$smtpMAIL->Subject = $subject;
		$smtpMAIL->Body = bc_email_embed_logo($smtpMAIL, $message);
		$smtpMAIL->AltBody = strip_tags($message);
		$sent = $smtpMAIL->send();
	} catch (\Throwable $e) {
        if ($platform_only) { bc_mail_log_error('Platform verification SMTP delivery failed.'); return false; }

        // Log the real failure so server-side SMTP problems are visible (previously the
        // exception was swallowed silently and mail() was used with no way to see why).
        // \Throwable, not the PHPMailer\PHPMailer\Exception alias alone: with the library missing
        // the failure is an Error and there is no $smtpMAIL object to read properties from.
        bc_mail_log_error("customBCMailSender SMTP failed (host=" . ($smtpMAIL->Host ?? 'unset') . ", info=" . (($smtpMAIL->ErrorInfo ?? '') ?: $e->getMessage()) . "); falling back to mail() for {$to}");

        // Fallback to inbuilt mail(). Build a complete, RFC-5322-compliant header block from
        // scratch (never trust the caller's raw $headers) so the From: header is ALWAYS present
        // and valid — this is exactly what Google's 550 5.7.1 "missing a valid address in
        // From" rejection complains about. Raw mail() applies no Content-Transfer-Encoding,
        // so base64-encode the body here too (same reasoning as the primary path's
        // Encoding='base64').
        // Also guard catch fallback against closed $connection_server during shutdown.
        $safe_from = (is_string($preloaded_safe_from) && filter_var($preloaded_safe_from, FILTER_VALIDATE_EMAIL))
            ? $preloaded_safe_from
            : (($connection_server instanceof mysqli) ? bc_resolve_safe_from_address($connection_server, $smtp_user ?? null) : ($smtp_user ?? 'notification@cheaperdata.com.ng'));
        $fallback_headers = "MIME-Version: 1.0\r\n";
        $fallback_headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $fallback_headers .= "From: " . bc_resolve_from_name($from, $headers) . " <" . $safe_from . ">\r\n";
        $fallback_headers .= "Reply-To: " . $safe_from . "\r\n";
        $fallback_headers .= "Content-Transfer-Encoding: base64\r\n";

        // Best-effort envelope sender so the receiving MTA sees a matching Return-Path.
        // Suppress warnings; some hosts restrict the -f parameter.
        $sent = @mail($to, $subject, chunk_split(base64_encode($message)), $fallback_headers, '-f' . $safe_from);
        if (!$sent) {
            // Last resort without -f (some hosts refuse the 5th argument).
            $sent = @mail($to, $subject, chunk_split(base64_encode($message)), $fallback_headers);
        }
	}
    return $sent;
}

function sendEmailWithAttachments($to, $subject, $message, $from_name, $from_email, $attachments = array()) {
    global $connection_server;
    require_once __DIR__ . '/email-design.php';
    $message = mailDesignTemplate($subject, $message, array(), true);
    $sent = false;
    try {
        if (!bc_mailer_library_available()) {
            throw new RuntimeException('PHPMailer library is not installed');
        }
        $smtpMAIL = new PHPMailer(true);
        // Resolve SMTP Context (Vendor vs Platform)
        $vid = resolveVendorID();
        $smtp_config = null;

        if ($vid > 0) {
            $q = mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_vendors WHERE id='$vid' LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q))) {
                if (!empty($r['smtp_host']) && !empty($r['smtp_user'])) $smtp_config = $r;
            }
        }

        if (!$smtp_config) {
            $q = mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_super_admin LIMIT 1");
            if ($q && ($r = mysqli_fetch_assoc($q))) {
                if (!empty($r['smtp_host']) && !empty($r['smtp_user'])) $smtp_config = $r;
            }
        }

        $smtp_host = $smtp_config['smtp_host'] ?? 'mail.cheaperdata.com.ng';
        $smtp_user = $smtp_config['smtp_user'] ?? 'notification@cheaperdata.com.ng';
        $smtp_pass = $smtp_config['smtp_pass'] ?? '';
        $smtp_port = (int)($smtp_config['smtp_port'] ?? 25);
        $smtp_sec = $smtp_config['smtp_sec'] ?? 'tls';

        $smtpMAIL->isSMTP();
        $smtpMAIL->Host = $smtp_host;
        $smtpMAIL->SMTPAuth = true;
        if ($smtp_sec == 'ssl') $smtpMAIL->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        elseif ($smtp_sec == 'tls') $smtpMAIL->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $smtpMAIL->Port = $smtp_port;
        $smtpMAIL->CharSet = 'UTF-8';
        $smtpMAIL->Timeout = 20;

        $smtpMAIL->Username = $smtp_user;
        $smtpMAIL->Password = $smtp_pass;

        $smtpMAIL->setFrom($smtp_user, $from_name);
        $smtpMAIL->addAddress($to);
        $smtpMAIL->addReplyTo($from_email);

        $smtpMAIL->IsHTML(true);
        $smtpMAIL->Encoding = 'base64'; // Fix "lines too long" error
        $smtpMAIL->Subject = $subject;
        $smtpMAIL->Body = bc_email_embed_logo($smtpMAIL, $message);
        $smtpMAIL->AltBody = strip_tags($message);

        foreach ($attachments as $file) {
            if (file_exists($file)) {
                $smtpMAIL->addAttachment($file);
            }
        }

        $sent = $smtpMAIL->send();
    } catch (\Throwable $e) {
        // Fallback. Same "lines too long for transport" risk as customBCMailSender()'s
        // fallback above — base64-encode the body here too. Note: attachments are not included
        // in this fallback (pre-existing limitation, unrelated to this fix) — if SMTP fails,
        // the email still goes out via mail() but without its attachments. The From address is
        // validated so a bad $from_email can't trigger Gmail 550 5.7.1 "missing From".
        bc_mail_log_error("sendEmailWithAttachments SMTP failed (" . (($smtpMAIL->ErrorInfo ?? '') ?: $e->getMessage()) . "); falling back to mail() for {$to}");
        $safe_from = bc_resolve_safe_from_address($connection_server, $from_email);
        $fallback_headers = "MIME-Version: 1.0\r\n";
        $fallback_headers .= "From: " . bc_sanitize_from_name($from_name) . " <" . $safe_from . ">\r\n";
        $fallback_headers .= "Reply-To: " . $safe_from . "\r\n";
        $fallback_headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $fallback_headers .= "Content-Transfer-Encoding: base64\r\n";
        $sent = @mail($to, $subject, chunk_split(base64_encode($message)), $fallback_headers, '-f' . $safe_from);
        if (!$sent) {
            $sent = @mail($to, $subject, chunk_split(base64_encode($message)), $fallback_headers);
        }
    }
    return $sent;
}
