<?php
/**
 * health.php — a dependency-free self check.
 *
 * Opens with no includes at all, so it works even when a deploy is broken and
 * every other page returns 500. It reports what a support request needs:
 * the PHP version, which files are missing, whether the runtime folders are
 * writable, and the last fatal error PHP recorded.
 *
 * It exposes file names and a stack-free error line, never a password, key or
 * token. Delete it once the installation is healthy.
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = __DIR__;

/* ------------------------------------------------------------------ checks */

$required = array(
    'index.php'                     => 'Landing page',
    'health.php'                    => 'This page',
    'admin/ping.php'                => 'Request self-test (9.0.3)',
    'includes/security.php'         => 'Sessions, CSRF, throttles',
    'includes/lm_config.php'        => 'Configuration and helpers',
    'includes/ui.php'               => 'Public header, footer and mobile menu (9.0.0)',
    'includes/updates.php'          => 'Publishing and cleanup',
    'includes/notifications.php'    => 'Update emails',
    'includes/mailer.php'           => 'SMTP transport',
    'includes/migrations.php'       => 'Schema migrations',
    'assets/site.css'               => 'Public stylesheet (9.0.0)',
    'assets/site.js'                => 'Menu and drawer behaviour (9.0.0)',
    'admin/_mobile_nav.php'         => 'Console mobile menu (9.0.0)',
    'admin/_ui.php'                 => 'Console chrome',
    'user/login.php'                => 'Customer sign-in',
    'admin/index.php'               => 'Admin sign-in',
    'db.php'                        => 'Database bootstrap',
    '.htaccess'                     => 'Server rules',
);

$missing = array();
foreach ($required as $path => $label) {
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
        $missing[$path] = $label;
    }
}

$storage   = $root . DIRECTORY_SEPARATOR . 'storage';
$logs      = $storage . DIRECTORY_SEPARATOR . 'logs';
$settings  = $root . DIRECTORY_SEPARATOR . 'settings.json';
$writable  = array(
    'storage/'          => is_dir($storage) && is_writable($storage),
    'storage/logs/'     => is_dir($logs) && is_writable($logs),
    'LM folder'         => is_writable($root),
);

$lastFatal = '';
$fatalFile = $logs . DIRECTORY_SEPARATOR . 'php.log';
if (is_file($fatalFile) && is_readable($fatalFile)) {
    $lines = @file($fatalFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines) && !empty($lines)) {
        $lastFatal = (string) end($lines);
        // Never print an absolute path: the document root is the one thing a
        // stranger should not learn from a public page.
        $lastFatal = str_replace($root, '[LM]', $lastFatal);
        $lastFatal = str_replace(dirname($root), '[site]', $lastFatal);
    }
}

$php = PHP_VERSION;
$phpOk = version_compare($php, '7.4.0', '>=');

/* Which release is actually on disk. */
$version = 'unknown';
$configFile = $root . '/includes/lm_config.php';
if (is_file($configFile) && is_readable($configFile)) {
    $head = (string) @file_get_contents($configFile, false, null, 0, 2048);
    if (preg_match("/define\('LM_VERSION',\s*'([^']+)'\)/", $head, $m)) {
        $version = $m[1];
    }
}

/*
 * Release manifest. build.json lists every file in the release with its SHA-1,
 * so a partial upload (one stale folder next to fresh files) is visible here
 * instead of surfacing later as an unexplained 403.
 */
$manifestFile = $root . '/build.json';
$manifest = null;
if (is_file($manifestFile)) {
    $decoded = json_decode((string) @file_get_contents($manifestFile), true);
    if (is_array($decoded) && !empty($decoded['files']) && is_array($decoded['files'])) {
        $manifest = $decoded;
    }
}

$stale = array();
$absent = array();
$staleChecked = 0;
if ($manifest !== null) {
    foreach ($manifest['files'] as $path => $hash) {
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($full)) {
            // Never uploaded at all. Already named above when it is required,
            // but a page nobody linked to yet would otherwise stay invisible.
            if (!isset($missing[$path])) {
                $absent[] = $path;
            }
            continue;
        }
        $staleChecked++;
        $actual = @sha1_file($full);
        /* local edits are legitimate: only flag a file the release would break */
        if ($actual !== false && !hash_equals((string) $hash, $actual)) {
            $stale[] = $path;
        }
    }
}

/*
 * Files that still open the legacy session. 8.9.0 renamed the cookie, so any
 * script calling session_start() directly sees an empty session and answers
 * "403 Forbidden" to an admin who is signed in.
 */
$legacySession = array();
$sessionScan = array();
if ($manifest !== null) {
    /* Only files this release ships; a helper left behind by an old ZIP or a
       deployment script of your own is not ours to judge. */
    foreach (array_keys($manifest['files']) as $path) {
        if (substr($path, -4) === '.php') {
            $sessionScan[] = $path;
        }
    }
} else {
    foreach (array('', 'admin/', 'user/') as $dir) {
        $full = $root . DIRECTORY_SEPARATOR . rtrim($dir, '/');
        if (!is_dir($full)) {
            continue;
        }
        foreach ((array) @scandir($full) as $entry) {
            if ($entry === '.' || $entry === '..' || substr($entry, -4) !== '.php') {
                continue;
            }
            $sessionScan[] = $dir . $entry;
        }
    }
}
foreach ($sessionScan as $path) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($file)) {
        continue;
    }
    $src = (string) @file_get_contents($file, false, null, 0, 8192);
    // Only a statement that starts a line counts; a mention inside a string
    // or in the instructions below does not open a session.
    if (preg_match('/^[ \t]*@?session_start[ \t]*\(/im', $src)) {
        $legacySession[] = $path;
    }
}

$problems = array();
if (!$phpOk) {
    $problems[] = 'PHP ' . $php . ' is too old. License Manager needs 7.4 or newer.';
}
foreach ($missing as $path => $label) {
    $problems[] = 'Missing file: ' . $path . ' — ' . $label . '. Upload it from the release ZIP.';
}
foreach ($writable as $label => $ok) {
    if (!$ok) {
        $problems[] = $label . ' is not writable by PHP (0755 on folders, 0644 on files).';
    }
}
if (!empty($stale)) {
    $problems[] = count($stale) . ' file(s) are from an older release than build.json '
        . '(listed below). Re-upload those files from the ' . $version . ' ZIP — a mixed folder '
        . 'is what makes an admin who is signed in get 403 Forbidden.';
}
if (!empty($absent)) {
    $problems[] = count($absent) . ' file(s) from this release are not on the server at all: '
        . implode(', ', array_slice($absent, 0, 8)) . (count($absent) > 8 ? ', …' : '')
        . '. Upload the complete folder, not just the files you changed.';
}
if (!empty($legacySession)) {
    $problems[] = count($legacySession) . ' script(s) still call session_start() instead of '
        . 'lm_session_start(), so they see an empty session: ' . implode(', ', array_slice($legacySession, 0, 8))
        . (count($legacySession) > 8 ? ', …' : '') . '. Re-upload the admin/ and user/ folders.';
}
if (!is_file($settings)) {
    $problems[] = 'settings.json is missing. Upload the copy from your backup, or open Settings once the panel loads.';
}
if (is_file($settings) && !is_readable($settings)) {
    $problems[] = 'settings.json exists but PHP cannot read it (permissions should be 0600–0644).';
}

$ok = empty($problems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation check — License Manager</title>
    <style>
        :root { --ink:#0b1220; --muted:#5f6b83; --line:#e4e9f2; --ok:#059669; --bad:#b91c1c; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
               background:#f6f8fc; color:var(--ink); line-height:1.65; }
        .wrap { max-width:820px; margin:0 auto; padding:40px 20px 70px; }
        h1 { font-size:26px; letter-spacing:-.03em; margin:0 0 6px; }
        .sub { color:var(--muted); margin:0 0 26px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:22px 24px;
                box-shadow:0 16px 40px -34px rgba(11,18,32,.5); margin-bottom:18px; }
        .card h2 { font-size:13px; letter-spacing:.12em; text-transform:uppercase; color:var(--muted); margin:0 0 14px; }
        .row { display:flex; justify-content:space-between; gap:16px; padding:7px 0; border-bottom:1px dashed #eef2f7; font-size:14.5px; }
        .row:last-child { border-bottom:0; }
        .row code { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; }
        .yes { color:var(--ok); font-weight:700; }
        .no  { color:var(--bad); font-weight:700; }
        .banner { border-radius:16px; padding:18px 22px; margin-bottom:18px; font-size:15px; }
        .banner.ok { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
        .banner.bad { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .banner ul { margin:10px 0 0; padding-left:20px; }
        .banner li { margin-bottom:6px; }
        pre { margin:0; white-space:pre-wrap; word-break:break-word; font-size:12.5px; background:#0d1424;
              color:#cbd5e1; padding:14px 16px; border-radius:12px; overflow-x:auto; }
        .hint { color:var(--muted); font-size:13.5px; margin:12px 0 0; }
        a { color:#4338ca; }
        .net button { font:inherit; font-weight:700; font-size:14px; padding:10px 18px; border-radius:10px;
                      border:1px solid #c7d2fe; background:#eef2ff; color:#3730a3; cursor:pointer; }
        .net button[disabled] { opacity:.55; cursor:progress; }
        .net ol { margin:14px 0 0; padding-left:20px; font-size:13.5px; }
        .net li { margin-bottom:6px; }
        .net .ok { color:var(--ok); font-weight:700; }
        .net .bad { color:var(--bad); font-weight:700; }
        .net .verdict { margin-top:14px; padding:13px 15px; border-radius:12px; background:#fffbeb;
                        border:1px solid #fde68a; color:#92400e; font-size:14px; display:none; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Installation check</h1>
    <p class="sub">What the server sees right now. Nothing here is a secret — file names, permissions and the
        last fatal error.</p>

    <div class="banner <?= $ok ? 'ok' : 'bad' ?>">
        <strong><?= $ok ? 'Everything the panel needs is in place.' : count($problems) . ' problem(s) found.' ?></strong>
        <?php if (!$ok): ?>
            <ul>
                <?php foreach ($problems as $problem): ?>
                    <li><?= htmlspecialchars($problem) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Release</h2>
        <div class="row"><span>Installed version</span><span class="<?= $version === 'unknown' ? 'no' : 'yes' ?>"><?= htmlspecialchars($version) ?></span></div>
        <div class="row">
            <span>Release manifest (build.json)</span>
            <span class="<?= $manifest === null ? 'no' : 'yes' ?>">
                <?= $manifest === null ? 'not found' : htmlspecialchars((string) ($manifest['version'] ?? '')) . ' · ' . count($manifest['files']) . ' files' ?>
            </span>
        </div>
        <div class="row">
            <span>Files matching the manifest</span>
            <span class="<?= (empty($stale) && empty($absent)) ? 'yes' : 'no' ?>">
                <?= (empty($stale) && empty($absent)) ? 'all ' . $staleChecked . ' match' : count($stale) . ' differ, ' . count($absent) . ' absent, ' . $staleChecked . ' checked' ?>
            </span>
        </div>
        <div class="row">
            <span>Legacy session_start() scripts</span>
            <span class="<?= empty($legacySession) ? 'yes' : 'no' ?>"><?= empty($legacySession) ? 'none' : count($legacySession) ?></span>
        </div>
        <?php if (!empty($absent)): ?>
            <p class="hint" style="margin-top:12px">In build.json but missing on the server:</p>
            <pre><?= htmlspecialchars(implode("\n", array_slice($absent, 0, 40)) . (count($absent) > 40 ? "\n… " . (count($absent) - 40) . ' more' : '')) ?></pre>
        <?php endif; ?>
        <?php if (!empty($stale)): ?>
            <p class="hint" style="margin-top:12px">From an older release — re-upload these:</p>
            <pre><?= htmlspecialchars(implode("\n", array_slice($stale, 0, 40)) . (count($stale) > 40 ? "\n… " . (count($stale) - 40) . ' more' : '')) ?></pre>
        <?php endif; ?>
        <?php if (!empty($legacySession)): ?>
            <p class="hint" style="margin-top:12px">Still opening the old session (this is what answers 403 to a signed-in admin):</p>
            <pre><?= htmlspecialchars(implode("\n", $legacySession)) ?></pre>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Runtime</h2>
        <div class="row"><span>PHP version</span><span class="<?= $phpOk ? 'yes' : 'no' ?>"><?= htmlspecialchars($php) ?></span></div>
        <div class="row"><span>Interface</span><span><?= htmlspecialchars(PHP_SAPI) ?></span></div>
        <div class="row"><span>ZIP support (zlib)</span><span class="<?= function_exists('gzinflate') ? 'yes' : 'no' ?>"><?= function_exists('gzinflate') ? 'available' : 'missing' ?></span></div>
        <div class="row"><span>cURL</span><span class="<?= function_exists('curl_init') ? 'yes' : 'no' ?>"><?= function_exists('curl_init') ? 'available' : 'missing' ?></span></div>
        <div class="row"><span>Multibyte strings</span><span class="<?= function_exists('mb_strlen') ? 'yes' : 'no' ?>"><?= function_exists('mb_strlen') ? 'available' : 'missing' ?></span></div>
        <div class="row"><span>Server</span><span><?= htmlspecialchars((string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown')) ?></span></div>
    </div>

    <div class="card">
        <h2>Files</h2>
        <?php foreach ($required as $path => $label): ?>
            <div class="row">
                <span><code><?= htmlspecialchars($path) ?></code><br><span class="hint"><?= htmlspecialchars($label) ?></span></span>
                <span class="<?= isset($missing[$path]) ? 'no' : 'yes' ?>"><?= isset($missing[$path]) ? 'missing' : 'ok' ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h2>Folders and settings</h2>
        <?php foreach ($writable as $label => $isWritable): ?>
            <div class="row">
                <span><?= htmlspecialchars($label) ?></span>
                <span class="<?= $isWritable ? 'yes' : 'no' ?>"><?= $isWritable ? 'writable' : 'not writable' ?></span>
            </div>
        <?php endforeach; ?>
        <div class="row">
            <span>settings.json</span>
            <span class="<?= (is_file($settings) && is_readable($settings)) ? 'yes' : 'no' ?>">
                <?= is_file($settings) ? (is_readable($settings) ? 'present' : 'unreadable') : 'missing' ?>
            </span>
        </div>
    </div>

    <div class="card net">
        <h2>Server rules (browser test)</h2>
        <p class="hint" style="margin-top:0">
            A bare <strong>403 Forbidden</strong> can be sent by the web server before PHP ever runs —
            <code>.htaccess</code>, mod_security, or a proxy. This page cannot see that from PHP, so this
            test asks the browser to make the same requests the console makes and reports where each one stops.
            It sends no data and needs no sign-in.
        </p>
        <p style="margin:14px 0 0"><button type="button" id="lm-net-run">Run network self-test</button></p>
        <ol id="lm-net-out" hidden></ol>
        <div class="verdict" id="lm-net-verdict"></div>
        <p class="hint">
            If the test says the server refuses everything in <code>admin/</code>, rename
            <code>.htaccess</code> to <code>.htaccess.off</code> in the License Manager folder, reload this page,
            and run the test again. That separates the rules file from a host-level rule.
        </p>
        <script>
        (function () {
            var run = document.getElementById('lm-net-run');
            var out = document.getElementById('lm-net-out');
            var verdict = document.getElementById('lm-net-verdict');
            if (!run) return;

            function json(text) { try { return JSON.parse(text); } catch (e) { return null; } }

            function step(label, promise) {
                return promise.then(function (response) {
                    return response.text().then(function (text) {
                        var parsed = json(text);
                        return { label: label, status: response.status, json: parsed, text: text };
                    });
                }).catch(function (error) {
                    return { label: label, status: 0, json: null, text: error.message };
                });
            }

            function line(result) {
                var li = document.createElement('li');
                var good = result.status >= 200 && result.status < 300 && result.json;
                li.innerHTML = '<span class="' + (good ? 'ok' : 'bad') + '">'
                    + (result.status === 0 ? 'blocked' : 'HTTP ' + result.status) + '</span> — '
                    + result.label
                    + (result.json ? ' <span class="hint" style="display:inline;margin:0">(PHP answered)</span>' : '');
                return li;
            }

            run.addEventListener('click', function () {
                run.disabled = true;
                out.hidden = false;
                out.innerHTML = '';
                verdict.style.display = 'none';

                var big = new FormData();
                big.append('filler', new Array(64 * 1024).join('x'));

                var checks = [
                    ['GET admin/ping.php', 'admin/ping.php', { method: 'GET' }],
                    ['POST admin/ping.php (empty body)', 'admin/ping.php', { method: 'POST', body: new FormData() }],
                    ['POST admin/ping.php (64 KB body)', 'admin/ping.php', { method: 'POST', body: big }],
                    ['POST admin/ajax_github.php (action=ping)', 'admin/ajax_github.php', { method: 'POST', body: (function () {
                        var fd = new FormData(); fd.append('action', 'ping'); return fd;
                    })() }]
                ];

                var results = [];
                var chain = Promise.resolve();
                checks.forEach(function (check) {
                    chain = chain.then(function () {
                        return step(check[0], fetch(check[1], Object.assign({
                            credentials: 'same-origin', cache: 'no-store'
                        }, check[2]))).then(function (result) {
                            results.push(result);
                            out.appendChild(line(result));
                        });
                    });
                });

                chain.then(function () {
                    var get = results[0];
                    var post = results[1];
                    var large = results[2];
                    var endpoint = results[3];
                    var text = '';

                    if (!get.json) {
                        if (get.status === 404) {
                            text = 'admin/ping.php is not on the server — upload this release\'s admin/ folder.';
                        } else {
                            text = 'The web server refuses files in admin/ before PHP runs. That is .htaccess or a host rule. '
                                + 'Rename .htaccess to .htaccess.off in the License Manager folder and run the test again. '
                                + 'If it passes then, ask the host which rule in the old file they reject.';
                        }
                    } else if (!post.json) {
                        text = 'PHP answers a GET but the server blocks POST requests. This is a mod_security rule on the host: '
                            + 'ask them to whitelist the admin folder, or set the account to DetectionOnly in cPanel → ModSecurity.';
                    } else if (!large.json) {
                        text = 'Small POSTs arrive, a 64 KB body does not. The host caps the request body — tick fewer folders, '
                            + 'or ask for a higher SecRequestBodyLimit.';
                    } else if (!endpoint.json) {
                        text = 'Other files in admin/ answer but ajax_github.php is refused: a rule is matching that file name. '
                            + 'Ask the host to whitelist it.';
                    } else if (endpoint.json.status !== 'success' && endpoint.json.code) {
                        text = 'PHP answered ajax_github.php but refused the request (code ' + endpoint.json.code + '): '
                            + (endpoint.json.message || '') + ' — this is the panel, not the web server.';
                    } else {
                        text = 'Every request reached PHP. The web server is not refusing POSTs, so a 403 in the console is '
                            + 'coming from the panel itself — check the Release card above, then the PHP error log.';
                    }

                    verdict.textContent = text;
                    verdict.style.display = 'block';
                    run.disabled = false;
                });
            });
        })();
        </script>
    </div>

    <div class="card">
        <h2>Last fatal error</h2>
        <?php if ($lastFatal === ''): ?>
            <p class="hint" style="margin:0">Nothing recorded in <code>storage/logs/php.log</code>.</p>
        <?php else: ?>
            <pre><?= htmlspecialchars($lastFatal) ?></pre>
            <p class="hint">The full history is in <code>storage/logs/php.log</code>.</p>
        <?php endif; ?>
    </div>

    <p class="hint">
        If every page returns 500 but this one loads, the fault is in the page it happened on, not in the
        server. If <em>this</em> page also returns 500, rename <code>.htaccess</code> to
        <code>.htaccess.off</code> in the License Manager folder and reload: some hosts reject directives
        they do not implement. <a href="index.php">Back to the site</a>.
    </p>
</div>
</body>
</html>
