<?php
declare(strict_types=1);
$requirements=['PHP 8.2+'=>PHP_VERSION_ID>=80200,'64-bit PHP'=>PHP_INT_SIZE>=8];
foreach(['pdo','pdo_mysql','curl','gd','json','openssl'] as $ext) $requirements[$ext]=extension_loaded($ext);
$requirements['Writable config directory']=is_writable(ROOT.'/config');
$licence = new LicenseManager(ROOT);
$host = $_SERVER['HTTP_HOST'] ?? '';
$step=(int)($_SESSION['install_step'] ?? 1); $error='';
if ($step > 1 && (!$licence->credentials() || empty($_SESSION['licence_setup_verified']))) {
 $step = 1; $_SESSION['install_step'] = 1;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
 $handle=fopen(ROOT.'/config/setup.mutex','c');
 try {
  if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('Cannot lock installer.');
  if (is_file(ROOT.'/config/installed.lock')) redirect('login');
  checkCsrf();
  if ($step===1) {
   if (in_array(false,$requirements,true)) throw new RuntimeException('Resolve all environment checks first.');
   $licence->activate((string)($_POST['license_key'] ?? ''), $host);
   $_SESSION['licence_setup_verified'] = true;
   $_SESSION['install_step']=2;
  } elseif ($step===2) {
   if (!$licence->check($host)['valid']) throw new RuntimeException('Licence validation is required. Return to stage 1.');
   if (is_file(ROOT.'/config/database.php')) throw new RuntimeException('Database configuration already exists. See recovery instructions in README; automatic destructive retries are disabled.');
   $config=['host'=>trim($_POST['host'] ?? ''),'name'=>trim($_POST['name'] ?? ''),'user'=>trim($_POST['db_user'] ?? ''),'password'=>$_POST['db_password'] ?? ''];
   $db=Database::connect($config);
   if ($db->query('SHOW TABLES')->fetchColumn()) throw new RuntimeException('Use an empty database dedicated to REMIT.');
   foreach(explode(';',file_get_contents(ROOT.'/database/schema.sql')) as $sql) if(trim($sql)!=='') $db->exec($sql);
   ChurchPortal::migrate($db);PaymentProof::migrate($db);PasswordReset::migrate($db);
   $path=ROOT.'/config/database.php';
   if (file_put_contents($path,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX)===false) throw new RuntimeException('Cannot save configuration.');
   chmod($path,0600); $_SESSION['install_step']=3;
  } elseif ($step===3) {
   $email=strtolower(trim($_POST['email'] ?? '')); $password=$_POST['password'] ?? ''; $pin=$_POST['pin'] ?? ''; $name=trim($_POST['full_name'] ?? ''); $title=trim($_POST['site_title'] ?? '');
   if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>150 || strlen($password)<12 || strlen($password)>72 || !preg_match('/^\d{4}$/D',$pin) || $name==='' || strlen($name)>100 || $title==='' || strlen($title)>150) throw new RuntimeException('Provide valid details, a 12–72 byte password and a four-digit PIN.');
   $db=Database::get(); $db->beginTransaction();
   if ($db->query('SELECT COUNT(*) FROM users')->fetchColumn()) throw new RuntimeException('An administrator already exists.');
   $db->prepare("INSERT INTO users(full_name,email,password_hash,role,transaction_pin_hash) VALUES(?,?,?,'provincial_admin',?)")->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),password_hash($pin,PASSWORD_DEFAULT)]);
   $s=$db->prepare('UPDATE security_settings SET setting_value=? WHERE setting_key=?'); $s->execute([$title,'site_title']); $s->execute([$email,'notification_email']);
   $db->commit(); $_SESSION['install_step']=4;
  } elseif ($step===4) {
   if (!is_file(ROOT.'/config/database.php') || !(int)Database::get()->query('SELECT COUNT(*) FROM users')->fetchColumn()) throw new RuntimeException('Setup is incomplete.');
   if (!$licence->check($host, true)['valid']) throw new RuntimeException('Licence is no longer active.');
   if (file_put_contents(ROOT.'/config/installed.lock',gmdate('c'),LOCK_EX)===false) throw new RuntimeException('Cannot write installation lock.');
   chmod(ROOT.'/config/installed.lock',0600); @unlink(ROOT.'/config/install-token'); $_SESSION=[]; session_regenerate_id(true); redirect('login');
  }
  header('Location: index.php',true,303); exit;
 } catch(Throwable $e) {
  if(isset($db) && $db->inTransaction()) $db->rollBack();
  error_log('REMIT setup: '.$e->getMessage()); $error=$e instanceof PDOException ? 'Database setup failed. Check server logs and recovery instructions.' : $e->getMessage();
 } finally { if($handle) {flock($handle,LOCK_UN);fclose($handle);} }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Set up REMIT</title><link rel="stylesheet" href="public/assets/app.f99713ab.css"><?php require __DIR__.'/pwa-head.php'; ?></head><body class="setup"><main class="setup-card"><a class="brand" href="index.php"><span class="brand-icon">R</span> remit<span class="brand-dot">.</span></a><p class="eyebrow">RCCG YOUTH PROVINCE • FIRST-TIME SETUP</p><h1>Your treasury, connected.</h1><p class="muted">A secure foundation for faithful stewardship.</p><div class="steps"><?php foreach(['Environment','Database','Administrator','Complete'] as $i=>$label): ?><span class="<?= $step===$i+1?'active':'' ?>"><?= $i+1 ?>. <?= $label ?></span><?php endforeach ?></div><?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><?php if($step===1): ?><h2>01 / Environment check</h2><ul class="checks"><?php foreach($requirements as $label=>$ok): ?><li><span><?= e($label) ?></span><b class="<?= $ok?'green':'amber' ?>"><?= $ok?'Ready':'Action required' ?></b></li><?php endforeach ?></ul><div class="notice">Enter the licence key from License Manager. It will be validated securely against this website’s domain. No command-line setup token is required.</div><label>Website domain<input value="<?= e(LicenseManager::domain($host)) ?>" readonly aria-label="Detected licence domain"></label><label>Licence key<input name="license_key" type="password" required maxlength="255" autocomplete="off"></label><p class="muted small">Manage your key and authorized domains at manager.pmhserver.name.ng. The key stays on the server and is never included in browser scripts.</p><?php elseif($step===2): ?><h2>02 / Connect your database</h2><p class="muted">Create an empty MySQL 8+ or MariaDB 10.6+ database in cPanel first. Existing tables will never be overwritten.</p><label>Database host<input name="host" value="localhost" required></label><label>Database name<input name="name" required></label><label>Database username<input name="db_user" required></label><label>Database password<input name="db_password" type="password" autocomplete="new-password"></label><?php elseif($step===3): ?><h2>03 / Provincial administrator</h2><label>Province / site title<input name="site_title" value="RCCG Youth Province" required maxlength="150"></label><label>Full name<input name="full_name" required maxlength="100"></label><label>Email & notification address<input name="email" type="email" required maxlength="150"></label><label>Password<input name="password" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></label><label>Four-digit transaction PIN<input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" required autocomplete="new-password"></label><?php else: ?><h2>04 / Ready for your province</h2><p>Your administrator and database are ready. Finish to lock the installer.</p><div class="notice">Before public access: enable HTTPS, confirm private-directory access is denied, configure backups and OPcache, and review the deployment checklist. Gateway, email delivery and firewall adapters still require deployment work. Licence validation is connected to License Manager.</div><?php endif ?><button class="primary" type="submit"><?= $step===4?'Lock installer & sign in':'Continue →' ?></button></form><p class="small muted">REMIT / Provincial treasury platform</p></main><?php require __DIR__.'/public-navigation.php'; ?></body></html>
