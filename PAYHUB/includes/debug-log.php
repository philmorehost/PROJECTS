<?php
/**
 * Bounded, opt-in debug log helper for PayHub.
 * Disabled by default unless PAYHUB_DEBUG_LOG_ENABLED=1.
 * Writes strictly into PAYHUB/logs/ (protected by .htaccess), never into webroot.
 */

if (!function_exists('payhub_debug_log_enabled')) {
    function payhub_debug_log_enabled() {
        return getenv('PAYHUB_DEBUG_LOG_ENABLED') === '1';
    }
}

if (!function_exists('payhub_debug_log')) {
    function payhub_debug_log($channel, $message, $baseDir = null) {
        if (!payhub_debug_log_enabled()) {
            return false;
        }
        $safeChannel = preg_replace('/[^a-z0-9_-]/i', '', (string)$channel);
        if ($safeChannel === '') {
            $safeChannel = 'payhub_debug';
        }
        $root = is_string($baseDir) && $baseDir !== ''
            ? rtrim($baseDir, '/\\')
            : dirname(__DIR__);
        $logDir = $root . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0750, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] '
            . substr(str_replace(["\r", "\n", "\0"], ' ', (string)$message), 0, 1000)
            . PHP_EOL;
        return @file_put_contents($logDir . '/' . $safeChannel . '.log', $line, FILE_APPEND | LOCK_EX) !== false;
    }
}
