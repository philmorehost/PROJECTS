<?php declare(strict_types=1); require_once __DIR__.'/navigation-icons.php'; $publicPage=isset($step)?'setup':(($page ?? '')==='register'?'register':'login'); ?>
<nav class="public-bottom-nav" aria-label="Public footer navigation">
<a href="?page=login" aria-label="Sign in" title="Sign in" <?= $publicPage==='login'?'aria-current="page"':'' ?>><?= navIcon('home') ?><span>Sign in</span></a>
<a href="?page=register" aria-label="Register officer account" title="Register officer account" <?= $publicPage==='register'?'aria-current="page"':'' ?>><?= navIcon('profile') ?><span>Register</span></a>
</nav>
