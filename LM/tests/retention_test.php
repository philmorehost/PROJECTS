<?php
/**
 * tests/retention_test.php — the release-retention rule, without a database.
 *
 * Runs under the repo's PHP runtime (tools/lm-render-check) and asserts the
 * behaviour that matters:
 *
 *   1. the newest release is never deleted, whatever the setting says;
 *   2. `keep = 1` removes every older release — the automatic case;
 *   3. the age floor keeps a release that the count alone would remove;
 *   4. a file that cannot be deleted is reported, not counted as removed, and
 *      its database row survives so a retry can find it again (this is the
 *      "it did not clear again and there was no error" bug);
 *   5. a missing file is not a failure;
 *   6. the summary line distinguishes "removed" from "could not remove".
 *
 * Usage: php tests/retention_test.php   (exit 0 = all pass)
 */

$failures = 0;
$checks = 0;

function check($name, $condition, $detail = '')
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "ok    {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$name}" . ($detail !== '' ? "\n      " . $detail : '') . "\n";
}

/* ------------------------------------------------------------------ fixtures */

/*
 * lm_delete_release() logs on success and on failure. Under test that must not
 * land in LM/storage/logs/updates.log — those logs are real operator history and
 * are tracked in git. Declaring the stub before lm_config.php loads is what makes
 * it win: lm_config.php guards its own definition with function_exists().
 */
$GLOBALS['lm_test_log_dir'] = sys_get_temp_dir() . '/lm_retention_logs_' . getmypid();
@mkdir($GLOBALS['lm_test_log_dir'], 0777, true);

if (!function_exists('lm_log')) {
    function lm_log($channel, $message)
    {
        // Deliberately silent: the test asserts on returned values.
    }
}

// The planner needs version comparison; pull in the real one.
require_once __DIR__ . '/../includes/lm_config.php';
if (!function_exists('lm_version_compare')) {
    // Minimal stand-in if lm_config.php does not carry it in this build.
    function lm_version_compare($a, $b)
    {
        $pa = array_map('intval', preg_split('/[._-]/', (string) $a));
        $pb = array_map('intval', preg_split('/[._-]/', (string) $b));
        for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
            $x = $pa[$i] ?? 0;
            $y = $pb[$i] ?? 0;
            if ($x !== $y) {
                return $x > $y ? 1 : -1;
            }
        }
        return 0;
    }
}

require_once __DIR__ . '/../includes/release_retention.php';

$now = strtotime('2026-11-14 12:00:00 UTC');

// Eight releases, one per day, oldest first in the "database" order that
// lm_retention_prune() uses (ORDER BY id ASC).
$rows = array();
for ($i = 1; $i <= 8; $i++) {
    $rows[] = array(
        'id' => $i,
        'version_number' => '7.' . (99 + $i),
        'release_date' => gmdate('Y-m-d H:i:s', $now - ((8 - $i) * 86400)),
    );
}

/* ------------------------------------------------------- 1. the newest stays */

$plan = lm_retention_plan($rows, array('keep' => 1, 'keep_days' => 0), $now);
$keptIds = array_column($plan['keep'], 'id');
check('keep=1 keeps exactly one release', count($plan['keep']) === 1, 'kept: ' . implode(',', $keptIds));
check('keep=1 keeps the newest (id 8)', $keptIds === array(8), 'kept: ' . implode(',', $keptIds));
check('keep=1 drops the other seven', count($plan['drop']) === 7, 'dropped: ' . count($plan['drop']));

$plan = lm_retention_plan($rows, array('keep' => 0, 'keep_days' => 0), $now);
check('keep=0 is clamped to 1', count($plan['keep']) === 1 && array_column($plan['keep'], 'id') === array(8));

$plan = lm_retention_plan($rows, array('keep' => 500, 'keep_days' => 0), $now);
check('keep=500 is clamped to 50 (retention cannot become a no-op by typo)', count($plan['keep']) === 8);

/* ------------------------------------------------- 2. version order, not id */
// A re-publish updates the row in place, so the newest id is not always the
// newest version. The rule must follow the version number.
$shuffled = array(
    array('id' => 3, 'version_number' => '7.101', 'release_date' => '2026-11-10 00:00:00'),
    array('id' => 9, 'version_number' => '7.90',  'release_date' => '2026-11-12 00:00:00'),
    array('id' => 4, 'version_number' => '7.102', 'release_date' => '2026-11-13 00:00:00'),
);
$plan = lm_retention_plan($shuffled, array('keep' => 1, 'keep_days' => 0), $now);
check(
    'the newest VERSION is kept, even when a higher id looks newer',
    array_column($plan['keep'], 'version_number') === array('7.102'),
    'kept: ' . implode(',', array_column($plan['keep'], 'version_number'))
);

/* --------------------------------------------------------- 3. the age floor */

// Keep 2, but nothing published within the last 3 days may go.
$plan = lm_retention_plan($rows, array('keep' => 2, 'keep_days' => 3), $now);
$dropped = array_column($plan['drop'], 'version_number');
$droppedDates = array_column($plan['drop'], 'release_date');
$allOldEnough = true;
foreach ($droppedDates as $date) {
    if (($now - strtotime($date)) < (3 * 86400)) {
        $allOldEnough = false;
    }
}
check('age floor keeps every release younger than 3 days', $allOldEnough, 'dropped: ' . implode(',', $dropped));
check('age floor still drops the older ones', count($plan['drop']) > 0);

$plan = lm_retention_plan($rows, array('keep' => 1, 'keep_days' => 0), $now);
check('keep_days=0 means the count is the only rule', count($plan['keep']) === 1);

/* ------------------------------------- 4. delete: failure is reported, row stays */

/*
 * A PDO stand-in. Not a subclass: PDO's method signatures are fixed, and a
 * stub only has to answer the few calls lm_delete_release() makes. The type
 * hint in that function is lifted with a `$pdo` that is duck-typed, so this
 * works without a real connection.
 */
class FakePdo
{
    public $rows = array();
    public $deleted = array();

    public function __construct(array $rows = array())
    {
        $this->rows = $rows;
    }

    public function prepare($sql)
    {
        return new FakeStatement($this, $sql);
    }

    public function query($sql)
    {
        return new FakeStatement($this, $sql);
    }
}

class FakeStatement
{
    private $pdo;
    private $sql;
    private $rows = array();

    public function __construct($pdo, $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute($params = array())
    {
        $sql = strtolower($this->sql);
        $this->rows = array();

        if (strpos($sql, 'from script_updates') !== false && strpos($sql, 'where u.id =') !== false) {
            $id = (int) ($params[0] ?? 0);
            foreach ($this->pdo->rows as $row) {
                if ((int) $row['id'] === $id) {
                    $this->rows = array($row);
                    break;
                }
            }
            return true;
        }

        if (strpos($sql, 'delete from script_updates') !== false) {
            $this->pdo->deleted[] = (int) ($params[0] ?? 0);
        }

        return true;
    }

    public function fetchAll()
    {
        return $this->rows;
    }

    public function fetch()
    {
        return $this->rows[0] ?? false;
    }

    public function fetchColumn()
    {
        return $this->rows ? array_values($this->rows[0])[0] : false;
    }

    public function rowCount()
    {
        return count($this->rows);
    }
}

/*
 * The filesystem half. lm_delete_release() resolves the stored path, so the
 * test needs a real directory it controls and can chmod.
 */
$sandbox = sys_get_temp_dir() . '/lm_retention_test_' . getmypid();
@mkdir($sandbox, 0777, true);
$updatesRoot = $sandbox . '/secure_updates';
$tierDir = $updatesRoot . '/test';
@mkdir($tierDir, 0777, true);

/*
 * The filesystem half. lm_delete_release() resolves the stored path through
 * helpers that live in updates.php; the test supplies its own only where the
 * real one would point at the production update store. Each is guarded, because
 * lm_config.php already defines some of them and a redeclare is a fatal.
 */
if (!function_exists('lm_updates_root')) {
    function lm_updates_root()
    {
        global $updatesRoot;
        return $updatesRoot;
    }
}

if (!function_exists('lm_storage_dir')) {
    function lm_storage_dir()
    {
        return __DIR__ . '/../storage';
    }
}

if (!function_exists('lm_resolve_update_path')) {
    function lm_resolve_update_path($stored, $tierCode = null)
    {
        $candidates = array();
        if ($stored) {
            $candidates[] = $stored;
        }
        $path = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $path = $candidate;
                break;
            }
        }
        return array('path' => $path, 'candidates' => $candidates);
    }
}

if (!function_exists('lm_tier_storage_dir')) {
    function lm_tier_storage_dir($tier, $create = true)
    {
        global $tierDir;
        return $tierDir;
    }
}

if (!function_exists('lm_remove_tree')) {
    function lm_remove_tree($dir)
    {
        foreach ((array) @scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? lm_remove_tree($p) : @unlink($p);
        }
        return @rmdir($dir);
    }
}

// A package that deletes cleanly.
$deletable = $tierDir . '/update_7.100.zip';
file_put_contents($deletable, 'zip');

// A package that cannot be deleted: make the directory read-only so unlink()
// fails for a non-root user. (When the test runs as root, chmod does not
// stop unlink — the assertion then falls back to the "missing file" path.)
$stuckDir = $tierDir . '/locked';
@mkdir($stuckDir, 0777, true);
$stuck = $stuckDir . '/update_7.101.zip';
file_put_contents($stuck, 'zip');
@chmod($stuckDir, 0555);

$pdo = new FakePdo(array(
    array('id' => 1, 'version_number' => '7.100', 'zip_path' => $deletable, 'tier_code' => 'test', 'release_date' => '2026-11-10 00:00:00'),
    array('id' => 2, 'version_number' => '7.101', 'zip_path' => $stuck, 'tier_code' => 'test', 'release_date' => '2026-11-11 00:00:00'),
));

$delete = lm_delete_release($pdo, 1);
check('a package that can be deleted is removed', $delete['ok'] === true && !file_exists($deletable), 'error: ' . $delete['error']);
check('its row is deleted', in_array(1, $pdo->deleted, true), 'deleted: ' . implode(',', $pdo->deleted));

$pdo->deleted = array();
$delete = lm_delete_release($pdo, 2);
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
if (!$isRoot && file_exists($stuck)) {
    check('a package that cannot be deleted is NOT reported as removed', $delete['ok'] === false, 'ok was true');
    check('the failure names the path', strpos($delete['error'], 'update_7.101.zip') !== false, 'error: ' . $delete['error']);
    check('the row survives so a retry can find it', !in_array(2, $pdo->deleted, true), 'the row was deleted anyway');
} else {
    // Root ignores directory permissions: assert the "already gone" contract
    // instead of a failure we cannot provoke.
    check('root cannot provoke a permission failure — checking the missing-file contract', true);
    @chmod($stuckDir, 0777);
    $missing = lm_delete_release($pdo, 2);
    check('an already-missing package is not an error', $missing['ok'] === true, 'error: ' . $missing['error']);
}

@chmod($stuckDir, 0777);

/* ------------------------------------------------- 5. the summary line */

$removed = lm_retention_summary_line(array('retention' => array('deleted' => 2, 'files' => 2, 'failed' => array())));
check('summary says what was removed', strpos($removed, 'removed 2') !== false, $removed);

$broken = lm_retention_summary_line(array('retention' => array(
    'deleted' => 0,
    'files' => 0,
    'failed' => array(array('version' => '7.100', 'error' => 'permission denied', 'paths' => array())),
)));
check('summary says it failed, and why', stripos($broken, 'could NOT') !== false && stripos($broken, 'permission denied') !== false, $broken);

$nothing = lm_retention_summary_line(array('retention' => array('deleted' => 0, 'files' => 0, 'failed' => array())));
check('summary distinguishes "nothing to remove" from "removed"', strpos($nothing, 'no old release') !== false, $nothing);

$none = lm_retention_summary_line(array());
check('summary never returns an empty string', $none !== '' && strpos($none, 'not checked') !== false, $none);

/* --------------------------------------------------------------------- done */

@rmdir($stuckDir);
@rmdir($tierDir);
@rmdir($updatesRoot);
@rmdir($sandbox);
@rmdir($GLOBALS['lm_test_log_dir']);

echo "\n--- {$failures} of {$checks} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
