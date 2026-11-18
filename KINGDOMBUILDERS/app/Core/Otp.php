<?php
declare(strict_types=1);
namespace KB;

/**
 * Short-lived, single-use email codes for sign-up confirmation and sign-in
 * from a new device.
 *
 * Only a keyed hash of the code is stored. The code is emailed once, expires
 * after ten minutes, tolerates six wrong guesses and can be re-sent three
 * times. A pending challenge is identified by a random selector kept in the
 * session, so the code itself never appears in a URL, a log or the database.
 */
final class Otp {
    /** How long a code stays usable. */
    public const TTL = 600;
    /** Session key for a sign-in challenge. */
    public const LOGIN_SESSION = 'otp_login';
    private const MAX_ATTEMPTS = 6;
    private const MAX_SENDS = 4;
    private const RESEND_COOLDOWN = 60;
    private const DEVICE_COOKIE = 'kb_device';
    private const DEVICE_DAYS = 30;

    public static function registrationEnabled(): bool { return (bool) Settings::get('otp_register'); }
    public static function loginEnabled(): bool { return (bool) Settings::get('otp_login'); }

    /**
     * Create a code, queue its email and try to deliver it straight away.
     * A queued message also survives a slow or failing mail server, so the
     * background worker can still deliver it while the code is valid.
     *
     * @return array{ok:bool,selector:string,expires:int,message:string}
     */
    public static function issue(string $purpose, string $email, ?int $userId, array $payload = []): array {
        $purpose = in_array($purpose, ['register','login'], true) ? $purpose : 'register';
        $email = strtolower(trim($email));
        if (!valid_email($email)) throw new UserError('Enter a valid email address.');
        if (!Settings::get('smtp_enabled')) {
            return ['ok'=>false,'created'=>false,'selector'=>'','expires'=>0,
                'message'=>'Email delivery is switched off, so a confirmation code cannot be sent. Ask the administrator to configure email delivery first.'];
        }
        Security::throttle('otp.send', Security::sourceHash(), 12, 900);
        Security::throttle('otp.send.mail', $email, 5, 3600);
        $selector = bin2hex(random_bytes(16));
        $code = self::code();
        $expires = time() + self::TTL;
        DB::run('INSERT INTO one_time_codes(selector,purpose,email,user_id,payload,code_hash,expires_at,created_at,last_sent_at) VALUES (?,?,?,?,?,?,?,?,?)', [
            $selector, $purpose, $email, $userId,
            $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            self::hash($code, $selector), gmdate('Y-m-d H:i:s', $expires), now(), now(),
        ]);
        $delivery = self::email($selector, $purpose, $email, $code, 1);
        // The challenge exists even if the mail server is down: the person can
        // request another code without losing what they have typed, and the
        // queued message may still be delivered by the background worker.
        return ['ok'=>$delivery['ok'],'created'=>true,'selector'=>$selector,'expires'=>$expires,'message'=>$delivery['message']];
    }

    /**
     * Send a fresh code for an existing challenge. The selector and session
     * stay valid, so the person keeps the same screen.
     *
     * @return array{ok:bool,expires:int,message:string}
     */
    public static function resend(string $selector, string $purpose): array {
        $row = self::lookup($selector, $purpose);
        if (!$row) throw new UserError('This code has expired. Start again.');
        if ((int) $row['sent_count'] >= self::MAX_SENDS) throw new UserError('A code has already been sent several times. Please wait ten minutes, then start again.');
        $last = strtotime((string) $row['last_sent_at'] . ' UTC');
        if ($last !== false && time() - $last < self::RESEND_COOLDOWN) {
            throw new UserError('A code was just sent. Give it a minute to arrive before requesting another.');
        }
        Security::throttle('otp.resend', Security::sourceHash(), 8, 900);
        $code = self::code();
        $expires = time() + self::TTL;
        // A new code deserves a fresh set of attempts: the old one may have used them up.
        DB::run('UPDATE one_time_codes SET code_hash=?,expires_at=?,attempts=0,sent_count=sent_count+1,last_sent_at=? WHERE id=? AND consumed_at IS NULL', [
            self::hash($code, $selector), gmdate('Y-m-d H:i:s', $expires), now(), $row['id'],
        ]);
        $delivery = self::email($selector, $purpose, (string) $row['email'], $code, (int) $row['sent_count'] + 1);
        return ['ok'=>$delivery['ok'],'expires'=>$expires,'message'=>$delivery['message']];
    }

    /**
     * Check a submitted code and consume it atomically.
     *
     * @return array<string,mixed> the used row, including its payload
     */
    public static function verify(string $selector, string $purpose, string $code): array {
        $row = self::lookup($selector, $purpose);
        if (!$row) throw new UserError('This code has expired or was already used. Start again.');
        $expires = strtotime((string) $row['expires_at'] . ' UTC');
        if ($expires !== false && $expires < time()) {
            throw new UserError('That code has expired. Request a new one.');
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            throw new UserError('Too many incorrect attempts on this code. Request a new one.');
        }
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6 || !hash_equals((string) $row['code_hash'], self::hash($code, $selector))) {
            $attempts = (int) $row['attempts'] + 1;
            DB::run('UPDATE one_time_codes SET attempts=? WHERE id=?', [$attempts, $row['id']]);
            $left = self::MAX_ATTEMPTS - $attempts;
            throw new UserError($left > 0
                ? 'That code is not correct. Check the six digits in the email and try again.'
                : 'Too many incorrect attempts. Request a new code.');
        }
        $claim = DB::run('UPDATE one_time_codes SET consumed_at=? WHERE id=? AND consumed_at IS NULL', [now(), $row['id']]);
        if ($claim->rowCount() !== 1) throw new UserError('That code was just used. Start again.');
        return $row;
    }

    /** @return array<string,mixed>|null */
    public static function lookup(string $selector, string $purpose): ?array {
        if (!preg_match('/^[a-f0-9]{32}$/', $selector)) return null;
        return DB::one('SELECT * FROM one_time_codes WHERE selector=? AND purpose=? AND consumed_at IS NULL', [$selector, $purpose]);
    }

    public static function remember(string $key, array $pending): void { $_SESSION[$key] = $pending; }

    /** @return array<string,mixed>|null */
    public static function pending(string $key, string $purpose): ?array {
        $pending = $_SESSION[$key] ?? null;
        if (!is_array($pending) || (string) ($pending['purpose'] ?? '') !== $purpose) return null;
        if ((int) ($pending['expires'] ?? 0) < time()) { unset($_SESSION[$key]); return null; }
        return $pending;
    }

    public static function forget(string $key): void { unset($_SESSION[$key]); }

    /** Remember this device so a member is not asked for a code on every visit. */
    public static function trustDevice(int $userId, int $sessionVersion): void {
        $expires = time() + self::DEVICE_DAYS * 86400;
        $payload = $userId . '|' . $sessionVersion . '|' . $expires;
        setcookie(self::DEVICE_COOKIE, $payload . '|' . self::sign($payload), [
            'expires'=>$expires, 'path'=>base_path() ?: '/', 'secure'=>Security::requestHttps(),
            'httponly'=>true, 'samesite'=>'Lax',
        ]);
    }

    public static function deviceTrusted(int $userId, int $sessionVersion): bool {
        $raw = $_COOKIE[self::DEVICE_COOKIE] ?? '';
        if (!is_string($raw) || $raw === '') return false;
        $parts = explode('|', $raw);
        if (count($parts) !== 4) return false;
        [$uid, $version, $expires, $signature] = $parts;
        if (!ctype_digit($uid) || !ctype_digit($version) || !ctype_digit($expires)) return false;
        if ((int) $expires < time() || (int) $uid !== $userId || (int) $version !== $sessionVersion) return false;
        return hash_equals(self::sign($uid . '|' . $version . '|' . $expires), $signature);
    }

    public static function forgetDevice(): void {
        setcookie(self::DEVICE_COOKIE, '', ['expires'=>time() - 3600, 'path'=>base_path() ?: '/']);
    }

    /** Housekeeping for the background worker. */
    public static function purge(): int {
        return DB::run('DELETE FROM one_time_codes WHERE expires_at < ? LIMIT 500', [gmdate('Y-m-d H:i:s', time() - 3600)])->rowCount();
    }

    private static function code(): string { return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); }

    private static function hash(string $code, string $selector): string {
        return hash_hmac('sha256', $selector . '|' . $code, (string) config('key'));
    }

    private static function sign(string $payload): string {
        return hash_hmac('sha256', 'kb-device|' . $payload, (string) config('key'));
    }

    /** @return array{ok:bool,message:string} */
    private static function email(string $selector, string $purpose, string $email, string $code, int $send): array {
        $title = (string) Settings::get('site_title');
        $what = $purpose === 'login' ? 'sign in to your account' : 'confirm your email address';
        $subject = $title . ' · Your ' . ($purpose === 'login' ? 'sign-in' : 'confirmation') . ' code';
        $body = "Hello,\n\nUse this code to {$what}:\n\n{$code}\n\n"
            . "The code expires in 10 minutes and works once. If you did not ask for it, ignore this message — nothing changes on the account.\n\n"
            . "Never share this code with anyone. Nobody from {$title} will ask you for it.\n\n"
            . "Requested from this website at " . now() . " UTC.";
        try {
            $id = Mail::queue($email, $subject, $body, 'otp:' . $selector . ':' . $send);
            if ($id < 1) return ['ok'=>false,'message'=>'The code could not be queued. Please try again.'];
            $delivery = Mail::deliver($id);
            if (!$delivery['ok']) {
                error_log('KB OTP delivery: queue id ' . $id . ' — ' . $delivery['message']);
                return ['ok'=>false,'message'=>'The code could not be emailed just now: ' . $delivery['message']];
            }
            return ['ok'=>true,'message'=>'We emailed a six-digit code to ' . $email . '. It expires in 10 minutes.'];
        } catch (\Throwable $e) {
            error_log('KB OTP: ' . get_class($e));
            return ['ok'=>false,'message'=>'The code could not be emailed just now. Please try again in a moment.'];
        }
    }
}
