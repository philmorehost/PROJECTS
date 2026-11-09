<?php
declare(strict_types=1);

/**
 * A PostgreSQL rendering of the tables `tests/e2e.php` needs.
 *
 * Why this exists: the application targets MySQL, and the only database reachable in the build
 * environment is PGlite — an in-memory PostgreSQL compiled into php-wasm. Writing the schema out
 * twice by hand would drift within a week, so the tables below are transcribed from
 * database/schema.sql with the *type* translations PostgreSQL needs and nothing else. Column names,
 * nullability, defaults and foreign-key behaviour are kept identical, because those are what the
 * tests assert against.
 *
 * What is deliberately not translated:
 *  - ENUM columns become CHECK constraints (PostgreSQL has no inline ENUM without a CREATE TYPE).
 *  - `ON UPDATE CURRENT_TIMESTAMP` has no PostgreSQL equivalent; the application never relies on
 *    it (it sets timestamps explicitly), and a trigger to fake it would test the trigger.
 *  - The append-only triggers in database/ledger-guards.sql are not created, since these tests
 *    write ledger rows directly to set up a scenario.
 *
 * Known limit: MySQL's `INSERT … ON DUPLICATE KEY UPDATE` (used by Settings::set, Auth::rememberDevice
 * and BruteForce) is not valid PostgreSQL, so any end-to-end test that saves a setting is skipped
 * with a message rather than silently passing. Making that portable is a separate change; pretending
 * it is covered here would be worse than saying so.
 *
 * If a column is added to schema.sql, add it here too.
 */

/**
 * @param \PDO $pdo
 */
function pgsql_create_schema(\PDO $pdo): void
{
    // Order matters: a table is created after everything it references. `users` points at
    // `parishes`, so it comes after the parish hierarchy.
    $statements = [
        'CREATE TABLE zones (
            id SERIAL PRIMARY KEY,
            zone_name VARCHAR(150) NOT NULL UNIQUE,
            zone_code VARCHAR(20) NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE areas (
            id SERIAL PRIMARY KEY,
            zone_id INT NOT NULL REFERENCES zones (id) ON DELETE CASCADE,
            area_name VARCHAR(150) NOT NULL,
            area_code VARCHAR(20) NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (zone_id, area_name)
        )',

        'CREATE TABLE parishes (
            id SERIAL PRIMARY KEY,
            area_id INT NOT NULL REFERENCES areas (id) ON DELETE CASCADE,
            parish_name VARCHAR(150) NOT NULL,
            parish_code VARCHAR(20) NULL UNIQUE,
            worship_address VARCHAR(255) NULL,
            yaya_rep_name VARCHAR(150) NULL,
            yaya_rep_phone VARCHAR(30) NULL,
            youth_count INT NOT NULL DEFAULT 0,
            needs_summary TEXT NULL,
            is_active SMALLINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (area_id, parish_name)
        )',

        'CREATE TABLE users (
            id SERIAL PRIMARY KEY,
            parish_id INT NULL REFERENCES parishes (id) ON DELETE SET NULL,
            parish_name_raw VARCHAR(150) NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            phone VARCHAR(30) NULL,
            password_hash VARCHAR(255) NOT NULL,
            must_change_password SMALLINT NOT NULL DEFAULT 0,
            role VARCHAR(30) NOT NULL DEFAULT \'youth_member\'
                CHECK (role IN (\'provincial_admin\',\'provincial_treasurer\',\'csr_rep\',\'youth_member\')),
            transaction_pin_hash VARCHAR(255) NULL,
            is_suspended SMALLINT NOT NULL DEFAULT 0,
            login_notifications_enabled SMALLINT NOT NULL DEFAULT 1,
            successful_sessions_count INT NOT NULL DEFAULT 0,
            theme_preference VARCHAR(10) NOT NULL DEFAULT \'system\',
            large_text SMALLINT NOT NULL DEFAULT 0,
            last_login_at TIMESTAMP NULL,
            password_changed_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE program_applications (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            parish_id INT NULL REFERENCES parishes (id) ON DELETE SET NULL,
            program_type VARCHAR(30) NOT NULL
                CHECK (program_type IN (\'academy\',\'seed_grant\',\'cooperative\',\'education_bridge\')),
            cycle_year SMALLINT NOT NULL,
            education_level VARCHAR(20) NOT NULL DEFAULT \'none\',
            school_name VARCHAR(200) NULL,
            school_class VARCHAR(50) NULL,
            requested_amount NUMERIC(15,2) NOT NULL DEFAULT 0.00,
            approved_amount NUMERIC(15,2) NOT NULL DEFAULT 0.00,
            documents_json TEXT NULL,
            answers_json TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'submitted\',
            decision_note TEXT NULL,
            idempotency_key VARCHAR(64) NULL,
            submitted_at TIMESTAMP NULL,
            verified_at TIMESTAMP NULL,
            approved_at TIMESTAMP NULL,
            disbursed_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE remittances (
            id SERIAL PRIMARY KEY,
            application_id INT NOT NULL REFERENCES program_applications (id) ON DELETE RESTRICT,
            amount NUMERIC(15,2) NOT NULL CHECK (amount > 0),
            payee_name VARCHAR(150) NOT NULL,
            bank_name VARCHAR(150) NOT NULL,
            account_number VARCHAR(34) NOT NULL,
            payment_reference VARCHAR(100) NOT NULL UNIQUE,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            requested_by INT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
            confirmed_by INT NULL REFERENCES users (id) ON DELETE SET NULL,
            confirmed_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE remittance_ledger (
            id BIGSERIAL PRIMARY KEY,
            entry_type VARCHAR(20) NOT NULL
                CHECK (entry_type IN (\'disbursement\',\'reversal\',\'pool_deposit\',\'adjustment\')),
            program_type VARCHAR(30) NULL,
            application_id INT NULL REFERENCES program_applications (id) ON DELETE SET NULL,
            amount NUMERIC(15,2) NOT NULL,
            balance_after NUMERIC(15,2) NULL,
            reference VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            created_by INT NULL REFERENCES users (id) ON DELETE SET NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE audit_events (
            id BIGSERIAL PRIMARY KEY,
            actor_id INT NULL REFERENCES users (id) ON DELETE SET NULL,
            actor_email VARCHAR(150) NULL,
            action VARCHAR(60) NOT NULL,
            entity VARCHAR(60) NOT NULL,
            entity_id VARCHAR(60) NULL,
            summary VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE security_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE notification_outbox (
            id BIGSERIAL PRIMARY KEY,
            recipient VARCHAR(150) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            body_html TEXT NOT NULL,
            body_text TEXT NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT \'transactional\',
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            attempts INT NOT NULL DEFAULT 0,
            available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_until TIMESTAMP NULL,
            last_error VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE security_logs (
            id SERIAL PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            country_code CHAR(2) NULL,
            username_attempted VARCHAR(150) NULL,
            event_type VARCHAR(40) NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE security_ips (
            ip_address VARCHAR(45) PRIMARY KEY,
            country_code CHAR(2) NULL,
            clean_sessions INT NOT NULL DEFAULT 0,
            whitelisted SMALLINT NOT NULL DEFAULT 0,
            failure_count INT NOT NULL DEFAULT 0,
            escalation_level SMALLINT NOT NULL DEFAULT 0,
            blocked_until TIMESTAMP NULL,
            first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE country_rules (
            country_code CHAR(2) PRIMARY KEY,
            country_name VARCHAR(100) NOT NULL,
            action_code VARCHAR(20) NOT NULL DEFAULT \'not_specified\'
        )',

        'CREATE TABLE manual_access_rules (
            id SERIAL PRIMARY KEY,
            rule_type VARCHAR(20) NOT NULL,
            target_value VARCHAR(150) NOT NULL,
            action VARCHAR(20) NOT NULL,
            note VARCHAR(255) NULL,
            created_by INT NULL REFERENCES users (id) ON DELETE SET NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT uniq_access_rule UNIQUE (rule_type, target_value, action)
        )',

        'CREATE TABLE geo_ip_ranges (
            id SERIAL PRIMARY KEY,
            cidr_start BIGINT NOT NULL,
            cidr_end BIGINT NOT NULL,
            country_code CHAR(2) NOT NULL,
            label VARCHAR(100) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE firewall_jobs (
            id BIGSERIAL PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            action VARCHAR(10) NOT NULL DEFAULT \'block\',
            block_until TIMESTAMP NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            attempts SMALLINT NOT NULL DEFAULT 0,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE password_resets (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            token_hash CHAR(64) NOT NULL UNIQUE,
            ip_address VARCHAR(45) NULL,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE schema_migrations (
            version VARCHAR(40) PRIMARY KEY,
            checksum CHAR(64) NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',

        'CREATE TABLE sessions (
            id VARCHAR(128) PRIMARY KEY,
            user_id INT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    // The application is written for MySQL and calls UTC_TIMESTAMP(). Defining it here is the
    // honest way to run that SQL unchanged: the function returns exactly what MySQL returns.
    $pdo->exec('CREATE OR REPLACE FUNCTION utc_timestamp() RETURNS timestamp AS $$
                SELECT (now() AT TIME ZONE \'UTC\')::timestamp;
                $$ LANGUAGE sql STABLE');
}

/**
 * Seed one account and return its row.
 *
 * @param \PDO $pdo
 * @return array<string, mixed>
 */
function pgsql_seed_user(\PDO $pdo, string $name, string $email, string $role): array
{
    $statement = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?,?)'
    );
    $statement->execute([$name, $email, password_hash('LooseShadow77!', PASSWORD_DEFAULT), $role]);

    $id   = (int) $pdo->lastInsertId();
    $fetch = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $fetch->execute([$id]);

    return $fetch->fetch() ?: [];
}
