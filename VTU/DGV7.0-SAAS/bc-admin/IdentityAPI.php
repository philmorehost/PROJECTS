<?php session_start();
    include("../func/bc-admin-config.php");
	
	if(isset($_POST["update-identity-provider"])){
        $allowed_providers = ["monnify", "dojah", "qoreid", "smileid", "prembly", "localhost"];
        $requested_provider = strtolower(trim(strip_tags($_POST["identity_provider"] ?? "monnify")));
        // Premium providers (Monnify/Prembly/Dojah/QoreID/Smile) require activation; the Local
        // Marketplace (vendor-to-vendor) API is FREE to install.
        if ($requested_provider !== 'localhost' && (int)($get_logged_admin_details['identity_api_enabled'] ?? 0) !== 1) {
            $_SESSION["product_purchase_response"] = "Premium identity providers are locked. Activate Identity API access (one-time fee) or choose the free Local Marketplace (vendor-to-vendor) API.";
            header("Location: IdentityAPI.php");
            exit();
        }
        $identity_provider_gateways = ["prembly", "dojah", "qoreid", "smileid"];
        $vendor_id = (int)$get_logged_admin_details["id"];

        $selected_provider = $requested_provider;
        if (!in_array($selected_provider, $allowed_providers, true)) $selected_provider = "monnify";
        $selected_provider_esc = mysqli_real_escape_string($connection_server, $selected_provider);
        
        $identity_api_id = (int)($_POST["identity_api_id"] ?? 0);
        mysqli_query($connection_server, "UPDATE sas_vendors SET identity_provider='$selected_provider_esc', identity_api_id='$identity_api_id' WHERE id='$vendor_id'");

        // Save API keys for identity verification providers.
        // sas_payment_gateways has NO unique key on (vendor_id, gateway_name), so the old
        // "UPDATE if a row exists, else INSERT" could leave several rows behind and every
        // read then picked an arbitrary (often empty) one. Replace the provider's rows with
        // exactly one clean row instead. A blank field keeps the value already saved.
        foreach ($identity_provider_gateways as $gw_name) {
            $pub_in = trim(strip_tags($_POST["idp_public_".$gw_name] ?? ""));
            $sec_in = trim(strip_tags($_POST["idp_secret_".$gw_name] ?? ""));

            $existing = getIdentityGatewayRow($gw_name, $vendor_id, true);
            if ($pub_in === '' && $existing) $pub_in = (string)($existing['public_key'] ?? '');
            if ($sec_in === '' && $existing) $sec_in = (string)($existing['secret_key'] ?? '');
            if ($pub_in === '' && $sec_in === '') continue; // nothing configured, keep as-is

            $pub = mysqli_real_escape_string($connection_server, $pub_in);
            $sec = mysqli_real_escape_string($connection_server, $sec_in);

            mysqli_query($connection_server, "DELETE FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='$gw_name'");
            mysqli_query($connection_server, "INSERT INTO sas_payment_gateways (vendor_id, gateway_name, public_key, secret_key, encrypt_key, percentage, status) VALUES ('$vendor_id', '$gw_name', '$pub', '$sec', '', '0', '1')");
        }

        unset($_SESSION['identity_provider_cache'], $_SESSION['identity_provider_vid']);

        $selected_row = getIdentityGatewayRow($selected_provider, $vendor_id);
        if (in_array($selected_provider, $identity_provider_gateways, true) && !identityGatewayHasKeys($selected_row, $selected_provider)) {
            $_SESSION["product_purchase_response"] = ucfirst($selected_provider) . " was selected, but its API keys are still empty. Enter the keys below and save again.";
        } else {
            $_SESSION["product_purchase_response"] = "Identity Verification Provider Updated Successfully";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    // SAAS monetization: unlock Identity API provider configuration with a one-time
    // fee (set by the super admin, default N7,000) paid from the vendor wallet.
    if (isset($_POST["activate-identity-api-wallet"])) {
        $vid = $get_logged_admin_details['id'];
        $q_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='identity_api_activation_fee'");
        $fee = (float)(($q_fee && mysqli_num_rows($q_fee) > 0) ? mysqli_fetch_assoc($q_fee)['option_value'] : 7000);

        if ((int)($get_logged_admin_details['identity_api_enabled'] ?? 0) === 1) {
            $_SESSION["product_purchase_response"] = "Identity API access is already active.";
        } elseif ($fee <= 0) {
            mysqli_query($connection_server, "UPDATE sas_vendors SET identity_api_enabled=1 WHERE id='$vid'");
            $_SESSION["product_purchase_response"] = "Identity API access activated successfully!";
        } elseif ($get_logged_admin_details['balance'] >= $fee) {
            $ref = "IDP_ACT_" . time();
            $res = chargeVendor("debit", "identity_api_activation", "Service Activation", $ref, $fee, $fee, "Identity API Access Activation Fee", $_SERVER['HTTP_HOST'], 1);
            if ($res === "success") {
                mysqli_query($connection_server, "UPDATE sas_vendors SET identity_api_enabled=1 WHERE id='$vid'");
                $_SESSION["product_purchase_response"] = "Identity API access activated successfully!";
            } else {
                $_SESSION["product_purchase_response"] = "Error: Activation failed during billing.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Error: Insufficient vendor wallet balance. You need NGN " . number_format($fee, 2);
        }
        header("Location: IdentityAPI.php");
        exit();
    }

    if (isset($_POST["activate-nin-card-wallet"])) {
        $vid = $get_logged_admin_details['id'];
        $q_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='nin_card_activation_fee'");
        $fee = (float)(mysqli_fetch_assoc($q_fee)['option_value'] ?? 5000);

        if ($get_logged_admin_details['balance'] >= $fee) {
            $ref = "NIN_ACT_" . time();
            $res = chargeVendor("debit", "nin_card_activation", "Service Activation", $ref, $fee, $fee, "NIN Card Service Activation Fee", $_SERVER['HTTP_HOST'], 1);
            if ($res === "success") {
                mysqli_query($connection_server, "UPDATE sas_vendors SET nin_card_enabled=1 WHERE id='$vid'");
                $_SESSION["product_purchase_response"] = "NIN Card Service Activated Successfully!";
            } else {
                $_SESSION["product_purchase_response"] = "Error: Activation failed during billing.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Error: Insufficient vendor wallet balance. You need NGN " . number_format($fee, 2);
        }
        header("Location: IdentityAPI.php");
        exit();
    }

    if (isset($_POST["update-nin-card-pricing"])) {
        $nin_fee      = (float)$_POST["nin_card_fee"];
        $nin_fee_agent = (float)$_POST["nin_card_fee_agent"];
        $nin_fee_api  = (float)$_POST["nin_card_fee_api"];
        $vid = $get_logged_admin_details['id'];
        mysqli_query($connection_server, "UPDATE sas_vendors SET nin_card_fee='$nin_fee', nin_card_fee_agent='$nin_fee_agent', nin_card_fee_api='$nin_fee_api' WHERE id='$vid'");
        $_SESSION["product_purchase_response"] = "NIN Card pricing updated successfully.";
        header("Location: IdentityAPI.php");
        exit();
    }

    if (isset($_POST["activate-bvn-verify-wallet"])) {
        $vid = $get_logged_admin_details['id'];
        $q_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='bvn_verify_activation_fee'");
        $fee = (float)(mysqli_fetch_assoc($q_fee)['option_value'] ?? 5000);

        if ($get_logged_admin_details['balance'] >= $fee) {
            $ref = "BVN_ACT_" . time();
            $res = chargeVendor("debit", "bvn_verify_activation", "Service Activation", $ref, $fee, $fee, "BVN Verification Service Activation Fee", $_SERVER['HTTP_HOST'], 1);
            if ($res === "success") {
                mysqli_query($connection_server, "UPDATE sas_vendors SET bvn_verify_enabled=1 WHERE id='$vid'");
                $_SESSION["product_purchase_response"] = "BVN Verification Service Activated Successfully!";
            } else {
                $_SESSION["product_purchase_response"] = "Error: Activation failed during billing.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Error: Insufficient vendor wallet balance. You need NGN " . number_format($fee, 2);
        }
        header("Location: IdentityAPI.php");
        exit();
    }

    if (isset($_POST["update-bvn-verify-pricing"])) {
        $bvn_fee       = (float)$_POST["bvn_verify_fee"];
        $bvn_fee_agent = (float)$_POST["bvn_verify_fee_agent"];
        $bvn_fee_api   = (float)$_POST["bvn_verify_fee_api"];
        $vid = $get_logged_admin_details['id'];
        mysqli_query($connection_server, "UPDATE sas_vendors SET bvn_verify_fee='$bvn_fee', bvn_verify_fee_agent='$bvn_fee_agent', bvn_verify_fee_api='$bvn_fee_api' WHERE id='$vid'");
        $_SESSION["product_purchase_response"] = "BVN Verification pricing updated successfully.";
        header("Location: IdentityAPI.php");
        exit();
    }

?>
<!DOCTYPE html>
<head>
    <title>Identity Services & API | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    
    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>    
	<div class="pagetitle">
      <h1>IDENTITY SERVICES MANAGER</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item">API Manager</li>
          <li class="breadcrumb-item active">Identity Services</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4 justify-content-center">
        <div class="col-lg-10">

            <?php
                // SAAS monetization: premium providers (Monnify/Dojah/QoreID/Smile) require a
                // one-time activation fee (super-admin set, default N7,000). The Local
                // Marketplace (vendor-to-vendor) API is FREE to install and instead charged
                // per verification (super-admin set) with a minimum wallet balance.
                $identity_api_enabled = ((int)($get_logged_admin_details['identity_api_enabled'] ?? 0) === 1);
                $q_idp_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='identity_api_activation_fee'");
                $idp_act_fee = (float)(($q_idp_fee && mysqli_num_rows($q_idp_fee) > 0) ? mysqli_fetch_assoc($q_idp_fee)['option_value'] : 7000);
                $q_local_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='local_identity_fee'");
                $local_ver_fee = (float)(($q_local_fee && mysqli_num_rows($q_local_fee) > 0) ? mysqli_fetch_assoc($q_local_fee)['option_value'] : 50);
                $q_local_min = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='local_identity_min_balance'");
                $local_min_bal = (float)(($q_local_min && mysqli_num_rows($q_local_min) > 0) ? mysqli_fetch_assoc($q_local_min)['option_value'] : 1000);
            ?>
            <?php if (!$identity_api_enabled): ?>
            <div class="alert alert-warning border-0 rounded-4 small mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <strong><i class="bi bi-shield-lock me-1"></i>Premium providers are locked.</strong>
                    Monnify, Prembly (IdentityPass), Dojah, QoreID (VerifyMe) and Smile Identity require a one-time activation of <strong>₦<?php echo number_format($idp_act_fee, 2); ?></strong>.
                    The <strong>Local Marketplace (vendor-to-vendor) API is free</strong> to install.
                </div>
                <form method="post" class="m-0">
                    <button type="submit" name="activate-identity-api-wallet" class="btn btn-sm btn-warning text-dark fw-bold"
                        onclick="return confirm('Charge â‚¦<?php echo number_format($idp_act_fee, 2); ?> from your vendor wallet to unlock the premium providers?')">
                        <i class="bi bi-wallet2 me-1"></i> Activate Premium - â‚¦<?php echo number_format($idp_act_fee, 2); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
            <!-- Identity Verification Provider Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-primary">Identity Verification Provider <?php echo $identity_api_enabled ? '<span class="badge bg-success bg-opacity-10 text-success border border-success ms-2" style="font-size:.65rem;">PREMIUM ACTIVE</span>' : '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning ms-2" style="font-size:.65rem;">LOCAL FREE</span>'; ?></h5>
                    <i class="bi bi-person-badge text-muted fs-4"></i>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info border-0 rounded-4 small mb-4">
                        Identity verification is <strong>optional</strong>. Pick any one of the integrated providers - Prembly (IdentityPass), Dojah, QoreID (VerifyMe), Smile Identity, Monnify, or your Local Marketplace (vendor-to-vendor) API - and enter its API keys; leave the others blank. Name matching is always applied. Changing the provider takes effect immediately for users who are already signed in.
                    </div>
                    <form method="post">
                        <?php
                        $current_idp = getIdentityProvider($get_logged_admin_details["id"]);
                        $idp_providers = [
                            "monnify" => "Monnify (uses your Monnify gateway keys)",
                            "prembly" => "Prembly (IdentityPass)",
                            "dojah"   => "Dojah",
                            "qoreid"  => "QoreID (VerifyMe)",
                            "smileid" => "Smile Identity",
                            "localhost" => "Localhost (Local Vendor API)"
                        ];
                        $idp_gateways = ["prembly", "dojah", "qoreid", "smileid"];
                        $idp_key_labels = [
                            "prembly"=> ["public_key" => "App ID (optional)", "secret_key" => "Secret Key"],
                            "dojah"  => ["public_key" => "App ID", "secret_key" => "Private Key"],
                            "qoreid" => ["public_key" => "Client ID", "secret_key" => "Client Secret"],
                            "smileid"=> ["public_key" => "Partner ID", "secret_key" => "API Key"],
                        ];
                        ?>
                        <div class="mb-4">
                            <label class="form-label fw-bold small">Active Provider</label>
                            <select name="identity_provider" class="form-select" id="idp-select">
                                <?php foreach ($idp_providers as $val => $label):
                                    $opt_is_premium = ($val !== 'localhost');
                                    // Never disable the provider that is already selected: a disabled
                                    // selected <option> is not submitted, so saving would silently
                                    // switch the vendor to whatever the first option happens to be.
                                    $opt_locked = ($opt_is_premium && !$identity_api_enabled && $current_idp !== $val);
                                    $opt_label = $label . ($opt_locked ? ' - locked (activate)' : ($val === 'localhost' ? ' - FREE' : ''));
                                ?>
                                <option value="<?php echo $val; ?>" <?php echo $opt_locked ? 'disabled' : ''; ?> <?php echo ($current_idp === $val) ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text mt-2 small">Premium providers require activation. The <strong>Local Marketplace (vendor-to-vendor) API is free</strong>.</div>
                        </div>
                        <?php foreach ($idp_gateways as $gw): ?>
                        <?php
                            // Duplicate-tolerant read (the table has no unique key).
                            $gw_row = getIdentityGatewayRow($gw, $get_logged_admin_details["id"]);
                            $gw_pub = $gw_row['public_key'] ?? '';
                            $gw_sec = $gw_row['secret_key'] ?? '';
                            $pub_label = $idp_key_labels[$gw]['public_key'];
                            $sec_label = $idp_key_labels[$gw]['secret_key'];
                            $gw_display = ($gw === 'smileid') ? 'Smile Identity' : ($gw === 'prembly' ? 'Prembly (IdentityPass)' : ucwords($gw));
                            $gw_ready = identityGatewayHasKeys($gw_row, $gw);
                        ?>
                        <div class="p-3 border rounded-4 mb-3 idp-keys-block" id="idp-keys-<?php echo $gw; ?>" style="display:<?php echo ($current_idp === $gw) ? '' : 'none'; ?>">
                            <h6 class="fw-bold mb-3">
                                <?php echo $gw_display; ?> API Keys
                                <?php if ($gw_ready): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success ms-2" style="font-size:.65rem;">CONFIGURED</span>
                                <?php else: ?>
                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning ms-2" style="font-size:.65rem;">KEYS MISSING</span>
                                <?php endif; ?>
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><?php echo $pub_label; ?></label>
                                    <input type="text" name="idp_public_<?php echo $gw; ?>" class="form-control" value="<?php echo htmlspecialchars($gw_pub); ?>" placeholder="Enter <?php echo $pub_label; ?>" autocomplete="off" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><?php echo $sec_label; ?></label>
                                    <input type="password" name="idp_secret_<?php echo $gw; ?>" class="form-control" value="<?php echo htmlspecialchars($gw_sec); ?>" placeholder="Enter <?php echo $sec_label; ?>" autocomplete="new-password" />
                                </div>
                            </div>
                            <?php if ($gw === 'prembly'): ?>
                                <div class="form-text mt-2 small">
                                    Keys are on your Prembly dashboard. The <strong>Secret Key</strong> is sent as
                                    <code>x-api-key</code>; the App ID is optional. Uses <code>api.prembly.com</code>.
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                        <div class="p-3 border rounded-4 mb-3 idp-keys-block" id="idp-keys-localhost" style="display:<?php echo ($current_idp === 'localhost') ? '' : 'none'; ?>">
                            <h6 class="fw-bold mb-3">Local Marketplace API</h6>
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <label class="form-label small fw-bold">Select Installed Identity API</label>
                                    <select name="identity_api_id" class="form-select">
                                        <option value="0">-- Select API --</option>
                                        <?php 
                                        $current_api_id = getIdentityApiId($get_logged_admin_details["id"]);
                                        $q_apis = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='".$get_logged_admin_details["id"]."' AND api_type='identity-verification'");
                                        while($api = mysqli_fetch_assoc($q_apis)): ?>
                                            <option value="<?php echo $api['id']; ?>" <?php echo ($current_api_id == $api['id']) ? 'selected' : ''; ?>>
                                                <?php echo strtoupper($api['api_base_url']); ?> (Status: <?php echo ($api['status'] == 1 ? 'Active' : 'Inactive'); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <div class="form-text mt-2 small">These are Identity Verification APIs you have installed from the MarketPlace. Ensure the selected API is "Active".</div>
                                    <div class="alert alert-info border-0 rounded-3 small mt-3 mb-0">
                                        <i class="bi bi-info-circle me-1"></i>
                                        <strong>Local Marketplace is free to install.</strong>
                                        You are charged <strong>â‚¦<?php echo number_format($local_ver_fee, 2); ?></strong> per successful verification.
                                        Minimum wallet balance required: <strong>â‚¦<?php echo number_format($local_min_bal, 2); ?></strong>.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button name="update-identity-provider" type="submit" class="btn btn-primary px-5 rounded-pill fw-bold">Save Provider Settings</button>
                    </form>
                    <script>
                    (function(){
                        var sel = document.getElementById('idp-select');
                        var blocks = document.querySelectorAll('.idp-keys-block');
                        function toggleBlocks(){
                            blocks.forEach(function(b){
                                b.style.display = b.id === 'idp-keys-'+sel.value ? '' : 'none';
                            });
                        }
                        sel.addEventListener('change', toggleBlocks);
                    })();
                    </script>
                </div>
            </div>

            <!-- VoveID removed permanently: too expensive for vendors. -->
            <!-- NIN Card Service Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-success">NIN Card Service</h5>
                    <i class="bi bi-person-badge text-muted fs-4"></i>
                </div>
                <div class="card-body p-4">
                    <?php if ($get_logged_admin_details['nin_card_enabled'] == 0):
                        $q_nin_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='nin_card_activation_fee'");
                        $nin_act_fee = (float)(mysqli_fetch_assoc($q_nin_fee)['option_value'] ?? 5000);
                    ?>
                        <div class="text-center py-5 bg-light rounded-4 border">
                            <i class="bi bi-lock-fill text-warning display-4 mb-3"></i>
                            <h4 class="fw-bold mb-2">NIN Card Service Locked</h4>
                            <p class="text-muted mb-4 px-lg-5">Activate the NIN Card Service to let your users generate and download their Digital NIN Slip. Uses Dojah or QoreID as the identity provider.</p>
                            <div class="h3 fw-bold text-success mb-4">One-time Fee: â‚¦<?php echo number_format($nin_act_fee, 2); ?></div>
                            <form method="post" class="m-0 d-inline-block">
                                <button type="submit" name="activate-nin-card-wallet" class="btn btn-success btn-lg rounded-pill fw-bold px-5"
                                    onclick="return confirm('Charge â‚¦<?php echo number_format($nin_act_fee, 2); ?> from your vendor wallet to activate the NIN Card Service?')">
                                    <i class="bi bi-wallet2 me-2"></i> Activate â€” Pay â‚¦<?php echo number_format($nin_act_fee, 2); ?> from Wallet
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success border-0 rounded-3 mb-4 d-flex align-items-center gap-3">
                            <i class="bi bi-check-circle-fill fs-4"></i>
                            <div>
                                <strong>NIN Card Service is Active.</strong>
                                Your users can now generate Digital NIN Slips via <a href="../web/NINCard.php">NINCard.php</a>.
                                Set pricing below and ensure your <strong>Identity Provider</strong> (Dojah or QoreID) is configured above.
                            </div>
                        </div>

                        <form method="post">
                            <div class="row g-4 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Fee â€” User (Level 1)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0">â‚¦</span>
                                        <input name="nin_card_fee" type="number" step="any" min="0"
                                               value="<?php echo $get_logged_admin_details['nin_card_fee']; ?>"
                                               class="form-control form-control-lg bg-light border-0" placeholder="300.00">
                                    </div>
                                    <small class="text-muted text-xs">Price charged to regular users.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Fee â€” Agent (Level 2)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0">â‚¦</span>
                                        <input name="nin_card_fee_agent" type="number" step="any" min="0"
                                               value="<?php echo $get_logged_admin_details['nin_card_fee_agent']; ?>"
                                               class="form-control form-control-lg bg-light border-0" placeholder="250.00">
                                    </div>
                                    <small class="text-muted text-xs">Price charged to agent-level users.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Fee â€” API (Level 3)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0">â‚¦</span>
                                        <input name="nin_card_fee_api" type="number" step="any" min="0"
                                               value="<?php echo $get_logged_admin_details['nin_card_fee_api']; ?>"
                                               class="form-control form-control-lg bg-light border-0" placeholder="200.00">
                                    </div>
                                    <small class="text-muted text-xs">Price charged to API-level users.</small>
                                </div>
                            </div>
                            <div class="mt-4 d-flex gap-3">
                                <button name="update-nin-card-pricing" type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm">
                                    <i class="bi bi-save2 me-2"></i>Save NIN Card Pricing
                                </button>
                                <a href="NINCard.php" class="btn btn-outline-secondary rounded-pill px-4">
                                    <i class="bi bi-clock-history me-1"></i> View History
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BVN Verification Card -->
            <div id="bvn-verify" class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-fingerprint me-2"></i>BVN Verification Service</h5>
                    <i class="bi bi-bank text-muted fs-4"></i>
                </div>
                <div class="card-body p-4">
                    <?php if ($get_logged_admin_details['bvn_verify_enabled'] == 0):
                        $q_bvn_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='bvn_verify_activation_fee'");
                        $bvn_act_fee = (float)(mysqli_fetch_assoc($q_bvn_fee)['option_value'] ?? 5000);
                    ?>
                        <div class="text-center py-5 bg-light rounded-4 border">
                            <i class="bi bi-lock-fill text-warning display-4 mb-3"></i>
                            <h4 class="fw-bold mb-2">BVN Verification Service Locked</h4>
                            <p class="text-muted mb-4 px-lg-5">Activate BVN Verification to let your users instantly verify any Bank Verification Number and retrieve identity details. Uses Dojah or QoreID as the identity provider.</p>
                            <div class="h3 fw-bold text-success mb-4">One-time Fee: â‚¦<?php echo number_format($bvn_act_fee, 2); ?></div>
                            <form method="post" class="m-0 d-inline-block">
                                <button type="submit" name="activate-bvn-verify-wallet" class="btn btn-success btn-lg rounded-pill fw-bold px-5"
                                    onclick="return confirm('Charge â‚¦<?php echo number_format($bvn_act_fee, 2); ?> from your vendor wallet to activate the BVN Verification Service?')">
                                    <i class="bi bi-wallet2 me-2"></i> Activate â€” Pay â‚¦<?php echo number_format($bvn_act_fee, 2); ?> from Wallet
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success border-0 rounded-3 mb-4 d-flex align-items-center gap-3">
                            <i class="bi bi-check-circle-fill fs-4"></i>
                            <div><strong>BVN Verification is Active!</strong> Your users can verify BVN numbers through the Print Hub.</div>
                        </div>
                        <form method="post">
                            <h6 class="fw-bold mb-3">Set Verification Fee by Account Level</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Smart (User) Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">â‚¦</span>
                                        <input name="bvn_verify_fee" type="number" step="any" min="0"
                                               value="<?php echo $get_logged_admin_details['bvn_verify_fee']; ?>"
                                               class="form-control" required>
                                    </div>
                                    <small class="text-muted text-xs">Price charged to regular users.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Agent Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">â‚¦</span>
                                        <input name="bvn_verify_fee_agent" type="number" step="any" min="0"
                                               value="<?php echo $get_logged_admin_details['bvn_verify_fee_agent']; ?>"
                                               class="form-control" required>
                                    </div>
                                    <small class="text-muted text-xs">Price charged to agent-level users.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">API Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">â‚¦</span>
                                        <input name="bvn_verify_fee_api" type="number" step="any" min="0"
                                               value="<?php echo $get_logged_admin_details['bvn_verify_fee_api']; ?>"
                                               class="form-control" required>
                                    </div>
                                    <small class="text-muted text-xs">Price charged to API-level users.</small>
                                </div>
                            </div>
                            <div class="mt-4 d-flex gap-3">
                                <button name="update-bvn-verify-pricing" type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm">
                                    <i class="bi bi-save2 me-2"></i>Save BVN Pricing
                                </button>
                                <a href="BVNVerification.php" class="btn btn-outline-secondary rounded-pill px-4">
                                    <i class="bi bi-clock-history me-1"></i> View History
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
      </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
