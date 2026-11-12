<?php
declare(strict_types=1);

namespace RISE63\Core;

/**
 * Minimal view renderer. Templates receive only the data they are given; no template ever
 * queries the database, which keeps SQL out of markup and makes each page easy to test.
 */
final class View
{
    public static function render(string $template, array $data = [], bool $layout = true): void
    {
        $file = self::templatePath($template);
        $data['view'] = $template;
        echo self::capture($file, $data, $layout);
    }

    /**
     * Resolve a template name to a file inside app/Views, or refuse.
     *
     * The name was concatenated into a path with no containment check. Nothing passes request input
     * today — the router reduces a page name to `[a-z0-9-]` before it is ever used — so this was
     * latent rather than exploitable. It is fixed anyway: a renderer that will `require` whatever
     * path it is handed is one careless call away from being a remote-code-execution primitive, and
     * the guard costs one `realpath()`.
     */
    private static function templatePath(string $template): string
    {
        if (preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $template) !== 1) {
            throw new \RuntimeException('Not a valid view name: ' . $template);
        }

        $root = realpath(\RISE63\ROOT . '/app/Views');
        $file = realpath(\RISE63\ROOT . '/app/Views/' . $template . '.php');
        if ($root === false || $file === false || !is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        if (!str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        return $file;
    }

    public static function capture(string $file, array $data, bool $layout): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        $content = (string) ob_get_clean();
        if (!$layout) {
            return $content;
        }

        // Which shell wraps this view. The application has one document (layout.php); the public
        // site has another (site/layout.php), because it must not carry the signed-in chrome, the
        // install prompt or the theme toggle. The caller names the shell it wants with a 'layout'
        // key, and an unknown name is refused rather than silently falling back — a landing page
        // wrapped in the admin shell, or vice versa, is the bug this prevents.
        $requested = isset($data['layout']) && is_string($data['layout']) ? $data['layout'] : '';
        $layoutTemplate = $requested === '' || $requested === 'app' ? 'layout' : 'site/layout';
        if ($requested !== '' && $requested !== 'app' && $requested !== 'site') {
            throw new \RuntimeException('Unknown layout: ' . $requested);
        }
        $layoutFile = self::templatePath($layoutTemplate);
        ob_start();
        require $layoutFile;
        return (string) ob_get_clean();
    }
}
