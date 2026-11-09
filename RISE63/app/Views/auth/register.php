<?php
declare(strict_types=1);

use RISE63\Core\Security;

use function RISE63\{e, site_title, url};

?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create your account · <?= e(site_title()) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <h1 style="font-size:1.5rem">Create your RISE 63 account</h1>
      <p class="auth-lead">Your parish representative may have given you an account already — check before registering.</p>

      <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>

      <form method="post" action="<?= e(url('')) ?>" autocomplete="on">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="register">

        <div class="field">
          <label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" required maxlength="150" autocomplete="name">
        </div>
        <div class="field">
          <label for="email">E-mail address</label>
          <input type="email" id="email" name="email" required maxlength="150" autocomplete="email"
                 inputmode="email" autocapitalize="none" spellcheck="false">
        </div>
        <div class="field">
          <label for="phone">Phone number <span class="faint">(optional)</span></label>
          <input type="tel" id="phone" name="phone" maxlength="25" autocomplete="tel" inputmode="tel">
        </div>

        <fieldset class="field" style="border:0;padding:0;margin:0 0 16px">
          <legend style="font-weight:700;font-size:var(--t-base);padding:0">Your parish</legend>
          <p class="hint" style="margin-top:2px">Find your church in the list. If it is not there yet,
            type its name and we will pass it to your parish representative.</p>

          <div class="field" data-combobox id="parish-combo" data-endpoint="<?= e(url('api/index.php')) ?>">
            <label for="parish-search">Search for your church</label>
            <div class="combo">
              <input type="search" id="parish-search" role="combobox" aria-expanded="false"
                     aria-autocomplete="list" aria-controls="parish-list" autocomplete="off"
                     placeholder="Parish, area or zone">
              <input type="hidden" name="parish_id" id="parish-id-field" value="">
              <ul class="combo-list" id="parish-list" role="listbox" hidden></ul>
            </div>
            <span class="hint">Start typing — the list filters as you type. You can change this later.</span>
          </div>

          <?php
            /*
             * The directory, server-rendered in a native <datalist>.
             *
             * This exists because the picker above depends on a fetch, a correct endpoint URL, a
             * Content-Security-Policy that permits it, a session cookie on the right path, no
             * service-worker cache in the way and JavaScript actually running. When any one of
             * those is broken the member sees an empty box, concluded the system does not know
             * their church, and typed it — which is exactly what was reported. A datalist needs
             * no network round trip, no script and no CSP allowance, and the browser matches on
             * the text in the list. app.js upgrades it to the richer picker when it can.
             */
            $choosable = $parishes ?? [];
          ?>
          <?php if ($choosable !== []): ?>
            <div class="field">
              <label for="parish-pick">Or choose your church from the list</label>
              <select id="parish-pick" data-parish-pick>
                <option value="">Choose your church</option>
                <?php foreach ($choosable as $row): ?>
                  <option value="<?= (int) $row['id'] ?>"
                          data-label="<?= e($row['label']) ?>"><?= e($row['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="hint"><?= count($choosable) ?> church<?= count($choosable) === 1 ? '' : 'es' ?>
                <?= count($choosable) >= 200 ? 'shown — use the search above for the rest' : 'in the directory so far' ?>.</span>
            </div>
            <datalist id="parish-names">
              <?php foreach ($choosable as $row): ?>
                <option value="<?= e($row['parish']) ?>"><?= e($row['area'] . ' · ' . $row['zone']) ?></option>
              <?php endforeach; ?>
            </datalist>
          <?php endif; ?>

          <details id="parish-manual" style="margin-top:4px">
            <summary style="cursor:pointer;min-height:32px;display:flex;align-items:center">My church is not in the list</summary>
            <div class="field" style="margin-top:10px">
              <label for="parish_name_raw">Church name</label>
              <input type="text" id="parish_name_raw" name="parish_name_raw" maxlength="150"
                     data-caps autocapitalize="characters" spellcheck="false"
                     placeholder="CHAPEL OF WONDERS PARISH">
              <span class="hint">Write it in full. Capitals are applied automatically, and your
                representative adds it to the directory afterwards.</span>
            </div>
          </details>

          <noscript>
            <p class="hint">
              <?= $choosable === []
                  ? 'The directory is empty, so type your church name below — your representative will add it.'
                  : 'Pick your church in the list above. If it is not there, type its name below.' ?>
            </p>
            <div class="field">
              <label for="parish_name_raw_ns">Church name, if not listed</label>
              <input type="text" id="parish_name_raw_ns" name="parish_name_raw" maxlength="150">
            </div>
          </noscript>
        </fieldset>

        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password">
          <span class="hint">At least 12 characters. Avoid your name, your e-mail and common words.</span>
        </div>
        <div class="field">
          <label for="password_confirm">Repeat password</label>
          <input type="password" id="password_confirm" name="password_confirm" required minlength="12" maxlength="72" autocomplete="new-password">
        </div>

        <div class="form-actions">
          <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </div>
      </form>
      <p class="auth-alt">Already registered? <a href="<?= e(url('login')) ?>">Sign in</a></p>
    </div>
  </div>
</main>
<script src="<?= e(RISE63\asset('app.js')) ?>" defer></script>
</body>
</html>
