<?php session_start();
include("../func/bc-admin-config.php");
include_once("../func/bc-ai-engine.php");
require_once "../func/bc-demo-mode.php";

// Block bulk email sends in demo mode
if (isset($_POST["send-mail"]) && bc_is_demo_mode($connection_server)) {
    bc_demo_block_feature('Broadcast Email');
}

$vid = $get_logged_admin_details['id'];
$ai_engine = ai_engine();
$assigned_model_raw = $get_logged_admin_details['ai_model_assigned'] ?: getSuperAdminOption('ai_default_model', '');
$assigned_model = $ai_engine->isModelCompatible($assigned_model_raw) ? $assigned_model_raw : $ai_engine->getDefaultModel();

$site_q = mysqli_query($connection_server, "SELECT site_title FROM sas_site_details WHERE vendor_id='$vid' LIMIT 1");
$site_data = mysqli_fetch_assoc($site_q);
$biz_name = $get_logged_admin_details['company_name'] ?? ($site_data['site_title'] ?? 'Our VTU Platform');
$copilot_cost_display = (int)getSuperAdminOption('ai_marketing_copilot_cost', 3);

// AJAX Handler for Drafts / AI / Live Progress
if (isset($_GET['action'])) {
    // Security: Verify Vendor session
    if (!isset($_SESSION['admin_session'])) {
        header("HTTP/1.1 403 Forbidden");
        echo json_encode(['error' => 'Unauthorized access']);
        exit;
    }

    if ($_GET['action'] == 'save_draft') {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        $subject = mysqli_real_escape_string($connection_server, $data['subject']);
        $mailto = mysqli_real_escape_string($connection_server, $data['mailto']);
        $body_html = mysqli_real_escape_string($connection_server, $data['body_html']);
        $body_json = mysqli_real_escape_string($connection_server, $data['body_json']);

        $check = mysqli_query($connection_server, "SELECT id FROM sas_mail_drafts WHERE is_super_admin=0 AND vendor_id='$vid' LIMIT 1");
        if (mysqli_num_rows($check) > 0) {
            mysqli_query($connection_server, "UPDATE sas_mail_drafts SET subject='$subject', mailto='$mailto', body_html='$body_html', body_json='$body_json' WHERE is_super_admin=0 AND vendor_id='$vid'");
        } else {
            mysqli_query($connection_server, "INSERT INTO sas_mail_drafts (vendor_id, subject, mailto, body_html, body_json, is_super_admin) VALUES ('$vid', '$subject', '$mailto', '$body_html', '$body_json', 0)");
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_GET['action'] == 'load_draft') {
        $res = mysqli_query($connection_server, "SELECT * FROM sas_mail_drafts WHERE is_super_admin=0 AND vendor_id='$vid' LIMIT 1");
        if ($row = mysqli_fetch_assoc($res)) {
            echo json_encode($row);
        } else {
            echo json_encode(['status' => 'empty']);
        }
        exit;
    }

    // Vendor Asset Upload Handler for GrapesJS
    if ($_GET['action'] == 'upload_asset' && isset($_FILES['files'])) {
        $upload_dir = '../uploaded-image/vendor_' . $vid . '/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $responses = [];
        foreach ($_FILES['files']['name'] as $key => $name) {
            $tmp_name = $_FILES['files']['tmp_name'][$key];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_extensions)) continue;

            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $target = $upload_dir . $filename;

            if (move_uploaded_file($tmp_name, $target)) {
                $responses[] = [
                    'src' => $web_http_host . '/uploaded-image/vendor_' . $vid . '/' . $filename,
                    'type' => 'image'
                ];
            }
        }
        echo json_encode(['data' => $responses]);
        exit;
    }

    // ─── AI: "Help me write" / "Refine content" ─────────────────────────────
    if (in_array($_GET['action'], ['ai_write', 'ai_refine'], true)) {
        header('Content-Type: application/json');

        $token_bal = (int)($get_logged_admin_details['ai_token_balance'] ?? 0);
        // Flat platform-wide rate set by the super admin (AI Management > Economics), separate
        // from ai_per_tx_cost (the general per-vendor chat fee used elsewhere, e.g. AI Assistant).
        $per_tx_cost = $copilot_cost_display;
        if ($token_bal < $per_tx_cost) {
            echo json_encode(['status' => 'error', 'message' => "Insufficient AI tokens. The Marketing Copilot costs $per_tx_cost token(s) per use — please top up in AI Suite.", 'copilot_cost' => $per_tx_cost, 'tokens_remaining' => $token_bal]);
            exit;
        }

        $ai_action = $_GET['action'];
        $raw_input = trim($_POST['content'] ?? '');
        if (empty($raw_input)) {
            echo json_encode(['status' => 'error', 'message' => $ai_action === 'ai_write' ? 'Please describe what this email should say.' : 'There is no content to refine yet.']);
            exit;
        }

        $safe_input = bc_firewall_prompt($raw_input);
        if ($safe_input === false) {
            echo json_encode(['status' => 'error', 'message' => 'Your request contains content that cannot be processed. Please describe a VTU business email.']);
            exit;
        }

        if ($ai_action === 'ai_write') {
            $prompt = "You are a professional VTU business email copywriter for '$biz_name' (website: {$get_logged_admin_details['website_url']}).
Write a complete marketing/notification email based on this brief: \"$safe_input\"
Respond with a subject line on the very first line, prefixed exactly with \"SUBJECT:\", then a blank line, then the email body as light HTML (short paragraphs, <b> for emphasis, <br> for line breaks) — no full HTML document.
You may use these personalization tags where natural: {firstname}, {lastname}, {email}, {phone}, {address}, {website}.";
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
Keep any personalization tags like {firstname} unchanged. If a line starting with \"SUBJECT:\" is present, keep that same format at the top of your response. Return only the revised content as light HTML, with no explanations before or after it.

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
            $esc_label = mysqli_real_escape_string($connection_server, ($ai_action === 'ai_write' ? 'SendMail Write' : 'SendMail Refine') . ': ' . substr($safe_input, 0, 150));
            mysqli_query($connection_server, "INSERT INTO sas_ai_transactions (vendor_id, username, prompt, response, tokens_burned, status, duration_ms) VALUES ('$vid', 'admin_{$get_logged_admin_details['email']}', '$esc_label', '$esc_res', '$tokens', 'success', '$duration')");

            mysqli_query($connection_server, "UPDATE sas_vendors SET ai_token_balance = ai_token_balance - $per_tx_cost WHERE id='$vid'");
            $get_logged_admin_details['ai_token_balance'] = $token_bal - $per_tx_cost;

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

    // ─── Live campaign progress (polled by the report panel) ────────────────
    if ($_GET['action'] == 'campaign_progress') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['campaign_id'] ?? 0);
        $progress = bc_get_mail_campaign_progress($connection_server, $cid, $vid);
        echo json_encode($progress ?: ['status' => 'error', 'message' => 'Campaign not found.']);
        exit;
    }

    // ─── Cancel a queued/sending campaign (stops remaining emails) ──────────
    if ($_GET['action'] == 'cancel_campaign') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['campaign_id'] ?? 0);
        $result = bc_cancel_mail_campaign($connection_server, $cid, $vid, false);
        echo json_encode($result);
        exit;
    }
}

if (isset($_POST["send-mail"])) {
    $subject = trim($_POST["subject"]);
    $body = trim($_POST["body"]);
    $status_cohort = trim($_POST['status_cohort'] ?? '');
    $account_level = (int)($_POST['account_level'] ?? 0);

    $external_emails = [];

    // Process Paste field
    if (!empty($_POST['paste_emails'])) {
        $pasted = preg_split('/[\s,]+/', $_POST['paste_emails'], -1, PREG_SPLIT_NO_EMPTY);
        foreach ($pasted as $email) {
            if (filter_var(trim($email), FILTER_VALIDATE_EMAIL)) $external_emails[] = trim($email);
        }
    }

    // Process File Upload
    if (isset($_FILES['email_file']) && $_FILES['email_file']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['email_file']['name'], PATHINFO_EXTENSION));
        if ($ext == 'csv') {
            if (($handle = fopen($_FILES['email_file']['tmp_name'], "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    foreach ($data as $email) {
                        if (!empty($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL)) $external_emails[] = trim($email);
                    }
                }
                fclose($handle);
            }
        } else if ($ext == 'txt') {
            $content = file_get_contents($_FILES['email_file']['tmp_name']);
            $lines = preg_split('/[\s,]+/', $content, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($lines as $email) {
                if (filter_var(trim($email), FILTER_VALIDATE_EMAIL)) $external_emails[] = trim($email);
            }
        }
    }

    if (!empty($subject) && !empty($body)) {
        $recipients = bc_resolve_campaign_recipients($connection_server, $vid, $status_cohort, $account_level, $external_emails);
        if (!empty($recipients)) {
            $campaign_id = bc_enqueue_mail_campaign($connection_server, $vid, $subject, $body, $recipients, 'sendmail', $biz_name, $get_logged_admin_details['website_url']);
            $_SESSION["product_purchase_response"] = "Campaign queued! Sending to " . count($recipients) . " recipient(s) in the background — no need to wait here.";
            header("Location: SendMail.php?campaign=$campaign_id");
        } else {
            $_SESSION["product_purchase_response"] = "Error: No valid recipients found for the selected targeting.";
            header("Location: SendMail.php");
        }
    } else {
        $_SESSION["product_purchase_response"] = "Error: Subject and Body are required.";
        header("Location: SendMail.php");
    }
    exit;
}

$recent_campaigns = bc_get_recent_mail_campaigns($connection_server, $vid, 10);
$active_campaign_id = (int)($_GET['campaign'] ?? 0);

// Preset "help me write" chips — same set used in AI Marketing Studio, for consistency.
$prompt_chips = [
    'Weekend data promo'      => 'Promote a weekend discount on data bundles running from Friday to Sunday',
    'New service announcement'=> 'Announce that we now support a new VTU service and invite users to try it',
    'Win back dormant users'  => "Re-engage users who haven't made a purchase in a while with a welcome-back offer",
    'Referral bonus push'     => 'Encourage users to refer friends and earn a referral bonus for every signup',
    'Price drop alert'        => 'Announce a price drop on airtime and data rates effective immediately',
    'Festive greeting'        => 'Send a warm festive/holiday greeting to customers with a special seasonal offer',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Marketing Suite | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">

    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        .marketing-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            background: #fff;
            padding: 20px;
        }
        #editor-container {
            height: 300px;
            border-radius: 0 0 10px 10px;
        }
        .ql-toolbar {
            border-radius: 10px 10px 0 0;
            background: #f8fafc;
        }
        .prompt-chip { border: 1.5px solid #e2e8f0; background: #fff; border-radius: 2rem; padding: 0.35rem 0.9rem; font-size: 0.78rem; font-weight: 600; color: #475569; cursor: pointer; transition: 0.2s; }
        .prompt-chip:hover { border-color: #4f46e5; color: #4f46e5; background: #f5f3ff; }
        .refine-btn { border-radius: 2rem; font-size: 0.76rem; font-weight: 700; }
        .campaign-progress-bar { height: 10px; border-radius: 1rem; }
        .campaign-row { font-size: 0.85rem; }
        .ai-spinner { display: inline-block; width: 1rem; height: 1rem; border: 2px solid rgba(255,255,255,0.4); border-top-color: #fff; border-radius: 50%; animation: ai-spin 0.7s linear infinite; }
        @keyframes ai-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1>MARKETING SUITE</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Send Mail</li>
        </ol>
      </nav>
    </div>

    <section class="section">
      <div class="row">
        <div class="col-lg-12">
            <div class="marketing-card mb-4">
                <div class="card-body p-0">
                    <div class="row">
        <div class="col-md-12">
            <h5 class="fw-bold mb-3 text-primary"><i class="bi bi-megaphone me-2"></i>Send Email</h5>

            <div class="mb-3 p-3 bg-light rounded-4 border">
                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Help Me Write</label>
                <div class="mb-2 d-flex flex-wrap gap-2">
                    <?php foreach ($prompt_chips as $label => $chip_prompt): ?>
                    <button type="button" class="prompt-chip" data-prompt="<?php echo htmlspecialchars($chip_prompt, ENT_QUOTES); ?>"><?php echo htmlspecialchars($label); ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-2">
                    <input id="briefInput" type="text" class="form-control" placeholder="e.g. Announce a new referral bonus program">
                    <button type="button" id="btnGenerate" class="btn btn-primary fw-bold px-4 text-nowrap">Generate 🪄</button>
                </div>
                <div class="text-muted small mt-2"><i class="bi bi-coin me-1"></i>Copilot costs <strong><?php echo $copilot_cost_display; ?> token<?php echo $copilot_cost_display == 1 ? '' : 's'; ?></strong> per generate or refine · You have <strong id="tokenBalanceLabel"><?php echo number_format($get_logged_admin_details['ai_token_balance'] ?? 0); ?></strong> tokens</div>
            </div>

            <form id="mainForm" method="post" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold text-muted text-uppercase">Email Subject</label>
                        <input name="subject" id="subject" type="text" class="form-control" placeholder="e.g. System Update v2.0" required />
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold text-muted text-uppercase">Registered Users</label>
                        <select name="status_cohort" id="mailto" class="form-select">
                            <option value="">No internal target (External only)</option>
                            <option value="all">All Users</option>
                            <option value="active">Active Users Only</option>
                            <option value="blocked">Blocked Users Only</option>
                            <option value="deleted">Deleted Users Only</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold text-muted text-uppercase">Account Level</label>
                        <select name="account_level" class="form-select">
                            <option value="0">Any Level</option>
                            <option value="1">Smart Users Only</option>
                            <option value="2">Agent Users Only</option>
                            <option value="3">API Users Only</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">External Emails (comma or space separated)</label>
                    <input name="paste_emails" id="paste_emails" type="text" class="form-control" placeholder="user1@email.com, user2@email.com" />
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Upload CSV (Optional)</label>
                    <input type="file" name="email_file" class="form-control form-control-sm" accept=".csv,.txt" />
                    <div class="form-text" style="font-size: 11px;">Upload a CSV file containing email addresses (one per line).</div>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-bold text-muted text-uppercase mb-0">Message Content</label>
                        <div class="d-flex flex-wrap gap-2" id="refineBar" style="display:none !important;">
                            <button type="button" class="btn btn-outline-secondary btn-sm refine-btn" data-refine="shorter">✂️ Shorter</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm refine-btn" data-refine="persuasive">🔥 More Persuasive</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm refine-btn" data-refine="tone">😊 Warmer Tone</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm refine-btn" data-refine="improve">✨ Improve</button>
                        </div>
                    </div>
                    <div class="mb-2 small text-muted">
                        <strong>Supported Tags:</strong> <code>{firstname}</code>, <code>{lastname}</code>, <code>{email}</code>, <code>{phone}</code>, <code>{address}</code>, <code>{website}</code>
                    </div>
                    <div id="editor-container"></div>
                    <textarea name="body" id="body_html" style="display:none;"></textarea>
                    <input type="hidden" name="body_json" id="body_json" />
                </div>

                <div class="mb-3 d-flex gap-2">
                    <button type="submit" name="send-mail" class="btn btn-primary fw-bold px-4 py-2 rounded-pill shadow-sm">
                        <i class="bi bi-send-fill me-2"></i> Send Campaign
                    </button>
                    <button type="button" class="btn btn-outline-secondary fw-bold px-4 py-2 rounded-pill" onclick="saveDraft(event)">
                        <i class="bi bi-save me-2"></i> Save Draft
                    </button>
                </div>
            </form>
        </div>
    </div>
            </div>
        </div>
      </div>

      <div class="row <?php echo $active_campaign_id ? '' : 'd-none'; ?>" id="progressRow">
        <div class="col-lg-12">
            <div class="marketing-card mb-4">
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
      </div>

      <div class="row">
        <div class="col-lg-12">
            <div class="marketing-card">
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
                                    <td class="text-truncate" style="max-width:280px;"><?php echo htmlspecialchars($c['subject']); ?></td>
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
      </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>

    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
        var quill = new Quill('#editor-container', {
            theme: 'snow',
            placeholder: 'Write your email content here...',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });

        fetch('?action=load_draft')
            .then(res => res.json())
            .then(data => {
                if (data.body_html) {
                    quill.root.innerHTML = data.body_html;
                    document.getElementById('subject').value = data.subject || '';
                    document.getElementById('mailto').value = data.mailto || '';
                }
            });

        document.getElementById('mainForm').onsubmit = function() {
            document.getElementById('body_html').value = quill.root.innerHTML;
            document.getElementById('body_json').value = JSON.stringify(quill.getContents());
            return true;
        };

        function saveDraft(event) {
            const btn = event.currentTarget;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i> Saving...';

            fetch('?action=save_draft', {
                method: 'POST',
                body: JSON.stringify({
                    subject: document.getElementById('subject').value,
                    mailto: document.getElementById('mailto').value,
                    body_html: quill.root.innerHTML,
                    body_json: JSON.stringify(quill.getContents())
                }),
                headers: { 'Content-Type': 'application/json' }
            }).then(() => {
                btn.innerHTML = '<i class="bi bi-check me-2"></i> Saved';
                setTimeout(() => btn.innerHTML = '<i class="bi bi-save me-2"></i> Save Draft', 2000);
            });
        }

        // ─── AI: Help Me Write / Refine ───────────────────────────────────────
        (function() {
            const briefInput = document.getElementById('briefInput');
            const btnGenerate = document.getElementById('btnGenerate');
            const refineBar = document.getElementById('refineBar');
            const subjectInput = document.getElementById('subject');
            const tokenBalanceLabel = document.getElementById('tokenBalanceLabel');

            document.querySelectorAll('.prompt-chip').forEach(function(chip) {
                chip.addEventListener('click', function() {
                    briefInput.value = chip.dataset.prompt;
                    briefInput.focus();
                });
            });

            function toggleRefineBar() {
                refineBar.style.setProperty('display', quill.getText().trim() ? 'flex' : 'none', 'important');
            }
            quill.on('text-change', toggleRefineBar);
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
                fetch('?action=' + action, { method: 'POST', body: form })
                    .then(r => r.json())
                    .then(data => {
                        setBusy(btn, false);
                        if (data.status === 'success') {
                            if (data.subject) subjectInput.value = data.subject;
                            quill.root.innerHTML = data.body;
                            toggleRefineBar();
                            if (typeof data.tokens_remaining !== 'undefined' && tokenBalanceLabel) tokenBalanceLabel.textContent = data.tokens_remaining;
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
                    const content = quill.root.innerHTML;
                    if (!quill.getText().trim()) return;
                    callAI('ai_refine', { content: content, refine_mode: btn.dataset.refine }, btn, 'Refining...');
                });
            });
        })();

        // ─── Live campaign progress polling ──────────────────────────────────
        (function() {
            const activeCampaignId = <?php echo (int)$active_campaign_id; ?>;
            if (activeCampaignId <= 0) return;

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
                fetch('?action=cancel_campaign&campaign_id=' + activeCampaignId)
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
                fetch('?action=campaign_progress&campaign_id=' + activeCampaignId)
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
        })();

        // ─── Cancel buttons in the Recent Campaigns table ─────────────────────
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.cancel-campaign-btn');
            if (!btn) return;
            const cid = btn.dataset.id;
            if (!confirm('Cancel campaign "' + (btn.dataset.subject || '') + '"? Emails that have not been sent yet will not be sent.')) return;
            btn.disabled = true;
            btn.textContent = 'Cancelling...';
            fetch('?action=cancel_campaign&campaign_id=' + cid)
                .then(r => r.json())
                .then(data => {
                    alert(data.message || (data.success ? 'Campaign cancelled.' : 'Could not cancel campaign.'));
                    if (data.success) { location.reload(); }
                    else { btn.disabled = false; btn.textContent = 'Cancel'; }
                })
                .catch(() => { btn.disabled = false; btn.textContent = 'Cancel'; });
        });
    </script>
</body>
</html>
