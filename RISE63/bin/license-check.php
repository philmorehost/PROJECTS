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

// cli, but also phpdbg and embed: those are never a web request, and the test harness runs the
// embedded engine. A real request arrives as apache2handler, fpm-fcgi or cli-server.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit("Not available over HTTP.\n");
}

// Named streams: the STDOUT/STDERR constants do not exist in every SAPI, and a fatal here would
// hide the diagnosis this script exists to print.
$stdout = @fopen('php://stdout', 'w');
$stderr = @fopen('php://stderr', 'w');
$write = static function (string $text, bool $toErr = false) use ($stdout, $stderr): void {
    $handle = $toErr ? $stderr : $stdout;
    if (is_resource($handle)) {
        fwrite($handle, $text);
        return;
    }
    echo $text;
};

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
        $write("Usage: php bin/license-check.php [--domain=example.org] [--key=KEY] [--probe] [--save]\n");
        exit(0);
    } else {
        $write("Unknown option: {$argument}\n", true);
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

/** The provider's address as this host resolves it, or null when DNS fails. */
$resolves = static function (): ?string {
    $addresses = @gethostbynamel(Activation::MANAGER_HOST);
    return is_array($addresses) && $addresses !== [] ? $addresses[0] : null;
};

$hostName = trim((string) (gethostname() ?: ''));
// A server's own hostname is often not the site's domain (shared hosting, containers, CI), so the
// order is: what the operator passed, what the environment declares, then the machine name — and a
// machine name that is not a real domain is dropped rather than turned into a nonsense candidate.
$source  = 'server hostname';
$rawHost = $hostName;
if ($options['domain'] !== null && trim($options['domain']) !== '') {
    $rawHost = $options['domain'];
    $source  = '--domain';
} elseif (getenv('RISE63_DOMAIN')) {
    $rawHost = (string) getenv('RISE63_DOMAIN');
    $source  = 'RISE63_DOMAIN';
}
$domain = Activation::domainFromHost($rawHost);
if (!Activation::validDomain($domain)) {
    $domain = '';
}

$write("RISE 63 licence check\n" . str_repeat('─', 60) . "\n");
$write('Key source      : ' . ($options['key'] !== null ? 'command line' : ($stored !== null ? 'config/activation-key.php' : 'none found:')) . "\n");
$write('Key             : ' . $mask($key) . "\n");
$write('Server hostname : ' . ($hostName !== '' ? $hostName : '(unknown)') . "\n");
$write('Domain source   : ' . $source . "\n");
$write('Endpoint        : ' . Activation::ENDPOINT . "\n");
$write('Transport       : ' . (function_exists('curl_init') ? 'cURL' : 'PHP HTTPS stream (cURL not loaded)') . "\n");
$write('DNS             : ' . Activation::MANAGER_HOST . ' -> ' . ($resolves() ?? 'does NOT resolve here') . "\n");

if ($domain === '') {
    $write(
    "\nNo usable domain to check: \"" . $rawHost . '\" (from ' . $source . ") is not a domain name.\n"
    . "Pass the domain this site is served from:\n"
    . "  php bin/license-check.php --domain=buildroom.example.org" . ($options['probe'] ? ' --probe' : '') . "\n",
    true
);
    exit(2);
}
if ($key === null || $key === '') {
    $write("\nNo licence key stored. Create one at " . Activation::MANAGER_ORDER . "\n", true);
    exit(1);
}
if (!Activation::looksLikeKey((string) $key)) {
    $write("\n" . Activation::KEY_HINT . "\n", true);
    exit(1);
}

$write("\nManager answer\n" . str_repeat('─', 60) . "\n");

if ($options['probe']) {
    // Try every plausible spelling; the first that the provider accepts is the answer.
    $accepted    = null;
    $unreachable = 0;
    $tried       = 0;
    foreach ($candidates($domain) as $candidate) {
        $result = $activation->verify((string) $key, $candidate);
        $tried++;
        if (!empty($result['unreachable'])) {
            $unreachable++;
        }
        fprintf($stdout,
            "  %-42s %s\n",
            $candidate,
            $result['valid'] ? 'ACCEPTED' : 'rejected — ' . $result['message']
        );
        if ($result['valid'] && $accepted === null) {
            $accepted = $candidate;
        }
    }

    if ($accepted === null) {
        if ($unreachable === $tried) {
            // Every attempt failed at the transport layer, so nothing was decided. Saying the key is
            // bad here would be a false diagnosis — the problem is the network path.
            $write("\nNothing was decided: the provider could not be reached at all (" . $tried
                . " attempt(s), all transport failures).\n"
                . "This is a hosting question, not a licence one. Prove outbound HTTPS from this server:\n"
                . "  curl -sS -o /dev/null -w '%{http_code}\\n' " . Activation::ENDPOINT . "\n"
                . "A timeout or SSL error there explains it. Ask the host to allow outbound HTTPS to "
                . Activation::MANAGER_HOST . ", then run this again.\n", true);
            exit(2);
        }
        if ($unreachable > 0) {
            // Distinguish "no DNS" (this host cannot even resolve the provider) from "DNS fine, request
        // failed" (TLS, firewall, timeout) — the two need different people to fix them.
        $dns = $resolves();
        $write("\nThe provider was unreachable for " . $unreachable . ' of ' . $tried
            . " attempt(s), so this answer is not final.\n"
            . 'DNS check       : ' . Activation::MANAGER_HOST . ' '
            . ($dns === null ? 'could not be resolved here' : 'resolves to ' . $dns) . "\n"
            . ($dns === null
                ? "That is a resolver problem on this host, not a licence one.\n"
                : "DNS is fine, so the failure is TLS, a firewall rule or a timeout.\n")
            . "Re-run when outbound HTTPS to " . Activation::MANAGER_HOST . " is working.\n"
            . "If it still fails then, the key is unknown, expired or suspended, or its domain "
            . "limit is used up.\n", true);
            exit(2);
        }
        $write("\nNone of those spelling(s) were accepted for this key.\n"
            . "The provider answered every one of them, and refused the key for all of them. That means\n"
            . "the key is unknown, expired or suspended, or its domain limit is used up — not that your\n"
            . "domain is spelled wrongly. Check the licence in the provider's console and confirm the\n"
            . "key was copied in full.\n", true);
        exit(1);
    }

    $write("\nThe provider accepts the key for: " . $accepted . "\n");
    if ($options['save']) {
        $activation->storeKey((string) $key);
        $activation->rememberDomain($accepted);
        if (Activation::domainFromHost($accepted) !== $domain) {
            $write("NOTE: that is not this site's own name (" . $domain . "). Setup will now use "
                . $accepted . "; make sure that is intentional.\n");
        }
        $write("Saved           : config/activation-key.php (mode 0600) with the accepted domain recorded\n");
    } else {
        $write("Re-run with --save to record it and skip the licence step.\n");
    }
    exit(0);
}

$result = $options['key'] !== null
    ? $activation->verify((string) $key, $domain)
    : $activation->check($domain, true);

$write('Domain checked  : ' . $domain . "\n");
$write('Result          : ' . ($result['valid']
    ? 'VALID'
    : (!empty($result['unreachable']) ? 'UNDECIDED (provider unreachable)' : 'NOT VALID')) . "\n");
$write('Message         : ' . $result['message'] . "\n");
if (!empty($result['grace'])) {
    $write("Note            : the provider was unreachable; a previously confirmed site is on its grace window.\n");
}

if ($result['valid']) {
    if ($options['save']) {
        $activation->storeKey((string) $key);
        $activation->rememberDomain($domain);
        $write("Saved           : config/activation-key.php (mode 0600)\n");
    }
    $write("\nThis installation is licensed for " . $domain . ".\n");
    exit(0);
}

if (!empty($result['unreachable'])) {
    // Nothing was decided, so do not present this as a licence refusal.
    $write("\nNothing was decided: the provider could not be reached (" . $result['message'] . ")\n"
        . "The key may well be fine. Prove outbound HTTPS from this server first:\n"
        . "  curl -sS -o /dev/null -w '%{http_code}\\n' " . Activation::ENDPOINT . "\n"
        . "Then run this command again.\n", true);
    exit(2);
}

$write("\nWhat to do next\n" . str_repeat('─', 60) . "\n");
$write("1. Find the exact domain on your licence. Try the spellings:\n");
$write("     php bin/license-check.php" . ($options['key'] !== null ? ' --key=' . $mask((string) $key) : '') . " --probe\n");
$write("2. Open the provider's console and compare: " . Activation::MANAGER_SIGNIN . "\n");
$write("   Domains are limited and changes may be capped, so check before editing it there.\n");
$write("3. Confirm the key arrived in full — no leading/trailing spaces, one line only.\n");
$write("4. Prove the server can reach the provider at all:\n");
$write("     curl -sS -o /dev/null -w '%{http_code}\\n' " . Activation::ENDPOINT . "\n");
$write("   A timeout or an SSL error here is a host firewall question, not a licence one.\n");
exit(1);
