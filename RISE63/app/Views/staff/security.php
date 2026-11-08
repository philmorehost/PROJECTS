<?php
declare(strict_types=1);

use RISE63\Core\BruteForce;
use RISE63\Core\Security;
use RISE63\Core\Settings;

use function RISE63\{e, human_date, relative_time, url};

$bool = static fn (string $key): bool => (string) ($settings[$key] ?? '0') === '1';
$val  = static fn (string $key, string $fallback = ''): string => (string) ($settings[$key] ?? $fallback);
?>
<section class="card">
  <h1>Security centre</h1>
  <p class="muted">cPHulk-style protection for accounts and IP addresses, country filtering and a firewall queue.
    Every decision below is written to the security log with its reason.</p>
  <div class="grid grid-3" style="margin-top:12px">
    <div class="stat"><b><?= (int) $counts['failed'] ?></b><span>Failed sign-ins (24 h)</span></div>
    <div class="stat"><b><?= (int) $counts['blockedIp'] ?></b><span>Blocked IPs</span></div>
    <div class="stat"><b><?= (int) $counts['suspended'] ?></b><span>Suspended accounts</span></div>
  </div>
</section>

<details class="card" open>
  <summary style="cursor:pointer;font-weight:700;min-height:32px">Protection policy</summary>
  <form method="post" action="<?= e(url('')) ?>" style="margin-top:14px">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="security-save">
    <input type="hidden" name="return_to" value="security">

    <h3>Account protection</h3>
    <div class="check">
      <input type="checkbox" id="username_protection" name="settings_bool[username_protection]" value="1" <?= $bool('username_protection') ? 'checked' : '' ?>>
      <label for="username_protection" style="font-weight:400">Enable account protection (existing suspensions are kept when this is switched off)</label>
    </div>
    <div class="grid grid-2">
      <div class="field">
        <label for="account_window_minutes">Protection period (minutes)</label>
        <input type="number" id="account_window_minutes" name="settings[account_window_minutes]" min="1" max="1440" value="<?= e($val('account_window_minutes', '15')) ?>">
      </div>
      <div class="field">
        <label for="account_max_failures">Maximum failures per account</label>
        <input type="number" id="account_max_failures" name="settings[account_max_failures]" min="2" max="50" value="<?= e($val('account_max_failures', '5')) ?>">
      </div>
      <div class="field">
        <label for="protection_scope">Scope</label>
        <select id="protection_scope" name="settings[protection_scope]">
          <option value="all" <?= $val('protection_scope') === 'all' ? 'selected' : '' ?>>Local and remote addresses</option>
          <option value="local" <?= $val('protection_scope') === 'local' ? 'selected' : '' ?>>Local addresses only</option>
        </select>
      </div>
      <div class="check" style="min-height:auto">
        <input type="checkbox" id="protect_privileged" name="settings_bool[protect_privileged]" value="1" <?= $bool('protect_privileged') ? 'checked' : '' ?>>
        <label for="protect_privileged" style="font-weight:400">Allow protection to lock privileged accounts (administrator, treasurer)</label>
      </div>
    </div>

    <h3 style="margin-top:18px">IP protection</h3>
    <div class="check">
      <input type="checkbox" id="ip_protection" name="settings_bool[ip_protection]" value="1" <?= $bool('ip_protection') ? 'checked' : '' ?>>
      <label for="ip_protection" style="font-weight:400">Enable IP protection</label>
    </div>
    <div class="grid grid-2">
      <div class="field">
        <label for="ip_window_minutes">Protection period (minutes)</label>
        <input type="number" id="ip_window_minutes" name="settings[ip_window_minutes]" min="1" max="1440" value="<?= e($val('ip_window_minutes', '15')) ?>">
      </div>
      <div class="field">
        <label for="ip_max_failures">Maximum failures per IP</label>
        <input type="number" id="ip_max_failures" name="settings[ip_max_failures]" min="2" max="200" value="<?= e($val('ip_max_failures', '10')) ?>">
      </div>
      <div class="field">
        <label for="block_duration">Block duration</label>
        <select id="block_duration" name="settings[block_duration]">
          <?php foreach (BruteForce::DURATIONS as $key => $days): ?>
            <option value="<?= e($key) ?>" <?= $val('block_duration', 'day') === $key ? 'selected' : '' ?>>
              <?= e(ucfirst($key)) ?> (<?= (int) $days ?> day<?= $days > 1 ? 's' : '' ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="auto_whitelist_sessions">Clean sessions before recognition</label>
        <input type="number" id="auto_whitelist_sessions" name="settings[auto_whitelist_sessions]" min="0" max="20" value="<?= e($val('auto_whitelist_sessions', '5')) ?>">
        <span class="hint">0 disables automatic recognition. Recognised addresses still obey the failure limits.</span>
      </div>
      <div class="field">
        <label for="log_retention_minutes">Failed-login retention</label>
        <select id="log_retention_minutes" name="settings[log_retention_minutes]">
          <?php foreach (['5' => '5 minutes', '10' => '10 minutes', '15' => '15 minutes', '60' => '60 minutes'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $val('log_retention_minutes', '60') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="check" style="min-height:auto">
        <input type="checkbox" id="unknown_user_insta_block" name="settings_bool[unknown_user_insta_block]" value="1" <?= $bool('unknown_user_insta_block') ? 'checked' : '' ?>>
        <label for="unknown_user_insta_block" style="font-weight:400">Block the IP immediately when the account does not exist</label>
      </div>
      <div class="check" style="min-height:auto">
        <input type="checkbox" id="escalate_repeat_blocks" name="settings_bool[escalate_repeat_blocks]" value="1" <?= $bool('escalate_repeat_blocks') ? 'checked' : '' ?>>
        <label for="escalate_repeat_blocks" style="font-weight:400">Escalate repeat offenders: day → week → month → year</label>
      </div>
    </div>

    <h3 style="margin-top:18px">Country &amp; firewall</h3>
    <div class="grid grid-2">
      <div class="check" style="min-height:auto">
        <input type="checkbox" id="country_filter_enabled" name="settings_bool[country_filter_enabled]" value="1" <?= $bool('country_filter_enabled') ? 'checked' : '' ?>>
        <label for="country_filter_enabled" style="font-weight:400">Apply country rules</label>
      </div>
      <div class="field">
        <label for="country_unknown_policy">When the country cannot be determined</label>
        <select id="country_unknown_policy" name="settings[country_unknown_policy]">
          <option value="allow" <?= $val('country_unknown_policy', 'allow') === 'allow' ? 'selected' : '' ?>>Allow (recommended)</option>
          <option value="deny" <?= $val('country_unknown_policy') === 'deny' ? 'selected' : '' ?>>Deny</option>
        </select>
        <span class="hint">Denying unknown countries can lock out legitimate members on shared mobile networks.</span>
      </div>
      <div class="check" style="min-height:auto">
        <input type="checkbox" id="firewall_enabled" name="settings_bool[firewall_enabled]" value="1" <?= $bool('firewall_enabled') ? 'checked' : '' ?>>
        <label for="firewall_enabled" style="font-weight:400">Queue firewall jobs for the CLI worker</label>
      </div>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Save policy</button>
    </div>
  </form>
  <form method="post" action="<?= e(url('')) ?>" style="margin-top:10px">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="run-maintenance">
    <input type="hidden" name="return_to" value="security">
    <button class="btn btn-secondary btn-sm" type="submit">Prune old failure logs now</button>
  </form>
</details>

<div class="grid grid-2">
  <section class="card">
    <h2>Blocked addresses</h2>
    <ul class="list-plain">
      <?php foreach ($blocked as $row): ?>
        <li>
          <div class="row row-end">
            <span>
              <strong class="mono"><?= e((string) $row['ip_address']) ?></strong>
              <?php if ((int) $row['whitelisted'] === 1): ?><span class="badge badge-crown"><span class="crown" aria-hidden="true">♛</span> Recognised</span><?php endif; ?>
              <br><span class="faint">Until <?= e(human_date((string) $row['blocked_until'], true)) ?> · level <?= (int) $row['escalation_level'] ?></span>
            </span>
            <form method="post" action="<?= e(url('')) ?>">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="ip-unblock">
              <input type="hidden" name="return_to" value="security">
              <input type="hidden" name="ip" value="<?= e((string) $row['ip_address']) ?>">
              <button class="btn btn-secondary btn-sm" type="submit">Clear</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if ($blocked === []): ?><li class="faint">No active blocks.</li><?php endif; ?>
    </ul>
  </section>

  <section class="card">
    <h2>Recognised addresses <span class="crown" aria-hidden="true">♛</span></h2>
    <p class="faint">Five clean sessions mark an address as recognised. The badge suppresses new-device alerts; it does not bypass failure limits.</p>
    <ul class="list-plain">
      <?php foreach ($recognised as $row): ?>
        <li class="row row-end">
          <span class="mono"><?= e((string) $row['ip_address']) ?></span>
          <span class="badge badge-ok"><?= (int) $row['clean_sessions'] ?> clean</span>
        </li>
      <?php endforeach; ?>
      <?php if ($recognised === []): ?><li class="faint">No recognised addresses yet.</li><?php endif; ?>
    </ul>
  </section>
</div>

<section class="card">
  <h2>Suspended accounts</h2>
  <ul class="list-plain">
    <?php foreach ($suspended as $row): ?>
      <li class="row row-end">
        <span>
          <strong><?= e((string) $row['full_name']) ?></strong>
          <br><span class="faint"><?= e((string) $row['email']) ?></span>
        </span>
        <form method="post" action="<?= e(url('')) ?>">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="account-suspend">
          <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
          <input type="hidden" name="suspend" value="0">
          <input type="hidden" name="return_to" value="security">
          <button class="btn btn-secondary btn-sm" type="submit">Reinstate</button>
        </form>
      </li>
    <?php endforeach; ?>
    <?php if ($suspended === []): ?><li class="faint">No suspended accounts.</li><?php endif; ?>
  </ul>
</section>

<div class="grid grid-2">
  <section class="card">
    <h2>Manual rules</h2>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="access-rule">
      <input type="hidden" name="return_to" value="security">
      <div class="field">
        <label for="rule_type">Rule applies to</label>
        <select id="rule_type" name="rule_type">
          <option value="ip">IP address</option>
          <option value="username">Account e-mail</option>
        </select>
      </div>
      <div class="field">
        <label for="target_value">Value</label>
        <input type="text" id="target_value" name="target_value" required maxlength="150">
      </div>
      <div class="field">
        <label for="action">Action</label>
        <select id="action" name="action">
          <option value="whitelisted">Whitelisted (never blocked)</option>
          <option value="blacklisted">Blacklisted (always refused)</option>
        </select>
      </div>
      <button class="btn btn-secondary" type="submit">Add rule</button>
    </form>
    <ul class="list-plain" style="margin-top:14px">
      <?php foreach ($rules as $rule): ?>
        <li class="row row-end">
          <span>
            <strong class="mono"><?= e((string) $rule['target_value']) ?></strong>
            <br><span class="faint"><?= e((string) $rule['rule_type']) ?> · <?= e((string) $rule['action']) ?>
              <?= !empty($rule['full_name']) ? ' · added by ' . e((string) $rule['full_name']) : '' ?></span>
          </span>
          <form method="post" action="<?= e(url('')) ?>">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="access-rule-delete">
            <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
            <input type="hidden" name="return_to" value="security">
            <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if ($rules === []): ?><li class="faint">No manual rules.</li><?php endif; ?>
    </ul>
  </section>

  <section class="card">
    <h2>Firewall queue</h2>
    <p class="faint">PHP never runs a firewall command. These jobs are applied by <code>bin/security-worker.php</code> with a least-privilege adapter.</p>
    <ul class="list-plain">
      <?php foreach ($firewall as $job): ?>
        <li class="row row-end">
          <span class="mono"><?= e((string) $job['ip_address']) ?></span>
          <span class="badge <?= (string) $job['status'] === 'applied' ? 'badge-ok' : ((string) $job['status'] === 'failed' ? 'badge-danger' : 'badge-warn') ?>">
            <?= e((string) $job['status']) ?>
          </span>
        </li>
      <?php endforeach; ?>
      <?php if ($firewall === []): ?><li class="faint">Queue is empty.</li><?php endif; ?>
    </ul>
  </section>
</div>

<section class="card">
  <h2>Country rules</h2>
  <p class="muted">250 countries are pre-loaded. Filter live and set each to whitelisted, not specified or blacklisted.</p>
  <div class="field">
    <label for="country-filter">Filter countries</label>
    <input type="search" id="country-filter" placeholder="Start typing a country name" autocomplete="off">
  </div>
  <div class="table-wrap" style="max-height:420px;overflow-y:auto">
    <table id="country-table">
      <thead><tr><th>Country</th><th>Code</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($countries as $country): ?>
          <tr data-country="<?= e(strtolower((string) $country['country_name'])) ?>">
            <td><?= e((string) $country['country_name']) ?></td>
            <td class="mono"><?= e((string) $country['country_code']) ?></td>
            <td>
              <form method="post" action="<?= e(url('')) ?>" class="row" style="gap:6px">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="country-rule">
                <input type="hidden" name="country_code" value="<?= e((string) $country['country_code']) ?>">
                <input type="hidden" name="return_to" value="security">
                <select name="action" aria-label="Rule for <?= e((string) $country['country_name']) ?>" style="min-height:40px;padding:6px 10px">
                  <option value="whitelisted" <?= (string) $country['action_code'] === 'whitelisted' ? 'selected' : '' ?>>Whitelisted</option>
                  <option value="not_specified" <?= (string) $country['action_code'] === 'not_specified' ? 'selected' : '' ?>>Not specified</option>
                  <option value="blacklisted" <?= (string) $country['action_code'] === 'blacklisted' ? 'selected' : '' ?>>Blacklisted</option>
                </select>
                <button class="btn btn-secondary btn-sm" type="submit">Save</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <h2>Security log</h2>
  <form method="get" action="<?= e(url('security')) ?>" class="row">
    <div class="field grow" style="margin:0">
      <label for="ip">Filter by IP</label>
      <input type="text" id="ip" name="ip" value="<?= e($ipFilter) ?>" maxlength="45">
    </div>
    <button class="btn btn-secondary" type="submit">Filter</button>
    <?php if ($ipFilter !== ''): ?><a class="btn btn-ghost" href="<?= e(url('security')) ?>">Clear</a><?php endif; ?>
  </form>
  <div class="table-wrap" style="margin-top:12px">
    <table class="table-stack">
      <thead><tr><th>When</th><th>Event</th><th>Address</th><th>Country</th><th>Account</th><th>Details</th></tr></thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td data-label="When"><?= e(relative_time((string) $log['created_at'])) ?></td>
            <td data-label="Event"><?= e(str_replace('_', ' ', (string) $log['event_type'])) ?></td>
            <td data-label="Address" class="mono"><?= e((string) $log['ip_address']) ?></td>
            <td data-label="Country"><?= e((string) ($log['country_code'] ?? '—')) ?></td>
            <td data-label="Account"><?= e((string) ($log['username_attempted'] ?? '—')) ?></td>
            <td data-label="Details"><?= e((string) ($log['details'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($logs === []): ?><tr><td colspan="6" class="faint" style="padding:16px">No events match.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<script src="<?= e(RISE63\asset('security.js')) ?>" defer></script>
