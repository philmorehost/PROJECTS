<?php
declare(strict_types=1);
/**
 * SMTP capability parsing tests. No network and no database: raw EHLO reply
 * lines are fed straight into the parser. Run with: php tests/mail_parse.php
 *
 * These exist because the mailer once compared capability names with the SMTP
 * reply code still attached ("250-AUTH" instead of "AUTH"), which made every
 * authenticated provider look as though it offered no AUTH at all.
 */
namespace KB {
    require_once dirname(__DIR__) . '/app/Core/MailError.php';
    require_once dirname(__DIR__) . '/app/Core/Mail.php';
}

namespace {
    use KB\Mail;
    use KB\MailError;

    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

    function check(bool $condition, string $label): void {
        if (!$condition) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
        echo "PASS {$label}\n";
    }

    function mechanisms(array $caps): array { return $caps['AUTH'] ?? []; }

    // 1. A cPanel/Exim reply, exactly as read from the socket (codes included).
    $exim = [
        '250-mail.example.com ESMTP Exim 4.96 Ubuntu',
        '250-SIZE 52428800',
        '250-8BITMIME',
        '250-PIPELINING',
        '250-AUTH PLAIN LOGIN',
        '250-STARTTLS',
        '250 HELP',
    ];
    $caps = Mail::parseEhlo($exim);
    check(mechanisms($caps) === ['PLAIN', 'LOGIN'], 'Exim: AUTH PLAIN LOGIN is recognised after the reply code');
    check(isset($caps['STARTTLS']), 'Exim: STARTTLS is recognised after the reply code');
    check($caps['SIZE'] === ['52428800'], 'Exim: other capabilities keep their values');
    check(!isset($caps['250']), 'Exim: no capability name contains the reply code');
    check(!str_contains(implode(' ', array_keys($caps)), '250'), 'Exim: no reply-code keys at all');

    // 2. Old Exchange style writes the first mechanism after an equals sign.
    $caps = Mail::parseEhlo(['250-mail.example.com Hello', '250-AUTH=LOGIN PLAIN', '250 HELP']);
    check(mechanisms($caps) === ['LOGIN', 'PLAIN'], 'AUTH=LOGIN PLAIN keeps both mechanisms');

    // 3. Servers may split the mechanism list over several AUTH lines.
    $caps = Mail::parseEhlo(['250-host', '250-AUTH LOGIN', '250-AUTH PLAIN XOAUTH2', '250 HELP']);
    check(mechanisms($caps) === ['LOGIN', 'PLAIN', 'XOAUTH2'], 'split AUTH lines are merged without duplicates');

    // 4. Case does not matter.
    $caps = Mail::parseEhlo(['250-host', '250-auth login plain', '250-starttls']);
    check(mechanisms($caps) === ['LOGIN', 'PLAIN'], 'lower-case capability names are normalised');
    check(isset($caps['STARTTLS']), 'lower-case STARTTLS is normalised');

    // 5. A greeting-only reply (aiosmtpd style) yields no capabilities.
    $caps = Mail::parseEhlo(['250-relay.local']);
    check($caps === [], 'greeting-only reply has no capabilities');

    // 6. Empty input and odd spacing are harmless.
    check(Mail::parseEhlo([]) === [], 'empty reply is handled');
    $caps = Mail::parseEhlo(['250-host', '250-AUTH    PLAIN     LOGIN']);
    check(mechanisms($caps) === ['PLAIN', 'LOGIN'], 'extra spaces are tolerated');

    // 7. Mechanism preference: PLAIN first, then LOGIN; hidden AUTH is probed.
    check(Mail::authCandidates([]) === ['PLAIN', 'LOGIN'], 'no advertised AUTH still tries PLAIN then LOGIN');
    check(Mail::authCandidates(['LOGIN']) === ['LOGIN'], 'LOGIN-only servers use LOGIN');
    check(Mail::authCandidates(['LOGIN', 'PLAIN']) === ['PLAIN', 'LOGIN'], 'PLAIN is preferred when both are offered');

    // 8. A server that only offers an unsupported mechanism explains itself.
    try {
        Mail::authCandidates(['XOAUTH2', 'GSSAPI']);
        check(false, 'unsupported mechanisms are refused');
    } catch (MailError $e) {
        check($e->stage === 'auth' && $e->smtpCode === null && $e->retryable === false, 'unsupported mechanisms are refused');
        check(str_contains($e->getMessage(), 'XOAUTH2') && str_contains($e->getMessage(), 'PLAIN or LOGIN'), 'refusal names the advertised and supported mechanisms');
    }

    echo "SMTP capability tests passed.\n";
}
