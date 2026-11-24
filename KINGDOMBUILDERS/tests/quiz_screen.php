<?php
declare(strict_types=1);
/**
 * Static checks on the quiz screen's source, plus a real exercise of the new
 * answer rules with a stubbed database. No MySQL is needed:
 *   php tests/quiz_screen.php
 */
namespace KB {
    // ── Minimal stand-ins, just enough for Quiz to run in memory ────────────
    final class Settings {
        public static array $values = [];
        public static function all(): array { return self::$values; }
        public static function get(string $key, mixed $default = null): mixed { return self::$values[$key] ?? $default; }
        public static function put(string $key, mixed $value): void { self::$values[$key] = $value; }
    }
    final class Audit { public static function activity(...$args): void {} public static function log(...$args): void {} }
    final class UserError extends \RuntimeException {}
    function config(string $key, mixed $default = null): mixed { return $key === 'key' ? 'test-key' : $default; }
    function now(): string { return gmdate('Y-m-d H:i:s'); }

    /** Tiny in-memory stand-in for the few queries Quiz uses. */
    final class DB {
        public static array $attempts = [];
        public static array $items = [];
        public static function one(string $sql, array $p = []): ?array {
            if (str_contains($sql, 'FROM attempts WHERE id=? AND user_id=?')) {
                $a = self::$attempts[$p[0]] ?? null;
                return $a && (int)$a['user_id'] === (int)$p[1] ? $a : null;
            }
            if (str_contains($sql, 'FROM attempts WHERE active_user_id=?')) {
                foreach (self::$attempts as $a) if ((int)($a['active_user_id'] ?? 0) === (int)$p[0]) return ['id'=>$a['id']];
                return null;
            }
            if (str_contains($sql, 'FROM attempt_items WHERE attempt_id=? AND position=?')) {
                return self::$items[$p[0]][(int)$p[1]] ?? null;
            }
            if (str_contains($sql, 'SELECT id FROM users')) return ['id'=>(int)$p[0]];
            return null;
        }
        public static function value(string $sql, array $p = []): mixed {
            if (str_contains($sql, 'COUNT(*) FROM attempts WHERE user_id=? AND status<>')) {
                $n = 0; foreach (self::$attempts as $a) if ((int)$a['user_id'] === (int)$p[0] && $a['status'] !== 'active') $n++;
                return $n;
            }
            if (str_contains($sql, 'COUNT(*) FROM attempt_items WHERE attempt_id=? AND answer IS NOT NULL')) {
                return count(array_filter(self::$items[$p[0]] ?? [], static fn($i) => $i['answer'] !== null));
            }
            if (str_contains($sql, 'COALESCE(MIN(position)')) {
                foreach (self::$items[$p[0]] ?? [] as $i) if ($i['answer'] === null && (int)$i['is_skipped'] === 0) return (int)$i['position'];
                return (int)$p[0];
            }
            return null;
        }
        public static function run(string $sql, array $p = []): object {
            if (str_contains($sql, 'UPDATE attempt_items SET answer=')) {
                foreach (self::$items as $attempt => $items) {
                    foreach ($items as $position => $item) {
                        if ((int)$item['id'] === (int)$p[3]) {
                            self::$items[$attempt][$position]['answer'] = $p[0] === null ? null : (int)$p[0];
                            self::$items[$attempt][$position]['is_skipped'] = (int)$p[1];
                        }
                    }
                }
            }
            return new class { public int $rowCount = 1; };
        }
        public static function all(string $sql, array $p = []): array { return []; }
        public static function transaction(callable $fn): mixed { return $fn(); }
    }

}

namespace {

    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $root = dirname(__DIR__);

    function check(bool $ok, string $label): void {
        if (!$ok) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
        echo "PASS {$label}\n";
    }

    // ── 1. The shipped source says what the members must be told ─────────────
    $js = (string) file_get_contents($root . '/assets/quiz.js');
    check(str_contains($js, "action: 'leave'"), 'leaving the page ends the assessment');
    check(str_contains($js, 'Leave the quiz?'), 'the member is warned before leaving');
    check(str_contains($js, 'keepalive: true'), 'the leave request survives a page close');
    check(str_contains($js, "Answer sheet"), 'the question menu is called an answer sheet');
    check(str_contains($js, "You can still change it"), 'the member is told an answer can be changed');
    check(str_contains($js, "Submit assessment"), 'the last step is an explicit submission');
    check(!str_contains($js, 'cannot be changed'), 'no code path still claims an answer cannot be changed');
    check(!str_contains($js, 'q.locked'), 'the old lock flag is gone from the screen logic');
    check(str_contains($js, 'state.budget_seconds'), 'the timer bar uses the real total budget');
    $dashboard = (string) file_get_contents($root . '/app/Views/member/dashboard.php');
    check(str_contains($dashboard, 'duration_minutes'), 'the dashboard shows the total time');
    check(str_contains($dashboard, 'One attempt only'), 'the dashboard says the assessment is taken once');

    // ── 2. The rules themselves ──────────────────────────────────────────────
    require $root . '/app/Core/Quiz.php';
    \KB\Settings::$values = ['allow_back'=>true,'duration_minutes'=>20,'seconds_per_question'=>24,'timer_enabled'=>true,'questions_per_attempt'=>50];
    check(\KB\Quiz::budget(['duration_minutes'=>20,'seconds_per_question'=>24], 50) === 1200, '50 questions in 20 minutes');
    check(\KB\Quiz::budget(['duration_minutes'=>20,'seconds_per_question'=>24], 41) === 1200, 'a 41-question bank still gets 20 minutes');
    check(\KB\Quiz::durationLabel(1200) === '20 minutes', 'the duration reads naturally');

    $id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    \KB\DB::$attempts[$id] = [
        'id'=>$id,'user_id'=>7,'active_user_id'=>7,'status'=>'active','total_questions'=>2,
        'settings_snapshot'=>json_encode(\KB\Settings::$values),'expires_at'=>gmdate('Y-m-d H:i:s', time()+600),
        'score'=>0,'percentage'=>0,'tab_switches'=>0,
    ];
    \KB\DB::$items[$id] = [
        0 => ['id'=>1,'position'=>0,'answer'=>null,'is_skipped'=>0,'correct_option'=>2,'explanation'=>''],
        1 => ['id'=>2,'position'=>1,'answer'=>null,'is_skipped'=>0,'correct_option'=>0,'explanation'=>''],
    ];
    $saved = \KB\Quiz::answer($id, 7, 0, 1);
    check($saved['changed'] === false, 'the first choice is a new answer');
    \KB\DB::$items[$id][0]['answer'] = 1;
    $changed = \KB\Quiz::answer($id, 7, 0, 3);
    check($changed['changed'] === true, 'a saved answer can be changed');
    check((int)\KB\DB::$items[$id][0]['answer'] === 3, 'the latest choice is stored');
    $same = \KB\Quiz::answer($id, 7, 0, 3);
    check($same['changed'] === false && $same['saved'] === true, 'repeating a choice changes nothing');
    \KB\DB::$attempts[$id]['status'] = 'submitted';
    try { \KB\Quiz::answer($id, 7, 0, 0); check(false, 'a submitted attempt refuses changes'); }
    catch (\KB\UserError $e) { check(str_contains($e->getMessage(), 'already ended'), 'a submitted attempt refuses changes'); }

    echo "Quiz screen tests passed.\n";
}
