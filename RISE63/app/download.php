<?php
declare(strict_types=1);

/**
 * Authenticated document download.
 *
 * Reached only through index.php for GET requests carrying action=document-download. A GET is
 * correct here: this is a read with no side effects, so it must not require a CSRF token (a
 * token would break "open in new tab" and browser download resumption).
 *
 * Three checks, in order: signed in → the application is in the caller's scope → the requested
 * file is actually attached to that application. The stored name is also re-validated against a
 * strict pattern so a crafted `file` parameter cannot walk out of storage/documents.
 */

use RISE63\Core\Applications;
use RISE63\Core\Auth;
use RISE63\Core\Security;

use function RISE63\path, RISE63\url;

$account = Auth::user();
if ($account === null) {
    \RISE63\redirect('login');
}

$stored = (string) ($_GET['file'] ?? '');
if (preg_match('/^[a-f0-9]{32}-[A-Za-z0-9._-]{1,80}$/D', $stored) !== 1) {
    Security::fail(404, 'Document not found.');
}

$application = Applications::find((int) ($_GET['id'] ?? 0), $account);
if ($application === null) {
    Security::fail(404, 'Document not found.');
}

$attached = false;
$displayName = 'document';
foreach (Applications::documents($application) as $document) {
    if (($document['stored'] ?? '') === $stored) {
        $attached    = true;
        $displayName = (string) ($document['name'] ?? 'document');
        break;
    }
}
if (!$attached) {
    Security::fail(404, 'Document not found.');
}

$file = path('storage/documents/' . $stored);
$real = realpath($file);
$root = realpath(path('storage/documents'));
if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
    Security::fail(404, 'Document not found.');
}

$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $displayName) ?: 'document';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Length: ' . (string) filesize($real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($real);
exit;
