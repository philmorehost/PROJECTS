<?php
declare(strict_types=1);

/**
 * Diagnose the licence from the server itself.
 *
 *   php bin/license-check.php                     the stored key, this host
 *   php bin/license-check.php --domain=example.org
 *   php bin/license-check.php --key=KEY            try a key without saving it
 *   php bin/license-check.php --probe              try the likely domain spellings of this host
 *   php bin/license-check.php --probe --domain=buildroom.example.org --save
 *
 * The provider answers "Invalid license key or domain" for *any* mismatch, and the wizard has to
 * send one exact string. --probe tries the handful of spellings a licence might have been issued
 * for (bare domain, www, subdomain, full host) and reports which one the provider accepts.
 *
 * The key is always printed masked. Exit codes: 0 licensed · 1 not licensed · 2 configuration.
 */

use RISE63\Core\Activation;

use function RISE63\config_dir;

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit("Not available over HTTP.\n");
}

$options = ['domain' => null, 'key' => null, 'save' => false, 'probe' => false];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if ($argument === '--save') {
        $options['save'] = true;
    } elseif ($argument === '--probe') {
        $options['probe'] = true;
    } elseif (str_starts_with($argument, '--domain=')) {
        $options['domain'] = substr($argument, 9);
    } elseif (str_starts_with($argument, '--key=')) {
        $options['key'] = substr($argument, 6);
    } elseif ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, "Usage: php bin/license-check.php [--domain=example.org] [--key=KEY] [--probe] [--save]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(2);
    }
}

$activation = new Activation(config_dir());
$stored     = $activation->storedKey();
$key        = $options['key'] ?? ($stored['key'] ?? null);

$mask = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '(none)';
    }
    if (strlen($value) <= 8) {
        return str_repeat('•', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('•', max(4, strlen($value) - 8)) . substr($value, -4);
};

/** The spellings a licence domain plausibly holds, most specific first. */
$candidates = static function (string $host): array {
    $host = Activation::domainFromHost($host);
    $www  = 'www.' . $host;
    $out  = [$host, $www];
    // buildroom.rccglp63yaya.org.ng → rccglp63yaya.org.ng → rccglp63yaya.org.ng is also tried
    // with www. Deeper suffixes are unlikely to be the licence target and are skipped.
    $parts = explode('.', $host);
    if (count($parts) >= 3) {
        $parent = implode('.', array_slice($parts, -3));
        $out[]  = $parent;
        $out[]  = 'www.' . $parent;
    }
    return array_values(array_unique(array_filter($out, [Activation::class, 'validDomain'])));
};

$host     = (string) ($options['domain'] ?? (getenv('RISE63_DOMAIN') ?: gethostname() ?: ''));
$domain   = Activation::domainFromHost($host);
$hostName = trim((string) (gethostname() ?: ''));

fwrite(STDOUT, "RISE 63 licence check\n" . str_repeat('─', 60) . "\n");
fwrite(STDOUT, 'Key source      : ' . ($options['key'] !== null ? 'command line' : ($stored !== null ? 'config/activation-key.php' : 'none found:')) . "\n");
fwrite(STDOUT, 'Key             : ' . $mask($key) . "\n");
fwrite(STDOUT, 'Server hostname : ' . ($hostName !== '' ? $hostName : '(unknown)') . "\n");
fwrite(STDOUT, 'Endpoint        : ' . Activation::ENDPOINT . "\n");
fwrite(STDOUT, 'Transport       : ' . (function_exists('curl_init') ? 'cURL' : 'PHP HTTPS stream (cURL not loaded)') . "\n");

if ($domain === '') {
    fwrite(STDERR, "\nNo domain to check. Pass --domain=buildroom.example.org\n");
    exit(2);
}
if ($key === null || $key === '') {
    fwrite(STDERR, "\nNo licence key stored. Create one at " . Activation::MANAGER_ORDER . "\n");
    exit(1);
}
if (!Activation::looksLikeKey((string) $key)) {
    fwrite(STDERR, "\n" . Activation::KEY_HINT . "\n");
    exit(1);
}

fwrite(STDOUT, "\nManager answer\n" . str_repeat('─', 60) . "\n");

if ($options['probe']) {
    // Try every plausible spelling; the first that the provider accepts is the answer.
    $accepted = null;
    foreach ($candidates($domain) as $candidate) {
        $result = $activation->verify((string) $key, $candidate);
        printf(
            "  %-42s %s\n",
            $candidate,
            $result['valid'] ? 'ACCEPTED' : 'rejected — ' . $result['message']
        );
        if ($result['valid'] && $accepted === null) {
            $accepted = $candidate;
        }
    }

    if ($accepted === null) {
        fwrite(STDOUT, "\nNone of those spelling(s) were accepted for this key.\n"
            . "That means the key itself is unknown, expired or suspended — or its domain limit has been\n"
            . "used up — not that your domain is spelled wrongly. Check the licence in the provider's\n"
            . "console and confirm the key was copied in full.\n");
        exit(1);
    }

    fwrite(STDOUT, "\nThe provider accepts the key for: " . $accepted . "\n");
    if ($options['save']) {
        $activation->storeKey((string) $key);
        $activation->rememberDomain($accepted);
        if (Activation::domainFromHost($accepted) !== $domain) {
            fwrite(STDOUT, "NOTE: that is not this site's own name (" . $domain . "). Setup will now use "
                . $accepted . "; make sure that is intentional.\n");
        }
        fwrite(STDOUT, "Saved           : config/activation-key.php (mode 0600) with the accepted domain recorded\n");
    } else {
        fwrite(STDOUT, "Re-run with --save to record it and skip the licence step.\n");
    }
    exit(0);
}

$result = $options['key'] !== null
    ? $activation->verify((string) $key, $domain)
    : $activation->check($domain, true);

fwrite(STDOUT, 'Domain checked  : ' . $domain . "\n");
fwrite(STDOUT, 'Result          : ' . ($result['valid'] ? 'VALID' : 'NOT VALID') . "\n");
fwrite(STDOUT, 'Message         : ' . $result['message'] . "\n");
if (!empty($result['grace'])) {
    fwrite(STDOUT, "Note            : the provider was unreachable; a previously confirmed site is on its grace window.\n");
}

if ($result['valid']) {
    if ($options['save']) {
        $activation->storeKey((string) $key);
        $activation->rememberDomain($domain);
        fwrite(STDOUT, "Saved           : config/activation-key.php (mode 0600)\n");
    }
    fwrite(STDOUT, "\nThis installation is licensed for " . $domain . ".\n");
    exit(0);
}

fwrite(STDOUT, "\nWhat to do next\n" . str_repeat('─', 60) . "\n");
fwrite(STDOUT, "1. Find the exact domain on your licence. Try the spellings:\n");
fwrite(STDOUT, "     php bin/license-check.php" . ($options['key'] !== null ? ' --key=' . $mask((string) $key) : '') . " --probe\n");
fwrite(STDOUT, "2. Open the provider's console and compare: " . Activation::MANAGER_SIGNIN . "\n");
fwrite(STDOUT, "   Domains are limited and changes may be capped, so check before editing it there.\n");
fwrite(STDOUT, "3. Confirm the key arrived in full — no leading/trailing spaces, one line only.\n");
fwrite(STDOUT, "4. Prove the server can reach the provider at all:\n");
fwrite(STDOUT, "     curl -sS -o /dev/null -w '%{http_code}\\n' " . Activation::ENDPOINT . "\n");
fwrite(STDOUT, "   A timeout or an SSL error here is a host firewall question, not a licence one.\n");
exit(1);
