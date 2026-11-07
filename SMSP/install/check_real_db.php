<?php
define('PORTAL_PATH', dirname(__DIR__));
require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';

echo "<pre>=== REAL DATABASE STATS ===\n";
echo "DB Host: " . DB_HOST . "\n";
echo "DB Name: " . DB_NAME . "\n";

$tables = ['sm_schools', 'sm_students', 'sm_teachers', 'sm_parents', 'sm_moderators', 'sm_results'];
foreach ($tables as $tbl) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        echo "Table `{$tbl}` count: {$count}\n";
    } catch (Exception $e) {
        echo "Table `{$tbl}` error: " . $e->getMessage() . "\n";
    }
}

// Print some student names to see if they are from the legacy database
try {
    $sample = $pdo->query("SELECT firstname, lastname, school_id_number, current_class FROM sm_students LIMIT 5")->fetchAll();
    echo "\nSample Students:\n";
    foreach ($sample as $st) {
        echo " - {$st['firstname']} {$st['lastname']} (School: {$st['school_id_number']}, Class: {$st['current_class']})\n";
    }
} catch (Exception $e) {}

echo "</pre>";
