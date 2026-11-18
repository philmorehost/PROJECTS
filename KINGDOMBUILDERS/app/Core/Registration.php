<?php
declare(strict_types=1);
namespace KB;

/**
 * Public self-registration, confirmed by an emailed six-digit code.
 *
 * The account is created only after the code has been verified, so an
 * automated form filler cannot add rows to `users` at all. Until then the
 * pending details live in `one_time_codes` for ten minutes, with the password
 * sealed (encrypted) rather than stored in clear or hashed for every bot.
 */
final class Registration {
    private const SESSION_KEY = 'otp_register';
    private const GENERIC = 'If this address can be registered, we have emailed a six-digit code. Check your inbox and spam folder.';

    /** The message shown when a submission must not reveal whether an account exists. */
    public static function genericNotice(): string { return self::GENERIC; }

    /**
     * Start a registration: validate nothing twice, store the pending details
     * and email the code. An existing address is never revealed.
     *
     * @return array{pending:bool,ok:bool,message:string}
     */
    public static function begin(string $name, string $email, string $password): array {
        $email = strtolower(trim($email));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !valid_email($email) || !valid_password($password)) {
            throw new UserError('Check your name, email address and password.');
        }
        if (!Settings::get('registration_open')) throw new UserError('Registration is currently closed.', 403);
        if (DB::one('SELECT id FROM users WHERE email=?', [$email])) {
            self::notifyExisting($email);
            return ['pending'=>false,'ok'=>true,'message'=>self::GENERIC];
        }
        // One address may not be used to start a pending sign-up more than a few
        // times an hour: that would let a bot use the site to mail-bomb someone.
        Security::throttle('register.pending', hash('sha256', $email), 3, 3600);
        $issued = Otp::issue('register', $email, null, [
            'name' => $name,
            'secret' => Security::seal(['password'=>$password]),
        ]);
        if (!$issued['created']) return ['pending'=>false,'ok'=>false,'message'=>$issued['message']];
        Otp::remember(self::SESSION_KEY, [
            'selector' => $issued['selector'], 'purpose' => 'register',
            'email' => $email, 'expires' => $issued['expires'],
        ]);
        return ['pending'=>true,'ok'=>$issued['ok'],'message'=>$issued['message']];
    }

    /** @return array<string,mixed>|null */
    public static function pending(): ?array { return Otp::pending(self::SESSION_KEY, 'register'); }

    public static function resend(): array {
        $pending = self::pending();
        if (!$pending) throw new UserError('Your registration session has expired. Enter your details again.');
        $issued = Otp::resend((string) $pending['selector'], 'register');
        Otp::remember(self::SESSION_KEY, array_merge($pending, ['expires'=>$issued['expires']]));
        return $issued;
    }

    /**
     * Verify the emailed code and create the account. Returns the new user row.
     *
     * @return array<string,mixed>
     */
    public static function complete(string $code): array {
        $pending = self::pending();
        if (!$pending) throw new UserError('Your registration session has expired. Enter your details again.');
        if (!Settings::get('registration_open')) throw new UserError('Registration has just been closed. Contact the administrator.', 403);
        $row = Otp::verify((string) $pending['selector'], 'register', $code);
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) throw new UserError('That registration could not be completed. Please start again.');
        $name = trim((string) ($payload['name'] ?? ''));
        try { $secret = Security::unseal((string) ($payload['secret'] ?? '')); }
        catch (\Throwable $e) { throw new UserError('That registration could not be completed. Please start again.'); }
        $password = (string) ($secret['password'] ?? '');
        $email = strtolower(trim((string) $row['email']));
        if ($name === '' || !valid_email($email) || !valid_password($password)) {
            throw new UserError('That registration could not be completed. Please start again.');
        }
        try {
            $id = DB::transaction(static function () use ($name, $email, $password): int {
                $existing = DB::one('SELECT id FROM users WHERE email=? FOR UPDATE', [$email]);
                if ($existing) throw new UserError('An account with this address already exists. Sign in instead, or use “Forgot your password”.');
                DB::run('INSERT INTO users(name,email,password_hash,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,?,?)', [
                    $name, $email, hash_password($password), now(), now(), now(),
                ]);
                $id = DB::id();
                Audit::activity('account.registered', 'user', $id, ['verified'=>'email_code'], DB::one('SELECT * FROM users WHERE id=?', [$id]),
                    'Welcome! Your email address is confirmed and your account is ready.');
                return $id;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') throw new UserError('An account with this address already exists. Sign in instead.');
            throw $e;
        }
        Otp::forget(self::SESSION_KEY);
        return (array) DB::one('SELECT * FROM users WHERE id=?', [$id]);
    }

    /**
     * Counters for the administrator's registration watch panel.
     *
     * @return array<string,mixed>
     */
    public static function watch(): array {
        $day = gmdate('Y-m-d H:i:s', time() - 86400);
        $week = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        try {
            return self::watchQuery($day, $week);
        } catch (\Throwable $e) {
            // A missing table (upgrade not applied yet) must not break the page.
            error_log('KB registration watch: ' . get_class($e));
            return ['new_24'=>0,'new_7d'=>0,'unverified'=>0,'blocked_24'=>0,'pending'=>0,'sources'=>[]];
        }
    }

    /** @return array<string,mixed> */
    private static function watchQuery(string $day, string $week): array {
        return [
            'new_24' => (int) DB::value('SELECT COUNT(*) FROM users WHERE role="member" AND created_at>=?', [$day]),
            'new_7d' => (int) DB::value('SELECT COUNT(*) FROM users WHERE role="member" AND created_at>=?', [$week]),
            'unverified' => (int) DB::value('SELECT COUNT(*) FROM users WHERE role="member" AND email_verified_at IS NULL'),
            'blocked_24' => (int) DB::value('SELECT COUNT(*) FROM audit_events WHERE action LIKE "registration.%" AND created_at>=?', [$day]),
            'pending' => (int) DB::value('SELECT COUNT(*) FROM one_time_codes WHERE purpose="register" AND consumed_at IS NULL AND expires_at>?', [now()]),
            'sources' => DB::all('SELECT source_hash, COUNT(*) AS total, MAX(created_at) AS last_seen FROM audit_events WHERE action IN ("account.registered","registration.blocked") AND created_at>=? AND source_hash IS NOT NULL GROUP BY source_hash ORDER BY total DESC LIMIT 5', [$week]),
        ];
    }

    /** Explain a submission that arrived from something that is not a person. */
    public static function block(string $reason, array $metadata = []): void {
        try { Audit::log('registration.blocked', 'site', null, ['reason'=>$reason] + $metadata); }
        catch (\Throwable $e) { error_log('KB registration block: ' . get_class($e)); }
    }

    /** Remove member accounts that never confirmed their address (housekeeping). */
    public static function purgeUnverified(int $olderThanHours = 24, int $limit = 200): int {
        $rows = DB::all('SELECT id,email FROM users WHERE role="member" AND email_verified_at IS NULL AND created_at < ? ORDER BY id LIMIT ' . max(1, min(500, $limit)), [gmdate('Y-m-d H:i:s', time() - $olderThanHours * 3600)]);
        $removed = 0;
        foreach ($rows as $row) {
            try { DB::run('DELETE FROM users WHERE id=?', [(int) $row['id']]); $removed++; }
            catch (\Throwable $e) { error_log('KB purge unverified: ' . get_class($e)); }
        }
        return $removed;
    }

    /** Tell an existing address that a sign-up attempt was made, without leaking it to the form. */
    private static function notifyExisting(string $email): void {
        // Only once an hour per address, so the form cannot be used to flood an inbox.
        try { Security::throttle('register.exists', hash('sha256', $email), 1, 3600); }
        catch (UserError) { return; }
        try {
            $title = (string) Settings::get('site_title');
            Mail::queue($email, $title . ' · Sign-in help',
                "Hello,\n\nSomeone (hopefully you) tried to create an account with this email address, but an account already exists.\n\n"
                . "If it was you, sign in, or choose “Forgot your password” to set a new one:\n"
                . rtrim((string) config('site_url'), '/') . url('login') . "\n\n"
                . "If it was not you, no action is needed — nothing has changed on your account.\n\n" . $title,
                'register.exists:' . hash('sha256', $email) . ':' . gmdate('YmdH'));
        } catch (\Throwable $e) { error_log('KB register existing: ' . get_class($e)); }
    }
}
