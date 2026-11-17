<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Http\Controllers\InstallController;
use App\Support\Security;

Security::startSession();
Security::sendHeaders();

if (bp_installed()) {
    // Once storage/installed exists, lock the installer unless viewing final Stage 4 summary in the same session
    if (($_SESSION['bp_install_stage'] ?? 0) === 4 && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        $controller = new InstallController();
        $stage = 4;
        $reqs = $controller->checkRequirements();
        $error = null;
        $message = null;
        unset($_SESSION['bp_install_stage']);
        require BP_ROOT . '/resources/views/install.php';
        bp_halt();
    }

    http_response_code(409);
    $bodyContent = '<main class="bp-container" style="max-width:460px;padding-top:64px;"><div class="bp-card" style="text-align:center;">'
        . '<div class="bp-badge bp-badge-success bp-mb-2">Already Installed</div>'
        . '<h1 style="margin:6px 0 8px;font-size:21px;">Installer is Locked</h1>'
        . '<p class="bp-muted" style="font-size:14px;margin:0 0 18px;">Remove <code class="bp-mono">storage/installed</code> only on a fresh copy if you intend to reinstall.</p>'
        . '<a href="' . bp_e(bp_url('')) . '" class="bp-btn bp-btn-primary bp-btn-block">Go to Homepage</a></div></main>';
    $pageTitle = 'Installer Locked';
    require BP_ROOT . '/resources/views/layout.php';
    bp_halt();
}

// Acquire non-blocking setup mutex
$mutexHandle = @fopen(BP_STORAGE . '/setup.mutex', 'c');
if ($mutexHandle !== false) {
    @flock($mutexHandle, LOCK_EX | LOCK_NB);
}

$controller = new InstallController();
$reqs = $controller->checkRequirements();
$stage = $controller->currentStage();
$error = null;
$message = null;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    Security::requireCsrf();
    $submittedStage = (int) ($_POST['stage'] ?? $stage);
    $res = $controller->submitStage($submittedStage, $_POST);
    $stage = $res['stage'];
    if (!$res['ok']) {
        $error = $res['error'] ?? 'Setup step failed.';
    } else {
        $message = $res['message'] ?? null;
    }
    $reqs = $controller->checkRequirements();
}

require BP_ROOT . '/resources/views/install.php';
