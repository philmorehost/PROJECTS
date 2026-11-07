<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';

function customBCMailSender($from,$to,$subject,$message,$headers, $background = true){
    global $connection_server;

    // Branch DG6.7 Optimization: Optionally skip blocking SMTP for login notifications
    // if requested, or if the server is known to be slow.
    // For now, we will use a shorter timeout to prevent "everlasting" loads.

	$smtpMAIL = new PHPMailer(true);
    $sent = false;
	try {
         // Resolve SMTP Context (Vendor vs Platform)
         $vid = resolveVendorID();
         $smtp_config = null;

         if ($vid > 0) {
             $q = mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_vendors WHERE id='$vid' LIMIT 1");
             if ($q && $r = mysqli_fetch_assoc($q)) {
                 if (!empty($r['smtp_host']) && !empty($r['smtp_user'])) $smtp_config = $r;
             }
         }

         if (!$smtp_config) {
             $q = mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_super_admin LIMIT 1");
             if ($q && $r = mysqli_fetch_assoc($q)) {
                 if (!empty($r['smtp_host']) && !empty($r['smtp_user'])) $smtp_config = $r;
             }
         }

		 //Server settings
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
        $smtpMAIL->Timeout = 7; // Branch DG6.7 Optimization: Shorter timeout for faster failover
		
		$smtpMAIL->Username = $smtp_user;
		$smtpMAIL->Password = $smtp_pass;
		
		 //Sender and recipient settings
		// $from was accepted but never used — every email looked like it came from a
		// generic "System Notification" regardless of caller. Callers that care about a
		// proper display name (e.g. a marketing campaign, which should show the vendor's
		// own site name) now get it; existing callers that still pass '' keep the old
		// default unchanged.
		$smtpMAIL->setFrom($smtp_user, !empty($from) ? $from : "System Notification");
		$smtpMAIL->addAddress($to);
		$smtpMAIL->addReplyTo($smtp_user);

        // Extract Cc from headers if present
        if (preg_match('/Cc:\s*([^\r\n]+)/i', $headers, $cc_matches)) {
            $cc_emails = explode(',', $cc_matches[1]);
            foreach($cc_emails as $cc_email) {
                $cc_email = trim($cc_email);
                if (!empty($cc_email)) $smtpMAIL->addCC($cc_email);
            }
        }
		
		 //Setting the email content
		$smtpMAIL->IsHTML(true);
        $smtpMAIL->Encoding = 'base64'; // Fix "lines too long" error
		$smtpMAIL->Subject = $subject;
		$smtpMAIL->Body = $message;
		$smtpMAIL->AltBody = strip_tags($message);
		$sent = $smtpMAIL->send();
	} catch (Exception $e) {
        // Fallback to Inbuilt Mail Functions. Raw mail() applies no Content-Transfer-Encoding
        // at all, so a message with any long line (the full HTML template rendered onto one
        // line, or a long marketing paragraph with no manual line breaks) reproduces the exact
        // "message has lines too long for transport" rejection the Encoding='base64' setting
        // above exists to prevent on the primary SMTP path — base64-encode it here too, so this
        // fallback can't hit the same failure.
	    $sent = mail($to, $subject, chunk_split(base64_encode($message)), $headers . "Content-Transfer-Encoding: base64\r\n");
	}
    return $sent;
}

function sendEmailWithAttachments($to, $subject, $message, $from_name, $from_email, $attachments = array()) {
    global $connection_server;
    $smtpMAIL = new PHPMailer(true);
    $sent = false;
    try {
        // Resolve SMTP Context (Vendor vs Platform)
        $vid = resolveVendorID();
        $smtp_config = null;

        if ($vid > 0) {
            $q = mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_vendors WHERE id='$vid' LIMIT 1");
            if ($q && $r = mysqli_fetch_assoc($q)) {
                if (!empty($r['smtp_host']) && !empty($r['smtp_user'])) $smtp_config = $r;
            }
        }

        if (!$smtp_config) {
            $q = mysqli_query($connection_server, "SELECT smtp_host, smtp_user, smtp_pass, smtp_port, smtp_sec FROM sas_super_admin LIMIT 1");
            if ($q && $r = mysqli_fetch_assoc($q)) {
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
        $smtpMAIL->Body = $message;
        $smtpMAIL->AltBody = strip_tags($message);

        foreach ($attachments as $file) {
            if (file_exists($file)) {
                $smtpMAIL->addAttachment($file);
            }
        }

        $sent = $smtpMAIL->send();
    } catch (Exception $e) {
        // Fallback. Same "lines too long for transport" risk as customBCMailSender()'s
        // fallback above — base64-encode the body here too. Note: attachments are not included
        // in this fallback (pre-existing limitation, unrelated to this fix) — if SMTP fails,
        // the email still goes out via mail() but without its attachments.
        $fallback_headers = "MIME-Version: 1.0\r\n";
        $fallback_headers .= "From: $from_name <$from_email>\r\n";
        $fallback_headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $fallback_headers .= "Content-Transfer-Encoding: base64\r\n";
        $sent = mail($to, $subject, chunk_split(base64_encode($message)), $fallback_headers);

    }
    return $sent;
}
