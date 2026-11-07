<?php
$_SERVER['HTTP_HOST'] = 'portal.schoolapp.ng';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../include/db.php';

$new_pass = password_hash('password123', PASSWORD_DEFAULT);

$updates = [
    'sm_super_moderators' => ['email', 'superadmin@schoolapp.ng'],
    'sm_moderators'       => ['email', 'philmorehost@gmail.com'],
    'sm_students'         => ['email', 'student@test.com'],
    'sm_teachers'         => ['email', 'johndoe@testacademy.com']
];

foreach ($updates as $table => [$field, $val]) {
    try {
        $stmt = $pdo->prepare("UPDATE `$table` SET password = ? WHERE `$field` = ?");
        $stmt->execute([$new_pass, $val]);
        echo "Updated password for $table ($val) to 'password123'\n";
    } catch (Exception $e) {
        echo "Error updating $table: " . $e->getMessage() . "\n";
    }
}
