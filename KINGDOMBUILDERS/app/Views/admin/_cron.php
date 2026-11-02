<?php
use function KB\{e,url};
use KB\Security;
$showPathField ??= false;
$lastRun = null;
if ($heartbeat && !empty($heartbeat['recorded_at'])) {
    $timestamp = strtotime($heartbeat['recorded_at'] . ' UTC');
    if ($timestamp !== false) $lastRun = $timestamp;
}
$stale = $lastRun === null || (time() - $lastRun) > 600;
?>
<section class="panel cron-panel" id="scheduled-tasks">
  <div class="section-heading">
    <div><span class="eyebrow">BACKGROUND DELIVERY</span><h2>Scheduled task (cron job)</h2></div>
    <span class="badge <?= $stale ? 'neutral' : 'green' ?>"><?= $stale ? 'Waiting for a recent run' : 'Running normally' ?></span>
  </div>
  <p class="lead-small">Email notifications are saved to a queue and delivered in the background. Your host runs the command below <strong>once every minute</strong>. This keeps page loads fast even when the mail provider is slow.</p>

  <div class="cron-links">
    <a class="text-link" href="<?= e(url('admin/smtp')) ?>#scheduled-tasks">SMTP connection settings →</a>
    <a class="text-link" href="<?= e(url('admin/notifications')) ?>">Delivery queue →</a>
  </div>
  <?php if ($showPathField): ?>
  <label class="cron-path-label">PHP command-line path <small>Leave blank to detect automatically. On cPanel, use the path for the PHP version selected in “MultiPHP Manager”.</small>
    <input name="php_cli_path" placeholder="<?= e($cron['php']) ?>" value="<?= e(KB\Settings::get('php_cli_path', '')) ?>" inputmode="text" spellcheck="false">
  </label>
  <?php endif; ?>
  <?php if (!$cron['php_detected']): ?><div class="notice warning"><p>The PHP path below is a sensible default but could not be verified on this server. Confirm it with your host, or enter the correct path above and save.</p></div><?php endif; ?>

  <div class="copy-field">
    <code id="cron-command"><?= e($cron['crontab']) ?></code>
    <button type="button" class="button secondary small" data-copy="#cron-command" data-copy-label="Copy command">Copy command</button>
  </div>

  <details class="cron-help">
    <summary><span>How to add this cron job</span><span aria-hidden="true">+</span></summary>
    <div>
      <ol class="cron-steps">
        <li><strong>cPanel or Plesk:</strong> open <em>Cron Jobs</em> → <em>Add New Cron Job</em>. Choose “Once per minute” (<code>* * * * *</code>) and paste the command above. Leave email notifications blank unless you want a message for every run.</li>
        <li><strong>SSH / terminal:</strong> run <code>crontab -e</code> and add one line: the command above. Save and exit.</li>
        <li><strong>No cron tool in your panel?</strong> Ask your hosting provider to add the command, or use any scheduler that can run a shell command on the server. A web-page “ping” will not work: background delivery must run as a command-line task.</li>
        <li><strong>Panel strips the <code>KB_CONFIG_DIR</code> prefix?</strong> Put the whole command in a small shell script inside your private directory, then schedule that script instead.</li>
        <li><strong>Verify:</strong> wait two minutes, reload this page and check the “Last background run” below. Then send a test message and confirm it appears as <em>Sent</em> in Notifications.</li>
      </ol>
      <div class="cron-facts">
        <div><span>PHP command</span><code><?= e($cron['php']) ?><?= $cron['php_detected'] ? ' <em>(found on this server)</em>' : '' ?></code></div>
        <div><span>Worker script</span><code><?= e($cron['worker']) ?></code></div>
        <div><span>Log file</span><code><?= e($cron['log']) ?></code></div>
        <div><span>Private configuration</span><code><?= e($cron['config_dir']) ?></code></div>
      </div>
      <p class="muted">The command includes <code>KB_CONFIG_DIR</code> so the scheduled task reads the same private configuration as the website. Keep this page private: it contains server paths. The task is safe to overlap — a lock prevents duplicate runs.</p>
    </div>
  </details>

  <div class="cron-status">
    <div><span>Last background run</span><strong><?= $heartbeat && !empty($heartbeat['recorded_at']) ? e($heartbeat['recorded_at']) . ' UTC' : 'No run recorded yet' ?></strong></div>
    <?php if ($heartbeat && !empty($heartbeat['recorded_at'])): ?>
      <div><span>Result</span><strong><?= !empty($heartbeat['ok']) ? 'Completed' : 'Stopped with an error' ?></strong></div>
      <div><span>Messages delivered</span><strong><?= (int)($heartbeat['sent'] ?? 0) ?></strong></div>
      <div><span>Messages unsuccessful</span><strong><?= (int)($heartbeat['failed'] ?? 0) ?></strong></div>
    <?php endif; ?>
  </div>
  <?php if ($lastRun !== null && $stale): ?><div class="notice warning"><p>The last recorded run is more than ten minutes old. Check that the cron job is enabled and that its output/log has no errors.</p></div><?php endif; ?>
</section>
