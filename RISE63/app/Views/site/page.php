<?php
declare(strict_types=1);

/**
 * A CMS page: Privacy Policy, Terms & Conditions, Vision, About Us, or anything added later.
 *
 * The body is rendered by Site::renderBody(), which escapes everything and emits only the small set
 * of tags it builds itself. A body pasted from Word with stray HTML in it therefore renders as text —
 * the safe failure for a field an administrator types into.
 *
 * @var array<string,mixed> $sitePageData
 * @var list<array<string,mixed>> $siteToc
 */

use RISE63\Core\Site;

use function RISE63\{e, url};

$page   = $sitePageData;
$body   = Site::renderBody((string) $page['body']);
$hasToc = count($siteToc) > 1;
?>

<article class="site-article">
  <header class="page-head">
    <div class="site-wrap">
      <nav class="crumbs" aria-label="Breadcrumb">
        <a href="<?= e(url('')) ?>">Home</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= e((string) $page['title']) ?></span>
      </nav>
      <?php if ((string) $page['kicker'] !== ''): ?>
        <p class="site-eyebrow"><span></span><?= e((string) $page['kicker']) ?></p>
      <?php endif; ?>
      <h1 class="page-title"><?= e((string) $page['title']) ?></h1>
      <?php if ((string) $page['summary'] !== ''): ?>
        <p class="page-summary"><?= e((string) $page['summary']) ?></p>
      <?php endif; ?>
    </div>
  </header>

  <div class="site-wrap article-grid<?= $hasToc ? '' : ' article-grid-narrow' ?>">
    <?php if ($hasToc): ?>
      <aside class="article-aside">
        <nav class="article-toc" aria-label="On this page">
          <p class="article-toc-head">On this page</p>
          <ol>
            <?php foreach ($siteToc as $entry): ?>
              <li><a href="#<?= e((string) $entry['anchor']) ?>"><?= e((string) $entry['title']) ?></a></li>
            <?php endforeach; ?>
          </ol>
        </nav>
      </aside>
    <?php endif; ?>

    <div class="article-body">
      <?php if ($body === ''): ?>
        <?php /* An empty published page is a mistake, but it must not look like a broken one. */ ?>
        <p class="site-lede">
          This page is being written. In the meantime, message us on WhatsApp or write to the
          provincial office — we will answer the question directly.
        </p>
        <a class="btn-site btn-site-primary" href="<?= e(url('contact')) ?>">Ask us directly</a>
      <?php else: ?>
        <?= $body ?>
      <?php endif; ?>

      <footer class="article-foot">
        <p class="faint">Last reviewed by the provincial office. If anything here is unclear, ask —
          <a href="<?= e(url('contact')) ?>">send us a message</a>.</p>
      </footer>
    </div>
  </div>
</article>
