<?php
/**
 * user/api-integration.php — per-licence integration kit.
 *
 * Linked from the dashboard and the licence details page since the licence
 * table was first written, but the file never existed: every click was a 404.
 * This is that page — the endpoint, this customer's key, and working snippets
 * for PHP, JavaScript, cURL and Python, all filled in for the selected licence.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
require_once __DIR__ . '/../includes/ui.php';

if (lm_public_user() === '') {
    lm_safe_redirect('login.php');
}

require_once __DIR__ . '/../db.php';

$user_email = lm_public_user();
$license_id = isset($_GET['license_id']) ? (int) $_GET['license_id'] : (int) ($_GET['id'] ?? 0);
if ($license_id <= 0 && isset($_SESSION['user_license_id'])) {
    $license_id = (int) $_SESSION['user_license_id'];
}

$stmt = $pdo->prepare('SELECT * FROM licenses WHERE id = ? AND customer_email = ?');
$stmt->execute(array($license_id, $user_email));
$license = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$license) {
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE customer_email = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute(array($user_email));
    $license = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$license) {
    lm_safe_redirect('index.php');
}

// Absolute endpoint, so a snippet can be pasted into any project as-is.
$apiUrl = function_exists('lm_site_url') ? lm_site_url('api.php') : lm_public_url('api.php');
if (!preg_match('#^https?://#i', $apiUrl)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'your-domain.com');
    $apiUrl = $scheme . '://' . $host . '/' . ltrim($apiUrl, '/');
}

$key = (string) $license['license_key'];
$domain = (string) ($license['domain'] ?? '');
$maskedKey = strlen($key) > 10 ? substr($key, 0, 6) . '…' . substr($key, -4) : $key;

$snippets = array(
    'php' => array(
        'label' => 'PHP',
        'code' => "<?php\n"
            . "\$payload = http_build_query([\n"
            . "    'key'    => '" . $key . "',\n"
            . "    'domain' => \$_SERVER['HTTP_HOST'],\n"
            . "]);\n\n"
            . "\$ch = curl_init('" . $apiUrl . "');\n"
            . "curl_setopt_array(\$ch, [\n"
            . "    CURLOPT_POST           => true,\n"
            . "    CURLOPT_POSTFIELDS     => \$payload,\n"
            . "    CURLOPT_RETURNTRANSFER => true,\n"
            . "    CURLOPT_TIMEOUT        => 8,\n"
            . "]);\n\n"
            . "\$result = json_decode(curl_exec(\$ch), true);\n"
            . "curl_close(\$ch);\n\n"
            . "if ((int) (\$result['status'] ?? 0) !== 1) {\n"
            . "    exit('Licence check failed: ' . (\$result['message'] ?? 'unknown'));\n"
            . "}",
    ),
    'js' => array(
        'label' => 'JavaScript',
        'code' => "// Run this on your server, or from a page that may reveal the key.\n"
            . "const res = await fetch('" . $apiUrl . "', {\n"
            . "  method: 'POST',\n"
            . "  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },\n"
            . "  body: new URLSearchParams({\n"
            . "    key: '" . $key . "',\n"
            . "    domain: location.hostname\n"
            . "  })\n"
            . "});\n\n"
            . "const data = await res.json();\n"
            . "if (data.status !== 1) {\n"
            . "  throw new Error(data.message || 'Licence invalid');\n"
            . "}",
    ),
    'curl' => array(
        'label' => 'cURL',
        'code' => "curl -s -X POST '" . $apiUrl . "' \\\n"
            . "  -d 'key=" . $key . "' \\\n"
            . "  -d 'domain=" . ($domain !== '' ? $domain : 'example.com') . "'\n\n"
            . "# → {\"status\":1,\"message\":\"License is valid.\"}",
    ),
    'python' => array(
        'label' => 'Python',
        'code' => "import requests\n\n"
            . "response = requests.post(\n"
            . "    '" . $apiUrl . "',\n"
            . "    data={'key': '" . $key . "', 'domain': '" . ($domain !== '' ? $domain : 'example.com') . "'},\n"
            . "    timeout=8,\n"
            . ")\n"
            . "data = response.json()\n\n"
            . "if data.get('status') != 1:\n"
            . "    raise SystemExit(data.get('message', 'Licence invalid'))",
    ),
);

$status = strtolower((string) ($license['status'] ?? ''));
?>
<?php lm_public_page_start('account', array('title' => 'API integration', 'account' => true)); ?>

<main class="lm-page" id="main">
    <div class="lm-wrap">
        <a class="lm-backlink" href="index.php"><?= lm_icon('arrow-left') ?> Back to my licences</a>

        <div class="lm-pagehead">
            <div class="lm-pagehead__text">
                <h1>API integration</h1>
                <p>Everything needed to verify <code><?= lm_e($maskedKey) ?></code> from your script — endpoint,
                    credentials and copy-paste snippets.</p>
            </div>
            <div class="lm-pagehead__actions">
                <a class="lm-btn lm-btn--ghost" href="license-details.php?id=<?= (int) $license['id'] ?>">
                    <?= lm_icon('receipt') ?> Licence details
                </a>
                <a class="lm-btn" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                    <?= lm_icon('book') ?> Full reference
                </a>
            </div>
        </div>

        <div class="lm-note">
            <?= lm_icon('shield') ?>
            <span><strong>This key is a secret.</strong> Send the request from your server, not from the page the
                buyer sees. A key pasted into front-end JavaScript can be copied by anyone who views the source.</span>
        </div>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('plug') ?> Credentials for this licence</h2>
                    <p>Three values, and nothing else is required.</p>
                </div>
                <span class="lm-badge <?= $status === 'active' ? 'lm-badge--ok' : 'lm-badge--bad' ?>">
                    <?= lm_e(ucfirst($status !== '' ? $status : 'unknown')) ?>
                </span>
            </div>
            <div class="lm-panel__body">
                <div class="lm-field" style="margin-bottom:16px">
                    <span class="lm-label">Endpoint (POST)</span>
                    <div class="lm-keyrow">
                        <code class="lm-key"><?= lm_e($apiUrl) ?></code>
                        <?= lm_copy_button($apiUrl, 'Copy') ?>
                    </div>
                </div>

                <div class="lm-field" style="margin-bottom:16px">
                    <span class="lm-label">Licence key</span>
                    <div class="lm-keyrow">
                        <code class="lm-key"><?= lm_e($key) ?></code>
                        <?= lm_copy_button($key, 'Copy') ?>
                    </div>
                </div>

                <div class="lm-field">
                    <span class="lm-label">Licensed domain</span>
                    <div class="lm-keyrow">
                        <code class="lm-key"><?= lm_e($domain !== '' ? $domain : 'not set') ?></code>
                        <?php if ($domain !== ''): ?>
                            <?= lm_copy_button($domain, 'Copy') ?>
                        <?php endif; ?>
                    </div>
                    <p class="lm-help">Send the domain the script is running on. A mismatch is refused — you can move
                        the domain from <a href="change-domain.php?id=<?= (int) $license['id'] ?>">this page</a>.</p>
                </div>
            </div>
        </section>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('code') ?> Ready-to-paste snippets</h2>
                    <p>Already filled in with the key above. Pick the language your script is written in.</p>
                </div>
            </div>
            <div class="lm-panel__body">
                <div class="lm-tabs" role="tablist" aria-label="Code language">
                    <?php $first = true; ?>
                    <?php foreach ($snippets as $id => $snippet): ?>
                        <button type="button" role="tab" id="tab-<?= lm_e($id) ?>"
                                aria-controls="panel-<?= lm_e($id) ?>"
                                aria-selected="<?= $first ? 'true' : 'false' ?>"
                                tabindex="<?= $first ? '0' : '-1' ?>"><?= lm_e($snippet['label']) ?></button>
                        <?php $first = false; ?>
                    <?php endforeach; ?>
                </div>

                <?php $first = true; ?>
                <?php foreach ($snippets as $id => $snippet): ?>
                    <div class="lm-tabpanel" id="panel-<?= lm_e($id) ?>" role="tabpanel"
                         aria-labelledby="tab-<?= lm_e($id) ?>"<?= $first ? '' : ' hidden' ?>>
                        <div class="lm-codeblock">
                            <div class="lm-codeblock__bar">
                                <span><?= lm_e($snippet['label']) ?></span>
                                <?= lm_copy_button($snippet['code'], 'Copy', false) ?>
                            </div>
                            <pre><code><?= lm_e($snippet['code']) ?></code></pre>
                        </div>
                    </div>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="lm-panel">
            <div class="lm-panel__head">
                <div>
                    <h2><?= lm_icon('terminal') ?> Reading the response</h2>
                    <p>The API answers with JSON and a numeric status.</p>
                </div>
            </div>
            <div class="lm-panel__body">
                <div class="lm-tablewrap">
                    <table class="lm-table">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Meaning</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>status: 1</code></td>
                                <td>Key is valid, active and the domain matches.</td>
                            </tr>
                            <tr>
                                <td><code>status: 0</code></td>
                                <td>Refused. The <code>message</code> field says why — wrong key, wrong domain,
                                    inactive or expired.</td>
                            </tr>
                            <tr>
                                <td><code>message</code></td>
                                <td>Human-readable explanation, safe to log. Do not show it to end users verbatim.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="lm-item__actions">
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="<?= lm_e(lm_public_url('api-docs.php#errors')) ?>">
                        <?= lm_icon('alert') ?> Error codes
                    </a>
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="<?= lm_e(lm_public_url('api-docs.php#examples')) ?>">
                        <?= lm_icon('external') ?> More examples
                    </a>
                    <a class="lm-btn lm-btn--sm lm-btn--ghost" href="change-domain.php?id=<?= (int) $license['id'] ?>">
                        <?= lm_icon('globe') ?> Change domain
                    </a>
                </div>
            </div>
        </section>
    </div>
</main>

<?php lm_public_page_end(array('active' => 'account', 'no_tabbar' => true)); ?>
