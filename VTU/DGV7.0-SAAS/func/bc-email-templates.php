<?php
    // Professional Skeleton for Email Templates
    $email_skeleton = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin: 0; padding: 0; background-color: #f4f7fa; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f4f7fa; padding-bottom: 40px; }
        .main { background-color: #ffffff; margin: 0 auto; width: 100%; max-width: 600px; border-spacing: 0; color: #1e293b; border-radius: 12px; overflow: hidden; margin-top: 20px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .header { background-color: #ffffff; padding: 25px; text-align: center; border-bottom: 1px solid #f1f5f9; }
        .content { padding: 30px 25px; line-height: 1.6; }
        .footer { padding: 20px; text-align: center; color: #64748b; font-size: 12px; }
        .button { display: inline-block; padding: 12px 24px; background-color: #287bff; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: bold; margin-top: 20px; }
        .details-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin: 20px 0; }
        .details-row { display: flex; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px; }
        .details-label { font-weight: bold; color: #64748b; font-size: 14px; text-align: left; }
        .details-value { color: #0f172a; font-size: 14px; text-align: right; }
        @media only screen and (max-width: 600px) {
            .main { width: 95% !important; margin-top: 10px !important; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <table class="main" role="presentation">
            <tr>
                <td class="header">
                    <h2 style="margin: 0; color: #287bff;">{{TITLE}}</h2>
                </td>
            </tr>
            <tr>
                <td class="content">
                    <div style="color: #475569; font-size: 16px;">
                        {{BODY}}
                    </div>
                    <div style="text-align: center; margin-top: 30px;">
                        <a href="https://{website_url}/web/Dashboard.php" class="button">Go to Dashboard</a>
                    </div>
                </td>
            </tr>
            {{APP_SECTION}}
            <tr>
                <td class="footer">
                    <p style="margin-bottom: 10px;">&copy; ' . date("Y") . ' {website_url}. All rights reserved.</p>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>';

    function getTemplateHtml($title, $body, $app_section = true) {
        global $email_skeleton;
        $app_html = $app_section ? '<tr>
                <td style="padding: 20px 25px; background-color: #f8fafc; text-align: center;">
                    <p style="margin: 0; font-size: 14px; color: #64748b; font-weight: bold;">DOWNLOAD AND INSTALL APP</p>
                    <div style="margin-top: 10px;">
                         <a href="#"><img src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg" height="40"></a>
                    </div>
                </td>
            </tr>' : '';

        $html = str_replace('{{TITLE}}', $title, $email_skeleton);
        $html = str_replace('{{BODY}}', $body, $html);
        $html = str_replace('{{APP_SECTION}}', $app_html, $html);
        return $html;
    }

	//User Registration
	createVendorEmailTemplateIfNotExists(
		"user-reg",
		"Welcome to Our Platform - Complete Your Registration",
		getTemplateHtml("Welcome!", "Dear {firstname} {lastname},<br/><br/>Thank you for registering with us. We are excited to have you on board!<br/><br/>Please find below the details you provided during registration:<br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Email address:</span> <span class=\"details-value\">{email}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Phone number:</span> <span class=\"details-value\">{phone}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Username:</span> <span class=\"details-value\">{username}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Home address:</span> <span class=\"details-value\">{address}</span></div>
        </div>
        If you have any questions or need assistance, feel free to contact us.")
	);

	//User Login
	createVendorEmailTemplateIfNotExists(
		"user-log",
		"User Login Notification",
		getTemplateHtml("Login Alert", "Hello {firstname} {lastname},<br/><br/>Your login details are as follows:<br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Username:</span> <span class=\"details-value\">{username}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">IP Address:</span> <span class=\"details-value\">{ip_address}</span></div>
        </div>")
	);

	//User Password Update
	createVendorEmailTemplateIfNotExists(
		"user-pass-update",
		"Password Update Notification",
		getTemplateHtml("Password Updated", "Dear {firstname} {lastname},<br/><br/>Your password has been successfully updated.<br/><br/>If you did not make this change, please contact our support team immediately.")
	);

	//User Account Update
	createVendorEmailTemplateIfNotExists(
		"user-account-update",
		"Account Information Updated",
		getTemplateHtml("Account Updated", "Dear {firstname} {lastname},<br/><br/>Your account information has been successfully updated.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Email:</span> <span class=\"details-value\">{email}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Phone:</span> <span class=\"details-value\">{phone}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Address:</span> <span class=\"details-value\">{address}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Security Answer:</span> <span class=\"details-value\">{security_answer}</span></div>
        </div>")
	);

	//User Account Recovery
	createVendorEmailTemplateIfNotExists(
		"user-account-recovery",
		"Password Recovery",
		getTemplateHtml("Recovery Code", "Hello {firstname} {lastname},<br/><br/>We received a request to recover your account password.<br/><br/>Your recovery code is:<br/><h2 style=\"text-align:center; color:#287bff;\">{recovery_code}</h2><br/>Please use this code to reset your password.")
	);

	//User Account Status
	createVendorEmailTemplateIfNotExists(
		"user-account-status",
		"User Account Status Update",
		getTemplateHtml("Account Status Update", "Hello {firstname} {lastname},<br/><br/>We are writing to inform you about your account status.<br/><br/>Your account is currently <strong>{account_status}</strong>.")
	);

	//User API Status
	createVendorEmailTemplateIfNotExists(
		"user-api-status",
		"User API Status Update",
		getTemplateHtml("API Status Update", "Hello {firstname} {lastname},<br/><br/>We wanted to inform you about the current status of your API:<br/><br/>{api_status}")
	);

	//User 
	createVendorEmailTemplateIfNotExists(
		"user-upgrade",
		"Upgrade Notification",
		getTemplateHtml("Account Upgraded", "Hello {firstname} {lastname},<br/><br/>We are pleased to inform you that your account has been upgraded to <strong>{account_level}</strong>. Thank you for choosing our service.")
	);

	//User Referral Commission
	createVendorEmailTemplateIfNotExists(
		"user-referral-commission",
		"Referral Commission Earned",
		getTemplateHtml("Commission Earned", "Hello {firstname} {lastname},<br/><br/>We are pleased to inform you that you have earned a referral commission of <strong>{referral_commission}</strong>.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Referree:</span> <span class=\"details-value\">{referree}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Account Level:</span> <span class=\"details-value\">{account_level}</span></div>
        </div>")
	);

	//User Transaction (Admin)
	createVendorEmailTemplateIfNotExists(
		"user-transactions",
		"Transaction Details",
		getTemplateHtml("Transaction Alert", "Hello {admin_firstname} {admin_lastname},<br/><br/>A transaction has been made by user {username} ({firstname}).<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Previous balance:</span> <span class=\"details-value\">{balance_before}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">New balance:</span> <span class=\"details-value\">{balance_after}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Amount:</span> <span class=\"details-value\">{amount}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Type:</span> <span class=\"details-value\">{type}</span></div>
        </div>
        <strong>Description:</strong><br/>{description}")
	);

	//User Credit/Debit Transaction
	createVendorEmailTemplateIfNotExists(
		"user-funding",
		"Account Update: Transaction Details",
	getTemplateHtml("Wallet Update", "Hello {firstname} {lastname},<br/><br/>Your account has been updated with the following transaction details:<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Balance Before:</span> <span class=\"details-value\">{balance_before}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Balance After:</span> <span class=\"details-value\">{balance_after}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Amount:</span> <span class=\"details-value\">{amount}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Type:</span> <span class=\"details-value\">{type}</span></div>
        </div>
        <strong>Description:</strong><br/>{description}")
	);

	//User Refund
	createVendorEmailTemplateIfNotExists(
		"user-refund",
		"Refund Notification",
		getTemplateHtml("Refund Processed", "Dear {firstname} {lastname},<br/><br/>We are pleased to inform you that a refund has been processed for you.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Amount:</span> <span class=\"details-value\">{amount}</span></div>
        </div>
        <strong>Description:</strong><br/>{description}")
	);

	//Vendor Registration
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-reg",
		"Vendor Registration Confirmation",
		getTemplateHtml("Vendor Application", "Hello,<br/><br/>We are delighted to welcome you as a potential vendor.<br/><br/>Please find below the details you provided:<br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Name:</span> <span class=\"details-value\">{firstname} {lastname}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Email:</span> <span class=\"details-value\">{email}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Phone:</span> <span class=\"details-value\">{phone}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Website:</span> <span class=\"details-value\">{website}</span></div>
        </div>", false)
	);

	//Vendor Login
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-log",
		"Vendor Login Notification",
		getTemplateHtml("Vendor Login Alert", "Hello {firstname} {lastname},<br/><br/>We wanted to inform you that there has been a login to your vendor account.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">IP Address:</span> <span class=\"details-value\">{ip_address}</span></div>
        </div>", false)
	);

	//Vendor Password Update
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-pass-update",
		"Password Update Notification",
		getTemplateHtml("Password Updated", "Hello {firstname} {lastname},<br/><br/>Your vendor account password has been updated successfully.", false)
	);

	//Vendor Account Update
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-account-update",
		"Vendor Account Update",
		getTemplateHtml("Account Updated", "Dear {firstname} {lastname},<br/><br/>Your vendor account information has been updated successfully.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Email:</span> <span class=\"details-value\">{email}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Phone:</span> <span class=\"details-value\">{phone}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Website:</span> <span class=\"details-value\">{website}</span></div>
        </div>", false)
	);

	//Vendor Password Recovery
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-account-recovery",
		"Password Recovery",
		getTemplateHtml("Recovery Code", "Hello {firstname} {lastname},<br/><br/>You have requested a password recovery for your vendor account.<br/><br/>Your recovery code is:<br/><h2 style=\"text-align:center; color:#287bff;\">{recovery_code}</h2>", false)
	);

	//Vendor Account Status
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-account-status",
		"Vendor Account Status",
		getTemplateHtml("Account Status Update", "Hello {firstname} {lastname},<br/><br/>Your vendor account status is: <strong>{account_status}</strong>", false)
	);

	//Vendor Transaction (Super Admin)
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-transactions",
		"Vendor Transaction Details",
		getTemplateHtml("Vendor Transaction Alert", "Hello {admin_firstname} {admin_lastname},<br/><br/>A new transaction has been made by a vendor.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Vendor Name:</span> <span class=\"details-value\">{firstname}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Vendor Email:</span> <span class=\"details-value\">{email}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Amount:</span> <span class=\"details-value\">{amount}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Type:</span> <span class=\"details-value\">{type}</span></div>
        </div>
        <strong>Description:</strong><br/>{description}", false)
	);

	//Vendor Credit/Debit
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-funding",
		"Vendor Credit/Debit Notification",
		getTemplateHtml("Wallet Update", "Hello {firstname} {lastname},<br/><br/>This email is to inform you about a recent transaction on your vendor account.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Balance Before:</span> <span class=\"details-value\">{balance_before}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Balance After:</span> <span class=\"details-value\">{balance_after}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Amount:</span> <span class=\"details-value\">{amount}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">Type:</span> <span class=\"details-value\">{type}</span></div>
        </div>
        <strong>Description:</strong><br/>{description}", false)
	);

	//Vendor Refund Notification
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-refund",
		"Vendor Refund Notification",
		getTemplateHtml("Refund Processed", "Hello {firstname} {lastname},<br/><br/>We are writing to inform you that a refund has been issued to your vendor account.<br/><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Amount:</span> <span class=\"details-value\">{amount}</span></div>
        </div>
        <strong>Reason:</strong><br/>{description}", false)
	);

	// Vendor Welcome & Activation
	createSuperAdminEmailTemplateIfNotExists(
		"vendor-welcome-activated",
		"Welcome! Your Vendor Account is Now Active",
		getTemplateHtml("Welcome & Activation", "Dear {firstname} {lastname},<br/><br/>Congratulations! Your vendor account has been approved and is now active.<br/><br/>
        Your subscription is active until <strong>{expiry_date}</strong>.<br/><br/>
        <strong>To set up your domain, please use the following details:</strong><br/>
        <div class=\"details-box\">
            <div class=\"details-row\"><span class=\"details-label\">Nameservers:</span> <span class=\"details-value\">{domain_nameservers}</span></div>
            <div class=\"details-row\"><span class=\"details-label\">IP Address (for A records):</span> <span class=\"details-value\">{domain_ip_address}</span></div>
        </div>
        Domain name registration is not free. You can register your domain through our suggested registrar: {domain_registrar_url}", false)
	);
