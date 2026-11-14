<?php
/**
 * user/index.php — the customer dashboard: licences, pending requests, account.
 *
 * Rendered through the shared public shell so it matches the rest of the site
 * on every screen size. The queries are the same ones the previous version
 * ran; the difference is the layout, the missing-page link (api-integration.php
 * now exists) and a real empty state.
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

$licenses = array();
$pending = array();
$approved = array();

try {
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE customer_email = ? ORDER BY created_at DESC');
    $stmt->execute(array($user_email));
    $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM license_requests WHERE customer_email = ? ORDER BY created_at DESC");
    $stmt->execute(array($user_email));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['status'] ?? '') === 'pending') {
            $pending[] = $row;
        } else {
            $approved[] = $row;
        }
    }
} catch (PDOException $e) {
    $dbError = 'The database is not answering right now, so your licences could not be loaded.';
}

$activeCount = 0;
foreach ($licenses as $l) {
    if (($l['status'] ?? '') === 'active') {
        $activeCount++;
    }
}

$name = lm_public_user_name();
$renewSoon = null;
foreach ($licenses as $l) {
    if (!empty($l['expiry_date'])) {
        $ts = strtotime((string) $l['expiry_date']);
        if ($ts !== false && $ts < strtotime('+30 days') && ($renewSoon === null || $ts < $renewSoon)) {
            $renewSoon = $ts;
        }
    }
}

/**
 * Human date, or an em dash for an empty value.
 *
 * @param string $value
 * @param bool   $withTime
 * @return string
 */
function lm_fmt_date($value, $withTime = false)
{
    if ($value === null || trim((string) $value) === '' || (string) $value === '0000-00-00') {
        return '—';
    }

    $ts = strtotime((string) $value);
    if ($ts === false) {
        return (string) $value;
    }

    return date($withTime ? 'M j, Y · H:i' : 'M j, Y', $ts);
}
?>
<?php lm_public_page_start('account', array('title' => 'My licences', 'account' => true)); ?>

<main class="lm-page" id="main">
    <div class="lm-wrap">
        <div class="lm-pagehead">
            <div class="lm-pagehead__text">
                <h1>Hello<?= $name !== '' ? ', ' . lm_e($name) : '' ?> 👋</h1>
                <p>Everything on your account: licences, pending requests and the details we have on file.</p>
            </div>
            <div class="lm-pagehead__actions">
                <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('user/profile.php')) ?>">
                    <?= lm_icon('user') ?> Profile
                </a>
                <a class="lm-btn" href="<?= lm_e(lm_public_url('order.php')) ?>">
                    <?= lm_icon('plus') ?> New licence
                </a>
            </div>
        </div>

        <?php if (isset($dbError)): ?>
            <?php lm_alert('error', $dbError); ?>
        <?php endif; ?>

        <?php if ($renewSoon !== null): ?>
            <div class="lm-note lm-note--warn">
                <?= lm_icon('clock') ?>
                <span><strong>A licence expires soon.</strong> The earliest expiry on your account is
                    <strong><?= lm_e(date('M j, Y', $renewSoon)) ?></strong>. Open the licence below to see the details
                    before it lapses.</span>
            </div>
        <?php endif; ?>

        <div class="lm-stats">
            <div class="lm-stat">
                <div class="lm-stat__label">Total licences</div>
                <div class="lm-stat__value"><?= count($licenses) ?></div>
                <div class="lm-stat__sub">Issued to <?= lm_e($user_email) ?></div>
            </div>
            <div class="lm-stat lm-stat--green">
                <div class="lm-stat__label">Active</div>
                <div class="lm-stat__value"><?= $activeCount ?></div>
                <div class="lm-stat__sub">Verifying right now</div>
            </div>
            <div class="lm-stat lm-stat--amber">
                <div class="lm-stat__label">Pending requests</div>
                <div class="lm-stat__value"><?= count($pending) ?></div>
                <div class="lm-stat__sub">Waiting for approval</div>
            </div>
            <div class="lm-stat lm-stat--indigo">
                <div class="lm-stat__label">API status</div>
                <div class="lm-stat__value" style="font-size:20px">v<?= lm_e(defined('LM_VERSION') ? LM_VERSION : '') ?></div>
                <div class="lm-stat__sub"><a href="<?= lm_e(lm_public_url('api-docs.php')) ?>">Read the docs</a></div>
            </div>
        </div>

        <section class="lm-panel" id="licences">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('key') ?> Your licences</h2>
                    <p>Each key is bound to one domain. Open a licence to copy the key, call the API or move the domain.</p>
                </div>
                <?php if (!empty($licenses)): ?>
                    <span class="lm-badge lm-badge--info"><?= count($licenses) ?> total</span>
                <?php endif; ?>
            </div>

            <div class="lm-panel__body">
                <?php if (empty($licenses)): ?>
                    <div class="lm-empty">
                        <div class="lm-empty__icon"><?= lm_icon('key') ?></div>
                        <h3>No licences yet</h3>
                        <p>Request one and it will appear here. If you already requested a licence, the reference is
                            listed below — approval moves it into this table.</p>
                        <a class="lm-btn lm-btn--glow" href="<?= lm_e(lm_public_url('order.php')) ?>">
                            <?= lm_icon('plus') ?> Request a licence
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($licenses as $license): ?>
                        <?php
                        $id = (int) $license['id'];
                        $status = strtolower((string) ($license['status'] ?? ''));
                        $isActive = $status === 'active';
                        $expiry = isset($license['expiry_date']) ? (string) $license['expiry_date'] : '';
                        $expired = $expiry !== '' && $expiry !== '0000-00-00' && strtotime($expiry) !== false
                            && strtotime($expiry) < time();
                        $tier = (string) ($license['license_type'] ?? 'standard');
                        ?>
                        <article class="lm-item">
                            <div class="lm-item__top">
                                <div style="min-width:0;flex:1 1 260px">
                                    <div class="lm-item__title">Licence key</div>
                                    <div class="lm-keyrow">
                                        <code class="lm-key"><?= lm_e($license['license_key']) ?></code>
                                        <?= lm_copy_button((string) $license['license_key'], 'Copy') ?>
                                    </div>
                                </div>
                                <div class="lm-flex" style="flex-wrap:nowrap">
                                    <span class="lm-badge <?= $isActive && !$expired ? 'lm-badge--ok' : 'lm-badge--bad' ?>">
                                        <?= $expired ? 'Expired' : lm_e(ucfirst($status !== '' ? $status : 'unknown')) ?>
                                    </span>
                                    <span class="lm-badge"><?= lm_e(ucfirst($tier)) ?></span>
                                </div>
                            </div>

                            <dl class="lm-deflist">
                                <div>
                                    <dt>Domain</dt>
                                    <dd><?= lm_e($license['domain'] !== '' ? $license['domain'] : '—') ?></dd>
                                </div>
                                <div>
                                    <dt>Issued</dt>
                                    <dd><?= lm_e(lm_fmt_date($license['created_at'] ?? '')) ?></dd>
                                </div>
                                <div>
                                    <dt>Expires</dt>
                                    <dd><?= $expiry !== '' && $expiry !== '0000-00-00' ? lm_e(lm_fmt_date($expiry)) : 'Never' ?></dd>
                                </div>
                                <?php if (array_key_exists('domain_change_count', $license)): ?>
                                    <div>
                                        <dt>Domain changes</dt>
                                        <dd><?= (int) $license['domain_change_count'] ?> used</dd>
                                    </div>
                                <?php endif; ?>
                            </dl>

                            <div class="lm-item__actions">
                                <a class="lm-btn lm-btn--sm lm-btn--glow" href="license-details.php?id=<?= $id ?>">
                                    <?= lm_icon('receipt') ?> Details
                                </a>
                                <a class="lm-btn lm-btn--sm lm-btn--ghost" href="change-domain.php?id=<?= $id ?>">
                                    <?= lm_icon('globe') ?> Change domain
                                </a>
                                <a class="lm-btn lm-btn--sm lm-btn--ghost" href="api-integration.php?license_id=<?= $id ?>">
                                    <?= lm_icon('code') ?> API integration
                                </a>
                                <a class="lm-btn lm-btn--sm lm-btn--ghost" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                                    <?= lm_icon('book') ?> Docs
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="lm-panel" id="requests">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('clock') ?> Licence requests</h2>
                    <p>A request becomes a licence once an administrator approves it. The reference below is also a
                        sign-in credential.</p>
                </div>
                <?php if (!empty($pending)): ?>
                    <span class="lm-badge lm-badge--warn"><?= count($pending) ?> pending</span>
                <?php endif; ?>
            </div>

            <div class="lm-panel__body">
                <?php if (empty($pending) && empty($approved)): ?>
                    <div class="lm-empty">
                        <div class="lm-empty__icon"><?= lm_icon('clock') ?></div>
                        <h3>Nothing waiting</h3>
                        <p>You have not requested a licence with this email. When you do, the reference appears here
                            until it is approved.</p>
                        <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('order.php')) ?>">Request a licence</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending as $req): ?>
                        <article class="lm-item">
                            <div class="lm-item__top">
                                <div style="min-width:0;flex:1 1 260px">
                                    <div class="lm-item__title">Request reference</div>
                                    <div class="lm-keyrow">
                                        <code class="lm-key"><?= lm_e($req['request_ref']) ?></code>
                                        <?= lm_copy_button((string) $req['request_ref'], 'Copy') ?>
                                    </div>
                                </div>
                                <span class="lm-badge lm-badge--warn">Pending approval</span>
                            </div>

                            <dl class="lm-deflist">
                                <div>
                                    <dt>Domain</dt>
                                    <dd><?= lm_e($req['domain'] ?? '—') ?></dd>
                                </div>
                                <div>
                                    <dt>Requested</dt>
                                    <dd><?= lm_e(lm_fmt_date($req['created_at'] ?? '')) ?></dd>
                                </div>
                            </dl>
                        </article>
                    <?php endforeach; ?>

                    <?php if (!empty($approved)): ?>
                        <div class="lm-mt-24">
                            <h3 style="font-size:13px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted-2);margin-bottom:12px">
                                Earlier requests
                            </h3>
                            <div class="lm-tablewrap">
                                <table class="lm-table lm-table--stack">
                                    <thead>
                                        <tr>
                                            <th>Reference</th>
                                            <th>Domain</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($approved, 0, 10) as $req): ?>
                                            <?php $st = strtolower((string) ($req['status'] ?? '')); ?>
                                            <tr>
                                                <td data-label="Reference"><code><?= lm_e($req['request_ref']) ?></code></td>
                                                <td data-label="Domain"><?= lm_e($req['domain'] ?? '—') ?></td>
                                                <td data-label="Status">
                                                    <span class="lm-badge <?= $st === 'approved' ? 'lm-badge--ok' : 'lm-badge--bad' ?>">
                                                        <?= lm_e(ucfirst($st !== '' ? $st : 'unknown')) ?>
                                                    </span>
                                                </td>
                                                <td data-label="Submitted"><?= lm_e(lm_fmt_date($req['created_at'] ?? '')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('user') ?> Account</h2>
                    <p>The address every licence and update notice is attached to.</p>
                </div>
            </div>
            <div class="lm-panel__body">
                <dl class="lm-deflist">
                    <div>
                        <dt>Email</dt>
                        <dd><?= lm_e($user_email) ?></dd>
                    </div>
                    <div>
                        <dt>Licences</dt>
                        <dd><?= count($licenses) ?></dd>
                    </div>
                    <div>
                        <dt>Notifications</dt>
                        <dd>On · <a href="<?= lm_e(lm_public_url('unsubscribe.php')) ?>">manage preferences</a></dd>
                    </div>
                </dl>

                <div class="lm-item__actions">
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="<?= lm_e(lm_public_url('user/profile.php')) ?>">
                        <?= lm_icon('user') ?> Edit profile
                    </a>
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="<?= lm_e(lm_public_url('user/logout.php')) ?>">
                        <?= lm_icon('logout') ?> Sign out
                    </a>
                </div>
            </div>
        </section>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'account', 'no_tabbar' => true)); ?>
