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
        $file = \RISE63\ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }
        $data['view'] = $template;
        echo self::capture($file, $data, $layout);
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
        $layoutFile = \RISE63\ROOT . '/app/Views/layout.php';
        ob_start();
        require $layoutFile;
        return (string) ob_get_clean();
    }
}
