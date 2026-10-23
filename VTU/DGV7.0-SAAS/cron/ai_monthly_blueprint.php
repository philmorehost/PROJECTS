<?php
/**
 * DGV6.90 — Monthly AI Blueprint Audit Cron
 * Transitioned to Cloud AI Architecture
 * Schedule: 0 8 1 * * (8:00 AM on the 1st of every month)
 */

define('CRON_CLI', true);
require_once __DIR__ . '/../func/bc-connect.php';
require_once __DIR__ . '/../func/bc-ai-engine.php';

$start_time = microtime(true);
echo "[BLUEPRINT] " . date('Y-m-d H:i:s') . " — Starting monthly AI Blueprint Audit\n";

// ── Check AI availability ─────────────────────────────────────────────────────
$engine   = ai_engine();
$ai_online = $engine->isAiOnline();
$model    = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');

if (!$ai_online) {
    echo "[BLUEPRINT] ⚠ Cloud AI engine offline. Aborting.\n";
    exit(0);
}

// ── Fetch admin info ──────────────────────────────────────────────────────────
$admin_email = getSuperAdminOption('admin_email', '');
$site_title  = getSuperAdminOption('site_title', 'VTU Platform');

if (empty($admin_email)) {
    echo "[BLUEPRINT] ⚠ No admin email found. Aborting.\n";
    exit(0);
}

// ── Step 1: Codebase Analysis ──────────────────────────────────────────────────
echo "[BLUEPRINT] Step 1: Scanning codebase...\n";
$root = dirname(__DIR__);
$php_files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$total_files = 0; $total_lines = 0;
foreach ($php_files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $total_files++;
        $total_lines += count(file($file->getPathname())) ?: 0;
    }
}

// ── Step 2: Stats ──────────────────────────────────────────────────────────────
echo "[BLUEPRINT] Step 2: Gathering DB statistics...\n";
$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end   = date('Y-m-t', strtotime('-1 month'));

function get_stat($conn, $sql) {
    $q = mysqli_query($conn, $sql);
    $r = mysqli_fetch_row($q);
    return $r[0] ?? 0;
}

$stats = [
    'vendors'   => get_stat($connection_server, "SELECT COUNT(*) FROM sas_vendors"),
    'revenue'   => get_stat($connection_server, "SELECT SUM(discounted_amount) FROM sas_transactions WHERE status=1 AND DATE(date) BETWEEN '$last_month_start' AND '$last_month_end'"),
    'failed'    => get_stat($connection_server, "SELECT COUNT(*) FROM sas_transactions WHERE status=3 AND DATE(date) BETWEEN '$last_month_start' AND '$last_month_end'"),
    'ai_calls'  => get_stat($connection_server, "SELECT COUNT(*) FROM sas_ai_transactions WHERE DATE(created_at) BETWEEN '$last_month_start' AND '$last_month_end'"),
];

// ── Step 3: Prompt ─────────────────────────────────────────────────────────────
$revenue_fmt = '₦' . number_format((float)$stats['revenue'], 2);
$month_label = date('F Y', strtotime('-1 month'));

$prompt = "You are a Senior Fintech Architect. Review the '$site_title' platform performance for $month_label:\n"
        . "- Codebase: $total_files PHP files, $total_lines lines\n"
        . "- Stats: {$stats['vendors']} vendors, $revenue_fmt revenue, {$stats['failed']} failures, {$stats['ai_calls']} AI calls.\n"
        . "Generate a 5-section Blueprint report: 1. Summary, 2. Feature Ideas, 3. Security, 4. UI/UX, 5. AI Agent Tasks.";

// ── Step 4: AI Analysis ────────────────────────────────────────────────────────
echo "[BLUEPRINT] Step 4: Querying Cloud AI...\n";
$result = $engine->chat($model, $prompt);
$blueprint_text = $result['response'] ?? '';

if (empty($blueprint_text)) {
    echo "[BLUEPRINT] ❌ AI Error. Aborting.\n";
    exit(0);
}

// ── Step 5: Save & Email ───────────────────────────────────────────────────────
echo "[BLUEPRINT] Step 5: Saving and delivering report...\n";
$bp_esc = mysqli_real_escape_string($connection_server, nl2br(htmlspecialchars($blueprint_text)));
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS `sas_ai_blueprints` (id INT AUTO_INCREMENT PRIMARY KEY, month_label VARCHAR(30), blueprint_html LONGTEXT, generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
mysqli_query($connection_server, "INSERT INTO sas_ai_blueprints (month_label, blueprint_html) VALUES ('$month_label', '$bp_esc')");

$subject = "🧠 AI Platform Blueprint — $month_label | $site_title";

// Build a valid, RFC-5322-compliant From. The old code used $_SERVER['HTTP_HOST'], which is
// empty under CLI, producing "From: ... <noreply@>" — an address with no domain — which Gmail
// rejects with 550 5.7.1 "missing a valid address in From". Resolve the SMTP user from the DB
// (same logic as getSMTPUserForHeaders()) and fall back to a known-good default.
$smtp_from = '';
$q = mysqli_query($connection_server, "SELECT smtp_user FROM sas_super_admin LIMIT 1");
if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['smtp_user'])) $smtp_from = $r['smtp_user'];
if (!filter_var($smtp_from, FILTER_VALIDATE_EMAIL)) $smtp_from = 'notification@cheaperdata.com.ng';
$from_name = trim(preg_replace('/[\x00-\x1F\x7F"(),:;<>@\[\]\\\\]/', '', $site_title));
if ($from_name === '') $from_name = 'System Notification';

$headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: $from_name <$smtp_from>\r\nReply-To: $smtp_from\r\n";
$sent = @mail($admin_email, $subject, "<html><body style='font-family:sans-serif;line-height:1.6;'>$bp_esc<hr><p style='font-size:12px;color:#888;'>Generated by Cloud AI Engine</p></body></html>", $headers, '-f' . $smtp_from);
if (!$sent) {
    $sent = @mail($admin_email, $subject, "<html><body style='font-family:sans-serif;line-height:1.6;'>$bp_esc<hr><p style='font-size:12px;color:#888;'>Generated by Cloud AI Engine</p></body></html>", $headers);
}

echo "[BLUEPRINT] " . ($sent ? "✅ Delivered to $admin_email" : "⚠ Email failed") . "\nDone.\n";
