<?php
/**
 * Public landing page.
 *
 * Marketing surface for the licence service: what it does, how a release
 * travels, and the three ways in (buy, read the API, sign in).
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';

$siteName = lm_site_name();
$signedIn = lm_public_user() !== '';
$version  = defined('LM_VERSION') ? LM_VERSION : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#070b16">
    <meta name="description" content="<?= htmlspecialchars($siteName) ?> issues licence keys, ships script updates and tells your customers when a new version is out.">
    <title><?= htmlspecialchars($siteName) ?> — licences, updates and customer notifications</title>
    <?php lm_public_assets(); ?>
</head>
<body>
<?php lm_public_header('home'); ?>

<main id="main">
    <section class="lm-hero">
        <div class="lm-wrap lm-hero__grid">
            <div>
                <span class="lm-eyebrow"><span class="lm-eyebrow__dot"></span> Release console</span>
                <h1>Ship your script.<br><em>We handle the licences.</em></h1>
                <p class="lm-hero__lead">
                    One place to issue keys, verify domains, publish updates and tell every customer
                    the moment a new version lands — without a spreadsheet, a manual email or a
                    FTP folder full of ZIPs.
                </p>

                <div class="lm-actions">
                    <a class="lm-btn" href="<?= htmlspecialchars(lm_public_url('order.php')) ?>">
                        Get a licence <?= lm_icon('arrow') ?>
                    </a>
                    <a class="lm-btn lm-btn--ghost" href="<?= htmlspecialchars(lm_public_url('api-docs.php')) ?>">
                        Read the API
                    </a>
                </div>

                <div class="lm-hero__stats">
                    <div class="lm-hero__stat"><strong>2 min</strong><span>from payment to key</span></div>
                    <div class="lm-hero__stat"><strong>1 call</strong><span>to verify a domain</span></div>
                    <div class="lm-hero__stat"><strong>0 files</strong><span>to move by hand</span></div>
                </div>
            </div>

            <div class="lm-mock" aria-hidden="true">
                <div class="lm-mock__inner">
                    <div class="lm-mock__bar">
                        <span class="lm-mock__dots"><i></i><i></i><i></i></span>
                        <span class="lm-mock__url">/lm/admin/licenses.php</span>
                    </div>
                    <div class="lm-mock__body">
                        <div class="lm-mock__title">
                            Licences <span>live</span>
                        </div>

                        <div class="lm-mock__row">
                            <code>VALID·7F3A·C21D·9B04</code>
                            <span class="lm-pill">active</span>
                        </div>
                        <div class="lm-mock__row">
                            <code>client-store.com</code>
                            <span class="lm-pill">active</span>
                        </div>
                        <div class="lm-mock__row">
                            <code>VALID·A9E1·44C8·2F70</code>
                            <span class="lm-pill lm-pill--amber">expiring</span>
                        </div>
                        <div class="lm-mock__row">
                            <code>v7.04 · 12 Feb</code>
                            <span class="lm-pill">shipped</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="lm-section" id="features">
        <div class="lm-wrap">
            <div class="lm-section__head" data-reveal>
                <span class="lm-eyebrow lm-eyebrow--light">Everything in one console</span>
                <h2 class="lm-h2">The boring parts, handled.</h2>
                <p class="lm-sub">
                    Keys, domains, updates and email are one workflow. You publish a version once;
                    every licensed install hears about it.
                </p>
            </div>

            <div class="lm-grid-3">
                <article class="lm-feature" data-reveal>
                    <div class="lm-icon"><?= lm_icon('key') ?></div>
                    <h3>Keys that verify</h3>
                    <p>Every key is checked against its project, its status and the domains it is
                        allowed to run on — server-side, in one request.</p>
                </article>

                <article class="lm-feature" data-reveal>
                    <div class="lm-icon"><?= lm_icon('rocket') ?></div>
                    <h3>Updates without FTP</h3>
                    <p>Upload a ZIP or pull straight from GitHub. The console bumps the version,
                        injects a manifest and keeps the package on your server.</p>
                </article>

                <article class="lm-feature" data-reveal>
                    <div class="lm-icon"><?= lm_icon('mail') ?></div>
                    <h3>Customers told, once</h3>
                    <p>A personal download link goes out when you publish, and a reminder follows
                        the next day only if the install has not reported the new version.</p>
                </article>

                <article class="lm-feature" data-reveal>
                    <div class="lm-icon"><?= lm_icon('grid') ?></div>
                    <h3>Many projects, one panel</h3>
                    <p>DGV7 SAAS, NON-SAAS, VTU — each project gets its own pool, version history
                        and storage folder, all managed from the same place.</p>
                </article>

                <article class="lm-feature" data-reveal>
                    <div class="lm-icon"><?= lm_icon('shield') ?></div>
                    <h3>Domains stay honest</h3>
                    <p>Licences carry a domain limit, a change budget and an audit trail. A key
                        copied to a second site stops verifying.</p>
                </article>

                <article class="lm-feature" data-reveal>
                    <div class="lm-icon"><?= lm_icon('clock') ?></div>
                    <h3>Works while you sleep</h3>
                    <p>Cron drains the mail queue, retries failed sends and runs queued pulls,
                        so a release published at midnight is delivered by morning.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="lm-section lm-section--tint" id="how">
        <div class="lm-wrap">
            <div class="lm-section__head" data-reveal>
                <span class="lm-eyebrow lm-eyebrow--light">How a release travels</span>
                <h2 class="lm-h2">Package, publish, notify.</h2>
                <p class="lm-sub">The same three steps whether the code comes from a ZIP or a GitHub branch.</p>
            </div>

            <div class="lm-steps">
                <article class="lm-step" data-reveal>
                    <span class="lm-step__n">01</span>
                    <h3>Package</h3>
                    <p>Drop in a ZIP, or pick a repository, branch and the folders you want. Runtime
                        files such as settings are stripped automatically.</p>
                </article>
                <article class="lm-step" data-reveal>
                    <span class="lm-step__n">02</span>
                    <h3>Publish</h3>
                    <p>The next version number is derived from the last one, the changelog is
                        stored, and the package gets a manifest your script can read.</p>
                </article>
                <article class="lm-step" data-reveal>
                    <span class="lm-step__n">03</span>
                    <h3>Notify</h3>
                    <p>Every active licence holder gets one email with a personal, expiring
                        download link. Installs that update stop the reminder.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="lm-section">
        <div class="lm-wrap">
            <div class="lm-code-card" data-reveal>
                <div>
                    <span class="lm-eyebrow lm-eyebrow--light">For developers</span>
                    <h3>Two lines in your script.</h3>
                    <p>Ask the endpoint what the latest version is. It answers with the changelog,
                        a signed download URL and a checksum.</p>
                    <ul class="lm-checklist">
                        <li><?= lm_icon('check') ?> Works with cURL or plain <code>file_get_contents()</code></li>
                        <li><?= lm_icon('check') ?> Returns JSON, never HTML errors</li>
                        <li><?= lm_icon('check') ?> Rate-limited and safe to call on every admin page load</li>
                    </ul>
                    <div class="lm-actions" style="margin-top:22px;margin-bottom:0">
                        <a class="lm-btn lm-btn--dark" href="<?= htmlspecialchars(lm_public_url('api-docs.php')) ?>">
                            API reference <?= lm_icon('arrow') ?>
                        </a>
                    </div>
                </div>

                <div class="lm-code">
                    <div class="lm-code__bar">
                        <span>check-update.php</span>
                        <button class="lm-copy" type="button" data-copy="#lm-sample">Copy</button>
                    </div>
                    <pre id="lm-sample"><code><span class="tok-com">// in your script</span>
$url = <span class="tok-str">'https://your-domain/lm/check-update.php'</span>
     . <span class="tok-str">'?license_key='</span> . $key
     . <span class="tok-str">'&amp;domain='</span>      . $domain
     . <span class="tok-str">'&amp;current_version='</span> . $version;

$data = json_decode(file_get_contents($url), <span class="tok-num">true</span>);

<span class="tok-key">if</span> (!empty($data[<span class="tok-str">'update_required'</span>])) {
    download($data[<span class="tok-str">'download_url'</span>]);
}</code></pre>
                </div>
            </div>
        </div>
    </section>

    <section class="lm-section lm-section--tight">
        <div class="lm-wrap">
            <div class="lm-cta-band" data-reveal>
                <div>
                    <h2>Ready when your next version is.</h2>
                    <p>Create a licence, point your script at the endpoint, and publish from the
                        console. No migration, no lock-in.</p>
                </div>
                <div class="lm-actions">
                    <a class="lm-btn lm-btn--light" href="<?= htmlspecialchars(lm_public_url('order.php')) ?>">Get a licence</a>
                    <a class="lm-btn lm-btn--ghost" href="<?= htmlspecialchars(lm_public_url($signedIn ? 'user/index.php' : 'user/login.php')) ?>">
                        <?= $signedIn ? 'Open my account' : 'Sign in' ?>
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php lm_public_footer(); ?>
<?php lm_public_tabbar('home'); ?>
</body>
</html>
