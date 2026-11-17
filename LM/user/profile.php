<?php
/**
 * user/profile.php — the customer's own details.
 *
 * Same session-backed fields as before (there is no users table in the schema),
 * rebuilt on the shared shell and given a real summary of what the account
 * holds, so the page is not just a form in the middle of a screen.
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

$ok = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $company = trim((string) ($_POST['company'] ?? ''));

    if ($name === '') {
        $error = 'Your name cannot be empty.';
    } elseif (strlen($name) > 120 || strlen($company) > 120 || strlen($phone) > 40) {
        $error = 'One of those values is longer than the field allows.';
    } else {
        // The schema has no users table: the profile lives with the session.
        $_SESSION['user_name'] = $name;
        $_SESSION['user_phone'] = $phone;
        $_SESSION['user_company'] = $company;
        $ok = 'Your details were saved.';
    }
}

$user_name = (string) ($_SESSION['user_name'] ?? '');
$user_phone = (string) ($_SESSION['user_phone'] ?? '');
$user_company = (string) ($_SESSION['user_company'] ?? '');

$licenceCount = 0;
$activeCount = 0;

try {
    $stmt = $pdo->prepare('SELECT status FROM licenses WHERE customer_email = ?');
    $stmt->execute(array($user_email));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $licenceCount++;
        if (strtolower((string) $row['status']) === 'active') {
            $activeCount++;
        }
    }
} catch (PDOException $e) {
    $licenceCount = 0;
}
?>
<?php lm_public_page_start('account', array('title' => 'Profile', 'account' => true)); ?>

<main class="lm-page" id="main">
    <div class="lm-wrap">
        <a class="lm-backlink" href="index.php"><?= lm_icon('arrow-left') ?> Back to my licences</a>

        <div class="lm-pagehead">
            <div class="lm-pagehead__text">
                <h1>Your profile</h1>
                <p>The name we use when we write to you, and the email every licence is attached to.</p>
            </div>
            <div class="lm-pagehead__actions">
                <a class="lm-btn lm-btn--ghost" href="index.php"><?= lm_icon('grid') ?> Dashboard</a>
                <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('user/logout.php')) ?>">
                    <?= lm_icon('logout') ?> Sign out
                </a>
            </div>
        </div>

        <?php if ($ok !== ''): ?>
            <?php lm_alert('ok', $ok); ?>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <?php lm_alert('error', $error); ?>
        <?php endif; ?>

        <div class="lm-order-layout">
            <section class="lm-panel">
                <div class="lm-panel__head">
                    <div>
                        <h2><?= lm_icon('user') ?> Your details</h2>
                        <p>Only the name is required. The rest is used on invoices and support replies.</p>
                    </div>
                </div>
                <div class="lm-panel__body">
                    <div class="lm-note">
                        <?= lm_icon('mail') ?>
                        <span><strong><?= lm_e($user_email) ?></strong> is the address your licences are registered to.
                            To move them to another address, contact support — keys cannot be re-parented from here.</span>
                    </div>

                    <form class="lm-form" method="POST">
                        <div class="lm-field">
                            <label for="name">Full name</label>
                            <input class="lm-input" type="text" id="name" name="name" autocomplete="name"
                                   value="<?= lm_e($user_name) ?>" placeholder="Ada Lovelace">
                        </div>

                        <div class="lm-field">
                            <label for="company">Company <span class="lm-help">(optional)</span></label>
                            <input class="lm-input" type="text" id="company" name="company" autocomplete="organization"
                                   value="<?= lm_e($user_company) ?>" placeholder="Acme Studio">
                        </div>

                        <div class="lm-field">
                            <label for="phone">Phone <span class="lm-help">(optional)</span></label>
                            <input class="lm-input" type="tel" id="phone" name="phone" autocomplete="tel"
                                   value="<?= lm_e($user_phone) ?>" placeholder="+234 800 000 0000">
                        </div>

                        <div class="lm-flex" style="flex-wrap:nowrap">
                            <button type="submit" class="lm-btn lm-btn--glow lm-btn--block">
                                <?= lm_icon('check') ?> Save changes
                            </button>
                            <a class="lm-btn lm-btn--ghost lm-btn--block" href="index.php">Cancel</a>
                        </div>
                    </form>
                </div>
            </section>

            <aside class="lm-order-aside">
                <div class="lm-panel">
                    <div class="lm-panel__head">
                        <h2><?= lm_icon('chart') ?> At a glance</h2>
                    </div>
                    <div class="lm-panel__body">
                        <dl class="lm-deflist">
                            <div>
                                <dt>Licences</dt>
                                <dd><?= (int) $licenceCount ?></dd>
                            </div>
                            <div>
                                <dt>Active</dt>
                                <dd><?= (int) $activeCount ?></dd>
                            </div>
                            <div>
                                <dt>Email</dt>
                                <dd style="word-break:break-all"><?= lm_e($user_email) ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="lm-panel">
                    <div class="lm-panel__head">
                        <h2><?= lm_icon('bell') ?> Notifications</h2>
                    </div>
                    <div class="lm-panel__body">
                        <p class="lm-help">Update reminders are sent to the address above. You can stop them at any time
                            without losing your licence.</p>
                        <a class="lm-btn lm-btn--ghost lm-btn--block lm-mt-16" href="<?= lm_e(lm_public_url('unsubscribe.php')) ?>">
                            <?= lm_icon('mail') ?> Email preferences
                        </a>
                    </div>
                </div>

                <div class="lm-panel">
                    <div class="lm-panel__head">
                        <h2><?= lm_icon('shield') ?> Security</h2>
                    </div>
                    <div class="lm-panel__body">
                        <p class="lm-help">Your licence key is the credential — there is no password on this account.
                            Treat the key like a password and never publish it in a public repository.</p>
                        <a class="lm-btn lm-btn--ghost lm-btn--block lm-mt-16" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                            <?= lm_icon('book') ?> Safe integration guide
                        </a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'account', 'no_tabbar' => true)); ?>
