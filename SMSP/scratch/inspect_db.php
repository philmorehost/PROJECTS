<?php
define('PORTAL_PATH', dirname(__DIR__));
require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';

echo "=== DATABASE INSPECTION ===\n";

$schools = $pdo->query("SELECT id, name, status, package_id FROM sm_schools")->fetchAll();
echo "Schools in sm_schools: " . count($schools) . "\n";
foreach ($schools as $s) {
    echo " - ID: {$s['id']} | Name: {$s['name']} | Status: {$s['status']} | Package: {$s['package_id']}\n";
}

$students = $pdo->query("SELECT COUNT(*) FROM sm_students")->fetchColumn();
echo "Students in sm_students: " . $students . "\n";

$moderators = $pdo->query("SELECT COUNT(*) FROM sm_moderators")->fetchColumn();
echo "Moderators in sm_moderators: " . $moderators . "\n";

$teachers = $pdo->query("SELECT COUNT(*) FROM sm_teachers")->fetchColumn();
echo "Teachers in sm_teachers: " . $teachers . "\n";
