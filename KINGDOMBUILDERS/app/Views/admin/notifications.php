<?php use function KB\{e,url}; use KB\Security; ?>
<section class="page-heading">
  <div><span class="eyebrow">EVERY IMPORTANT MOMENT</span><h1>Messages that keep moving.</h1><p>Notifications are stored safely before delivery. A slow email provider never holds up a quiz.</p></div>
  <form method="post"><?= Security::csrfField() ?><button name="action" value="test" class="button">Send test email +</button></form>
</section>

<?php if (!empty($testError)): ?><div class="notice error" role="alert"><p><strong>Test email failed.</strong> <?= e($testError) ?></p><p>Review <a href="<?= e(url('admin/smtp')) ?>">Email &amp; SMTP settings</a> and try “Test without saving”.</p></div><?php endif; ?>

<div class="notice info">
  <div><strong>Background delivery</strong><p>Messages leave the queue when the scheduled task runs. Copy the command below into your hosting cron job, once per minute.</p></div>
  <?php if (KB\config('local_preview', false)): ?><form method="post"><?= Security::csrfField() ?><button name="action" value="process" class="button secondary small">Run local worker</button></form><?php endif; ?>
</div>

<div class="copy-field">
  <code id="cron-command"><?= e($cron['crontab']) ?></code>
  <button type="button" class="button secondary small" data-copy="#cron-command" data-copy-label="Copy command">Copy command</button>
</div>
<p class="muted">PHP command: <code><?= e($cron['php']) ?></code> · Worker: <code><?= e($cron['worker']) ?></code> · Log: <code><?= e($cron['log']) ?></code>. <a href="<?= e(url('admin/smtp')) ?>#scheduled-tasks">Full cron job instructions →</a></p>

<section class="panel">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Message</th><th>Status</th><th>Attempts</th><th>Created (UTC)</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($mails as $mail): ?>
        <tr>
          <td><strong><?= e($mail['subject']) ?></strong><small><?= e($mail['recipient']) ?></small><?php if ($mail['last_error']): ?><small class="danger-text"><?= e($mail['last_error']) ?></small><?php endif; ?></td>
          <td><span class="badge <?= $mail['status'] === 'sent' ? 'green' : ($mail['status'] === 'failed' ? 'red' : 'neutral') ?>"><?= e(ucfirst($mail['status'])) ?></span></td>
          <td><?= (int)$mail['attempts'] ?><small><?= (int)$mail['deliveries'] ?> successful</small></td>
          <td><?= e($mail['created_at']) ?></td>
          <td><?php if ($mail['status'] === 'failed'): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="id" value="<?= (int)$mail['id'] ?>"><button name="action" value="retry" class="inline-button">Retry delivery</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$mails): ?><p class="empty">No notifications yet.</p><?php endif; ?>
  </div>
  <?php require __DIR__.'/../pagination.php'; ?>
</section>
