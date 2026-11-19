<?php session_start();
    include("../func/bc-config.php");

    if (!isset($get_logged_user_details["username"]) || empty($get_logged_user_details["username"])) {
        header("Location: Login.php");
        exit();
    }

    $share_vendor_id = (int)$get_logged_user_details["vendor_id"];
    $share_username  = (string)$get_logged_user_details["username"];

    // Service Control Center switch (bc-admin per vendor, bc-spadmin globally).
    if (!isServiceEnabled('share_fund', $share_vendor_id)) {
        $_SESSION["product_purchase_response"] = "Fund sharing is currently disabled by the administrator.";
        header("Location: Dashboard.php");
        exit();
    }

    if (isset($_GET["cancel-share"])) {
        unset($_SESSION["share_fund_pending"]);
        header("Location: ShareFund.php");
        exit();
    }

    $share_stage = isset($_SESSION["share_fund_pending"]) ? "verify" : "form";
    $share_error = "";

    // Shared validation used by both steps so a tampered session cannot bypass it.
    $share_validate = function ($recipient, $amount) use ($get_logged_user_details) {
        if (empty($recipient) || $amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
            return "Incomplete Parameters";
        }
        if ((float)$amount < 10) {
            return "Minimum share amount is N10";
        }
        if (strtolower((string)$get_logged_user_details["username"]) === strtolower((string)$recipient)) {
            return "Cannot share fund with yourself";
        }
        if (!is_numeric(userBalance(1)) || (float)userBalance(1) <= 0) {
            return "Balance is LOW";
        }
        if ((float)userBalance(1) < (float)$amount) {
            return "Insufficient Wallet Balance";
        }
        return "";
    };

    // ── Step 1: details + PIN → email a one-time code, do NOT move money yet ──────────────
    if (isset($_POST["share-fund"])) {
        bc_validate_csrf();

        $share_recipient = strtolower(trim(strip_tags((string)($_POST["user"] ?? ''))));
        $share_amount    = bc_sanitize_number($_POST["amount"] ?? 0, 2);
        $share_pin       = trim((string)($_POST["pin"] ?? ''));

        if (!bc_verify_pin_secure($share_pin, $get_logged_user_details, 'share_fund')) {
            $share_error = "Incorrect Security PIN!";
        } else {
            $share_error = $share_validate($share_recipient, $share_amount);
        }

        if ($share_error === "") {
            $share_recipient_sql = mysqli_real_escape_string($connection_server, $share_recipient);
            $share_recipient_q = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$share_vendor_id' AND username='$share_recipient_sql' LIMIT 1");
            if (!$share_recipient_q || mysqli_num_rows($share_recipient_q) !== 1) {
                $share_error = "Recipient username not found";
            }
        }

        if ($share_error === "") {
            // A PIN alone is what the attacker needed once they had the account; the emailed
            // code proves the person at the keyboard still controls the mailbox on file.
            $share_otp = bc_issue_otp('share_fund', $share_username, $share_vendor_id, array(
                'recipient' => $share_recipient,
                'amount' => $share_amount,
            ), 600);

            $otp_body = "Someone (hopefully you) requested a fund transfer of N" . number_format((float)$share_amount, 2)
                . " to @" . $share_recipient . ".<br><br>Your verification code is <b>" . $share_otp
                . "</b>. It expires in 10 minutes.<br><br>If this was not you, change your password and transaction PIN immediately.";
            sendVendorEmail($get_logged_user_details["email"], "Share Fund Verification Code", $otp_body);

            $_SESSION["share_fund_pending"] = array(
                'recipient' => $share_recipient,
                'amount'    => $share_amount,
                'expires'   => time() + 600,
            );
            $share_stage = "verify";
        }
    }

    // ── Step 2: PIN + emailed code → execute exactly the transfer that was staged ─────────
    if (isset($_POST["share-fund-verify"])) {
        bc_validate_csrf();

        $share_pending = $_SESSION["share_fund_pending"] ?? null;
        $share_pin = trim((string)($_POST["pin"] ?? ''));
        $share_otp = preg_replace('/\D/', '', (string)($_POST["otp"] ?? ''));

        if (!is_array($share_pending) || (int)($share_pending['expires'] ?? 0) < time()) {
            unset($_SESSION["share_fund_pending"]);
            $share_stage = "form";
            $share_error = "This transfer request expired. Please start again.";
        } elseif (!bc_verify_pin_secure($share_pin, $get_logged_user_details, 'share_fund')) {
            $share_error = "Incorrect Security PIN!";
        } elseif (!bc_verify_otp('share_fund', $share_username, $share_vendor_id, $share_otp)) {
            $share_error = "Invalid or expired verification code.";
        } else {
            $share_recipient = (string)$share_pending['recipient'];
            $share_amount    = (float)$share_pending['amount'];
            $share_error     = $share_validate($share_recipient, $share_amount);
        }

        if ($share_error === "This transfer request expired. Please start again." || $share_error === "Incorrect Security PIN!" || $share_error === "Invalid or expired verification code.") {
            // Keep the staged request so the user can retry the code; only an expired session resets.
        }

        if ($share_error === "" && isset($share_pending['recipient'])) {
            $purchase_method = "WEB";
            $reference = "SF" . time() . random_int(1000, 9999);
            $description = "Fund Sharing";
            $discounted_amount = $share_amount;
            $type_alternative = ucwords("shared fund");
            $share_recipient_sql = mysqli_real_escape_string($connection_server, $share_recipient);

            $debit_user = chargeUser("debit", $share_recipient_sql, $type_alternative, $reference, "", $share_amount, $discounted_amount, $description, $purchase_method, bc_safe_host(), "1");

            if ($debit_user === "success") {
                $api_response_description = "Shared Fund To User: " . ucwords($share_recipient_sql);
                $create_submitted_share_fund_table = mysqli_query($connection_server, "INSERT INTO sas_fund_transfer_requests (vendor_id, username, recipient_username, reference, amount, discounted_amount, description, mode, api_website, status) VALUES ('$share_vendor_id', '" . mysqli_real_escape_string($connection_server, $share_username) . "', '$share_recipient_sql', '$reference', '$share_amount', '$discounted_amount', '$description', '$purchase_method', '" . bc_safe_host_sql($connection_server) . "', '2')");

                if ($create_submitted_share_fund_table == true) {
                    alterTransaction($reference, "status", "2");
                    alterTransaction($reference, "description", $api_response_description);
                    alterTransaction($reference, "api_website", bc_safe_host_sql($connection_server));

                    $_SESSION["transfer_result"] = array(
                        "status" => "success",
                        "title" => "Transfer Submitted",
                        "message" => "Your fund sharing request to @$share_recipient has been submitted successfully.",
                        "details" => array(
                            "amount" => $share_amount,
                            "recipient" => $share_recipient,
                            "ref" => $reference
                        )
                    );
                } else {
                    // Refund the sender: the debit succeeded but the request row did not.
                    $refund_reference = "RFD" . time() . random_int(1000, 9999);
                    alterTransaction($reference, "description", $api_response_description);
                    chargeUser("credit", $share_username, "Refund", $refund_reference, "", $share_amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, bc_safe_host(), "1");
                    $share_error = "Request Initiation Failed. Your wallet has been refunded.";
                }
            } else {
                $share_error = "Unable to proceed with charges";
            }

            unset($_SESSION["share_fund_pending"]);
            $share_stage = "form";
        }
    }
?>
<?php
// Plate app shell: one shared chrome for the signed-in console.
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Share Fund | " . ($get_all_site_details["site_title"]));
?>
<?php bc_app_sidebar(); ?>
<?php bc_app_topbar(array("title" => "SHARE FUND", "crumb" => "Home / Share Fund")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <h5 class="fw-bold mb-4 text-center">Transfer Funds to Another User</h5>

            <?php if ($share_error !== ""): ?>
            <div class="alert alert-danger py-2 small fw-bold text-center"><?php echo htmlspecialchars($share_error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($share_stage === "verify"):
                $share_pending_view = $_SESSION["share_fund_pending"];
            ?>
            <div class="alert alert-info small text-center">
                A 6-digit verification code was sent to <b><?php echo htmlspecialchars((string)$get_logged_user_details["email"], ENT_QUOTES, 'UTF-8'); ?></b>.
                Enter it below to release <b>N<?php echo number_format((float)$share_pending_view['amount'], 2); ?></b> to
                <b>@<?php echo htmlspecialchars((string)$share_pending_view['recipient'], ENT_QUOTES, 'UTF-8'); ?></b>.
            </div>
            <form method="post" action="">
                <?php echo bc_csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Email Verification Code (OTP)</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope-check text-primary"></i></span>
                        <input name="otp" type="text" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit code" class="form-control border-start-0 fw-bold text-center" inputmode="numeric" autocomplete="one-time-code" required/>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase text-muted">Security PIN</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-shield-lock text-primary"></i></span>
                        <input name="pin" type="password" maxlength="4" placeholder="4-digit PIN" class="form-control border-start-0 fw-bold text-center" inputmode="numeric" required/>
                    </div>
                </div>

                <button name="share-fund-verify" type="submit" class="btn btn-success btn-lg w-100 shadow-sm py-3 fw-bold rounded-3">
                    CONFIRM TRANSFER
                </button>
                <a href="ShareFund.php?cancel-share=1" class="btn btn-link w-100 mt-2 text-muted small">Cancel this transfer</a>
            </form>
            <?php else: ?>
            <form method="post" action="">
                <?php echo bc_csrf_field(); ?>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Recipient Username</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-person text-primary"></i></span>
                        <input id="share-fund-user" name="user" onkeyup="confirmUser();" type="text" placeholder="Enter username" class="form-control border-start-0" required/>
                    </div>
                    <div id="user-status-span" class="small mt-2 text-center text-primary fw-bold"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-uppercase text-muted">Amount to Transfer (NGN)</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">₦</span>
                        <input id="share-fund-amount" name="amount" onkeyup="confirmUser();" type="number" placeholder="Min 10" class="form-control border-start-0 fw-bold" inputmode="numeric" required/>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase text-muted">Security PIN</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-shield-lock text-primary"></i></span>
                        <input name="pin" type="password" maxlength="4" placeholder="4-digit PIN" class="form-control border-start-0 fw-bold text-center" inputmode="numeric" required/>
                    </div>
                    <p class="text-center small text-muted mt-2">Enter your 4-digit transaction PIN. A verification code will then be emailed to you.</p>
                </div>

                <button id="proceedBtn" name="share-fund" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none; opacity: 0.7;">
                    TRANSFER NOW
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small fw-bold text-danger"></span>
                </div>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>
		<?php include("../func/short-fund-transfer-request.php"); ?>
	<script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Swal === 'undefined') return;

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
                            <span class="fw-bold small text-end">@${details.recipient}</span>
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
	<?php bc_app_foot(); ?>
