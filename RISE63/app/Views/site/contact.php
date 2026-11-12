<?php
declare(strict_types=1);

/**
 * Contact — the WhatsApp route, and nothing that pretends to be a mail server.
 *
 * There is no contact form posting to the application on purpose. A form would need its own spam
 * defence, its own queue and its own failure modes, and the organisation's actual support channel is
 * WhatsApp. So the page does the honest thing: it builds the WhatsApp message for the visitor, on
 * their own number, and lets them send it. If WhatsApp is not installed the same link opens on the
 * web, and the e-mail and telephone routes are printed beside it.
 *
 * @var string $whatsappDigits   international, digits only, ready for wa.me
 * @var string $whatsappDisplay  what a person should read
 * @var string $contactEmail
 * @var string $contactPhone
 * @var array<string,string> $topics  pre-written openers, so nobody has to compose from scratch
 */

use function RISE63\{e, url};

$hasWhatsapp = $whatsappDigits !== '';
?>
<section class="contact">
  <div class="site-wrap contact-grid">
    <div class="contact-copy">
      <p class="site-eyebrow"><span></span>Contact</p>
      <h1 class="page-title">Talk to a person.</h1>
      <p class="site-lede">
        Questions about a programme, an application or a payment go to the provincial CSR office.
        WhatsApp is the fastest route and the one we answer first.
      </p>

      <?php if ($hasWhatsapp): ?>
        <div class="contact-primary">
          <div class="contact-primary-head">
            <span class="contact-wa-mark" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12.04 2c-5.5 0-9.96 4.46-9.96 9.96 0 1.76.46 3.45 1.32 4.95L2 22l5.25-1.37a9.9 9.9 0 0 0 4.79 1.22h.01c5.5 0 9.96-4.46 9.96-9.96S17.54 2 12.04 2Zm5.83 14.16c-.25.7-1.45 1.34-2 1.39-.55.05-1.06.24-3.6-.75-3.06-1.2-4.98-4.36-5.13-4.56-.15-.2-1.2-1.6-1.2-3.05 0-1.45.76-2.16 1.03-2.46.27-.3.59-.37.79-.37.2 0 .4 0 .57.01.18.01.43-.07.67.51.25.6.85 2.07.92 2.22.08.15.13.33.03.53-.1.2-.15.33-.3.51-.15.18-.32.4-.45.53-.15.15-.31.32-.13.62.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.65-.08.18-.2.75-.87.95-1.17.2-.3.4-.25.67-.15.28.1 1.75.83 2.05.98.3.15.5.23.57.35.08.13.08.75-.17 1.45Z"/></svg>
            </span>
            <div>
              <strong>WhatsApp the office</strong>
              <span><?= e($whatsappDisplay) ?></span>
            </div>
          </div>

          <p class="contact-topics-label">Start with one of these, or write your own:</p>
          <div class="contact-topics">
            <?php foreach ($topics as $label => $message): ?>
              <a class="contact-topic"
                 href="https://wa.me/<?= e($whatsappDigits) ?>?text=<?= e(rawurlencode($message)) ?>"
                 target="_blank" rel="noopener"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>

          <a class="btn-site btn-site-primary btn-site-wide"
             href="https://wa.me/<?= e($whatsappDigits) ?>?text=<?= e(rawurlencode('Hello RISE 63 — ')) ?>"
             target="_blank" rel="noopener">
            Open WhatsApp
            <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 10h11M10.5 5.5 15 10l-4.5 4.5"/></svg>
          </a>
          <p class="contact-note">Opens WhatsApp with the message written for you. Nothing is sent until
            you press send, on your own account.</p>
        </div>
      <?php else: ?>
        <div class="contact-primary contact-primary-empty">
          <strong>WhatsApp is not configured yet</strong>
          <p>The provincial office has not published a WhatsApp number. Use the e-mail address or
            telephone number below and we will answer from there.</p>
        </div>
      <?php endif; ?>
    </div>

    <aside class="contact-side">
      <div class="contact-card">
        <h2>Other ways to reach us</h2>
        <?php if ($contactEmail !== ''): ?>
          <a class="contact-row" href="mailto:<?= e($contactEmail) ?>">
            <span class="contact-row-icon" aria-hidden="true">
              <svg viewBox="0 0 20 20" width="18" height="18"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M3 5h14v10H3z"/><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="m3 5 7 5.5L17 5"/></svg>
            </span>
            <span><strong>E-mail</strong><?= e($contactEmail) ?></span>
          </a>
        <?php endif; ?>
        <?php if ($contactPhone !== ''): ?>
          <a class="contact-row" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone) ?? '') ?>">
            <span class="contact-row-icon" aria-hidden="true">
              <svg viewBox="0 0 20 20" width="18" height="18"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M5 3h3l1.5 4-2 1.5a9 9 0 0 0 4 4L13 10.5 17 12v3a2 2 0 0 1-2 2A12 12 0 0 1 3 5a2 2 0 0 1 2-2Z"/></svg>
            </span>
            <span><strong>Telephone</strong><?= e($contactPhone) ?></span>
          </a>
        <?php endif; ?>
        <a class="contact-row" href="<?= e(url('parish-connect')) ?>">
          <span class="contact-row-icon" aria-hidden="true">
            <svg viewBox="0 0 20 20" width="18" height="18"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M3 17V8l7-5 7 5v9"/><path fill="none" stroke="currentColor" stroke-width="1.6" d="M8 17v-5h4v5"/></svg>
          </span>
          <span><strong>Your parish</strong>Speak to the YAYA CSR representative</span>
        </a>
      </div>

      <div class="contact-card contact-card-quiet">
        <h2>What to include</h2>
        <ul class="contact-tips">
          <li>Your full name, as it appears on your account.</li>
          <li>The parish you belong to, and its area.</li>
          <li>The programme you are asking about — Academy, Seed Grant, Cooperative or Education Bridge.</li>
          <li>Never send bank details, a password or a photograph of an ID document in a first message.</li>
        </ul>
      </div>
    </aside>
  </div>
</section>
