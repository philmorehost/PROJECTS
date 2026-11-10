<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Settings;

/**
 * Security & Transactional Email Notification Queue & Dispatcher.
 */
final class NotificationService
{
    public static function queue(?int $userId, string $recipientEmail, string $subject, string $body, string $eventType = 'security'): void
    {
        $email = trim($recipientEmail);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO notification_logs (user_id, recipient_email, subject, body, event_type, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $email, $subject, $body, $eventType, 'queued', bp_now()]);
        } catch (\Throwable) {
            // Never crash primary transaction or auth flow on notification logging failure
        }
    }

    public static function notifyUser(array $user, string $subject, string $body, string $eventType = 'security'): void
    {
        if (empty($user['enable_login_notifications']) && $eventType === 'security') {
            return;
        }
        self::queue(
            isset($user['id']) ? (int) $user['id'] : null,
            (string) ($user['email'] ?? ''),
            $subject,
            $body,
            $eventType
        );
    }

    public static function notifyAdmins(string $subject, string $body, string $eventType = 'admin_alert'): void
    {
        try {
            $pdo = Database::connection();
            $customEmail = trim(Settings::get('admin_alert_email', ''));
            if ($customEmail !== '' && filter_var($customEmail, FILTER_VALIDATE_EMAIL)) {
                self::queue(null, $customEmail, $subject, $body, $eventType);
                return;
            }
            $admins = $pdo->query("SELECT id, email, enable_login_notifications FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
            foreach ($admins as $admin) {
                if (!empty($admin['enable_login_notifications'])) {
                    self::queue((int) $admin['id'], (string) $admin['email'], $subject, $body, $eventType);
                }
            }
        } catch (\Throwable) {
            // Ignore when DB not migrated
        }
    }

    /**
     * Flush queued notifications via PHP mail() when running under cron.
     */
    public static function processQueue(int $limit = 25): int
    {
        $sentCount = 0;
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare("SELECT * FROM notification_logs WHERE status = 'queued' ORDER BY id ASC LIMIT " . max(1, $limit));
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $fromEmail = Settings::get('mail_from_address', 'security@' . LicenseService::normalizeDomain((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
            $fromName  = Settings::get('mail_from_name', Settings::get('site_title', 'BlockPayne'));
            $headers = "From: {$fromName} <{$fromEmail}>\r\n"
                . "Reply-To: {$fromEmail}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "X-Mailer: BlockPayne/" . BP_VERSION . "\r\n";

            $upd = $pdo->prepare('UPDATE notification_logs SET status = ? WHERE id = ?');
            foreach ($rows as $row) {
                $ok = function_exists('mail')
                    ? @mail((string) $row['recipient_email'], (string) $row['subject'], (string) $row['body'], $headers)
                    : false;
                $upd->execute([$ok ? 'sent' : 'failed', (int) $row['id']]);
                if ($ok) {
                    $sentCount++;
                }
            }
        } catch (\Throwable) {
            return $sentCount;
        }
        return $sentCount;
    }
}
