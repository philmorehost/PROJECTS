<?php
// Academics Management View — Sessions, Classes, Class Categories, Terms, Subjects, Timetable, Live Classes
if (!defined('PORTAL_PATH')) exit;

$msg = '';
$msg_type = 'green';
$action = isset($_GET['action']) ? $_GET['action'] : 'overview';

// ── Handle all POST submissions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ── Add Session ──────────────────────────────────────────────
        if (isset($_POST['add_session'])) {
            $name = trim($_POST['session_name']);
            $start = trim($_POST['start_year']);
            $end   = trim($_POST['end_year']);
            $status = trim($_POST['session_status']);
            $stmt = $pdo->prepare("INSERT INTO sm_sessions (school_id_number, session, session_name, start_year, end_year, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $name, $name, $start, $end, $status]);
            if ($status === 'active') {
                // Deactivate all others
                $pdo->prepare("UPDATE sm_sessions SET status='inactive' WHERE school_id_number = ? AND session_name != ?")->execute([$school_id, $name]);
            }
            $msg = "Academic session added!";
        }

        // ── Add Class Category ──────────────────────────────────────
        elseif (isset($_POST['add_category'])) {
            $name = trim($_POST['category_name']);
            $order = (int)($_POST['display_order'] ?? 0);
            $stmt = $pdo->prepare("INSERT INTO sm_class_categories (school_id_number, category_name, display_order) VALUES (?, ?, ?)");
            $stmt->execute([$school_id, $name, $order]);
            $msg = "Class category added!";
        }

        // ── Add Class ───────────────────────────────────────────────
        elseif (isset($_POST['add_class'])) {
            $name      = trim($_POST['class_name']);
            
            // Automatically generate unique numeric_class_name (Class Code) from Class Label
            $base_code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '-', $name));
            $base_code = preg_replace('/-+/', '-', $base_code);
            $base_code = trim($base_code, '-');
            if (empty($base_code)) {
                $base_code = 'CLS';
            }
            
            $num_name = $base_code;
            $i = 1;
            while (true) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM sm_classes WHERE school_id_number = ? AND numeric_class_name = ?");
                $check->execute([$school_id, $num_name]);
                if ($check->fetchColumn() == 0) {
                    break;
                }
                $num_name = $base_code . '-' . $i;
                $i++;
            }
            
            $capacity  = (int)$_POST['student_capacity'];
            $sess      = trim($_POST['session']);
            $category  = trim($_POST['category_id'] ?? '');
            $category_val = ($category === '') ? null : (int)$category;
            $stmt = $pdo->prepare("INSERT INTO sm_classes (school_id_number, class_name, numeric_class_name, student_capacity, session, category_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $name, $num_name, $capacity, $sess, $category_val]);
            $msg = "Class added! (Generated Code: " . htmlspecialchars($num_name) . ")";
        }

        // ── Edit Class ──────────────────────────────────────────────
        elseif (isset($_POST['edit_class'])) {
            $id       = (int)$_POST['class_id'];
            $name     = trim($_POST['class_name']);
            $capacity = (int)$_POST['student_capacity'];
            $category = trim($_POST['category_id'] ?? '');
            $category_val = ($category === '') ? null : (int)$category;
            $stmt = $pdo->prepare("UPDATE sm_classes SET class_name = ?, student_capacity = ?, category_id = ? WHERE id = ? AND school_id_number = ?");
            $stmt->execute([$name, $capacity, $category_val, $id, $school_id]);
            $msg = "Class updated!";
        }

        // ── Delete Class ─────────────────────────────────────────────
        elseif (isset($_POST['delete_class'])) {
            $id = (int)$_POST['class_id'];
            $pdo->prepare("DELETE FROM sm_classes WHERE id = ? AND school_id_number = ?")->execute([$id, $school_id]);
            $msg = "Class removed.";
        }

        // ── Add Term ────────────────────────────────────────────────
        elseif (isset($_POST['add_term'])) {
            $id_num  = strtoupper(trim($_POST['id_number']));
            $name    = trim($_POST['term_name']);
            $sess    = trim($_POST['session']);
            $opens   = trim($_POST['school_open_days'] ?? '0');
            $next    = trim($_POST['next_term_begins'] ?? '');
            $next_val = ($next === '') ? null : $next;
            $stmt = $pdo->prepare("INSERT INTO sm_terms (school_id_number, id_number, term_name, session, school_open_days, next_term_begins) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $id_num, $name, $sess, $opens, $next_val]);
            $msg = "Term created!";
        }

        // ── Edit Term ────────────────────────────────────────────────
        elseif (isset($_POST['edit_term'])) {
            $term_id  = (int)$_POST['term_id'];
            $name     = trim($_POST['term_name']);
            $sess     = trim($_POST['session']);
            $opens    = (int)($_POST['school_open_days'] ?? 0);
            $next     = trim($_POST['next_term_begins'] ?? '');
            $next_val = ($next === '') ? null : $next;
            $stmt = $pdo->prepare("UPDATE sm_terms SET term_name = ?, session = ?, school_open_days = ?, next_term_begins = ? WHERE id = ? AND school_id_number = ?");
            $stmt->execute([$name, $sess, $opens, $next_val, $term_id, $school_id]);
            $msg = "Term updated successfully!";
        }

        // ── Delete Term ───────────────────────────────────────────────
        elseif (isset($_POST['delete_term'])) {
            $term_id = (int)$_POST['term_id'];
            $pdo->prepare("DELETE FROM sm_terms WHERE id = ? AND school_id_number = ?")->execute([$term_id, $school_id]);
            $msg = "Term deleted.";
            $msg_type = 'red';
        }

        // ── Add Subject ─────────────────────────────────────────────
        elseif (isset($_POST['add_subject'])) {
            $code     = strtoupper(trim($_POST['subject_code']));
            $name     = trim($_POST['subject_name']);
            $class    = trim($_POST['class_code']);
            
            // Get active session for subject
            $sess_stmt = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? AND status = 'active' LIMIT 1");
            $sess_stmt->execute([$school_id]);
            $session = $sess_stmt->fetchColumn();
            if (!$session) {
                $sess_stmt = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC LIMIT 1");
                $sess_stmt->execute([$school_id]);
                $session = $sess_stmt->fetchColumn();
            }
            if (!$session) {
                $session = '2025/2026';
            }
            
            $stmt = $pdo->prepare("INSERT INTO sm_subjects (school_id_number, subject_code, subject_name, numeric_class_name, teacher_id_number, session) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $code, $name, $class, null, $session]);
            $msg = "Subject added!";
        }

        // ── Delete Subject ───────────────────────────────────────────
        elseif (isset($_POST['delete_subject'])) {
            $id = (int)$_POST['subject_id'];
            $pdo->prepare("DELETE FROM sm_subjects WHERE id = ? AND school_id_number = ?")->execute([$id, $school_id]);
            $msg = "Subject removed.";
        }

        // ── Add Timetable Entry ──────────────────────────────────────
        elseif (isset($_POST['add_timetable'])) {
            $class   = trim($_POST['class_code']);
            $sess    = trim($_POST['session']);
            $day     = trim($_POST['day_of_week']);
            $period  = trim($_POST['period']);
            $subject = trim($_POST['subject_code']);
            $teacher = trim($_POST['teacher_name'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO sm_timetable (school_id_number, numeric_class_name, session, day_of_week, period, subject_code, teacher_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $class, $sess, $day, $period, $subject, $teacher]);
            $msg = "Timetable entry added!";
        }

        // ── Schedule Live Class ──────────────────────────────────────
        elseif (isset($_POST['schedule_live'])) {
            $c_name = trim($_POST['class_name']);
            $sub    = trim($_POST['subject_code']);
            $title  = trim($_POST['title']);
            $link   = trim($_POST['meeting_link']);
            $start  = $_POST['start_time'];
            $stmt = $pdo->prepare("INSERT INTO sm_live_classes (school_id_number, class_name, subject_code, title, meeting_link, start_time) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $c_name, $sub, $title, $link, $start]);
            $msg = "Live Class scheduled!";
        }
    } catch (PDOException $e) {
        $msg = "Database Error: " . $e->getMessage();
        $msg_type = 'red';
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
        $msg_type = 'red';
    }
}

// ── Fetch lists ────────────────────────────────────────────────────────────
$sessions     = $pdo->prepare("SELECT * FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC");
$sessions->execute([$school_id]);
$sessions     = $sessions->fetchAll();

$categories   = $pdo->prepare("SELECT * FROM sm_class_categories WHERE school_id_number = ? ORDER BY display_order, id");
$categories->execute([$school_id]);
$categories   = $categories->fetchAll();

$classes      = $pdo->prepare("SELECT c.*, cat.category_name FROM sm_classes c LEFT JOIN sm_class_categories cat ON cat.id = c.category_id WHERE c.school_id_number = ? ORDER BY c.category_id, c.id");
$classes->execute([$school_id]);
$classes      = $classes->fetchAll();

$terms        = $pdo->prepare("SELECT * FROM sm_terms WHERE school_id_number = ? ORDER BY id DESC");
$terms->execute([$school_id]);
$terms        = $terms->fetchAll();

$search_sub = isset($_GET['search_sub']) ? trim($_GET['search_sub']) : '';
$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';

$subject_clauses = ["school_id_number = ?"];
$subject_params = [$school_id];
if ($filter_class !== '') {
    $subject_clauses[] = "numeric_class_name = ?";
    $subject_params[] = $filter_class;
}
if ($search_sub !== '') {
    $subject_clauses[] = "(subject_name LIKE ? OR subject_code LIKE ?)";
    $search_param = '%' . $search_sub . '%';
    $subject_params[] = $search_param;
    $subject_params[] = $search_param;
}
$subject_where = implode(' AND ', $subject_clauses);
$subjects = $pdo->prepare("SELECT * FROM sm_subjects WHERE $subject_where ORDER BY numeric_class_name, subject_name");
$subjects->execute($subject_params);
$subjects = $subjects->fetchAll();

$timetable_clauses = ["school_id_number = ?"];
$timetable_params = [$school_id];
if ($filter_class !== '') {
    $timetable_clauses[] = "numeric_class_name = ?";
    $timetable_params[] = $filter_class;
}
$timetable_where = implode(' AND ', $timetable_clauses);
$timetable = $pdo->prepare("SELECT * FROM sm_timetable WHERE $timetable_where ORDER BY numeric_class_name, day_of_week, period");
$timetable->execute($timetable_params);
$timetable = $timetable->fetchAll();

$live_classes = $pdo->prepare("SELECT * FROM sm_live_classes WHERE school_id_number = ? ORDER BY start_time DESC LIMIT 20");
$live_classes->execute([$school_id]);
$live_classes = $live_classes->fetchAll();

// Get active session
$active_sess = '';
foreach ($sessions as $s) { if ($s['status'] === 'active') { $active_sess = $s['session_name']; break; } }
if (!$active_sess && !empty($sessions)) $active_sess = $sessions[0]['session_name'];
?>

<div class="space-y-6 min-w-0">
    <!-- Header -->
    <div class="flex flex-wrap justify-between items-center gap-3">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">📚 Academic Management</h2>
        <div class="text-xs text-gray-400">Active Session: <span class="text-blue-400 font-bold"><?php echo htmlspecialchars($active_sess ?: 'None set'); ?></span></div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-3 bg-<?php echo $msg_type; ?>-500/10 border border-<?php echo $msg_type; ?>-500/20 text-<?php echo $msg_type; ?>-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="flex overflow-x-auto gap-1 bg-white/5 border border-white/10 rounded-xl p-1">
        <?php
        $tabs = [
            'overview'   => ['📊', 'Overview'],
            'sessions'   => ['📅', 'Sessions'],
            'categories' => ['🏷️', 'Categories'],
            'classes'    => ['🏫', 'Classes'],
            'terms'      => ['📆', 'Terms'],
            'subjects'   => ['📖', 'Subjects'],
            'timetable'  => ['🗓️', 'Timetable'],
            'live'       => ['🎥', 'Live Classes'],
        ];
        foreach ($tabs as $tab_key => [$icon, $label]) :
            $active_tab = ($action === $tab_key) ? 'bg-blue-600 text-white shadow-lg' : 'text-gray-400 hover:text-white hover:bg-white/5';
        ?>
            <a href="?page=academics&action=<?php echo $tab_key; ?>" class="flex-shrink-0 flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-semibold whitespace-nowrap transition-all <?php echo $active_tab; ?>">
                <?php echo $icon; ?> <?php echo $label; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ── OVERVIEW ──────────────────────────────────────────────────── -->
    <?php if ($action === 'overview') : ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php
        $stats = [
            ['Sessions',   count($sessions),   'blue',   '📅'],
            ['Classes',    count($classes),     'indigo', '🏫'],
            ['Subjects',   count($subjects),    'emerald','📖'],
            ['Terms',      count($terms),       'amber',  '📆'],
        ];
        foreach ($stats as [$label, $count, $color, $icon]) : ?>
        <div class="bg-white/5 border border-white/10 rounded-xl p-5 flex justify-between items-center">
            <div>
                <p class="text-xs text-gray-400 mb-1"><?php echo $label; ?></p>
                <p class="text-2xl font-black text-<?php echo $color; ?>-400"><?php echo $count; ?></p>
            </div>
            <span class="text-2xl"><?php echo $icon; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- Recent Classes -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <h3 class="font-semibold text-blue-300 mb-3">Registered Classes</h3>
            <?php if (empty($classes)) : ?>
                <p class="text-gray-400 text-sm">No classes yet. Go to <a href="?page=academics&action=classes" class="text-blue-400 underline">Classes</a> tab to add.</p>
            <?php else : ?>
                <div class="space-y-1.5">
                    <?php foreach (array_slice($classes, 0, 6) as $c) : ?>
                        <div class="flex justify-between text-sm py-1.5 border-b border-white/5">
                            <span class="text-white font-medium"><?php echo htmlspecialchars($c['class_name']); ?></span>
                            <span class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($c['numeric_class_name']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <!-- Subjects -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
            <h3 class="font-semibold text-indigo-300 mb-3">Registered Subjects</h3>
            <?php if (empty($subjects)) : ?>
                <p class="text-gray-400 text-sm">No subjects yet. Go to <a href="?page=academics&action=subjects" class="text-blue-400 underline">Subjects</a> tab.</p>
            <?php else : ?>
                <div class="space-y-1.5">
                    <?php foreach (array_slice($subjects, 0, 6) as $sub) : ?>
                        <div class="flex justify-between text-sm py-1.5 border-b border-white/5">
                            <span class="text-white"><?php echo htmlspecialchars($sub['subject_name']); ?></span>
                            <span class="text-xs text-indigo-400 font-mono"><?php echo htmlspecialchars($sub['subject_code']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── SESSIONS ─────────────────────────────────────────────────── -->
    <?php elseif ($action === 'sessions') : ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-blue-300 mb-4">Add Academic Session</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Session Name (e.g. 2025/2026)</label>
                    <input type="text" name="session_name" required placeholder="2025/2026" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Start Year</label>
                        <input type="number" name="start_year" placeholder="2025" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">End Year</label>
                        <input type="number" name="end_year" placeholder="2026" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Status</label>
                    <select name="session_status" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                        <option value="active">Active (Current)</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <button type="submit" name="add_session" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-sm transition">Add Session</button>
            </form>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-blue-300 mb-4">Existing Sessions</h3>
            <?php if (empty($sessions)) : ?>
                <p class="text-gray-400 text-sm">No sessions yet.</p>
            <?php else : ?>
                <div class="space-y-2.5 js-paginate" data-limit="10">
                    <?php foreach ($sessions as $s) : ?>
                        <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl border border-white/5 js-paginate-item">
                            <div>
                                <p class="font-bold text-white text-sm"><?php echo htmlspecialchars($s['session_name']); ?></p>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($s['start_year']); ?> — <?php echo htmlspecialchars($s['end_year']); ?></p>
                            </div>
                            <span class="px-2 py-1 rounded-md text-[10px] font-bold uppercase <?php echo $s['status'] === 'active' ? 'bg-green-500/20 text-green-400 border border-green-500/30' : 'bg-white/5 text-gray-400 border border-white/10'; ?>">
                                <?php echo $s['status'] === 'active' ? '✅ Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── CLASS CATEGORIES ─────────────────────────────────────────── -->
    <?php elseif ($action === 'categories') : ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-amber-300 mb-4">Add Class Category</h3>
            <p class="text-xs text-gray-400 mb-4">Categories group classes (e.g. Nursery, Primary, JSS, SSS). They are used in class assignment and report generation.</p>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Category Name</label>
                    <input type="text" name="category_name" required placeholder="e.g. Senior Secondary" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Display Order (0 = first)</label>
                    <input type="number" name="display_order" value="0" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <button type="submit" name="add_category" class="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-semibold text-sm transition">Add Category</button>
            </form>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-amber-300 mb-4">Existing Categories</h3>
            <?php if (empty($categories)) : ?>
                <p class="text-gray-400 text-sm">No categories yet. Add one to organize your classes.</p>
            <?php else : ?>
                <div class="space-y-2 js-paginate" data-limit="10">
                    <?php foreach ($categories as $i => $cat) : ?>
                        <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl border border-white/5 js-paginate-item">
                            <div class="flex items-center gap-3">
                                <span class="w-7 h-7 flex items-center justify-center bg-amber-600/20 text-amber-400 text-xs font-bold rounded-lg"><?php echo $i+1; ?></span>
                                <p class="font-semibold text-white text-sm"><?php echo htmlspecialchars($cat['category_name']); ?></p>
                            </div>
                            <span class="text-xs text-gray-500">Order: <?php echo htmlspecialchars($cat['display_order']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── CLASSES ───────────────────────────────────────────────────── -->
    <?php elseif ($action === 'classes') : ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-blue-300 mb-4">Add Class</h3>
            <form method="post" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Class Label</label>
                        <input type="text" name="class_name" required placeholder="e.g. Primary One" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Class Code</label>
                        <input type="text" name="numeric_class_name" readonly value="Auto-generated" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-gray-500 focus:outline-none text-sm cursor-not-allowed" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Max Capacity</label>
                        <input type="number" name="student_capacity" value="40" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Session</label>
                        <select name="session" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <?php foreach ($sessions as $s) : ?>
                                <option value="<?php echo htmlspecialchars($s['session_name']); ?>" <?php echo $s['status'] === 'active' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['session_name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($sessions)) : ?>
                                <option value="2025/2026">2025/2026</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Category</label>
                    <select name="category_id" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                        <option value="">— No Category —</option>
                        <?php foreach ($categories as $cat) : ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="add_class" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-sm transition">Save Class</button>
            </form>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-blue-300 mb-4">Registered Classes (<?php echo count($classes); ?>)</h3>
            <?php if (empty($classes)) : ?>
                <p class="text-gray-400 text-sm">No classes added yet.</p>
            <?php else : ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[500px] text-sm">
                        <thead><tr class="border-b border-white/10 text-xs text-gray-400">
                            <th class="py-2 text-left">Class Name</th>
                            <th class="py-2 text-left">Code</th>
                            <th class="py-2 text-center">Cap.</th>
                            <th class="py-2 text-left">Category</th>
                            <th class="py-2 text-center">Action</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5 js-paginate" data-limit="10">
                        <?php foreach ($classes as $c) : ?>
                            <tr class="hover:bg-white/3 js-paginate-item">
                                <td class="py-2 text-white font-medium"><?php echo htmlspecialchars($c['class_name']); ?></td>
                                <td class="py-2 text-indigo-400 font-mono text-xs"><?php echo htmlspecialchars($c['numeric_class_name']); ?></td>
                                <td class="py-2 text-center text-gray-300"><?php echo htmlspecialchars($c['student_capacity']); ?></td>
                                <td class="py-2 text-gray-400 text-xs"><?php echo htmlspecialchars($c['category_name'] ?? '—'); ?></td>
                                <td class="py-2 text-center">
                                    <form method="post" onsubmit="return confirm('Remove this class?');" style="display:inline;">
                                        <input type="hidden" name="class_id" value="<?php echo $c['id']; ?>" />
                                        <button type="submit" name="delete_class" class="text-red-400 hover:text-red-300 text-xs">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── TERMS ─────────────────────────────────────────────────────── -->
    <?php elseif ($action === 'terms') : ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-emerald-300 mb-4">Add Term</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Term ID (e.g. TERM-1)</label>
                    <input type="text" name="id_number" required placeholder="TERM-1" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Term Name</label>
                    <input type="text" name="term_name" required placeholder="e.g. First Term" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Session</label>
                    <select name="session" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                        <?php foreach ($sessions as $s) : ?>
                            <option value="<?php echo htmlspecialchars($s['session_name']); ?>" <?php echo $s['status']==='active' ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['session_name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($sessions)) : ?>
                            <option value="2025/2026">2025/2026</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">School Open Days</label>
                        <input type="number" name="school_open_days" value="65" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Next Term Begins</label>
                        <input type="date" name="next_term_begins" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
                <button type="submit" name="add_term" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold text-sm transition">Save Term</button>
            </form>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-emerald-300 mb-4">Terms (<?php echo count($terms); ?>)</h3>
            <?php if (empty($terms)) : ?>
                <p class="text-gray-400 text-sm">No terms added yet.</p>
            <?php else : ?>
                <div class="space-y-2.5 js-paginate" data-limit="10">
                    <?php foreach ($terms as $t) : ?>
                        <div class="p-3 bg-white/5 border border-white/5 rounded-xl js-paginate-item">
                            <div class="flex justify-between items-start gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="font-bold text-white text-sm truncate"><?php echo htmlspecialchars($t['term_name']); ?></p>
                                    <p class="text-xs text-gray-400 mt-0.5">Session: <span class="text-indigo-400"><?php echo htmlspecialchars($t['session'] ?? 'N/A'); ?></span> &nbsp;|&nbsp; ID: <span class="font-mono text-emerald-400"><?php echo htmlspecialchars($t['id_number']); ?></span></p>
                                    <p class="text-xs text-gray-500 mt-0.5">📅 <?php echo htmlspecialchars($t['school_open_days']); ?> open days<?php if ($t['next_term_begins']) : ?> &nbsp;·&nbsp; Next: <span class="text-amber-400"><?php echo htmlspecialchars($t['next_term_begins']); ?></span><?php endif; ?></p>
                                </div>
                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    <!-- Edit Button -->
                                    <button type="button"
                                        onclick="openTermEdit(<?php echo $t['id']; ?>, <?php echo htmlspecialchars(json_encode($t['term_name'])); ?>, <?php echo htmlspecialchars(json_encode($t['session'] ?? '')); ?>, <?php echo (int)$t['school_open_days']; ?>, <?php echo htmlspecialchars(json_encode($t['next_term_begins'] ?? '')); ?>)"
                                        class="p-1.5 rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-400 hover:bg-blue-500/20 hover:text-blue-300 transition text-xs" title="Edit Term">
                                        ✏️
                                    </button>
                                    <!-- Delete Button -->
                                    <form method="post" onsubmit="return confirm('Delete this term? This cannot be undone.')" style="display:inline;">
                                        <input type="hidden" name="term_id" value="<?php echo $t['id']; ?>" />
                                        <button type="submit" name="delete_term" class="p-1.5 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 hover:text-red-300 transition text-xs" title="Delete Term">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── EDIT TERM MODAL ────────────────────────────────────────────── -->
    <div id="termEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm hidden">
        <div class="bg-[#0f172a] border border-white/10 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6 space-y-4">
            <div class="flex justify-between items-center mb-2">
                <h3 class="text-base font-bold text-emerald-300">✏️ Edit Term</h3>
                <button onclick="closeTermEdit()" class="text-gray-400 hover:text-white text-lg leading-none">&times;</button>
            </div>
            <form method="post" class="space-y-4" id="editTermForm">
                <input type="hidden" name="term_id" id="edit_term_id" />
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Term Name</label>
                    <input type="text" name="term_name" id="edit_term_name" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-emerald-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Session</label>
                    <select name="session" id="edit_term_session" class="w-full bg-[#1e293b] border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-emerald-500 text-sm">
                        <?php foreach ($sessions as $s) : ?>
                            <option value="<?php echo htmlspecialchars($s['session_name']); ?>"><?php echo htmlspecialchars($s['session_name']); ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($sessions)) : ?>
                            <option value="2025/2026">2025/2026</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">School Open Days</label>
                        <input type="number" name="school_open_days" id="edit_term_days" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-emerald-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Next Term Begins</label>
                        <input type="date" name="next_term_begins" id="edit_term_next" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-emerald-500 text-sm" />
                    </div>
                </div>
                <div class="flex gap-3 pt-1">
                    <button type="button" onclick="closeTermEdit()" class="flex-1 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg font-semibold text-sm transition">Cancel</button>
                    <button type="submit" name="edit_term" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-semibold text-sm transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function openTermEdit(id, name, session, days, next) {
        document.getElementById('edit_term_id').value   = id;
        document.getElementById('edit_term_name').value = name;
        document.getElementById('edit_term_days').value = days;
        document.getElementById('edit_term_next').value = next || '';
        var sel = document.getElementById('edit_term_session');
        for (var i = 0; i < sel.options.length; i++) {
            sel.options[i].selected = (sel.options[i].value === session);
        }
        document.getElementById('termEditModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeTermEdit() {
        document.getElementById('termEditModal').classList.add('hidden');
        document.body.style.overflow = '';
    }
    // Close on backdrop click
    document.getElementById('termEditModal').addEventListener('click', function(e) {
        if (e.target === this) closeTermEdit();
    });
    </script>

    <!-- ── SUBJECTS ──────────────────────────────────────────────────── -->
    <?php elseif ($action === 'subjects') : ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-indigo-300 mb-4">Add Subject</h3>
            <form method="post" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Subject Code</label>
                        <input type="text" name="subject_code" required placeholder="MATH101" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm uppercase" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Subject Name</label>
                        <input type="text" name="subject_name" required placeholder="Mathematics" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Class (Code)</label>
                    <select name="class_code" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                        <?php foreach ($classes as $c) : ?>
                            <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>"><?php echo htmlspecialchars($c['class_name']); ?> (<?php echo htmlspecialchars($c['numeric_class_name']); ?>)</option>
                        <?php endforeach; ?>
                        <?php if (empty($classes)) : ?>
                            <option value="">— No classes yet —</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Category</label>
                    <input type="text" name="category" placeholder="Core / Elective / Vocational" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <button type="submit" name="add_subject" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold text-sm transition">Add Subject</button>
            </form>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <!-- Search & Filters -->
            <form method="get" class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-4">
                <input type="hidden" name="page" value="academics" />
                <input type="hidden" name="action" value="subjects" />
                <div>
                    <input type="text" name="search_sub" placeholder="🔍 Search name/code..." value="<?php echo htmlspecialchars($search_sub); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white focus:outline-none focus:border-blue-500 text-xs" />
                </div>
                <div>
                    <select name="filter_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white focus:outline-none focus:border-blue-500 text-xs">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $c) : ?>
                            <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>" <?php echo $filter_class === $c['numeric_class_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-1">
                    <button type="submit" class="flex-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition">Filter</button>
                    <a href="?page=academics&action=subjects" class="px-2 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-xs transition flex items-center justify-center">Reset</a>
                </div>
            </form>

            <h3 class="font-semibold text-indigo-300 mb-4">Subjects (<?php echo count($subjects); ?>)</h3>
            <?php if (empty($subjects)) : ?>
                <p class="text-gray-400 text-sm">No subjects added yet.</p>
            <?php else : ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[500px] text-sm">
                        <thead><tr class="border-b border-white/10 text-xs text-gray-400">
                            <th class="py-2 text-left">Code</th>
                            <th class="py-2 text-left">Subject</th>
                            <th class="py-2 text-left">Class</th>
                            <th class="py-2 text-center">Del</th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/5 js-paginate" data-limit="10">
                        <?php foreach ($subjects as $sub) : ?>
                            <tr class="hover:bg-white/3 js-paginate-item">
                                <td class="py-2 text-indigo-400 font-mono text-xs"><?php echo htmlspecialchars($sub['subject_code']); ?></td>
                                <td class="py-2 text-white font-medium"><?php echo htmlspecialchars($sub['subject_name']); ?></td>
                                <td class="py-2 text-gray-400 text-xs"><?php echo htmlspecialchars($sub['numeric_class_name']); ?></td>
                                <td class="py-2 text-center">
                                    <form method="post" onsubmit="return confirm('Remove subject?');" style="display:inline;">
                                        <input type="hidden" name="subject_id" value="<?php echo $sub['id']; ?>" />
                                        <button type="submit" name="delete_subject" class="text-red-400 hover:text-red-300 text-xs">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── TIMETABLE ─────────────────────────────────────────────────── -->
    <?php elseif ($action === 'timetable') : ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-purple-300 mb-4">Add Timetable Entry</h3>
            <form method="post" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Class</label>
                        <select name="class_code" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <?php foreach ($classes as $c) : ?>
                                <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>"><?php echo htmlspecialchars($c['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Session</label>
                        <select name="session" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <?php foreach ($sessions as $s) : ?>
                                <option value="<?php echo htmlspecialchars($s['session_name']); ?>" <?php echo $s['status']==='active'?'selected':''; ?>><?php echo htmlspecialchars($s['session_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Day</label>
                        <select name="day_of_week" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday'] as $day) : ?>
                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Period</label>
                        <input type="text" name="period" required placeholder="e.g. 8:00am - 9:00am" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Subject</label>
                    <select name="subject_code" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                        <?php foreach ($subjects as $sub) : ?>
                            <option value="<?php echo htmlspecialchars($sub['subject_code']); ?>"><?php echo htmlspecialchars($sub['subject_name']); ?> (<?php echo htmlspecialchars($sub['subject_code']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Teacher Name (optional)</label>
                    <input type="text" name="teacher_name" placeholder="Mr. Adeyemi" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <button type="submit" name="add_timetable" class="w-full py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-semibold text-sm transition">Add Entry</button>
            </form>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <!-- Search & Filters -->
            <form method="get" class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4">
                <input type="hidden" name="page" value="academics" />
                <input type="hidden" name="action" value="timetable" />
                <div>
                    <select name="filter_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white focus:outline-none focus:border-blue-500 text-xs">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $c) : ?>
                            <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>" <?php echo $filter_class === $c['numeric_class_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-1">
                    <button type="submit" class="flex-1 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition">Filter</button>
                    <a href="?page=academics&action=timetable" class="px-2 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-xs transition flex items-center justify-center">Reset</a>
                </div>
            </form>

            <h3 class="font-semibold text-purple-300 mb-4">Timetable Entries (<?php echo count($timetable); ?>)</h3>
            <?php if (empty($timetable)) : ?>
                <p class="text-gray-400 text-sm">No timetable entries yet.</p>
            <?php else : ?>
                <?php
                $days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
                $grouped = [];
                foreach ($timetable as $t) { $grouped[$t['numeric_class_name']][$t['day_of_week']][] = $t; }
                ?>
                <div class="space-y-4 js-paginate" data-limit="5">
                    <?php foreach ($grouped as $class_code => $days_data) : ?>
                        <div class="mb-4 js-paginate-item">
                            <p class="text-xs font-bold text-indigo-400 uppercase mb-2"><?php echo htmlspecialchars($class_code); ?></p>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[500px] text-xs">
                                    <thead><tr class="border-b border-white/10 text-gray-400">
                                        <th class="py-1.5 text-left">Day</th>
                                        <th class="py-1.5 text-left">Period</th>
                                        <th class="py-1.5 text-left">Subject</th>
                                        <th class="py-1.5 text-left">Teacher</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($days as $day) : ?>
                                        <?php if (!empty($days_data[$day])) : ?>
                                            <?php foreach ($days_data[$day] as $ti) : ?>
                                            <tr class="border-b border-white/5">
                                                <td class="py-1.5 text-gray-300"><?php echo $day; ?></td>
                                                <td class="py-1.5 text-gray-400"><?php echo htmlspecialchars($ti['period']); ?></td>
                                                <td class="py-1.5 text-white font-medium"><?php echo htmlspecialchars($ti['subject_code']); ?></td>
                                                <td class="py-1.5 text-gray-400"><?php echo htmlspecialchars($ti['teacher_name'] ?? '—'); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── LIVE CLASSES ──────────────────────────────────────────────── -->
    <?php elseif ($action === 'live') : ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-cyan-300 mb-4">Schedule Live Class</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Class</label>
                    <select name="class_name" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm">
                        <?php foreach ($classes as $c) : ?>
                            <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>"><?php echo htmlspecialchars($c['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Subject Code</label>
                    <input type="text" name="subject_code" required placeholder="e.g. MATH101" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Topic/Title</label>
                    <input type="text" name="title" required placeholder="e.g. Introduction to Algebra" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Meeting Link</label>
                    <input type="url" name="meeting_link" required placeholder="https://meet.google.com/..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Start Time</label>
                    <input type="datetime-local" name="start_time" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm" />
                </div>
                <button type="submit" name="schedule_live" class="w-full py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg font-semibold text-sm transition">Schedule Live Class</button>
            </form>
        </div>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <h3 class="font-semibold text-cyan-300 mb-4">Upcoming Live Classes</h3>
            <?php if (empty($live_classes)) : ?>
                <p class="text-gray-400 text-sm">No live classes scheduled.</p>
            <?php else : ?>
                <div class="space-y-3 js-paginate" data-limit="10">
                    <?php foreach ($live_classes as $lc) : ?>
                        <div class="p-4 bg-white/5 rounded-xl border border-white/10 flex flex-col sm:flex-row justify-between gap-3 js-paginate-item">
                            <div>
                                <h4 class="font-semibold text-white text-sm"><?php echo htmlspecialchars($lc['title']); ?></h4>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($lc['class_name']); ?> | <?php echo htmlspecialchars($lc['subject_code']); ?></p>
                                <p class="text-xs text-cyan-400 mt-1">🕒 <?php echo htmlspecialchars($lc['start_time']); ?></p>
                            </div>
                            <a href="<?php echo htmlspecialchars($lc['meeting_link']); ?>" target="_blank" class="self-start px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold transition">
                                🔗 Join
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-paginate').forEach(function(container) {
        const limit = parseInt(container.getAttribute('data-limit')) || 10;
        let items = [];
        if (container.tagName === 'TBODY') {
            items = Array.from(container.querySelectorAll('tr.js-paginate-item'));
        } else {
            items = Array.from(container.querySelectorAll('.js-paginate-item'));
        }

        const totalItems = items.length;
        if (totalItems <= limit) return;

        const totalPages = Math.ceil(totalItems / limit);
        let currentPage = 1;

        const nav = document.createElement('div');
        nav.className = 'flex flex-col sm:flex-row items-center justify-between border-t border-white/10 pt-4 mt-4 gap-3 text-xs sm:text-sm';
        
        const info = document.createElement('div');
        info.className = 'text-gray-400';
        nav.appendChild(info);

        const controls = document.createElement('div');
        controls.className = 'flex items-center gap-1';
        nav.appendChild(controls);

        if (container.tagName === 'TBODY') {
            const table = container.closest('table');
            table.parentNode.insertBefore(nav, table.nextSibling);
        } else {
            container.parentNode.insertBefore(nav, container.nextSibling);
        }

        function render() {
            const start = (currentPage - 1) * limit;
            const end = start + limit;
            items.forEach((item, idx) => {
                if (idx >= start && idx < end) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });

            info.textContent = `Showing ${start + 1} to ${Math.min(end, totalItems)} of ${totalItems} entries`;

            controls.innerHTML = '';
            
            const prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'px-2 py-1.5 bg-white/5 border border-white/10 text-white rounded-lg flex items-center justify-center transition hover:bg-white/10';
            prevBtn.textContent = '◀️ Prev';
            if (currentPage === 1) {
                prevBtn.disabled = true;
                prevBtn.className = 'px-2 py-1.5 bg-white/5 border border-white/10 text-gray-500 rounded-lg cursor-not-allowed';
            } else {
                prevBtn.onclick = () => { currentPage--; render(); };
            }
            controls.appendChild(prevBtn);

            const maxDisplayed = 5;
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + maxDisplayed - 1);
            if (endPage - startPage < maxDisplayed - 1) {
                startPage = Math.max(1, endPage - maxDisplayed + 1);
            }

            for (let i = startPage; i <= endPage; i++) {
                const numBtn = document.createElement('button');
                numBtn.type = 'button';
                numBtn.textContent = i;
                numBtn.className = `px-3 py-1.5 border rounded-lg transition ${i === currentPage ? 'bg-blue-600 border-blue-500 text-white font-bold' : 'bg-white/5 border-white/10 text-gray-300 hover:bg-white/10'}`;
                numBtn.onclick = () => { currentPage = i; render(); };
                controls.appendChild(numBtn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'px-2 py-1.5 bg-white/5 border border-white/10 text-white rounded-lg flex items-center justify-center transition hover:bg-white/10';
            nextBtn.textContent = 'Next ▶️';
            if (currentPage === totalPages) {
                nextBtn.disabled = true;
                nextBtn.className = 'px-2 py-1.5 bg-white/5 border border-white/10 text-gray-500 rounded-lg cursor-not-allowed';
            } else {
                nextBtn.onclick = () => { currentPage++; render(); };
            }
            controls.appendChild(nextBtn);
        }

        render();
    });
});
</script>
