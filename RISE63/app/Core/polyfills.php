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
/**
 * ASCII-only upper-casing is not good enough here: church names are proper nouns and West African
 * parishes carry accented letters (Ọ, Á, È, Ẹ). strtoupper() leaves those untouched, so a name
 * typed with an accent would not match the same name typed without one and the directory would
 * quietly grow two rows for one church. These map the Latin-1 Supplement and Latin Extended-A
 * ranges, which covers everything the province actually uses.
 */
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $value, ?string $encoding = null): string
    {
        $upper = strtoupper($value);
        return strtr($upper, [
            'à' => 'À', 'á' => 'Á', 'â' => 'Â', 'ã' => 'Ã', 'ä' => 'Ä', 'å' => 'Å', 'æ' => 'Æ',
            'ç' => 'Ç', 'è' => 'È', 'é' => 'É', 'ê' => 'Ê', 'ë' => 'Ë', 'ì' => 'Ì', 'í' => 'Í',
            'î' => 'Î', 'ï' => 'Ï', 'ð' => 'Ð', 'ñ' => 'Ñ', 'ò' => 'Ò', 'ó' => 'Ó', 'ô' => 'Ô',
            'õ' => 'Õ', 'ö' => 'Ö', 'ø' => 'Ø', 'ù' => 'Ù', 'ú' => 'Ú', 'û' => 'Û', 'ü' => 'Ü',
            'ý' => 'Ý', 'þ' => 'Þ', 'ÿ' => 'Ÿ', 'ā' => 'Ā', 'ă' => 'Ă', 'ą' => 'Ą', 'ć' => 'Ć',
            'č' => 'Č', 'ď' => 'Ď', 'ē' => 'Ē', 'ę' => 'Ę', 'ě' => 'Ě', 'ğ' => 'Ğ', 'ī' => 'Ī',
            'į' => 'Į', 'ł' => 'Ł', 'ń' => 'Ń', 'ň' => 'Ň', 'ō' => 'Ō', 'ő' => 'Ő', 'œ' => 'Œ',
            'ř' => 'Ř', 'ś' => 'Ś', 'š' => 'Š', 'ş' => 'Ş', 'ť' => 'Ť', 'ū' => 'Ū', 'ů' => 'Ů',
            'ű' => 'Ű', 'ŵ' => 'Ŵ', 'ź' => 'Ź', 'ż' => 'Ż', 'ž' => 'Ž', 'ş' => 'Ş',
        ]);
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

