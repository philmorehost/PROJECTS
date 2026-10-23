<?php session_start();
include("../func/bc-config.php");

$payment_gateway_array = array("monnify", "flutterwave", "paystack", "beewave", "payhub");

$retry_ref = $_GET['retry_ref'] ?? '';
$retry_amount = $_GET['amount'] ?? '';
?>
<!DOCTYPE html>

<head>
    <title>Fund Wallet | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    <script type="text/javascript" src="https://sdk.monnify.com/plugin/monnify.js"></script>
    <script src="https://checkout.flutterwave.com/v3.js"></script>
    <script src="https://js.paystack.co/v2/inline.js"></script>
    <script src="https://merchant.beewave.ng/checkout.min.js" defer></script>

    <script src="/jsfile/bc-custom-all.js"></script>
    <script>
        function initServerCheckout(callback) {
            const reference = document.getElementById("num-ref").value;
            const amount = document.getElementById("amount-to-pay").value;
            const username = '<?php echo $get_logged_user_details['username']; ?>';
            const vendor_id = '<?php echo $get_logged_user_details['vendor_id']; ?>';
            const gateway = document.getElementById("gatewayname").value;

            // Show loading state on button
            const btn = document.getElementById("fundProceedBtn");
            const originalText = btn.innerHTML;

            function restoreBtn() {
                btn.innerHTML = originalText;
                btn.style.pointerEvents = "auto";
                btn.style.opacity = "1";
            }

            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>INITIALIZING...';
            btn.style.pointerEvents = "none";

            fetch('finance-ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=create_checkout&reference=${encodeURIComponent(reference)}&amount=${amount}&username=${encodeURIComponent(username)}&vendor_id=${vendor_id}`
            })
            .then(response => response.json())
            .then(data => {
                restoreBtn();
                if(data.status === 'success') {
                    callback();
                } else {
                    alert("Failed to initialize transaction: " + (data.message || "Unknown error"));
                }
            })
            .catch(err => {
                console.error("Checkout init error:", err);
                restoreBtn();
                // Fallback: try to proceed anyway for some gateways
                callback();
            });
        }

        function monnifyPaymentGateway() {
            initServerCheckout(payWithMonnify);
        }

        function flutterwavePaymentGateway() {
            initServerCheckout(makePaymentFlutterwave);
        }

        function paystackPaymentGateway() {
            initServerCheckout(makePaymentPaystack);
        }

        function beewavePaymentGateway() {
            initServerCheckout(makePaymentBeewave);
        }

        function payhubPaymentGateway() {
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
                        "username": '<?php echo $get_logged_user_details['username']; ?>',
                        "vendor_id": '<?php echo $get_logged_user_details['vendor_id']; ?>'
                    },
                    incomeSplitConfig: [],
                    onLoadStart: () => {
                        console.log("loading has started");
                    },
                    onLoadComplete: () => {
                        console.log("SDK is UP");
                    },
                    onComplete: function (response) {
                        //Implement what happens when the transaction is completed.
                        window.location.href = "/web/Dashboard.php";
                    },
                    onClose: function (data) {
                        //Implement what should happen when the modal is closed here
                        //window.location.href = "/web/Dashboard.php";
                    }
                });
            }, 0);
        }

        //FLUTTERWAVE CHECKOUT GATEWAY
        function makePaymentFlutterwave() {
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
                    callback: function (payment) {
                        window.location.href = "/web/Dashboard.php";
                    }
                });
            }, 0);
        }

        // Confirms a Paystack payment with the server. The server re-verifies with Paystack's
        // API BEFORE crediting (see finance-ajax.php?action=verify_paystack), so the wallet is
        // funded immediately even if the webhook is delayed, and never from client-side data alone.
        function verifyPaystackFunding(done) {
            const reference = document.getElementById("num-ref").value;
            fetch('finance-ajax.php?action=verify_paystack&reference=' + encodeURIComponent(reference) + '&is_vendor=0')
            .then(r => r.json())
            .then(() => { if (done) done(); })
            .catch(() => { if (done) done(); });
        }

        //PAYSTACK CHECKOUT GATEWAY
        function makePaymentPaystack() {
            setTimeout(() => {
                try {
                    var popup = new PaystackPop();
                    popup.newTransaction({
                        key: document.getElementById("gateway-public").value,
                        email: document.getElementById("user-email").value,
                        amount: Math.round(parseFloat(document.getElementById("amount-to-pay").value) * 100),
                        currency: 'NGN',
                        ref: document.getElementById("num-ref").value,
                        onSuccess: function(transaction) {
                            verifyPaystackFunding(function() {
                                window.location.href = "/web/Dashboard.php";
                            });
                        },
                        onCancel: function() {
                            // User closed the popup - re-enable button
                            var btn = document.getElementById("fundProceedBtn");
                            if (btn) {
                                btn.style.pointerEvents = "auto";
                                btn.style.opacity = "1";
                            }
                        }
                    });
                } catch(e) {
                    console.error("Paystack error:", e);
                    alert("Paystack could not be initialized. Please try again.");
                    var btn = document.getElementById("fundProceedBtn");
                    if (btn) {
                        btn.style.pointerEvents = "auto";
                        btn.style.opacity = "1";
                    }
                }
            }, 0);
        }

        //BEEWAVE CHECKOUT GATEWAY
        function makePaymentBeewave() {
            setTimeout(() => {
                BeefinanceCheckout.open({
                    accessKey: document.getElementById("gateway-public").value,
                    name: document.getElementById("user-name").value,
                    email: '<?php echo str_replace([".", "-"], "", $_SERVER["HTTP_HOST"])."-".$get_logged_user_details['username']."-".$get_logged_user_details['email']; ?>',
                    phone: document.getElementById("user-phone").value,
                    amount: document.getElementById("amount-to-pay").value
                });
            }, 0);
        }


        function makePaymentPayhubServer() {
            const reference = document.getElementById("num-ref").value;
            const btn = document.getElementById("fundProceedBtn");
            const originalText = btn.innerHTML;

            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>PREPARING...';
            btn.style.pointerEvents = "none";

            fetch('finance-ajax.php?action=gateway_redirect&gateway=payhub&reference=' + reference + '&amount=' + encodeURIComponent(document.getElementById("amount-to-pay").value))
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
                                        <iframe id="payhubIframe" src="" style="width: 100%; height: 100%; border: none;" allow="clipboard-read; clipboard-write; payment" allowfullscreen></iframe>
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
                                window.location.href = '/web/payhub-success.php?reference=' + encodeURIComponent(reference) + '&payhub_ref=' + encodeURIComponent(phRef) + '&amount=' + encodeURIComponent(document.getElementById("amount-to-pay").value);
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
                        fetch('finance-ajax.php?action=payhub_status&reference=' + encodeURIComponent(reference) + '&payhub_ref=' + encodeURIComponent(payhubRef))
                            .then(r => r.json())
                            .then(data => {
                                if (data && data.status === 'paid') {
                                    clearInterval(payhubPoll);
                                    window.location.href = '/web/payhub-success.php?reference=' + encodeURIComponent(reference) + '&payhub_ref=' + encodeURIComponent(payhubRef) + '&amount=' + encodeURIComponent(document.getElementById("amount-to-pay").value);
                                } else if (data && data.status === 'failed') {
                                    // A definitive failure: stop polling and say so, instead of holding
                                    // the modal open for the whole 10-minute window on a payment that is
                                    // already over. Nothing was credited.
                                    clearInterval(payhubPoll);
                                    btn.innerHTML = originalText;
                                    btn.style.pointerEvents = "auto";
                                    alert("This payment was not completed by PayHub, so no wallet was credited."
                                        + (data.message ? "\n\n" + data.message : "")
                                        + "\n\nIf money left your account, contact support with reference " + reference + ".");
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
    
    <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link
    href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
    rel="stylesheet">

    <script src="https://merchant.beewave.ng/checkout.min.js" defer></script>
  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>

<body>
    <?php include("../func/bc-header.php"); ?>
    
<div class="pagetitle">
      <h1>ATM FUNDING</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">ATM Funding</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <?php include("../func/service-header.php"); ?>

      <div class="row justify-content-center">
        <div class="col-lg-8">
          <div class="card shadow-sm border-0 p-4">
            <h5 class="fw-bold mb-4 text-center">Top Up with ATM Card / Bank Transfer</h5>

            <form method="post" action="">
                <div class="gateway-grid d-flex flex-wrap justify-content-center gap-3 mb-4">
                    <?php
                    foreach ($payment_gateway_array as $gateway_name) {
                        // Strict vendor isolation: always evaluate against the logged-in user's
                        // OWN vendor, never a resolved/fallback vendor.
                        if (!isServiceEnabled($gateway_name, $get_logged_user_details["vendor_id"])) continue;

                        // Optimization DG6.7: Use robust gateway detection with platform fallback
                        $gw_details = getGatewayDetails($gateway_name, $get_logged_user_details["vendor_id"]);
                        $is_enabled = isGatewayEnabled($gateway_name, $get_logged_user_details["vendor_id"]);

                        if ($gw_details) {
                            $gw_display_name = ucwords(trim($gateway_name));
                            $gw_id = strtolower(trim($gateway_name));
                            $gw_public = trim($gw_details["public_key"] ?? '');
                            $gw_encrypt = trim($gw_details["encrypt_key"] ?? '');
                            $gw_int = trim($gw_details["percentage"] ?? '0');

                            if ($is_enabled) {
                                echo '<img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="enabled" gateway-public="' . $gw_public . '" gateway-encrypt="' . $gw_encrypt . '" gateway-int="' . $gw_int . '" product-name-array="' . implode(",", $payment_gateway_array) . '" src="/asset/' . $gw_id . '.jpg" onclick="tickPaymentGateway(this, \'' . $gw_id . '\', \'gatewayname\', \'fundProceedBtn\', \'jpg\');" class="rounded-3 border shadow-sm" style="width: 80px; height: 50px; object-fit: contain; cursor: pointer;"/>';
                            } else {
                                echo '<img alt="' . $gw_display_name . '" id="' . $gw_id . '-lg" product-status="disabled" src="/asset/' . $gw_id . '.jpg" class="rounded-3 border opacity-50 grayscale" style="width: 80px; height: 50px; object-fit: contain; filter: grayscale(1);"/>';
                            }
                        }
                    }
                    ?>
                </div>

                <input id="gatewayname" name="" type="text" placeholder="Gateway Name" hidden readonly required />
                <input id="amount-to-pay" name="" type="text" placeholder="" hidden readonly required />
                <?php
                    $full_name = trim($get_logged_user_details['firstname'] . " " . $get_logged_user_details['lastname'] . " " . ($get_logged_user_details['othername'] ?? ''));
                    $full_name = preg_replace('/\s+/', ' ', $full_name);
                ?>
                <input id="user-name" name="" type="text" value="<?php echo htmlspecialchars($full_name); ?>" hidden readonly required />
                <input id="user-email" name="" type="email" value="<?php echo $get_logged_user_details['email']; ?>" hidden readonly required />
                <input id="user-phone" name="" type="number" value="<?php echo $get_logged_user_details['phone_number']; ?>" hidden readonly required />
                <input id="num-ref" name="" type="text" value="<?php echo !empty($retry_ref) ? htmlspecialchars($retry_ref) : time().rand(1000,9999); ?>" hidden readonly required />
                <input id="gateway-public" name="" type="text" hidden readonly required />
                <input id="gateway-encrypt" name="" type="text" hidden readonly required />

                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase text-muted">Amount to Fund (NGN)</label>
                    <?php if(!empty($retry_ref)): ?>
                        <div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i> Retrying payment for Ref: <?php echo htmlspecialchars($retry_ref); ?></div>
                    <?php endif; ?>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white border-end-0">₦</span>
                        <input id="fund-amount" name="" type="number" onkeyup="checkPaymentGatewayDetails('fundProceedBtn','2');" placeholder="Min 100" step="1" min="100" class="form-control border-start-0 fw-bold" value="<?php echo htmlspecialchars($retry_amount); ?>" <?php echo !empty($retry_amount) ? 'readonly' : ''; ?> required />
                    </div>
                </div>

                <button id="fundProceedBtn" name="" type="button" class="btn btn-primary btn-lg w-100 shadow-sm py-3 fw-bold rounded-3" style="pointer-events: none; opacity: 0.7;">
                    PAY NOW
                </button>

                <div class="text-center mt-3">
                    <span id="product-status-span" class="small fw-bold text-danger"></span>
                </div>
            </form>
          </div>
        </div>
      </div>
    </section>
    <?php include("../func/bc-footer.php"); ?>
    <script>
        window.addEventListener('load', () => {
            if(document.getElementById('fund-amount').value) {
                checkPaymentGatewayDetails('fundProceedBtn','2');
            }
        });
    </script>
</body>

</html>