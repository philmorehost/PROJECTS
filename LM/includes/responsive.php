<?php
/**
 * Responsive layer — the one <head> include every page needs.
 *
 * The panel grew page by page, and each page carries its own <style> block
 * written for a desktop screen. Rewriting thirty stylesheets is a big diff and
 * a bigger risk; loading one extra file last is neither.
 *
 * Usage, immediately before </head>:
 *
 *     <?php require_once __DIR__ . '/includes/responsive.php'; lm_responsive_head(); ?>
 *
 * and from a page in admin/ or user/:
 *
 *     <?php require_once __DIR__ . '/../includes/responsive.php'; lm_responsive_head(); ?>
 *
 * It prints, in this order:
 *
 *   1. a viewport meta — only when nothing in the request already wrote one,
 *      so a page that says `viewport-fit=cover` keeps its own;
 *   2. a theme-color for the phone's browser chrome — again only if missing;
 *   3. assets/lm-responsive.css, cache-busted with the file's mtime;
 *   4. assets/lm-responsive.js, deferred so it never blocks the first paint.
 *
 * Including it twice prints once: the shared chomes (includes/ui.php for the
 * public and customer pages, admin/_ui.php for the release console) call it on
 * behalf of the pages they render.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/ui.php';

if (!function_exists('lm_responsive_sources')) {
    /**
     * Source of every file in the current render chain.
     *
     * The head is written by whichever file prints it — sometimes the page,
     * sometimes the shared chrome the page included. Reading all of them means
     * a page that already declares a viewport is never given a second one,
     * whether the tag sits in dashboard.php or in admin/_ui.php.
     *
     * Each file is read until the tag is found, and never more than 64 KB. The
     * search stops at the first hit, so the cost is one read of the page the
     * browser already asked for; 64 KB covers every <head> in this panel (the
     * largest is 15 KB in).
     *
     * The framework's own files are skipped. includes/security.php and db.php
     * both carry a viewport in the error pages they print, and neither is the
     * <head> of the page being rendered.
     *
     * @param string $needle Regex, without delimiters, to look for.
     * @return bool
     */
    function lm_responsive_sources_have($needle)
    {
        static $found = array();

        if (isset($found[$needle])) {
            return $found[$needle];
        }

        $found[$needle] = false;
        $skip = str_replace('\\', '/', lm_lm_dir() . '/includes/');
        $seen = array();
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);

        foreach ($trace as $frame) {
            if (empty($frame['file']) || $frame['file'] === __FILE__ || isset($seen[$frame['file']])) {
                continue;
            }

            $looks = str_replace('\\', '/', $frame['file']);
            $seen[$frame['file']] = true;

            if (strpos($looks, $skip) === 0 || substr($looks, -7) === '/db.php' || !@is_readable($frame['file'])) {
                continue;
            }

            $handle = @fopen($frame['file'], 'rb');
            if (!$handle) {
                continue;
            }

            $chunk = (string) fread($handle, 65536);
            fclose($handle);

            if (preg_match('#' . $needle . '#i', $chunk) === 1) {
                $found[$needle] = true;
                break;
            }
        }

        return $found[$needle];
    }
}

if (!function_exists('lm_responsive_head')) {
    /**
     * Print the viewport, the stylesheet and the script.
     *
     * @return void
     */
    function lm_responsive_head()
    {
        if (!empty($GLOBALS['lm_responsive_head_printed'])) {
            return;
        }
        $GLOBALS['lm_responsive_head_printed'] = true;

        $lines = array();

        if (!lm_responsive_sources_have('name=["\']viewport["\']')) {
            $lines[] = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        }

        if (!lm_responsive_sources_have('name=["\']theme-color["\']')) {
            $lines[] = '<meta name="theme-color" content="#0f172a">';
        }

        $lines[] = '<link rel="stylesheet" href="' . htmlspecialchars(lm_asset('lm-responsive.css')) . '">';
        $lines[] = '<script src="' . htmlspecialchars(lm_asset('lm-responsive.js')) . '" defer></script>';

        echo "\n    " . implode("\n    ", $lines) . "\n";
    }
}
