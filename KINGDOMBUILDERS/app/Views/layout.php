<?php
use function KB\{e,url,asset,current_title,initial};
use KB\Auth; use KB\Security;
$auth=KB\installed()?Auth::user():null;
$isAdmin=($auth['role']??'')==='admin' && empty($_SESSION['admin_id']);
$current=route();
// Text size: an individual choice (cookie) wins; otherwise the administrator's site-wide setting applies.
$textChoice=in_array($_COOKIE['kb_text']??'',['large','xlarge'],true)?$_COOKIE['kb_text']:'';
$textClass=$textChoice!==''?'text-'.$textChoice:((KB\installed() && KB\Settings::get('large_text'))?'text-large':'');
?>
<!doctype html>
<html lang="en"<?= $textClass!=='' ? ' class="'.e($textClass).'"' : '' ?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= e(Security::csrf()) ?>">
<meta name="theme-color" content="#142d3a"><meta name="description" content="A welcoming home for Bible learning, thoughtful quizzes and spiritual growth.">
<title><?= e($title ?? current_title()) ?> · <?= e(current_title()) ?></title>
<link rel="icon" href="<?= e(url('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<?php if($current==='quiz'): ?><script src="<?= e(asset('quiz.js')) ?>" defer></script><?php endif; ?>
<?php if(str_starts_with($current,'admin') || str_starts_with($current,'admin/')): ?><script src="<?= e(asset('admin.js')) ?>" defer></script><?php endif; ?>
</head>
<body class="<?= str_starts_with($current,'admin')?'admin-body':($current===''?'home-body':'') ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php if(!empty($_SESSION['admin_id'])): ?><div class="support-bar"><span>Support session · <?= e($auth['name']??'Member') ?> · Changes are recorded</span><form method="post" action="<?= e(url('support/return')) ?>"><?= Security::csrfField() ?><button>Return to administrator</button></form></div><?php endif; ?>
<header class="site-header"><div class="shell nav-wrap">
<a class="brand" href="<?= e(url()) ?>"><span class="brand-mark" aria-hidden="true"><?= e(initial()) ?><i></i></span><span><?= e(current_title()) ?><small>FAITH · KNOWLEDGE · GROWTH</small></span></a>
<nav aria-label="Main navigation">
<?php if($auth): ?>
<a href="<?= e(url($isAdmin?'admin':'dashboard')) ?>">Dashboard</a>
<a href="<?= e(url('account')) ?>">Account</a>
<form method="post" action="<?= e(url('logout')) ?>"><?= Security::csrfField() ?><button class="nav-button">Sign out</button></form>
<?php elseif($current!=='install'): ?><a href="<?= e(url('login')) ?>">Sign in</a><a class="button small" href="<?= e(url('register')) ?>">Join the community <span aria-hidden="true">↗</span></a><?php else: ?><span class="nav-note">A new beginning starts here.</span><?php endif; ?>
</nav></div></header>
<?php if($isAdmin && $current!=='admin/authorization'): ?><div class="admin-nav"><div class="shell"><?php foreach(['admin'=>'Overview','admin/questions'=>'Question bank','admin/users'=>'Members','admin/results'=>'Results','admin/settings'=>'Settings','admin/smtp'=>'Email & SMTP','admin/cron'=>'Cron job','admin/notifications'=>'Notifications','admin/audit'=>'Audit trail'] as $r=>$label): ?><a href="<?= e(url($r)) ?>" <?= $current===$r?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?></div></div><?php endif; ?>
<main id="main" class="shell main-content">
<?php foreach($flashes ?? [] as [$message,$type]): ?><div class="notice <?= e($type) ?>" role="status"><?= e($message) ?></div><?php endforeach; ?>
<?php require __DIR__.'/'.$view.'.php'; ?>
</main>
<footer class="site-footer"><div class="shell">
<span>© <?= date('Y') ?> <?= e(current_title()) ?><?= $isAdmin ? ' · Build ' . e(KB\VERSION) : '' ?></span>
<span>Rooted in the Word. Growing together.</span>
<form class="text-size-form" method="post" action="<?= e(url('text-size')) ?>" aria-label="Text size">
  <?= Security::csrfField() ?>
  <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
  <span>Text size</span>
  <button name="size" value="standard" class="<?= $textChoice===''?'is-active':'' ?>" aria-pressed="<?= $textChoice===''?'true':'false' ?>" title="Standard text">A</button>
  <button name="size" value="large" class="<?= $textChoice==='large'?'is-active':'' ?>" aria-pressed="<?= $textChoice==='large'?'true':'false' ?>" title="Larger text">A+</button>
  <button name="size" value="xlarge" class="<?= $textChoice==='xlarge'?'is-active':'' ?>" aria-pressed="<?= $textChoice==='xlarge'?'true':'false' ?>" title="Largest text">A++</button>
</form>
<a href="<?= e(url('login')) ?>">Member access</a>
</div></footer>
</body></html>
