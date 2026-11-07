<?php session_start();
include("../func/bc-admin-config.php");

// Ensure vendor session exists
if (!isset($_SESSION["admin_session"]) || !isset($get_logged_admin_details['id'])) {
    header("Location: /bc-admin/Login.php"); exit();
    exit;
}

$vid = $get_logged_admin_details['id'];

// Get USSD Channel settings
$enabled = getSuperAdminOption('ussd_access_enabled', '0');
$fee = (float)getSuperAdminOption('ussd_access_fee', '0');
$ussd_access = (int)$get_logged_admin_details['ussd_access'];
$balance = (float)$get_logged_admin_details['balance'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>USSD Channel | Vendor Admin</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .premium-card { border: none; border-radius: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .hero-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white; border-radius: 1.5rem; padding: 3rem 2rem; position: relative; overflow: hidden;
        }
        .hero-banner::after {
            content: 'USSD'; position: absolute; right: -10px; bottom: -30px;
            font-size: 10rem; font-weight: 900; opacity: 0.05; font-style: italic;
        }
        .btn-activate {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: white; border: none; border-radius: 1rem; padding: 14px 28px;
            font-weight: 700; transition: all 0.2s;
        }
        .btn-activate:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(13,110,253,0.3); color: white; }
    </style>
</head>
<body>
<?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle mb-4">
        <h1 class="fw-bold">USSD Channel Manager</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">USSD Channel</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <!-- Global Check -->
        <?php if ($enabled != '1'): ?>
            <div class="alert alert-warning border-0 rounded-4 p-4 shadow-sm animate__animated animate__fadeIn mb-4">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-exclamation-triangle-fill fs-3 text-warning"></i>
                    <div>
                        <h5 class="fw-bold mb-1">Service Globally Disabled</h5>
                        <p class="mb-0 small opacity-75">The USSD Channel Access service is currently disabled by the Super Administrator. Please check back later.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>

            <!-- Premium Hero Banner -->
            <div class="hero-banner shadow-sm mb-4 animate__animated animate__fadeIn">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <?php if ($ussd_access == 1): ?>
                                <span class="badge bg-success rounded-pill px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger rounded-pill px-3 py-2"><i class="bi bi-lock-fill me-1"></i> Locked</span>
                            <?php endif; ?>
                            <span class="small text-white opacity-75">USSD Gateway Service</span>
                        </div>
                        <h2 class="fw-bold mb-3">Unlock Offline USSD Capabilities</h2>
                        <p class="opacity-75 mb-0">Allow your users to print data bundles, airtime, utility tokens, and verify identity pins offline via USSD codes.</p>
                    </div>
                    <div class="col-md-5 mt-4 mt-md-0 text-md-end">
                        <div class="bg-white bg-opacity-10 rounded-4 p-3 d-inline-block text-start border border-white border-opacity-10">
                            <small class="text-white opacity-50 d-block mb-1">Your Wallet Balance</small>
                            <h3 class="fw-bold text-white mb-0">₦<?php echo number_format($balance, 2); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card premium-card p-4">
                        <?php if ($ussd_access == 1): ?>
                            <div class="text-center py-4">
                                <div class="rounded-circle bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center mb-3" style="width: 70px; height: 70px;">
                                    <i class="bi bi-shield-check fs-1"></i>
                                </div>
                                <h4 class="fw-bold text-success mb-2">USSD Access is Active!</h4>
                                <p class="text-muted small px-3">Your platform is authorized to configure and process USSD offline VTU print jobs.</p>
                                <hr class="my-4 opacity-25">
                                <a href="DataBundleCard.php?type=data" class="btn btn-outline-dark rounded-pill px-4 fw-bold mb-2">Go to Print Hub to Configure</a>
                            </div>
                        <?php else: ?>
                            <h5 class="fw-bold mb-3"><i class="bi bi-wallet2 me-2 text-primary"></i>Activate USSD Service</h5>
                            <p class="text-muted small mb-4">Activating the USSD Channel grants your platform access to setup HollaTags integrations and offline menus.</p>
                            
                            <div class="p-3 bg-light rounded-3 mb-4">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="small text-muted">Activation Cost</span>
                                    <span class="fw-bold text-dark">₦<?php echo number_format($fee, 2); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="small text-muted">Billing Cycle</span>
                                    <span class="fw-bold text-success">One-Time Payment</span>
                                </div>
                            </div>

                            <button class="btn-activate w-100 py-3" id="btn-pay-ussd">
                                <i class="bi bi-credit-card me-2"></i> Pay & Activate Now
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card premium-card bg-light p-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-info-circle me-2"></i>How USSD Integration Works</h5>
                        <ul class="small text-muted" style="line-height: 1.8;">
                            <li><strong>Setup HollaTags Credentials:</strong> Once activated, you can input your HollaTags username, password, and custom USSD code inside the Print Hub configurations.</li>
                            <li><strong>Create Custom Offline Menus:</strong> Link networks (MTN, Glo, Airtel, etc.) to your customized service packages and define offline customer experiences.</li>
                            <li><strong>Automatic Processing:</strong> Commands dialed by users are automatically forwarded to your server API endpoint and fulfilled using your active VTU APIs.</li>
                        </ul>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </section>

<?php include("../func/bc-admin-footer.php"); ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.getElementById('btn-pay-ussd')?.addEventListener('click', function() {
    Swal.fire({
        title: 'Activate USSD Channel?',
        text: 'A sum of ₦<?php echo number_format($fee, 2); ?> will be debited from your wallet to unlock offline USSD channel capabilities.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Pay Now',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            return fetch('ajax-ussd-activate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(response.statusText)
                }
                return response.json()
            })
            .catch(error => {
                Swal.showValidationMessage(`Request failed: ${error}`)
            })
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            if (result.value.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Activated!',
                    text: result.value.message,
                    confirmButtonColor: '#0d6efd'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Failed',
                    text: result.value.message,
                    confirmButtonColor: '#0d6efd'
                });
            }
        }
    })
});
</script>
</body>
</html>
