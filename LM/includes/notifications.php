<?php
/**
 * License Manager — Update notifications.
 *
 * Queues and delivers "a new version is available" emails to licensed
 * customers, then sends ONE reminder the next day to anyone who has not
 * updated yet (configurable interval + count).
 *
 * "Has the customer updated?" is decided from the version the target script
 * reports to check-update.php (`licensed_domains.installed_version`), and
 * optionally from whether they already downloaded the package.
 *
 * PHP 7.4 compatible.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/updates.php';

if (!function_exists('lm_changelog_sanitize')) {
    /**
     * Remove active content from admin-authored changelog HTML.
     *
     * @param string $html
     * @return string
     */
    function lm_changelog_sanitize($html)
    {
        $html = (string) $html;

        // Strip dangerous blocks entirely.
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta)\b[^>]*/?\s*>#is', '', $html);

        // Strip event handlers and javascript: URLs.
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/javascript\s*:/i', '', $html);
        $html = preg_replace('/expression\s*\(/i', '(', $html);

        return trim($html);
    }
}

if (!function_exists('lm_changelog_from_text')) {
    /**
     * Convert a plain-text changelog into clean HTML.
     *
     * @param string $text
     * @return string
     */
    function lm_changelog_from_text($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $lines = explode("\n", $text);

        $html = '';
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                continue;
            }

            $isBullet = (bool) preg_match('/^([-*•]|\d+[.)])\s+/u', $trimmed);
            $item = preg_replace('/^([-*•]|\d+[.)])\s+/u', '', $trimmed);
            $item = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
            $item = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $item);
            $item = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $item);

            if ($isBullet) {
                if (!$inList) {
                    $html .= '<ul>';
                    $inList = true;
                }
                $html .= '<li>' . $item . '</li>';
            } else {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                $html .= '<p>' . $item . '</p>';
            }
        }

        if ($inList) {
            $html .= '</ul>';
        }

        return $html !== '' ? $html : '<p>Performance improvements and bug fixes.</p>';
    }
}

if (!function_exists('lm_changelog_html')) {
    /**
     * @param string $changelog
     * @param string $format html|text
     * @return string
     */
    function lm_changelog_html($changelog, $format = 'html')
    {
        if (trim((string) $changelog) === '') {
            return '<p>Performance improvements and bug fixes.</p>';
        }
        if ($format === 'text') {
            return lm_changelog_from_text($changelog);
        }
        return lm_changelog_sanitize($changelog);
    }
}

if (!function_exists('lm_changelog_plain')) {
    /**
     * Plain-text changelog for the AltBody.
     *
     * @param string $changelog
     * @param string $format
     * @return string
     */
    function lm_changelog_plain($changelog, $format = 'html')
    {
        $changelog = (string) $changelog;
        if (trim($changelog) === '') {
            return 'Performance improvements and bug fixes.';
        }
        if ($format === 'text') {
            return $changelog;
        }

        $text = preg_replace('#<\s*li[^>]*>#i', "- ", $changelog);
        $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
        $text = preg_replace('#<\s*/\s*(p|li|h[1-6]|div|ul|ol)\s*>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}

if (!function_exists('lm_update_email_site_logo')) {
    /**
     * Resolve the configured logo to a public URL (or '' when missing).
     *
     * @return string
     */
    function lm_update_email_site_logo()
    {
        $settings = lm_settings();
        $logo = trim((string) ($settings['site_logo'] ?? ''));
        if ($logo === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $logo)) {
            return $logo;
        }

        $relative = ltrim(str_replace('\\', '/', $logo), '/');
        if (!is_file(lm_lm_dir() . DIRECTORY_SEPARATOR . $relative)) {
            return '';
        }

        return lm_site_url($relative);
    }
}

if (!function_exists('lm_render_update_email')) {
    /**
     * Render the update / reminder email.
     *
     * @param array  $update   script_updates row
     * @param array  $tier     script_tiers row
     * @param array  $license  licenses row (may be partial)
     * @param string $kind     initial|reminder|resend
     * @param array  $extra    download_url
     * @return array{subject:string,html:string,alt:string}
     */
    function lm_render_update_email(array $update, array $tier, array $license, $kind = 'initial', array $extra = array())
    {
        $settings = lm_settings();

        $templatePath = lm_lm_dir() . '/email_templates/update_available.html';
        $template = is_file($templatePath) ? (string) file_get_contents($templatePath) : '';
        if ($template === '') {
            $template = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#111;"><h2>{{HEADLINE}}</h2><p>{{INTRO}}</p><div>{{CHANGELOG}}</div><p><a href="{{CTA_URL}}">{{CTA_LABEL}}</a></p></body></html>';
        }

        $siteName = lm_site_name();
        $version = $update['version_number'];
        $tierName = $tier['tier_name'] ?? ($tier['tier_code'] ?? 'Script');
        $domain = $license['domain'] ?? ($license['customer_email'] ?? '');
        if ($domain === '' || $domain === 'N/A') {
            $domain = 'your website';
        }

        $isReminder = ($kind === 'reminder');
        $format = $update['changelog_format'] ?? 'html';
        $changelogHtml = lm_changelog_html($update['changelog'] ?? '', $format);
        $changelogText = lm_changelog_plain($update['changelog'] ?? '', $format);

        $releaseTs = !empty($update['release_date']) ? strtotime($update['release_date']) : time();
        $releaseDate = gmdate('j M Y', $releaseTs);
        $year = gmdate('Y');
        $supportEmail = trim((string) ($settings['admin_email'] ?? ''));
        if ($supportEmail === '') {
            $supportEmail = 'support@' . preg_replace('#^www\.#', '', (string) (parse_url(lm_site_url(), PHP_URL_HOST) ?: 'localhost'));
        }

        $ctaUrl = lm_site_url('user/login.php');
        $ctaLabel = 'Open your dashboard';
        $ctaNote = 'Sign in to download the package and follow the install steps.';

        if (!empty($extra['download_url'])) {
            $ctaUrl = $extra['download_url'];
            $ctaLabel = 'Download update v' . $version;
            $ctaNote = 'This secure link is unique to your licence and expires in 7 days. You can also sign in at '
                . '<a href="' . htmlspecialchars(lm_site_url('user/login.php'), ENT_QUOTES, 'UTF-8') . '" style="color:#6366f1;">'
                . htmlspecialchars(lm_site_url('user/login.php'), ENT_QUOTES, 'UTF-8') . '</a>.';
        }

        $unsubToken = lm_unsubscribe_token((int) ($license['id'] ?? 0), (string) ($license['customer_email'] ?? ''));
        $unsubUrl = lm_site_url('unsubscribe.php?t=' . rawurlencode($unsubToken));

        $subject = $isReminder
            ? 'Reminder: ' . $tierName . ' v' . $version . ' is still available'
            : 'New update available: ' . $tierName . ' v' . $version;

        if ($siteName !== '') {
            $subject = $siteName . ' — ' . $subject;
        }

        if ($isReminder) {
            $accent = '#b45309';
            $accent2 = '#f59e0b';
            $badgeBg = 'rgba(255,255,255,0.16)';
            $badgeColor = '#fff7ed';
            $badgeText = 'Still pending';
            $eyebrow = 'A quick reminder';
            $headline = 'Your update is ready when you are';
            $intro = 'A little while ago we released <strong>' . htmlspecialchars($tierName, ENT_QUOTES, 'UTF-8') . ' v' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
                . '</strong>. Our records show that <strong>' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</strong> has not been updated yet, so here is one friendly nudge.'
                . ' Installing the latest version keeps your site secure and gives you everything listed below.';
            $preheader = 'Reminder: ' . $tierName . ' v' . $version . ' has not been installed on ' . $domain . ' yet.';
        } else {
            $accent = '#4338ca';
            $accent2 = '#06b6d4';
            $badgeBg = 'rgba(255,255,255,0.16)';
            $badgeColor = '#eef2ff';
            $badgeText = 'Update available';
            $eyebrow = 'Fresh release';
            $headline = 'A new version is ready for your website';
            $intro = 'We have just published <strong>' . htmlspecialchars($tierName, ENT_QUOTES, 'UTF-8') . ' v' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
                . '</strong> for <strong>' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</strong>. It includes the improvements below and is ready to install from your dashboard.';
            $preheader = $tierName . ' v' . $version . ' is now available for ' . $domain . '.';
        }

        $logoUrl = lm_update_email_site_logo();
        $logoBlock = '';
        if ($logoUrl !== '') {
            $logoBlock = '<td valign="middle" style="padding-right:10px;">'
                . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" height="34" style="display:block;height:34px;width:auto;border-radius:8px;">'
                . '</td>';
        }

        $replacements = array(
            '{{SUBJECT}}'          => htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
            '{{PREHEADER}}'        => htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8'),
            '{{SITE_NAME}}'        => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
            '{{LOGO_BLOCK}}'       => $logoBlock,
            '{{BADGE_TEXT}}'       => $badgeText,
            '{{BADGE_BG}}'         => $badgeBg,
            '{{BADGE_COLOR}}'      => $badgeColor,
            '{{EYEBROW}}'          => htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8'),
            '{{HEADLINE}}'         => htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'),
            '{{INTRO}}'            => $intro,
            '{{ACCENT}}'           => $accent,
            '{{ACCENT_2}}'         => $accent2,
            '{{TIER_LABEL}}'       => 'Target script',
            '{{TIER_NAME}}'        => htmlspecialchars($tierName, ENT_QUOTES, 'UTF-8'),
            '{{VERSION}}'          => htmlspecialchars($version, ENT_QUOTES, 'UTF-8'),
            '{{RELEASE_DATE}}'     => $releaseDate,
            '{{CHANGELOG}}'        => $changelogHtml,
            '{{CTA_URL}}'          => htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8'),
            '{{CTA_LABEL}}'        => htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8'),
            '{{CTA_NOTE}}'         => $ctaNote,
            '{{LICENSE_DOMAIN}}'   => htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'),
            '{{PACKAGE_NAME}}'     => 'update_' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '.zip',
            '{{SUPPORT_EMAIL}}'    => htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'),
            '{{UNSUBSCRIBE_URL}}'  => htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8'),
            '{{YEAR}}'             => $year,
        );

        $html = str_replace(array_keys($replacements), array_values($replacements), $template);

        $alt = $subject . "\n\n"
            . strtoupper($headline) . "\n\n"
            . trim(preg_replace('/\s+/', ' ', strip_tags($intro))) . "\n\n"
            . "What's new in v" . $version . ":\n" . $changelogText . "\n\n"
            . 'Install: ' . $ctaUrl . "\n"
            . 'Licensed domain: ' . $domain . "\n"
            . 'Released: ' . $releaseDate . "\n\n"
            . 'Stop receiving update reminders: ' . $unsubUrl . "\n"
            . '© ' . $year . ' ' . $siteName;

        return array('subject' => $subject, 'html' => $html, 'alt' => $alt);
    }
}

if (!function_exists('lm_customer_recipients')) {
    /**
     * Unique, emailable customers for a tier.
     *
     * @param PDO $pdo
     * @param int $tierId
     * @return array<int,array{id:int,email:string,domain:string}>
     */
    function lm_customer_recipients(PDO $pdo, $tierId)
    {
        $stmt = $pdo->prepare(
            "SELECT id, customer_email, domain, notify_updates
             FROM licenses
             WHERE tier_id = ? AND status = 'active'
               AND customer_email IS NOT NULL AND customer_email <> ''
             ORDER BY id ASC"
        );
        $stmt->execute(array((int) $tierId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $seen = array();
        $recipients = array();

        foreach ($rows as $row) {
            $email = strtolower(trim($row['customer_email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (strpos($email, 'unknown@') === 0 || strpos($email, 'n/a') === 0) {
                continue;
            }
            if ((int) ($row['notify_updates'] ?? 1) === 0) {
                continue;
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $recipients[] = array(
                'id' => (int) $row['id'],
                'email' => $email,
                'domain' => $row['domain'],
            );
        }

        return $recipients;
    }
}

if (!function_exists('lm_license_installed_version')) {
    /**
     * Highest version reported by any domain of a licence.
     *
     * @param PDO $pdo
     * @param int $licenseId
     * @return string
     */
    function lm_license_installed_version(PDO $pdo, $licenseId)
    {
        try {
            $stmt = $pdo->prepare("SELECT installed_version FROM licensed_domains WHERE license_id = ? AND installed_version IS NOT NULL AND installed_version <> ''");
            $stmt->execute(array((int) $licenseId));
            $versions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return '';
        }

        $highest = '';
        foreach ($versions as $version) {
            if ($highest === '' || lm_version_compare($version, $highest) > 0) {
                $highest = $version;
            }
        }

        return $highest;
    }
}

if (!function_exists('lm_license_downloaded_update')) {
    /**
     * @param PDO $pdo
     * @param int $licenseId
     * @param int $updateId
     * @return bool
     */
    function lm_license_downloaded_update(PDO $pdo, $licenseId, $updateId)
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM download_tokens WHERE license_id = ? AND update_id = ? AND is_used = 1");
            $stmt->execute(array((int) $licenseId, (int) $updateId));
            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('lm_create_update_download_link')) {
    /**
     * One-time, 7-day download link for a specific licence/update.
     *
     * @param PDO   $pdo
     * @param int   $updateId
     * @param int   $licenseId
     * @param int   $days
     * @return string
     */
    function lm_create_update_download_link(PDO $pdo, $updateId, $licenseId, $days = 7)
    {
        try {
            $token = bin2hex(random_bytes(32));
            $expires = gmdate('Y-m-d H:i:s', time() + (max(1, (int) $days) * 86400));
            $stmt = $pdo->prepare("INSERT INTO download_tokens (license_id, update_id, token, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->execute(array((int) $licenseId, (int) $updateId, $token, $expires));
            return lm_site_url('download.php?token=' . $token);
        } catch (Exception $e) {
            return lm_site_url('user/login.php');
        }
    }
}

if (!function_exists('lm_queue_update_notifications')) {
    /**
     * Queue the first "update available" email for every customer of a tier.
     *
     * @param PDO   $pdo
     * @param int   $updateId
     * @param array $options delay_minutes
     * @return array{queued:int,skipped:int,recipients:int,error:string}
     */
    function lm_queue_update_notifications(PDO $pdo, $updateId, array $options = array())
    {
        $stats = array('queued' => 0, 'skipped' => 0, 'recipients' => 0, 'error' => '');

        $settings = lm_settings();
        if (empty($settings['update_notify_enabled'])) {
            $stats['error'] = 'Update notifications are disabled in Settings.';
            return $stats;
        }

        $stmt = $pdo->prepare("SELECT u.*, t.tier_code, t.tier_name FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.id = ?");
        $stmt->execute(array((int) $updateId));
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            $stats['error'] = 'Update not found.';
            return $stats;
        }

        $delay = isset($options['delay_minutes']) && $options['delay_minutes'] !== null
            ? max(0, (int) $options['delay_minutes'])
            : max(0, (int) ($settings['update_notify_delay_minutes'] ?? 0));

        $sendAfter = gmdate('Y-m-d H:i:s', time() + ($delay * 60));
        $recipients = lm_customer_recipients($pdo, (int) $update['tier_id']);
        $stats['recipients'] = count($recipients);

        foreach ($recipients as $recipient) {
            // Skip customers already running this version or newer.
            $installed = lm_license_installed_version($pdo, $recipient['id']);
            if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                $stats['skipped']++;
                continue;
            }

            $unsub = lm_unsubscribe_token($recipient['id'], $recipient['email']);

            try {
                $insert = $pdo->prepare(
                    "INSERT IGNORE INTO update_notifications
                        (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                     VALUES (?, ?, ?, ?, 'initial', 0, 'pending', 0, ?, ?)"
                );
                $insert->execute(array(
                    (int) $update['id'],
                    (int) $update['tier_id'],
                    $recipient['id'],
                    $recipient['email'],
                    $sendAfter,
                    $unsub,
                ));
                if ($insert->rowCount() > 0) {
                    $stats['queued']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (PDOException $e) {
                $stats['error'] = 'Could not queue notifications: ' . $e->getMessage();
                lm_log('notifications', $stats['error']);
                break;
            }
        }

        // Mark the update as announced.
        try {
            $pdo->prepare("UPDATE script_updates SET notified_at = NOW() WHERE id = ?")->execute(array((int) $update['id']));
        } catch (PDOException $e) {
            // column may not exist on a partially migrated install
        }

        lm_log('notifications', 'Queued ' . $stats['queued'] . ' notification(s) for ' . $update['tier_code'] . ' v' . $update['version_number'] . ' (' . $stats['skipped'] . ' skipped).');

        return $stats;
    }
}

if (!function_exists('lm_dispatch_update_notifications')) {
    /**
     * Send due notifications and schedule the next-day reminder.
     *
     * @param PDO $pdo
     * @param int $limit
     * @param bool $dryRun
     * @return array{sent:int,reminders:int,failed:int,skipped:int,processed:int,errors:array,disabled:bool}
     */
    function lm_dispatch_update_notifications(PDO $pdo, $limit = 25, $dryRun = false)
    {
        $stats = array(
            'sent' => 0,
            'reminders' => 0,
            'failed' => 0,
            'skipped' => 0,
            'processed' => 0,
            'errors' => array(),
            'disabled' => false,
        );

        $settings = lm_settings();
        if (empty($settings['update_notify_enabled'])) {
            $stats['disabled'] = true;
            return $stats;
        }

        if (empty(lm_smtp_plan($settings))) {
            $stats['errors'][] = 'SMTP is not configured — notifications stay queued.';
            return $stats;
        }

        $limit = max(1, min(100, (int) $limit));
        $reminderHours = max(1, (int) ($settings['update_reminder_hours'] ?? 24));
        $maxReminders = max(0, min(5, (int) ($settings['update_reminder_max'] ?? 1)));
        $skipIfDownloaded = !empty($settings['update_skip_if_downloaded']);

        // Recover rows left in "sending" by a crashed worker.
        try {
            $pdo->exec("UPDATE update_notifications SET status = 'pending' WHERE status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)");
        } catch (PDOException $e) {
            // ignore
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM update_notifications
             WHERE status = 'pending' AND send_after <= NOW()
             ORDER BY send_after ASC, id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($due as $row) {
            $stats['processed']++;
            $id = (int) $row['id'];

            // Claim the row.
            $claim = $pdo->prepare("UPDATE update_notifications SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'pending'");
            $claim->execute(array($id));
            if ($claim->rowCount() === 0) {
                continue;
            }

            if ($dryRun) {
                $pdo->prepare("UPDATE update_notifications SET status = 'pending' WHERE id = ?")->execute(array($id));
                $stats['sent']++;
                continue;
            }

            $updStmt = $pdo->prepare("SELECT u.*, t.tier_code, t.tier_name FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.id = ?");
            $updStmt->execute(array((int) $row['update_id']));
            $update = $updStmt->fetch(PDO::FETCH_ASSOC);

            if (!$update) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Update no longer exists' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            $licStmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ?");
            $licStmt->execute(array((int) $row['license_id']));
            $license = $licStmt->fetch(PDO::FETCH_ASSOC);

            if (!$license) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Licence no longer exists' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            if (($license['status'] ?? '') !== 'active' || (int) ($license['notify_updates'] ?? 1) === 0) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Licence inactive or notifications disabled' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            // Already installed? Then no email at all.
            $installed = lm_license_installed_version($pdo, (int) $license['id']);
            if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Customer already updated' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            // A newer release exists; the reminder for the old one is pointless.
            $latest = lm_latest_update_for_tier($pdo, (int) $update['tier_id'], true);
            if ($row['kind'] === 'reminder' && $latest && (int) $latest['id'] !== (int) $update['id']) {
                $pdo->prepare("UPDATE update_notifications SET status = 'superseded', last_error = 'A newer version was published' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            if ($row['kind'] === 'reminder' && $skipIfDownloaded && lm_license_downloaded_update($pdo, (int) $license['id'], (int) $update['id'])) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Package already downloaded' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            $downloadUrl = lm_create_update_download_link($pdo, (int) $update['id'], (int) $license['id'], 7);
            $email = lm_render_update_email($update, $update, $license, $row['kind'], array('download_url' => $downloadUrl));

            $result = lm_send_mail($license['customer_email'], $email['subject'], $email['html'], $email['alt']);

            if ($result['ok']) {
                $pdo->prepare("UPDATE update_notifications SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")->execute(array($id));

                if ($row['kind'] === 'initial') {
                    $stats['sent']++;
                    $reminderNo = 1;
                } else {
                    $stats['reminders']++;
                    $reminderNo = (int) $row['reminder_no'] + 1;
                }

                // Schedule the next reminder (default: exactly one, next day).
                if ($reminderNo <= $maxReminders) {
                    $sendAt = gmdate('Y-m-d H:i:s', time() + ($reminderHours * 3600));
                    try {
                        $pdo->prepare(
                            "INSERT IGNORE INTO update_notifications
                                (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                             VALUES (?, ?, ?, ?, 'reminder', ?, 'pending', 0, ?, ?)"
                        )->execute(array(
                            (int) $update['id'],
                            (int) $update['tier_id'],
                            (int) $license['id'],
                            $license['customer_email'],
                            $reminderNo,
                            $sendAt,
                            $row['unsub_token'],
                        ));
                    } catch (PDOException $e) {
                        lm_log('notifications', 'Could not schedule reminder: ' . $e->getMessage());
                    }
                }
            } else {
                $attempts = (int) $row['attempts'];

                if ($attempts >= 3) {
                    $pdo->prepare("UPDATE update_notifications SET status = 'failed', last_error = ? WHERE id = ?")
                        ->execute(array(substr($result['error'], 0, 2000), $id));
                } else {
                    $retryAt = gmdate('Y-m-d H:i:s', time() + (15 * 60 * $attempts));
                    $pdo->prepare("UPDATE update_notifications SET status = 'pending', last_error = ?, send_after = ? WHERE id = ?")
                        ->execute(array(substr($result['error'], 0, 2000), $retryAt, $id));
                }

                $stats['failed']++;
                $stats['errors'][] = 'Notification #' . $id . ': ' . $result['error'];
            }
        }

        return $stats;
    }
}

if (!function_exists('lm_notification_summary')) {
    /**
     * Status counters for one update.
     *
     * @param PDO $pdo
     * @param int $updateId
     * @return array
     */
    function lm_notification_summary(PDO $pdo, $updateId)
    {
        $summary = array(
            'total' => 0,
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'reminders_sent' => 0,
            'reminders_pending' => 0,
        );

        try {
            $stmt = $pdo->prepare("SELECT kind, status, COUNT(*) AS c FROM update_notifications WHERE update_id = ? GROUP BY kind, status");
            $stmt->execute(array((int) $updateId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $count = (int) $row['c'];
                $summary['total'] += $count;
                $status = $row['status'];
                if (isset($summary[$status])) {
                    $summary[$status] += $count;
                }
                if ($row['kind'] === 'reminder' && $status === 'sent') {
                    $summary['reminders_sent'] += $count;
                }
                if ($row['kind'] === 'reminder' && $status === 'pending') {
                    $summary['reminders_pending'] += $count;
                }
            }
        } catch (PDOException $e) {
            // table missing on a fresh install
        }

        return $summary;
    }
}

if (!function_exists('lm_requeue_update_notifications')) {
    /**
     * Re-send notifications for an update.
     *
     * @param PDO    $pdo
     * @param int    $updateId
     * @param string $audience failed|pending|all
     * @return array{ok:bool,queued:int,message:string}
     */
    function lm_requeue_update_notifications(PDO $pdo, $updateId, $audience = 'failed')
    {
        $stmt = $pdo->prepare("SELECT * FROM script_updates WHERE id = ?");
        $stmt->execute(array((int) $updateId));
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            return array('ok' => false, 'queued' => 0, 'message' => 'Update not found.');
        }

        $queued = 0;

        if ($audience === 'failed') {
            $stmt = $pdo->prepare("UPDATE update_notifications SET status = 'pending', attempts = 0, send_after = NOW() WHERE update_id = ? AND status = 'failed'");
            $stmt->execute(array((int) $updateId));
            $queued = $stmt->rowCount();
            return array('ok' => true, 'queued' => $queued, 'message' => $queued . ' failed notification(s) re-queued.');
        }

        // Send a reminder now to every customer who is still behind, even if
        // their scheduled reminder is not due yet.
        if ($audience === 'reminder') {
            $recipients = lm_customer_recipients($pdo, (int) $update['tier_id']);
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(reminder_no), 0) FROM update_notifications WHERE update_id = ? AND kind = 'reminder'");
            $maxStmt->execute(array((int) $updateId));
            $batch = (int) $maxStmt->fetchColumn() + 1;

            foreach ($recipients as $recipient) {
                $installed = lm_license_installed_version($pdo, $recipient['id']);
                if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                    continue;
                }
                try {
                    $pdo->prepare(
                        "INSERT IGNORE INTO update_notifications
                            (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                         VALUES (?, ?, ?, ?, 'reminder', ?, 'pending', 0, NOW(), ?)"
                    )->execute(array(
                        (int) $update['id'],
                        (int) $update['tier_id'],
                        $recipient['id'],
                        $recipient['email'],
                        $batch,
                        lm_unsubscribe_token($recipient['id'], $recipient['email']),
                    ));
                    if ($pdo->lastInsertId()) {
                        $queued++;
                    }
                } catch (PDOException $e) {
                    // duplicate batch — ignore
                }
            }

            return array('ok' => true, 'queued' => $queued, 'message' => $queued . ' reminder(s) queued for customers who are still behind.');
        }

        // Send to everyone still behind, as a new "resend" batch.
        $recipients = lm_customer_recipients($pdo, (int) $update['tier_id']);
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(reminder_no), 0) FROM update_notifications WHERE update_id = ? AND kind = 'resend'");
        $maxStmt->execute(array((int) $updateId));
        $batch = (int) $maxStmt->fetchColumn() + 1;

        foreach ($recipients as $recipient) {
            $installed = lm_license_installed_version($pdo, $recipient['id']);
            if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                continue;
            }
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO update_notifications
                        (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                     VALUES (?, ?, ?, ?, 'resend', ?, 'pending', 0, NOW(), ?)"
                )->execute(array(
                    (int) $update['id'],
                    (int) $update['tier_id'],
                    $recipient['id'],
                    $recipient['email'],
                    $batch,
                    lm_unsubscribe_token($recipient['id'], $recipient['email']),
                ));
                if ($pdo->lastInsertId()) {
                    $queued++;
                }
            } catch (PDOException $e) {
                // duplicate batch — ignore
            }
        }

        return array('ok' => true, 'queued' => $queued, 'message' => $queued . ' customer(s) queued for a fresh notification.');
    }
}

if (!function_exists('lm_notification_log')) {
    /**
     * Recent notification rows for the admin log.
     *
     * @param PDO $pdo
     * @param int $limit
     * @param array $filters update_id, tier_id, status
     * @return array
     */
    function lm_notification_log(PDO $pdo, $limit = 100, array $filters = array())
    {
        $sql = "SELECT n.*, u.version_number, t.tier_code, t.tier_name
                FROM update_notifications n
                LEFT JOIN script_updates u ON n.update_id = u.id
                LEFT JOIN script_tiers t ON n.tier_id = t.id
                WHERE 1=1";
        $params = array();

        if (!empty($filters['update_id'])) {
            $sql .= " AND n.update_id = ?";
            $params[] = (int) $filters['update_id'];
        }
        if (!empty($filters['tier_id'])) {
            $sql .= " AND n.tier_id = ?";
            $params[] = (int) $filters['tier_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND n.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY n.id DESC LIMIT " . max(1, min(500, (int) $limit));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
