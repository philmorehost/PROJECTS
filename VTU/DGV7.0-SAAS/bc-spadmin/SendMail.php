<?php session_start();
include("../func/bc-spadmin-config.php");

// AJAX Handler for Drafts
if (isset($_GET['action'])) {
    // Security: Explicitly verify Super Admin session
    if (!isset($_SESSION['spadmin_session'])) {
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

        $check = mysqli_query($connection_server, "SELECT id FROM sas_mail_drafts WHERE is_super_admin=1 AND vendor_id=0 LIMIT 1");
        if (mysqli_num_rows($check) > 0) {
            mysqli_query($connection_server, "UPDATE sas_mail_drafts SET subject='$subject', mailto='$mailto', body_html='$body_html', body_json='$body_json' WHERE is_super_admin=1 AND vendor_id=0");
        } else {
            mysqli_query($connection_server, "INSERT INTO sas_mail_drafts (vendor_id, subject, mailto, body_html, body_json, is_super_admin) VALUES (0, '$subject', '$mailto', '$body_html', '$body_json', 1)");
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
    if ($_GET['action'] == 'load_draft') {
        $res = mysqli_query($connection_server, "SELECT * FROM sas_mail_drafts WHERE is_super_admin=1 AND vendor_id=0 LIMIT 1");
        if ($row = mysqli_fetch_assoc($res)) {
            echo json_encode($row);
        } else {
            echo json_encode(['status' => 'empty']);
        }
        exit;
    }

    // ─── Cancel any vendor's queued/sending campaign (super admin) ─────────
    if ($_GET['action'] == 'cancel_campaign') {
        header('Content-Type: application/json');
        $cid = (int)($_GET['campaign_id'] ?? 0);
        $result = bc_cancel_mail_campaign($connection_server, $cid, 0, true);
        echo json_encode($result);
        exit;
    }
}

if (isset($_POST["send-mail"])) {
    $subject = trim($_POST["subject"]);
    $body = trim($_POST["body"]); // GrapesJS output
    $mailto = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["mailto"]))));
    
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

    $external_emails = array_unique($external_emails);

    if (!empty($subject) && !empty($body)) {
        $success_count = 0;

        // Internal targets
        if (!empty($mailto)) {
            $res = sendSuperAdminEmailSpecific($mailto, $subject, $body);
            if ($res == "success") $success_count++;
        }
        
        // External targets
        if (!empty($external_emails)) {
            foreach ($external_emails as $ext_email) {
                sendSuperAdminEmail($ext_email, $subject, $body);
            }
            $success_count += count($external_emails);
        }

        if ($success_count > 0) {
            $_SESSION["product_purchase_response"] = "Global Dispatch Successful! (Targeted: $success_count)";
        } else {
            $_SESSION["product_purchase_response"] = "Error: No targets selected or dispatch failed.";
        }
    } else {
        $_SESSION["product_purchase_response"] = "Error: Subject and Body are required.";
    }
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

// Campaigns still queued/sending across all vendors, so the super admin can cancel any pending dispatch.
$active_campaigns = bc_get_active_mail_campaigns($connection_server, 50);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Marketing Suite | Super Admin</title>
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
    </style>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

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
            <div class="marketing-card">
                <div class="card-body p-0">
                    <div class="row">
        <div class="col-md-12">
            <h5 class="fw-bold mb-4 text-primary"><i class="bi bi-megaphone me-2"></i>Send Email</h5>
            <form id="mainForm" method="post" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold text-muted text-uppercase">Email Subject</label>
                        <input name="subject" id="subject" type="text" class="form-control" placeholder="e.g. System Update v2.0" required />
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold text-muted text-uppercase">Target Audience</label>
                        <select name="mailto" id="mailto" class="form-select">
                            <option value="">No internal target (External only)</option>
                            <option value="all">All Vendors</option>
                            <option value="a">Active Vendors Only</option>
                            <option value="b">Suspended Vendors</option>
                            <option value="d">Deleted Accounts</option>
                            <option value="bd">Blocked & Deleted</option>
                            <option value="Select Vendor">Select Vendor</option>
                        </select>
                    </div>
                </div>

                <div id="select_user_div" class="mb-3" style="display:none;">
                    <label class="form-label fw-bold text-muted text-uppercase">Select Vendors (comma separated emails)</label>
                    <input name="paste_emails" id="paste_emails" type="text" class="form-control" placeholder="vendor1@email.com, vendor2@email.com" />
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Upload CSV (Optional)</label>
                    <input type="file" name="email_file" class="form-control form-control-sm" accept=".csv,.txt" />
                    <div class="form-text" style="font-size: 11px;">Upload a CSV file containing email addresses (one per line).</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold text-muted text-uppercase">Message Content</label>
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

            <hr class="my-4">

            <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Queued / Sending Campaigns</h6>
            <?php if (empty($active_campaigns)): ?>
                <p class="text-muted small mb-0">No campaigns are currently queued or sending.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="text-muted"><tr><th>Subject</th><th>Vendor</th><th>Status</th><th>Sent</th><th>Failed</th><th>Total</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($active_campaigns as $c): ?>
                            <tr>
                                <td class="text-truncate" style="max-width:260px;"><?php echo htmlspecialchars($c['subject']); ?></td>
                                <td class="text-truncate" style="max-width:180px;"><?php echo htmlspecialchars($c['company_name'] ?: ($c['vendor_email'] ?: ('Vendor #' . (int)$c['vendor_id']))); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['status']); ?></span></td>
                                <td><?php echo (int)$c['sent_count']; ?></td>
                                <td><?php echo (int)$c['failed_count']; ?></td>
                                <td><?php echo (int)$c['total_count']; ?></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger cancel-campaign-btn" data-id="<?php echo (int)$c['id']; ?>" data-subject="<?php echo htmlspecialchars($c['subject'], ENT_QUOTES); ?>">Cancel</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
            </div>
        </div>
      </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>

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

        document.getElementById('mailto').addEventListener('change', function() {
            if (this.value === 'Select Vendor') {
                document.getElementById('select_user_div').style.display = 'block';
            } else {
                document.getElementById('select_user_div').style.display = 'none';
            }
        });

        fetch('?action=load_draft')
            .then(res => res.json())
            .then(data => {
                if (data.body_html) {
                    quill.root.innerHTML = data.body_html;
                    document.getElementById('subject').value = data.subject || '';
                    document.getElementById('mailto').value = data.mailto || 'all';
                    if (data.mailto === 'Select Vendor') {
                        document.getElementById('select_user_div').style.display = 'block';
                    }
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

        // ─── Cancel queued/sending campaigns (super admin) ────────────────
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