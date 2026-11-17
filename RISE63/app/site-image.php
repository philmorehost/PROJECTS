<?php
declare(strict_types=1);

/**
 * Serves an uploaded hero photograph.
 *
 * Reached only through index.php for GET requests carrying action=site-image. A GET is correct: this
 * is a read with no side effects, so it must not carry a CSRF token, and it must be cacheable.
 *
 * Why a PHP endpoint rather than a public directory:
 *
 *   - **Nothing uploaded can execute.** The file lives in storage/site/, which .htaccess and the
 *     development router both refuse. It is streamed from here with a fixed Content-Type taken from
 *     the extension this code controls, plus `X-Content-Type-Options: nosniff`. An uploaded file is
 *     never handed to the web server to interpret.
 *   - **The name is unguessable and validated again here.** The stored name is 16 hex characters and
 *     an extension; anything else is a 404 before the filesystem is touched.
 *   - **The response is immutable**, because the name is derived from random bytes and never
 *     rewritten. That keeps a hero photograph out of the page render entirely after the first load.
 */

use RISE63\Core\Security;

use function RISE63\path;

$stored = (string) ($_GET['file'] ?? '');
if (preg_match('/^[a-f0-9]{16}\.(jpg|png|webp)$/D', $stored) !== 1) {
    Security::fail(404, 'Image not found.');
}

$root = path('storage/site');
$real = realpath($root . '/' . $stored);
$base = realpath($root);
if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
    Security::fail(404, 'Image not found.');
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$type = $types[$extension] ?? null;
if ($type === null) {
    Security::fail(404, 'Image not found.');
}

$size = (int) filesize($real);
header('Content-Type: ' . $type);
header('Content-Length: ' . (string) $size);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
// A downloaded hero photograph must not be treated as a document that can run script.
header('Content-Disposition: inline; filename="' . $stored . '"');

readfile($real);
