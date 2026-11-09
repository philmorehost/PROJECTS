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
  <?php
    // The empty state, on the page that fixes it. An empty directory used to be invisible from
    // inside the application: the counters read 0 and everything else looked normal, while every
    // member registering saw a search box that matched nothing.
    $picker = $pickerState ?? ['state' => 'ok', 'message' => '', 'total' => 0, 'inactive' => 0, 'orphaned' => 0];
  ?>
  <?php if (($picker['state'] ?? 'ok') !== 'ok'): ?>
    <div class="notice notice-warn" style="margin-top:12px" role="status" id="directory-empty">
      <strong><?= e((string) $picker['message']) ?></strong>
      Until churches are added, the registration page cannot list them — members can still type
      their church name, and it waits in the queues below for you to add.
      Paste the list into <strong>Add zones, areas and parishes in bulk</strong> below — one church
      per line as <code>ZONE, AREA, PARISH</code>. A zone on its own is a church too, so even
      <code>GRACE ZONE</code> is enough to give members something to choose.
    </div>
  <?php endif; ?>

  <div class="grid grid-3" style="margin-top:12px">
    <?php
      // The heading says "churches" and the three levels are shown separately, because "0 Parishes"
      // beside 26 areas and 16 zones was read — correctly — as an empty directory. A zone church and
      // an area church are churches members can register at; the total is what the picker offers.
      $churchTotal = (int) $totals['parishes'] + (int) $totals['areas'] + (int) $totals['zones'];
    ?>
    <div class="stat"><b><?= $churchTotal ?></b><span>Churches members can choose</span></div>
    <div class="stat"><b><?= e((string) $totals['parishes']) ?></b><span>Parish churches</span></div>
    <div class="stat"><b><?= e((string) $totals['areas']) ?></b><span>Area churches</span></div>
    <div class="stat"><b><?= e((string) $totals['zones']) ?></b><span>Zonal churches</span></div>
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
          <input type="text" id="parish_name" name="parish_name" required maxlength="150" data-caps
                 autocapitalize="characters" spellcheck="false">
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
    <?php
      // The hierarchy is maintained as a list, because that is the shape it already has in the
      // office's spreadsheet. Three fields per church, one church per line, repeats allowed.
      $sample = "GRACE ZONE, HOPE AREA, LIVING WATER PARISH\n"
              . "GRACE ZONE, HOPE AREA, GOOD SHEPHERD PARISH\n"
              . "GRACE ZONE, FAITH AREA\n"
              . "CONQUERORS CHAPEL ZONE, CHAPEL OF WONDERS PARISH AREA, CHAPEL OF WONDERS PARISH";
    ?>
    <details class="card" <?= ($importPreview ?? null) !== null ? 'open' : '' ?>>
      <summary style="cursor:pointer;font-weight:700;min-height:32px">Add zones, areas and parishes in bulk</summary>
      <form method="post" action="<?= e(url('')) ?>" style="margin-top:14px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="directory-import">
        <input type="hidden" name="return_to" value="parishes">
        <div class="field">
          <label for="hierarchy">One church per line — <span class="faint" style="font-weight:400">zone, area, parish</span></label>
          <textarea id="hierarchy" name="hierarchy" rows="8" maxlength="300000" spellcheck="false"
                    autocapitalize="characters" autocomplete="off" style="min-height:180px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace"
                    placeholder="<?= e($sample) ?>"><?= e((string) ($importText ?? '')) ?></textarea>
          <span class="hint">
            Separate the three parts with a comma or a tab. Names are converted to CAPITALS for you.
          </span>
        </div>

        <div class="notice" style="margin-bottom:14px">
          <strong>How to write it</strong>
          <ul class="list-plain" style="margin-top:8px;list-style:disc;padding-left:20px">
            <li><code>ZONE, AREA, PARISH</code> — the usual line: creates whatever is missing.</li>
            <li><code>ZONE, AREA</code> — creates the area and leaves its parishes for later. Add them
              by adding a line with the same zone and area and a parish name.</li>
            <li><code>ZONE</code> — creates just the zone.</li>
            <li>Repeat a zone or area on as many lines as it has children. That is how the hierarchy
              is set: three lines starting <code>GRACE ZONE, HOPE AREA,</code> are three parishes in
              one area, not three areas.</li>
            <li>Anything that already exists is matched and left alone — case does not matter, so a
              re-paste never duplicates a church.</li>
            <li>Lines starting with <code>#</code> are ignored, so an exported sheet header can be
              left in place.</li>
          </ul>
        </div>

        <div class="row" style="gap:10px;flex-wrap:wrap">
          <button class="btn btn-primary" type="submit" name="mode" value="preview">Preview changes</button>
          <button class="btn btn-secondary" type="submit" name="mode" value="apply"
                  onclick="return confirm('Add these churches to the directory? Existing rows are not changed.')">
            Add to directory
          </button>
        </div>
      </form>
    </details>

    <?php if (($importPreview ?? null) !== null): ?>
      <?php $preview = $importPreview; ?>
      <section class="card">
        <div class="card-head">
          <h2 style="margin:0">Preview — nothing has been saved yet</h2>
        </div>
        <div class="grid grid-3" style="margin-top:12px">
          <div class="stat"><b><?= (int) $preview['new_zones'] ?></b><span>New zones</span></div>
          <div class="stat"><b><?= (int) $preview['new_areas'] ?></b><span>New areas</span></div>
          <div class="stat"><b><?= (int) $preview['new_parishes'] ?></b><span>New parishes</span></div>
        </div>
        <p class="muted" style="margin-top:10px">
          <?= (int) $preview['existing_zones'] ?> zone(s), <?= (int) $preview['existing_areas'] ?> area(s) and
          <?= (int) $preview['existing_parishes'] ?> parish(es) are already in the directory and will be left alone.
          <?php if ((int) $preview['duplicates'] > 0): ?>
            <?= (int) $preview['duplicates'] ?> line(s) repeat an earlier line and are counted once.
          <?php endif; ?>
        </p>

        <?php if ((int) ($preview['areas_without_church'] ?? 0) > 0): ?>
          <?php
            // The signature of the reported problem: a list whose third column was never filled in.
            // The lines are valid — "ZONE, AREA" legitimately means "add the area, its churches come
            // later" — so they are not skipped, but the operator must be told that this paste creates
            // places with no church attached.
          ?>
          <div class="flash flash-error" role="alert" style="margin-top:12px">
            <strong><?= (int) $preview['areas_without_church'] ?> area(s) in this list have no church after them.</strong>
            <p style="margin:6px 0 0">
              If you meant to add the churches, each line needs a third column:
              <code>ZONE, AREA, PARISH</code> — for example
              <code>GRACE ZONE, HOPE AREA, LIVING WATER PARISH</code>.
              The areas will still be created, and you can add their churches afterwards.
            </p>
          </div>
        <?php endif; ?>

        <?php if (($importSkipped ?? []) !== []): ?>
          <div class="flash flash-error" role="alert" style="margin-top:12px">
            <strong><?= count($importSkipped) ?> line(s) will be skipped:</strong>
            <ul class="list-plain" style="margin-top:8px;list-style:disc;padding-left:20px">
              <?php foreach ($importSkipped as $skip): ?>
                <li>Line <?= (int) $skip['number'] ?> — <?= e((string) $skip['reason']) ?>
                  <span class="faint block"><?= e((string) $skip['raw']) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($preview['new_zones'] > 0 || $preview['new_areas'] > 0 || $preview['new_parishes'] > 0): ?>
          <div class="table-wrap" style="margin-top:14px">
            <table class="table-stack">
              <thead><tr><th>Zone</th><th>Area</th><th>Parish</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach (array_slice($preview['parishes'], 0, 200) as $row): ?>
                  <tr>
                    <td data-label="Zone"><?= e((string) $row['zone']) ?></td>
                    <td data-label="Area"><?= e((string) $row['area']) ?></td>
                    <td data-label="Parish"><?= e((string) $row['name']) ?></td>
                    <td data-label="Status"><span class="badge badge-info">New parish</span></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach (array_slice($preview['areas'], 0, 100) as $row): ?>
                  <tr>
                    <td data-label="Zone"><?= e((string) $row['zone']) ?></td>
                    <td data-label="Area"><?= e((string) $row['name']) ?></td>
                    <td data-label="Parish" class="faint">— no parish yet</td>
                    <td data-label="Status"><span class="badge badge-warn">New area</span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('')) ?>" style="margin-top:14px">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="directory-import">
          <input type="hidden" name="return_to" value="parishes">
          <input type="hidden" name="mode" value="apply">
          <textarea name="hierarchy" hidden><?= e((string) ($importText ?? '')) ?></textarea>
          <button class="btn btn-primary" type="submit">Add these to the directory</button>
        </form>
      </section>
    <?php endif; ?>

    <details class="card">
      <summary style="cursor:pointer;font-weight:700;min-height:32px">Add a single zone or area</summary>
      <div class="grid grid-2" style="margin-top:14px">
        <form method="post" action="<?= e(url('')) ?>">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="zone-create">
          <input type="hidden" name="return_to" value="parishes">
          <div class="field">
            <label for="zone_name">New zone</label>
            <input type="text" id="zone_name" name="zone_name" required maxlength="150" data-caps>
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
            <input type="text" id="area_name" name="area_name" required maxlength="150" data-caps>
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

<h2 class="section-title">Directory <span class="faint"><?= count($parishes) ?> church<?= count($parishes) === 1 ? '' : 'es' ?> shown — parishes, area churches and zonal churches</span></h2>
<div class="grid grid-2">
  <?php foreach ($parishes as $parish): ?>
    <article class="card">
      <div class="card-head">
        <div>
          <h3 style="margin:0"><?= e((string) $parish['parish_name']) ?></h3>
          <p class="faint" style="margin:2px 0 0"><?= e((string) $parish['area_name']) ?> · <?= e((string) $parish['zone_name']) ?></p>
        </div>
        <?php
          // A zone or area church is a church, and saying so is the point of this change: before
          // it, the directory listed parishes only, so a correctly-entered hierarchy read as empty.
          $level = (string) ($parish['level'] ?? 'parish');
          $levelLabel = ['parish' => 'Parish', 'area' => 'Area church', 'zone' => 'Zonal church'][$level] ?? 'Parish';
        ?>
        <span class="badge badge-info"><?= e($levelLabel) ?></span>
        <?php if (!(int) $parish['is_active']): ?><span class="badge badge-warn">Inactive</span><?php endif; ?>
      </div>
      <ul class="list-plain">
        <li class="row row-end"><span class="muted">Representative</span><span><?= e((string) ($parish['yaya_rep_name'] ?? '—')) ?></span></li>
        <li class="row row-end"><span class="muted">Phone</span><span><?= e((string) ($parish['yaya_rep_phone'] ?? '—')) ?></span></li>
        <li class="row row-end"><span class="muted">Youth</span><span><?= e(number_format((int) $parish['youth_count'])) ?></span></li>
        <li class="row row-end"><span class="muted">Accounts</span><span><?= (int) $parish['account_count'] ?></span></li>
      </ul>
      <?php if ($level === 'parish'): ?>
        <a class="btn btn-secondary btn-sm" style="margin-top:10px" href="<?= e(url('parish', ['id' => (int) $parish['id']])) ?>">Open parish</a>
      <?php else: ?>
        <?php /* An area or zone church has no parish record to open; its details live on the
                 hierarchy itself, which the zone/area forms above edit. */ ?>
        <p class="faint" style="margin:10px 0 0">Recorded under <?= e((string) $parish['zone_name']) ?>. Members can choose it at registration.</p>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  <?php if ($parishes === []): ?>
    <p class="muted">
      <?= $term !== ''
          ? 'No church matches that search. Parishes, area churches and zonal churches are all searched.'
          : 'No churches yet — paste your list into “Add zones, areas and parishes in bulk” below.' ?>
    </p>
  <?php endif; ?>
</div>
