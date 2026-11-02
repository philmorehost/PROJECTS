<?php
declare(strict_types=1);
namespace KB;

final class Auth {
    private static ?array $user = null;
    private static bool $loaded = false;
    public static function id(): ?int { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
    public static function user(): ?array {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        if (!self::id()) return self::$user = null;
        $user = DB::one('SELECT * FROM users WHERE id=?', [self::id()]);
        if (!$user || $user['status'] !== 'active' || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? -1) || (int)($_SESSION['last_seen'] ?? 0) < time() - 3600 || (int)($_SESSION['created'] ?? 0) < time() - 28800) {
            self::clear(); return self::$user = null;
        }
        $_SESSION['last_seen'] = time();
        return self::$user = $user;
    }
    public static function admin(): bool { return (self::user()['role'] ?? '') === 'admin'; }
    public static function requireUser(): array {
        $user = self::user();
        if (!$user) { flash('Please sign in to continue.', 'info'); redirect('login'); }
        return $user;
    }
    public static function requireAdmin(): array {
        $user = self::requireUser();
        if ($user['role'] !== 'admin') throw new UserError('Administrator access is required.', 403);
        if (!empty($_SESSION['admin_id'])) throw new UserError('Return to your administrator session before making administrative changes.', 403);
        return $user;
    }
    public static function signIn(array $user, bool $rotate = true): void {
        if ($rotate) session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['session_version'] = (int)$user['session_version'];
        $_SESSION['created'] = time(); $_SESSION['last_seen'] = time();
        unset($_SESSION['admin_id']);
        self::$user = $user; self::$loaded = true;
        DB::run('UPDATE users SET last_login_at=? WHERE id=?', [now(),$user['id']]);
    }
    public static function clear(): void {
        unset($_SESSION['user_id'],$_SESSION['session_version'],$_SESSION['admin_id'],$_SESSION['created'],$_SESSION['last_seen']);
        self::$user = null; self::$loaded = true;
    }
    public static function logout(): void {
        self::clear(); session_regenerate_id(true); $_SESSION = [];
    }
    public static function revoke(int $id): void { DB::run('UPDATE users SET session_version=session_version+1,updated_at=? WHERE id=?', [now(),$id]); }
    public static function issueToken(int $userId, string $purpose): string {
        $token = bin2hex(random_bytes(32));
        DB::transaction(static function () use ($userId,$purpose,$token): void {
            DB::run('UPDATE access_tokens SET used_at=? WHERE user_id=? AND purpose=? AND used_at IS NULL', [now(),$userId,$purpose]);
            DB::run('INSERT INTO access_tokens(user_id,purpose,token_hash,expires_at,created_at) VALUES (?,?,?,?,?)', [$userId,$purpose,hash('sha256',$token),gmdate('Y-m-d H:i:s',time() + ($purpose === 'reset' ? 3600 : 86400)),now()]);
        });
        return $token;
    }
    public static function sendAccess(int $userId, string $purpose): void {
        $user = DB::one('SELECT * FROM users WHERE id=?', [$userId]);
        if (!$user) return;
        $token = self::issueToken($userId,$purpose);
        $path = $purpose === 'reset' ? 'reset-password' : 'verify-email';
        $link = rtrim((string)config('site_url'), '/') . url($path, ['token'=>$token]);
        $action = $purpose === 'reset' ? 'Choose a new password' : 'Confirm your email';
        Mail::queue($user['email'], Settings::get('site_title') . ' · ' . $action,
            "Hello {$user['name']},\n\nUse this single-use link to continue:\n{$link}\n\nThis link expires in " . ($purpose === 'reset' ? '1 hour' : '24 hours') . ". If you did not request it, you can ignore this message.\n\nNever share this link with anyone.",
            $purpose . ':' . $userId . ':' . hash('sha256',$token));
    }
    public static function impersonate(int $target): void {
        $admin = self::requireAdmin();
        $user = DB::one('SELECT * FROM users WHERE id=?', [$target]);
        if (!$user || $user['role'] !== 'member' || $user['status'] !== 'active') throw new UserError('Choose an active member account.');
        Audit::activity('support.started','user',$target,['impersonation'=>true],$user,'An administrator opened a support session for your account. Administrative changes are disabled during support sessions.');
        $_SESSION['admin_id'] = $admin['id'];
        self::signIn($user);
        $_SESSION['admin_id'] = $admin['id'];
    }
    public static function returnToAdmin(): void {
        $id = (int)($_SESSION['admin_id'] ?? 0);
        if (!$id) throw new UserError('No support session is active.');
        $admin = DB::one('SELECT * FROM users WHERE id=? AND role="admin" AND status="active"', [$id]);
        if (!$admin || (int)$admin['session_version'] !== (int)($_SESSION['admin_session_version'] ?? -1)) {
            // The original version is separately retained by the route before switching accounts.
            throw new UserError('The administrator session has expired. Sign in again.');
        }
        Audit::log('support.ended','user',self::id(),[],$id);
        self::signIn($admin);
    }
}
