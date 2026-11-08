<?php
declare(strict_types=1);

use function RISE63\{e, site_title};
?>
<section class="card center">
  <span class="program-icon" aria-hidden="true">◍</span>
  <h1>You are offline</h1>
  <p><?= e(site_title()) ?> could not reach the network. You can keep reading this page; anything you submit will be
    queued and sent automatically when you reconnect.</p>
  <p class="faint">If this message stays for more than a few minutes, check your data balance or Wi-Fi.</p>
  <button class="btn btn-primary" type="button" onclick="window.location.reload()">Try again</button>
</section>
