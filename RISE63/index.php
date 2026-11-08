<?php
declare(strict_types=1);

/**
 * RISE 63 — front controller.
 *
 * Everything public enters through this file. Private directories (app, config, storage,
 * database, tests, bin, docs) are denied by .htaccess and must also be denied at the server
 * level; see docs/DEPLOYMENT.md.
 */

require_once __DIR__ . '/app/bootstrap.php';

use RISE63\Core\Activation;
use RISE63\Core\Auth;
use RISE63\Core\Security;
use RISE63\Core\Settings;
use RISE63\Core\View;

use function RISE63\{config_dir, e, flash, installed, redirect, site_title, take_flashes, url};

// ── Installer hand-off ────────────────────────────────────────────────────────────────
if (!installed()) {
    require __DIR__ . '/install/index.php';
    exit;
}

Security::startSession();
Security::sendHeaders();

// ── Maintenance mode ──────────────────────────────────────────────────────────────────
// Checked before activation on purpose: during an outage the queue workers (which run from
// cron, not through this file) can still drain the notification backlog, and an administrator
// can still get in. Note that no outbound activation call happens in this branch.
$user = Auth::user();
if (Settings::bool('maintenance_mode') && !($user !== null && Auth::isAdmin($user))) {
    require __DIR__ . '/app/Views/maintenance.php';
    exit;
}

// ── System activation gate ────────────────────────────────────────────────────────────
// Cached for 6 hours with a 24-hour grace window, so this is usually a single local read.
$activation = new Activation(config_dir());
$activationState = $activation->check((string) ($_SERVER['HTTP_HOST'] ?? ''));
if (!$activationState['valid']) {
    require __DIR__ . '/app/Views/system-gate.php';
    exit;
}

Settings::flush();

// ── Routing ───────────────────────────────────────────────────────────────────────────
$page    = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['page'] ?? ''))) ?? '';
$action  = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['action'] ?? ''))) ?? '';
$method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$publicPages = ['login', 'register', 'forgot-password', 'reset-password', 'offline', 'about'];
if ($page === '') {
    $page = $user === null ? 'login' : 'dashboard';
}

/** URL of the page for the signed-in user's role. */
$home = static function (array $account): string {
    return match ((string) $account['role']) {
        'provincial_admin'    => 'dashboard',
        'provincial_treasurer' => 'dashboard',
        'csr_rep'             => 'dashboard',
        default               => 'my-rise',
    };
};

try {
    // ── Write actions (POST only: the CSRF + origin check lives in assertPost) ─────────
    if ($method === 'POST') {
        Security::assertPost();
        require __DIR__ . '/app/actions.php';
        exit;
    }

    // ── Authenticated reads that cannot be a page (file download) ─────────────────────
    if ($action === 'document-download') {
        require __DIR__ . '/app/download.php';
        exit;
    }

    // ── Public pages ──────────────────────────────────────────────────────────────────
    if ($user === null && $page === 'register' && !Settings::bool('allow_self_registration')) {
        flash('Self-registration is closed. Ask your parish representative for an account.', 'error');
        redirect('login');
    }
    if ($user === null && !in_array($page, $publicPages, true)) {
        redirect('login');
    }
    if ($user !== null && in_array($page, ['login', 'register', 'forgot-password', 'reset-password'], true)) {
        redirect($home($user));
    }

    // A temporary password must be replaced before anything else is reachable. Only the change
    // form, the sign-out action and the theme toggle are allowed through.
    if ($user !== null && (int) ($user['must_change_password'] ?? 0) === 1) {
        $exempt = $page === 'change-password'
            || ($method === 'POST' && in_array($action, ['password-change', 'theme', 'logout'], true));
        if (!$exempt) {
            flash('Choose a new password before continuing — your current one was issued by an administrator.', 'error');
            redirect('change-password');
        }
    }

    require __DIR__ . '/app/pages.php';
} catch (\Throwable $error) {
    error_log('RISE63: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
    http_response_code(500);
    $message = 'Something went wrong on our side. Please try again.';
    if (getenv('RISE63_DEBUG') === '1') {
        $message .= ' [' . $error->getMessage() . ']';
    }
    View::render('error', ['title' => 'Temporary problem', 'message' => $message, 'user' => $user], false);
}
