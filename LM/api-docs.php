<?php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'yourdomain.com';
if (strpos($host, ',') !== false) {
    $host = trim(explode(',', $host)[0]);
}
$host = trim($host);
$baseUrl = $scheme . '://' . $host . '/api.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Manager API Documentation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 2rem;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .content {
            padding: 3rem 2rem;
        }
        
        section {
            margin-bottom: 3rem;
        }
        
        section h2 {
            color: #667eea;
            font-size: 1.8rem;
            margin-bottom: 1rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        
        section h3 {
            color: #764ba2;
            font-size: 1.3rem;
            margin-top: 1.5rem;
            margin-bottom: 0.8rem;
        }
        
        .endpoint {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        
        .method {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            font-weight: 600;
            margin-right: 0.5rem;
            color: white;
            font-size: 0.9rem;
        }
        
        .method.post {
            background-color: #ffa500;
        }
        
        .method.get {
            background-color: #28a745;
        }
        
        .url {
            font-family: 'Courier New', monospace;
            background: #2d3748;
            color: #a0aec0;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            margin: 0.5rem 0;
            display: inline-block;
            font-size: 0.95rem;
        }
        
        .param-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .param-table th,
        .param-table td {
            padding: 0.8rem 1rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .param-table th {
            background-color: #f7fafc;
            font-weight: 600;
            color: #2d3748;
        }
        
        .param-table tr:last-child td {
            border-bottom: none;
        }
        
        .param-name {
            font-family: 'Courier New', monospace;
            color: #764ba2;
            font-weight: 500;
        }
        
        .required {
            color: #e53e3e;
            font-weight: 600;
        }
        
        .optional {
            color: #744210;
        }
        
        .code-block {
            background: #2d3748;
            color: #a0aec0;
            padding: 1.5rem;
            border-radius: 6px;
            overflow-x: auto;
            margin: 1rem 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .code-block code {
            color: #a0aec0;
        }
        
        .response-success {
            background: #f0fff4;
            border-left: 4px solid #28a745;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        
        .response-error {
            background: #fff5f5;
            border-left: 4px solid #e53e3e;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
        }
        
        .response-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .response-success .response-label {
            color: #22543d;
        }
        
        .response-error .response-label {
            color: #742a2a;
        }
        
        .example-tab {
            display: none;
        }
        
        .example-tab.active {
            display: block;
        }
        
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin: 1rem 0;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .tab-button {
            padding: 0.8rem 1rem;
            border: none;
            background: none;
            cursor: pointer;
            font-weight: 500;
            color: #718096;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-button:hover {
            color: #667eea;
        }
        
        .integration-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin: 1.5rem 0;
        }
        
        .integration-card {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .integration-card h4 {
            color: #2d3748;
            margin-bottom: 0.5rem;
        }
        
        .integration-card p {
            color: #718096;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        
        .highlight {
            background-color: #fef3c7;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
        }
        
        .footer {
            background: #f7fafc;
            padding: 2rem;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #718096;
            font-size: 0.95rem;
        }

        .success-code {
            color: #48bb78;
        }

        .error-code {
            color: #f56565;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 License Manager API</h1>
            <p>Integrate license validation into your website</p>
        </div>
        
        <div class="content">
            <!-- Overview Section -->
            <section>
                <h2>Overview</h2>
                <p>The License Manager API allows you to validate customer licenses directly from your website. Simply send a POST request with the license key and domain, and receive instant validation results.</p>
                <p style="margin-top: 1rem; color: #718096;"><strong>No authentication required.</strong> Use the API publicly to verify licenses on your platform.</p>
            </section>
            
            <!-- Base URL Section -->
            <section>
                <h2>Base URL</h2>
                <div class="url"><?= htmlspecialchars($baseUrl) ?></div>
                <p style="margin-top: 0.75rem; color: #718096;">This page detected your current website domain as <strong><?= htmlspecialchars($host) ?></strong>. Use this value in the <span class="param-name">domain</span> field or use browser-side hostname detection when integrating from the frontend.</p>
                <p style="margin-top: 1rem; color: #718096;">Copy this exact URL and use it as the base endpoint for license validation.</p>
            </section>

            <section>
                <h2>Domain Change Support</h2>
                <p style="color: #718096;">This API validates license keys against the current domain configured for that license. If a customer changes the domain in their user dashboard, the API will validate the new domain automatically.</p>
                <p style="color: #718096;">Always send the customer’s current domain in the <span class="param-name">domain</span> field to avoid mismatches.</p>
            </section>
            
            <!-- Endpoints Section -->
            <section>
                <h2>Endpoints</h2>
                <p style="margin-top: 0.75rem; color: #718096;">The API validates license keys against the domain currently assigned to the license. If a customer changes the domain in their dashboard, the new domain will be used for validation.</p>
                
                <h3>Validate License</h3>
                <div class="endpoint">
                    <div>
                        <span class="method post">POST</span>
                        <span class="url">/api.php</span>
                    </div>
                    <p style="margin-top: 0.5rem; color: #718096;">Validates a license key for a specific domain.</p>
                </div>
                
                <h3>Request Parameters</h3>
                <table class="param-table">
                    <thead>
                        <tr>
                            <th>Parameter</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="param-name">key</span></td>
                            <td>string</td>
                            <td><span class="required">Required</span></td>
                            <td>The license key to validate</td>
                        </tr>
                        <tr>
                            <td><span class="param-name">domain</span></td>
                            <td>string</td>
                            <td><span class="required">Required</span></td>
                            <td>The domain associated with the license</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Response Format</h3>
                <p style="margin-bottom: 1rem; color: #718096;">The API returns a JSON response with the following structure:</p>
                
                <div class="response-success">
                    <div class="response-label" style="color: #22543d;">✓ Success Response</div>
                    <div class="code-block"><code>{
  "status": <span class="success-code">1</span>,
  "message": "License is valid."
}</code></div>
                </div>
                
                <div class="response-error">
                    <div class="response-label" style="color: #742a2a;">✗ Error Response</div>
                    <div class="code-block"><code>{
  "status": <span class="error-code">0</span>,
  "message": "Invalid license key or domain."
}</code></div>
                </div>
                
                <h3>Response Status Codes</h3>
                <table class="param-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Meaning</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="success-code" style="font-weight: 600;">1</span></td>
                            <td>License is valid and active</td>
                        </tr>
                        <tr>
                            <td><span class="error-code" style="font-weight: 600;">0</span></td>
                            <td>Invalid license key, domain mismatch, or license inactive</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            
            <!-- Code Examples Section -->
            <section>
                <h2>Code Examples</h2>
                
                <h3>Integration Examples</h3>
                <div class="tabs">
                    <button class="tab-button active" onclick="switchTab(event, 'php')">PHP</button>
                    <button class="tab-button" onclick="switchTab(event, 'javascript')">JavaScript</button>
                    <button class="tab-button" onclick="switchTab(event, 'curl')">cURL</button>
                    <button class="tab-button" onclick="switchTab(event, 'python')">Python</button>
                </div>
                
                <!-- PHP Example -->
                <div id="php" class="example-tab active">
                    <h4 style="color: #2d3748; margin-bottom: 0.5rem;">PHP Implementation</h4>
                    <?php
                    $phpExample = <<<PHP
<?php
\$license_key = \$_POST['license_key'] ?? '';
\$domain = \$_SERVER['HTTP_HOST'];

\$ch = curl_init();
curl_setopt(\$ch, CURLOPT_URL, '$baseUrl');
curl_setopt(\$ch, CURLOPT_POST, 1);
curl_setopt(\$ch, CURLOPT_POSTFIELDS, http_build_query([
    'key' => \$license_key,
    'domain' => \$domain
]));
curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt(\$ch, CURLOPT_TIMEOUT, 5);

\$response = curl_exec(\$ch);
curl_close(\$ch);

\$result = json_decode(\$response, true);

if (\$result['status'] == 1) {
    echo "License is valid!";
    // Allow feature access
} else {
    echo "License is invalid!";
    // Restrict feature access
}
PHP;
                    ?>
                    <div class="code-block"><code><?= htmlspecialchars($phpExample) ?></code></div>
                </div>
                
                <!-- JavaScript Example -->
                <div id="javascript" class="example-tab">
                    <h4 style="color: #2d3748; margin-bottom: 0.5rem;">JavaScript Implementation</h4>
                    <div class="code-block"><code>const licenseKey = 'customer_license_key';
const domain = window.location.hostname;

fetch('<?= htmlspecialchars($baseUrl) ?>', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: new URLSearchParams({
        key: licenseKey,
        domain: domain
    })
})
.then(response => response.json())
.then(data => {
    if (data.status === 1) {
        console.log('License is valid!');
        // Enable premium features
    } else {
        console.log('License is invalid!');
        // Show license error
    }
})
.catch(error => console.error('Error:', error));</code></div>
                </div>
                
                <!-- cURL Example -->
                <div id="curl" class="example-tab">
                    <h4 style="color: #2d3748; margin-bottom: 0.5rem;">cURL Command Line</h4>
                    <div class="code-block"><code>curl -X POST <?= htmlspecialchars($baseUrl) ?> \
  -d "key=YOUR_LICENSE_KEY" \
  -d "domain=<?= htmlspecialchars($host) ?>" \
  -H "Content-Type: application/x-www-form-urlencoded"</code></div>
                </div>
                
                <!-- Python Example -->
                <div id="python" class="example-tab">
                    <h4 style="color: #2d3748; margin-bottom: 0.5rem;">Python Implementation</h4>
                    <div class="code-block"><code>import requests
import json

license_key = 'customer_license_key'
domain = '<?= htmlspecialchars($host) ?>'

response = requests.post(
    '<?= htmlspecialchars($baseUrl) ?>',
    data={
        'key': license_key,
        'domain': domain
    },
    timeout=5
)

result = response.json()

if result['status'] == 1:
    print('License is valid!')
    # Enable features
else:
    print('License is invalid!')
    # Restrict features</code></div>
                </div>
            </section>
            
            <!-- Common Integration Patterns -->
            <section>
                <h2>Integration Patterns</h2>
                
                <h3>Page Load Verification</h3>
                <p>Check the license when a user accesses your premium content:</p>
                <div class="code-block"><code>// Validate on page load
document.addEventListener('DOMContentLoaded', function() {
    validateLicense();
});

function validateLicense() {
    fetch('<?= htmlspecialchars($baseUrl) ?>', {
        method: 'POST',
        body: new URLSearchParams({
            key: localStorage.getItem('license_key'),
            domain: window.location.hostname
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.status !== 1) {
            document.getElementById('premium-content').style.display = 'none';
            showLicenseError();
        }
    });
}</code></div>
                
                <h3>Server-Side Caching</h3>
                <p>Cache validation results to reduce API calls:</p>
                <div class="code-block"><code>// Check cache first
$cache_key = 'license_' . md5($license_key . $domain);
$cached = apcu_fetch($cache_key);

if ($cached) {
    $result = $cached;
} else {
    // Make API call
    $result = validateLicense($license_key, $domain);
    // Cache for 1 hour
    apcu_store($cache_key, $result, 3600);
}</code></div>
            </section>
            
            <!-- Error Handling -->
            <section>
                <h2>Error Handling</h2>
                <p>Always implement error handling in your integration:</p>
                
                <div class="code-block"><code>try {
    const response = await fetch('<?= htmlspecialchars($baseUrl) ?>', {
        method: 'POST',
        body: new URLSearchParams({
            key: licenseKey,
            domain: domain
        })
    });
    
    if (!response.ok) {
        throw new Error('Network error');
    }
    
    const data = await response.json();
    
    if (data.status === 1) {
        // License valid
    } else {
        // License invalid - show message
        console.warn(data.message);
    }
} catch (error) {
    // Fallback behavior
    console.error('License check failed:', error);
    // Allow access with warning or block
}</code></div>
            </section>
            
            <!-- Best Practices -->
            <section>
                <h2>Best Practices</h2>
                <ul style="line-height: 2; color: #718096;">
                    <li>✓ <strong>Cache results:</strong> Don't validate on every page load. Cache validation results for 1-24 hours.</li>
                    <li>✓ <strong>Handle timeouts:</strong> Set a reasonable timeout (3-5 seconds) for API calls.</li>
                    <li>✓ <strong>Fallback logic:</strong> If the API is unreachable, decide whether to allow or block access.</li>
                    <li>✓ <strong>HTTPS only:</strong> Always use HTTPS when sending license data.</li>
                    <li>✓ <strong>Server-side validation:</strong> Validate licenses on your backend, not just frontend.</li>
                    <li>✓ <strong>Store securely:</strong> Don't expose license keys in client-side code.</li>
                    <li>✓ <strong>Log activity:</strong> Log license validation attempts for audit purposes.</li>
                </ul>
            </section>
            
            <!-- FAQ -->
            <section>
                <h2>Frequently Asked Questions</h2>
                
                <h3>Is the API publicly accessible?</h3>
                <p style="color: #718096;">Yes, the API is public and requires no authentication. This allows customers to use their license keys for verification.</p>
                
                <h3>What formats are supported?</h3>
                <p style="color: #718096;">The API accepts POST requests with form data and returns JSON responses.</p>
                
                <h3>Are there rate limits?</h3>
                <p style="color: #718096;">Currently, there are no rate limits. However, we recommend implementing client-side caching to reduce unnecessary API calls.</p>
                
                <h3>Can I validate multiple licenses at once?</h3>
                <p style="color: #718096;">The current API validates one license per request. For multiple validations, make multiple API calls.</p>
                
                <h3>What happens if the license is inactive?</h3>
                <p style="color: #718096;">The API returns status <span class="highlight">0</span> with the message "Invalid license key or domain." You can check the admin panel for license status details.</p>

                <h3>How do domain changes affect validation?</h3>
                <p style="color: #718096;">If a customer updates their license domain, the API will validate only the newly configured domain. Admins can set how many domain changes each license can perform.</p>
                
                <h3>How do I debug API issues?</h3>
                <p style="color: #718096;">Use the cURL example above to test your request. Check the response status code and message. The API also maintains debug logs for troubleshooting.</p>
            </section>
            
            <!-- Contact -->
            <section style="background: #f7fafc; padding: 2rem; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0;">
                <h2 style="border: none; padding-bottom: 0;">Need Help?</h2>
                <p style="color: #718096; margin-top: 1rem;">For integration support, questions, or issues, please contact our support team or check the admin panel for more information.</p>
            </section>
        </div>
        
        <div class="footer">
            <p>&copy; License Manager. API Documentation. Last updated: <?php echo date('Y-m-d'); ?></p>
        </div>
    </div>
    
    <script>
        function switchTab(event, tabName) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.example-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked button
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            }
        }
    </script>
</body>
</html>
