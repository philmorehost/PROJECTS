<?php session_start();
include("../func/bc-config.php");
include_once("../func/bc-func.php");

// Guard: BVN verify must be enabled for this vendor
if (!isServiceEnabled('bvn_verify')) {
    $_SESSION["product_purchase_response"] = "BVN Verification service is not available on this platform.";
    header("Location: PrintHub.php");
    exit();
}

$bvn_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT bvn_verify_fee, bvn_verify_fee_agent, bvn_verify_fee_api FROM sas_vendors WHERE id='".$get_logged_user_details["vendor_id"]."' LIMIT 1"));

// Determine fee by account level
$acc_level = $get_logged_user_details["account_level"];
if ($acc_level == 3) $service_fee = (float)$bvn_vendor['bvn_verify_fee_api'];
elseif ($acc_level == 2) $service_fee = (float)$bvn_vendor['bvn_verify_fee_agent'];
else $service_fee = (float)$bvn_vendor['bvn_verify_fee'];

if (isset($_POST["request-bvn-verify"])) {
    $bvn_input = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bvn_number"] ?? "")));

    if (empty($bvn_input) || !ctype_digit($bvn_input) || strlen($bvn_input) !== 11) {
        $_SESSION["product_purchase_response"] = "Please enter a valid 11-digit BVN.";
        header("Location: BVNVerification.php");
        exit();
    }

    if (userBalance(1) < $service_fee) {
        $_SESSION["product_purchase_response"] = "Insufficient wallet balance. You need ₦" . number_format($service_fee, 2);
        header("Location: BVNVerification.php");
        exit();
    }

    $reference = "BVN" . strtoupper(substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 12));

    // Debit wallet first
    $debit = chargeUser("debit", $reference, "BVN Verify", $reference, "", $service_fee, $service_fee,
        "BVN Verification for BVN: " . substr($bvn_input, 0, 3) . "****" . substr($bvn_input, -2),
        "WEB", $_SERVER["HTTP_HOST"], 1);

    if ($debit !== "success") {
        $_SESSION["product_purchase_response"] = "Transaction failed. Please try again.";
        header("Location: BVNVerification.php");
        exit();
    }

    // Fetch BVN profile from provider
    $profile = fetchBVNProfile($bvn_input, $get_logged_user_details["vendor_id"]);

    if ($profile['status'] !== 'success') {
        // Refund on API failure
        chargeUser("credit", $reference . "_REFUND", "BVN Verify Refund", $reference . "_RF", "", $service_fee, $service_fee,
            "Refund: BVN Verification API error - " . ($profile['message'] ?? 'Unknown error'), "WEB", $_SERVER["HTTP_HOST"], 1);
        $_SESSION["product_purchase_response"] = "BVN lookup failed: " . ($profile['message'] ?? 'Please try again.');
        header("Location: BVNVerification.php");
        exit();
    }

    // Store record
    $firstname  = mysqli_real_escape_string($connection_server, $profile['firstname'] ?? '');
    $middlename = mysqli_real_escape_string($connection_server, $profile['middlename'] ?? '');
    $lastname   = mysqli_real_escape_string($connection_server, $profile['lastname'] ?? '');
    $birthdate  = mysqli_real_escape_string($connection_server, $profile['birthdate'] ?? '');
    $gender     = mysqli_real_escape_string($connection_server, $profile['gender'] ?? '');
    $phone      = mysqli_real_escape_string($connection_server, $profile['phone'] ?? '');
    $bank_enrol = mysqli_real_escape_string($connection_server, $profile['bank_of_enrolment'] ?? '');
    $level_acct = mysqli_real_escape_string($connection_server, $profile['level_of_account'] ?? '');
    $provider   = mysqli_real_escape_string($connection_server, $profile['provider'] ?? '');

    mysqli_query($connection_server, "INSERT INTO sas_bvn_verify_requests
        (vendor_id, user_id, reference, bvn_input, firstname, middlename, lastname, birthdate, gender, phone, bank_of_enrolment, level_of_account, price, provider, status)
        VALUES
        ('".$get_logged_user_details["vendor_id"]."', '".$get_logged_user_details["id"]."', '$reference',
         '".substr($bvn_input, 0, 3)."****".substr($bvn_input, -2)."',
         '$firstname', '$middlename', '$lastname', '$birthdate', '$gender', '$phone', '$bank_enrol', '$level_acct',
         '$service_fee', '$provider', 'success')");

    $_SESSION["bvn_result_" . $reference] = $profile;
    $_SESSION["bvn_result_" . $reference]['reference'] = $reference;
    $_SESSION["bvn_result_" . $reference]['fee'] = $service_fee;
    header("Location: BVNVerification.php?ref=$reference");
    exit();
}

// Show result if ref is provided
$show_result = false;
$result_data = [];
if (!empty($_GET['ref'])) {
    $ref_safe = htmlspecialchars($_GET['ref']);
    if (isset($_SESSION["bvn_result_" . $ref_safe])) {
        $show_result = true;
        $result_data = $_SESSION["bvn_result_" . $ref_safe];
        unset($_SESSION["bvn_result_" . $ref_safe]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>BVN Verification | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .result-card { border-radius: 1rem; overflow: hidden; }
        .result-header { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 1.5rem 2rem; }
        .result-row { display: flex; border-bottom: 1px solid #f1f5f9; padding: 0.75rem 1.5rem; align-items: center; }
        .result-row:last-child { border-bottom: none; }
        .result-label { font-weight: 600; color: #64748b; min-width: 180px; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .result-value { color: #1e293b; font-weight: 500; font-size: 0.95rem; }
        .badge-success-soft { background: #dcfce7; color: #16a34a; padding: 0.25rem 0.75rem; border-radius: 50px; font-size: 0.8rem; font-weight: 600; }
        .fee-badge { background: #fef3c7; color: #92400e; padding: 0.25rem 0.75rem; border-radius: 50px; font-size: 0.8rem; font-weight: 600; }
    </style>
</head>
<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
        <h1>BVN VERIFICATION</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="PrintHub.php">Print Hub</a></li>
                <li class="breadcrumb-item active">BVN Verification</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <?php if(isset($_SESSION["product_purchase_response"]) && !empty($_SESSION["product_purchase_response"])): ?>
        <div class="alert alert-warning alert-dismissible fade show rounded-3 mb-3 d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <span><?php echo htmlspecialchars($_SESSION["product_purchase_response"]); unset($_SESSION["product_purchase_response"]); ?></span>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($show_result): ?>
        <!-- BVN Result -->
        <div class="row justify-content-center mb-4">
            <div class="col-lg-7">
                <div class="card result-card border-0 shadow">
                    <div class="result-header">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:52px;height:52px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">
                                <i class="bi bi-fingerprint"></i>
                            </div>
                            <div>
                                <div class="small opacity-75">BVN Verification Result</div>
                                <div class="fw-bold fs-5"><?php echo htmlspecialchars($result_data['firstname'] . ' ' . $result_data['middlename'] . ' ' . $result_data['lastname']); ?></div>
                            </div>
                            <div class="ms-auto text-end">
                                <span class="badge-success-soft">✓ Verified</span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php
                        $fields = [
                            "Date of Birth"     => $result_data['birthdate'] ?? '',
                            "Gender"            => $result_data['gender'] ?? '',
                            "Phone Number"      => $result_data['phone'] ?? '',
                            "Bank of Enrolment" => $result_data['bank_of_enrolment'] ?? '',
                            "Account Level"     => $result_data['level_of_account'] ?? '',
                            "Reference"         => $result_data['reference'] ?? '',
                            "Service Fee"       => '₦' . number_format($result_data['fee'] ?? 0, 2),
                        ];
                        foreach ($fields as $label => $value):
                            if (!empty($value)):
                        ?>
                        <div class="result-row">
                            <span class="result-label"><?php echo $label; ?></span>
                            <span class="result-value"><?php echo htmlspecialchars($value); ?></span>
                        </div>
                        <?php endif; endforeach; ?>
                    </div>
                    <div class="card-footer bg-white border-0 py-3 px-4 d-flex gap-2">
                        <a href="BVNVerification.php" class="btn btn-primary rounded-pill px-4">
                            <i class="bi bi-arrow-repeat me-1"></i>New Verification
                        </a>
                        <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-4">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- BVN Input Form -->
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <!-- Info Banner -->
                <div class="card border-0 rounded-4 mb-4" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6);color:white;">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div style="width:48px;height:48px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
                                <i class="bi bi-fingerprint"></i>
                            </div>
                            <div>
                                <h5 class="mb-0 fw-bold">BVN Verification</h5>
                                <div class="small opacity-75">Instantly verify any Bank Verification Number</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-4 text-center">
                                <div class="fw-bold fs-5">Instant</div>
                                <div class="small opacity-75">Real-time Result</div>
                            </div>
                            <div class="col-4 text-center">
                                <div class="fw-bold fs-5">Secure</div>
                                <div class="small opacity-75">Encrypted Lookup</div>
                            </div>
                            <div class="col-4 text-center">
                                <div class="fw-bold fs-5">₦<?php echo number_format($service_fee, 2); ?></div>
                                <div class="small opacity-75">Per Verification</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Card -->
                <div class="card border-0 rounded-4 shadow-sm">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-1">Enter BVN Number</h6>
                        <p class="text-muted small mb-4">Provide the 11-digit Bank Verification Number to look up identity details.</p>

                        <form method="post" action="BVNVerification.php">
                            <div class="mb-4">
                                <label class="form-label fw-semibold">BVN Number <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0">
                                        <i class="bi bi-fingerprint text-primary"></i>
                                    </span>
                                    <input type="text" name="bvn_number" id="bvn_number"
                                           class="form-control border-start-0 py-3"
                                           placeholder="Enter 11-digit BVN" maxlength="11"
                                           pattern="[0-9]{11}" inputmode="numeric" required
                                           style="font-size:1.1rem;letter-spacing:0.1em;">
                                </div>
                                <div class="form-text mt-1">Your BVN is the 11-digit number provided by your bank at registration.</div>
                            </div>

                            <!-- Fee Notice -->
                            <div class="alert alert-info border-0 rounded-3 d-flex align-items-start gap-2 mb-4">
                                <i class="bi bi-info-circle-fill text-info mt-1"></i>
                                <div>
                                    <strong>Service Fee:</strong> ₦<?php echo number_format($service_fee, 2); ?> will be deducted from your wallet.<br>
                                    <small class="text-muted">Current Balance: ₦<?php echo number_format(userBalance(1), 2); ?></small>
                                </div>
                            </div>

                            <button type="submit" name="request-bvn-verify"
                                    class="btn btn-primary w-100 py-3 rounded-3 fw-bold"
                                    style="font-size:1.05rem;">
                                <i class="bi bi-search me-2"></i>Verify BVN — ₦<?php echo number_format($service_fee, 2); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Note Card -->
                <div class="card border-0 rounded-4 mt-3 bg-light">
                    <div class="card-body py-3 px-4">
                        <div class="d-flex gap-2">
                            <i class="bi bi-shield-check text-success mt-1"></i>
                            <div class="small">
                                <strong>Privacy Note:</strong> BVN details are retrieved from secure government-approved identity providers. Results are confidential and should not be shared with third parties.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <?php include("../func/bc-footer.php"); ?>
    <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('bvn_number').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 11);
        });
    </script>
</body>
</html>
