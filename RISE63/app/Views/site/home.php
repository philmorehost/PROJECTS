<?php
declare(strict_types=1);

/**
 * The landing page.
 *
 * Layout only — every sentence a visitor can change has already been decided by an administrator and
 * arrives in $slides / $programmes / $copy. See app/Core/Site.php.
 *
 * The hero is a slider with three inputs of equal standing, because a carousel that only works with a
 * mouse is a carousel half the visitors cannot use:
 *
 *   - the arrow buttons and the numbered pips (pointer);
 *   - the keyboard, once the region has focus (← → Home End);
 *   - horizontal swipe (touch), which is what a phone user will actually try.
 *
 * It is a *cross-fade* rather than a slide: the incoming panel is already loaded and only its opacity
 * changes, so nothing shifts, no scrollbar appears, and a slow connection shows the first image rather
 * than an empty box. `prefers-reduced-motion` stops the autoplay entirely — motion that cannot be
 * turned off is an accessibility failure, not a flourish.
 *
 * The first slide is server-rendered as `is-active`, so the page is complete and readable before
 * site.js has parsed. If the script never runs, every slide is still in the document and the CSS
 * reveals the first one.
 *
 * @var list<array<string,mixed>> $slides
 * @var list<array<string,mixed>> $programmes
 * @var array<string,string> $copy
 */

use function RISE63\{e, url};

$slideCount = count($slides);
?>

<?php if ($slideCount > 0): ?>
<section class="hero" id="hero" role="region" aria-label="RISE 63 in brief"
         data-slider aria-roledescription="carousel"
         data-interval="<?= (int) ($heroInterval ?? 7000) ?>">

  <div class="hero-stage">
    <?php foreach ($slides as $i => $slide): ?>
      <?php
        $image = $slide['image_path'] !== null ? url('', ['action' => 'site-image', 'file' => (string) $slide['image_path']]) : (string) ($slide['fallback'] ?? '');
        $isFirst = $i === 0;
      ?>
      <article class="hero-slide<?= $isFirst ? ' is-active' : '' ?> hero-tone-<?= e((string) $slide['tone']) ?>"
               data-slide
               role="group"
               aria-roledescription="slide"
               aria-label="<?= $i + 1 ?> of <?= $slideCount ?>"
               <?= $isFirst ? '' : 'aria-hidden="true"' ?>>

        <?php if ($image !== ''): ?>
          <?php /* The photograph is decorative to a sighted visitor and described for everyone else:
                   the slide's message is already in its heading. Hiding the wrapper and giving the
                   image a real alt would hide the description too, so the alt carries it and the
                   wrapper is a plain container. */ ?>
          <div class="hero-media">
            <img src="<?= e($image) ?>" alt="<?= e((string) $slide['image_alt']) ?>"
                 <?= $isFirst
                        ? 'loading="eager" fetchpriority="high"'
                        : 'loading="lazy"' ?> decoding="async" width="1600" height="900">
          </div>
        <?php endif; ?>
        <div class="hero-veil" aria-hidden="true"></div>

        <div class="site-wrap hero-body">
          <div class="hero-copy">
            <?php if ((string) $slide['kicker'] !== ''): ?>
              <p class="hero-kicker"><span></span><?= e((string) $slide['kicker']) ?></p>
            <?php endif; ?>
            <?php
              /* Exactly one <h1> per document. The first slide carries it, because that is the
                 message the page is about; the rest are <h2> with the same type treatment. Rotating
                 the level with the carousel would give a page whose outline changes as the visitor
                 watches it, which is worse than a stable heading they never see twice. */
              $heading = $isFirst ? 'h1' : 'h2';
            ?>
            <<?= $heading ?> class="hero-headline"><?= e((string) $slide['headline']) ?></<?= $heading ?>>
            <?php if ((string) $slide['subhead'] !== ''): ?>
              <p class="hero-subhead"><?= e((string) $slide['subhead']) ?></p>
            <?php endif; ?>
            <?php if ((string) $slide['body'] !== ''): ?>
              <p class="hero-body-text"><?= e((string) $slide['body']) ?></p>
            <?php endif; ?>
            <div class="hero-actions">
              <?php if ((string) $slide['cta_label'] !== '' && (string) $slide['cta_page'] !== ''): ?>
                <a class="btn-site btn-site-primary" href="<?= e(url((string) $slide['cta_page'])) ?>">
                  <?= e((string) $slide['cta_label']) ?>
                  <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 10h11M10.5 5.5 15 10l-4.5 4.5"/></svg>
                </a>
              <?php endif; ?>
              <a class="btn-site btn-site-ghost" href="<?= e(url('contact')) ?>">Talk to us</a>
            </div>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($slideCount > 1): ?>
    <div class="site-wrap hero-controls">
      <button class="hero-arrow" type="button" data-slide-prev aria-label="Previous slide">
        <svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" d="M12 4 6 10l6 6"/></svg>
      </button>
      <button class="hero-arrow" type="button" data-slide-next aria-label="Next slide">
        <svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" d="M8 4l6 6-6 6"/></svg>
      </button>

      <div class="hero-pips" role="group" aria-label="Choose a slide">
        <?php foreach ($slides as $i => $slide): ?>
          <button type="button" class="hero-pip<?= $i === 0 ? ' is-active' : '' ?>"
                  data-slide-to="<?= $i ?>"
                  <?= $i === 0 ? 'aria-current="true"' : '' ?>
                  aria-label="Slide <?= $i + 1 ?>: <?= e(mb_substr((string) $slide['headline'], 0, 60)) ?>">
            <span class="hero-pip-track" aria-hidden="true"></span>
            <span class="hero-pip-num" aria-hidden="true"><?= sprintf('%02d', $i + 1) ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <p class="hero-count" aria-hidden="true">
        <span data-slide-current>01</span><i></i><span><?= sprintf('%02d', $slideCount) ?></span>
      </p>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ── The proposition, in one line ───────────────────────────────────────────────── */ ?>
<section class="site-section statement">
  <div class="site-wrap">
    <p class="statement-rule" aria-hidden="true"></p>
    <blockquote class="statement-quote">
      <?= e((string) ($copy['statement'] ?? '')) ?>
    </blockquote>
    <p class="statement-source"><?= e((string) ($copy['statement_source'] ?? '')) ?></p>
  </div>
</section>

<?php /* ── What RISE 63 is ────────────────────────────────────────────────────────────── */ ?>
<section class="site-section intro" id="about-rise">
  <div class="site-wrap intro-grid">
    <div class="intro-copy">
      <p class="site-eyebrow"><span></span>What we are</p>
      <h2 class="site-h2"><?= e((string) ($copy['intro_title'] ?? '')) ?></h2>
      <?php foreach (($copy['intro_paragraphs'] ?? []) as $paragraph): ?>
        <p class="site-lede"><?= e((string) $paragraph) ?></p>
      <?php endforeach; ?>
      <ul class="intro-facts">
        <?php foreach (($copy['intro_facts'] ?? []) as $fact): ?>
          <li>
            <strong><?= e((string) $fact[0]) ?></strong>
            <span><?= e((string) $fact[1]) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <a class="site-link" href="<?= e(url('about')) ?>">
        Read about RISE 63
        <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 10h11M10.5 5.5 15 10l-4.5 4.5"/></svg>
      </a>
    </div>
    <figure class="intro-figure">
      <img src="<?= e(url('public/img/about-network.jpg')) ?>"
           alt="A YAYA CSR representative reviewing parish figures with a young member" loading="lazy" decoding="async">
      <figcaption>
        <span class="intro-figure-label">Phase One</span>
        A parish representative in every congregation — the network everything else is built on.
      </figcaption>
    </figure>
  </div>
</section>

<?php /* ── Phase one: the network ─────────────────────────────────────────────────────── */ ?>
<section class="site-section phase">
  <div class="site-wrap">
    <div class="phase-head">
      <p class="site-eyebrow site-eyebrow-light"><span></span>Phase One</p>
      <h2 class="site-h2 site-h2-light">RISE 63 Parish Connect</h2>
      <p class="site-lede site-lede-light"><?= e((string) ($copy['phase_lede'] ?? '')) ?></p>
    </div>
    <ol class="phase-steps">
      <?php foreach (($copy['phase_steps'] ?? []) as $i => $step): ?>
        <li>
          <span class="phase-num" aria-hidden="true"><?= sprintf('%02d', $i + 1) ?></span>
          <h3><?= e((string) $step[0]) ?></h3>
          <p><?= e((string) $step[1]) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<?php /* ── The four programmes ────────────────────────────────────────────────────────── */ ?>
<section class="site-section programmes" id="programmes">
  <div class="site-wrap">
    <div class="section-head">
      <p class="site-eyebrow"><span></span>The programmes</p>
      <h2 class="site-h2">Four ways we back a young person</h2>
      <p class="site-lede">Each one stands on its own, and together they cover the whole journey — from
        learning the skills, to funding the business, to keeping a student in school.</p>
    </div>

    <div class="programme-grid">
      <?php foreach ($programmes as $i => $programme): ?>
        <article class="programme-card" style="--accent: <?= e((string) $programme['accent']) ?>">
          <div class="programme-card-top">
            <span class="programme-letter" aria-hidden="true"><?= e((string) $programme['letter']) ?></span>
            <span class="programme-tag"><?= e((string) $programme['tag']) ?></span>
          </div>
          <h3><?= e((string) $programme['name']) ?></h3>
          <p class="programme-lede"><?= e((string) $programme['lede']) ?></p>
          <ul class="programme-points">
            <?php foreach ($programme['points'] as $point): ?>
              <li><?= e((string) $point) ?></li>
            <?php endforeach; ?>
          </ul>
          <p class="programme-outcome"><strong>Outcome</strong> <?= e((string) $programme['outcome']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php /* ── The commitment / accountability ────────────────────────────────────────────── */ ?>
<section class="site-section promise">
  <div class="site-wrap promise-grid">
    <div>
      <p class="site-eyebrow site-eyebrow-light"><span></span>Governance</p>
      <h2 class="site-h2 site-h2-light">Built to be trusted with other people's money</h2>
      <p class="site-lede site-lede-light">
        Every naira is documented, every award is assessed by a panel that did not apply, and the
        three funds — grants, education support and the cooperative — never mix.
      </p>
      <a class="btn-site btn-site-ghost" href="<?= e(url('vision')) ?>">Read the full framework</a>
    </div>
    <ul class="promise-list">
      <?php foreach (($copy['promises'] ?? []) as $promise): ?>
        <li>
          <svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4 10.5 8 14.5 16 6"/></svg>
          <span><?= e((string) $promise) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<?php /* ── Closing call ───────────────────────────────────────────────────────────────── */ ?>
<section class="site-section closing">
  <div class="site-wrap closing-inner">
    <p class="closing-line"><?= e((string) ($copy['closing_line'] ?? '')) ?></p>
    <p class="closing-sub"><?= e((string) ($copy['closing_sub'] ?? '')) ?></p>
    <div class="closing-actions">
      <a class="btn-site btn-site-primary" href="<?= e(url('register')) ?>">Create a member account</a>
      <a class="btn-site btn-site-ghost btn-site-ghost-dark" href="<?= e(url('contact')) ?>">Ask a question first</a>
    </div>
  </div>
</section>
