# Ultimate Comprehensive Architectural Blueprint: Modern Non-Custodial Crypto Payment Gateway (Advanced Blockonomics Clone)

This master blueprint consolidates all core architecture, non-custodial cryptographic logic, the cPHulk/Imunify360-grade security engine, the 4-stage dynamic web installer, and modern Single-Page Application (SPA) UX enhancements into a single unified implementation reference.

---

## 1. Executive Summary & Core Philosophy

The platform is engineered as a lightning-fast, highly secure, non-custodial cryptocurrency payment gateway.

* **Zero Fund Custody:** Crypto payments route directly from buyers to merchant external wallets via deterministic `xPub` address derivation.
* **Prefund Accounting Model:** Gateway operational fees (e.g., 1%) are deducted from an internal database credit balance prefunded by the merchant.
* **Frictionless SPA Experience:** Built with Laravel and Livewire/Alpine.js to eliminate full-page reloads, featuring a Command Palette (`Ctrl+K`), real-time WebSockets, and a mobile-first sticky bottom navigation bar.

---

## 2. Technology Stack & Directory Architecture

```text
/app
├── Http/
│   ├── Controllers/
│   │   ├── InstallController.php       # Manages 4-stage setup wizard
│   │   ├── GatewayController.php      # xPub derivation & invoice handling
│   │   └── SecurityController.php     # cPHulk firewall & brute-force monitoring
│   ├── Middleware/
│   │   ├── CheckInstalled.php         # Intercepts requests for root installer
│   │   └── BruteForceProtection.php   # IP/Account rate limiting & lockout rules
├── Livewire/
│   └── DashboardManager.php           # SPA reactive dashboard navigation container
├── Services/
│   ├── BitcoinHDWalletService.php     # BIP44/xPub address generation engine
│   └── BlockchainNodeService.php      # QuickNode/Alchemy/BlockBee API polling

```

* **Backend:** Laravel 10/11 (PHP 8.2+), MySQL 8.0+, Redis.
* **Frontend:** Tailwind CSS v3/v4, Alpine.js, Laravel Livewire (for reactive SPA navigation without page refreshes).
* **Cryptographic & Blockchain Libs:** `kornrunner/secp256k1`, `bitwasp/bitcoin-php`, GuzzleHttp.

---

## 3. Dynamic 4-Stage Web Installer

Accessing the root directory automatically invokes the installer if the `storage/installed` lock file is missing.

* **Stage 1 (System & License):** Validates PHP version (`>=8.2`), extensions (`BCMath`, `OpenSSL`, `PDO`, `cURL`, `Mbstring`, `Redis`), and verifies the license key against `[https://manager.pmhserver.name.ng/api-docs.php](https://manager.pmhserver.name.ng/api-docs.php)`.
* **Stage 2 (Database Connection):** Collects MySQL credentials, tests connection, auto-writes the `.env` file, and executes database migrations.
* **Stage 3 (Super Admin & Branding):** Creates the primary administrator account and dynamically generates the SVG/CSS Favicon and Logo using the **First Letter of the Site Title**.
* **Stage 4 (Completion):** Generates the `storage/installed` lock file, provides Cron setup instructions (`* * * * * php artisan schedule:run`), and redirects to the admin panel.

---

## 4. Advanced cPHulk / Imunify360-Grade Security Engine

### A. IP & Country Firewall

* **Global Country Control:** Database table storing ISO countries with status options: `Whitelisted`, `Blacklisted`, or `Not Specified` (Default).
* **Live Search Filter:** Instant JavaScript filtering in the admin panel. Blacklisted countries or IPs are blocked at the firewall level instantly.

### B. Account & IP Brute-Force Protection

* **Configurable Parameters (Admin Managed):**
* Brute Force Protection Period (in minutes).
* Maximum Failures by Account & IP Address.
* Privilege Exception: Toggle whether username protection locks default administrative accounts (`admin`, `administrator`).


* **Lockout Actions:** Suspend user accounts on repeated login failures; automatically block attacking IPs at the firewall level (1-day, 1-week, 1-month, or 1-year blocks).

### C. Smart Whitelisting & King Icon Logging

* Successful logins without failed attempts increment a session counter.
* After **5 successful logins from different sessions**, the IP is automatically whitelisted.
* **UI Indicator:** In the Admin Login History & Security Monitor logs, recognized/whitelisted IPs display a distinct **King Icon with a Green Marking**.
* **Notifications:** Real-time email alerts dispatched for successful admin logins from unknown IPs, netblock matches, or brute-force user attempts. Users can toggle personal alerts in their profile settings.

---

## 5. Unified Database Schema Blueprint

```sql
-- Core Settings & Configuration Table
CREATE TABLE `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key_name` VARCHAR(191) UNIQUE NOT NULL,
  `key_value` LONGTEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Users & Merchants Table
CREATE TABLE `users` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'merchant') DEFAULT 'merchant',
  `status` ENUM('active', 'suspended', 'locked') DEFAULT 'active',
  `enable_login_notifications` BOOLEAN DEFAULT TRUE,
  `failed_login_attempts` INT DEFAULT 0,
  `last_failed_login` TIMESTAMP NULL,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Merchant xPub Wallets
CREATE TABLE `merchant_wallets` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `coin_symbol` VARCHAR(20) NOT NULL,
  `xpub_key` TEXT NOT NULL,
  `derived_index` INT DEFAULT 0,
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- Prefund Internal Ledger Balances
CREATE TABLE `merchant_balances` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `currency` VARCHAR(20) DEFAULT 'USD',
  `balance` DECIMAL(18,8) DEFAULT 0.00000000,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- Transactions & Fee Ledger
CREATE TABLE `transactions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `type` ENUM('prefund_deposit', 'fee_debit', 'withdrawal') NOT NULL,
  `amount` DECIMAL(18,8) NOT NULL,
  `currency` VARCHAR(20) NOT NULL,
  `tx_hash` VARCHAR(255) NULL,
  `status` ENUM('pending', 'confirmed', 'failed') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- Invoices Table
CREATE TABLE `invoices` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `invoice_id` VARCHAR(100) UNIQUE NOT NULL,
  `order_id` VARCHAR(100) NULL,
  `crypto_amount` DECIMAL(18,8) NOT NULL,
  `fiat_amount` DECIMAL(18,2) NOT NULL,
  `currency` VARCHAR(20) NOT NULL,
  `deposit_address` VARCHAR(255) NOT NULL,
  `status` ENUM('pending', 'detected', 'confirmed', 'expired') DEFAULT 'pending',
  `confirmations` INT DEFAULT 0,
  `callback_url` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- Security Logs & Brute-Force Tracking
CREATE TABLE `security_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `username_attempted` VARCHAR(255) NULL,
  `event_type` ENUM('failed_login', 'account_lock', 'ip_block', 'whitelisted_success', 'admin_alert') NOT NULL,
  `details` TEXT NULL,
  `country_code` VARCHAR(5) NULL,
  `is_whitelisted` BOOLEAN DEFAULT FALSE,
  `success_session_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Country Firewall Rules Table
CREATE TABLE `country_firewall` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `country_code` VARCHAR(5) NOT NULL,
  `country_name` VARCHAR(100) NOT NULL,
  `action` ENUM('Whitelisted', 'Not Specified', 'Blacklisted') DEFAULT 'Not Specified',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

```

---

## 6. Frontend UI/UX & Single-Page Application (SPA) Enhancements

* **Zero-Reload SPA Navigation:** Powered by Laravel Livewire / Alpine.js, allowing merchants to switch between Invoices, Wallets, and Settings instantly without full-page reloads.
* **Command Palette (`Ctrl+K`):** Spotlight-style keyboard search modal enabling merchants to execute quick navigation or trigger actions instantly.
* **Real-Time WebSockets (Laravel Reverb / Pusher):** Instant invoice payment status updates and toast notifications without manual page refreshes.
* **Mobile-First Design & Sticky Footer:** Styled with dark-themed cards (`bg-slate-900 border border-slate-800 text-slate-100`) and a modern fintech bottom navigation bar equipped with quick-access buttons.
* **Dynamic Logo & Favicon:** Automatically renders a styled badge using the first letter of the site title if no custom logo image has been uploaded by the admin.

---