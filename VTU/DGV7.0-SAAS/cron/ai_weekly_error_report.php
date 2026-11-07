<?php
/**
 * AI Weekly Health Scan & Enhancement Plan — DGV7.0 AI Edition
 * Schedule: weekly (recommended) → 0 8 * * 1   (every Monday 08:00)
 *
 * 1) Scans PHP error_log files, the app logs/ directory and database audit logs
 *    (sas_ai_audit_log, failed transactions, password-reset brute-force attempts)
 *    for the last 7 days.
 * 2) When errors/issues are detected, the Cloud AI engine analyses them and a
 *    report is emailed to the admin email (getSuperAdminOption 'admin_email').
 * 3) The AI also reviews the health snapshot for feature-enhancement needs and,
 *    when it finds improvements worth making, emails an implementation plan.
 *
 * Add to crontab (see CRON_SETUP.md):
 *   0 8 * * 1 /usr/bin/php /home/YOUR_USERNAME/public_html/cron/ai_weekly_error_report.php >> /home/YOUR_USERNAME/logs/weekly_health.log 2>&1
 */

define('CRON_CLI', true);
require_once __DIR__ . '/../func/bc-connect.php';
require_once __DIR__ . '/../func/bc-ai-engine.php';

$started = microtime(true);
echo "[WEEKLY-AI-SCAN] " . date('Y-m-d H:i:s') . " — starting\n";

/* ── Local helpers ─────────────────────────────────────────────────── */

/** Read error lines from a file that were written after $since (reads the last ~2 MB tail). */
function weekly_scan_file_since(string $path, string $since): array {
    $since_ts = strtotime($since);
    $lines    = [];
    $fp = @fopen($path, 'r');
    if (!$fp) return $lines;
    $size   = @filesize($path);
    $offset = max(0, (int)$size - 2000000); // last 2 MB
    if ($offset > 0) { @fseek($fp, $offset); @fgets($fp); }
    while (($line = @fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        // PHP default log format: "[26-Aug-2026 12:00:00 UTC] message"
        if (preg_match('/\[(\d{2}-[A-Za-z]{3}-\d{4}[^\]]*)\]/', $line, $m)) {
            $ts = strtotime($m[1]);
            if ($ts !== false && $ts < $since_ts) continue;
        }
        $lines[] = weekly_truncate($line, 500);
        if (count($lines) >= 150) break;
    }
    @fclose($fp);
    return $lines;
}

/** Truncate a string, tolerating a missing mbstring extension. */
function weekly_truncate(string $s, int $len): string {
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

/** Send an HTML report to the admin. Uses the platform mailer when available. */
function weekly_send_report_email(string $to, string $subject, string $html): bool {
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\n";
    if (function_exists('customBCMailSender')) {
        return (bool)@customBCMailSender('', $to, $subject, $html, $headers, true);
    }
    $from = 'no-reply@' . (($_SERVER['HTTP_HOST'] ?? '') ?: 'localhost');
    return (bool)@mail($to, $subject, $html, $headers, "-f $from");
}

/* ── Resolve admin email ───────────────────────────────────────────── */
$admin_email = getSuperAdminOption('admin_email', '');
if (empty($admin_email)) {
    $sp = @mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_super_admin LIMIT 1"));
    if ($sp && !empty($sp['email'])) $admin_email = $sp['email'];
}
if (empty($admin_email)) {
    $vid = resolveVendorID();
    $vn  = @mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_vendors WHERE id='$vid' LIMIT 1"));
    if ($vn && !empty($vn['email'])) $admin_email = $vn['email'];
}
if (empty($admin_email)) {
    echo "[WEEKLY-AI-SCAN] No admin email configured. Aborting.\n";
    exit(0);
}

$since      = date('Y-m-d H:i:s', strtotime('-7 days'));
$since_date = date('Y-m-d', strtotime('-7 days'));
$engine     = ai_engine();
$ai_online  = $engine->isAiOnline();
$model      = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');
$site_title = getSuperAdminOption('site_title', 'VTU Platform');

/* ── 1. Collect error/issue sources (last 7 days) ──────────────────── */
$issues = []; // source => [lines/rows]

// 1a. php.ini error_log path
$php_log = ini_get('error_log');
foreach ((array)$php_log as $logf) {
    if (is_string($logf) && $logf !== '' && is_file($logf) && is_readable($logf)) {
        $l = weekly_scan_file_since($logf, $since);
        if ($l) $issues['PHP error_log'] = $l;
    }
}

// 1b. error_log files under the app root (recursive, skip heavy dirs)
$root      = str_replace('\\', '/', dirname(__DIR__));
$root_rel  = rtrim($root, '/') . '/';
$skip_dirs = ['/vendor/', '/node_modules/', '/build/', '/.git/', '/assets-2/', '/cssfile/', '/jsfile/', '/uploaded-image/', '/ANDROID - iOS/', '/lm/'];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getFilename() !== 'error_log' || !is_readable($f->getPathname())) continue;
    $full = str_replace('\\', '/', $f->getPathname());
    $rel  = (strpos($full, $root_rel) === 0) ? substr($full, strlen($root_rel)) : basename($full);
    $skip = false;
    foreach ($skip_dirs as $sd) { if (stripos('/' . $rel, $sd) !== false) { $skip = true; break; } }
    if ($skip) continue;
    $l = weekly_scan_file_since($f->getPathname(), $since);
    if ($l) $issues['error_log: ' . $rel] = $l;
}

// 1c. logs/ directory files
$logs_dir = dirname(__DIR__) . '/logs';
if (is_dir($logs_dir)) {
    foreach ((array)glob($logs_dir . '/*') as $lf) {
        if (is_file($lf) && is_readable($lf)) {
            $l = weekly_scan_file_since($lf, $since);
            if ($l) $issues['logs/' . basename($lf)] = $l;
        }
    }
}

// 1d. DB audit log (security/error events)
$audit = [];
$aq = @mysqli_query($connection_server, "SELECT event_type, action, actor, detail, created_at FROM sas_ai_audit_log WHERE created_at > '$since' ORDER BY created_at DESC LIMIT 200");
if ($aq) while ($a = mysqli_fetch_assoc($aq)) $audit[] = "[" . $a['created_at'] . "] " . $a['event_type'] . " / " . $a['action'] . " / " . $a['actor'] . " — " . $a['detail'];
if ($audit) $issues['DB audit log (sas_ai_audit_log)'] = array_slice($audit, 0, 100);

// 1e. Password-reset brute-force attempts (anti-abuse, ties into the daily-reset-limit feature)
$resets = [];
$rq = @mysqli_query($connection_server, "SELECT username, ip_address, attempt_type, timestamp FROM sas_password_reset_attempts WHERE success=0 AND timestamp > '$since' ORDER BY timestamp DESC LIMIT 100");
if ($rq) while ($a = mysqli_fetch_assoc($rq)) $resets[] = "[" . $a['timestamp'] . "] " . $a['username'] . " via " . $a['ip_address'] . " (" . $a['attempt_type'] . ")";
if ($resets) $issues['Password-reset brute-force attempts'] = array_slice($resets, 0, 80);

// 1f. Failed transactions
$failed = [];
$fq = @mysqli_query($connection_server, "SELECT vendor_id, username, gateway_name, amount, date, status, response FROM sas_transactions WHERE status=3 AND date >= '$since_date' ORDER BY date DESC LIMIT 100");
if ($fq) while ($a = mysqli_fetch_assoc($fq)) $failed[] = "[" . $a['date'] . "] v" . $a['vendor_id'] . " / " . $a['username'] . " / " . ($a['gateway_name'] ?? '?') . " / " . ($a['amount'] ?? '?') . " — " . ($a['response'] ?? '');
if ($failed) $issues['Failed transactions (7d)'] = array_slice($failed, 0, 80);

$total_issues = 0;
foreach ($issues as $src => $rows) $total_issues += count($rows);
$has_issues = $total_issues > 0;
echo "[WEEKLY-AI-SCAN] Sources with issues: " . count($issues) . ", total items: $total_issues\n";

/* ── 2. Build the health snapshot text for the AI ──────────────────── */
$snapshot = "Weekly health snapshot for '$site_title' (last 7 days):\n";
if (!$issues) {
    $snapshot .= "- No errors detected in PHP logs, app logs, DB audit log, failed transactions or password-reset attempts.\n";
} else {
    foreach ($issues as $src => $rows) {
        $snapshot .= "- $src: " . count($rows) . " item(s)\n";
        foreach (array_slice($rows, 0, 8) as $row) $snapshot .= "    • " . $row . "\n";
    }
}

/* ── 3. AI: error analysis report ─────────────────────────────────── */
$report_text = '';
if ($has_issues && $ai_online) {
    $report_prompt = "You are the senior reliability auditor for '$site_title'. Below is the 7-day health snapshot.\n$snapshot\n\n"
        . "Produce a concise error report: 1) Root causes grouped by severity (critical/warning/info), 2) Which areas were affected, 3) Concrete fixes/recommendations for each. Use plain text with short headings.";
    $res = $engine->chat($model, $report_prompt);
    $report_text = trim($res['response'] ?? '');
    echo "[WEEKLY-AI-SCAN] AI error report generated (" . strlen($report_text) . " chars)\n";
}

/* ── 4. AI: feature enhancement / implementation plan ──────────────── */
$plan_text = '';
if ($ai_online) {
    $plan_prompt = "You are the product architect for '$site_title'. Review this weekly health snapshot and the error findings.\n$snapshot\n"
        . "Decide whether any feature enhancement or hardening is worth implementing this week. "
        . "If yes, output a concrete implementation plan titled 'ENHANCEMENT PLAN' with: 1) What to improve and why, 2) Step-by-step implementation steps (files/functions to touch where sensible), 3) Priority (P0/P1/P2), 4) Estimated effort. "
        . "If nothing is needed, reply exactly: 'No enhancements needed this week.'";
    $res2 = $engine->chat($model, $plan_prompt);
    $plan_text = trim($res2['response'] ?? '');
    echo "[WEEKLY-AI-SCAN] AI enhancement plan generated (" . strlen($plan_text) . " chars)\n";
}
$plan_wanted = ($plan_text !== '' && stripos($plan_text, 'No enhancements needed') === false);

/* ── 5. Compose & send the email ───────────────────────────────────── */
$body_html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1e293b;max-width:720px;margin:0 auto;">';
$body_html .= '<div style="background:#0f172a;color:#fff;padding:18px 22px;border-radius:12px 12px 0 0;">';
$body_html .= '<h2 style="margin:0;font-size:18px;">🛡️ Weekly AI Health Scan — ' . htmlspecialchars($site_title) . '</h2>';
$body_html .= '<div style="font-size:12px;opacity:.8;">' . date('l, j F Y H:i') . ' • period: last 7 days</div></div>';
$body_html .= '<div style="border:1px solid #e2e8f0;border-top:0;border-radius:0 0 12px 12px;padding:22px;">';

if ($has_issues) {
    $body_html .= '<div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:8px;margin-bottom:18px;"><b>' . $total_issues . ' issue(s)</b> detected across ' . count($issues) . ' source(s).</div>';
} else {
    $body_html .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:12px 16px;border-radius:8px;margin-bottom:18px;"><b>✅ No errors detected</b> in the last 7 days.</div>';
}

if ($issues) {
    $body_html .= '<h3 style="font-size:15px;margin:0 0 10px;">Error scan summary</h3><table style="width:100%;border-collapse:collapse;font-size:13px;">';
    foreach ($issues as $src => $rows) {
        $body_html .= '<tr><td style="border:1px solid #e2e8f0;padding:8px 10px;font-weight:bold;">' . htmlspecialchars($src) . '</td><td style="border:1px solid #e2e8f0;padding:8px 10px;text-align:center;">' . count($rows) . '</td></tr>';
    }
    $body_html .= '</table>';
    $body_html .= '<h3 style="font-size:15px;margin:16px 0 10px;">Sample entries</h3><pre style="background:#f8fafc;border:1px solid #e2e8f0;padding:12px;border-radius:8px;font-size:12px;white-space:pre-wrap;max-height:280px;overflow:auto;">';
    foreach ($issues as $src => $rows) {
        $body_html .= '— ' . htmlspecialchars($src) . " —\n";
        foreach (array_slice($rows, 0, 6) as $row) $body_html .= htmlspecialchars(weekly_truncate($row, 300)) . "\n";
    }
    $body_html .= '</pre>';
}

if ($report_text) {
    $body_html .= '<h3 style="font-size:15px;margin:18px 0 10px;">🤖 AI Error Analysis</h3><div style="background:#f8fafc;border:1px solid #e2e8f0;padding:14px;border-radius:8px;font-size:13px;white-space:pre-wrap;">' . htmlspecialchars($report_text) . '</div>';
}

if ($plan_wanted) {
    $body_html .= '<h3 style="font-size:15px;margin:18px 0 10px;">🚀 AI Enhancement & Implementation Plan</h3><div style="background:#fefce8;border:1px solid #fde68a;padding:14px;border-radius:8px;font-size:13px;white-space:pre-wrap;">' . htmlspecialchars($plan_text) . '</div>';
} else {
    $body_html .= '<h3 style="font-size:15px;margin:18px 0 10px;">🚀 AI Enhancement Review</h3><div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:14px;border-radius:8px;font-size:13px;">No feature enhancements needed this week.</div>';
}

$body_html .= '<div style="margin-top:20px;font-size:11px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:10px;">Generated automatically by the weekly AI health-scan cron. AI service: ' . ($ai_online ? 'online' : 'offline') . ' • Model: ' . htmlspecialchars($model) . '</div>';
$body_html .= '</div></div>';

$subject = $has_issues
    ? "⚠ Weekly AI Health Report — $total_issues issue(s) detected"
    : "✅ Weekly AI Health Report — All clear";

weekly_send_report_email($admin_email, $subject, $body_html);
echo "[WEEKLY-AI-SCAN] Email sent to $admin_email\n";

// Persist a snapshot into the AI intelligence store for history/reporting.
if (function_exists('bc_log_ai_intelligence')) {
    @bc_log_ai_intelligence(0, 'weekly_health', $subject . "\n\n" . $snapshot, json_encode(['issues' => $total_issues, 'sources' => count($issues), 'ai_online' => $ai_online]));
}

echo "[WEEKLY-AI-SCAN] Done in " . round(microtime(true) - $started, 2) . "s\n";
