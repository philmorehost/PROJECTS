<?php
declare(strict_types=1);

/**
 * Mail worker. Run from cron every five minutes:
 *
 *   *\/5 * * * * /usr/local/bin/php /home/USER/rise63/bin/mail-worker.php >> ~/rise63-mail.log 2>&1
 *
 * Claims rows atomically with a lease (FOR UPDATE SKIP LOCKED where available) so two cron
 * runs cannot deliver the same message twice, retries with exponential backoff, and never
 * prints message bodies or credentials.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use RISE63\Core\Database;
use RISE63\Core\Mailer;
use RISE63\Core\Settings;

use function RISE63\config_dir;

$verbose = in_array('--verbose', $argv ?? [], true);
$limit   = 50;
$sent    = 0;
$failed  = 0;

if (!is_file(config_dir() . '/installed.lock')) {
    fwrite(STDERR, "RISE 63 is not installed; nothing to do.\n");
    exit(0);
}

try {
    Database::connection();
} catch (Throwable $error) {
    fwrite(STDERR, 'Database unavailable: ' . $error->getMessage() . "\n");
    exit(1);
}

Settings::flush();
$from     = (string) (getenv('RISE63_MAIL_FROM') ?: 'no-reply@' . (parse_url((string) (getenv('RISE63_SITE_URL') ?: 'https://localhost'), PHP_URL_HOST) ?: 'localhost'));
$fromName = (string) Settings::get('site_title', 'RISE 63');

try {
    $mailer = Mailer::fromConfig();
} catch (Throwable $error) {
    fwrite(STDERR, 'SMTP is not ready: ' . $error->getMessage() . "\n");
    exit(1);
}

$claimed = Database::all(
    "SELECT * FROM notification_outbox
     WHERE status IN ('pending','failed')
       AND available_at <= UTC_TIMESTAMP()
       AND attempts < 6
       AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())
     ORDER BY id ASC LIMIT {$limit}"
);

foreach ($claimed as $row) {
    $id = (int) $row['id'];
    $claim = Database::run(
        'UPDATE notification_outbox SET status = ?, locked_until = UTC_TIMESTAMP() + INTERVAL 10 MINUTE WHERE id = ? AND status IN (?,?)',
        ['sending', $id, 'pending', 'failed']
    );
    if ($claim->rowCount() !== 1) {
        continue; // Another worker won the race.
    }

    try {
        $mailer->send($from, $fromName, (string) $row['recipient'], (string) $row['subject'], (string) $row['body_html'], (string) $row['body_text']);
        Database::run('UPDATE notification_outbox SET status = ?, sent_at = UTC_TIMESTAMP(), locked_until = NULL, last_error = NULL WHERE id = ?', ['sent', $id]);
        $sent++;
        if ($verbose) {
            fwrite(STDOUT, "sent #{$id}\n");
        }
    } catch (Throwable $error) {
        $attempts = (int) $row['attempts'] + 1;
        $delay    = min(240, 5 * (2 ** ($attempts - 1)));
        $status   = $attempts >= 6 ? 'failed' : 'pending';
        // Only the exception message is stored, and Mailer never includes credentials in it.
        Database::run(
            'UPDATE notification_outbox SET status = ?, attempts = ?, available_at = UTC_TIMESTAMP() + INTERVAL ? MINUTE,
                    locked_until = NULL, last_error = ? WHERE id = ?',
            [$status, $attempts, $delay, mb_substr($error->getMessage(), 0, 255), $id]
        );
        $failed++;
        fwrite(STDERR, "delivery #{$id} attempt {$attempts} failed: " . $error->getMessage() . "\n");
    }
}

try {
    $mailer->quit();
} catch (Throwable) {
    // Ignore: the connection is finished either way.
}

$pruned = Database::run("DELETE FROM notification_outbox WHERE status = 'sent' AND sent_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)")->rowCount();

fwrite(STDOUT, sprintf("RISE63 mail worker: %d sent, %d failed, %d pruned.\n", $sent, $failed, $pruned));
exit($failed > 0 && $sent === 0 ? 1 : 0);
