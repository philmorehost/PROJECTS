<?php
declare(strict_types=1);

use RISE63\Core\Program;

use function RISE63\{e, setting, url};
?>
<section class="card">
  <h1>Programs</h1>
  <p class="muted">Four ways the Province supports young people and super teens in Lagos Province 63.
    Programme year <?= e((string) setting('current_cycle_year', date('Y'))) ?>.</p>
</section>

<div class="grid grid-2">
  <?php foreach ($eligibility as $type => $info): ?>
    <article class="card program-card">
      <span class="program-icon" aria-hidden="true"><?= e(match ($type) {
          'academy' => '◈', 'seed_grant' => '₦', 'cooperative' => '⇄', default => '★',
      }) ?></span>
      <h2 style="margin:0"><?= e($info['label']) ?></h2>
      <p><?= e(Program::summary($type)) ?></p>
      <?php if ($type === 'seed_grant'): ?>
        <p class="faint">Monthly pool <?= e(\RISE63\Core\Money::fromDecimal((string) setting('seed_grant_monthly_pool', '0'))->format()) ?>.
          Maximum per application <?= e($maxGrant->format()) ?>.</p>
      <?php endif; ?>
      <?php if ($info['available']): ?>
        <a class="btn btn-primary" href="<?= e(url('programs', ['program' => $type])) ?>">Start application</a>
      <?php else: ?>
        <div class="notice"><strong>Not available yet</strong><?= e((string) $info['reason']) ?></div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</div>
