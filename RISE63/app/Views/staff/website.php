<?php
declare(strict_types=1);

/**
 * Settings → Website: the CMS.
 *
 * Designed for the person who will actually use it — a provincial officer, on a phone, between other
 * work. So: one thing per card, plain words, and the consequence of each control stated next to it.
 * There is no rich-text editor and no drag-and-drop. The body field explains its four pieces of
 * markup inline, and the live preview link sits beside it so the officer can look before publishing.
 *
 * @var list<array<string,mixed>> $pages
 * @var list<array<string,mixed>> $slides
 * @var array<string,mixed>|null $editingPage
 * @var array<string,mixed>|null $editingSlide
 * @var array<string,mixed> $siteSettings
 */

use RISE63\Core\Security;
use RISE63\Core\Site;

use function RISE63\{e, url};

$editingPage  = $editingPage ?? null;
$editingSlide = $editingSlide ?? null;
$isNewPage    = $editingPage === null;
$isNewSlide   = $editingSlide === null;
?>

<section class="card">
  <div class="card-head">
    <div>
      <h1 style="margin:0">Website</h1>
      <p class="faint" style="margin:4px 0 0">
        Everything on the public site — the home page, the four footer pages, the contact page and the
        hero carousel. Nothing here needs a release.
      </p>
    </div>
    <a class="btn btn-secondary" href="<?= e(url('')) ?>" target="_blank" rel="noopener">Open the site</a>
  </div>
</section>

<?php /* ── The hero carousel ─────────────────────────────────────────────────────────── */ ?>
<section class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Hero slider</h2>
      <p class="faint" style="margin:2px 0 0">
        The photographs and headlines at the top of the home page, in the order they appear.
        <?= count($slides) ?> slide<?= count($slides) === 1 ? '' : 's' ?>.
      </p>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= e(url('website', ['slide' => 'new'])) ?>">Add a slide</a>
  </div>

  <?php if ($slides === []): ?>
    <p class="muted" style="margin-top:12px">
      No slides yet. The site is showing its built-in opening slides; add one here to replace them.
    </p>
  <?php else: ?>
    <ul class="cms-slide-list">
      <?php foreach ($slides as $index => $slide): ?>
        <li class="cms-slide">
          <div class="cms-slide-thumb">
            <?php if ($slide['image_path'] !== null): ?>
              <img src="<?= e(url('', ['action' => 'site-image', 'file' => (string) $slide['image_path']])) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
              <span class="cms-slide-thumb-none" aria-hidden="true">No image</span>
            <?php endif; ?>
          </div>
          <div class="cms-slide-info">
            <p class="cms-slide-order"><?= sprintf('%02d', $index + 1) ?></p>
            <p class="cms-slide-headline"><?= e((string) $slide['headline']) ?></p>
            <p class="cms-slide-meta">
              <?php if ((string) $slide['kicker'] !== ''): ?><?= e((string) $slide['kicker']) ?> · <?php endif; ?>
              <?php if ((string) $slide['cta_label'] !== ''): ?>
                Button: <?= e((string) $slide['cta_label']) ?>
              <?php else: ?>
                No button
              <?php endif; ?>
              <?php if ((int) $slide['is_published'] !== 1): ?>
                · <span class="badge badge-warn">Hidden</span>
              <?php endif; ?>
            </p>
          </div>
          <div class="cms-slide-actions">
            <a class="btn btn-secondary btn-sm" href="<?= e(url('website', ['slide' => (int) $slide['id']])) ?>">Edit</a>
            <form method="post" action="<?= e(url('')) ?>" class="cms-inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="slide-move">
              <input type="hidden" name="id" value="<?= (int) $slide['id'] ?>">
              <input type="hidden" name="direction" value="up">
              <input type="hidden" name="return_to" value="website">
              <button class="btn btn-secondary btn-sm" type="submit"
                      <?= $index === 0 ? 'disabled aria-disabled="true"' : '' ?>
                      aria-label="Move “<?= e((string) $slide['headline']) ?>” up">↑</button>
            </form>
            <form method="post" action="<?= e(url('')) ?>" class="cms-inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="slide-move">
              <input type="hidden" name="id" value="<?= (int) $slide['id'] ?>">
              <input type="hidden" name="direction" value="down">
              <input type="hidden" name="return_to" value="website">
              <button class="btn btn-secondary btn-sm" type="submit"
                      <?= $index === count($slides) - 1 ? 'disabled aria-disabled="true"' : '' ?>
                      aria-label="Move “<?= e((string) $slide['headline']) ?>” down">↓</button>
            </form>
            <form method="post" action="<?= e(url('')) ?>" class="cms-inline"
                  data-confirm="Delete this slide? This cannot be undone.">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="slide-delete">
              <input type="hidden" name="id" value="<?= (int) $slide['id'] ?>">
              <input type="hidden" name="return_to" value="website">
              <button class="btn btn-secondary btn-sm" type="submit">Delete</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php /* ── The slide editor ──────────────────────────────────────────────────────────── */ ?>
<?php if (isset($_GET['slide'])): ?>
<section class="card" id="slide-editor">
  <h2><?= $isNewSlide ? 'Add a slide' : 'Edit slide' ?></h2>
  <form method="post" action="<?= e(url('')) ?>" enctype="multipart/form-data">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="slide-save">
    <input type="hidden" name="return_to" value="website">
    <?php if (!$isNewSlide): ?>
      <input type="hidden" name="id" value="<?= (int) $editingSlide['id'] ?>">
    <?php endif; ?>

    <div class="field">
      <label for="slide_headline">Headline</label>
      <input type="text" id="slide_headline" name="headline" required maxlength="180"
             value="<?= e((string) ($editingSlide['headline'] ?? '')) ?>"
             placeholder="No genuine need should stay invisible.">
      <span class="hint">The largest text on the slide. Keep it under ten words if you can — it is read on a phone.</span>
    </div>

    <div class="grid grid-2">
      <div class="field">
        <label for="slide_kicker">Small label above it</label>
        <input type="text" id="slide_kicker" name="kicker" maxlength="120" data-caps
               value="<?= e((string) ($editingSlide['kicker'] ?? '')) ?>"
               placeholder="RISE ACADEMY">
        <span class="hint">Optional. Names the programme or phase the slide is about.</span>
      </div>
      <div class="field">
        <label for="slide_order">Position</label>
        <input type="number" id="slide_order" name="sort_order" min="1" max="999"
               value="<?= (int) ($editingSlide['sort_order'] ?? 100) ?>">
        <span class="hint">Lower numbers come first. The arrows on the list above are easier.</span>
      </div>
    </div>

    <div class="field">
      <label for="slide_subhead">One-line summary</label>
      <input type="text" id="slide_subhead" name="subhead" maxlength="400"
             value="<?= e((string) ($editingSlide['subhead'] ?? '')) ?>"
             placeholder="A youth educational and economic empowerment initiative across every parish.">
      <span class="hint">Shown directly under the headline, in a larger size.</span>
    </div>

    <div class="field">
      <label for="slide_body">Supporting sentence</label>
      <textarea id="slide_body" name="body" rows="2" maxlength="600"
                placeholder="Recognise. Inspire. Support. Empower."><?= e((string) ($editingSlide['body'] ?? '')) ?></textarea>
      <span class="hint">Optional, and dimmer than the rest. Leave blank for a cleaner slide.</span>
    </div>

    <div class="grid grid-2">
      <div class="field">
        <label for="slide_image">Photograph</label>
        <input type="file" id="slide_image" name="image" accept="image/jpeg,image/png,image/webp">
        <span class="hint">
          JPG, PNG or WEBP, up to 4&nbsp;MB. Landscape works best — roughly 1600&times;900.
          <?php if (($editingSlide['image_path'] ?? null) !== null): ?>
            Leave blank to keep the current photograph.
          <?php endif; ?>
        </span>
      </div>
      <div class="field">
        <label for="slide_alt">What is in the photograph?</label>
        <input type="text" id="slide_alt" name="image_alt" maxlength="200"
               value="<?= e((string) ($editingSlide['image_alt'] ?? '')) ?>"
               placeholder="Members standing together after a service">
        <span class="hint">Required if there is a picture. Read aloud to blind visitors and used by search engines.</span>
      </div>
    </div>

    <div class="grid grid-3">
      <div class="field">
        <label for="slide_cta_label">Button label</label>
        <input type="text" id="slide_cta_label" name="cta_label" maxlength="60"
               value="<?= e((string) ($editingSlide['cta_label'] ?? '')) ?>" placeholder="Discover RISE 63">
      </div>
      <div class="field">
        <label for="slide_cta_page">Button opens</label>
        <select id="slide_cta_page" name="cta_page">
          <option value="">— no button —</option>
          <?php foreach ($pages as $page): ?>
            <option value="<?= e((string) $page['slug']) ?>"
              <?= (string) ($editingSlide['cta_page'] ?? '') === (string) $page['slug'] ? 'selected' : '' ?>>
              <?= e((string) $page['title']) ?>
            </option>
          <?php endforeach; ?>
          <option value="contact" <?= (string) ($editingSlide['cta_page'] ?? '') === 'contact' ? 'selected' : '' ?>>Contact</option>
          <option value="register" <?= (string) ($editingSlide['cta_page'] ?? '') === 'register' ? 'selected' : '' ?>>Create a member account</option>
        </select>
        <span class="hint">Both fields are needed, or neither.</span>
      </div>
      <div class="field">
        <label for="slide_tone">Text treatment</label>
        <select id="slide_tone" name="tone">
          <option value="ink" <?= ((string) ($editingSlide['tone'] ?? 'ink')) === 'ink' ? 'selected' : '' ?>>Dark over the photograph</option>
          <option value="light" <?= ((string) ($editingSlide['tone'] ?? 'ink')) === 'light' ? 'selected' : '' ?>>Lighter — for a pale photograph</option>
        </select>
      </div>
    </div>

    <div class="check">
      <input type="checkbox" id="slide_published" name="is_published" value="1"
             <?= (int) ($editingSlide['is_published'] ?? 1) === 1 ? 'checked' : '' ?>>
      <label for="slide_published" style="font-weight:400">Show this slide on the home page</label>
    </div>

    <div class="form-actions" style="margin-top:14px">
      <button class="btn btn-primary" type="submit">Save slide</button>
      <a class="btn btn-secondary" href="<?= e(url('website')) ?>">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>

<?php /* ── The pages ─────────────────────────────────────────────────────────────────── */ ?>
<section class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Pages</h2>
      <p class="faint" style="margin:2px 0 0">
        Each page is reachable at its own address, for example <code>/privacy</code>. The four with a
        padlock are linked from the footer, so they can be rewritten or hidden but not deleted.
      </p>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('website', ['page' => 'new'])) ?>">Add a page</a>
  </div>

  <ul class="cms-page-list">
    <?php foreach ($pages as $page): ?>
      <li class="cms-page">
        <div>
          <p class="cms-page-title">
            <?= e((string) $page['title']) ?>
            <?php if ((int) $page['is_system'] === 1): ?>
              <span class="badge badge-info" title="Linked from the footer of every page">Footer</span>
            <?php endif; ?>
            <?php if ((int) $page['is_published'] !== 1): ?>
              <span class="badge badge-warn">Hidden</span>
            <?php endif; ?>
          </p>
          <p class="cms-page-meta">
            <code>/<?= e((string) $page['slug']) ?></code>
            <?php if ((string) $page['summary'] !== ''): ?>
              · <?= e(mb_substr((string) $page['summary'], 0, 90)) ?><?= mb_strlen((string) $page['summary']) > 90 ? '…' : '' ?>
            <?php endif; ?>
          </p>
        </div>
        <div class="cms-page-actions">
          <a class="btn btn-secondary btn-sm" href="<?= e(url('website', ['page' => (string) $page['slug']])) ?>">Edit</a>
          <a class="btn btn-secondary btn-sm" href="<?= e(url((string) $page['slug'])) ?>" target="_blank" rel="noopener">View</a>
          <?php if ((int) $page['is_system'] !== 1): ?>
            <form method="post" action="<?= e(url('')) ?>" class="cms-inline"
                  data-confirm="Delete “<?= e((string) $page['title']) ?>”? This cannot be undone.">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="page-delete">
              <input type="hidden" name="slug" value="<?= e((string) $page['slug']) ?>">
              <input type="hidden" name="return_to" value="website">
              <button class="btn btn-secondary btn-sm" type="submit">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<?php /* ── The page editor ───────────────────────────────────────────────────────────── */ ?>
<?php if (isset($_GET['page'])): ?>
<section class="card" id="page-editor">
  <h2><?= $isNewPage ? 'Add a page' : 'Edit “' . e((string) $editingPage['title']) . '”' ?></h2>
  <form method="post" action="<?= e(url('')) ?>">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="page-save">
    <input type="hidden" name="return_to" value="website">
    <?php if (!$isNewPage && (int) $editingPage['is_system'] === 1): ?>
      <?php /* The slug is the footer URL. Changing it would break every existing link to it. */ ?>
      <input type="hidden" name="slug" value="<?= e((string) $editingPage['slug']) ?>">
    <?php endif; ?>

    <div class="grid grid-2">
      <div class="field">
        <label for="page_title">Page title</label>
        <input type="text" id="page_title" name="title" required maxlength="150"
               value="<?= e((string) ($editingPage['title'] ?? '')) ?>" placeholder="Privacy Policy">
      </div>
      <div class="field">
        <label for="page_slug">Address</label>
        <?php if (!$isNewPage && (int) $editingPage['is_system'] === 1): ?>
          <input type="text" id="page_slug" value="/<?= e((string) $editingPage['slug']) ?>" readonly aria-readonly="true">
          <span class="hint">Fixed, because the footer links here on every page.</span>
        <?php else: ?>
          <input type="text" id="page_slug" name="slug" maxlength="60" pattern="[a-z0-9-]+"
                 value="<?= e((string) ($editingPage['slug'] ?? '')) ?>" placeholder="scholarships">
          <span class="hint">Letters, numbers and hyphens. The page becomes <code>/scholarships</code>.</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="field">
        <label for="page_kicker">Small label above the title</label>
        <input type="text" id="page_kicker" name="kicker" maxlength="120" data-caps
               value="<?= e((string) ($editingPage['kicker'] ?? '')) ?>" placeholder="YOUR INFORMATION">
      </div>
      <div class="field">
        <label for="page_order">Position in the menu</label>
        <input type="number" id="page_order" name="sort_order" min="1" max="999"
               value="<?= (int) ($editingPage['sort_order'] ?? 100) ?>">
      </div>
    </div>

    <div class="field">
      <label for="page_summary">Summary</label>
      <textarea id="page_summary" name="summary" rows="2" maxlength="300"
                placeholder="What RISE 63 collects, why it is collected, who can see it."><?= e((string) ($editingPage['summary'] ?? '')) ?></textarea>
      <span class="hint">One or two sentences under the title, and the description search engines show.</span>
    </div>

    <?php if (!empty($editingPage['body_is_built_in'])): ?>
      <p class="notice" style="margin-bottom:12px">
        This page is showing the wording RISE 63 ships with. Edit it freely — the moment you save,
        your version is what visitors read, and this note stops appearing.
      </p>
    <?php endif; ?>

    <div class="field">
      <label for="page_body">Page content</label>
      <textarea id="page_body" name="body" rows="18" maxlength="24000"
                placeholder="## A heading&#10;&#10;A paragraph of ordinary text.&#10;&#10;- A list item&#10;- Another one"><?= e((string) ($editingPage['body'] ?? '')) ?></textarea>
      <details class="cms-help">
        <summary>How to format the content</summary>
        <ul class="list-plain">
          <li><code>## Heading</code> — a section heading, also added to the contents list on the left of the page.</li>
          <li><code>### Sub-heading</code> — a smaller heading inside a section.</li>
          <li><code>- Item</code> — one line per list item.</li>
          <li><code>&gt; Quoted line</code> — a pull quote, set larger.</li>
          <li>A blank line starts a new paragraph.</li>
          <li><code>**bold**</code> and <code>*italic*</code> work inside any line.</li>
        </ul>
        <p class="faint" style="margin-top:8px">
          Anything else is shown as plain text. Pasting from a word processor is safe — its formatting
          is dropped rather than pasted in.
        </p>
      </details>
    </div>

    <div class="check">
      <input type="checkbox" id="page_published" name="is_published" value="1"
             <?= (int) ($editingPage['is_published'] ?? 1) === 1 ? 'checked' : '' ?>>
      <label for="page_published" style="font-weight:400">Publish this page</label>
    </div>
    <p class="hint" style="margin-top:4px">
      An unpublished page returns “not found” to visitors. That is what hiding a footer page does —
      the footer link still shows, and lands on the contact page’s advice instead of the text.
    </p>

    <div class="form-actions" style="margin-top:14px">
      <button class="btn btn-primary" type="submit">Save page</button>
      <a class="btn btn-secondary" href="<?= e(url('website')) ?>">Cancel</a>
      <?php if (!$isNewPage): ?>
        <a class="btn btn-secondary" href="<?= e(url((string) $editingPage['slug'])) ?>" target="_blank" rel="noopener">Preview</a>
      <?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<?php /* ── Contact details, which the site uses everywhere ───────────────────────────── */ ?>
<section class="card">
  <h2>Contact details used by the site</h2>
  <p class="muted">
    The WhatsApp number appears in the header, the footer, the floating button on every page and on
    the contact page. Set it on
    <a href="<?= e(url('settings', ['do' => ''] )) ?>#website">Settings</a>.
  </p>
  <ul class="list-plain">
    <li class="row row-end">
      <span class="muted">WhatsApp</span>
      <span>
        <?php if ((string) ($siteSettings['whatsappRaw'] ?? '') !== ''): ?>
          <?= e((string) $siteSettings['whatsappRaw']) ?>
          <span class="faint">→ wa.me/<?= e((string) ($siteSettings['whatsappDigits'] ?? '')) ?></span>
        <?php else: ?>
          <span class="badge badge-warn">Not set</span>
        <?php endif; ?>
      </span>
    </li>
    <li class="row row-end">
      <span class="muted">E-mail</span>
      <span><?= (string) ($siteSettings['contactEmail'] ?? '') !== '' ? e((string) $siteSettings['contactEmail']) : '—' ?></span>
    </li>
    <li class="row row-end">
      <span class="muted">Telephone</span>
      <span><?= (string) ($siteSettings['contactPhone'] ?? '') !== '' ? e((string) $siteSettings['contactPhone']) : '—' ?></span>
    </li>
  </ul>
</section>
