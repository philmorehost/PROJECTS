<?php
$_SERVER['HTTP_HOST'] = 'portal.schoolapp.ng';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../include/db.php';

$tables = [
    'sm_super_moderators' => ['email', 'fullname'],
    'sm_moderators'       => ['email', 'fullname', 'school_id_number'],
    'sm_students'         => ['email', 'admission_number', 'firstname', 'lastname'],
    'sm_teachers'         => ['email', 'id_number', 'fullname'],
    'sm_parents'          => ['email', 'id_number', 'fullname']
];

foreach ($tables as $table => $fields) {
    try {
        $stmt = $pdo->query("SELECT * FROM `$table` LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "=== $table ===\n";
        if ($row) {
            foreach ($row as $k => $v) {
                if ($k === 'password') {
                    echo "  $k => [HASH] " . substr($v, 0, 15) . "...\n";
                } else {
                    echo "  $k => $v\n";
                }
            }
        } else {
            echo "  No records found.\n";
        }
    } catch (Exception $e) {
        echo "Error in $table: " . $e->getMessage() . "\n";
    }
}
