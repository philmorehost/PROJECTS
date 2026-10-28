<?php
/**
 * A PDO stand-in for the QA harness.
 *
 * php-wasm has no MySQL driver and the only bundled driver is pgsql with no
 * server, so tests that touch the database build one of these instead. It is a
 * real PDO subclass (the app type-hints PDO) whose statements are subclasses of
 * PDOStatement, so `foreach ($stmt as $row)` and `fetchAll(PDO::FETCH_ASSOC)`
 * behave the way the production code expects.
 *
 * It does not parse SQL. It recognises the statements this panel sends and
 * answers from a fixture, which is enough to test control flow — and it records
 * every statement in $GLOBALS['qa_sql'] so a test can assert what ran.
 */

if (!class_exists('QAStmt')) {
    class QAStmt extends PDOStatement
    {
        public $sql;
        public $rows = array();
        public $affected = 0;
        public $args = array();
        public $fetchMode = PDO::FETCH_BOTH;

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
            $this->resolve();
            if (!isset($GLOBALS['qa_sql'])) {
                $GLOBALS['qa_sql'] = array();
            }
            $GLOBALS['qa_sql'][] = $this->sql . ($this->args ? ' -- ' . json_encode($this->args) : '');

            // php-wasm does not run shutdown handlers after exit(), and every
            // POST handler in the panel ends with `header(Location) + exit`.
            // Recording to a file as it happens is the only way a test can see
            // what a request actually wrote.
            if (!empty($GLOBALS['qa_sql_file'])) {
                @file_put_contents(
                    $GLOBALS['qa_sql_file'],
                    $this->sql . ($this->args ? ' -- ' . json_encode($this->args) : '') . "\n",
                    FILE_APPEND
                );
            }
            return true;
        }

        private function resolve(): void
        {
            $q = $this->q();
            $counts = isset($GLOBALS['qa_counts']) ? (array) $GLOBALS['qa_counts'] : array();
            $tiers = isset($GLOBALS['qa_tiers']) ? (array) $GLOBALS['qa_tiers'] : array();
            $updates = isset($GLOBALS['qa_updates']) ? (array) $GLOBALS['qa_updates'] : array();
            $n = function ($key) use ($counts) {
                return isset($counts[$key]) ? (int) $counts[$key] : 0;
            };

            /* ---- reads -------------------------------------------------- */

            if (strpos($q, 'select * from script_tiers where id =') !== false) {
                $id = isset($this->args[0]) ? (string) $this->args[0] : '';
                foreach ($tiers as $tier) {
                    if ((string) $tier['id'] === $id) {
                        $this->rows = array($tier);
                        return;
                    }
                }
                $this->rows = array();
                return;
            }

            if (preg_match('/select .* from script_tiers( where id <>| where id !=)/', $q)) {
                $skip = isset($this->args[0]) ? (string) $this->args[0] : '';
                $out = array();
                foreach ($tiers as $tier) {
                    if ((string) $tier['id'] === $skip) {
                        continue;
                    }
                    $out[] = array('id' => (string) $tier['id'], 'tier_name' => $tier['tier_name'], 'tier_code' => $tier['tier_code']);
                }
                $this->rows = $out;
                return;
            }

            if (strpos($q, 'select id, tier_name, tier_code from script_tiers') !== false) {
                $out = array();
                foreach ($tiers as $tier) {
                    $out[] = array('id' => (string) $tier['id'], 'tier_name' => $tier['tier_name'], 'tier_code' => $tier['tier_code']);
                }
                $this->rows = $out;
                return;
            }

            if (strpos($q, 'select id, tier_name from script_tiers') !== false) {
                $id = isset($this->args[0]) ? (string) $this->args[0] : '';
                $this->rows = array();
                foreach ($tiers as $tier) {
                    if ((string) $tier['id'] === $id) {
                        $this->rows = array(array('id' => (string) $tier['id'], 'tier_name' => $tier['tier_name']));
                    }
                }
                return;
            }

            if (strpos($q, 'select * from script_tiers') !== false) {
                $this->rows = array_values($tiers);
                return;
            }

            if (strpos($q, 'select tier_id, count(*) as total from licenses') !== false) {
                $this->rows = array(array('tier_id' => '1', 'total' => (string) $n('licenses')));
                return;
            }

            if (strpos($q, 'select tier_id, count(*) as total from script_updates') !== false) {
                $this->rows = array(array('tier_id' => '1', 'total' => (string) $n('script_updates')));
                return;
            }

            if (strpos($q, 'select * from script_updates') !== false) {
                $tierId = isset($this->args[0]) ? (string) $this->args[0] : '';
                $out = array();
                foreach ($updates as $update) {
                    if ((string) ($update['tier_id'] ?? '') === $tierId) {
                        $out[] = $update;
                    }
                }
                $this->rows = $out;
                return;
            }

            // Column probes (lm_db_has_column). Default: the column exists, so a
            // test can render the real page; $GLOBALS['qa_columns'] narrows it.
            if (strpos($q, 'information_schema.columns') !== false) {
                $table = isset($this->args[0]) ? (string) $this->args[0] : '';
                $column = isset($this->args[1]) ? (string) $this->args[1] : '';
                $known = isset($GLOBALS['qa_columns']) ? (array) $GLOBALS['qa_columns'] : null;
                $exists = $known === null || in_array($table . '.' . $column, $known, true);
                $this->rows = array(array($exists ? '1' : '0'));
                return;
            }

            // Licences listed on admin/licenses.php (the per-licence update
            // switches live here, so a test needs real rows to look at).
            if (strpos($q, 'select * from licenses') !== false) {
                if (isset($GLOBALS['qa_licences'])) {
                    $this->rows = array_values((array) $GLOBALS['qa_licences']);
                    return;
                }
                $out = array();
                for ($i = 1; $i <= $n('licenses'); $i++) {
                    $out[] = array(
                        'id' => (string) $i,
                        'license_key' => 'QA-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                        'domain' => 'site' . $i . '.example.com',
                        'customer_email' => 'owner' . $i . '@example.com',
                        'status' => 'active',
                        'license_type' => 'standard',
                        'tier_id' => '1',
                        'updates_enabled' => '1',
                        'created_at' => '2026-10-0' . min(9, $i) . ' 09:00:00',
                    );
                }
                $this->rows = $out;
                return;
            }

            if (strpos($q, 'select t.id, t.tier_name, t.tier_code') !== false) {
                // The project-wide bulk control: one row per project with the
                // number of licences and how many of them are on hold.
                $out = array();
                foreach ($tiers as $tier) {
                    $total = 0;
                    $held = 0;
                    foreach (isset($GLOBALS['qa_licences']) ? (array) $GLOBALS['qa_licences'] : array() as $lic) {
                        if ((string) ($lic['tier_id'] ?? '') === (string) $tier['id']) {
                            $total++;
                            if (isset($lic['updates_enabled']) && (int) $lic['updates_enabled'] === 0) {
                                $held++;
                            }
                        }
                    }
                    $out[] = array(
                        'id' => (string) $tier['id'],
                        'tier_name' => (string) $tier['tier_name'],
                        'tier_code' => (string) ($tier['tier_code'] ?? ''),
                        'licences' => (string) $total,
                        'on_hold' => (string) $held,
                    );
                }
                $this->rows = $out;
                return;
            }

            if (strpos($q, 'from license_requests') !== false) {
                $this->rows = isset($GLOBALS['qa_requests']) ? array_values((array) $GLOBALS['qa_requests']) : array();
                return;
            }

            if (strpos($q, 'select id from licenses') !== false) {
                $out = array();
                for ($i = 1; $i <= $n('licenses'); $i++) {
                    $out[] = array((string) $i);
                }
                $this->rows = $out;
                return;
            }

            foreach (array('licenses', 'script_updates', 'github_pulls', 'github_pull_jobs', 'update_notifications') as $table) {
                if (strpos($q, 'count(*) from ' . $table) !== false) {
                    $key = $table;
                    if ($table === 'github_pull_jobs' && strpos($q, 'queued') !== false) {
                        $key = 'jobs_running';
                    }
                    $this->rows = array(array((string) $n($key)));
                    return;
                }
            }

            /* ---- writes ------------------------------------------------- */

            if (strpos($q, 'update licenses set tier_id') !== false) {
                $this->affected = $n('licenses');
                return;
            }

            $table = '';
            foreach (array('licensed_domains', 'download_tokens', 'update_notifications', 'script_updates', 'github_pulls', 'github_pull_jobs', 'licenses', 'script_tiers') as $candidate) {
                if (strpos($q, 'delete from ' . $candidate) !== false) {
                    $table = $candidate;
                    break;
                }
            }

            if ($table !== '') {
                $map = array(
                    'licensed_domains' => 0,
                    'download_tokens' => 0,
                    'update_notifications' => $n('update_notifications'),
                    'script_updates' => $n('script_updates'),
                    'github_pulls' => $n('github_pulls'),
                    'github_pull_jobs' => $n('github_pull_jobs'),
                    'licenses' => $n('licenses'),
                    'script_tiers' => 1,
                );
                $this->affected = $map[$table];
                return;
            }

            if (strpos($q, 'update script_tiers') !== false) {
                $this->affected = 1;
                return;
            }

            $this->rows = array();
        }

        private function typed(array $row): array
        {
            return $row;
        }

        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            if ($this->rows === array()) {
                return false;
            }
            if ($mode !== PDO::FETCH_DEFAULT) {
                $this->fetchMode = $mode;
            }
            return $this->format(array_shift($this->rows));
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
            if ($mode === PDO::FETCH_COLUMN) {
                $out = array();
                foreach ($this->rows as $row) {
                    $values = array_values($row);
                    $out[] = isset($values[0]) ? $values[0] : null;
                }
                return $out;
            }

            $assoc = ($mode === PDO::FETCH_ASSOC)
                || ($mode === PDO::FETCH_DEFAULT && $this->fetchMode === PDO::FETCH_ASSOC);

            $out = array();
            foreach ($this->rows as $row) {
                $out[] = $assoc ? $row : $this->format($row, true);
            }
            return $out;
        }

        public function fetchObject(?string $class = 'stdClass', array $constructorArgs = array()): object|false
        {
            $row = $this->fetch(PDO::FETCH_ASSOC);
            return $row === false ? false : (object) $row;
        }

        public function rowCount(): int
        {
            return $this->affected;
        }

        public function columnCount(): int
        {
            return $this->rows === array() ? 0 : count((array) $this->rows[0]);
        }

        #[\ReturnTypeWillChange]
        public function setFetchMode(int $mode, mixed ...$args)
        {
            $this->fetchMode = $mode;
            return true;
        }

        public function closeCursor(): bool
        {
            return true;
        }

        public function getIterator(): Iterator
        {
            return new ArrayIterator($this->fetchAll(PDO::FETCH_ASSOC));
        }

        private function format(array $row, bool $both = false): array
        {
            if (!$both && $this->fetchMode !== PDO::FETCH_BOTH) {
                return $row;
            }
            $out = array();
            foreach ($row as $key => $value) {
                $out[$key] = $value;
                if (is_int($key)) {
                    $out[$key] = $value;
                }
            }
            return $out;
        }
    }
}

if (!class_exists('QAPdo')) {
    class QAPdo extends PDO
    {
        public $inTx = false;

        public function __construct()
        {
            // No driver: every method used by the app is overridden below.
        }

        public function prepare(string $query, array $options = array()): PDOStatement|false
        {
            return new QAStmt($query);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $stmt = new QAStmt($query);
            if ($fetchMode !== null) {
                $stmt->setFetchMode($fetchMode);
            }
            $stmt->execute();
            return $stmt;
        }

        public function exec(string $statement): int|false
        {
            if (!isset($GLOBALS['qa_sql'])) {
                $GLOBALS['qa_sql'] = array();
            }
            $GLOBALS['qa_sql'][] = 'EXEC ' . $statement;
            return 0;
        }

        public function beginTransaction(): bool
        {
            $this->inTx = true;
            return true;
        }

        public function commit(): bool
        {
            $this->inTx = false;
            return true;
        }

        public function rollBack(): bool
        {
            $this->inTx = false;
            return true;
        }

        public function inTransaction(): bool
        {
            return $this->inTx;
        }

        public function lastInsertId(?string $name = null): string|false
        {
            return '0';
        }
    }
}
