<?php
/**
 * License Manager — Mail transport.
 *
 * Single source of truth for SMTP. Fixes the classic
 * "SMTP connect() failed" error that happens when port 465 is used with
 * STARTTLS (465 requires implicit SSL, not STARTTLS).
 *
 * Features:
 *   - automatic encryption/port resolution (465 => ssl, 587/25 => tls)
 *   - automatic fallback attempt (ssl 465 <-> tls 587) in "auto" mode
 *   - SMTP transcript capture written to LM/storage/logs/mail.log
 *   - queue with attempt counting + exponential back-off (no more
 *     permanently "failed" emails after one transient network blip)
 *
 * PHP 7.4 compatible.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';

if (!function_exists('lm_phpmailer_loaded')) {
    /**
     * Load PHPMailer 6 from the bundled /includes/src folder.
     *
     * @return bool
     */
    function lm_phpmailer_loaded()
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }

        $base = lm_lm_dir() . '/includes/src';
        if (!is_file($base . '/PHPMailer.php')) {
            $loaded = false;
            return $loaded;
        }

        require_once $base . '/Exception.php';
        require_once $base . '/PHPMailer.php';
        require_once $base . '/SMTP.php';
        $loaded = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

        return $loaded;
    }
}

if (!function_exists('lm_smtp_plan')) {
    /**
     * Build the ordered list of SMTP configurations to attempt.
     *
     * @param array $settings
     * @return array<int,array{host:string,port:int,encryption:string,label:string}>
     */
    function lm_smtp_plan(array $settings)
    {
        $host = trim((string) ($settings['smtp_host'] ?? ''));
        $port = (int) ($settings['smtp_port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }
        $mode = strtolower(trim((string) ($settings['smtp_encryption'] ?? 'auto')));

        if ($host === '') {
            return array();
        }

        if ($mode === 'ssl' || $mode === 'smtps') {
            return array(array('host' => $host, 'port' => $port, 'encryption' => 'ssl', 'label' => 'SSL/TLS (implicit) on port ' . $port));
        }
        if ($mode === 'tls' || $mode === 'starttls') {
            return array(array('host' => $host, 'port' => $port, 'encryption' => 'tls', 'label' => 'STARTTLS on port ' . $port));
        }
        if ($mode === 'none' || $mode === 'plain') {
            return array(
                array('host' => $host, 'port' => $port, 'encryption' => 'none', 'label' => 'No encryption on port ' . $port),
                array('host' => $host, 'port' => 587, 'encryption' => 'tls', 'label' => 'STARTTLS on port 587 (fallback)'),
            );
        }

        // AUTO — infer the correct transport from the port, then add one fallback.
        if ($port === 465) {
            return array(
                array('host' => $host, 'port' => 465, 'encryption' => 'ssl', 'label' => 'SSL/TLS (implicit) on port 465'),
                array('host' => $host, 'port' => 587, 'encryption' => 'tls', 'label' => 'STARTTLS on port 587 (fallback)'),
            );
        }
        if ($port === 587) {
            return array(
                array('host' => $host, 'port' => 587, 'encryption' => 'tls', 'label' => 'STARTTLS on port 587'),
                array('host' => $host, 'port' => 465, 'encryption' => 'ssl', 'label' => 'SSL/TLS (implicit) on port 465 (fallback)'),
            );
        }
        if ($port === 25 || $port === 2525) {
            return array(
                array('host' => $host, 'port' => $port, 'encryption' => 'tls', 'label' => 'STARTTLS on port ' . $port),
                array('host' => $host, 'port' => $port, 'encryption' => 'none', 'label' => 'No encryption on port ' . $port . ' (fallback)'),
            );
        }

        return array(
            array('host' => $host, 'port' => $port, 'encryption' => 'tls', 'label' => 'STARTTLS on port ' . $port),
        );
    }
}

if (!function_exists('lm_smtp_describe')) {
    /**
     * Human-readable summary of the resolved SMTP transport.
     *
     * @param array $settings
     * @return string
     */
    function lm_smtp_describe(array $settings)
    {
        $plan = lm_smtp_plan($settings);
        if (empty($plan)) {
            return 'SMTP not configured';
        }
        $primary = $plan[0];
        return $primary['host'] . ':' . $primary['port'] . ' — ' . $primary['label'];
    }
}

if (!function_exists('lm_build_mailer')) {
    /**
     * Create a configured PHPMailer instance for one transport attempt.
     *
     * @param array  $settings
     * @param array  $transport  One item from lm_smtp_plan().
     * @param string $debug      Receives the SMTP transcript.
     * @return PHPMailer\PHPMailer\PHPMailer|null
     */
    function lm_build_mailer(array $settings, array $transport, &$debug = '')
    {
        if (!lm_phpmailer_loaded()) {
            $debug = 'PHPMailer library missing at LM/includes/src';
            return null;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $transport['host'];
        $mail->Port = (int) $transport['port'];
        $mail->SMTPAuth = trim((string) ($settings['smtp_user'] ?? '')) !== '';
        $mail->Username = (string) ($settings['smtp_user'] ?? '');
        $mail->Password = (string) ($settings['smtp_pass'] ?? '');

        if ($transport['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = false;
        } elseif ($transport['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $timeout = (int) ($settings['smtp_timeout'] ?? 20);
        if ($timeout < 5 || $timeout > 120) {
            $timeout = 20;
        }
        $mail->Timeout = $timeout;
        $mail->SMTPKeepAlive = false;

        $verifyPeer = (bool) ($settings['smtp_verify_peer'] ?? true);
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer,
            ),
        );

        $fromEmail = trim((string) ($settings['smtp_from_email'] ?? ''));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = trim((string) ($settings['admin_email'] ?? ''));
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = trim((string) ($settings['smtp_user'] ?? ''));
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'noreply@' . (isset($_SERVER['HTTP_HOST']) ? preg_replace('/^www\./', '', (string) $_SERVER['HTTP_HOST']) : 'localhost');
        }

        $fromName = trim((string) ($settings['smtp_from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = lm_site_name();
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->Sender = $fromEmail;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->isHTML(true);
        $mail->XMailer = 'Philmore LM ' . LM_VERSION;

        if (!empty($settings['smtp_debug'])) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function ($str, $level) use (&$debug) {
                $debug .= trim(preg_replace('/\s+/', ' ', (string) $str)) . "\n";
            };
        } else {
            // Always capture the last transcript so failures are diagnosable.
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = function ($str, $level) use (&$debug) {
                $debug .= trim(preg_replace('/\s+/', ' ', (string) $str)) . "\n";
            };
        }

        return $mail;
    }
}

if (!function_exists('lm_send_mail')) {
    /**
     * Send one HTML email, trying each transport in the plan.
     *
     * @param string $to
     * @param string $subject
     * @param string $html
     * @param string $altText
     * @param array  $options  bcc, reply_to, from_email, from_name, headers
     * @return array{ok:bool,error:string,transport:string,debug:string}
     */
    function lm_send_mail($to, $subject, $html, $altText = '', array $options = array())
    {
        $settings = lm_settings();
        $to = trim((string) $to);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return array('ok' => false, 'error' => 'Invalid recipient address: ' . $to, 'transport' => '', 'debug' => '');
        }

        $plan = lm_smtp_plan($settings);
        if (empty($plan)) {
            $error = 'SMTP is not configured. Add host, port and credentials in Settings → SMTP.';
            lm_log('mail', 'FAILED to ' . $to . ': ' . $error);
            return array('ok' => false, 'error' => $error, 'transport' => '', 'debug' => '');
        }

        $subject = trim(preg_replace('/[\r\n]+/', ' ', (string) $subject));
        $html = (string) $html;
        if ($altText === '') {
            $altText = trim(strip_tags(str_replace(array('<br>', '<br/>', '<br />', '</p>', '</li>'), "\n", $html)));
        }

        $errors = array();
        $allDebug = '';

        foreach ($plan as $transport) {
            $debug = '';
            $mail = lm_build_mailer($settings, $transport, $debug);
            if (!$mail) {
                $errors[] = $debug;
                break;
            }

            try {
                if (!empty($options['from_email']) && filter_var($options['from_email'], FILTER_VALIDATE_EMAIL)) {
                    $mail->setFrom($options['from_email'], $options['from_name'] ?? lm_site_name());
                }
                if (!empty($options['reply_to']) && filter_var($options['reply_to'], FILTER_VALIDATE_EMAIL)) {
                    $mail->addReplyTo($options['reply_to']);
                }

                $mail->addAddress($to);
                if (!empty($options['bcc']) && strcasecmp((string) $options['bcc'], $to) !== 0 && filter_var($options['bcc'], FILTER_VALIDATE_EMAIL)) {
                    $mail->addBCC($options['bcc']);
                }

                $mail->Subject = $subject;
                $mail->Body = $html;
                $mail->AltBody = $altText;
                $mail->isHTML(true);

                if (!empty($options['headers']) && is_array($options['headers'])) {
                    foreach ($options['headers'] as $headerName => $headerValue) {
                        $mail->addCustomHeader($headerName, $headerValue);
                    }
                }

                $mail->send();
                $allDebug .= $debug;

                lm_log('mail', 'OK ' . $transport['label'] . ' → ' . $to . ' | ' . $subject);
                return array('ok' => true, 'error' => '', 'transport' => $transport['label'], 'debug' => $allDebug);
            } catch (Exception $e) {
                $errors[] = $transport['label'] . ': ' . ($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage());
                $allDebug .= $debug;
            } catch (Throwable $e) {
                $errors[] = $transport['label'] . ': ' . $e->getMessage();
                $allDebug .= $debug;
            }

            // Close the SMTP connection before retrying.
            try {
                $mail->smtpClose();
            } catch (Exception $e) {
                // ignore
            }
        }

        $error = implode(' | ', array_filter($errors));
        if ($error === '') {
            $error = 'Unknown SMTP failure.';
        }

        if (stripos($error, 'connect() failed') !== false || stripos($error, 'Connection refused') !== false) {
            $error .= ' — hint: port 465 needs SSL, port 587 needs STARTTLS. Set SMTP encryption to "Auto" in Settings.';
        }

        lm_log('mail', 'FAILED to ' . $to . ' | ' . $subject . ' | ' . $error);
        if ($allDebug !== '') {
            lm_log('mail-debug', $allDebug);
        }

        return array('ok' => false, 'error' => $error, 'transport' => '', 'debug' => $allDebug);
    }
}

if (!function_exists('lm_queue_email')) {
    /**
     * Add an email to the delivery queue.
     *
     * @param PDO    $pdo
     * @param string $to
     * @param string $subject
     * @param string $html
     * @param string $altText
     * @param array  $options bcc, priority, send_after (Y-m-d H:i:s), reference
     * @return int|false Queue row id.
     */
    function lm_queue_email(PDO $pdo, $to, $subject, $html, $altText = '', array $options = array())
    {
        $to = trim((string) $to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $priority = isset($options['priority']) ? (int) $options['priority'] : 5;
        $sendAfter = $options['send_after'] ?? date('Y-m-d H:i:s');
        $body = (string) $html;

        // Keep the existing email_queue schema working on older installs.
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO email_queue (recipient_email, subject, body, alt_body, status, priority, next_attempt_at)
                 VALUES (?, ?, ?, ?, 'pending', ?, ?)"
            );
            $stmt->execute(array($to, $subject, $body, $altText, $priority, $sendAfter));
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO email_queue (recipient_email, subject, body, status) VALUES (?, ?, ?, 'pending')"
                );
                $stmt->execute(array($to, $subject, $body));
                return (int) $pdo->lastInsertId();
            } catch (PDOException $e2) {
                lm_log('mail', 'Could not queue email to ' . $to . ': ' . $e2->getMessage());
                return false;
            }
        }
    }
}

if (!function_exists('lm_process_email_queue')) {
    /**
     * Deliver pending queued emails with retry + back-off.
     *
     * A transient SMTP failure keeps the row pending and schedules a retry
     * (1m, 5m, 15m, 60m, 6h). After max attempts it is marked failed.
     *
     * @param PDO $pdo
     * @param int $limit
     * @return array{processed:int,sent:int,failed:int,errors:array}
     */
    function lm_process_email_queue(PDO $pdo, $limit = 20)
    {
        $limit = max(1, min(200, (int) $limit));
        $stats = array('processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => array());

        $hasExtended = true;

        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM email_queue
                 WHERE status = 'pending'
                   AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                 ORDER BY priority ASC, id ASC
                 LIMIT {$limit}"
            );
            $stmt->execute();
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $hasExtended = false;
            $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE status = 'pending' ORDER BY id ASC LIMIT {$limit}");
            $stmt->execute();
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($emails as $job) {
            $stats['processed']++;
            $id = (int) $job['id'];
            $attempts = isset($job['attempts']) ? (int) $job['attempts'] : 0;

            // Claim the row so two concurrent cron runs don't double-send.
            if ($hasExtended) {
                $claim = $pdo->prepare(
                    "UPDATE email_queue SET attempts = attempts + 1, next_attempt_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                     WHERE id = ? AND status = 'pending' AND attempts = ?"
                );
                $claim->execute(array($id, $attempts));
                if ($claim->rowCount() === 0) {
                    continue;
                }
            }

            $result = lm_send_mail(
                $job['recipient_email'],
                $job['subject'],
                $job['body'],
                (string) ($job['alt_body'] ?? '')
            );

            if ($result['ok']) {
                $stats['sent']++;
                if ($hasExtended) {
                    $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")
                        ->execute(array($id));
                } else {
                    $pdo->prepare("UPDATE email_queue SET status = 'sent' WHERE id = ?")->execute(array($id));
                }
                continue;
            }

            $stats['failed']++;
            $stats['errors'][] = 'Email #' . $id . ': ' . $result['error'];
            $attempts++;

            // Back-off schedule in minutes.
            $schedule = array(1, 5, 15, 60, 360);
            $maxAttempts = count($schedule) + 1;
            $delay = isset($schedule[$attempts - 1]) ? $schedule[$attempts - 1] : 720;

            if ($hasExtended) {
                if ($attempts >= $maxAttempts) {
                    $pdo->prepare("UPDATE email_queue SET status = 'failed', last_error = ? WHERE id = ?")
                        ->execute(array(substr($result['error'], 0, 2000), $id));
                } else {
                    $pdo->prepare(
                        "UPDATE email_queue SET status = 'pending', last_error = ?,
                         next_attempt_at = DATE_ADD(NOW(), INTERVAL {$delay} MINUTE)
                         WHERE id = ?"
                    )->execute(array(substr($result['error'], 0, 2000), $id));
                }
            } else {
                $pdo->prepare("UPDATE email_queue SET status = 'failed' WHERE id = ?")->execute(array($id));
            }

            lm_log('mail', 'Queue retry #' . $attempts . ' for email ' . $id . ' in ' . $delay . 'm: ' . $result['error']);
        }

        return $stats;
    }
}

if (!function_exists('lm_send_test_email')) {
    /**
     * Send a diagnostic email and report the transport that worked.
     *
     * @param string $to
     * @return array
     */
    function lm_send_test_email($to)
    {
        $settings = lm_settings();
        $site = htmlspecialchars(lm_site_name(), ENT_QUOTES, 'UTF-8');
        $plan = lm_smtp_describe($settings);
        $when = gmdate('Y-m-d H:i:s') . ' UTC';

        $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">'
            . '<div style="background:linear-gradient(135deg,#4338ca,#6366f1 55%,#06b6d4);padding:28px 32px;color:#ffffff;">'
            . '<div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;opacity:.85;">' . $site . '</div>'
            . '<h1 style="margin:8px 0 0;font-size:22px;font-weight:700;">SMTP test successful</h1></div>'
            . '<div style="padding:28px 32px;">'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">This is a test message from your License Manager. If you can read this, email delivery is working.</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
            . '<tr><td style="padding:8px 0;color:#64748b;">Transport</td><td style="padding:8px 0;text-align:right;font-weight:600;">' . htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#64748b;">Sent at</td><td style="padding:8px 0;text-align:right;font-weight:600;">' . $when . '</td></tr>'
            . '<tr><td style="padding:8px 0;color:#64748b;">Recipient</td><td style="padding:8px 0;text-align:right;font-weight:600;">' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '</table></div></div></body></html>';

        $result = lm_send_mail($to, 'SMTP test — ' . lm_site_name(), $html, "SMTP test from " . lm_site_name() . "\nTransport: " . $plan . "\nSent: " . $when);
        $result['debug'] = substr((string) $result['debug'], 0, 4000);
        return $result;
    }
}
