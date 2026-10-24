<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/lm_config.php'; // lm_verify_csrf(), lm_csrf_token(), lm_log()
lm_session_start();
lm_security_headers();

// Check if user is admin
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once('../db.php');

/**
 * The signatures of a PHP web shell, split into two confidence levels.
 *
 * `critical` signatures are strings that only appear in a dropper. One hit is
 * decisive and the file must go. `review` signatures are functions legitimate
 * code also uses (a mailer may shell out, a backup script may gzinflate), so a
 * hit means "a human should look", not "delete this".
 *
 * The needles are assembled from fragments on purpose: this file must never
 * match its own list. Before 9.2.0 it did — it reported itself, and it reported
 * lm_config.php for a php_uname() call inside a random-bytes fallback. Two
 * false positives in a three-item list is how an operator learns to ignore the
 * one that matters.
 *
 * @return array{critical:array,review:array,allowed:array}
 */
if (!function_exists('lm_shell_signatures')) {
function lm_shell_signatures()
{
    $word = function () {
        return implode('', func_get_args());
    };

    return array(
        'critical' => array(
            // The shell removed from the package in 8.9.0.
            array('label' => 'WP_Theme_Worker remote-access shell', 'needle' => $word('WP', '_Theme', '_Worker')),
            array('label' => 'WP_Theme_Worker shell entry point', 'needle' => $word('do_maintenance', '_cron')),
            array('label' => 'command execution', 'needle' => $word('pcntl', '_exec')),
            array('label' => 'command execution', 'needle' => $word('shell', '_exec(')),
            array('label' => 'command execution', 'needle' => $word('passthru', '(')),
            array('label' => 'obfuscated payload', 'needle' => $word('gzinflate(', 'base64_decode')),
            array('label' => 'obfuscated payload', 'needle' => $word('str_rot13', '(')),
            array('label' => 'eval of request data', 'needle' => $word('eval', '($_')),
            array('label' => 'eval of request data', 'needle' => $word('assert', '($_')),
            // A key pasted into source, as the 8.9.0 shell did.
            array('label' => 'hard-coded shell key', 'needle' => $word("hex2bin( '", '19d8d3c5')),
        ),
        'review' => array(
            array('label' => 'popen()', 'needle' => $word('popen', '(')),
            array('label' => 'proc_open()', 'needle' => $word('proc_open', '(')),
            array('label' => 'host fingerprinting', 'needle' => $word('php', '_uname(')),
            array('label' => 'include of a variable', 'needle' => $word('include ', '($_')),
        ),
        /*
         * Reviewed, legitimate uses. A file listed here still gets its critical
         * scan; only the named review signature is excused.
         */
        'allowed' => array(
            'includes/src/PHPMailer.php' => array('str_rot13('),
            'includes/src/SMTP.php' => array('str_rot13('),
        ),
    );
}
}

/**
 * Files inside LM/ that are not part of the release.
 *
 * The strongest signal available, and the one that would have caught the
 * 8.9.0 shell on sight: build.json lists every shipped file, so anything else
 * that can be executed is either a leftover or an intruder.
 *
 * @return array<int,string> Relative paths
 */
if (!function_exists('lm_unexpected_php_files')) {
function lm_unexpected_php_files()
{
    $root = dirname(__DIR__);
    $manifestFile = $root . '/build.json';
    if (!is_file($manifestFile)) {
        return array();
    }

    $manifest = json_decode((string) @file_get_contents($manifestFile), true);
    if (!is_array($manifest) || empty($manifest['files']) || !is_array($manifest['files'])) {
        return array();
    }

    $known = array();
    foreach (array_keys($manifest['files']) as $path) {
        $known[strtolower(str_replace('\\', '/', $path))] = true;
    }

    $skipDirs = array('storage', 'uploads', 'docs');
    $out = array();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');

        $first = strtolower(strtok($rel, '/'));
        if (in_array($first, $skipDirs, true)) {
            continue;
        }
        // Hidden folders (.git, deploy scripts, this project's QA harness) are
        // not part of the release and are never served. The shell scan above
        // still reads every file, including these.
        if ($first !== '' && $first[0] === '.' && $first !== '.well-known') {
            continue;
        }
        if (isset($known[strtolower($rel)])) {
            continue;
        }

        $out[] = $rel;
    }

    sort($out);
    return $out;
}
}

/**
 * Look for the patterns used by PHP web shells, and for files that do not
 * belong to this release.
 *
 * Never executes the file it reads, skips files larger than 1 MB, and reports
 * the matching signature so the operator can judge it.
 *
 * @return array{paths:array,review:array,unexpected:array,scanned:int}
 */
if (!function_exists('lm_scan_for_shells')) {
function lm_scan_for_shells()
{
    $root = dirname(__DIR__);
    $signatures = lm_shell_signatures();
    $self = basename(__DIR__) . '/system-check.php';

    $hits = array();
    $review = array();
    $scanned = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (strpos($path, DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }
        if ($file->getSize() > 1048576) {
            continue;
        }

        $scanned++;
        $body = @file_get_contents($path);
        if (!is_string($body) || $body === '') {
            continue;
        }

        $rel = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
        if (strpos($rel, $self) === 0) {
            continue; // never judge this file by the list it carries
        }

        $excused = isset($signatures['allowed'][$rel]) ? $signatures['allowed'][$rel] : array();

        foreach ($signatures['critical'] as $signature) {
            if (in_array($signature['needle'], $excused, true)) {
                continue;
            }
            if (stripos($body, $signature['needle']) !== false) {
                $hits[] = array('file' => $rel, 'label' => $signature['label']);
                continue 2;
            }
        }

        foreach ($signatures['review'] as $signature) {
            if (in_array($signature['needle'], $excused, true)) {
                continue;
            }
            if (stripos($body, $signature['needle']) !== false) {
                $review[$rel][] = $signature['label'];
            }
        }
    }

    usort($hits, function ($a, $b) {
        return strcmp($a['file'], $b['file']);
    });
    ksort($review);

    return array(
        'paths' => $hits,
        'review' => $review,
        'unexpected' => lm_unexpected_php_files(),
        'scanned' => $scanned,
    );
}
}

$scan = lm_scan_for_shells();

$removalNotice = '';
$removalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_file'])) {
    $target = str_replace('\\', '/', trim((string) $_POST['remove_file']));
    $confirmName = basename($target);
    $typed = trim((string) ($_POST['confirm_name'] ?? ''));

    clearstatcache();

    // The list of removable files is rebuilt from the scan, never taken from
    // the request: a POST can only name something the scanner already found.
    $removable = array();
    foreach ($scan['paths'] as $hit) {
        $removable[$hit['file']] = 'a web-shell signature';
    }
    foreach ($scan['unexpected'] as $path) {
        if (!isset($removable[$path])) {
            $removable[$path] = 'not part of this release';
        }
    }

    $root = dirname(__DIR__);
    $resolved = strpos($target, '..') === false ? realpath($root . '/' . $target) : false;
    $rootReal = realpath($root);

    if (!lm_verify_csrf()) {
        $removalError = 'Your session was not saved, so this action was refused. Go back and reload; if it repeats, open System Check → PHP & sessions.';
    } elseif ($typed !== $confirmName) {
        $removalError = 'Type the file name exactly (' . $confirmName . ') to confirm.';
    } elseif (!isset($removable[$target])) {
        $removalError = 'That file is not on the list of findings. Run the check again.';
    } elseif ($target === 'admin/system-check.php') {
        $removalError = 'That is this page. Remove it in cPanel if you want it gone.';
    } elseif ($resolved === false || $rootReal === false || strpos($resolved, $rootReal . DIRECTORY_SEPARATOR) !== 0 || !is_file($resolved)) {
        $removalError = 'That path is not a file inside this installation.';
    } else {
        $backup = dirname($resolved) . DIRECTORY_SEPARATOR . '.' . basename($resolved) . '.removed-' . gmdate('Ymd-His') . '.bak';
        $moved = @rename($resolved, $backup);

        if (!$moved) {
            // Filesystem may refuse the rename across a mount; try a copy+unlink.
            $moved = @copy($resolved, $backup) && @unlink($resolved);
            if (!$moved) {
                @unlink($backup);
            }
        }

        if ($moved) {
            @chmod($backup, 0600);
            @chmod(dirname($backup), 0755);
            $removalNotice = $target . ' was moved to ' . basename($backup) . ' in the same folder. '
                . 'It is no longer executable. Download it for evidence, then delete it.'
                . ' Rotate every credential it could read.';
            if (function_exists('lm_log')) {
                lm_log('security', 'System check quarantined ' . $target . ' ('
                    . $removable[$target] . ') as ' . basename($backup));
            }
            $scan = lm_scan_for_shells();
        } else {
            $removalError = 'The server would not let PHP remove ' . $target
                . '. Delete it in cPanel → File Manager, then run this check again.';
        }
    }
}

$integrityProblems = !empty($scan['paths']) || !empty($scan['unexpected']);

$diagnostics = [
    'curl_enabled' => extension_loaded('curl'),
    'paystack_configured' => false,
    'paystack_reachable' => false,
    'settings_file' => file_exists('../settings.json'),
    'smtp_configured' => false,
    'licenses_table_ok' => false,
    'transactions_table_ok' => false,
    'shell_scan' => ['paths' => [], 'review' => [], 'unexpected' => [], 'scanned' => 0],
    'php_ok' => true,
    'session_path' => '',
    'session_writable' => false,
    'session_fallback' => false,
    'session_problem' => '',
];

$diagnostics['shell_scan'] = $scan;

// PHP version + session storage. This is the row that explains "the panel
// says my session expired" after a MultiPHP version switch: the version is
// fine, the session folder is not.
if (function_exists('lm_session_save_dir')) {
    $sessionInfo = lm_session_save_dir();
    $diagnostics['php_ok'] = function_exists('lm_php_version_supported') ? lm_php_version_supported() !== 'old' : true;
    $diagnostics['session_path'] = $sessionInfo['path'];
    $diagnostics['session_writable'] = $sessionInfo['writable'];
    $diagnostics['session_fallback'] = $sessionInfo['fallback'];
    if (function_exists('lm_session_failure')) {
        $diagnostics['session_problem'] = lm_session_failure();
    }
}
$sessionOk = $diagnostics['php_ok'] && $diagnostics['session_writable'] && $diagnostics['session_problem'] === '';

$settings = [];
if (file_exists('../settings.json')) {
    $settings = json_decode(file_get_contents('../settings.json'), true);
    $diagnostics['paystack_configured'] = !empty($settings['paystack_secret_key']);
    $diagnostics['smtp_configured'] = !empty($settings['smtp_host']) && !empty($settings['smtp_user']);
}

// Check database tables. This page is a diagnostic: a broken database must
// produce a red row, not a white page.
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('No database connection');
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field IN ('customer_email', 'license_key', 'domain')");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $diagnostics['licenses_table_ok'] = count($cols) >= 3;
    
    $stmt = $pdo->query("SHOW COLUMNS FROM `transactions` WHERE Field IN ('license_id', 'transaction_ref', 'status')");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $diagnostics['transactions_table_ok'] = count($cols) >= 3;
} catch (Exception $e) {
    // Database error
} catch (Throwable $e) {
    // PHP 7 TypeError from a null $pdo
}

// Test Paystack connectivity if curl is available
if ($diagnostics['curl_enabled'] && $diagnostics['paystack_configured']) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/test",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . $settings['paystack_secret_key'],
        ),
    ));
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    // Any response from Paystack API means it's reachable
    $diagnostics['paystack_reachable'] = ($http_code > 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Check - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 2rem;
            padding: 0.75rem 1.5rem;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        h1 {
            color: white;
            margin-bottom: 2rem;
            font-size: 2.5rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            gap: 1rem;
        }
        .check-item:last-child {
            border-bottom: none;
        }
        .check-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }
        .check-icon.pass {
            background: #10b981;
        }
        .check-icon.fail {
            background: #ef4444;
        }
        .check-label {
            flex-grow: 1;
        }
        .check-label strong {
            display: block;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        .check-label small {
            color: #666;
            font-size: 0.875rem;
        }
        .next-step {
            background: #eff6ff;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            color: #1e40af;
        }
        .next-step h3 {
            margin-bottom: 0.75rem;
            color: #1e40af;
        }
        .next-step ol {
            padding-left: 1.5rem;
        }
        .next-step li {
            margin-bottom: 0.5rem;
            line-height: 1.6;
        }
        .action-link {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.75rem 1.5rem;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .action-link:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
        .notice { padding: 14px 16px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; line-height: 1.6; }
        .notice.ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .notice.bad { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .finding { margin-top: 14px; padding: 14px 16px; border-radius: 10px; background: #f8fafc; border: 1px solid #e5e7eb; font-size: 13.5px; line-height: 1.65; }
        .finding ul.filelist { list-style: none; padding: 0; margin: 10px 0 0; display: grid; gap: 8px; }
        .finding ul.filelist li { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 10px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        .finding code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; word-break: break-all; }
        .bad-text { color: #b91c1c; }
        .warn-text { color: #b45309; }
        .tag { font-size: 11.5px; font-weight: 700; padding: 3px 8px; border-radius: 999px; background: #eef2ff; color: #3730a3; }
        .bad-tag { background: #fef2f2; color: #b91c1c; }
        .warn-tag { background: #fffbeb; color: #92400e; }
        details.review { margin-top: 14px; font-size: 13px; }
        details.review summary { cursor: pointer; font-weight: 600; color: #475569; }
        details.review ul.filelist { list-style: none; padding: 0; margin: 10px 0 0; display: grid; gap: 6px; }
        details.review li { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 12.5px; }
        .tiny { font-size: 12px; color: #64748b; margin-top: 6px; }
        .quarantine { display: flex; gap: 6px; margin-left: auto; }
        .quarantine input[type="text"] { width: 150px; padding: 6px 9px; border: 1px solid #d7deea; border-radius: 7px; font-size: 12px; font-family: ui-monospace, Menlo, monospace; }
        .quarantine button { padding: 6px 12px; border-radius: 7px; border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; font-size: 12px; font-weight: 700; cursor: pointer; }
        .quarantine button[disabled] { opacity: .45; cursor: not-allowed; }
    </style>
</head>
<body>
<?php require __DIR__ . '/_mobile_nav.php'; ?>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1>✓ System Check</h1>

        <div class="card">
            <h2 style="font-size: 1.5rem; color: #1e293b; margin-bottom: 1rem;">Payment System Status</h2>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['curl_enabled'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['curl_enabled'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>cURL Extension</strong>
                    <small><?= $diagnostics['curl_enabled'] ? 'Enabled and ready' : 'NOT INSTALLED - Contact hosting provider' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['settings_file'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['settings_file'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Settings File</strong>
                    <small><?= $diagnostics['settings_file'] ? 'Found and readable' : 'Missing - Run setup' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['paystack_configured'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['paystack_configured'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Paystack Secret Key</strong>
                    <small><?= $diagnostics['paystack_configured'] ? 'Configured' : 'NOT SET - Update in Settings' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['paystack_reachable'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['paystack_reachable'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Paystack API Reachable</strong>
                    <small><?= $diagnostics['paystack_reachable'] ? 'Successfully connected' : 'Connection failed - Check secret key' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['smtp_configured'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['smtp_configured'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>SMTP Email</strong>
                    <small><?= $diagnostics['smtp_configured'] ? 'Configured' : 'NOT SET - Customers won\'t receive emails' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['licenses_table_ok'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['licenses_table_ok'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Licenses Database</strong>
                    <small><?= $diagnostics['licenses_table_ok'] ? 'Schema is correct' : 'Schema is incomplete - Run repair' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['transactions_table_ok'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['transactions_table_ok'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Transactions Database</strong>
                    <small><?= $diagnostics['transactions_table_ok'] ? 'Schema is correct' : 'Schema is incomplete - Run repair' ?></small>
                </div>
            </div>

            <?php if ($removalNotice !== ''): ?>
                <div class="notice ok"><?= htmlspecialchars($removalNotice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($removalError !== ''): ?>
                <div class="notice bad"><?= htmlspecialchars($removalError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="check-item" style="margin-top:14px;align-items:flex-start;">
                <div class="check-icon <?= $sessionOk ? 'pass' : 'fail' ?>">
                    <?= $sessionOk ? '✓' : '✗' ?>
                </div>
                <div style="flex-grow:1;min-width:0;">
                    <h3>PHP &amp; sessions</h3>
                    <small>
                        PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?>
                        <?php if (function_exists('lm_php_version_supported') && lm_php_version_supported() === 'newer'): ?>
                            — newer than the tested range (7.4 – 8.5); working, but unsupported
                        <?php elseif ($diagnostics['php_ok']): ?>
                            — supported (7.4 – 8.5)
                        <?php else: ?>
                            — too old, upgrade in cPanel → MultiPHP Manager
                        <?php endif; ?>
                    </small>
                    <div class="detail" style="margin-top:8px;font-size:13px;color:#475569;line-height:1.7;">
                        <div>
                            <code>session.save_path</code>:
                            <?= htmlspecialchars($diagnostics['session_path'] !== '' ? $diagnostics['session_path'] : '(not set)', ENT_QUOTES, 'UTF-8') ?>
                            — <?= $diagnostics['session_writable'] ? 'writable' : 'NOT WRITABLE' ?>
                        </div>
                        <?php if ($diagnostics['session_fallback']): ?>
                            <div style="color:#92400e;">
                                ⚠ The path PHP is configured to use is missing or read-only, so sessions are being
                                stored in <code>storage/sessions</code> instead. That works — but set
                                <code>session.save_path</code> in cPanel → MultiPHP INI Editor to a writable folder to
                                go back to the default.
                            </div>
                        <?php endif; ?>
                        <?php if ($diagnostics['session_problem'] !== ''): ?>
                            <div style="color:#991b1b;"><?= htmlspecialchars($diagnostics['session_problem'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($sessionOk && !$diagnostics['session_fallback']): ?>
                            <div>Sessions start and persist normally, and the sign-in form's security token survives between requests.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="check-item" style="margin-top:14px;align-items:flex-start;">
                <div class="check-icon <?= $integrityProblems ? 'fail' : 'pass' ?>">
                    <?= $integrityProblems ? '✗' : '✓' ?>
                </div>
                <div style="flex-grow:1;min-width:0;">
                    <h3>File integrity</h3>
                    <small>
                        <?= (int) $diagnostics['shell_scan']['scanned'] ?> PHP files scanned
                        <?php if (!$integrityProblems): ?>— no shell signature and no file outside this release<?php endif; ?>
                    </small>

                    <?php if (!empty($diagnostics['shell_scan']['paths'])): ?>
                        <div class="finding">
                            <strong class="bad-text">Web shell found.</strong>
                            A file here carries a signature that only a remote-access shell uses.
                            Quarantine it, then rotate every credential it could read.
                            <ul class="filelist">
                                <?php foreach ($diagnostics['shell_scan']['paths'] as $hit): ?>
                                    <li>
                                        <code><?= htmlspecialchars($hit['file'], ENT_QUOTES, 'UTF-8') ?></code>
                                        <span class="tag bad-tag"><?= htmlspecialchars($hit['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php require __DIR__ . '/_quarantine_form.php'; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($diagnostics['shell_scan']['unexpected'])): ?>
                        <div class="finding">
                            <strong class="warn-text"><?= count($diagnostics['shell_scan']['unexpected']) ?> file(s) are not part of this release.</strong>
                            build.json lists every file this version ships. Anything else in the
                            folder that can run is a leftover from an old copy or was uploaded by
                            someone else — check each one before removing it.
                            <ul class="filelist">
                                <?php foreach ($diagnostics['shell_scan']['unexpected'] as $path): ?>
                                    <li>
                                        <code><?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?></code>
                                        <span class="tag warn-tag">not in build.json</span>
                                        <?php
                                        $hit = array('file' => $path, 'label' => 'not part of this release');
                                        require __DIR__ . '/_quarantine_form.php';
                                        ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($diagnostics['shell_scan']['review'])): ?>
                        <details class="review">
                            <summary><?= count($diagnostics['shell_scan']['review']) ?> file(s) use a function a shell might use — review, do not panic</summary>
                            <p class="tiny">
                                These are ordinary PHP functions that legitimate code also calls.
                                Nothing here is a finding on its own; it is a list to skim.
                            </p>
                            <ul class="filelist">
                                <?php foreach ($diagnostics['shell_scan']['review'] as $path => $labels): ?>
                                    <li>
                                        <code><?= htmlspecialchars($path, ENT_QUOTES, 'UTF-8') ?></code>
                                        <span class="tag"><?= htmlspecialchars(implode(', ', $labels), ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($diagnostics['curl_enabled'] && $diagnostics['paystack_configured'] && $diagnostics['paystack_reachable'] && $diagnostics['smtp_configured'] && $diagnostics['licenses_table_ok'] && $diagnostics['transactions_table_ok'] && !$integrityProblems && $sessionOk): ?>
                <div class="next-step" style="background: #d1fae5; border-color: #a7f3d0; color: #065f46;">
                    <h3>✓ All Systems Ready!</h3>
                    <p>Your payment system is fully configured. You're ready to process payments.</p>
                    <a href="../order.php" class="action-link" style="background: #10b981;">Try Test Payment</a>
                </div>
            <?php else: ?>
                <div class="next-step">
                    <h3>⚠️ Issues Found</h3>
                    <ol>
                        <?php if (!$diagnostics['php_ok']): ?>
                            <li><strong>PHP is too old:</strong> this build needs PHP 7.4 or newer. Switch it in cPanel → MultiPHP Manager, then reload this page.</li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['session_writable']): ?>
                            <li><strong>Sessions cannot be written:</strong> PHP has nowhere to store session files, so every request starts with an empty session and the panel reports “your session expired”. In cPanel → MultiPHP INI Editor set <code>session.save_path</code> to a writable folder (for example the account's <code>tmp</code> directory), or make <code>storage/sessions</code> writable (chmod 0755).</li>
                        <?php endif; ?>
                        <?php if ($diagnostics['session_problem'] !== ''): ?>
                            <li><strong>Session start failed:</strong> <?= htmlspecialchars($diagnostics['session_problem'], ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['curl_enabled']): ?>
                            <li><strong>Enable cURL:</strong> Contact your hosting provider - cURL is required for Paystack API integration</li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['paystack_configured']): ?>
                            <li><strong>Add Paystack Secret Key:</strong> Go to <a href="settings.php" style="color: #3b82f6; font-weight: 500;">Settings</a> and paste your Paystack Secret Key</li>
                        <?php endif; ?>
                        <?php if ($diagnostics['paystack_configured'] && !$diagnostics['paystack_reachable']): ?>
                            <li><strong>Check Secret Key:</strong> Your Paystack Secret Key appears to be invalid. Verify it in <a href="settings.php" style="color: #3b82f6; font-weight: 500;">Settings</a></li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['smtp_configured']): ?>
                            <li><strong>Configure SMTP:</strong> Go to <a href="settings.php" style="color: #3b82f6; font-weight: 500;">Settings</a> and add your SMTP details so customers receive license emails</li>
                        <?php endif; ?>
                        <?php if (!empty($diagnostics['shell_scan']['paths'])): ?>
                            <li><strong>Web shell present:</strong> a file carries a signature that only a remote-access shell uses. Quarantine it above, then change the database password, the admin password, the Paystack keys, the SMTP password and the GitHub token — a shell can read all of them.</li>
                        <?php endif; ?>
                        <?php if (!empty($diagnostics['shell_scan']['unexpected'])): ?>
                            <li><strong>Unexpected files:</strong> <?= count($diagnostics['shell_scan']['unexpected']) ?> file(s) in the folder are not in <code>build.json</code>. Remove them above if you did not put them there.</li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['licenses_table_ok'] || !$diagnostics['transactions_table_ok']): ?>
                            <li><strong>Database Schema Issue:</strong> <a href="database-repair.php" style="color: #3b82f6; font-weight: 500;">Run Database Repair</a> to fix missing columns</li>
                        <?php endif; ?>
                    </ol>
                    <a href="settings.php" class="action-link">Go to Settings</a>
                    <?php if (!$diagnostics['licenses_table_ok'] || !$diagnostics['transactions_table_ok']): ?>
                        <a href="database-repair.php" class="action-link" style="margin-left: 0.5rem;">Run Database Repair</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="next-step" style="background: #fef3c7; border-color: #fcd34d; color: #92400e; margin-top: 1.5rem;">
                <h3>📊 Debug Information</h3>
                <p>If you still have issues after checking all items above, use these pages:</p>
                <ul style="margin-top: 0.75rem; padding-left: 1.5rem;">
                    <li><a href="payment-debug.php" style="color: #b45309; font-weight: 500;">View Payment Debug Logs</a> - See error messages</li>
                    <li><a href="diagnostic.php" style="color: #b45309; font-weight: 500;">View System Diagnostic</a> - Database and transaction info</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
