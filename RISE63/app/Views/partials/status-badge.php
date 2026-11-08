<?php
declare(strict_types=1);

use RISE63\Core\Program;

use function RISE63\e;

/** @var string $status */
$map = [
    'draft'               => ['', '✎'],
    'submitted'           => ['badge-info', '→'],
    'under_review'        => ['badge-warn', '◔'],
    'documents_requested' => ['badge-warn', '⌑'],
    'verified'            => ['badge-info', '✓'],
    'approved'            => ['badge-ok', '★'],
    'disbursed'           => ['badge-ok', '₦'],
    'completed'           => ['badge-ok', '✓'],
    'rejected'            => ['badge-danger', '✕'],
    'withdrawn'           => ['', '⏻'],
];
[$class, $icon] = $map[$status] ?? ['', '•'];
?>
<span class="badge <?= e($class) ?>"><span aria-hidden="true"><?= e($icon) ?></span> <?= e(Program::statusLabel($status)) ?></span>
