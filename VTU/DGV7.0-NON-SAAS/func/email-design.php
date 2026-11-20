<?php
/**
 * bc_extractEmailBodyContent()
 * Reduces a full HTML document (e.g. a GrapesJS export) to just the content inside
 * <body>…</body>. Email clients should receive an HTML *fragment*, not a full
 * <!DOCTYPE html><html><head><style>…</style></head><body>…</body></html> document —
 * sending the complete document is what makes some mail clients render the raw HTML
 * source instead of a formatted email.
 */
function bc_extractEmailBodyContent($html)
{
    if (!is_string($html)) return '';
    if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
        return trim($m[1]);
    }
    $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
    $html = preg_replace('/<html[^>]*>/i', '', $html);
    $html = preg_replace('/<\/html>/i', '', $html);
    $html = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $html);
    $html = preg_replace('/<body[^>]*>/i', '', $html);
    $html = preg_replace('/<\/body>/i', '', $html);
    return trim($html);
}

/**
 * §46 — shade a #rrggbb colour darker/lighter for email gradients.
 * $factor < 1 darkens, > 1 lightens.
 */
function bc_email_shade($hex, $factor) {
    $hex = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$hex) ? (string)$hex : '#287bff';
    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
    if ($factor < 1) { $r = (int)($r * $factor); $g = (int)($g * $factor); $b = (int)($b * $factor); }
    else { $r = (int)($r + (255 - $r) * ($factor - 1)); $g = (int)($g + (255 - $g) * ($factor - 1)); $b = (int)($b + (255 - $b) * ($factor - 1)); }
    return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

/**
 * §46 — who is this email from? Resolves the website (vendor) for the current
 * request context and returns name / brand colour / support email / host.
 * The Site Name and colour the owner set in Account Settings win; the old
 * shell hard-coded a blue and the bare hostname everywhere.
 */
function bc_email_brand() {
    global $connection_server;
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $vendor_id = (int)($GLOBALS['get_logged_user_details']['vendor_id'] ?? $GLOBALS['get_logged_admin_details']['id'] ?? $GLOBALS['vendor_id'] ?? 0);
    if ($vendor_id <= 0 && $connection_server instanceof mysqli && function_exists('resolveVendorID')) {
        $vendor_id = (int)resolveVendorID();
    }
    if ($vendor_id <= 0 && $connection_server instanceof mysqli && $host !== '') {
        $host_e = mysqli_real_escape_string($connection_server, $host);
        $vq = mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE website_url='$host_e' LIMIT 1");
        if ($vq && ($vr = mysqli_fetch_assoc($vq))) $vendor_id = (int)$vr['id'];
    }
    $site_name = '';
    $color = '';
    $support = '';
    $apk = '';
    if ($connection_server instanceof mysqli && $vendor_id > 0) {
        $sq = mysqli_query($connection_server, "SELECT site_title, primary_color, apk_download_url FROM sas_site_details WHERE vendor_id='$vendor_id' LIMIT 1");
        if ($sq && ($sr = mysqli_fetch_assoc($sq))) {
            $site_name = trim((string)($sr['site_title'] ?? ''));
            $color = (string)($sr['primary_color'] ?? '');
            $apk = trim((string)($sr['apk_download_url'] ?? ''));
        }
        $vq2 = mysqli_query($connection_server, "SELECT email, website_url FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
        if ($vq2 && ($vr2 = mysqli_fetch_assoc($vq2))) {
            $support = (string)$vr2['email'];
            $vendor_host = strtolower(trim((string)($vr2['website_url'] ?? '')));
            if (preg_match('/^[a-z0-9.-]+$/D', $vendor_host)) $host = $vendor_host;
        }
    }
    if ($site_name === '' && $connection_server instanceof mysqli) {
        $pq = mysqli_query($connection_server, "SELECT site_title FROM sas_super_admin_site_details LIMIT 1");
        if ($pq && ($pr = mysqli_fetch_assoc($pq))) $site_name = trim((string)($pr['site_title'] ?? ''));
    }
    if ($site_name === '') $site_name = $host !== '' ? $host : 'Our Platform';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#287bff';
    if ($support === '' || !filter_var($support, FILTER_VALIDATE_EMAIL)) $support = 'support@' . ($host !== '' ? $host : 'example.com');
    $brand = array(
        'vendor_id' => $vendor_id,
        'site_name' => $site_name,
        'color' => $color,
        'dark' => bc_email_shade($color, 0.72),
        'support_email' => $support,
        'host' => $host,
        'apk_url' => $apk,
        'logo_url' => $host !== '' ? 'https://' . $host . '/uploaded-image/' . str_replace(array(':', '.'), '-', $host) . '_logo.png' : '',
    );
    return $brand;
}

/**
 * §46 — one-time-code email. Built so the code can be taken straight from
 * the inbox list: the subject starts with the code and the body's first
 * (hidden preheader) text repeats it, which is what mail apps surface as
 * the preview / one-tap "copy code" text. In the body the digits sit in
 * individual boxes that are easy to read and copy.
 * Returns array('subject' => ..., 'body' => ...).
 */
function bc_otp_email($code, $purpose, $firstname = '', $expiry = '10 minutes') {
    $code = preg_replace('/\D+/', '', (string)$code);
    $brand = bc_email_brand();
    $purpose_e = htmlspecialchars((string)$purpose, ENT_QUOTES, 'UTF-8');
    $boxes = '';
    foreach (str_split($code) as $digit) {
        $boxes .= '<td style="width:46px;height:56px;border:1.5px solid #cbd5e1;border-radius:10px;background-color:#f8fafc;text-align:center;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;font-size:26px;font-weight:700;color:#0f172a;">' . $digit . '</td><td style="width:6px;"></td>';
    }
    $greeting = trim((string)$firstname) !== '' ? 'Hello ' . htmlspecialchars((string)$firstname, ENT_QUOTES, 'UTF-8') . ',' : 'Hello,';
    $body = '<span style="display:none!important;visibility:hidden;opacity:0;height:0;width:0;overflow:hidden;mso-hide:all;">'
        . $code . ' is your ' . $purpose_e . ' code. It expires in ' . htmlspecialchars((string)$expiry, ENT_QUOTES, 'UTF-8') . '.</span>'
        . '<p style="margin:0 0 14px 0;">' . $greeting . '</p>'
        . '<p style="margin:0 0 6px 0;">Use this code to complete your ' . $purpose_e . '. It expires in <strong>' . htmlspecialchars((string)$expiry, ENT_QUOTES, 'UTF-8') . '</strong> and can be used once.</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:22px auto;"><tr>' . $boxes . '</tr></table>'
        . '<p style="margin:0 0 14px 0;text-align:center;color:#64748b;font-size:13px;">Enter the code exactly as shown — no spaces needed.</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#fff7ed;border:1px solid #fed7aa;border-radius:10px;margin-top:18px;"><tr><td style="padding:12px 16px;color:#9a3412;font-size:13px;line-height:1.55;"><strong>Never share this code.</strong> ' . htmlspecialchars($brand['site_name'], ENT_QUOTES, 'UTF-8') . ' will never ask for it by phone, chat or email. If you did not request it, ignore this email and secure your account.</td></tr></table>';
    return array(
        'subject' => $code . ' is your ' . (string)$purpose . ' code',
        'body' => $body,
    );
}

function mailDesignTemplate($title, $message, $details_array, $show_app = true) {
    global $connection_server;

    // All outgoing messages use one shell, including saved full-document designs.
    if (strpos(ltrim((string)$message), '<!-- bc-email-shell:v2 -->') === 0) return $message;
    if (preg_match('/<(?:html|body)\b/i', (string)$message)) {
        $message = bc_extractEmailBodyContent($message);
    }

    $mail_title = !empty(trim(strip_tags($title))) ? trim(strip_tags($title)) : 'Notification';
    $mail_message = str_replace("\n", "<br/>", trim($message));

    $brand = bc_email_brand();
    $website_url = $brand['host'];
    $primary_color = $brand['color'];
    $dark_color = $brand['dark'];
    $site_name_e = htmlspecialchars($brand['site_name'], ENT_QUOTES, 'UTF-8');
    $base_url = $website_url !== '' ? 'https://' . $website_url : '';

    // Inbox preview text: the first words of the message itself (OTP bodies
    // lead with their hidden code line, so codes surface without opening).
    $preheader = trim(preg_replace('/\s+/', ' ', strip_tags($mail_message)));
    $preheader = htmlspecialchars(substr($preheader, 0, 110), ENT_QUOTES, 'UTF-8');

    // Optional label => value details (transaction receipts pass these in).
    $details_html = '';
    if (is_array($details_array)) {
        $rows = '';
        foreach ($details_array as $k => $v) {
            if (!is_string($k) || $v === null || $v === '') continue;
            $rows .= '<tr><td style="padding:9px 16px;border-bottom:1px solid #eef2f7;color:#64748b;font-size:13px;font-family:Arial,Helvetica,sans-serif;">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td align="right" style="padding:9px 16px;border-bottom:1px solid #eef2f7;color:#0f172a;font-size:14px;font-weight:600;font-family:Arial,Helvetica,sans-serif;">' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        if ($rows !== '') {
            $details_html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin:20px 0;">' . $rows . '</table>';
        }
    }

    $services_html = '';
    if ($brand['vendor_id'] > 0 && $base_url !== '') {
        $services_list = [
            'data' => ['label' => 'Data Bundle', 'link' => '/web/Data.php'],
            'airtime' => ['label' => 'Airtime VTU', 'link' => '/web/Airtime.php'],
            'cable' => ['label' => 'Cable TV', 'link' => '/web/Cable.php'],
            'electric' => ['label' => 'Electricity', 'link' => '/web/Electric.php'],
            'betting' => ['label' => 'Betting Fund', 'link' => '/web/Betting.php'],
            'exam' => ['label' => 'Exam PIN', 'link' => '/web/Exam.php'],
            'bulk_sms' => ['label' => 'Bulk SMS', 'link' => '/web/BulkSMS.php'],
            'virtual_card' => ['label' => 'Virtual Card', 'link' => '/web/VirtualCard.php'],
            'gift_card' => ['label' => 'Gift Cards', 'link' => '/web/GiftCard.php'],
            'crypto_hub' => ['label' => 'Crypto Hub', 'link' => '/web/CryptoHub.php'],
            'data_card' => ['label' => 'Print Hub', 'link' => '/web/PrintHub.php'],
            'withdraw' => ['label' => 'Wallet to Bank', 'link' => '/web/SendFund.php'],
        ];
        $sc_q = mysqli_query($connection_server, "SELECT service_name, status FROM sas_service_control WHERE vendor_id='" . (int)$brand['vendor_id'] . "'");
        $sc_settings = [];
        if ($sc_q) {
            while ($sc_r = mysqli_fetch_assoc($sc_q)) $sc_settings[$sc_r['service_name']] = $sc_r['status'];
        }
        $chips = '';
        foreach ($services_list as $key => $data) {
            if (isset($sc_settings[$key]) && $sc_settings[$key] != 1) continue;
            $chips .= '<a href="' . $base_url . $data['link'] . '" style="display:inline-block;padding:7px 13px;background-color:#eef4ff;border:1px solid #dbe7ff;border-radius:999px;text-decoration:none;color:' . $dark_color . ';font-size:12px;margin:3px;font-family:Arial,Helvetica,sans-serif;font-weight:700;">' . $data['label'] . '</a>';
        }
        if ($chips !== '') {
            $services_html = '<tr><td style="padding:22px 25px 6px 25px;border-top:1px solid #eef2f7;text-align:center;">'
                . '<p style="margin:0 0 10px 0;color:#94a3b8;font-size:11px;letter-spacing:2px;font-family:Arial,Helvetica,sans-serif;font-weight:700;">QUICK SERVICES</p>' . $chips . '</td></tr>';
        }
    }

    $app_section_html = '';
    if ($show_app && $base_url !== '') {
        $badge_img = '<img src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg" height="40" alt="Get the app" style="border:0;">';
        $app_cta = !empty($brand['apk_url'])
            ? '<a href="' . htmlspecialchars($brand['apk_url'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" style="text-decoration:none;">' . $badge_img . '</a>'
            : $badge_img;
        $app_section_html = '<tr><td style="padding:20px 25px;background-color:#f8fafc;text-align:center;border-top:1px solid #eef2f7;">'
            . '<p style="margin:0;font-size:12px;color:#64748b;font-weight:700;letter-spacing:1.5px;font-family:Arial,Helvetica,sans-serif;">GET THE APP</p>'
            . '<div style="margin-top:10px;">' . $app_cta . '</div></td></tr>';
    }

    $logo_html = $brand['logo_url'] !== ''
        ? '<img src="' . htmlspecialchars($brand['logo_url'], ENT_QUOTES, 'UTF-8') . '" alt="' . $site_name_e . '" style="max-height:44px;width:auto;background-color:#ffffff;border-radius:10px;padding:6px 10px;">'
        : '<span style="display:inline-block;background-color:#ffffff;color:' . $dark_color . ';font-family:Arial,Helvetica,sans-serif;font-weight:800;font-size:20px;border-radius:10px;padding:8px 14px;">' . $site_name_e . '</span>';

    $html = '<!-- bc-email-shell:v2 --><div style="display:none!important;visibility:hidden;opacity:0;height:0;width:0;overflow:hidden;mso-hide:all;">' . $preheader . '</div>'
    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed;background-color:#edf1f6;">'
    . '<tr><td align="center" style="padding:28px 12px 36px 12px;">'
    . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;margin:0 auto;width:100%;max-width:600px;border-spacing:0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.10);">'
    . '<tr><td align="center" style="background-color:' . $primary_color . ';background:linear-gradient(135deg,' . $primary_color . ',' . $dark_color . ');padding:26px 25px 22px 25px;text-align:center;">'
    . $logo_html
    . '<div style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:19px;font-weight:800;margin-top:10px;letter-spacing:0.2px;">' . $site_name_e . '</div>'
    . '<div style="color:rgba(255,255,255,0.85);font-family:Arial,Helvetica,sans-serif;font-size:12.5px;margin-top:3px;">Fast payments, data, airtime &amp; bills — done in seconds.</div>'
    . '</td></tr>'
    . '<tr><td style="padding:30px 28px 8px 28px;line-height:1.65;font-family:Arial,Helvetica,sans-serif;">'
    . '<h1 style="margin:0 0 16px 0;color:#0f172a;font-size:21px;line-height:1.3;font-family:Arial,Helvetica,sans-serif;">' . htmlspecialchars($mail_title, ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<div style="color:#475569;font-size:15px;">' . $mail_message . '</div>'
    . $details_html
    . ($base_url !== '' ? '<div style="text-align:center;margin:28px 0 8px 0;"><a href="' . $base_url . '/web/Dashboard.php" style="display:inline-block;padding:13px 30px;background-color:' . $primary_color . ';color:#ffffff !important;text-decoration:none;border-radius:999px;font-weight:700;font-size:14.5px;font-family:Arial,Helvetica,sans-serif;">Open Dashboard</a></div>' : '')
    . '</td></tr>'
    . $services_html
    . $app_section_html
    . '<tr><td style="padding:22px 25px;background-color:#0f172a;text-align:center;">'
    . '<p style="margin:0 0 6px 0;font-size:13px;color:#e2e8f0;font-weight:700;font-family:Arial,Helvetica,sans-serif;">Need help?</p>'
    . '<a href="mailto:' . htmlspecialchars($brand['support_email'], ENT_QUOTES, 'UTF-8') . '" style="text-decoration:none;color:#7dd3fc;font-size:13.5px;font-weight:700;font-family:Arial,Helvetica,sans-serif;">' . htmlspecialchars($brand['support_email'], ENT_QUOTES, 'UTF-8') . '</a>'
    . '<p style="margin:14px 0 4px 0;color:#94a3b8;font-size:11.5px;font-family:Arial,Helvetica,sans-serif;">&copy; ' . date('Y') . ' ' . $site_name_e . '. All rights reserved.</p>'
    . '<p style="margin:0;color:#64748b;font-size:11.5px;font-family:Arial,Helvetica,sans-serif;">You received this email because you have an account with ' . $site_name_e . '. Please do not reply to automated messages.</p>'
    . '</td></tr>'
    . '</table></td></tr></table>';

    return $html;
}
