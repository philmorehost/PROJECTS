<?php
declare(strict_types=1);

/**
 * RISE 63 — four-stage installer.
 *
 * Reached only from index.php when config/installed.lock is absent. Once the installation is
 * locked this file does nothing but say so; it never reveals configuration details.
 *
 * Layout notes: every asset URL goes through RISE63\asset(), which resolves against the front
 * controller's directory — never SCRIPT_NAME. Using SCRIPT_NAME here produced
 * /install/public/assets/app.css (404) and the wizard rendered as unstyled HTML.
 */

use RISE63\Core\Activation;
use RISE63\Core\Installer;
use RISE63\Core\Security;

use function RISE63\{asset, config_dir, e, installed, site_title, url};

// This file is deliberately outside the RISE63 namespace, so every bootstrap constant needs a
// fully qualified name here: a bare ROOT resolves to the global namespace and fatals.
use const RISE63\{ROOT, VERSION};

require_once dirname(__DIR__) . '/app/bootstrap.php';

Security::startSession();
Security::sendHeaders();

$installer = new Installer(config_dir(), ROOT);

/**
 * One shared head/body wrapper so all four stages, the lock screen and the success screen look
 * like the same product. $body is the markup between <body> and </body>.
 */
$page = static function (string $title, string $body, string $extraClass = 'wizard'): void {
    ?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · RISE 63 setup</title>
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#2563eb">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="<?= e($extraClass) ?>">
<?= $body ?>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html><?php
};

if ($installer->installed()) {
    http_response_code(409);
    ob_start();
    ?>
<main class="wizard-wrap">
  <div class="wizard-top">
    <span class="brand-mark" style="--brand-hue:220" aria-hidden="true">R</span>
    <div>
      <h1>Already installed</h1>
      <p>This copy of RISE 63 has finished setup.</p>
    </div>
  </div>
  <div class="card wizard-panel">
    <h2>The setup wizard is locked</h2>
    <p class="lead">Setup can only run once. To start again, restore a backup, or ask the hosting
      operator to remove <code>config/installed.lock</code> from a copy of the site — never from the
      live one.</p>
    <div class="notice">
      <strong>Looking for the application?</strong>
      The sign-in page lives at the address below. If you expected to be signed in, ask a provincial
      administrator for an account.
    </div>
    <div class="wizard-actions">
      <a class="btn btn-primary btn-block" href="<?= e(url('')) ?>">Go to the sign-in page</a>
    </div>
  </div>
</main>
    <?php
    $page('Already installed', (string) ob_get_clean());
    exit;
}

// One installer at a time.
$mutex = @fopen(config_dir() . '/setup.mutex', 'c');
if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    ob_start();
    ?>
<main class="wizard-wrap">
  <div class="wizard-top">
    <span class="brand-mark" style="--brand-hue:220" aria-hidden="true">R</span>
    <div><h1>Setup is busy</h1><p>Another setup session holds the lock.</p></div>
  </div>
  <div class="card wizard-panel">
    <h2>One installer at a time</h2>
    <p class="lead">Another tab or browser is part-way through setup. That is a safety measure: two
      installers writing configuration at once would corrupt it.</p>
    <div class="notice">Close the other tab, wait ten seconds, then reload this page.</div>
    <div class="wizard-actions">
      <a class="btn btn-secondary btn-block" href="<?= e(url('install/index.php')) ?>">Reload setup</a>
    </div>
  </div>
</main>
    <?php
    $page('Setup is busy', (string) ob_get_clean());
    exit;
}
@chmod(config_dir() . '/setup.mutex', 0600);

$stage         = $installer->stage();
$error         = '';
$licenseNotice = '';
$verifiedKey   = '';
$old = [
    'host'                => 'localhost',
    'port'                => '3306',
    'name'                => '',
    'user'                => '',
    'full_name'           => '',
    'email'               => '',
    'site_title'          => 'RISE 63',
    'notification_email'  => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Security::assertPost();
        $stage = (int) ($_POST['stage'] ?? 1);

        if ($stage === 1) {
            $checks = $installer->environment();
            if (!$installer->environmentOk($checks)) {
                throw new RuntimeException('Resolve the items marked “Action required” before continuing.');
            }

            $activation = new Activation(config_dir());
            $host       = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $key        = trim((string) ($_POST['license_key'] ?? ''));
            // The domain shown is read from the request, but the operator may correct it: a host
            // behind a proxy, or a licence registered to the bare domain while the site is served
            // from a subdomain, both need this.
            $formDomain = trim((string) ($_POST['license_domain'] ?? ''));
            if ($formDomain !== '' && Activation::domainFromHost($formDomain) !== Activation::domainFromHost($host)) {
                $host = $formDomain;
            }

            if (($_POST['verify_only'] ?? '') === '1') {
                // Ask the manager without committing, so the operator can see the answer first.
                $probe = $activation->verify($key, $host);
                if ($probe['valid']) {
                    $licenseNotice = 'That key is valid for ' . $probe['domain']
                        . '. Press “Save key and continue” to use it.';
                    $verifiedKey = $key;
                } else {
                    $verifiedKey = $key;   // keep it in the field so a typo can be corrected
                    throw new RuntimeException(
                        $probe['message'] . ' The domain sent was ' . $probe['domain']
                        . ' — if the licence was issued for a different name, correct the field above.'
                    );
                }
            } elseif ($key !== '') {
                // Never write a key the provider has not confirmed: an unverified key on disk
                // would block the next attempt for no reason.
                $probe = $activation->verify($key, $host);
                $verifiedKey = $key;
                if (!$probe['valid']) {
                    throw new RuntimeException(
                        $probe['message'] . ' The domain sent was ' . $probe['domain'] . '.'
                    );
                }
                $activation->storeKey($key);
                $activation->verify($key, $host, true);   // record it; no second call needed
                $licenseNotice = 'Licence saved and confirmed for ' . $probe['domain'] . '.';
                $installer->advance(2);
            } else {
                // Resuming setup with a key already on disk. If the provider already accepted this
                // domain for that key there is nothing to ask: forcing a call here would let a
                // rate-limited provider block a setup that is already licensed.
                $probeDomain = Activation::domainFromHost($host);
                if ($activation->storedKey() !== null && $activation->domainAccepted($probeDomain)) {
                    $licenseNotice = 'The saved key is confirmed for ' . $probeDomain . '.';
                } else {
                    $state = $activation->check($host, true);
                    if (!$state['valid']) {
                        throw new RuntimeException($state['message'] . ' The domain sent was ' . $state['domain'] . '.');
                    }
                    $licenseNotice = $state['message'];
                }
                $installer->advance(2);
            }
        } elseif ($stage === 2) {
            $old = [
                'host' => (string) ($_POST['host'] ?? 'localhost'),
                'port' => (string) ($_POST['port'] ?? '3306'),
                'name' => (string) ($_POST['name'] ?? ''),
                'user' => (string) ($_POST['user'] ?? ''),
            ];
            $installer->createDatabase([
                'host'     => $old['host'],
                'port'     => $old['port'],
                'name'     => $old['name'],
                'user'     => $old['user'],
                'password' => (string) ($_POST['password'] ?? ''),
                'socket'   => (string) ($_POST['socket'] ?? ''),
            ]);
        } elseif ($stage === 3) {
            $old = [
                'full_name'          => (string) ($_POST['full_name'] ?? ''),
                'email'              => (string) ($_POST['email'] ?? ''),
                'site_title'         => (string) ($_POST['site_title'] ?? 'RISE 63'),
                'notification_email' => (string) ($_POST['notification_email'] ?? ''),
            ];
            $replacement = trim((string) ($_POST['license_key'] ?? ''));
            $replacementDomain = trim((string) ($_POST['license_domain'] ?? ''));
            $host3 = $replacementDomain !== '' ? $replacementDomain : (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($replacement !== '') {
                $activation3 = new Activation(config_dir());
                $probe3      = $activation3->verify($replacement, $host3);
                if (!$probe3['valid']) {
                    throw new RuntimeException('The replacement licence key was not accepted: ' . $probe3['message']);
                }
                $activation3->storeKey($replacement);
            }
            // The domain may have been corrected in either step, so it is passed through explicitly
            // rather than re-derived from the request host inside the installer.
            $installer->createAdministrator([
                'domain'             => $host3,
                'full_name'          => $old['full_name'],
                'email'              => $old['email'],
                'password'           => (string) ($_POST['password'] ?? ''),
                'password_confirm'   => (string) ($_POST['password_confirm'] ?? ''),
                'pin'                => (string) ($_POST['pin'] ?? ''),
                'site_title'         => $old['site_title'],
                'notification_email' => $old['notification_email'],
            ]);
        } elseif ($stage === 4) {
            $installer->complete();
            flock($mutex, LOCK_UN);
            fclose($mutex);
            ob_start();
            ?>
<main class="wizard-wrap">
  <div class="wizard-done">
    <div class="done-mark" aria-hidden="true">✓</div>
    <h2>RISE 63 is ready</h2>
    <p>Setup is finished and the wizard is now locked. Work through the checklist below before you
      share the link with anyone.</p>
  </div>
  <div class="card wizard-panel">
    <h2>Before you share the link</h2>
    <ul class="checklist">
      <li class="is-ok"><span class="check-icon" aria-hidden="true">1</span>
        <span class="check-body"><strong>HTTPS is on</strong><span>Confirm the padlock; secure cookies are only issued over TLS.</span></span></li>
      <li class="is-ok"><span class="check-icon" aria-hidden="true">2</span>
        <span class="check-body"><strong>Private folders return 403</strong><span>Open <code>/config/</code> and <code>/storage/</code> in a browser. Both must be refused.</span></span></li>
      <li class="is-ok"><span class="check-icon" aria-hidden="true">3</span>
        <span class="check-body"><strong>Backups are scheduled</strong><span>Database and files, before real member data arrives.</span></span></li>
      <li class="is-ok"><span class="check-icon" aria-hidden="true">4</span>
        <span class="check-body"><strong>Cron workers are running</strong><span><code>bin/mail-worker.php</code> and <code>bin/security-worker.php</code>, every five minutes.</span></span></li>
      <li class="is-ok"><span class="check-icon" aria-hidden="true">5</span>
        <span class="check-body"><strong>Self-check passes</strong><span>Run <code>php bin/doctor.php</code> on the server.</span></span></li>
    </ul>
    <div class="wizard-actions">
      <a class="btn btn-primary btn-block" href="<?= e(url('')) ?>">Open the sign-in page</a>
    </div>
  </div>
</main>
            <?php
            $page('Setup complete', (string) ob_get_clean());
            exit;
        }

        header('Location: ' . url('install/index.php'), true, 303);
        exit;
    } catch (RISE63\Core\Blocked $blocked) {
        $error = $blocked->getMessage();
    } catch (Throwable $failure) {
        error_log('RISE63 setup: ' . $failure->getMessage());
        $error = $failure instanceof PDOException
            ? 'The database connection or schema step failed. Check the host, database name, user and password.'
            : $failure->getMessage();
    } finally {
        if (isset($mutex) && is_resource($mutex)) {
            flock($mutex, LOCK_UN);
            fclose($mutex);
        }
    }
}

$stage  = $installer->stage();
$checks = $stage === 1 ? $installer->environment() : [];
$host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
$domain = Activation::domainFromHost($host);

$license = $stage === 1 ? new Activation(config_dir()) : null;
// Display only: never block the first paint on a network call. If there is no cached answer the
// panel simply shows nothing, and the operator triggers the real check with the button.
$current = ['valid' => false, 'message' => '', 'grace' => false, 'domain' => ''];
$cachedLicense = $license?->cache();
if (is_array($cachedLicense) && $domain === ($cachedLicense['domain'] ?? null)) {
    $current = [
        'valid'   => !empty($cachedLicense['valid']),
        'message' => !empty($cachedLicense['valid'])
            ? 'Last confirmed ' . gmdate('j M Y H:i', (int) ($cachedLicense['checked_at'] ?? 0)) . ' UTC'
            : 'The last check did not confirm this domain.',
        'grace'   => (int) ($cachedLicense['checked_at'] ?? 0) > 0 && !empty($cachedLicense['valid']),
        'domain'  => (string) $cachedLicense['domain'],
    ];
}
$maskedKey = '';
$stored    = $license?->storedKey();
if ($stored !== null) {
    $value     = (string) $stored['key'];
    $maskedKey = substr($value, 0, 4) . str_repeat('•', max(4, strlen($value) - 8)) . substr($value, -4);
}

$stageTitles = [
    1 => ['Licence and server check', 'Confirm this server can run RISE 63, then licence this domain.'],
    2 => ['Database', 'Point the site at an empty MySQL database.'],
    3 => ['Provincial administrator', 'The first account, which manages security and approves programs.'],
    4 => ['Ready to finish', 'Lock the installer and hand the site over.'],
];
[$stageTitle, $stageLead] = $stageTitles[$stage] ?? $stageTitles[1];

ob_start();
?>
<main class="wizard-wrap">
  <header class="wizard-top">
    <span class="brand-mark" style="--brand-hue:220" aria-hidden="true">R</span>
    <div>
      <h1>Set up RISE 63</h1>
      <p>Four steps · about two minutes</p>
    </div>
    <div class="wizard-version">
      Version <?= e(VERSION) ?><br>
      <span class="faint">PHP <?= e(PHP_VERSION) ?></span>
    </div>
  </header>

  <ol class="wizard-steps" aria-label="Setup progress">
    <?php foreach (Installer::STAGES as $number => $label): ?>
      <li class="<?= $number === $stage ? 'is-current' : ($number < $stage ? 'is-done' : '') ?>"
          <?= $number === $stage ? 'aria-current="step"' : '' ?>>
        <span class="step-num" aria-hidden="true"><?= $number < $stage ? '✓' : $number ?></span>
        <span class="step-label"><?= e($label) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($error !== ''): ?>
    <div class="flash flash-error" role="alert" style="margin-bottom:16px"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" class="card wizard-panel" autocomplete="off" novalidate>
    <?= Security::csrfField() ?>
    <input type="hidden" name="stage" value="<?= e((string) $stage) ?>">

    <h2><?= e($stageTitle) ?></h2>
    <p class="lead"><?= e($stageLead) ?></p>

    <?php if ($stage === 1): ?>
      <h3 style="margin:0 0 10px">Server check</h3>
      <ul class="checklist">
        <?php foreach ($checks as $check): ?>
          <li class="<?= $check['ok'] ? 'is-ok' : 'is-bad' ?>">
            <span class="check-icon" aria-hidden="true"><?= $check['ok'] ? '✓' : '!' ?></span>
            <span class="check-body">
              <strong><?= e($check['label']) ?></strong>
              <span><?= e($check['detail']) ?></span>
            </span>
            <span class="badge <?= $check['ok'] ? 'badge-ok' : 'badge-danger' ?>">
              <?= $check['ok'] ? 'Ready' : 'Action required' ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>

      <h3 style="margin:26px 0 6px">Licence key</h3>
      <p class="lead" style="margin-bottom:14px">RISE 63 runs on a licence locked to one domain. The key is
        created on the provider's site, which sends it to you by e-mail.</p>

      <div class="notice wizard-note">
        <strong>No key yet?</strong>
        <a href="<?= e(Activation::MANAGER_ORDER) ?>" target="_blank" rel="noopener noreferrer">Request a licence for this domain &rarr;</a>
        <br>
        <span class="faint">
          Already a customer?
          <a href="<?= e(Activation::MANAGER_SIGNIN) ?>" target="_blank" rel="noopener noreferrer">Sign in to copy your key</a>
          &middot;
          <a href="<?= e(Activation::MANAGER_DOCS) ?>" target="_blank" rel="noopener noreferrer">API reference</a>
        </span>
      </div>

      <?php if ($maskedKey !== ''): ?>
        <div class="flash <?= $current['valid'] ? 'flash-success' : 'flash-error' ?>" role="status" style="margin-bottom:14px">
          Key already saved: <code><?= e($maskedKey) ?></code> —
          <?= $current['valid'] ? e('confirmed. ' . $current['message']) : e('not confirmed. ' . $current['message']) ?>
          <br><span class="faint">Pasting a new key below replaces it.</span>
        </div>
      <?php endif; ?>

      <div class="wizard-fields">
        <div class="field">
          <label for="license_domain">Domain on the licence</label>
          <input type="text" id="license_domain" name="license_domain" value="<?= e($domain) ?>"
                 spellcheck="false" autocapitalize="none" maxlength="253" autocorrect="off" inputmode="url">
          <span class="hint">Checked against the provider: no <code>https://</code>, no <code>www.</code>.
            Edit it if your licence was issued for a different name.</span>
          <button class="btn btn-secondary btn-sm" type="button" data-copy-target="license_domain" style="margin-top:8px">Copy domain</button>
        </div>
        <div class="field">
          <label for="license_key">Licence key</label>
          <input type="text" id="license_key" name="license_key" spellcheck="false" autocapitalize="none"
                 maxlength="255" placeholder="Paste the key from the provider" value="<?= e($verifiedKey) ?>">
          <span class="hint">Stored as <code>config/activation-key.php</code> (0600). Sent only to
            <code><?= e(Activation::MANAGER_HOST) ?></code>, over HTTPS.</span>
        </div>
      </div>

      <?php if ($licenseNotice !== ''): ?>
        <div class="flash flash-success" role="status"><?= e($licenseNotice) ?></div>
      <?php endif; ?>

      <div class="wizard-actions">
        <button class="btn btn-primary btn-block" type="submit">Save key and continue</button>
        <button class="btn btn-secondary btn-block" type="submit" name="verify_only" value="1">Check this key first</button>
      </div>
      <p class="faint" style="margin:12px 0 0;text-align:center">Both buttons contact the licence service.
        Nothing is enabled until it confirms the key for this domain.</p>

    <?php elseif ($stage === 2): ?>
      <div class="notice wizard-note">
        <strong>Before you fill this in</strong>
        Create an <em>empty</em> MySQL 8 or MariaDB 10.6 database in cPanel, and a user with full rights
        on it. RISE 63 refuses to touch a database that already contains tables.
      </div>
      <div class="wizard-fields">
        <div class="field">
          <label for="host">Host</label>
          <input type="text" id="host" name="host" value="<?= e($old['host']) ?>" required maxlength="255">
        </div>
        <div class="field">
          <label for="port">Port</label>
          <input type="number" id="port" name="port" value="<?= e($old['port']) ?>" min="1" max="65535" required>
        </div>
        <div class="field">
          <label for="name">Database name</label>
          <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required maxlength="64"
                 pattern="[A-Za-z0-9_]+" inputmode="text">
          <span class="hint">Letters, digits and underscores only.</span>
        </div>
        <div class="field">
          <label for="user">Database user</label>
          <input type="text" id="user" name="user" value="<?= e($old['user']) ?>" required maxlength="64"
                 autocomplete="username" autocapitalize="none">
        </div>
        <div class="field">
          <label for="password">Database password</label>
          <input type="password" id="password" name="password" autocomplete="new-password" maxlength="255">
        </div>
        <div class="field">
          <label for="socket">Unix socket <span class="faint">(optional)</span></label>
          <input type="text" id="socket" name="socket" maxlength="255" placeholder="/var/lib/mysql/mysql.sock">
          <span class="hint">Leave blank unless your host documents a socket path.</span>
        </div>
      </div>
      <div class="wizard-actions">
        <button class="btn btn-primary btn-block" type="submit">Create tables and continue</button>
        <a class="btn btn-secondary btn-block" href="<?= e(url('install/index.php')) ?>">Back to the previous step</a>
      </div>

    <?php elseif ($stage === 3): ?>
      <div class="wizard-fields">
        <div class="field field-full">
          <label for="site_title">Site title</label>
          <input type="text" id="site_title" name="site_title" value="<?= e($old['site_title']) ?>" required maxlength="120">
          <span class="hint">Shown in the header and used for the dynamic app icon.</span>
        </div>
        <div class="field">
          <label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required maxlength="150" autocomplete="name">
        </div>
        <div class="field">
          <label for="email">E-mail address</label>
          <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required maxlength="150"
                 autocomplete="email" inputmode="email" autocapitalize="none">
        </div>
        <div class="field field-full">
          <label for="notification_email">Security notification e-mail</label>
          <input type="email" id="notification_email" name="notification_email" value="<?= e($old['notification_email']) ?>"
                 maxlength="150" autocomplete="email" inputmode="email" autocapitalize="none">
          <span class="hint">Leave blank to use the administrator address.</span>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password">
          <span class="hint">At least 12 characters. Avoid names and common words.</span>
        </div>
        <div class="field">
          <label for="password_confirm">Repeat password</label>
          <input type="password" id="password_confirm" name="password_confirm" required minlength="12" maxlength="72" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="pin">Four-digit transaction PIN</label>
          <input type="password" id="pin" name="pin" required inputmode="numeric" pattern="[0-9]{4}"
                 maxlength="4" autocomplete="new-password">
          <span class="hint">Confirms disbursements. Sequences such as 1234 are rejected.</span>
        </div>
        <div class="field">
          <label for="license_domain_stage3">Domain on the licence</label>
          <input type="text" id="license_domain_stage3" name="license_domain" value="<?= e($domain) ?>"
                 spellcheck="false" autocapitalize="none" maxlength="253" autocorrect="off" inputmode="url">
          <span class="hint">Already confirmed for this installation. Change it only if the site now answers
            on a different name — it must match what the provider holds.</span>
        </div>
        <div class="field">
          <label for="license_key_stage3">Licence key</label>
          <input type="text" id="license_key_stage3" name="license_key" spellcheck="false" autocapitalize="none"
                 maxlength="255" placeholder="<?= $maskedKey !== '' ? 'Leave blank to keep the saved key' : 'Paste your licence key' ?>">
          <span class="hint">Only needed to replace the saved key.
            <a href="<?= e(Activation::MANAGER_ORDER) ?>" target="_blank" rel="noopener noreferrer">Request a licence</a>.</span>
        </div>
      </div>
      <p class="faint" id="caps-hint" hidden>Caps Lock is on.</p>
      <div class="wizard-actions">
        <button class="btn btn-primary btn-block" type="submit">Create administrator</button>
        <a class="btn btn-secondary btn-block" href="<?= e(url('install/index.php')) ?>">Back to the previous step</a>
      </div>

    <?php else: ?>
      <div class="notice wizard-note">
        <strong>Finishing writes <code>config/installed.lock</code></strong>
        The wizard is then permanently disabled. Everything below is a reminder of what to check after
        setup — none of it blocks the next step, and <code>php bin/doctor.php</code> checks it for you.
      </div>
      <ul class="checklist">
        <li class="is-ok"><span class="check-icon" aria-hidden="true">1</span>
          <span class="check-body"><strong>HTTPS</strong><span>Secure cookies require TLS.</span></span></li>
        <li class="is-ok"><span class="check-icon" aria-hidden="true">2</span>
          <span class="check-body"><strong>Private folders return 403</strong><span><code>/config/</code> and <code>/storage/</code>.</span></span></li>
        <li class="is-ok"><span class="check-icon" aria-hidden="true">3</span>
          <span class="check-body"><strong>Backups scheduled</strong><span>Database and files.</span></span></li>
        <li class="is-ok"><span class="check-icon" aria-hidden="true">4</span>
          <span class="check-body"><strong>Cron workers</strong><span>Mail and security, every five minutes.</span></span></li>
      </ul>
      <div class="wizard-actions">
        <button class="btn btn-primary btn-block" type="submit">Lock the installer and finish</button>
      </div>
    <?php endif; ?>
  </form>

  <p class="faint" style="text-align:center;margin-top:18px">
    RISE 63 · RCCG Lagos Province 63 · <a href="<?= e(url('')) ?>">sign-in</a>
  </p>
</main>
<?php
$body = (string) ob_get_clean();
$page('Set up RISE 63', $body);
