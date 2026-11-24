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
    /** Google sign-in: start the redirect, or handle Google's callback. */
    public static function google(string $step): never {
        if (!installed()) redirect('install');
        if (!Activation::allowed()) redirect('admin/authorization');
        if ($step === 'start') {
            if (Auth::user()) redirect(Auth::admin() && empty($_SESSION['admin_id']) ? 'admin' : 'dashboard');
            try { redirect_external(Google::begin(query('from'))); }
            catch (UserError $e) { flash($e->getMessage(), 'error'); redirect('login'); }
        }
        if (query('error') !== '') {
            $reason = query('error') === 'access_denied' ? 'Google sign-in was cancelled.' : 'Google could not complete the sign-in. Please try again.';
            flash($reason, 'info'); redirect('login');
        }
        try {
            $profile = Google::complete(query('code'), query('state'));
            $user = Google::accountFor($profile);
            Auth::signIn($user);
            Audit::activity('account.signed_in_google', 'user', $user['id'], ['provider' => 'google'], $user, 'A sign-in with your Google account was recorded.');
            flash('Signed in with Google.', 'success');
            redirect($user['role'] === 'admin' ? 'admin' : 'dashboard');
        } catch (UserError $e) {
            flash($e->getMessage(), 'error');
            redirect('login');
        }
    }
    public static function auth(string $route): never {
        if (in_array($route,['login','login/verify','register','register/verify','forgot-password','reset-password','verify-email'],true) && Auth::user() && !in_array($route,['reset-password','verify-email'],true)) redirect('dashboard');
        // A half-finished sign-in stays reachable even though no session exists yet.
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
                    // A second factor for everyone signing in from an unfamiliar device.
                    if (Otp::loginEnabled() && !Otp::deviceTrusted((int)$user['id'],(int)$user['session_version'])) {
                        $issued=Otp::issue('login',(string)$user['email'],(int)$user['id']);
                        if (!$issued['created']) throw new UserError('Your password is correct, but a sign-in code cannot be sent. '.$issued['message'],503);
                        Otp::remember(Otp::LOGIN_SESSION,['selector'=>$issued['selector'],'purpose'=>'login','email'=>$user['email'],'uid'=>(int)$user['id'],'expires'=>$issued['expires']]);
                        Audit::log('account.sign_in_challenge','user',$user['id'],['method'=>'email_code']);
                        flash($issued['message'],$issued['ok']?'info':'error');
                        redirect('login/verify');
                    }
                    Auth::signIn($user);
                    Audit::activity('account.signed_in','user',$user['id'],[],$user,'A sign-in to your account was recorded.');
                    redirect($user['role']==='admin'?'admin':'dashboard');
                } catch(UserError $e) { $error=$e->getMessage(); }
            }
            render('auth/login',['error'=>$error]);
        }
        if ($route==='login/verify') {
            $pending=Otp::pending(Otp::LOGIN_SESSION,'login');
            if (!$pending) { flash('Sign in again to continue.','info'); redirect('login'); }
            if (is_post()) {
                try {
                    if (post('action')==='resend') {
                        $issued=Otp::resend((string)$pending['selector'],'login');
                        Otp::remember(Otp::LOGIN_SESSION,array_merge($pending,['expires'=>$issued['expires']]));
                        flash($issued['message'],'info'); redirect('login/verify');
                    }
                    Security::throttle('otp.verify',Security::sourceHash(),15,900);
                    $row=Otp::verify((string)$pending['selector'],'login',post('code'));
                    $user=DB::one('SELECT * FROM users WHERE id=?',[(int)$row['user_id']]);
                    if(!$user || $user['status']!=='active' || strtolower((string)$user['email'])!==strtolower((string)$pending['email'])) throw new UserError('This sign-in can no longer be completed. Sign in again.',403);
                    Otp::forget(Otp::LOGIN_SESSION);
                    Auth::signIn($user);
                    if(post('remember')==='1') Otp::trustDevice((int)$user['id'],(int)$user['session_version']);
                    Audit::activity('account.signed_in','user',$user['id'],['method'=>'email_code'],$user,'A sign-in to your account was recorded.');
                    redirect($user['role']==='admin'?'admin':'dashboard');
                } catch(UserError $e) { $error=$e->getMessage(); }
            }
            render('auth/login-verify',['email'=>$pending['email'],'error'=>$error]);
        }
        if ($route==='register') {
            if (!Settings::get('registration_open')) throw new UserError('Registration is currently closed.',403);
            $status=200;
            if (is_post()) {
                try {
                    self::guardRegistration();
                    $name=post('name'); $email=strtolower(post('email')); $password=password_value('password');
                    if (mb_strlen($name)<2 || mb_strlen($name)>120 || !valid_email($email) || !valid_password($password) || $password!==password_value('password_confirm') || post('accept')!=='1') throw new UserError('Check your name, email, acceptance of the terms, and matching passwords of 12–72 bytes.');
                    $result=Registration::begin($name,$email,$password);
                    // No account exists yet: the emailed code creates it, so a form
                    // filler cannot add rows to the members table.
                    if ($result['pending']) {
                        flash($result['message'], $result['ok'] ? 'info' : 'error');
                        redirect('register/verify');
                    }
                    if (!$result['ok']) throw new UserError($result['message']);
                    $notice=$result['message'];
                } catch(UserError $e) {
                    $error=$e->getMessage();
                    // A blocked submission keeps its real status for monitoring and logs.
                    if (in_array($e->status,[400,429],true)) $status=$e->status;
                }
            }
            render('auth/register',['error'=>$error,'notice'=>$notice],$status);
        }
        if ($route==='register/verify') {
            $pending=Registration::pending();
            if (!$pending) { flash('Enter your details to start again.','info'); redirect('register'); }
            if (is_post()) {
                try {
                    if (post('action')==='resend') {
                        $issued=Registration::resend();
                        flash($issued['message'],'info'); redirect('register/verify');
                    }
                    $user=Registration::complete(post('code'));
                    Auth::signIn($user);
                    Audit::activity('account.signed_in','user',$user['id'],['method'=>'email_code'],$user,'A sign-in to your account was recorded.');
                    flash('Your email is confirmed and your account is ready. Welcome!','success');
                    redirect('dashboard');
                } catch(UserError $e) { $error=$e->getMessage(); }
            }
            render('auth/register-verify',['email'=>$pending['email'],'error'=>$error]);
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
    /**
     * Cheap, layered checks that stop automated form filling before any work is
     * done (and before an email is sent). Each layer is independent: the hidden
     * field catches naive bots, the timing seal catches instant submissions,
     * reCAPTCHA catches the rest, and the hourly cap limits a distributed run.
     */
    private static function guardRegistration(): void {
        if (post('website') !== '') { Registration::block('honeypot'); throw new UserError('We could not complete that request. Reload the page and try again.',400); }
        try { $seal=Security::unseal(post('form_seal')); }
        catch(\Throwable $e) { $seal=[]; }
        $started=(int)($seal['t'] ?? 0);
        if ($started<1 || time()-$started<1 || time()-$started>7200) {
            Registration::block('timing');
            throw new UserError('Please reload the page, check your details, and submit the form again.',400);
        }
        Captcha::verify('register');
        $cap=(int)Settings::get('registration_cap');
        if ($cap>0) {
            try { Security::throttle('register.global','site',$cap,3600); }
            catch(UserError $e) {
                Audit::log('registration.blocked','site',null,['reason'=>'hourly_cap','cap'=>$cap]);
                throw new UserError('Sign-up is paused for a short while because of unusual activity. Please try again later, or contact the administrator.',429);
            }
        }
        Security::throttle('register',Security::sourceHash(),5,3600);
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
        // One assessment per member: a finished attempt replaces the "start" button.
        $taken=$active?null:DB::one('SELECT id,submitted_at FROM attempts WHERE user_id=? AND status<>"active" ORDER BY submitted_at DESC LIMIT 1',[$user['id']]);
        $history=DB::all('SELECT id,score,total_questions,percentage,started_at,submitted_at,status,settings_snapshot FROM attempts WHERE user_id=? AND status<>"active" ORDER BY started_at DESC LIMIT 10',[$user['id']]);
        $aggregate=DB::one('SELECT COUNT(*) AS completed, MAX(percentage) AS best, AVG(percentage) AS average FROM attempts WHERE user_id=? AND status<>"active"',[$user['id']]);
        $memberStats=['completed'=>(int)($aggregate['completed']??0),'best'=>$aggregate['best']!==null?round((float)$aggregate['best'],1):null,'average'=>$aggregate['average']!==null?round((float)$aggregate['average'],1):null];
        render('member/dashboard',['user'=>$user,'active'=>$active,'taken'=>$taken,'history'=>$history,'memberStats'=>$memberStats,'count'=>DB::value('SELECT COUNT(*) FROM questions WHERE is_active=1')]);
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
        if ($route==='quiz') {
            $attempt=Quiz::owned($id,(int)$user['id']);
            // The quiz page is only for a running attempt. A finished one goes to
            // its result, so reloading the old link can never reopen the questions.
            if ($attempt['status']==='active') render('member/quiz',['attempt'=>Quiz::payload($id,(int)$user['id']),'user'=>$user]);
            if ($attempt['status']==='submitted') { flash('This assessment has been submitted. Each member may take it once.','info'); redirect_query('result',['id'=>$id]); }
            flash('Your time ran out, so the assessment was submitted automatically.','info');
            redirect_query('result',['id'=>$id]);
        }
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
            // Closing or leaving the quiz page ends the attempt: the saved answers
            // are scored and the one allowed attempt is used.
            if($action==='leave') json_response(Quiz::leave($id,(int)$user['id']));
            json_response(['error'=>'Unknown action.'],400);
        }
        if($route==='result') {
            $attempt=Quiz::owned($id,(int)$user['id']);
            if($attempt['status']==='active') redirect_query('quiz',['id'=>$id]);
            // Question content is only read when the administrator has allowed review;
            // otherwise the page is built from score aggregates alone.
            $review=Quiz::reviewEnabled();
            $items=$review?DB::all('SELECT question_text,options_snapshot,correct_option,answer,reference,explanation FROM attempt_items WHERE attempt_id=? ORDER BY position',[$id]):[];
            $summary=DB::one('SELECT COUNT(*) AS total, COALESCE(SUM(answer IS NOT NULL AND is_skipped=0),0) AS answered, COALESCE(SUM(is_skipped=1),0) AS skipped FROM attempt_items WHERE attempt_id=?',[$id]);
            render('member/result',['attempt'=>$attempt,'items'=>$items,'review'=>$review,'summary'=>$summary,'user'=>$user]);
        }
        throw new UserError('Page not found.',404);
    }
}
