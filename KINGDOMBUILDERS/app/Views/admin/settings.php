<?php use function KB\{e,url}; use KB\Security; ?>
<section class="page-heading">
  <div><span class="eyebrow">MAKE IT YOUR OWN</span><h1>A space with your name on it.</h1><p>Site identity and assessment rules. Quiz changes apply only to new attempts.</p></div>
</section>
<?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="stack">
  <?= Security::csrfField() ?>
  <section class="panel">
    <h2>Site identity</h2>
    <div class="form-grid">
      <label>Site title<input name="site_title" required minlength="2" maxlength="80" value="<?= e($settings['site_title']) ?>"><small>The first letter automatically becomes your favicon.</small></label>
      <label>Administrator notification email<input name="admin_email" type="email" required value="<?= e($settings['admin_email']) ?>"></label>
    </div>
    <label>Tagline<input name="site_tagline" maxlength="200" value="<?= e($settings['site_tagline']) ?>"></label>
    <label class="check-label"><input type="checkbox" name="registration_open" value="1" <?= $settings['registration_open'] ? 'checked' : '' ?>> Allow new member registrations</label>
    <a class="text-link" href="<?= e(url('admin/authorization')) ?>">Manage site access →</a>
  </section>

  <section class="panel">
    <div class="section-heading"><h2>Assessment settings</h2><label class="check-label"><input type="checkbox" name="quiz_open" value="1" <?= $settings['quiz_open'] ? 'checked' : '' ?>> Open for new attempts</label></div>
    <div class="form-grid">
      <?php foreach (['questions_per_attempt'=>['Questions per attempt',1,100],'seconds_per_question'=>['Seconds per question',5,300],'pass_mark'=>['Pass mark (%)',0,100],'max_tab_switches'=>['Focus changes before submission (0 = off)',0,100]] as $key=>[$label,$min,$max]): ?>
        <label><?= e($label) ?><input type="number" name="<?= e($key) ?>" min="<?= $min ?>" max="<?= $max ?>" required value="<?= (int)$settings[$key] ?>"></label>
      <?php endforeach; ?>
    </div>
    <p class="muted">The server sets a single total deadline: question count × seconds per question. Reloading does not reset it.</p>
    <div class="settings-checks">
      <?php foreach (['timer_enabled'=>'Enable the time limit','randomize_questions'=>'Randomize question order','randomize_answers'=>'Randomize answer order','allow_back'=>'Allow backtracking and answer changes','practice_feedback'=>'Show practice feedback after each answer','one_attempt'=>'Allow only one completed attempt per member'] as $key=>$label): ?>
        <label class="check-label"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $settings[$key] ? 'checked' : '' ?>><?= e($label) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="notice info">Keep practice feedback off for assessments. Focus-change signals are advisory and cannot prevent use of another device.</div>
  </section>

  <section class="panel">
    <h2>Reading comfort</h2>
    <p class="muted">The site already uses large, high-contrast text. Turn this on if your community wants everything even bigger; readers can also choose A / A+ / A++ in the footer.</p>
    <label class="check-label"><input type="checkbox" name="large_text" value="1" <?= $settings['large_text'] ? 'checked' : '' ?>> Use extra-large text across the whole site <small>Sets the base text to 21.5px instead of 19px. Each reader's own footer choice still takes priority.</small></label>
  </section>

  <section class="panel" id="google-sign-in">
    <div class="section-heading">
      <div><h2>Google sign-in</h2><p>Let people create an account or sign in with one click on the sign-in and registration pages. Google confirms the email address; no other profile data is stored.</p></div>
      <span class="badge <?= KB\Google::configured() ? 'green' : 'neutral' ?>"><?= KB\Google::configured() ? 'Ready' : (KB\Settings::get('google_enabled') ? 'Needs attention' : 'Off') ?></span>
    </div>
    <?php if (KB\Settings::get('google_enabled') && !KB\Google::configured()): ?>
      <div class="notice warning"><p><strong>Google sign-in is switched on but not usable.</strong> Paste the client secret and save, or switch it off. Members will not see the button until it is complete.</p></div>
    <?php endif; ?>
    <input type="hidden" name="google_enabled" value="0">
    <label class="check-label"><input type="checkbox" name="google_enabled" value="1" <?= $settings['google_enabled'] ? 'checked' : '' ?>> Show “Continue with Google” on the sign-in and registration pages</label>
    <div class="form-grid">
      <label>Google client ID<input name="google_client_id" value="<?= e($settings['google_client_id']) ?>" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="1234567890-abc123.apps.googleusercontent.com" maxlength="200"></label>
      <label>Google client secret<input name="google_client_secret" type="password" autocomplete="new-password" spellcheck="false" placeholder="<?= $settings['google_client_secret'] ? 'Saved securely — leave blank to keep it' : 'Paste the client secret' ?>"><small>Encrypted at rest and never shown again.</small></label>
    </div>
    <div class="field-block">
      <span class="field-label">Authorized redirect URI <small>Paste this exact value into your Google Cloud OAuth client.</small></span>
      <div class="copy-field">
        <code id="google-redirect-uri"><?= e(KB\Google::redirectUri()) ?></code>
        <button type="button" class="button secondary small" data-copy="#google-redirect-uri" data-copy-label="Copy URI">Copy URI</button>
      </div>
    </div>
    <details class="cron-help">
      <summary><span>How to set up Google sign-in (10 minutes)</span><span aria-hidden="true">+</span></summary>
      <div>
        <ol class="cron-steps">
          <li>Open <strong>console.cloud.google.com</strong> and create or select a project.</li>
          <li><strong>APIs &amp; Services → OAuth consent screen:</strong> choose <em>External</em>, add your app name and support email, and add the scopes <code>openid</code>, <code>email</code> and <code>profile</code>. While the app is in <em>Testing</em>, add each Google account you want to try it with as a test user; press <em>Publish</em> when you are ready for everyone.</li>
          <li><strong>APIs &amp; Services → Credentials → Create credentials → OAuth client ID → Web application.</strong></li>
          <li><strong>Authorized JavaScript origins:</strong> <code><?= e(rtrim((string) KB\config('site_url'), '/')) ?></code></li>
          <li><strong>Authorized redirect URIs:</strong> paste the exact URI shown above. It must match character for character, including <code>https://</code> and any subfolder.</li>
          <li>Create the client, copy the <strong>client ID</strong> and <strong>client secret</strong> into the fields above, tick the box and save.</li>
          <li>Open the sign-in page in a private window and press <em>Continue with Google</em> to confirm the round trip.</li>
        </ol>
        <p class="muted">Existing members are matched by their Google email address, so they keep their progress and results. A new Google account is created only when the email is new and registration is open. Suspended accounts stay suspended.</p>
      </div>
    </details>
  </section>

  <div class="form-actions sticky-actions"><button class="button fit">Save settings</button><a class="text-link" href="<?= e(url('admin/cron')) ?>">Cron job →</a><a class="text-link" href="<?= e(url('admin/smtp')) ?>">Email &amp; SMTP →</a></div>
</form>

<?php
// The cron command also lives here, because this is where administrators look
// for "settings". It is the same include used on the Email & SMTP page.
$showPathField = false;
require __DIR__ . '/_cron.php';
?>
