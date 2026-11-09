<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * The administrator's side of the member lifecycle: create, edit, suspend, reinstate, delete, and
 * sign in as.
 *
 * Rules that are enforced here rather than in the form, because a form is a suggestion and this is
 * the only thing standing between an administrator and the province's records:
 *
 *  - **You cannot lock yourself out.** No self-suspension, no self-deletion, no self-demotion from
 *    the last admin, and no deleting or suspending the last active administrator. Losing the only
 *    administrator account on a shared-hosting deployment means a database console to recover, and
 *    most operators do not have one.
 *  - **Deletion is for accounts with no history.** `program_applications.user_id` is
 *    `ON DELETE CASCADE`, so deleting a member would silently delete their applications and, with
 *    them, the record of money that may have been paid out. So a member who has ever applied cannot
 *    be deleted — they are suspended instead, which keeps the ledger's counterparties intact and is
 *    also what an auditor would expect. The refusal says so, and names the alternative.
 *  - **Every change is audited with the actor, the target and the before/after values.** "Who
 *    changed this person's e-mail" is answerable.
 *  - **Church names are capitals.** Typed names are upper-cased, never merely warned about.
 */
final class Members
{
    /**
     * Create a member or an additional administrator.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array{user: array<string, mixed>, password: string}
     */
    public static function create(array $input, array $actor): array
    {
        $name  = trim((string) ($input['full_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $role  = (string) ($input['role'] ?? 'youth_member');
        $password = (string) ($input['password'] ?? '');

        if (!isset(Auth::ROLES[$role])) {
            throw new InvalidArgumentException('Choose a valid role.');
        }
        // Only a provincial administrator may mint another one. Enforced again in the action, but
        // stated here too so the rule lives with the write.
        if ($role === 'provincial_admin' && !Auth::isAdmin($actor)) {
            throw new InvalidArgumentException('Only a provincial administrator can create another administrator.');
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Enter the person\'s full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            throw new InvalidArgumentException('Enter a valid e-mail address.');
        }
        if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{7,25}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('Enter a valid phone number, or leave it blank.');
        }
        if ($password === '') {
            $password = self::temporaryPassword();
        }
        $problem = Security::passwordProblem($password, $email, $name);
        if ($problem !== null) {
            throw new InvalidArgumentException('Choose a stronger temporary password. ' . $problem);
        }
        if (Database::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            throw new InvalidArgumentException('That e-mail address is already registered.');
        }

        [$parishId, $unlisted] = self::resolveParish($input);

        // `parish_name_raw` is written only when there is a name to write. It arrived in migration
        // 0002, and naming a column that a database does not have fails the whole statement — which
        // is how "create an administrator" died with a message that named no cause at all. Every
        // account created with a parish from the directory, or with none, now works on both schemas.
        if ($unlisted !== null && $unlisted !== '') {
            Database::run(
                'INSERT INTO users (full_name, email, phone, parish_id, parish_name_raw, password_hash, role, must_change_password)
                 VALUES (?,?,?,?,?,?,?,?)',
                [$name, $email, $phone === '' ? null : $phone, $parishId, $unlisted, Security::hashPassword($password), $role, 1]
            );
        } else {
            Database::run(
                'INSERT INTO users (full_name, email, phone, parish_id, password_hash, role, must_change_password)
                 VALUES (?,?,?,?,?,?,?)',
                [$name, $email, $phone === '' ? null : $phone, $parishId, Security::hashPassword($password), $role, 1]
            );
        }
        $id = Database::id();

        Audit::log(
            (int) $actor['id'],
            $role === 'provincial_admin' ? 'admin.create' : 'user.create',
            'user',
            (string) $id,
            $name . ' <' . $email . '> as ' . (string) (Auth::ROLES[$role] ?? $role)
        );

        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]) ?? [];
        if ($user !== []) {
            Notifier::welcome($user);
        }

        // Returned once, in the flash, so the administrator can hand it over. Never mailed: a
        // temporary password in an inbox is a password in at least three systems.
        return ['user' => $user, 'password' => $password];
    }

    /**
     * Edit a member's own details.
     *
     * Role is deliberately not editable here — it has its own action and its own guards, and mixing
     * "fix a typo in a name" with "make this person an administrator" in one form is how the wrong
     * field gets submitted.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed> the updated row
     */
    public static function update(int $userId, array $input, array $actor): array
    {
        $target = self::find($userId);
        $name   = trim((string) ($input['full_name'] ?? ''));
        $email  = strtolower(trim((string) ($input['email'] ?? '')));
        $phone  = trim((string) ($input['phone'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Enter the person\'s full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            throw new InvalidArgumentException('Enter a valid e-mail address.');
        }
        if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{7,25}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('Enter a valid phone number, or leave it blank.');
        }
        $taken = Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId]);
        if ($taken !== null) {
            throw new InvalidArgumentException('Another account already uses that e-mail address.');
        }
        if ($email !== (string) $target['email'] && $userId === (int) $actor['id']) {
            // Changing your own sign-in address mid-session is a way to lose access to the account
            // you are holding. My account is the place for that, where it can ask for the password.
            throw new InvalidArgumentException('Change your own e-mail address from My account, where your password is confirmed.');
        }

        [$parishId, $unlisted] = self::resolveParish($input);

        // Same rule as create(): do not name a column that may not exist. Only touch
        // `parish_name_raw` when the value is actually changing, so a plain "fix the phone number"
        // edit needs nothing from migration 0002 either.
        $currentRaw = (string) ($target['parish_name_raw'] ?? '');
        $newRaw     = (string) ($unlisted ?? '');
        Database::run(
            'UPDATE users SET full_name = ?, email = ?, phone = ?, parish_id = ? WHERE id = ?',
            [$name, $email, $phone === '' ? null : $phone, $parishId, $userId]
        );
        if ($newRaw !== $currentRaw) {
            try {
                Database::run('UPDATE users SET parish_name_raw = ? WHERE id = ?', [$newRaw === '' ? null : $newRaw, $userId]);
            } catch (\PDOException $error) {
                // The column is absent (migration 0002 not applied). The rest of the edit has been
                // saved; refusing the whole thing would lose the operator's correction.
                error_log('RISE63 Members::update: parish_name_raw not written: ' . $error->getMessage());
            }
        }

        $changes = [];
        foreach (['full_name' => $name, 'email' => $email, 'phone' => $phone] as $field => $after) {
            $before = (string) ($target[$field] ?? '');
            if ($before !== $after) {
                $changes[] = $field . ': "' . $before . '" → "' . $after . '"';
            }
        }
        if ((int) ($target['parish_id'] ?? 0) !== (int) ($parishId ?? 0) || (string) ($target['parish_name_raw'] ?? '') !== (string) ($unlisted ?? '')) {
            $changes[] = 'parish updated';
        }

        Audit::log(
            (int) $actor['id'],
            'user.update',
            'user',
            (string) $userId,
            $changes === [] ? 'No change' : implode('; ', $changes)
        );

        return self::find($userId);
    }

    /**
     * Suspend or reinstate.
     *
     * Suspension also destroys the account's sessions through BruteForce::suspend — a suspended
     * member holding a live cookie is not suspended.
     */
    public static function setSuspended(int $userId, bool $suspended, array $actor): void
    {
        $target = self::find($userId);

        if ($userId === (int) $actor['id']) {
            throw new InvalidArgumentException('You cannot suspend your own account.');
        }
        if ($suspended && (string) $target['role'] === 'provincial_admin') {
            $active = (int) Database::value(
                "SELECT COUNT(*) FROM users WHERE role = 'provincial_admin' AND is_suspended = 0 AND id <> ?",
                [$userId]
            );
            if ($active === 0) {
                throw new InvalidArgumentException('That is the last active administrator. Create another one before suspending this account.');
            }
        }
        if ((int) $target['is_suspended'] === ($suspended ? 1 : 0)) {
            return;
        }

        BruteForce::suspend($userId, $suspended, (int) $actor['id']);
        Audit::log(
            (int) $actor['id'],
            $suspended ? 'user.suspend' : 'user.reinstate',
            'user',
            (string) $userId,
            (string) $target['full_name'] . ' <' . (string) $target['email'] . '>'
        );
    }

    /**
     * Delete an account that has no history.
     *
     * @param array<string, mixed> $actor
     */
    public static function delete(int $userId, array $actor): void
    {
        $target = self::find($userId);

        if ($userId === (int) $actor['id']) {
            throw new InvalidArgumentException('You cannot delete your own account.');
        }
        if ((string) $target['role'] === 'provincial_admin') {
            $active = (int) Database::value(
                "SELECT COUNT(*) FROM users WHERE role = 'provincial_admin' AND is_suspended = 0 AND id <> ?",
                [$userId]
            );
            if ($active === 0) {
                throw new InvalidArgumentException('That is the last active administrator, so it cannot be deleted.');
            }
        }

        // Anything that references the account and must not lose its counterparty.
        $applications = (int) Database::value('SELECT COUNT(*) FROM program_applications WHERE user_id = ?', [$userId]);
        if ($applications > 0) {
            throw new InvalidArgumentException(
                (string) $target['full_name'] . ' has ' . $applications . ' application(s) on file, so their record cannot be deleted —'
                . ' that would erase the applications, and any money paid against them. Suspend the account instead:'
                . ' it blocks sign-in and keeps the ledger honest.'
            );
        }
        $requested = (int) Database::value('SELECT COUNT(*) FROM remittances WHERE requested_by = ?', [$userId]);
        if ($requested > 0) {
            throw new InvalidArgumentException(
                'That account requested ' . $requested . ' disbursement(s). Suspend it instead of deleting it, so the money trail keeps its author.'
            );
        }

        Database::transaction(function () use ($userId, $target, $actor): void {
            // Sessions and tokens go with the row (ON DELETE CASCADE); audit rows keep their
            // snapshotted actor_email and fall back to it once actor_id is nulled.
            Database::run('DELETE FROM users WHERE id = ?', [$userId]);
            Audit::log(
                (int) $actor['id'],
                'user.delete',
                'user',
                (string) $userId,
                'Deleted ' . (string) $target['full_name'] . ' <' . (string) $target['email'] . '> (' . (string) $target['role'] . ')'
            );
        });
    }

    /**
     * Sign in as somebody else, for support.
     *
     * This is impersonation, so it is treated as such: the audit row names both parties, the
     * session records who the real administrator is, and the interface shows a permanent banner
     * with a way back. An admin cannot impersonate another admin — that would let one administrator
     * act with another's identity in the trail, which defeats the point of the trail.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed> the account switched to
     */
    public static function impersonate(int $userId, array $actor): array
    {
        $target = self::find($userId);

        if ($userId === (int) $actor['id']) {
            throw new InvalidArgumentException('You are already signed in as yourself.');
        }
        if ((int) $target['is_suspended'] === 1) {
            throw new InvalidArgumentException('That account is suspended. Reinstate it before signing in as them.');
        }
        if (Auth::isAdmin($target)) {
            throw new InvalidArgumentException('Administrator accounts cannot be impersonated — sign in with that account directly.');
        }

        // Remember who is really here before the session is replaced.
        $returnTo = [
            'id'    => (int) $actor['id'],
            'name'  => (string) $actor['full_name'],
            'email' => (string) $actor['email'],
            'at'    => gmdate('c'),
        ];

        Audit::log(
            (int) $actor['id'],
            'user.impersonate.start',
            'user',
            (string) $userId,
            (string) $actor['email'] . ' signed in as ' . (string) $target['email']
        );

        Auth::loginAs($target);
        $_SESSION['rise63_impersonator'] = $returnTo;

        Audit::log(
            (int) $userId,
            'user.impersonated',
            'user',
            (string) $actor['id'],
            'Session started by administrator ' . (string) $actor['email']
        );

        return $target;
    }

    /**
     * Stop impersonating and return to the administrator's own session.
     *
     * @return array<string, mixed> the administrator restored
     */
    public static function stopImpersonating(): array
    {
        $returnTo = $_SESSION['rise63_impersonator'] ?? null;
        if (!is_array($returnTo) || !isset($returnTo['id'])) {
            throw new RuntimeException('No impersonation session is active.');
        }
        $admin = Database::one('SELECT * FROM users WHERE id = ?', [(int) $returnTo['id']]);
        if ($admin === null || (int) $admin['is_suspended'] === 1 || !Auth::isAdmin($admin)) {
            unset($_SESSION['rise63_impersonator']);
            throw new RuntimeException('The administrator account is no longer available. Sign in again.');
        }

        $currentId = isset($_SESSION['rise63_user_id']) ? (int) $_SESSION['rise63_user_id'] : null;
        Auth::loginAs($admin);
        unset($_SESSION['rise63_impersonator']);

        Audit::log((int) $admin['id'], 'user.impersonate.end', 'user', $currentId === null ? null : (string) $currentId, 'Returned to administrator session');
        return $admin;
    }

    /** @return array<string, mixed> */
    private static function find(int $userId): array
    {
        $target = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($target === null) {
            throw new InvalidArgumentException('That account does not exist.');
        }
        return $target;
    }

    /**
     * Turn the submitted parish fields into (id, unlisted-name).
     *
     * @param array<string, mixed> $input
     * @return array{0: ?int, 1: ?string}
     */
    private static function resolveParish(array $input): array
    {
        $id = $input['parish_id'] ?? null;
        if ($id !== null && $id !== '' && $id !== '0') {
            $parishId = (int) $id;
            if (ParishDirectory::parish($parishId) !== null) {
                return [$parishId, null];
            }
        }
        $raw = \RISE63\caps((string) ($input['parish_name_raw'] ?? ''));
        return [null, $raw === '' ? null : mb_substr($raw, 0, 150)];
    }

    /**
     * A readable temporary password.
     *
     * Generated rather than left blank so "create" always produces a working account; the alphabet
     * omits characters that are mistaken for each other when read aloud on the telephone
     * (0/O, 1/l/I) because that is how these get handed over.
     */
    public static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 14; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code . '!' . random_int(10, 99);
    }

    /**
     * The directory of people, filtered and paged.
     *
     * @param array<string, mixed> $get
     * @return array{rows: list<array<string, mixed>>, page: Paginator, term: string, role: string, status: string, counts: array<string,int>}
     */
    public static function directory(array $get): array
    {
        $term   = trim((string) ($get['q'] ?? ''));
        $role   = (string) ($get['role'] ?? '');
        $status = (string) ($get['status'] ?? '');

        $where  = [];
        $params = [];
        if ($term !== '') {
            // One placeholder per occurrence — see the same note in Audit::page(). Native prepares
            // cannot bind one marker to three positions, and reusing it fails the whole request.
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], \RISE63\caps($term)) . '%';
            $where[] = '(UPPER(u.full_name) LIKE :t1 OR UPPER(u.email) LIKE :t2 OR UPPER(COALESCE(p.parish_name, \'\')) LIKE :t3)';
            $params['t1'] = $like;
            $params['t2'] = $like;
            $params['t3'] = $like;
        }
        if (isset(Auth::ROLES[$role])) {
            $where[] = 'u.role = :role';
            $params['role'] = $role;
        }
        if ($status === 'active') {
            $where[] = 'u.is_suspended = 0';
        } elseif ($status === 'suspended') {
            $where[] = 'u.is_suspended = 1';
        } elseif ($status === 'temporary') {
            $where[] = 'u.must_change_password = 1';
        }

        // Joined query, so the keyset page is written out rather than pushed through a generic
        // helper that assumes one table — filtering on a joined column through a single-table
        // helper is how a filter silently stops applying.
        $perPage = (int) ($get['per_page'] ?? 20);
        $perPage = max(10, min(100, $perPage > 0 ? $perPage : 20));
        $before  = null;
        if (isset($get['before']) && preg_match('/^\d{1,18}$/', (string) $get['before']) === 1) {
            $before = (int) $get['before'];
        }

        $conditions = $where;
        if ($before !== null) {
            $conditions[] = 'u.id < :cursor';
            $params['cursor'] = $before;
        }

        $sql = 'SELECT u.*, p.parish_name, a.area_name, z.zone_name
                FROM users u
                LEFT JOIN parishes p ON p.id = u.parish_id
                LEFT JOIN areas a ON a.id = p.area_id
                LEFT JOIN zones z ON z.id = a.zone_id';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY u.id DESC LIMIT ' . ($perPage + 1);

        $rows    = Database::all($sql, $params);
        $hasNext = count($rows) > $perPage;
        if ($hasNext) {
            array_pop($rows);
        }

        $carry = array_filter(
            ['q' => $term, 'role' => $role, 'status' => $status, 'per_page' => (string) $perPage],
            static fn (string $v): bool => $v !== ''
        );

        return [
            'rows'  => $rows,
            'term'  => $term,
            'role'  => $role,
            'status' => $status,
            'counts' => self::counts(),
            'page'  => Paginator::fromState($perPage, $before, count($rows), $carry, 'people', $hasNext),
        ];
    }

    /**
     * Headline numbers, read from one query so the filters and the totals cannot disagree.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        // SUM(CASE WHEN … THEN 1 ELSE 0 END) rather than SUM(x = 1): the latter is a MySQL
        // extension that sums a boolean, and it makes the query untestable anywhere else. This
        // form is identical on MySQL 8 and MariaDB and is what the end-to-end suite runs.
        $row = Database::one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN is_suspended = 1 THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN must_change_password = 1 THEN 1 ELSE 0 END) AS temporary,
                    SUM(CASE WHEN role = 'provincial_admin' THEN 1 ELSE 0 END) AS admins,
                    SUM(CASE WHEN role = 'provincial_treasurer' THEN 1 ELSE 0 END) AS treasurers,
                    SUM(CASE WHEN role = 'csr_rep' THEN 1 ELSE 0 END) AS reps,
                    SUM(CASE WHEN role = 'youth_member' THEN 1 ELSE 0 END) AS members
             FROM users"
        ) ?? [];
        $counts = [];
        foreach ($row as $key => $value) {
            $counts[(string) $key] = (int) $value;
        }
        return $counts;
    }
}
