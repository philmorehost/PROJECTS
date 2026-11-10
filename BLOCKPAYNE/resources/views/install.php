<?php
declare(strict_types=1);

use App\Services\LicenseService;
use App\Support\DynamicBrand;
use App\Support\Env;
use App\Support\Security;

/**
 * 4-Stage Dynamic Web Installer View
 * Expects: $stage (int 1..4), $reqs (array), $error (?string), $message (?string)
 */
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$detectedDomain = LicenseService::normalizeDomain($host);

ob_start();
?>
<main class="bp-container" style="max-width:640px;padding-top:36px;padding-bottom:60px;">
  <div class="bp-row-between bp-mb-4">
    <div class="bp-brand-row">
      <span id="bp-install-badge-preview" class="bp-brand-badge">B</span>
      <div>
        <div style="font-weight:800;font-size:18px;">BlockPayne Setup</div>
        <div class="bp-subtle" style="font-size:12.5px;">4-Stage Non-Custodial Gateway Installer</div>
      </div>
    </div>
    <span class="bp-badge bp-badge-info">Stage <?= (int) $stage ?> of 4</span>
  </div>

  <!-- Progress Stepper -->
  <div class="bp-grid-4 bp-mb-4" style="grid-template-columns:repeat(4,1fr);gap:6px;">
    <?php foreach ([1 => '1. License', 2 => '2. Database', 3 => '3. Branding', 4 => '4. Ready'] as $num => $lbl): ?>
      <div class="bp-step <?= $stage === $num ? 'active' : ($stage > $num ? 'done' : '') ?>"><?= bp_e($lbl) ?></div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($error)): ?>
    <div class="bp-card-compact bp-mb-4" style="border-color:rgba(239,68,68,0.45);background:rgba(239,68,68,0.1);color:#fca5a5;">
      <?= bp_e($error) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($message) && empty($error)): ?>
    <div class="bp-card-compact bp-mb-4" style="border-color:rgba(16,185,129,0.45);background:rgba(16,185,129,0.1);color:#6ee7b7;">
      <?= bp_e($message) ?>
    </div>
  <?php endif; ?>

  <?php if ($stage === 1): ?>
    <div class="bp-card">
      <h2 style="margin:0 0 6px;font-size:19px;">Stage 1: System &amp; License Verification</h2>
      <p class="bp-muted" style="margin:0 0 16px;font-size:13.5px;">
        Verify server extensions and activate your domain (<code class="bp-mono"><?= bp_e($detectedDomain) ?></code>).
      </p>

      <div class="bp-table-wrap bp-mb-4">
        <table class="bp-table">
          <thead>
            <tr><th>Check</th><th>Required</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($reqs['checks'] as $chk): ?>
              <tr>
                <td><?= bp_e($chk['label']) ?></td>
                <td class="bp-subtle"><?= bp_e($chk['required']) ?></td>
                <td>
                  <?php if ($chk['passed']): ?>
                    <span class="bp-badge bp-badge-success">&#10003; <?= bp_e($chk['current']) ?></span>
                  <?php else: ?>
                    <span class="bp-badge bp-badge-danger">&#10007; <?= bp_e($chk['current']) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <form method="post" action="<?= bp_e(bp_url('install')) ?>">
        <input type="hidden" name="_token" value="<?= bp_e(Security::csrfToken()) ?>">
        <input type="hidden" name="stage" value="1">

        <div class="bp-field">
          <label class="bp-label" for="bp-lic-key">License Key</label>
          <input
            id="bp-lic-key"
            type="text"
            name="license_key"
            class="bp-input bp-mono"
            required
            placeholder="Enter license key for <?= bp_e($detectedDomain) ?>"
          >
          <div class="bp-subtle bp-mt-2" style="font-size:12px;">
            Validated via <a href="<?= bp_e(LicenseService::DOCS_URL) ?>" target="_blank" style="color:#34d399;text-decoration:underline;">License Manager API</a>
            &middot; Need a key? <a href="<?= bp_e(LicenseService::ORDER_URL) ?>" target="_blank" style="color:#34d399;text-decoration:underline;">Order for <?= bp_e($detectedDomain) ?></a>
          </div>
        </div>

        <button type="submit" class="bp-btn bp-btn-primary bp-btn-block" <?= !$reqs['all_passed'] ? 'disabled' : '' ?>>
          Verify License &amp; Continue &rarr;
        </button>
      </form>
    </div>

  <?php elseif ($stage === 2): ?>
    <div class="bp-card">
      <h2 style="margin:0 0 6px;font-size:19px;">Stage 2: Database Connection</h2>
      <p class="bp-muted" style="margin:0 0 16px;font-size:13.5px;">
        Connect MySQL 8.0+, auto-generate <code class="bp-mono">.env</code>, and run schema migrations.
      </p>

      <form method="post" action="<?= bp_e(bp_url('install')) ?>">
        <input type="hidden" name="_token" value="<?= bp_e(Security::csrfToken()) ?>">
        <input type="hidden" name="stage" value="2">
        <?php if (Env::get('DB_CONNECTION') === 'pglite'): ?>
          <input type="hidden" name="db_driver" value="pglite">
        <?php endif; ?>

        <div class="bp-grid-2">
          <div class="bp-field">
            <label class="bp-label">Database Host</label>
            <input type="text" name="db_host" class="bp-input" value="<?= bp_e(Env::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1') ?>" required>
          </div>
          <div class="bp-field">
            <label class="bp-label">Port</label>
            <input type="text" name="db_port" class="bp-input" value="<?= bp_e(Env::get('DB_PORT', '3306') ?? '3306') ?>" required>
          </div>
        </div>

        <div class="bp-field">
          <label class="bp-label">Database Name</label>
          <input type="text" name="db_database" class="bp-input" value="<?= bp_e(Env::get('DB_DATABASE', 'blockpayne') ?? '') ?>" placeholder="cpanel_blockpayne" required>
        </div>

        <div class="bp-grid-2">
          <div class="bp-field">
            <label class="bp-label">Database Username</label>
            <input type="text" name="db_username" class="bp-input" value="<?= bp_e(Env::get('DB_USERNAME', 'root') ?? '') ?>" required>
          </div>
          <div class="bp-field">
            <label class="bp-label">Database Password</label>
            <input type="password" name="db_password" class="bp-input" placeholder="Database password">
          </div>
        </div>

        <button type="submit" class="bp-btn bp-btn-primary bp-btn-block">
          Test Connection &amp; Run Migrations &rarr;
        </button>
      </form>
    </div>

  <?php elseif ($stage === 3): ?>
    <div class="bp-card">
      <h2 style="margin:0 0 6px;font-size:19px;">Stage 3: Super Admin &amp; Branding</h2>
      <p class="bp-muted" style="margin:0 0 16px;font-size:13.5px;">
        Your SVG Favicon &amp; Logo Badge auto-generate from the first letter of your Site Title.
      </p>

      <form method="post" action="<?= bp_e(bp_url('install')) ?>">
        <input type="hidden" name="_token" value="<?= bp_e(Security::csrfToken()) ?>">
        <input type="hidden" name="stage" value="3">

        <div class="bp-grid-3">
          <div class="bp-field" style="grid-column: span 2;">
            <label class="bp-label">Site Title</label>
            <input id="bp-install-title" type="text" name="site_title" class="bp-input" value="BlockPayne" required>
          </div>
          <div class="bp-field">
            <label class="bp-label">Accent Color</label>
            <input id="bp-install-color" type="color" name="brand_color" class="bp-input" value="#10b981" style="padding:4px;height:44px;">
          </div>
        </div>

        <div class="bp-field">
          <label class="bp-label">Gateway Operational Fee (%)</label>
          <input type="number" step="0.01" min="0" max="15" name="gateway_fee_percent" class="bp-input" value="1.00" required>
        </div>

        <hr style="border:none;border-top:1px solid #1e293b;margin:16px 0;">

        <div class="bp-field">
          <label class="bp-label">Super Admin Name</label>
          <input type="text" name="admin_name" class="bp-input" placeholder="Platform Administrator" required>
        </div>

        <div class="bp-grid-2">
          <div class="bp-field">
            <label class="bp-label">Admin Email</label>
            <input type="email" name="admin_email" class="bp-input" placeholder="admin@yourdomain.com" required>
          </div>
          <div class="bp-field">
            <label class="bp-label">Admin Password (min 8 chars)</label>
            <input type="password" name="admin_password" class="bp-input" minlength="8" required>
          </div>
        </div>

        <button type="submit" class="bp-btn bp-btn-primary bp-btn-block">
          Create Admin &amp; Finish Setup &rarr;
        </button>
      </form>
    </div>

  <?php else: ?>
    <div class="bp-card" style="text-align:center;">
      <div class="bp-badge bp-badge-success bp-mb-2">Installation Locked &amp; Complete</div>
      <h2 style="margin:6px 0 8px;font-size:22px;">Your Crypto Gateway is Ready!</h2>
      <p class="bp-muted" style="margin:0 0 18px;font-size:14px;">
        Lock file written to <code class="bp-mono">storage/installed</code>. Add this 1-minute Cron job in cPanel to poll blockchain nodes automatically:
      </p>

      <div class="bp-card-compact bp-mono" style="text-align:left;font-size:12.5px;background:#020617;margin-bottom:20px;overflow-x:auto;">
        * * * * * php <?= bp_e(BP_ROOT) ?>/artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1
      </div>

      <a href="<?= bp_e(bp_url('dashboard')) ?>" class="bp-btn bp-btn-primary bp-btn-block">
        Enter Admin Panel &rarr;
      </a>
    </div>
  <?php endif; ?>
</main>
<?php
$bodyContent = (string) ob_get_clean();
$pageTitle = 'Installer';
require __DIR__ . '/layout.php';
