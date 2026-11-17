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

use function RISE63\{config_dir, e, flash, installed, page_from_path, redirect, site_title, take_flashes, url};

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
//
// "Try again" on the gate is a POST to ?retry=activation. Without this the button reloaded the
// same cached failure and the operator could not force a re-check after fixing their key or the
// host's firewall — the screen looked stuck no matter what they did.
$retryActivation = false;
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
    && ($_POST['action'] ?? '') === 'activation-retry') {
    // The gate is rendered before the CSRF-checked POST router, so the token and origin are
    // verified here. Without this the retry button would be a one-click way to hammer the
    // provider from any third-party page.
    Security::assertPost();
    $retryActivation = true;
}
$activation = new Activation(config_dir());
$activationState = $activation->check((string) ($_SERVER['HTTP_HOST'] ?? ''), $retryActivation);
if (!$activationState['valid']) {
    $domain = $activationState['domain'] ?? Activation::domainFromHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
    require __DIR__ . '/app/Views/system-gate.php';
    exit;
}

Settings::flush();

// ── Keep the database in step with the code ───────────────────────────────────────────────
// Once per code version, before any page reads it: create the migration bookkeeping table if it is
// missing, record migrations whose effect is already present, and apply the rest. This is what
// makes "we shipped a new table" take effect on deploy instead of needing a shell command that, in
// the field, refused to run at all (see RISE63\Core\SelfHeal).
//
// Deliberately after the activation gate and the maintenance check: a broken licence or a site in
// maintenance must not trigger schema writes. Deliberately before the router: pages must never be
// the thing that discovers the schema is behind.
try {
    $selfHeal = RISE63\Core\SelfHeal::run(RISE63\ROOT, RISE63\VERSION);
    if ($selfHeal['ran']) {
        error_log('RISE63 self-heal: ' . ($selfHeal['error'] !== ''
            ? 'failed: ' . $selfHeal['error']
            : 'ok: ' . json_encode($selfHeal['report'])));
        Settings::flush();
    }
} catch (\Throwable $error) {
    // A schema check must never be the reason a page fails. It is logged and reported on Settings,
    // and the request continues with whatever schema the database actually has.
    error_log('RISE63 self-heal unavailable: ' . $error->getMessage());
}

// ── Routing ───────────────────────────────────────────────────────────────────────────
// The page comes from ?page= when the rewrite in .htaccess supplied it, and from the URL path
// otherwise. Both are supported on purpose: /login must work as a path segment (every link and
// every redirect in this application emits one) *and* as /?page=login, which is what the
// development router and the preview harnesses produce. Deriving it here rather than relying on
// the rewrite alone means a host with AllowOverride off still serves /login correctly instead of
// an Apache "Not Found" — and if the rewrite is added later it does the same thing.
$page    = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['page'] ?? ''))) ?? '';
if ($page === '') {
    $page = page_from_path((string) ($_SERVER['REQUEST_URI'] ?? '/'));
}
$action  = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['action'] ?? ''))) ?? '';
$method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * Pages a visitor who is not signed in may see.
 *
 * The landing page is the front door now, not the sign-in form. A youth worker forwarded a WhatsApp
 * link to RISE 63 should land on something that explains the initiative, not on a password prompt.
 *
 * CMS pages are resolved here rather than being listed: an administrator can add /scholarships
 * without a release, so the list cannot be a constant. The check is a database read, so it is done
 * once and only when the page is not one of the fixed ones.
 */
$publicPages = ['', 'login', 'register', 'forgot-password', 'reset-password', 'offline',
                'about', 'vision', 'privacy', 'terms', 'contact', 'parish-connect'];
/**
 * Public URLs that are not pages of the application.
 *
 * A stranger who mistypes a site address, or follows a link to a page an administrator has since
 * unpublished, used to be redirected to the sign-in form — which told them they needed an account
 * to read a public website and gave them no way back. These two shapes are the public site's own
 * territory: the site's 404 lives under `site-not-found`, and anything that looks like a page slug
 * is handed to the CMS, which answers with that same 404 when there is nothing behind it.
 */
$siteFallback = 'site-not-found';
if ($user === null && !in_array($page, $publicPages, true) && $page !== '') {
    try {
        if (\RISE63\Core\Site::page($page) !== null) {
            $publicPages[] = $page;
        }
    } catch (\Throwable $error) {
        // Migration 0005 has not run, or the table is unreachable. Deny, which is the safe direction:
        // an unknown URL must not become an unauthenticated route into the application.
        error_log('RISE63 public page lookup: ' . $error->getMessage());
    }
}
if ($page === '') {
    if ($user !== null) {
        $page = 'dashboard';
    }
    // Anonymous visitors stay on '' — that is the landing page. The site router answers it.
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

    // ── Reads that cannot be a page ──────────────────────────────────────────────────
    if ($action === 'document-download') {
        require __DIR__ . '/app/download.php';
        exit;
    }
    // An uploaded hero photograph. Public, because the landing page is public: the file is streamed
    // with a fixed image type and is never executed or listed.
    if ($action === 'site-image') {
        require __DIR__ . '/app/site-image.php';
        exit;
    }

    // ── Public pages ──────────────────────────────────────────────────────────────────
    if ($user === null && $page === 'register' && !Settings::bool('allow_self_registration')) {
        flash('Self-registration is closed. Ask your parish representative for an account.', 'error');
        redirect('login');
    }
    if ($user === null && !in_array($page, $publicPages, true)) {
        // A signed-out visitor is a reader, not a user who forgot to sign in. Inside the site's own
        // URL space they get the site's "that page is not here" page; the sign-in redirect is kept
        // for the application's own URLs, which is what it was always for.
        $page = $siteFallback;
    }
    // A signed-in member who taps the site's own "Home" arrives at the dashboard instead of being
    // dropped on a marketing page with a "sign in" button they do not need.
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

    // ── Program year ──────────────────────────────────────────────────────────────────
    // Auto mode follows the calendar. Done here, once, before any page reads the year, so /register
    // and /applications cannot disagree about which cycle an application belongs to.
    if ($user !== null) {
        Settings::rolloverCycleYear((int) $user['id']);
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
