<?php
declare(strict_types=1);

/**
 * Small JSON API used by the live-search combobox.
 *
 * Only signed-in users may query the directory, responses are no-store, and the payload is
 * deliberately limited to what a selector needs (no contacts, no youth figures).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use RISE63\Core\Auth;
use RISE63\Core\ParishDirectory;
use RISE63\Core\Security;

use function RISE63\installed;

if (!installed()) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'not-installed']);
    exit;
}

// A read-only JSON endpoint does not need a session; skipping it avoids a session file for
// every keystroke debounce and keeps the endpoint independent of login state.
Security::sendHeaders(false);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method-not-allowed']);
    exit;
}

$signedIn = Auth::user() !== null;
$term     = substr(trim((string) ($_GET['q'] ?? '')), 0, 80);

if (!$signedIn) {
    // The registration form needs the picker before anyone has an account. Anonymous callers
    // get a deliberately narrow answer: a minimum of three characters (so the directory cannot
    // be enumerated in one request), at most 15 rows, and only names — no codes or contacts.
    if (mb_strlen($term) < 3) {
        echo json_encode(['parishes' => [], 'term' => $term, 'hint' => 'Type at least three characters.']);
        exit;
    }
    $cookie = 'rise63_geo_lookups';
    $count  = (int) ($_COOKIE[$cookie] ?? 0);
    if ($count > 120) {
        http_response_code(429);
        echo json_encode(['error' => 'too-many-requests']);
        exit;
    }
    setcookie($cookie, (string) ($count + 1), ['expires' => time() + 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => Security::isHttps()]);
}

try {
    $parishes = ParishDirectory::search($term, $signedIn ? 40 : 15);
    if ($signedIn && $term === '' && $parishes === []) {
        $parishes = ParishDirectory::search('', 10);
    }
    if (!$signedIn) {
        $parishes = array_map(static fn (array $row): array => [
            'id'     => $row['id'],
            'label'  => $row['label'],
            'parish' => $row['parish'],
            'area'   => $row['area'],
            'zone'   => $row['zone'],
            'code'   => null,
        ], $parishes);
    }
    echo json_encode(['parishes' => $parishes, 'term' => $term], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $error) {
    error_log('RISE63 api: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'temporary-failure']);
}
