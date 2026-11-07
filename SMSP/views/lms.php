<?php
// Learning Management System (LMS) View (Curriculum modules, lessons, assignments, submissions)
if (!defined('PORTAL_PATH')) exit;

// Restrict writing/creating access for students and parents
if (in_array($user_role, ['student', 'parent']) && in_array($action, ['add_module', 'add_lesson'])) {
    echo '<div class="p-6 text-red-400 font-semibold">⛔ Access Denied. Students and parents do not have permission to modify learning modules.</div>';
    return;
}

$msg = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($user_role, ['student', 'parent'])) {
        $msg = "⚠️ Error: You do not have permission to modify the LMS.";
    } else {
        if (isset($_POST['add_module'])) {
            $sub = trim($_POST['subject_code']);
            $cls = trim($_POST['numeric_class_name']);
            $title = trim($_POST['title']);
            $desc = trim($_POST['description']);

            $stmt = $pdo->prepare("INSERT INTO sm_lms_modules (school_id_number, subject_code, numeric_class_name, title, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $sub, $cls, $title, $desc]);
            $msg = "Learning Module created successfully!";
        } elseif (isset($_POST['add_lesson'])) {
            $mod_id = (int)$_POST['module_id'];
            $title = trim($_POST['title']);
            $type = trim($_POST['content_type']);
            $body = trim($_POST['content_body']);
            
            $attachment = '';
            if (is_uploaded_file($_FILES['attachment']['tmp_name'])) {
                require_once PORTAL_PATH . '/include/gdrive_helper.php';
                $attachment = GDriveHelper::uploadFile($_FILES['attachment']['tmp_name'], $_FILES['attachment']['name'], $_FILES['attachment']['type'], $school_id, 'lessons');
            }

            $stmt = $pdo->prepare("INSERT INTO sm_lms_lessons (module_id, title, content_type, content_body, attachment_url) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$mod_id, $title, $type, $body, $attachment]);
            $msg = "Lesson topic added to module!";
        }
    }
}

// Fetch lists
$stmtMods = $pdo->prepare("SELECT * FROM sm_lms_modules WHERE school_id_number = ? ORDER BY id DESC");
$stmtMods->execute([$school_id]);
$modules = $stmtMods->fetchAll();
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🎓 Learning Management (LMS)</h2>
        <div class="flex overflow-x-auto gap-2 w-full md:w-auto p-1 scrollbar-thin">
            <a href="?page=lms&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 transition text-sm">Modules Catalog</a>
            <?php if (!in_array($user_role, ['student', 'parent'])): ?>
                <a href="?page=lms&action=add_module" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-semibold">New Module</a>
                <a href="?page=lms&action=add_lesson" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition text-sm font-semibold">Add Lesson Topic</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list') : ?>
        <div class="space-y-4">
            <?php if (empty($modules)) : ?>
                <div class="bg-white/5 border border-white/10 rounded-2xl p-8 text-center text-gray-400 text-sm">
                    No curriculum modules designed yet. Select 'New Module' to start building.
                </div>
            <?php else : ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php foreach ($modules as $mod) : ?>
                        <?php
                        // Fetch lessons for this module
                        $stmtLes = $pdo->prepare("SELECT * FROM sm_lms_lessons WHERE module_id = ? ORDER BY order_index ASC");
                        $stmtLes->execute([$mod['id']]);
                        $lessons = $stmtLes->fetchAll();
                        ?>
                        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md space-y-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <span class="px-2 py-0.5 bg-blue-500/20 text-blue-400 border border-blue-500/20 rounded text-[10px] font-bold uppercase"><?php echo htmlspecialchars($mod['subject_code']); ?></span>
                                    <h3 class="text-lg font-bold text-white mt-1"><?php echo htmlspecialchars($mod['title']); ?></h3>
                                </div>
                                <span class="text-xs text-gray-400">Class: <?php echo htmlspecialchars($mod['numeric_class_name']); ?></span>
                            </div>
                            <p class="text-xs text-gray-300"><?php echo htmlspecialchars($mod['description']); ?></p>
                            
                            <div class="border-t border-white/10 pt-3 space-y-2">
                                <h4 class="text-xs font-semibold text-indigo-300 uppercase tracking-wide">Lesson Topics:</h4>
                                <?php if (empty($lessons)) : ?>
                                    <p class="text-[11px] text-gray-500">No lessons uploaded in this module.</p>
                                <?php else : ?>
                                    <ul class="space-y-1 text-xs">
                                        <?php foreach ($lessons as $l) : ?>
                                            <li class="flex items-center justify-between text-gray-300 bg-white/5 p-2 rounded-lg border border-white/5">
                                                <span>📖 <?php echo htmlspecialchars($l['title']); ?></span>
                                                <span class="text-[10px] uppercase text-indigo-400 font-bold"><?php echo htmlspecialchars($l['content_type']); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'add_module') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-blue-300">Create Curriculum Learning Module</h3>
            <form method="post" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Subject Code</label>
                        <input type="text" name="subject_code" required placeholder="e.g. MTH101" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Numeric Class Code</label>
                        <input type="text" name="numeric_class_name" required placeholder="e.g. PRI-1" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Module Title</label>
                    <input type="text" name="title" required placeholder="e.g. Introduction to Algebra" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Objectives / Description</label>
                    <textarea name="description" rows="3" placeholder="Learning aims..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"></textarea>
                </div>
                <button type="submit" name="add_module" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Save Module
                </button>
            </form>
        </div>

    <?php elseif ($action === 'add_lesson') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-indigo-300">Add Lesson Topic to Curriculum</h3>
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Target Curriculum Module</label>
                    <select name="module_id" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                        <?php foreach ($modules as $m) : ?>
                            <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['title'] . ' (' . $m['subject_code'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Lesson Topic Title</label>
                    <input type="text" name="title" required placeholder="e.g. Linear Equations Basics" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Content Delivery Mode</label>
                        <select name="content_type" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="text">Text Notes</option>
                            <option value="video">Video Lecture link</option>
                            <option value="pdf">PDF Resource</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Attachment File (GDrive Linked)</label>
                        <input type="file" name="attachment" class="w-full text-xs text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-700 cursor-pointer" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Lecture Body / Notes (HTML/Text format)</label>
                    <textarea name="content_body" rows="5" placeholder="Lecture notes content here..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs font-mono"></textarea>
                </div>
                <button type="submit" name="add_lesson" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition">
                    Save Lesson Topic
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>
