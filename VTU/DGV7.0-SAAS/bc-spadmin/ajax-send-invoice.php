<?php session_start();
include("../func/bc-spadmin-config.php");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

// CSRF check
bc_validate_csrf(true);

if (!isset($_POST['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Registration ID missing.']);
    exit();
}

$id = mysqli_real_escape_string($connection_server, $_POST['id']);

// Fetch order details
$sql = "SELECT pv.*, bp.name as package_name, bp.price as package_price
        FROM sas_pending_vendors pv
        JOIN sas_billing_packages bp ON pv.billing_package_id = bp.id
        WHERE pv.id='$id'";
$res = mysqli_query($connection_server, $sql);
$order = mysqli_fetch_assoc($res);

if (!$order) {
    echo json_encode(['status' => 'error', 'message' => 'Order not found.']);
    exit();
}

// Prepare items for invoice
$items = [];
$items[] = ['name' => $order['package_name'] . ' Subscription', 'price' => (float)$order['package_price']];

if($order['order_apk']) $items[] = ['name' => 'Android APK Development', 'price' => (float)getSuperAdminOption('apk_development_price', '0')];
if($order['order_ios']) $items[] = ['name' => 'iOS App Development', 'price' => (float)getSuperAdminOption('ios_development_price', '0')];
if($order['order_playstore']) $items[] = ['name' => 'PlayStore Listing Service', 'price' => (float)getSuperAdminOption('playstore_listing_price', '0')];
if($order['order_sms_bridge']) $items[] = ['name' => 'PrintHub APP Service Integration', 'price' => (float)getSuperAdminOption('sms_bridge_price', '0')];

if(!empty($order['selected_addons'])) {
    $addon_ids = $order['selected_addons'];
    $addons_res = mysqli_query($connection_server, "SELECT name, price FROM sas_billing_addons WHERE id IN ($addon_ids)");
    while($ar = mysqli_fetch_assoc($addons_res)) {
        $items[] = ['name' => $ar['name'], 'price' => (float)$ar['price']];
    }
}

if((float)$order['domain_registration_fee'] > 0) {
    $items[] = ['name' => 'Domain Name Registration (' . $order['app_base_url'] . ')', 'price' => (float)$order['domain_registration_fee']];
}

// Site Details
$site_details_res = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_site_details LIMIT 1");
$site_details = mysqli_fetch_assoc($site_details_res);
$site_name = !empty($site_details['site_title']) ? $site_details['site_title'] : 'Super Admin Platform';

$spadmin_res = mysqli_query($connection_server, "SELECT email, phone_number FROM sas_super_admin LIMIT 1");
$spadmin_details = mysqli_fetch_assoc($spadmin_res);
$admin_email = !empty($spadmin_details['email']) ? $spadmin_details['email'] : 'support@cheaperdata.com.ng';
$admin_phone = !empty($spadmin_details['phone_number']) ? $spadmin_details['phone_number'] : '';

// Bank Details from sas_super_admin_payments
$bank_details_res = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payments LIMIT 1");
$bank_details = mysqli_fetch_assoc($bank_details_res);
$bank_name = !empty($bank_details['bank_name']) ? $bank_details['bank_name'] : 'N/A';
$account_name = !empty($bank_details['account_name']) ? $bank_details['account_name'] : 'N/A';
$account_number = !empty($bank_details['account_number']) ? $bank_details['account_number'] : 'N/A';

// Generate HTML Invoice
$invoice_html = '
<div style="background-color: #f4f6f9; padding: 20px 10px; font-family: system-ui, -apple-system, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Main Email Container -->
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #eef2f5;">
        <tr>
            <td style="padding: 0;">
                <!-- Header Banner with Gradient Accent -->
                <table width="100%" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); color: #ffffff; text-align: center; padding: 40px 20px;">
                    <tr>
                        <td>
                            <h1 style="margin: 0; font-size: 26px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #ffffff;">' . htmlspecialchars(strtoupper($site_name)) . '</h1>
                            <p style="margin: 8px 0 0 0; font-size: 14px; color: #e2ecff; opacity: 0.9;">Professional Invoice for Your Fintech Platform</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding: 30px 24px;">
                <!-- Billing & Invoice Details Stack -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                    <tr>
                        <td style="padding: 0;">
                            <!--[if mso]>
                            <table width="100%" cellpadding="0" cellspacing="0"><tr><td width="280" valign="top">
                            <![endif]-->
                            <div style="display: inline-block; width: 100%; max-width: 270px; vertical-align: top; margin-bottom: 20px;">
                                <p style="margin: 0 0 6px 0; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #8b96a5;">Bill To</p>
                                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b;">' . htmlspecialchars($order['firstname'] . ' ' . $order['lastname']) . '</h3>
                                <p style="margin: 4px 0 0 0; font-size: 14px; color: #475569;">' . htmlspecialchars($order['email']) . '</p>
                                <p style="margin: 2px 0 0 0; font-size: 13px; color: #0d6efd; font-weight: 500;">' . htmlspecialchars($order['website_url']) . '</p>
                            </div>
                            <!--[if mso]>
                            </td><td width="20">&nbsp;</td><td width="250" valign="top">
                            <![endif]-->
                            <div style="display: inline-block; width: 100%; max-width: 260px; vertical-align: top; text-align: left;">
                                <p style="margin: 0 0 6px 0; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #8b96a5;">Invoice Details</p>
                                <table cellpadding="0" cellspacing="0" style="font-size: 14px; color: #475569;">
                                    <tr>
                                        <td style="padding-bottom: 4px; padding-right: 10px; font-weight: 600; color: #1e293b;">Invoice No:</td>
                                        <td style="padding-bottom: 4px;">#' . str_pad($order['id'], 5, '0', STR_PAD_LEFT) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding-bottom: 4px; padding-right: 10px; font-weight: 600; color: #1e293b;">Date:</td>
                                        <td style="padding-bottom: 4px;">' . date('F d, Y') . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding-right: 10px; font-weight: 600; color: #1e293b;">Status:</td>
                                        <td><span style="background-color: #ffe8e6; color: #dc3545; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase;">Awaiting Payment</span></td>
                                    </tr>
                                </table>
                            </div>
                            <!--[if mso]>
                            </td></tr></table>
                            <![endif]-->
                        </td>
                    </tr>
                </table>

                <!-- Divider -->
                <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 25px 0;" />

                <!-- Table of Services -->
                <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0;">
                            <th align="left" style="padding: 10px 0; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #8b96a5; letter-spacing: 0.5px;">Service / Item</th>
                            <th align="right" style="padding: 10px 0; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #8b96a5; letter-spacing: 0.5px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>';

foreach ($items as $item) {
    $invoice_html .= '
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 14px 0; font-size: 14px; font-weight: 500; color: #1e293b;">' . htmlspecialchars($item['name']) . '</td>
                            <td align="right" style="padding: 14px 0; font-size: 14px; font-weight: 600; color: #1e293b;">₦' . number_format($item['price'], 2) . '</td>
                        </tr>';
}

$invoice_html .= '
                    </tbody>
                    <tfoot>
                        <tr>
                            <td style="padding: 20px 0 10px 0; font-size: 16px; font-weight: 700; color: #1e293b;">Total Amount Due:</td>
                            <td align="right" style="padding: 20px 0 10px 0; font-size: 22px; font-weight: 800; color: #0d6efd;">₦' . number_format($order['total_amount'], 2) . '</td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Payment Instructions -->
                <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #fffaf0; border-left: 4px solid #ffc107; border-radius: 6px; margin-bottom: 25px; border-collapse: separate;">
                    <tr>
                        <td style="padding: 20px;">
                            <h4 style="margin: 0 0 10px 0; font-size: 15px; font-weight: 700; color: #856404; text-transform: uppercase; letter-spacing: 0.5px;">How To Pay</h4>
                            <p style="margin: 0 0 16px 0; font-size: 13.5px; line-height: 1.5; color: #664d03;">Please make bank transfer of the total sum to the official account details below and submit your payment receipt in the vendor portal.</p>
                            <table width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; color: #1e293b; background-color: #ffffff; border-radius: 8px; border: 1px solid #ffeeba; padding: 12px 16px;">
                                <tr>
                                    <td width="35%" style="padding: 6px 0; font-weight: 600; color: #475569;">Bank:</td>
                                    <td width="65%" style="padding: 6px 0; font-weight: 700; color: #1e293b;">' . htmlspecialchars($bank_name) . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; font-weight: 600; color: #475569;">Account Name:</td>
                                    <td style="padding: 6px 0; font-weight: 700; color: #1e293b;">' . htmlspecialchars($account_name) . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; font-weight: 600; color: #475569;">Account Number:</td>
                                    <td style="padding: 6px 0; font-size: 16px; font-weight: 800; color: #0d6efd; letter-spacing: 0.5px;">' . htmlspecialchars($account_number) . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding: 24px; background-color: #f8fafc; border-top: 1px solid #f1f5f9; text-align: center;">
                <p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.5;">If you have any questions or require support, contact us at <a href="mailto:' . htmlspecialchars($admin_email) . '" style="color: #0d6efd; text-decoration: none; font-weight: 500;">' . htmlspecialchars($admin_email) . '</a></p>
                <p style="margin: 8px 0 0 0; font-size: 11px; color: #94a3b8;">&copy; ' . date('Y') . ' ' . htmlspecialchars($site_name) . '. All rights reserved.</p>
            </td>
        </tr>
    </table>
</div>';

// Send the Email
$subject = "Official Invoice - Order #" . str_pad($order['id'], 5, '0', STR_PAD_LEFT) . " | " . $site_name;

if (sendVendorEmail($order['email'], $subject, $invoice_html)) {
    echo json_encode(['status' => 'success', 'message' => 'Invoice sent successfully.']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to deliver email. Please check your SMTP settings.']);
}
