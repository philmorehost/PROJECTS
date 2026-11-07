<?php
if (!defined('PORTAL_PATH')) exit;

// Create settings table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sm_id_card_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id_number VARCHAR(50) NOT NULL UNIQUE,
        primary_color VARCHAR(20) DEFAULT '#2563eb',
        secondary_color VARCHAR(20) DEFAULT '#ffffff',
        text_color VARCHAR(20) DEFAULT '#1f2937',
        signature_image VARCHAR(255) DEFAULT NULL,
        card_template VARCHAR(50) DEFAULT 'modern',
        validity_text VARCHAR(100) DEFAULT 'Valid for Current Session',
        school_address VARCHAR(255) DEFAULT ''
    )");
} catch(Exception $e) {}

$msg = '';
$action = $_GET['action'] ?? 'view';

// Fetch settings
$stmt_set = $pdo->prepare("SELECT * FROM sm_id_card_settings WHERE school_id_number = ?");
$stmt_set->execute([$school_id]);
$id_settings = $stmt_set->fetch(PDO::FETCH_ASSOC);

if (!$id_settings) {
    $pdo->prepare("INSERT INTO sm_id_card_settings (school_id_number) VALUES (?)")->execute([$school_id]);
    $stmt_set->execute([$school_id]);
    $id_settings = $stmt_set->fetch(PDO::FETCH_ASSOC);
}

// Handle POST actions for admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user_role, ['school_admin', 'super_admin'])) {
    if (isset($_POST['save_settings'])) {
        $p_color = trim($_POST['primary_color']);
        $s_color = trim($_POST['secondary_color'] ?? $id_settings['secondary_color'] ?? '#ffffff');
        $t_color = trim($_POST['text_color']);
        $val_txt = trim($_POST['validity_text']);
        $sch_add = trim($_POST['school_address']);
        $template = trim($_POST['card_template']);

        $sig_url = $id_settings['signature_image'];
        if (isset($_FILES['signature']) && $_FILES['signature']['error'] == UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION);
            $sig_name = 'sig_' . $school_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['signature']['tmp_name'], PORTAL_PATH . '/dataimg/' . $sig_name)) {
                $sig_url = '/dataimg/' . $sig_name;
            }
        }

        $stmt = $pdo->prepare("UPDATE sm_id_card_settings SET primary_color=?, secondary_color=?, text_color=?, validity_text=?, school_address=?, card_template=?, signature_image=? WHERE school_id_number=?");
        $stmt->execute([$p_color, $s_color, $t_color, $val_txt, $sch_add, $template, $sig_url, $school_id]);
        $msg = "ID Card settings updated successfully.";
        
        // Refresh settings
        $stmt_set->execute([$school_id]);
        $id_settings = $stmt_set->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch classes for dropdown (Admin gets all, Teacher gets assigned)
$classes = [];
if (in_array($user_role, ['school_admin', 'super_admin'])) {
    $stmt_cls = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? ORDER BY class_name");
    $stmt_cls->execute([$school_id]);
    $classes = $stmt_cls->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'teacher') {
    $stmtTInfo = $pdo->prepare("SELECT class FROM sm_teachers WHERE (email = ? OR id_number = ?) AND school_id_number = ? LIMIT 1");
    $stmtTInfo->execute([$_SESSION['user_session'], $_SESSION['user_session'], $school_id]);
    $t_classes_str = $stmtTInfo->fetchColumn();
    $teacher_classes = array_filter(array_map('trim', explode("\n", $t_classes_str ?? '')));
    
    if (!empty($teacher_classes)) {
        $in_clause = implode(',', array_fill(0, count($teacher_classes), '?'));
        $stmt_cls = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? AND numeric_class_name IN ($in_clause) ORDER BY class_name");
        $stmt_cls->execute(array_merge([$school_id], $teacher_classes));
        $classes = $stmt_cls->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Determine which students to show
$students = [];
$selected_class = $_GET['class'] ?? '';

if ($user_role === 'student') {
    $stmt_stu = $pdo->prepare("SELECT * FROM sm_students WHERE (email = ? OR admission_number = ?) AND school_id_number = ? LIMIT 1");
    $stmt_stu->execute([$_SESSION['user_session'], $_SESSION['user_session'], $school_id]);
    $student = $stmt_stu->fetch(PDO::FETCH_ASSOC);
    if ($student) {
        $student['is_staff'] = false;
        $students[] = $student;
    }
} elseif (in_array($user_role, ['school_admin', 'super_admin']) && $selected_class === 'STAFF_TEACHER') {
    $stmt_stu = $pdo->prepare("SELECT id_number AS admission_number, firstname, lastname, class AS current_class, dob FROM sm_teachers WHERE school_id_number = ? ORDER BY lastname, firstname");
    $stmt_stu->execute([$school_id]);
    $results = $stmt_stu->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $r) {
        $r['is_staff'] = true;
        $students[] = $r;
    }
} elseif ($user_role === 'teacher' && $selected_class === 'STAFF_MYSELF') {
    $stmt_stu = $pdo->prepare("SELECT id_number AS admission_number, firstname, lastname, class AS current_class, dob FROM sm_teachers WHERE (email = ? OR id_number = ?) AND school_id_number = ? LIMIT 1");
    $stmt_stu->execute([$_SESSION['user_session'], $_SESSION['user_session'], $school_id]);
    $teacher = $stmt_stu->fetch(PDO::FETCH_ASSOC);
    if ($teacher) {
        $teacher['is_staff'] = true;
        $students[] = $teacher;
    }
} elseif (in_array($user_role, ['school_admin', 'super_admin', 'teacher']) && $selected_class) {
    $has_access = false;
    if ($user_role === 'teacher') {
        foreach ($classes as $c) {
            if ($c['numeric_class_name'] === $selected_class) {
                $has_access = true; break;
            }
        }
    } else {
        $has_access = true;
    }
    
    if ($has_access) {
        $stmt_stu = $pdo->prepare("SELECT * FROM sm_students WHERE school_id_number = ? AND current_class = ? ORDER BY lastname, firstname");
        $stmt_stu->execute([$school_id, $selected_class]);
        $results = $stmt_stu->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as $r) {
            $r['is_staff'] = false;
            $students[] = $r;
        }
    }
}

function getIdCardStudentPhoto($stu, $school_id) {
    $photo_webp = 'dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.webp';
    $photo_png = 'dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.png';
    $photo_jpg = 'dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.jpg';

    if (file_exists(PORTAL_PATH . '/' . $photo_webp)) return '/' . $photo_webp;
    elseif (file_exists(PORTAL_PATH . '/' . $photo_png)) return '/' . $photo_png;
    elseif (file_exists(PORTAL_PATH . '/' . $photo_jpg)) return '/' . $photo_jpg;

    return 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23cbd5e1"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 4c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm0 13.6c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';
}

$school_logo = 'assets/images/default_logo.png';
$stmt_s = $pdo->prepare("SELECT logo_url, name AS school_name FROM sm_schools WHERE id = ?");
$stmt_s->execute([$school_id]);
$sch_info = $stmt_s->fetch(PDO::FETCH_ASSOC);
if ($sch_info && !empty($sch_info['logo_url'])) {
    $school_logo = $sch_info['logo_url'];
}
$school_name = $sch_info ? $sch_info['school_name'] : 'School';
?>
<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🪪 ID Cards Management</h2>
        <div class="flex gap-2">
            <?php if (in_array($user_role, ['school_admin', 'super_admin'])) : ?>
                <a href="?page=id_card_view&action=settings" class="px-4 py-2 bg-white/5 border border-white/10 hover:bg-white/10 text-white rounded-lg text-sm font-semibold transition">⚙️ Configuration</a>
            <?php endif; ?>
            <?php if ($user_role !== 'student') : ?>
                <button onclick="window.print()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition print:hidden">🖨️ Print ID Cards</button>
            <?php endif; ?>
            <?php if ($user_role === 'student') : ?>
                <button onclick="window.print()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition print:hidden">🖨️ Print ID Card</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'settings' && in_array($user_role, ['school_admin', 'super_admin'])) : ?>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <h3 class="text-lg font-semibold mb-4 text-blue-300">ID Card Configuration</h3>
            <form method="post" enctype="multipart/form-data" class="space-y-4 max-w-2xl">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Primary Color</label>
                        <input type="color" name="primary_color" value="<?php echo htmlspecialchars($id_settings['primary_color']); ?>" class="w-full h-10 rounded border border-white/10 bg-transparent cursor-pointer" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Text Color</label>
                        <input type="color" name="text_color" value="<?php echo htmlspecialchars($id_settings['text_color']); ?>" class="w-full h-10 rounded border border-white/10 bg-transparent cursor-pointer" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Validity Text</label>
                    <input type="text" name="validity_text" value="<?php echo htmlspecialchars($id_settings['validity_text']); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">School Address</label>
                    <input type="text" name="school_address" value="<?php echo htmlspecialchars($id_settings['school_address']); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Template Style</label>
                    <select name="card_template" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                        <option value="modern" <?php echo $id_settings['card_template'] === 'modern' ? 'selected' : ''; ?>>Modern</option>
                        <option value="classic" <?php echo $id_settings['card_template'] === 'classic' ? 'selected' : ''; ?>>Classic</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Authorized Signature Image (PNG/JPG)</label>
                    <?php if ($id_settings['signature_image']) : ?>
                        <div class="mb-2"><img src="<?php echo htmlspecialchars($id_settings['signature_image']); ?>" class="h-10 object-contain" alt="Signature" /></div>
                    <?php endif; ?>
                    <input type="file" name="signature" accept="image/*" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white text-sm" />
                </div>
                <button type="submit" name="save_settings" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-sm transition">Save Configuration</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($action === 'view') : ?>
        
        <?php if ($user_role !== 'student') : ?>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-4 shadow-xl backdrop-blur-md print:hidden mb-6 flex flex-wrap items-end gap-4">
                <form method="get" class="flex flex-wrap items-end gap-4">
                    <input type="hidden" name="page" value="id_card_view" />
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Select Class to View ID Cards</label>
                        <select name="class" class="bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm min-w-[200px]" required>
                            <option value="">-- Select Class --</option>
                            <?php if ($user_role === 'teacher') : ?>
                                <option value="STAFF_MYSELF" <?php echo $selected_class === 'STAFF_MYSELF' ? 'selected' : ''; ?>>🪪 My Staff ID Card</option>
                            <?php endif; ?>
                            <?php if (in_array($user_role, ['school_admin', 'super_admin'])) : ?>
                                <option value="STAFF_TEACHER" <?php echo $selected_class === 'STAFF_TEACHER' ? 'selected' : ''; ?>>👨‍🏫 All Staff / Teachers</option>
                            <?php endif; ?>
                            <?php foreach ($classes as $c) : ?>
                                <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>" <?php echo $selected_class === $c['numeric_class_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold text-sm transition">Generate</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($students)) : ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 print:block">
                <?php foreach ($students as $stu) : ?>
                    <div class="relative w-[340px] h-[540px] bg-white rounded-xl shadow-2xl overflow-hidden print:w-[3.375in] print:h-[2.125in] print:float-left print:m-2 print:page-break-inside-avoid print:shadow-none print:border print:border-gray-300 mx-auto transform transition hover:scale-[1.02]">
                        <?php if ($id_settings['card_template'] === 'modern') : ?>
                            <!-- Modern Vertical ID Card Template -->
                            <div class="absolute inset-0 z-0">
                                <svg width="100%" height="100%" preserveAspectRatio="none" viewBox="0 0 340 540">
                                    <path d="M0,0 L340,0 L340,180 Q170,250 0,180 Z" fill="<?php echo htmlspecialchars($id_settings['primary_color']); ?>"/>
                                    <path d="M0,450 Q170,420 340,450 L340,540 L0,540 Z" fill="<?php echo htmlspecialchars($id_settings['primary_color']); ?>"/>
                                </svg>
                            </div>
                            <div class="relative z-10 flex flex-col items-center h-full pt-4 pb-4 px-6 text-center">
                                <div class="w-12 h-12 bg-white rounded-full p-1 shadow-md mb-2 flex items-center justify-center overflow-hidden">
                                    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" class="max-h-full max-w-full object-contain" />
                                </div>
                                <h3 class="text-white font-bold text-sm leading-tight uppercase tracking-wide px-2 drop-shadow-md"><?php echo htmlspecialchars($school_name); ?></h3>
                                
                                <div class="mt-8 mb-4">
                                    <div class="w-28 h-28 bg-white p-1 rounded-2xl shadow-xl mx-auto rotate-3 hover:rotate-0 transition-transform duration-300">
                                        <div class="w-full h-full overflow-hidden rounded-xl">
                                            <img src="<?php echo getIdCardStudentPhoto($stu, $school_id); ?>" class="w-full h-full object-cover" alt="Student Photo" />
                                        </div>
                                    </div>
                                </div>

                                <h2 class="text-xl font-extrabold uppercase mt-1 mb-1" style="color: <?php echo htmlspecialchars($id_settings['text_color']); ?>"><?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname']); ?></h2>
                                <?php if (!empty($stu['is_staff'])) : ?>
                                    <p class="text-sm font-semibold uppercase text-emerald-600 mb-4 bg-emerald-50 px-3 py-1 rounded-full inline-block tracking-widest shadow-sm">STAFF / TEACHER</p>
                                    <div class="w-full text-left space-y-1.5 text-xs font-semibold px-4" style="color: <?php echo htmlspecialchars($id_settings['text_color']); ?>">
                                        <div class="flex justify-between border-b border-gray-200 pb-1">
                                            <span class="text-gray-500 uppercase">Staff ID:</span>
                                            <span><?php echo htmlspecialchars($stu['admission_number']); ?></span>
                                        </div>
                                    </div>
                                <?php else : ?>
                                    <p class="text-sm font-semibold uppercase text-blue-600 mb-4 bg-blue-50 px-3 py-1 rounded-full inline-block tracking-widest shadow-sm">STUDENT</p>
                                    <div class="w-full text-left space-y-1.5 text-xs font-semibold px-4" style="color: <?php echo htmlspecialchars($id_settings['text_color']); ?>">
                                        <div class="flex justify-between border-b border-gray-200 pb-1">
                                            <span class="text-gray-500 uppercase">ID No:</span>
                                            <span><?php echo htmlspecialchars($stu['admission_number']); ?></span>
                                        </div>
                                        <div class="flex justify-between border-b border-gray-200 pb-1">
                                            <span class="text-gray-500 uppercase">Class:</span>
                                            <span><?php echo htmlspecialchars($stu['current_class']); ?></span>
                                        </div>
                                        <?php if (!empty($stu['dob']) && $stu['dob'] !== '0000-00-00') : ?>
                                        <div class="flex justify-between border-b border-gray-200 pb-1">
                                            <span class="text-gray-500 uppercase">DOB:</span>
                                            <span><?php echo htmlspecialchars($stu['dob']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>                      </div>

                                <div class="mt-auto flex flex-col items-center w-full">
                                    <?php if ($id_settings['signature_image']) : ?>
                                        <img src="<?php echo htmlspecialchars($id_settings['signature_image']); ?>" class="h-8 object-contain mb-1 drop-shadow-sm" alt="Signature" />
                                    <?php else : ?>
                                        <div class="h-8 border-b-2 border-dashed border-gray-400 w-32 mb-1 opacity-50"></div>
                                    <?php endif; ?>
                                    <span class="text-[10px] font-bold uppercase text-white/90 pb-1">Authorized Signature</span>
                                    <div class="w-full bg-black/10 py-1.5 rounded text-[9px] text-white font-medium uppercase tracking-wider">
                                        <?php echo htmlspecialchars($id_settings['validity_text']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php else : ?>
                            <!-- Classic Horizontal ID Card Template -->
                            <!-- Note: Width/Height swapped visually using padding/layout for horizontal look -->
                            <div class="absolute inset-0 bg-white z-0 flex flex-col">
                                <div class="h-24 w-full flex items-center px-4" style="background-color: <?php echo htmlspecialchars($id_settings['primary_color']); ?>;">
                                    <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo" class="h-16 w-16 bg-white p-1 rounded-lg object-contain mr-3 shadow-md" />
                                    <div>
                                        <h3 class="text-white font-bold text-lg leading-tight uppercase drop-shadow-md"><?php echo htmlspecialchars($school_name); ?></h3>
                                        <p class="text-white/80 text-xs font-medium mt-1"><?php echo htmlspecialchars($id_settings['school_address'] ?? 'STUDENT IDENTITY CARD'); ?></p>
                                    </div>
                                </div>
                                <div class="flex-1 p-4 flex gap-4">
                                    <div class="w-24 shrink-0">
                                        <img src="<?php echo getIdCardStudentPhoto($stu, $school_id); ?>" class="w-24 h-28 object-cover rounded-lg shadow-md border-2 border-gray-100" alt="Student Photo" />
                                    </div>
                                    <div class="flex-1 text-sm font-semibold space-y-2 mt-2" style="color: <?php echo htmlspecialchars($id_settings['text_color']); ?>">
                                        <div>
                                            <span class="text-gray-400 text-xs uppercase block">Name</span>
                                            <span class="text-base font-bold uppercase"><?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname']); ?></span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <?php if (!empty($stu['is_staff'])) : ?>
                                                <div>
                                                    <span class="text-gray-400 text-[10px] uppercase block">Staff ID</span>
                                                    <span><?php echo htmlspecialchars($stu['admission_number']); ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-emerald-500 text-[10px] font-bold uppercase block mt-1">STAFF</span>
                                                </div>
                                            <?php else : ?>
                                                <div>
                                                    <span class="text-gray-400 text-[10px] uppercase block">ID Number</span>
                                                    <span><?php echo htmlspecialchars($stu['admission_number']); ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-400 text-[10px] uppercase block">Class</span>
                                                    <span><?php echo htmlspecialchars($stu['current_class']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="h-16 w-full flex justify-between items-center px-4 bg-gray-50 border-t border-gray-200">
                                    <div class="text-[10px] font-medium text-gray-500 uppercase">
                                        <?php echo htmlspecialchars($id_settings['validity_text']); ?>
                                    </div>
                                    <div class="flex flex-col items-center">
                                        <?php if ($id_settings['signature_image']) : ?>
                                            <img src="<?php echo htmlspecialchars($id_settings['signature_image']); ?>" class="h-8 object-contain mb-0.5" alt="Signature" />
                                        <?php else : ?>
                                            <div class="h-6 border-b border-gray-400 w-20 mb-1"></div>
                                        <?php endif; ?>
                                        <span class="text-[8px] font-bold text-gray-500 uppercase">Principal Sign</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($user_role !== 'student' && $selected_class) : ?>
            <p class="text-gray-400 mt-4">No students found in this class.</p>
        <?php elseif ($user_role === 'student') : ?>
            <p class="text-red-400 mt-4">Unable to load your ID card details.</p>
        <?php endif; ?>
    <?php endif; ?>

    <style>
        @media print {
            body * {
                visibility: hidden;
            }
            .print\:block, .print\:block * {
                visibility: visible;
            }
            .print\:block {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                justify-content: flex-start;
                gap: 10px;
            }
            .print\:hidden {
                display: none !important;
            }
            @page {
                size: A4;
                margin: 0.5cm;
            }
        }
    </style>
</div>
