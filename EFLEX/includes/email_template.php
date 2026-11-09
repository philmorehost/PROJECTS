<?php

/**
 * Generates a modern, responsive HTML email body.
 *
 * @param string $subject The subject of the email, used in the title and header.
 * @param string $body_content The main HTML content of the email body.
 * @param array $settings An array of site settings, used for branding (site name, colors, etc.).
 * @return string The full HTML for the email.
 */
function get_modern_email_html($subject, $body_content, $settings) {
    $site_name = $settings['site_name'] ?? 'Eflex E-commerce';
    $primary_color = $settings['theme_primary_color'] ?? '#ae8e6a';
    $bg_color = '#f2f2f2';
    $text_color = '#333333';
    $footer_text = $settings['copyright_text'] ?? ('© ' . date('Y') . ' ' . $site_name . '. All Rights Reserved.');

    $html = <<<EOD
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$subject</title>
    <style>
        body { margin: 0; padding: 0; word-spacing: normal; background-color: $bg_color; }
        table, td, div, h1, p { font-family: Arial, sans-serif; }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: $bg_color;">
    <table role="presentation" style="width: 100%; border-collapse: collapse; border: 0; border-spacing: 0; background: $bg_color;">
        <tr>
            <td align="center" style="padding: 0;">
                <!--[if mso]>
                <table role="presentation" style="width:600px;">
                <tr>
                <td>
                <![endif]-->
                <table role="presentation" style="width: 100%; max-width: 600px; border-collapse: collapse; border: 1px solid #cccccc; border-spacing: 0; text-align: left; background: #ffffff;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding: 20px 0 20px 0; background-color: $primary_color;">
                            <h1 style="font-size: 24px; margin: 0; font-family: Arial, sans-serif; color: #ffffff;">$site_name</h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 36px 30px 42px 30px;">
                            <table role="presentation" style="width: 100%; border-collapse: collapse; border: 0; border-spacing: 0;">
                                <tr>
                                    <td style="color: $text_color;">
                                        $body_content
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px; background: #eeeeee;">
                            <table role="presentation" style="width: 100%; border-collapse: collapse; border: 0; border-spacing: 0; font-size: 9px; font-family: Arial, sans-serif;">
                                <tr>
                                    <td style="padding: 0; width: 100%;" align="center">
                                        <p style="margin: 0; font-size: 14px; line-height: 16px; font-family: Arial, sans-serif; color: #888888;">
                                            $footer_text
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <!--[if mso]>
                </td>
                </tr>
                </table>
                <![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
EOD;

    return $html;
}
