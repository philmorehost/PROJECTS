<?php
/**
 * unsubscribe.php — opt out of (or back in to) update notification emails.
 *
 * 8.9.0: opening the link no longer changes anything.
 *
 * Mail scanners (Outlook Safe Links, corporate link rewriters, antivirus
 * extensions) fetch every URL in a message before the customer sees it. When
 * the GET itself unsubscribed people, those scanners silently opted real
 * customers out of security-relevant update notices. The link now lands on a
 * confirmation page; only the POST from the person reading the mail acts.
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();

require_once __DIR__ . '/db.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

$requested = strtolower((string) ($_POST['action'] ?? $_GET['action'] ?? 'unsubscribe'));
$action = $requested === 'resubscribe' ? 'resubscribe' : 'unsubscribe';

$parsed = lm_parse_unsubscribe_token($token);

$state = 'invalid';
$message = 'This unsubscribe link is invalid or has expired.';
$email = '';
$project = '';

// A link scanner that hits this page dozens of times should be turned away.
$throttle = lm_rate_limit(lm_rate_limit_ip('unsubscribe'), 30, 600, true);

if ($parsed && !$throttle['allowed']) {
    http_response_code(429);
    $message = 'Too many requests from this browser. Please try again later.';
    $parsed = false;
}

if ($parsed && !$isPost) {
    /*
     * Confirmation step. Nothing is written yet: the customer sees what the
     * link will do and presses the button.
     */
    try {
        $stmt = $pdo->prepare('SELECT l.*, t.tier_name, t.tier_code FROM licenses l LEFT JOIN script_tiers t ON l.tier_id = t.id WHERE l.id = ? LIMIT 1');
        $stmt->execute(array((int) $parsed['license_id']));
        $preview = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($preview && strtolower(trim((string) $preview['customer_email'])) === strtolower($parsed['email'])) {
            $email = $parsed['email'];
            $project = (string) ($preview['tier_name'] ?? $preview['tier_code'] ?? '');
            $state = 'confirm';
            $message = $action === 'resubscribe'
                ? 'Confirm that you want to receive update reminders again for ' . ($project !== '' ? $project : 'this licence') . '.'
                : 'Confirm that you want to stop receiving update reminders for ' . ($project !== '' ? $project : 'this licence') . '.';
        } else {
            $message = 'We could not match that link to a licence. Please contact support.';
        }
    } catch (PDOException $e) {
        $message = 'We could not process your request right now. Please try again later.';
    }
}

if ($parsed && $isPost) {
    $email = $parsed['email'];
    $licenseId = (int) $parsed['license_id'];

    try {
        $stmt = $pdo->prepare("SELECT l.*, t.tier_name, t.tier_code FROM licenses l LEFT JOIN script_tiers t ON l.tier_id = t.id WHERE l.id = ? LIMIT 1");
        $stmt->execute(array($licenseId));
        $license = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($license && strtolower(trim((string) $license['customer_email'])) === strtolower($email)) {
            $project = (string) ($license['tier_name'] ?? $license['tier_code'] ?? '');

            if ($action === 'resubscribe') {
                $pdo->prepare("UPDATE licenses SET notify_updates = 1 WHERE id = ?")->execute(array($licenseId));
                $state = 'resubscribed';
                $message = 'You will receive update notifications again for ' . ($project !== '' ? $project : 'your licence') . '.';
            } else {
                $pdo->prepare("UPDATE licenses SET notify_updates = 0 WHERE id = ?")->execute(array($licenseId));
                $pdo->prepare("UPDATE update_notifications SET status = 'cancelled', last_error = 'Customer unsubscribed' WHERE license_id = ? AND status = 'pending'")->execute(array($licenseId));
                $state = 'unsubscribed';
                $message = 'You will no longer receive update reminder emails for ' . ($project !== '' ? $project : 'this licence') . '. Security and transactional emails are unaffected.';
            }
        } else {
            $message = 'We could not match that link to a licence. Please contact support.';
        }
    } catch (PDOException $e) {
        $message = 'We could not process your request right now. Please try again later.';
    }
}

$site = lm_site_name();
$accent = $state === 'unsubscribed' ? '#f59e0b' : ($state === 'confirm' ? '#0e7490' : '#4f46e5');
$icon = $state === 'invalid' ? '!' : ($state === 'unsubscribed' ? '✓' : ($state === 'confirm' ? '?' : '↺'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site) ?> — Email preferences</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: radial-gradient(circle at 20% 10%, #e0e7ff 0%, #f8fafc 45%, #eef2ff 100%); color: #0f172a; }
        .card { width: 100%; max-width: 520px; background: #ffffff; border-radius: 24px; box-shadow: 0 30px 80px rgba(15, 23, 42, 0.12); overflow: hidden; border: 1px solid rgba(148, 163, 184, 0.18); }
        .head { background: linear-gradient(135deg, #312e81, #4f46e5 55%, #06b6d4); padding: 30px 34px; color: #fff; }
        .brand { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; opacity: 0.8; font-weight: 700; }
        .body { padding: 32px 34px 36px; }
        .icon { width: 52px; height: 52px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; color: #fff; background: <?= $accent ?>; margin-bottom: 20px; }
        h1 { font-size: 22px; line-height: 1.3; letter-spacing: -0.3px; margin-bottom: 10px; }
        p { font-size: 15px; line-height: 1.7; color: #475569; }
        .meta { margin-top: 22px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 13px; color: #64748b; }
        .meta strong { color: #334155; }
        .actions { margin-top: 26px; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { display: inline-block; padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-ghost { background: #eef2ff; color: #4338ca; }
        .foot { margin-top: 26px; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="head">
            <div class="brand"><?= htmlspecialchars($site) ?></div>
            <h2 style="font-size:20px;font-weight:800;margin-top:10px;letter-spacing:-0.3px;">Email preferences</h2>
        </div>
        <div class="body">
            <div class="icon"><?= $icon ?></div>
            <h1><?= $state === 'invalid' ? 'Link not recognised' : ($state === 'unsubscribed' ? 'You are unsubscribed' : ($state === 'confirm' ? 'Confirm your choice' : 'You are subscribed again')) ?></h1>
            <p><?= htmlspecialchars($message) ?></p>

            <?php if ($state !== 'invalid' && $email !== ''): ?>
                <div class="meta">
                    <div><strong>Email:</strong> <?= htmlspecialchars($email) ?></div>
                    <?php if ($project !== ''): ?>
                        <div style="margin-top:4px;"><strong>Product:</strong> <?= htmlspecialchars($project) ?></div>
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <?php if ($state === 'confirm'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="<?= $action === 'resubscribe' ? 'resubscribe' : 'unsubscribe' ?>">
                            <button type="submit" class="btn btn-primary">
                                <?= $action === 'resubscribe' ? 'Turn reminders back on' : 'Stop update reminders' ?>
                            </button>
                        </form>
                        <a class="btn btn-ghost" href="<?= htmlspecialchars(lm_site_url('user/login.php')) ?>">Keep them and open dashboard</a>
                    <?php elseif ($state === 'unsubscribed'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="resubscribe">
                            <button type="submit" class="btn btn-ghost">Turn reminders back on</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="unsubscribe">
                            <button type="submit" class="btn btn-ghost">Pause reminders</button>
                        </form>
                    <?php endif; ?>
                    <a class="btn btn-primary" href="<?= htmlspecialchars(lm_site_url('user/login.php')) ?>">Open dashboard</a>
                </div>
            <?php endif; ?>

            <div class="foot">You can manage this anytime from the footer of any update email.</div>
        </div>
    </div>
</body>
</html>
