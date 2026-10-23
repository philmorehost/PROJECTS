<?php session_start();
include("../func/bc-admin-config.php");
include_once("../func/bc-ai-engine.php");
require_once "../func/bc-demo-mode.php";

$title = "AI Marketing Studio";
$vendor_id = $get_logged_admin_details['id'];

// Block bulk email sends in demo mode
if (isset($_POST['send-campaign']) && bc_is_demo_mode($connection_server)) {
    bc_demo_block_feature('AI Marketing Email Campaign');
}
$ai_engine = ai_engine();
$assigned_model_raw = $get_logged_admin_details['ai_model_assigned'] ?: getSuperAdminOption('ai_default_model', '');
$assigned_model = $ai_engine->isModelCompatible($assigned_model_raw) ? $assigned_model_raw : $ai_engine->getDefaultModel();

// Business Name Fallback Logic
$site_q = mysqli_query($connection_server, "SELECT site_title FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
$site_data = mysqli_fetch_assoc($site_q);
$biz_name = $get_logged_admin_details['company_name'] ?? ($site_data['site_title'] ?? 'Our VTU Platform');

$current_bg = $get_logged_admin_details['ai_marketing_bg'] ?? 'midnight';
$copilot_cost_display = (int)getSuperAdminOption('ai_marketing_copilot_cost', 3);

// ─── AJAX: AI "Help me write" / "Refine content" ─────────────────────────────
if (isset($_GET['action']) && in_array($_GET['action'], ['ai_write', 'ai_refine'], true)) {
    header('Content-Type: application/json');

    if (empty($_SESSION['admin_session'])) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please refresh the page.']);
        exit;
    }

    $token_bal = (int)($get_logged_admin_details['ai_token_balance'] ?? 0);
    // Flat platform-wide rate set by the super admin (AI Management > Economics), separate
    // from ai_per_tx_cost (the general per-vendor chat fee used elsewhere, e.g. AI Assistant).
    $per_tx_cost = $copilot_cost_display;
    if ($token_bal < $per_tx_cost) {
        echo json_encode(['status' => 'error', 'message' => "Insufficient AI tokens. The Marketing Copilot costs $per_tx_cost token(s) per use — please top up in AI Suite.", 'copilot_cost' => $per_tx_cost, 'tokens_remaining' => $token_bal]);
        exit;
    }

    $action = $_GET['action'];
    $raw_input = trim($_POST['content'] ?? '');
    if (empty($raw_input)) {
        echo json_encode(['status' => 'error', 'message' => $action === 'ai_write' ? 'Please describe what you want to promote.' : 'There is no content to refine yet.']);
        exit;
    }

    $safe_input = bc_firewall_prompt($raw_input);
    if ($safe_input === false) {
        echo json_encode(['status' => 'error', 'message' => 'Your request contains content that cannot be processed. Please describe a VTU business promotion.']);
        exit;
    }

    if ($action === 'ai_write') {
        $prompt = "You are a professional VTU marketing copywriter for '$biz_name' (website: {$get_logged_admin_details['website_url']}).
Write compelling marketing email copy for this brief: \"$safe_input\"
Respond with a catchy subject line on the very first line, prefixed exactly with \"SUBJECT:\", then a blank line, then the email body.
Keep the body engaging and mobile-friendly, with a clear call to action pointing to the website. Use light formatting only
(short paragraphs, <b> for emphasis) — do not output a full HTML document, just the body content.";
    } else {
        $refine_instructions = [
            'shorter'    => 'Make this significantly shorter and punchier while keeping the core message and call to action.',
            'persuasive' => 'Rewrite this to be more persuasive and urgent, while staying honest and professional.',
            'tone'       => 'Rewrite this with a warmer, more conversational tone.',
            'improve'    => 'Improve the clarity, grammar, and persuasiveness of this content without changing its meaning.',
        ];
        $refine_mode = trim($_POST['refine_mode'] ?? 'improve');
        $instruction = $refine_instructions[$refine_mode] ?? $refine_instructions['improve'];
        $prompt = "You are a professional marketing editor. $instruction
If a line starting with \"SUBJECT:\" is present, keep that same format at the top of your response. Return only the revised content, with no explanations before or after it.

Content to revise:
$safe_input";
    }

    $start_time = microtime(true);
    $result = $ai_engine->chat($assigned_model, $prompt, ['temperature' => 0.85]);
    $duration = round((microtime(true) - $start_time) * 1000);

    if ($result['status'] === 'success') {
        $generated = trim($result['response']);
        $tokens = strlen($prompt . $generated) / 4;
        $esc_res = mysqli_real_escape_string($connection_server, $generated);
        $esc_label = mysqli_real_escape_string($connection_server, ($action === 'ai_write' ? 'Marketing Write' : 'Marketing Refine') . ': ' . substr($safe_input, 0, 150));
        mysqli_query($connection_server, "INSERT INTO sas_ai_transactions (vendor_id, username, prompt, response, tokens_burned, status, duration_ms) VALUES ('$vendor_id', 'admin_{$get_logged_admin_details['email']}', '$esc_label', '$esc_res', '$tokens', 'success', '$duration')");

        mysqli_query($connection_server, "UPDATE sas_vendors SET ai_token_balance = ai_token_balance - $per_tx_cost WHERE id='$vendor_id'");
        $get_logged_admin_details['ai_token_balance'] = $token_bal - $per_tx_cost;

        // Split off a leading "SUBJECT:" line if the model produced one, so the client can
        // route it straight into the subject field instead of leaving it in the body.
        $subject_out = '';
        $body_out = $generated;
        if (preg_match('/^\s*SUBJECT:\s*(.+)$/mi', $generated, $m)) {
            $subject_out = trim($m[1]);
            $body_out = trim(preg_replace('/^\s*SUBJECT:\s*.+$/mi', '', $generated, 1));
        }

        echo json_encode([
            'status'           => 'success',
            'subject'          => $subject_out,
            'body'             => $body_out,
            'tokens_remaining' => $get_logged_admin_details['ai_token_balance'],
            'tokens_charged'   => $per_tx_cost,
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'AI Error: ' . ($result['message'] ?? 'Unable to connect to AI engine.')]);
    }
    exit;
}

// ─── AJAX: live campaign progress (polled by the report panel) ──────────────
if (isset($_GET['action']) && $_GET['action'] === 'campaign_progress') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['campaign_id'] ?? 0);
    $progress = bc_get_mail_campaign_progress($connection_server, $cid, $vendor_id);
    echo json_encode($progress ?: ['status' => 'error', 'message' => 'Campaign not found.']);
    exit;
}

// ─── AJAX: cancel a queued/sending campaign (stops remaining emails) ────────
if (isset($_GET['action']) && $_GET['action'] === 'cancel_campaign') {
    header('Content-Type: application/json');
    $cid = (int)($_GET['campaign_id'] ?? 0);
    $result = bc_cancel_mail_campaign($connection_server, $cid, $vendor_id, false);
    echo json_encode($result);
    exit;
}

if (isset($_POST['set-bg'])) {
    $new_bg = bc_sanitize($_POST['bg_name'] ?? 'midnight');
    mysqli_query($connection_server, "UPDATE sas_vendors SET ai_marketing_bg='$new_bg' WHERE id='$vendor_id'");
    header("Location: AIMarketing.php"); exit();
    exit;
}

if (isset($_POST['send-campaign'])) {
    $subject = trim($_POST["subject"] ?? '');
    $body    = trim($_POST["body"] ?? '');
    $status_cohort = trim($_POST['status_cohort'] ?? '');
    $account_level = (int)($_POST['account_level'] ?? 0);
    $external_raw  = [$_POST['paste_emails'] ?? ''];

    if (!empty($subject) && !empty($body)) {
        $recipients = bc_resolve_campaign_recipients($connection_server, $vendor_id, $status_cohort, $account_level, $external_raw);
        if (!empty($recipients)) {
            $campaign_id = bc_enqueue_mail_campaign($connection_server, $vendor_id, $subject, $body, $recipients, 'aimarketing', $biz_name, $get_logged_admin_details['website_url']);
            $_SESSION["product_purchase_response"] = "Campaign queued! Sending to " . count($recipients) . " recipient(s) in the background — no need to wait here.";
            header("Location: AIMarketing.php?campaign=$campaign_id");
        } else {
            $_SESSION["product_purchase_response"] = "Error: No valid recipients found for the selected targeting.";
            header("Location: AIMarketing.php");
        }
    } else {
        $_SESSION["product_purchase_response"] = "Error: Subject and Marketing Content are required.";
        header("Location: AIMarketing.php");
    }
    exit;
}

$bg_templates = [
    'midnight' => ['name' => 'Midnight', 'css' => 'linear-gradient(135deg, #0f172a, #1e293b)'],
    'solar'    => ['name' => 'Solar', 'css' => 'linear-gradient(135deg, #f97316, #ef4444)'],
    'emerald'  => ['name' => 'Emerald', 'css' => 'linear-gradient(135deg, #065f46, #064e3b)'],
    'royal'    => ['name' => 'Royal', 'css' => 'linear-gradient(135deg, #6d28d9, #4c1d95)'],
    'neon'     => ['name' => 'Neon', 'css' => '#000', 'border' => '2px solid #3b82f6'],
    'glass'    => ['name' => 'Glass', 'css' => 'rgba(255,255,255,0.1)', 'blur' => 'backdrop-filter: blur(10px); color: #000;']
];

// Preset "help me write" chips — one per service the platform actually sells (matches the
// $service_map in web/ai-handler.php: airtime, data, electricity, cable, betting, exam), plus
// two general campaign types.
$prompt_chips = [
    'Weekend data promo'      => 'Promote a weekend discount on data bundles running from Friday to Sunday',
    'New service announcement'=> 'Announce that we now support a new VTU service and invite users to try it',
    'Win back dormant users'  => "Re-engage users who haven't made a purchase in a while with a welcome-back offer",
    'Referral bonus push'     => 'Encourage users to refer friends and earn a referral bonus for every signup',
    'Price drop alert'        => 'Announce a price drop on airtime and data rates effective immediately',
    'Festive greeting'        => 'Send a warm festive/holiday greeting to customers with a special seasonal offer',
];

$recent_campaigns = bc_get_recent_mail_campaigns($connection_server, $vendor_id, 10);
$active_campaign_id = (int)($_GET['campaign'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo $title; ?></title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .studio-header { background: linear-gradient(135deg, #6366f1, #a855f7); border-radius: 2rem; padding: 3rem; color: white; margin-bottom: 2rem; position: relative; overflow: hidden; }
        .studio-header::after { content: '✨'; position: absolute; right: 5%; top: 50%; transform: translateY(-50%); font-size: 5rem; opacity: 0.2; }
        .fintech-card { border: none; border-radius: 2rem; box-shadow: 0 10px 40px rgba(0,0,0,0.05); background: #fff; transition: 0.3s; }
        .fintech-card:hover { transform: translateY(-5px); }
        .gen-box { background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 1.5rem; padding: 2rem; white-space: pre-wrap; position: relative; }
        .flyer-preview { width: 100%; max-width: 350px; aspect-ratio: 9/16; border-radius: 2.5rem; background: <?php echo $bg_templates[$current_bg]['css']; ?>; padding: 2rem; display: flex; flex-direction: column; justify-content: center; text-align: center; color: white; position: relative; border: 8px solid #1e293b; <?php echo $bg_templates[$current_bg]['blur'] ?? ''; ?> }
        .flyer-overlay { background: rgba(255,255,255,0.1); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.2); border-radius: 1.5rem; padding: 1.5rem; height: 80%; display: flex; flex-direction: column; justify-content: space-between; }
        .nav-pills-studio .nav-link { border-radius: 2rem; padding: 0.8rem 2rem; font-weight: 700; color: #64748b; }
        .nav-pills-studio .nav-link.active { background: #6366f1 !important; color: white !important; }
        .prompt-chip { border: 1.5px solid #e2e8f0; background: #fff; border-radius: 2rem; padding: 0.4rem 1rem; font-size: 0.8rem; font-weight: 600; color: #475569; cursor: pointer; transition: 0.2s; }
        .prompt-chip:hover { border-color: #6366f1; color: #6366f1; background: #f5f3ff; }
        .refine-btn { border-radius: 2rem; font-size: 0.78rem; font-weight: 700; }
        .campaign-progress-bar { height: 10px; border-radius: 1rem; }
        .campaign-row { font-size: 0.85rem; }
        .ai-spinner { display: inline-block; width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: ai-spin 0.7s linear infinite; }
        @keyframes ai-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<?php include("../func/bc-admin-header.php"); ?>
<div class="pagetitle"><h1>AI Marketing Studio</h1></div>

<section class="section">
    <div class="studio-header shadow">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="fw-bold mb-0">Marketing Intelligence Hub</h2>
            <div class="bg-white bg-opacity-20 rounded-pill px-4 py-2 small fw-bold">
                <i class="bi bi-coin me-1"></i> <span id="tokenBalanceLabel"><?php echo number_format($get_logged_admin_details['ai_token_balance'] ?? 0); ?></span> Tokens
            </div>
        </div>
        <p class="opacity-75 mb-0">Harness the power of generative AI to create viral campaigns for <strong><?php echo $biz_name; ?></strong></p>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card fintech-card mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Help Me Write</h5>
                    <div class="mb-3 d-flex flex-wrap gap-2">
                        <?php foreach ($prompt_chips as $label => $chip_prompt): ?>
                        <button type="button" class="prompt-chip" data-prompt="<?php echo htmlspecialchars($chip_prompt, ENT_QUOTES); ?>"><?php echo htmlspecialchars($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">What do you want to promote?</label>
                        <textarea id="briefInput" class="form-control rounded-4 p-3 bg-light border-0" rows="4" placeholder="e.g. Promote our new 1GB data plan at a discounted weekend rate"></textarea>
                    </div>
                    <button type="button" id="btnGenerate" class="btn btn-primary w-100 rounded-pill py-3 fw-bold">Generate Magic 🪄</button>
                    <div class="text-center text-muted small mt-2"><i class="bi bi-coin me-1"></i>Copilot costs <strong><?php echo $copilot_cost_display; ?> token<?php echo $copilot_cost_display == 1 ? '' : 's'; ?></strong> per generate or refine</div>
                </div>
            </div>

            <div class="card fintech-card">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Flyer Backgrounds</h5>
                    <div class="row g-2">
                        <?php foreach($bg_templates as $key => $tpl): ?>
                        <div class="col-4 text-center">
                            <form method="post">
                                <input type="hidden" name="bg_name" value="<?php echo $key; ?>">
                                <div onclick="this.parentElement.submit()" class="rounded-4 mb-1" style="height:50px; background:<?php echo $tpl['css']; ?>; cursor:pointer; border:2px solid <?php echo $key==$current_bg?'#6366f1':'transparent'; ?>"></div>
                                <small class="fw-bold" style="font-size:10px;"><?php echo $tpl['name']; ?></small>
                                <input type="hidden" name="set-bg" value="1">
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card fintech-card mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">Ad Copy</h5>
                        <button type="button" class="btn btn-light btn-sm rounded-pill px-3" onclick="navigator.clipboard.writeText(document.getElementById('adText').value); this.innerHTML='<i class=\'bi bi-check2\'></i> Copied'; setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i>',1500)"><i class="bi bi-clipboard"></i></button>
                    </div>

                    <form method="post" action="" id="campaignForm">
                        <div class="mb-3 d-flex flex-wrap gap-2" id="refineBar" style="display:none !important;">
                            <button type="button" class="btn btn-outline-secondary refine-btn" data-refine="shorter">✂️ Shorter</button>
                            <button type="button" class="btn btn-outline-secondary refine-btn" data-refine="persuasive">🔥 More Persuasive</button>
                            <button type="button" class="btn btn-outline-secondary refine-btn" data-refine="tone">😊 Warmer Tone</button>
                            <button type="button" class="btn btn-outline-secondary refine-btn" data-refine="improve">✨ Improve</button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Email Subject</label>
                            <input type="text" id="genSubject" name="subject" class="form-control rounded-3 py-2" placeholder="e.g. Exciting Offers Inside!">
                        </div>

                        <div class="mb-4">
                            <textarea class="gen-box form-control shadow-none" id="adText" name="body" rows="8" style="width: 100%; resize: vertical;" placeholder="Your generated ad copy will appear here — or write your own."></textarea>
                        </div>

                        <div class="p-4 bg-light rounded-4 border border-dashed mb-3">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-send-fill me-2"></i>Campaign Dispatch</h6>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold">Registered Users</label>
                                    <select name="status_cohort" class="form-select rounded-3 py-2">
                                        <option value="">-- Do not send to internal users --</option>
                                        <option value="all">All Users</option>
                                        <option value="active">Active Users Only</option>
                                        <option value="blocked">Blocked Users Only</option>
                                        <option value="deleted">Deleted Users Only</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold">Account Level</label>
                                    <select name="account_level" class="form-select rounded-3 py-2">
                                        <option value="0">Any Level</option>
                                        <option value="1">Smart Users Only</option>
                                        <option value="2">Agent Users Only</option>
                                        <option value="3">API Users Only</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">External Emails (comma or space separated)</label>
                                <textarea name="paste_emails" class="form-control rounded-3" rows="1" placeholder="user1@email.com, user2@email.com"></textarea>
                            </div>

                            <button type="submit" name="send-campaign" class="btn btn-success w-100 rounded-pill py-3 fw-bold mt-2 shadow-sm"><i class="bi bi-envelope-paper-fill me-2"></i> Launch Email Campaign</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card fintech-card mb-4 <?php echo $active_campaign_id ? '' : 'd-none'; ?>" id="progressCard">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0"><i class="bi bi-broadcast-pin me-2"></i>Live Send Report</h6>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary-subtle text-primary" id="progressStatus">—</span>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="cancelActiveBtn">
                                <i class="bi bi-x-circle me-1"></i>Cancel Campaign
                            </button>
                        </div>
                    </div>
                    <div class="progress campaign-progress-bar mb-2">
                        <div class="progress-bar bg-success" id="progressBarSent" style="width:0%"></div>
                        <div class="progress-bar bg-danger" id="progressBarFailed" style="width:0%"></div>
                    </div>
                    <div class="small text-muted">
                        <span id="progressSent">0</span> sent &middot;
                        <span id="progressFailed">0</span> failed &middot;
                        <span id="progressPending">0</span> pending of
                        <span id="progressTotal">0</span> total
                    </div>
                </div>
            </div>

            <div class="card fintech-card mb-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Recent Campaigns</h6>
                    <?php if (empty($recent_campaigns)): ?>
                        <p class="text-muted small mb-0">No campaigns sent yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm campaign-row align-middle mb-0">
                                <thead class="text-muted"><tr><th>Subject</th><th>Status</th><th>Sent</th><th>Failed</th><th>Total</th><th>Action</th></tr></thead>
                                <tbody>
                                <?php foreach ($recent_campaigns as $c): ?>
                                    <tr>
                                        <td class="text-truncate" style="max-width:220px;"><?php echo htmlspecialchars($c['subject']); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['status']); ?></span></td>
                                        <td><?php echo (int)$c['sent_count']; ?></td>
                                        <td><?php echo (int)$c['failed_count']; ?></td>
                                        <td><?php echo (int)$c['total_count']; ?></td>
                                        <td>
                                            <?php if (in_array($c['status'], ['queued', 'sending'], true)): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger cancel-campaign-btn" data-id="<?php echo (int)$c['id']; ?>" data-subject="<?php echo htmlspecialchars($c['subject'], ENT_QUOTES); ?>">Cancel</button>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card fintech-card p-5 d-flex align-items-center justify-content-center bg-light overflow-hidden">
                <div class="flyer-preview shadow-lg">
                    <div class="flyer-overlay">
                        <h4 class="fw-bold"><?php echo strtoupper($biz_name); ?></h4>
                        <div class="flex-grow-1 d-flex align-items-center justify-content-center overflow-hidden my-3">
                            <p class="small mb-0" id="flyerText" style="font-size: 0.9rem; line-height: 1.4;">
                                Generate professional marketing assets in seconds.
                            </p>
                        </div>
                        <div class="bg-white text-dark py-2 rounded-4 fw-bold small" style="word-break: break-all;"><?php echo $get_logged_admin_details['website_url']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php include("../func/bc-admin-footer.php"); ?>
<script>
(function() {
    const briefInput = document.getElementById('briefInput');
    const adText = document.getElementById('adText');
    const genSubject = document.getElementById('genSubject');
    const btnGenerate = document.getElementById('btnGenerate');
    const refineBar = document.getElementById('refineBar');
    const flyerText = document.getElementById('flyerText');
    const tokenLabel = document.getElementById('tokenBalanceLabel');

    document.querySelectorAll('.prompt-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            briefInput.value = chip.dataset.prompt;
            briefInput.focus();
        });
    });

    function updateFlyer() {
        const text = adText.value.trim();
        flyerText.textContent = text ? text.substring(0, 600) : 'Generate professional marketing assets in seconds.';
    }
    adText.addEventListener('input', updateFlyer);

    function toggleRefineBar() {
        refineBar.style.setProperty('display', adText.value.trim() ? 'flex' : 'none', 'important');
    }
    adText.addEventListener('input', toggleRefineBar);
    toggleRefineBar();

    function setBusy(btn, busy, busyLabel) {
        if (busy) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="ai-spinner me-2"></span>' + busyLabel;
            btn.disabled = true;
        } else {
            btn.innerHTML = btn.dataset.originalHtml || btn.innerHTML;
            btn.disabled = false;
        }
    }

    function callAI(action, body, btn, busyLabel) {
        setBusy(btn, true, busyLabel);
        const form = new URLSearchParams(body);
        fetch('AIMarketing.php?action=' + action, { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                setBusy(btn, false);
                if (data.status === 'success') {
                    if (data.subject) genSubject.value = data.subject;
                    adText.value = data.body;
                    updateFlyer();
                    toggleRefineBar();
                    if (typeof data.tokens_remaining !== 'undefined') tokenLabel.textContent = data.tokens_remaining;
                } else {
                    alert(data.message || 'Something went wrong. Please try again.');
                }
            })
            .catch(() => {
                setBusy(btn, false);
                alert('Network error. Please try again.');
            });
    }

    btnGenerate.addEventListener('click', function() {
        const brief = briefInput.value.trim();
        if (!brief) { briefInput.focus(); return; }
        callAI('ai_write', { content: brief }, btnGenerate, 'Writing...');
    });

    document.querySelectorAll('.refine-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const content = adText.value.trim();
            if (!content) return;
            callAI('ai_refine', { content: content, refine_mode: btn.dataset.refine }, btn, 'Refining...');
        });
    });

    // ─── Live campaign progress polling ──────────────────────────────────
    const activeCampaignId = <?php echo (int)$active_campaign_id; ?>;
    if (activeCampaignId > 0) {
        const progressCard = document.getElementById('progressCard');
        const barSent = document.getElementById('progressBarSent');
        const barFailed = document.getElementById('progressBarFailed');
        const elSent = document.getElementById('progressSent');
        const elFailed = document.getElementById('progressFailed');
        const elPending = document.getElementById('progressPending');
        const elTotal = document.getElementById('progressTotal');
        const elStatus = document.getElementById('progressStatus');
        const cancelActiveBtn = document.getElementById('cancelActiveBtn');

        function stopPolling() { cancelActiveBtn.disabled = true; cancelActiveBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Cancelled'; }

        cancelActiveBtn.addEventListener('click', function() {
            if (!confirm('Cancel this campaign? Emails that have not been sent yet will not be sent.')) return;
            cancelActiveBtn.disabled = true;
            cancelActiveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Cancelling...';
            fetch('AIMarketing.php?action=cancel_campaign&campaign_id=' + activeCampaignId)
                .then(r => r.json())
                .then(data => {
                    alert(data.message || (data.success ? 'Campaign cancelled.' : 'Could not cancel campaign.'));
                    if (data.success) { stopPolling(); setTimeout(() => location.reload(), 1200); }
                    else { cancelActiveBtn.disabled = false; cancelActiveBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Cancel Campaign'; }
                })
                .catch(() => {
                    cancelActiveBtn.disabled = false;
                    cancelActiveBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i>Cancel Campaign';
                });
        });

        function poll() {
            fetch('AIMarketing.php?action=campaign_progress&campaign_id=' + activeCampaignId)
                .then(r => r.json())
                .then(data => {
                    if (!data || data.status === 'error') return;
                    const total = data.total || 0;
                    const sentPct = total ? (data.sent / total * 100) : 0;
                    const failedPct = total ? (data.failed / total * 100) : 0;
                    barSent.style.width = sentPct + '%';
                    barFailed.style.width = failedPct + '%';
                    elSent.textContent = data.sent;
                    elFailed.textContent = data.failed;
                    elPending.textContent = data.pending;
                    elTotal.textContent = total;
                    elStatus.textContent = data.status;
                    if (data.status === 'cancelled') { stopPolling(); return; }
                    if (data.status !== 'completed') {
                        setTimeout(poll, 3000);
                    }
                })
                .catch(() => setTimeout(poll, 5000));
        }
        poll();
    }

    // ─── Cancel buttons in the Recent Campaigns table ─────────────────────
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.cancel-campaign-btn');
        if (!btn) return;
        const cid = btn.dataset.id;
        if (!confirm('Cancel campaign "' + (btn.dataset.subject || '') + '"? Emails that have not been sent yet will not be sent.')) return;
        btn.disabled = true;
        btn.textContent = 'Cancelling...';
        fetch('AIMarketing.php?action=cancel_campaign&campaign_id=' + cid)
            .then(r => r.json())
            .then(data => {
                alert(data.message || (data.success ? 'Campaign cancelled.' : 'Could not cancel campaign.'));
                if (data.success) { location.reload(); }
                else { btn.disabled = false; btn.textContent = 'Cancel'; }
            })
            .catch(() => { btn.disabled = false; btn.textContent = 'Cancel'; });
    });
})();
</script>
</body>
</html>
