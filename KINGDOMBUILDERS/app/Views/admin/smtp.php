<?php
use function KB\{e,url};
use KB\Security;
$test = $test ?? null;
$diagnosis = $diagnosis ?? null;
?>
<section class="page-heading">
  <div><span class="eyebrow">KEEP EVERYONE IN THE LOOP</span><h1>A reliable connection.</h1><p>Deliver account confirmations, security notices and learning updates through your email provider.</p></div>
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <form method="post" style="margin:0"><input type="hidden" name="action" value="diagnose"><?= Security::csrfField() ?><button class="button secondary">Diagnose connection</button></form>
    <a class="button secondary" href="<?= e(url('admin/notifications')) ?>">View notification queue →</a>
    <a class="text-link" href="<?= e(url('admin/smtp/preview')) ?>" target="_blank" rel="noopener">Preview email design ↗</a>
  </div>
</section>

<?php if ($error): ?><div class="notice error" role="alert"><p><strong>Settings were not saved.</strong> <?= e($error) ?></p></div><?php endif; ?>
<div class="notice info"><div><strong>How the test works</strong><p>Both test buttons connect to your mail server immediately and report the exact result — the test is never queued. Activity emails (confirmations, resets, results) do use the queue, so a row marked <em>Pending</em> in Notifications means the scheduled task has not run yet, not that SMTP is broken.</p></div><a class="text-link" href="<?= e(url('admin/notifications')) ?>">Open the queue →</a></div>
<?php if ($test): ?>
  <div class="notice <?= $test['ok'] ? 'success' : 'error' ?>" role="status">
    <p><strong><?= $test['ok'] ? 'Test email accepted.' : 'Test email failed.' ?></strong> <?= e($test['message']) ?></p>
    <?php if (!$test['ok']): ?><p>Correct the detail above and test again. Your previously saved settings were not changed.</p><?php elseif (!$saved): ?><p>This test used the values currently in the form. Choose <em>Save email settings</em> to keep them.</p><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($diagnosis): ?>
  <?php
    $rec = $diagnosis['recommendation'];
    $tone = $rec['code'] === 'ok' ? 'success' : ($rec['code'] === 'switch' ? 'warning' : 'error');
    $working = null;
    foreach ($diagnosis['probes'] as $probe) { if (!empty($probe['ok'])) { $working = $probe; break; } }
  ?>
  <section class="panel diagnosis-panel">
    <div class="section-heading">
      <div><span class="eyebrow">DIAGNOSIS</span><h2>What this server can reach</h2></div>
      <span class="badge <?= $rec['code'] === 'ok' ? 'green' : ($rec['code'] === 'switch' ? 'neutral' : 'red') ?>"><?= $rec['code'] === 'ok' ? 'Connection healthy' : ($rec['code'] === 'switch' ? 'Different port works' : 'No working port') ?></span>
    </div>
    <div class="notice <?= e($tone) ?>"><p><strong><?= e($diagnosis['host']) ?></strong> — <?= e($rec['text']) ?></p></div>
    <?php if ($rec['code'] === 'switch'): ?>
      <form method="post" class="form-actions" style="margin-bottom:20px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="apply-transport">
        <input type="hidden" name="port" value="<?= (int) $rec['port'] ?>">
        <input type="hidden" name="security" value="<?= e($rec['security']) ?>">
        <button class="button">Use port <?= (int) $rec['port'] ?> with <?= e($working['label'] ?? 'TLS') ?></button>
        <span class="muted">Changes only the port and security mode. Your host, username and password stay as saved.</span>
      </form>
    <?php endif; ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Port</th><th>Security</th><th>Result</th><th>Detail</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($diagnosis['probes'] as $probe): ?>
          <tr>
            <td><strong><?= (int) $probe['port'] ?></strong><?php if ($probe['configured']): ?><small>Saved setting</small><?php endif; ?></td>
            <td><?= e($probe['label']) ?></td>
            <td><span class="badge <?= $probe['ok'] ? 'green' : 'red' ?>"><?= $probe['ok'] ? 'Ready' : e($probe['stage']) ?></span></td>
            <td><small style="font-size:.9rem"><?= e($probe['detail']) ?></small><?php if (!empty($probe['banner'])): ?><small><?= e($probe['banner']) ?></small><?php endif; ?></td>
            <td><?= (int) $probe['ms'] ?> ms</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="muted" style="margin-top:16px">Host resolves to <?= $diagnosis['ips'] === [] ? 'nothing — check the spelling' : e(implode(', ', array_slice($diagnosis['ips'], 0, 4))) ?>. The diagnosis never sends your username or password, and it does not change any setting unless you press the button above.</p>
  </section>
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
    <div><h3>Connection refused or timed out</h3><p>Press <strong>Diagnose connection</strong> at the top of this page: it tests 465, 587 and the saved port from this server and tells you which one answers. Shared hosts often block outbound SMTP entirely.</p></div>
    <div><h3>Authentication failed</h3><p>Re-enter the password. Gmail, Microsoft 365 and most hosts require an app password or SMTP-specific credential.</p></div>
    <div><h3>STARTTLS rejected</h3><p>Try implicit TLS on port 465, or the other way around. Your provider documents the correct pair.</p></div>
    <div><h3>Sender rejected</h3><p>The From email must belong to the authenticated mailbox or a verified sender identity.</p></div>
    <div><h3>Message accepted but not received</h3><p>Check spam, then SPF/DKIM/DMARC. The test result reports acceptance by the mail server, not final inbox placement.</p></div>
    <div><h3>Nothing is being delivered</h3><p>Messages wait in the queue until the scheduled task runs. Add the cron command above, then retry failed messages in Notifications.</p></div>
  </div>
  <p class="muted">Provider examples — cPanel mail: <code>mail.yourdomain.com</code> port 587 STARTTLS. Gmail: <code>smtp.gmail.com</code> port 587 STARTTLS with an app password. Microsoft 365: <code>smtp.office365.com</code> port 587 STARTTLS. Brevo: <code>smtp-relay.brevo.com</code> port 587. Mailgun: <code>smtp.mailgun.org</code> port 587.</p>
</section>
