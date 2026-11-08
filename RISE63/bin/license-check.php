<?php
declare(strict_types=1);

/**
 * Diagnose the licence state from the server itself.
 *
 *   php bin/license-check.php                 use the stored key and this host
 *   php bin/license-check.php --domain=foo.ng check a different domain
 *   php bin/license-check.php --key=XXXX      try a key without saving it
 *   php bin/license-check.php --key=XXXX --save   try it, and keep it if it is valid
 *
 * Prints the key masked (first 4 and last 4 characters) and never writes it to a log.
 * Exit codes: 0 licensed · 1 not licensed · 2 configuration problem.
 */

use RISE63\Core\Activation;

use function RISE63\{config_dir, is_cli};

require dirname(__DIR__) . '/app/bootstrap.php';

if (!is_cli()) {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit("Not available over HTTP.\n");
}

$options = ['domain' => null, 'key' => null, 'save' => false];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if ($argument === '--save') {
        $options['save'] = true;
    } elseif (str_starts_with($argument, '--domain=')) {
        $options['domain'] = substr($argument, 9);
    } elseif (str_starts_with($argument, '--key=')) {
        $options['key'] = substr($argument, 6);
    } elseif ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, "Usage: php bin/license-check.php [--domain=example.org] [--key=KEY [--save]]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
}

$activation = new Activation(config_dir());
$stored     = $activation->storedKey();
$key        = $options['key'] ?? ($stored['key'] ?? null);
$host       = $options['domain'] ?? (getenv('RISE63_DOMAIN') ?: gethostname() ?: '');
$domain     = Activation::domainFromHost((string) $host);

$mask = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '(none)';
    }
    if (strlen($value) <= 8) {
        return str_repeat('•', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('•', max(4, strlen($value) - 8)) . substr($value, -4);
};

fwrite(STDOUT, "RISE 63 licence check\n");
fwrite(STDOUT, str_repeat('─', 52) . "\n");
fwrite(STDOUT, 'Key source      : ' . ($options['key'] !== null ? 'command line' : ($stored !== null ? 'config/activation-key.php' : 'none found')) . "\n");
fwrite(STDOUT, 'Key             : ' . $mask($key) . "\n");
fwrite(STDOUT, 'Domain checked  : ' . ($domain !== '' ? $domain : '(unknown — pass --domain=)') . "\n");
fwrite(STDOUT, 'Endpoint        : ' . Activation::ENDPOINT . "\n");
fwrite(STDOUT, 'Transport       : ' . (function_exists('curl_init') ? 'cURL' : 'PHP HTTPS stream (cURL not loaded)') . "\n");

if ($domain === '') {
    fwrite(STDERR, "\nCannot check without a domain. Pass --domain=buildroom.example.org\n");
    exit(2);
}
if ($key === null || $key === '') {
    fwrite(STDERR, "\nNo licence key stored. Create one at " . Activation::MANAGER_ORDER . "\n");
    exit(1);
}

$result = $options['key'] !== null
    ? $activation->verify((string) $key, $domain)
    : $activation->check($domain, true);

fwrite(STDOUT, "\nManager answer  : " . ($result['valid'] ? 'VALID' : 'NOT VALID') . "\n");
fwrite(STDOUT, 'Message         : ' . $result['message'] . "\n");
if (!empty($result['grace'])) {
    fwrite(STDOUT, "Note            : the manager was unreachable; a previously confirmed site is on its grace window.\n");
}

if ($result['valid'] && $options['save'] && $options['key'] !== null) {
    $activation->storeKey((string) $options['key']);
    fwrite(STDOUT, "Saved           : config/activation-key.php (mode 0600)\n");
}

if (!$result['valid']) {
    fwrite(STDOUT, "\nIf the key is right, check that the domain on the order form matches exactly\n"
        . "(no https://, no www.) and that this server can open an outbound HTTPS connection:\n"
        . "  curl -sS -o /dev/null -w '%{http_code}\\n' " . Activation::ENDPOINT . "\n");
    exit(1);
}

fwrite(STDOUT, "\nThis installation is licensed for " . $domain . ".\n");
exit(0);
