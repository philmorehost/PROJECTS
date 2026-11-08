<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Security;

use function RISE63\{e, url};

$isAdmin = Auth::isAdmin($account);
?>
<section class="card">
  <h1>Parish Connect</h1>
  <p class="muted">The grassroots network: every parish, its CSR representative and its recorded needs.</p>
  <div class="grid grid-3" style="margin-top:12px">
    <div class="stat"><b><?= e((string) $totals['parishes']) ?></b><span>Parishes</span></div>
    <div class="stat"><b><?= e((string) $totals['areas']) ?></b><span>Areas</span></div>
    <div class="stat"><b><?= e((string) $totals['zones']) ?></b><span>Zones</span></div>
  </div>
  <form method="get" action="<?= e(url('parishes')) ?>" class="row" style="margin-top:16px" data-combobox data-endpoint="<?= e(url('api/index.php')) ?>">
    <div class="field grow" style="margin:0">
      <label for="q">Search parishes</label>
      <input type="search" id="q" name="q" value="<?= e($term) ?>" placeholder="Parish, area or zone">
    </div>
    <button class="btn btn-secondary" type="submit">Search</button>
  </form>
</section>

<?php if ($canEdit): ?>
  <details class="card">
    <summary style="cursor:pointer;font-weight:700;min-height:32px">Add a parish</summary>
    <form method="post" action="<?= e(url('')) ?>" style="margin-top:14px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="parish-create">
      <input type="hidden" name="return_to" value="parishes">
      <div class="grid grid-2">
        <div class="field">
          <label for="parish_name">Parish name</label>
          <input type="text" id="parish_name" name="parish_name" required maxlength="150">
        </div>
        <div class="field">
          <label for="area_id">Area</label>
          <select id="area_id" name="area_id" required>
            <option value="">Choose an area</option>
            <?php foreach ($areas as $area): ?>
              <option value="<?= (int) $area['id'] ?>"><?= e($area['zone_name'] . ' · ' . $area['area_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($areas === [] && $isAdmin): ?>
            <span class="hint">Create a zone and area below first.</span>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="parish_code">Parish code <span class="faint">(optional)</span></label>
          <input type="text" id="parish_code" name="parish_code" maxlength="20" placeholder="LP63-001">
        </div>
        <div class="field">
          <label for="youth_count">Youth population</label>
          <input type="number" id="youth_count" name="youth_count" min="0" max="100000" value="0">
        </div>
        <div class="field">
          <label for="yaya_rep_name">YAYA CSR representative</label>
          <input type="text" id="yaya_rep_name" name="yaya_rep_name" maxlength="150">
        </div>
        <div class="field">
          <label for="yaya_rep_phone">Representative phone</label>
          <input type="tel" id="yaya_rep_phone" name="yaya_rep_phone" maxlength="25">
        </div>
      </div>
      <div class="field">
        <label for="worship_address">Worship address</label>
        <input type="text" id="worship_address" name="worship_address" maxlength="255">
      </div>
      <div class="field">
        <label for="needs_summary">Needs assessment</label>
        <textarea id="needs_summary" name="needs_summary" maxlength="2000" placeholder="What does this parish need most this year?"></textarea>
      </div>
      <button class="btn btn-primary" type="submit">Add parish</button>
    </form>
  </details>

  <?php if ($isAdmin): ?>
    <details class="card">
      <summary style="cursor:pointer;font-weight:700">Manage zones and areas</summary>
      <div class="grid grid-2" style="margin-top:14px">
        <form method="post" action="<?= e(url('')) ?>">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="zone-create">
          <input type="hidden" name="return_to" value="parishes">
          <div class="field">
            <label for="zone_name">New zone</label>
            <input type="text" id="zone_name" name="zone_name" required maxlength="150">
          </div>
          <div class="field">
            <label for="zone_code">Zone code <span class="faint">(optional)</span></label>
            <input type="text" id="zone_code" name="zone_code" maxlength="20">
          </div>
          <button class="btn btn-secondary" type="submit">Create zone</button>
        </form>
        <form method="post" action="<?= e(url('')) ?>">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="area-create">
          <input type="hidden" name="return_to" value="parishes">
          <div class="field">
            <label for="area_zone">Zone</label>
            <select id="area_zone" name="zone_id" required>
              <option value="">Choose a zone</option>
              <?php foreach ($zones as $zone): ?>
                <option value="<?= (int) $zone['id'] ?>"><?= e((string) $zone['zone_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="area_name">New area</label>
            <input type="text" id="area_name" name="area_name" required maxlength="150">
          </div>
          <div class="field">
            <label for="area_code">Area code <span class="faint">(optional)</span></label>
            <input type="text" id="area_code" name="area_code" maxlength="20">
          </div>
          <button class="btn btn-secondary" type="submit">Create area</button>
        </form>
      </div>
    </details>
  <?php endif; ?>
<?php endif; ?>

<h2 class="section-title">Directory <span class="faint"><?= count($parishes) ?> shown</span></h2>
<div class="grid grid-2">
  <?php foreach ($parishes as $parish): ?>
    <article class="card">
      <div class="card-head">
        <div>
          <h3 style="margin:0"><?= e((string) $parish['parish_name']) ?></h3>
          <p class="faint" style="margin:2px 0 0"><?= e((string) $parish['area_name']) ?> · <?= e((string) $parish['zone_name']) ?></p>
        </div>
        <?php if (!(int) $parish['is_active']): ?><span class="badge badge-warn">Inactive</span><?php endif; ?>
      </div>
      <ul class="list-plain">
        <li class="row row-end"><span class="muted">Representative</span><span><?= e((string) ($parish['yaya_rep_name'] ?? '—')) ?></span></li>
        <li class="row row-end"><span class="muted">Phone</span><span><?= e((string) ($parish['yaya_rep_phone'] ?? '—')) ?></span></li>
        <li class="row row-end"><span class="muted">Youth</span><span><?= e(number_format((int) $parish['youth_count'])) ?></span></li>
        <li class="row row-end"><span class="muted">Accounts</span><span><?= (int) $parish['account_count'] ?></span></li>
      </ul>
      <a class="btn btn-secondary btn-sm" style="margin-top:10px" href="<?= e(url('parish', ['id' => (int) $parish['id']])) ?>">Open parish</a>
    </article>
  <?php endforeach; ?>
  <?php if ($parishes === []): ?><p class="muted">No parishes match that search.</p><?php endif; ?>
</div>
