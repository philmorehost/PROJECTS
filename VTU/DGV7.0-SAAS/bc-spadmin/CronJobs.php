<?php session_start();
include("../func/bc-spadmin-config.php");

$current_page = "CronJobs.php";

// Resolve helper values once for the command snippets below.
$php_bin = '/usr/bin/php';
$logs_dir = dirname(__FILE__) . '/../logs';
$root = dirname(__FILE__) . '/..';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Platform Cron Jobs | Super Admin</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <style>
        .cron-header { background: linear-gradient(135deg, #0f172a, #1e293b); color: white; border-radius: 1.5rem; padding: 3rem 2rem; position: relative; overflow: hidden; }
        .cron-header::after { content: '⏱️'; position: absolute; right: 5%; top: 50%; transform: translateY(-50%); font-size: 5rem; opacity: 0.1; }
        .code-block { background: #1e293b; color: #f8fafc; border-radius: 0.75rem; padding: 1.1rem 1.25rem; font-family: 'Courier New', Courier, monospace; position: relative; font-size: 0.82rem; border: 1px solid #334155; word-break: break-all; }
        .copy-btn { position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.1); border: none; color: white; padding: 4px 12px; border-radius: 4px; font-size: 0.7rem; transition: 0.2s; }
        .copy-btn:hover { background: rgba(255,255,255,0.2); }
        .schedule-badge { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.02em; }
    </style>
</head>
<body>
<?php include("../func/bc-spadmin-header.php"); ?>
<div class="pagetitle"><h1>PLATFORM CRON JOBS</h1></div>

<section class="section">
    <div class="cron-header mb-4 shadow">
        <h2 class="fw-bold mb-1">All Platform Cron Jobs — One Page</h2>
        <p class="opacity-75 mb-0">Set each entry once in your cPanel/VPS crontab. They run for <strong>all vendors automatically</strong>, so you never need to configure them per vendor.</p>
    </div>

    <div class="alert alert-warning border-0 rounded-4 p-3 small mb-4">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        The commands below assume the PHP CLI binary is at <code>/usr/bin/php</code>. On some servers it is <code>/usr/local/bin/php</code> — run <code>which php</code> over SSH to confirm and adjust if needed.
    </div>

    <div class="row g-4">
        <!-- ── Core business cronjobs ─────────────────────────────────────── -->
        <div class="col-12">
            <div class="card border-0 rounded-4 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-currency-exchange text-primary me-2"></i>Core Business Cronjobs</h5>

                    <!-- Bulk Queue -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Bulk Airtime/Data Queue Processor <span class="badge bg-primary bg-opacity-10 text-primary schedule-badge">Every 1 minute</span></h6>
                        <p class="text-muted small mb-2">Keeps bulk airtime/data batches processing in the background even if a customer closes their browser or their connection drops mid-submission.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>* * * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/process_bulk_queue.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/bulk_queue.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <!-- Requery -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Cron Requery Processor <span class="badge bg-primary bg-opacity-10 text-primary schedule-badge">Every 1 minute</span></h6>
                        <p class="text-muted small mb-2">Re-checks pending transactions for every vendor. Detailed run and provider diagnostics are written to <code>logs/requery_queue_report.log</code>; standard output and PHP errors are also appended to <code>logs/requery_queue.log</code>. The report redacts API credentials, email addresses, and long number sequences.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>* * * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/process_requery_queue.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/requery_queue.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <!-- Mail queue -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Bulk Email Queue Processor <span class="badge bg-primary bg-opacity-10 text-primary schedule-badge">Every 1 minute</span></h6>
                        <p class="text-muted small mb-2">Sends queued campaign/notification emails in the background (AI Marketing Studio / Send Mail).</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>* * * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/process_mail_queue.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/mail_queue.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <!-- Crypto -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Crypto Automation <span class="badge bg-info bg-opacity-10 text-info schedule-badge">Every 2-5 minutes</span></h6>
                        <p class="text-muted small mb-2">Automates crypto deposit verification.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>*/5 * * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/func/crypto-cron.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/crypto.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <!-- Sender ID sync -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Sender ID Auto-Sync <span class="badge bg-info bg-opacity-10 text-info schedule-badge">Every 1 hour</span></h6>
                        <p class="text-muted small mb-2">Automatically updates the status of pending Sender IDs from PhilmoreSMS.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 * * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/sync_sender_ids.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/sender_sync.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <!-- Notifications -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">User Notifications (Low Balance, Inactivity &amp; Sales) <span class="badge bg-info bg-opacity-10 text-info schedule-badge">Every 6-12 hours</span></h6>
                        <p class="text-muted small mb-2">Sends low balance alerts (weekly), inactivity reminders (7+ days), and weekly sales reports.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 */6 * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/user_notifications.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/notifications.log 2&gt;&amp;1</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Subscription & maintenance cronjobs ─────────────────────────── -->
        <div class="col-12">
            <div class="card border-0 rounded-4 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat text-success me-2"></i>Subscription &amp; Platform Maintenance</h5>

                    <!-- Subscription checking -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Automated Subscription Checking <span class="badge bg-success bg-opacity-10 text-success schedule-badge">Daily at 00:00</span></h6>
                        <p class="text-muted small mb-2">Automates vendor subscription status updates and renewals.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 0 * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/check_expirations.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/subscriptions.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <!-- Subscription reminders -->
                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Subscription Reminders <span class="badge bg-success bg-opacity-10 text-success schedule-badge">Daily at 00:15</span></h6>
                        <p class="text-muted small mb-2">Emails vendors before their subscription expires (7, 3 and 1 day remaining).</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>15 0 * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/subscription_reminders.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/subscription_reminders.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <!-- OTA updates -->
                    <div class="mb-0">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">System Automated Updates (OTA) <span class="badge bg-success bg-opacity-10 text-success schedule-badge">Daily</span></h6>
                        <p class="text-muted small mb-2">Automates over-the-air platform update installation with email notifications.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 1 * * * <?php echo $php_bin; ?> <?php echo realpath(dirname(__FILE__) . '/cron_update.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/ota_updates.log 2&gt;&amp;1</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── AI & monitoring cronjobs ────────────────────────────────────── -->
        <div class="col-12">
            <div class="card border-0 rounded-4 shadow-sm">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-cpu text-warning me-2"></i>AI &amp; Monitoring Cronjobs</h5>

                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">API Aggregator Monitor <span class="badge bg-warning bg-opacity-10 text-warning schedule-badge">Every 5 minutes</span></h6>
                        <p class="text-muted small mb-2">Monitors API aggregator health.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>*/5 * * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/aggregator_monitor.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/agg_mon.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">AI Daily Briefing <span class="badge bg-warning bg-opacity-10 text-warning schedule-badge">Daily at 07:00</span></h6>
                        <p class="text-muted small mb-2">Generates the daily AI briefing.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 7 * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/ai_daily_briefing.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/daily.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">Dormant User Re-engagement <span class="badge bg-warning bg-opacity-10 text-warning schedule-badge">Daily at 10:00</span></h6>
                        <p class="text-muted small mb-2">Re-engages dormant users.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 10 * * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/dormant_user_alert.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/dormant.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">AI Monthly Platform Audit <span class="badge bg-warning bg-opacity-10 text-warning schedule-badge">1st of month at 08:00</span></h6>
                        <p class="text-muted small mb-2">Runs the monthly AI platform audit.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 8 1 * * <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/ai_monthly_blueprint.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/audit.log 2&gt;&amp;1</code>
                        </div>
                    </div>

                    <div class="mb-0">
                        <h6 class="fw-bold small text-uppercase d-flex align-items-center gap-2">AI Weekly Health Scan &amp; Enhancement Plan <span class="badge bg-warning bg-opacity-10 text-warning schedule-badge">Every Monday at 08:00</span></h6>
                        <p class="text-muted small mb-2">Scans PHP error logs, app logs, DB audit log, failed transactions and password-reset brute-force attempts — emails an AI error report and an enhancement plan to the admin.</p>
                        <div class="code-block"><button class="copy-btn" onclick="copyCode(this)">COPY</button>
                            <code>0 8 * * 1 <?php echo $php_bin; ?> <?php echo realpath($root . '/cron/ai_weekly_error_report.php'); ?> &gt;&gt; <?php echo $logs_dir; ?>/weekly_health.log 2&gt;&amp;1</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
function copyCode(btn) {
    const code = btn.nextElementSibling ? btn.nextElementSibling.innerText : '';
    if (!code) return;
    navigator.clipboard.writeText(code).then(() => {
        const originalText = btn.innerText;
        btn.innerText = 'COPIED!';
        btn.classList.add('bg-success');
        setTimeout(() => {
            btn.innerText = originalText;
            btn.classList.remove('bg-success');
        }, 2000);
    });
}
</script>

<?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
