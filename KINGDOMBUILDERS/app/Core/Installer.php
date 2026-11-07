<?php
declare(strict_types=1);
namespace KB;

final class Installer {
    public static function checks(): array {
        $checks = [['PHP 8.2 or newer (8.3+ recommended)',version_compare(PHP_VERSION,MIN_PHP,'>='),PHP_VERSION]];
        foreach (['pdo_mysql'=>'MySQL database driver','openssl'=>'Encryption and secure connections','mbstring'=>'Unicode site titles','curl'=>'Domain authorization','session'=>'Secure sign-in sessions','fileinfo'=>'Safe file handling','json'=>'Structured data'] as $extension=>$label) $checks[] = [$label,extension_loaded($extension),$extension];
        $dir = config_path();
        if (!is_dir($dir)) @mkdir($dir,0700,true);
        $checks[] = ['Private configuration directory',is_dir($dir) && is_writable($dir),$dir];
        $checks[] = ['Dedicated MySQL database',true,'Create an empty database before continuing'];
        return $checks;
    }
    public static function ready(): bool { foreach (self::checks() as $check) if (!$check[1]) return false; return true; }
    public static function state(): array { return $_SESSION['install'] ?? ['step'=>1]; }
    public static function saveState(array $state): void { $_SESSION['install'] = $state; }
    public static function handle(): never {
        $dir = config_path(); if (!is_dir($dir)) @mkdir($dir,0700,true);
        $lock = @fopen($dir."/setup.lock","c");
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new UserError("Another setup request is in progress. Please wait.",409);
        register_shutdown_function(static function () use ($lock): void { flock($lock,LOCK_UN); fclose($lock); });
        if (installed()) {
            if (!empty($_SESSION['install_complete'])) { unset($_SESSION['install_complete']); render('install/ready',['title'=>'Your community is ready']); }
            render('errors/message',['heading'=>'This page is unavailable','message'=>'Setup has already been completed. Sign in to continue.'],404);
        }
        $state = self::state();
        $step = (int)$state['step'];
        $error = null;
        if (is_post()) {
            try {
                Security::check();
                Security::throttle('install','global',30,900);
                if ((int)post('step') !== $step) throw new UserError('Please complete the current step.');
                if ($step === 1) {
                    if (!self::ready()) throw new UserError('Please resolve the server requirements before continuing.');
                    if (post('accept') !== '1') throw new UserError('Please read and accept the software terms.');
                    $siteUrl = Security::canonicalUrl(post('site_url'));
                    if (!$siteUrl) throw new UserError('Enter the full website address, starting with https://.');
                    if (!str_starts_with($siteUrl,'https://') && getenv('KB_LOCAL_PREVIEW')!=='1') throw new UserError('HTTPS is required before installing on a public website.');
                    $domain = parse_url($siteUrl,PHP_URL_HOST);
                    $key = post('activation_key');
                    if (!Activation::verify($key,$domain)) throw new UserError('This key is not active for this domain. Check the address and key, then try again.');
                    $state = ['step'=>2,'site_url'=>$siteUrl,'key'=>$key,'key_bytes'=>random_bytes(32)];
                } elseif ($step === 2) {
                    $db = ['host'=>post('db_host','localhost'),'port'=>(int)post('db_port','3306'),'name'=>post('db_name'),'user'=>post('db_user'),'password'=>password_value('db_password'),'socket'=>''];
                    if (!preg_match('/^[a-zA-Z0-9_.-]+$/',$db['host']) || !preg_match('/^[a-zA-Z0-9_]+$/',$db['name']) || $db['user']==='' || strlen($db['user'])>80 || $db['port']<1 || $db['port']>65535 || strlen($db['password'])>2048) throw new UserError('Check the database host, name, username and port.');
                    try { $pdo = DB::connect($db); }
                    catch (\PDOException $e) { throw new UserError('Could not connect. Check the credentials and confirm the database exists and allows this web server.'); }
                    $existing = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
                    $allowed = ['schema_migrations','users','settings','access_tokens','questions','attempts','attempt_items','audit_events','mail_queue','rate_limits'];
                    if ($existing && (array_diff($existing,$allowed) || !in_array('users',$existing,true))) throw new UserError('This database is not empty. Choose a dedicated empty database; existing tables will never be overwritten.');
                    if ($existing && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) throw new UserError('This database already contains an installation.');
                    {
                        $sql = (string)file_get_contents(ROOT.'/database/schema.sql');
                        foreach (array_filter(array_map('trim',explode(';',$sql))) as $statement) $pdo->exec($statement);
                        if ((int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() === 0) {
                        $seed = json_decode((string)file_get_contents(ROOT.'/database/seed.json'),true,512,JSON_THROW_ON_ERROR);
                        $insert = $pdo->prepare('INSERT INTO questions(category,question_text,option_a,option_b,option_c,option_d,correct_option,reference,explanation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                        foreach ($seed as $q) $insert->execute([$q['category'],$q['question'],...$q['options'],$q['correct'],$q['reference'],'',now(),now()]);
                        }
                    }
                    $state['db'] = $db; $state['step'] = 3;
                } elseif ($step === 3) {
                    $name = post('name'); $email = strtolower(post('email')); $password = password_value('password');
                    if (mb_strlen($name)<2 || mb_strlen($name)>120 || !valid_email($email) || !valid_password($password) || $password !== password_value('password_confirm')) throw new UserError('Enter your name, a valid email and matching passwords of 12–72 bytes.');
                    $config = ['key'=>base64_encode($state['key_bytes']),'db'=>$state['db'],'site_url'=>$state['site_url'],'base_path'=>base_path(),'installed_at'=>now(),'local_preview'=>getenv('KB_LOCAL_PREVIEW')==='1'];
                    Security::atomicWrite(config_path().'/site.php',"<?php\nreturn ".var_export($config,true).";\n");
                    \KB\config(null,null,true);
                    try {
                        Activation::save($state['key'],true);
                        DB::transaction(static function () use ($name,$email,$password): void {
                            $existingAdmin = DB::one('SELECT * FROM users WHERE role="admin" FOR UPDATE');
                            if ($existingAdmin) {
                                if ($existingAdmin['email'] !== $email || !password_verify($password,$existingAdmin['password_hash'])) throw new UserError('An administrator already exists. Use the original setup credentials to recover this installation.');
                                $id = (int)$existingAdmin['id'];
                            } else {
                                DB::run('INSERT INTO users(name,email,password_hash,role,status,email_verified_at,created_at,updated_at) VALUES (?,?,?,"admin","active",?,?,?)',[$name,$email,hash_password($password),now(),now(),now()]);
                                $id = DB::id();
                            }
                            foreach (Settings::DEFAULTS as $key=>$value) Settings::put($key,$value);
                            Settings::put('admin_email',$email); Settings::put('from_email',$email);
                            Audit::log('installation.completed','site',null,[],$id);
                            Mail::queue($email,'Welcome to '.current_title(),"Your administrator account is ready.\n\nSign in: ".config('site_url').url('login')."\n\nNext: configure SMTP, send a test message, review the question bank and schedule the background worker. Keep backups of the database AND private configuration directory.",'install:'.$id);
                        });
                        Security::atomicWrite(config_path().'/installed.lock',now());
                    } catch (\Throwable $e) { @unlink(config_path().'/site.php'); @unlink(config_path().'/runtime.dat'); @unlink(config_path().'/installed.lock'); throw $e; }
                    unset($_SESSION['install']); session_regenerate_id(true); $_SESSION['install_complete'] = true;
                    redirect('install');
                }
                self::saveState($state); redirect('install');
            } catch (UserError $e) { $error = $e->getMessage(); }
            catch (\Throwable $e) { error_log('KB installer: '.get_class($e)); $error = 'Setup could not be saved. Check private-directory permissions and database privileges, then retry.'; }
        }
        if ($step > 1 && empty($state['key_bytes'])) { $step = 1; $state = ['step'=>1]; }
        render('install/form',['step'=>$step,'state'=>$state,'checks'=>self::checks(),'error'=>$error,'title'=>'Set up your community']);
    }
}
