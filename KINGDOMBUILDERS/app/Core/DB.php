<?php
declare(strict_types=1);
namespace KB;

final class DB {
    private static ?\PDO $pdo = null;
    public static function connection(): \PDO {
        if (self::$pdo === null) {
            $c = config('db');
            self::$pdo = self::connect($c);
        }
        return self::$pdo;
    }
    public static function connect(array $c): \PDO {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)$c['port'], $c['name']);
        if (!empty($c['socket'])) $dsn = 'mysql:unix_socket=' . $c['socket'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
        return new \PDO($dsn, $c['user'], $c['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
    }
    public static function run(string $sql, array $params = []): \PDOStatement {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    public static function one(string $sql, array $params = []): ?array { return self::run($sql, $params)->fetch() ?: null; }
    public static function all(string $sql, array $params = []): array { return self::run($sql, $params)->fetchAll(); }
    public static function value(string $sql, array $params = []): mixed { return self::run($sql, $params)->fetchColumn(); }
    public static function id(): int { return (int)self::connection()->lastInsertId(); }
    private static int $depth = 0;
    public static function transaction(callable $callback): mixed {
        $pdo = self::connection();
        $nested = $pdo->inTransaction();
        $savepoint = 'kb_sp_' . self::$depth;
        if ($nested) $pdo->exec('SAVEPOINT ' . $savepoint); else $pdo->beginTransaction();
        self::$depth++;
        try {
            $result = $callback();
            if ($nested) $pdo->exec('RELEASE SAVEPOINT ' . $savepoint); else $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { if ($nested) $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint); else $pdo->rollBack(); }
            throw $e;
        } finally { self::$depth--; }
    }
}
