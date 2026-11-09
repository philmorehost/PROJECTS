<?php
declare(strict_types=1);

/**
 * POST handler.
 *
 * Every branch starts from Security::assertPost() (CSRF + origin) which the front controller
 * runs first. Invalid input raises an exception carrying a message we are happy to show;
 * anything unexpected becomes a generic message and a log line.
 */

use RISE63\Core\Applications;
use RISE63\Core\Audit;
use RISE63\Core\Auth;
use RISE63\Core\BruteForce;
use RISE63\Core\Database;
use RISE63\Core\Ledger;
use RISE63\Core\NotFound;
use RISE63\Core\ParishDirectory;
use RISE63\Core\Program;
use RISE63\Core\Security;
use RISE63\Core\Settings;
use RISE63\Core\Updater;

use function RISE63\{config_dir, e, flash, redirect, site_title};

$input  = static fn (string $key, ?string $default = null): ?string
    => isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
$array  = static fn (string $key): array => isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];
$back   = static function (string $fallback) use ($page): never {
    $target = preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['return_to'] ?? $page));
    redirect($target !== '' ? $target : $fallback);
};
/** The page a user of this role lands on. */
$home = static fn (array $account): string => (string) $account['role'] === 'youth_member' ? 'my-rise' : 'dashboard';

$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

try {
    switch ($action) {

        // ── Authentication ───────────────────────────────────────────────────────────
        case 'login':
            $result = Auth::attempt((string) $input('email', ''), (string) ($_POST['password'] ?? ''));
            if (!$result['ok']) {
                if ($result['retry_after'] !== null) {
                    header('Retry-After: ' . (int) $result['retry_after']);
                }
                flash($result['message'], 'error');
                redirect('login');
            }
            $account = Auth::user();
            flash('Welcome back, ' . ($account['full_name'] ?? 'friend') . '.', 'success');
            redirect($account === null ? 'dashboard' : $home($account));

        case 'logout':
            Auth::logout();
            flash('You have signed out.', 'success');
            redirect('login');

        case 'register':
            // Public self-registration is opt-in: a province may prefer to provision accounts itself.
            if (!Settings::bool('allow_self_registration')) {
                throw new InvalidArgumentException('Self-registration is closed. Ask your parish representative for an account.');
            }
            $account = RISE63\Core\Registration::create([
                'full_name'        => (string) $input('full_name', ''),
                'email'            => (string) $input('email', ''),
                'phone'            => (string) $input('phone', ''),
                'parish_id'        => $input('parish_id'),
                'parish_name_raw'  => (string) $input('parish_name_raw', ''),
                'password'         => (string) ($_POST['password'] ?? ''),
                'confirm'          => (string) ($_POST['password_confirm'] ?? ''),
            ]);
            flash('Your RISE 63 account is ready. Welcome!', 'success');
            Auth::attempt((string) $account['email'], (string) ($_POST['password'] ?? ''));
            redirect('my-rise');

        // ── Profile & security preferences ───────────────────────────────────────────
        case 'profile-save':
            $account = Auth::requireLogin();
            $name    = (string) $input('full_name', '');
            $phone   = (string) $input('phone', '');
            $parish  = $input('parish_id');
            if ($name === '' || mb_strlen($name) > 150) {
                throw new InvalidArgumentException('Enter your full name.');
            }
            if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{7,25}$/D', $phone) !== 1) {
                throw new InvalidArgumentException('Enter a valid phone number.');
            }
            $parishId = null;
            if ($parish !== null && $parish !== '') {
                $parishId = (int) $parish;
                if (ParishDirectory::parish($parishId) === null) {
                    throw new InvalidArgumentException('Choose your parish from the list.');
                }
            }

            // Same rule as registration: a chosen parish clears any leftover typed name, and a
            // typed name is kept only when nothing was chosen, so the two can never disagree.
            $typed = RISE63\caps((string) $input('parish_name_raw', ''));
            if ($parishId !== null) {
                $typed = '';
            } elseif ($typed !== '') {
                $match = ParishDirectory::findByName($typed);
                if ($match !== null) {
                    // Their church has been added since they last looked: attach them to it instead
                    // of leaving a duplicate request behind.
                    $parishId = (int) $match['id'];
                    $typed    = '';
                }
            }
            if (mb_strlen($typed) > 150) {
                $typed = mb_substr($typed, 0, 150);
            }
            // `parish_name_raw` is only named when it is being written — see Members::create().
            // A member whose church is in the directory must be able to save their profile on a
            // database that has not received migration 0002.
            Database::run(
                'UPDATE users SET full_name = ?, phone = ?, parish_id = ? WHERE id = ?',
                [$name, $phone === '' ? null : $phone, $parishId, (int) $account['id']]
            );
            if ($typed !== (string) ($account['parish_name_raw'] ?? '')) {
                try {
                    Database::run(
                        'UPDATE users SET parish_name_raw = ? WHERE id = ?',
                        [$typed === '' ? null : $typed, (int) $account['id']]
                    );
                } catch (\PDOException $error) {
                    // The column is absent. The name and phone were still saved; a member must not
                    // lose their correction because one optional column is missing.
                    error_log('RISE63 profile: parish_name_raw not written: ' . $error->getMessage());
                }
            }
            Audit::log((int) $account['id'], 'profile.update', 'user', (string) $account['id'], 'Profile details updated.');
            flash('Your profile has been updated.', 'success');
            $back('profile');

        case 'preference-save':
            $account = Auth::requireLogin();
            $alerts  = $input('login_notifications_enabled') === '1';
            $theme   = (string) $input('theme_preference', 'system');
            $large   = $input('large_text') === '1';
            if (!in_array($theme, ['system', 'light', 'dark'], true)) {
                throw new InvalidArgumentException('Choose a valid appearance.');
            }
            $changed = (int) $account['login_notifications_enabled'] !== ($alerts ? 1 : 0);
            Database::run(
                'UPDATE users SET login_notifications_enabled = ?, theme_preference = ?, large_text = ? WHERE id = ?',
                [$alerts ? 1 : 0, $theme, $large ? 1 : 0, (int) $account['id']]
            );
            if ($changed) {
                RISE63\Core\Notifier::preferenceChanged($account, 'Sign-in alerts', $alerts);
            }
            flash('Preferences saved.', 'success');
            $back('profile');

        case 'password-change':
            $account  = Auth::requireLogin();
            $current  = (string) ($_POST['current_password'] ?? '');
            $next     = (string) ($_POST['new_password'] ?? '');
            $confirm  = (string) ($_POST['new_password_confirm'] ?? '');
            if (!password_verify($current, (string) $account['password_hash'])) {
                throw new InvalidArgumentException('Your current password is not correct.');
            }
            $problem = Security::passwordProblem($next, (string) $account['email'], (string) $account['full_name']);
            if ($problem !== null) {
                throw new InvalidArgumentException($problem);
            }
            if (!hash_equals($next, $confirm)) {
                throw new InvalidArgumentException('The two new passwords do not match.');
            }
            if (Security::passwordReused($next, (int) $account['id'])) {
                throw new InvalidArgumentException('Choose a password you have not used on this account before.');
            }
            Database::run(
                'UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), must_change_password = 0 WHERE id = ?',
                [Security::hashPassword($next), (int) $account['id']]
            );
            Audit::log((int) $account['id'], 'password.change', 'user', (string) $account['id'], 'Password changed by the account holder.');
            RISE63\Core\Notifier::securityNotice(
                $account,
                'your password was changed',
                'Your password was changed',
                '<p>Your RISE 63 password was changed from ' . RISE63\e(Security::clientIp()) . ' at '
                . RISE63\e(gmdate('j M Y, H:i') . ' UTC') . '.</p><p>Other devices were signed out. If this was not you, contact the province office.</p>'
            );
            flash('Password changed. Other devices were signed out.', 'success');
            Auth::revokeOthers((int) $account['id']);
            $back('change-password' === (string) $input('return_to', 'profile') ? 'my-rise' : 'profile');

        // ── Password reset (signed out) ──────────────────────────────────────────────
        case 'password-reset-request':
            // Always the same answer, with or without an account, so this cannot be used to
            // discover who is registered.
            RISE63\Core\PasswordReset::request((string) $input('email', ''), Security::clientIp());
            flash(RISE63\Core\PasswordReset::NEUTRAL_MESSAGE, 'success');
            redirect('forgot-password', ['sent' => '1']);

        case 'password-reset-complete':
            try {
                $resetUser = RISE63\Core\PasswordReset::complete(
                    (string) $input('token', ''),
                    (string) ($_POST['new_password'] ?? ''),
                    (string) ($_POST['new_password_confirm'] ?? ''),
                    Security::clientIp()
                );
            } catch (InvalidArgumentException $error) {
                flash($error->getMessage(), 'error');
                redirect('forgot-password');
            }
            // The member just proved control of the mailbox and chose the password, so sign them in.
            Auth::loginAs($resetUser);
            flash('Your password has been changed and you are signed in.', 'success');
            redirect($home($resetUser));

        case 'pin-set':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin', 'provincial_treasurer']);
            if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $account['password_hash'])) {
                throw new InvalidArgumentException('Your password is required to set a transaction PIN.');
            }
            Auth::setPin((int) $account['id'], (string) ($_POST['pin'] ?? ''));
            Audit::log((int) $account['id'], 'pin.set', 'user', (string) $account['id'], 'Transaction PIN set.');
            flash('Transaction PIN updated.', 'success');
            $back('profile');

        case 'revoke-devices':
            $account = Auth::requireLogin();
            $count   = Auth::revokeOthers((int) $account['id']);
            flash($count === 0 ? 'No other device sessions were active.' : $count . ' other device session(s) signed out.', 'success');
            $back('profile');

        case 'theme':
            // Works without JavaScript: the preference is stored on the account and in a cookie.
            $account = Auth::requireLogin();
            $theme   = (string) $input('theme', 'system');
            if (!in_array($theme, ['system', 'light', 'dark'], true)) {
                $theme = 'system';
            }
            Database::run('UPDATE users SET theme_preference = ? WHERE id = ?', [$theme, (int) $account['id']]);
            setcookie('rise63_theme', $theme, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true, 'secure' => Security::isHttps()]);
            $back('dashboard');

        // ── Applications ─────────────────────────────────────────────────────────────
        case 'application-transition':
            $account = Auth::requireLogin();
            $id      = (int) ($_POST['application_id'] ?? 0);
            $to      = (string) $input('to_status', '');
            if ($id <= 0) {
                throw new InvalidArgumentException('Choose an application.');
            }
            // Members may only act on their own record; reviewers are scoped by role.
            $candidate = Applications::find($id, $account);
            if ($candidate === null) {
                throw new NotFound('That application is not available to you.');
            }
            Applications::transition($id, $account, $to, (string) $input('note', ''));
            flash('Application moved to ' . Program::statusLabel($to) . '.', 'success');
            $back('applications');

        case 'academy-save':
            $account     = Auth::requireLogin();
            $id          = (int) ($_POST['application_id'] ?? 0);
            $application = Applications::find($id, $account);
            if ($application === null) {
                throw new NotFound('That application is not available to you.');
            }
            RISE63\Core\Academy::save($application, $account, $_POST);
            flash('Academy attendance saved.', 'success');
            redirect('application', ['id' => $id]);

        case 'application-withdraw':
            $account = Auth::requireLogin();
            $id      = (int) ($_POST['application_id'] ?? 0);
            $application = Applications::find($id, $account);
            if ($application === null || (int) $application['user_id'] !== (int) $account['id']) {
                throw new NotFound('That application is not available to you.');
            }
            Applications::transition($id, $account, 'withdrawn', (string) $input('note', 'Withdrawn by the applicant.'));
            flash('Application withdrawn.', 'success');
            redirect('my-rise');

        case 'document-upload':
            // Optional attachment chosen on the apply form, plus later uploads from the detail page.
            $account = Auth::requireLogin();
            $type    = (string) $input('program_type', '');

            if ($type !== '') {
                // Coming straight from the apply form: create the application, then attach.
                $result = Applications::submit($account, $type, [
                    'education_level'  => (string) $input('education_level', 'none'),
                    'school_name'      => (string) $input('school_name', ''),
                    'school_class'     => (string) $input('school_class', ''),
                    'requested_amount' => (string) $input('requested_amount', '0'),
                    'answers'          => $array('answers'),
                    'idempotency_key'  => (string) $input('idempotency_key', ''),
                ]);
                $id = $result['id'];
                if (($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $document = Security::storeUpload($_FILES['document'] ?? []);
                    $document['type'] = 'supporting document';
                    Applications::attachDocument($id, $document, (int) $account['id']);
                }
                flash('Application received' . (isset($document) ? ' with your document' : '') . '. We will review it shortly.', 'success');
                redirect('application', ['id' => $id]);
            }

            $id          = (int) ($_POST['application_id'] ?? 0);
            $application = Applications::find($id, $account);
            if ($application === null || (int) $application['user_id'] !== (int) $account['id']) {
                throw new NotFound('That application is not available to you.');
            }
            $document = Security::storeUpload($_FILES['document'] ?? []);
            $document['type'] = (string) $input('document_type', 'supporting document');
            Applications::attachDocument($id, $document, (int) $account['id']);
            flash('Document uploaded: ' . $document['name'], 'success');
            redirect('application', ['id' => $id]);

        // ── Parish Connect ───────────────────────────────────────────────────────────
        case 'parish-create':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin', 'csr_rep']);
            $id = ParishDirectory::createParish([
                'parish_name'     => (string) $input('parish_name', ''),
                'area_id'         => (int) ($_POST['area_id'] ?? 0),
                'parish_code'     => $input('parish_code'),
                'worship_address' => (string) $input('worship_address', ''),
                'yaya_rep_name'   => (string) $input('yaya_rep_name', ''),
                'yaya_rep_phone'  => (string) $input('yaya_rep_phone', ''),
                'youth_count'     => (int) ($_POST['youth_count'] ?? 0),
                'needs_summary'   => (string) $input('needs_summary', ''),
            ], (int) $account['id']);
            flash('Parish added to the directory.', 'success');
            redirect('parish', ['id' => $id]);

        case 'parish-update':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin', 'csr_rep']);
            $id = (int) ($_POST['parish_id'] ?? 0);
            if ($account['role'] === 'csr_rep' && (int) $account['parish_id'] !== $id) {
                Security::fail(403, 'You can only update your own parish.');
            }
            ParishDirectory::updateParish($id, [
                'parish_name'     => (string) $input('parish_name', ''),
                'area_id'         => (int) ($_POST['area_id'] ?? 0),
                'parish_code'     => $input('parish_code'),
                'worship_address' => (string) $input('worship_address', ''),
                'yaya_rep_name'   => (string) $input('yaya_rep_name', ''),
                'yaya_rep_phone'  => (string) $input('yaya_rep_phone', ''),
                'youth_count'     => (int) ($_POST['youth_count'] ?? 0),
                'needs_summary'   => (string) $input('needs_summary', ''),
                'is_active'       => $input('is_active') === '1',
            ], (int) $account['id']);
            flash('Parish details saved.', 'success');
            $back('parishes');

        case 'zone-create':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            ParishDirectory::createZone((string) $input('zone_name', ''), $input('zone_code'), (int) $account['id']);
            flash('Zone created.', 'success');
            $back('parishes');

        case 'area-create':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            ParishDirectory::createArea((int) ($_POST['zone_id'] ?? 0), (string) $input('area_name', ''), $input('area_code'), (int) $account['id']);
            flash('Area created.', 'success');
            $back('parishes');

        /**
         * The bulk paste. Two modes on purpose: "preview" writes nothing and shows what the list
         * would create, "apply" writes. A single button that both counted and wrote would be
         * useless for the one thing that matters here — checking 200 pasted lines against the
         * sheet they came from before they become rows.
         *
         * The preview text is parked in the session and read back by the parishes page, so the
         * redirect after the POST does not lose the pasted list (PRG, no JavaScript required).
         */
        case 'directory-import':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $text = (string) ($_POST['hierarchy'] ?? '');
            $mode = (string) ($_POST['mode'] ?? 'preview') === 'apply' ? 'apply' : 'preview';

            $parsed = RISE63\Core\DirectoryImport::parse($text);
            if ($parsed['lines'] === []) {
                unset($_SESSION['directory_import']);
                flash(
                    $parsed['skipped'] === []
                        ? 'Nothing to add — paste one church per line, e.g. “GRACE ZONE, HOPE AREA, LIVING WATER PARISH”.'
                        : 'No usable lines in that list. Fix the ' . count($parsed['skipped']) . ' skipped line(s) and try again.',
                    'error'
                );
                $back('parishes');
            }

            if ($mode === 'preview') {
                $_SESSION['directory_import'] = [
                    'text' => mb_substr($text, 0, 300000),
                    'at'   => time(),
                ];
                redirect('parishes');
            }

            $tally = ParishDirectory::importLines($parsed['lines'], (int) $account['id']);
            unset($_SESSION['directory_import']);

            $message = 'Directory updated: '
                . $tally['created'] . ' new record(s) added, '
                . $tally['existing'] . ' already existed. '
                . $tally['zones'] . ' zone(s), ' . $tally['areas'] . ' area(s) and '
                . $tally['parishes'] . ' parish(es) in this list.';
            if ($parsed['skipped'] !== []) {
                $message .= ' ' . count($parsed['skipped']) . ' line(s) were skipped — see the preview for the reason.';
            }
            flash($message, $parsed['skipped'] === [] ? 'success' : 'error');
            $back('parishes');

        // ── Treasury ─────────────────────────────────────────────────────────────────
        case 'remittance-request':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin', 'provincial_treasurer']);
            $application = Applications::find((int) ($_POST['application_id'] ?? 0), $account);
            if ($application === null) {
                throw new NotFound('That application is not available.');
            }
            Ledger::requestDisbursement($application, $account, $_POST);
            flash('Disbursement queued. Confirm it with your transaction PIN.', 'success');
            redirect('treasury');

        case 'remittance-confirm':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin', 'provincial_treasurer']);
            $locked = Auth::pinLockedFor($account);
            if ($locked > 0) {
                throw new InvalidArgumentException('The transaction PIN is locked for another ' . max(1, (int) ceil($locked / 60)) . ' minute(s).');
            }
            if (!Auth::verifyPin($account, (string) ($_POST['pin'] ?? ''))) {
                throw new InvalidArgumentException('That transaction PIN is not correct.');
            }
            $record = Ledger::confirm((int) ($_POST['remittance_id'] ?? 0), $account);
            flash('Disbursement confirmed: ' . RISE63\Core\Money::fromDecimal($record['amount'])->format() . '.', 'success');
            redirect('treasury');

        // ── Program funds ────────────────────────────────────────────────────────────
        // "Where does the admin set the Seed Grant pool left?" The figure on the treasury screen is
        // a real balance now, and these are the two ways it changes. They are deliberately not the
        // same kind of write: a budget decision (set-pool) is audited because it is a decision, and
        // money arriving (deposit) is ledgered because it is an event. Putting both in the ledger
        // would count the same naira twice.
        case 'fund-set-pool':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $program = (string) $input('program', 'seed_grant');
            $year    = (int) ($_POST['cycle_year'] ?? Settings::cycleYear());
            $amount  = RISE63\Core\Money::fromNaira((string) ($_POST['amount'] ?? ''));
            $remaining = RISE63\Core\Fund::setPool($program, $year, $amount, (int) $account['id'], trim((string) $input('reason', '')));
            flash(
                RISE63\Core\Fund::LABELS[$program] . ' pool for ' . $year . ' set to ' . $amount->format()
                . '. Remaining: ' . $remaining->format() . '.',
                'success'
            );
            $back('treasury');

        case 'fund-deposit':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin', 'provincial_treasurer']);
            $program = (string) $input('program', 'seed_grant');
            $amount  = RISE63\Core\Money::fromNaira((string) ($_POST['amount'] ?? ''));
            RISE63\Core\Fund::deposit($program, $amount, (int) $account['id'], trim((string) $input('note', '')));
            flash($amount->format() . ' added to ' . RISE63\Core\Fund::LABELS[$program] . '.', 'success');
            $back('treasury');

        // ── Administration ───────────────────────────────────────────────────────────
        case 'settings-save':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $group   = (string) $input('group', '');
            $values  = [];
            foreach ($array('settings') as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }
                $values[$key] = (string) $value;
            }
            foreach ($array('settings_bool') as $key => $value) {
                $values[(string) $key] = $value === '1' ? '1' : '0';
            }
            Settings::setMany($values, (int) $account['id']);
            BruteForce::log(Security::clientIp(), null, (string) $account['email'], 'settings_changed', 'Group: ' . $group);
            flash('Settings saved.', 'success');
            $back('settings');

        case 'security-save':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $values = [];
            foreach ($array('settings') as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }
                $values[$key] = (string) $value;
            }
            foreach ($array('settings_bool') as $key => $value) {
                $values[(string) $key] = $value === '1' ? '1' : '0';
            }
            Settings::setMany($values, (int) $account['id']);
            BruteForce::log(Security::clientIp(), null, (string) $account['email'], 'settings_changed', 'Security policy updated.');
            flash('Security policy saved. Existing suspensions are unchanged.', 'success');
            $back('security');

        case 'country-rule':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $code   = strtoupper((string) $input('country_code', ''));
            $rule   = (string) $input('action', 'not_specified');
            if (preg_match('/^[A-Z]{2}$/D', $code) !== 1 || !in_array($rule, ['whitelisted', 'not_specified', 'blacklisted'], true)) {
                throw new InvalidArgumentException('That country rule is not valid.');
            }
            Database::run('UPDATE country_rules SET action_code = ? WHERE country_code = ?', [$rule, $code]);
            BruteForce::log(Security::clientIp(), $code, (string) $account['email'], 'settings_changed', 'Country ' . $code . ' set to ' . $rule . '.');
            if ($wantsJson) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'code' => $code, 'action' => $rule]);
                exit;
            }
            flash('Country rule updated.', 'success');
            $back('security');

        case 'access-rule':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $type   = (string) $input('rule_type', 'ip');
            $target = trim((string) $input('target_value', ''));
            $next   = (string) $input('action', 'whitelisted');
            if (!in_array($type, ['ip', 'username'], true) || !in_array($next, ['whitelisted', 'blacklisted'], true)) {
                throw new InvalidArgumentException('That rule is not valid.');
            }
            if ($type === 'ip' && filter_var($target, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Enter a single IPv4 or IPv6 address.');
            }
            if ($type === 'username') {
                $target = strtolower($target);
                if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter the account e-mail address.');
                }
                if ($next === 'blacklisted' && $target === strtolower((string) $account['email'])) {
                    throw new InvalidArgumentException('You cannot blacklist your own account.');
                }
            }
            if ($target === Security::clientIp() && $next === 'blacklisted') {
                throw new InvalidArgumentException('You cannot blacklist the address you are using.');
            }
            Database::run(
                'INSERT INTO manual_access_rules (rule_type, target_value, action, note, created_by) VALUES (?,?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE created_at = UTC_TIMESTAMP()',
                [$type, $target, $next, (string) $input('note', ''), (int) $account['id']]
            );
            if ($type === 'ip' && $next === 'whitelisted') {
                Database::run('INSERT INTO security_ips (ip_address, whitelisted) VALUES (?,1) ON DUPLICATE KEY UPDATE whitelisted = 1', [$target]);
            }
            Audit::log((int) $account['id'], 'access.rule', 'access_rule', $target, $type . ' ' . $next);
            flash('Access rule saved.', 'success');
            $back('security');

        case 'access-rule-delete':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            Database::run('DELETE FROM manual_access_rules WHERE id = ?', [(int) ($_POST['rule_id'] ?? 0)]);
            flash('Access rule removed.', 'success');
            $back('security');

        case 'ip-unblock':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $ip = (string) $input('ip', '');
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('That IP address is not valid.');
            }
            Database::run('UPDATE security_ips SET blocked_until = NULL, escalation_level = 0 WHERE ip_address = ?', [$ip]);
            BruteForce::log($ip, null, (string) $account['email'], 'ip_unblocked', 'Block cleared by an administrator.');
            if (Settings::bool('firewall_enabled')) {
                Database::run('INSERT INTO firewall_jobs (ip_address, action, block_until) VALUES (?,?,UTC_TIMESTAMP())', [$ip, 'unblock']);
            }
            Audit::log((int) $account['id'], 'ip.unblock', 'ip', $ip, 'Block cleared.');
            flash('Block cleared for ' . $ip . '.', 'success');
            $back('security');

        case 'account-suspend':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $targetId = (int) ($_POST['user_id'] ?? 0);
            // Members::setSuspended carries the guards (no self-suspension, never the last active
            // administrator) so the same rules apply whether the button is on this page or another.
            RISE63\Core\Members::setSuspended($targetId, $input('suspend') === '1', $account);
            flash($input('suspend') === '1' ? 'Account suspended and signed out everywhere.' : 'Account reinstated.', 'success');
            $back('people');

        case 'user-create':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $created = RISE63\Core\Members::create([
                'full_name'  => (string) $input('full_name', ''),
                'email'      => (string) $input('email', ''),
                'phone'      => (string) $input('phone', ''),
                'parish_id'  => $input('parish_id'),
                'parish_name_raw' => (string) $input('parish_name_raw', ''),
                'role'       => (string) $input('role', 'youth_member'),
                'password'   => (string) ($_POST['password'] ?? ''),
            ], $account);
            flash(
                'Account created for ' . (string) $created['user']['email'] . '. Temporary password: ' . $created['password']
                . ' — write it down now; it cannot be shown again.',
                'success'
            );
            $back('people');

        case 'user-update':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $targetId = (int) ($_POST['user_id'] ?? 0);
            RISE63\Core\Members::update($targetId, [
                'full_name'       => (string) $input('full_name', ''),
                'email'           => (string) $input('email', ''),
                'phone'           => (string) $input('phone', ''),
                'parish_id'       => $input('parish_id'),
                'parish_name_raw' => (string) $input('parish_name_raw', ''),
            ], $account);
            flash('Details saved.', 'success');
            redirect('people', ['user' => $targetId]);

        case 'user-delete':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            RISE63\Core\Members::delete((int) ($_POST['user_id'] ?? 0), $account);
            flash('Account deleted.', 'success');
            $back('people');

        // Impersonation: "sign in as this member" for support. Only members, never another
        // administrator, and the interface banners the session while it lasts.
        case 'user-login-as':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $target = RISE63\Core\Members::impersonate((int) ($_POST['user_id'] ?? 0), $account);
            flash('You are signed in as ' . (string) $target['full_name'] . '. Use "Return to my account" when you are done.', 'info');
            redirect('my-rise');

        case 'user-login-as-stop':
            Auth::requireLogin();
            RISE63\Core\Members::stopImpersonating();
            flash('Back in your own account.', 'success');
            redirect('people');

        case 'user-admin-create':
            // A second administrator, so the province is not one forgotten password away from a
            // database console. Same write path as any other account; only the role differs.
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $created = RISE63\Core\Members::create([
                'full_name'  => (string) $input('full_name', ''),
                'email'      => (string) $input('email', ''),
                'phone'      => (string) $input('phone', ''),
                'parish_id'  => $input('parish_id'),
                'role'       => 'provincial_admin',
                'password'   => (string) ($_POST['password'] ?? ''),
            ], $account);
            flash(
                'Administrator created: ' . (string) $created['user']['email'] . '. Temporary password: ' . $created['password']
                . ' — write it down now; it cannot be shown again.',
                'success'
            );
            $back('people');

        case 'user-temporary-password':
            $account = Auth::requireLogin();
            Auth::requireAdmin($account);
            $target = Database::one('SELECT * FROM users WHERE id = ?', [(int) ($_POST['user_id'] ?? 0)]);
            if ($target === null) {
                throw new NotFound('That account does not exist.');
            }
            if ((int) $target['id'] === (int) $account['id']) {
                throw new InvalidArgumentException('Use My account to change your own password.');
            }
            $temporary = RISE63\Core\PasswordReset::issueTemporary($target, $account);
            // Shown once, never stored in the clear and never queued: an administrator reads it out
            // or writes it down and hands it over in person.
            flash('Temporary password for ' . (string) $target['full_name'] . ': ' . $temporary
                . ' — write it down now; it cannot be shown again.', 'success');
            redirect('people', ['user' => (int) $target['id']]);

        case 'user-role':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $role     = (string) $input('role', '');
            if (!isset(Auth::ROLES[$role])) {
                throw new InvalidArgumentException('Choose a valid role.');
            }
            if ($targetId === (int) $account['id'] && $role !== 'provincial_admin') {
                throw new InvalidArgumentException('You cannot remove your own administrator role.');
            }
            if ($role !== 'provincial_admin' && (string) Database::value('SELECT role FROM users WHERE id = ?', [$targetId]) === 'provincial_admin'
                && (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'provincial_admin' AND is_suspended = 0") <= 1) {
                throw new InvalidArgumentException('The last active provincial administrator must keep that role.');
            }
            Database::run('UPDATE users SET role = ? WHERE id = ?', [$role, $targetId]);
            Audit::log((int) $account['id'], 'user.role', 'user', (string) $targetId, $role);
            flash('Role updated.', 'success');
            $back('people');

        case 'queue-test':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            RISE63\Core\Notifier::queue(
                (string) ($account['email'] ?? ''),
                'RISE 63 notification test',
                RISE63\Core\Notifier::wrap('Notification pipeline test', '<p>This message confirms the queue accepts and renders HTML mail. Delivery requires <code>bin/mail-worker.php</code> to run from cron.</p>'),
                'test'
            );
            flash('Test message queued. Delivery happens on the next mail worker run.', 'success');
            $back('settings');

        // ── Mail (SMTP) ──────────────────────────────────────────────────────────────
        // config/smtp.php used to be hand-made over SSH, and a typo in it produced no error at all —
        // just password resets that never arrived. These handlers validate before writing, keep the
        // stored password when the field is left blank, and can prove the transport works without
        // sending anything.
        case 'smtp-save':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            try {
                RISE63\Core\Smtp::save($_POST);
            } catch (\Throwable $error) {
                flash($error->getMessage(), 'error');
                $back('settings');
            }
            // The audit row names the transport, never the credential or the mailbox username.
            Audit::log((int) $account['id'], 'settings.smtp', 'config', 'smtp', RISE63\Core\Smtp::summary());
            BruteForce::log(Security::clientIp(), null, (string) $account['email'], 'settings_changed', 'SMTP saved');
            flash('Mail settings saved. Use “Test the connection” to confirm the server accepts them.', 'success');
            $back('settings');

        case 'smtp-test':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $result = RISE63\Core\Smtp::test();
            flash($result['message'], $result['ok'] ? 'success' : 'error');
            $back('settings');

        case 'smtp-remove':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            RISE63\Core\Smtp::forget();
            Audit::log((int) $account['id'], 'settings.smtp', 'config', 'smtp', 'SMTP configuration removed');
            flash('Mail settings removed. Self-service password reset is now switched off.', 'success');
            $back('settings');

        case 'smtp-send-test':
            // A queued message to the signed-in administrator's own address: the point is to test
            // delivery (SPF, DKIM, spam folder), which a connection test cannot.
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            if (!RISE63\Core\Smtp::configured()) {
                flash('Save the mail settings first — there is no transport to send through.', 'error');
                $back('settings');
            }
            $to = (string) ($account['email'] ?? '');
            RISE63\Core\Notifier::queue(
                $to,
                'RISE 63 mail test',
                RISE63\Core\Notifier::wrap(
                    'Mail is configured',
                    '<p>This message was sent from Settings → Mail to confirm that RISE 63 can deliver mail.</p>'
                    . '<p>If you are reading it, the transport works. If it arrived in your spam folder, the sending domain still needs SPF and DKIM records before members will trust reset links.</p>'
                ),
                'test'
            );
            BruteForce::log(Security::clientIp(), null, (string) $account['email'], 'mail_test', 'Test message queued to ' . $to);
            flash('A test message is queued for ' . $to . '. It is delivered on the next mail worker run (cron every five minutes).', 'success');
            $back('settings');

        // ── Updates ──────────────────────────────────────────────────────────────────
        // Three steps, deliberately separate: ask what is published, download and inspect it, then
        // apply. The download step is the one that writes a file to disk (under storage/updates/),
        // and the apply step is the only one that writes into the installation.
        case 'update-check':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $updater = new Updater(RISE63\ROOT, config_dir(), RISE63\ROOT . '/storage');
            $check   = $updater->check();

            $_SESSION['rise63_update_check'] = $check;
            unset($_SESSION['rise63_update_plan']);

            if ($check['ok']) {
                flash(
                    version_compare((string) $check['version'], $updater->currentVersion(), '>')
                        ? 'Version ' . $check['version'] . ' is available.'
                        : 'This installation is already on the latest published release (' . $updater->currentVersion() . ').',
                    'success'
                );
            } else {
                flash((string) $check['message'], 'error');
            }
            $back('updates');

        case 'update-plan':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $updater = new Updater(RISE63\ROOT, config_dir(), RISE63\ROOT . '/storage');
            $check   = $_SESSION['rise63_update_check'] ?? null;
            if (!is_array($check) || ($check['ok'] ?? false) !== true || (string) ($check['download_url'] ?? '') === '') {
                throw new RuntimeException('Check for an update again: the release details are no longer held in this session.');
            }

            $download = $updater->download((string) $check['download_url'], (string) ($check['checksum'] ?? ''));
            $plan     = $updater->plan($download['archive'], $download['sha256']);
            if (is_array($_SESSION['rise63_update_check'] ?? null)) {
                $plan['tier']      = (string) ($_SESSION['rise63_update_check']['tier'] ?? '');
                $plan['changelog'] = (string) ($_SESSION['rise63_update_check']['changelog'] ?? '');
            }
            $_SESSION['rise63_update_plan'] = $plan;

            flash(
                $plan['ok']
                    ? 'Release ' . ($plan['version'] !== '' ? $plan['version'] : $check['version']) . ' downloaded and verified. Review it below.'
                    : 'The release was downloaded but cannot be applied safely. See the problems below.',
                $plan['ok'] ? 'success' : 'error'
            );
            $back('updates');

        case 'update-apply':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $updater = new Updater(RISE63\ROOT, config_dir(), RISE63\ROOT . '/storage');
            $plan    = $_SESSION['rise63_update_plan'] ?? null;
            if (!is_array($plan) || !is_file((string) ($plan['archive'] ?? ''))) {
                throw new RuntimeException('Review the release again before applying it: the download is no longer on disk.');
            }
            if (($plan['ok'] ?? false) !== true) {
                throw new RuntimeException('This release was rejected when it was inspected, so it will not be applied.');
            }

            $result = $updater->apply((string) $plan['archive'], $plan);
            $result['version'] = (string) ($plan['version'] ?? '');
            $_SESSION['rise63_update_result'] = $result;
            unset($_SESSION['rise63_update_plan']);

            Audit::log(
                (int) $account['id'],
                'system.update',
                'installation',
                $result['version'] !== '' ? $result['version'] : null,
                ($result['ok'] ? 'Applied' : 'Attempted')
                    . ' release ' . ($result['version'] !== '' ? $result['version'] : '(unversioned)')
                    . ': ' . $result['written'] . ' file(s) written, '
                    . $result['removed'] . ' removed, ' . $result['sql_run'] . ' SQL statement(s)'
                    . ($result['sql_failed'] > 0 ? ', ' . $result['sql_failed'] . ' SQL failure(s)' : '')
            );

            // An update replaces files; it does not migrate the database. A release that ships a new
            // migration therefore leaves this installation running new code against an old schema —
            // which is exactly how account creation broke after 1.0.1. So the moment the files land,
            // say whether the schema is now behind, and say it in the same breath.
            $behind = '';
            try {
                $pending = (new RISE63\Core\Migrator(RISE63\ROOT))->pending();
                if ($pending !== []) {
                    $behind = ' ' . count($pending) . ' database update(s) are waiting: run php bin/migrate.php.';
                }
            } catch (\Throwable $error) {
                $behind = ' The database update state could not be read: ' . $error->getMessage();
            }

            flash(
                $result['ok']
                    ? 'Updated to ' . ($result['version'] !== '' ? $result['version'] : 'the new release') . '.' . $behind
                    : 'The update was not applied. Nothing was changed.',
                $result['ok'] && $behind === '' ? 'success' : 'error'
            );
            $back('updates');

        case 'run-maintenance':
            $account = Auth::requireLogin();
            Auth::requireRole($account, ['provincial_admin']);
            $removed = BruteForce::prune();
            flash('Maintenance complete: ' . $removed . ' expired failure log row(s) removed.', 'success');
            $back('security');

        default:
            throw new InvalidArgumentException('That action is not recognised.');
    }
} catch (InvalidArgumentException | RISE63\Core\DuplicateApplication $error) {
    flash($error->getMessage(), 'error');
    $back($page !== '' ? $page : 'dashboard');
} catch (RISE63\Core\StatusConflict $error) {
    http_response_code(409);
    flash($error->getMessage(), 'error');
    $back($page !== '' ? $page : 'dashboard');
} catch (NotFound $error) {
    http_response_code(404);
    flash($error->getMessage(), 'error');
    $back('dashboard');
} catch (\PDOException $error) {
    // A database error the operator caused is worth naming; one they cannot act on is not. The
    // first version of this handler collapsed everything into "The record could not be saved.
    // Please try again." — which is what a missing migration looked like for three releases: the
    // cause was one screen away, in Settings → Database updates, and the message pointed nowhere.
    error_log('RISE63 PDO: ' . $error->getMessage());
    // 42S22 = unknown column, 42S02 = unknown table. Both mean the same thing to an operator: this
    // database is a migration behind the code.
    $state = in_array((string) $error->getCode(), ['42S22', '42S02'], true) ? 'missing_schema'
        : ($error->getCode() === '23000' ? 'duplicate' : 'unknown');
    if ($state === 'duplicate') {
        flash('That record already exists. Check the parish name, code or e-mail address.', 'error');
    } elseif ($state === 'missing_schema') {
        // SQLSTATE 42S22 / 42S02: a column or table the code expects is not in this database.
        // That is almost always an unapplied migration, and the fix is one command.
        flash(
            'This saved nothing: a database update is waiting. Open Settings → Database updates and run '
            . 'php bin/migrate.php on the server, then try again.',
            'error'
        );
    } else {
        flash('The record could not be saved. Please try again.', 'error');
    }
    $back($page !== '' ? $page : 'dashboard');
} catch (RISE63\Core\ActivationRequired $error) {
    flash($error->getMessage(), 'error');
    redirect('dashboard');
}
