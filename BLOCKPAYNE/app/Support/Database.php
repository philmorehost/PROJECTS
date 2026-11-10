<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    /**
     * Inject an existing PDO connection (used by unit/e2e tests and preview runtime).
     */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = strtolower((string) Env::get('DB_CONNECTION', 'mysql'));
        if ($driver === 'pglite' || $driver === 'pgsql') {
            $dbName = Env::get('DB_DATABASE', 'memory://') ?: 'memory://';
            $dsn = $driver === 'pglite' ? ('pgsql:' . $dbName) : sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::get('DB_PORT', '5432'),
                $dbName
            );
            $pdo = new PDO($dsn, Env::get('DB_USERNAME', 'postgres') ?: 'postgres', Env::get('DB_PASSWORD', 'postgres') ?: 'postgres', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo = $pdo;
            return $pdo;
        }

        $host = (string) Env::get('DB_HOST', '127.0.0.1');
        $port = (string) Env::get('DB_PORT', '3306');
        $db   = (string) Env::get('DB_DATABASE', '');
        $user = (string) Env::get('DB_USERNAME', '');
        $pass = (string) Env::get('DB_PASSWORD', '');

        if ($db === '') {
            throw new RuntimeException('Database is not configured yet.');
        }

        $persistent = Env::get('DB_PERSISTENT', 'false') === 'true';
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => $persistent,
        ]);

        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * Test explicit database credentials during Stage 2 of the installer.
     *
     * @param array{driver?:string,host?:string,port?:string,database:string,username?:string,password?:string} $cfg
     */
    public static function testCredentials(array $cfg): PDO
    {
        $driver = strtolower((string) ($cfg['driver'] ?? 'mysql'));
        if ($driver === 'pglite') {
            $pdo = new PDO('pgsql:' . ($cfg['database'] ?: 'memory://'), 'postgres', 'postgres', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['port'] ?? '3306',
            $cfg['database']
        );
        return new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }

    public static function driver(?PDO $pdo = null): string
    {
        $conn = $pdo ?? self::connection();
        return strtolower((string) $conn->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public static function isPostgres(?PDO $pdo = null): bool
    {
        $d = self::driver($pdo);
        return $d === 'pgsql' || $d === 'pglite';
    }

    /**
     * Portable boolean bind value for MySQL TINYINT(1) and PostgreSQL BOOLEAN columns.
     */
    public static function boolVal(bool $value, ?PDO $pdo = null): int|string
    {
        return self::isPostgres($pdo) ? ($value ? 'true' : 'false') : ($value ? 1 : 0);
    }

    /**
     * Portable upsert across MySQL 8+ and PostgreSQL / PGlite.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $conflictColumns
     * @param list<string>         $updateColumns
     */
    public static function upsert(string $table, array $data, array $conflictColumns, array $updateColumns, ?PDO $pdo = null): void
    {
        $conn = $pdo ?? self::connection();
        $cols = array_keys($data);
        $placeholders = array_fill(0, count($cols), '?');
        $values = array_values($data);

        if (self::isPostgres($conn)) {
            $updates = [];
            foreach ($updateColumns as $col) {
                $updates[] = sprintf('%s = EXCLUDED.%s', $col, $col);
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
                $table,
                implode(', ', $cols),
                implode(', ', $placeholders),
                implode(', ', $conflictColumns),
                implode(', ', $updates)
            );
        } else {
            $updates = [];
            foreach ($updateColumns as $col) {
                $updates[] = sprintf('`%s` = VALUES(`%s`)', $col, $col);
            }
            $quotedCols = array_map(static fn(string $c): string => '`' . $c . '`', $cols);
            $sql = sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(', ', $quotedCols),
                implode(', ', $placeholders),
                implode(', ', $updates)
            );
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * Run database/schema.sql and database/countries.sql idempotently.
     */
    public static function migrate(?PDO $pdo = null): void
    {
        $conn = $pdo ?? self::connection();
        $isPg = self::isPostgres($conn);

        if ($isPg) {
            self::migratePostgres($conn);
        } else {
            $schemaSql = (string) file_get_contents(BP_ROOT . '/database/schema.sql');
            foreach (self::splitStatements($schemaSql) as $stmt) {
                $conn->exec($stmt);
            }
            try {
                $conn->exec('ALTER TABLE `invoices` ADD COLUMN `metadata` TEXT NULL AFTER `redirect_url`');
            } catch (\Throwable) {
                // Column already exists
            }
        }

        // Seed ISO countries if empty
        $count = (int) $conn->query('SELECT COUNT(*) FROM country_firewall')->fetchColumn();
        if ($count === 0) {
            $countriesSql = (string) file_get_contents(BP_ROOT . '/database/countries.sql');
            foreach (self::splitStatements($countriesSql) as $stmt) {
                $conn->exec($stmt);
            }
        }
    }

    /**
     * Equivalent DDL for PGlite / PostgreSQL test & preview environments.
     */
    private static function migratePostgres(PDO $conn): void
    {
        $ddls = [
            "CREATE TABLE IF NOT EXISTS settings (
                id SERIAL PRIMARY KEY,
                key_name VARCHAR(191) UNIQUE NOT NULL,
                key_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS users (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(191) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(20) DEFAULT 'merchant' CHECK (role IN ('admin', 'merchant')),
                status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'locked')),
                enable_login_notifications BOOLEAN DEFAULT TRUE,
                failed_login_attempts INT DEFAULT 0,
                last_failed_login TIMESTAMP NULL,
                remember_token VARCHAR(100) NULL,
                api_key VARCHAR(120) UNIQUE NULL,
                webhook_secret VARCHAR(120) NULL,
                callback_url TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS merchant_wallets (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                coin_symbol VARCHAR(20) NOT NULL,
                wallet_label VARCHAR(100) DEFAULT 'Main Wallet',
                derivation_mode VARCHAR(20) DEFAULT 'xpub' CHECK (derivation_mode IN ('xpub', 'address', 'blockbee')),
                xpub_key TEXT NOT NULL,
                derived_index INT DEFAULT 0,
                status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS merchant_balances (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                currency VARCHAR(20) DEFAULT 'USD',
                balance DECIMAL(18,8) DEFAULT 0.00000000,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, currency)
            )",
            "CREATE TABLE IF NOT EXISTS transactions (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type VARCHAR(30) NOT NULL CHECK (type IN ('prefund_deposit', 'fee_debit', 'withdrawal')),
                amount DECIMAL(18,8) NOT NULL,
                currency VARCHAR(20) NOT NULL,
                tx_hash VARCHAR(191) NULL,
                gateway VARCHAR(40) DEFAULT 'internal',
                description VARCHAR(255) NULL,
                status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'failed')),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS invoices (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                wallet_id BIGINT NULL,
                invoice_id VARCHAR(100) UNIQUE NOT NULL,
                order_id VARCHAR(100) NULL,
                title VARCHAR(191) NULL,
                buyer_email VARCHAR(191) NULL,
                crypto_amount DECIMAL(18,8) NOT NULL,
                received_amount DECIMAL(18,8) DEFAULT 0.00000000,
                fiat_amount DECIMAL(18,2) NOT NULL,
                fiat_currency VARCHAR(10) DEFAULT 'USD',
                currency VARCHAR(20) NOT NULL,
                deposit_address VARCHAR(255) NOT NULL,
                derivation_index INT DEFAULT 0,
                tx_hash VARCHAR(191) NULL,
                is_prefund BOOLEAN DEFAULT FALSE,
                fee_deducted BOOLEAN DEFAULT FALSE,
                status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'detected', 'confirmed', 'expired')),
                confirmations INT DEFAULT 0,
                callback_url TEXT NULL,
                redirect_url TEXT NULL,
                metadata TEXT NULL,
                expires_at TIMESTAMP NULL,
                confirmed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS payment_links (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                slug VARCHAR(64) UNIQUE NOT NULL,
                title VARCHAR(191) NOT NULL,
                description VARCHAR(255) NULL,
                fiat_amount DECIMAL(18,2) DEFAULT 0.00,
                fiat_currency VARCHAR(10) DEFAULT 'USD',
                allow_custom_amount BOOLEAN DEFAULT FALSE,
                accepted_coins VARCHAR(191) DEFAULT 'BTC,LTC,BCH,ETH,USDT-TRC20',
                redirect_url TEXT NULL,
                status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS security_logs (
                id BIGSERIAL PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                username_attempted VARCHAR(255) NULL,
                event_type VARCHAR(40) NOT NULL CHECK (event_type IN ('failed_login', 'account_lock', 'ip_block', 'whitelisted_success', 'admin_alert')),
                details TEXT NULL,
                country_code VARCHAR(5) NULL,
                is_whitelisted BOOLEAN DEFAULT FALSE,
                success_session_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS ip_firewall (
                id BIGSERIAL PRIMARY KEY,
                ip_address VARCHAR(45) UNIQUE NOT NULL,
                rule_type VARCHAR(20) DEFAULT 'tracker' CHECK (rule_type IN ('block', 'whitelist', 'tracker')),
                duration_label VARCHAR(20) DEFAULT '1_day',
                clean_sessions INT DEFAULT 0,
                last_session_token VARCHAR(128) NULL,
                reason VARCHAR(255) NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS country_firewall (
                id SERIAL PRIMARY KEY,
                country_code VARCHAR(5) UNIQUE NOT NULL,
                country_name VARCHAR(100) NOT NULL,
                action VARCHAR(20) DEFAULT 'Not Specified' CHECK (action IN ('Whitelisted', 'Not Specified', 'Blacklisted')),
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS notification_logs (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NULL,
                recipient_email VARCHAR(191) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                event_type VARCHAR(50) DEFAULT 'security',
                status VARCHAR(20) DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'failed')),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
        ];
        foreach ($ddls as $sql) {
            $conn->exec($sql);
        }
    }

    /**
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $clean = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = explode(';', $clean);
        $out = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }
}
