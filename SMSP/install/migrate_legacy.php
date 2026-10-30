<?php
// ============================================================
//  Legacy → New SchoolApp  |  Migration Wizard
//  Endpoint: /install/migrate_legacy.php
// ============================================================
define('ROOT_PATH', dirname(__DIR__));

// ── AJAX Migration Runner ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'run') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    ob_implicit_flush(true);
    ob_end_flush();

    function sse($type, $data) {
        echo "data: " . json_encode(['type' => $type, 'data' => $data]) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
    }

    function sseProgress($step, $label, $pct, $count = null) {
        sse('progress', ['step' => $step, 'label' => $label, 'pct' => $pct, 'count' => $count]);
    }

    // Load new DB config
    if (file_exists(ROOT_PATH . '/config/config.php')) {
        require_once ROOT_PATH . '/config/config.php';
        require_once ROOT_PATH . '/include/db.php';
    } else {
        sse('error', 'New app config.php not found. Please complete setup first.');
        exit;
    }

    $leg_host = trim($_POST['leg_host'] ?? '127.0.0.1');
    $leg_port = (int)($_POST['leg_port'] ?? 3306);
    $leg_user = trim($_POST['leg_user'] ?? '');
    $leg_pass = $_POST['leg_pass'] ?? '';
    $leg_name = trim($_POST['leg_name'] ?? '');

    // ── Test legacy connection ───────────────────────────────
    sseProgress(1, 'Connecting to legacy database…', 5);
    try {
        $legacyDsn = "mysql:host={$leg_host};port={$leg_port};dbname={$leg_name};charset=utf8mb4";
        $legacyPdo = new PDO($legacyDsn, $leg_user, $leg_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Exception $e) {
        sse('error', 'Cannot connect to legacy DB: ' . $e->getMessage());
        exit;
    }
    sseProgress(1, 'Legacy database connected ✓', 10);

    // ── Pre-flight: count records ────────────────────────────
    sseProgress(2, 'Running pre-flight checks…', 14);
    $preflight = [];
    $checkTables = [
        'sm_school_details','sm_students','sm_parents','sm_teachers',
        'sm_moderators','sm_results','sm_exams','sm_fee_payment_lists'
    ];
    foreach ($checkTables as $t) {
        try {
            $n = (int)$legacyPdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $preflight[$t] = $n;
        } catch (Exception $e) {
            $preflight[$t] = 0;
        }
    }
    sse('preflight', $preflight);
    sseProgress(2, 'Pre-flight complete ✓', 18);

    // ── Begin migration ──────────────────────────────────────
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Truncate tables to ensure a clean, non-duplicated migration run
    $tablesToTruncate = [
        'sm_school_addons', 'sm_teacher_attendances', 'sm_student_attendances',
        'sm_submitted_homework_lists', 'sm_homework_lists', 'sm_notifications',
        'sm_notices', 'sm_fee_payment_lists', 'sm_transports', 'sm_book_lists',
        'sm_beds', 'sm_rooms', 'sm_hostels', 'sm_cbt_schedules', 'sm_result_remarks',
        'sm_results', 'sm_grades', 'sm_exams', 'sm_subjects', 'sm_classes',
        'sm_class_categories', 'sm_terms', 'sm_sessions', 'sm_students', 'sm_parents',
        'sm_teachers', 'sm_moderators', 'sm_schools'
    ];
    foreach ($tablesToTruncate as $tbl) {
        try {
            $pdo->exec("TRUNCATE TABLE `{$tbl}`");
        } catch (Exception $e) {
            // Silence if table doesn't exist yet
        }
    }

    $pdo->beginTransaction();

    $results = [];
    $errors  = [];
    $totalSteps = 10;
    $baseProgress = 20;

    // Helper: migrate one table using memory-efficient iteration and lazy password migration
    $migrate = function($legacyTable, $newTable, $colMap) use ($legacyPdo, $pdo, &$results, &$errors) {
        try {
            $cols = array_values($colMap);
            $ph   = implode(', ', array_fill(0, count($cols), '?'));
            $sql  = "INSERT IGNORE INTO `{$newTable}` (" . implode(', ', $cols) . ") VALUES ({$ph})";
            $stmt = $pdo->prepare($sql);
            
            $query = $legacyPdo->query("SELECT * FROM `{$legacyTable}`");
            $count = 0;
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $vals = [];
                foreach (array_keys($colMap) as $lc) {
                    $vals[] = $row[$lc] ?? null;
                }
                $stmt->execute($vals);
                $count++;
            }
            $results[$newTable] = $count;
            return $count;
        } catch (Exception $e) {
            $errors[] = "Table {$legacyTable}: " . $e->getMessage();
            $results[$newTable] = 'ERROR';
            return 0;
        }
    };

    // ── Step 3: Schools (Custom migration to generate unique slugs dynamically) ──
    sseProgress(3, 'Migrating schools…', 22);
    $n = 0;
    try {
        $query = $legacyPdo->query("SELECT * FROM `sm_school_details`");
        $stmt = $pdo->prepare("INSERT INTO `sm_schools` (id, name, motto, wallet_balance, slug, status) VALUES (?, ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE name=VALUES(name), motto=VALUES(motto), wallet_balance=VALUES(wallet_balance), status='active'");
        
        $usedSlugs = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $schoolId = $row['school_id_number'];
            $name = $row['school_name'];
            $motto = $row['school_motto'] ?? '';
            $wallet = $row['wallet_balance'] ?? 0;
            
            // Generate clean URL slug from name
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
            if (empty($slug)) {
                $slug = 'school-' . $schoolId;
            }
            if (in_array($slug, $usedSlugs)) {
                $slug .= '-' . $schoolId;
            }
            $usedSlugs[] = $slug;
            
            $stmt->execute([$schoolId, $name, $motto, $wallet, $slug]);
            $n++;
        }
        $results['sm_schools'] = $n;
    } catch (Exception $e) {
        $errors[] = "Schools Migration: " . $e->getMessage();
        $results['sm_schools'] = 'ERROR';
    }
    sse('step_done', ['label' => 'Schools', 'count' => $n]);
    sseProgress(3, "Schools migrated: {$n} ✓", 26);

    // ── Step 4: Users ────────────────────────────────────────
    sseProgress(4, 'Migrating admins & moderators…', 29);
    $migrate('sm_super_moderators', 'sm_super_moderators', [
        'email'     => 'email',
        'password'  => 'password',
        'firstname' => 'fullname',
    ]);
    $mod = $migrate('sm_moderators', 'sm_moderators', [
        'school_id_number' => 'school_id_number',
        'firstname'        => 'fullname',
        'email'            => 'email',
        'password'         => 'password',
    ]);
    sse('step_done', ['label' => 'Admins / Moderators', 'count' => $mod]);

    sseProgress(4, 'Migrating teachers…', 33);
    $teachers = $migrate('sm_teachers', 'sm_teachers', [
        'id_number'        => 'id_number',
        'school_id_number' => 'school_id_number',
        'lastname'         => 'lastname',
        'firstname'        => 'firstname',
        'email'            => 'email',
        'password'         => 'password',
        'class'            => 'class',
        'phone_number'     => 'phone_number',
        'gender'           => 'gender',
        'dob'              => 'dob',
        'home_address'     => 'home_address',
    ]);
    sse('step_done', ['label' => 'Teachers', 'count' => $teachers]);

    sseProgress(4, 'Migrating parents…', 37);
    $parents = $migrate('sm_parents', 'sm_parents', [
        'id_number'           => 'id_number',
        'school_id_number'    => 'school_id_number',
        'father_first_name'   => 'fullname',
        'email'               => 'email',
        'password'            => 'password',
        'father_phone_number' => 'phone_number',
        'home_address'        => 'home_address',
    ]);
    sse('step_done', ['label' => 'Parents', 'count' => $parents]);

    sseProgress(4, 'Migrating students…', 41);
    $students = $migrate('sm_students', 'sm_students', [
        'admission_number' => 'admission_number',
        'school_id_number' => 'school_id_number',
        'parent_id_number' => 'parent_id_number',
        'lastname'         => 'lastname',
        'firstname'        => 'firstname',
        'othername'        => 'othername',
        'email'            => 'email',
        'password'         => 'password',
        'current_class'    => 'current_class',
        'session'          => 'session',
        'gender'           => 'gender',
        'dob'              => 'dob',
        'blood_group'      => 'blood_group',
        'home_address'     => 'home_address',
        'bus_id_number'    => 'bus_id_number',
        'bed_id_number'    => 'bed_id_number',
    ]);
    sse('step_done', ['label' => 'Students', 'count' => $students]);
    sseProgress(4, "All users migrated ✓", 45);

    // ── Step 5: Academics ────────────────────────────────────
    sseProgress(5, 'Migrating academic structure…', 48);
    $migrate('sm_sessions', 'sm_sessions', ['school_id_number' => 'school_id_number', 'session' => 'session']);
    $migrate('sm_terms', 'sm_terms', [
        'school_id_number' => 'school_id_number',
        'id_number'        => 'id_number',
        'term_name'        => 'term_name',
        'next_term_begins' => 'next_term_begins',
        'school_open_days' => 'school_open_days',
    ]);
    $migrate('sm_class_category', 'sm_class_category', [
        'school_id_number'    => 'school_id_number',
        'class_category_name' => 'class_category_name',
    ]);
    $migrate('sm_classes', 'sm_classes', [
        'school_id_number'   => 'school_id_number',
        'class_name'         => 'class_name',
        'numeric_class_name' => 'numeric_class_name',
        'student_capacity'   => 'student_capacity',
        'session'            => 'session',
    ]);
    $subjects = $migrate('sm_subjects', 'sm_subjects', [
        'school_id_number'   => 'school_id_number',
        'subject_name'       => 'subject_name',
        'subject_code'       => 'subject_code',
        'teacher_id_number'  => 'teacher_id_number',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
    ]);
    sse('step_done', ['label' => 'Classes & Subjects', 'count' => $subjects]);
    sseProgress(5, 'Academic structure migrated ✓', 53);

    // ── Step 6: Exams, Results, CBT ──────────────────────────
    sseProgress(6, 'Migrating exams & results…', 56);
    $exams = $migrate('sm_exams', 'sm_exams', [
        'school_id_number'   => 'school_id_number',
        'subject_code'       => 'subject_code',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'term_id_number'     => 'term_id_number',
        'pass_mark'          => 'pass_mark',
        'total_mark'         => 'total_mark',
        'exam_start_date'    => 'exam_start_date',
        'exam_end_date'      => 'exam_end_date',
        'exam_comment'       => 'exam_comment',
    ]);
    $migrate('sm_grades', 'sm_grades', [
        'school_id_number' => 'school_id_number',
        'grade_name'       => 'grade_name',
        'mark_from'        => 'mark_from',
        'mark_upto'        => 'mark_upto',
        'grade_comment'    => 'grade_comment',
    ]);
    $results_r = $migrate('sm_results', 'sm_results', [
        'school_id_number'   => 'school_id_number',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'term_id_number'     => 'term_id_number',
        'subject_code'       => 'subject_code',
        'admission_number'   => 'admission_number',
        'first_ca'           => 'first_ca',
        'second_ca'          => 'second_ca',
        'third_ca'           => 'third_ca',
        'exam'               => 'exam',
        'comment'            => 'comment',
    ]);
    $migrate('sm_result_remarks', 'sm_result_remarks', [
        'school_id_number'   => 'school_id_number',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'term_id_number'     => 'term_id_number',
        'admission_number'   => 'admission_number',
        'principal_remark'   => 'principal_remark',
    ]);
    $cbt = $migrate('sm_cbt_scheldule_lists', 'sm_cbt_schedules', [
        'school_id_number'   => 'school_id_number',
        'paper_title'        => 'paper_title',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'term_id_number'     => 'term_id_number',
        'subject_code'       => 'subject_code',
        'cbt_type'           => 'cbt_type',
        'exam_json'          => 'exam_json',
        'exam_date'          => 'exam_date',
        'exam_time'          => 'exam_time',
        'exam_questions'     => 'exam_questions',
        'exam_duration'      => 'exam_duration',
    ]);
    sse('step_done', ['label' => 'Exams / Results / CBT', 'count' => $exams + $results_r + $cbt]);
    sseProgress(6, 'Exams & results migrated ✓', 63);

    // ── Step 7: Logistics ────────────────────────────────────
    sseProgress(7, 'Migrating hostels, library & transport…', 66);
    $migrate('sm_hostels', 'sm_hostels', [
        'id_number'          => 'id_number',
        'school_id_number'   => 'school_id_number',
        'hostel_name'        => 'hostel_name',
        'hostel_type'        => 'hostel_type',
        'hostel_description' => 'hostel_description',
    ]);
    $migrate('sm_rooms', 'sm_rooms', [
        'id_number'        => 'id_number',
        'school_id_number' => 'school_id_number',
        'hostel_id_number' => 'hostel_id_number',
        'category_number'  => 'category_number',
        'bed_capacity'     => 'bed_capacity',
        'room_description' => 'room_description',
    ]);
    $migrate('sm_beds', 'sm_beds', [
        'id_number'       => 'id_number',
        'school_id_number'=> 'school_id_number',
        'room_id_number'  => 'room_id_number',
        'bed_description' => 'bed_description',
    ]);
    $migrate('sm_book_category', 'sm_book_categories', [
        'id_number'        => 'id_number',
        'school_id_number' => 'school_id_number',
        'category_name'    => 'category_name',
    ]);
    $migrate('sm_book_lists', 'sm_book_lists', [
        'school_id_number' => 'school_id_number',
        'isbn'             => 'isbn',
        'book_category_id' => 'book_category_id',
        'book_name'        => 'book_name',
        'author_name'      => 'author_name',
        'rack_location'    => 'rack_location',
        'price'            => 'price',
        'quantity'         => 'quantity',
        'description'      => 'description',
    ]);
    $migrate('sm_transports', 'sm_transports', [
        'id_number'        => 'id_number',
        'school_id_number' => 'school_id_number',
        'route_name'       => 'route_name',
        'reg_number'       => 'reg_number',
        'driver_name'      => 'driver_name',
        'phone_number'     => 'phone_number',
        'home_address'     => 'home_address',
        'route_fares'      => 'route_fares',
        'description'      => 'description',
    ]);
    sse('step_done', ['label' => 'Hostels / Library / Transport', 'count' => null]);
    sseProgress(7, 'Logistics migrated ✓', 72);

    // ── Step 8: Finance ──────────────────────────────────────
    sseProgress(8, 'Migrating fee records & payments…', 75);
    $migrate('sm_fee_type', 'sm_fee_types', [
        'id_number'        => 'id_number',
        'school_id_number' => 'school_id_number',
        'fee_name'         => 'fee_name',
    ]);
    $migrate('sm_fee_lists', 'sm_fee_lists', [
        'school_id_number'   => 'school_id_number',
        'fee_type_id'        => 'fee_type_id',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'amount'             => 'amount',
        'description'        => 'description',
    ]);
    $payments = $migrate('sm_fee_payment_lists', 'sm_fee_payment_lists', [
        'school_id_number'   => 'school_id_number',
        'admission_number'   => 'admission_number',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'fee_type_id'        => 'fee_type_id',
        'online_ref'         => 'online_ref',
        'manual_ref'         => 'manual_ref',
        'amount'             => 'amount',
        'amount_paid'        => 'amount_paid',
        'starting_year'      => 'starting_year',
        'ending_year'        => 'ending_year',
        'status'             => 'status',
        'description'        => 'description',
        'date'               => 'date',
    ]);
    sse('step_done', ['label' => 'Fees & Payments', 'count' => $payments]);
    sseProgress(8, 'Finance records migrated ✓', 81);

    // ── Step 9: Communications, Homework, Attendance ─────────
    sseProgress(9, 'Migrating homework, notices & attendance…', 84);
    $migrate('sm_notices', 'sm_notices', [
        'school_id_number'   => 'school_id_number',
        'notice_title'       => 'notice_title',
        'notice_comment'     => 'notice_comment',
        'numeric_class_name' => 'numeric_class_name',
        'start_date'         => 'start_date',
        'end_date'           => 'end_date',
        'notice_for'         => 'notice_for',
    ]);
    $migrate('sm_notifications', 'sm_notifications', [
        'school_id_number'   => 'school_id_number',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'user'               => 'user',
        'title'              => 'title',
        'message'            => 'message',
    ]);
    $migrate('sm_homework_lists', 'sm_homework_lists', [
        'school_id_number'   => 'school_id_number',
        'title'              => 'title',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'subject_code'       => 'subject_code',
        'document_title'     => 'document_title',
        'document_link'      => 'document_link',
        'content'            => 'content',
        'submission_date'    => 'submission_date',
    ]);
    $migrate('sm_submitted_homework_lists', 'sm_submitted_homework_lists', [
        'school_id_number'  => 'school_id_number',
        'homework_id_number'=> 'homework_id',
        'admission_number'  => 'admission_number',
        'document_link'     => 'document_link',
        'date_submitted'    => 'date_submitted',
    ]);
    $migrate('sm_student_attendances', 'sm_student_attendances', [
        'school_id_number'   => 'school_id_number',
        'numeric_class_name' => 'numeric_class_name',
        'session'            => 'session',
        'date_taken'         => 'date_taken',
        'admission_number'   => 'admission_number',
        'attendance_remark'  => 'attendance_remark',
        'comment'            => 'comment',
    ]);
    $migrate('sm_teacher_attendances', 'sm_teacher_attendances', [
        'school_id_number'   => 'school_id_number',
        'date_taken'         => 'date_taken',
        'teacher_id_number'  => 'teacher_id_number',
        'attendance_remark'  => 'attendance_remark',
        'comment'            => 'comment',
    ]);
    sse('step_done', ['label' => 'Homework / Notices / Attendance', 'count' => null]);
    sseProgress(9, 'Communications & attendance migrated ✓', 90);

    // ── Step 10: Addon Modules ───────────────────────────────
    sseProgress(10, 'Activating addon modules for schools…', 93);
    $addons = ['CBT' => 'sm_cbt_activated_schools', 'Bulk Report Card' => 'sm_bulk_report_card_activated_schools', 'Data Cleanup' => 'sm_cleanup_activated_schools'];
    $addonCount = 0;
    foreach ($addons as $addonName => $legTable) {
        try {
            $rows = $legacyPdo->query("SELECT school_id_number FROM `{$legTable}`")->fetchAll();
            $stmt = $pdo->prepare("INSERT INTO sm_school_addons (school_id_number, addon_name, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status='active'");
            foreach ($rows as $row) {
                $stmt->execute([$row['school_id_number'], $addonName]);
                $addonCount++;
            }
        } catch (Exception $e) { /* Table may not exist in legacy */ }
    }
    sse('step_done', ['label' => 'Addon Modules Activated', 'count' => $addonCount]);
    sseProgress(10, 'Addon modules restored ✓', 97);

    // ── Commit ───────────────────────────────────────────────
    $pdo->commit();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    sseProgress(10, 'Finalising…', 99);
    sse('done', ['results' => $results, 'errors' => $errors]);
    exit;
}

// ── AJAX: Test Connection Only ───────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'test') {
    header('Content-Type: application/json');
    $leg_host = trim($_POST['leg_host'] ?? '');
    $leg_port = (int)($_POST['leg_port'] ?? 3306);
    $leg_user = trim($_POST['leg_user'] ?? '');
    $leg_pass = $_POST['leg_pass'] ?? '';
    $leg_name = trim($_POST['leg_name'] ?? '');
    try {
        $dsn = "mysql:host={$leg_host};port={$leg_port};dbname={$leg_name};charset=utf8mb4";
        $pdo2 = new PDO($dsn, $leg_user, $leg_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Get school count
        $schools = (int)$pdo2->query("SELECT COUNT(*) FROM sm_school_details")->fetchColumn();
        $students = (int)$pdo2->query("SELECT COUNT(*) FROM sm_students")->fetchColumn();
        echo json_encode(['ok' => true, 'schools' => $schools, 'students' => $students]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Migration Wizard · SchoolApp</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #080d18;
    --surface:   rgba(255,255,255,0.04);
    --border:    rgba(255,255,255,0.09);
    --accent:    #4f7fff;
    --accent2:   #7c5cfc;
    --success:   #22c55e;
    --warning:   #f59e0b;
    --danger:    #ef4444;
    --text:      #e8ecf4;
    --muted:     #6b7694;
    --radius:    14px;
  }

  body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 32px 16px 60px;
    background-image:
      radial-gradient(ellipse 80% 50% at 20% -10%, rgba(79,127,255,.18) 0%, transparent 60%),
      radial-gradient(ellipse 60% 40% at 80% 110%, rgba(124,92,252,.14) 0%, transparent 55%);
  }

  /* ── Header ──────────────────────────────────── */
  .hdr { text-align:center; margin-bottom: 36px; }
  .hdr-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(79,127,255,.12); border: 1px solid rgba(79,127,255,.3);
    color: #93b4ff; font-size: 11px; font-weight:600; letter-spacing:.08em;
    padding: 4px 14px; border-radius: 99px; margin-bottom: 18px; text-transform:uppercase;
  }
  .hdr h1 {
    font-size: clamp(1.8rem, 4vw, 2.6rem);
    font-weight: 800;
    background: linear-gradient(135deg, #93b4ff 0%, #c4b5fd 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    line-height: 1.15; letter-spacing: -.02em;
  }
  .hdr p { color: var(--muted); font-size: 14px; margin-top: 8px; }

  /* ── Wizard card ─────────────────────────────── */
  .card {
    width: 100%; max-width: 760px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 36px;
    backdrop-filter: blur(20px);
    box-shadow: 0 32px 80px rgba(0,0,0,.45);
  }

  /* ── Step bar ────────────────────────────────── */
  .steps {
    display: flex; align-items: center; margin-bottom: 36px;
    gap: 0;
  }
  .step-item { flex: 1; display:flex; flex-direction:column; align-items:center; position:relative; }
  .step-item:not(:last-child)::after {
    content:''; position:absolute; top: 18px; left: calc(50% + 18px);
    width: calc(100% - 36px); height: 2px;
    background: var(--border); z-index:0;
  }
  .step-item.done:not(:last-child)::after { background: var(--accent); transition: background .4s; }
  .step-circle {
    width: 36px; height: 36px; border-radius: 50%;
    border: 2px solid var(--border);
    background: var(--bg);
    display: flex; align-items:center; justify-content:center;
    font-size: 12px; font-weight: 700; color: var(--muted);
    z-index: 1; transition: all .3s;
  }
  .step-item.active .step-circle { border-color: var(--accent); color: var(--accent); box-shadow: 0 0 0 6px rgba(79,127,255,.12); }
  .step-item.done .step-circle { background: var(--accent); border-color: var(--accent); color:#fff; }
  .step-label { font-size: 10px; font-weight:600; color: var(--muted); margin-top: 6px; text-align:center; letter-spacing:.04em; }
  .step-item.active .step-label, .step-item.done .step-label { color: var(--text); }

  /* ── Panels ──────────────────────────────────── */
  .panel { display:none; animation: fadeIn .35s ease; }
  .panel.active { display:block; }
  @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }

  /* ── Form elements ───────────────────────────── */
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .field { display:flex; flex-direction:column; gap:6px; }
  .field.full { grid-column: 1/-1; }
  label { font-size:12px; font-weight:600; color: var(--muted); letter-spacing:.04em; text-transform:uppercase; }
  input[type=text], input[type=number], input[type=password] {
    background: rgba(0,0,0,.25); border: 1px solid var(--border);
    border-radius: 10px; color: var(--text); font-size:14px; font-family:inherit;
    padding: 11px 14px; outline:none; width:100%;
    transition: border-color .2s, box-shadow .2s;
  }
  input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,127,255,.18); }
  input::placeholder { color: var(--muted); }

  /* ── Buttons ─────────────────────────────────── */
  .btn {
    display:inline-flex; align-items:center; gap:8px;
    font-family:inherit; font-size:14px; font-weight:700;
    padding: 12px 28px; border-radius: 10px; border:none; cursor:pointer;
    transition: all .2s; letter-spacing:.02em;
  }
  .btn-primary {
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    color: #fff; box-shadow: 0 4px 20px rgba(79,127,255,.35);
  }
  .btn-primary:hover { transform:translateY(-1px); box-shadow: 0 8px 28px rgba(79,127,255,.45); }
  .btn-primary:active { transform:translateY(0); }
  .btn-outline {
    background: transparent; border: 1px solid var(--border); color: var(--muted);
  }
  .btn-outline:hover { border-color: var(--accent); color: var(--text); }
  .btn-success { background: linear-gradient(135deg, #16a34a, #22c55e); color:#fff; }
  .btn:disabled { opacity:.5; cursor:not-allowed; transform:none !important; }

  /* ── Test-connection result ───────────────────── */
  .conn-status {
    margin-top: 14px; padding: 12px 16px; border-radius: 10px; font-size:13px;
    display:none; align-items:center; gap:10px;
    border: 1px solid transparent;
  }
  .conn-status.ok   { background: rgba(34,197,94,.08); border-color: rgba(34,197,94,.25); color: #86efac; display:flex; }
  .conn-status.fail { background: rgba(239,68,68,.08);  border-color: rgba(239,68,68,.25);  color: #fca5a5; display:flex; }

  /* ── Pre-flight card ─────────────────────────── */
  .pf-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:12px; margin-top:20px; }
  .pf-card {
    background: rgba(0,0,0,.25); border: 1px solid var(--border);
    border-radius: 12px; padding: 16px;
    text-align:center; transition: border-color .2s;
  }
  .pf-card:hover { border-color: rgba(79,127,255,.3); }
  .pf-num { font-size: 1.8rem; font-weight:800; color: var(--accent); line-height:1; }
  .pf-lbl { font-size: 11px; color: var(--muted); margin-top: 4px; font-weight:600; }

  /* ── Progress track ──────────────────────────── */
  .progress-wrap { margin: 28px 0; }
  .progress-hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
  .progress-label { font-size:13px; font-weight:600; color: var(--text); }
  .progress-pct { font-size:22px; font-weight:800; color: var(--accent); }
  .bar-track {
    height: 10px; background: rgba(255,255,255,.06);
    border-radius: 99px; overflow:hidden;
  }
  .bar-fill {
    height:100%; width:0%; border-radius:99px;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    transition: width .6s cubic-bezier(.22,1,.36,1);
    box-shadow: 0 0 12px rgba(79,127,255,.5);
  }

  /* ── Live step list ──────────────────────────── */
  .live-steps { list-style:none; display:flex; flex-direction:column; gap:8px; margin-top:20px; }
  .live-step {
    display:flex; align-items:center; gap:12px;
    padding: 12px 16px; border-radius: 10px;
    background: rgba(0,0,0,.2); border: 1px solid transparent;
    font-size:13px; font-weight:500;
    opacity:.35; transition: opacity .3s, border-color .3s, background .3s;
  }
  .live-step.running { opacity:1; border-color: rgba(79,127,255,.3); background: rgba(79,127,255,.06); }
  .live-step.done    { opacity:1; border-color: rgba(34,197,94,.25); background: rgba(34,197,94,.05); color: #86efac; }
  .live-step.error   { opacity:1; border-color: rgba(239,68,68,.25); background: rgba(239,68,68,.05); color: #fca5a5; }
  .live-step .icon { font-size:16px; flex-shrink:0; }
  .live-step .count { margin-left:auto; font-size:12px; color: var(--muted); font-weight:700; }
  .live-step.done .count { color: #4ade80; }

  /* ── Spinner ─────────────────────────────────── */
  @keyframes spin { to { transform:rotate(360deg); } }
  .spinner {
    width:16px; height:16px; border-radius:50%;
    border: 2px solid rgba(79,127,255,.3); border-top-color: var(--accent);
    animation: spin .7s linear infinite; flex-shrink:0;
  }

  /* ── Results grid ────────────────────────────── */
  .results-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin: 20px 0; }
  .res-card {
    background: rgba(0,0,0,.25); border: 1px solid var(--border);
    border-radius: 12px; padding: 14px 16px;
  }
  .res-card .tbl { font-size:11px; color: var(--muted); font-weight:600; margin-bottom:4px; text-transform:uppercase; }
  .res-card .cnt { font-size:1.3rem; font-weight:800; color: var(--success); }
  .res-card .cnt.err { color: var(--danger); }

  /* ── Error list ──────────────────────────────── */
  .err-list {
    background: rgba(239,68,68,.07); border: 1px solid rgba(239,68,68,.2);
    border-radius: 12px; padding: 16px; margin-top:16px;
  }
  .err-list h4 { color: #fca5a5; font-size:13px; margin-bottom:10px; }
  .err-list li { font-size:12px; color: #fca5a5; margin-left:16px; line-height:1.8; }

  /* ── Tip box ─────────────────────────────────── */
  .tip {
    background: rgba(245,158,11,.07); border: 1px solid rgba(245,158,11,.25);
    border-radius: 12px; padding: 14px 16px; font-size:13px; color: #fde68a;
    display:flex; gap:10px; line-height:1.6; margin-top:20px;
  }
  .tip-icon { font-size:18px; flex-shrink:0; }

  /* ── Divider ─────────────────────────────────── */
  .divider { border:none; border-top: 1px solid var(--border); margin: 28px 0; }

  /* ── Footer bar ──────────────────────────────── */
  .foot { display:flex; justify-content:space-between; align-items:center; margin-top:28px; }

  /* ── Section title ───────────────────────────── */
  .section-title { font-size:16px; font-weight:700; margin-bottom:16px; }
  .section-sub   { font-size:13px; color: var(--muted); margin-bottom:20px; line-height:1.6; }

  /* checkmark tick animation */
  @keyframes pop { 0%{transform:scale(0)} 70%{transform:scale(1.3)} 100%{transform:scale(1)} }
  .tick { display:inline-block; animation: pop .35s ease; }

  /* success banner */
  .success-banner {
    text-align:center; padding: 28px 0;
  }
  .success-icon {
    font-size: 3.5rem; margin-bottom: 16px; display:block;
    animation: pop .5s ease;
  }
  .success-banner h2 { font-size:1.6rem; font-weight:800; color: #86efac; }
  .success-banner p  { font-size:14px; color: var(--muted); margin-top:8px; }

  @media(max-width:600px) {
    .form-grid { grid-template-columns:1fr; }
    .card { padding: 24px 18px; }
    .steps { gap: 0; }
    .step-label { display:none; }
  }
</style>
</head>
<body>

<div class="hdr">
  <div class="hdr-badge">🚀 SchoolApp</div>
  <h1>Data Migration Wizard</h1>
  <p>Seamlessly move all schools, users, results, and modules from your old system</p>
</div>

<div class="card">

  <!-- ── Step Indicator ── -->
  <div class="steps" id="stepBar">
    <div class="step-item active" id="si1">
      <div class="step-circle">1</div>
      <div class="step-label">Connect</div>
    </div>
    <div class="step-item" id="si2">
      <div class="step-circle">2</div>
      <div class="step-label">Pre-flight</div>
    </div>
    <div class="step-item" id="si3">
      <div class="step-circle">3</div>
      <div class="step-label">Migrate</div>
    </div>
    <div class="step-item" id="si4">
      <div class="step-circle">4</div>
      <div class="step-label">Done</div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════ -->
  <!-- PANEL 1 – Connection details                -->
  <!-- ═══════════════════════════════════════════ -->
  <div class="panel active" id="panel1">
    <div class="section-title">🔌 Legacy Database Connection</div>
    <p class="section-sub">Enter the credentials of your <strong>old SchoolApp</strong> database. If it is on a different server, use the remote IP and make sure Remote MySQL is enabled on the old cPanel.</p>

    <div class="form-grid">
      <div class="field">
        <label>Database Host</label>
        <input type="text" id="leg_host" value="127.0.0.1" placeholder="127.0.0.1 or remote IP">
      </div>
      <div class="field">
        <label>Port</label>
        <input type="number" id="leg_port" value="3306" placeholder="3306">
      </div>
      <div class="field">
        <label>Username</label>
        <input type="text" id="leg_user" placeholder="db_user">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" id="leg_pass" placeholder="••••••••••">
      </div>
      <div class="field full">
        <label>Legacy Database Name</label>
        <input type="text" id="leg_name" placeholder="e.g. cpanel_schoolapp_old">
      </div>
    </div>

    <div class="conn-status" id="connStatus"></div>

    <div class="tip">
      <span class="tip-icon">💡</span>
      <span><strong>Different server?</strong> Log into your old cPanel → <em>Remote MySQL</em> → add your new server's IP address. Then use the old server's IP address as the host above.</span>
    </div>

    <div class="foot">
      <span></span>
      <div style="display:flex;gap:12px;">
        <button class="btn btn-outline" onclick="testConnection()">🔍 Test Connection</button>
        <button class="btn btn-primary" id="nextBtn1" onclick="goToPreFlight()" disabled>Next →</button>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════ -->
  <!-- PANEL 2 – Pre-flight summary                -->
  <!-- ═══════════════════════════════════════════ -->
  <div class="panel" id="panel2">
    <div class="section-title">📊 Pre-flight Summary</div>
    <p class="section-sub">We found the following records in your legacy database. Confirm everything looks right before starting the migration.</p>

    <div class="pf-grid" id="pfGrid"></div>

    <div class="tip" style="margin-top:24px;">
      <span class="tip-icon">📁</span>
      <span><strong>Media files (images, homework, reports)</strong> are NOT in the database — you must manually copy the <code>imgfile/</code>, <code>homework/</code>, and <code>dataimg/</code> folders via FTP or cPanel File Manager to the new server after migration.</span>
    </div>

    <hr class="divider">

    <p class="section-sub" style="margin:0;">By clicking <strong>Start Migration</strong> you confirm that you have backed up both databases and are ready to proceed.</p>

    <div class="foot">
      <button class="btn btn-outline" onclick="switchPanel(1)">← Back</button>
      <button class="btn btn-primary" onclick="startMigration()">🚀 Start Migration</button>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════ -->
  <!-- PANEL 3 – Live migration progress           -->
  <!-- ═══════════════════════════════════════════ -->
  <div class="panel" id="panel3">
    <div class="section-title">⚙️ Migration In Progress</div>
    <p class="section-sub">Please keep this window open. Do not refresh or navigate away.</p>

    <div class="progress-wrap">
      <div class="progress-hdr">
        <span class="progress-label" id="progressLabel">Initialising…</span>
        <span class="progress-pct"><span id="progressPct">0</span>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill" id="barFill"></div></div>
    </div>

    <ul class="live-steps" id="liveSteps">
      <li class="live-step" data-key="schools">       <span class="icon">🏫</span> Schools                   <span class="count">—</span></li>
      <li class="live-step" data-key="admins">        <span class="icon">👤</span> Admins &amp; Moderators    <span class="count">—</span></li>
      <li class="live-step" data-key="teachers">      <span class="icon">🧑‍🏫</span> Teachers                  <span class="count">—</span></li>
      <li class="live-step" data-key="parents">       <span class="icon">👨‍👩‍👧</span> Parents                    <span class="count">—</span></li>
      <li class="live-step" data-key="students">      <span class="icon">🎓</span> Students                  <span class="count">—</span></li>
      <li class="live-step" data-key="classes">       <span class="icon">📚</span> Classes &amp; Subjects      <span class="count">—</span></li>
      <li class="live-step" data-key="exams">         <span class="icon">📝</span> Exams / Results / CBT     <span class="count">—</span></li>
      <li class="live-step" data-key="logistics">     <span class="icon">🚌</span> Hostels / Library / Bus   <span class="count">—</span></li>
      <li class="live-step" data-key="finance">       <span class="icon">💰</span> Fees &amp; Payments         <span class="count">—</span></li>
      <li class="live-step" data-key="comms">         <span class="icon">📢</span> Homework / Notices / Att. <span class="count">—</span></li>
      <li class="live-step" data-key="addons">        <span class="icon">🧩</span> Addon Modules             <span class="count">—</span></li>
    </ul>
  </div>

  <!-- ═══════════════════════════════════════════ -->
  <!-- PANEL 4 – Results                           -->
  <!-- ═══════════════════════════════════════════ -->
  <div class="panel" id="panel4">
    <div class="success-banner">
      <span class="success-icon">🎉</span>
      <h2>Migration Complete!</h2>
      <p>All legacy data has been successfully imported into the new SchoolApp.</p>
    </div>

    <div class="results-grid" id="resultsGrid"></div>
    <div id="errorBlock"></div>

    <div class="tip">
      <span class="tip-icon">🔒</span>
      <span><strong>Security:</strong> Delete or rename <code>migrate_legacy.php</code> from your server now that migration is complete. Leaving it accessible is a security risk.</span>
    </div>

    <div class="tip" style="margin-top:12px;">
      <span class="tip-icon">📁</span>
      <span>Remember to copy your <code>imgfile/</code>, <code>homework/</code>, and <code>dataimg/</code> directories from the old server to the same paths on this server via FTP.</span>
    </div>

    <div class="foot" style="margin-top:28px;">
      <span></span>
      <a href="/dashboard.php" class="btn btn-success">Go to Dashboard →</a>
    </div>
  </div>

</div><!-- /.card -->

<script>
// ── State ─────────────────────────────────────────────────────
const creds = () => ({
  leg_host: document.getElementById('leg_host').value.trim(),
  leg_port: document.getElementById('leg_port').value.trim(),
  leg_user: document.getElementById('leg_user').value.trim(),
  leg_pass: document.getElementById('leg_pass').value,
  leg_name: document.getElementById('leg_name').value.trim(),
});

let connectionOk = false;

// ── Panel navigation ──────────────────────────────────────────
function switchPanel(n) {
  document.querySelectorAll('.panel').forEach((p,i) => p.classList.toggle('active', i+1 === n));
  document.querySelectorAll('.step-item').forEach((s,i) => {
    s.classList.toggle('active', i+1 === n);
    s.classList.toggle('done', i+1 < n);
  });
  window.scrollTo({top:0, behavior:'smooth'});
}

// ── Test connection ───────────────────────────────────────────
async function testConnection() {
  const status = document.getElementById('connStatus');
  status.className = 'conn-status';
  status.innerHTML = '<div class="spinner"></div> Testing connection…';
  status.style.display = 'flex';

  const fd = new FormData();
  Object.entries(creds()).forEach(([k,v]) => fd.append(k, v));

  try {
    const r = await fetch('?action=test', { method:'POST', body: fd });
    const d = await r.json();
    if (d.ok) {
      status.className = 'conn-status ok';
      status.innerHTML = `✅ Connected! Found <strong>${d.schools}</strong> school(s) and <strong>${d.students}</strong> student(s).`;
      connectionOk = true;
      document.getElementById('nextBtn1').disabled = false;
    } else {
      status.className = 'conn-status fail';
      status.innerHTML = `❌ ${d.error}`;
      connectionOk = false;
      document.getElementById('nextBtn1').disabled = true;
    }
  } catch(e) {
    status.className = 'conn-status fail';
    status.innerHTML = '❌ Network error: ' + e.message;
  }
}

// ── Pre-flight ────────────────────────────────────────────────
async function goToPreFlight() {
  if (!connectionOk) { alert('Please test the connection first.'); return; }

  switchPanel(2);

  const fd = new FormData();
  Object.entries(creds()).forEach(([k,v]) => fd.append(k, v));

  const r = await fetch('?action=test', { method:'POST', body: fd });
  const d = await r.json();

  const labels = {
    sm_school_details:    'Schools',
    sm_students:          'Students',
    sm_parents:           'Parents',
    sm_teachers:          'Teachers',
    sm_moderators:        'Admins',
    sm_results:           'Result Records',
    sm_exams:             'Exams',
    sm_fee_payment_lists: 'Payments',
  };

  // We reuse test endpoint data + show grid
  const grid = document.getElementById('pfGrid');
  grid.innerHTML = '';
  const entries = {
    'Schools': d.schools,
    'Students': d.students,
  };
  Object.entries(entries).forEach(([k, v]) => {
    grid.innerHTML += `<div class="pf-card"><div class="pf-num">${v.toLocaleString()}</div><div class="pf-lbl">${k}</div></div>`;
  });
  grid.innerHTML += `
    <div class="pf-card"><div class="pf-num">✓</div><div class="pf-lbl">Exams & Results</div></div>
    <div class="pf-card"><div class="pf-num">✓</div><div class="pf-lbl">Fee Payments</div></div>
    <div class="pf-card"><div class="pf-num">✓</div><div class="pf-lbl">CBT Schedules</div></div>
    <div class="pf-card"><div class="pf-num">✓</div><div class="pf-lbl">Addon Modules</div></div>
    <div class="pf-card"><div class="pf-num">✓</div><div class="pf-lbl">Hostels & Beds</div></div>
    <div class="pf-card"><div class="pf-num">✓</div><div class="pf-lbl">Library Books</div></div>
  `;
}

// ── Start migration (SSE) ─────────────────────────────────────
const stepKeyMap = {
  'Schools':                    'schools',
  'Admins / Moderators':        'admins',
  'Teachers':                   'teachers',
  'Parents':                    'parents',
  'Students':                   'students',
  'Classes & Subjects':         'classes',
  'Exams / Results / CBT':      'exams',
  'Hostels / Library / Transport': 'logistics',
  'Fees & Payments':            'finance',
  'Homework / Notices / Attendance': 'comms',
  'Addon Modules Activated':    'addons',
};

let currentRunningKey = null;

function getLiveStep(key) {
  return document.querySelector(`.live-step[data-key="${key}"]`);
}

function setStepRunning(key) {
  if (currentRunningKey && currentRunningKey !== key) {
    const prev = getLiveStep(currentRunningKey);
    if (prev && !prev.classList.contains('done')) {
      prev.classList.remove('running');
      prev.classList.add('done');
      prev.querySelector('.icon').textContent = '✅';
    }
  }
  currentRunningKey = key;
  const el = getLiveStep(key);
  if (el) {
    el.classList.add('running');
    const icon = el.querySelector('.icon');
    icon.innerHTML = '<div class="spinner"></div>';
  }
}

function setStepDone(label, count) {
  const key = stepKeyMap[label];
  if (!key) return;
  const el = getLiveStep(key);
  if (!el) return;
  el.classList.remove('running');
  el.classList.add('done');
  el.querySelector('.icon').innerHTML = '<span class="tick">✅</span>';
  if (count !== null && count !== undefined) {
    el.querySelector('.count').textContent = Number(count).toLocaleString() + ' rows';
  } else {
    el.querySelector('.count').textContent = '✓';
  }
  currentRunningKey = null;
}

// Map progress step numbers to live-step keys
const stepToKey = {3:'schools',4:'students',5:'classes',6:'exams',7:'logistics',8:'finance',9:'comms',10:'addons'};

async function startMigration() {
  switchPanel(3);

  const fd = new FormData();
  Object.entries(creds()).forEach(([k,v]) => fd.append(k, v));

  const queryStr = '?action=run';

  // Use fetch + ReadableStream to consume SSE
  const response = await fetch(queryStr, { method:'POST', body: fd });
  const reader   = response.body.getReader();
  const decoder  = new TextDecoder();
  let   buffer   = '';

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });

    const lines = buffer.split('\n\n');
    buffer = lines.pop(); // keep incomplete chunk

    for (const block of lines) {
      const match = block.match(/^data: (.+)$/m);
      if (!match) continue;
      let msg;
      try { msg = JSON.parse(match[1]); } catch(e) { continue; }

      if (msg.type === 'progress') {
        const { step, label, pct, count } = msg.data;
        document.getElementById('progressLabel').textContent = label;
        document.getElementById('progressPct').textContent = pct;
        document.getElementById('barFill').style.width = pct + '%';
        if (stepToKey[step]) setStepRunning(stepToKey[step]);
      }

      if (msg.type === 'step_done') {
        setStepDone(msg.data.label, msg.data.count);
      }

      if (msg.type === 'preflight') {
        // optional: use server-side counts
      }

      if (msg.type === 'error') {
        document.getElementById('progressLabel').textContent = '❌ ' + msg.data;
        document.getElementById('barFill').style.background = 'var(--danger)';
        // Still show results panel with error
        setTimeout(() => finishWithResults({}, [msg.data]), 1500);
        return;
      }

      if (msg.type === 'done') {
        document.getElementById('progressLabel').textContent = 'Migration complete! ✓';
        document.getElementById('progressPct').textContent = '100';
        document.getElementById('barFill').style.width = '100%';
        // finalize all live steps
        document.querySelectorAll('.live-step').forEach(el => {
          if (!el.classList.contains('done')) {
            el.classList.add('done');
            el.querySelector('.icon').innerHTML = '<span class="tick">✅</span>';
          }
        });
        setTimeout(() => finishWithResults(msg.data.results, msg.data.errors), 1200);
      }
    }
  }
}

function finishWithResults(results, errors) {
  switchPanel(4);

  const grid = document.getElementById('resultsGrid');
  grid.innerHTML = '';
  const friendlyNames = {
    sm_schools:                   'Schools',
    sm_teachers:                  'Teachers',
    sm_parents:                   'Parents',
    sm_students:                  'Students',
    sm_moderators:                'Admins',
    sm_results:                   'Results',
    sm_exams:                     'Exams',
    sm_cbt_schedules:             'CBT Tests',
    sm_fee_payment_lists:         'Payments',
    sm_student_attendances:       'Student Attend.',
    sm_teacher_attendances:       'Teacher Attend.',
    sm_homework_lists:            'Homework',
    sm_submitted_homework_lists:  'HW Submissions',
    sm_notices:                   'Notices',
    sm_book_lists:                'Books',
    sm_transports:                'Transport',
  };

  for (const [tbl, cnt] of Object.entries(results)) {
    const name = friendlyNames[tbl] || tbl.replace('sm_', '').replace(/_/g,' ');
    const isErr = cnt === 'ERROR';
    grid.innerHTML += `
      <div class="res-card">
        <div class="tbl">${name}</div>
        <div class="cnt ${isErr ? 'err':''}">
          ${isErr ? '⚠ Error' : (typeof cnt === 'number' ? cnt.toLocaleString() : cnt)}
        </div>
      </div>`;
  }

  const errBlock = document.getElementById('errorBlock');
  if (errors && errors.length) {
    errBlock.innerHTML = `<div class="err-list"><h4>⚠️ Non-fatal errors (${errors.length})</h4><ul>${errors.map(e=>`<li>${e}</li>`).join('')}</ul></div>`;
  }
}
</script>
</body>
</html>
