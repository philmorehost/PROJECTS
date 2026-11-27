<?php
/**
 * bc-compliance-notifications.php
 *
 * Unified compliance notification dispatcher across Web, Backoffice, Email, and FCM Push.
 * Notifies BOTH users/vendors and administrators (Vendor Admin / Super Admin) at every stage:
 *   - KYC/KYB document uploads (partial or complete)
 *   - Antivirus / blank-file / video validation blocks
 *   - Automated AI Compliance Engine screening & document scoring
 *   - Submission entering review queue
 *   - Human review decisions (Approved, Rejected, Re-opened, Reset)
 *
 * Identical across SAAS and NON-SAAS editions.
 */

if (!function_exists('bc_compliance_ensure_notification_tables')) {
    function bc_compliance_ensure_notification_tables($connection_server): void
    {
        static $done = false;
        if ($done || !($connection_server instanceof mysqli)) {
            return;
        }
        $done = true;

        @mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            title VARCHAR(150) NOT NULL,
            message VARCHAR(255) NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_unread (vendor_id, username, is_read)
        )");

        @mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_admin_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL DEFAULT 0,
            area VARCHAR(20) NOT NULL DEFAULT 'admin',
            category VARCHAR(40) NOT NULL DEFAULT 'compliance',
            title VARCHAR(160) NOT NULL,
            message VARCHAR(500) NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_unread (vendor_id, area, is_read, id)
        )");
    }
}

if (!function_exists('bc_compliance_notify_user_inapp')) {
    function bc_compliance_notify_user_inapp($connection_server, int $vendor_id, string $username, string $title, string $message, string $link = ''): void
    {
        if (!($connection_server instanceof mysqli) || $vendor_id <= 0 || trim($username) === '') {
            return;
        }
        bc_compliance_ensure_notification_tables($connection_server);
        if (function_exists('bc_notify_user')) {
            @bc_notify_user($connection_server, $vendor_id, $username, substr($title, 0, 150), substr($message, 0, 255), substr($link, 0, 255));
            return;
        }
        $u_esc = mysqli_real_escape_string($connection_server, trim($username));
        $t_esc = mysqli_real_escape_string($connection_server, substr($title, 0, 150));
        $m_esc = mysqli_real_escape_string($connection_server, substr($message, 0, 255));
        $l_esc = mysqli_real_escape_string($connection_server, substr($link, 0, 255));
        @mysqli_query($connection_server, "INSERT INTO sas_user_notifications (vendor_id, username, title, message, link) VALUES ('$vendor_id', '$u_esc', '$t_esc', '$m_esc', '$l_esc')");
    }
}

if (!function_exists('bc_notify_admin')) {
    function bc_notify_admin($connection_server, int $vendor_id, string $area, string $title, string $message, string $link = '', string $category = 'compliance'): void
    {
        if (!($connection_server instanceof mysqli)) {
            return;
        }
        bc_compliance_ensure_notification_tables($connection_server);
        $area_clean = ($area === 'spadmin') ? 'spadmin' : 'admin';
        $vid = ($area_clean === 'spadmin') ? 0 : max(0, $vendor_id);
        $t_esc = mysqli_real_escape_string($connection_server, substr($title, 0, 160));
        $m_esc = mysqli_real_escape_string($connection_server, substr($message, 0, 500));
        $l_esc = mysqli_real_escape_string($connection_server, substr($link, 0, 255));
        $c_esc = mysqli_real_escape_string($connection_server, substr($category, 0, 40));
        @mysqli_query($connection_server, "INSERT INTO sas_admin_notifications (vendor_id, area, category, title, message, link) VALUES ('$vid', '$area_clean', '$c_esc', '$t_esc', '$m_esc', '$l_esc')");
    }
}

if (!function_exists('bc_compliance_fetch_user_notifications')) {
    function bc_compliance_fetch_user_notifications($connection_server, int $vendor_id, string $username, int $limit = 10): array
    {
        $items = [];
        $unread = 0;
        if (!($connection_server instanceof mysqli) || $vendor_id <= 0 || trim($username) === '') {
            return ['unread' => 0, 'items' => []];
        }
        bc_compliance_ensure_notification_tables($connection_server);
        $u_esc = mysqli_real_escape_string($connection_server, trim($username));
        $lim = max(1, min(50, $limit));
        $uq = @mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_user_notifications WHERE vendor_id='$vendor_id' AND username='$u_esc' AND is_read=0");
        if ($uq && ($ur = mysqli_fetch_assoc($uq))) {
            $unread = (int)($ur['c'] ?? 0);
        }
        $q = @mysqli_query($connection_server, "SELECT id, title, message, link, is_read, created_at FROM sas_user_notifications WHERE vendor_id='$vendor_id' AND username='$u_esc' ORDER BY id DESC LIMIT $lim");
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $items[] = [
                'id'         => (int)$r['id'],
                'title'      => (string)$r['title'],
                'message'    => (string)$r['message'],
                'link'       => (string)($r['link'] ?? ''),
                'is_read'    => (int)$r['is_read'] === 1,
                'created_at' => (string)$r['created_at'],
            ];
        }
        return ['unread' => $unread, 'items' => $items];
    }
}

if (!function_exists('bc_compliance_mark_user_notifications_read')) {
    function bc_compliance_mark_user_notifications_read($connection_server, int $vendor_id, string $username): void
    {
        if (!($connection_server instanceof mysqli) || $vendor_id <= 0 || trim($username) === '') {
            return;
        }
        bc_compliance_ensure_notification_tables($connection_server);
        $u_esc = mysqli_real_escape_string($connection_server, trim($username));
        @mysqli_query($connection_server, "UPDATE sas_user_notifications SET is_read=1 WHERE vendor_id='$vendor_id' AND username='$u_esc' AND is_read=0");
    }
}

if (!function_exists('bc_compliance_fetch_admin_notifications')) {
    function bc_compliance_fetch_admin_notifications($connection_server, int $vendor_id, string $area, int $limit = 12): array
    {
        $items = [];
        $unread = 0;
        if (!($connection_server instanceof mysqli)) {
            return ['unread' => 0, 'items' => []];
        }
        bc_compliance_ensure_notification_tables($connection_server);
        $area_clean = ($area === 'spadmin') ? 'spadmin' : 'admin';
        $vid = ($area_clean === 'spadmin') ? 0 : max(0, $vendor_id);
        $lim = max(1, min(50, $limit));
        $uq = @mysqli_query($connection_server, "SELECT COUNT(*) AS c FROM sas_admin_notifications WHERE vendor_id='$vid' AND area='$area_clean' AND is_read=0");
        if ($uq && ($ur = mysqli_fetch_assoc($uq))) {
            $unread = (int)($ur['c'] ?? 0);
        }
        $q = @mysqli_query($connection_server, "SELECT id, category, title, message, link, is_read, created_at FROM sas_admin_notifications WHERE vendor_id='$vid' AND area='$area_clean' ORDER BY id DESC LIMIT $lim");
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $items[] = [
                'id'         => (int)$r['id'],
                'category'   => (string)$r['category'],
                'title'      => (string)$r['title'],
                'message'    => (string)$r['message'],
                'link'       => (string)($r['link'] ?? ''),
                'is_read'    => (int)$r['is_read'] === 1,
                'created_at' => (string)$r['created_at'],
            ];
        }
        return ['unread' => $unread, 'items' => $items];
    }
}

if (!function_exists('bc_compliance_mark_admin_notifications_read')) {
    function bc_compliance_mark_admin_notifications_read($connection_server, int $vendor_id, string $area): void
    {
        if (!($connection_server instanceof mysqli)) {
            return;
        }
        bc_compliance_ensure_notification_tables($connection_server);
        $area_clean = ($area === 'spadmin') ? 'spadmin' : 'admin';
        $vid = ($area_clean === 'spadmin') ? 0 : max(0, $vendor_id);
        @mysqli_query($connection_server, "UPDATE sas_admin_notifications SET is_read=1 WHERE vendor_id='$vid' AND area='$area_clean' AND is_read=0");
    }
}

if (!function_exists('bc_compliance_send_push')) {
    /**
     * Best-effort FCM v1 push notification to a user's registered mobile devices.
     * Never throws or blocks long enough to fail a web/API response.
     */
    function bc_compliance_send_push($connection_server, int $vendor_id, string $username, string $title, string $body, array $extra_data = []): void
    {
        if (!($connection_server instanceof mysqli) || $vendor_id <= 0 || trim($username) === '') {
            return;
        }
        if (!function_exists('curl_init') || !function_exists('openssl_sign')) {
            return;
        }
        $transport = __DIR__ . '/bc-app-update-transport.php';
        if (!function_exists('generateFcmAccessToken') && is_file($transport)) {
            @include_once($transport);
        }
        if (!function_exists('generateFcmAccessToken')) {
            return;
        }

        $t_check = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_fcm_settings'");
        $d_check = @mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_device_tokens'");
        if (!$t_check || mysqli_num_rows($t_check) === 0 || !$d_check || mysqli_num_rows($d_check) === 0) {
            return;
        }

        $fq = @mysqli_query($connection_server, "SELECT project_id, service_account_json FROM sas_fcm_settings WHERE vendor_id='$vendor_id' LIMIT 1");
        $fcm = $fq ? mysqli_fetch_assoc($fq) : null;
        if (!$fcm || empty($fcm['project_id']) || empty($fcm['service_account_json'])) {
            return;
        }

        $u_esc = mysqli_real_escape_string($connection_server, trim($username));
        $dq = @mysqli_query($connection_server, "SELECT fcm_token FROM sas_device_tokens WHERE vendor_id='$vendor_id' AND username='$u_esc' ORDER BY id DESC LIMIT 5");
        $tokens = [];
        while ($dq && ($dr = mysqli_fetch_assoc($dq))) {
            $tk = trim((string)($dr['fcm_token'] ?? ''));
            if ($tk !== '') {
                $tokens[] = $tk;
            }
        }
        if (empty($tokens)) {
            return;
        }

        $sa_json = (string)$fcm['service_account_json'];
        if (function_exists('bc_stepup_open_secret')) {
            $opened = bc_stepup_open_secret($sa_json);
            if (is_string($opened) && $opened !== '') {
                $sa_json = $opened;
            }
        }
        $access_token = @generateFcmAccessToken($sa_json);
        if (!$access_token) {
            return;
        }

        $project_id = trim((string)$fcm['project_id']);
        $data_str = [];
        foreach ($extra_data as $k => $v) {
            $data_str[(string)$k] = (string)$v;
        }
        if (!isset($data_str['type'])) {
            $data_str['type'] = 'kyc_status';
        }
        $data_str['title'] = $title;
        $data_str['body'] = $body;

        foreach ($tokens as $dev_token) {
            $payload = json_encode([
                'message' => [
                    'token'        => $dev_token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => $data_str,
                ],
            ]);
            if (!$payload) continue;
            $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bearer $access_token"],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
            ]);
            @curl_exec($ch);
            @curl_close($ch);
        }
    }
}

if (!function_exists('bc_compliance_safe_email')) {
    function bc_compliance_safe_email($connection_server, int $vendor_id, string $to_email, string $subject, string $html_body, bool $use_spadmin = false): bool
    {
        $to_email = trim($to_email);
        if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $prev_vid = $GLOBALS['vendor_id'] ?? null;
        if ($vendor_id > 0) {
            $GLOBALS['vendor_id'] = $vendor_id;
        }
        $sent = false;
        try {
            if ($use_spadmin && function_exists('sendSuperAdminEmail')) {
                $sent = (bool)sendSuperAdminEmail($to_email, $subject, $html_body, true);
            } elseif (function_exists('sendVendorEmail')) {
                $sent = (bool)sendVendorEmail($to_email, $subject, $html_body, true);
            }
        } catch (Throwable $e) {
            $sent = false;
        }
        if ($prev_vid === null) {
            unset($GLOBALS['vendor_id']);
        } else {
            $GLOBALS['vendor_id'] = $prev_vid;
        }
        return $sent;
    }
}

if (!function_exists('bc_compliance_lookup_vendor')) {
    function bc_compliance_lookup_vendor($connection_server, int $vendor_id): array
    {
        if (!($connection_server instanceof mysqli) || $vendor_id <= 0) {
            return [];
        }
        $q = @mysqli_query($connection_server, "SELECT id, email, firstname, lastname, business_name, website_url FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
        return ($q && ($r = mysqli_fetch_assoc($q))) ? $r : [];
    }
}

if (!function_exists('bc_compliance_lookup_spadmin_emails')) {
    function bc_compliance_lookup_spadmin_emails($connection_server): array
    {
        $emails = [];
        if (!($connection_server instanceof mysqli)) {
            return $emails;
        }
        $q = @mysqli_query($connection_server, "SELECT email FROM sas_super_admin WHERE email IS NOT NULL AND email <> '' ORDER BY id ASC LIMIT 5");
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $em = trim((string)($r['email'] ?? ''));
            if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $em;
            }
        }
        return array_values(array_unique($emails));
    }
}

if (!function_exists('bc_compliance_notify_event')) {
    /**
     * Dispatch user/vendor AND admin/super-admin notifications for every compliance event.
     *
     * @param mysqli $connection_server
     * @param string $event
     * @param array  $ctx
     */
    function bc_compliance_notify_event($connection_server, string $event, array $ctx): void
    {
        if (!($connection_server instanceof mysqli)) {
            return;
        }
        bc_compliance_ensure_notification_tables($connection_server);

        $vendor_id = (int)($ctx['vendor_id'] ?? 0);
        $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : [];
        $uid = (int)($ctx['user_id'] ?? ($user['id'] ?? 0));
        $username = trim((string)($ctx['username'] ?? ($user['username'] ?? '')));
        $user_email = trim((string)($ctx['email'] ?? ($user['email'] ?? '')));
        $first = trim((string)($ctx['firstname'] ?? ($user['firstname'] ?? $username)));
        if ($first === '') $first = 'Customer';

        $vendor = is_array($ctx['vendor'] ?? null) ? $ctx['vendor'] : bc_compliance_lookup_vendor($connection_server, $vendor_id);
        $admin_email = trim((string)($vendor['email'] ?? ''));
        $vendor_name = trim((string)($vendor['business_name'] ?? ($vendor['firstname'] ?? 'Vendor')));

        switch ($event) {
            case 'user_kyc_partial_saved': {
                $still = (array)($ctx['still_needed'] ?? []);
                $still_txt = !empty($still) ? implode(', ', $still) : 'remaining compliance steps';
                bc_compliance_notify_user_inapp(
                    $connection_server, $vendor_id, $username,
                    'KYC Progress Saved',
                    'Your uploaded item(s) passed security screening. Still needed: ' . $still_txt,
                    '/web/KYCVerification.php'
                );
                bc_notify_admin(
                    $connection_server, $vendor_id, 'admin',
                    'Partial KYC Upload: @' . $username,
                    'User @' . $username . ' saved KYC item(s). Waiting on: ' . $still_txt . '.',
                    'KYCManagement.php?status=0&view=' . $uid,
                    'kyc'
                );
                break;
            }

            case 'user_kyc_submitted': {
                $ai_txt = trim((string)($ctx['ai_summary_text'] ?? ''));
                bc_compliance_notify_user_inapp(
                    $connection_server, $vendor_id, $username,
                    'KYC Under Review',
                    'All required KYC documents were received and are awaiting compliance review.',
                    '/web/KYCVerification.php'
                );
                bc_compliance_safe_email(
                    $connection_server, $vendor_id, $user_email,
                    'Identity Verification (KYC) Received — Under Review',
                    'Hello ' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . ',<br><br>We have received all your required identity verification (KYC) documents. Our automated security checks are complete and your file is now awaiting human compliance review.<br><br>You will receive a notification as soon as your account is approved.'
                );
                bc_compliance_send_push(
                    $connection_server, $vendor_id, $username,
                    'KYC Submitted',
                    'Your identity verification is under review. You will be notified once approved.',
                    ['type' => 'kyc_status', 'kyc_status' => '1']
                );

                $adm_msg = 'User @' . $username . ' (' . $first . ') completed all KYC steps and is awaiting your review & approval.' . ($ai_txt !== '' ? ' ' . $ai_txt : '');
                bc_notify_admin(
                    $connection_server, $vendor_id, 'admin',
                    'KYC Ready for Review: @' . $username,
                    $adm_msg,
                    'KYCManagement.php?status=1&view=' . $uid,
                    'kyc'
                );
                if ($admin_email !== '') {
                    bc_compliance_safe_email(
                        $connection_server, $vendor_id, $admin_email,
                        'Action Required: New KYC Submission from @' . $username,
                        'Hello Admin,<br><br>User <b>@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</b> has completed all KYC steps and is waiting for your review and approval.' . ($ai_txt !== '' ? '<br><br><b>AI Compliance Report:</b> ' . htmlspecialchars($ai_txt, ENT_QUOTES, 'UTF-8') : '') . '<br><br>Please open <b>KYC Management</b> in your Admin Console to review and approve or reject.'
                    );
                }
                break;
            }

            case 'user_kyc_ai_screened': {
                $ai_status = (string)($ctx['ai_status'] ?? 'review');
                $rating = (int)($ctx['overall_rating'] ?? 0);
                $grade = (string)($ctx['overall_grade'] ?? 'C');
                $reason = trim((string)($ctx['reason'] ?? ''));
                $rec_text = $ai_status === 'approve' ? 'AI Recommends Approval' : ($ai_status === 'reject' ? 'AI Flagged Issues' : 'Manual Review Recommended');

                bc_compliance_notify_user_inapp(
                    $connection_server, $vendor_id, $username,
                    'AI KYC Screening Complete',
                    $ai_status === 'reject'
                        ? 'AI flagged issues with your documents: ' . ($reason !== '' ? $reason : 'please review and re-upload.')
                        : 'Automated AI screening is complete. Your file is awaiting final admin review.',
                    '/web/KYCVerification.php'
                );
                bc_compliance_send_push(
                    $connection_server, $vendor_id, $username,
                    'AI KYC Screening Update',
                    $ai_status === 'reject' ? 'AI flagged an issue with your KYC upload. Open the app to check.' : 'AI screening completed — awaiting admin approval.',
                    ['type' => 'kyc_status', 'ai_status' => $ai_status]
                );

                $adm_msg = "AI Compliance Report for @$username: $rec_text (Rating: {$rating}/100, Grade $grade)." . ($reason !== '' ? " Summary: $reason" : '');
                bc_notify_admin(
                    $connection_server, $vendor_id, 'admin',
                    'AI KYC Report: @' . $username . ' (' . $rating . '/100)',
                    $adm_msg,
                    'KYCManagement.php?status=1&view=' . $uid,
                    'kyc_ai'
                );
                if ($admin_email !== '' && !empty($ctx['notify_admin_email'])) {
                    bc_compliance_safe_email(
                        $connection_server, $vendor_id, $admin_email,
                        'AI KYC Compliance Report for @' . $username . ' — ' . $rec_text,
                        'Hello Admin,<br><br>The automated AI Compliance Engine finished analyzing <b>@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</b>.<br><br><b>Recommendation:</b> ' . htmlspecialchars($rec_text, ENT_QUOTES, 'UTF-8') . '<br><b>Score:</b> ' . $rating . '/100 (Grade ' . htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') . ')' . ($reason !== '' ? '<br><b>Notes:</b> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '') . '<br><br>Final approval/rejection remains with you in <b>KYC Management</b>.'
                    );
                }
                break;
            }

            case 'user_kyc_av_blocked': {
                $reason = trim((string)($ctx['reason'] ?? 'Blocked by security scanner'));
                bc_compliance_notify_user_inapp(
                    $connection_server, $vendor_id, $username,
                    'KYC Security Alert',
                    'Your upload was blocked by automated security screening: ' . $reason,
                    '/web/KYCVerification.php'
                );
                bc_notify_admin(
                    $connection_server, $vendor_id, 'admin',
                    'KYC Upload Blocked: @' . $username,
                    'Security scanner blocked a KYC file for @' . $username . ': ' . $reason,
                    'KYCManagement.php?view=' . $uid,
                    'security'
                );
                if ($admin_email !== '') {
                    bc_compliance_safe_email(
                        $connection_server, $vendor_id, $admin_email,
                        'Security Alert: Blocked KYC Upload from @' . $username,
                        'Hello Admin,<br><br>Automated antivirus/blank-file scanning blocked a KYC upload from <b>@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</b>.<br><br><b>Reason:</b> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')
                    );
                }
                break;
            }

            case 'user_kyc_decision': {
                $status = (int)($ctx['status'] ?? 0);
                $label = (string)($ctx['label'] ?? ($status === 2 ? 'Approved' : ($status === 3 ? 'Rejected' : 'Updated')));
                $reason = trim((string)($ctx['reason'] ?? ''));
                $title = $status === 2 ? 'KYC Approved!' : ($status === 3 ? 'KYC Action Required' : 'KYC Status Updated');
                $msg = $status === 2
                    ? 'Your identity verification has been approved. Full platform & app access is now unlocked!'
                    : ($status === 3
                        ? 'Your KYC submission was rejected: ' . ($reason !== '' ? $reason : 'Please resubmit.')
                        : 'Your KYC status changed to: ' . $label);

                bc_compliance_notify_user_inapp($connection_server, $vendor_id, $username, $title, $msg, '/web/KYCVerification.php');
                bc_compliance_send_push($connection_server, $vendor_id, $username, $title, $msg, ['type' => 'kyc_status', 'kyc_status' => (string)$status]);
                bc_notify_admin(
                    $connection_server, $vendor_id, 'admin',
                    'KYC ' . ucfirst($label) . ': @' . $username,
                    'KYC decision recorded for @' . $username . ': ' . $label . ($reason !== '' ? ' — ' . $reason : ''),
                    'KYCManagement.php?status=' . $status . '&view=' . $uid,
                    'kyc'
                );
                break;
            }

            case 'user_kyb_submitted': {
                $biz_name = trim((string)($ctx['business_name'] ?? 'Business'));
                $reg_no = trim((string)($ctx['registration_no'] ?? ''));
                $ai_report = is_array($ctx['ai_report'] ?? null) ? $ctx['ai_report'] : [];
                $ai_rating = (int)($ai_report['rating'] ?? 0);
                $ai_grade = (string)($ai_report['grade'] ?? '');
                $ai_rec = (string)($ai_report['recommendation'] ?? '');

                bc_compliance_notify_user_inapp(
                    $connection_server, $vendor_id, $username,
                    'Business Upgrade (KYB) Submitted',
                    'Your KYB application for ' . $biz_name . ' passed security screening and is awaiting admin review.',
                    '/web/BusinessUpgrade.php'
                );
                bc_compliance_safe_email(
                    $connection_server, $vendor_id, $user_email,
                    'Business Upgrade (KYB) Request Under Review',
                    'Hello ' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . ',<br><br>Your Business Upgrade (KYB) request for <b>' . htmlspecialchars($biz_name, ENT_QUOTES, 'UTF-8') . '</b> (' . htmlspecialchars($reg_no, ENT_QUOTES, 'UTF-8') . ') has passed automated screening and is awaiting admin review and approval.'
                );
                bc_compliance_send_push(
                    $connection_server, $vendor_id, $username,
                    'Business Upgrade Submitted',
                    'Your KYB request for ' . $biz_name . ' is awaiting admin review.',
                    ['type' => 'kyb_status', 'kyb_status' => '1']
                );

                $adm_msg = 'User @' . $username . ' applied for Business Upgrade: ' . $biz_name . ' (' . $reg_no . ').' . ($ai_rating > 0 ? " AI Score: {$ai_rating}/100 (Grade $ai_grade) — $ai_rec." : '');
                bc_notify_admin(
                    $connection_server, $vendor_id, 'admin',
                    'New KYB Request: ' . $biz_name,
                    $adm_msg,
                    'BusinessKYB.php',
                    'kyb'
                );
                if ($admin_email !== '') {
                    bc_compliance_safe_email(
                        $connection_server, $vendor_id, $admin_email,
                        'Action Required: New Business KYB Request from @' . $username,
                        'Hello Admin,<br><br>User <b>@' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</b> submitted a Business Upgrade (KYB) request for <b>' . htmlspecialchars($biz_name, ENT_QUOTES, 'UTF-8') . '</b> (Reg: ' . htmlspecialchars($reg_no, ENT_QUOTES, 'UTF-8') . ').' . ($ai_rating > 0 ? '<br><br><b>AI KYB Rating:</b> ' . $ai_rating . '/100 (Grade ' . htmlspecialchars($ai_grade, ENT_QUOTES, 'UTF-8') . ') — ' . htmlspecialchars($ai_rec, ENT_QUOTES, 'UTF-8') : '') . '<br><br>Please open <b>Business KYB Review</b> in your Admin Console to approve or reject.'
                    );
                }
                break;
            }

            case 'user_kyb_decision': {
                $decision = (string)($ctx['decision'] ?? '');
                $reason = trim((string)($ctx['reason'] ?? ''));
                $approved = ($decision === 'approve');
                $title = $approved ? 'Business Account Approved!' : 'Business Upgrade Rejected';
                $msg = $approved
                    ? 'Congratulations! Your business upgrade (KYB) was approved. Business features are now live on the website.'
                    : 'Your business upgrade (KYB) request was not approved: ' . ($reason !== '' ? $reason : 'Please update your details and resubmit.');

                bc_compliance_notify_user_inapp($connection_server, $vendor_id, $username, $title, $msg, '/web/BusinessUpgrade.php');
                bc_compliance_safe_email(
                    $connection_server, $vendor_id, $user_email,
                    $title,
                    'Hello ' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . ',<br><br>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
                );
                bc_compliance_send_push(
                    $connection_server, $vendor_id, $username,
                    $title,
                    $msg,
                    ['type' => 'kyb_status', 'kyb_status' => $approved ? '2' : '3']
                );
                bc_notify_admin(
                    $connection_server, $vendor_id, 'admin',
                    'KYB ' . ($approved ? 'Approved' : 'Rejected') . ': @' . $username,
                    'Business KYB decision recorded for @' . $username . ($reason !== '' ? ' — ' . $reason : ''),
                    'BusinessKYB.php',
                    'kyb'
                );
                break;
            }

            case 'vendor_kyc_submitted': {
                $complete = !empty($ctx['complete']);
                $ai_txt = trim((string)($ctx['ai_summary_text'] ?? ''));
                $pending_txt = trim((string)($ctx['pending_text'] ?? ''));
                $v_title = $complete ? 'Vendor Compliance Submitted' : 'Vendor Compliance Progress Saved';
                $v_msg = $complete
                    ? 'All required vendor KYC/KYB files were submitted and are awaiting Super Admin review.'
                    : 'Compliance document(s) saved. Still needed: ' . ($pending_txt !== '' ? $pending_txt : 'remaining items');
                bc_notify_admin($connection_server, $vendor_id, 'admin', $v_title, $v_msg, 'AccountSettings.php#tab-kyc', 'kyc');
                if ($complete && $admin_email !== '') {
                    bc_compliance_safe_email(
                        $connection_server, $vendor_id, $admin_email,
                        'Vendor Compliance Submission Received — Under Review',
                        'Hello ' . htmlspecialchars($vendor_name, ENT_QUOTES, 'UTF-8') . ',<br><br>Your compliance documents have passed automated screening and are now awaiting Super Admin review. Full admin console access unlocks automatically once approved.'
                    );
                }

                $sp_title = ($complete ? 'Vendor KYC Ready for Review: ' : 'Vendor KYC Updated: ') . $vendor_name;
                $sp_msg = $vendor_name . ' (Vendor #' . $vendor_id . ') submitted compliance files.' . ($complete ? ' Ready for review & approval.' : ' Still pending: ' . $pending_txt) . ($ai_txt !== '' ? ' ' . $ai_txt : '');
                bc_notify_admin($connection_server, 0, 'spadmin', $sp_title, $sp_msg, 'VendorKYC.php?status=' . ($complete ? 1 : 0), 'vendor_kyc');
                if ($complete) {
                    foreach (bc_compliance_lookup_spadmin_emails($connection_server) as $sp_email) {
                        bc_compliance_safe_email(
                            $connection_server, 0, $sp_email,
                            'Action Required: Vendor KYC Review for ' . $vendor_name,
                            'Hello Super Admin,<br><br>Vendor <b>' . htmlspecialchars($vendor_name, ENT_QUOTES, 'UTF-8') . '</b> (#' . $vendor_id . ') completed their KYC/KYB submission and is awaiting your review and approval.' . ($ai_txt !== '' ? '<br><br><b>AI Compliance Report:</b> ' . htmlspecialchars($ai_txt, ENT_QUOTES, 'UTF-8') : '') . '<br><br>Please open <b>Vendor KYC Review</b> in Super Admin.',
                            true
                        );
                    }
                }
                break;
            }

            case 'vendor_kyc_decision': {
                $decision = (string)($ctx['decision'] ?? '');
                $reason = trim((string)($ctx['reason'] ?? ''));
                $approved = ($decision === 'approve');
                $v_title = $approved ? 'Vendor Compliance Approved!' : 'Vendor Compliance Action Required';
                $v_msg = $approved
                    ? 'Your vendor KYC/KYB verification has been approved by Super Admin. Full dashboard access is unlocked!'
                    : 'Your vendor compliance submission requires attention: ' . ($reason !== '' ? $reason : 'Please resubmit.');
                bc_notify_admin($connection_server, $vendor_id, 'admin', $v_title, $v_msg, 'AccountSettings.php#tab-kyc', 'vendor_kyc');
                bc_notify_admin(
                    $connection_server, 0, 'spadmin',
                    'Vendor KYC ' . ($approved ? 'Approved' : 'Rejected') . ': ' . $vendor_name,
                    'Super Admin ' . ($approved ? 'approved' : 'rejected') . ' compliance for ' . $vendor_name . ' (#' . $vendor_id . ')' . ($reason !== '' ? ' — ' . $reason : ''),
                    'VendorKYC.php?status=' . ($approved ? 2 : 3),
                    'vendor_kyc'
                );
                break;
            }
        }
    }
}
