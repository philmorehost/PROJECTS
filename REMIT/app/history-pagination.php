<?php declare(strict_types=1); ?>
<nav class="history-pagination" aria-label="<?= ucfirst($history->prefix) ?> pages">
<?php if($history->page>1): ?><a class="secondary" data-history-link href="<?= e($history->url($_GET,[$history->prefix.'_page'=>$history->page-1])) ?>">← Previous</a><?php else: ?><span class="secondary" aria-disabled="true">← Previous</span><?php endif ?>
<span>Page <?= $history->page ?> / <?= $history->pages ?></span>
<?php if($history->page<$history->pages): ?><a class="secondary" data-history-link href="<?= e($history->url($_GET,[$history->prefix.'_page'=>$history->page+1])) ?>">Next →</a><?php else: ?><span class="secondary" aria-disabled="true">Next →</span><?php endif ?>
</nav>
