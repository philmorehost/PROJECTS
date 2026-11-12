<?php
declare(strict_types=1);

/**
 * The public site layout.
 *
 * Deliberately a *separate* document from app/Views/layout.php. The application layout is a
 * signed-in workspace: a fixed tab bar, a top bar of admin links, a compact type scale tuned for
 * tables and forms. A landing page built on it would look like a form, which is the one thing it
 * must not look like.
 *
 * Both documents share the design tokens in app.css (brand colour, radii, the 16px type floor, light
 * and dark themes) so the public site and the app remain visibly one product. Everything else here is
 * arrangement.
 *
 * No inline <script>: the CSP allows only listed hashes, and a slide carousel written as an inline
 * block would be silently blocked in production. The behaviour is in public/assets/site.js.
 *
 * @var string $content
 * @var string $title
 * @var string $metaDescription
 * @var list<array<string,mixed>> $siteNav
 * @var string $sitePage  the slug of the page being viewed, for the active nav state
 */

use RISE63\Core\Security;
use RISE63\Core\Site;

use function RISE63\{asset, e, setting, site_title, url};

$account ??= null;
$sitePage ??= '';
$metaDescription ??= '';
$siteNav ??= [];
$brand = (string) (setting('theme_color', '#2563eb'));
$title = $title ?? 'RISE 63';
$whatsappRaw = (string) (setting('whatsapp_number', ''));
$whatsapp = preg_replace('/\D+/', '', $whatsappRaw) ?? '';
if (str_starts_with($whatsapp, '0')) {
    $whatsapp = '234' . substr($whatsapp, 1);
}
$whatsappText = rawurlencode((string) (setting('whatsapp_message', 'Hello RISE 63 — I would like to know more.')));
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($metaDescription) ?>">
<meta name="theme-color" content="<?= e($brand) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(site_title()) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($metaDescription) ?>">
<?php if (($ogImage ?? '') !== ''): ?>
<meta property="og:image" content="<?= e($ogImage) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(url('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('site.css')) ?>">
</head>
<body class="site<?= $sitePage === '' ? ' site-home' : '' ?>">

<a class="site-skip" href="#main">Skip to content</a>

<header class="site-header" data-site-header>
  <div class="site-wrap site-header-inner">
    <a class="site-brand" href="<?= e(url('')) ?>" aria-label="<?= e(site_title()) ?> — home">
      <span class="site-brand-mark" aria-hidden="true"><?= e(RISE63\favicon_letter()) ?></span>
      <span class="site-brand-text">
        <strong>RISE 63</strong>
        <small>Recognise · Inspire · Support · Empower</small>
      </span>
    </a>

    <nav class="site-nav" id="site-nav" aria-label="Main">
      <?php foreach ($siteNav as $item): ?>
        <a href="<?= e(url((string) $item['slug'])) ?>"
           <?= $sitePage === (string) $item['slug'] ? 'aria-current="page"' : '' ?>><?= e((string) $item['label']) ?></a>
      <?php endforeach; ?>
      <?php if ($whatsapp !== ''): ?>
        <a class="site-nav-wa" href="https://wa.me/<?= e($whatsapp) ?>?text=<?= e($whatsappText) ?>"
           target="_blank" rel="noopener">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.04 2c-5.5 0-9.96 4.46-9.96 9.96 0 1.76.46 3.45 1.32 4.95L2 22l5.25-1.37a9.9 9.9 0 0 0 4.79 1.22h.01c5.5 0 9.96-4.46 9.96-9.96S17.54 2 12.04 2Zm5.83 14.16c-.25.7-1.45 1.34-2 1.39-.55.05-1.06.24-3.6-.75-3.06-1.2-4.98-4.36-5.13-4.56-.15-.2-1.2-1.6-1.2-3.05 0-1.45.76-2.16 1.03-2.46.27-.3.59-.37.79-.37.2 0 .4 0 .57.01.18.01.43-.07.67.51.25.6.85 2.07.92 2.22.08.15.13.33.03.53-.1.2-.15.33-.3.51-.15.18-.32.4-.45.53-.15.15-.31.32-.13.62.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.65-.08.18-.2.75-.87.95-1.17.2-.3.4-.25.67-.15.28.1 1.75.83 2.05.98.3.15.5.23.57.35.08.13.08.75-.17 1.45Z"/></svg>
          <span>WhatsApp</span>
        </a>
      <?php endif; ?>
      <?php if ($account === null): ?>
        <a class="site-nav-cta" href="<?= e(url('login')) ?>">Member sign in</a>
      <?php else: ?>
        <a class="site-nav-cta" href="<?= e(url('my-rise')) ?>">Open dashboard</a>
      <?php endif; ?>
    </nav>

    <button class="site-nav-toggle" type="button" data-site-menu aria-expanded="false" aria-controls="site-nav">
      <span class="site-nav-toggle-bars" aria-hidden="true"></span>
      <span class="site-visually-hidden">Menu</span>
    </button>
  </div>
</header>

<main id="main" class="site-main">
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="site-wrap site-footer-grid">
    <div class="site-footer-brand">
      <span class="site-brand-mark" aria-hidden="true"><?= e(RISE63\favicon_letter()) ?></span>
      <p class="site-footer-tagline"><strong>RISE 63</strong><br>Learn. Build. Rise.</p>
      <p class="site-footer-note">
        A Youth Educational and Economic Empowerment Initiative of the LP63 YAYA CSR and the
        Young Professionals Club (YPC), RCCG Lagos Province 63.
      </p>
    </div>

    <nav class="site-footer-col" aria-label="Pages">
      <h2 class="site-footer-head">Explore</h2>
      <?php foreach ($siteNav as $item): ?>
        <a href="<?= e(url((string) $item['slug'])) ?>"><?= e((string) $item['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="site-footer-col">
      <h2 class="site-footer-head">Contact</h2>
      <?php if ($whatsapp !== ''): ?>
        <a href="https://wa.me/<?= e($whatsapp) ?>?text=<?= e($whatsappText) ?>" target="_blank" rel="noopener">
          Chat on WhatsApp
        </a>
      <?php endif; ?>
      <?php $email = (string) (setting('contact_email', '')) ?: (string) (setting('notification_email', '')); ?>
      <?php if (trim($email) !== ''): ?>
        <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>
      <?php endif; ?>
      <?php $phone = (string) (setting('support_phone', '')); ?>
      <?php if (trim($phone) !== ''): ?>
        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone) ?? '') ?>"><?= e($phone) ?></a>
      <?php endif; ?>
      <a class="site-footer-cta" href="<?= e(url('contact')) ?>">Send us a message</a>
    </div>
  </div>

  <div class="site-wrap site-footer-base">
    <p>&copy; <?= e(gmdate('Y')) ?> <?= e(site_title()) ?>. All rights reserved.</p>
    <p class="site-footer-legal">
      <?php /* Plain text, escaped once at output — the same rule the whole codebase follows. An
               entity written here renders correctly only while nobody escapes the label. */ ?>
      <?php foreach (['privacy' => 'Privacy Policy', 'terms' => 'Terms & Conditions'] as $slug => $label): ?>
        <a href="<?= e(url($slug)) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </p>
  </div>
</footer>

<?php if ($whatsapp !== ''): ?>
  <a class="site-wa-fab" href="https://wa.me/<?= e($whatsapp) ?>?text=<?= e($whatsappText) ?>"
     target="_blank" rel="noopener" aria-label="Chat with RISE 63 on WhatsApp">
    <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.04 2c-5.5 0-9.96 4.46-9.96 9.96 0 1.76.46 3.45 1.32 4.95L2 22l5.25-1.37a9.9 9.9 0 0 0 4.79 1.22h.01c5.5 0 9.96-4.46 9.96-9.96S17.54 2 12.04 2Zm5.83 14.16c-.25.7-1.45 1.34-2 1.39-.55.05-1.06.24-3.6-.75-3.06-1.2-4.98-4.36-5.13-4.56-.15-.2-1.2-1.6-1.2-3.05 0-1.45.76-2.16 1.03-2.46.27-.3.59-.37.79-.37.2 0 .4 0 .57.01.18.01.43-.07.67.51.25.6.85 2.07.92 2.22.08.15.13.33.03.53-.1.2-.15.33-.3.51-.15.18-.32.4-.45.53-.15.15-.31.32-.13.62.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.65-.08.18-.2.75-.87.95-1.17.2-.3.4-.25.67-.15.28.1 1.75.83 2.05.98.3.15.5.23.57.35.08.13.08.75-.17 1.45Z"/></svg>
  </a>
<?php endif; ?>

<script src="<?= e(asset('site.js')) ?>" defer></script>
</body>
</html>
