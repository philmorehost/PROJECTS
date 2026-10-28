<?php
/**
 * License Manager — Update notifications.
 *
 * Queues and delivers "a new version is available" emails to licensed
 * customers, then sends ONE reminder the next day to anyone who has not
 * updated yet (configurable interval + count).
 *
 * "Has the customer updated?" is decided from the version the target script
 * reports to check-update.php (`licensed_domains.installed_version`), and
 * optionally from whether they already downloaded the package.
 *
 * PHP 7.4 compatible.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/updates.php';

if (!function_exists('lm_changelog_sanitize')) {
    /**
     * Remove active content from admin-authored changelog HTML.
     *
     * @param string $html
     * @return string
     */
    function lm_changelog_sanitize($html)
    {
        $html = (string) $html;

        // Strip dangerous blocks entirely.
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|button|link|meta)\b[^>]*/?\s*>#is', '', $html);

        // Strip event handlers and javascript: URLs.
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/javascript\s*:/i', '', $html);
        $html = preg_replace('/expression\s*\(/i', '(', $html);

        return trim($html);
    }
}

if (!function_exists('lm_changelog_from_text')) {
    /**
     * Convert a plain-text changelog into clean HTML.
     *
     * @param string $text
     * @return string
     */
    function lm_changelog_from_text($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
        $lines = explode("\n", $text);

        $html = '';
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                continue;
            }

            $isBullet = (bool) preg_match('/^([-*•]|\d+[.)])\s+/u', $trimmed);
            $item = preg_replace('/^([-*•]|\d+[.)])\s+/u', '', $trimmed);
            $item = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
            $item = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $item);
            $item = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $item);

            if ($isBullet) {
                if (!$inList) {
                    $html .= '<ul>';
                    $inList = true;
                }
                $html .= '<li>' . $item . '</li>';
            } else {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                $html .= '<p>' . $item . '</p>';
            }
        }

        if ($inList) {
            $html .= '</ul>';
        }

        return $html !== '' ? $html : '<p>Performance improvements and bug fixes.</p>';
    }
}

if (!function_exists('lm_changelog_html')) {
    /**
     * @param string $changelog
     * @param string $format html|text
     * @return string
     */
    function lm_changelog_html($changelog, $format = 'html')
    {
        if (trim((string) $changelog) === '') {
            return '<p>Performance improvements and bug fixes.</p>';
        }
        if ($format === 'text') {
            return lm_changelog_from_text($changelog);
        }
        return lm_changelog_sanitize($changelog);
    }
}

if (!function_exists('lm_changelog_plain')) {
    /**
     * Plain-text changelog for the AltBody.
     *
     * @param string $changelog
     * @param string $format
     * @return string
     */
    function lm_changelog_plain($changelog, $format = 'html')
    {
        $changelog = (string) $changelog;
        if (trim($changelog) === '') {
            return 'Performance improvements and bug fixes.';
        }
        if ($format === 'text') {
            return $changelog;
        }

        $text = preg_replace('#<\s*li[^>]*>#i', "- ", $changelog);
        $text = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
        $text = preg_replace('#<\s*/\s*(p|li|h[1-6]|div|ul|ol)\s*>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}

if (!function_exists('lm_update_email_site_logo')) {
    /**
     * Resolve the configured logo to a public URL (or '' when missing).
     *
     * @return string
     */
    function lm_update_email_site_logo()
    {
        $settings = lm_settings();
        $logo = trim((string) ($settings['site_logo'] ?? ''));
        if ($logo === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $logo)) {
            return $logo;
        }

        $relative = ltrim(str_replace('\\', '/', $logo), '/');
        if (!is_file(lm_lm_dir() . DIRECTORY_SEPARATOR . $relative)) {
            return '';
        }

        return lm_site_url($relative);
    }
}

if (!function_exists('lm_email_domain_clean')) {
    /**
     * Turn whatever is stored in `licenses.domain` into a bare hostname.
     *
     * Purchases arrive from a payment gateway, and the metadata is written by
     * hand: "https://shop.example.com/", "shop.example.com:443",
     * "www.shop.example.com" and "SHOP.EXAMPLE.COM" all mean the same site.
     *
     * @param mixed $value
     * @return string Empty when the value is not a usable domain
     */
    function lm_email_domain_clean($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        // Strip a pasted URL down to its host.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : '';
        }

        $value = preg_replace('#^//#', '', (string) $value);
        $value = preg_split('~[/?#]~', $value);
        $value = is_array($value) ? $value[0] : (string) $value;

        // Drop a port and any userinfo.
        $value = preg_replace('#^.*@#', '', $value);
        $value = preg_replace('#:\d+$#', '', $value);
        $value = rtrim(trim($value), '.');

        $value = strtolower($value);

        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value)) {
            return '';
        }

        return $value;
    }
}

if (!function_exists('lm_email_domain_is_placeholder')) {
    /**
     * Values that look like a domain but are not the customer's site.
     *
     * Older purchases stored "N/A" or the field was typed by hand during
     * testing, and the template used to print whatever was there — which is
     * how "yourdomain.com" ended up in a release email.
     *
     * @param string $domain Already cleaned domain
     * @return bool
     */
    function lm_email_domain_is_placeholder($domain)
    {
        $domain = strtolower(trim((string) $domain));
        if ($domain === '') {
            return true;
        }

        $exact = array(
            'n/a', 'na', 'none', 'null', 'unknown', 'localhost', 'test', 'demo',
            'example.com', 'example.org', 'example.net', 'test.com', 'domain.com',
            'site.com', 'website.com', 'yoursite.com', 'your-domain.com',
            'yourwebsite.com', 'yourdomain.com', 'mydomain.com', 'mysite.com',
            'sample.com', 'placeholder.com', 'email.com', 'admin.com',
        );

        $bare = preg_replace('#^www\.#', '', $domain);
        if (in_array($bare, $exact, true)) {
            return true;
        }

        // RFC 2606 / RFC 6761 reserved names, and anything that ends in them.
        foreach (array('.example.com', '.example.org', '.example.net', '.test', '.invalid', '.localhost') as $suffix) {
            if (substr($bare, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        // Loopback and private-network hosts are never a customer's public site.
        if (preg_match('#^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)#', $bare)
            || preg_match('#\.(local|localdomain|internal)$#', $bare)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('lm_license_domain_info')) {
    /**
     * Work out which site a licence email is about.
     *
     * `licenses.domain` is only what the gateway happened to receive. The sites
     * that really run the script are the ones that called `check-update.php`
     * and were registered in `licensed_domains` — that list is the accurate
     * source, so it is consulted when the stored value is missing or is a
     * leftover placeholder.
     *
     * @param PDO|null $pdo      Pass null to skip the licensed_domains lookup
     * @param array    $license  licenses row (may be partial)
     * @return array{known:bool,primary:string,display:string,all:array,note:string,prose:string}
     */
    function lm_license_domain_info($pdo, array $license)
    {
        static $cache = array();

        $cacheKey = (int) ($license['id'] ?? 0) . '|' . strtolower(trim((string) ($license['domain'] ?? '')));
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $candidates = array();
        $seen = array();

        $add = function ($value) use (&$candidates, &$seen) {
            $clean = lm_email_domain_clean($value);
            if ($clean === '' || lm_email_domain_is_placeholder($clean)) {
                return;
            }
            $key = preg_replace('#^www\.#', '', $clean);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $candidates[] = preg_replace('#^www\.#', '', $clean);
        };

        // 1. The domain stored on the licence.
        $add($license['domain'] ?? '');

        // 2. Every domain that has actually checked in for this licence.
        $licenseId = (int) ($license['id'] ?? 0);
        if ($pdo instanceof PDO && $licenseId > 0) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT domain_name FROM licensed_domains
                      WHERE license_id = ?
                      ORDER BY last_update_check DESC, id DESC"
                );
                $stmt->execute(array($licenseId));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $domainName) {
                    $add($domainName);
                }
            } catch (PDOException $e) {
                // Table missing or a partial install — the licence value stands.
            } catch (Throwable $e) {
                // Same.
            }
        }

        if (empty($candidates)) {
            $cache[$cacheKey] = array(
                'known' => false,
                'primary' => '',
                'display' => 'your website',
                'all' => array(),
                'note' => '',
                'prose' => 'your website',
            );
            return $cache[$cacheKey];
        }

        $primary = $candidates[0];
        $extra = count($candidates) - 1;
        $note = $extra > 0
            ? '+' . $extra . ' more ' . ($extra === 1 ? 'domain' : 'domains') . ' on this licence'
            : '';

        $cache[$cacheKey] = array(
            'known' => true,
            'primary' => $primary,
            'display' => $primary,
            'all' => $candidates,
            'note' => $note,
            'prose' => $primary,
        );
        return $cache[$cacheKey];
    }
}

if (!function_exists('lm_preview_license')) {
    /**
     * Pick a realistic licence for an email preview.
     *
     * The preview used to be rendered with a hard-coded `yourdomain.com`,
     * which is exactly what leaked into a real-looking screenshot. Use the
     * customer being previewed when given, otherwise a real recipient that has
     * a domain, otherwise any recipient on the project.
     *
     * @param PDO   $pdo
     * @param array $update    script_updates row
     * @param int   $licenseId Preferred licence
     * @return array{id:int,domain:string,customer_email:string}
     */
    function lm_preview_license(PDO $pdo, array $update, $licenseId = 0)
    {
        $fallback = array('id' => 0, 'domain' => '', 'customer_email' => 'customer@example.com');

        if ($licenseId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ?");
                $stmt->execute(array((int) $licenseId));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            } catch (PDOException $e) {
                // Fall through to the automatic pick.
            }
        }

        $tierId = (int) ($update['tier_id'] ?? 0);
        if ($tierId <= 0) {
            return $fallback;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM licenses
                  WHERE tier_id = ? AND status = 'active'
                    AND customer_email IS NOT NULL AND customer_email <> ''
                  ORDER BY id ASC
                  LIMIT 25"
            );
            $stmt->execute(array($tierId));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $fallback;
        }

        $firstRow = null;
        foreach ($rows as $row) {
            if ($firstRow === null) {
                $firstRow = $row;
            }
            $info = lm_license_domain_info($pdo, $row);
            if (!empty($info['known'])) {
                return $row;
            }
        }

        return $firstRow ? $firstRow : $fallback;
    }
}

if (!function_exists('lm_update_email_options')) {
    /**
     * The "how do I update?" block used inside the customer email.
     *
     * Two routes, in the order customers actually use them:
     *   1. the script's own admin area — one button, no files to move;
     *   2. a daily cron job in cPanel / Plesk / DirectAdmin that updates itself.
     *
     * The wording can be replaced per installation from settings.json with
     * `update_instructions_admin` and `update_instructions_cron`.
     *
     * @param array  $tier   script_tiers row
     * @param string $version
     * @param array  $domain Domain info from lm_license_domain_info()
     * @return array{html:string,text:string,cron:string}
     */
    function lm_update_email_options(array $tier, $version, array $domain)
    {
        $settings = lm_settings();
        $project = trim((string) ($tier['tier_name'] ?? 'your script'));
        $project = $project !== '' ? $project : 'your script';

        $cron = '0 1 * * * /usr/local/bin/php /home/USER/public_html/bc-spadmin/cron_update.php >> /home/USER/ota_updates.log 2>&1';

        $adminOverride = trim((string) ($settings['update_instructions_admin'] ?? ''));
        $cronOverride = trim((string) ($settings['update_instructions_cron'] ?? ''));

        $adminText = $adminOverride !== ''
            ? $adminOverride
            : 'Sign in to ' . $project . ' as the super admin, open <strong>System Update Centre</strong> '
                . 'and press <strong>Install Update Automatically</strong>. The script downloads v' . $version
                . ', backs up your files and database first, and rolls back on its own if anything fails.';

        $cronText = $cronOverride !== ''
            ? $cronOverride
            : 'Prefer it hands-free? In <strong>cPanel &rarr; Cron Jobs</strong> (Plesk and DirectAdmin work the same way) '
                . 'add one daily job. It checks for a release, installs it and e-mails you the result &mdash; '
                . 'no dashboard visit needed.';

        $escAdmin = $adminOverride !== '' ? nl2br(htmlspecialchars($adminOverride, ENT_QUOTES, 'UTF-8')) : $adminText;
        $escCron = $cronOverride !== '' ? nl2br(htmlspecialchars($cronOverride, ENT_QUOTES, 'UTF-8')) : $cronText;

        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

        $option = function ($number, $tone, $title, $body, $code, $foot) use ($font) {
            $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="lm-surface" '
                . 'style="border:1px solid #e6ebf5; border-left:4px solid ' . $tone . '; border-radius:14px; background-color:#fbfcff;">'
                . '<tr><td style="padding:18px 20px;">'
                . '<div style="font-family:' . $font . '; font-size:10px; font-weight:800; letter-spacing:1.5px; '
                . 'text-transform:uppercase; color:' . $tone . ';">Option ' . $number . '</div>'
                . '<div class="lm-h1 lm-text" style="margin-top:5px; font-family:' . $font . '; font-size:15px; font-weight:700; color:#0f172a; line-height:1.4;">'
                . $title . '</div>'
                . '<div class="lm-text" style="margin-top:7px; font-family:' . $font . '; font-size:13.5px; line-height:1.65; color:#475569;">'
                . $body . '</div>';

            if ($code !== '') {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:12px;">'
                    . '<tr><td style="background-color:#0f172a; border-radius:10px; padding:13px 15px; '
                    . 'font-family:Consolas,Menlo,Monaco,\'Liberation Mono\',monospace; font-size:11.5px; line-height:1.7; '
                    . 'color:#e2e8f0; word-break:break-all;">' . $code . '</td></tr></table>';
            }

            if ($foot !== '') {
                $html .= '<div style="margin-top:9px; font-family:' . $font . '; font-size:11.5px; line-height:1.6; color:#94a3b8;">'
                    . $foot . '</div>';
            }

            return $html . '</td></tr></table>';
        };

        $cronCode = '<span style="color:#7dd3fc;">0 1 * * *</span> /usr/local/bin/php /home/USER/public_html/'
            . 'bc-spadmin/cron_update.php &gt;&gt; /home/USER/ota_updates.log 2&gt;&amp;1';

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding-bottom:12px;">'
            . $option(
                '1',
                '#4338ca',
                'From your admin dashboard',
                $escAdmin,
                '',
                'No files to move. The update is applied for you, and the page shows the progress step by step.'
            )
            . '</td></tr>'
            . '<tr><td align="center" style="padding:0 0 12px 0; font-family:' . $font . '; font-size:10.5px; '
            . 'font-weight:800; letter-spacing:1.6px; text-transform:uppercase; color:#cbd5e1;">or</td></tr>'
            . '<tr><td>'
            . $option(
                '2',
                '#0e7490',
                'Automatic, from your control panel',
                $escCron,
                $cronCode,
                'Copy the exact line from <strong>Super Admin &rarr; Cron Jobs</strong> &mdash; it already contains your own paths.'
            )
            . '</td></tr>'
            . '</table>'
            . '<div class="lm-muted" style="margin-top:14px; font-family:' . $font . '; font-size:12px; line-height:1.65; color:#94a3b8;">'
            . 'Either route installs v' . htmlspecialchars((string) $version, ENT_QUOTES, 'UTF-8') . '. '
            . 'If you would rather do it by hand, download the package from your dashboard and upload it in '
            . 'cPanel &rarr; File Manager, then press <strong>Force Check</strong> on the update page.'
            . '</div>';

        $plain = function ($value) {
            return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'));
        };

        $text = "OPTION 1 - FROM YOUR ADMIN DASHBOARD\n"
            . ($adminOverride !== '' ? $adminOverride : $plain($adminText)) . "\n\n"
            . "OPTION 2 - AUTOMATIC FROM YOUR CONTROL PANEL\n"
            . ($cronOverride !== '' ? $cronOverride : $plain($cronText)) . "\n"
            . "cPanel > Cron Jobs (daily):\n"
            . "0 1 * * * /usr/local/bin/php /home/USER/public_html/bc-spadmin/cron_update.php >> /home/USER/ota_updates.log 2>&1\n"
            . "(the exact line is on Super Admin > Cron Jobs in your installation)\n\n"
            . "Manual: download the package from your dashboard, upload it in cPanel > File Manager, then press Force Check.";

        return array(
            'html' => $html,
            'text' => $text,
            'cron' => $cron,
            'admin' => $adminOverride !== '' ? $adminOverride : $plain($adminText),
            'cron_text' => $cronOverride !== '' ? $cronOverride : $plain($cronText),
        );
    }
}

if (!function_exists('lm_render_update_email')) {
    /**
     * Render the update / reminder email.
     *
     * @param array  $update   script_updates row
     * @param array  $tier     script_tiers row
     * @param array  $license  licenses row (may be partial)
     * @param string $kind     initial|reminder|resend
     * @param array  $extra    download_url
     * @param PDO|null $pdo    Needed to look up the domains that checked in
     * @return array{subject:string,html:string,alt:string,domain:string}
     */
    function lm_render_update_email(array $update, array $tier, array $license, $kind = 'initial', array $extra = array(), $pdo = null)
    {
        $settings = lm_settings();

        $templatePath = lm_lm_dir() . '/email_templates/update_available.html';
        $template = is_file($templatePath) ? (string) file_get_contents($templatePath) : '';
        if ($template === '') {
            $template = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#111;">'
                . '<h2>{{HEADLINE}}</h2><p>{{INTRO}}</p><div>{{CHANGELOG}}</div>'
                . '{{UPDATE_OPTIONS}}'
                . '<p><a href="{{CTA_URL}}">{{CTA_LABEL}}</a></p></body></html>';
        }

        $siteName = lm_site_name();
        $version = $update['version_number'];
        $tierName = $tier['tier_name'] ?? ($tier['tier_code'] ?? 'Script');

        // Which site is this about? `licenses.domain` is what the payment
        // gateway passed along; the domains that actually checked in are more
        // trustworthy, and a leftover placeholder is not a domain at all.
        $domainInfo = lm_license_domain_info($pdo, $license);
        $domain = $domainInfo['display'];
        $domainKnown = !empty($domainInfo['known']);
        $domainHtml = $domainKnown
            ? '<strong style="color:#0f172a;">' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</strong>'
            : '<strong style="color:#0f172a;">your website</strong>';

        $isReminder = ($kind === 'reminder');
        $format = $update['changelog_format'] ?? 'html';
        $changelogHtml = lm_changelog_html($update['changelog'] ?? '', $format);
        $changelogText = lm_changelog_plain($update['changelog'] ?? '', $format);

        $releaseTs = !empty($update['release_date']) ? strtotime($update['release_date']) : time();
        $releaseDate = gmdate('j M Y', $releaseTs);
        $year = gmdate('Y');
        $supportEmail = trim((string) ($settings['admin_email'] ?? ''));
        if ($supportEmail === '') {
            $supportEmail = 'support@' . preg_replace('#^www\.#', '', (string) (parse_url(lm_site_url(), PHP_URL_HOST) ?: 'localhost'));
        }

        $ctaUrl = lm_site_url('user/login.php');
        $ctaLabel = 'Open your dashboard';
        $ctaNote = 'Sign in to download the package and follow the install steps.';

        if (!empty($extra['download_url'])) {
            $ctaUrl = $extra['download_url'];
            $ctaLabel = 'Download update v' . $version;
            $ctaNote = 'This secure link is unique to your licence and expires in 7 days. You can also sign in at '
                . '<a href="' . htmlspecialchars(lm_site_url('user/login.php'), ENT_QUOTES, 'UTF-8') . '" style="color:#6366f1;">'
                . htmlspecialchars(lm_site_url('user/login.php'), ENT_QUOTES, 'UTF-8') . '</a>.';
        }

        $unsubToken = lm_unsubscribe_token((int) ($license['id'] ?? 0), (string) ($license['customer_email'] ?? ''));
        $unsubUrl = lm_site_url('unsubscribe.php?t=' . rawurlencode($unsubToken));

        $subject = $isReminder
            ? 'Reminder: ' . $tierName . ' v' . $version . ' is still available'
            : 'New update available: ' . $tierName . ' v' . $version;

        if ($siteName !== '') {
            $subject = $siteName . ' — ' . $subject;
        }

        if ($isReminder) {
            $accent = '#b45309';
            $accent2 = '#f59e0b';
            $badgeBg = 'rgba(255,255,255,0.16)';
            $badgeColor = '#fff7ed';
            $badgeText = 'Still pending';
            $eyebrow = 'A quick reminder';
            $headline = 'Your update is ready when you are';
            $intro = 'A little while ago we released <strong>' . htmlspecialchars($tierName, ENT_QUOTES, 'UTF-8') . ' v' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
                . '</strong>. Our records show that ' . $domainHtml . ($domainKnown ? '' : ' (the site on this licence)') . ' has not been updated yet, so here is one friendly nudge.'
                . ' Installing the latest version keeps your site secure and gives you everything listed below.';
            $preheader = 'Reminder: ' . $tierName . ' v' . $version . ' has not been installed on ' . $domain . ' yet.';
        } else {
            $accent = '#4338ca';
            $accent2 = '#06b6d4';
            $badgeBg = 'rgba(255,255,255,0.16)';
            $badgeColor = '#eef2ff';
            $badgeText = 'Update available';
            $eyebrow = 'Fresh release';
            $headline = 'A new version is ready for your website';
            $intro = 'We have just published <strong>' . htmlspecialchars($tierName, ENT_QUOTES, 'UTF-8') . ' v' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
                . '</strong> for ' . $domainHtml . '. It includes the improvements below, and you can install it in '
                . '<strong>one click from your admin dashboard</strong> &mdash; or let the daily cron job do it for you.';
            $preheader = $tierName . ' v' . $version . ' is ready for ' . $domain . ' — install it from your dashboard or your update cron.';
        }

        $updateOptions = lm_update_email_options($tier, $version, $domainInfo);

        // Only claim a licensed domain when we actually know one.
        $domainRow = '';
        if ($domainKnown) {
            $domainRow = '<tr>'
                . '<td style="padding:2px 0;">Licensed domain</td>'
                . '<td align="right" style="padding:2px 0; color:#334155; font-weight:600;">'
                . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8')
                . ($domainInfo['note'] !== ''
                    ? '<div style="font-size:11px; font-weight:400; color:#94a3b8;">'
                        . htmlspecialchars($domainInfo['note'], ENT_QUOTES, 'UTF-8') . '</div>'
                    : '')
                . '</td>'
                . '</tr>';
        }

        $logoUrl = lm_update_email_site_logo();
        $logoBlock = '';
        if ($logoUrl !== '') {
            $logoBlock = '<td valign="middle" style="padding-right:10px;">'
                . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" height="34" style="display:block;height:34px;width:auto;border-radius:8px;">'
                . '</td>';
        }

        $replacements = array(
            '{{SUBJECT}}'          => htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
            '{{PREHEADER}}'        => htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8'),
            '{{SITE_NAME}}'        => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
            '{{LOGO_BLOCK}}'       => $logoBlock,
            '{{BADGE_TEXT}}'       => $badgeText,
            '{{BADGE_BG}}'         => $badgeBg,
            '{{BADGE_COLOR}}'      => $badgeColor,
            '{{EYEBROW}}'          => htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8'),
            '{{HEADLINE}}'         => htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'),
            '{{INTRO}}'            => $intro,
            '{{ACCENT}}'           => $accent,
            '{{ACCENT_2}}'         => $accent2,
            '{{TIER_LABEL}}'       => 'Target script',
            '{{TIER_NAME}}'        => htmlspecialchars($tierName, ENT_QUOTES, 'UTF-8'),
            '{{VERSION}}'          => htmlspecialchars($version, ENT_QUOTES, 'UTF-8'),
            '{{RELEASE_DATE}}'     => $releaseDate,
            '{{CHANGELOG}}'        => $changelogHtml,
            '{{CTA_URL}}'          => htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8'),
            '{{CTA_LABEL}}'        => htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8'),
            '{{CTA_NOTE}}'         => $ctaNote,
            '{{LICENSE_DOMAIN}}'   => htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'),
            '{{DOMAIN_ROW}}'       => $domainRow,
            '{{DOMAIN_NOTE}}'      => htmlspecialchars($domainInfo['note'], ENT_QUOTES, 'UTF-8'),
            '{{UPDATE_OPTIONS}}'   => $updateOptions['html'],
            '{{UPDATE_CRON}}'      => htmlspecialchars($updateOptions['cron'], ENT_QUOTES, 'UTF-8'),
            '{{PACKAGE_NAME}}'     => 'update_' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '.zip',
            '{{SUPPORT_EMAIL}}'    => htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8'),
            '{{UNSUBSCRIBE_URL}}'  => htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8'),
            '{{YEAR}}'             => $year,
        );

        $html = str_replace(array_keys($replacements), array_values($replacements), $template);

        $alt = $subject . "\n\n"
            . strtoupper($headline) . "\n\n"
            . trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($intro), ENT_QUOTES, 'UTF-8'))) . "\n\n"
            . "What's new in v" . $version . ":\n" . $changelogText . "\n\n"
            . $updateOptions['text'] . "\n\n"
            . 'Licensed domain: ' . $domain . ($domainInfo['note'] !== '' ? ' (' . $domainInfo['note'] . ')' : '') . "\n"
            . 'Released: ' . $releaseDate . "\n"
            . 'Download / dashboard: ' . $ctaUrl . "\n\n"
            . 'Stop receiving update reminders: ' . $unsubUrl . "\n"
            . '(c) ' . $year . ' ' . $siteName;

        return array('subject' => $subject, 'html' => $html, 'alt' => $alt, 'domain' => $domain);
    }
}

if (!function_exists('lm_customer_recipients')) {
    /**
     * Unique, emailable customers for a tier.
     *
     * @param PDO $pdo
     * @param int $tierId
     * @return array<int,array{id:int,email:string,domain:string}>
     */
    function lm_customer_recipients(PDO $pdo, $tierId)
    {
        $stmt = $pdo->prepare(
            "SELECT id, customer_email, domain, notify_updates
             FROM licenses
             WHERE tier_id = ? AND status = 'active'
               AND customer_email IS NOT NULL AND customer_email <> ''
             ORDER BY id ASC"
        );
        $stmt->execute(array((int) $tierId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $seen = array();
        $recipients = array();

        foreach ($rows as $row) {
            $email = strtolower(trim($row['customer_email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (strpos($email, 'unknown@') === 0 || strpos($email, 'n/a') === 0) {
                continue;
            }
            if ((int) ($row['notify_updates'] ?? 1) === 0) {
                continue;
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $recipients[] = array(
                'id' => (int) $row['id'],
                'email' => $email,
                'domain' => $row['domain'],
            );
        }

        return $recipients;
    }
}

if (!function_exists('lm_license_installed_version')) {
    /**
     * Highest version reported by any domain of a licence.
     *
     * @param PDO $pdo
     * @param int $licenseId
     * @return string
     */
    function lm_license_installed_version(PDO $pdo, $licenseId)
    {
        try {
            $stmt = $pdo->prepare("SELECT installed_version FROM licensed_domains WHERE license_id = ? AND installed_version IS NOT NULL AND installed_version <> ''");
            $stmt->execute(array((int) $licenseId));
            $versions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return '';
        }

        $highest = '';
        foreach ($versions as $version) {
            if ($highest === '' || lm_version_compare($version, $highest) > 0) {
                $highest = $version;
            }
        }

        return $highest;
    }
}

if (!function_exists('lm_license_downloaded_update')) {
    /**
     * @param PDO $pdo
     * @param int $licenseId
     * @param int $updateId
     * @return bool
     */
    function lm_license_downloaded_update(PDO $pdo, $licenseId, $updateId)
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM download_tokens WHERE license_id = ? AND update_id = ? AND is_used = 1");
            $stmt->execute(array((int) $licenseId, (int) $updateId));
            return (int) $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('lm_create_update_download_link')) {
    /**
     * One-time, 7-day download link for a specific licence/update.
     *
     * @param PDO   $pdo
     * @param int   $updateId
     * @param int   $licenseId
     * @param int   $days
     * @return string
     */
    function lm_create_update_download_link(PDO $pdo, $updateId, $licenseId, $days = 7)
    {
        try {
            $token = bin2hex(random_bytes(32));
            $expires = gmdate('Y-m-d H:i:s', time() + (max(1, (int) $days) * 86400));
            $stmt = $pdo->prepare("INSERT INTO download_tokens (license_id, update_id, token, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->execute(array((int) $licenseId, (int) $updateId, $token, $expires));
            return lm_site_url('download.php?token=' . $token);
        } catch (Exception $e) {
            return lm_site_url('user/login.php');
        }
    }
}

if (!function_exists('lm_queue_update_notifications')) {
    /**
     * Queue the first "update available" email for every customer of a tier.
     *
     * @param PDO   $pdo
     * @param int   $updateId
     * @param array $options delay_minutes
     * @return array{queued:int,skipped:int,recipients:int,error:string}
     */
    function lm_queue_update_notifications(PDO $pdo, $updateId, array $options = array())
    {
        $stats = array('queued' => 0, 'skipped' => 0, 'recipients' => 0, 'error' => '');

        $settings = lm_settings();
        if (empty($settings['update_notify_enabled'])) {
            $stats['error'] = 'Update notifications are disabled in Settings.';
            return $stats;
        }

        $stmt = $pdo->prepare("SELECT u.*, t.tier_code, t.tier_name FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.id = ?");
        $stmt->execute(array((int) $updateId));
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            $stats['error'] = 'Update not found.';
            return $stats;
        }

        $delay = isset($options['delay_minutes']) && $options['delay_minutes'] !== null
            ? max(0, (int) $options['delay_minutes'])
            : max(0, (int) ($settings['update_notify_delay_minutes'] ?? 0));

        $sendAfter = gmdate('Y-m-d H:i:s', time() + ($delay * 60));
        $recipients = lm_customer_recipients($pdo, (int) $update['tier_id']);
        $stats['recipients'] = count($recipients);

        foreach ($recipients as $recipient) {
            // Skip customers already running this version or newer.
            $installed = lm_license_installed_version($pdo, $recipient['id']);
            if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                $stats['skipped']++;
                continue;
            }

            $unsub = lm_unsubscribe_token($recipient['id'], $recipient['email']);

            try {
                $insert = $pdo->prepare(
                    "INSERT IGNORE INTO update_notifications
                        (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                     VALUES (?, ?, ?, ?, 'initial', 0, 'pending', 0, ?, ?)"
                );
                $insert->execute(array(
                    (int) $update['id'],
                    (int) $update['tier_id'],
                    $recipient['id'],
                    $recipient['email'],
                    $sendAfter,
                    $unsub,
                ));
                if ($insert->rowCount() > 0) {
                    $stats['queued']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (PDOException $e) {
                $stats['error'] = 'Could not queue notifications: ' . $e->getMessage();
                lm_log('notifications', $stats['error']);
                break;
            }
        }

        // Mark the update as announced.
        try {
            $pdo->prepare("UPDATE script_updates SET notified_at = NOW() WHERE id = ?")->execute(array((int) $update['id']));
        } catch (PDOException $e) {
            // column may not exist on a partially migrated install
        }

        lm_log('notifications', 'Queued ' . $stats['queued'] . ' notification(s) for ' . $update['tier_code'] . ' v' . $update['version_number'] . ' (' . $stats['skipped'] . ' skipped).');

        return $stats;
    }
}

if (!function_exists('lm_dispatch_update_notifications')) {
    /**
     * Send due notifications and schedule the next-day reminder.
     *
     * @param PDO $pdo
     * @param int $limit
     * @param bool $dryRun
     * @return array{sent:int,reminders:int,failed:int,skipped:int,processed:int,errors:array,disabled:bool}
     */
    function lm_dispatch_update_notifications(PDO $pdo, $limit = 25, $dryRun = false)
    {
        $stats = array(
            'sent' => 0,
            'reminders' => 0,
            'failed' => 0,
            'skipped' => 0,
            'processed' => 0,
            'errors' => array(),
            'disabled' => false,
        );

        $settings = lm_settings();
        if (empty($settings['update_notify_enabled'])) {
            $stats['disabled'] = true;
            return $stats;
        }

        if (empty(lm_smtp_plan($settings))) {
            $stats['errors'][] = 'SMTP is not configured — notifications stay queued.';
            return $stats;
        }

        $limit = max(1, min(100, (int) $limit));
        $reminderHours = max(1, (int) ($settings['update_reminder_hours'] ?? 24));
        $maxReminders = max(0, min(5, (int) ($settings['update_reminder_max'] ?? 1)));
        $skipIfDownloaded = !empty($settings['update_skip_if_downloaded']);

        // Recover rows left in "sending" by a crashed worker.
        try {
            $pdo->exec("UPDATE update_notifications SET status = 'pending' WHERE status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE)");
        } catch (PDOException $e) {
            // ignore
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM update_notifications
             WHERE status = 'pending' AND send_after <= NOW()
             ORDER BY send_after ASC, id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($due as $row) {
            $stats['processed']++;
            $id = (int) $row['id'];

            // Claim the row.
            $claim = $pdo->prepare("UPDATE update_notifications SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'pending'");
            $claim->execute(array($id));
            if ($claim->rowCount() === 0) {
                continue;
            }

            if ($dryRun) {
                $pdo->prepare("UPDATE update_notifications SET status = 'pending' WHERE id = ?")->execute(array($id));
                $stats['sent']++;
                continue;
            }

            $updStmt = $pdo->prepare("SELECT u.*, t.tier_code, t.tier_name FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.id = ?");
            $updStmt->execute(array((int) $row['update_id']));
            $update = $updStmt->fetch(PDO::FETCH_ASSOC);

            if (!$update) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Update no longer exists' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            $licStmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ?");
            $licStmt->execute(array((int) $row['license_id']));
            $license = $licStmt->fetch(PDO::FETCH_ASSOC);

            if (!$license) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Licence no longer exists' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            if (($license['status'] ?? '') !== 'active' || (int) ($license['notify_updates'] ?? 1) === 0) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Licence inactive or notifications disabled' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            // Already installed? Then no email at all.
            $installed = lm_license_installed_version($pdo, (int) $license['id']);
            if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Customer already updated' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            // A newer release exists; the reminder for the old one is pointless.
            $latest = lm_latest_update_for_tier($pdo, (int) $update['tier_id'], true);
            if ($row['kind'] === 'reminder' && $latest && (int) $latest['id'] !== (int) $update['id']) {
                $pdo->prepare("UPDATE update_notifications SET status = 'superseded', last_error = 'A newer version was published' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            if ($row['kind'] === 'reminder' && $skipIfDownloaded && lm_license_downloaded_update($pdo, (int) $license['id'], (int) $update['id'])) {
                $pdo->prepare("UPDATE update_notifications SET status = 'skipped', last_error = 'Package already downloaded' WHERE id = ?")->execute(array($id));
                $stats['skipped']++;
                continue;
            }

            $downloadUrl = lm_create_update_download_link($pdo, (int) $update['id'], (int) $license['id'], 7);
            $email = lm_render_update_email($update, $update, $license, $row['kind'], array('download_url' => $downloadUrl), $pdo);

            $result = lm_send_mail($license['customer_email'], $email['subject'], $email['html'], $email['alt']);

            if ($result['ok']) {
                $pdo->prepare("UPDATE update_notifications SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")->execute(array($id));

                if ($row['kind'] === 'initial') {
                    $stats['sent']++;
                    $reminderNo = 1;
                } else {
                    $stats['reminders']++;
                    $reminderNo = (int) $row['reminder_no'] + 1;
                }

                // Schedule the next reminder (default: exactly one, next day).
                if ($reminderNo <= $maxReminders) {
                    $sendAt = gmdate('Y-m-d H:i:s', time() + ($reminderHours * 3600));
                    try {
                        $pdo->prepare(
                            "INSERT IGNORE INTO update_notifications
                                (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                             VALUES (?, ?, ?, ?, 'reminder', ?, 'pending', 0, ?, ?)"
                        )->execute(array(
                            (int) $update['id'],
                            (int) $update['tier_id'],
                            (int) $license['id'],
                            $license['customer_email'],
                            $reminderNo,
                            $sendAt,
                            $row['unsub_token'],
                        ));
                    } catch (PDOException $e) {
                        lm_log('notifications', 'Could not schedule reminder: ' . $e->getMessage());
                    }
                }
            } else {
                $attempts = (int) $row['attempts'];

                if ($attempts >= 3) {
                    $pdo->prepare("UPDATE update_notifications SET status = 'failed', last_error = ? WHERE id = ?")
                        ->execute(array(substr($result['error'], 0, 2000), $id));
                } else {
                    $retryAt = gmdate('Y-m-d H:i:s', time() + (15 * 60 * $attempts));
                    $pdo->prepare("UPDATE update_notifications SET status = 'pending', last_error = ?, send_after = ? WHERE id = ?")
                        ->execute(array(substr($result['error'], 0, 2000), $retryAt, $id));
                }

                $stats['failed']++;
                $stats['errors'][] = 'Notification #' . $id . ': ' . $result['error'];
            }
        }

        return $stats;
    }
}

if (!function_exists('lm_notification_summary')) {
    /**
     * Status counters for one update.
     *
     * @param PDO $pdo
     * @param int $updateId
     * @return array
     */
    function lm_notification_summary(PDO $pdo, $updateId)
    {
        $summary = array(
            'total' => 0,
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'reminders_sent' => 0,
            'reminders_pending' => 0,
        );

        try {
            $stmt = $pdo->prepare("SELECT kind, status, COUNT(*) AS c FROM update_notifications WHERE update_id = ? GROUP BY kind, status");
            $stmt->execute(array((int) $updateId));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $count = (int) $row['c'];
                $summary['total'] += $count;
                $status = $row['status'];
                if (isset($summary[$status])) {
                    $summary[$status] += $count;
                }
                if ($row['kind'] === 'reminder' && $status === 'sent') {
                    $summary['reminders_sent'] += $count;
                }
                if ($row['kind'] === 'reminder' && $status === 'pending') {
                    $summary['reminders_pending'] += $count;
                }
            }
        } catch (PDOException $e) {
            // table missing on a fresh install
        }

        return $summary;
    }
}

if (!function_exists('lm_requeue_update_notifications')) {
    /**
     * Re-send notifications for an update.
     *
     * @param PDO    $pdo
     * @param int    $updateId
     * @param string $audience failed|pending|all
     * @return array{ok:bool,queued:int,message:string}
     */
    function lm_requeue_update_notifications(PDO $pdo, $updateId, $audience = 'failed')
    {
        $stmt = $pdo->prepare("SELECT * FROM script_updates WHERE id = ?");
        $stmt->execute(array((int) $updateId));
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            return array('ok' => false, 'queued' => 0, 'message' => 'Update not found.');
        }

        $queued = 0;

        if ($audience === 'failed') {
            $stmt = $pdo->prepare("UPDATE update_notifications SET status = 'pending', attempts = 0, send_after = NOW() WHERE update_id = ? AND status = 'failed'");
            $stmt->execute(array((int) $updateId));
            $queued = $stmt->rowCount();
            return array('ok' => true, 'queued' => $queued, 'message' => $queued . ' failed notification(s) re-queued.');
        }

        // Send a reminder now to every customer who is still behind, even if
        // their scheduled reminder is not due yet.
        if ($audience === 'reminder') {
            $recipients = lm_customer_recipients($pdo, (int) $update['tier_id']);
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(reminder_no), 0) FROM update_notifications WHERE update_id = ? AND kind = 'reminder'");
            $maxStmt->execute(array((int) $updateId));
            $batch = (int) $maxStmt->fetchColumn() + 1;

            foreach ($recipients as $recipient) {
                $installed = lm_license_installed_version($pdo, $recipient['id']);
                if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                    continue;
                }
                try {
                    $pdo->prepare(
                        "INSERT IGNORE INTO update_notifications
                            (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                         VALUES (?, ?, ?, ?, 'reminder', ?, 'pending', 0, NOW(), ?)"
                    )->execute(array(
                        (int) $update['id'],
                        (int) $update['tier_id'],
                        $recipient['id'],
                        $recipient['email'],
                        $batch,
                        lm_unsubscribe_token($recipient['id'], $recipient['email']),
                    ));
                    if ($pdo->lastInsertId()) {
                        $queued++;
                    }
                } catch (PDOException $e) {
                    // duplicate batch — ignore
                }
            }

            return array('ok' => true, 'queued' => $queued, 'message' => $queued . ' reminder(s) queued for customers who are still behind.');
        }

        // Send to everyone still behind, as a new "resend" batch.
        $recipients = lm_customer_recipients($pdo, (int) $update['tier_id']);
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(reminder_no), 0) FROM update_notifications WHERE update_id = ? AND kind = 'resend'");
        $maxStmt->execute(array((int) $updateId));
        $batch = (int) $maxStmt->fetchColumn() + 1;

        foreach ($recipients as $recipient) {
            $installed = lm_license_installed_version($pdo, $recipient['id']);
            if ($installed !== '' && lm_version_compare($installed, $update['version_number']) >= 0) {
                continue;
            }
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO update_notifications
                        (update_id, tier_id, license_id, email, kind, reminder_no, status, attempts, send_after, unsub_token)
                     VALUES (?, ?, ?, ?, 'resend', ?, 'pending', 0, NOW(), ?)"
                )->execute(array(
                    (int) $update['id'],
                    (int) $update['tier_id'],
                    $recipient['id'],
                    $recipient['email'],
                    $batch,
                    lm_unsubscribe_token($recipient['id'], $recipient['email']),
                ));
                if ($pdo->lastInsertId()) {
                    $queued++;
                }
            } catch (PDOException $e) {
                // duplicate batch — ignore
            }
        }

        return array('ok' => true, 'queued' => $queued, 'message' => $queued . ' customer(s) queued for a fresh notification.');
    }
}

if (!function_exists('lm_notification_log')) {
    /**
     * Recent notification rows for the admin log.
     *
     * @param PDO $pdo
     * @param int $limit
     * @param array $filters update_id, tier_id, status
     * @return array
     */
    function lm_notification_log(PDO $pdo, $limit = 100, array $filters = array())
    {
        $sql = "SELECT n.*, u.version_number, t.tier_code, t.tier_name
                FROM update_notifications n
                LEFT JOIN script_updates u ON n.update_id = u.id
                LEFT JOIN script_tiers t ON n.tier_id = t.id
                WHERE 1=1";
        $params = array();

        if (!empty($filters['update_id'])) {
            $sql .= " AND n.update_id = ?";
            $params[] = (int) $filters['update_id'];
        }
        if (!empty($filters['tier_id'])) {
            $sql .= " AND n.tier_id = ?";
            $params[] = (int) $filters['tier_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND n.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY n.id DESC LIMIT " . max(1, min(500, (int) $limit));

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
