<?php
declare(strict_types=1);

/**
 * GET router. Each page gathers its own data and hands plain arrays to a view; no view
 * performs a query and no page performs writes.
 */

use RISE63\Core\Applications;
use RISE63\Core\Audit;
use RISE63\Core\Auth;
use RISE63\Core\BruteForce;
use RISE63\Core\Database;
use RISE63\Core\GeoResolver;
use RISE63\Core\Ledger;
use RISE63\Core\Money;
use RISE63\Core\ParishDirectory;
use RISE63\Core\Program;
use RISE63\Core\Security;
use RISE63\Core\Settings;
use RISE63\Core\View;

use function RISE63\{e, take_flashes};

$flashes = take_flashes();
$now     = ['flashes' => $flashes, 'user' => $user, 'page' => $page, 'title' => site_title()];

/** Render with the standard chrome. */
$render = static function (string $template, array $data = [], bool $layout = true) use ($now, $user): void {
    View::render($template, array_merge($now, ['account' => $user], $data), $layout);
};

$requireAdmin = static function () use ($user): void {
    if ($user === null || !Auth::isAdmin($user)) {
        Security::fail(403, 'Only a provincial administrator can open this page.');
    }
};

switch ($page) {

    // ── Public ───────────────────────────────────────────────────────────────────────
    case 'login':
        $render('auth/login', [
            'title'    => 'Sign in',
            'parishes' => ParishDirectory::search('', 1),
        ]);
        break;

    case 'register':
        $render('auth/register', ['title' => 'Create account']);
        break;

    case 'forgot-password':
        $render('auth/forgot-password', ['title' => 'Password help']);
        break;

    case 'about':
    case 'offline':
        $render($page === 'offline' ? 'offline' : 'about', ['title' => $page === 'offline' ? 'Offline' : 'About RISE 63']);
        break;

    // ── Member workspace ─────────────────────────────────────────────────────────────
    case 'my-rise':
        $applications = Applications::visibleTo($user, 100);
        $summary      = Applications::summaryFor($user);
        $render('member/home', [
            'title'        => 'My RISE 63',
            'applications' => $applications,
            'summary'      => $summary,
            'eligibility'  => Applications::eligibility($user),
        ]);
        break;

    case 'programs':
        $type = (string) ($_GET['program'] ?? '');
        if ($type !== '' && !Program::exists($type)) {
            Security::fail(404, 'That program does not exist.');
        }
        $render($type === '' ? 'member/programs' : 'member/apply', [
            'title'       => $type === '' ? 'Programs' : 'Apply: ' . Program::label($type),
            'programType' => $type,
            'eligibility' => Applications::eligibility($user),
            'questions'   => $type === '' ? [] : Program::questions($type),
            'maxGrant'    => Money::fromDecimal((string) Settings::get('seed_grant_max_per_application', '0')),
        ]);
        break;

    case 'application':
        $application = Applications::find((int) ($_GET['id'] ?? 0), $user);
        if ($application === null) {
            Security::fail(404, 'Application not found.');
        }
        $render('member/application', [
            'title'       => Program::short((string) $application['program_type']) . ' application',
            'application' => $application,
            'events'      => Applications::events((int) $application['id']),
            'answers'     => Applications::answers($application),
            'documents'   => Applications::documents($application),
            'tracker'     => Program::tracker(
                (string) $application['program_type'],
                (string) $application['status'],
                (string) ($application['updated_at'] ?? '')
            ),
            'canReview'   => Auth::isReviewer($user) && (int) $application['user_id'] !== (int) $user['id'],
        ]);
        break;

    case 'applications':
        Auth::requireRole($user, ['provincial_admin', 'provincial_treasurer', 'csr_rep']);
        $type   = (string) ($_GET['type'] ?? '');
        $status = (string) ($_GET['status'] ?? '');
        $rows   = Applications::visibleTo($user, 300);
        $rows   = array_values(array_filter($rows, static function (array $row) use ($type, $status): bool {
            return ($type === '' || (string) $row['program_type'] === $type)
                && ($status === '' || (string) $row['status'] === $status);
        }));
        $render('staff/applications', [
            'title'        => 'Applications',
            'applications' => $rows,
            'filterType'   => $type,
            'filterStatus' => $status,
            'programs'     => Program::TYPES,
            'statuses'     => Program::STATUSES,
        ]);
        break;

    // ── Parish Connect ───────────────────────────────────────────────────────────────
    case 'parishes':
        $term    = (string) ($_GET['q'] ?? '');
        $rows    = ParishDirectory::directory($user, $term);
        $canEdit = Auth::isAdmin($user) || (string) $user['role'] === 'csr_rep';
        $render('staff/parishes', [
            'title'   => 'Parish Connect',
            'parishes' => $rows,
            'term'    => $term,
            'zones'   => ParishDirectory::zones(),
            'areas'   => ParishDirectory::areas(),
            'totals'  => ParishDirectory::totals(),
            'canEdit' => $canEdit,
        ]);
        break;

    case 'parish':
        $parish = ParishDirectory::parish((int) ($_GET['id'] ?? 0));
        if ($parish === null) {
            Security::fail(404, 'Parish not found.');
        }
        if ((string) $user['role'] === 'csr_rep' && (int) $user['parish_id'] !== (int) $parish['id']) {
            Security::fail(403, 'You can only open your own parish.');
        }
        $render('staff/parish', [
            'title'    => (string) $parish['parish_name'],
            'parish'   => $parish,
            'areas'    => ParishDirectory::areas(),
            'members'  => Database::all(
                'SELECT id, full_name, email, role, is_suspended FROM users WHERE parish_id = ? ORDER BY full_name',
                [(int) $parish['id']]
            ),
            'apps'     => Database::all(
                'SELECT id, program_type, status, requested_amount, created_at FROM program_applications
                 WHERE parish_id = ? ORDER BY id DESC LIMIT 50',
                [(int) $parish['id']]
            ),
        ]);
        break;

    // ── Treasurer ────────────────────────────────────────────────────────────────────
    case 'treasury':
        Auth::requireRole($user, ['provincial_admin', 'provincial_treasurer']);
        $render('staff/treasury', [
            'title'    => 'Treasury',
            'pending'  => Ledger::pending(),
            'entries'  => Ledger::entries(80),
            'totals'   => Ledger::totals(),
            'pinLock'  => Auth::pinLockedFor($user),
            'approved' => Database::all(
                "SELECT a.*, u.full_name FROM program_applications a JOIN users u ON u.id = a.user_id
                 WHERE a.status = 'approved' ORDER BY a.approved_at ASC LIMIT 50"
            ),
        ]);
        break;

    // ── Administration ───────────────────────────────────────────────────────────────
    case 'dashboard':
        if (Auth::isAdmin($user) || Auth::isTreasurer($user)) {
            $render('staff/dashboard', [
                'title'      => 'Dashboard',
                'summary'    => Applications::summaryFor($user),
                'totals'     => ParishDirectory::totals(),
                'ledger'     => Ledger::totals(),
                'recent'     => Database::all(
                    "SELECT a.*, u.full_name, p.parish_name FROM program_applications a
                     JOIN users u ON u.id = a.user_id LEFT JOIN parishes p ON p.id = a.parish_id
                     ORDER BY a.updated_at DESC LIMIT 8"
                ),
                'security'   => Database::all('SELECT * FROM security_logs ORDER BY id DESC LIMIT 6'),
                'attention'  => (int) Database::value(
                    "SELECT COUNT(*) FROM program_applications WHERE status IN ('submitted','under_review')"
                ),
                'slaBreach'  => (int) Database::value(
                    "SELECT COUNT(*) FROM program_applications WHERE status IN ('submitted','under_review')
                     AND submitted_at < (UTC_TIMESTAMP() - INTERVAL " . Settings::int('education_bridge_sla_hours', 72) . ' HOUR)'
                ),
            ]);
            break;
        }
        $applications = Applications::visibleTo($user, 60);
        $render('member/home', [
            'title'        => 'Dashboard',
            'applications' => $applications,
            'summary'      => Applications::summaryFor($user),
            'eligibility'  => Applications::eligibility($user),
        ]);
        break;

    case 'people':
        $requireAdmin();
        $term = (string) ($_GET['q'] ?? '');
        $sql  = 'SELECT u.*, p.parish_name, a.area_name, z.zone_name FROM users u
                 LEFT JOIN parishes p ON p.id = u.parish_id
                 LEFT JOIN areas a ON a.id = p.area_id
                 LEFT JOIN zones z ON z.id = a.zone_id';
        $params = [];
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $sql .= ' WHERE u.full_name LIKE ? OR u.email LIKE ? OR p.parish_name LIKE ?';
            $params = [$like, $like, $like];
        }
        $sql .= ' ORDER BY u.role, u.full_name LIMIT 300';
        $render('staff/people', [
            'title'    => 'People & roles',
            'people'   => Database::all($sql, $params),
            'term'     => $term,
            'roles'    => Auth::ROLES,
        ]);
        break;

    case 'security':
        $requireAdmin();
        $ipFilter = (string) ($_GET['ip'] ?? '');
        $logs     = $ipFilter !== ''
            ? Database::all('SELECT * FROM security_logs WHERE ip_address = ? ORDER BY id DESC LIMIT 200', [$ipFilter])
            : Database::all('SELECT * FROM security_logs ORDER BY id DESC LIMIT 200');
        $render('staff/security', [
            'title'      => 'Security',
            'logs'       => $logs,
            'ipFilter'   => $ipFilter,
            'blocked'    => Database::all(
                'SELECT * FROM security_ips WHERE blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP() ORDER BY blocked_until ASC LIMIT 100'
            ),
            'recognised' => Database::all('SELECT * FROM security_ips WHERE whitelisted = 1 ORDER BY clean_sessions DESC LIMIT 50'),
            'rules'      => Database::all(
                'SELECT r.*, u.full_name FROM manual_access_rules r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC LIMIT 100'
            ),
            'firewall'   => Database::all("SELECT * FROM firewall_jobs ORDER BY id DESC LIMIT 30"),
            'countries'  => Database::all("SELECT * FROM country_rules ORDER BY country_name"),
            'suspended'  => Database::all('SELECT id, full_name, email, role FROM users WHERE is_suspended = 1 ORDER BY full_name'),
            'counts'     => [
                'failed'   => (int) Database::value("SELECT COUNT(*) FROM security_logs WHERE event_type = 'failed_login' AND created_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)"),
                'blockedIp' => (int) Database::value('SELECT COUNT(*) FROM security_ips WHERE blocked_until > UTC_TIMESTAMP()'),
                'suspended' => (int) Database::value('SELECT COUNT(*) FROM users WHERE is_suspended = 1'),
            ],
            'settings'   => Settings::all(),
        ]);
        break;

    case 'settings':
        $requireAdmin();
        $render('staff/settings', [
            'title'   => 'Settings',
            'settings' => Settings::all(),
            'outbox'  => Database::all('SELECT id, recipient, subject, status, attempts, created_at FROM notification_outbox ORDER BY id DESC LIMIT 30'),
            'queue'   => [
                'pending' => (int) Database::value("SELECT COUNT(*) FROM notification_outbox WHERE status = 'pending'"),
                'failed'  => (int) Database::value("SELECT COUNT(*) FROM notification_outbox WHERE status = 'failed'"),
            ],
            'audit'   => Audit::recent(30),
        ]);
        break;

    case 'profile':
        $render('member/profile', [
            'title'    => 'My account',
            'devices'  => Auth::devices((int) $user['id']),
            'parish'   => $user['parish_id'] === null ? null : ParishDirectory::parish((int) $user['parish_id']),
            'pinLock'  => Auth::pinLockedFor($user),
        ]);
        break;

    default:
        Security::fail(404, 'That page does not exist.');
}
