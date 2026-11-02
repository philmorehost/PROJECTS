<?php use function KB\{e,url}; ?>
<section class="page-heading dashboard-heading">
  <div><span class="eyebrow">THE COMMUNITY AT A GLANCE</span><h1>Welcome, <?= e(explode(' ',$admin['name'])[0]) ?>.</h1><p>Help your community learn with purpose. Here’s what’s happening today.</p></div>
  <a class="button" href="<?= e(url('admin/questions',['new'=>1])) ?>">Add a question <span aria-hidden="true">+</span></a>
</section>

<?php if(!$smtp): ?><div class="notice warning"><div><strong>Email needs your attention.</strong><p>Notifications are safely queued, but SMTP is not enabled yet. Connect your email provider to deliver them.</p></div><a class="text-link" href="<?= e(url('admin/smtp')) ?>">Set up email →</a></div><?php endif; ?>

<div class="stats-grid admin-stats">
  <?php foreach([
    'members'=>['Members','People growing together','admin/users','👥'],
    'questions'=>['Active questions','Ready for curious minds','admin/questions','📖'],
    'completed'=>['Completed attempts','Steps forward in the Word','admin/results','✓'],
    'pending'=>['Notifications waiting','Queued or needing attention','admin/notifications','✉️'],
  ] as $key=>[$label,$note,$path,$icon]): ?>
  <a class="stat-card" href="<?= e(url($path)) ?>"><span><?= e($label) ?> <b aria-hidden="true"><?= $icon ?></b></span><strong><?= number_format((int)$stats[$key]) ?></strong><small><?= e($note) ?></small></a>
  <?php endforeach; ?>
</div>

<div class="admin-insights">
  <section class="panel insight-card"><span class="eyebrow">ASSESSMENT HEALTH</span><h2><?= (int)$stats['pass_rate'] ?>% pass rate</h2><p>Across completed attempts, using each attempt’s saved pass mark.</p><div class="progress-track"><progress class="admin-progress" max="100" value="<?= (int)$stats['pass_rate'] ?>"></progress></div></section>
  <section class="panel insight-card"><span class="eyebrow">IN PROGRESS</span><h2><?= number_format((int)$stats['active']) ?> active quiz<?= (int)$stats['active']===1?'':'zes' ?></h2><p>Members currently working through an assessment. Deadlines are enforced server-side.</p><a class="text-link" href="<?= e(url('admin/results')) ?>">View results →</a></section>
  <section class="panel insight-card"><span class="eyebrow">LAST 7 DAYS</span><h2>Completed attempts</h2><?php if(!$weekly): ?><p class="muted">No completed attempts yet.</p><?php else: ?><ul class="weekly-list"><?php foreach($weekly as $day): ?><li><span><?= e($day['day']) ?></span><progress max="<?= max(1,(int)$day['total']) ?>" value="<?= (int)$day['total'] ?>"></progress><b><?= (int)$day['total'] ?></b></li><?php endforeach; ?></ul><?php endif; ?></section>
</div>

<div class="dashboard-grid admin-dashboard-grid">
  <section class="panel">
    <div class="section-heading"><div><span class="eyebrow">A LIVING RECORD</span><h2>Recent activity</h2></div><a class="text-link" href="<?= e(url('admin/audit')) ?>">View audit trail →</a></div>
    <div class="activity-list"><?php foreach($recent as $event): ?><div><span class="activity-dot"></span><div><strong><?= e(ucfirst(str_replace(['.','_'],' ',$event['action']))) ?></strong><small><?= e($event['name']??'System') ?> · <?= e($event['created_at']) ?> UTC</small></div></div><?php endforeach; ?><?php if(!$recent): ?><p class="empty">Your community’s story will appear here.</p><?php endif; ?></div>
  </section>
  <section class="start-card modern-start-card">
    <span class="eyebrow">BUILD WITH INTENTION</span><h2>A healthy community starts with care.</h2><p>Review your question bank, test your email connection and make sure your quiz rules are right for your members.</p>
    <a href="<?= e(url('admin/settings')) ?>">Review quiz settings <span>→</span></a>
    <a href="<?= e(url('admin/smtp')) ?>">Test email delivery <span>→</span></a>
    <a href="<?= e(url('admin/users')) ?>">Manage member access <span>→</span></a>
    <a href="<?= e(url('admin/notifications')) ?>">Open notification queue <span>→</span></a>
  </section>
</div>
