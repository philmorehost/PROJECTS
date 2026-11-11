<?php
declare(strict_types=1);
$error='';$submitted=false;$unavailable='Registration is closed. Please contact the provincial administrator.';$ready=ChurchPortal::officerRegistrationReady($db);$enabled=$ready && setting($db,'registration_enabled')==='1';
if(!$ready)$unavailable='The administrator needs to upgrade officer registration in Portal settings before applications can be submitted.';
try { $state=(new LicenseManager(ROOT))->check($_SERVER['HTTP_HOST'] ?? '');$enabled=$enabled && $state['valid'];if(!$state['valid'])$unavailable='The portal licence needs administrator attention before registration can open.'; } catch(RuntimeException){$enabled=false;$unavailable='Registration is temporarily unavailable. Please ask the administrator to check the portal licence.';}
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  checkCsrf();if(!$enabled)throw new RuntimeException('Registration is currently unavailable. Contact the provincial administrator.');
  if(($_POST['consent'] ?? '')!=='1')throw new InvalidArgumentException('Please confirm that you are authorized to represent this church.');
  ChurchPortal::register($db,$_POST);$submitted=true;
  try{ApprovalNotifications::dispatch($db);}catch(Throwable $mailError){error_log('REMIT approval notification remains queued; check delivery worker.');}
 }catch(PDOException $ex){error_log('REMIT registration database error.');$error='Your registration could not be submitted. Please try again or contact the provincial administrator.';}
 catch(RuntimeException|InvalidArgumentException $ex){$error=$ex->getMessage();}
}
$churches=$enabled && !$submitted?ChurchPortal::directory($db):[];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Register your officer account · REMIT</title><link rel="stylesheet" href="public/assets/app.0d7c55de.css"><?php require __DIR__.'/pwa-head.php'; ?><script src="public/assets/app.b8cc7b49.js" defer></script></head><body class="setup registration-page"><main class="setup-card registration-card"><a class="brand" href="index.php"><span class="brand-icon">R</span>remit<span class="brand-dot">.</span></a><p class="eyebrow">YOUR CHURCH. ONE CONNECTED PROVINCE.</p><h1>Create your officer account.</h1><p class="muted">Find your Parish, Area or Zone, add your officer details, and request provincial approval. No bank details are needed to register.</p><div class="steps"><span class="active">01 · Choose your church</span><span>02 · Add your details</span><span>03 · Admin approval</span></div>
<?php if($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if($submitted): ?><div class="success-state" role="status"><span>✓</span><h2>Application received</h2><p>If these details are eligible, your application is now awaiting review. Your administrator will verify your church affiliation before enabling access. You cannot sign in until approval.</p><a class="primary" href="?page=login">Back to sign in →</a></div>
<?php elseif(!$enabled): ?><div class="notice"><?= e($unavailable) ?></div><a class="text-link" href="?page=login">← Back to sign in</a>
<?php else: require __DIR__.'/registration-form.php'; ?><?php endif ?></main><aside class="registration-aside"><p class="eyebrow">FAITHFUL STEWARDSHIP, SIMPLIFIED</p><h2>More clarity.<br>Less paperwork.<br>One purpose.</h2><p>Report Youth Thanksgiving offerings, see your exact allocation and keep every remittance in one place.</p><div class="aside-check">✓ &nbsp; Automatic offering calculation</div><div class="aside-check">✓ &nbsp; Track payment confirmations</div><div class="aside-check">✓ &nbsp; Admin-approved officer access</div></aside><?php require __DIR__.'/public-navigation.php'; ?></body></html>
