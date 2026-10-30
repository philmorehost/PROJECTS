<?php
// Evaluation Management View (Exams, Grades, Results, CBT Panels)
if (!defined('PORTAL_PATH')) exit;

require_once PORTAL_PATH . '/include/result_helper.php';

$msg = '';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Load student context if role is student or parent
$student_info = null;
if ($user_role === 'student') {
    $stmt = $pdo->prepare("SELECT * FROM sm_students WHERE email = ? OR admission_number = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_session'], $_SESSION['user_session']]);
    $student_info = $stmt->fetch();
}

if (($user_role === 'student' || $user_role === 'parent') && $action === 'list') {
    $action = 'student_results';
}

// Handle submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_grade'])) {
        $name = trim($_POST['grade_name']);
        $from = (int)$_POST['mark_from'];
        $to = (int)$_POST['mark_upto'];
        $comment = trim($_POST['grade_comment']);

        $stmt = $pdo->prepare("INSERT INTO sm_grades (school_id_number, grade_name, mark_from, mark_upto, grade_comment) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$school_id, $name, $from, $to, $comment]);
        $msg = "Grade scale rule configured!";
    } elseif (isset($_POST['create_cbt'])) {
        $title = trim($_POST['paper_title']);
        $cls = trim($_POST['numeric_class_name']);
        $sess = trim($_POST['session']);
        $term = trim($_POST['term_id_number']);
        $sub = trim($_POST['subject_code']);
        $type = trim($_POST['cbt_type']);
        $date = $_POST['exam_date'];
        $time = $_POST['exam_time'];
        $questions = (int)$_POST['exam_questions'];
        $duration = (int)$_POST['exam_duration'];
        $attempt_questions = isset($_POST['attempt_questions']) ? (int)$_POST['attempt_questions'] : 0;
        $is_approved = ($_SESSION['user_role'] === 'teacher') ? 0 : 1;

        // Automatically initialize an empty JSON structure for the questions
        $stmtSub = $pdo->prepare("SELECT subject_name FROM sm_subjects WHERE school_id_number = ? AND subject_code = ? LIMIT 1");
        $stmtSub->execute([$school_id, $sub]);
        $subName = $stmtSub->fetchColumn() ?: $sub;

        $empty_json = json_encode([
            "exams" => [$subName],
            "time" => str_pad(floor($duration/60), 2, '0', STR_PAD_LEFT) . ":" . str_pad($duration%60, 2, '0', STR_PAD_LEFT) . ":00",
            "examAttempts" => $questions,
            "quiz" => []
        ]);

        $stmt = $pdo->prepare("INSERT INTO sm_cbt_schedules (school_id_number, paper_title, numeric_class_name, session, term_id_number, subject_code, cbt_type, exam_json, exam_date, exam_time, exam_questions, exam_duration, attempt_questions, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$school_id, $title, $cls, $sess, $term, $sub, $type, $empty_json, $date, $time, $questions, $duration, $attempt_questions, $is_approved]);
        $msg = "CBT Test scheduled successfully!";
    } elseif (isset($_POST['update_cbt_questions'])) {
        $cbt_id = (int)$_POST['cbt_id'];
        $questions = $_POST['questions'] ?? [];
        $option1 = $_POST['option1'] ?? [];
        $option2 = $_POST['option2'] ?? [];
        $option3 = $_POST['option3'] ?? [];
        $option4 = $_POST['option4'] ?? [];

        $paper_title = trim($_POST['paper_title']);
        $exam_date = $_POST['exam_date'];
        $exam_time = $_POST['exam_time'];
        $exam_duration = (int)$_POST['exam_duration'];
        $attempt_questions = (int)$_POST['attempt_questions'];

        // Fetch subject details for exams array
        $stmtC = $pdo->prepare("SELECT subject_code, exam_duration FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
        $stmtC->execute([$school_id, $cbt_id]);
        $cbt_info = $stmtC->fetch();

        if ($cbt_info) {
            $stmtSub = $pdo->prepare("SELECT subject_name FROM sm_subjects WHERE school_id_number = ? AND subject_code = ? LIMIT 1");
            $stmtSub->execute([$school_id, $cbt_info['subject_code']]);
            $subName = $stmtSub->fetchColumn() ?: $cbt_info['subject_code'];

            $quiz = [];
            for ($i = 0; $i < count($questions); $i++) {
                if (empty(trim($questions[$i]))) continue;
                $quiz[] = [
                    "question" => base64_encode(trim($questions[$i])),
                    "answers" => [
                        "correct" => base64_encode(trim($option1[$i] ?? '')),
                        "wrong" => [
                            base64_encode(trim($option2[$i] ?? '')),
                            base64_encode(trim($option3[$i] ?? '')),
                            base64_encode(trim($option4[$i] ?? ''))
                        ]
                    ]
                ];
            }

            $exam_json = json_encode([
                "exams" => [$subName],
                "time" => str_pad(floor($exam_duration/60), 2, '0', STR_PAD_LEFT) . ":" . str_pad($exam_duration%60, 2, '0', STR_PAD_LEFT) . ":00",
                "examAttempts" => count($quiz),
                "quiz" => $quiz
            ]);

            $stmtUpdate = $pdo->prepare("UPDATE sm_cbt_schedules SET paper_title = ?, exam_date = ?, exam_time = ?, exam_duration = ?, attempt_questions = ?, exam_json = ?, exam_questions = ? WHERE school_id_number = ? AND cbt_id = ?");
            $stmtUpdate->execute([$paper_title, $exam_date, $exam_time, $exam_duration, $attempt_questions, $exam_json, count($quiz), $school_id, $cbt_id]);
            $msg = "CBT Questions and schedule updated successfully!";
        }
    } elseif (isset($_POST['delete_cbt'])) {
        $cbt_id = (int)$_POST['cbt_id'];
        $stmt = $pdo->prepare("DELETE FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
        $stmt->execute([$school_id, $cbt_id]);
        $msg = "CBT Test schedule deleted.";
    } elseif (isset($_POST['approve_cbt'])) {
        $cbt_id = (int)$_POST['cbt_id'];
        $stmt = $pdo->prepare("UPDATE sm_cbt_schedules SET is_approved = 1 WHERE school_id_number = ? AND cbt_id = ?");
        $stmt->execute([$school_id, $cbt_id]);
        $msg = "CBT Exam schedule approved successfully!";
    } elseif (isset($_POST['bulk_delete_cbt'])) {
        $ids = $_POST['bulk_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id IN ($placeholders)");
            $stmt->execute(array_merge([$school_id], $ids));
            $msg = "Selected scheduled exams deleted successfully!";
        }
    } elseif (isset($_POST['clone_cbt'])) {
        $clone_id = (int)$_POST['clone_id'];
        $new_title = trim($_POST['paper_title']);
        $new_session = trim($_POST['session']);
        $new_term = trim($_POST['term_id_number']);

        $stmtOrig = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
        $stmtOrig->execute([$school_id, $clone_id]);
        $orig = $stmtOrig->fetch();

        if ($orig) {
            $is_approved = ($_SESSION['user_role'] === 'teacher') ? 0 : 1;
            $stmt = $pdo->prepare("INSERT INTO sm_cbt_schedules (school_id_number, paper_title, numeric_class_name, session, term_id_number, subject_code, cbt_type, exam_json, exam_date, exam_time, exam_questions, exam_duration, attempt_questions, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$school_id, $new_title, $orig['numeric_class_name'], $new_session, $new_term, $orig['subject_code'], $orig['cbt_type'], $orig['exam_json'], $orig['exam_date'], $orig['exam_time'], $orig['exam_questions'], $orig['exam_duration'], $orig['attempt_questions'], $is_approved]);
            $new_cbt_id = $pdo->lastInsertId();
            echo "<script>window.location.href = 'dashboard.php?page=evaluation&action=add_cbt_quiz&cbt_id=" . $new_cbt_id . "&msg=" . urlencode("CBT Test cloned successfully! You can now edit the questions.") . "';</script>";
            exit;
        }
    } elseif (isset($_POST['reschedule_student'])) {
        $cbt_id = (int)$_POST['cbt_id'];
        $adm_num = trim($_POST['admission_number']);

        $stmt1 = $pdo->prepare("DELETE FROM sm_cbt_started WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ?");
        $stmt1->execute([$school_id, $cbt_id, $adm_num]);

        $stmt2 = $pdo->prepare("DELETE FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ?");
        $stmt2->execute([$school_id, $cbt_id, $adm_num]);

        $msg = "Student has been rescheduled to retake the exam.";
    } elseif (isset($_POST['student_start_cbt'])) {
        try {
            $cbt_id = (int)$_POST['cbt_id'];
            if (!$student_info) {
                $stmt = $pdo->prepare("SELECT * FROM sm_students WHERE email = ? OR admission_number = ? LIMIT 1");
                $stmt->execute([$_SESSION['user_session'] ?? '', $_SESSION['user_session'] ?? '']);
                $student_info = $stmt->fetch();
            }
            if (!$student_info) {
                throw new Exception("Student context not found.");
            }
            $adm_num = $student_info['admission_number'];

            $stmtCheck = $pdo->prepare("SELECT id FROM sm_cbt_started WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ?");
            $stmtCheck->execute([$school_id, $cbt_id, $adm_num]);
            if (!$stmtCheck->fetchColumn()) {
                $stmtInsert = $pdo->prepare("INSERT INTO sm_cbt_started (school_id_number, cbt_id, admission_number) VALUES (?, ?, ?)");
                $stmtInsert->execute([$school_id, $cbt_id, $adm_num]);
            }
            
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success']);
                exit;
            }
        } catch (Exception $e) {
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
    } elseif (isset($_POST['student_submit_cbt'])) {
        try {
            $cbt_id = (int)$_POST['cbt_id'];
            $score = (float)$_POST['score'];
            $answers = $_POST['answers'] ?? ''; 

            if (!$student_info) {
                $stmt = $pdo->prepare("SELECT * FROM sm_students WHERE email = ? OR admission_number = ? LIMIT 1");
                $stmt->execute([$_SESSION['user_session'] ?? '', $_SESSION['user_session'] ?? '']);
                $student_info = $stmt->fetch();
            }
            if (!$student_info) {
                throw new Exception("Student context not found. Please log in again.");
            }
            $adm_num = $student_info['admission_number'];
            if (empty($adm_num)) {
                throw new Exception("Student admission number is missing from profile.");
            }

            // 1. Record in sm_cbt_submitted
            $stmtCheck = $pdo->prepare("SELECT id FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ?");
            $stmtCheck->execute([$school_id, $cbt_id, $adm_num]);
            if (!$stmtCheck->fetchColumn()) {
                $stmtInsert = $pdo->prepare("INSERT INTO sm_cbt_submitted (school_id_number, cbt_id, admission_number, score, answers) VALUES (?, ?, ?, ?, ?)");
                $stmtInsert->execute([$school_id, $cbt_id, $adm_num, $score, $answers]);
            }

            // 2. Fetch CBT metadata to upsert in sm_results
            $stmtC = $pdo->prepare("SELECT numeric_class_name, session, term_id_number, subject_code, cbt_type, exam_questions FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
            $stmtC->execute([$school_id, $cbt_id]);
            $cbt_meta = $stmtC->fetch();

            if ($cbt_meta) {
                $percentage = $cbt_meta['exam_questions'] > 0 ? round(($score / $cbt_meta['exam_questions']) * 100, 2) : 0;
                $score_col = 'exam';
                if ($cbt_meta['cbt_type'] === 'quiz' || $cbt_meta['cbt_type'] === 'test') {
                    $score_col = 'first_ca'; 
                }

                $stmtResCheck = $pdo->prepare("SELECT id FROM sm_results WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=? AND subject_code=?");
                $stmtResCheck->execute([$school_id, $adm_num, $cbt_meta['numeric_class_name'], $cbt_meta['session'], $cbt_meta['term_id_number'], $cbt_meta['subject_code']]);
                $res_id = $stmtResCheck->fetchColumn();

                if ($res_id) {
                    $pdo->prepare("UPDATE sm_results SET {$score_col} = ? WHERE id = ? AND school_id_number = ?")->execute([$percentage, $res_id, $school_id]);
                } else {
                    $pdo->prepare("INSERT INTO sm_results (school_id_number, admission_number, numeric_class_name, session, term_id_number, subject_code, {$score_col}) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$school_id, $adm_num, $cbt_meta['numeric_class_name'], $cbt_meta['session'], $cbt_meta['term_id_number'], $cbt_meta['subject_code'], $percentage]);
                }
            }

            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Exam submitted successfully']);
                exit;
            }
        } catch (Exception $e) {
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
    }
}

// Fetch lists
$stmtGrades = $pdo->prepare("SELECT * FROM sm_grades WHERE school_id_number = ? ORDER BY mark_from DESC");
$stmtGrades->execute([$school_id]);
$grades = $stmtGrades->fetchAll();

$school_classes = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? ORDER BY class_name");
$school_classes->execute([$school_id]);
$school_classes = $school_classes->fetchAll();

$school_sessions = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC");
$school_sessions->execute([$school_id]);
$school_sessions = $school_sessions->fetchAll();

$school_terms = $pdo->prepare("SELECT id_number, term_name FROM sm_terms WHERE school_id_number = ? ORDER BY id DESC");
$school_terms->execute([$school_id]);
$school_terms = $school_terms->fetchAll();

$search_cbt = isset($_GET['search_cbt']) ? trim($_GET['search_cbt']) : '';
$filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';
$filter_session = isset($_GET['filter_session']) ? trim($_GET['filter_session']) : '';
$filter_term = isset($_GET['filter_term']) ? trim($_GET['filter_term']) : '';
$filter_type = isset($_GET['filter_type']) ? trim($_GET['filter_type']) : '';

$cbt_clauses = ["school_id_number = ?"];
$cbt_params = [$school_id];

if ($filter_class !== '') {
    $cbt_clauses[] = "numeric_class_name = ?";
    $cbt_params[] = $filter_class;
}
if ($filter_session !== '') {
    $cbt_clauses[] = "session = ?";
    $cbt_params[] = $filter_session;
}
if ($filter_term !== '') {
    $cbt_clauses[] = "term_id_number = ?";
    $cbt_params[] = $filter_term;
}
if ($filter_type !== '') {
    $cbt_clauses[] = "cbt_type = ?";
    $cbt_params[] = $filter_type;
}
if ($search_cbt !== '') {
    $cbt_clauses[] = "(paper_title LIKE ? OR subject_code LIKE ?)";
    $search_param = '%' . $search_cbt . '%';
    $cbt_params[] = $search_param;
    $cbt_params[] = $search_param;
}
$cbt_where = implode(' AND ', $cbt_clauses);
$stmtCBT = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE $cbt_where ORDER BY cbt_id DESC");
$stmtCBT->execute($cbt_params);
$cbt_tests = $stmtCBT->fetchAll();

// Pagination for Grade Configuration
$grades_per_page = 5;
$total_grades = count($grades);
$grades_total_pages = max(1, ceil($total_grades / $grades_per_page));
$grades_current_page = isset($_GET['gpage']) ? max(1, (int)$_GET['gpage']) : 1;
if ($grades_current_page > $grades_total_pages) $grades_current_page = $grades_total_pages;
$grades_offset = ($grades_current_page - 1) * $grades_per_page;
$grades_paginated = array_slice($grades, $grades_offset, $grades_per_page);

// Pagination for Active CBT Exams in action=list
$cbt_per_page = 5;
$total_cbt = count($cbt_tests);
$cbt_total_pages = max(1, ceil($total_cbt / $cbt_per_page));
$cbt_current_page = isset($_GET['cbtpage']) ? max(1, (int)$_GET['cbtpage']) : 1;
if ($cbt_current_page > $cbt_total_pages) $cbt_current_page = $cbt_total_pages;
$cbt_offset = ($cbt_current_page - 1) * $cbt_per_page;
$cbt_paginated = array_slice($cbt_tests, $cbt_offset, $cbt_per_page);

// Pagination for Active CBT Schedules in action=cbt
$cbt_sched_per_page = 10;
$cbt_sched_total_pages = max(1, ceil($total_cbt / $cbt_sched_per_page));
$cbt_sched_current_page = isset($_GET['schedpage']) ? max(1, (int)$_GET['schedpage']) : 1;
if ($cbt_sched_current_page > $cbt_sched_total_pages) $cbt_sched_current_page = $cbt_sched_total_pages;
$cbt_sched_offset = ($cbt_sched_current_page - 1) * $cbt_sched_per_page;
$cbt_sched_paginated = array_slice($cbt_tests, $cbt_sched_offset, $cbt_sched_per_page);

function getSlidingPages($current_page, $total_pages, $max_visible = 6) {
    if ($total_pages <= $max_visible) {
        return range(1, $total_pages);
    }
    
    $half = floor($max_visible / 2);
    $start = $current_page - $half;
    $end = $current_page + $half;
    
    if ($start < 1) {
        $start = 1;
        $end = $max_visible;
    }
    if ($end > $total_pages) {
        $end = $total_pages;
        $start = $total_pages - $max_visible + 1;
    }
    
    return range((int)$start, (int)$end);
}

if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    $section = $_GET['section'] ?? '';
    if ($section === 'grades') {
        ?>
        <div>
            <h3 class="text-lg font-semibold mb-4 text-blue-300">Grade Configuration</h3>
            <?php if (empty($grades)) : ?>
                <p class="text-gray-400 text-sm">No grading scale set. Navigate to 'Grade Scales' to configure.</p>
            <?php else : ?>
                <div class="space-y-2">
                    <?php foreach ($grades_paginated as $g) : ?>
                        <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl border border-white/5">
                            <span class="font-bold text-white text-lg"><?php echo htmlspecialchars($g['grade_name']); ?></span>
                            <span class="text-xs text-gray-400">Score Range: <strong class="text-blue-400"><?php echo htmlspecialchars($g['mark_from']); ?>% - <?php echo htmlspecialchars($g['mark_upto']); ?>%</strong></span>
                            <span class="text-xs italic text-gray-300"><?php echo htmlspecialchars($g['grade_comment']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($grades_total_pages > 1) : ?>
            <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                <span class="text-xs text-gray-400">Page <?php echo $grades_current_page; ?> of <?php echo $grades_total_pages; ?></span>
                <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                    <button onclick="loadGradesPage(<?php echo $grades_current_page - 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $grades_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ◀
                    </button>
                    <?php 
                    $visible_pages = getSlidingPages($grades_current_page, $grades_total_pages, 6);
                    foreach ($visible_pages as $i): 
                    ?>
                        <button onclick="loadGradesPage(<?php echo $i; ?>)" 
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $grades_current_page ? 'bg-blue-600/30 text-blue-400 border-blue-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                            <?php echo $i; ?>
                        </button>
                    <?php endforeach; ?>
                    <button onclick="loadGradesPage(<?php echo $grades_current_page + 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $grades_current_page >= $grades_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ▶
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php
        exit;
    } elseif ($section === 'cbt') {
        ?>
        <div>
            <h3 class="text-lg font-semibold mb-4 text-indigo-300">Active CBT Exams</h3>
            <?php if (empty($cbt_tests)) : ?>
                <p class="text-gray-400 text-sm">No computer-based tests scheduled yet.</p>
            <?php else : ?>
                <div class="space-y-3">
                    <?php foreach ($cbt_paginated as $cbt) : ?>
                        <div class="p-4 bg-white/5 rounded-xl border border-white/10 flex justify-between items-center">
                            <div>
                                <h4 class="font-semibold text-white"><?php echo htmlspecialchars($cbt['paper_title']); ?></h4>
                                <p class="text-xs text-gray-400">Class: <?php echo htmlspecialchars($cbt['numeric_class_name']); ?> | Questions: <?php echo htmlspecialchars($cbt['exam_questions']); ?></p>
                                <p class="text-[10px] text-indigo-400 mt-1">🕒 Duration: <?php echo htmlspecialchars($cbt['exam_duration']); ?> mins | Date: <?php echo htmlspecialchars($cbt['exam_date']); ?></p>
                            </div>
                            <span class="px-2 py-1 bg-green-500/10 border border-green-500/20 text-green-400 rounded-md text-xs font-semibold uppercase"><?php echo htmlspecialchars($cbt['cbt_type']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($cbt_total_pages > 1) : ?>
            <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                <span class="text-xs text-gray-400">Page <?php echo $cbt_current_page; ?> of <?php echo $cbt_total_pages; ?></span>
                <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                    <button onclick="loadCbtPage(<?php echo $cbt_current_page - 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ◀
                    </button>
                    <?php 
                    $visible_pages = getSlidingPages($cbt_current_page, $cbt_total_pages, 6);
                    foreach ($visible_pages as $i): 
                    ?>
                        <button onclick="loadCbtPage(<?php echo $i; ?>)" 
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $cbt_current_page ? 'bg-indigo-600/30 text-indigo-400 border-indigo-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                            <?php echo $i; ?>
                        </button>
                    <?php endforeach; ?>
                    <button onclick="loadCbtPage(<?php echo $cbt_current_page + 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_current_page >= $cbt_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ▶
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php
        exit;
    } elseif ($section === 'student_cbt') {
        $stmtClassTests = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND numeric_class_name = ? AND is_approved = 1 ORDER BY exam_date DESC, exam_time DESC");
        $stmtClassTests->execute([$school_id, $student_info['current_class']]);
        $student_tests = $stmtClassTests->fetchAll();
        
        $scbt_per_page = 5;
        $total_scbt = count($student_tests);
        $scbt_total_pages = max(1, ceil($total_scbt / $scbt_per_page));
        $scbt_current_page = isset($_GET['scbtpage']) ? max(1, (int)$_GET['scbtpage']) : 1;
        if ($scbt_current_page > $scbt_total_pages) $scbt_current_page = $scbt_total_pages;
        $scbt_offset = ($scbt_current_page - 1) * $scbt_per_page;
        $student_tests_paginated = array_slice($student_tests, $scbt_offset, $scbt_per_page);
        ?>
        <div>
            <h4 class="text-lg font-bold text-white mb-4">Your Available Examinations</h4>
            <?php if (empty($student_tests)) : ?>
                <div class="text-center py-8 text-gray-400 text-sm">No scheduled tests found for your class.</div>
            <?php else : ?>
                <div class="grid md:grid-cols-2 gap-4">
                    <?php 
                    foreach ($student_tests_paginated as $cbt) : 
                        $stmtSubCheck = $pdo->prepare("SELECT score, date_submitted FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ? LIMIT 1");
                        $stmtSubCheck->execute([$school_id, $cbt['cbt_id'], $student_info['admission_number']]);
                        $submission = $stmtSubCheck->fetch();

                        $exam_start = strtotime($cbt['exam_date'] . ' ' . $cbt['exam_time']);
                        $exam_end = $exam_start + ($cbt['exam_duration'] * 60);
                        $now = time();

                        $status = 'upcoming';
                        if ($now >= $exam_start && $now <= $exam_end) {
                            $status = 'active';
                        } elseif ($now > $exam_end) {
                            $status = 'expired';
                        }
                    ?>
                        <div class="bg-white/5 border border-white/10 rounded-xl p-5 flex flex-col justify-between gap-4">
                            <div>
                                <div class="flex justify-between items-start">
                                    <h5 class="font-bold text-white text-base"><?php echo htmlspecialchars($cbt['paper_title']); ?></h5>
                                    <?php if ($submission) : ?>
                                        <span class="px-2 py-0.5 bg-green-500/10 border border-green-500/20 text-green-400 rounded-md text-[10px] font-bold uppercase">Submitted</span>
                                    <?php elseif ($status === 'active') : ?>
                                        <span class="px-2 py-0.5 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-md text-[10px] font-bold uppercase animate-pulse">Active Now</span>
                                    <?php elseif ($status === 'expired') : ?>
                                        <span class="px-2 py-0.5 bg-red-500/10 border border-red-500/20 text-red-400 rounded-md text-[10px] font-bold uppercase">Expired</span>
                                    <?php else : ?>
                                        <span class="px-2 py-0.5 bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-md text-[10px] font-bold uppercase">Upcoming</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-indigo-400 mt-1 font-semibold"><?php echo htmlspecialchars($cbt['subject_code']); ?></p>
                                <div class="grid grid-cols-2 gap-y-1 gap-x-2 text-[11px] text-gray-400 mt-3 font-mono">
                                    <div>📅 Date:</div>
                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_date']); ?></div>
                                    <div>🕒 Start:</div>
                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_time']); ?></div>
                                    <div>⏱️ Duration:</div>
                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_duration']); ?> minutes</div>
                                    <div>📝 Questions:</div>
                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_questions']); ?> items</div>
                                </div>
                            </div>

                            <div>
                                <?php if ($submission) : ?>
                                    <div class="p-3 bg-black/20 rounded-lg border border-white/5 text-xs">
                                        <span class="text-gray-400">Score:</span> 
                                        <strong class="text-green-400 font-bold"><?php echo $submission['score']; ?> / <?php echo $cbt['exam_questions']; ?></strong>
                                        <span class="text-[10px] text-gray-500 block mt-1">Submitted: <?php echo $submission['date_submitted']; ?></span>
                                    </div>
                                <?php elseif ($status === 'active') : ?>
                                    <a href="?page=evaluation&action=take_cbt&cbt_id=<?php echo $cbt['cbt_id']; ?>" 
                                       class="w-full block text-center py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition shadow-lg">
                                        💻 Start Examination
                                    </a>
                                <?php elseif ($status === 'expired') : ?>
                                    <button disabled class="w-full py-2 bg-white/5 text-gray-500 border border-white/5 rounded-lg text-xs font-bold cursor-not-allowed">
                                        Exam Expired / Closed
                                    </button>
                                <?php else : ?>
                                    <button disabled class="w-full py-2 bg-white/5 text-gray-400 border border-white/5 rounded-lg text-xs font-bold cursor-not-allowed">
                                        Waiting to Open
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($scbt_total_pages > 1) : ?>
            <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                <span class="text-xs text-gray-400">Page <?php echo $scbt_current_page; ?> of <?php echo $scbt_total_pages; ?></span>
                <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                    <button onclick="loadStudentCbtPage(<?php echo $scbt_current_page - 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $scbt_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ◀
                    </button>
                    <?php 
                    $visible_pages = getSlidingPages($scbt_current_page, $scbt_total_pages, 6);
                    foreach ($visible_pages as $i): 
                    ?>
                        <button onclick="loadStudentCbtPage(<?php echo $i; ?>)" 
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $scbt_current_page ? 'bg-indigo-600/30 text-indigo-400 border-indigo-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                            <?php echo $i; ?>
                        </button>
                    <?php endforeach; ?>
                    <button onclick="loadStudentCbtPage(<?php echo $scbt_current_page + 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $scbt_current_page >= $scbt_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ▶
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php
        exit;
    } elseif ($section === 'cbt_sched') {
        ?>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px] text-left text-sm text-gray-300">
            <thead>
                <tr class="border-b border-white/10 text-gray-400 text-xs uppercase">
                    <th class="py-3 px-4">Title</th>
                    <th class="py-3 px-4">Class</th>
                    <th class="py-3 px-4">Subject</th>
                    <th class="py-3 px-4">Questions</th>
                    <th class="py-3 px-4">Duration</th>
                    <th class="py-3 px-4">Date & Time</th>
                    <th class="py-3 px-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($cbt_sched_paginated as $cbt) : ?>
                    <tr class="hover:bg-white/5 transition">
                        <td class="py-3.5 px-4 font-semibold text-white">
                            <?php echo htmlspecialchars($cbt['paper_title']); ?>
                            <?php if ($cbt['is_approved'] == 0): ?>
                                <span class="ml-2 px-1.5 py-0.5 bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 rounded text-[9px] font-bold uppercase tracking-wider">Pending Approval</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-xs"><?php echo htmlspecialchars($cbt['numeric_class_name']); ?></td>
                        <td class="py-3.5 px-4"><?php echo htmlspecialchars($cbt['subject_code']); ?></td>
                        <td class="py-3.5 px-4"><?php echo htmlspecialchars($cbt['exam_questions']) . ($cbt['attempt_questions'] > 0 ? ' (Attempt ' . $cbt['attempt_questions'] . ')' : ''); ?></td>
                        <td class="py-3.5 px-4"><?php echo htmlspecialchars($cbt['exam_duration']); ?> mins</td>
                        <td class="py-3.5 px-4 text-xs">
                            <div>📅 <?php echo htmlspecialchars($cbt['exam_date']); ?></div>
                            <div class="text-[10px] text-indigo-400 mt-0.5">🕒 <?php echo htmlspecialchars($cbt['exam_time']); ?></div>
                        </td>
                        <td class="py-3.5 px-4 text-right">
                            <div class="inline-flex gap-1.5 items-center">
                                <?php if ($cbt['is_approved'] == 0 && $user_role === 'school_admin'): ?>
                                    <form method="post" class="inline">
                                        <input type="hidden" name="cbt_id" value="<?php echo $cbt['cbt_id']; ?>" />
                                        <button type="submit" name="approve_cbt" class="px-2.5 py-1.5 bg-amber-600/20 hover:bg-amber-600/30 text-amber-300 rounded-lg text-xs font-semibold transition" title="Approve Test">
                                            ✅ Approve
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a href="?page=evaluation&action=add_cbt_quiz&cbt_id=<?php echo $cbt['cbt_id']; ?>" 
                                   class="px-2.5 py-1.5 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded-lg text-xs font-semibold transition" title="Set Questions">
                                    ✏️ Questions
                                </a>
                                <a href="?page=evaluation&action=view_cbt&cbt_id=<?php echo $cbt['cbt_id']; ?>" 
                                   class="px-2.5 py-1.5 bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 rounded-lg text-xs font-semibold transition" title="View Submissions">
                                    📊 Results
                                </a>
                                <form method="post" onsubmit="return confirm('Are you sure you want to delete this scheduled CBT exam?');" class="inline">
                                    <input type="hidden" name="cbt_id" value="<?php echo $cbt['cbt_id']; ?>" />
                                    <button type="submit" name="delete_cbt" class="px-2.5 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-300 rounded-lg text-xs font-semibold transition">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </div>
        <?php if ($cbt_sched_total_pages > 1) : ?>
            <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                <span class="text-xs text-gray-400">Showing <?php echo $cbt_sched_offset + 1; ?> to <?php echo min($total_cbt, $cbt_sched_offset + $cbt_sched_per_page); ?> of <?php echo $total_cbt; ?> schedules</span>
                <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                    <button onclick="loadSchedPage(<?php echo $cbt_sched_current_page - 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_sched_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ◀
                    </button>
                    <?php 
                    $visible_pages = getSlidingPages($cbt_sched_current_page, $cbt_sched_total_pages, 6);
                    foreach ($visible_pages as $i): 
                    ?>
                        <button onclick="loadSchedPage(<?php echo $i; ?>)" 
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $cbt_sched_current_page ? 'bg-indigo-600/30 text-indigo-400 border-indigo-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                            <?php echo $i; ?>
                        </button>
                    <?php endforeach; ?>
                    <button onclick="loadSchedPage(<?php echo $cbt_sched_current_page + 1; ?>)" 
                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_sched_current_page >= $cbt_sched_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                        ▶
                    </button>
                </div>
            </div>
        <?php endif; ?>
        <?php
        exit;
    }
}
?>

<?php if (!isset($_GET['print'])): ?>
<div class="space-y-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 pb-2">
        <h2 class="text-2xl font-extrabold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">📝 Student Evaluation</h2>
        <div class="flex overflow-x-auto gap-2 items-center w-full md:w-auto p-1 scrollbar-thin">
            <?php if ($user_role === 'school_admin' || $user_role === 'teacher'): ?>
                <a href="?page=evaluation&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-white/5 border border-white/10 text-white rounded-xl hover:bg-white/10 transition text-xs sm:text-sm font-semibold shadow-md">Dashboard</a>
                <a href="?page=evaluation&action=grades" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl transition text-xs sm:text-sm font-semibold shadow-md shadow-blue-600/25">Grade Scales</a>
                <a href="?page=evaluation&action=cbt" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition text-xs sm:text-sm font-semibold shadow-md shadow-indigo-600/25">CBT Builder</a>
                <a href="?page=evaluation&action=report_cards" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl transition text-xs sm:text-sm font-semibold shadow-md shadow-emerald-600/25 flex items-center gap-1">📂 Report Cards</a>
            <?php else: ?>
                <a href="?page=evaluation&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl transition text-xs sm:text-sm font-semibold shadow-md shadow-blue-600/25">Results Desk</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

    <?php if ($action === 'list') : ?>
        <?php if ($user_role === 'school_admin' || $user_role === 'teacher') : ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-6">
            <a href="?page=evaluation&action=enter_marks" class="flex flex-col items-center gap-2 p-5 bg-blue-600/10 border border-blue-500/20 hover:bg-blue-600/20 rounded-2xl text-center transition group">
                <span class="text-3xl group-hover:scale-110 transition-transform">✏️</span>
                <p class="font-bold text-blue-300 text-sm">Enter Marks</p>
                <p class="text-xs text-gray-400">Single student score entry per subject</p>
            </a>
            <a href="?page=evaluation&action=result_grid" class="flex flex-col items-center gap-2 p-5 bg-indigo-600/10 border border-indigo-500/20 hover:bg-indigo-600/20 rounded-2xl text-center transition group">
                <span class="text-3xl group-hover:scale-110 transition-transform">📊</span>
                <p class="font-bold text-indigo-300 text-sm">Class Result Grid</p>
                <p class="text-xs text-gray-400">Batch enter marks for all students in a class</p>
            </a>
            <a href="?page=evaluation&action=bulk_csv" class="flex flex-col items-center gap-2 p-5 bg-emerald-600/10 border border-emerald-500/20 hover:bg-emerald-600/20 rounded-2xl text-center transition group">
                <span class="text-3xl group-hover:scale-110 transition-transform">📂</span>
                <p class="font-bold text-emerald-300 text-sm">Bulk CSV Upload</p>
                <p class="text-xs text-gray-400">Upload result spreadsheet for entire class</p>
            </a>
            <a href="?page=evaluation&action=exam_halls" class="flex flex-col items-center gap-2 p-5 bg-amber-600/10 border border-amber-500/20 hover:bg-amber-600/20 rounded-2xl text-center transition group">
                <span class="text-3xl group-hover:scale-110 transition-transform">🏛️</span>
                <p class="font-bold text-amber-300 text-sm">Exam Halls</p>
                <p class="text-xs text-gray-400">Manage exam venues and hall assignments</p>
            </a>
            <a href="?page=evaluation&action=migration" class="flex flex-col items-center gap-2 p-5 bg-purple-600/10 border border-purple-500/20 hover:bg-purple-600/20 rounded-2xl text-center transition group">
                <span class="text-3xl group-hover:scale-110 transition-transform">🚀</span>
                <p class="font-bold text-purple-300 text-sm">Promote / Migrate</p>
                <p class="text-xs text-gray-400">Move students between classes at end of session</p>
            </a>
            <a href="?page=evaluation&action=report_cards" class="flex flex-col items-center gap-2 p-5 bg-rose-600/10 border border-rose-500/20 hover:bg-rose-600/20 rounded-2xl text-center transition group">
                <span class="text-3xl group-hover:scale-110 transition-transform">📄</span>
                <p class="font-bold text-rose-300 text-sm">Print Report Cards</p>
                <p class="text-xs text-gray-400">Single or bulk report card printing</p>
            </a>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Active evaluations -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md flex flex-col justify-between">
                <div id="grades-table-wrapper" class="flex flex-col justify-between h-full transition-opacity duration-200">
                    <div>
                        <h3 class="text-lg font-semibold mb-4 text-blue-300">Grade Configuration</h3>
                        <?php if (empty($grades)) : ?>
                            <p class="text-gray-400 text-sm">No grading scale set. Navigate to 'Grade Scales' to configure.</p>
                        <?php else : ?>
                            <div class="space-y-2">
                                <?php foreach ($grades_paginated as $g) : ?>
                                    <div class="flex justify-between items-center p-3 bg-white/5 rounded-xl border border-white/5">
                                        <span class="font-bold text-white text-lg"><?php echo htmlspecialchars($g['grade_name']); ?></span>
                                        <span class="text-xs text-gray-400">Score Range: <strong class="text-blue-400"><?php echo htmlspecialchars($g['mark_from']); ?>% - <?php echo htmlspecialchars($g['mark_upto']); ?>%</strong></span>
                                        <span class="text-xs italic text-gray-300"><?php echo htmlspecialchars($g['grade_comment']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($grades_total_pages > 1) : ?>
                        <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                            <span class="text-xs text-gray-400">Page <?php echo $grades_current_page; ?> of <?php echo $grades_total_pages; ?></span>
                            <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                                <button onclick="loadGradesPage(<?php echo $grades_current_page - 1; ?>)" 
                                   class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $grades_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                                    ◀
                                </button>
                                <?php 
                                $visible_pages = getSlidingPages($grades_current_page, $grades_total_pages, 6);
                                foreach ($visible_pages as $i): 
                                ?>
                                    <button onclick="loadGradesPage(<?php echo $i; ?>)" 
                                       class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $grades_current_page ? 'bg-blue-600/30 text-blue-400 border-blue-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                                        <?php echo $i; ?>
                                    </button>
                                <?php endforeach; ?>
                                <button onclick="loadGradesPage(<?php echo $grades_current_page + 1; ?>)" 
                                   class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $grades_current_page >= $grades_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                                    ▶
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CBT Panel -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md flex flex-col justify-between">
                <div id="cbt-exams-wrapper" class="flex flex-col justify-between h-full transition-opacity duration-200">
                    <div>
                        <h3 class="text-lg font-semibold mb-4 text-indigo-300">Active CBT Exams</h3>
                        <?php if (empty($cbt_tests)) : ?>
                            <p class="text-gray-400 text-sm">No computer-based tests scheduled yet.</p>
                        <?php else : ?>
                            <div class="space-y-3">
                                <?php foreach ($cbt_paginated as $cbt) : ?>
                                    <div class="p-4 bg-white/5 rounded-xl border border-white/10 flex justify-between items-center">
                                        <div>
                                            <h4 class="font-semibold text-white"><?php echo htmlspecialchars($cbt['paper_title']); ?></h4>
                                            <p class="text-xs text-gray-400">Class: <?php echo htmlspecialchars($cbt['numeric_class_name']); ?> | Questions: <?php echo htmlspecialchars($cbt['exam_questions']); ?></p>
                                            <p class="text-[10px] text-indigo-400 mt-1">🕒 Duration: <?php echo htmlspecialchars($cbt['exam_duration']); ?> mins | Date: <?php echo htmlspecialchars($cbt['exam_date']); ?></p>
                                        </div>
                                        <span class="px-2 py-1 bg-green-500/10 border border-green-500/20 text-green-400 rounded-md text-xs font-semibold uppercase"><?php echo htmlspecialchars($cbt['cbt_type']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($cbt_total_pages > 1) : ?>
                        <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                            <span class="text-xs text-gray-400">Page <?php echo $cbt_current_page; ?> of <?php echo $cbt_total_pages; ?></span>
                            <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                                <button onclick="loadCbtPage(<?php echo $cbt_current_page - 1; ?>)" 
                                   class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                                    ◀
                                </button>
                                <?php 
                                $visible_pages = getSlidingPages($cbt_current_page, $cbt_total_pages, 6);
                                foreach ($visible_pages as $i): 
                                ?>
                                    <button onclick="loadCbtPage(<?php echo $i; ?>)" 
                                       class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $cbt_current_page ? 'bg-indigo-600/30 text-indigo-400 border-indigo-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                                        <?php echo $i; ?>
                                    </button>
                                <?php endforeach; ?>
                                <button onclick="loadCbtPage(<?php echo $cbt_current_page + 1; ?>)" 
                                   class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_current_page >= $cbt_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                                    ▶
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'grades') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-blue-300">Configure Grade Rule</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Grade Symbol (e.g. A)</label>
                    <input type="text" name="grade_name" required placeholder="e.g. A" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Score From (%)</label>
                        <input type="number" name="mark_from" required placeholder="70" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Score To (%)</label>
                        <input type="number" name="mark_upto" required placeholder="100" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Default Remark</label>
                    <input type="text" name="grade_comment" required placeholder="Excellent" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <button type="submit" name="add_grade" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Save Grade Scale
                </button>
            </form>
        </div>

    <?php elseif ($action === 'cbt') : ?>
        <div class="space-y-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-white/5 border border-white/10 p-6 rounded-2xl">
                <div>
                    <h3 class="text-xl font-bold text-indigo-300">💻 CBT Center</h3>
                    <p class="text-sm text-gray-400">Manage computer-based examinations, quizzes, and continuous assessments.</p>
                </div>
                <?php if ($user_role === 'school_admin' || $user_role === 'teacher') : ?>
                    <div class="flex flex-wrap gap-2 w-full md:w-auto">
                        <a href="?page=evaluation&action=add_cbt" class="whitespace-nowrap px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-semibold transition">
                            ➕ Schedule Exam
                        </a>
                        <a href="?page=evaluation&action=past_questions" class="whitespace-nowrap px-4 py-2 bg-white/5 hover:bg-white/10 text-white border border-white/10 rounded-lg text-sm font-semibold transition">
                            📚 Past Questions
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($user_role === 'school_admin' || $user_role === 'teacher') : ?>
                <!-- Admin/Teacher View -->
                <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl">
                    <!-- Search & Filters -->
                    <form method="get" class="grid grid-cols-1 sm:grid-cols-6 gap-3 mb-6">
                        <input type="hidden" name="page" value="evaluation" />
                        <input type="hidden" name="action" value="cbt" />
                        <div>
                            <input type="text" name="search_cbt" placeholder="🔍 Title/Subject..." value="<?php echo htmlspecialchars($search_cbt); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.8 text-white focus:outline-none focus:border-blue-500 text-xs" />
                        </div>
                        <div>
                            <select name="filter_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.8 text-white focus:outline-none focus:border-blue-500 text-xs">
                                <option value="">All Classes</option>
                                <?php foreach ($school_classes as $cls) : ?>
                                    <option value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" <?php echo $filter_class === $cls['numeric_class_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cls['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="filter_session" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.8 text-white focus:outline-none focus:border-blue-500 text-xs">
                                <option value="">All Sessions</option>
                                <?php foreach ($school_sessions as $sess) : ?>
                                    <option value="<?php echo htmlspecialchars($sess['session_name']); ?>" <?php echo $filter_session === $sess['session_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sess['session_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="filter_term" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.8 text-white focus:outline-none focus:border-blue-500 text-xs">
                                <option value="">All Terms</option>
                                <?php foreach ($school_terms as $t) : ?>
                                    <option value="<?php echo htmlspecialchars($t['id_number']); ?>" <?php echo $filter_term === $t['id_number'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['term_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="filter_type" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.8 text-white focus:outline-none focus:border-blue-500 text-xs">
                                <option value="">All Types</option>
                                <option value="exam" <?php echo $filter_type === 'exam' ? 'selected' : ''; ?>>Exam</option>
                                <option value="test" <?php echo $filter_type === 'test' ? 'selected' : ''; ?>>Test</option>
                                <option value="quiz" <?php echo $filter_type === 'quiz' ? 'selected' : ''; ?>>Quiz</option>
                            </select>
                        </div>
                        <div class="flex gap-1">
                            <button type="submit" class="flex-1 px-3 py-1.8 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition">Filter</button>
                            <a href="?page=evaluation&action=cbt" class="px-2 py-1.8 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-xs transition flex items-center justify-center">Reset</a>
                        </div>
                    </form>

                    <h4 class="text-lg font-bold text-white mb-4">Active CBT Schedules</h4>
                    <div id="cbt-schedules-wrapper" class="transition-opacity duration-200">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[800px] text-left text-sm text-gray-300">
                                <thead>
                                    <tr class="border-b border-white/10 text-gray-400 text-xs uppercase">
                                        <th class="py-3 px-4">Title</th>
                                        <th class="py-3 px-4">Class</th>
                                        <th class="py-3 px-4">Subject</th>
                                        <th class="py-3 px-4">Questions</th>
                                        <th class="py-3 px-4">Duration</th>
                                        <th class="py-3 px-4">Date & Time</th>
                                        <th class="py-3 px-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <?php foreach ($cbt_sched_paginated as $cbt) : ?>
                                        <tr class="hover:bg-white/5 transition">
                                            <td class="py-3.5 px-4 font-semibold text-white"><?php echo htmlspecialchars($cbt['paper_title']); ?></td>
                                            <td class="py-3.5 px-4 font-mono text-xs"><?php echo htmlspecialchars($cbt['numeric_class_name']); ?></td>
                                            <td class="py-3.5 px-4"><?php echo htmlspecialchars($cbt['subject_code']); ?></td>
                                            <td class="py-3.5 px-4"><?php echo htmlspecialchars($cbt['exam_questions']); ?></td>
                                            <td class="py-3.5 px-4"><?php echo htmlspecialchars($cbt['exam_duration']); ?> mins</td>
                                            <td class="py-3.5 px-4 text-xs">
                                                <div>📅 <?php echo htmlspecialchars($cbt['exam_date']); ?></div>
                                                <div class="text-[10px] text-indigo-400 mt-0.5">🕒 <?php echo htmlspecialchars($cbt['exam_time']); ?></div>
                                            </td>
                                            <td class="py-3.5 px-4 text-right">
                                                <div class="inline-flex gap-1.5">
                                                    <a href="?page=evaluation&action=add_cbt_quiz&cbt_id=<?php echo $cbt['cbt_id']; ?>" 
                                                       class="px-2.5 py-1.5 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded-lg text-xs font-semibold transition" title="Set Questions">
                                                        ✏️ Questions
                                                    </a>
                                                    <a href="?page=evaluation&action=view_cbt&cbt_id=<?php echo $cbt['cbt_id']; ?>" 
                                                       class="px-2.5 py-1.5 bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 rounded-lg text-xs font-semibold transition" title="View Submissions">
                                                        📊 Results
                                                    </a>
                                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete this scheduled CBT exam?');" class="inline">
                                                        <input type="hidden" name="cbt_id" value="<?php echo $cbt['cbt_id']; ?>" />
                                                        <button type="submit" name="delete_cbt" class="px-2.5 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-300 rounded-lg text-xs font-semibold transition">
                                                            🗑️ Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($cbt_sched_total_pages > 1) : ?>
                            <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                                <span class="text-xs text-gray-400">Showing <?php echo $cbt_sched_offset + 1; ?> to <?php echo min($total_cbt, $cbt_sched_offset + $cbt_sched_per_page); ?> of <?php echo $total_cbt; ?> schedules</span>
                                <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                                    <button onclick="loadSchedPage(<?php echo $cbt_sched_current_page - 1; ?>)" 
                                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_sched_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                                        ◀
                                    </button>
                                    <?php 
                                    $visible_pages = getSlidingPages($cbt_sched_current_page, $cbt_sched_total_pages, 6);
                                    foreach ($visible_pages as $i): 
                                    ?>
                                        <button onclick="loadSchedPage(<?php echo $i; ?>)" 
                                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $cbt_sched_current_page ? 'bg-indigo-600/30 text-indigo-400 border-indigo-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                                            <?php echo $i; ?>
                                        </button>
                                    <?php endforeach; ?>
                                    <button onclick="loadSchedPage(<?php echo $cbt_sched_current_page + 1; ?>)" 
                                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $cbt_sched_current_page >= $cbt_sched_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                                        ▶
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else : ?>
                <!-- Student View -->
                <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl flex flex-col justify-between">
                    <div id="student-cbt-wrapper" class="flex flex-col justify-between h-full transition-opacity duration-200">
                        <?php
                        // We will load the paginated view directly using the same logic
                        $stmtClassTests = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND numeric_class_name = ? AND is_approved = 1 ORDER BY exam_date DESC, exam_time DESC");
                        $stmtClassTests->execute([$school_id, $student_info['current_class']]);
                        $student_tests = $stmtClassTests->fetchAll();
                        
                        $scbt_per_page = 5;
                        $total_scbt = count($student_tests);
                        $scbt_total_pages = max(1, ceil($total_scbt / $scbt_per_page));
                        $scbt_current_page = isset($_GET['scbtpage']) ? max(1, (int)$_GET['scbtpage']) : 1;
                        if ($scbt_current_page > $scbt_total_pages) $scbt_current_page = $scbt_total_pages;
                        $scbt_offset = ($scbt_current_page - 1) * $scbt_per_page;
                        $student_tests_paginated = array_slice($student_tests, $scbt_offset, $scbt_per_page);
                        ?>
                        <div>
                            <h4 class="text-lg font-bold text-white mb-4">Your Available Examinations</h4>
                            <?php if (empty($student_tests)) : ?>
                                <div class="text-center py-8 text-gray-400 text-sm">No scheduled tests found for your class.</div>
                            <?php else : ?>
                                <div class="grid md:grid-cols-2 gap-4">
                                    <?php 
                                    foreach ($student_tests_paginated as $cbt) : 
                                        $stmtSubCheck = $pdo->prepare("SELECT score, date_submitted FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ? LIMIT 1");
                                        $stmtSubCheck->execute([$school_id, $cbt['cbt_id'], $student_info['admission_number']]);
                                        $submission = $stmtSubCheck->fetch();

                                        $exam_start = strtotime($cbt['exam_date'] . ' ' . $cbt['exam_time']);
                                        $exam_end = $exam_start + ($cbt['exam_duration'] * 60);
                                        $now = time();

                                        $status = 'upcoming';
                                        if ($now >= $exam_start && $now <= $exam_end) {
                                            $status = 'active';
                                        } elseif ($now > $exam_end) {
                                            $status = 'expired';
                                        }
                                    ?>
                                        <div class="bg-white/5 border border-white/10 rounded-xl p-5 flex flex-col justify-between gap-4">
                                            <div>
                                                <div class="flex justify-between items-start">
                                                    <h5 class="font-bold text-white text-base"><?php echo htmlspecialchars($cbt['paper_title']); ?></h5>
                                                    <?php if ($submission) : ?>
                                                        <span class="px-2 py-0.5 bg-green-500/10 border border-green-500/20 text-green-400 rounded-md text-[10px] font-bold uppercase">Submitted</span>
                                                    <?php elseif ($status === 'active') : ?>
                                                        <span class="px-2 py-0.5 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-md text-[10px] font-bold uppercase animate-pulse">Active Now</span>
                                                    <?php elseif ($status === 'expired') : ?>
                                                        <span class="px-2 py-0.5 bg-red-500/10 border border-red-500/20 text-red-400 rounded-md text-[10px] font-bold uppercase">Expired</span>
                                                    <?php else : ?>
                                                        <span class="px-2 py-0.5 bg-amber-500/10 border border-amber-500/20 text-amber-400 rounded-md text-[10px] font-bold uppercase">Upcoming</span>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-xs text-indigo-400 mt-1 font-semibold"><?php echo htmlspecialchars($cbt['subject_code']); ?></p>
                                                <div class="grid grid-cols-2 gap-y-1 gap-x-2 text-[11px] text-gray-400 mt-3 font-mono">
                                                    <div>📅 Date:</div>
                                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_date']); ?></div>
                                                    <div>🕒 Start:</div>
                                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_time']); ?></div>
                                                    <div>⏱️ Duration:</div>
                                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_duration']); ?> minutes</div>
                                                    <div>📝 Questions:</div>
                                                    <div class="text-white"><?php echo htmlspecialchars($cbt['exam_questions']); ?> items</div>
                                                </div>
                                            </div>

                                            <div>
                                                <?php if ($submission) : ?>
                                                    <div class="p-3 bg-black/20 rounded-lg border border-white/5 text-xs">
                                                        <span class="text-gray-400">Score:</span> 
                                                        <strong class="text-green-400 font-bold"><?php echo $submission['score']; ?> / <?php echo $cbt['exam_questions']; ?></strong>
                                                        <span class="text-[10px] text-gray-500 block mt-1">Submitted: <?php echo $submission['date_submitted']; ?></span>
                                                    </div>
                                                <?php elseif ($status === 'active') : ?>
                                                    <a href="?page=evaluation&action=take_cbt&cbt_id=<?php echo $cbt['cbt_id']; ?>" 
                                                       class="w-full block text-center py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold transition shadow-lg">
                                                        💻 Start Examination
                                                    </a>
                                                <?php elseif ($status === 'expired') : ?>
                                                    <button disabled class="w-full py-2 bg-white/5 text-gray-500 border border-white/5 rounded-lg text-xs font-bold cursor-not-allowed">
                                                        Exam Expired / Closed
                                                    </button>
                                                <?php else : ?>
                                                    <button disabled class="w-full py-2 bg-white/5 text-gray-400 border border-white/5 rounded-lg text-xs font-bold cursor-not-allowed">
                                                        Waiting to Open
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($scbt_total_pages > 1) : ?>
                            <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/5">
                                <span class="text-xs text-gray-400">Page <?php echo $scbt_current_page; ?> of <?php echo $scbt_total_pages; ?></span>
                                <div class="flex flex-wrap items-center gap-1 justify-center sm:justify-end">
                                    <button onclick="loadStudentCbtPage(<?php echo $scbt_current_page - 1; ?>)" 
                                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $scbt_current_page <= 1 ? 'opacity-50 pointer-events-none' : ''; ?>">
                                        ◀
                                    </button>
                                    <?php 
                                    $visible_pages = getSlidingPages($scbt_current_page, $scbt_total_pages, 6);
                                    foreach ($visible_pages as $i): 
                                    ?>
                                        <button onclick="loadStudentCbtPage(<?php echo $i; ?>)" 
                                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition border <?php echo $i === $scbt_current_page ? 'bg-indigo-600/30 text-indigo-400 border-indigo-500/30' : 'bg-white/5 hover:bg-white/10 text-white border-white/10'; ?>">
                                            <?php echo $i; ?>
                                        </button>
                                    <?php endforeach; ?>
                                    <button onclick="loadStudentCbtPage(<?php echo $scbt_current_page + 1; ?>)" 
                                       class="px-2.5 py-1.5 bg-white/5 hover:bg-white/10 text-white rounded-lg text-xs font-semibold transition border border-white/10 <?php echo $scbt_current_page >= $scbt_total_pages ? 'opacity-50 pointer-events-none' : ''; ?>">
                                        ▶
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
            function loadGradesPage(page) {
                const wrapper = document.getElementById('grades-table-wrapper');
                if (!wrapper) return;
                wrapper.style.opacity = '0.5';
                fetch('?page=evaluation&ajax=1&section=grades&gpage=' + page)
                    .then(response => response.text())
                    .then(html => {
                        wrapper.innerHTML = html;
                        wrapper.style.opacity = '1';
                    })
                    .catch(err => {
                        console.error(err);
                        wrapper.style.opacity = '1';
                    });
            }

            function loadCbtPage(page) {
                const wrapper = document.getElementById('cbt-exams-wrapper');
                if (!wrapper) return;
                wrapper.style.opacity = '0.5';
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('cbtpage', page);
                urlParams.set('ajax', '1');
                urlParams.set('section', 'cbt');
                fetch('?' + urlParams.toString())
                    .then(response => response.text())
                    .then(html => {
                        wrapper.innerHTML = html;
                        wrapper.style.opacity = '1';
                    })
                    .catch(err => {
                        console.error(err);
                        wrapper.style.opacity = '1';
                    });
            }

            function loadStudentCbtPage(page) {
                const wrapper = document.getElementById('student-cbt-wrapper');
                if (!wrapper) return;
                wrapper.style.opacity = '0.5';
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('scbtpage', page);
                urlParams.set('ajax', '1');
                urlParams.set('section', 'student_cbt');
                fetch('?' + urlParams.toString())
                    .then(response => response.text())
                    .then(html => {
                        wrapper.innerHTML = html;
                        wrapper.style.opacity = '1';
                    })
                    .catch(err => {
                        console.error(err);
                        wrapper.style.opacity = '1';
                    });
            }

            function loadSchedPage(page) {
                const wrapper = document.getElementById('cbt-schedules-wrapper');
                if (!wrapper) return;
                wrapper.style.opacity = '0.5';
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('schedpage', page);
                urlParams.set('ajax', '1');
                urlParams.set('section', 'cbt_sched');
                fetch('?' + urlParams.toString())
                    .then(response => response.text())
                    .then(html => {
                        wrapper.innerHTML = html;
                        wrapper.style.opacity = '1';
                    })
                    .catch(err => {
                        console.error(err);
                        wrapper.style.opacity = '1';
                    });
            }

            // Auto-refresh student CBT list every 20 seconds to activate scheduled exams automatically
            if (document.getElementById('student-cbt-wrapper')) {
                setInterval(() => {
                    const currentPage = new URLSearchParams(window.location.search).get('scbtpage') || 1;
                    loadStudentCbtPage(currentPage);
                }, 20000);
            }
        </script>

    <?php elseif ($action === 'add_cbt') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <div class="flex items-center gap-3 mb-6">
                <a href="?page=evaluation&action=cbt" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
                <h3 class="text-lg font-semibold text-indigo-300">Schedule CBT Exam</h3>
            </div>
            
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Paper Title</label>
                    <input type="text" name="paper_title" required placeholder="e.g. Midterm Physics" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Class Code</label>
                        <select name="numeric_class_name" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <?php
                            $stmtCls = $pdo->prepare("SELECT DISTINCT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? ORDER BY class_name");
                            $stmtCls->execute([$school_id]);
                            foreach ($stmtCls->fetchAll() as $c) {
                                echo "<option value='".htmlspecialchars($c['numeric_class_name'])."'>".htmlspecialchars($c['class_name'])." (".htmlspecialchars($c['numeric_class_name']).")</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Subject Code</label>
                        <select name="subject_code" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <?php
                            $stmtSub = $pdo->prepare("SELECT DISTINCT subject_code, subject_name FROM sm_subjects WHERE school_id_number = ? ORDER BY subject_name");
                            $stmtSub->execute([$school_id]);
                            foreach ($stmtSub->fetchAll() as $s) {
                                echo "<option value='".htmlspecialchars($s['subject_code'])."'>".htmlspecialchars($s['subject_name'])." (".htmlspecialchars($s['subject_code']).")</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Session</label>
                        <select name="session" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <?php
                            $stmtSess = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC");
                            $stmtSess->execute([$school_id]);
                            foreach ($stmtSess->fetchAll() as $s) {
                                echo "<option value='".htmlspecialchars($s['session_name'])."'>".htmlspecialchars($s['session_name'])."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Term ID</label>
                        <select name="term_id_number" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <?php
                            $stmtTrm = $pdo->prepare("SELECT id_number, term_name FROM sm_terms WHERE school_id_number = ? ORDER BY term_name");
                            $stmtTrm->execute([$school_id]);
                            foreach ($stmtTrm->fetchAll() as $t) {
                                echo "<option value='".htmlspecialchars($t['id_number'])."'>".htmlspecialchars($t['term_name'])."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">CBT Mode</label>
                        <select name="cbt_type" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="exam">Official Exam</option>
                            <option value="quiz">Classroom Quiz</option>
                            <option value="test">Continuous Assessment</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Exam Date</label>
                        <input type="date" name="exam_date" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Start Time</label>
                        <input type="time" name="exam_time" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Duration (Min)</label>
                        <input type="number" name="exam_duration" value="60" required min="1" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Total Questions</label>
                        <input type="number" name="exam_questions" value="10" required min="1" max="100" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Attempt Questions (0 for all)</label>
                        <input type="number" name="attempt_questions" value="0" required min="0" max="100" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>

                <button type="submit" name="create_cbt" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition">
                    Schedule Exam & Setup Questions
                </button>
            </form>
        </div>

    <?php elseif ($action === 'add_cbt_quiz') : 
        $cbt_id = (int)$_GET['cbt_id'];
        $stmtC = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
        $stmtC->execute([$school_id, $cbt_id]);
        $cbt = $stmtC->fetch();

        if (!$cbt) {
            echo "<div class='p-4 bg-red-500/10 text-red-400 border border-red-500/20 rounded-xl'>Scheduled exam not found.</div>";
        } else {
            $quiz_data = json_decode($cbt['exam_json'], true);
            $questions_list = $quiz_data['quiz'] ?? [];
            $total_to_make = $cbt['exam_questions'];
        ?>
            <div class="max-w-3xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto space-y-6">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <a href="?page=evaluation&action=cbt" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
                        <h3 class="text-lg font-semibold text-indigo-300">Set Questions: <?php echo htmlspecialchars($cbt['paper_title']); ?></h3>
                    </div>
                    <span class="text-xs text-gray-400 font-mono">Class: <?php echo htmlspecialchars($cbt['numeric_class_name']); ?></span>
                </div>

                <form method="post" class="space-y-8">
                    <input type="hidden" name="cbt_id" value="<?php echo $cbt_id; ?>" />

                    <!-- Metadata Editing Section -->
                    <div class="p-6 bg-white/5 border border-white/10 rounded-xl space-y-4">
                        <h4 class="text-sm font-bold text-indigo-300 uppercase tracking-wider">📋 Edit Examination Details</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Paper Title</label>
                                <input type="text" name="paper_title" value="<?php echo htmlspecialchars($cbt['paper_title']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Exam Date</label>
                                <input type="date" name="exam_date" value="<?php echo htmlspecialchars($cbt['exam_date']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Start Time</label>
                                <input type="time" name="exam_time" value="<?php echo htmlspecialchars($cbt['exam_time']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Duration (Minutes)</label>
                                <input type="number" name="exam_duration" value="<?php echo htmlspecialchars($cbt['exam_duration']); ?>" required min="1" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Total Questions</label>
                                <input type="number" name="attempt_questions_hidden" id="metadata_exam_questions_disp" disabled value="<?php echo htmlspecialchars($cbt['exam_questions']); ?>" class="w-full bg-white/10 border border-white/15 rounded-lg px-4 py-2 text-gray-400 cursor-not-allowed text-sm" />
                                <input type="hidden" name="exam_questions" id="metadata_exam_questions" value="<?php echo htmlspecialchars($cbt['exam_questions']); ?>" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-400 mb-1">Attempt Questions (0 for all)</label>
                                <input type="number" name="attempt_questions" value="<?php echo htmlspecialchars($cbt['attempt_questions']); ?>" required min="0" max="100" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                            </div>
                        </div>
                        <div class="pt-2">
                            <label class="block text-xs text-gray-400 mb-1 font-semibold">Quick Adjustment: Number of Questions</label>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="adjustQuestionLimit(-1)" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-xs font-bold text-white transition">- 1 Question</button>
                                <button type="button" onclick="adjustQuestionLimit(1)" class="px-3 py-1.5 bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/20 rounded-lg text-xs font-bold text-indigo-300 transition">+ 1 Question</button>
                            </div>
                        </div>
                    </div>

                    <!-- Questions container -->
                    <div id="questions-container" class="space-y-6">
                        <?php for ($i = 0; $i < $total_to_make; $i++) : 
                            $q = $questions_list[$i] ?? null;
                            $q_text = $q ? base64_decode($q['question']) : '';
                            $opt1 = $q ? base64_decode($q['answers']['correct']) : '';
                            $opt2 = $q ? base64_decode($q['answers']['wrong'][0] ?? '') : '';
                            $opt3 = $q ? base64_decode($q['answers']['wrong'][1] ?? '') : '';
                            $opt4 = $q ? base64_decode($q['answers']['wrong'][2] ?? '') : '';
                        ?>
                            <div class="question-card p-6 bg-white/5 border border-white/10 rounded-xl space-y-4" data-index="<?php echo $i; ?>">
                                <div class="font-bold text-indigo-400 text-sm">Question <?php echo $i + 1; ?></div>
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">Question Body</label>
                                    <textarea id="q-text-<?php echo $i; ?>" name="questions[]" rows="2" placeholder="Enter question description" class="rich-text w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"><?php echo htmlspecialchars($q_text); ?></textarea>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-green-400">Option 1 (Correct Answer)</label>
                                        <textarea name="option1[]" placeholder="Correct answer" required class="w-full bg-white/5 border border-green-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-green-500 text-sm"><?php echo htmlspecialchars($opt1); ?></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-red-400">Option 2 (Wrong Option)</label>
                                        <textarea name="option2[]" placeholder="Wrong option 1" required class="w-full bg-white/5 border border-red-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-red-500 text-sm"><?php echo htmlspecialchars($opt2); ?></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-red-400">Option 3 (Wrong Option)</label>
                                        <textarea name="option3[]" placeholder="Wrong option 2" required class="w-full bg-white/5 border border-red-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-red-500 text-sm"><?php echo htmlspecialchars($opt3); ?></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-red-400">Option 4 (Wrong Option)</label>
                                        <textarea name="option4[]" placeholder="Wrong option 3" required class="w-full bg-white/5 border border-red-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-red-500 text-sm"><?php echo htmlspecialchars($opt4); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <button type="submit" name="update_cbt_questions" class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-bold transition shadow-lg">
                        💾 Save All Exam Questions
                    </button>
                </form>
            </div>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js"></script>
            <script>
                tinymce.init({
                    selector: '.rich-text',
                    menubar: false,
                    statusbar: false,
                    skin: 'oxide-dark',
                    content_css: 'dark',
                    height: 120,
                    plugins: 'advlist autolink lists link charmap searchreplace visualblocks code',
                    toolbar: 'undo redo | bold italic underline superscript subscript | bullist numlist | removeformat | code',
                    setup: function (editor) {
                        editor.on('change', function () {
                            tinymce.triggerSave();
                        });
                    }
                });

                function adjustQuestionLimit(change) {
                    const qInput = document.getElementById('metadata_exam_questions');
                    const qDisp = document.getElementById('metadata_exam_questions_disp');
                    let currentVal = parseInt(qInput.value) || 0;
                    let newVal = currentVal + change;
                    if (newVal >= 1 && newVal <= 100) {
                        qInput.value = newVal;
                        qDisp.value = newVal;
                        adjustQuestionCount(newVal);
                    }
                }

                function adjustQuestionCount(newCount) {
                    const container = document.getElementById('questions-container');
                    const currentCount = container.children.length;
                    if (newCount > currentCount) {
                        for (let i = currentCount; i < newCount; i++) {
                            const card = document.createElement('div');
                            card.className = 'question-card p-6 bg-white/5 border border-white/10 rounded-xl space-y-4';
                            card.setAttribute('data-index', i);
                            const textareaId = 'q-text-' + i;
                            card.innerHTML = `
                                <div class="font-bold text-indigo-400 text-sm">Question ${i + 1}</div>
                                <div>
                                    <label class="block text-xs text-gray-400 mb-1">Question Body</label>
                                    <textarea id="${textareaId}" name="questions[]" rows="2" placeholder="Enter question description" class="rich-text w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"></textarea>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-green-400">Option 1 (Correct Answer)</label>
                                        <textarea name="option1[]" placeholder="Correct answer" required class="w-full bg-white/5 border border-green-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-green-500 text-sm"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-red-400">Option 2 (Wrong Option)</label>
                                        <textarea name="option2[]" placeholder="Wrong option 1" required class="w-full bg-white/5 border border-red-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-red-500 text-sm"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-red-400">Option 3 (Wrong Option)</label>
                                        <textarea name="option3[]" placeholder="Wrong option 2" required class="w-full bg-white/5 border border-red-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-red-500 text-sm"></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1 text-red-400">Option 4 (Wrong Option)</label>
                                        <textarea name="option4[]" placeholder="Wrong option 3" required class="w-full bg-white/5 border border-red-500/20 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-red-500 text-sm"></textarea>
                                    </div>
                                </div>
                            `;
                            container.appendChild(card);
                            if (typeof tinymce !== 'undefined') {
                                tinymce.init({
                                    selector: '#' + textareaId,
                                    menubar: false,
                                    statusbar: false,
                                    skin: 'oxide-dark',
                                    content_css: 'dark',
                                    height: 120,
                                    plugins: 'advlist autolink lists link charmap searchreplace visualblocks code',
                                    toolbar: 'undo redo | bold italic underline superscript subscript | bullist numlist | removeformat | code',
                                    setup: function (editor) {
                                        editor.on('change', function () {
                                            tinymce.triggerSave();
                                        });
                                    }
                                });
                            }
                        }
                    } else if (newCount < currentCount) {
                        for (let i = currentCount - 1; i >= newCount; i--) {
                            const card = container.children[i];
                            const textarea = card.querySelector('textarea.rich-text');
                            if (textarea && typeof tinymce !== 'undefined') {
                                tinymce.execCommand('mceRemoveEditor', false, textarea.id);
                            }
                            container.removeChild(card);
                        }
                    }
                }
            </script>
        <?php } ?>

    <?php elseif ($action === 'past_questions') : ?>
        <div class="space-y-6">
            <div class="flex items-center gap-3 bg-white/5 border border-white/10 p-6 rounded-2xl">
                <a href="?page=evaluation&action=cbt" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
                <h3 class="text-xl font-bold text-indigo-300">📚 Past Question Repository</h3>
            </div>

            <!-- Search and filters -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl">
                <form method="get" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <input type="hidden" name="page" value="evaluation" />
                    <input type="hidden" name="action" value="past_questions" />

                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Subject</label>
                        <select name="subject" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                            <option value="">All Subjects</option>
                            <?php
                            $stmtS = $pdo->prepare("SELECT DISTINCT subject_code, subject_name FROM sm_subjects WHERE school_id_number = ?");
                            $stmtS->execute([$school_id]);
                            foreach ($stmtS->fetchAll() as $s) {
                                $selected = (isset($_GET['subject']) && $_GET['subject'] === $s['subject_code']) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($s['subject_code'])."' {$selected}>".htmlspecialchars($s['subject_name'])."</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Session</label>
                        <select name="session" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                            <option value="">All Sessions</option>
                            <?php
                            $stmtSe = $pdo->prepare("SELECT DISTINCT session FROM sm_cbt_schedules WHERE school_id_number = ?");
                            $stmtSe->execute([$school_id]);
                            foreach ($stmtSe->fetchAll() as $se) {
                                $selected = (isset($_GET['session']) && $_GET['session'] === $se['session']) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($se['session'])."' {$selected}>".htmlspecialchars($se['session'])."</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Search Keywords</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Search titles..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition">
                            🔍 Filter Past Questions
                        </button>
                    </div>
                </form>
            </div>

            <!-- List Results -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl">
                <?php
                $q_parts = ["school_id_number = ?"];
                $q_args = [$school_id];

                if (!empty($_GET['subject'])) {
                    $q_parts[] = "subject_code = ?";
                    $q_args[] = $_GET['subject'];
                }
                if (!empty($_GET['session'])) {
                    $q_parts[] = "session = ?";
                    $q_args[] = $_GET['session'];
                }
                if (!empty($_GET['search'])) {
                    $q_parts[] = "paper_title LIKE ?";
                    $q_args[] = "%" . $_GET['search'] . "%";
                }

                $query_str = "SELECT * FROM sm_cbt_schedules WHERE " . implode(' AND ', $q_parts) . " ORDER BY exam_date DESC";
                $stmtQ = $pdo->prepare($query_str);
                $stmtQ->execute($q_args);
                $past_list = $stmtQ->fetchAll();

                if (empty($past_list)) :
                ?>
                    <div class="text-center py-8 text-gray-400 text-sm">No examinations match the search filters.</div>
                <?php else : ?>
                    <form method="post" class="space-y-4">
                        <div class="flex justify-between items-center p-3 bg-white/5 border border-white/10 rounded-xl">
                            <label class="flex items-center gap-2 text-xs font-semibold text-gray-300 cursor-pointer select-none">
                                <input type="checkbox" id="selectAllPastQuestions" onchange="toggleSelectAllPastQuestions(this)" class="rounded bg-white/5 border-white/10 text-indigo-600 focus:ring-0 focus:ring-offset-0" />
                                Select All
                            </label>
                            <button type="submit" name="bulk_delete_cbt" onclick="return confirm('Are you sure you want to delete all selected exams? This action cannot be undone.');" class="px-3.5 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-500/20 rounded-lg text-xs font-bold transition flex items-center gap-1">
                                🗑️ Delete Selected
                            </button>
                        </div>

                        <div class="grid md:grid-cols-2 gap-4">
                            <?php foreach ($past_list as $c) : ?>
                                <div class="p-5 bg-white/5 border border-white/10 rounded-xl flex items-start gap-4">
                                    <input type="checkbox" name="bulk_ids[]" value="<?php echo $c['cbt_id']; ?>" class="bulk-select-checkbox rounded bg-white/5 border-white/10 text-indigo-600 focus:ring-0 focus:ring-offset-0 mt-1" />
                                    <div class="flex-1 flex flex-col justify-between gap-4">
                                        <div>
                                            <h5 class="font-bold text-white"><?php echo htmlspecialchars($c['paper_title']); ?></h5>
                                            <p class="text-xs text-indigo-400 font-mono mt-0.5"><?php echo htmlspecialchars($c['subject_code']); ?> | Session: <?php echo htmlspecialchars($c['session']); ?> | Term: <?php echo htmlspecialchars($c['term_id_number']); ?></p>
                                            <p class="text-xs text-gray-400 mt-2">Questions: <?php echo htmlspecialchars($c['exam_questions']); ?> | Duration: <?php echo htmlspecialchars($c['exam_duration']); ?> mins</p>
                                        </div>
                                        <div class="border-t border-white/10 pt-3 flex items-center justify-between w-full">
                                            <span class="text-[10px] text-gray-500">Scheduled: <?php echo htmlspecialchars($c['exam_date']); ?></span>
                                            
                                            <div class="flex gap-2">
                                                <a href="?page=evaluation&action=preview_past_paper&cbt_id=<?php echo $c['cbt_id']; ?>" class="px-3 py-1 bg-gray-600 hover:bg-gray-700 text-white rounded text-[10px] font-bold transition flex items-center justify-center">
                                                    Preview
                                                </a>

                                                <button type="button" onclick="openCloneModal(<?php echo $c['cbt_id']; ?>, '<?php echo addslashes($c['paper_title']); ?>')" class="px-3 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-[10px] font-bold transition flex items-center gap-1">
                                                    <span>📋</span> Clone
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Clone Modal -->
        <div id="clone-cbt-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[9999] flex items-center justify-center hidden">
            <div class="bg-[#111827] border border-white/10 rounded-2xl p-6 max-w-md w-full mx-4 space-y-4 shadow-2xl">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">📋 Clone Examination</h3>
                <form method="post" id="actualCloneForm" class="space-y-4">
                    <input type="hidden" name="clone_id" id="modal_clone_id" value="" />
                    <input type="hidden" name="clone_cbt" value="1" />
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">New Paper Title</label>
                        <input type="text" name="paper_title" id="modal_paper_title" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Target Session</label>
                        <select name="session" id="modal_session" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <?php
                            $stmtSessAll = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC");
                            $stmtSessAll->execute([$school_id]);
                            foreach ($stmtSessAll->fetchAll() as $s) {
                                $sel = (isset($latest_sess) && $s['session_name'] === $latest_sess) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($s['session_name'])."' {$sel}>".htmlspecialchars($s['session_name'])."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Target Term</label>
                        <select name="term_id_number" id="modal_term_id_number" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <?php
                            $stmtTrmAll = $pdo->prepare("SELECT id_number, term_name FROM sm_terms WHERE school_id_number = ? ORDER BY term_name");
                            $stmtTrmAll->execute([$school_id]);
                            foreach ($stmtTrmAll->fetchAll() as $t) {
                                $sel = (isset($latest_term) && $t['id_number'] === $latest_term) ? 'selected' : '';
                                echo "<option value='".htmlspecialchars($t['id_number'])."' {$sel}>".htmlspecialchars($t['term_name'])."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" onclick="closeCloneModal()" class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-xs font-semibold">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold shadow-lg">Confirm Clone</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        function toggleSelectAllPastQuestions(master) {
            const checkboxes = document.querySelectorAll('.bulk-select-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = master.checked;
            });
        }

        function openCloneModal(cbtId, oldTitle) {
            document.getElementById('modal_clone_id').value = cbtId;
            document.getElementById('modal_paper_title').value = "[CLONE] " + oldTitle;
            document.getElementById('clone-cbt-modal').classList.remove('hidden');
        }
        
        function closeCloneModal() {
            document.getElementById('clone-cbt-modal').classList.add('hidden');
        }
        </script>

    <?php elseif ($action === 'view_cbt') : 
        $cbt_id = (int)$_GET['cbt_id'];
        $stmtC = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
        $stmtC->execute([$school_id, $cbt_id]);
        $cbt = $stmtC->fetch();

        if (!$cbt) {
            echo "<div class='p-4 bg-red-500/10 text-red-400 border border-red-500/20 rounded-xl'>Exam schedule not found.</div>";
        } else {
            // Fetch student submissions
            $stmtSubmissions = $pdo->prepare("SELECT sub.*, stu.firstname, stu.lastname FROM sm_cbt_submitted sub JOIN sm_students stu ON sub.admission_number = stu.admission_number WHERE sub.school_id_number = ? AND sub.cbt_id = ? ORDER BY sub.date_submitted DESC");
            $stmtSubmissions->execute([$school_id, $cbt_id]);
            $submissions = $stmtSubmissions->fetchAll();
        ?>
            <div class="space-y-6">
                <div class="flex items-center gap-3 bg-white/5 border border-white/10 p-6 rounded-2xl">
                    <a href="?page=evaluation&action=cbt" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
                    <div>
                        <h3 class="text-xl font-bold text-indigo-300">Exam Results: <?php echo htmlspecialchars($cbt['paper_title']); ?></h3>
                        <p class="text-xs text-gray-400">Class: <?php echo htmlspecialchars($cbt['numeric_class_name']); ?> | Date: <?php echo htmlspecialchars($cbt['exam_date']); ?></p>
                    </div>
                </div>

                <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl">
                    <h4 class="text-lg font-bold text-white mb-4">Student Submissions</h4>
                    <?php if (empty($submissions)) : ?>
                        <div class="text-center py-8 text-gray-400 text-sm">No student submissions found.</div>
                    <?php else : ?>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[800px] text-left text-sm text-gray-300">
                                <thead>
                                    <tr class="border-b border-white/10 text-gray-400 text-xs uppercase">
                                        <th class="py-3 px-4">Admission #</th>
                                        <th class="py-3 px-4">Student Name</th>
                                        <th class="py-3 px-4">Raw Score</th>
                                        <th class="py-3 px-4">Percentage</th>
                                        <th class="py-3 px-4">Date Submitted</th>
                                        <th class="py-3 px-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <?php foreach ($submissions as $sub) : 
                                        $pct = $cbt['exam_questions'] > 0 ? round(($sub['score'] / $cbt['exam_questions']) * 100, 2) : 0;
                                    ?>
                                        <tr class="hover:bg-white/5 transition">
                                            <td class="py-3.5 px-4 font-mono text-xs text-gray-300"><?php echo htmlspecialchars($sub['admission_number']); ?></td>
                                            <td class="py-3.5 px-4 font-semibold text-white"><?php echo htmlspecialchars($sub['lastname'] . ' ' . $sub['firstname']); ?></td>
                                            <td class="py-3.5 px-4 text-green-400 font-bold"><?php echo htmlspecialchars($sub['score']); ?> / <?php echo htmlspecialchars($cbt['exam_questions']); ?></td>
                                            <td class="py-3.5 px-4 font-semibold"><?php echo $pct; ?>%</td>
                                            <td class="py-3.5 px-4 text-xs text-gray-400"><?php echo htmlspecialchars($sub['date_submitted']); ?></td>
                                            <td class="py-3.5 px-4 text-right">
                                                <form method="post" onsubmit="return confirm('Rescheduling this student will completely clear their exam score and start log, allowing them to retake the test. Proceed?');" class="inline">
                                                    <input type="hidden" name="cbt_id" value="<?php echo $cbt_id; ?>" />
                                                    <input type="hidden" name="admission_number" value="<?php echo htmlspecialchars($sub['admission_number']); ?>" />
                                                    <button type="submit" name="reschedule_student" class="px-2.5 py-1.5 bg-amber-500/10 border border-amber-500/20 hover:bg-amber-500/20 text-amber-400 rounded-lg text-xs font-semibold transition">
                                                        🔄 Reschedule / Retake
                                                    </button>
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
        <?php } ?>

    <?php elseif ($action === 'take_cbt') : 
        $cbt_id = (int)$_GET['cbt_id'];
        $stmtC = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
        $stmtC->execute([$school_id, $cbt_id]);
        $cbt = $stmtC->fetch();

        if (!$cbt || ($user_role === 'student' && ($student_info['current_class'] !== $cbt['numeric_class_name'] || $cbt['is_approved'] != 1))) {
            echo "<div class='p-4 bg-red-500/10 text-red-400 border border-red-500/20 rounded-xl'>Scheduled exam not found or unauthorized.</div>";
        } else {
            // Check if already submitted
            $stmtSubCheck = $pdo->prepare("SELECT id FROM sm_cbt_submitted WHERE school_id_number = ? AND cbt_id = ? AND admission_number = ? LIMIT 1");
            $stmtSubCheck->execute([$school_id, $cbt_id, $student_info['admission_number']]);
            if ($stmtSubCheck->fetchColumn()) {
                echo "<script>window.location.href = '?page=evaluation&action=cbt';</script>";
                exit;
            }
        ?>
            <!-- Dynamic Fullscreen JAMB / WAEC Simulation Panel -->
            <div id="cbt-fullscreen-wrapper" class="fixed inset-0 z-[9999] bg-[#0b0f19] text-gray-200 flex flex-col font-sans overflow-hidden select-none">
                <!-- Topbar Header -->
                <header class="h-16 bg-[#111827] border-b border-white/10 flex items-center justify-between px-8">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">🎓</span>
                        <div>
                            <h2 class="font-bold text-white text-sm md:text-base leading-tight uppercase"><?php echo htmlspecialchars($_SESSION['impersonated_school_name'] ?? 'SchoolApp Center'); ?></h2>
                            <p class="text-[10px] text-indigo-400 uppercase font-semibold font-mono tracking-wider"><?php echo htmlspecialchars($cbt['paper_title']); ?> — CBT Engine</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right hidden sm:block">
                            <h4 class="text-xs font-bold text-white"><?php echo htmlspecialchars($student_info['lastname'] . ' ' . $student_info['firstname']); ?></h4>
                            <p class="text-[10px] text-gray-400 font-mono">ID: <?php echo htmlspecialchars($student_info['admission_number']); ?></p>
                        </div>
                        <div class="w-10 h-10 rounded-full bg-indigo-600/30 border border-indigo-500/50 flex items-center justify-center font-bold text-indigo-200 text-sm">
                            <?php echo strtoupper(substr($student_info['firstname'], 0, 1)); ?>
                        </div>
                    </div>
                </header>

                <!-- Start Landing Screen -->
                <div id="cbt-start-screen" class="flex-1 flex items-center justify-center p-4">
                    <div class="max-w-md w-full bg-[#111827]/80 border border-white/10 p-8 rounded-2xl text-center space-y-6 shadow-2xl backdrop-blur-md">
                        <span class="text-5xl">📝</span>
                        <h3 class="text-xl font-bold text-white">Examination Instructions</h3>
                        <div class="text-xs text-gray-400 text-left space-y-2 font-sans bg-black/20 p-4 border border-white/5 rounded-xl">
                            <p>• Total Questions in this paper: <strong class="text-white"><?php echo $cbt['exam_questions']; ?></strong>.</p>
                            <?php if ($cbt['attempt_questions'] > 0): ?>
                                <p>• ⚠️ <strong>Important:</strong> You are required to attempt exactly <strong class="text-indigo-400"><?php echo $cbt['attempt_questions']; ?></strong> questions. The system will restrict you from answering more. You can clear a response to answer a different question.</p>
                            <?php endif; ?>
                            <p>• Carefully read each question and select the correct answer option.</p>
                            <p>• You can use keyboard hotkeys <strong class="text-white">A, B, C, D</strong> to select options.</p>
                            <p>• Press <strong class="text-white">P</strong> for Previous, <strong class="text-white">N</strong> for Next, <strong class="text-white">C</strong> to Clear Answer, and <strong class="text-white">S</strong> to Submit.</p>
                            <p>• The exam is timed. On countdown expiration, your progress is submitted automatically.</p>
                            <p>• Do not refresh or exit the page, as it may result in immediate auto-submission.</p>
                        </div>
                        <button onclick="startSimulatedExam()" class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold transition shadow-lg text-sm uppercase">
                            Proceed to Start Exam
                        </button>
                    </div>
                </div>

                <!-- Simulation Interface (Initially Hidden) -->
                <div id="cbt-sim-interface" class="flex-1 hidden flex flex-col md:flex-row overflow-hidden">
                    <!-- Left Side: Question Display -->
                    <div class="flex-1 flex flex-col p-6 md:p-10 justify-between overflow-y-auto space-y-6">
                        <div class="space-y-4">
                            <div class="flex justify-between items-center border-b border-white/10 pb-3">
                                <h4 class="text-indigo-400 text-sm font-mono font-bold">Question <span id="current-q-num">1</span> of <span id="total-q-num">10</span></h4>
                                <span class="px-2.5 py-1 bg-white/5 border border-white/10 rounded-lg text-xs font-mono" id="exam-mode-badge"><?php echo htmlspecialchars($cbt['cbt_type']); ?></span>
                            </div>
                            <div class="text-lg md:text-xl font-semibold text-white leading-relaxed whitespace-pre-wrap select-text" id="question-text-box">
                                Loading question text...
                            </div>
                        </div>

                        <!-- Answer Options -->
                        <div class="space-y-3" id="options-container">
                            <!-- Options generated dynamically -->
                        </div>

                        <!-- Nav buttons -->
                        <div class="flex justify-between items-center border-t border-white/5 pt-4">
                            <button onclick="prevQuestion()" class="px-5 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-xl text-xs font-bold transition flex items-center gap-2">
                                ⬅️ Previous [P]
                            </button>
                            <button onclick="clearAnswer()" class="px-5 py-2.5 bg-amber-600/10 hover:bg-amber-600/20 border border-amber-500/20 text-amber-300 rounded-xl text-xs font-bold transition flex items-center gap-2">
                                🧹 Clear Answer [C]
                            </button>
                            <button onclick="nextQuestion()" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-2">
                                Next [N] ➡️
                            </button>
                        </div>
                    </div>

                    <!-- Right Side: Sidebar Navigation Palette -->
                    <div class="w-full md:w-80 bg-[#111827]/50 border-t md:border-t-0 md:border-l border-white/10 p-6 flex flex-col justify-between gap-6 overflow-y-auto">
                        <div class="space-y-6">
                            <!-- Timer Widget -->
                            <div class="bg-black/20 border border-white/5 rounded-2xl p-5 text-center">
                                <span class="text-[10px] text-gray-400 uppercase tracking-widest block mb-1">Time Remaining</span>
                                <div class="text-3xl font-mono font-bold text-green-400 tracking-wider transition-colors" id="timer-box">--:--:--</div>
                            </div>

                            <!-- Question Grid Palette -->
                            <div>
                                <span class="text-xs text-gray-400 font-bold block mb-3 uppercase tracking-wider">Navigation Grid</span>
                                <div class="grid grid-cols-5 gap-2" id="grid-palette">
                                    <!-- Buttons generated dynamically -->
                                </div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="text-[10px] text-gray-500 font-mono space-y-1">
                                <div>⌨️ Keyboard Guide:</div>
                                <div>• [A, B, C, D] — Select Option</div>
                                <div>• [P] / [N] — Prev / Next</div>
                                <div>• [C] — Clear Answer</div>
                                <div>• [S] — Submit Examination</div>
                            </div>
                            <button onclick="confirmCbtSubmission()" class="w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl font-bold transition shadow-lg text-xs uppercase tracking-wider">
                                🛑 Submit Exam
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Client Script Engine -->
            <script>
                const cbtId = <?php echo $cbt_id; ?>;
                const totalQuestions = <?php echo $cbt['exam_questions']; ?>;
                const examDuration = <?php echo $cbt['exam_duration']; ?>; // in minutes
                const rawJson = <?php echo $cbt['exam_json']; ?>;
                const attemptQuestions = <?php echo (int)$cbt['attempt_questions']; ?>;
                
                let questionsData = [];
                let studentAnswers = {}; // question index (0-based) => selected option text base64
                let correctAnswers = {}; // question index => correct option text base64
                let shuffledOptions = []; // question index => array of option texts base64
                let activeQuestion = 0;
                let durationSeconds = examDuration * 60;
                let timerInterval = null;

                // Decode base64 helper
                function decodeB64(str) {
                    try {
                        return decodeURIComponent(escape(atob(str)));
                    } catch(e) {
                        return atob(str); // fallback
                    }
                }

                // Shuffler helper
                function shuffle(array) {
                    let currentIndex = array.length, randomIndex;
                    while (currentIndex !== 0) {
                        randomIndex = Math.floor(Math.random() * currentIndex);
                        currentIndex--;
                        [array[currentIndex], array[randomIndex]] = [array[randomIndex], array[currentIndex]];
                    }
                    return array;
                }

                // Initializer
                if (rawJson && rawJson.quiz) {
                    questionsData = rawJson.quiz;
                    questionsData.forEach((q, idx) => {
                        correctAnswers[idx] = q.answers.correct;
                        studentAnswers[idx] = null;
                        
                        // Shuffle answer options
                        const allOpts = [q.answers.correct, ...q.answers.wrong];
                        shuffledOptions[idx] = shuffle(allOpts);
                    });
                }

                function startSimulatedExam() {
                    // Start start log
                    const formData = new FormData();
                    formData.append('student_start_cbt', '1');
                    formData.append('cbt_id', cbtId);
                    
                    fetch('?page=evaluation&action=cbt&ajax=1', {
                        method: 'POST',
                        body: formData
                    }).then(() => {
                        document.getElementById('cbt-start-screen').classList.add('hidden');
                        document.getElementById('cbt-sim-interface').classList.remove('hidden');
                        
                        // Start Timer
                        startCountdown();
                        
                        // Render UI
                        renderQuestionGrid();
                        renderQuestion(0);
                        
                        // Key listener
                        window.addEventListener('keydown', handleKeyPress);
                    });
                }

                function renderQuestionGrid() {
                    const grid = document.getElementById('grid-palette');
                    grid.innerHTML = '';
                    
                    for (let i = 0; i < questionsData.length; i++) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = `w-10 h-10 rounded-lg text-xs font-mono font-bold transition flex items-center justify-center border ${
                            studentAnswers[i] !== null 
                                ? 'bg-indigo-600/30 text-indigo-300 border-indigo-500/50' 
                                : 'bg-white/5 text-gray-400 border-white/10 hover:bg-white/10'
                        }`;
                        if (i === activeQuestion) {
                            btn.className += ' ring-2 ring-indigo-500 ring-offset-2 ring-offset-[#0b0f19]';
                        }
                        btn.innerText = i + 1;
                        btn.onclick = () => renderQuestion(i);
                        grid.appendChild(btn);
                    }
                }

                function renderQuestion(index) {
                    if (index < 0 || index >= questionsData.length) return;
                    activeQuestion = index;

                    // Update header/grid
                    document.getElementById('current-q-num').innerText = index + 1;
                    document.getElementById('total-q-num').innerText = questionsData.length;
                    renderQuestionGrid();

                    const q = questionsData[index];
                    document.getElementById('question-text-box').innerHTML = decodeB64(q.question).replace(/\n/g, '<br>');

                    const optionsBox = document.getElementById('options-container');
                    optionsBox.innerHTML = '';

                    const alphabet = ['A', 'B', 'C', 'D'];
                    shuffledOptions[index].forEach((opt, oIdx) => {
                        const optText = decodeB64(opt);
                        const isChecked = studentAnswers[index] === opt;

                        const div = document.createElement('div');
                        div.className = `p-4 rounded-xl border transition cursor-pointer flex items-center gap-4 ${
                            isChecked 
                                ? 'bg-indigo-600/10 border-indigo-500 text-white' 
                                : 'bg-white/5 border-white/10 hover:bg-white/10 text-gray-300'
                        }`;
                        div.onclick = () => selectOpt(index, opt);

                        div.innerHTML = `
                            <span class="w-8 h-8 rounded-lg ${isChecked ? 'bg-indigo-600 text-white' : 'bg-black/40 text-gray-400'} border border-white/10 flex items-center justify-center font-bold text-xs font-mono">${alphabet[oIdx]}</span>
                            <span class="text-sm font-semibold select-text">${optText}</span>
                        `;
                        optionsBox.appendChild(div);
                    });
                }

                function selectOpt(qIdx, optVal) {
                    if (attemptQuestions > 0 && studentAnswers[qIdx] === null) {
                        let answeredCount = 0;
                        for (let k = 0; k < questionsData.length; k++) {
                            if (studentAnswers[k] !== null) answeredCount++;
                        }
                        if (answeredCount >= attemptQuestions) {
                            alert(`You have reached the maximum attempt limit of ${attemptQuestions} questions. Please clear an answer from another question to answer this one.`);
                            return;
                        }
                    }
                    studentAnswers[qIdx] = optVal;
                    renderQuestion(qIdx);
                }

                function clearAnswer() {
                    studentAnswers[activeQuestion] = null;
                    renderQuestion(activeQuestion);
                }

                function prevQuestion() {
                    if (activeQuestion > 0) {
                        renderQuestion(activeQuestion - 1);
                    }
                }

                function nextQuestion() {
                    if (activeQuestion < questionsData.length - 1) {
                        renderQuestion(activeQuestion + 1);
                    }
                }

                function handleKeyPress(e) {
                    const key = e.key.toLowerCase();
                    if (key === 'a') selectKeyOption(0);
                    else if (key === 'b') selectKeyOption(1);
                    else if (key === 'c') selectKeyOption(2);
                    else if (key === 'd') selectKeyOption(3);
                    else if (key === 'p') prevQuestion();
                    else if (key === 'n') nextQuestion();
                    else if (key === 'c') clearAnswer();
                    else if (key === 's') confirmCbtSubmission();
                }

                function selectKeyOption(optIdx) {
                    const opts = shuffledOptions[activeQuestion];
                    if (opts && opts[optIdx]) {
                        selectOpt(activeQuestion, opts[optIdx]);
                    }
                }

                function startCountdown() {
                    const timerBox = document.getElementById('timer-box');
                    
                    timerInterval = setInterval(() => {
                        durationSeconds--;
                        if (durationSeconds <= 0) {
                            clearInterval(timerInterval);
                            timerBox.innerText = '00:00:00';
                            timerBox.className = 'text-3xl font-mono font-bold text-red-500';
                            alert('Time limit reached! Submitting exam automatically.');
                            submitCbtExamSilently();
                        } else {
                            const hrs = Math.floor(durationSeconds / 3600);
                            const mins = Math.floor((durationSeconds % 3600) / 60);
                            const secs = durationSeconds % 60;
                            
                            timerBox.innerText = `${String(hrs).padStart(2,'0')}:${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
                            
                            if (durationSeconds <= 300) {
                                timerBox.className = 'text-3xl font-mono font-bold text-red-500 animate-pulse';
                            } else {
                                timerBox.className = 'text-3xl font-mono font-bold text-green-400';
                            }
                        }
                    }, 1000);
                }

                function confirmCbtSubmission() {
                    let answered = 0;
                    for (let i = 0; i < questionsData.length; i++) {
                        if (studentAnswers[i] !== null) answered++;
                    }

                    const denom = attemptQuestions > 0 ? attemptQuestions : questionsData.length;
                    const confirmSubmit = confirm(`Submit examination?\n\nAnswered: ${answered} / ${denom}\n\nClick OK to record your final score. This action cannot be reversed.`);
                    if (confirmSubmit) {
                        submitCbtExamSilently();
                    }
                }

                function submitCbtExamSilently() {
                    clearInterval(timerInterval);
                    window.removeEventListener('keydown', handleKeyPress);

                    let score = 0;
                    for (let i = 0; i < questionsData.length; i++) {
                        if (studentAnswers[i] === correctAnswers[i]) {
                            score++;
                        }
                    }

                    const answersPayload = JSON.stringify(studentAnswers);
                    const formData = new FormData();
                    formData.append('student_submit_cbt', '1');
                    formData.append('cbt_id', cbtId);
                    formData.append('score', score);
                    formData.append('answers', answersPayload);

                    fetch('?page=evaluation&action=cbt&ajax=1', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch(e) {
                            console.error("Raw response from server:", text);
                            throw new Error("Invalid response from server: " + text.substring(0, 150));
                        }
                    }))
                    .then(data => {
                        if (data.status === 'success') {
                            // Close fullscreen interface
                            const wrapper = document.getElementById('cbt-fullscreen-wrapper');
                            const denom = attemptQuestions > 0 ? attemptQuestions : questionsData.length;
                            wrapper.innerHTML = `
                                <div class="flex-1 flex flex-col items-center justify-center p-8 text-center space-y-6 bg-[#0b0f19]">
                                    <span class="text-6xl animate-bounce">🏆</span>
                                    <h2 class="text-2xl font-bold text-white">Examination Completed</h2>
                                    <p class="text-sm text-gray-400 max-w-sm">Your answers have been processed. Your score has been entered in the school record database.</p>
                                    <div class="text-lg font-mono font-bold text-green-400 bg-white/5 border border-white/10 px-6 py-3 rounded-xl">
                                        Raw Score: ${score} / ${denom}
                                    </div>
                                    <button onclick="window.location.href='?page=evaluation&action=cbt'" class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow-lg">
                                        Return to Dashboard
                                    </button>
                                </div>
                            `;
                        } else {
                            alert('Submission Error: ' + (data.message || 'Unknown error occurred.'));
                        }
                    })
                    .catch(err => {
                        console.error("Submission error details:", err);
                        alert('Could not submit result. Please check internet connection and try again. (' + err.message + ')');
                    });
                }
            </script>
        <?php } ?>
    <?php endif; ?>


<?php if (!isset($_GET['print'])): ?>

    <?php endif; ?>

    <?php
    // ── ─────────────────────────────────────────────────────────────────
    // ENTER MARKS — Single student score entry
    // ─────────────────────────────────────────────────────────────────────
    if ($action === 'enter_marks' && ($user_role === 'school_admin' || $user_role === 'teacher')) :
        // Handle save
        $marks_msg = '';
        if (isset($_POST['save_marks'])) {
            $adm   = trim($_POST['admission_number']);
            $cls   = trim($_POST['class_code']);
            $sess  = trim($_POST['session']);
            $term  = trim($_POST['term_id']);
            foreach ($_POST['subject'] as $sub_code => $scores) {
                $f1   = isset($scores['first_ca'])  ? (int)$scores['first_ca']  : null;
                $f2   = isset($scores['second_ca']) ? (int)$scores['second_ca'] : null;
                $f3   = isset($scores['third_ca'])  ? (int)$scores['third_ca']  : null;
                $exam = isset($scores['exam'])       ? (int)$scores['exam']      : null;
                // Upsert
                $stmtCheck = $pdo->prepare("SELECT id FROM sm_results WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=? AND subject_code=?");
                $stmtCheck->execute([$school_id, $adm, $cls, $sess, $term, $sub_code]);
                $existRow = $stmtCheck->fetch();
                if ($existRow) {
                    $pdo->prepare("UPDATE sm_results SET first_ca=?, second_ca=?, third_ca=?, exam=? WHERE id=? AND school_id_number=?")
                        ->execute([$f1, $f2, $f3, $exam, $existRow['id'], $school_id]);
                } else {
                    $pdo->prepare("INSERT INTO sm_results (school_id_number, admission_number, numeric_class_name, session, term_id_number, subject_code, first_ca, second_ca, third_ca, exam) VALUES (?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$school_id, $adm, $cls, $sess, $term, $sub_code, $f1, $f2, $f3, $exam]);
                }
            }
            // Save remark
            if (isset($_POST['teacher_remark'])) {
                $tremark = trim($_POST['teacher_remark']);
                $stmtRem = $pdo->prepare("SELECT id FROM sm_result_remarks WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=?");
                $stmtRem->execute([$school_id, $adm, $cls, $sess, $term]);
                if ($stmtRem->fetch()) {
                    $pdo->prepare("UPDATE sm_result_remarks SET teacher_remark=? WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=?")
                        ->execute([$tremark, $school_id, $adm, $cls, $sess, $term]);
                } else {
                    $pdo->prepare("INSERT INTO sm_result_remarks (school_id_number, admission_number, numeric_class_name, session, term_id_number, teacher_remark) VALUES (?,?,?,?,?,?)")
                        ->execute([$school_id, $adm, $cls, $sess, $term, $tremark]);
                }
            }
            $marks_msg = "Marks saved successfully!";
        }

        // Fetch selectors
        $cls_list   = $pdo->prepare("SELECT * FROM sm_classes WHERE school_id_number = ?"); $cls_list->execute([$school_id]); $cls_list = $cls_list->fetchAll();
        $sess_list  = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC"); $sess_list->execute([$school_id]); $sess_list = $sess_list->fetchAll();
        $term_list  = $pdo->prepare("SELECT * FROM sm_terms WHERE school_id_number = ? ORDER BY id"); $term_list->execute([$school_id]); $term_list = $term_list->fetchAll();

        $sel_adm  = $_GET['adm'] ?? '';
        $sel_cls  = $_GET['cls'] ?? '';
        $sel_sess = $_GET['sess'] ?? '';
        $sel_term = $_GET['term'] ?? '';

        $student_data = null;
        $subs_data    = [];
        $existing_marks = [];

        if ($sel_adm && $sel_cls && $sel_sess && $sel_term) {
            $stmtStu = $pdo->prepare("SELECT * FROM sm_students WHERE school_id_number=? AND admission_number=? LIMIT 1");
            $stmtStu->execute([$school_id, $sel_adm]);
            $student_data = $stmtStu->fetch();

            $stmtSubs = $pdo->prepare("SELECT * FROM sm_subjects WHERE school_id_number=? AND numeric_class_name=? ORDER BY subject_name");
            $stmtSubs->execute([$school_id, $sel_cls]);
            $subs_data = $stmtSubs->fetchAll();

            $stmtExist = $pdo->prepare("SELECT * FROM sm_results WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=?");
            $stmtExist->execute([$school_id, $sel_adm, $sel_cls, $sel_sess, $sel_term]);
            foreach ($stmtExist->fetchAll() as $row) {
                $existing_marks[$row['subject_code']] = $row;
            }
        }
    ?>
    <div class="space-y-6">
        <div class="flex items-center gap-3">
            <a href="?page=evaluation&action=list" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
            <h3 class="text-xl font-bold text-blue-300">✏️ Enter Student Marks</h3>
        </div>

        <?php if ($marks_msg) : ?>
            <div class="p-3 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm"><?php echo htmlspecialchars($marks_msg); ?></div>
        <?php endif; ?>

        <!-- Selector Form -->
        <form method="get" class="bg-white/5 border border-white/10 rounded-2xl p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
            <input type="hidden" name="page" value="evaluation" />
            <input type="hidden" name="action" value="enter_marks" />
            <div>
                <label class="block text-xs text-gray-400 mb-1">Admission Number</label>
                <input type="text" name="adm" value="<?php echo htmlspecialchars($sel_adm); ?>" placeholder="e.g. STU-001" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500" />
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Class</label>
                <select name="cls" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— Select —</option>
                    <?php foreach ($cls_list as $c) : ?>
                        <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>" <?php echo $sel_cls === $c['numeric_class_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Session</label>
                <select name="sess" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— Select —</option>
                    <?php foreach ($sess_list as $s) : ?>
                        <option value="<?php echo htmlspecialchars($s['session_name']); ?>" <?php echo $sel_sess === $s['session_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Term</label>
                <select name="term" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— Select —</option>
                    <?php foreach ($term_list as $t) : ?>
                        <option value="<?php echo htmlspecialchars($t['id_number']); ?>" <?php echo $sel_term === $t['id_number'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-span-2 md:col-span-4">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-sm transition">Load Student</button>
            </div>
        </form>

        <?php if ($student_data && !empty($subs_data)) : ?>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
            <div class="flex items-center gap-4 mb-5 pb-4 border-b border-white/10">
                <div class="w-12 h-12 bg-blue-600/20 rounded-xl flex items-center justify-center text-xl font-bold text-blue-400">
                    <?php echo strtoupper(substr($student_data['firstname'], 0, 1)); ?>
                </div>
                <div>
                    <h4 class="font-bold text-white"><?php echo htmlspecialchars($student_data['firstname'] . ' ' . $student_data['lastname']); ?></h4>
                    <p class="text-xs text-gray-400">Adm: <?php echo htmlspecialchars($student_data['admission_number']); ?> | Class: <?php echo htmlspecialchars($sel_cls); ?> | <?php echo htmlspecialchars($sel_sess); ?> | <?php echo htmlspecialchars($sel_term); ?></p>
                </div>
            </div>
            <form method="post" action="?page=evaluation&action=enter_marks&adm=<?php echo urlencode($sel_adm); ?>&cls=<?php echo urlencode($sel_cls); ?>&sess=<?php echo urlencode($sel_sess); ?>&term=<?php echo urlencode($sel_term); ?>">
                <input type="hidden" name="admission_number" value="<?php echo htmlspecialchars($sel_adm); ?>" />
                <input type="hidden" name="class_code" value="<?php echo htmlspecialchars($sel_cls); ?>" />
                <input type="hidden" name="session" value="<?php echo htmlspecialchars($sel_sess); ?>" />
                <input type="hidden" name="term_id" value="<?php echo htmlspecialchars($sel_term); ?>" />
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px] text-sm">
                        <thead>
                            <tr class="border-b border-white/10 text-xs text-gray-400 uppercase">
                                <th class="py-3 text-left">Subject</th>
                                <th class="py-3 text-center w-20">1st CA<br><span class="text-[9px]">(/30)</span></th>
                                <th class="py-3 text-center w-20">2nd CA<br><span class="text-[9px]">(/20)</span></th>
                                <th class="py-3 text-center w-20">3rd CA<br><span class="text-[9px]">(/10)</span></th>
                                <th class="py-3 text-center w-20">Exam<br><span class="text-[9px]">(/40)</span></th>
                                <th class="py-3 text-center w-16">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                        <?php foreach ($subs_data as $sub) :
                            $em = $existing_marks[$sub['subject_code']] ?? [];
                            $t_f1   = $em['first_ca'] ?? '';
                            $t_f2   = $em['second_ca'] ?? '';
                            $t_f3   = $em['third_ca'] ?? '';
                            $t_exam = $em['exam'] ?? '';
                            $t_total = ($t_f1 !== '' ? (int)$t_f1 : 0) + ($t_f2 !== '' ? (int)$t_f2 : 0) + ($t_f3 !== '' ? (int)$t_f3 : 0) + ($t_exam !== '' ? (int)$t_exam : 0);
                        ?>
                        <tr>
                            <td class="py-2.5 text-white font-medium"><?php echo htmlspecialchars($sub['subject_name']); ?> <span class="text-xs text-gray-500">(<?php echo htmlspecialchars($sub['subject_code']); ?>)</span></td>
                            <td class="py-2.5 px-1"><input type="number" name="subject[<?php echo htmlspecialchars($sub['subject_code']); ?>][first_ca]" value="<?php echo htmlspecialchars($t_f1); ?>" min="0" max="100" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                            <td class="py-2.5 px-1"><input type="number" name="subject[<?php echo htmlspecialchars($sub['subject_code']); ?>][second_ca]" value="<?php echo htmlspecialchars($t_f2); ?>" min="0" max="100" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                            <td class="py-2.5 px-1"><input type="number" name="subject[<?php echo htmlspecialchars($sub['subject_code']); ?>][third_ca]" value="<?php echo htmlspecialchars($t_f3); ?>" min="0" max="100" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                            <td class="py-2.5 px-1"><input type="number" name="subject[<?php echo htmlspecialchars($sub['subject_code']); ?>][exam]" value="<?php echo htmlspecialchars($t_exam); ?>" min="0" max="100" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                            <td class="py-2.5 text-center font-bold text-blue-400"><?php echo ($t_total > 0 || $t_f1 !== '') ? $t_total : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-5">
                    <label class="block text-xs text-gray-400 mb-1">Teacher's Remark</label>
                    <textarea name="teacher_remark" rows="2" placeholder="e.g. A diligent student who consistently participates in class activities." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500"><?php
                        $stmtTR = $pdo->prepare("SELECT teacher_remark FROM sm_result_remarks WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=? LIMIT 1");
                        $stmtTR->execute([$school_id, $sel_adm, $sel_cls, $sel_sess, $sel_term]);
                        echo htmlspecialchars($stmtTR->fetchColumn() ?: '');
                    ?></textarea>
                </div>
                <div class="mt-4 flex gap-3">
                    <button type="submit" name="save_marks" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-sm transition shadow-lg">💾 Save Marks</button>
                    <a href="?page=evaluation&action=print_single&class=<?php echo urlencode($sel_cls); ?>&session=<?php echo urlencode($sel_sess); ?>&term=<?php echo urlencode($sel_term); ?>&admission_number=<?php echo urlencode($sel_adm); ?>&print=1" target="_blank" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold text-sm transition">📄 Print Report Card</a>
                </div>
            </form>
        </div>
        <?php elseif ($sel_adm) : ?>
            <div class="p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-xl text-sm">⚠️ Student not found or no subjects configured for this class. Please verify the admission number and ensure subjects are set up under Academics → Subjects.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // ── ─────────────────────────────────────────────────────────────────
    // CLASS RESULT GRID — Batch enter marks for all students in a class
    // ─────────────────────────────────────────────────────────────────────
    if ($action === 'result_grid' && ($user_role === 'school_admin' || $user_role === 'teacher')) :
        $grid_msg = '';
        $sel_cls  = $_GET['cls'] ?? '';
        $sel_sess = $_GET['sess'] ?? '';
        $sel_term = $_GET['term'] ?? '';
        $sel_sub  = $_GET['sub'] ?? '';

        // Handle bulk save
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grid_marks'])) {
            $cls   = trim($_POST['class_code']);
            $sess  = trim($_POST['session']);
            $term  = trim($_POST['term_id']);
            $sub   = trim($_POST['subject_code']);
            
            if (isset($_POST['student_scores'])) {
                foreach ($_POST['student_scores'] as $adm => $scores) {
                    $f1   = $scores['first_ca'] !== '' ? (int)$scores['first_ca'] : null;
                    $f2   = $scores['second_ca'] !== '' ? (int)$scores['second_ca'] : null;
                    $f3   = $scores['third_ca'] !== '' ? (int)$scores['third_ca'] : null;
                    $exam = $scores['exam'] !== '' ? (int)$scores['exam'] : null;

                    // Upsert
                    $stmtCheck = $pdo->prepare("SELECT id FROM sm_results WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=? AND subject_code=?");
                    $stmtCheck->execute([$school_id, $adm, $cls, $sess, $term, $sub]);
                    $existRow = $stmtCheck->fetch();
                    if ($existRow) {
                        $pdo->prepare("UPDATE sm_results SET first_ca=?, second_ca=?, third_ca=?, exam=? WHERE id=? AND school_id_number=?")
                            ->execute([$f1, $f2, $f3, $exam, $existRow['id'], $school_id]);
                    } else {
                        $pdo->prepare("INSERT INTO sm_results (school_id_number, admission_number, numeric_class_name, session, term_id_number, subject_code, first_ca, second_ca, third_ca, exam) VALUES (?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$school_id, $adm, $cls, $sess, $term, $sub, $f1, $f2, $f3, $exam]);
                    }
                }
                $grid_msg = "✅ Batch marks updated successfully!";
            }
        }

        // Fetch selectors
        $cls_list   = $pdo->prepare("SELECT * FROM sm_classes WHERE school_id_number = ?"); $cls_list->execute([$school_id]); $cls_list = $cls_list->fetchAll();
        $sess_list  = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC"); $sess_list->execute([$school_id]); $sess_list = $sess_list->fetchAll();
        $term_list  = $pdo->prepare("SELECT * FROM sm_terms WHERE school_id_number = ? ORDER BY id"); $term_list->execute([$school_id]); $term_list = $term_list->fetchAll();
        
        $subs_list = [];
        if ($sel_cls) {
            $stmtSubs = $pdo->prepare("SELECT * FROM sm_subjects WHERE school_id_number=? AND numeric_class_name=? ORDER BY subject_name");
            $stmtSubs->execute([$school_id, $sel_cls]);
            $subs_list = $stmtSubs->fetchAll();
        }

        $students = [];
        $existing_marks = [];
        if ($sel_cls && $sel_sess && $sel_term && $sel_sub) {
            // Get all students in this class and session
            $stmtStus = $pdo->prepare("SELECT admission_number, firstname, lastname FROM sm_students WHERE school_id_number=? AND current_class=? AND session=? ORDER BY firstname, lastname");
            $stmtStus->execute([$school_id, $sel_cls, $sel_sess]);
            $students = $stmtStus->fetchAll();

            // Get existing marks
            $stmtExist = $pdo->prepare("SELECT admission_number, first_ca, second_ca, third_ca, exam FROM sm_results WHERE school_id_number=? AND numeric_class_name=? AND session=? AND term_id_number=? AND subject_code=?");
            $stmtExist->execute([$school_id, $sel_cls, $sel_sess, $sel_term, $sel_sub]);
            foreach ($stmtExist->fetchAll() as $row) {
                $existing_marks[$row['admission_number']] = $row;
            }
        }
    ?>
    <div class="space-y-6">
        <div class="flex items-center gap-3">
            <a href="?page=evaluation&action=list" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
            <h3 class="text-xl font-bold text-indigo-300">📊 Class Result Grid (Batch Entry)</h3>
        </div>

        <?php if ($grid_msg) : ?>
            <div class="p-3 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm"><?php echo htmlspecialchars($grid_msg); ?></div>
        <?php endif; ?>

        <!-- Search Form -->
        <form method="get" class="bg-white/5 border border-white/10 rounded-2xl p-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4">
            <input type="hidden" name="page" value="evaluation" />
            <input type="hidden" name="action" value="result_grid" />
            <div>
                <label class="block text-xs text-gray-400 mb-1">Class</label>
                <select name="cls" onchange="this.form.submit()" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— Select —</option>
                    <?php foreach ($cls_list as $c) : ?>
                        <option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>" <?php echo $sel_cls === $c['numeric_class_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Session</label>
                <select name="sess" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— Select —</option>
                    <?php foreach ($sess_list as $s) : ?>
                        <option value="<?php echo htmlspecialchars($s['session_name']); ?>" <?php echo $sel_sess === $s['session_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['session_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Term</label>
                <select name="term" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— Select —</option>
                    <?php foreach ($term_list as $t) : ?>
                        <option value="<?php echo htmlspecialchars($t['id_number']); ?>" <?php echo $sel_term === $t['id_number'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['term_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1">Subject</label>
                <select name="sub" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <option value="">— Select —</option>
                    <?php foreach ($subs_list as $sub) : ?>
                        <option value="<?php echo htmlspecialchars($sub['subject_code']); ?>" <?php echo $sel_sub === $sub['subject_code'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub['subject_name']); ?> (<?php echo htmlspecialchars($sub['subject_code']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-span-2 md:col-span-1 flex items-end">
                <button type="submit" class="w-full py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-sm transition">Load Grid</button>
            </div>
        </form>

        <?php if ($sel_cls && $sel_sess && $sel_term && $sel_sub) : ?>
            <?php if (empty($students)) : ?>
                <div class="p-8 text-center bg-white/5 border border-white/10 rounded-2xl text-gray-400 text-sm">No students registered in this class/session.</div>
            <?php else : ?>
                <form method="post" action="?page=evaluation&action=result_grid&cls=<?php echo urlencode($sel_cls); ?>&sess=<?php echo urlencode($sel_sess); ?>&term=<?php echo urlencode($sel_term); ?>&sub=<?php echo urlencode($sel_sub); ?>" class="bg-white/5 border border-white/10 rounded-2xl p-6 space-y-4">
                    <input type="hidden" name="class_code" value="<?php echo htmlspecialchars($sel_cls); ?>" />
                    <input type="hidden" name="session" value="<?php echo htmlspecialchars($sel_sess); ?>" />
                    <input type="hidden" name="term_id" value="<?php echo htmlspecialchars($sel_term); ?>" />
                    <input type="hidden" name="subject_code" value="<?php echo htmlspecialchars($sel_sub); ?>" />

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[700px] text-sm">
                            <thead>
                                <tr class="border-b border-white/10 text-xs text-gray-400 uppercase">
                                    <th class="py-3 text-left">Student</th>
                                    <th class="py-3 text-center w-24">1st CA (/30)</th>
                                    <th class="py-3 text-center w-24">2nd CA (/20)</th>
                                    <th class="py-3 text-center w-24">3rd CA (/10)</th>
                                    <th class="py-3 text-center w-24">Exam (/40)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                            <?php foreach ($students as $stu) :
                                $adm = $stu['admission_number'];
                                $em  = $existing_marks[$adm] ?? [];
                                $f1  = $em['first_ca'] ?? '';
                                $f2  = $em['second_ca'] ?? '';
                                $f3  = $em['third_ca'] ?? '';
                                $exam = $em['exam'] ?? '';
                            ?>
                                <tr>
                                    <td class="py-3 text-white font-medium">
                                        <?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname']); ?>
                                        <span class="text-xs text-gray-500 block">Adm: <?php echo htmlspecialchars($adm); ?></span>
                                    </td>
                                    <td class="py-3 px-1"><input type="number" name="student_scores[<?php echo htmlspecialchars($adm); ?>][first_ca]" value="<?php echo htmlspecialchars($f1); ?>" min="0" max="30" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                                    <td class="py-3 px-1"><input type="number" name="student_scores[<?php echo htmlspecialchars($adm); ?>][second_ca]" value="<?php echo htmlspecialchars($f2); ?>" min="0" max="20" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                                    <td class="py-3 px-1"><input type="number" name="student_scores[<?php echo htmlspecialchars($adm); ?>][third_ca]" value="<?php echo htmlspecialchars($f3); ?>" min="0" max="10" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                                    <td class="py-3 px-1"><input type="number" name="student_scores[<?php echo htmlspecialchars($adm); ?>][exam]" value="<?php echo htmlspecialchars($exam); ?>" min="0" max="40" class="w-full bg-white/5 border border-white/10 rounded px-2 py-1.5 text-white text-xs text-center focus:outline-none focus:border-blue-500" placeholder="—" /></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pt-4 border-t border-white/5">
                        <button type="submit" name="save_grid_marks" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-sm transition shadow-lg">💾 Save Batch Marks</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // ── ─────────────────────────────────────────────────────────────────
    // BULK CSV UPLOAD
    // ─────────────────────────────────────────────────────────────────────
    if ($action === 'bulk_csv' && ($user_role === 'school_admin' || $user_role === 'teacher')) :
        $csv_msg = ''; $csv_type = 'green'; $csv_count = 0;
        if (isset($_POST['upload_csv'])) {
            $cls   = trim($_POST['class_code']);
            $sess  = trim($_POST['session']);
            $term  = trim($_POST['term_id']);

            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === 0) {
                $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
                $header = fgetcsv($handle); // Skip header row
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 6) continue;
                    [$adm, $sub_code, $f1, $f2, $f3, $exam] = array_map('trim', $row);
                    // Upsert
                    $stmtCheck = $pdo->prepare("SELECT id FROM sm_results WHERE school_id_number=? AND admission_number=? AND numeric_class_name=? AND session=? AND term_id_number=? AND subject_code=?");
                    $stmtCheck->execute([$school_id, $adm, $cls, $sess, $term, $sub_code]);
                    $existRow = $stmtCheck->fetch();
                    if ($existRow) {
                        $pdo->prepare("UPDATE sm_results SET first_ca=?, second_ca=?, third_ca=?, exam=? WHERE id=?")->execute([$f1 ?: null, $f2 ?: null, $f3 ?: null, $exam ?: null, $existRow['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO sm_results (school_id_number, admission_number, numeric_class_name, session, term_id_number, subject_code, first_ca, second_ca, third_ca, exam) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$school_id, $adm, $cls, $sess, $term, $sub_code, $f1 ?: null, $f2 ?: null, $f3 ?: null, $exam ?: null]);
                    }
                    $csv_count++;
                }
                fclose($handle);
                $csv_msg = "✅ {$csv_count} result records imported successfully!";
            } else {
                $csv_msg = "❌ Upload failed. Please select a valid CSV file.";
                $csv_type = 'red';
            }
        }

        $cls_list  = $pdo->prepare("SELECT * FROM sm_classes WHERE school_id_number = ?"); $cls_list->execute([$school_id]); $cls_list = $cls_list->fetchAll();
        $sess_list = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ?"); $sess_list->execute([$school_id]); $sess_list = $sess_list->fetchAll();
        $term_list = $pdo->prepare("SELECT * FROM sm_terms WHERE school_id_number = ?"); $term_list->execute([$school_id]); $term_list = $term_list->fetchAll();
    ?>
    <div class="space-y-6">
        <div class="flex items-center gap-3">
            <a href="?page=evaluation&action=list" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
            <h3 class="text-xl font-bold text-emerald-300">📂 Bulk CSV Mark Upload</h3>
        </div>
        <?php if ($csv_msg) : ?>
            <div class="p-3 bg-<?php echo $csv_type; ?>-500/10 border border-<?php echo $csv_type; ?>-500/20 text-<?php echo $csv_type; ?>-400 rounded-xl text-sm"><?php echo $csv_msg; ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
                <h4 class="font-semibold text-emerald-300 mb-4">Upload Marks File</h4>
                <form method="post" enctype="multipart/form-data" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Class</label>
                            <select name="class_code" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                                <?php foreach ($cls_list as $c) : ?><option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>"><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Session</label>
                            <select name="session" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                                <?php foreach ($sess_list as $s) : ?><option value="<?php echo htmlspecialchars($s['session_name']); ?>"><?php echo htmlspecialchars($s['session_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Term</label>
                            <select name="term_id" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                                <?php foreach ($term_list as $t) : ?><option value="<?php echo htmlspecialchars($t['id_number']); ?>"><?php echo htmlspecialchars($t['term_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="border-2 border-dashed border-white/20 rounded-xl p-8 text-center hover:border-emerald-500/50 transition">
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" class="hidden" />
                        <label for="csv_file" class="cursor-pointer">
                            <span class="text-4xl block mb-2">📤</span>
                            <p class="text-white font-semibold text-sm">Click to select CSV file</p>
                            <p class="text-xs text-gray-400 mt-1">Max 5MB. UTF-8 encoded CSV format.</p>
                        </label>
                    </div>
                    <button type="submit" name="upload_csv" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold text-sm transition">Import Marks</button>
                </form>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
                <h4 class="font-semibold text-blue-300 mb-4">📋 CSV Format Guide</h4>
                <p class="text-xs text-gray-400 mb-4">Your CSV file must follow this column order exactly. The header row is automatically skipped.</p>
                <div class="bg-black/30 rounded-xl p-4 font-mono text-xs overflow-x-auto mb-4">
                    <p class="text-emerald-400 mb-1"># Column order (include header row):</p>
                    <p class="text-white">admission_number, subject_code, first_ca, second_ca, third_ca, exam</p>
                    <hr class="border-white/10 my-2" />
                    <p class="text-gray-400"># Example rows:</p>
                    <p class="text-yellow-300">STU-001, MATH101, 28, 18, 9, 38</p>
                    <p class="text-yellow-300">STU-001, ENG101, 25, 16, 8, 35</p>
                    <p class="text-yellow-300">STU-002, MATH101, 22, 14, 7, 30</p>
                </div>
                <div class="space-y-1.5 text-xs">
                    <div class="flex gap-2"><span class="text-blue-400 font-bold">admission_number</span><span class="text-gray-400">— Student's unique admission ID</span></div>
                    <div class="flex gap-2"><span class="text-blue-400 font-bold">subject_code</span><span class="text-gray-400">— Subject code (uppercase, e.g. MATH101)</span></div>
                    <div class="flex gap-2"><span class="text-blue-400 font-bold">first_ca</span><span class="text-gray-400">— 1st Continuous Assessment score</span></div>
                    <div class="flex gap-2"><span class="text-blue-400 font-bold">second_ca</span><span class="text-gray-400">— 2nd CA score</span></div>
                    <div class="flex gap-2"><span class="text-blue-400 font-bold">third_ca</span><span class="text-gray-400">— 3rd CA score</span></div>
                    <div class="flex gap-2"><span class="text-blue-400 font-bold">exam</span><span class="text-gray-400">— End-of-term examination score</span></div>
                </div>
                <div class="mt-4 p-3 bg-amber-500/10 border border-amber-500/20 rounded-lg text-xs text-amber-300">
                    ⚠️ Blank scores are saved as NULL (not counted). Existing records for the same student/subject/term will be overwritten.
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // ── ─────────────────────────────────────────────────────────────────
    // EXAM HALLS
    // ─────────────────────────────────────────────────────────────────────
    if ($action === 'exam_halls' && ($user_role === 'school_admin' || $user_role === 'teacher')) :
        $hall_msg = '';
        if (isset($_POST['add_hall'])) {
            $hall_name = trim($_POST['hall_name']);
            $capacity  = (int)$_POST['hall_capacity'];
            $location  = trim($_POST['hall_location'] ?? '');
            $pdo->prepare("INSERT INTO sm_exam_halls (school_id_number, hall_name, capacity, location) VALUES (?,?,?,?)")->execute([$school_id, $hall_name, $capacity, $location]);
            $hall_msg = "Exam hall added!";
        }
        if (isset($_POST['delete_hall'])) {
            $pdo->prepare("DELETE FROM sm_exam_halls WHERE id=? AND school_id_number=?")->execute([(int)$_POST['hall_id'], $school_id]);
            $hall_msg = "Hall removed.";
        }
        $stmtHalls = $pdo->prepare("SELECT * FROM sm_exam_halls WHERE school_id_number=? ORDER BY id");
        $stmtHalls->execute([$school_id]);
        $halls = $stmtHalls->fetchAll();
    ?>
    <div class="space-y-6">
        <div class="flex items-center gap-3">
            <a href="?page=evaluation&action=list" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
            <h3 class="text-xl font-bold text-amber-300">🏛️ Exam Hall Management</h3>
        </div>
        <?php if ($hall_msg) : ?><div class="p-3 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm"><?php echo htmlspecialchars($hall_msg); ?></div><?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
                <h4 class="font-semibold text-amber-300 mb-4">Add Exam Hall</h4>
                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Hall Name</label>
                        <input type="text" name="hall_name" required placeholder="e.g. Block A Hall" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Seating Capacity</label>
                            <input type="number" name="hall_capacity" value="50" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">Location/Floor</label>
                            <input type="text" name="hall_location" placeholder="e.g. Ground Floor" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500" />
                        </div>
                    </div>
                    <button type="submit" name="add_hall" class="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-bold text-sm transition">Add Hall</button>
                </form>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
                <h4 class="font-semibold text-amber-300 mb-4">Registered Halls (<?php echo count($halls); ?>)</h4>
                <?php if (empty($halls)) : ?>
                    <p class="text-gray-400 text-sm">No exam halls registered yet.</p>
                <?php else : ?>
                    <div class="space-y-2.5">
                        <?php foreach ($halls as $hall) : ?>
                        <div class="flex justify-between items-center p-4 bg-white/5 border border-white/5 rounded-xl">
                            <div>
                                <p class="font-bold text-white"><?php echo htmlspecialchars($hall['hall_name']); ?></p>
                                <p class="text-xs text-gray-400">Capacity: <span class="text-amber-400"><?php echo htmlspecialchars($hall['capacity']); ?></span> seats <?php if ($hall['location']) echo '| ' . htmlspecialchars($hall['location']); ?></p>
                            </div>
                            <form method="post" onsubmit="return confirm('Remove this hall?');">
                                <input type="hidden" name="hall_id" value="<?php echo $hall['id']; ?>" />
                                <button type="submit" name="delete_hall" class="text-red-400 hover:text-red-300 text-xs transition">🗑️ Remove</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // ── ─────────────────────────────────────────────────────────────────
    // STUDENT MIGRATION / PROMOTION
    // ─────────────────────────────────────────────────────────────────────
    if ($action === 'migration' && $user_role === 'school_admin') :
        $mig_msg = ''; $mig_count = 0;
        if (isset($_POST['promote_students'])) {
            $from_cls   = trim($_POST['from_class']);
            $to_cls     = trim($_POST['to_class']);
            $from_sess  = trim($_POST['from_session']);
            $to_sess    = trim($_POST['to_session']);
            // Fetch students in from_class
            $stmtStuM = $pdo->prepare("SELECT admission_number FROM sm_students WHERE school_id_number=? AND current_class=? AND session=?");
            $stmtStuM->execute([$school_id, $from_cls, $from_sess]);
            $stuList = $stmtStuM->fetchAll(PDO::FETCH_COLUMN);
            foreach ($stuList as $adm) {
                $pdo->prepare("UPDATE sm_students SET current_class=?, session=? WHERE school_id_number=? AND admission_number=?")->execute([$to_cls, $to_sess, $school_id, $adm]);
                $mig_count++;
            }
            $mig_msg = "✅ {$mig_count} student(s) promoted from {$from_cls} → {$to_cls} in session {$to_sess}.";
        }

        $cls_list  = $pdo->prepare("SELECT * FROM sm_classes WHERE school_id_number=? ORDER BY id"); $cls_list->execute([$school_id]); $cls_list = $cls_list->fetchAll();
        $sess_list = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number=? ORDER BY id DESC"); $sess_list->execute([$school_id]); $sess_list = $sess_list->fetchAll();
    ?>
    <div class="space-y-6">
        <div class="flex items-center gap-3">
            <a href="?page=evaluation&action=list" class="text-gray-400 hover:text-white text-sm transition">← Back</a>
            <h3 class="text-xl font-bold text-purple-300">🚀 Student Promotion / Migration</h3>
        </div>
        <?php if ($mig_msg) : ?><div class="p-3 bg-green-500/10 border border-green-500/20 text-green-400 rounded-xl text-sm"><?php echo htmlspecialchars($mig_msg); ?></div><?php endif; ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
                <h4 class="font-semibold text-purple-300 mb-2">Promote Students to Next Class</h4>
                <p class="text-xs text-gray-400 mb-5">This will bulk update all students in the selected class to a new class and session. Useful for end-of-year promotions.</p>
                <form method="post" onsubmit="return confirm('Are you sure you want to promote all students in the selected class? This cannot be undone.');" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">From Class</label>
                            <select name="from_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                                <?php foreach ($cls_list as $c) : ?><option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>"><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">From Session</label>
                            <select name="from_session" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                                <?php foreach ($sess_list as $s) : ?><option value="<?php echo htmlspecialchars($s['session_name']); ?>"><?php echo htmlspecialchars($s['session_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">To Class</label>
                            <select name="to_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                                <?php foreach ($cls_list as $c) : ?><option value="<?php echo htmlspecialchars($c['numeric_class_name']); ?>"><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 mb-1">New Session</label>
                            <select name="to_session" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2.5 text-white text-sm focus:outline-none focus:border-blue-500">
                                <?php foreach ($sess_list as $s) : ?><option value="<?php echo htmlspecialchars($s['session_name']); ?>"><?php echo htmlspecialchars($s['session_name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="p-3 bg-red-500/10 border border-red-500/20 rounded-lg text-xs text-red-300">
                        ⚠️ <strong>Warning:</strong> This action permanently updates student records. Ensure you have already printed or saved all report cards for the current session before promoting.
                    </div>
                    <button type="submit" name="promote_students" class="w-full py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-bold text-sm transition">🚀 Promote All Students</button>
                </form>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6">
                <h4 class="font-semibold text-purple-300 mb-4">How Promotion Works</h4>
                <div class="space-y-3 text-sm text-gray-300">
                    <div class="flex gap-3 items-start">
                        <span class="w-6 h-6 flex-shrink-0 bg-purple-600/20 text-purple-400 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                        <p>Select the <strong class="text-white">source class</strong> (where students currently are) and the current session.</p>
                    </div>
                    <div class="flex gap-3 items-start">
                        <span class="w-6 h-6 flex-shrink-0 bg-purple-600/20 text-purple-400 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                        <p>Choose the <strong class="text-white">destination class</strong> (the new class they will be promoted to) and the new session year.</p>
                    </div>
                    <div class="flex gap-3 items-start">
                        <span class="w-6 h-6 flex-shrink-0 bg-purple-600/20 text-purple-400 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                        <p>Click Promote. All students in the source class will be moved automatically. Past <strong class="text-white">result records remain intact</strong> — only current_class and session are updated.</p>
                    </div>
                    <div class="flex gap-3 items-start">
                        <span class="w-6 h-6 flex-shrink-0 bg-purple-600/20 text-purple-400 rounded-full flex items-center justify-center text-xs font-bold">4</span>
                        <p>Students who did not pass can be individually re-assigned by going to <strong class="text-white">User Registry → Edit Student</strong>.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php elseif ($action === 'preview_past_paper'): ?>
        <?php
        $cbt_id = (int)$_GET['cbt_id'];
        $stmtC = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ?");
        $stmtC->execute([$school_id, $cbt_id]);
        $cbt = $stmtC->fetch();
        if (!$cbt) {
            echo "<div class='p-4 text-red-400'>CBT Not Found.</div>";
        } else {
            $questions = json_decode($cbt['exam_json'] ?: '[]', true);
            function decodeB64Preview($str) {
                return htmlspecialchars_decode(base64_decode($str));
            }
        ?>
            <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                <h3 class="text-xl font-bold text-white">Previewing: <?php echo htmlspecialchars($cbt['paper_title']); ?></h3>
                <a href="?page=evaluation&action=list" class="whitespace-nowrap px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg transition text-sm">⬅️ Back</a>
            </div>

            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md space-y-6">
                <?php if (empty($questions['quiz'])): ?>
                    <p class="text-gray-400">No questions found in this paper.</p>
                <?php else: ?>
                    <?php foreach ($questions['quiz'] as $index => $q): ?>
                        <div class="p-4 bg-black/20 rounded-lg border border-white/5">
                            <p class="text-white font-medium mb-3"><span class="text-indigo-400 font-bold"><?php echo $index + 1; ?>.</span> <?php echo decodeB64Preview($q['question']); ?></p>
                            
                            <ul class="space-y-2 text-sm text-gray-300">
                                <li class="text-green-400 font-bold">A (Correct). <?php echo decodeB64Preview($q['answers']['correct']); ?></li>
                                <li>B. <?php echo decodeB64Preview($q['answers']['wrong'][0] ?? ''); ?></li>
                                <li>C. <?php echo decodeB64Preview($q['answers']['wrong'][1] ?? ''); ?></li>
                                <li>D. <?php echo decodeB64Preview($q['answers']['wrong'][2] ?? ''); ?></li>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php } ?>
    <?php endif; ?>

</div>


<?php if (isset($_GET['print'])): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card Print Preview</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media screen {
            body {
                background-color: #f1f5f9;
                color: #1e293b;
                padding: 2rem 0;
            }
            .print-container {
                max-width: 21cm;
                margin: 0 auto;
                background-color: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 1rem;
                padding: 2.5rem;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            }
        }
        @media print {
            body {
                background: white !important;
                color: black !important;
            }
            .print-container {
                background: white !important;
                color: black !important;
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .no-print {
                display: none !important;
            }
            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body class="font-sans antialiased text-slate-800">
    <!-- Print controls -->
    <div class="no-print max-w-4xl mx-auto mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 bg-white border border-slate-200 rounded-xl p-4 shadow-sm text-slate-800">
        <span class="text-sm text-slate-600 font-semibold">📄 Print preview mode. Press Ctrl+P or click print button.</span>
        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
            <button onclick="window.print()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-bold shadow-md transition-all whitespace-nowrap">
                🖨️ Print Report Card
            </button>
            <button onclick="window.close()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-sm font-bold transition-all whitespace-nowrap">
                Close Window
            </button>
        </div>
    </div>
<?php endif; ?>

<?php
// ── 1. Action: Print Single Student Report Card ───────────────────────────
if ($action === 'print_single') {
    $admission_no = '';
    $class_code = '';
    $session = '';
    $term_id = '';
    
    if (isset($_GET['view'])) {
        $stmt = $pdo->prepare("SELECT * FROM sm_result_lists WHERE result_ref = ? LIMIT 1");
        $stmt->execute([$_GET['view']]);
        $ref_row = $stmt->fetch();
        if ($ref_row) {
            $admission_no = $ref_row['admission_number'];
            $class_code = $ref_row['numeric_class_name'];
            $session = $ref_row['session'];
            $term_id = $ref_row['term_id_number'];
            $school_id = $ref_row['school_id_number'];
        }
    } else {
        $admission_no = $_GET['admission_number'] ?? '';
        $class_code = $_GET['class'] ?? '';
        $session = $_GET['session'] ?? '';
        $term_id = $_GET['term'] ?? '';
    }

    if (empty($admission_no) || empty($class_code) || empty($session) || empty($term_id)) {
        echo '<div class="p-8 text-center text-red-400">⚠️ Error: Invalid result identifiers provided.</div>';
        if (isset($_GET['print'])) echo '</body></html>';
        return;
    }

    $stmtStu = $pdo->prepare("SELECT * FROM sm_students WHERE school_id_number = ? AND admission_number = ? LIMIT 1");
    $stmtStu->execute([$school_id, $admission_no]);
    $student = $stmtStu->fetch();
    
    if (!$student) {
        echo '<div class="p-8 text-center text-red-400">⚠️ Error: Student not found.</div>';
        if (isset($_GET['print'])) echo '</body></html>';
        return;
    }

    $payment_allowed = true;
    $unpaid_bills = [];
    if ($user_role === 'student' || $user_role === 'parent') {
        // Only enforce check if lock is turned on in global settings
        $stmtLockSetting = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = 'lock_print_on_fees_due' LIMIT 1");
        $stmtLockSetting->execute();
        $is_lock_enabled = $stmtLockSetting->fetchColumn();
        
        if ($is_lock_enabled === '1') {
            $fee_check = ResultHelper::checkPayment($pdo, $admission_no, $class_code, $session, $school_id);
            $payment_allowed = $fee_check['fee_status'];
            $unpaid_bills = $fee_check['fee_overdue'];
        }
    }

    if (!$payment_allowed) {
        ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto text-center mt-12 text-white">
            <span class="text-5xl block mb-4">🔒 Access Blocked</span>
            <h3 class="text-xl font-bold text-red-400 mb-2">Outstanding Fees Required</h3>
            <p class="text-sm text-gray-400 mb-6">You have outstanding bills that must be settled before you can view or print the academic report card.</p>
            
            <div class="text-left bg-black/20 border border-white/5 rounded-xl p-5 mb-6">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Unpaid Bills:</p>
                <ul class="list-disc pl-5 text-sm space-y-1 text-gray-300">
                    <?php foreach ($unpaid_bills as $bill): ?>
                        <li><?php echo htmlspecialchars($bill); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <a href="?page=billing&action=list" class="inline-block px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold shadow-lg transition">
                Go to Payments & Invoices
            </a>
        </div>
        <?php
        if (isset($_GET['print'])) echo '</body></html>';
        return;
    }

    $stmtResults = $pdo->prepare("SELECT * FROM sm_results WHERE school_id_number = ? AND numeric_class_name = ? AND session = ? AND term_id_number = ? AND admission_number = ? ORDER BY subject_code ASC");
    $stmtResults->execute([$school_id, $class_code, $session, $term_id, $admission_no]);
    $results = $stmtResults->fetchAll();

    $stmtRemark = $pdo->prepare("SELECT * FROM sm_result_remarks WHERE school_id_number = ? AND numeric_class_name = ? AND session = ? AND term_id_number = ? AND admission_number = ? LIMIT 1");
    $stmtRemark->execute([$school_id, $class_code, $session, $term_id, $admission_no]);
    $remarks = $stmtRemark->fetch();

    $stmtTermDetails = $pdo->prepare("SELECT term_name, next_term_begins, school_open_days FROM sm_terms WHERE school_id_number = ? AND id_number = ? LIMIT 1");
    $stmtTermDetails->execute([$school_id, $term_id]);
    $term_details = $stmtTermDetails->fetch();

    $stmtSchool = $pdo->prepare("SELECT * FROM sm_schools WHERE id = ? LIMIT 1");
    $stmtSchool->execute([$school_id]);
    $school_details = $stmtSchool->fetch();
    ?>
    <div class="print-container report-card-container mx-auto">
        <div class="flex items-center gap-6 border-b border-gray-200 print:border-gray-300 pb-6 mb-6">
            <div class="w-20 h-20 flex-shrink-0 bg-gray-100 rounded-xl flex items-center justify-center border border-gray-200">
                <?php 
                $logo_webp = "/dataimg/school_{$school_id}.webp";
                $logo_png = "/dataimg/school_{$school_id}.png";
                $logo_jpg = "/dataimg/school_{$school_id}.jpg";
                $logo_src = '';
                
                if (file_exists(dirname(dirname(__DIR__)) . $logo_webp)) {
                    $logo_src = $logo_webp;
                } elseif (file_exists(dirname(dirname(__DIR__)) . $logo_png)) {
                    $logo_src = $logo_png;
                } elseif (file_exists(dirname(dirname(__DIR__)) . $logo_jpg)) {
                    $logo_src = $logo_jpg;
                }
                
                if (!empty($logo_src)): ?>
                    <img src="<?php echo $logo_src; ?>" class="max-w-full max-h-full object-contain p-1" />
                <?php else: ?>
                    <span class="text-3xl">🏫</span>
                <?php endif; ?>
            </div>
            <div class="flex-1">
                <h1 class="text-xl font-black text-slate-800 print:text-black uppercase tracking-wide"><?php echo htmlspecialchars($school_details['name']); ?></h1>
                <p class="text-xs text-slate-500 print:text-gray-600 mt-1">Academic Progress Report Card</p>
                <p class="text-xs text-indigo-600 print:text-indigo-700 font-bold mt-0.5 uppercase tracking-wider">
                    <?php echo htmlspecialchars(ResultHelper::termName($pdo, $term_id, $school_id)); ?> | Session: <?php echo htmlspecialchars(str_replace('-', '/', $session)); ?>
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 bg-slate-50 border border-slate-200 rounded-xl p-5 mb-6 text-slate-800">
            <div class="md:col-span-3 space-y-2">
                <div class="grid grid-cols-3 text-sm">
                    <span class="text-slate-500 print:text-gray-500 font-medium">Student Name:</span>
                    <span class="col-span-2 text-slate-950 print:text-black font-bold"><?php echo htmlspecialchars(ResultHelper::studentName($pdo, $admission_no, $school_id)); ?></span>
                </div>
                <div class="grid grid-cols-3 text-sm">
                    <span class="text-slate-500 print:text-gray-500 font-medium">Admission ID:</span>
                    <span class="col-span-2 text-slate-950 print:text-black font-mono"><?php echo htmlspecialchars($admission_no); ?></span>
                </div>
                <div class="grid grid-cols-3 text-sm">
                    <span class="text-slate-500 print:text-gray-500 font-medium">Class:</span>
                    <span class="col-span-2 text-slate-950 print:text-black font-semibold"><?php echo htmlspecialchars(ResultHelper::studentClassName($pdo, $class_code, $school_id)); ?></span>
                </div>
                <div class="grid grid-cols-3 text-sm">
                    <span class="text-slate-500 print:text-gray-500 font-medium">Gender:</span>
                    <span class="col-span-2 text-slate-950 print:text-black capitalize"><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></span>
                </div>
            </div>
            <div class="flex justify-center items-center">
                <div class="w-24 h-24 bg-slate-100 border border-slate-200 rounded-lg overflow-hidden flex items-center justify-center">
                    <?php 
                    $pass_webp = "/dataimg/student_{$school_id}_{$admission_no}.webp";
                    $pass_png = "/dataimg/student_{$school_id}_{$admission_no}.png";
                    $pass_jpg = "/dataimg/student_{$school_id}_{$admission_no}.jpg";
                    $pass_src = '';
                    
                    if (file_exists(dirname(dirname(__DIR__)) . $pass_webp)) {
                        $pass_src = $pass_webp;
                    } elseif (file_exists(dirname(dirname(__DIR__)) . $pass_png)) {
                        $pass_src = $pass_png;
                    } elseif (file_exists(dirname(dirname(__DIR__)) . $pass_jpg)) {
                        $pass_src = $pass_jpg;
                    }
                    
                    if (!empty($pass_src)): ?>
                        <img src="<?php echo $pass_src; ?>" class="w-full h-full object-cover" />
                    <?php else: ?>
                        <span class="text-3xl text-gray-500">👤</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto mb-6">
            <table class="w-full min-w-[800px] border-collapse text-sm text-slate-800">
                <thead>
                    <tr class="bg-slate-100 border-b border-slate-200 text-slate-700">
                        <th class="px-4 py-3 text-left font-bold uppercase tracking-wider">Subject</th>
                        <th class="px-2 py-3 text-center font-bold">1st CA</th>
                        <th class="px-2 py-3 text-center font-bold">2nd CA</th>
                        <th class="px-2 py-3 text-center font-bold">3rd CA</th>
                        <th class="px-2 py-3 text-center font-bold">Exam</th>
                        <th class="px-2 py-3 text-center font-bold">Total</th>
                        <th class="px-2 py-3 text-center font-bold">Grade</th>
                        <th class="px-4 py-3 text-left font-bold">Remark</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php
                    $total_score_obtained = 0;
                    $subject_count = 0;
                    $total_score_obtainable = 0;

                    foreach ($results as $res):
                        $first_ca = $res['first_ca'] !== null ? (int)$res['first_ca'] : 0;
                        $second_ca = $res['second_ca'] !== null ? (int)$res['second_ca'] : 0;
                        $third_ca = $res['third_ca'] !== null ? (int)$res['third_ca'] : 0;
                        $exam = $res['exam'] !== null ? (int)$res['exam'] : 0;
                        
                        $subject_total = $first_ca + $second_ca + $third_ca + $exam;
                        $total_score_obtained += $subject_total;
                        $subject_count++;
                        $total_score_obtainable += 100;
                    ?>
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars(ResultHelper::subjectName($pdo, $res['subject_code'], $school_id)); ?></td>
                            <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['first_ca'] !== null ? $first_ca : '-'; ?></td>
                            <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['second_ca'] !== null ? $second_ca : '-'; ?></td>
                            <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['third_ca'] !== null ? $third_ca : '-'; ?></td>
                            <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['exam'] !== null ? $exam : '-'; ?></td>
                            <td class="px-2 py-3 text-center font-bold text-slate-900"><?php echo $subject_total; ?></td>
                            <td class="px-2 py-3 text-center font-black text-blue-700"><?php echo ResultHelper::getScoreGrade($pdo, $subject_total, 'grade', $school_id); ?></td>
                            <td class="px-4 py-3 text-xs italic text-slate-500"><?php echo ResultHelper::getScoreGrade($pdo, $subject_total, 'remark', $school_id); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 border-t border-slate-200 pt-6 mb-6">
            <div class="space-y-2 text-sm text-slate-700">
                <div>
                    <strong>Total Marks Obtained:</strong> <?php echo $total_score_obtained; ?> / <?php echo $total_score_obtainable; ?>
                </div>
                <div>
                    <strong>Average Percentage:</strong> 
                    <span class="text-blue-700 font-bold">
                        <?php echo $subject_count > 0 ? round(($total_score_obtained / $total_score_obtainable) * 100, 2) : 0; ?>%
                    </span>
                </div>
                <div>
                    <strong>Next Term Commences:</strong> <?php echo htmlspecialchars($term_details['next_term_begins'] ?? 'N/A'); ?>
                </div>
                <div>
                    <strong>Total School Open Days:</strong> <?php echo htmlspecialchars($term_details['school_open_days'] ?? 'N/A'); ?>
                </div>
            </div>

            <div class="space-y-4 text-xs text-slate-700">
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
                    <strong class="block text-[10px] uppercase text-indigo-700 font-bold mb-1">Teacher's Remarks:</strong>
                    <p class="italic text-slate-600">
                        <?php 
                        if (!empty($remarks['teacher_remark'])) {
                            echo htmlspecialchars($remarks['teacher_remark']);
                        } else {
                            echo 'No remark logged yet.';
                        }
                        ?>
                    </p>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
                    <strong class="block text-[10px] uppercase text-indigo-700 font-bold mb-1">Principal's Remarks:</strong>
                    <p class="italic text-slate-600">
                        <?php 
                        if (!empty($remarks['principal_remark'])) {
                            echo htmlspecialchars($remarks['principal_remark']);
                        } else {
                            $avg = $subject_count > 0 ? ($total_score_obtained / $total_score_obtainable) * 100 : 0;
                            echo htmlspecialchars(ResultHelper::principalRemark($student['gender'] ?? 'N/A', $avg));
                        }
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="flex justify-between items-end pt-12 mt-12 border-t border-dashed border-slate-200">
            <div class="text-center w-40">
                <div class="border-b border-slate-300 pb-2 mb-1"></div>
                <p class="text-xs text-slate-500">Class Teacher's Signature</p>
            </div>
            <div class="text-center w-40">
                <div class="border-b border-slate-300 pb-2 mb-1"></div>
                <p class="text-xs text-slate-500">Principal's Signature</p>
            </div>
        </div>
    </div>
    <?php
}

// ── 2. Action: Print Bulk Class Report Cards ──────────────────────────────
if ($action === 'print_bulk') {
    if ($user_role !== 'school_admin' && $user_role !== 'teacher') {
        echo '<div class="p-8 text-center text-red-400">⛔ Access Denied.</div>';
        if (isset($_GET['print'])) echo '</body></html>';
        return;
    }

    $class_code = $_GET['class'] ?? '';
    $session = $_GET['session'] ?? '';
    $term_id = $_GET['term'] ?? '';

    if (empty($class_code) || empty($session) || empty($term_id)) {
        echo '<div class="p-8 text-center text-red-400">⚠️ Error: Missing bulk criteria details.</div>';
        if (isset($_GET['print'])) echo '</body></html>';
        return;
    }

    $stmtStudents = $pdo->prepare("SELECT * FROM sm_students WHERE school_id_number = ? AND current_class = ? AND session = ? ORDER BY lastname, firstname");
    $stmtStudents->execute([$school_id, $class_code, $session]);
    $students = $stmtStudents->fetchAll();

    if (empty($students)) {
        echo '<div class="p-8 text-center text-red-400">⚠️ No students registered in class ' . htmlspecialchars($class_code) . ' for this session.</div>';
        if (isset($_GET['print'])) echo '</body></html>';
        return;
    }

    $stmtTermDetails = $pdo->prepare("SELECT term_name, next_term_begins, school_open_days FROM sm_terms WHERE school_id_number = ? AND id_number = ? LIMIT 1");
    $stmtTermDetails->execute([$school_id, $term_id]);
    $term_details = $stmtTermDetails->fetch();

    $stmtSchool = $pdo->prepare("SELECT * FROM sm_schools WHERE id = ? LIMIT 1");
    $stmtSchool->execute([$school_id]);
    $school_details = $stmtSchool->fetch();

    foreach ($students as $student):
        $admission_no = $student['admission_number'];

        $stmtResults = $pdo->prepare("SELECT * FROM sm_results WHERE school_id_number = ? AND numeric_class_name = ? AND session = ? AND term_id_number = ? AND admission_number = ? ORDER BY subject_code ASC");
        $stmtResults->execute([$school_id, $class_code, $session, $term_id, $admission_no]);
        $results = $stmtResults->fetchAll();

        if (empty($results)) continue;

        $stmtRemark = $pdo->prepare("SELECT * FROM sm_result_remarks WHERE school_id_number = ? AND numeric_class_name = ? AND session = ? AND term_id_number = ? AND admission_number = ? LIMIT 1");
        $stmtRemark->execute([$school_id, $class_code, $session, $term_id, $admission_no]);
        $remarks = $stmtRemark->fetch();
        ?>
        <div class="print-container report-card-container mx-auto page-break mb-12">
            <div class="flex items-center gap-6 border-b border-gray-200 pb-6 mb-6">
                <div class="w-20 h-20 flex-shrink-0 bg-gray-100 rounded-xl flex items-center justify-center border border-gray-200">
                    <?php 
                    $logo_webp = "/dataimg/school_{$school_id}.webp";
                    $logo_png = "/dataimg/school_{$school_id}.png";
                    $logo_jpg = "/dataimg/school_{$school_id}.jpg";
                    $logo_src = '';
                    
                    if (file_exists(dirname(dirname(__DIR__)) . $logo_webp)) {
                        $logo_src = $logo_webp;
                    } elseif (file_exists(dirname(dirname(__DIR__)) . $logo_png)) {
                        $logo_src = $logo_png;
                    } elseif (file_exists(dirname(dirname(__DIR__)) . $logo_jpg)) {
                        $logo_src = $logo_jpg;
                    }
                    
                    if (!empty($logo_src)): ?>
                        <img src="<?php echo $logo_src; ?>" class="max-w-full max-h-full object-contain p-1" />
                    <?php else: ?>
                        <span class="text-3xl">🏫</span>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <h1 class="text-xl font-black text-slate-800 print:text-black uppercase tracking-wide"><?php echo htmlspecialchars($school_details['name']); ?></h1>
                    <p class="text-xs text-slate-500 print:text-gray-600 mt-1">Academic Progress Report Card</p>
                    <p class="text-xs text-indigo-600 print:text-indigo-700 font-bold mt-0.5 uppercase tracking-wider">
                        <?php echo htmlspecialchars($term_details['term_name'] ?? 'N/A'); ?> | Session: <?php echo htmlspecialchars(str_replace('-', '/', $session)); ?>
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 bg-slate-50 border border-slate-200 rounded-xl p-5 mb-6 text-slate-800">
                <div class="md:col-span-3 space-y-2">
                    <div class="grid grid-cols-3 text-sm">
                        <span class="text-slate-500 print:text-gray-500 font-medium">Student Name:</span>
                        <span class="col-span-2 text-slate-950 print:text-black font-bold"><?php echo htmlspecialchars($student['firstname'] . ' ' . $student['lastname']); ?></span>
                    </div>
                    <div class="grid grid-cols-3 text-sm">
                        <span class="text-slate-500 print:text-gray-500 font-medium">Admission ID:</span>
                        <span class="col-span-2 text-slate-950 print:text-black font-mono"><?php echo htmlspecialchars($admission_no); ?></span>
                    </div>
                    <div class="grid grid-cols-3 text-sm">
                        <span class="text-slate-500 print:text-gray-500 font-medium">Class:</span>
                        <span class="col-span-2 text-slate-950 print:text-black font-semibold"><?php echo htmlspecialchars(ResultHelper::studentClassName($pdo, $class_code, $school_id)); ?></span>
                    </div>
                    <div class="grid grid-cols-3 text-sm">
                        <span class="text-slate-500 print:text-gray-500 font-medium">Gender:</span>
                        <span class="col-span-2 text-slate-950 print:text-black capitalize"><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></span>
                    </div>
                </div>
                <div class="flex justify-center items-center">
                    <div class="w-24 h-24 bg-slate-100 border border-slate-200 rounded-lg overflow-hidden flex items-center justify-center">
                        <?php 
                    $pass_webp = "/dataimg/student_{$school_id}_{$admission_no}.webp";
                    $pass_png = "/dataimg/student_{$school_id}_{$admission_no}.png";
                    $pass_jpg = "/dataimg/student_{$school_id}_{$admission_no}.jpg";
                    $pass_src = '';
                    
                    if (file_exists(dirname(dirname(__DIR__)) . $pass_webp)) {
                        $pass_src = $pass_webp;
                    } elseif (file_exists(dirname(dirname(__DIR__)) . $pass_png)) {
                        $pass_src = $pass_png;
                    } elseif (file_exists(dirname(dirname(__DIR__)) . $pass_jpg)) {
                        $pass_src = $pass_jpg;
                    }
                    
                    if (!empty($pass_src)): ?>
                        <img src="<?php echo $pass_src; ?>" class="w-full h-full object-cover" />
                    <?php else: ?>
                        <span class="text-3xl text-gray-500">👤</span>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto mb-6">
                <table class="w-full min-w-[800px] border-collapse text-sm text-slate-800">
                    <thead>
                        <tr class="bg-slate-100 border-b border-slate-200 text-slate-700">
                            <th class="px-4 py-3 text-left font-bold uppercase tracking-wider">Subject</th>
                            <th class="px-2 py-3 text-center font-bold">1st CA</th>
                            <th class="px-2 py-3 text-center font-bold">2nd CA</th>
                            <th class="px-2 py-3 text-center font-bold">3rd CA</th>
                            <th class="px-2 py-3 text-center font-bold">Exam</th>
                            <th class="px-2 py-3 text-center font-bold">Total</th>
                            <th class="px-2 py-3 text-center font-bold">Grade</th>
                            <th class="px-4 py-3 text-left font-bold">Remark</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php
                        $total_score_obtained = 0;
                        $subject_count = 0;
                        $total_score_obtainable = 0;

                        foreach ($results as $res):
                            $first_ca = $res['first_ca'] !== null ? (int)$res['first_ca'] : 0;
                            $second_ca = $res['second_ca'] !== null ? (int)$res['second_ca'] : 0;
                            $third_ca = $res['third_ca'] !== null ? (int)$res['third_ca'] : 0;
                            $exam = $res['exam'] !== null ? (int)$res['exam'] : 0;
                            
                            $subject_total = $first_ca + $second_ca + $third_ca + $exam;
                            $total_score_obtained += $subject_total;
                            $subject_count++;
                            $total_score_obtainable += 100;
                        ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars(ResultHelper::subjectName($pdo, $res['subject_code'], $school_id)); ?></td>
                                <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['first_ca'] !== null ? $first_ca : '-'; ?></td>
                                <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['second_ca'] !== null ? $second_ca : '-'; ?></td>
                                <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['third_ca'] !== null ? $third_ca : '-'; ?></td>
                                <td class="px-2 py-3 text-center text-slate-600"><?php echo $res['exam'] !== null ? $exam : '-'; ?></td>
                                <td class="px-2 py-3 text-center font-bold text-slate-900"><?php echo $subject_total; ?></td>
                                <td class="px-2 py-3 text-center font-black text-blue-700"><?php echo ResultHelper::getScoreGrade($pdo, $subject_total, 'grade', $school_id); ?></td>
                                <td class="px-4 py-3 text-xs italic text-slate-500"><?php echo ResultHelper::getScoreGrade($pdo, $subject_total, 'remark', $school_id); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 border-t border-slate-200 pt-6 mb-6">
                <div class="space-y-2 text-sm text-slate-700">
                    <div>
                        <strong>Total Marks Obtained:</strong> <?php echo $total_score_obtained; ?> / <?php echo $total_score_obtainable; ?>
                    </div>
                    <div>
                        <strong>Average Percentage:</strong> 
                        <span class="text-blue-700 font-bold">
                            <?php echo $subject_count > 0 ? round(($total_score_obtained / $total_score_obtainable) * 100, 2) : 0; ?>%
                        </span>
                    </div>
                    <div>
                        <strong>Next Term Commences:</strong> <?php echo htmlspecialchars($term_details['next_term_begins'] ?? 'N/A'); ?>
                    </div>
                    <div>
                        <strong>Total School Open Days:</strong> <?php echo htmlspecialchars($term_details['school_open_days'] ?? 'N/A'); ?>
                    </div>
                </div>

                <div class="space-y-4 text-xs text-slate-700">
                    <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
                        <strong class="block text-[10px] uppercase text-indigo-700 font-bold mb-1">Teacher's Remarks:</strong>
                        <p class="italic text-slate-600">
                            <?php 
                            if (!empty($remarks['teacher_remark'])) {
                                echo htmlspecialchars($remarks['teacher_remark']);
                            } else {
                                echo 'No remark logged yet.';
                            }
                            ?>
                        </p>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 rounded-lg p-3">
                        <strong class="block text-[10px] uppercase text-indigo-700 font-bold mb-1">Principal's Remarks:</strong>
                        <p class="italic text-slate-600">
                            <?php 
                            if (!empty($remarks['principal_remark'])) {
                                echo htmlspecialchars($remarks['principal_remark']);
                            } else {
                                $avg = $subject_count > 0 ? ($total_score_obtained / $total_score_obtainable) * 100 : 0;
                                echo htmlspecialchars(ResultHelper::principalRemark($student['gender'] ?? 'N/A', $avg));
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex justify-between items-end pt-12 mt-12 border-t border-dashed border-slate-200">
                <div class="text-center w-40">
                    <div class="border-b border-slate-300 pb-2 mb-1"></div>
                    <p class="text-xs text-slate-500">Class Teacher's Signature</p>
                </div>
                <div class="text-center w-40">
                    <div class="border-b border-slate-300 pb-2 mb-1"></div>
                    <p class="text-xs text-slate-500">Principal's Signature</p>
                </div>
            </div>
        </div>
    <?php
    endforeach;
}

// ── 3. Action: Student Results View Desk (Student & Parent portals) ─────────
if ($action === 'student_results') {
    ?>
    <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
        <h3 class="text-xl font-bold mb-2 bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🎓 Student Results Desk</h3>
        <p class="text-sm text-gray-400 mb-6">Select a term report card from the list below to view or print.</p>

        <?php if ($user_role === 'student'):
            $admission_no = $student_info['admission_number'];
            $stmt = $pdo->prepare("SELECT DISTINCT numeric_class_name, session, term_id_number FROM sm_results WHERE school_id_number = ? AND admission_number = ?");
            $stmt->execute([$school_id, $admission_no]);
            $results = $stmt->fetchAll();
        ?>
            <?php if (empty($results)): ?>
                <p class="text-gray-400 text-sm">No report cards are available for your account yet.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($results as $res): ?>
                        <div class="flex items-center justify-between p-4 bg-white/5 rounded-xl border border-white/5 hover:border-white/10 transition">
                            <div>
                                <h4 class="font-bold text-white"><?php echo htmlspecialchars(ResultHelper::studentClassName($pdo, $res['numeric_class_name'], $school_id)); ?></h4>
                                <p class="text-xs text-gray-400">Session: <?php echo htmlspecialchars(str_replace('-', '/', $res['session'])); ?> | <?php echo htmlspecialchars(ResultHelper::termName($pdo, $res['term_id_number'], $school_id)); ?></p>
                            </div>
                            <a href="?page=evaluation&action=print_single&class=<?php echo htmlspecialchars($res['numeric_class_name']); ?>&session=<?php echo htmlspecialchars($res['session']); ?>&term=<?php echo htmlspecialchars($res['term_id_number']); ?>&admission_number=<?php echo htmlspecialchars($admission_no); ?>&print=1" target="_blank"
                               class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold shadow-md transition">
                                📄 Open Report Card
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($user_role === 'parent'):
            $stmtParent = $pdo->prepare("SELECT id_number FROM sm_parents WHERE email = ? OR id_number = ? LIMIT 1");
            $stmtParent->execute([$_SESSION['user_session'], $_SESSION['user_session']]);
            $parent_id = $stmtParent->fetchColumn();

            $stmtChildren = $pdo->prepare("SELECT * FROM sm_students WHERE parent_id_number = ?");
            $stmtChildren->execute([$parent_id]);
            $children = $stmtChildren->fetchAll();
        ?>
            <?php if (empty($children)): ?>
                <div class="p-6 bg-red-500/10 border border-red-500/25 text-red-400 rounded-xl text-sm text-center">
                    ⚠️ No children accounts linked to this parent profile. Please contact the school registry to link your children.
                </div>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($children as $child):
                        $admission_no = $child['admission_number'];
                        $stmt = $pdo->prepare("SELECT DISTINCT numeric_class_name, session, term_id_number FROM sm_results WHERE school_id_number = ? AND admission_number = ?");
                        $stmt->execute([$school_id, $admission_no]);
                        $child_results = $stmt->fetchAll();
                    ?>
                        <div class="bg-white/5 border border-white/5 rounded-2xl p-5">
                            <div class="flex items-center gap-4 mb-4">
                                <div class="w-10 h-10 bg-gray-700 rounded-full flex items-center justify-center font-bold text-white uppercase text-sm">
                                    <?php echo substr($child['firstname'], 0, 1) . substr($child['lastname'], 0, 1); ?>
                                </div>
                                <div>
                                    <h4 class="font-bold text-white text-base"><?php echo htmlspecialchars($child['firstname'] . ' ' . $child['lastname']); ?></h4>
                                    <p class="text-xs text-gray-400 font-mono">ID: <?php echo htmlspecialchars($admission_no); ?> | Class: <?php echo htmlspecialchars(ResultHelper::studentClassName($pdo, $child['current_class'], $school_id)); ?></p>
                                </div>
                            </div>

                            <?php if (empty($child_results)): ?>
                                <p class="text-gray-500 text-xs italic">No report cards are available for this student yet.</p>
                            <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <?php foreach ($child_results as $res): ?>
                                        <div class="flex items-center justify-between p-3.5 bg-black/20 rounded-xl border border-white/5">
                                            <div>
                                                <h5 class="text-xs font-bold text-white"><?php echo htmlspecialchars(ResultHelper::termName($pdo, $res['term_id_number'], $school_id)); ?></h5>
                                                <p class="text-[10px] text-gray-400">Class: <?php echo htmlspecialchars($res['numeric_class_name']); ?> | Session: <?php echo htmlspecialchars(str_replace('-', '/', $res['session'])); ?></p>
                                            </div>
                                            <a href="?page=evaluation&action=print_single&class=<?php echo htmlspecialchars($res['numeric_class_name']); ?>&session=<?php echo htmlspecialchars($res['session']); ?>&term=<?php echo htmlspecialchars($res['term_id_number']); ?>&admission_number=<?php echo htmlspecialchars($admission_no); ?>&print=1" target="_blank"
                                               class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-semibold transition">
                                                📄 View
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php
}

// ── 4. Action: Report Cards Directory Panel (Admin & Teacher portal) ───────
if ($action === 'report_cards') {
    $stmtClasses = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? GROUP BY numeric_class_name");
    $stmtClasses->execute([$school_id]);
    $classes = $stmtClasses->fetchAll();

    $stmtSessions = $pdo->prepare("SELECT DISTINCT session FROM sm_students WHERE school_id_number = ? AND session IS NOT NULL AND session != ''");
    $stmtSessions->execute([$school_id]);
    $sessions = $stmtSessions->fetchAll(PDO::FETCH_COLUMN);
    if (empty($sessions)) {
        $sessions = ['2026/2027', '2025/2026'];
    }

    $stmtTerms = $pdo->prepare("SELECT id_number, term_name FROM sm_terms WHERE school_id_number = ?");
    $stmtTerms->execute([$school_id]);
    $terms = $stmtTerms->fetchAll();

    $class_filter = $_GET['filter_class'] ?? '';
    $session_filter = $_GET['filter_session'] ?? '';
    $term_filter = $_GET['filter_term'] ?? '';
    
    $students_list = [];
    if (!empty($class_filter) && !empty($session_filter)) {
        $stmtS = $pdo->prepare("SELECT admission_number, lastname, firstname, current_class FROM sm_students WHERE school_id_number = ? AND current_class = ? AND session = ? ORDER BY lastname, firstname");
        $stmtS->execute([$school_id, $class_filter, $session_filter]);
        $students_list = $stmtS->fetchAll();
    }
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 text-white">
        <!-- Selection Filters Card -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md h-fit">
            <h3 class="text-lg font-semibold text-blue-300 mb-4">🗂️ Report Card Printing Panel</h3>
            <p class="text-xs text-gray-400 mb-6">Select a class, session, and term below to fetch results.</p>

            <form method="get" class="space-y-4">
                <input type="hidden" name="page" value="evaluation">
                <input type="hidden" name="action" value="report_cards">

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Select Class</label>
                    <select name="filter_class" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">-- Choose Class --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" <?php echo $class_filter === $cls['numeric_class_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cls['class_name'] . ' (' . $cls['numeric_class_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Select Session</label>
                    <select name="filter_session" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">-- Choose Session --</option>
                        <?php foreach ($sessions as $sess): ?>
                            <option value="<?php echo htmlspecialchars($sess); ?>" <?php echo $session_filter === $sess ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(str_replace('-', '/', $sess)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Select Term</label>
                    <select name="filter_term" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">-- Choose Term --</option>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?php echo htmlspecialchars($t['id_number']); ?>" <?php echo $term_filter === $t['id_number'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['term_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-all shadow-md">
                    🔍 Load Students List
                </button>
            </form>
        </div>

        <!-- Students Directory & Printing Options -->
        <div class="lg:col-span-2 bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6 pb-4 border-b border-white/10">
                <h3 class="text-lg font-semibold text-white">Student Results Directory</h3>
                <?php if (!empty($students_list)): ?>
                    <a href="?page=evaluation&action=print_bulk&class=<?php echo urlencode($class_filter); ?>&session=<?php echo urlencode($session_filter); ?>&term=<?php echo urlencode($term_filter); ?>&print=1" target="_blank"
                       class="whitespace-nowrap px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold transition-all shadow-md flex items-center gap-1.5">
                       🖨️ Print All Class Results (Bulk)
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($class_filter) || empty($session_filter)): ?>
                <div class="text-center py-12 text-gray-500">
                    <span class="text-5xl block mb-4">🗂️</span>
                    <p class="font-medium text-sm">Please choose Class, Session, and Term from filters card, then click "Load Students List" to view student directory.</p>
                </div>
            <?php else: ?>
                <?php if (empty($students_list)): ?>
                    <p class="text-gray-400 text-sm text-center py-8">No students found matching class <?php echo htmlspecialchars($class_filter); ?> in session <?php echo htmlspecialchars($session_filter); ?>.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[650px] text-left text-sm text-gray-300">
                            <thead>
                                <tr class="border-b border-white/10 text-gray-400 text-xs uppercase">
                                    <th class="py-3 px-4">Admission ID</th>
                                    <th class="py-3 px-4">Student Name</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php foreach ($students_list as $std): ?>
                                    <tr class="hover:bg-white/5 transition">
                                        <td class="py-3.5 px-4 font-mono text-xs text-gray-300"><?php echo htmlspecialchars($std['admission_number']); ?></td>
                                        <td class="py-3.5 px-4 font-semibold text-white"><?php echo htmlspecialchars($std['lastname'] . ' ' . $std['firstname']); ?></td>
                                        <td class="py-3.5 px-4 text-right">
                                            <a href="?page=evaluation&action=print_single&class=<?php echo urlencode($class_filter); ?>&session=<?php echo urlencode($session_filter); ?>&term=<?php echo urlencode($term_filter); ?>&admission_number=<?php echo urlencode($std['admission_number']); ?>&print=1" target="_blank"
                                               class="inline-block px-3 py-1.5 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded-lg text-xs font-semibold transition">
                                                📄 View Report Card
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php
}
?>

<?php if (isset($_GET['print'])): ?>
</body>
</html>
<?php endif; ?>

