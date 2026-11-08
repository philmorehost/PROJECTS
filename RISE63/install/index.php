<?php
declare(strict_types=1);

/**
 * RISE 63 — four-stage installer.
 *
 * Reached only from index.php when config/installed.lock is absent. Once the installation is
 * locked this file does nothing but say so; it never reveals configuration details.
 */

use RISE63\Core\Activation;
use RISE63\Core\Installer;
use RISE63\Core\Security;

use function RISE63\{config_dir, e, installed, site_title, url};

// This file is deliberately outside the RISE63 namespace, so every bootstrap constant needs a
// fully qualified name here: a bare ROOT resolves to the global namespace and fatals.
use const RISE63\{ROOT};

require_once dirname(__DIR__) . '/app/bootstrap.php';

Security::startSession();
Security::sendHeaders();

$installer = new Installer(config_dir(), ROOT);

if ($installer->installed()) {
    http_response_code(409);
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>RISE 63 is already installed</title><link rel="stylesheet" href="<?= e(url('public/assets/app.css')) ?>"></head>
    <body class="guest"><main class="page"><div class="auth-shell card">
      <h1>RISE 63 is already installed</h1>
      <p>The setup wizard is locked. If you need to reinstall, restore a backup or ask the
      hosting operator to remove <code>config/installed.lock</code> on a copy of the site first.</p>
      <a class="btn btn-primary btn-block" href="<?= e(url('')) ?>">Go to the sign-in page</a>
    </div></main></body></html><?php
    exit;
}

// One installer at a time.
$mutex = @fopen(config_dir() . '/setup.mutex', 'c');
if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo 'Another setup session is running. Close the other tab and try again.';
    exit;
}
@chmod(config_dir() . '/setup.mutex', 0600);

$stage  = $installer->stage();
$error  = '';
$notice = '';
$licenseNotice = '';
$verifiedKey   = '';
$storedKey     = null;
$old    = ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => '', 'full_name' => '', 'email' => '', 'site_title' => 'RISE 63', 'notification_email' => ''];

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

            if (($_POST['verify_only'] ?? '') === '1') {
                // Ask the manager without committing, so the operator can see the answer first.
                $probe = $activation->verify($key, $host);
                if ($probe['valid']) {
                    $licenseNotice = 'That key is valid for ' . $probe['domain'] . '. Press “Save key and continue” to use it.';
                    $verifiedKey   = $key;
                } else {
                    $verifiedKey = $key;   // keep it in the field so a typo can be corrected
                    throw new RuntimeException($probe['message']);
                }
            } elseif ($key !== '') {
                // Never write a key that the provider has not confirmed: an unverified key on disk
                // would block the wizard on the next attempt for no reason.
                $probe = $activation->verify($key, $host);
                $verifiedKey = $key;
                if (!$probe['valid']) {
                    throw new RuntimeException($probe['message']);
                }
                $activation->storeKey($key);
                $activation->verify($key, $host, true);   // records the result, no second call needed
                $licenseNotice = 'Licence saved and confirmed for ' . $probe['domain'] . '.';
                $installer->advance(2);
            } else {
                // Resuming setup with a key already on disk.
                $state = $activation->check($host, true);
                if (!$state['valid']) {
                    throw new RuntimeException($state['message']);
                }
                $licenseNotice = $state['message'];
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
            if ($replacement !== '') {
                $activation3 = new Activation(config_dir());
                $probe3      = $activation3->verify($replacement, (string) ($_SERVER['HTTP_HOST'] ?? ''));
                if (!$probe3['valid']) {
                    throw new RuntimeException('The replacement licence key was not accepted: ' . $probe3['message']);
                }
                $activation3->storeKey($replacement);
                $activation3->verify($replacement, (string) ($_SERVER['HTTP_HOST'] ?? ''), true);
            }
            $installer->createAdministrator([
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
            ?><!doctype html><html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup complete</title>
            <link rel="stylesheet" href="<?= e(url('public/assets/app.css')) ?>"></head>
            <body class="guest"><main class="page"><div class="auth-shell card">
              <h1>Installation complete</h1>
              <p class="muted">The installer is now locked. Complete the checklist below before sharing the link.</p>
              <ul class="list-plain">
                <li><strong>HTTPS</strong> — confirm the padlock and that secure cookies are issued.</li>
                <li><strong>Private folders</strong> — open <code>/config/</code> and <code>/storage/</code> in a browser; both must return 403/404.</li>
                <li><strong>Backups</strong> — schedule database and file backups before real data arrives.</li>
                <li><strong>Cron</strong> — <code>php bin/mail-worker.php</code> and <code>php bin/security-worker.php</code> every five minutes.</li>
                <li><strong>OPcache</strong> — enable it at the hosting level.</li>
              </ul>
              <a class="btn btn-primary btn-block" href="<?= e(url('')) ?>">Open the sign-in page</a>
            </div></main></body></html><?php
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

$stage   = $installer->stage();
$checks  = $stage === 1 ? $installer->environment() : [];
$host    = (string) ($_SERVER['HTTP_HOST'] ?? '');
$license = $stage === 1 ? new Activation(config_dir()) : null;
// Display only: never block the first paint on a network call. If there is no cached answer the
// panel simply shows nothing, and the operator triggers the real check with the button.
$current = ['valid' => false, 'message' => '', 'grace' => false, 'domain' => ''];
$cachedLicense = $license?->cache();
if (is_array($cachedLicense) && Activation::domainFromHost($host) === ($cachedLicense['domain'] ?? null)) {
    $current = [
        'valid'   => !empty($cachedLicense['valid']),
        'message' => !empty($cachedLicense['valid'])
            ? 'Last confirmed ' . gmdate('j M Y H:i', (int) ($cachedLicense['checked_at'] ?? 0)) . ' UTC'
            : 'The last check did not confirm this domain.',
        'grace'   => (int) ($cachedLicense['checked_at'] ?? 0) > 0 && !empty($cachedLicense['valid']),
        'domain'  => (string) $cachedLicense['domain'],
    ];
}
$maskedKey = $license?->storedKey();
$maskedKey = $maskedKey === null ? '' : substr($maskedKey['key'], 0, 4)
    . str_repeat('•', max(4, strlen($maskedKey['key']) - 8)) . substr($maskedKey['key'], -4);
?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up RISE 63</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(url('public/assets/app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <header class="card">
      <div class="row" style="gap:10px">
        <span class="brand-mark" style="--brand-hue:220">R</span>
        <div>
          <h1 style="margin:0;font-size:1.5rem">Set up RISE 63</h1>
          <p class="faint" style="margin:2px 0 0">Four steps · about two minutes</p>
        </div>
      </div>
      <ol class="pill-row" style="list-style:none;padding:0;margin:16px 0 0">
        <?php foreach (Installer::STAGES as $number => $label): ?>
          <li class="badge <?= $number === $stage ? 'badge-info' : ($number < $stage ? 'badge-ok' : '') ?>">
            <?= $number ?>. <?= e($label) ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </header>

    <?php if ($error !== ''): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <form method="post" class="card" autocomplete="off">
      <?= Security::csrfField() ?>
      <input type="hidden" name="stage" value="<?= e((string) $stage) ?>">

      <?php if ($stage === 1): ?>
        <h2>System check &amp; licence</h2>
        <p class="muted">Every row must read “Ready” before setup continues.</p>
        <ul class="list-plain">
          <?php foreach ($checks as $key => $check): ?>
            <li class="row row-end" style="align-items:flex-start">
              <span><strong><?= e($check['label']) ?></strong><br><span class="faint"><?= e($check['detail']) ?></span></span>
              <span class="badge <?= $check['ok'] ? 'badge-ok' : 'badge-danger' ?>"><?= $check['ok'] ? 'Ready' : 'Action required' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <h3 style="margin:22px 0 6px;font-size:1.0625rem">Licence key</h3>
        <p class="muted" style="margin-top:0">RISE 63 runs on a licence locked to this domain. The key is created on the
          provider's site — follow the link, enter your e-mail and the domain below, and the key is sent to you.</p>

        <div class="notice" style="margin-bottom:14px">
          <strong>No key yet?</strong>
          <a href="<?= e(Activation::MANAGER_ORDER) ?>" target="_blank" rel="noopener noreferrer">Request a licence for this domain &rarr;</a>
          <br>
          <span class="faint">
            Already have an account?
            <a href="<?= e(Activation::MANAGER_SIGNIN) ?>" target="_blank" rel="noopener noreferrer">Sign in to copy your key</a>
            &middot;
            <a href="<?= e(Activation::MANAGER_DOCS) ?>" target="_blank" rel="noopener noreferrer">API reference</a>
          </span>
        </div>

        <?php if ($maskedKey !== '' && $current['grace']): ?>
          <div class="flash flash-warning" role="status">
            The licence service is currently unreachable. This site is running on its grace window:
            <?= e((string) $current['message']) ?>
          </div>
        <?php endif; ?>
        <?php if ($maskedKey !== ''): ?>
          <div class="flash <?= $current['valid'] ? 'flash-success' : 'flash-warning' ?>" role="status">
            Key already saved: <code><?= e($maskedKey) ?></code> —
            <?= $current['valid']
                ? 'confirmed for ' . e((string) $current['domain']) . '.'
                : 'not confirmed yet: ' . e((string) $current['message']) ?>
            Pasting a new key below replaces it.
          </div>
        <?php endif; ?>

        <div class="field">
          <label for="domain">Domain to license</label>
          <input type="text" id="domain" value="<?= e(Activation::domainFromHost($host)) ?>" readonly>
          <span class="hint">Read from this request. Use this exact value on the provider's order form.
            <?php if (str_starts_with(Activation::domainFromHost($host), 'www.')): ?>
              Keys are checked without the <code>www.</code> prefix.
            <?php endif; ?>
          </span>
          <button class="btn btn-quiet btn-sm" type="button" data-copy-target="domain" style="margin-top:6px">Copy domain</button>
        </div>

        <div class="field">
          <label for="license_key">Licence key</label>
          <input type="text" id="license_key" name="license_key" autocomplete="off" spellcheck="false"
                 autocapitalize="none" maxlength="255" placeholder="Paste the key from the provider"
                 value="<?= e($verifiedKey) ?>">
          <span class="hint">Stored privately as <code>config/activation-key.php</code> (mode 0600) and never written to
            the error log. It is sent only to <code><?= e(Activation::MANAGER_HOST) ?></code>, over HTTPS, from PHP.</span>
        </div>

        <?php if ($licenseNotice !== ''): ?>
          <div class="flash flash-success" role="status"><?= e($licenseNotice) ?></div>
        <?php endif; ?>

        <div class="form-actions" style="display:grid;gap:8px">
          <button class="btn btn-secondary btn-block" type="submit" name="verify_only" value="1" formnovalidate>
            Check this key first
          </button>
          <button class="btn btn-primary btn-block" type="submit">Save key and continue</button>
        </div>
        <p class="faint" style="margin-bottom:0">Both buttons contact the licence service. Nothing is enabled until it
          confirms the key for this domain.</p>

      <?php elseif ($stage === 2): ?>
        <h2>02 · Database</h2>
        <p class="muted">Create an empty MySQL 8 / MariaDB 10.6 database in cPanel first. Existing tables are never overwritten.</p>
        <div class="field"><label for="host">Host</label>
          <input type="text" id="host" name="host" value="<?= e($old['host']) ?>" required maxlength="255"></div>
        <div class="field"><label for="port">Port</label>
          <input type="number" id="port" name="port" value="<?= e($old['port']) ?>" min="1" max="65535" required></div>
        <div class="field"><label for="name">Database name</label>
          <input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required maxlength="64" pattern="[A-Za-z0-9_]+">
          <span class="hint">Letters, digits and underscores only.</span></div>
        <div class="field"><label for="user">Database user</label>
          <input type="text" id="user" name="user" value="<?= e($old['user']) ?>" required maxlength="64" autocomplete="username"></div>
        <div class="field"><label for="password">Database password</label>
          <input type="password" id="password" name="password" autocomplete="new-password"></div>
        <div class="field"><label for="socket">Unix socket <span class="faint">(optional)</span></label>
          <input type="text" id="socket" name="socket" maxlength="255" placeholder="/var/lib/mysql/mysql.sock"></div>
        <div class="form-actions"><button class="btn btn-primary btn-block" type="submit">Create tables &amp; continue</button></div>

      <?php elseif ($stage === 3): ?>
        <h2>03 · Provincial administrator</h2>
        <p class="muted">This account manages security policy and approves programs.</p>
        <div class="field"><label for="site_title">Site title</label>
          <input type="text" id="site_title" name="site_title" value="<?= e($old['site_title']) ?>" required maxlength="120">
          <span class="hint">The first letter of this title becomes the dynamic app icon.</span></div>
        <div class="field"><label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required maxlength="150" autocomplete="name"></div>
        <div class="field"><label for="email">E-mail address</label>
          <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required maxlength="150" autocomplete="email"></div>
        <div class="field"><label for="notification_email">Security notification e-mail</label>
          <input type="email" id="notification_email" name="notification_email" value="<?= e($old['notification_email']) ?>" maxlength="150">
          <span class="hint">Leave blank to use the administrator address.</span></div>
        <div class="field"><label for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password">
          <span class="hint">At least 12 characters. Avoid names, e-mail fragments and common words.</span></div>
        <div class="field"><label for="password_confirm">Repeat password</label>
          <input type="password" id="password_confirm" name="password_confirm" required minlength="12" maxlength="72" autocomplete="new-password"></div>
        <div class="field"><label for="pin">Four-digit transaction PIN</label>
          <input type="password" id="pin" name="pin" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="new-password">
          <span class="hint">Used to confirm disbursements. Sequences such as 1234 are rejected.</span></div>
        <div class="field">
          <label for="license_key_stage3">Licence key</label>
          <input type="text" id="license_key_stage3" name="license_key" autocomplete="off" spellcheck="false"
                 autocapitalize="none" maxlength="255" placeholder="<?= $maskedKey !== '' ? 'Leave blank to keep the saved key' : 'Paste your licence key' ?>">
          <span class="hint">Replaces the saved key if you paste one.
            <a href="<?= e(Activation::MANAGER_ORDER) ?>" target="_blank" rel="noopener noreferrer">Request a licence</a>.</span>
        </div>
        <div class="form-actions"><button class="btn btn-primary btn-block" type="submit">Create administrator</button></div>

      <?php else: ?>
        <h2>04 · Ready to finish</h2>
        <p>Everything is in place. Finishing writes <code>config/installed.lock</code> and permanently disables this wizard.</p>
        <div class="notice">
          <strong>Before you share the link</strong>
          Enable HTTPS, confirm <code>/config/</code> and <code>/storage/</code> return 403, schedule backups,
          and add the two cron workers described in <code>docs/DEPLOYMENT.md</code>.
        </div>
        <div class="form-actions"><button class="btn btn-primary btn-block" type="submit">Lock the installer</button></div>
      <?php endif; ?>
    </form>
  </div>
</main>
</body>
</html>
