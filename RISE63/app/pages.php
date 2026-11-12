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
use RISE63\Core\Site;
use RISE63\Core\Updater;
use RISE63\Core\View;

use function RISE63\{config_dir, e, setting, site_title, take_flashes, url};

$flashes = take_flashes();
$now     = ['flashes' => $flashes, 'user' => $user, 'page' => $page, 'title' => site_title()];

/** Render with the standard chrome. */
$render = static function (string $template, array $data = [], bool $layout = true) use ($now, $user): void {
    View::render($template, array_merge($now, ['account' => $user], $data), $layout);
};

/**
 * Render a public page.
 *
 * A separate path from $render on purpose: the public site is a different document (site/layout.php)
 * with its own stylesheet, no tab bar and no admin chrome. Everything it needs is gathered here so the
 * templates stay layout-only.
 *
 * $pageName is the slug used for the nav's current-page state; $extra carries page data.
 *
 * @param array<string,mixed> $extra
 */
$renderSite = static function (string $template, array $extra = []) use ($now, $user, $page): void {
    // $template is the view *inside* site/ — the prefix is added here, once, so every call site reads
    // as the page name rather than as a path.
    $navPages = [[ 'slug' => '', 'label' => 'Home' ]];
    try {
        foreach (Site::publishedPages() as $row) {
            // The four seeded pages are linked by name in the footer and by position in the nav; any
            // page an administrator adds is appended, so the menu grows with the content.
            $navPages[] = ['slug' => $row['slug'], 'label' => $row['title']];
        }
    } catch (\Throwable $error) {
        error_log('RISE63 site nav: ' . $error->getMessage());
    }

    $view = 'site/' . $template;
    View::render($view, array_merge($now, $extra, [
        'siteNav'     => $navPages,
        'sitePage'    => $extra['sitePage'] ?? $page,
        'account'     => $user,
        'flashes'     => [],
        'layout'      => 'site',
    ]), true);
};

/** Settings shared by every public page, read once. */
$siteSettings = static function (): array {
    $raw = (string) (setting('whatsapp_number', '') ?? '');
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    // A local Nigerian number written as 0803… becomes 234803…; anything already international is left
    // alone. wa.me requires the country code with no plus and no leading zero.
    if (str_starts_with($digits, '0')) {
        $digits = '234' . substr($digits, 1);
    }
    return [
        'whatsappRaw' => $raw,
        'whatsappDigits' => $digits,
        'whatsappDisplay' => $raw,
        'contactEmail' => trim((string) (setting('contact_email', '') ?: setting('notification_email', ''))),
        'contactPhone' => trim((string) setting('support_phone', '')),
        'heroInterval' => max(0, (int) setting('hero_autoplay_seconds', 7)) * 1000,
    ];
};

$requireAdmin = static function () use ($user): void {
    if ($user === null || !Auth::isAdmin($user)) {
        Security::fail(403, 'Only a provincial administrator can open this page.');
    }
};

switch ($page) {

    // ── The public site ──────────────────────────────────────────────────────────────
    // The landing page, the four CMS pages the footer links to, the contact page, and any page an
    // administrator adds under Settings → Website. All of them render through site/layout.php: a
    // different document from the signed-in application, with its own stylesheet and no tab bar.
    case '':
        $site = $siteSettings();
        $slides = Site::slides(true);
        if ($slides === []) {
            // Before migration 0005, or if every slide has been unpublished, the hero still has to
            // say something. These are the blueprint's own opening lines rather than lorem ipsum.
            $slides = \RISE63\Core\SiteDefaults::slides();
        }
        $renderSite('home', [
            'title'           => site_title() . ' — Learn. Build. Rise.',
            'metaDescription' => 'RISE 63 is a youth educational and economic empowerment initiative of RCCL Lagos Province 63 — '
                . 'education support, entrepreneurship training, seed grants and responsible access to cooperative financing.',
            'ogImage'         => url('public/img/hero-recognise.jpg'),
            'slides'          => $slides,
            'programmes'      => \RISE63\Core\SiteDefaults::programmes(),
            'copy'            => \RISE63\Core\SiteDefaults::copy(),
            'heroInterval'    => $site['heroInterval'],
            'sitePage'        => '',
            'siteNav'         => [],
        ]);
        break;

    case 'contact':
        $site = $siteSettings();
        $renderSite('contact', [
            'title'           => 'Contact — ' . site_title(),
            'metaDescription' => 'Speak to the RISE 63 provincial office on WhatsApp, by e-mail or by telephone.',
            'whatsappDigits'  => $site['whatsappDigits'],
            'whatsappDisplay' => $site['whatsappDisplay'],
            'contactEmail'    => $site['contactEmail'],
            'contactPhone'    => $site['contactPhone'],
            'topics'          => [
                'A programme'        => 'Hello RISE 63 — I would like to know more about one of the programmes.',
                'My application'     => 'Hello RISE 63 — I would like to ask about the status of my application.',
                'Education Bridge'   => 'Hello RISE 63 — I would like to ask about Education Bridge support.',
                'My parish'          => 'Hello RISE 63 — I would like to be put in touch with my parish representative.',
                'Partner with us'    => 'Hello RISE 63 — I would like to discuss supporting or partnering with RISE 63.',
                'Something is wrong' => 'Hello RISE 63 — I would like to report a problem with the platform.',
            ],
        ]);
        break;

    case 'privacy':
    case 'terms':
    case 'vision':
    case 'about':
    case 'parish-connect':
        // One handler for every CMS page. `parish-connect` is the public explanation of Phase One and
        // is seeded alongside the other four, so it is reachable from the landing page.
        $record = Site::page($page);
        if ($record === null) {
            // A page that has been unpublished, or a slug with nothing behind it. Rendering the site's
            // own 404 keeps the visitor inside the public site instead of dropping them at a sign-in
            // form, which is what the catch-all below would do.
            http_response_code(404);
            $renderSite('not-found', [
                'title'           => 'Page not found — ' . site_title(),
                'metaDescription' => 'That page is not available.',
                'sitePage'        => '',
            ]);
            break;
        }
        $renderSite('page', [
            'title'           => $record['title'] . ' — ' . site_title(),
            'metaDescription' => $record['summary'] !== '' ? $record['summary'] : Site::plain($record['body'], 158),
            'sitePageData'    => $record,
            'siteToc'         => \RISE63\Core\SiteDefaults::toc($record['body']),
            'sitePage'        => $record['slug'],
        ]);
        break;

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

        // If the public picker has nothing to show, say so here, on the page where the fix lives.
        // Before this, an empty directory was invisible until a member reported that registration
        // listed no churches — the administrator had no way to see the problem from inside the app.
        $pickerState = \RISE63\Core\ParishDirectory::pickerState();

        $render('staff/parishes', [
            'pickerState' => $pickerState,
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

        // Two queries here depend on schema that arrived after the first release. They are run
        // defensively and their failure is surfaced on the page rather than thrown: an operator
        // who sees "one column is missing, apply the pending migration" can act on it; an operator
        // who sees "Temporary problem" cannot.
        $unlisted = [];
        $schemaGap = null;
        try {
            $unlisted = Database::all(
                'SELECT parish_name_raw, COUNT(*) AS member_count
                 FROM users
                 WHERE parish_id IS NULL AND parish_name_raw IS NOT NULL AND parish_name_raw <> \'\'
                 GROUP BY parish_name_raw ORDER BY member_count DESC, parish_name_raw LIMIT 50'
            );
        } catch (\Throwable $error) {
            $schemaGap = 'The “churches members asked for” queue needs a database update that has not been applied yet (it reads users.parish_name_raw). Everything else on this page still works.';
            error_log('RISE63 people: unlisted-parish query unavailable: ' . $error->getMessage());
        }

        // This is the page an administrator comes to in order to create an account, so it is the
        // page that must warn when the schema is behind — a failed save here is how the operator
        // found out last time, and the cause was three clicks away.
        $pendingMigrations = 0;
        if ($schemaGap === null) {
            try {
                $pendingMigrations = count((new \RISE63\Core\Migrator(RISE63\ROOT))->pending());
            } catch (\Throwable $error) {
                error_log('RISE63 people: migration state unreadable: ' . $error->getMessage());
            }
        }

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
                    'SELECT u.*, COALESCE(p.parish_name, ua.area_name, uz.zone_name) AS church_name,
                            CASE WHEN p.id IS NOT NULL THEN \'parish\'
                                 WHEN ua.id IS NOT NULL THEN \'area\'
                                 WHEN uz.id IS NOT NULL THEN \'zone\' END AS church_level
                       FROM users u
                       LEFT JOIN parishes p ON p.id = u.parish_id
                       LEFT JOIN areas ua ON ua.id = u.area_id
                       LEFT JOIN zones uz ON uz.id = u.zone_id
                      WHERE u.id = ?',
                    [(int) $_GET['user']]
                  )
                : null,
            // The parish list for the edit form. Scoped to active parishes; the combobox on the
            // create form does live search, but this form is for one person, where a native picker
            // is one tap rather than three.
            // A member's church may be a parish, an area church or a zonal church, so the edit form
            // offers all three. Listing only parishes meant an admin could not correct a member who
            // had registered at the zonal church: the form showed their church as "no parish on
            // record" and the only alternative was to overwrite it with a parish they do not attend.
            // Only parishes carry `is_active`; a zone or area has no switched-off state, because the
            // hierarchy itself is the thing the church belongs to.
            'parishes' => Database::all(
                "SELECT p.id, p.parish_name, 'parish' AS level, 0 AS sort_order
                   FROM parishes p WHERE p.is_active = 1
                 UNION ALL
                 SELECT a.id, CONCAT(a.area_name, ' (Area church)'), 'area', 1
                   FROM areas a
                 UNION ALL
                 SELECT z.id, CONCAT(z.zone_name, ' (Zonal church)'), 'zone', 2
                   FROM zones z
                 ORDER BY sort_order, parish_name"
            ),
            // The queue the "add it later" promise depends on: members who typed a church that is
            // still not in the directory. Without showing it, nobody ever adds them.
            //
            // Guarded because this is the only query on the site that names `parish_name_raw`
            // explicitly, and that column arrives in migration 0002. On an installation created
            // before 0002 the whole page died with "Unknown column 'parish_name_raw'" — the card
            // below is a convenience, and a convenience must never be able to take a page of
            // record-keeping down with it. A missing column now means an empty queue and a notice.
            'unlisted' => $unlisted ?? [],
            'schemaGap' => $schemaGap ?? null,
            'pendingMigrations' => $pendingMigrations,
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

    case 'website':
        $requireAdmin();
        $site = $siteSettings();
        $pages = [];
        $slides = [];
        try {
            $pages  = Site::pages();
            $slides = Site::slides(false);
        } catch (\Throwable $error) {
            // Migration 0005 has not run. The screen must still open and say so, rather than 500 on
            // the one page an administrator would go to in order to discover that.
            error_log('RISE63 website admin: ' . $error->getMessage());
        }
        $editingPage = null;
        if (isset($_GET['page']) && $_GET['page'] !== 'new') {
            $editingPage = Site::pageForEditing((string) $_GET['page']);
        }
        $editingSlide = null;
        if (isset($_GET['slide']) && $_GET['slide'] !== 'new') {
            $editingSlide = Site::slide((int) $_GET['slide']);
        }
        $render('staff/website', [
            'title'        => 'Website',
            'pages'        => $pages,
            'slides'       => $slides,
            'editingPage'  => $editingPage,
            'editingSlide' => $editingSlide,
            'siteSettings' => $site,
            'whatsappDigits' => $site['whatsappDigits'],
            'whatsappRaw'    => $site['whatsappRaw'],
        ]);
        break;

    case 'settings':
        $requireAdmin();
        // The form names the action filter `do`, because `action` is already the POST router's.
        $audit = Audit::page(
            $_GET + ['action' => (string) ($_GET['do'] ?? '')],
            'settings'
        );
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
            // Masked: the password is replaced by a "saved" flag before it ever reaches a template,
            // so it cannot end up in the page cache, the browser history or a screenshot.
            'smtp'        => \RISE63\Core\Smtp::masked(),
            // Read-only: what migrations this database has and has not received. The People page
            // 500'd in production because migration 0002 was pending and nothing said so.
            'schemaState' => \RISE63\Core\Schema::state(RISE63\ROOT),
        ]);
        break;

    // Full activity report: every administrator and member, filterable, paged. Separate from the
    // audit card on Settings because it answers a different question — not "what happened lately"
    // but "who has been doing what", and it is the page an operator opens during a review.
    case 'audit':
        $requireAdmin();
        $report = Audit::page(
            $_GET + ['action' => (string) ($_GET['do'] ?? '')],
            'audit'
        );
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
        // The church on My account may be a parish, an area church or a zonal church — the same
        // three the picker offers. Resolving only `parish_id` left a member who registered at the
        // zonal church with a blank church on their own account page.
        $myChurch = null;
        $myLevel  = null;
        if (($user['parish_id'] ?? null) !== null) {
            $myChurch = ParishDirectory::parish((int) $user['parish_id']);
            $myLevel  = 'parish';
        } elseif (($user['area_id'] ?? null) !== null) {
            $myChurch = ParishDirectory::area((int) $user['area_id']);
            $myLevel  = 'area';
        } elseif (($user['zone_id'] ?? null) !== null) {
            $myChurch = ParishDirectory::zone((int) $user['zone_id']);
            $myLevel  = 'zone';
        }
        $render('member/profile', [
            'title'    => 'My account',
            'devices'  => Auth::devices((int) $user['id']),
            'parish'   => $myChurch,
            'churchLevel' => $myLevel,
            'pinLock'  => Auth::pinLockedFor($user),
            // Same reason as registration: the picker is an enhancement, the list is the feature.
            'parishes' => $choosable,
        ]);
        break;

    case 'site-not-found':
        // Reached two ways: index.php maps an unknown anonymous URL here, and a CMS page that has
        // been unpublished renders it directly. Either way the visitor stays inside the public site.
        http_response_code(404);
        $renderSite('not-found', [
            'title'           => 'Page not found — ' . site_title(),
            'metaDescription' => 'That page is not available.',
            'sitePage'        => '',
        ]);
        break;

    default:
        Security::fail(404, 'That page does not exist.');
}
