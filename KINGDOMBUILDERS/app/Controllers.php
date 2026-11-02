<?php
declare(strict_types=1);
namespace KB;

final class Controllers {
    public static function home(): never {
        if (!installed()) redirect('install');
        if (!Activation::allowed() && !str_starts_with(route(), 'admin/authorization')) redirect('admin/authorization');
        if (Auth::user()) redirect(Auth::admin() && empty($_SESSION['admin_id']) ? 'admin' : 'dashboard');
        $homeStats = [
            'questions' => (int)DB::value('SELECT COUNT(*) FROM questions WHERE is_active=1'),
            'members' => (int)DB::value('SELECT COUNT(*) FROM users WHERE role="member" AND status="active"'),
            'completed' => (int)DB::value('SELECT COUNT(*) FROM attempts WHERE status<>"active"'),
        ];
        render('home',['homeStats'=>$homeStats]);
    }
    public static function auth(string $route): never {
        if (in_array($route,['login','register','forgot-password','reset-password','verify-email'],true) && Auth::user() && !in_array($route,['reset-password','verify-email'],true)) redirect('dashboard');
        $error=null; $notice=null; $token=query('token');
        if (is_post()) Security::check();
        if ($route==='login') {
            if (is_post()) {
                try {
                    $email=strtolower(post('email')); $password=password_value('password');
                    Security::throttle('login.ip',Security::sourceHash(),40);
                    Security::throttle('login.account',$email,10);
                    $user=DB::one('SELECT * FROM users WHERE email=?',[$email]);
                    $hash=$user['password_hash'] ?? '$2y$12$uses9mS99K1RXdQJnAeIhOjFjI2Jl3SxWVFBGrK6cM9KJfWl9S1O';
                    $ok=password_verify($password,$hash);
                    if (!$user || !$ok || $user['status']!=='active') throw new UserError('The email or password is incorrect, or this account is unavailable.',401);
                    if (password_needs_rehash($hash,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_BCRYPT,defined('PASSWORD_ARGON2ID')?[]:['cost'=>12])) DB::run('UPDATE users SET password_hash=? WHERE id=?',[hash_password($password),$user['id']]);
                    Auth::signIn($user);
                    Audit::activity('account.signed_in','user',$user['id'],[],$user,'A sign-in to your account was recorded.');
                    redirect($user['role']==='admin'?'admin':'dashboard');
                } catch(UserError $e) { $error=$e->getMessage(); }
            }
            render('auth/login',['error'=>$error]);
        }
        if ($route==='register') {
            if (!Settings::get('registration_open')) throw new UserError('Registration is currently closed.',403);
            if (is_post()) {
                try {
                    Security::throttle('register',Security::sourceHash(),5,3600);
                    $name=post('name'); $email=strtolower(post('email')); $password=password_value('password');
                    if (mb_strlen($name)<2 || mb_strlen($name)>120 || !valid_email($email) || !valid_password($password) || $password!==password_value('password_confirm') || post('accept')!=='1') throw new UserError('Check your name, email, acceptance of the terms, and matching passwords of 12–72 bytes.');
                    $existing=DB::one('SELECT id,email_verified_at FROM users WHERE email=?',[$email]);
                    if (!$existing) {
                        try {
                            DB::transaction(static function () use ($name,$email,$password): void {
                                DB::run('INSERT INTO users(name,email,password_hash,created_at,updated_at) VALUES (?,?,?,?,?)',[$name,$email,hash_password($password),now(),now()]);
                                $id=DB::id(); Audit::activity('account.registered','user',$id,[],DB::one('SELECT * FROM users WHERE id=?',[$id]),'Welcome! Confirm your email to begin learning.');
                                Auth::sendAccess($id,'verify');
                            });
                        } catch(\PDOException $e) { if ($e->getCode()!=='23000') throw $e; }
                    } elseif (!$existing['email_verified_at']) Auth::sendAccess((int)$existing['id'],'verify');
                    $notice='If this address can be registered, a confirmation message has been queued. Check your inbox and spam folder.';
                } catch(UserError $e) { $error=$e->getMessage(); }
            }
            render('auth/register',['error'=>$error,'notice'=>$notice]);
        }
        if ($route==='forgot-password') {
            if (is_post()) {
                try {
                    $email=strtolower(post('email'));
                    if (!valid_email($email)) throw new UserError('Enter a valid email address.');
                    Security::throttle('reset.ip',Security::sourceHash(),8);
                    Security::throttle('reset.account',$email,4);
                    $user=DB::one('SELECT id,status FROM users WHERE email=?',[$email]);
                    if ($user && $user['status']==='active') {
                        DB::transaction(static function () use ($user): void { Auth::sendAccess((int)$user['id'],'reset'); Audit::log('account.reset_requested','user',$user['id'],[],(int)$user['id']); });
                    }
                    $notice='If an active account matches this address, a reset link has been queued. It expires in one hour.';
                } catch(UserError $e) { $error=$e->getMessage(); }
            }
            render('auth/forgot',['error'=>$error,'notice'=>$notice]);
        }
        if ($route==='reset-password') {
            $record=self::tokenRecord($token,'reset');
            if (is_post()) {
                try {
                    $password=password_value('password');
                    if (!$record || !valid_password($password) || $password!==password_value('password_confirm')) throw new UserError('Use matching passwords of 12–72 bytes. If the link expired, request a new one.');
                    DB::transaction(static function () use ($record,$password): void {
                        $claim=DB::run('UPDATE access_tokens SET used_at=? WHERE id=? AND used_at IS NULL AND expires_at>?',[now(),$record['id'],now()]);
                        if ($claim->rowCount()!==1) throw new UserError('This link has already been used or has expired.');
                        DB::run('UPDATE users SET password_hash=?,session_version=session_version+1,email_verified_at=COALESCE(email_verified_at,?),updated_at=? WHERE id=? AND status="active"',[hash_password($password),now(),now(),$record['user_id']]);
                        DB::run('UPDATE access_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL',[now(),$record['user_id']]);
                        $user=DB::one('SELECT * FROM users WHERE id=?',[$record['user_id']]);
                        Audit::activity('account.password_changed','user',$user['id'],[],$user,'Your password was changed. Other sessions have been signed out.');
                    });
                    Auth::logout(); flash('Your password has been changed. Sign in with your new password.'); redirect('login');
                } catch(UserError $e) { $error=$e->getMessage(); }
            }
            render('auth/reset',['token'=>$token,'valid'=>$record!==null,'error'=>$error]);
        }
        if ($route==='verify-email') {
            $verified=false; $record=self::tokenRecord($token,'verify');
            if (is_post() && $record) {
                try {
                    DB::transaction(static function () use ($record,&$verified): void {
                        if (DB::run('UPDATE access_tokens SET used_at=? WHERE id=? AND used_at IS NULL AND expires_at>?',[now(),$record['id'],now()])->rowCount()!==1) return;
                        DB::run('UPDATE users SET email_verified_at=COALESCE(email_verified_at,?),updated_at=? WHERE id=?',[now(),now(),$record['user_id']]);
                        Audit::activity('account.email_verified','user',$record['user_id'],[],DB::one('SELECT * FROM users WHERE id=?',[$record['user_id']]),'Your email address has been confirmed.');
                        $verified=true;
                    });
                } catch(\Throwable $e) { error_log('Verification failed: '.get_class($e)); }
            }
            render('auth/verify',['verified'=>$verified,'valid'=>$record!==null,'token'=>$token]);
        }
        if ($route==='logout') {
            Security::check();
            if (is_post()) { if(Auth::id()) Audit::log('account.signed_out','user',Auth::id()); Auth::logout(); }
            redirect('login');
        }
        throw new UserError('Page not found.',404);
    }
    private static function tokenRecord(string $token,string $purpose): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/',$token)) return null;
        return DB::one('SELECT t.* FROM access_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.purpose=? AND t.used_at IS NULL AND t.expires_at>? AND u.status="active"',[hash('sha256',$token),$purpose,now()]);
    }
    public static function dashboard(): never {
        $user=Auth::requireUser();
        if ($user['role']==='admin' && empty($_SESSION['admin_id'])) redirect('admin');
        if (!$user['email_verified_at']) {
            if(is_post()) { Security::check(); Security::throttle('verify',Security::sourceHash(),5); Auth::sendAccess((int)$user['id'],'verify'); flash('A new confirmation link has been queued.','info'); redirect('dashboard'); }
            render('member/verify-required');
        }
        $active=DB::one('SELECT id,started_at FROM attempts WHERE active_user_id=?',[$user['id']]);
        $history=DB::all('SELECT id,score,total_questions,percentage,started_at,submitted_at,status,settings_snapshot FROM attempts WHERE user_id=? AND status<>"active" ORDER BY started_at DESC LIMIT 10',[$user['id']]);
        $aggregate=DB::one('SELECT COUNT(*) AS completed, MAX(percentage) AS best, AVG(percentage) AS average FROM attempts WHERE user_id=? AND status<>"active"',[$user['id']]);
        $memberStats=['completed'=>(int)($aggregate['completed']??0),'best'=>$aggregate['best']!==null?round((float)$aggregate['best'],1):null,'average'=>$aggregate['average']!==null?round((float)$aggregate['average'],1):null];
        render('member/dashboard',['user'=>$user,'active'=>$active,'history'=>$history,'memberStats'=>$memberStats,'count'=>DB::value('SELECT COUNT(*) FROM questions WHERE is_active=1')]);
    }
    public static function account(): never {
        $user=Auth::requireUser(); $error=null;
        if(is_post()) {
            Security::check();
            try {
                if(post('action')==='profile') {
                    $name=post('name'); if(mb_strlen($name)<2 || mb_strlen($name)>120) throw new UserError('Enter a name between 2 and 120 characters.');
                    DB::run('UPDATE users SET name=?,updated_at=? WHERE id=?',[$name,now(),$user['id']]);
                    Audit::activity('account.profile_updated','user',$user['id']); flash('Profile saved.');
                } else {
                    Security::throttle('password.change',Security::sourceHash(),5);
                    $password=password_value('password');
                    if(!password_verify(password_value('current_password'),$user['password_hash']) || !valid_password($password) || $password!==password_value('password_confirm')) throw new UserError('Check your current password and use matching new passwords of 12–72 bytes.');
                    DB::transaction(static function () use ($user,$password): void {
                        DB::run('UPDATE users SET password_hash=?,session_version=session_version+1,updated_at=? WHERE id=?',[hash_password($password),now(),$user['id']]);
                        Audit::activity('account.password_changed','user',$user['id']);
                    });
                    Auth::logout(); flash('Password changed. Please sign in again.'); redirect('login');
                }
                redirect('account');
            } catch(UserError $e) {$error=$e->getMessage();}
        }
        render('member/account',['user'=>$user,'error'=>$error]);
    }
    public static function quiz(string $route): never {
        $user=Auth::requireUser();
        if (!$user['email_verified_at'] || !empty($_SESSION['admin_id'])) throw new UserError('Use a verified member account to take an assessment.',403);
        if ($route==='quiz/start') {
            Security::check(); if(!is_post()) throw new UserError('Method not allowed.',405);
            Security::throttle('quiz.start',(string)$user['id'],20,3600);
            $id=Quiz::start((int)$user['id']); redirect_query('quiz',['id'=>$id]);
        }
        $id=query('id');
        if ($route==='quiz') render('member/quiz',['attempt'=>Quiz::payload($id,(int)$user['id']),'user'=>$user]);
        if ($route==='api/quiz') {
            header('Content-Type: application/json; charset=utf-8');
            $data=json_decode((string)file_get_contents('php://input',false,null,0,65537),true);
            if(!is_array($data)) json_response(['error'=>'Invalid JSON request.'],400);
            Security::check();
            $action=$data['action'] ?? '';
            if($action==='state') json_response(Quiz::payload($id,(int)$user['id']));
            Security::throttle('quiz.answer',(string)$user['id'],240,60);
            if($action==='answer') json_response(Quiz::answer($id,(int)$user['id'],(int)($data['position']??-1),(int)($data['choice']??-2)));
            if($action==='flag') json_response(Quiz::flag($id,(int)$user['id']));
            if($action==='finish') json_response(Quiz::finish($id,(int)$user['id']));
            json_response(['error'=>'Unknown action.'],400);
        }
        if($route==='result') {
            $attempt=Quiz::owned($id,(int)$user['id']);
            if($attempt['status']==='active') redirect_query('quiz',['id'=>$id]);
            $items=DB::all('SELECT question_text,options_snapshot,correct_option,answer,reference,explanation FROM attempt_items WHERE attempt_id=? ORDER BY position',[$id]);
            render('member/result',['attempt'=>$attempt,'items'=>$items,'user'=>$user]);
        }
        throw new UserError('Page not found.',404);
    }
}
