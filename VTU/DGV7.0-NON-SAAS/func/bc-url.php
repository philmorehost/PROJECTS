<?php
/**
 * DGV6.90 — Clean URL Helper
 * Provides bc_url() for generating extension-free links.
 *
 * Usage:
 *   href="<?php echo bc_url('/web/Login.php'); ?>"  → /web/login
 *   href="<?php echo bc_url('/bc-admin/Dashboard.php'); ?>"  → /bc-admin/dashboard
 *
 * The .htaccess rewrite rules handle the actual serving — this helper
 * just strips the extension from generated <a href> tags for clean markup.
 *
 * Backward compat: existing hardcoded .php hrefs still work via 301 redirect.
 */

if (function_exists('bc_url')) return;

/**
 * Returns a clean URL without .php extension.
 * Preserves query strings and anchors.
 */
function bc_url(string $path, array $params = []): string {
    // Split off any query string / fragment
    $fragment = '';
    if (str_contains($path, '#')) {
        $parts = explode('#', $path, 2);
        $path = $parts[0];
        $fragment = '#' . ($parts[1] ?? '');
    }

    $qs = '';
    if (str_contains($path, '?')) {
        $parts = explode('?', $path, 2);
        $path = $parts[0];
        $qs = $parts[1] ?? '';
    }

    // Strip .php extension
    $clean = preg_replace('/\.php$/i', '', $path);

    // Convert CamelCase filenames to kebab-case for nicer URLs (optional, opt-in)
    // e.g. AIMarketing → ai-marketing  (only applied if no slashes in the segment)
    // Disabled by default to maintain maximum compatibility
    // $clean = preg_replace_callback('/([a-z])([A-Z])/', fn($m) => $m[1].'-'.strtolower($m[2]), $clean);

    // Append any extra params
    if (!empty($params)) {
        $qs_parts = [];
        if ($qs) {
            parse_str($qs, $qs_parts);
        }
        $merged = array_merge($qs_parts, $params);
        $qs     = http_build_query($merged);
    }

    return $clean . ($qs ? '?' . $qs : '') . $fragment;
}

/**
 * Returns a full absolute URL without .php extension.
 */
function bc_abs_url(string $path, array $params = []): string {
    global $web_http_host;
    $host = $web_http_host ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return rtrim($host, '/') . '/' . ltrim(bc_url($path, $params), '/');
}

/**
 * Detect the current clean page name (without extension, without leading slash).
 * Useful for active nav highlighting.
 */
function bc_current_page(): string {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    return basename(preg_replace('/\.php$/i', '', $uri));
}
