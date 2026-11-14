<?php
/**
 * admin/licenses.php — every licence, the add/edit forms and the request queue.
 *
 * Behaviour preserved from the previous screen: add, edit and toggle status all
 * post back here, and approval hands off to approve_request.php. What changed:
 * the page runs on the shared shell, writes go through the CSRF check, the
 * tables restack into cards on a phone, and there is a filter box so a thousand
 * keys are still searchable.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$settings = function_exists('lm_settings') ? lm_settings() : array();
$maxDomainChanges = (int) ($settings['max_domain_changes'] ?? 3);
$notice = '';
$problem = '';

/* ------------------------------------------------------------------ writes */

if (lm_app_handle_post() && $pdo instanceof PDO) {
    $action = (string) ($_POST['lm_action'] ?? '');

    try {
        if ($action === 'add') {
            $key = trim((string) ($_POST['license_key'] ?? ''));
            if ($key === '') {
                $key = 'LM-' . strtoupper(bin2hex(random_bytes(8)));
            }

            $definition = function_exists('getLicenseInsertDefinition') ? getLicenseInsertDefinition($pdo) : null;

            if (is_array($definition) && isset($definition['sql'], $definition['params'])) {
                $params = call_user_func(
                    $definition['params'],
                    $key,
                    trim((string) ($_POST['domain'] ?? '')),
                    trim((string) ($_POST['customer_email'] ?? '')),
                    (string) ($_POST['status'] ?? 'active'),
                    $maxDomainChanges
                );
                $pdo->prepare($definition['sql'])->execute($params);
            } else {
                $stmt = $pdo->prepare('INSERT INTO licenses (license_key, domain, customer_email, status, license_type) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute(array(
                    $key,
                    trim((string) ($_POST['domain'] ?? '')),
                    trim((string) ($_POST['customer_email'] ?? '')),
                    (string) ($_POST['status'] ?? 'active'),
                    (string) ($_POST['license_type'] ?? 'standard'),
                ));
            }

            $notice = 'Licence ' . $key . ' created.';
        } elseif ($action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE licenses SET license_key = ?, domain = ?, customer_email = ?, status = ?, license_type = ? WHERE id = ?');
            $stmt->execute(array(
                trim((string) ($_POST['license_key'] ?? '')),
                trim((string) ($_POST['domain'] ?? '')),
                trim((string) ($_POST['customer_email'] ?? '')),
                (string) ($_POST['status'] ?? 'active'),
                (string) ($_POST['license_type'] ?? 'standard'),
                $id,
            ));
            $notice = 'Licence #' . $id . ' updated.';
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $status = strtolower((string) ($_POST['current_status'] ?? '')) === 'active' ? 'inactive' : 'active';
            $stmt = $pdo->prepare('UPDATE licenses SET status = ? WHERE id = ?');
            $stmt->execute(array($status, $id));
            $notice = 'Licence #' . $id . ' is now ' . $status . '.';
        }
    } catch (PDOException $e) {
        $problem = 'That change could not be saved: ' . $e->getMessage();
    }
}

/* ------------------------------------------------------------------- reads */

$licenses = array();
$requests = array();
$requestCounts = array('pending' => 0, 'approved' => 0, 'declined' => 0);

if ($pdo instanceof PDO) {
    try {
        $licenses = $pdo->query('SELECT * FROM licenses ORDER BY created_at DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = $problem !== '' ? $problem : 'The licences table could not be read. Run Database Repair.';
    }

    try {
        $requests = $pdo->query('SELECT * FROM license_requests ORDER BY created_at DESC, id DESC LIMIT 200')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($requests as $row) {
            $status = strtolower((string) ($row['status'] ?? 'pending'));
            if (isset($requestCounts[$status])) {
                $requestCounts[$status]++;
            }
        }
    } catch (PDOException $e) {
        $requests = array();
    }
}

$edit = null;
if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit' && $pdo instanceof PDO) {
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE id = ?');
    $stmt->execute(array((int) $_GET['id']));
    $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$adding = isset($_GET['action']) && $_GET['action'] === 'add';
$activeCount = 0;
foreach ($licenses as $row) {
    if (strtolower((string) ($row['status'] ?? '')) === 'active') {
        $activeCount++;
    }
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="licenses.php">' . lm_icon('refresh') . ' Refresh</a>'
    . '<a class="lm-btn lm-btn--glow" href="licenses.php?action=add">' . lm_icon('plus') . ' Add licence</a>';

lm_app_start('Licences', array(
    'subtitle' => 'Issue keys, edit domains, suspend a licence and approve what customers asked for.',
    'active'   => 'licenses',
    'actions'  => $actionsHtml,
));
?>

<?php if ($notice !== ''): ?>
    <?php lm_alert('ok', $notice); ?>
<?php endif; ?>
<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('Total', (string) count($licenses), 'All keys ever issued');
    lm_app_stat('Active', (string) $activeCount, 'Verifying right now', 'green');
    lm_app_stat('Inactive', (string) (count($licenses) - $activeCount), 'Suspended or expired', 'amber');
    lm_app_stat('Pending requests', (string) $requestCounts['pending'], 'Need a decision', 'indigo');
    ?>
</div>

<?php if ($adding): ?>
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('plus') ?> Add a licence</h2>
                <p>Leave the key blank and one is generated in the <code>LM-</code> format.</p>
            </div>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php">Cancel</a>
        </div>
        <div class="lm-panel__body">
            <form method="POST" class="lm-form">
                <?= lm_csrf_field() ?>
                <input type="hidden" name="lm_action" value="add">
                <div class="lm-formgrid">
                    <div class="lm-field">
                        <label for="add_key">Licence key</label>
                        <input class="lm-input lm-mono" type="text" id="add_key" name="license_key" placeholder="Leave blank to generate">
                    </div>
                    <div class="lm-field">
                        <label for="add_domain">Domain</label>
                        <input class="lm-input" type="text" id="add_domain" name="domain" placeholder="example.com" required>
                    </div>
                    <div class="lm-field">
                        <label for="add_email">Customer email</label>
                        <input class="lm-input" type="email" id="add_email" name="customer_email" placeholder="buyer@example.com" required>
                    </div>
                    <div class="lm-field">
                        <label for="add_type">Type</label>
                        <select class="lm-select" id="add_type" name="license_type">
                            <option value="standard">Standard</option>
                            <option value="extended">Extended</option>
                        </select>
                    </div>
                    <div class="lm-field">
                        <label for="add_status">Status</label>
                        <select class="lm-select" id="add_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="lm-btnrow">
                    <button type="submit" class="lm-btn lm-btn--glow"><?= lm_icon('check') ?> Save licence</button>
                    <a class="lm-btn lm-btn--ghost" href="licenses.php">Cancel</a>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if ($edit): ?>
    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('settings') ?> Edit licence #<?= (int) $edit['id'] ?></h2>
                <p>Changing the domain here takes effect on the next API call — no customer action needed.</p>
            </div>
            <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php">Cancel</a>
        </div>
        <div class="lm-panel__body">
            <form method="POST" class="lm-form">
                <?= lm_csrf_field() ?>
                <input type="hidden" name="lm_action" value="edit">
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <div class="lm-formgrid">
                    <div class="lm-field">
                        <label for="edit_key">Licence key</label>
                        <input class="lm-input lm-mono" type="text" id="edit_key" name="license_key"
                               value="<?= lm_e((string) $edit['license_key']) ?>" required>
                    </div>
                    <div class="lm-field">
                        <label for="edit_domain">Domain</label>
                        <input class="lm-input" type="text" id="edit_domain" name="domain"
                               value="<?= lm_e((string) ($edit['domain'] ?? '')) ?>" required>
                    </div>
                    <div class="lm-field">
                        <label for="edit_email">Customer email</label>
                        <input class="lm-input" type="email" id="edit_email" name="customer_email"
                               value="<?= lm_e((string) ($edit['customer_email'] ?? '')) ?>" required>
                    </div>
                    <div class="lm-field">
                        <label for="edit_type">Type</label>
                        <select class="lm-select" id="edit_type" name="license_type">
                            <option value="standard" <?= ($edit['license_type'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>Standard</option>
                            <option value="extended" <?= ($edit['license_type'] ?? '') === 'extended' ? 'selected' : '' ?>>Extended</option>
                        </select>
                    </div>
                    <div class="lm-field">
                        <label for="edit_status">Status</label>
                        <select class="lm-select" id="edit_status" name="status">
                            <option value="active" <?= ($edit['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="lm-btnrow">
                    <button type="submit" class="lm-btn lm-btn--glow"><?= lm_icon('check') ?> Save changes</button>
                    <a class="lm-btn lm-btn--ghost" href="licenses.php">Cancel</a>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('key') ?> All licences</h2>
            <p>Search by key, domain or email. Toggling takes effect immediately for API calls.</p>
        </div>
        <div class="lm-field" style="margin:0;min-width:240px">
            <label class="lm-sr" for="licenceFilter">Filter licences</label>
            <input class="lm-input" type="search" id="licenceFilter" placeholder="Filter key, domain or email…" data-lm-filter="#licenceTable">
        </div>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($licenses)): ?>
            <?php lm_app_empty(
                'key',
                'No licences yet',
                'Add one by hand, or approve a request from a customer and it appears here.',
                '<a class="lm-btn lm-btn--glow" href="licenses.php?action=add">' . lm_icon('plus') . ' Add the first licence</a>'
            ); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack" id="licenceTable">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Domain</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Issued</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($licenses as $license): ?>
                            <?php
                            $status = strtolower((string) ($license['status'] ?? ''));
                            $isActive = $status === 'active';
                            $type = (string) ($license['license_type'] ?? 'standard');
                            ?>
                            <tr data-filter-text="<?= lm_e(strtolower(
                                (string) $license['license_key'] . ' ' . (string) ($license['domain'] ?? '') . ' ' . (string) ($license['customer_email'] ?? '')
                            )) ?>">
                                <td data-label="Key">
                                    <div class="lm-keyrow">
                                        <code><?= lm_e((string) $license['license_key']) ?></code>
                                        <?= lm_app_copy((string) $license['license_key'], 'Copy') ?>
                                    </div>
                                </td>
                                <td data-label="Domain"><?= lm_e((string) ($license['domain'] ?? '—')) ?></td>
                                <td data-label="Customer"><?= lm_e((string) ($license['customer_email'] ?? '—')) ?></td>
                                <td data-label="Type"><?= lm_app_badge(ucfirst($type), $type === 'extended' ? 'info' : '') ?></td>
                                <td data-label="Status"><?= lm_app_badge(ucfirst($status !== '' ? $status : 'unknown'), $isActive ? 'ok' : 'bad') ?></td>
                                <td data-label="Issued"><?= lm_e($license['created_at'] ? date('M j, Y', strtotime((string) $license['created_at'])) : '—') ?></td>
                                <td data-label="" class="lm-cell-actions">
                                    <div class="lm-actions">
                                        <a class="lm-btn lm-btn--sm lm-btn--ghost" href="licenses.php?action=edit&amp;id=<?= (int) $license['id'] ?>">Edit</a>
                                        <form method="POST" style="display:inline">
                                            <?= lm_csrf_field() ?>
                                            <input type="hidden" name="lm_action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int) $license['id'] ?>">
                                            <input type="hidden" name="current_status" value="<?= lm_e($status) ?>">
                                            <button type="submit" class="lm-btn lm-btn--sm <?= $isActive ? 'lm-btn--danger' : 'lm-btn--ghost' ?>">
                                                <?= $isActive ? 'Suspend' : 'Activate' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="lm-help" id="licenceFilterEmpty" hidden style="padding:14px 18px">No licence matches that filter.</p>
        <?php endif; ?>
    </div>
</section>

<section class="lm-panel" id="requests">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('clock') ?> Licence requests</h2>
            <p>Approving creates the key, records a manual transaction and queues the email.</p>
        </div>
        <div class="lm-btnrow">
            <?= lm_app_badge($requestCounts['pending'] . ' pending', 'warn') ?>
            <?= lm_app_badge($requestCounts['approved'] . ' approved', 'ok') ?>
            <?= lm_app_badge($requestCounts['declined'] . ' declined', 'bad') ?>
        </div>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($requests)): ?>
            <?php lm_app_empty(
                'check',
                'No requests',
                'Requests come from the public order form. When a customer asks for a licence it lands here for approval.',
                '<a class="lm-btn lm-btn--ghost" href="' . lm_e(lm_public_url('order.php')) . '" target="_blank" rel="noopener">'
                    . lm_icon('external') . ' Open the order form</a>'
            ); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Email</th>
                            <th>Domain</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                            <?php
                            $status = strtolower((string) ($req['status'] ?? 'pending'));
                            $tone = $status === 'approved' ? 'ok' : ($status === 'declined' ? 'bad' : 'warn');
                            ?>
                            <tr>
                                <td data-label="Reference"><code><?= lm_e((string) ($req['request_ref'] ?? '—')) ?></code></td>
                                <td data-label="Email"><?= lm_e((string) ($req['customer_email'] ?? '—')) ?></td>
                                <td data-label="Domain"><?= lm_e((string) ($req['domain'] ?? '—')) ?></td>
                                <td data-label="Status"><?= lm_app_badge(ucfirst($status), $tone) ?></td>
                                <td data-label="Submitted"><?= lm_e(!empty($req['created_at']) ? date('M j, Y', strtotime((string) $req['created_at'])) : '—') ?></td>
                                <td data-label="" class="lm-cell-actions">
                                    <?php if ($status === 'pending'): ?>
                                        <div class="lm-actions">
                                            <form method="POST" action="approve_request.php" style="display:inline">
                                                <?= lm_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                                                <button type="submit" name="action" value="approve" class="lm-btn lm-btn--sm lm-btn--green">Approve</button>
                                            </form>
                                            <form method="POST" action="approve_request.php" style="display:inline">
                                                <?= lm_csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                                                <button type="submit" name="action" value="decline" class="lm-btn lm-btn--sm lm-btn--danger">Decline</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="lm-help">Handled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php lm_app_end(); ?>
