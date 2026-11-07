<?php session_start();
include("../func/bc-config.php");

// Wallet to Bank service toggle (Service Control Center)
if (!isServiceEnabled('withdraw')) {
    header("Location: Dashboard.php");
    exit();
}

// Optimized bank list loading (Cache within request)
$banks_json_path = $_SERVER["DOCUMENT_ROOT"] . "/func/banks.json";
$retrieve_bank_list = [];
if (file_exists($banks_json_path)) {
    $banks_raw = file_get_contents($banks_json_path);
    $retrieve_bank_list = json_decode($banks_raw, true) ?: [];
}

$u_name = $get_logged_user_details['username'] ?? '';
$v_id = $get_logged_user_details['vendor_id'] ?? 0;
$all_beneficiaries_list = [];
if ($connection_server && !empty($u_name)) {
    $q_ben = mysqli_query($connection_server, "SELECT id, bank_name, account_number, account_name, bank_code FROM sas_withdrawal_beneficiaries WHERE vendor_id='$v_id' AND username='$u_name' ORDER BY id DESC");
    if ($q_ben) {
        while ($r = mysqli_fetch_assoc($q_ben)) {
            $all_beneficiaries_list[] = $r;
        }
    }
}

if (isset($_POST["initiate-transfer"]) || isset($_POST["process-transfer"])) {
    $purchase_method = "web";
    $action_function = 1;

    $pin = $_POST['pin'] ?? '';
    $otp = $_POST['otp'] ?? '';

    // Verify PIN
    if (!verifyUserPIN($pin, $get_logged_user_details)) {
        $error_data = [
            "status" => "error",
            "title" => "Verification Failed",
            "message" => "Invalid Transaction PIN"
        ];
        if (isset($_POST['is_ajax'])) {
            echo json_encode($error_data);
            exit();
        }
        $_SESSION["transfer_result"] = $error_data;
        header("Location: SendFund.php");
        exit();
    }

    // Verify OTP
    if (empty($_SESSION["withdrawal_otp"]) || $otp !== $_SESSION["withdrawal_otp"]) {
        $error_data = [
            "status" => "error",
            "title" => "Verification Failed",
            "message" => "Invalid Email OTP"
        ];
        if (isset($_POST['is_ajax'])) {
            echo json_encode($error_data);
            exit();
        }
        $_SESSION["transfer_result"] = $error_data;
        header("Location: SendFund.php");
        exit();
    }
    if (time() - $_SESSION["withdrawal_otp_time"] > 600) {
        $_SESSION["transfer_result"] = [
            "status" => "error",
            "title" => "Verification Failed",
            "message" => "OTP has expired"
        ];
        header("Location: SendFund.php");
        exit();
    }

    include("func/bank-transfer.php");
    $json_response_decode = json_decode($json_response_encode, true);
    $status = $json_response_decode["status"] ?? "failed";
    $desc = $json_response_decode["desc"] ?? "An error occurred";

    if ($status == "success") {
        // Save Beneficiary logic
        if (isset($_POST['save-beneficiary'])) {
            $u_name = mysqli_real_escape_string($connection_server, $get_logged_user_details['username']);
            $v_id = $get_logged_user_details['vendor_id'];
            $b_code = mysqli_real_escape_string($connection_server, $_SESSION["bank_code"]);
            $b_name = mysqli_real_escape_string($connection_server, $_SESSION["bank_name"]);
            $a_num = mysqli_real_escape_string($connection_server, $_SESSION["account_number"]);
            $a_name = mysqli_real_escape_string($connection_server, $_SESSION["account_name"]);
            $overwrite_id = (int)($_POST['overwrite_id'] ?? 0);

            // First check if this EXACT account already exists for this user to avoid duplicates
            $check_dup = mysqli_query($connection_server, "SELECT id FROM sas_withdrawal_beneficiaries WHERE vendor_id='$v_id' AND username='$u_name' AND bank_code='$b_code' AND account_number='$a_num' LIMIT 1");
            if ($row_dup = mysqli_fetch_assoc($check_dup)) {
                $overwrite_id = $row_dup['id'];
            }

            if ($overwrite_id > 0) {
                mysqli_query($connection_server, "UPDATE sas_withdrawal_beneficiaries SET bank_code='$b_code', bank_name='$b_name', account_name='$a_name', account_number='$a_num' WHERE id='$overwrite_id' AND vendor_id='$v_id' AND username='$u_name'");
            } else {
                // Check limit of 4
                $count_q = mysqli_query($connection_server, "SELECT COUNT(*) as total FROM sas_withdrawal_beneficiaries WHERE vendor_id='$v_id' AND username='$u_name'");
                $count_r = mysqli_fetch_assoc($count_q);
                if (($count_r['total'] ?? 0) < 4) {
                    mysqli_query($connection_server, "INSERT IGNORE INTO sas_withdrawal_beneficiaries (vendor_id, username, bank_code, bank_name, account_name, account_number) VALUES ('$v_id', '$u_name', '$b_code', '$b_name', '$a_name', '$a_num')");
                }
            }
        }

        $_SESSION["transfer_result"] = [
            "status" => "success",
            "title" => (strpos(strtolower($desc), 'approval') !== false) ? "Request Submitted" : "Transfer Successful!",
            "message" => $desc,
            "details" => [
                "amount" => $_SESSION["amount"],
                "bank" => $_SESSION["bank_name"],
                "account" => $_SESSION["account_number"],
                "recipient" => $_SESSION["account_name"],
                "ref" => $json_response_decode["ref"] ?? "N/A"
            ]
        ];
    } else {
        $_SESSION["transfer_result"] = [
            "status" => "error",
            "title" => "Transfer Failed",
            "message" => $desc
        ];
    }
    unset($_SESSION["amount"]);
    unset($_SESSION["transfer_fee"]);
    unset($_SESSION["account_number"]);
    unset($_SESSION["transfer_enquiry_id"]);
    unset($_SESSION["bank_code"]);
    unset($_SESSION["bank_name"]);
    unset($_SESSION["account_name"]);
    unset($_SESSION["narration"]);

    if (isset($_POST['is_ajax'])) {
        echo json_encode($_SESSION["transfer_result"]);
        unset($_SESSION["transfer_result"]);
        exit();
    }

    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

if (isset($_POST["verify-bank"])) {
    $purchase_method = "web";
    $action_function = 3;

    $bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["bank-code"]))));
    $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["amount"]))));
    $account_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["account-number"]))));
    $narration = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["narration"])));

    include("func/bank-transfer.php");
    $json_response_decode = json_decode($json_response_encode, true);
    if (($json_response_decode["status"] ?? "") == "success") {
        $_SESSION["amount"] = $amount;
        $_SESSION["transfer_fee"] = $json_response_decode["transfer_fee"];
        $_SESSION["account_number"] = $account_number;
        $_SESSION["transfer_enquiry_id"] = $json_response_decode["enquiry_id"];
        $_SESSION["bank_code"] = $json_response_decode["bank_code"] ?? $bank_code;
        $_SESSION["bank_name"] = $json_response_decode["bank_name"];

        // Fallback to AJAX-resolved name if server-side resolution returned empty
        $customer_name = $json_response_decode["customer_name"] ?? "";
        if (empty($customer_name) && !empty($_POST["resolved-account-name"])) {
            $customer_name = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["resolved-account-name"])));
        }

        $_SESSION["account_name"] = $customer_name;
        $_SESSION["narration"] = $json_response_decode["narration"];
    }

    if (($json_response_decode["status"] ?? "") == "failed") {
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
    }
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

// Handle OTP Sending
if (isset($_POST["send_otp"])) {
    $otp = generateOTP();
    $_SESSION["withdrawal_otp"] = $otp;
    $_SESSION["withdrawal_otp_time"] = time();

    $subject = "Withdrawal Verification Code";
    $body = "Your verification code for withdrawal is: <b>$otp</b>. This code expires in 10 minutes.";
    sendVendorEmail($get_logged_user_details["email"], $subject, $body);

    echo json_encode(['status' => 'success', 'message' => 'OTP sent to your email']);
    exit();
}

// Handle AJAX Beneficiary Deletion
if (isset($_POST["delete_beneficiary"])) {
    $ben_id = (int)$_POST["delete_beneficiary"];
    $u_name = mysqli_real_escape_string($connection_server, $get_logged_user_details['username']);
    $v_id = $get_logged_user_details['vendor_id'];

    $delete = mysqli_query($connection_server, "DELETE FROM sas_withdrawal_beneficiaries WHERE id='$ben_id' AND vendor_id='$v_id' AND username='$u_name'");
    if ($delete) {
        echo json_encode(['status' => 'success', 'message' => 'Beneficiary deleted']);
    } else {
        echo json_encode(['status' => 'failed', 'message' => 'Failed to delete beneficiary']);
    }
    exit();
}

// Handle AJAX Account Resolution
if (isset($_POST["resolve_account"])) {
    $bank_code = mysqli_real_escape_string($connection_server, trim($_POST["bank_code"]));
    $account_number = mysqli_real_escape_string($connection_server, trim($_POST["account_number"]));

    $log = function($m) {};

    if (strlen($account_number) == 10 && !empty($bank_code)) {
        $vid = $get_logged_user_details["vendor_id"];
        $select_v = mysqli_query($connection_server, "SELECT payout_provider FROM sas_vendors WHERE id='$vid' LIMIT 1");
        $rv = mysqli_fetch_assoc($select_v);
        $payout_provider = $rv['payout_provider'] ?? '';
        $log("Provider: $payout_provider");

        if ($payout_provider == 'payhub') {
            $raw = payhubResolveBank($account_number, $bank_code, $vid);
            $log("PayHub RAW: " . json_encode($raw));
            if (($raw['status'] ?? '') == 'success' && !empty($raw['account_name'])) {
                echo json_encode([
                    'status' => 'success',
                    'account_name' => $raw['account_name'],
                    'mapped_bank_code' => $raw['mapped_bank_code'] ?? $bank_code
                ]);
            } else {
                echo json_encode(['status' => 'failed', 'message' => $raw['message'] ?? 'Account name not found']);
            }
        } else if ($payout_provider == 'paystack') {
            $raw = paystackResolveAccount($account_number, $bank_code, $vid);
            $log("Paystack RAW: " . json_encode($raw));
            if (($raw['status'] ?? '') == 'success' && !empty($raw['account_name'])) {
                echo json_encode([
                    'status' => 'success',
                    'account_name' => $raw['account_name'],
                    'mapped_bank_code' => $raw['mapped_bank_code'] ?? $bank_code
                ]);
            } else {
                echo json_encode(['status' => 'failed', 'message' => $raw['message'] ?? 'Account name not found']);
            }
        } else {
            $log("Error: Payout provider '$payout_provider' not configured");
            echo json_encode(['status' => 'failed', 'message' => 'Payout provider not configured']);
        }
    } else {
        echo json_encode(['status' => 'failed', 'message' => 'Invalid account details']);
    }
    exit();
}

if (isset($_POST["reset-bank"])) {
    unset($_SESSION["amount"]);
    unset($_SESSION["transfer_fee"]);
    unset($_SESSION["account_number"]);
    unset($_SESSION["transfer_enquiry_id"]);
    unset($_SESSION["bank_code"]);
    unset($_SESSION["bank_name"]);
    unset($_SESSION["account_name"]);
    unset($_SESSION["narration"]);
    header("Location: " . $_SERVER["REQUEST_URI"]);
}

?>
<!DOCTYPE html>

<head>
    <title>Withdraw Funds | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

    <script src="/jsfile/bc-custom-all.js"></script>

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link
        href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
        rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">

    <!-- Searchable Select Assets (Tom Select) -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <style>
        .ts-control { border-radius: 0.5rem !important; padding: 0.75rem 1rem !important; border: none !important; background-color: #f8f9fa !important; font-size: 1rem !important; transition: all 0.3s ease; }
        .ts-control:focus { background-color: #fff !important; box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.1) !important; }
        .ts-dropdown { border-radius: 0.75rem !important; box-shadow: 0 15px 35px rgba(0,0,0,0.15) !important; border: 1px solid rgba(0,0,0,0.05) !important; padding: 0.5rem !important; margin-top: 5px !important; }
        .ts-dropdown .option { border-radius: 0.5rem !important; padding: 0.6rem 1rem !important; margin-bottom: 2px !important; }
        .ts-dropdown .active { background-color: var(--primary-color) !important; color: #fff !important; }
        .ts-wrapper.single .ts-control { background-image: none !important; }

        .modern-input { border-radius: 0.75rem !important; padding: 0.75rem 1.25rem !important; background-color: #f8f9fa !important; border: 1px solid transparent !important; transition: all 0.3s ease; }
        .modern-input:focus { background-color: #fff !important; border-color: var(--primary-color) !important; box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.1) !important; }

        .hover-up { transition: all 0.3s ease; }
        .hover-up:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }

        .ls-1 { letter-spacing: 1px; }
        .animated { animation-duration: 0.6s; animation-fill-mode: both; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .fadeIn { animation-name: fadeIn; }

        /* Compact Header Overrides */
        .dashboard .card.bg-primary { margin-bottom: 0.75rem !important; }
        .dashboard .card.bg-primary .card-body { padding: 0.75rem 1.25rem !important; }
        .dashboard .card.bg-primary .h2 { font-size: 1.25rem !important; margin-bottom: 0 !important; }
        .dashboard .card.bg-primary .small { font-size: 0.65rem !important; margin-bottom: 0 !important; }
        .dashboard .card.bg-primary .bg-opacity-20 { padding: 0.5rem !important; }
        .dashboard .card.bg-primary .fs-3 { font-size: 1.1rem !important; }
    </style>

</head>

<body>
    <?php include("../func/bc-header.php"); ?>

    <div class="pagetitle">
        <h1>WITHDRAW FUNDS</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Withdraw Funds</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <?php include("../func/service-header.php"); ?>

        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                    <div class="card-header bg-white py-4 border-0 text-center">
                        <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow-sm" style="width: 70px; height: 70px;">
                            <i class="bi bi-bank fs-2"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Request Withdrawal</h4>
                        <p class="text-muted small">Move funds from your wallet to any local bank account instantly.</p>
                    </div>
                    <div class="card-body p-4 p-md-5 pt-2">
                    <?php
                    // Prevent default footer modal from showing if we have a transfer result
                    if (isset($_SESSION["transfer_result"])) unset($_SESSION["product_purchase_response"]);

                    if (isset($_SESSION["product_purchase_response"])) { ?>
                        <div class="alert alert-info alert-dismissible fade show rounded-3 mb-4" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            <?php echo $_SESSION["product_purchase_response"]; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php unset($_SESSION["product_purchase_response"]); } ?>

                    <?php if (!isset($_SESSION["transfer_enquiry_id"])) {
                        $get_beneficiaries = array_slice($all_beneficiaries_list, 0, 4);
                        if (!empty($get_beneficiaries)) {
                        ?>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-people me-2"></i>Saved Accounts (Max 4)</label>
                            <div class="list-group list-group-flush rounded-4 overflow-hidden border">
                                <?php foreach($get_beneficiaries as $ben) { ?>
                                <div class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-0 border-0 border-bottom last-border-0" id="ben-row-<?php echo $ben['id']; ?>">
                                    <div class="d-flex align-items-center gap-3 overflow-hidden a-cursor p-3 w-100" onclick="fillBeneficiary(<?php echo htmlspecialchars(json_encode($ben), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                            <i class="bi bi-bank2"></i>
                                        </div>
                                        <div class="text-truncate">
                                            <h6 class="fw-bold text-dark mb-0 small text-truncate"><?php echo strtoupper($ben['account_name']); ?></h6>
                                            <small class="text-muted" style="font-size: 11px;"><?php echo $ben['bank_name']; ?> • <?php echo $ben['account_number']; ?></small>
                                        </div>
                                    </div>
                                    <div class="p-2 pe-3">
                                        <button type="button" class="btn btn-sm text-danger shadow-none" onclick="event.stopPropagation(); deleteBeneficiary(<?php echo $ben['id']; ?>, this)">
                                            <i class="bi bi-trash3 fs-5"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                        <style>.last-border-0:last-child { border-bottom: 0 !important; }</style>
                        <?php } ?>

                        <form method="post" action="">
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-building me-2"></i>Select Bank</label>
                                <select id="bank-code" name="bank-code" placeholder="Start typing bank name..." required>
                                    <option value="">Choose Bank</option>
                                    <?php
                                    foreach ($retrieve_bank_list as $each_bank) {
                                        echo '<option value="' . $each_bank["bankCode"] . '">' . $each_bank["bankName"] . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-hash me-2"></i>Account Number</label>
                                <input id="account-number" name="account-number" type="text" pattern="[0-9]{10}" maxlength="10" placeholder="0123456789" class="form-control form-control-lg modern-input" required />
                            </div>

                            <?php $min_with_amount = (float)($select_vendor_table['min_withdrawal_amount'] ?? getSuperAdminOption('default_min_withdrawal', 1000)); ?>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-wallet2 me-2"></i>Amount (NGN) <span class="ms-2 text-primary">(Min: <?php echo number_format($min_with_amount); ?>)</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0 fw-bold fs-5 pe-2 rounded-start-3" style="border-radius: 0.75rem 0 0 0.75rem !important;">₦</span>
                                    <input name="amount" type="number" min="<?php echo $min_with_amount; ?>" placeholder="<?php echo number_format($min_with_amount, 0); ?>" class="form-control form-control-lg modern-input" style="border-radius: 0 0.75rem 0.75rem 0 !important;" required />
                                </div>
                            </div>

                            <div class="mb-4 pb-2">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-chat-left-dots me-2"></i>Narration</label>
                                <input name="narration" type="text" placeholder="e.g Savings, Rent, Data Gifting..." class="form-control form-control-lg modern-input" required />
                            </div>

                            <input type="hidden" id="resolved-account-name" name="resolved-account-name" value="" />
                            <input type="hidden" id="resolved-bank-code" name="resolved-bank-code" value="" />

                            <button name="verify-bank" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold py-3 shadow-sm transition-all hover-up">
                                <i class="bi bi-shield-check me-2"></i> VERIFY ACCOUNT
                            </button>
                        </form>
                    <?php } else { ?>
                        <div class="text-center mb-4">
                            <div class="bg-primary bg-opacity-10 rounded-4 p-4 text-start border border-primary border-opacity-25">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="w-100">
                                        <small class="text-muted text-uppercase fw-bold ls-1 d-block mb-1" style="font-size: 10px;">Recipient Account Name</small>
                                        <h4 class="fw-bold text-primary mb-2"><?php echo strtoupper($_SESSION["account_name"] ?? "RECIPIENT"); ?></h4>
                                        <div class="bg-white d-inline-block px-3 py-1 rounded-pill shadow-sm border">
                                            <span class="small fw-bold text-dark"><?php echo ($_SESSION["bank_name"] ?? "BANK"); ?> • <?php echo ($_SESSION["account_number"] ?? "0000000000"); ?></span>
                                        </div>
                                    </div>
                                    <div class="bg-white rounded-circle p-3 shadow-sm d-none d-md-flex">
                                        <i class="bi bi-person-check-fill text-primary fs-2"></i>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="bg-white bg-opacity-50 p-3 rounded-3 border">
                                            <small class="text-muted d-block mb-1 fw-bold text-uppercase" style="font-size: 9px;">Withdrawal Amount</small>
                                            <span class="h6 fw-bold mb-0">₦<?php echo number_format($_SESSION["amount"], 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="bg-white bg-opacity-50 p-3 rounded-3 border">
                                            <small class="text-muted d-block mb-1 fw-bold text-uppercase" style="font-size: 9px;">Processing Fee</small>
                                            <span class="h6 fw-bold mb-0">₦<?php echo number_format($_SESSION["transfer_fee"], 2); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 pt-3 border-top border-primary border-opacity-10 d-flex justify-content-between align-items-center">
                                    <span class="small fw-bold text-muted text-uppercase">Total Debit</span>
                                    <span class="h4 fw-bold text-success mb-0">₦<?php echo number_format(floatval($_SESSION["amount"]) + floatval($_SESSION["transfer_fee"]), 2); ?></span>
                                </div>
                            </div>
                        </div>

                        <form method="post" action="" onsubmit="event.preventDefault(); handleTransferSubmit(this);">
                            <input type="hidden" name="process-transfer" value="1" />
                            <input type="hidden" name="overwrite_id" id="overwrite_id" value="0" />
                            <input type="hidden" name="is_ajax" value="1" />
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-shield-lock me-2"></i>Transaction PIN</label>
                                <input type="password" name="pin" class="form-control form-control-lg modern-input text-center" maxlength="4" placeholder="****" inputmode="numeric" style="letter-spacing: 10px; font-size: 24px;" required />
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-envelope-at me-2"></i>Email OTP Verification</label>
                                <div class="input-group">
                                    <input type="text" name="otp" class="form-control form-control-lg modern-input" style="border-radius: 0.75rem 0 0 0.75rem !important;" placeholder="Enter 6-digit code" required />
                                    <button type="button" class="btn btn-dark px-4 fw-bold" style="border-radius: 0 0.75rem 0.75rem 0 !important;" onclick="sendWithdrawalOTP(this)">GET CODE</button>
                                </div>
                                <small class="text-muted mt-2 d-block"><i class="bi bi-info-circle me-1"></i> Check your registered email for the code.</small>
                            </div>

                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" name="save-beneficiary" id="save-beneficiary" checked>
                                <label class="form-check-label small fw-bold text-muted" for="save-beneficiary">SAVE AS BENEFICIARY</label>
                            </div>

                            <button id="btn-complete-transfer" name="initiate-transfer" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold py-3 shadow-sm mb-3">
                                <i class="bi bi-arrow-right-circle me-2"></i> COMPLETE TRANSFER
                            </button>
                        </form>

                        <form method="post" action="">
                            <button name="reset-bank" type="submit" class="btn btn-link text-danger w-100 fw-bold text-decoration-none small">
                                <i class="bi bi-x-circle me-1"></i> Cancel and Change Details
                            </button>
                        </form>
                    <?php } ?>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-footer.php"); ?>

    <?php if (isset($_SESSION["transfer_result"])) {
        $res = $_SESSION["transfer_result"];
        unset($_SESSION["transfer_result"]);
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Swal === 'undefined') {
                console.error('SweetAlert2 not loaded');
                return;
            }

            const resData = <?php echo json_encode($res); ?>;
            const status = resData.status;
            const title = resData.title;
            const message = resData.message;

            let htmlContent = `<div class="text-center">`;

            if (status === 'success') {
                const details = resData.details || {};
                htmlContent += `
                    <div class="mb-3"><i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i></div>
                    <h3 class="fw-bold mb-2">₦${Number(details.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</h3>
                    <p class="text-muted small mb-4">${message}</p>
                    <div class="bg-light p-3 rounded-4 text-start mb-0 border">
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-white">
                            <span class="text-muted small">Recipient</span>
                            <span class="fw-bold small text-end">${details.recipient}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-white">
                            <span class="text-muted small">Bank</span>
                            <span class="fw-bold small text-end">${details.bank}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-white">
                            <span class="text-muted small">Account</span>
                            <span class="fw-bold small text-end">${details.account}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Reference</span>
                            <span class="fw-bold small text-end">${details.ref}</span>
                        </div>
                    </div>
                `;
            } else {
                htmlContent += `
                    <div class="mb-3"><i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3.5rem;"></i></div>
                    <h4 class="fw-bold mb-2">${title}</h4>
                    <p class="text-muted mb-0">${message}</p>
                `;
            }

            htmlContent += `</div>`;

            Swal.fire({
                title: '',
                html: htmlContent,
                showConfirmButton: true,
                confirmButtonText: status === 'success' ? 'DONE' : 'TRY AGAIN',
                confirmButtonColor: status === 'success' ? '#28a745' : '#dc3545',
                customClass: {
                    popup: 'rounded-4 border-0 shadow-lg',
                    confirmButton: 'rounded-pill px-5 py-2 fw-bold'
                }
            });
        });
    </script>
    <?php } ?>

    <script>
    function fillBeneficiary(data) {
        const bankSelect = document.getElementById('bank-code');
        const accountInput = document.getElementById('account-number');

        if (bankSelect && bankSelect.tomselect) {
            bankSelect.tomselect.setValue(data.bank_code);
        } else if (bankSelect) {
            bankSelect.value = data.bank_code;
        }

        if (accountInput) {
            accountInput.value = data.account_number;
            // Trigger verification
            accountInput.dispatchEvent(new Event('input'));
        }

        // Scroll back to top of card
        document.querySelector('.card-header').scrollIntoView({ behavior: 'smooth' });
    }

    const SAVED_BENEFICIARIES = <?php echo json_encode($all_beneficiaries_list); ?>;

    async function handleTransferSubmit(form) {
        const saveCheck = document.getElementById('save-beneficiary');
        const overwriteInput = document.getElementById('overwrite_id');

        if (saveCheck && saveCheck.checked && SAVED_BENEFICIARIES.length >= 4) {
            // Check if current account is already saved
            const currentAcc = "<?php echo $_SESSION['account_number'] ?? ''; ?>";
            const isAlreadySaved = SAVED_BENEFICIARIES.some(b => b.account_number === currentAcc);

            if (!isAlreadySaved) {
                const { value: overwriteId } = await Swal.fire({
                    title: 'Beneficiary Limit Reached',
                    text: 'You can only save up to 4 accounts. Would you like to overwrite an existing one to keep this new one?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Overwrite',
                    cancelButtonText: 'No, Just Transfer',
                    input: 'select',
                    inputOptions: SAVED_BENEFICIARIES.reduce((obj, b) => {
                        obj[b.id] = `${b.bank_name} - ${b.account_number} (${b.account_name})`;
                        return obj;
                    }, {}),
                    inputPlaceholder: 'Select account to replace',
                    inputValidator: (value) => {
                        if (!value) return 'You need to select an account to overwrite';
                    }
                });

                if (overwriteId) {
                    overwriteInput.value = overwriteId;
                } else {
                    // User cancelled or chose "No, Just Transfer"
                    saveCheck.checked = false;
                }
            }
        }

        const btn = document.getElementById('btn-complete-transfer');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>PROCESSING...';
        }

        const formData = new FormData(form);
        fetch('SendFund.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            let htmlContent = `<div class="text-center">`;

            if (res.status === 'success') {
                const details = res.details || {};
                htmlContent += `
                    <div class="mb-3"><i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i></div>
                    <h3 class="fw-bold mb-2">₦${Number(details.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</h3>
                    <p class="text-muted small mb-4">${res.message}</p>
                    <div class="bg-light p-3 rounded-4 text-start mb-0 border">
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-white">
                            <span class="text-muted small">Recipient</span>
                            <span class="fw-bold small text-end">${details.recipient}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-white">
                            <span class="text-muted small">Bank</span>
                            <span class="fw-bold small text-end">${details.bank}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 pb-2 border-bottom border-white">
                            <span class="text-muted small">Account</span>
                            <span class="fw-bold small text-end">${details.account}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Reference</span>
                            <span class="fw-bold small text-end">${details.ref}</span>
                        </div>
                    </div>
                `;
            } else {
                htmlContent += `
                    <div class="mb-3"><i class="bi bi-exclamation-octagon-fill text-danger" style="font-size: 3.5rem;"></i></div>
                    <h4 class="fw-bold mb-2">${res.title}</h4>
                    <p class="text-muted mb-0">${res.message}</p>
                `;
            }

            htmlContent += `</div>`;

            Swal.fire({
                title: '',
                html: htmlContent,
                showConfirmButton: true,
                confirmButtonText: res.status === 'success' ? 'DONE' : 'TRY AGAIN',
                confirmButtonColor: res.status === 'success' ? '#28a745' : '#dc3545',
                allowOutsideClick: false,
                customClass: {
                    popup: 'rounded-4 border-0 shadow-lg',
                    confirmButton: 'rounded-pill px-5 py-2 fw-bold'
                }
            }).then(() => {
                if (res.status === 'success') {
                    window.location.href = 'SendFund.php';
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-right-circle me-2"></i> COMPLETE TRANSFER';
                }
            });
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'A system error occurred. Please check your network.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-right-circle me-2"></i> COMPLETE TRANSFER';
        });

        return false;
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Tom Select for Bank Search
        const bankSelect = document.getElementById('bank-code');
        if (bankSelect) {
            new TomSelect(bankSelect, {
                create: false,
                sortField: {
                    field: "text",
                    direction: "asc"
                },
                maxOptions: null,
                render: {
                    no_results: function(data, escape) {
                        return '<div class="no-results">No banks found for "' + escape(data.input) + '"</div>';
                    }
                }
            });
        }

        const accountInput = document.getElementById('account-number');
        if (bankSelect && accountInput) {
            const nameStatus = document.createElement('div');
            nameStatus.id = 'account-name-status';
            nameStatus.className = 'small fw-bold mt-1';
            accountInput.parentNode.appendChild(nameStatus);

            let lastVerified = "";
            let resolutionCache = {};
            let debounceTimer;

            function verifyAccount() {
                const bankCode = bankSelect.value;
                const accountNo = accountInput.value.trim();
                const currentKey = bankCode + accountNo;

                if (bankCode && accountNo.length === 10) {
                    if (lastVerified === currentKey) return;
                    lastVerified = currentKey;

                    if (resolutionCache[currentKey]) {
                        displayAccountName(resolutionCache[currentKey]);
                        return;
                    }

                    nameStatus.innerHTML = '<span class="text-primary"><i class="spinner-border spinner-border-sm me-1"></i> Verifying...</span>';

                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        const formData = new URLSearchParams();
                        formData.append('resolve_account', '1');
                        formData.append('bank_code', bankCode);
                        formData.append('account_number', accountNo);

                        fetch('SendFund.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: formData.toString()
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status === 'success') {
                                resolutionCache[currentKey] = res;
                                displayAccountName(res);
                            } else {
                                const errorMsg = res.message || 'Unable to verify account';
                                nameStatus.innerHTML = `<span class="text-danger animated headShake"><i class="bi bi-exclamation-triangle-fill me-1"></i> ${errorMsg}</span>`;
                            }
                        })
                        .catch(err => {
                            nameStatus.innerHTML = '<span class="text-danger">Error verifying account</span>';
                        });
                    }, 500);
                } else {
                    nameStatus.innerHTML = '';
                }
            }

            function displayAccountName(res) {
                const accountName = (res.account_name || "").toUpperCase();
                if (accountName && !["NULL", "NONE", "N/A", "UNDEFINED"].includes(accountName)) {
                    nameStatus.innerHTML = `<span class="text-success animated fadeIn"><i class="bi bi-check-circle-fill me-1"></i> ${accountName}</span>`;

                    // Populate hidden fields if they exist for fast POST
                    const hn = document.getElementById('resolved-account-name');
                    const hc = document.getElementById('resolved-bank-code');
                    if (hn) hn.value = accountName;
                    if (hc) hc.value = res.mapped_bank_code || bankSelect.value;
                } else {
                    nameStatus.innerHTML = `<span class="text-danger animated headShake"><i class="bi bi-exclamation-triangle-fill me-1"></i> Verification failed: Name not returned</span>`;
                }
            }

            bankSelect.addEventListener('change', verifyAccount);
            accountInput.addEventListener('input', verifyAccount);
        }
    });

    function deleteBeneficiary(id, btn) {
        if (!confirm('Are you sure you want to delete this saved account?')) return;

        const row = document.getElementById(`ben-row-${id}`);
        if (row) {
            row.style.transition = 'all 0.5s ease';
            row.style.opacity = '0.5';
            row.style.pointerEvents = 'none';
        }

        const formData = new URLSearchParams();
        formData.append('delete_beneficiary', id);

        fetch('SendFund.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData.toString()
        })
        .then(r => r.json())
        .then(res => {
            if (res.status === 'success') {
                if (row) {
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(20px)';
                    setTimeout(() => {
                        row.remove();
                        // Check if list is empty
                        const list = document.querySelector('.list-group');
                        if (list && list.children.length === 0) {
                            list.closest('.mb-4').remove();
                        }
                    }, 500);
                }
            } else {
                alert(res.message);
                if (row) {
                    row.style.opacity = '1';
                    row.style.pointerEvents = 'auto';
                }
            }
        })
        .catch(err => {
            console.error(err);
            if (row) {
                row.style.opacity = '1';
                row.style.pointerEvents = 'auto';
            }
        });
    }

    function sendWithdrawalOTP(btn) {
        const originalText = btn.innerHTML;
        btn.innerHTML = 'Sending...';
        btn.disabled = true;

        const formData = new URLSearchParams();
        formData.append('send_otp', '1');

        fetch('SendFund.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData.toString()
        }).then(r => r.json()).then(res => {
            if(res.status == 'success') {
                Swal.fire({
                    title: 'Code Sent!',
                    text: 'A verification code has been sent to your email. It may take a minute to arrive. Please check your Inbox and Spam folder.',
                    icon: 'success',
                    confirmButtonColor: '#287bff'
                });

                let count = 60;
                const timer = setInterval(() => {
                    btn.innerHTML = `Resend in ${count}s`;
                    count--;
                    if(count < 0) {
                        clearInterval(timer);
                        btn.innerHTML = 'Get Code';
                        btn.disabled = false;
                    }
                }, 1000);
            } else {
                btn.innerHTML = 'Get Code';
                btn.disabled = false;
            }
        });
    }
    </script>
</body>

</html>