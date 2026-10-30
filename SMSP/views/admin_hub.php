<?php
// Administrative Hub View (Attendance, Library, Hostels, Transport, ID Cards)
if (!defined('PORTAL_PATH')) exit;

$msg = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_book'])) {
        $isbn = trim($_POST['isbn']);
        $cat = trim($_POST['book_category_id']);
        $name = trim($_POST['book_name']);
        $author = trim($_POST['author_name']);
        $rack = trim($_POST['rack_location']);
        $qty = (int)$_POST['quantity'];

        // Make sure category exists
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sm_book_categories WHERE id_number = ? AND school_id_number = ?");
        $stmtCheck->execute([$cat, $school_id]);
        if ($stmtCheck->fetchColumn() == 0) {
            $stmtCat = $pdo->prepare("INSERT INTO sm_book_categories (id_number, school_id_number, category_name) VALUES (?, ?, ?)");
            $stmtCat->execute([$cat, $school_id, $cat]);
        }

        $stmt = $pdo->prepare("INSERT INTO sm_book_lists (school_id_number, isbn, book_category_id, book_name, author_name, rack_location, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$school_id, $isbn, $cat, $name, $author, $rack, $qty]);
        $msg = "Book added to library successfully!";
    } elseif (isset($_POST['add_hostel'])) {
        $hid = trim($_POST['id_number']);
        $name = trim($_POST['hostel_name']);
        $type = trim($_POST['hostel_type']);
        $desc = trim($_POST['hostel_description']);

        $stmt = $pdo->prepare("INSERT INTO sm_hostels (id_number, school_id_number, hostel_name, hostel_type, hostel_description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$hid, $school_id, $name, $type, $desc]);
        $msg = "Hostel created successfully!";
    } elseif (isset($_POST['save_attendance'])) {
        $date = trim($_POST['attendance_date']);
        $session = trim($_POST['session']);
        $term = trim($_POST['term']);
        
        if (isset($_POST['attendance']) && is_array($_POST['attendance'])) {
            $stmtInsert = $pdo->prepare("INSERT INTO sm_student_attendances (school_id_number, admission_number, date, attendance_remark, session, term) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE attendance_remark = VALUES(attendance_remark)");
            foreach ($_POST['attendance'] as $adm_no => $remark) {
                // Determine remark
                $remark_val = 'present';
                if ($remark === 'A') $remark_val = 'absent';
                elseif ($remark === 'L') $remark_val = 'late';
                elseif ($remark === 'S') $remark_val = 'sick';

                // sm_student_attendances uses `date`, we might need to alter table or assume no unique index. Actually, we will just delete and insert if ON DUPLICATE KEY UPDATE fails due to missing unique index.
                // Let's delete existing first to be safe
                $stmtDel = $pdo->prepare("DELETE FROM sm_student_attendances WHERE school_id_number = ? AND admission_number = ? AND date = ?");
                $stmtDel->execute([$school_id, $adm_no, $date]);

                $stmtInsert = $pdo->prepare("INSERT INTO sm_student_attendances (school_id_number, admission_number, date, attendance_remark) VALUES (?, ?, ?, ?)");
                $stmtInsert->execute([$school_id, $adm_no, $date, $remark_val]);
            }
            $msg = "Attendance records saved successfully!";
        }
    }
}

// Fetch lists
$stmtBooks = $pdo->prepare("SELECT * FROM sm_book_lists WHERE school_id_number = ?");
$stmtBooks->execute([$school_id]);
$books = $stmtBooks->fetchAll();

$stmtHostels = $pdo->prepare("SELECT * FROM sm_hostels WHERE school_id_number = ?");
$stmtHostels->execute([$school_id]);
$hostels = $stmtHostels->fetchAll();
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🏢 Administrative Hub</h2>
        <div class="flex overflow-x-auto gap-2 w-full md:w-auto p-1 scrollbar-thin">
            <a href="?page=admin_hub&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-white/5 border border-white/10 rounded-lg hover:bg-white/10 transition text-sm">Dashboard Overview</a>
            <a href="?page=admin_hub&action=attendance" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition text-sm font-semibold">📅 Attendance</a>
            <a href="?page=admin_hub&action=library" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition text-sm font-semibold">Library Books</a>
            <a href="?page=admin_hub&action=hostel" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition text-sm font-semibold">Hostel Registry</a>
            <?php if (!empty($addons['ID Card'])): ?>
                <a href="?page=admin_hub&action=id_card" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition text-sm font-semibold">🪪 ID Cards</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list') : ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Books Summary -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-4 text-blue-300">Library Catalogue</h3>
                <?php if (empty($books)) : ?>
                    <p class="text-gray-400 text-sm">No books registered in the system library.</p>
                <?php else : ?>
                    <div class="space-y-2">
                        <?php foreach ($books as $b) : ?>
                            <div class="p-3 bg-white/5 rounded-xl border border-white/5 flex justify-between items-center">
                                <div>
                                    <h4 class="font-bold text-white text-sm"><?php echo htmlspecialchars($b['book_name']); ?></h4>
                                    <p class="text-xs text-gray-400">Author: <?php echo htmlspecialchars($b['author_name']); ?> | Location: <?php echo htmlspecialchars($b['rack_location']); ?></p>
                                </div>
                                <span class="px-2 py-0.5 bg-blue-600/20 text-blue-400 border border-blue-500/20 rounded-md text-xs font-semibold">Qty: <?php echo htmlspecialchars($b['quantity']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hostels Summary -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold mb-4 text-indigo-300">Hostel Allocation</h3>
                <?php if (empty($hostels)) : ?>
                    <p class="text-gray-400 text-sm">No hostels registered yet.</p>
                <?php else : ?>
                    <div class="space-y-3">
                        <?php foreach ($hostels as $h) : ?>
                            <div class="p-4 bg-white/5 rounded-xl border border-white/10 flex justify-between items-center">
                                <div>
                                    <h4 class="font-semibold text-white"><?php echo htmlspecialchars($h['hostel_name']); ?></h4>
                                    <p class="text-xs text-gray-400">Type: <span class="capitalize text-indigo-400"><?php echo htmlspecialchars($h['hostel_type']); ?></span></p>
                                    <p class="text-[11px] text-gray-300 mt-1"><?php echo htmlspecialchars($h['hostel_description']); ?></p>
                                </div>
                                <span class="px-2.5 py-1 bg-white/10 border border-white/10 text-white rounded-lg text-xs font-bold"><?php echo htmlspecialchars($h['id_number']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($action === 'library') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-blue-300">Register Book in Library</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Book Title</label>
                    <input type="text" name="book_name" required placeholder="e.g. Modern Physics Volume 1" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Author</label>
                        <input type="text" name="author_name" required placeholder="e.g. Nelkon & Parker" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">ISBN/Serial Code</label>
                        <input type="text" name="isbn" required placeholder="e.g. 978-3-16-148410-0" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Category Code</label>
                        <input type="text" name="book_category_id" required placeholder="e.g. SCI-PHYS" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Rack Location</label>
                        <input type="text" name="rack_location" required placeholder="e.g. Shelf A3" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Quantity</label>
                        <input type="number" name="quantity" value="5" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <button type="submit" name="add_book" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Register Book
                </button>
            </form>
        </div>

    <?php elseif ($action === 'hostel') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-indigo-300">Add Hostel / Hall</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Hostel Name</label>
                    <input type="text" name="hostel_name" required placeholder="e.g. Mandela Hall" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Hostel ID Key</label>
                        <input type="text" name="id_number" required placeholder="e.g. MND-HALL" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Type</label>
                        <select name="hostel_type" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="mixed">Co-ed / Mixed</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Brief Description</label>
                    <textarea name="hostel_description" rows="3" placeholder="Additional details..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                </div>
                <button type="submit" name="add_hostel" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition">
                    Save Hostel Block
                </button>
            </form>
        </div>
    <?php elseif ($action === 'id_card') : ?>
        <div class="max-w-4xl mx-auto space-y-6">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold text-purple-300 border-b border-white/10 pb-2 mb-4 flex items-center gap-2">
                    <span>🪪</span> Digital ID Card Generator
                </h3>
                
                <p class="text-xs text-gray-400 leading-relaxed">Design and generate digital badges and printable ID cards for students and staff. Configure card templates, backgrounds, and dynamically print avatars.</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                    <div class="p-6 bg-white/5 border border-white/5 rounded-xl text-center space-y-4">
                        <div class="text-4xl">🎓</div>
                        <h4 class="font-bold text-white">Student ID Badges</h4>
                        <p class="text-xs text-gray-450">Bulk generate ID cards for all students in selected class categories.</p>
                        <a href="?page=id_card_view" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-semibold transition inline-block">Configure & Generate</a>
                    </div>
                    
                    <div class="p-6 bg-white/5 border border-white/5 rounded-xl text-center space-y-4">
                        <div class="text-4xl">👨‍🏫</div>
                        <h4 class="font-bold text-white">Staff / Faculty ID Badges</h4>
                        <p class="text-xs text-gray-450">Generate identity cards for teachers and administrative staff.</p>
                        <a href="?page=id_card_view&class=STAFF_TEACHER" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition inline-block">Configure & Generate</a>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'attendance') : ?>
        <?php
        $selected_class = $_GET['class_name'] ?? '';
        $selected_date = $_GET['date'] ?? date('Y-m-d');
        ?>
        <div class="space-y-6">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <input type="hidden" name="page" value="admin_hub">
                    <input type="hidden" name="action" value="attendance">
                    
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Select Class</label>
                        <select name="class_name" required class="w-full bg-black/40 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-green-500 text-sm">
                            <option value="">-- Choose Class --</option>
                            <?php
                            $stmtCls = $pdo->prepare("SELECT DISTINCT class_name FROM sm_classes WHERE school_id_number = ?");
                            $stmtCls->execute([$school_id]);
                            foreach ($stmtCls->fetchAll() as $cls) {
                                $sel = ($selected_class === $cls['class_name']) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($cls['class_name'])."' {$sel}>".htmlspecialchars($cls['class_name'])."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Date</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" required class="w-full bg-black/40 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-green-500 text-sm" />
                    </div>
                    <div>
                        <button type="submit" class="w-full py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-semibold transition shadow-lg">
                            Fetch Students
                        </button>
                    </div>
                </form>
            </div>

            <?php if ($selected_class): ?>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <form method="post" class="space-y-4">
                    <input type="hidden" name="attendance_date" value="<?php echo htmlspecialchars($selected_date); ?>" />
                    <input type="hidden" name="session" value="Current" />
                    <input type="hidden" name="term" value="Current" />

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[700px] text-left text-sm whitespace-nowrap">
                            <thead class="text-xs text-gray-400 border-b border-white/10">
                                <tr>
                                    <th class="py-3 px-4 font-semibold uppercase tracking-wider">Student Name</th>
                                    <th class="py-3 px-4 font-semibold uppercase tracking-wider">Adm No.</th>
                                    <th class="py-3 px-4 font-semibold uppercase tracking-wider text-center">Present (P)</th>
                                    <th class="py-3 px-4 font-semibold uppercase tracking-wider text-center">Absent (A)</th>
                                    <th class="py-3 px-4 font-semibold uppercase tracking-wider text-center">Late (L)</th>
                                    <th class="py-3 px-4 font-semibold uppercase tracking-wider text-center">Sick (S)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php
                                $stmtStud = $pdo->prepare("SELECT admission_number, firstname, lastname FROM sm_students WHERE current_class = ? AND school_id_number = ? ORDER BY firstname ASC");
                                $stmtStud->execute([$selected_class, $school_id]);
                                $students = $stmtStud->fetchAll();

                                foreach ($students as $stu):
                                    // Get existing record if any
                                    $stmtExt = $pdo->prepare("SELECT attendance_remark FROM sm_student_attendances WHERE school_id_number = ? AND admission_number = ? AND date = ?");
                                    $stmtExt->execute([$school_id, $stu['admission_number'], $selected_date]);
                                    $ext = $stmtExt->fetchColumn() ?: 'present'; // default present
                                ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="py-3 px-4 text-white font-medium">
                                        <?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname']); ?>
                                    </td>
                                    <td class="py-3 px-4 text-gray-400 text-xs font-mono">
                                        <?php echo htmlspecialchars($stu['admission_number']); ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <input type="radio" name="attendance[<?php echo htmlspecialchars($stu['admission_number']); ?>]" value="P" <?php echo ($ext === 'present') ? 'checked' : ''; ?> class="accent-green-500 w-4 h-4" />
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <input type="radio" name="attendance[<?php echo htmlspecialchars($stu['admission_number']); ?>]" value="A" <?php echo ($ext === 'absent') ? 'checked' : ''; ?> class="accent-red-500 w-4 h-4" />
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <input type="radio" name="attendance[<?php echo htmlspecialchars($stu['admission_number']); ?>]" value="L" <?php echo ($ext === 'late') ? 'checked' : ''; ?> class="accent-yellow-500 w-4 h-4" />
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <input type="radio" name="attendance[<?php echo htmlspecialchars($stu['admission_number']); ?>]" value="S" <?php echo ($ext === 'sick') ? 'checked' : ''; ?> class="accent-blue-500 w-4 h-4" />
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (empty($students)): ?>
                        <div class="text-center py-6 text-gray-400">No students found in <?php echo htmlspecialchars($selected_class); ?>.</div>
                    <?php else: ?>
                        <div class="mt-6 flex justify-end">
                            <button type="submit" name="save_attendance" class="px-8 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-bold shadow-lg shadow-green-500/20 transition">
                                💾 Save Attendance Records
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
    <?php endif; ?>
</div>
