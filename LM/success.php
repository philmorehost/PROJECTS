<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #f9fafb; margin: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .container { background: #fff; padding: 2.5rem; border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 36rem; text-align: center; }
        h1 { color: #16a34a; font-size: 2.25rem; margin-bottom: 1rem; }
        h1.error-title { color: #dc2626; }
        p { color: #6b7280; margin-bottom: 2rem; }
        .license-key { background: #f3f4f6; border: 2px dashed #d1d5db; padding: 1rem; border-radius: 0.5rem; font-size: 1.25rem; font-weight: 600; margin-bottom: 2rem; word-wrap: break-word; }
        .btn { background-color: #3b82f6; color: #fff; padding: 0.75rem 1.5rem; border-radius: 0.5rem; text-decoration: none; font-weight: 500; display: inline-block; margin-top: 1rem; border: none; cursor: pointer; font-size: 1rem; }
        .error { color: #b91c1c; background-color: #fef2f2; border: 1px solid #fca5a5; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .processing { color: #1e40af; background-color: #eff6ff; border: 1px solid #93c5fd; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .spinner { display: inline-block; width: 1.5rem; height: 1.5rem; border: 3px solid #3b82f6; border-radius: 50%; border-top-color: transparent; animation: spin 0.8s linear infinite; margin-right: 0.5rem; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .installation-guide { text-align: left; margin-top: 2.5rem; padding: 1.5rem; background-color: #f9fafb; border-radius: 0.5rem; border: 1px solid #e5e7eb; }
        .installation-guide h3 { margin-top: 0; color: #111827; }
        .installation-guide ol { padding-left: 1.5rem; }
        .installation-guide li { margin-bottom: 0.75rem; }
        .installation-guide code { background-color: #e5e7eb; padding: 0.2rem 0.4rem; border-radius: 0.25rem; font-family: 'Courier New', Courier, monospace; }
    </style>
</head>
<body>
    <div class="container">
        <div id="content"></div>
    </div>

    <script>
        const ref = '<?= isset($_GET['ref']) ? htmlspecialchars($_GET['ref']) : '' ?>';
        let attemptCount = 0;
        const maxAttempts = 30; // Try for up to 60 seconds
        
        async function checkLicense() {
            attemptCount++;
            
            try {
                // Use the new verification endpoint that checks Paystack API
                const response = await fetch('api-verify-payment.php?ref=' + encodeURIComponent(ref));
                const data = await response.json();
                
                if (data.success) {
                    // License found!
                    displaySuccess(data);
                } else if (attemptCount < maxAttempts) {
                    // Not ready yet, try again
                    displayProcessing(data.message, attemptCount, maxAttempts);
                    setTimeout(checkLicense, 2000); // Check again in 2 seconds
                } else {
                    // Timed out
                    displayError(data.message);
                }
            } catch (err) {
                if (attemptCount < maxAttempts) {
                    displayProcessing('Processing your payment...', attemptCount, maxAttempts);
                    setTimeout(checkLicense, 2000);
                } else {
                    displayError('An error occurred. Please check your email or contact support.');
                }
            }
        }
        
        function displaySuccess(data) {
            const html = `
                <h1>✓ Payment Successful!</h1>
                <p>Thank you for your purchase. Your license key has been sent to your email.</p>
                <div class="license-key" id="licenseKey">${escapeHtml(data.license_key)}</div>
                <button class="btn" onclick="copyLicense()">Copy Key</button>
                <a href="user/index.php" class="btn" style="background-color: #10b981; margin-left: 0.5rem;">Go to Dashboard</a>
                <p style="color: #475569; margin-top: 1rem; font-size: 0.95rem;">Your license key has been copied to the clipboard. Redirecting you to your dashboard in a few seconds.</p>
                ${data.auto_login_token ? `<p style="margin-top: 0.75rem; color: #475569; font-size: 0.9rem;">If automatic login does not work, <a href="user/auto_login.php?token=${encodeURIComponent(data.auto_login_token)}">click here to continue</a>.</p>` : ''}
                
                <div class="installation-guide">
                    <h3>Installation Guide</h3>
                    <ol>
                        <li>Download the main script and upload it to the server where you wish to install it.</li>
                        <li>Navigate to the <strong>setup.php</strong> file in your browser to begin installation.</li>
                        <li>You will be asked for the following details on the first step:</li>
                        <ul>
                            <li><strong>License Server URL:</strong> <code>${window.location.origin}</code></li>
                            <li><strong>Your License Key:</strong> (The key shown above)</li>
                            <li><strong>Your Domain Name:</strong> The domain you entered during purchase.</li>
                        </ul>
                        <li>Follow the on-screen instructions to complete the installation.</li>
                    </ol>
                </div>
            `;
            document.getElementById('content').innerHTML = html;
            copyLicense(true);
            if (data.auto_login_token) {
                // Submit token to server-side auto-login endpoint
                setTimeout(() => {
                    submitToken(data.auto_login_token);
                }, 1500);
            } else if (data.customer_email) {
                setTimeout(() => {
                    autoLogin(data.customer_email, data.license_key);
                }, 3000);
            } else {
                setTimeout(() => {
                    window.location.href = 'user/index.php';
                }, 7000);
            }
        }
        
        function displayProcessing(message, attempt, max) {
            const progress = Math.round((attempt / max) * 100);
            const html = `
                <h1>Processing Payment...</h1>
                <div class="processing">
                    <span class="spinner"></span>
                    <strong>${message}</strong>
                    <p style="margin-top: 0.5rem; font-size: 0.875rem;">This may take up to a minute. Attempt ${attempt}/${max}</p>
                </div>
                <p>Your license key will appear here once the payment is confirmed.</p>
            `;
            document.getElementById('content').innerHTML = html;
        }
        
        function displayError(message) {
            const html = `
                <h1 class="error-title">⚠️ Payment Processing Error</h1>
                <div class="error">${escapeHtml(message)}</div>
                <p style="color: #6b7280; font-size: 0.875rem;">Reference: <code>${escapeHtml(ref)}</code></p>
                <p>Your license may have been created. Check your email or login to your dashboard.</p>
                <a href="user/login.php" class="btn" style="background-color: #10b981; margin-right: 0.5rem;">Login to Dashboard</a>
                <a href="order.php" class="btn">Try Again</a>
            `;
            document.getElementById('content').innerHTML = html;
        }
        
        function copyLicense(silent = false) {
            const licenseKey = document.getElementById('licenseKey').innerText;
            navigator.clipboard.writeText(licenseKey).then(() => {
                if (!silent) {
                    alert('License key copied to clipboard!');
                }
            }).catch(() => {
                if (!silent) {
                    alert('Unable to copy license key automatically. Please copy it manually.');
                }
            });
        }

        function autoLogin(email, licenseKey) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'user/login.php';
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="email" value="${escapeHtml(email)}">
                <input type="hidden" name="license_key" value="${escapeHtml(licenseKey)}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function submitToken(token) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'user/auto_login.php';
            form.style.display = 'none';
            form.innerHTML = `<input type="hidden" name="token" value="${escapeHtml(token)}">`;
            document.body.appendChild(form);
            form.submit();
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
        
        // Start checking for license
        displayProcessing('Processing your payment...', 0, maxAttempts);
        checkLicense();
    </script>
</body>
</html>
