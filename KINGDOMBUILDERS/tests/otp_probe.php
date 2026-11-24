<?php
declare(strict_types=1);
/**
 * Test helper (never shipped): reads state from the disposable test database
 * so the HTTP smoke suite can finish a flow that now needs an emailed code.
 *
 *   php tests/otp_probe.php user grace@example.test   -> 1 or 0
 *   php tests/otp_probe.php code grace@example.test   -> six digits
 *   php tests/otp_probe.php count questions           -> row count
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

if (!KB\installed()) { fwrite(STDERR, "Not installed.\n"); exit(2); }
$command = (string) ($argv[1] ?? '');
$email = strtolower(trim((string) ($argv[2] ?? '')));

if ($command === 'user') {
    echo (int) (bool) KB\DB::value('SELECT id FROM users WHERE email=?', [$email]), PHP_EOL;
    exit(0);
}
if ($command === 'code') {
    foreach (KB\DB::all('SELECT body FROM mail_queue WHERE recipient=? ORDER BY id DESC LIMIT 6', [$email]) as $row) {
        if (preg_match('/^\s*(\d{6})\s*$/m', (string) $row['body'], $match)) { echo $match[1], PHP_EOL; exit(0); }
    }
    fwrite(STDERR, "No code found for that address.\n");
    exit(1);
}
if ($command === 'count' && in_array($email, ['questions', 'users', 'attempts'], true)) {
    echo (int) KB\DB::value('SELECT COUNT(*) FROM ' . $email), PHP_EOL;
    exit(0);
}
fwrite(STDERR, "Usage: otp_probe.php user|code <email> | count <table>\n");
exit(2);
