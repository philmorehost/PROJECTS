<?php
declare(strict_types=1);

use function RISE63\{e, site_title, url};
?>
<section class="card">
  <h1>About <?= e(site_title()) ?></h1>
  <p class="muted">A single place for RCCG Lagos Province 63's YAYA CSR and Young Professionals Club to know every parish,
    support every applicant and account for every naira.</p>

  <div class="grid grid-2" style="margin-top:18px">
    <article class="card card-flat">
      <span class="program-icon" aria-hidden="true">⌂</span>
      <h3>Parish Connect</h3>
      <p class="muted">Every parish, its CSR representative, youth population and needs assessment — searchable in seconds.</p>
    </article>
    <article class="card card-flat">
      <span class="program-icon" aria-hidden="true">◈</span>
      <h3>RISE Academy</h3>
      <p class="muted">A three-month entrepreneurship and financial literacy programme with attendance tracking.</p>
    </article>
    <article class="card card-flat">
      <span class="program-icon" aria-hidden="true">₦</span>
      <h3>Seed Grants</h3>
      <p class="muted">Non-repayable micro-grants for graduating Academy members, drawn from a controlled monthly pool.</p>
    </article>
    <article class="card card-flat">
      <span class="program-icon" aria-hidden="true">★</span>
      <h3>Education Bridge</h3>
      <p class="muted">Needs-based tuition support for Super Teens and tertiary students, paid directly to the school.</p>
    </article>
  </div>

  <p class="faint" style="margin-top:18px">
    Working offline? The installed app keeps working and syncs your submissions when the connection returns.
  </p>
  <a class="btn btn-primary" href="<?= e(url('login')) ?>">Sign in</a>
</section>
