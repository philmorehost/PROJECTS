<?php
/**
 * DGV7 Standalone — Database Credentials
 * Written by install wizard on 2026-10-02 22:50:24
 */

function _bc_db_config_safe(): array {
    return [
        'server'  => getenv('DB_HOST') ?: 'localhost',
        'user'    => getenv('DB_USER') ?: 'haritzc1_vtuapp',
        'pass'    => getenv('DB_PASS') ?: 'q8LaYT5JUYUt5c6DkSEJ',
        'dbname'  => getenv('DB_NAME') ?: 'haritzc1_vtuapp',
        'app_env' => getenv('APP_ENV') ?: 'production',
    ];
}

$db_json_decode = _bc_db_config_safe();

// Legacy variables kept for strict backward compatibility
$db_json_dtls   = $db_json_decode;
$db_json_encode = json_encode($db_json_decode, JSON_THROW_ON_ERROR);
