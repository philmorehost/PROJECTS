<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;

/**
 * Self-service registration and administrator provisioning.
 *
 * Roles are never taken from submitted data: registration always creates a youth_member,
 * and an administrator can promote the account afterwards. This closes the obvious
 * privilege-escalation path in a public sign-up form.
 */
final class Registration
{
    /**
     * @param array{full_name:string, email:string, phone:string, parish_id:?string,
     *              parish_name_raw?:string, password:string, confirm:string} $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        $name  = trim($input['full_name']);
        $email = strtolower(trim($input['email']));
        $phone = trim($input['phone']);
        $pass  = $input['password'];

        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Enter your full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            throw new InvalidArgumentException('Enter a valid e-mail address.');
        }
        if ($phone !== '' && preg_match('/^\+?[0-9 ()-]{7,25}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('Enter a valid phone number, or leave it blank.');
        }
        $problem = Security::passwordProblem($pass, $email, $name);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (!hash_equals($pass, $input['confirm'])) {
            throw new InvalidArgumentException('The two passwords do not match.');
        }
        if (Database::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            // Deliberately explicit here: the account holder is the one who just chose the
            // address, so a vague message would only send them in circles.
            throw new InvalidArgumentException('An account with that e-mail address already exists. Try signing in instead.');
        }

        $parishId = self::parishId($input['parish_id']);

        // Picking from the list is the normal path. A church that is not listed yet is typed by
        // hand, in capitals, and kept on the account as "not yet in the directory" so a
        // representative can add it — the alternative was making the member pick the wrong parish
        // or give up on registering.
        $unlisted = null;
        if ($parishId === null) {
            $unlisted = self::unlistedParish($input['parish_name_raw'] ?? null);
        }

        Database::run(
            'INSERT INTO users (full_name, email, phone, parish_id, parish_name_raw, password_hash, role)
             VALUES (?,?,?,?,?,?,?)',
            [$name, $email, $phone === '' ? null : $phone, $parishId, $unlisted, Security::hashPassword($pass), 'youth_member']
        );
        $id = Database::id();
        Audit::log(
            $id,
            'user.register',
            'user',
            (string) $id,
            $unlisted === null
                ? 'Self-service registration.'
                : 'Self-service registration; church not yet in the directory: ' . $unlisted . '.'
        );

        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]) ?? [];
        if ($user !== []) {
            Notifier::welcome($user);
        }
        return $user;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function createByAdmin(array $input, array $actor): array
    {
        $name  = trim((string) $input['full_name']);
        $email = strtolower(trim((string) $input['email']));
        $role  = (string) ($input['role'] ?? 'youth_member');
        $pass  = (string) ($input['password'] ?? '');

        if (!isset(Auth::ROLES[$role])) {
            throw new InvalidArgumentException('Choose a valid role.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid e-mail address.');
        }
        $problem = Security::passwordProblem($pass, $email, $name);
        if ($problem !== null) {
            throw new InvalidArgumentException('Choose a stronger temporary password. ' . $problem);
        }
        if (Database::value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            throw new InvalidArgumentException('That e-mail address is already registered.');
        }

        Database::run(
            'INSERT INTO users (full_name, email, phone, parish_id, password_hash, role) VALUES (?,?,?,?,?,?)',
            [
                $name,
                $email,
                null,
                self::parishId($input['parish_id'] ?? null),
                Security::hashPassword($pass),
                $role,
            ]
        );
        $id   = Database::id();
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]) ?? [];
        Audit::log((int) $actor['id'], 'user.create', 'user', (string) $id, $email . ' (' . $role . ')');
        if ($user !== []) {
            Notifier::welcome($user);
        }
        return $user;
    }

    private static function parishId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        $id = (int) $value;
        return ParishDirectory::parish($id) === null ? null : $id;
    }

    /**
     * A church name typed by a member because the picker did not have it.
     *
     * Stored upper-case, like everything else in the directory, and matched against the directory
     * first: if the church was added by an admin between the member opening the form and submitting
     * it, the typed name should attach to that parish rather than become a second "unlisted" copy.
     */
    private static function unlistedParish(mixed $value): ?string
    {
        $name = \RISE63\caps((string) ($value ?? ''));
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Enter your church name in 150 characters or fewer.');
        }
        if (preg_match('/[\x00-\x1F<>]/', $name) === 1) {
            throw new InvalidArgumentException('That church name contains characters that are not allowed.');
        }

        $match = ParishDirectory::findByName($name);
        if ($match !== null) {
            // Belt and braces: the caller only reaches here when no id was chosen, but the
            // directory may have gained the church while the form was open.
            return null;
        }

        return $name;
    }
}
