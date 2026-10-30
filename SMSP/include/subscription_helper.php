<?php
// Subscription Monitoring Engine Helper
require_once __DIR__ . '/mail_helper.php';

class SubscriptionHelper {
    public static function checkAndProcess($pdo) {
        // Run check at most once per 5 minutes to avoid DB overhead
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $last_check = $_SESSION['last_sub_check'] ?? 0;
        if (time() - $last_check < 300) {
            return; // Throttle checks
        }
        $_SESSION['last_sub_check'] = time();

        try {
            // Fetch all schools (except super admins who have no school constraints)
            $schools = $pdo->query("SELECT * FROM `sm_schools` WHERE `id` != 'global'")->fetchAll();
            
            $warn_days_1 = (int)(defined('SUBSCRIPTION_WARN_DAYS_1') ? SUBSCRIPTION_WARN_DAYS_1 : 30);
            $warn_days_2 = (int)(defined('SUBSCRIPTION_WARN_DAYS_2') ? SUBSCRIPTION_WARN_DAYS_2 : 7);
            $grace_days  = (int)(defined('SUBSCRIPTION_GRACE_DAYS') ? SUBSCRIPTION_GRACE_DAYS : 3);
            $auto_suspend = (defined('AUTO_SUSPEND_EXPIRED') ? AUTO_SUSPEND_EXPIRED : '1') === '1';
            $super_admin_email = defined('PLATFORM_SUPPORT_EMAIL') ? PLATFORM_SUPPORT_EMAIL : 'support@schoolapp.ng';

            foreach ($schools as $school) {
                if (empty($school['subscription_expires_at'])) {
                    continue; // Skip schools with no expiration date set
                }

                $expiryTime = strtotime($school['subscription_expires_at']);
                $currentTime = time();
                $secondsLeft = $expiryTime - $currentTime;
                $daysLeft = $secondsLeft / 86400;

                // Find school admin email
                $stmtEmail = $pdo->prepare("SELECT email FROM `sm_moderators` WHERE `school_id_number` = ? LIMIT 1");
                $stmtEmail->execute([$school['id']]);
                $admin_email = $stmtEmail->fetchColumn();

                if (!$admin_email) {
                    $admin_email = $super_admin_email; // fallback
                }

                if ($daysLeft <= 0) {
                    // ── EXPIRATION HAS OCCURRED ──
                    
                    // Check if package has auto_renew enabled
                    $pkg = null;
                    if (!empty($school['package_id'])) {
                        $stmtPkg = $pdo->prepare("SELECT * FROM `sm_subscription_packages` WHERE `id` = ?");
                        $stmtPkg->execute([$school['package_id']]);
                        $pkg = $stmtPkg->fetch();
                    }

                    if ($pkg && (int)($pkg['auto_renew'] ?? 0) === 1) {
                        // Automatically renew
                        $cycle = $pkg['billing_cycle'];
                        if ($cycle === 'monthly') {
                            $newExpiry = date('Y-m-d H:i:s', strtotime('+1 month'));
                        } elseif ($cycle === 'lifetime') {
                            $newExpiry = date('Y-m-d H:i:s', strtotime('+50 years'));
                        } else {
                            $newExpiry = date('Y-m-d H:i:s', strtotime('+1 year'));
                        }

                        $stmtUpdate = $pdo->prepare("UPDATE `sm_schools` SET `subscription_expires_at` = ?, `subscription_status` = 'active', `status` = 'active', `subscription_expiry_warned` = 0, `subscription_suspended_notified` = 0 WHERE `id` = ?");
                        $stmtUpdate->execute([$newExpiry, $school['id']]);

                        $ref = 'AUTORENEW-' . strtoupper(bin2hex(random_bytes(6)));
                        $now = date('Y-m-d H:i:s');
                        $stmtLog = $pdo->prepare("INSERT INTO `sm_addon_purchases` (`school_id_number`, `addon_name`, `amount_paid`, `payment_method`, `transaction_ref`, `status`, `purchase_date`) VALUES (?, ?, 0.00, 'Auto-Renewal', ?, 'approved', ?)");
                        $stmtLog->execute([$school['id'], 'Subscription Auto-Renew: ' . $pkg['name'], $ref, $now]);
                        
                        $daysLeft = 30; // mock positive value for subsequent warning checks
                        continue;
                    }

                    // Mark as expired in DB
                    if ($school['subscription_status'] !== 'expired') {
                        $stmt = $pdo->prepare("UPDATE `sm_schools` SET `subscription_status` = 'expired' WHERE `id` = ?");
                        $stmt->execute([$school['id']]);
                    }

                    // Check Grace Period Expiry
                    $graceExpiryTime = $expiryTime + ($grace_days * 86400);
                    if ($currentTime > $graceExpiryTime && $auto_suspend) {
                        // Suspend school portal (status = inactive)
                        if ($school['status'] !== 'inactive') {
                            $stmt = $pdo->prepare("UPDATE `sm_schools` SET `status` = 'inactive' WHERE `id` = ?");
                            $stmt->execute([$school['id']]);
                        }

                        // Send suspension email if not notified yet
                        if ($school['subscription_suspended_notified'] == 0) {
                            $subject = "🚨 School Portal Suspension Notice - " . $school['name'];
                            $body = "
                                <html>
                                <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                    <div style='max-width: 600px; margin: 0 auto; border: 1px solid #e1e1e1; padding: 20px; border-radius: 10px;'>
                                        <h2 style='color: #d9534f;'>Portal Suspended</h2>
                                        <p>Dear Administrator,</p>
                                        <p>This is to inform you that the portal for <strong>{$school['name']}</strong> has been <strong>suspended</strong> because your subscription has expired and the {$grace_days}-day grace period has passed.</p>
                                        <p>Your users (Teachers, Students, and Parents) will no longer be able to access the portal dashboard until a renewal is completed.</p>
                                        <h3 style='color: #337ab7;'>How to Reactivate:</h3>
                                        <ol>
                                            <li>Log in to your Administrator account.</li>
                                            <li>You will be automatically directed to the Renewal Billing screen.</li>
                                            <li>Select a subscription package and complete the payment online.</li>
                                        </ol>
                                        <p>Once paid, your portal will be unsuspended and reactivated instantly.</p>
                                        <hr style='border: none; border-top: 1px solid #e1e1e1;' />
                                        <p style='font-size: 11px; color: #777;'>SchoolApp Multitenant Platform Support.</p>
                                    </div>
                                </body>
                                </html>
                            ";
                            MailHelper::sendHTML($admin_email, $subject, $body, $super_admin_email);

                            $stmt = $pdo->prepare("UPDATE `sm_schools` SET `subscription_suspended_notified` = 1 WHERE `id` = ?");
                            $stmt->execute([$school['id']]);
                        }
                    } else {
                        // Expired but inside grace period
                        if ($school['subscription_expiry_warned'] < 3) {
                            $subject = "⚠️ Portal Subscription Expired (Grace Period Active) - " . $school['name'];
                            $body = "
                                <html>
                                <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                    <div style='max-width: 600px; margin: 0 auto; border: 1px solid #e1e1e1; padding: 20px; border-radius: 10px;'>
                                        <h2 style='color: #f0ad4e;'>Subscription Expired</h2>
                                        <p>Dear Administrator,</p>
                                        <p>Your subscription for <strong>{$school['name']}</strong> has expired. Your portal is currently running within a <strong>{$grace_days}-day grace period</strong> before total suspension.</p>
                                        <p>Please renew your subscription immediately to avoid service interruption.</p>
                                        <hr style='border: none; border-top: 1px solid #e1e1e1;' />
                                        <p style='font-size: 11px; color: #777;'>SchoolApp Platform Support.</p>
                                    </div>
                                </body>
                                </html>
                            ";
                            MailHelper::sendHTML($admin_email, $subject, $body, $super_admin_email);

                            $stmt = $pdo->prepare("UPDATE `sm_schools` SET `subscription_expiry_warned` = 3 WHERE `id` = ?");
                            $stmt->execute([$school['id']]);
                        }
                    }
                } elseif ($daysLeft <= $warn_days_2) {
                    // Expiry within 7 days
                    if ($school['subscription_expiry_warned'] < 2) {
                        $subject = "⏳ Urgent: Subscription Expiring in " . round($daysLeft) . " Days - " . $school['name'];
                        $body = "
                            <html>
                            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                <div style='max-width: 600px; margin: 0 auto; border: 1px solid #e1e1e1; padding: 20px; border-radius: 10px;'>
                                    <h2>Urgent Renewal Reminder</h2>
                                    <p>Dear Administrator,</p>
                                    <p>Your portal subscription for <strong>{$school['name']}</strong> will expire on <strong>{$school['subscription_expires_at']}</strong>.</p>
                                    <p>Please log in to your dashboard and complete your renewal now to maintain continuous access.</p>
                                    <hr style='border: none; border-top: 1px solid #e1e1e1;' />
                                    <p style='font-size: 11px; color: #777;'>SchoolApp Platform Support.</p>
                                </div>
                            </body>
                            </html>
                        ";
                        MailHelper::sendHTML($admin_email, $subject, $body, $super_admin_email);

                        $stmt = $pdo->prepare("UPDATE `sm_schools` SET `subscription_expiry_warned` = 2 WHERE `id` = ?");
                        $stmt->execute([$school['id']]);
                    }
                } elseif ($daysLeft <= $warn_days_1) {
                    // Expiry within 30 days
                    if ($school['subscription_expiry_warned'] < 1) {
                        $subject = "📅 Renewal Reminder: Subscription Expiring Soon - " . $school['name'];
                        $body = "
                            <html>
                            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                                <div style='max-width: 600px; margin: 0 auto; border: 1px solid #e1e1e1; padding: 20px; border-radius: 10px;'>
                                    <h2>Subscription Renewal Notice</h2>
                                    <p>Dear Administrator,</p>
                                    <p>This is a friendly reminder that your portal subscription for <strong>{$school['name']}</strong> expires in " . round($daysLeft) . " days.</p>
                                    <hr style='border: none; border-top: 1px solid #e1e1e1;' />
                                    <p style='font-size: 11px; color: #777;'>SchoolApp Platform Support.</p>
                                </div>
                            </body>
                            </html>
                        ";
                        MailHelper::sendHTML($admin_email, $subject, $body, $super_admin_email);

                        $stmt = $pdo->prepare("UPDATE `sm_schools` SET `subscription_expiry_warned` = 1 WHERE `id` = ?");
                        $stmt->execute([$school['id']]);
                    }
                } else {
                    // Normal / Active state. Reset flags if they were set (e.g. after renewal)
                    if ($school['subscription_expiry_warned'] > 0 || $school['subscription_suspended_notified'] > 0 || $school['subscription_status'] === 'expired') {
                        $stmt = $pdo->prepare("UPDATE `sm_schools` SET `subscription_expiry_warned` = 0, `subscription_suspended_notified` = 0, `subscription_status` = 'active', `status` = 'active' WHERE `id` = ?");
                        $stmt->execute([$school['id']]);
                    }
                }
            }
        } catch (PDOException $e) {
            // Fail silently to avoid breaking page loads if database is locked/busy
        }
    }
}
?>
