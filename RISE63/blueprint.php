# COMPREHENSIVE TECHNICAL & ARCHITECTURAL BLUEPRINT: RISE 63 PLATFORM & REMITTANCE SYSTEM

## 1. Executive Summary & System Objectives

The **RISE 63 Platform** is a high-performance, secure, and mobile-first progressive web application (PWA) designed for RCCG Lagos Province 63 (LP63 YAYA CSR & Young Professionals Club). The system unifies grassroots parish intake, applicant profiling, four core empowerment programs (RISE Academy, Seed Grants, Cooperative Access, and Education Bridge), financial remittances, and advanced administrative security workflows.

The architecture incorporates enterprise security modeled directly after **cPanel cPHulk Brute Force Protection** and **Imunify360**, paired with a PWA installation engine, dynamic SVG favicons, a 4-stage silent installer, and comprehensive event notifications.

---

## 2. System Architecture & High-Scale Performance Stack

Built on a high-efficiency native stack for seamless deployment on standard cPanel/WHM hosting environments (AlmaLinux/CloudLinux with Apache/LiteSpeed):

* **Backend Engine:** Native Vanilla PHP (PHP 8.2+) utilizing modular object-oriented structures and secure PDO database abstractions.
* **Database Layer:** MySQL/MariaDB configured with InnoDB storage engines, explicit foreign key constraints, and strict decimal precision (`DECIMAL(15,2)`) for accurate monetary calculations.
* **Performance Optimizations:**
* **Persistent Database Connections:** Reusing PDO connection instances (`PDO::ATTR_PERSISTENT => true`) to eliminate TCP handshake overhead during traffic surges.
* **OPcache Acceleration:** Configured to cache compiled PHP bytecode directly into shared memory (`opcache.enable=1`, `opcache.memory_consumption=128MB`), reducing disk I/O to near-zero.
* **Static Asset Caching:** Long-lived cache headers (`Cache-Control: max-age=31536000, immutable`) for compiled CSS and JS assets, alongside Gzip/Brotli transport compression.



---

## 3. Progressive Web App (PWA) & Mobile Native Experience Architecture

To ensure the portal functions and feels like a native mobile app on smartphones without requiring app store distribution:

* **Web App Manifest (`manifest.json`):** Defines standalone display mode, orientation, app name ("RISE 63"), theme colors (`#2563eb`), and launcher icons (`192x192` and `512x512`).
* **Service Worker (`sw.js`):** Intercepts network requests and caches core shell assets (`/`, `/index.php`, `/manifest.json`) to support reliable offline access.
* **Persistent Floating Install Prompt:**
* Automatically captures the browser's `beforeinstallprompt` event on Android/Chrome devices to display a custom floating installation banner.
* Detects iOS Safari users and provides an automated interactive guide directing them to use the "Share -> Add to Home Screen" native feature.
* Automatically hides when the app runs in standalone installed mode (`window.matchMedia('(display-mode: standalone)')`).



---

## 4. Complete Database Schema Blueprint

```sql
-- Hierarchical Structure (Zone -> Area -> Parish)
CREATE TABLE `parish_structure` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parish_name` VARCHAR(150) NOT NULL,
  `area_name` VARCHAR(150) NOT NULL,
  `zone_name` VARCHAR(150) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users & Roles
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parish_id` INT DEFAULT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('provincial_admin', 'provincial_treasurer', 'csr_rep', 'youth_member') NOT NULL DEFAULT 'youth_member',
  `is_suspended` TINYINT(1) DEFAULT 0,
  `login_notifications_enabled` TINYINT(1) DEFAULT 1,
  `successful_sessions_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`parish_id`) REFERENCES `parish_structure`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Core Applications Table (Education Bridge, Seed Grants, Academy)
CREATE TABLE `program_applications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `program_type` ENUM('academy', 'seed_grant', 'cooperative', 'education_bridge') NOT NULL,
  `education_level` ENUM('super_teen', 'tertiary', 'none') DEFAULT 'none',
  `school_name` VARCHAR(200) DEFAULT NULL,
  `requested_amount` DECIMAL(15,2) DEFAULT 0.00,
  `verifiable_documents_json` TEXT DEFAULT NULL,
  `status` ENUM('pending', 'verified', 'approved', 'rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Security Settings Configuration Table
CREATE TABLE `security_settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Security Tracking Logs (cPHulk / Imunify360 Style)
CREATE TABLE `security_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `username_attempted` VARCHAR(150) DEFAULT NULL,
  `event_type` ENUM('failed_login', 'account_suspended', 'ip_blocked', 'whitelisted_ip', 'country_blocked') NOT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Country Blacklist / Whitelist Rules
CREATE TABLE `country_rules` (
  `country_code` CHAR(2) PRIMARY KEY,
  `country_name` VARCHAR(100) NOT NULL,
  `action` ENUM('whitelisted', 'not_specified', 'blacklisted') DEFAULT 'not_specified'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manual IP & Username Blacklist/Whitelist
CREATE TABLE `manual_access_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rule_type` ENUM('ip', 'username') NOT NULL,
  `target_value` VARCHAR(150) NOT NULL,
  `action` ENUM('whitelisted', 'blacklisted') NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

```

---

## 5. Automated 4-Stage Silent Installer & Core Security Engine

When accessing the root directory (`index.php`), the application checks for an installation lockfile (`config/installed.lock`). If absent, execution is routed immediately to the setup wizard.

### The 4-Stage Installation Workflow

1. **Stage 1 (Environment & System Validation):** Verifies PHP version `>= 8.1.0`, checks required extensions (`pdo`, `pdo_mysql`, `curl`, `gd`, `json`), and securely validates the system activation token against the remote verification manager (`[https://manager.pmhserver.name.ng/api-docs.php](https://manager.pmhserver.name.ng/api-docs.php)`) using obfuscated core helper functions (`sys_check_core()`). *Note: The term "license" is never referenced in code, logs, or parameters.*
2. **Stage 2 (Database Configuration & Schema Migration):** Tests database connectivity credentials, initializes the database, and executes schema creation scripts automatically.
3. **Stage 3 (Provincial Administrator Setup):** Creates the primary administrator profile, sets the site title (used for dynamic SVG favicon generation), configures notification emails, and establishes a secure 4-digit transaction PIN.
4. **Stage 4 (Completion & Lock):** Generates the `config/installed.lock` file to permanently disable the installer wizard, displaying post-installation security instructions.

---

## 6. Advanced Anti-Brute-Force & Adaptive Security Engine (cPHulk & Imunify360 Style)

### A. Username-Based Protection

* **Account Suspension:** Exceeding the maximum allowed failed login attempts automatically suspends the target user account (`is_suspended = 1`).
* **Non-Existent User Handling:** If a login attempt targets a username/email that does not exist in the database, the originating IP is immediately logged and blocked.
* **Admin Controls:**
* Enable/Disable Username Protection (retains existing locks when toggled off).
* Brute Force Protection Period (in minutes).
* Maximum Failures by Account threshold.
* Scope selection: Apply protection to local addresses only, or both local and remote addresses.
* Privilege exception toggle: Allow or disallow username protection to lock privileged accounts (`admin`, `administrator`).



### B. IP Address-Based Protection & Auto-Whitelist Engine

* **Session Tracking & Auto-Whitelist:** Successful logins without prior failures increment a distinct session counter per IP. Upon reaching **5 successful sessions across different sessions**, the IP is automatically recognized and whitelisted.
* **Visual Indicator:** Whitelisted and recognized IPs are displayed in the admin security logs accompanied by a **King icon with a green marking**.
* **IP Brute-Force Thresholds:**
* Enable/Disable IP-Based Protection.
* IP Brute-Force Protection Period (in minutes).
* Maximum Failures per IP Address before triggering restrictions.


* **Firewall Level Blocks & Durations:** When an IP address triggers brute-force thresholds or one-day blocks, the system communicates with server firewalls to block the IP. Configurable blocking durations include:
* One-day Blocks
* One-week Blocks
* One-month Blocks
* One-year Blocks



### C. Manual Blacklist, Country Filtering & Log Retentions

* **Global Country Filter:** Pre-populated table of all world countries defaulted to `Not Specified`, featuring a live search/filter bar. Actions: `Whitelisted`, `Not Specified`, `Blacklisted`.
* **Log Retention Window:** Configurable duration for retaining failed login history (`5 MINS`, `10 MINS`, `15 MINS`, `60 MINS`).

---

## 7. Core Functional Modules

* **Module A: Parish Connect (Grassroots Network):** Digital intake form and directory tracking YAYA CSR Representatives across every parish in LP63, capturing aggregate youth participation, mentorship directories, and needs assessments.
* **Module B: RISE Academy:** 3-month entrepreneurship and digital/financial literacy training tracking module.
* **Module C: Seed Grants:** Non-repayable micro-grants workflow (₦100,000 monthly pool) for top Academy graduates with verified business proposals.
* **Module D: Cooperative Access:** Facilitates structured savings and loans partnerships with registered cooperative societies.
* **Module E: Education Bridge:** Needs-based tuition assistance for Super Teens (SS1–SS3) and Tertiary students, featuring automated document uploads and direct school-payment tracking.

---

## 8. Dynamic SVG Favicon & Notification Engines

* **Dynamic SVG Favicon:** Generates an inline base64 SVG favicon dynamically from the first letter of the Admin-configured Site Title.
* **Multi-Event Notifications:** Asynchronous HTML email notifications dispatched for successful admin logins from non-whitelisted or known netblock IPs, brute-force security alerts (including attempted username), user profile preference toggles, and transactional updates.

---