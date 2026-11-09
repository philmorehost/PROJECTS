<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Security;

use function RISE63\{e, human_date, relative_time, url};
?>
<section class="card">
  <h1>People &amp; roles</h1>
  <p class="muted">Provision parish representatives and treasurers, promote members, and clear suspensions.
    New accounts receive a welcome mail but never a password in the mail.</p>
  <form method="get" action="<?= e(url('people')) ?>" class="row">
    <div class="field grow" style="margin:0">
      <label for="q">Search</label>
      <input type="search" id="q" name="q" value="<?= e($term) ?>" placeholder="Name, e-mail or parish">
    </div>
    <button class="btn btn-secondary" type="submit">Search</button>
  </form>
</section>

<details class="card">
  <summary style="cursor:pointer;font-weight:700;min-height:32px">Create an account</summary>
  <form method="post" action="<?= e(url('')) ?>" style="margin-top:14px">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="user-create">
    <input type="hidden" name="return_to" value="people">
    <div class="grid grid-2">
      <div class="field">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required maxlength="150">
      </div>
      <div class="field">
        <label for="email">E-mail address</label>
        <input type="email" id="email" name="email" required maxlength="150" inputmode="email">
      </div>
      <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role" required>
          <?php foreach ($roles as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $key === 'youth_member' ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" data-combobox data-endpoint="<?= e(url('api/index.php')) ?>">
        <label for="parish-search">Parish</label>
        <div class="combo">
          <input type="search" id="parish-search" role="combobox" aria-expanded="false" aria-autocomplete="list"
                 aria-controls="parish-list-people" autocomplete="off" placeholder="Search a parish">
          <input type="hidden" name="parish_id" value="">
          <ul class="combo-list" id="parish-list-people" role="listbox" hidden></ul>
        </div>
      </div>
    </div>
    <div class="field">
      <label for="password">Temporary password</label>
      <input type="password" id="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password">
      <span class="hint">At least 12 characters. Share it through a trusted channel and ask the person to change it immediately.</span>
    </div>
    <button class="btn btn-primary" type="submit">Create account</button>
  </form>
</details>

<?php if (($unlisted ?? []) !== []): ?>
  <section class="card">
    <div class="card-head">
      <div>
        <h2 style="margin:0">Churches members asked for</h2>
        <p class="faint" style="margin:2px 0 0">Typed at registration because the picker did not have them.
          Add each one in Parish Connect — the members attached to it are then ready to be linked.</p>
      </div>
    </div>
    <ul class="list-plain" style="margin-top:10px">
      <?php foreach ($unlisted as $row): ?>
        <li class="row row-end" style="padding:10px 0;border-bottom:1px solid var(--border)">
          <span><strong><?= e((string) $row['parish_name_raw']) ?></strong></span>
          <span class="faint"><?= (int) $row['member_count'] ?> member(s)</span>
        </li>
      <?php endforeach; ?>
    </ul>
    <a class="btn btn-secondary btn-sm" style="margin-top:12px" href="<?= e(url('parishes')) ?>">Open Parish Connect</a>
  </section>
<?php endif; ?>

<section class="card card-flat" style="padding:0">
  <div class="table-wrap" style="border:0">
    <table class="table-stack">
      <thead><tr><th>Person</th><th>Role</th><th>Parish</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($people as $person): ?>
          <tr>
            <td data-label="Person">
              <strong><?= e((string) $person['full_name']) ?></strong><br>
              <span class="faint"><?= e((string) $person['email']) ?></span>
            </td>
            <td data-label="Role">
              <form method="post" action="<?= e(url('')) ?>" class="row" style="gap:6px">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="user-role">
                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                <input type="hidden" name="return_to" value="people">
                <select name="role" aria-label="Role for <?= e((string) $person['full_name']) ?>" style="min-height:40px;padding:6px 10px">
                  <?php foreach ($roles as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= (string) $person['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary btn-sm" type="submit">Set</button>
              </form>
            </td>
            <td data-label="Parish">
              <?php if (!empty($person['parish_name_raw']) && empty($person['parish_name'])): ?>
                <strong><?= e((string) $person['parish_name_raw']) ?></strong><br>
                <span class="badge badge-warn">Not in the directory</span>
              <?php else: ?>
                <?= e((string) ($person['parish_name'] ?? '—')) ?>
              <?php endif; ?>
            </td>
            <td data-label="Last sign-in"><?= $person['last_login_at'] !== null ? e(relative_time((string) $person['last_login_at'])) : 'Never' ?></td>
            <td data-label="Status">
              <?php if ((int) $person['is_suspended'] === 1): ?>
                <span class="badge badge-danger">Suspended</span>
              <?php elseif ((int) ($person['must_change_password'] ?? 0) === 1): ?>
                <span class="badge badge-warn">Temporary password</span>
              <?php else: ?>
                <span class="badge badge-ok">Active</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int) $person['id'] !== (int) $account['id']): ?>
                <form method="post" action="<?= e(url('')) ?>" style="margin-bottom:6px"
                      data-confirm="Issue a temporary password for <?= e((string) $person['full_name']) ?>? Their current password stops working immediately and every device is signed out.">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="user-temporary-password">
                  <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                  <button class="btn btn-quiet btn-sm" type="submit">New temporary password</button>
                </form>
                <form method="post" action="<?= e(url('')) ?>">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="account-suspend">
                  <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                  <input type="hidden" name="suspend" value="<?= (int) $person['is_suspended'] === 1 ? '0' : '1' ?>">
                  <input type="hidden" name="return_to" value="people">
                  <button class="btn btn-sm <?= (int) $person['is_suspended'] === 1 ? 'btn-secondary' : 'btn-danger' ?>" type="submit">
                    <?= (int) $person['is_suspended'] === 1 ? 'Reinstate' : 'Suspend' ?>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($people === []): ?><tr><td colspan="6" class="faint" style="padding:18px">No accounts match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
