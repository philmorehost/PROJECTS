<?php
/**
 * bc-mail-queue.php — Background Bulk Email Processing (AIMarketing.php / SendMail.php)
 *
 * Campaigns are enqueued here instead of being sent inline in the HTTP request (the old
 * path did one blocking SMTP handshake per recipient inside the request — see
 * sendVendorEmailSpecific() in bc-func.php). cron/process_mail_queue.php drains the
 * queue at a configurable rate, independently of the admin's browser/connection.
 *
 * Each recipient's email is fully rendered (personalized + wrapped in the branded
 * template) at ENQUEUE time, not at send time. This matters because mailDesignTemplate()
 * reads $_SERVER['HTTP_HOST'] directly (for the logo URL, vendor lookup, and links) —
 * at enqueue time we're inside a real admin HTTP request so HTTP_HOST is already correct,
 * which avoids having to fake it inside the CLI cron. The cron only needs to resolve the
 * vendor's SMTP credentials (via $GLOBALS['vendor_id'] + resolveVendorID(true), the same
 * pattern cron/process_bulk_queue.php already uses) and hand the pre-rendered HTML to
 * customBCMailSender() as-is.
 */

if (function_exists('bc_enqueue_mail_campaign')) return;

function bc_ensure_mail_queue_schema($connection_server)
{
    static $done = false;
    if ($done) return;
    $done = true;

    $check = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_mail_campaigns'");
    if ($check && mysqli_num_rows($check) == 0) {
        mysqli_query($connection_server, "CREATE TABLE sas_mail_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vendor_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html LONGTEXT,
            source VARCHAR(30) NOT NULL DEFAULT 'sendmail',
            total_count INT NOT NULL DEFAULT 0,
            sent_count INT NOT NULL DEFAULT 0,
            failed_count INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_vendor_status (vendor_id, status)
        )");
    }

    $check_items = mysqli_query($connection_server, "SHOW TABLES LIKE 'sas_mail_queue_items'");
    if ($check_items && mysqli_num_rows($check_items) == 0) {
        mysqli_query($connection_server, "CREATE TABLE sas_mail_queue_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            vendor_id INT NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_name VARCHAR(150) DEFAULT NULL,
            from_name VARCHAR(150) DEFAULT NULL,
            rendered_subject VARCHAR(255) NOT NULL,
            rendered_html LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            claim_token VARCHAR(40) DEFAULT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            error_msg VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_status_queue (status, id),
            INDEX idx_campaign (campaign_id)
        )");
    }
}

/**
 * Single source of truth for who a campaign goes to. Replaces the old broken targeting
 * (AIMarketing.php offered all|api|smart|agent but sendVendorEmailSpecific() only
 * accepted all|a|b|d|bd, so api/smart/agent silently matched nobody; SendMail.php's
 * "Select User" option was separately broken by a strtolower() on the mailto value).
 *
 * $status_cohort: 'all' | 'active' | 'blocked' | 'deleted' | '' (skip internal users entirely)
 * $account_level: 0 (any) | 1 (smart) | 2 (agent) | 3 (api)
 * $external: array of raw strings (from a textarea/CSV upload) — split, validated, de-duped
 *
 * Returns array of ['email','name','firstname','lastname','username','phone','balance','address'].
 * External-only recipients get empty strings for the internal-only fields.
 */
function bc_resolve_campaign_recipients($connection_server, $vendor_id, $status_cohort, $account_level, array $external = [])
{
    $vendor_id_esc = (int)$vendor_id;
    $recipients = [];
    $seen = [];

    if (!empty($status_cohort)) {
        $status_map = [
            'all'     => "(status='1' OR status='2' OR status='3')",
            'active'  => "status='1'",
            'blocked' => "status='2'",
            'deleted' => "status='3'",
        ];
        $where = $status_map[$status_cohort] ?? $status_map['all'];
        if ((int)$account_level > 0) {
            $where .= " AND account_level='" . (int)$account_level . "'";
        }
        $q = mysqli_query($connection_server, "SELECT firstname, lastname, username, email, phone_number, home_address, balance FROM sas_users WHERE vendor_id='$vendor_id_esc' AND $where AND email != ''");
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $email_key = strtolower(trim($row['email']));
                if (empty($email_key) || isset($seen[$email_key])) continue;
                $seen[$email_key] = true;
                $recipients[] = [
                    'email'     => trim($row['email']),
                    'name'      => trim($row['firstname'] . ' ' . $row['lastname']),
                    'firstname' => $row['firstname'],
                    'lastname'  => $row['lastname'],
                    'username'  => $row['username'],
                    'phone'     => $row['phone_number'],
                    'balance'   => $row['balance'],
                    'address'   => $row['home_address'],
                ];
            }
        }
    }

    foreach ($external as $raw) {
        foreach (preg_split('/[\s,]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $addr) {
            $addr = trim($addr);
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) continue;
            $email_key = strtolower($addr);
            if (isset($seen[$email_key])) continue;
            $seen[$email_key] = true;
            $recipients[] = [
                'email' => $addr, 'name' => $addr, 'firstname' => '', 'lastname' => '',
                'username' => '', 'phone' => '', 'balance' => '', 'address' => '',
            ];
        }
    }

    return $recipients;
}

/**
 * Renders and queues a campaign. $subject/$body_html may contain the same placeholder
 * tokens sendVendorEmailSpecific() already supports ({firstname},{lastname},{username},
 * {address},{email},{phone},{balance},{website}) — substituted per recipient, then each
 * recipient's body is wrapped via mailDesignTemplate() (which produces an email-safe HTML
 * fragment with inline styles, and reduces full HTML documents to their body content —
 * see email-design.php — so GrapesJS output isn't double-wrapped or sent as a full document).
 *
 * Returns the new campaign_id, or 0 if there were no valid recipients.
 */
function bc_enqueue_mail_campaign($connection_server, $vendor_id, $subject, $body_html, array $recipients, $source, $from_name = null, $website_url = '')
{
    // Refuse to enqueue new campaigns in demo mode — testers must not be able to blast
    // real users or external addresses with bulk email.  The cron processor will also
    // skip any pending items when bc_is_demo_mode() is true, but blocking at enqueue
    // time is cleaner and works for both SAAS and NON-SAAS deployments.
    if (bc_is_demo_mode($connection_server)) return 0;

    bc_ensure_mail_queue_schema($connection_server);

    $recipients = array_values($recipients);
    if (empty($recipients)) return 0;

    $vendor_id_esc = (int)$vendor_id;
    $subject_esc = mysqli_real_escape_string($connection_server, $subject);
    $body_raw_esc = mysqli_real_escape_string($connection_server, $body_html);
    $source_esc = mysqli_real_escape_string($connection_server, $source);
    $from_name_esc = mysqli_real_escape_string($connection_server, (string)$from_name);

    mysqli_query($connection_server, "INSERT INTO sas_mail_campaigns (vendor_id, subject, body_html, source, total_count, status) VALUES ('$vendor_id_esc', '$subject_esc', '$body_raw_esc', '$source_esc', " . count($recipients) . ", 'queued')");
    $campaign_id = mysqli_insert_id($connection_server);

    // mailDesignTemplate() runs a vendor lookup plus a service-list query on every call — the
    // branded shell it produces (logo, active-services block, footer) is identical for every
    // recipient of the same campaign, only the subject/body inside it differ. Calling it once
    // per recipient (as this used to) meant a bulk send to hundreds/thousands of users did that
    // many redundant DB round trips synchronously inside this HTTP request — that's what made
    // "submit" hang instead of returning instantly. Render the shell ONCE with placeholder
    // tokens standing in for subject/body, then substitute the real per-recipient content with
    // a cheap in-memory str_replace — zero extra queries per recipient.
    $is_full_document = (strpos($body_html, '<html') !== false || strpos($body_html, '<body') !== false);
    $shell_html = null;
    $subject_token = '';
    $body_token = '';
    if (!$is_full_document) {
        $subject_token = '%%BCMAILQ_SUBJECT_' . bin2hex(random_bytes(6)) . '%%';
        $body_token = '%%BCMAILQ_BODY_' . bin2hex(random_bytes(6)) . '%%';
        $shell_html = mailDesignTemplate($subject_token, $body_token, [], true);
    }

    $rows = [];
    foreach ($recipients as $r) {
        $placeholders = [
            '{firstname}' => $r['firstname'] ?? '', '{lastname}' => $r['lastname'] ?? '',
            '{username}'  => $r['username'] ?? '', '{address}' => $r['address'] ?? '',
            '{email}'     => $r['email'] ?? '', '{phone}' => $r['phone'] ?? '',
            '{balance}'   => isset($r['balance']) && $r['balance'] !== '' ? toDecimal($r['balance'], 2) : '',
            '{website}'   => $website_url,
        ];
        $personal_subject = strtr($subject, $placeholders);
        $personal_body = strtr($body_html, $placeholders);

        // Full HTML documents (e.g. GrapesJS output) are reduced to their body content the
        // same way mailDesignTemplate() now does — email clients should never receive a full
        // <!DOCTYPE html><html><head>… document, or they render the raw source instead of
        // a formatted email.
        $rendered_html = $is_full_document
            ? bc_extractEmailBodyContent($personal_body)
            : str_replace([$subject_token, $body_token], [$personal_subject, $personal_body], $shell_html);

        $rows[] = "(" . implode(',', [
            $campaign_id,
            $vendor_id_esc,
            "'" . mysqli_real_escape_string($connection_server, $r['email']) . "'",
            "'" . mysqli_real_escape_string($connection_server, $r['name'] ?? '') . "'",
            "'" . $from_name_esc . "'",
            "'" . mysqli_real_escape_string($connection_server, $personal_subject) . "'",
            "'" . mysqli_real_escape_string($connection_server, $rendered_html) . "'",
            "'pending'",
        ]) . ")";

        // Insert in chunks so one query doesn't grow unbounded for very large lists.
        if (count($rows) >= 200) {
            mysqli_query($connection_server, "INSERT INTO sas_mail_queue_items (campaign_id, vendor_id, recipient_email, recipient_name, from_name, rendered_subject, rendered_html, status) VALUES " . implode(',', $rows));
            $rows = [];
        }
    }
    if (!empty($rows)) {
        mysqli_query($connection_server, "INSERT INTO sas_mail_queue_items (campaign_id, vendor_id, recipient_email, recipient_name, from_name, rendered_subject, rendered_html, status) VALUES " . implode(',', $rows));
    }

    return $campaign_id;
}

/**
 * Live progress for the polling report. Vendor-scoped so one tenant can never read
 * another's campaign by guessing an id.
 */
function bc_get_mail_campaign_progress($connection_server, $campaign_id, $vendor_id)
{
    bc_ensure_mail_queue_schema($connection_server);

    $campaign_id_esc = (int)$campaign_id;
    $vendor_id_esc = (int)$vendor_id;

    $campaign = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_mail_campaigns WHERE id='$campaign_id_esc' AND vendor_id='$vendor_id_esc' LIMIT 1"));
    if (!$campaign) return null;

    $counts = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
    $q = mysqli_query($connection_server, "SELECT status, COUNT(*) as c FROM sas_mail_queue_items WHERE campaign_id='$campaign_id_esc' GROUP BY status");
    while ($q && $row = mysqli_fetch_assoc($q)) {
        $counts[$row['status']] = (int)$row['c'];
    }

    return [
        'campaign_id' => $campaign_id,
        'subject'     => $campaign['subject'],
        'status'      => $campaign['status'],
        'total'       => (int)$campaign['total_count'],
        'sent'        => $counts['sent'],
        'failed'      => $counts['failed'],
        'pending'     => $counts['pending'] + $counts['processing'],
    ];
}

/**
 * Recent campaigns for the history list shown on page load (so progress is visible
 * even after the admin navigates away and comes back).
 */
function bc_get_recent_mail_campaigns($connection_server, $vendor_id, $limit = 10)
{
    bc_ensure_mail_queue_schema($connection_server);
    $vendor_id_esc = (int)$vendor_id;
    $limit_esc = (int)$limit;
    $rows = [];
    $q = mysqli_query($connection_server, "SELECT * FROM sas_mail_campaigns WHERE vendor_id='$vendor_id_esc' ORDER BY id DESC LIMIT $limit_esc");
    while ($q && $row = mysqli_fetch_assoc($q)) $rows[] = $row;
    return $rows;
}

/**
 * Every existing cron/*.php script is reachable over plain HTTPS with no guard at all
 * (no token, no CLI-only check, no .htaccess — confirmed across all 11 scripts). That's
 * tolerable for the read-mostly jobs that already exist, but this one drains outbound
 * email: an unauthenticated caller could repeatedly trigger it to force-send a vendor's
 * queue. cron/process_mail_queue.php requires this secret whenever it's not run via CLI.
 * Auto-provisions on first use so there's no separate setup step before the Developer
 * tab has something to display.
 */
function bc_get_or_create_cron_secret($connection_server)
{
    $existing = getSuperAdminOption('cron_secret', '');
    if (!empty($existing)) return $existing;

    // Deliberately not an INSERT ... ON DUPLICATE KEY UPDATE: sas_super_admin_options has
    // an older schema variant in the wild without a unique constraint on option_name (see
    // the license_key/license_domain fix in bc-levelup.php), where that upsert silently
    // appends duplicate rows instead of updating. Check-then-insert is correct on either
    // schema. cron_secret is a brand-new option with no prior writes from any code path,
    // so — unlike license_key — there's no pre-existing duplicate-row risk to guard
    // against here; a plain existence check is enough.
    $check = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='cron_secret' LIMIT 1");
    if ($check && $row = mysqli_fetch_assoc($check)) {
        if (!empty($row['option_value'])) return $row['option_value'];
    }

    $secret = bin2hex(random_bytes(20));
    $secret_esc = mysqli_real_escape_string($connection_server, $secret);
    mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('cron_secret', '$secret_esc')");
    return $secret;
}
