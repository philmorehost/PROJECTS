<?php
declare(strict_types=1);

namespace RISE63\Core;

use function RISE63\{base_path, setting, site_title, url};

/**
 * The metadata a link preview needs.
 *
 * Reported: pasting the site link into WhatsApp produced a wall of raw markup instead of a preview.
 * Two causes, both fixed here:
 *
 *   1. **The response was declared `text/plain`.** A browser guesses its way to a usable page anyway,
 *      so nobody noticed; a scraper does not guess. That is fixed in `Security::sendHtmlHeaders()`.
 *   2. **There was nothing to preview.** No `og:` tags existed anywhere in the application, so even a
 *      correctly-typed page rendered as a bare title and a paragraph of body text.
 *
 * A link preview needs four things: an absolute URL, a title, a description, and an image the
 * scraper can fetch without signing in. Open Graph is read by WhatsApp, Facebook, LinkedIn, Slack,
 * Telegram and iMessage; the `twitter:` tags are what X reads; the plain `description` is what
 * search engines and every other client falls back to.
 *
 * Everything here is unsigned-in on purpose. A preview scraper never carries a session, so any URL
 * behind a login renders as the sign-in page — this is why the tags are emitted on the public pages
 * and the image lives in `public/`.
 */
final class Share
{
    /**
     * The shared card image, relative to the site root.
     *
     * JPEG rather than PNG: the card is a gradient, so a palette PNG of it was 477 KB against 48 KB
     * as a JPEG, and a preview scraper on a slow connection gives up long before that. Every client
     * that reads Open Graph fetches JPEG.
     */
    public const IMAGE = 'public/icons/share.jpg';

    /** Width/height of that image, so a preview reserves the right space before loading it. */
    public const IMAGE_WIDTH  = 1200;
    public const IMAGE_HEIGHT = 630;

    /**
     * The site's own absolute base URL.
     *
     * Order matters: an explicit `site_url` setting wins, because an operator behind a proxy or on a
     * host whose `HTTP_HOST` is an internal name knows their public URL better than this code does.
     * Otherwise it is derived from the request, honouring the same trusted-proxy header that
     * `Security::isHttps()` already trusts — a site behind a TLS-terminating proxy would otherwise
     * advertise `http://`, and a scraper fetching that finds a redirect it will not follow.
     */
    public static function baseUrl(): string
    {
        $configured = trim((string) setting('site_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = Security::isHttps();
        $host  = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$/', $host) !== 1) {
            // A malformed or absent Host must not end up in a URL that goes out to the world.
            $host = 'localhost';
        }

        return ($https ? 'https' : 'http') . '://' . $host . base_path();
    }

    /** Absolute URL for a path — what a scraper needs, where `url()` returns a site-relative path. */
    public static function absolute(string $path = ''): string
    {
        $base = self::baseUrl();
        $path = ltrim($path, '/');
        return $path === '' ? $base . '/' : $base . '/' . $path;
    }

    /** Absolute URL of the share card image. */
    public static function imageUrl(): string
    {
        // Versioned so a redesigned card is not masked for months by a scraper's cache. Scrapers
        // key their cache on the URL, so changing the query string is the only way to invalidate it.
        return self::absolute(self::IMAGE) . '?v=' . substr(hash('sha256', site_title()), 0, 8);
    }

    /**
     * Everything the `<head>` needs, for one page.
     *
     * @return array{
     *     title:string, description:string, url:string, image:string,
     *     image_width:int, image_height:int, site_name:string, type:string
     * }
     */
    public static function meta(?string $title = null, ?string $description = null): array
    {
        $site = site_title();
        $page = trim((string) $title);

        return [
            'title'       => $page !== '' && $page !== $site ? $page . ' · ' . $site : $site,
            'description' => self::describe($description),
            'url'         => self::currentUrl(),
            'image'       => self::imageUrl(),
            'image_width' => self::IMAGE_WIDTH,
            'image_height' => self::IMAGE_HEIGHT,
            'site_name'   => $site,
            'type'        => 'website',
        ];
    }

    /**
     * A description that works in a chat card: one line, no markup, physically short enough that
     * WhatsApp does not truncate it mid-word. Falls back to the tagline, then to a sentence that is
     * true of every installation.
     */
    public static function describe(?string $text = null): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)) ?? '');
        if ($text === '') {
            $text = trim((string) setting('site_tagline', ''));
        }
        if ($text === '') {
            $text = 'RCCG Lagos Province 63 — parish directory, member records and transparent programme accounting.';
        }
        return mb_substr($text, 0, 200);
    }

    /**
     * The absolute URL of the page being rendered.
     *
     * Built from the request path and a whitelist of query parameters. The whole query string is
     * deliberately *not* echoed: a URL that arrives with somebody's search term or a stray token in
     * it would be published to every chat the link is pasted into, and would make two visits to the
     * same page look like two different URLs to a scraper's cache.
     */
    public static function currentUrl(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        // Strip any traversal or control characters rather than sending them out as a share URL.
        $path = '/' . ltrim(preg_replace('#/+#', '/', $path) ?? $path, '/');
        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            $path = '/';
        }

        $keep = [];
        foreach (['page', 'id'] as $key) {
            $value = $_GET[$key] ?? null;
            if (is_string($value) && $value !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) === 1) {
                $keep[$key] = $value;
            }
        }

        $base = self::baseUrl();
        // base_path() already prefixes the request path for a subdirectory install, so compare only
        // the remainder before appending — otherwise /rise63/rise63/login.
        $basePath = base_path();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $url = $base . '/' . ltrim($path, '/');
        if ($keep !== []) {
            $url .= '?' . http_build_query($keep);
        }
        return $url;
    }
}
