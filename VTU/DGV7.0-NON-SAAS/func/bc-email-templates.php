<?php
    // §46 — Email template library.
    //
    // Template bodies are stored as email-safe FRAGMENTS with fully inline
    // styles. sendVendorEmail()/beeMailer() wrap fragments in the branded
    // shell from mailDesignTemplate() (func/email-design.php), so a stored
    // body must NOT be a full HTML document any more: the old documents
    // carried their CSS in a <style> block that mail clients (and the
    // sender's document-to-fragment reduction) strip, leaving unstyled
    // flex rows. Everything below therefore uses tables + inline styles.
    //
    // Transaction receipts always include the customer's phone number,
    // email, our own reference, status and date. Payment gateways, API
    // providers and provider references (api_website / api_reference) must
    // never appear in a customer-facing template or description.

    // Kept for backwards compatibility with anything that still reads it;
    // getTemplateHtml() below no longer builds stored bodies from it.
    $email_skeleton = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#edf1f6;"><tr><td align="center" style="padding:28px 12px;"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color:#ffffff;max-width:600px;border-radius:16px;"><tr><td style="padding:30px 28px;font-family:Arial,Helvetica,sans-serif;color:#334155;">{{BODY}}</td></tr></table></td></tr></table>';

    function getTemplateHtml($title, $body, $app_section = true) {
        // §46: bodies are fragments now — the branded shell supplies the
        // header, title and app section at send time.
        return $body;
    }

    /**
     * §46 — label/value receipt rows as an inline-styled table (email-safe:
     * no flex, no external CSS). Values may contain {placeholders}.
     */
    function bc_template_details($rows) {
        $html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin:18px 0;">';
        foreach ($rows as $row) {
            $html .= '<tr><td style="padding:9px 16px;border-bottom:1px solid #eef2f7;color:#64748b;font-size:13px;font-family:Arial,Helvetica,sans-serif;">' . $row[0] . '</td>'
                . '<td align="right" style="padding:9px 16px;border-bottom:1px solid #eef2f7;color:#0f172a;font-size:14px;font-weight:600;font-family:Arial,Helvetica,sans-serif;">' . $row[1] . '</td></tr>';
        }
        return $html . '</table>';
    }

    /** §46 — big, copy-friendly one-time code block for stored templates. */
    function bc_template_code_block($placeholder) {
        return '<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:22px auto;"><tr><td style="border:1.5px solid #cbd5e1;border-radius:12px;background-color:#f8fafc;padding:16px 26px;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:32px;font-weight:800;letter-spacing:10px;color:#0f172a;">' . $placeholder . '</td></tr></table>';
    }

    /**
     * §46 — refresh stored templates that still hold the LEGACY default
     * bodies (full HTML documents with class-based flex rows) to the new
     * fragment defaults. A body counts as a legacy default only when it
     * still carries the old skeleton fingerprint AND was never rebuilt in
     * the GrapesJS builder (body_json empty) — real customisations are
     * never overwritten.
     */
    function bc_refresh_legacy_email_templates($vendor_defaults, $super_defaults) {
        global $connection_server;
        if (!($connection_server instanceof mysqli)) return;
        try {
            foreach ($super_defaults as $type => $sb) {
                $type_e = mysqli_real_escape_string($connection_server, $type);
                $q = mysqli_query($connection_server, "SELECT id, body, body_json FROM sas_super_admin_email_templates WHERE email_type='$type_e'");
                if (!$q) continue;
                while ($row = mysqli_fetch_assoc($q)) {
                    $legacy = (strpos($row['body'], 'details-row') !== false || strpos($row['body'], 'class="wrapper"') !== false || strpos($row['body'], '<!DOCTYPE html') !== false)
                        && trim((string)($row['body_json'] ?? '')) === '';
                    if ($legacy) {
                        $s_e = mysqli_real_escape_string($connection_server, $sb[0]);
                        $b_e = mysqli_real_escape_string($connection_server, $sb[1]);
                        mysqli_query($connection_server, "UPDATE sas_super_admin_email_templates SET subject='$s_e', body='$b_e' WHERE id='" . (int)$row['id'] . "'");
                    }
                }
            }
            foreach ($vendor_defaults as $type => $sb) {
                $type_e = mysqli_real_escape_string($connection_server, $type);
                $q = mysqli_query($connection_server, "SELECT id, body, body_json FROM sas_email_templates WHERE email_type='$type_e'");
                if (!$q) continue;
                while ($row = mysqli_fetch_assoc($q)) {
                    $legacy = (strpos($row['body'], 'details-row') !== false || strpos($row['body'], 'class="wrapper"') !== false || strpos($row['body'], '<!DOCTYPE html') !== false)
                        && trim((string)($row['body_json'] ?? '')) === '';
                    if ($legacy) {
                        $s_e = mysqli_real_escape_string($connection_server, $sb[0]);
                        $b_e = mysqli_real_escape_string($connection_server, $sb[1]);
                        mysqli_query($connection_server, "UPDATE sas_email_templates SET subject='$s_e', body='$b_e' WHERE id='" . (int)$row['id'] . "'");
                    }
                }
            }
        } catch (Throwable $e) {
            // A template refresh must never break the migration include.
        }
    }

    $bc_vendor_email_defaults = array(
        'user-reg' => array(
            "Welcome to Our Platform - Complete Your Registration",
            "Dear {firstname} {lastname},<br/><br/>Thank you for registering with us. We are excited to have you on board!<br/><br/>Please find below the details you provided during registration:"
            . bc_template_details(array(
                array('Email address', '{email}'),
                array('Phone number', '{phone}'),
                array('Username', '{username}'),
                array('Home address', '{address}'),
            ))
            . "If you have any questions or need assistance, feel free to contact us."
        ),
        'user-log' => array(
            "User Login Notification",
            "Hello {firstname} {lastname},<br/><br/>There was a new sign-in to your account:"
            . bc_template_details(array(
                array('Username', '{username}'),
                array('IP Address', '{ip_address}'),
            ))
            . "If this was not you, change your password immediately and contact support."
        ),
        'user-pass-update' => array(
            "Password Update Notification",
            "Dear {firstname} {lastname},<br/><br/>Your password has been successfully updated.<br/><br/>If you did not make this change, please contact our support team immediately."
        ),
        'user-account-update' => array(
            "Account Information Updated",
            "Dear {firstname} {lastname},<br/><br/>Your account information has been successfully updated."
            . bc_template_details(array(
                array('Email', '{email}'),
                array('Phone', '{phone}'),
                array('Address', '{address}'),
                array('Security Answer', '{security_answer}'),
            ))
        ),
        'user-account-recovery' => array(
            "Password Recovery",
            "Hello {firstname} {lastname},<br/><br/>We received a request to recover your account password. Use the code below to reset your password:"
            . bc_template_code_block('{recovery_code}')
            . "This code can be used once. If you did not request a password reset, ignore this email — your password will not change."
        ),
        'user-account-status' => array(
            "User Account Status Update",
            "Hello {firstname} {lastname},<br/><br/>We are writing to inform you about your account status.<br/><br/>Your account is currently <strong>{account_status}</strong>."
        ),
        'user-api-status' => array(
            "User API Status Update",
            "Hello {firstname} {lastname},<br/><br/>We wanted to inform you about the current status of your API access:<br/><br/>{api_status}"
        ),
        'user-upgrade' => array(
            "Upgrade Notification",
            "Hello {firstname} {lastname},<br/><br/>We are pleased to inform you that your account has been upgraded to <strong>{account_level}</strong>. Thank you for choosing our service."
        ),
        'user-referral-commission' => array(
            "Referral Commission Earned",
            "Hello {firstname} {lastname},<br/><br/>We are pleased to inform you that you have earned a referral commission of <strong>{referral_commission}</strong>."
            . bc_template_details(array(
                array('Referree', '{referree}'),
                array('Account Level', '{account_level}'),
            ))
        ),
        // Sent to the vendor when one of their users transacts. The
        // customer's phone number and email are always included so the
        // vendor can reach them about the transaction.
        'user-transactions' => array(
            "Transaction Alert: {firstname} {lastname} ({username})",
            "Hello {admin_firstname} {admin_lastname},<br/><br/>A transaction has been completed by one of your customers:"
            . bc_template_details(array(
                array('Customer', '{firstname} {lastname}'),
                array('Username', '{username}'),
                array('Phone number', '{phone}'),
                array('Email', '{email}'),
                array('Type', '{type}'),
                array('Amount', '{amount}'),
                array('Balance before', '{balance_before}'),
                array('Balance after', '{balance_after}'),
                array('Status', '{status}'),
                array('Reference', '{reference}'),
                array('Date', '{date}'),
            ))
            . "<strong>Description:</strong><br/>{description}"
        ),
        // Sent to the user as their own wallet receipt.
        'user-funding' => array(
            "Your Wallet Transaction Receipt",
            "Hello {firstname} {lastname},<br/><br/>Your wallet has been updated. Here is your transaction receipt:"
            . bc_template_details(array(
                array('Type', '{type}'),
                array('Amount', '{amount}'),
                array('Balance before', '{balance_before}'),
                array('Balance after', '{balance_after}'),
                array('Status', '{status}'),
                array('Reference', '{reference}'),
                array('Date', '{date}'),
                array('Phone number', '{phone}'),
                array('Email', '{email}'),
            ))
            . "<strong>Description:</strong><br/>{description}<br/><br/>If you did not make this transaction, please contact support immediately."
        ),
        'user-refund' => array(
            "Refund Notification",
            "Dear {firstname} {lastname},<br/><br/>We are pleased to inform you that a refund has been processed for you."
            . bc_template_details(array(
                array('Amount', '{amount}'),
            ))
            . "<strong>Description:</strong><br/>{description}"
        ),
    );

    $bc_super_email_defaults = array(
        'vendor-reg' => array(
            "Vendor Registration Confirmation",
            "Hello,<br/><br/>We are delighted to welcome you as a potential vendor.<br/><br/>Please find below the details you provided:"
            . bc_template_details(array(
                array('Name', '{firstname} {lastname}'),
                array('Email', '{email}'),
                array('Phone', '{phone}'),
                array('Website', '{website}'),
            ))
        ),
        'vendor-log' => array(
            "Vendor Login Notification",
            "Hello {firstname} {lastname},<br/><br/>We wanted to inform you that there has been a login to your vendor account."
            . bc_template_details(array(
                array('IP Address', '{ip_address}'),
            ))
            . "If this was not you, change your password immediately and contact support."
        ),
        'vendor-pass-update' => array(
            "Password Update Notification",
            "Hello {firstname} {lastname},<br/><br/>Your vendor account password has been updated successfully."
        ),
        'vendor-account-update' => array(
            "Vendor Account Update",
            "Dear {firstname} {lastname},<br/><br/>Your vendor account information has been updated successfully."
            . bc_template_details(array(
                array('Email', '{email}'),
                array('Phone', '{phone}'),
                array('Website', '{website}'),
            ))
        ),
        'vendor-account-recovery' => array(
            "Password Recovery",
            "Hello {firstname} {lastname},<br/><br/>You have requested a password recovery for your vendor account. Use the code below to reset your password:"
            . bc_template_code_block('{recovery_code}')
            . "This code can be used once. If you did not request it, ignore this email — your password will not change."
        ),
        'vendor-account-status' => array(
            "Vendor Account Status",
            "Hello {firstname} {lastname},<br/><br/>Your vendor account status is: <strong>{account_status}</strong>"
        ),
        // Sent to the super admin when a vendor transacts — includes the
        // vendor's phone number and email for follow-up.
        'vendor-transactions' => array(
            "Vendor Transaction Alert",
            "Hello {admin_firstname} {admin_lastname},<br/><br/>A new transaction has been made by a vendor:"
            . bc_template_details(array(
                array('Vendor', '{firstname} {lastname}'),
                array('Vendor email', '{email}'),
                array('Vendor phone', '{phone}'),
                array('Type', '{type}'),
                array('Amount', '{amount}'),
                array('Balance before', '{balance_before}'),
                array('Balance after', '{balance_after}'),
                array('Status', '{status}'),
                array('Reference', '{reference}'),
                array('Date', '{date}'),
            ))
            . "<strong>Description:</strong><br/>{description}"
        ),
        'vendor-funding' => array(
            "Vendor Wallet Transaction",
            "Hello {firstname} {lastname},<br/><br/>This email is to inform you about a recent transaction on your vendor account."
            . bc_template_details(array(
                array('Phone number', '{phone}'),
                array('Email', '{email}'),
                array('Type', '{type}'),
                array('Amount', '{amount}'),
                array('Balance before', '{balance_before}'),
                array('Balance after', '{balance_after}'),
                array('Status', '{status}'),
                array('Reference', '{reference}'),
                array('Date', '{date}'),
            ))
            . "<strong>Description:</strong><br/>{description}"
        ),
        'vendor-refund' => array(
            "Vendor Refund Notification",
            "Hello {firstname} {lastname},<br/><br/>We are writing to inform you that a refund has been issued to your vendor account."
            . bc_template_details(array(
                array('Amount', '{amount}'),
            ))
            . "<strong>Reason:</strong><br/>{description}"
        ),
        'vendor-welcome-activated' => array(
            "Welcome! Your Vendor Account is Now Active",
            "Dear {firstname} {lastname},<br/><br/>Congratulations! Your vendor account has been approved and is now active.<br/><br/>Your subscription is active until <strong>{expiry_date}</strong>.<br/><br/><strong>To set up your domain, please use the following details:</strong>"
            . bc_template_details(array(
                array('Nameservers', '{domain_nameservers}'),
                array('IP Address (for A records)', '{domain_ip_address}'),
            ))
            . "Domain name registration is not free. You can register your domain through our suggested registrar: {domain_registrar_url}"
        ),
    );

    foreach ($bc_vendor_email_defaults as $bc_tpl_type => $bc_tpl_sb) {
        createVendorEmailTemplateIfNotExists($bc_tpl_type, $bc_tpl_sb[0], $bc_tpl_sb[1]);
    }
    foreach ($bc_super_email_defaults as $bc_tpl_type => $bc_tpl_sb) {
        createSuperAdminEmailTemplateIfNotExists($bc_tpl_type, $bc_tpl_sb[0], $bc_tpl_sb[1]);
    }
    // Existing installs keep their stored rows (seed-if-missing above), so
    // push the new defaults into rows that still hold a legacy default.
    bc_refresh_legacy_email_templates($bc_vendor_email_defaults, $bc_super_email_defaults);
