<?php
declare(strict_types=1);

/**
 * Global-scope polyfills. Keep this file free of a namespace declaration: PHP resolves
 * unqualified function calls in a namespace by falling back to the global namespace only.
 */

// mbstring is recommended, not mandatory. These shims keep the app correct (if not perfectly
// Unicode-aware) on hosts that omit the extension; docs/DEPLOYMENT.md asks for it.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int
    {
        return strlen((string) preg_replace('/[\x80-\xBF]/', '', $value));
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $value, ?string $encoding = null): string
    {
        return strtoupper($value);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string
    {
        return strtolower($value);
    }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $value, int $start, int $width, string $trim = '', ?string $encoding = null): string
    {
        $slice = substr($value, $start, $width);
        return strlen($value) > $start + $width ? $slice . $trim : $slice;
    }
}

