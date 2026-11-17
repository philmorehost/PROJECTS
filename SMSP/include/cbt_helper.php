<?php
/**
 * SchoolApp (SMSP) — CBT Exam Visibility Helper
 *
 * Centralises the rules that decide whether a student can see / start a scheduled
 * CBT exam. Used by both the web portal (views/evaluation.php) and the mobile API
 * (api/v1/exams.php) so the two can never drift apart again.
 *
 * The helper fixes three long-standing classroom-blocking problems:
 *   1. Student records were resolved without a school scope, so on a multi-tenant
 *      database (the same admission number / email repeated in another school)
 *      the wrong student row could be loaded, pointing at the wrong class.
 *   2. Class matching was an exact string compare, so "JSS 1", "JSS-1" and "JSS1"
 *      (mix of imported data and generated class codes) never matched.
 *   3. Exams awaiting approval silently disappeared for students with no feedback.
 */

class CbtHelper
{
    /** Normalise a class identifier for tolerant comparison ("JSS 1" == "jss-1" == "JSS1"). */
    public static function normalizeClass($value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$value);
        return strtoupper((string)$clean);
    }

    /**
     * Resolve the logged-in student row, strictly scoped to the current school.
     * Mirrors login.php (email first, then admission number) but never leaks a
     * student that belongs to another tenant.
     */
    public static function resolveStudent(PDO $pdo, ?string $schoolId, ?string $sessionUser): ?array
    {
        $schoolId = trim((string)$schoolId);
        $sessionUser = trim((string)$sessionUser);
        if ($schoolId === '' || $sessionUser === '') {
            return null;
        }

        $attempts = [
            ["SELECT * FROM sm_students WHERE school_id_number = ? AND email = ? LIMIT 1", [$schoolId, $sessionUser]],
            ["SELECT * FROM sm_students WHERE school_id_number = ? AND admission_number = ? LIMIT 1", [$schoolId, $sessionUser]],
            ["SELECT * FROM sm_students WHERE school_id_number = ? AND (LOWER(email) = LOWER(?) OR LOWER(admission_number) = LOWER(?)) LIMIT 1", [$schoolId, $sessionUser, $sessionUser]],
        ];

        foreach ($attempts as [$sql, $params]) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            } catch (Throwable $e) {
                // Fall through to the next resolution attempt.
            }
        }

        return null;
    }

    /** Resolve the same student by an explicit admission number (parents / admins). */
    public static function resolveStudentByAdmission(PDO $pdo, ?string $schoolId, ?string $admission): ?array
    {
        $schoolId = trim((string)$schoolId);
        $admission = trim((string)$admission);
        if ($schoolId === '' || $admission === '') {
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM sm_students WHERE school_id_number = ? AND admission_number = ? LIMIT 1");
            $stmt->execute([$schoolId, $admission]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            // ignore
        }

        return null;
    }

    /**
     * Build the set of accepted class identifiers for a student's current class.
     * Includes the raw value, the generated class code and the human class label
     * whenever they differ ("JSS 1" <-> "JSS-1").
     */
    public static function classAliases(PDO $pdo, ?string $schoolId, ?string $classCode): array
    {
        $raw = trim((string)$classCode);
        if ($raw === '') {
            return [];
        }

        $schoolId = trim((string)$schoolId);
        $cacheKey = $schoolId . '|' . self::normalizeClass($raw);
        if (isset(self::$aliasCache[$cacheKey])) {
            return self::$aliasCache[$cacheKey];
        }

        $aliases = [];
        $add = static function ($value) use (&$aliases) {
            $normalized = self::normalizeClass($value);
            if ($normalized !== '') {
                $aliases[$normalized] = true;
            }
        };
        $add($raw);

        try {
            $stmt = $pdo->prepare("SELECT class_name, numeric_class_name FROM sm_classes WHERE school_id_number = ?");
            $stmt->execute([$schoolId]);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $classes = [];
        }

        // Pass 1 — find every label/code that describes this student's class
        $related = [self::normalizeClass($raw) => true];
        foreach ($classes as $cls) {
            $normCode = self::normalizeClass($cls['numeric_class_name'] ?? '');
            $normName = self::normalizeClass($cls['class_name'] ?? '');
            if (isset($related[$normCode]) || isset($related[$normName]) || $normCode === self::normalizeClass($raw) || $normName === self::normalizeClass($raw)) {
                if ($normCode !== '') $related[$normCode] = true;
                if ($normName !== '') $related[$normName] = true;
            }
        }

        // Pass 2 — pull in every code that shares one of those labels.
        // This covers classes re-created for a new session, where the same label
        // ("JSS 1") gets a suffixed code ("JSS-1-1") while students still carry
        // the earlier code ("JSS-1").
        foreach ($classes as $cls) {
            $normCode = self::normalizeClass($cls['numeric_class_name'] ?? '');
            $normName = self::normalizeClass($cls['class_name'] ?? '');
            if ((isset($related[$normCode]) && $normCode !== '') || (isset($related[$normName]) && $normName !== '')) {
                if ($normCode !== '') $related[$normCode] = true;
                if ($normName !== '') $related[$normName] = true;
            }
        }

        foreach (array_keys($related) as $alias) {
            $add($alias);
        }

        self::$aliasCache[$cacheKey] = array_keys($aliases);
        return self::$aliasCache[$cacheKey];
    }

    /** Does an exam's stored class match one of the student's class aliases? */
    public static function classMatches($examClass, array $aliasSet): bool
    {
        $normalized = self::normalizeClass($examClass);
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, $aliasSet, true);
    }

    /**
     * Compute the opening window for a scheduled exam.
     * Returns a state of upcoming|active|expired plus display labels.
     */
    public static function state(array $exam, ?int $now = null): array
    {
        $now = $now ?? time();
        $duration = (int)($exam['exam_duration'] ?? 0);
        if ($duration <= 0) {
            $duration = 60;
        }

        $datePart = trim((string)($exam['exam_date'] ?? ''));
        $timePart = trim((string)($exam['exam_time'] ?? ''));
        $startsAt = strtotime(trim($datePart . ' ' . $timePart));

        // Malformed date/time: never hide an exam because of bad data — keep it open.
        if ($startsAt === false) {
            return [
                'state'            => 'active',
                'can_start'        => true,
                'starts_at'        => null,
                'ends_at'          => null,
                'starts_at_label'  => $datePart . ' ' . $timePart,
                'ends_at_label'    => '',
                'seconds_to_start' => 0,
                'seconds_left'     => 0,
                'now'              => $now,
            ];
        }

        $endsAt = $startsAt + ($duration * 60);
        if ($now < $startsAt) {
            $state = 'upcoming';
        } elseif ($now > $endsAt) {
            $state = 'expired';
        } else {
            $state = 'active';
        }

        return [
            'state'            => $state,
            'can_start'        => $state === 'active',
            'starts_at'        => $startsAt,
            'ends_at'          => $endsAt,
            'starts_at_label'  => date('D, d M Y · h:i A', $startsAt),
            'ends_at_label'    => date('D, d M Y · h:i A', $endsAt),
            'seconds_to_start' => max(0, $startsAt - $now),
            'seconds_left'     => max(0, $endsAt - $now),
            'now'              => $now,
        ];
    }

    /**
     * Every approved exam a student is allowed to see (class alias match, newest first).
     */
    public static function visibleExams(PDO $pdo, ?string $schoolId, ?array $student): array
    {
        if (!$student) {
            return [];
        }

        $aliases = self::classAliases($pdo, $schoolId, $student['current_class'] ?? '');
        if (empty($aliases)) {
            return [];
        }

        $rows = self::approvedExamsForSchool($pdo, $schoolId);
        $visible = [];
        foreach ($rows as $row) {
            if (self::classMatches($row['numeric_class_name'] ?? '', $aliases)) {
                $visible[] = $row;
            }
        }
        return $visible;
    }

    /**
     * Exams still waiting for approval that a student would otherwise be able to take.
     * Used to explain an empty list ("your exam is awaiting school approval").
     */
    public static function pendingExamsForStudent(PDO $pdo, ?string $schoolId, ?array $student): array
    {
        if (!$student) {
            return [];
        }

        $aliases = self::classAliases($pdo, $schoolId, $student['current_class'] ?? '');
        if (empty($aliases)) {
            return [];
        }

        if (!self::hasApprovalColumn($pdo)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ? AND is_approved = 0 ORDER BY cbt_id DESC");
            $stmt->execute([trim((string)$schoolId)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        $pending = [];
        foreach ($rows as $row) {
            if (self::classMatches($row['numeric_class_name'] ?? '', $aliases)) {
                $pending[] = $row;
            }
        }
        return $pending;
    }

    /** @var array<string, array<int, array>> Per-request approved-exam cache, keyed by school. */
    private static array $approvedCache = [];

    /** @var array<string, array<int, string>> Class-alias cache keyed by school|class. */
    private static array $aliasCache = [];

    /** @var bool|null Whether sm_cbt_schedules.is_approved exists on this installation. */
    private static ?bool $hasApprovalColumn = null;

    /**
     * Older installations may not have the `is_approved` column yet (the ALTER in
     * db.php can fail on restricted MySQL users). Probing once keeps the exam list
     * working instead of throwing "unknown column" and hiding every exam.
     */
    public static function hasApprovalColumn(PDO $pdo): bool
    {
        if (self::$hasApprovalColumn !== null) {
            return self::$hasApprovalColumn;
        }
        try {
            $pdo->query("SELECT is_approved FROM sm_cbt_schedules LIMIT 0");
            self::$hasApprovalColumn = true;
        } catch (Throwable $e) {
            self::$hasApprovalColumn = false;
        }
        return self::$hasApprovalColumn;
    }

    /** All approved exams for a school (cached per request). */
    private static function approvedExamsForSchool(PDO $pdo, ?string $schoolId): array
    {
        $schoolId = trim((string)$schoolId);
        if ($schoolId === '') {
            return [];
        }
        if (isset(self::$approvedCache[$schoolId])) {
            return self::$approvedCache[$schoolId];
        }

        $approvalFilter = self::hasApprovalColumn($pdo) ? " AND is_approved = 1" : "";

        try {
            $stmt = $pdo->prepare("SELECT * FROM sm_cbt_schedules WHERE school_id_number = ?{$approvalFilter} ORDER BY exam_date DESC, exam_time DESC, cbt_id DESC");
            $stmt->execute([$schoolId]);
            self::$approvedCache[$schoolId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$approvedCache[$schoolId] = [];
        }

        return self::$approvedCache[$schoolId];
    }

    /** Clear the per-request cache after an exam is approved / created / deleted. */
    public static function flushCache(): void
    {
        self::$approvedCache = [];
        self::$aliasCache = [];
    }

    /**
     * How many students sit in the class an exam was scheduled for.
     * Lets admins spot exams aimed at a class code nobody is assigned to.
     */
    public static function classStudentCount(PDO $pdo, ?string $schoolId, ?string $classCode, array &$cache = []): int
    {
        $normalized = self::normalizeClass($classCode);
        if ($normalized === '') {
            return 0;
        }
        if (isset($cache[$normalized])) {
            return $cache[$normalized];
        }

        $count = 0;
        try {
            $aliases = self::classAliases($pdo, $schoolId, $classCode);
            $stmt = $pdo->prepare("SELECT current_class FROM sm_students WHERE school_id_number = ?");
            $stmt->execute([trim((string)$schoolId)]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $studentClass) {
                if (self::classMatches($studentClass, $aliases)) {
                    $count++;
                }
            }
        } catch (Throwable $e) {
            $count = 0;
        }

        $cache[$normalized] = $count;
        return $count;
    }

    /**
     * Number of questions actually published inside `exam_json`.
     * A scheduled exam can exist with `exam_questions = 40` while the teacher has
     * not added a single question yet — starting it would show a blank paper.
     */
    public static function publishedQuestionCount(array $exam): int
    {
        $raw = (string)($exam['exam_json'] ?? '');
        if (trim($raw) === '') {
            return 0;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return 0;
        }

        foreach (['quiz', 'questions'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return count($decoded[$key]);
            }
        }

        return 0;
    }

    /**
     * Should a newly scheduled exam go live immediately, or wait for a School
     * Admin? Defaults to "live immediately" — a school that schedules an exam
     * expects its students to see it, and moderation can be switched on per
     * school when the exam officer is not the school admin.
     */
    public static function requireApproval(PDO $pdo, ?string $schoolId = null): bool
    {
        $key = self::approvalSettingKey($schoolId);
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null || $value === '') {
                return false;
            }
            return (string)$value === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Persist the school's approval policy (School Admin only). */
    public static function setRequireApproval(PDO $pdo, ?string $schoolId, bool $enabled): bool
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([self::approvalSettingKey($schoolId), $enabled ? '1' : '0']);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Per-tenant setting key, so one school's policy never leaks into another. */
    public static function approvalSettingKey(?string $schoolId): string
    {
        $schoolId = trim((string)$schoolId);
        return 'cbt_require_approval_' . ($schoolId !== '' ? $schoolId : 'global');
    }

    /**
     * The `is_approved` value a new exam should get for the creating role.
     * School admins always publish immediately; teachers only need approval
     * when the school has switched moderation on.
     */
    public static function approvalForRole(PDO $pdo, ?string $schoolId, ?string $role): int
    {
        if ($role === 'teacher' && self::requireApproval($pdo, $schoolId)) {
            return 0;
        }
        return 1;
    }

    /**
     * Full "can students see this exam?" diagnostic for admins and teachers.
     * Returns one entry per gate in the visibility chain so a support call can
     * be answered without opening the database.
     */
    public static function visibilityReport(PDO $pdo, ?string $schoolId, array $exam, ?array $student = null): array
    {
        $window    = self::state($exam);
        $approved  = (int)($exam['is_approved'] ?? 1) === 1;
        $published = self::publishedQuestionCount($exam);
        $classCode = (string)($exam['numeric_class_name'] ?? '');
        $classSize = self::classStudentCount($pdo, $schoolId, $classCode);

        $checks = [];

        $checks[] = [
            'ok'     => $approved,
            'label'  => 'Approved by School Admin',
            'detail' => $approved
                ? 'Approved — the exam is published to students.'
                : 'Pending approval — approve it from the CBT panel to release it to students.',
        ];

        $checks[] = [
            'ok'     => $classSize > 0,
            'label'  => 'Class has students',
            'detail' => $classSize > 0
                ? $classSize . ' student(s) are assigned to class code ' . $classCode . '.'
                : 'Nobody is assigned to class code ' . ($classCode !== '' ? $classCode : '(empty)') . '. Re-check the students\' Current Class in the Registry.',
        ];

        $checks[] = [
            'ok'     => $published > 0,
            'label'  => 'Questions published',
            'detail' => $published > 0
                ? $published . ' question(s) found in the paper.'
                : 'The paper has 0 questions. Open “Questions” on this row and add them — students cannot start an empty paper.',
        ];

        $checks[] = [
            'ok'     => $window['state'] !== 'upcoming',
            'label'  => 'Exam window opened',
            'detail' => $window['state'] === 'upcoming'
                ? 'Opens ' . $window['starts_at_label'] . '.'
                : 'Opened ' . ($window['starts_at_label'] ?: '—') . '.',
        ];

        $checks[] = [
            'ok'     => $window['state'] !== 'expired',
            'label'  => 'Exam window still open',
            'detail' => $window['state'] === 'expired'
                ? 'Closed ' . $window['ends_at_label'] . '. Reschedule the paper to re-open it.'
                : 'Closes ' . ($window['ends_at_label'] ?: '—') . '.',
        ];

        if ($student) {
            $aliases = self::classAliases($pdo, $schoolId, $student['current_class'] ?? '');
            $matches = self::classMatches($classCode, $aliases);
            $checks[] = [
                'ok'     => $matches,
                'label'  => 'Student class match',
                'detail' => $matches
                    ? 'Student class ' . ($student['current_class'] ?? '—') . ' matches this exam.'
                    : 'Student class ' . ($student['current_class'] ?? '(not set)') . ' does not match ' . $classCode . '.',
            ];
        }

        $verdict = 'Visible to students';
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $verdict = $check['detail'];
                break;
            }
        }

        return [
            'checks'   => $checks,
            'verdict'  => $verdict,
            'window'   => $window,
            'approved' => $approved,
            'published'=> $published,
            'class_size' => $classSize,
        ];
    }

    /** Human friendly explanation of why an exam is not startable right now. */
    public static function visibilityReason(array $exam, array $state): string
    {
        if (($state['state'] ?? '') === 'upcoming') {
            return 'Opens ' . ($state['starts_at_label'] ?? 'soon');
        }
        if (($state['state'] ?? '') === 'expired') {
            return 'Closed ' . ($state['ends_at_label'] ?? '');
        }
        return 'Open now';
    }
}
