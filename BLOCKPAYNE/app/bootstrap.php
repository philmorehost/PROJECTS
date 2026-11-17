<?php
declare(strict_types=1);

define('BP_ROOT', dirname(__DIR__));
define('BP_STORAGE', BP_ROOT . '/storage');
define('BP_VERSION', '1.0.0');

// Polyfills for minimal PHP environments (e.g. php-wasm) where mbstring may be omitted
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $str, ?string $encoding = null): int
    {
        return strlen((string) preg_replace('/./u', 'x', $str));
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $str, int $start, ?int $length = null, ?string $encoding = null): string
    {
        preg_match_all('/./us', $str, $matches);
        $chars = $matches[0] ?? [];
        $slice = $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length);
        return implode('', $slice);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $str, ?string $encoding = null): string
    {
        return strtoupper($str);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $str, ?string $encoding = null): string
    {
        return strtolower($str);
    }
}

// Self-contained PSR-4 autoloader for App\* -> app/*
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BP_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

\App\Support\Env::load(BP_ROOT . '/.env');
date_default_timezone_set(\App\Support\Env::get('APP_TIMEZONE', 'UTC') ?: 'UTC');

/**
 * HTML-escape any value safely.
 */
function bp_e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Check whether the installation lock file exists.
 */
function bp_installed(): bool
{
    return is_file(BP_STORAGE . '/installed');
}

/**
 * Compute base path when deployed either at domain root or a subdirectory (e.g. /BLOCKPAYNE).
 */
function bp_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = rtrim(dirname($script), '/');
    if (preg_match('#/(install|api|public)$#', $dir)) {
        $dir = rtrim(dirname($dir), '/');
    }
    return ($dir === '' || $dir === '.') ? '' : $dir;
}

/**
 * Generate an application URL relative to the deployment root.
 */
function bp_url(string $path = ''): string
{
    $base = bp_base_path();
    $clean = ltrim($path, '/');
    if ($clean === '') {
        return $base !== '' ? $base . '/' : '/';
    }
    return $base . '/' . $clean;
}

/**
 * Generate a full absolute URL including scheme & host (for webhooks & QR links).
 */
function bp_abs_url(string $path = ''): string
{
    $envUrl = trim((string) \App\Support\Env::get('APP_URL', ''));
    if ($envUrl !== '' && empty($_SERVER['HTTP_HOST'])) {
        return rtrim($envUrl, '/') . '/' . ltrim($path, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . bp_url($path);
}

/**
 * Static asset URL with cache-busting version query.
 */
function bp_asset(string $asset): string
{
    $rel = 'public/assets/' . ltrim($asset, '/');
    $full = BP_ROOT . '/' . $rel;
    $ver = is_file($full) ? (string) filemtime($full) : BP_VERSION;
    return bp_url($rel) . '?v=' . $ver;
}

/**
 * Flush embed/WASM response metadata (if active) and terminate PHP cleanly.
 */
function bp_halt(string $output = '', ?int $status = null): never
{
    if ($status !== null && !headers_sent()) {
        http_response_code($status);
    }
    if ($output !== '') {
        echo $output;
    }
    if (!empty($GLOBALS['BP_META_FILE'])) {
        @file_put_contents((string) $GLOBALS['BP_META_FILE'], json_encode([
            'status'   => $status ?? (http_response_code() ?: 200),
            'redirect' => $GLOBALS['BP_REDIRECT_TO'] ?? null,
            'session'  => $_SESSION ?? [],
        ]));
    }
    exit;
}

/**
 * Emit a Location redirect and exit (records target in $GLOBALS for embed/WASM test harnesses too).
 */
function bp_redirect(string $targetUrl, int $status = 302): never
{
    $GLOBALS['BP_REDIRECT_TO'] = $targetUrl;
    if (!headers_sent()) {
        http_response_code($status);
        header('Location: ' . $targetUrl, true, $status);
    }
    bp_halt('', $status);
}

/**
 * Stream a binary attachment download (such as `.zip` integration plugins) cleanly on cPanel and WASM preview.
 */
function bp_download_binary(string $filename, string $binary, string $mime = 'application/zip'): never
{
    $safeName = preg_replace('/[^A-Za-z0-9._\-]/', '-', $filename) ?: 'download.zip';
    if (!empty($GLOBALS['BP_META_FILE'])) {
        @file_put_contents((string) $GLOBALS['BP_META_FILE'], json_encode([
            'status'   => 200,
            'redirect' => null,
            'session'  => $_SESSION ?? [],
            'bin_b64'  => base64_encode($binary),
            'filename' => $safeName,
            'mime'     => $mime,
        ]));
        exit;
    }
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: no-store');
    }
    echo $binary;
    exit;
}

/**
 * Emit a JSON HTTP response and exit.
 */
function bp_json(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    bp_halt('', $status);
}

/**
 * Current UTC timestamp formatted for SQL TIMESTAMP columns.
 */
function bp_now(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

/**
 * Format fiat amounts cleanly (e.g., 25.00).
 */
function bp_money_fmt(string|float|int $val, int $decimals = 2): string
{
    return number_format((float) $val, $decimals, '.', ',');
}

/**
 * Format crypto amounts trimming trailing zeros while keeping at least 4 decimals.
 */
function bp_crypto_fmt(string|float|int $val): string
{
    $formatted = number_format((float) $val, 8, '.', '');
    $trimmed = rtrim($formatted, '0');
    $parts = explode('.', $trimmed);
    $dec = $parts[1] ?? '';
    if (strlen($dec) < 4) {
        $dec = str_pad($dec, 4, '0');
    }
    return $parts[0] . '.' . $dec;
}
