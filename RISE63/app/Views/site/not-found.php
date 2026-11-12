<?php
declare(strict_types=1);

/**
 * The public 404.
 *
 * Rendered as part of the site, not as the application's error page: a visitor who mistypes a URL
 * should get the organisation's name, a way back, and a way to ask — not a sign-in form.
 */

use function RISE63\{e, url};
?>
<section class="site-notice">
  <div class="site-wrap">
    <p class="site-notice-code" aria-hidden="true">404</p>
    <h1 class="page-title" style="margin-inline:auto">That page is not here.</h1>
    <p class="site-lede" style="margin-inline:auto">
      It may have been moved, or the address may have a typo in it. Everything the RISE 63 site
      publishes is reachable from the menu above.
    </p>
    <div class="closing-actions" style="margin-top:28px">
      <a class="btn-site btn-site-primary" href="<?= e(url('')) ?>">Back to the home page</a>
      <a class="btn-site btn-site-ghost" href="<?= e(url('contact')) ?>">Ask us</a>
    </div>
  </div>
</section>
