<?php
declare(strict_types=1);

/**
 * RISE 63 — application bootstrap.
 *
 * Deliberately framework-free. Everything below runs on stock PHP 8.2+ and is loaded by
 * the front controller (index.php), the installer (install/index.php) and the CLI workers.
 */

namespace RISE63;

const VERSION        = '1.0.8';
const MIN_PHP        = '8.2.0';
const PRODUCT        = 'RISE 63';
const ROOT           = __DIR__ . '/..';
const SESSION_NAME   = 'rise63_session';
const IDLE_MINUTES   = 30;
const MAX_BODY_BYTES = 1048576; // 1 MiB request ceiling for form posts.

require __DIR__ . '/Core/polyfills.php';
require __DIR__ . '/Core/Money.php';
require __DIR__ . '/Core/Database.php';
require __DIR__ . '/Core/Settings.php';
require __DIR__ . '/Core/Functions.php';
require __DIR__ . '/Core/Security.php';
require __DIR__ . '/Core/GeoResolver.php';
require __DIR__ . '/Core/BruteForce.php';
require __DIR__ . '/Core/Auth.php';
require __DIR__ . '/Core/ParishDirectory.php';
require __DIR__ . '/Core/Program.php';
require __DIR__ . '/Core/Applications.php';
require __DIR__ . '/Core/Ledger.php';
require __DIR__ . '/Core/Notifier.php';
require __DIR__ . '/Core/Mailer.php';
require __DIR__ . '/Core/Smtp.php';
require __DIR__ . '/Core/View.php';
require __DIR__ . '/Core/Activation.php';
require __DIR__ . '/Core/Installer.php';
require __DIR__ . '/Core/Audit.php';
require __DIR__ . '/Core/Migrator.php';
require __DIR__ . '/Core/Schema.php';
require __DIR__ . '/Core/ZipReader.php';
require __DIR__ . '/Core/Updater.php';
require __DIR__ . '/Core/PasswordReset.php';

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
require __DIR__ . '/Core/Registration.php';
require __DIR__ . '/Core/DirectoryImport.php';
require __DIR__ . '/Core/Paginator.php';
require __DIR__ . '/Core/Fund.php';
require __DIR__ . '/Core/Members.php';
require __DIR__ . '/Core/SelfHeal.php';
require __DIR__ . '/Core/Share.php';
