<?php
declare(strict_types=1);

namespace RISE63;

use RISE63\Core\Database;

/** Escape for HTML text and attribute contexts. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config_dir(): string
{
    if (!defined('RISE63\Core\CONFIG_DIR')) {
        $override = getenv('RISE63_CONFIG_DIR');
        define('RISE63\Core\CONFIG_DIR', rtrim($override !== false && $override !== '' ? $override : ROOT . '/config', '/'));
    }
    return \RISE63\Core\CONFIG_DIR;
}

function installed(): bool
{
    return is_file(config_dir() . '/installed.lock');
}

/** Base URL path for the application, e.g. "" or "/rise63". */
function base_path(): string
{
    static $base;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir    = rtrim(dirname($script), '/.');
    return $base = ($dir === '/' ? '' : $dir);
}

function url(string $path = '', array $query = []): string
{
    $path = ltrim($path, '/');
    $url  = base_path() . ($path === '' ? '/' : '/' . $path);
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

/** Content-addressed public asset URL. Falls back to the unhashed name in development. */
function asset(string $file): string
{
    static $map;
    if ($map === null) {
        $map     = [];
        $manifest = ROOT . '/public/assets/manifest.php';
        if (is_file($manifest)) {
            $loaded = require $manifest;
            if (is_array($loaded)) {
                $map = $loaded;
            }
        }
    }
    $name = $map[$file] ?? $file;
    return url('public/assets/' . $name);
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query), true, 303);
    exit;
}

/** Absolute path to a local asset file, used to emit inline critical markup when needed. */
function path(string $relative = ''): string
{
    return ROOT . '/' . ltrim($relative, '/');
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    if ($parts === []) {
        return 'R';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** First grapheme of the site title, used by the dynamic SVG favicon. */
function favicon_letter(): string
{
    $title = 'RISE 63';
    if (installed()) {
        try {
            $title = (string) \RISE63\Core\Settings::get('site_title', 'RISE 63');
        } catch (\Throwable) {
            $title = 'RISE 63';
        }
    }
    $title = trim($title);
    if ($title === '') {
        return 'R';
    }
    if (function_exists('grapheme_substr')) {
        $letter = grapheme_substr($title, 0, 1);
        if (is_string($letter) && $letter !== '') {
            return mb_strtoupper($letter);
        }
    }
    return mb_strtoupper(mb_substr($title, 0, 1));
}

/** Deterministic hue from a string so the favicon colour is stable per installation. */
function title_hue(string $seed): int
{
    return (int) (hexdec(substr(hash('sha256', $seed), 0, 4)) % 360);
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** @return list<array{message: string, type: string}> */
function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}

function human_date(?string $utc, bool $withTime = false): string
{
    if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
        return '—';
    }
    try {
        $date = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        $date = $date->setTimezone(new \DateTimeZone('Africa/Lagos'));
    } catch (\Throwable) {
        return '—';
    }
    return $date->format($withTime ? 'j M Y, g:ia' : 'j M Y');
}

function relative_time(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        $then = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    } catch (\Throwable) {
        return '—';
    }
    $diff = time() - $then->getTimestamp();
    if ($diff < 0) {
        return 'just now';
    }
    if ($diff < 60) {
        return $diff . 's ago';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . 'm ago';
    }
    if ($diff < 86400) {
        return intdiv($diff, 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return intdiv($diff, 86400) . 'd ago';
    }
    return human_date($utc);
}

/** Hours elapsed since a UTC datetime, used for the Education Bridge SLA badge. */
function hours_since(?string $utc): ?float
{
    if ($utc === null || $utc === '') {
        return null;
    }
    try {
        $then = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    } catch (\Throwable) {
        return null;
    }
    return max(0.0, (time() - $then->getTimestamp()) / 3600);
}

/** Tracked setting read with a defensive fallback so a broken setting never breaks a page. */
function setting(string $key, mixed $default = null): mixed
{
    try {
        return \RISE63\Core\Settings::get($key, $default);
    } catch (\Throwable) {
        return $default;
    }
}

function site_title(): string
{
    $title = (string) setting('site_title', 'RISE 63');
    return $title !== '' ? $title : 'RISE 63';
}

function request_ip(): string
{
    return \RISE63\Core\Security::clientIp();
}

function db(): \PDO
{
    return Database::connection();
}

/** Cheap static cache for reference lookups inside one request. */
function remember(string $key, callable $factory): mixed
{
    static $store = [];
    if (!array_key_exists($key, $store)) {
        $store[$key] = $factory();
    }
    return $store[$key];
}
