<?php
/**
 * License Manager — in-app documentation.
 *
 * One source of truth for the admin guide: `admin/guide.php` renders the whole
 * thing, and every console page can drop in a contextual help drawer with
 * lm_guide_help_button('github') etc.
 *
 * PHP 7.4 compatible.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';

if (!function_exists('lm_guide_pages')) {
    /**
     * All guide content, keyed by page.
     *
     * Section keys are used as anchors, so `guide.php#cron` works.
     *
     * @return array
     */
    function lm_guide_pages()
    {
        static $pages = null;
        if ($pages !== null) {
            return $pages;
        }

        $pages = array(

            /* ---------------------------------------------------------- */
            'overview' => array(
                'title' => 'Start here',
                'icon' => '🧭',
                'summary' => 'How the release pipeline works and the five things to set up first.',
                'sections' => array(
                    array(
                        'id' => 'flow',
                        'title' => 'How a release travels',
                        'html' => <<<'HTML'
<p>Everything in this console exists to move a new version of your script from
your computer to your customers' servers. The path is always the same:</p>
<ol class="lm-guide-steps">
  <li><strong>Project</strong> — a script you sell (DGV7 SAAS, VTU, …). Each project has a code, a licence pool, its own version history and its own folder on the server.</li>
  <li><strong>Package</strong> — either upload a ZIP by hand, or let the LM pull the code straight from GitHub.</li>
  <li><strong>Publish</strong> — the version number is bumped from the previous release, the changelog is stored and the ZIP gets a <code>manifest.json</code>.</li>
  <li><strong>Notify</strong> — every active licence holder gets one email with a personal download link. If their site has not reported the new version by the next day, a reminder follows.</li>
  <li><strong>Update</strong> — the customer's script asks <code>check-update.php</code> for the latest version, downloads the package and applies it.</li>
</ol>
<p class="lm-guide-note">The customer's site reports its installed version on every check. That report is what stops the reminder — not the download.</p>
HTML
                    ),
                    array(
                        'id' => 'checklist',
                        'title' => 'First-time checklist',
                        'html' => <<<'HTML'
<ol class="lm-guide-steps">
  <li><strong>Settings → SMTP</strong> — fill in host, port and password, then press <em>Send test email</em>. Use encryption <em>Auto</em>: port 465 gets SSL, port 587 gets STARTTLS.</li>
  <li><strong>Settings → Public Site URL</strong> — set it explicitly. Cron runs without a web request, so it cannot detect the domain, and emails would contain broken links.</li>
  <li><strong>Script Projects</strong> — create one project per script you sell. The project code is permanent.</li>
  <li><strong>GitHub Pull</strong> (optional) — paste <em>one</em> token in <em>Settings → GitHub Access Token</em>, then link each project to its repository and tick the folder that belongs to it.</li>
  <li><strong>Cron</strong> — add the cron job from <a href="guide.php#cron">Guide → Cron</a>. Without it nothing is sent automatically.</li>
  <li><strong>Publish a test release</strong> — push a version, check the email, then download the ZIP from the link to confirm the package contents.</li>
</ol>
HTML
                    ),
                    array(
                        'id' => 'where',
                        'title' => 'Where everything is stored',
                        'html' => <<<'HTML'
<table class="lm-guide-table">
  <thead><tr><th>What</th><th>Where</th></tr></thead>
  <tbody>
    <tr><td>Update packages</td><td><code>secure_updates/&lt;PROJECT-CODE&gt;/update_&lt;version&gt;.zip</code> — create it, delete it, inspect it from <em>Push Updates → Cleanup &amp; storage</em>.</td></tr>
    <tr><td>Settings</td><td><code>LM/settings.json</code> (0600). SMTP password and GitHub token are stored here; the token itself is AES-256-GCM encrypted.</td></tr>
    <tr><td>Logs</td><td><code>LM/storage/logs/</code> — <code>mail.log</code>, <code>cron.log</code>, <code>github.log</code> and per-channel logs.</td></tr>
    <tr><td>Secret key</td><td><code>LM/storage/app_secret.key</code> — encrypts tokens and signs unsubscribe links. Back it up; losing it invalidates stored tokens.</td></tr>
    <tr><td>Database</td><td><code>script_tiers</code>, <code>script_updates</code>, <code>update_notifications</code>, <code>github_pulls</code>, <code>email_queue</code>.</td></tr>
  </tbody>
</table>
HTML
                    ),
                    array(
                        'id' => 'roles',
                        'title' => 'What is automatic and what is manual',
                        'html' => <<<'HTML'
<table class="lm-guide-table">
  <thead><tr><th>Automatic</th><th>Manual</th></tr></thead>
  <tbody>
    <tr><td>Version increment, changelog generation, manifest injection</td><td>Choosing what to publish (folder selection, version override)</td></tr>
    <tr><td>Customer emails, next-day reminder, retry with back-off</td><td>Turning notifications off for one release</td></tr>
    <tr><td>Cleaning expired download tokens</td><td>Deleting old releases and their files</td></tr>
    <tr><td>GitHub auto-pull (only if enabled per project)</td><td>Connecting a repository and pasting a token</td></tr>
  </tbody>
</table>
HTML
                    ),
                ),
            ),

            /* ---------------------------------------------------------- */
            'projects' => array(
                'title' => 'Script Projects',
                'icon' => '🧩',
                'summary' => 'Add a new script to the manager, archive it, rotate its key.',
                'sections' => array(
                    array(
                        'id' => 'create',
                        'title' => 'Create a project',
                        'html' => <<<'HTML'
<p>Open <strong>Script Projects → ＋ New project</strong>.</p>
<table class="lm-guide-table">
  <thead><tr><th>Field</th><th>Meaning</th></tr></thead>
  <tbody>
    <tr><td>Project name</td><td>Shown to customers and in emails, e.g. <em>DGV7.0 SAAS</em>.</td></tr>
    <tr><td>Project code</td><td>Used by the script and in URLs, e.g. <code>SAAS</code>. <strong>Permanent</strong> — it names the storage folder.</td></tr>
    <tr><td>Licence price</td><td>Optional per-project price. Empty = the global price from Settings.</td></tr>
    <tr><td>Max domains</td><td>Optional per-project domain limit. Empty = the global limit.</td></tr>
  </tbody>
</table>
<p>The project appears immediately in <em>Push Updates</em> and <em>GitHub Pull</em>, and its folder is created under <code>secure_updates/</code>.</p>
HTML
                    ),
                    array(
                        'id' => 'key',
                        'title' => 'Project code vs project key',
                        'html' => <<<'HTML'
<p><strong>Code</strong> (<code>SAAS</code>) is the human value you put in the script.</p>
<p><strong>Key</strong> (<code>PRJ-SAAS</code>) is a stable identifier you can rotate. The OTA endpoint accepts either in its <code>project</code> parameter, so rotating a key does not break a script that sends the code.</p>
<p class="lm-guide-note">Rotate the key if it leaks. Any script or client sending the old key must be updated.</p>
HTML
                    ),
                    array(
                        'id' => 'lifecycle',
                        'title' => 'Archive, restore, delete',
                        'html' => <<<'HTML'
<ul>
  <li><strong>Archive</strong> — hides the project from the publish dropdowns. Existing licences keep validating and existing downloads keep working, but no new release can be published.</li>
  <li><strong>Restore</strong> — brings it back exactly as it was.</li>
  <li><strong>Delete</strong> — only allowed when the project has no licences and no updates. Deleting also removes its ZIP files from cPanel.</li>
</ul>
HTML
                    ),
                    array(
                        'id' => 'integration',
                        'title' => 'Connect the target script',
                        'html' => <<<'HTML'
<p>Press <strong>Integration</strong> on a project card. The modal shows the exact endpoint URL, the cPanel storage folder and a drop-in PHP client you can paste into the script.</p>
<p>Full protocol details are in <a href="guide.php#script">Guide → Script integration</a>.</p>
HTML
                    ),
                ),
            ),

            /* ---------------------------------------------------------- */
            'push' => array(
                'title' => 'Push Updates',
                'icon' => '🚀',
                'summary' => 'Publish a ZIP package by hand, add a changelog and notify customers.',
                'sections' => array(
                    array(
                        'id' => 'upload',
                        'title' => 'Publish a package',
                        'html' => <<<'HTML'
<ol class="lm-guide-steps">
  <li><strong>Target script project</strong> — pick the project this ZIP belongs to.</li>
  <li><strong>Version number</strong> — leave <em>auto-increment</em> ticked and the next version is computed from the last release (<code>7.02 → 7.03</code>; first release <code>1.0.0</code>). Untick only to type a version yourself.</li>
  <li><strong>Changelog</strong> — write it as <em>HTML</em> or <em>Plain text</em>. Plain text is converted to a list automatically. HTML is sanitised (scripts, event handlers and iframes are stripped) before it reaches an email.</li>
  <li><strong>Update package</strong> — drag a ZIP in. A <code>manifest.json</code> is added at the root, replacing any old one.</li>
  <li><strong>Email customers</strong> — leave ticked to queue the notification, or untick to publish silently.</li>
  <li><strong>Publish update</strong> — the file is copied to <code>secure_updates/&lt;code&gt;/</code> and the record is created.</li>
</ol>
<p class="lm-guide-note">The version must be newer than the current one. “Force re-publish” exists to overwrite a version you just released with a fix; it re-queues notifications only for customers still behind.</p>
HTML
                    ),
                    array(
                        'id' => 'limits',
                        'title' => 'Size limits',
                        'html' => <<<'HTML'
<p>Two limits apply to an upload:</p>
<ul>
  <li><strong>PHP</strong> — <code>upload_max_filesize</code> and <code>post_max_size</code> (shown under the dropzone). Raise them in cPanel → MultiPHP INI Editor if needed.</li>
  <li><strong>LM</strong> — <em>Settings → Maximum package size</em> (default 128 MB), also applied to GitHub archives.</li>
</ul>
<p>Packages are stored outside the web root, so they are not publicly listable even if the upload limit is large.</p>
HTML
                    ),
                    array(
                        'id' => 'history',
                        'title' => 'History, details and email preview',
                        'html' => <<<'HTML'
<p><strong>Recent updates</strong> lists every release with its source (Manual / GitHub), package name and size, and notification counters.</p>
<ul>
  <li><strong>Details</strong> — checksum, file list, storage path, manifest.</li>
  <li><strong>Email</strong> — renders the exact message a customer would receive, in an iframe.</li>
  <li><strong>Remind</strong> — queues a reminder now for everyone still behind.</li>
  <li><strong>Delete</strong> — removes the record, the ZIP, its extracted folders, tokens and notifications.</li>
</ul>
HTML
                    ),
                    array(
                        'id' => 'cleanup',
                        'title' => 'Cleanup and storage',
                        'html' => <<<'HTML'
<p><strong>Cleanup &amp; storage</strong> keeps only the newest release per project. For every deleted release the LM:</p>
<ol class="lm-guide-steps">
  <li>resolves the stored path (it survives folder moves and renames),</li>
  <li>deletes the ZIP from cPanel,</li>
  <li>removes extracted or staging folders for that version,</li>
  <li>deletes download tokens and notification rows,</li>
  <li>deletes the database record — and reports every path it removed.</li>
</ol>
<p>Use <strong>Storage inspector</strong> to compare what is on disk with what the database tracks (orphan files, missing packages, total size).</p>
HTML
                    ),
                ),
            ),

            /* ---------------------------------------------------------- */
            'github' => array(
                'title' => 'GitHub Pull',
                'icon' => '🐙',
                'summary' => 'Publish straight from a repository, choose the folders, keep private repos private.',
                'sections' => array(
                    array(
                        'id' => 'setup',
                        'title' => 'Step 1 — connect the repository',
                        'html' => <<<'HTML'
<ol class="lm-guide-steps">
  <li>Choose the <strong>script project</strong> at the top.</li>
  <li><strong>Repository</strong> — click the field and pick from the list of everything your token can see. Typing still works: <code>owner/repo</code>, <code>https://github.com/owner/repo</code>, <code>…/repo.git</code>, or a branch URL <code>…/tree/dev</code> (the branch is read from the URL).</li>
  <li><strong>Branch</strong> — chosen from that repository's branches; empty means its default branch.</li>
  <li><strong>Default folder</strong> — the project's folder inside the repository, e.g. <code>LM</code>. Used for auto-pull and as the package root.</li>
  <li>Press <strong>Test connection</strong>. You should see the branch, the HEAD commit and whether the repository is public or private.</li>
</ol>
<p class="lm-guide-note">One repository can hold every project. Point each LM project at the same repo and give it a different default folder.</p>
HTML
                    ),
                    array(
                        'id' => 'repos',
                        'title' => 'Step 2 — pick the repository and branch',
                        'html' => <<<'HTML'
<p>The page asks GitHub for every repository your token can reach as soon as it loads — old and new, personal and organisation, private included — and prints the count under the field.</p>
<ol class="lm-guide-steps">
  <li>Click the <strong>Repository</strong> field. The list opens, most recently updated first. 🔒 marks private repositories, 🌐 public ones, with <em>fork</em> and <em>archived</em> badges where they apply.</li>
  <li>Type to narrow it down. Several words work — <code>acme admin</code> matches owner plus description.</li>
  <li>Press <strong>Enter</strong> or click a row. The branch list fills from that repository and the file tree loads straight away — <strong>no need to save first</strong>.</li>
  <li>Choose a <strong>branch</strong> from the suggestions, or leave it empty for the default branch.</li>
</ol>
<p class="lm-guide-note">The list is cached for five minutes on the server. Press <strong>↻</strong> to bypass the cache after creating a repository on GitHub.</p>
<p>Loading is one API call per 100 repositories — a few units of your 5,000-per-hour quota — and up to 500 repositories are fetched (newest first). The hint under the field says when the list was truncated.</p>
HTML
                    ),
                    array(
                        'id' => 'token',
                        'title' => 'Step 3 — one token for every repository',
                        'html' => <<<'HTML'
<p>Public repositories need no token. For private ones you need a personal access token — but only <strong>one for the whole installation</strong>. Paste it once in <em>Settings → GitHub Access Token</em>; every project without a token of its own uses it, including projects you create next month.</p>
<table class="lm-guide-table">
  <thead><tr><th>Type</th><th>Where</th><th>Access to set</th></tr></thead>
  <tbody>
    <tr>
      <td><strong>Fine-grained</strong> (recommended)</td>
      <td>GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens</td>
      <td>Repository access: <strong>All repositories</strong> (covers repositories created later). Permissions: <em>Contents → Read-only</em>, <em>Metadata → Read-only</em>.</td>
    </tr>
    <tr>
      <td><strong>Classic</strong></td>
      <td>… → Tokens (classic) → Generate new token</td>
      <td>Scope <code>repo</code> — gives every repository the account can see, current and future. Organisation repos with SSO must be authorised for the token.</td>
    </tr>
  </tbody>
</table>
<p>Set the expiry to whatever your policy allows and put a reminder in the calendar — when the token expires, pulls start returning 401 until it is replaced. A classic token can be created without an expiry.</p>
<p><strong>Settings → GitHub Access Token → Test token</strong> shows the account the token belongs to, how many repositories it can see and how many API calls are left, so you know it covers everything before you rely on it.</p>
<p>Overrides are still possible: a project that must read a repository the shared token cannot see can store its own token in <em>GitHub Pull → Repository settings</em>. The badge then reads <em>Project token</em> instead of <em>Shared token</em>.</p>
<p>Tokens are encrypted with <code>storage/app_secret.key</code> and never displayed again — only a masked hint such as <code>ghp_1a2b…9f3c</code>. They are only ever sent to <code>api.github.com</code>; the archive download follows GitHub's signed redirect without credentials.</p>
HTML
                    ),
                    array(
                        'id' => 'files',
                        'title' => 'Step 4 — choose the folders and files',
                        'html' => <<<'HTML'
<p>Press <strong>↻ Load files</strong>. The LM reads the branch tree and shows it as an expandable list.</p>
<ul>
  <li><strong>▶ / folder name</strong> — open or close a folder.</li>
  <li><strong>Folder checkbox</strong> — ticks everything inside. A half-selected folder shows a dash and its file count.</li>
  <li><strong>File checkbox</strong> — just that file, in as many folders as you like.</li>
  <li><strong>Select all / Clear</strong> — act on everything currently visible.</li>
  <li><strong>Filter box</strong> — narrows the tree; <em>Select all</em> then only ticks the matches.</li>
  <li><strong>💾 Save selection</strong> — stores the choice on the project so the cron auto-pull repeats it.</li>
</ul>
<p>The status line tells you exactly what will be packaged: <code>📦 148 files · 3.2 MB · root LM/</code>.</p>
<h4>Where the files land</h4>
<p>The package root is the deepest folder shared by everything you ticked, and that prefix is removed:</p>
<table class="lm-guide-table">
  <thead><tr><th>Selection</th><th>ZIP contents</th></tr></thead>
  <tbody>
    <tr><td><code>LM</code></td><td><code>index.php</code>, <code>admin/…</code>, <code>includes/…</code></td></tr>
    <tr><td><code>LM/includes</code></td><td><code>mailer.php</code>, <code>zip.php</code>, …</td></tr>
    <tr><td><code>LM/admin/updates.php</code> (default folder <code>LM</code>)</td><td><code>admin/updates.php</code></td></tr>
    <tr><td><code>LM</code> + <code>VTU</code></td><td><code>LM/…</code> and <code>VTU/…</code> (structure kept)</td></tr>
    <tr><td><code>LM/index.php</code> + <code>VTU/app/Main.java</code></td><td>both paths, unchanged</td></tr>
    <tr><td>nothing ticked</td><td>the whole repository, wrapper folder removed</td></tr>
  </tbody>
</table>
<p class="lm-guide-note">Keep the <em>Default folder</em> set. With it, ticking a single file keeps its path relative to the project (<code>admin/updates.php</code>) instead of collapsing it to the archive root.</p>
HTML
                    ),
                    array(
                        'id' => 'publish',
                        'title' => 'Step 5 — preview and publish',
                        'html' => <<<'HTML'
<p><strong>↻ Refresh preview</strong> shows the commits since the last pull, the changed files, the version that will be used and the generated changelog.</p>
<ul>
  <li><strong>Version to publish</strong> — pre-filled from the previous release. Overwrite it if you tag releases by hand.</li>
  <li><strong>Changelog</strong> — edit before publishing; this text goes into the email, the manifest and the update row. Switch HTML / plain text with the radios.</li>
  <li><strong>Email customers</strong> — same behaviour as a manual upload.</li>
  <li><strong>🚀 Pull &amp; publish</strong> — queues the pull, then downloads the archive, filters it to your selection, injects <code>manifest.json</code>, publishes and records the pull.</li>
</ul>
<p>Changing only the selection (no new commits) still publishes a new package — the LM compares the commit <em>and</em> the stored selection.</p>
<p class="lm-guide-note">The pull runs in the background: the page returns immediately and the progress card shows the real stage. See <a href="guide.php#background">Pulls run in the background</a>.</p>
HTML
                    ),
                    array(
                        'id' => 'background',
                        'title' => 'Pulls run in the background',
                        'html' => <<<'HTML'
<p>Downloading and repacking a monorepo takes longer than a browser request should stay open, so the console no longer waits for it:</p>
<ol class="lm-guide-steps">
  <li><strong>Queue</strong> — pressing <em>Pull &amp; publish</em> stores the job and answers straight away. A worker starts on the server (a detached CLI process, or a loopback request when <code>exec()</code> is disabled).</li>
  <li><strong>Close the tab</strong> — the job keeps running. Nothing depends on the page staying open.</li>
  <li><strong>Watch the stage</strong> — the card shows <em>Reading the repository → Downloading → Packaging → Publishing</em> with real progress from the server, not a guess.</li>
  <li><strong>Come back later</strong> — reopening <em>GitHub Pull</em> restores the running job, or shows the finished result if it completed while you were away.</li>
</ol>
<p>When the pull finishes, two things happen without you doing anything:</p>
<ul>
  <li><strong>You get an email</strong> at <em>Settings → Admin email</em> with the version, commit, file count, archive size and duration — or the exact error if it failed.</li>
  <li><strong>Customers get the update email</strong> immediately. <code>lm_publish_update()</code> queues one email per licence and this job sends the batch right away instead of waiting for the next cron run. The next-day reminder still runs from cron.</li>
</ul>
<table class="lm-guide-table">
  <thead><tr><th>Situation</th><th>What happens</th></tr></thead>
  <tbody>
    <tr><td>You press pull twice</td><td>The second press returns the job that is already running — never two pulls for one project.</td></tr>
    <tr><td>Worker cannot start (no <code>exec()</code>, blocked loopback)</td><td>The job stays queued and <code>cron.php</code> runs it on its next pass (within 10 minutes). The progress card says so.</td></tr>
    <tr><td>The worker dies mid-pull</td><td>No heartbeat for 45 minutes → the job is marked failed with a readable reason, so the project is never locked.</td></tr>
    <tr><td>Pull fails</td><td>You get the failure email; no customer email is sent and no update row is created.</td></tr>
  </tbody>
</table>
HTML
                    ),
                    array(
                        'id' => 'autopull',
                        'title' => 'Auto-pull from cron',
                        'html' => <<<'HTML'
<p>Enable <strong>Auto-pull every 30 minutes</strong> on a project and every cron run checks the branch. If the HEAD commit changed, it publishes immediately with the saved selection and the project's version and changelog settings.</p>
<p class="lm-guide-note">Treat this as continuous delivery: a commit on the branch becomes a customer release. Keep it off until you trust the branch, or point the project at a stable branch rather than <code>main</code>.</p>
<p>Auto-pull needs a working cron job — see <a href="guide.php#cron">Guide → Cron</a>.</p>
HTML
                    ),
                    array(
                        'id' => 'gittrouble',
                        'title' => 'GitHub troubleshooting',
                        'html' => <<<'HTML'
<table class="lm-guide-table">
  <thead><tr><th>Symptom</th><th>Cause / fix</th></tr></thead>
  <tbody>
    <tr><td><code>401</code></td><td>Token expired or revoked. Save a new one.</td></tr>
    <tr><td><code>403</code></td><td>Token lacks <em>Contents: Read</em>, organisation SSO is not authorised, or the API rate limit is exhausted (the message says which).</td></tr>
    <tr><td><code>404</code></td><td>Wrong <code>owner/repo</code>, wrong branch, or a private repo the token cannot see.</td></tr>
    <tr><td>“None of the selected folders exist”</td><td>The branch moved. Press <em>Load files</em> and tick again.</td></tr>
    <tr><td>Package is missing files</td><td>They were not ticked, or they are submodules (not packaged). The pull result shows the packaged file count.</td></tr>
    <tr><td>“Already up to date”</td><td>Same commit and same selection. Use <em>Force re-publish</em> to repackage anyway.</td></tr>
    <tr><td>ZIP support unavailable</td><td>Enable the PHP <code>zlib</code> extension. The bundled engine does not need <code>ZipArchive</code>.</td></tr>
  </tbody>
</table>
HTML
                    ),
                ),
            ),

            /* ---------------------------------------------------------- */
            'notifications' => array(
                'title' => 'Update Notifications',
                'icon' => '🔔',
                'summary' => 'Who gets an email, when the reminder fires, and what each status means.',
                'sections' => array(
                    array(
                        'id' => 'who',
                        'title' => 'Who receives an email',
                        'html' => <<<'HTML'
<p>One email per <strong>active licence</strong> that has <code>notify_updates</code> on and belongs to the released project. Duplicate addresses are collapsed, and obviously fake addresses are skipped.</p>
<p>An email is <strong>not</strong> sent when the customer has already reported the new version, unsubscribed, or when the licence is not active.</p>
HTML
                    ),
                    array(
                        'id' => 'domain',
                        'title' => 'Which domain the email names',
                        'html' => <<<'HTML'
<p>Each email talks about <strong>one site</strong>: the customer's own domain. It comes from two places, in this order:</p>
<ol class="lm-guide-steps">
  <li><strong>The domains that checked in.</strong> Every domain that calls <code>check-update.php</code> is stored in <code>licensed_domains</code> for that licence. This is the accurate list — it is the site that is actually running the script.</li>
  <li><strong>The domain on the licence.</strong> What the payment gateway received at checkout. It is used only when nothing has checked in yet.</li>
</ol>
<p>Both are cleaned before they are printed: <code>https://Shop.Example.com/checkout</code>, <code>shop.example.com:443</code> and <code>www.shop.example.com</code> all become <code>shop.example.com</code>.</p>
<p><strong>Placeholders are never printed.</strong> <code>N/A</code>, <code>example.com</code>, <code>yourdomain.com</code>, <code>localhost</code> and private IPs are treated as "unknown", and the email says <em>your website</em> instead of inventing a name. When a licence has more than one site, the email shows the primary one and <em>+N more domains</em>.</p>
<p class="lm-guide-note">The preview on <em>Update Notifications</em> renders a real customer of that project, and tells you whose email you are looking at. The old preview used a hard-coded domain, which is how a placeholder could look like a real send.</p>
HTML
                    ),
                    array(
                        'id' => 'instructions',
                        'title' => 'The “how to update” block',
                        'html' => <<<'HTML'
<p>Under the changelog every email carries two routes to install the release, because customers do not all work the same way:</p>
<table class="lm-guide-table">
  <thead><tr><th>Option</th><th>What the customer does</th></tr></thead>
  <tbody>
    <tr><td><strong>1 · Admin dashboard</strong></td><td>Signs in to the script as super admin, opens <em>System Update Centre</em> and presses <em>Install Update Automatically</em>. The script downloads the package, backs up files + database and rolls back on failure.</td></tr>
    <tr><td><strong>2 · Control-panel cron</strong></td><td>Adds one daily job in cPanel → Cron Jobs (Plesk and DirectAdmin work the same way). It installs the release and emails the result. The exact command is shown in the email and on <em>Super Admin → Cron Jobs</em>.</td></tr>
  </tbody>
</table>
<p>The wording follows the project name automatically. If a project names its screens differently, override it per installation in <a href="settings.php#email-help">Settings → Email update instructions</a> — leave a box empty to keep the built-in text.</p>
HTML
                    ),
                    array(
                        'id' => 'reminders',
                        'title' => 'The next-day reminder',
                        'html' => <<<'HTML'
<table class="lm-guide-table">
  <thead><tr><th>Setting</th><th>Default</th><th>Effect</th></tr></thead>
  <tbody>
    <tr><td>First email delay</td><td>0 min</td><td>How long after publishing the initial email goes out.</td></tr>
    <tr><td>Reminder after</td><td>24 h</td><td>When the reminder becomes due. 24 = the next day.</td></tr>
    <tr><td>Max reminders</td><td>1</td><td>0 turns reminders off entirely; up to 5 are allowed.</td></tr>
    <tr><td>Skip if downloaded</td><td>on</td><td>A customer who already fetched the package is not nagged.</td></tr>
    <tr><td>Batch per cron run</td><td>25</td><td>Caps each run so a big list does not hit PHP time limits.</td></tr>
  </tbody>
</table>
<p>The reminder is cancelled automatically when the site reports the new version, the licence is suspended, the customer unsubscribes, or a newer release supersedes it.</p>
HTML
                    ),
                    array(
                        'id' => 'statuses',
                        'title' => 'What each status means',
                        'html' => <<<'HTML'
<table class="lm-guide-table">
  <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
  <tbody>
    <tr><td><strong>Pending</strong></td><td>Queued, waiting for <code>send_after</code> or the next cron run.</td></tr>
    <tr><td><strong>Sending</strong></td><td>Claimed by a worker right now. Rows stuck here for 20 minutes are reset automatically.</td></tr>
    <tr><td><strong>Sent</strong></td><td>Accepted by the SMTP server. <code>last_error</code> is cleared.</td></tr>
    <tr><td><strong>Failed</strong></td><td>Three attempts failed. <em>Resend failed</em> puts it back in the queue.</td></tr>
    <tr><td><strong>Skipped</strong></td><td>Not needed: already updated, unsubscribed, or package downloaded. The reason is shown under the address.</td></tr>
    <tr><td><strong>Superseded</strong></td><td>A newer version was published while this reminder was waiting.</td></tr>
    <tr><td><strong>Cancelled</strong></td><td>Cancelled by you, or by an unsubscribe.</td></tr>
  </tbody>
</table>
HTML
                    ),
                    array(
                        'id' => 'actions',
                        'title' => 'Buttons on this page',
                        'html' => <<<'HTML'
<ul>
  <li><strong>▶ Send due emails now</strong> — drains the queue without waiting for cron (same code path, so results and back-off are identical).</li>
  <li><strong>Preview</strong> — shows the exact HTML a customer receives, in a sandboxed iframe.</li>
  <li><strong>Cancel</strong> — stops a single queued row.</li>
  <li><strong>Remind</strong> (on Push Updates) — queues a reminder immediately for everyone still behind.</li>
  <li><strong>Save schedule</strong> — writes the fields above to <code>settings.json</code>.</li>
</ul>
HTML
                    ),
                    array(
                        'id' => 'deliverability',
                        'title' => 'Deliverability tips',
                        'html' => <<<'HTML'
<ul>
  <li>Send from your own domain (<em>Settings → From email</em>), not from a free mailbox, and make sure SPF and DKIM are configured in cPanel → Email Deliverability.</li>
  <li>Keep the batch size at 25 unless your host allows long-running scripts.</li>
  <li>If messages fail with <code>SMTP connect() failed</code>, open <em>Settings → SMTP</em>: encryption should be <em>Auto</em>. Send a test email to see the transcript in <code>storage/logs/mail.log</code>.</li>
  <li>Unsubscribe links are signed and work without login. Customers who unsubscribe are excluded from future releases automatically.</li>
</ul>
HTML
                    ),
                ),
            ),

            /* ---------------------------------------------------------- */
            'settings' => array(
                'title' => 'Settings',
                'icon' => '⚙️',
                'summary' => 'SMTP transport, site URL, storage limits, the shared GitHub token and the cron key.',
                'sections' => array(
                    array(
                        'id' => 'smtp',
                        'title' => 'SMTP',
                        'html' => <<<'HTML'
<p>Encryption is inferred from the port, which is what fixes the classic <code>SMTP connect() failed</code> error.</p>
<table class="lm-guide-table">
  <thead><tr><th>Port</th><th>Transport</th><th>When to use</th></tr></thead>
  <tbody>
    <tr><td>465</td><td>Implicit SSL/TLS</td><td>Default on cPanel mail servers.</td></tr>
    <tr><td>587</td><td>STARTTLS</td><td>Preferred when 465 is blocked.</td></tr>
    <tr><td>25 / 2525</td><td>STARTTLS, then plain</td><td>Internal relays only.</td></tr>
  </tbody>
</table>
<p>Keep <strong>Encryption = Auto</strong> and the LM tries the right transport for the port, then falls back to the other one. <strong>Send test email</strong> reports the transport used and the SMTP transcript.</p>
<p><em>Verify server certificate</em> should stay on. Turn it off only for a self-signed mail server on your own network.</p>
HTML
                    ),
                    array(
                        'id' => 'siteurl',
                        'title' => 'Public site URL',
                        'html' => <<<'HTML'
<p>Every link in an email is built from this value, and so is the OTA endpoint in the integration guide.</p>
<p>The LM detects the URL on the first web request and stores it. Set it explicitly when cron runs on a different host, when the site sits behind a proxy, or when the detected value is an IP address.</p>
HTML
                    ),
                    array(
                        'id' => 'storage',
                        'title' => 'Storage',
                        'html' => <<<'HTML'
<p><strong>Storage directory</strong> defaults to <code>secure_updates/</code> next to <code>LM/</code>, outside the web root. Each project gets a sub-folder named after its code. Absolute paths are allowed if you keep packages on a separate volume.</p>
<p><strong>Maximum package size</strong> applies to uploads and GitHub archives.</p>
<p class="lm-guide-note"><strong>Save storage settings</strong> is its own button: it saves only the two fields in that card, so a half-filled form elsewhere on the page can never block it. The card then shows the effective folder, how much space the packages use, the free space on the disk and the limit that is actually in force.</p>
<table class="lm-guide-table">
  <thead><tr><th>Symptom</th><th>Cause and fix</th></tr></thead>
  <tbody>
    <tr><td>Value reverts to the old number</td><td>Press <strong>Save storage settings</strong> (not just <strong>Save Settings</strong>) and watch for the green confirmation above the page. A red banner means the folder was rejected — read the message.</td></tr>
    <tr><td>Upload rejected before it starts</td><td>PHP's <code>post_max_size</code> / <code>upload_max_filesize</code> are lower than the limit. The card warns you when this is the case; raise both in cPanel → MultiPHP INI Editor, or publish through GitHub pull, which streams to disk and ignores those limits.</td></tr>
    <tr><td>Folder refused</td><td>The LM will not store packages inside its own web root (anyone could download them). Pick a folder outside <code>LM/</code>.</td></tr>
  </tbody>
</table>
HTML
                    ),
                    array(
                        'id' => 'github',
                        'title' => 'GitHub access token',
                        'html' => <<<'HTML'
<p>One token for the whole installation. Projects that have no token of their own inherit it, so adding a project never means creating another key.</p>
<ol class="lm-guide-steps">
  <li>GitHub → Settings → Developer settings → Personal access tokens.</li>
  <li>Create a <strong>fine-grained</strong> token with repository access <strong>All repositories</strong> and <em>Contents: Read-only</em>, or a <strong>classic</strong> token with the <code>repo</code> scope.</li>
  <li>Paste it here and press <strong>Save Settings</strong>. The field is write-only — leave it blank to keep the current token.</li>
  <li>Press <strong>Test token</strong>. It reports <code>@login</code>, the token kind, how many repositories are visible and the remaining API quota.</li>
</ol>
<p class="lm-guide-note">“All repositories” covers repositories created later too. A fine-grained token limited to <em>select repositories</em> will silently 404 on every other repo — the usual cause of “not found” on a repo you can see in the browser.</p>
<p><strong>Show</strong> reveals the stored token (masked in the field, hidden again after 60 seconds) and <strong>Copy</strong> puts it on the clipboard — useful when you need the same key in another tool. <strong>Remove</strong> clears it; projects with their own token keep working, the rest fall back to public-only access.</p>
<p>The status chip above the field tells you which of these you are in:</p>
<table class="lm-guide-table">
  <thead><tr><th>Chip</th><th>Meaning</th></tr></thead>
  <tbody>
    <tr><td>✓ Token stored</td><td>Encrypted, readable, and inherited by every project without its own token.</td></tr>
    <tr><td>No token stored</td><td>Nothing saved yet — only public repositories work.</td></tr>
    <tr><td>⚠ Token stored but unreadable</td><td>The ciphertext is there but no key on this install can open it — neither <code>storage/app_secret.key</code> nor any archived key. Paste the token again and press <em>Save token</em>. Nothing else is lost.</td></tr>
  </tbody>
</table>
<p class="lm-guide-note">Every save is verified before it is written: the token is encrypted and decrypted again in the same request. If the round-trip does not match, the save is refused with the reason instead of storing a value that would look empty later.</p>
<p class="lm-guide-note">If an update replaced the encryption key, the token is recovered from the archived key and re-encrypted with the current one during the first request — no action needed. Settings → <em>Settings File &amp; Recovery</em> shows how many archived keys are available.</p>
<p>Rate limit: 5,000 requests per hour per token. A pull uses a handful; the tree view one more per load.</p>
<h4>Why a pull takes as long as it does</h4>
<p>The archive is downloaded straight to disk with cURL, then re-packed <strong>once</strong>: the wrapper folder is stripped, only the ticked files are kept and <code>manifest.json</code> is written in the same pass. An older build copied the archive twice, which roughly doubled the packaging time — a 117 MB / 600-file archive went from 2.05 s to 0.81 s of packaging in testing.</p>
<p>A failed pull keeps the downloaded archive for that commit, so pressing <strong>Pull &amp; publish</strong> again resumes from packaging instead of downloading 100 MB a second time.</p>
<p class="lm-guide-note">While it runs, the button shows the stage and the elapsed seconds. If it always stops around 30–60 s, the host is killing the request: raise <code>max_execution_time</code>, or tick fewer folders so the package is smaller.</p>
HTML
                    ),
                    array(
                        'id' => 'emailhelp',
                        'title' => 'Email update instructions',
                        'html' => <<<'HTML'
<p>Controls the “How to update your website” block in every customer update email:</p>
<ul>
  <li><strong>From the admin dashboard</strong> — the text of option 1.</li>
  <li><strong>Automated from the control panel</strong> — the text of option 2. The daily cron command is still added from the project, so the paths are always right.</li>
</ul>
<p>Both boxes are plain text and both are optional: an empty box means the built-in wording, which names the project and the version automatically. The card has its own <em>Save email instructions</em> button and cannot be affected by the main settings form.</p>
HTML
                    ),
                    array(
                        'id' => 'survives',
                        'title' => 'Settings survive updates',
                        'html' => <<<'HTML'
<p><code>settings.json</code> holds the GitHub token, the SMTP password, the Paystack keys and the cron key. It belongs to the <em>installation</em>, not to the code — uploading a new version must never replace it.</p>
<table class="lm-guide-table">
  <thead><tr><th>File</th><th>Tracked in Git</th><th>In an update package</th></tr></thead>
  <tbody>
    <tr><td><code>LM/settings.json</code></td><td>No — ignored, created on the server</td><td>Never — stripped on upload</td></tr>
    <tr><td><code>LM/settings.sample.json</code></td><td>Yes — the reference copy</td><td>Never</td></tr>
    <tr><td><code>LM/storage/app_secret.key</code></td><td>No</td><td>Never</td></tr>
    <tr><td><code>LM/storage/backups/</code></td><td>No</td><td>Never</td></tr>
  </tbody>
</table>
<h4>Two safety nets</h4>
<ol class="lm-guide-steps">
  <li><strong>Snapshots.</strong> Every save of <code>settings.json</code> writes a copy to <code>storage/backups/settings-YYYYmmdd-HHMMSS-xxxxxx.json</code>. The newest 12 are kept.</li>
  <li><strong>Archived keys.</strong> <code>storage/app_secret.key</code> is backed up next to them whenever it is created or read. If a redeploy deletes it, the LM reuses the newest archived key; if the file comes back with a different key, a token encrypted under an older key is decrypted with that archived key and immediately re-encrypted under the current one.</li>
</ol>
<h4>Restoring</h4>
<p>Settings → <em>Settings File &amp; Recovery</em> lists the snapshots with what each one contains: a green <span class="lm-guide-chip">token ✓</span> means the token in it can still be decrypted on this install. <strong>Restore</strong> merges that snapshot over the current values — fields you changed since are kept unless the snapshot has a value too. The current file is snapshotted first, so a restore can be undone by restoring again.</p>
<p class="lm-guide-note">A restore only applies non-empty values, so restoring an old snapshot never blanks a field you have since filled in.</p>
<h4>When the token looks empty after an update</h4>
<ol class="lm-guide-steps">
  <li>Open Settings. If a yellow banner offers a backup with a readable token, press <strong>Restore that backup</strong>.</li>
  <li>Otherwise paste the token and press <strong>Save token</strong> — a new fine-grained token is a good moment to rotate it anyway.</li>
  <li>Check <code>storage/backups/</code> over SFTP or the cPanel file manager if you need an older copy by hand.</li>
</ol>
<p>Uploading a package that still contains <code>settings.json</code> no longer breaks anything: the upload strips it, and the success message lists the files that were removed.</p>
HTML
                    ),
                    array(
                        'id' => 'cronkey',
                        'title' => 'Cron key',
                        'html' => <<<'HTML'
<p>The key authenticates web-cron calls (<code>cron.php?key=…</code>). CLI cron does not need it.</p>
<p><strong>Generate a new cron key</strong> invalidates the old URL immediately — update every cron job and monitoring probe that used it.</p>
<p>Setup steps are in <a href="guide.php#cron">Guide → Cron</a>.</p>
HTML
                    ),
                ),
            ),

            /* ---------------------------------------------------------- */
            'cron' => array(
                'title' => 'Cron',
                'icon' => '⏱️',
                'summary' => 'Set up the schedule, run it by hand, and check it is working.',
                'sections' => array(
                    array(
                        'id' => 'tasks',
                        'title' => 'What the cron job does',
                        'html' => <<<'HTML'
<p>One job handles everything that must happen without an admin watching the page:</p>
<table class="lm-guide-table">
  <thead><tr><th>Task</th><th>What it does</th></tr></thead>
  <tbody>
    <tr><td><code>mail</code></td><td>Delivers the outbound email queue (licence keys, fallbacks) with back-off retries.</td></tr>
    <tr><td><code>notifications</code></td><td>Sends update emails and the next-day reminders, up to the batch size.</td></tr>
    <tr><td><code>cleanup</code></td><td>Deletes download tokens that expired more than a day ago.</td></tr>
    <tr><td><code>github</code></td><td>Auto-pull for projects that enabled it, throttled to once every 30 minutes.</td></tr>
    <tr><td><code>pull_jobs</code></td><td>Runs background pulls that no worker could start, and reaps jobs whose worker disappeared.</td></tr>
  </tbody>
</table>
<p>A lock file (<code>storage/cron.lock</code>) prevents overlapping runs, so a slow SMTP connection cannot stack up.</p>
HTML
                    ),
                    array(
                        'id' => 'cpanel',
                        'title' => 'Set it up in cPanel (recommended)',
                        'html' => <<<'HTML'
<ol class="lm-guide-steps">
  <li>cPanel → <strong>Cron Jobs</strong> → <em>Add New Cron Job</em>.</li>
  <li>Common Settings: <strong>Once per 10 minutes</strong> (<code>*/10 * * * *</code>).</li>
  <li>Command — paste, replacing the path with the one shown in <em>Settings → Automation</em>:</li>
</ol>
<p class="lm-guide-note">The command runs PHP directly, so no web request is involved and the job works even when the admin area is protected by a firewall.</p>
HTML
                        ,
                        'code' => array(
                            array(
                                'label' => '/usr/local/bin/php /home/USER/public_html/lm/cron.php',
                                'text' => "*/10 * * * * /usr/local/bin/php /home/USER/public_html/lm/cron.php >> /home/USER/lm-cron.log 2>&1",
                            ),
                        ),
                    ),
                    array(
                        'id' => 'webcron',
                        'title' => 'Alternative: web cron',
                        'html' => <<<'HTML'
<p>If the host does not allow CLI cron, call the endpoint over HTTP. The URL with its key is on <em>Settings → Automation</em> and in <em>Update Notifications</em>.</p>
<p>It accepts the secret three ways, so it works with any scheduler:</p>
<ul>
  <li>query string — <code>?key=SECRET</code></li>
  <li>header — <code>X-LM-Cron-Key: SECRET</code></li>
  <li>a specific task list — <code>&amp;tasks=mail,notifications</code></li>
</ul>
<p>Point an external scheduler (cron-job.org, UptimeRobot) at the HTTPS URL every 10 minutes. It is idempotent: two runs at the same moment are safe.</p>
HTML
                    ),
                    array(
                        'id' => 'frequency',
                        'title' => 'How often to run it',
                        'html' => <<<'HTML'
<table class="lm-guide-table">
  <thead><tr><th>Interval</th><th>Result</th></tr></thead>
  <tbody>
    <tr><td>Every 5 minutes</td><td>Emails and reminders go out almost immediately. More SMTP connections.</td></tr>
    <tr><td><strong>Every 10 minutes</strong></td><td>Recommended balance.</td></tr>
    <tr><td>Hourly</td><td>Fine for small lists; “next day” reminders can drift by up to an hour.</td></tr>
    <tr><td>Daily</td><td>Reminders are effectively useless — a reminder due at 09:00 may send at midnight.</td></tr>
  </tbody>
</table>
<p>Each run is bounded by the batch size, so a large customer list is spread across several runs rather than one long request.</p>
HTML
                    ),
                    array(
                        'id' => 'verify',
                        'title' => 'Check that it works',
                        'html' => <<<'HTML'
<ol class="lm-guide-steps">
  <li>Look at <em>Push Updates</em> — the sidebar shows the last cron run, and <em>Update Notifications</em> shows queued vs. delivered.</li>
  <li>Run it by hand with the button below; it returns the JSON summary of every task.</li>
  <li>Check <code>LM/storage/logs/cron.log</code> for the same summary plus timings.</li>
</ol>
<p>If queued rows never move, cron is not reaching the script — that is almost always a wrong path, a wrong key, or a disabled <code>curl_fopen</code>/<code>exec</code> on the host.</p>
HTML
                    ),
                    array(
                        'id' => 'crontrouble',
                        'title' => 'Cron troubleshooting',
                        'html' => <<<'HTML'
<table class="lm-guide-table">
  <thead><tr><th>Symptom</th><th>Fix</th></tr></thead>
  <tbody>
    <tr><td><code>{"ok":false,"error":"Invalid cron key"}</code></td><td>Regenerate the key in Settings and update the URL, or use CLI cron which needs no key.</td></tr>
    <tr><td>Empty response / 500</td><td>Check the cPanel error log and <code>storage/logs/</code>; usually a PHP version mismatch or a write permission on <code>storage/</code>.</td></tr>
    <tr><td>“Another cron run is still in progress”</td><td>Normal overlap protection. If it persists, delete <code>storage/cron.lock</code>.</td></tr>
    <tr><td>Emails queue but never send</td><td>The SMTP transport is failing. Send a test email from Settings.</td></tr>
    <tr><td>Reminders never fire</td><td><em>Max reminders</em> is 0, the customers already updated, or the job runs less often than the reminder interval.</td></tr>
    <tr><td>Times look off by hours</td><td>All LM timestamps are UTC. Set the cPanel server timezone to UTC, or accept the offset shown in the log.</td></tr>
  </tbody>
</table>
HTML
                    ),
                ),
            ),

            /* ---------------------------------------------------------- */
            'script' => array(
                'title' => 'Script integration',
                'icon' => '🔌',
                'summary' => 'The OTA protocol: what the target script sends and receives.',
                'sections' => array(
                    array(
                        'id' => 'endpoint',
                        'title' => 'The update check',
                        'html' => <<<'HTML'
<pre class="lm-guide-pre">GET https://your-domain/lm/check-update.php
    ?license_key=VALID-XXXX
    &amp;domain=example.com
    &amp;current_version=7.02
    &amp;project=SAAS</pre>
<table class="lm-guide-table">
  <thead><tr><th>Parameter</th><th>Required</th><th>Notes</th></tr></thead>
  <tbody>
    <tr><td><code>license_key</code></td><td>yes</td><td>Licence key (alias: <code>key</code>).</td></tr>
    <tr><td><code>domain</code></td><td>yes</td><td>Requesting domain; auto-registered when the licence has room.</td></tr>
    <tr><td><code>current_version</code></td><td>yes</td><td>Installed version. Stored per domain and used for reminders.</td></tr>
    <tr><td><code>project</code></td><td>no</td><td>Project code or project key. Defaults to the licence's project.</td></tr>
    <tr><td><code>force_version</code></td><td>no</td><td>Download a specific version instead of the latest.</td></tr>
  </tbody>
</table>
HTML
                    ),
                    array(
                        'id' => 'response',
                        'title' => 'The response',
                        'html' => <<<'HTML'
<pre class="lm-guide-pre">{
  "status": "success",
  "latest_version": "7.03",
  "installed_version": "7.02",
  "update_required": true,
  "changelog": "&lt;ul&gt;&lt;li&gt;New gateway&lt;/li&gt;&lt;/ul&gt;",
  "changelog_format": "html",
  "release_date": "2026-10-23 09:00:00",
  "download_url": "https://your-domain/lm/download.php?token=…",
  "checksum": "sha256…",
  "tier_detected": "SAAS",
  "project": "DGV7.0 SAAS"
}</pre>
<p><code>download_url</code> contains a single-use token valid for 30 minutes. The response carries no files, so the check is cheap and works on any host.</p>
HTML
                    ),
                    array(
                        'id' => 'apply',
                        'title' => 'Applying the package',
                        'html' => <<<'HTML'
<ol class="lm-guide-steps">
  <li>Compare <code>latest_version</code> with the installed version — <code>update_required</code> already tells you.</li>
  <li>Download <code>download_url</code> and verify it against <code>checksum</code> (SHA-256).</li>
  <li>Extract over the installation, refusing paths that escape the root.</li>
  <li>Read <code>manifest.json</code> at the archive root and run <code>database_queries</code> if present.</li>
  <li>Delete the files listed in <code>files_to_delete</code>.</li>
  <li>Write the new version to your local config and keep reporting it as <code>current_version</code> — that is what stops the reminders.</li>
</ol>
HTML
                    ),
                    array(
                        'id' => 'manifest',
                        'title' => 'manifest.json',
                        'html' => <<<'HTML'
<pre class="lm-guide-pre">{
  "version": "7.03",
  "tier": "SAAS",
  "project": "DGV7.0 SAAS",
  "source": "github",
  "repository": "owner/repo",
  "branch": "main",
  "commit": "9f2c…",
  "released_at": "2026-10-23T09:00:00+00:00",
  "changelog": "Plain-text changelog",
  "files_to_delete": [],
  "database_queries": []
}</pre>
<p>The LM writes this file itself, replacing any manifest at the archive root. An app's own <code>public/manifest.json</code> is left untouched.</p>
HTML
                    ),
                    array(
                        'id' => 'client',
                        'title' => 'PHP client you can paste',
                        'html' => <<<'HTML'
<p>The <em>Integration</em> button on each project generates this with the right URL and project code.</p>
<p class="lm-guide-note">Cache the check for a few hours so the target site does not call the LM on every page load.</p>
HTML
                        ,
                        'code' => array(
                            array(
                                'label' => 'includes/ota-client.php',
                                'text' => "<?php\nfunction ota_check(\$licenseKey, \$domain, \$currentVersion, \$project)\n{\n    \$url = 'https://your-domain/lm/check-update.php?' . http_build_query(array(\n        'license_key'     => \$licenseKey,\n        'domain'          => \$domain,\n        'current_version' => \$currentVersion,\n        'project'         => \$project,\n    ));\n\n    \$context = stream_context_create(array('http' => array('timeout' => 12)));\n    \$raw = @file_get_contents(\$url, false, \$context);\n    if (\$raw === false) {\n        return null;\n    }\n\n    \$data = json_decode(\$raw, true);\n    if (!is_array(\$data) || empty(\$data['update_required'])) {\n        return null;\n    }\n\n    return \$data; // latest_version, changelog, download_url, checksum\n}",
                            ),
                        ),
                    ),
                ),
            ),
        );

        return $pages;
    }
}

if (!function_exists('lm_guide_page')) {
    /**
     * @param string $key
     * @return array|null
     */
    function lm_guide_page($key)
    {
        $pages = lm_guide_pages();
        return isset($pages[$key]) ? $pages[$key] : null;
    }
}

if (!function_exists('lm_guide_cron_info')) {
    /**
     * Everything needed to document or test the cron job.
     *
     * @return array
     */
    function lm_guide_cron_info()
    {
        $secret = lm_get_cron_secret();
        $dir = rtrim(lm_lm_dir(), '/');

        return array(
            'php' => '/usr/local/bin/php',
            'script' => $dir . '/cron.php',
            'command' => '*/10 * * * * /usr/local/bin/php ' . $dir . '/cron.php >> '
                . lm_storage_dir() . '/logs/cron-output.log 2>&1',
            'web' => lm_site_url('cron.php?key=' . rawurlencode($secret)),
            'tasks_web' => lm_site_url('cron.php?key=' . rawurlencode($secret) . '&tasks=mail,notifications'),
            'has_secret' => $secret !== '',
            'log' => lm_storage_dir() . '/logs/cron.log',
        );
    }
}

if (!function_exists('lm_guide_section_html')) {
    /**
     * Render one section (body + optional code block + copy button).
     *
     * @param array $section
     * @param bool  $open
     * @return string
     */
    function lm_guide_section_html(array $section, $open = false)
    {
        $id = isset($section['id']) ? (string) $section['id'] : '';
        $title = isset($section['title']) ? (string) $section['title'] : '';
        $html = isset($section['html']) ? (string) $section['html'] : '';

        $out = '<details class="lm-guide-section"' . ($open ? ' open' : '') . ($id !== '' ? ' id="' . htmlspecialchars($id) . '"' : '') . '>';
        $out .= '<summary><span class="lm-guide-chev">▸</span>' . htmlspecialchars($title) . '</summary>';
        $out .= '<div class="lm-guide-body">' . $html;

        if (!empty($section['code']) && is_array($section['code'])) {
            foreach ($section['code'] as $block) {
                $label = isset($block['label']) ? (string) $block['label'] : '';
                $text = isset($block['text']) ? (string) $block['text'] : '';
                $out .= '<div class="lm-guide-code">';
                if ($label !== '') {
                    $out .= '<div class="lm-guide-code-head"><span>' . htmlspecialchars($label) . '</span>'
                        . '<button type="button" class="lm-guide-copy" data-copy="' . htmlspecialchars($text, ENT_QUOTES) . '">Copy</button></div>';
                }
                $out .= '<pre>' . htmlspecialchars($text) . '</pre></div>';
            }
        }

        $out .= '</div></details>';

        return $out;
    }
}

if (!function_exists('lm_guide_assets')) {
    /**
     * Print the drawer styles and JS once per page.
     *
     * @return void
     */
    function lm_guide_assets()
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        $pages = array();
        foreach (lm_guide_pages() as $key => $page) {
            $items = array();
            foreach ($page['sections'] as $section) {
                $items[] = array(
                    'id' => isset($section['id']) ? $section['id'] : '',
                    'title' => $section['title'],
                    'html' => isset($section['html']) ? $section['html'] : '',
                    'code' => isset($section['code']) ? $section['code'] : array(),
                );
            }
            $pages[$key] = array(
                'title' => $page['title'],
                'icon' => $page['icon'],
                'summary' => $page['summary'],
                'sections' => $items,
            );
        }

        $json = json_encode($pages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
<style>
    .lm-guide-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.55); z-index: 120; display: none; }
    .lm-guide-backdrop.open { display: block; }
    .lm-guide-drawer { position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100%); background: #fff; z-index: 121;
        box-shadow: -24px 0 60px rgba(15,23,42,.28); transform: translateX(102%); transition: transform .24s ease; display: flex; flex-direction: column; }
    .lm-guide-drawer.open { transform: translateX(0); }
    .lm-guide-drawer header { padding: 20px 22px 16px; border-bottom: 1px solid #e5e9f2; display: flex; gap: 12px; align-items: flex-start; }
    .lm-guide-drawer header .icon { font-size: 22px; line-height: 1; }
    .lm-guide-drawer header h3 { font-size: 16px; font-weight: 800; letter-spacing: -.2px; }
    .lm-guide-drawer header p { font-size: 12px; color: #64748b; margin-top: 5px; line-height: 1.55; }
    .lm-guide-drawer header button { margin-left: auto; border: none; background: #f1f5f9; width: 32px; height: 32px; border-radius: 9px; cursor: pointer; font-size: 15px; color: #475569; flex: 0 0 auto; }
    .lm-guide-scroll { overflow-y: auto; padding: 18px 22px 40px; flex: 1; }
    .lm-guide-section { border: 1px solid #e5e9f2; border-radius: 12px; margin-bottom: 10px; background: #fff; }
    .lm-guide-section[open] { box-shadow: 0 8px 22px rgba(15,23,42,.05); }
    .lm-guide-section > summary { cursor: pointer; padding: 13px 15px; font-size: 13px; font-weight: 700; color: #1e293b; list-style: none; display: flex; gap: 8px; align-items: center; }
    .lm-guide-section > summary::-webkit-details-marker { display: none; }
    .lm-guide-chev { color: #94a3b8; font-size: 10px; transition: transform .18s ease; display: inline-block; }
    .lm-guide-section[open] .lm-guide-chev { transform: rotate(90deg); }
    .lm-guide-body { padding: 0 15px 15px; font-size: 12.5px; line-height: 1.7; color: #334155; }
    .lm-guide-body p { margin: 0 0 10px; }
    .lm-guide-body h4 { font-size: 12.5px; font-weight: 800; margin: 16px 0 8px; color: #0f172a; }
    .lm-guide-body ul, .lm-guide-body ol { margin: 0 0 10px; padding-left: 20px; }
    .lm-guide-body li { margin-bottom: 5px; }
    .lm-guide-body code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; font-family: 'JetBrains Mono', monospace; font-size: 11px; }
    .lm-guide-body a { color: #4f46e5; font-weight: 600; }
    .lm-guide-steps { counter-reset: step; list-style: none; padding-left: 0 !important; }
    .lm-guide-steps li { counter-increment: step; position: relative; padding-left: 26px; margin-bottom: 8px; }
    .lm-guide-steps li::before { content: counter(step); position: absolute; left: 0; top: 1px; width: 18px; height: 18px; border-radius: 50%;
        background: #eef2ff; color: #4338ca; font-size: 10px; font-weight: 800; display: grid; place-items: center; }
    .lm-guide-table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; font-size: 11.5px; }
    .lm-guide-table th { text-align: left; background: #f8fafc; color: #64748b; font-size: 10px; letter-spacing: .8px; text-transform: uppercase; padding: 7px 9px; border: 1px solid #e9eef7; }
    .lm-guide-table td { padding: 7px 9px; border: 1px solid #eef2f8; vertical-align: top; line-height: 1.6; }
    .lm-guide-note { background: #eff6ff; border: 1px solid #dbeafe; border-radius: 9px; padding: 9px 11px; color: #1e40af; font-size: 12px; }
    .lm-guide-chip { display: inline-block; background: #ecfdf5; color: #047857; border: 1px solid #bbf7d0; border-radius: 999px; padding: 1px 8px; font-size: 12px; font-weight: 800; }
    .lm-guide-pre { background: #0f172a; color: #e2e8f0; padding: 12px 14px; border-radius: 9px; overflow: auto; font-size: 11px; line-height: 1.6; font-family: 'JetBrains Mono', monospace; margin: 0 0 10px; }
    .lm-guide-code { margin: 10px 0 4px; }
    .lm-guide-code-head { display: flex; justify-content: space-between; align-items: center; background: #1e293b; color: #cbd5e1; font-size: 10.5px;
        padding: 7px 12px; border-radius: 9px 9px 0 0; font-family: 'JetBrains Mono', monospace; }
    .lm-guide-code pre { background: #0f172a; color: #e2e8f0; padding: 12px 14px; border-radius: 0 0 9px 9px; overflow: auto; font-size: 11px;
        line-height: 1.6; margin: 0; font-family: 'JetBrains Mono', monospace; }
    .lm-guide-copy { border: none; background: rgba(148,163,184,.2); color: #e2e8f0; border-radius: 6px; padding: 3px 8px; font-size: 10px; cursor: pointer; font-family: inherit; }
    .lm-guide-copy:hover { background: rgba(148,163,184,.35); }
    .lm-guide-foot { margin-top: 16px; padding-top: 14px; border-top: 1px solid #e5e9f2; text-align: center; }
    .lm-guide-foot a { color: #4f46e5; font-size: 12px; font-weight: 700; text-decoration: none; }
</style>
<script>
    var LM_GUIDE = { pages: <?= $json ?>, open: null };

    function lmGuideEscape(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function lmGuideEnsure() {
        if (document.getElementById('lmGuideDrawer')) return;

        var backdrop = document.createElement('div');
        backdrop.className = 'lm-guide-backdrop';
        backdrop.id = 'lmGuideBackdrop';
        backdrop.addEventListener('click', lmGuideClose);

        var drawer = document.createElement('aside');
        drawer.className = 'lm-guide-drawer';
        drawer.id = 'lmGuideDrawer';
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.innerHTML = '<header><span class="icon" id="lmGuideIcon">📖</span>'
            + '<div style="flex:1;min-width:0;"><h3 id="lmGuideTitle">Guide</h3><p id="lmGuideSummary"></p></div>'
            + '<button type="button" aria-label="Close" data-lm-guide-close>✕</button></header>'
            + '<div class="lm-guide-scroll" id="lmGuideScroll"></div>';

        document.body.appendChild(backdrop);
        document.body.appendChild(drawer);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') lmGuideClose();
        });

        drawer.addEventListener('click', function (event) {
            var button = event.target.closest('.lm-guide-copy');
            if (!button) return;
            var text = button.getAttribute('data-copy') || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    button.textContent = 'Copied';
                    setTimeout(function () { button.textContent = 'Copy'; }, 1500);
                });
            }
        });
    }

    function lmGuideOpen(key) {
        var page = LM_GUIDE.pages && LM_GUIDE.pages[key];
        if (!page) return false;

        lmGuideEnsure();
        LM_GUIDE.trigger = document.activeElement;
        LM_GUIDE.open = key;
        document.getElementById('lmGuideIcon').textContent = page.icon || '📖';
        document.getElementById('lmGuideTitle').textContent = page.title;
        document.getElementById('lmGuideSummary').textContent = page.summary || '';

        var html = '';
        (page.sections || []).forEach(function (section, index) {
            html += '<details class="lm-guide-section"' + (index === 0 ? ' open' : '') + '>'
                + '<summary><span class="lm-guide-chev">▸</span>' + lmGuideEscape(section.title) + '</summary>'
                + '<div class="lm-guide-body">' + (section.html || '');

            (section.code || []).forEach(function (block) {
                if (block.label) {
                    html += '<div class="lm-guide-code"><div class="lm-guide-code-head"><span>' + lmGuideEscape(block.label) + '</span>'
                        + '<button type="button" class="lm-guide-copy" data-copy="' + lmGuideEscape(block.text || '') + '">Copy</button></div>';
                } else {
                    html += '<div class="lm-guide-code">';
                }
                html += '<pre>' + lmGuideEscape(block.text || '') + '</pre></div>';
            });

            html += '</div></details>';
        });

        html += '<div class="lm-guide-foot"><a href="guide.php#' + encodeURIComponent(key) + '">Open the full guide →</a></div>';

        document.getElementById('lmGuideScroll').innerHTML = html;
        document.getElementById('lmGuideScroll').scrollTop = 0;
        document.getElementById('lmGuideBackdrop').classList.add('open');
        document.getElementById('lmGuideDrawer').classList.add('open');
        document.body.style.overflow = 'hidden';

        var closeBtn = document.querySelector('#lmGuideDrawer [data-lm-guide-close]');
        if (closeBtn) closeBtn.focus();

        return true;
    }

    function lmGuideClose() {
        var backdrop = document.getElementById('lmGuideBackdrop');
        var drawer = document.getElementById('lmGuideDrawer');
        if (backdrop) backdrop.classList.remove('open');
        if (drawer) drawer.classList.remove('open');
        if (LM_GUIDE.open) {
            LM_GUIDE.open = null;
            document.body.style.overflow = '';
            if (LM_GUIDE.trigger && LM_GUIDE.trigger.focus) LM_GUIDE.trigger.focus();
        }
    }

    // One delegated listener: works for every button on the page, whatever
    // order the markup arrives in, and without inline event attributes.
    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) return;

        var trigger = target.closest('[data-lm-guide]');
        if (trigger) {
            var key = trigger.getAttribute('data-lm-guide');
            if (lmGuideOpen(key)) event.preventDefault();
            return;
        }

        if (target.closest('[data-lm-guide-close]')) {
            event.preventDefault();
            lmGuideClose();
        }
    });
</script>
        <?php
    }
}

if (!function_exists('lm_guide_help_button')) {
    /**
     * Contextual "How this page works" button.
     *
     * @param string $key  Page key from lm_guide_pages()
     * @param string $label
     * @return string HTML
     */
    function lm_guide_help_button($key, $label = 'How this page works')
    {
        $page = lm_guide_page($key);
        if (!$page) {
            return '';
        }

        lm_guide_assets();

        // An <a> rather than a <button>: the drawer intercepts the click, but if
        // JavaScript is unavailable the link still lands on the full guide.
        return '<a class="lm-guide-btn" data-lm-guide="' . htmlspecialchars($key, ENT_QUOTES) . '"'
            . ' href="guide.php#' . rawurlencode($key) . '"'
            . ' style="display:inline-flex;align-items:center;gap:7px;padding:10px 15px;border-radius:10px;'
            . 'border:1px solid #d7deea;background:#fff;color:#334155;font-family:inherit;font-size:13px;'
            . 'font-weight:700;cursor:pointer;white-space:nowrap;text-decoration:none;line-height:1.2;"'
            . ' onmouseover="this.style.background=\'#f8faff\';this.style.borderColor=\'#c7d2fe\';"'
            . ' onmouseout="this.style.background=\'#fff\';this.style.borderColor=\'#d7deea\';">'
            . '📖 ' . htmlspecialchars($label) . '</a>';
    }
}
