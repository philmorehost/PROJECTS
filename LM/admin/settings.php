<?php
/**
 * admin/settings.php — everything the console reads from settings.json.
 *
 * The previous page wrote the file directly and only ever saved the handful of
 * keys it printed, so any other key (GitHub token, cron secret, reminder
 * windows, vendor contact) disappeared on save. This version writes through
 * lm_update_setting(), which locks, snapshots and merges — nothing is dropped.
 *
 * Sections: site, payments, licensing, SMTP, notifications, storage/cron.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$settings = lm_settings();
$notice = '';
$problem = '';

/* ------------------------------------------------------------------ write */

if (lm_app_handle_post()) {
    $posted = array();

    $text = function ($key) use (&$posted) {
        $posted[$key] = trim((string) ($_POST[$key] ?? ''));
    };

    $text('site_name');
    $text('site_url');
    $text('admin_email');
    $text('vendor_whatsapp');
    $text('vendor_contact_message');
    $text('paystack_public_key');
    $text('paystack_secret_key');
    $text('api_secret_key');
    $text('smtp_host');
    $text('smtp_port');
    $text('smtp_user');
    $text('smtp_encryption');
    $text('smtp_from_email');
    $text('smtp_from_name');
    $text('trusted_proxies');
    $text('updates_storage_dir');
    $text('update_instructions_admin');
    $text('update_instructions_cron');
    $text('github_api_base');

    // A blank password field means "keep the stored one", never "erase it".
    if (trim((string) ($_POST['smtp_pass'] ?? '')) !== '') {
        $posted['smtp_pass'] = (string) $_POST['smtp_pass'];
    }

    $posted['license_price'] = (string) max(0, (float) ($_POST['license_price'] ?? 0));
    $posted['max_domain_changes'] = max(1, (int) ($_POST['max_domain_changes'] ?? 3));
    $posted['max_update_size_mb'] = max(1, (int) ($_POST['max_update_size_mb'] ?? 128));
    $posted['update_notify_delay_minutes'] = max(0, (int) ($_POST['update_notify_delay_minutes'] ?? 0));
    $posted['update_reminder_hours'] = max(1, (int) ($_POST['update_reminder_hours'] ?? 24));
    $posted['update_reminder_max'] = max(0, (int) ($_POST['update_reminder_max'] ?? 1));
    $posted['update_email_batch_size'] = max(1, (int) ($_POST['update_email_batch_size'] ?? 25));
    $posted['release_keep'] = max(1, min(50, (int) ($_POST['release_keep'] ?? 1)));
    $posted['release_keep_days'] = max(0, min(3650, (int) ($_POST['release_keep_days'] ?? 0)));
    $posted['smtp_timeout'] = max(5, (int) ($_POST['smtp_timeout'] ?? 20));

    $posted['payment_gateway_enabled'] = isset($_POST['payment_gateway_enabled']);
    $posted['update_notify_enabled'] = isset($_POST['update_notify_enabled']);
    $posted['smtp_verify_peer'] = isset($_POST['smtp_verify_peer']);
    $posted['update_skip_if_downloaded'] = isset($_POST['update_skip_if_downloaded']);
    $posted['release_auto_prune'] = isset($_POST['release_auto_prune']);

    $ips = (string) ($_POST['whitelisted_ips'] ?? '');
    $posted['whitelisted_ips'] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $ips) ?: array())));

    if ($posted['site_url'] !== '' && !preg_match('#^https?://#i', $posted['site_url'])) {
        $posted['site_url'] = 'https://' . ltrim($posted['site_url'], '/');
    }

    if (!empty($_POST['site_logo_reset'])) {
        $posted['site_logo'] = '';
    }

    if (!empty($_FILES['site_logo']['name']) && (int) ($_FILES['site_logo']['error'] ?? 1) === 0) {
        $allowed = array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'gif' => 'image/gif');
        $ext = strtolower(pathinfo((string) $_FILES['site_logo']['name'], PATHINFO_EXTENSION));
        $size = (int) ($_FILES['site_logo']['size'] ?? 0);

        if (!isset($allowed[$ext])) {
            $problem = 'The logo must be a PNG, JPG, SVG, WEBP or GIF file.';
        } elseif ($size > 2 * 1024 * 1024) {
            $problem = 'The logo must be smaller than 2 MB.';
        } else {
            $dir = lm_lm_dir() . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $name = 'logo-' . date('Ymd-His') . '.' . $ext;
            if (@move_uploaded_file($_FILES['site_logo']['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
                $posted['site_logo'] = 'uploads/' . $name;
            } else {
                $problem = 'The uploads/ folder is not writable, so the logo was not saved.';
            }
        }
    }

    if ($problem === '') {
        // Merge over the live file, so keys this form does not print (GitHub
        // token, cron secret, SMTP debug) survive the save untouched.
        if (lm_update_setting($posted)) {
            $settings = lm_settings(true);
            $notice = 'Settings saved. A snapshot of the previous file is in storage/backups.';
        } else {
            $problem = 'settings.json could not be written. Check that the LM folder is writable (0755 on folders, 0644 on files).';
        }
    }
}

$webhookUrl = function_exists('lm_site_url') ? lm_site_url('webhook.php') : '';
$cronUrl = function_exists('lm_site_url') ? lm_site_url('cron.php') : '';
$secret = function_exists('lm_get_cron_secret') ? lm_get_cron_secret() : (string) ($settings['cron_secret'] ?? '');
if ($secret !== '') {
    $cronUrl .= (strpos($cronUrl, '?') === false ? '?' : '&') . 'secret=' . rawurlencode($secret);
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="system-check.php">' . lm_icon('shield') . ' System check</a>'
    . '<a class="lm-btn lm-btn--ghost" href="guide.php">' . lm_icon('book') . ' Admin guide</a>';

lm_app_start('Settings', array(
    'subtitle' => 'Site identity, payments, SMTP and the update pipeline. Every value here is read live.',
    'active'   => 'settings',
    'actions'  => $actionsHtml,
));
?>

<?php if ($notice !== ''): ?>
    <?php lm_alert('ok', $notice); ?>
<?php endif; ?>
<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<div class="lm-note">
    <?= lm_icon('shield') ?>
    <span><strong>This page holds secrets.</strong> The Paystack secret key, SMTP password and cron secret are stored
        in <code>settings.json</code>. Keep that file out of git and out of any ZIP you hand to a customer.</span>
</div>

<form method="POST" enctype="multipart/form-data" class="lm-form" style="display:block">
    <?= lm_csrf_field() ?>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('globe') ?> Site</h2>
                <p>How the product names itself and where it links back to.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="site_name">Site name</label>
                    <input class="lm-input" type="text" id="site_name" name="site_name"
                           value="<?= lm_e((string) ($settings['site_name'] ?? '')) ?>" placeholder="License Manager">
                </div>
                <div class="lm-field">
                    <label for="site_url">Site URL</label>
                    <input class="lm-input" type="url" id="site_url" name="site_url"
                           value="<?= lm_e((string) ($settings['site_url'] ?? '')) ?>" placeholder="https://example.com/lm">
                    <p class="lm-help">Used for links in emails and webhook URLs. Leave blank to detect automatically.</p>
                </div>
                <div class="lm-field">
                    <label for="admin_email">Admin email</label>
                    <input class="lm-input" type="email" id="admin_email" name="admin_email"
                           value="<?= lm_e((string) ($settings['admin_email'] ?? '')) ?>" placeholder="you@example.com">
                    <p class="lm-help">Every new request and approval notification is copied here.</p>
                </div>
                <div class="lm-field">
                    <label for="site_logo">Logo</label>
                    <input class="lm-input" type="file" id="site_logo" name="site_logo" accept="image/*">
                    <p class="lm-help">PNG, JPG, SVG or WEBP, up to 2 MB. Replaces the key glyph in the header.</p>
                </div>
            </div>

            <?php if (!empty($settings['site_logo'])): ?>
                <div class="lm-logo-preview">
                    <img src="<?= lm_e(lm_site_logo_url()) ?>" alt="Current logo">
                    <div>
                        <span class="lm-help">Current logo: <code><?= lm_e((string) $settings['site_logo']) ?></code></span>
                        <label class="lm-check" style="margin-top:8px">
                            <input type="checkbox" name="site_logo_reset" value="1">
                            <span class="txt">
                                <strong>Remove the logo</strong>
                                <small>Falls back to the built-in mark. The file stays in uploads/.</small>
                            </span>
                        </label>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('wallet') ?> Payments</h2>
                <p>Paystack checkout. With the gateway off, customers submit a request you approve by hand.</p>
            </div>
            <label class="lm-check" style="margin:0">
                <input type="checkbox" name="payment_gateway_enabled" value="1"
                    <?= !empty($settings['payment_gateway_enabled']) ? 'checked' : '' ?>>
                <span class="txt"><strong>Gateway enabled</strong></span>
            </label>
        </div>
        <div class="lm-panel__body">
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="paystack_public_key">Paystack public key</label>
                    <input class="lm-input lm-mono" type="text" id="paystack_public_key" name="paystack_public_key"
                           value="<?= lm_e((string) ($settings['paystack_public_key'] ?? '')) ?>" placeholder="pk_…">
                </div>
                <div class="lm-field">
                    <label for="paystack_secret_key">Paystack secret key</label>
                    <input class="lm-input lm-mono" type="password" id="paystack_secret_key" name="paystack_secret_key"
                           value="<?= lm_e((string) ($settings['paystack_secret_key'] ?? '')) ?>" placeholder="sk_…">
                    <p class="lm-help">Also verifies the webhook signature. Never share this key.</p>
                </div>
                <div class="lm-field">
                    <label for="license_price">Licence price</label>
                    <input class="lm-input" type="number" step="0.01" min="0" id="license_price" name="license_price"
                           value="<?= lm_e((string) ($settings['license_price'] ?? '0')) ?>">
                    <p class="lm-help">Charged per licence, in the currency your Paystack account uses.</p>
                </div>
            </div>

            <div class="lm-field" style="margin-bottom:0">
                <span class="lm-label">Webhook URL</span>
                <div class="lm-keyrow">
                    <code class="lm-key"><?= lm_e($webhookUrl) ?></code>
                    <?= lm_app_copy($webhookUrl, 'Copy') ?>
                </div>
                <p class="lm-help">Paste this into Paystack → Settings → API Keys &amp; Webhooks, and subscribe to
                    <code>charge.success</code>.</p>
            </div>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('key') ?> Licensing</h2>
                <p>How much freedom a customer has with the key they bought.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="max_domain_changes">Domain changes allowed</label>
                    <input class="lm-input" type="number" min="1" max="100" id="max_domain_changes" name="max_domain_changes"
                           value="<?= lm_e((string) ($settings['max_domain_changes'] ?? 3)) ?>">
                    <p class="lm-help">Per licence. The customer makes the change themselves from their dashboard.</p>
                </div>
                <div class="lm-field">
                    <label for="api_secret_key">API secret key</label>
                    <input class="lm-input lm-mono" type="text" id="api_secret_key" name="api_secret_key"
                           value="<?= lm_e((string) ($settings['api_secret_key'] ?? '')) ?>">
                    <p class="lm-help">Lets a trusted product create licences automatically.</p>
                </div>
                <div class="lm-field">
                    <label for="whitelisted_ips">Download whitelist</label>
                    <input class="lm-input lm-mono" type="text" id="whitelisted_ips" name="whitelisted_ips"
                           value="<?= lm_e(implode(', ', (array) ($settings['whitelisted_ips'] ?? array()))) ?>"
                           placeholder="127.0.0.1, ::1">
                    <p class="lm-help">Comma-separated. These addresses may download packages without a key.</p>
                </div>
                <div class="lm-field">
                    <label for="trusted_proxies">Trusted proxies</label>
                    <input class="lm-input lm-mono" type="text" id="trusted_proxies" name="trusted_proxies"
                           value="<?= lm_e((string) ($settings['trusted_proxies'] ?? '')) ?>" placeholder="10.0.0.0/8">
                    <p class="lm-help">Only these may set <code>X-Forwarded-For</code>. Empty means trust nobody —
                        the safe default unless the site is behind Cloudflare or a load balancer.</p>
                </div>
                <div class="lm-field">
                    <label for="vendor_whatsapp">Vendor WhatsApp</label>
                    <input class="lm-input" type="text" id="vendor_whatsapp" name="vendor_whatsapp"
                           value="<?= lm_e((string) ($settings['vendor_whatsapp'] ?? '')) ?>" placeholder="+234 800 000 0000">
                    <p class="lm-help">Shown as a contact button when a licence's update access is on hold.</p>
                </div>
                <div class="lm-field">
                    <label for="vendor_contact_message">Vendor contact note</label>
                    <input class="lm-input" type="text" id="vendor_contact_message" name="vendor_contact_message"
                           value="<?= lm_e((string) ($settings['vendor_contact_message'] ?? '')) ?>"
                           placeholder="Message shown next to the contact button">
                </div>
            </div>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('mail') ?> Email (SMTP)</h2>
                <p>Licence delivery, approval notices and update reminders all leave through this account.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="smtp_host">Host</label>
                    <input class="lm-input" type="text" id="smtp_host" name="smtp_host"
                           value="<?= lm_e((string) ($settings['smtp_host'] ?? '')) ?>" placeholder="smtp.example.com">
                </div>
                <div class="lm-field">
                    <label for="smtp_port">Port</label>
                    <input class="lm-input" type="text" id="smtp_port" name="smtp_port"
                           value="<?= lm_e((string) ($settings['smtp_port'] ?? '587')) ?>" placeholder="587">
                </div>
                <div class="lm-field">
                    <label for="smtp_encryption">Encryption</label>
                    <select class="lm-select" id="smtp_encryption" name="smtp_encryption">
                        <?php foreach (array('auto' => 'Auto', 'tls' => 'STARTTLS', 'ssl' => 'SSL / SMTPS', 'none' => 'None') as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($settings['smtp_encryption'] ?? 'auto') === $value ? 'selected' : '' ?>>
                                <?= lm_e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lm-field">
                    <label for="smtp_timeout">Timeout (seconds)</label>
                    <input class="lm-input" type="number" min="5" max="120" id="smtp_timeout" name="smtp_timeout"
                           value="<?= lm_e((string) ($settings['smtp_timeout'] ?? 20)) ?>">
                </div>
                <div class="lm-field">
                    <label for="smtp_user">Username</label>
                    <input class="lm-input" type="text" id="smtp_user" name="smtp_user"
                           value="<?= lm_e((string) ($settings['smtp_user'] ?? '')) ?>" autocomplete="off">
                </div>
                <div class="lm-field">
                    <label for="smtp_pass">Password</label>
                    <input class="lm-input" type="password" id="smtp_pass" name="smtp_pass"
                           value="" placeholder="Leave blank to keep the stored password" autocomplete="new-password">
                    <p class="lm-help">
                        <?= !empty($settings['smtp_pass']) ? 'A password is stored.' : 'No password stored yet.' ?>
                    </p>
                </div>
                <div class="lm-field">
                    <label for="smtp_from_email">From address</label>
                    <input class="lm-input" type="email" id="smtp_from_email" name="smtp_from_email"
                           value="<?= lm_e((string) ($settings['smtp_from_email'] ?? '')) ?>" placeholder="licences@example.com">
                </div>
                <div class="lm-field">
                    <label for="smtp_from_name">From name</label>
                    <input class="lm-input" type="text" id="smtp_from_name" name="smtp_from_name"
                           value="<?= lm_e((string) ($settings['smtp_from_name'] ?? '')) ?>" placeholder="Licence Desk">
                </div>
            </div>

            <label class="lm-check">
                <input type="checkbox" name="smtp_verify_peer" value="1"
                    <?= !empty($settings['smtp_verify_peer']) ? 'checked' : '' ?>>
                <span class="txt">
                    <strong>Verify the server certificate</strong>
                    <small>Leave on. Turning it off lets a man-in-the-middle read the SMTP password.</small>
                </span>
            </label>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('bell') ?> Notifications</h2>
                <p>What customers hear about a new release, and when.</p>
            </div>
            <label class="lm-check" style="margin:0">
                <input type="checkbox" name="update_notify_enabled" value="1"
                    <?= !empty($settings['update_notify_enabled']) ? 'checked' : '' ?>>
                <span class="txt"><strong>Send update emails</strong></span>
            </label>
        </div>
        <div class="lm-panel__body">
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="update_notify_delay_minutes">Delay before sending (minutes)</label>
                    <input class="lm-input" type="number" min="0" id="update_notify_delay_minutes" name="update_notify_delay_minutes"
                           value="<?= lm_e((string) ($settings['update_notify_delay_minutes'] ?? 0)) ?>">
                    <p class="lm-help">A grace period to pull a bad release before anyone is told about it.</p>
                </div>
                <div class="lm-field">
                    <label for="update_reminder_hours">Reminder interval (hours)</label>
                    <input class="lm-input" type="number" min="1" id="update_reminder_hours" name="update_reminder_hours"
                           value="<?= lm_e((string) ($settings['update_reminder_hours'] ?? 24)) ?>">
                </div>
                <div class="lm-field">
                    <label for="update_reminder_max">Maximum reminders</label>
                    <input class="lm-input" type="number" min="0" id="update_reminder_max" name="update_reminder_max"
                           value="<?= lm_e((string) ($settings['update_reminder_max'] ?? 1)) ?>">
                    <p class="lm-help">0 disables reminders; the first notice is still sent.</p>
                </div>
                <div class="lm-field">
                    <label for="update_email_batch_size">Emails per batch</label>
                    <input class="lm-input" type="number" min="1" id="update_email_batch_size" name="update_email_batch_size"
                           value="<?= lm_e((string) ($settings['update_email_batch_size'] ?? 25)) ?>">
                    <p class="lm-help">Lower it if the host throttles outgoing mail.</p>
                </div>
            </div>

            <label class="lm-check" style="margin-bottom:16px">
                <input type="checkbox" name="update_skip_if_downloaded" value="1"
                    <?= !empty($settings['update_skip_if_downloaded']) ? 'checked' : '' ?>>
                <span class="txt">
                    <strong>Skip customers who already downloaded</strong>
                    <small>Anyone who fetched the package does not also get a reminder about it.</small>
                </span>
            </label>

            <div class="lm-field">
                <label for="update_instructions_admin">How-to text — admin triggered</label>
                <textarea class="lm-textarea" id="update_instructions_admin" name="update_instructions_admin"
                          placeholder="Leave blank for the built-in instructions."><?= lm_e((string) ($settings['update_instructions_admin'] ?? '')) ?></textarea>
            </div>
            <div class="lm-field" style="margin-bottom:0">
                <label for="update_instructions_cron">How-to text — cron triggered</label>
                <textarea class="lm-textarea" id="update_instructions_cron" name="update_instructions_cron"
                          placeholder="Leave blank for the built-in instructions."><?= lm_e((string) ($settings['update_instructions_cron'] ?? '')) ?></textarea>
            </div>
        </div>
    </section>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('terminal') ?> Storage &amp; cron</h2>
                <p>Where packages live and what wakes the worker up.</p>
            </div>
        </div>
        <div class="lm-panel__body">
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="updates_storage_dir">Updates folder</label>
                    <input class="lm-input lm-mono" type="text" id="updates_storage_dir" name="updates_storage_dir"
                           value="<?= lm_e((string) ($settings['updates_storage_dir'] ?? '')) ?>" placeholder="Leave blank for storage/updates">
                    <p class="lm-help">Absolute path, or blank to use the built-in folder.</p>
                </div>
                <div class="lm-field">
                    <label for="max_update_size_mb">Maximum package size (MB)</label>
                    <input class="lm-input" type="number" min="1" id="max_update_size_mb" name="max_update_size_mb"
                           value="<?= lm_e((string) ($settings['max_update_size_mb'] ?? 128)) ?>">
                    <p class="lm-help">Must not exceed PHP's <code>upload_max_filesize</code>
                        (<?= lm_e((string) ini_get('upload_max_filesize')) ?>).</p>
                </div>
                <div class="lm-field">
                    <label for="release_keep">Releases kept per project</label>
                    <input class="lm-input" type="number" min="1" max="50" id="release_keep" name="release_keep"
                           value="<?= (int) ($settings['release_keep'] ?? 1) ?>">
                    <p class="lm-help"><code>1</code> keeps only the current release: the previous ZIP is
                        deleted as soon as a new one is published. Raise it only if you need to go back.</p>
                </div>
                <div class="lm-field">
                    <label for="release_keep_days">Keep releases for at least (days)</label>
                    <input class="lm-input" type="number" min="0" max="3650" id="release_keep_days" name="release_keep_days"
                           value="<?= (int) ($settings['release_keep_days'] ?? 0) ?>">
                    <p class="lm-help"><code>0</code> means the count above is the only rule. Otherwise a release
                        younger than this stays even when it falls outside the count.</p>
                </div>
                <div class="lm-field">
                    <label for="github_api_base">GitHub API base</label>
                    <input class="lm-input lm-mono" type="url" id="github_api_base" name="github_api_base"
                           value="<?= lm_e((string) ($settings['github_api_base'] ?? 'https://api.github.com')) ?>">
                    <p class="lm-help">Only change this for GitHub Enterprise.</p>
                </div>
            </div>

            <label class="lm-check" style="margin-top:16px">
                <input type="checkbox" name="release_auto_prune" value="1"
                    <?= !empty($settings['release_auto_prune']) ? 'checked' : '' ?>>
                <span class="txt">
                    <strong>Delete the old release automatically after every publish</strong>
                    <small>Applies to GitHub pulls and manual uploads. The current release is never deleted, and
                        anything that cannot be deleted is reported on the dashboard and in GitHub Pull instead of
                        failing quietly.</small>
                </span>
            </label>

            <div class="lm-field" style="margin-bottom:0">
                <span class="lm-label">Cron URL</span>
                <div class="lm-keyrow">
                    <code class="lm-key"><?= lm_e($cronUrl) ?></code>
                    <?= lm_app_copy($cronUrl, 'Copy') ?>
                </div>
                <p class="lm-help">Call this every few minutes from cPanel → Cron Jobs to send queued notifications.
                    The secret is generated on first use and stored in settings.json.</p>
            </div>
        </div>
    </section>

    <div class="lm-btnrow" style="margin-bottom:30px">
        <button type="submit" class="lm-btn lm-btn--glow"><?= lm_icon('check') ?> Save settings</button>
        <a class="lm-btn lm-btn--ghost" href="settings.php">Discard changes</a>
    </div>
</form>

<?php lm_app_end(); ?>
