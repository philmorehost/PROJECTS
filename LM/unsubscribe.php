<?php
/**
 * unsubscribe.php — opt out of (or back in to) update notification emails.
 *
 * 8.9.0: opening the link no longer changes anything.
 *
 * Mail scanners (Outlook Safe Links, corporate link rewriters, antivirus
 * extensions) fetch every URL in a message before the customer sees it. When
 * the GET itself unsubscribed people, those scanners silently opted real
 * customers out of security-relevant update notices. The link lands on a
 * confirmation page; only the POST from the person reading the mail acts.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';
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
        $stmt = $pdo->prepare('SELECT l.*, t.tier_name, t.tier_code FROM licenses l LEFT JOIN script_tiers t ON l.tier_id = t.id WHERE l.id = ? LIMIT 1');
        $stmt->execute(array($licenseId));
        $license = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($license && strtolower(trim((string) $license['customer_email'])) === strtolower($email)) {
            $project = (string) ($license['tier_name'] ?? $license['tier_code'] ?? '');

            if ($action === 'resubscribe') {
                $pdo->prepare('UPDATE licenses SET notify_updates = 1 WHERE id = ?')->execute(array($licenseId));
                $state = 'resubscribed';
                $message = 'You will receive update notifications again for ' . ($project !== '' ? $project : 'your licence') . '.';
            } else {
                $pdo->prepare('UPDATE licenses SET notify_updates = 0 WHERE id = ?')->execute(array($licenseId));
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

$titles = array(
    'invalid'      => 'Link not recognised',
    'confirm'      => 'Confirm your choice',
    'unsubscribed' => 'You are unsubscribed',
    'resubscribed' => 'You are subscribed again',
);

$icons = array(
    'invalid'      => 'alert',
    'confirm'      => 'question',
    'unsubscribed' => 'check',
    'resubscribed' => 'refresh',
);

$badge = array(
    'invalid'      => 'lm-badge--bad',
    'confirm'      => 'lm-badge--info',
    'unsubscribed' => 'lm-badge--warn',
    'resubscribed' => 'lm-badge--ok',
);

$title = isset($titles[$state]) ? $titles[$state] : 'Email preferences';
$icon = isset($icons[$state]) ? $icons[$state] : 'mail';
$dashboard = lm_site_url('user/login.php');
if (!preg_match('#^https?://#i', $dashboard)) {
    $dashboard = lm_public_url('user/login.php');
}
?>
<?php lm_public_page_start('account', array('title' => 'Email preferences')); ?>

<main class="lm-page lm-page--center" id="main">
    <div class="lm-wrap">
        <div class="lm-auth">
            <div class="lm-card">
                <div class="lm-card__body">
                    <div class="lm-center">
                        <span class="lm-icon-badge<?= $state === 'unsubscribed' || $state === 'invalid' ? ' lm-icon-badge--warn' : '' ?>">
                            <?= lm_icon($icon) ?>
                        </span>
                        <span class="lm-badge <?= isset($badge[$state]) ? $badge[$state] : '' ?>" style="margin-bottom:12px">
                            Email preferences
                        </span>
                        <h1 style="font-size:clamp(22px,3vw,30px);margin-bottom:10px"><?= lm_e($title) ?></h1>
                        <p class="lm-meta"><?= lm_e($message) ?></p>
                    </div>

                    <?php if ($state !== 'invalid' && $email !== ''): ?>
                        <dl class="lm-deflist lm-mt-24">
                            <div>
                                <dt>Email</dt>
                                <dd style="word-break:break-all"><?= lm_e($email) ?></dd>
                            </div>
                            <?php if ($project !== ''): ?>
                                <div>
                                    <dt>Product</dt>
                                    <dd><?= lm_e($project) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    <?php endif; ?>

                    <?php if ($state === 'confirm'): ?>
                        <form method="POST" class="lm-form lm-mt-24">
                            <input type="hidden" name="t" value="<?= lm_e($token) ?>">
                            <input type="hidden" name="action" value="<?= $action === 'resubscribe' ? 'resubscribe' : 'unsubscribe' ?>">
                            <button type="submit" class="lm-btn lm-btn--glow lm-btn--block">
                                <?= $action === 'resubscribe' ? lm_icon('bell') . ' Turn reminders back on' : lm_icon('mail') . ' Stop update reminders' ?>
                            </button>
                        </form>
                        <a class="lm-btn lm-btn--ghost lm-btn--block lm-mt-16" href="<?= lm_e($dashboard) ?>">
                            <?= lm_icon('grid') ?> Keep them and open the dashboard
                        </a>
                    <?php elseif ($state === 'unsubscribed'): ?>
                        <form method="POST" class="lm-form lm-mt-24">
                            <input type="hidden" name="t" value="<?= lm_e($token) ?>">
                            <input type="hidden" name="action" value="resubscribe">
                            <button type="submit" class="lm-btn lm-btn--ghost lm-btn--block">
                                <?= lm_icon('bell') ?> Turn reminders back on
                            </button>
                        </form>
                        <a class="lm-btn lm-btn--glow lm-btn--block lm-mt-16" href="<?= lm_e($dashboard) ?>">
                            <?= lm_icon('grid') ?> Open the dashboard
                        </a>
                    <?php elseif ($state === 'resubscribed'): ?>
                        <form method="POST" class="lm-form lm-mt-24">
                            <input type="hidden" name="t" value="<?= lm_e($token) ?>">
                            <input type="hidden" name="action" value="unsubscribe">
                            <button type="submit" class="lm-btn lm-btn--ghost lm-btn--block">
                                <?= lm_icon('mail') ?> Pause reminders again
                            </button>
                        </form>
                        <a class="lm-btn lm-btn--glow lm-btn--block lm-mt-16" href="<?= lm_e($dashboard) ?>">
                            <?= lm_icon('grid') ?> Open the dashboard
                        </a>
                    <?php else: ?>
                        <div class="lm-note lm-note--warn lm-mt-24">
                            <?= lm_icon('question') ?>
                            <span>Links expire, and a link forwarded to someone else stops working. Sign in to the
                                dashboard to manage notifications for the whole account.</span>
                        </div>
                        <a class="lm-btn lm-btn--block" href="<?= lm_e($dashboard) ?>">
                            <?= lm_icon('lock') ?> Sign in to manage notifications
                        </a>
                    <?php endif; ?>

                    <hr class="lm-divider">
                    <p class="lm-help lm-center lm-mb-0">
                        You can manage this at any time from the footer of any update email.
                    </p>
                </div>
            </div>
        </div>
    </div>
</main>

<?php lm_public_page_end(array('no_tabbar' => true)); ?>
