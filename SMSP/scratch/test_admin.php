<?php
// Database Diagnostic Script for login checks
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/include/db.php';

echo "<h2>🔑 SchoolApp Database Credentials Check</h2>";

try {
    // 1. Check Super Admins
    echo "<h3>1. Super Administrators (Select 'Super Administrator' Role)</h3>";
    $stmt = $pdo->query("SELECT id, fullname, email FROM `sm_super_moderators`");
    $admins = $stmt->fetchAll();
    if (empty($admins)) {
        echo "<p style='color: red;'>No super admins found in database!</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Fullname</th><th>Email (Use this to Login)</th></tr>";
        foreach ($admins as $a) {
            echo "<tr><td>{$a['fullname']}</td><td><b>{$a['email']}</b></td></tr>";
        }
        echo "</table>";
    }

    // 2. Check School Admins
    echo "<h3>2. School Administrators (Select 'School Admin' Role)</h3>";
    $stmt = $pdo->query("SELECT m.id, m.fullname, m.email, m.school_id_number, s.name as school_name 
                         FROM `sm_moderators` m 
                         LEFT JOIN `sm_schools` s ON m.school_id_number = s.id");
    $school_admins = $stmt->fetchAll();
    if (empty($school_admins)) {
        echo "<p style='color: red;'>No school admins found in database!</p>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Fullname</th><th>Email/ID (Use to Login)</th><th>School Name (ID)</th></tr>";
        foreach ($school_admins as $sa) {
            echo "<tr><td>{$sa['fullname']}</td><td><b>{$sa['email']}</b></td><td>{$sa['school_name']} ({$sa['school_id_number']})</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Database Query Error: " . $e->getMessage() . "</p>";
}
?>
