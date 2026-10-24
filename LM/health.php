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
