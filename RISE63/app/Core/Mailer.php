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
    /** @param resource $socket */
    private function __construct(private $socket, private array $config)
    {
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
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 587);
        $encryption = (string) ($config['encryption'] ?? 'tls');
        $isLocal = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if ($encryption === 'none' && !$isLocal) {
            throw new RuntimeException('Refusing to send mail over an unencrypted connection to a remote host.');
        }
        $transport = ($encryption === 'ssl' || $encryption === 'none') ? 'tcp' : 'tcp';
        $remote    = $transport . '://' . $host . ':' . $port;
        $context   = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $host,
            'allow_self_signed' => false,
        ]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException('SMTP connection failed: ' . ($errstr !== '' ? $errstr : 'unknown error'));
        }
        stream_set_timeout($socket, 15);
        $mailer = new self($socket, $config);
        $mailer->expect(220);
        $hostname = (string) ($config['helo'] ?? (gethostname() ?: 'localhost'));
        $mailer->command('EHLO ' . self::sanitise($hostname), 250);

        if ($encryption === 'tls') {
            $mailer->command('STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            $mailer->command('EHLO ' . self::sanitise($hostname), 250);
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

    private function expect(int|array $expected): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3} /', $line) === 1) {
                break;
            }
        }
        $code     = (int) substr($response, 0, 3);
        $expected = is_array($expected) ? $expected : [$expected];
        if ($response === '' || !in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP rejected the request (' . ($code ?: 'no response') . ').');
        }
        return $response;
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
