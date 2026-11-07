<?php
// Registry Management View (Admit Students, CSV Import, Parents, Teachers)
if (!defined('PORTAL_PATH')) exit;

$msg = '';
$msg_type = 'success';
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Restrict teachers to view-only actions and hide teacher profile details
$write_actions = ['admit', 'add_teacher', 'add_parent', 'csv', 'edit_student', 'edit_teacher', 'edit_parent', 'view_teacher'];
if ($user_role === 'teacher') {
    if (in_array($action, $write_actions)) {
        echo '<div class="p-6 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl max-w-xl mx-auto mt-6">⛔ Access Denied. You do not have permission to view or modify this registry area.</div>';
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo '<div class="p-6 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl max-w-xl mx-auto mt-6">⛔ Access Denied. POST requests are not allowed for teachers.</div>';
        return;
    }
}

// Helper to resolve student profile photo
function getStudentPhoto($stu, $school_id, $photo_map = []) {
    if (!empty($photo_map) && isset($photo_map[$stu['admission_number']])) {
        return $photo_map[$stu['admission_number']];
    }

    $photo_webp = 'dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.webp';
    $photo_png = 'dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.png';
    $photo_jpg = 'dataimg/student_' . $school_id . '_' . $stu['admission_number'] . '.jpg';

    if (file_exists(PORTAL_PATH . '/' . $photo_webp)) {
        return '/' . $photo_webp;
    } elseif (file_exists(PORTAL_PATH . '/' . $photo_png)) {
        return '/' . $photo_png;
    } elseif (file_exists(PORTAL_PATH . '/' . $photo_jpg)) {
        return '/' . $photo_jpg;
    }

    // Return inline SVG based on gender
    $gender = strtolower(trim($stu['gender'] ?? ''));
    if ($gender === 'female') {
        return 'data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%23ec4899\'><path d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 4c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm0 13.6c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z\'/></svg>';
    } else {
        return 'data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%233b82f6\'><path d=\'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 4c1.93 0 3.5 1.57 3.5 3.5S13.93 13 12 13s-3.5-1.57-3.5-3.5S10.07 6 12 6zm0 13.6c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z\'/></svg>';
    }
}

// Helper to render pagination controls
function renderPagination($total_records, $limit, $current_page, $tab_name) {
    $total_pages = ceil($total_records / $limit);
    if ($total_pages <= 1) return;

    $offset = ($current_page - 1) * $limit;
    $max_displayed_pages = 5;
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $start_page + $max_displayed_pages - 1);
    if ($end_page - $start_page < $max_displayed_pages - 1) {
        $start_page = max(1, $end_page - $max_displayed_pages + 1);
    }
    
    $getLink = function($pageNum) use ($tab_name) {
        $params = $_GET;
        $params['tab'] = $tab_name;
        $params['p'] = $pageNum;
        return '?' . http_build_query($params);
    };
    ?>
    <div class="flex flex-col sm:flex-row items-center justify-between border-t border-white/10 pt-4 mt-4 gap-3 text-xs sm:text-sm">
        <div class="text-gray-400">
            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_records); ?> of <?php echo $total_records; ?> entries
        </div>
        <div class="flex items-center gap-1">
            <!-- Previous Button -->
            <?php if ($current_page > 1) : ?>
                <a href="<?php echo htmlspecialchars($getLink($current_page - 1)); ?>" class="px-2 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg flex items-center justify-center transition">
                    ◀️ Prev
                </a>
            <?php else : ?>
                <span class="px-2 py-1.5 bg-white/5 border border-white/10 text-gray-500 rounded-lg cursor-not-allowed">
                    ◀️ Prev
                </span>
            <?php endif; ?>

            <!-- Page Numbers -->
            <?php for ($i = $start_page; $i <= $end_page; $i++) : ?>
                <a href="<?php echo htmlspecialchars($getLink($i)); ?>" class="px-3 py-1.5 border rounded-lg transition <?php echo ($i === $current_page) ? 'bg-blue-600 border-blue-500 text-white font-bold' : 'bg-white/5 border-white/10 text-gray-300 hover:bg-white/10'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <!-- Next Button -->
            <?php if ($current_page < $total_pages) : ?>
                <a href="<?php echo htmlspecialchars($getLink($current_page + 1)); ?>" class="px-2 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg flex items-center justify-center transition">
                    Next ▶️
                </a>
            <?php else : ?>
                <span class="px-2 py-1.5 bg-white/5 border border-white/10 text-gray-500 rounded-lg cursor-not-allowed">
                    Next ▶️
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Handle Registry POST Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['impersonate_user'])) {
        $target_id = trim($_POST['target_id']);
        $target_role = trim($_POST['target_role']); // 'student', 'teacher', or 'parent'

        $stmt = null;
        if ($target_role === 'student') {
            $stmt = $pdo->prepare("SELECT * FROM sm_students WHERE admission_number = ? AND school_id_number = ? LIMIT 1");
        } elseif ($target_role === 'teacher') {
            $stmt = $pdo->prepare("SELECT * FROM sm_teachers WHERE id_number = ? AND school_id_number = ? LIMIT 1");
        } elseif ($target_role === 'parent') {
            $stmt = $pdo->prepare("SELECT * FROM sm_parents WHERE id_number = ? AND school_id_number = ? LIMIT 1");
        }

        if ($stmt) {
            $stmt->execute([$target_id, $school_id]);
            $target_user = $stmt->fetch();
            if ($target_user) {
                // Save current school admin session details
                $_SESSION['school_admin_session'] = [
                    'user_session' => $_SESSION['user_session'],
                    'user_role'    => $_SESSION['user_role'],
                    'user_name'    => $_SESSION['user_name'],
                    'school_id'    => $_SESSION['school_id']
                ];

                $_SESSION['impersonated_user_role'] = $target_role;
                $_SESSION['impersonated_user_name'] = $target_role === 'parent' ? $target_user['fullname'] : ($target_user['firstname'] . ' ' . $target_user['lastname']);

                $_SESSION['user_session'] = !empty($target_user['email']) ? $target_user['email'] : ($target_user['admission_number'] ?? $target_user['id_number']);
                $_SESSION['user_role']    = $target_role;
                $_SESSION['user_name']    = $_SESSION['impersonated_user_name'] . ' (Impersonated)';

                echo '<script>window.location.href="dashboard.php";</script>';
                exit;
            } else {
                $msg = "Error: Target user not found or does not belong to this school.";
                $msg_type = 'error';
            }
        }
    }

    if (isset($_POST['admit_student'])) {
        $adm = trim($_POST['admission_number']);
        $last = trim($_POST['lastname']);
        $first = trim($_POST['firstname']);
        $email = trim($_POST['email']);
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $cls = trim($_POST['current_class']);
        $sess = trim($_POST['session']);

        if (empty($adm)) {
            $year = date('Y');
            // Find max sequence matching STU-yyyy-%
            $stmtMax = $pdo->prepare("SELECT admission_number FROM sm_students WHERE school_id_number = ? AND admission_number LIKE ? ORDER BY admission_number DESC LIMIT 1");
            $stmtMax->execute([$school_id, 'STU-' . $year . '-%']);
            $rowMax = $stmtMax->fetch();
            if ($rowMax) {
                $parts = explode('-', $rowMax['admission_number']);
                $num = intval(end($parts));
                $nextNum = $num + 1;
                $adm = 'STU-' . $year . '-' . sprintf("%04d", $nextNum);
            } else {
                $adm = 'STU-' . $year . '-0001';
            }
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO sm_students (admission_number, school_id_number, lastname, firstname, email, password, current_class, session) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$adm, $school_id, $last, $first, $email, $pass, $cls, $sess]);
            $msg = "Student admitted successfully! Admission Number: " . htmlspecialchars($adm);
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg_type = 'error';
            if ($e->getCode() == '23000' || strpos($e->getMessage(), '1062') !== false) {
                try {
                    // Determine what was duplicate: admission_number or email
                    $stmtCheckAdm = $pdo->prepare("SELECT school_id_number, firstname, lastname, current_class, session FROM sm_students WHERE admission_number = ? AND school_id_number = ?");
                    $stmtCheckAdm->execute([$adm, $school_id]);
                    $existingStuAdm = $stmtCheckAdm->fetch();

                    $stmtCheckEmail = $pdo->prepare("SELECT school_id_number, firstname, lastname, admission_number, current_class, session FROM sm_students WHERE email = ? AND school_id_number = ?");
                    $stmtCheckEmail->execute([$email, $school_id]);
                    $existingStuEmail = $stmtCheckEmail->fetch();

                    if ($existingStuAdm) {
                        $msg = "<strong>Admission Number Duplicate:</strong> A student named <strong>" . htmlspecialchars($existingStuAdm['firstname'] . ' ' . $existingStuAdm['lastname']) . "</strong> is already registered in your school with the admission number <strong>" . htmlspecialchars($adm) . "</strong> (Class: " . htmlspecialchars($existingStuAdm['current_class']) . ", Session: " . htmlspecialchars($existingStuAdm['session']) . ").<br><br>If this student is not showing up in the directory, please clear your active directory filters/search queries or check all pages of the student directory.";
                    } elseif ($existingStuEmail) {
                        $msg = "<strong>Email Already Registered:</strong> The email <strong>" . htmlspecialchars($email) . "</strong> is already assigned to student <strong>" . htmlspecialchars($existingStuEmail['firstname'] . ' ' . $existingStuEmail['lastname']) . "</strong> (Admission No: " . htmlspecialchars($existingStuEmail['admission_number']) . ") in your school.";
                    } else {
                        // Check other tables for email
                        $checkOtherEmail = $pdo->prepare("
                            SELECT 'teacher' as role, school_id_number, firstname, lastname FROM sm_teachers WHERE email = ? AND school_id_number = ?
                            UNION
                            SELECT 'parent' as role, school_id_number, fullname as firstname, '' as lastname FROM sm_parents WHERE email = ? AND school_id_number = ?
                            UNION
                            SELECT 'moderator' as role, school_id_number, fullname as firstname, '' as lastname FROM sm_moderators WHERE email = ? AND school_id_number = ?
                        ");
                        $checkOtherEmail->execute([$email, $school_id, $email, $school_id, $email, $school_id]);
                        $otherUser = $checkOtherEmail->fetch();
                        if ($otherUser) {
                            $roleName = ($otherUser['role'] === 'parent') ? 'Parent' : (($otherUser['role'] === 'teacher') ? 'Teacher' : 'Moderator');
                            $msg = "<strong>Email Already Registered:</strong> The email <strong>" . htmlspecialchars($email) . "</strong> is already assigned to a <strong>" . $roleName . "</strong> in your school.";
                        } else {
                            $msg = "<strong>Duplicate Entry Error:</strong> A record with this admission number or email already exists in your school database. Please verify the information entered.";
                        }
                    }
                } catch (Exception $e2) {
                    $msg = "Error admitting student: " . $e->getMessage();
                }
            } else {
                $msg = "Error admitting student: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['import_csv'])) {
        // Bulk CSV Import logic
        if (is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], "r");
            $header = fgetcsv($handle, 1000, ","); // Skip header
            $importedCount = 0;
            $pdo->beginTransaction();
            try {
                $rowNum = 1;
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $rowNum++;
                    if (count($data) >= 5) {
                        $adm = trim($data[0]);
                        $last = trim($data[1]);
                        $first = trim($data[2]);
                        $email = trim($data[3]);
                        $pass = password_hash(trim($data[4]), PASSWORD_DEFAULT);
                        $cls = isset($data[5]) ? trim($data[5]) : '';
                        $sess = isset($data[6]) ? trim($data[6]) : '2026/2027';

                        // Pre-check for duplicate admission number
                        $stmtCheckAdm = $pdo->prepare("SELECT school_id_number, firstname, lastname FROM sm_students WHERE admission_number = ?");
                        $stmtCheckAdm->execute([$adm]);
                        $existingStuAdm = $stmtCheckAdm->fetch();
                        if ($existingStuAdm) {
                            if ($existingStuAdm['school_id_number'] === $school_id) {
                                throw new Exception("Row {$rowNum}: Admission Number '{$adm}' is already registered to " . $existingStuAdm['firstname'] . " " . $existingStuAdm['lastname'] . " in your school.");
                            } else {
                                throw new Exception("Row {$rowNum}: Admission Number '{$adm}' is already registered in another school.");
                            }
                        }

                        // Pre-check for duplicate email
                        $stmtCheckEmail = $pdo->prepare("SELECT school_id_number, firstname, lastname, admission_number FROM sm_students WHERE email = ?");
                        $stmtCheckEmail->execute([$email]);
                        $existingStuEmail = $stmtCheckEmail->fetch();
                        if ($existingStuEmail) {
                            if ($existingStuEmail['school_id_number'] === $school_id) {
                                throw new Exception("Row {$rowNum}: Email '{$email}' is already registered to " . $existingStuEmail['firstname'] . " " . $existingStuEmail['lastname'] . " (Admission No: " . $existingStuEmail['admission_number'] . ") in your school.");
                            } else {
                                throw new Exception("Row {$rowNum}: Email '{$email}' is already registered in another school.");
                            }
                        }

                        $stmt = $pdo->prepare("INSERT INTO sm_students (admission_number, school_id_number, lastname, firstname, email, password, current_class, session) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$adm, $school_id, $last, $first, $email, $pass, $cls, $sess]);
                        $importedCount++;
                    }
                }
                $pdo->commit();
                $msg = "Imported {$importedCount} students successfully!";
                $msg_type = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "Bulk import failed: " . $e->getMessage();
                $msg_type = 'error';
            }
            fclose($handle);
        }
    } elseif (isset($_POST['add_teacher'])) {
        $first = trim($_POST['firstname']);
        $last = trim($_POST['lastname']);
        $email = strtolower(trim($_POST['email']));
        $password_raw = $_POST['password'];
        $class_post = $_POST['class'] ?? '';
        $class = is_array($class_post) ? implode("\n", array_map('trim', $class_post)) : trim($class_post);
        $phone = trim($_POST['phone_number']);
        $gender = trim($_POST['gender']);
        $dob = trim($_POST['dob']);
        $address = trim($_POST['home_address']);

        // Validation
        if (empty($first) || empty($last) || empty($email) || empty($password_raw)) {
            $msg = "All required fields (First name, Last name, Email, Password) must be filled.";
            $msg_type = 'error';
        } else {
            try {
                // Check if email already exists in teachers, parents, moderators, or students
                $checkEmail = $pdo->prepare("
                    SELECT email FROM sm_teachers WHERE email = ?
                    UNION
                    SELECT email FROM sm_parents WHERE email = ?
                    UNION
                    SELECT email FROM sm_moderators WHERE email = ?
                    UNION
                    SELECT email FROM sm_students WHERE email = ?
                ");
                $checkEmail->execute([$email, $email, $email, $email]);
                if ($checkEmail->fetch()) {
                    $msg = "Error: A user with this email address already exists.";
                    $msg_type = 'error';
                } else {
                    // Generate unique teacher ID using legacy format yyXXXX
                    $year = date('y');
                    $stmtMax = $pdo->prepare("SELECT id_number FROM sm_teachers WHERE id_number LIKE ? ORDER BY id_number DESC LIMIT 1");
                    $stmtMax->execute([$year . '%']);
                    $rowMax = $stmtMax->fetch();

                    if ($rowMax) {
                        $num = intval(substr($rowMax['id_number'], 2));
                        $nextNum = $num + 1;
                        $teacherId = $year . sprintf("%04d", $nextNum);
                    } else {
                        $teacherId = $year . "0001";
                    }

                    $pass = password_hash($password_raw, PASSWORD_DEFAULT);
                    $can_view_payments = isset($_POST['can_view_payments']) ? 1 : 0;
                    $is_sub_admin = isset($_POST['is_sub_admin']) ? 1 : 0;

                    $stmt = $pdo->prepare("INSERT INTO sm_teachers (id_number, school_id_number, lastname, firstname, email, password, class, phone_number, gender, dob, home_address, can_view_payments, is_sub_admin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$teacherId, $school_id, $last, $first, $email, $pass, $class, $phone, $gender, $dob, $address, $can_view_payments, $is_sub_admin]);

                    $msg = "🎉 <strong>Teacher Account Created Successfully!</strong><br/>
                            <div class='mt-2 p-3 bg-white/5 border border-white/10 rounded-xl space-y-2 text-sm text-gray-200'>
                                <p>👤 <strong>Teacher UserID:</strong> <code id='teacher-userid-copy' class='bg-black/30 px-2 py-0.5 rounded font-mono text-indigo-300 select-all'>{$teacherId}</code></p>
                                <p>📧 <strong>Email Address:</strong> <code class='bg-black/30 px-2 py-0.5 rounded font-mono text-gray-300 select-all'>{$email}</code></p>
                                <p class='text-xs text-gray-400 mt-1'>ℹ️ <em>Instruction: The teacher can access the portal using either their <strong>UserID</strong> or their <strong>Email Address</strong> along with their configured password. You can copy the UserID by double-clicking it.</em></p>
                            </div>";
                }
            } catch (PDOException $e) {
                $msg = "Error adding teacher: " . $e->getMessage();
                $msg_type = 'error';
            }
        }
    } elseif (isset($_POST['add_parent'])) {
        $fullname = trim($_POST['fullname']);
        $email = strtolower(trim($_POST['email']));
        $password_raw = $_POST['password'];
        $phone = trim($_POST['phone_number']);
        $address = trim($_POST['home_address']);
        $selected_students = isset($_POST['student_admissions']) ? $_POST['student_admissions'] : [];

        // Validation
        if (empty($fullname) || empty($email) || empty($password_raw)) {
            $msg = "All required fields (Full name, Email, Password) must be filled.";
            $msg_type = 'error';
        } else {
            try {
                // Check if email already exists
                $checkEmail = $pdo->prepare("
                    SELECT email FROM sm_teachers WHERE email = ?
                    UNION
                    SELECT email FROM sm_parents WHERE email = ?
                    UNION
                    SELECT email FROM sm_moderators WHERE email = ?
                    UNION
                    SELECT email FROM sm_students WHERE email = ?
                ");
                $checkEmail->execute([$email, $email, $email, $email]);
                if ($checkEmail->fetch()) {
                    $msg = "Error: A user with this email address already exists.";
                    $msg_type = 'error';
                } else {
                    $pdo->beginTransaction();

                    // Generate unique parent ID using legacy format yyXXXX
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

                    $pass = password_hash($password_raw, PASSWORD_DEFAULT);

                    $stmt = $pdo->prepare("INSERT INTO sm_parents (id_number, school_id_number, fullname, email, password, phone_number, home_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$parentId, $school_id, $fullname, $email, $pass, $phone, $address]);

                    // Link students
                    if (!empty($selected_students)) {
                        $stmtUpdate = $pdo->prepare("UPDATE sm_students SET parent_id_number = ? WHERE admission_number = ? AND school_id_number = ?");
                        foreach ($selected_students as $adm) {
                            $stmtUpdate->execute([$parentId, $adm, $school_id]);
                        }
                    }

                    $pdo->commit();
                    $msg = "Parent added successfully! Generated Parent ID: " . $parentId . " and linked to " . count($selected_students) . " children.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = "Error adding parent: " . $e->getMessage();
                $msg_type = 'error';
            }
        }
    } elseif (isset($_POST['update_student'])) {
        $adm = trim($_POST['admission_number']);
        $last = trim($_POST['lastname']);
        $first = trim($_POST['firstname']);
        $email = trim($_POST['email']);
        $cls = trim($_POST['current_class']);
        $sess = trim($_POST['session']);
        $pass = $_POST['password'];

        try {
            if (!empty($pass)) {
                $pass_hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE sm_students SET lastname = ?, firstname = ?, email = ?, current_class = ?, session = ?, password = ? WHERE admission_number = ? AND school_id_number = ?");
                $stmt->execute([$last, $first, $email, $cls, $sess, $pass_hash, $adm, $school_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE sm_students SET lastname = ?, firstname = ?, email = ?, current_class = ?, session = ? WHERE admission_number = ? AND school_id_number = ?");
                $stmt->execute([$last, $first, $email, $cls, $sess, $adm, $school_id]);
            }
            $msg = "Student details updated successfully!";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg_type = 'error';
            if ($e->getCode() == '23000' || strpos($e->getMessage(), '1062') !== false) {
                $stmtCheckEmail = $pdo->prepare("SELECT school_id_number, firstname, lastname, admission_number FROM sm_students WHERE email = ? AND admission_number != ?");
                $stmtCheckEmail->execute([$email, $adm]);
                $existingStuEmail = $stmtCheckEmail->fetch();
                if ($existingStuEmail) {
                    $msg = "<strong>Email Already Registered:</strong> The email <strong>" . htmlspecialchars($email) . "</strong> is already assigned to another student (<strong>" . htmlspecialchars($existingStuEmail['firstname'] . ' ' . $existingStuEmail['lastname']) . "</strong>, Admission No: " . htmlspecialchars($existingStuEmail['admission_number']) . ").";
                } else {
                    $msg = "<strong>Duplicate Entry:</strong> This email is already registered in the system.";
                }
            } else {
                $msg = "Error updating student: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_student_record'])) {
        $adm = trim($_POST['admission_number']);
        try {
            $stmt = $pdo->prepare("DELETE FROM sm_students WHERE admission_number = ? AND school_id_number = ?");
            $stmt->execute([$adm, $school_id]);
            $msg = "Student record deleted successfully.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Error deleting student: " . $e->getMessage();
            $msg_type = 'error';
        }
    } elseif (isset($_POST['update_teacher'])) {
        $id_num = trim($_POST['id_number']);
        $last = trim($_POST['lastname']);
        $first = trim($_POST['firstname']);
        $email = trim($_POST['email']);
        $class_post = $_POST['class'] ?? '';
        $class = is_array($class_post) ? implode("\n", array_map('trim', $class_post)) : trim($class_post);
        $phone = trim($_POST['phone_number']);
        $gender = trim($_POST['gender']);
        $dob = trim($_POST['dob']);
        $address = trim($_POST['home_address']);
        $can_view_payments = isset($_POST['can_view_payments']) ? 1 : 0;
        $is_sub_admin = isset($_POST['is_sub_admin']) ? 1 : 0;
        $pass = $_POST['password'] ?? '';

        try {
            if (!empty($pass)) {
                $pass_hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE sm_teachers SET lastname = ?, firstname = ?, email = ?, class = ?, phone_number = ?, gender = ?, dob = ?, home_address = ?, password = ?, can_view_payments = ?, is_sub_admin = ? WHERE id_number = ? AND school_id_number = ?");
                $stmt->execute([$last, $first, $email, $class, $phone, $gender, $dob, $address, $pass_hash, $can_view_payments, $is_sub_admin, $id_num, $school_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE sm_teachers SET lastname = ?, firstname = ?, email = ?, class = ?, phone_number = ?, gender = ?, dob = ?, home_address = ?, can_view_payments = ?, is_sub_admin = ? WHERE id_number = ? AND school_id_number = ?");
                $stmt->execute([$last, $first, $email, $class, $phone, $gender, $dob, $address, $can_view_payments, $is_sub_admin, $id_num, $school_id]);
            }
            $msg = "Teacher details updated successfully!";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg_type = 'error';
            if ($e->getCode() == '23000' || strpos($e->getMessage(), '1062') !== false) {
                $msg = "<strong>Email Already Registered:</strong> The email <strong>" . htmlspecialchars($email) . "</strong> is already registered to another user.";
            } else {
                $msg = "Error updating teacher: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_teacher_record'])) {
        $id_num = trim($_POST['id_number']);
        try {
            $stmt = $pdo->prepare("DELETE FROM sm_teachers WHERE id_number = ? AND school_id_number = ?");
            $stmt->execute([$id_num, $school_id]);
            $msg = "Teacher record deleted successfully.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Error deleting teacher: " . $e->getMessage();
            $msg_type = 'error';
        }
    } elseif (isset($_POST['update_parent'])) {
        $id_num = trim($_POST['id_number']);
        $fullname = trim($_POST['fullname']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $address = trim($_POST['home_address']);
        $pass = $_POST['password'];

        try {
            if (!empty($pass)) {
                $pass_hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE sm_parents SET fullname = ?, email = ?, phone_number = ?, home_address = ?, password = ? WHERE id_number = ? AND school_id_number = ?");
                $stmt->execute([$fullname, $email, $phone, $address, $pass_hash, $id_num, $school_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE sm_parents SET fullname = ?, email = ?, phone_number = ?, home_address = ? WHERE id_number = ? AND school_id_number = ?");
                $stmt->execute([$fullname, $email, $phone, $address, $id_num, $school_id]);
            }
            
            $selected_students = isset($_POST['student_admissions']) ? $_POST['student_admissions'] : [];
            $pdo->prepare("UPDATE sm_students SET parent_id_number = NULL WHERE parent_id_number = ? AND school_id_number = ?")->execute([$id_num, $school_id]);
            if (!empty($selected_students)) {
                $stmtUpdate = $pdo->prepare("UPDATE sm_students SET parent_id_number = ? WHERE admission_number = ? AND school_id_number = ?");
                foreach ($selected_students as $adm) {
                    $stmtUpdate->execute([$id_num, $adm, $school_id]);
                }
            }
            $msg = "Parent details updated successfully!";
            $msg_type = 'success';
        } catch (Exception $e) {
            $msg_type = 'error';
            if ($e->getCode() == '23000' || strpos($e->getMessage(), '1062') !== false) {
                $msg = "<strong>Email Already Registered:</strong> The email <strong>" . htmlspecialchars($email) . "</strong> is already registered to another user.";
            } else {
                $msg = "Error updating parent: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_parent_record'])) {
        $id_num = trim($_POST['id_number']);
        try {
            $pdo->prepare("UPDATE sm_students SET parent_id_number = NULL WHERE parent_id_number = ? AND school_id_number = ?")->execute([$id_num, $school_id]);
            $stmt = $pdo->prepare("DELETE FROM sm_parents WHERE id_number = ? AND school_id_number = ?");
            $stmt->execute([$id_num, $school_id]);
            $msg = "Parent record deleted successfully.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Error deleting parent: " . $e->getMessage();
            $msg_type = 'error';
        }
    }
}

// Fetch lists based on active view
if ($action === 'list') {
    $active_tab = $_GET['tab'] ?? 'students';
    if ($user_role === 'teacher') {
        $active_tab = ($active_tab === 'teachers') ? 'students' : $active_tab;
    }
    $limit = 25;
    $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $offset = ($current_page - 1) * $limit;

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_class = isset($_GET['filter_class']) ? trim($_GET['filter_class']) : '';
    $filter_session = isset($_GET['filter_session']) ? trim($_GET['filter_session']) : '';

    // Retrieve teacher's assigned classes
    $teacher_classes = [];
    if ($user_role === 'teacher') {
        $stmtTInfo = $pdo->prepare("SELECT class FROM sm_teachers WHERE (email = ? OR id_number = ?) AND school_id_number = ? LIMIT 1");
        $stmtTInfo->execute([$_SESSION['user_session'], $_SESSION['user_session'], $school_id]);
        $t_classes_str = $stmtTInfo->fetchColumn();
        $teacher_classes = array_filter(array_map('trim', explode("\n", $t_classes_str ?? '')));
    }

    // --- Student Queries ---
    $student_where_clauses = ["school_id_number = ?"];
    $student_params = [$school_id];
    if ($user_role === 'teacher') {
        if (empty($teacher_classes)) {
            $student_where_clauses[] = "1 = 0";
        } else {
            $placeholders = implode(',', array_fill(0, count($teacher_classes), '?'));
            $student_where_clauses[] = "current_class IN ($placeholders)";
            $student_params = array_merge($student_params, $teacher_classes);
        }
    }
    if ($filter_class !== '') {
        $student_where_clauses[] = "current_class = ?";
        $student_params[] = $filter_class;
    }
    if ($filter_session !== '') {
        $student_where_clauses[] = "session = ?";
        $student_params[] = $filter_session;
    }
    if ($search !== '') {
        $student_where_clauses[] = "(firstname LIKE ? OR lastname LIKE ? OR admission_number LIKE ? OR email LIKE ?)";
        $search_param = '%' . $search . '%';
        $student_params[] = $search_param;
        $student_params[] = $search_param;
        $student_params[] = $search_param;
        $student_params[] = $search_param;
    }
    $student_where_sql = implode(' AND ', $student_where_clauses);

    $stmtCountStu = $pdo->prepare("SELECT COUNT(*) FROM sm_students WHERE $student_where_sql");
    $stmtCountStu->execute($student_params);
    $total_students = $stmtCountStu->fetchColumn();

    $stmtStu = $pdo->prepare("SELECT * FROM sm_students WHERE $student_where_sql LIMIT ? OFFSET ?");
    $bind_idx = 1;
    foreach ($student_params as $val) {
        $stmtStu->bindValue($bind_idx++, $val, PDO::PARAM_STR);
    }
    $stmtStu->bindValue($bind_idx++, $limit, PDO::PARAM_INT);
    $stmtStu->bindValue($bind_idx++, $offset, PDO::PARAM_INT);
    $stmtStu->execute();
    $students = $stmtStu->fetchAll();

    // --- Teacher Queries ---
    $teacher_where_clauses = ["school_id_number = ?"];
    $teacher_params = [$school_id];
    if ($filter_class !== '') {
        $teacher_where_clauses[] = "(class = ? OR class LIKE ? OR class LIKE ? OR class LIKE ?)";
        $teacher_params[] = $filter_class;
        $teacher_params[] = $filter_class . "\n%";
        $teacher_params[] = "%\n" . $filter_class;
        $teacher_params[] = "%\n" . $filter_class . "\n%";
    }
    if ($search !== '') {
        $teacher_where_clauses[] = "(firstname LIKE ? OR lastname LIKE ? OR id_number LIKE ? OR email LIKE ? OR phone_number LIKE ?)";
        $search_param = '%' . $search . '%';
        $teacher_params[] = $search_param;
        $teacher_params[] = $search_param;
        $teacher_params[] = $search_param;
        $teacher_params[] = $search_param;
        $teacher_params[] = $search_param;
    }
    $teacher_where_sql = implode(' AND ', $teacher_where_clauses);

    $stmtCountTea = $pdo->prepare("SELECT COUNT(*) FROM sm_teachers WHERE $teacher_where_sql");
    $stmtCountTea->execute($teacher_params);
    $total_teachers = $stmtCountTea->fetchColumn();

    $stmtTea = $pdo->prepare("SELECT * FROM sm_teachers WHERE $teacher_where_sql LIMIT ? OFFSET ?");
    $bind_idx = 1;
    foreach ($teacher_params as $val) {
        $stmtTea->bindValue($bind_idx++, $val, PDO::PARAM_STR);
    }
    $stmtTea->bindValue($bind_idx++, $limit, PDO::PARAM_INT);
    $stmtTea->bindValue($bind_idx++, $offset, PDO::PARAM_INT);
    $stmtTea->execute();
    $teachers = $stmtTea->fetchAll();

    // --- Parent Queries ---
    $parent_where_clauses = ["p.school_id_number = ?"];
    $parent_params = [$school_id];
    if ($user_role === 'teacher') {
        if (empty($teacher_classes)) {
            $parent_where_clauses[] = "1 = 0";
        } else {
            $placeholders = implode(',', array_fill(0, count($teacher_classes), '?'));
            $parent_where_clauses[] = "s.current_class IN ($placeholders)";
            $parent_params = array_merge($parent_params, $teacher_classes);
        }
    }
    if ($search !== '') {
        $parent_where_clauses[] = "(p.fullname LIKE ? OR p.email LIKE ? OR p.id_number LIKE ? OR p.phone_number LIKE ? OR s.firstname LIKE ? OR s.lastname LIKE ? OR s.admission_number LIKE ?)";
        $search_param = '%' . $search . '%';
        $parent_params[] = $search_param;
        $parent_params[] = $search_param;
        $parent_params[] = $search_param;
        $parent_params[] = $search_param;
        $parent_params[] = $search_param;
        $parent_params[] = $search_param;
        $parent_params[] = $search_param;
    }
    $parent_where_sql = implode(' AND ', $parent_where_clauses);

    $stmtCountPar = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id_number) 
        FROM sm_parents p
        LEFT JOIN sm_students s ON s.parent_id_number = p.id_number
        WHERE $parent_where_sql
    ");
    $stmtCountPar->execute($parent_params);
    $total_parents = $stmtCountPar->fetchColumn();

    $stmtPar = $pdo->prepare("
        SELECT p.*, GROUP_CONCAT(CONCAT(s.firstname, ' ', s.lastname) SEPARATOR ', ') as children_names 
        FROM sm_parents p 
        LEFT JOIN sm_students s ON s.parent_id_number = p.id_number 
        WHERE $parent_where_sql
        GROUP BY p.id_number
        LIMIT ? OFFSET ?
    ");
    $bind_idx = 1;
    foreach ($parent_params as $val) {
        $stmtPar->bindValue($bind_idx++, $val, PDO::PARAM_STR);
    }
    $stmtPar->bindValue($bind_idx++, $limit, PDO::PARAM_INT);
    $stmtPar->bindValue($bind_idx++, $offset, PDO::PARAM_INT);
    $stmtPar->execute();
    $parents = $stmtPar->fetchAll();

    // Fetch classes and sessions for filter inputs
    $stmtClasses = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? ORDER BY class_name");
    $stmtClasses->execute([$school_id]);
    $school_classes = $stmtClasses->fetchAll();

    $stmtSessions = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC");
    $stmtSessions->execute([$school_id]);
    $school_sessions = $stmtSessions->fetchAll();

    // Cache existing student photos
    $photo_map = [];
    $photo_files = glob(PORTAL_PATH . "/dataimg/student_{$school_id}_*");
    if ($photo_files) {
        foreach ($photo_files as $file) {
            $basename = basename($file);
            $prefix = "student_{$school_id}_";
            if (strpos($basename, $prefix) === 0) {
                $temp = substr($basename, strlen($prefix));
                $last_dot = strrpos($temp, '.');
                if ($last_dot !== false) {
                    $adm = substr($temp, 0, $last_dot);
                    $photo_map[$adm] = "/dataimg/" . $basename;
                }
            }
        }
    }
} else {
    // Fetch registered classes and sessions for select dropdown inputs
    $stmtClasses = $pdo->prepare("SELECT numeric_class_name, class_name FROM sm_classes WHERE school_id_number = ? ORDER BY class_name");
    $stmtClasses->execute([$school_id]);
    $school_classes = $stmtClasses->fetchAll();

    $stmtSessions = $pdo->prepare("SELECT session_name FROM sm_sessions WHERE school_id_number = ? ORDER BY id DESC");
    $stmtSessions->execute([$school_id]);
    $school_sessions = $stmtSessions->fetchAll();

    if ($action === 'add_parent') {
        // Fetch unlinked students for link checklist
        $stmtUnlinked = $pdo->prepare("SELECT admission_number, firstname, lastname, current_class FROM sm_students WHERE school_id_number = ? AND parent_id_number IS NULL ORDER BY lastname, firstname");
        $stmtUnlinked->execute([$school_id]);
        $unlinked_students = $stmtUnlinked->fetchAll();
    }
}
?>

<div class="space-y-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">👥 User Registry</h2>
        <div class="flex overflow-x-auto gap-2 w-full md:w-auto p-1 scrollbar-thin">
            <a href="?page=registry&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $action === 'list' ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10'; ?>">Registry Directory</a>
            <?php if ($user_role !== 'teacher') : ?>
                <a href="?page=registry&action=admit" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $action === 'admit' ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10'; ?>">Admit Student</a>
                <a href="?page=registry&action=add_teacher" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $action === 'add_teacher' ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10'; ?>">Add Teacher</a>
                <a href="?page=registry&action=add_parent" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $action === 'add_parent' ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10'; ?>">Add Parent</a>
                <a href="?page=registry&action=csv" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-lg text-sm font-semibold transition <?php echo $action === 'csv' ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10'; ?>">Bulk CSV Import</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 <?php echo (isset($msg_type) && $msg_type === 'error') ? 'bg-red-500/10 border border-red-500/20 text-red-400' : 'bg-blue-500/10 border border-blue-500/20 text-blue-400'; ?> rounded-xl text-sm">
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list') : ?>
        <!-- Tabbed Directory Card -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <!-- Tabs Header -->
            <div class="flex border-b border-white/10 mb-6 gap-2 overflow-x-auto">
                <button onclick="switchTab('students')" id="tab-btn-students" class="flex-shrink-0 py-2.5 px-4 font-semibold text-sm border-b-2 <?php echo $active_tab === 'students' ? 'border-blue-500 text-blue-400' : 'border-transparent text-gray-400 hover:text-white'; ?> transition whitespace-nowrap">
                    🎓 Students (<?php echo $total_students; ?>)
                </button>
                <?php if ($user_role !== 'teacher') : ?>
                    <button onclick="switchTab('teachers')" id="tab-btn-teachers" class="flex-shrink-0 py-2.5 px-4 font-semibold text-sm border-b-2 <?php echo $active_tab === 'teachers' ? 'border-blue-500 text-blue-400' : 'border-transparent text-gray-400 hover:text-white'; ?> transition whitespace-nowrap">
                        🧑‍🏫 Faculty/Teachers (<?php echo $total_teachers; ?>)
                    </button>
                <?php endif; ?>
                <button onclick="switchTab('parents')" id="tab-btn-parents" class="flex-shrink-0 py-2.5 px-4 font-semibold text-sm border-b-2 <?php echo $active_tab === 'parents' ? 'border-blue-500 text-blue-400' : 'border-transparent text-gray-400 hover:text-white'; ?> transition whitespace-nowrap">
                    👨‍👩‍👧 Parents (<?php echo $total_parents; ?>)
                </button>
            </div>

            <!-- Students Section -->
            <div id="tab-content-students" class="tab-pane <?php echo $active_tab === 'students' ? '' : 'hidden'; ?>">
                <h3 class="text-lg font-semibold mb-4 text-blue-300">Student Profiles</h3>
                
                <!-- Search & Filters -->
                <form method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
                    <input type="hidden" name="page" value="registry" />
                    <input type="hidden" name="action" value="list" />
                    <input type="hidden" name="tab" value="students" />
                    <div>
                        <input type="text" name="search" placeholder="🔍 Search name, adm no..." value="<?php echo htmlspecialchars($search); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <select name="filter_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <option value="">All Classes</option>
                            <?php foreach ($school_classes as $cls) : ?>
                                <option value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" <?php echo $filter_class === $cls['numeric_class_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cls['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <select name="filter_session" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <option value="">All Sessions</option>
                            <?php foreach ($school_sessions as $sess) : ?>
                                <option value="<?php echo htmlspecialchars($sess['session_name']); ?>" <?php echo $filter_session === $sess['session_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sess['session_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition">
                            Filter
                        </button>
                        <a href="?page=registry&action=list&tab=students" class="px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-sm transition flex items-center justify-center" title="Reset">
                            Reset
                        </a>
                    </div>
                </form>

                <?php if (empty($students)) : ?>
                    <p class="text-gray-400 text-sm">No students admitted yet.</p>
                <?php else : ?>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[800px] text-left text-sm text-gray-300">
                            <thead>
                                <tr class="border-b border-white/10 text-gray-400">
                                    <th class="py-2.5 w-12">Photo</th>
                                    <th class="py-2.5">Admission No.</th>
                                    <th class="py-2.5">Name</th>
                                    <th class="py-2.5">Class</th>
                                    <th class="py-2.5">Email</th>
                                    <th class="py-2.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $stu) : ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5">
                                        <td class="py-3">
                                            <img src="<?php echo getStudentPhoto($stu, $school_id, $photo_map); ?>" class="w-8 h-8 rounded-full object-cover border border-white/10" alt="avatar" />
                                        </td>
                                        <td class="py-3 font-semibold text-white"><?php echo htmlspecialchars($stu['admission_number']); ?></td>
                                        <td class="py-3"><?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname']); ?></td>
                                        <td class="py-3"><?php echo htmlspecialchars($stu['current_class']); ?></td>
                                        <td class="py-3 text-xs"><?php echo htmlspecialchars($stu['email']); ?></td>
                                        <td class="py-3 text-right">
                                            <div class="inline-flex gap-1.5 items-center">
                                                <?php if ($user_role !== 'teacher') : ?>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="target_id" value="<?php echo htmlspecialchars($stu['admission_number']); ?>" />
                                                        <input type="hidden" name="target_role" value="student" />
                                                        <button type="submit" name="impersonate_user" class="px-2 py-1 bg-indigo-650/20 hover:bg-indigo-600/30 text-indigo-300 rounded text-xs">🔑 Login As</button>
                                                    </form>
                                                <?php endif; ?>
                                                <a href="?page=registry&action=view_student&adm=<?php echo urlencode($stu['admission_number']); ?>" class="px-2 py-1 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded text-xs">🔍 View</a>
                                                <?php if ($user_role !== 'teacher') : ?>
                                                    <a href="?page=registry&action=edit_student&adm=<?php echo urlencode($stu['admission_number']); ?>" class="px-2 py-1 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded text-xs">✏️ Edit</a>
                                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete student <?php echo htmlspecialchars($stu['firstname']); ?>?');" class="inline">
                                                        <input type="hidden" name="admission_number" value="<?php echo htmlspecialchars($stu['admission_number']); ?>" />
                                                        <button type="submit" name="delete_student_record" class="px-2 py-1 bg-red-600/20 hover:bg-red-600/30 text-red-300 rounded text-xs">🗑️ Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php renderPagination($total_students, $limit, $current_page, 'students'); ?>
            </div>

            <!-- Teachers Section -->
            <div id="tab-content-teachers" class="tab-pane <?php echo $active_tab === 'teachers' ? '' : 'hidden'; ?>">
                <h3 class="text-lg font-semibold mb-4 text-indigo-300">Faculty/Teachers</h3>

                <!-- Search & Filters -->
                <form method="get" class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                    <input type="hidden" name="page" value="registry" />
                    <input type="hidden" name="action" value="list" />
                    <input type="hidden" name="tab" value="teachers" />
                    <div>
                        <input type="text" name="search" placeholder="🔍 Search name, ID, email..." value="<?php echo htmlspecialchars($search); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <select name="filter_class" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <option value="">All Form Classes</option>
                            <?php foreach ($school_classes as $cls) : ?>
                                <option value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" <?php echo $filter_class === $cls['numeric_class_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cls['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition">
                            Filter
                        </button>
                        <a href="?page=registry&action=list&tab=teachers" class="px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-sm transition flex items-center justify-center" title="Reset">
                            Reset
                        </a>
                    </div>
                </form>

                <?php if (empty($teachers)) : ?>
                    <p class="text-gray-400 text-sm">No teachers registered yet.</p>
                <?php else : ?>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[800px] text-left text-sm text-gray-300">
                            <thead>
                                <tr class="border-b border-white/10 text-gray-400">
                                    <th class="py-2.5">Teacher ID</th>
                                    <th class="py-2.5">Name</th>
                                    <th class="py-2.5">Class/Form</th>
                                    <th class="py-2.5">Email</th>
                                    <th class="py-2.5">Phone Number</th>
                                    <th class="py-2.5">Gender</th>
                                    <th class="py-2.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teachers as $t) : ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5">
                                        <td class="py-3 font-semibold text-white font-mono"><?php echo htmlspecialchars($t['id_number']); ?></td>
                                        <td class="py-3 font-semibold text-white"><?php echo htmlspecialchars($t['firstname'] . ' ' . $t['lastname']); ?></td>
                                        <td class="py-3"><?php echo htmlspecialchars($t['class'] ?? 'None'); ?></td>
                                        <td class="py-3 text-xs"><?php echo htmlspecialchars($t['email']); ?></td>
                                        <td class="py-3 text-xs"><?php echo htmlspecialchars($t['phone_number'] ?? 'N/A'); ?></td>
                                        <td class="py-3"><?php echo htmlspecialchars($t['gender'] ?? 'N/A'); ?></td>
                                        <td class="py-3 text-right">
                                            <div class="inline-flex gap-1.5 items-center">
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="target_id" value="<?php echo htmlspecialchars($t['id_number']); ?>" />
                                                    <input type="hidden" name="target_role" value="teacher" />
                                                    <button type="submit" name="impersonate_user" class="px-2 py-1 bg-indigo-650/20 hover:bg-indigo-600/30 text-indigo-300 rounded text-xs">🔑 Login As</button>
                                                </form>
                                                <a href="?page=registry&action=view_teacher&id=<?php echo urlencode($t['id_number']); ?>" class="px-2 py-1 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded text-xs">🔍 View</a>
                                                <a href="?page=registry&action=edit_teacher&id=<?php echo urlencode($t['id_number']); ?>" class="px-2 py-1 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded text-xs">✏️ Edit</a>
                                                <form method="post" onsubmit="return confirm('Are you sure you want to delete teacher <?php echo htmlspecialchars($t['firstname']); ?>?');" class="inline">
                                                    <input type="hidden" name="id_number" value="<?php echo htmlspecialchars($t['id_number']); ?>" />
                                                    <button type="submit" name="delete_teacher_record" class="px-2 py-1 bg-red-600/20 hover:bg-red-600/30 text-red-300 rounded text-xs">🗑️ Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php renderPagination($total_teachers, $limit, $current_page, 'teachers'); ?>
            </div>

            <!-- Parents Section -->
            <div id="tab-content-parents" class="tab-pane <?php echo $active_tab === 'parents' ? '' : 'hidden'; ?>">
                <h3 class="text-lg font-semibold mb-4 text-emerald-300">Parent Profiles</h3>

                <!-- Search & Filters -->
                <form method="get" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    <input type="hidden" name="page" value="registry" />
                    <input type="hidden" name="action" value="list" />
                    <input type="hidden" name="tab" value="parents" />
                    <div>
                        <input type="text" name="search" placeholder="🔍 Search name, child name, email..." value="<?php echo htmlspecialchars($search); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition">
                            Search
                        </button>
                        <a href="?page=registry&action=list&tab=parents" class="px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-gray-300 rounded-lg text-sm transition flex items-center justify-center" title="Reset">
                            Reset
                        </a>
                    </div>
                </form>

                <?php if (empty($parents)) : ?>
                    <p class="text-gray-400 text-sm">No parents registered yet.</p>
                <?php else : ?>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[850px] text-left text-sm text-gray-300">
                            <thead>
                                <tr class="border-b border-white/10 text-gray-400">
                                    <th class="py-2.5">Parent ID</th>
                                    <th class="py-2.5">Name</th>
                                    <th class="py-2.5">Email</th>
                                    <th class="py-2.5">Phone Number</th>
                                    <th class="py-2.5">Linked Children</th>
                                    <th class="py-2.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parents as $p) : ?>
                                    <tr class="border-b border-white/5 hover:bg-white/5">
                                        <td class="py-3 font-semibold text-white font-mono"><?php echo htmlspecialchars($p['id_number']); ?></td>
                                        <td class="py-3 font-semibold text-white"><?php echo htmlspecialchars($p['fullname']); ?></td>
                                        <td class="py-3 text-xs"><?php echo htmlspecialchars($p['email']); ?></td>
                                        <td class="py-3 text-xs"><?php echo htmlspecialchars($p['phone_number'] ?? 'N/A'); ?></td>
                                        <td class="py-3 text-xs text-blue-300 max-w-xs truncate" title="<?php echo htmlspecialchars($p['children_names'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($p['children_names'] ?? 'None'); ?>
                                        </td>
                                        <td class="py-3 text-right">
                                            <div class="inline-flex gap-1.5 items-center">
                                                <?php if ($user_role !== 'teacher') : ?>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="target_id" value="<?php echo htmlspecialchars($p['id_number']); ?>" />
                                                        <input type="hidden" name="target_role" value="parent" />
                                                        <button type="submit" name="impersonate_user" class="px-2 py-1 bg-indigo-650/20 hover:bg-indigo-600/30 text-indigo-300 rounded text-xs">🔑 Login As</button>
                                                    </form>
                                                <?php endif; ?>
                                                <a href="?page=registry&action=view_parent&id=<?php echo urlencode($p['id_number']); ?>" class="px-2 py-1 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded text-xs">🔍 View</a>
                                                <?php if ($user_role !== 'teacher') : ?>
                                                    <a href="?page=registry&action=edit_parent&id=<?php echo urlencode($p['id_number']); ?>" class="px-2 py-1 bg-blue-600/20 hover:bg-blue-600/30 text-blue-300 rounded text-xs">✏️ Edit</a>
                                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete parent <?php echo htmlspecialchars($p['fullname']); ?>?');" class="inline">
                                                        <input type="hidden" name="id_number" value="<?php echo htmlspecialchars($p['id_number']); ?>" />
                                                        <button type="submit" name="delete_parent_record" class="px-2 py-1 bg-red-600/20 hover:bg-red-600/30 text-red-300 rounded text-xs">🗑️ Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php renderPagination($total_parents, $limit, $current_page, 'parents'); ?>
            </div>
        </div>

        <script>
        function switchTab(tabName) {
            // Hide all tab panes
            document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
            // Show selected tab pane
            document.getElementById('tab-content-' + tabName).classList.remove('hidden');

            // Reset tab button styles
            const buttons = ['students', 'teachers', 'parents'];
            buttons.forEach(btn => {
                const el = document.getElementById('tab-btn-' + btn);
                if (btn === tabName) {
                    el.classList.remove('border-transparent', 'text-gray-400');
                    el.classList.add('border-blue-500', 'text-blue-400');
                } else {
                    el.classList.remove('border-blue-500', 'text-blue-400');
                    el.classList.add('border-transparent', 'text-gray-400');
                }
            });

            // Update URL search parameters without reloading
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            url.searchParams.set('p', '1');
            window.history.pushState({}, '', url);
        }
        </script>

    <?php elseif ($action === 'admit') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-blue-300">Single Admission Form</h3>
            <form method="post" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">First Name</label>
                        <input type="text" name="firstname" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Last Name</label>
                        <input type="text" name="lastname" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Admission ID Number <span class="text-xs text-gray-500 font-normal">(Leave blank to auto-generate)</span></label>
                    <input type="text" name="admission_number" placeholder="e.g. STU-2026-0001 (Optional)" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Student Email Address</label>
                    <input type="email" name="email" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Portal Password</label>
                    <input type="password" name="password" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Target Class</label>
                        <select name="current_class" required class="w-full bg-slate-900 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <?php foreach ($school_classes as $cls) : ?>
                                <option value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>"><?php echo htmlspecialchars($cls['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Session</label>
                        <select name="session" required class="w-full bg-slate-900 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <?php foreach ($school_sessions as $sess) : ?>
                                <option value="<?php echo htmlspecialchars($sess['session_name']); ?>"><?php echo htmlspecialchars($sess['session_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="admit_student" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Admit Student
                </button>
            </form>
        </div>

    <?php elseif ($action === 'add_teacher') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-indigo-300">Add Teacher (Manual registry)</h3>
            <form method="post" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">First Name *</label>
                        <input type="text" name="firstname" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Last Name *</label>
                        <input type="text" name="lastname" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Email Address *</label>
                        <input type="email" name="email" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Portal Password *</label>
                        <input type="password" name="password" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Class Assigned</label>
                        <div class="relative">
                            <button type="button" onclick="toggleTeacherClassDropdown(this)" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white text-left focus:outline-none focus:border-blue-500 text-sm flex justify-between items-center select-none">
                                <span class="selected-classes-label truncate">Select Classes Assigned</span>
                                <span class="text-xs text-gray-400">▼</span>
                            </button>
                            <div class="teacher-class-dropdown-menu absolute left-0 right-0 mt-1 bg-[#1e293b] border border-white/10 rounded-lg shadow-xl hidden z-50 p-3 max-h-60 overflow-y-auto space-y-2">
                                <?php foreach ($school_classes as $cls) : ?>
                                    <label class="flex items-center gap-2 text-sm text-gray-200 cursor-pointer hover:bg-white/5 p-1 rounded transition select-none">
                                        <input type="checkbox" name="class[]" value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" onchange="updateSelectedClassesLabel(this)" class="rounded bg-white/5 border-white/10 text-blue-600 focus:ring-0 focus:ring-offset-0" />
                                        <span><?php echo htmlspecialchars($cls['class_name']); ?> (<?php echo htmlspecialchars($cls['numeric_class_name']); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if (empty($school_classes)) : ?>
                                    <p class="text-xs text-gray-400">No classes registered yet.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Phone Number</label>
                        <input type="text" name="phone_number" placeholder="e.g. +234..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Gender</label>
                        <select name="gender" class="w-full bg-slate-900 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="" class="bg-[#0b0f19] text-white">Select Gender</option>
                            <option value="Male" class="bg-[#0b0f19] text-white">Male</option>
                            <option value="Female" class="bg-[#0b0f19] text-white">Female</option>
                            <option value="Other" class="bg-[#0b0f19] text-white">Other</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Date of Birth</label>
                    <input type="date" name="dob" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Home Address</label>
                    <textarea name="home_address" rows="3" placeholder="Enter residential address" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                </div>
                <div class="flex items-center gap-2 p-3 bg-white/5 border border-white/5 rounded-xl">
                    <input type="checkbox" name="can_view_payments" id="can_view_payments" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4 bg-slate-900" />
                    <label for="can_view_payments" class="text-sm font-medium text-white select-none">Allow Teacher to view student payments & invoice reports</label>
                </div>
                <div class="flex items-center gap-2 p-3 bg-white/5 border border-white/5 rounded-xl">
                    <input type="checkbox" name="is_sub_admin" id="is_sub_admin" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4 bg-slate-900" />
                    <label for="is_sub_admin" class="text-sm font-medium text-white select-none">Designate as Sub-Admin (Grants Admin access except Billing & System Settings)</label>
                </div>
                <button type="submit" name="add_teacher" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition shadow-md shadow-indigo-600/10">
                    Add Teacher Account
                </button>
            </form>
        </div>

    <?php elseif ($action === 'add_parent') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-6 text-blue-300">Add Parent (Manual registry)</h3>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Parent Full Name *</label>
                    <input type="text" name="fullname" required placeholder="e.g. John Doe" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Email Address *</label>
                        <input type="email" name="email" required placeholder="e.g. parent@example.com" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Portal Password *</label>
                        <input type="password" name="password" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Phone Number</label>
                    <input type="text" name="phone_number" placeholder="e.g. +234..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Home Address</label>
                    <textarea name="home_address" rows="2" placeholder="Enter home address" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Link Children (Select from unlinked students in this school)</label>
                    <div class="mb-2">
                        <input type="text" id="parent_student_search" placeholder="🔍 Type child's name or admission number to filter..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-xs" />
                    </div>
                    <div class="border border-white/10 rounded-lg p-3 max-h-48 overflow-y-auto space-y-2 bg-black/25" id="parent_student_checklist">
                        <?php if (empty($unlinked_students)) : ?>
                            <p class="text-xs text-gray-500 italic">No unclaimed/unlinked students available in this school.</p>
                        <?php else : ?>
                            <?php foreach ($unlinked_students as $stu) : ?>
                                <label class="flex items-center gap-2 text-sm text-gray-300 hover:text-white cursor-pointer py-0.5 student-check-item" data-search="<?php echo htmlspecialchars(strtolower($stu['firstname'] . ' ' . $stu['lastname'] . ' ' . $stu['admission_number'])); ?>">
                                    <input type="checkbox" name="student_admissions[]" value="<?php echo htmlspecialchars($stu['admission_number']); ?>" class="rounded bg-white/5 border-white/10 text-blue-600 focus:ring-blue-500" />
                                    <span><?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname'] . ' (' . $stu['current_class'] . ' - ' . $stu['admission_number'] . ')'); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <script>
                    document.getElementById('parent_student_search').addEventListener('input', function() {
                        const searchVal = this.value.toLowerCase().trim();
                        const items = document.querySelectorAll('#parent_student_checklist .student-check-item');
                        items.forEach(item => {
                            const dataSearch = item.getAttribute('data-search');
                            if (dataSearch.includes(searchVal)) {
                                item.style.display = 'flex';
                            } else {
                                item.style.display = 'none';
                            }
                        });
                    });
                    </script>
                </div>
                
                <button type="submit" name="add_parent" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition shadow-md shadow-blue-600/10">
                    Add Parent Account
                </button>
            </form>
        </div>

        </div>
    <?php elseif ($action === 'view_student') : 
        $adm = $_GET['adm'] ?? '';
        $stmtS = $pdo->prepare("SELECT s.*, p.fullname as parent_name FROM sm_students s LEFT JOIN sm_parents p ON p.id_number = s.parent_id_number WHERE s.admission_number = ? AND s.school_id_number = ?");
        $stmtS->execute([$adm, $school_id]);
        $stu = $stmtS->fetch();

        if ($stu && $user_role === 'teacher') {
            $stmtTInfo = $pdo->prepare("SELECT class FROM sm_teachers WHERE (email = ? OR id_number = ?) AND school_id_number = ? LIMIT 1");
            $stmtTInfo->execute([$_SESSION['user_session'], $_SESSION['user_session'], $school_id]);
            $t_classes_str = $stmtTInfo->fetchColumn();
            $teacher_classes = array_filter(array_map('trim', explode("\n", $t_classes_str ?? '')));
            if (!in_array($stu['current_class'], $teacher_classes)) {
                echo '<div class="p-6 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl max-w-xl mx-auto mt-6">⛔ Access Denied. You do not have permission to view students from this class.</div>';
                return;
            }
        }
    ?>
        <div class="max-w-2xl bg-white/5 border border-white/10 rounded-3xl p-8 shadow-2xl mx-auto space-y-6 backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/10 pb-4">
                <h3 class="text-xl font-bold text-blue-300">🎓 Student Profile Details</h3>
                <a href="?page=registry&action=list" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg text-xs font-semibold">Back to Directory</a>
            </div>
            <?php if (!$stu) : ?>
                <p class="text-red-400">Student not found.</p>
            <?php else : ?>
                <div class="flex flex-col items-center justify-center border-b border-white/5 pb-6">
                    <img src="<?php echo getStudentPhoto($stu, $school_id); ?>" class="w-24 h-24 rounded-full object-cover ring-4 ring-indigo-500/30 bg-slate-950" alt="avatar" />
                    <h4 class="text-lg font-bold text-white mt-3"><?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname']); ?></h4>
                    <p class="text-xs text-indigo-400 font-mono"><?php echo htmlspecialchars($stu['admission_number']); ?></p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-300">
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">First Name</p>
                        <p class="text-white font-bold text-base mt-0.5"><?php echo htmlspecialchars($stu['firstname']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Last Name</p>
                        <p class="text-white font-bold text-base mt-0.5"><?php echo htmlspecialchars($stu['lastname']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Admission ID Number</p>
                        <p class="text-white font-mono text-base mt-0.5"><?php echo htmlspecialchars($stu['admission_number']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Email Address</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($stu['email']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Current Class</p>
                        <p class="text-blue-400 font-bold text-base mt-0.5"><?php echo htmlspecialchars($stu['current_class']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Academic Session</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($stu['session']); ?></p>
                    </div>
                    <div class="md:col-span-2 border-t border-white/5 pt-4">
                        <p class="text-xs text-gray-500 uppercase font-semibold">Linked Parent / Guardian</p>
                        <p class="text-white font-bold text-base mt-0.5"><?php echo htmlspecialchars($stu['parent_name'] ?: 'No parent linked yet'); ?></p>
                        <?php if ($stu['parent_id_number']) : ?>
                            <p class="text-xs text-gray-450 font-mono mt-0.5">Parent ID: <?php echo htmlspecialchars($stu['parent_id_number']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'edit_student') : 
        $adm = $_GET['adm'] ?? '';
        $stmtS = $pdo->prepare("SELECT * FROM sm_students WHERE admission_number = ? AND school_id_number = ?");
        $stmtS->execute([$adm, $school_id]);
        $stu = $stmtS->fetch();
    ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-3xl p-8 shadow-2xl mx-auto space-y-6 backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/10 pb-4">
                <h3 class="text-xl font-bold text-blue-300">✏️ Edit Student Profile</h3>
                <a href="?page=registry&action=list" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg text-xs font-semibold">Cancel</a>
            </div>
            <?php if (!$stu) : ?>
                <p class="text-red-400">Student not found.</p>
            <?php else : ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="admission_number" value="<?php echo htmlspecialchars($stu['admission_number']); ?>" />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">First Name</label>
                            <input type="text" name="firstname" value="<?php echo htmlspecialchars($stu['firstname']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Last Name</label>
                            <input type="text" name="lastname" value="<?php echo htmlspecialchars($stu['lastname']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($stu['email']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Current Class</label>
                            <input type="text" name="current_class" value="<?php echo htmlspecialchars($stu['current_class']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Session</label>
                            <input type="text" name="session" value="<?php echo htmlspecialchars($stu['session']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Change Password (leave blank to keep current)</label>
                        <input type="password" name="password" placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <button type="submit" name="update_student" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                        Save Changes
                    </button>
                </form>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'view_teacher') : 
        $id = $_GET['id'] ?? '';
        $stmtT = $pdo->prepare("SELECT * FROM sm_teachers WHERE id_number = ? AND school_id_number = ?");
        $stmtT->execute([$id, $school_id]);
        $t = $stmtT->fetch();
    ?>
        <div class="max-w-2xl bg-white/5 border border-white/10 rounded-3xl p-8 shadow-2xl mx-auto space-y-6 backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/10 pb-4">
                <h3 class="text-xl font-bold text-indigo-300">🧑‍🏫 Teacher Profile Details</h3>
                <a href="?page=registry&action=list" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg text-xs font-semibold">Back to Directory</a>
            </div>
            <?php if (!$t) : ?>
                <p class="text-red-400">Teacher not found.</p>
            <?php else : ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-300">
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">First Name</p>
                        <p class="text-white font-bold text-base mt-0.5"><?php echo htmlspecialchars($t['firstname']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Last Name</p>
                        <p class="text-white font-bold text-base mt-0.5"><?php echo htmlspecialchars($t['lastname']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Teacher ID ID</p>
                        <p class="text-white font-mono text-base mt-0.5"><?php echo htmlspecialchars($t['id_number']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Email Address</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($t['email']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Class Assigned</p>
                        <p class="text-indigo-400 font-bold text-base mt-0.5"><?php echo htmlspecialchars($t['class'] ?: 'None'); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Phone Number</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($t['phone_number'] ?: 'N/A'); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Gender</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($t['gender'] ?: 'N/A'); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Date of Birth</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($t['dob'] ?: 'N/A'); ?></p>
                    </div>
                    <div class="md:col-span-2 border-t border-white/5 pt-4">
                        <p class="text-xs text-gray-500 uppercase font-semibold">Home Address</p>
                        <p class="text-white text-base mt-0.5"><?php echo nl2br(htmlspecialchars($t['home_address'] ?: 'N/A')); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'edit_teacher') : 
        $id = $_GET['id'] ?? '';
        $stmtT = $pdo->prepare("SELECT * FROM sm_teachers WHERE id_number = ? AND school_id_number = ?");
        $stmtT->execute([$id, $school_id]);
        $t = $stmtT->fetch();
    ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-3xl p-8 shadow-2xl mx-auto space-y-6 backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/10 pb-4">
                <h3 class="text-xl font-bold text-indigo-300">✏️ Edit Teacher Profile</h3>
                <a href="?page=registry&action=list" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg text-xs font-semibold">Cancel</a>
            </div>
            <?php if (!$t) : ?>
                <p class="text-red-400">Teacher not found.</p>
            <?php else : ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="id_number" value="<?php echo htmlspecialchars($t['id_number']); ?>" />
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">First Name</label>
                            <input type="text" name="firstname" value="<?php echo htmlspecialchars($t['firstname']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Last Name</label>
                            <input type="text" name="lastname" value="<?php echo htmlspecialchars($t['lastname']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Email Address</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($t['email']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Class Assigned</label>
                            <div class="relative">
                                <button type="button" onclick="toggleTeacherClassDropdown(this)" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white text-left focus:outline-none focus:border-blue-500 text-sm flex justify-between items-center select-none">
                                    <span class="selected-classes-label truncate">Select Classes Assigned</span>
                                    <span class="text-xs text-gray-400">▼</span>
                                </button>
                                <div class="teacher-class-dropdown-menu absolute left-0 right-0 mt-1 bg-[#1e293b] border border-white/10 rounded-lg shadow-xl hidden z-50 p-3 max-h-60 overflow-y-auto space-y-2">
                                    <?php 
                                    $assigned_classes = array_filter(array_map('trim', explode("\n", $t['class'] ?? '')));
                                    foreach ($school_classes as $cls) : 
                                        $checked = in_array($cls['numeric_class_name'], $assigned_classes) ? 'checked' : '';
                                    ?>
                                        <label class="flex items-center gap-2 text-sm text-gray-200 cursor-pointer hover:bg-white/5 p-1 rounded transition select-none">
                                            <input type="checkbox" name="class[]" value="<?php echo htmlspecialchars($cls['numeric_class_name']); ?>" <?php echo $checked; ?> onchange="updateSelectedClassesLabel(this)" class="rounded bg-white/5 border-white/10 text-blue-600 focus:ring-0 focus:ring-offset-0" />
                                            <span><?php echo htmlspecialchars($cls['class_name']); ?> (<?php echo htmlspecialchars($cls['numeric_class_name']); ?>)</span>
                                        </label>
                                    <?php endforeach; ?>
                                    <?php if (empty($school_classes)) : ?>
                                        <p class="text-xs text-gray-400">No classes registered yet.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Phone Number</label>
                            <input type="text" name="phone_number" value="<?php echo htmlspecialchars($t['phone_number'] ?? ''); ?>" placeholder="e.g. +234..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-1">Gender</label>
                            <select name="gender" class="w-full bg-[#0f172a] border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                                <option value="Male" <?php echo ($t['gender'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($t['gender'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo ($t['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Date of Birth</label>
                        <input type="date" name="dob" value="<?php echo $t['dob'] ? date('Y-m-d', strtotime($t['dob'])) : ''; ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Home Address</label>
                        <textarea name="home_address" rows="3" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"><?php echo htmlspecialchars($t['home_address'] ?? ''); ?></textarea>
                    </div>
                    <div class="flex items-center gap-2 p-3 bg-white/5 border border-white/5 rounded-xl">
                        <input type="checkbox" name="can_view_payments" id="can_view_payments_edit" value="1" <?php echo ($t['can_view_payments'] == 1) ? 'checked' : ''; ?> class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4 bg-slate-900" />
                        <label for="can_view_payments_edit" class="text-sm font-medium text-white select-none">Allow Teacher to view student payments & invoice reports</label>
                    </div>
                    <div class="flex items-center gap-2 p-3 bg-white/5 border border-white/5 rounded-xl">
                        <input type="checkbox" name="is_sub_admin" id="is_sub_admin_edit" value="1" <?php echo ($t['is_sub_admin'] == 1) ? 'checked' : ''; ?> class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4 bg-slate-900" />
                        <label for="is_sub_admin_edit" class="text-sm font-medium text-white select-none">Designate as Sub-Admin (Grants Admin access except Billing & System Settings)</label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Change Password (leave blank to keep current)</label>
                        <input type="password" name="password" placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <button type="submit" name="update_teacher" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                        Save Changes
                    </button>
                </form>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'view_parent') : 
        $id = $_GET['id'] ?? '';
        $stmtP = $pdo->prepare("SELECT * FROM sm_parents WHERE id_number = ? AND school_id_number = ?");
        $stmtP->execute([$id, $school_id]);
        $p = $stmtP->fetch();
        
        if ($p && $user_role === 'teacher') {
            $stmtTInfo = $pdo->prepare("SELECT class FROM sm_teachers WHERE (email = ? OR id_number = ?) AND school_id_number = ? LIMIT 1");
            $stmtTInfo->execute([$_SESSION['user_session'], $_SESSION['user_session'], $school_id]);
            $t_classes_str = $stmtTInfo->fetchColumn();
            $teacher_classes = array_filter(array_map('trim', explode("\n", $t_classes_str ?? '')));
            
            if (!empty($teacher_classes)) {
                $placeholders = implode(',', array_fill(0, count($teacher_classes), '?'));
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM sm_students WHERE parent_id_number = ? AND school_id_number = ? AND current_class IN ($placeholders)");
                $params = array_merge([$p['id_number'], $school_id], $teacher_classes);
                $stmtCheck->execute($params);
                $has_access = $stmtCheck->fetchColumn() > 0;
            } else {
                $has_access = false;
            }
            if (!$has_access) {
                echo '<div class="p-6 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl max-w-xl mx-auto mt-6">⛔ Access Denied. You do not have permission to view this parent profile.</div>';
                return;
            }
        }

        $children = [];
        if ($p) {
            if ($user_role === 'teacher') {
                $stmtTInfo = $pdo->prepare("SELECT class FROM sm_teachers WHERE (email = ? OR id_number = ?) AND school_id_number = ? LIMIT 1");
                $stmtTInfo->execute([$_SESSION['user_session'], $_SESSION['user_session'], $school_id]);
                $t_classes_str = $stmtTInfo->fetchColumn();
                $teacher_classes = array_filter(array_map('trim', explode("\n", $t_classes_str ?? '')));
                if (!empty($teacher_classes)) {
                    $placeholders = implode(',', array_fill(0, count($teacher_classes), '?'));
                    $stmtC = $pdo->prepare("SELECT firstname, lastname, current_class, admission_number FROM sm_students WHERE parent_id_number = ? AND school_id_number = ? AND current_class IN ($placeholders)");
                    $params = array_merge([$p['id_number'], $school_id], $teacher_classes);
                    $stmtC->execute($params);
                    $children = $stmtC->fetchAll();
                }
            } else {
                $stmtC = $pdo->prepare("SELECT firstname, lastname, current_class, admission_number FROM sm_students WHERE parent_id_number = ? AND school_id_number = ?");
                $stmtC->execute([$p['id_number'], $school_id]);
                $children = $stmtC->fetchAll();
            }
        }
    ?>
        <div class="max-w-2xl bg-white/5 border border-white/10 rounded-3xl p-8 shadow-2xl mx-auto space-y-6 backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/10 pb-4">
                <h3 class="text-xl font-bold text-emerald-300">👨‍👩‍👧 Parent Profile Details</h3>
                <a href="?page=registry&action=list" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg text-xs font-semibold">Back to Directory</a>
            </div>
            <?php if (!$p) : ?>
                <p class="text-red-400">Parent not found.</p>
            <?php else : ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-300">
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Parent Full Name</p>
                        <p class="text-white font-bold text-base mt-0.5"><?php echo htmlspecialchars($p['fullname']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Parent ID</p>
                        <p class="text-white font-mono text-base mt-0.5"><?php echo htmlspecialchars($p['id_number']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Email Address</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($p['email']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold">Phone Number</p>
                        <p class="text-white text-base mt-0.5"><?php echo htmlspecialchars($p['phone_number'] ?: 'N/A'); ?></p>
                    </div>
                    <div class="md:col-span-2">
                        <p class="text-xs text-gray-500 uppercase font-semibold">Home Address</p>
                        <p class="text-white text-base mt-0.5"><?php echo nl2br(htmlspecialchars($p['home_address'] ?: 'N/A')); ?></p>
                    </div>
                    <div class="md:col-span-2 border-t border-white/5 pt-4">
                        <p class="text-xs text-gray-500 uppercase font-semibold">Linked Children</p>
                        <div class="space-y-2 mt-2">
                            <?php if (empty($children)) : ?>
                                <p class="text-xs text-gray-500 italic">No children linked to this parent profile.</p>
                            <?php else : ?>
                                <?php foreach ($children as $child) : ?>
                                    <div class="flex justify-between items-center bg-white/5 border border-white/5 p-2.5 rounded-lg">
                                        <span class="text-white font-semibold text-xs"><?php echo htmlspecialchars($child['firstname'] . ' ' . $child['lastname']); ?></span>
                                        <span class="text-[10px] bg-blue-500/10 text-blue-300 px-2 py-0.5 rounded"><?php echo htmlspecialchars($child['current_class']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'edit_parent') : 
        $id = $_GET['id'] ?? '';
        $stmtP = $pdo->prepare("SELECT * FROM sm_parents WHERE id_number = ? AND school_id_number = ?");
        $stmtP->execute([$id, $school_id]);
        $p = $stmtP->fetch();
        
        $my_students = [];
        $other_unlinked = [];
        if ($p) {
            $stmtC = $pdo->prepare("SELECT admission_number FROM sm_students WHERE parent_id_number = ? AND school_id_number = ?");
            $stmtC->execute([$p['id_number'], $school_id]);
            $my_students = $stmtC->fetchAll(PDO::FETCH_COLUMN);
            
            $stmtO = $pdo->prepare("SELECT admission_number, firstname, lastname, current_class FROM sm_students WHERE school_id_number = ? AND (parent_id_number IS NULL OR parent_id_number = ?) ORDER BY lastname, firstname");
            $stmtO->execute([$school_id, $p['id_number']]);
            $other_unlinked = $stmtO->fetchAll();
        }
    ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-3xl p-8 shadow-2xl mx-auto space-y-6 backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/10 pb-4">
                <h3 class="text-xl font-bold text-emerald-300">✏️ Edit Parent Profile</h3>
                <a href="?page=registry&action=list" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 border border-white/10 text-white rounded-lg text-xs font-semibold">Cancel</a>
            </div>
            <?php if (!$p) : ?>
                <p class="text-red-400">Parent not found.</p>
            <?php else : ?>
                <form method="post" class="space-y-4">
                    <input type="hidden" name="id_number" value="<?php echo htmlspecialchars($p['id_number']); ?>" />
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Parent Full Name *</label>
                        <input type="text" name="fullname" value="<?php echo htmlspecialchars($p['fullname']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Email Address *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($p['email']); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Phone Number</label>
                        <input type="text" name="phone_number" value="<?php echo htmlspecialchars($p['phone_number'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Home Address</label>
                        <textarea name="home_address" rows="2" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"><?php echo htmlspecialchars($p['home_address'] ?? ''); ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Link Children</label>
                        <div class="border border-white/10 rounded-lg p-3 max-h-48 overflow-y-auto space-y-2 bg-black/25">
                            <?php if (empty($other_unlinked)) : ?>
                                <p class="text-xs text-gray-500 italic">No unclaimed/unlinked students available in this school.</p>
                            <?php else : ?>
                                <?php foreach ($other_unlinked as $stu) : 
                                    $checked = in_array($stu['admission_number'], $my_students) ? 'checked' : '';
                                ?>
                                    <label class="flex items-center gap-2 text-sm text-gray-300 hover:text-white cursor-pointer py-0.5">
                                        <input type="checkbox" name="student_admissions[]" value="<?php echo htmlspecialchars($stu['admission_number']); ?>" <?php echo $checked; ?> class="rounded bg-white/5 border-white/10 text-blue-600 focus:ring-blue-500" />
                                        <span><?php echo htmlspecialchars($stu['firstname'] . ' ' . $stu['lastname'] . ' (' . $stu['current_class'] . ' - ' . $stu['admission_number'] . ')'); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-400 mb-1">Change Password (leave blank to keep current)</label>
                        <input type="password" name="password" placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <button type="submit" name="update_parent" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                        Save Changes
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php elseif ($action === 'csv') : ?>
        <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
            <h3 class="text-lg font-semibold mb-2 text-indigo-300">Bulk Student CSV Import</h3>
            <p class="text-xs text-gray-400 mb-6">Upload a structured CSV file. Headers must follow the layout:<br>
            <code class="text-blue-400">admission_number, lastname, firstname, email, password, current_class, session</code></p>
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-2">Choose CSV File</label>
                    <input type="file" name="csv_file" accept=".csv" required class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-700 cursor-pointer" />
                </div>
                <button type="submit" name="import_csv" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-semibold transition">
                    Execute Bulk Import
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleTeacherClassDropdown(btn) {
    const menu = btn.nextElementSibling;
    menu.classList.toggle('hidden');
    
    // Close when clicking outside
    const handler = function(e) {
        if (!btn.contains(e.target) && !menu.contains(e.target)) {
            menu.classList.add('hidden');
            document.removeEventListener('click', handler);
        }
    };
    if (!menu.classList.contains('hidden')) {
        document.addEventListener('click', handler);
    }
}

function updateSelectedClassesLabel(checkbox) {
    const container = checkbox.closest('.relative');
    const labelSpan = container.querySelector('.selected-classes-label');
    const checkedCheckboxes = container.querySelectorAll('input[type="checkbox"]:checked');
    
    if (checkedCheckboxes.length === 0) {
        labelSpan.innerText = 'No Class Assigned';
        labelSpan.classList.add('text-gray-400');
        labelSpan.classList.remove('text-white');
    } else {
        const selectedNames = Array.from(checkedCheckboxes).map(cb => cb.value);
        labelSpan.innerText = selectedNames.join(', ');
        labelSpan.classList.remove('text-gray-400');
        labelSpan.classList.add('text-white');
    }
}

// Run initial label updates on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.teacher-class-dropdown-menu input[type="checkbox"]').forEach(cb => {
        updateSelectedClassesLabel(cb);
    });
});
</script>