<?php
declare(strict_types=1);

/**
 * Share metadata for a public page.
 *
 * One partial, included by every `<head>` that can be reached without signing in, so the tags
 * cannot drift apart between the layout and the four auth pages — and so adding a tag means adding
 * it in one place. See `RISE63\Core\Share` for why each one exists.
 *
 * @var string|null $shareTitle
 * @var string|null $shareDescription
 * @var bool        $shareNoIndex  internal pages must never be indexed or previewed
 */

use RISE63\Core\Share;

use function RISE63\e;

$shareMeta = Share::meta(
    isset($shareTitle) ? (string) $shareTitle : null,
    isset($shareDescription) ? (string) $shareDescription : null
);

// A signed-in page is private by definition: it must not be indexed, and a preview card for it
// would show one person's page title to whoever they pasted the link to.
$shareNoIndex = (bool) ($shareNoIndex ?? false);
?>
<meta name="description" content="<?= e($shareMeta['description']) ?>">
<?php if ($shareNoIndex): ?>
<meta name="robots" content="noindex, nofollow">
<?php else: ?>
<link rel="canonical" href="<?= e($shareMeta['url']) ?>">
<meta property="og:type" content="<?= e($shareMeta['type']) ?>">
<meta property="og:site_name" content="<?= e($shareMeta['site_name']) ?>">
<meta property="og:title" content="<?= e($shareMeta['title']) ?>">
<meta property="og:description" content="<?= e($shareMeta['description']) ?>">
<meta property="og:url" content="<?= e($shareMeta['url']) ?>">
<meta property="og:image" content="<?= e($shareMeta['image']) ?>">
<meta property="og:image:width" content="<?= (int) $shareMeta['image_width'] ?>">
<meta property="og:image:height" content="<?= (int) $shareMeta['image_height'] ?>">
<meta property="og:image:alt" content="<?= e($shareMeta['site_name']) ?>">
<?php /* X, and every client that has not adopted Open Graph, reads these. */ ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($shareMeta['title']) ?>">
<meta name="twitter:description" content="<?= e($shareMeta['description']) ?>">
<meta name="twitter:image" content="<?= e($shareMeta['image']) ?>">
<?php endif; ?>
