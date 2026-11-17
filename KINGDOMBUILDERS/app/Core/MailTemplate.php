<?php
declare(strict_types=1);
namespace KB;

/**
 * Turns the plain-text notification stored in the mail queue into a modern,
 * responsive HTML email.
 *
 * The plain text stays the source of truth: it is what the text/plain part of
 * the message contains, and it is upgraded here with a heading, a single clear
 * button, detail rows and security notes. No images, scripts or remote assets
 * are used, every dynamic value is escaped, and the layout is table-based with
 * inline styles so it survives Outlook, Gmail and Apple Mail. A small <style>
 * block adds dark-mode colours and narrow-screen adjustments.
 */
final class MailTemplate {
    /** Renders the full HTML document for a queued message. */
    public static function html(string $subject, string $text, string $recipient = ''): string {
        $brand = self::brand();
        $items = self::items($text, $brand['title']);
        $heading = self::heading($subject);
        $cta = '';
        foreach ($items as $item) if ($item[0] === 'cta') $cta = $item[1];
        $eyebrow = self::eyebrow($subject, $cta);
        $preheader = self::preheader($items, $heading);
        $url = $brand['url'];
        $urlLabel = $url !== '' ? (string) (parse_url($url, PHP_URL_HOST) ?: $url) : $brand['title'];
        $footLink = $url !== '' ? '<a href="' . self::esc($url) . '" class="kb-foot-link" style="color:#39735b;text-decoration:none;font-weight:600;">' . self::esc($urlLabel) . '</a>' : self::esc($urlLabel);
        $recipientLine = $recipient !== '' ? ' · Sent to ' . self::esc($recipient) : '';
        $initial = $brand['initial'];
        $title = self::esc($brand['title']);
        $headingHtml = self::esc($heading);
        $body = self::renderItems($items);
        $year = gmdate('Y');
        $css = self::css();

        return <<<HTML
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{$headingHtml}</title>
<style>{$css}</style>
</head>
<body class="kb-bg" style="margin:0;padding:0;width:100%;background:#eef2ec;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:#eef2ec;">{$preheader}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="kb-bg" bgcolor="#eef2ec" style="background:#eef2ec;">
  <tr>
    <td align="center" style="padding:30px 12px 38px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="kb-container" style="width:600px;max-width:600px;">
        <tr>
          <td style="padding:0 4px 16px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr>
                <td width="46" valign="middle">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" aria-hidden="true"><tr>
                    <td width="44" height="46" align="center" valign="middle" bgcolor="#142d3a" class="kb-mark" style="width:44px;height:46px;background:#142d3a;border-radius:10px 10px 18px 18px;color:#e6bd62;font-family:Georgia,'Times New Roman',serif;font-size:29px;line-height:46px;text-align:center;mso-line-height-rule:exactly;">{$initial}</td>
                  </tr></table>
                </td>
                <td valign="middle" style="padding-left:13px;">
                  <div class="kb-title" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:800;letter-spacing:1.6px;line-height:1.3;color:#142d3a;text-transform:uppercase;">{$title}</div>
                  <div class="kb-tag" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:9px;font-weight:600;letter-spacing:2.1px;line-height:1.9;color:#7b8a82;text-transform:uppercase;">Faith · Knowledge · Growth</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td class="kb-card" bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e2e8e1;border-radius:18px;box-shadow:0 18px 40px rgba(16,38,50,0.08);">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-radius:18px;overflow:hidden;">
              <tr><td height="5" class="kb-accent" bgcolor="#142d3a" style="height:5px;line-height:5px;font-size:0;background:#142d3a;background-image:linear-gradient(90deg,#142d3a 0%,#39735b 58%,#e6bd62 100%);">&nbsp;</td></tr>
              <tr>
                <td class="kb-pad" style="padding:32px 38px 0;">
                  <div class="kb-eyebrow" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:10px;font-weight:800;letter-spacing:2.1px;line-height:1.4;color:#39735b;text-transform:uppercase;">{$eyebrow}</div>
                  <h1 class="kb-h1" style="margin:10px 0 0;font-family:Georgia,'Times New Roman',serif;font-size:27px;line-height:1.3;font-weight:400;letter-spacing:-0.5px;color:#142d3a;">{$headingHtml}</h1>
                  <div style="height:22px;line-height:22px;font-size:0;">&nbsp;</div>
                  <div class="kb-rule" style="height:1px;background:#edf1e9;font-size:0;line-height:0;">&nbsp;</div>
                </td>
              </tr>
              <tr><td class="kb-pad" style="padding:22px 38px 34px;">{$body}</td></tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 28px 0;text-align:center;">
            <p class="kb-foot" style="margin:0 0 7px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:11.5px;line-height:1.75;color:#7d8d85;">{$footLink}{$recipientLine}</p>
            <p class="kb-foot" style="margin:0 0 6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:11.5px;line-height:1.75;color:#7d8d85;">This is an automated message, so replies are not monitored.</p>
            <p class="kb-foot-faint" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:10.5px;line-height:1.7;color:#96a29b;">© {$year} {$title}. All rights reserved.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }

    /** Subject "Site · Action" becomes the visible heading "Action". */
    public static function heading(string $subject): string {
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
        if ($subject === '') return 'Notification';
        $parts = preg_split('/\s*[·|—–]\s*/u', $subject) ?: [];
        $heading = trim((string) end($parts));
        return $heading !== '' ? $heading : $subject;
    }

    /** @return array{title:string,initial:string,url:string} */
    private static function brand(): array {
        $title = trim((string) Settings::get('site_title'));
        if ($title === '') $title = 'Notification';
        $url = rtrim(trim((string) config('site_url')), '/');
        if ($url !== '' && !self::safeUrl($url)) $url = '';
        $initial = mb_strtoupper(mb_substr($title, 0, 1));
        if ($initial === '') $initial = '•';
        return ['title' => $title, 'initial' => self::esc($initial), 'url' => $url];
    }

    private static function eyebrow(string $subject, string $cta): string {
        $path = strtolower((string) parse_url($cta, PHP_URL_PATH));
        if (str_contains($path, 'reset-password') || str_contains($path, 'verify-email')) return 'Account security';
        if (str_contains($path, 'quiz') || str_contains($path, 'result')) return 'Assessment';
        if (preg_match('/\bwelcome\b/i', $subject)) return 'Welcome';
        return 'Notification';
    }

    /**
     * Split the message into renderable items, keeping the author's order.
     * @return array<int,array{0:string,1:mixed,2?:string}>
     */
    private static function items(string $text, string $siteTitle): array {
        $items = [];
        $salutation = false;
        $cta = false;
        foreach (self::blocks($text) as $index => $block) {
            if (trim($block) === $siteTitle) continue;
            if (!$salutation && $index === 0 && preg_match('/^Hello\s+[^,\n]{1,80},$/u', $block)) {
                $items[] = ['salutation', $block];
                $salutation = true;
                continue;
            }
            if (!$cta && ($link = self::extractCta($block)) !== null) {
                $items[] = ['cta', $link['url'], $link['hint']];
                $cta = true;
                continue;
            }
            if (self::isDetails($block)) {
                $items[] = ['details', self::detailRows($block)];
                continue;
            }
            if (self::isNote($block)) {
                $items[] = ['note', $block];
                continue;
            }
            $items[] = ['p', $block];
        }
        // Consecutive notes (or detail rows) read better as one panel.
        $merged = [];
        foreach ($items as $item) {
            $last = count($merged) - 1;
            if ($last >= 0 && $item[0] === 'note' && $merged[$last][0] === 'note') {
                $merged[$last][1] .= "\n" . $item[1];
            } elseif ($last >= 0 && $item[0] === 'details' && $merged[$last][0] === 'details') {
                $merged[$last][1] = array_merge($merged[$last][1], $item[1]);
            } else {
                $merged[] = $item;
            }
        }
        return $merged;
    }

    /** @return array<int,string> */
    private static function blocks(string $text): array {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = preg_split('/\n\s*\n/', trim($text)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
    }

    /** @return array<int,string> */
    private static function urls(string $text): array {
        if (!preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches)) return [];
        return array_map(static fn(string $url): string => rtrim($url, ".,;:!?)]}'"), $matches[0]);
    }

    /** @return array{url:string,hint:string}|null */
    private static function extractCta(string $block): ?array {
        $urls = self::urls($block);
        if (count($urls) !== 1 || !self::safeUrl($urls[0])) return null;
        $url = $urls[0];
        $rest = trim(str_replace($url, '', $block));
        $rest = trim($rest, " \t\n\r\0\x0B:;,.–—-");
        // A button needs a link that stands alone, optionally after a short label.
        if ($rest !== '' && (mb_strlen($rest) > 90 || preg_match('/[.!?]\s/u', $rest))) return null;
        return ['url' => $url, 'hint' => $rest];
    }

    private static function ctaLabel(string $url): string {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (str_contains($path, 'reset-password')) return 'Choose a new password';
        if (str_contains($path, 'verify-email')) return 'Confirm email address';
        if (str_contains($path, 'quiz')) return 'Continue your assessment';
        if (str_contains($path, 'result')) return 'View your result';
        if (str_contains($path, 'login')) return 'Sign in';
        if (str_contains($path, 'install')) return 'Continue setup';
        return 'Open link';
    }

    private static function isDetails(string $block): bool {
        $lines = preg_split('/\n/', trim($block)) ?: [];
        if ($lines === []) return false;
        foreach ($lines as $line) {
            if (!preg_match('/^([A-Z][A-Za-z0-9 ]{1,28}):\s+(\S.{0,110})$/u', $line, $m)) return false;
            if (preg_match('/[.!?]$/u', $m[2])) return false;
        }
        return true;
    }

    /** @return array<int,array{label:string,value:string}> */
    private static function detailRows(string $block): array {
        $rows = [];
        foreach (preg_split('/\n/', trim($block)) ?: [] as $line) {
            [$label, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            if ($label === '' || $value === '') continue;
            if (strtolower($label) === 'activity') $value = ucfirst(str_replace(['.', '_'], ' ', $value));
            if (strtolower($label) === 'security') $value = strtoupper($value);
            if (strtolower($label) === 'time' && ($timestamp = strtotime($value)) !== false) $value = date('j M Y, H:i', $timestamp) . ' UTC';
            $rows[] = ['label' => $label, 'value' => $value];
        }
        return $rows;
    }

    private static function isNote(string $block): bool {
        return (bool) preg_match('/^(This link expires|This code expires|If you did not request|If you didn’t request|If you didn.t request|If this was not|If this wasn.t|If this wasn’t|Never share|You can safely ignore|If you weren.t expecting)/iu', $block);
    }

    /** @param array<int,array{0:string,1:mixed,2?:string}> $items */
    private static function renderItems(array $items): string {
        $html = '';
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        foreach ($items as $item) {
            $type = $item[0];
            if ($type === 'salutation') {
                $html .= '<p class="kb-lead" style="margin:0 0 14px;font-family:' . $font . ';font-size:16px;line-height:1.7;font-weight:650;color:#17313c;">' . self::esc((string) $item[1]) . '</p>';
            } elseif ($type === 'p') {
                $html .= '<p class="kb-p" style="margin:0 0 16px;font-family:' . $font . ';font-size:15px;line-height:1.8;color:#3b5057;word-break:break-word;">' . nl2br(self::format((string) $item[1]), false) . '</p>';
            } elseif ($type === 'note') {
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="kb-note" style="margin:0 0 14px;background:#fcf7ea;border-left:3px solid #e6bd62;border-radius:0 10px 10px 0;"><tr><td class="kb-note-text" style="padding:12px 15px;font-family:' . $font . ';font-size:13px;line-height:1.75;color:#6c5a2d;">' . nl2br(self::format((string) $item[1]), false) . '</td></tr></table>';
            } elseif ($type === 'details') {
                $html .= self::detailsPanel($item[1], $font);
            } elseif ($type === 'cta') {
                $url = (string) $item[1];
                $hint = (string) ($item[2] ?? '');
                $label = self::ctaLabel($url);
                $hintHtml = $hint !== '' ? '<p class="kb-p" style="margin:0 0 14px;font-family:' . $font . ';font-size:15px;line-height:1.8;color:#3b5057;">' . self::esc($hint) . '</p>' : '';
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:2px 0 18px;"><tr><td>'
                    . $hintHtml
                    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="kb-btn"><tr>'
                    . '<td align="center" bgcolor="#142d3a" class="kb-btn-cell" style="border-radius:11px;background:#142d3a;">'
                    . '<a href="' . self::esc($url) . '" class="kb-btn-a" style="display:inline-block;padding:15px 30px;font-family:' . $font . ';font-size:15px;font-weight:700;letter-spacing:0.2px;color:#ffffff;text-decoration:none;border-radius:11px;">' . self::esc($label) . ' <span style="font-weight:400;">&rarr;</span></a>'
                    . '</td></tr></table>'
                    . '<p class="kb-small" style="margin:15px 0 0;font-family:' . $font . ';font-size:11px;line-height:1.75;color:#8b9a93;">Button not working? Copy this address into your browser:<br><a href="' . self::esc($url) . '" class="kb-small-link" style="color:#39735b;text-decoration:underline;word-break:break-all;">' . self::esc($url) . '</a></p>'
                    . '</td></tr></table>';
            }
        }
        return $html;
    }

    /** @param array<int,array{label:string,value:string}> $rows */
    private static function detailsPanel(array $rows, string $font): string {
        if ($rows === []) return '';
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="kb-detail" style="margin:2px 0 20px;background:#f5f8f2;border:1px solid #e7ede1;border-radius:12px;"><tr><td style="padding:8px 18px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">';
        foreach ($rows as $index => $row) {
            $border = $index > 0 ? 'border-top:1px solid #e7ede1;' : '';
            $html .= '<tr>'
                . '<td width="34%" valign="top" class="kb-label" style="padding:10px 12px 10px 0;' . $border . 'font-family:' . $font . ';font-size:9.5px;font-weight:800;letter-spacing:1.4px;line-height:1.6;text-transform:uppercase;color:#7d8d85;">' . self::esc($row['label']) . '</td>'
                . '<td valign="top" class="kb-value" style="padding:10px 0;' . $border . 'font-family:' . $font . ';font-size:13.5px;line-height:1.65;color:#17313c;">' . self::format($row['value']) . '</td>'
                . '</tr>';
        }
        return $html . '</table></td></tr></table>';
    }

    /** @param array<int,array{0:string,1:mixed,2?:string}> $items */
    private static function preheader(array $items, string $heading): string {
        $text = '';
        foreach ($items as $item) {
            if ($item[0] === 'p') { $text = (string) $item[1]; break; }
        }
        if ($text === '') {
            foreach ($items as $item) {
                if ($item[0] === 'cta' && trim((string) ($item[2] ?? '')) !== '') { $text = (string) $item[2]; break; }
            }
        }
        if ($text === '') $text = $heading;
        $text = preg_replace('#https?://\S+#i', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') $text = $heading;
        if (mb_strlen($text) > 140) $text = rtrim(mb_substr($text, 0, 137)) . '…';
        return self::esc($text);
    }

    /** Escape a plain-text fragment, then turn bare URLs into links. */
    private static function format(string $text): string {
        $escaped = self::esc($text);
        return preg_replace_callback('#https?://[^\s<]+#i', static function (array $match): string {
            $url = rtrim(html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), ".,;:!?)]}'");
            if (!self::safeUrl($url)) return $match[0];
            $trailing = substr($match[0], strlen($url));
            return '<a href="' . self::esc($url) . '" class="kb-link" style="color:#2f634d;text-decoration:underline;">' . self::esc($url) . '</a>' . $trailing;
        }, $escaped) ?? $escaped;
    }

    private static function safeUrl(string $url): bool {
        return (bool) preg_match('~^https?://[a-z0-9.-]+(?::\d+)?(?:[/?#]\S*)?$~i', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function esc(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Inline <style>: progressive dark mode and small-screen tuning. */
    private static function css(): string {
        return <<<'CSS'
body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
table{border-collapse:collapse;mso-table-lspace:0;mso-table-rspace:0}
img{border:0;outline:none;text-decoration:none}
a{text-decoration:none}
.kb-link{color:#2f634d;text-decoration:underline}
@media(max-width:620px){
  .kb-container{width:100%!important;max-width:100%!important}
  .kb-pad{padding-left:22px!important;padding-right:22px!important}
  .kb-h1{font-size:23px!important}
  .kb-btn,.kb-btn-cell{width:100%!important}
  .kb-btn-a{display:block!important;padding:14px 18px!important;text-align:center!important}
  .kb-small{font-size:10.5px!important}
  .kb-power{padding:0 6px!important}
}
@media(prefers-color-scheme:dark){
  .kb-bg{background:#0b1a21!important}
  .kb-card{background:#102630!important;border-color:#1d3a44!important}
  .kb-title,.kb-h1,.kb-lead{color:#eef4f1!important}
  .kb-tag,.kb-label,.kb-foot,.kb-foot-faint{color:#9fb0a9!important}
  .kb-p{color:#c3d0ca!important}
  .kb-rule{background:#1d3a44!important}
  .kb-detail{background:#0d2029!important;border-color:#1d3a44!important}
  .kb-value{color:#e7eeeb!important}
  .kb-detail .kb-label{border-color:#1d3a44!important}
  .kb-detail td{border-color:#1d3a44!important}
  .kb-note{background:#1b2417!important;border-left-color:#e6bd62!important}
  .kb-note-text{color:#e3d6ae!important}
  .kb-foot-link,.kb-small-link{color:#e6bd62!important}
  .kb-small{color:#9fb0a9!important}
  .kb-btn-cell{background:#e6bd62!important}
  .kb-btn-a{color:#142d3a!important}
  .kb-accent{background:#e6bd62!important;background-image:none!important}
  .kb-mark{background:#e6bd62!important;color:#142d3a!important}
  .kb-link{color:#9ed3b8!important}
}
CSS;
    }
}
