<?php
/**
 * DGV7 phase 3 — provider smoke test.
 *
 * Safe to run on any host, including one with no database and no keys. It reports
 * the configuration state of every registered provider (Klasha and Flutterwave at
 * the time of writing) and does NOTHING else: no HTTP request is made, no
 * credential is read from a live tenant, and no endpoint is called. An empty base
 * URL or endpoint path is reported as "not configured" rather than guessed at.
 *
 * Usage:
 *   php VTU/tests/provider_smoke.php [edition]            # registry only, no DB
 *   php VTU/tests/provider_smoke.php [edition] --vendor=N # also read stored keys
 *
 * Exit code is 0 whenever the check ran, 2 on usage error.
 */

// Providers covered: klasha, flutterwave, platnova, oneglobal. The registry is the
// source of truth, so a provider added there is reported here automatically.
// Since §26 the registry ships the documented endpoints; what keeps a provider
// "not configured" here is missing credentials and the deliberately-unmapped
// pieces (e.g. Klasha's undocumented webhook signature, Flutterwave's
// undocumented virtual-card paths).
$root = dirname(__DIR__);
$args = (isset($argv) && is_array($argv)) ? array_slice($argv, 1) : [];
$edition = 'DGV7.0-SAAS';
$vendor = 0;
foreach ($args as $arg) {
    if (strpos($arg, '--vendor=') === 0) {
        $vendor = (int)substr($arg, 9);
    } elseif (strpos($arg, '--') !== 0) {
        $edition = $arg;
    }
}

$provider_file = $root . '/' . $edition . '/func/bc-fintech-providers.php';
if (!is_file($provider_file)) {
    fwrite(STDERR, "unknown edition: {$edition}\n");
    exit(2);
}
require $provider_file;

// Optional credential lookup. Deliberately NOT automatic: connecting to a database
// is a side effect, so it only happens when the operator asks for a tenant.
$connection_server = null;
if ($vendor > 0) {
    $connect = $root . '/' . $edition . '/func/bc-connect.php';
    if (is_file($connect)) {
        require $connect;
    }
}

echo "DGV7 provider smoke test\n";
echo "edition: {$edition}\n";
echo str_repeat('-', 64) . "\n";

$unconfigured = 0;
foreach (bc_fintech_provider_list() as $slug) {
    $config = bc_fintech_provider_config($slug);
    if ($config === null) {
        echo str_pad($slug, 14) . "not configured (unknown provider)\n";
        $unconfigured++;
        continue;
    }

    $problems = [];
    if (trim((string)($config['base_url'] ?? '')) === '') {
        $problems[] = 'base URL empty';
    }
    $empty_endpoints = 0;
    foreach ((array)($config['endpoints'] ?? []) as $path) {
        if (trim((string)$path) === '') $empty_endpoints++;
    }
    if ($empty_endpoints > 0) {
        $problems[] = $empty_endpoints . ' endpoint path(s) empty';
    }
    if (trim((string)($config['signature']['header'] ?? '')) === '') {
        $problems[] = 'signature scheme empty';
    }
    $empty_fields = 0;
    foreach ((array)($config['fields'] ?? []) as $path) {
        if (trim((string)$path) === '') $empty_fields++;
    }
    if ($empty_fields > 0) {
        $problems[] = $empty_fields . ' payload field(s) unmapped';
    }

    // Credentials are only inspected when a tenant was explicitly named.
    $credential_note = 'not checked (no --vendor)';
    if ($vendor > 0) {
        $creds = bc_fintech_provider_credentials($vendor, $slug);
        if (!$creds['found']) {
            $credential_note = 'no stored credentials';
            $problems[] = 'no stored credentials';
        } elseif (trim((string)$creds['secret_key']) === '') {
            $credential_note = 'stored row has no secret key';
            $problems[] = 'stored row has no secret key';
        } else {
            $credential_note = 'credentials present (' . $creds['source'] . ')';
        }
    }

    if ($problems) {
        echo str_pad($slug, 14) . "not configured (" . implode('; ', $problems) . ")\n";
        $unconfigured++;
    } else {
        echo str_pad($slug, 14) . "configured ({$credential_note})\n";
    }
}

echo str_repeat('-', 64) . "\n";
if ($unconfigured > 0) {
    echo "{$unconfigured} provider(s) not configured. No provider calls were made.\n";
    echo "Save credentials in bc-admin (Payment Gateways / Service Control),\n";
    echo "then re-run. Endpoints follow the official provider docs (§26).\n";
} else {
    echo "Every provider is configured. This script made no provider calls.\n";
}
exit(0);
