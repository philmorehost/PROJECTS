<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");
        
    require_once __DIR__.'/../func/bc-admin-wallet-adjustment.php';
    bc_admin_wallet_handle('admin',$get_logged_admin_details);
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Share Fund | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "SHARE FUND", "crumb" => "Home / Share Fund")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-5 col-md-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-4 border-0">
                    <div class="d-flex align-items-center justify-content-center gap-3">
                        <div class="bg-primary bg-opacity-10 p-3 rounded-circle">
                            <i class="bi bi-send text-dark-primary fs-3"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0">Share Funds</h4>
                            <p class="small text-muted mb-0">Admin to Customer Transfer</p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4 p-md-5">
                    <form method="post" action="" data-admin-wallet>
                        <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                        <?php bc_admin_wallet_fields(); ?>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted text-uppercase mb-2">Recipient Username</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-light border-0"><i class="bi bi-person text-primary"></i></span>
                                <input id="share-fund-user" name="user" onkeyup="adminConfirmUser();" type="text" placeholder="e.g. smartuser1" class="form-control bg-light border-0 py-3 rounded-end-3" required/>
                            </div>
                            <div class="mt-3">
                                <div id="user-status-container" class="p-3 bg-light rounded-3 text-center border border-dashed">
                                    <span id="user-status-span" class="small fw-bold text-muted">Awaiting recipient username...</span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Transaction Type</label>
                                <div class="row g-2">
                                    <div class="col-4">
                                        <input type="radio" class="btn-check" name="type" id="type-credit" value="credit" required>
                                        <label class="btn btn-outline-success w-100 py-3 rounded-3" for="type-credit">
                                            <i class="bi bi-plus-circle d-block mb-1 fs-4"></i>
                                            <span class="small fw-bold">Credit</span>
                                        </label>
                                    </div>
                                    <div class="col-4">
                                        <input type="radio" class="btn-check" name="type" id="type-debit" value="debit">
                                        <label class="btn btn-outline-danger w-100 py-3 rounded-3" for="type-debit">
                                            <i class="bi bi-dash-circle d-block mb-1 fs-4"></i>
                                            <span class="small fw-bold">Debit</span>
                                        </label>
                                    </div>
                                    <div class="col-4">
                                        <input type="radio" class="btn-check" name="type" id="type-refund" value="refund">
                                        <label class="btn btn-outline-primary w-100 py-3 rounded-3" for="type-refund">
                                            <i class="bi bi-arrow-counterclockwise d-block mb-1 fs-4"></i>
                                            <span class="small fw-bold">Refund</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Amount (₦)</label>
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-light border-0 fw-bold text-primary">₦</span>
                                    <input id="share-fund-amount" name="amount" onkeyup="adminConfirmUser();" type="number" step="any" min="1" placeholder="0.00" class="form-control bg-light border-0 py-3 rounded-end-3" required/>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                             <div class="alert alert-warning border-0 rounded-4 small py-3 px-4 d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                                <div><b>Security Note:</b> This action is irreversible. Funds will be deducted/added immediately.</div>
                             </div>
                        </div>

                        <div class="mb-4 border-top pt-3">
                            <label class="form-label small fw-bold text-danger text-uppercase mb-2"><i class="bi bi-shield-lock me-1"></i>Admin Security PIN</label>
                            <input name="security_pin" type="password" maxlength="4" pattern="\d{4}" inputmode="numeric" placeholder="****" class="form-control form-control-lg text-center fw-bold bg-light border-0" style="letter-spacing: 10px; font-size: 24px;" required/>
                            <div class="form-text text-center mt-2 small">Verify your identity to authorize this transfer.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold text-danger text-uppercase mb-2"><i class="bi bi-phone-lock me-1"></i>Authenticator / Email Code</label>
                            <div class="input-group input-group-lg">
                                <input name="otp_code" type="text" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="6-digit code" class="form-control text-center fw-bold bg-light border-0" style="letter-spacing: 6px;"/>
                                <input type="hidden" name="totp_code" value=""/>
                                <button type="button" data-security-send="send_stepup_code" class="btn btn-outline-danger fw-bold">Email me a code</button>
                            </div>
                            <div class="form-text text-center mt-2 small">Enter your authenticator code, or request an email code. Required with your PIN for every credit, debit and refund.</div>
                        </div>

                        <button id="proceedBtn" name="share-fund" type="submit" class="btn btn-primary btn-lg w-100 rounded-4 py-3 fw-bold shadow-sm mb-3" style="pointer-events: none; opacity:0.6;">
                            <i class="bi bi-check2-circle me-2"></i>CONFIRM & PROCESS
                        </button>

                        <div id="product-status-span" class="text-center small text-danger fw-bold"></div>
                    </form>
                </div>
            </div>
        </div>
      </div>

	
	



<script src="../asset/bc-admin-wallet.js"></script>
<?php bc_adm_foot(array("area" => "admin")); ?>
