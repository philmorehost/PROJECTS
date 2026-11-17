<?php
declare(strict_types=1);
remittingUnit($db,$user);
if(!$portalReady){echo '<div class="notice">Your administrator must enable the church portal schema first.</div>';return;}
$s=$db->prepare('SELECT * FROM officer_profiles WHERE user_id=?');$s->execute([$user['id']]);$profile=$s->fetch() ?: [];
$account=empty($profile['account_ciphertext'])?'':ChurchPortal::decrypt($profile['account_ciphertext']);
?>
<section class="panel form-panel"><div class="section-kicker">YOUR CHURCH ACCOUNT</div><h2>Keep your account details up to date.</h2><p class="muted">Your church account details. Account numbers are encrypted.</p><form method="post" class="form-grid"><?php hiddenAction('profile') ?><label>Contact phone<input name="phone" value="<?= e($profile['phone'] ?? '') ?>" required maxlength="25" type="tel"></label><label>Bank name<input name="bank_name" value="<?= e($profile['bank_name'] ?? '') ?>" required maxlength="100"></label><label>Account name<input name="account_name" value="<?= e($profile['account_name'] ?? '') ?>" required maxlength="150"></label><label>Account number<input name="account_number" value="<?= e($account) ?>" required pattern="[0-9]{10}" maxlength="10" inputmode="numeric" autocomplete="off"></label><button class="primary">Save church account details</button></form><p class="muted small">Never submit your banking PIN, BVN, card number or banking password. Transaction PINs are stored as one-way hashes and cannot be viewed in plain text.</p></section>
