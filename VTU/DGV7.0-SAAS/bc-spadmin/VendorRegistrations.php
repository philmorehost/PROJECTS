<?php session_start();
    include("../func/bc-spadmin-config.php");

    // Handle actions
    if(isset($_GET['action']) && isset($_GET['id'])) {
        $action = $_GET['action'];
        $pending_id = mysqli_real_escape_string($connection_server, $_GET['id']);

        // Mark as Paid for manual deposit
        if($action == 'mark_paid') {
            mysqli_query($connection_server, "UPDATE sas_pending_vendors SET payment_status='paid' WHERE id='$pending_id'") or die("Error marking as paid: " . mysqli_error($connection_server));
            $_SESSION['page_alert'] = "Vendor marked as paid.";
            header("Location: VendorRegistrations.php");
            exit();
        }

        $result = mysqli_query($connection_server, "SELECT * FROM sas_pending_vendors WHERE id='$pending_id'") or die("Error fetching pending vendor: " . mysqli_error($connection_server));
        if(mysqli_num_rows($result) > 0) {
            $pending_vendor = mysqli_fetch_assoc($result);

            if($action == 'approve') {
                if(trim($pending_vendor['payment_status']) == 'paid') {
                    // 1. Get package details
                    $package_id = $pending_vendor['billing_package_id'];
                    $package_result = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages WHERE id='$package_id'") or die("Error fetching package details: " . mysqli_error($connection_server));
                    $package = mysqli_fetch_assoc($package_result);
                    $duration_days = $package['duration_days'];

                    // 2. Insert into main vendors table
                    $email = $pending_vendor['email'];
                    $password = $pending_vendor['password'];
                    $firstname = $pending_vendor['firstname'];
                    $lastname = $pending_vendor['lastname'];
                    $phone_number = $pending_vendor['phone_number'];
                    $website_url = $pending_vendor['website_url'];
                    $home_address = $pending_vendor['home_address'];
                    $min_with = $pending_vendor['min_withdrawal_amount'];
                    $max_with = $pending_vendor['max_withdrawal_amount'];
                    $payout_limit = $pending_vendor['daily_payout_limit'];

                    $app_base_url = $pending_vendor['app_base_url'];
                    $apk_ordered = $pending_vendor['order_apk'];
                    $ios_ordered = $pending_vendor['order_ios'];
                    $playstore_ordered = $pending_vendor['order_playstore'];
                    $sms_bridge_ordered = $pending_vendor['order_sms_bridge'];
                    $selected_addons = $pending_vendor['selected_addons'];
                    
                    // Generate unique access hash for the order portal
                    $access_hash = bin2hex(random_bytes(32));

                    $insert_sql = "INSERT INTO sas_vendors (email, access_hash, password, firstname, lastname, phone_number, website_url, home_address, balance, status, min_withdrawal_amount, max_withdrawal_amount, daily_payout_limit, app_base_url, apk_ordered, ios_ordered, playstore_ordered, sms_bridge_ordered, selected_addons)
                                   VALUES ('$email', '$access_hash', '$password', '$firstname', '$lastname', '$phone_number', '$website_url', '$home_address', '0.00', '1', '$min_with', '$max_with', '$payout_limit', '$app_base_url', '$apk_ordered', '$ios_ordered', '$playstore_ordered', '$sms_bridge_ordered', '$selected_addons')";

                        if(mysqli_query($connection_server, $insert_sql)) {
                        $new_vendor_id = mysqli_insert_id($connection_server);

                            // Trigger WHMCS Domain Registration if domain fee was paid
                            if ((float)$pending_vendor['domain_registration_fee'] > 0 && !empty($pending_vendor['app_base_url'])) {
                                include_once("../func/bc-func.php");
                                include_once("../func/whmcs-func.php");
                                // We need a WHMCS Client ID. For now, we use a placeholder or logic to find/create one.
                                // In a real scenario, you might have already created the client or have a dedicated account for automation.
                                $whmcs_client_id = getSuperAdminOption('whmcs_default_client_id', '1');
                                whmcsCreateOrder($whmcs_client_id, $pending_vendor['app_base_url']);
                            }

                        // 3. Set subscription dates
                        $start_date = date("Y-m-d");
                        $expiry_date = date('Y-m-d', strtotime("+$duration_days days"));

                        // 4. Update vendor with subscription info
                        $update_sql = "UPDATE sas_vendors SET start_date='$start_date', expiry_date='$expiry_date', current_billing_id='$package_id' WHERE id='$new_vendor_id'";
                        mysqli_query($connection_server, $update_sql) or die("Error updating vendor subscription: " . mysqli_error($connection_server));

                        // 5. Log this initial subscription to the history table
                        $price = $package['price'];
                        $purchase_date = date('Y-m-d H:i:s');
                        $log_sql = "INSERT INTO sas_vendor_subscriptions (vendor_id, package_id, purchase_date, expiry_date, amount_paid) VALUES ('$new_vendor_id', '$package_id', '$purchase_date', '$expiry_date', '$price')";
                        mysqli_query($connection_server, $log_sql) or die("Error logging subscription: " . mysqli_error($connection_server));

                        // 6. Delete from pending vendors
                        mysqli_query($connection_server, "DELETE FROM sas_pending_vendors WHERE id='$pending_id'") or die("Error deleting from pending vendors: " . mysqli_error($connection_server));

                        // 6b. Add addon domain via cPanel API
                        include_once(__DIR__ . "/../func/cpanel-func.php");
                        cpanel_add_addon_domain($website_url);

                        // Fetch domain settings to include in the welcome email
                        $nameservers = '';
                        $ip_address = '';
                        $registrar_url = '';
                        $sql_fetch_settings = "SELECT * FROM sas_super_admin_options WHERE option_name IN ('domain_nameservers', 'domain_ip_address', 'domain_registrar_url')";
                        $settings_result = mysqli_query($connection_server, $sql_fetch_settings);
                        while($row = mysqli_fetch_assoc($settings_result)) {
                            if($row['option_name'] == 'domain_nameservers') {
                                $nameservers = nl2br(htmlspecialchars($row['option_value']));
                            }
                            if($row['option_name'] == 'domain_ip_address') {
                                $ip_address = htmlspecialchars($row['option_value']);
                            }
                            if($row['option_name'] == 'domain_registrar_url') {
                                $registrar_url = htmlspecialchars($row['option_value']);
                            }
                        }

                        // Portal Link
                        $portal_link = bc_temporary_vendor_portal_url($access_hash);

                        // Send welcome email
                        $template_includes_portal_link = strpos(getSuperAdminEmailTemplate('vendor-welcome-activated', 'body'), '{portal_link}') !== false;
                        $email_placeholders = array(
                            "{firstname}" => $firstname,
                            "{lastname}" => $lastname,
                            "{expiry_date}" => date('F j, Y', strtotime($expiry_date)),
                            "{domain_nameservers}" => $nameservers,
                            "{domain_ip_address}" => $ip_address,
                            "{domain_registrar_url}" => $registrar_url,
                            "{portal_link}" => $portal_link !== ''
                                ? '<a href="' . htmlspecialchars($portal_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($portal_link, ENT_QUOTES, 'UTF-8') . '</a>'
                                : 'Temporary vendor access is not configured yet. Please contact the super admin for assistance.'
                        );
                        $email_subject = getSuperAdminEmailTemplate('vendor-welcome-activated', 'subject');
                        $email_body = getSuperAdminEmailTemplate('vendor-welcome-activated', 'body');
                        foreach($email_placeholders as $key => $val) {
                            $email_subject = str_replace($key, $val, $email_subject);
                            $email_body = str_replace($key, $val, $email_body);
                        }

                        // Append instructions for the temporary URL
                        $expiry_days = max(1, min(30, (int)getSuperAdminOption('tmp_url_expiry_days', '3')));
                        $tmp_instructions = $portal_link !== '' ? "
                        <br><br>
                        <strong>Temporary Dashboard Access:</strong><br>
                        While your domain name (<strong>{$website_url}</strong>) is propagating, you can access your temporary admin dashboard through your Portal Link above. This temporary access will be valid for <strong>{$expiry_days} days</strong>.
                        " : "
                        <br><br><strong>Temporary Dashboard Access:</strong><br>
                        Temporary vendor access has not been configured yet. Please contact the super admin for help.
                        ";
                        if ($portal_link !== '' && !$template_includes_portal_link && strpos($email_body, 'Portal Link') === false) {
                           // if {portal_link} was missing from the template, ensure they get it
                           $email_body .= "<br><br><strong>Portal Link:</strong> <a href=\"{$portal_link}\">{$portal_link}</a>";
                        }
                        $email_body .= $tmp_instructions;

                        sendVendorEmail($email, $email_subject, $email_body);

                        $_SESSION['page_alert'] = "Vendor approved and account activated.";
                    } else {
                        $_SESSION['page_alert'] = "Error approving vendor: " . mysqli_error($connection_server);
                    }
                } else {
                    $_SESSION['page_alert'] = "Cannot approve vendor. Payment has not been confirmed.";
                }

            } elseif($action == 'decline') {
                $email = $pending_vendor['email'];
                $firstname = $pending_vendor['firstname'];
                $lastname = $pending_vendor['lastname'];

                mysqli_query($connection_server, "DELETE FROM sas_pending_vendors WHERE id='$pending_id'") or die("Error declining vendor: " . mysqli_error($connection_server));

                // Send rejection email
                $email_placeholders = array(
                    "{firstname}" => $firstname,
                    "{lastname}" => $lastname
                );
                $email_subject = getSuperAdminEmailTemplate('vendor-rejection', 'subject');
                $email_body = getSuperAdminEmailTemplate('vendor-rejection', 'body');
                foreach($email_placeholders as $key => $val) {
                    $email_subject = str_replace($key, $val, $email_subject);
                    $email_body = str_replace($key, $val, $email_body);
                }
                sendVendorEmail($email, $email_subject, $email_body);

                $_SESSION['page_alert'] = "Vendor registration declined.";
            }
        } else {
            $_SESSION['page_alert'] = "Invalid registration ID.";
        }
        header("Location: VendorRegistrations.php");
        exit();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Pending Vendor Registrations</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
        <h1>Pending Registrations</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Pending Registrations</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-4 border-0">
                        <h5 class="fw-bold mb-0 text-primary">Pending Vendor Approvals</h5>
                        <p class="text-muted small mb-0">Review and authorize new vendor registration requests</p>
                    </div>
                    <div class="card-body p-0">
                        <?php if(isset($_SESSION['page_alert'])): ?>
                            <div class="px-4 pt-3">
                                <div class="alert alert-info alert-dismissible fade show rounded-3 border-0 shadow-sm" role="alert">
                                    <i class="bi bi-info-circle me-2"></i><?php echo $_SESSION['page_alert']; unset($_SESSION['page_alert']); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-4">#</th>
                                        <th>Vendor Identity</th>
                                        <th>Subscription</th>
                                        <th>Order Summary</th>
                                        <th>Payment Verification</th>
                                        <th>Date</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $sql = "SELECT pv.*, bp.name as package_name
                                                FROM sas_pending_vendors pv
                                                JOIN sas_billing_packages bp ON pv.billing_package_id = bp.id
                                                ORDER BY pv.reg_date DESC";
                                        $result = mysqli_query($connection_server, $sql) or die("Error fetching registration list: " . mysqli_error($connection_server));
                                        $count = 1;
                                        if(mysqli_num_rows($result) > 0):
                                            while($row = mysqli_fetch_assoc($result)):
                                    ?>
                                    <tr>
                                        <td class="ps-4 text-muted"><?php echo $count++; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></div>
                                            <div class="small text-primary"><i class="bi bi-link-45deg"></i> <?php echo htmlspecialchars($row['website_url']); ?></div>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($row['email']); ?> | <?php echo htmlspecialchars($row['phone_number']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary bg-opacity-10 text-dark-primary rounded-pill px-3"><?php echo htmlspecialchars($row['package_name']); ?></span>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <ul class="list-unstyled mb-0">
                                                    <li><i class="bi bi-check2-circle text-success me-1"></i> <?php echo htmlspecialchars($row['package_name']); ?></li>
                                                    <?php 
                                                        // Legacy checks
                                                        if($row['order_apk']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> Android APK</li><?php endif; 
                                                        if($row['order_ios']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> iOS App</li><?php endif; 
                                                        if($row['order_playstore']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> Playstore Listing</li><?php endif; 
                                                        if($row['order_sms_bridge']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> PrintHub APP</li><?php endif;
                                                        
                                                        // Dynamic Addons lookup
                                                        if(!empty($row['selected_addons'])) {
                                                            $addon_ids = explode(',', $row['selected_addons']);
                                                            $addon_ids_clean = array_map('intval', $addon_ids);
                                                            $addon_ids_str = implode(',', $addon_ids_clean);
                                                            $addons_res = mysqli_query($connection_server, "SELECT name FROM sas_billing_addons WHERE id IN ($addon_ids_str)");
                                                            if($addons_res) {
                                                                while($addon_row = mysqli_fetch_assoc($addons_res)) {
                                                                    echo "<li><i class='bi bi-plus-circle text-primary me-1'></i>".htmlspecialchars($addon_row['name'])."</li>";
                                                                }
                                                            }
                                                        }

                                                        if($row['domain_registration_fee'] > 0): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> Domain (₦<?php echo number_format($row['domain_registration_fee'], 0); ?>)</li><?php endif; 
                                                    ?>
                                                </ul>
                                                <div class="mt-2 pt-2 border-top">
                                                    <strong class="text-primary">Total: ₦<?php echo number_format($row['total_amount'], 2); ?></strong>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small"><strong>Method:</strong> <?php echo ucwords(str_replace('_', ' ', $row['payment_method'])); ?></div>
                                            <div class="mt-1">
                                                <?php if(trim($row['payment_status']) == 'paid'): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">Verified Paid</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-2">Pending Confirmation</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if(!empty($row['paystack_reference'])): ?>
                                                <div class="small text-muted mt-1"><i class="bi bi-hash"></i> <?php echo htmlspecialchars($row['paystack_reference']); ?></div>
                                            <?php endif; ?>
                                            <?php if(!empty($row['payment_proof_path'])): ?>
                                                <div class="mt-1"><a href="/<?php echo htmlspecialchars($row['payment_proof_path']); ?>" target="_blank" class="btn btn-link btn-sm p-0 fw-bold"><i class="bi bi-image me-1"></i>View Proof</a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?php echo date('M d, Y', strtotime($row['reg_date'])); ?></td>
                                        <td class="text-end pe-4">
                                            <div class="d-flex flex-column gap-1 align-items-end">
                                                <?php if(($row['payment_method'] == 'bank_deposit' || $row['payment_method'] == 'paystack') && trim($row['payment_status']) != 'paid'): ?>
                                                    <a href="?action=mark_paid&id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm rounded-pill px-3 fw-bold shadow-sm" onclick="return confirm('Confirm receipt of manual payment?');">Verify Payment</a>
                                                <?php endif; ?>
                                                
                                                <div class="btn-group btn-group-sm mt-1">
                                                    <button type="button" class="btn btn-outline-primary rounded-start-pill px-3 send-invoice" data-id="<?php echo $row['id']; ?>" title="Send Invoice Email">
                                                        <i class="bi bi-envelope-paper"></i> Invoice
                                                    </button>
                                                    <a href="EditVendorOrder.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-secondary rounded-end-pill px-3" title="Edit Order Details">
                                                        <i class="bi bi-pencil-square"></i> Edit
                                                    </a>
                                                </div>

                                                <div class="btn-group btn-group-sm mt-1">
                                                    <a href="?action=approve&id=<?php echo $row['id']; ?>" class="btn btn-primary <?php if(trim($row['payment_status']) != 'paid') echo 'disabled'; ?> rounded-start-pill px-3 fw-bold" onclick="return confirm('Approve and activate vendor account?');">Approve</a>
                                                    <a href="?action=decline&id=<?php echo $row['id']; ?>" class="btn btn-outline-danger rounded-end-pill px-3" onclick="return confirm('Reject and delete registration?');">Decline</a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">No pending registrations at the moment</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>

    <script>
        document.querySelectorAll('.send-invoice').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const btn = this;
                const originalText = btn.innerHTML;
                
                if(!confirm('Send professional invoice to this vendor?')) return;

                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...';

                fetch('ajax-send-invoice.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id + '&_csrf_token=<?php echo bc_generate_csrf_token(); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if(data.status === 'success') {
                        alert('Invoice successfully sent to vendor.');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('A system error occurred while sending the invoice.');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
            });
        });
    </script>
</body>
</html>