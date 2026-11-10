<?php
declare(strict_types=1);
require_once __DIR__.'/navigation-icons.php';
$mobileItems=['overview'=>['home','Home'],'remittances'=>['payments',$treasurer?'Ledger':'Remit'],'hierarchy'=>['church','Churches']];
$moreActive=in_array($page,['profile','settings','security','representatives'],true);
?>
<nav class="mobile-bottom-nav" aria-label="Primary footer navigation">
 <?php foreach($mobileItems as $target=>[$icon,$label]): ?><a href="?page=<?= $target ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>" <?= $page===$target?'aria-current="page"':'' ?>><?= navIcon($icon) ?><span><?= e($label) ?></span></a><?php endforeach ?>
 <button type="button" data-menu-open aria-controls="mobile-menu" aria-expanded="false" aria-haspopup="dialog" aria-label="More" title="More pages" class="<?= $moreActive?'is-current':'' ?>"><?= navIcon('menu') ?><span>More</span></button>
 <?php if(canRemit($user)): ?><a class="desktop-footer-link" href="?page=profile" <?= $page==='profile'?'aria-current="page"':'' ?>>My church account</a><?php endif ?>
 <?php if($admin): ?><a class="desktop-footer-link" href="?page=representatives">Church reps</a><a class="desktop-footer-link" href="?page=settings" <?= $page==='settings'?'aria-current="page"':'' ?>>Settings</a><a class="desktop-footer-link" href="?page=security" <?= $page==='security'?'aria-current="page"':'' ?>>Security</a><?php endif ?>
</nav>
<dialog id="mobile-menu" class="mobile-menu" aria-labelledby="mobile-menu-title">
 <div class="mobile-menu-heading"><div><p class="eyebrow">YOUR WORKSPACE</p><h2 id="mobile-menu-title">Where would you like to go?</h2></div><button type="button" class="icon-button" data-menu-close aria-label="Close navigation menu"><?= navIcon('close') ?></button></div>
 <div class="mobile-menu-user"><strong><?= e($user['full_name']) ?></strong><span><?= e(ucwords(str_replace('_',' ',$user['role']))) ?></span></div>
 <nav aria-label="All workspace pages">
 <?php foreach($mobileItems as $target=>[$icon,$label]): ?><a href="?page=<?= $target ?>" <?= $page===$target?'aria-current="page"':'' ?>><?= navIcon($icon) ?><span><?= e($target==='remittances'?'Offerings & payments':($target==='hierarchy'?'Church directory':'Dashboard')) ?></span><span aria-hidden="true">→</span></a><?php endforeach ?>
 <?php if(canRemit($user)): ?><a href="?page=profile" <?= $page==='profile'?'aria-current="page"':'' ?>><?= navIcon('profile') ?><span>My church account</span><span aria-hidden="true">→</span></a><?php endif ?>
 <?php if($admin): ?><a href="?page=representatives"><?= navIcon('profile') ?><span>Church representatives</span><span aria-hidden="true">→</span></a><a href="?page=settings" <?= $page==='settings'?'aria-current="page"':'' ?>><?= navIcon('settings') ?><span>Portal settings</span><span aria-hidden="true">→</span></a><a href="?page=security" <?= $page==='security'?'aria-current="page"':'' ?>><?= navIcon('security') ?><span>Security centre</span><span aria-hidden="true">→</span></a><?php endif ?>
 </nav>
 <form method="post"><?php hiddenAction('logout') ?><button type="submit" class="secondary menu-signout">Sign out securely</button></form>
</dialog>
<nav class="mobile-fallback-nav" aria-label="Additional mobile pages"><?php if($admin): ?><a href="?page=representatives">Church reps</a><a href="?page=settings">Portal settings</a><a href="?page=security">Security centre</a><?php elseif(canRemit($user)): ?><a href="?page=profile">My church account</a><?php endif ?></nav>
