<?php
/**
 * Minimal in-memory stand-in for mysqli, just enough to exercise EFLEX's
 * order/cancellation logic in a sandbox without a MySQL server. It understands
 * ONLY the queries used by includes/order_stock.php and
 * includes/order_cancellation.php; anything else throws.
 */

class FakeResult
{
    public $rows;
    public $num_rows;

    public function __construct($rows)
    {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc()
    {
        if (empty($this->rows)) {
            return null;
        }
        return array_shift($this->rows);
    }

    public function fetch_row()
    {
        $row = $this->fetch_assoc();
        return $row === null ? null : array_values($row);
    }

    public function fetch_all($mode = MYSQLI_ASSOC)
    {
        $rows = $this->rows;
        $this->rows = array();
        $this->num_rows = 0;
        return $rows;
    }

    public function free()
    {
        $this->rows = array();
        $this->num_rows = 0;
    }
}

class FakeStmt
{
    public $db;
    public $sql;
    public $params = array();
    public $affected_rows = 0;
    public $num_rows = 0;
    private $result = null;
    public $insert_id = 0;

    public function __construct($db, $sql)
    {
        $this->db = $db;
        $this->sql = $sql;
    }

    public function bind_param($types, &...$params)
    {
        $this->params = $params;
        return true;
    }

    public function execute()
    {
        $this->db->execute($this);
        return true;
    }

    public function get_result()
    {
        return $this->result;
    }

    public function store_result()
    {
    }

    public function free_result()
    {
    }

    public function close()
    {
        return true;
    }

    public function set_result($rows)
    {
        $this->result = new FakeResult($rows);
        $this->num_rows = $this->result->num_rows;
    }
}

class FakeMysqli
{
    public $products = array();   // id => ['stock'=>int, 'has_variants'=>int, 'name'=>string]
    public $variants = array();   // id => ['stock'=>int, 'product_id'=>int]
    public $orders = array();     // id => ['user_id','status','stock_restored','created_at','cancelled_at','cancel_reason']
    public $order_items = array(); // id => ['order_id','product_id','variant_id','quantity']
    public $settings = array();
    public $users = array();
    public $has_audit_columns = true;
    public $log = array();
    public $insert_id = 0;
    /** When true, queries the fake does not understand return empty results
     *  (used to render whole pages that touch many unrelated tables). */
    public $permissive = false;

    private $snapshot = null;

    public function __construct()
    {
        $this->settings = array();
    }

    public function real_escape_string($value)
    {
        return str_replace("'", "''", (string)$value);
    }

    public function prepare($sql)
    {
        return new FakeStmt($this, $sql);
    }

    public function query($sql)
    {
        $stmt = new FakeStmt($this, $sql);
        $this->execute($stmt);
        return $stmt->get_result();
    }

    public function begin_transaction()
    {
        $this->snapshot = array(
            'products' => $this->products,
            'variants' => $this->variants,
            'orders' => $this->orders,
            'settings' => $this->settings,
        );
    }

    public function commit()
    {
        $this->snapshot = null;
        return true;
    }

    public function rollback()
    {
        if ($this->snapshot !== null) {
            $this->products = $this->snapshot['products'];
            $this->variants = $this->snapshot['variants'];
            $this->orders = $this->snapshot['orders'];
            $this->settings = $this->snapshot['settings'];
        }
        $this->snapshot = null;
        return true;
    }

    private function log($line)
    {
        $this->log[] = $line;
    }

    public function execute(FakeStmt $stmt)
    {
        $sql = preg_replace('/\s+/', ' ', trim($stmt->sql));
        $p = $stmt->params;
        $this->log($sql . ' ' . json_encode($p));

        // ---- schema introspection -------------------------------------------
        if (preg_match("/^SHOW COLUMNS FROM `orders` LIKE '([a-z_]+)'/i", $sql, $m)) {
            $known = array('stock_restored', 'cancelled_at', 'cancel_reason');
            $exists = in_array($m[1], $known, true) && ($m[1] === 'stock_restored' || $this->has_audit_columns);
            $stmt->set_result($exists ? array(array('Field' => $m[1])) : array());
            return;
        }
        if (strpos($sql, 'SHOW INDEX FROM `orders`') === 0) {
            $stmt->set_result(array(array('Key_name' => 'status_created_at')));
            return;
        }
        if (strpos($sql, 'SELECT GET_LOCK') === 0) {
            $stmt->set_result(array(array('l' => 1)));
            return;
        }
        if (strpos($sql, 'SELECT DATABASE()') === 0) {
            $stmt->set_result(array(array('db' => 'eflex_test')));
            return;
        }

        // ---- settings --------------------------------------------------------
        if (strpos($sql, 'SELECT setting_value FROM settings WHERE setting_key = ?') === 0) {
            $key = $p[0];
            $stmt->set_result(isset($this->settings[$key]) ? array(array('setting_value' => $this->settings[$key])) : array());
            return;
        }
        if (strpos($sql, 'INSERT INTO settings (setting_key, setting_value)') === 0) {
            $this->settings[$p[0]] = $p[1];
            $stmt->affected_rows = 1;
            $stmt->set_result(array());
            return;
        }
        if (strpos($sql, 'SELECT setting_key, setting_value FROM settings') === 0) {
            $rows = array();
            foreach ($this->settings as $k => $v) {
                $rows[] = array('setting_key' => $k, 'setting_value' => $v);
            }
            $stmt->set_result($rows);
            return;
        }

        // ---- users -----------------------------------------------------------
        if (strpos($sql, 'SELECT id, username, email FROM users WHERE id = ?') === 0) {
            $id = (int)$p[0];
            $stmt->set_result(isset($this->users[$id]) ? array($this->users[$id]) : array());
            return;
        }

        // ---- order lookups ---------------------------------------------------
        if (preg_match('/^SELECT id, status, COALESCE\(stock_restored, 0\) AS stock_restored FROM orders WHERE id = \? FOR UPDATE$/', $sql)) {
            $id = (int)$p[0];
            $stmt->set_result(isset($this->orders[$id]) ? array(array(
                'id' => $id,
                'status' => $this->orders[$id]['status'],
                'stock_restored' => $this->orders[$id]['stock_restored'],
            )) : array());
            return;
        }
        if (preg_match('/^SELECT id, COALESCE\(stock_restored, 0\) AS stock_restored FROM orders WHERE id = \? FOR UPDATE$/', $sql)) {
            $id = (int)$p[0];
            $stmt->set_result(isset($this->orders[$id]) ? array(array(
                'id' => $id,
                'stock_restored' => $this->orders[$id]['stock_restored'],
            )) : array());
            return;
        }
        if (preg_match('/^SELECT status FROM orders WHERE id = \? AND user_id = \?$/', $sql)) {
            $id = (int)$p[0];
            $uid = (int)$p[1];
            $stmt->set_result((isset($this->orders[$id]) && (int)$this->orders[$id]['user_id'] === $uid)
                ? array(array('status' => $this->orders[$id]['status'])) : array());
            return;
        }
        if (preg_match('/^SELECT status, COALESCE\(stock_restored, 0\) AS stock_restored FROM orders WHERE id = \?$/', $sql)) {
            $id = (int)$p[0];
            $stmt->set_result(isset($this->orders[$id]) ? array(array(
                'status' => $this->orders[$id]['status'],
                'stock_restored' => $this->orders[$id]['stock_restored'],
            )) : array());
            return;
        }

        // ---- order + customer joins (admin order detail) ----------------------
        if (strpos($sql, 'SELECT o.status, u.email, u.username FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?') === 0) {
            $id = (int)$p[0];
            $row = isset($this->orders[$id]) ? $this->orders[$id] : null;
            $user = $row ? ($this->users[$row['user_id']] ?? null) : null;
            $stmt->set_result($row ? array(array(
                'status' => $row['status'],
                'email' => $user['email'] ?? '',
                'username' => $user['username'] ?? '',
            )) : array());
            return;
        }
        if (strpos($sql, 'SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?') === 0) {
            $id = (int)$p[0];
            $row = isset($this->orders[$id]) ? $this->orders[$id] : null;
            $user = $row ? ($this->users[$row['user_id']] ?? null) : null;
            $out = $row ? array_merge($row, array(
                'username' => $user['username'] ?? '',
                'email' => $user['email'] ?? '',
                'payment_method' => 'bank_transfer',
                'payment_proof' => null,
                'order_notes' => null,
            )) : array();
            $stmt->set_result($out ? array($out) : array());
            return;
        }
        if (preg_match('/^UPDATE orders SET status = \? WHERE id = \?$/', $sql)) {
            $status = $p[0];
            $id = (int)$p[1];
            $stmt->affected_rows = 0;
            if (isset($this->orders[$id])) {
                $this->orders[$id]['status'] = $status;
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }
        if (preg_match('/^UPDATE orders SET payment_proof = NULL WHERE id = \?$/', $sql)) {
            $stmt->affected_rows = 1;
            $stmt->set_result(array());
            return;
        }

        // ---- order items -----------------------------------------------------
        if (strpos($sql, 'FROM order_items oi JOIN products p ON p.id = oi.product_id') !== false) {
            $order_id = (int)$p[0];
            $rows = array();
            foreach ($this->order_items as $item) {
                if ((int)$item['order_id'] !== $order_id) {
                    continue;
                }
                $product = $this->products[$item['product_id']] ?? array('name' => 'missing', 'has_variants' => 0);
                $rows[] = array(
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                    'product_name' => $product['name'],
                    'has_variants' => $product['has_variants'],
                    'variant_exists' => empty($item['variant_id']) ? null : (isset($this->variants[$item['variant_id']]) ? 1 : null),
                );
            }
            $stmt->set_result($rows);
            return;
        }

        // ---- stock updates ---------------------------------------------------
        if (preg_match('/^UPDATE product_variants SET stock = COALESCE\(stock, 0\) \+ \? WHERE id = \?$/', $sql)) {
            $qty = (int)$p[0];
            $id = (int)$p[1];
            $stmt->affected_rows = 0;
            if (isset($this->variants[$id])) {
                $this->variants[$id]['stock'] += $qty;
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }
        if (preg_match('/^UPDATE products SET stock = COALESCE\(stock, 0\) \+ \? WHERE id = \? AND has_variants = 0$/', $sql)) {
            $qty = (int)$p[0];
            $id = (int)$p[1];
            $stmt->affected_rows = 0;
            if (isset($this->products[$id]) && (int)$this->products[$id]['has_variants'] === 0) {
                $this->products[$id]['stock'] += $qty;
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }
        if (preg_match('/^UPDATE product_variants SET stock = COALESCE\(stock, 0\) - \? WHERE id = \? AND COALESCE\(stock, 0\) >= \?$/', $sql)) {
            $qty = (int)$p[0];
            $id = (int)$p[1];
            $stmt->affected_rows = 0;
            if (isset($this->variants[$id]) && $this->variants[$id]['stock'] >= $qty) {
                $this->variants[$id]['stock'] -= $qty;
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }
        if (preg_match('/^UPDATE products SET stock = COALESCE\(stock, 0\) - \? WHERE id = \? AND has_variants = 0 AND COALESCE\(stock, 0\) >= \?$/', $sql)) {
            $qty = (int)$p[0];
            $id = (int)$p[1];
            $stmt->affected_rows = 0;
            if (isset($this->products[$id]) && (int)$this->products[$id]['has_variants'] === 0 && $this->products[$id]['stock'] >= $qty) {
                $this->products[$id]['stock'] -= $qty;
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }
        if (preg_match('/^SELECT has_variants FROM products WHERE id = \?$/', $sql)) {
            $id = (int)$p[0];
            $stmt->set_result(isset($this->products[$id]) ? array(array('has_variants' => $this->products[$id]['has_variants'])) : array());
            return;
        }

        // ---- order updates ---------------------------------------------------
        if (preg_match('/^UPDATE orders SET stock_restored = 1 WHERE id = \? AND COALESCE\(stock_restored, 0\) = 0$/', $sql)) {
            $id = (int)$p[0];
            $stmt->affected_rows = 0;
            if (isset($this->orders[$id]) && (int)$this->orders[$id]['stock_restored'] === 0) {
                $this->orders[$id]['stock_restored'] = 1;
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }
        if (preg_match('/^UPDATE orders SET stock_restored = 0(, cancelled_at = NULL, cancel_reason = NULL)? WHERE id = \? AND COALESCE\(stock_restored, 0\) = 1$/', $sql, $m)) {
            $id = (int)$p[0];
            $stmt->affected_rows = 0;
            if (isset($this->orders[$id]) && (int)$this->orders[$id]['stock_restored'] === 1) {
                $this->orders[$id]['stock_restored'] = 0;
                if ($this->has_audit_columns) {
                    $this->orders[$id]['cancelled_at'] = null;
                    $this->orders[$id]['cancel_reason'] = null;
                }
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }
        if (preg_match("/^UPDATE orders SET status = 'Cancelled', stock_restored = 1(, cancelled_at = NOW\(\), cancel_reason = \?)? WHERE id = \? AND \(status IN \((.*)\)\)$/", $sql, $m)) {
            $offset = 0;
            $reason = null;
            if (!empty($m[1])) {
                $reason = $p[$offset];
                $offset++;
            }
            $id = (int)$p[$offset];
            $offset++;
            $allowed = array_slice($p, $offset);
            $stmt->affected_rows = 0;
            if (isset($this->orders[$id]) && in_array($this->orders[$id]['status'], $allowed, true)) {
                $this->orders[$id]['status'] = 'Cancelled';
                $this->orders[$id]['stock_restored'] = 1;
                if ($this->has_audit_columns && $reason !== null) {
                    $this->orders[$id]['cancelled_at'] = date('Y-m-d H:i:s');
                    $this->orders[$id]['cancel_reason'] = $reason;
                }
                $stmt->affected_rows = 1;
            }
            $stmt->set_result(array());
            return;
        }

        // ---- automatic cancellation ------------------------------------------
        if (preg_match('/^SELECT id FROM orders WHERE status IN \([?,]+\) ORDER BY created_at ASC$/', $sql)) {
            $statuses = $p;
            $rows = array();
            foreach ($this->orders as $id => $order) {
                if (in_array($order['status'], $statuses, true)) {
                    $rows[] = array('id' => $id);
                }
            }
            $stmt->set_result($rows);
            return;
        }
        if (strpos($sql, 'FROM orders WHERE status IN (') !== false && strpos($sql, 'ORDER BY created_at ASC LIMIT ?') !== false) {
            $params = $p;
            $limit = (int)array_pop($params);
            $hours = (int)array_pop($params);
            $statuses = $params;
            $cutoff = time() - ($hours * 3600);
            $rows = array();
            foreach ($this->orders as $id => $order) {
                if (!in_array($order['status'], $statuses, true)) {
                    continue;
                }
                if (empty($order['created_at']) || strtotime($order['created_at']) >= $cutoff) {
                    continue;
                }
                $rows[] = array('id' => $id, 'user_id' => $order['user_id']);
            }
            $stmt->set_result(array_slice($rows, 0, $limit));
            return;
        }
        if (preg_match('/^SELECT COUNT\(\*\) FROM orders WHERE status IN \(([?,]+)\) AND created_at IS NOT NULL AND created_at < DATE_SUB\(NOW\(\), INTERVAL \? HOUR\)$/', $sql, $m)) {
            $params = $p;
            $hours = (int)array_pop($params);
            $statuses = $params;
            $cutoff = time() - ($hours * 3600);
            $count = 0;
            foreach ($this->orders as $order) {
                if (in_array($order['status'], $statuses, true) && !empty($order['created_at']) && strtotime($order['created_at']) < $cutoff) {
                    $count++;
                }
            }
            $stmt->set_result(array(array('COUNT(*)' => $count)));
            return;
        }

        if ($this->permissive) {
            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)/i', $sql)) {
                $stmt->affected_rows = 1;
                $stmt->set_result(array());
            } elseif (preg_match('/^(SHOW|DESCRIBE|EXPLAIN)/i', $sql)) {
                $stmt->set_result(array());
            } else {
                $stmt->set_result(array());
            }
            return;
        }

        throw new RuntimeException('FakeMysqli cannot handle: ' . $sql);
    }
}
