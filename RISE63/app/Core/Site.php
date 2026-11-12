<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;

/**
 * The public website: its pages, its hero slides, and the copy an administrator can change.
 *
 * Everything the visitor reads on the public side comes from here. The templates hold layout only —
 * if a sentence would need a release to change, it does not belong in a template.
 *
 * Two deliberate constraints:
 *
 *  1. **Pages are addressed by slug, never by id.** `/about` is the URL the footer prints and the
 *     link a WhatsApp forward carries. A slug can be changed, but it is validated hard and must be
 *     unique, because a duplicate slug would make one page silently unreachable.
 *  2. **The four footer pages cannot be deleted.** They are seeded with `is_system = 1`. An
 *     administrator may rewrite, unpublish or re-order them; deleting one would leave a footer link
 *     pointing at a 404 on a page nobody would think to check. Adding pages is unrestricted.
 */
final class Site
{
    /** The four pages the public footer always links to. Seeded by migration 0005. */
    public const SYSTEM_PAGES = ['about', 'vision', 'privacy', 'terms'];

    /** Where a CMS page body's headings come from. Kept small on purpose — see renderBody(). */
    private const MAX_BODY = 24000;
    private const MAX_SLIDES = 8;

    // ── Pages ────────────────────────────────────────────────────────────────────────

    /**
     * The published page for a slug, or null.
     *
     * Unpublished pages are null here rather than "rendered with a draft banner": a public URL that
     * serves an unpublished page is not unpublished. The administrator previews it from the admin
     * screen, where being signed in is the qualification.
     */
    public static function page(string $slug): ?array
    {
        $slug = self::slug($slug);
        if ($slug === '') {
            return null;
        }
        $row = Database::one(
            'SELECT * FROM site_pages WHERE slug = ? AND is_published = 1 LIMIT 1',
            [$slug]
        );
        return $row === null ? null : self::shapePage($row);
    }

    /** One page whatever its state — for the admin editor, which must open a draft. */
    public static function pageForEditing(string $slug): ?array
    {
        $slug = self::slug($slug);
        $row  = $slug === '' ? null : Database::one('SELECT * FROM site_pages WHERE slug = ? LIMIT 1', [$slug]);
        return $row === null ? null : self::shapePage($row);
    }

    /**
     * Every page, published or not, in the order an administrator arranged them.
     *
     * @return list<array<string, mixed>>
     */
    public static function pages(): array
    {
        return array_map(
            static fn (array $row): array => self::shapePage($row),
            Database::all('SELECT * FROM site_pages ORDER BY sort_order, title')
        );
    }

    /**
     * The pages the public footer and navigation show.
     *
     * @return list<array<string, mixed>>
     */
    public static function publishedPages(): array
    {
        return array_values(array_filter(self::pages(), static fn (array $p): bool => (int) $p['is_published'] === 1));
    }

    /**
     * Create or update a page.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> the stored page
     */
    public static function savePage(array $input, ?int $actorId = null): array
    {
        $slug = self::slug((string) ($input['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('Give the page an address: letters, numbers and hyphens.');
        }
        if (in_array($slug, ['login', 'register', 'api', 'install', 'offline', 'forgot-password', 'reset-password'], true)) {
            // These are real routes. A page that shadows one would be unreachable and would make the
            // real one ambiguous, so it is refused at the point of the mistake.
            throw new InvalidArgumentException('“' . $slug . '” is used by the application. Choose another address.');
        }

        $title   = self::text((string) ($input['title'] ?? ''), 150);
        $kicker  = self::text((string) ($input['kicker'] ?? ''), 120);
        $summary = self::text((string) ($input['summary'] ?? ''), 300);
        $body    = self::text((string) ($input['body'] ?? ''), self::MAX_BODY);
        if ($title === '') {
            throw new InvalidArgumentException('The page needs a title.');
        }

        $published = !empty($input['is_published']) ? 1 : 0;
        $order     = self::order($input['sort_order'] ?? 100);

        $existing = Database::one('SELECT * FROM site_pages WHERE slug = ? LIMIT 1', [$slug]);
        if ($existing === null) {
            Database::run(
                'INSERT INTO site_pages (slug, title, kicker, summary, body, is_published, is_system, sort_order, updated_by)
                 VALUES (?,?,?,?,?,?,0,?,?)',
                [$slug, $title, $kicker === '' ? null : $kicker, $summary === '' ? null : $summary,
                 $body, $published, $order, $actorId]
            );
        } else {
            Database::run(
                'UPDATE site_pages SET title = ?, kicker = ?, summary = ?, body = ?, is_published = ?, sort_order = ?, updated_by = ?
                  WHERE slug = ?',
                [$title, $kicker === '' ? null : $kicker, $summary === '' ? null : $summary,
                 $body, $published, $order, $actorId, $slug]
            );
        }

        $stored = self::pageForEditing($slug);
        if ($stored === null) {
            throw new InvalidArgumentException('The page could not be saved.');
        }
        return $stored;
    }

    /** Delete a page. System pages are refused: a footer link must never 404. */
    public static function deletePage(string $slug): void
    {
        $slug = self::slug($slug);
        $row  = $slug === '' ? null : Database::one('SELECT * FROM site_pages WHERE slug = ? LIMIT 1', [$slug]);
        if ($row === null) {
            throw new InvalidArgumentException('That page does not exist.');
        }
        if ((int) $row['is_system'] === 1) {
            throw new InvalidArgumentException(
                'This page is linked from every page footer, so it cannot be deleted. Unpublish it instead — '
                . 'the footer link then takes visitors to the contact page.'
            );
        }
        Database::run('DELETE FROM site_pages WHERE slug = ?', [$slug]);
    }

    // ── Hero slides ──────────────────────────────────────────────────────────────────

    /**
     * Slides for the public hero, in order.
     *
     * @param bool $publishedOnly the carousel passes true; the admin list passes false
     * @return list<array<string, mixed>>
     */
    public static function slides(bool $publishedOnly = true): array
    {
        $sql = 'SELECT * FROM site_slides'
            . ($publishedOnly ? ' WHERE is_published = 1' : '')
            . ' ORDER BY sort_order, id';
        try {
            return array_map(
                static fn (array $row): array => self::shapeSlide($row),
                Database::all($sql)
            );
        } catch (\Throwable $error) {
            // Before migration 0005 has run the table is absent. The landing page must still render —
            // it falls back to the built-in slides — because a schema that is one migration behind
            // must never be the reason the public site is down.
            error_log('RISE63 Site::slides: ' . $error->getMessage());
            return [];
        }
    }

    /** One slide, whatever its state. */
    public static function slide(int $id): ?array
    {
        $row = Database::one('SELECT * FROM site_slides WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::shapeSlide($row);
    }

    /**
     * Create or update a slide.
     *
     * @param array<string, mixed> $input
     */
    public static function saveSlide(array $input, ?int $id = null, ?int $actorId = null): int
    {
        $headline = self::text((string) ($input['headline'] ?? ''), 180);
        if ($headline === '') {
            throw new InvalidArgumentException('The slide needs a headline — it is the largest text on the page.');
        }
        $fields = [
            'kicker'   => self::text((string) ($input['kicker'] ?? ''), 120),
            'headline' => $headline,
            'subhead'  => self::text((string) ($input['subhead'] ?? ''), 400),
            'body'     => self::text((string) ($input['body'] ?? ''), 600),
            'cta_label' => self::text((string) ($input['cta_label'] ?? ''), 60),
            'cta_page'  => self::slug((string) ($input['cta_page'] ?? '')),
            'tone'      => ((string) ($input['tone'] ?? 'ink')) === 'light' ? 'light' : 'ink',
            'is_published' => !empty($input['is_published']) ? 1 : 0,
            'sort_order'   => self::order($input['sort_order'] ?? 100),
        ];

        // A call to action needs both halves or neither: a label with no destination renders a button
        // that does nothing, which is worse than no button.
        if (($fields['cta_label'] === '') !== ($fields['cta_page'] === '')) {
            throw new InvalidArgumentException('A button needs both a label and a page to open — or leave both blank.');
        }

        $image = $input['image_path'] ?? null;
        $alt   = self::text((string) ($input['image_alt'] ?? ''), 200);
        if (is_string($image) && $image !== '') {
            if (preg_match('/^[a-f0-9]{16,32}\.(jpg|png|webp)$/D', $image) !== 1) {
                throw new InvalidArgumentException('That image reference is not valid.');
            }
            if ($alt === '') {
                // Enforced rather than advised: an undescribed hero image is invisible to a screen
                // reader, and "no text" is the one thing an image search cannot recover.
                throw new InvalidArgumentException('Describe the image in a few words — it is read aloud to blind visitors.');
            }
        }

        if ($id === null) {
            if (count(self::slides(false)) >= self::MAX_SLIDES) {
                throw new InvalidArgumentException(
                    'The hero holds ' . self::MAX_SLIDES . ' slides. Remove one before adding another — a carousel '
                    . 'nobody reaches the end of is worse than a shorter one.'
                );
            }
            Database::run(
                'INSERT INTO site_slides (kicker, headline, subhead, body, image_path, image_alt, cta_label, cta_page, tone, is_published, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$fields['kicker'] ?: null, $fields['headline'], $fields['subhead'] ?: null, $fields['body'] ?: null,
                 is_string($image) && $image !== '' ? $image : null, $alt ?: null,
                 $fields['cta_label'] ?: null, $fields['cta_page'] ?: null, $fields['tone'],
                 $fields['is_published'], $fields['sort_order']]
            );
            return Database::id();
        }

        $existing = self::slide($id);
        if ($existing === null) {
            throw new InvalidArgumentException('That slide does not exist.');
        }
        // No new image supplied means keep the old one. Reading that as "remove the image" would turn
        // a text correction into a visual one.
        $path = is_string($image) && $image !== '' ? $image : $existing['image_path'];
        $altOut = $alt !== '' ? $alt : ($path === null ? null : (string) $existing['image_alt']);

        Database::run(
            'UPDATE site_slides SET kicker = ?, headline = ?, subhead = ?, body = ?, image_path = ?, image_alt = ?,
                    cta_label = ?, cta_page = ?, tone = ?, is_published = ?, sort_order = ? WHERE id = ?',
            [$fields['kicker'] ?: null, $fields['headline'], $fields['subhead'] ?: null, $fields['body'] ?: null,
             $path, $altOut, $fields['cta_label'] ?: null, $fields['cta_page'] ?: null, $fields['tone'],
             $fields['is_published'], $fields['sort_order'], $id]
        );
        return $id;
    }

    public static function deleteSlide(int $id): void
    {
        Database::run('DELETE FROM site_slides WHERE id = ?', [$id]);
    }

    /** Move a slide up or down by swapping sort_order with its neighbour. */
    public static function moveSlide(int $id, string $direction): void
    {
        $all = self::slides(false);
        $index = null;
        foreach ($all as $i => $slide) {
            if ((int) $slide['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($all)) {
            return;
        }
        // Normalise first: two slides that share a sort_order would make the swap a no-op.
        foreach ($all as $i => $slide) {
            Database::run('UPDATE site_slides SET sort_order = ? WHERE id = ?', [($i + 1) * 10, (int) $slide['id']]);
        }
        $a = (int) $all[$index]['id'];
        $b = (int) $all[$swapWith]['id'];
        Database::run('UPDATE site_slides SET sort_order = ? WHERE id = ?', [($swapWith + 1) * 10, $a]);
        Database::run('UPDATE site_slides SET sort_order = ? WHERE id = ?', [($index + 1) * 10, $b]);
    }

    // ── Body rendering ───────────────────────────────────────────────────────────────

    /**
     * Turn a stored body into HTML.
     *
     * Deliberately **not** a rich-text field and deliberately not "trusted HTML": a textarea whose
     * contents are echoed raw is a stored-XSS hole with an administrator's own session as the payload
     * target, and this codebase refuses to add one. Instead a small, predictable convention:
     *
     *   - blank line separates blocks;
     *   - `## ` starts a heading, `### ` a sub-heading;
     *   - `- ` starts a list item;
     *   - `> ` is a pull quote;
     *   - anything else is a paragraph, with **bold** and *italic* inline.
     *
     * Every character is escaped first; the tags come from this method, never from the input. A body
     * pasted in from Word with stray HTML in it renders as text, which is the safe failure.
     */
    public static function renderBody(?string $body): string
    {
        $body = trim((string) $body);
        if ($body === '') {
            return '';
        }

        $blocks = preg_split('/\n\s*\n/', $body) ?: [];
        $html   = '';
        $listOpen = false;
        // Heading anchors must match SiteDefaults::toc(), or the contents list points at nothing.
        $usedAnchors = [];

        $inline = static function (string $text): string {
            $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            // **bold** first: doing italics first would eat the inner asterisks of `**x**`.
            $safe = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
            return (string) preg_replace('/(?<![\*\w])\*([^\*\n]+)\*(?!\*)/', '<em>$1</em>', $safe);
        };

        foreach ($blocks as $block) {
            $lines = preg_split('/\R/', trim($block)) ?: [];
            $first = $lines[0] ?? '';
            if (str_starts_with($first, '### ')) {
                $html .= ($listOpen ? '</ul>' : '') . '<h3>' . $inline(substr($first, 4)) . '</h3>';
                $listOpen = false;
                $lines = array_slice($lines, 1);
                if ($lines === []) {
                    continue;
                }
                $first = $lines[0];
            }
            if (str_starts_with($first, '## ')) {
                $titleText = substr($first, 3);
                $anchor = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($titleText)), '-');
                if ($anchor === '') {
                    $anchor = 'section';
                }
                $base = $anchor;
                $n = 2;
                while (isset($usedAnchors[$anchor])) {
                    $anchor = $base . '-' . $n;
                    $n++;
                }
                $usedAnchors[$anchor] = true;
                $html .= ($listOpen ? '</ul>' : '') . '<h2 id="' . $anchor . '">' . $inline($titleText) . '</h2>';
                $listOpen = false;
                $lines = array_slice($lines, 1);
                if ($lines === []) {
                    continue;
                }
                $first = $lines[0];
            }
            if (str_starts_with($first, '> ')) {
                $html .= ($listOpen ? '</ul>' : '') . '<blockquote>' . $inline(substr($first, 2)) . '</blockquote>';
                $listOpen = false;
                continue;
            }
            if (str_starts_with($first, '- ') || str_starts_with($first, '• ')) {
                if (!$listOpen) {
                    $html .= '<ul class="site-list">';
                    $listOpen = true;
                }
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, '- ') || str_starts_with($line, '• ')) {
                        $html .= '<li>' . $inline(substr($line, 2)) . '</li>';
                    } elseif ($line !== '') {
                        // A wrapped list item: the continuation belongs to the previous <li>.
                        $html = preg_replace('/<\/li>$/', ' ' . $inline($line) . '</li>', $html, 1) ?? $html;
                    }
                }
                continue;
            }
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            $paragraph = implode(' ', array_map('trim', $lines));
            $html .= '<p>' . $inline($paragraph) . '</p>';
        }

        return $html . ($listOpen ? '</ul>' : '');
    }

    /** A body with its markup stripped to a short summary — used for meta descriptions. */
    public static function plain(?string $body, int $limit = 160): string
    {
        $text = strip_tags(self::renderBody($body));
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────

    /**
     * A URL segment: lower-case letters, numbers and single hyphens.
     *
     * Anything else is dropped rather than rejected so that pasting "About Us" produces `about-us`
     * instead of an error the operator has to decode.
     */
    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim(substr($value, 0, 60), '-');
    }

    private static function text(string $value, int $max): string
    {
        // Control characters are stripped, not escaped: they are never intentional in published copy
        // and they corrupt the layout they land in.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", trim($value));
        return mb_substr($value, 0, $max);
    }

    private static function order(mixed $value): int
    {
        $int = (int) $value;
        return max(0, min(9999, $int === 0 ? 100 : $int));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapePage(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'slug'         => (string) $row['slug'],
            'title'        => (string) $row['title'],
            'kicker'       => $row['kicker'] === null ? '' : (string) $row['kicker'],
            'summary'      => $row['summary'] === null ? '' : (string) $row['summary'],
            'body'         => $row['body'] === null ? '' : (string) $row['body'],
            'is_published' => (int) ($row['is_published'] ?? 1),
            'is_system'    => (int) ($row['is_system'] ?? 0),
            'sort_order'   => (int) ($row['sort_order'] ?? 100),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shapeSlide(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'kicker'       => $row['kicker'] === null ? '' : (string) $row['kicker'],
            'headline'     => (string) $row['headline'],
            'subhead'      => $row['subhead'] === null ? '' : (string) $row['subhead'],
            'body'         => $row['body'] === null ? '' : (string) $row['body'],
            'image_path'   => $row['image_path'] === null ? null : (string) $row['image_path'],
            'image_alt'    => $row['image_alt'] === null ? '' : (string) $row['image_alt'],
            'cta_label'    => $row['cta_label'] === null ? '' : (string) $row['cta_label'],
            'cta_page'     => $row['cta_page'] === null ? '' : (string) $row['cta_page'],
            'tone'         => ((string) ($row['tone'] ?? 'ink')) === 'light' ? 'light' : 'ink',
            'is_published' => (int) ($row['is_published'] ?? 1),
            'sort_order'   => (int) ($row['sort_order'] ?? 100),
        ];
    }
}
