<?php
// ─────────────────────────────────────────────────────────────────────────
// Mzeevtu crypto bridge — configuration
// Put secrets in environment variables (or a file outside public_html).
// ─────────────────────────────────────────────────────────────────────────
return [
    // The Node wallet service (runs on the same server / private network)
    'service_url'     => getenv('MZ_CRYPTO_SERVICE_URL') ?: 'http://127.0.0.1:8787',
    'api_secret'      => getenv('MZ_CRYPTO_API_SECRET') ?: '',      // = API_SECRET in service .env
    'callback_secret' => getenv('MZ_CRYPTO_CALLBACK_SECRET') ?: '', // = CALLBACK_SECRET in service .env

    // Your DGV7.0 database (same one the site uses)
    'db' => [
        'dsn'  => getenv('MZ_DB_DSN') ?: 'mysql:host=localhost;dbname=YOUR_DGV_DATABASE;charset=utf8mb4',
        'user' => getenv('MZ_DB_USER') ?: 'YOUR_DB_USER',
        'pass' => getenv('MZ_DB_PASS') ?: 'YOUR_DB_PASSWORD',
    ],

    // ⚠️ ADAPT: where DGV7.0 stores the user's Naira wallet balance.
    // Open phpMyAdmin → find the users table and the balance column, then fill these in.
    'wallet' => [
        'table'       => 'users',          // e.g. users / subscribers / members
        'id_col'      => 'id',             // primary key of the user row
        'balance_col' => 'wallet_balance', // e.g. balance / wallet / user_balance
    ],

    // ⚠️ ADAPT: the $_SESSION key DGV7.0 uses for the logged-in user id
    'session_user_key' => 'user_id',

    // Optional: write a line into DGV's own transaction history so users see it in their statement.
    // Leave null to skip. Placeholders: :uid, :amount, :ref, :desc, :type ('credit' or 'debit')
    'history_sql' => null,
    // e.g. "INSERT INTO transactions (user_id, amount, reference, description, type, status, date)
    //       VALUES (:uid, :amount, :ref, :desc, :type, 'success', NOW())",
];
