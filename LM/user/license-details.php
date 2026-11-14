<?php
/**
 * user/license-details.php — one licence, in full.
 *
 * Same lookup as before (id + owning email), rebuilt on the shared shell. The
 * action links now all point at pages that exist, including the newly created
 * api-integration.php.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/../includes/ui.php';

if (lm_public_user() === '') {
    lm_safe_redirect('login.php');
}

require_once __DIR__ . '/../db.php';

$user_email = lm_public_user();
$license_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($license_id <= 0) {
    lm_safe_redirect('index.php');
}

$stmt = $pdo->prepare('SELECT * FROM licenses WHERE id = ? AND customer_email = ?');
$stmt->execute(array($license_id, $user_email));
$license = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$license) {
    lm_safe_redirect('index.php');
}

$api_url = function_exists('lm_site_url') ? lm_site_url('api.php') : lm_public_url('api.php');
if (!preg_match('#^https?://#i', $api_url)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $api_url = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . '/' . ltrim($api_url, '/');
}

$status = strtolower((string) ($license['status'] ?? ''));
$expiry = (string) ($license['expiry_date'] ?? '');
$expired = $expiry !== '' && $expiry !== '0000-00-00' && strtotime($expiry) !== false && strtotime($expiry) < time();
$maxChanges = (int) ($license['max_domain_changes'] ?? 3);
$usedChanges = (int) ($license['domain_change_count'] ?? 0);
$remaining = max(0, $maxChanges - $usedChanges);

/**
 * Human date, or an em dash when the column is empty.
 *
 * @param string $value
 * @return string
 */
function lm_licence_date($value)
{
    if ($value === null || trim((string) $value) === '' || (string) $value === '0000-00-00') {
        return '—';
    }

    $ts = strtotime((string) $value);
    return $ts === false ? (string) $value : date('F j, Y', $ts);
}

$example = "fetch('" . $api_url . "', {\n"
    . "  method: 'POST',\n"
    . "  body: new URLSearchParams({\n"
    . "    key: '" . substr((string) $license['license_key'], 0, 20) . "…',\n"
    . "    domain: '" . (string) ($license['domain'] ?? '') . "'\n"
    . "  })\n"
    . "})\n"
    . ".then(r => r.json())\n"
    . ".then(data => console.log(data));";
?>
<?php lm_public_page_start('account', array('title' => 'Licence details', 'account' => true)); ?>

<main class="lm-page" id="main">
    <div class="lm-wrap">
        <a class="lm-backlink" href="index.php"><?= lm_icon('arrow-left') ?> Back to my licences</a>

        <div class="lm-pagehead">
            <div class="lm-pagehead__text">
                <h1>Licence details</h1>
                <p>The key, the domain it is locked to, and the dates on the record.</p>
            </div>
            <div class="lm-pagehead__actions">
                <span class="lm-badge <?= $expired ? 'lm-badge--bad' : ($status === 'active' ? 'lm-badge--ok' : 'lm-badge--warn') ?>">
                    <?= $expired ? 'Expired' : lm_e(ucfirst($status !== '' ? $status : 'unknown')) ?>
                </span>
                <span class="lm-badge lm-badge--info"><?= lm_e(ucfirst((string) ($license['license_type'] ?? 'standard'))) ?></span>
            </div>
        </div>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('key') ?> Licence information</h2>
                    <p>Issued to <?= lm_e((string) ($license['customer_email'] ?? $user_email)) ?></p>
                </div>
            </div>
            <div class="lm-panel__body">
                <div class="lm-field" style="margin-bottom:18px">
                    <span class="lm-label">Licence key</span>
                    <div class="lm-keyrow">
                        <code class="lm-key"><?= lm_e((string) $license['license_key']) ?></code>
                        <?= lm_copy_button((string) $license['license_key'], 'Copy') ?>
                    </div>
                </div>

                <dl class="lm-deflist">
                    <div>
                        <dt>Domain</dt>
                        <dd><?= lm_e((string) ($license['domain'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd><?= lm_e(ucfirst($status !== '' ? $status : 'unknown')) ?></dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd><?= lm_e((string) ($license['customer_email'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>Created</dt>
                        <dd><?= lm_e(lm_licence_date($license['created_at'] ?? '')) ?></dd>
                    </div>
                    <div>
                        <dt>Expiry</dt>
                        <dd><?= $expiry !== '' && $expiry !== '0000-00-00' ? lm_e(lm_licence_date($expiry)) : 'Never' ?></dd>
                    </div>
                    <div>
                        <dt>Domain changes</dt>
                        <dd><?= $usedChanges ?> of <?= $maxChanges ?> used</dd>
                    </div>
                </dl>

                <div class="lm-progress" aria-hidden="true">
                    <span style="width:<?= $maxChanges > 0 ? (int) round(min(100, ($usedChanges / $maxChanges) * 100)) : 0 ?>%"></span>
                </div>
                <p class="lm-help">
                    <?= $remaining > 0
                        ? $remaining . ' domain change' . ($remaining === 1 ? '' : 's') . ' remaining.'
                        : 'All domain changes used. Contact support if you need another.' ?>
                </p>
            </div>
        </section>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('plug') ?> API integration</h2>
                    <p>Endpoint and a working example for this licence.</p>
                </div>
                <a class="lm-btn lm-btn--sm lm-btn--ghost" href="api-integration.php?license_id=<?= (int) $license['id'] ?>">
                    <?= lm_icon('code') ?> Full integration kit
                </a>
            </div>
            <div class="lm-panel__body">
                <div class="lm-field" style="margin-bottom:18px">
                    <span class="lm-label">API endpoint</span>
                    <div class="lm-keyrow">
                        <code class="lm-key"><?= lm_e($api_url) ?></code>
                        <?= lm_copy_button($api_url, 'Copy') ?>
                    </div>
                </div>

                <div class="lm-codeblock">
                    <div class="lm-codeblock__bar">
                        <span>Example request</span>
                        <?= lm_copy_button($example, 'Copy', false) ?>
                    </div>
                    <pre><code><?= lm_e($example) ?></code></pre>
                </div>

                <div class="lm-item__actions">
                    <a class="lm-btn lm-btn--sm lm-btn--glow" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                        <?= lm_icon('book') ?> API documentation
                    </a>
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="api-integration.php?license_id=<?= (int) $license['id'] ?>">
                        <?= lm_icon('terminal') ?> PHP · JS · cURL · Python
                    </a>
                </div>
            </div>
        </section>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('settings') ?> Manage this licence</h2>
                    <p>Self-service actions, available immediately.</p>
                </div>
            </div>
            <div class="lm-panel__body">
                <div class="lm-item__actions" style="margin-top:0">
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="change-domain.php?id=<?= (int) $license['id'] ?>">
                        <?= lm_icon('globe') ?> Change domain (<?= $remaining ?> left)
                    </a>
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="<?= lm_e(lm_public_url('unsubscribe.php')) ?>">
                        <?= lm_icon('mail') ?> Email preferences
                    </a>
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="index.php">
                        <?= lm_icon('grid') ?> Dashboard
                    </a>
                </div>
            </div>
        </section>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'account', 'no_tabbar' => true)); ?>
