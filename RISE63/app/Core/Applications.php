<?php
declare(strict_types=1);

namespace RISE63\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Program application intake, validation and the single legal path for status changes.
 */
final class Applications
{
    /** Program types that allow more than one live application per year. */
    private const REPEATABLE = ['cooperative'];

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{id:int, created:bool}
     */
    public static function submit(array $user, string $type, array $input): array
    {
        if (!Program::exists($type)) {
            throw new InvalidArgumentException('Choose a program to apply for.');
        }

        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key !== '' && preg_match('/^[a-f0-9]{32,64}$/D', $key) !== 1) {
            throw new InvalidArgumentException('The submission key is not valid.');
        }

        // Replay protection: an offline queue retry or a double tap must not duplicate the row.
        if ($key !== '') {
            $existing = Database::value(
                'SELECT id FROM program_applications WHERE user_id = ? AND idempotency_key = ?',
                [(int) $user['id'], $key]
            );
            if ($existing !== null) {
                return ['id' => (int) $existing, 'created' => false];
            }
        }

        $cycle  = Settings::int('current_cycle_year', (int) gmdate('Y'));
        $level  = (string) ($input['education_level'] ?? 'none');
        if (!in_array($level, Program::educationLevels($type), true)) {
            throw new InvalidArgumentException('That education level does not apply to this program.');
        }

        $school = trim((string) ($input['school_name'] ?? ''));
        $class  = trim((string) ($input['school_class'] ?? ''));
        if ($type === 'education_bridge') {
            if ($school === '') {
                throw new InvalidArgumentException('Enter the name of your school or institution.');
            }
            if ($level === 'super_teen' && $class === '') {
                throw new InvalidArgumentException('Select the class of the Super Teen (SS1, SS2 or SS3).');
            }
        }
        if (mb_strlen($school) > 200 || mb_strlen($class) > 50) {
            throw new InvalidArgumentException('One of the school fields is too long.');
        }

        $amount = Money::fromNaira($input['requested_amount'] ?? '0');
        if ($amount->isZero()) {
            throw new InvalidArgumentException('Enter the amount you are requesting.');
        }
        if ($type === 'seed_grant' && $amount->compare(Money::fromDecimal((string) Settings::get('seed_grant_max_per_application', '0'))) === 1) {
            throw new InvalidArgumentException('Seed grant requests are capped at ' . Money::fromDecimal((string) Settings::get('seed_grant_max_per_application'))->format() . '.');
        }
        if ($type === 'academy' && $amount->compare(Money::zero()) === 1) {
            // The Academy is free; a monetary request on it would only confuse reporting.
            throw new InvalidArgumentException('RISE Academy does not require a monetary request. Enter 0 or apply for a Seed Grant after graduation.');
        }

        $answers = self::validateAnswers($type, $input);

        if (!in_array($type, self::REPEATABLE, true)) {
            $open = (int) Database::value(
                "SELECT COUNT(*) FROM program_applications WHERE user_id = ? AND program_type = ? AND cycle_year = ? "
                . "AND status NOT IN ('rejected','withdrawn','completed')",
                [(int) $user['id'], $type, $cycle]
            );
            if ($open > 0) {
                throw new DuplicateApplication(Program::label($type));
            }
        }

        return Database::transaction(function () use ($user, $type, $cycle, $level, $school, $class, $amount, $answers, $key, $input): array {
            Database::run(
                'INSERT INTO program_applications
                 (user_id, parish_id, program_type, cycle_year, education_level, school_name, school_class,
                  requested_amount, answers_json, status, idempotency_key, submitted_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
                [
                    (int) $user['id'],
                    $user['parish_id'] === null ? null : (int) $user['parish_id'],
                    $type,
                    $cycle,
                    $level,
                    $school === '' ? null : $school,
                    $class === '' ? null : $class,
                    $amount->toDecimal(),
                    json_encode($answers, JSON_THROW_ON_ERROR),
                    'submitted',
                    $key === '' ? null : $key,
                ]
            );
            $id = Database::id();
            self::timeline($id, (int) $user['id'], null, 'submitted', 'Application submitted by the member.');
            Audit::log((int) $user['id'], 'application.submit', 'application', (string) $id, Program::label($type));

            $application = Database::one('SELECT * FROM program_applications WHERE id = ?', [$id]);
            if ($application !== null) {
                Notifier::applicationStatus($application, 'submitted');
            }
            if ($key !== '' && $input['draft_reset'] ?? false) {
                // Nothing to clean up server-side: drafts live in the browser only.
            }
            return ['id' => $id, 'created' => true];
        });
    }

    /**
     * The only method allowed to change program_applications.status.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed> the refreshed application row
     */
    public static function transition(int $applicationId, array $actor, string $to, string $note = ''): array
    {
        return Database::transaction(function () use ($applicationId, $actor, $to, $note): array {
            $application = Database::one('SELECT * FROM program_applications WHERE id = ? FOR UPDATE', [$applicationId]);
            if ($application === null) {
                throw new NotFound('That application no longer exists.');
            }

            $isOwner    = (int) $application['user_id'] === (int) $actor['id'];
            $isReviewer = Auth::isReviewer($actor);

            if ($isOwner) {
                $memberMoves = ['submitted', 'withdrawn'];
                if (!in_array($to, $memberMoves, true)) {
                    throw new InvalidArgumentException('Members can submit or withdraw an application, nothing else.');
                }
            } elseif (!$isReviewer) {
                throw new InvalidArgumentException('You do not have permission to review applications.');
            } elseif (!Program::reviewerCanSet($to)) {
                throw new InvalidArgumentException('That status is set by the system, not by a reviewer.');
            }

            // Approval and disbursement are split: only the treasurer confirms money movement.
            if ($to === 'disbursed' && !Auth::isTreasurer($actor)) {
                throw new InvalidArgumentException('Only the provincial treasurer can record a disbursement.');
            }

            $from = (string) $application['status'];
            Program::assertTransition($from, $to, $note);

            $fields = ['status = ?'];
            $params = [$to];
            if ($to === 'verified') {
                $fields[] = 'verified_at = UTC_TIMESTAMP()';
            }
            if ($to === 'approved') {
                $fields[] = 'approved_at = UTC_TIMESTAMP()';
            }
            if (in_array($to, Program::REASON_REQUIRED, true)) {
                $fields[] = 'decision_note = ?';
                $params[] = mb_substr($note, 0, 500);
            }
            $params[] = $applicationId;
            Database::run('UPDATE program_applications SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);

            self::timeline($applicationId, (int) $actor['id'], $from, $to, $note === '' ? null : mb_substr($note, 0, 500));
            Audit::log((int) $actor['id'], 'application.transition', 'application', (string) $applicationId, $from . ' → ' . $to);

            $fresh = Database::one('SELECT * FROM program_applications WHERE id = ?', [$applicationId]);
            if ($fresh !== null) {
                Notifier::applicationStatus($fresh, $to, $note);
            }
            return $fresh ?? $application;
        });
    }

    public static function timeline(int $applicationId, ?int $actorId, ?string $from, string $to, ?string $note): void
    {
        Database::run(
            'INSERT INTO application_events (application_id, actor_id, from_status, to_status, note) VALUES (?,?,?,?,?)',
            [$applicationId, $actorId, $from, $to, $note]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function events(int $applicationId): array
    {
        return Database::all(
            'SELECT e.*, u.full_name AS actor_name FROM application_events e
             LEFT JOIN users u ON u.id = e.actor_id
             WHERE e.application_id = ? ORDER BY e.id ASC',
            [$applicationId]
        );
    }

    /**
     * Applications visible to a user: a member sees their own, a CSR rep sees their parish,
     * province roles see everything.
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public static function visibleTo(array $user, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        if (Auth::isTreasurer($user) || Auth::isAdmin($user)) {
            return Database::all(
                "SELECT a.*, u.full_name, u.email, p.parish_name, ar.area_name, z.zone_name
                 FROM program_applications a
                 JOIN users u ON u.id = a.user_id
                 LEFT JOIN parishes p ON p.id = a.parish_id
                 LEFT JOIN areas ar ON ar.id = p.area_id
                 LEFT JOIN zones z ON z.id = ar.zone_id
                 ORDER BY a.updated_at DESC LIMIT {$limit}"
            );
        }
        if ((string) $user['role'] === 'csr_rep' && $user['parish_id'] !== null) {
            return Database::all(
                "SELECT a.*, u.full_name, u.email, p.parish_name, ar.area_name, z.zone_name
                 FROM program_applications a
                 JOIN users u ON u.id = a.user_id
                 JOIN parishes p ON p.id = a.parish_id
                 JOIN areas ar ON ar.id = p.area_id
                 JOIN zones z ON z.id = ar.zone_id
                 WHERE a.parish_id = ?
                 ORDER BY a.updated_at DESC LIMIT {$limit}",
                [(int) $user['parish_id']]
            );
        }
        return Database::all(
            'SELECT a.*, p.parish_name FROM program_applications a
             LEFT JOIN parishes p ON p.id = a.parish_id
             WHERE a.user_id = ? ORDER BY a.updated_at DESC LIMIT ' . $limit,
            [(int) $user['id']]
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function find(int $id, array $user): ?array
    {
        $application = Database::one(
            'SELECT a.*, u.full_name, u.email, p.parish_name FROM program_applications a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN parishes p ON p.id = a.parish_id
             WHERE a.id = ?',
            [$id]
        );
        if ($application === null) {
            return null;
        }
        $isOwner = (int) $application['user_id'] === (int) $user['id'];
        $sameParish = $user['parish_id'] !== null && (int) $application['parish_id'] === (int) $user['parish_id'];
        if ($isOwner || Auth::isTreasurer($user) || ((string) $user['role'] === 'csr_rep' && $sameParish)) {
            return $application;
        }
        return null;
    }

    /** @return array<string, string> answers decoded from JSON */
    public static function answers(array $application): array
    {
        $decoded = json_decode((string) ($application['answers_json'] ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $decoded);
    }

    public static function attachDocument(int $applicationId, array $document, int $actorId): void
    {
        $current = Database::value('SELECT documents_json FROM program_applications WHERE id = ?', [$applicationId]);
        $list    = json_decode((string) $current, true);
        $list    = is_array($list) ? $list : [];
        $document['uploaded_at'] = gmdate('c');
        $list[] = $document;
        Database::run('UPDATE program_applications SET documents_json = ? WHERE id = ?', [json_encode($list, JSON_THROW_ON_ERROR), $applicationId]);
        self::timeline($applicationId, $actorId, null, (string) Database::value('SELECT status FROM program_applications WHERE id = ?', [$applicationId]) ?? 'submitted', 'Document attached: ' . $document['name']);
    }

    /** @return list<array<string, mixed>> */
    public static function documents(array $application): array
    {
        $list = json_decode((string) ($application['documents_json'] ?? ''), true);
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** Aggregate counters for the dashboard, scoped by role. */
    public static function summaryFor(array $user): array
    {
        $rows = self::visibleTo($user, 500);
        $counts = ['total' => count($rows), 'pending' => 0, 'approved' => 0, 'disbursed' => 0, 'rejected' => 0, 'requested' => 0];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (in_array($status, ['submitted', 'under_review', 'documents_requested', 'verified'], true)) {
                $counts['pending']++;
            }
            if ($status === 'approved') {
                $counts['approved']++;
            }
            if (in_array($status, ['disbursed', 'completed'], true)) {
                $counts['disbursed']++;
            }
            if ($status === 'rejected') {
                $counts['rejected']++;
            }
            $counts['requested'] += Money::fromDecimal($row['requested_amount'])->cents();
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function validateAnswers(string $type, array $input): array
    {
        $answers = [];
        foreach (Program::questions($type) as $key => $definition) {
            $raw = trim((string) ($input['answers'][$key] ?? ''));
            if ($definition['type'] === 'yesno') {
                $answers[$key] = $raw === 'yes' ? 'Yes' : ($raw === 'no' ? 'No' : '');
            } elseif ($definition['type'] === 'money') {
                $answers[$key] = Money::fromNaira($raw === '' ? '0' : $raw)->toDecimal();
            } else {
                $answers[$key] = mb_substr($raw, 0, (int) ($definition['max'] ?? 500));
            }
            if (!empty($definition['required']) && $answers[$key] === '') {
                throw new InvalidArgumentException('Please complete: ' . $definition['label'] . '.');
            }
        }
        return $answers;
    }

    /** Programs a member may apply to right now, with a reason when they cannot. */
    public static function eligibility(array $user): array
    {
        $cycle  = Settings::int('current_cycle_year', (int) gmdate('Y'));
        $result = [];
        foreach (Program::TYPES as $type => $label) {
            $reason = null;
            if (!in_array($type, self::REPEATABLE, true)) {
                $existing = Database::one(
                    "SELECT id, status FROM program_applications WHERE user_id = ? AND program_type = ? AND cycle_year = ? "
                    . "AND status NOT IN ('rejected','withdrawn','completed') ORDER BY id DESC LIMIT 1",
                    [(int) $user['id'], $type, $cycle]
                );
                if ($existing !== null) {
                    $reason = 'You already have an application in progress.';
                }
            }
            if ($type === 'seed_grant') {
                $graduated = (int) Database::value(
                    "SELECT COUNT(*) FROM academy_progress pr JOIN program_applications a ON a.id = pr.application_id
                     WHERE a.user_id = ? AND pr.sessions_held > 0 AND (pr.sessions_attended * 100 / pr.sessions_held) >= ?",
                    [(int) $user['id'], Settings::int('academy_graduation_percent', 80)]
                );
                if ($graduated === 0) {
                    $reason = 'Seed Grants open to Academy graduates who attended at least ' . Settings::int('academy_graduation_percent', 80) . '% of sessions.';
                }
            }
            $result[$type] = ['label' => $label, 'available' => $reason === null, 'reason' => $reason];
        }
        return $result;
    }
}

final class DuplicateApplication extends RuntimeException
{
    public function __construct(string $program)
    {
        parent::__construct('You already have an open ' . $program . ' application this year.');
    }
}

final class NotFound extends RuntimeException
{
}
