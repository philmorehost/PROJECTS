<?php
declare(strict_types=1);

namespace KB;

const VERSION = '0.1.0';
const ROOT = __DIR__ . '/..';
const MIN_PHP = '8.2.0';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KB\\')) {
        $file = ROOT . '/app/' . str_replace('\\', '/', substr($class, 3)) . '.php';
        if (is_file($file)) require $file;
        else { $file = ROOT . '/app/Core/' . str_replace('\\', '/', substr($class, 3)) . '.php'; if (is_file($file)) require $file; }
    }
});

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function config_path(): string {
    return rtrim(getenv('KB_CONFIG_DIR') ?: dirname(ROOT) . '/kb-private-' . substr(hash('sha256', ROOT), 0, 10), '/');
}
function installed(): bool { return is_file(config_path() . '/site.php') && is_file(config_path() . '/installed.lock'); }
function config(?string $key = null, mixed $default = null, bool $reload = false): mixed {
    static $data;
    if ($reload) $data = null;
    if ($data === null) $data = is_file(config_path() . '/site.php') ? require config_path() . '/site.php' : [];
    return $key === null ? $data : ($data[$key] ?? $default);
}
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_path(): string {
    if (installed()) return rtrim((string)config('base_path'), '/');
    $name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(dirname($name), '/.');
    return $base === '/' ? '' : $base;
}
function url(string $path = '', array $query = []): string {
    return base_path() . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}
function asset(string $file): string { return url('assets/' . $file, ['v' => VERSION]); }
function redirect(string $path, int $status = 303): never { header('Location: ' . url($path), true, $status); exit; }
function redirect_query(string $path, array $query, int $status = 303): never { header('Location: ' . url($path,$query), true, $status); exit; }
function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function now(): string { return gmdate('Y-m-d H:i:s'); }
function request_id(): string { return bin2hex(random_bytes(8)); }
function post(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : '';
}
function query(string $key, string $default = ''): string {
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : '';
}
function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
function is_post(): bool { return method() === 'POST'; }
function valid_email(string $email): bool { return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
function valid_password(string $password): bool { return strlen($password) >= 12 && strlen($password) <= 72; }
function password_value(string $key): string { return is_string($_POST[$key] ?? null) ? $_POST[$key] : ''; }
function hash_password(string $password): string {
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT, defined('PASSWORD_ARGON2ID') ? [] : ['cost' => 12]);
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'][] = [$message, $type]; }
function take_flashes(): array { $items = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $items; }
function current_title(): string { return installed() ? Settings::get('site_title', 'KINGDOMBUILDERS') : 'KINGDOMBUILDERS'; }
function initial(): string { return mb_substr(current_title(), 0, 1, 'UTF-8'); }
function header_security(): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'; object-src 'none'");
    header('Cache-Control: private, no-store');
    if (Security::requestHttps()) header('Strict-Transport-Security: max-age=31536000');
}
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '28800');
    $sessionPath = getenv('KB_SESSION_DIR') ?: (string)config('session_path', '');
    if ($sessionPath !== '') { if (!is_dir($sessionPath)) mkdir($sessionPath,0700,true); session_save_path($sessionPath); }
    session_name('kb_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => base_path() ?: '/', 'secure' => Security::requestHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function render(string $view, array $data = [], int $status = 200): never {
    http_response_code($status);
    $data['title'] ??= current_title();
    $data['flashes'] = take_flashes();
    extract($data, EXTR_SKIP);
    require ROOT . '/app/Views/layout.php';
    exit;
}
function paginate(int $total, int $perPage = 20): array {
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($pages, max(1, (int)query('page', '1')));
    return [$page, $pages, ($page - 1) * $perPage];
}

set_exception_handler(static function (\Throwable $error): void {
    $reference = request_id();
    error_log('KB [' . $reference . '] ' . get_class($error) . ': ' . $error->getMessage());
    if (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        json_response(['error' => 'The request could not be completed. Please try again.', 'reference' => $reference], 500);
    }
    if (!headers_sent()) header_security();
    http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Temporarily unavailable</title><body style="font-family:system-ui;max-width:40rem;margin:10vh auto;padding:2rem"><h1>We’ll be right back.</h1><p>The service is temporarily unavailable. Please try again shortly.</p><p>Reference: ' . e($reference) . '</p></body></html>';
});

header_security();
start_session();
