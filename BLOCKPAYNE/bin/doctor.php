<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Http\Controllers\InstallController;
use App\Support\Database;

$ic = new InstallController();
$reqs = $ic->checkRequirements();

echo "=== BlockPayne Health Check (v" . BP_VERSION . ") ===\n";
echo "Installed Lockfile: " . (bp_installed() ? "PRESENT" : "MISSING") . "\n";
foreach ($reqs['checks'] as $c) {
    echo sprintf(" - %-36s : %s (%s)\n", $c['label'], $c['passed'] ? 'OK' : 'FAIL', $c['current']);
}

if (bp_installed()) {
    try {
        $pdo = Database::connection();
        $uCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $cCount = (int) $pdo->query('SELECT COUNT(*) FROM country_firewall')->fetchColumn();
        echo "Database Connection : OK ({$uCount} user(s), {$cCount} country rules)\n";
    } catch (\Throwable $e) {
        echo "Database Connection : FAIL (" . $e->getMessage() . ")\n";
        exit(1);
    }
}
exit($reqs['all_passed'] ? 0 : 1);
