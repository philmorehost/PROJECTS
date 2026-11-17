<?php
declare(strict_types=1);

namespace RISE63\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin PDO wrapper.
 *
 * Notes that matter for this project:
 *  - Emulated prepares are always off, so numeric/boolean binding is handled by the driver.
 *  - Persistent connections are opt-in (RISE63_DB_PERSISTENT=1). On shared hosting a crashed
 *    request can leave a persistent connection mid-transaction, so the default is off and the
 *    trade-off is documented in docs/DEPLOYMENT.md.
 *  - Nested transactions use SAVEPOINTs, because services legitimately call each other.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function connect(array $config): PDO
    {
        $host = (string) ($config['host'] ?? '');
        $name = (string) ($config['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,255}$/', $host) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new RuntimeException('The database host or name is not valid.');
        }
        $port    = (int) ($config['port'] ?? 3306);
        $charset = 'utf8mb4';
        $dsn     = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        if (!empty($config['socket'])) {
            $dsn = 'mysql:unix_socket=' . $config['socket'] . ";dbname={$name};charset={$charset}";
        }
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 8,
        ];
        if (getenv('RISE63_DB_PERSISTENT') === '1') {
            $options[PDO::ATTR_PERSISTENT] = true;
        }
        return new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['password'] ?? ''), $options);
    }

    public static function use(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (!defined('\RISE63\Core\CONFIG_DIR')) {
            throw new RuntimeException('The application configuration directory is unknown.');
        }
        $file = CONFIG_DIR . '/database.php';
        if (!is_file($file)) {
            throw new RuntimeException('The database configuration is missing.');
        }
        $config = require $file;
        self::$pdo = self::connect(is_array($config) ? $config : []);
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo   = null;
        self::$depth = 0;
    }

    /** @param array<string|int, mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** @param array<string|int, mixed> $params */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int, mixed> $params @return list<array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function id(): int
    {
        return (int) self::connection()->lastInsertId();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo    = self::connection();
        $nested = $pdo->inTransaction();
        $name   = 'rise63_sp_' . self::$depth;

        if ($nested) {
            $pdo->exec('SAVEPOINT ' . $name);
        } else {
            $pdo->beginTransaction();
        }
        self::$depth++;

        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                if ($nested) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                } else {
                    $pdo->rollBack();
                }
            }
            throw $error;
        } finally {
            self::$depth--;
        }
    }

    /**
     * Execute a semicolon-separated SQL script.
     * The schema deliberately keeps each statement on its own terminated line so this
     * naive-but-predictable splitter is sufficient; no stored procedure bodies exist.
     *
     * @return int number of statements executed
     */
    public static function runScript(PDO $pdo, string $sql): int
    {
        $count = 0;
        foreach (self::splitStatements($sql) as $statement) {
            $pdo->exec($statement);
            $count++;
        }
        return $count;
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($trimmed, ';')) {
                $sqlStatement = trim($buffer);
                if ($sqlStatement !== '') {
                    $statements[] = $sqlStatement;
                }
                $buffer = '';
            }
        }
        $leftover = trim($buffer);
        if ($leftover !== '') {
            $statements[] = $leftover;
        }
        return $statements;
    }

    public static function isConnectionError(PDOException $error): bool
    {
        return in_array((string) $error->getCode(), ['1044', '1045', '1049', '2002', '2005', '2006'], true);
    }
}
