<?php
use function KB\{e,url};
use KB\Security;
?>
<section class="page-heading">
  <div><span class="eyebrow">BACKGROUND DELIVERY</span><h1>One command keeps email moving.</h1><p>Your site stores notifications safely, then a scheduled task delivers them. Set it up once and forget it.</p></div>
  <a class="button secondary" href="<?= e(url('admin/smtp')) ?>">SMTP connection →</a>
</section>

<div class="notice info">
  <div><strong>Why a scheduled task?</strong><p>Sending email inside a page request would slow sign-in and could time out. The queue plus this command keeps pages fast and retries failures automatically.</p></div>
</div>

<?php
$showPathField = true;
require __DIR__ . '/_cron.php';
?>

<section class="panel">
  <h2>If the command does not work</h2>
  <div class="help-grid">
    <div><h3>“Could not open input file”</h3><p>The worker path is wrong. Use the exact worker path shown above; on shared hosting the document root is usually <code>/home/USER/public_html</code>.</p></div>
    <div><h3>“PHP command not found”</h3><p>Enter the absolute CLI PHP path on the Email &amp; SMTP page, then copy the command again. On cPanel, MultiPHP Manager shows the version; the path looks like <code>/opt/cpanel/ea-php83/root/usr/bin/php</code>.</p></div>
    <div><h3>Job runs but nothing is delivered</h3><p>Open <a href="<?= e(url('admin/smtp')) ?>">Email &amp; SMTP</a> and use <em>Test without saving</em>. The queue only sends when SMTP is enabled <strong>and</strong> the test succeeds.</p></div>
    <div><h3>Messages stay queued</h3><p>Check the log file listed above. If your host blocks outbound SMTP, ask them to allow the mail port you configured.</p></div>
    <div><h3>No cron feature at all</h3><p>Ask your host to schedule the command, or run <strong>Deliver queued messages now</strong> on <a href="<?= e(url('admin/notifications')) ?>">Notifications</a> while you arrange it.</p></div>
    <div><h3>Want proof it ran?</h3><p>Each successful run records a heartbeat, shown above with the number of emails sent. Anything older than ten minutes raises a warning on the dashboard.</p></div>
  </div>
</section>
