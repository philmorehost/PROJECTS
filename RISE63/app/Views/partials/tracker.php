<?php
declare(strict_types=1);

use RISE63\Core\Program;

use function RISE63\e;

/** @var array{step:int,total:int,label:string,detail:string} $tracker */
$steps = Program::trackerSteps();
?>
<div class="tracker" role="list" aria-label="Application progress">
  <?php foreach ($steps as $number => $label): ?>
    <?php
      $state = '';
      if ($tracker['step'] > $number) {
          $state = 'done';
      } elseif ($tracker['step'] === $number) {
          $state = 'current';
      }
    ?>
    <div class="tracker-step <?= $state ?>" role="listitem">
      <?= e($label) ?><?= $state === 'current' ? ' (current)' : ($state === 'done' ? ' ✓' : '') ?>
    </div>
  <?php endforeach; ?>
</div>
<p class="faint" aria-live="polite">Step <?= (int) max(1, $tracker['step']) ?> of <?= (int) $tracker['total'] ?>: <?= e($tracker['label']) ?> — <?= e($tracker['detail']) ?></p>
