<?php
/**
 * health.php — a dependency-free self check.
 *
 * Opens with no includes at all, so it works even when a deploy is broken and
 * every other page returns 500. It reports what a support request needs:
 * the PHP version, which files are missing, whether the runtime folders are
 * writable, the last fatal error PHP recorded, and whether any file leaks PHP
 * source into the page (a misplaced `?>` — see lm_health_stray_html()).
 *
 * It exposes file names and a stack-free error line, never a password, key or
 * token. Delete it once the installation is healthy.
 */

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = __DIR__;

/*
 * Line numbers where a file leaves PHP mode and prints code as text.
 *
 * The source is walked once, tracking PHP mode and skipping strings and
 * comments, to produce a copy of the file with every PHP region blanked out
 * (newlines kept, so line numbers still match). What is left is page output.
 * A line of that output which is nothing but PHP syntax means the file forgot
 * to reopen with `<?php`.
 *
 * Blocks that legitimately print code are masked first — <script>, <style>,
 * <pre>, <code>, <textarea> — because JavaScript braces and the operator
 * manual's PHP samples look exactly like a leak otherwise.
 *
 * The signatures are the shapes a forgotten reopen actually takes: braces,
 * guard blocks, method signatures, docblocks, require lines. Prose and markup
 * match none of them, and `<?= ?>` stays valid throughout.
 *
 * @param string $src Whole file.
 * @return array<int,string> Line number => trimmed text, empty when clean.
 */
if (!function_exists('lm_health_stray_html')) {
function lm_health_stray_html($src)
{
    $length = strlen($src);
    $masked = '';
    $state = 'html'; // html | php | sq | dq | line | block
    $i = 0;

    while ($i < $length) {
        $char = $src[$i];
        $next = ($i + 1 < $length) ? $src[$i + 1] : '';

        if ($state === 'html') {
            if ($char === '<' && $next === '?') {
                // `<?php` opens code; `<?=` opens a short echo. Both are code,
                // so nothing is written to the output copy.
                $openTag = substr($src, $i, 5);
                $isPhp = (strtolower(substr($openTag, 0, 5)) === '<' . '?php');
                $isEcho = (substr($openTag, 0, 3) === '<' . '?=');
                if ($isPhp || $isEcho) {
                    $state = 'php';
                    $i += $isPhp ? 5 : 3;
                    continue;
                }
            }
            $masked .= $char;
            $i++;
            continue;
        }

        // Inside PHP: look for the tag that closes it, ignoring strings and
        // comments (a closing tag inside an echo or a docblock is not a mode
        // switch).
        if ($state === 'php') {
            if ($char === '?' && $next === '>') {
                $state = 'html';
                $i += 2;
                continue;
            }
            if ($char === "'") {
                $state = 'sq';
            } elseif ($char === '"') {
                $state = 'dq';
            } elseif ($char === '#' || ($char === '/' && $next === '/')) {
                $state = 'line';
            } elseif ($char === '/' && $next === '*') {
                $state = 'block';
                $i++;
            }
        } elseif ($state === 'sq') {
            if ($char === '\\') {
                $i++;
            } elseif ($char === "'") {
                $state = 'php';
            }
        } elseif ($state === 'dq') {
            if ($char === '\\') {
                $i++;
            } elseif ($char === '"') {
                $state = 'php';
            }
        } elseif ($state === 'line') {
            if ($char === "\n") {
                $state = 'php';
            }
        } elseif ($state === 'block') {
            if ($char === '*' && $next === '/') {
                $state = 'php';
                $i++;
            }
        }

        if ($char === "\n") {
            $masked .= "\n"; // keep line numbers true
        }
        $i++;
    }

    // Output that legitimately contains code: scripts, styles and samples.
    $output = preg_replace(
        '#<(script|style|pre|code|textarea)\b[^>]*>.*?</\1\s*>#is',
        '',
        $masked
    );
    if (!is_string($output)) {
        $output = $masked;
    }

    $strays = array();
    $inHtmlComment = false;
    foreach (preg_split('/\r\n|\r|\n/', $output) as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if ($inHtmlComment) {
            if (strpos($trimmed, '-->') !== false) {
                $inHtmlComment = false;
            }
            continue;
        }
        if (substr($trimmed, 0, 4) === '<!--') {
            $inHtmlComment = strpos($trimmed, '-->') === false;
            continue;
        }
        if (lm_health_looks_like_php($trimmed)) {
            $strays[$index + 1] = $trimmed;
        }
    }

    return $strays;
}
}

/**
 * Is this line PHP source rather than page markup?
 *
 * Kept narrow on purpose: telling an operator to re-upload a file that is
 * fine is worse than staying quiet about a rare shape.
 *
 * @param string $line Trimmed line.
 * @return bool
 */
if (!function_exists('lm_health_looks_like_php')) {
function lm_health_looks_like_php($line)
{
    if (preg_match('/^(?:public|private|protected)\s+(?:static\s+)?function\s+\w+/', $line)) {
        return true;
    }
    if (preg_match('/^(?:abstract\s+|final\s+)?function\s+\w+\s*\(/', $line)) {
        return true;
    }
    if (preg_match('/^if\s*\(\s*!?\s*function_exists\s*\(/', $line)) {
        return true;
    }
    if (preg_match('/^(?:require|include)(?:_once)?\s*[\(\'"]/', $line)) {
        return true;
    }
    if (preg_match('/^\*+\s*@(?:param|return|package|throws|var)\b/', $line)) {
        return true;
    }
    if (preg_match('/^\}\s*(?:else|elseif|catch|finally)\b/', $line)) {
        return true;
    }
    // A bare brace or pair of braces is the classic tail of a swallowed
    // function body — and never appears as page text on its own line.
    $bare = str_replace(array(' ', "\t"), '', $line);
    if ($bare === '}' || $bare === '{' || $bare === '}}') {
        return true;
    }

    return false;
}
}

/* ------------------------------------------------------------------ checks */

$required = array(
    'index.php'                     => 'Landing page',
    'health.php'                    => 'This page',
    'admin/ping.php'                => 'Request self-test (9.0.3)',
    'includes/security.php'         => 'Sessions, CSRF, throttles',
    'includes/lm_config.php'        => 'Configuration and helpers',
    'includes/ui.php'               => 'Public header, footer and mobile menu (9.7.0)',
    'includes/updates.php'          => 'Publishing and cleanup',
    'includes/notifications.php'    => 'Update emails',
    'includes/mailer.php'           => 'SMTP transport',
    'includes/migrations.php'       => 'Schema migrations',
    'assets/site.css'               => 'Public stylesheet (9.0.0)',
    'assets/site.js'                => 'Menu and drawer behaviour (9.0.0)',
    'includes/app_ui.php'           => 'Console chrome and navigation (9.7.0)',
    'includes/guide.php'            => 'Admin guide content',
    'assets/admin.css'              => 'Console stylesheet (9.7.0)',
    'assets/app.js'                 => 'Console behaviour and AJAX helper (9.7.0)',
    'user/index.php'                => 'Customer dashboard',
    'user/api-integration.php'      => 'Per-licence integration kit (9.7.0)',
    'api-docs.php'                  => 'Public API reference',
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
$phpTested = version_compare($php, '8.5.99', '<=');

/*
 * Session storage, checked without loading includes/security.php — this page
 * has to work when that file is the broken one.
 *
 * A cPanel MultiPHP switch changes session.save_path to a per-version folder
 * (/var/cpanel/php/sessions/ea-php83). If that folder is missing or read-only
 * every request starts an empty session, the login form's token never comes
 * back, and the panel says "your session expired" — with nothing pointing here.
 */
$sessionPath = trim((string) ini_get('session.save_path'));
if ($sessionPath !== '' && strpos($sessionPath, ';') !== false) {
    $sessionBits = explode(';', $sessionPath);
    $sessionPath = (string) end($sessionBits);
}
$sessionDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
/*
 * Database driver. "Class 'PDO' not found" is a fatal that no page can catch,
 * so the panel cannot report it — this file can, because it loads nothing.
 */
$pdoLoaded = class_exists('PDO');
$pdoDrivers = $pdoLoaded ? PDO::getAvailableDrivers() : array();
$pdoMysql = in_array('mysql', $pdoDrivers, true);
$mysqliLoaded = class_exists('mysqli');

$sessionConfigured = $sessionPath !== '' && is_dir($sessionPath) && is_writable($sessionPath);
$sessionFallbackOk = (is_dir($sessionDir) && is_writable($sessionDir))
    || (!is_dir($sessionDir) && is_dir($storage) && is_writable($storage));

if ($sessionConfigured) {
    $sessionLabel = 'Writable';
    $sessionClass = 'yes';
} elseif ($sessionFallbackOk) {
    $sessionLabel = 'Configured path unusable — storage/sessions is used instead';
    $sessionClass = 'yes';
} else {
    $sessionLabel = 'NOT WRITABLE — sign-in cannot keep a session';
    $sessionClass = 'no';
}

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

/*
 * Files that end a PHP block and never open another one.
 *
 * A template that prints HTML legitimately leaves PHP mode with `?>`, but it
 * has to come back with `<?php` before any more code — otherwise PHP prints
 * that code as text. The 9.7.0 console shipped exactly that in
 * includes/app_ui.php: the shell's closing markup was emitted in HTML mode and
 * the function's closing braces, plus the whole lm_app_end() definition, were
 * never parsed. Every console page ended with `} } if (!function_exists(...`
 * on screen, and because lm_app_end() was never declared each page also died
 * with a fatal. The file *parses* perfectly, so a syntax check cannot see it —
 * only counting the mode switches can.
 *
 * The scan is deliberately strict about what counts as code: a `?>` inside a
 * string or a comment is skipped, and a closing tag whose remainder is only
 * HTML has to hit `<?php` before the file ends. Files that never leave PHP
 * mode (the common case) cost nothing.
 */
$strayHtml = array();
foreach ($sessionScan as $path) {
    $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($file)) {
        continue;
    }
    $src = (string) @file_get_contents($file);
    if (strpos($src, '?>') === false) {
        continue; // stayed in PHP the whole way through
    }
    $strays = lm_health_stray_html($src);
    if (!empty($strays)) {
        $strayHtml[$path] = $strays;
    }
}

/*
 * Files on disk that this release does not ship.
 *
 * build.json lists every file that runs. A .php file that is not in it is
 * either a leftover from an older upload or something an intruder put there —
 * that is how the WordPress-disguised shell was found in 8.9.0.
 */
$unexpected = array();
if ($manifest !== null) {
    $skipTop = array('storage', 'uploads', 'docs');
    foreach ((array) @scandir($root) as $entryDir) {
        if ($entryDir === '.' || $entryDir === '..') {
            continue;
        }
        $fullDir = $root . DIRECTORY_SEPARATOR . $entryDir;
        if (!is_dir($fullDir)) {
            continue;
        }
        $top = strtolower($entryDir);
        if (in_array($top, $skipTop, true) || ($top !== '' && $top[0] === '.' && $top !== '.well-known')) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php' || $file->getSize() === 0) {
                continue; // a zero-byte placeholder carries no code
            }
            $relPath = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (!isset($manifest['files'][$relPath])) {
                $unexpected[] = $relPath;
            }
        }
    }

    // Root-level PHP files too (a shell likes the document root).
    foreach ((array) @scandir($root) as $entry) {
        if (substr($entry, -4) !== '.php' || !is_file($root . DIRECTORY_SEPARATOR . $entry)) {
            continue;
        }
        if ((int) @filesize($root . DIRECTORY_SEPARATOR . $entry) === 0) {
            continue;
        }
        if (!isset($manifest['files'][$entry])) {
            $unexpected[] = $entry;
        }
    }
    sort($unexpected);
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
if (!empty($unexpected)) {
    $problems[] = count($unexpected) . ' PHP file(s) here are not part of this release: '
        . implode(', ', array_slice($unexpected, 0, 8)) . (count($unexpected) > 8 ? ', …' : '')
        . '. If you did not add them, treat the installation as compromised — open admin/system-check.php.';
}
if (!empty($legacySession)) {
    $problems[] = count($legacySession) . ' script(s) still call session_start() instead of '
        . 'lm_session_start(), so they see an empty session: ' . implode(', ', array_slice($legacySession, 0, 8))
        . (count($legacySession) > 8 ? ', …' : '') . '. Re-upload the admin/ and user/ folders.';
}
if (!empty($strayHtml)) {
    $names = array_keys($strayHtml);
    $problems[] = count($names) . ' file(s) leave PHP mode and never reopen it, so PHP prints the code that '
        . 'follows as text on the page: ' . implode(', ', array_slice($names, 0, 6))
        . (count($names) > 6 ? ', …' : '') . '. The usual cause is a misplaced ?> or <?php in a template; '
        . 're-upload ' . (count($names) === 1 ? 'that file' : 'those files') . ' from the ' . $version . ' ZIP. '
        . 'The console shell does this in 9.7.0 and every admin page ends with stray braces.';
}
if (!is_file($settings)) {
    $problems[] = 'settings.json is missing. Upload the copy from your backup, or open Settings once the panel loads.';
}
if (is_file($settings) && !is_readable($settings)) {
    $problems[] = 'settings.json exists but PHP cannot read it (permissions should be 0600–0644).';

if (!$pdoLoaded) {
    $problems[] = 'PHP ' . $php . ' does not load the PDO extension, so no page can reach MySQL. '
        . ($mysqliLoaded
            ? 'mysqli is loaded, which means the extension is switched off for this version rather than missing from the server. '
            : '')
        . 'Enable pdo + pdo_mysql in cPanel → MultiPHP Manager → Extensions, or switch the domain back to PHP 7.4.';
} elseif (!$pdoMysql) {
    $problems[] = 'PDO is loaded but has no mysql driver (available: ' . (empty($pdoDrivers) ? 'none' : implode(', ', $pdoDrivers)) . '). '
        . 'Enable pdo_mysql in cPanel → MultiPHP Manager → Extensions, or switch the domain back to PHP 7.4.';
}
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
            <span>Files outside this release</span>
            <span class="<?= empty($unexpected) ? 'yes' : 'no' ?>"><?= empty($unexpected) ? 'none' : count($unexpected) ?></span>
        </div>
        <?php if (!empty($unexpected)): ?>
            <p class="hint" style="margin-top:12px">Not shipped by any release. If one of these was not added on purpose, rename it to <code>.bak</code> (or delete it) over FTP/cPanel — the console no longer ships a quarantine tool:</p>
            <pre><?= htmlspecialchars(implode("\n", array_slice($unexpected, 0, 40)) . (count($unexpected) > 40 ? "\n… " . (count($unexpected) - 40) . ' more' : '')) ?></pre>
        <?php endif; ?>
        <div class="row">
            <span>Legacy session_start() scripts</span>
            <span class="<?= empty($legacySession) ? 'yes' : 'no' ?>"><?= empty($legacySession) ? 'none' : count($legacySession) ?></span>
        </div>
        <div class="row">
            <span>Files printing PHP source</span>
            <span class="<?= empty($strayHtml) ? 'yes' : 'no' ?>"><?= empty($strayHtml) ? 'none' : count($strayHtml) ?></span>
        </div>
        <?php if (!empty($strayHtml)): ?>
            <p class="hint" style="margin-top:12px">These leave PHP mode and print code as text — a misplaced <code>?&gt;</code> or <code>&lt;?php</code>. Line numbers are where the code starts showing up in the page:</p>
            <pre><?= htmlspecialchars(implode("\n", array_map(function ($path, $lines) {
                $first = array_slice($lines, 0, 3, true);
                $detail = array();
                foreach ($first as $number => $text) {
                    $detail[] = $number . ': ' . $text;
                }
                return $path . ' — ' . implode(' | ', $detail) . (count($lines) > 3 ? ' | …' : '');
            }, array_keys($strayHtml), $strayHtml))) ?></pre>
        <?php endif; ?>
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
        <div class="row"><span>PHP version</span><span class="<?= $phpOk ? 'yes' : 'no' ?>"><?= htmlspecialchars($php) ?><?= $phpOk ? ($phpTested ? '' : ' — newer than the tested range (7.4 – 8.5)') : ' — too old, needs 7.4+' ?></span></div>
        <div class="row"><span>Session storage</span><span class="<?= $sessionClass ?>"><?= htmlspecialchars($sessionLabel) ?></span></div>
        <div class="row"><span>Interface</span><span><?= htmlspecialchars(PHP_SAPI) ?></span></div>
        <div class="row"><span>PDO (database access)</span><span class="<?= $pdoLoaded ? 'yes' : 'no' ?>"><?= $pdoLoaded ? 'loaded' : 'MISSING' ?></span></div>
        <div class="row"><span>PDO MySQL driver</span><span class="<?= $pdoMysql ? 'yes' : 'no' ?>"><?= $pdoMysql ? 'available' : ($pdoLoaded ? 'MISSING — available: ' . htmlspecialchars(empty($pdoDrivers) ? 'none' : implode(', ', $pdoDrivers)) : 'n/a (PDO missing)') ?></span></div>
        <div class="row"><span>mysqli</span><span class="yes"><?= $mysqliLoaded ? 'available' : 'missing' ?></span></div>
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
