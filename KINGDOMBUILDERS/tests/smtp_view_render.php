<?php
declare(strict_types=1);
/**
 * Renders the Email & SMTP view with a fabricated diagnosis so the template is
 * exercised without a database. Run: php tests/smtp_view_render.php
 */
namespace KB {
    function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function url(string $path = '', array $query = []): string { return '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : ''); }
    function config(string $key, mixed $default = null): mixed { return $default; }
    final class Security { public static function csrfField(): string { return '<input type="hidden" name="_token" value="token">'; } }
    final class Settings {
        public static function get(string $key, mixed $default = null): mixed { return $key === 'php_cli_path' ? '' : $default; }
        public static function all(): array { return []; }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

    function check(bool $condition, string $label): void {
        if (!$condition) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
        echo "PASS {$label}\n";
    }

    function renderView(array $diagnosis): string {
        $settings = [
            'smtp_enabled' => true, 'smtp_host' => 'mail.example.test', 'smtp_port' => 587,
            'smtp_security' => 'tls', 'smtp_username' => 'postmaster@example.test', 'smtp_secret' => 'sealed',
            'from_name' => 'KINGDOMBUILDERS', 'from_email' => 'notifications@example.test',
            'admin_email' => 'admin@example.test', 'php_cli_path' => '',
        ];
        $error = null; $test = null; $saved = false;
        $cron = ['command' => '/usr/bin/php /var/www/bin/worker.php', 'crontab' => '* * * * * /usr/bin/php /var/www/bin/worker.php', 'php' => '/usr/bin/php', 'php_detected' => true, 'path' => '/var/www/bin/worker.php', 'detected' => true];
        $heartbeat = ['recorded_at' => gmdate('Y-m-d H:i:s', time() - 30)];
        ob_start();
        require dirname(__DIR__) . '/app/Views/admin/smtp.php';
        return (string) ob_get_clean();
    }

    $healthy = [
        'host' => 'mail.example.test', 'ips' => ['203.0.113.10'], 'resolved' => true,
        'configured' => ['port' => 587, 'security' => 'tls', 'label' => 'STARTTLS'],
        'probes' => [[
            'ok' => true, 'stage' => 'ready', 'detail' => 'Greeting and EHLO succeeded with STARTTLS. Authentication was not attempted.',
            'capabilities' => ['AUTH', 'STARTTLS'], 'banner' => '220 mail.example.test ESMTP', 'ms' => 42,
            'configured' => true, 'port' => 587, 'security' => 'tls', 'label' => 'STARTTLS', 'auth' => ['PLAIN', 'LOGIN'],
        ]],
        'recommendation' => ['code' => 'ok', 'text' => 'The saved port and security mode answer correctly.'],
    ];
    $switch = [
        'host' => 'mail.example.test', 'ips' => ['203.0.113.10'], 'resolved' => true,
        'configured' => ['port' => 587, 'security' => 'tls', 'label' => 'STARTTLS'],
        'probes' => [
            ['ok' => false, 'stage' => 'greeting', 'detail' => 'The SMTP server did not answer during greeting within the time limit.',
             'capabilities' => [], 'banner' => '', 'ms' => 4000, 'configured' => true, 'port' => 587, 'security' => 'tls', 'label' => 'STARTTLS'],
            ['ok' => true, 'stage' => 'ready', 'detail' => 'Greeting and EHLO succeeded with implicit TLS. Authentication was not attempted.',
             'capabilities' => ['AUTH'], 'banner' => '220 mail.example.test ESMTP', 'ms' => 55, 'configured' => false, 'port' => 465, 'security' => 'ssl', 'label' => 'implicit TLS', 'auth' => ['PLAIN']],
        ],
        'recommendation' => ['code' => 'switch', 'port' => 465, 'security' => 'ssl',
            'text' => 'Port 465 with implicit TLS answers correctly but your saved setting is port 587 with STARTTLS. Switch and save, then send a test message.'],
    ];
    $blocked = [
        'host' => 'mail.example.test', 'ips' => [], 'resolved' => false,
        'configured' => ['port' => 587, 'security' => 'tls', 'label' => 'STARTTLS'],
        'probes' => [[
            'ok' => false, 'stage' => 'connection', 'detail' => 'Connection timed out: a firewall or your hosting provider is very likely blocking outbound traffic to port 587.',
            'capabilities' => [], 'banner' => '', 'ms' => 4000, 'configured' => true, 'port' => 587, 'security' => 'tls', 'label' => 'STARTTLS',
        ]],
        'recommendation' => ['code' => 'blocked', 'text' => 'None of the mail ports answered from this server.'],
    ];

    $okHtml = renderView($healthy);
    check(str_contains($okHtml, 'What this server can reach'), 'healthy diagnosis renders');
    check(str_contains($okHtml, 'Connection healthy'), 'healthy badge');
    check(str_contains($okHtml, '203.0.113.10'), 'resolved address is shown');
    check(!str_contains($okHtml, 'Use port'), 'no switch offered when healthy');

    $switchHtml = renderView($switch);
    check(str_contains($switchHtml, 'Use port 465 with implicit TLS'), 'switch button names port and mode');
    check(str_contains($switchHtml, 'name="action" value="apply-transport"'), 'switch posts the transport action');
    check(str_contains($switchHtml, 'Different port works'), 'switch badge');
    check(str_contains($switchHtml, 'Saved setting'), 'configured probe is labelled');

    $blockedHtml = renderView($blocked);
    check(str_contains($blockedHtml, 'No working port'), 'blocked badge');
    check(str_contains($blockedHtml, 'check the spelling'), 'missing DNS is called out');
    check(str_contains($blockedHtml, 'blocking outbound traffic'), 'provider blocking is explained');

    $plain = renderView([]);
    check(!str_contains($plain, 'What this server can reach'), 'no diagnosis panel without a diagnosis');
    check(str_contains($plain, 'Diagnose connection'), 'the diagnose button is always offered');

    echo "SMTP view render tests passed.\n";
}
