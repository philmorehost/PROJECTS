<?php
// Communications Management View (Bulk SMS, Bulk Email, Notices, Announcements)
if (!defined('PORTAL_PATH')) exit;

// Restrict access for parents/students on dispatch actions
if (in_array($user_role, ['parent', 'student']) && in_array($action, ['sms', 'email', 'notices'])) {
    echo '<div class="p-6 text-red-400 font-semibold">⛔ Access Denied. Parents and students do not have permission to access broadcast channels.</div>';
    return;
}

$msg = '';
$action = isset($_GET['action']) ? $_GET['action'] : (in_array($user_role, ['parent', 'student']) ? 'list' : 'list');

$school_students = [];
$school_teachers = [];
$school_parents = [];
if ($action === 'email') {
    $stmtS = $pdo->prepare("SELECT admission_number, firstname, lastname, email FROM sm_students WHERE school_id_number = ? ORDER BY firstname ASC");
    $stmtS->execute([$school_id]);
    $school_students = $stmtS->fetchAll();
    
    $stmtT = $pdo->prepare("SELECT id_number, firstname, lastname, email FROM sm_teachers WHERE school_id_number = ? ORDER BY firstname ASC");
    $stmtT->execute([$school_id]);
    $school_teachers = $stmtT->fetchAll();
    
    $stmtP = $pdo->prepare("SELECT id_number, fullname, email FROM sm_parents WHERE school_id_number = ? ORDER BY fullname ASC");
    $stmtP->execute([$school_id]);
    $school_parents = $stmtP->fetchAll();
}

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prevent unauthorized parent/student posts
    if (in_array($user_role, ['parent', 'student'])) {
        $msg = "⚠️ Error: Parents and students are not permitted to publish broadcasts.";
    } else {
        if (isset($_POST['send_email'])) {
            $recipient_scope = $_POST['recipient_scope'] ?? 'individual';
            $email_address = trim($_POST['email_address'] ?? '');
            $selected_emails = $_POST['selected_emails'] ?? [];
            $subject = trim($_POST['subject'] ?? '');
            $body = trim($_POST['body'] ?? '');
            
            $recipients = [];
            if ($recipient_scope === 'individual') {
                if (filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email_address;
                }
            } elseif ($recipient_scope === 'some') {
                foreach ($selected_emails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $recipients[] = $email;
                    }
                }
            } elseif ($recipient_scope === 'all') {
                // Fetch all students, teachers, and parents of this school
                $stmtStud = $pdo->prepare("SELECT email FROM sm_students WHERE school_id_number = ?");
                $stmtStud->execute([$school_id]);
                $recipients = array_merge($recipients, $stmtStud->fetchAll(PDO::FETCH_COLUMN));
                
                $stmtTeach = $pdo->prepare("SELECT email FROM sm_teachers WHERE school_id_number = ?");
                $stmtTeach->execute([$school_id]);
                $recipients = array_merge($recipients, $stmtTeach->fetchAll(PDO::FETCH_COLUMN));
                
                $stmtPar = $pdo->prepare("SELECT email FROM sm_parents WHERE school_id_number = ?");
                $stmtPar->execute([$school_id]);
                $recipients = array_merge($recipients, $stmtPar->fetchAll(PDO::FETCH_COLUMN));
            }
            
            // Remove duplicates and empty elements
            $recipients = array_filter(array_unique($recipients));
            
            if (empty($recipients)) {
                $msg = "⚠️ Error: No valid recipients selected.";
            } elseif (empty($subject) || empty($body)) {
                $msg = "⚠️ Error: Email Subject and Body are required.";
            } else {
                require_once PORTAL_PATH . '/include/mail_helper.php';
                $success_count = 0;
                foreach ($recipients as $to_email) {
                    if (MailHelper::sendHTML($to_email, $subject, $body)) {
                        $success_count++;
                    }
                }
                
                // Log outbox message inside sm_email_logs
                $recipient_list = implode(', ', $recipients);
                $stmtLog = $pdo->prepare("INSERT INTO sm_email_logs (school_id_number, sender_role, recipient_type, recipient_email, subject, body, status) VALUES (?, 'school_admin', ?, ?, ?, ?, 'sent')");
                $stmtLog->execute([$school_id, $recipient_scope, $recipient_list, $subject, $body]);
                
                $msg = "Email broadcast dispatched successfully to {$success_count} recipient(s)!";
            }
        } elseif (isset($_POST['send_sms'])) {
            $sender = trim($_POST['sender_id']);
            $recipient = trim($_POST['recipient']);
            $body = trim($_POST['message']);

            // Validate that teachers only send SMS to parents in their own class
            $is_allowed = true;
            if ($user_role === 'teacher') {
                $stmtTeacherClass = $pdo->prepare("SELECT class FROM sm_teachers WHERE email = ? AND school_id_number = ? LIMIT 1");
                $stmtTeacherClass->execute([$_SESSION['user_session'] ?? '', $school_id]);
                $teacher_class = $stmtTeacherClass->fetchColumn() ?: '';

                if (empty($teacher_class)) {
                    $msg = "⚠️ Error: You are not assigned to any class. You cannot send SMS.";
                    $is_allowed = false;
                } else {
                    $stmtAllowed = $pdo->prepare("
                        SELECT DISTINCT p.phone_number 
                        FROM sm_students s 
                        JOIN sm_parents p ON s.parent_id_number = p.id_number 
                        WHERE s.current_class = ? AND s.school_id_number = ? AND p.phone_number IS NOT NULL AND p.phone_number != ''
                    ");
                    $stmtAllowed->execute([$teacher_class, $school_id]);
                    $allowed_numbers = $stmtAllowed->fetchAll(PDO::FETCH_COLUMN) ?: [];

                    $numbers = array_filter(array_map('trim', explode(',', $recipient)));
                    $invalid_numbers = array_diff($numbers, $allowed_numbers);

                    if (empty($numbers) || !empty($invalid_numbers)) {
                        $msg = "⚠️ Error: You can only send SMS messages strictly to parents in your assigned class (" . htmlspecialchars($teacher_class) . ").";
                        $is_allowed = false;
                    }
                }
            }

            // Check if Sender ID is approved for this school
            $stmtVal = $pdo->prepare("SELECT COUNT(*) FROM sm_sms_sender_ids WHERE school_id_number = ? AND sender_id = ? AND status = 'approved'");
            $stmtVal->execute([$school_id, $sender]);
            $isValidSender = $stmtVal->fetchColumn() > 0;

            if (!$is_allowed) {
                // message already set
            } elseif (!$isValidSender) {
                $msg = "⚠️ Error: The Sender ID '{$sender}' is not approved for this school. Please submit it for approval first.";
            } else {
                // Count characters and calculate pagination (160 for 1 page, 153 per page for 2-3 pages. Max 459 chars)
                $length = mb_strlen($body, 'UTF-8');
                if ($length > 459) {
                    $msg = "⚠️ Error: Message exceeds the maximum limit of 3 pages (459 characters). Current length: {$length} characters.";
                } else {
                    if ($length <= 160) {
                        $creditsNeeded = 1;
                    } else {
                        $creditsNeeded = (int)ceil($length / 153);
                    }

                    // Check wallet balance
                    $stmtSchool = $pdo->prepare("SELECT wallet_balance FROM sm_schools WHERE id = ?");
                    $stmtSchool->execute([$school_id]);
                    $balance = $stmtSchool->fetchColumn();

                    if ($balance >= $creditsNeeded) {
                        // Get central API key from sm_global_settings
                        $stmtApi = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = 'platform_sms_api_key' LIMIT 1");
                        $stmtApi->execute();
                        $api_key = $stmtApi->fetchColumn();

                        $status = 'failed';
                        if (!empty($api_key)) {
                            $encoded_message = urlencode($body);
                            $url = "https://app.philmoresms.com/api/sms.php?token=" . urlencode($api_key) . "&senderID=" . urlencode($sender) . "&recipients=" . urlencode($recipient) . "&message=" . $encoded_message;

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            $result = curl_exec($ch);
                            curl_close($ch);

                            if ($result) {
                                $response = json_decode($result, true);
                                if (isset($response['status']) && $response['status'] === 'success') {
                                    $status = 'sent';
                                }
                            }
                        }

                        if ($status === 'sent') {
                            // Deduct balance
                            $stmtDeduct = $pdo->prepare("UPDATE sm_schools SET wallet_balance = wallet_balance - ? WHERE id = ?");
                            $stmtDeduct->execute([$creditsNeeded, $school_id]);

                            // Save log
                            $stmtLog = $pdo->prepare("INSERT INTO sm_sms_logs (school_id_number, sender_id, recipient, message, credits_used, status) VALUES (?, ?, ?, ?, ?, 'sent')");
                            $stmtLog->execute([$school_id, $sender, $recipient, $body, $creditsNeeded]);

                            $msg = "SMS dispatched successfully via PhilmoreSMS API! Wallet debited {$creditsNeeded} credits.";
                        } else {
                            $msg = "Failed to dispatch SMS. Please configure a valid PhilmoreSMS API Token in Gateway settings.";
                        }
                    } else {
                        $msg = "Insufficient SMS credits. Please recharge your wallet (credits required: {$creditsNeeded}, available: {$balance}).";
                    }
                }
            }
        } elseif (isset($_POST['request_sender_id'])) {
            $sender_id = trim($_POST['sender_id']);
            $sample_sms = trim($_POST['sample_sms']);

            if (strlen($sender_id) < 1 || strlen($sender_id) > 11) {
                $msg = "⚠️ Error: Sender ID must be between 1 and 11 characters.";
            } elseif (empty($sample_sms)) {
                $msg = "⚠️ Error: Sample SMS template text is required.";
            } else {
                $stmtReq = $pdo->prepare("INSERT INTO sm_sms_sender_ids (school_id_number, sender_id, sample_sms, status) VALUES (?, ?, ?, 'pending')");
                $stmtReq->execute([$school_id, $sender_id, $sample_sms]);
                $msg = "Sender ID '{$sender_id}' and template requested successfully. Waiting for Super Admin approval.";
            }
        } elseif (isset($_POST['create_notice'])) {
            $title = trim($_POST['notice_title']);
            $content = trim($_POST['notice_comment']);
            $cls = trim($_POST['numeric_class_name']);
            $start = $_POST['start_date'];
            $end = $_POST['end_date'];
            $target = trim($_POST['notice_for']);

            $stmt = $pdo->prepare("INSERT INTO sm_notices (school_id_number, notice_title, notice_comment, numeric_class_name, start_date, end_date, notice_for) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $title, $content, $cls, $start, $end, $target]);
            $msg = "Notice published to dashboard announcement feed!";
        }
    }
}

// Fetch balances and listings
$stmtSchool = $pdo->prepare("SELECT wallet_balance FROM sm_schools WHERE id = ?");
$stmtSchool->execute([$school_id]);
$wallet_balance = $stmtSchool->fetchColumn();

$stmtLogs = $pdo->prepare("SELECT * FROM sm_sms_logs WHERE school_id_number = ? ORDER BY id DESC LIMIT 50");
$stmtLogs->execute([$school_id]);
$sms_logs = $stmtLogs->fetchAll();

$school_classes = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? ORDER BY class_name");
$school_classes->execute([$school_id]);
$school_classes = $school_classes->fetchAll();

$search_notice = isset($_GET['search_notice']) ? trim($_GET['search_notice']) : '';
$filter_audience = isset($_GET['filter_audience']) ? trim($_GET['filter_audience']) : '';
$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';

$notice_clauses = ["school_id_number = ?"];
$notice_params = [$school_id];

if ($filter_audience !== '') {
    $notice_clauses[] = "notice_for = ?";
    $notice_params[] = $filter_audience;
}
if ($filter_class !== '') {
    $notice_clauses[] = "(numeric_class_name = ? OR numeric_class_name = 'all')";
    $notice_params[] = $filter_class;
}
if ($search_notice !== '') {
    $notice_clauses[] = "(notice_title LIKE ? OR notice_comment LIKE ?)";
    $search_param = '%' . $search_notice . '%';
    $notice_params[] = $search_param;
    $notice_params[] = $search_param;
}

$notice_where = implode(' AND ', $notice_clauses);
$stmtNotices = $pdo->prepare("SELECT * FROM sm_notices WHERE $notice_where ORDER BY notice_id DESC LIMIT 50");
$stmtNotices->execute($notice_params);
$notices = $stmtNotices->fetchAll();

$stmtApprovedSenders = $pdo->prepare("SELECT * FROM sm_sms_sender_ids WHERE school_id_number = ? AND status = 'approved' ORDER BY id DESC");
$stmtApprovedSenders->execute([$school_id]);
$approved_senders = $stmtApprovedSenders->fetchAll();

$stmtSenderRequests = $pdo->prepare("SELECT * FROM sm_sms_sender_ids WHERE school_id_number = ? ORDER BY id DESC LIMIT 30");
$stmtSenderRequests->execute([$school_id]);
$sender_requests = $stmtSenderRequests->fetchAll();
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">💬 Communications Panel</h2>
        <?php if (!in_array($user_role, ['parent', 'student'])): ?>
        <div class="flex overflow-x-auto gap-2 w-full md:w-auto p-1 scrollbar-thin">
            <a href="?page=communications&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 transition text-sm">Overview</a>
            <a href="?page=communications&action=sms" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-semibold">SMS Dispatch</a>
            <a href="?page=communications&action=email" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition text-sm font-semibold">Email Dispatch</a>
            <a href="?page=communications&action=notices" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition text-sm font-semibold">Publish Notice</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list') : ?>
        <div class="grid grid-cols-1 <?php echo !in_array($user_role, ['parent', 'student']) ? 'lg:grid-cols-3' : 'lg:grid-cols-1'; ?> gap-6">
            <?php if (!in_array($user_role, ['parent', 'student'])): ?>
            <!-- Balance Indicator -->
            <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-6 shadow-xl text-white flex flex-col justify-between">
                <div>
                    <span class="text-sm font-semibold opacity-75 uppercase tracking-wider">SMS Wallet Balance</span>
                    <h3 class="text-4xl font-extrabold mt-2"><?php echo htmlspecialchars($wallet_balance); ?></h3>
                    <p class="text-xs opacity-75 mt-1">Credits ready to dispatch broadcasts.</p>
                </div>
                <a href="?page=billing&action=recharge" class="mt-6 text-center w-full py-2 bg-white text-blue-600 font-bold rounded-lg hover:bg-blue-50 transition text-sm">
                    Recharge Balance
                </a>
            </div>
            <?php endif; ?>

            <!-- Bulletins/Notices Feed -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md <?php echo !in_array($user_role, ['parent', 'student']) ? 'lg:col-span-2' : ''; ?>">
                <h3 class="text-lg font-semibold mb-4 text-blue-300">Active Notice Board Bulletins</h3>
                
                <!-- Search & Filters -->
                <form method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-6">
                    <input type="hidden" name="page" value="communications" />
                    <input type="hidden" name="action" value="list" />
                    <div>
                        <input type="text" name="search_notice" placeholder="🔍 Search notice..." value="<?php echo htmlspecialchars($search_notice); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                    </div>
                    <div>
                        <select name="filter_audience" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                            <option value="">All Audiences</option>
                            <option value="all" <?php echo $filter_audience === 'all' ? 'selected' : ''; ?>>All Groups</option>
                            <option value="students" <?php echo $filter_audience === 'students' ? 'selected' : ''; ?>>Students Only</option>
                            <option value="teachers" <?php echo $filter_audience === 'teachers' ? 'selected' : ''; ?>>Teachers Only</option>
                            <option value="parents" <?php echo $filter_audience === 'parents' ? 'selected' : ''; ?>>Parents Only</option>
                        </select>
                    </div>
                    <div>
                        <select name="filter_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                            <option value="">All Classes</option>
                            <?php foreach ($school_classes as $cls) : ?>
                                <option value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" <?php echo $filter_class === $cls['numeric_class_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cls['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex gap-1">
                        <button type="submit" class="flex-1 px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition">Filter</button>
                        <a href="?page=communications&action=list" class="px-2 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-xs transition flex items-center justify-center">Reset</a>
                    </div>
                </form>

                <?php if (empty($notices)) : ?>
                    <p class="text-gray-400 text-sm">No notices published recently.</p>
                <?php else : ?>
                    <div class="space-y-3 max-h-80 overflow-y-auto pr-2">
                        <?php foreach ($notices as $n) : ?>
                            <div class="p-4 bg-white/5 rounded-xl border border-white/5">
                                <div class="flex justify-between items-start">
                                    <h4 class="font-bold text-white"><?php echo htmlspecialchars($n['notice_title']); ?></h4>
                                    <span class="px-2 py-0.5 bg-indigo-600/20 text-indigo-400 border border-indigo-500/20 rounded-md text-[9px] uppercase font-bold"><?php echo htmlspecialchars($n['notice_for']); ?></span>
                                </div>
                                <p class="text-xs text-gray-300 mt-2"><?php echo htmlspecialchars($n['notice_comment']); ?></p>
                                <div class="flex justify-between items-center text-[10px] text-gray-400 mt-3 pt-2 border-t border-white/5">
                                    <span>Class Scope: <?php echo htmlspecialchars($n['numeric_class_name']); ?></span>
                                    <span>Date Range: <?php echo htmlspecialchars($n['start_date'] . ' to ' . $n['end_date']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'sms') : ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- SMS Form -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl">
                <h3 class="text-lg font-semibold mb-6 text-blue-300">Send Bulk SMS Message</h3>
                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Sender Identifier ID</label>
                        <?php if ($user_role === 'teacher') : ?>
                            <?php if (empty($approved_senders)) : ?>
                                <div class="p-3 bg-red-500/10 border border-red-500/20 text-red-400 text-xs rounded-lg">
                                    ⚠️ No approved Sender ID set up by the school admin. Please contact the administrator.
                                </div>
                                <input type="hidden" name="sender_id" value="" />
                            <?php else: ?>
                                <input type="text" name="sender_id" value="<?php echo htmlspecialchars($approved_senders[0]['sender_id']); ?>" readonly class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-gray-450 font-bold focus:outline-none" />
                                <span class="text-[10px] text-gray-500 block mt-1">General school-approved sender ID (read-only)</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php if (empty($approved_senders)) : ?>
                                <div class="p-3 bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 text-xs rounded-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                    <span>⚠️ No approved Sender IDs. Please submit a request for approval below first.</span>
                                    <button type="button" onclick="document.getElementById('sender-id-requests').scrollIntoView({ behavior: 'smooth' })" class="px-2.5 py-1 bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-300 font-bold rounded text-[10px] uppercase transition shrink-0 self-start sm:self-auto">Request ID</button>
                                </div>
                                <input type="hidden" name="sender_id" value="" />
                            <?php else : ?>
                                <select name="sender_id" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                                    <?php foreach ($approved_senders as $as) : ?>
                                        <option value="<?php echo htmlspecialchars($as['sender_id']); ?>"><?php echo htmlspecialchars($as['sender_id']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Recipient Phone Number(s) (comma separated)</label>
                        <?php if ($user_role === 'teacher') : ?>
                            <?php
                            $teacher_class = '';
                            $stmtTeacherClass = $pdo->prepare("SELECT class FROM sm_teachers WHERE email = ? AND school_id_number = ? LIMIT 1");
                            $stmtTeacherClass->execute([$_SESSION['user_session'] ?? '', $school_id]);
                            $teacher_class = $stmtTeacherClass->fetchColumn() ?: '';

                            $class_recipients = [];
                            if (!empty($teacher_class)) {
                                $stmtClassRec = $pdo->prepare("
                                    SELECT s.firstname, s.lastname, p.fullname as parent_name, p.phone_number 
                                    FROM sm_students s 
                                    JOIN sm_parents p ON s.parent_id_number = p.id_number 
                                    WHERE s.current_class = ? AND s.school_id_number = ? AND p.phone_number IS NOT NULL AND p.phone_number != ''
                                    ORDER BY s.firstname ASC
                                ");
                                $class_recipients = $stmtClassRec->fetchAll();
                            }
                            ?>
                            <div class="mb-3">
                                <span class="text-xs text-gray-450 block mb-1.5">Class Recipients (<?php echo htmlspecialchars($teacher_class ?: 'No Class Assigned'); ?>):</span>
                                <?php if (empty($class_recipients)) : ?>
                                    <p class="text-xs text-gray-500 italic">No student-parent profiles with registered phone numbers found in your class.</p>
                                <?php else : ?>
                                    <div class="max-h-40 overflow-y-auto border border-white/10 rounded-lg p-2.5 bg-black/20 space-y-1">
                                        <?php foreach ($class_recipients as $cr) : ?>
                                            <label class="flex items-center gap-2 text-xs text-gray-300 hover:text-white cursor-pointer select-none">
                                                <input type="checkbox" value="<?php echo htmlspecialchars($cr['phone_number']); ?>" onchange="updateRecipientField()" class="recipient-checkbox rounded bg-white/5 border-white/10 text-blue-600 focus:ring-0" />
                                                <span><?php echo htmlspecialchars($cr['firstname'] . ' ' . $cr['lastname'] . ' (' . $cr['parent_name'] . '): ' . $cr['phone_number']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <input type="text" name="recipient" id="recipient_field" readonly required placeholder="Select recipients from the list above" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-gray-450 font-mono text-xs focus:outline-none" />
                            <script>
                            function updateRecipientField() {
                                const checkboxes = document.querySelectorAll('.recipient-checkbox:checked');
                                const values = Array.from(checkboxes).map(cb => cb.value);
                                document.getElementById('recipient_field').value = values.join(', ');
                            }
                            </script>
                        <?php else : ?>
                            <input type="text" name="recipient" required placeholder="e.g. +2348012345678, +2349098765432" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Message Content (Max 160 characters per unit)</label>
                        <textarea name="message" id="sms_message_body" rows="4" required placeholder="Type message..." oninput="updateCharCounter(this.value)" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"></textarea>
                        <div class="flex justify-between items-center text-xs mt-1.5 text-gray-400">
                            <span id="char_counter_len">Characters: 0</span>
                            <span id="char_counter_units">Pages: 0 / 3 (Max 459 chars)</span>
                        </div>
                    </div>
                    <button type="submit" name="send_sms" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                        Dispatch Message(s)
                    </button>
                </form>
            </div>

            <!-- Outbox Log -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-4 text-indigo-300">Recent Outbox Logs</h3>
                <?php if (empty($sms_logs)) : ?>
                    <p class="text-gray-400 text-sm">Outbox logs are currently empty.</p>
                <?php else : ?>
                    <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
                        <?php foreach ($sms_logs as $log) : ?>
                            <div class="p-3 bg-white/5 rounded-xl border border-white/5 flex justify-between items-start">
                                <div class="text-xs">
                                    <div class="flex items-center space-x-2 text-white font-bold">
                                        <span>To: <?php echo htmlspecialchars($log['recipient']); ?></span>
                                        <span class="text-[10px] text-gray-400 font-normal"><?php echo htmlspecialchars($log['sent_at']); ?></span>
                                    </div>
                                    <p class="text-gray-300 mt-1"><?php echo htmlspecialchars($log['message']); ?></p>
                                </div>
                                <span class="px-2 py-0.5 bg-green-500/10 text-green-400 border border-green-500/10 rounded-md text-[9px] font-bold uppercase"><?php echo htmlspecialchars($log['status']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sender ID Requests Section -->
        <?php if ($user_role !== 'teacher') : ?>
        <div id="sender-id-requests" class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-8">
            <!-- Request Form -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl">
                <h3 class="text-lg font-semibold mb-4 text-indigo-300">Request Sender ID & Template</h3>
                <p class="text-xs text-gray-400 mb-6">Submit a custom 11-character Sender ID (alphanumeric) and sample message template for platform administrator review.</p>
                
                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-xs text-gray-405 mb-1">Requested Sender ID (Max 11 characters)</label>
                        <input type="text" name="sender_id" maxlength="11" required placeholder="e.g. SCHOOLNAME" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-indigo-500 text-sm font-bold uppercase tracking-wider text-center" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-405 mb-1">Sample Message Body (Template)</label>
                        <textarea name="sample_sms" rows="4" required placeholder="e.g. Dear Parent, your child was marked present at school today." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-indigo-500 text-xs"></textarea>
                    </div>
                    <button type="submit" name="request_sender_id" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition text-sm">
                        Submit Request
                    </button>
                </form>
            </div>

            <!-- Requests History -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-4 text-blue-300">Requested Sender ID Approvals</h3>
                
                <?php if (empty($sender_requests)) : ?>
                    <p class="text-gray-400 text-sm">No requests submitted yet.</p>
                <?php else : ?>
                    <div class="space-y-3 max-h-96 overflow-y-auto pr-2">
                        <?php foreach ($sender_requests as $req) : ?>
                            <div class="p-4 bg-white/5 rounded-xl border border-white/5 space-y-2">
                                <div class="flex justify-between items-center">
                                    <strong class="text-white text-xs font-mono uppercase bg-white/5 px-2 py-0.5 rounded border border-white/10"><?php echo htmlspecialchars($req['sender_id']); ?></strong>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php 
                                        if ($req['status'] === 'approved') echo 'bg-green-500/10 text-green-400 border border-green-500/10';
                                        elseif ($req['status'] === 'pending') echo 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/10';
                                        else echo 'bg-red-500/10 text-red-400 border border-red-500/10';
                                    ?>">
                                        <?php echo htmlspecialchars($req['status']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-300 bg-black/20 p-2.5 rounded-lg border border-white/5 italic">"<?php echo htmlspecialchars($req['sample_sms']); ?>"</p>
                                <div class="text-[10px] text-gray-500 text-right"><?php echo htmlspecialchars($req['created_at']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <script>
        function updateCharCounter(val) {
            const len = val.length;
            document.getElementById('char_counter_len').innerText = 'Characters: ' + len;
            
            let units = 0;
            if (len > 0) {
                if (len <= 160) {
                    units = 1;
                } else {
                    units = Math.ceil(len / 153);
                }
            }
            
            const counterUnits = document.getElementById('char_counter_units');
            counterUnits.innerText = 'Pages: ' + units + ' / 3 (Max 459 chars)';
            
            if (len > 459) {
                counterUnits.classList.remove('text-gray-400');
                counterUnits.classList.add('text-red-400', 'font-bold');
            } else {
                counterUnits.classList.remove('text-red-400', 'font-bold');
                counterUnits.classList.add('text-gray-400');
            }
        }
        </script>

    <?php elseif ($action === 'notices') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-indigo-300">Publish Notice Bulletin</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Notice Title / Subject</label>
                    <input type="text" name="notice_title" required placeholder="e.g. End of Term Examination Notice" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Detailed Message/Body</label>
                    <textarea name="notice_comment" rows="4" required placeholder="Notice description details..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"></textarea>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Scope / Target Audience</label>
                        <select name="notice_for" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="all">All Groups</option>
                            <option value="students">Students Only</option>
                            <option value="teachers">Teachers Only</option>
                            <option value="parents">Parents Only</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Class Scope (Limit visibility)</label>
                        <input type="text" name="numeric_class_name" value="all" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Display Start Date</label>
                        <input type="date" name="start_date" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Expiry Date</label>
                        <input type="date" name="end_date" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <button type="submit" name="create_notice" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition">
                    Publish Bulletin
                </button>
            </form>
        </div>
    <?php elseif ($action === 'email') : ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Email Form (Left 2 cols) -->
            <div class="lg:col-span-2 bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl space-y-6">
                <h3 class="text-lg font-semibold text-emerald-300">Send Email Broadcast</h3>
                
                <!-- AI Smart Compose -->
                <div class="p-4 bg-white/5 border border-white/10 rounded-xl space-y-3">
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider">✨ Gmail-like AI Smart Compose</label>
                    <div class="flex gap-2">
                        <input type="text" id="ai-prompt" placeholder="e.g. Announce a parents teacher meeting this Friday at 4pm" class="flex-grow bg-black/40 border border-white/10 rounded-lg px-4 py-2 text-white text-sm focus:outline-none focus:border-emerald-500">
                        <button type="button" onclick="composeEmailViaAI()" id="ai-btn" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-bold transition-all shrink-0">✨ Compose</button>
                    </div>
                </div>

                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Recipient Scope</label>
                        <select name="recipient_scope" id="recipient_scope" onchange="toggleRecipientScope(this.value)" class="w-full bg-slate-900 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-emerald-500">
                            <option value="individual">Send to Individual Email</option>
                            <option value="some">Select Some Users (Checklist)</option>
                            <option value="all">Send to All Users (Students, Teachers, Parents)</option>
                        </select>
                    </div>

                    <!-- Individual Input -->
                    <div id="wrapper-individual">
                        <label class="block text-sm font-medium text-gray-400 mb-1">Email Address</label>
                        <input type="email" name="email_address" placeholder="e.g. recipient@example.com" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <!-- Checklist Selector -->
                    <div id="wrapper-some" class="space-y-4" style="display: none;">
                        <label class="block text-sm font-medium text-gray-400 mb-1">Select Recipients</label>
                        
                        <div class="border border-white/10 rounded-xl p-4 space-y-4 max-h-80 overflow-y-auto">
                            <!-- Students Checklist -->
                            <div>
                                <div class="flex justify-between items-center bg-white/5 p-2 rounded mb-2">
                                    <span class="text-xs font-bold text-blue-300">Students</span>
                                    <label class="text-xs text-gray-400 cursor-pointer"><input type="checkbox" onclick="toggleSelectAll('chk-student', this.checked)"> Select All</label>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($school_students as $stu): ?>
                                        <label class="flex items-center gap-2 text-xs text-gray-300 cursor-pointer">
                                            <input type="checkbox" name="selected_emails[]" value="<?php echo htmlspecialchars($stu['email']); ?>" class="chk-student">
                                            <span><?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname'] . ' (' . $stu['email'] . ')'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Teachers Checklist -->
                            <div>
                                <div class="flex justify-between items-center bg-white/5 p-2 rounded mb-2">
                                    <span class="text-xs font-bold text-indigo-300">Teachers</span>
                                    <label class="text-xs text-gray-400 cursor-pointer"><input type="checkbox" onclick="toggleSelectAll('chk-teacher', this.checked)"> Select All</label>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($school_teachers as $t): ?>
                                        <label class="flex items-center gap-2 text-xs text-gray-300 cursor-pointer">
                                            <input type="checkbox" name="selected_emails[]" value="<?php echo htmlspecialchars($t['email']); ?>" class="chk-teacher">
                                            <span><?php echo htmlspecialchars($t['firstname'] . ' ' . $t['lastname'] . ' (' . $t['email'] . ')'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Parents Checklist -->
                            <div>
                                <div class="flex justify-between items-center bg-white/5 p-2 rounded mb-2">
                                    <span class="text-xs font-bold text-emerald-300">Parents</span>
                                    <label class="text-xs text-gray-400 cursor-pointer"><input type="checkbox" onclick="toggleSelectAll('chk-parent', this.checked)"> Select All</label>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($school_parents as $p): ?>
                                        <label class="flex items-center gap-2 text-xs text-gray-300 cursor-pointer">
                                            <input type="checkbox" name="selected_emails[]" value="<?php echo htmlspecialchars($p['email']); ?>" class="chk-parent">
                                            <span><?php echo htmlspecialchars($p['fullname'] . ' (' . $p['email'] . ')'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Subject</label>
                        <input type="text" name="subject" id="email-subject" required placeholder="Email subject line" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Email Body</label>
                        <textarea name="body" id="email-body" rows="8" required placeholder="Write your message here..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-emerald-500 text-sm"></textarea>
                    </div>

                    <button type="submit" name="send_email" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold transition shadow-lg shadow-emerald-600/15">
                        🚀 Send Email Broadcast
                    </button>
                </form>
            </div>
            
            <!-- Instructions/Tip Pane (Right 1 col) -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl space-y-4 h-fit">
                <h4 class="font-bold text-white">Email Dispatcher Guide</h4>
                <p class="text-xs text-gray-400 leading-relaxed">Broadcast newsletters, emergency warnings, and terminal announcements directly to parents, faculty, and student portals.</p>
                <div class="p-3 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-lg text-[11px] leading-relaxed">
                    <strong>Tip:</strong> The smart compose tool lets you input a simple prompt to write the subject and outline email templates instantly using the system's integrated DeepSeek AI.
                </div>
            </div>
        </div>

        <script>
        function toggleRecipientScope(val) {
            document.getElementById('wrapper-individual').style.display = (val === 'individual') ? 'block' : 'none';
            document.getElementById('wrapper-some').style.display = (val === 'some') ? 'block' : 'none';
        }

        function toggleSelectAll(className, checked) {
            document.querySelectorAll('.' + className).forEach(chk => chk.checked = checked);
        }

        async function composeEmailViaAI() {
            const promptInput = document.getElementById('ai-prompt');
            const promptVal = promptInput.value.trim();
            const btn = document.getElementById('ai-btn');

            if (!promptVal) {
                alert('Please enter a description or prompt for the email.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Composing...';

            try {
                const formData = new FormData();
                formData.append('ajax_ai_compose', promptVal);

                const response = await fetch('dashboard.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.subject && data.body) {
                    document.getElementById('email-subject').value = data.subject;
                    document.getElementById('email-body').value = data.body;
                } else {
                    alert('Failed to compose email templates.');
                }
            } catch(e) {
                alert('AI helper server connection timeout.');
            } finally {
                btn.disabled = false;
                btn.textContent = '✨ Compose';
            }
        }
        </script>
    <?php endif; ?>
</div>
