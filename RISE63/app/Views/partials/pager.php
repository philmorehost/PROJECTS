<?php
declare(strict_types=1);

// The partial is required from several views, each in the global namespace, so it imports what it
// uses itself rather than relying on the including file's imports.
use function RISE63\e;
use function RISE63\url as page_url;

/**
 * Pagination bar.
 *
 * Two constraints shaped this:
 *
 *  - **It has to work on a 390 px phone without a horizontal scrollbar.** The row is a wrapping
 *    flex line, not a fixed bar; each control keeps a 44 px touch target and the middle caption
 *    shrinks first, because the buttons are the part that must stay reachable with a thumb.
 *  - **It has to work without JavaScript.** Paging is ordinary links carrying `?before=` /
 *    `?per_page=` (see Paginator), so it survives an offline moment, a slow connection, the browser
 *    "back" button and a reader that ignores scripts. There is no "load more" spinner to get stuck.
 *
 * @var \RISE63\Core\Paginator $pager
 * @var list<array<string, mixed>> $rows  the rows currently shown
 * @var string $label what is being paged, e.g. "events"
 * @var bool|null $allowSizes show the page-size chooser
 */

$allowSizes = $allowSizes ?? true;
$lastRow    = $rows === [] ? null : $rows[array_key_last($rows)];
$firstId    = $lastRow['id'] ?? null;
$shown      = count($rows);
?>
<nav class="pager" aria-label="Pagination for <?= e($label) ?>">
  <div class="pager-controls">
    <?php if ($pager->hasPrevious): ?>
      <a class="btn btn-secondary btn-sm" href="<?= e($pager->newerUrl()) ?>" rel="prev">
        <span aria-hidden="true">←</span> Newer
      </a>
    <?php else: ?>
      <span class="btn btn-sm btn-quiet" aria-disabled="true">Newest</span>
    <?php endif; ?>

    <?php if ($pager->hasNext && $firstId !== null): ?>
      <a class="btn btn-secondary btn-sm" href="<?= e($pager->olderUrl((string) $firstId)) ?>" rel="next">
        Older <span aria-hidden="true">→</span>
      </a>
    <?php else: ?>
      <span class="btn btn-sm btn-quiet" aria-disabled="true">Oldest</span>
    <?php endif; ?>
  </div>

  <p class="pager-caption faint" role="status">
    <?php if ($shown === 0): ?>
      Nothing to show
    <?php else: ?>
      Showing <strong><?= (int) $shown ?></strong> <?= e($label) ?><?= $pager->before !== null ? ' · older than the newest' : ' · newest first' ?>
    <?php endif; ?>
  </p>

  <?php if ($allowSizes): ?>
    <div class="pager-sizes" role="group" aria-label="Rows per page">
      <span class="faint">Per page</span>
      <?php foreach ([10, 25, 50, 100] as $size): ?>
        <?php if ($pager->perPage === $size): ?>
          <span class="chip chip-on" aria-current="true"><?= $size ?></span>
        <?php else: ?>
          <a class="chip" href="<?= e($pager->sizeUrl($size)) ?>"><?= $size ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</nav>
