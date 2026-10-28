<?php
/**
 * A stateful stand-in for `github_pull_jobs`, for the pull-cancellation tests.
 *
 * fakedb.php answers from fixed fixtures, which is right for pages that only
 * read. Stopping a pull is a state machine — queued → running → cancelled, and
 * "the worker may not overwrite a stop" — so this fake keeps real rows and
 * applies the UPDATEs the way MySQL would, including counting *changed* rows
 * rather than matched ones (PDO's default) because that difference is exactly
 * what would make a repeated progress write look like a cancellation.
 *
 * It understands only the statements includes/pull_jobs.php sends. Anything
 * else is recorded in $GLOBALS['qa_unhandled'] so a drifted query fails a test
 * loudly instead of silently returning nothing.
 */

if (!class_exists('JobStore')) {
    class JobStore
    {
        /** @var array<int,array<string,mixed>> */
        public static $rows = array();
        public static $nextId = 1;

        public static function reset(): void
        {
            self::$rows = array();
            self::$nextId = 1;
        }

        public static function insert(array $row): int
        {
            $id = self::$nextId++;
            $row['id'] = $id;
            $row['created_at'] = $row['created_at'] ?? gmdate('Y-m-d H:i:s');
            self::$rows[$id] = $row;
            return $id;
        }

        public static function all(): array
        {
            $rows = self::$rows;
            ksort($rows);
            return array_values($rows);
        }
    }
}

if (!class_exists('JobStmt')) {
    class JobStmt extends PDOStatement
    {
        public $sql;
        public $rows = array();
        public $affected = 0;
        public $args = array();

        public function __construct(string $sql = '')
        {
            $this->sql = $sql;
        }

        private function q(): string
        {
            return strtolower(trim((string) preg_replace('/\s+/', ' ', $this->sql)));
        }

        public function execute(?array $params = null): bool
        {
            $this->args = $params ? array_values($params) : array();
            $this->rows = array();
            $this->affected = 0;
            $this->resolve();
            $GLOBALS['qa_sql'][] = $this->sql;
            return true;
        }

        private function resolve(): void
        {
            $q = $this->q();

            if (strpos($q, 'insert into github_pull_jobs') === 0) {
                $this->doInsert($q);
                return;
            }

            if (strpos($q, 'update github_pull_jobs') === 0) {
                $this->doUpdate($q);
                return;
            }

            if (strpos($q, 'select 1 from github_pull_jobs') === 0) {
                $this->rows = array(array('1' => '1'));
                return;
            }

            if (strpos($q, 'select status from github_pull_jobs where id =') === 0) {
                $id = (int) ($this->args[0] ?? 0);
                $this->rows = isset(JobStore::$rows[$id])
                    ? array(array('status' => JobStore::$rows[$id]['status']))
                    : array();
                return;
            }

            if (strpos($q, 'select * from github_pull_jobs where id =') === 0) {
                $id = (int) ($this->args[0] ?? 0);
                $this->rows = isset(JobStore::$rows[$id]) ? array(JobStore::$rows[$id]) : array();
                return;
            }

            if (strpos($q, 'select * from github_pull_jobs where tier_id =') === 0) {
                $tierId = (int) ($this->args[0] ?? 0);
                $out = array();
                foreach (JobStore::all() as $row) {
                    if ((int) $row['tier_id'] !== $tierId) {
                        continue;
                    }
                    if (strpos($q, "status in ('queued', 'running')") !== false
                        && !in_array($row['status'], array('queued', 'running'), true)) {
                        continue;
                    }
                    $out[] = $row;
                }
                $out = array_reverse($out);          // ORDER BY id DESC
                $this->rows = array_slice($out, 0, 1);
                return;
            }

            if (strpos($q, "select * from github_pull_jobs where status = 'queued'") === 0) {
                $out = array();
                foreach (JobStore::all() as $row) {
                    if ($row['status'] === 'queued') {
                        $out[] = $row;
                    }
                }
                $limit = preg_match('/limit (\d+)/', $q, $m) ? (int) $m[1] : 10;
                $this->rows = array_slice($out, 0, $limit);
                return;
            }

            $GLOBALS['qa_unhandled'][] = $this->sql;
        }

        private function doInsert(string $q): void
        {
            preg_match('/\(([^)]*)\)\s*values\s*\((.*)\)\s*$/', $q, $m);
            if (empty($m)) {
                $GLOBALS['qa_unhandled'][] = $this->sql;
                return;
            }

            $columns = array_map('trim', explode(',', $m[1]));
            $values = self::splitValues($m[2]);

            $row = array();
            $argIndex = 0;
            foreach ($columns as $i => $column) {
                $column = trim($column, "` \t");
                $value = isset($values[$i]) ? $values[$i] : 'null';
                if ($value === '?') {
                    $row[$column] = $this->args[$argIndex++] ?? null;
                } elseif (stripos($value, 'utc_timestamp()') !== false) {
                    $row[$column] = gmdate('Y-m-d H:i:s');
                } elseif (preg_match("/^'(.*)'$/", $value, $lit)) {
                    $row[$column] = $lit[1];
                } elseif (is_numeric($value)) {
                    $row[$column] = 0 + $value;
                } else {
                    $row[$column] = null;
                }
            }

            $before = JobStore::$rows;
            JobStore::insert($row);
            $this->affected = count(JobStore::$rows) - count($before);
        }

        private function doUpdate(string $q): void
        {
            if (!preg_match('/^update github_pull_jobs set (.*?) where (.*)$/', $q, $m)) {
                $GLOBALS['qa_unhandled'][] = $this->sql;
                return;
            }

            $args = $this->args;
            $argIndex = 0;

            // SET: the placeholders here consume the first arguments.
            $set = array();
            foreach (self::splitValues($m[1]) as $assignment) {
                if (!preg_match('/^\s*([a-z_]+)\s*=\s*(.*)$/i', $assignment, $a)) {
                    continue;
                }
                $column = strtolower($a[1]);
                $expr = trim($a[2]);

                if ($expr === '?') {
                    $set[$column] = $args[$argIndex++] ?? null;
                } elseif (stripos($expr, 'utc_timestamp()') === 0 || stripos($expr, 'coalesce(') === 0) {
                    $set[$column] = gmdate('Y-m-d H:i:s');   // tests only need "now"
                } elseif (preg_match("/^'(.*)'$/", $expr, $lit)) {
                    $set[$column] = $lit[1];
                } elseif (is_numeric($expr)) {
                    $set[$column] = 0 + $expr;
                } else {
                    $set[$column] = null;
                }
            }

            $where = $m[2];
            $whereArgs = array_slice($args, $argIndex);

            // Which rows does the WHERE select?
            $matched = array();
            foreach (JobStore::$rows as $id => $row) {
                if (strpos($where, 'id = ?') !== false) {
                    if ((int) ($whereArgs[0] ?? 0) !== (int) $id) {
                        continue;
                    }
                }
                if (preg_match("/status = '([a-z]+)'/", $where, $st)) {
                    if ($row['status'] !== $st[1]) {
                        continue;
                    }
                }
                if (preg_match('/status in \(([^)]+)\)/', $where, $si)) {
                    preg_match_all("/'([a-z]+)'/", $si[1], $names);
                    if (!in_array($row['status'], $names[1], true)) {
                        continue;
                    }
                }
                if (strpos($where, 'created_at <') !== false) {
                    preg_match('/interval (\d+) second/', $where, $iv);
                    $seconds = isset($iv[1]) ? (int) $iv[1] : 600;
                    $created = $row['created_at'] ?? null;
                    if ($created === null || strtotime($created . ' UTC') >= time() - $seconds) {
                        continue;
                    }
                }
                if (strpos($where, 'started_at is null') !== false && !empty($row['started_at'])) {
                    continue;
                }
                if (strpos($where, 'heartbeat_at <') !== false) {
                    preg_match('/interval (\d+) second/', $where, $iv);
                    $seconds = isset($iv[1]) ? (int) $iv[1] : 2700;
                    $beat = $row['heartbeat_at'] ?? null;
                    if ($beat !== null && strtotime($beat . ' UTC') > time() - $seconds) {
                        continue;
                    }
                }
                $matched[$id] = $row;
            }

            $changed = 0;
            foreach ($matched as $id => $row) {
                $new = $row;
                foreach ($set as $column => $value) {
                    $new[$column] = $value;
                }
                if ($new !== $row) {
                    $changed++;
                }
                JobStore::$rows[$id] = $new;
            }

            $this->affected = $changed;   // PDO's default: rows CHANGED, not matched
        }

        /** Split "a, b, c" respecting quoted strings. */
        private static function splitValues(string $list): array
        {
            $out = array();
            $buffer = '';
            $quote = false;
            $depth = 0;

            for ($i = 0, $n = strlen($list); $i < $n; $i++) {
                $char = $list[$i];
                if ($char === "'") {
                    $quote = !$quote;
                } elseif (!$quote && $char === '(') {
                    $depth++;
                } elseif (!$quote && $char === ')') {
                    $depth--;
                }

                if ($char === ',' && !$quote && $depth === 0) {
                    $out[] = trim($buffer);
                    $buffer = '';
                    continue;
                }
                $buffer .= $char;
            }

            $out[] = trim($buffer);
            return $out;
        }

        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            if ($this->rows === array()) {
                return false;
            }
            return array_shift($this->rows);
        }

        public function fetchColumn(int $column = 0): mixed
        {
            if ($this->rows === array()) {
                return false;
            }
            $row = array_shift($this->rows);
            $values = array_values($row);
            return isset($values[$column]) ? $values[$column] : false;
        }

        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            $out = $this->rows;
            $this->rows = array();
            return $out;
        }

        public function rowCount(): int
        {
            return $this->affected;
        }

        public function closeCursor(): bool
        {
            return true;
        }

        #[\ReturnTypeWillChange]
        public function setFetchMode(int $mode, mixed ...$args)
        {
            return true;
        }

        public function getIterator(): Iterator
        {
            return new ArrayIterator($this->rows);
        }
    }
}

if (!class_exists('JobsPdo')) {
    class JobsPdo extends PDO
    {
        public $lastId = 0;

        public function __construct()
        {
            // No driver: every method used by pull_jobs.php is overridden.
        }

        public function prepare(string $query, array $options = array()): PDOStatement|false
        {
            return new JobStmt($query);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $stmt = new JobStmt($query);
            $stmt->execute();
            return $stmt;
        }

        public function exec(string $statement): int|false
        {
            $GLOBALS['qa_sql'][] = 'EXEC ' . $statement;
            return 0;
        }

        public function lastInsertId(?string $name = null): string|false
        {
            return (string) (JobStore::$nextId - 1);
        }

        public function beginTransaction(): bool
        {
            return true;
        }

        public function commit(): bool
        {
            return true;
        }

        public function rollBack(): bool
        {
            return true;
        }

        public function inTransaction(): bool
        {
            return false;
        }
    }
}
