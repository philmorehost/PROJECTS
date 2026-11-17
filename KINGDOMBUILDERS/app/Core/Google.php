<?php
declare(strict_types=1);
namespace KB;

/**
 * "Continue with Google" using the OAuth 2.0 authorization-code flow with
 * PKCE. Only the OpenID Connect scopes openid/email/profile are requested and
 * no Google API data is stored: the verified email establishes the account.
 */
final class Google {
    private const AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const SESSION_KEY = 'google_oauth';
    private const STATE_TTL = 900;

    public static function enabled(): bool {
        return (bool) Settings::get('google_enabled') && self::clientId() !== '';
    }

    public static function configured(): bool {
        return self::enabled() && self::secret() !== '';
    }

    public static function clientId(): string {
        return trim((string) Settings::get('google_client_id'));
    }

    /** The exact URI that must be registered in the Google Cloud console. */
    public static function redirectUri(): string {
        return rtrim((string) config('site_url'), '/') . url('auth/google/callback');
    }

    public static function secret(): string {
        $sealed = (string) Settings::get('google_client_secret');
        if ($sealed === '') return '';
        try { return (string) (Security::unseal($sealed)['value'] ?? ''); }
        catch (\Throwable) { return ''; }
    }

    /** Start a sign-in: store one-time state, PKCE verifier and nonce; return Google's URL. */
    public static function begin(string $from = 'login'): string {
        if (!self::configured()) throw new UserError('Google sign-in is not available yet. Use your email and password, or ask an administrator to enable it.', 404);
        if (!Security::requestHttps() && !config('local_preview', false)) throw new UserError('Google sign-in requires a secure HTTPS connection.', 400);
        Security::throttle('google.start', Security::sourceHash(), 20);
        $pkce = self::pkce();
        $state = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(16));
        $_SESSION[self::SESSION_KEY] = [
            'state' => $state,
            'verifier' => $pkce['verifier'],
            'nonce' => $nonce,
            'from' => in_array($from, ['login', 'register'], true) ? $from : 'login',
            'started' => time(),
        ];
        return self::authorizationUrl($state, $pkce['challenge'], $nonce);
    }

    /** Validate the callback, exchange the code and return a verified profile. */
    public static function complete(string $code, string $state): array {
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]); // single use, whether or not it succeeds
        if (!is_array($pending) || !isset($pending['state'], $pending['verifier'], $pending['nonce'])) {
            throw new UserError('That Google sign-in link has expired. Please start again.', 400);
        }
        if ($state === '' || !hash_equals((string) $pending['state'], $state)) {
            throw new UserError('The Google sign-in response could not be verified. Please start again.', 400);
        }
        if (time() - (int) ($pending['started'] ?? 0) > self::STATE_TTL) {
            throw new UserError('The Google sign-in took too long. Please start again.', 400);
        }
        if ($code === '' || strlen($code) > 4096) throw new UserError('Google did not return an authorization code. Please try again.', 400);
        Security::throttle('google.callback', Security::sourceHash(), 30);

        $tokens = self::exchange($code, (string) $pending['verifier']);
        $claims = self::idTokenClaims((string) ($tokens['id_token'] ?? ''), (string) $pending['nonce']);
        $accessToken = (string) ($tokens['access_token'] ?? '');
        $info = $accessToken !== '' ? self::userinfo($accessToken) : [];
        if ($info !== [] && (string) ($info['sub'] ?? '') !== (string) ($claims['sub'] ?? '')) {
            throw new UserError('The Google account details did not match. Please try again.', 400);
        }
        $profile = $info !== [] ? $info : $claims;
        $email = strtolower(trim((string) ($profile['email'] ?? $claims['email'] ?? '')));
        $verified = $profile['email_verified'] ?? $claims['email_verified'] ?? false;
        if (!filter_var($verified, FILTER_VALIDATE_BOOLEAN)) {
            throw new UserError('Google has not verified this email address. Verify it in your Google account, then try again.', 403);
        }
        return [
            'email' => $email,
            'name' => (string) ($profile['name'] ?? $claims['name'] ?? ''),
            'sub' => (string) ($profile['sub'] ?? $claims['sub'] ?? ''),
            'email_verified' => true,
            'picture' => (string) ($profile['picture'] ?? ''),
        ];
    }

    /**
     * Find or create the local account for a verified Google profile.
     * Existing accounts are matched by email; the Google proof marks an
     * unverified local account as verified.
     */
    public static function accountFor(array $profile, ?bool $registrationOpen = null): array {
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        if (!valid_email($email)) throw new UserError('Google did not return a usable email address. Add an email to your Google account and try again.', 422);
        if (empty($profile['email_verified'])) throw new UserError('Google has not verified this email address.', 403);
        Security::throttle('google.account', $email, 10);

        $user = DB::one('SELECT * FROM users WHERE email=?', [$email]);
        if ($user) return self::existing($user);
        if (!($registrationOpen ?? (bool) Settings::get('registration_open'))) {
            throw new UserError('Registration is currently closed. Ask an administrator for access.', 403);
        }
        $name = self::name((string) ($profile['name'] ?? ''), $email);
        try {
            DB::transaction(static function () use ($name, $email): void {
                DB::run('INSERT INTO users(name,email,password_hash,email_verified_at,created_at,updated_at) VALUES (?,?,?,?,?,?)', [
                    $name, $email, hash_password(bin2hex(random_bytes(32))), now(), now(), now(),
                ]);
                $id = DB::id();
                Audit::activity('account.registered_google', 'user', $id, ['provider'=>'google'], DB::one('SELECT * FROM users WHERE id=?', [$id]), 'Welcome! Your account was created with Google, so there is no password to remember.');
            });
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            $race = DB::one('SELECT * FROM users WHERE email=?', [$email]);
            if (!$race) throw new UserError('The account could not be created. Please try again.');
            return self::existing($race);
        }
        $created = DB::one('SELECT * FROM users WHERE email=?', [$email]);
        if (!$created) throw new UserError('The account could not be created. Please try again.');
        return $created;
    }

    private static function existing(array $user): array {
        if (($user['status'] ?? '') !== 'active') {
            throw new UserError('This account is suspended. Contact an administrator if you believe this is a mistake.', 403);
        }
        if (empty($user['email_verified_at'])) {
            DB::run('UPDATE users SET email_verified_at=?,updated_at=? WHERE id=?', [now(), now(), $user['id']]);
            $user['email_verified_at'] = now();
        }
        return $user;
    }

    private static function name(string $name, string $email): string {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $local = strstr($email, '@', true) ?: 'Friend';
            $name = ucwords(str_replace(['.', '_', '-'], ' ', $local));
            $name = mb_substr(trim($name) !== '' ? trim($name) : 'Friend', 0, 120);
        }
        return $name;
    }

    /** @return array{verifier:string,challenge:string} */
    public static function pkce(): array {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        return ['verifier' => $verifier, 'challenge' => self::challenge($verifier)];
    }

    public static function challenge(string $verifier): string {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function authorizationUrl(string $state, string $challenge, string $nonce): string {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'access_type' => 'online',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    private static function exchange(string $code, string $verifier): array {
        [$status, $body] = self::http('POST', self::TOKEN, [
            'code' => $code,
            'client_id' => self::clientId(),
            'client_secret' => self::secret(),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ]);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            $error = is_array($data) ? (string) ($data['error'] ?? '') : '';
            if ($error === 'invalid_client') throw new UserError('Google rejected the sign-in configuration. An administrator needs to check the client ID and secret.', 503);
            if ($error === 'invalid_grant') throw new UserError('That Google sign-in code has expired. Please try again.', 400);
            error_log('KB Google token exchange failed: status=' . $status . ' error=' . preg_replace('/[^a-z_]/', '', $error));
            throw new UserError('Google sign-in could not be completed. Please try again in a moment.', 502);
        }
        return $data;
    }

    /** @return array<string,mixed> */
    private static function idTokenClaims(string $idToken, string $nonce): array {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) throw new UserError('Google returned an unreadable sign-in token. Please try again.', 502);
        $raw = self::base64UrlDecode($parts[1]);
        $claims = $raw === null ? null : json_decode($raw, true);
        if (!is_array($claims)) throw new UserError('Google returned an unreadable sign-in token. Please try again.', 502);
        // The token arrived directly from Google's token endpoint over TLS, which
        // OIDC permits as the authenticity check for the code flow; the claims
        // below are still validated strictly.
        $issuer = (string) ($claims['iss'] ?? '');
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new UserError('The Google sign-in response came from an unexpected issuer. Please try again.', 400);
        }
        $audience = $claims['aud'] ?? '';
        $audienceOk = is_array($audience)
            ? in_array(self::clientId(), array_map('strval', $audience), true)
            : hash_equals(self::clientId(), (string) $audience);
        if (!$audienceOk) {
            throw new UserError('The Google sign-in response was issued for a different application. Check the client ID in settings.', 400);
        }
        if (isset($claims['azp']) && !hash_equals(self::clientId(), (string) $claims['azp'])) {
            throw new UserError('The Google sign-in response was issued for a different application.', 400);
        }
        if ((int) ($claims['exp'] ?? 0) < time() - 60) throw new UserError('The Google sign-in response expired. Please try again.', 400);
        if ((int) ($claims['iat'] ?? 0) > time() + 120) throw new UserError('The Google sign-in response is not valid yet. Please try again.', 400);
        if (!isset($claims['nonce']) || !hash_equals($nonce, (string) $claims['nonce'])) {
            throw new UserError('The Google sign-in response did not match this session. Please start again.', 400);
        }
        return $claims;
    }

    /** @return array<string,mixed> */
    private static function userinfo(string $accessToken): array {
        [$status, $body] = self::http('GET', self::USERINFO, [], $accessToken);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            error_log('KB Google userinfo failed: status=' . $status);
            throw new UserError('Google did not return your profile details. Please try again.', 502);
        }
        return $data;
    }

    /**
     * Minimal HTTPS client: cURL when available, otherwise PHP streams.
     * Never logs the client secret, authorization code or tokens.
     * @param array<string,string> $fields
     * @return array{0:int,1:string}
     */
    private static function http(string $method, string $url, array $fields = [], string $bearer = ''): array {
        $body = $fields ? http_build_query($fields, '', '&', PHP_QUERY_RFC3986) : '';
        $headers = ['Accept: application/json'];
        if ($method === 'POST') $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($bearer !== '') $headers[] = 'Authorization: Bearer ' . $bearer;
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'KINGDOMBUILDERS/' . VERSION,
            ]);
            if ($method === 'POST') curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            unset($handle); // curl_close() is a no-op since PHP 8.0
            if ($response === false) {
                error_log('KB Google HTTP failure: ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $error));
                throw new UserError('Google could not be reached from this server. Ask your host to allow outbound HTTPS.', 502);
            }
            return [$status, (string) $response];
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
        }
        if ($response === false) throw new UserError('Google could not be reached from this server. Ask your host to allow outbound HTTPS.', 502);
        return [$status, $response];
    }

    private static function base64UrlDecode(string $value): ?string {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }
}
