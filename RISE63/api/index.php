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

// Auth::user() reads the session. Skipping this include is fine for a signed-in caller and fatal
// for an anonymous one, which is the case that matters most here — the registration form. It is
// cheap: an empty session returns null without touching the database.
$signedIn = false;
try {
    Security::startSession();
    $signedIn = Auth::user() !== null;
} catch (\Throwable $error) {
    // Treat an unreadable session as anonymous rather than failing the search the member needs.
    error_log('RISE63 api: session unavailable: ' . $error->getMessage());
}

$term = substr(trim((string) ($_GET['q'] ?? '')), 0, 80);

// The rate limit is counted for anonymous callers only, and only against a *search*. An empty
// term asks for the head of the directory, which the registration page now renders itself, so it
// is both pointless to limit and counted more than once per page load.
$limitCookie = 'rise63_geo_lookups';
if (!$signedIn && mb_strlen($term) >= 3) {
    $count = (int) ($_COOKIE[$limitCookie] ?? 0);
    if ($count > 120) {
        http_response_code(429);
        echo json_encode(['error' => 'too-many-requests', 'parishes' => [], 'term' => $term]);
        exit;
    }
    setcookie($limitCookie, (string) ($count + 1), ['expires' => time() + 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => Security::isHttps()]);
}

try {
    $parishes = ParishDirectory::search($term, $signedIn ? 40 : 15);
    if ($term === '' && $parishes === []) {
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

    // `parishes` is always present, even when it is empty: the picker's only failure mode used to
    // be a silently empty list, and a member cannot tell "no church matches" from "the search did
    // not run". An empty list here is a real answer, and `hint` says what to do about it.
    $payload = ['parishes' => $parishes, 'term' => $term];
    if (!$signedIn && mb_strlen($term) < 3 && $term !== '') {
        $payload['hint'] = 'Type at least three characters.';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $error) {
    error_log('RISE63 api: ' . $error->getMessage());
    http_response_code(500);
    // Still a JSON body with the expected key, so the picker shows "the list is unavailable"
    // instead of silently rendering nothing.
    echo json_encode(['parishes' => [], 'term' => $term, 'error' => 'temporary-failure']);
}
