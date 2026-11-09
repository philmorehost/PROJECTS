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
use RISE63\Core\Updater;
use RISE63\Core\View;

use function RISE63\{e, site_title, take_flashes};

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
        // The directory is rendered into the page, not fetched by the picker alone. A member
        // registering is by definition not signed in, so this is the one place where the list has
        // to be guaranteed rather than hoped for — see the note in the view. Capped at 200 because
        // the page is served over 2G; above that the live search takes over.
        $choosable = [];
        try {
            $choosable = ParishDirectory::search('', 200);
        } catch (\Throwable $error) {
            // A broken directory must not stop somebody registering: they can still type the name.
            error_log('RISE63 register: parish list unavailable: ' . $error->getMessage());
        }
        $render('auth/register', ['title' => 'Create account', 'parishes' => $choosable]);
        break;

    case 'forgot-password':
        $render('auth/forgot-password', [
            'title'    => 'Password help',
            'sent'     => isset($_GET['sent']),
            'canEmail' => RISE63\Core\Mailer::configured(),
        ]);
        break;

    case 'reset-password':
        // The token arrives in the URL; it is never echoed back into the page.
        $token = (string) ($_GET['token'] ?? '');
        if (RISE63\Core\PasswordReset::resolve($token) === null) {
            $render('auth/reset-password', ['title' => 'Reset link expired', 'valid' => false, 'token' => '']);
            break;
        }
        $render('auth/reset-password', ['title' => 'Choose a new password', 'valid' => true, 'token' => $token]);
        break;

    case 'change-password':
        $account = Auth::requireLogin();
        $render('auth/change-password', [
            'title'   => 'Choose a new password',
            'forced'  => (int) ($account['must_change_password'] ?? 0) === 1,
            'account' => $account,
        ]);
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
            'academy'      => RISE63\Core\Academy::bestFor((int) $user['id']),
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
            'academy'     => (string) $application['program_type'] === 'academy'
                ? RISE63\Core\Academy::forApplication((int) $application['id'])
                : null,
            'canRecord'   => Auth::isAdmin($user)
                || ((string) $user['role'] === 'csr_rep'
                    && $user['parish_id'] !== null
                    && (int) $user['parish_id'] === (int) $application['parish_id']),
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

        // A bulk paste is previewed, not applied, on the first submission: the operator checks the
        // parsed counts against the sheet before anything is written. The text is held in the
        // session so the redirect after the POST does not empty the box.
        $importPreview = null;
        $importSkipped = [];
        $importText    = '';
        if (Auth::isAdmin($user)) {
            $parked = $_SESSION['directory_import'] ?? null;
            if (is_array($parked) && (time() - (int) ($parked['at'] ?? 0)) < 3600) {
                $importText = (string) ($parked['text'] ?? '');
                $parsed     = RISE63\Core\DirectoryImport::parse($importText);
                $existing   = ParishDirectory::existingNames();
                $importPreview = RISE63\Core\DirectoryImport::summarise(
                    $parsed['lines'],
                    $existing['zones'],
                    $existing['areas'],
                    $existing['parishes']
                );
                $importSkipped = $parsed['skipped'];
            }
        }

        $render('staff/parishes', [
            'title'         => 'Parish Connect',
            'parishes'      => $rows,
            'term'          => $term,
            'zones'         => ParishDirectory::zones(),
            'areas'         => ParishDirectory::areas(),
            'totals'        => ParishDirectory::totals(),
            'canEdit'       => $canEdit,
            'importPreview' => $importPreview,
            'importSkipped' => $importSkipped,
            'importText'    => $importText,
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
            'cycleYear' => Settings::cycleYear(),
            'funds'    => \RISE63\Core\Fund::all(Settings::cycleYear()),
            // Where an administrator restated a pool. A budget decision, so it lives in the audit
            // trail; the ledger beside it holds only deposits and disbursements.
            'poolEntries' => \RISE63\Core\Fund::poolHistory(10),
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
            'academy'      => RISE63\Core\Academy::bestFor((int) $user['id']),
        ]);
        break;

    case 'people':
        $requireAdmin();
        $directory = \RISE63\Core\Members::directory($_GET);
        $render('staff/people', [
            'title'    => 'People & roles',
            'people'   => $directory['rows'],
            'pager'    => $directory['page'],
            'term'     => $directory['term'],
            'roleFilter' => $directory['role'],
            'statusFilter' => $directory['status'],
            'counts'   => $directory['counts'],
            'roles'    => Auth::ROLES,
            // The account being edited, if the URL names one. One row, fetched here rather than
            // filtered out of the page in the template, so an id outside this page still works.
            'editing'  => isset($_GET['user'])
                ? Database::one(
                    'SELECT u.*, p.parish_name FROM users u LEFT JOIN parishes p ON p.id = u.parish_id WHERE u.id = ?',
                    [(int) $_GET['user']]
                  )
                : null,
            // The parish list for the edit form. Scoped to active parishes; the combobox on the
            // create form does live search, but this form is for one person, where a native picker
            // is one tap rather than three.
            'parishes' => Database::all(
                'SELECT p.id, p.parish_name FROM parishes p WHERE p.is_active = 1 ORDER BY p.parish_name'
            ),
            // The queue the "add it later" promise depends on: members who typed a church that is
            // still not in the directory. Without showing it, nobody ever adds them.
            'unlisted' => Database::all(
                'SELECT parish_name_raw, COUNT(*) AS member_count
                 FROM users
                 WHERE parish_id IS NULL AND parish_name_raw IS NOT NULL AND parish_name_raw <> \'\'
                 GROUP BY parish_name_raw ORDER BY member_count DESC, parish_name_raw LIMIT 50'
            ),
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
        $audit = Audit::page($_GET, [
            'actor'  => (string) ($_GET['actor'] ?? ''),
            'action' => (string) ($_GET['do'] ?? ''),
            'entity' => (string) ($_GET['entity'] ?? ''),
            'kind'   => (string) ($_GET['kind'] ?? ''),
            'q'      => (string) ($_GET['q'] ?? ''),
        ], 'settings');
        $render('staff/settings', [
            'title'   => 'Settings',
            'settings' => Settings::all(),
            'outbox'  => Database::all('SELECT id, recipient, subject, status, attempts, created_at FROM notification_outbox ORDER BY id DESC LIMIT 30'),
            'queue'   => [
                'pending' => (int) Database::value("SELECT COUNT(*) FROM notification_outbox WHERE status = 'pending'"),
                'failed'  => (int) Database::value("SELECT COUNT(*) FROM notification_outbox WHERE status = 'failed'"),
            ],
            'audit'       => $audit['rows'],
            'auditPager'  => $audit['page'],
            'auditFilters' => $audit['filters'],
            'auditActors' => Audit::actors(),
            'auditActions' => Audit::actions(),
            'auditTotal'  => Audit::count(),
            'cycleYear'   => Settings::cycleYear(),
            'autoYear'    => Settings::autoCycleYear(),
            'yearMode'    => (string) Settings::get('cycle_year_mode', 'auto'),
            'funds'       => \RISE63\Core\Fund::all(Settings::cycleYear()),
        ]);
        break;

    // Full activity report: every administrator and member, filterable, paged. Separate from the
    // audit card on Settings because it answers a different question — not "what happened lately"
    // but "who has been doing what", and it is the page an operator opens during a review.
    case 'audit':
        $requireAdmin();
        $report = Audit::page($_GET, [
            'actor'  => (string) ($_GET['actor'] ?? ''),
            'action' => (string) ($_GET['do'] ?? ''),
            'entity' => (string) ($_GET['entity'] ?? ''),
            'kind'   => (string) ($_GET['kind'] ?? ''),
            'q'      => (string) ($_GET['q'] ?? ''),
        ], 'audit');
        $render('staff/audit', [
            'title'        => 'Activity report',
            'events'       => $report['rows'],
            'pager'        => $report['page'],
            'filters'      => $report['filters'],
            'actors'       => Audit::actors(),
            'actions'      => Audit::actions(),
            'summary'      => Audit::actorSummary(40),
            'total'        => Audit::count(),
            'financial'    => Audit::count('e.action IN (\'' . implode("','", Audit::FINANCIAL_ACTIONS) . '\')'),
            'adminEvents'  => Audit::count("u.role IN ('provincial_admin','provincial_treasurer')"),
            'cycleYear'    => Settings::cycleYear(),
        ]);
        break;

    case 'updates':
        $requireAdmin();
        // The operator's view of the release channel. Everything shown here is read from disk or
        // from a previous check; nothing in this page triggers a download or a write.
        $updater = new Updater(RISE63\ROOT, config_dir(), RISE63\ROOT . '/storage');
        $render('staff/updates', [
            'title'          => 'Updates',
            'currentVersion' => $updater->currentVersion(),
            'check'          => $_SESSION['rise63_update_check'] ?? null,
            'plan'           => $_SESSION['rise63_update_plan'] ?? null,
            'result'         => $_SESSION['rise63_update_result'] ?? null,
            'history'        => $updater->history(),
        ]);
        unset(
            $_SESSION['rise63_update_result'],
            // The check survives so the operator can see what it found; the plan does not, because
            // applying it twice would be a second write of the same files.
            $_SESSION['rise63_update_plan']
        );
        break;

    case 'profile':
        $choosable = [];
        try {
            $choosable = ParishDirectory::search('', 200);
        } catch (\Throwable $error) {
            error_log('RISE63 profile: parish list unavailable: ' . $error->getMessage());
        }
        $render('member/profile', [
            'title'    => 'My account',
            'devices'  => Auth::devices((int) $user['id']),
            'parish'   => $user['parish_id'] === null ? null : ParishDirectory::parish((int) $user['parish_id']),
            'pinLock'  => Auth::pinLockedFor($user),
            // Same reason as registration: the picker is an enhancement, the list is the feature.
            'parishes' => $choosable,
        ]);
        break;

    default:
        Security::fail(404, 'That page does not exist.');
}
