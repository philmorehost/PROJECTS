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
/**
 * URL base for the installation, e.g. "" at a domain root or "/rise63" in a subdirectory.
 *
 * Derived from the front controller's location, never from SCRIPT_NAME. Pages under /install/
 * and /api/ are included *by* index.php, so SCRIPT_NAME there points at the wrong directory:
 * that mismatch made /install/index.php ask for /install/public/assets/app.css (a 404) and the
 * setup wizard rendered as unstyled HTML.
 */
function base_path(): string
{
    // Cached per script path, not once per process: a CLI run or a long-lived worker can look at
    // more than one installation, and a cache keyed on nothing returned the first answer forever.
    static $base = null;
    static $forScript = null;
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if ($base !== null && $forScript === $script) {
        return $base;
    }
    $forScript = $script;

    // RISE63_BASE_PATH is an escape hatch for hosts whose document root maps elsewhere.
    $override = getenv('RISE63_BASE_PATH');
    if (is_string($override) && $override !== '') {
        return $base = ($override === '/' ? '' : '/' . trim($override, '/'));
    }

    $dir = rtrim(dirname(str_replace('\\', '/', $script)), '/.');

    // Included pages (install/, api/) sit one level below the front controller.
    foreach (['/install', '/api', '/bin'] as $nested) {
        if (str_ends_with($dir, $nested)) {
            $dir = substr($dir, 0, -strlen($nested));
            break;
        }
    }

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

/**
 * The page named by a request path, for the front controller.
 *
 * Every link and every redirect in this application emits a path segment (/login, /treasury),
 * but there is one entry point. Apache maps the segment to index.php with ?page=…; this function
 * is the fallback for when it does not (AllowOverride off, a rewrite the host strips, the
 * development router), so /login still works instead of returning an Apache "Not Found".
 *
 * Returns '' when the path names no page of its own: the site root, index.php itself, and the
 * sub-applications that have their own entry point (api/, install/).
 */
function page_from_path(string $requestUri): string
{
    // Decoded before segmenting, so an encoded traversal (/%2e%2e) is seen as '..' and filtered
    // out rather than becoming the page name "2e2e".
    $path = rawurldecode((string) (parse_url($requestUri, PHP_URL_PATH) ?: '/'));
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $segments = array_values(array_filter(
        explode('/', trim($path, '/')),
        static fn (string $segment): bool => $segment !== ''
    ));
    $first = $segments[0] ?? '';
    if ($first === '' || $first === 'index.php' || in_array($first, ['api', 'install'], true)) {
        return '';
    }
    // One segment is the page name; anything deeper is a page plus an identifier, which the page
    // controller locates itself. The remainder is never accepted as input here.
    return preg_replace('/[^a-z0-9-]/', '', strtolower($first)) ?? '';
}

/** Content-addressed public asset URL. Falls back to the unhashed name in development. */
function asset(string $file): string
{
    static $map;
    if ($map === null) {
        $map      = [];
        $manifest = ROOT . '/public/assets/manifest.php';
        if (is_file($manifest)) {
            $loaded = require $manifest;
            if (is_array($loaded)) {
                $map = $loaded;
            }
        }
    }

    // No manifest, or no entry for this file: fall back to a content fingerprint computed from the
    // file itself rather than to the bare name.
    //
    // This is not a nicety. The bare name is cached by browsers and by the service worker, so a
    // deploy that skipped `bin/build-assets.php` served the *previous* app.css beside the *new*
    // HTML: the markup was current, the stylesheet and script were a release behind, and the
    // result looked exactly like "the redesign was never applied" — or like a button that does
    // nothing. Deriving the fingerprint here makes the browser-side cache correct even when the
    // build step was forgotten, at the cost of one hash per asset per request.
    if (isset($map[$file])) {
        return url('public/assets/' . $map[$file]);
    }

    $path = ROOT . '/public/assets/' . $file;
    $hash = is_file($path) ? substr(hash('sha256', (string) file_get_contents($path)), 0, 8) : '';
    if ($hash === '') {
        return url('public/assets/' . $file);
    }
    $info = pathinfo($file);
    $name = $info['filename'] . '.' . $hash . '.' . ($info['extension'] ?? '');

    // Serve the fingerprinted copy if the build has already written it; otherwise the URL carries
    // the hash of the source, which is what the browser would ask for anyway.
    return url('public/assets/' . (is_file(ROOT . '/public/assets/' . $name) ? $name : $file) . '?v=' . $hash);
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

/**
 * An inline SVG icon, sized by CSS.
 *
 * The bottom bar previously used text glyphs (▤ ▦ ⌂ ₦ ◈ ⚙ ⏻). They are not the same width, they
 * sit at different heights, and several of them render as a different picture — or as a missing
 * box — depending on the device's font. A phone-style tab bar needs marks that are drawn the same
 * everywhere and that can be filled when the tab is active, so these are stroked paths that switch
 * to a filled state via CSS `currentColor`.
 *
 * Only a fixed set of names is accepted: the icon name is never interpolated into the markup.
 */
function icon(string $name, string $class = 'icon'): string
{
    $paths = [
        // A grid of four panes — "dashboard".
        'dashboard' => '<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/>'
            . '<rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>',
        // A page with lines — "applications".
        'applications' => '<path d="M6 3.5h8.5L19 8v12.5H6z"/><path d="M14.5 3.5V8H19"/><path d="M9 12h6M9 16h6"/>',
        // A house — "parish connect".
        'parish' => '<path d="M4 11 12 4l8 7"/><path d="M6.5 9.8V20h11V9.8"/><path d="M10 20v-5h4v5"/>',
        // A stacked-coin mark in place of a currency glyph, which renders as a box on many phones.
        'treasury' => '<circle cx="12" cy="12" r="8.5"/><path d="M9 13.5h4.2a2.1 2.1 0 0 0 0-4.2H9m0 0V7m0 6.5V16"/>',
        // A shield with a tick — "security".
        'security' => '<path d="M12 3.5 19 6v6c0 4.2-3 7.3-7 8.5-4-1.2-7-4.3-7-8.5V6z"/><path d="M9 12l2 2 4-4"/>',
        // A slider pair — "settings".
        'settings' => '<path d="M4 7h9M17 7h3M4 17h4M12 17h8"/><circle cx="15" cy="7" r="2.2"/><circle cx="10" cy="17" r="2.2"/>',
        // A star — member "my rise".
        'star' => '<path d="m12 3.8 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.8-5.2 2.8 1-5.8L3.5 10l5.9-.9z"/>',
        // A compass — "programs".
        'programs' => '<circle cx="12" cy="12" r="8.5"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
        // A door with an arrow — sign out.
        'logout' => '<path d="M14 8V5.5H5.5v13H14V16"/><path d="M10.5 12H21"/><path d="m17.5 8.5 3.5 3.5-3.5 3.5"/>',
        // A cloud with a slash — offline.
        'offline' => '<path d="M7 18.5h9.2a3.3 3.3 0 0 0 .6-6.5 5 5 0 0 0-8.3-3.4"/><path d="M4.5 4.5 19.5 19.5"/>',
        // A crossed-out phone/tower — nothing is reachable.
        'signal-off' => '<path d="M4 20h4M12 20h.01M20 20h.01"/><path d="M7 16.5a7 7 0 0 1 3-1.6"/>'
            . '<path d="M4.5 4.5 19.5 19.5"/>',
        // A plain check — "back online".
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    ];

    if (!isset($paths[$name])) {
        return '';
    }

    $class = preg_replace('/[^a-z0-9 -]/i', '', $class) ?? 'icon';

    return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}
