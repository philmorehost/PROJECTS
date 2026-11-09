<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Money;
use RISE63\Core\Program;
use RISE63\Core\Security;

use function RISE63\{e, human_date, relative_time, url};

$canEdit = Auth::isAdmin($account) || (string) $account['role'] === 'csr_rep';
?>
<section class="card">
  <a class="faint" href="<?= e(url('parishes')) ?>">← Parish Connect</a>
  <div class="card-head" style="margin-top:10px">
    <div>
      <h1 style="margin:0;font-size:1.375rem"><?= e((string) $parish['parish_name']) ?></h1>
      <p class="faint" style="margin:2px 0 0"><?= e((string) $parish['area_name']) ?> · <?= e((string) $parish['zone_name']) ?>
        <?= !empty($parish['parish_code']) ? ' · ' . e((string) $parish['parish_code']) : '' ?></p>
    </div>
  </div>
  <div class="grid grid-3" style="margin-top:12px">
    <div class="stat"><b><?= e(number_format((int) $parish['youth_count'])) ?></b><span>Youth</span></div>
    <div class="stat"><b><?= e((string) count($members)) ?></b><span>Accounts</span></div>
    <div class="stat"><b><?= e((string) count($apps)) ?></b><span>Applications</span></div>
  </div>
</section>

<?php if ($canEdit): ?>
  <section class="card">
    <h2>Parish record</h2>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="parish-update">
      <input type="hidden" name="parish_id" value="<?= (int) $parish['id'] ?>">
      <input type="hidden" name="return_to" value="parish">
      <div class="grid grid-2">
        <div class="field">
          <label for="parish_name">Parish name</label>
          <input type="text" id="parish_name" name="parish_name" required maxlength="150" data-caps
                 autocapitalize="characters" spellcheck="false" value="<?= e((string) $parish['parish_name']) ?>">
        </div>
        <div class="field">
          <label for="area_id">Area</label>
          <select id="area_id" name="area_id" required>
            <?php foreach ($areas as $area): ?>
              <option value="<?= (int) $area['id'] ?>" <?= (int) $area['id'] === (int) $parish['area_id'] ? 'selected' : '' ?>>
                <?= e($area['zone_name'] . ' · ' . $area['area_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="parish_code">Parish code</label>
          <input type="text" id="parish_code" name="parish_code" maxlength="20" value="<?= e((string) ($parish['parish_code'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="youth_count">Youth population</label>
          <input type="number" id="youth_count" name="youth_count" min="0" max="100000" value="<?= (int) $parish['youth_count'] ?>">
        </div>
        <div class="field">
          <label for="yaya_rep_name">YAYA CSR representative</label>
          <input type="text" id="yaya_rep_name" name="yaya_rep_name" maxlength="150" value="<?= e((string) ($parish['yaya_rep_name'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="yaya_rep_phone">Representative phone</label>
          <input type="tel" id="yaya_rep_phone" name="yaya_rep_phone" maxlength="25" value="<?= e((string) ($parish['yaya_rep_phone'] ?? '')) ?>">
        </div>
      </div>
      <div class="field">
        <label for="worship_address">Worship address</label>
        <input type="text" id="worship_address" name="worship_address" maxlength="255" value="<?= e((string) ($parish['worship_address'] ?? '')) ?>">
      </div>
      <div class="field">
        <label for="needs_summary">Needs assessment</label>
        <textarea id="needs_summary" name="needs_summary" maxlength="2000"><?= e((string) ($parish['needs_summary'] ?? '')) ?></textarea>
      </div>
      <?php if (Auth::isAdmin($account)): ?>
        <div class="check">
          <input type="checkbox" id="is_active" name="is_active" value="1" <?= (int) $parish['is_active'] === 1 ? 'checked' : '' ?>>
          <label for="is_active" style="font-weight:400">Parish is active in the directory</label>
        </div>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit">Save parish</button>
    </form>
  </section>
<?php endif; ?>

<div class="grid grid-2">
  <section class="card">
    <h2>Members</h2>
    <ul class="list-plain">
      <?php foreach ($members as $member): ?>
        <li class="row row-end">
          <span>
            <strong><?= e((string) $member['full_name']) ?></strong>
            <?php if ((int) $member['is_suspended'] === 1): ?><span class="badge badge-danger">Suspended</span><?php endif; ?>
            <br><span class="faint"><?= e(Auth::ROLES[(string) $member['role']] ?? (string) $member['role']) ?> · <?= e((string) $member['email']) ?></span>
          </span>
        </li>
      <?php endforeach; ?>
      <?php if ($members === []): ?><li class="faint">No accounts are linked to this parish yet.</li><?php endif; ?>
    </ul>
  </section>

  <section class="card">
    <h2>Applications from this parish</h2>
    <ul class="list-plain">
      <?php foreach ($apps as $row): ?>
        <li class="row row-end">
          <span>
            <a href="<?= e(url('application', ['id' => (int) $row['id']])) ?>"><strong><?= e(Program::short((string) $row['program_type'])) ?></strong></a>
            <br><span class="faint"><?= e(Program::statusLabel((string) $row['status'])) ?> ·
              <?= e(Money::fromDecimal($row['requested_amount'])->format()) ?> ·
              <?= e(relative_time((string) $row['created_at'])) ?></span>
          </span>
        </li>
      <?php endforeach; ?>
      <?php if ($apps === []): ?><li class="faint">No applications yet.</li><?php endif; ?>
    </ul>
  </section>
</div>
