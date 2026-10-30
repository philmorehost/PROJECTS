<?php
// Public Parent Registration Page
define('PORTAL_PATH', __DIR__);

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    die('App not installed. Please run the installer first.');
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';

session_start();

// ── AJAX: Student Search ──────────────────────────────────────
if (isset($_GET['search_student'])) {
    header('Content-Type: application/json');
    $school_id = trim($_GET['school_id'] ?? '');
    $search = trim($_GET['search_student'] ?? '');

    if (empty($school_id) || strlen($search) < 2) {
        echo json_encode([]);
        exit;
    }

    try {
        // Find students in the school who do NOT have a parent linked yet
        $stmt = $pdo->prepare("SELECT admission_number, school_id_number, lastname, firstname, othername, current_class, session 
                               FROM sm_students 
                               WHERE school_id_number = ? 
                                 AND (parent_id_number IS NULL OR parent_id_number = '') 
                                 AND (firstname LIKE ? OR lastname LIKE ? OR othername LIKE ? OR CONCAT(firstname, ' ', lastname) LIKE ? OR CONCAT(lastname, ' ', firstname) LIKE ?)
                               LIMIT 8");
        $searchTerm = '%' . $search . '%';
        $stmt->execute([$school_id, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $students = $stmt->fetchAll();

        $results = [];
        foreach ($students as $stu) {
            $photo_webp = '/dataimg/student_' . $stu['school_id_number'] . '_' . $stu['admission_number'] . '.webp';
            $photo_png = '/dataimg/student_' . $stu['school_id_number'] . '_' . $stu['admission_number'] . '.png';
            $photo_jpg = '/dataimg/student_' . $stu['school_id_number'] . '_' . $stu['admission_number'] . '.jpg';
            
            if (file_exists(PORTAL_PATH . $photo_webp)) {
                $photo_web_path = $photo_webp;
                $photo_exists = true;
            } elseif (file_exists(PORTAL_PATH . $photo_png)) {
                $photo_web_path = $photo_png;
                $photo_exists = true;
            } elseif (file_exists(PORTAL_PATH . $photo_jpg)) {
                $photo_web_path = $photo_jpg;
                $photo_exists = true;
            } else {
                $photo_web_path = $photo_png;
                $photo_exists = false;
            }

            $results[] = [
                'admission_number' => $stu['admission_number'],
                'firstname' => $stu['firstname'],
                'lastname' => $stu['lastname'],
                'othername' => $stu['othername'] ?? '',
                'current_class' => $stu['current_class'] ?? 'N/A',
                'session' => $stu['session'] ?? 'N/A',
                'photo_url' => $photo_web_path,
                'photo_exists' => $photo_exists
            ];
        }
        echo json_encode($results);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: Parent email check ──────────────────────────────────
if (isset($_GET['check_email'])) {
    header('Content-Type: application/json');
    $email = strtolower(trim($_GET['check_email']));
    $stmt = $pdo->prepare("SELECT id_number FROM sm_parents WHERE email = ?");
    $stmt->execute([$email]);
    echo json_encode(['exists' => (bool)$stmt->fetch()]);
    exit;
}

$error   = '';
$success = null;

// ── Handle registration form ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_parent'])) {
    $school_id    = trim($_POST['school_id'] ?? '');
    $fullname     = trim($_POST['fullname'] ?? '');
    $email        = strtolower(trim($_POST['email'] ?? ''));
    $phone        = trim($_POST['phone_number'] ?? '');
    $address      = trim($_POST['home_address'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    // Form validation
    if (empty($school_id) || empty($fullname) || empty($email) || empty($password_raw)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password_raw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password_raw !== $confirm_pass) {
        $error = 'Passwords do not match.';
    } else {
        // Check email uniqueness
        $emailCheck = $pdo->prepare("SELECT id_number FROM sm_parents WHERE email = ?");
        $emailCheck->execute([$email]);
        if ($emailCheck->fetch()) {
            $error = 'An account with this email address already exists.';
        }

        if (!$error) {
            try {
                $pdo->beginTransaction();

                // ── Generate Unique Parent ID (Legacy yyXXXX sequence format) ──
                $year = date('y');
                $stmtMax = $pdo->prepare("SELECT id_number FROM sm_parents WHERE id_number LIKE ? ORDER BY id_number DESC LIMIT 1");
                $stmtMax->execute([$year . '%']);
                $rowMax = $stmtMax->fetch();
                
                if ($rowMax) {
                    $num = intval(substr($rowMax['id_number'], 2));
                    $nextNum = $num + 1;
                    $parentId = $year . sprintf("%04d", $nextNum);
                } else {
                    $parentId = $year . "0001";
                }

                // Create Parent Account
                $hashed = password_hash($password_raw, PASSWORD_DEFAULT);
                $stmtParent = $pdo->prepare("INSERT INTO sm_parents (id_number, school_id_number, fullname, email, password, phone_number, home_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtParent->execute([$parentId, $school_id, $fullname, $email, $hashed, $phone, $address]);

                $pdo->commit();

                // Save success details to session and redirect
                $_SESSION['success_msg'] = "Registration successful! Your Unique Parent ID is: {$parentId}. Log in using your email or ID.";
                header("Location: login.php");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}

// Fetch active school from session context resolved by index.php
$school_id = $_SESSION['school_id'] ?? '';
if (empty($school_id) && isset($_GET['school_id'])) {
    $school_id = trim($_GET['school_id']);
}

// Redirect or show context error if no school ID is active
if (empty($school_id)) {
    die('<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Access Denied — SchoolApp</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: "Inter", sans-serif; background: #080d18; color: #e8ecf4; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
            .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.09); border-radius: 16px; padding: 40px; max-width: 480px; }
            h1 { font-size: 24px; color: #ef4444; margin-bottom: 16px; }
            p { font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 24px; }
            a { display: inline-block; padding: 12px 24px; background: #4f7fff; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 700; transition: background 0.2s; }
            a:hover { background: #3b66d6; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>⛔ Portal Context Required</h1>
            <p>Parent registration cannot be performed directly from the central SaaS gateway. Please visit your child\'s school landing portal (e.g. <code>school-slug.schoolapp.ng</code>) to register your parent account.</p>
            <a href="/">Go to Homepage</a>
        </div>
    </body>
    </html>');
}

// Verify parent registration is globally allowed
if (defined('ALLOW_PARENT_REGISTRATION') && ALLOW_PARENT_REGISTRATION === '0') {
    die('<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Registration Disabled — SchoolApp</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
        <style>
            body { font-family: "Inter", sans-serif; background: #080d18; color: #e8ecf4; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; text-align: center; }
            .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.09); border-radius: 16px; padding: 40px; max-width: 480px; }
            h1 { font-size: 24px; color: #eab308; margin-bottom: 16px; }
            p { font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 24px; }
            a { display: inline-block; padding: 12px 24px; background: #4f7fff; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 700; transition: background 0.2s; }
            a:hover { background: #3b66d6; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>⚠️ Self-Registration Closed</h1>
            <p>Parent self-registration is currently disabled on this platform. Please contact your school admin to have your parent account created manually.</p>
            <a href="/login.php">Back to Login</a>
        </div>
    </body>
    </html>');
}
// Fetch current school details
$stmtSch = $pdo->prepare("SELECT name, slug, logo_url FROM sm_schools WHERE id = ? AND status = 'active' LIMIT 1");
$stmtSch->execute([$school_id]);
$currentSchool = $stmtSch->fetch();
if (!$currentSchool) {
    die('School portal account is suspended or invalid.');
}

require_once PORTAL_PATH . '/include/favicon_helper.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Portal Self-Registration — <?php echo htmlspecialchars($currentSchool['name']); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconDataUri($pdo, $school_id, null); ?>" />
    <meta name="description" content="Set up your Parent Account and link to your children.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #080d18;
            --surface:  rgba(255,255,255,0.04);
            --border:   rgba(255,255,255,0.09);
            --accent:   #4f7fff;
            --accent2:  #7c5cfc;
            --success:  #22c55e;
            --danger:   #ef4444;
            --text:     #e8ecf4;
            --muted:    #cbd5e1;
            --radius:   14px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            background-image:
                radial-gradient(ellipse 80% 50% at 20% -10%, rgba(79,127,255,.18) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 80% 110%, rgba(124,92,252,.14) 0%, transparent 55%);
        }

        .page-wrap {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        /* Left Hero */
        .hero {
            padding: 60px 56px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-right: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(79,127,255,.06) 0%, transparent 60%);
            pointer-events: none;
        }
        .badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(79,127,255,.12); border: 1px solid rgba(79,127,255,.3);
            color: #93b4ff; font-size: 11px; font-weight: 600; letter-spacing: .08em;
            padding: 4px 14px; border-radius: 99px; margin-bottom: 28px;
            text-transform: uppercase; width: fit-content;
        }
        .hero h1 {
            font-size: clamp(2rem, 3.5vw, 3rem);
            font-weight: 900; line-height: 1.1; letter-spacing: -.03em;
            background: linear-gradient(135deg, #93b4ff 0%, #c4b5fd 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }
        .hero p { color: var(--muted); font-size: 15px; line-height: 1.7; max-width: 380px; }

        .feature-list { list-style: none; margin-top: 40px; display: flex; flex-direction: column; gap: 14px; }
        .feature-list li {
            display: flex; align-items: center; gap: 12px;
            font-size: 14px; color: #a0a8c0; font-weight: 500;
        }
        .feature-list li span.icon {
            width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
            background: rgba(79,127,255,.12); border: 1px solid rgba(79,127,255,.2);
        }

        .already-link { margin-top: 48px; font-size: 13px; color: var(--muted); }
        .already-link a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .already-link a:hover { text-decoration: underline; }

        /* Right Form */
        .form-side {
            padding: 60px 56px;
            display: flex; flex-direction: column; justify-content: center;
            overflow-y: auto;
        }
        .form-title { font-size: 22px; font-weight: 800; margin-bottom: 6px; }
        .form-sub   { font-size: 13px; color: var(--muted); margin-bottom: 32px; }

        .form-section {
            border: 1px solid var(--border); border-radius: var(--radius);
            padding: 20px 20px 16px; margin-bottom: 20px; background: var(--surface);
        }
        .form-section-title {
            font-size: 11px; font-weight: 700; color: var(--muted);
            letter-spacing: .08em; text-transform: uppercase; margin-bottom: 16px;
        }
        .field { margin-bottom: 14px; }
        label  { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 5px; letter-spacing: .04em; text-transform: uppercase; }

        input[type=text], input[type=email], input[type=password], select {
            width: 100%; background: rgba(0,0,0,.25); border: 1px solid var(--border);
            border-radius: 10px; color: var(--text); font-size: 14px; font-family: inherit;
            padding: 11px 14px; outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,127,255,.18); }
        input::placeholder { color: var(--muted); }

        select option {
            background-color: #0b0f19;
            color: var(--text);
        }

        .btn-action {
            display: flex; justify-content: space-between; gap: 14px; margin-top: 18px;
        }
        #btnSearch:hover {
            transform: translateY(-0.5px);
            box-shadow: 0 4px 15px rgba(79, 127, 255, 0.35) !important;
            filter: brightness(1.1);
        }
        #btnSearch:active {
            transform: translateY(0.5px);
        }
        .btn-next, .btn-submit {
            flex: 1; padding: 14px; font-size: 15px; font-weight: 800;
            font-family: inherit; border: none; border-radius: 12px; cursor: pointer;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: #fff; box-shadow: 0 4px 24px rgba(79,127,255,.35);
            transition: all .2s; letter-spacing: .02em; text-align: center;
        }
        .btn-next:hover, .btn-submit:hover { transform: translateY(-1px); box-shadow: 0 8px 32px rgba(79,127,255,.45); }
        .btn-next:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        
        .btn-back {
            padding: 14px 24px; font-size: 15px; font-weight: 600; border-radius: 12px;
            background: transparent; border: 1px solid var(--border); color: var(--muted);
            cursor: pointer; transition: all .2s;
        }
        .btn-back:hover { color: var(--text); border-color: var(--muted); }

        .alert {
            padding: 14px 16px; border-radius: 12px; font-size: 13px; margin-bottom: 20px;
            border: 1px solid transparent; display: flex; gap: 10px; align-items: flex-start;
        }
        .alert.err  { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.25); color: #fca5a5; }
        .alert.ok   { background: rgba(34,197,94,.08);  border-color: rgba(34,197,94,.25);  color: #86efac; }
        .alert.warn { background: rgba(234,179,8,.08);  border-color: rgba(234,179,8,.25);  color: #fef08a; }

        /* Step display classes */
        .step-panel { display: none; }
        .step-panel.active { display: block; }

        /* Search Results Styles */
        .search-results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 14px;
            max-height: 250px;
            overflow-y: auto;
            padding: 4px;
        }
        .stu-card {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            display: flex;
            gap: 10px;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .stu-card:hover {
            background: rgba(255,255,255,0.06);
            border-color: rgba(79,127,255,0.4);
        }
        .stu-card.selected {
            background: rgba(79,127,255,0.15);
            border-color: var(--accent);
        }
        .stu-photo {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid rgba(255,255,255,0.2);
            background: #111827;
        }
        .stu-info {
            flex: 1;
            min-width: 0;
        }
        .stu-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .stu-meta {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        /* Selected Children Pills */
        .selected-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .selected-pill {
            background: rgba(124,92,252,0.15);
            border: 1px solid rgba(124,92,252,0.3);
            border-radius: 99px;
            padding: 4px 12px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .selected-pill-remove {
            color: var(--danger);
            font-weight: bold;
            cursor: pointer;
        }

        /* Success screen */
        .success-screen { text-align: center; padding: 40px 20px; }
        .success-icon   { font-size: 4rem; margin-bottom: 20px; animation: pop .5s ease; }
        .success-screen h2 { font-size: 1.7rem; font-weight: 800; color: #86efac; margin-bottom: 10px; }
        .success-screen p  { color: var(--muted); font-size: 14px; line-height: 1.7; }
        .cred-box {
            margin: 24px auto; max-width: 380px; text-align: left;
            background: rgba(0,0,0,.3); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 18px 20px;
        }
        .cred-box .cred-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px; }
        .cred-box .cred-label { color: var(--muted); }
        .cred-box .cred-val   { color: var(--text); font-weight: 600; font-family: monospace; }
        .btn-login {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 13px 32px; background: linear-gradient(135deg, var(--success), #16a34a);
            color: #fff; border-radius: 12px; font-weight: 800; text-decoration: none;
            font-size: 14px; margin-top: 20px; transition: all .2s;
        }
        .btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(34,197,94,.35); }

        @keyframes pop { 0%{transform:scale(0)} 70%{transform:scale(1.2)} 100%{transform:scale(1)} }

        @media (max-width: 860px) {
            .page-wrap { grid-template-columns: 1fr; }
            .hero { display: none; }
            .form-side { padding: 40px 24px; }
        }
    </style>
</head>
<body>
<div class="page-wrap">

    <!-- ── LEFT HERO ─────────────────────────────────── -->
    <div class="hero">
        <div class="badge">👨‍👩‍👧 Parent Access Portal</div>
        <h1>Link to <?php echo htmlspecialchars($currentSchool['name']); ?></h1>
        <p>Set up a parent account to instantly monitor report cards, pay tuition fees, access curriculum homework, and receive notice bulletins.</p>

        <ul class="feature-list">
            <li><span class="icon">📈</span> Live academic tracking & report card checks</li>
            <li><span class="icon">💳</span> Secure school fee payments & receipt logs</li>
            <li><span class="icon">🔔</span> Notice bulletins, events & text alerts</li>
            <li><span class="icon">🎥</span> Join virtual learning sessions & check assignments</li>
            <li><span class="icon">🤖</span> AI Study Buddy logs to guide home study</li>
        </ul>

        <div class="already-link">
            Already have an account? <a href="/login.php">Log in to the portal →</a>
        </div>
    </div>

    <!-- ── RIGHT FORM ─────────────────────────────────── -->
    <div class="form-side">
        <div class="form-title">Parent Registration</div>
        <div class="form-sub" id="formSubtitle">Create your parent credentials and profile details.</div>

        <?php if ($error): ?>
            <div class="alert err">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" id="parentRegForm" novalidate>
            <input type="hidden" name="school_id" id="schoolSelect" value="<?php echo htmlspecialchars($school_id); ?>">

            <div class="form-section">
                <div class="form-section-title">👤 Parent Profile Details</div>

                <div class="field">
                    <label>Parent Full Name</label>
                    <input type="text" name="fullname" id="fullname"
                           value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>"
                           placeholder="e.g. John Doe" required>
                </div>

                <div class="field">
                    <label>Email Address</label>
                    <input type="email" name="email" id="emailInput"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           placeholder="e.g. parent@example.com" required>
                    <span class="text-[10px] text-red-400 mt-1" id="emailStatus" style="display:none"></span>
                </div>

                <div class="field">
                    <label>Phone Number</label>
                    <input type="text" name="phone_number"
                           value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>"
                           placeholder="e.g. +2348012345678" required>
                </div>

                <div class="field" style="margin-bottom: 0">
                    <label>Home Address</label>
                    <input type="text" name="home_address"
                           value="<?php echo htmlspecialchars($_POST['home_address'] ?? ''); ?>"
                           placeholder="e.g. 12 Greenfield Way, Lagos" required>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">🔒 Secure Password</div>
                
                <div class="field">
                    <label>Password <span style="color:var(--muted);font-weight:400">(min 8 characters)</span></label>
                    <input type="password" name="password" id="parentPassword" placeholder="••••••••" required>
                </div>

                <div class="field" style="margin-bottom: 0">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" id="parentConfirmPassword" placeholder="••••••••" required>
                </div>
            </div>

            <div class="btn-action">
                <a href="/login.php" class="btn-back" style="text-decoration:none; text-align:center;">← Cancel</a>
                <button type="submit" name="register_parent" class="btn-submit" id="btnSubmitForm">🚀 Setup Parent Account</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const btnSubmit = document.getElementById('btnSubmitForm');
    const emailInput = document.getElementById('emailInput');
    const emailStatus = document.getElementById('emailStatus');
    const confirmPassInput = document.getElementById('parentConfirmPassword');
    const passInput = document.getElementById('parentPassword');

    // Realtime Email Check
    let emailTimer;
    emailInput.addEventListener('input', () => {
        clearTimeout(emailTimer);
        const email = emailInput.value.trim();
        if (!email) {
            emailStatus.style.display = 'none';
            return;
        }

        emailTimer = setTimeout(async () => {
            try {
                const res = await fetch(`?check_email=${encodeURIComponent(email)}`);
                const data = await res.json();
                if (data.exists) {
                    emailStatus.textContent = '✗ An account with this email already exists.';
                    emailStatus.style.display = 'block';
                    btnSubmit.disabled = true;
                } else {
                    emailStatus.style.display = 'none';
                    btnSubmit.disabled = false;
                }
            } catch (e) {
                // Ignored
            }
        }, 500);
    });

    // Password Match Validation style
    confirmPassInput.addEventListener('input', function() {
        const pw = passInput.value;
        this.style.borderColor = this.value === pw ? 'var(--success)' : 'var(--danger)';
    });
});
</script>
</body>
</html>
