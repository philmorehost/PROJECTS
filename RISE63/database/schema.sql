-- RISE 63 — schema
-- Target: MySQL 8.0+ / MariaDB 10.6+, InnoDB, utf8mb4.
-- Money is DECIMAL(15,2); the application does all arithmetic in integer kobo (see app/Core/Money.php).
-- Statements are separated by a semicolon followed by a newline and are executed once, in order.
-- The installer refuses to run this file against a database that already contains tables.

CREATE TABLE zones (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    zone_name     VARCHAR(150) NOT NULL,
    zone_code     VARCHAR(20)  NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_zone_name (zone_name),
    UNIQUE KEY uniq_zone_code (zone_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE areas (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    zone_id       INT          NOT NULL,
    area_name     VARCHAR(150) NOT NULL,
    area_code     VARCHAR(20)  NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_area_per_zone (zone_id, area_name),
    UNIQUE KEY uniq_area_code (area_code),
    CONSTRAINT fk_areas_zone FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE parishes (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    area_id           INT          NOT NULL,
    parish_name       VARCHAR(150) NOT NULL,
    parish_code       VARCHAR(20)  NULL,
    worship_address   VARCHAR(255) NULL,
    yaya_rep_name     VARCHAR(150) NULL,
    yaya_rep_phone    VARCHAR(30)  NULL,
    youth_count       INT          NOT NULL DEFAULT 0,
    needs_summary     TEXT         NULL,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_parish_per_area (area_id, parish_name),
    UNIQUE KEY uniq_parish_code (parish_code),
    KEY idx_parishes_area (area_id),
    CONSTRAINT fk_parishes_area FOREIGN KEY (area_id) REFERENCES areas (id) ON DELETE CASCADE,
    CONSTRAINT chk_parishes_youth CHECK (youth_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flat reporting view that reproduces the original blueprint's parish_structure shape.
CREATE VIEW parish_directory AS
SELECT p.id            AS id,
       p.parish_name   AS parish_name,
       a.area_name     AS area_name,
       z.zone_name     AS zone_name,
       p.parish_code   AS parish_code,
       p.youth_count   AS youth_count
FROM parishes p
JOIN areas a ON a.id = p.area_id
JOIN zones z ON z.id = a.zone_id;

CREATE TABLE users (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    -- A member worships at a parish, an area or a zone church — exactly one of the three is set.
    -- `parish_id` keeps its meaning; migration 0004 added the other two (see 0004's header).
    parish_id                   INT          NULL,
    zone_id                     INT          NULL,
    area_id                     INT          NULL,
    parish_name_raw             VARCHAR(150) NULL,
    full_name                   VARCHAR(150) NOT NULL,
    email                       VARCHAR(150) NOT NULL,
    phone                       VARCHAR(30)  NULL,
    password_hash               VARCHAR(255) NOT NULL,
    must_change_password        TINYINT(1)   NOT NULL DEFAULT 0,
    role                        ENUM('provincial_admin','provincial_treasurer','csr_rep','youth_member') NOT NULL DEFAULT 'youth_member',
    transaction_pin_hash        VARCHAR(255) NULL,
    is_suspended                TINYINT(1)   NOT NULL DEFAULT 0,
    login_notifications_enabled TINYINT(1)   NOT NULL DEFAULT 1,
    successful_sessions_count   INT          NOT NULL DEFAULT 0,
    theme_preference            ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    large_text                  TINYINT(1)   NOT NULL DEFAULT 0,
    last_login_at               DATETIME     NULL,
    password_changed_at         DATETIME     NULL,
    created_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_users_email (email),
    KEY idx_users_parish (parish_id),
    KEY idx_users_zone (zone_id),
    KEY idx_users_area (area_id),
    KEY idx_users_role (role),
    CONSTRAINT fk_users_parish FOREIGN KEY (parish_id) REFERENCES parishes (id) ON DELETE SET NULL,
    CONSTRAINT fk_users_zone FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE SET NULL,
    CONSTRAINT fk_users_area FOREIGN KEY (area_id) REFERENCES areas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE program_applications (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    user_id                  INT          NOT NULL,
    parish_id                INT          NULL,
    program_type             ENUM('academy','seed_grant','cooperative','education_bridge') NOT NULL,
    cycle_year               SMALLINT     NOT NULL,
    education_level          ENUM('super_teen','tertiary','none') NOT NULL DEFAULT 'none',
    school_name              VARCHAR(200) NULL,
    school_class             VARCHAR(50)  NULL,
    requested_amount         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    approved_amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    documents_json           TEXT         NULL,
    answers_json             TEXT         NULL,
    status                   ENUM('draft','submitted','under_review','documents_requested','verified','approved','disbursed','completed','rejected','withdrawn') NOT NULL DEFAULT 'draft',
    decision_note            VARCHAR(500) NULL,
    idempotency_key          CHAR(64)     NULL,
    submitted_at             DATETIME     NULL,
    verified_at              DATETIME     NULL,
    approved_at              DATETIME     NULL,
    disbursed_at             DATETIME     NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_application_idempotency (user_id, idempotency_key),
    KEY idx_applications_user (user_id),
    KEY idx_applications_status (program_type, status),
    KEY idx_applications_parish (parish_id),
    KEY idx_applications_cycle (cycle_year, program_type),
    CONSTRAINT fk_applications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_applications_parish FOREIGN KEY (parish_id) REFERENCES parishes (id) ON DELETE SET NULL,
    CONSTRAINT chk_applications_amount CHECK (requested_amount >= 0 AND approved_amount >= 0),
    CONSTRAINT chk_applications_education CHECK (program_type <> 'education_bridge' OR (education_level <> 'none' AND school_name IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE application_events (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    application_id INT          NOT NULL,
    actor_id       INT          NULL,
    from_status    VARCHAR(30)  NULL,
    to_status      VARCHAR(30)  NOT NULL,
    note           VARCHAR(500) NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_events_application (application_id, id),
    CONSTRAINT fk_events_application FOREIGN KEY (application_id) REFERENCES program_applications (id) ON DELETE CASCADE,
    CONSTRAINT fk_events_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academy_cohorts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    cohort_name VARCHAR(150) NOT NULL,
    cycle_year  SMALLINT     NOT NULL,
    starts_on   DATE         NULL,
    ends_on     DATE         NULL,
    is_open     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cohort (cohort_name, cycle_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academy_progress (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT          NOT NULL,
    sessions_held  SMALLINT     NOT NULL DEFAULT 0,
    sessions_attended SMALLINT  NOT NULL DEFAULT 0,
    business_idea  TEXT         NULL,
    mentor_note    VARCHAR(500) NULL,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_progress_application (application_id),
    CONSTRAINT fk_progress_application FOREIGN KEY (application_id) REFERENCES program_applications (id) ON DELETE CASCADE,
    CONSTRAINT chk_progress_sessions CHECK (sessions_held >= 0 AND sessions_attended >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cooperative_partners (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    partner_name VARCHAR(150) NOT NULL,
    contact_name VARCHAR(150) NULL,
    contact_phone VARCHAR(30) NULL,
    notes        VARCHAR(500) NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partner_name (partner_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE remittances (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT          NOT NULL,
    amount         DECIMAL(15,2) NOT NULL,
    payee_name     VARCHAR(150) NOT NULL,
    bank_name      VARCHAR(150) NOT NULL,
    account_number VARCHAR(34)  NOT NULL,
    payment_reference VARCHAR(100) NOT NULL,
    status         ENUM('pending','paid','failed','reversed') NOT NULL DEFAULT 'pending',
    requested_by   INT          NOT NULL,
    confirmed_by   INT          NULL,
    confirmed_at   DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_remittance_reference (payment_reference),
    KEY idx_remittances_application (application_id),
    CONSTRAINT fk_remittances_application FOREIGN KEY (application_id) REFERENCES program_applications (id) ON DELETE RESTRICT,
    CONSTRAINT fk_remittances_requested FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_remittances_confirmed FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_remittances_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only. Triggers in database/ledger-guards.sql reject UPDATE and DELETE.
CREATE TABLE remittance_ledger (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    entry_type     ENUM('disbursement','reversal','pool_deposit','adjustment') NOT NULL,
    program_type   ENUM('academy','seed_grant','cooperative','education_bridge') NULL,
    application_id INT          NULL,
    amount         DECIMAL(15,2) NOT NULL,
    balance_after  DECIMAL(15,2) NULL,
    reference      VARCHAR(100) NOT NULL,
    description    VARCHAR(255) NULL,
    created_by     INT          NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ledger_reference (reference),
    KEY idx_ledger_program (program_type, entry_type),
    CONSTRAINT fk_ledger_application FOREIGN KEY (application_id) REFERENCES program_applications (id) ON DELETE SET NULL,
    CONSTRAINT fk_ledger_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE security_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT         NOT NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE security_logs (
    id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address         VARCHAR(45)  NOT NULL,
    country_code       CHAR(2)      NULL,
    username_attempted VARCHAR(150) NULL,
    event_type         ENUM('failed_login','account_suspended','account_unsuspended','ip_blocked','ip_unblocked','whitelisted_ip','country_blocked','success','pin_failure','settings_changed','login_alert') NOT NULL,
    details            TEXT         NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_ip (ip_address, event_type, created_at),
    KEY idx_logs_username (username_attempted, event_type, created_at),
    KEY idx_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE security_ips (
    ip_address     VARCHAR(45) PRIMARY KEY,
    country_code   CHAR(2)     NULL,
    clean_sessions INT         NOT NULL DEFAULT 0,
    whitelisted    TINYINT(1)  NOT NULL DEFAULT 0,
    failure_count  INT         NOT NULL DEFAULT 0,
    escalation_level TINYINT   NOT NULL DEFAULT 0,
    blocked_until  DATETIME    NULL,
    first_seen_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE country_rules (
    country_code CHAR(2)      PRIMARY KEY,
    country_name VARCHAR(100) NOT NULL,
    action_code  ENUM('whitelisted','not_specified','blacklisted') NOT NULL DEFAULT 'not_specified'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE manual_access_rules (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    rule_type    ENUM('ip','username') NOT NULL,
    target_value VARCHAR(150) NOT NULL,
    action       ENUM('whitelisted','blacklisted') NOT NULL,
    note         VARCHAR(255) NULL,
    created_by   INT          NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_access_rule (rule_type, target_value, action),
    CONSTRAINT fk_access_rules_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional operator-managed IPv4 ranges used when no trusted-proxy country header exists.
CREATE TABLE geo_ip_ranges (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    cidr_start   BIGINT UNSIGNED NOT NULL,
    cidr_end     BIGINT UNSIGNED NOT NULL,
    country_code CHAR(2)         NOT NULL,
    label        VARCHAR(100)    NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_geo_lookup (cidr_start, cidr_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE firewall_jobs (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45) NOT NULL,
    action        ENUM('block','unblock') NOT NULL DEFAULT 'block',
    block_until   DATETIME    NOT NULL,
    status        ENUM('pending','applied','failed','skipped') NOT NULL DEFAULT 'pending',
    attempts      TINYINT     NOT NULL DEFAULT 0,
    notes         VARCHAR(255) NULL,
    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_firewall_status (status, created_at),
    KEY idx_firewall_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notification_outbox (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    recipient    VARCHAR(150) NOT NULL,
    subject      VARCHAR(200) NOT NULL,
    body_html    MEDIUMTEXT   NOT NULL,
    body_text    MEDIUMTEXT   NOT NULL,
    category     VARCHAR(40)  NOT NULL DEFAULT 'transactional',
    status       ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts     TINYINT      NOT NULL DEFAULT 0,
    available_at DATETIME     NOT NULL,
    locked_until DATETIME     NULL,
    last_error   VARCHAR(255) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at      DATETIME     NULL,
    KEY idx_outbox_dispatch (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_events (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT          NULL,
    actor_email VARCHAR(150) NULL,
    action      VARCHAR(60)  NOT NULL,
    entity      VARCHAR(60)  NOT NULL,
    entity_id   VARCHAR(40)  NULL,
    summary     VARCHAR(255) NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_entity (entity, entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sessions_meta (
    session_hash CHAR(64) PRIMARY KEY,
    user_id      INT          NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    revoked_at   DATETIME     NULL,
    KEY idx_sessions_user (user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Public site ─────────────────────────────────────────────────────────────────────────
-- The landing page is content, not markup: every word an administrator publishes lives here and is
-- edited in Settings → Website. `site_pages` holds only pages that can be *added*; the four that must
-- always exist (about, vision, privacy, terms) are seeded by migration 0005 and flagged is_system so
-- they cannot be deleted out from under a footer link.
CREATE TABLE site_pages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(60)  NOT NULL,
    title       VARCHAR(150) NOT NULL,
    kicker      VARCHAR(120) NULL,
    summary     VARCHAR(300) NULL,
    body        MEDIUMTEXT   NULL,
    is_published TINYINT(1)  NOT NULL DEFAULT 1,
    is_system   TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  INT          NOT NULL DEFAULT 100,
    updated_by  INT          NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_site_page_slug (slug),
    KEY idx_site_pages_order (sort_order),
    CONSTRAINT fk_site_pages_author FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per hero slide. `image_path` is a file in storage/site/ written by Security::storeSiteImage;
-- `image_alt` is required in the form because a slide with no description is invisible to a screen
-- reader and to search. `is_published` is separate from `sort_order` so a slide can be taken out of
-- rotation without losing its position.
CREATE TABLE site_slides (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kicker       VARCHAR(120) NULL,
    headline     VARCHAR(180) NOT NULL,
    subhead      VARCHAR(400) NULL,
    body         VARCHAR(600) NULL,
    image_path   VARCHAR(120) NULL,
    image_alt    VARCHAR(200) NULL,
    cta_label    VARCHAR(60)  NULL,
    cta_page     VARCHAR(60)  NULL,
    tone         ENUM('ink','light') NOT NULL DEFAULT 'ink',
    is_published TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order   INT          NOT NULL DEFAULT 100,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_site_slides_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schema_migrations (
    version    VARCHAR(40) PRIMARY KEY,
    checksum   CHAR(64)    NULL,
    applied_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
