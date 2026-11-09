<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Security;

use function RISE63\{e, human_date, relative_time, url};

$roleLabels = Auth::ROLES;
$editing    = $editing ?? null;
$pager      = $pager ?? null;
$schemaGap  = $schemaGap ?? null;
$pendingMigrations = (int) ($pendingMigrations ?? 0);
?>
<section class="card">
  <div class="card-head">
    <div>
      <h1 style="margin:0">People &amp; roles</h1>
      <p class="faint" style="margin:2px 0 0">
        <?= (int) ($counts['total'] ?? 0) ?> accounts ·
        <?= (int) ($counts['admins'] ?? 0) ?> administrator(s) ·
        <?= (int) ($counts['treasurers'] ?? 0) ?> treasurer(s) ·
        <?= (int) ($counts['members'] ?? 0) ?> member(s)<?= (int) ($counts['suspended'] ?? 0) > 0 ? ' · ' . (int) $counts['suspended'] . ' suspended' : '' ?>
      </p>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= e(url('audit')) ?>">Activity report</a>
  </div>
  <p class="muted" style="margin-top:8px">
    Create accounts, correct details, suspend or reinstate, sign in as a member to see what they see,
    and remove an account that was made by mistake. Anything that would destroy a record — a member
    with applications, or the last administrator — is refused, and the refusal says what to do instead.
  </p>
</section>

<?php if ($pendingMigrations > 0): ?>
  <?php /* Placed here, above the forms, because this is the page where a failed save looks like a
           bug in the form. It is not: the database is behind the code. */ ?>
  <section class="card" style="border-color:var(--warn);background:var(--warn-soft)">
    <div class="card-head">
      <div>
        <h2 style="margin:0">Database updates are waiting</h2>
        <p class="muted" style="margin:6px 0 0">
          <?= $pendingMigrations ?> database update<?= $pendingMigrations === 1 ? '' : 's' ?> shipped with a
          release but has not been applied to this server. Creating or editing an account may fail until
          it runs — and the failure will not say why.
        </p>
        <p class="faint" style="margin:8px 0 0">On the server: <code>php bin/migrate.php</code></p>
      </div>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('settings')) ?>#database-updates">See what is pending</a>
    </div>
  </section>
<?php endif; ?>


<?php if ($editing !== null): ?>
  <?php /* Edit one account. A dedicated card rather than a row of inline inputs: editing an e-mail
           address and a parish in a 40 px row is how the wrong field gets changed. */ ?>
  <section class="card" id="edit-account">
    <div class="card-head">
      <div>
        <h2 style="margin:0">Edit <?= e((string) $editing['full_name']) ?></h2>
        <p class="faint" style="margin:2px 0 0">
          Account #<?= (int) $editing['id'] ?> ·
          <?= e(ucwords(str_replace('_', ' ', (string) $editing['role']))) ?> ·
          created <?= e(human_date((string) $editing['created_at'])) ?>
        </p>
      </div>
      <a class="btn btn-quiet btn-sm" href="<?= e(url('people')) ?>">Cancel</a>
    </div>

    <form method="post" action="<?= e(url('')) ?>" style="margin-top:12px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="user-update">
      <input type="hidden" name="user_id" value="<?= (int) $editing['id'] ?>">
      <div class="grid grid-2">
        <div class="field">
          <label for="edit_name">Full name</label>
          <input type="text" id="edit_name" name="full_name" required maxlength="150" value="<?= e((string) $editing['full_name']) ?>">
        </div>
        <div class="field">
          <label for="edit_email">E-mail address</label>
          <input type="email" id="edit_email" name="email" required maxlength="150" inputmode="email" value="<?= e((string) $editing['email']) ?>">
        </div>
        <div class="field">
          <label for="edit_phone">Phone (optional)</label>
          <input type="tel" id="edit_phone" name="phone" maxlength="30" inputmode="tel" value="<?= e((string) ($editing['phone'] ?? '')) ?>">
        </div>
        <div class="field">
          <label for="edit_parish">Parish</label>
          <?php /* A plain select rather than the live-search combobox: the directory can be long,
                   but this form is already scoped to one person, and a native picker is one tap on
                   a phone where the combobox is three. */ ?>
          <select id="edit_parish" name="parish_id">
            <option value="">— no parish on record —</option>
            <?php foreach ($parishes ?? [] as $parish): ?>
              <option value="<?= (int) $parish['id'] ?>" <?= (int) ($editing['parish_id'] ?? 0) === (int) $parish['id'] ? 'selected' : '' ?>>
                <?= e((string) $parish['parish_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($editing['parish_name_raw'])): ?>
            <span class="hint">Currently recorded as “<?= e((string) $editing['parish_name_raw']) ?>”, which is not in the directory. Choosing a parish above replaces it.</span>
          <?php endif; ?>
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Save changes</button>
    </form>
  </section>
<?php endif; ?>

<section class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Directory</h2>
      <p class="faint" style="margin:2px 0 0">Search and filters are in the address bar, so a view can be bookmarked.</p>
    </div>
  </div>

  <form method="get" action="<?= e(url('people')) ?>" class="filter-row" style="margin-top:12px">
    <div class="field grow" style="margin:0">
      <label for="q">Search</label>
      <input type="search" id="q" name="q" value="<?= e($term) ?>" placeholder="Name, e-mail or parish">
    </div>
    <div class="field" style="margin:0">
      <label for="role-filter">Role</label>
      <select id="role-filter" name="role">
        <option value="">Any role</option>
        <?php foreach ($roleLabels as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $roleFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="status-filter">Status</label>
      <select id="status-filter" name="status">
        <?php foreach (['' => 'Any status', 'active' => 'Active', 'suspended' => 'Suspended', 'temporary' => 'Temporary password'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-secondary" type="submit">Filter</button>
    <?php if ($term !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
      <a class="btn btn-quiet" href="<?= e(url('people')) ?>">Clear</a>
    <?php endif; ?>
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
        <label for="phone">Phone (optional)</label>
        <input type="tel" id="phone" name="phone" maxlength="30" inputmode="tel">
      </div>
      <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role" required>
          <?php foreach ($roleLabels as $key => $label): ?>
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
      <div class="field">
        <label for="parish_name_raw">Or type a church not listed</label>
        <input type="text" id="parish_name_raw" name="parish_name_raw" maxlength="150"
               style="text-transform:uppercase" placeholder="Saved in capitals">
        <span class="hint">It is added to the “churches members asked for” list below for a representative to add.</span>
      </div>
    </div>
    <div class="field">
      <label for="password">Temporary password</label>
      <input type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password">
      <span class="hint">Leave blank and one is generated for you — it is shown once, after saving. At least 12 characters if you choose it yourself.</span>
    </div>
    <button class="btn btn-primary" type="submit">Create account</button>
  </form>
</details>

<section class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Create another administrator</h2>
      <p class="faint" style="margin:2px 0 0">
        A second administrator means the province is never one forgotten password away from losing
        access to its own records. Both accounts have identical access, and both appear in the
        activity report — one administrator cannot act invisibly.
      </p>
    </div>
  </div>
  <form method="post" action="<?= e(url('')) ?>" style="margin-top:12px">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="user-admin-create">
    <input type="hidden" name="return_to" value="people">
    <div class="grid grid-2">
      <div class="field">
        <label for="admin_name">Full name</label>
        <input type="text" id="admin_name" name="full_name" required maxlength="150">
      </div>
      <div class="field">
        <label for="admin_email">E-mail address</label>
        <input type="email" id="admin_email" name="email" required maxlength="150" inputmode="email">
      </div>
      <div class="field">
        <label for="admin_phone">Phone (optional)</label>
        <input type="tel" id="admin_phone" name="phone" maxlength="30" inputmode="tel">
      </div>
      <div class="field">
        <label for="admin_password">Temporary password</label>
        <input type="password" id="admin_password" name="password" minlength="12" maxlength="72" autocomplete="new-password">
        <span class="hint">Leave blank for a generated one. The new administrator must change it at first sign-in.</span>
      </div>
    </div>
    <button class="btn btn-primary" type="submit"
            data-confirm="Give this person full administrator access to every member, parish, fund and report?">
      Create administrator
    </button>
  </form>
</section>

<?php if ($schemaGap !== null): ?>
  <?php /* The queue below reads a column that a later migration adds. If that migration has not
           been applied, saying so here is the difference between an operator who can fix it in a
           minute and one who sees a page that simply does not work. */ ?>
  <section class="card" style="border-color:var(--warn);background:var(--warn-soft)">
    <div class="card-head">
      <div>
        <h2 style="margin:0">Churches members asked for — unavailable</h2>
        <p class="muted" style="margin:6px 0 0"><?= e($schemaGap) ?></p>
      </div>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('updates')) ?>">Check for updates</a>
    </div>
  </section>
<?php endif; ?>

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
      <thead><tr><th>Person</th><th>Role</th><th>Parish</th><th>Last sign-in</th><th>Status</th><th>Manage</th></tr></thead>
      <tbody>
        <?php foreach ($people as $person): ?>
          <?php $isSelf = (int) $person['id'] === (int) $account['id']; ?>
          <tr>
            <td data-label="Person">
              <strong><?= e((string) $person['full_name']) ?></strong><?= $isSelf ? ' <span class="badge badge-info">you</span>' : '' ?><br>
              <span class="faint"><?= e((string) $person['email']) ?></span>
              <?php if (!empty($person['phone'])): ?><br><span class="faint"><?= e((string) $person['phone']) ?></span><?php endif; ?>
            </td>
            <td data-label="Role">
              <form method="post" action="<?= e(url('')) ?>" class="row" style="gap:6px">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="user-role">
                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                <input type="hidden" name="return_to" value="people">
                <select name="role" aria-label="Role for <?= e((string) $person['full_name']) ?>" style="min-height:40px;padding:6px 10px">
                  <?php foreach ($roleLabels as $key => $label): ?>
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
            <td data-label="Manage">
              <div class="manage-actions">
                <a class="btn btn-quiet btn-sm" href="<?= e(url('people', ['user' => (int) $person['id']])) ?>#edit-account">Edit</a>

                <?php if (!$isSelf): ?>
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

                  <form method="post" action="<?= e(url('')) ?>"
                        data-confirm="Issue a temporary password for <?= e((string) $person['full_name']) ?>? Their current password stops working immediately and every device is signed out.">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="user-temporary-password">
                    <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                    <button class="btn btn-quiet btn-sm" type="submit">New password</button>
                  </form>

                  <?php if ((string) $person['role'] !== 'provincial_admin' && (int) $person['is_suspended'] === 0): ?>
                    <form method="post" action="<?= e(url('')) ?>"
                          data-confirm="Sign in as <?= e((string) $person['full_name']) ?> to see their account as they see it? A banner will stay on screen, and everything you do is recorded against their account.">
                      <?= Security::csrfField() ?>
                      <input type="hidden" name="action" value="user-login-as">
                      <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                      <button class="btn btn-quiet btn-sm" type="submit">Sign in as</button>
                    </form>
                  <?php endif; ?>

                  <form method="post" action="<?= e(url('')) ?>"
                        data-confirm="Delete <?= e((string) $person['full_name']) ?> permanently? This cannot be undone. If they have any applications or disbursements on file the platform will refuse and tell you to suspend instead.">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="user-delete">
                    <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                    <button class="btn btn-quiet btn-sm" type="submit">Delete</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($people === []): ?><tr><td colspan="6" class="faint" style="padding:18px">No accounts match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pager !== null): ?>
    <?php
    $rows   = $people;
    $label  = 'people';
    require __DIR__ . '/../partials/pager.php';
    ?>
  <?php endif; ?>
</section>
