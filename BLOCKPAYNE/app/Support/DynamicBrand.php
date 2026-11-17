<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Dynamically generates the SVG Favicon and Brand Logo Badge using the First Letter of the Site Title
 * whenever no custom logo image has been uploaded by the administrator.
 */
final class DynamicBrand
{
    public static function firstLetter(?string $siteTitle = null): string
    {
        $title = trim($siteTitle ?? Settings::get('site_title', 'BlockPayne'));
        if ($title === '') {
            return 'B';
        }
        // Extract first alphanumeric/Unicode letter
        if (preg_match('/[\p{L}\p{N}]/u', $title, $m)) {
            return mb_strtoupper($m[0]);
        }
        return mb_strtoupper(mb_substr($title, 0, 1));
    }

    public static function accentColor(?string $color = null): string
    {
        $c = trim($color ?? Settings::get('brand_color', '#10b981'));
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#10b981';
    }

    /**
     * Generate crisp standalone SVG favicon / app icon markup from the first letter of Site Title.
     */
    public static function faviconSvg(?string $siteTitle = null, ?string $accent = null): string
    {
        $letter = htmlspecialchars(self::firstLetter($siteTitle), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $primary = self::accentColor($accent);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img" aria-label="{$letter}">
  <defs>
    <linearGradient id="bpGrad" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="#0f172a"/>
    </linearGradient>
  </defs>
  <rect x="2" y="2" width="60" height="60" rx="16" fill="#0f172a" stroke="{$primary}" stroke-width="3"/>
  <rect x="6" y="6" width="52" height="52" rx="12" fill="url(#bpGrad)" opacity="0.28"/>
  <text x="32" y="43" text-anchor="middle" font-family="Inter, system-ui, -apple-system, sans-serif" font-size="34" font-weight="800" fill="#f8fafc">{$letter}</text>
</svg>
SVG;
    }

    /**
     * Render inline HTML logo badge (uses custom logo URL if uploaded, otherwise dynamic First-Letter badge).
     */
    public static function renderLogoBadge(?string $siteTitle = null, string $sizeClass = 'bp-brand-badge'): string
    {
        $title = trim($siteTitle ?? Settings::get('site_title', 'BlockPayne'));
        $customLogo = trim(Settings::get('custom_logo_url', ''));
        if ($customLogo !== '' && preg_match('#^(https?://|/)#i', $customLogo)) {
            $safeUrl = bp_e($customLogo);
            $safeAlt = bp_e($title);
            return '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" class="' . bp_e($sizeClass) . '-img">';
        }

        $letter = bp_e(self::firstLetter($title));
        $accent = bp_e(self::accentColor());
        return '<span class="' . bp_e($sizeClass) . '" style="--bp-accent:' . $accent . ';" aria-hidden="true">' . $letter . '</span>';
    }
}
