<?php
/**
 * user/change-domain.php — let a customer move a licence to another domain.
 *
 * The move itself is unchanged (the same UPDATE and the same log line), but the
 * page now runs on the hardened session, is capped by a rate limit, keeps the
 * domain-check regex it already had, and renders through the shared shell.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/../includes/ui.php';

$user_email = lm_public_user();

if ($user_email === '') {
    lm_safe_redirect('login.php');
}

require_once __DIR__ . '/../db.php';

$license_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_SESSION['user_license_id'] ?? 0);

if ($license_id <= 0) {
    lm_safe_redirect('index.php');
}

$message = '';
$error = '';

// Fetch license details
$stmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ? AND customer_email = ?");
$stmt->execute([$license_id, $user_email]);
$license = $stmt->fetch(PDO::FETCH_ASSOC);

if ($license) {
    $license['max_domain_changes'] = isset($license['max_domain_changes']) ? (int)$license['max_domain_changes'] : 3;
    $license['domain_change_count'] = isset($license['domain_change_count']) ? (int)$license['domain_change_count'] : 0;
} else {
    $error = 'License not found';
}

// Handle domain change request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_domain'])) {
    $new_domain = strtolower(trim((string) $_POST['new_domain']));
    $new_domain = preg_replace('#^https?://#', '', $new_domain);
    $new_domain = rtrim($new_domain, '/');
    $throttle = lm_rate_limit(lm_rate_limit_ip('domain-change'), 10, 3600, true);

    // Validation
    if (!$throttle['allowed']) {
        $error = 'Too many domain changes in a short time. Try again later.';
    } elseif (empty($new_domain)) {
        $error = 'Domain name is required';
    } elseif (strlen($new_domain) > 150
        || !preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i', $new_domain)) {
        $error = 'Please enter a valid domain name (e.g., example.com)';
    } elseif ($new_domain === $license['domain']) {
        $error = 'New domain must be different from current domain';
    } elseif ($license['domain_change_count'] >= $license['max_domain_changes']) {
        $error = 'You have reached the maximum number of domain changes (' . $license['max_domain_changes'] . ')';
    } else {
        // Update domain
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE licenses SET domain = ?, domain_change_count = domain_change_count + 1 WHERE id = ?");
            $stmt->execute([$new_domain, $license_id]);
            
            // Log the change (unchanged behaviour, one line per move).
            $change_log = date('Y-m-d H:i:s') . " - Licence {$license_id} ({$user_email}) domain changed from "
                . "'{$license['domain']}' to '{$new_domain}'\n";
            @file_put_contents(__DIR__ . '/../domain-change.log', $change_log, FILE_APPEND);
            
            $pdo->commit();
            
            $message = 'Domain updated successfully to ' . $new_domain . '.';
            
            // Refresh license data
            $stmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ?");
            $stmt->execute([$license_id]);
            $license = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'The domain could not be updated. Please try again, or contact support if it keeps happening.';
        }
    }
}

$changes_remaining = $license ? ($license['max_domain_changes'] - $license['domain_change_count']) : 0;
?>
<?php lm_public_page_start('account', array('title' => 'Change domain', 'account' => true)); ?>

<main class="lm-page" id="main">
    <div class="lm-wrap">
        <a class="lm-backlink" href="index.php"><?= lm_icon('arrow-left') ?> Back to my licences</a>

        <div class="lm-pagehead">
            <div class="lm-pagehead__text">
                <h1>Change domain</h1>
                <p>Move this licence to the domain it will run on. The change applies immediately.</p>
            </div>
            <div class="lm-pagehead__actions">
                <a class="lm-btn lm-btn--ghost" href="license-details.php?id=<?= $license_id ?>">
                    <?= lm_icon('receipt') ?> Licence details
                </a>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <?php lm_alert('ok', $message); ?>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <?php lm_alert('error', $error); ?>
        <?php endif; ?>

        <?php if (!$license): ?>
            <div class="lm-panel">
                <div class="lm-panel__body">
                    <div class="lm-empty">
                        <div class="lm-empty__icon"><?= lm_icon('alert') ?></div>
                        <h3>Licence not found</h3>
                        <p>That licence is not on this account. Pick one from the dashboard instead.</p>
                        <a class="lm-btn" href="index.php"><?= lm_icon('grid') ?> My licences</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="lm-order-layout">
                <section class="lm-panel">
                    <div class="lm-panel__head">
                        <div>
                            <h2><?= lm_icon('globe') ?> New domain</h2>
                            <p>Enter the domain without <code>http://</code>, <code>https://</code> or <code>www</code>.</p>
                        </div>
                        <span class="lm-badge <?= $changes_remaining > 0 ? 'lm-badge--info' : 'lm-badge--bad' ?>">
                            <?= (int) $changes_remaining ?> change<?= (int) $changes_remaining === 1 ? '' : 's' ?> left
                        </span>
                    </div>

                    <div class="lm-panel__body">
                        <div class="lm-note">
                            <?= lm_icon('shield') ?>
                            <span><strong>What happens on a change.</strong> The old domain stops validating at once and
                                the new one starts. Update the <code>domain</code> value in your script if it is
                                hard-coded.</span>
                        </div>

                        <?php if ($changes_remaining > 0): ?>
                            <form class="lm-form" method="POST">
                                <div class="lm-field">
                                    <label for="new_domain">Domain name</label>
                                    <input class="lm-input" type="text" id="new_domain" name="new_domain"
                                           placeholder="example.com" autocapitalize="none" spellcheck="false"
                                           value="<?= lm_e($license['domain']) ?>" required>
                                    <p class="lm-help">Must be different from the current domain
                                        (<code><?= lm_e($license['domain']) ?></code>) and must already resolve.</p>
                                </div>

                                <button type="submit" class="lm-btn lm-btn--glow">
                                    <?= lm_icon('refresh') ?> Update domain
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="lm-note lm-note--warn">
                                <?= lm_icon('lock') ?>
                                <span><strong>All <?= (int) $license['max_domain_changes'] ?> changes are used.</strong>
                                    This licence can no longer move itself. Contact support if the site has genuinely
                                    changed address.</span>
                            </div>
                            <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('order.php')) ?>">
                                <?= lm_icon('plus') ?> Request a new licence
                            </a>
                        <?php endif; ?>
                    </div>
                </section>

                <aside class="lm-order-aside">
                    <div class="lm-panel">
                        <div class="lm-panel__head">
                            <h2><?= lm_icon('chart') ?> Change allowance</h2>
                        </div>
                        <div class="lm-panel__body">
                            <dl class="lm-deflist">
                                <div>
                                    <dt>Current domain</dt>
                                    <dd><?= lm_e($license['domain']) ?></dd>
                                </div>
                                <div>
                                    <dt>Allowed</dt>
                                    <dd><?= (int) $license['max_domain_changes'] ?></dd>
                                </div>
                                <div>
                                    <dt>Used</dt>
                                    <dd><?= (int) $license['domain_change_count'] ?> / <?= (int) $license['max_domain_changes'] ?></dd>
                                </div>
                                <div>
                                    <dt>Remaining</dt>
                                    <dd><?= (int) $changes_remaining ?></dd>
                                </div>
                            </dl>

                            <div class="lm-progress" aria-hidden="true">
                                <span style="width:<?= (int) $license['max_domain_changes'] > 0
                                    ? (int) round(min(100, ((int) $license['domain_change_count'] / (int) $license['max_domain_changes']) * 100))
                                    : 0 ?>%"></span>
                            </div>
                            <p class="lm-help lm-mb-0">
                                Changes are counted per licence, not per month. Moving back to a previous domain
                                still uses one.
                            </p>
                        </div>
                    </div>

                    <div class="lm-panel">
                        <div class="lm-panel__head">
                            <h2><?= lm_icon('key') ?> This licence</h2>
                        </div>
                        <div class="lm-panel__body">
                            <div class="lm-field" style="margin-bottom:14px">
                                <span class="lm-label">Key</span>
                                <div class="lm-keyrow">
                                    <code class="lm-key"><?= lm_e(substr((string) $license['license_key'], 0, 18)) ?>…</code>
                                    <?= lm_copy_button((string) $license['license_key'], 'Copy') ?>
                                </div>
                            </div>
                            <dl class="lm-deflist">
                                <div>
                                    <dt>Email</dt>
                                    <dd><?= lm_e((string) ($license['customer_email'] ?? '')) ?></dd>
                                </div>
                                <div>
                                    <dt>Status</dt>
                                    <dd><?= lm_e(ucfirst((string) ($license['status'] ?? 'unknown'))) ?></dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'account', 'no_tabbar' => true)); ?>
