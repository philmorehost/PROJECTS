<?php
declare(strict_types=1);
/**
 * HTML email rendering and MIME tests. No database, no network.
 * Run with: php tests/mail_html.php
 */
namespace KB {
    final class Settings {
        /** @var array<string,mixed> */
        public static array $values = ['site_title' => 'KINGDOMBUILDERS'];
        public static function get(string $key, mixed $default = null): mixed { return self::$values[$key] ?? $default; }
    }
    final class Security {
        public static function domain(): string { return 'example.test'; }
    }
}

namespace {
    use KB\Mail;
    use KB\MailTemplate;
    use KB\Settings;

    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

    function config(string $key, mixed $default = null): mixed {
        return $key === 'site_url' ? 'https://gamio.pmhserver.name.ng' : $default;
    }

    require_once dirname(__DIR__) . '/app/Core/MailError.php';
    require_once dirname(__DIR__) . '/app/Core/MailTemplate.php';
    require_once dirname(__DIR__) . '/app/Core/Mail.php';

    function check(bool $condition, string $label, string $detail = ''): void {
        if (!$condition) { fwrite(STDERR, "FAIL {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n"); exit(1); }
        echo "PASS {$label}\n";
    }

    function bodyOf(string $html): string {
        return preg_match('/<td class="kb-pad" style="padding:22px 38px 34px;">(.*?)<\/td>\s*<\/tr>/s', $html, $m) ? $m[1] : '';
    }

    $reset = "Hello Ada Obi,\n\nUse this single-use link to continue:\nhttps://gamio.pmhserver.name.ng/reset-password?token=abc123\n\nThis link expires in 1 hour. If you did not request it, you can ignore this message.\n\nNever share this link with anyone.";
    $html = MailTemplate::html('KINGDOMBUILDERS · Choose a new password', $reset, 'ada@example.test');

    // 1. Document shell and client compatibility.
    check(str_contains($html, '<!doctype html>') && str_contains($html, '<html lang="en"'), 'HTML document with language');
    check(str_contains($html, 'name="viewport"'), 'mobile viewport meta');
    check(str_contains($html, 'name="color-scheme" content="light dark"'), 'declares colour schemes');
    check(str_contains($html, 'prefers-color-scheme:dark'), 'dark-mode stylesheet');
    check(str_contains($html, '@media(max-width:620px)'), 'narrow-screen stylesheet');
    check(substr_count($html, 'role="presentation"') >= 6, 'tables are presentational');
    check(!str_contains($html, '<script') && !str_contains($html, '<img') && !str_contains($html, '<link'), 'no scripts, images or remote assets');

    // 2. Brand, heading and hierarchy.
    check(str_contains($html, 'class="kb-mark"') && str_contains($html, '>K</td>'), 'monogram uses the first letter of the site title');
    check(str_contains($html, '>KINGDOMBUILDERS</div>'), 'brand title is shown');
    check(str_contains($html, 'Faith · Knowledge · Growth'), 'brand tagline is shown');
    check(str_contains($html, '>Choose a new password</h1>'), 'subject after the separator becomes the heading');
    check(str_contains($html, 'Account security'), 'reset link is labelled as account security');
    check(str_contains($html, 'Hello Ada Obi,'), 'salutation is preserved');

    // 3. One clear action with a plain address beneath it.
    check(str_contains($html, 'href="https://gamio.pmhserver.name.ng/reset-password?token=abc123"'), 'button links to the single-use URL');
    check(str_contains($html, '>Choose a new password <span'), 'button label matches the task');
    check(str_contains($html, 'Button not working? Copy this address'), 'plain URL fallback accompanies the button');
    check(!str_contains($html, 'href="https://gamio.pmhserver.name.ng/reset-password?token=abc123" class="kb-link"'), 'no duplicate inline link for the primary action');

    // 4. Notes are merged, not repeated as stacked boxes.
    check(substr_count($html, 'class="kb-note"') === 1, 'consecutive notes share one panel');
    check(str_contains($html, 'This link expires in 1 hour.'), 'expiry note is kept');
    check(str_contains($html, 'Never share this link with anyone.'), 'security note is kept');

    // 5. Preheader and footer.
    check(str_contains($html, 'Use this single-use link to continue&nbsp;&zwnj;'), 'hidden preheader summarises the message');
    check(str_contains($html, 'Sent to ada@example.test'), 'footer names the recipient');
    check(str_contains($html, 'This is an automated message'), 'footer explains the message is automated');
    check(str_contains($html, '© ' . gmdate('Y') . ' KINGDOMBUILDERS'), 'footer carries the current year');

    // 6. Activity mail: details panel, friendly time, no signature line.
    $activity = "Hello Ada Obi,\n\nYour assessment result is ready: 12 out of 15 (80%).\n\nActivity: assessment.completed\nTime: 2026-11-02 19:30:00\n\nIf this was not expected, contact your administrator.\n\nKINGDOMBUILDERS";
    $act = MailTemplate::html('KINGDOMBUILDERS · Assessment completed', $activity, 'ada@example.test');
    check(str_contains($act, '>Assessment completed</h1>'), 'activity heading');
    check(str_contains($act, '>Activity</td>') && str_contains($act, '>Assessment completed</td>'), 'activity row is humanised');
    check(str_contains($act, '2 Nov 2026, 19:30 UTC'), 'timestamps are humanised');
    check(substr_count($act, 'class="kb-detail"') === 1, 'detail rows share one panel');
    check(!str_contains(bodyOf($act), 'KINGDOMBUILDERS'), 'site-name signature is removed from the body');

    // 7. Untrusted text and URLs are escaped.
    Settings::$values['site_title'] = '<b>Church</b> & Co';
    $xss = "Hello <script>alert(1)</script>,\n\nVisit https://example.test/x?q=\"><script>alert(2)</script> or javascript:alert(3)\n\nTime: 2026-11-02 10:00:00";
    $bad = MailTemplate::html('Site · <em>Test</em>', $xss, 'person+tag@example.test');
    Settings::$values['site_title'] = 'KINGDOMBUILDERS';
    check(!str_contains($bad, '<script') && !str_contains($bad, '<em>'), 'markup in text and subject is escaped');
    check(str_contains($bad, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'plain-text markup is rendered literally');
    check(!str_contains($bad, 'href="javascript:'), 'javascript URLs never become links');
    check(str_contains($bad, '&amp; Co'), 'ampersands are escaped in the brand');
    check(str_contains($bad, 'person+tag@example.test'), 'recipient address is shown verbatim');

    // 8. MIME: multipart/alternative, both parts, dot-stuffing, no header injection.
    $mimeBody = "First line\n.leading dot\nUnicode: Ẹ̀kọ́";
    $mime = Mail::message('ada@example.test', "Reset\r\nBcc: evil@example.test", $mimeBody, 'notifications@example.test', 'KINGDOMBUILDERS', 'fixed@example.test');
    check(str_contains($mime, 'Content-Type: multipart/alternative; boundary="=_kb_'), 'message is multipart/alternative');
    check(str_contains($mime, 'Content-Type: text/plain; charset=UTF-8') && str_contains($mime, 'Content-Type: text/html; charset=UTF-8'), 'text and HTML parts are present');
    check(str_contains($mime, 'Auto-Submitted: auto-generated'), 'message is marked automated');
    check(str_contains($mime, "\r\n.leading dot\r\n"), 'leading dots survive in the composed message');
    check(Mail::stuff("a\r\n.b\r\n..c\r\n") === "a\r\n..b\r\n...c\r\n", 'SMTP transparency doubles leading dots');
    check(!str_contains($mime, "\r\nBcc:"), 'subject line breaks cannot inject headers');
    check(str_contains($mime, 'Subject: "Reset Bcc: evil@example.test"') || str_contains($mime, 'Subject: "Reset'), 'plain subjects are quoted');
    $unicode = Mail::message('ada@example.test', 'KINGDOMBUILDERS · Weekly reading', 'Body', 'notifications@example.test', 'KINGDOMBUILDERS');
    check(str_contains($unicode, 'Subject: =?UTF-8?B?'), 'non-ASCII subject is encoded');

    preg_match('/boundary="([^"]+)"/', $mime, $boundary);
    $parts = explode('--' . $boundary[1], $mime);
    check(count($parts) === 4, 'two MIME parts plus the closing boundary');
    [$plainHeaders, $plainBody] = explode("\r\n\r\n", $parts[1], 2);
    [$htmlHeaders, $htmlBody] = explode("\r\n\r\n", $parts[2], 2);
    check(str_contains($plainHeaders, 'text/plain') && str_contains($plainBody, 'Unicode: Ẹ̀kọ́'), 'plain part keeps the original text');
    check(str_contains($htmlHeaders, 'quoted-printable'), 'HTML part is quoted-printable');
    $decoded = quoted_printable_decode($htmlBody);
    check(str_contains($decoded, '<!doctype html>') && str_contains($decoded, 'Unicode: Ẹ̀kọ́'), 'HTML part decodes to the designed document');
    check(str_contains($decoded, '.kb-h1{') && str_contains($decoded, '.kb-btn-a{'), 'styles are intact after transfer encoding');
    check(!preg_match('/(?<!\r)\n/', $mime), 'message uses CRLF line endings');

    // 9. Heading helper.
    check(MailTemplate::heading('Site · Choose a new password') === 'Choose a new password', 'heading uses the last subject segment');
    check(MailTemplate::heading('Welcome to KINGDOMBUILDERS') === 'Welcome to KINGDOMBUILDERS', 'heading keeps subjects without a separator');
    check(MailTemplate::heading('') === 'Notification', 'empty subject gets a fallback heading');

    echo "HTML email tests passed.\n";
}
