<?php
declare(strict_types=1);
namespace KB;

final class Admin {
    public static function handle(string $route): never {
        if ($route==='admin/authorization') {
            // Recovery stays reachable during an upstream outage, but requires an administrator.
            $user=Auth::user();
            if (!$user || $user['role']!=='admin' || !empty($_SESSION['admin_id'])) {
                if(is_post()) { Security::check(); self::loginForRecovery(); }
                render('admin/authorization-login',['error'=>null]);
            }
            $error=null;
            if(is_post()) {
                Security::check();
                try { Security::throttle('authorization',Security::sourceHash(),8); $key=post('activation_key'); if(!Activation::verify($key,Security::domain())) throw new UserError('This key is not active for the configured domain.'); Activation::save($key,true); Audit::activity('site.authorization_updated','site',1); flash('Site authorization renewed.'); redirect('admin'); }
                catch(UserError $e) {$error=$e->getMessage();}
            }
            render('admin/authorization',['error'=>$error,'allowed'=>Activation::allowed()]);
        }
        $admin=Auth::requireAdmin();
        if($route==='admin/smtp/preview') {
            // A real rendering of the transactional template, using the signed-in
            // administrator's own address for the footer. Inline styles are the
            // design, so the preview route allows them while blocking everything else.
            $base=rtrim((string)config('site_url'),'/');
            $sample="Hello ".$admin['name'].",\n\nUse this single-use link to continue:\n".$base."/reset-password?token=PREVIEW-ONLY\n\nThis link expires in 1 hour. If you did not request it, you can ignore this message.\n\nNever share this link with anyone.";
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
            echo MailTemplate::html(Settings::get('site_title').' · Choose a new password',$sample,(string)$admin['email']);
            exit;
        }
        $error=null;
        if(is_post()) Security::check();
        if($route==='admin') {
            $stats=['members'=>DB::value('SELECT COUNT(*) FROM users WHERE role="member"'),'questions'=>DB::value('SELECT COUNT(*) FROM questions WHERE is_active=1'),'completed'=>DB::value('SELECT COUNT(*) FROM attempts WHERE status<>"active"'),'pending'=>DB::value('SELECT COUNT(*) FROM mail_queue WHERE status IN ("pending","failed")'),'active'=>DB::value('SELECT COUNT(*) FROM attempts WHERE status="active"'),'pass_rate'=>DB::value('SELECT COALESCE(ROUND(AVG(CASE WHEN percentage >= CAST(JSON_UNQUOTE(JSON_EXTRACT(settings_snapshot, "$.pass_mark")) AS UNSIGNED) THEN 100 ELSE 0 END)),0) FROM attempts WHERE status<>"active"')];
            $recent=DB::all('SELECT a.*,u.name FROM audit_events a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 8');
            $weekly=DB::all('SELECT DATE(submitted_at) AS day, COUNT(*) AS total FROM attempts WHERE status<>"active" AND submitted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 DAY) GROUP BY DATE(submitted_at) ORDER BY day');
            render('admin/dashboard',['stats'=>$stats,'recent'=>$recent,'admin'=>$admin,'smtp'=>Settings::get('smtp_enabled'),'weekly'=>$weekly]);
        }
        if($route==='admin/questions') {
            if(is_post()) {
                try {
                    $id=(int)post('id');
                    if(post('action')==='delete') {
                        if(!$id || !DB::one('SELECT id FROM questions WHERE id=?',[$id])) throw new UserError('Question not found.',404);
                        DB::transaction(static function () use($id): void { DB::run('DELETE FROM questions WHERE id=?',[$id]); Audit::activity('question.deleted','question',$id); });
                        flash('Question deleted. Existing attempts retain their original snapshots.');
                    } else {
                        $q=self::questionInput();
                        if($id) {
                            if(!DB::one('SELECT id FROM questions WHERE id=?',[$id])) throw new UserError('Question not found.',404);
                            DB::transaction(static function () use($id,$q,$admin): void {
                                DB::run('UPDATE questions SET category=?,question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,reference=?,explanation=?,is_active=?,updated_at=? WHERE id=?',[...array_values($q),now(),$id]);
                                Audit::activity('question.updated','question',$id);
                            });
                        } else {
                            DB::transaction(static function () use($q,$admin): void {
                                DB::run('INSERT INTO questions(category,question_text,option_a,option_b,option_c,option_d,correct_option,reference,explanation,is_active,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[...array_values($q),$admin['id'],now(),now()]);
                                Audit::activity('question.created','question',DB::id());
                            });
                        }
                        flash('Question saved.');
                    }
                    redirect('admin/questions');
                } catch(UserError $e) {$error=$e->getMessage();}
            }
            $search=mb_substr(query('q'),0,100); $where=$search!==''?' WHERE question_text LIKE ? OR category LIKE ?':''; $params=$search!==''?['%'.$search.'%','%'.$search.'%']:[];
            [$page,$pages,$offset]=paginate((int)DB::value('SELECT COUNT(*) FROM questions'.$where,$params));
            $questions=DB::all('SELECT * FROM questions'.$where.' ORDER BY id DESC LIMIT 20 OFFSET '.$offset,$params);
            $edit=query('edit')?DB::one('SELECT * FROM questions WHERE id=?',[(int)query('edit')]):null;
            render('admin/questions',['questions'=>$questions,'edit'=>$edit,'search'=>$search,'page'=>$page,'pages'=>$pages,'error'=>$error]);
        }
        if($route==='admin/users') {
            if(is_post()) {
                try { DB::transaction(static fn()=>self::mutateUser($admin)); redirect('admin/users'); }
                catch(UserError $e) {$error=$e->getMessage();}
            }
            $search=mb_substr(query('q'),0,100); $where=$search!==''?' WHERE name LIKE ? OR email LIKE ?':''; $params=$search!==''?['%'.$search.'%','%'.$search.'%']:[];
            [$page,$pages,$offset]=paginate((int)DB::value('SELECT COUNT(*) FROM users'.$where,$params));
            $users=DB::all('SELECT id,name,email,role,status,email_verified_at,created_at,last_login_at FROM users'.$where.' ORDER BY id DESC LIMIT 20 OFFSET '.$offset,$params);
            $edit=query('edit')?DB::one('SELECT id,name,email,role,status,email_verified_at FROM users WHERE id=?',[(int)query('edit')]):null;
            render('admin/users',['users'=>$users,'edit'=>$edit,'search'=>$search,'page'=>$page,'pages'=>$pages,'error'=>$error]);
        }
        if($route==='admin/impersonate') {
            if(!is_post()) throw new UserError('Method not allowed.',405);
            $_SESSION['admin_session_version']=$_SESSION['session_version'];
            Auth::impersonate((int)post('id')); flash('Support session started. Administrative actions are disabled.','info'); redirect('dashboard');
        }
        if($route==='admin/results' || $route==='admin/results/export') {
            if($route==='admin/results/export') {
                header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="kingdombuilders-results.csv"');
                $out=fopen('php://output','w'); fputcsv($out,['Attempt','Member','Email','Score','Total','Percent','Status','Started UTC','Submitted UTC','Focus changes'],escape:'');
                $stmt=DB::run('SELECT a.*,u.name,u.email FROM attempts a JOIN users u ON u.id=a.user_id WHERE a.status<>"active" ORDER BY a.started_at DESC');
                $rows=0;
                while($row=$stmt->fetch()) {
                    $values=[$row['id'],$row['name'],$row['email'],$row['score'],$row['total_questions'],$row['percentage'],$row['status'],$row['started_at'],$row['submitted_at'],$row['tab_switches']];
                    fputcsv($out,array_map(static fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v,$values),escape:'');
                    if(++$rows%500===0) flush();
                }
                fclose($out); Audit::log('results.exported','site',null,['rows'=>$rows]); exit;
            }
            [$page,$pages,$offset]=paginate((int)DB::value('SELECT COUNT(*) FROM attempts WHERE status<>"active"'));
            $results=DB::all('SELECT a.*,u.name,u.email FROM attempts a JOIN users u ON u.id=a.user_id WHERE a.status<>"active" ORDER BY a.started_at DESC LIMIT 20 OFFSET '.$offset);
            render('admin/results',['results'=>$results,'page'=>$page,'pages'=>$pages]);
        }
        if($route==='admin/cron') {
            if(is_post()) {
                try {
                    Security::check();
                    $path=trim((string)post('php_cli_path'));
                    $values=Settings::validate(array_merge(Settings::all(),['php_cli_path'=>$path]),false);
                    Settings::put('php_cli_path',$values['php_cli_path']);
                    Audit::activity('settings.cron_path_updated','site',1);
                    flash('PHP path saved. Copy the command again to use it.');
                } catch(UserError $e) { $error=$e->getMessage(); }
                redirect('admin/cron');
            }
            render('admin/cron',['cron'=>Cron::info(),'heartbeat'=>Cron::heartbeat(),'error'=>null]);
        }
        if($route==='admin/settings' || $route==='admin/smtp') {
            $smtp=$route==='admin/smtp';
            $test=null; $saved=false;
            if(is_post()) {
                $action=post('action');
                try {
                    $input=$_POST;
                    if($smtp) { $input=array_merge(Settings::all(),$_POST); $input['smtp_enabled']=isset($_POST['smtp_enabled']); }
                    else { $input=array_merge(Settings::all(),$_POST); foreach(['registration_open','quiz_open','timer_enabled','randomize_questions','randomize_answers','allow_back','practice_feedback','review_questions','one_attempt','large_text','google_enabled'] as $key) $input[$key]=isset($_POST[$key]); }
                    $values=Settings::validate($input,$smtp);
                    if($smtp) {
                        try { $values['smtp_secret']=Settings::smtpPassword($input); }
                        catch(\Throwable $sealError) { error_log('KB SMTP seal: '.get_class($sealError)); throw new UserError('The SMTP password could not be encrypted on this server. Ask your host to enable the PHP OpenSSL extension, then try again.'); }
                        $values['php_cli_path']=trim((string)($input['php_cli_path']??''));
                    } else {
                        try { $values['google_client_secret']=Settings::googleSecret($input); }
                        catch(\Throwable $sealError) { error_log('KB Google seal: '.get_class($sealError)); throw new UserError('The Google client secret could not be encrypted on this server. Ask your host to enable the PHP OpenSSL extension, then try again.'); }
                        if (!empty($values['google_enabled']) && $values['google_client_secret']==='') throw new UserError('Paste the Google client secret before turning on Google sign-in.');
                    }
                    if(!$smtp || $action!=='test') {
                        try {
                            DB::transaction(static function () use($values,$smtp): void { foreach($values as $key=>$value) Settings::put($key,$value); Audit::activity($smtp?'settings.smtp_updated':'settings.updated','site',1); });
                        } catch(\Throwable $saveError) {
                            error_log('KB settings save: '.get_class($saveError));
                            throw new UserError('The settings could not be written to the database. Check the server error log, then try again.');
                        }
                        $saved=true;
                        flash($smtp?'Email settings saved. Send a test message, then make sure the scheduled task is running.':'Settings saved. New settings apply to new assessments.');
                        if(!$smtp) redirect('admin/settings');
                        if($action!=='save_test') redirect('admin/smtp');
                    }
                    if(in_array($action,['test','save_test'],true)) {
                        Security::throttle('smtp.test',Security::sourceHash(),10);
                        Settings::preview($values);
                        try {
                            $result=Mail::sendTest($admin['email']);
                            $test=['ok'=>true,'message'=>'Delivery succeeded. A test message was accepted for '.$admin['email'].'.','id'=>$result['message_id']];
                            Audit::activity('settings.smtp_tested','site',1,['ok'=>true]);
                        } catch(MailError $e) {
                            $test=['ok'=>false,'message'=>$e->getMessage(),'stage'=>$e->stage,'code'=>$e->smtpCode];
                            Audit::activity('settings.smtp_tested','site',1,['ok'=>false,'stage'=>$e->stage]);
                        } catch(\Throwable $e) {
                            error_log('KB SMTP test: '.get_class($e));
                            $test=['ok'=>false,'message'=>'The test could not be completed. Check the server error log for the reference and try again.','stage'=>'internal','code'=>null];
                        }
                    }
                } catch(UserError $e) {$error=$e->getMessage();}
            }
            if(!$smtp) render('admin/settings',['settings'=>Settings::all(),'error'=>$error,'cron'=>Cron::info(),'heartbeat'=>Cron::heartbeat()]);
            render('admin/smtp',['settings'=>Settings::all(),'error'=>$error,'test'=>$test,'cron'=>Cron::info(),'heartbeat'=>Cron::heartbeat(),'saved'=>$saved]);
        }
        if($route==='admin/notifications') {
            $testError=null;
            if(is_post()) {
                $action=post('action');
                if($action==='test') {
                    try { Security::throttle('smtp.test',Security::sourceHash(),10); Mail::sendTest($admin['email']); flash('Test message accepted for '.$admin['email'].'. Check the inbox and spam folder.'); }
                    catch(MailError|UserError $e) { $testError=$e->getMessage(); Audit::activity('settings.smtp_tested','site',1,['ok'=>false]); }
                    catch(\Throwable $e) { error_log('KB SMTP test: '.get_class($e)); $testError='The test could not be completed. Check the server error log and try again.'; }
                }
                elseif($action==='deliver') {
                    Security::throttle('mail.deliver',Security::sourceHash(),20);
                    @set_time_limit(30);
                    $id=(int)post('id');
                    $result=Mail::deliver($id);
                    flash($result['message'],$result['ok']?'success':'error');
                    Audit::activity('notification.delivered_now','notification',$id,['ok'=>$result['ok']]);
                    if(!$result['ok']) { $testError=$result['message']; render('admin/notifications',['mails'=>DB::all('SELECT id,recipient,subject,status,deliveries,attempts,available_at,last_error,sent_at,created_at FROM mail_queue ORDER BY id DESC LIMIT 20'),'page'=>1,'pages'=>1,'cron'=>Cron::info(),'heartbeat'=>Cron::heartbeat(),'testError'=>$testError,'queued'=>(int)DB::value('SELECT COUNT(*) FROM mail_queue WHERE status IN ("pending","processing")')]); }
                }
                elseif($action==='retry') { $id=(int)post('id'); DB::transaction(static function () use($id): void { DB::run('UPDATE mail_queue SET status="pending",attempts=0,available_at=?,last_error=NULL,lease_token=NULL,leased_at=NULL WHERE id=? AND status="failed"',[now(),$id]); Audit::activity('notification.retried','notification',$id); }); flash('Notification queued for retry.'); }
                elseif($action==='process') {
                    Security::throttle('mail.process',Security::sourceHash(),6);
                    @set_time_limit(45);
                    $stats=Mail::process(5,time()+25);
                    $summary='Delivered '.$stats['sent'].'; unsuccessful '.$stats['failed'].'. '.$stats['pending'].' message'.($stats['pending']===1?'':'s').' still queued.';
                    flash($summary,$stats['failed']>0?'error':'success');
                }
                else throw new UserError('Unknown action.');
                if(!in_array($action,['test','deliver'],true) || !$testError) redirect('admin/notifications');
            }
            [$page,$pages,$offset]=paginate((int)DB::value('SELECT COUNT(*) FROM mail_queue'));
            $mails=DB::all('SELECT id,recipient,subject,status,deliveries,attempts,available_at,last_error,sent_at,created_at FROM mail_queue ORDER BY id DESC LIMIT 20 OFFSET '.$offset);
            $queued=(int)DB::value('SELECT COUNT(*) FROM mail_queue WHERE status IN ("pending","processing")');
            render('admin/notifications',['mails'=>$mails,'page'=>$page,'pages'=>$pages,'cron'=>Cron::info(),'heartbeat'=>Cron::heartbeat(),'testError'=>$testError,'queued'=>$queued]);
        }
        if($route==='admin/audit') {
            [$page,$pages,$offset]=paginate((int)DB::value('SELECT COUNT(*) FROM audit_events'));
            $events=DB::all('SELECT a.*,u.name FROM audit_events a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 30 OFFSET '.$offset);
            render('admin/audit',['events'=>$events,'page'=>$page,'pages'=>$pages]);
        }
        throw new UserError('Page not found.',404);
    }
    private static function loginForRecovery(): void {
        try {
            $email=strtolower(post('email')); Security::throttle('recovery',Security::sourceHash(),10); Security::throttle('recovery.account',$email,5);
            $user=DB::one('SELECT * FROM users WHERE email=? AND role="admin" AND status="active"',[$email]);
            if(!$user || !password_verify(password_value('password'),$user['password_hash'])) throw new UserError('Invalid administrator credentials.');
            Auth::signIn($user); Audit::log('account.signed_in','user',$user['id']); redirect('admin/authorization');
        } catch(UserError $e) { render('admin/authorization-login',['error'=>$e->getMessage()]); }
    }
    private static function questionInput(): array {
        $options=[post('option_a'),post('option_b'),post('option_c'),post('option_d')];
        $q=post('question_text'); $category=post('category'); $correct=(int)post('correct_option', '-1');
        if(mb_strlen($q)<8 || mb_strlen($q)>5000 || mb_strlen($category)<1 || mb_strlen($category)>80 || $correct<0 || $correct>3 || count(array_unique($options))!==4) throw new UserError('Enter a question, category and four distinct options.');
        foreach($options as $option) if($option==='' || mb_strlen($option)>1000) throw new UserError('Each option must contain 1–1,000 characters.');
        if(mb_strlen(post('reference'))>160 || mb_strlen(post('explanation'))>5000) throw new UserError('Reference or explanation is too long.');
        return ['category'=>$category,'question_text'=>$q,'option_a'=>$options[0],'option_b'=>$options[1],'option_c'=>$options[2],'option_d'=>$options[3],'correct_option'=>$correct,'reference'=>post('reference'),'explanation'=>post('explanation'),'is_active'=>post('is_active')==='1'?1:0];
    }
    private static function mutateUser(array $admin): void {
        $id=(int)post('id'); $action=post('action');
        // Serialize administrator mutations so concurrent requests cannot remove the last administrator.
        DB::all('SELECT id FROM users WHERE role="admin" ORDER BY id FOR UPDATE');
        $target=$id?DB::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]):null;
        if($id && !$target) throw new UserError('Account not found.',404);
        if($target && in_array($action,['delete','suspend','role'],true)) {
            if($id===(int)$admin['id']) throw new UserError('You cannot remove or restrict your own administrator access.');
            if($target['role']==='admin' && DB::value('SELECT COUNT(*) FROM users WHERE role="admin" AND status="active"')<=1) throw new UserError('Keep at least one active administrator.');
        }
        if($action==='delete') {
            DB::transaction(static function () use($id,$target): void { DB::run('DELETE FROM users WHERE id=?',[$id]); Audit::activity('account.deleted','user',$id,['email_hash'=>hash('sha256',$target['email'])],null,'An administrator deleted an account. Historical activity remains without the account association.'); Mail::queue($target['email'],Settings::get('site_title').' · Account removed','Your account has been removed. Contact your administrator if you need assistance.','deleted:'.$id); });
            flash('Account deleted.'); return;
        }
        if($action==='suspend' || $action==='unsuspend') {
            $status=$action==='suspend'?'suspended':'active';
            DB::transaction(static function () use($id,$status,$target): void { DB::run('UPDATE users SET status=?,session_version=session_version+1,updated_at=? WHERE id=?',[$status,now(),$id]); Audit::activity('account.'.$status,'user',$id,[],$target,$status==='suspended'?'Your account has been suspended. Contact your administrator for assistance.':'Your account has been reactivated. You can sign in again.'); });
            flash('Account '.$status.'.'); return;
        }
        if($action==='reset') { Auth::sendAccess($id,'reset'); Audit::activity('account.reset_sent','user',$id,[],$target,'An administrator requested a secure password reset for your account.'); flash('Password reset queued.'); return; }
        $name=post('name'); $email=strtolower(post('email')); $role=post('role','member'); $status=post('status','active');
        if(mb_strlen($name)<2 || mb_strlen($name)>120 || !valid_email($email) || !in_array($role,['admin','member'],true) || !in_array($status,['active','suspended'],true)) throw new UserError('Check the account fields.');
        if($target && (int)$admin['id']===$id && ($role!=='admin' || $status!=='active')) throw new UserError('You cannot remove your own administrator access.');
        if($target && $target['role']==='admin' && ($role!=='admin' || $status!=='active') && DB::value('SELECT COUNT(*) FROM users WHERE role="admin" AND status="active"')<=1) throw new UserError('Keep at least one active administrator.');
        $password=password_value('password');
        if(!$target && !valid_password($password)) throw new UserError('New accounts need a password of 12–72 bytes. Ask the member to change it after signing in.');
        if($password!=='' && !valid_password($password)) throw new UserError('Passwords must contain 12–72 bytes.');
        if(DB::one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id])) throw new UserError('This email address is already in use.');
        DB::transaction(static function () use($target,$id,$name,$email,$role,$status,$password,$admin): void {
            if($target) {
                $verified=$email===$target['email']?$target['email_verified_at']:null;
                DB::run('UPDATE users SET name=?,email=?,role=?,status=?,email_verified_at=?,session_version=session_version+1,updated_at=? WHERE id=?',[$name,$email,$role,$status,$verified,now(),$id]);
                if($password!=='') DB::run('UPDATE users SET password_hash=? WHERE id=?',[hash_password($password),$id]);
                $user=DB::one('SELECT * FROM users WHERE id=?',[$id]);
                Audit::activity('account.updated','user',$id,['role'=>$role,'status'=>$status],$user,'Your account details or access permissions were updated.');
                if(!$verified) Auth::sendAccess($id,'verify');
            } else {
                DB::run('INSERT INTO users(name,email,password_hash,role,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)',[$name,$email,hash_password($password),$role,$status,$admin['id'],now(),now()]);
                $id=DB::id(); $user=DB::one('SELECT * FROM users WHERE id=?',[$id]);
                Audit::activity('account.created','user',$id,['role'=>$role],$user,'An administrator created your account. Sign in and change your temporary password in Account settings.');
                Auth::sendAccess($id,'verify');
            }
        });
        flash('Account saved.');
    }
}
