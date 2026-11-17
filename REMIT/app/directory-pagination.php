<?php declare(strict_types=1); ?>
<nav class="directory-pagination" aria-label="Church directory pages" data-directory-page="<?= $directory->page ?>">
 <p id="directory-page-counter" class="directory-page-counter">Page <?= $directory->page ?> of <?= $directory->pages ?></p>
 <div class="directory-pagination-controls" id="directory-pagination-controls">
 <?php if($directory->page>1): ?><a class="page-link" data-directory-page="<?= $directory->page-1 ?>" href="<?= e($directory->url(['directory_page'=>$directory->page-1])) ?>" rel="prev">← Previous</a><?php else: ?><span class="page-link is-disabled" aria-disabled="true">← Previous</span><?php endif ?>
 <div class="directory-page-numbers"><?php foreach($directory->numbers() as $number): ?><?php if($number===null): ?><span class="page-ellipsis" aria-hidden="true">…</span><?php else: ?><a class="page-link page-number" data-directory-page="<?= $number ?>" href="<?= e($directory->url(['directory_page'=>$number])) ?>" aria-label="Page <?= $number ?>" <?= $number===$directory->page?'aria-current="page"':'' ?>><?= $number ?></a><?php endif ?><?php endforeach ?></div>
 <?php if($directory->page<$directory->pages): ?><a class="page-link" data-directory-page="<?= $directory->page+1 ?>" href="<?= e($directory->url(['directory_page'=>$directory->page+1])) ?>" rel="next">Next →</a><?php else: ?><span class="page-link is-disabled" aria-disabled="true">Next →</span><?php endif ?>
 </div>
</nav>
