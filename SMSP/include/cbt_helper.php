<?php
/**
 * SchoolApp (SMSP) — CBT Exam Visibility Helper
 *
 * Centralises the rules that decide whether a student can see / start a scheduled
 * CBT exam. Used by both the web portal (views/evaluation.php) and the mobile API
 * (api/v1/exams.php) so the two can never drift apart again.
 *
 * The helper fixes four long-standing classroom-blocking problems:
 *   1. Student records were resolved without a school scope, so on a multi-tenant
 *      database (the same admission number / email repeated in another school)
 *      the wrong student row could be loaded, pointing at the wrong class.
 *   2. Class matching was an exact string compare, so "JSS 1", "JSS-1" and "JSS1"
 *      (mix of imported data and generated class codes) never matched.
 *   3. Exams awaiting approval silently disappeared for students with no feedback.
 *   4. A class value that never existed in `sm_classes` (a bare number such as
 *      "1" left over from an older release, or a class that was deleted) matched
 *      nothing at all — and the visibility page blamed the students. Values are
 *      now resolved to a class identity first (see `resolveClass()`), and the
 *      admin is given a one-click repair instead of a dead end.
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

    /* ---------------------------------------------------------------------
     |  Class identity
     |
     |  A class is stored three different ways in this database:
     |      sm_classes.numeric_class_name  the generated code  ("CLASS-1")
     |      sm_classes.class_name          the human label     ("Class 1")
     |      sm_students.current_class      whatever was on the form / CSV at
     |                                     admission time — code, label, or a
     |                                     bare number left over from an older
     |                                     release ("1"), even if that exact
     |                                     value no longer exists in sm_classes.
     |
     |  Comparing those strings directly is why a scheduled exam can look
     |  "unassigned" to the school even though the class has 30 students.
     |  Everything below resolves a value to ONE class identity first, and only
     |  then compares. When a value could mean two different classes it is
     |  reported as ambiguous instead of guessing, because showing a Paper to
     |  the wrong class is worse than showing nothing.
     * ------------------------------------------------------------------- */

    /** @var array<string, array<int, array>> sm_classes rows, keyed by school. */
    private static array $classRows = [];

    /** @var array<string, string> Resolved class identity, keyed by school|value. */
    private static array $classKeyCache = [];

    /** Every class row for a school (cached per request). */
    public static function classesForSchool(PDO $pdo, ?string $schoolId): array
    {
        $schoolId = trim((string)$schoolId);
        if (isset(self::$classRows[$schoolId])) {
            return self::$classRows[$schoolId];
        }

        try {
            $stmt = $pdo->prepare("SELECT id, class_name, numeric_class_name, session FROM sm_classes WHERE school_id_number = ? ORDER BY class_name ASC");
            $stmt->execute([$schoolId]);
            self::$classRows[$schoolId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            self::$classRows[$schoolId] = [];
        }

        return self::$classRows[$schoolId];
    }

    /**
     * Split a normalised class value into its alpha prefix and its trailing number.
     * "CLASS1"  => ["CLASS", "1"]
     * "1"       => ["", "1"]
     * "JSS1"    => ["JSS", "1"]
     * "PRIMARY4"=> ["PRIMARY", "4"]
     * "SENIOR"  => ["SENIOR", ""]
     */
    public static function classTokens(string $normalized): array
    {
        if (preg_match('/^([A-Z]*)0*(\d+)$/', $normalized, $m)) {
            return [$m[1], $m[2]];
        }

        return [$normalized, ''];
    }

    /** "/1/" and "/CLASS 1/" describe the same class; "/JSS 1/" and "/PRIMARY 1/" do not. */
    private static function prefixCompatible(string $a, string $b): bool
    {
        if ($a === $b || $a === '' || $b === '') {
            return true;
        }

        // "JSS1" vs "JS1" style drift — one is a prefix of the other.
        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    /**
     * Resolve any stored class value to a single identity.
     *
     * @return array{
     *   input:string, normalized:string, key:string, kind:string,
     *   label:string, code:string, exists:bool, ambiguous:bool,
     *   candidates:array<int,array{code:string,label:string}>
     * }
     */
    public static function resolveClass(PDO $pdo, ?string $schoolId, $value): array
    {
        $raw = trim((string)$value);
        $normalized = self::normalizeClass($raw);
        $schoolId = trim((string)$schoolId);

        $empty = [
            'input' => $raw, 'normalized' => $normalized, 'key' => '', 'kind' => 'empty',
            'label' => '', 'code' => '', 'exists' => false, 'ambiguous' => false, 'candidates' => [],
        ];

        if ($normalized === '') {
            return $empty;
        }

        $cacheKey = $schoolId . '|' . $normalized;
        if (isset(self::$classKeyCache[$cacheKey])) {
            return self::describeClassKey($pdo, $schoolId, $raw, $normalized, self::$classKeyCache[$cacheKey]);
        }

        $classes = self::classesForSchool($pdo, $schoolId);

        // 1. Direct hit — the value is a class code or a class label.
        $direct = [];
        foreach ($classes as $cls) {
            $codeNorm = self::normalizeClass($cls['numeric_class_name'] ?? '');
            $labelNorm = self::normalizeClass($cls['class_name'] ?? '');
            if ($normalized === $codeNorm || $normalized === $labelNorm) {
                $direct[] = $cls;
            }
        }
        if (!empty($direct)) {
            $labels = [];
            foreach ($direct as $cls) {
                $labels[self::normalizeClass($cls['class_name'] ?? '')] = true;
            }
            if (count($labels) === 1) {
                $key = 'L:' . array_key_first($labels);
                self::$classKeyCache[$cacheKey] = $key;
                return self::describeClassKey($pdo, $schoolId, $raw, $normalized, $key, $direct);
            }
            // Two different labels share this exact code — refuse to guess.
            return array_merge($empty, ['kind' => 'ambiguous', 'ambiguous' => true, 'candidates' => self::slimClasses($direct)]);
        }

        // 2. Numeric family — "1" should find "Class 1" / "CLASS-1".
        [$prefix, $digits] = self::classTokens($normalized);
        if ($digits === '') {
            self::$classKeyCache[$cacheKey] = 'R:' . $normalized;
            return array_merge($empty, ['key' => 'R:' . $normalized, 'kind' => 'raw']);
        }

        $matches = [];
        foreach ($classes as $cls) {
            $codeNorm = self::normalizeClass($cls['numeric_class_name'] ?? '');
            $labelNorm = self::normalizeClass($cls['class_name'] ?? '');

            $hit = false;
            foreach ([$codeNorm, $labelNorm] as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                [$cPrefix, $cDigits] = self::classTokens($candidate);
                if ($cDigits !== '' && $cDigits === $digits && self::prefixCompatible($prefix, $cPrefix)) {
                    $hit = true;
                    break;
                }
            }

            if ($hit) {
                $matches[] = $cls;
            }
        }

        if (count($matches) === 1) {
            $key = 'L:' . self::normalizeClass($matches[0]['class_name'] ?? '');
            self::$classKeyCache[$cacheKey] = $key;
            return self::describeClassKey($pdo, $schoolId, $raw, $normalized, $key, $matches);
        }

        if (count($matches) > 1) {
            $labels = [];
            foreach ($matches as $cls) {
                $labels[self::normalizeClass($cls['class_name'] ?? '')] = true;
            }
            if (count($labels) === 1) {
                $key = 'L:' . array_key_first($labels);
                self::$classKeyCache[$cacheKey] = $key;
                return self::describeClassKey($pdo, $schoolId, $raw, $normalized, $key, $matches);
            }

            // Several genuinely different classes end with that number.
            return array_merge($empty, ['kind' => 'ambiguous', 'ambiguous' => true, 'candidates' => self::slimClasses($matches)]);
        }

        // 3. Nothing in sm_classes looks like this value: only an identical
        //    string matches. The admin gets a repair prompt instead of silence.
        self::$classKeyCache[$cacheKey] = 'R:' . $normalized;
        return array_merge($empty, ['key' => 'R:' . $normalized, 'kind' => 'orphan']);
    }

    /** @param array<int, array> $rows */
    private static function describeClassKey(PDO $pdo, string $schoolId, string $raw, string $normalized, string $key, ?array $rows = null): array
    {
        $classes = $rows ?? self::classesForSchool($pdo, $schoolId);
        $code = '';
        $label = '';
        $exists = false;

        foreach ($classes as $cls) {
            if ('L:' . self::normalizeClass($cls['class_name'] ?? '') === $key) {
                $code = (string)($cls['numeric_class_name'] ?? '');
                $label = (string)($cls['class_name'] ?? '');
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $kind = ($key !== '' && str_starts_with($key, 'R:')) ? 'orphan' : 'raw';
        } elseif (self::normalizeClass($code) === $normalized) {
            $kind = 'exact';
        } else {
            $kind = 'mapped';
        }

        return [
            'input'      => $raw,
            'normalized' => $normalized,
            'key'        => $key,
            'kind'       => $kind,
            'label'      => $label,
            'code'       => $code,
            'exists'     => $exists,
            'ambiguous'  => false,
            'candidates' => [],
        ];
    }

    /** @param array<int, array> $rows */
    private static function slimClasses(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $code = (string)($row['numeric_class_name'] ?? '');
            $label = (string)($row['class_name'] ?? '');
            if ($code === '' && $label === '') {
                continue;
            }
            $out[] = ['code' => $code, 'label' => $label];
        }
        return $out;
    }

    /**
     * Store the real class code when a value maps to one, otherwise keep what
     * the school typed. Used by every student write path so a form typo or a
     * legacy CSV column can no longer split a class in two.
     */
    public static function canonicalClass(PDO $pdo, ?string $schoolId, $value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $resolution = self::resolveClass($pdo, $schoolId, $raw);
        if ($resolution['ambiguous'] || $resolution['code'] === '') {
            return $raw;
        }

        return $resolution['code'];
    }

    /** Do these two stored values describe the same class? */
    public static function classSameAs(PDO $pdo, ?string $schoolId, $a, $b): bool
    {
        $leftNorm = self::normalizeClass($a);
        $rightNorm = self::normalizeClass($b);

        // Same stored value on both sides ("1" and "1", "JSS 1" and "jss-1") is
        // always the same class — even when that value is ambiguous against the
        // class list, because both records were written by the same school.
        if ($leftNorm !== '' && $leftNorm === $rightNorm) {
            return true;
        }

        $left = self::resolveClass($pdo, $schoolId, $a);
        $right = self::resolveClass($pdo, $schoolId, $b);

        if ($left['key'] === '' || $right['key'] === '') {
            return false;
        }

        if ($left['key'] === $right['key']) {
            return true;
        }

        // Two orphan values ("1" and "1") already share a key; anything else
        // that reached this point must not be treated as the same class.
        return false;
    }

    /**
     * Build the set of accepted class identifiers for a student's current class.
     * Kept for callers that compare raw strings; new code should use classSameAs().
     */
    public static function classAliases(PDO $pdo, ?string $schoolId, ?string $classCode): array
    {
        $resolution = self::resolveClass($pdo, $schoolId, $classCode);
        if ($resolution['key'] === '') {
            return [];
        }

        $aliases = [self::normalizeClass($resolution['input']) => true];
        if ($resolution['code'] !== '') {
            $aliases[self::normalizeClass($resolution['code'])] = true;
        }
        if ($resolution['label'] !== '') {
            $aliases[self::normalizeClass($resolution['label'])] = true;
        }

        unset($aliases['']);

        return array_keys($aliases);
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

        $studentClass = $student['current_class'] ?? '';
        if (self::normalizeClass($studentClass) === '') {
            return [];
        }

        $rows = self::approvedExamsForSchool($pdo, $schoolId);
        $visible = [];
        foreach ($rows as $row) {
            if (self::classSameAs($pdo, $schoolId, $row['numeric_class_name'] ?? '', $studentClass)) {
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

        $studentClass = $student['current_class'] ?? '';
        if (self::normalizeClass($studentClass) === '') {
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
            if (self::classSameAs($pdo, $schoolId, $row['numeric_class_name'] ?? '', $studentClass)) {
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
        self::$classRows = [];
        self::$classKeyCache = [];
        self::$classHistogram = [];
    }

    /** @var array<string, array<string, int>> Distinct current_class values per school, with counts. */
    private static array $classHistogram = [];

    /**
     * Distinct stored `current_class` values for a school, with how many students
     * hold each. A school has a handful of classes but can have thousands of
     * students, so every membership test runs against this instead of the roster.
     *
     * @return array<string, int> normalised class value => student count
     */
    public static function studentClassHistogram(PDO $pdo, ?string $schoolId): array
    {
        $schoolId = trim((string)$schoolId);
        if (isset(self::$classHistogram[$schoolId])) {
            return self::$classHistogram[$schoolId];
        }

        $histogram = [];
        try {
            $stmt = $pdo->prepare("SELECT current_class FROM sm_students WHERE school_id_number = ?");
            $stmt->execute([$schoolId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $studentClass) {
                $normalized = self::normalizeClass($studentClass);
                if ($normalized === '') {
                    continue;
                }
                $histogram[$normalized] = ($histogram[$normalized] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            $histogram = [];
        }

        self::$classHistogram[$schoolId] = $histogram;
        return self::$classHistogram[$schoolId];
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
        foreach (self::studentClassHistogram($pdo, $schoolId) as $studentClass => $howMany) {
            if (self::classSameAs($pdo, $schoolId, $classCode, $studentClass)) {
                $count += $howMany;
            }
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
        $resolution = self::resolveClass($pdo, $schoolId, $classCode);
        $classSize = self::classStudentCount($pdo, $schoolId, $classCode);
        $classKey  = $resolution['key'];

        $checks = [];

        $checks[] = [
            'ok'     => $approved,
            'label'  => 'Approved by School Admin',
            'detail' => $approved
                ? 'Approved — the exam is published to students.'
                : 'Pending approval — approve it from the CBT panel to release it to students.',
        ];

        // Class identity — the part that was silently failing before.
        if ($resolution['ambiguous']) {
            $names = [];
            foreach ($resolution['candidates'] as $candidate) {
                $names[] = $candidate['label'] . ' (' . $candidate['code'] . ')';
            }
            $checks[] = [
                'ok'     => $classSize > 0,
                'label'  => 'Class recognised',
                'detail' => 'Class code "' . $classCode . '" could mean any of: ' . implode(', ', array_slice($names, 0, 6)) . '. '
                    . ($classSize > 0
                        ? $classSize . ' student(s) store exactly "' . $classCode . '", so they receive this paper. Move the exam onto the exact class below to make it unambiguous.'
                        : 'No student stores exactly "' . $classCode . '". Choose the exact class below so the right students get this paper.'),
            ];
        } elseif ($resolution['kind'] === 'mapped') {
            $checks[] = [
                'ok'     => $classSize > 0,
                'label'  => 'Class recognised',
                'detail' => 'Class code "' . $classCode . '" is not in the class list; it was matched to '
                    . $resolution['label'] . ' (' . $resolution['code'] . '). '
                    . ($classSize > 0
                        ? $classSize . ' student(s) sit in that class.'
                        : 'No student is assigned to that class yet.'),
            ];
        } elseif ($resolution['kind'] === 'orphan') {
            $checks[] = [
                'ok'     => $classSize > 0,
                'label'  => 'Class exists',
                'detail' => 'Class code "' . $classCode . '" is not in your class list (Academics → Classes). '
                    . ($classSize > 0
                        ? 'It still reaches the ' . $classSize . ' student(s) whose Current Class is stored as "' . $classCode . '". Point the exam at a real class below to keep it in sync.'
                        : 'Pick the class this paper belongs to below, or re-create the class with that exact code.'),
            ];
        } else {
            $checks[] = [
                'ok'     => $classSize > 0,
                'label'  => 'Class has students',
                'detail' => $classSize > 0
                    ? $classSize . ' student(s) are assigned to ' . ($resolution['label'] !== '' ? $resolution['label'] : $classCode) . '.'
                    : 'Nobody is assigned to class code ' . ($classCode !== '' ? $classCode : '(empty)')
                        . '. Check the Current Class of each student in the Registry, or assign them below.',
            ];
        }

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
            $studentClass = (string)($student['current_class'] ?? '');
            $studentResolution = self::resolveClass($pdo, $schoolId, $studentClass);
            $matches = self::classSameAs($pdo, $schoolId, $classCode, $studentClass);

            $checks[] = [
                'ok'     => $matches,
                'label'  => 'Student class match',
                'detail' => $matches
                    ? 'Student class ' . $studentClass . ' matches this exam.'
                    : 'Student class ' . ($studentClass !== '' ? $studentClass : '(not set)')
                        . ' does not match ' . ($classCode !== '' ? $classCode : '(empty)')
                        . ($studentResolution['ambiguous'] ? ' (that student class value is ambiguous).' : '.'),
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
            'checks'      => $checks,
            'verdict'     => $verdict,
            'window'      => $window,
            'approved'    => $approved,
            'published'   => $published,
            'class_size'  => $classSize,
            'class'       => $resolution,
            'class_key'   => $classKey,
            'needs_repair'=> ($resolution['ambiguous'] || $resolution['kind'] === 'orphan') && $classSize === 0,
        ];
    }

    /* ---------------------------------------------------------------------
     |  Repair tools
     * ------------------------------------------------------------------- */

    /**
     * Point an exam at a real class code. This is the permanent fix when the
     * paper was scheduled against a code that no longer exists, or against a
     * label-ish value ("Class 1") instead of the generated code ("CLASS-1").
     */
    public static function reassignExamClass(PDO $pdo, ?string $schoolId, int $cbtId, string $newClassCode): bool
    {
        $newClassCode = trim($newClassCode);
        if ($cbtId <= 0 || $newClassCode === '') {
            return false;
        }

        // The target must be a class that actually exists for this school.
        $resolution = self::resolveClass($pdo, $schoolId, $newClassCode);
        if ($resolution['key'] === '' || $resolution['code'] === '') {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE sm_cbt_schedules SET numeric_class_name = ? WHERE school_id_number = ? AND cbt_id = ?"
            );
            $stmt->execute([$resolution['code'], trim((string)$schoolId), $cbtId]);
            self::flushCache();

            // rowCount() is 0 when the paper already sits on that class, so read
            // the stored value back instead of trusting the update counter.
            $verify = $pdo->prepare("SELECT numeric_class_name FROM sm_cbt_schedules WHERE school_id_number = ? AND cbt_id = ? LIMIT 1");
            $verify->execute([trim((string)$schoolId), $cbtId]);
            $stored = $verify->fetchColumn();

            return $stored !== false && self::normalizeClass($stored) === self::normalizeClass($resolution['code']);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Move students into a class code (Registry bulk fix). Only ever scoped to
     * the current school, and only to a class that exists.
     */
    public static function assignStudentsToClass(PDO $pdo, ?string $schoolId, array $admissions, string $classCode): int
    {
        $schoolId = trim((string)$schoolId);
        $classCode = trim($classCode);

        $admissions = array_values(array_filter(array_map(static function ($a) {
            return trim((string)$a);
        }, $admissions), static function ($a) {
            return $a !== '';
        }));

        if ($schoolId === '' || $classCode === '' || empty($admissions)) {
            return 0;
        }

        $resolution = self::resolveClass($pdo, $schoolId, $classCode);
        if ($resolution['code'] === '') {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($admissions), '?'));
        $params = array_merge([$resolution['code'], $schoolId], $admissions);

        try {
            $stmt = $pdo->prepare(
                "UPDATE sm_students SET current_class = ? WHERE school_id_number = ? AND admission_number IN ({$placeholders})"
            );
            $stmt->execute($params);
            self::flushCache();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Plain-language status of a student's class, shown when their exam list is
     * empty so the student (and their parent) know who to ask instead of staring
     * at a blank screen.
     *
     * @return array{class:string, label:string, known:bool, ambiguous:bool, message:string}
     */
    public static function studentClassStatus(PDO $pdo, ?string $schoolId, ?array $student): array
    {
        $classValue = (string)($student['current_class'] ?? '');
        $resolution = self::resolveClass($pdo, $schoolId, $classValue);

        $status = [
            'class'     => $classValue,
            'label'     => $resolution['label'],
            'known'     => $resolution['exists'] || $resolution['kind'] === 'exact',
            'ambiguous' => $resolution['ambiguous'],
            'message'   => '',
        ];

        if (self::normalizeClass($classValue) === '') {
            $status['message'] = 'Your student record has no class set. Ask your school to open the Registry and set your Current Class.';
            return $status;
        }

        if ($resolution['ambiguous']) {
            $status['message'] = 'Your class "' . $classValue . '" matches more than one class in the school records. Ask your school to set your Current Class to the exact class name.';
            return $status;
        }

        if ($resolution['kind'] === 'orphan') {
            $status['message'] = 'Your class "' . $classValue . '" is not on the school\'s class list any more. Ask your school to set your Current Class in the Registry.';
            return $status;
        }

        $status['message'] = 'Your class is ' . ($resolution['label'] !== '' ? $resolution['label'] : $classValue)
            . '. No paper is scheduled for it yet, or the paper has not been approved.';

        return $status;
    }

    /**
     * How much data still points at a class: students in the Registry and
     * scheduled exams. Deleting a class with either of those is what leaves a
     * school with papers nobody is assigned to, so the delete path checks this.
     *
     * @return array{students:int, exams:int, pending:int}
     */
    public static function classUsage(PDO $pdo, ?string $schoolId, ?string $classCode): array
    {
        $usage = ['students' => 0, 'exams' => 0, 'pending' => 0];
        if (trim((string)$classCode) === '') {
            return $usage;
        }

        foreach (self::studentClassHistogram($pdo, $schoolId) as $studentClass => $howMany) {
            if (self::classSameAs($pdo, $schoolId, $classCode, $studentClass)) {
                $usage['students'] += $howMany;
            }
        }

        try {
            $stmt = $pdo->prepare("SELECT numeric_class_name, is_approved FROM sm_cbt_schedules WHERE school_id_number = ?");
            $stmt->execute([trim((string)$schoolId)]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $exam) {
                if (self::classSameAs($pdo, $schoolId, $classCode, $exam['numeric_class_name'] ?? '')) {
                    $usage['exams']++;
                    if ((int)($exam['is_approved'] ?? 1) !== 1) {
                        $usage['pending']++;
                    }
                }
            }
        } catch (Throwable $e) {
            $usage['exams'] = 0;
            $usage['pending'] = 0;
        }

        return $usage;
    }

    /**
     * Every class in the school with its student count, plus the stored
     * current_class values that no longer belong to a class. This is the roster
     * the repair screen shows, so the school can see what is actually stored.
     */
    public static function classRoster(PDO $pdo, ?string $schoolId): array
    {
        $schoolId = trim((string)$schoolId);

        $students = [];
        try {
            $stmt = $pdo->prepare("SELECT admission_number, firstname, lastname, current_class, session FROM sm_students WHERE school_id_number = ? ORDER BY firstname ASC, lastname ASC");
            $stmt->execute([$schoolId]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $students = [];
        }

        $byKey = [];
        $orphans = [];

        foreach ($students as $student) {
            $resolution = self::resolveClass($pdo, $schoolId, $student['current_class'] ?? '');
            $student['resolved'] = $resolution['label'] !== '' ? $resolution['label'] : '';

            if ($resolution['key'] === '') {
                $orphans[$resolution['normalized'] !== '' ? $resolution['normalized'] : '(blank)'][] = $student;
                continue;
            }

            $byKey[$resolution['key']][] = $student;
        }

        $classes = [];
        foreach (self::classesForSchool($pdo, $schoolId) as $cls) {
            $key = 'L:' . self::normalizeClass($cls['class_name'] ?? '');
            $classes[] = [
                'id'      => (int)($cls['id'] ?? 0),
                'code'    => (string)($cls['numeric_class_name'] ?? ''),
                'label'   => (string)($cls['class_name'] ?? ''),
                'session' => (string)($cls['session'] ?? ''),
                'students'=> $byKey[$key] ?? [],
                'count'   => isset($byKey[$key]) ? count($byKey[$key]) : 0,
            ];
        }

        // Students resolved to a code that is not (or no longer) in sm_classes.
        $unlisted = [];
        foreach ($byKey as $key => $rows) {
            if (str_starts_with((string)$key, 'R:')) {
                $unlisted = array_merge($unlisted, $rows);
            }
        }

        return [
            'classes'  => $classes,
            'students' => $students,
            'orphans'  => $orphans,
            'unlisted' => $unlisted,
        ];
    }

    /**
     * One-line summary of who a paper reaches, for the CBT table. Never claims
     * "nobody is assigned" when the truth is "this code is not a class".
     *
     * @return array{state:string, text:string, count:int, fixable:bool}
     */
    public static function examClassSummary(PDO $pdo, ?string $schoolId, $classCode): array
    {
        $resolution = self::resolveClass($pdo, $schoolId, $classCode);
        $count = self::classStudentCount($pdo, $schoolId, $classCode);

        if ($count > 0) {
            return [
                'state'   => 'ok',
                'text'    => 'Visible to ' . $count . ' student' . ($count === 1 ? '' : 's') . ' in this class',
                'count'   => $count,
                'fixable' => false,
            ];
        }

        if ($resolution['ambiguous']) {
            return [
                'state'   => 'ambiguous',
                'text'    => 'Class code "' . (string)$classCode . '" matches more than one class — choose the right one',
                'count'   => 0,
                'fixable' => true,
            ];
        }

        if ($resolution['kind'] === 'orphan' || $resolution['key'] === '') {
            return [
                'state'   => 'orphan',
                'text'    => 'Class code "' . (string)$classCode . '" is not in your class list — move this exam to a real class',
                'count'   => 0,
                'fixable' => true,
            ];
        }

        $label = $resolution['label'] !== '' ? $resolution['label'] : (string)$classCode;

        return [
            'state'   => 'empty',
            'text'    => $label . ' has no students yet — assign them from the Registry',
            'count'   => 0,
            'fixable' => true,
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
