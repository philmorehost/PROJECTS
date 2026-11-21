<?php
/** Removal policy only. No automatic database writes or remote requests. */
function bc_retired_vtu_provider($value) {
    if (!is_string($value)) return false;
    $value = strtolower(trim($value));
    if (in_array($value, array('legitdataway', 'bilalsadasub', 'legitdataway-com', 'bilalsadasub-com'), true)) return true;
    $url = strpos($value, '://') === false ? 'https://' . ltrim($value, '/') : $value;
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host)) return false;
    $host = rtrim($host, '.');
    foreach (array('legitdataway.com', 'bilalsadasub.com') as $domain) {
        if ($host === $domain || substr($host, -strlen('.'.$domain)) === '.'.$domain) return true;
    }
    return false;
}
function bc_retired_vtu_block_http_route() {
    if (PHP_SAPI === 'cli') return;
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('~/(?:webhook|func/api-gateway(?:/requery)?)/[^/]*(?:legitdataway|bilalsadasub)[^/]*\.php$~i', $path)) {
        http_response_code(410);
        exit('This provider integration has been removed.');
    }
}
