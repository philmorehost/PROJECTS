<?php
declare(strict_types=1);

namespace RISE63\Core;

use RuntimeException;

/**
 * SMTP client used only by bin/mail-worker.php.
 *
 * A deliberately small implementation: STARTTLS or implicit TLS, AUTH LOGIN or PLAIN,
 * CRLF-safe headers, dot-stuffing, and a strict recipient/message size ceiling. It refuses
 * to run without TLS unless the configuration explicitly targets localhost for testing.
 */
final class Mailer
{
    /** Seconds to wait for each response. Mirrors the stream timeout, for messages. */
    private int $timeout = 20;

    /** The last complete reply, kept so a failure can quote what the server actually said. */
    private string $lastResponse = '';

    /**
     * host:port of the connection, so a failure names what it tried.
     *
     * A blocked port frequently *connects* — the firewall drops the server's replies rather than
     * refusing the socket — so "no reply" is exactly the case where the operator most needs to know
     * which endpoint went quiet.
     */
    private string $endpoint = '';

    /** Descriptions for the errno values stream_socket_client() actually produces. */
    private const CONNECT_ERRORS = [
        110 => 'the connection timed out',
        111 => 'the connection was refused',
        113 => 'no route to the host',
    ];

    /** @param resource $socket */
    private function __construct(private $socket, private array $config)
    {
    }

    /** True when a usable SMTP configuration exists — the UI uses this to decide what to promise. */
    public static function configured(): bool
    {
        $file = \RISE63\config_dir() . '/smtp.php';
        if (!is_file($file)) {
            return false;
        }
        $config = require $file;
        return is_array($config) && trim((string) ($config['host'] ?? '')) !== '';
    }

    /**
     * The sender identity configured in Settings → Mail.
     *
     * The worker used to derive the From address from the site URL and the sender name from the site
     * title, which meant the one thing an operator could not change was the address members see —
     * and a From that does not match the authenticated mailbox is exactly what makes providers mark
     * mail as spam. Falls back to the old behaviour when the fields are absent, so an existing
     * hand-written config/smtp.php keeps working untouched.
     *
     * @return array{email:string,name:string}
     */
    public static function sender(): array
    {
        $config = self::config();
        $email  = trim((string) ($config['from_email'] ?? ''));
        $name   = trim((string) ($config['from_name'] ?? ''));

        if ($email === '') {
            $host = (string) (parse_url((string) (\RISE63\setting('site_url') ?: ''), PHP_URL_HOST) ?: '');
            // A bare hostname is not a valid address: `no-reply@localhost` fails every validator and
            // is refused by `send()` before it reaches the wire. Without configuration nothing sends
            // anyway (fromConfig() throws), so the fallback only has to be well-formed — but it does
            // have to be well-formed, or the failure arrives as a transport error instead of the
            // honest "SMTP is not configured".
            if (!str_contains($host, '.')) {
                $host = 'localhost.localdomain';
            }
            $email = 'no-reply@' . $host;
        }
        if ($name === '') {
            // \RISE63\setting() swallows a database error and returns the default, so this has to
            // have a usable default of its own: mail with an empty display name is a spam signal.
            $name = trim((string) \RISE63\setting('site_title', 'RISE 63'));
        }
        if ($name === '') {
            $name = 'RISE 63';
        }

        return ['email' => $email, 'name' => $name];
    }

    /** @return array<string, mixed> the parsed file, or an empty array when there is none. */
    private static function config(): array
    {
        $file = \RISE63\config_dir() . '/smtp.php';
        if (!is_file($file)) {
            return [];
        }
        $config = @include $file;
        return is_array($config) ? $config : [];
    }

    public static function fromConfig(): self
    {
        $file = \RISE63\config_dir() . '/smtp.php';
        if (!is_file($file)) {
            throw new RuntimeException('SMTP is not configured (config/smtp.php is missing).');
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new RuntimeException('SMTP configuration is invalid.');
        }
        return self::connect($config);
    }

    public static function connect(array $config): self
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 587);
        $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
        $isLocal = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($encryption === 'none' && !$isLocal) {
            throw new RuntimeException('Refusing to send mail over an unencrypted connection to a remote host.');
        }

        // ── Implicit TLS negotiates at connect time, and nothing else does.
        //
        // Both branches here used to read `'tcp'` — a typo that made `encryption = ssl` (port 465)
        // open a *plain* TCP socket to a server that starts its TLS handshake immediately. The
        // server waits for a ClientHello; this client waits for a `220` banner; neither sends
        // anything, and the read times out with an empty response. That surfaced to the operator as
        //
        //     SMTP rejected the request (no response).
        //
        // which named neither the cause nor the setting to change. `ssl://` is what makes PHP do the
        // handshake during `stream_socket_client()`.
        $remote = self::transportFor($encryption) . '://' . $host . ':' . $port;

        $connectTimeout = (int) ($config['connect_timeout'] ?? 20) ?: 20;
        $readTimeout    = (int) ($config['timeout'] ?? 20) ?: 20;

        $context = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            // SNI: a server hosting several mail domains answers with the wrong certificate without
            // it, which arrives as an unexpected-verification error rather than as a bad hostname.
            'peer_name'         => $host,
            'SNI_enabled'       => true,
            'allow_self_signed' => false,
        ]]);

        $socket = @stream_socket_client($remote, $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException(self::connectError($host, $port, $encryption, (int) $errno, (string) $errstr));
        }
        stream_set_timeout($socket, $readTimeout);

        $mailer = new self($socket, $config);
        $mailer->timeout  = $readTimeout;
        $mailer->endpoint = $host . ':' . $port;

        try {
            $mailer->expect(220);
            $hostname = (string) ($config['helo'] ?? (gethostname() ?: 'localhost'));
            $mailer->command('EHLO ' . self::sanitise($hostname), 250);

            if ($encryption === 'tls') {
                // Say what is actually wrong when the server does not offer STARTTLS: on port 465 it
                // never will, and "SMTP rejected the request (530)" sends the operator looking at
                // their password instead of at the encryption setting.
                try {
                    $mailer->command('STARTTLS', 220);
                } catch (RuntimeException $error) {
                    if (!str_contains($mailer->lastResponse, 'STARTTLS')) {
                        throw new RuntimeException(
                            'SMTP rejected STARTTLS. Port 465 requires “Implicit TLS” rather than STARTTLS — '
                            . 'if that is not the problem, the server does not offer STARTTLS on this port.',
                            0,
                            $error
                        );
                    }
                    throw $error;
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException(
                        'SMTP STARTTLS negotiation failed: the server accepted the command but the '
                        . 'TLS handshake did not complete. An outdated TLS configuration on the server, '
                        . 'or a firewall inspecting the connection, causes this.'
                    );
                }
                $mailer->command('EHLO ' . self::sanitise($hostname), 250);
            }
        } catch (RuntimeException $error) {
            // Do not leak the socket when a handshake step fails: bin/mail-worker.php exits, but
            // Settings → “Test the connection” catches this and carries on.
            $mailer->close();
            throw $error;
        }

        $user = (string) ($config['username'] ?? '');
        if ($user !== '') {
            $auth = strtolower((string) ($config['auth'] ?? 'login'));
            if ($auth === 'plain') {
                $mailer->command('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . (string) ($config['password'] ?? '')), 235);
            } else {
                $mailer->command('AUTH LOGIN', 334);
                $mailer->command(base64_encode($user), 334);
                $mailer->command(base64_encode((string) ($config['password'] ?? '')), 235);
            }
        }

        return $mailer;
    }

    public function send(string $from, string $fromName, string $to, string $subject, string $html, string $text): void
    {
        foreach ([$from, $to] as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
                throw new RuntimeException('Invalid e-mail address in the queue.');
            }
        }
        $this->command('MAIL FROM:<' . $from . '>', 250);
        $this->command('RCPT TO:<' . $to . '>', [250, 251]);
        $this->command('DATA', 354);

        $boundary = 'rise63-' . bin2hex(random_bytes(12));
        $headers  = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::sanitise(explode('@', $from)[1] ?? 'rise63.local') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: RISE63/1.0',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . self::dotStuff(self::normaliseNewlines($text)) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . self::dotStuff(self::normaliseNewlines($html)) . "\r\n"
            . '--' . $boundary . "--\r\n";

        if (strlen($body) > 512000) {
            throw new RuntimeException('Message exceeds the 500 KB transport limit.');
        }
        $this->write($body . ".\r\n");
        $this->expect(250);
    }

    public function quit(): void
    {
        if (is_resource($this->socket)) {
            try {
                $this->command('QUIT', 221);
            } catch (\Throwable) {
                // Ignore: the message is already accepted.
            }
            fclose($this->socket);
        }
    }

    private function command(string $line, int|array $expected): string
    {
        $this->write($line . "\r\n");
        return $this->expect($expected);
    }

    private function write(string $data): void
    {
        if (fwrite($this->socket, $data) === false) {
            throw new RuntimeException('SMTP write failed.');
        }
    }

    /**
     * Read one reply and check its code.
     *
     * The distinction that matters to the operator: **no reply at all** is a transport problem
     * (blocked port, wrong encryption, dead server), while a reply with an unexpected code is the
     * server saying no (bad password, refused sender). Both used to arrive as
     *
     *     SMTP rejected the request (no response).
     *
     * which is true of neither in a way anyone can act on. The message now says which it was, quotes
     * the server's own words, and names the setting.
     */
    private function expect(int|array $expected): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }
        $this->lastResponse = $response;

        $expected = is_array($expected) ? $expected : [$expected];
        $wanted   = implode(' or ', $expected);

        // fgets() returning false means end of stream, a timeout, or an error — never a reply.
        if ($response === '') {
            $meta = @stream_get_meta_data($this->socket);
            $why  = !empty($meta['timed_out'])
                ? 'no reply within ' . $this->timeout . ' seconds'
                : 'the connection was closed without a reply';

            throw new RuntimeException(
                'The mail server at ' . $this->endpoint . ' gave no reply: ' . $why
                . ', expected ' . $wanted . '. This is a connection problem rather than a rejected '
                . 'password — usually a blocked port, the wrong encryption mode for the port, or a '
                . 'firewall dropping SMTP.'
            );
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException(
                'The mail server refused the request with “' . trim($response) . '” (expected ' . $wanted . '). '
                . self::authHint($code)
            );
        }
        return $response;
    }

    /**
     * The usual reason the server says no, named so the operator does not have to know SMTP codes.
     * Deliberately about settings, never about the password itself.
     */
    private static function authHint(int $code): string
    {
        return match (true) {
            // 535/534 is a rejected credential. Name the two things that cause it in practice.
            $code === 535, $code === 534, $code === 530 =>
                'That is an authentication failure. Most providers need an app password rather than '
                . 'the mailbox password — Gmail, Outlook and Yandex all do when 2-step verification is '
                . 'on. A username that is not the full mailbox address causes it too.',
            $code === 550, $code === 551, $code === 553 =>
                'The server rejected the sender or recipient address. The “send from” address must be '
                . 'one the mailbox is allowed to send as — many providers refuse any other.',
            $code >= 500 =>
                'Check the port, the encryption mode and the username.',
            default => 'Check the settings on this page.',
        };
    }

    /** Release the socket without saying goodbye — used when the handshake failed. */
    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }

    /**
     * The PHP stream transport for an encryption mode.
     *
     * `ssl` means "negotiate TLS during connect" (implicit TLS, port 465). `tcp` means "plain socket,
     * upgrade later if at all" (STARTTLS on 587, or none). Getting this wrong is not a subtle bug: a
     * `tcp` socket to a 465 server deadlocks, because the server waits for a ClientHello while the
     * client waits for a banner. Public and separate so the unit suite can assert it without a
     * mail server, which is how the original typo survived.
     */
    public static function transportFor(string $encryption): string
    {
        return match (strtolower(trim($encryption))) {
            'ssl' => 'ssl',
            default => 'tcp',
        };
    }

    /** Whether this PHP was built with the TLS socket transport mail needs. */
    public static function tlsAvailable(): bool
    {
        return in_array('ssl', stream_get_transports(), true);
    }

    /**
     * Explain a failed connection in the operator's terms.
     *
     * The raw message from PHP is `Connection timed out`, which does not say *which* of the three
     * things is wrong. This maps the common cases to the setting that fixes them, and always names
     * the host, port and mode that were attempted — the operator is editing those three fields.
     */
    private static function connectError(string $host, int $port, string $encryption, int $errno, string $errstr): string
    {
        $detail = self::CONNECT_ERRORS[$errno] ?? ($errstr !== '' ? $errstr : 'unknown error');

        $hint = match (true) {
            $errno === 110 && $encryption === 'tls' && $port === 465 =>
                'Port 465 uses implicit TLS, not STARTTLS — and many hosts block outbound 465 as well. '
                . 'Choose “Implicit TLS” for 465, or use STARTTLS on 587.',
            $errno === 110 =>
                'The server did not answer. Common causes: this host blocks outbound SMTP (shared '
                . 'hosting often blocks 25, 465 and 587 — ask the provider, or use their relay), or a '
                . 'firewall is dropping the connection.',
            $errno === 111 =>
                'The server refused the connection. Check the port: 587 for STARTTLS, 465 for implicit TLS.',
            str_contains($errstr, 'certificate') || str_contains($errstr, 'SSL') =>
                'The TLS certificate could not be verified. If this server uses a self-signed '
                . 'certificate, it cannot be used without weakening verification, which this '
                . 'installation refuses to do.',
            default => $detail,
        };

        // The hint for a certificate failure already quotes the reason; do not say it twice.
        $prefix = 'Could not connect to ' . $host . ':' . $port . ' (' . strtoupper($encryption) . '): ';
        return str_contains($hint, $detail)
            ? $prefix . rtrim($hint, '. ') . '.'
            : $prefix . rtrim($detail, '. ') . '. ' . $hint;
    }

    private static function dotStuff(string $data): string
    {
        return preg_replace('/(^|\r\n)\./', '$1..', $data) ?? $data;
    }

    private static function normaliseNewlines(string $data): string
    {
        return str_replace(["\r\n", "\r"], "\n", $data) === $data
            ? str_replace("\n", "\r\n", $data)
            : str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $data));
    }

    private static function sanitise(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9.\-_]/', '', $value) ?? 'localhost';
    }

    private static function encodeName(string $name): string
    {
        $name = str_replace(['"', "\r", "\n"], '', $name);
        return '"' . $name . '"';
    }

    private static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}
