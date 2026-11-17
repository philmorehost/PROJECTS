<?php
/**
 * api-docs.php — the public API reference.
 *
 * Content is the same reference that shipped before (overview, parameters,
 * status codes, examples, integration patterns, errors, FAQ); the page around
 * it is now the shared shell with a sticky table of contents, so it is usable
 * on a phone as well as on a wide screen.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/includes/ui.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'yourdomain.com';
if (strpos((string) $host, ',') !== false) {
    $host = trim(explode(',', (string) $host)[0]);
}
$host = trim((string) $host);
$baseUrl = $scheme . '://' . $host . '/api.php';
$today = date('Y-m-d');

$toc = array(
    'overview'   => 'Overview',
    'base-url'   => 'Base URL',
    'endpoints'  => 'Endpoints',
    'params'     => 'Parameters',
    'responses'  => 'Responses',
    'errors'     => 'Error handling',
    'examples'   => 'Code examples',
    'patterns'   => 'Integration patterns',
    'best'       => 'Best practices',
    'faq'        => 'FAQ',
);

$jsExample = "fetch('" . $baseUrl . "', {\n"
    . "    method: 'POST',\n"
    . "    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },\n"
    . "    body: new URLSearchParams({\n"
    . "        key: 'LM-XXXXXXXXXXXX',\n"
    . "        domain: window.location.hostname\n"
    . "    })\n"
    . "})\n"
    . ".then(r => r.json())\n"
    . ".then(data => {\n"
    . "    if (data.status !== 1) {\n"
    . "        console.warn('Invalid licence:', data.message);\n"
    . "    }\n"
    . "});";

$phpExample = "\$ch = curl_init('" . $baseUrl . "');\n"
    . "curl_setopt_array(\$ch, [\n"
    . "    CURLOPT_POST           => true,\n"
    . "    CURLOPT_POSTFIELDS     => http_build_query([\n"
    . "        'key'    => \$row['license_key'],\n"
    . "        'domain' => \$_SERVER['HTTP_HOST'],\n"
    . "    ]),\n"
    . "    CURLOPT_RETURNTRANSFER => true,\n"
    . "]);\n\n"
    . "\$result = json_decode(curl_exec(\$ch), true);\n"
    . "curl_close(\$ch);\n\n"
    . "if ((int) (\$result['status'] ?? 0) !== 1) {\n"
    . "    exit('This copy is not licensed.');\n"
    . "}";

$curlExample = "curl -X POST '" . $baseUrl . "' \\\n"
    . "     -d 'key=LM-XXXXXXXXXXXX' \\\n"
    . "     -d 'domain=example.com'";
?>
<?php lm_public_page_start('docs', array('title' => 'API documentation')); ?>

<main class="lm-docs" id="main">
    <div class="lm-wrap">
        <div class="lm-pagehead">
            <div class="lm-pagehead__text">
                <span class="lm-eyebrow lm-eyebrow--light"><span class="lm-eyebrow__dot"></span> Reference</span>
                <h1 style="margin-top:14px">API documentation</h1>
                <p>Validate a licence from your own code. One endpoint, two parameters, a JSON answer —
                    no SDK and no authentication header.</p>
            </div>
            <div class="lm-pagehead__actions">
                <a class="lm-btn lm-btn--ghost" href="<?= lm_e(lm_public_url('admin/index.php')) ?>">
                    <?= lm_icon('shield') ?> Admin console
                </a>
                <a class="lm-btn" href="<?= lm_e(lm_public_url('order.php')) ?>">
                    <?= lm_icon('key') ?> Get a licence
                </a>
            </div>
        </div>

        <div class="lm-docs__layout">
            <aside class="lm-docs__toc" aria-label="On this page">
                <h2>On this page</h2>
                <nav class="lm-docs__tocnav">
                    <?php foreach ($toc as $id => $label): ?>
                        <a href="#<?= lm_e($id) ?>"><?= lm_e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
                <div class="lm-docs__tocfoot">
                    <p class="lm-help lm-mb-0">Updated <?= lm_e($today) ?> · v<?= lm_e(defined('LM_VERSION') ? LM_VERSION : '') ?></p>
                </div>
            </aside>

            <div class="lm-docs__content">
                <section class="lm-docsec" id="overview">
                    <h2>Overview</h2>
                    <p>The License Manager API validates a customer licence from your own website. Send a POST
                        request with the licence key and the domain it is running on, and receive an instant verdict.</p>
                    <div class="lm-note">
                        <?= lm_icon('shield') ?>
                        <span><strong>No authentication required.</strong> The endpoint is public by design: the key
                            itself is the credential. Call it from your server so the key is never exposed to the
                            browser.</span>
                    </div>
                </section>

                <section class="lm-docsec" id="base-url">
                    <h2>Base URL</h2>
                    <div class="lm-endpoint">
                        <span class="lm-method">POST</span>
                        <code><?= lm_e($baseUrl) ?></code>
                        <?= lm_copy_button($baseUrl, 'Copy') ?>
                    </div>
                    <p>This page detected <strong><?= lm_e($host) ?></strong> as the current host. Use the same value
                        in the <span class="lm-param">domain</span> field, or derive it at runtime
                        (<code>$_SERVER['HTTP_HOST']</code>, <code>location.hostname</code>).</p>
                    <h3>Domain changes</h3>
                    <p>The API validates against the domain currently configured on the licence. If the customer moves
                        the licence in their dashboard, the new domain is accepted immediately and the old one is
                        refused. Always send the customer's current domain to avoid mismatches.</p>
                </section>

                <section class="lm-docsec" id="endpoints">
                    <h2 id="params">Endpoints &amp; parameters</h2>
                    <div class="lm-endpoint">
                        <span class="lm-method">POST</span>
                        <code>/api.php</code>
                        <span class="lm-meta" style="margin-left:auto">Form-encoded or multipart</span>
                    </div>
                    <div class="lm-tablewrap">
                        <table class="lm-table lm-table--stack">
                            <thead>
                                <tr>
                                    <th>Parameter</th>
                                    <th>Type</th>
                                    <th>Required</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td data-label="Parameter"><span class="lm-param">key</span></td>
                                    <td data-label="Type">string</td>
                                    <td data-label="Required"><span class="lm-badge lm-badge--warn">Required</span></td>
                                    <td data-label="Description">The licence key to validate, as issued to the customer.</td>
                                </tr>
                                <tr>
                                    <td data-label="Parameter"><span class="lm-param">domain</span></td>
                                    <td data-label="Type">string</td>
                                    <td data-label="Required"><span class="lm-badge lm-badge--warn">Required</span></td>
                                    <td data-label="Description">The domain the script is running on. No scheme, no path.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="lm-docsec" id="responses">
                    <h2>Responses</h2>
                    <p>Every answer is JSON with a numeric <code>status</code> and a human-readable
                        <code>message</code>.</p>

                    <h3>Valid licence</h3>
                    <div class="lm-codeblock">
                        <div class="lm-codeblock__bar">
                            <span>200 · valid</span>
                            <?= lm_copy_button("{\n  \"status\": 1,\n  \"message\": \"License is valid.\"\n}", 'Copy', false) ?>
                        </div>
                        <pre><code>{
  "status": <span class="tok-num">1</span>,
  "message": <span class="tok-str">"License is valid."</span>
}</code></pre>
                    </div>

                    <h3>Refused</h3>
                    <div class="lm-codeblock">
                        <div class="lm-codeblock__bar">
                            <span>200 · refused</span>
                            <?= lm_copy_button("{\n  \"status\": 0,\n  \"message\": \"Invalid license key or domain.\"\n}", 'Copy', false) ?>
                        </div>
                        <pre><code>{
  "status": <span class="tok-num">0</span>,
  "message": <span class="tok-str">"Invalid license key or domain."</span>
}</code></pre>
                    </div>

                    <h3>Status codes</h3>
                    <div class="lm-tablewrap">
                        <table class="lm-table lm-table--stack">
                            <thead>
                                <tr>
                                    <th>status</th>
                                    <th>Meaning</th>
                                    <th>What to do</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td data-label="status"><span class="lm-badge lm-badge--ok">1</span></td>
                                    <td data-label="Meaning">The key is valid, active and the domain matches.</td>
                                    <td data-label="Action">Continue running.</td>
                                </tr>
                                <tr>
                                    <td data-label="status"><span class="lm-badge lm-badge--bad">0</span></td>
                                    <td data-label="Meaning">Wrong key, wrong domain, inactive or expired.</td>
                                    <td data-label="Action">Stop, and show the customer the message.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="lm-docsec" id="errors">
                    <h2>Error handling</h2>
                    <p>The single <code>status: 0</code> covers every refusal, so a licence being invalid must never be
                        treated as a temporary failure — but a network timeout must. Wrap the call:</p>
                    <ul>
                        <li><strong>Timeout or transport failure</strong> — retry with backoff, then fall back to a cached
                            verdict for a short window rather than locking a paying customer out.</li>
                        <li><strong><code>status: 0</code></strong> — a decision, not an error. Do not retry it; show the
                            customer the message and a link to their dashboard.</li>
                        <li><strong>Unexpected HTML instead of JSON</strong> — usually a WAF or a wrong URL. Log the first
                            line of the body; it names the layer that refused.</li>
                    </ul>
                </section>

                <section class="lm-docsec" id="examples">
                    <h2>Code examples</h2>
                    <div class="lm-tabs" role="tablist" aria-label="Language">
                        <button type="button" role="tab" id="tab-js" aria-controls="panel-js" aria-selected="true" tabindex="0">JavaScript</button>
                        <button type="button" role="tab" id="tab-php" aria-controls="panel-php" aria-selected="false" tabindex="-1">PHP</button>
                        <button type="button" role="tab" id="tab-curl" aria-controls="panel-curl" aria-selected="false" tabindex="-1">cURL</button>
                    </div>

                    <div class="lm-tabpanel" id="panel-js" role="tabpanel" aria-labelledby="tab-js">
                        <div class="lm-codeblock">
                            <div class="lm-codeblock__bar">
                                <span>JavaScript</span>
                                <?= lm_copy_button($jsExample, 'Copy', false) ?>
                            </div>
                            <pre><code><?= lm_e($jsExample) ?></code></pre>
                        </div>
                    </div>

                    <div class="lm-tabpanel" id="panel-php" role="tabpanel" aria-labelledby="tab-php" hidden>
                        <div class="lm-codeblock">
                            <div class="lm-codeblock__bar">
                                <span>PHP</span>
                                <?= lm_copy_button($phpExample, 'Copy', false) ?>
                            </div>
                            <pre><code><?= lm_e($phpExample) ?></code></pre>
                        </div>
                    </div>

                    <div class="lm-tabpanel" id="panel-curl" role="tabpanel" aria-labelledby="tab-curl" hidden>
                        <div class="lm-codeblock">
                            <div class="lm-codeblock__bar">
                                <span>cURL</span>
                                <?= lm_copy_button($curlExample, 'Copy', false) ?>
                            </div>
                            <pre><code><?= lm_e($curlExample) ?></code></pre>
                        </div>
                    </div>
                </section>

                <section class="lm-docsec" id="patterns">
                    <h2>Integration patterns</h2>

                    <h3>Verify once, on page load</h3>
                    <p>Good for a small script that boots a single page. Cache the verdict for the session so a page
                        refresh does not hammer the endpoint.</p>

                    <h3>Cache server-side</h3>
                    <p>Store the last good verdict per domain and re-check every few hours. Combine that with a short
                        timeout so a slow licence server never stalls the customer's page.</p>

                    <h3>Send the real hostname</h3>
                    <p>Behind a proxy or CDN, derive the domain from the forwarded host, not from
                        <code>localhost</code> — otherwise the licence check runs against the wrong domain.</p>
                </section>

                <section class="lm-docsec" id="best">
                    <h2>Best practices</h2>
                    <ul class="lm-checklist">
                        <li><?= lm_icon('check') ?> Keep the key server-side. A key in front-end code is a public key.</li>
                        <li><?= lm_icon('check') ?> Fail closed on a definitive <code>status: 0</code>, and report the message.</li>
                        <li><?= lm_icon('check') ?> Use a short timeout (5–8 seconds) so a slow network never blocks a page.</li>
                        <li><?= lm_icon('check') ?> Cache the verdict briefly; licences do not change second to second.</li>
                        <li><?= lm_icon('check') ?> Log the response body on unexpected answers — it names the refusing layer.</li>
                        <li><?= lm_icon('check') ?> Never let a customer "fix" a check by editing a JavaScript file: verify on the server.</li>
                    </ul>
                </section>

                <section class="lm-docsec" id="faq">
                    <h2>Frequently asked questions</h2>

                    <h3>Is the API publicly accessible?</h3>
                    <p>Yes. There is no API key to obtain — the licence key in the request is the credential, which is
                        why the call belongs on the server.</p>

                    <h3>What formats are supported?</h3>
                    <p>POST with form-encoded data; the answer is always JSON.</p>

                    <h3>Are there rate limits?</h3>
                    <p>Not on this endpoint. Cache the result anyway: one check per page load is plenty.</p>

                    <h3>Can I validate several licences at once?</h3>
                    <p>No — one licence per request. Loop, or batch on your side.</p>

                    <h3>What if the licence is inactive?</h3>
                    <p>The API answers <code>status: 0</code> with <em>"Invalid license key or domain."</em> The admin
                        console shows the real status for support.</p>

                    <h3>How do domain changes affect validation?</h3>
                    <p>The licence validates only against its current domain. Customers move it themselves from their
                        dashboard, up to the limit the administrator set.</p>

                    <h3>How do I debug a failure?</h3>
                    <p>Reproduce the request with the cURL example above. If that works and your code does not, the
                        difference is almost always the <span class="lm-param">domain</span> value.</p>
                </section>

                <div class="lm-cta-band">
                    <div>
                        <h2>Need a key first?</h2>
                        <p>Request one, and the dashboard shows the endpoint pre-filled with your licence.</p>
                    </div>
                    <div class="lm-actions">
                        <a class="lm-btn lm-btn--glow" href="<?= lm_e(lm_public_url('order.php')) ?>"><?= lm_icon('key') ?> Get a licence</a>
                        <a class="lm-btn lm-btn--light" href="<?= lm_e(lm_public_url('user/login.php')) ?>"><?= lm_icon('user') ?> Sign in</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'docs', 'no_tabbar' => true)); ?>
