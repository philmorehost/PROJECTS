<?php use function KB\{e,url}; use KB\Security; ?>
<section class="message-panel"><span class="success-seal"><?= $verified?'✓':'↗' ?></span><h1><?= $verified?'Your email is confirmed.':'Let’s confirm your email.' ?></h1>
<?php if($verified): ?><p>Your account is ready for its next step. Sign in to begin your learning journey.</p><a class="button" href="<?= e(url('login')) ?>">Continue to sign in →</a>
<?php elseif($valid): ?><p>Confirm that you own this email address. This link can only be used once.</p><form method="post" action="<?= e(url('verify-email',['token'=>$token])) ?>"><?= Security::csrfField() ?><button class="button">Confirm my email →</button></form>
<?php else: ?><p>This confirmation link is invalid, expired or already used. Sign in to request a fresh link from your dashboard.</p><a class="button" href="<?= e(url('login')) ?>">Continue to sign in →</a><?php endif; ?></section>
