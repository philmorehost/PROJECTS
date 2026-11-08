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

Security::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method-not-allowed']);
    exit;
}

if (Auth::user() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'authentication-required']);
    exit;
}

$term = substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
try {
    $parishes = ParishDirectory::search($term, 40);
    if ($term === '' && $parishes === []) {
        $parishes = ParishDirectory::search('', 10);
    }
    echo json_encode(['parishes' => $parishes, 'term' => $term], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $error) {
    error_log('RISE63 api: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'temporary-failure']);
}
