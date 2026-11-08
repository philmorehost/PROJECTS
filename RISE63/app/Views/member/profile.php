<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Security;

use function RISE63\{e, human_date, initials, relative_time, url};

$isTreasury = Auth::isTreasurer($account);
?>
<section class="card">
  <div class="row" style="gap:14px">
    <span class="brand-mark" style="--brand-hue:214"><?= e(initials((string) $account['full_name'])) ?></span>
    <div>
      <h1 style="margin:0;font-size:1.375rem"><?= e((string) $account['full_name']) ?></h1>
      <p class="faint" style="margin:2px 0 0">
        <?= e(Auth::ROLES[(string) $account['role']] ?? (string) $account['role']) ?>
        <?= $parish !== null ? ' · ' . e((string) $parish['parish_name']) : '' ?>
      </p>
    </div>
  </div>
</section>

<div class="grid grid-2">
  <section class="card">
    <h2>Profile details</h2>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="profile-save">
      <input type="hidden" name="return_to" value="profile">
      <div class="field">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required maxlength="150" value="<?= e((string) $account['full_name']) ?>" autocomplete="name">
      </div>
      <div class="field">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone" maxlength="25" value="<?= e((string) ($account['phone'] ?? '')) ?>" autocomplete="tel">
      </div>
      <div class="field" data-combobox data-endpoint="<?= e(url('api/index.php')) ?>">
        <label for="parish-search">My parish</label>
        <div class="combo">
          <input type="search" id="parish-search" role="combobox" aria-expanded="false" aria-autocomplete="list"
                 aria-controls="parish-list-profile" autocomplete="off"
                 value="<?= $parish !== null ? e($parish['parish_name'] . ' · ' . $parish['area_name'] . ' · ' . $parish['zone_name']) : '' ?>">
          <input type="hidden" name="parish_id" value="<?= $parish !== null ? (int) $parish['id'] : '' ?>">
          <ul class="combo-list" id="parish-list-profile" role="listbox" hidden></ul>
        </div>
        <span class="hint">Type to search by parish, area or zone.</span>
      </div>
      <div class="field">
        <label>E-mail address</label>
        <input type="email" value="<?= e((string) $account['email']) ?>" readonly aria-readonly="true">
        <span class="hint">Contact the province office to change the e-mail on your account.</span>
      </div>
      <button class="btn btn-primary" type="submit">Save profile</button>
    </form>
  </section>

  <section class="card">
    <h2>Appearance &amp; alerts</h2>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="preference-save">
      <input type="hidden" name="return_to" value="profile">
      <div class="field">
        <label for="theme_preference">Appearance</label>
        <select id="theme_preference" name="theme_preference">
          <?php foreach (['system' => 'Follow my device', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= (string) $account['theme_preference'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="check">
        <input type="checkbox" id="large_text" name="large_text" value="1" <?= (int) $account['large_text'] === 1 ? 'checked' : '' ?>>
        <label for="large_text" style="font-weight:400">Larger text (easier reading)</label>
      </div>
      <div class="check">
        <input type="checkbox" id="alerts" name="login_notifications_enabled" value="1"
               <?= (int) $account['login_notifications_enabled'] === 1 ? 'checked' : '' ?>>
        <label for="alerts" style="font-weight:400">E-mail me when my account signs in from a new network</label>
      </div>
      <button class="btn btn-primary" type="submit">Save preferences</button>
    </form>
  </section>
</div>

<div class="grid grid-2">
  <section class="card">
    <h2>Change password</h2>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="password-change">
      <input type="hidden" name="return_to" value="profile">
      <div class="field">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="field">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required minlength="12" maxlength="72" autocomplete="new-password">
        <span class="hint">At least 12 characters. Other devices will be signed out.</span>
      </div>
      <div class="field">
        <label for="new_password_confirm">Repeat new password</label>
        <input type="password" id="new_password_confirm" name="new_password_confirm" required minlength="12" maxlength="72" autocomplete="new-password">
      </div>
      <button class="btn btn-secondary" type="submit">Change password</button>
    </form>
  </section>

  <?php if ($isTreasury): ?>
    <section class="card">
      <h2>Transaction PIN</h2>
      <p class="muted">The four-digit PIN confirms disbursements. It is separate from your password and locked after repeated mistakes.</p>
      <?php if ($pinLock > 0): ?>
        <div class="flash flash-error" role="alert">PIN locked for about <?= (int) ceil($pinLock / 60) ?> more minute(s).</div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('')) ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="pin-set">
        <input type="hidden" name="return_to" value="profile">
        <div class="field">
          <label for="current_password_pin">Password</label>
          <input type="password" id="current_password_pin" name="current_password" required autocomplete="current-password">
        </div>
        <div class="field">
          <label for="pin">New four-digit PIN</label>
          <input type="password" id="pin" name="pin" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="new-password">
          <span class="hint">Sequences such as 1234 and repeats such as 0000 are rejected.</span>
        </div>
        <button class="btn btn-secondary" type="submit">Set PIN</button>
      </form>
    </section>
  <?php endif; ?>
</div>

<section class="card">
  <div class="card-head">
    <h2 style="margin:0">Devices</h2>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="revoke-devices">
      <input type="hidden" name="return_to" value="profile">
      <button class="btn btn-secondary btn-sm" type="submit">Sign out other devices</button>
    </form>
  </div>
  <ul class="list-plain">
    <?php foreach ($devices as $device): ?>
      <li>
        <strong><?= e(mb_strimwidth((string) ($device['user_agent'] ?? 'Unknown device'), 0, 60, '…')) ?></strong><br>
        <span class="faint"><?= e((string) $device['ip_address']) ?> · last seen <?= e(relative_time((string) $device['last_seen_at'])) ?>
          · signed in <?= e(human_date((string) $device['created_at'], true)) ?></span>
      </li>
    <?php endforeach; ?>
    <?php if ($devices === []): ?><li class="faint">No active sessions recorded.</li><?php endif; ?>
  </ul>
</section>
