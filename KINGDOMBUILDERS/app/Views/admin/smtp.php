<?php
use function KB\{e,url};
use KB\Security;
$test = $test ?? null;
?>
<section class="page-heading">
  <div><span class="eyebrow">KEEP EVERYONE IN THE LOOP</span><h1>A reliable connection.</h1><p>Deliver account confirmations, security notices and learning updates through your email provider.</p></div>
  <a class="button secondary" href="<?= e(url('admin/notifications')) ?>">View notification queue →</a>
</section>

<?php if ($error): ?><div class="notice error" role="alert"><p><strong>Settings were not saved.</strong> <?= e($error) ?></p></div><?php endif; ?>
<?php if ($test): ?>
  <div class="notice <?= $test['ok'] ? 'success' : 'error' ?>" role="status">
    <p><strong><?= $test['ok'] ? 'Test email accepted.' : 'Test email failed.' ?></strong> <?= e($test['message']) ?></p>
    <?php if (!$test['ok']): ?><p>Correct the detail above and test again. Your previously saved settings were not changed.</p><?php elseif (!$saved): ?><p>This test used the values currently in the form. Choose <em>Save email settings</em> to keep them.</p><?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" class="stack" autocomplete="off">
  <?= Security::csrfField() ?>
  <section class="panel">
    <div class="section-heading"><div><h2>SMTP connection</h2><p>Use the outgoing-mail details from your email provider or hosting control panel.</p></div><span class="badge <?= $settings['smtp_enabled'] ? 'green' : 'neutral' ?>"><?= $settings['smtp_enabled'] ? 'Delivery enabled' : 'Delivery disabled' ?></span></div>
    <input type="hidden" name="smtp_enabled" value="0">
    <label class="check-label"><input type="checkbox" name="smtp_enabled" value="1" <?= $settings['smtp_enabled'] ? 'checked' : '' ?>> Enable SMTP delivery</label>
    <div class="form-grid">
      <label>SMTP host<input name="smtp_host" placeholder="mail.yourdomain.com" value="<?= e($settings['smtp_host']) ?>" spellcheck="false" autocapitalize="off"><small>Hostname only — no <code>https://</code> and no port.</small></label>
      <label>Port<input name="smtp_port" type="number" min="1" max="65535" value="<?= (int)$settings['smtp_port'] ?>" required><small>587 for STARTTLS, 465 for SSL.</small></label>
    </div>
    <div class="form-grid">
      <label>Connection security<select name="smtp_security">
        <option value="tls" <?= $settings['smtp_security'] === 'tls' ? 'selected' : '' ?>>STARTTLS (usually port 587)</option>
        <option value="ssl" <?= $settings['smtp_security'] === 'ssl' ? 'selected' : '' ?>>Implicit TLS (usually port 465)</option>
        <?php if (KB\config('local_preview', false)): ?><option value="none" <?= $settings['smtp_security'] === 'none' ? 'selected' : '' ?>>Local sandbox only — no encryption</option><?php endif; ?>
      </select></label>
      <label>SMTP username<input name="smtp_username" autocomplete="off" spellcheck="false" value="<?= e($settings['smtp_username']) ?>"><small>Often the full email address.</small></label>
    </div>
    <label>SMTP password / app password<input name="smtp_password" type="password" autocomplete="new-password" spellcheck="false" placeholder="<?= $settings['smtp_secret'] ? 'Saved securely — leave blank to keep the current password' : 'Enter the mailbox or app password' ?>"><small>Encrypted at rest and never sent back to the browser. Most providers require an app-specific password, not your normal login.</small></label>
  </section>

  <section class="panel">
    <h2>Sender identity</h2>
    <div class="form-grid">
      <label>From name<input name="from_name" maxlength="120" value="<?= e($settings['from_name']) ?>" required></label>
      <label>From email<input name="from_email" type="email" value="<?= e($settings['from_email']) ?>" spellcheck="false"></label>
    </div>
    <div class="notice info"><p>Use a sender address on your own domain, and publish SPF, DKIM and DMARC records with your provider. Test messages go to your administrator email: <strong><?= e($settings['admin_email']) ?></strong>.</p></div>
  </section>

  <?php $showPathField = true; require __DIR__ . '/_cron.php'; ?>

  <div class="form-actions sticky-actions">
    <button class="button" name="action" value="save">Save email settings</button>
    <button class="button secondary" name="action" value="save_test">Save &amp; send test</button>
    <button class="button secondary" name="action" value="test">Test without saving</button>
  </div>
</form>

<section class="panel help-panel">
  <h2>If delivery still fails</h2>
  <div class="help-grid">
    <div><h3>Connection refused or timed out</h3><p>Your host may block outbound SMTP. Confirm the host and port, and ask your provider to allow outbound connections to that port.</p></div>
    <div><h3>Authentication failed</h3><p>Re-enter the password. Gmail, Microsoft 365 and most hosts require an app password or SMTP-specific credential.</p></div>
    <div><h3>STARTTLS rejected</h3><p>Try implicit TLS on port 465, or the other way around. Your provider documents the correct pair.</p></div>
    <div><h3>Sender rejected</h3><p>The From email must belong to the authenticated mailbox or a verified sender identity.</p></div>
    <div><h3>Message accepted but not received</h3><p>Check spam, then SPF/DKIM/DMARC. The test result reports acceptance by the mail server, not final inbox placement.</p></div>
    <div><h3>Nothing is being delivered</h3><p>Messages wait in the queue until the scheduled task runs. Add the cron command above, then retry failed messages in Notifications.</p></div>
  </div>
  <p class="muted">Provider examples — cPanel mail: <code>mail.yourdomain.com</code> port 587 STARTTLS. Gmail: <code>smtp.gmail.com</code> port 587 STARTTLS with an app password. Microsoft 365: <code>smtp.office365.com</code> port 587 STARTTLS. Brevo: <code>smtp-relay.brevo.com</code> port 587. Mailgun: <code>smtp.mailgun.org</code> port 587.</p>
</section>
