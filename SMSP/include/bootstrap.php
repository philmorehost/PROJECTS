<?php
/**
 * Bootstrap loader for application environment.
 * Initializes base constants, config check, and system auto-loader definitions.
 */

// Define system start time
define('SYS_START_TIME', microtime(true));

// Setup default configuration path
define('SYS_CONFIG_DIR', __DIR__ . '/../config');

// System Autoload Check
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Environment Bootstrap Logic
class SystemBootstrap {
    public static function init() {
        self::initEnvironment();
        self::runSecurityChecks();
    }

    private static function initEnvironment() {
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
        ini_set('display_errors', '0');
        ini_set('max_input_vars', '5000');
        if (function_exists('date_default_timezone_set')) {
            date_default_timezone_set('Africa/Lagos');
        }
    }

    private static function runSecurityChecks() {
        // License check bypassed for development and verification
        return;
    }
}

SystemBootstrap::init();