<?php session_start();
    include("../func/bc-admin-config.php");
        
    $payment_gateway_array = array("monnify", "flutterwave", "paystack", "payvessel", "payhub");
?>
<!DOCTYPE html>
<head>
    <title>Fund Wallet | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    <script type="text/javascript" src="https://sdk.monnify.com/plugin/monnify.js"></script>
    <script src="https://checkout.flutterwave.com/v3.js"></script>
    <script src="https://js.paystack.co/v1/inline.js"></script>
    <script src="/jsfile/bc-custom-all.js"></script>
    <script>
        function initServerCheckout(callback) {
            const urlParams = new URLSearchParams(window.location.search);
            const purpose = urlParams.get('purpose') || '';

            const reference = document.getElementById("num-ref").value;
            const amount = document.getElementById("amount-to-pay").value;
            const username = '<?php echo $get_logged_admin_details['email']; ?>';
            const vendor_id = '<?php echo $get_logged_admin_details['id']; ?>';

            // Show loading state on button
            const btn = document.getElementById("fundProceedBtn");
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>INITIALIZING...';
            btn.style.pointerEvents = "none";

            fetch('../web/finance-ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=create_checkout&is_vendor=1&reference=${encodeURIComponent(reference)}&amount=${amount}&username=${encodeURIComponent(username)}&vendor_id=${vendor_id}&target=${purpose}`
            })
            .then(response => response.json())
            .then(data => {
                btn.innerHTML = originalText;
                btn.style.pointerEvents = "auto";
                if(data.status === 'success') {
                    callback();
                } else {
                    alert("Failed to initialize transaction: " + (data.message || "Unknown error"));
                }
            })
            .catch(err => {
                console.error("Checkout init error:", err);
                btn.innerHTML = originalText;
                btn.style.pointerEvents = "auto";
                callback();
            });
        }
    
    	function monnifyPaymentGateway(){
		initServerCheckout(payWithMonnify);
    	}

        function flutterwavePaymentGateway(){
		initServerCheckout(makePaymentFlutterwave);
    	}

        function paystackPaymentGateway(){
		initServerCheckout(makePaymentPaystack);
    	}

        function payhubPaymentGateway(){
            initServerCheckout(makePaymentPayhubServer);
	    }
    	
        //MONNIFY CHECKOUT GATEWAY
        function payWithMonnify() {
            setTimeout(() => {
                MonnifySDK.initialize({
                    amount: parseFloat(document.getElementById("amount-to-pay").value),
                    currency: "NGN",
                    reference: document.getElementById("num-ref").value,
                    customerFullName: document.getElementById("user-name").value,
                    customerEmail: document.getElementById("user-email").value,
                    apiKey: document.getElementById("gateway-public").value,
                    contractCode: document.getElementById("gateway-encrypt").value,
                    paymentDescription: "Wallet Funding",
                    metadata: {
                        "name": "",
                        "age": ""
                    },
                    incomeSplitConfig: [],
                    onLoadStart: () => {
                        console.log("loading has started");
                    },
                    onLoadComplete: () => {
                        console.log("SDK is UP");
                    },
                    onComplete: function(response) {
                        //Implement what happens when the transaction is completed.
                        window.location.href = "/bc-admin/Dashboard.php";
                    },
                    onClose: function(data) {
                        //Implement what should happen when the modal is closed here
                        //window.location.href = "/bc-admin/Dashboard.php";
                    }
                });
            }, 100);
        }

        //FLUTTERWAVE CHECKOUT GATEWAY
        function makePaymentFlutterwave(){
            setTimeout(() => {
                FlutterwaveCheckout({
                    public_key: document.getElementById("gateway-public").value,
                    tx_ref: document.getElementById("num-ref").value,
                    amount: document.getElementById("amount-to-pay").value,
                    currency: "NGN",
                    payment_options: "card, banktransfer, ussd",
                    redirect_url: "",
                    meta: {
                        consumer_id: "",
                        consumer_mac: "",
                    },
                    customer: {
                        email: document.getElementById("user-email").value,
                        phone_number: document.getElementById("user-phone").value,
                        name: document.getElementById("user-name").value,
                    },
                    customizations: {
                        title: "",
                        description: "",
                        logo: "",
                    },
                    callback: function(payment) {
                        window.location.href = "/bc-admin/Dashboard.php";
                    }
                });
            }, 0);
        }

        // Confirms a Paystack payment with the server. The server re-verifies with Paystack's
        // API BEFORE crediting (see web/finance-ajax.php?action=verify_paystack), so the wallet is
        // funded immediately even if the webhook is delayed, and never from client-side data alone.
        function verifyPaystackFunding(done) {
            const reference = document.getElementById("num-ref").value;
            fetch('../web/finance-ajax.php?action=verify_paystack&reference=' + encodeURIComponent(reference) + '&is_vendor=1')
            .then(r => r.json())
            .then(() => { if (done) done(); })
            .catch(() => { if (done) done(); });
        }

        //PAYSTACK CHECKOUT GATEWAY
        function makePaymentPaystack(){
            setTimeout(() => {
                let handler = PaystackPop.setup({
                key: document.getElementById("gateway-public").value, // Replace with your public key
                email: document.getElementById("user-email").value,
                amount: document.getElementById("amount-to-pay").value * 100,
                currency: 'NGN', // Use GHS for Ghana Cedis or USD for US Dollars
                ref: document.getElementById("num-ref").value, // Replace with a reference you generated
                
                // label: "Optional string that replaces customer email"
                onClose: function() {
                    //window.location.href = "/bc-admin/Dashboard.php";
                },
                callback: function(response){
                    verifyPaystackFunding(function() {
                        window.location.href = "/bc-admin/Dashboard.php";
                    });
                }
                });
                handler.openIframe();
            }, 0);
        }


        function makePaymentPayhubServer() {
            const reference = document.getElementById("num-ref").value;
            const btn = document.getElementById("fundProceedBtn");
            const originalText = btn.innerHTML;

            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>PREPARING...';
            btn.style.pointerEvents = "none";

            fetch('../web/finance-ajax.php?action=gateway_redirect&gateway=payhub&reference=' + reference + '&amount=' + encodeURIComponent(document.getElementById("amount-to-pay").value))
            .then(response => response.json())
            .then(res => {
                if (res.status === 'success') {
                    const url = new URL(res.checkout_url);
                    url.searchParams.set('embed', '1');
                    // PayHub's initialize generates its own PH_... reference (it ignores ours) —
                    // use it for status polling and the success redirect so the server verifies the
                    // correct PayHub transaction.
                    const payhubRef = (res.payhub_ref || '').trim();

                    // Create Modal for Inline Checkout
                    const modalId = 'payhubModal';
                    let modalEl = document.getElementById(modalId);
                    if (!modalEl) {
                        modalEl = document.createElement('div');
                        modalEl.id = modalId;
                        modalEl.className = 'modal fade';
                        modalEl.setAttribute('data-bs-backdrop', 'static');
                        modalEl.innerHTML = `
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
                                    <div class="modal-header border-0 bg-light py-3">
                                        <h6 class="modal-title fw-bold"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>SECURE CHECKOUT</h6>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body p-0" style="height: 600px; max-height: 80vh;">
                                        <iframe id="payhubIframe" src="" style="width: 100%; height: 100%; border: none;" allow="clipboard-read; clipboard-write"></iframe>
                                    </div>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(modalEl);
                    }

                    document.getElementById('payhubIframe').src = url.toString();
                    const bsModal = new bootstrap.Modal(modalEl);
                    bsModal.show();

                    btn.innerHTML = originalText;
                    btn.style.pointerEvents = "auto";

                    window.addEventListener('message', function(event) {
                        // The PayHub checkout iframe posts {type:'payhub_success', data:{reference,status:'success'}}.
                        if (event.origin.includes('merchant.payhub.com.ng')) {
                            const msg = event.data;
                            const ok = msg && (msg === 'payment_success' || msg.type === 'payhub_success' || (msg.data && msg.data.status === 'success'));
                            if (ok) {
                                // Prefer the reference PayHub reported back; fall back to the one we stored.
                                const phRef = (msg && msg.data && msg.data.reference) ? msg.data.reference : payhubRef;
                                window.location.href = '/bc-admin/payhub-success.php?reference=' + encodeURIComponent(reference) + '&payhub_ref=' + encodeURIComponent(phRef) + '&amount=' + encodeURIComponent(document.getElementById("amount-to-pay").value);
                            }
                        }
                    }, false);

                    // PayHub's postMessage isn't reliable (the modal often just resets), so also
                    // poll the server: it verifies the payment and credits the wallet, then we
                    // redirect to the dashboard — same behaviour as Paystack.
                    let payhubPollCount = 0;
                    let payhubPoll = setInterval(() => {
                        payhubPollCount++;
                        if (payhubPollCount > 150) { clearInterval(payhubPoll); return; } // ~10 min cap
                        fetch('../web/finance-ajax.php?action=payhub_status&reference=' + encodeURIComponent(reference) + '&payhub_ref=' + encodeURIComponent(payhubRef) + '&is_vendor=1')
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.status === 'paid') {
                                    clearInterval(payhubPoll);
                                    window.location.href = '/bc-admin/payhub-success.php?reference=' + encodeURIComponent(reference) + '&payhub_ref=' + encodeURIComponent(payhubRef) + '&amount=' + encodeURIComponent(document.getElementById("amount-to-pay").value);
                                }
                            })
                            .catch(() => {});
                    }, 4000);

                } else {
                    throw new Error(res.message || "Unknown API Error");
                }
            })
            .catch(err => {
                console.error("PayHub Init Error:", err);
                alert("Could not initialize PayHub: " + err.message);
                btn.innerHTML = originalText;
                btn.style.pointerEvents = "auto";
            });
        }
    </script>
            
          <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">
  <style>
    @media (min-width: 992px) {
        .gateway-option img { width: 150px !important; height: 150px !important; }
    }
    @media (max-width: 991px) {
        .gateway-option img { width: 50px !important; height: 50px !important; }
    }
  </style>
</head>
<body>
	<?php include("../func/bc-admin-header.php"); ?>	
	<div class="pagetitle">
      <h1>FUND WALLET</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Fund Wallet</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-6">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="display-6 fw-bold text-primary mb-2">Fund Account</div>
                    <p class="text-muted">Select a payment gateway and enter amount to fund your wallet instantly.</p>
                </div>

                <form method="post">
                    <div class="d-flex justify-content-center gap-3 mb-4 flex-wrap">
                        <?php
                            foreach($payment_gateway_array as $gateway_name){
                                // For Vendors funding the platform, we use platform config (vid=0)
                                $gw_details = getGatewayDetails($gateway_name, 0);
                                $is_enabled = isGatewayEnabled($gateway_name, 0);

                                if ($gw_details) {
                                    $gw_display_name = ucwords(trim($gateway_name));
                                    $gw_id = strtolower(trim($gateway_name));
                                    $gw_public = trim($gw_details["public_key"] ?? '');
                                    $gw_encrypt = trim($gw_details["encrypt_key"] ?? '');
                                    $gw_int = trim($gw_details["percentage"] ?? '0');

                                    if ($is_enabled) {
                                        echo '<div class="gateway-option">
                                            <img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="enabled" gateway-public="' . $gw_public . '" gateway-encrypt="' . $gw_encrypt . '" gateway-int="' . $gw_int . '" product-name-array="' . implode(",",$payment_gateway_array) . '" src="/asset/' . $gw_id . '.jpg" onclick="vtickPaymentGateway(this, \'' . $gw_id . '\', \'gatewayname\', \'fundProceedBtn\', \'jpg\');" class="rounded-4 border shadow-sm a-cursor" style="width: 80px; height: 80px; object-fit: contain; padding: 10px;"/>
                                            <div class="small text-center mt-1 fw-bold">' . $gw_display_name . '</div>
                                        </div>';
                                    } else {
                                        echo '<div class="gateway-option opacity-50">
                                            <img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="disabled" src="/asset/' . $gw_id . '.jpg" class="rounded-4 border grayscale" style="width: 80px; height: 80px; object-fit: contain; padding: 10px; cursor: not-allowed;"/>
                                            <div class="small text-center mt-1">' . $gw_display_name . '</div>
                                        </div>';
                                    }
                                }
                            }
                        ?>
                    </div>

                    <?php if (isset($_GET['purpose']) && $_GET['purpose'] == 'plisio_activation'): ?>
                        <div class="alert alert-primary rounded-4 mb-4">
                             <h6 class="fw-bold mb-1"><i class="bi bi-rocket-takeoff me-2"></i>Plisio Activation</h6>
                             <p class="small mb-0">You are completing the payment to unlock the Plisio Crypto Gateway.</p>
                        </div>
                    <?php elseif (isset($_GET['purpose']) && $_GET['purpose'] == 'payout_activation'): ?>
                        <div class="alert alert-primary rounded-4 mb-4">
                             <h6 class="fw-bold mb-1"><i class="bi bi-bank2 me-2"></i>Withdrawal Module Activation</h6>
                             <p class="small mb-0">You are completing the payment to unlock the Automated Withdrawal Module.</p>
                        </div>
                    <?php endif; ?>

                    <div class="form-floating mb-4">
                        <input id="fund-amount" type="number" class="form-control form-control-lg rounded-3 border-0 bg-light" onkeyup="vcheckPaymentGatewayDetails('fundProceedBtn','2');" placeholder="Amount" step="1" min="100" required value="<?php echo $_GET['amount'] ?? ''; ?>" <?php echo isset($_GET['amount']) ? 'readonly' : ''; ?>>
                        <label for="fund-amount" class="text-muted ps-3">Amount to Fund (₦)</label>
                        <div class="form-text small mt-2 ps-2 text-primary fw-bold" id="product-status-span"></div>
                    </div>

                    <input id="gatewayname" hidden readonly required/>
                    <input id="amount-to-pay" hidden readonly required/>
                    <input id="user-name" value="<?php echo $get_logged_admin_details['firstname']." ".$get_logged_admin_details['lastname']." ".(isset($get_logged_admin_details['othername']) ? $get_logged_admin_details['othername'] : ""); ?>" hidden readonly required/>
                    <input id="user-email" value="<?php echo $get_logged_admin_details['email']; ?>" hidden readonly required/>
                    <input id="user-phone" value="<?php echo $get_logged_admin_details['phone_number']; ?>" hidden readonly required/>
                    <input id="num-ref" value="<?php echo $_GET['ref'] ?? ''; ?>" hidden readonly required/>
                    <input id="gateway-public" hidden readonly required/>
                    <input id="gateway-encrypt" hidden readonly required/>

                    <button id="fundProceedBtn" type="button" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold py-3 shadow" style="pointer-events: none; opacity: 0.6;">
                        CONTINUE TO PAYMENT
                    </button>

                    <div class="mt-4 text-center">
                        <div class="small text-muted mb-2">Secure Payment Gateway</div>
                        <div class="d-flex justify-content-center gap-2 opacity-75">
                            <i class="bi bi-shield-lock-fill text-success"></i>
                            <i class="bi bi-credit-card-2-front-fill text-primary"></i>
                            <i class="bi bi-bank2 text-info"></i>
                        </div>
                    </div>
                </form>
            </div>
          </div>
        </div>
      </div>
    </section>

	<?php include("../func/bc-admin-footer.php"); ?>
	
</body>
</html>