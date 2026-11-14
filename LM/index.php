<?php
/**
 * index.php — the public landing page.
 *
 * One page, one stylesheet (assets/site.css), no inline CSS. Every link
 * resolves through lm_public_url() so the page works from /lm/ as well as from
 * the document root, and every menu entry the rest of the app advertises is
 * reachable from here.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';

$signedIn = lm_public_user() !== '';
$price = '';

if (function_exists('lm_settings')) {
    $settings = lm_settings();
    $price = trim((string) ($settings['license_price'] ?? ''));
}

$features = array(
    array('icon' => 'key', 'title' => 'Licence keys that verify', 'text' => 'Every key is bound to a domain and checked server-side on each request — no client-side guesswork, no shared secrets in your JavaScript.'),
    array('icon' => 'rocket', 'title' => 'Push updates over the air', 'text' => 'Upload a ZIP once. Customers are told there is a new version, and only licensed installs can download it.'),
    array('icon' => 'shield', 'title' => 'Domain locking', 'text' => 'A key that is valid on example.com is refused everywhere else. Customers can move it themselves, up to the limit you set.'),
    array('icon' => 'bell', 'title' => 'Notifications that land', 'text' => 'Update reminders are queued and sent over your own SMTP, with one-click opt-out handled in the database.'),
    array('icon' => 'wallet', 'title' => 'Payments wired in', 'text' => 'Paystack checkout, webhook verification, automatic licence delivery and a transaction trail you can audit.'),
    array('icon' => 'chart', 'title' => 'One console for all of it', 'text' => 'Licences, requests, revenue, releases and health checks live in a single admin area — on a phone as well as a desktop.'),
);

$steps = array(
    array('t' => 'Issue or sell a licence', 'd' => 'Approve a request from the console, or let a Paystack payment create the key automatically and email it to the customer.'),
    array('t' => 'Ship a signed update', 'd' => 'Upload the ZIP for the version you just built. The console stores it, hashes it and records the changelog.'),
    array('t' => 'Customers update in place', 'd' => 'Their install asks the update endpoint, proves the licence, downloads the ZIP and reports the new version back.'),
);
?>
<?php lm_public_page_start('home', array('title' => 'Licensing, updates and delivery', 'bare' => true)); ?>

<section class="lm-hero" id="main">
    <div class="lm-wrap lm-hero__grid">
        <div>
            <span class="lm-eyebrow"><span class="lm-eyebrow__dot"></span> Licence manager v<?= lm_e(defined('LM_VERSION') ? LM_VERSION : '') ?></span>
            <h1>Licences, updates and delivery in <em>one console</em>.</h1>
            <p class="lm-hero__lead">
                Sell a script, bind the key to a domain, ship updates over the air and tell every
                customer about them — without spreadsheets, manual emails or a second dashboard.
            </p>

            <div class="lm-actions">
                <?php if ($signedIn): ?>
                    <a class="lm-btn lm-btn--glow" href="<?= lm_e(lm_public_url('user/index.php')) ?>">
                        <?= lm_icon('grid') ?> Open my account
                    </a>
                <?php else: ?>
                    <a class="lm-btn lm-btn--glow" href="<?= lm_e(lm_public_url('order.php')) ?>">
                        <?= lm_icon('key') ?> Get a licence
                    </a>
                <?php endif; ?>
                <a class="lm-btn lm-btn--light" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                    <?= lm_icon('book') ?> Read the API docs
                </a>
            </div>

            <dl class="lm-hero__stats">
                <div class="lm-hero__stat"><strong>2 min</strong><span>from upload to update</span></div>
                <div class="lm-hero__stat"><strong>0</strong><span>client-side secrets</span></div>
                <div class="lm-hero__stat"><strong>&infin;</strong><span>installs per key</span></div>
            </dl>
        </div>

        <div class="lm-mock" aria-hidden="true">
            <div class="lm-mock__inner">
                <div class="lm-mock__bar">
                    <span class="lm-mock__dots"><i></i><i></i><i></i></span>
                    <span class="lm-mock__url">POST /api.php</span>
                </div>
                <div class="lm-mock__body">
                    <div class="lm-mock__title">Validate a licence <span>200 OK</span></div>
                    <div class="lm-mock__row"><code>key=LM-7F2A-94C1</code><span class="lm-pill">valid</span></div>
                    <div class="lm-mock__row"><code>domain=shop.example.com</code><span class="lm-pill">matches</span></div>
                    <div class="lm-mock__row"><code>status=active</code><span class="lm-pill">active</span></div>
                    <div class="lm-mock__row"><code>version=2.4.1 → 2.5.0</code><span class="lm-pill lm-pill--amber">update</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="lm-section" id="features">
    <div class="lm-wrap">
        <div class="lm-section__head">
            <span class="lm-eyebrow lm-eyebrow--light"><span class="lm-eyebrow__dot"></span> Everything included</span>
            <h2 class="lm-h2">The parts you would otherwise build twice.</h2>
            <p class="lm-sub">Issue keys, enforce domains, deliver updates and chase customers for new versions.
                It is all here, and it is all the same code path.</p>
        </div>

        <div class="lm-grid-3">
            <?php foreach ($features as $f): ?>
                <article class="lm-feature">
                    <div class="lm-icon"><?= lm_icon($f['icon']) ?></div>
                    <h3><?= lm_e($f['title']) ?></h3>
                    <p><?= lm_e($f['text']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="lm-section lm-section--tint" id="how">
    <div class="lm-wrap">
        <div class="lm-section__head">
            <h2 class="lm-h2">Three steps, then it runs itself.</h2>
            <p class="lm-sub">The first five minutes are the only manual ones.</p>
        </div>

        <div class="lm-steps">
            <?php foreach ($steps as $i => $step): ?>
                <article class="lm-step">
                    <span class="lm-step__n"><?= $i + 1 ?></span>
                    <h3><?= lm_e($step['t']) ?></h3>
                    <p><?= lm_e($step['d']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="lm-section">
    <div class="lm-wrap">
        <div class="lm-code-card">
            <div>
                <h3>Integrate in one request.</h3>
                <p>The API takes a key and a domain and answers with a status. No SDK, no OAuth,
                    no build step — a form POST or a fetch call is enough.</p>
                <ul class="lm-checklist">
                    <li><?= lm_icon('check') ?> Works from server-side PHP, Node, Python or plain JavaScript.</li>
                    <li><?= lm_icon('check') ?> Domain changes made in the customer dashboard apply immediately.</li>
                    <li><?= lm_icon('check') ?> Public endpoint — no key is ever exposed to the browser.</li>
                    <li><?= lm_icon('check') ?> Full reference, error codes and examples in the docs.</li>
                </ul>
                <div class="lm-actions lm-mt-24">
                    <a class="lm-btn" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                        <?= lm_icon('code') ?> Open the API reference
                    </a>
                    <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('api-docs.php#examples')) ?>">
                        <?= lm_icon('terminal') ?> See code samples
                    </a>
                </div>
            </div>

            <div class="lm-code">
                <div class="lm-code__bar">
                    <span>verify.js</span>
                    <?= lm_copy_button("const res = await fetch('/api.php', {\n  method: 'POST',\n  body: new URLSearchParams({ key, domain })\n});\nconst data = await res.json();\nconsole.log(data.status === 1 ? 'valid' : 'invalid');", 'Copy', false) ?>
                </div>
                <pre><code><span class="tok-com">// runs on your server, never in the buyer's browser</span>
<span class="tok-key">const</span> res = <span class="tok-key">await</span> fetch(<span class="tok-str">'/api.php'</span>, {
  method: <span class="tok-str">'POST'</span>,
  body: <span class="tok-key">new</span> URLSearchParams({ key, domain })
});

<span class="tok-key">const</span> data = <span class="tok-key">await</span> res.json();
<span class="tok-key">if</span> (data.status === <span class="tok-num">1</span>) {
  <span class="tok-com">// licensed — continue boot</span>
  boot();
}</code></pre>
            </div>
        </div>
    </div>
</section>

<section class="lm-section lm-section--tight" id="faq">
    <div class="lm-wrap">
        <div class="lm-code-card">
            <div>
                <span class="lm-eyebrow lm-eyebrow--light"><span class="lm-eyebrow__dot"></span> Answers</span>
                <h3>Questions buyers ask first.</h3>
                <p>If yours is not here, the docs go deeper — and the console has an Admin Guide
                    built into every screen.</p>
                <?php if ($price !== '' && (float) $price > 0): ?>
                    <p class="lm-meta lm-mt-16">Current licence price: <strong><?= lm_e($price) ?></strong> per domain.</p>
                <?php endif; ?>
                <div class="lm-actions lm-mt-24">
                    <a class="lm-btn" href="<?= lm_e(lm_public_url('order.php')) ?>"><?= lm_icon('key') ?> Start a licence</a>
                    <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('api-docs.php#faq')) ?>"><?= lm_icon('question') ?> API FAQ</a>
                </div>
            </div>

            <div class="lm-faq">
                <div class="lm-item">
                    <h4 class="lm-item__q">Do I need to change my script?</h4>
                    <p class="lm-item__text">One POST on boot. The response tells you whether to continue, and
                        whether a newer version is waiting.</p>
                </div>
                <div class="lm-item">
                    <h4 class="lm-item__q">What happens if a customer moves domain?</h4>
                    <p class="lm-item__text">They change it from their account page. You set how many moves a
                        licence gets; the API honours it immediately.</p>
                </div>
                <div class="lm-item">
                    <h4 class="lm-item__q">Where do updates live?</h4>
                    <p class="lm-item__text">On your server, in <code>storage/</code>. Only a licensed install
                        receives a download token, and tokens expire.</p>
                </div>
                <div class="lm-item">
                    <h4 class="lm-item__q">Can I try it without paying?</h4>
                    <p class="lm-item__text">If the payment gateway is off, requests are queued for you to
                        approve by hand. Both paths end in the same dashboard.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="lm-section lm-section--tight">
    <div class="lm-wrap">
        <div class="lm-cta-band">
            <div>
                <h2>Ready when your next release is.</h2>
                <p>Create the key, upload the ZIP, let the console do the telling.</p>
            </div>
            <div class="lm-actions">
                <a class="lm-btn lm-btn--glow" href="<?= lm_e(lm_public_url('order.php')) ?>"><?= lm_icon('rocket') ?> Get a licence</a>
                <a class="lm-btn lm-btn--light" href="<?= lm_e(lm_public_url('user/login.php')) ?>"><?= lm_icon('user') ?> Sign in</a>
            </div>
        </div>
    </div>
</section>

<?php lm_public_page_end(array('no_tabbar' => true)); ?>
