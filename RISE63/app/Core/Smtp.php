<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;

/**
 * SMTP settings, editable from the admin UI.
 *
 * Until now `config/smtp.php` was the one thing an operator had to create by hand, over SSH or FTP,
 * in a specific PHP array shape — and the only feedback if they got it wrong was that password
 * resets silently never arrived. Settings → Mail replaces that with a form. The file stays the
 * source of truth (the mail worker, the doctor and the reset page all read it), so this class is a
 * careful reader and writer of that one file and nothing more.
 *
 * Three rules, because this file holds a credential:
 *
 *   1. **The password is never sent back to the browser.** The form shows "(saved)" and an empty
 *      field. Submitting the form with the field left empty keeps the stored password; there is a
 *      separate, explicit "remove" control. A settings page that echoes a password into HTML has
 *      put it in the page cache, the browser history and any screenshot of the screen.
 *   2. **Writes are atomic and 0600.** A half-written config is a mail worker that crashes every
 *      five minutes; a world-readable one is a leaked mailbox.
 *   3. **Validation happens before anything is written**, and the boolean options are written as
 *      real booleans — the mailer compares `=== 'ssl'`, so a string 'false' is a live bug.
 */
final class Smtp
{
    /** Filename inside the config directory, shared with Mailer and bin/doctor.php. */
    public const FILE = 'smtp.php';

    public const ENCRYPTIONS = ['tls' => 'STARTTLS (port 587 — the usual choice)', 'ssl' => 'Implicit TLS (port 465)', 'none' => 'No encryption (localhost only)'];
    public const AUTH_METHODS = ['login' => 'AUTH LOGIN (most servers)', 'plain' => 'AUTH PLAIN'];

    /**
     * The current settings, with the password included. Only ever used server-side: the view is
     * handed `masked()` instead.
     *
     * @return array{host:string,port:int,encryption:string,username:string,password:string,auth:string,from_email:string,from_name:string,helo:string,configured:bool}
     */
    public static function load(): array
    {
        $defaults = [
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => '',
            'password'   => '',
            'auth'       => 'login',
            'from_email' => '',
            'from_name'  => '',
            'helo'       => '',
        ];

        $file = \RISE63\config_dir() . '/' . self::FILE;
        if (!is_file($file)) {
            return $defaults + ['configured' => false];
        }

        $raw = @include $file;
        if (!is_array($raw)) {
            return $defaults + ['configured' => false];
        }

        $loaded = [
            'host'       => trim((string) ($raw['host'] ?? '')),
            'port'       => (int) ($raw['port'] ?? 587) ?: 587,
            'encryption' => strtolower(trim((string) ($raw['encryption'] ?? 'tls'))),
            'username'   => (string) ($raw['username'] ?? ''),
            'password'   => (string) ($raw['password'] ?? ''),
            'auth'       => strtolower(trim((string) ($raw['auth'] ?? 'login'))),
            'from_email' => trim((string) ($raw['from_email'] ?? '')),
            'from_name'  => trim((string) ($raw['from_name'] ?? '')),
            'helo'       => trim((string) ($raw['helo'] ?? '')),
        ];

        return $loaded + ['configured' => $loaded['host'] !== ''];
    }

    /**
     * What the view receives: everything except the password, plus a flag saying whether one is
     * stored. The password itself must never cross into HTML.
     *
     * @return array<string, mixed>
     */
    public static function masked(): array
    {
        $settings = self::load();
        $settings['has_password'] = $settings['password'] !== '';
        unset($settings['password']);
        return $settings;
    }

    /** Whether a usable transport exists. The reset page promises self-service on the strength of this. */
    public static function configured(): bool
    {
        return self::load()['host'] !== '';
    }

    /**
     * Validate and persist. `$input` is the raw form payload.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> the masked settings, as saved
     *
     * @throws RuntimeException with an operator-readable message; nothing is written when it throws
     */
    public static function save(array $input): array
    {
        $current = self::load();

        $host = strtolower(trim((string) ($input['host'] ?? '')));
        $port = (int) ($input['port'] ?? 587);
        $encryption = strtolower(trim((string) ($input['encryption'] ?? 'tls')));
        $auth = strtolower(trim((string) ($input['auth'] ?? 'login')));
        $username = trim((string) ($input['username'] ?? ''));
        $fromEmail = strtolower(trim((string) ($input['from_email'] ?? '')));
        $fromName = trim((string) ($input['from_name'] ?? ''));
        $helo = trim((string) ($input['helo'] ?? ''));

        $removePassword = !empty($input['remove_password']);

        // ── Validation. Every message names the field and what was expected.
        if ($host === '' || mb_strlen($host) > 190) {
            throw new RuntimeException('Enter the SMTP server address, for example smtp.gmail.com.');
        }
        // A host is a hostname or an IP — never a URL, and never anything with a scheme: pasting
        // "https://smtp.example.com" into the field is the most common version of this mistake.
        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            throw new RuntimeException('The server address should be a hostname or IP address only — no "https://", no spaces, no port.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('The port must be between 1 and 65535 (587 for STARTTLS, 465 for implicit TLS).');
        }
        if (!isset(self::ENCRYPTIONS[$encryption])) {
            throw new RuntimeException('Choose an encryption mode from the list.');
        }
        if (!in_array($auth, ['login', 'plain'], true)) {
            throw new RuntimeException('Choose an authentication method from the list.');
        }
        if ($fromEmail === '') {
            throw new RuntimeException('Enter the address mail should be sent from — members see it as the sender.');
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The “send from” address is not a valid e-mail address.');
        }
        if (preg_match('/[\r\n]/', $fromName) === 1) {
            throw new RuntimeException('The sender name cannot contain a line break.');
        }
        if ($helo !== '' && preg_match('/^[a-z0-9.-]+$/i', $helo) !== 1) {
            throw new RuntimeException('The EHLO name should look like a hostname (letters, digits, dots and hyphens).');
        }

        // ── Encryption and port must agree, or mail silently never leaves the server. This is the
        // single most common misconfiguration: port 465 with STARTTLS hangs until the timeout.
        $isLocal = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($encryption === 'none' && !$isLocal) {
            throw new RuntimeException('Unencrypted SMTP is refused for a remote server — the mail worker would refuse to send and queue everything instead. Use STARTTLS, or implicit TLS on port 465. For a local relay, use the address 127.0.0.1.');
        }
        if ($encryption === 'ssl' && !in_array($port, [465, 8465], true)) {
            throw new RuntimeException('Implicit TLS is normally port 465. Change the port, or choose STARTTLS if your provider uses 587.');
        }
        if ($encryption === 'tls' && $port === 465) {
            throw new RuntimeException('Port 465 uses implicit TLS, not STARTTLS. Choose “Implicit TLS” — with STARTTLS on 465 the connection hangs until it times out.');
        }
        if ($username === '' && !$isLocal) {
            throw new RuntimeException('Enter the SMTP username. Most providers refuse mail from an unauthenticated sender.');
        }

        // Port 25 is the relay port, blocked almost everywhere. This is a *coherence* check on the
        // numbers the operator typed — not a capability check. Whether this PHP can actually do TLS
        // belongs in test(), not here: validation that depends on the machine makes the same form
        // save or refuse depending on the host, and silently blocks an operator whose host enables
        // openssl tomorrow.
        if ($encryption === 'tls' && $port === 25) {
            throw new RuntimeException(
                'Port 25 is the relay port, and most providers block it on shared hosting and '
                . 'outbound from virtual servers. Use STARTTLS on 587 or implicit TLS on 465.'
            );
        }

        // ── The password: an empty field means "keep what is stored", which is why it is never
        // rendered back to the browser.
        $password = (string) ($input['password'] ?? '');
        if ($removePassword) {
            // Removing the password while keeping the username produces a configuration that no
            // real server accepts — it would save cleanly and then fail on every worker run. Say
            // what to do instead of accepting it.
            if ($username !== '' && !$isLocal) {
                throw new RuntimeException('You asked to remove the saved password but the username is still filled in. Clear the username too — a server that requires a username will refuse mail without its password — or enter a new password.');
            }
            $password = '';
        } elseif ($password === '') {
            $password = $current['password'];
        }
        if ($username !== '' && $password === '' && !$isLocal) {
            throw new RuntimeException('Enter the SMTP password. Leaving it blank keeps the saved one — there is none saved for this username yet.');
        }
        if (preg_match('/[\r\n]/', $password) === 1) {
            throw new RuntimeException('The password cannot contain a line break. If it does, generate an app password for this application.');
        }

        $config = [
            'host'       => $host,
            'port'       => $port,
            'encryption' => $encryption,
            'username'   => $username,
            'password'   => $password,
            'auth'       => $auth,
            'from_email' => $fromEmail,
            'from_name'  => $fromName !== '' ? $fromName : 'RISE 63',
            'helo'       => $helo !== '' ? $helo : (string) (parse_url((string) (\RISE63\setting('site_url') ?: 'https://localhost'), PHP_URL_HOST) ?: 'localhost'),
        ];

        self::write($config);

        // No audit write here on purpose: this class owns exactly one file and is tested without a
        // database. The POST handler records the change, and `summary()` is what it writes.
        return self::masked();
    }

    /**
     * Write the file atomically with 0600. Written to a temporary name in the same directory and
     * renamed, so a crash mid-write cannot leave a partly written config that the mail worker
     * would `require` on its next run.
     *
     * @param array<string, mixed> $config
     */
    private static function write(array $config): void
    {
        $dir  = \RISE63\config_dir();
        $file = $dir . '/' . self::FILE;
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $php = "<?php\n"
            . "/**\n"
            . " * SMTP transport for bin/mail-worker.php.\n"
            . " *\n"
            . " * Written by Settings → Mail. Safe to edit by hand, but the form validates more than a\n"
            . " * hand edit does — in particular that the port and the encryption agree.\n"
            . " *\n"
            . " * Keep this file at mode 0600: it holds a mailbox password.\n"
            . " */\n"
            . "return [\n"
            . "    'host'       => " . var_export((string) $config['host'], true) . ",\n"
            . "    'port'       => " . (int) $config['port'] . ",\n"
            . "    'encryption' => " . var_export((string) $config['encryption'], true) . ",\n"
            . "    'username'   => " . var_export((string) $config['username'], true) . ",\n"
            . "    'password'   => " . var_export((string) $config['password'], true) . ",\n"
            . "    'auth'       => " . var_export((string) $config['auth'], true) . ",\n"
            . "    'from_email' => " . var_export((string) $config['from_email'], true) . ",\n"
            . "    'from_name'  => " . var_export((string) $config['from_name'], true) . ",\n"
            . "    'helo'       => " . var_export((string) $config['helo'], true) . ",\n"
            . "];\n";

        if (@file_put_contents($temp, $php) === false) {
            throw new RuntimeException('Could not write config/smtp.php. Check that the config directory is writable by PHP.');
        }
        @chmod($temp, 0600);
        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException('Could not replace config/smtp.php. Check the permissions on the config directory.');
        }
        @chmod($file, 0600);
        clearstatcache(true, $file);
    }

    /**
     * A one-line description of the saved transport, for the audit trail.
     *
     * Deliberately excluded: the password, and the username (which is usually the mailbox address).
     * "Who changed the sender and when" is the question worth answering later; the credential is
     * not evidence, it is a liability in a log.
     */
    public static function summary(): string
    {
        $settings = self::load();
        if ($settings['host'] === '') {
            return 'SMTP removed';
        }
        return 'SMTP saved: ' . $settings['host'] . ':' . (int) $settings['port']
            . ' ' . strtoupper((string) $settings['encryption'])
            . ' from ' . $settings['from_email'];
    }

    /** Delete the configuration — used by "remove" and available to a future uninstall path. */
    public static function forget(): void
    {
        $file = \RISE63\config_dir() . '/' . self::FILE;
        if (is_file($file)) {
            @unlink($file);
            clearstatcache(true, $file);
        }
    }

    /**
     * Open a connection, authenticate, and disconnect — without sending anything.
     *
     * This is what makes the form usable: a wrong password or the wrong port is reported while the
     * operator is looking at the field, instead of being discovered by a member who never got their
     * reset link. It performs the same handshake `Mailer::fromConfig()` does, so a test that passes
     * here is a transport that works.
     *
     * @return array{ok:bool,message:string}
     */
    public static function test(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'message' => 'Save the server settings first — there is nothing to test yet.'];
        }

        if (!Mailer::tlsAvailable()) {
            return [
                'ok' => false,
                'message' => 'This PHP build has no TLS support (the openssl extension is missing), so no '
                    . 'encrypted SMTP connection can be made. Ask the host to enable openssl.',
            ];
        }

        try {
            $mailer = Mailer::fromConfig();
            $mailer->quit();
        } catch (\Throwable $error) {
            // Mailer never puts credentials in its messages; see the note in Mailer::expect().
            return [
                'ok' => false,
                'message' => $error->getMessage() . ' (Nothing was sent: this only opens a connection and '
                    . 'authenticates.)',
            ];
        }

        $settings = self::load();
        return [
            'ok' => true,
            'message' => 'Connected to ' . $settings['host'] . ':' . (int) $settings['port']
                . ' and authenticated. Send a real message to confirm delivery and check it does not land in spam.',
        ];
    }
}
